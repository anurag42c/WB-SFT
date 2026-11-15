<?php
declare(strict_types=1);

/** Turns raw indicator bytes into weight readings (continuous-output ASCII protocols). */
final class ScaleParser
{
    private string $buf = '';

    public function __construct(private array $cfg) {}

    public static function decodeEscapes(string $s): string
    {
        return stripcslashes($s);   // "\r\n" typed in the setup page -> real CR LF, "\x02" -> STX
    }

    /** Feed bytes; returns the newest parsed reading or null. */
    public function feed(string $bytes): ?array
    {
        $this->buf .= $bytes;
        if (strlen($this->buf) > 8192) { $this->buf = substr($this->buf, -2048); }
        $term = self::decodeEscapes((string)$this->cfg['terminator']);
        if ($term === '') { $term = "\n"; }

        $last = null;
        while (($p = strpos($this->buf, $term)) !== false) {
            $frame = substr($this->buf, 0, $p);
            $this->buf = substr($this->buf, $p + strlen($term));
            $r = $this->parseFrame($frame);
            if ($r) { $last = $r; }
        }
        return $last;
    }

    public function parseFrame(string $frame): ?array
    {
        $clean = trim(preg_replace('/[\x00-\x08\x0B-\x1F\x7F]/', '', $frame) ?? '');
        if ($clean === '') { return null; }
        $re = (string)$this->cfg['weight_regex'];
        if (@preg_match($re, $clean, $m) !== 1) { return null; }
        $val = (float)$m[1];
        $unit = strtolower($m[2] ?? '') ?: strtolower((string)$this->cfg['unit']);
        $val /= max(0.000001, (float)$this->cfg['divisor']);
        $val *= match ($unit) { 'g' => 0.001, 't' => 1000.0, default => 1.0 };

        $stable = null;   // null = unknown, reader will derive it
        $sr = (string)$this->cfg['stable_regex']; $ur = (string)$this->cfg['unstable_regex'];
        if ($ur !== '' && @preg_match($ur, $clean) === 1) { $stable = false; }
        elseif ($sr !== '' && @preg_match($sr, $clean) === 1) { $stable = true; }
        elseif ($sr !== '') { $stable = false; }

        return ['weight' => round($val, 3), 'unit' => 'kg', 'stable' => $stable, 'raw' => $clean];
    }

    /** Validate a user-entered regex; returns error text or null. */
    public static function regexError(string $re): ?string
    {
        if ($re === '') { return null; }
        set_error_handler(fn() => true);
        $ok = preg_match($re, '') !== false;
        restore_error_handler();
        return $ok ? null : 'Invalid regular expression (remember delimiters, e.g. /ST/)';
    }
}
