<?php
require __DIR__ . '/_layout.php';
Auth::require();
$f = basename((string)($_GET['f'] ?? ''));
$p = Camera::dir() . '/' . $f;
if (!preg_match('/^[A-Za-z0-9_-]+\.jpg$/', $f) || !is_file($p)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg'); header('Cache-Control: private, max-age=86400');
readfile($p);
