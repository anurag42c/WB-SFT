<?php
declare(strict_types=1);

/**
 * Heuristic invoice reader for plain OCR text (used with the offline Tesseract provider).
 * It is deliberately conservative: anything it cannot find stays empty and is listed in `warnings`,
 * and a person always reviews the result on the document screen before it is verified.
 * Returns the same structure as the Claude provider.
 */
final class OcrParser
{
    private const VAL = '([A-Z0-9][A-Z0-9\/\-\.]{2,30})';                       // an invoice / PO style reference (no spaces)
    private const NUM = '([\d][\d,]*(?:\.\d+)?)';

    public static function parse(string $text, string $docType = 'PO_INVOICE'): array
    {
        $text = str_replace(["\r", "\t", '|', "\xC2\xA0"], ["\n", ' ', ' ', ' '], $text);
        $text = preg_replace('/[ ]{2,}/', ' ', $text) ?? $text;
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
        $flat = implode("\n", $lines);
        $warn = [];
        $isReturn = $docType === 'MATERIAL_RETURN';
        $out = ['invoice_no' => null, 'invoice_date' => null, 'supplier_name' => null, 'supplier_tax_id' => null, 'buyer_name' => null, 'po_no' => null,
                'orig_invoice_no' => null, 'vehicle_no' => null, 'transporter' => null, 'eway_bill_no' => null, 'currency' => null,
                'subtotal' => null, 'tax_amount' => null, 'total_amount' => null, 'lines' => [], 'confidence' => 0.0, 'warnings' => []];

        // 1. the ORIGINAL invoice a return refers to (must be removed before looking for this document's own number)
        $work = $flat;
        if (preg_match('/\b(?:against|ref(?:erence)?|original|orig\.?|w\.?r\.?t\.?)\s+(?:tax\s+)?(?:invoice|bill)\s*(?:no\.?|number|#)?\s*[:\-#]?\s*' . self::VAL . '/i', $work, $m)) {
            $out['orig_invoice_no'] = strtoupper($m[1]);
            $work = str_replace($m[0], ' ', $work);
        }

        // 2. this document's number
        $numLabels = $isReturn
            ? ['(?:return|debit\s*note|credit\s*note|rejection)\s*(?:challan|note|memo)?\s*(?:no\.?|number|#)', 'challan\s*(?:no\.?|number|#)', 'document\s*(?:no\.?|number|#)', '(?:tax\s+)?(?:invoice|bill)\s*(?:no\.?|number|#)']
            : ['(?:tax\s+)?invoice\s*(?:no\.?|number|#)', 'bill\s*(?:no\.?|number|#)', 'challan\s*(?:no\.?|number|#)', 'document\s*(?:no\.?|number|#)', 'inv\.?\s*(?:no\.?|#)'];
        foreach ($numLabels as $lab) {
            if (preg_match('/\b' . $lab . '\s*[:\-#]?\s*' . self::VAL . '/i', $work, $m)) { $out['invoice_no'] = strtoupper($m[1]); break; }
        }

        // 3. date (labelled first, then the first date anywhere)
        $dateRe = '(\d{1,2}[\-\/.]\d{1,2}[\-\/.]\d{2,4}|\d{4}-\d{2}-\d{2}|\d{1,2}[\s\-]*[A-Za-z]{3,9}[\s\-,]*\d{2,4})';
        if (preg_match('/\b(?:(?:invoice|bill|challan|document|return)\s*)?(?:date|dated|dt\.?)\s*[:\-]?\s*' . $dateRe . '/i', $work, $m)) { $out['invoice_date'] = Documents::date($m[1]); }
        if (!$out['invoice_date'] && preg_match('/' . $dateRe . '/', $work, $m)) { $out['invoice_date'] = Documents::date($m[1]); }

        // 4. PO number
        if (preg_match('/(?:\bP\.?\s?O\.?\s*(?:no\.?|number|#)\s*[:\-#]?|\bP\.?\s?O\.?\s*[:#]|purchase\s+order(?:\s+(?:no\.?|number))?\s*[:\-#]?|your\s+order(?:\s+no\.?)?\s*[:\-#]?|\border\s+(?:no\.?|number)\s*[:\-#]?)\s*' . self::VAL . '/i', $work, $m)) {
            $out['po_no'] = strtoupper($m[1]);
        }

        // 5. vehicle
        if (preg_match('/\b(?:vehicle|truck|lorry|veh\.?|trailer)\s*(?:no\.?|number|#)?\s*[:\-#]?\s*([A-Z]{2}[\s\-]?\d{1,2}[\s\-]?[A-Z]{1,3}[\s\-]?\d{3,4})\b/i', $work, $m)
            || preg_match('/\b([A-Z]{2}[\s\-]?\d{1,2}[\s\-]?[A-Z]{1,3}[\s\-]?\d{4})\b/', $work, $m)) {
            $out['vehicle_no'] = Documents::keyOf($m[1]);
        } elseif (preg_match('/\b(?:vehicle|truck|lorry)\s*(?:no\.?|number|#)\s*[:\-#]?\s*([A-Z0-9][A-Z0-9 \-]{3,14})/i', $work, $m)) {
            $out['vehicle_no'] = Documents::keyOf($m[1]);
        }
        if (preg_match('/\be[\s\-]?way\s*bill(?:\s*(?:no\.?|number|#))?\s*[:\-#]?\s*(\d{10,14})/i', $work, $m)) { $out['eway_bill_no'] = $m[1]; }
        if (preg_match('/\btransporter\s*[:\-]\s*([^\n]{3,50})/i', $work, $m)) { $out['transporter'] = trim($m[1]); }

        // 6. parties + tax ids
        preg_match_all('/\b\d{2}[A-Z]{5}\d{4}[A-Z]\dZ[A-Z0-9]\b/', $work, $g);
        $gst = $g[0] ?? [];
        $own = (string)Settings::get('company_name', '');
        $out['buyer_name'] = self::firstMatch($work, ['/(?:\b(?:buyer|bill(?:ed)?\s*to|ship(?:ped)?\s*to|consignee|sold\s*to)|(?<!return )(?<!returned )(?<!debit )\bto)\s*[:\-]\s*(?:m\/s\.?\s*)?([^\n]{3,70})/i']);
        if ($isReturn) {
            $out['supplier_name'] = self::firstMatch($work, ['/\b(?:returned?\s*to|return\s*to|debit(?:ed)?\s*to|supplier|vendor)\s*[:\-]\s*(?:m\/s\.?\s*)?([^\n]{3,70})/i']);
        } else {
            $out['supplier_name'] = self::firstMatch($work, ['/\b(?:supplier|vendor|sold\s*by|seller|bill\s*from|from)\s*[:\-]\s*(?:m\/s\.?\s*)?([^\n]{3,70})/i']);
        }
        if (!$out['supplier_name']) {
            foreach (array_slice($lines, 0, 4) as $l) {
                $cand = trim(preg_replace('/\b(?:tax\s+invoice|invoice|bill|debit\s*note|credit\s*note|return\s*challan|delivery\s*challan|challan|original\s+for\s+recipient)\b.*$/i', '', $l) ?? '');
                $cand = trim($cand, " -:/.,");
                if (strlen($cand) >= 4 && !preg_match('/^(gstin|pan|plot|ph|phone|tel|address|\d)/i', $cand) && ($own === '' || Documents::sim($cand, $own) < 0.7 || $isReturn === false)) {
                    if ($own !== '' && !$isReturn && Documents::sim($cand, $own) >= 0.7) { continue; }   // that is us, the buyer
                    if ($own !== '' && $isReturn && Documents::sim($cand, $own) >= 0.7) { continue; }
                    $out['supplier_name'] = $cand; break;
                }
            }
        }
        if ($out['supplier_name']) { $out['supplier_name'] = trim(preg_replace('/\s+(?:gstin|pan|ph|phone|tel)\b.*$/i', '', $out['supplier_name']) ?? '', " -:,"); }
        if ($out['buyer_name']) { $out['buyer_name'] = trim(preg_replace('/\s+(?:gstin|pan|ph|phone|tel)\b.*$/i', '', $out['buyer_name']) ?? '', " -:,"); }
        if ($gst && !$isReturn) { $out['supplier_tax_id'] = $gst[0]; }

        // 7. totals
        $out['total_amount'] = self::amountAfter($work, ['grand\s+total', 'total\s+(?:invoice\s+)?(?:amount|value)', 'invoice\s+total', 'net\s+(?:amount\s+)?payable', 'total\s+payable', 'amount\s+payable']);
        $out['subtotal'] = self::amountAfter($work, ['taxable\s+(?:value|amount)', 'sub\s*-?\s*total', 'basic\s+(?:amount|value)', 'total\s+before\s+tax']);
        $tax = 0.0; $seenTax = false;
        foreach ($lines as $l) {
            if (preg_match('/^(?:c\s*gst|s\s*gst|i\s*gst|utgst|gst|vat|tax)\b[^\n]*?([\d,]+\.\d{2})\s*$/i', $l, $m)) { $tax += (float)Documents::num($m[1]); $seenTax = true; }
        }
        if ($seenTax) { $out['tax_amount'] = round($tax, 2); }
        if ($out['total_amount'] === null && $out['subtotal'] !== null && $out['tax_amount'] !== null) { $out['total_amount'] = round($out['subtotal'] + $out['tax_amount'], 2); }
        if (preg_match('/(?:₹|\bRs\.?|\bINR\b)/u', $work)) { $out['currency'] = 'INR'; }
        elseif (preg_match('/\bUSD\b|\$/', $work)) { $out['currency'] = 'USD'; }
        elseif (preg_match('/\bEUR\b|€/u', $work)) { $out['currency'] = 'EUR'; }
        elseif ($gst) { $out['currency'] = 'INR'; }

        // 8. line items: "description [hsn] qty [unit] rate amount"
        $defUom = null;
        if (preg_match('/\b(MT|MTS|KGS?|TONNES?|TONS?|QTL)\b/i', $work, $u)) { $defUom = Documents::uom($u[1]); }
        $unitGuessed = false;
        $lineRe = '/^\s*(?:\d{1,3}[\s.)]+)?([A-Za-z][^\n]*?)\s+(?:(\d{4,8})\s+)?' . self::NUM . '\s*([A-Za-z]{1,6}\.?)?\s+(\d[\d,]*\.\d{2})\s+(\d[\d,]*\.\d{2})\s*$/';
        foreach ($lines as $l) {
            if (!preg_match($lineRe, $l, $m)) { continue; }
            $desc = trim($m[1], " -:.,");
            if (strlen($desc) < 3 || preg_match('/\b(total|tax|gst|round|discount|freight|amount in words)\b/i', $desc)) { continue; }
            $uomTxt = $m[4] ?? '';
            $uom = ($uomTxt !== '' && preg_match('/^(kgs?|mts?|tons?|tonnes?|qtl|nos|pcs?|ltrs?|bags?|gm|g|t|no|l|m)\.?$/i', $uomTxt)) ? Documents::uom($uomTxt) : null;
            if ($uom === null && $uomTxt !== '') { $desc = trim($desc . ' ' . $m[3] . $uomTxt); }     // the letters were part of the description
            if ($uom === null && $defUom) { $uom = $defUom; $unitGuessed = true; }
            $qty = Documents::num($m[3]); $rate = Documents::num($m[5]); $amt = Documents::num($m[6]);
            $out['lines'][] = ['description' => $desc, 'material_code' => null, 'hsn' => $m[2] !== '' ? $m[2] : null, 'qty' => $qty, 'uom' => $uom, 'rate' => $rate, 'amount' => $amt];
            if ($qty !== null && $rate !== null && $amt !== null && $amt > 0 && abs($qty * $rate - $amt) / $amt > 0.02) {
                $warn[] = 'Line ' . count($out['lines']) . ': quantity x rate does not equal the amount - check the numbers.';
            }
        }

        // 9. warnings + confidence
        $need = ['invoice_no' => 'invoice / challan number', 'invoice_date' => 'date', 'supplier_name' => 'supplier', 'total_amount' => 'total amount'];
        if ($docType === 'PO_INVOICE') { $need['po_no'] = 'PO number'; }
        $found = 0;
        foreach ($need as $k => $label) { if ($out[$k] !== null && $out[$k] !== '') { $found++; } else { $warn[] = "Not found: $label."; } }
        if (!$out['vehicle_no']) { $warn[] = 'Not found: vehicle number (needed to match the weighbridge ticket).'; }
        if (!$out['lines']) { $warn[] = 'No line items could be read - enter them by hand.'; }
        elseif ($unitGuessed) { $warn[] = 'Quantity unit was not read from the lines; "' . $defUom . '" was assumed from elsewhere on the page - check it.'; }
        elseif (array_filter($out['lines'], fn($l) => $l['uom'] === null)) { $warn[] = 'Quantity unit not read for some lines - select it (needed for the weight check).'; }
        if ($out['subtotal'] !== null && $out['tax_amount'] !== null && $out['total_amount'] !== null && abs($out['subtotal'] + $out['tax_amount'] - $out['total_amount']) > 1.0) {
            $warn[] = 'Subtotal + tax does not equal the total - one of these was misread.';
        }
        $out['confidence'] = round(min(0.85, 0.25 + 0.6 * ($found / count($need)) + ($out['lines'] ? 0.1 : 0) - 0.03 * count(array_filter($warn, fn($w) => str_starts_with($w, 'Line ')))), 2);
        $out['warnings'] = array_values(array_unique($warn));
        return $out;
    }

    private static function firstMatch(string $t, array $res): ?string
    {
        foreach ($res as $re) { if (preg_match($re, $t, $m)) { $v = trim($m[1]); if ($v !== '') { return $v; } } }
        return null;
    }

    /** First amount after any of the labels; "Total" lines with a label like CGST are not confused because labels are specific. */
    private static function amountAfter(string $t, array $labels): ?float
    {
        foreach ($labels as $lab) {
            if (preg_match_all('/\b' . $lab . '\b[^\n\d]{0,25}?(?:rs\.?|inr|₹)?\s*' . self::NUM . '/iu', $t, $m)) { return Documents::num(end($m[1])); }
        }
        return null;
    }
}
