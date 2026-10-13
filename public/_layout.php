<?php
declare(strict_types=1);
require_once __DIR__ . '/../src/bootstrap.php';
Auth::start();
header('X-Frame-Options: SAMEORIGIN'); header('X-Content-Type-Options: nosniff'); header('Referrer-Policy: same-origin');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; frame-ancestors 'self'");
if (is_installed() && Settings::get('schema') !== '6') { Db::upgrade(); Settings::set('schema', '6'); }
if (!is_installed() && basename($_SERVER['SCRIPT_NAME']) !== 'install.php') { redirect('install.php'); }

function page_head(string $title, bool $admin = false): void
{
    $u = Auth::user(); $cur = basename($_SERVER['SCRIPT_NAME']);
    $nav = ['index.php' => 'Weighing', 'gate.php' => 'Gate', 'documents.php' => 'Documents', 'exceptions.php' => 'Exceptions', 'reports.php' => 'Reports', 'masters.php' => 'Masters'];
    $hi = $u ? (int)Db::val("SELECT COUNT(*) FROM doc_exceptions e LEFT JOIN documents d ON d.id = e.doc_id WHERE e.status = 'OPEN' AND e.severity = 'HIGH' AND (e.doc_id IS NULL OR d.status <> 'CANCELLED')") : 0;
    if (Auth::isAdmin()) { $nav['setup.php'] = 'Setup'; }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . e($title) . ' - ' . e(Settings::get('company_name')) . '</title><link rel="stylesheet" href="assets/style.css"></head><body>';
    echo '<header><div class="brand">&#9878; ' . e(Settings::get('company_name')) . '</div><nav>';
    foreach ($nav as $f => $l) { echo '<a href="' . $f . '"' . (($cur === $f || ($f === 'documents.php' && in_array($cur, ['document.php', 'po_master.php'], true))) ? ' class="on"' : '') . '>' . $l . ($f === 'exceptions.php' && $hi ? ' <span class="navbadge">' . $hi . '</span>' : '') . '</a>'; }
    echo '</nav><div class="who">' . ($u ? e($u['username']) . ' (' . e($u['role']) . ') &middot; <a href="logout.php">Logout</a>' : '') . '</div></header><main>';
    if ($f = flash()) { echo '<div class="flash ' . e($f[1]) . '">' . e($f[0]) . '</div>'; }
}

function page_foot(): void { echo '</main><script src="assets/app.js"></script></body></html>'; }

function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . e(Auth::csrf()) . '">'; }
