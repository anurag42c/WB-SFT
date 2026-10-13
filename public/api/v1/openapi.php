<?php
/** OpenAPI 3 description of this API (public; contains no data). Import it into Postman / Swagger / an Odoo developer's tooling. */
require_once __DIR__ . '/../../../src/bootstrap.php';
header('Content-Type: application/json; charset=utf-8');
$ref = fn(string $n) => ['$ref' => "#/components/schemas/$n"];
$ok = fn(string $d, ?array $schema = null) => ['description' => $d] + ($schema ? ['content' => ['application/json' => ['schema' => $schema]]] : []);
$q = fn(string $n, string $t, string $d) => ['name' => $n, 'in' => 'query', 'schema' => ['type' => $t], 'description' => $d];
$sec = [['bearer' => []]];
$err = ['401' => $ok('Missing or wrong token'), '429' => $ok('Too many failed attempts')];
echo json_encode([
    'openapi' => '3.0.3',
    'info' => ['title' => 'Weighbridge API', 'version' => WB_VERSION, 'description' => 'Pull weighbridge tickets and invoice documents into an ERP (for example Odoo); push purchase orders and master data in. Token: Setup > Odoo & API.'],
    'servers' => [['url' => '.']],
    'components' => [
        'securitySchemes' => ['bearer' => ['type' => 'http', 'scheme' => 'bearer']],
        'schemas' => [
            'Ticket' => ['type' => 'object', 'properties' => [
                'id' => ['type' => 'integer'], 'ticket_no' => ['type' => 'string'], 'status' => ['type' => 'string', 'enum' => ['OPEN', 'CLOSED', 'CANCELLED']], 'direction' => ['type' => 'string', 'enum' => ['INWARD', 'OUTWARD']],
                'vehicle_no' => ['type' => 'string'], 'party' => ['type' => 'string', 'nullable' => true], 'material' => ['type' => 'string', 'nullable' => true], 'driver' => ['type' => 'string', 'nullable' => true],
                'challan_no' => ['type' => 'string', 'nullable' => true], 'remarks' => ['type' => 'string', 'nullable' => true], 'gross_kg' => ['type' => 'number'], 'tare_kg' => ['type' => 'number'], 'net_kg' => ['type' => 'number'],
                'first_at' => ['type' => 'string', 'description' => 'Local time YYYY-MM-DD HH:MM:SS'], 'second_at' => ['type' => 'string'], 'scale' => ['type' => 'string', 'nullable' => true],
                'plate_in' => ['type' => 'string', 'nullable' => true], 'plate_out' => ['type' => 'string', 'nullable' => true], 'plate_flag' => ['type' => 'string', 'nullable' => true], 'operator' => ['type' => 'string'], 'odoo_id' => ['type' => 'integer', 'nullable' => true]]],
            'PurchaseOrder' => ['type' => 'object', 'required' => ['po_no', 'lines'], 'properties' => [
                'po_no' => ['type' => 'string'], 'vendor' => ['type' => 'string'], 'po_date' => ['type' => 'string', 'format' => 'date'],
                'lines' => ['type' => 'array', 'items' => ['type' => 'object', 'required' => ['qty'], 'properties' => ['line_no' => ['type' => 'integer'], 'material_code' => ['type' => 'string'], 'description' => ['type' => 'string'], 'qty' => ['type' => 'number'], 'uom' => ['type' => 'string'], 'rate' => ['type' => 'number']]]]]],
        ],
    ],
    'security' => $sec,
    'paths' => [
        '/api/v1/ping.php' => ['get' => ['summary' => 'Check the token', 'responses' => ['200' => $ok('OK')] + $err]],
        '/api/v1/tickets.php' => ['get' => ['summary' => 'Closed / cancelled tickets (or one by ticket_no)', 'parameters' => [$q('since_id', 'integer', 'Return tickets with id greater than this'), $q('limit', 'integer', '1-500'), $q('ticket_no', 'string', 'One ticket, any status'),
            $q('status', 'string', 'CLOSED or CANCELLED'), $q('from', 'string', 'First-weight date >= YYYY-MM-DD'), $q('to', 'string', 'First-weight date <= YYYY-MM-DD'), $q('vehicle', 'string', 'contains'), $q('party', 'string', 'contains'), $q('material', 'string', 'contains')],
            'responses' => ['200' => $ok('{count, next_since_id, tickets[]}', ['type' => 'object', 'properties' => ['count' => ['type' => 'integer'], 'next_since_id' => ['type' => 'integer'], 'tickets' => ['type' => 'array', 'items' => $ref('Ticket')]]]), '404' => $ok('ticket_no unknown')] + $err]],
        '/api/v1/documents.php' => ['get' => ['summary' => 'Invoice / return documents with match status and exceptions (doc_no = full document)', 'parameters' => [$q('doc_no', 'string', 'One document in full'), $q('since_id', 'integer', ''), $q('status', 'string', 'UPLOADED, EXTRACTED, VERIFIED, CANCELLED'),
            $q('match', 'string', 'UNMATCHED, MATCHED, REVIEW, EXCEPTION'), $q('type', 'string', 'PO_INVOICE or MATERIAL_RETURN'), $q('limit', 'integer', '1-500')], 'responses' => ['200' => $ok('{count, next_since_id, documents[]} or {document}'), '404' => $ok('doc_no unknown')] + $err]],
        '/api/v1/purchase_orders.php' => [
            'get' => ['summary' => 'Lines of one PO (no parameter: number of POs)', 'parameters' => [$q('po', 'string', 'PO number')], 'responses' => ['200' => $ok('PO'), '404' => $ok('Unknown PO')] + $err],
            'post' => ['summary' => 'Replace the lines of the POs sent (needs "Allow API writes")', 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'properties' => ['pos' => ['type' => 'array', 'items' => $ref('PurchaseOrder')]]]]]],
                'responses' => ['200' => $ok('{ok, pos, lines}'), '403' => $ok('Writes disabled'), '422' => $ok('Validation failed, nothing stored')] + $err]],
        '/api/v1/masters.php' => [
            'get' => ['summary' => 'parties, materials or vehicles', 'parameters' => [$q('type', 'string', 'parties | materials | vehicles')], 'responses' => ['200' => $ok('{type, items[]}')] + $err],
            'post' => ['summary' => 'Upsert master data (needs "Allow API writes")', 'requestBody' => ['required' => true, 'content' => ['application/json' => ['schema' => ['type' => 'object', 'required' => ['type', 'items'], 'properties' => ['type' => ['type' => 'string'], 'items' => ['type' => 'array', 'items' => ['type' => 'object']]]]]]],
                'responses' => ['200' => $ok('{ok, type, upserted}'), '403' => $ok('Writes disabled'), '422' => $ok('Validation failed, nothing stored')] + $err]],
    ],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
