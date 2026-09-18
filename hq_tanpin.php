<?php
/**
 * hq_tanpin.php  単品管理ダッシュボード
 * ・タブ「推移」＝全店合計を日別に集計（① 相当）
 * ・タブ「商品・店舗分析」＝商品別ランキング（②-1/②-2 相当、部門で絞り込み可）
 *   └ 商品名をクリックすると店舗別内訳にドリルダウン（②-3 相当）
 *   └ 「店舗別」に切り替えると、選択店舗の商品別内訳を表示（③ 相当）
 * データソース: pos_API（定価/値引の実績）＋ haiki_API（廃棄数）＋ daily_report_API（店舗・日次の上代目標）
 * ・「上代」は商品マスタの単価ではなく、店舗が売上日報で毎日入力する合計目標額
 *   （daily_report_API.上代合計、部門別ではなく単一値）。商品・部門単位の内訳を
 *   持たないため、「全部門・全店舗」の範囲と一致するとき（推移タブ／商品別・部門
 *   絞り込みなし／店舗別）のみ意味を持つ。部門絞り込みや単品ドリルダウンでは
 *   「―」表示とする。
 */
use fmRESTor\fmRESTor;
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hq') {
    header('Location: login.php'); exit();
}
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/bumon_master.php';

// ============================================================
// フィルタ入力
// ============================================================
$tab  = ($_GET['tab']  ?? 'trend') === 'item' ? 'item' : 'trend';
$view = ($_GET['view'] ?? 'product') === 'store' ? 'store' : 'product';

$today = new DateTime(); $today->setTime(0, 0, 0);
$default_from = (clone $today)->modify('first day of this month');
$from_dt = DateTime::createFromFormat('Y-m-d', $_GET['from'] ?? '') ?: $default_from;
$to_dt   = DateTime::createFromFormat('Y-m-d', $_GET['to']   ?? '') ?: clone $today;
$from_dt->setTime(0, 0, 0); $to_dt->setTime(0, 0, 0);
if ($from_dt > $to_dt) { [$from_dt, $to_dt] = [$to_dt, $from_dt]; }
if ($to_dt > $today) { $to_dt = clone $today; }
$from_html = $from_dt->format('Y-m-d');
$to_html   = $to_dt->format('Y-m-d');
$from_fm   = $from_dt->format('m/d/Y');
$to_fm     = $to_dt->format('m/d/Y');
$period_jp = $from_dt->format('Y/n/j') . ' 〜 ' . $to_dt->format('Y/n/j');

$sel_bumon   = trim($_GET['bumon']   ?? '');
$sel_store   = trim($_GET['store']   ?? '');
$sel_product = ($tab === 'item' && $view === 'product') ? trim($_GET['product'] ?? '') : '';

// ============================================================
// マスタ取得
// ============================================================
$bumon_master = fetch_bumon_master($host, $db, $layout_bumon, $api_master_user, $api_master_pass);
$bumon_names  = bumon_names($bumon_master);

// 商品マスタ：商品名 => ['bumon'=>部門名]（haiki_APIには部門が無いため部門絞り込み・上代の部門特定に使用）
$fmHanbai  = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$resHanbai = $fmHanbai->getRecords(['_limit' => 1000]);
$product_master = [];
foreach ($resHanbai['result']['response']['data'] ?? [] as $row) {
    $f = $row['fieldData'];
    $n = trim($f['商品名'] ?? '');
    if ($n === '') continue;
    $product_master[$n] = ['bumon' => trim($f['部門'] ?? '')];
}

// 店舗マスタ：店舗Ｎｏ（account_API・全角Ｎ）=> 店舗名
$fmAccount  = new fmRESTor($host, $db, $layout_account, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$resAccount = $fmAccount->getRecords(['_limit' => 300]);
$store_master = [];
foreach ($resAccount['result']['response']['data'] ?? [] as $row) {
    $f   = $row['fieldData'];
    $sno = trim((string)($f['店舗Ｎｏ'] ?? ''));
    if ($sno === '') continue;
    $store_master[$sno] = trim($f['店舗名'] ?? $sno);
}
uksort($store_master, fn($a, $b) => (int)$a <=> (int)$b);

// ============================================================
// pos_API / haiki_API 取得（期間絞り込み、必要なら店舗絞り込み）
// ============================================================
function fetchAllRecords(fmRESTor $fm, array $query, int $chunk = 2000, int $maxPages = 30): array {
    $all = []; $offset = 1;
    for ($i = 0; $i < $maxPages; $i++) {
        $r = $fm->findRecords(['query' => [$query], 'limit' => $chunk, 'offset' => $offset]);
        if (($r['result']['messages'][0]['code'] ?? '0') === '401') break;
        $data = $r['result']['response']['data'] ?? [];
        if (!$data) break;
        foreach ($data as $row) $all[] = $row['fieldData'];
        if (count($data) < $chunk) break;
        $offset += $chunk;
    }
    return $all;
}

// グループ化モードの決定
if ($tab === 'trend') {
    $group_mode = 'date';
} elseif ($view === 'store') {
    $group_mode = 'product_of_store';
} else {
    $group_mode = ($sel_product !== '') ? 'store_of_product' : 'product';
}

$pos_query = ['販売日時' => "{$from_fm}...{$to_fm}"];
$haiki_query = ['売上日' => "{$from_fm}...{$to_fm}"];
if ($group_mode === 'product_of_store' && $sel_store !== '') {
    $pos_query['店舗No'] = $sel_store;
    $haiki_query['fk_店舗No'] = $sel_store;
}

$fmPos   = new fmRESTor($host, $db, $layout_pos,   $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$fmHaiki = new fmRESTor($host, $db, $layout_haiki, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$pos_records   = ($group_mode === 'product_of_store' && $sel_store === '') ? [] : fetchAllRecords($fmPos, $pos_query);
$haiki_records = ($group_mode === 'product_of_store' && $sel_store === '') ? [] : fetchAllRecords($fmHaiki, $haiki_query);

// ============================================================
// daily_report_API 取得（店舗・日次の上代目標。部門別ではなく合計のみ）
// ============================================================
$joudai_query = ['売上日' => "{$from_fm}...{$to_fm}"];
if ($group_mode === 'product_of_store' && $sel_store !== '') $joudai_query['fk_店舗No'] = $sel_store;
$fmReport = new fmRESTor($host, $db, $layout_daily_report, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$report_records = ($group_mode === 'product_of_store' && $sel_store === '') ? [] : fetchAllRecords($fmReport, $joudai_query);

$joudai_by_date  = []; // date_fm => 上代合計（全店）
$joudai_by_store = []; // store_no => 上代合計（期間累計）
foreach ($report_records as $f) {
    $sno  = trim((string)($f['fk_店舗No'] ?? ''));
    $date = $f['売上日'] ?? '';
    $val  = (int)($f['上代合計'] ?? 0);
    if ($val === 0) continue;
    if ($date !== '') $joudai_by_date[$date] = ($joudai_by_date[$date] ?? 0) + $val;
    if ($sno !== '')  $joudai_by_store[$sno] = ($joudai_by_store[$sno] ?? 0) + $val;
}

// 上代は「全部門・全商品」の範囲と一致する場合のみ意味を持つ（店舗合計のみで部門別内訳が無いため）
$joudai_applicable = match ($group_mode) {
    'date' => true,
    'product' => $sel_bumon === '',
    'product_of_store' => true,
    'store_of_product' => false,
};

// ============================================================
// 集計（[バケット][商品名] => 定価/値引/廃棄 の積み上げ → バケット単位に上代を合算）
// ============================================================
$agg = [];
function ensureBucket(array &$agg, string $bucket, string $product): void {
    if (!isset($agg[$bucket][$product])) {
        $agg[$bucket][$product] = ['teika_su' => 0, 'teika_uri' => 0, 'nebiki_su' => 0, 'nebiki_uri' => 0, 'haiki_su' => 0];
    }
}

foreach ($pos_records as $f) {
    $name = trim($f['商品名'] ?? '');
    if ($name === '') continue;
    if ($group_mode === 'store_of_product' && $name !== $sel_product) continue;
    $bumon = $product_master[$name]['bumon'] ?? trim($f['部門'] ?? '');
    if ($group_mode === 'product' && $sel_bumon !== '' && $bumon !== $sel_bumon) continue;

    $bucket = match ($group_mode) {
        'date'             => $f['販売日時'] ?? '不明',
        'product', 'product_of_store' => $name,
        'store_of_product' => trim($f['店舗No'] ?? ''),
    };
    ensureBucket($agg, $bucket, $name);
    $qty    = (int)($f['数量'] ?? 0);
    $amt    = (int)($f['明細金額'] ?? $f['販売金額'] ?? 0);
    $nebiki = (int)($f['値引額'] ?? 0);
    if ($nebiki > 0) {
        $agg[$bucket][$name]['nebiki_su']  += $qty;
        $agg[$bucket][$name]['nebiki_uri'] += $amt;
    } else {
        $agg[$bucket][$name]['teika_su']  += $qty;
        $agg[$bucket][$name]['teika_uri'] += $amt;
    }
}

foreach ($haiki_records as $f) {
    $name = trim($f['商品名'] ?? '');
    if ($name === '') continue;
    if ($group_mode === 'store_of_product' && $name !== $sel_product) continue;
    $bumon = $product_master[$name]['bumon'] ?? '';
    if ($group_mode === 'product' && $sel_bumon !== '' && $bumon !== $sel_bumon) continue;

    $bucket = match ($group_mode) {
        'date'             => $f['売上日'] ?? '不明',
        'product', 'product_of_store' => $name,
        'store_of_product' => trim($f['fk_店舗No'] ?? ''),
    };
    ensureBucket($agg, $bucket, $name);
    $agg[$bucket][$name]['haiki_su'] += (int)($f['廃棄数'] ?? 0);
}

// バケット単位にロールアップ（上代は商品単位の内訳を持たないため、この時点では計算しない）
$rows = [];
foreach ($agg as $bucket => $products) {
    $teika_su = 0; $teika_uri = 0; $nebiki_su = 0; $nebiki_uri = 0; $haiki_su = 0;
    foreach ($products as $v) {
        $teika_su   += $v['teika_su'];
        $teika_uri  += $v['teika_uri'];
        $nebiki_su  += $v['nebiki_su'];
        $nebiki_uri += $v['nebiki_uri'];
        $haiki_su   += $v['haiki_su'];
    }
    $goukei = $teika_su + $nebiki_su + $haiki_su;
    $rows[$bucket] = [
        'bucket' => $bucket,
        'teika_su' => $teika_su, 'teika_uri' => $teika_uri,
        'teika_ritsu' => $goukei > 0 ? $teika_su / $goukei : null,
        'nebiki_su' => $nebiki_su, 'nebiki_uri' => $nebiki_uri,
        'nebiki_ritsu' => $goukei > 0 ? $nebiki_su / $goukei : null,
        'haiki_su' => $haiki_su,
        'haiki_ritsu' => $goukei > 0 ? $haiki_su / $goukei : null,
        'goukei' => $goukei,
        'joudai' => null,
        'joudai_ritsu' => null,
    ];
}

// 上代（店舗・日次目標の積み上げ）をバケットの意味に応じて割り当てる
// ・date: その日の全店合計 → 商品構成に関わらず一意に定まる
// ・product / product_of_store / store_of_product: 商品単位の内訳を持たないため行には出さない（該当する場合のみ合計行に設定）
if ($group_mode === 'date') {
    foreach ($rows as $bucket => &$r) {
        $j = $joudai_by_date[$bucket] ?? 0;
        $r['joudai'] = $j;
        $r['joudai_ritsu'] = $j > 0 ? ($r['teika_uri'] + $r['nebiki_uri']) / $j : null;
    }
    unset($r);
}

// 並び替え
if ($group_mode === 'date') {
    uksort($rows, function ($a, $b) {
        $da = DateTime::createFromFormat('m/d/Y', $a) ?: new DateTime('1970-01-01');
        $db = DateTime::createFromFormat('m/d/Y', $b) ?: new DateTime('1970-01-01');
        return $da <=> $db;
    });
} else {
    uasort($rows, fn($a, $b) => $b['goukei'] <=> $a['goukei']);
}

// 合計行
$total = ['teika_su' => 0, 'teika_uri' => 0, 'nebiki_su' => 0, 'nebiki_uri' => 0, 'haiki_su' => 0, 'goukei' => 0];
foreach ($rows as $r) {
    foreach (['teika_su', 'teika_uri', 'nebiki_su', 'nebiki_uri', 'haiki_su', 'goukei'] as $k) {
        $total[$k] += $r[$k];
    }
}
$total['teika_ritsu']  = $total['goukei'] > 0 ? $total['teika_su']  / $total['goukei'] : null;
$total['nebiki_ritsu'] = $total['goukei'] > 0 ? $total['nebiki_su'] / $total['goukei'] : null;
$total['haiki_ritsu']  = $total['goukei'] > 0 ? $total['haiki_su']  / $total['goukei'] : null;

// 合計行の上代：全部門・全商品の範囲と一致する場合のみ意味を持つ
if (!$joudai_applicable) {
    $total['joudai'] = null;
} elseif ($group_mode === 'date') {
    $total['joudai'] = array_sum(array_column($rows, 'joudai'));
} elseif ($group_mode === 'product') { // sel_bumon === ''（全部門）
    $total['joudai'] = array_sum($joudai_by_store);
} else { // product_of_store（選択店舗）
    $total['joudai'] = $joudai_by_store[$sel_store] ?? 0;
}
$total['joudai_ritsu'] = ($total['joudai'] !== null && $total['joudai'] > 0)
    ? ($total['teika_uri'] + $total['nebiki_uri']) / $total['joudai'] : null;

// ============================================================
// 行ラベル表示・リンク生成
// ============================================================
function rowLabel(string $group_mode, string $bucket, array $store_master): string {
    if ($group_mode === 'date') {
        $dt = DateTime::createFromFormat('m/d/Y', $bucket);
        if (!$dt) return htmlspecialchars($bucket);
        $week_ja = ['Sun' => '日', 'Mon' => '月', 'Tue' => '火', 'Wed' => '水', 'Thu' => '木', 'Fri' => '金', 'Sat' => '土'];
        return $dt->format('n/j') . '(' . ($week_ja[$dt->format('D')] ?? '') . ')';
    }
    if ($group_mode === 'store_of_product') {
        return htmlspecialchars($store_master[$bucket] ?? $bucket);
    }
    return htmlspecialchars($bucket);
}

function pct($v): string { return $v === null ? '―' : number_format($v * 100, 1) . '%'; }
function yen($v): string { return $v === null ? '―' : number_format((int)round($v)); }

$col_defs = [
    ['key' => 'teika_su',   'label' => '定価販売数', 'fmt' => 'num'],
    ['key' => 'teika_uri',  'label' => '定価売上',   'fmt' => 'yen'],
    ['key' => 'teika_ritsu','label' => '定価率',     'fmt' => 'pct'],
    ['key' => 'nebiki_su',  'label' => '値引き販売数','fmt' => 'num'],
    ['key' => 'nebiki_uri', 'label' => '値引き売上', 'fmt' => 'yen'],
    ['key' => 'nebiki_ritsu','label'=> '値引き率',   'fmt' => 'pct'],
    ['key' => 'haiki_su',   'label' => '廃棄数',     'fmt' => 'num'],
    ['key' => 'haiki_ritsu','label' => '廃棄率',     'fmt' => 'pct'],
    ['key' => 'goukei',     'label' => '合計',       'fmt' => 'num'],
    ['key' => 'joudai',     'label' => '上代',       'fmt' => 'yen'],
    ['key' => 'joudai_ritsu','label'=> '上代達成率', 'fmt' => 'pct'],
];
function fmtCell(array $col, $v): string {
    if ($v === null) return '―';
    return match ($col['fmt']) {
        'num' => number_format((int)$v),
        'yen' => '¥' . yen($v),
        'pct' => pct($v),
    };
}

// ============================================================
// CSV 書き出し
// ============================================================
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="tanpin_' . date('Ymd_His') . '.csv"');
    header('Cache-Control: no-cache, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $csvOut = fn(array $row) => fputcsv($out, $row, ',', '"', '\\');
    $csvOut(['期間', $period_jp]);
    $csvOut([]);
    $label_head = $group_mode === 'date' ? '日付' : ($group_mode === 'store_of_product' ? '店舗' : '商品名');
    $csvOut(array_merge([$label_head], array_map(fn($c) => $c['label'], $col_defs)));
    foreach ($rows as $bucket => $r) {
        $line = [$group_mode === 'date' ? $bucket : ($group_mode === 'store_of_product' ? ($store_master[$bucket] ?? $bucket) : $bucket)];
        foreach ($col_defs as $c) $line[] = fmtCell($c, $r[$c['key']]);
        $csvOut($line);
    }
    $line = ['合計'];
    foreach ($col_defs as $c) $line[] = fmtCell($c, $total[$c['key']]);
    $csvOut($line);
    exit();
}

include __DIR__ . '/hq_header.php';
?>
<style>
:root { --hq-accent: #1a237e; }
.tp-wrap { max-width: 1200px; margin: 0 auto; padding: 0 0.8em 3em; }

.top-bar {
    background: #1a237e; color: #fff;
    padding: 0.6em 1em; margin: 0 -0.8em 1em;
    display: flex; align-items: center; flex-wrap: wrap; gap: 0.5em;
}
.top-bar .m-title { font-size: 1.05em; font-weight: bold; width: 100%; }

.tp-tabs { display: flex; gap: 0.4em; margin-bottom: 0.8em; }
.tp-tab {
    flex: 1; text-align: center; padding: 0.55em 0.6em;
    border: 2px solid var(--hq-accent); border-radius: 0.6em;
    color: var(--hq-accent); background: #fff; font-weight: bold; font-size: 0.92em;
    text-decoration: none;
}
.tp-tab.active { background: var(--hq-accent); color: #fff; }

.tp-subtabs { display: flex; gap: 0.4em; margin-bottom: 0.8em; }
.tp-subtab {
    padding: 0.4em 1em; border: 1.5px solid #3949ab; border-radius: 1em;
    color: #3949ab; background: #fff; font-weight: bold; font-size: 0.85em; text-decoration: none;
}
.tp-subtab.active { background: #3949ab; color: #fff; }

.nav-select, .store-select {
    border: 1px solid rgba(255,255,255,.45); border-radius: 0.4em;
    padding: 0.3em 0.5em; font-size: 0.88em; font-weight: bold;
}
.nav-select { background: rgba(255,255,255,.15); color: #fff; }
.nav-select option { background: #1a237e; color: #fff; }
.store-select { background: #fff; color: #1a237e; min-width: 8em; }
.store-select-btn {
    background: rgba(255,255,255,.25); color: #fff; border: 1px solid rgba(255,255,255,.5);
    border-radius: 0.4em; padding: 0.35em 1.1em; font-size: 0.85em; font-weight: bold; cursor: pointer;
}
.store-select-btn:hover { background: rgba(255,255,255,.45); }
.tp-date-input {
    border: 1px solid rgba(255,255,255,.45); border-radius: 0.4em;
    padding: 0.28em 0.4em; font-size: 0.85em;
}

.tp-drill-note {
    background: #fff3e0; border-left: 3px solid #ef6c00; border-radius: 0 0.4em 0.4em 0;
    padding: 0.5em 0.9em; margin-bottom: 0.8em; font-size: 0.88em; color: #5d4037;
    display: flex; align-items: center; justify-content: space-between; gap: 1em; flex-wrap: wrap;
}
.tp-drill-note a { color: #1a237e; font-weight: bold; text-decoration: none; }

.tp-warn {
    background: #fffde7; border-left: 3px solid #f9a825; border-radius: 0 0.4em 0.4em 0;
    padding: 0.45em 0.8em; margin-bottom: 0.8em; font-size: 0.8em; color: #555;
}

.tp-table-wrap { overflow-x: auto; }
table.tp-table { width: 100%; border-collapse: collapse; font-size: 0.85em; min-width: 900px; }
table.tp-table th {
    background: #e8eaf6; color: #1a237e; padding: 0.4em 0.6em;
    text-align: center; border-bottom: 2px solid #c5cae9; white-space: nowrap;
}
table.tp-table th.left { text-align: left; }
table.tp-table td { padding: 0.35em 0.6em; border-bottom: 1px solid #f0f0f0; text-align: right; white-space: nowrap; }
table.tp-table td.left { text-align: left; }
table.tp-table td.label a { color: #1a237e; font-weight: bold; text-decoration: none; }
table.tp-table td.label a:hover { text-decoration: underline; }
table.tp-table tr:hover td { background: #f5f5fb; }
table.tp-table tfoot td {
    background: #e8eaf6; font-weight: bold; border-top: 2px solid #3949ab; padding: 0.4em 0.6em;
}
table.tp-table tfoot td.left { text-align: left; }
.tp-empty { text-align: center; padding: 3em 1em; color: #888; font-size: 0.9em; }

.tp-csv-link {
    background: rgba(255,255,255,.25); color: #fff; border: 1px solid rgba(255,255,255,.5);
    border-radius: 0.4em; padding: 0.3em 0.9em; font-size: 0.85em; font-weight: bold; text-decoration: none;
}
</style>

<div class="tp-wrap">

  <div class="top-bar">
    <span class="m-title">🔍 単品管理ダッシュボード</span>
    <form method="get" style="display:flex;align-items:center;gap:.5em;flex-wrap:wrap;">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
      <?php if ($tab === 'item'): ?><input type="hidden" name="view" value="<?= htmlspecialchars($view) ?>"><?php endif; ?>
      <input class="tp-date-input" type="date" name="from" value="<?= $from_html ?>" max="<?= $today->format('Y-m-d') ?>">
      〜
      <input class="tp-date-input" type="date" name="to" value="<?= $to_html ?>" max="<?= $today->format('Y-m-d') ?>">
      <?php if ($tab === 'item' && $view === 'product'): ?>
        <select name="bumon" class="nav-select">
          <option value="">全部門</option>
          <?php foreach ($bumon_names as $bn): ?>
            <option value="<?= htmlspecialchars($bn) ?>" <?= $bn === $sel_bumon ? 'selected' : '' ?>><?= htmlspecialchars($bn) ?></option>
          <?php endforeach; ?>
        </select>
      <?php elseif ($tab === 'item' && $view === 'store'): ?>
        <select name="store" class="store-select">
          <option value="">-- 店舗を選択 --</option>
          <?php foreach ($store_master as $sno => $sname): ?>
            <option value="<?= htmlspecialchars($sno) ?>" <?= $sno === $sel_store ? 'selected' : '' ?>><?= htmlspecialchars("{$sno} {$sname}") ?></option>
          <?php endforeach; ?>
        </select>
      <?php endif; ?>
      <button type="submit" class="store-select-btn">表示</button>
    </form>
    <a class="tp-csv-link" href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>">⬇ CSV</a>
  </div>

  <div class="tp-tabs">
    <a class="tp-tab <?= $tab === 'trend' ? 'active' : '' ?>" href="?tab=trend&from=<?= $from_html ?>&to=<?= $to_html ?>">📈 推移（全店合計）</a>
    <a class="tp-tab <?= $tab === 'item' ? 'active' : '' ?>" href="?tab=item&view=product&from=<?= $from_html ?>&to=<?= $to_html ?>">🔍 商品・店舗分析</a>
  </div>

  <?php if ($tab === 'item'): ?>
    <div class="tp-subtabs">
      <a class="tp-subtab <?= $view === 'product' ? 'active' : '' ?>" href="?tab=item&view=product&from=<?= $from_html ?>&to=<?= $to_html ?>">商品別</a>
      <a class="tp-subtab <?= $view === 'store' ? 'active' : '' ?>" href="?tab=item&view=store&from=<?= $from_html ?>&to=<?= $to_html ?>">店舗別</a>
    </div>
  <?php endif; ?>

  <?php if ($group_mode === 'store_of_product'): ?>
    <div class="tp-drill-note">
      <span>🔎 「<strong><?= htmlspecialchars($sel_product) ?></strong>」の店舗別内訳を表示しています。</span>
      <a href="?tab=item&view=product&from=<?= $from_html ?>&to=<?= $to_html ?>&bumon=<?= htmlspecialchars($sel_bumon) ?>">← 商品一覧に戻る</a>
    </div>
  <?php endif; ?>

  <?php if ($group_mode === 'store_of_product'): ?>
    <div class="tp-warn">ℹ 上代は売上日報で入力する店舗ごとの合計目標（部門・商品別の内訳なし）のため、単品の店舗別内訳では表示できません（合計行も対象外です）。</div>
  <?php elseif ($group_mode === 'product' && $sel_bumon !== ''): ?>
    <div class="tp-warn">ℹ 上代は部門別ではなく店舗ごとの合計目標のため、部門で絞り込んだ場合は表示できません（合計行も対象外です）。「全部門」に戻すと合計行に上代目標が表示されます。</div>
  <?php elseif ($group_mode === 'product' || $group_mode === 'product_of_store'): ?>
    <div class="tp-warn">ℹ 上代は売上日報で入力する店舗ごとの合計目標のため、商品単位の内訳は表示できません。合計行に表示範囲（<?= $group_mode === 'product' ? '全店舗・全部門' : '選択店舗' ?>・選択期間）の上代目標を表示しています。</div>
  <?php endif; ?>

  <?php if ($group_mode === 'product_of_store' && $sel_store === ''): ?>
    <div class="tp-empty">🏪 上部のプルダウンから店舗を選択してください。</div>
  <?php elseif (empty($rows)): ?>
    <div class="tp-empty">該当期間のデータがありません。</div>
  <?php else: ?>
    <div class="tp-table-wrap">
      <table class="tp-table">
        <thead>
          <tr>
            <th class="left"><?= $group_mode === 'date' ? '日付' : ($group_mode === 'store_of_product' ? '店舗' : '商品名') ?></th>
            <?php foreach ($col_defs as $c): ?><th><?= $c['label'] ?></th><?php endforeach; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $bucket => $r): ?>
            <tr>
              <td class="left label">
                <?php if ($group_mode === 'product'): ?>
                  <a href="?tab=item&view=product&from=<?= $from_html ?>&to=<?= $to_html ?>&bumon=<?= htmlspecialchars($sel_bumon) ?>&product=<?= urlencode($bucket) ?>"><?= rowLabel($group_mode, $bucket, $store_master) ?></a>
                <?php else: ?>
                  <?= rowLabel($group_mode, $bucket, $store_master) ?>
                <?php endif; ?>
              </td>
              <?php foreach ($col_defs as $c): ?><td><?= fmtCell($c, $r[$c['key']]) ?></td><?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="left">合計</td>
            <?php foreach ($col_defs as $c): ?><td><?= fmtCell($c, $total[$c['key']]) ?></td><?php endforeach; ?>
          </tr>
        </tfoot>
      </table>
    </div>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/footer.php'; ?>
