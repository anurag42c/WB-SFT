<?php
require __DIR__ . '/../_layout.php';
if (!Auth::user()) { http_response_code(401); exit('{}'); }
session_write_close();
header('Content-Type: application/json'); header('Cache-Control: no-store');
$out = [];
foreach (Weighment::liveAll() as $l) {
    $out[] = ['id' => (int)$l['scale_id'], 'name' => $l['name'], 'weight' => (float)($l['weight'] ?? 0), 'stable' => (int)($l['stable'] ?? 0),
        'fresh' => $l['fresh'], 'daemon_alive' => $l['daemon_alive'], 'status' => $l['status'] ?? '', 'message' => $l['message'] ?? '',
        'raw' => $l['raw'] ?? '', 'min' => $l['min']];
}
echo json_encode(['scales' => $out]);
