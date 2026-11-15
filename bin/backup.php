<?php
declare(strict_types=1);
/**
 * Consistent online backup of the SQLite DB (safe while the app runs).
 *   php bin/backup.php [--dir=/path/to/backups] [--keep=30]
 * Schedule daily (cron / Task Scheduler). Copy app.key separately and keep it safe.
 */
require __DIR__ . '/../src/bootstrap.php';
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
$dir = WB_DATA . '/backups'; $keep = 30;
foreach ($argv as $a) {
    if (preg_match('/^--dir=(.+)$/', $a, $m)) { $dir = $m[1]; }
    if (preg_match('/^--keep=(\d+)$/', $a, $m)) { $keep = max(1, (int)$m[1]); }
}
if (!is_dir($dir) && !mkdir($dir, 0770, true)) { fwrite(STDERR, "Cannot create $dir\n"); exit(1); }
$file = $dir . '/weighbridge_' . date('Ymd_His') . '_' . substr(uniqid(), -4) . '.sqlite';
Db::pdo()->exec('VACUUM INTO ' . Db::pdo()->quote($file));
$chk = new PDO('sqlite:' . $file);
if ($chk->query('PRAGMA integrity_check')->fetchColumn() !== 'ok') { fwrite(STDERR, "Backup failed integrity check\n"); exit(2); }
$all = glob($dir . '/weighbridge_*.sqlite'); sort($all);
foreach (array_slice($all, 0, max(0, count($all) - $keep)) as $old) { unlink($old); }
echo "Backup OK: $file (" . number_format(filesize($file) / 1024) . " KB), keeping " . min($keep, count($all)) . "\n";
