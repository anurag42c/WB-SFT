<?php
declare(strict_types=1);

/** Shared plumbing for the REST API under public/api/v1: bearer-token auth, JSON in/out, brute-force limit. */
final class Api
{
    public const VERSION = '1';

    /** Authenticate and set headers. $write = the endpoint changes data (needs "Allow API writes" in Setup). */
    public static function begin(bool $write = false): void
    {
        header('Content-Type: application/json; charset=utf-8'); header('Cache-Control: no-store'); header('X-Content-Type-Options: nosniff');
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        if ($method === 'OPTIONS') { http_response_code(204); exit; }
        try { Db::upgrade(); } catch (Throwable) {}
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ((int)Db::val("SELECT COUNT(*) FROM login_attempts WHERE username = 'api' AND ip = ? AND at > ?", [$ip, time() - 300]) >= 20) { self::err(429, 'too many failed attempts, wait 5 minutes'); }
        $hash = (string)Settings::get('api_token_hash');
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        if ($hash === '' || !preg_match('/^Bearer\s+(\S+)$/', $auth, $m) || !hash_equals($hash, hash('sha256', $m[1]))) {
            if ($hash !== '') { Db::q("INSERT INTO login_attempts(username, ip, at) VALUES ('api', ?, ?)", [$ip, time()]); }
            header('WWW-Authenticate: Bearer'); self::err(401, 'unauthorized');
        }
        if ($write) {
            if ($method !== 'POST') { header('Allow: POST'); self::err(405, 'use POST'); }
            if (Settings::get('api_write') !== '1') { self::err(403, 'API writes are disabled (Setup > General > Allow API writes)'); }
        } elseif ($method !== 'GET') { header('Allow: GET'); self::err(405, 'read-only endpoint, use GET'); }
    }

    public static function body(): array
    {
        $raw = (string)file_get_contents('php://input');
        if (strlen($raw) > 4 * 1048576) { self::err(413, 'body too large'); }
        $j = json_decode($raw, true);
        if (!is_array($j)) { self::err(400, 'JSON object expected'); }
        return $j;
    }

    public static function out(mixed $data, int $code = 200): never
    {
        http_response_code($code);
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
        exit;
    }

    public static function err(int $code, string $msg): never { self::out(['error' => $msg], $code); }

    public static function limit(int $default = 100, int $max = 500): int { return max(1, min($max, (int)($_GET['limit'] ?? $default))); }

    /** A ticket row as exposed by the API (no internal sync columns except Odoo's reference). */
    public static function ticket(array $w): array
    {
        return [
            'id' => (int)$w['id'], 'ticket_no' => $w['ticket_no'], 'status' => $w['status'], 'direction' => $w['direction'],
            'vehicle_no' => $w['vehicle_no'], 'party' => $w['party'], 'material' => $w['material'], 'driver' => $w['driver'], 'challan_no' => $w['challan_no'],
            'remarks' => $w['remarks'], 'gross_kg' => $w['gross_kg'] !== null ? (float)$w['gross_kg'] : null, 'tare_kg' => $w['tare_kg'] !== null ? (float)$w['tare_kg'] : null,
            'net_kg' => $w['net_kg'] !== null ? (float)$w['net_kg'] : null, 'first_at' => $w['first_at'], 'second_at' => $w['second_at'],
            'scale' => $w['scale_id'] ? (Scales::find((int)$w['scale_id'])['name'] ?? null) : null,
            'plate_in' => $w['plate_in'], 'plate_out' => $w['plate_out'], 'plate_flag' => $w['plate_flag'],
            'operator' => $w['operator'], 'odoo_id' => $w['odoo_id'] !== null ? (int)$w['odoo_id'] : null,
        ];
    }
}
