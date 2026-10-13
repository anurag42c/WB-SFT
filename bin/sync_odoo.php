<?php
declare(strict_types=1);
/**
 * Odoo job: push completed tickets, and (if enabled in Setup) fetch purchase orders / masters.
 *   php bin/sync_odoo.php                one run (cron / Task Scheduler every minute)
 *   php bin/sync_odoo.php --loop=30      stay running, every 30 s (systemd / NSSM service)
 * Purchase orders and masters are fetched every 10th pass in loop mode (every run otherwise).
 */
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

Db::migrate(); Db::upgrade();
$loop = 0;
foreach ($argv as $a) { if (preg_match('/^--loop=(\d+)$/', $a, $m)) { $loop = max(5, (int)$m[1]); } }
$pass = 0;
do {
    Settings::reset();
    $r = OdooSync::syncPending();
    if ($r['ok'] || $r['failed'] || ($r['error'] && !str_contains((string)$r['error'], 'disabled'))) {
        echo sprintf("[%s] odoo tickets sent=%d failed=%d%s\n", date('c'), $r['ok'], $r['failed'], $r['error'] ? ' error=' . $r['error'] : '');
    }
    if ($pass++ % 10 === 0) {
        try {
            foreach (OdooSync::pullEnabled() as $what => $x) { echo sprintf("[%s] odoo pull %s: %s\n", date('c'), $what, json_encode($x)); }
        } catch (Throwable $e) { echo '[' . date('c') . '] odoo pull error=' . $e->getMessage() . "\n"; }
    }
    if ($loop) { sleep($loop); }
} while ($loop);
