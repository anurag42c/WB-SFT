<?php
/**
 * Read-only REST API for ERP integration (Odoo, SAP, Oracle jobs ...). Authorization: Bearer <token> (Setup > General).
 *   GET tickets.php?since_id=0&limit=100              closed/cancelled tickets, ascending id (use the last id as the next since_id)
 *   GET tickets.php?ticket_no=WB000123                one ticket (any status)
 *   GET tickets.php?from=2026-09-01&to=2026-09-30&status=CLOSED&vehicle=MH31&party=Balaji&material=Iron   filters (dates are first-weight dates)
 */
require_once __DIR__ . '/../../../src/bootstrap.php';
Api::begin();
if (isset($_GET['ticket_no'])) {
    $w = Db::one('SELECT * FROM weighments WHERE ticket_no = ?', [trim((string)$_GET['ticket_no'])]);
    $w ? Api::out(['ticket' => Api::ticket($w)]) : Api::err(404, 'ticket not found');
}
$where = ["status IN ('CLOSED','CANCELLED')"]; $args = [];
$since = max(0, (int)($_GET['since_id'] ?? 0)); $where[] = 'id > ?'; $args[] = $since;
if (in_array($_GET['status'] ?? '', ['CLOSED', 'CANCELLED'], true)) { $where[] = 'status = ?'; $args[] = $_GET['status']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? ''))) { $where[] = 'substr(first_at,1,10) >= ?'; $args[] = $_GET['from']; }
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? ''))) { $where[] = 'substr(first_at,1,10) <= ?'; $args[] = $_GET['to']; }
foreach (['vehicle' => 'vehicle_no', 'party' => 'party', 'material' => 'material'] as $q => $col) {
    if (($_GET[$q] ?? '') !== '') { $where[] = "$col LIKE ? ESCAPE '\\'"; $args[] = '%' . addcslashes((string)$_GET[$q], '%_\\') . '%'; }
}
$args[] = Api::limit();
$rows = Db::all('SELECT * FROM weighments WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ?', $args);
Api::out(['count' => count($rows), 'next_since_id' => $rows ? (int)end($rows)['id'] : $since, 'tickets' => array_map([Api::class, 'ticket'], $rows)]);
