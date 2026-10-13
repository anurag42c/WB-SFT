<?php
declare(strict_types=1);
/** Dependency-free tests:  php tests/run.php   (uses a throw-away data dir) */
$tmp = sys_get_temp_dir() . '/wbtest_' . bin2hex(random_bytes(4));
mkdir($tmp);
putenv("WB_DATA=$tmp");
require __DIR__ . '/../src/bootstrap.php';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

$fail = 0; $n = 0;
function t(string $name, bool $ok, string $info = ''): void { global $fail, $n; $n++; if (!$ok) { $fail++; }; echo ($ok ? "  ok   " : "  FAIL ") . $name . ($ok ? '' : "  $info") . "\n"; }
function throws(callable $f): ?string { try { $f(); return null; } catch (Throwable $e) { return $e->getMessage(); } }
function setLive(float $w, bool $stable, string $status = 'RUNNING', int $scale = 1): void {
    Db::q('INSERT OR REPLACE INTO live_scale(scale_id, weight, unit, stable, status, updated_at, heartbeat) VALUES (?,?,?,?,?,?,?)',
        [$scale, $w, 'kg', (int)$stable, $status, microtime(true), microtime(true)]);
}
function mockUrl(string $p): string { global $mockPort; return "http://127.0.0.1:$mockPort$p"; }

Db::upgrade();
Settings::set('installed', '1');

// mock camera + ANPR HTTP services
$mockPort = random_int(20000, 40000);
$relayLog = $tmp . '/relay.log';
$mock = proc_open([PHP_BINARY, '-S', "127.0.0.1:$mockPort", __DIR__ . '/mock_server.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $mp, null, ['RELAY_LOG' => $relayLog, 'CLAUDE_LOG' => $tmp . '/claude.log', 'SAP_LOG' => $tmp . '/sap.log', 'ODOO_STATE' => $tmp . '/odoo.json'] + getenv());
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $mockPort); $i++) { usleep(100000); }
register_shutdown_function(function () use ($mock) { if (is_resource($mock)) { proc_terminate($mock); } });
$d = Settings::DEFAULTS;

echo "Parser\n";
$p = new ScaleParser($d);
foreach ([["ST,GS,+0012340kg\r\n", 12340.0], ["   12340 kg\r\n", 12340.0], ["US,GS,   950 g\r\n", 0.95], ["W 1.5 t\r\n", 1500.0]] as [$in, $exp]) {
    $r = $p->feed($in); t("frame " . trim($in), $r && abs($r['weight'] - $exp) < 0.001, json_encode($r));
}
$c = $d; $c['stable_regex'] = '/\bST\b/'; $c['unstable_regex'] = '/\bUS\b/'; $p = new ScaleParser($c);
$r = $p->feed("US,GS,+0000100kg\r\nST,GS,+0000200kg\r\n"); t('stable flag from last frame', $r['stable'] === true && $r['weight'] == 200.0);
$r = $p->feed("US,GS,+0000300kg\r\n"); t('motion flag', $r['stable'] === false);
$c = $d; $c['divisor'] = '10'; $r = (new ScaleParser($c))->feed("012340\r\n"); t('divisor', $r && $r['weight'] == 1234.0);
$c = $d; $c['terminator'] = '\x03'; $r = (new ScaleParser($c))->feed("\x02 5000 kg\x03"); t('ETX terminator', $r && $r['weight'] == 5000.0);
$p = new ScaleParser($d); $p->feed("  12"); $r = $p->feed("340 kg\r\n"); t('frame split across reads', $r && $r['weight'] == 12340.0);
t('bad regex detected', ScaleParser::regexError('bad(') !== null);
t('good regex ok', ScaleParser::regexError('/ST/') === null);

echo "Modbus\n";
t('request frame + CRC', bin2hex(Modbus::request(1, 3, 0, 2)) === '010300000002c40b');
$b = pack('CCCnn', 1, 3, 4, 1, 0x1F40); $b .= pack('v', Modbus::crc16($b));
t('32-bit big', Modbus::toNumber(Modbus::parse($b, 1, 3, 2), false, 'big') == 73536.0);
t('32-bit little word order', Modbus::toNumber([0x1F40, 1], false, 'little') == 73536.0);
t('signed 16', Modbus::toNumber([0xFFFF], true, 'big') == -1.0);
$bad = $b; $bad[5] = "\x00"; t('CRC error detected', str_contains((string)throws(fn() => Modbus::parse($bad, 1, 3, 2)), 'CRC'));
t('short reply detected', str_contains((string)throws(fn() => Modbus::parse('', 1, 3, 2)), 'short'));

echo "Input validation\n";
t('COM3 ok', valid_port('COM3')); t('ttyUSB0 ok', valid_port('/dev/ttyUSB0'));
t('shell injection rejected', !valid_port('COM1 & calc') && !valid_port('/dev/tty; rm -rf /'));
t('host ok', valid_host('192.168.1.5')); t('host injection rejected', !valid_host('a b;c'));

echo "Settings\n";
Settings::set('ora_pass', 'Secret#1');
t('secret encrypted at rest', str_starts_with((string)Db::val("SELECT v FROM settings WHERE k='ora_pass'"), 'enc:'));
Settings::reset(); t('secret round-trips', Settings::get('ora_pass') === 'Secret#1');

echo "Auth\n";
Auth::createUser('op', 'password1', 'operator');
t('login ok', Auth::login('op', 'password1'));
for ($i = 0; $i < 8; $i++) { Auth::login('nobody', 'x'); }
t('lockout after repeated failures', str_contains((string)throws(fn() => Auth::login('nobody', 'x')), 'Too many'));

echo "Weighment flow\n";
Settings::set('ora_enabled', '1');
setLive(30000, true);
t('daemon fresh', Weighment::live()['fresh'] === true);
$id = Weighment::create(['vehicle_no' => 'mh 12 ab 1234', 'party' => 'ACME', 'material' => 'Coal', 'first_type' => 'GROSS'], null);
$w = Db::one('SELECT * FROM weighments WHERE id=?', [$id]);
t('ticket created OPEN, vehicle normalised', $w['status'] === 'OPEN' && $w['vehicle_no'] === 'MH12AB1234' && $w['first_kg'] == 30000.0);
t('ticket number format', (bool)preg_match('/^WB\d{8}0001$/', $w['ticket_no']));
t('duplicate open ticket blocked', str_contains((string)throws(fn() => Weighment::create(['vehicle_no' => 'MH12AB1234'], null)), 'open ticket'));
setLive(9000, false);
t('unstable weight rejected', str_contains((string)throws(fn() => Weighment::second($id, null)), 'not stable'));
setLive(9000, true, 'ERROR');
t('scale error rejected', str_contains((string)throws(fn() => Weighment::second($id, null)), 'No live weight'));
setLive(5, true);
t('below minimum rejected', str_contains((string)throws(fn() => Weighment::second($id, null)), 'minimum'));
setLive(35000, true);
t('gross<=tare rejected', str_contains((string)throws(fn() => Weighment::second($id, null)), 'must be greater'));
setLive(9000, true); Weighment::second($id, null);
$w = Db::one('SELECT * FROM weighments WHERE id=?', [$id]);
t('closed with correct net', $w['status'] === 'CLOSED' && $w['gross_kg'] == 30000.0 && $w['tare_kg'] == 9000.0 && $w['net_kg'] == 21000.0);
t('queued for Oracle', $w['sync_status'] === 'PENDING');
t('vehicle tare learned', Db::val("SELECT tare_kg FROM vehicles WHERE reg_no='MH12AB1234'") == 9000.0);
t('manual weight disabled by default', str_contains((string)throws(fn() => Weighment::capture('1234')), 'disabled'));

setLive(31000, true);
$id2 = Weighment::create(['vehicle_no' => 'MH12AB1234', 'use_stored_tare' => '1'], null);
$w2 = Db::one('SELECT * FROM weighments WHERE id=?', [$id2]);
t('stored-tare single pass', $w2['status'] === 'CLOSED' && $w2['net_kg'] == 22000.0 && str_ends_with($w2['ticket_no'], '0002'));
t('stored tare missing blocked', str_contains((string)throws(fn() => Weighment::create(['vehicle_no' => 'NEWVEH1', 'use_stored_tare' => '1'], null)), 'No stored tare'));

setLive(8000, true);
$id3 = Weighment::create(['vehicle_no' => 'TARE1', 'first_type' => 'TARE'], null);
setLive(20000, true); Weighment::second($id3, null);
t('tare-first flow', Db::val('SELECT net_kg FROM weighments WHERE id=?', [$id3]) == 12000.0);

Db::q("UPDATE weighments SET sync_status='SYNCED' WHERE id=?", [$id]);
Weighment::cancel($id, 'wrong party');
$w = Db::one('SELECT status, sync_status FROM weighments WHERE id=?', [$id]);
t('cancelling a synced ticket re-queues it', $w['status'] === 'CANCELLED' && $w['sync_status'] === 'PENDING');
setLive(9000, true);
$idc = Weighment::create(['vehicle_no' => 'OPENCANCEL', 'first_type' => 'GROSS'], null);
Weighment::cancel($idc, 'x');
t('cancelling an open ticket needs no sync', Db::val('SELECT sync_status FROM weighments WHERE id=?', [$idc]) === 'NA');


echo "Multiple scales\n";
t('default scale exists', Scales::defaultId() === 1 && Scales::config(1)['scale_name'] === 'Scale 1');
$s2 = Scales::create('Gate 2');
Scales::save($s2, 'Gate 2', true, ['conn_type' => 'serial', 'serial_port' => '/dev/ttyUSB1', 'min_capture_kg' => '500']);
Scales::save(1, 'Scale 1', true, ['conn_type' => 'serial', 'serial_port' => '/dev/ttyUSB0']);
t('two enabled scales', count(Scales::all(true)) === 2);
t('port clash rejected', str_contains((string)throws(fn() => Scales::save($s2, 'Gate 2', true, ['serial_port' => '/dev/ttyUSB0'])), 'already uses'));
Scales::save(1, 'Scale 1', true, ['conn_type' => 'simulator']); Scales::save($s2, 'Gate 2', true, ['conn_type' => 'simulator']);
t('per-scale min weight', Scales::config($s2)['min_capture_kg'] === '500' && Scales::config(1)['min_capture_kg'] === '20');
setLive(30000, true, 'RUNNING', 1); setLive(700, true, 'RUNNING', $s2);
t('live isolated per scale', Weighment::live(1)['weight'] == 30000.0 && Weighment::live($s2)['weight'] == 700.0);
t('capture uses chosen scale', Weighment::capture(null, $s2)[0] == 700.0 && Weighment::capture(null, 1)[0] == 30000.0);
setLive(400, true, 'RUNNING', $s2);
t('scale-specific minimum enforced', str_contains((string)throws(fn() => Weighment::capture(null, $s2)), 'minimum'));
setLive(9000, false, 'RUNNING', $s2);
t('unstable on scale 2 does not block scale 1', Weighment::capture(null, 1)[0] == 30000.0 && str_contains((string)throws(fn() => Weighment::capture(null, $s2)), "'Gate 2'"));
t('unknown scale rejected', str_contains((string)throws(fn() => Weighment::capture(null, 999)), 'Unknown scale'));
t('liveAll lists both', count(Weighment::liveAll()) === 2);
$id = Weighment::create(['vehicle_no' => 'GATE2A', 'first_type' => 'GROSS', 'scale_id' => 1], null);
setLive(8000, true, 'RUNNING', $s2); setLive(30000, true, 'RUNNING', 1);
Weighment::second($id, null, $s2);
$w = Db::one('SELECT scale_id, second_scale_id, net_kg FROM weighments WHERE id=?', [$id]);
t('ticket weighed on two different bridges', (int)$w['scale_id'] === 1 && (int)$w['second_scale_id'] === $s2 && $w['net_kg'] == 22000.0);
Scales::save($s2, 'Gate 2', false, []);
t('disabled scale drops out', count(Scales::all(true)) === 1);
t('cannot delete the last scale', str_contains((string)throws(function () use ($s2) { Scales::delete($s2); Scales::delete(1); }), 'only scale'));
Scales::create('X'); // keep 2+ rows for later tests
$s2 = 2; Scales::save($s2, 'Gate 2', true, ['conn_type' => 'simulator']);

echo "Upgrade from single-scale v1 data\n";
$old = sys_get_temp_dir() . '/wbold_' . bin2hex(random_bytes(3)); mkdir($old);
$pdo = new PDO("sqlite:$old/weighbridge.sqlite");
$pdo->exec("CREATE TABLE settings(k TEXT PRIMARY KEY, v TEXT); CREATE TABLE weighments(id INTEGER PRIMARY KEY, ticket_no TEXT UNIQUE NOT NULL, vehicle_no TEXT NOT NULL, direction TEXT NOT NULL DEFAULT 'INWARD', first_type TEXT NOT NULL, status TEXT NOT NULL DEFAULT 'OPEN', sync_status TEXT NOT NULL DEFAULT 'NA', sync_tries INTEGER NOT NULL DEFAULT 0, created_at TEXT);
 INSERT INTO settings VALUES ('serial_port','COM7'),('baud','19200'),('installed','1'); INSERT INTO weighments(ticket_no,vehicle_no,first_type) VALUES ('T1','V1','GROSS');");
unset($pdo);
$out = shell_exec('WB_DATA=' . escapeshellarg($old) . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . __DIR__ . '/../src/bootstrap.php"; Db::upgrade(); $c = Scales::config(1); echo json_encode([$c["serial_port"], $c["baud"], $c["scale_name"], Db::val("SELECT scale_id FROM weighments"), Db::val("SELECT COUNT(*) FROM scales")]);'));
t('v1 settings become Scale 1 and old tickets get scale_id', $out === '["COM7","19200","Scale 1",1,1]', (string)$out);
array_map('unlink', glob("$old/*") ?: []); @rmdir($old);

echo "ANPR matching\n";
t('normalise', Anpr::normalize('mh 12-ab 1234') === 'MH12AB1234');
t('exact match', Anpr::matches('MH12AB1234', 'mh12 ab1234'));
t('OCR look-alikes 0/O 1/I 5/S', Anpr::matches('MH12AB1234', 'MHI2AB1Z34') && Anpr::matches('KA05MN0S12', 'KA05MNO512'));
t('one wrong char tolerated', Anpr::matches('MH12AB1234', 'MH12AB1284'));
t('different plates rejected', !Anpr::matches('MH12AB1234', 'MH12CD9999') && !Anpr::matches('MH12AB1234', 'DL01XY4321'));
t('short plates need exact match', !Anpr::matches('AB12', 'AB13'));
t('empty never matches', !Anpr::matches('', 'MH12AB1234'));

echo "Camera + ANPR clients (mock services)\n";
$img = Camera::fetch(['cam_url' => mockUrl('/snap.jpg?plate=mh12ab1234')]);
t('camera snapshot fetched', $img !== null && str_starts_with($img, "\xFF\xD8") && str_contains($img, 'PLATE:MH12AB1234'));
t('non-JPEG rejected', Camera::fetch(['cam_url' => mockUrl('/notjpeg.jpg')]) === null);
t('dead camera returns null', Camera::fetch(['cam_url' => 'http://127.0.0.1:1/x.jpg']) === null);
t('no camera configured', Camera::fetch(['cam_url' => '']) === null);
t('file:// URL refused', Camera::fetch(['cam_url' => 'file:///etc/passwd']) === null);
$pr = ['anpr_provider' => 'platerecognizer', 'anpr_url' => mockUrl('/pr'), 'anpr_key' => 'testkey'];
$r = Anpr::recognize($img, $pr);
t('Plate Recognizer: best plate, normalised', $r['plate'] === 'MH12AB1234' && abs($r['confidence'] - 0.93) < 0.001 && $r['error'] === null, json_encode($r));
$r = Anpr::recognize($img, ['anpr_key' => 'wrong'] + $pr);
t('Plate Recognizer: bad token -> clear error', $r['plate'] === null && str_contains((string)$r['error'], '403'), json_encode($r));
$r = Anpr::recognize(Camera::fetch(['cam_url' => mockUrl('/snap.jpg')]), $pr);
t('Plate Recognizer: no plate in picture', $r['plate'] === null && $r['error'] === null);
$r = Anpr::recognize($img, ['anpr_provider' => 'codeproject', 'anpr_url' => mockUrl('/cp')]);
t('CodeProject.AI format', $r['plate'] === 'MH12AB1234' && abs($r['confidence'] - 0.88) < 0.001, json_encode($r));
$r = Anpr::recognize($img, ['anpr_provider' => 'codeproject', 'anpr_url' => mockUrl('/boom')]);
t('service 500 -> error not exception', $r['plate'] === null && str_contains((string)$r['error'], '500'));
$r = Anpr::recognize($img, ['anpr_provider' => 'codeproject', 'anpr_url' => 'http://127.0.0.1:1/']);
t('service down -> error', $r['plate'] === null && str_contains((string)$r['error'], 'unreachable'));
$r = Anpr::recognize($img, ['anpr_provider' => 'codeproject', 'anpr_url' => 'ftp://x/']);
t('non-http URL refused', $r['plate'] === null && str_contains((string)$r['error'], 'http'));
$fake = escapeshellarg(PHP_BINARY);
$r = Anpr::recognize($img, ['anpr_provider' => 'command', 'anpr_command' => PHP_BINARY . ' ' . __DIR__ . '/fake_alpr.php {image}']);
t('command provider (OpenALPR JSON, 0-100 confidence)', $r['plate'] === 'MH12AB1234' && abs($r['confidence'] - 0.915) < 0.001, json_encode($r));
$r = Anpr::recognize($img, ['anpr_provider' => 'command', 'anpr_command' => PHP_BINARY . ' ' . __DIR__ . '/fake_alpr.php {image} plain']);
t('command provider (plain text)', $r['plate'] === 'MH12AB1234' && abs($r['confidence'] - 0.91) < 0.001, json_encode($r));
$r = Anpr::recognize($img, ['anpr_provider' => 'command', 'anpr_command' => '/nonexistent/alpr {image}']);
t('missing command -> error', $r['plate'] === null && $r['error'] !== null);
$r = Anpr::recognize($img, ['anpr_provider' => 'command', 'anpr_command' => PHP_BINARY . ' ' . __DIR__ . '/fake_alpr.php {image}; touch ' . $tmp . '/pwned']);
t('no shell: metacharacters are just arguments', !file_exists("$tmp/pwned"));

echo "ANPR in the weighing flow\n";
Settings::set('anpr_provider', 'codeproject'); Settings::set('anpr_url', mockUrl('/cp')); Settings::set('anpr_min_conf', '0.6'); Settings::set('anpr_policy', 'warn');
Scales::save(1, 'Scale 1', true, ['cam_url' => mockUrl('/snap.jpg?plate=MH12AB1234')]);
Scales::save($s2, 'Gate 2', true, ['cam_url' => mockUrl('/snap.jpg?plate=DL01XY4321')]);
setLive(30000, true, 'RUNNING', 1); setLive(9000, true, 'RUNNING', $s2);
$a = Weighment::create(['vehicle_no' => 'mh12ab1234', 'first_type' => 'GROSS', 'scale_id' => 1], null);
$w = Db::one('SELECT * FROM weighments WHERE id=?', [$a]);
t('plate matches ticket vehicle -> OK', $w['plate_flag'] === 'OK' && $w['plate_in'] === 'MH12AB1234' && $w['first_img'] && is_file(Camera::dir() . '/' . $w['first_img']));
setLive(9000, true, 'RUNNING', 1);
Weighment::second($a, null, 1);
setLive(30000, true, 'RUNNING', 1);
t('return on same truck -> still OK', Db::val('SELECT plate_flag FROM weighments WHERE id=?', [$a]) === 'OK' && Db::val('SELECT plate_out FROM weighments WHERE id=?', [$a]) === 'MH12AB1234');

$b = Weighment::create(['vehicle_no' => 'GJ01ZZ0001', 'first_type' => 'GROSS', 'scale_id' => 1], null);
t('typed vehicle differs from camera -> MISMATCH flagged (policy warn)', Db::val('SELECT plate_flag FROM weighments WHERE id=?', [$b]) === 'MISMATCH');
t('mismatch written to audit', (int)Db::val("SELECT COUNT(*) FROM audit WHERE action='plate_mismatch'") >= 1);
Weighment::cancel($b, 'test');

$c = Weighment::create(['vehicle_no' => '', 'first_type' => 'GROSS', 'scale_id' => 1], null);
t('empty vehicle box -> plate from camera used', Db::val('SELECT vehicle_no FROM weighments WHERE id=?', [$c]) === 'MH12AB1234' && Db::val('SELECT plate_flag FROM weighments WHERE id=?', [$c]) === 'OK');
t('recognised truck already open -> blocked', str_contains((string)throws(fn() => Weighment::create(['vehicle_no' => '', 'scale_id' => 1], null)), 'open ticket'));

// second weighment on the other bridge, whose camera sees a different truck
setLive(8000, true, 'RUNNING', $s2);
Weighment::second($c, null, $s2);
t('other truck on 2nd bridge -> MISMATCH flag stored', Db::val('SELECT plate_flag FROM weighments WHERE id=?', [$c]) === 'MISMATCH' && Db::val('SELECT plate_out FROM weighments WHERE id=?', [$c]) === 'DL01XY4321');

Settings::set('anpr_policy', 'block');
setLive(30000, true, 'RUNNING', 1);
$d = Weighment::create(['vehicle_no' => 'MH12AB1234', 'first_type' => 'GROSS', 'scale_id' => 1], null);
setLive(8000, true, 'RUNNING', $s2);
t('block: wrong truck on the bridge refused', str_contains((string)throws(fn() => Weighment::second($d, null, $s2)), 'must confirm') && Db::val('SELECT status FROM weighments WHERE id=?', [$d]) === 'OPEN');
Weighment::second($d, null, $s2, true);
t('block: admin override closes it and flags it', Db::val('SELECT status FROM weighments WHERE id=?', [$d]) === 'CLOSED' && Db::val('SELECT plate_flag FROM weighments WHERE id=?', [$d]) === 'MISMATCH');
setLive(30000, true, 'RUNNING', 1);
t('block: mismatch at first weighing refused', str_contains((string)throws(fn() => Weighment::create(['vehicle_no' => 'KA01AA1111', 'scale_id' => 1], null)), 'must confirm'));

Settings::set('anpr_policy', 'warn');
Scales::save(1, 'Scale 1', true, ['cam_url' => mockUrl('/snap.jpg')]);        // empty road: no plate visible
$e = Weighment::create(['vehicle_no' => 'KA01AA1111', 'first_type' => 'GROSS', 'scale_id' => 1], null);
t('camera sees no plate -> UNREAD, weighing still works', Db::val('SELECT plate_flag FROM weighments WHERE id=?', [$e]) === 'UNREAD');
Scales::save(1, 'Scale 1', true, ['cam_url' => 'http://127.0.0.1:1/dead.jpg']);
$f = Weighment::create(['vehicle_no' => 'KA02BB2222', 'first_type' => 'GROSS', 'scale_id' => 1], null);
t('camera down -> NOIMG, weighing still works', Db::val('SELECT plate_flag FROM weighments WHERE id=?', [$f]) === 'NOIMG');
Settings::set('anpr_min_conf', '0.95'); Scales::save(1, 'Scale 1', true, ['cam_url' => mockUrl('/snap.jpg?plate=KA03CC3333')]);
$g = Weighment::create(['vehicle_no' => 'KA03CC3333', 'first_type' => 'GROSS', 'scale_id' => 1], null);
t('low-confidence read is not trusted -> UNREAD', Db::val('SELECT plate_flag FROM weighments WHERE id=?', [$g]) === 'UNREAD');
Settings::set('anpr_provider', 'off');
Scales::save(1, 'Scale 1', true, ['cam_url' => mockUrl('/snap.jpg?plate=KA04DD4444')]);
$h = Weighment::create(['vehicle_no' => 'KA04DD4444', 'first_type' => 'GROSS', 'scale_id' => 1], null);
t('ANPR off -> no flag, photo still saved', Db::val('SELECT plate_flag FROM weighments WHERE id=?', [$h]) === null && Db::val('SELECT first_img FROM weighments WHERE id=?', [$h]) !== null);

echo "Supervisor (one reader per enabled scale)\n";
$sup = sys_get_temp_dir() . '/wbsup_' . bin2hex(random_bytes(3)); mkdir($sup);
$env = ['WB_DATA' => $sup] + getenv();
$q = static function (string $code) use ($sup): string { return (string)shell_exec('WB_DATA=' . escapeshellarg($sup) . ' ' . escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg('require "' . __DIR__ . '/../src/bootstrap.php"; Db::upgrade(); ' . $code)); };
$q('Scales::save(1, "A", true, ["conn_type" => "simulator"]); $i = Scales::create("B"); Scales::save($i, "B", true, ["conn_type" => "simulator"]);');
$proc = proc_open([PHP_BINARY, __DIR__ . '/../bin/scale_supervisor.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', "$sup/sup.out", 'w'], 2 => ['file', "$sup/sup.out", 'a']], $pp, null, $env);
$alive = fn(int $id) => trim($q("echo Weighment::live($id)['daemon_alive'] ? 1 : 0;")) === '1';
$deadline = microtime(true) + 15; while (microtime(true) < $deadline && !($alive(1) && $alive(2))) { usleep(500000); }
t('supervisor started a reader for each enabled scale', $alive(1) && $alive(2), (string)@file_get_contents("$sup/sup.out"));
$fresh = trim($q('echo json_encode([Weighment::live(1)["status"], Weighment::live(2)["status"]]);'));
t('both readers publishing', str_contains($fresh, 'RUNNING') || str_contains($fresh, 'CONNECTING'), $fresh);
$q('Scales::save(2, "B", false, []);');
$deadline = microtime(true) + 15; while (microtime(true) < $deadline && $alive(2)) { usleep(500000); }
t('disabling a scale stops only its reader', !$alive(2) && $alive(1));
$q('Scales::save(2, "B", true, []);');
$deadline = microtime(true) + 15; while (microtime(true) < $deadline && !$alive(2)) { usleep(500000); }
t('re-enabling starts it again', $alive(2));
$q('$i = Scales::create("C"); Scales::save($i, "C", true, ["conn_type" => "simulator"]);');
$deadline = microtime(true) + 15; while (microtime(true) < $deadline && trim($q('echo Weighment::live(3)["daemon_alive"] ? 1 : 0;')) !== '1') { usleep(500000); }
t('scale added while running gets a reader', trim($q('echo Weighment::live(3)["daemon_alive"] ? 1 : 0;')) === '1');
proc_terminate($proc); $st = proc_get_status($proc); $deadline = microtime(true) + 8; while (microtime(true) < $deadline && proc_get_status($proc)['running']) { usleep(200000); }
proc_close($proc); sleep(1);
$kids = trim((string)shell_exec('pgrep -f ' . escapeshellarg('[s]cale_daemon.php --scale=') . ' | wc -l'));
t('stopping the supervisor stops its readers', $kids === '0', "children left: $kids");
shell_exec('pkill -f ' . escapeshellarg('[s]cale_daemon.php --scale=') . ' 2>/dev/null');
array_map('unlink', array_merge(glob("$sup/*.*") ?: [], glob("$sup/logs/*") ?: [])); @rmdir("$sup/logs"); @unlink("$sup/app.key"); @rmdir($sup);

echo "Modbus coil frames\n";
t('RTU write-coil ON frame + CRC', strtoupper(bin2hex(Modbus::writeCoil(1, 0, true))) === '010500 00FF008C3A' || strtoupper(bin2hex(Modbus::writeCoil(1, 0, true))) === '01050000FF008C3A');
t('RTU write-coil OFF frame + CRC', strtoupper(bin2hex(Modbus::writeCoil(1, 0, false))) === '010500000000CDCA');
t('TCP write-coil frame (MBAP)', bin2hex(Modbus::writeCoilTcp(1, 1, 3, true)) === '000100000006010500 03ff00' || bin2hex(Modbus::writeCoilTcp(1, 1, 3, true)) === '00010000000601050003ff00');

echo "Gate controller\n";
t('hex command parser', Gates::bytes('hex:A0 01 01 A2') === "\xA0\x01\x01\xA2" && Gates::bytes('OPEN\\r\\n') === "OPEN\r\n");
t('bad hex refused', str_contains((string)throws(fn() => Gates::bytes('hex:A0 01 0')), 'hex'));
t('empty command refused', str_contains((string)throws(fn() => Gates::bytes('')), 'empty'));
$gIn = Gates::create('Main IN');  Gates::save($gIn, 'Main IN', true, 'ENTRY', null, ['driver' => 'simulator']);
$gOut = Gates::create('Main OUT'); Gates::save($gOut, 'Main OUT', true, 'EXIT', null, ['driver' => 'simulator']);
$r = Gates::command($gIn, 'open', 'manual');
t('simulator open -> state OPEN + event logged', $r['ok'] && Gates::state($gIn)['state'] === 'OPEN' && (int)Db::val("SELECT COUNT(*) FROM gate_events WHERE gate_id=? AND action='open' AND ok=1", [$gIn]) === 1);
$r = Gates::command($gIn, 'close'); t('close -> CLOSED', $r['ok'] && Gates::state($gIn)['state'] === 'CLOSED');
t('unknown gate / action handled', !Gates::command(999, 'open')['ok'] && !Gates::command($gIn, 'explode')['ok']);
Gates::save($gOut, 'Main OUT', false, 'EXIT', null, []);
t('disabled gate refuses commands', str_contains(Gates::command($gOut, 'open')['message'], 'disabled'));
Gates::save($gOut, 'Main OUT', true, 'EXIT', null, []);
Gates::save($gIn, 'Main IN', true, 'ENTRY', null, ['auto_close_sec' => '5']);
Gates::command($gIn, 'open', 'manual');
$st = Gates::state($gIn); t('auto-close scheduled', $st['close_at'] !== null && $st['close_at'] > microtime(true) + 3);
t('runDue does nothing before the time', Gates::runDue() === 0 && Gates::state($gIn)['state'] === 'OPEN');
Db::q('UPDATE gate_state SET close_at = ? WHERE gate_id = ?', [microtime(true) - 1, $gIn]);
t('runDue closes an expired gate once', Gates::runDue() === 1 && Gates::state($gIn)['state'] === 'CLOSED' && Gates::runDue() === 0);
t('auto-close event recorded', (int)Db::val("SELECT COUNT(*) FROM gate_events WHERE source='auto-close'") === 1);
Gates::save($gIn, 'Main IN', true, 'ENTRY', null, ['auto_close_sec' => '0']);

// HTTP relay: open -> wait pulse -> release
@unlink($relayLog);
$gH = Gates::create('HTTP boom');
Gates::save($gH, 'HTTP boom', true, 'BRIDGE_OUT', 1, ['driver' => 'http', 'mode' => 'pulse', 'pulse_ms' => '300', 'http_open_url' => mockUrl('/relay/open?ch=1'),
    'http_close_url' => mockUrl('/relay/close?ch=1'), 'http_release_url' => mockUrl('/relay/release?ch=1')]);
$r = Gates::command($gH, 'open', 'manual');
$lines = file($relayLog, FILE_IGNORE_NEW_LINES) ?: [];
t('http pulse: open then release, in order', $r['ok'] && count($lines) === 2 && str_contains($lines[0], '/relay/open?ch=1') && str_contains($lines[1], '/relay/release?ch=1'), json_encode($lines));
$dt = $lines ? (float)explode(' ', $lines[1])[0] - (float)explode(' ', $lines[0])[0] : 0;
t('http pulse: release after the pulse length', $dt >= 0.25 && $dt < 1.5, (string)$dt);
@unlink($relayLog); Gates::command($gH, 'close', 'manual'); $lines = file($relayLog, FILE_IGNORE_NEW_LINES) ?: [];
t('http close sends close URL then release', count($lines) === 2 && str_contains($lines[0], '/relay/close'));
Gates::save($gH, 'HTTP boom', true, 'BRIDGE_OUT', 1, ['http_close_url' => '', 'http_method' => 'POST', 'http_open_body' => '<RemoteControlDoor><cmd>open</cmd></RemoteControlDoor>']);
t('no close URL -> close unavailable', !Gates::capabilities(Gates::config($gH))['close'] && str_contains(Gates::command($gH, 'close')['message'], 'not configured'));
@unlink($relayLog); Gates::command($gH, 'open'); $lines = file($relayLog, FILE_IGNORE_NEW_LINES) ?: [];
t('http POST body sent', str_contains($lines[0] ?? '', 'POST') && str_contains($lines[0], '<cmd>open</cmd>'));
Gates::save($gH, 'HTTP boom', true, 'BRIDGE_OUT', 1, ['http_open_url' => mockUrl('/relay/fail'), 'http_release_url' => '']);
Db::q("UPDATE gate_state SET state='CLOSED' WHERE gate_id=?", [$gH]);
$failsBefore = (int)Db::val("SELECT COUNT(*) FROM gate_events WHERE gate_id=? AND ok=0", [$gH]);
$r = Gates::command($gH, 'open');
t('relay error -> ok=false, state unchanged, event FAIL', !$r['ok'] && str_contains($r['message'], '503') && Gates::state($gH)['state'] === 'CLOSED' && (int)Db::val("SELECT COUNT(*) FROM gate_events WHERE gate_id=? AND ok=0", [$gH]) === $failsBefore + 1);
Gates::save($gH, 'HTTP boom', true, 'BRIDGE_OUT', 1, ['http_open_url' => 'http://127.0.0.1:1/x']);
t('relay unreachable -> clear error', str_contains(Gates::command($gH, 'open')['message'], 'unreachable'));
Gates::save($gH, 'HTTP boom', true, 'BRIDGE_OUT', 1, ['http_open_url' => mockUrl('/relay/auth/open'), 'http_user' => 'gateuser', 'http_pass' => 'wrong']);
t('relay auth failure reported', str_contains(Gates::command($gH, 'open')['message'], '401'));
Gates::save($gH, 'HTTP boom', true, 'BRIDGE_OUT', 1, ['http_pass' => 'gatepass']);
t('relay basic auth works, password stored encrypted', Gates::command($gH, 'open')['ok'] && str_contains((string)Db::val('SELECT cfg FROM gates WHERE id=?', [$gH]), 'enc:') && !str_contains((string)Db::val('SELECT cfg FROM gates WHERE id=?', [$gH]), 'gatepass'));
t('non-http URL refused', str_contains((string)throws(fn() => Gates::execute(['driver' => 'http', 'mode' => 'latched', 'http_open_url' => 'file:///etc/passwd'] + Gates::DEFAULTS, 'open')), 'http'));

// raw TCP relay
$tcpPort = random_int(41000, 45000); $tcpLog = $tmp . '/tcp.log';
$tcp = proc_open([PHP_BINARY, __DIR__ . '/mock_tcp.php', (string)$tcpPort, $tcpLog], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tp);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $tcpPort); $i++) { usleep(100000); }
$gT = Gates::create('TCP relay');
Gates::save($gT, 'TCP relay', true, 'ENTRY', null, ['driver' => 'tcp', 'mode' => 'pulse', 'pulse_ms' => '100', 'tcp_host' => '127.0.0.1', 'tcp_port' => (string)$tcpPort,
    'open_cmd' => 'hex:A0 01 01 A2', 'close_cmd' => 'CLOSE\r\n', 'release_cmd' => 'hex:A0 01 00 A1']);
$r = Gates::command($gT, 'open'); $got = array_map(fn($l) => explode(' ', $l)[1], file($tcpLog, FILE_IGNORE_NEW_LINES) ?: []);
t('tcp: raw hex open then release', $r['ok'] && $got === ['a00101a2', 'a00100a1'], json_encode($got));
@unlink($tcpLog); Gates::command($gT, 'close'); $got = array_map(fn($l) => explode(' ', $l)[1], file($tcpLog, FILE_IGNORE_NEW_LINES) ?: []);
t('tcp: text command with escapes', ($got[0] ?? '') === bin2hex("CLOSE\r\n"));
proc_terminate($tcp);
$tcpPort2 = random_int(45001, 48000); $tcpLog2 = $tmp . '/tcp2.log';
$tcp2 = proc_open([PHP_BINARY, __DIR__ . '/mock_tcp.php', (string)$tcpPort2, $tcpLog2, 'modbus'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $tp2);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $tcpPort2); $i++) { usleep(100000); }
$gM = Gates::create('PLC');
Gates::save($gM, 'PLC', true, 'ENTRY', null, ['driver' => 'modbus_tcp', 'mode' => 'latched', 'tcp_host' => '127.0.0.1', 'tcp_port' => (string)$tcpPort2, 'modbus_slave' => '1', 'coil_open' => '3', 'coil_close' => '']);
$r = Gates::command($gM, 'open'); $r2 = Gates::command($gM, 'close');
$got = array_map(fn($l) => substr(explode(' ', $l)[1], 8), file($tcpLog2, FILE_IGNORE_NEW_LINES) ?: []);   // strip transaction id + protocol id
t('modbus tcp latched: coil 3 ON = open, OFF = close', $r['ok'] && $r2['ok'] && $got === ['00060105' . '0003ff00', '00060105' . '00030000'], json_encode($got));
proc_terminate($tcp2); usleep(500000);
$m = Gates::command($gM, 'open')['message'];
t('modbus tcp: nobody listening -> error', str_contains($m, 'cannot connect'), $m);

// serial relay boards on a pseudo-terminal
$py = trim((string)shell_exec('command -v python3'));
if ($py !== '') {
    foreach (['serial' => false, 'modbus' => true] as $kind => $echo) {
        $slog = $tmp . "/ser_$kind.log"; @unlink($slog); @unlink("$slog.tty");
        $sp = proc_open(array_filter([$py, __DIR__ . '/fake_relay.py', $slog, $echo ? 'echo' : null]), [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $spp);
        for ($i = 0; $i < 50 && !is_file("$slog.tty"); $i++) { usleep(100000); }
        $tty = trim((string)@file_get_contents("$slog.tty"));
        $gS = Gates::create("Serial $kind");
        $cfg = $echo ? ['driver' => 'modbus_rtu', 'mode' => 'pulse', 'pulse_ms' => '100', 'serial_port' => $tty, 'modbus_slave' => '1', 'coil_open' => '0', 'coil_close' => '1']
                     : ['driver' => 'serial', 'mode' => 'latched', 'serial_port' => $tty, 'open_cmd' => 'hex:A0 01 01 A2', 'close_cmd' => 'hex:A0 01 00 A1'];
        Gates::save($gS, "Serial $kind", true, 'EXIT', null, $cfg);
        $r = Gates::command($gS, 'open'); $r2 = Gates::command($gS, 'close');
        $got = array_map(fn($l) => explode(' ', $l)[1], file($slog, FILE_IGNORE_NEW_LINES) ?: []);
        $exp = $echo ? ['01050000ff008c3a', '010500000000cdca', '01050001ff00ddfa', '0105000100009c0a'] : ['a00101a2', 'a00100a1'];
        $got = array_values(array_filter(array_map(null, [implode('', $got)]) ));
        $flat = implode('', array_map(fn($l) => explode(' ', $l)[1], file($slog, FILE_IGNORE_NEW_LINES) ?: []));
        t("$kind relay board: exact bytes on the wire", $r['ok'] && $r2['ok'] && $flat === implode('', $exp), $flat . ' vs ' . implode('', $exp) . ' ' . $r['message'] . $r2['message']);
        if ($echo) {
            Gates::save($gS, "Serial $kind", true, 'EXIT', null, ['serial_port' => $tty, 'expect_reply' => '1']);
            proc_terminate($sp); usleep(300000);
            t('modbus rtu: no reply -> clear error', str_contains(Gates::command($gS, 'open')['message'], 'Cannot open') || str_contains(Gates::command($gS, 'open')['message'], 'did not reply') || str_contains(Gates::command($gS, 'open')['message'], 'failed'));
        } else { proc_terminate($sp); }
    }
    $gs = Gates::create('Clash'); $sc1 = Scales::config(1);
    Scales::save(1, 'Scale 1', true, ['conn_type' => 'serial', 'serial_port' => '/dev/ttyUSB7']);
    t('gate cannot share a scale reader\'s serial port', str_contains((string)throws(fn() => Gates::save($gs, 'Clash', true, 'ENTRY', null, ['driver' => 'serial', 'serial_port' => '/dev/ttyUSB7', 'open_cmd' => 'x'])), 'scale reader'));
    Gates::save($gs, 'Clash', true, 'ENTRY', null, ['driver' => 'serial', 'serial_port' => '/dev/ttyUSB8', 'open_cmd' => 'x']);
    t('scale cannot take a gate relay\'s serial port', str_contains((string)throws(fn() => Scales::save(2, 'Gate 2', true, ['conn_type' => 'serial', 'serial_port' => '/dev/ttyUSB8'])), 'gate relay'));
    Scales::save(1, 'Scale 1', true, ['conn_type' => 'simulator']); Gates::delete($gs);
} else { echo "  skip serial relay tests (no python3)\n"; }

echo "Gate entry (gate pass)\n";
Settings::set('anpr_provider', 'off');
$e1 = GateEntries::register(['vehicle_no' => 'mh 04 gate 1', 'driver' => 'Ravi', 'party' => 'ACME', 'material' => 'Coal', 'purpose' => 'DELIVERY'], $gIn, true);
$en = Db::one('SELECT * FROM gate_entries WHERE id=?', [$e1['id']]);
t('entry registered INSIDE, number format, master data learned', $en['status'] === 'INSIDE' && $en['vehicle_no'] === 'MH04GATE1' && (bool)preg_match('/^GE\d{8}0001$/', $en['entry_no']) && Db::val("SELECT COUNT(*) FROM vehicles WHERE reg_no='MH04GATE1'") == 1);
t('entry opens the entry gate', $e1['gate']['ok'] && Gates::state($gIn)['state'] === 'OPEN' && (int)Db::val("SELECT COUNT(*) FROM gate_events WHERE source LIKE 'entry GE%'") === 1);
t('same vehicle cannot enter twice', str_contains((string)throws(fn() => GateEntries::register(['vehicle_no' => 'MH04GATE1'], $gIn)), 'already inside'));
t('vehicle number required', str_contains((string)throws(fn() => GateEntries::register(['vehicle_no' => ' '], $gIn)), 'required'));
Db::q("UPDATE vehicles SET blocked=1, block_reason='unpaid dues' WHERE reg_no='BADTRUCK1'") ; Db::q("INSERT OR IGNORE INTO vehicles(reg_no, blocked, block_reason) VALUES ('BADTRUCK1', 1, 'unpaid dues')");
Gates::command($gIn, 'close');
$msg = (string)throws(fn() => GateEntries::register(['vehicle_no' => 'badtruck1'], $gIn));
t('blocked vehicle refused, gate stays closed, reason shown', str_contains($msg, 'BLOCKED') && str_contains($msg, 'unpaid dues') && Gates::state($gIn)['state'] === 'CLOSED' && (int)Db::val("SELECT COUNT(*) FROM gate_entries WHERE vehicle_no='BADTRUCK1'") === 0);
t('denial is audited and in the gate log', (int)Db::val("SELECT COUNT(*) FROM audit WHERE action='gate_denied'") === 1 && (int)Db::val("SELECT COUNT(*) FROM gate_events WHERE action='deny'") === 1);
$e2 = GateEntries::register(['vehicle_no' => 'badtruck1'], $gIn, true, true);
t('admin override admits a blocked vehicle and notes it', Db::val('SELECT override_note FROM gate_entries WHERE id=?', [$e2['id']]) !== null);
t('register without a gate works', GateEntries::register(['vehicle_no' => 'NOGATE01'], 0)['gate'] === null);

// weighing links to the gate entry
Settings::set('gate_require_entry', 'off'); setLive(30000, true, 'RUNNING', 1);
$tk = Weighment::create(['vehicle_no' => 'MH04GATE1', 'first_type' => 'GROSS', 'scale_id' => 1], null);
t('ticket links to the vehicle\'s open gate entry', (int)Db::val('SELECT gate_entry_id FROM weighments WHERE id=?', [$tk]) === (int)$e1['id']);
Settings::set('gate_require_entry', 'block');
t('policy block: weighing without gate entry refused', str_contains((string)throws(fn() => Weighment::create(['vehicle_no' => 'NEVERENTERED', 'scale_id' => 1], null)), 'no gate entry'));
$tk2 = Weighment::create(['vehicle_no' => 'NEVERENTERED', 'scale_id' => 1], null, true);
t('policy block: admin override weighs it', $tk2 > 0 && Db::val('SELECT gate_entry_id FROM weighments WHERE id=?', [$tk2]) === null);
Weighment::cancel($tk2, 'test');
Settings::set('gate_require_entry', 'warn');
$tk3 = Weighment::create(['vehicle_no' => 'WARNONLY', 'scale_id' => 1], null); Weighment::cancel($tk3, 'test');
t('policy warn: weighing allowed, audited', (int)Db::val("SELECT COUNT(*) FROM audit WHERE action='no_gate_entry' AND detail='WARNONLY'") === 1);
Settings::set('gate_require_entry', 'off');

// exit rules
Settings::set('gate_exit_policy', 'block');
t('exit blocked while the ticket is open', str_contains((string)throws(fn() => GateEntries::exit($e1['id'], $gOut)), 'OPEN weighbridge ticket'));
Gates::command($gOut, 'close');
setLive(9000, true, 'RUNNING', 1); Weighment::second($tk, null, 1);
$x = GateEntries::exit($e1['id'], $gOut, true);
$en = Db::one('SELECT * FROM gate_entries WHERE id=?', [$e1['id']]);
t('after weighing: exit recorded and exit gate opened', $en['status'] === 'EXITED' && $en['out_at'] !== null && (int)$en['out_gate_id'] === $gOut && $x['gate']['ok'] && Gates::state($gOut)['state'] === 'OPEN');
t('exited vehicle may enter again', GateEntries::register(['vehicle_no' => 'MH04GATE1'], 0)['id'] > 0);
t('delivery with no weighment blocked on exit', str_contains((string)throws(fn() => GateEntries::exit($e2['id'], $gOut)), 'no completed weighment'));
GateEntries::exit($e2['id'], $gOut, false, true);
t('admin override exit succeeds and is noted', Db::val('SELECT status FROM gate_entries WHERE id=?', [$e2['id']]) === 'EXITED' && str_contains((string)Db::val('SELECT override_note FROM gate_entries WHERE id=?', [$e2['id']]), 'override'));
$ev = GateEntries::register(['vehicle_no' => 'VISITOR01', 'purpose' => 'VISIT'], 0);
GateEntries::exit($ev['id'], 0);
t('visitor exit needs no weighment', Db::val('SELECT status FROM gate_entries WHERE vehicle_no=\'VISITOR01\'') === 'EXITED');
Settings::set('gate_exit_policy', 'warn');
$ew = GateEntries::register(['vehicle_no' => 'WARNEXIT1', 'purpose' => 'DISPATCH'], 0);
GateEntries::exit($ew['id'], 0);
t('policy warn: exit allowed, warning noted', str_contains((string)Db::val('SELECT override_note FROM gate_entries WHERE id=?', [$ew['id']]), 'warned'));
$ec = GateEntries::register(['vehicle_no' => 'CANCELME1'], 0); GateEntries::cancel($ec['id'], 'typo');
t('cancelled entry is not inside', GateEntries::inside('CANCELME1') === null);
t('exit of an unknown/left entry refused', str_contains((string)throws(fn() => GateEntries::exit(9999, 0)), 'not found'));

echo "Weighbridge boom automation\n";
$gB1 = Gates::create('Bridge out 1'); Gates::save($gB1, 'Bridge out 1', true, 'BRIDGE_OUT', 1, ['driver' => 'simulator']);
$gB2 = Gates::create('Bridge out 2'); Gates::save($gB2, 'Bridge out 2', true, 'BRIDGE_OUT', 2, ['driver' => 'simulator']);
$gB3 = Gates::create('Bridge out manual'); Gates::save($gB3, 'Bridge out manual', true, 'BRIDGE_OUT', 1, ['driver' => 'simulator', 'auto_open' => '0']);
setLive(30000, true, 'RUNNING', 1);
$tid = Weighment::create(['vehicle_no' => 'BOOMTEST1', 'first_type' => 'GROSS', 'scale_id' => 1], null);
t('opening for first weighing does not open the exit boom', Gates::state($gB1)['state'] === 'UNKNOWN');
setLive(9000, true, 'RUNNING', 1);
Weighment::second($tid, null, 1);
t('completing on scale 1 opens ITS exit boom only', Gates::state($gB1)['state'] === 'OPEN' && Gates::state($gB2)['state'] === 'UNKNOWN');
t('boom with automation off stays shut', Gates::state($gB3)['state'] === 'UNKNOWN');
t('boom open logged with the ticket as source', (int)Db::val("SELECT COUNT(*) FROM gate_events WHERE gate_id=? AND source LIKE 'ticket WB%'", [$gB1]) === 1);
Db::q('UPDATE vehicles SET tare_kg=9000 WHERE reg_no=\'BOOMTEST1\'');
setLive(30000, true, 'RUNNING', 2);
Weighment::create(['vehicle_no' => 'BOOMTEST1', 'use_stored_tare' => '1', 'scale_id' => 2], null);
t('single-pass (stored tare) ticket also opens the boom', (int)Db::val("SELECT COUNT(*) FROM gate_events WHERE gate_id=? AND source LIKE 'ticket WB%'", [$gB2]) === 1);
Gates::save($gB1, 'Bridge out 1', true, 'BRIDGE_OUT', 1, ['driver' => 'http', 'mode' => 'latched', 'http_open_url' => 'http://127.0.0.1:1/dead']);
setLive(30000, true, 'RUNNING', 1);
$tid = Weighment::create(['vehicle_no' => 'BOOMFAIL1', 'first_type' => 'GROSS', 'scale_id' => 1], null);
setLive(9000, true, 'RUNNING', 1); Weighment::second($tid, null, 1);
t('a dead barrier never blocks or undoes the weighing', Db::val('SELECT status FROM weighments WHERE id=?', [$tid]) === 'CLOSED' && (int)Db::val("SELECT COUNT(*) FROM gate_events WHERE gate_id=? AND ok=0", [$gB1]) === 1);

require __DIR__ . '/docs_tests.php';
require __DIR__ . '/odoo_tests.php';

echo "Oracle (no driver expected in CI)\n";
$r = OracleSync::syncPending(10);
t('sync degrades gracefully without driver', OracleSync::available() !== '' || ($r['error'] !== null && Db::val("SELECT COUNT(*) FROM weighments WHERE sync_status='PENDING'") >= 2));

echo "\n$n checks, $fail failed\n";
array_map('unlink', array_merge(glob("$tmp/*.*") ?: [], glob("$tmp/snapshots/*") ?: [])); @rmdir("$tmp/snapshots"); @unlink("$tmp/app.key"); @rmdir($tmp);
exit($fail ? 1 : 0);
