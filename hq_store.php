<?php
/**
 * hq_store.php  店舗別集計（プルダウンで店舗選択）
 * 選択店舗の月次成績：日別売上・客数・部門別合計・昨対
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hq') {
    header('Location: login.php'); exit();
}
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';

// ---- 月・店舗選択 ----
$today = new DateTime();
$sel_year  = (int)($_GET['year']  ?? $today->format('Y'));
$sel_month = (int)($_GET['month'] ?? $today->format('n'));
$sel_store = $_GET['store'] ?? '';

$this_ym = (int)$today->format('Ym');
$sel_ym  = $sel_year * 100 + $sel_month;
if ($sel_ym > $this_ym) {
    $sel_year  = (int)$today->format('Y');
    $sel_month = (int)$today->format('n');
    $sel_ym    = $this_ym;
}

$prev_month = $sel_month - 1; $prev_year = $sel_year;
if ($prev_month < 1)  { $prev_month = 12; $prev_year--; }
$next_month = $sel_month + 1; $next_year = $sel_year;
if ($next_month > 12) { $next_month = 1; $next_year++; }
$is_next_future = ($next_year * 100 + $next_month) > $this_ym;
$month_jp      = "{$sel_year}年{$sel_month}月";
$days_in_month = (int)date('t', mktime(0,0,0,$sel_month,1,$sel_year));
$week_ja = ['Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土'];

// ---- 当月の全日報データを1クエリで取得 ----
// ドロップダウン用の店舗リストと、選択店舗の今年データを同時に構築
$first_fm = sprintf('%02d/01/%04d', $sel_month, $sel_year);
$last_fm  = sprintf('%02d/%02d/%04d', $sel_month, $days_in_month, $sel_year);

$fm = new fmRESTor($host, $db, $layout_daily_report,
                   $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$r = $fm->findRecords([
    'query' => [['売上日' => "{$first_fm}...{$last_fm}"]],
    'sort'  => [
        ['fieldName' => 'fk_店舗No', 'sortOrder' => 'ascend'],
        ['fieldName' => '売上日',    'sortOrder' => 'ascend'],
    ],
    'limit' => 2000,
]);

$store_list = [];  // sno => name （ドロップダウン用）
$cy_days    = [];  // day => fieldData （選択店舗の今年データ）

if (($r['result']['messages'][0]['code'] ?? '0') !== '401') {
    foreach ($r['result']['response']['data'] ?? [] as $row) {
        $f  = $row['fieldData'];
        $sn = (string)($f['fk_店舗No'] ?? '');
        if ($sn === '' || $sn === '000') continue;

        // ドロップダウン用リスト（重複排除）
        if (!isset($store_list[$sn])) {
            $store_list[$sn] = $f['店舗名'] ?? $sn;
        }
        // 選択店舗の今年日別データ
        if ($sn === $sel_store) {
            $fmd = $f['売上日'] ?? '';
            $p   = explode('/', $fmd);
            $d   = isset($p[1]) ? (int)$p[1] : 0;
            if ($d >= 1) $cy_days[$d] = $f;
        }
    }
}
// 店舗No昇順
ksort($store_list);

// ---- 前年データ（選択店舗のみ）----
$py_days = [];
if ($sel_store !== '') {
    $py_year   = $sel_year - 1;
    $py_last   = (int)date('t', mktime(0,0,0,$sel_month,1,$py_year));
    $py_first  = sprintf('%02d/01/%04d', $sel_month, $py_year);
    $py_last_s = sprintf('%02d/%02d/%04d', $sel_month, $py_last, $py_year);
    $r2 = $fm->findRecords([
        'query' => [['fk_店舗No' => $sel_store, '売上日' => "{$py_first}...{$py_last_s}"]],
        'sort'  => [['fieldName' => '売上日', 'sortOrder' => 'ascend']],
        'limit' => 100,
    ]);
    if (($r2['result']['messages'][0]['code'] ?? '0') !== '401') {
        foreach ($r2['result']['response']['data'] ?? [] as $row) {
            $f   = $row['fieldData'];
            $fmd = $f['売上日'] ?? '';
            $p   = explode('/', $fmd);
            $d   = isset($p[1]) ? (int)$p[1] : 0;
            if ($d >= 1) $py_days[$d] = $f;
        }
    }
}

$store_name_sel = $store_list[$sel_store] ?? $sel_store;

// ---- 月合計 ----
$cy_total = 0; $py_total = 0; $cy_kyaku = 0;
foreach ($cy_days as $f) { $cy_total += (int)($f['合計売上'] ?? 0); $cy_kyaku += (int)($f['客数_閉店後'] ?? 0); }
// 前年合計は「当年入力済みの最終日まで」に限定して昨対を公平に比較
$max_cy_day = $cy_days ? max(array_keys($cy_days)) : 0;
foreach ($py_days as $d => $f) {
    if ($d <= $max_cy_day) $py_total += (int)($f['合計売上'] ?? 0);
}
$month_ratio = ($py_total > 0) ? round($cy_total / $py_total * 100, 1) : null;

// ---- 部門別月合計 ----
$all_busho = [
    '売上_天ぷら'         => '天ぷら',
    '売上_魚'             => '魚',
    '売上_唐揚'           => '唐揚',
    '売上_冷惣菜'         => '冷惣菜',
    '売上_催事'           => '催事',
    '売上_イカ焼'         => 'イカ焼',
    '売上_エキタカ'       => 'エキタカ',
    '売上_くじら'         => 'くじら',
    '売上_コンビニデリカ' => 'コンビニデリカ',
    '売上_セルフ唐揚'     => 'セルフ唐揚',
    '売上_セルフ天丼'     => 'セルフ天丼',
    '売上_セルフ惣菜'     => 'セルフ惣菜',
    '売上_フライ'         => 'フライ',
    '売上_串揚'           => '串揚',
    '売上_丼'             => '丼',
    '売上_個食'           => '個食',
    '売上_弁当'           => '弁当',
    '売上_弁当Ⅱ'         => '弁当Ⅱ',
    '売上_生串揚'         => '生串揚',
    '売上_鯛'             => '鯛',
];
$cy_dept = array_fill_keys(array_keys($all_busho), 0);
$py_dept = array_fill_keys(array_keys($all_busho), 0);
foreach ($cy_days as $f) {
    foreach ($all_busho as $field => $_) {
        $cy_dept[$field] += (int)($f[$field] ?? 0);
    }
}
foreach ($py_days as $d => $f) {
    if ($d <= $max_cy_day) {
        foreach ($all_busho as $field => $_) {
            $py_dept[$field] += (int)($f[$field] ?? 0);
        }
    }
}
// 当年・前年ともに0の部門は非表示
$active_busho = array_filter($all_busho,
    fn($label, $field) => $cy_dept[$field] > 0 || $py_dept[$field] > 0,
    ARRAY_FILTER_USE_BOTH
);

$today_day = ($sel_year == (int)$today->format('Y') && $sel_month == (int)$today->format('n'))
             ? (int)$today->format('j') : 0;

// ---- CSV 書き出し ----
if (isset($_GET['export']) && $_GET['export'] === 'csv' && $sel_store !== '') {
    $ym_str = $sel_year . sprintf('%02d', $sel_month);
    $fname  = 'store_' . $sel_store . '_' . $ym_str . '.csv';
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: no-cache, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    $fputcsv = fn(array $row) => fputcsv($out, $row, ',', '"', '\\');
    $fputcsv(['店舗No', $sel_store]);
    $fputcsv(['店舗名', $store_name_sel]);
    $fputcsv(['期間', "{$sel_year}年{$sel_month}月"]);
    $fputcsv([]);
    // ヘッダー：基本列 ＋ 部門列（本年・前年・昨対）
    $csv_header = ['日', '曜', '本年合計', '前年合計', '昨対比(%)', '客数', '上代', '上代達成率(%)', '状態'];
    foreach ($active_busho as $field => $label) {
        $csv_header[] = $label . '_本年';
        $csv_header[] = $label . '_前年';
        $csv_header[] = $label . '_昨対(%)';
    }
    $fputcsv($csv_header);

    $week_ja_csv = ['Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土'];
    $cy_sum_csv = 0; $py_sum_csv = 0; $kyaku_sum_csv = 0; $joudai_sum_csv = 0;
    for ($d = 1; $d <= $days_in_month; $d++) {
        $ts  = mktime(0,0,0,$sel_month,$d,$sel_year);
        $dow = $week_ja_csv[date('D', $ts)] ?? '';
        $cf  = $cy_days[$d] ?? null;
        $pf  = $py_days[$d] ?? null;
        if ($cf !== null) {
            $cy_s   = (int)($cf['合計売上'] ?? 0);
            $ky     = (int)($cf['客数_閉店後'] ?? 0);
            $status = $cf['入力状態'] ?? '入力中';
            $py_s   = ($pf !== null) ? (int)($pf['合計売上'] ?? 0) : null;
            $ratio  = ($py_s !== null && $py_s > 0) ? round($cy_s / $py_s * 100, 1) : '';
            $cy_sum_csv += $cy_s; $kyaku_sum_csv += $ky;
            if ($py_s !== null) $py_sum_csv += $py_s;
            $is_teikyu_csv = ((int)($cf['定休日'] ?? 0) === 1);
            $joudai_csv    = (int)($cf['上代合計'] ?? 0);
            $joudai_r_csv  = ($joudai_csv > 0) ? round($cy_s / $joudai_csv * 100, 1) : '';
            $joudai_sum_csv += $joudai_csv;
            if ($status !== '確定') {
                $row = [$sel_month . '/' . $d, $dow, '未確定', '未確定', '未確定', '未確定', '未確定', '未確定', $status];
                foreach ($active_busho as $_label2) { $row[] = '未確定'; $row[] = ''; $row[] = ''; }
            } elseif ($is_teikyu_csv) {
                $row = [$sel_month . '/' . $d, $dow, '定休日', '定休日', '', '', '', '', '定休日'];
                foreach ($active_busho as $_label2) { $row[] = ''; $row[] = ''; $row[] = ''; }
            } else {
                $row = [$sel_month . '/' . $d, $dow, $cy_s, ($py_s !== null ? $py_s : ''), $ratio, $ky, ($joudai_csv > 0 ? $joudai_csv : ''), $joudai_r_csv, $status];
                // 部門列を追加
                foreach ($active_busho as $field => $_label) {
                    $dcy = (int)($cf[$field] ?? 0);
                    $dpy = ($pf !== null) ? (int)($pf[$field] ?? 0) : 0;
                    $dr  = ($dcy > 0 && $dpy > 0) ? round($dcy / $dpy * 100, 1) : '';
                    $row[] = $dcy > 0 ? $dcy : '';
                    $row[] = $dpy > 0 ? $dpy : '';
                    $row[] = $dr;
                }
            }
            $fputcsv($row);
        } else {
            $row = [$sel_month . '/' . $d, $dow, '', '', '', '', '', '', ''];
            foreach ($active_busho as $_ => $__) { $row[] = ''; $row[] = ''; $row[] = ''; }
            $fputcsv($row);
        }
    }
    // 月合計行
    $total_r_csv = ($py_sum_csv > 0) ? round($cy_sum_csv / $py_sum_csv * 100, 1) : '';
    $total_joudai_r_csv = ($joudai_sum_csv > 0) ? round($cy_sum_csv / $joudai_sum_csv * 100, 1) : '';
    $total_row = ['月合計', '', $cy_sum_csv, $py_sum_csv, $total_r_csv, $kyaku_sum_csv, $joudai_sum_csv, $total_joudai_r_csv, ''];
    foreach ($active_busho as $field => $_label) {
        $dcy_t = $cy_dept[$field];
        $dpy_t = $py_dept[$field];
        $dr_t  = ($dcy_t > 0 && $dpy_t > 0) ? round($dcy_t / $dpy_t * 100, 1) : '';
        $total_row[] = $dcy_t > 0 ? $dcy_t : '';
        $total_row[] = $dpy_t > 0 ? $dpy_t : '';
        $total_row[] = $dr_t;
    }
    $fputcsv($total_row);
    fclose($out);
    exit();
}

include __DIR__ . '/hq_header.php';
?>
<style>
.hq-store-wrap { font-size: 15px; padding: 0 0 2em; }

.top-bar {
    background: #1a237e; color: #fff;
    padding: 0.6em 1em;
    display: flex; align-items: center; flex-wrap: wrap; gap: 0.5em;
}
.top-bar .m-title { font-size: 1.05em; font-weight: bold; width: 100%; }
.nav-select {
    background: rgba(255,255,255,.15); color: #fff;
    border: 1px solid rgba(255,255,255,.45);
    border-radius: 0.4em; padding: 0.3em 0.5em;
    font-size: 0.9em; font-weight: bold;
}
.nav-select option { background: #1a237e; color: #fff; }
/* 店舗プルダウン（白背景） */
.store-select {
    border: 1px solid rgba(255,255,255,.45); border-radius: 0.4em;
    padding: 0.3em 0.6em; font-size: 0.9em; font-weight: bold;
    background: #fff; color: #1a237e;
    min-width: 8em;
}
.store-select-btn {
    background: rgba(255,255,255,.25); color: #fff; border: 1px solid rgba(255,255,255,.5);
    border-radius: 0.4em; padding: 0.3em 1em;
    font-size: 0.85em; font-weight: bold; cursor: pointer;
}
.store-select-btn:hover { background: rgba(255,255,255,.45); }

/* サマリー */
.summary-bar {
    background: #fff;
    border-bottom: 2px solid #e0e0e0;
    padding: 0.5em 1em;
    display: flex; gap: 1.5em; flex-wrap: wrap; font-size: 0.88em;
}
.summary-bar .s-item { display: flex; flex-direction: column; }
.summary-bar .s-label { font-size: 0.75em; color: #888; }
.summary-bar .s-val   { font-weight: bold; color: #1a237e; }
.ratio-high { color: #2e7d32 !important; }
.ratio-low  { color: #c62828 !important; }

/* テーブル */
.day-table-wrap { overflow-x: auto; padding: 0.5em; }
table.day-table {
    border-collapse: collapse;
    font-size: 0.85em;
    white-space: nowrap;
    width: 100%;
    min-width: 420px;
}
table.day-table th {
    background: #1a237e; color: #fff;
    padding: 0.4em 0.6em; text-align: center;
    position: sticky; top: 0;
    border-right: 1px solid rgba(255,255,255,.2);
}
table.day-table td {
    padding: 0.35em 0.6em;
    border-bottom: 1px solid #e8e8e8;
    text-align: right;
    vertical-align: middle;
}
table.day-table td.date-col { text-align: center; font-weight: bold; }
table.day-table td.dow-col  { text-align: center; }
table.day-table td.dow-sun  { color: #c62828; font-weight: bold; }
table.day-table td.dow-sat  { color: #1565c0; font-weight: bold; }
table.day-table td.status-col { text-align: center; font-size: 0.8em; }
table.day-table td.empty    { color: #bbb; text-align: center; }
table.day-table tbody tr:hover td { background: #f3f4fb; }
table.day-table .today-row td { background: #fff9c4 !important; }

.ratio-high-cell { color: #2e7d32; font-weight: bold; }
.ratio-low-cell  { color: #c62828; font-weight: bold; }
.mikakunin-cell  { color: #e65100; font-weight: bold; }

table.day-table tfoot td {
    background: #e8eaf6; font-weight: bold;
    border-top: 2px solid #1a237e;
    text-align: right;
}
table.day-table tfoot td.date-col { text-align: center; }

/* 部門サブ行 */
table.day-table tr.dept-day-row td {
    background: #f5f6ff;
    padding: 0.18em 0.6em;
    border-bottom: 1px solid #ebebf5;
    font-size: 0.78em;
    color: #555;
}
table.day-table tr.dept-day-row.today-dept td { background: #fefde0; }
table.day-table tr.dept-day-row td.dept-day-label {
    padding-left: 1.8em;
    color: #555;
    font-style: italic;
    text-align: left;
}
table.day-table tr.dept-day-row td.right { text-align: right; }
table.day-table tr.dept-day-row:hover td { background: #eceeff; }
table.day-table tr.dept-day-row.today-dept:hover td { background: #faf7cb; }
/* サブ行グループの最終行に区切り線 */
table.day-table tr.dept-day-last td { border-bottom: 2px solid #dde0f5; }

/* トグルボタン */
.dept-toggle-btn {
    font-size: 0.8em;
    padding: 0.25em 0.8em;
    border: 1.5px solid #3949ab;
    border-radius: 1em;
    background: #fff;
    color: #3949ab;
    cursor: pointer;
    font-weight: bold;
    white-space: nowrap;
    transition: all .15s;
}
.dept-toggle-btn:hover { background: #3949ab; color: #fff; }

.no-store-msg { text-align: center; padding: 3em 1em; color: #888; font-size: 0.9em; }

/* 部門別明細 */
.dept-section {
    margin: 0.5em 0.5em 1.5em;
    border: 1px solid #c5cae9;
    border-radius: 0.5em;
    overflow: hidden;
}
.dept-section-head {
    background: #3949ab;
    color: #fff;
    padding: 0.4em 0.8em;
    font-size: 0.82em;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: space-between;
    user-select: none;
}
.dept-section-head .dept-arrow { transition: transform .2s; }
.dept-section-head.open .dept-arrow { transform: rotate(180deg); }
.dept-table-wrap { display: none; overflow-x: auto; }
.dept-table-wrap.open { display: block; }
table.dept-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82em;
    min-width: 320px;
}
table.dept-table th {
    background: #e8eaf6;
    color: #1a237e;
    padding: 0.35em 0.6em;
    text-align: center;
    border-bottom: 2px solid #c5cae9;
    white-space: nowrap;
}
table.dept-table th.left { text-align: left; }
table.dept-table td {
    padding: 0.32em 0.6em;
    border-bottom: 1px solid #f0f0f0;
    white-space: nowrap;
}
table.dept-table td.dept-name { font-weight: bold; color: #333; }
table.dept-table td.right { text-align: right; }
table.dept-table tr:hover td { background: #f5f5fb; }
table.dept-table tfoot td {
    background: #e8eaf6;
    font-weight: bold;
    border-top: 2px solid #3949ab;
    padding: 0.38em 0.6em;
    text-align: right;
}
table.dept-table tfoot td.dept-name { text-align: left; }
</style>

<div class="hq-store-wrap">

  <!-- 絞り込みバー（年・月・店舗を1フォームに統合） -->
  <div class="top-bar">
    <span class="m-title">🏪 店舗別集計</span>
    <form method="get" style="display:flex;align-items:center;gap:.5em;flex-wrap:wrap;">
      <select name="year" class="nav-select">
        <?php for ($y = 2023; $y <= (int)date('Y'); $y++): ?>
          <option value="<?= $y ?>" <?= $y == $sel_year ? 'selected' : '' ?>><?= $y ?>年</option>
        <?php endfor; ?>
      </select>
      <select name="month" class="nav-select">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?= $m ?>" <?= $m == $sel_month ? 'selected' : '' ?>><?= $m ?>月</option>
        <?php endfor; ?>
      </select>
      <select name="store" class="store-select">
        <option value="">-- 店舗を選択 --</option>
        <?php foreach ($store_list as $sno => $sname): ?>
          <option value="<?= htmlspecialchars($sno) ?>"
            <?= ($sno === $sel_store) ? 'selected' : '' ?>>
            <?= htmlspecialchars("{$sno} {$sname}") ?>
          </option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="store-select-btn">表示</button>
    </form>
    <?php if ($sel_store !== ''): ?>
      <a href="?year=<?= $sel_year ?>&month=<?= $sel_month ?>&store=<?= htmlspecialchars($sel_store) ?>&export=csv"
         style="background:rgba(255,255,255,.25);color:#fff;border:1px solid rgba(255,255,255,.5);
                border-radius:.4em;padding:.3em .9em;font-size:.85em;font-weight:bold;
                text-decoration:none;white-space:nowrap;margin-left:auto;">📥 Excel (CSV) 書き出し</a>
    <?php endif; ?>
  </div>

  <?php if ($sel_store === ''): ?>
    <div class="no-store-msg">店舗を選択してください。</div>

  <?php elseif (empty($cy_days) && empty($py_days)): ?>
    <div class="no-store-msg"><?= htmlspecialchars($store_name_sel) ?> の <?= $month_jp ?> データはまだありません。</div>

  <?php else: ?>

    <!-- サマリー -->
    <?php
      $r_class = ($month_ratio === null) ? '' : ($month_ratio >= 105 ? 'ratio-high' : ($month_ratio < 95 ? 'ratio-low' : ''));
    ?>
    <div class="summary-bar">
      <div class="s-item">
        <span class="s-label">店舗</span>
        <span class="s-val"><?= htmlspecialchars($store_name_sel) ?></span>
      </div>
      <div class="s-item">
        <span class="s-label">本年月計</span>
        <span class="s-val">¥<?= number_format($cy_total) ?></span>
      </div>
      <div class="s-item">
        <span class="s-label">前年月計</span>
        <span class="s-val" style="color:#888;">¥<?= number_format($py_total) ?></span>
      </div>
      <?php if ($month_ratio !== null): ?>
      <div class="s-item">
        <span class="s-label">昨対</span>
        <span class="s-val <?= $r_class ?>"><?= $month_ratio ?>%</span>
      </div>
      <?php endif; ?>
      <div class="s-item">
        <span class="s-label">月間客数</span>
        <span class="s-val"><?= number_format($cy_kyaku) ?></span>
      </div>
    </div>

    <!-- 日別テーブル -->
    <?php if (!empty($active_busho)): ?>
    <div style="padding:0.4em 0.5em 0.2em; text-align:right;">
      <button class="dept-toggle-btn" id="dept-toggle-btn" onclick="toggleDeptRows()">
        📊 部門内訳を非表示
      </button>
    </div>
    <?php endif; ?>
    <div class="day-table-wrap">
      <table class="day-table">
        <thead>
          <tr>
            <th>日</th>
            <th>曜</th>
            <th>本年売上</th>
            <th>前年売上</th>
            <th>昨対</th>
            <th>客数</th>
            <th>上代</th>
            <th>上代達成率</th>
            <th>入力状態</th>
          </tr>
        </thead>
        <tbody>
          <?php
            $cy_sum = 0; $py_sum = 0; $kyaku_sum = 0; $joudai_sum = 0;
            for ($d = 1; $d <= $days_in_month; $d++):
              $ts = mktime(0,0,0,$sel_month,$d,$sel_year);
              $dow = $week_ja[date('D', $ts)] ?? '';
              $dow_class = ($dow === '日') ? 'dow-sun' : (($dow === '土') ? 'dow-sat' : '');
              $cf = $cy_days[$d] ?? null;
              $pf = $py_days[$d] ?? null;
              $is_today = ($d == $today_day);
              $is_future = ($ts > time());

              if ($cf !== null) {
                  $cy_s = (int)($cf['合計売上'] ?? 0);
                  $ky   = (int)($cf['客数_閉店後'] ?? 0);
                  $joudai = (int)($cf['上代合計'] ?? 0);
                  $joudai_ritsu = ($joudai > 0) ? round($cy_s / $joudai * 100, 1) : null;
                  $status = $cf['入力状態'] ?? '入力中';
                  $cy_sum += $cy_s; $kyaku_sum += $ky; $joudai_sum += $joudai;
              } else {
                  $cy_s = null; $ky = null; $status = null; $joudai = null; $joudai_ritsu = null;
              }
              if ($pf !== null) {
                  $py_s = (int)($pf['合計売上'] ?? 0);
                  if ($cf !== null) $py_sum += $py_s;  // 当年データがある日のみ集計
              } else {
                  $py_s = null;
              }
              $ratio = ($py_s !== null && $py_s > 0 && $cy_s !== null)
                      ? round($cy_s / $py_s * 100, 1) : null;
              $r_class = ($ratio === null) ? '' : ($ratio >= 105 ? 'ratio-high-cell' : ($ratio < 95 ? 'ratio-low-cell' : ''));
              $is_unconfirmed = ($cf !== null && $status !== '確定');
              $is_teikyu      = ($cf !== null && (int)($cf['定休日'] ?? 0) === 1);
          ?>
          <tr class="<?= $is_today ? 'today-row' : '' ?>">
            <td class="date-col"><?= $sel_month ?>/<?= $d ?></td>
            <td class="dow-col <?= $dow_class ?>"><?= $dow ?></td>
            <?php if ($is_unconfirmed): ?>
              <td class="mikakunin-cell" style="text-align:center;">未確定</td>
              <td class="mikakunin-cell" style="text-align:center;">未確定</td>
              <td class="mikakunin-cell" style="text-align:center;">未確定</td>
              <td class="mikakunin-cell" style="text-align:center;">未確定</td>
              <td class="mikakunin-cell" style="text-align:center;">未確定</td>
              <td class="mikakunin-cell" style="text-align:center;">未確定</td>
              <td class="status-col"><span style="color:#e65100;">△</span></td>
            <?php elseif ($is_teikyu): ?>
              <td colspan="6" style="text-align:center; color:#888;">🏠 定休日</td>
              <td class="status-col"><span style="color:#2e7d32;">✅</span></td>
            <?php elseif ($cf !== null): ?>
              <td>¥<?= number_format($cy_s) ?></td>
              <td style="color:#888;"><?= $py_s !== null ? '¥' . number_format($py_s) : '－' ?></td>
              <td class="<?= $r_class ?>"><?= $ratio !== null ? $ratio . '%' : '－' ?></td>
              <td><?= number_format($ky) ?></td>
              <td style="color:#888;"><?= $joudai > 0 ? '¥' . number_format($joudai) : '－' ?></td>
              <td class="<?= $joudai_ritsu === null ? '' : ($joudai_ritsu >= 100 ? 'ratio-high-cell' : 'ratio-low-cell') ?>">
                <?= $joudai_ritsu !== null ? $joudai_ritsu . '%' : '－' ?>
              </td>
              <td class="status-col">
                <span style="color:#2e7d32;">✅</span>
              </td>
            <?php elseif ($is_future): ?>
              <?php for ($c=0;$c<6;$c++): ?><td class="empty"></td><?php endfor; ?>
              <td></td>
            <?php else: ?>
              <?php for ($c=0;$c<6;$c++): ?><td class="empty">－</td><?php endfor; ?>
              <td class="status-col" style="color:#bbb;">未</td>
            <?php endif; ?>
          </tr>
          <?php
          // --- 部門別サブ行（当年データが確定している日のみ） ---
          if ($cf !== null && !$is_unconfirmed && !$is_teikyu && !empty($active_busho)):
              // この日に値がある部門だけ抽出
              $day_active = [];
              foreach ($active_busho as $field => $label) {
                  $dcy = (int)($cf[$field] ?? 0);
                  $dpy = ($pf !== null) ? (int)($pf[$field] ?? 0) : 0;
                  if ($dcy > 0 || $dpy > 0) $day_active[$field] = $label;
              }
              $dept_keys = array_keys($day_active);
              $last_key  = end($dept_keys);
              foreach ($day_active as $field => $label):
                  $dcy = (int)($cf[$field] ?? 0);
                  $dpy = ($pf !== null) ? (int)($pf[$field] ?? 0) : 0;
                  $dr  = ($dcy > 0 && $dpy > 0) ? round($dcy / $dpy * 100, 1) : null;
                  $dr_cls = ($dr === null) ? '' : ($dr >= 105 ? 'ratio-high-cell' : ($dr < 95 ? 'ratio-low-cell' : ''));
                  $is_last = ($field === $last_key);
          ?>
          <tr class="dept-day-row <?= $is_today ? 'today-dept' : '' ?> <?= $is_last ? 'dept-day-last' : '' ?>">
            <td></td>
            <td class="dept-day-label"><?= htmlspecialchars($label) ?></td>
            <td class="right"><?= $dcy > 0 ? '¥' . number_format($dcy) : '<span style="color:#ccc;">－</span>' ?></td>
            <td class="right" style="color:#888;"><?= $dpy > 0 ? '¥' . number_format($dpy) : '<span style="color:#ccc;">－</span>' ?></td>
            <td class="right <?= $dr_cls ?>"><?= $dr !== null ? $dr . '%' : '<span style="color:#ccc;">－</span>' ?></td>
            <td></td>
            <td></td>
            <td></td>
            <td></td>
          </tr>
          <?php
              endforeach;
          endif;
          ?>
          <?php endfor; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="date-col" colspan="2">月合計</td>
            <td>¥<?= number_format($cy_sum) ?></td>
            <td style="color:#888;">¥<?= number_format($py_sum) ?></td>
            <td>
              <?php
                $total_r = ($py_sum > 0) ? round($cy_sum / $py_sum * 100, 1) : null;
                $tr_class = ($total_r === null) ? '' : ($total_r >= 105 ? 'ratio-high-cell' : ($total_r < 95 ? 'ratio-low-cell' : ''));
                echo $total_r !== null ? "<span class='{$tr_class}'>{$total_r}%</span>" : '－';
              ?>
            </td>
            <td><?= number_format($kyaku_sum) ?></td>
            <td style="color:#888;">¥<?= number_format($joudai_sum) ?></td>
            <td>
              <?php
                $total_jr = ($joudai_sum > 0) ? round($cy_sum / $joudai_sum * 100, 1) : null;
                $tjr_class = ($total_jr === null) ? '' : ($total_jr >= 100 ? 'ratio-high-cell' : 'ratio-low-cell');
                echo $total_jr !== null ? "<span class='{$tjr_class}'>{$total_jr}%</span>" : '－';
              ?>
            </td>
            <td></td>
          </tr>
        </tfoot>
      </table>
    </div>

    <!-- 部門別明細 -->
    <?php if (!empty($active_busho)): ?>
    <div class="dept-section">
      <div class="dept-section-head" id="dept-head" onclick="toggleDept()">
        📊 部門別売上明細（月累計）
        <span class="dept-arrow" id="dept-arrow">▼</span>
      </div>
      <div class="dept-table-wrap open" id="dept-body">
        <table class="dept-table">
          <thead>
            <tr>
              <th class="left">部門</th>
              <th>本年（累計）</th>
              <th>前年（同日まで）</th>
              <th>昨対比</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($active_busho as $field => $label):
              $cs = $cy_dept[$field];
              $ps = $py_dept[$field];
              $dr = ($ps > 0 && $cs > 0) ? round($cs / $ps * 100, 1) : null;
              $dr_class = ($dr === null) ? '' : ($dr >= 105 ? 'ratio-high-cell' : ($dr < 95 ? 'ratio-low-cell' : ''));
            ?>
            <tr>
              <td class="dept-name"><?= htmlspecialchars($label) ?></td>
              <td class="right"><?= $cs > 0 ? '¥' . number_format($cs) : '<span style="color:#bbb;">－</span>' ?></td>
              <td class="right" style="color:#888;"><?= $ps > 0 ? '¥' . number_format($ps) : '<span style="color:#bbb;">－</span>' ?></td>
              <td class="right <?= $dr_class ?>"><?= $dr !== null ? $dr . '%' : '<span style="color:#bbb;">－</span>' ?></td>
            </tr>
            <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <td class="dept-name">合　計</td>
              <td>¥<?= number_format($cy_total) ?></td>
              <td style="color:#888;">¥<?= number_format($py_total) ?></td>
              <td><?= $month_ratio !== null ? $month_ratio . '%' : '－' ?></td>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>
    <script>
    function toggleDept() {
        const head = document.getElementById('dept-head');
        const body = document.getElementById('dept-body');
        const isOpen = body.classList.toggle('open');
        head.classList.toggle('open', isOpen);
    }
    </script>
    <script>
    // 日別部門サブ行のトグル
    let deptRowsVisible = true;
    function toggleDeptRows() {
        deptRowsVisible = !deptRowsVisible;
        document.querySelectorAll('tr.dept-day-row').forEach(function(tr) {
            tr.style.display = deptRowsVisible ? '' : 'none';
        });
        const btn = document.getElementById('dept-toggle-btn');
        if (btn) {
            btn.textContent = deptRowsVisible ? '📊 部門内訳を非表示' : '📊 部門内訳を表示';
        }
    }
    </script>
    <?php endif; ?>

  <?php endif; ?>
</div>

<?php include __DIR__ . '/footer.php'; ?>
