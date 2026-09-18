<?php
/**
 * hq_nyuryoku.php  投入確認（全店日別売上マトリクス）
 * Excel「投入確認」シートをWeb再現
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hq') {
    header('Location: login.php'); exit();
}
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';

// ---- 年月選択 ----
$today      = new DateTime();
$sel_year   = (int)($_GET['year']  ?? $today->format('Y'));
$sel_month  = (int)($_GET['month'] ?? $today->format('n'));
if ($sel_month < 1 || $sel_month > 12) { $sel_month = (int)$today->format('n'); }
$this_ym    = (int)$today->format('Ym');
$sel_ym     = $sel_year * 100 + $sel_month;
if ($sel_ym > $this_ym) { $sel_year = (int)$today->format('Y'); $sel_month = (int)$today->format('n'); }
$py_year    = $sel_year - 1;

$days_in_month    = (int)date('t', mktime(0,0,0,$sel_month,1,$sel_year));
$days_in_py_month = (int)date('t', mktime(0,0,0,$sel_month,1,$py_year));

$prev_m = $sel_month - 1; $prev_y = $sel_year; if ($prev_m < 1) { $prev_m = 12; $prev_y--; }
$next_m = $sel_month + 1; $next_y = $sel_year; if ($next_m > 12) { $next_m = 1; $next_y++; }
$is_next_future = ($next_y * 100 + $next_m) > $this_ym;
$month_jp = "{$sel_year}年{$sel_month}月";

// ---- 新規店舗（既存店計から除外） ----
// Excel投入確認シート 除外行：堺・くずは・須磨・川崎・千里丘イズミヤ
$new_store_names = ['堺', 'くずは', '須磨', '川崎', '千里丘イズミヤ'];

// ---- 閉店店舗リスト取得（アカウントマスタ） ----
$fm_acct  = new fmRESTor($host, $db, $layout_account,
                          $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$acct_res = $fm_acct->getRecords(['_limit' => 300]);
$closed_stores = []; // sno => true
foreach ($acct_res['result']['response']['data'] ?? [] as $arec) {
    $af  = $arec['fieldData'];
    $asn = (string)($af['店舗Ｎｏ'] ?? '');
    if (trim($af['営業状態'] ?? '') === '閉店') {
        $closed_stores[$asn] = true;
    }
}

// ---- FM 接続 ----
$fm = new fmRESTor($host, $db, $layout_daily_report,
                   $api_master_user, $api_master_pass, ['allowInsecure' => true]);

// ---- 当月データ取得 ----
$first_fm = sprintf('%02d/01/%04d', $sel_month, $sel_year);
$last_fm  = sprintf('%02d/%02d/%04d', $sel_month, $days_in_month, $sel_year);
$cy_days    = [];   // $cy_days[sno][day] = fieldData
$sno_name   = [];   // sno => 店舗名
$unconfirmed = [];  // $unconfirmed[sno][day] = true（入力中だが未確定）
$teikyu     = [];   // $teikyu[sno][day] = true（定休日として確定）

$r1 = $fm->findRecords([
    'query' => [['売上日' => "{$first_fm}...{$last_fm}"]],
    'sort'  => [['fieldName'=>'fk_店舗No','sortOrder'=>'ascend'],['fieldName'=>'売上日','sortOrder'=>'ascend']],
    'limit' => 2000,
]);
if (($r1['result']['messages'][0]['code'] ?? '0') !== '401') {
    foreach ($r1['result']['response']['data'] ?? [] as $rec) {
        $f   = $rec['fieldData'];
        $sno = (string)($f['fk_店舗No'] ?? '');
        if ($sno === '' || $sno === '000') continue;
        $sno_name[$sno] = $f['店舗名'] ?? $sno;
        $fmd = $f['売上日'] ?? '';
        $p   = explode('/', $fmd);
        $d   = isset($p[1]) ? (int)$p[1] : 0;
        if ($d >= 1) {
            $cy_days[$sno][$d] = $f;
            // 入力状態が「確定」以外は未確定扱い
            if (trim($f['入力状態'] ?? '') !== '確定') {
                $unconfirmed[$sno][$d] = true;
            }
            if ((int)($f['定休日'] ?? 0) === 1) {
                $teikyu[$sno][$d] = true;
            }
        }
    }
}

// ---- 前年データ取得（同カレンダー月） ----
$py_first_fm = sprintf('%02d/01/%04d', $sel_month, $py_year);
$py_last_fm  = sprintf('%02d/%02d/%04d', $sel_month, $days_in_py_month, $py_year);
$py_days     = [];   // $py_days[sno][day] = fieldData

$r2 = $fm->findRecords([
    'query' => [['売上日' => "{$py_first_fm}...{$py_last_fm}"]],
    'sort'  => [['fieldName'=>'fk_店舗No','sortOrder'=>'ascend'],['fieldName'=>'売上日','sortOrder'=>'ascend']],
    'limit' => 2000,
]);
if (($r2['result']['messages'][0]['code'] ?? '0') !== '401') {
    foreach ($r2['result']['response']['data'] ?? [] as $rec) {
        $f   = $rec['fieldData'];
        $sno = (string)($f['fk_店舗No'] ?? '');
        if ($sno === '' || $sno === '000') continue;
        if (!isset($sno_name[$sno])) $sno_name[$sno] = $f['店舗名'] ?? $sno;
        $fmd = $f['売上日'] ?? '';
        $p   = explode('/', $fmd);
        $d   = isset($p[1]) ? (int)$p[1] : 0;
        if ($d >= 1) $py_days[$sno][$d] = $f;
    }
}

// 店舗No昇順ソート
ksort($sno_name, SORT_NATURAL);
$store_nos = array_keys($sno_name);

// ---- 集計 ----
// 新規店かどうかの判定
function is_new_store(string $name, array $new_names): bool {
    foreach ($new_names as $n) {
        if (mb_strpos($name, $n) !== false) return true;
    }
    return false;
}

// 本年・前年 店別月計
$cy_total  = []; // sno => 合計売上
$py_total  = []; // sno => 合計売上
$cy_kyaku  = []; // sno => 客数
$sakutai   = []; // sno => ratio

foreach ($store_nos as $sno) {
    $cy_t = 0; $py_t = 0; $ky = 0;
    for ($d = 1; $d <= $days_in_month; $d++) {
        $cy_t += (int)(($cy_days[$sno][$d] ?? [])['合計売上'] ?? 0);
        $ky   += (int)(($cy_days[$sno][$d] ?? [])['客数_閉店後'] ?? 0);
    }
    for ($d = 1; $d <= $days_in_py_month; $d++) {
        // 当年同日が未確定の場合は前年も除外
        if (!isset($unconfirmed[$sno][$d])) {
            $py_t += (int)(($py_days[$sno][$d] ?? [])['合計売上'] ?? 0);
        }
    }
    $cy_total[$sno] = $cy_t;
    $py_total[$sno] = $py_t;
    $cy_kyaku[$sno] = $ky;
    $sakutai[$sno]  = ($py_t > 0) ? $cy_t / $py_t : null;
}

// 売上順位・昨対順位（データのある店舗のみ）
$rank_uriage  = []; // sno => rank
$rank_sakutai = []; // sno => rank
$valid_snos   = array_filter($store_nos, fn($s) => $cy_total[$s] > 0);
$sorted_u     = $valid_snos;
usort($sorted_u, fn($a, $b) => $cy_total[$b] - $cy_total[$a]);
foreach ($sorted_u as $i => $sno) $rank_uriage[$sno] = $i + 1;
$sorted_s     = array_filter($valid_snos, fn($s) => $sakutai[$s] !== null);
usort($sorted_s, fn($a, $b) => $sakutai[$b] <=> $sakutai[$a]);
foreach ($sorted_s as $i => $sno) $rank_sakutai[$sno] = $i + 1;

// 日別全店合計 / 既存店合計 / 前年合計
$cy_all  = []; // d => 合計
$cy_kizon = []; // d => 合計（既存店）
$py_all  = []; // d => 合計
$py_kizon= []; // d => 合計（既存店）
for ($d = 1; $d <= 31; $d++) {
    $ca = 0; $ck = 0; $pa = 0; $pk = 0;
    foreach ($store_nos as $sno) {
        $name = $sno_name[$sno];
        $cy_v = (int)(($cy_days[$sno][$d] ?? [])['合計売上'] ?? 0);
        // 未確定の場合は前年を集計から除外
        $py_v = isset($unconfirmed[$sno][$d]) ? 0
                : (int)(($py_days[$sno][$d] ?? [])['合計売上'] ?? 0);
        $ca += $cy_v;
        $pa += $py_v;
        if (!is_new_store($name, $new_store_names)) {
            $ck += $cy_v;
            $pk += $py_v;
        }
    }
    $cy_all[$d]  = $ca;
    $cy_kizon[$d] = $ck;
    $py_all[$d]  = $pa;
    $py_kizon[$d] = $pk;
}

// 月計（全店・既存店）
$cy_all_total    = array_sum($cy_all);
$py_all_total    = array_sum($py_all);
$cy_kizon_total  = array_sum($cy_kizon);
$py_kizon_total  = array_sum($py_kizon);
$all_sakutai     = ($py_all_total > 0) ? $cy_all_total / $py_all_total : null;
$kizon_sakutai   = ($py_kizon_total > 0) ? $cy_kizon_total / $py_kizon_total : null;

// 本年累計（全店）・前年累計（全店）
$cy_cumul = []; $py_cumul = [];
$ca_run = 0; $pa_run = 0;
for ($d = 1; $d <= $days_in_month; $d++) {
    $ca_run += $cy_all[$d];
    $pa_run += $py_all[$d];
    $cy_cumul[$d] = $ca_run;
    $py_cumul[$d] = $pa_run;
}

// 既存店累計
$cy_k_cumul = []; $py_k_cumul = [];
$ck_run = 0; $pk_run = 0;
for ($d = 1; $d <= $days_in_month; $d++) {
    $ck_run += $cy_kizon[$d];
    $pk_run += $py_kizon[$d];
    $cy_k_cumul[$d] = $ck_run;
    $py_k_cumul[$d] = $pk_run;
}

// 客数合計（全店）
$cy_kyaku_all = 0;
foreach ($store_nos as $sno) $cy_kyaku_all += $cy_kyaku[$sno];

// ---- ヘルパー ----
function fmt_yen(int $v): string {
    return $v > 0 ? '¥' . number_format($v) : '－';
}
function fmt_pct(?float $r): string {
    if ($r === null) return '－';
    return number_format($r * 100, 1) . '%';
}
function pct_class(?float $r): string {
    if ($r === null) return '';
    if ($r >= 1.05) return 'up';
    if ($r < 0.95)  return 'down';
    return 'even';
}

// 曜日
$week_ja = ['Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土'];
function dow_class(string $dow): string {
    if ($dow === '日') return 'dow-sun';
    if ($dow === '土') return 'dow-sat';
    return '';
}

// =====================================================================
// Excel エクスポート（本年分）
// =====================================================================
if (($_GET['export'] ?? '') === 'excel') {
    $filename = "投入確認_{$sel_year}年{$sel_month}月_本年.xls";
    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
    header('Cache-Control: no-cache');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM

    // 曜日列ヘッダー
    $day_dows_ex = [];
    for ($d = 1; $d <= $days_in_month; $d++) {
        $ts = mktime(0,0,0,$sel_month,$d,$sel_year);
        $day_dows_ex[$d] = $week_ja[date('D',$ts)] ?? '';
    }

    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">';
    echo '<head><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8"></head><body>';
    echo '<table border="1">';

    // ヘッダー行1（月表示）
    $span = 6 + $days_in_month + 2;
    echo '<tr><th colspan="' . $span . '" style="background:#1a237e;color:#fff;font-size:14px;">'
       . "{$sel_year}年{$sel_month}月　投入確認（本年）"
       . '</th></tr>';

    // ヘッダー行2（列名）
    echo '<tr style="background:#1a237e;color:#fff;">';
    echo '<th>昨対順位</th><th>売上順位</th><th>昨対比</th><th>前年月計</th><th>本年月計</th><th>店舗名</th>';
    for ($d = 1; $d <= $days_in_month; $d++) {
        echo '<th>' . $d . '日(' . $day_dows_ex[$d] . ')</th>';
    }
    echo '<th>月計</th><th>客数</th>';
    echo '</tr>';

    // 店舗行
    foreach ($store_nos as $sno) {
        $name      = $sno_name[$sno];
        $is_new    = is_new_store($name, $new_store_names);
        $is_closed = isset($closed_stores[$sno]);
        $cyt       = $cy_total[$sno];
        $pyt       = $py_total[$sno];
        $ratio     = $sakutai[$sno];
        $ru        = $rank_uriage[$sno]  ?? '－';
        $rs        = $rank_sakutai[$sno] ?? '－';
        $name_disp = ($is_closed ? '★閉店 ' : '') . $name . ($is_new && !$is_closed ? '★' : '');

        echo '<tr>';
        echo '<td>' . (($cyt > 0 && $rs !== '－') ? $rs : '－') . '</td>';
        echo '<td>' . (($cyt > 0) ? $ru : '－') . '</td>';
        $pct_val = ($ratio !== null) ? number_format($ratio * 100, 1) . '%' : '－';
        echo '<td>' . $pct_val . '</td>';
        echo '<td>' . ($pyt > 0 ? $pyt : '') . '</td>';
        echo '<td>' . ($cyt > 0 ? $cyt : '') . '</td>';
        echo '<td>' . htmlspecialchars($name_disp) . '</td>';
        for ($d = 1; $d <= $days_in_month; $d++) {
            $is_uc = isset($unconfirmed[$sno][$d]);
            $is_tk = isset($teikyu[$sno][$d]);
            $v = (int)(($cy_days[$sno][$d] ?? [])['合計売上'] ?? 0);
            if ($is_uc) {
                echo '<td style="color:#e65100;font-weight:bold;">未確定</td>';
            } elseif ($is_tk) {
                echo '<td style="color:#888;font-weight:bold;">定休</td>';
            } else {
                echo '<td>' . ($v > 0 ? $v : '') . '</td>';
            }
        }
        echo '<td>' . ($cyt > 0 ? $cyt : '') . '</td>';
        echo '<td>' . ($cy_kyaku[$sno] > 0 ? $cy_kyaku[$sno] : '') . '</td>';
        echo '</tr>';
    }

    // 全店合計行
    $all_pct = ($py_all_total > 0 && $cy_all_total > 0)
               ? number_format($cy_all_total / $py_all_total * 100, 1) . '%' : '－';
    echo '<tr style="background:#e8eaf6;font-weight:bold;">';
    echo '<td colspan="2">全店</td>';
    echo '<td>' . $all_pct . '</td>';
    echo '<td>' . ($py_all_total > 0 ? $py_all_total : '') . '</td>';
    echo '<td>' . ($cy_all_total > 0 ? $cy_all_total : '') . '</td>';
    echo '<td>全店合計</td>';
    for ($d = 1; $d <= $days_in_month; $d++) {
        echo '<td>' . ($cy_all[$d] > 0 ? $cy_all[$d] : '') . '</td>';
    }
    echo '<td>' . $cy_all_total . '</td><td>' . $cy_kyaku_all . '</td>';
    echo '</tr>';

    // 既存店計行
    $kz_pct = ($py_kizon_total > 0 && $cy_kizon_total > 0)
              ? number_format($cy_kizon_total / $py_kizon_total * 100, 1) . '%' : '－';
    echo '<tr style="background:#fce4ec;font-weight:bold;">';
    echo '<td colspan="2">既存店</td>';
    echo '<td>' . $kz_pct . '</td>';
    echo '<td>' . ($py_kizon_total > 0 ? $py_kizon_total : '') . '</td>';
    echo '<td>' . ($cy_kizon_total > 0 ? $cy_kizon_total : '') . '</td>';
    echo '<td>既存店計（★除く）</td>';
    for ($d = 1; $d <= $days_in_month; $d++) {
        echo '<td>' . ($cy_kizon[$d] > 0 ? $cy_kizon[$d] : '') . '</td>';
    }
    echo '<td>' . $cy_kizon_total . '</td><td>－</td>';
    echo '</tr>';

    echo '</table></body></html>';
    exit();
}

include __DIR__ . '/hq_header.php';
?>
<style>
:root { --bg: #f5f6fa; --dark: #1a237e; }
.tu-wrap { font-size: 13px; padding: 0 0 3em; }

/* ---- ナビ ---- */
.tu-nav {
    background: var(--dark); color:#fff;
    padding: .5em 1em; display:flex; align-items:center; flex-wrap:wrap; gap:.5em;
}
.tu-nav .m-title { font-size:1.05em; font-weight:bold; width:100%; }
.nav-btn {
    background:rgba(255,255,255,.15); color:#fff;
    border:1px solid rgba(255,255,255,.4); border-radius:.4em;
    padding:.3em .8em; font-size:.9em; font-weight:bold;
    text-decoration:none; white-space:nowrap;
}
.nav-btn:hover { background:rgba(255,255,255,.3); }
.nav-btn.disabled { opacity:.35; pointer-events:none; }
.nav-btn.excel-btn {
    background: #217346; border-color: #1a5c38;
}
.nav-btn.excel-btn:hover { background: #1a5c38; }
.nav-sel {
    background:rgba(255,255,255,.15); color:#fff;
    border:1px solid rgba(255,255,255,.4); border-radius:.4em;
    padding:.3em .5em; font-size:.9em; font-weight:bold;
}
.nav-sel option { background:var(--dark); }

/* ---- テーブル共通 ---- */
.tbl-wrap {
    overflow: auto;
    max-height: calc(100vh - 130px);
    padding: .5em;
}
table.main-tbl {
    border-collapse: collapse;
    font-size: 12px;
    white-space: nowrap;
}
table.main-tbl th, table.main-tbl td {
    border: 1px solid #d0d4e8;
    padding: .3em .45em;
    text-align: right;
}
table.main-tbl thead th {
    background: var(--dark); color: #fff;
    position: sticky; top: 0; z-index: 4;
    text-align: center;
}
table.main-tbl th.dow-sun { background: #b71c1c; }
table.main-tbl th.dow-sat { background: #1565c0; }

/* 固定列 */
.col-rank1, .col-rank2, .col-ratio, .col-py, .col-cy, .col-name { position:sticky; }
.col-rank1 { left:0; z-index:3; min-width:3em; }
.col-rank2 { left:3em; z-index:3; min-width:3em; }
.col-ratio { left:6em; z-index:3; min-width:4.5em; }
.col-py    { left:10.5em; z-index:3; min-width:6em; }
.col-cy    { left:16.5em; z-index:3; min-width:6em; }
.col-name  { left:22.5em; z-index:3; min-width:6em; text-align:left !important; }
table.main-tbl th.col-rank1,
table.main-tbl th.col-rank2,
table.main-tbl th.col-ratio,
table.main-tbl th.col-py,
table.main-tbl th.col-cy,
table.main-tbl th.col-name { z-index: 6; }

/* 未確定・閉店 */
.mikakunin { color: #e65100; font-size: 0.85em; font-weight: bold; }
.teikyu-cell { color: #888; font-size: 0.85em; font-weight: bold; }
.heiten-name { color: #c62828; font-weight: bold; }

/* データ行 - tr に背景を設定してstickyセルが正しくinheritできるようにする */
table.main-tbl tbody tr         { background:#fff; }
table.main-tbl tbody tr:nth-child(even) { background:#f0f2fa; }
table.main-tbl tbody tr:hover   { background:#e8eaf6; }
table.main-tbl tbody td         { background:inherit; }

/* 集計行スタイル（背景はtr側に設定） */
.row-total    { background:#e8eaf6 !important; }
.row-total td { font-weight:bold; border-top:2px solid var(--dark); }
.row-kizon    { background:#fce4ec !important; }
.row-kizon td { font-weight:bold; }
.row-cumul    { background:#e3f2fd !important; }
.row-py-total { background:#fff8e1 !important; }
.row-py-total td { font-weight:bold; border-top:2px solid #f57f17; }
.row-py-kizon { background:#fce4ec !important; }
.row-py-cumul { background:#f3e5f5 !important; }
.row-ratio-d  { background:#e8f5e9 !important; }
.row-ratio-d td { font-weight:bold; }
.row-ratio-c  { background:#c8e6c9 !important; }
.row-ratio-c td { font-weight:bold; }
.row-ratio-kc { background:#f9fbe7 !important; }

/* 値スタイル */
.up   { color:#1b5e20; font-weight:bold; }
.down { color:#b71c1c; font-weight:bold; }
.even { color:#555; }
.dow-sun { color:#b71c1c; font-weight:bold; }
.dow-sat { color:#1565c0; font-weight:bold; }
.zero   { color:#ccc; }
.new-st { color:#e65100; font-style:italic; }

/* セクションラベル */
.section-label {
    background:var(--dark); color:#fff; font-weight:bold;
    padding:.4em 1em; font-size:.95em; margin:.8em 0 0;
}
.section-label.py-label { background:#f57f17; }

/* 今日列 */
.today-col-cell { background:#fffde7 !important; }
</style>

<div class="tu-wrap">

  <!-- 月選択ナビ -->
  <div class="tu-nav">
    <span class="m-title">📋 投入確認 &nbsp; <?= $month_jp ?></span>
    <a class="nav-btn" href="?year=<?=$prev_y?>&month=<?=$prev_m?>">◀ 前月</a>
    <form method="get" style="display:inline-flex;gap:.3em;align-items:center;">
      <select name="year" class="nav-sel" onchange="this.form.submit()">
        <?php for ($y = 2024; $y <= (int)date('Y'); $y++): ?>
          <option value="<?=$y?>" <?=$y==$sel_year?'selected':''?>><?=$y?>年</option>
        <?php endfor; ?>
      </select>
      <select name="month" class="nav-sel" onchange="this.form.submit()">
        <?php for ($m = 1; $m <= 12; $m++): ?>
          <option value="<?=$m?>" <?=$m==$sel_month?'selected':''?>><?=$m?>月</option>
        <?php endfor; ?>
      </select>
    </form>
    <a class="nav-btn <?=$is_next_future?'disabled':''?>" href="?year=<?=$next_y?>&month=<?=$next_m?>">翌月 ▶</a>
    <a class="nav-btn excel-btn"
       href="?year=<?=$sel_year?>&month=<?=$sel_month?>&export=excel"
       title="本年分をExcelでダウンロード">
      📥 Excel
    </a>
  </div>

  <?php
  // 日付ヘッダー用（曜日付き）
  $day_dows = [];
  for ($d = 1; $d <= $days_in_month; $d++) {
      $ts = mktime(0,0,0,$sel_month,$d,$sel_year);
      $day_dows[$d] = $week_ja[date('D',$ts)] ?? '';
  }
  $today_day = ($sel_year == (int)date('Y') && $sel_month == (int)date('n'))
               ? (int)date('j') : 0;
  ?>

  <!-- ====== 本年セクション ====== -->
  <div class="section-label">▶ 本年 &nbsp; <?= $month_jp ?></div>
  <div class="tbl-wrap">
  <table class="main-tbl">
    <thead>
      <tr>
        <th class="col-rank1" title="昨対順位">昨対<br>順位</th>
        <th class="col-rank2" title="売上順位">売上<br>順位</th>
        <th class="col-ratio">昨対比</th>
        <th class="col-py">前年</th>
        <th class="col-cy">本年</th>
        <th class="col-name">店舗名</th>
        <?php for ($d = 1; $d <= $days_in_month; $d++):
          $dc = dow_class($day_dows[$d]); ?>
        <th class="<?=$dc?>">
          <?=$d?>日<br><small><?=$day_dows[$d]?></small>
        </th>
        <?php endfor; ?>
        <th>合計</th>
        <th>客数</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($store_nos as $sno):
        $name      = $sno_name[$sno];
        $is_new    = is_new_store($name, $new_store_names);
        $is_closed = isset($closed_stores[$sno]);
        $cyt       = $cy_total[$sno];
        $pyt       = $py_total[$sno];
        $ratio     = $sakutai[$sno];
        $ru        = $rank_uriage[$sno]  ?? '－';
        $rs        = $rank_sakutai[$sno] ?? '－';
    ?>
    <tr>
      <td class="col-rank1"><?= ($cyt > 0 && $rs !== '－') ? $rs : '－' ?></td>
      <td class="col-rank2"><?= ($cyt > 0) ? $ru : '－' ?></td>
      <td class="col-ratio <?= pct_class($ratio) ?>"><?= fmt_pct($ratio) ?></td>
      <td class="col-py"><?= $pyt > 0 ? '¥'.number_format($pyt) : '－' ?></td>
      <td class="col-cy"><?= $cyt > 0 ? '¥'.number_format($cyt) : '－' ?></td>
      <td class="col-name <?= $is_new ? 'new-st' : '' ?>">
        <?php if ($is_closed): ?>
          <span class="heiten-name">★ <?= htmlspecialchars($name) ?></span>
        <?php else: ?>
          <?= htmlspecialchars($name) ?><?= $is_new ? '★' : '' ?>
        <?php endif; ?>
      </td>
      <?php for ($d = 1; $d <= $days_in_month; $d++):
        $day_f = $cy_days[$sno][$d] ?? null;
        $v     = (int)(($day_f ?? [])['合計売上'] ?? 0);
        $is_uc = isset($unconfirmed[$sno][$d]);
        $is_tk = isset($teikyu[$sno][$d]);
      ?>
      <td class="<?= $d===$today_day ? 'today-col-cell' : '' ?>">
        <?php if ($is_uc): ?>
          <span class="mikakunin">未確定</span>
        <?php elseif ($is_tk): ?>
          <span class="teikyu-cell">定休</span>
        <?php elseif ($v > 0): ?>
          <?= number_format($v) ?>
        <?php else: ?>
          <span class="zero">－</span>
        <?php endif; ?>
      </td>
      <?php endfor; ?>
      <td><?= $cyt > 0 ? number_format($cyt) : '<span class="zero">－</span>' ?></td>
      <td><?= $cy_kyaku[$sno] > 0 ? number_format($cy_kyaku[$sno]) : '<span class="zero">－</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>

    <!-- 全店合計 -->
    <tr class="row-total">
      <td colspan="2" style="position:sticky;left:0;z-index:3;text-align:center;">全店</td>
      <td class="col-ratio <?= pct_class($all_sakutai) ?>"><?= fmt_pct($all_sakutai) ?></td>
      <td class="col-py"><?= $py_all_total > 0 ? '¥'.number_format($py_all_total) : '－' ?></td>
      <td class="col-cy"><?= $cy_all_total > 0 ? '¥'.number_format($cy_all_total) : '－' ?></td>
      <td class="col-name">全店合計</td>
      <?php for ($d = 1; $d <= $days_in_month; $d++): ?>
      <td><?= $cy_all[$d] > 0 ? number_format($cy_all[$d]) : '<span class="zero">－</span>' ?></td>
      <?php endfor; ?>
      <td><?= number_format($cy_all_total) ?></td>
      <td><?= number_format($cy_kyaku_all) ?></td>
    </tr>

    <!-- 既存店計 -->
    <tr class="row-kizon">
      <td colspan="2" style="position:sticky;left:0;z-index:3;text-align:center;">既存店</td>
      <td class="col-ratio <?= pct_class($kizon_sakutai) ?>"><?= fmt_pct($kizon_sakutai) ?></td>
      <td class="col-py"><?= $py_kizon_total > 0 ? '¥'.number_format($py_kizon_total) : '－' ?></td>
      <td class="col-cy"><?= $cy_kizon_total > 0 ? '¥'.number_format($cy_kizon_total) : '－' ?></td>
      <td class="col-name">既存店計<br><small>★除く</small></td>
      <?php for ($d = 1; $d <= $days_in_month; $d++): ?>
      <td><?= $cy_kizon[$d] > 0 ? number_format($cy_kizon[$d]) : '<span class="zero">－</span>' ?></td>
      <?php endfor; ?>
      <td><?= number_format($cy_kizon_total) ?></td>
      <td>－</td>
    </tr>

    <!-- 本年累計（全店） -->
    <tr class="row-cumul">
      <td colspan="5" style="position:sticky;left:0;z-index:3;text-align:center;">本年累計</td>
      <td class="col-name">全店累計</td>
      <?php for ($d = 1; $d <= $days_in_month; $d++): ?>
      <td><?= $cy_cumul[$d] > 0 ? number_format($cy_cumul[$d]) : '－' ?></td>
      <?php endfor; ?>
      <td><?= number_format($cy_all_total) ?></td>
      <td>－</td>
    </tr>

    <!-- 既存店累計 -->
    <tr class="row-cumul">
      <td colspan="5" style="position:sticky;left:0;z-index:3;text-align:center;">既存店累計</td>
      <td class="col-name">既存累計</td>
      <?php for ($d = 1; $d <= $days_in_month; $d++): ?>
      <td><?= $cy_k_cumul[$d] > 0 ? number_format($cy_k_cumul[$d]) : '－' ?></td>
      <?php endfor; ?>
      <td><?= number_format($cy_kizon_total) ?></td>
      <td>－</td>
    </tr>

    <!-- 単日前比（報告店のみ） -->
    <tr class="row-ratio-d">
      <td colspan="5" style="position:sticky;left:0;z-index:3;text-align:center;">単日前比</td>
      <td class="col-name">全店 単日前比</td>
      <?php
      for ($d = 1; $d <= $days_in_month; $d++):
          // 本年データのある店舗の前年同日合計
          $py_sum_for_cy = 0; $cy_sum_for_day = 0;
          foreach ($store_nos as $sno) {
              $cy_v = (int)(($cy_days[$sno][$d] ?? [])['合計売上'] ?? 0);
              if ($cy_v > 0) {
                  $cy_sum_for_day += $cy_v;
                  $py_sum_for_py = (int)(($py_days[$sno][$d] ?? [])['合計売上'] ?? 0);
                  $py_sum_for_cy += $py_sum_for_py;
              }
          }
          $day_ratio = ($py_sum_for_cy > 0 && $cy_sum_for_day > 0)
                       ? $cy_sum_for_day / $py_sum_for_cy : null;
      ?>
      <td class="<?= pct_class($day_ratio) ?>"><?= fmt_pct($day_ratio) ?></td>
      <?php endfor; ?>
      <td class="<?= pct_class($all_sakutai) ?>"><?= fmt_pct($all_sakutai) ?></td>
      <td>－</td>
    </tr>

    <!-- 全店累計前比 -->
    <tr class="row-ratio-c">
      <td colspan="5" style="position:sticky;left:0;z-index:3;text-align:center;">累計前比</td>
      <td class="col-name">全店 累計前比</td>
      <?php for ($d = 1; $d <= $days_in_month; $d++):
          $r = ($py_cumul[$d] > 0 && $cy_cumul[$d] > 0) ? $cy_cumul[$d] / $py_cumul[$d] : null;
      ?>
      <td class="<?= pct_class($r) ?>"><?= fmt_pct($r) ?></td>
      <?php endfor; ?>
      <td class="<?= pct_class($all_sakutai) ?>"><?= fmt_pct($all_sakutai) ?></td>
      <td>－</td>
    </tr>

    <!-- 既存店累計前比 -->
    <tr class="row-ratio-kc">
      <td colspan="5" style="position:sticky;left:0;z-index:3;text-align:center;">既存店累計前比</td>
      <td class="col-name">既存 累計前比</td>
      <?php for ($d = 1; $d <= $days_in_month; $d++):
          $r = ($py_k_cumul[$d] > 0 && $cy_k_cumul[$d] > 0) ? $cy_k_cumul[$d] / $py_k_cumul[$d] : null;
      ?>
      <td class="<?= pct_class($r) ?>"><?= fmt_pct($r) ?></td>
      <?php endfor; ?>
      <td class="<?= pct_class($kizon_sakutai) ?>"><?= fmt_pct($kizon_sakutai) ?></td>
      <td>－</td>
    </tr>

  </table>
  </div><!-- /tbl-wrap -->

  <!-- ====== 前年セクション ====== -->
  <div class="section-label py-label">▶ 前年 &nbsp; <?= $py_year ?>年<?= $sel_month ?>月（前年同月）</div>
  <div class="tbl-wrap">
  <table class="main-tbl">
    <thead>
      <tr>
        <th style="min-width:4em">累計</th>
        <th class="col-name" style="position:static">店舗名</th>
        <?php for ($d = 1; $d <= $days_in_py_month; $d++):
          $ts_py = mktime(0,0,0,$sel_month,$d,$py_year);
          $dow_py = $week_ja[date('D',$ts_py)] ?? '';
          $dc = dow_class($dow_py);
        ?>
        <th class="<?=$dc?>">
          <?=$d?>日<br><small><?=$dow_py?></small>
        </th>
        <?php endfor; ?>
        <th>合計</th>
      </tr>
    </thead>
    <tbody>
    <?php foreach ($store_nos as $sno):
        $name = $sno_name[$sno];
        $pyt  = $py_total[$sno];
    ?>
    <tr>
      <td><?= $pyt > 0 ? '¥'.number_format($pyt) : '－' ?></td>
      <td style="text-align:left;"><?= htmlspecialchars($name) ?></td>
      <?php for ($d = 1; $d <= $days_in_py_month; $d++):
        $v = (int)(($py_days[$sno][$d] ?? [])['合計売上'] ?? 0);
      ?>
      <td><?= $v > 0 ? number_format($v) : '<span class="zero">－</span>' ?></td>
      <?php endfor; ?>
      <td><?= $pyt > 0 ? number_format($pyt) : '<span class="zero">－</span>' ?></td>
    </tr>
    <?php endforeach; ?>
    </tbody>

    <!-- 前年全店合計 -->
    <tr class="row-py-total">
      <td><?= $py_all_total > 0 ? '¥'.number_format($py_all_total) : '－' ?></td>
      <td style="text-align:left;">前年全店合計</td>
      <?php for ($d = 1; $d <= $days_in_py_month; $d++): ?>
      <td><?= $py_all[$d] > 0 ? number_format($py_all[$d]) : '<span class="zero">－</span>' ?></td>
      <?php endfor; ?>
      <td><?= number_format($py_all_total) ?></td>
    </tr>

    <!-- 前年既存店計 -->
    <tr class="row-py-kizon">
      <td><?= $py_kizon_total > 0 ? '¥'.number_format($py_kizon_total) : '－' ?></td>
      <td style="text-align:left;">前年既存店計</td>
      <?php for ($d = 1; $d <= $days_in_py_month; $d++): ?>
      <td><?= $py_kizon[$d] > 0 ? number_format($py_kizon[$d]) : '<span class="zero">－</span>' ?></td>
      <?php endfor; ?>
      <td><?= number_format($py_kizon_total) ?></td>
    </tr>

    <!-- 前年累計（全店） -->
    <tr class="row-py-cumul">
      <td>前年累計</td>
      <td style="text-align:left;">全店前年累計</td>
      <?php
      $py_run = 0;
      for ($d = 1; $d <= $days_in_py_month; $d++):
          $py_run += $py_all[$d];
      ?>
      <td><?= $py_run > 0 ? number_format($py_run) : '－' ?></td>
      <?php endfor; ?>
      <td><?= number_format($py_all_total) ?></td>
    </tr>

    <!-- 前年既存店累計 -->
    <tr class="row-py-cumul">
      <td>既存累計</td>
      <td style="text-align:left;">既存店前年累計</td>
      <?php
      $pk_run = 0;
      for ($d = 1; $d <= $days_in_py_month; $d++):
          $pk_run += $py_kizon[$d];
      ?>
      <td><?= $pk_run > 0 ? number_format($pk_run) : '－' ?></td>
      <?php endfor; ?>
      <td><?= number_format($py_kizon_total) ?></td>
    </tr>

  </table>
  </div><!-- /tbl-wrap -->

  <p style="font-size:.78em;color:#888;padding:.5em 1em;">
    ★：新規店（<?= implode('・', $new_store_names) ?>）は既存店計から除外。
    前年：<?=$py_year?>年<?=$sel_month?>月同カレンダー日比較。
  </p>

</div><!-- /tu-wrap -->

<?php include __DIR__ . '/footer.php'; ?>
