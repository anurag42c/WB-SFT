<?php
// Unauthenticated, minimal: for uptime monitors. 200 = every enabled scale reader is alive, 503 otherwise.
require_once __DIR__ . '/../../src/bootstrap.php';
header('Content-Type: application/json'); header('Cache-Control: no-store');
try {
    $scales = []; $ok = true;
    foreach (Scales::all(true) as $s) {
        $l = Weighment::live((int)$s['id']);
        $scales[] = ['id' => (int)$s['id'], 'name' => $s['name'], 'reader' => $l['daemon_alive'], 'status' => $l['status'] ?? null];
        $ok = $ok && $l['daemon_alive'];
    }
    $pending = (int)Db::val("SELECT COUNT(*) FROM weighments WHERE status IN ('CLOSED','CANCELLED') AND sync_status IN ('PENDING','FAILED')");
    $docs = ['pending_send' => (int)Db::val("SELECT COUNT(*) FROM documents WHERE status = 'VERIFIED' AND (oracle_status IN ('PENDING','FAILED') OR sap_status IN ('PENDING','FAILED'))"),
             'to_verify' => (int)Db::val("SELECT COUNT(*) FROM documents WHERE status IN ('UPLOADED','EXTRACTED')"),
             'open_high_exceptions' => (int)Db::val("SELECT COUNT(*) FROM doc_exceptions WHERE status = 'OPEN' AND severity = 'HIGH'")];
    http_response_code($ok && $scales ? 200 : 503);
    echo json_encode(['ok' => $ok && (bool)$scales, 'scales' => $scales, 'oracle_pending' => $pending, 'documents' => $docs]);
} catch (Throwable) { http_response_code(503); echo '{"ok":false}'; }
