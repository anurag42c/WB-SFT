<?php
declare(strict_types=1);

/**
 * Sends VERIFIED documents to Oracle and/or SAP (Settings > Documents > "Send verified documents to").
 * Store-and-forward like the weighbridge tickets: PENDING -> SYNCED, FAILED is retried, and a document that changes
 * after it was sent (edited, re-matched, exception resolved) becomes PENDING again because its payload hash differs.
 */
final class DocSync
{
    public static function targets(): array
    {
        $t = (string)Settings::get('doc_targets', 'none');
        return ['oracle' => in_array($t, ['oracle', 'both'], true), 'sap' => in_array($t, ['sap', 'both'], true)];
    }

    /** Held back by policy: open HIGH exceptions must be resolved or waived first. */
    public static function held(array $doc): bool
    {
        return Settings::get('doc_send_policy', 'no_high') === 'no_high' && (int)$doc['exc_high'] > 0;
    }

    /** Recompute the payload hash and queue the document for (re)sending when it differs from what was sent. */
    public static function refreshDirty(int $docId): void
    {
        $d = Documents::get($docId);
        if (!$d) { return; }
        $t = self::targets();
        if ($d['status'] !== 'VERIFIED') {
            Db::q("UPDATE documents SET oracle_status = 'NA', sap_status = 'NA' WHERE id = ?", [$docId]);
            return;
        }
        $h = Documents::payloadHash($docId);
        $or = !$t['oracle'] ? 'NA' : (($d['oracle_hash'] === $h && $d['oracle_status'] === 'SYNCED') ? 'SYNCED' : (in_array($d['oracle_status'], ['FAILED'], true) ? 'FAILED' : 'PENDING'));
        $sp = !$t['sap'] ? 'NA' : (($d['sap_hash'] === $h && $d['sap_status'] === 'SYNCED') ? 'SYNCED' : (in_array($d['sap_status'], ['FAILED'], true) ? 'FAILED' : 'PENDING'));
        Db::q('UPDATE documents SET payload_hash = ?, oracle_status = ?, sap_status = ? WHERE id = ?', [$h, $or, $sp, $docId]);
    }

    /**
     * Send one document to the enabled targets. $force ignores the "hold documents with open HIGH exceptions" policy.
     * @return array{ok: bool, held: bool, messages: string[]}
     */
    public static function sendOne(int $docId, bool $force = false, ?OracleSync $ora = null): array
    {
        $d = Documents::get($docId);
        $res = ['ok' => true, 'held' => false, 'messages' => []];
        if (!$d || $d['status'] !== 'VERIFIED') { return ['ok' => false, 'held' => false, 'messages' => ['Only verified documents are sent.']]; }
        $t = self::targets();
        if (!$t['oracle'] && !$t['sap']) { return ['ok' => false, 'held' => false, 'messages' => ['No target is enabled (Setup > Documents).']]; }
        if (!$force && self::held($d)) { return ['ok' => false, 'held' => true, 'messages' => ['Held: resolve or waive the ' . $d['exc_high'] . ' open HIGH exception(s) first.']]; }
        self::refreshDirty($docId);
        $d = Documents::get($docId);
        $payload = Documents::payload($docId);
        $now = date('Y-m-d H:i:s');

        if ($t['oracle'] && $d['oracle_status'] !== 'SYNCED') {
            try {
                $o = $ora ?? new OracleSync(Settings::all());
                if (!$ora) { $o->connect(); }
                $o->pushDocument($payload);
                Db::q("UPDATE documents SET oracle_status = 'SYNCED', oracle_hash = ?, oracle_at = ?, oracle_error = NULL WHERE id = ?", [$d['payload_hash'], $now, $docId]);
                $res['messages'][] = 'Oracle: sent.';
            } catch (Throwable $e) {
                Db::q("UPDATE documents SET oracle_status = 'FAILED', oracle_error = ?, sync_tries = sync_tries + 1 WHERE id = ?", [mb_substr($e->getMessage(), 0, 400), $docId]);
                $res['ok'] = false; $res['messages'][] = 'Oracle: ' . $e->getMessage();
            }
        }
        if ($t['sap'] && $d['sap_status'] !== 'SYNCED') {
            $r = Sap::send($payload);
            if ($r['ok']) {
                Db::q("UPDATE documents SET sap_status = 'SYNCED', sap_hash = ?, sap_at = ?, sap_ref = ?, sap_error = NULL WHERE id = ?", [$d['payload_hash'], $now, $r['ref'], $docId]);
                $res['messages'][] = 'SAP: ' . $r['message'];
            } else {
                Db::q("UPDATE documents SET sap_status = 'FAILED', sap_error = ?, sync_tries = sync_tries + 1 WHERE id = ?", [mb_substr($r['message'], 0, 400), $docId]);
                $res['ok'] = false; $res['messages'][] = 'SAP: ' . $r['message'];
            }
        }
        return $res;
    }

    /** Background job: send every pending/failed verified document. @return array{sent: int, failed: int, held: int, error: ?string} */
    public static function syncPending(int $limit = 50): array
    {
        $out = ['sent' => 0, 'failed' => 0, 'held' => 0, 'error' => null];
        $t = self::targets();
        if (!$t['oracle'] && !$t['sap']) { return $out; }
        foreach (Db::all("SELECT id FROM documents WHERE status = 'VERIFIED' AND payload_hash IS NOT NULL LIMIT 500") as $r) { self::refreshDirty((int)$r['id']); }   // pick up edits
        $rows = Db::all("SELECT * FROM documents WHERE status = 'VERIFIED' AND (oracle_status IN ('PENDING','FAILED') OR sap_status IN ('PENDING','FAILED')) ORDER BY id LIMIT ?", [$limit]);
        if (!$rows) { return $out; }
        $ora = null;
        if ($t['oracle']) {
            try { $ora = new OracleSync(Settings::all()); $ora->connect(); }
            catch (Throwable $e) { $out['error'] = $e->getMessage(); $ora = null; }
        }
        foreach ($rows as $d) {
            if (self::held($d)) { $out['held']++; continue; }
            if ($t['oracle'] && !$ora && $d['oracle_status'] !== 'SYNCED') {     // Oracle down: leave PENDING, but still try SAP below
                Db::q('UPDATE documents SET oracle_error = ?, sync_tries = sync_tries + 1 WHERE id = ?', [mb_substr((string)$out['error'], 0, 400), $d['id']]);
                if (!$t['sap']) { $out['failed']++; continue; }
            }
            $r = self::sendOne((int)$d['id'], false, $ora);
            if ($r['ok']) { $out['sent']++; } elseif (!$r['held']) { $out['failed']++; $out['error'] = end($r['messages']) ?: $out['error']; }
        }
        return $out;
    }
}
