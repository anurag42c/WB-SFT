<?php
declare(strict_types=1);

/**
 * Gate / boom-barrier control. A gate is a relay (or PLC output) that opens and closes a barrier.
 * Drivers: simulator, http (IP relay, Shelly, ESP, barrier web APIs), tcp (raw ASCII/hex to an Ethernet relay board),
 * serial (raw bytes to a USB/RS-232 relay board, e.g. LCUS "A0 01 01 A2"), modbus_rtu (RS-485 coil write),
 * modbus_tcp (coil write to a PLC / Ethernet I/O module).
 *
 * "pulse" mode: send the open/close command, wait pulse_ms, then send the optional release command
 * (most barrier controllers want a momentary contact). "latched" mode: send the command and leave it.
 * State is the last command sent (there is no sensor feedback), so it is shown as "last command".
 * Software is never the safety system: use the barrier's own loop / photocell for anti-crush protection.
 */
final class Gates
{
    public const ROLES = ['ENTRY' => 'Entry gate', 'EXIT' => 'Exit gate', 'BRIDGE_IN' => 'Weighbridge entry boom', 'BRIDGE_OUT' => 'Weighbridge exit boom'];
    public const DRIVERS = ['simulator' => 'Simulator (no hardware)', 'http' => 'HTTP relay / barrier web API', 'tcp' => 'TCP raw commands (Ethernet relay board)',
        'serial' => 'Serial raw commands (USB / RS-232 relay board)', 'modbus_rtu' => 'Modbus RTU coil (RS-485 relay / PLC)', 'modbus_tcp' => 'Modbus TCP coil (PLC / Ethernet I/O)'];

    public const DEFAULTS = [
        'driver' => 'simulator', 'mode' => 'pulse', 'pulse_ms' => '1000', 'auto_close_sec' => '0', 'auto_open' => '1',
        'http_open_url' => '', 'http_close_url' => '', 'http_release_url' => '', 'http_method' => 'GET',
        'http_open_body' => '', 'http_close_body' => '', 'http_release_body' => '', 'http_user' => '', 'http_pass' => '',
        'tcp_host' => '', 'tcp_port' => '502', 'open_cmd' => '', 'close_cmd' => '', 'release_cmd' => '',
        'serial_port' => '', 'baud' => '9600', 'data_bits' => '8', 'parity' => 'N', 'stop_bits' => '1',
        'modbus_slave' => '1', 'coil_open' => '0', 'coil_close' => '', 'expect_reply' => '1',
        'cam_url' => '', 'cam_user' => '', 'cam_pass' => '',
    ];
    public const SECRET_KEYS = ['http_pass', 'cam_pass'];

    // ---------------------------------------------------------------- configuration
    public static function all(bool $onlyEnabled = false): array
    {
        return Db::all('SELECT id, name, enabled, role, scale_id FROM gates' . ($onlyEnabled ? ' WHERE enabled = 1' : '') . ' ORDER BY id');
    }

    public static function find(int $id): ?array { return Db::one('SELECT id, name, enabled, role, scale_id FROM gates WHERE id = ?', [$id]); }

    public static function config(int $id): ?array
    {
        $row = Db::one('SELECT * FROM gates WHERE id = ?', [$id]);
        if (!$row) { return null; }
        $own = json_decode((string)$row['cfg'], true) ?: [];
        foreach (self::SECRET_KEYS as $k) { if (isset($own[$k])) { $own[$k] = Settings::open((string)$own[$k]); } }
        return array_merge(self::DEFAULTS, $own, ['gate_id' => (string)$id, 'gate_name' => $row['name'], 'role' => $row['role'], 'scale_id' => $row['scale_id']]);
    }

    public static function save(int $id, string $name, bool $enabled, string $role, ?int $scaleId, array $cfg): void
    {
        if (!isset(self::ROLES[$role])) { throw new RuntimeException('Unknown gate role.'); }
        $row = Db::one('SELECT cfg FROM gates WHERE id = ?', [$id]);
        $cur = $row ? (json_decode((string)$row['cfg'], true) ?: []) : [];
        foreach (array_keys(self::DEFAULTS) as $k) {
            if (!array_key_exists($k, $cfg)) { continue; }
            $v = (string)$cfg[$k];
            if (in_array($k, self::SECRET_KEYS, true)) { if ($v === '') { continue; } $v = Settings::seal($v); }   // blank = keep
            $cur[$k] = $v;
        }
        $merged = array_merge(self::DEFAULTS, $cur);
        if ($enabled) {
            $port = self::serialPort($merged);
            if ($port !== null && Scales::usesSerial($port)) { throw new RuntimeException("$port is used by a scale reader. A gate relay needs its own serial adapter."); }
        }
        Db::q('UPDATE gates SET name = ?, enabled = ?, role = ?, scale_id = ?, cfg = ? WHERE id = ?', [$name, (int)$enabled, $role, $scaleId ?: null, json_encode($cur), $id]);
    }

    public static function create(string $name): int
    {
        Db::q("INSERT INTO gates(name, enabled, role, cfg) VALUES (?, 0, 'ENTRY', ?)", [$name, json_encode(['driver' => 'simulator'])]);
        return Db::id();
    }

    public static function delete(int $id): void
    {
        Db::q('DELETE FROM gates WHERE id = ?', [$id]); Db::q('DELETE FROM gate_state WHERE gate_id = ?', [$id]);
    }

    public static function serialPort(array $c): ?string
    {
        return in_array($c['driver'], ['serial', 'modbus_rtu'], true) && $c['serial_port'] !== '' ? strtolower($c['serial_port']) : null;
    }

    /** Is any enabled gate using this serial port? (Scales refuse to share a port with a gate.) */
    public static function usesSerial(string $port, int $exceptGate = 0): bool
    {
        foreach (Db::all('SELECT id, cfg FROM gates WHERE enabled = 1 AND id <> ?', [$exceptGate]) as $g) {
            if (self::serialPort(array_merge(self::DEFAULTS, json_decode((string)$g['cfg'], true) ?: [])) === strtolower($port)) { return true; }
        }
        return false;
    }

    /** Which buttons make sense for this gate. */
    public static function capabilities(array $c): array
    {
        $pulse = $c['mode'] === 'pulse';
        $close = match ($c['driver']) {
            'simulator' => true,
            'http' => $c['http_close_url'] !== '',
            'tcp', 'serial' => $c['close_cmd'] !== '',
            'modbus_rtu', 'modbus_tcp' => !$pulse || $c['coil_close'] !== '',
            default => false,
        };
        return ['open' => true, 'close' => $close];
    }

    // ---------------------------------------------------------------- state + events
    public static function state(int $id): array
    {
        return Db::one('SELECT * FROM gate_state WHERE gate_id = ?', [$id]) ?? ['gate_id' => $id, 'state' => 'UNKNOWN', 'updated_at' => null, 'close_at' => null];
    }

    public static function log(int $id, string $name, string $action, string $source, bool $ok, string $msg): void
    {
        Db::q('INSERT INTO gate_events(at, gate_id, gate_name, action, source, user, ok, message) VALUES (?,?,?,?,?,?,?,?)',
            [date('Y-m-d H:i:s'), $id, $name, $action, $source, $_SESSION['user']['username'] ?? 'system', (int)$ok, mb_substr($msg, 0, 300)]);
    }

    // ---------------------------------------------------------------- commands
    /**
     * Open or close a configured gate: serialised per physical port, state + event log updated, never throws.
     * @return array{ok: bool, message: string}
     */
    public static function command(int $id, string $action, string $source = 'manual'): array
    {
        $cfg = self::config($id);
        if (!$cfg) { return ['ok' => false, 'message' => 'Unknown gate.']; }
        $name = $cfg['gate_name'];
        $row = Db::one('SELECT enabled FROM gates WHERE id = ?', [$id]);
        if (!$row || !(int)$row['enabled']) { return ['ok' => false, 'message' => "Gate '$name' is disabled."]; }
        if (!in_array($action, ['open', 'close'], true)) { return ['ok' => false, 'message' => 'Unknown action.']; }

        $lock = null;
        try {
            $lock = self::lock($cfg);
            self::execute($cfg, $action);
            $now = microtime(true);
            $closeAt = ($action === 'open' && (int)$cfg['auto_close_sec'] > 0) ? $now + (int)$cfg['auto_close_sec'] : null;
            Db::q('INSERT INTO gate_state(gate_id, state, updated_at, close_at) VALUES (?,?,?,?)
                   ON CONFLICT(gate_id) DO UPDATE SET state = excluded.state, updated_at = excluded.updated_at, close_at = excluded.close_at',
                [$id, $action === 'open' ? 'OPEN' : 'CLOSED', $now, $closeAt]);
            self::log($id, $name, $action, $source, true, 'OK');
            return ['ok' => true, 'message' => "Gate '$name': $action sent."];
        } catch (Throwable $e) {
            self::log($id, $name, $action, $source, false, $e->getMessage());
            return ['ok' => false, 'message' => "Gate '$name' $action failed: " . $e->getMessage()];
        } finally { self::unlock($lock); }
    }

    /** Close gates whose auto-close time has passed. Called by the supervisor every couple of seconds. */
    public static function runDue(): int
    {
        $n = 0;
        foreach (Db::all('SELECT gate_id FROM gate_state WHERE close_at IS NOT NULL AND close_at <= ?', [microtime(true)]) as $r) {
            Db::q('UPDATE gate_state SET close_at = NULL WHERE gate_id = ?', [$r['gate_id']]);   // one attempt; failure is in the event log
            self::command((int)$r['gate_id'], 'close', 'auto-close'); $n++;
        }
        return $n;
    }

    /** Boom at the weighbridge exit opens when the second weighment of a truck on that scale completes. */
    public static function onTicketClosed(int $scaleId, string $ticketNo): void
    {
        foreach (Db::all("SELECT id, cfg FROM gates WHERE enabled = 1 AND role = 'BRIDGE_OUT' AND (scale_id IS NULL OR scale_id = ?)", [$scaleId]) as $g) {
            $c = array_merge(self::DEFAULTS, json_decode((string)$g['cfg'], true) ?: []);
            if ($c['auto_open'] === '1') { self::command((int)$g['id'], 'open', "ticket $ticketNo"); }
        }
    }

    // ---------------------------------------------------------------- drivers
    /** Send the physical command(s) for one action using $cfg. Throws on failure. Also used by the Setup test buttons. */
    public static function execute(array $c, string $action): void
    {
        $cap = self::capabilities($c);
        if ($action === 'close' && !$cap['close']) { throw new RuntimeException('Close is not configured for this gate (pulse-type barrier closes by itself).'); }
        if ($c['driver'] === 'simulator') { return; }
        self::send($c, $action);
        $rel = self::hasRelease($c, $action);
        if ($c['mode'] === 'pulse' && $rel) {
            usleep(max(50, min(10000, (int)$c['pulse_ms'])) * 1000);
            self::send($c, 'release_' . $action);
        }
    }

    private static function hasRelease(array $c, string $action): bool
    {
        return match ($c['driver']) {
            'http' => $c['http_release_url'] !== '',
            'tcp', 'serial' => $c['release_cmd'] !== '',
            'modbus_rtu', 'modbus_tcp' => $action === 'open' || $c['coil_close'] !== '',
            default => false,
        };
    }

    private static function send(array $c, string $step): void
    {
        match ($c['driver']) {
            'http' => self::sendHttp($c, $step),
            'tcp' => self::sendTcp($c, self::bytes($c[self::cmdKey($step)] ?? '')),
            'serial' => self::sendSerial($c, self::bytes($c[self::cmdKey($step)] ?? '')),
            'modbus_rtu', 'modbus_tcp' => self::sendCoil($c, $step),
            default => throw new RuntimeException('Unknown driver'),
        };
    }

    private static function cmdKey(string $step): string { return str_starts_with($step, 'release') ? 'release_cmd' : $step . '_cmd'; }

    /** "hex:A0 01 01 A2" -> raw bytes; anything else is text with \r \n \xNN escapes. */
    public static function bytes(string $s): string
    {
        if ($s === '') { throw new RuntimeException('Command is empty.'); }
        if (stripos($s, 'hex:') === 0) {
            $h = preg_replace('/[^0-9A-Fa-f]/', '', substr($s, 4));
            if ($h === '' || strlen($h) % 2) { throw new RuntimeException('Invalid hex command.'); }
            return (string)hex2bin($h);
        }
        return stripcslashes($s);
    }

    private static function sendHttp(array $c, string $step): void
    {
        $k = str_starts_with($step, 'release') ? 'release' : $step;
        $url = $c["http_{$k}_url"];
        if (!preg_match('#^https?://#i', $url)) { throw new RuntimeException('HTTP URL must start with http:// or https://'); }
        $method = in_array($c['http_method'], ['GET', 'POST', 'PUT'], true) ? $c['http_method'] : 'GET';
        $body = (string)$c["http_{$k}_body"];
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 5, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPAUTH => CURLAUTH_ANY, CURLOPT_FOLLOWLOCATION => false, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
        if ($method !== 'GET' && $body !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: ' . (str_starts_with(ltrim($body), '<') ? 'application/xml' : (str_starts_with(ltrim($body), '{') ? 'application/json' : 'application/x-www-form-urlencoded'))]);
        }
        if ($c['http_user'] !== '') { curl_setopt($ch, CURLOPT_USERPWD, $c['http_user'] . ':' . $c['http_pass']); }
        $r = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        if ($r === false) { throw new RuntimeException("relay unreachable: $err"); }
        if ($code < 200 || $code >= 300) { throw new RuntimeException("relay answered HTTP $code"); }
    }

    private static function sendTcp(array $c, string $bytes): void
    {
        if (!valid_host($c['tcp_host']) || (int)$c['tcp_port'] < 1 || (int)$c['tcp_port'] > 65535) { throw new RuntimeException('Invalid TCP host/port'); }
        $fh = @stream_socket_client("tcp://{$c['tcp_host']}:{$c['tcp_port']}", $en, $es, 3);
        if (!$fh) { throw new RuntimeException("cannot connect to {$c['tcp_host']}:{$c['tcp_port']}: $es"); }
        stream_set_timeout($fh, 1);
        fwrite($fh, $bytes); fflush($fh);
        usleep(150000);
        stream_set_blocking($fh, false); fread($fh, 256);   // swallow any acknowledgement
        fclose($fh);
    }

    private static function sendSerial(array $c, string $bytes): void
    {
        $p = new SerialPort(['conn_type' => 'serial'] + $c);
        $p->open();
        try { $p->flushInput(); $p->write($bytes); usleep(200000); $p->read(50); } finally { $p->close(); }
    }

    private static function sendCoil(array $c, string $step): void
    {
        [$addr, $on] = match ($step) {
            'open' => [(int)$c['coil_open'], true],
            'close' => $c['coil_close'] !== '' ? [(int)$c['coil_close'], true] : [(int)$c['coil_open'], false],
            'release_open' => [(int)$c['coil_open'], false],
            'release_close' => [(int)$c['coil_close'], false],
        };
        $slave = (int)$c['modbus_slave'];
        if ($c['driver'] === 'modbus_tcp') {
            if (!valid_host($c['tcp_host'])) { throw new RuntimeException('Invalid host'); }
            $fh = @stream_socket_client("tcp://{$c['tcp_host']}:{$c['tcp_port']}", $en, $es, 3);
            if (!$fh) { throw new RuntimeException("cannot connect to {$c['tcp_host']}:{$c['tcp_port']}: $es"); }
            stream_set_timeout($fh, 2);
            $req = Modbus::writeCoilTcp(random_int(1, 65000), $slave, $addr, $on);
            fwrite($fh, $req);
            $resp = ''; $end = microtime(true) + 2;
            while (strlen($resp) < 12 && microtime(true) < $end) { $d = fread($fh, 12 - strlen($resp)); if ($d === false || $d === '') { usleep(20000); } else { $resp .= $d; } }
            fclose($fh);
            if ($c['expect_reply'] === '1' && substr($resp, 2) !== substr($req, 2)) { throw new RuntimeException('no/incorrect Modbus TCP reply'); }
            return;
        }
        $p = new SerialPort(['conn_type' => 'serial'] + $c);
        $p->open();
        try {
            $req = Modbus::writeCoil($slave, $addr, $on);
            $p->flushInput(); $p->write($req);
            $resp = $p->readExact(8, 800);
            if ($c['expect_reply'] === '1' && $resp !== $req) { throw new RuntimeException($resp === '' ? 'relay did not reply (check wiring A/B, baud, slave id)' : 'unexpected Modbus reply'); }
        } finally { $p->close(); }
    }

    // ---------------------------------------------------------------- per-endpoint lock (two gates may share one relay board)
    private static function lock(array $c)
    {
        $key = $c['driver'] . '|' . (self::serialPort($c) ?? ($c['tcp_host'] . ':' . $c['tcp_port']) . '|' . $c['http_open_url']);
        $fh = @fopen(WB_DATA . '/gate_' . md5($key) . '.lock', 'c');
        if ($fh) {
            $end = microtime(true) + 8;
            while (!flock($fh, LOCK_EX | LOCK_NB)) { if (microtime(true) > $end) { fclose($fh); throw new RuntimeException('relay busy'); } usleep(50000); }
        }
        return $fh;
    }

    private static function unlock($fh): void { if ($fh) { flock($fh, LOCK_UN); fclose($fh); } }
}
