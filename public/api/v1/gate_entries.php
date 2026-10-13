<?php
/**
 * Gate register.
 *   GET  gate_entries.php?since_id=0&status=INSIDE|EXITED|CANCELLED&vehicle=MH31&limit=100   entries with the factory ticket result (wb_*) and the outside slip (ext_*)
 *   POST gate_entries.php  {"vehicle_no":"MH31AB1234","purpose":"DELIVERY|DISPATCH|VISIT|EMPTY","party":"..","material":"..","driver":"..","driver_phone":"..","challan_no":".."}
 *        registers the vehicle as INSIDE (no barrier command; blocked vehicles are refused with 422). Needs "Allow API writes".
 */
require_once __DIR__ . '/../../../src/bootstrap.php';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
Api::begin($post);
if ($post) {
    try { $r = GateEntries::register(Api::body(), 0, false, false); }
    catch (Throwable $e) { Api::err(422, $e->getMessage()); }
    $e = Db::one('SELECT id, entry_no, vehicle_no, status, in_at FROM gate_entries WHERE id = ?', [$r['id']]);
    Api::out(['ok' => true] + $e, 201);
}
$where = ['id > ?']; $args = [max(0, (int)($_GET['since_id'] ?? 0))];
if (in_array($_GET['status'] ?? '', ['INSIDE', 'EXITED', 'CANCELLED'], true)) { $where[] = 'status = ?'; $args[] = $_GET['status']; }
if (($_GET['vehicle'] ?? '') !== '') { $where[] = "vehicle_no LIKE ? ESCAPE '\\'"; $args[] = '%' . addcslashes(strtoupper(preg_replace('/\s+/', '', (string)$_GET['vehicle'])), '%_\\') . '%'; }
$args[] = Api::limit();
$rows = Db::all('SELECT id, entry_no, vehicle_no, driver, party, material, purpose, challan_no, status, in_at, out_at, plate_in, plate_out, plate_flag,
    wb_ticket_no, wb_status, wb_net_kg, ext_wb_name, ext_slip_no, ext_gross_kg, ext_tare_kg, ext_net_kg, ext_at FROM gate_entries WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ?', $args);
foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $v = GateEntries::variance(Db::one('SELECT * FROM gate_entries WHERE id = ?', [$r['id']])); $r['slip_variance_kg'] = $v ? round($v['diff_kg'], 3) : null; $r['slip_ok'] = $v ? $v['ok'] : null; }
Api::out(['count' => count($rows), 'next_since_id' => $rows ? (int)end($rows)['id'] : (int)$args[0], 'entries' => $rows]);
