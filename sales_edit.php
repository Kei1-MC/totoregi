<?php
/**
 * sales_edit.php  レシート訂正画面 v2
 * - 元レシートをカートに読み込んで直接編集
 * - 数量・値引きの変更、行削除、新商品追加
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
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/bumon_master.php';
require_once __DIR__ . '/instore_codes.php';

$store_id   = $_SESSION['store_id'];
$store_name = $_SESSION['store_name'];

// 部門マスタ（並び順昇順。bumon_API から取得。sales_entry.php と同方式）
$bumon_master   = fetch_bumon_master($host, $db, $layout_bumon, $api_master_user, $api_master_pass);
$category_order = bumon_names($bumon_master);

// インストアコード: FM account_API から取得（sales_entry.php と同方式）
function _fetch_instore_codes_from_fm_edit(string $store_id, string $host, string $db,
                                            string $layout_account,
                                            string $api_master_user, string $api_master_pass,
                                            array $dept_fields): array {
    try {
        $fm = new \fmRESTor\fmRESTor($host, $db, $layout_account,
                                      $api_master_user, $api_master_pass,
                                      ['allowInsecure' => true]);
        $res = $fm->findRecords(['query' => [['店舗Ｎｏ' => $store_id]]]);
        $fd  = $res['result']['response']['data'][0]['fieldData'] ?? [];
        $codes = [];
        foreach ($dept_fields as $cat => $field) {
            $v = trim((string)($fd[$field] ?? ''));
            if ($v !== '') $codes[$cat] = $v;
        }
        return $codes;
    } catch (\Throwable $e) {
        return [];
    }
}

$ic_dept_fm = _fetch_instore_codes_from_fm_edit(
    $store_id, $host, $db, $layout_account, $api_master_user, $api_master_pass,
    bumon_ic_field_map($bumon_master)
);
// フォールバック: instore_codes.php の静的設定
$_ic_static = $instore_config[$store_id] ?? [];
$_ic_static_dept = $_ic_static['dept'] ?? [];
if (empty($ic_dept_fm) && !empty($_ic_static_dept)) {
    $pfx = $_ic_static['prefix'] ?? '00';
    $ic_dept = [];
    foreach ($_ic_static_dept as $cat => $code3) {
        $ic_dept[$cat] = '20' . $pfx . $code3;
    }
} else {
    $ic_dept = $ic_dept_fm;
}

$raw     = $_GET['data'] ?? '';
$receipt = json_decode($raw, true);
if (!$receipt || empty($receipt['record_ids'])) {
    header('Location: sales_list.php');
    exit();
}

$receipt_no = $receipt['receipt_no'] ?? '';
$record_ids = $receipt['record_ids'] ?? [];
$items      = $receipt['items']      ?? [];
$date_raw   = $receipt['date']       ?? date('Y-m-d');
$date_fm    = (new DateTime($date_raw))->format('m/d/Y');
$date_jp    = (new DateTime($date_raw))->format('Y年n月j日');

$nebiki_ritsu_master = [10, 20, 30, 50];
$nebiki_gaku_master  = [50, 100, 200, 300];

$error_message = '';

// ---- POST: レシート全体削除 ----
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $is_ajax) {
    $json_body = json_decode(file_get_contents('php://input'), true) ?? [];
    header('Content-Type: application/json');

    // 全体削除モード
    if (!empty($json_body['action']) && $json_body['action'] === 'delete_all') {
        $del_ids = $json_body['record_ids'] ?? [];
        $fm = new fmRESTor($host, $db, $layout_pos, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
        $fail = 0;
        foreach ($del_ids as $rid) {
            $r = $fm->deleteRecord((int)$rid);
            if ($fm->isError($r)) $fail++;
        }
        // ヘッダーも削除
        $hdr_no = $json_body['receipt_no'] ?? '';
        if ($hdr_no !== '') {
            $fmH = new fmRESTor($host, $db, 'sales_header_API', $api_master_user, $api_master_pass, ['allowInsecure' => true]);
            $fmH->deleteRecord((int)$hdr_no);
        }
        if ($fail === 0) {
            echo json_encode(['ok' => true]);
        } else {
            echo json_encode(['ok' => false, 'error' => $fail . '件の削除に失敗しました。']);
        }
        exit();
    }

    // 訂正登録モード（AJAX）
    if (!empty($json_body['action']) && $json_body['action'] === 'update') {
        $new_items = $json_body['cart_items'] ?? [];
        $del_ids   = $json_body['record_ids'] ?? [];

        if (empty($new_items)) {
            echo json_encode(['ok' => false, 'error' => '訂正内容が空です。']);
            exit();
        }

        $fm = new fmRESTor($host, $db, $layout_pos, $api_master_user, $api_master_pass, ['allowInsecure' => true]);

        // 既存明細を全件削除
        $del_fail = 0;
        foreach ($del_ids as $rid) {
            $r = $fm->deleteRecord((int)$rid);
            if ($fm->isError($r)) $del_fail++;
        }

        // 訂正内容を新規登録
        $reg_fail = 0;
        $total = 0;
        foreach ($new_items as $item) {
            $honbai     = (int)($item['price']         ?? 0);
            $suryo      = (int)($item['qty']           ?? 1);
            $neb_gaku   = (int)($item['nebiki_gaku']   ?? 0);
            $neb_ritsu  = (float)($item['nebiki_ritsu'] ?? 0);
            $hanbai_kin = ($honbai - $neb_gaku) * $suryo;
            $total += $hanbai_kin;
            $r = $fm->createRecord(['fieldData' => [
                '販売日時'    => $date_fm,
                '店舗No'     => $store_id,
                '商品名'     => $item['name']  ?? '',
                '部門'       => $item['bumon'] ?? '',
                '販売単位'   => $item['tani']  ?? '',
                '本体価格'   => $honbai,
                '数量'       => $suryo,
                '値引額'     => $neb_gaku,
                '値引率'     => $neb_ritsu,
                '販売金額'   => $hanbai_kin,
                '明細金額'   => $hanbai_kin,
                'レシート番号' => $receipt_no,
            ]]);
            if ($fm->isError($r)) $reg_fail++;
        }

        if ($del_fail === 0 && $reg_fail === 0) {
            echo json_encode(['ok' => true, 'receipt_no' => $receipt_no, 'total' => $total, 'count' => count($new_items)]);
        } else {
            $msg = '訂正処理中にエラーが発生しました。';
            if ($del_fail > 0) $msg .= "（削除失敗:{$del_fail}件）";
            if ($reg_fail > 0) $msg .= "（登録失敗:{$reg_fail}件）";
            echo json_encode(['ok' => false, 'error' => $msg]);
        }
        exit();
    }
}

// 販売商品マスタ取得（新商品追加用）
$fm2       = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$res2      = $fm2->getRecords(['_limit' => 500]);
$all_products = [];
foreach ($res2['result']['response']['data'] ?? [] as $row) {
    $f = $row['fieldData'];
    // 発売中の自店舗取扱い商品のみ（sales_entry.php と同方式。取扱店舗は繰り返しフィールドのため
    // 単純な文字列比較ではなく getStorePositions() で全ポジションを見る必要がある）
    if ((int)($f['発売中'] ?? 0) !== 1) continue;
    $positions = getStorePositions($f);
    if (!in_array($store_id, $positions, true)) continue;
    $n = trim($f['商品名'] ?? '');
    if ($n === '') continue;

    // 本体価格：店舗別設定 → 本部設定の順にフォールバックし、セール価格があればさらに優先（sales_entry.php と同方式）
    $store_honbai = getStoreHonbaiPrice($f, $store_id);
    $base_price   = ($store_honbai > 0) ? $store_honbai : (int)($f['本体価格'] ?? 0);
    $sale_price   = getStoreSalePrice($f, $store_id);

    $all_products[] = [
        'bumon' => trim($f['部門']     ?? ''),
        'name'  => $n,
        'yomi'  => trim($f['よみがな'] ?? ''),
        'tani'  => trim($f['販売単位'] ?? ''),
        'price' => ($sale_price > 0) ? $sale_price : $base_price,
    ];
}
usort($all_products, fn($a,$b) => strcmp($a['yomi'] ?: $a['name'], $b['yomi'] ?: $b['name']));

// 元データをJS用に整形
$initial_cart = array_map(fn($it) => [
    'name'         => $it['name'],
    'bumon'        => $it['bumon'],
    'tani'         => $it['tani'],
    'price'        => $it['price'],
    'qty'          => $it['qty'],
    'nebiki_gaku'  => $it['nebiki_gaku'],
    'nebiki_ritsu' => $it['nebiki_ritsu'],
    'subtotal'     => $it['kingaku'],
], $items);

include __DIR__ . '/header.php';
echo '</div>';
?>
<style>
:root { --pos-font-size: 18px; }
.edit-wrap {
    font-size: var(--pos-font-size);
    max-width: 640px;
    margin: 0 auto;
    padding: 0.5em 0.75em 3em;
}

.edit-badge {
    background: #fff3cd; border: 2px solid #ffc107;
    border-radius: 0.6em; padding: 0.6em 1em; margin-bottom: 0.8em;
    font-size: 0.85em;
}
.edit-badge .title { font-weight: bold; color: #856404; }
.edit-badge .caution { color: #856404; margin-top: 0.2em; font-size: 0.9em; }

/* カートテーブル */
.cart-wrap {
    background: #fff; border: 2px solid #004d40;
    border-radius: 0.6em; overflow: hidden; margin-bottom: 0.8em;
}
.cart-wrap h3 {
    background: #004d40; color: #fff;
    font-size: 0.9em; font-weight: bold;
    margin: 0; padding: 0.4em 0.8em;
}
.cart-table { width: 100%; border-collapse: collapse; font-size: 0.85em; }
.cart-table th {
    background: #f0f4f0; color: #555;
    padding: 0.3em 0.5em; text-align: left;
    font-size: 0.8em; font-weight: bold;
    border-bottom: 1px solid #ddd;
}
.cart-table td {
    padding: 0.4em 0.5em;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}
.cart-table tr:last-child td { border-bottom: none; }
.cart-table .td-qty { text-align: center; white-space: nowrap; }
.cart-table .td-neb { white-space: nowrap; }
.cart-table .td-price { text-align: right; font-weight: bold; white-space: nowrap; }

/* インライン数量変更 */
.inline-qty { display: flex; align-items: center; gap: 0.2em; justify-content: center; }
.iqty-btn {
    width: 1.4em; height: 1.4em; border-radius: 50%;
    border: 1.5px solid #004d40; background: #fff; color: #004d40;
    font-size: 0.9em; font-weight: bold; cursor: pointer; line-height: 1;
    display: flex; align-items: center; justify-content: center;
    flex-shrink: 0;
}
.iqty-btn:hover { background: #004d40; color: #fff; }
.iqty-num { font-weight: bold; min-width: 1.4em; text-align: center; font-size: 0.95em; }

/* インライン値引き */
.inline-neb select, .ci-neb {
    border: 1.5px solid #00897b; border-radius: 0.3em;
    padding: 0.15em 0.3em; font-size: 0.8em; max-width: 7em;
    color: #00897b; background: #fff;
}

/* 行削除ボタン */
.btn-del-row {
    border: none; background: none; color: #e53935;
    cursor: pointer; font-size: 1em; padding: 0 0.2em;
}

/* 合計行 */
.cart-total-row {
    display: flex; justify-content: flex-end;
    padding: 0.5em 0.8em;
    font-weight: bold; color: #c62828; font-size: 1em;
    border-top: 2px solid #004d40;
    background: #f8f8f8;
}

/* 新商品追加パネル */
.add-panel {
    background: #f0f4f0; border-radius: 0.5em;
    padding: 0.7em 0.8em; margin-bottom: 0.8em;
    border: 1px solid #c8d8c8;
}
.add-panel h4 { font-size: 0.85em; font-weight: bold; color: #004d40; margin: 0 0 0.5em; }
.add-row { display: flex; align-items: center; flex-wrap: wrap; gap: 0.4em; }
.item-select {
    border: 2px solid #004d40; border-radius: 0.4em;
    padding: 0.3em 0.5em; font-size: 0.85em;
    flex: 1; min-width: 140px; background: #fff; color: #333;
}
.add-qty-row { display: flex; align-items: center; gap: 0.25em; }
.aqty-btn {
    width: 1.6em; height: 1.6em; border-radius: 50%;
    border: 2px solid #004d40; background: #fff; color: #004d40;
    font-size: 0.9em; font-weight: bold; cursor: pointer;
    display: flex; align-items: center; justify-content: center;
}
.aqty-btn:hover { background: #004d40; color: #fff; }
.aqty-num { font-weight: bold; min-width: 1.4em; text-align: center; font-size: 0.95em; }

.neb-tabs { display: flex; gap: 0.2em; margin-bottom: 0.25em; }
.neb-tab {
    padding: 0.15em 0.55em; border-radius: 1em;
    border: 2px solid #00897b; background: #fff; color: #00897b;
    font-size: 0.78em; font-weight: bold; cursor: pointer;
}
.neb-tab.active { background: #00897b; color: #fff; }
.neb-sel {
    border: 2px solid #00897b; border-radius: 0.35em;
    padding: 0.2em 0.4em; font-size: 0.8em; max-width: 7em;
}
.btn-add-item {
    background: #004d40; color: #fff; border: none;
    border-radius: 0.4em; padding: 0.4em 0.9em;
    font-size: 0.85em; font-weight: bold; cursor: pointer; white-space: nowrap;
}
.btn-add-item:disabled { background: #bbb; cursor: not-allowed; }

/* アクションボタン */
.action-row { display: flex; gap: 0.5em; }
.btn-cancel {
    background: #fff; color: #555; border: 2px solid #ccc;
    border-radius: 0.5em; padding: 0.65em 1em;
    font-size: 0.9em; font-weight: bold; cursor: pointer;
    text-decoration: none; display: flex; align-items: center; white-space: nowrap;
}
.btn-register {
    flex: 1; background: #c62828; color: #fff; border: none;
    border-radius: 0.5em; padding: 0.65em;
    font-size: 1em; font-weight: bold; cursor: pointer;
}
.btn-register:hover { background: #b71c1c; }
.btn-register:disabled { background: #bbb; cursor: not-allowed; }
.cart-empty-msg { color: #aaa; font-size: 0.85em; padding: 0.8em; text-align: center; }

/* 全体削除ボタン */
.btn-delete-all {
    background: #fff; color: #e53935; border: 2px solid #e53935;
    border-radius: 0.5em; padding: 0.65em 1em;
    font-size: 0.9em; font-weight: bold; cursor: pointer;
    white-space: nowrap;
}
.btn-delete-all:hover { background: #e53935; color: #fff; }

/* トースト */
.toast-msg {
    position: fixed; top: 60px; left: 50%; transform: translateX(-50%);
    color: #fff; padding: 0.7em 1.5em; border-radius: 0.6em;
    font-size: 1em; font-weight: bold; box-shadow: 0 4px 16px rgba(0,0,0,.25);
    z-index: 20000; opacity: 0; transition: opacity .3s; pointer-events: none;
}
.toast-msg.ok  { background: #2e7d32; }
.toast-msg.err { background: #c62828; }
.toast-msg.show { opacity: 1; }

/* ===================== レシートオーバーレイ ===================== */
.receipt-overlay {
    display: none; position: fixed; inset: 0; z-index: 10000;
    background: #f0f4f0; overflow-y: auto; -webkit-overflow-scrolling: touch;
}
.receipt-overlay.show { display: block; }
.receipt-inner { max-width: 480px; margin: 0 auto; padding: 1em; }

.rcpt-card {
    background: #fff; border-radius: 0.6em;
    box-shadow: 0 4px 16px rgba(0,0,0,.12);
    padding: 1.2em 1em 1em; margin-bottom: 1em; position: relative;
}
.rcpt-card::before {
    content: ''; display: block; height: 14px;
    background: radial-gradient(circle at 8px -4px, transparent 10px, #f0f4f0 11px) top left / 16px 14px repeat-x;
    position: absolute; top: -12px; left: 0; right: 0;
}
.rcpt-card::after {
    content: ''; display: block; height: 14px;
    background: radial-gradient(circle at 8px 18px, transparent 10px, #f0f4f0 11px) bottom left / 16px 14px repeat-x;
    position: absolute; bottom: -12px; left: 0; right: 0;
}
.rcpt-header { text-align: center; border-bottom: 1px dashed #ccc; padding-bottom: 0.6em; margin-bottom: 0.6em; }
.rcpt-header .store { font-size: 1.1em; font-weight: bold; color: #004d40; }
.rcpt-header .date  { font-size: 0.7em; color: #888; margin-top: 0.15em; }
.rcpt-header .badge-ok {
    display: block;
    font-size: 1.1em; font-weight: bold; color: #222;
    margin-top: 0.4em;
}
.rcpt-not-receipt {
    font-size: 0.72em; color: #555;
    margin-top: 0.1em;
}
.rcpt-bumon-section { margin-bottom: 0.8em; }
.rcpt-bumon-title {
    font-weight: bold; font-size: 0.85em; color: #fff;
    padding: 0.25em 0.6em; border-radius: 0.3em;
    margin-bottom: 0.3em; display: inline-block;
}
.rcpt-bumon-title[data-bumon="魚"]    { background: #1565c0; }
.rcpt-bumon-title[data-bumon="天ぷら"] { background: #f9a825; color: #333; }
.rcpt-bumon-title[data-bumon="惣菜"]   { background: #2e7d32; }
.rcpt-bumon-title[data-bumon="唐揚"]   { background: #c62828; }
.rcpt-items { width: 100%; border-collapse: collapse; font-size: 0.8em; margin-bottom: 0.3em; }
.rcpt-items th { font-size: 0.75em; color: #888; font-weight: normal; padding: 0.15em 0.2em; border-bottom: 1px solid #eee; text-align: left; }
.rcpt-items th:nth-child(n+2) { text-align: right; }
.rcpt-items td { padding: 0.3em 0.2em; border-bottom: 1px solid #f5f5f5; vertical-align: top; }
.rcpt-items td:nth-child(n+2) { text-align: right; white-space: nowrap; }
.rcpt-items .nebiki-cell { color: #e53935; font-size: 0.9em; }
.rcpt-bumon-subtotal {
    display: flex; justify-content: space-between; align-items: center;
    border-top: 1.5px solid #ccc; padding-top: 0.3em;
    font-size: 0.85em; font-weight: bold; margin-bottom: 0.3em;
}
.rcpt-bumon-subtotal .amt { color: #c62828; font-size: 1.1em; }
.rcpt-bumon-code { text-align: center; font-size: 0.7em; color: #666; margin-top: 0.2em; }
.rcpt-bumon-code svg { display: block; margin: 0 auto; max-width: 260px; height: auto; }
.rcpt-grand-total {
    border-top: 2.5px solid #004d40; padding-top: 0.5em; margin-top: 0.3em;
    display: flex; justify-content: space-between; align-items: baseline;
}
.rcpt-grand-total .label { font-size: 0.9em; font-weight: bold; color: #555; }
.rcpt-grand-total .amount { font-size: 1.5em; font-weight: bold; color: #c62828; }
.rcpt-grand-total .tax { font-size: 0.6em; color: #888; display: block; text-align: right; }
.rcpt-footer-msg { text-align: center; margin-top: 0.6em; font-size: 0.65em; color: #aaa; border-top: 1px dashed #eee; padding-top: 0.4em; }
.rcpt-corp { text-align: center; font-size: 0.6em; color: #888; margin-top: 0.5em; padding-top: 0.4em; border-top: 1px dashed #ddd; line-height: 1.6; }
.rcpt-buttons { display: flex; gap: 0.6em; margin-top: 0.8em; }
.rcpt-btn-print {
    flex: 1; background: #1565c0; color: #fff; border: none;
    border-radius: 0.5em; padding: 0.7em; font-size: 1em; font-weight: bold; cursor: pointer;
}
.rcpt-btn-print:hover { background: #0d47a1; }
.rcpt-btn-next {
    flex: 1; background: #004d40; color: #fff; border: none;
    border-radius: 0.5em; padding: 0.7em; font-size: 1em; font-weight: bold; cursor: pointer;
}
.rcpt-btn-next:hover { background: #00695c; }

@media print {
    body > nav, .edit-wrap, .toast-msg, footer, .container, .rcpt-buttons { display: none !important; }
    body { margin: 0; padding: 0; background: #fff; font-size: 11px; }
    .receipt-overlay { display: block !important; position: static; background: #fff; overflow: visible; }
    .receipt-inner { max-width: 72mm; margin: 0; padding: 0; }
    .rcpt-card { box-shadow: none; border-radius: 0; padding: 2mm 1mm; }
    .rcpt-card::before, .rcpt-card::after { display: none; }
    .rcpt-bumon-title { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    .rcpt-bumon-code svg { max-width: 58mm; }
}
</style>

<!-- JsBarcode CDN -->
<script src="https://cdn.jsdelivr.net/npm/jsbarcode@3.11.6/dist/JsBarcode.all.min.js"></script>
<!-- StarWebPRNT 公式SDK -->
<script src="JS/StarWebPrintBuilder.js"></script>
<script src="JS/StarWebPrintTrader.js"></script>
<!-- 印刷ヘルパー（PHP inline で提供 = JSファイル不要） -->
<?php include __DIR__ . '/star_webprnt_inline.php'; ?>

<!-- トースト -->
<div class="toast-msg ok" id="toast-ok"></div>
<div class="toast-msg err" id="toast-err"></div>

<!-- レシートオーバーレイ -->
<div class="receipt-overlay" id="receipt-overlay">
  <div class="receipt-inner" id="receipt-inner"></div>
</div>

<div class="edit-wrap">

  <?php if ($error_message): ?>
    <div class="alert alert-danger py-2 mb-2" style="font-size:.85em;">
      <?= htmlspecialchars($error_message) ?>
    </div>
  <?php endif; ?>

  <div class="edit-badge">
    <div class="title">✏️ レシート No.<?= htmlspecialchars($receipt_no) ?> の訂正（<?= htmlspecialchars($date_jp) ?>）</div>
    <div class="caution">⚠️ 「訂正登録」を押すと元のレシートが削除されて再登録されます</div>
  </div>

  <!-- 訂正カート -->
  <div class="cart-wrap">
    <h3>訂正内容（元の内容を読み込み済み）</h3>
    <div id="cart-empty-msg" class="cart-empty-msg" style="display:none;">
      商品が0件です。↓から追加してください
    </div>
    <table class="cart-table" id="cart-table">
      <thead>
        <tr>
          <th>商品</th>
          <th style="text-align:center;">数量</th>
          <th>値引き</th>
          <th style="text-align:right;">金額</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="cart-tbody"></tbody>
    </table>
    <div class="cart-total-row">
      合計 <span id="cart-total" style="margin-left:.5em;">¥0</span>
    </div>
  </div>

  <!-- 新商品追加パネル -->
  <div class="add-panel">
    <h4>↓ 商品を追加</h4>
    <div class="add-row">
      <select id="item-select" class="item-select">
        <option value="">-- 商品を選択 --</option>
        <?php foreach ($all_products as $p): ?>
          <option value="<?= htmlspecialchars(json_encode([
            'name'  => $p['name'],
            'bumon' => $p['bumon'],
            'tani'  => $p['tani'],
            'price' => $p['price'],
          ]), ENT_QUOTES) ?>">
            <?= htmlspecialchars($p['bumon']) ?> <?= htmlspecialchars($p['name']) ?> ¥<?= number_format($p['price']) ?>
          </option>
        <?php endforeach; ?>
      </select>

      <div class="add-qty-row">
        <button class="aqty-btn" id="aqty-minus">－</button>
        <span class="aqty-num" id="aqty-num">1</span>
        <button class="aqty-btn" id="aqty-plus">＋</button>
      </div>

      <select id="add-sel-neb" class="ci-neb">
        <option value="0">値引なし</option>
        <?php foreach ($nebiki_ritsu_master as $r): ?>
          <option value="r<?= $r ?>"><?= $r ?>%引</option>
        <?php endforeach; ?>
        <?php foreach ($nebiki_gaku_master as $g): ?>
          <option value="g<?= $g ?>">¥<?= number_format($g) ?>引</option>
        <?php endforeach; ?>
      </select>

      <button class="btn-add-item" id="btn-add-item" disabled>＋ 追加</button>
    </div>
  </div>

  <!-- アクションボタン -->
  <div class="action-row">
    <a href="sales_list.php?date=<?= htmlspecialchars($date_raw) ?>" class="btn-cancel">
      キャンセル
    </a>
    <button type="button" class="btn-delete-all" id="btn-delete-all">
      🗑 全体削除
    </button>
    <button type="button" class="btn-register" id="btn-register" disabled>
      🔄 訂正登録する
    </button>
  </div>

</div>

<script>
// 元データをカートの初期値として設定
var cart = <?= json_encode($initial_cart, JSON_UNESCAPED_UNICODE) ?>;
var addQty = 1;

// 値引き統合オプション（sales_entry.php と同じ形式）
var nebikiOptions = [
    {label:'値引なし', ritsu:0, gaku:0},
    <?php foreach ($nebiki_ritsu_master as $r): ?>
    {label:'<?= $r ?>%引', ritsu:<?= $r ?>, gaku:0},
    <?php endforeach; ?>
    <?php foreach ($nebiki_gaku_master as $g): ?>
    {label:'¥<?= number_format($g) ?>引', ritsu:0, gaku:<?= $g ?>},
    <?php endforeach; ?>
];

// 初期データに nebikiIdx を付与
cart.forEach(function(item) {
    item.nebikiIdx = 0;
    for (var oi = 0; oi < nebikiOptions.length; oi++) {
        var opt = nebikiOptions[oi];
        if (opt.ritsu > 0 && opt.ritsu === item.nebiki_ritsu) { item.nebikiIdx = oi; break; }
        if (opt.gaku  > 0 && opt.gaku  === item.nebiki_gaku)  { item.nebikiIdx = oi; break; }
    }
});

// ---- 要素取得 ----
var elCartBody  = document.getElementById('cart-tbody');
var elCartTotal = document.getElementById('cart-total');
var elCartEmp   = document.getElementById('cart-empty-msg');
var elRegBtn    = document.getElementById('btn-register');
var elSelect    = document.getElementById('item-select');
var elAddBtn    = document.getElementById('btn-add-item');
var elAqtyNum   = document.getElementById('aqty-num');

// ---- カート描画 ----
function renderCart() {
    elCartBody.innerHTML = '';

    cart.forEach(function(item, i) {
        var tr = document.createElement('tr');

        // 統合値引きドロップダウン
        var nebOpts = '';
        nebikiOptions.forEach(function(opt, oi) {
            nebOpts += '<option value="' + oi + '"' + (oi === item.nebikiIdx ? ' selected' : '') + '>' + esc(opt.label) + '</option>';
        });

        tr.innerHTML =
            '<td>' + esc(item.name) + '<br><small style="color:#888;">' + esc(item.tani) + '</small></td>' +
            '<td class="td-qty"><div class="inline-qty">' +
              '<button type="button" class="iqty-btn" onclick="changeQty(' + i + ',-1)">－</button>' +
              '<span class="iqty-num">' + item.qty + '</span>' +
              '<button type="button" class="iqty-btn" onclick="changeQty(' + i + ',1)">＋</button>' +
            '</div></td>' +
            '<td class="td-neb"><select class="ci-neb" onchange="updateNeb(' + i + ',this.value)">' + nebOpts + '</select></td>' +
            '<td class="td-price">¥' + item.subtotal.toLocaleString() + '</td>' +
            '<td><button type="button" class="btn-del-row" onclick="delRow(' + i + ')">✕</button></td>';

        elCartBody.appendChild(tr);
    });

    var total = cart.reduce(function(s, it) { return s + it.subtotal; }, 0);
    elCartTotal.textContent = '¥' + total.toLocaleString();

    var has = cart.length > 0;
    elCartEmp.style.display = has ? 'none' : '';
    elRegBtn.disabled = !has;
}

// ---- 値引き変更（統合） ----
function updateNeb(i, val) {
    var ni  = parseInt(val, 10);
    var opt = nebikiOptions[ni];
    cart[i].nebikiIdx = ni;
    if (opt.ritsu > 0) {
        cart[i].nebiki_ritsu = opt.ritsu;
        cart[i].nebiki_gaku  = Math.round(cart[i].price * opt.ritsu / 100);
    } else {
        cart[i].nebiki_ritsu = 0;
        cart[i].nebiki_gaku  = opt.gaku;
    }
    recalc(i);
}

// ---- 小計再計算 ----
function recalc(i) {
    var item = cart[i];
    var unit = Math.max(0, item.price - item.nebiki_gaku);
    cart[i].subtotal = unit * item.qty;
    renderCart();
}

// ---- 行操作 ----
function changeQty(i, d) {
    cart[i].qty = Math.max(1, cart[i].qty + d);
    recalc(i);
}
function delRow(i) {
    cart.splice(i, 1);
    renderCart();
}

// ---- 新商品追加 ----
elSelect.addEventListener('change', function() {
    elAddBtn.disabled = (this.value === '');
});
document.getElementById('aqty-minus').addEventListener('click', function() {
    addQty = Math.max(1, addQty - 1);
    elAqtyNum.textContent = addQty;
});
document.getElementById('aqty-plus').addEventListener('click', function() {
    addQty++;
    elAqtyNum.textContent = addQty;
});
elAddBtn.addEventListener('click', function() {
    var val = elSelect.value;
    if (!val) return;
    var p = JSON.parse(val);

    // 統合ドロップダウンから値引きを取得
    var nebVal = document.getElementById('add-sel-neb').value;
    var nr = 0, ng = 0;
    if (nebVal.charAt(0) === 'r') {
        nr = parseFloat(nebVal.substring(1)) || 0;
    } else if (nebVal.charAt(0) === 'g') {
        ng = parseInt(nebVal.substring(1), 10) || 0;
    }
    var nGaku = ng > 0 ? ng : Math.round(p.price * nr / 100);
    var unit  = Math.max(0, p.price - nGaku);

    // nebikiIdx を特定
    var nebikiIdx = 0;
    for (var oi = 0; oi < nebikiOptions.length; oi++) {
        if (nebikiOptions[oi].ritsu === nr && nebikiOptions[oi].gaku === ng) { nebikiIdx = oi; break; }
    }

    cart.push({
        name: p.name, bumon: p.bumon, tani: p.tani, price: p.price,
        qty: addQty, nebiki_gaku: ng, nebiki_ritsu: nr,
        nebikiIdx: nebikiIdx, subtotal: unit * addQty
    });
    renderCart();
    // リセット
    addQty = 1; elAqtyNum.textContent = 1;
    document.getElementById('add-sel-neb').value = '0';
    elSelect.value = ''; elAddBtn.disabled = true;
});

// ---- 要素（追加分） ----
var elToastOk  = document.getElementById('toast-ok');
var elToastErr = document.getElementById('toast-err');
var elDelBtn   = document.getElementById('btn-delete-all');
var RECORD_IDS = <?= json_encode($record_ids) ?>;
var RECEIPT_NO = '<?= addslashes($receipt_no) ?>';
var DATE_RAW   = '<?= addslashes($date_raw) ?>';
// FM から取得したインストアコード（sales_entry.php と同方式）
var INSTORE_CODES = <?= json_encode($ic_dept, JSON_UNESCAPED_UNICODE) ?>;

// ---- トースト ----
function showToast(el, msg) {
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(function(){ el.classList.remove('show'); }, 3000);
}

// ---- JAN-13 チェックデジット ----
function jan13CheckDigit(digits12) {
    var sum = 0;
    for (var i = 0; i < 12; i++) sum += parseInt(digits12[i],10) * (i%2===0?1:3);
    return (10 - (sum%10)) % 10;
}

// ---- インストアコード生成: FM 7桁コード + 金額5桁 + CD1桁 = 13桁 ----
function makeInstoreCode(bumon, amount) {
    var prefix7 = INSTORE_CODES[bumon] || '';
    if (!prefix7) return null;
    var amt  = Math.min(Math.max(0, Math.round(amount)), 99999);
    var body = prefix7 + String(amt).padStart(5, '0');   // 7 + 5 = 12桁
    return body + jan13CheckDigit(body);                  // + CD = 13桁
}

// ---- 全体削除 ----
elDelBtn.addEventListener('click', async function() {
    if (!confirm('レシート No.' + RECEIPT_NO + ' を完全に削除しますか？\nこの操作は取り消せません。')) return;
    elDelBtn.disabled = true;
    elDelBtn.textContent = '削除中…';
    try {
        var res = await fetch('sales_edit.php?data=' + encodeURIComponent('<?= addslashes($raw) ?>'), {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest' },
            body: JSON.stringify({ action:'delete_all', record_ids: RECORD_IDS, receipt_no: RECEIPT_NO })
        });
        var data = await res.json();
        if (data.ok) {
            showToast(elToastOk, '✓ レシート No.' + RECEIPT_NO + ' を削除しました');
            setTimeout(function(){ location.href = 'sales_list.php?date=' + DATE_RAW; }, 1500);
        } else {
            showToast(elToastErr, '✕ ' + (data.error || '削除に失敗しました'));
            elDelBtn.disabled = false;
            elDelBtn.textContent = '🗑 全体削除';
        }
    } catch(err) {
        showToast(elToastErr, '✕ 通信エラー: ' + err.message);
        elDelBtn.disabled = false;
        elDelBtn.textContent = '🗑 全体削除';
    }
});

// ---- 訂正登録（AJAX） ----
elRegBtn.addEventListener('click', async function() {
    if (!cart.length) return;
    elRegBtn.disabled = true;
    elRegBtn.textContent = '送信中…';
    try {
        var res = await fetch('sales_edit.php?data=' + encodeURIComponent('<?= addslashes($raw) ?>'), {
            method: 'POST',
            headers: { 'Content-Type':'application/json', 'X-Requested-With':'XMLHttpRequest' },
            body: JSON.stringify({ action:'update', cart_items: cart, record_ids: RECORD_IDS })
        });
        var data = await res.json();
        if (data.ok) {
            showReceipt(cart, data.receipt_no, data.total, data.count);
        } else {
            showToast(elToastErr, '✕ ' + (data.error || '訂正に失敗しました'));
        }
    } catch(err) {
        showToast(elToastErr, '✕ 通信エラー: ' + err.message);
    }
    elRegBtn.textContent = '🔄 訂正登録する';
    elRegBtn.disabled = cart.length === 0;
});

// ---- レシート表示 ----
function showReceipt(items, receiptNo, grandTotal, count) {
    var overlay = document.getElementById('receipt-overlay');
    var inner   = document.getElementById('receipt-inner');

    var groups = {};
    var order  = [];
    items.forEach(function(it) {
        var b = it.bumon || 'その他';
        if (!groups[b]) { groups[b] = []; order.push(b); }
        groups[b].push(it);
    });
    var catOrder = <?= json_encode($category_order, JSON_UNESCAPED_UNICODE) ?>;
    order.sort(function(a,b) {
        var ia = catOrder.indexOf(a); if (ia<0) ia=99;
        var ib = catOrder.indexOf(b); if (ib<0) ib=99;
        return ia-ib;
    });

    var now = new Date();
    var dateStr = now.getFullYear()+'年'+(now.getMonth()+1)+'月'+now.getDate()+'日 '
                + String(now.getHours()).padStart(2,'0')+':'+String(now.getMinutes()).padStart(2,'0');

    var html = '<div class="rcpt-card">';
    html += '<div class="rcpt-header">';
    html += '<div class="store">🐟 <?= htmlspecialchars($store_name) ?></div>';
    html += '<div class="date">' + esc(dateStr) + '　No.' + esc(receiptNo) + '（訂正）</div>';
    html += '<div class="badge-ok">明細書</div>';
    html += '<div class="rcpt-not-receipt">領収書ではありません</div>';
    html += '</div>';

    order.forEach(function(bumon) {
        var bItems = groups[bumon];
        var bSubtotal = bItems.reduce(function(s,it){ return s+it.subtotal; },0);
        var code = makeInstoreCode(bumon, bSubtotal);

        html += '<div class="rcpt-bumon-section">';
        html += '<div class="rcpt-bumon-title" data-bumon="'+esc(bumon)+'">'+esc(bumon)+'</div>';
        html += '<table class="rcpt-items"><thead><tr><th>商品名</th><th>単価</th><th>数量</th><th>値引額</th><th>値引詳細</th><th>請求小計</th></tr></thead><tbody>';
        bItems.forEach(function(it) {
            var nebDetail = '-';
            var nebAmt = 0;
            if (it.nebiki_ritsu>0) {
                nebDetail = it.nebiki_ritsu+'%引';
                nebAmt = it.nebiki_gaku || Math.round(it.price*it.nebiki_ritsu/100);
            } else if (it.nebiki_gaku>0) {
                nebDetail = '¥'+it.nebiki_gaku.toLocaleString()+'引';
                nebAmt = it.nebiki_gaku;
            }
            html += '<tr><td>'+esc(it.name)+'</td><td>¥'+it.price.toLocaleString()+'</td><td>'+it.qty+'</td>';
            html += '<td class="nebiki-cell">'+(nebAmt>0?'¥'+nebAmt.toLocaleString():'-')+'</td>';
            html += '<td class="nebiki-cell">'+nebDetail+'</td><td>¥'+it.subtotal.toLocaleString()+'</td></tr>';
        });
        html += '</tbody></table>';
        html += '<div class="rcpt-bumon-subtotal"><span>'+esc(bumon)+' 小計</span><span class="amt">¥'+bSubtotal.toLocaleString()+'</span></div>';
        if (code) {
            html += '<div class="rcpt-bumon-code"><svg id="bc-'+esc(bumon)+'"></svg><span>'+code+'</span></div>';
        }
        html += '</div>';
    });

    html += '<div class="rcpt-grand-total"><span class="label">合　計</span><div>';
    html += '<span class="amount">¥'+grandTotal.toLocaleString()+'</span>';
    html += '<span class="tax">（本体価格）</span></div></div>';
    html += '<div class="rcpt-footer-msg">ありがとうございました</div>';
    html += '<div class="rcpt-corp">';
    html += '<div style="font-weight:bold;">株式会社富惣</div>';
    html += '<div>大阪府堺市堺区遠里小野町３丁４番１号</div>';
    html += '<div>TEL 072-229-8800 / FAX 072-229-4700</div>';
    html += '<div>お問い合せ窓口 0120-014-868</div>';
    html += '</div></div>';

    /* StarWebPRNT 用にレシートデータをセット */
    var _barCodes = {};
    order.forEach(function(bumon) {
        var bItems = groups[bumon];
        var bSub   = bItems.reduce(function(s,it){ return s + it.subtotal; }, 0);
        _barCodes[bumon] = makeInstoreCode(bumon, bSub);
    });
    _starReceiptData = {
        storeName : '<?= htmlspecialchars($store_name, ENT_QUOTES) ?>',
        dateStr   : dateStr,
        receiptNo : String(receiptNo),
        groups    : groups,
        catOrder  : order,
        grandTotal: grandTotal,
        isKakunin : true,
        barCodes  : _barCodes,
        count     : count
    };

    html += '<div class="rcpt-buttons">';
    html += '<button class="rcpt-btn-print" id="rcpt-print-btn">🖨 印刷</button>';
    html += '<button class="rcpt-btn-next" id="rcpt-close">売上一覧へ →</button>';
    html += '</div>';

    inner.innerHTML = html;
    overlay.classList.add('show');

    order.forEach(function(bumon) {
        var bItems = groups[bumon];
        var bSubtotal = bItems.reduce(function(s,it){ return s+it.subtotal; },0);
        var code = makeInstoreCode(bumon, bSubtotal);
        if (!code) return;
        try {
            JsBarcode('#bc-'+CSS.escape(bumon), code, {
                format:'EAN13', width:2, height:50, displayValue:false, margin:4
            });
        } catch(e) { console.warn('Barcode error:', e); }
    });

    /* 印刷ボタン */
    document.getElementById('rcpt-print-btn').addEventListener('click', function() {
        starPrintReceipt(this);
    });

    document.getElementById('rcpt-close').addEventListener('click', function() {
        location.href = 'sales_list.php?date=' + DATE_RAW + '&edited=' + RECEIPT_NO;
    });
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

// 初期描画
renderCart();
</script>

<?php include __DIR__ . '/footer.php'; ?>
