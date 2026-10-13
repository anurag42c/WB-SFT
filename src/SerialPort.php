<?php
declare(strict_types=1);

/**
 * Byte transport to the indicator: a local serial port (RS-232, or RS-485 via a USB/RS-485 converter)
 * or a TCP socket (serial-to-Ethernet gateway such as Moxa NPort, USR-TCP232, Waveshare, etc).
 * All reads are non-blocking + polled so the same code works on Linux and Windows.
 */
final class SerialPort
{
    /** @var resource|null */
    private $fh = null;

    public function __construct(private array $cfg) {}

    public static function isWindows(): bool { return PHP_OS_FAMILY === 'Windows'; }

    /** List candidate serial devices for the setup page. */
    public static function listPorts(): array
    {
        if (self::isWindows()) { return array_map(fn($i) => "COM$i", range(1, 20)); }
        $found = [];
        foreach (['/dev/ttyUSB*', '/dev/ttyACM*', '/dev/ttyS[0-9]*', '/dev/ttyAMA*', '/dev/serial/by-id/*', '/dev/cu.usb*'] as $g) {
            foreach (glob($g) ?: [] as $p) { $found[] = $p; }
        }
        return array_values(array_unique($found));
    }

    public function open(): void
    {
        $c = $this->cfg;
        if (($c['conn_type'] ?? 'serial') === 'tcp') {
            if (!valid_host((string)$c['tcp_host']) || (int)$c['tcp_port'] < 1 || (int)$c['tcp_port'] > 65535) { throw new RuntimeException('Invalid TCP host/port'); }
            $fh = @stream_socket_client("tcp://{$c['tcp_host']}:{$c['tcp_port']}", $en, $es, 5);
            if (!$fh) { throw new RuntimeException("TCP connect to {$c['tcp_host']}:{$c['tcp_port']} failed: $es"); }
            stream_set_blocking($fh, false);
            $this->fh = $fh;
            return;
        }

        $port = (string)$c['serial_port'];
        if (!valid_port($port)) { throw new RuntimeException('Invalid serial port name (use COM3 or /dev/ttyUSB0)'); }
        $baud = (int)$c['baud']; $bits = (int)$c['data_bits']; $par = strtoupper((string)$c['parity']); $stop = (string)$c['stop_bits'];

        if (self::isWindows()) {
            $pmap = ['N' => 'n', 'E' => 'e', 'O' => 'o'];
            $cmd = sprintf('mode %s: BAUD=%d PARITY=%s DATA=%d STOP=%s to=off xon=off odsr=off octs=off dtr=on rts=on idsr=off',
                $port, $baud, $pmap[$par] ?? 'n', $bits, $stop);
            exec($cmd . ' 2>&1', $out, $rc);
            if ($rc !== 0) { throw new RuntimeException("mode failed: " . implode(' ', $out)); }
            $path = '\\\\.\\' . $port;
        } else {
            $path = $port;
            if (!file_exists($path)) { throw new RuntimeException("Serial device $path not found"); }
            $flags = ['cs' . $bits, $stop === '2' ? 'cstopb' : '-cstopb'];
            $flags[] = match ($par) { 'E' => 'parenb -parodd', 'O' => 'parenb parodd', default => '-parenb' };
            $flag = PHP_OS_FAMILY === 'Darwin' ? '-f' : '-F';
            $cmd = sprintf('stty %s %s %d %s raw -echo -echoe -echok -crtscts clocal cread min 0 time 0 2>&1',
                $flag, escapeshellarg($path), $baud, implode(' ', $flags));
            exec($cmd, $out, $rc);
            if ($rc !== 0) { throw new RuntimeException("stty failed (permission? add user to 'dialout'): " . implode(' ', $out)); }
        }
        $fh = @fopen($path, 'r+b');
        if (!$fh) { throw new RuntimeException("Cannot open $path (in use, or no permission)"); }
        stream_set_blocking($fh, false);
        $this->fh = $fh;
    }

    public function isOpen(): bool { return $this->fh !== null; }

    /** Read whatever is available, waiting up to $waitMs for the first byte. */
    public function read(int $waitMs = 100, int $max = 4096): string
    {
        if (!$this->fh) { throw new RuntimeException('Port not open'); }
        $end = microtime(true) + $waitMs / 1000;
        do {
            $d = fread($this->fh, $max);
            if ($d !== false && $d !== '') { return $d; }
            if (feof($this->fh) && ($this->cfg['conn_type'] ?? '') === 'tcp') { throw new RuntimeException('TCP connection closed by peer'); }
            usleep(5000);
        } while (microtime(true) < $end);
        return '';
    }

    /** Read exactly $n bytes or fewer if $timeoutMs elapses. */
    public function readExact(int $n, int $timeoutMs): string
    {
        $buf = ''; $end = microtime(true) + $timeoutMs / 1000;
        while (strlen($buf) < $n && microtime(true) < $end) { $buf .= $this->read(20, $n - strlen($buf)); }
        return $buf;
    }

    public function write(string $data): void
    {
        if (!$this->fh) { throw new RuntimeException('Port not open'); }
        fwrite($this->fh, $data);
        fflush($this->fh);
    }

    public function flushInput(): void { while ($this->read(1) !== '') {} }

    public function close(): void { if ($this->fh) { @fclose($this->fh); $this->fh = null; } }
}
