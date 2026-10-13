<?php
declare(strict_types=1);

define('WB_ROOT', dirname(__DIR__));
define('WB_DATA', rtrim(getenv('WB_DATA') ?: WB_ROOT . '/data', '/\\'));
define('WB_DB', WB_DATA . '/weighbridge.sqlite');

define('WB_VERSION', is_file(WB_ROOT . '/VERSION') ? trim((string)file_get_contents(WB_ROOT . '/VERSION')) : 'dev');
date_default_timezone_set(getenv('WB_TZ') ?: 'Asia/Kolkata');

foreach (['Db', 'Settings', 'Auth', 'SerialPort', 'ScaleParser', 'Modbus', 'ScaleReader', 'Scales', 'Gates', 'GateEntries', 'OracleSync', 'Documents', 'OcrParser', 'Ocr', 'PurchaseOrders', 'Sap', 'DocMatch', 'DocSync', 'Api', 'OdooSync', 'Camera', 'Anpr', 'Weighment'] as $c) {
    require_once __DIR__ . "/$c.php";
}

function e(?string $s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

function is_installed(): bool { return is_file(WB_DB) && filesize(WB_DB) > 0 && Settings::get('installed') === '1'; }

function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null; unset($_SESSION['flash']); return $f;
}

/** Serial device / host values reach shell commands and sockets, so accept only safe shapes. */
function valid_port(string $p): bool { return (bool)preg_match('#^(COM\d{1,3}|/dev/[A-Za-z0-9_./:-]{1,80})$#', $p); }
function valid_host(string $h): bool { return (bool)preg_match('/^[A-Za-z0-9._-]{1,253}$/', $h); }

function redirect(string $url): never { header('Location: ' . $url); exit; }
