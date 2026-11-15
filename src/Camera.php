<?php
declare(strict_types=1);

/** Optional IP-camera snapshot (JPEG) per scale. Never blocks or fails a weighment. */
final class Camera
{
    public static function dir(): string
    {
        $d = WB_DATA . '/snapshots';
        if (!is_dir($d)) { @mkdir($d, 0770, true); }
        return $d;
    }

    /** JPEG bytes from the scale's camera_url, or null (no camera / unreachable / not a JPEG). */
    public static function fetch(?array $cfg): ?string
    {
        $url = trim((string)($cfg['cam_url'] ?? ''));
        if ($url === '' || !preg_match('#^https?://#i', $url) || !function_exists('curl_init')) { return null; }
        try {
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2,
                CURLOPT_HTTPAUTH => CURLAUTH_ANY, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS, CURLOPT_FOLLOWLOCATION => false]);
            if (($cfg['cam_user'] ?? '') !== '') { curl_setopt($ch, CURLOPT_USERPWD, $cfg['cam_user'] . ':' . ($cfg['cam_pass'] ?? '')); }
            $body = curl_exec($ch);
            $ok = $body !== false && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200 && str_starts_with((string)$body, "\xFF\xD8");
            curl_close($ch);
            return $ok ? (string)$body : null;
        } catch (Throwable) { return null; }
    }

    /** @return string|null file name saved under data/snapshots */
    public static function save(string $ticket, string $tag, string $bytes): ?string
    {
        $name = preg_replace('/[^A-Za-z0-9_-]/', '', $ticket) . "_$tag.jpg";
        return @file_put_contents(self::dir() . '/' . $name, $bytes) === false ? null : $name;
    }
}
