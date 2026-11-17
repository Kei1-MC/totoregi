<?php
/**
 * order_history.php  発注履歴（店舗）
 * 自店舗の直近の発注を納品日ごとに表示する（編集は order_entry.php）
 */
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['store_id'])) { header('Location: login.php'); exit(); }
if (($_SESSION['role'] ?? '') === 'hq') { header('Location: hq_order_list.php'); exit(); }

require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/order_lib.php';

$store_id   = $_SESSION['store_id'];
$store_name = $_SESSION['store_name'];

// 直近30日前〜先の納品日を新しい順に
$from = (new DateTime('today'))->modify('-30 days')->format('m/d/Y');
$res  = order_fm($layout_order)->findRecords([
    'query' => [['fk_店舗No' => '==' . $store_id, '納品日' => $from . '...12/31/2099']],
    'limit' => 1000,
]);
$by_date = []; // Y-m-d => [rows]
foreach (order_rows($res) as $row) {
    $f  = $row['fieldData'];
    $d  = order_parse_date(trim((string)($f['納品日'] ?? '')));
    if (!$d) continue;
    $by_date[$d->format('Y-m-d')][] = [
        'bin'   => trim((string)($f['便名'] ?? '')),
        'bumon' => trim((string)($f['販売部門'] ?? '')),
        'name'  => trim((string)($f['品名'] ?? '')),
        'qty'   => (int)($f['数量'] ?? 0),
        'state' => trim((string)($f['状態'] ?? '')) ?: ORDER_STATE_OPEN,
        'fm'    => $d->format('m/d/Y'),
    ];
}
krsort($by_date);

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$week_ja = ['日','月','火','水','木','金','土'];
include __DIR__ . '/header.php';
?>
<style>
.oh-wrap { font-size: 16px; max-width: 680px; margin: 0 auto; padding: 0 0.4em 3em; }
.oh-day { background: #004d40; color: #fff; padding: 0.4em 0.8em; margin-top: 1em; border-radius: 0.5em; font-weight: bold; display: flex; justify-content: space-between; }
.oh-day a { color: #fff; font-size: .8em; }
.oh-table { width: 100%; font-size: .88em; border-collapse: collapse; }
.oh-table td { padding: .3em .4em; border-bottom: 1px solid #e0ece9; }
.oh-table td.q { text-align: right; font-weight: bold; white-space: nowrap; }
.oh-state { font-size: .75em; color: #666; }
</style>
<div class="oh-wrap">
  <div class="text-center py-3" style="border-bottom:2px solid #e0e0e0;">
    <div style="font-weight:bold;color:#004d40;">🗂 発注履歴</div>
    <div style="font-size:.82em;color:#888;"><?= $h($store_name) ?>（直近30日以降の納品日）</div>
    <a href="order_entry.php" style="font-size:.85em;">← 発注入力へ</a>
  </div>

  <?php if (!$by_date): ?>
    <div class="alert alert-info mt-3" style="font-size:.9em;">該当する発注はありません。</div>
  <?php endif; ?>

  <?php foreach ($by_date as $ymd => $rows):
      $d = new DateTime($ymd);
      $total = array_sum(array_column($rows, 'qty'));
      usort($rows, fn($a, $b) => [$a['bin'], $a['bumon'], $a['name']] <=> [$b['bin'], $b['bumon'], $b['name']]);
  ?>
    <div class="oh-day">
      <span>納品日 <?= $d->format('n/j') ?>（<?= $week_ja[(int)$d->format('w')] ?>）　計 <?= number_format($total) ?></span>
      <a href="order_entry.php?date=<?= urlencode($rows[0]['fm']) ?>">開く</a>
    </div>
    <table class="oh-table">
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><span class="oh-state"><?= $h($r['bin']) ?>／<?= $h($r['bumon']) ?></span><br><?= $h($r['name']) ?></td>
          <td class="q"><?= number_format($r['qty']) ?><br><span class="oh-state"><?= $h($r['state']) ?></span></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endforeach; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>
