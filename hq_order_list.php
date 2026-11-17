<?php
/**
 * hq_order_list.php  本社：発注一覧（納品日別・便別。商品別／店舗別の集計・CSV・確定）
 *
 * view=product : 商品ごとの合計（工場の製造・出荷数の確認用。店舗別内訳つき）
 * view=store   : 店舗ごとの発注明細
 * 確定 : 入力中 → 確定（店舗は編集不可になる）。差戻し : 確定 → 入力中
 * ※ 取込済（納品明細への反映）は今後の拡張用。この画面では変更しない
 */
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hq') {
    header('Location: login.php'); exit();
}
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/test_store.php';
require_once __DIR__ . '/order_lib.php';

$dt = order_parse_date($_GET['date'] ?? $_POST['date'] ?? null) ?: (new DateTime('today'))->modify('+1 day');
$date_fm = $dt->format('m/d/Y');
$view    = (($_GET['view'] ?? 'product') === 'store') ? 'store' : 'product';
$bin_sel = (int)($_GET['bin'] ?? $_POST['bin'] ?? 0);   // 0 = 全便
$bins    = order_fetch_bins();
$fmOrder = order_fm($layout_order);

// ---- 確定／差戻し（POST） ----
$msg = ''; $msg_class = 'alert-success';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $to = ($_POST['action'] ?? '') === 'confirm' ? ORDER_STATE_CONFIRMED
        : (($_POST['action'] ?? '') === 'reopen' ? ORDER_STATE_OPEN : null);
    if (!order_csrf_ok($_POST['csrf'] ?? null)) {
        $msg = '❌ 画面の有効期限が切れました。開き直してください。'; $msg_class = 'alert-danger';
    } elseif ($to !== null) {
        $from = ($to === ORDER_STATE_CONFIRMED) ? ORDER_STATE_OPEN : ORDER_STATE_CONFIRMED;
        $q = ['納品日' => $date_fm, '状態' => '==' . $from];
        if ($bin_sel > 0) $q['便ID'] = $bin_sel;
        $r = $fmOrder->findRecords(['query' => [$q], 'limit' => 2000]);
        $n = 0; $fail = 0;
        foreach (order_rows($r) as $row) {
            $res = $fmOrder->editRecord($row['recordId'], ['fieldData' => ['状態' => $to, '更新日時' => date('m/d/Y H:i:s')]]);
            if ($fmOrder->isError($res)) $fail++; else $n++;
        }
        if ($fail) { $msg = "❌ {$n} 件を{$to}にしましたが、{$fail} 件は失敗しました。もう一度実行してください。"; $msg_class = 'alert-danger'; }
        else       { $msg = "✅ {$n} 件を{$to}にしました。"; }
    }
}

// ---- 当日の発注を取得 ----
$q = ['納品日' => $date_fm];
if ($bin_sel > 0) $q['便ID'] = $bin_sel;
$res  = $fmOrder->findRecords(['query' => [$q], 'limit' => 5000]);
$rows = [];
foreach (order_rows($res) as $r) {
    $f = $r['fieldData'];
    $qty = (int)($f['数量'] ?? 0);
    if ($qty <= 0) continue;
    if (is_test_store($f['fk_店舗No'] ?? '')) continue;
    $rows[] = [
        'store_id' => trim((string)($f['fk_店舗No'] ?? '')),
        'store'    => trim((string)($f['店舗名'] ?? '')),
        'bin_id'   => (int)($f['便ID'] ?? 0),
        'bin'      => trim((string)($f['便名'] ?? '')),
        'bumon'    => trim((string)($f['販売部門'] ?? '')),
        'pno'      => (int)($f['商品No'] ?? 0),
        'name'     => trim((string)($f['品名'] ?? '')),
        'qty'      => $qty,
        'state'    => trim((string)($f['状態'] ?? '')) ?: ORDER_STATE_OPEN,
    ];
}

// 商品別集計
$by_product = []; // "便ID:商品No" => [...]
foreach ($rows as $r) {
    $k = $r['bin_id'] . ':' . $r['pno'];
    $by_product[$k] ??= ['bin' => $r['bin'], 'bumon' => $r['bumon'], 'pno' => $r['pno'], 'name' => $r['name'], 'qty' => 0, 'stores' => []];
    $by_product[$k]['qty'] += $r['qty'];
    $by_product[$k]['stores'][] = $r['store'] . ' ' . $r['qty'];
}
uasort($by_product, fn($a, $b) => [$a['bin'], $a['bumon'], $a['pno']] <=> [$b['bin'], $b['bumon'], $b['pno']]);
usort($rows, fn($a, $b) => [$a['store_id'], $a['bin'], $a['bumon'], $a['pno']] <=> [$b['store_id'], $b['bin'], $b['bumon'], $b['pno']]);

$cnt_open = count(array_filter($rows, fn($r) => $r['state'] === ORDER_STATE_OPEN));
$cnt_conf = count(array_filter($rows, fn($r) => $r['state'] === ORDER_STATE_CONFIRMED));
$store_cnt = count(array_unique(array_column($rows, 'store_id')));

// ---- CSV ----
if (($_GET['csv'] ?? '') === '1') {
    $csv_cell = function ($v) {
        $v = (string)$v;
        if ($v !== '' && strpbrk($v[0], "=+-@\t\r") !== false) $v = "'" . $v;   // 数式として解釈されないように
        return '"' . str_replace('"', '""', $v) . '"';
    };
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="order_' . $dt->format('Ymd') . '.csv"');
    echo "\xEF\xBB\xBF";
    echo implode(',', array_map($csv_cell, ['納品日','便','販売部門','店舗No','店舗名','商品No','品名','数量','状態'])) . "\r\n";
    foreach ($rows as $r) {
        echo implode(',', array_map($csv_cell, [$dt->format('Y/m/d'), $r['bin'], $r['bumon'], $r['store_id'],
            $r['store'], $r['pno'], $r['name'], $r['qty'], $r['state']])) . "\r\n";
    }
    exit();
}

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$week_ja = ['日','月','火','水','木','金','土'];
$qs = fn(array $o) => '?' . http_build_query(array_merge(['date' => $date_fm, 'view' => $view, 'bin' => $bin_sel], $o));
include __DIR__ . '/hq_header.php';
?>
<style>
.hq-wrap { font-size: 15px; max-width: 980px; margin: 0 auto; padding: 0 0.5em 3em; }
.ol-bar { display: flex; flex-wrap: wrap; gap: .5em; align-items: center; margin: .8em 0; }
.ol-table { width: 100%; border-collapse: collapse; font-size: .9em; }
.ol-table th { background: #1a237e; color: #fff; padding: .35em .5em; text-align: left; }
.ol-table td { padding: .35em .5em; border-bottom: 1px solid #e0e0e0; vertical-align: top; }
.ol-table td.q { text-align: right; font-weight: bold; white-space: nowrap; }
.ol-sub { color: #666; font-size: .85em; }
.ol-sum { display: flex; gap: 1.2em; flex-wrap: wrap; font-size: .9em; margin-bottom: .5em; }
</style>

<div class="hq-wrap">
  <div class="text-center py-3" style="border-bottom:2px solid #e0e0e0;">
    <div style="font-size:1.1em;font-weight:bold;color:#1a237e;">📦 発注一覧</div>
    <div style="font-size:.85em;color:#888;">納品日 <?= $h($dt->format('Y年n月j日')) ?>（<?= $week_ja[(int)$dt->format('w')] ?>）</div>
  </div>

  <?php if ($msg): ?><div class="alert <?= $msg_class ?> py-2 mt-2"><?= $h($msg) ?></div><?php endif; ?>

  <form class="ol-bar" method="get">
    <input type="hidden" name="view" value="<?= $h($view) ?>">
    <input type="date" class="form-control form-control-sm" style="width:auto;" value="<?= $dt->format('Y-m-d') ?>"
           onchange="var p=this.value.split('-'); this.form.date.value=p[1]+'/'+p[2]+'/'+p[0]; this.form.submit();">
    <input type="hidden" name="date" value="<?= $h($date_fm) ?>">
    <select name="bin" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
      <option value="0">全ての便</option>
      <?php foreach ($bins as $b): ?>
        <option value="<?= $b['id'] ?>" <?= $bin_sel === $b['id'] ? 'selected' : '' ?>>
          <?= $h($b['name']) ?>（<?= $b['cutoff'] !== '' ? $h($b['cutoff']) . '締め' : '締め未設定' ?>）
        </option>
      <?php endforeach; ?>
    </select>
    <a class="btn btn-sm <?= $view === 'product' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= $h($qs(['view' => 'product'])) ?>">商品別</a>
    <a class="btn btn-sm <?= $view === 'store' ? 'btn-primary' : 'btn-outline-primary' ?>" href="<?= $h($qs(['view' => 'store'])) ?>">店舗別</a>
    <a class="btn btn-sm btn-outline-success" href="<?= $h($qs(['csv' => 1])) ?>">⬇ CSV</a>
  </form>

  <div class="ol-sum">
    <span>発注店舗 <strong><?= $store_cnt ?></strong></span>
    <span>明細 <strong><?= count($rows) ?></strong> 件</span>
    <span>入力中 <strong><?= $cnt_open ?></strong></span>
    <span>確定 <strong><?= $cnt_conf ?></strong></span>
  </div>

  <?php $unset = array_filter($bins, fn($b) => $b['cutoff'] === ''); if ($unset): ?>
    <div class="alert alert-warning py-1" style="font-size:.85em;">
      締め時刻が未設定の便があります（<?= $h(implode('・', array_column($unset, 'name'))) ?>）。
      未設定の便は店舗で受付できません。出荷便マスタの「締め時刻」を設定してください。
    </div>
  <?php endif; ?>

  <form method="post" class="mb-3" onsubmit="return confirm(this.action_name.value + 'します。よろしいですか？');">
    <input type="hidden" name="csrf" value="<?= $h(order_csrf_token()) ?>">
    <input type="hidden" name="date" value="<?= $h($date_fm) ?>">
    <input type="hidden" name="bin" value="<?= $bin_sel ?>">
    <input type="hidden" name="action_name" value="">
    <button name="action" value="confirm" class="btn btn-sm btn-danger"
            onclick="this.form.action_name.value='入力中の発注（<?= $cnt_open ?>件）を確定';"
            <?= $cnt_open ? '' : 'disabled' ?>>✔ 入力中を確定する</button>
    <button name="action" value="reopen" class="btn btn-sm btn-outline-secondary"
            onclick="this.form.action_name.value='確定済み（<?= $cnt_conf ?>件）を入力中に差戻し';"
            <?= $cnt_conf ? '' : 'disabled' ?>>↩ 確定を差戻す</button>
    <span class="ol-sub ms-2">※ 確定すると店舗は編集できなくなります。<?= $bin_sel ? '選択中の便のみ対象です。' : '全ての便が対象です。' ?></span>
  </form>

  <?php if (!$rows): ?>
    <div class="alert alert-info">この納品日の発注はまだありません。</div>
  <?php elseif ($view === 'product'): ?>
    <table class="ol-table">
      <tr><th>便</th><th>部門</th><th>商品No</th><th>品名</th><th style="text-align:right;">合計</th><th>店舗別内訳</th></tr>
      <?php foreach ($by_product as $p): ?>
        <tr>
          <td><?= $h($p['bin']) ?></td><td><?= $h($p['bumon']) ?></td><td><?= $p['pno'] ?></td>
          <td><?= $h($p['name']) ?></td><td class="q"><?= number_format($p['qty']) ?></td>
          <td class="ol-sub"><?= $h(implode('　', $p['stores'])) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php else: ?>
    <table class="ol-table">
      <tr><th>店舗</th><th>便</th><th>部門</th><th>商品No</th><th>品名</th><th style="text-align:right;">数量</th><th>状態</th></tr>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><?= $h($r['store']) ?></td><td><?= $h($r['bin']) ?></td><td><?= $h($r['bumon']) ?></td>
          <td><?= $r['pno'] ?></td><td><?= $h($r['name']) ?></td><td class="q"><?= number_format($r['qty']) ?></td>
          <td class="ol-sub"><?= $h($r['state']) ?></td>
        </tr>
      <?php endforeach; ?>
    </table>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>
