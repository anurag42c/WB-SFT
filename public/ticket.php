<?php
require __DIR__ . '/_layout.php';
Auth::require();
$w = Db::one('SELECT * FROM weighments WHERE id = ?', [(int)($_GET['id'] ?? 0)]);
if (!$w) { http_response_code(404); exit('Ticket not found'); }
$kg = fn($v) => $v === null ? '-' : number_format((float)$v, 0) . ' kg';
page_head('Ticket ' . $w['ticket_no']);
?>
<div class="card" style="max-width:640px;margin:auto">
  <h2 style="text-align:center;font-size:20px"><?= e(Settings::get('company_name')) ?></h2>
  <p style="text-align:center;margin:0"><?= nl2br(e(Settings::get('company_address'))) ?></p>
  <h3 style="text-align:center">WEIGHMENT SLIP <?= $w['status'] === 'CANCELLED' ? '(CANCELLED)' : '' ?></h3>
  <table>
    <tr><th>Ticket no</th><td><?= e($w['ticket_no']) ?></td><th>Direction</th><td><?= e($w['direction']) ?></td></tr>
    <tr><th>Vehicle</th><td><?= e($w['vehicle_no']) ?></td><th>Driver</th><td><?= e($w['driver']) ?></td></tr>
    <tr><th>Party</th><td><?= e($w['party']) ?></td><th>Material</th><td><?= e($w['material']) ?></td></tr>
    <tr><th>Scale in / out</th><td><?= e((string)(Scales::find((int)$w['scale_id'])['name'] ?? '-')) ?> / <?= e((string)(Scales::find((int)$w['second_scale_id'])['name'] ?? '-')) ?></td>
        <th>Plate check</th><td><?php if ($w['plate_flag']): ?><span class="badge <?= ['OK' => 'ok', 'MISMATCH' => 'bad', 'UNREAD' => 'warn'][$w['plate_flag']] ?? '' ?>"><?= e($w['plate_flag']) ?></span> <small><?= e(trim(($w['plate_in'] ?? '') . ' / ' . ($w['plate_out'] ?? ''), ' /')) ?></small><?php else: ?>-<?php endif; ?></td></tr>
    <tr><th>Challan</th><td><?= e($w['challan_no']) ?></td><th>Operator</th><td><?= e($w['operator']) ?></td></tr>
    <tr><th>1st (<?= e($w['first_type']) ?>)</th><td><?= $kg($w['first_kg']) ?><br><small><?= e($w['first_at']) ?><?= $w['first_manual'] ? ' (manual)' : '' ?></small></td>
        <th>2nd</th><td><?= $kg($w['second_kg']) ?><br><small><?= e($w['second_at']) ?><?= $w['second_manual'] ? ' (manual)' : '' ?></small></td></tr>
    <tr><th>Gross</th><td><b><?= $kg($w['gross_kg']) ?></b></td><th>Tare</th><td><b><?= $kg($w['tare_kg']) ?></b></td></tr>
    <tr><th>NET WEIGHT</th><td colspan="3" style="font-size:22px"><b><?= $kg($w['net_kg']) ?></b></td></tr>
  </table>
  <?php $docs = Db::all("SELECT d.id, d.doc_no, d.invoice_no, d.supplier, d.match_status FROM document_tickets dt JOIN documents d ON d.id = dt.doc_id WHERE dt.ticket_id = ? AND dt.link <> 'BLOCK' AND d.status <> 'CANCELLED'", [$w['id']]); ?>
  <?php if ($docs): ?><p class="noprint"><b>Invoice / return document:</b> <?php foreach ($docs as $x) echo '<a href="document.php?id=' . (int)$x['id'] . '">' . e($x['doc_no']) . '</a> (' . e($x['invoice_no']) . ', ' . e($x['supplier']) . ', ' . e($x['match_status']) . ') '; ?></p><?php endif; ?>
  <p><?= e($w['remarks']) ?></p>
  <?php foreach (['first_img' => '1st', 'second_img' => '2nd'] as $c => $l) if ($w[$c]) echo '<img src="snapshot.php?f=' . e($w[$c]) . '" alt="' . $l . ' weighment photo" style="max-width:48%;margin-right:1%">'; ?>
  <p class="noprint"><button onclick="print()">Print</button> <a class="btn sec" href="index.php">Back</a></p>
</div>
<?php page_foot();
