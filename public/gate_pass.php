<?php
require __DIR__ . '/_layout.php';
Auth::require();
$e = Db::one('SELECT * FROM gate_entries WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$e) { http_response_code(404); exit('Entry not found'); }
$gn = fn($id) => (string)(Gates::find((int)$id)['name'] ?? '-');
$tickets = Db::all('SELECT ticket_no, status, net_kg FROM weighments WHERE gate_entry_id = ? ORDER BY id', [$e['id']]);
page_head('Gate pass ' . $e['entry_no']);
?>
<div class="card" style="max-width:560px;margin:auto">
  <h2 style="text-align:center;font-size:20px"><?= e(Settings::get('company_name')) ?></h2>
  <h3 style="text-align:center;margin:4px 0">GATE PASS <?= $e['status'] === 'CANCELLED' ? '(CANCELLED)' : '' ?></h3>
  <table>
    <tr><th>Entry no</th><td><?= e($e['entry_no']) ?></td><th>Status</th><td><?= e($e['status']) ?></td></tr>
    <tr><th>Vehicle</th><td><b><?= e($e['vehicle_no']) ?></b></td><th>Purpose</th><td><?= e(GateEntries::PURPOSES[$e['purpose']] ?? $e['purpose']) ?></td></tr>
    <tr><th>Driver</th><td><?= e($e['driver']) ?> <?= e($e['driver_phone']) ?></td><th>Challan</th><td><?= e($e['challan_no']) ?></td></tr>
    <tr><th>Party</th><td><?= e($e['party']) ?></td><th>Material</th><td><?= e($e['material']) ?></td></tr>
    <tr><th>In</th><td><?= e($e['in_at']) ?><br><small><?= e($gn($e['in_gate_id'])) ?> &middot; <?= e($e['operator']) ?></small></td>
        <th>Out</th><td><?= e((string)$e['out_at']) ?: '&mdash;' ?><br><small><?= $e['out_at'] ? e($gn($e['out_gate_id'])) . ' &middot; ' . e((string)$e['exit_operator']) : '' ?></small></td></tr>
    <?php if ($tickets): ?><tr><th>Weighment</th><td colspan="3"><?php foreach ($tickets as $t) echo '<a href="ticket.php?id=' . (int)Db::val('SELECT id FROM weighments WHERE ticket_no = ?', [$t['ticket_no']]) . '">' . e($t['ticket_no']) . '</a> (' . e($t['status']) . ($t['net_kg'] !== null ? ', net ' . number_format((float)$t['net_kg']) . ' kg' : '') . ') '; ?></td></tr><?php endif; ?>
    <?php if ($e['plate_flag']): ?><tr><th>Plate check</th><td colspan="3"><span class="badge <?= ['OK' => 'ok', 'MISMATCH' => 'bad', 'UNREAD' => 'warn'][$e['plate_flag']] ?? '' ?>"><?= e($e['plate_flag']) ?></span> <small><?= e(trim(($e['plate_in'] ?? '') . ' / ' . ($e['plate_out'] ?? ''), ' /')) ?></small></td></tr><?php endif; ?>
  </table>
  <p><?= e($e['remarks']) ?> <?= e((string)$e['override_note']) ?></p>
  <?php foreach (['img_in' => 'In', 'img_out' => 'Out'] as $c => $l) if ($e[$c]) echo '<img src="snapshot.php?f=' . e($e[$c]) . '" alt="' . $l . ' photo" style="max-width:48%;margin-right:1%">'; ?>
  <p class="noprint"><button onclick="print()">Print</button> <a class="btn sec" href="gate.php">Back</a></p>
</div>
<?php page_foot();
