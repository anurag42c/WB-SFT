<?php
declare(strict_types=1);

/**
 * Automatic number-plate recognition. The heavy OCR runs in a service you choose; this class is the client:
 *   platerecognizer - Plate Recognizer cloud or on-prem Snapshot SDK  (POST multipart 'upload', "Authorization: Token <key>")
 *   codeproject     - CodeProject.AI Server ALPR module                (POST multipart 'upload' to /v1/vision/alpr)
 *   command         - any local program (e.g. OpenALPR `alpr -j`) that prints a plate or JSON; run without a shell
 * All providers return the same shape: plate (normalised) + confidence 0..1.
 */
final class Anpr
{
    public static function enabled(): bool { return Settings::get('anpr_provider', 'off') !== 'off'; }

    /** Upper-case letters and digits only: "mh 12-ab 1234" -> "MH12AB1234". */
    public static function normalize(string $p): string { return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $p) ?? ''); }

    /** OCR look-alikes folded together so 0/O, 1/I, 5/S, 8/B, 2/Z do not cause false alarms. */
    private static function canonical(string $p): string { return strtr(self::normalize($p), 'OIZSB', '01258'); }

    /** Same vehicle after folding look-alike characters (no edit-distance tolerance). */
    public static function exact(?string $a, ?string $b): bool
    {
        $x = self::canonical((string)$a); $y = self::canonical((string)$b);
        return $x !== '' && $x === $y;
    }

    public static function matches(string $a, string $b): bool
    {
        $x = self::canonical($a); $y = self::canonical($b);
        if ($x === '' || $y === '') { return false; }
        return $x === $y || (min(strlen($x), strlen($y)) >= 6 && levenshtein($x, $y) <= 1);
    }

    /** @return array{plate: ?string, confidence: ?float, error: ?string} */
    public static function recognize(string $jpeg, ?array $override = null): array
    {
        $c = array_merge(Settings::all(), $override ?? []);
        $res = ['plate' => null, 'confidence' => null, 'error' => null];
        try {
            $best = match ($c['anpr_provider']) {
                'platerecognizer' => self::viaHttp($jpeg, $c, $c['anpr_url'] ?: 'https://api.platerecognizer.com/v1/plate-reader/', true),
                'codeproject' => self::viaHttp($jpeg, $c, $c['anpr_url'] ?: 'http://127.0.0.1:32168/v1/vision/alpr', false),
                'command' => self::viaCommand($jpeg, (string)$c['anpr_command']),
                default => throw new RuntimeException('ANPR is switched off in Setup.'),
            };
            if ($best) { $res['plate'] = self::normalize($best[0]) ?: null; $res['confidence'] = $best[1]; }
        } catch (Throwable $e) { $res['error'] = $e->getMessage(); }
        return $res;
    }

    /** @return array{0:string,1:?float}|null highest-confidence plate */
    private static function viaHttp(string $jpeg, array $c, string $url, bool $tokenAuth): ?array
    {
        if (!preg_match('#^https?://#i', $url)) { throw new RuntimeException('ANPR URL must start with http:// or https://'); }
        $tmp = tempnam(sys_get_temp_dir(), 'wbplate'); file_put_contents($tmp, $jpeg);
        try {
            $post = ['upload' => new CURLFile($tmp, 'image/jpeg', 'plate.jpg')];
            if ($tokenAuth && $c['anpr_region'] !== '') { $post['regions'] = $c['anpr_region']; }
            $hdr = ($tokenAuth && $c['anpr_key'] !== '') ? ['Authorization: Token ' . $c['anpr_key']] : [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $post, CURLOPT_HTTPHEADER => $hdr, CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10, CURLOPT_CONNECTTIMEOUT => 3, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS]);
            $body = curl_exec($ch); $code = curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
        } finally { @unlink($tmp); }
        if ($body === false) { throw new RuntimeException("ANPR service unreachable: $err"); }
        if ($code >= 400) { throw new RuntimeException("ANPR service returned HTTP $code"); }
        $j = json_decode((string)$body, true);
        if (!is_array($j)) { throw new RuntimeException('ANPR service returned invalid JSON'); }

        $cands = [];
        foreach ($j['results'] ?? [] as $r) { if (!empty($r['plate'])) { $cands[] = [(string)$r['plate'], isset($r['score']) ? (float)$r['score'] : (isset($r['confidence']) ? self::conf($r['confidence']) : null)]; } }
        foreach ($j['predictions'] ?? [] as $r) {
            $p = $r['plate'] ?? (isset($r['label']) ? trim((string)preg_replace('/^\s*plate:\s*/i', '', (string)$r['label'])) : '');
            if ($p !== '') { $cands[] = [(string)$p, isset($r['confidence']) ? self::conf($r['confidence']) : null]; }
        }
        if (isset($j['success']) && $j['success'] === false) { throw new RuntimeException('ANPR error: ' . ($j['error'] ?? 'unknown')); }
        return self::best($cands);
    }

    private static function viaCommand(string $jpeg, string $cmd): ?array
    {
        $cmd = trim($cmd);
        if ($cmd === '') { throw new RuntimeException('ANPR command is empty.'); }
        $tmp = tempnam(sys_get_temp_dir(), 'wbplate'); file_put_contents($tmp, $jpeg);
        try {
            $argv = preg_split('/\s+/', $cmd);
            $argv = array_map(fn($a) => str_replace('{image}', $tmp, $a), $argv);
            if (!in_array($tmp, $argv, true) && !str_contains($cmd, '{image}')) { $argv[] = $tmp; }
            $p = @proc_open($argv, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);   // array form: no shell involved
            if (!is_resource($p)) { throw new RuntimeException('Cannot start ANPR command'); }
            stream_set_blocking($pipes[1], false);
            $out = ''; $end = microtime(true) + 10;
            while (microtime(true) < $end) {
                $out .= (string)stream_get_contents($pipes[1]);
                if (!(proc_get_status($p)['running'])) { $out .= (string)stream_get_contents($pipes[1]); break; }
                usleep(50000);
            }
            if (proc_get_status($p)['running']) { proc_terminate($p); throw new RuntimeException('ANPR command timed out'); }
            fclose($pipes[1]); fclose($pipes[2]); proc_close($p);
        } finally { @unlink($tmp); }

        $j = json_decode($out, true);
        if (is_array($j)) {       // OpenALPR style: {"results":[{"plate":"..","confidence":92.3}]}
            $cands = [];
            foreach ($j['results'] ?? [] as $r) { if (!empty($r['plate'])) { $cands[] = [(string)$r['plate'], isset($r['confidence']) ? self::conf($r['confidence']) : null]; } }
            return self::best($cands);
        }
        $t = trim($out);         // plain text: "PLATE" or "PLATE 0.93"
        if ($t === '') { return null; }
        $parts = preg_split('/[\s,;]+/', strtok($t, "\n"));
        return [$parts[0], isset($parts[1]) && is_numeric($parts[1]) ? self::conf($parts[1]) : null];
    }

    private static function conf(mixed $v): float { $v = (float)$v; return $v > 1 ? min(1.0, $v / 100) : $v; }

    private static function best(array $c): ?array
    {
        if (!$c) { return null; }
        usort($c, fn($a, $b) => ($b[1] ?? 0) <=> ($a[1] ?? 0));
        return $c[0];
    }
}
