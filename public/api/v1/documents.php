<?php
/**
 * Invoice / material-return documents with match result and exceptions.
 *   GET documents.php?since_id=0&status=VERIFIED&match=EXCEPTION&type=PO_INVOICE&limit=100    list (summary)
 *   GET documents.php?doc_no=DOC202609290001                                                   one document in full (same JSON as sent to SAP)
 */
require_once __DIR__ . '/../../../src/bootstrap.php';
Api::begin();
if (isset($_GET['doc_no'])) {
    $d = Db::one('SELECT id FROM documents WHERE doc_no = ?', [trim((string)$_GET['doc_no'])]);
    $d ? Api::out(['document' => Documents::payload((int)$d['id'])]) : Api::err(404, 'document not found');
}
$where = ['id > ?']; $args = [max(0, (int)($_GET['since_id'] ?? 0))];
foreach (['status' => ['UPLOADED', 'EXTRACTED', 'VERIFIED', 'CANCELLED'], 'match' => ['UNMATCHED', 'MATCHED', 'REVIEW', 'EXCEPTION'], 'type' => array_keys(Documents::TYPES)] as $q => $allowed) {
    $col = ['status' => 'status', 'match' => 'match_status', 'type' => 'doc_type'][$q];
    if (in_array($_GET[$q] ?? '', $allowed, true)) { $where[] = "$col = ?"; $args[] = $_GET[$q]; }
}
$args[] = Api::limit();
$rows = Db::all('SELECT id, doc_no, doc_type, status, invoice_no, invoice_date, supplier, po_no, total_amount, match_status, exc_high, exc_warn, wb_tickets, wb_net_kg, verified_at FROM documents WHERE ' . implode(' AND ', $where) . ' ORDER BY id LIMIT ?', $args);
Api::out(['count' => count($rows), 'next_since_id' => $rows ? (int)end($rows)['id'] : (int)$args[0], 'documents' => array_map(function ($r) { $id = (int)$r['id']; unset($r['id']); return $r + ['id' => $id]; }, $rows)]);
