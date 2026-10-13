<?php
require __DIR__ . '/_layout.php';
Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    if (isset($_POST['sync'])) { Auth::require(true); $r = OracleSync::syncPending(500); flash("Oracle sync: {$r['ok']} sent, {$r['failed']} failed" . ($r['error'] ? ' - ' . $r['error'] : ''), $r['error'] ? 'err' : 'ok'); }
    if (isset($_POST['retry'])) { Auth::require(true); Db::q("UPDATE weighments SET sync_status='PENDING' WHERE status IN ('CLOSED','CANCELLED') AND sync_status='FAILED'"); flash('Failed tickets re-queued.'); }
    redirect('reports.php?' . http_build_query($_GET));
}

$from = $_GET['from'] ?? date('Y-m-d'); $to = $_GET['to'] ?? date('Y-m-d');
$q = trim($_GET['q'] ?? ''); $st = $_GET['sync'] ?? '';
$where = ['date(created_at) BETWEEN ? AND ?']; $args = [$from, $to];
if ($q !== '') { $where[] = '(vehicle_no LIKE ? OR party LIKE ? OR material LIKE ? OR ticket_no LIKE ?)'; array_push($args, "%$q%", "%$q%", "%$q%", "%$q%"); }
if ($st !== '') { $where[] = 'sync_status = ?'; $args[] = $st; }
$rows = Db::all('SELECT * FROM weighments WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 2000', $args);

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv'); header('Content-Disposition: attachment; filename="weighments_' . $from . '_' . $to . '.csv"');
    $o = fopen('php://output', 'w');
    fputcsv($o, ['Ticket', 'Date', 'Vehicle', 'Party', 'Material', 'Direction', 'Gross kg', 'Tare kg', 'Net kg', 'Status', 'Scale', 'Plate in', 'Plate out', 'Plate check', 'Oracle'], ',', '"', '');
    foreach ($rows as $w) { fputcsv($o, [$w['ticket_no'], $w['created_at'], $w['vehicle_no'], $w['party'], $w['material'], $w['direction'], $w['gross_kg'], $w['tare_kg'], $w['net_kg'], $w['status'], Scales::find((int)$w['scale_id'])['name'] ?? '', $w['plate_in'], $w['plate_out'], $w['plate_flag'], $w['sync_status']], ',', '"', ''); }
    exit;
}
$tot = array_sum(array_map(fn($w) => $w['status'] === 'CLOSED' ? (float)$w['net_kg'] : 0, $rows));
$pending = Db::val("SELECT COUNT(*) FROM weighments WHERE status IN ('CLOSED','CANCELLED') AND sync_status IN ('PENDING','FAILED')");
page_head('Reports');
?>
<div class="card"><form method="get" class="row" style="align-items:end">
  <div><label>From</label><input type="date" name="from" value="<?= e($from) ?>"></div><div><label>To</label><input type="date" name="to" value="<?= e($to) ?>"></div>
  <div><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="vehicle / party / material / ticket"></div>
  <div><label>Oracle</label><select name="sync"><option value="">All</option><?php foreach (['PENDING', 'SYNCED', 'FAILED', 'NA'] as $s) echo '<option' . ($st === $s ? ' selected' : '') . '>' . $s . '</option>'; ?></select></div>
  <div><button>Filter</button> <button name="csv" value="1" class="sec">CSV</button></div></form></div>
<?php if (Auth::isAdmin()): ?><div class="card"><form method="post" style="display:flex;gap:10px;align-items:center"><?= csrf_field() ?>
  <span><b><?= (int)$pending ?></b> ticket(s) waiting for Oracle</span><button name="sync" value="1" class="ok">Send to Oracle now</button><button name="retry" value="1" class="sec">Re-queue failed</button></form></div><?php endif; ?>
<div class="card"><p><b><?= count($rows) ?></b> tickets &middot; Net total <b><?= number_format($tot) ?> kg</b> (<?= number_format($tot / 1000, 3) ?> t)</p>
<table><tr><th>Ticket</th><th>Date</th><th>Vehicle</th><th>Party</th><th>Material</th><th>Scale</th><th class="n">Gross</th><th class="n">Tare</th><th class="n">Net</th><th>Status</th><th>Plate</th><th>Oracle</th></tr>
<?php foreach ($rows as $w): ?>
  <tr><td><a href="ticket.php?id=<?= (int)$w['id'] ?>"><?= e($w['ticket_no']) ?></a></td><td><?= e(substr($w['created_at'], 0, 16)) ?></td><td><?= e($w['vehicle_no']) ?></td><td><?= e($w['party']) ?></td><td><?= e($w['material']) ?></td><td><?= e((string)(Scales::find((int)$w['scale_id'])['name'] ?? '')) ?></td>
  <td class="n"><?= $w['gross_kg'] !== null ? number_format((float)$w['gross_kg']) : '' ?></td><td class="n"><?= $w['tare_kg'] !== null ? number_format((float)$w['tare_kg']) : '' ?></td><td class="n"><b><?= $w['net_kg'] !== null ? number_format((float)$w['net_kg']) : '' ?></b></td>
  <td><?= e($w['status']) ?></td><td><?php if ($w['plate_flag']): ?><span class="badge <?= ['OK' => 'ok', 'MISMATCH' => 'bad', 'UNREAD' => 'warn'][$w['plate_flag']] ?? '' ?>" title="<?= e(trim(($w['plate_in'] ?? '') . ' / ' . ($w['plate_out'] ?? ''), ' /')) ?>"><?= e($w['plate_flag']) ?></span><?php endif; ?></td><td><span class="badge <?= ['SYNCED' => 'ok', 'FAILED' => 'bad', 'PENDING' => 'warn'][$w['sync_status']] ?? '' ?>" title="<?= e($w['sync_error']) ?>"><?= e($w['sync_status']) ?></span></td></tr>
<?php endforeach; ?></table></div>
<?php page_foot();
