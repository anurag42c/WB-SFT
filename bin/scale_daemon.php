<?php
declare(strict_types=1);
/**
 * Reader for ONE scale. Keeps its serial/TCP connection open, decodes weights and publishes the latest
 * reading into SQLite (live_scale). Normally started for you by bin/scale_supervisor.php.
 *
 *   php bin/scale_daemon.php --scale=2 [--debug]
 */
require __DIR__ . '/../src/bootstrap.php';

if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
$debug = in_array('--debug', $argv, true);
$sid = 1;
foreach ($argv as $a) { if (preg_match('/^--scale=(\d+)$/', $a, $m)) { $sid = (int)$m[1]; } }
$running = true;
if (function_exists('pcntl_async_signals')) {
    pcntl_async_signals(true);
    foreach ([SIGTERM, SIGINT] as $s) { pcntl_signal($s, function () use (&$running) { $running = false; }); }
}

function publish(int $sid, array $f): void
{
    Db::q('INSERT OR IGNORE INTO live_scale(scale_id, status) VALUES (?, ?)', [$sid, 'STARTING']);
    $sets = []; $args = [];
    foreach ($f as $k => $v) { $sets[] = "$k = ?"; $args[] = $v; }
    $sets[] = 'heartbeat = ?'; $args[] = microtime(true); $args[] = $sid;
    Db::q('UPDATE live_scale SET ' . implode(', ', $sets) . ' WHERE scale_id = ?', $args);
}

Db::upgrade();
$tag = "[scale $sid]";
$reader = null; $cfgHash = ''; $lastCfgCheck = 0.0; $errUntil = 0.0; $cfg = null;
echo "[" . date('c') . "] $tag reader started\n";

while ($running) {
    try {
        if (microtime(true) - $lastCfgCheck > 5) {           // hot-reload settings saved from the Setup page
            $lastCfgCheck = microtime(true);
            Settings::reset();
            $s = Scales::find($sid);
            if (!$s || !(int)$s['enabled']) { echo "[" . date('c') . "] $tag disabled or deleted - exiting\n"; break; }
            $cfg = Scales::config($sid);
            $h = md5(json_encode($cfg));
            if ($h !== $cfgHash) {
                $reader?->close(); $reader = new ScaleReader($cfg); $cfgHash = $h;
                echo "[" . date('c') . "] $tag {$cfg['scale_name']}: {$cfg['conn_type']} " . ($cfg['conn_type'] === 'serial' ? "{$cfg['serial_port']} {$cfg['baud']}-{$cfg['data_bits']}{$cfg['parity']}{$cfg['stop_bits']} {$cfg['protocol']}" : ($cfg['conn_type'] === 'tcp' ? "{$cfg['tcp_host']}:{$cfg['tcp_port']}" : '')) . "\n";
                publish($sid, ['status' => 'CONNECTING', 'message' => 'Opening connection']);
            }
        }
        if (microtime(true) < $errUntil) { publish($sid, ['status' => 'ERROR']); usleep(500000); continue; }

        $r = $reader->poll();
        if ($r) {
            publish($sid, ['weight' => $r['weight'], 'unit' => 'kg', 'stable' => $r['stable'] ? 1 : 0, 'raw' => $r['raw'],
                           'status' => 'RUNNING', 'message' => null, 'updated_at' => microtime(true)]);
            if ($debug) { echo sprintf("%s %s %10.2f kg  %s  | %s\n", date('H:i:s'), $tag, $r['weight'], $r['stable'] ? 'STABLE ' : 'motion ', $r['raw']); }
        } else {
            publish($sid, []);   // heartbeat only; updated_at stays old so the UI shows "no data"
        }
    } catch (Throwable $e) {
        $reader?->close();
        $reader = $cfg ? new ScaleReader($cfg) : null;
        if (!$reader) { $lastCfgCheck = 0.0; }
        $errUntil = microtime(true) + 3;
        publish($sid, ['status' => 'ERROR', 'message' => mb_substr($e->getMessage(), 0, 300)]);
        echo "[" . date('c') . "] $tag ERROR: " . $e->getMessage() . "\n";
    }
}
$reader?->close();
publish($sid, ['status' => 'STOPPED', 'message' => 'Reader stopped']);
echo "[" . date('c') . "] $tag reader stopped\n";
