<?php
declare(strict_types=1);

/**
 * Odoo integration over the standard external API (JSON-RPC, /jsonrpc) - works with Odoo 14-18, Odoo.sh and Odoo Online.
 * Push: closed/cancelled tickets -> model wb.ticket (from the addon in odoo/wb_weighbridge), keyed on ticket_no (create or write).
 * Pull: confirmed purchase-order lines -> po_lines (source ODOO), vendors/customers -> parties, products -> materials.
 * Log in with an Odoo user + API key (Odoo: Preferences > Account Security > New API Key).
 */
final class OdooSync
{
    private ?int $uid = null;
    private int $rid = 0;

    public function __construct(private array $cfg) {}

    public static function configured(?array $c = null): bool
    {
        $c ??= Settings::all();
        return trim((string)$c['odoo_url']) !== '' && trim((string)$c['odoo_db']) !== '' && trim((string)$c['odoo_user']) !== '' && (string)$c['odoo_key'] !== '';
    }

    private function rpc(string $service, string $method, array $args): mixed
    {
        $url = rtrim(trim((string)$this->cfg['odoo_url']), '/');
        if (!preg_match('#^https?://#i', $url)) { throw new RuntimeException('The Odoo URL must start with https:// (or http://).'); }
        $ch = curl_init($url . '/jsonrpc');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => max(5, (int)$this->cfg['odoo_timeout']),
            CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            CURLOPT_POSTFIELDS => json_encode(['jsonrpc' => '2.0', 'method' => 'call', 'id' => ++$this->rid, 'params' => ['service' => $service, 'method' => $method, 'args' => $args]], JSON_UNESCAPED_UNICODE)]);
        $out = curl_exec($ch);
        if ($out === false) { $e = curl_error($ch); throw new RuntimeException('Odoo unreachable: ' . $e); }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $j = json_decode((string)$out, true);
        if (!is_array($j)) { throw new RuntimeException("Odoo answered HTTP $code and it is not JSON-RPC (check the URL)."); }
        if (isset($j['error'])) {
            $d = $j['error']['data'] ?? [];
            $m = trim((string)($d['message'] ?? $j['error']['message'] ?? 'error'));
            throw new RuntimeException('Odoo: ' . mb_substr($m, 0, 300));
        }
        return $j['result'] ?? null;
    }

    public function login(): int
    {
        if ($this->uid !== null) { return $this->uid; }
        if (!self::configured($this->cfg)) { throw new RuntimeException('Odoo URL, database, user and API key are all required.'); }
        $uid = $this->rpc('common', 'authenticate', [$this->cfg['odoo_db'], $this->cfg['odoo_user'], $this->cfg['odoo_key'], new stdClass()]);
        if (!is_int($uid) || $uid <= 0) { throw new RuntimeException('Odoo refused the login (database, user or API key wrong).'); }
        return $this->uid = $uid;
    }

    public function call(string $model, string $method, array $args = [], array $kw = []): mixed
    {
        $uid = $this->login();
        return $this->rpc('object', 'execute_kw', [$this->cfg['odoo_db'], $uid, $this->cfg['odoo_key'], $model, $method, $args, (object)$kw]);
    }

    private function model(): string
    {
        $m = (string)$this->cfg['odoo_model'];
        if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/', $m)) { throw new RuntimeException('Invalid Odoo model name.'); }
        return $m;
    }

    public function test(): string
    {
        $v = $this->rpc('common', 'version', []);
        $this->login();
        $model = $this->model();
        try { $n = (int)$this->call($model, 'search_count', [[]]); }
        catch (RuntimeException $e) { return 'Logged in (Odoo ' . ($v['server_version'] ?? '?') . ") BUT model $model is not usable: " . $e->getMessage() . ' - install the addon odoo/wb_weighbridge.'; }
        return 'Connected OK (Odoo ' . ($v['server_version'] ?? '?') . ", user id {$this->uid}). Model $model has $n record(s).";
    }

    /** Odoo stores datetimes in UTC; the weighbridge keeps local time. */
    private static function utc(?string $local): ?string
    {
        if ($local === null || $local === '') { return null; }
        $t = strtotime($local);
        return $t === false ? null : gmdate('Y-m-d H:i:s', $t);
    }

    /** @return array<string, mixed> field values for wb.ticket */
    public static function ticketValues(array $w): array
    {
        $n = fn($x) => ($x === null || $x === '') ? false : $x;
        return [
            'ticket_no' => $w['ticket_no'], 'vehicle_no' => $w['vehicle_no'], 'party_name' => $n($w['party']), 'material_name' => $n($w['material']),
            'direction' => $w['direction'], 'driver' => $n($w['driver']), 'challan_no' => $n($w['challan_no']), 'remarks' => $n($w['remarks']),
            'gross_kg' => (float)$w['gross_kg'], 'tare_kg' => (float)$w['tare_kg'], 'net_kg' => (float)$w['net_kg'],
            'first_weight_at' => self::utc($w['first_at']) ?? false, 'second_weight_at' => self::utc($w['second_at']) ?? false,
            'state' => $w['status'], 'operator' => $n($w['operator']), 'scale_name' => $n($w['scale_id'] ? (Scales::find((int)$w['scale_id'])['name'] ?? null) : null),
            'plate_in' => $n($w['plate_in']), 'plate_out' => $n($w['plate_out']), 'plate_flag' => $n($w['plate_flag']), 'local_id' => (int)$w['id'],
        ];
    }

    /** Create or update (idempotent on ticket_no). Returns the Odoo record id. */
    public function push(array $w): int
    {
        $model = $this->model(); $vals = self::ticketValues($w);
        $ids = $this->call($model, 'search', [[['ticket_no', '=', $w['ticket_no']]]], ['limit' => 1]);
        if (is_array($ids) && $ids) { $this->call($model, 'write', [[(int)$ids[0]], $vals]); return (int)$ids[0]; }
        $id = $this->call($model, 'create', [$vals]);
        if (is_array($id)) { $id = $id[0] ?? 0; }
        if (!is_int($id) || $id <= 0) { throw new RuntimeException('Odoo did not return a record id.'); }
        return $id;
    }

    /** Background job: send every pending/failed ticket. @return array{ok:int, failed:int, error:?string} */
    public static function syncPending(?int $limit = null): array
    {
        $cfg = Settings::all(); $res = ['ok' => 0, 'failed' => 0, 'error' => null];
        if ($cfg['odoo_enabled'] !== '1') { $res['error'] = 'Odoo transfer is disabled in Setup.'; return $res; }
        $rows = Db::all("SELECT * FROM weighments WHERE status IN ('CLOSED','CANCELLED') AND gross_kg IS NOT NULL AND odoo_status IN ('PENDING','FAILED') ORDER BY id LIMIT ?", [$limit ?? (int)$cfg['odoo_batch']]);
        if (!$rows) { return $res; }
        $o = new self($cfg);
        try { $o->login(); }
        catch (Throwable $e) {     // Odoo unreachable: keep everything PENDING (store-and-forward)
            $res['error'] = $e->getMessage();
            Db::q("UPDATE weighments SET odoo_error = ?, odoo_tries = odoo_tries + 1 WHERE id IN (" . implode(',', array_map(fn($r) => (int)$r['id'], $rows)) . ')', [mb_substr($e->getMessage(), 0, 400)]);
            return $res;
        }
        foreach ($rows as $w) {
            try {
                $id = $o->push($w);
                Db::q("UPDATE weighments SET odoo_status='SYNCED', odoo_id=?, odoo_at=?, odoo_error=NULL WHERE id=?", [$id, date('Y-m-d H:i:s'), $w['id']]);
                $res['ok']++;
            } catch (Throwable $e) {
                Db::q("UPDATE weighments SET odoo_status='FAILED', odoo_tries=odoo_tries+1, odoo_error=? WHERE id=?", [mb_substr($e->getMessage(), 0, 400), $w['id']]);
                $res['failed']++; $res['error'] = $e->getMessage();
            }
        }
        return $res;
    }

    /** "[CODE] Name" (Odoo's display name) -> [code, name] */
    private static function splitProduct(string $dn): array
    {
        return preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $dn, $m) ? [trim($m[1]), trim($m[2])] : ['', trim($dn)];
    }

    /** Confirmed purchase orders -> local PO master. @return array{pos:int, lines:int} */
    public function pullPurchaseOrders(int $limit = 2000): array
    {
        $lines = $this->call('purchase.order.line', 'search_read', [[['order_id.state', 'in', ['purchase', 'done']], ['display_type', '=', false]]],
            ['fields' => ['order_id', 'sequence', 'partner_id', 'product_id', 'name', 'product_qty', 'product_uom', 'price_unit', 'date_order'], 'limit' => $limit, 'order' => 'order_id desc, sequence, id']);
        if (!is_array($lines)) { throw new RuntimeException('Unexpected answer from Odoo.'); }
        $by = [];
        foreach ($lines as $l) {
            $po = is_array($l['order_id'] ?? null) ? (string)$l['order_id'][1] : '';
            if ($po === '' || (float)($l['product_qty'] ?? 0) <= 0) { continue; }
            $by[$po][] = $l;
        }
        $pdo = Db::pdo(); $pdo->exec('BEGIN IMMEDIATE'); $cnt = 0;
        try {
            foreach ($by as $po => $ls) {
                Db::q("DELETE FROM po_lines WHERE source = 'ODOO' AND po_no = ?", [$po]);
                Db::q("DELETE FROM po_lines WHERE source <> 'ODOO' AND UPPER(REPLACE(REPLACE(REPLACE(REPLACE(po_no,'-',''),'/',''),' ',''),'.','')) = ?", [Documents::keyOf($po)]);
                $i = 0;
                foreach ($ls as $l) {
                    [$code, $nm] = self::splitProduct(is_array($l['product_id'] ?? null) ? (string)$l['product_id'][1] : '');
                    $uom = is_array($l['product_uom'] ?? null) ? (string)$l['product_uom'][1] : null;
                    $dt = !empty($l['date_order']) ? substr((string)$l['date_order'], 0, 10) : null;
                    Db::q("INSERT INTO po_lines(po_no, line_no, vendor, material_code, description, qty, uom, rate, po_date, source) VALUES (?,?,?,?,?,?,?,?,?, 'ODOO')",
                        [$po, (string)(++$i * 10), is_array($l['partner_id'] ?? null) ? (string)$l['partner_id'][1] : null, $code, $nm !== '' ? $nm : (string)($l['name'] ?? ''),
                         (float)$l['product_qty'], Documents::uom($uom), (float)$l['price_unit'], $dt]);
                    $cnt++;
                }
            }
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK'); } throw $e; }
        Db::audit('odoo_po_pull', count($by) . " POs, $cnt lines");
        return ['pos' => count($by), 'lines' => $cnt];
    }

    /** Vendors/customers -> parties, products -> materials (new names only, nothing is removed). @return array{parties:int, materials:int} */
    public function pullMasters(int $limit = 5000): array
    {
        $p = $this->call('res.partner', 'search_read', [['|', ['supplier_rank', '>', 0], ['customer_rank', '>', 0]]], ['fields' => ['name'], 'limit' => $limit]);
        $m = $this->call('product.product', 'search_read', [[['active', '=', true]]], ['fields' => ['name'], 'limit' => $limit]);
        $np = $nm = 0;
        foreach ((array)$p as $r) { $n = trim((string)($r['name'] ?? '')); if ($n !== '') { $np += Db::q('INSERT OR IGNORE INTO parties(name) VALUES (?)', [$n])->rowCount(); } }
        foreach ((array)$m as $r) { $n = trim((string)($r['name'] ?? '')); if ($n !== '') { $nm += Db::q('INSERT OR IGNORE INTO materials(name) VALUES (?)', [$n])->rowCount(); } }
        Db::audit('odoo_master_pull', "$np parties, $nm materials");
        return ['parties' => $np, 'materials' => $nm];
    }

    /** Pulls enabled in Setup (called from the background job). */
    public static function pullEnabled(): array
    {
        $cfg = Settings::all(); $out = [];
        if ($cfg['odoo_enabled'] !== '1' || !self::configured($cfg)) { return $out; }
        $o = new self($cfg);
        if ($cfg['odoo_pull_po'] === '1') { $out['po'] = $o->pullPurchaseOrders(); }
        if ($cfg['odoo_pull_masters'] === '1') { $out['masters'] = $o->pullMasters(); }
        return $out;
    }
}
