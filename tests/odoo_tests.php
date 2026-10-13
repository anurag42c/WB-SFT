<?php
// Included by run.php: Odoo JSON-RPC client (mock Odoo) and the REST API (real php -S on public/).
echo "Odoo: connection\n";
foreach (['odoo_url' => mockUrl(''), 'odoo_db' => 'testdb', 'odoo_user' => 'admin', 'odoo_key' => 'odookey', 'odoo_model' => 'wb.ticket'] as $k => $v) { Settings::set($k, $v); }
Settings::reset();
$msg = (new OdooSync(Settings::all()))->test();
t('test() logs in and counts records', str_contains($msg, 'Connected OK') && str_contains($msg, 'Odoo 17.0'), $msg);
t('wrong API key -> clear message', str_contains((string)throws(fn() => (new OdooSync(['odoo_key' => 'nope'] + Settings::all()))->login()), 'refused'));
t('missing settings -> clear message', str_contains((string)throws(fn() => (new OdooSync(['odoo_url' => ''] + Settings::all()))->login()), 'required'));
t('non-http URL refused', str_contains((string)throws(fn() => (new OdooSync(['odoo_url' => 'ftp://x', 'odoo_timeout' => '5'] + Settings::all()))->login()), 'https'));
t('bad model name refused', str_contains((string)throws(fn() => (new OdooSync(['odoo_model' => "x'; drop"] + Settings::all()))->push(['ticket_no' => 'x'])), 'Invalid Odoo model'));
t('key is stored encrypted', Db::val("SELECT v FROM settings WHERE k='odoo_key'") !== 'odookey');

echo "Odoo: ticket push\n";
$before = (int)Db::val("SELECT COUNT(*) FROM weighments WHERE odoo_status = 'PENDING'");
Settings::set('odoo_enabled', '1'); Settings::reset();
setLive(30000, true); $tid = Weighment::create(['vehicle_no' => 'ODOO1234', 'party' => 'Odoo Party', 'material' => 'Iron Ore', 'first_type' => 'GROSS', 'scale_id' => 1], null);
setLive(9000, true); Weighment::second($tid, null, 1);
t('closing a ticket queues it for Odoo', Db::val('SELECT odoo_status FROM weighments WHERE id=?', [$tid]) === 'PENDING');
$w = Db::one('SELECT * FROM weighments WHERE id=?', [$tid]);
$v = OdooSync::ticketValues($w);
t('payload: weights, names, scale', $v['net_kg'] === 21000.0 && $v['party_name'] === 'Odoo Party' && $v['scale_name'] === 'Scale 1' && $v['state'] === 'CLOSED' && $v['gross_kg'] === 30000.0);
t('payload: local time converted to UTC (IST-5:30)', $v['first_weight_at'] === gmdate('Y-m-d H:i:s', strtotime($w['first_at'])) && $v['first_weight_at'] !== $w['first_at']);
t('payload: empty strings become false (Odoo null)', $v['driver'] === false);
$r = OdooSync::syncPending();
t('pending tickets are sent', $r['failed'] === 0 && $r['ok'] === $before + 1 && Db::val('SELECT odoo_status FROM weighments WHERE id=?', [$tid]) === 'SYNCED', json_encode($r));
$oid = (int)Db::val('SELECT odoo_id FROM weighments WHERE id=?', [$tid]);
$od = json_decode((string)file_get_contents($tmp . '/odoo.json'), true);
t('record created in Odoo with the right data', $oid > 0 && ($od['tickets'][$oid]['ticket_no'] ?? '') === $w['ticket_no'] && $od['tickets'][$oid]['net_kg'] == 21000);
$cnt = count($od['tickets']);
Weighment::cancel($tid, 'wrong party');
t('cancelling a synced ticket re-queues it', Db::val('SELECT odoo_status FROM weighments WHERE id=?', [$tid]) === 'PENDING');
OdooSync::syncPending();
$od = json_decode((string)file_get_contents($tmp . '/odoo.json'), true);
t('re-send updates the SAME Odoo record (no duplicate)', count($od['tickets']) === $cnt && $od['tickets'][$oid]['state'] === 'CANCELLED' && (int)Db::val('SELECT odoo_id FROM weighments WHERE id=?', [$tid]) === $oid);

echo "Odoo: store-and-forward\n";
setLive(30000, true); $t2 = Weighment::create(['vehicle_no' => 'ODOO5678', 'first_type' => 'GROSS', 'scale_id' => 1], null);
setLive(9000, true); Weighment::second($t2, null, 1);
touch($tmp . '/odoo.json.down');
$r = OdooSync::syncPending();
t('Odoo erroring -> ticket FAILED with the reason, weighing untouched', $r['failed'] === 1 && Db::val('SELECT odoo_status FROM weighments WHERE id=?', [$t2]) === 'FAILED' && Db::val('SELECT status FROM weighments WHERE id=?', [$t2]) === 'CLOSED' && (int)Db::val('SELECT odoo_tries FROM weighments WHERE id=?', [$t2]) === 1);
unlink($tmp . '/odoo.json.down');
$r = OdooSync::syncPending();
t('Odoo back -> failed ticket is retried and delivered', $r['ok'] === 1 && Db::val('SELECT odoo_status FROM weighments WHERE id=?', [$t2]) === 'SYNCED' && Db::val('SELECT odoo_error FROM weighments WHERE id=?', [$t2]) === null);
setLive(30000, true); $t3 = Weighment::create(['vehicle_no' => 'ODOO9999', 'first_type' => 'GROSS', 'scale_id' => 1], null);
setLive(9000, true); Weighment::second($t3, null, 1);
Settings::set('odoo_url', 'http://127.0.0.1:1'); Settings::set('odoo_timeout', '5'); Settings::reset();
$r = OdooSync::syncPending();
t('Odoo unreachable -> stays PENDING (not lost)', $r['error'] !== null && str_contains($r['error'], 'unreachable') && Db::val('SELECT odoo_status FROM weighments WHERE id=?', [$t3]) === 'PENDING');
Settings::set('odoo_url', mockUrl('')); Settings::set('odoo_model', 'no.such'); Settings::reset();
$r = OdooSync::syncPending();
t('addon missing in Odoo -> Odoo\'s own message is shown', $r['failed'] === 1 && str_contains((string)Db::val('SELECT odoo_error FROM weighments WHERE id=?', [$t3]), "doesn't exist"));
Settings::set('odoo_model', 'wb.ticket'); Settings::reset();
t('model not installed is explained by test()', str_contains((new OdooSync(['odoo_model' => 'no.such'] + Settings::all()))->test(), 'install the addon'));
Settings::set('odoo_enabled', '0'); Settings::reset();
t('disabled -> nothing sent', OdooSync::syncPending()['error'] !== null && Db::val('SELECT odoo_status FROM weighments WHERE id=?', [$t3]) === 'FAILED');
setLive(30000, true); $t4 = Weighment::create(['vehicle_no' => 'ODOO0001', 'first_type' => 'GROSS', 'scale_id' => 1], null);
setLive(9000, true); Weighment::second($t4, null, 1);
t('Odoo off -> new tickets are not queued', Db::val('SELECT odoo_status FROM weighments WHERE id=?', [$t4]) === 'NA');

echo "Odoo: pull purchase orders and masters\n";
Settings::set('odoo_enabled', '1'); Settings::reset();
Db::q("INSERT INTO po_lines(po_no, line_no, vendor, qty, uom, source) VALUES ('P-00007', '10', 'Old Vendor', 1, 'KG', 'CSV')");
$o = new OdooSync(Settings::all());
$p = $o->pullPurchaseOrders();
t('only confirmed lines with quantity are imported', $p === ['pos' => 1, 'lines' => 2], json_encode($p));
$pl = PurchaseOrders::lines('P00007');
t('PO lines mapped (code, unit, rate, vendor, date)', count($pl) === 2 && $pl[0]['material_code'] === 'IRON01' && $pl[0]['description'] === 'Iron Ore Lumps' && $pl[0]['uom'] === 'MT' && (float)$pl[0]['rate'] === 3500.0 && $pl[0]['vendor'] === 'Shree Balaji Minerals Pvt Ltd' && $pl[0]['po_date'] === '2026-09-10' && $pl[0]['source'] === 'ODOO');
t('an older copy of the same PO is replaced', (int)Db::val("SELECT COUNT(*) FROM po_lines WHERE source='CSV' AND po_no='P-00007'") === 0);
$o->pullPurchaseOrders();
t('pulling again does not duplicate', count(PurchaseOrders::lines('P00007')) === 2);
$m = $o->pullMasters();
t('masters: new names added', $m['parties'] >= 1 && $m['materials'] >= 1 && Db::val("SELECT COUNT(*) FROM parties WHERE name='Odoo Customer Co'") == 1);
$m2 = $o->pullMasters();
t('masters: second pull adds nothing', $m2 === ['parties' => 0, 'materials' => 0]);
t('pullEnabled does nothing unless switched on', OdooSync::pullEnabled() === []);
Settings::set('odoo_pull_po', '1'); Settings::reset();
t('pullEnabled honours the switch', array_keys(OdooSync::pullEnabled()) === ['po']);
Settings::set('odoo_pull_po', '0'); Settings::set('odoo_enabled', '0'); Settings::reset();

echo "REST API v1 (php -S on public/)\n";
$apiPort = random_int(41000, 60000);
$api = proc_open([PHP_BINARY, '-S', "127.0.0.1:$apiPort", '-t', __DIR__ . '/../public'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $ap, null, ['WB_DATA' => $tmp] + getenv());
register_shutdown_function(function () use ($api) { if (is_resource($api)) { proc_terminate($api); } });
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $apiPort); $i++) { usleep(100000); }
function api(string $method, string $path, ?array $body = null, ?string $token = 'tok123', ?string $raw = null): array {
    global $apiPort;
    $ch = curl_init("http://127.0.0.1:$apiPort/api/v1/$path");
    $h = $token ? ["Authorization: Bearer $token"] : [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $h, CURLOPT_TIMEOUT => 20]);
    if ($body !== null || $raw !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $raw ?? json_encode($body)); }
    $out = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode($out, true) ?? ['_raw' => $out]];
}
[$c] = api('GET', 'ping.php', null, null);
t('API disabled (no token configured) -> 401', $c === 401);
Settings::set('api_token_hash', hash('sha256', 'tok123')); Settings::reset();
[$c] = api('GET', 'ping.php', null, null); t('no token -> 401', $c === 401);
[$c] = api('GET', 'ping.php', null, 'wrong'); t('wrong token -> 401', $c === 401);
[$c, $j] = api('GET', 'ping.php'); t('ping with token', $c === 200 && $j['ok'] === true && $j['writes_enabled'] === false && in_array('tickets', $j['endpoints'], true), json_encode($j));
[$c, $j] = api('GET', 'tickets.php?since_id=0&limit=2');
t('tickets: list, ascending, capped by limit', $c === 200 && $j['count'] === 2 && $j['tickets'][0]['id'] < $j['tickets'][1]['id'] && $j['next_since_id'] === $j['tickets'][1]['id']);
$ids = array_column(Db::all("SELECT id FROM weighments WHERE status IN ('CLOSED','CANCELLED') ORDER BY id"), 'id');
[$c, $j] = api('GET', 'tickets.php?since_id=' . $ids[count($ids) - 3] . '&limit=100');
t('tickets: since_id pages forward, nothing repeated', $j['count'] === 2 && !isset($j['tickets'][0]['pass_hash']));
[$c, $j] = api('GET', 'tickets.php?since_id=' . end($ids));
t('tickets: caught up -> empty and the cursor stays', $j['count'] === 0 && $j['next_since_id'] === (int)end($ids));
[$c, $j] = api('GET', 'tickets.php?ticket_no=' . rawurlencode($w['ticket_no']));
t('tickets: by ticket_no (cancelled one included)', $c === 200 && $j['ticket']['status'] === 'CANCELLED' && $j['ticket']['net_kg'] == 21000 && $j['ticket']['scale'] === 'Scale 1' && $j['ticket']['odoo_id'] === $oid);
[$c] = api('GET', 'tickets.php?ticket_no=NOPE'); t('tickets: unknown ticket -> 404', $c === 404);
[$c, $j] = api('GET', 'tickets.php?vehicle=ODOO56&status=CLOSED');
t('tickets: vehicle + status filter', $j['count'] === 1 && $j['tickets'][0]['vehicle_no'] === 'ODOO5678');
[$c, $j] = api('GET', "tickets.php?vehicle=" . rawurlencode("%") . "&limit=500");
t('tickets: LIKE wildcards in a filter are literal', $j['count'] === 0);
[$c, $j] = api('GET', 'tickets.php?from=' . date('Y-m-d') . '&to=' . date('Y-m-d') . '&limit=500');
t('tickets: date filter', $j['count'] > 0);
[$c] = api('GET', 'tickets.php?from=2000-01-01&to=2000-01-02'); [, $j2] = api('GET', 'tickets.php?from=2000-01-01&to=2000-01-02'); t('tickets: date filter excludes', $j2['count'] === 0);
[$c, $j] = api('POST', 'tickets.php', []); t('tickets: POST not allowed -> 405', $c === 405);

[$c, $j] = api('GET', 'documents.php?limit=500');
t('documents: list with match status', $c === 200 && $j['count'] > 0 && isset($j['documents'][0]['match_status'], $j['documents'][0]['doc_no']));
$dn = $j['documents'][0]['doc_no'];
[$c, $j] = api('GET', 'documents.php?doc_no=' . $dn);
t('documents: one in full = the SAP JSON contract', $c === 200 && $j['document']['documentNo'] === $dn && array_key_exists('lines', $j['document']) && array_key_exists('exceptions', $j['document']));
[$c, $j] = api('GET', 'documents.php?match=EXCEPTION&limit=500'); t('documents: filter by match status', $c === 200 && array_unique(array_column($j['documents'], 'match_status')) <= ['EXCEPTION']);
[$c] = api('GET', 'documents.php?doc_no=NOPE'); t('documents: unknown -> 404', $c === 404);

$po = ['pos' => [['po_no' => 'PX/100', 'vendor' => 'Api Vendor', 'po_date' => '2026-09-20', 'lines' => [['material_code' => 'LIME', 'description' => 'Limestone', 'qty' => '25', 'uom' => 'tonnes', 'rate' => 800], ['description' => 'Gypsum', 'qty' => 5, 'uom' => 'MT']]]]];
[$c, $j] = api('POST', 'purchase_orders.php', $po); t('PO write refused while writes are off -> 403', $c === 403 && (int)Db::val("SELECT COUNT(*) FROM po_lines WHERE po_no='PX/100'") === 0);
Settings::set('api_write', '1'); Settings::reset();
[$c, $j] = api('POST', 'purchase_orders.php', $po); t('PO write: stored', $c === 200 && $j['pos'] === 1 && $j['lines'] === 2, json_encode($j));
[$c, $j] = api('GET', 'purchase_orders.php?po=PX100');
t('PO read: found by punctuation-free number, units normalised', $c === 200 && count($j['lines']) === 2 && $j['lines'][0]['uom'] === 'MT' && $j['vendor'] === 'Api Vendor' && $j['lines'][1]['line_no'] === '20');
$po['pos'][0]['lines'] = [['description' => 'Only', 'qty' => 1, 'uom' => 'MT']];
api('POST', 'purchase_orders.php', $po); t('PO write replaces earlier lines', count(PurchaseOrders::lines('PX/100')) === 1);
$bad = ['pos' => [['po_no' => 'GOOD1', 'lines' => [['qty' => 1]]], ['po_no' => 'BAD1', 'lines' => [['description' => 'no qty']]]]];
[$c, $j] = api('POST', 'purchase_orders.php', $bad); t('PO write: one bad entry -> 422 and NOTHING stored', $c === 422 && (int)Db::val("SELECT COUNT(*) FROM po_lines WHERE po_no IN ('GOOD1','BAD1')") === 0, json_encode($j));
[$c] = api('POST', 'purchase_orders.php', null, 'tok123', '{not json'); t('PO write: invalid JSON -> 400', $c === 400);
[$c] = api('GET', 'purchase_orders.php?po=NOPE'); t('PO read: unknown -> 404', $c === 404);
[$c] = api('POST', 'purchase_orders.php', $po, 'wrong'); t('PO write needs the token too', $c === 401);

[$c, $j] = api('POST', 'masters.php', ['type' => 'parties', 'items' => [['name' => 'Api Party One'], ['name' => 'Api Party Two', 'active' => false]]]);
t('masters: parties upserted', $c === 200 && $j['upserted'] === 2 && Db::val("SELECT active FROM parties WHERE name='Api Party Two'") == 0);
[$c, $j] = api('POST', 'masters.php', ['type' => 'parties', 'items' => [['name' => 'Api Party Two', 'active' => true]]]);
t('masters: upsert re-activates, no duplicate', Db::val("SELECT active FROM parties WHERE name='Api Party Two'") == 1 && (int)Db::val("SELECT COUNT(*) FROM parties WHERE name='Api Party Two'") === 1);
api('POST', 'masters.php', ['type' => 'vehicles', 'items' => [['reg_no' => 'mh 31 zz 0001', 'tare_kg' => 8500, 'transporter' => 'Trans A']]]);
api('POST', 'masters.php', ['type' => 'vehicles', 'items' => [['reg_no' => 'MH31ZZ0001', 'blocked' => true, 'block_reason' => 'insurance expired']]]);
$vr = Db::one("SELECT * FROM vehicles WHERE reg_no='MH31ZZ0001'");
t('masters: vehicle number normalised, tare kept on later upsert, blocked + reason', $vr && (float)$vr['tare_kg'] === 8500.0 && $vr['transporter'] === 'Trans A' && (int)$vr['blocked'] === 1 && $vr['block_reason'] === 'insurance expired');
[$c, $j] = api('POST', 'masters.php', ['type' => 'materials', 'items' => [['name' => 'Ok Material'], ['x' => 1]]]);
t('masters: bad entry -> 422, nothing stored', $c === 422 && (int)Db::val("SELECT COUNT(*) FROM materials WHERE name='Ok Material'") === 0);
[$c] = api('GET', 'masters.php?type=users'); t('masters: only the three master types are exposed', $c === 400);
[$c, $j] = api('GET', 'masters.php?type=vehicles'); t('masters: read vehicles', $c === 200 && in_array('MH31ZZ0001', array_column($j['items'], 'reg_no'), true));
Settings::set('api_write', '0'); Settings::reset();
[$c] = api('POST', 'masters.php', ['type' => 'parties', 'items' => [['name' => 'x']]]); t('masters: writes off -> 403', $c === 403);

[$c, $j] = api('GET', 'openapi.php', null, null);
t('openapi.php is public and describes every endpoint', $c === 200 && $j['openapi'] === '3.0.3' && count(array_intersect(array_keys($j['paths']), ['/api/v1/tickets.php', '/api/v1/documents.php', '/api/v1/purchase_orders.php', '/api/v1/masters.php', '/api/v1/ping.php'])) === 5);

Db::q("DELETE FROM login_attempts");
for ($i = 0; $i < 20; $i++) { api('GET', 'ping.php', null, 'guess' . $i); }
[$c] = api('GET', 'ping.php', null, 'tok123'); t('20 wrong tokens lock the API for 5 minutes (even the right one)', $c === 429);
Db::q("DELETE FROM login_attempts");
[$c] = api('GET', 'ping.php'); t('lock released', $c === 200);
