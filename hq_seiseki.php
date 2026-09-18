<?php
/**
 * hq_seiseki.php  成績一覧（月次店舗ランキング）
 * 売上順 / 店舗名 / 本年 / 昨年 / 昨対比 / 昨対順序
 * 昨年データのみの店舗も表示。CSV エクスポート対応。
 */
use fmRESTor\fmRESTor;
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hq') {
    header('Location: login.php'); exit();
}
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';

// ---- 月選択 ----
$today = new DateTime();
$sel_year  = (int)($_GET['year']  ?? $today->format('Y'));
$sel_month = (int)($_GET['month'] ?? $today->format('n'));

$this_ym  = (int)$today->format('Ym');
$sel_ym   = $sel_year * 100 + $sel_month;
if ($sel_ym > $this_ym) {
    $sel_year = (int)$today->format('Y');
    $sel_month = (int)$today->format('n');
    $sel_ym = $this_ym;
}

$prev_month = $sel_month - 1; $prev_year = $sel_year;
if ($prev_month < 1) { $prev_month = 12; $prev_year--; }
$next_month = $sel_month + 1; $next_year = $sel_year;
if ($next_month > 12) { $next_month = 1; $next_year++; }
$is_next_future = ($next_year * 100 + $next_month) > $this_ym;

$month_jp = "{$sel_year}年{$sel_month}月";

// ---- 昨対モード ----
$sakutai_mode = $_GET['mode'] ?? 'day';  // 'day' or 'week'

// ---- 日付レンジ（FM形式 MM/DD/YYYY）----
$first_day    = sprintf('%02d/01/%04d', $sel_month, $sel_year);
$last_day_num = (int)date('t', mktime(0,0,0,$sel_month,1,$sel_year));
$last_day     = sprintf('%02d/%02d/%04d', $sel_month, $last_day_num, $sel_year);
$range_cy     = "{$first_day}...{$last_day}";

$py_year = $sel_year - 1;
// 前年は同週同曜日対応のため ±7日バッファ付きで取得
$py_from_ts = mktime(0,0,0,$sel_month,1,$py_year)   - 7*86400;
$py_to_ts   = mktime(0,0,0,$sel_month+1,0,$py_year) + 7*86400;
$range_py   = date('m/d/Y',$py_from_ts) . '...' . date('m/d/Y',$py_to_ts);

// ---- ヘルパー ----
function hq_fm_ts(string $s): int|false {
    if (!$s) return false;
    $dt = \DateTime::createFromFormat('m/d/Y', $s);
    return $dt ? $dt->getTimestamp() : false;
}

// ---- FM からデータ取得 ----
$fm = new fmRESTor($host, $db, $layout_daily_report,
                   $api_master_user, $api_master_pass, ['allowInsecure' => true]);

// 前年レコードをインデックス化
// [sn][day_j]=sales, [sn][week_dow]=sales
$py_by_day      = [];   // [sn][1..31]  = sales
$py_by_week     = [];   // [sn]['WW_N'] = sales
$py_names       = [];   // [sn] = 店舗名
$py_month_total = [];   // [sn] = 前年同月合計（cy未入力店用）

$r2 = $fm->findRecords(['query' => [['売上日' => $range_py]], 'limit' => 2000]);
if (($r2['result']['messages'][0]['code'] ?? '0') !== '401') {
    foreach ($r2['result']['response']['data'] ?? [] as $row) {
        $f  = $row['fieldData'];
        $sn = (string)($f['fk_店舗No'] ?? '');
        if ($sn === '') continue;
        $ts = hq_fm_ts($f['売上日'] ?? '');
        if (!$ts) continue;
        $d   = (int)date('j', $ts);
        $wk  = date('W', $ts) . '_' . date('N', $ts);
        $s   = (int)($f['合計売上'] ?? 0);
        // py_by_day は対象月内のレコードのみ（バッファ月のデータで上書きしないよう）
        if ((int)date('n',$ts) === $sel_month && (int)date('Y',$ts) === $py_year) {
            $py_by_day[$sn][$d] = $s;
            $py_month_total[$sn] = ($py_month_total[$sn] ?? 0) + $s;
        }
        // py_by_week は週モード用にバッファ込みで全件インデックス
        $py_by_week[$sn][$wk] = $s;
        $py_names[$sn] = $f['店舗名'] ?? $sn;
    }
}

// 当年レコード集計 ＋ 対応する前年を累計
$cy_data    = [];
$max_cy_day = 0;       // 当年の最大日（閉店店舗の前年同日までカット用）
$cy_week_keys = [];    // 当年に存在する週キー（週モード用）

$r = $fm->findRecords(['query' => [['売上日' => $range_cy]], 'limit' => 2000]);
if (($r['result']['messages'][0]['code'] ?? '0') !== '401') {
    // 店舗No+売上日が重複しているレコードは1件に集約する（二重登録データによる集計ズレ防止）
    $cy_rows_by_key = [];
    foreach ($r['result']['response']['data'] ?? [] as $row) {
        $f  = $row['fieldData'];
        $sn = (string)($f['fk_店舗No'] ?? '');
        $key = $sn . '|' . ($f['売上日'] ?? '');
        $cy_rows_by_key[$key] = $f; // 同一店舗・同一日は後勝ち
    }
    foreach ($cy_rows_by_key as $f) {
        $sn = (string)($f['fk_店舗No'] ?? '');
        if ($sn === '') continue;
        $ts = hq_fm_ts($f['売上日'] ?? '');
        if (!$ts) continue;
        $d  = (int)date('j', $ts);
        $wk = date('W', $ts) . '_' . date('N', $ts);
        // 閉店店舗の前年カット用：当年全店舗の最大日・週を記録
        if ($d > $max_cy_day) $max_cy_day = $d;
        $cy_week_keys[$wk] = true;
        if (!isset($cy_data[$sn])) {
            $cy_data[$sn] = ['store_name' => $f['店舗名'] ?? $sn,
                             'cy_sales' => 0, 'py_matched' => 0,
                             'days' => 0, 'kakutei' => 0];
        }
        $cy_data[$sn]['cy_sales'] += (int)($f['合計売上'] ?? 0);
        $cy_data[$sn]['days']++;
        // 確定件数カウント
        if (($f['入力状態'] ?? '') === '確定') {
            $cy_data[$sn]['kakutei']++;
        }
        // 前年マッチングは全日（確定・入力中とも）対象
        // ※ 当年に売上日があれば前年の対応日を累計（未確定日でも本年売上に含まれるため）
        $py_s = ($sakutai_mode === 'week')
            ? ($py_by_week[$sn][$wk] ?? 0)
            : ($py_by_day[$sn][$d]   ?? 0);
        $cy_data[$sn]['py_matched'] += $py_s;
    }
}

// ---- 全店舗をマージ ----
$all_sns = array_unique(array_merge(array_keys($cy_data), array_keys($py_names)));
$stores  = [];
foreach ($all_sns as $sn) {
    $cy      = $cy_data[$sn] ?? null;
    $cy_s    = $cy ? $cy['cy_sales']   : 0;
    // cy あり → マッチング前年累計
    // cy なし（閉店等）→ 前年の「当年最終入力日と同日まで」の累積
    if ($cy) {
        $py_s = $cy['py_matched'];
    } elseif ($sakutai_mode === 'week') {
        // 週モード：当年に存在する週キーのみ合算
        $py_s = 0;
        foreach (array_keys($cy_week_keys) as $wk_key) {
            $py_s += $py_by_week[$sn][$wk_key] ?? 0;
        }
    } else {
        // 日モード：前年の1日〜$max_cy_day 日まで合算
        $py_s = 0;
        for ($d2 = 1; $d2 <= $max_cy_day; $d2++) {
            $py_s += $py_by_day[$sn][$d2] ?? 0;
        }
    }
    $name    = ($cy['store_name'] ?? '') ?: ($py_names[$sn] ?? $sn);
    $ratio   = ($py_s > 0 && $cy_s > 0) ? round($cy_s / $py_s * 100, 1) : null;
    $stores[$sn] = [
        'store_no'   => $sn,
        'store_name' => $name,
        'cy_sales'   => $cy_s,
        'py_sales'   => $py_s,
        'ratio'      => $ratio,
        'days'       => $cy ? $cy['days']    : 0,
        'kakutei'    => $cy ? $cy['kakutei'] : 0,
        'has_cy'     => $cy !== null,
    ];
}

// ---- ランキング計算 ----
// 売上順：本年売上の降順（今年データなし店舗は末尾）
$stores_for_sales = $stores;
uasort($stores_for_sales, function($a, $b) {
    if ($a['has_cy'] !== $b['has_cy']) return $a['has_cy'] ? -1 : 1;
    return $b['cy_sales'] <=> $a['cy_sales'];
});
$rank_sales = [];
$i = 1;
foreach ($stores_for_sales as $sn => $_) { $rank_sales[$sn] = $i++; }

// 昨対順：昨対比の降順（null は末尾）
$stores_for_ratio = $stores;
uasort($stores_for_ratio, function($a, $b) {
    if ($a['ratio'] === null && $b['ratio'] === null) return 0;
    if ($a['ratio'] === null) return 1;
    if ($b['ratio'] === null) return -1;
    return $b['ratio'] <=> $a['ratio'];
});
$rank_ratio = [];
$i = 1;
foreach ($stores_for_ratio as $sn => $_) { $rank_ratio[$sn] = $i++; }

// 表示は売上順
$display_order = array_keys($stores_for_sales);

// ---- 全体集計（2系統）----
// ① 現行店舗のみ（当年データあり）
$active_stores   = array_filter($stores, fn($s) => $s['has_cy']);
$total_cy_act    = array_sum(array_column($active_stores, 'cy_sales'));
$total_py_act    = array_sum(array_column($active_stores, 'py_sales'));
$total_ratio_act = ($total_py_act > 0 && $total_cy_act > 0)
                   ? round($total_cy_act / $total_py_act * 100, 1) : null;

// ② 全店（前年のみ店舗も含む）
$total_cy_all    = array_sum(array_column($stores, 'cy_sales'));
$total_py_all    = array_sum(array_column($stores, 'py_sales'));
$total_ratio_all = ($total_py_all > 0 && $total_cy_all > 0)
                   ? round($total_cy_all / $total_py_all * 100, 1) : null;

// 後方互換（サマリーバー用）
$total_cy    = $total_cy_act;
$total_py    = $total_py_act;
$total_ratio = $total_ratio_act;

// ---- CSV エクスポート ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="seiseki_' . $sel_year . sprintf('%02d', $sel_month) . '.csv"');
    $out = fopen('php://output', 'w');
    // UTF-8 BOM（Excel 文字化け防止）
    fwrite($out, "\xEF\xBB\xBF");
    fputcsv($out, ['売上順', '店舗名', '本年', '昨年', '昨対比(%)', '昨対順序']);
    foreach ($display_order as $sn) {
        $s = $stores[$sn];
        fputcsv($out, [
            $rank_sales[$sn],
            $s['store_name'],
            $s['has_cy'] ? $s['cy_sales'] : '',
            $s['py_sales'] > 0 ? $s['py_sales'] : '',
            $s['ratio'] !== null ? $s['ratio'] : '',
            $rank_ratio[$sn],
        ]);
    }
    // 合計行（2系統）
    fputcsv($out, ['', '現行店舗 合計', $total_cy_act, $total_py_act, $total_ratio_act ?? '', '']);
    fputcsv($out, ['', '全店 合計（前年店含む）', $total_cy_all, $total_py_all, $total_ratio_all ?? '', '']);
    fclose($out);
    exit();
}

include __DIR__ . '/hq_header.php';
?>
<style>
.hq-seiseki-wrap { font-size: 16px; padding: 0 0 2em; }

.month-nav {
    background: #1a237e; color: #fff;
    padding: 0.5em 1em;
    display: flex; align-items: center; justify-content: space-between;
    flex-wrap: wrap; gap: 0.4em;
}
.month-nav .m-title { font-size: 1.1em; font-weight: bold; flex: 1; }
.nav-select {
    background: rgba(255,255,255,.15); color: #fff;
    border: 1px solid rgba(255,255,255,.45);
    border-radius: 0.4em; padding: 0.3em 0.5em;
    font-size: 0.9em; font-weight: bold;
}
.nav-select option { background: #1a237e; color: #fff; }
.nav-btn-go {
    background: rgba(255,255,255,.25); color: #fff;
    border: 1px solid rgba(255,255,255,.5);
    border-radius: 0.4em; padding: 0.3em 1em;
    font-size: 0.85em; font-weight: bold; cursor: pointer;
    transition: background .15s;
}
.nav-btn-go:hover { background: rgba(255,255,255,.45); }

.summary-bar {
    background: #e8eaf6;
    padding: 0.4em 1em;
    font-size: 0.85em; color: #444;
    display: flex; gap: 1.5em; flex-wrap: wrap; align-items: center;
}
.summary-bar strong { color: #1a237e; }

.export-bar {
    padding: 0.5em 1em;
    text-align: right;
}
.btn-export {
    background: #fff; color: #1a237e;
    border: 2px solid #1a237e;
    border-radius: 0.4em; padding: 0.3em 1em;
    font-size: 0.82em; font-weight: bold;
    text-decoration: none; cursor: pointer;
    transition: all .15s;
}
.btn-export:hover { background: #1a237e; color: #fff; }

/* テーブル */
.seiseki-table-wrap { overflow-x: auto; padding: 0 0.5em 1em; }
.seiseki-table {
    width: 100%; border-collapse: collapse;
    font-size: 0.9em; min-width: 460px;
}
.seiseki-table th {
    background: #1a237e; color: #fff;
    padding: 0.45em 0.7em; text-align: center;
    white-space: nowrap; font-size: 0.85em;
    position: sticky; top: 0;
}
.seiseki-table th.left { text-align: left; }
.seiseki-table td {
    padding: 0.4em 0.7em;
    border-bottom: 1px solid #e0e0e0;
    white-space: nowrap; vertical-align: middle;
}
.seiseki-table td.center { text-align: center; }
.seiseki-table td.right  { text-align: right; }
.seiseki-table tbody tr:hover { background: #f3f4fb; }
.seiseki-table tbody tr:nth-child(even) { background: #fafafa; }
.seiseki-table tbody tr:nth-child(even):hover { background: #f3f4fb; }
/* 今年データなし行 */
.seiseki-table tbody tr.no-cy td { color: #999; background: #fafafa; }
.seiseki-table tbody tr.no-cy:hover td { background: #f3f4fb; }

.rank-badge {
    display: inline-block; min-width: 2em; text-align: center;
    font-weight: bold; font-size: 0.9em;
    padding: 1px 4px; border-radius: 0.3em;
}
.rank-badge.top3  { background: #ffd700; color: #5d4037; }
.rank-badge.other { background: #e8eaf6; color: #333; }

.ratio-high { color: #2e7d32; font-weight: bold; }
.ratio-mid  { color: #333; }
.ratio-low  { color: #c62828; font-weight: bold; }

.empty-msg { text-align: center; padding: 3em 1em; color: #888; font-size: 0.9em; }

/* モード切り替え */
.mode-toggle {
    display: flex; gap: 0.4em;
    padding: 0.4em 1em 0;
}
.mode-btn {
    flex: 1; max-width: 200px;
    padding: 0.35em 0.8em;
    border: 2px solid #c5cae9;
    border-radius: 0.5em;
    background: #f8f8ff;
    color: #555;
    font-size: 0.82em; font-weight: bold;
    text-align: center; text-decoration: none;
}
.mode-btn.active {
    background: #1a237e; border-color: #1a237e; color: #fff;
}

/* 合計行 */
tfoot.seiseki-foot td {
    background: #1a237e; color: #fff;
    font-weight: bold; padding: 0.45em 0.7em;
    border-top: 2px solid #0d1757;
}
tfoot.seiseki-foot td.right { text-align: right; }
tfoot.seiseki-foot td.center { text-align: center; }
</style>

<div class="hq-seiseki-wrap">

  <!-- 月ナビ -->
  <div class="month-nav">
    <span class="m-title">🏆 成績一覧</span>
    <form method="get" style="display:flex;align-items:center;gap:.4em;flex-wrap:wrap;">
      <?php if (isset($_GET['export'])) { /* exportパラメータは除外 */ } ?>
      <input type="hidden" name="mode" value="<?= htmlspecialchars($sakutai_mode) ?>">
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
      <button type="submit" class="nav-btn-go">表示</button>
    </form>
  </div>

  <!-- サマリー -->
  <div class="summary-bar">
    <div>現行店舗 <strong><?= count($active_stores) ?></strong>店
      　本年 <strong>¥<?= number_format($total_cy_act) ?></strong>
      <?php if ($total_py_act > 0): ?>
        　前年 <strong>¥<?= number_format($total_py_act) ?></strong>
        <?php if ($total_ratio_act !== null): ?>
          　昨対 <strong><?= $total_ratio_act ?>%</strong>
        <?php endif; ?>
      <?php endif; ?>
    </div>
    <div style="color:#888;">（前年含む全店）
      本年 ¥<?= number_format($total_cy_all) ?>
      <?php if ($total_py_all > 0): ?>
        　前年 ¥<?= number_format($total_py_all) ?>
        <?php if ($total_ratio_all !== null): ?>
          　昨対 <?= $total_ratio_all ?>%
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- 昨対モード切り替え -->
  <div class="mode-toggle">
    <a class="mode-btn <?= $sakutai_mode === 'day'  ? 'active' : '' ?>"
       href="?year=<?= $sel_year ?>&month=<?= $sel_month ?>&mode=day">前年同日累計</a>
    <a class="mode-btn <?= $sakutai_mode === 'week' ? 'active' : '' ?>"
       href="?year=<?= $sel_year ?>&month=<?= $sel_month ?>&mode=week">前年同週同曜日累計</a>
  </div>

  <!-- エクスポート -->
  <div class="export-bar">
    <a href="hq_seiseki.php?year=<?= $sel_year ?>&month=<?= $sel_month ?>&mode=<?= $sakutai_mode ?>&export=csv"
       class="btn-export">📥 Excel (CSV) 書き出し</a>
  </div>

  <!-- テーブル -->
  <div class="seiseki-table-wrap">
    <?php if (empty($stores)): ?>
      <div class="empty-msg"><?= $month_jp ?> のデータはまだありません。</div>
    <?php else: ?>
    <table class="seiseki-table">
      <thead>
        <tr>
          <th>売上順</th>
          <th class="left">店舗名</th>
          <th>本年</th>
          <th><?= $sakutai_mode === 'week' ? '前年同週同曜日累計' : '前年同日累計' ?></th>
          <th>昨対比</th>
          <th>昨対順序</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($display_order as $sn):
          $s      = $stores[$sn];
          $rs     = $rank_sales[$sn];
          $rr     = $rank_ratio[$sn];
          $ratio  = $s['ratio'];
          $has_cy = $s['has_cy'];
          $ratio_class = ($ratio === null) ? 'ratio-mid'
                       : ($ratio >= 105 ? 'ratio-high' : ($ratio < 95 ? 'ratio-low' : 'ratio-mid'));
        ?>
        <tr class="<?= !$has_cy ? 'no-cy' : '' ?>">
          <td class="center">
            <?php if ($has_cy): ?>
              <span class="rank-badge <?= $rs <= 3 ? 'top3' : 'other' ?>"><?= $rs ?></span>
            <?php else: ?>
              <span style="color:#bbb;">－</span>
            <?php endif; ?>
          </td>
          <td><?= htmlspecialchars($s['store_name']) ?></td>
          <td class="right">
            <?= $has_cy ? '¥' . number_format($s['cy_sales']) : '<span style="color:#bbb;">－</span>' ?>
          </td>
          <td class="right" style="color:#777;">
            <?= $s['py_sales'] > 0 ? '¥' . number_format($s['py_sales']) : '<span style="color:#bbb;">－</span>' ?>
          </td>
          <td class="right <?= $ratio_class ?>">
            <?= $ratio !== null ? $ratio . '%' : '<span style="color:#bbb;">－</span>' ?>
          </td>
          <td class="center">
            <?php if ($ratio !== null): ?>
              <span class="rank-badge <?= $rr <= 3 ? 'top3' : 'other' ?>"><?= $rr ?></span>
            <?php else: ?>
              <span style="color:#bbb;">－</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
      <tfoot class="seiseki-foot">
        <tr title="当年データがある現行店舗のみの合計">
          <td class="center">－</td>
          <td>現行店舗 合計</td>
          <td class="right">¥<?= number_format($total_cy_act) ?></td>
          <td class="right">¥<?= number_format($total_py_act) ?></td>
          <td class="right">
            <?= $total_ratio_act !== null ? $total_ratio_act . '%' : '－' ?>
          </td>
          <td></td>
        </tr>
        <tr style="background:#0d1757;" title="前年のみ存在する閉店・移転店舗も含む全店合計">
          <td class="center">－</td>
          <td>全店 合計<span style="font-size:.78em;font-weight:normal;opacity:.75;">（前年店含む）</span></td>
          <td class="right">¥<?= number_format($total_cy_all) ?></td>
          <td class="right">¥<?= number_format($total_py_all) ?></td>
          <td class="right">
            <?= $total_ratio_all !== null ? $total_ratio_all . '%' : '－' ?>
          </td>
          <td></td>
        </tr>
      </tfoot>
    </table>
    <?php endif; ?>
  </div>

</div>

<?php if (($_GET['debug'] ?? '') === '1'): ?>
<div style="background:#f9fbe7;border:2px solid #f9a825;border-radius:.5em;padding:1em;margin:1em;font-size:.8em;">
  <strong>🔍 デバッグ：店舗番号一覧</strong><br>
  <table border="1" cellpadding="4" style="border-collapse:collapse;margin-top:.5em;width:100%;">
    <tr style="background:#fff9c4;">
      <th>店舗No (fk_店舗No)</th>
      <th>当年：店舗名</th>
      <th>前年：店舗名</th>
    </tr>
    <?php
    $all_debug_sns = array_unique(array_merge(array_keys($cy_data), array_keys($py_names)));
    sort($all_debug_sns);
    foreach ($all_debug_sns as $sn):
      $cy_name = $cy_data[$sn]['store_name'] ?? '（当年なし）';
      $py_name = $py_names[$sn] ?? '（前年なし）';
      $mismatch = (!isset($cy_data[$sn]) || !isset($py_names[$sn]));
    ?>
    <tr style="<?= $mismatch ? 'background:#ffebee;' : '' ?>">
      <td><code><?= htmlspecialchars($sn) ?></code></td>
      <td><?= htmlspecialchars($cy_name) ?></td>
      <td><?= htmlspecialchars($py_name) ?></td>
    </tr>
    <?php endforeach; ?>
  </table>
  <p style="margin-top:.5em;color:#888;">※ 赤背景 = 当年か前年のどちらかにしかデータがない店舗（要確認）</p>
</div>
<?php endif; ?>

<?php include __DIR__ . '/footer.php'; ?>
