<?php
declare(strict_types=1);

/**
 * Pushes CLOSED tickets to Oracle with an idempotent MERGE (safe to retry).
 * Uses the oci8 extension when present, otherwise PDO_OCI.
 */
final class OracleSync
{
    private $conn = null;      // oci8 resource or PDO
    private bool $useOci8;

    public function __construct(private array $cfg)
    {
        $this->useOci8 = function_exists('oci_connect');
    }

    public static function available(): string
    {
        if (function_exists('oci_connect')) { return 'oci8'; }
        if (extension_loaded('pdo_oci')) { return 'pdo_oci'; }
        return '';
    }

    private function table(): string
    {
        $t = strtoupper((string)$this->cfg['ora_table']);
        if (!preg_match('/^[A-Z][A-Z0-9_$#]{0,29}(\.[A-Z][A-Z0-9_$#]{0,29})?$/', $t)) { throw new RuntimeException('Invalid Oracle table name.'); }
        return $t;
    }

    public function connect(): void
    {
        if (self::available() === '') { throw new RuntimeException('No Oracle PHP driver: install the oci8 (or pdo_oci) extension and Oracle Instant Client. See README step 6.'); }
        $c = $this->cfg;
        if ($c['ora_host'] === '' || $c['ora_service'] === '' || $c['ora_user'] === '') { throw new RuntimeException('Oracle host, service and user are required.'); }
        $dsn = "//{$c['ora_host']}:{$c['ora_port']}/{$c['ora_service']}";
        if ($this->useOci8) {
            $this->conn = @oci_connect($c['ora_user'], $c['ora_pass'], $dsn, 'AL32UTF8');
            if (!$this->conn) { $e = oci_error(); throw new RuntimeException('Oracle connect failed: ' . ($e['message'] ?? 'unknown')); }
        } else {
            try { $this->conn = new PDO("oci:dbname=$dsn;charset=AL32UTF8", $c['ora_user'], $c['ora_pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]); }
            catch (PDOException $e) { throw new RuntimeException('Oracle connect failed: ' . $e->getMessage()); }
        }
    }

    private function run(string $sql, array $params = []): array
    {
        if ($this->useOci8) {
            $st = oci_parse($this->conn, $sql);
            foreach ($params as $k => &$v) { oci_bind_by_name($st, ':' . $k, $v, -1); }
            unset($v);
            if (!@oci_execute($st, OCI_COMMIT_ON_SUCCESS)) { $e = oci_error($st); throw new RuntimeException($e['message'] ?? 'Oracle error'); }
            $rows = [];
            if (oci_statement_type($st) === 'SELECT') { oci_fetch_all($st, $rows, 0, -1, OCI_FETCHSTATEMENT_BY_ROW); }
            return $rows;
        }
        $st = $this->conn->prepare($sql);
        foreach ($params as $k => $v) { $st->bindValue(':' . $k, $v); }
        $st->execute();
        return $st->columnCount() ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    /** Connectivity + table check for the setup page. */
    public function test(): string
    {
        $this->connect();
        $v = $this->run("SELECT banner FROM v\$version WHERE ROWNUM = 1");
        $banner = $v[0]['BANNER'] ?? 'Oracle';
        try { $this->run('SELECT COUNT(*) AS C FROM ' . $this->table() . ' WHERE 1 = 0'); }
        catch (RuntimeException $e) { return "Connected ($banner) BUT table {$this->table()} is not usable: " . $e->getMessage() . ' - run sql/oracle_schema.sql'; }
        return "Connected OK ($banner). Table {$this->table()} is present.";
    }

    public function push(array $w): void
    {
        $t = $this->table();
        $ts = fn(string $p) => "TO_TIMESTAMP(:$p, 'YYYY-MM-DD HH24:MI:SS')";
        $sql = "MERGE INTO $t d USING (SELECT :ticket_no AS ticket_no FROM dual) s ON (d.TICKET_NO = s.ticket_no)
          WHEN MATCHED THEN UPDATE SET VEHICLE_NO=:vehicle_no, PARTY=:party, MATERIAL=:material, DIRECTION=:direction, DRIVER=:driver,
               CHALLAN_NO=:challan_no, REMARKS=:remarks, GROSS_KG=:gross_kg, TARE_KG=:tare_kg, NET_KG=:net_kg,
               FIRST_WEIGHT_AT={$ts('first_at')}, SECOND_WEIGHT_AT={$ts('second_at')}, STATUS=:status, OPERATOR=:operator,
               LOCAL_ID=:local_id, SCALE_NAME=:scale_name, PLATE_IN=:plate_in, PLATE_OUT=:plate_out, PLATE_FLAG=:plate_flag, SYNCED_AT=SYSTIMESTAMP
          WHEN NOT MATCHED THEN INSERT (TICKET_NO, VEHICLE_NO, PARTY, MATERIAL, DIRECTION, DRIVER, CHALLAN_NO, REMARKS, GROSS_KG, TARE_KG, NET_KG,
               FIRST_WEIGHT_AT, SECOND_WEIGHT_AT, STATUS, OPERATOR, LOCAL_ID, SCALE_NAME, PLATE_IN, PLATE_OUT, PLATE_FLAG, SYNCED_AT)
          VALUES (:ticket_no, :vehicle_no, :party, :material, :direction, :driver, :challan_no, :remarks, :gross_kg, :tare_kg, :net_kg,
               {$ts('first_at')}, {$ts('second_at')}, :status, :operator, :local_id, :scale_name, :plate_in, :plate_out, :plate_flag, SYSTIMESTAMP)";
        $n = fn($x) => ($x === '' || $x === null) ? null : $x;
        $this->run($sql, [
            'ticket_no' => $w['ticket_no'], 'vehicle_no' => $w['vehicle_no'], 'party' => $n($w['party']), 'material' => $n($w['material']),
            'direction' => $w['direction'], 'driver' => $n($w['driver']), 'challan_no' => $n($w['challan_no']), 'remarks' => $n($w['remarks']),
            'gross_kg' => (string)$w['gross_kg'], 'tare_kg' => (string)$w['tare_kg'], 'net_kg' => (string)$w['net_kg'],
            'first_at' => $w['first_at'], 'second_at' => $w['second_at'], 'status' => $w['status'], 'operator' => $n($w['operator']),
            'local_id' => (string)$w['id'], 'scale_name' => $n(Scales::find((int)$w['scale_id'])['name'] ?? null),
            'plate_in' => $n($w['plate_in']), 'plate_out' => $n($w['plate_out']), 'plate_flag' => $n($w['plate_flag']),
        ]);
    }

    // ------------------------------------------------------------------ invoice / return documents
    private static function ident(string $t): string
    {
        $t = strtoupper($t);
        if (!preg_match('/^[A-Z][A-Z0-9_$#]{0,29}(\.[A-Z][A-Z0-9_$#]{0,29})?$/', $t)) { throw new RuntimeException('Invalid Oracle table name: ' . $t); }
        return $t;
    }

    /** Bind values for the document header MERGE (also used by the tests). */
    public static function documentParams(array $p): array
    {
        $n = fn($x) => ($x === '' || $x === null) ? null : (is_float($x) || is_int($x) ? (string)$x : $x);
        $exc = array_filter($p['exceptions'] ?? [], fn($e) => ($e['status'] ?? '') === 'OPEN');
        $high = count(array_filter($exc, fn($e) => $e['severity'] === 'HIGH')); $warn = count(array_filter($exc, fn($e) => $e['severity'] === 'WARN'));
        return [
            'doc_no' => $p['documentNo'], 'doc_type' => $p['documentType'], 'invoice_no' => $n($p['invoiceNo']), 'invoice_date' => $n($p['invoiceDate']),
            'supplier' => $n($p['supplier']), 'supplier_tax_id' => $n($p['supplierTaxId']), 'buyer' => $n($p['buyer']), 'po_no' => $n($p['poNo']),
            'ref_no' => $n($p['referenceNo']), 'orig_invoice_no' => $n($p['originalInvoiceNo']), 'vehicle_no' => $n($p['vehicleNo']), 'eway_no' => $n($p['ewayBillNo']),
            'currency' => $n($p['currency']), 'subtotal' => $n($p['subtotal']), 'tax_amount' => $n($p['taxAmount']), 'total_amount' => $n($p['totalAmount']),
            'match_status' => $p['matchStatus'], 'wb_tickets' => mb_substr(implode(',', $p['weighbridge']['ticketNos'] ?? []), 0, 500) ?: null,
            'wb_net_kg' => $n($p['weighbridge']['netKg'] ?? null), 'exc_high' => (string)$high, 'exc_warn' => (string)$warn,
            'exc_summary' => mb_substr(implode('; ', array_map(fn($e) => $e['code'], $exc)), 0, 1000) ?: null,
            'verified_by' => $n($p['verifiedBy']), 'verified_at' => $n($p['verifiedAt']),
        ];
    }

    /** MERGE the document and its lines (idempotent; lines that no longer exist are removed). */
    public function pushDocument(array $p): void
    {
        $t = self::ident((string)$this->cfg['ora_doc_table']); $lt = self::ident((string)$this->cfg['ora_doc_line_table']);
        $cols = ['DOC_TYPE' => 'doc_type', 'INVOICE_NO' => 'invoice_no', 'SUPPLIER' => 'supplier', 'SUPPLIER_TAX_ID' => 'supplier_tax_id', 'BUYER' => 'buyer', 'PO_NO' => 'po_no',
                 'REF_NO' => 'ref_no', 'ORIG_INVOICE_NO' => 'orig_invoice_no', 'VEHICLE_NO' => 'vehicle_no', 'EWAY_NO' => 'eway_no', 'CURRENCY' => 'currency',
                 'SUBTOTAL' => 'subtotal', 'TAX_AMOUNT' => 'tax_amount', 'TOTAL_AMOUNT' => 'total_amount', 'MATCH_STATUS' => 'match_status', 'WB_TICKETS' => 'wb_tickets',
                 'WB_NET_KG' => 'wb_net_kg', 'EXC_HIGH' => 'exc_high', 'EXC_WARN' => 'exc_warn', 'EXC_SUMMARY' => 'exc_summary', 'VERIFIED_BY' => 'verified_by'];
        $set = []; $ic = []; $iv = [];
        foreach ($cols as $c => $b) { $set[] = "$c = :$b"; $ic[] = $c; $iv[] = ":$b"; }
        $date = "TO_DATE(:invoice_date, 'YYYY-MM-DD')"; $ts = "TO_TIMESTAMP(:verified_at, 'YYYY-MM-DD HH24:MI:SS')";
        $sql = "MERGE INTO $t d USING (SELECT :doc_no AS doc_no FROM dual) s ON (d.DOC_NO = s.doc_no)
                WHEN MATCHED THEN UPDATE SET " . implode(', ', $set) . ", INVOICE_DATE = $date, VERIFIED_AT = $ts, SYNCED_AT = SYSTIMESTAMP
                WHEN NOT MATCHED THEN INSERT (DOC_NO, " . implode(', ', $ic) . ", INVOICE_DATE, VERIFIED_AT, SYNCED_AT)
                VALUES (:doc_no, " . implode(', ', $iv) . ", $date, $ts, SYSTIMESTAMP)";
        $this->run($sql, self::documentParams($p));

        $this->run("DELETE FROM $lt WHERE DOC_NO = :doc_no AND LINE_NO > :n", ['doc_no' => $p['documentNo'], 'n' => (string)count($p['lines'])]);
        $n = fn($x) => ($x === '' || $x === null) ? null : (string)$x;
        foreach ($p['lines'] as $l) {
            $this->run("MERGE INTO $lt d USING (SELECT :doc_no AS doc_no, :line_no AS line_no FROM dual) s ON (d.DOC_NO = s.doc_no AND d.LINE_NO = s.line_no)
                WHEN MATCHED THEN UPDATE SET MATERIAL_CODE = :material_code, DESCRIPTION = :description, HSN = :hsn, QTY = :qty, UOM = :uom, RATE = :rate, AMOUNT = :amount, QTY_KG = :qty_kg
                WHEN NOT MATCHED THEN INSERT (DOC_NO, LINE_NO, MATERIAL_CODE, DESCRIPTION, HSN, QTY, UOM, RATE, AMOUNT, QTY_KG)
                VALUES (:doc_no, :line_no, :material_code, :description, :hsn, :qty, :uom, :rate, :amount, :qty_kg)", [
                'doc_no' => $p['documentNo'], 'line_no' => (string)$l['lineNo'], 'material_code' => $n($l['materialCode']), 'description' => $n($l['description']),
                'hsn' => $n($l['hsn']), 'qty' => $n($l['quantity']), 'uom' => $n($l['uom']), 'rate' => $n($l['rate']), 'amount' => $n($l['amount']), 'qty_kg' => $n($l['quantityKg'])]);
        }
    }

    /** Are the two document tables there? (Setup test button) */
    public function testDocTables(): string
    {
        $this->connect();
        foreach ([$this->cfg['ora_doc_table'], $this->cfg['ora_doc_line_table']] as $tb) {
            try { $this->run('SELECT COUNT(*) AS C FROM ' . self::ident((string)$tb) . ' WHERE 1 = 0'); }
            catch (RuntimeException $e) { return "Connected, but table $tb is not usable: " . $e->getMessage() . ' - run the WB_DOCUMENTS part of sql/oracle_schema.sql'; }
        }
        return 'Connected OK. Document tables ' . $this->cfg['ora_doc_table'] . ' and ' . $this->cfg['ora_doc_line_table'] . ' are present.';
    }

    /** @return array{ok:int, failed:int, error:?string} */
    public static function syncPending(?int $limit = null): array
    {
        $cfg = Settings::all();
        $res = ['ok' => 0, 'failed' => 0, 'error' => null];
        if ($cfg['ora_enabled'] !== '1') { $res['error'] = 'Oracle transfer is disabled in Setup.'; return $res; }
        $rows = Db::all("SELECT * FROM weighments WHERE status IN ('CLOSED','CANCELLED') AND sync_status IN ('PENDING','FAILED') ORDER BY id LIMIT ?",
            [$limit ?? (int)$cfg['ora_batch']]);
        if (!$rows) { return $res; }
        $o = new self($cfg);
        try { $o->connect(); }
        catch (Throwable $e) {   // Oracle unreachable: leave everything PENDING, retry next run (store-and-forward)
            $res['error'] = $e->getMessage();
            Db::q("UPDATE weighments SET sync_error = ?, sync_tries = sync_tries + 1 WHERE id IN (" . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')', [$e->getMessage()]);
            return $res;
        }
        foreach ($rows as $w) {
            try {
                $o->push($w);
                Db::q("UPDATE weighments SET sync_status='SYNCED', sync_at=?, sync_error=NULL WHERE id=?", [date('Y-m-d H:i:s'), $w['id']]);
                $res['ok']++;
            } catch (Throwable $e) {
                Db::q("UPDATE weighments SET sync_status='FAILED', sync_tries=sync_tries+1, sync_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 500), $w['id']]);
                $res['failed']++; $res['error'] = $e->getMessage();
            }
        }
        return $res;
    }
}
