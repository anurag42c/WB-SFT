<?php
require __DIR__ . '/_layout.php';
Auth::require();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    try {
        $ov = Auth::isAdmin() && !empty($_POST['override']);
        switch ($_POST['do'] ?? '') {
            case 'register':
                $r = GateEntries::register($_POST, (int)($_POST['gate_id'] ?? 0), !empty($_POST['open_gate']), $ov);
                $e = Db::one('SELECT entry_no FROM gate_entries WHERE id = ?', [$r['id']]);
                flash("Entry {$e['entry_no']} registered." . ($r['gate'] ? ' ' . $r['gate']['message'] : ''), ($r['gate'] && !$r['gate']['ok']) ? 'err' : 'ok');
                if (!empty($_POST['print'])) { redirect('gate_pass.php?id=' . $r['id']); }
                break;
            case 'exit':
                $r = GateEntries::exit((int)$_POST['id'], (int)($_POST['gate_id'] ?? 0), !empty($_POST['open_gate']), $ov);
                flash('Exit recorded.' . ($r['gate'] ? ' ' . $r['gate']['message'] : ''), ($r['gate'] && !$r['gate']['ok']) ? 'err' : 'ok');
                break;
            case 'cancel':
                Auth::require(true); GateEntries::cancel((int)$_POST['id'], trim($_POST['reason'] ?? '') ?: 'no reason'); flash('Entry cancelled.');
                break;
        }
    } catch (Throwable $e) { flash($e->getMessage(), 'err'); }
    redirect('gate.php');
}

$gates = Gates::all(true);
$entryGates = array_values(array_filter($gates, fn($g) => in_array($g['role'], ['ENTRY', 'BRIDGE_IN'], true)));
$exitGates = array_values(array_filter($gates, fn($g) => in_array($g['role'], ['EXIT', 'BRIDGE_OUT'], true)));
$inside = Db::all("SELECT e.*, (SELECT status FROM weighments w WHERE w.gate_entry_id = e.id ORDER BY w.id DESC LIMIT 1) AS tstatus,
                          (SELECT ticket_no FROM weighments w WHERE w.gate_entry_id = e.id ORDER BY w.id DESC LIMIT 1) AS tno
                   FROM gate_entries e WHERE e.status = 'INSIDE' ORDER BY e.id");
$recent = Db::all("SELECT * FROM gate_entries WHERE status <> 'INSIDE' ORDER BY id DESC LIMIT 8");
$events = Db::all('SELECT * FROM gate_events ORDER BY id DESC LIMIT 10');
$override = Auth::isAdmin() ? '<label style="text-transform:none;font-weight:400"><input type="checkbox" name="override" value="1"> Admin override</label>' : '';
page_head('Gate');
?>
<div data-gates data-csrf="<?= e(Auth::csrf()) ?>"></div>
<div class="scales">
<?php foreach ($gates as $g): $c = Gates::config((int)$g['id']); $cap = Gates::capabilities($c); ?>
  <div class="gate-card" data-id="<?= (int)$g['id'] ?>">
    <div class="nm"><span><?= e($g['name']) ?></span><span class="badge" data-gstate>...</span></div>
    <div class="hint"><?= e(Gates::ROLES[$g['role']] ?? '') ?> &middot; <?= e($c['driver']) ?><?= (int)$c['auto_close_sec'] > 0 ? ' &middot; auto-close ' . (int)$c['auto_close_sec'] . ' s' : '' ?></div>
    <div class="gbtns"><button class="ok" data-gcmd="open">&#9650; OPEN</button><button class="bad" data-gcmd="close" <?= $cap['close'] ? '' : 'disabled title="No close command configured"' ?>>&#9660; CLOSE</button></div>
    <div class="hint" data-glast></div>
  </div>
<?php endforeach; if (!$gates): ?><div class="flash err">No enabled gate. Add one in Setup &gt; Gates<?= Auth::isAdmin() ? '' : ' (ask an admin)' ?>.</div><?php endif; ?>
</div>
<p class="hint" id="gmsg" style="min-height:1.4em"></p>

<div class="grid g2">
  <div class="card"><h2>Gate entry &mdash; register vehicle</h2>
    <form method="post" autocomplete="off"><?= csrf_field() ?><input type="hidden" name="do" value="register">
      <div class="row"><div><label>Gate</label><select name="gate_id" id="entry_gate"><?php foreach ($entryGates as $g) echo '<option value="' . (int)$g['id'] . '">' . e($g['name']) . '</option>'; ?><option value="0">(no gate - register only)</option></select></div>
        <div><label>Purpose</label><select name="purpose"><?php foreach (GateEntries::PURPOSES as $k => $l) echo '<option value="' . $k . '">' . e($l) . '</option>'; ?></select></div></div>
      <label>Vehicle no *</label>
      <div style="display:flex;gap:6px"><input name="vehicle_no" id="g_vehicle" list="dl_veh" style="text-transform:uppercase"><?php if (Anpr::enabled()): ?><button type="button" id="g_anpr" class="sec">&#128247; Read</button><?php endif; ?></div>
      <div class="hint" id="g_note"></div>
      <div class="row"><div><label>Driver</label><input name="driver"></div><div><label>Driver phone</label><input name="driver_phone" inputmode="tel"></div></div>
      <div class="row"><div><label>Party</label><input name="party" list="dl_party"></div><div><label>Material</label><input name="material" list="dl_mat"></div></div>
      <div class="row"><div><label>Challan / PO no</label><input name="challan_no"></div><div><label>Remarks</label><input name="remarks"></div></div>
      <p style="display:flex;gap:14px;align-items:center;flex-wrap:wrap"><label style="text-transform:none;font-weight:400"><input type="checkbox" name="open_gate" value="1" checked> Open the gate</label>
        <label style="text-transform:none;font-weight:400"><input type="checkbox" name="print" value="1"> Print gate pass</label> <?= $override ?></p>
      <p><button>Register &amp; admit</button></p>
    </form>
  </div>

  <div>
    <div class="card"><h2>Vehicles inside (<?= count($inside) ?>)</h2>
      <?php if (!$inside): ?><p class="hint">None.</p><?php else: ?>
      <table><tr><th>Entry</th><th>Vehicle</th><th>In</th><th>Weighing</th><th></th></tr>
      <?php foreach ($inside as $e): ?>
        <tr><td><a href="gate_pass.php?id=<?= (int)$e['id'] ?>"><?= e($e['entry_no']) ?></a><div class="hint"><?= e($e['party']) ?> <?= e($e['material']) ?></div></td>
          <td><?= e($e['vehicle_no']) ?><?php if ($e['plate_flag'] === 'MISMATCH'): ?> <span class="badge bad">PLATE</span><?php endif; ?></td>
          <td><?= e(substr($e['in_at'], 11, 5)) ?></td>
          <td><?= $e['tstatus'] === 'CLOSED' ? '<span class="badge ok">done</span>' : ($e['tstatus'] === 'OPEN' ? '<span class="badge warn">1st done</span>' : '<span class="badge">not yet</span>') ?></td>
          <td><form method="post" class="exitform"><?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$e['id'] ?>"><input type="hidden" name="open_gate" value="1">
            <select name="gate_id"><?php foreach ($exitGates as $g) echo '<option value="' . (int)$g['id'] . '">' . e($g['name']) . '</option>'; ?><option value="0">(no gate)</option></select>
            <?= $override ?><div style="display:flex;gap:6px"><button name="do" value="exit">Exit &amp; open</button>
            <?php if (Auth::isAdmin()): ?><button name="do" value="cancel" class="sec" onclick="var r=prompt('Cancel reason?');if(!r)return false;var i=document.createElement('input');i.type='hidden';i.name='reason';i.value=r;this.form.appendChild(i)">Cancel</button><?php endif; ?></div></form></td></tr>
      <?php endforeach; ?></table><?php endif; ?>
    </div>
    <div class="card"><h2>Recent exits</h2><table><tr><th>Entry</th><th>Vehicle</th><th>In</th><th>Out</th><th>Plate</th></tr>
      <?php foreach ($recent as $e): ?><tr><td><a href="gate_pass.php?id=<?= (int)$e['id'] ?>"><?= e($e['entry_no']) ?></a></td><td><?= e($e['vehicle_no']) ?><?= $e['status'] === 'CANCELLED' ? ' <s>cancelled</s>' : '' ?></td>
        <td><?= e(substr((string)$e['in_at'], 5, 11)) ?></td><td><?= e(substr((string)$e['out_at'], 5, 11)) ?></td>
        <td><?php if ($e['plate_flag']): ?><span class="badge <?= ['OK' => 'ok', 'MISMATCH' => 'bad', 'UNREAD' => 'warn'][$e['plate_flag']] ?? '' ?>"><?= e($e['plate_flag']) ?></span><?php endif; ?></td></tr><?php endforeach; ?></table></div>
    <div class="card"><h2>Gate activity</h2><table><tr><th>Time</th><th>Gate</th><th>Action</th><th>By / source</th><th></th></tr>
      <?php foreach ($events as $ev): ?><tr><td><?= e(substr($ev['at'], 11)) ?></td><td><?= e($ev['gate_name']) ?></td><td><?= e($ev['action']) ?></td><td><?= e($ev['user']) ?> / <?= e($ev['source']) ?></td>
        <td><span class="badge <?= $ev['ok'] ? 'ok' : 'bad' ?>" title="<?= e($ev['message']) ?>"><?= $ev['ok'] ? 'OK' : 'FAIL' ?></span></td></tr><?php endforeach; ?></table></div>
  </div>
</div>
<datalist id="dl_veh"><?php foreach (Db::all('SELECT reg_no FROM vehicles WHERE active=1 AND blocked=0 ORDER BY reg_no') as $r) echo '<option value="' . e($r['reg_no']) . '">'; ?></datalist>
<datalist id="dl_party"><?php foreach (Db::all('SELECT name FROM parties WHERE active=1 ORDER BY name') as $r) echo '<option value="' . e($r['name']) . '">'; ?></datalist>
<datalist id="dl_mat"><?php foreach (Db::all('SELECT name FROM materials WHERE active=1 ORDER BY name') as $r) echo '<option value="' . e($r['name']) . '">'; ?></datalist>
<?php page_foot();
