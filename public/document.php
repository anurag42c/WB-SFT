<?php
require __DIR__ . '/_layout.php';
Auth::require();
$id = (int)($_GET['id'] ?? 0);
$d = Documents::get($id);
if (!$d) { http_response_code(404); exit('Document not found'); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    set_time_limit(240);
    try {
        $do = $_POST['do'] ?? '';
        switch ($do) {
            case 'save': case 'verify':
                if ($d['status'] !== 'VERIFIED') { Documents::save($id, $_POST); }
                if ($do === 'verify') { Documents::verify($id); flash('Verified. It is now matched with the weighbridge and queued for sending.'); }
                else { flash('Saved.'); }
                DocMatch::run($id);
                break;
            case 'reocr':
                $r = Documents::extract($id);
                flash($r['ok'] ? 'Read again. Check the details against the picture.' : 'Could not read the picture: ' . $r['error'], $r['ok'] ? 'ok' : 'err');
                DocMatch::run($id);
                break;
            case 'reopen': Documents::reopen($id); DocMatch::run($id); flash('Reopened for editing.'); break;
            case 'cancel': Auth::require(true); Documents::cancel($id, trim($_POST['reason'] ?? '') ?: 'no reason'); flash('Document cancelled.'); redirect('documents.php');
            case 'rematch': DocMatch::run($id); flash('Matched again with the weighbridge.'); break;
            case 'link': DocMatch::link($id, (int)$_POST['ticket_id']); flash('Ticket linked.'); break;
            case 'unlink': DocMatch::unlink($id, (int)$_POST['ticket_id']); flash('Ticket unlinked.'); break;
            case 'exc':
                DocMatch::setExceptionStatus((int)$_POST['exc_id'], (string)$_POST['status'], (string)($_POST['note'] ?? '')); flash('Exception updated.'); break;
            case 'send':
                $r = DocSync::sendOne($id, !empty($_POST['force']) && Auth::isAdmin());
                flash(implode(' ', $r['messages']), $r['ok'] ? 'ok' : 'err'); break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect("document.php?id=$id");
}

$lines = Documents::lines($id);
if (!$lines && $d['status'] !== 'VERIFIED') { $lines = [['line_no' => 1, 'material_code' => '', 'description' => '', 'hsn' => '', 'qty' => '', 'uom' => '', 'rate' => '', 'amount' => '']]; }
$tickets = DocMatch::linkedTickets($id);
$facts = DocMatch::facts($id);
$exc = Db::all("SELECT * FROM doc_exceptions WHERE doc_id = ? ORDER BY CASE severity WHEN 'HIGH' THEN 0 WHEN 'WARN' THEN 1 ELSE 2 END, id", [$id]);
$cands = [];
if ($d['status'] !== 'CANCELLED') { $linkedIds = array_column($tickets, 'id'); foreach (array_slice(DocMatch::candidates($d, Documents::lines($id)), 0, 8) as $c) { if ($c['score'] >= 15 && !in_array($c['ticket']['id'], $linkedIds)) { $cands[] = $c; } } }
$ro = $d['status'] === 'VERIFIED';
$warn = json_decode((string)$d['ocr_warnings'], true) ?: [];
$miss = fn($v) => ($v === null || $v === '') && !$ro ? ' missing' : '';
$f = fn($k) => e((string)$d[$k]);
$in = fn(string $k, string $label, string $extra = '') => '<div><label>' . $label . '</label><input name="' . $k . '" value="' . e((string)$d[$k]) . '" class="' . trim($miss($d[$k])) . '" ' . ($ro ? 'readonly' : '') . ' ' . $extra . '></div>';
$badge = ['MATCHED' => 'ok', 'REVIEW' => 'warn', 'EXCEPTION' => 'bad', 'UNMATCHED' => ''];
$t = DocSync::targets();
page_head($d['doc_no']);
?>
<div class="dochead"><a href="documents.php">&larr; Documents</a> <b><?= e($d['doc_no']) ?></b>
  <span class="badge <?= $ro ? 'ok' : 'warn' ?>"><?= e($d['status']) ?></span>
  <span class="badge <?= $badge[$d['match_status']] ?? '' ?>">WEIGHBRIDGE: <?= e($d['match_status']) ?></span>
  <?php if ($d['exc_high']): ?><span class="badge bad"><?= (int)$d['exc_high'] ?> HIGH</span><?php endif; ?><?php if ($d['exc_warn']): ?><span class="badge warn"><?= (int)$d['exc_warn'] ?> warn</span><?php endif; ?>
  <span class="hint">uploaded by <?= e($d['uploaded_by']) ?> <?= e(substr((string)$d['uploaded_at'], 0, 16)) ?><?= $d['verified_by'] ? ' &middot; verified by ' . e($d['verified_by']) . ' ' . e(substr((string)$d['verified_at'], 0, 16)) : '' ?></span></div>

<div class="grid docgrid">
  <div class="card viewer"><h2>Original</h2>
    <?php if ($d['mime'] === 'application/pdf'): ?><embed src="docfile.php?id=<?= $id ?>" type="application/pdf" style="width:100%;height:760px">
    <?php else: ?><a href="docfile.php?id=<?= $id ?>" target="_blank"><img src="docfile.php?id=<?= $id ?>" alt="Uploaded document" class="docimg"></a><div class="hint">Click the picture to open it full size.</div><?php endif; ?>
    <?php if ($d['ocr_provider']): ?><div class="hint">Read by <b><?= e($d['ocr_provider']) ?></b><?= $d['ocr_conf'] !== null ? ' &middot; confidence ' . round((float)$d['ocr_conf'] * 100) . '%' : '' ?></div><?php endif; ?>
    <?php if ($d['ocr_error']): ?><div class="flash err"><?= e($d['ocr_error']) ?></div><?php endif; ?>
    <?php if ($warn): ?><div class="flash warnbox"><b>The reader was unsure about:</b><ul><?php foreach ($warn as $w) echo '<li>' . e($w) . '</li>'; ?></ul></div><?php endif; ?>
  </div>

  <div>
  <form method="post" id="docform"><?= csrf_field() ?>
    <div class="card"><h2>Document details</h2>
      <div class="row"><div><label>Tag</label><select name="doc_type" id="doc_type" <?= $ro ? 'disabled' : '' ?>><?php foreach (Documents::TYPES as $k => $l) echo '<option value="' . $k . '"' . ($d['doc_type'] === $k ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select><?php if ($ro): ?><input type="hidden" name="doc_type" value="<?= $f('doc_type') ?>"><?php endif; ?></div>
        <?= $in('invoice_no', 'Invoice / challan no *') ?><?= $in('invoice_date', 'Date (YYYY-MM-DD)') ?></div>
      <div class="row"><?= $in('supplier', 'Supplier *', 'list="dl_party"') ?><?= $in('supplier_tax_id', 'Supplier GSTIN / tax id') ?></div>
      <div class="row t_po"><?= $in('po_no', 'PO number *') ?><?= $in('buyer', 'Buyer') ?></div>
      <div class="row t_ret"><?= $in('orig_invoice_no', 'Original invoice no (being returned)') ?><?= $in('ref_no', 'Return reference / reason ref') ?></div>
      <div class="row"><?= $in('vehicle_no', 'Vehicle no', 'style="text-transform:uppercase"') ?><?= $in('transporter', 'Transporter') ?><?= $in('eway_no', 'E-way bill / LR no') ?></div>
      <div class="row"><?= $in('currency', 'Currency') ?><?= $in('subtotal', 'Subtotal') ?><?= $in('tax_amount', 'Tax') ?><?= $in('total_amount', 'Total') ?></div>
      <div><label>Remarks</label><input name="remarks" value="<?= $f('remarks') ?>" <?= $ro ? 'readonly' : '' ?>></div>
    </div>

    <div class="card"><h2>Line items</h2>
      <table id="lines"><tr><th>Code</th><th>Description</th><th>HSN</th><th class="n">Qty</th><th>Unit</th><th class="n">Rate</th><th class="n">Amount</th><th></th></tr>
      <?php foreach ($lines as $i => $l): $bad = $l['qty'] !== '' && $l['qty'] !== null && !$l['uom'] && !$ro; ?>
        <tr class="ln"><?php foreach (['material_code' => 12, 'description' => 30, 'hsn' => 8, 'qty' => 9, 'uom' => 6, 'rate' => 10, 'amount' => 11] as $k => $w): ?>
          <td><input name="line[<?= $i ?>][<?= $k ?>]" value="<?= e((string)$l[$k]) ?>" class="<?= ($k === 'uom' && $bad) ? 'missing' : '' ?> <?= in_array($k, ['qty', 'rate', 'amount']) ? 'num' : '' ?>" size="<?= $w ?>" <?= $ro ? 'readonly' : '' ?> <?= $k === 'uom' ? 'list="dl_uom"' : '' ?>></td><?php endforeach; ?>
          <td><?php if (!$ro): ?><button type="button" class="sec rmline" title="Remove line">&times;</button><?php endif; ?></td></tr>
      <?php endforeach; ?></table>
      <datalist id="dl_uom"><?php foreach (['MT', 'KG', 'QTL', 'NOS', 'LTR', 'BAG', 'M'] as $u) echo '<option value="' . $u . '">'; ?></datalist>
      <?php if (!$ro): ?><p><button type="button" class="sec" id="addline">+ Add line</button></p><?php endif; ?>
    </div>

    <div class="card actions">
      <?php if (!$ro): ?>
        <button name="do" value="save">Save</button>
        <button name="do" value="verify" class="ok">Save &amp; Verify</button>
        <?php if (Ocr::enabled()): ?><button name="do" value="reocr" class="sec" onclick="return confirm('Read the picture again? Your edits on this screen will be replaced.')">Read again</button><?php endif; ?>
      <?php else: ?>
        <button name="do" value="reopen" class="sec" onclick="return confirm('Reopen for editing? It will need to be verified and sent again.')">Reopen</button>
      <?php endif; ?>
      <button name="do" value="rematch" class="sec">Match again</button>
      <?php if (Auth::isAdmin()): ?><button name="do" value="cancel" class="sec" onclick="var r=prompt('Cancel this document - reason?');if(!r)return false;var i=document.createElement('input');i.type='hidden';i.name='reason';i.value=r;this.form.appendChild(i)">Cancel document</button><?php endif; ?>
    </div>
  </form>

  <div class="card"><h2>Weighbridge match</h2>
    <?php if ($facts['ticket_kg'] !== null): ?>
      <div class="qtybar <?= $facts['ok'] ? 'okq' : ($facts['checkable'] ? 'badq' : '') ?>">
        <div><span>Document</span><b><?= $facts['closest_kg'] !== null ? number_format($facts['closest_kg']) . ' kg' : ($facts['doc_kg'] !== null ? number_format($facts['doc_kg']) . ' kg' : 'unit unknown') ?></b></div>
        <div><span>Weighbridge net</span><b><?= number_format($facts['ticket_kg']) ?> kg</b></div>
        <div><span>Difference</span><b><?= $facts['diff_kg'] !== null ? sprintf('%+.1f kg (%+.2f%%)', $facts['diff_kg'], $facts['diff_pct']) : '-' ?></b></div>
        <div><span>Tolerance</span><b><?= e((string)$facts['tol_pct']) ?>%</b></div></div>
    <?php endif; ?>
    <?php if ($tickets): ?><table><tr><th>Ticket</th><th>Vehicle</th><th>Party / material</th><th class="n">Net kg</th><th>Date</th><th>Link</th><th></th></tr>
      <?php foreach ($tickets as $tk): ?><tr><td><a href="ticket.php?id=<?= (int)$tk['id'] ?>"><?= e($tk['ticket_no']) ?></a> <span class="hint"><?= e($tk['status']) ?></span></td><td><?= e($tk['vehicle_no']) ?></td>
        <td><?= e($tk['party']) ?><div class="hint"><?= e($tk['material']) ?></div></td><td class="n"><?= $tk['net_kg'] !== null ? number_format((float)$tk['net_kg']) : '' ?></td><td><?= e(substr((string)($tk['second_at'] ?: $tk['first_at']), 0, 10)) ?></td>
        <td><span class="badge"><?= e($tk['link']) ?></span><?= $tk['score'] ? ' <span class="hint">' . round((float)$tk['score']) . '</span>' : '' ?></td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="unlink"><input type="hidden" name="ticket_id" value="<?= (int)$tk['id'] ?>"><button class="sec">Unlink</button></form></td></tr><?php endforeach; ?></table>
    <?php else: ?><p class="hint">No weighbridge ticket is linked yet.</p><?php endif; ?>
    <?php if ($cands && $d['status'] !== 'CANCELLED'): ?><h3 style="margin:12px 0 4px">Possible tickets</h3><table><tr><th>Ticket</th><th>Vehicle</th><th>Party / material</th><th class="n">Net kg</th><th>Why</th><th></th></tr>
      <?php foreach ($cands as $c): $tk = $c['ticket']; ?><tr><td><?= e($tk['ticket_no']) ?></td><td><?= e($tk['vehicle_no']) ?></td><td><?= e($tk['party']) ?><div class="hint"><?= e($tk['material']) ?></div></td><td class="n"><?= $tk['net_kg'] !== null ? number_format((float)$tk['net_kg']) : '' ?></td>
        <td class="hint"><?= e(implode(', ', $c['why']) ?: 'weak') ?> (<?= round($c['score']) ?>)</td>
        <td><form method="post"><?= csrf_field() ?><input type="hidden" name="do" value="link"><input type="hidden" name="ticket_id" value="<?= (int)$tk['id'] ?>"><button class="sec">Link</button></form></td></tr><?php endforeach; ?></table><?php endif; ?>
  </div>

  <div class="card"><h2>Exceptions</h2>
    <?php if (!$exc): ?><p class="okt">&#10003; No differences found.</p><?php endif; ?>
    <?php foreach ($exc as $x): ?>
      <div class="exc <?= strtolower($x['severity']) ?> <?= $x['status'] !== 'OPEN' ? 'done' : '' ?>">
        <div><span class="badge <?= ['HIGH' => 'bad', 'WARN' => 'warn', 'INFO' => ''][$x['severity']] ?>"><?= e($x['severity']) ?></span> <code><?= e($x['code']) ?></code>
          <?php if ($x['status'] !== 'OPEN'): ?><span class="badge ok"><?= e($x['status']) ?></span> <span class="hint"><?= e((string)$x['updated_by']) ?>: <?= e((string)$x['note']) ?></span><?php endif; ?></div>
        <div><?= e($x['message']) ?></div>
        <?php if ($x['severity'] !== 'INFO'): ?><form method="post" class="excform"><?= csrf_field() ?><input type="hidden" name="do" value="exc"><input type="hidden" name="exc_id" value="<?= (int)$x['id'] ?>">
          <?php if ($x['status'] === 'OPEN'): ?><input name="note" placeholder="Note (required)" size="28"><button name="status" value="RESOLVED" class="sec">Resolved</button><?php if (Auth::isAdmin()): ?><button name="status" value="WAIVED" class="sec">Waive</button><?php endif; ?>
          <?php else: ?><button name="status" value="OPEN" class="sec">Reopen</button><?php endif; ?></form><?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="card"><h2>Sending</h2>
    <?php if ($d['status'] !== 'VERIFIED'): ?><p class="hint">Verify the document to send it.</p>
    <?php elseif (!$t['oracle'] && !$t['sap']): ?><p class="hint">No target is configured. An administrator can choose Oracle and/or SAP in Setup &gt; Documents.</p>
    <?php else: ?>
      <table><?php foreach (['oracle' => ['Oracle', $t['oracle']], 'sap' => ['SAP', $t['sap']]] as $k => [$lab, $on]) if ($on): ?><tr><td><?= $lab ?></td><td><span class="badge <?= ['SYNCED' => 'ok', 'FAILED' => 'bad', 'PENDING' => 'warn'][$d[$k . '_status']] ?? '' ?>"><?= e($d[$k . '_status']) ?></span>
        <?= $d[$k . '_at'] ? '<span class="hint">' . e($d[$k . '_at']) . '</span>' : '' ?> <?= $k === 'sap' && $d['sap_ref'] ? '<code>' . e($d['sap_ref']) . '</code>' : '' ?><div class="hint badt"><?= e((string)$d[$k . '_error']) ?></div></td></tr><?php endif; ?></table>
      <?php if (DocSync::held($d)): ?><div class="flash warnbox">Held: <?= (int)$d['exc_high'] ?> open HIGH exception(s). Resolve or waive them first<?= Auth::isAdmin() ? ', or an administrator can send anyway' : '' ?>.</div><?php endif; ?>
      <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center"><?= csrf_field() ?><input type="hidden" name="do" value="send"><button class="ok">Send now</button>
        <?php if (Auth::isAdmin() && DocSync::held($d)): ?><label style="text-transform:none;font-weight:400"><input type="checkbox" name="force" value="1"> send anyway</label><?php endif; ?></form>
      <p class="hint">Automatic: the background service sends pending documents every 30 seconds. Anything changed after sending (details, links, exceptions) is sent again.</p>
    <?php endif; ?>
  </div>
  </div>
</div>
<datalist id="dl_party"><?php foreach (Db::all('SELECT name FROM parties WHERE active=1 ORDER BY name') as $r) echo '<option value="' . e($r['name']) . '">'; ?></datalist>
<?php page_foot();
