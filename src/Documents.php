<?php
declare(strict_types=1);

/**
 * Invoice / material-return documents: an uploaded picture (or PDF) converted into a structured record that is
 * reviewed by a person, matched to weighbridge tickets and sent to SAP / Oracle.
 */
final class Documents
{
    public const TYPES = ['PO_INVOICE' => 'Purchase invoice (PO)', 'MATERIAL_RETURN' => 'Material return'];
    public const ALLOWED_MIME = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'application/pdf' => 'pdf'];

    private const UOM = [
        'KG' => ['kg', 'kgs', 'kilo', 'kilos', 'kilogram', 'kilograms'], 'G' => ['g', 'gm', 'gms', 'gram', 'grams'],
        'MT' => ['mt', 'mts', 'ton', 'tons', 'tonne', 'tonnes', 't', 'metricton', 'metrictons'], 'QTL' => ['qtl', 'quintal', 'quintals'],
        'NOS' => ['nos', 'no', 'pcs', 'pc', 'piece', 'pieces', 'each', 'ea', 'unit', 'units'], 'LTR' => ['ltr', 'ltrs', 'l', 'lt', 'litre', 'litres', 'liter', 'liters'],
        'BAG' => ['bag', 'bags'], 'M' => ['m', 'mtr', 'mtrs', 'meter', 'meters', 'metre', 'metres'],
    ];
    private const KG_FACTOR = ['KG' => 1.0, 'G' => 0.001, 'MT' => 1000.0, 'QTL' => 100.0];

    // ------------------------------------------------------------------ parsing helpers
    public static function uom(?string $u): ?string
    {
        $k = strtolower(preg_replace('/[^A-Za-z]/', '', (string)$u) ?? '');
        if ($k === '') { return null; }
        foreach (self::UOM as $canon => $names) { if (in_array($k, $names, true)) { return $canon; } }
        return strtoupper($k);
    }

    /** Quantity in kilograms when the unit is a weight unit, else null. */
    public static function qtyKg(?float $qty, ?string $uom): ?float
    {
        $u = self::uom($uom);
        return ($qty === null || $u === null || !isset(self::KG_FACTOR[$u])) ? null : round($qty * self::KG_FACTOR[$u], 3);
    }

    /** "1,23,456.78" / "12 500.5" / "Rs. 1,000" / "1.234,56" -> float; null if no number. */
    public static function num(mixed $v): ?float
    {
        if ($v === null || $v === '') { return null; }
        if (is_int($v) || is_float($v)) { return (float)$v; }
        $s = trim((string)$v);
        if (!preg_match('/-?\d[\d.,\s]*/', $s, $m)) { return null; }
        $s = preg_replace('/\s+/', '', $m[0]);
        $neg = str_starts_with(trim((string)$v), '-') || preg_match('/^\(.*\)$/', trim((string)$v));
        if (preg_match('/^\d{1,3}(\.\d{3})+,\d{1,2}$/', $s)) { $s = str_replace(['.', ','], ['', '.'], $s); }      // 1.234,56
        elseif (preg_match('/^\d+,\d{1,2}$/', $s)) { $s = str_replace(',', '.', $s); }                              // 12,5
        else { $s = str_replace(',', '', $s); }                                                                     // 1,23,456.78
        $s = rtrim($s, '.');
        return is_numeric($s) ? ($neg ? -1 : 1) * abs((float)$s) : null;
    }

    private const MONTHS = ['jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6, 'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12];

    /** Many printed formats -> YYYY-MM-DD, or null. Day-first unless setting doc_date_order = MDY. */
    public static function date(?string $s): ?string
    {
        $s = trim((string)$s);
        if ($s === '') { return null; }
        $y = $m = $d = null;
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})/', $s, $x)) { [$y, $m, $d] = [(int)$x[1], (int)$x[2], (int)$x[3]]; }
        elseif (preg_match('/(\d{1,2})[\s\-\/.]*([A-Za-z]{3,9})[a-z]*[\s\-\/.,]*(\d{2,4})/', $s, $x) && isset(self::MONTHS[strtolower(substr($x[2], 0, 3))])) {
            [$d, $m, $y] = [(int)$x[1], self::MONTHS[strtolower(substr($x[2], 0, 3))], (int)$x[3]];
        }
        elseif (preg_match('/([A-Za-z]{3,9})[a-z]*\.?\s+(\d{1,2}),?\s+(\d{2,4})/', $s, $x) && isset(self::MONTHS[strtolower(substr($x[1], 0, 3))])) {
            [$m, $d, $y] = [self::MONTHS[strtolower(substr($x[1], 0, 3))], (int)$x[2], (int)$x[3]];
        }
        elseif (preg_match('/(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2,4})/', $s, $x)) {
            $a = (int)$x[1]; $b = (int)$x[2]; $y = (int)$x[3];
            if (Settings::get('doc_date_order', 'DMY') === 'MDY') { [$m, $d] = [$a, $b]; } else { [$d, $m] = [$a, $b]; }
            if ($m > 12 && $d <= 12) { [$m, $d] = [$d, $m]; }     // unambiguous swap
        }
        if ($y === null) { return null; }
        if ($y < 100) { $y += 2000; }
        return checkdate((int)$m, (int)$d, (int)$y) ? sprintf('%04d-%02d-%02d', $y, $m, $d) : null;
    }

    /**
     * Name similarity 0..1 on whole words (company suffixes and punctuation ignored, one-letter typos tolerated).
     * Sharing one generic word ("Minerals") is NOT enough; a name fully contained in another ("ACME" in "ACME Coal") scores 0.9.
     */
    public static function sim(?string $a, ?string $b): float
    {
        $n = function (?string $s): array {
            $s = strtolower((string)$s);
            $s = preg_replace('/[^a-z0-9 ]+/', ' ', $s) ?? '';
            $stop = ['pvt', 'private', 'ltd', 'limited', 'llp', 'co', 'company', 'inc', 'corp', 'corporation', 'ms', 'm', 's', 'the', 'and', 'of', 'traders', 'enterprises'];
            return array_values(array_filter(preg_split('/\s+/', trim($s)) ?: [], fn($w) => $w !== '' && !in_array($w, $stop, true)));
        };
        $x = $n($a); $y = $n($b);
        if (!$x || !$y) { return 0.0; }
        if ($x === $y) { return 1.0; }
        $same = function (string $p, string $q): bool {
            return $p === $q || (min(strlen($p), strlen($q)) >= 5 && levenshtein($p, $q) <= 1);
        };
        $matched = 0; $used = [];
        foreach ($x as $p) {
            foreach ($y as $k => $q) { if (!isset($used[$k]) && $same($p, $q)) { $used[$k] = true; $matched++; break; }  }
        }
        $dice = 2 * $matched / (count($x) + count($y));
        $contain = ($matched === min(count($x), count($y))) ? 0.9 : 0.0;
        return round(max($dice, $contain), 3);
    }

    public static function keyOf(?string $s): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$s) ?? ''); }

    // ------------------------------------------------------------------ storage
    public static function dir(): string
    {
        $d = WB_DATA . '/documents';
        if (!is_dir($d)) { @mkdir($d, 0770, true); }
        return $d;
    }

    public static function path(array $doc): ?string
    {
        $f = basename((string)$doc['file']);
        return ($f !== '' && is_file(self::dir() . '/' . $f)) ? self::dir() . '/' . $f : null;
    }

    private static function nextNo(): string
    {
        $prefix = 'DOC' . date('Ymd');
        $max = Db::val('SELECT MAX(doc_no) FROM documents WHERE doc_no LIKE ?', [$prefix . '%']);
        return $prefix . str_pad((string)($max ? ((int)substr((string)$max, strlen($prefix))) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }

    /**
     * Store an uploaded file (already on disk) as a new document. Validates size, real file type and image size.
     * @throws RuntimeException with a message fit for the operator
     */
    public static function create(string $tmpPath, string $origName, string $type, string $poOrRef = ''): int
    {
        if (!isset(self::TYPES[$type])) { throw new RuntimeException('Choose PO invoice or Material return.'); }
        $size = (int)@filesize($tmpPath);
        $max = max(1, (int)Settings::get('doc_max_mb', '10')) * 1048576;
        if ($size <= 0) { throw new RuntimeException('The file is empty.'); }
        if ($size > $max) { throw new RuntimeException('The file is larger than ' . (int)($max / 1048576) . ' MB.'); }
        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmpPath) ?: '';
        if (!isset(self::ALLOWED_MIME[$mime])) { throw new RuntimeException('Only JPEG, PNG or PDF files can be uploaded (this file is ' . ($mime ?: 'unknown') . ').'); }
        if ($mime !== 'application/pdf') {
            $info = @getimagesize($tmpPath);
            if (!$info || $info[0] * $info[1] > 60000000) { throw new RuntimeException('The picture is corrupt or too large (over 60 megapixels).'); }
        }
        $stored = 'doc_' . bin2hex(random_bytes(10)) . '.' . self::ALLOWED_MIME[$mime];
        if (!copy($tmpPath, self::dir() . '/' . $stored)) { throw new RuntimeException('Cannot store the file (data/documents not writable).'); }
        @chmod(self::dir() . '/' . $stored, 0640);

        $now = date('Y-m-d H:i:s'); $ref = trim($poOrRef);
        $pdo = Db::pdo(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            $no = self::nextNo();
            Db::q('INSERT INTO documents(doc_no, doc_type, status, file, mime, orig_name, po_no, ref_no, uploaded_by, uploaded_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)',
                [$no, $type, 'UPLOADED', $stored, $mime, mb_substr(basename($origName), 0, 120), $type === 'PO_INVOICE' ? $ref : null, $type === 'MATERIAL_RETURN' ? $ref : null,
                 Auth::user()['username'] ?? 'system', $now, $now]);
            $id = Db::id();
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK'); } @unlink(self::dir() . '/' . $stored); throw $e; }
        Db::audit('doc_upload', "$no $origName");
        return $id;
    }

    public static function get(int $id): ?array { return Db::one('SELECT * FROM documents WHERE id = ?', [$id]); }
    public static function lines(int $id): array { return Db::all('SELECT * FROM document_lines WHERE doc_id = ? ORDER BY line_no', [$id]); }

    /** Run the OCR provider on the stored file and fill the record (does not overwrite fields a person already verified). */
    public static function extract(int $id): array
    {
        $doc = self::get($id);
        if (!$doc) { throw new RuntimeException('Document not found.'); }
        if ($doc['status'] === 'VERIFIED') { throw new RuntimeException('A verified document cannot be re-read. Reopen it first.'); }
        $path = self::path($doc);
        if (!$path) { throw new RuntimeException('The stored file is missing.'); }
        $res = Ocr::extract($path, (string)$doc['mime'], (string)$doc['doc_type']);
        if (!$res['ok']) {
            Db::q('UPDATE documents SET ocr_error = ?, ocr_provider = ?, updated_at = ? WHERE id = ?', [mb_substr((string)$res['error'], 0, 400), $res['provider'], date('Y-m-d H:i:s'), $id]);
            return $res;
        }
        self::applyExtraction($id, $res['data'], $res);
        return $res;
    }

    /** Copy extracted values into the record. The tag's reference (PO no / return ref) typed at upload is kept if OCR finds none. */
    public static function applyExtraction(int $id, array $d, array $meta = []): void
    {
        $doc = self::get($id);
        $po = $d['po_no'] ?? null; $orig = $d['orig_invoice_no'] ?? null;
        Db::q('UPDATE documents SET status = ?, ocr_provider = ?, ocr_conf = ?, ocr_warnings = ?, ocr_error = NULL, ocr_raw = ?,
               invoice_no = ?, invoice_date = ?, supplier = ?, supplier_tax_id = ?, buyer = ?, po_no = ?, orig_invoice_no = ?, vehicle_no = ?, transporter = ?, eway_no = ?,
               currency = ?, subtotal = ?, tax_amount = ?, total_amount = ?, updated_at = ? WHERE id = ?', [
            $doc['status'] === 'UPLOADED' ? 'EXTRACTED' : $doc['status'], $meta['provider'] ?? null, $d['confidence'] ?? null,
            json_encode($d['warnings'] ?? [], JSON_UNESCAPED_UNICODE), mb_substr((string)($meta['raw'] ?? ''), 0, 60000),
            self::clean($d['invoice_no'] ?? null), self::date($d['invoice_date'] ?? null), self::clean($d['supplier_name'] ?? null), self::clean($d['supplier_tax_id'] ?? null),
            self::clean($d['buyer_name'] ?? null), self::clean($po) ?? $doc['po_no'], self::clean($orig) ?? $doc['orig_invoice_no'],
            ($v = self::clean($d['vehicle_no'] ?? null)) ? self::keyOf($v) : null, self::clean($d['transporter'] ?? null), self::clean($d['eway_bill_no'] ?? null),
            strtoupper((string)self::clean($d['currency'] ?? null)) ?: null, self::num($d['subtotal'] ?? null), self::num($d['tax_amount'] ?? null), self::num($d['total_amount'] ?? null),
            date('Y-m-d H:i:s'), $id]);
        self::replaceLines($id, is_array($d['lines'] ?? null) ? $d['lines'] : []);
    }

    private static function clean(mixed $v): ?string
    {
        if ($v === null) { return null; }
        $s = trim(preg_replace('/\s+/', ' ', (string)$v) ?? '');
        return $s === '' ? null : mb_substr($s, 0, 200);
    }

    public static function replaceLines(int $id, array $lines): void
    {
        Db::q('DELETE FROM document_lines WHERE doc_id = ?', [$id]);
        $n = 0;
        foreach ($lines as $l) {
            $desc = self::clean($l['description'] ?? null); $code = self::clean($l['material_code'] ?? null);
            $qty = self::num($l['qty'] ?? null); $amt = self::num($l['amount'] ?? null);
            if ($desc === null && $code === null && $qty === null && $amt === null) { continue; }   // blank row
            $uom = self::uom($l['uom'] ?? null);
            Db::q('INSERT INTO document_lines(doc_id, line_no, material_code, description, hsn, qty, uom, rate, amount, qty_kg) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$id, ++$n, $code, $desc, self::clean($l['hsn'] ?? null), $qty, $uom, self::num($l['rate'] ?? null), $amt, self::qtyKg($qty, $uom)]);
        }
    }

    /** Save a person's edits from the review screen. */
    public static function save(int $id, array $in): void
    {
        $doc = self::get($id);
        if (!$doc || $doc['status'] === 'CANCELLED') { throw new RuntimeException('Document not found or cancelled.'); }
        if ($doc['status'] === 'VERIFIED') { throw new RuntimeException('Reopen the document before editing it.'); }
        $in += ['invoice_no' => $doc['invoice_no'], 'invoice_date' => $doc['invoice_date'], 'supplier' => $doc['supplier'], 'supplier_tax_id' => $doc['supplier_tax_id'], 'buyer' => $doc['buyer'],
                'po_no' => $doc['po_no'], 'ref_no' => $doc['ref_no'], 'orig_invoice_no' => $doc['orig_invoice_no'], 'vehicle_no' => $doc['vehicle_no'], 'transporter' => $doc['transporter'],
                'eway_no' => $doc['eway_no'], 'currency' => $doc['currency'], 'subtotal' => $doc['subtotal'], 'tax_amount' => $doc['tax_amount'], 'total_amount' => $doc['total_amount'], 'remarks' => $doc['remarks']];   // a partial post never wipes fields
        $type = isset(self::TYPES[$in['doc_type'] ?? '']) ? $in['doc_type'] : $doc['doc_type'];
        $veh = self::clean($in['vehicle_no'] ?? null);
        Db::q('UPDATE documents SET doc_type = ?, invoice_no = ?, invoice_date = ?, supplier = ?, supplier_tax_id = ?, buyer = ?, po_no = ?, ref_no = ?, orig_invoice_no = ?,
               vehicle_no = ?, transporter = ?, eway_no = ?, currency = ?, subtotal = ?, tax_amount = ?, total_amount = ?, remarks = ?,
               status = CASE WHEN status = \'UPLOADED\' THEN \'EXTRACTED\' ELSE status END, updated_at = ? WHERE id = ?', [
            $type, self::clean($in['invoice_no'] ?? null), self::date($in['invoice_date'] ?? null), self::clean($in['supplier'] ?? null), self::clean($in['supplier_tax_id'] ?? null),
            self::clean($in['buyer'] ?? null), self::clean($in['po_no'] ?? null), self::clean($in['ref_no'] ?? null), self::clean($in['orig_invoice_no'] ?? null),
            $veh ? self::keyOf($veh) : null, self::clean($in['transporter'] ?? null), self::clean($in['eway_no'] ?? null), strtoupper((string)self::clean($in['currency'] ?? null)) ?: null,
            self::num($in['subtotal'] ?? null), self::num($in['tax_amount'] ?? null), self::num($in['total_amount'] ?? null), self::clean($in['remarks'] ?? null),
            date('Y-m-d H:i:s'), $id]);
        if (isset($in['line']) && is_array($in['line'])) { self::replaceLines($id, array_values($in['line'])); }
    }

    public static function verify(int $id): void
    {
        $doc = self::get($id);
        if (!$doc || in_array($doc['status'], ['CANCELLED', 'VERIFIED'], true)) { throw new RuntimeException('Document not found, cancelled or already verified.'); }
        $missing = [];
        if (!$doc['invoice_no']) { $missing[] = 'invoice / challan number'; }
        if (!$doc['supplier']) { $missing[] = 'supplier'; }
        if (!self::lines($id)) { $missing[] = 'at least one line'; }
        if ($missing) { throw new RuntimeException('Fill in ' . implode(', ', $missing) . ' before verifying.'); }
        Db::q("UPDATE documents SET status = 'VERIFIED', verified_by = ?, verified_at = ?, updated_at = ? WHERE id = ?", [Auth::user()['username'] ?? 'system', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $id]);
        Db::audit('doc_verify', $doc['doc_no']);
    }

    public static function reopen(int $id): void
    {
        Db::q("UPDATE documents SET status = 'EXTRACTED', verified_by = NULL, verified_at = NULL, updated_at = ? WHERE id = ? AND status = 'VERIFIED'", [date('Y-m-d H:i:s'), $id]);
        Db::audit('doc_reopen', (string)$id);
    }

    public static function cancel(int $id, string $reason): void
    {
        $doc = self::get($id);
        if (!$doc) { return; }
        Db::q("UPDATE documents SET status = 'CANCELLED', remarks = TRIM(COALESCE(remarks,'') || ' [CANCELLED: ' || ? || ']'), updated_at = ? WHERE id = ?", [$reason, date('Y-m-d H:i:s'), $id]);
        Db::q('DELETE FROM document_tickets WHERE doc_id = ?', [$id]);
        Db::q('DELETE FROM doc_exceptions WHERE doc_id = ?', [$id]);
        Db::audit('doc_cancel', $doc['doc_no'] . ' ' . $reason);
    }

    // ------------------------------------------------------------------ outbound payload (SAP JSON and Oracle rows)
    public static function payload(int $id): array
    {
        $d = self::get($id);
        $tickets = Db::all('SELECT w.ticket_no, w.vehicle_no, w.direction, w.net_kg, w.gross_kg, w.tare_kg, w.first_at, w.second_at, w.status FROM document_tickets dt
                            JOIN weighments w ON w.id = dt.ticket_id WHERE dt.doc_id = ? AND dt.link <> \'BLOCK\' ORDER BY w.id', [$id]);
        $exc = Db::all('SELECT code, severity, message, status, note FROM doc_exceptions WHERE doc_id = ? ORDER BY severity, code', [$id]);
        return [
            'documentNo' => $d['doc_no'], 'documentType' => $d['doc_type'], 'invoiceNo' => $d['invoice_no'], 'invoiceDate' => $d['invoice_date'],
            'supplier' => $d['supplier'], 'supplierTaxId' => $d['supplier_tax_id'], 'buyer' => $d['buyer'],
            'poNo' => $d['po_no'], 'referenceNo' => $d['ref_no'], 'originalInvoiceNo' => $d['orig_invoice_no'],
            'vehicleNo' => $d['vehicle_no'], 'ewayBillNo' => $d['eway_no'], 'currency' => $d['currency'],
            'subtotal' => $d['subtotal'], 'taxAmount' => $d['tax_amount'], 'totalAmount' => $d['total_amount'],
            'lines' => array_map(fn($l) => ['lineNo' => (int)$l['line_no'], 'materialCode' => $l['material_code'], 'description' => $l['description'], 'hsn' => $l['hsn'],
                'quantity' => $l['qty'], 'uom' => $l['uom'], 'rate' => $l['rate'], 'amount' => $l['amount'], 'quantityKg' => $l['qty_kg']], self::lines($id)),
            'weighbridge' => ['ticketNos' => array_column($tickets, 'ticket_no'), 'netKg' => $d['wb_net_kg'] !== null ? (float)$d['wb_net_kg'] : null, 'tickets' => $tickets],
            'matchStatus' => $d['match_status'],
            'exceptions' => array_map(fn($e) => ['code' => $e['code'], 'severity' => $e['severity'], 'message' => $e['message'], 'status' => $e['status'], 'note' => $e['note']], $exc),
            'verifiedBy' => $d['verified_by'], 'verifiedAt' => $d['verified_at'],
        ];
    }

    public static function payloadHash(int $id): string { return md5(json_encode(self::payload($id), JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR)); }
}
