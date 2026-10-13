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
}
