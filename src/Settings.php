<?php
declare(strict_types=1);

/** Key/value settings in SQLite. Secrets are encrypted with a key stored in data/app.key. */
final class Settings
{
    public const DEFAULTS = [
        'company_name' => 'My Weighbridge',
        'company_address' => '',
        'ticket_prefix' => 'WB',
        // scale connection
        'conn_type' => 'serial',        // serial | tcp | simulator
        'serial_port' => '/dev/ttyUSB0',
        'baud' => '9600', 'data_bits' => '8', 'parity' => 'N', 'stop_bits' => '1',
        'tcp_host' => '192.168.1.50', 'tcp_port' => '4001',
        'protocol' => 'ascii',          // ascii | modbus_rtu
        'terminator' => '\r\n',
        'weight_regex' => '/([+-]?\d+(?:\.\d+)?)\s*(kg|g|t)?/i',
        'stable_regex' => '',           // e.g. /ST/ ; empty = derive from repeated readings
        'unstable_regex' => '',         // e.g. /US|MOTION/
        'stable_count' => '5',
        'stable_tolerance' => '0',
        'divisor' => '1',
        'unit' => 'kg',
        'modbus_slave' => '1', 'modbus_func' => '3', 'modbus_addr' => '0', 'modbus_regs' => '2',
        'modbus_signed' => '0', 'modbus_word_order' => 'big', 'modbus_poll_ms' => '300',
        'request_cmd' => '',            // optional ASCII command sent before each read (e.g. "W\r\n")
        'min_capture_kg' => '20',
        'allow_manual' => '0',
        'cam_url' => '', 'cam_user' => '', 'cam_pass' => '', 'api_token_hash' => '',
        // ANPR (plate recognition) - global; camera URL is per scale
        'anpr_provider' => 'off',       // off | platerecognizer | codeproject | command
        'anpr_url' => '', 'anpr_key' => '', 'anpr_region' => '', 'anpr_command' => '',
        'anpr_min_conf' => '0.6', 'anpr_policy' => 'warn',   // off | warn | block
        'anpr_auto' => '0',
        // Gate entry
        'gate_require_entry' => 'off',  // off | warn | block : weighing needs a gate entry (vehicle inside)
        'gate_exit_policy' => 'warn',   // off | warn | block : exit needs completed weighment, no open ticket
        // Invoice / material-return documents (OCR, matching, transfer)
        'ocr_provider' => 'off',        // off | claude | tesseract
        'ocr_claude_key' => '', 'ocr_claude_model' => 'claude-opus-5-5', 'ocr_claude_url' => 'https://api.anthropic.com/v1/messages', 'ocr_effort' => 'medium',
        'ocr_tesseract_cmd' => 'tesseract {image} stdout -l eng --psm 6',
        'doc_max_mb' => '10', 'doc_date_order' => 'DMY',
        'doc_match_days' => '5', 'doc_qty_tol_pct' => '2', 'doc_scan_days' => '14', 'doc_scan_grace_h' => '24',
        'doc_targets' => 'none',        // none | oracle | sap | both
        'doc_send_policy' => 'no_high', // no_high: hold documents that have open HIGH exceptions | any
        'ora_doc_table' => 'WB_DOCUMENTS', 'ora_doc_line_table' => 'WB_DOCUMENT_LINES',
        'sap_url' => '', 'sap_auth' => 'basic', 'sap_user' => '', 'sap_pass' => '', 'sap_token' => '', 'sap_client' => '',
        'sap_csrf' => '1', 'sap_ref_path' => '', 'sap_po_url' => '', 'sap_timeout' => '15',
        // Odoo (JSON-RPC push of tickets, pull of purchase orders / masters) and REST API write access
        'odoo_enabled' => '0', 'odoo_url' => '', 'odoo_db' => '', 'odoo_user' => '', 'odoo_key' => '', 'odoo_model' => 'wb.ticket',
        'odoo_timeout' => '20', 'odoo_pull_po' => '0', 'odoo_pull_masters' => '0', 'odoo_batch' => '50',
        'api_write' => '0',
        // oracle
        'ora_enabled' => '0', 'ora_host' => '', 'ora_port' => '1521', 'ora_service' => '',
        'ora_user' => '', 'ora_pass' => '', 'ora_table' => 'WEIGHBRIDGE_TICKETS', 'ora_batch' => '50',
    ];
    public const SECRETS = ['ora_pass', 'cam_pass', 'anpr_key', 'ocr_claude_key', 'sap_pass', 'sap_token', 'odoo_key'];

    private static ?array $cache = null;
    private static array $over = [];        // unsaved values used by the Setup "Test" buttons

    public static function override(array $values): void { self::$over = $values; }

    public static function all(): array
    {
        if (self::$cache === null) {
            self::$cache = self::DEFAULTS;
            try {
                foreach (Db::all('SELECT k, v FROM settings') as $r) {
                    self::$cache[$r['k']] = in_array($r['k'], self::SECRETS, true) ? self::open((string)$r['v']) : $r['v'];
                }
            } catch (Throwable) {}
        }
        return self::$over ? array_merge(self::$cache, self::$over) : self::$cache;
    }

    public static function get(string $k, ?string $default = null): ?string { return self::all()[$k] ?? $default; }

    public static function set(string $k, string $v): void
    {
        $store = in_array($k, self::SECRETS, true) ? self::seal($v) : $v;
        Db::q('INSERT INTO settings(k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v', [$k, $store]);
        self::$cache = null;
    }

    public static function reset(): void { self::$cache = null; }

    private static function key(): string
    {
        $f = WB_DATA . '/app.key';
        if (!is_file($f)) { file_put_contents($f, random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES)); @chmod($f, 0600); }
        return (string)file_get_contents($f);
    }

    public static function seal(string $plain): string
    {
        if ($plain === '') { return ''; }
        $n = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 'enc:' . base64_encode($n . sodium_crypto_secretbox($plain, $n, self::key()));
    }

    public static function open(string $s): string
    {
        if (!str_starts_with($s, 'enc:')) { return $s; }
        $raw = base64_decode(substr($s, 4), true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) { return ''; }
        $p = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $p === false ? '' : $p;
    }
}
