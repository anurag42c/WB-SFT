<?php
declare(strict_types=1);

/**
 * Gate pass / security entry-exit register. A vehicle is registered INSIDE at the entry gate (barrier opens),
 * is weighed (tickets link to its entry), and leaves through the exit gate (barrier opens) once its weighing is complete.
 */
final class GateEntries
{
    public const PURPOSES = ['DELIVERY' => 'Delivery (material in)', 'DISPATCH' => 'Dispatch (material out)', 'VISIT' => 'Visitor / other', 'EMPTY' => 'Empty / returning'];

    public static function inside(string $vehicle): ?array
    {
        return Db::one("SELECT * FROM gate_entries WHERE vehicle_no = ? AND status = 'INSIDE' ORDER BY id DESC LIMIT 1", [self::key($vehicle)]);
    }

    private static function key(string $v): string { return strtoupper(preg_replace('/\s+/', '', trim($v)) ?? ''); }

    private static function nextNo(): string
    {
        $prefix = 'GE' . date('Ymd');
        $max = Db::val('SELECT MAX(entry_no) FROM gate_entries WHERE entry_no LIKE ?', [$prefix . '%']);
        return $prefix . str_pad((string)($max ? ((int)substr((string)$max, strlen($prefix))) + 1 : 1), 4, '0', STR_PAD_LEFT);
    }

    /** Camera picture + plate reading at a gate (same rules as the weighbridge). */
    private static function look(int $gateId, string $expected): array
    {
        $g = ['bytes' => null, 'plate' => null, 'flag' => null];
        $cfg = Gates::config($gateId);
        $g['bytes'] = $cfg ? Camera::fetch($cfg) : null;
        if (!Anpr::enabled()) { return $g; }
        if ($g['bytes'] === null) { $g['flag'] = 'NOIMG'; return $g; }
        $r = Anpr::recognize($g['bytes']);
        if ($r['plate'] !== null && ($r['confidence'] ?? 1.0) >= (float)Settings::get('anpr_min_conf')) { $g['plate'] = $r['plate']; }
        $g['flag'] = $g['plate'] === null ? 'UNREAD' : (($expected === '' || Anpr::matches($g['plate'], $expected)) ? 'OK' : 'MISMATCH');
        return $g;
    }

    /**
     * Register a vehicle at an entry gate.
     * @return array{id: int, gate: ?array} gate = result of the barrier command (null if none requested)
     */
    public static function register(array $in, int $gateId, bool $openGate = true, bool $override = false): array
    {
        $gate = $gateId ? Gates::find($gateId) : null;
        if ($gateId && !$gate) { throw new RuntimeException('Unknown gate.'); }
        $veh = self::key((string)($in['vehicle_no'] ?? ''));
        $g = $gateId ? self::look($gateId, $veh) : ['bytes' => null, 'plate' => null, 'flag' => null];
        if ($veh === '' && $g['plate'] !== null) { $veh = $g['plate']; }
        if ($veh === '') { throw new RuntimeException('Vehicle number is required.'); }

        if ($x = self::inside($veh)) { throw new RuntimeException("Vehicle $veh is already inside (entry {$x['entry_no']})."); }
        $blk = Db::one('SELECT blocked, block_reason FROM vehicles WHERE reg_no = ?', [$veh]);
        $note = null;
        if ($blk && (int)$blk['blocked']) {
            if (!$override) {
                Db::audit('gate_denied', "$veh blocked: " . ($blk['block_reason'] ?? ''));
                if ($gate) { Gates::log((int)$gate['id'], $gate['name'], 'deny', 'entry', false, "$veh BLOCKED"); }
                throw new RuntimeException("Vehicle $veh is BLOCKED" . ($blk['block_reason'] ? ': ' . $blk['block_reason'] : '') . '. An admin can override.');
            }
            $note = 'blocked vehicle admitted by admin override';
        }
        if ($g['flag'] === 'MISMATCH') {
            if (Settings::get('anpr_policy') === 'block' && !$override) {
                throw new RuntimeException("Camera read plate {$g['plate']} but the entry says $veh. An admin must confirm with 'Override'.");
            }
            Db::audit('plate_mismatch', "gate entry: read={$g['plate']} typed=$veh");
        }

        $now = date('Y-m-d H:i:s');
        $purpose = isset(self::PURPOSES[$in['purpose'] ?? '']) ? $in['purpose'] : 'DELIVERY';
        $pdo = Db::pdo(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            $no = self::nextNo();
            $img = $g['bytes'] !== null ? Camera::save($no, 'gin', $g['bytes']) : null;
            Db::q('INSERT INTO gate_entries(entry_no, vehicle_no, driver, driver_phone, party, material, purpose, challan_no, remarks, status, in_at, in_gate_id,
                   plate_in, plate_flag, img_in, operator, override_note) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)', [
                $no, $veh, trim((string)($in['driver'] ?? '')), trim((string)($in['driver_phone'] ?? '')), trim((string)($in['party'] ?? '')),
                trim((string)($in['material'] ?? '')), $purpose, trim((string)($in['challan_no'] ?? '')), trim((string)($in['remarks'] ?? '')),
                'INSIDE', $now, $gateId ?: null, $g['plate'], $g['flag'], $img, Auth::user()['username'] ?? 'system', $note]);
            $id = Db::id();
            Db::q('INSERT OR IGNORE INTO vehicles(reg_no) VALUES (?)', [$veh]);
            if (($in['party'] ?? '') !== '') { Db::q('INSERT OR IGNORE INTO parties(name) VALUES (?)', [trim($in['party'])]); }
            if (($in['material'] ?? '') !== '') { Db::q('INSERT OR IGNORE INTO materials(name) VALUES (?)', [trim($in['material'])]); }
            Db::audit('gate_in', "$no $veh" . ($note ? " ($note)" : ''));
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK'); } throw $e; }

        $res = null;   // barrier problems never undo the register entry: the guard can retry the button
        if ($gate && $openGate) { $res = Gates::command((int)$gate['id'], 'open', "entry $no"); }
        return ['id' => $id, 'gate' => $res];
    }

    /**
     * Record the exit. Policy gate_exit_policy: block => needs a completed weighment (delivery/dispatch) and no open ticket.
     * @return array{gate: ?array}
     */
    public static function exit(int $entryId, int $gateId, bool $openGate = true, bool $override = false): array
    {
        $e = Db::one("SELECT * FROM gate_entries WHERE id = ? AND status = 'INSIDE'", [$entryId]);
        if (!$e) { throw new RuntimeException('Entry not found or vehicle already left.'); }
        $gate = $gateId ? Gates::find($gateId) : null;

        $problems = [];
        if (Db::val("SELECT COUNT(*) FROM weighments WHERE vehicle_no = ? AND status = 'OPEN'", [$e['vehicle_no']])) {
            $problems[] = 'it still has an OPEN weighbridge ticket (second weighment not done)';
        }
        if (in_array($e['purpose'], ['DELIVERY', 'DISPATCH'], true)
            && !Db::val("SELECT COUNT(*) FROM weighments WHERE gate_entry_id = ? AND status = 'CLOSED'", [$entryId])) {
            $problems[] = 'no completed weighment is linked to this entry';
        }
        $policy = Settings::get('gate_exit_policy', 'warn');
        $note = null;
        if ($problems && $policy !== 'off') {
            $msg = 'Vehicle ' . $e['vehicle_no'] . ': ' . implode('; ', $problems) . '.';
            if ($policy === 'block' && !$override) { throw new RuntimeException($msg . ' An admin can override.'); }
            $note = trim(($e['override_note'] ?? '') . ' exit: ' . implode('; ', $problems) . ($policy === 'block' ? ' (admin override)' : ' (warned)'));
            Db::audit('gate_exit_warning', $msg);
        }

        $g = $gateId ? self::look($gateId, $e['vehicle_no']) : ['bytes' => null, 'plate' => null, 'flag' => null];
        if ($g['flag'] === 'MISMATCH') {
            if (Settings::get('anpr_policy') === 'block' && !$override) {
                throw new RuntimeException("Camera read plate {$g['plate']} at the exit but the entry is for {$e['vehicle_no']}. An admin must confirm with 'Override'.");
            }
            Db::audit('plate_mismatch', "gate exit: read={$g['plate']} expected={$e['vehicle_no']}");
        }
        $img = $g['bytes'] !== null ? Camera::save($e['entry_no'], 'gout', $g['bytes']) : null;
        $flag = ($e['plate_flag'] === 'MISMATCH' || $g['flag'] === 'MISMATCH') ? 'MISMATCH' : ($g['flag'] ?? $e['plate_flag']);
        Db::q("UPDATE gate_entries SET status='EXITED', out_at=?, out_gate_id=?, plate_out=?, img_out=?, plate_flag=?, exit_operator=?, override_note=? WHERE id=?",
            [date('Y-m-d H:i:s'), $gateId ?: null, $g['plate'], $img, $flag, Auth::user()['username'] ?? 'system', $note, $entryId]);
        Db::audit('gate_out', $e['entry_no'] . ' ' . $e['vehicle_no']);

        $res = ($gate && $openGate) ? Gates::command((int)$gate['id'], 'open', 'exit ' . $e['entry_no']) : null;
        return ['gate' => $res];
    }

    public static function cancel(int $entryId, string $reason): void
    {
        Db::q("UPDATE gate_entries SET status='CANCELLED', remarks=TRIM(COALESCE(remarks,'') || ' [CANCELLED: ' || ? || ']') WHERE id=? AND status='INSIDE'", [$reason, $entryId]);
        Db::audit('gate_cancel', "$entryId $reason");
    }

    // ------------------------------------------------------------------ weighbridge <-> gate (both directions)

    /** Direction implied by the purpose of a gate entry (null when it says nothing). */
    public static function directionFor(?array $entry): ?string
    {
        return match ($entry['purpose'] ?? '') { 'DELIVERY' => 'INWARD', 'DISPATCH' => 'OUTWARD', default => null };
    }

    /** Weighbridge -> gate: show the factory ticket result on the gate entry and fill in what the guard left empty. */
    public static function refreshFromTickets(?int $entryId): void
    {
        if (!$entryId) { return; }
        $e = Db::one('SELECT * FROM gate_entries WHERE id = ?', [$entryId]);
        if (!$e) { return; }
        $w = Db::one("SELECT * FROM weighments WHERE gate_entry_id = ? AND source = 'LOCAL' AND status <> 'CANCELLED' ORDER BY id DESC LIMIT 1", [$entryId]);
        Db::q('UPDATE gate_entries SET wb_ticket_no = ?, wb_status = ?, wb_net_kg = ?,
               party = CASE WHEN COALESCE(party, \'\') = \'\' THEN ? ELSE party END, material = CASE WHEN COALESCE(material, \'\') = \'\' THEN ? ELSE material END,
               challan_no = CASE WHEN COALESCE(challan_no, \'\') = \'\' THEN ? ELSE challan_no END WHERE id = ?',
            [$w['ticket_no'] ?? null, $w['status'] ?? null, ($w && $w['status'] === 'CLOSED') ? $w['net_kg'] : null, $w['party'] ?? '', $w['material'] ?? '', $w['challan_no'] ?? '', $entryId]);
    }

    /**
     * Gate -> weighbridge: a weighing slip issued by ANOTHER weighbridge (a public one, a supplier's) is entered at the gate and becomes a completed
     * ticket (source EXTERNAL) so that reports, invoice matching, Oracle/Odoo transfer and analytics see it like any other load.
     * Idempotent on (weighbridge name, slip no): posting the same slip again returns the existing ticket.
     * $in: vehicle_no, slip_no, weighbridge, gross_kg + tare_kg and/or net_kg, weighed_at, party, material, purpose|direction, driver, challan_no.
     * @return array{ticket_id: int, ticket_no: string, entry_id: int, entry_no: string, duplicate: bool, net_kg: float}
     */
    public static function externalSlip(array $in, ?int $entryId = null, bool $override = false): array
    {
        $num = fn($k) => (isset($in[$k]) && trim((string)$in[$k]) !== '' && is_numeric(str_replace(',', '', (string)$in[$k]))) ? (float)str_replace(',', '', (string)$in[$k]) : null;
        $slip = trim((string)($in['slip_no'] ?? '')); $wbName = trim((string)($in['weighbridge'] ?? ''));
        if ($slip === '' || mb_strlen($slip) > 40) { throw new RuntimeException('The slip number is required.'); }
        if ($wbName === '') { throw new RuntimeException('Name of the outside weighbridge is required.'); }
        $dup = Db::one('SELECT id, ticket_no, gate_entry_id, net_kg FROM weighments WHERE ext_wb_name = ? AND ext_slip_no = ?', [$wbName, $slip]);
        if ($dup) {
            $en = Db::val('SELECT entry_no FROM gate_entries WHERE id = ?', [$dup['gate_entry_id']]);
            return ['ticket_id' => (int)$dup['id'], 'ticket_no' => $dup['ticket_no'], 'entry_id' => (int)$dup['gate_entry_id'], 'entry_no' => (string)$en, 'duplicate' => true, 'net_kg' => (float)$dup['net_kg']];
        }
        $gross = $num('gross_kg'); $tare = $num('tare_kg'); $net = $num('net_kg');
        if ($gross !== null && $tare !== null) {
            if ($gross <= $tare) { throw new RuntimeException('Gross weight must be greater than tare weight.'); }
            if ($net !== null && abs(($gross - $tare) - $net) > 1.0) { throw new RuntimeException(sprintf('Gross - tare = %.0f kg but the slip says net %.0f kg. Check the figures.', $gross - $tare, $net)); }
            $net = $gross - $tare;
        } elseif ($net !== null && ($gross !== null || $tare !== null)) {
            if ($gross !== null) { $tare = $gross - $net; } else { $gross = $tare + $net; }
        } else { throw new RuntimeException('Enter gross and tare weight from the slip (or one of them and the net weight).'); }
        if ($net === null || $net <= 0 || $net > 200000 || $gross > 300000 || $tare < 0) { throw new RuntimeException('Weights look wrong (net must be positive and below 200 000 kg).'); }
        $at = trim((string)($in['weighed_at'] ?? ''));
        $ts = $at !== '' ? strtotime($at) : time();
        if ($ts === false || $ts > time() + 3600) { throw new RuntimeException('Weighing date/time of the slip is not valid.'); }
        $when = date('Y-m-d H:i:s', $ts);

        $entry = $entryId ? Db::one('SELECT * FROM gate_entries WHERE id = ?', [$entryId]) : null;
        if ($entryId && (!$entry || $entry['status'] !== 'INSIDE')) { throw new RuntimeException('Gate entry not found or the vehicle has already left.'); }
        $veh = self::key((string)($in['vehicle_no'] ?? ($entry['vehicle_no'] ?? '')));
        if ($veh === '') { throw new RuntimeException('Vehicle number is required.'); }
        if ($entry && $entry['vehicle_no'] !== $veh) { throw new RuntimeException("The slip is for $veh but the entry is for {$entry['vehicle_no']}."); }
        if (!$entry) { $entry = self::inside($veh); }
        if (!$entry) {   // not at the gate yet: register it now (no barrier command; blocked vehicles are still refused)
            $in['vehicle_no'] = $veh;
            $r = self::register($in + ['purpose' => ($in['direction'] ?? '') === 'OUTWARD' ? 'DISPATCH' : 'DELIVERY'], 0, false, $override);
            $entry = Db::one('SELECT * FROM gate_entries WHERE id = ?', [$r['id']]);
        }
        $dir = in_array($in['direction'] ?? '', ['INWARD', 'OUTWARD'], true) ? $in['direction'] : (self::directionFor($entry) ?? 'INWARD');
        $party = trim((string)($in['party'] ?? '')) ?: (string)$entry['party']; $mat = trim((string)($in['material'] ?? '')) ?: (string)$entry['material'];
        $challan = trim((string)($in['challan_no'] ?? '')) ?: (string)$entry['challan_no'];
        $driver = trim((string)($in['driver'] ?? '')) ?: (string)$entry['driver'];
        $op = Auth::user()['username'] ?? 'api';

        $pdo = Db::pdo(); $pdo->exec('BEGIN IMMEDIATE');
        try {
            $ticket = Weighment::nextTicket();
            $ora = Settings::get('ora_enabled') === '1' ? 'PENDING' : 'NA'; $odoo = Settings::get('odoo_enabled') === '1' ? 'PENDING' : 'NA';
            Db::q("INSERT INTO weighments(ticket_no, vehicle_no, party, material, direction, driver, challan_no, remarks, first_type, first_kg, first_at, second_kg, second_at,
                   gross_kg, tare_kg, net_kg, status, operator, created_at, gate_entry_id, source, ext_slip_no, ext_wb_name, sync_status, odoo_status)
                   VALUES (?,?,?,?,?,?,?,?, 'GROSS', ?,?,?,?, ?,?,?, 'CLOSED', ?,?,?, 'EXTERNAL', ?,?,?,?)", [
                $ticket, $veh, $party, $mat, $dir, $driver, $challan, "OUTSIDE WEIGHBRIDGE: $wbName slip $slip" . (($in['remarks'] ?? '') !== '' ? ' - ' . trim((string)$in['remarks']) : ''),
                $gross, $when, $tare, $when, $gross, $tare, $net, $op, date('Y-m-d H:i:s'), $entry['id'], $slip, $wbName, $ora, $odoo]);
            $tid = Db::id();
            Db::q('UPDATE gate_entries SET ext_slip_no = ?, ext_wb_name = ?, ext_gross_kg = ?, ext_tare_kg = ?, ext_net_kg = ?, ext_at = ? WHERE id = ?',
                [$slip, $wbName, $gross, $tare, $net, $when, $entry['id']]);
            foreach (['parties' => $party, 'materials' => $mat] as $t => $v) { if ($v !== '') { Db::q("INSERT OR IGNORE INTO $t(name) VALUES (?)", [$v]); } }
            Db::audit('ext_slip', "$ticket $veh $wbName/$slip net $net");
            $pdo->exec('COMMIT');
        } catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK'); } throw $e; }
        return ['ticket_id' => $tid, 'ticket_no' => $ticket, 'entry_id' => (int)$entry['id'], 'entry_no' => $entry['entry_no'], 'duplicate' => false, 'net_kg' => $net];
    }

    /**
     * Compare the outside slip with the factory weighbridge for one gate entry.
     * @return ?array{ext_kg: float, factory_kg: float, diff_kg: float, allowed_kg: float, ok: bool}  null while one of the two is missing
     */
    public static function variance(array $entry): ?array
    {
        if ($entry['ext_net_kg'] === null || $entry['wb_net_kg'] === null) { return null; }
        $ext = (float)$entry['ext_net_kg']; $fac = (float)$entry['wb_net_kg'];
        $allowed = max((float)Settings::get('ext_tol_kg', '50'), $ext * (float)Settings::get('ext_tol_pct', '1') / 100);
        return ['ext_kg' => $ext, 'factory_kg' => $fac, 'diff_kg' => $fac - $ext, 'allowed_kg' => $allowed, 'ok' => abs($fac - $ext) <= $allowed];
    }
}
