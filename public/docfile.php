<?php
require __DIR__ . '/_layout.php';
Auth::require();
$d = Documents::get((int)($_GET['id'] ?? 0));
$p = $d ? Documents::path($d) : null;
if (!$p) { http_response_code(404); exit('Not found'); }
session_write_close();
header('Content-Type: ' . $d['mime']); header('X-Content-Type-Options: nosniff'); header('Cache-Control: private, max-age=3600');
header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $d['doc_no']) . '.' . pathinfo($p, PATHINFO_EXTENSION) . '"');
readfile($p);
