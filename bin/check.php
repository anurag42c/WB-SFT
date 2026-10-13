<?php
declare(strict_types=1);
/**
 * Pre-flight check: run after copying the program to a PC, and any time something "does not work".
 *   php bin/check.php
 * Exit code 0 = ready, 1 = a required item is missing.
 */
define('WB_CHECK', true);
if (PHP_SAPI !== 'cli') { exit("CLI only\n"); }
$root = dirname(__DIR__);
$bad = 0; $warn = 0;
function line(string $s, string $name, string $msg): void { echo str_pad("[$s]", 7) . str_pad($name, 34) . $msg . "\n"; }
function ok(string $n, string $m = ''): void { line('OK', $n, $m); }
function warn(string $n, string $m): void { global $warn; $warn++; line('WARN', $n, $m); }
function fail(string $n, string $m): void { global $bad; $bad++; line('FAIL', $n, $m); }

echo "Weighbridge pre-flight check (v" . trim((string)@file_get_contents("$root/VERSION")) . ")\n\n";

version_compare(PHP_VERSION, '8.1.0', '>=') ? ok('PHP version', PHP_VERSION) : fail('PHP version', PHP_VERSION . ' - need 8.1 or newer');
foreach (['pdo_sqlite' => 'database', 'sodium' => 'password/secret encryption', 'mbstring' => 'text handling'] as $e => $why) {
    extension_loaded($e) ? ok("extension $e") : fail("extension $e", "missing ($why) - enable it in php.ini");
}
function_exists('curl_init') ? ok('extension curl') : warn('extension curl', 'missing - needed for camera snapshots, plate recognition and HTTP gate relays');
$bytes = function (string $v): int { $v = trim($v); $n = (int)$v; return match (strtolower(substr($v, -1))) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n }; };
if ($bytes((string)ini_get('upload_max_filesize')) < 8 * 1048576 || $bytes((string)ini_get('post_max_size')) < 10 * 1048576) {
    warn('php.ini upload limits', 'upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size') . ' - phone photos of invoices need at least 10M / 12M (set both in php.ini)');
} else { ok('php.ini upload limits', 'upload_max_filesize=' . ini_get('upload_max_filesize') . ', post_max_size=' . ini_get('post_max_size')); }
extension_loaded('gd') ? ok('extension gd', 'invoice pictures are rotated / resized before reading') : warn('extension gd', 'missing - invoice pictures are used as they are (enable php-gd for best OCR results)');
function_exists('exif_read_data') ? ok('extension exif') : warn('extension exif', 'missing - phone photos taken sideways are not turned upright automatically');
$tess = trim((string)@shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where tesseract 2>NUL' : 'command -v tesseract 2>/dev/null'));
$tess !== '' ? ok('Tesseract OCR', strtok($tess, "\r\n")) : warn('Tesseract OCR', 'not installed - only needed for offline invoice reading (the Claude provider does not need it)');
$oci = function_exists('oci_connect') ? 'oci8' : (extension_loaded('pdo_oci') ? 'pdo_oci' : '');
$oci !== '' ? ok('Oracle driver', $oci) : warn('Oracle driver', 'not installed - only needed for the Oracle transfer (README step 6)');

$dis = array_filter(array_map('trim', explode(',', (string)ini_get('disable_functions'))));
$off = array_values(array_intersect(['exec', 'proc_open', 'stream_socket_client', 'fopen'], $dis));
$off ? fail('php.ini functions', 'disabled in disable_functions: ' . implode(', ', $off) . ' (serial port / supervisor need them)') : ok('php.ini functions', 'exec / proc_open available');

$data = getenv('WB_DATA') ?: "$root/data";
if (!is_dir($data)) { @mkdir($data, 0775, true); }
is_dir($data) && is_writable($data) ? ok('data folder writable', $data) : fail('data folder writable', "$data - create it and give the service user write access");
$free = @disk_free_space($data);
$free !== false && $free < 500 * 1024 * 1024 ? warn('free disk space', number_format($free / 1048576) . ' MB (low)') : ok('free disk space', $free !== false ? number_format($free / 1073741824, 1) . ' GB' : 'unknown');
ok('timezone', (getenv('WB_TZ') ?: 'Asia/Kolkata') . ' (set env WB_TZ to change)');

if (PHP_OS_FAMILY === 'Windows') {
    ok('serial ports', 'COM1..COM20 (check Device Manager > Ports for the number)');
} else {
    $ports = [];
    foreach (['/dev/ttyUSB*', '/dev/ttyACM*', '/dev/ttyS[0-9]*', '/dev/serial/by-id/*'] as $g) { $ports = array_merge($ports, glob($g) ?: []); }
    $ports ? ok('serial ports', implode(', ', array_slice($ports, 0, 8))) : warn('serial ports', 'none detected (plug in the USB-serial adapter, or use TCP/simulator)');
    if (function_exists('posix_getgroups') && $ports) {
        $names = array_map(fn($g) => posix_getgrgid($g)['name'] ?? '', posix_getgroups());
        in_array('dialout', $names, true) || posix_geteuid() === 0 ? ok('serial permission', 'user may open serial ports') : warn('serial permission', "add this user to 'dialout':  sudo usermod -aG dialout \$USER  (then log in again)");
    }
    $r = shell_exec('command -v stty 2>/dev/null'); trim((string)$r) !== '' ? ok('stty tool') : fail('stty tool', 'not found (install coreutils) - needed to set baud/parity');
}

if (!$bad && extension_loaded('pdo_sqlite')) {
    try {
        putenv("WB_DATA=$data");
        require_once "$root/src/bootstrap.php";
        Db::upgrade();
        $n = (int)Db::val('SELECT COUNT(*) FROM scales'); ok('database', 'opens and upgrades; ' . $n . ' scale(s), ' . count(Gates::all()) . ' gate(s) configured');
        echo "\n";
        foreach (Scales::all() as $s) {
            $c = Scales::config((int)$s['id']);
            $l = Weighment::live((int)$s['id']);
            line($s['enabled'] ? ($l['daemon_alive'] ? 'OK' : 'WARN') : 'off', 'scale ' . $s['id'] . ' ' . $s['name'], $c['conn_type'] . ($c['conn_type'] === 'serial' ? ' ' . $c['serial_port'] : '') . ' - ' . (!$s['enabled'] ? 'disabled' : ($l['daemon_alive'] ? 'reader running, ' . $l['status'] : 'reader NOT running (start bin/scale_supervisor.php)')));
        }
    } catch (Throwable $e) { fail('database', $e->getMessage()); }
}

echo "\n" . ($bad ? "$bad problem(s) must be fixed before running." : ($warn ? "Ready, with $warn warning(s) above." : 'All good.')) . "\n";
exit($bad ? 1 : 0);
