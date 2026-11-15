<?php
// Gate API for the Gate screen: state (poll), command (open/close), anpr (plate lookup at a gate camera).
require __DIR__ . '/../_layout.php';
Auth::require();
$action = $_REQUEST['action'] ?? '';
function out(array $a, int $code = 200): never { http_response_code($code); header('Content-Type: application/json'); header('Cache-Control: no-store'); echo json_encode($a); exit; }

if ($action === 'state') {
    session_write_close();
    $r = [];
    foreach (Gates::all(true) as $g) {
        $st = Gates::state((int)$g['id']); $c = Gates::config((int)$g['id']); $cap = Gates::capabilities($c);
        $ev = Db::one('SELECT at, action, source, ok, message FROM gate_events WHERE gate_id = ? ORDER BY id DESC LIMIT 1', [$g['id']]);
        $r[] = ['id' => (int)$g['id'], 'name' => $g['name'], 'role' => $g['role'], 'state' => $st['state'], 'closes_in' => $st['close_at'] ? max(0, (int)round($st['close_at'] - microtime(true))) : null,
                'can_close' => $cap['close'], 'last' => $ev];
    }
    out(['gates' => $r]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { out(['ok' => false, 'error' => 'POST required'], 405); }
Auth::checkCsrf();
session_write_close();

if ($action === 'command') {
    $do = ($_POST['do'] ?? '') === 'close' ? 'close' : 'open';
    $res = Gates::command((int)($_POST['gate_id'] ?? 0), $do, 'manual');
    out(['ok' => $res['ok'], 'message' => $res['message']]);
}
if ($action === 'anpr') {
    if (!Anpr::enabled()) { out(['ok' => false, 'error' => 'ANPR is off (Setup > Plate recognition).']); }
    $cfg = Gates::config((int)($_POST['gate_id'] ?? 0));
    if (!$cfg) { out(['ok' => false, 'error' => 'Unknown gate.']); }
    $img = Camera::fetch($cfg);
    if ($img === null) { out(['ok' => false, 'error' => "No usable camera image from gate '{$cfg['gate_name']}'. Set its camera URL in Setup > Gates."]); }
    $r = Anpr::recognize($img);
    if ($r['error']) { out(['ok' => false, 'error' => $r['error']]); }
    if ($r['plate'] === null || ($r['confidence'] ?? 1.0) < (float)Settings::get('anpr_min_conf')) { out(['ok' => false, 'error' => 'No plate read with enough confidence. Type it manually.']); }
    $veh = null;
    foreach (Db::all('SELECT reg_no, tare_kg, blocked, block_reason FROM vehicles') as $v) { if (Anpr::matches($v['reg_no'], $r['plate'])) { $veh = $v; break; } }
    $plate = $veh['reg_no'] ?? $r['plate'];
    $in = GateEntries::inside($plate);
    out(['ok' => true, 'plate' => $plate, 'confidence' => $r['confidence'], 'known' => (bool)$veh, 'blocked' => (bool)($veh['blocked'] ?? 0), 'block_reason' => $veh['block_reason'] ?? null,
         'inside_entry_id' => $in ? (int)$in['id'] : null, 'inside_entry_no' => $in['entry_no'] ?? null]);
}
out(['ok' => false, 'error' => 'Unknown action'], 400);
