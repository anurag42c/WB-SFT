<?php
require __DIR__ . '/_layout.php';
Auth::require();

$f = ['from' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from'] ?? '') ? $_GET['from'] : date('Y-m-d', strtotime('-29 days')),
      'to' => preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to'] ?? '') ? $_GET['to'] : date('Y-m-d'),
      'category' => trim((string)($_GET['category'] ?? '')), 'source' => $_GET['source'] ?? ''];
$cats = Analytics::categories();
$rows = Analytics::byCategory($f); $tot = Analytics::totals($rows);
$t = fn(float $kg) => number_format($kg / 1000, 1);

if (isset($_GET['csv'])) {
    header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="truck_analytics_' . $f['from'] . '_' . $f['to'] . '.csv"');
    $o = fopen('php://output', 'w'); fwrite($o, "\xEF\xBB\xBF");
    fputcsv($o, ['Category', 'Trucks in', 'Tonnes in', 'Trucks out', 'Tonnes out', 'Balance tonnes (in-out)', 'Avg t/truck in', 'Avg t/truck out', 'Trucks from outside weighbridge'], ',', '"', '');
    foreach ($rows as $r) { fputcsv($o, [$r['category'], $r['in_trucks'], round($r['in_kg'] / 1000, 3), $r['out_trucks'], round($r['out_kg'] / 1000, 3), round($r['balance_kg'] / 1000, 3), $r['avg_in_kg'] !== null ? round($r['avg_in_kg'] / 1000, 3) : '', $r['avg_out_kg'] !== null ? round($r['avg_out_kg'] / 1000, 3) : '', $r['ext_trucks']], ',', '"', ''); }
    fputcsv($o, ['TOTAL', $tot['in_trucks'], round($tot['in_kg'] / 1000, 3), $tot['out_trucks'], round($tot['out_kg'] / 1000, 3), round($tot['balance_kg'] / 1000, 3), '', '', $tot['ext_trucks']], ',', '"', '');
    exit;
}
$daily = Analytics::daily($f); $gate = Analytics::gate($f); $top = Analytics::topMaterials($f); $var = Analytics::variances($f);
$max = max([1.0] + array_map(fn($r) => max($r['in_kg'], $r['out_kg']), $rows));
$dmax = max([1] + array_map(fn($d) => max($d['in_trucks'], $d['out_trucks']), $daily));
page_head('Analytics');
?>
<style>
.kpis{display:grid;gap:12px;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));margin-bottom:16px}
.kpi{background:var(--card);border:1px solid var(--line);border-radius:10px;padding:12px 14px}.kpi b{display:block;font-size:26px;line-height:1.2;font-variant-numeric:tabular-nums}.kpi span{font-size:12px;color:var(--mut);text-transform:uppercase;letter-spacing:.03em}
.bar{height:14px;border-radius:3px;min-width:2px}.bin{background:#0b5ed7}.bout{background:#d98a00}
.lg i{display:inline-block;width:12px;height:12px;border-radius:3px;margin:0 4px 0 12px;vertical-align:-1px}
.cbar td{border:0;padding:3px 8px}.cbar td:first-child{width:28%;font-size:13px}
.trend{display:flex;align-items:flex-end;gap:2px;height:130px;border-bottom:1px solid var(--line);overflow-x:auto}.trend>div{flex:1;min-width:9px;display:flex;align-items:flex-end;gap:1px;height:100%}.trend .bar{flex:1;border-radius:2px 2px 0 0}
</style>
<form class="card noprint" method="get" style="display:flex;gap:10px;align-items:end;flex-wrap:wrap">
  <div><label>From</label><input type="date" name="from" value="<?= e($f['from']) ?>"></div><div><label>To</label><input type="date" name="to" value="<?= e($f['to']) ?>"></div>
  <div><label>Product category</label><select name="category"><option value="">All categories</option><?php foreach ($cats as $c) echo '<option' . ($c === $f['category'] ? ' selected' : '') . '>' . e($c) . '</option>'; ?></select></div>
  <div><label>Weighed at</label><select name="source"><?php foreach (['' => 'Factory + outside slips', 'LOCAL' => 'Factory weighbridge only', 'EXTERNAL' => 'Outside weighbridge slips only'] as $k => $l) echo '<option value="' . $k . '"' . ($k === $f['source'] ? ' selected' : '') . '>' . e($l) . '</option>'; ?></select></div>
  <div><button>Show</button> <a class="btn sec" href="?<?= e(http_build_query($f + ['csv' => 1])) ?>">CSV</a></div>
</form>
<p class="hint">A truck is one completed weighbridge ticket (one load). Categories come from <a href="masters.php?t=materials">Masters &rarr; Materials</a>; cancelled tickets are not counted.</p>

<div class="kpis">
  <div class="kpi"><span>Trucks in</span><b><?= $tot['in_trucks'] ?></b><small class="hint"><?= $t($tot['in_kg']) ?> t received</small></div>
  <div class="kpi"><span>Trucks out</span><b><?= $tot['out_trucks'] ?></b><small class="hint"><?= $t($tot['out_kg']) ?> t dispatched</small></div>
  <div class="kpi"><span>Balance (in &minus; out)</span><b><?= ($tot['balance_kg'] > 0 ? '+' : '') . $t($tot['balance_kg']) ?> t</b></div>
  <div class="kpi"><span>From outside weighbridges</span><b><?= $tot['ext_trucks'] ?></b><small class="hint">slips entered at the gate</small></div>
  <div class="kpi"><span>Slip differences</span><b style="color:<?= $var ? 'var(--bad)' : 'var(--ok)' ?>"><?= count($var) ?></b><small class="hint">outside vs factory weight</small></div>
</div>

<div class="card"><h2>By product category <span class="lg hint"><i class="bin"></i>In (t)<i class="bout"></i>Out (t)</span></h2>
<?php if (!$rows): ?><p class="hint">No completed tickets in this range.</p><?php else: ?>
<table class="cbar"><?php foreach ($rows as $r): ?>
  <tr><td rowspan="2"><b><?= e($r['category']) ?></b></td><td><div class="bar bin" style="width:<?= round($r['in_kg'] / $max * 100, 1) ?>%"></div></td><td class="n"><?= $t($r['in_kg']) ?> t &middot; <?= $r['in_trucks'] ?> trucks</td></tr>
  <tr><td><div class="bar bout" style="width:<?= round($r['out_kg'] / $max * 100, 1) ?>%"></div></td><td class="n"><?= $t($r['out_kg']) ?> t &middot; <?= $r['out_trucks'] ?> trucks</td></tr>
<?php endforeach; ?></table>
<table style="margin-top:12px"><tr><th>Category</th><th class="n">Trucks in</th><th class="n">Tonnes in</th><th class="n">Trucks out</th><th class="n">Tonnes out</th><th class="n">Balance t</th><th class="n">Avg t / truck in</th><th class="n">Avg t / truck out</th><th class="n">Outside slips</th></tr>
<?php foreach ($rows as $r): ?><tr><td><a href="?<?= e(http_build_query(array_merge($f, ['category' => $r['category']]))) ?>"><?= e($r['category']) ?></a></td><td class="n"><?= $r['in_trucks'] ?></td><td class="n"><?= $t($r['in_kg']) ?></td><td class="n"><?= $r['out_trucks'] ?></td><td class="n"><?= $t($r['out_kg']) ?></td>
  <td class="n"><?= ($r['balance_kg'] > 0 ? '+' : '') . $t($r['balance_kg']) ?></td><td class="n"><?= $r['avg_in_kg'] !== null ? $t($r['avg_in_kg']) : '&ndash;' ?></td><td class="n"><?= $r['avg_out_kg'] !== null ? $t($r['avg_out_kg']) : '&ndash;' ?></td><td class="n"><?= $r['ext_trucks'] ?></td></tr><?php endforeach; ?>
<tr><th>Total</th><th class="n"><?= $tot['in_trucks'] ?></th><th class="n"><?= $t($tot['in_kg']) ?></th><th class="n"><?= $tot['out_trucks'] ?></th><th class="n"><?= $t($tot['out_kg']) ?></th><th class="n"><?= ($tot['balance_kg'] > 0 ? '+' : '') . $t($tot['balance_kg']) ?></th><th></th><th></th><th class="n"><?= $tot['ext_trucks'] ?></th></tr></table>
<?php endif; ?></div>

<div class="card"><h2>Trucks per day<?= $f['category'] !== '' ? ' &mdash; ' . e($f['category']) : '' ?> <span class="lg hint"><i class="bin"></i>In<i class="bout"></i>Out</span></h2>
<?php if (!$daily): ?><p class="hint">No data.</p><?php else: ?>
<div class="trend" role="img" aria-label="Trucks in and out per day"><?php foreach ($daily as $d): ?><div title="<?= e($d['date']) ?>: <?= $d['in_trucks'] ?> in (<?= $t($d['in_kg']) ?> t), <?= $d['out_trucks'] ?> out (<?= $t($d['out_kg']) ?> t)"><span class="bar bin" style="height:<?= round($d['in_trucks'] / $dmax * 100) ?>%"></span><span class="bar bout" style="height:<?= round($d['out_trucks'] / $dmax * 100) ?>%"></span></div><?php endforeach; ?></div>
<div class="hint" style="display:flex;justify-content:space-between"><span><?= e($daily[0]['date']) ?></span><span>peak <?= $dmax ?> trucks / day</span><span><?= e(end($daily)['date']) ?></span></div>
<?php endif; ?></div>

<div class="grid g2">
<div class="card"><h2>Top materials</h2><table><tr><th>Material</th><th>Category</th><th class="n">In</th><th class="n">Out</th><th class="n">Tonnes</th></tr>
<?php foreach ($top as $r): ?><tr><td><?= e($r['material']) ?></td><td><?= e($r['category']) ?></td><td class="n"><?= (int)$r['in_trucks'] ?></td><td class="n"><?= (int)$r['out_trucks'] ?></td><td class="n"><?= $t((float)$r['kg']) ?></td></tr><?php endforeach; if (!$top) echo '<tr><td colspan="5" class="hint">No data.</td></tr>'; ?></table></div>
<div class="card"><h2>Gate register (entries in the range)</h2><table><tr><th>Category</th><th class="n">Entries</th><th class="n">Exited</th><th class="n">Inside</th><th class="n">Avg time on site</th></tr>
<?php foreach ($gate as $r): ?><tr><td><?= e($r['category']) ?></td><td class="n"><?= $r['entries'] ?></td><td class="n"><?= $r['exits'] ?></td><td class="n"><?= $r['inside'] ?></td><td class="n"><?= $r['avg_min'] !== null ? floor($r['avg_min'] / 60) . ' h ' . str_pad((string)round(fmod($r['avg_min'], 60)), 2, '0', STR_PAD_LEFT) . ' min' : '&ndash;' ?></td></tr><?php endforeach; if (!$gate) echo '<tr><td colspan="5" class="hint">No gate entries.</td></tr>'; ?></table></div>
</div>

<div class="card"><h2>Outside slip &ne; factory weighbridge</h2>
<?php if (!$var): ?><p class="hint">No load differs by more than the allowed <?= e(Settings::get('ext_tol_pct')) ?> % / <?= e(Settings::get('ext_tol_kg')) ?> kg (Setup &rarr; Gates).</p><?php else: ?>
<table><tr><th>Entry</th><th>Vehicle</th><th>Material</th><th>Outside slip</th><th class="n">Slip kg</th><th>Ticket</th><th class="n">Factory kg</th><th class="n">Difference</th></tr>
<?php foreach ($var as $v): ?><tr><td><?= e($v['entry_no']) ?></td><td><?= e($v['vehicle_no']) ?></td><td><?= e($v['material']) ?></td><td><?= e($v['slip']) ?></td><td class="n"><?= number_format($v['ext_kg']) ?></td><td><?= e($v['ticket_no']) ?></td><td class="n"><?= number_format($v['factory_kg']) ?></td>
  <td class="n"><span class="badge bad"><?= ($v['diff_kg'] > 0 ? '+' : '') . number_format($v['diff_kg']) ?> kg</span></td></tr><?php endforeach; ?></table><?php endif; ?></div>
<?php page_foot();
