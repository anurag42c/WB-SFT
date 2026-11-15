<?php
require __DIR__ . '/_layout.php';
Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    try { DocMatch::setExceptionStatus((int)$_POST['exc_id'], (string)$_POST['status'], (string)($_POST['note'] ?? '')); flash('Updated.'); }
    catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('exceptions.php?' . http_build_query(array_intersect_key($_GET, array_flip(['sev', 'st', 'code']))));
}

DocMatch::scanTickets();                       // weighed-but-no-invoice check, cheap enough to refresh on every visit
$sev = $_GET['sev'] ?? ''; $st = $_GET['st'] ?? 'OPEN'; $code = $_GET['code'] ?? '';
$where = ['1=1']; $args = [];
if (in_array($sev, ['HIGH', 'WARN', 'INFO'], true)) { $where[] = 'e.severity = ?'; $args[] = $sev; }
if (in_array($st, ['OPEN', 'RESOLVED', 'WAIVED'], true)) { $where[] = 'e.status = ?'; $args[] = $st; }
if ($code !== '') { $where[] = 'e.code = ?'; $args[] = $code; }
$rows = Db::all("SELECT e.*, d.doc_no, d.supplier, d.invoice_no, d.doc_type, w.ticket_no, w.vehicle_no AS t_vehicle FROM doc_exceptions e
                 LEFT JOIN documents d ON d.id = e.doc_id LEFT JOIN weighments w ON w.id = e.ticket_id
                 WHERE " . implode(' AND ', $where) . " AND (e.doc_id IS NULL OR d.status <> 'CANCELLED') ORDER BY CASE e.severity WHEN 'HIGH' THEN 0 WHEN 'WARN' THEN 1 ELSE 2 END, e.id DESC LIMIT 500", $args);
$byCode = Db::all("SELECT code, severity, COUNT(*) AS n FROM doc_exceptions WHERE status = 'OPEN' GROUP BY code, severity ORDER BY CASE severity WHEN 'HIGH' THEN 0 ELSE 1 END, n DESC");
$open = ['HIGH' => 0, 'WARN' => 0];
foreach ($byCode as $b) { if (isset($open[$b['severity']])) { $open[$b['severity']] += (int)$b['n']; } }
$titles = ['NO_TICKET' => 'No weighbridge ticket', 'QTY_MISMATCH' => 'Quantity differs', 'VEHICLE_MISMATCH' => 'Vehicle differs', 'DIRECTION_MISMATCH' => 'Wrong direction', 'TICKET_CANCELLED' => 'Ticket cancelled',
    'TICKET_MULTI_DOC' => 'Ticket on two documents', 'DUPLICATE_INVOICE' => 'Duplicate invoice', 'PO_MISSING' => 'PO number missing', 'PO_UNKNOWN' => 'PO not found', 'PO_VENDOR_MISMATCH' => 'Vendor not on PO',
    'PO_OVER_QTY' => 'Over PO quantity', 'RETURN_OVER_QTY' => 'Return exceeds invoice', 'PARTY_MISMATCH' => 'Party differs', 'MATERIAL_MISMATCH' => 'Material differs', 'DATE_MISMATCH' => 'Date differs',
    'TICKET_OPEN' => 'Ticket incomplete', 'UOM_MISSING' => 'Unit missing', 'NO_INVOICE_NO' => 'No invoice no', 'NO_SUPPLIER' => 'No supplier', 'NO_DATE' => 'No date', 'NO_LINES' => 'No lines',
    'PO_LINE_UNKNOWN' => 'Item not on PO', 'PO_RATE_MISMATCH' => 'Rate differs from PO', 'RETURN_NO_REF' => 'Return has no reference', 'RETURN_ORIG_UNKNOWN' => 'Original invoice unknown',
    'RETURN_SUPPLIER_MISMATCH' => 'Return supplier differs', 'RETURN_LINE_UNKNOWN' => 'Returned item not on invoice', 'NO_DOCUMENT' => 'Weighed, no invoice', 'QTY_UNCHECKED' => 'Quantity not checked', 'PO_NOT_CHECKED' => 'PO not verified'];
page_head('Exceptions');
?>
<div class="card"><div class="stats">
  <div class="<?= $open['HIGH'] ? 'redn' : '' ?>"><b><?= $open['HIGH'] ?></b><span>open HIGH</span></div><div><b><?= $open['WARN'] ?></b><span>open warnings</span></div>
  <div><b><?= (int)Db::val("SELECT COUNT(*) FROM doc_exceptions WHERE status='RESOLVED'") ?></b><span>resolved</span></div><div><b><?= (int)Db::val("SELECT COUNT(*) FROM doc_exceptions WHERE status='WAIVED'") ?></b><span>waived</span></div></div>
  <div class="chips"><?php foreach ($byCode as $b): ?><a class="chip <?= $b['severity'] === 'HIGH' ? 'hi' : '' ?>" href="?code=<?= e($b['code']) ?>&st=OPEN"><?= e($titles[$b['code']] ?? $b['code']) ?> <b><?= (int)$b['n'] ?></b></a><?php endforeach; ?><?php if ($code || $sev || $st !== 'OPEN'): ?><a class="chip" href="exceptions.php">&times; clear filter</a><?php endif; ?></div>
</div>
<div class="card"><form method="get" class="row" style="align-items:end">
  <div><label>Severity</label><select name="sev"><option value="">All</option><?php foreach (['HIGH', 'WARN', 'INFO'] as $s) echo '<option' . ($sev === $s ? ' selected' : '') . '>' . $s . '</option>'; ?></select></div>
  <div><label>Status</label><select name="st"><option value="">All</option><?php foreach (['OPEN', 'RESOLVED', 'WAIVED'] as $s) echo '<option' . ($st === $s ? ' selected' : '') . '>' . $s . '</option>'; ?></select></div>
  <div><button>Filter</button></div></form></div>
<div class="card"><table><tr><th>Severity</th><th>What</th><th>Document / ticket</th><th>Details</th><th>Decision</th></tr>
<?php foreach ($rows as $x): ?>
  <tr class="<?= $x['status'] !== 'OPEN' ? 'doneRow' : '' ?>"><td><span class="badge <?= ['HIGH' => 'bad', 'WARN' => 'warn', 'INFO' => ''][$x['severity']] ?>"><?= e($x['severity']) ?></span></td>
    <td><b><?= e($titles[$x['code']] ?? $x['code']) ?></b></td>
    <td><?php if ($x['doc_no']): ?><a href="document.php?id=<?= (int)$x['doc_id'] ?>"><?= e($x['doc_no']) ?></a><div class="hint"><?= e($x['invoice_no']) ?> &middot; <?= e($x['supplier']) ?></div><?php endif; ?>
      <?php if ($x['ticket_no']): ?><a href="ticket.php?id=<?= (int)$x['ticket_id'] ?>"><?= e($x['ticket_no']) ?></a><div class="hint"><?= e($x['t_vehicle']) ?></div><?php endif; ?></td>
    <td><?= e($x['message']) ?><?php if ($x['status'] !== 'OPEN'): ?><div class="hint"><span class="badge ok"><?= e($x['status']) ?></span> <?= e((string)$x['updated_by']) ?>: <?= e((string)$x['note']) ?></div><?php endif; ?></td>
    <td><?php if ($x['severity'] !== 'INFO'): ?><form method="post" class="excform"><?= csrf_field() ?><input type="hidden" name="exc_id" value="<?= (int)$x['id'] ?>">
      <?php if ($x['status'] === 'OPEN'): ?><input name="note" placeholder="Note (required)" size="22"><button name="status" value="RESOLVED" class="sec">Resolved</button><?php if (Auth::isAdmin()): ?><button name="status" value="WAIVED" class="sec">Waive</button><?php endif; ?>
      <?php else: ?><button name="status" value="OPEN" class="sec">Reopen</button><?php endif; ?></form><?php endif; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="5" class="okt">&#10003; Nothing here.</td></tr><?php endif; ?></table>
<p class="hint">"Weighed, no invoice" lists inward tickets older than <?= (int)Settings::get('doc_scan_grace_h') ?> hours (within the last <?= (int)Settings::get('doc_scan_days') ?> days) that no document has been linked to. Upload the invoice, or link the ticket to it from the document screen.</p></div>
<?php page_foot();
