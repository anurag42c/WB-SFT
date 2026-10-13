<?php
/**
 * Purchase-order master used to validate invoices.
 *   GET  purchase_orders.php?po=4500012345                 lines of one PO
 *   POST purchase_orders.php   {"pos":[{"po_no":"P00012","vendor":"ABC","po_date":"2026-09-01","lines":[{"line_no":10,"material_code":"IRON","description":"Iron ore","qty":40,"uom":"MT","rate":1200}]}]}
 *        replaces all lines of each PO sent (needs "Allow API writes" in Setup)
 */
require_once __DIR__ . '/../../../src/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    Api::begin(true);
    $b = Api::body(); $pos = $b['pos'] ?? (isset($b['po_no']) ? [$b] : null);
    if (!is_array($pos) || !$pos || count($pos) > 500) { Api::err(400, 'send {"pos":[...]} with 1-500 purchase orders'); }
    $pdo = Db::pdo(); $pdo->exec('BEGIN IMMEDIATE'); $n = 0; $nl = 0;
    try {
        foreach ($pos as $i => $p) {
            $po = trim((string)($p['po_no'] ?? '')); $lines = $p['lines'] ?? null;
            if ($po === '' || !is_array($lines) || !$lines) { throw new InvalidArgumentException("pos[$i]: po_no and lines are required"); }
            Db::q("DELETE FROM po_lines WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(po_no,'-',''),'/',''),' ',''),'.','')) = ?", [Documents::keyOf($po)]);
            foreach ($lines as $j => $l) {
                $q = Documents::num($l['qty'] ?? null);
                if ($q === null) { throw new InvalidArgumentException("pos[$i].lines[$j]: qty is required"); }
                Db::q("INSERT INTO po_lines(po_no, line_no, vendor, material_code, description, qty, uom, rate, po_date, source) VALUES (?,?,?,?,?,?,?,?,?, 'API')",
                    [$po, isset($l['line_no']) ? (string)$l['line_no'] : (string)(($j + 1) * 10), trim((string)($p['vendor'] ?? '')) ?: null, trim((string)($l['material_code'] ?? '')) ?: null,
                     trim((string)($l['description'] ?? '')) ?: null, $q, Documents::uom($l['uom'] ?? null), Documents::num($l['rate'] ?? null), Documents::date($p['po_date'] ?? null)]);
                $nl++;
            }
            $n++;
        }
        $pdo->exec('COMMIT');
    } catch (InvalidArgumentException $e) { $pdo->exec('ROLLBACK'); Api::err(422, $e->getMessage()); }
    catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK'); } Api::err(500, 'could not store'); }
    Db::audit('api_po_upsert', "$n POs, $nl lines");
    Api::out(['ok' => true, 'pos' => $n, 'lines' => $nl]);
}
Api::begin();
$po = trim((string)($_GET['po'] ?? ''));
if ($po === '') { Api::out(['count' => PurchaseOrders::count()]); }
$l = PurchaseOrders::lines($po);
$l ? Api::out(['po_no' => $l[0]['po_no'], 'vendor' => $l[0]['vendor'], 'lines' => array_map(fn($x) => array_intersect_key($x, array_flip(['line_no', 'material_code', 'description', 'qty', 'uom', 'rate', 'source'])), $l)]) : Api::err(404, 'PO not found');
