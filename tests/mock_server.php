<?php
// Router for `php -S`: fake IP camera + fake ANPR services (Plate Recognizer, CodeProject.AI formats) for tests.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
header('Content-Type: application/json');

function plate_in_upload(): ?string {
    $f = $_FILES['upload']['tmp_name'] ?? null;
    if (!$f) { return null; }
    return preg_match('/PLATE:([A-Z0-9]+)/', (string)file_get_contents($f), $m) ? $m[1] : null;
}

switch ($path) {
    case '/snap.jpg':                               // camera: JPEG that "contains" the plate given in ?plate=
        header('Content-Type: image/jpeg');
        echo "\xFF\xD8\xFF\xE0", isset($_GET['plate']) ? 'PLATE:' . preg_replace('/[^A-Z0-9]/', '', strtoupper($_GET['plate'])) : 'EMPTYROAD', str_repeat("\x00", 64), "\xFF\xD9";
        break;
    case '/notjpeg.jpg': echo 'hello'; break;
    case '/pr':                                     // Plate Recognizer format
        if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Token testkey') { http_response_code(403); echo '{"detail":"Invalid token."}'; break; }
        $p = plate_in_upload();
        echo json_encode(['results' => $p ? [['plate' => strtolower($p), 'score' => 0.93, 'dscore' => 0.8], ['plate' => 'zz99', 'score' => 0.2]] : []]);
        break;
    case '/cp':                                     // CodeProject.AI format
        $p = plate_in_upload();
        echo json_encode(['success' => true, 'predictions' => $p ? [['label' => "Plate: $p", 'plate' => $p, 'confidence' => 0.88]] : [], 'code' => 200]);
        break;
    case '/relay/open': case '/relay/close': case '/relay/release':     // IP relay: log every hit, optional Basic auth on /relay/auth/*
        file_put_contents(getenv('RELAY_LOG') ?: '/dev/null', sprintf("%.3f %s %s %s\n", microtime(true), $_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], file_get_contents('php://input')), FILE_APPEND);
        echo '{"ok":true}'; break;
    case '/relay/auth/open':
        if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'gateuser' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'gatepass') { http_response_code(401); header('WWW-Authenticate: Basic realm="r"'); break; }
        file_put_contents(getenv('RELAY_LOG') ?: '/dev/null', sprintf("%.3f AUTHOK\n", microtime(true)), FILE_APPEND);
        echo 'ok'; break;
    case '/relay/fail': http_response_code(503); echo 'busy'; break;
    case '/jsonrpc':                                // fake Odoo external API (JSON-RPC): wb.ticket model + purchase lines + masters
        $req = json_decode((string)file_get_contents('php://input'), true) ?: [];
        $a = $req['params']['args'] ?? []; $svc = $req['params']['service'] ?? ''; $meth = $req['params']['method'] ?? '';
        $stf = getenv('ODOO_STATE') ?: '/tmp/odoo_state.json';
        $st = is_file($stf) ? json_decode((string)file_get_contents($stf), true) : ['tickets' => [], 'next' => 1, 'calls' => 0];
        $ok = fn($r) => print(json_encode(['jsonrpc' => '2.0', 'id' => $req['id'] ?? 1, 'result' => $r]));
        $fail = fn($m) => print(json_encode(['jsonrpc' => '2.0', 'id' => $req['id'] ?? 1, 'error' => ['code' => 200, 'message' => 'Odoo Server Error', 'data' => ['message' => $m]]]));
        if ($svc === 'common' && $meth === 'version') { $ok(['server_version' => '17.0', 'server_version_info' => [17, 0, 0, 'final', 0]]); break; }
        if ($svc === 'common' && $meth === 'authenticate') { $ok(($a[0] ?? '') === 'testdb' && ($a[1] ?? '') === 'admin' && ($a[2] ?? '') === 'odookey' ? 2 : false); break; }
        if ($svc === 'object' && $meth === 'execute_kw') {
            [$db, $uid, $key, $model, $m, $args, $kw] = $a + [null, null, null, null, null, [], []];
            if ($db !== 'testdb' || $uid !== 2 || $key !== 'odookey') { $fail('Access Denied'); break; }
            if (getenv('ODOO_DOWN') || is_file($stf . '.down')) { http_response_code(503); echo 'down'; break; }
            $st['calls']++;
            if ($model === 'wb.ticket') {
                if ($m === 'search_count') { $ok(count($st['tickets'])); }
                elseif ($m === 'search') { $no = $args[0][0][2] ?? null; $ids = []; foreach ($st['tickets'] as $id => $t) { if (($t['ticket_no'] ?? null) === $no) { $ids[] = (int)$id; } } $ok($ids); }
                elseif ($m === 'create') {
                    if (($args[0]['ticket_no'] ?? '') === 'WBREJECT') { $fail('ValidationError: ticket rejected by rule'); break; }
                    $id = $st['next']++; $st['tickets'][$id] = $args[0]; $ok($id);
                } elseif ($m === 'write') { foreach ($args[0] as $id) { $st['tickets'][$id] = $args[1] + $st['tickets'][$id]; } $ok(true); }
                else { $fail("bad method $m"); }
            } elseif ($model === 'purchase.order.line' && $m === 'search_read') {
                $ok([['id' => 1, 'order_id' => [7, 'P00007'], 'sequence' => 10, 'partner_id' => [5, 'Shree Balaji Minerals Pvt Ltd'], 'product_id' => [9, '[IRON01] Iron Ore Lumps'], 'name' => 'Iron Ore Lumps', 'product_qty' => 40.0, 'product_uom' => [3, 'MT'], 'price_unit' => 3500.0, 'date_order' => '2026-09-10 05:00:00'],
                     ['id' => 2, 'order_id' => [7, 'P00007'], 'sequence' => 20, 'partner_id' => [5, 'Shree Balaji Minerals Pvt Ltd'], 'product_id' => [10, 'Coal'], 'name' => 'Coal', 'product_qty' => 15.0, 'product_uom' => [3, 'MT'], 'price_unit' => 900.0, 'date_order' => '2026-09-10 05:00:00'],
                     ['id' => 3, 'order_id' => [8, 'P00008'], 'sequence' => 10, 'partner_id' => [6, 'Other Ltd'], 'product_id' => [10, 'Coal'], 'name' => 'Coal', 'product_qty' => 0.0, 'product_uom' => [3, 'MT'], 'price_unit' => 1.0, 'date_order' => false]]);
            } elseif ($model === 'res.partner' && $m === 'search_read') { $ok([['id' => 5, 'name' => 'Shree Balaji Minerals Pvt Ltd'], ['id' => 11, 'name' => 'Odoo Customer Co']]);
            } elseif ($model === 'product.product' && $m === 'search_read') { $ok([['id' => 9, 'name' => 'Iron Ore Lumps'], ['id' => 12, 'name' => 'Limestone']]);
            } else { $fail("Object $model doesn't exist"); }
            file_put_contents($stf, json_encode($st)); break;
        }
        $fail('unknown service'); break;
    case '/v1/messages':                            // fake Anthropic Messages API: logs the request shape, answers by API key
        $key = $_SERVER['HTTP_X_API_KEY'] ?? '';
        $raw = file_get_contents('php://input'); $req = json_decode($raw, true) ?: [];
        $blocks = $req['messages'][0]['content'] ?? [];
        $summary = ['key' => $key, 'version' => $_SERVER['HTTP_ANTHROPIC_VERSION'] ?? null, 'ctype' => $_SERVER['CONTENT_TYPE'] ?? null, 'model' => $req['model'] ?? null, 'max_tokens' => $req['max_tokens'] ?? null,
            'thinking' => $req['thinking'] ?? 'absent', 'temperature' => array_key_exists('temperature', $req), 'tool_choice' => array_key_exists('tool_choice', $req),
            'output_config' => ['effort' => $req['output_config']['effort'] ?? null, 'format' => $req['output_config']['format']['type'] ?? null, 'required' => $req['output_config']['format']['schema']['required'] ?? null],
            'blocks' => array_map(function ($b) { $d = $b['source']['data'] ?? ''; $sz = ($b['type'] === 'image' && $d !== '') ? @getimagesizefromstring(base64_decode($d)) : false; return ['type' => $b['type'], 'media_type' => $b['source']['media_type'] ?? null, 'data_len' => strlen($d), 'w' => $sz ? $sz[0] : null, 'h' => $sz ? $sz[1] : null, 'text' => $b['text'] ?? null]; }, $blocks)];
        file_put_contents(getenv('CLAUDE_LOG') ?: '/dev/null', json_encode($summary) . "\n", FILE_APPEND);
        $err = fn(int $c, string $t, string $m) => [http_response_code($c), print(json_encode(['type' => 'error', 'error' => ['type' => $t, 'message' => $m]]))];
        if ($key === 'bad') { $err(401, 'authentication_error', 'invalid x-api-key'); break; }
        if ($key === 'limited') { $err(429, 'rate_limit_error', 'Rate limited'); break; }
        if ($key === 'busy') { $err(529, 'overloaded_error', 'Overloaded'); break; }
        $stop = 'end_turn'; $text = null;
        $inv = ['invoice_no' => 'BM/2026-27/0451', 'invoice_date' => '2026-09-15', 'supplier_name' => 'Shree Balaji Minerals Pvt Ltd', 'supplier_tax_id' => '27AABCS1234F1Z5', 'buyer_name' => 'Sunrise Steel Pvt Ltd',
            'po_no' => '4500012345', 'orig_invoice_no' => null, 'vehicle_no' => 'MH 31 AB 1234', 'transporter' => null, 'eway_bill_no' => '481234567890', 'currency' => 'INR', 'subtotal' => 139650.0, 'tax_amount' => 25137.0, 'total_amount' => 164787.0,
            'lines' => [['description' => 'Iron Ore Fines 62% Fe', 'material_code' => null, 'hsn' => '2601', 'qty' => 28.5, 'uom' => 'MT', 'rate' => 4850.0, 'amount' => 138225.0],
                        ['description' => 'Loading and Handling Charges', 'material_code' => null, 'hsn' => '9967', 'qty' => 28.5, 'uom' => 'MT', 'rate' => 50.0, 'amount' => 1425.0]],
            'confidence' => 0.97, 'warnings' => []];
        if ($key === 'refuse') { $stop = 'refusal'; $text = ''; }
        elseif ($key === 'trunc') { $stop = 'max_tokens'; $text = '{"invoice_no": "BM'; }
        elseif ($key === 'garbage') { $text = 'Sorry, I cannot read this.'; }
        else { $text = json_encode($inv); }
        echo json_encode(['id' => 'msg_test', 'type' => 'message', 'role' => 'assistant', 'model' => $req['model'] ?? '', 'stop_reason' => $stop, 'content' => [['type' => 'text', 'text' => $text]], 'usage' => ['input_tokens' => 1200, 'output_tokens' => 300]]);
        break;
    case '/sap/doc': case '/sap/doc_fail': case '/sap/bearer':      // fake SAP Gateway: basic auth + CSRF fetch/post with session cookie
        $log = getenv('SAP_LOG') ?: '/dev/null';
        if ($path === '/sap/bearer') {
            if (($_SERVER['HTTP_AUTHORIZATION'] ?? '') !== 'Bearer tok123') { http_response_code(401); echo '{"error":{"message":"bad token"}}'; break; }
            file_put_contents($log, "BEARER-POST " . file_get_contents('php://input') . "\n", FILE_APPEND); echo json_encode(['documentNo' => 'BR-77']); break;
        }
        if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'sapuser' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'sappass') { http_response_code(401); header('WWW-Authenticate: Basic realm="SAP"'); echo '{"error":{"message":{"value":"Logon failed"}}}'; break; }
        if ($_SERVER['REQUEST_METHOD'] === 'GET') {
            if (strcasecmp($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '', 'Fetch') === 0) { header('X-CSRF-Token: TOK123'); header('Set-Cookie: sap-sess=abc; Path=/'); }
            echo '{}'; break;
        }
        if (($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '') !== 'TOK123' || !str_contains($_SERVER['HTTP_COOKIE'] ?? '', 'sap-sess=abc')) { http_response_code(403); header('X-CSRF-Token: Required'); echo '{"error":{"message":{"value":"CSRF token validation failed"}}}'; break; }
        file_put_contents($log, "POST " . ($_SERVER['CONTENT_TYPE'] ?? '') . " " . file_get_contents('php://input') . "\n", FILE_APPEND);
        if ($path === '/sap/doc_fail') { http_response_code(500); echo '{"error":{"message":{"value":"Plant 1000 is locked"}}}'; break; }
        http_response_code(201); echo json_encode(['d' => ['MaterialDocument' => '5000012345', 'Note' => 'ok']]); break;
    case '/sap/po/4500012345':                       // OData v2 style purchase order
        if (($_SERVER['PHP_AUTH_USER'] ?? '') !== 'sapuser' || ($_SERVER['PHP_AUTH_PW'] ?? '') !== 'sappass') { http_response_code(401); echo '{"error":{"message":{"value":"Logon failed"}}}'; break; }
        echo json_encode(['d' => ['PurchaseOrder' => '4500012345', 'Supplier' => 'Shree Balaji Minerals Pvt Ltd', 'CreationDate' => '2026-09-01', 'to_PurchaseOrderItem' => ['results' => [
            ['PurchaseOrderItem' => '00010', 'Material' => 'IRONORE62', 'PurchaseOrderItemText' => 'Iron Ore Fines 62% Fe', 'OrderQuantity' => '100.000', 'PurchaseOrderQuantityUnit' => 'MT', 'NetPriceAmount' => '4850.00']]]]]);
        break;
    case '/sap/po/PO-2026%2F778': case '/sap/po/PO-2026/778':     // simple JSON contract
        echo json_encode(['po_no' => 'PO-2026/778', 'vendor' => 'Kiran Logistics & Traders', 'lines' => [['line_no' => 10, 'material_code' => 'LIME2040', 'description' => 'Limestone 20-40mm', 'qty' => 40, 'uom' => 'MT', 'rate' => 1200]]]);
        break;
    case '/boom': http_response_code(500); echo 'oops'; break;
    default: http_response_code(404);
}
