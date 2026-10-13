<?php
/** GET ping.php - checks the token and tells the caller what it may do. */
require_once __DIR__ . '/../../../src/bootstrap.php';
Api::begin();
Api::out(['ok' => true, 'app' => 'weighbridge', 'version' => WB_VERSION, 'api' => Api::VERSION, 'writes_enabled' => Settings::get('api_write') === '1', 'time' => date('c'),
    'endpoints' => ['tickets', 'documents', 'purchase_orders', 'masters', 'ping']]);
