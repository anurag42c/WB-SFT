<?php
/**
 * Push a weighing slip issued by ANOTHER weighbridge (supplier's, public) to the gate entry. It becomes a completed ticket (source EXTERNAL).
 *   POST external_slips.php
 *   {"vehicle_no":"MH31AB1234","weighbridge":"Shiv Dharam Kanta","slip_no":"A-5521","gross_kg":38400,"tare_kg":14200,"net_kg":24200,
 *    "weighed_at":"2026-10-13 09:40:00","party":"Shree Balaji","material":"Iron Ore","direction":"INWARD","challan_no":"CH-77","driver":"Ramesh"}
 * Idempotent: posting the same (weighbridge, slip_no) again returns the first ticket with "duplicate": true (HTTP 200). A vehicle that is not at the gate yet is registered.
 * Needs "Allow API writes". Several slips: {"slips":[{...},{...}]} (all-or-nothing is NOT applied here: each slip gets its own result).
 */
require_once __DIR__ . '/../../../src/bootstrap.php';
Api::begin(true);
$b = Api::body();
$slips = isset($b['slips']) && is_array($b['slips']) ? $b['slips'] : [$b];
if (!$slips || count($slips) > 100) { Api::err(400, 'send one slip or {"slips":[...]} with up to 100'); }
$out = []; $bad = 0;
foreach ($slips as $i => $s) {
    try {
        if (!is_array($s)) { throw new RuntimeException('slip must be an object'); }
        $out[] = ['ok' => true] + GateEntries::externalSlip($s);
    } catch (Throwable $e) { $bad++; $out[] = ['ok' => false, 'index' => $i, 'error' => $e->getMessage()]; }
}
if (!isset($b['slips'])) { $r = $out[0]; $r['ok'] ? Api::out($r) : Api::err(422, $r['error']); }
Api::out(['ok' => $bad === 0, 'results' => $out], $bad === count($slips) ? 422 : 200);
