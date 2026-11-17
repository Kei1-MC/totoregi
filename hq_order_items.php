<?php
/**
 * hq_order_items.php  本社：発注可能商品の管理
 * 店舗ごとに「どの便で・どの部門の・どの商品を発注できるか」を登録する。
 * ・商品は product_API（商品マスタ。廃盤は除外）を品名で検索して追加
 * ・他店舗の設定をまとめてコピー可能（既に登録済みの商品は重複させない）
 */
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hq') {
    header('Location: login.php'); exit();
}
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/test_store.php';
require_once __DIR__ . '/bumon_master.php';
require_once __DIR__ . '/order_lib.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

// ---- 店舗一覧 ----
$stores = [];
$res = order_fm($layout_tenpo)->getRecords(['_limit' => 200]);
foreach ($res['result']['response']['data'] ?? [] as $row) {
    $f  = $row['fieldData'];
    $id = trim((string)($f['店舗Ｎｏ'] ?? ''));
    $nm = trim((string)($f['店舗名'] ?? ''));
    if ($id !== '' && $nm !== '' && !is_test_store($id)) $stores[$id] = $nm;
}
asort($stores);

$store_id = (string)($_GET['store'] ?? $_POST['store'] ?? '');
if (!isset($stores[$store_id])) $store_id = '';

$bins   = order_fetch_bins();
$bumons = array_column(fetch_bumon_master($host, $db, $layout_bumon, $api_master_user, $api_master_pass), 'name');
$fmItem = order_fm($layout_order_item);

$msg = ''; $msg_class = 'alert-success';
$search_results = [];
$kw = trim((string)($_GET['kw'] ?? ''));

if ($store_id !== '' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!order_csrf_ok($_POST['csrf'] ?? null)) {
        $msg = '❌ 画面の有効期限が切れました。開き直してください。'; $msg_class = 'alert-danger';
    } else {
        $action   = $_POST['action'] ?? '';
        $existing = order_fetch_items($store_id);
        $have = [];
        foreach ($existing as $it) $have[$it['bin_id'] . ':' . $it['pno']] = true;
        $fail = 0; $n = 0;

        if ($action === 'add') {
            $bin_id = (int)($_POST['bin_id'] ?? 0);
            $bumon  = trim((string)($_POST['bumon'] ?? ''));
            if (!isset($bins[$bin_id])) {
                $msg = '❌ 便を選んでください。'; $msg_class = 'alert-danger';
            } else {
                foreach (($_POST['pno'] ?? []) as $pno => $name) {
                    $pno = (int)$pno;
                    if ($pno <= 0 || isset($have[$bin_id . ':' . $pno])) continue;
                    $r = $fmItem->createRecord(['fieldData' => [
                        'fk_店舗No' => $store_id, '便ID' => $bin_id, '販売部門' => $bumon,
                        '商品No' => $pno, '品名' => trim((string)$name), '表示順' => $pno, '有効' => 1,
                    ]]);
                    if ($fmItem->isError($r)) $fail++; else $n++;
                }
                $msg = $fail ? "❌ {$n} 件追加、{$fail} 件失敗しました。" : "✅ {$n} 件追加しました。";
                if ($fail) $msg_class = 'alert-danger';
            }
        } elseif ($action === 'remove') {
            $allowed = array_column($existing, 'record_id');   // この店舗のレコードだけ削除できる
            foreach (($_POST['rid'] ?? []) as $rid) {
                if (!in_array((string)$rid, array_map('strval', $allowed), true)) continue;
                $r = $fmItem->deleteRecord((int)$rid);
                if ($fmItem->isError($r)) $fail++; else $n++;
            }
            $msg = $fail ? "❌ {$n} 件削除、{$fail} 件失敗しました。" : "✅ {$n} 件削除しました。";
            if ($fail) $msg_class = 'alert-danger';
        } elseif ($action === 'copy') {
            $src = (string)($_POST['src_store'] ?? '');
            if (!isset($stores[$src]) || $src === $store_id) {
                $msg = '❌ コピー元の店舗を選んでください。'; $msg_class = 'alert-danger';
            } else {
                foreach (order_fetch_items($src) as $it) {
                    if (isset($have[$it['bin_id'] . ':' . $it['pno']])) continue;
                    $r = $fmItem->createRecord(['fieldData' => [
                        'fk_店舗No' => $store_id, '便ID' => $it['bin_id'], '販売部門' => $it['bumon'],
                        '商品No' => $it['pno'], '品名' => $it['name'], '表示順' => $it['order'], '有効' => 1,
                    ]]);
                    if ($fmItem->isError($r)) $fail++; else $n++;
                }
                $msg = $fail ? "❌ {$n} 件コピー、{$fail} 件失敗しました。" : "✅ {$stores[$src]} から {$n} 件コピーしました。";
                if ($fail) $msg_class = 'alert-danger';
            }
        }
    }
}

// ---- 商品検索（GET kw） ----
if ($store_id !== '' && $kw !== '') {
    $r = order_fm($layout_product)->findRecords([
        'query' => [['品名' => '*' . str_replace(['*', '=', '"', '@', '#', '?', '!', '<', '>'], '', $kw) . '*']],
        'limit' => 100,
    ]);
    foreach (order_rows($r) as $row) {
        $f = $row['fieldData'];
        if ((int)($f['廃盤'] ?? 0) === 1) continue;
        $pno = (int)($f['商品Ｎｏ'] ?? 0);
        if ($pno > 0) $search_results[$pno] = trim((string)($f['品名'] ?? ''));
    }
}

$items = $store_id !== '' ? order_fetch_items($store_id) : [];
$grouped = [];
foreach ($items as $it) $grouped[$it['bin_id']][$it['bumon']][] = $it;
ksort($grouped);

include __DIR__ . '/hq_header.php';
?>
<style>
.hq-wrap { font-size: 15px; max-width: 900px; margin: 0 auto; padding: 0 0.5em 3em; }
.oi-box { border: 1px solid #c5cae9; border-radius: .6em; padding: .7em .9em; margin: .8em 0; background: #fafaff; }
.oi-bin { background: #1a237e; color: #fff; padding: .3em .7em; border-radius: .4em; font-weight: bold; margin-top: .8em; }
.oi-bumon { font-size: .85em; font-weight: bold; color: #1a237e; margin: .5em 0 .1em; border-left: 3px solid #1a237e; padding-left: .5em; }
.oi-row { display: flex; gap: .5em; align-items: center; padding: .25em .2em; border-bottom: 1px solid #eee; font-size: .92em; }
.oi-pno { color: #888; font-size: .85em; min-width: 4em; }
</style>

<div class="hq-wrap">
  <div class="text-center py-3" style="border-bottom:2px solid #e0e0e0;">
    <div style="font-size:1.1em;font-weight:bold;color:#1a237e;">🗒 発注可能商品の管理</div>
    <div style="font-size:.85em;color:#888;">店舗ごとに、便・部門別の発注できる商品を登録します</div>
  </div>

  <?php if ($msg): ?><div class="alert <?= $msg_class ?> py-2 mt-2"><?= $h($msg) ?></div><?php endif; ?>

  <form method="get" class="my-3">
    <select name="store" class="form-select" onchange="this.form.submit()">
      <option value="">店舗を選択してください</option>
      <?php foreach ($stores as $id => $nm): ?>
        <option value="<?= $h($id) ?>" <?= $id === $store_id ? 'selected' : '' ?>><?= $h($nm) ?>（<?= $h($id) ?>）</option>
      <?php endforeach; ?>
    </select>
  </form>

<?php if ($store_id !== ''): ?>
  <!-- 追加 -->
  <div class="oi-box">
    <div class="fw-bold mb-1">➕ 商品を追加</div>
    <form method="get" class="d-flex gap-2 mb-2">
      <input type="hidden" name="store" value="<?= $h($store_id) ?>">
      <input type="text" name="kw" class="form-control form-control-sm" placeholder="品名の一部で検索" value="<?= $h($kw) ?>">
      <button class="btn btn-sm btn-primary text-nowrap">検索</button>
    </form>
    <?php if ($kw !== ''): ?>
      <?php if (!$search_results): ?>
        <div class="text-muted" style="font-size:.9em;">該当する商品がありません（廃盤は表示されません）。</div>
      <?php else: ?>
      <form method="post">
        <input type="hidden" name="csrf" value="<?= $h(order_csrf_token()) ?>">
        <input type="hidden" name="store" value="<?= $h($store_id) ?>">
        <input type="hidden" name="action" value="add">
        <div class="d-flex gap-2 mb-2">
          <select name="bin_id" class="form-select form-select-sm" required>
            <option value="">便を選択</option>
            <?php foreach ($bins as $b): ?><option value="<?= $b['id'] ?>"><?= $h($b['name']) ?></option><?php endforeach; ?>
          </select>
          <select name="bumon" class="form-select form-select-sm">
            <option value="">(部門なし)</option>
            <?php foreach ($bumons as $bn): ?><option value="<?= $h($bn) ?>"><?= $h($bn) ?></option><?php endforeach; ?>
          </select>
        </div>
        <?php foreach ($search_results as $pno => $nm): ?>
          <label class="oi-row">
            <input type="checkbox" name="pno[<?= $pno ?>]" value="<?= $h($nm) ?>">
            <span class="oi-pno"><?= $pno ?></span><span><?= $h($nm) ?></span>
          </label>
        <?php endforeach; ?>
        <button class="btn btn-sm btn-success mt-2">選んだ商品を追加</button>
        <?php if (count($search_results) >= 100): ?><span class="text-muted ms-2" style="font-size:.8em;">100件まで表示。検索語を絞ってください。</span><?php endif; ?>
      </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>

  <!-- コピー -->
  <div class="oi-box">
    <form method="post" class="d-flex gap-2 align-items-center"
          onsubmit="return confirm('選んだ店舗の設定を、この店舗へコピーします（登録済みの商品は重複しません）。よろしいですか？');">
      <input type="hidden" name="csrf" value="<?= $h(order_csrf_token()) ?>">
      <input type="hidden" name="store" value="<?= $h($store_id) ?>">
      <input type="hidden" name="action" value="copy">
      <span class="text-nowrap fw-bold">📋 他店舗からコピー</span>
      <select name="src_store" class="form-select form-select-sm" required>
        <option value="">コピー元の店舗</option>
        <?php foreach ($stores as $id => $nm): if ($id === $store_id) continue; ?>
          <option value="<?= $h($id) ?>"><?= $h($nm) ?></option>
        <?php endforeach; ?>
      </select>
      <button class="btn btn-sm btn-outline-primary text-nowrap">コピー</button>
    </form>
  </div>

  <!-- 現在のリスト -->
  <div class="fw-bold mt-3">登録済み（<?= count($items) ?> 件）</div>
  <?php if (!$items): ?>
    <div class="alert alert-info mt-2">まだ登録がありません。上の「商品を追加」または「他店舗からコピー」で登録してください。</div>
  <?php else: ?>
  <form method="post" onsubmit="return confirm('チェックした商品を発注可能リストから外します。よろしいですか？');">
    <input type="hidden" name="csrf" value="<?= $h(order_csrf_token()) ?>">
    <input type="hidden" name="store" value="<?= $h($store_id) ?>">
    <input type="hidden" name="action" value="remove">
    <?php foreach ($grouped as $bin_id => $bm): ?>
      <div class="oi-bin">🚚 <?= $h($bins[$bin_id]['name'] ?? "便{$bin_id}") ?></div>
      <?php foreach ($bm as $bumon => $list): ?>
        <div class="oi-bumon">■ <?= $h($bumon !== '' ? $bumon : '(部門なし)') ?></div>
        <?php foreach ($list as $it): ?>
          <label class="oi-row">
            <input type="checkbox" name="rid[]" value="<?= $h($it['record_id']) ?>">
            <span class="oi-pno"><?= $it['pno'] ?></span><span><?= $h($it['name']) ?></span>
          </label>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>
    <button class="btn btn-sm btn-outline-danger mt-3">チェックした商品を外す</button>
  </form>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php include __DIR__ . '/footer.php'; ?>
