<?php
declare(strict_types=1);
/** Push pending tickets to Oracle. Run from cron / Task Scheduler every minute, or loop with --loop=30 */
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

Db::migrate();
$loop = 0;
foreach ($argv as $a) { if (preg_match('/^--loop=(\d+)$/', $a, $m)) { $loop = max(5, (int)$m[1]); } }
do {
    Settings::reset();
    $r = OracleSync::syncPending();
    echo sprintf("[%s] tickets synced=%d failed=%d%s\n", date('c'), $r['ok'], $r['failed'], $r['error'] ? ' error=' . $r['error'] : '');
    try {
        $d = DocSync::syncPending();
        if ($d['sent'] || $d['failed'] || $d['held']) { echo sprintf("[%s] documents sent=%d failed=%d held=%d%s\n", date('c'), $d['sent'], $d['failed'], $d['held'], $d['error'] ? ' error=' . $d['error'] : ''); }
    } catch (Throwable $e) { echo '[' . date('c') . '] documents error=' . $e->getMessage() . "\n"; }
    if ($loop) { sleep($loop); }
} while ($loop);
