<?php
declare(strict_types=1);

/**
 * Truck in/out analytics by product category. A "truck" below is one completed weighbridge ticket (one load);
 * the category comes from the material master (Masters > Materials), "Uncategorised" when none is set.
 * Tickets from the factory weighbridge and from outside weighbridge slips are both counted (filterable).
 */
final class Analytics
{
    public const NONE = 'Uncategorised';

    private static function cat(string $col): string
    {
        return "COALESCE(NULLIF(TRIM((SELECT m.category FROM materials m WHERE LOWER(m.name) = LOWER($col) ORDER BY m.id LIMIT 1)), ''), '" . self::NONE . "')";
    }

    /** @return array{0: string, 1: array} WHERE clause + args for completed tickets in range. */
    private static function where(array $f): array
    {
        $w = ["w.status = 'CLOSED'", 'w.net_kg IS NOT NULL']; $a = [];
        $day = 'substr(COALESCE(w.second_at, w.first_at), 1, 10)';
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['from'] ?? ''))) { $w[] = "$day >= ?"; $a[] = $f['from']; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['to'] ?? ''))) { $w[] = "$day <= ?"; $a[] = $f['to']; }
        if (in_array($f['source'] ?? '', ['LOCAL', 'EXTERNAL'], true)) { $w[] = 'w.source = ?'; $a[] = $f['source']; }
        if (($f['category'] ?? '') !== '') { $w[] = self::cat('w.material') . ' = ?'; $a[] = $f['category']; }
        return [implode(' AND ', $w), $a];
    }

    public static function categories(): array
    {
        $r = array_column(Db::all("SELECT DISTINCT TRIM(category) AS c FROM materials WHERE TRIM(COALESCE(category, '')) <> '' ORDER BY 1"), 'c');
        $r[] = self::NONE;
        return $r;
    }

    /** One row per category: trucks and tonnes in / out, balance, averages, share from outside weighbridges. */
    public static function byCategory(array $f): array
    {
        [$w, $a] = self::where($f);
        $rows = Db::all("SELECT " . self::cat('w.material') . " AS category,
                SUM(w.direction = 'INWARD') AS in_trucks, COALESCE(SUM(CASE WHEN w.direction = 'INWARD' THEN w.net_kg END), 0) AS in_kg,
                SUM(w.direction = 'OUTWARD') AS out_trucks, COALESCE(SUM(CASE WHEN w.direction = 'OUTWARD' THEN w.net_kg END), 0) AS out_kg,
                SUM(w.source = 'EXTERNAL') AS ext_trucks
            FROM weighments w WHERE $w GROUP BY 1 ORDER BY (in_kg + out_kg) DESC", $a);
        foreach ($rows as &$r) {
            foreach (['in_trucks', 'out_trucks', 'ext_trucks'] as $k) { $r[$k] = (int)$r[$k]; }
            foreach (['in_kg', 'out_kg'] as $k) { $r[$k] = (float)$r[$k]; }
            $r['balance_kg'] = $r['in_kg'] - $r['out_kg'];
            $r['avg_in_kg'] = $r['in_trucks'] ? $r['in_kg'] / $r['in_trucks'] : null;
            $r['avg_out_kg'] = $r['out_trucks'] ? $r['out_kg'] / $r['out_trucks'] : null;
        }
        return $rows;
    }

    public static function totals(array $rows): array
    {
        $t = ['in_trucks' => 0, 'out_trucks' => 0, 'in_kg' => 0.0, 'out_kg' => 0.0, 'ext_trucks' => 0];
        foreach ($rows as $r) { foreach ($t as $k => $_) { $t[$k] += $r[$k]; } }
        $t['balance_kg'] = $t['in_kg'] - $t['out_kg'];
        return $t;
    }

    /** One row per day (every day in the range is present, also when empty). */
    public static function daily(array $f): array
    {
        [$w, $a] = self::where($f);
        $day = 'substr(COALESCE(w.second_at, w.first_at), 1, 10)';
        $got = [];
        foreach (Db::all("SELECT $day AS d, SUM(w.direction = 'INWARD') AS in_trucks, SUM(w.direction = 'OUTWARD') AS out_trucks,
                COALESCE(SUM(CASE WHEN w.direction = 'INWARD' THEN w.net_kg END), 0) AS in_kg, COALESCE(SUM(CASE WHEN w.direction = 'OUTWARD' THEN w.net_kg END), 0) AS out_kg
            FROM weighments w WHERE $w GROUP BY 1", $a) as $r) { $got[$r['d']] = $r; }
        $from = $f['from'] ?? null; $to = $f['to'] ?? null;
        if (!$got && (!$from || !$to)) { return []; }
        $from = $from ?: min(array_keys($got)); $to = $to ?: max(array_keys($got));
        $out = [];
        for ($t = strtotime($from), $n = 0; $t !== false && $t <= strtotime($to) && $n < 400; $t = strtotime('+1 day', $t), $n++) {
            $d = date('Y-m-d', $t); $r = $got[$d] ?? null;
            $out[] = ['date' => $d, 'in_trucks' => (int)($r['in_trucks'] ?? 0), 'out_trucks' => (int)($r['out_trucks'] ?? 0), 'in_kg' => (float)($r['in_kg'] ?? 0), 'out_kg' => (float)($r['out_kg'] ?? 0)];
        }
        return $out;
    }

    public static function topMaterials(array $f, int $limit = 10): array
    {
        [$w, $a] = self::where($f);
        return Db::all("SELECT COALESCE(NULLIF(TRIM(w.material), ''), '(none)') AS material, " . self::cat('w.material') . " AS category,
                SUM(w.direction = 'INWARD') AS in_trucks, SUM(w.direction = 'OUTWARD') AS out_trucks, SUM(w.net_kg) AS kg
            FROM weighments w WHERE $w GROUP BY 1, 2 ORDER BY kg DESC LIMIT " . max(1, $limit), $a);
    }

    /** Gate register view: entries, exits, still inside and average time on site, per category of the entry's material. */
    public static function gate(array $f): array
    {
        $w = ["e.status <> 'CANCELLED'"]; $a = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['from'] ?? ''))) { $w[] = 'substr(e.in_at, 1, 10) >= ?'; $a[] = $f['from']; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['to'] ?? ''))) { $w[] = 'substr(e.in_at, 1, 10) <= ?'; $a[] = $f['to']; }
        $cat = self::cat('e.material');
        if (($f['category'] ?? '') !== '') { $w[] = "$cat = ?"; $a[] = $f['category']; }
        $rows = Db::all("SELECT $cat AS category, COUNT(*) AS entries, SUM(e.status = 'EXITED') AS exits, SUM(e.status = 'INSIDE') AS inside,
                SUM(e.purpose = 'DELIVERY') AS deliveries, SUM(e.purpose = 'DISPATCH') AS dispatches,
                AVG(CASE WHEN e.status = 'EXITED' THEN (julianday(e.out_at) - julianday(e.in_at)) * 1440 END) AS avg_min
            FROM gate_entries e WHERE " . implode(' AND ', $w) . " GROUP BY 1 ORDER BY entries DESC", $a);
        foreach ($rows as &$r) { foreach (['entries', 'exits', 'inside', 'deliveries', 'dispatches'] as $k) { $r[$k] = (int)$r[$k]; } $r['avg_min'] = $r['avg_min'] !== null ? (float)$r['avg_min'] : null; }
        return $rows;
    }

    /** Loads where the outside slip and the factory weighbridge disagree by more than the tolerance (Setup > Gates). */
    public static function variances(array $f): array
    {
        $w = ['e.ext_net_kg IS NOT NULL', 'e.wb_net_kg IS NOT NULL']; $a = [];
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['from'] ?? ''))) { $w[] = 'substr(e.in_at, 1, 10) >= ?'; $a[] = $f['from']; }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($f['to'] ?? ''))) { $w[] = 'substr(e.in_at, 1, 10) <= ?'; $a[] = $f['to']; }
        $out = [];
        foreach (Db::all('SELECT e.* FROM gate_entries e WHERE ' . implode(' AND ', $w) . ' ORDER BY e.id DESC LIMIT 500', $a) as $e) {
            $v = GateEntries::variance($e);
            if ($v && !$v['ok']) { $out[] = $v + ['entry_no' => $e['entry_no'], 'vehicle_no' => $e['vehicle_no'], 'material' => $e['material'], 'slip' => trim($e['ext_wb_name'] . ' ' . $e['ext_slip_no']), 'ticket_no' => $e['wb_ticket_no']]; }
        }
        return $out;
    }
}
