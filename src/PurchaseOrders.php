<?php
declare(strict_types=1);

/** Purchase-order master used to check invoices: imported from a CSV export, or fetched on demand from SAP. */
final class PurchaseOrders
{
    private const COLS = [
        'po_no' => ['po_no', 'po', 'pono', 'po_number', 'purchase_order', 'purchaseorder', 'ebeln', 'order_no'],
        'line_no' => ['line_no', 'line', 'item', 'po_item', 'ebelp', 'item_no'],
        'vendor' => ['vendor', 'supplier', 'vendor_name', 'supplier_name', 'name1', 'party'],
        'material_code' => ['material', 'material_code', 'matnr', 'item_code', 'code'],
        'description' => ['description', 'text', 'short_text', 'txz01', 'material_description', 'item_description'],
        'qty' => ['qty', 'quantity', 'menge', 'order_qty', 'ordered_qty'],
        'uom' => ['uom', 'unit', 'meins', 'unit_of_measure'],
        'rate' => ['rate', 'price', 'net_price', 'netpr', 'unit_price'],
        'po_date' => ['po_date', 'date', 'bedat', 'order_date'],
    ];

    public static function count(): int { return (int)Db::val('SELECT COUNT(DISTINCT po_no) FROM po_lines'); }

    private static function key(string $po): string { return Documents::keyOf($po); }

    public static function lines(string $po): array
    {
        return Db::all("SELECT * FROM po_lines WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(po_no,'-',''),'/',''),' ',''),'.','')) = ? ORDER BY id", [self::key($po)]);
    }

    /** @return array{pos: int, lines: int, skipped: int} */
    public static function importCsv(string $path): array
    {
        $fh = fopen($path, 'rb');
        if (!$fh) { throw new RuntimeException('Cannot read the file.'); }
        $first = (string)fgets($fh); rewind($fh);
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : (substr_count($first, "\t") > substr_count($first, ',') ? "\t" : ',');
        $head = fgetcsv($fh, 0, $delim, '"', '');
        if (!$head) { throw new RuntimeException('The file is empty.'); }
        $map = [];
        foreach ($head as $i => $h) {
            $k = strtolower(preg_replace('/[^A-Za-z0-9]+/', '_', trim((string)preg_replace('/^\xEF\xBB\xBF/', '', (string)$h))) ?? '');
            foreach (self::COLS as $field => $aliases) { if (in_array(trim($k, '_'), $aliases, true) && !isset($map[$field])) { $map[$field] = $i; } }
        }
        if (!isset($map['po_no']) || !isset($map['qty'])) { throw new RuntimeException('The first row must have column titles including at least PO number and quantity (for example: po_no, line_no, vendor, material, description, qty, uom, rate).'); }
        $rows = []; $skipped = 0;
        while (($r = fgetcsv($fh, 0, $delim, '"', '')) !== false) {
            $g = fn(string $f) => isset($map[$f], $r[$map[$f]]) ? trim((string)$r[$map[$f]]) : '';
            $po = $g('po_no');
            if ($po === '' || Documents::num($g('qty')) === null) { $skipped++; continue; }
            $rows[] = [$po, $g('line_no'), $g('vendor'), $g('material_code'), $g('description'), Documents::num($g('qty')), Documents::uom($g('uom')), Documents::num($g('rate')), Documents::date($g('po_date'))];
        }
        fclose($fh);
        if (!$rows) { throw new RuntimeException('No usable rows found (each needs a PO number and a quantity).'); }
        $pdo = Db::pdo(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            foreach (array_unique(array_column($rows, 0)) as $po) { Db::q('DELETE FROM po_lines WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(po_no,\'-\',\'\'),\'/\',\'\'),\' \',\'\'),\'.\',\'\')) = ?', [self::key($po)]); }
            foreach ($rows as $x) {
                Db::q("INSERT INTO po_lines(po_no, line_no, vendor, material_code, description, qty, uom, rate, po_date, source) VALUES (?,?,?,?,?,?,?,?,?, 'CSV')", $x);
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK'); } throw $e; }
        Db::audit('po_import', count($rows) . ' lines');
        return ['pos' => count(array_unique(array_column($rows, 0))), 'lines' => count($rows), 'skipped' => $skipped];
    }

    /** PO lines from the local master; if unknown and SAP lookup is configured, fetch from SAP and remember. */
    public static function ensure(string $po): array
    {
        $l = self::lines($po);
        if ($l || trim((string)Settings::get('sap_po_url')) === '') { return $l; }
        try { $p = Sap::fetchPo($po); } catch (Throwable) { return []; }
        if (!$p || !$p['lines']) { return []; }
        Db::q("DELETE FROM po_lines WHERE source = 'SAP' AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(po_no,'-',''),'/',''),' ',''),'.','')) = ?", [self::key($po)]);
        foreach ($p['lines'] as $x) {
            Db::q("INSERT INTO po_lines(po_no, line_no, vendor, material_code, description, qty, uom, rate, po_date, source) VALUES (?,?,?,?,?,?,?,?,?, 'SAP')",
                [$p['po_no'] ?: $po, $x['line_no'], $p['vendor'], $x['material_code'], $x['description'], $x['qty'], Documents::uom($x['uom']), $x['rate'], $p['po_date'] ?? null]);
        }
        return self::lines($po);
    }

    public static function delete(string $po): void
    {
        Db::q("DELETE FROM po_lines WHERE UPPER(REPLACE(REPLACE(REPLACE(REPLACE(po_no,'-',''),'/',''),' ',''),'.','')) = ?", [self::key($po)]);
        Db::audit('po_delete', $po);
    }
}
