<?php
/**
 * daily_report_mystore.php  自店舗 月次成績閲覧
 * 当月の日別売上一覧 ＋ 昨対比（前年同日 / 前年同週同曜日）切り替え
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit(); }
if (($_SESSION['role'] ?? '') === 'hq') { header('Location: hq_top.php'); exit(); }

require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';

$store_id   = $_SESSION['store_id']   ?? '';
$store_name = $_SESSION['store_name'] ?? '';

// ---- 対象年月 ----
$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
// 範囲チェック
if ($month < 1 || $month > 12) { $month = (int)date('n'); $year = (int)date('Y'); }

$month_label = $year . '年' . $month . '月';

// 月初・月末（FileMaker形式 MM/DD/YYYY）
$first_day = mktime(0, 0, 0, $month, 1, $year);
$last_day  = mktime(0, 0, 0, $month + 1, 0, $year);
$fm_first  = date('m/d/Y', $first_day);
$fm_last   = date('m/d/Y', $last_day);

// 前年の同月前後（同週同曜日カバーのため±7日）
$py_first = mktime(0, 0, 0, $month, 1,  $year - 1);
$py_last  = mktime(0, 0, 0, $month + 1, 7, $year - 1);
$fm_py_first = date('m/d/Y', $py_first - 7 * 86400);
$fm_py_last  = date('m/d/Y', $py_last);

// ---- FileMaker 接続 ----
$fm = new fmRESTor($host, $db, $layout_daily_report_sum,
                   $api_master_user, $api_master_pass, ['allowInsecure' => true]);

// 当月データ取得
$this_data = [];
$r1 = $fm->findRecords([
    'query' => [['fk_店舗No' => $store_id, '売上日' => $fm_first . '...' . $fm_last]],
    'sort'  => [['fieldName' => '売上日', 'sortOrder' => 'ascend']],
    'limit' => 35,
]);
if (($r1['result']['messages'][0]['code'] ?? '0') !== '401') {
    foreach ($r1['result']['response']['data'] ?? [] as $rec) {
        $this_data[] = $rec['fieldData'];
    }
}
$this_data = _dedupe_by_uriage_date($this_data);

// 前年データ取得（同日・同週同曜日の両方に対応できる広めの範囲）
$prev_data = [];
$r2 = $fm->findRecords([
    'query' => [['fk_店舗No' => $store_id, '売上日' => $fm_py_first . '...' . $fm_py_last]],
    'sort'  => [['fieldName' => '売上日', 'sortOrder' => 'ascend']],
    'limit' => 45,
]);
if (($r2['result']['messages'][0]['code'] ?? '0') !== '401') {
    foreach ($r2['result']['response']['data'] ?? [] as $rec) {
        $prev_data[] = $rec['fieldData'];
    }
}
$prev_data = _dedupe_by_uriage_date($prev_data);

// ---- 前年ルックアップマップ構築 ----
// キー: 日(DD)            → 前年同日
// キー: 週番号_曜日番号   → 前年同週同曜日
$prev_by_day  = [];   // 'day' => fieldData
$prev_by_week = [];   // 'Wxx_N' => fieldData

foreach ($prev_data as $pf) {
    $pdate = _fm_to_ts($pf['売上日'] ?? '');
    if (!$pdate) continue;
    $day_key  = (int)date('j', $pdate);
    $week_key = date('W', $pdate) . '_' . date('N', $pdate);
    if (!isset($prev_by_day[$day_key]))   $prev_by_day[$day_key]   = $pf;
    if (!isset($prev_by_week[$week_key])) $prev_by_week[$week_key] = $pf;
}

// ---- 月計集計 ----
$month_total  = 0;
$month_kyaku  = 0;
$month_py_day = 0;   // 前年同日合計

foreach ($this_data as $f) {
    $month_total  += (int)($f['合計売上'] ?? 0);
    $month_kyaku  += (int)($f['客数_閉店後'] ?? 0);
    $day_key = (int)date('j', _fm_to_ts($f['売上日'] ?? ''));
    $month_py_day += (int)(($prev_by_day[$day_key] ?? [])['合計売上'] ?? 0);
}
$month_sakutai = ($month_py_day > 0)
    ? round($month_total / $month_py_day * 100, 1) : null;

// ---- 当年データを日付・週キーでインデックス ----
$this_by_day  = [];
$this_by_week = [];
foreach ($this_data as $f) {
    $ts = _fm_to_ts($f['売上日'] ?? '');
    if (!$ts) continue;
    $this_by_day[(int)date('j', $ts)] = $f;
    $this_by_week[date('W', $ts) . '_' . date('N', $ts)] = $f;
}

// 月の日数・今日
$days_in_month = (int)date('t', $first_day);
$today_ts      = mktime(0, 0, 0, (int)date('n'), (int)date('j'), (int)date('Y'));

// ---- 昨対表示モード ----
$sakutai_mode = $_GET['mode'] ?? 'day';  // 'day' or 'week'

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

// 当年部門合計
foreach ($this_data as $f2) {
    foreach ($all_busho as $field => $_) {
        $cy_dept[$field] += (int)($f2[$field] ?? 0);
    }
}
// 当年の最終入力日（前年も同日まで集計するため）
$max_cy_day = 0;
foreach ($this_data as $f2) {
    $ts2 = _fm_to_ts($f2['売上日'] ?? '');
    if ($ts2) $max_cy_day = max($max_cy_day, (int)date('j', $ts2));
}
// 前年部門合計（当年最終入力日まで・前年同日モード基準）
foreach ($prev_by_day as $day_key => $pf2) {
    if ($day_key <= $max_cy_day) {
        foreach ($all_busho as $field => $_) {
            $py_dept[$field] += (int)($pf2[$field] ?? 0);
        }
    }
}
// 当年・前年ともに0の部門は非表示
$active_busho = array_filter($all_busho,
    fn($label, $field) => $cy_dept[$field] > 0 || $py_dept[$field] > 0,
    ARRAY_FILTER_USE_BOTH
);

// ---- ヘルパー ----
function _fm_to_ts(string $fm_date): int|false {
    if (!$fm_date) return false;
    $dt = \DateTime::createFromFormat('m/d/Y', $fm_date);
    return $dt ? $dt->getTimestamp() : false;
}

/** 売上日が重複しているレコードを1件に集約する（二重登録データによる集計ズレ防止） */
function _dedupe_by_uriage_date(array $records): array {
    $byDate = [];
    foreach ($records as $f) {
        $byDate[$f['売上日'] ?? ''] = $f; // 同一日は後勝ち
    }
    return array_values($byDate);
}

$week_ja = ['1'=>'月','2'=>'火','3'=>'水','4'=>'木','5'=>'金','6'=>'土','7'=>'日'];
$jotai_badge = ['未入力'=>'secondary','入力中'=>'warning text-dark','確定'=>'success'];

// 前月・翌月ナビ
$prev_month = $month - 1; $prev_year = $year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $month + 1; $next_year = $year;
if ($next_month > 12) { $next_month = 1; $next_year++; }
$is_future_month = ($next_year > (int)date('Y') ||
    ($next_year == (int)date('Y') && $next_month > (int)date('n')));

include __DIR__ . '/header.php';
?>

<style>
:root { --pos-font-size: 16px; }
.ms-wrap {
    font-size: var(--pos-font-size);
    max-width: 720px;
    margin: 0 auto;
    padding: 0 0.5em 3em;
}

/* 月ナビ */
.month-nav {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.7em 0.5em 0.5em;
}
.month-nav-btn {
    background: #fff;
    border: 2px solid #004d40;
    color: #004d40;
    border-radius: 0.5em;
    padding: 0.3em 0.9em;
    font-size: 0.95em;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
}
.month-nav-btn:hover { background: #004d40; color: #fff; }
.month-nav-btn.disabled { opacity: 0.35; pointer-events: none; }
.month-title { font-size: 1.1em; font-weight: bold; color: #004d40; }

/* サマリーカード */
.summary-row {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 0.5em;
    margin-bottom: 0.8em;
}
.s-card {
    background: #fff;
    border: 2px solid #e0e0e0;
    border-radius: 0.7em;
    padding: 0.6em 0.8em;
    text-align: center;
    box-shadow: 0 2px 6px rgba(0,0,0,.05);
}
.s-card.main { border-color: #004d40; background: #004d40; color: #fff; }
.s-card .s-label { font-size: 0.7em; opacity: .75; margin-bottom: 0.2em; }
.s-card .s-value { font-size: 1.3em; font-weight: bold; line-height: 1.1; }
.s-card.main .s-label { color: #fff; }
.s-card.main .s-value { color: #fff; }
.s-up   { color: #2e7d32; }
.s-down { color: #c62828; }

/* 昨対切り替え */
.mode-toggle {
    display: flex;
    gap: 0.4em;
    margin-bottom: 0.6em;
}
.mode-btn {
    flex: 1;
    padding: 0.4em;
    border: 2px solid #ccc;
    border-radius: 0.5em;
    background: #f8f8f8;
    color: #666;
    font-size: 0.82em;
    font-weight: bold;
    text-align: center;
    text-decoration: none;
    cursor: pointer;
}
.mode-btn.active {
    background: #004d40;
    border-color: #004d40;
    color: #fff;
}

/* 日別テーブル */
.day-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88em;
}
.day-table th {
    background: #004d40;
    color: #fff;
    padding: 0.45em 0.5em;
    font-weight: bold;
    font-size: 0.85em;
    white-space: nowrap;
}
.day-table th.right { text-align: right; }
.day-table td {
    padding: 0.45em 0.5em;
    border-bottom: 1px solid #eee;
    vertical-align: middle;
    white-space: nowrap;
}
.day-table td.right { text-align: right; }
.day-table tr:nth-child(even) td { background: #f9f9f9; }
.day-table tr:hover td { background: #f0f8f0; }

/* 曜日色 */
.dow-sat { color: #1565c0; font-weight: bold; }
.dow-sun { color: #c62828; font-weight: bold; }
.dow-wk  { color: #444; }

/* 昨対色 */
.up   { color: #2e7d32; font-weight: bold; }
.down { color: #c62828; font-weight: bold; }
.even { color: #888; }
.mikakunin { color: #e65100; font-weight: bold; }

/* 合計行 */
.day-table tr.total-row td {
    background: #e8f5e9;
    font-weight: bold;
    border-top: 2px solid #004d40;
    color: #004d40;
}

/* 入力リンク */
.entry-link {
    color: #004d40;
    font-size: 0.8em;
    text-decoration: none;
    opacity: .6;
}
.entry-link:hover { opacity: 1; }

.empty-msg {
    text-align: center;
    padding: 3em 1em;
    color: #888;
    font-size: 0.9em;
}

/* 部門サブ行 */
.day-table tr.dept-day-row td {
    background: #f3faf5;
    padding: 0.15em 0.5em;
    border-bottom: 1px solid #e8f0ea;
    font-size: 0.78em;
    color: #555;
}
.day-table tr.dept-day-row td.dept-day-label {
    padding-left: 1.8em;
    color: #555;
    font-style: italic;
}
.day-table tr.dept-day-row td.right { text-align: right; }
.day-table tr.dept-day-row:hover td { background: #e8f5ec; }
.day-table tr.dept-day-last td { border-bottom: 2px solid #c8e6cc; }

/* 部門月集計テーブル */
.dept-month-section {
    margin-top: 1em;
    border: 1px solid #a5d6a7;
    border-radius: 0.5em;
    overflow: hidden;
}
.dept-month-head {
    background: #2e7d32;
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
.dept-month-head .dept-arrow { transition: transform .2s; }
.dept-month-head.open .dept-arrow { transform: rotate(180deg); }
.dept-month-body { display: none; overflow-x: auto; }
.dept-month-body.open { display: block; }
table.dept-month-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.82em;
    min-width: 300px;
}
table.dept-month-table th {
    background: #e8f5e9;
    color: #1b5e20;
    padding: 0.35em 0.6em;
    text-align: center;
    border-bottom: 2px solid #a5d6a7;
    white-space: nowrap;
}
table.dept-month-table th.left { text-align: left; }
table.dept-month-table td {
    padding: 0.3em 0.6em;
    border-bottom: 1px solid #f0f0f0;
    white-space: nowrap;
    text-align: right;
}
table.dept-month-table td.dept-name { text-align: left; font-weight: bold; color: #333; }
table.dept-month-table tr:hover td { background: #f1f8f2; }
table.dept-month-table tfoot td {
    background: #e8f5e9;
    font-weight: bold;
    border-top: 2px solid #2e7d32;
    text-align: right;
}
table.dept-month-table tfoot td.dept-name { text-align: left; }

/* トグルボタン */
.dept-toggle-btn {
    font-size: 0.78em;
    padding: 0.22em 0.7em;
    border: 1.5px solid #2e7d32;
    border-radius: 1em;
    background: #fff;
    color: #2e7d32;
    cursor: pointer;
    font-weight: bold;
    white-space: nowrap;
    transition: all .15s;
    margin-bottom: 0.3em;
}
.dept-toggle-btn:hover { background: #2e7d32; color: #fff; }
</style>

<div class="ms-wrap">

  <!-- 月ナビ -->
  <div class="month-nav">
    <a class="month-nav-btn"
       href="?year=<?= $prev_year ?>&month=<?= $prev_month ?>&mode=<?= $sakutai_mode ?>">
      ◀ 前月
    </a>
    <span class="month-title">📊 <?= htmlspecialchars($store_name) ?> ／ <?= $month_label ?></span>
    <a class="month-nav-btn <?= $is_future_month ? 'disabled' : '' ?>"
       href="?year=<?= $next_year ?>&month=<?= $next_month ?>&mode=<?= $sakutai_mode ?>">
      翌月 ▶
    </a>
  </div>

  <!-- サマリーカード -->
  <div class="summary-row">
    <div class="s-card main">
      <div class="s-label">月計売上</div>
      <div class="s-value">¥<?= number_format($month_total) ?></div>
    </div>
    <div class="s-card">
      <div class="s-label">月計客数</div>
      <div class="s-value" style="color:#004d40;"><?= number_format($month_kyaku) ?><span style="font-size:.6em;"> 人</span></div>
    </div>
    <div class="s-card">
      <div class="s-label">昨対（前年同日）</div>
      <div class="s-value <?= $month_sakutai === null ? '' : ($month_sakutai >= 100 ? 's-up' : 's-down') ?>">
        <?= $month_sakutai !== null ? $month_sakutai . '%' : '―' ?>
      </div>
    </div>
  </div>

  <!-- 昨対モード切り替え -->
  <div class="mode-toggle">
    <a class="mode-btn <?= $sakutai_mode === 'day'  ? 'active' : '' ?>"
       href="?year=<?= $year ?>&month=<?= $month ?>&mode=day">前年同日</a>
    <a class="mode-btn <?= $sakutai_mode === 'week' ? 'active' : '' ?>"
       href="?year=<?= $year ?>&month=<?= $month ?>&mode=week">前年同週同曜日</a>
  </div>

  <!-- 日別テーブル -->
  <?php if (!empty($active_busho)): ?>
  <div style="text-align:right; margin-bottom:.3em;">
    <button class="dept-toggle-btn" id="dept-toggle-btn" onclick="toggleDeptRows()">
      📊 部門内訳を非表示
    </button>
  </div>
  <?php endif; ?>
  <table class="day-table">
    <thead>
      <tr>
        <th>日付</th>
        <th>曜</th>
        <th class="right">合計売上</th>
        <th class="right">客数</th>
        <th class="right">上代</th>
        <th class="right">上代達成率</th>
        <th class="right">前年</th>
        <th class="right">昨対</th>
        <th>状態</th>
      </tr>
    </thead>
    <tbody>

    <?php
    $tbl_total_this   = 0;
    $tbl_total_prev   = 0;
    $tbl_total_kyaku  = 0;
    $tbl_total_joudai = 0;

    for ($d = 1; $d <= $days_in_month; $d++):
        $ts    = mktime(0, 0, 0, $month, $d, $year);
        $day_j = $d;
        $dow_n = date('N', $ts);   // 1=月 … 7=日
        $dow   = $week_ja[$dow_n] ?? '';
        $week_k = date('W', $ts) . '_' . $dow_n;
        $fm_date = date('m/d/Y', $ts);

        // 当年データ
        $f = ($sakutai_mode === 'week')
            ? ($this_by_week[$week_k] ?? null)
            : ($this_by_day[$day_j]   ?? null);
        $this_uriage = $f ? (int)($f['合計売上']   ?? 0) : 0;
        $this_kyaku  = $f ? (int)($f['客数_閉店後'] ?? 0) : 0;
        $this_joudai = $f ? (int)($f['上代合計']   ?? 0) : 0;
        $joudai_ritsu = ($this_joudai > 0) ? round($this_uriage / $this_joudai * 100, 1) : null;
        $jotai = $f ? ($f['入力状態'] ?? '未入力') : null;

        // 前年データ
        $pf = ($sakutai_mode === 'week')
            ? ($prev_by_week[$week_k] ?? null)
            : ($prev_by_day[$day_j]   ?? null);
        $prev_uriage = $pf ? (int)($pf['合計売上'] ?? 0) : 0;

        // 昨対
        $sakutai_pct = ($prev_uriage > 0 && $this_uriage > 0)
            ? round($this_uriage / $prev_uriage * 100, 1) : null;

        // 月計集計は当年データがある日のみ
        $is_future_day = ($ts > $today_ts);
        if ($f) {
            $tbl_total_this   += $this_uriage;
            $tbl_total_kyaku  += $this_kyaku;
            $tbl_total_joudai += $this_joudai;
            if ($prev_uriage > 0) $tbl_total_prev += $prev_uriage;
        }

        // 曜日スタイル
        $dow_cls = match($dow_n) { '6' => 'dow-sat', '7' => 'dow-sun', default => 'dow-wk' };

        // 昨対スタイル
        $sk_cls = '';
        if ($sakutai_pct !== null) {
            if ($sakutai_pct >= 105)    $sk_cls = 'up';
            elseif ($sakutai_pct >= 95) $sk_cls = 'even';
            else                         $sk_cls = 'down';
        }

        $date_str  = date('n/j', $ts);
        $jotai_cls = $jotai !== null ? ($jotai_badge[$jotai] ?? 'secondary') : null;
        $is_unconfirmed = ($f !== null && $jotai !== '確定');
        $is_teikyu      = ($f !== null && (int)($f['定休日'] ?? 0) === 1);

        // 未来の日はグレーアウト
        $row_style = $is_future_day ? ' style="opacity:.5;"' : '';
    ?>
    <tr<?= $row_style ?>>
      <td>
        <a href="daily_report_entry.php?date=<?= urlencode($fm_date) ?>" class="entry-link">
          <?= $date_str ?> ✎
        </a>
      </td>
      <td class="<?= $dow_cls ?>"><?= $dow ?></td>
      <?php if ($is_unconfirmed): ?>
      <td class="right mikakunin">未確定</td>
      <td class="right mikakunin">未確定</td>
      <td class="right mikakunin">未確定</td>
      <td class="right mikakunin">未確定</td>
      <td class="right mikakunin">未確定</td>
      <td class="right mikakunin">未確定</td>
      <?php elseif ($is_teikyu): ?>
      <td class="right" colspan="6" style="text-align:center; color:#888;">🏠 定休日</td>
      <?php else: ?>
      <td class="right">
        <?= $this_uriage > 0 ? '¥' . number_format($this_uriage) : '<span style="color:#ccc;">―</span>' ?>
      </td>
      <td class="right">
        <?= $this_kyaku > 0 ? number_format($this_kyaku) : '<span style="color:#ccc;">―</span>' ?>
      </td>
      <td class="right" style="color:#999; font-size:.9em;">
        <?= $this_joudai > 0 ? '¥' . number_format($this_joudai) : '<span style="color:#ddd;">―</span>' ?>
      </td>
      <td class="right <?= $joudai_ritsu === null ? '' : ($joudai_ritsu >= 100 ? 'up' : 'down') ?>">
        <?= $joudai_ritsu !== null ? $joudai_ritsu . '%' : '<span style="color:#ddd;">―</span>' ?>
      </td>
      <td class="right" style="color:#999; font-size:.9em;">
        <?= $prev_uriage > 0 ? '¥' . number_format($prev_uriage) : '<span style="color:#ddd;">―</span>' ?>
      </td>
      <td class="right <?= $sk_cls ?>">
        <?= $sakutai_pct !== null ? $sakutai_pct . '%' : '<span style="color:#ddd;">―</span>' ?>
      </td>
      <?php endif; ?>
      <td>
        <?php if ($jotai_cls !== null): ?>
          <span class="badge bg-<?= $jotai_cls ?>" style="font-size:.72em;"><?= htmlspecialchars($jotai) ?></span>
        <?php endif; ?>
      </td>
    </tr>
    <?php
    // --- 部門別サブ行（当年データが確定している日のみ） ---
    if ($f && !$is_unconfirmed && !empty($active_busho)):
        $day_active = [];
        foreach ($active_busho as $field => $label) {
            $dcy = (int)($f[$field] ?? 0);
            $dpy = $pf ? (int)($pf[$field] ?? 0) : 0;
            if ($dcy > 0 || $dpy > 0) $day_active[$field] = $label;
        }
        $dept_keys = array_keys($day_active);
        $last_key  = end($dept_keys);
        foreach ($day_active as $field => $label):
            $dcy    = (int)($f[$field] ?? 0);
            $dpy    = $pf ? (int)($pf[$field] ?? 0) : 0;
            $dr     = ($dcy > 0 && $dpy > 0) ? round($dcy / $dpy * 100, 1) : null;
            $dr_cls = ($dr === null) ? '' : ($dr >= 105 ? 'up' : ($dr < 95 ? 'down' : 'even'));
            $is_last = ($field === $last_key);
    ?>
    <tr class="dept-day-row <?= $is_last ? 'dept-day-last' : '' ?>">
      <td></td>
      <td class="dept-day-label"><?= htmlspecialchars($label) ?></td>
      <td class="right"><?= $dcy > 0 ? '¥' . number_format($dcy) : '<span style="color:#ccc;">―</span>' ?></td>
      <td class="right" style="color:#999; font-size:.9em;"></td>
      <td class="right" style="color:#999; font-size:.9em;"></td>
      <td class="right" style="color:#999; font-size:.9em;"></td>
      <td class="right" style="color:#999; font-size:.9em;"><?= $dpy > 0 ? '¥' . number_format($dpy) : '<span style="color:#ddd;">―</span>' ?></td>
      <td class="right <?= $dr_cls ?>"><?= $dr !== null ? $dr . '%' : '<span style="color:#ddd;">―</span>' ?></td>
      <td></td>
    </tr>
    <?php
        endforeach;
    endif;
    ?>
    <?php endfor; ?>

    <!-- 月計行 -->
    <?php
    $total_sakutai = ($tbl_total_prev > 0)
        ? round($tbl_total_this / $tbl_total_prev * 100, 1) : null;
    $total_sk_cls = '';
    if ($total_sakutai !== null) {
        $total_sk_cls = $total_sakutai >= 105 ? 'up' : ($total_sakutai >= 95 ? 'even' : 'down');
    }
    $total_joudai_ritsu = ($tbl_total_joudai > 0)
        ? round($tbl_total_this / $tbl_total_joudai * 100, 1) : null;
    ?>
    <tr class="total-row">
      <td colspan="2">月　計</td>
      <td class="right">¥<?= number_format($tbl_total_this) ?></td>
      <td class="right"><?= number_format($tbl_total_kyaku) ?></td>
      <td class="right" style="font-size:.9em;">¥<?= number_format($tbl_total_joudai) ?></td>
      <td class="right <?= $total_joudai_ritsu === null ? '' : ($total_joudai_ritsu >= 100 ? 'up' : 'down') ?>">
        <?= $total_joudai_ritsu !== null ? $total_joudai_ritsu . '%' : '―' ?>
      </td>
      <td class="right" style="font-size:.9em;">¥<?= number_format($tbl_total_prev) ?></td>
      <td class="right <?= $total_sk_cls ?>">
        <?= $total_sakutai !== null ? $total_sakutai . '%' : '―' ?>
      </td>
      <td></td>
    </tr>

    </tbody>
  </table>

  <p style="font-size:.75em; color:#aaa; margin-top:.5em; text-align:right;">
    昨対モード：<?= $sakutai_mode === 'week' ? '前年同週同曜日' : '前年同日' ?>
    ／ 前年：<?= $year - 1 ?>年<?= $month ?>月
  </p>

  <!-- 部門別月集計 -->
  <?php if (!empty($active_busho)): ?>
  <div class="dept-month-section">
    <div class="dept-month-head open" id="dept-month-head" onclick="toggleDeptMonth()">
      📊 部門別月集計（前年同日累計）
      <span class="dept-arrow open" id="dept-month-arrow">▼</span>
    </div>
    <div class="dept-month-body open" id="dept-month-body">
      <table class="dept-month-table">
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
            $dr = ($cs > 0 && $ps > 0) ? round($cs / $ps * 100, 1) : null;
            $dr_cls = ($dr === null) ? '' : ($dr >= 105 ? 'up' : ($dr < 95 ? 'down' : 'even'));
          ?>
          <tr>
            <td class="dept-name"><?= htmlspecialchars($label) ?></td>
            <td><?= $cs > 0 ? '¥' . number_format($cs) : '<span style="color:#bbb;">―</span>' ?></td>
            <td style="color:#888;"><?= $ps > 0 ? '¥' . number_format($ps) : '<span style="color:#bbb;">―</span>' ?></td>
            <td class="<?= $dr_cls ?>"><?= $dr !== null ? $dr . '%' : '<span style="color:#bbb;">―</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
        <tfoot>
          <tr>
            <td class="dept-name">合　計</td>
            <td>¥<?= number_format($month_total) ?></td>
            <td style="color:#888;">¥<?= number_format(array_sum($py_dept)) ?></td>
            <td class="<?= $month_sakutai !== null ? ($month_sakutai >= 105 ? 'up' : ($month_sakutai < 95 ? 'down' : 'even')) : '' ?>">
              <?= $month_sakutai !== null ? $month_sakutai . '%' : '―' ?>
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  </div>
  <script>
  let deptRowsVisible = true;
  function toggleDeptRows() {
      deptRowsVisible = !deptRowsVisible;
      document.querySelectorAll('tr.dept-day-row').forEach(tr => {
          tr.style.display = deptRowsVisible ? '' : 'none';
      });
      const btn = document.getElementById('dept-toggle-btn');
      if (btn) btn.textContent = deptRowsVisible ? '📊 部門内訳を非表示' : '📊 部門内訳を表示';
  }
  function toggleDeptMonth() {
      const head = document.getElementById('dept-month-head');
      const body = document.getElementById('dept-month-body');
      const isOpen = body.classList.toggle('open');
      head.classList.toggle('open', isOpen);
  }
  </script>
  <?php endif; ?>

</div>

<?php include __DIR__ . '/footer.php'; ?>
