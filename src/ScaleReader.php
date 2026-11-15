<?php
declare(strict_types=1);

/** One polling step for whichever protocol is configured. Used by the daemon and the setup test. */
final class ScaleReader
{
    private ?SerialPort $port = null;
    private ScaleParser $parser;
    private array $history = [];
    private float $simT;
    private float $lastPoll = 0;

    public function __construct(private array $cfg)
    {
        $this->parser = new ScaleParser($cfg);
        $this->simT = microtime(true);
    }

    public function close(): void { $this->port?->close(); $this->port = null; }

    /** @return array|null {weight, unit, stable, raw} or null when nothing new arrived */
    public function poll(): ?array
    {
        $type = $this->cfg['conn_type'];
        if ($type === 'simulator') { return $this->simulate(); }

        if (!$this->port) { $this->port = new SerialPort($this->cfg); $this->port->open(); }

        if ($this->cfg['protocol'] === 'modbus_rtu') { $r = $this->pollModbus(); }
        else {
            $cmd = ScaleParser::decodeEscapes((string)$this->cfg['request_cmd']);
            if ($cmd !== '') { $this->port->write($cmd); }
            $r = $this->parser->feed($this->port->read(200));
        }
        return $r ? $this->deriveStable($r) : null;
    }

    private function pollModbus(): ?array
    {
        $c = $this->cfg;
        $wait = (int)$c['modbus_poll_ms'] / 1000 - (microtime(true) - $this->lastPoll);
        if ($wait > 0) { usleep((int)($wait * 1e6)); }
        $this->lastPoll = microtime(true);

        $slave = (int)$c['modbus_slave']; $func = (int)$c['modbus_func']; $n = max(1, min(2, (int)$c['modbus_regs']));
        $this->port->flushInput();
        $this->port->write(Modbus::request($slave, $func, (int)$c['modbus_addr'], $n));
        $resp = $this->port->readExact(5 + 2 * $n, 600);
        $regs = Modbus::parse($resp, $slave, $func, $n);
        $val = Modbus::toNumber($regs, $c['modbus_signed'] === '1', $c['modbus_word_order']) / max(0.000001, (float)$c['divisor']);
        return ['weight' => round($val, 3), 'unit' => 'kg', 'stable' => null, 'raw' => 'REG[' . implode(',', $regs) . ']'];
    }

    /** If the indicator has no stability flag, call it stable when the last N readings agree within tolerance. */
    private function deriveStable(array $r): array
    {
        $n = max(2, (int)$this->cfg['stable_count']); $tol = (float)$this->cfg['stable_tolerance'];
        $this->history[] = $r['weight'];
        $this->history = array_slice($this->history, -$n);
        if ($r['stable'] === null) {
            $r['stable'] = count($this->history) >= $n && (max($this->history) - min($this->history)) <= $tol;
        }
        return $r;
    }

    /** Fake truck: ramps up, holds ~15s, ramps down, idle. Lets you test the whole flow without hardware. */
    private function simulate(): array
    {
        usleep(200000);
        $t = fmod(microtime(true) - $this->simT, 40.0);
        $target = 28450.0;
        if ($t < 5) { $w = 0.0; $st = true; }
        elseif ($t < 10) { $w = $target * ($t - 5) / 5 + mt_rand(-40, 40); $st = false; }
        elseif ($t < 28) { $w = $target; $st = true; }
        elseif ($t < 33) { $w = $target * (1 - ($t - 28) / 5); $st = false; }
        else { $w = 0.0; $st = true; }
        $w = round(max(0, $w));
        return ['weight' => (float)$w, 'unit' => 'kg', 'stable' => $st, 'raw' => sprintf('%s,GS,%+08dkg', $st ? 'ST' : 'US', $w)];
    }
}
