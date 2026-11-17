<?php
/**
 * haiki_entry.php  廃棄入力画面（レジ画面と同じ配置・操作性）
 *
 * ・2026-11-07 会議の決定：「廃棄というお客様が来て、すべて0円で購入した」扱いで、
 *   レジ画面（sales_entry.php）と同じ商品配置・操作で入力する。色は赤系にして、
 *   通常のレジ画面と一目で区別できるようにする。合計金額は画面で確認するだけ（印刷なし）。
 * ・保存先は従来どおり haiki_API（商品単位・1日1商品1レコード・廃棄数のみ）。
 *   → 単品管理ダッシュボード等の既存集計はそのまま使える。
 *   金額（定価×数量）は画面表示用の参考値で、保存しない。
 * ・廃棄が発生した商品のみ保存する（0個に戻した商品のレコードは削除）。
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
$is_ajax = !empty($_SERVER['HTTP_X_REQUESTED_WITH'])
        && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
if (!isset($_SESSION['store_id'])) {
    if ($is_ajax) { header('Content-Type: application/json'); http_response_code(401); echo json_encode(['ok'=>false,'error'=>'セッションが切れました。画面を再読み込みしてログインし直してください。']); exit(); }
    header('Location: login.php'); exit();
}
if (($_SESSION['role'] ?? '') === 'hq') {
    if ($is_ajax) { header('Content-Type: application/json'); http_response_code(403); echo json_encode(['ok'=>false,'error'=>'本部アカウントでは登録できません。']); exit(); }
    header('Location: hq_top.php'); exit();
}

require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/bumon_master.php';

$store_id   = $_SESSION['store_id'];
$store_name = $_SESSION['store_name'];

// ---- 対象日付 ----
$target_date_in = $_GET['date'] ?? date('m/d/Y');
$dt = \DateTime::createFromFormat('m/d/Y', $target_date_in) ?: new \DateTime();
$target_date_fm = $dt->format('m/d/Y');
$week_ja = ['Sunday'=>'日','Monday'=>'月','Tuesday'=>'火','Wednesday'=>'水',
            'Thursday'=>'木','Friday'=>'金','Saturday'=>'土'];
$target_date_jp = $dt->format('n/j') . '（' . ($week_ja[$dt->format('l')] ?? '') . '）';

$today_dt  = new DateTime(); $today_dt->setTime(0,0,0);
$dt_clone  = clone $dt;     $dt_clone->setTime(0,0,0);
$is_future_page = ($dt_clone > $today_dt);
$prev_dt   = (clone $dt_clone)->modify('-1 day');
$next_dt   = (clone $dt_clone)->modify('+1 day');
$max_nav_dt      = (clone $today_dt)->modify('+30 days');
$next_is_too_far = ($next_dt > $max_nav_dt);
$prev_date_fm  = $prev_dt->format('m/d/Y');
$next_date_fm  = $next_dt->format('m/d/Y');
$date_html     = $dt_clone->format('Y-m-d');
$date_html_max = $max_nav_dt->format('Y-m-d');

// ---- 部門マスタ（並び順）と、店舗ごとの部門タブ設定（sales_entry.php と同じ） ----
$bumon_master   = fetch_bumon_master($host, $db, $layout_bumon, $api_master_user, $api_master_pass);
$category_order = bumon_names($bumon_master);

$bumon_tab = ['hidden' => [], 'order' => []];
try {
    $fmAcc = new fmRESTor($host, $db, $layout_account, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
    $resAcc = $fmAcc->findRecords(['query' => [['店舗Ｎｏ' => $store_id]]]);
    $fd = $resAcc['result']['response']['data'][0]['fieldData'] ?? [];
    foreach ($category_order as $b) {
        if ((int)($fd['非表示_' . $b] ?? 0) === 1) $bumon_tab['hidden'][$b] = true;
        $o = trim((string)($fd['表示順_' . $b] ?? ''));
        if ($o !== '') $bumon_tab['order'][$b] = (int)$o;
    }
} catch (\Throwable $e) {
    // 取得失敗時は既定の並び・全表示
}

// ---- 自店の取扱商品一覧（hanbai_API、sales_entry.php と同じ絞り込み・同じ表示部門・同じ価格） ----
$category_map = ['焼魚' => '魚', '煮魚' => '魚', 'いか焼き' => 'イカ焼', 'いか焼' => 'イカ焼'];
$fmHanbai = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$resHanbai = $fmHanbai->getRecords(['_limit' => 1000]);
$products = [];
foreach ($resHanbai['result']['response']['data'] ?? [] as $row) {
    $f = $row['fieldData'];
    $n = trim($f['商品名'] ?? '');
    if ($n === '') continue;
    if ((int)($f['発売中'] ?? 0) !== 1) continue;
    $positions = getStorePositions($f);
    if (!in_array($store_id, $positions, true)) continue;

    $store_bumon = getStoreBumon($f, $store_id);
    $b   = $store_bumon !== '' ? $store_bumon : trim($f['部門'] ?? '');
    $cat = $category_map[$b] ?? $b;

    $store_honbai = getStoreHonbaiPrice($f, $store_id);
    $base_price   = ($store_honbai > 0) ? $store_honbai : (int)($f['本体価格'] ?? 0);
    $sale_price   = getStoreSalePrice($f, $store_id);
    $disp_price   = ($sale_price > 0) ? $sale_price : $base_price;

    $products[] = [
        'bumon' => $cat,
        'name'  => $n,
        'yomi'  => trim($f['よみがな'] ?? ''),
        'price' => $disp_price,
    ];
}
$cat_pos = array_flip($category_order);
$effective_cat_pos = function(string $cat) use ($cat_pos, $bumon_tab): int {
    return $bumon_tab['order'][$cat] ?? ($cat_pos[$cat] ?? 999);
};
usort($products, function($a, $b) use ($effective_cat_pos) {
    $pa = $effective_cat_pos($a['bumon']);
    $pb = $effective_cat_pos($b['bumon']);
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['yomi'], $b['yomi']);
});
$bumon_list = [];
foreach ($products as $p) {
    if ($p['bumon'] !== '' && !in_array($p['bumon'], $bumon_list, true)
        && empty($bumon_tab['hidden'][$p['bumon']])) {
        $bumon_list[] = $p['bumon'];
    }
}
usort($bumon_list, fn($a, $b) => $effective_cat_pos($a) <=> $effective_cat_pos($b));

// ---- 当日の既存 haiki_API レコード（商品名 => ['recordId'=>, 'count'=>]） ----
$fmHaiki = new fmRESTor($host, $db, $layout_haiki, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
function haiki_fetch_existing(fmRESTor $fmHaiki, string $store_id, string $date_fm): array {
    $qr = $fmHaiki->findRecords([
        'query' => [['fk_店舗No' => $store_id, '売上日' => $date_fm]],
        'limit' => 1000,
    ]);
    $existing = [];
    if (($qr['result']['messages'][0]['code'] ?? '0') !== '401') {
        foreach ($qr['result']['response']['data'] ?? [] as $row) {
            $f = $row['fieldData'];
            $n = trim($f['商品名'] ?? '');
            if ($n === '') continue;
            $existing[$n] = ['recordId' => $row['recordId'], 'count' => (int)($f['廃棄数'] ?? 0)];
        }
    }
    return $existing;
}
$existing = haiki_fetch_existing($fmHaiki, $store_id, $target_date_fm);

// ---- POST 保存（AJAX / JSON）----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    if (!$is_ajax) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'不正なリクエストです。']); exit(); }
    if ($is_future_page) { echo json_encode(['ok'=>false,'error'=>'この日付はまだ到来していません。']); exit(); }

    $body  = json_decode(file_get_contents('php://input'), true) ?? [];
    $items = $body['items'] ?? [];
    if (!is_array($items)) $items = [];

    // 受け付ける商品名＝取扱商品 ＋ 当日すでに保存済みの商品
    $allowed = [];
    foreach ($products as $p) $allowed[$p['name']] = true;
    foreach ($existing as $n => $_) $allowed[$n] = true;

    $want = []; // 商品名 => 廃棄数
    foreach ($items as $it) {
        $n = trim((string)($it['name'] ?? ''));
        $q = (int)($it['qty'] ?? 0);
        if ($n === '' || !isset($allowed[$n]) || $q <= 0) continue;
        $want[$n] = min($q, 9999);
    }

    // 保存直前に最新の状態を取り直して差分を反映する（別端末の入力との食い違いを減らす）
    $existing = haiki_fetch_existing($fmHaiki, $store_id, $target_date_fm);
    $fail = 0;
    foreach ($want as $n => $q) {
        $ex = $existing[$n] ?? null;
        if ($ex) {
            if ($ex['count'] === $q) continue;
            $res = $fmHaiki->editRecord($ex['recordId'], ['fieldData' => ['廃棄数' => $q]]);
        } else {
            $res = $fmHaiki->createRecord(['fieldData' => [
                'fk_店舗No' => $store_id,
                '店舗名'    => $store_name,
                '売上日'    => $target_date_fm,
                '商品名'    => $n,
                '廃棄数'    => $q,
            ]]);
        }
        if ($fmHaiki->isError($res)) $fail++;
    }
    foreach ($existing as $n => $ex) {
        if (!isset($want[$n])) {
            // カートから外した（0個に戻した）商品はレコードごと削除
            $res = $fmHaiki->deleteRecord($ex['recordId']);
            if ($fmHaiki->isError($res)) $fail++;
        }
    }

    if ($fail === 0) {
        echo json_encode(['ok' => true, 'count' => count($want)]);
    } else {
        echo json_encode(['ok' => false, 'error' => '一部の保存に失敗しました（' . $fail . '件）。もう一度「廃棄を登録する」を押してください。']);
    }
    exit();
}

// ---- 画面に渡す初期カート（保存済みの廃棄） ----
$price_of = [];
foreach ($products as $p) $price_of[$p['name']] = $p;
$init_cart = [];
foreach ($existing as $n => $ex) {
    if ($ex['count'] <= 0) continue;
    $p = $price_of[$n] ?? null;   // 現在は取扱いが無い商品は 0円・部門なしで表示
    $init_cart[] = [
        'name'  => $n,
        'bumon' => $p['bumon'] ?? '',
        'price' => $p['price'] ?? 0,
        'qty'   => $ex['count'],
    ];
}

include __DIR__ . '/header.php';
// header.php の <div class="container mt-4"> を閉じ、余白をリセット（sales_entry.php と同じ）
echo '</div>';
?>
<style>
/* === レイアウト（sales_entry.php と同じ配置。上に日付バーを追加） === */
body > nav + div { margin-top: 0 !important; padding-top: 0 !important; }
:root { --pos-font-size: 16px; --hk: #b71c1c; --hk-dark: #8e0000; --hk-light: #ffebee; }
body { overflow: hidden; margin: 0; padding: 0; }
.pos-wrap {
    font-size: var(--pos-font-size);
    display: grid;
    grid-template-columns: 1fr;
    grid-template-rows: auto auto 1fr auto;  /* 日付 → タブ → 商品 → カート */
    height: calc(100vh - 48px);
    height: calc(100dvh - 48px);
    overflow: hidden;
    margin-top: 0 !important;
    background: #fff5f5;
}

/* === 廃棄画面の目印（日付バー） === */
.hk-bar {
    display: flex; align-items: center; justify-content: space-between; gap: 0.5em;
    padding: 0.35em 0.75em;
    background: var(--hk); color: #fff;
}
.hk-bar .hk-title { font-weight: bold; font-size: 1em; white-space: nowrap; }
.hk-bar .hk-store { font-size: 0.78em; opacity: .85; }
.date-nav { display: flex; align-items: center; gap: 0.3em; }
.date-nav-btn {
    background: #fff; color: var(--hk);
    border: none; border-radius: 0.4em;
    padding: 0.15em 0.7em; font-size: 1em; font-weight: bold;
    text-decoration: none; line-height: 1.6;
}
.date-nav-btn.disabled { background: #e0b4b4; pointer-events: none; }
.date-nav input[type=date] {
    font-size: 0.85em; padding: 0.2em 0.4em;
    border: 2px solid #fff; border-radius: 0.4em;
    color: var(--hk); font-weight: bold; background: #fff;
}

/* === カテゴリータブ === */
.pos-bumon {
    padding: 0.4em 0.75em !important;
    background: var(--hk-light) !important;
    border-bottom: 1px solid #e0b4b4;
    display: flex !important;
    flex-wrap: nowrap !important;
    gap: 0.4em;
    overflow-x: auto !important;
    -webkit-overflow-scrolling: touch;
    scrollbar-width: none;
}
.pos-bumon::-webkit-scrollbar { display: none; }
.bumon-btn {
    padding: 0.3em 0.8em;
    border: 2px solid var(--hk);
    border-radius: 2em;
    background: #fff;
    color: var(--hk);
    font-size: 1em;
    font-weight: bold;
    cursor: pointer;
    flex-shrink: 0;
    white-space: nowrap;
}
.bumon-btn.active { background: var(--hk); color: #fff; }

/* === 商品グリッド === */
.pos-shohin {
    overflow-y: auto;
    padding: 0.3em 0.5em;
    background: #fff5f5;
    display: grid !important;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.3em;
    align-content: start;
}
.shohin-item {
    display: flex; flex-direction: column; align-items: center; justify-content: center;
    padding: 0.4em 0.3em;
    border: 2px solid #e8b4b4;
    border-radius: 0.5em;
    background: #ffeaea;
    cursor: pointer;
    font-size: 0.9em;
    text-align: center;
    box-sizing: border-box;
    transition: all .12s;
    min-height: 3.2em;
    line-height: 1.3;
    -webkit-tap-highlight-color: transparent;
    user-select: none;
}
.shohin-item.hidden { display: none !important; }
.shohin-item:hover  { filter: brightness(0.95); }
.shohin-item.flash  { transform: scale(0.92); filter: brightness(0.8); }
.shohin-item .s-name  { font-weight: bold; font-size: 0.95em; color: #4a0000; }
.shohin-item .s-price { font-weight: bold; color: var(--hk); font-size: 0.85em; margin-top: 0.1em; }
.shohin-item .s-badge {
    display: inline-block; min-width: 1.6em; padding: 0 0.35em; margin-top: 0.15em;
    background: var(--hk); color: #fff; border-radius: 1em; font-size: 0.8em; font-weight: bold;
}

/* === カートエリア（画面下部。横向きタブレットでは右パネル） === */
.pos-cart {
    background: #fff;
    border-top: 2px solid var(--hk);
    box-shadow: 0 -2px 8px rgba(0,0,0,.1);
    display: flex; flex-direction: column;
    max-height: 40vh; overflow: hidden;
}
.cart-items { overflow-y: auto; padding: 0.3em 0.5em; flex: 1; min-height: 0; }
.cart-empty { text-align: center; color: #aaa; font-size: 0.85em; padding: 0.6em; }
.ci-row {
    display: flex; align-items: center; gap: 0.3em;
    padding: 0.25em 0; border-bottom: 1px solid #f5e0e0; font-size: 0.85em;
}
.ci-row:last-child { border-bottom: none; }
.ci-name { flex: 1; min-width: 0; font-weight: bold; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; }
.ci-qty { display: flex; align-items: center; gap: 0.2em; }
.ci-qty-btn {
    width: 1.6em; height: 1.6em; border-radius: 50%;
    border: 1.5px solid var(--hk); background: #fff; color: var(--hk);
    font-size: 0.9em; font-weight: bold; cursor: pointer;
    display: flex; align-items: center; justify-content: center; line-height: 1; padding: 0;
}
.ci-qty-btn:hover { background: var(--hk); color: #fff; }
.ci-qty-num { font-weight: bold; min-width: 1.3em; text-align: center; font-size: 1em; }
.ci-subtotal { font-weight: bold; color: var(--hk); white-space: nowrap; min-width: 3.5em; text-align: right; }
.ci-del {
    border: none; background: #e53935; color: #fff; cursor: pointer;
    font-size: 0.78em; font-weight: bold; padding: 0.3em 0.55em; border-radius: 0.3em;
    white-space: nowrap; flex-shrink: 0; min-height: 2.2em; line-height: 1.2;
}
.ci-del:hover, .ci-del:active { background: var(--hk-dark); }
.clear-cart-btn {
    background: none; color: #e53935; font-size: 0.78em; font-weight: bold; cursor: pointer;
    padding: 0.2em 0.5em; border-radius: 0.3em; border: 1.5px solid #e53935; white-space: nowrap;
}
.clear-cart-btn:hover { background: #e53935; color: #fff; }
.cart-footer {
    display: flex; align-items: center; justify-content: space-between;
    padding: 0.4em 0.6em;
    padding-bottom: calc(0.4em + env(safe-area-inset-bottom, 0px));
    border-top: 1px solid #e0e0e0; background: #fdf3f3;
}
.cart-total-label { font-size: 0.85em; color: #555; }
.cart-total-amount { font-size: 1.3em; font-weight: bold; color: var(--hk); }
.cart-total-note { font-size: 0.7em; color: #888; margin-left: 0.3em; }
.register-btn {
    background: var(--hk); color: #fff; border: none; border-radius: 0.5em;
    padding: 0.5em 1.2em; font-size: 1em; font-weight: bold; cursor: pointer; white-space: nowrap;
}
.register-btn:hover { background: var(--hk-dark); }
.register-btn:disabled { background: #bbb; cursor: not-allowed; }
.register-btn.loading { background: #666; pointer-events: none; }
.cart-panel-header { display: none; }

.future-note {
    background: #e3f2fd; color: #0d47a1; text-align: center; font-size: 0.85em; padding: 0.4em;
}

/* === トースト === */
.toast-ok, .toast-err {
    position: fixed; top: 60px; left: 50%; transform: translateX(-50%);
    color: #fff; padding: 0.7em 1.5em; border-radius: 0.6em; font-size: 1.05em; font-weight: bold;
    box-shadow: 0 4px 16px rgba(0,0,0,.25); z-index: 9999; opacity: 0; transition: opacity .3s;
    pointer-events: none; max-width: 90vw; text-align: center;
}
.toast-ok  { background: #2e7d32; }
.toast-err { background: #c62828; }
.toast-ok.show, .toast-err.show { opacity: 1; }

/* === 横向きタブレット：左に商品・右にカート（sales_entry.php と同じ） === */
@media (orientation: landscape) {
    .pos-wrap {
        grid-template-columns: 1.7fr 1fr;
        grid-template-rows: auto auto 1fr;
        grid-template-areas:
            "bar    cart"
            "tabs   cart"
            "shohin cart";
    }
    .hk-bar     { grid-area: bar; }
    .pos-bumon  { grid-area: tabs; }
    .pos-shohin { grid-area: shohin; }
    .pos-cart {
        grid-area: cart; height: 100%; max-height: none;
        border-top: none; border-left: 2px solid var(--hk);
        box-shadow: -2px 0 8px rgba(0,0,0,.1);
    }
    .cart-panel-header {
        display: flex; align-items: center; justify-content: space-between;
        padding: 0.6em 0.8em; background: var(--hk); color: #fff; font-weight: bold; font-size: 0.95em;
    }
}
</style>

<div class="toast-ok" id="toast-ok"></div>
<div class="toast-err" id="toast-err"></div>

<div class="pos-wrap">

  <!-- 0. 廃棄画面の目印＋日付 -->
  <div class="hk-bar">
    <div>
      <div class="hk-title">🗑 廃棄入力</div>
      <div class="hk-store"><?= htmlspecialchars($store_name) ?></div>
    </div>
    <div class="date-nav">
      <a class="date-nav-btn" href="?date=<?= urlencode($prev_date_fm) ?>">◀</a>
      <input type="date" id="date-picker" value="<?= $date_html ?>" max="<?= $date_html_max ?>">
      <a class="date-nav-btn <?= $next_is_too_far ? 'disabled' : '' ?>" href="?date=<?= urlencode($next_date_fm) ?>">▶</a>
    </div>
  </div>

  <!-- 1. カテゴリータブ -->
  <div class="pos-bumon" id="bumon-area">
    <button class="bumon-btn active" data-bumon="">すべて</button>
    <?php foreach ($bumon_list as $b): ?>
      <button class="bumon-btn" data-bumon="<?= htmlspecialchars($b, ENT_QUOTES) ?>"><?= htmlspecialchars($b) ?></button>
    <?php endforeach; ?>
  </div>

  <!-- 2. 商品グリッド -->
  <div class="pos-shohin" id="shohin-list">
    <?php if ($is_future_page): ?><div class="future-note" style="grid-column:1/-1;">📅 この日付はまだ到来していません。入力は当日以降に行ってください。</div><?php endif; ?>
    <?php foreach ($products as $p): ?>
      <button class="shohin-item"
              data-bumon="<?= htmlspecialchars($p['bumon'], ENT_QUOTES) ?>"
              data-name="<?= htmlspecialchars($p['name'], ENT_QUOTES) ?>"
              data-price="<?= (int)$p['price'] ?>"
              <?= $is_future_page ? 'disabled' : '' ?>>
        <span class="s-name"><?= htmlspecialchars($p['name']) ?></span>
        <span class="s-price">¥<?= number_format($p['price']) ?></span>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- 3. カート（スマホ：画面下部 / タブレット：右パネル） -->
  <div class="pos-cart" id="pos-cart">
    <div class="cart-panel-header">
      <span>🗑 廃棄リスト</span>
      <span id="cart-item-count" style="font-size:0.85em;opacity:.8;"></span>
    </div>
    <div class="cart-items" id="cart-items">
      <div class="cart-empty" id="cart-empty">廃棄した商品をタップして追加</div>
    </div>
    <div class="cart-footer">
      <div>
        <span class="cart-total-label">廃棄金額</span>
        <span class="cart-total-amount" id="cart-total">¥0</span>
        <span class="cart-total-note" id="cart-qty-note"></span>
        <button type="button" class="clear-cart-btn" id="clear-cart-btn" style="display:none;margin-left:.5em;">🗑 全消去</button>
      </div>
      <button class="register-btn" id="reg-btn" <?= $is_future_page ? 'disabled' : '' ?>>廃棄を登録する</button>
    </div>
  </div>

</div>

<script>
(function() {
'use strict';

const IS_FUTURE = <?= $is_future_page ? 'true' : 'false' ?>;
/* 保存済みの廃棄（この日付）。{name,bumon,price,qty} */
let cart = <?= json_encode($init_cart, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?>;
let dirty = false;

const $ = id => document.getElementById(id);
const elCartItems = $('cart-items'), elCartEmpty = $('cart-empty'), elCartTotal = $('cart-total');
const elRegBtn = $('reg-btn'), elClearBtn = $('clear-cart-btn'), elQtyNote = $('cart-qty-note');
const elToastOk = $('toast-ok'), elToastErr = $('toast-err');

function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function toast(el, msg, ms) {
    el.textContent = msg; el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), ms || 2200);
}

/* 日付ピッカー */
$('date-picker').addEventListener('change', function() {
    if (dirty && !confirm('登録していない入力があります。日付を変えると消えます。よろしいですか？')) {
        this.value = '<?= $date_html ?>'; return;
    }
    const p = this.value.split('-');
    if (p.length !== 3) return;
    dirty = false;
    location.href = '?date=' + encodeURIComponent(p[1] + '/' + p[2] + '/' + p[0]);
});
document.querySelectorAll('.date-nav-btn').forEach(a => a.addEventListener('click', function(e) {
    if (dirty && !confirm('登録していない入力があります。日付を変えると消えます。よろしいですか？')) e.preventDefault();
    else dirty = false;
}));
window.addEventListener('beforeunload', function(e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

/* カテゴリータブ */
$('bumon-area').addEventListener('click', function(e) {
    const btn = e.target.closest('.bumon-btn');
    if (!btn) return;
    document.querySelectorAll('.bumon-btn').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    const bumon = btn.dataset.bumon;
    document.querySelectorAll('.shohin-item').forEach(el => {
        el.classList.toggle('hidden', bumon !== '' && el.dataset.bumon !== bumon);
    });
});

/* 商品タップ → 数量+1 */
$('shohin-list').addEventListener('click', function(e) {
    const btn = e.target.closest('.shohin-item');
    if (!btn || IS_FUTURE) return;
    btn.classList.add('flash');
    setTimeout(() => btn.classList.remove('flash'), 150);
    const name = btn.dataset.name, price = parseInt(btn.dataset.price, 10) || 0;
    const exist = cart.find(it => it.name === name);
    if (exist) exist.qty++;
    else cart.push({name, bumon: btn.dataset.bumon, price, qty: 1});
    dirty = true;
    renderCart();
});

/* カート描画 */
function renderCart() {
    elCartItems.querySelectorAll('.ci-row').forEach(el => el.remove());
    elCartEmpty.style.display = cart.length ? 'none' : '';
    cart.forEach((item, i) => {
        const row = document.createElement('div');
        row.className = 'ci-row';
        row.dataset.idx = i;
        row.innerHTML =
            '<span class="ci-name">' + esc(item.name) + '</span>' +
            '<div class="ci-qty">' +
                '<button type="button" class="ci-qty-btn ci-minus">－</button>' +
                '<span class="ci-qty-num">' + item.qty + '</span>' +
                '<button type="button" class="ci-qty-btn ci-plus">＋</button>' +
            '</div>' +
            '<span class="ci-subtotal">¥' + (item.price * item.qty).toLocaleString() + '</span>' +
            '<button type="button" class="ci-del">🗑 削除</button>';
        elCartItems.appendChild(row);
    });
    const total = cart.reduce((s, it) => s + it.price * it.qty, 0);
    const qty   = cart.reduce((s, it) => s + it.qty, 0);
    elCartTotal.textContent = '¥' + total.toLocaleString();
    elQtyNote.textContent = cart.length ? '（' + qty + '個）' : '';
    /* 保存済みの廃棄を全部消して登録する場合もあるので、登録ボタンは「変更あり」で有効にする */
    elRegBtn.disabled = IS_FUTURE || !dirty;
    elClearBtn.style.display = cart.length > 0 ? '' : 'none';
    const countEl = $('cart-item-count');
    if (countEl) countEl.textContent = cart.length > 0 ? cart.length + '点' : '';
}

/* カート内操作 */
elCartItems.addEventListener('click', function(e) {
    const row = e.target.closest('.ci-row');
    if (!row) return;
    const idx = parseInt(row.dataset.idx, 10);
    const item = cart[idx];
    if (!item) return;
    if (e.target.closest('.ci-plus')) item.qty++;
    else if (e.target.closest('.ci-minus')) { item.qty--; if (item.qty <= 0) cart.splice(idx, 1); }
    else if (e.target.closest('.ci-del')) cart.splice(idx, 1);
    else return;
    dirty = true;
    renderCart();
});
elClearBtn.addEventListener('click', function() {
    if (!confirm('廃棄リストをすべて消しますか？（「廃棄を登録する」を押すまでは保存されません）')) return;
    cart = []; dirty = true; renderCart();
});

/* 登録（保存済みの廃棄との差分を反映。印刷はしない） */
let submitting = false;
elRegBtn.addEventListener('click', async function() {
    if (submitting || IS_FUTURE) return;
    if (cart.length === 0 && !confirm('廃棄リストが空です。この日の廃棄をすべて取り消して登録しますか？')) return;
    submitting = true;
    elRegBtn.classList.add('loading'); elRegBtn.textContent = '登録中…';
    try {
        const res = await fetch(location.href, {
            method: 'POST',
            headers: {'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            body: JSON.stringify({items: cart.map(it => ({name: it.name, qty: it.qty}))}),
            credentials: 'same-origin'
        });
        const data = await res.json();
        if (data.ok) {
            dirty = false;
            toast(elToastOk, '✅ 廃棄を登録しました', 1500);
            setTimeout(() => location.reload(), 900);
        } else {
            toast(elToastErr, data.error || '登録に失敗しました。', 4000);
        }
    } catch (err) {
        toast(elToastErr, '通信できませんでした。電波の良い場所でもう一度押してください。', 4000);
    }
    submitting = false;
    elRegBtn.classList.remove('loading'); elRegBtn.textContent = '廃棄を登録する';
    renderCart();
});

renderCart();
})();
</script>

<?php include __DIR__ . '/footer.php'; ?>
