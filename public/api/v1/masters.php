<?php
/**
 * Master data the operator picks from.
 *   GET  masters.php?type=parties|materials|vehicles
 *   POST masters.php  {"type":"parties","items":[{"name":"ABC Traders","active":true}]}
 *        {"type":"vehicles","items":[{"reg_no":"MH31AB1234","tare_kg":9000,"transporter":"XYZ","blocked":false}]}   (upsert; needs "Allow API writes")
 */
require_once __DIR__ . '/../../../src/bootstrap.php';
$post = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
Api::begin($post);
$b = $post ? Api::body() : $_GET;
$type = (string)($b['type'] ?? '');
if (!in_array($type, ['parties', 'materials', 'vehicles'], true)) { Api::err(400, 'type must be parties, materials or vehicles'); }
if (!$post) {
    $cols = $type === 'vehicles' ? 'reg_no, tare_kg, transporter, active, blocked, block_reason' : 'name, active';
    Api::out(['type' => $type, 'items' => Db::all("SELECT $cols FROM $type ORDER BY id")]);
}
$items = $b['items'] ?? null;
if (!is_array($items) || !$items || count($items) > 5000) { Api::err(400, 'items: 1-5000 entries expected'); }
$pdo = Db::pdo(); $pdo->exec('BEGIN IMMEDIATE'); $n = 0;
try {
    foreach ($items as $i => $it) {
        if (!is_array($it)) { throw new InvalidArgumentException("items[$i] must be an object"); }
        $act = array_key_exists('active', $it) ? ($it['active'] ? 1 : 0) : 1;
        if ($type === 'vehicles') {
            $reg = strtoupper(preg_replace('/\s+/', '', trim((string)($it['reg_no'] ?? ''))) ?? '');
            if ($reg === '' || strlen($reg) > 20) { throw new InvalidArgumentException("items[$i]: reg_no is required"); }
            $tare = isset($it['tare_kg']) ? Documents::num($it['tare_kg']) : null;
            Db::q('INSERT INTO vehicles(reg_no, tare_kg, transporter, active, blocked, block_reason) VALUES (?,?,?,?,?,?)
                   ON CONFLICT(reg_no) DO UPDATE SET tare_kg = COALESCE(excluded.tare_kg, tare_kg), transporter = COALESCE(excluded.transporter, transporter),
                   active = excluded.active, blocked = excluded.blocked, block_reason = excluded.block_reason',
                [$reg, $tare, ($it['transporter'] ?? null) !== null ? trim((string)$it['transporter']) : null, $act, !empty($it['blocked']) ? 1 : 0, !empty($it['blocked']) ? trim((string)($it['block_reason'] ?? '')) : null]);
        } else {
            $nm = trim((string)($it['name'] ?? ''));
            if ($nm === '' || mb_strlen($nm) > 120) { throw new InvalidArgumentException("items[$i]: name is required"); }
            Db::q("INSERT INTO $type(name, active) VALUES (?,?) ON CONFLICT(name) DO UPDATE SET active = excluded.active", [$nm, $act]);
        }
        $n++;
    }
    $pdo->exec('COMMIT');
} catch (InvalidArgumentException $e) { $pdo->exec('ROLLBACK'); Api::err(422, $e->getMessage()); }
catch (Throwable $e) { if ($pdo->inTransaction()) { $pdo->exec('ROLLBACK'); } Api::err(500, 'could not store'); }
Db::audit('api_masters', "$type $n");
Api::out(['ok' => true, 'type' => $type, 'upserted' => $n]);
