<?php
/**
 * hq_jikanbetsu.php  時間別（時間帯別全店集計）
 * 日別に 12時/15時/17時/閉店後 の客数・売上累計を全店合計で表示
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
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

$month_jp = "{$sel_year}年{$sel_month}月";
$days_in_month = (int)date('t', mktime(0,0,0,$sel_month,1,$sel_year));

// 曜日ラベル
$week_ja  = ['Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土'];

// ---- 部門一覧（12時/15時/17時の部門別売上フィールド算出用。閉店後と同一） ----
$all_busho = [
    '売上_天ぷら', '売上_魚', '売上_唐揚', '売上_冷惣菜', '売上_催事', '売上_イカ焼',
    '売上_エキタカ', '売上_くじら', '売上_コンビニデリカ', '売上_セルフ唐揚',
    '売上_セルフ天丼', '売上_セルフ惣菜', '売上_フライ', '売上_串揚', '売上_丼',
    '売上_個食', '売上_弁当', '売上_弁当Ⅱ', '売上_生串揚', '売上_鯛',
];

/**
 * 時間帯の累計売上を算出する。
 * 部門別入力への移行前（旧・単一フィールド）／移行後（新・部門別フィールド合計）の
 * どちらか一方にしか値が入らないため、両方を単純に加算すれば月をまたいでも集計が途切れない。
 */
function jikanbetsuUriage(array $f, string $legacyField, string $fmSuffix, array $all_busho): int {
    $v = (int)($f[$legacyField] ?? 0);
    foreach ($all_busho as $bf) {
        $v += (int)($f[$bf . '_' . $fmSuffix] ?? 0);
    }
    return $v;
}

// ---- FM 取得 ----
$first_fm = sprintf('%02d/01/%04d', $sel_month, $sel_year);
$last_fm  = sprintf('%02d/%02d/%04d', $sel_month, $days_in_month, $sel_year);
$range_fm = "{$first_fm}...{$last_fm}";

$fm = new fmRESTor($host, $db, $layout_daily_report,
                   $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$r = $fm->findRecords([
    'query' => [['売上日' => $range_fm]],
    'limit' => 2000,
]);

// ---- 日別集計 ----
// $daily[day] = [kyaku12, uriage12, kyaku15, uriage15, kyaku17, uriage17,
//                kyaku_heiten, uriage_heiten, kareisan, kareihaki, count]
$daily = [];
for ($d = 1; $d <= $days_in_month; $d++) {
    $ts = mktime(0,0,0,$sel_month,$d,$sel_year);
    $daily[$d] = [
        'dow'          => $week_ja[date('D', $ts)] ?? '',
        'kyaku_12'     => 0,
        'uriage_12'    => 0,
        'kyaku_15'     => 0,
        'uriage_15'    => 0,
        'kyaku_17'     => 0,
        'uriage_17'    => 0,
        'kyaku_heiten' => 0,
        'uriage_total' => 0,
        'kareisan'     => 0,
        'kareihaki'    => 0,
        'stores'       => 0,
        'confirmed'    => 0,
    ];
}

if (($r['result']['messages'][0]['code'] ?? '0') !== '401') {
    foreach ($r['result']['response']['data'] ?? [] as $row) {
        $f = $row['fieldData'];
        $fm_date = $f['売上日'] ?? '';
        $parts   = explode('/', $fm_date);
        $day     = isset($parts[1]) ? (int)$parts[1] : 0;
        if ($day < 1 || $day > $days_in_month) continue;

        $daily[$day]['kyaku_12']     += (int)($f['客数_12時']         ?? 0);
        $daily[$day]['uriage_12']    += jikanbetsuUriage($f, '売上累計_12時', '12時', $all_busho);
        $daily[$day]['kyaku_15']     += (int)($f['客数_15時']         ?? 0);
        $daily[$day]['uriage_15']    += jikanbetsuUriage($f, '売上累計_15時', '15時', $all_busho);
        $daily[$day]['kyaku_17']     += (int)($f['客数_17時']         ?? 0);
        $daily[$day]['uriage_17']    += jikanbetsuUriage($f, '売上累計_17時', '17時', $all_busho);
        $daily[$day]['kyaku_heiten'] += (int)($f['客数_閉店後']       ?? 0);
        $daily[$day]['uriage_total'] += (int)($f['合計売上']          ?? 0);
        $daily[$day]['kareisan']     += (int)($f['からすかれい_製造数'] ?? 0);
        $daily[$day]['kareihaki']    += (int)($f['からすかれい_廃棄数'] ?? 0);
        $daily[$day]['stores']++;
        if (trim($f['入力状態'] ?? '') === '確定') $daily[$day]['confirmed']++;
    }
}

// ---- 月合計 ----
$totals = array_fill_keys(['kyaku_12','uriage_12','kyaku_15','uriage_15',
    'kyaku_17','uriage_17','kyaku_heiten','uriage_total','kareisan','kareihaki'], 0);
foreach ($daily as $d => $row) {
    if ($row['stores'] === 0) continue;
    foreach ($totals as $k => $_) $totals[$k] += $row[$k];
}

$today_day = ($sel_year == (int)$today->format('Y') && $sel_month == (int)$today->format('n'))
             ? (int)$today->format('j') : 0;

// ---- CSV 書き出し ----
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $ym_str = $sel_year . sprintf('%02d', $sel_month);
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="jikanbetsu_' . $ym_str . '.csv"');
    header('Cache-Control: no-cache, no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM for Excel
    fputcsv($out, ['日', '曜', '12時客数', '12時売上累計', '15時客数', '15時売上累計',
                   '17時客数', '17時売上累計', '閉店後客数', '売上合計',
                   'からすかれい製造', 'からすかれい廃棄', '投入店舗数']);
    for ($d = 1; $d <= $days_in_month; $d++) {
        $row = $daily[$d];
        if ($row['stores'] === 0) {
            fputcsv($out, [
                $sel_month . '/' . $d, $row['dow'],
                '', '', '', '', '', '', '', '', '', '', ''
            ]);
        } elseif ($row['confirmed'] < $row['stores']) {
            fputcsv($out, [
                $sel_month . '/' . $d, $row['dow'],
                '未確定', '未確定', '未確定', '未確定', '未確定', '未確定',
                '未確定', '未確定', '未確定', '未確定', $row['stores'],
            ]);
        } else {
            fputcsv($out, [
                $sel_month . '/' . $d, $row['dow'],
                $row['kyaku_12'],  $row['uriage_12'],
                $row['kyaku_15'],  $row['uriage_15'],
                $row['kyaku_17'],  $row['uriage_17'],
                $row['kyaku_heiten'], $row['uriage_total'],
                $row['kareisan'], $row['kareihaki'],
                $row['stores'],
            ]);
        }
    }
    // 月合計行
    fputcsv($out, [
        '月合計', '',
        $totals['kyaku_12'],  $totals['uriage_12'],
        $totals['kyaku_15'],  $totals['uriage_15'],
        $totals['kyaku_17'],  $totals['uriage_17'],
        $totals['kyaku_heiten'], $totals['uriage_total'],
        $totals['kareisan'], $totals['kareihaki'], '',
    ]);
    fclose($out);
    exit();
}

include __DIR__ . '/hq_header.php';
?>
<style>
.hq-jk-wrap { font-size: 15px; padding: 0 0 2em; }

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
}
.nav-btn-go:hover { background: rgba(255,255,255,.45); }

.jk-scroll { overflow-x: auto; padding: 0.5em; }

table.jk-table {
    border-collapse: collapse;
    font-size: 0.82em;
    white-space: nowrap;
    min-width: 700px;
}
table.jk-table th {
    background: #1a237e; color: #fff;
    padding: 0.4em 0.6em;
    text-align: center;
    border-right: 1px solid rgba(255,255,255,.2);
    position: sticky; top: 0;
}
table.jk-table th.group-12 { background: #1565c0; }
table.jk-table th.group-15 { background: #00695c; }
table.jk-table th.group-17 { background: #6a1b9a; }
table.jk-table th.group-heiten { background: #bf360c; }
table.jk-table th.group-karei  { background: #4e342e; }
table.jk-table th.left { text-align: left; }

table.jk-table td {
    padding: 0.35em 0.6em;
    border-bottom: 1px solid #e8e8e8;
    border-right: 1px solid #eee;
    text-align: right;
    vertical-align: middle;
}
table.jk-table td.date-col {
    text-align: center;
    font-weight: bold;
    color: #333;
    min-width: 3em;
}
table.jk-table td.dow-col {
    text-align: center;
    min-width: 2em;
}
table.jk-table td.dow-sun { color: #c62828; font-weight: bold; }
table.jk-table td.dow-sat { color: #1565c0; font-weight: bold; }
table.jk-table td.empty { color: #bbb; text-align: center; }
table.jk-table td.mikakunin-cell { color: #e65100; font-weight: bold; text-align: center; }

table.jk-table tbody tr:hover td { background: #f3f4fb; }
table.jk-table .today-row td { background: #fff9c4 !important; }
table.jk-table .today-row td.date-col { color: #e65100; }

table.jk-table tfoot td {
    background: #e8eaf6;
    font-weight: bold;
    border-top: 2px solid #1a237e;
}
table.jk-table tfoot td.date-col { text-align: center; }
</style>

<div class="hq-jk-wrap">

  <!-- 月ナビ -->
  <div class="month-nav">
    <span class="m-title">🕐 時間別集計</span>
    <form method="get" style="display:flex;align-items:center;gap:.4em;flex-wrap:wrap;">
      <?php /* CSV export button */ ?>
      <a href="?year=<?= $sel_year ?>&month=<?= $sel_month ?>&export=csv"
         style="background:rgba(255,255,255,.25);color:#fff;border:1px solid rgba(255,255,255,.5);
                border-radius:.4em;padding:.3em .9em;font-size:.85em;font-weight:bold;
                text-decoration:none;white-space:nowrap;">📥 Excel (CSV) 書き出し</a>
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

  <div class="jk-scroll">
    <table class="jk-table">
      <thead>
        <tr>
          <th rowspan="2" class="left">日</th>
          <th rowspan="2">曜</th>
          <th colspan="2" class="group-12">12 時</th>
          <th colspan="2" class="group-15">15 時</th>
          <th colspan="2" class="group-17">17 時</th>
          <th colspan="2" class="group-heiten">閉店後</th>
          <th colspan="2" class="group-karei">からすかれい</th>
          <th rowspan="2">投入<br>店舗</th>
        </tr>
        <tr>
          <th class="group-12">客数</th>
          <th class="group-12">売上累計</th>
          <th class="group-15">客数</th>
          <th class="group-15">売上累計</th>
          <th class="group-17">客数</th>
          <th class="group-17">売上累計</th>
          <th class="group-heiten">客数</th>
          <th class="group-heiten">売上合計</th>
          <th class="group-karei">製造</th>
          <th class="group-karei">廃棄</th>
        </tr>
      </thead>
      <tbody>
        <?php for ($d = 1; $d <= $days_in_month; $d++):
          $row = $daily[$d];
          $dow = $row['dow'];
          $dow_class = ($dow === '日') ? 'dow-sun' : (($dow === '土') ? 'dow-sat' : '');
          $is_today = ($d == $today_day);
          $has_data = ($row['stores'] > 0);
          $is_unconfirmed = ($has_data && $row['confirmed'] < $row['stores']);
        ?>
        <tr class="<?= $is_today ? 'today-row' : '' ?>">
          <td class="date-col">
            <?= $sel_month ?>/<?= $d ?>
          </td>
          <td class="dow-col <?= $dow_class ?>"><?= $dow ?></td>
          <?php if ($is_unconfirmed): ?>
            <td class="mikakunin-cell" colspan="10">未確定</td>
            <td style="text-align:center;"><?= $row['stores'] ?></td>
          <?php elseif ($has_data): ?>
            <td><?= number_format($row['kyaku_12']) ?></td>
            <td>¥<?= number_format($row['uriage_12']) ?></td>
            <td><?= number_format($row['kyaku_15']) ?></td>
            <td>¥<?= number_format($row['uriage_15']) ?></td>
            <td><?= number_format($row['kyaku_17']) ?></td>
            <td>¥<?= number_format($row['uriage_17']) ?></td>
            <td><?= number_format($row['kyaku_heiten']) ?></td>
            <td>¥<?= number_format($row['uriage_total']) ?></td>
            <td><?= number_format($row['kareisan']) ?></td>
            <td><?= number_format($row['kareihaki']) ?></td>
            <td style="text-align:center;"><?= $row['stores'] ?></td>
          <?php else: ?>
            <?php for ($c = 0; $c < 11; $c++): ?>
              <td class="empty">－</td>
            <?php endfor; ?>
          <?php endif; ?>
        </tr>
        <?php endfor; ?>
      </tbody>
      <tfoot>
        <tr>
          <td class="date-col" colspan="2">月 合 計</td>
          <td><?= number_format($totals['kyaku_12']) ?></td>
          <td>¥<?= number_format($totals['uriage_12']) ?></td>
          <td><?= number_format($totals['kyaku_15']) ?></td>
          <td>¥<?= number_format($totals['uriage_15']) ?></td>
          <td><?= number_format($totals['kyaku_17']) ?></td>
          <td>¥<?= number_format($totals['uriage_17']) ?></td>
          <td><?= number_format($totals['kyaku_heiten']) ?></td>
          <td>¥<?= number_format($totals['uriage_total']) ?></td>
          <td><?= number_format($totals['kareisan']) ?></td>
          <td><?= number_format($totals['kareihaki']) ?></td>
          <td></td>
        </tr>
      </tfoot>
    </table>
  </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>
