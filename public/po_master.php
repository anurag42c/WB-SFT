<?php
require __DIR__ . '/_layout.php';
Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    try {
        if (($_POST['do'] ?? '') === 'import') {
            $f = $_FILES['file'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) { throw new RuntimeException('Choose a CSV file first.'); }
            $r = PurchaseOrders::importCsv($f['tmp_name']);
            flash("Imported {$r['lines']} line(s) for {$r['pos']} PO(s)" . ($r['skipped'] ? ", skipped {$r['skipped']} row(s) without a PO number or quantity." : '.'));
        } elseif (($_POST['do'] ?? '') === 'delete') { Auth::require(true); PurchaseOrders::delete((string)$_POST['po']); flash('PO removed from the list.'); }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('po_master.php');
}
$q = trim($_GET['q'] ?? '');
$rows = Db::all('SELECT po_no, MIN(vendor) AS vendor, COUNT(*) AS n, MIN(source) AS source, MIN(po_date) AS po_date FROM po_lines WHERE po_no LIKE ? OR vendor LIKE ? GROUP BY po_no ORDER BY MAX(id) DESC LIMIT 300', ["%$q%", "%$q%"]);
$show = trim($_GET['po'] ?? '');
page_head('Purchase orders');
?>
<div class="grid g2">
<div class="card"><h2>Import purchase orders (CSV)</h2>
  <form method="post" enctype="multipart/form-data"><?= csrf_field() ?><input type="hidden" name="do" value="import">
  <input type="file" name="file" accept=".csv,.txt,text/csv" required>
  <div class="hint">One row per PO line, first row = column titles. Recognised titles: <code>po_no, line_no, vendor, material, description, qty, uom, rate, po_date</code> (SAP field names such as EBELN, EBELP, MATNR, TXZ01, MENGE, MEINS, NETPR also work). Re-importing a PO replaces its lines.</div>
  <p><button>Import</button></p></form></div>
<div class="card"><h2>How it is used</h2><p class="hint">When a purchase invoice is verified, its PO number is looked up here<?= trim((string)Settings::get('sap_po_url')) !== '' ? ' (and in SAP if it is not in this list)' : '' ?>. The system checks that the PO exists, that the supplier is the PO vendor, that the invoiced items are on the PO, and that the total invoiced quantity does not exceed the ordered quantity. If no PO list is loaded the check is skipped.</p>
  <p><b><?= PurchaseOrders::count() ?></b> PO(s) loaded.</p></div>
</div>
<div class="card"><form method="get" class="row" style="align-items:end"><div><label>Search</label><input name="q" value="<?= e($q) ?>" placeholder="PO number or vendor"></div><div><button>Search</button></div></form></div>
<div class="card"><table><tr><th>PO</th><th>Vendor</th><th class="n">Lines</th><th>Date</th><th>Source</th><th></th></tr>
<?php foreach ($rows as $r): ?><tr><td><a href="?po=<?= urlencode($r['po_no']) ?>"><?= e($r['po_no']) ?></a></td><td><?= e($r['vendor']) ?></td><td class="n"><?= (int)$r['n'] ?></td><td><?= e($r['po_date']) ?></td><td><?= e($r['source']) ?></td>
  <td><?php if (Auth::isAdmin()): ?><form method="post" onsubmit="return confirm('Remove this PO from the list?')"><?= csrf_field() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="po" value="<?= e($r['po_no']) ?>"><button class="sec">Remove</button></form><?php endif; ?></td></tr>
  <?php if ($show !== '' && $show === $r['po_no']): ?><tr><td colspan="6"><table><tr><th>Line</th><th>Material</th><th>Description</th><th class="n">Qty</th><th>Unit</th><th class="n">Rate</th></tr>
    <?php foreach (PurchaseOrders::lines($show) as $l): ?><tr><td><?= e($l['line_no']) ?></td><td><?= e($l['material_code']) ?></td><td><?= e($l['description']) ?></td><td class="n"><?= number_format((float)$l['qty'], 3) ?></td><td><?= e($l['uom']) ?></td><td class="n"><?= $l['rate'] !== null ? number_format((float)$l['rate'], 2) : '' ?></td></tr><?php endforeach; ?></table></td></tr><?php endif; ?>
<?php endforeach; if (!$rows): ?><tr><td colspan="6" class="hint">No purchase orders loaded.</td></tr><?php endif; ?></table></div>
<?php page_foot();
