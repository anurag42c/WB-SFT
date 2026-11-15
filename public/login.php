<?php
require __DIR__ . '/_layout.php';
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    Auth::checkCsrf();
    try {
        if (Auth::login(trim($_POST['username'] ?? ''), $_POST['password'] ?? '')) { redirect('index.php'); }
        $err = 'Invalid username or password.'; usleep(400000);
    } catch (RuntimeException $e) { $err = $e->getMessage(); }
}
?><!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Login</title><link rel="stylesheet" href="assets/style.css"></head><body>
<main><div class="card center"><h2>&#9878; <?= e(Settings::get('company_name')) ?></h2>
<?php if ($f = flash()) echo '<div class="flash ' . e($f[1]) . '">' . e($f[0]) . '</div>'; if ($err) echo '<div class="flash err">' . e($err) . '</div>'; ?>
<form method="post"><?= csrf_field() ?><label>Username</label><input name="username" autofocus required>
<label>Password</label><input name="password" type="password" required><p><button>Login</button></p></form></div></main></body></html>
