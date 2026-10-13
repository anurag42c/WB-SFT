<?php
declare(strict_types=1);

/**
 * Matches a document with weighbridge tickets and produces the exception list.
 *
 * Exception codes (severity):
 *  HIGH  NO_TICKET, QTY_MISMATCH, VEHICLE_MISMATCH, DIRECTION_MISMATCH, TICKET_CANCELLED, TICKET_MULTI_DOC, DUPLICATE_INVOICE,
 *        PO_MISSING, PO_UNKNOWN, PO_VENDOR_MISMATCH, PO_OVER_QTY, RETURN_OVER_QTY
 *  WARN  PARTY_MISMATCH, MATERIAL_MISMATCH, DATE_MISMATCH, TICKET_OPEN, UOM_MISSING, NO_INVOICE_NO, NO_SUPPLIER, NO_DATE, NO_LINES,
 *        PO_LINE_UNKNOWN, PO_RATE_MISMATCH, RETURN_NO_REF, RETURN_ORIG_UNKNOWN, RETURN_SUPPLIER_MISMATCH, RETURN_LINE_UNKNOWN, NO_DOCUMENT (ticket with no invoice)
 *  INFO  QTY_UNCHECKED, PO_NOT_CHECKED
 */
final class DocMatch
{
    private const SERVICE_RE = '/\b(loading|unloading|handling|freight|transport(?:ation)?|service|services|charges?|packing|insurance|labou?r|commission|cartage)\b/i';

    /** A line that is goods (not a service/charge): used for the quantity comparison. */
    private static function isGoods(array $l): bool
    {
        return !(str_starts_with((string)$l['hsn'], '99') || preg_match(self::SERVICE_RE, (string)$l['description']));
    }

    private static function ticketDate(array $t): string { return substr((string)($t['second_at'] ?: $t['first_at'] ?: $t['created_at']), 0, 10); }

    // ------------------------------------------------------------------ candidates and links
    /** Weighbridge tickets that might belong to this document, best first. */
    public static function candidates(array $doc, array $lines): array
    {
        $days = max(0, (int)Settings::get('doc_match_days', '5'));
        $dir = $doc['doc_type'] === 'MATERIAL_RETURN' ? 'OUTWARD' : 'INWARD';
        $date = $doc['invoice_date'] ?: null;
        $inv = Documents::keyOf($doc['invoice_no']);
        $sql = "SELECT * FROM weighments WHERE status <> 'CANCELLED' AND direction = ? AND (";
        $args = [$dir];
        if ($date) { $sql .= "date(COALESCE(second_at, first_at)) BETWEEN date(?, ?) AND date(?, ?)"; array_push($args, $date, "-{$days} days", $date, "+{$days} days"); }
        else { $sql .= "date(COALESCE(second_at, first_at)) >= date('now', '-30 days')"; }
        if ($inv !== '') { $sql .= " OR UPPER(REPLACE(REPLACE(REPLACE(REPLACE(challan_no,'-',''),'/',''),' ',''),'.','')) = ?"; $args[] = $inv; }
        $sql .= ') ORDER BY id DESC LIMIT 300';
        $kg = self::docKg($lines, true);
        $tol = (float)Settings::get('doc_qty_tol_pct', '2') / 100;
        $out = [];
        foreach (Db::all($sql, $args) as $t) {
            $score = 0.0; $why = [];
            if ($doc['vehicle_no'] && Anpr::exact($doc['vehicle_no'], $t['vehicle_no'])) { $score += 50; $why[] = 'vehicle'; }
            elseif ($doc['vehicle_no'] && Anpr::matches($doc['vehicle_no'], $t['vehicle_no'])) { $score += 30; $why[] = 'vehicle (1 character off)'; }   // possible misread: needs other evidence
            if ($inv !== '' && Documents::keyOf($t['challan_no']) === $inv) { $score += 60; $why[] = 'challan no = invoice no'; }
            $ps = $t['party'] ? Documents::sim($doc['supplier'], $t['party']) : 0.0;
            if ($ps >= 0.5) { $score += 15 * $ps; $why[] = 'party'; }
            $ms = 0.0; foreach ($lines as $l) { $ms = max($ms, $t['material'] ? Documents::sim($l['description'], $t['material']) : 0.0); }
            if ($ms >= 0.5) { $score += 10 * $ms; $why[] = 'material'; }
            if ($kg !== null && $t['net_kg'] !== null && (float)$t['net_kg'] > 0 && abs($kg - (float)$t['net_kg']) <= $tol * (float)$t['net_kg']) { $score += 15; $why[] = 'weight'; }
            if ($date) {
                $dd = abs((strtotime(self::ticketDate($t)) - strtotime($date)) / 86400);
                if ($dd <= 1) { $score += 5; } elseif ($dd <= $days) { $score += 2; }
            }
            if ($score > 0) { $out[] = ['ticket' => $t, 'score' => round($score, 1), 'why' => $why]; }
        }
        usort($out, fn($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    /** Sum of goods quantity in kg (null if no weight-based line). $goodsOnly excludes service/charge lines. */
    private static function docKg(array $lines, bool $goodsOnly): ?float
    {
        $s = null;
        foreach ($lines as $l) {
            if ($l['qty_kg'] === null || ($goodsOnly && !self::isGoods($l))) { continue; }
            $s = ($s ?? 0.0) + (float)$l['qty_kg'];
        }
        return $s;
    }

    private static function linkedElsewhere(int $ticketId, int $exceptDoc): ?array
    {
        return Db::one("SELECT d.id, d.doc_no FROM document_tickets dt JOIN documents d ON d.id = dt.doc_id WHERE dt.ticket_id = ? AND dt.doc_id <> ? AND dt.link <> 'BLOCK' AND d.status <> 'CANCELLED' LIMIT 1", [$ticketId, $exceptDoc]);
    }

    public static function link(int $docId, int $ticketId): void
    {
        Db::q("INSERT INTO document_tickets(doc_id, ticket_id, link, score) VALUES (?,?, 'MANUAL', NULL) ON CONFLICT(doc_id, ticket_id) DO UPDATE SET link = 'MANUAL'", [$docId, $ticketId]);
        Db::audit('doc_link', "doc $docId ticket $ticketId");
        self::run($docId);
    }

    public static function unlink(int $docId, int $ticketId): void
    {
        // a removed automatic link is remembered as BLOCK so the next run does not put the same ticket back
        Db::q("UPDATE document_tickets SET link = 'BLOCK' WHERE doc_id = ? AND ticket_id = ? AND link = 'AUTO'", [$docId, $ticketId]);
        Db::q("DELETE FROM document_tickets WHERE doc_id = ? AND ticket_id = ? AND link = 'MANUAL'", [$docId, $ticketId]);
        Db::audit('doc_unlink', "doc $docId ticket $ticketId");
        self::run($docId);
    }

    private static function autoLink(array $doc, array $lines): void
    {
        if (Db::val("SELECT COUNT(*) FROM document_tickets WHERE doc_id = ? AND link = 'MANUAL'", [$doc['id']])) {     // a person chose the tickets: leave them
            Db::q("DELETE FROM document_tickets WHERE doc_id = ? AND link = 'AUTO'", [$doc['id']]);
            return;
        }
        $had = array_map('intval', array_column(Db::all("SELECT ticket_id FROM document_tickets WHERE doc_id = ? AND link = 'AUTO'", [$doc['id']]), 'ticket_id'));
        $keep = [];
        $kg = self::docKg($lines, true);
        $tol = (float)Settings::get('doc_qty_tol_pct', '2') / 100;
        $sum = 0.0; $n = 0;
        foreach (self::candidates($doc, $lines) as $c) {
            if ($c['score'] < 50 || $n >= 5) { break; }
            $t = $c['ticket'];
            if (Db::val("SELECT 1 FROM document_tickets WHERE doc_id = ? AND ticket_id = ? AND link = 'BLOCK'", [$doc['id'], $t['id']])) { continue; }
            // a ticket already claimed by another document is not taken over - but this document keeps one it already had (so double billing shows up)
            if (self::linkedElsewhere((int)$t['id'], (int)$doc['id']) && !in_array((int)$t['id'], $had, true)) { continue; }
            if ($n > 0) {                                     // split loads: add more trips only while the invoice quantity is not yet reached
                if ($kg === null || $sum >= $kg * (1 - $tol)) { break; }
                if (!in_array('vehicle', $c['why'], true) && !in_array('challan no = invoice no', $c['why'], true)) { continue; }   // extra trips need the same vehicle (exact) or challan
            }
            Db::q("INSERT INTO document_tickets(doc_id, ticket_id, link, score) VALUES (?,?, 'AUTO', ?) ON CONFLICT(doc_id, ticket_id) DO UPDATE SET score = excluded.score WHERE link = 'AUTO'", [$doc['id'], $t['id'], $c['score']]);
            $keep[] = (int)$t['id']; $sum += (float)($t['net_kg'] ?? 0); $n++;
        }
        Db::q("DELETE FROM document_tickets WHERE doc_id = ? AND link = 'AUTO'" . ($keep ? ' AND ticket_id NOT IN (' . implode(',', $keep) . ')' : ''), [$doc['id']]);
    }

    public static function linkedTickets(int $docId): array
    {
        return Db::all('SELECT w.*, dt.link, dt.score FROM document_tickets dt JOIN weighments w ON w.id = dt.ticket_id WHERE dt.doc_id = ? AND dt.link <> \'BLOCK\' ORDER BY w.id', [$docId]);
    }

    // ------------------------------------------------------------------ the check
    /** Quantity facts shown on the screen and used by the QTY check. */
    public static function facts(int $docId): array
    {
        $lines = Documents::lines($docId);
        $tk = self::linkedTickets($docId);
        $tolPct = (float)Settings::get('doc_qty_tol_pct', '2');
        $net = null; foreach ($tk as $t) { if ($t['status'] !== 'CANCELLED' && $t['net_kg'] !== null) { $net = ($net ?? 0.0) + (float)$t['net_kg']; } }
        $goods = self::docKg($lines, true); $all = self::docKg($lines, false);
        $cands = array_values(array_filter([$goods, $all] + array_map(fn($l) => $l['qty_kg'] !== null ? (float)$l['qty_kg'] : null, $lines), fn($v) => $v !== null));
        $best = null;
        if ($net !== null) { foreach ($cands as $v) { if ($best === null || abs($v - $net) < abs($best - $net)) { $best = $v; } } }
        $ok = $net !== null && $best !== null && $net > 0 && abs($best - $net) <= $net * $tolPct / 100;
        return ['doc_kg' => $goods ?? $all, 'closest_kg' => $best, 'ticket_kg' => $net, 'diff_kg' => ($net !== null && $best !== null) ? round($best - $net, 1) : null,
                'diff_pct' => ($net && $best !== null) ? round(($best - $net) / $net * 100, 2) : null, 'ok' => $ok, 'tol_pct' => $tolPct, 'checkable' => $goods !== null || $all !== null];
    }

    /** @return array{high: int, warn: int, match_status: string} */
    public static function run(int $docId): array
    {
        $doc = Documents::get($docId);
        if (!$doc || $doc['status'] === 'CANCELLED') { return ['high' => 0, 'warn' => 0, 'match_status' => 'UNMATCHED']; }
        $lines = Documents::lines($docId);
        $tolPct = (float)Settings::get('doc_qty_tol_pct', '2');
        $days = max(0, (int)Settings::get('doc_match_days', '5'));
        $exc = [];
        $add = function (string $code, string $sev, string $msg, string $detail = '', ?int $ticket = null) use (&$exc, $docId) {
            $exc["d$docId|$code|$detail"] = ['code' => $code, 'severity' => $sev, 'message' => $msg, 'ticket' => $ticket];
        };
        $fmt = fn($v) => number_format((float)$v, 0);

        // ---- the document itself
        if (!$doc['invoice_no']) { $add('NO_INVOICE_NO', 'WARN', 'No invoice / challan number on the document.'); }
        if (!$doc['supplier']) { $add('NO_SUPPLIER', 'WARN', 'Supplier name is missing.'); }
        if (!$doc['invoice_date']) { $add('NO_DATE', 'WARN', 'Document date is missing.'); }
        if (!$lines) { $add('NO_LINES', 'WARN', 'The document has no line items.'); }
        if ($doc['invoice_no']) {
            foreach (Db::all("SELECT id, doc_no, supplier FROM documents WHERE id <> ? AND status <> 'CANCELLED' AND doc_type = ?", [$docId, $doc['doc_type']]) as $o) {
                $oi = Db::val('SELECT invoice_no FROM documents WHERE id = ?', [$o['id']]);
                if (Documents::keyOf($oi) === Documents::keyOf($doc['invoice_no']) && ($doc['supplier'] === null || Documents::sim($doc['supplier'], $o['supplier']) >= 0.7)) {
                    $add('DUPLICATE_INVOICE', 'HIGH', "Invoice {$doc['invoice_no']} from this supplier was already uploaded as {$o['doc_no']}.", $o['doc_no']);
                }
            }
        }

        // ---- purchase order (invoices) or original invoice (returns)
        if ($doc['doc_type'] === 'PO_INVOICE') { self::checkPo($doc, $lines, $add, $tolPct); }
        else { self::checkReturn($doc, $lines, $add, $tolPct); }

        // ---- weighbridge
        self::autoLink($doc, $lines);
        $tickets = self::linkedTickets($docId);
        $dirWant = $doc['doc_type'] === 'MATERIAL_RETURN' ? 'OUTWARD' : 'INWARD';
        if (!$tickets) {
            $hint = $doc['vehicle_no'] ? "vehicle {$doc['vehicle_no']}" : 'no vehicle number on the document - link a ticket by hand';
            $add('NO_TICKET', 'HIGH', 'No weighbridge ticket found for this document (' . $hint . ($doc['invoice_date'] ? ", around {$doc['invoice_date']}" : '') . '). The goods may not have been weighed.');
        }
        foreach ($tickets as $t) {
            $tn = $t['ticket_no']; $tid = (int)$t['id'];
            if ($t['status'] === 'CANCELLED') { $add('TICKET_CANCELLED', 'HIGH', "Linked ticket $tn is CANCELLED.", $tn, $tid); continue; }
            if ($t['status'] === 'OPEN') { $add('TICKET_OPEN', 'WARN', "Ticket $tn is not complete (second weighment pending).", $tn, $tid); }
            if ($t['direction'] !== $dirWant) { $add('DIRECTION_MISMATCH', 'HIGH', "Ticket $tn is {$t['direction']} but a " . ($doc['doc_type'] === 'MATERIAL_RETURN' ? 'material return should be OUTWARD' : 'purchase invoice should be INWARD') . '.', $tn, $tid); }
            if ($doc['vehicle_no'] && $t['vehicle_no'] && !Anpr::exact($doc['vehicle_no'], $t['vehicle_no'])) {
                if (Anpr::matches($doc['vehicle_no'], $t['vehicle_no'])) { $add('VEHICLE_MISMATCH', 'WARN', "Document vehicle {$doc['vehicle_no']} differs from ticket $tn vehicle {$t['vehicle_no']} by one character - misread or a different truck?", $tn, $tid); }
                else { $add('VEHICLE_MISMATCH', 'HIGH', "Document vehicle {$doc['vehicle_no']} differs from ticket $tn vehicle {$t['vehicle_no']}.", $tn, $tid); }
            }
            if ($t['party'] && $doc['supplier'] && Documents::sim($doc['supplier'], $t['party']) < 0.5) {
                $add('PARTY_MISMATCH', 'WARN', "Supplier '{$doc['supplier']}' differs from party '{$t['party']}' on ticket $tn.", $tn, $tid);
            }
            if ($t['material'] && $lines) {
                $ms = 0.0; foreach ($lines as $l) { $ms = max($ms, Documents::sim($l['description'], $t['material'])); }
                if ($ms < 0.4) { $add('MATERIAL_MISMATCH', 'WARN', "Material '{$t['material']}' on ticket $tn does not resemble any invoice line.", $tn, $tid); }
            }
            if ($doc['invoice_date'] && abs((strtotime(self::ticketDate($t)) - strtotime($doc['invoice_date'])) / 86400) > $days) {
                $add('DATE_MISMATCH', 'WARN', "Ticket $tn was weighed on " . self::ticketDate($t) . ", {$doc['invoice_date']} on the document (more than $days days apart).", $tn, $tid);
            }
            if ($o = self::linkedElsewhere($tid, $docId)) { $add('TICKET_MULTI_DOC', 'HIGH', "Ticket $tn is also linked to {$o['doc_no']} (possible double billing).", $tn, $tid); }
        }
        if ($tickets) {
            $f = self::facts($docId);
            if (!$f['checkable']) { $add('QTY_UNCHECKED', 'INFO', 'Quantity could not be compared: the document lines have no weight unit (kg / MT).'); }
            elseif ($f['ticket_kg'] !== null && !$f['ok']) {
                $add('QTY_MISMATCH', 'HIGH', 'Quantity differs: document ' . $fmt($f['closest_kg']) . ' kg vs weighbridge net ' . $fmt($f['ticket_kg']) . ' kg (' . sprintf('%+.1f kg, %+.2f%%', $f['diff_kg'], $f['diff_pct']) . "; tolerance $tolPct%).");
            }
            foreach ($lines as $l) {          // a missing unit: suggest what the weighbridge weight implies
                if ($l['qty'] !== null && !$l['uom'] && $f['ticket_kg']) {
                    $guess = null;
                    foreach (['MT' => 1000, 'KG' => 1, 'QTL' => 100] as $u => $k) { if (abs($l['qty'] * $k - $f['ticket_kg']) <= $f['ticket_kg'] * $tolPct / 100) { $guess = $u; break; } }
                    $add('UOM_MISSING', 'WARN', "Line {$l['line_no']} has no unit." . ($guess ? " The weighbridge weight suggests it is $guess." : ''), (string)$l['line_no']);
                }
            }
        } else {
            foreach ($lines as $l) { if ($l['qty'] !== null && !$l['uom']) { $add('UOM_MISSING', 'WARN', "Line {$l['line_no']} has no unit.", (string)$l['line_no']); } }
        }

        return self::store($docId, $exc);
    }

    /** Persist the computed exceptions (keeping people's resolve/waive decisions), refresh counters and the sync state. */
    private static function store(int $docId, array $exc): array
    {
        $now = date('Y-m-d H:i:s');
        $old = [];
        foreach (Db::all('SELECT id, ekey FROM doc_exceptions WHERE doc_id = ?', [$docId]) as $r) { $old[$r['ekey']] = (int)$r['id']; }
        foreach ($exc as $key => $e) {
            if (isset($old[$key])) { Db::q('UPDATE doc_exceptions SET severity = ?, message = ?, ticket_id = ? WHERE id = ?', [$e['severity'], $e['message'], $e['ticket'], $old[$key]]); unset($old[$key]); }
            else { Db::q("INSERT INTO doc_exceptions(doc_id, ticket_id, code, severity, message, ekey, status, created_at) VALUES (?,?,?,?,?,?, 'OPEN', ?)", [$docId, $e['ticket'], $e['code'], $e['severity'], $e['message'], $key, $now]); }
        }
        foreach ($old as $id) { Db::q('DELETE FROM doc_exceptions WHERE id = ?', [$id]); }   // no longer applicable
        return self::finalize($docId);
    }

    /** Recount exceptions, derive match status, cache ticket info, and mark the document for re-sending if its payload changed. */
    public static function finalize(int $docId): array
    {
        $high = (int)Db::val("SELECT COUNT(*) FROM doc_exceptions WHERE doc_id = ? AND status = 'OPEN' AND severity = 'HIGH'", [$docId]);
        $warn = (int)Db::val("SELECT COUNT(*) FROM doc_exceptions WHERE doc_id = ? AND status = 'OPEN' AND severity = 'WARN'", [$docId]);
        $tk = self::linkedTickets($docId);
        $status = !$tk ? 'UNMATCHED' : ($high ? 'EXCEPTION' : ($warn ? 'REVIEW' : 'MATCHED'));
        $net = null; foreach ($tk as $t) { if ($t['status'] !== 'CANCELLED' && $t['net_kg'] !== null) { $net = ($net ?? 0.0) + (float)$t['net_kg']; } }
        Db::q('UPDATE documents SET exc_high = ?, exc_warn = ?, match_status = ?, wb_net_kg = ?, wb_tickets = ? WHERE id = ?',
            [$high, $warn, $status, $net, implode(',', array_column($tk, 'ticket_no')) ?: null, $docId]);
        DocSync::refreshDirty($docId);
        return ['high' => $high, 'warn' => $warn, 'match_status' => $status];
    }

    public static function setExceptionStatus(int $excId, string $status, string $note): void
    {
        if (!in_array($status, ['OPEN', 'RESOLVED', 'WAIVED'], true)) { throw new RuntimeException('Bad status.'); }
        $e = Db::one('SELECT * FROM doc_exceptions WHERE id = ?', [$excId]);
        if (!$e) { throw new RuntimeException('Exception not found.'); }
        if ($status === 'WAIVED' && !Auth::isAdmin()) { throw new RuntimeException('Only an administrator can waive an exception.'); }
        if ($status !== 'OPEN' && trim($note) === '') { throw new RuntimeException('Please write a short note explaining the decision.'); }
        Db::q('UPDATE doc_exceptions SET status = ?, note = ?, updated_by = ?, updated_at = ? WHERE id = ?', [$status, trim($note) ?: null, Auth::user()['username'] ?? 'system', date('Y-m-d H:i:s'), $excId]);
        Db::audit('doc_exception_' . strtolower($status), $e['code'] . ' ' . $e['ekey']);
        if ($e['doc_id']) { self::finalize((int)$e['doc_id']); }
    }

    // ------------------------------------------------------------------ PO checks
    /** Comparable quantity: kg when both are weight units, else the raw number if the units are identical, else null. */
    private static function comparable(?float $qty, ?string $uom, ?string $poUom): ?float
    {
        if ($qty === null) { return null; }
        $kg = Documents::qtyKg($qty, $uom);
        if ($kg !== null && Documents::qtyKg(1.0, $poUom) !== null) { return $kg; }
        return (Documents::uom($uom) !== null && Documents::uom($uom) === Documents::uom($poUom)) ? $qty : null;
    }

    /** Which PO line does an invoice line refer to: same material code, else best description match. */
    private static function matchPoLine(array $line, array $poLines): ?array
    {
        if ($line['material_code']) { foreach ($poLines as $p) { if ($p['material_code'] && Documents::keyOf($p['material_code']) === Documents::keyOf($line['material_code'])) { return $p; } } }
        $best = null; $bs = 0.0;
        foreach ($poLines as $p) { $s = Documents::sim($line['description'], trim($p['description'] . ' ' . $p['material_code'])); if ($s > $bs) { $bs = $s; $best = $p; } }
        return $bs >= 0.6 ? $best : null;
    }

    private static function checkPo(array $doc, array $lines, callable $add, float $tolPct): void
    {
        $po = trim((string)$doc['po_no']);
        if ($po === '') { $add('PO_MISSING', 'HIGH', 'No PO number on the document - a purchase invoice must reference a purchase order.'); return; }
        $poLines = PurchaseOrders::ensure($po);
        if (!$poLines) {
            if (PurchaseOrders::count() > 0 || trim((string)Settings::get('sap_po_url')) !== '') { $add('PO_UNKNOWN', 'HIGH', "PO $po was not found in the PO list" . (trim((string)Settings::get('sap_po_url')) !== '' ? ' or in SAP' : '') . '.'); }
            else { $add('PO_NOT_CHECKED', 'INFO', 'The PO was not verified: no PO list is loaded and no SAP lookup is configured.'); }
            return;
        }
        if ($poLines[0]['vendor'] && $doc['supplier'] && Documents::sim($doc['supplier'], $poLines[0]['vendor']) < 0.6) {
            $add('PO_VENDOR_MISMATCH', 'HIGH', "Supplier '{$doc['supplier']}' is not the vendor on PO $po ('{$poLines[0]['vendor']}').");
        }
        $others = Db::all("SELECT id FROM documents WHERE id <> ? AND status <> 'CANCELLED' AND doc_type = 'PO_INVOICE' AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(po_no,'-',''),'/',''),' ',''),'.','')) = ?", [$doc['id'], Documents::keyOf($po)]);
        foreach ($lines as $l) {
            if (!self::isGoods($l)) { continue; }
            $p = self::matchPoLine($l, $poLines);
            if (!$p) { $add('PO_LINE_UNKNOWN', 'WARN', "Line {$l['line_no']} ('{$l['description']}') is not on PO $po.", (string)$l['line_no']); continue; }
            if ($l['rate'] !== null && $p['rate'] && abs($l['rate'] - $p['rate']) / $p['rate'] > 0.01) {
                $add('PO_RATE_MISMATCH', 'WARN', "Line {$l['line_no']}: rate " . $l['rate'] . " differs from PO rate " . $p['rate'] . '.', (string)$l['line_no']);
            }
            $total = self::comparable($l['qty'] !== null ? (float)$l['qty'] : null, $l['uom'], $p['uom']);
            if ($total === null) { continue; }
            foreach ($others as $o) {
                foreach (Documents::lines((int)$o['id']) as $ol) {
                    $op = self::matchPoLine($ol, $poLines);
                    if ($op && (int)$op['id'] === (int)$p['id']) { $total += (float)self::comparable($ol['qty'] !== null ? (float)$ol['qty'] : null, $ol['uom'], $p['uom']); }
                }
            }
            $ordered = self::comparable((float)$p['qty'], $p['uom'], $p['uom']);
            if ($ordered !== null && $total > $ordered * (1 + $tolPct / 100)) {
                $u = Documents::qtyKg(1.0, $p['uom']) !== null ? 'kg' : (string)$p['uom'];
                $add('PO_OVER_QTY', 'HIGH', "PO $po line {$p['line_no']}: invoiced in total " . number_format($total) . " $u exceeds the ordered " . number_format($ordered) . " $u.", (string)$l['line_no']);
            }
        }
    }

    private static function checkReturn(array $doc, array $lines, callable $add, float $tolPct): void
    {
        $orig = trim((string)($doc['orig_invoice_no'] ?: $doc['ref_no']));
        if ($orig === '') { $add('RETURN_NO_REF', 'WARN', 'The return does not name the invoice it is returning material from.'); return; }
        $o = null;
        foreach (Db::all("SELECT * FROM documents WHERE id <> ? AND status <> 'CANCELLED' AND doc_type = 'PO_INVOICE'", [$doc['id']]) as $c) {
            if (Documents::keyOf($c['invoice_no']) === Documents::keyOf($orig)) { $o = $c; if (!$doc['supplier'] || Documents::sim($doc['supplier'], $c['supplier']) >= 0.6) { break; } }
        }
        if (!$o) { $add('RETURN_ORIG_UNKNOWN', 'WARN', "The original invoice $orig is not among the uploaded documents."); return; }
        if ($doc['supplier'] && Documents::sim($doc['supplier'], $o['supplier']) < 0.6) {
            $add('RETURN_SUPPLIER_MISMATCH', 'WARN', "The return is to '{$doc['supplier']}' but invoice $orig is from '{$o['supplier']}'.");
        }
        $olines = Documents::lines((int)$o['id']);
        $prior = Db::all("SELECT id FROM documents WHERE id <> ? AND status <> 'CANCELLED' AND doc_type = 'MATERIAL_RETURN' AND (UPPER(REPLACE(REPLACE(orig_invoice_no,'-',''),'/','')) = ? OR UPPER(REPLACE(REPLACE(ref_no,'-',''),'/','')) = ?)",
            [$doc['id'], Documents::keyOf($orig), Documents::keyOf($orig)]);
        foreach ($lines as $l) {
            if (!self::isGoods($l)) { continue; }
            $p = self::matchPoLine($l, $olines);
            if (!$p) { $add('RETURN_LINE_UNKNOWN', 'WARN', "Returned item '{$l['description']}' is not on invoice $orig.", (string)$l['line_no']); continue; }
            $ret = self::comparable($l['qty'] !== null ? (float)$l['qty'] : null, $l['uom'], $p['uom']);
            if ($ret === null) { continue; }
            foreach ($prior as $pr) {
                foreach (Documents::lines((int)$pr['id']) as $rl) {
                    $m = self::matchPoLine($rl, $olines);
                    if ($m && (int)$m['id'] === (int)$p['id']) { $ret += (float)self::comparable($rl['qty'] !== null ? (float)$rl['qty'] : null, $rl['uom'], $p['uom']); }
                }
            }
            $bought = self::comparable((float)$p['qty'], $p['uom'], $p['uom']);
            if ($bought !== null && $ret > $bought * (1 + $tolPct / 100)) {
                $add('RETURN_OVER_QTY', 'HIGH', "Returned " . number_format($ret) . " in total but invoice $orig only had " . number_format($bought) . " of '{$p['description']}'.", (string)$l['line_no']);
            }
        }
    }

    // ------------------------------------------------------------------ reverse check: weighed but no invoice
    /** Inward tickets with no document after the grace period become NO_DOCUMENT exceptions; ones that got a document are cleared. */
    public static function scanTickets(): int
    {
        $days = max(1, (int)Settings::get('doc_scan_days', '14'));
        $grace = max(0, (int)Settings::get('doc_scan_grace_h', '24'));
        $rows = Db::all("SELECT w.* FROM weighments w WHERE w.status = 'CLOSED' AND w.direction = 'INWARD'
                         AND w.second_at <= datetime('now', 'localtime', ?) AND w.second_at >= datetime('now', 'localtime', ?)
                         AND NOT EXISTS (SELECT 1 FROM document_tickets dt JOIN documents d ON d.id = dt.doc_id WHERE dt.ticket_id = w.id AND dt.link <> 'BLOCK' AND d.status <> 'CANCELLED')",
            ["-{$grace} hours", "-{$days} days"]);
        $now = date('Y-m-d H:i:s'); $keep = [];
        foreach ($rows as $t) {
            $key = 'T' . $t['id'] . '|NO_DOCUMENT'; $keep[] = $key;
            $msg = "Ticket {$t['ticket_no']} ({$t['vehicle_no']}, " . number_format((float)$t['net_kg']) . ' kg' . ($t['party'] ? ", {$t['party']}" : '') . ') was weighed on ' . substr((string)$t['second_at'], 0, 10) . ' but has no invoice document.';
            $ex = Db::one('SELECT id FROM doc_exceptions WHERE ekey = ?', [$key]);
            if ($ex) { Db::q('UPDATE doc_exceptions SET message = ? WHERE id = ?', [$msg, $ex['id']]); }
            else { Db::q("INSERT INTO doc_exceptions(doc_id, ticket_id, code, severity, message, ekey, status, created_at) VALUES (NULL, ?, 'NO_DOCUMENT', 'WARN', ?, ?, 'OPEN', ?)", [$t['id'], $msg, $key, $now]); }
        }
        foreach (Db::all("SELECT id, ekey FROM doc_exceptions WHERE doc_id IS NULL AND code = 'NO_DOCUMENT'") as $r) { if (!in_array($r['ekey'], $keep, true)) { Db::q('DELETE FROM doc_exceptions WHERE id = ?', [$r['id']]); } }
        return count($rows);
    }
}
