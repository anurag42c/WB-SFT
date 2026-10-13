<?php
require __DIR__ . '/_layout.php';
if (is_installed()) { redirect('index.php'); }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    $u = trim($_POST['username'] ?? ''); $p = $_POST['password'] ?? '';
    if (!preg_match('/^[A-Za-z0-9_.-]{3,30}$/', $u)) { $err = 'Username: 3-30 letters/digits.'; }
    elseif (strlen($p) < 8) { $err = 'Password must be at least 8 characters.'; }
    else {
        Db::upgrade();
        Auth::createUser($u, $p, 'admin');
        Settings::set('company_name', trim($_POST['company'] ?? '') ?: 'My Weighbridge');
        Scales::save(1, 'Scale 1', true, ['conn_type' => 'simulator']);   // safe default: works without hardware until configured
        Settings::set('schema', '5');
        Settings::set('installed', '1');
        flash('Installed. Log in, then open Setup to configure your scale (currently in Simulator mode).');
        redirect('login.php');
    }
}
$dirOk = is_writable(WB_DATA);
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Install</title><link rel="stylesheet" href="assets/style.css"></head><body>
<main><div class="card center" style="max-width:460px"><h2>Weighbridge - First-time install <small style="font-weight:400;color:var(--mut)">v<?= e(WB_VERSION) ?></small></h2>
<?php if ($err) echo '<div class="flash err">' . e($err) . '</div>'; ?>
<table>
<tr><td>PHP version</td><td><?= PHP_VERSION ?> <?= version_compare(PHP_VERSION, '8.1', '>=') ? '&#10003;' : '&#10007; need 8.1+' ?></td></tr>
<tr><td>pdo_sqlite</td><td><?= extension_loaded('pdo_sqlite') ? '&#10003;' : '&#10007;' ?></td></tr>
<tr><td>mbstring</td><td><?= extension_loaded('mbstring') ? '&#10003;' : '&#10007; required' ?></td></tr>
<tr><td>curl (camera / plate recognition / HTTP gates)</td><td><?= function_exists('curl_init') ? '&#10003;' : '&#10007; enable php-curl for those features' ?></td></tr>
<tr><td>sodium</td><td><?= extension_loaded('sodium') ? '&#10003;' : '&#10007;' ?></td></tr>
<tr><td>data/ writable</td><td><?= $dirOk ? '&#10003;' : '&#10007; chmod/chown data/' ?></td></tr>
<tr><td>Oracle driver</td><td><?= OracleSync::available() ?: 'not installed (optional until you enable transfer)' ?></td></tr>
</table>
<form method="post"><?= csrf_field() ?>
<label>Company name</label><input name="company" value="<?= e($_POST['company'] ?? '') ?>">
<label>Admin username</label><input name="username" required value="<?= e($_POST['username'] ?? 'admin') ?>">
<label>Admin password (min 8)</label><input name="password" type="password" required>
<p><button <?= ($dirOk && extension_loaded('mbstring') && extension_loaded('pdo_sqlite') && extension_loaded('sodium')) ? '' : 'disabled' ?>>Install</button></p></form></div></main></body></html>
