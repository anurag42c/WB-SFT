<?php
require __DIR__ . '/_layout.php';
Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($_FILES) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    flash('That file is bigger than this server accepts (php.ini: upload_max_filesize ' . ini_get('upload_max_filesize') . ', post_max_size ' . ini_get('post_max_size') . '). Use a smaller picture, or ask the administrator to raise these limits.', 'err');
    redirect('documents.php');
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['do'] ?? '') === 'upload') {
    Auth::checkCsrf();
    set_time_limit(240);
    try {
        $f = $_FILES['file'] ?? null;
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException($f && $f['error'] === UPLOAD_ERR_INI_SIZE ? 'The file is larger than the server allows (php.ini upload_max_filesize).' : 'Choose a picture or PDF first.');
        }
        $id = Documents::create($f['tmp_name'], $f['name'], (string)($_POST['doc_type'] ?? ''), (string)($_POST['ref'] ?? ''));
        if (Ocr::enabled() && !empty($_POST['read'])) {
            $r = Documents::extract($id);
            if (!$r['ok']) { flash('Uploaded, but the picture could not be read automatically: ' . $r['error'] . ' You can enter the details by hand.', 'err'); }
            else { flash('Uploaded and read. Please check the details against the picture, then Verify.'); }
        } else { flash(Ocr::enabled() ? 'Uploaded. Press "Read again" to extract the details, or type them in.' : 'Uploaded. OCR is off, so please type the details in (an administrator can enable OCR in Setup > Documents).'); }
        DocMatch::run($id);
        redirect('document.php?id=' . $id);
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); redirect('documents.php'); }
}

$q = trim($_GET['q'] ?? ''); $st = $_GET['status'] ?? ''; $ty = $_GET['type'] ?? ''; $ms = $_GET['match'] ?? '';
$where = ["status <> 'CANCELLED'"]; $args = [];
if ($q !== '') { $where[] = '(doc_no LIKE ? OR invoice_no LIKE ? OR supplier LIKE ? OR po_no LIKE ? OR vehicle_no LIKE ? OR wb_tickets LIKE ?)'; array_push($args, ...array_fill(0, 6, "%$q%")); }
if (in_array($st, ['UPLOADED', 'EXTRACTED', 'VERIFIED'], true)) { $where[] = 'status = ?'; $args[] = $st; }
if (isset(Documents::TYPES[$ty])) { $where[] = 'doc_type = ?'; $args[] = $ty; }
if (in_array($ms, ['UNMATCHED', 'MATCHED', 'REVIEW', 'EXCEPTION'], true)) { $where[] = 'match_status = ?'; $args[] = $ms; }
$rows = Db::all('SELECT * FROM documents WHERE ' . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT 300', $args);
$cnt = Db::one("SELECT COUNT(*) AS n, SUM(status='VERIFIED') AS ver, SUM(status<>'VERIFIED') AS todo, SUM(exc_high>0) AS exc FROM documents WHERE status <> 'CANCELLED'");
$badge = ['MATCHED' => 'ok', 'REVIEW' => 'warn', 'EXCEPTION' => 'bad', 'UNMATCHED' => ''];
$sbadge = ['SYNCED' => 'ok', 'FAILED' => 'bad', 'PENDING' => 'warn'];
page_head('Documents');
?>
<div class="grid g2">
  <div class="card"><h2>Upload an invoice or return document</h2>
    <form method="post" enctype="multipart/form-data" id="upform"><?= csrf_field() ?><input type="hidden" name="do" value="upload">
      <label>Tag this document as</label>
      <div class="tagpick">
        <label class="tag"><input type="radio" name="doc_type" value="PO_INVOICE" checked> <b>Purchase invoice</b><small>goods received against a PO</small></label>
        <label class="tag"><input type="radio" name="doc_type" value="MATERIAL_RETURN"> <b>Material return</b><small>goods sent back to the supplier</small></label>
      </div>
      <label id="ref_l">PO number (optional - read from the picture if left empty)</label><input name="ref" autocomplete="off">
      <label>Picture or PDF</label>
      <input type="file" name="file" accept="image/jpeg,image/png,application/pdf,image/*" required>
      <div class="hint">On a phone or tablet this opens the camera. JPEG, PNG or PDF, up to <?= (int)Settings::get('doc_max_mb', '10') ?> MB. Keep the page flat, well lit, and fill the frame.</div>
      <p style="display:flex;gap:14px;align-items:center;flex-wrap:wrap"><?php if (Ocr::enabled()): ?><label style="text-transform:none;font-weight:400"><input type="checkbox" name="read" value="1" checked> Read the details automatically (<?= e(Settings::get('ocr_provider')) ?>)</label><?php else: ?><span class="hint">OCR is off: you will type the details in.</span><?php endif; ?>
        <button id="upbtn">Upload</button></p>
      <div class="hint" id="upmsg"></div>
    </form>
  </div>
  <div class="card"><h2>Overview</h2>
    <div class="stats"><div><b><?= (int)$cnt['n'] ?></b><span>documents</span></div><div><b><?= (int)$cnt['todo'] ?></b><span>to verify</span></div><div><b><?= (int)$cnt['ver'] ?></b><span>verified</span></div><div class="<?= $cnt['exc'] ? 'redn' : '' ?>"><b><?= (int)$cnt['exc'] ?></b><span>with exceptions</span></div></div>
    <p><a class="btn" href="exceptions.php">Open exceptions</a> <a class="btn sec" href="po_master.php">Purchase orders</a></p>
    <p class="hint">Flow: upload &rarr; check the details against the picture &rarr; Verify &rarr; the system matches it with the weighbridge tickets and lists any differences &rarr; verified documents are sent to <?= e(['none' => 'no target (not configured)', 'oracle' => 'Oracle', 'sap' => 'SAP', 'both' => 'Oracle and SAP'][Settings::get('doc_targets', 'none')] ?? '') ?>.</p>
  </div>
</div>

<div class="card"><form method="get" class="row" style="align-items:end">
  <div><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="invoice / supplier / PO / vehicle / ticket"></div>
  <div><label>Type</label><select name="type"><option value="">All</option><?php foreach (Documents::TYPES as $k => $l) echo '<option value="' . $k . '"' . ($ty === $k ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></div>
  <div><label>Status</label><select name="status"><option value="">All</option><?php foreach (['UPLOADED', 'EXTRACTED', 'VERIFIED'] as $s) echo '<option' . ($st === $s ? ' selected' : '') . '>' . $s . '</option>'; ?></select></div>
  <div><label>Weighbridge match</label><select name="match"><option value="">All</option><?php foreach (['MATCHED', 'REVIEW', 'EXCEPTION', 'UNMATCHED'] as $s) echo '<option' . ($ms === $s ? ' selected' : '') . '>' . $s . '</option>'; ?></select></div>
  <div><button>Filter</button></div></form></div>

<div class="card"><table><tr><th>Document</th><th>Type</th><th>Invoice / challan</th><th>Supplier</th><th>Date</th><th class="n">Total</th><th>Weighbridge</th><th>Sent</th></tr>
<?php foreach ($rows as $d): ?>
  <tr><td><a href="document.php?id=<?= (int)$d['id'] ?>"><?= e($d['doc_no']) ?></a><div class="hint"><span class="badge <?= $d['status'] === 'VERIFIED' ? 'ok' : 'warn' ?>"><?= e($d['status']) ?></span></div></td>
    <td><span class="badge <?= $d['doc_type'] === 'MATERIAL_RETURN' ? 'plate' : '' ?>"><?= $d['doc_type'] === 'MATERIAL_RETURN' ? 'RETURN' : 'PO' ?></span><div class="hint"><?= e($d['po_no'] ?: $d['orig_invoice_no'] ?: $d['ref_no']) ?></div></td>
    <td><?= e($d['invoice_no']) ?></td><td><?= e($d['supplier']) ?></td><td><?= e($d['invoice_date']) ?></td>
    <td class="n"><?= $d['total_amount'] !== null ? number_format((float)$d['total_amount'], 2) : '' ?></td>
    <td><span class="badge <?= $badge[$d['match_status']] ?? '' ?>"><?= e($d['match_status']) ?></span>
      <?php if ($d['exc_high'] || $d['exc_warn']): ?> <span class="hint"><?= $d['exc_high'] ? '<b class="badt">' . (int)$d['exc_high'] . ' high</b> ' : '' ?><?= $d['exc_warn'] ? (int)$d['exc_warn'] . ' warn' : '' ?></span><?php endif; ?>
      <div class="hint"><?= e($d['wb_tickets']) ?></div></td>
    <td><?php foreach (['oracle' => 'ORA', 'sap' => 'SAP'] as $k => $lab) { $s = $d[$k . '_status']; if ($s !== 'NA') echo '<span class="badge ' . ($sbadge[$s] ?? '') . '" title="' . e((string)$d[$k . '_error']) . '">' . $lab . ' ' . e($s) . '</span> '; }
        if ($d['status'] === 'VERIFIED' && DocSync::held($d)) echo '<span class="badge bad">HELD</span>'; ?></td></tr>
<?php endforeach; if (!$rows): ?><tr><td colspan="8" class="hint">No documents yet. Upload the first one above.</td></tr><?php endif; ?></table></div>
<?php page_foot();
