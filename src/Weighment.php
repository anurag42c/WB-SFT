<?php
declare(strict_types=1);

/** Ticket business rules. Weights are always captured server-side from a scale's live reading. */
final class Weighment
{
    /** Live state of one scale (0 = first enabled scale). */
    public static function live(int $scaleId = 0): array
    {
        $scaleId = $scaleId ?: Scales::defaultId();
        $r = Db::one('SELECT * FROM live_scale WHERE scale_id = ?', [$scaleId]) ?? [];
        $age = isset($r['updated_at']) ? microtime(true) - (float)$r['updated_at'] : 9999;
        $beat = isset($r['heartbeat']) ? microtime(true) - (float)$r['heartbeat'] : 9999;
        $r['scale_id'] = $scaleId;
        $r['daemon_alive'] = $beat < 6;
        $r['fresh'] = $r['daemon_alive'] && $age < 3 && ($r['status'] ?? '') === 'RUNNING';
        $r['age'] = round($age, 1);
        return $r;
    }

    /** Live state of every enabled scale, with name and minimum weight. */
    public static function liveAll(): array
    {
        $out = [];
        foreach (Scales::all(true) as $s) {
            $l = self::live((int)$s['id']);
            $l['name'] = $s['name'];
            $l['min'] = (float)(Scales::config((int)$s['id'])['min_capture_kg'] ?? 0);
            $out[] = $l;
        }
        return $out;
    }

    /** @return array{0: float, 1: int} [kg, manual?] */
    public static function capture(?string $manualKg, int $scaleId = 0): array
    {
        if ($manualKg !== null && $manualKg !== '') {
            if (Settings::get('allow_manual') !== '1') { throw new RuntimeException('Manual weight entry is disabled in Setup.'); }
            $kg = (float)$manualKg;
            if ($kg <= 0) { throw new RuntimeException('Enter a valid manual weight.'); }
            return [round($kg, 2), 1];
        }
        $scaleId = $scaleId ?: Scales::defaultId();
        $cfg = Scales::config($scaleId);
        if (!$cfg) { throw new RuntimeException('Unknown scale.'); }
        $l = self::live($scaleId);
        $n = $cfg['scale_name'];
        if (!$l['fresh']) { throw new RuntimeException("No live weight from '$n' (reader stopped or scale disconnected). Check Setup > Scales."); }
        if (!(int)$l['stable']) { throw new RuntimeException("Weight on '$n' is not stable yet. Wait for STABLE and try again."); }
        $kg = (float)$l['weight'];
        if ($kg < (float)$cfg['min_capture_kg']) { throw new RuntimeException("Weight {$kg} kg is below the minimum capture weight."); }
        return [$kg, 0];
    }

    public static function nextTicket(): string
    {
        $prefix = Settings::get('ticket_prefix', 'WB') . date('Ymd');
        $max = Db::val('SELECT MAX(ticket_no) FROM weighments WHERE ticket_no LIKE ?', [$prefix . '%']);
        $seq = $max ? ((int)substr((string)$max, strlen($prefix))) + 1 : 1;
        return $prefix . str_pad((string)$seq, 4, '0', STR_PAD_LEFT);
    }

    private static function remember(string $table, string $name): void
    {
        if ($name !== '') { Db::q("INSERT OR IGNORE INTO $table(" . ($table === 'vehicles' ? 'reg_no' : 'name') . ') VALUES (?)', [$name]); }
    }

    /** Camera photo + plate reading for one weighment. Slow (network), so callers run it outside any DB transaction. */
    private static function gather(int $scaleId, string $expectedVehicle): array
    {
        $g = ['bytes' => null, 'plate' => null, 'conf' => null, 'flag' => null];
        $g['bytes'] = Camera::fetch(Scales::config($scaleId));
        if (!Anpr::enabled()) { return $g; }
        if ($g['bytes'] === null) { $g['flag'] = 'NOIMG'; return $g; }
        $rec = Anpr::recognize($g['bytes']);
        $g['conf'] = $rec['confidence'];
        if ($rec['plate'] !== null && ($rec['confidence'] ?? 1.0) >= (float)Settings::get('anpr_min_conf')) { $g['plate'] = $rec['plate']; }
        $g['flag'] = $g['plate'] === null ? 'UNREAD' : (($expectedVehicle === '' || Anpr::matches($g['plate'], $expectedVehicle)) ? 'OK' : 'MISMATCH');
        return $g;
    }

    private static function enforcePlate(array $g, string $expected, bool $override): void
    {
        if ($g['flag'] === 'MISMATCH' && Settings::get('anpr_policy') === 'block' && !$override) {
            throw new RuntimeException("Camera read plate {$g['plate']} but the ticket vehicle is $expected. An admin must confirm with 'Override plate mismatch'.");
        }
        if ($g['flag'] === 'MISMATCH') { Db::audit('plate_mismatch', "read={$g['plate']} expected=$expected" . ($override ? ' (admin override)' : '')); }
    }

    public static function create(array $in, ?string $manualKg, bool $override = false): int
    {
        $scaleId = (int)($in['scale_id'] ?? 0) ?: Scales::defaultId();
        $veh = self::vehicleKey((string)($in['vehicle_no'] ?? ''));
        $useStored = !empty($in['use_stored_tare']);

        if ($veh !== '') { self::preflight($veh, $useStored); }
        [$kg, $man] = self::capture($manualKg, $scaleId);     // moment of button press
        $g = self::gather($scaleId, $veh);
        if ($veh === '' && $g['plate'] !== null) { $veh = $g['plate']; self::preflight($veh, $useStored); }
        if ($veh === '') { throw new RuntimeException('Vehicle number is required (camera could not read it).'); }
        self::enforcePlate($g, $veh, $override);
        $entry = GateEntries::inside($veh);
        $gp = Settings::get('gate_require_entry', 'off');
        if (!$entry && $gp !== 'off') {
            if ($gp === 'block' && !$override) { throw new RuntimeException("Vehicle $veh has no gate entry (not registered inside). Register it at the gate first, or an admin can override."); }
            Db::audit('no_gate_entry', $veh);
        }
        $stored = $useStored ? Db::val('SELECT tare_kg FROM vehicles WHERE reg_no = ?', [$veh]) : null;

        $firstType = ($in['first_type'] ?? 'GROSS') === 'TARE' ? 'TARE' : 'GROSS';
        $now = date('Y-m-d H:i:s');
        $party = trim((string)($in['party'] ?? '')); $mat = trim((string)($in['material'] ?? ''));

        $pdo = Db::pdo(); $pdo->exec('BEGIN IMMEDIATE');   // serialise ticket-number allocation
        try {
            $ticket = self::nextTicket();
            $img = $g['bytes'] !== null ? Camera::save($ticket, 'in', $g['bytes']) : null;
            Db::q('INSERT INTO weighments(ticket_no, vehicle_no, party, material, direction, driver, challan_no, remarks, first_type, first_kg, first_at, first_manual,
                   operator, created_at, scale_id, first_img, plate_in, plate_in_conf, plate_flag, gate_entry_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $ticket, $veh, $party, $mat, ($in['direction'] ?? 'INWARD') === 'OUTWARD' ? 'OUTWARD' : 'INWARD',
                trim((string)($in['driver'] ?? '')), trim((string)($in['challan_no'] ?? '')), trim((string)($in['remarks'] ?? '')),
                $useStored ? 'GROSS' : $firstType, $kg, $now, $man, Auth::user()['username'] ?? 'system', $now,
                $scaleId, $img, $g['plate'], $g['conf'], $g['flag'], $entry['id'] ?? null]);
            $id = Db::id();
            self::remember('vehicles', $veh); self::remember('parties', $party); self::remember('materials', $mat);
            if ($useStored) { self::finish($id, (float)$stored, 'STORED TARE', 0, $now); }
            Db::audit('ticket_create', $ticket);
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK'); } throw $e; }
        if ($useStored) { try { Gates::onTicketClosed($scaleId, $ticket); } catch (Throwable) {} }   // single-pass ticket is complete now
        return $id;
    }

    private static function vehicleKey(string $v): string { return strtoupper(preg_replace('/\s+/', '', trim($v)) ?? ''); }

    private static function preflight(string $veh, bool $useStored): void
    {
        if (Db::val("SELECT COUNT(*) FROM weighments WHERE vehicle_no = ? AND status = 'OPEN'", [$veh])) {
            throw new RuntimeException("Vehicle $veh already has an open ticket. Complete the second weighment instead.");
        }
        if ($useStored && !Db::val('SELECT tare_kg FROM vehicles WHERE reg_no = ?', [$veh])) {
            throw new RuntimeException('No stored tare for this vehicle. Untick "Use stored tare".');
        }
    }

    public static function second(int $id, ?string $manualKg, int $scaleId = 0, bool $override = false): void
    {
        $w = Db::one("SELECT * FROM weighments WHERE id = ? AND status = 'OPEN'", [$id]);
        if (!$w) { throw new RuntimeException('Ticket not found or already closed.'); }
        $scaleId = $scaleId ?: (int)$w['scale_id'] ?: Scales::defaultId();
        [$kg, $man] = self::capture($manualKg, $scaleId);
        $g = self::gather($scaleId, $w['vehicle_no']);
        self::enforcePlate($g, $w['vehicle_no'], $override);   // is the truck on the bridge the one on the ticket?
        self::finish($id, $kg, null, $man, date('Y-m-d H:i:s'));
        $img = $g['bytes'] !== null ? Camera::save($w['ticket_no'], 'out', $g['bytes']) : null;
        $flag = ($w['plate_flag'] === 'MISMATCH' || $g['flag'] === 'MISMATCH') ? 'MISMATCH' : ($g['flag'] ?? $w['plate_flag']);
        Db::q('UPDATE weighments SET second_scale_id = ?, second_img = ?, plate_out = ?, plate_out_conf = ?, plate_flag = ? WHERE id = ?',
            [$scaleId, $img, $g['plate'], $g['conf'], $flag, $id]);
        Db::audit('ticket_close', $w['ticket_no']);
        try { Gates::onTicketClosed($scaleId, $w['ticket_no']); } catch (Throwable) {}   // a barrier fault never undoes a weighing
    }

    private static function finish(int $id, float $kg, ?string $note, int $manual, string $now): void
    {
        $w = Db::one('SELECT * FROM weighments WHERE id = ?', [$id]);
        $first = (float)$w['first_kg'];
        if ($w['first_type'] === 'GROSS') { $gross = $first; $tare = $kg; } else { $gross = $kg; $tare = $first; }
        if ($gross <= $tare) { throw new RuntimeException(sprintf('Gross (%.0f kg) must be greater than tare (%.0f kg). Check first-weight type.', $gross, $tare)); }
        $sync = Settings::get('ora_enabled') === '1' ? 'PENDING' : 'NA';
        $odoo = Settings::get('odoo_enabled') === '1' ? 'PENDING' : 'NA';
        Db::q("UPDATE weighments SET second_kg=?, second_at=?, second_manual=?, gross_kg=?, tare_kg=?, net_kg=?, status='CLOSED', sync_status=?, odoo_status=?,
               remarks = CASE WHEN ? IS NULL THEN remarks ELSE TRIM(COALESCE(remarks,'') || ' [' || ? || ']') END WHERE id=?",
            [$kg, $now, $manual, $gross, $tare, $gross - $tare, $sync, $odoo, $note, $note, $id]);
        if ($w['first_type'] === 'TARE' || $note === null) {   // learn the vehicle's tare for next time
            Db::q('UPDATE vehicles SET tare_kg = ? WHERE reg_no = ? AND (tare_kg IS NULL)', [$tare, $w['vehicle_no']]);
        }
    }

    public static function cancel(int $id, string $reason): void
    {
        // A ticket already in Oracle must be re-sent so Oracle also shows it as CANCELLED.
        Db::q("UPDATE weighments SET remarks=TRIM(COALESCE(remarks,'') || ' [CANCELLED: ' || ? || ']'),
               sync_status = CASE WHEN sync_status IN ('SYNCED','PENDING','FAILED') AND gross_kg IS NOT NULL THEN 'PENDING' ELSE 'NA' END,
               odoo_status = CASE WHEN odoo_status IN ('SYNCED','PENDING','FAILED') AND gross_kg IS NOT NULL THEN 'PENDING' ELSE 'NA' END,
               status='CANCELLED' WHERE id=? AND status<>'CANCELLED'", [$reason, $id]);
        Db::audit('ticket_cancel', "$id $reason");
    }
}
