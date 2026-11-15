<?php
declare(strict_types=1);
/**
 * Starts one bin/scale_daemon.php per ENABLED scale, restarts any that die, stops readers for scales that were
 * disabled or deleted, and picks up scales added in the Setup page - no service changes needed.
 * This is the one process you run as the service (systemd / NSSM).
 *
 *   php bin/scale_supervisor.php
 */
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }

$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $s) { pcntl_signal($s, function () use (&$running) { $running = false; }); }
}
Db::upgrade();
$logDir = WB_DATA . '/logs'; if (!is_dir($logDir)) { mkdir($logDir, 0770, true); }
$children = [];   // scale id => ['proc' => resource, 'started' => float]
$retryAt = [];

function log_line(string $m): void { echo '[' . date('c') . "] $m\n"; }

function start_child(int $id, string $logDir): mixed
{
    $log = "$logDir/scale_$id.log";
    if (is_file($log) && filesize($log) > 5 * 1024 * 1024) { @rename($log, "$log.1"); }   // simple size-based rotation
    $out = fopen($log, 'ab');
    $p = proc_open([PHP_BINARY, __DIR__ . '/scale_daemon.php', "--scale=$id"], [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => $out, 2 => $out], $pipes, dirname(__DIR__), ['WB_DATA' => WB_DATA] + getenv());
    return is_resource($p) ? $p : null;
}

log_line('supervisor started, data=' . WB_DATA);
while ($running) {
    Settings::reset();
    $want = array_map(fn($r) => (int)$r['id'], Scales::all(true));

    foreach ($children as $id => $c) {                       // reap dead / unwanted
        $alive = proc_get_status($c['proc'])['running'];
        if (!in_array($id, $want, true)) {
            if ($alive) { proc_terminate($c['proc']); }
            proc_close($c['proc']); unset($children[$id]); log_line("scale $id stopped (disabled/deleted)");
        } elseif (!$alive) {
            proc_close($c['proc']); unset($children[$id]); $retryAt[$id] = microtime(true) + 3;
            log_line("scale $id reader exited - restarting in 3 s");
        }
    }
    foreach ($want as $id) {                                 // start missing
        if (isset($children[$id]) || microtime(true) < ($retryAt[$id] ?? 0)) { continue; }
        $p = start_child($id, $logDir);
        if ($p) { $children[$id] = ['proc' => $p, 'started' => microtime(true)]; log_line("scale $id reader started"); }
        else { $retryAt[$id] = microtime(true) + 10; log_line("scale $id: cannot start reader"); }
    }
    for ($i = 0; $i < 20 && $running; $i++) {
        usleep(100000);
        if ($i % 5 === 4) { try { Gates::runDue(); } catch (Throwable $e) { log_line('gate auto-close error: ' . $e->getMessage()); } }   // every 0.5 s
    }
}
log_line('supervisor stopping');
foreach ($children as $c) { proc_terminate($c['proc']); }
foreach ($children as $c) { proc_close($c['proc']); }
