<?php
/**
 * Truck in/out analytics by product category (a truck = one completed ticket).
 *   GET analytics.php?from=2026-10-01&to=2026-10-31&category=Fuel&source=LOCAL|EXTERNAL
 * Returns totals, by_category, daily, top_materials, gate and slip_variances.
 */
require_once __DIR__ . '/../../../src/bootstrap.php';
Api::begin();
$f = ['from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-29 days')),
      'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d'),
      'category' => trim((string)($_GET['category'] ?? '')), 'source' => $_GET['source'] ?? ''];
$rows = Analytics::byCategory($f);
Api::out(['filter' => $f, 'totals' => Analytics::totals($rows), 'by_category' => $rows, 'daily' => Analytics::daily($f), 'top_materials' => Analytics::topMaterials($f),
          'gate' => Analytics::gate($f), 'slip_variances' => Analytics::variances($f)]);
