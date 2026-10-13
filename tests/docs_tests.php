<?php
declare(strict_types=1);
// Included by run.php: invoice / material-return documents (OCR, matching, exceptions, SAP / Oracle sending).

$fx = __DIR__ . '/fixtures';
Settings::set('company_name', 'Sunrise Steel Pvt Ltd');
$asAdmin = fn() => $_SESSION['user'] = ['id' => 1, 'username' => 'admin', 'role' => 'admin'];
$asOp = fn() => $_SESSION['user'] = ['id' => 2, 'username' => 'op', 'role' => 'operator'];
$asOp();

echo "Documents: parsing helpers\n";
t('num: Indian grouping', Documents::num('1,23,456.78') === 123456.78 && Documents::num('Rs. 1,000') === 1000.0);
t('num: european and decimal comma', Documents::num('1.234,56') === 1234.56 && Documents::num('12,5') === 12.5);
t('num: negative and junk', Documents::num('(500)') === -500.0 && Documents::num('abc') === null && Documents::num('') === null && Documents::num(null) === null);
t('date: common printed formats', Documents::date('15-09-2026') === '2026-09-15' && Documents::date('15/09/26') === '2026-09-15' && Documents::date('2026-09-15') === '2026-09-15'
  && Documents::date('16 Sep 2026') === '2026-09-16' && Documents::date('September 16, 2026') === '2026-09-16' && Documents::date('5-Sept-2026') === '2026-09-05');
t('date: impossible dates refused, unambiguous swap', Documents::date('31/02/2026') === null && Documents::date('09/15/2026') === '2026-09-15' && Documents::date('hello') === null);
Settings::set('doc_date_order', 'MDY'); t('date: month-first setting', Documents::date('03/04/2026') === '2026-03-04'); Settings::set('doc_date_order', 'DMY');
t('date: day-first default', Documents::date('03/04/2026') === '2026-04-03');
t('uom aliases', Documents::uom('MTS') === 'MT' && Documents::uom('Tonnes') === 'MT' && Documents::uom('kgs.') === 'KG' && Documents::uom('gm') === 'G' && Documents::uom('') === null);
t('qtyKg conversions', Documents::qtyKg(28.5, 'MT') === 28500.0 && Documents::qtyKg(2.0, 'qtl') === 200.0 && Documents::qtyKg(500.0, 'g') === 0.5 && Documents::qtyKg(3.0, 'NOS') === null && Documents::qtyKg(null, 'MT') === null);
t('sim: same company different suffix', Documents::sim('Shree Balaji Minerals Pvt Ltd', 'SHREE BALAJI MINERALS PRIVATE LIMITED') === 1.0);
t('sim: short name inside long name', Documents::sim('ACME', 'ACME Coal Traders') >= 0.8 && Documents::sim('Kiran Logistics', 'Sunrise Steel') < 0.3 && Documents::sim('', 'x') === 0.0);

echo "Documents: offline OCR reader on real Tesseract output\n";
$txtA = (string)file_get_contents("$fx/invoice_a.txt"); $txtB = (string)file_get_contents("$fx/invoice_b.txt"); $txtC = (string)file_get_contents("$fx/return_c.txt");
$a = OcrParser::parse($txtA, 'PO_INVOICE');
t('invoice A: number, date, PO, vehicle, e-way', $a['invoice_no'] === 'BM/2026-27/0451' && $a['invoice_date'] === '2026-09-15' && $a['po_no'] === '4500012345' && $a['vehicle_no'] === 'MH31AB1234' && $a['eway_bill_no'] === '481234567890', json_encode($a));
t('invoice A: supplier, GSTIN, buyer', $a['supplier_name'] === 'SHREE BALAJI MINERALS PVT LTD' && $a['supplier_tax_id'] === '27AABCS1234F1Z5' && $a['buyer_name'] === 'Sunrise Steel Pvt Ltd');
t('invoice A: totals reconcile (sub + CGST + SGST = total)', $a['subtotal'] === 139650.0 && $a['tax_amount'] === 25137.0 && $a['total_amount'] === 164787.0 && $a['currency'] === 'INR');
t('invoice A: two line items with HSN', count($a['lines']) === 2 && $a['lines'][0]['description'] === 'Iron Ore Fines 62% Fe' && $a['lines'][0]['hsn'] === '2601' && $a['lines'][0]['qty'] === 28.5 && $a['lines'][0]['rate'] === 4850.0 && $a['lines'][0]['amount'] === 138225.0);
t('invoice A: unit lost by OCR is flagged, not invented', $a['lines'][0]['uom'] === null && (bool)array_filter($a['warnings'], fn($w) => str_contains($w, 'unit')));
$b = OcrParser::parse($txtB, 'PO_INVOICE');
t('invoice B (different layout): bill no, date, PO, truck, buyer', $b['invoice_no'] === 'KL-7788' && $b['invoice_date'] === '2026-09-16' && $b['po_no'] === 'PO-2026/778' && $b['vehicle_no'] === 'MH40CD5678' && str_contains((string)$b['buyer_name'], 'Sunrise'));
t('invoice B: supplier from first line, total, 1 line', $b['supplier_name'] === 'Kiran Logistics & Traders' && $b['total_amount'] === 34524.0 && count($b['lines']) === 1 && $b['lines'][0]['qty'] === 27.4);
$c = OcrParser::parse($txtC, 'MATERIAL_RETURN');
t('return C: own number, ORIGINAL invoice kept apart', $c['invoice_no'] === 'RC/0098' && $c['orig_invoice_no'] === 'BM/2026-27/0451' && $c['invoice_date'] === '2026-09-18');
t('return C: counterparty is "returned to", not our own company', $c['supplier_name'] === 'Shree Balaji Minerals Pvt Ltd' && $c['vehicle_no'] === 'MH31AB1234' && $c['total_amount'] === 19400.0 && $c['lines'][0]['qty'] === 4.0);
$nz = OcrParser::parse("smudge ### 12\n???", 'PO_INVOICE');
t('unreadable text: nothing invented, warnings listed, low confidence', $nz["invoice_no"] === null && !$nz["lines"] && count($nz["warnings"]) >= 4 && $nz["confidence"] < 0.4);
$wu = OcrParser::parse("Invoice No: X-1\nDate: 01/09/2026\nQuantities in MT\nSteel Coil 10.000 500.00 5,000.00\nTotal Amount 5,000.00", 'PO_INVOICE');
t('unit found elsewhere on the page is used but flagged as assumed', $wu['lines'][0]['uom'] === 'MT' && (bool)array_filter($wu['warnings'], fn($w) => str_contains($w, 'assumed')));
$bad = OcrParser::parse("Invoice No: X-2\nWidget 10.000 KG 100.00 5,000.00\nTotal Amount 5,000.00", 'PO_INVOICE');
t('quantity x rate that does not equal the amount is flagged', (bool)array_filter($bad['warnings'], fn($w) => str_contains($w, 'does not equal')));

$tessBin = trim((string)shell_exec('command -v tesseract 2>/dev/null'));
if ($tessBin !== '') {
    Settings::set('ocr_provider', 'tesseract'); Settings::set('ocr_tesseract_cmd', 'tesseract {image} stdout -l eng --psm 6');
    $r = Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE');
    t('REAL Tesseract on the invoice picture -> structured data', $r['ok'] && $r['data']['invoice_no'] === 'BM/2026-27/0451' && $r['data']['total_amount'] === 164787.0 && count($r['data']['lines']) === 2, json_encode($r['error'] ?? $r['data']['invoice_no']));
    t('REAL Tesseract: first letter of the first item is not lost (preprocessing regression)', ($r0 = Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE')) && $r0['data']['lines'][0]['description'] === 'Iron Ore Fines 62% Fe');
    if (function_exists('imagecreatefrompng')) {      // a poor scan: small and heavily JPEG-compressed
        $src = imagecreatefrompng("$fx/invoice_a.png"); $sm = imagescale($src, 1000, (int)(imagesy($src) * 1000 / imagesx($src))); imagejpeg($sm, "$tmp/poor.jpg", 55);
        $rp = Ocr::extract("$tmp/poor.jpg", 'image/jpeg', 'PO_INVOICE');
        t('REAL Tesseract on a poor (1000 px, JPEG q55) scan still gets number, date and total', $rp['ok'] && $rp['data']['invoice_no'] === 'BM/2026-27/0451' && $rp['data']['invoice_date'] === '2026-09-15' && $rp['data']['total_amount'] === 164787.0, json_encode($rp['data']['invoice_no'] ?? $rp['error']));
    }
    $r = Ocr::extract("$fx/return_c.png", 'image/png', 'MATERIAL_RETURN');
    t('REAL Tesseract on the return challan picture', $r['ok'] && $r['data']['orig_invoice_no'] === 'BM/2026-27/0451' && $r['data']['supplier_name'] === 'Shree Balaji Minerals Pvt Ltd');
    file_put_contents("$tmp/blank.png", base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $r = Ocr::extract("$tmp/blank.png", 'image/png', 'PO_INVOICE');
    t('picture with no text -> clear error', !$r['ok'] && str_contains((string)$r['error'], 'No text'));
    Settings::set('ocr_tesseract_cmd', '/nonexistent/tesseract {image}');
    $r = Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE');
    t('Tesseract missing -> clear error', !$r['ok'] && str_contains((string)$r['error'], 'Tesseract'));
    Settings::set('ocr_tesseract_cmd', 'tesseract {image} stdout -l eng --psm 6');
} else { echo "  skip real Tesseract tests (not installed)\n"; }

$oldMem = ini_get('memory_limit'); ini_set('memory_limit', '128M');
t('memory guard (128M limit): a normal photo can be decoded, a monster cannot', Ocr::canDecode(1600, 1200) && !Ocr::canDecode(30000, 30000));
t('memory guard: tries to raise the limit for a big phone photo (8000x6000)', Ocr::canDecode(8000, 6000) && ini_get('memory_limit') === '512M');
ini_set('memory_limit', $oldMem);
t('memory guard: unlimited memory means yes', (function () { $o = ini_get('memory_limit'); ini_set('memory_limit', '-1'); $r = Ocr::canDecode(30000, 3000); ini_set('memory_limit', $o); return $r; })());
echo "Documents: Claude vision client (mock API)\n";
$claudeLog = "$tmp/claude.log"; @unlink($claudeLog);
$lastReq = function () use ($claudeLog) { $l = file($claudeLog, FILE_IGNORE_NEW_LINES) ?: []; return $l ? json_decode(end($l), true) : null; };
Settings::set('ocr_provider', 'claude'); Settings::set('ocr_claude_url', mockUrl('/v1/messages')); Settings::set('ocr_claude_key', 'goodkey');
Settings::reset();
$r = Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE'); $req = $lastReq();
t('Claude: structured data returned', $r['ok'] && $r['data']['invoice_no'] === 'BM/2026-27/0451' && count($r['data']['lines']) === 2 && $r['data']['confidence'] === 0.97, json_encode($r['error']));
t('Claude request: key, API version, JSON', $req['key'] === 'goodkey' && $req['version'] === '2023-06-01' && str_contains((string)$req['ctype'], 'application/json'));
t('Claude request: current model, 16000 tokens, no removed params', $req['model'] === 'claude-opus-5-5' && $req['max_tokens'] === 16000 && $req['thinking'] === 'absent' && $req['temperature'] === false && $req['tool_choice'] === false);
t('Claude request: JSON-schema structured output + effort', $req['output_config']['format'] === 'json_schema' && $req['output_config']['effort'] === 'medium' && in_array('lines', $req['output_config']['required'], true) && in_array('po_no', $req['output_config']['required'], true));
t('Claude request: picture block first, then the instructions', $req['blocks'][0]['type'] === 'image' && $req['blocks'][0]['media_type'] === 'image/jpeg' && $req['blocks'][0]['data_len'] > 1000
   && $req['blocks'][1]['type'] === 'text' && str_contains($req['blocks'][1]['text'], 'PURCHASE INVOICE') && str_contains($req['blocks'][1]['text'], 'YYYY-MM-DD'));
Ocr::extract("$fx/return_c.png", 'image/png', 'MATERIAL_RETURN'); $req = $lastReq();
t('Claude request: return documents get the return instructions', str_contains($req['blocks'][1]['text'], 'MATERIAL RETURN') && str_contains($req['blocks'][1]['text'], 'orig_invoice_no'));
if (function_exists('imagecreatetruecolor')) {
    $big = imagecreatetruecolor(4200, 3000); imagefill($big, 0, 0, imagecolorallocate($big, 250, 250, 250)); imagepng($big, "$tmp/big.png");
    Ocr::extract("$tmp/big.png", 'image/png', 'PO_INVOICE'); $req = $lastReq();
    t('large pictures are scaled down before upload', max($req['blocks'][0]['w'], $req['blocks'][0]['h']) <= 2000 && $req['blocks'][0]['w'] > 1000);
}
file_put_contents("$tmp/t.pdf", "%PDF-1.4\n1 0 obj<</Type/Catalog>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n");
Ocr::extract("$tmp/t.pdf", 'application/pdf', 'PO_INVOICE'); $req = $lastReq();
t('Claude request: PDF sent as a document block', $req['blocks'][0]['type'] === 'document' && $req['blocks'][0]['media_type'] === 'application/pdf');
Settings::set('ocr_claude_model', 'claude-sonnet-5-5'); Settings::set('ocr_effort', 'low'); Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE'); $req = $lastReq();
t('model and effort come from Setup', $req['model'] === 'claude-sonnet-5-5' && $req['output_config']['effort'] === 'low');
Settings::set('ocr_claude_model', 'claude-opus-5-5'); Settings::set('ocr_effort', 'medium');
foreach (['bad' => 'rejected', 'limited' => 'Rate limited', 'busy' => 'busy', 'refuse' => 'declined', 'trunc' => 'too long', 'garbage' => 'unreadable'] as $k => $needle) {
    Settings::set('ocr_claude_key', $k); $r = Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE');
    t("Claude error '$k' -> operator-friendly message", !$r['ok'] && str_contains((string)$r['error'], $needle), (string)$r['error']);
}
Settings::set('ocr_claude_key', ''); t('no API key -> asks for it', str_contains((string)Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE')['error'], 'API key'));
Settings::set('ocr_claude_key', 'goodkey'); Settings::set('ocr_claude_url', 'http://127.0.0.1:1/v1/messages');
t('API unreachable -> clear error', str_contains((string)Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE')['error'], 'Cannot reach'));
Settings::set('ocr_claude_url', 'ftp://x/'); t('non-http API URL refused', str_contains((string)Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE')['error'], 'https'));
Settings::set('ocr_claude_url', mockUrl('/v1/messages'));
Settings::set('ocr_provider', 'off'); t('OCR off -> explains', str_contains((string)Ocr::extract("$fx/invoice_a.png", 'image/png', 'PO_INVOICE')['error'], 'switched off'));
Settings::set('ocr_provider', 'claude');

echo "Documents: upload and review\n";
$id = Documents::create("$fx/invoice_a.png", 'scan 001.png', 'PO_INVOICE', 'PO-UP1');
$d = Documents::get($id);
t('upload stores a private copy and numbers the document', $d['status'] === 'UPLOADED' && (bool)preg_match('/^DOC\d{8}0001$/', $d['doc_no']) && $d['po_no'] === 'PO-UP1' && $d['mime'] === 'image/png' && is_file(Documents::path($d)) && str_starts_with($d['file'], 'doc_'));
$r = Documents::extract($id); $d = Documents::get($id);
t('reading fills the record and lines', $r['ok'] && $d['status'] === 'EXTRACTED' && $d['invoice_no'] === 'BM/2026-27/0451' && $d['vehicle_no'] === 'MH31AB1234' && $d['ocr_provider'] === 'claude' && count(Documents::lines($id)) === 2);
t('the PO typed at upload is replaced by the one on the picture', $d['po_no'] === '4500012345');
t('weight units and kg are derived for lines', Documents::lines($id)[0]['uom'] === 'MT' && Documents::lines($id)[0]['qty_kg'] === 28500.0);
Settings::set('ocr_claude_key', 'bad'); $r = Documents::extract($id); $d = Documents::get($id);
t('a failed re-read keeps the data and records the error', !$r['ok'] && $d['invoice_no'] === 'BM/2026-27/0451' && str_contains((string)$d['ocr_error'], 'rejected'));
Settings::set('ocr_claude_key', 'goodkey');
foreach ([['not an image', 'Only JPEG, PNG or PDF'], ['', 'empty']] as [$body, $needle]) {
    file_put_contents("$tmp/x.bin", $body);
    t("upload refused: $needle", str_contains((string)throws(fn() => Documents::create("$tmp/x.bin", 'x.txt', 'PO_INVOICE')), $needle));
}
file_put_contents("$tmp/corrupt.png", "\x89PNG\r\n\x1a\n" . str_repeat("\0", 40));
t('upload refused: corrupt picture', throws(fn() => Documents::create("$tmp/corrupt.png", 'c.png', 'PO_INVOICE')) !== null);
Settings::set('doc_max_mb', '1'); file_put_contents("$tmp/huge.png", file_get_contents("$fx/invoice_a.png") . str_repeat("\0", 1200000));
t('upload refused: too large', str_contains((string)throws(fn() => Documents::create("$tmp/huge.png", 'h.png', 'PO_INVOICE')), 'larger than 1 MB')); Settings::set('doc_max_mb', '10');
t('upload refused: no tag', str_contains((string)throws(fn() => Documents::create("$fx/invoice_a.png", 'a.png', 'SOMETHING')), 'Choose'));
t('verify needs invoice no, supplier and lines', str_contains((string)throws(function () use ($tmp, $fx) { $i = Documents::create("$fx/invoice_a.png", 'a.png', 'PO_INVOICE'); Documents::verify($i); }), 'Fill in'));
Documents::save($id, ['doc_type' => 'PO_INVOICE', 'invoice_no' => ' BM/2026-27/0451 ', 'invoice_date' => '15/09/2026', 'supplier' => 'Shree Balaji Minerals Pvt Ltd', 'po_no' => '4500012345', 'vehicle_no' => 'mh 31 ab 1234',
    'subtotal' => '1,39,650.00', 'tax_amount' => '25137', 'total_amount' => '164,787.00', 'line' => [['description' => 'Iron Ore Fines 62% Fe', 'qty' => '28.500', 'uom' => 'mts', 'rate' => '4,850.00', 'amount' => '1,38,225.00'], ['description' => '', 'qty' => '']]]);
$d = Documents::get($id);
t('saving cleans up what a person typed (dates, numbers, units, vehicle, blank rows)', $d['invoice_no'] === 'BM/2026-27/0451' && $d['invoice_date'] === '2026-09-15' && $d['vehicle_no'] === 'MH31AB1234' && $d['total_amount'] === 164787.0 && count(Documents::lines($id)) === 1 && Documents::lines($id)[0]['uom'] === 'MT');
Documents::verify($id);
t('verified: locked against edits and re-reading', Documents::get($id)['status'] === 'VERIFIED' && str_contains((string)throws(fn() => Documents::save($id, ['invoice_no' => 'X'])), 'Reopen') && str_contains((string)throws(fn() => Documents::extract($id)), 'verified'));
Documents::reopen($id); t('reopen allows editing again', Documents::get($id)['status'] === 'EXTRACTED');

echo "Documents: matching with the weighbridge\n";
function mkTicket(string $veh, string $party, string $mat, string $dir, ?float $net, int $daysAgo = 0, string $status = 'CLOSED', string $challan = ''): int
{
    static $n = 0; $ts = date('Y-m-d H:i:s', strtotime("-$daysAgo days"));
    Db::q('INSERT INTO weighments(ticket_no, vehicle_no, party, material, direction, challan_no, first_type, first_kg, first_at, second_kg, second_at, gross_kg, tare_kg, net_kg, status, created_at, scale_id)
           VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)', ['TDOC' . (++$n), $veh, $party, $mat, $dir, $challan, 'GROSS', $net ? $net + 9000 : 30000, $ts, $status === 'OPEN' ? null : 9000, $status === 'OPEN' ? null : $ts,
           $net ? $net + 9000 : null, $net ? 9000 : null, $net, $status, $ts]);
    return Db::id();
}
function mkDoc(array $h, array $lines, string $type = 'PO_INVOICE'): int
{
    static $n = 0;
    Db::q("INSERT INTO documents(doc_no, doc_type, status, uploaded_by, uploaded_at, updated_at) VALUES (?,?, 'EXTRACTED', 'test', ?, ?)", ['DOCT' . (++$n), $type, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $id = Db::id();
    $h += ['invoice_date' => date('Y-m-d'), 'supplier_name' => 'Shree Balaji Minerals Pvt Ltd', 'po_no' => 'PO-DEF', 'lines' => $lines];
    Documents::applyExtraction($id, $h, ['provider' => 'test']);
    return $id;
}
$iron = fn(float $q = 28.5, string $u = 'MT') => ['description' => 'Iron Ore Fines 62% Fe', 'qty' => $q, 'uom' => $u, 'rate' => 4850, 'amount' => $q * 4850, 'hsn' => '2601'];
$charges = ['description' => 'Loading and Handling Charges', 'qty' => 28.5, 'uom' => 'MT', 'rate' => 50, 'amount' => 1425, 'hsn' => '9967'];
$codes = fn(int $docId, string $st = 'OPEN') => array_column(Db::all('SELECT code FROM doc_exceptions WHERE doc_id = ? AND status = ? ORDER BY code', [$docId, $st]), 'code');
$doc = fn(int $docId) => Documents::get($docId);
Settings::set('doc_qty_tol_pct', '2'); Settings::set('doc_match_days', '5');

$t1 = mkTicket('MH31DOC0001', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28450);
$d1 = mkDoc(['invoice_no' => 'INV-S1', 'vehicle_no' => 'MH 31 DOC 0001', 'po_no' => 'PO-S1'], [$iron(), $charges]);
$res = DocMatch::run($d1);
t('perfect match: ticket linked automatically, MATCHED, no HIGH/WARN', $res['match_status'] === 'MATCHED' && $res['high'] === 0 && $res['warn'] === 0 && array_column(DocMatch::linkedTickets($d1), 'id') === [$t1], json_encode($codes($d1)));
t('service/charge lines are not counted as extra goods (28.5 MT invoice vs 28,450 kg ticket is within 2%)', !in_array('QTY_MISMATCH', $codes($d1), true) && $doc($d1)['wb_net_kg'] === 28450.0 && $doc($d1)['wb_tickets'] === 'TDOC1');
t('no PO list loaded -> PO is noted as not verified (INFO only)', Db::val("SELECT severity FROM doc_exceptions WHERE doc_id = ? AND code = 'PO_NOT_CHECKED'", [$d1]) === 'INFO');

$t2 = mkTicket('MH31DOC0002', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 25000);
$d2 = mkDoc(['invoice_no' => 'INV-S2', 'vehicle_no' => 'MH31DOC0002'], [$iron()]);
$res = DocMatch::run($d2); $qm = Db::val("SELECT message FROM doc_exceptions WHERE doc_id = ? AND code = 'QTY_MISMATCH'", [$d2]);
t('quantity mismatch: HIGH, EXCEPTION, message shows both numbers', in_array('QTY_MISMATCH', $codes($d2), true) && $res['match_status'] === 'EXCEPTION' && str_contains((string)$qm, '28,500') && str_contains((string)$qm, '25,000') && str_contains((string)$qm, '+3500'), (string)$qm);
Settings::set('doc_qty_tol_pct', '20'); DocMatch::run($d2);
t('tolerance is a setting: 14% difference passes at 20%', !in_array('QTY_MISMATCH', $codes($d2), true)); Settings::set('doc_qty_tol_pct', '2'); DocMatch::run($d2);

$d3 = mkDoc(['invoice_no' => 'INV-S3', 'vehicle_no' => 'MH31DOC0003'], [$iron()]);
$res = DocMatch::run($d3);
t('goods never weighed -> NO_TICKET HIGH, UNMATCHED', $res['match_status'] === 'UNMATCHED' && $codes($d3) === ['NO_TICKET'] || in_array('NO_TICKET', $codes($d3), true));
$d3b = mkDoc(['invoice_no' => 'INV-S3B', 'vehicle_no' => null], [$iron()]); DocMatch::run($d3b);
t('document without vehicle no: NO_TICKET says to link by hand', str_contains((string)Db::val("SELECT message FROM doc_exceptions WHERE doc_id = ? AND code = 'NO_TICKET'", [$d3b]), 'link a ticket by hand'));

$t4 = mkTicket('MH31DOC0004', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28500, 0, 'CLOSED', 'INV-S4');
$d4 = mkDoc(['invoice_no' => 'INV-S4', 'vehicle_no' => 'MH31DOC9999'], [$iron()]); DocMatch::run($d4);
t('challan number = invoice number links the ticket even when the vehicle differs', array_column(DocMatch::linkedTickets($d4), 'id') === [$t4] && in_array('VEHICLE_MISMATCH', $codes($d4), true));

$tn1 = mkTicket('MH31DOC0X01', 'Somebody Else', 'Sand', 'INWARD', 5000);
$dn1 = mkDoc(['invoice_no' => 'INV-NEAR', 'vehicle_no' => 'MH31DOC0X02'], [$iron()]); DocMatch::run($dn1);
t('a vehicle number one character off is NOT enough on its own to take a ticket', in_array('NO_TICKET', $codes($dn1), true));
$tn2 = mkTicket('MH31DOC0Y01', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28480);
$dn2 = mkDoc(['invoice_no' => 'INV-NEAR2', 'vehicle_no' => 'MH31DOC0Y02'], [$iron()]); DocMatch::run($dn2);
t('...but one character off plus matching supplier, material and weight is linked, with a warning', array_column(DocMatch::linkedTickets($dn2), 'id') === [$tn2] && Db::val("SELECT severity FROM doc_exceptions WHERE doc_id = ? AND code = 'VEHICLE_MISMATCH'", [$dn2]) === 'WARN');
$t5 = mkTicket('MH31DOC0005', 'Totally Other Co', 'Sand', 'INWARD', 28500);
$d5 = mkDoc(['invoice_no' => 'INV-S5', 'vehicle_no' => 'MH31DOC0005'], [$iron()]); $res = DocMatch::run($d5);
t('different party and material: two warnings, status REVIEW (not EXCEPTION)', array_intersect(['PARTY_MISMATCH', 'MATERIAL_MISMATCH'], $codes($d5)) === ['PARTY_MISMATCH', 'MATERIAL_MISMATCH'] && $res['high'] === 0 && $res['match_status'] === 'REVIEW');

$t6 = mkTicket('MH31DOC0006', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28500, 8, 'CLOSED', 'INV-S6');
$d6 = mkDoc(['invoice_no' => 'INV-S6', 'vehicle_no' => 'MH31DOC0006'], [$iron()]); DocMatch::run($d6);
t('ticket 8 days from the document date -> DATE_MISMATCH warning', in_array('DATE_MISMATCH', $codes($d6), true));

$t7 = mkTicket('MH31DOC0007', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28500, 0, 'CANCELLED');
$d7 = mkDoc(['invoice_no' => 'INV-S7', 'vehicle_no' => 'MH31DOC0007'], [$iron()]); DocMatch::run($d7);
t('cancelled tickets are never candidates', in_array('NO_TICKET', $codes($d7), true));
DocMatch::link($d7, $t7); t('manually linked cancelled ticket -> TICKET_CANCELLED HIGH', in_array('TICKET_CANCELLED', $codes($d7), true));

$t8 = mkTicket('MH31DOC0008', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', null, 0, 'OPEN');
$d8 = mkDoc(['invoice_no' => 'INV-S8', 'vehicle_no' => 'MH31DOC0008'], [$iron()]); DocMatch::run($d8);
t('ticket with the second weighment pending -> TICKET_OPEN warning, no quantity complaint', in_array('TICKET_OPEN', $codes($d8), true) && !in_array('QTY_MISMATCH', $codes($d8), true));

$t9 = mkTicket('MH31DOC0009', 'Shree Balaji Minerals', 'Iron ore', 'OUTWARD', 28500);
$d9 = mkDoc(['invoice_no' => 'INV-S9', 'vehicle_no' => 'MH31DOC0009'], [$iron()]); DocMatch::run($d9);
t('purchase invoice never auto-links an OUTWARD ticket', in_array('NO_TICKET', $codes($d9), true));
DocMatch::link($d9, $t9); t('manual link to OUTWARD ticket -> DIRECTION_MISMATCH', in_array('DIRECTION_MISMATCH', $codes($d9), true));

$d10 = mkDoc(['invoice_no' => 'INV-S10', 'vehicle_no' => 'MH31DOC0001'], [$iron()]); DocMatch::run($d10);
t('a ticket already used by another document is not stolen automatically', in_array('NO_TICKET', $codes($d10), true));
DocMatch::link($d10, $t1); DocMatch::run($d1);
t('double billing: manual link to a used ticket -> TICKET_MULTI_DOC on both documents', in_array('TICKET_MULTI_DOC', $codes($d10), true) && in_array('TICKET_MULTI_DOC', $codes($d1), true));

$d11 = mkDoc(['invoice_no' => 'inv s1', 'vehicle_no' => 'MH31DOC7777'], [$iron()]); DocMatch::run($d11); DocMatch::run($d1);
t('same invoice number from the same supplier -> DUPLICATE_INVOICE (either direction)', in_array('DUPLICATE_INVOICE', $codes($d11), true) && in_array('DUPLICATE_INVOICE', $codes($d1), true));
$d11b = mkDoc(['invoice_no' => 'INV-S1', 'supplier_name' => 'Completely Different Traders', 'vehicle_no' => 'MH31DOC7778'], [$iron()]); DocMatch::run($d11b);
t('same number from a different supplier is not a duplicate', !in_array('DUPLICATE_INVOICE', $codes($d11b), true));

$ta = mkTicket('MH31DOC0012', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 15000); $tb = mkTicket('MH31DOC0012', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 13500);
$d12 = mkDoc(['invoice_no' => 'INV-S12', 'vehicle_no' => 'MH31DOC0012'], [$iron()]); $res = DocMatch::run($d12);
t('split load: two trips are linked until the invoice quantity is reached', count(DocMatch::linkedTickets($d12)) === 2 && !in_array('QTY_MISMATCH', $codes($d12), true) && $doc($d12)['wb_net_kg'] === 28500.0);

$t13 = mkTicket('MH31DOC0013', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28450);
$d13 = mkDoc(['invoice_no' => 'INV-S13', 'vehicle_no' => 'MH31DOC0013'], [['description' => 'Iron Ore Fines', 'qty' => 28.5, 'uom' => null, 'rate' => 4850, 'amount' => 138225]]); DocMatch::run($d13);
t('missing unit: warning that suggests MT from the weighbridge weight', str_contains((string)Db::val("SELECT message FROM doc_exceptions WHERE doc_id = ? AND code = 'UOM_MISSING'", [$d13]), 'suggests it is MT'));

$t14 = mkTicket('MH31DOC0014', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28450);
$d14 = mkDoc(['invoice_no' => 'INV-S14', 'vehicle_no' => 'MH31DOC0014'], [['description' => 'Iron Ore Fines', 'qty' => 570, 'uom' => 'NOS', 'rate' => 250, 'amount' => 142500]]); $res = DocMatch::run($d14);
t('non-weight units: quantity check skipped (INFO), not a false alarm', in_array('QTY_UNCHECKED', $codes($d14), true) && $res['high'] === 0);

DocMatch::unlink($d1, $t1); DocMatch::run($d1);
t('unlink sticks: the same ticket is not automatically put back', !in_array($t1, array_column(DocMatch::linkedTickets($d1), 'id')) && in_array('NO_TICKET', $codes($d1), true));
DocMatch::link($d1, $t1); t('manual link overrides the block', array_column(DocMatch::linkedTickets($d1), 'link') === ['MANUAL']);
$f = DocMatch::facts($d2);
t('facts for the screen: difference and tolerance', $f['ticket_kg'] === 25000.0 && $f['closest_kg'] === 28500.0 && $f['diff_kg'] === 3500.0 && $f['diff_pct'] === 14.0 && $f['ok'] === false);

echo "Documents: purchase orders and returns\n";
file_put_contents("$tmp/po.csv", "po_no,line_no,vendor,material,description,qty,uom,rate\nPO-P1,10,Shree Balaji Minerals Pvt Ltd,IRONORE62,Iron Ore Fines 62% Fe,50,MT,4850\nPO-P1,20,Shree Balaji Minerals Pvt Ltd,COKE,Metallurgical Coke,10,MT,30000\n,,,,,,,\nPO-P2,10,Kiran Logistics & Traders,LIME,Limestone 20-40mm,abc,MT,1200\n");
$imp = PurchaseOrders::importCsv("$tmp/po.csv");
t('CSV import: lines loaded, bad rows skipped', $imp === ['pos' => 1, 'lines' => 2, 'skipped' => 2], json_encode($imp));
t('PO lookup ignores case, dashes and spaces', count(PurchaseOrders::lines('po p1')) === 2 && count(PurchaseOrders::lines('POP1')) === 2 && PurchaseOrders::count() === 1);
file_put_contents("$tmp/po2.csv", "EBELN;EBELP;NAME1;MATNR;TXZ01;MENGE;MEINS;NETPR\n4500099999;00010;Test Vendor;M1;Cement OPC 53;1.234,5;KG;9,5\n");
$imp2 = PurchaseOrders::importCsv("$tmp/po2.csv"); $l = PurchaseOrders::lines('4500099999')[0];
t('CSV import: SAP column names, semicolons and decimal commas', $imp2['lines'] === 1 && $l['qty'] === 1234.5 && $l['vendor'] === 'Test Vendor' && $l['uom'] === 'KG' && $l['rate'] === 9.5);
PurchaseOrders::delete('4500099999');
file_put_contents("$tmp/bad.csv", "name,city\nx,y\n"); t('CSV import: unknown columns explained', str_contains((string)throws(fn() => PurchaseOrders::importCsv("$tmp/bad.csv")), 'column titles'));
$tp = mkTicket('MH31DOC0101', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28500);
$mk = fn(array $h, ?array $lines = null) => (function () use ($h, $lines, $iron) { $i = mkDoc($h + ['vehicle_no' => 'MH31DOC0101', 'invoice_no' => 'INV-P' . random_int(1000, 9999)], $lines ?? [$iron()]); DocMatch::run($i); return $i; })();
$dp = $mk(['po_no' => 'po p1']);
t('PO ok: vendor, item and quantity all agree -> no PO exceptions', array_filter($codes($dp), fn($c) => str_starts_with($c, 'PO_') && $c !== 'PO_NOT_CHECKED') === [], json_encode($codes($dp)));
t('PO missing on a purchase invoice -> HIGH', in_array('PO_MISSING', $codes($mk(['po_no' => null])), true));
t('PO not in the list -> PO_UNKNOWN HIGH', in_array('PO_UNKNOWN', $codes($mk(['po_no' => 'PO-NOPE'])), true));
t('invoice from someone who is not the PO vendor -> PO_VENDOR_MISMATCH HIGH', in_array('PO_VENDOR_MISMATCH', $codes($mk(['po_no' => 'PO-P1', 'supplier_name' => 'Rival Minerals Ltd'])), true));
$x = $mk(['po_no' => 'PO-P1'], [$iron(), ['description' => 'Sand 10mm', 'qty' => 5, 'uom' => 'MT', 'rate' => 900, 'amount' => 4500]]);
t('item that is not on the PO -> PO_LINE_UNKNOWN warning', in_array('PO_LINE_UNKNOWN', $codes($x), true));
$x = $mk(['po_no' => 'PO-P1'], [['description' => 'Iron Ore Fines 62% Fe', 'qty' => 10, 'uom' => 'MT', 'rate' => 5200, 'amount' => 52000]]);
t('rate different from the PO -> PO_RATE_MISMATCH warning', in_array('PO_RATE_MISMATCH', $codes($x), true));
$x = $mk(['po_no' => 'PO-P1'], [['description' => 'Something else entirely', 'material_code' => 'ironore62', 'qty' => 10, 'uom' => 'MT', 'rate' => 4850, 'amount' => 48500]]);
t('material code match works even when the description differs', !in_array('PO_LINE_UNKNOWN', $codes($x), true));
Db::q("DELETE FROM documents WHERE UPPER(REPLACE(REPLACE(po_no,'-',''),' ','')) = 'POP1'");   // clean slate for the cumulative check
$o1 = $mk(['po_no' => 'PO-P1'], [$iron(28.5)]); $o2 = $mk(['po_no' => 'PO-P1'], [$iron(28.5)]); DocMatch::run($o1);
t('PO ordered 50 MT, 28.5 + 28.5 invoiced -> PO_OVER_QTY HIGH on the invoices', in_array('PO_OVER_QTY', $codes($o2), true) && in_array('PO_OVER_QTY', $codes($o1), true));
Documents::cancel($o2, 'test'); DocMatch::run($o1);
t('cancelling one invoice frees the quantity again', !in_array('PO_OVER_QTY', $codes($o1), true) && Db::val('SELECT COUNT(*) FROM doc_exceptions WHERE doc_id = ?', [$o2]) == 0 && Db::val('SELECT COUNT(*) FROM document_tickets WHERE doc_id = ?', [$o2]) == 0);

// original invoice + returns
$orig = mkDoc(['invoice_no' => 'BM/RET-1', 'vehicle_no' => 'MH31DOC0201', 'po_no' => 'PO-P1'], [$iron(28.5)]);
$tr = mkTicket('MH31DOC0202', 'Shree Balaji Minerals', 'Iron ore', 'OUTWARD', 4000);
$mr = fn(array $h, ?array $lines = null) => (function () use ($h, $lines, $iron) { $i = mkDoc($h + ['vehicle_no' => 'MH31DOC0202', 'invoice_no' => 'RC-' . random_int(1000, 9999)], $lines ?? [$iron(4)], 'MATERIAL_RETURN'); DocMatch::run($i); return $i; })();
$r1 = $mr(['orig_invoice_no' => 'BM/RET-1']);
t('return: matches the OUTWARD ticket and the original invoice, no exceptions', array_column(DocMatch::linkedTickets($r1), 'id') === [$tr] && array_filter($codes($r1), fn($c) => $c !== 'PO_NOT_CHECKED') === [], json_encode($codes($r1)));
t('return quantity above the original invoice -> RETURN_OVER_QTY HIGH', in_array('RETURN_OVER_QTY', $codes($mr(['orig_invoice_no' => 'BM/RET-1'], [$iron(30)])), true));
t('return that names no invoice -> RETURN_NO_REF warning', in_array('RETURN_NO_REF', $codes($mr([])), true));
t('return of an invoice we do not have -> RETURN_ORIG_UNKNOWN warning', in_array('RETURN_ORIG_UNKNOWN', $codes($mr(['orig_invoice_no' => 'NOPE-1'])), true));
t('return to a different supplier than the invoice -> warning', in_array('RETURN_SUPPLIER_MISMATCH', $codes($mr(['orig_invoice_no' => 'BM/RET-1', 'supplier_name' => 'Somebody Else Ltd'])), true));
t('returned item not on the invoice -> warning', in_array('RETURN_LINE_UNKNOWN', $codes($mr(['orig_invoice_no' => 'BM/RET-1'], [['description' => 'Copper wire', 'qty' => 1, 'uom' => 'MT', 'rate' => 1, 'amount' => 1]])), true));
$ra = $mr(['orig_invoice_no' => 'BM/RET-1'], [$iron(20)]); $rb = $mr(['orig_invoice_no' => 'BM/RET-1'], [$iron(10)]); DocMatch::run($ra);
t('two returns 20 + 10 MT against 28.5 MT -> the total is caught', in_array('RETURN_OVER_QTY', $codes($rb), true) && in_array('RETURN_OVER_QTY', $codes($ra), true));
t('a return links only OUTWARD tickets', Db::val("SELECT direction FROM weighments WHERE id = ?", [$tr]) === 'OUTWARD' && in_array('DIRECTION_MISMATCH', $codes($mr(['vehicle_no' => 'MH31DOC0001', 'orig_invoice_no' => 'BM/RET-1'])), true) === false);

Db::q('DELETE FROM po_lines');     // later sections run without a PO list
echo "Documents: SAP purchase-order lookup\n";
Settings::set('sap_auth', 'basic'); Settings::set('sap_user', 'sapuser'); Settings::set('sap_pass', 'sappass'); Settings::set('sap_po_url', mockUrl('/sap/po/{po}'));
$po = Sap::fetchPo('4500012345');
t('OData v2 purchase order is normalised', $po && $po['vendor'] === 'Shree Balaji Minerals Pvt Ltd' && $po['po_date'] === '2026-09-01' && $po['lines'][0]['material_code'] === 'IRONORE62' && $po['lines'][0]['qty'] === 100.0 && $po['lines'][0]['uom'] === 'MT' && $po['lines'][0]['rate'] === 4850.0, json_encode($po));
$po = Sap::fetchPo('PO-2026/778');
t('simple JSON purchase order (PO with a slash is URL-encoded)', $po && $po['vendor'] === 'Kiran Logistics & Traders' && $po['lines'][0]['qty'] === 40.0);
t('unknown PO in SAP -> null', Sap::fetchPo('DOES-NOT-EXIST') === null);
Settings::set('sap_pass', 'wrong'); t('wrong SAP password on lookup -> clear error', str_contains((string)throws(fn() => Sap::fetchPo('4500012345')), 'login refused')); Settings::set('sap_pass', 'sappass');
$v4 = Sap::normalizePo(['value' => [['PurchaseOrder' => '4500', 'Supplier' => 'V4 Vendor', '_PurchaseOrderItem' => [['PurchaseOrderItem' => '10', 'Material' => 'M', 'OrderQuantity' => 5, 'PurchaseOrderQuantityUnit' => 'KG']]]]]);
t('OData v4 shape accepted', $v4 && $v4['vendor'] === 'V4 Vendor' && $v4['lines'][0]['qty'] === 5.0);
t('unrecognised shape -> null', Sap::normalizePo(['hello' => 'world']) === null);
$ds = mkDoc(['invoice_no' => 'INV-SAP1', 'vehicle_no' => 'MH31DOC0301', 'po_no' => '4500012345'], [$iron(28.5)]); mkTicket('MH31DOC0301', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28500); DocMatch::run($ds);
t('PO missing locally is fetched from SAP, remembered, and checks pass', !in_array('PO_UNKNOWN', $codes($ds), true) && (int)Db::val("SELECT COUNT(*) FROM po_lines WHERE source = 'SAP'") === 1 && !in_array('PO_VENDOR_MISMATCH', $codes($ds), true));
Settings::set('sap_po_url', ''); Db::q('DELETE FROM po_lines');

echo "Documents: exceptions handling\n";
$asOp();
t('resolving needs a note', str_contains((string)throws(function () use ($d2) { $e = Db::one("SELECT id FROM doc_exceptions WHERE doc_id = ? AND code = 'QTY_MISMATCH'", [$d2]); DocMatch::setExceptionStatus((int)$e['id'], 'RESOLVED', ' '); }), 'note'));
t('an operator cannot waive', str_contains((string)throws(function () use ($d2) { $e = Db::one("SELECT id FROM doc_exceptions WHERE doc_id = ? AND code = 'QTY_MISMATCH'", [$d2]); DocMatch::setExceptionStatus((int)$e['id'], 'WAIVED', 'ok'); }), 'administrator'));
$e = Db::one("SELECT id FROM doc_exceptions WHERE doc_id = ? AND code = 'QTY_MISMATCH'", [$d2]);
DocMatch::setExceptionStatus((int)$e['id'], 'RESOLVED', 'Supplier confirmed short load; credit note coming');
t('resolved: counts drop, status recorded with who and why', $doc($d2)['exc_high'] === 0 && Db::val('SELECT status FROM doc_exceptions WHERE id = ?', [$e['id']]) === 'RESOLVED' && Db::val('SELECT updated_by FROM doc_exceptions WHERE id = ?', [$e['id']]) === 'op');
DocMatch::run($d2);
t('re-matching keeps the decision instead of re-raising the exception', array_diff($codes($d2, 'OPEN'), ['PO_NOT_CHECKED']) === [] && in_array('QTY_MISMATCH', $codes($d2, 'RESOLVED'), true));
$asAdmin(); $e3 = Db::one("SELECT id FROM doc_exceptions WHERE doc_id = ? AND code = 'NO_TICKET'", [$d3]); DocMatch::setExceptionStatus((int)$e3['id'], 'WAIVED', 'Goods weighed at the other plant');
t('administrator can waive', Db::val('SELECT status FROM doc_exceptions WHERE id = ?', [$e3['id']]) === 'WAIVED' && $doc($d3)['exc_high'] === 0); $asOp();
Db::q("UPDATE document_lines SET qty = 28.45, qty_kg = 28450 WHERE doc_id = ?", [$d2]); Db::q("UPDATE weighments SET net_kg = 28450 WHERE id = ?", [$t2]); DocMatch::run($d2);
t('when the cause disappears the exception row is removed', Db::val('SELECT COUNT(*) FROM doc_exceptions WHERE doc_id = ? AND code = ?', [$d2, 'QTY_MISMATCH']) == 0);

echo "Documents: weighed but no invoice\n";
$to = mkTicket('MH31DOC0401', 'ACME Coal', 'Coal', 'INWARD', 20000, 3);
$tn = mkTicket('MH31DOC0402', 'ACME Coal', 'Coal', 'INWARD', 20000, 0);
$tout = mkTicket('MH31DOC0403', 'ACME Coal', 'Coal', 'OUTWARD', 20000, 3);
$found = DocMatch::scanTickets();
$ND = fn(int $tid) => (int)Db::val("SELECT COUNT(*) FROM doc_exceptions WHERE code = 'NO_DOCUMENT' AND ticket_id = ?", [$tid]);
t('inward ticket older than the grace period with no document is listed', $ND($to) === 1 && $ND($tn) === 0 && $ND($tout) === 0 && $found >= 1);
$dn = mkDoc(['invoice_no' => 'INV-N1', 'vehicle_no' => 'MH31DOC0401', 'invoice_date' => date('Y-m-d', strtotime('-3 days')), 'supplier_name' => 'ACME Coal'], [['description' => 'Coal', 'qty' => 20, 'uom' => 'MT', 'rate' => 1, 'amount' => 1]]); DocMatch::run($dn); DocMatch::scanTickets();
t('once a document is linked to it the ticket drops off the list', $ND($to) === 0);
Documents::cancel($dn, 'oops'); DocMatch::scanTickets();
t('cancelling the document brings the ticket back', $ND($to) === 1);

echo "Documents: sending to SAP / Oracle\n";
$send = mkDoc(['invoice_no' => 'INV-SEND1', 'vehicle_no' => 'MH31DOC0501', 'po_no' => 'PO-Z'], [$iron(28.5), $charges]); mkTicket('MH31DOC0501', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28450);
DocMatch::run($send); Db::q("UPDATE documents SET status = 'VERIFIED', verified_by = 'op', verified_at = ? WHERE id = ?", [date('Y-m-d H:i:s'), $send]); DocMatch::run($send);
$pl = Documents::payload($send);
t('payload: document, lines, weighbridge and exceptions', $pl['documentNo'] === $doc($send)['doc_no'] && $pl['invoiceNo'] === 'INV-SEND1' && count($pl['lines']) === 2 && $pl['lines'][0]['quantityKg'] === 28500.0 && $pl['weighbridge']['netKg'] === 28450.0 && count($pl['weighbridge']['ticketNos']) === 1 && $pl['matchStatus'] === 'MATCHED', $pl['matchStatus'] . ' ' . json_encode($pl['exceptions']) . ' ' . json_encode($pl['weighbridge']['ticketNos']));
Settings::set('doc_targets', 'none'); DocSync::refreshDirty($send);
t('no target configured -> nothing queued', $doc($send)['oracle_status'] === 'NA' && $doc($send)['sap_status'] === 'NA' && str_contains(DocSync::sendOne($send)['messages'][0], 'No target'));
$sapLog = "$tmp/sap.log"; @unlink($sapLog);
Settings::set('doc_targets', 'sap'); Settings::set('sap_url', mockUrl('/sap/doc')); Settings::set('sap_ref_path', 'd.MaterialDocument'); Settings::set('sap_csrf', '1');
DocSync::refreshDirty($send); t('target on -> verified document is queued PENDING', $doc($send)['sap_status'] === 'PENDING' && $doc($send)['oracle_status'] === 'NA');
$r = DocSync::sendOne($send); $d = $doc($send); $l0 = (string)(@file($sapLog)[0] ?? ''); $body = json_decode(substr($l0, (int)strpos($l0, '{')), true);
t('SAP: basic login + CSRF handshake + JSON POST accepted, SAP document number stored', $r['ok'] && $d['sap_status'] === 'SYNCED' && $d['sap_ref'] === '5000012345' && str_contains($l0, 'application/json'), json_encode($r));
t('SAP: the posted JSON is the payload', ($body['invoiceNo'] ?? null) === 'INV-SEND1' && ($body['weighbridge']['netKg'] ?? null) === 28450 && count($body['lines'] ?? []) === 2);
$n0 = count(@file($sapLog) ?: []); DocSync::sendOne($send); t('unchanged document is not sent twice', count(@file($sapLog) ?: []) === $n0);
$e = Db::one("SELECT id FROM doc_exceptions WHERE doc_id = ? AND code = 'PO_NOT_CHECKED'", [$send]); $wasHash = $doc($send)['payload_hash'];
Db::q('UPDATE documents SET supplier_tax_id = ? WHERE id = ?', ['27AABCS1234F1Z5', $send]); DocSync::refreshDirty($send);
t('a change after sending queues it again (payload hash differs)', $doc($send)['sap_status'] === 'PENDING' && $doc($send)['payload_hash'] !== $wasHash);
DocSync::sendOne($send); t('...and the update is delivered', $doc($send)['sap_status'] === 'SYNCED' && count(@file($sapLog) ?: []) === $n0 + 1);

Settings::set('sap_url', mockUrl('/sap/doc_fail')); Db::q("UPDATE documents SET sap_status = 'PENDING' WHERE id = ?", [$send]);
$r = DocSync::sendOne($send); $d = $doc($send);
t('SAP error -> FAILED with SAP\'s own message and a retry counter', !$r['ok'] && $d['sap_status'] === 'FAILED' && str_contains((string)$d['sap_error'], 'Plant 1000 is locked') && $d['sync_tries'] >= 1, (string)$d['sap_error']);
Settings::set('sap_url', mockUrl('/sap/doc')); $sy = DocSync::syncPending();
t('background job retries FAILED documents and they succeed', $doc($send)['sap_status'] === 'SYNCED' && $sy['sent'] >= 1);
Settings::set('sap_pass', 'wrong'); Db::q("UPDATE documents SET sap_status = 'PENDING' WHERE id = ?", [$send]); DocSync::sendOne($send);
t('wrong SAP password -> FAILED, "login refused"', $doc($send)['sap_status'] === 'FAILED' && str_contains((string)$doc($send)['sap_error'], 'login refused')); Settings::set('sap_pass', 'sappass');
Settings::set('sap_csrf', '0'); Db::q("UPDATE documents SET sap_status = 'PENDING' WHERE id = ?", [$send]); DocSync::sendOne($send);
t('CSRF handshake off but SAP needs it -> SAP\'s 403 message is shown', str_contains((string)$doc($send)['sap_error'], 'CSRF token validation failed')); Settings::set('sap_csrf', '1');
Settings::set('sap_auth', 'bearer'); Settings::set('sap_token', 'tok123'); Settings::set('sap_url', mockUrl('/sap/bearer')); Settings::set('sap_ref_path', ''); Db::q("UPDATE documents SET sap_status = 'PENDING' WHERE id = ?", [$send]);
DocSync::sendOne($send); t('bearer-token login, reference taken from documentNo', $doc($send)['sap_status'] === 'SYNCED' && $doc($send)['sap_ref'] === 'BR-77');
Settings::set('sap_auth', 'basic'); Settings::set('sap_url', mockUrl('/sap/doc')); Settings::set('sap_ref_path', 'd.MaterialDocument');
$st = Sap::test(); t('Setup test: reachable, login accepted, CSRF token received', $st['ok'] && str_contains($st['message'], 'CSRF token received'), $st['message']);
Settings::set('sap_user', 'nobody'); t('Setup test: bad login explained', !Sap::test()['ok'] && str_contains(Sap::test()['message'], 'login refused')); Settings::set('sap_user', 'sapuser');
Settings::set('sap_url', 'http://127.0.0.1:1/x'); t('Setup test: unreachable explained', str_contains(Sap::test()['message'], 'unreachable')); Settings::set('sap_url', mockUrl('/sap/doc'));

// hold policy
$held = mkDoc(['invoice_no' => 'INV-HOLD', 'vehicle_no' => 'MH31DOC0601'], [$iron()]); DocMatch::run($held);
Db::q("UPDATE documents SET status = 'VERIFIED' WHERE id = ?", [$held]); DocMatch::run($held);
t('policy: open HIGH exception -> document is held, nothing sent', DocSync::held($doc($held)) && DocSync::sendOne($held)['held'] === true && $doc($held)['sap_status'] === 'PENDING');
$s = DocSync::syncPending(); t('background job counts held documents and leaves them', $s['held'] >= 1 && $doc($held)['sap_status'] === 'PENDING');
$asAdmin(); $r = DocSync::sendOne($held, true); t('administrator can send anyway (exceptions travel in the payload)', $r['ok'] && $doc($held)['sap_status'] === 'SYNCED' && count(Documents::payload($held)['exceptions']) >= 1); $asOp();
Db::q("UPDATE documents SET sap_status = 'PENDING', sap_hash = NULL WHERE id = ?", [$held]);
$e = Db::one("SELECT id FROM doc_exceptions WHERE doc_id = ? AND code = 'NO_TICKET'", [$held]); DocMatch::setExceptionStatus((int)$e['id'], 'RESOLVED', 'weighed elsewhere');
t('after the exception is resolved the document is released and sent', !DocSync::held($doc($held)) && DocSync::sendOne($held)['ok'] && $doc($held)['sap_status'] === 'SYNCED');
Settings::set('doc_send_policy', 'any'); $h2 = mkDoc(['invoice_no' => 'INV-ANY', 'vehicle_no' => 'MH31DOC0602'], [$iron()]); DocMatch::run($h2); Db::q("UPDATE documents SET status = 'VERIFIED' WHERE id = ?", [$h2]); DocMatch::run($h2);
t('policy "send anyway": not held', !DocSync::held($doc($h2)) && DocSync::sendOne($h2)['ok']); Settings::set('doc_send_policy', 'no_high');
t('unverified documents are never sent', str_contains(DocSync::sendOne($d13)['messages'][0], 'verified'));

// Oracle (no driver here): failure is reported per target, SAP still goes through
Settings::set('doc_targets', 'both'); Db::q("UPDATE documents SET sap_status = 'PENDING', sap_hash = NULL, oracle_status = 'PENDING', oracle_hash = NULL WHERE id = ?", [$send]);
$r = DocSync::sendOne($send); $d = $doc($send);
if (OracleSync::available() === '') {
    t('both targets: Oracle down does not stop SAP; each has its own status', $d['sap_status'] === 'SYNCED' && $d['oracle_status'] === 'FAILED' && str_contains((string)$d['oracle_error'], 'No Oracle PHP driver') && !$r['ok']);
}
$prm = OracleSync::documentParams(Documents::payload($send));
t('Oracle row: bind values for the documents table', $prm['doc_no'] === $doc($send)['doc_no'] && $prm['invoice_date'] === date('Y-m-d') && $prm['wb_net_kg'] === '28450' && $prm['match_status'] === 'MATCHED' && $prm['exc_high'] === '0' && $prm['verified_by'] === 'op');
$prm2 = OracleSync::documentParams(Documents::payload($held));
t('Oracle row: only OPEN exceptions are summarised', $prm2['exc_high'] === '0' && !str_contains((string)$prm2['exc_summary'], 'NO_TICKET'));
t('Oracle table names are validated', str_contains((string)throws(function () { Settings::set('ora_doc_table', 'X; DROP TABLE Y'); (new OracleSync(Settings::all()))->pushDocument(Documents::payload(1)); }), 'Invalid Oracle table name'));
Settings::set('ora_doc_table', 'WB_DOCUMENTS'); Settings::set('doc_targets', 'none');

echo "Documents: cancel\n";
$cx = mkDoc(['invoice_no' => 'INV-CX', 'vehicle_no' => 'MH31DOC0701'], [$iron()]); mkTicket('MH31DOC0701', 'Shree Balaji Minerals', 'Iron ore', 'INWARD', 28500); DocMatch::run($cx);
Documents::cancel($cx, 'uploaded twice');
t('cancel removes links and exceptions and hides the document', $doc($cx)['status'] === 'CANCELLED' && Db::val('SELECT COUNT(*) FROM document_tickets WHERE doc_id = ?', [$cx]) == 0 && Db::val('SELECT COUNT(*) FROM doc_exceptions WHERE doc_id = ?', [$cx]) == 0 && DocMatch::run($cx)['match_status'] === 'UNMATCHED');
t('a cancelled invoice number can be uploaded again (not a duplicate)', !in_array('DUPLICATE_INVOICE', $codes(($dd = mkDoc(['invoice_no' => 'INV-CX', 'vehicle_no' => 'MH31DOC0701'], [$iron()])) ? (function () use ($dd) { DocMatch::run($dd); return $dd; })() : 0), true));
Settings::set('ocr_provider', 'off');
