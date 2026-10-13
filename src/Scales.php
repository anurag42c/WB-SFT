<?php
declare(strict_types=1);

/**
 * Several indicators on one PC. Each scale has its own connection, parser rules, minimum weight and camera.
 * Global things (company, ANPR provider, Oracle) stay in Settings. config($id) returns Settings overlaid
 * with the scale's own values, so ScaleReader / SerialPort / Camera work unchanged.
 */
final class Scales
{
    public const KEYS = ['conn_type', 'serial_port', 'baud', 'data_bits', 'parity', 'stop_bits', 'tcp_host', 'tcp_port', 'protocol',
        'terminator', 'request_cmd', 'weight_regex', 'stable_regex', 'unstable_regex', 'stable_count', 'stable_tolerance', 'divisor', 'unit',
        'modbus_slave', 'modbus_func', 'modbus_addr', 'modbus_regs', 'modbus_signed', 'modbus_word_order', 'modbus_poll_ms',
        'min_capture_kg', 'cam_url', 'cam_user', 'cam_pass'];

    public static function all(bool $onlyEnabled = false): array
    {
        return Db::all('SELECT id, name, enabled FROM scales' . ($onlyEnabled ? ' WHERE enabled = 1' : '') . ' ORDER BY id');
    }

    public static function find(int $id): ?array { return Db::one('SELECT id, name, enabled FROM scales WHERE id = ?', [$id]); }

    public static function defaultId(): int { return (int)(Db::val('SELECT id FROM scales WHERE enabled = 1 ORDER BY id LIMIT 1') ?? 0); }

    /** Effective config for one scale, or null if it does not exist. */
    public static function config(int $id): ?array
    {
        $row = Db::one('SELECT * FROM scales WHERE id = ?', [$id]);
        if (!$row) { return null; }
        $own = json_decode((string)$row['cfg'], true) ?: [];
        if (isset($own['cam_pass'])) { $own['cam_pass'] = Settings::open((string)$own['cam_pass']); }
        $base = array_intersect_key(Settings::DEFAULTS, array_flip(self::KEYS));
        return array_merge(Settings::all(), $base, $own, ['scale_id' => (string)$id, 'scale_name' => $row['name']]);
    }

    /** Own (raw) values only, for the edit form. */
    public static function own(int $id): array
    {
        return self::config($id) ?? [];
    }

    public static function save(int $id, string $name, bool $enabled, array $cfg): void
    {
        $row = Db::one('SELECT cfg FROM scales WHERE id = ?', [$id]);
        $cur = $row ? (json_decode((string)$row['cfg'], true) ?: []) : [];
        foreach (self::KEYS as $k) {
            if (!array_key_exists($k, $cfg)) { continue; }
            $v = (string)$cfg[$k];
            if ($k === 'cam_pass') { if ($v === '') { continue; } $v = Settings::seal($v); }   // blank = keep
            $cur[$k] = $v;
        }
        self::assertNoPortClash($id, $enabled, array_merge(array_map(fn($x) => $x, $cur), ['cam_pass' => '']));
        Db::q('UPDATE scales SET name = ?, enabled = ?, cfg = ? WHERE id = ?', [$name, (int)$enabled, json_encode($cur), $id]);
    }

    public static function create(string $name): int
    {
        Db::q("INSERT INTO scales(name, enabled, cfg) VALUES (?, 0, ?)", [$name, json_encode(['conn_type' => 'simulator'])]);
        return Db::id();
    }

    public static function delete(int $id): void
    {
        if ((int)Db::val('SELECT COUNT(*) FROM scales') <= 1) { throw new RuntimeException('Cannot delete the only scale.'); }
        Db::q('DELETE FROM scales WHERE id = ?', [$id]);
        Db::q('DELETE FROM live_scale WHERE scale_id = ?', [$id]);
    }

    public static function ensureDefault(): void
    {
        if ((int)Db::val('SELECT COUNT(*) FROM scales') > 0) { return; }
        $cfg = [];
        foreach (self::KEYS as $k) {
            $r = Db::val('SELECT v FROM settings WHERE k = ?', [$k]);   // carry over v1 single-scale settings
            if ($r !== null) { $cfg[$k] = (string)$r; }
        }
        Db::q("INSERT INTO scales(id, name, enabled, cfg) VALUES (1, 'Scale 1', 1, ?)", [json_encode($cfg)]);
        Db::q('UPDATE weighments SET scale_id = 1 WHERE scale_id IS NULL');
    }

    /** Is any enabled serial scale using this port? (A gate relay may not share it: the reader keeps the port open.) */
    public static function usesSerial(string $port): bool
    {
        foreach (Db::all('SELECT cfg FROM scales WHERE enabled = 1') as $s) {
            $c = array_merge(Settings::DEFAULTS, json_decode((string)$s['cfg'], true) ?: []);
            if ($c['conn_type'] === 'serial' && strtolower((string)$c['serial_port']) === strtolower($port)) { return true; }
        }
        return false;
    }

    /** Two enabled scales cannot share one serial port or one gateway endpoint. */
    private static function assertNoPortClash(int $id, bool $enabled, array $cfg): void
    {
        if (!$enabled) { return; }
        $mine = self::endpoint(array_merge(Settings::DEFAULTS, $cfg));
        if ($mine === null) { return; }
        if (($cfg['conn_type'] ?? '') === 'serial' && Gates::usesSerial((string)$cfg['serial_port'])) {
            throw new RuntimeException("$mine is used by a gate relay. Each device needs its own serial adapter.");
        }
        foreach (Db::all('SELECT id, name, cfg FROM scales WHERE enabled = 1 AND id <> ?', [$id]) as $o) {
            if (self::endpoint(array_merge(Settings::DEFAULTS, json_decode((string)$o['cfg'], true) ?: [])) === $mine) {
                throw new RuntimeException("'{$o['name']}' already uses $mine. Each scale needs its own port / gateway address.");
            }
        }
    }

    private static function endpoint(array $c): ?string
    {
        return match ($c['conn_type']) {
            'serial' => strtolower((string)$c['serial_port']),
            'tcp' => strtolower($c['tcp_host'] . ':' . $c['tcp_port']),
            default => null,
        };
    }
}
