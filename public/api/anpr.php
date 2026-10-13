<?php
// Operator button "Read plate": snapshot from the chosen scale's camera -> ANPR service -> plate + known-vehicle info.
require __DIR__ . '/../_layout.php';
Auth::require();
Auth::checkCsrf();
session_write_close();
header('Content-Type: application/json');
function out(array $a): never { echo json_encode($a); exit; }

if (!Anpr::enabled()) { out(['ok' => false, 'error' => 'ANPR is off. Enable it in Setup > Plate recognition.']); }
$cfg = Scales::config((int)($_POST['scale_id'] ?? 0));
if (!$cfg) { out(['ok' => false, 'error' => 'Unknown scale.']); }
$img = Camera::fetch($cfg);
if ($img === null) { out(['ok' => false, 'error' => "No usable camera image from '{$cfg['scale_name']}'. Check the camera URL in Setup > Scales."]); }
$r = Anpr::recognize($img);
if ($r['error']) { out(['ok' => false, 'error' => $r['error']]); }
$min = (float)Settings::get('anpr_min_conf');
if ($r['plate'] === null || ($r['confidence'] ?? 1.0) < $min) {
    out(['ok' => false, 'error' => $r['plate'] === null ? 'No plate found in the image.' : "Plate {$r['plate']} read with low confidence (" . round(($r['confidence'] ?? 0) * 100) . '%). Type it manually.', 'plate' => $r['plate']]);
}
$veh = null;
foreach (Db::all('SELECT reg_no, tare_kg FROM vehicles') as $v) { if (Anpr::matches($v['reg_no'], $r['plate'])) { $veh = $v; break; } }
$open = $veh ? Db::val("SELECT id FROM weighments WHERE vehicle_no = ? AND status = 'OPEN'", [$veh['reg_no']]) : null;
out(['ok' => true, 'plate' => $veh['reg_no'] ?? $r['plate'], 'read' => $r['plate'], 'confidence' => $r['confidence'],
     'known' => (bool)$veh, 'tare_kg' => $veh['tare_kg'] ?? null, 'open_ticket_id' => $open ? (int)$open : null]);
