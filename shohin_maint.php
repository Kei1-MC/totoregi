<?php
/**
 * shohin_maint.php  販売商品メンテナンス（店舗用）
 * 機能: 発売中ON/OFF、セール価格入力、マスターから追加、取扱い解除
 * ※ 店舗による新規商品登録・削除は行わない（本部管理）
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['store_id'])) { header('Location: login.php'); exit(); }
if (($_SESSION['role'] ?? '') === 'hq') { header('Location: hq_top.php'); exit(); }
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/bumon_master.php';

$store_id   = $_SESSION['store_id'];
$store_name = $_SESSION['store_name'];

// 部門定義（bumon_API から取得。並び順昇順）
$bumon_master = bumon_names(fetch_bumon_master($host, $db, $layout_bumon, $api_master_user, $api_master_pass));

/* =====================================================================
   AJAX ハンドラー（POST）— getRecord を使わず、ポジションは呼び出し元が渡す
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=UTF-8');
    $action = $_POST['action'] ?? '';
    $rid    = (int)trim($_POST['record_id'] ?? '');

    $fm = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);

    /* ── 発売中トグル ── */
    if ($action === 'toggle') {
        $val  = ((int)($_POST['val'] ?? 0) === 1) ? 1 : 0;
        $res  = $fm->editRecord($rid, ['fieldData' => ['発売中' => $val]]);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        echo json_encode(['ok' => ($code === '0')]);
        exit();
    }

    /* ── セール価格保存（my_pos はページ側から受け取る）── */
    if ($action === 'save_sale_price') {
        $sale_price = max(0, (int)($_POST['sale_price'] ?? 0));
        $my_pos     = (int)($_POST['my_pos'] ?? 0);
        if ($my_pos < 1 || $my_pos > MAX_STORE_REPS) {
            echo json_encode(['ok' => false, 'error' => 'invalid_pos']);
            exit();
        }
        $key = repeatKey('セール価格', $my_pos);
        $res = $fm->editRecord($rid, ['fieldData' => [$key => $sale_price ?: '']]);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        echo json_encode(['ok' => ($code === '0')]);
        exit();
    }

    /* ── 本体価格（店舗別）保存（my_pos はページ側から受け取る）── */
    if ($action === 'save_honbai_price') {
        $honbai_price = max(0, (int)($_POST['honbai_price'] ?? 0));
        $my_pos       = (int)($_POST['my_pos'] ?? 0);
        if ($my_pos < 1 || $my_pos > MAX_STORE_REPS || $honbai_price <= 0) {
            echo json_encode(['ok' => false, 'error' => 'invalid_pos_or_price']);
            exit();
        }
        $key = repeatKey('店舗本体価格', $my_pos);
        $res = $fm->editRecord($rid, ['fieldData' => [$key => $honbai_price]]);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        echo json_encode(['ok' => ($code === '0')]);
        exit();
    }

    /* ── レジ表示部門（店舗別上書き）保存。空欄選択で本部既定値に戻す（my_pos はページ側から受け取る）── */
    if ($action === 'save_bumon') {
        $bumon  = trim($_POST['bumon'] ?? '');
        $my_pos = (int)($_POST['my_pos'] ?? 0);
        if ($my_pos < 1 || $my_pos > MAX_STORE_REPS) {
            echo json_encode(['ok' => false, 'error' => 'invalid_pos']);
            exit();
        }
        $key = repeatKey('店舗部門', $my_pos);
        $res = $fm->editRecord($rid, ['fieldData' => [$key => $bumon]]);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        echo json_encode(['ok' => ($code === '0')]);
        exit();
    }

    /* ── デバッグ: pos 2以降のデータを持つレコードを検索して生 fieldData を返す ── */
    if ($action === 'debug_record') {
        // 最大20件取得して、pos 2以降にデータがあるレコードを探す
        $res = $fm->getRecords(['_limit' => 50]);
        $all = $res['result']['response']['data'] ?? [];

        // pos 2以降に店舗データが入っているレコードを優先
        $multi = [];
        $single = [];
        foreach ($all as $row) {
            $f = $row['fieldData'];
            // FM が返すキーに '取扱店舗(2)' があるか、または 取扱店舗 系のキーが複数あるか
            $tk = array_filter(array_keys($f), fn($k) => str_contains($k, '取扱店舗'));
            $has_pos2 = false;
            foreach ($tk as $k) {
                // (2) 以上のサフィックスがあれば multi
                if (preg_match('/\(([2-9]|\d{2,})\)$/', $k) && $f[$k] !== '' && $f[$k] !== 0) {
                    $has_pos2 = true; break;
                }
            }
            if ($has_pos2) $multi[] = $row;
            else           $single[] = $row;
        }
        $records_found = !empty($multi) ? array_slice($multi, 0, 2) : array_slice($single, 0, 2);
        $search_note   = !empty($multi) ? 'pos2+ data found' : 'no pos2+ → showing first records';

        $out = [];
        foreach ($records_found as $row) {
            $f = $row['fieldData'];

            // (1) 取扱・セール 全キー（FM が実際に返したキーをそのまま）
            $store_keys   = array_filter(array_keys($f), fn($k) => str_contains($k, '取扱') || str_contains($k, 'セール'));
            $store_fields = array_intersect_key($f, array_flip($store_keys));

            // (2) repeatKey(pos) で引けるか確認（pos 1〜5）
            $repeat_check = [];
            for ($i = 1; $i <= 5; $i++) {
                $k = repeatKey('取扱店舗', $i);
                $repeat_check["pos{$i} key={$k}"] = $f[$k] ?? '(none)';
            }

            // (3) getStorePositions の結果
            $positions = getStorePositions($f);

            $out[] = [
                'rid'                            => $row['recordId'],
                '商品名'                         => $f['商品名'] ?? '',
                'store_fields_raw'               => $store_fields,
                'repeat_check'                   => $repeat_check,
                'getStorePositions'              => $positions,
                'my_pos_for_'.$store_id          => array_search($store_id, $positions),
            ];
        }
        echo json_encode([
            'session_store_id' => $store_id,
            'search_note'      => $search_note,
            'total_fetched'    => count($all),
            'multi_store_count'=> count($multi),
            'records'          => $out,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        exit();
    }

    /* ── マスターから追加（next_pos はページ側から受け取る）── */
    if ($action === 'add_store') {
        $next_pos = (int)($_POST['next_pos'] ?? 0);
        $dbg = [
            'rid'      => $rid,
            'next_pos' => $next_pos,
            'store_id' => $store_id,
            'post'     => $_POST,
        ];
        if ($next_pos < 1 || $next_pos > MAX_STORE_REPS) {
            echo json_encode(['ok' => false, 'error' => 'invalid_pos', 'debug' => $dbg]);
            exit();
        }
        // 本部設定の本体価格を取得し、店舗本体価格の初期値にする（クライアント値は信用しない）
        $rec_res = $fm->getRecord($rid);
        $master_price = (int)($rec_res['result']['response']['data'][0]['fieldData']['本体価格'] ?? 0);

        $key      = repeatKey('取扱店舗', $next_pos);
        $price_key = repeatKey('店舗本体価格', $next_pos);
        $dbg['key'] = $key;
        $dbg['master_price'] = $master_price;
        $res  = $fm->editRecord($rid, ['fieldData' => [
            $key       => $store_id,
            $price_key => $master_price,
        ]]);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        $dbg['fm_code']     = $code;
        $dbg['fm_messages'] = $res['result']['messages'] ?? [];
        echo json_encode(['ok' => ($code === '0'), 'debug' => $dbg]);
        exit();
    }

    /* ── 自店舗を外す（my_pos はページ側から受け取る）── */
    if ($action === 'remove_store') {
        $my_pos = (int)($_POST['my_pos'] ?? 0);
        if ($my_pos < 1 || $my_pos > MAX_STORE_REPS) {
            echo json_encode(['ok' => false, 'error' => 'invalid_pos']);
            exit();
        }
        $res = $fm->editRecord($rid, ['fieldData' => [
            repeatKey('取扱店舗',   $my_pos) => '',
            repeatKey('セール価格', $my_pos) => '',
            repeatKey('店舗本体価格', $my_pos) => '',
        ]]);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        echo json_encode(['ok' => ($code === '0')]);
        exit();
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit();
}

/* =====================================================================
   商品一覧取得（全件 → PHP で自店舗 / マスターに分離）
   ===================================================================== */
$fm  = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$res = $fm->getRecords(['_limit' => 1000]);

$products    = []; // 自店舗
$master_list = []; // 未取扱

foreach ($res['result']['response']['data'] ?? [] as $row) {
    $f = $row['fieldData'];
    $n = trim($f['商品名'] ?? '');
    if ($n === '') continue;

    $positions = getStorePositions($f); // [pos => storeId]
    $my_pos    = array_search($store_id, $positions); // false or int

    if ($my_pos !== false) {
        /* ── 自店舗商品 ── */
        $sale_price   = (int)($f[repeatKey('セール価格', $my_pos)] ?? 0);
        $master_price = (int)($f['本体価格'] ?? 0);
        // 店舗本体価格が未設定（移行前・データ不整合時）は本部設定価格にフォールバック
        $honbai_price = (int)($f[repeatKey('店舗本体価格', $my_pos)] ?? 0) ?: $master_price;
        $master_bumon = trim($f['部門'] ?? '');
        // レジ表示部門：店舗別の上書きがあればそちらを優先、無ければ本部既定値
        $bumon_override = trim($f[repeatKey('店舗部門', $my_pos)] ?? '');
        $effective_bumon = $bumon_override !== '' ? $bumon_override : $master_bumon;
        $products[] = [
            'record_id'      => $row['recordId'],
            'name'           => $n,
            'bumon'          => $effective_bumon,   // タブ分類・並び替えに使う実効値
            'master_bumon'   => $master_bumon,      // 本部既定値（参考表示・「既定に戻す」用）
            'bumon_override' => $bumon_override,     // 自店の上書き値（空=上書きなし）
            'yomi'           => trim($f['よみがな'] ?? ''),
            'master_price'   => $master_price, // 本部設定価格（参考表示用）
            'price'          => $honbai_price, // 店舗本体価格（編集可能）
            'tani'           => trim($f['販売単位'] ?? ''),
            'hanbai_chu'     => (int)($f['発売中']  ?? 1),
            'sale'           => (int)($f['セール']  ?? 0),
            'sale_price'     => $sale_price,
            'my_pos'         => $my_pos, // 価格保存・外す処理に使用
        ];
    } else {
        /* ── マスター（未取扱）── */
        // 次の空きポジションを計算
        $next_pos = 0;
        for ($i = 1; $i <= MAX_STORE_REPS; $i++) {
            if (!isset($positions[$i])) { $next_pos = $i; break; }
        }
        if ($next_pos === 0) continue; // 全50ポジション満杯はスキップ

        $master_list[] = [
            'record_id' => $row['recordId'],
            'name'      => $n,
            'bumon'     => trim($f['部門']     ?? ''),
            'yomi'      => trim($f['よみがな'] ?? ''),
            'price'     => (int)($f['本体価格'] ?? 0),
            'tani'      => trim($f['販売単位'] ?? ''),
            'next_pos'  => $next_pos, // add_store処理に使用
        ];
    }
}

// ソート
$sortFn = function($a, $b) use ($bumon_master) {
    $pa = array_search($a['bumon'], $bumon_master); $pa = ($pa === false) ? 99 : $pa;
    $pb = array_search($b['bumon'], $bumon_master); $pb = ($pb === false) ? 99 : $pb;
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['yomi'] ?: $a['name'], $b['yomi'] ?: $b['name']);
};
usort($products,    $sortFn);
usort($master_list, $sortFn);

$total_count  = count($products);
$hanbai_count = count(array_filter($products, fn($p) => $p['hanbai_chu']));

include __DIR__ . '/header.php';
?>
<style>
.maint-wrap { max-width: 900px; margin: 0 auto; padding-bottom: 5em; }

/* ページヘッダー */
.maint-page-header {
    background: #004d40; color: #fff;
    padding: 0.6em 1em;
    display: flex; align-items: center; gap: 0.6em; flex-wrap: wrap;
}
.maint-page-title { font-size: 1.1em; font-weight: bold; flex: 1; }
.maint-stats { font-size: 0.82em; opacity: .85; white-space: nowrap; }

/* 部門タブ */
.dept-tabs {
    display: flex; flex-wrap: wrap; gap: 0.3em;
    padding: 0.5em 0.8em; background: #f0f4f0;
    border-bottom: 1px solid #c8d8c8;
    position: sticky; top: 48px; z-index: 10;
}
.dept-tab {
    background: #fff; border: 1px solid #c8d8c8;
    border-radius: 1em; padding: 0.25em 0.85em;
    font-size: 0.82em; font-weight: bold; color: #555; cursor: pointer;
}
.dept-tab:hover  { background: #e8f5e9; color: #004d40; }
.dept-tab.active { background: #004d40; color: #fff; border-color: #004d40; }

/* 商品リスト */
.product-list { padding: 0.3em 0.8em; }
.product-row {
    display: flex; align-items: center; gap: 0.5em; flex-wrap: wrap;
    padding: 0.5em 0.6em; border-bottom: 1px solid #eee; background: #fff;
    transition: background .12s;
}
.product-row:hover { background: #f5faf5; }
.product-row.inactive { background: #fafafa; }

/* 発売中トグル */
.toggle-wrap { position: relative; display: inline-block; width: 3em; height: 1.6em; flex-shrink: 0; cursor: pointer; margin: 0; }
.toggle-wrap input { opacity: 0; width: 0; height: 0; }
.toggle-slider { position: absolute; inset: 0; background: #ccc; border-radius: 1em; transition: background .2s; }
.toggle-slider::before { content: ''; position: absolute; width: 1.2em; height: 1.2em; left: 0.2em; bottom: 0.2em; background: #fff; border-radius: 50%; transition: transform .2s; }
.toggle-wrap input:checked + .toggle-slider { background: #00897b; }
.toggle-wrap input:checked + .toggle-slider::before { transform: translateX(1.4em); }

/* 商品情報 */
.product-info { flex: 1; min-width: 0; display: flex; align-items: center; gap: 0.4em; flex-wrap: wrap; }
.bumon-badge  { display: inline-block; background: #e8f5e9; color: #004d40; font-size: 0.72em; padding: 1px 7px; border-radius: 1em; font-weight: bold; white-space: nowrap; flex-shrink: 0; }
.sale-badge   { display: inline-block; background: #c62828; color: #fff; font-size: 0.68em; font-weight: bold; padding: 1px 6px; border-radius: 0.3em; white-space: nowrap; flex-shrink: 0; }
.product-name { font-size: 0.95em; font-weight: bold; color: #222; word-break: break-all; }
.product-row.inactive .product-name { color: #aaa; }
.product-tani { font-size: 0.75em; color: #888; white-space: nowrap; }
.product-price { font-size: 0.88em; color: #555; white-space: nowrap; }
.product-row.inactive .product-price { color: #aaa; }

/* レジ表示部門（店舗別上書き） */
.bumon-override-wrap { display: flex; align-items: center; gap: 0.3em; flex-shrink: 0; }
.bumon-override-wrap label { font-size: 0.72em; color: #888; white-space: nowrap; }
.bumon-select {
    border: 1.5px solid #c8d8c8; border-radius: 0.35em;
    padding: 0.2em 0.3em; font-size: 0.82em; color: #333; max-width: 8em;
}
.bumon-select.saved { border-color: #00897b; }
.bumon-badge-override {
    display: inline-block; background: #fff3e0; color: #e65100;
    font-size: 0.68em; font-weight: bold; padding: 1px 6px; border-radius: 0.3em;
    white-space: nowrap; flex-shrink: 0;
}

/* セール価格 */
.sale-price-wrap { display: flex; align-items: center; gap: 0.3em; flex-shrink: 0; }
.sale-price-wrap label { font-size: 0.72em; color: #888; white-space: nowrap; }
.sale-price-input, .honbai-price-input {
    width: 5.5em; border: 1.5px solid #c8d8c8; border-radius: 0.35em;
    padding: 0.2em 0.4em; font-size: 0.88em; text-align: right;
    color: #c62828; font-weight: bold;
}
.honbai-price-input { color: #004d40; }
.sale-price-input:focus, .honbai-price-input:focus { border-color: #004d40; outline: none; }
.sale-price-input.saved, .honbai-price-input.saved { border-color: #00897b; }

/* 外すボタン */
.btn-remove-row {
    background: #fff3e0; color: #e65100; border: 1px solid #ffcc80;
    border-radius: 0.35em; padding: 0.3em 0.5em;
    font-size: 0.78em; cursor: pointer; white-space: nowrap;
}
.btn-remove-row:hover { background: #ffe0b2; }

/* マスターから追加 */
.master-section { margin: 1.2em 0.8em 0; border: 1.5px solid #a5d6a7; border-radius: 0.6em; background: #f9fffe; }
.master-header  { display: flex; align-items: center; gap: 0.5em; flex-wrap: wrap; background: #e8f5e9; border-radius: 0.5em 0.5em 0 0; padding: 0.5em 0.8em; cursor: pointer; border-bottom: 1px solid #c8e6c9; }
.master-header-title { font-size: 0.95em; font-weight: bold; color: #004d40; flex: 1; }
.master-count { font-size: 0.8em; color: #00897b; }
.master-toggle-icon { font-size: 0.9em; color: #004d40; transition: transform .2s; }
.master-body { padding: 0.4em; display: none; max-height: 50vh; overflow-y: auto; }
.master-body.open { display: block; }

.master-row { display: flex; align-items: center; gap: 0.5em; padding: 0.45em 0.5em; border-bottom: 1px solid #eee; background: #fff; }
.master-row:last-child { border-bottom: none; }
.master-info { flex: 1; min-width: 0; }
.master-name { font-size: 0.9em; font-weight: bold; color: #333; }
.master-sub  { font-size: 0.75em; color: #888; }
.btn-add-from-master {
    background: #004d40; color: #fff; border: none;
    border-radius: 0.35em; padding: 0.3em 0.8em;
    font-size: 0.8em; font-weight: bold; cursor: pointer; white-space: nowrap; flex-shrink: 0;
}
.btn-add-from-master:hover    { background: #00695c; }
.btn-add-from-master:disabled { background: #aaa; cursor: default; }
</style>

<div class="maint-wrap">

  <!-- ページヘッダー -->
  <div class="maint-page-header">
    <span class="maint-page-title">📦 商品メンテ</span>
    <span class="maint-stats">
      発売中 <strong><?= $hanbai_count ?></strong> / <?= $total_count ?> 件
      &nbsp;|&nbsp; <?= htmlspecialchars($store_name) ?>
    </span>
  </div>

  <!-- 部門フィルタータブ -->
  <div class="dept-tabs">
    <button class="dept-tab active" data-dept="">全て (<?= $total_count ?>)</button>
    <?php foreach ($bumon_master as $b):
        $cnt = count(array_filter($products, fn($p) => $p['bumon'] === $b));
        if ($cnt === 0) continue;
    ?>
      <button class="dept-tab" data-dept="<?= htmlspecialchars($b, ENT_QUOTES) ?>">
        <?= htmlspecialchars($b) ?> (<?= $cnt ?>)
      </button>
    <?php endforeach; ?>
  </div>

  <!-- 自店舗商品リスト -->
  <div class="product-list">
    <?php if (empty($products)): ?>
      <div style="text-align:center; padding:3em; color:#888;">商品が登録されていません</div>
    <?php else: ?>
      <?php foreach ($products as $p): ?>
        <div class="product-row <?= $p['hanbai_chu'] ? '' : 'inactive' ?>"
             data-dept="<?= htmlspecialchars($p['bumon'], ENT_QUOTES) ?>">

          <!-- 発売中トグル -->
          <label class="toggle-wrap" title="<?= $p['hanbai_chu'] ? '発売中' : '停止中' ?>">
            <input type="checkbox" class="toggle-hanbai"
                   data-rid="<?= $p['record_id'] ?>"
                   <?= $p['hanbai_chu'] ? 'checked' : '' ?>>
            <span class="toggle-slider"></span>
          </label>

          <!-- 商品情報 -->
          <div class="product-info">
            <span class="product-name"><?= htmlspecialchars($p['name']) ?></span>
            <?php if ($p['sale']): ?><span class="sale-badge">セール</span><?php endif; ?>
            <?php if ($p['tani']): ?><span class="product-tani"><?= htmlspecialchars($p['tani']) ?></span><?php endif; ?>
          </div>

          <!-- レジ表示部門（店舗別に上書き可能。空欄選択で本部既定に戻る） -->
          <div class="bumon-override-wrap">
            <label>部門</label>
            <select class="bumon-select"
                    data-rid="<?= $p['record_id'] ?>"
                    data-my-pos="<?= $p['my_pos'] ?>"
                    title="本部既定: <?= htmlspecialchars($p['master_bumon']) ?>">
              <option value="">-- 本部既定（<?= htmlspecialchars($p['master_bumon']) ?>）--</option>
              <?php foreach ($bumon_master as $b): ?>
                <option value="<?= htmlspecialchars($b, ENT_QUOTES) ?>" <?= $p['bumon_override'] === $b ? 'selected' : '' ?>><?= htmlspecialchars($b) ?></option>
              <?php endforeach; ?>
            </select>
            <?php if ($p['bumon_override'] !== ''): ?>
              <span class="bumon-badge-override" title="この店舗だけの上書き設定">上書き中</span>
            <?php endif; ?>
          </div>

          <!-- 本部設定価格（参考） -->
          <div class="product-price" title="本部設定価格">定価¥<?= number_format($p['master_price']) ?></div>

          <!-- 本体価格（店舗別・編集可能） -->
          <div class="sale-price-wrap">
            <label>本体価格</label>
            <input type="number" class="honbai-price-input"
                   data-rid="<?= $p['record_id'] ?>"
                   data-my-pos="<?= $p['my_pos'] ?>"
                   value="<?= $p['price'] ?>"
                   min="1" max="99999">
          </div>

          <!-- セール価格 -->
          <div class="sale-price-wrap">
            <label>セール価格</label>
            <input type="number" class="sale-price-input"
                   data-rid="<?= $p['record_id'] ?>"
                   data-my-pos="<?= $p['my_pos'] ?>"
                   value="<?= $p['sale_price'] ?: '' ?>"
                   placeholder="－" min="0" max="99999">
          </div>

          <!-- 取扱いから外す -->
          <button class="btn-remove-row"
                  data-rid="<?= $p['record_id'] ?>"
                  data-my-pos="<?= $p['my_pos'] ?>"
                  data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
                  onclick="removeFromStore(this)"
                  title="この店舗の取扱いから外す">✕</button>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- マスターから追加 -->
  <?php if (!empty($master_list)): ?>
  <div class="master-section">
    <div class="master-header" onclick="toggleMaster(this)">
      <span class="master-header-title">📋 マスターから追加</span>
      <span class="master-count"><?= count($master_list) ?> 件</span>
      <span class="master-toggle-icon">▼</span>
    </div>
    <div class="master-body" id="master-body">
      <?php foreach ($master_list as $m): ?>
        <div class="master-row">
          <div class="master-info">
            <div class="master-name">
              <span class="bumon-badge"><?= htmlspecialchars($m['bumon']) ?></span>
              <?= htmlspecialchars($m['name']) ?>
              <?php if ($m['tani']): ?><small style="color:#888;"> <?= htmlspecialchars($m['tani']) ?></small><?php endif; ?>
            </div>
            <div class="master-sub">¥<?= number_format($m['price']) ?></div>
          </div>
          <button class="btn-add-from-master"
                  data-rid="<?= $m['record_id'] ?>"
                  data-next-pos="<?= $m['next_pos'] ?>"
                  data-name="<?= htmlspecialchars($m['name'], ENT_QUOTES) ?>"
                  onclick="addFromMaster(this)">＋ 追加</button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

</div>

<script>
/* ── 発売中トグル ── */
document.querySelectorAll('.toggle-hanbai').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var rid = this.dataset.rid, val = this.checked ? 1 : 0;
        var row = this.closest('.product-row');
        row.classList.toggle('inactive', !this.checked);
        var me = this;
        fetch('shohin_maint.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=toggle&record_id=' + encodeURIComponent(rid) + '&val=' + val
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.ok) {
                me.checked = !me.checked;
                row.classList.toggle('inactive', !me.checked);
                alert('保存に失敗しました。');
            }
        }).catch(function() { me.checked = !me.checked; row.classList.toggle('inactive', !me.checked); });
    });
});

/* ── セール価格 自動保存（フォーカスアウト / Enter）── */
document.querySelectorAll('.sale-price-input').forEach(function(inp) {
    inp.addEventListener('blur', saveSalePrice);
    inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') this.blur(); });
});
function saveSalePrice() {
    var inp   = this;
    var rid   = inp.dataset.rid;
    var myPos = inp.dataset.myPos;
    var val   = parseInt(inp.value) || 0;
    fetch('shohin_maint.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=save_sale_price&record_id=' + encodeURIComponent(rid)
            + '&my_pos=' + encodeURIComponent(myPos)
            + '&sale_price=' + val
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.ok) {
            inp.classList.add('saved');
            setTimeout(function() { inp.classList.remove('saved'); }, 1500);
        } else {
            alert('セール価格の保存に失敗しました。');
        }
    });
}

/* ── 本体価格（店舗別）自動保存（フォーカスアウト / Enter）── */
document.querySelectorAll('.honbai-price-input').forEach(function(inp) {
    inp.dataset.lastValid = inp.value;
    inp.addEventListener('blur', saveHonbaiPrice);
    inp.addEventListener('keydown', function(e) { if (e.key === 'Enter') this.blur(); });
});
function saveHonbaiPrice() {
    var inp   = this;
    var rid   = inp.dataset.rid;
    var myPos = inp.dataset.myPos;
    var val   = parseInt(inp.value) || 0;
    if (val <= 0) {
        alert('本体価格は1円以上を入力してください。');
        inp.value = inp.dataset.lastValid;
        return;
    }
    fetch('shohin_maint.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=save_honbai_price&record_id=' + encodeURIComponent(rid)
            + '&my_pos=' + encodeURIComponent(myPos)
            + '&honbai_price=' + val
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.ok) {
            inp.dataset.lastValid = String(val);
            inp.classList.add('saved');
            setTimeout(function() { inp.classList.remove('saved'); }, 1500);
        } else {
            alert('本体価格の保存に失敗しました。');
            inp.value = inp.dataset.lastValid;
        }
    });
}

/* ── レジ表示部門（店舗別上書き）保存。部門はタブ分け・件数表示にも影響するため保存後に再読込 ── */
document.querySelectorAll('.bumon-select').forEach(function(sel) {
    sel.addEventListener('change', function() {
        var el    = this;
        var rid   = el.dataset.rid;
        var myPos = el.dataset.myPos;
        var val   = el.value;
        el.disabled = true;
        fetch('shohin_maint.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=save_bumon&record_id=' + encodeURIComponent(rid)
                + '&my_pos=' + encodeURIComponent(myPos)
                + '&bumon=' + encodeURIComponent(val)
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (d.ok) { location.reload(); }
            else { alert('部門の保存に失敗しました。'); el.disabled = false; }
        }).catch(function() { alert('通信エラーが発生しました。'); el.disabled = false; });
    });
});

/* ── 部門タブ ── */
document.querySelectorAll('.dept-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.dept-tab').forEach(function(t) { t.classList.remove('active'); });
        this.classList.add('active');
        var dept = this.dataset.dept;
        document.querySelectorAll('.product-row').forEach(function(row) {
            row.style.display = (dept === '' || row.dataset.dept === dept) ? '' : 'none';
        });
    });
});

/* ── マスター開閉 ── */
function toggleMaster(header) {
    var body = document.getElementById('master-body');
    var icon = header.querySelector('.master-toggle-icon');
    body.classList.toggle('open');
    icon.style.transform = body.classList.contains('open') ? 'rotate(180deg)' : '';
}

/* ── カスタム確認ダイアログ（Bootstrap モーダル） ── */
function showConfirm(title, msg) {
    return new Promise(function(resolve) {
        document.getElementById('confirmModalTitle').textContent = title;
        document.getElementById('confirmModalBody').textContent  = msg;
        var el     = document.getElementById('confirmModal');
        var modal  = bootstrap.Modal.getOrCreateInstance(el);
        var okBtn  = document.getElementById('confirmModalOk');
        var clBtn  = document.getElementById('confirmModalCancel');
        function cleanup() {
            okBtn.removeEventListener('click', onOk);
            clBtn.removeEventListener('click', onCancel);
            el.removeEventListener('hidden.bs.modal', onCancel);
        }
        function onOk()     { cleanup(); modal.hide(); resolve(true);  }
        function onCancel() { cleanup(); resolve(false); }
        okBtn.addEventListener('click', onOk);
        clBtn.addEventListener('click', onCancel);
        el.addEventListener('hidden.bs.modal', onCancel, { once: true });
        modal.show();
    });
}

/* ── マスターから追加 ── */
async function addFromMaster(btn) {
    var rid     = btn.dataset.rid;
    var nextPos = btn.dataset.nextPos;
    var name    = btn.dataset.name;
    if (!await showConfirm('商品登録', '「' + name + '」をこの店舗に追加しますか？')) return;
    btn.disabled = true; btn.textContent = '⏳';
    fetch('shohin_maint.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=add_store&record_id=' + encodeURIComponent(rid)
            + '&next_pos=' + encodeURIComponent(nextPos)
    }).then(function(r) { return r.text(); }).then(function(t) {
        var d;
        try { d = JSON.parse(t); } catch(e) { alert('【PHPエラー】\n' + t.substring(0,500)); btn.disabled=false; btn.textContent='＋ 追加'; return; }
        if (d.ok) { location.reload(); }
        else {
            var msg = '追加に失敗\n\n';
            if (d.debug) msg += 'DEBUG:\n' + JSON.stringify(d.debug, null, 2);
            alert(msg);
            btn.disabled = false; btn.textContent = '＋ 追加';
        }
    }).catch(function(e) { alert('通信エラー: '+e); btn.disabled=false; btn.textContent='＋ 追加'; });
}

/* ── 取扱いから外す ── */
async function removeFromStore(btn) {
    var rid   = btn.dataset.rid;
    var myPos = btn.dataset.myPos;
    var name  = btn.dataset.name;
    if (!await showConfirm('取扱い削除', '「' + name + '」をこの店舗の取扱いから外しますか？\n（マスターから削除はされません）')) return;
    fetch('shohin_maint.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=remove_store&record_id=' + encodeURIComponent(rid)
            + '&my_pos=' + encodeURIComponent(myPos)
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.ok) { location.reload(); }
        else { alert('外すのに失敗しました。'); }
    });
}
</script>

<!-- ── 確認モーダル ── -->
<div class="modal fade" id="confirmModal" tabindex="-1" aria-labelledby="confirmModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header" style="background:#004d40; color:#fff;">
        <h5 class="modal-title" id="confirmModalTitle">確認</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="閉じる"></button>
      </div>
      <div class="modal-body" id="confirmModalBody" style="white-space: pre-line;"></div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" id="confirmModalCancel">キャンセル</button>
        <button type="button" class="btn" id="confirmModalOk"
                style="background:#004d40; color:#fff;">OK</button>
      </div>
    </div>
  </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
