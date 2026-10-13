<?php
require __DIR__ . '/_layout.php';
Auth::require();
$types = ['vehicles' => ['Vehicles', 'reg_no'], 'parties' => ['Parties', 'name'], 'materials' => ['Materials', 'name']];
$t = $_GET['t'] ?? 'vehicles'; if (!isset($types[$t])) { $t = 'vehicles'; }
[$label, $col] = $types[$t];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    try {
        if (isset($_POST['add'])) {
            $name = trim($_POST['name']); if ($t === 'vehicles') { $name = strtoupper(preg_replace('/\s+/', '', $name)); }
            if ($name === '') { throw new RuntimeException('Name required'); }
            if ($t === 'vehicles') { Db::q('INSERT INTO vehicles(reg_no, tare_kg, transporter) VALUES (?,?,?)', [$name, ($_POST['tare_kg'] ?? '') !== '' ? (float)$_POST['tare_kg'] : null, trim($_POST['transporter'] ?? '')]); }
            else { Db::q("INSERT INTO $t(name) VALUES (?)", [$name]); }
            flash('Added.');
        } elseif (isset($_POST['block']) && $t === 'vehicles') {
            Auth::require(true);
            $v = Db::one('SELECT blocked FROM vehicles WHERE id = ?', [(int)$_POST['block']]);
            if ($v && !(int)$v['blocked']) { Db::q('UPDATE vehicles SET blocked = 1, block_reason = ? WHERE id = ?', [trim($_POST['reason'] ?? '') ?: 'blocked', (int)$_POST['block']]); Db::audit('vehicle_block', (string)(int)$_POST['block']); flash('Vehicle blocked: the gate will refuse it.'); }
            else { Db::q('UPDATE vehicles SET blocked = 0, block_reason = NULL WHERE id = ?', [(int)$_POST['block']]); Db::audit('vehicle_unblock', (string)(int)$_POST['block']); flash('Vehicle unblocked.'); }
        } elseif (isset($_POST['del'])) {
            Auth::require(true);
            Db::q("DELETE FROM $t WHERE id = ?", [(int)$_POST['del']]); flash('Deleted.');
        }
    } catch (Throwable $e) { flash(str_contains($e->getMessage(), 'UNIQUE') ? 'Already exists.' : $e->getMessage(), 'err'); }
    redirect("masters.php?t=$t");
}
page_head('Masters');
echo '<div class="tabs">'; foreach ($types as $k => [$l]) { echo '<a href="?t=' . $k . '"' . ($k === $t ? ' class="on"' : '') . '>' . $l . '</a>'; } echo '</div>';
?>
<div class="card"><h2>Add <?= e($label) ?></h2>
<form method="post" class="row" style="align-items:end"><?= csrf_field() ?>
  <div><label><?= $t === 'vehicles' ? 'Registration no' : 'Name' ?></label><input name="name" required></div>
  <?php if ($t === 'vehicles'): ?><div><label>Stored tare kg (optional)</label><input name="tare_kg" type="number" step="0.01"></div><div><label>Transporter</label><input name="transporter"></div><?php endif; ?>
  <div><button name="add" value="1">Add</button></div></form></div>
<div class="card"><table><tr><th><?= $col === 'reg_no' ? 'Vehicle' : 'Name' ?></th><?php if ($t === 'vehicles') echo '<th class="n">Tare kg</th><th>Transporter</th><th>Gate</th>'; ?><th></th></tr>
<?php foreach (Db::all("SELECT * FROM $t ORDER BY $col") as $r): ?>
  <tr><td><?= e($r[$col]) ?></td><?php if ($t === 'vehicles') echo '<td class="n">' . ($r['tare_kg'] !== null ? number_format((float)$r['tare_kg']) : '-') . '</td><td>' . e($r['transporter']) . '</td><td>' . ((int)$r['blocked'] ? '<span class="badge bad" title="' . e((string)$r['block_reason']) . '">BLOCKED</span>' : '') . '</td>'; ?>
  <td><?php if (Auth::isAdmin() && $t === 'vehicles'): ?><form method="post" style="display:inline"><?= csrf_field() ?><button name="block" value="<?= (int)$r['id'] ?>" class="sec" onclick="<?= (int)$r['blocked'] ? '' : 'var r=prompt(\'Reason for blocking?\');if(r===null)return false;var i=document.createElement(\'input\');i.type=\'hidden\';i.name=\'reason\';i.value=r;this.form.appendChild(i)' ?>"><?= (int)$r['blocked'] ? 'Unblock' : 'Block' ?></button></form> <?php endif; ?><?php if (Auth::isAdmin()): ?><form method="post" style="display:inline" onsubmit="return confirm('Delete?')"><?= csrf_field() ?><button name="del" value="<?= (int)$r['id'] ?>" class="sec">Delete</button></form><?php endif; ?></td></tr>
<?php endforeach; ?></table></div>
<?php page_foot();
