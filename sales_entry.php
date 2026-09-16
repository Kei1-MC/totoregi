<?php
/**
 * sales_entry.php  レシート番号対応版
 * 登録フロー:
 *   1. sales_header_API に createRecord → recordId をレシート番号に使用
 *   2. 各明細を pos_API に createRecord（レシート番号を付与）
 *   3. sales_confirm.php へリダイレクト
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
require_once __DIR__ . '/instore_codes.php';

$store_id   = $_SESSION['store_id'];
$store_name = $_SESSION['store_name'];
$today      = date('m/d/Y');

// =====================================================================
// インストアコード 店舗別設定
// 優先順位: ① FM アカウントマスタ（account_API）→ ② instore_codes.php（暫定）
// FM に「インストアコード_*」フィールドが設定されたら①が自動的に有効になる
//
// フォーマット: 店舗部門コード(7桁) + 金額(5桁) + CD(1桁) = 13桁
// 7桁コードの例: 魚=2004355, 天ぷら=2004354 など
// フィールド型: テキスト（バーコード生成で文字列連結するため）
//
// 部門定義は bumon_API（部門マスタ）から取得する（bumon_master.php）
// =====================================================================

// 部門マスタ（並び順昇順）
$bumon_master   = fetch_bumon_master($host, $db, $layout_bumon, $api_master_user, $api_master_pass);
$category_order = bumon_names($bumon_master);

/** FM account_API からインストアコード7桁を取得 */
function _fetch_instore_codes_from_fm(string $store_id, string $host, string $db,
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
        return [];   // FM 取得失敗時は空配列 → フォールバックへ
    }
}

// FM から取得（フィールド未設定店舗は空配列が返る）
$ic_dept_fm = _fetch_instore_codes_from_fm(
    $store_id, $host, $db, $layout_account, $api_master_user, $api_master_pass,
    bumon_ic_field_map($bumon_master)
);

// フォールバック: instore_codes.php の静的設定（FM 移行完了後は削除可）
$_ic_static = $instore_config[$store_id] ?? [];
$_ic_static_dept = $_ic_static['dept'] ?? [];
// 静的設定は旧フォーマット(3桁)なので7桁に変換して利用
// ※ FM 設定が揃い次第このブロックは不要になる
if (empty($ic_dept_fm) && !empty($_ic_static_dept)) {
    $pfx = $_ic_static['prefix'] ?? '00';
    $ic_dept = [];
    foreach ($_ic_static_dept as $cat => $code3) {
        $ic_dept[$cat] = '20' . $pfx . $code3;   // 2 + 2 + 3 = 7桁
    }
} else {
    $ic_dept = $ic_dept_fm;   // FM 設定を優先
}

$nebiki_ritsu_master = [10, 20, 30, 50];
$nebiki_gaku_master  = [50, 100, 200, 300];

$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // AJAX の場合は JSON body を読む
    if ($is_ajax) {
        $json_body = json_decode(file_get_contents('php://input'), true) ?? [];
        $items = $json_body['cart_items'] ?? [];
    } else {
        $items = json_decode($_POST['cart_items'] ?? '[]', true) ?? [];
    }

    if (empty($items)) {
        if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'カートに商品がありません。']); exit(); }
        $error_message = 'カートに商品がありません。';
    } else {
        $total = array_reduce($items, fn($s, $it) => $s + $it['subtotal'], 0);

        // ① sales_header_API にヘッダーを1件作成
        $fmHeader = new fmRESTor($host, $db, 'sales_header_API',
                                  $api_master_user, $api_master_pass, ['allowInsecure' => true]);
        $headerResult = $fmHeader->createRecord(['fieldData' => [
            '店舗No'  => $store_id,
            '店舗名'  => $store_name,
            '合計金額' => $total,
        ]]);

        if ($fmHeader->isError($headerResult)) {
            if ($is_ajax) { header('Content-Type: application/json'); echo json_encode(['ok'=>false,'error'=>'レシート番号の発番に失敗しました。']); exit(); }
            $error_message = 'レシート番号の発番に失敗しました。';
        } else {
            // ② recordId をレシート番号として取得
            $receipt_no = $headerResult['result']['response']['recordId'] ?? '';

            // ③ 各明細を pos_API に登録
            $fmPos = new fmRESTor($host, $db, $layout_pos,
                                   $api_master_user, $api_master_pass, ['allowInsecure' => true]);
            $fail = 0;
            foreach ($items as $item) {
                $honbai     = (int)($item['price']        ?? 0);
                $suryo      = (int)($item['qty']          ?? 1);
                $neb_gaku   = (int)($item['nebiki_gaku']  ?? 0);
                $neb_ritsu  = (float)($item['nebiki_ritsu'] ?? 0);
                $hanbai_kin = ($honbai - $neb_gaku) * $suryo;
                $result = $fmPos->createRecord(['fieldData' => [
                    '販売日時'   => $today,
                    '店舗No'    => $store_id,
                    '商品名'    => $item['name']  ?? '',
                    '部門'      => $item['bumon'] ?? '',
                    '販売単位'  => $item['tani']  ?? '',
                    '本体価格'  => $honbai,
                    '数量'      => $suryo,
                    '値引額'    => $neb_gaku,
                    '値引率'    => $neb_ritsu,
                    '販売金額'  => $hanbai_kin,
                    '明細金額'  => $hanbai_kin,
                    'レシート番号' => $receipt_no,
                ]]);
                if ($fmPos->isError($result)) $fail++;
            }

            if ($is_ajax) {
                header('Content-Type: application/json');
                if ($fail === 0) {
                    echo json_encode(['ok'=>true,'receipt_no'=>$receipt_no,'total'=>$total,'count'=>count($items)]);
                } else {
                    echo json_encode(['ok'=>false,'error'=>count($items).'件中 '.$fail.'件の明細登録に失敗しました。']);
                }
                exit();
            }

            if ($fail === 0) {
                $_SESSION['last_sale'] = [
                    'items'       => $items,
                    'store_name'  => $store_name,
                    'date'        => date('Y年m月d日 H:i'),
                    'receipt_no'  => $receipt_no,
                    'total'       => $total,
                ];
                header('Location: sales_confirm.php');
                exit();
            } else {
                $error_message = count($items) . '件中 ' . $fail . '件の明細登録に失敗しました。';
            }
        }
    }
}

// --- カテゴリーマッピング（FM 部門名 → 表示カテゴリー名） ---
// キー = FileMaker 部門名 → 値 = 表示カテゴリー名
$category_map = [
    '焼魚'    => '魚',
    '煮魚'    => '魚',
    '天ぷら'  => '天ぷら',
    '弁当'    => '惣菜',
    '冷惣菜'  => '惣菜',
    '真空商品' => '惣菜',
    'いか焼き' => 'イカ焼',
    'いか焼'  => 'イカ焼',
    '南蛮漬'  => '惣菜',
    '唐揚'    => '唐揚',
];
// 表示順は bumon_master.php で取得済みの $category_order（bumon_API 並び順）を使用

$fm2  = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
// 全件取得 → PHP で自店舗・発売中 を絞り込む（shohin_maint.php と同方式）
$res2 = $fm2->getRecords(['_limit' => 1000]);
$products   = [];
$bumon_list = [];
foreach ($res2['result']['response']['data'] ?? [] as $row) {
    $f = $row['fieldData'];
    $n = trim($f['商品名']   ?? '');
    if ($n === '') continue;

    // ① 発売中=1 のみ
    if ((int)($f['発売中'] ?? 0) !== 1) continue;

    // ② 自店舗が取扱店舗に含まれているか
    $positions = getStorePositions($f);
    if (!in_array($store_id, $positions, true)) continue;

    $b = trim($f['部門']     ?? '');
    $y = trim($f['よみがな'] ?? '');

    // 部門名をカテゴリーにマッピング
    $cat = $category_map[$b] ?? $b;

    // 本体価格：店舗別に設定されていればそれを使用、なければ本部設定の本体価格
    $store_honbai = getStoreHonbaiPrice($f, $store_id);
    $base_price   = ($store_honbai > 0) ? $store_honbai : (int)($f['本体価格'] ?? 0);
    // セール価格が設定されていればさらに優先
    $sale_price = getStoreSalePrice($f, $store_id);
    $disp_price = ($sale_price > 0) ? $sale_price : $base_price;

    $products[] = [
        'bumon'      => $cat,
        'bumon_orig' => $b,
        'name'       => $n,
        'yomi'       => $y,
        'tani'       => trim($f['販売単位'] ?? ''),
        'price'      => $disp_price,
        'sale'       => (int)($f['セール']  ?? 0),
    ];
}

// カテゴリー順 → よみがな順でソート
$cat_pos = array_flip($category_order);
usort($products, function($a, $b) use ($cat_pos) {
    $pa = $cat_pos[$a['bumon']] ?? 999;
    $pb = $cat_pos[$b['bumon']] ?? 999;
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['yomi'], $b['yomi']);
});

// 表示カテゴリーリスト（定義順を維持、存在するもののみ）
$active_cats = [];
foreach ($products as $p) {
    if ($p['bumon'] !== '' && !in_array($p['bumon'], $active_cats, true)) {
        $active_cats[] = $p['bumon'];
    }
}
// $category_order の順に並べ直し、未定義カテゴリーは末尾に追加
$bumon_list = [];
foreach ($category_order as $c) {
    if (in_array($c, $active_cats, true)) $bumon_list[] = $c;
}
foreach ($active_cats as $c) {
    if (!in_array($c, $bumon_list, true)) $bumon_list[] = $c;
}

include __DIR__ . '/header.php';
// header.phpの <div class="container mt-4"> を閉じ、余白をリセット
echo '</div>';
?>
<style>
/* === レイアウト === */
body > nav + div { margin-top: 0 !important; padding-top: 0 !important; }
:root { --pos-font-size: 16px; }
body { overflow: hidden; margin: 0; padding: 0; }
.pos-wrap {
    font-size: var(--pos-font-size);
    display: grid;
    grid-template-columns: 1fr;
    grid-template-rows: auto 1fr auto;  /* タブ → 商品 → カート */
    height: calc(100vh - 48px);         /* フォールバック: dvh未対応の古いWebView/Chrome向け */
    height: calc(100dvh - 48px);        /* dvh: ブラウザナビバーを考慮した実際の高さ（対応ブラウザで上書き）2026/08/06 */
    overflow: hidden;
    margin-top: 0 !important;
}

/* === カテゴリータブ === */
.pos-bumon {
    padding: 0.4em 0.75em !important;
    background: #f0f4f0 !important;
    border-bottom: 1px solid #c8d8c8;
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
    border: 2px solid #004d40;
    border-radius: 2em;
    background: #fff;
    color: #004d40;
    font-size: 1em;
    font-weight: bold;
    cursor: pointer;
    transition: all .15s;
    flex-shrink: 0;
    white-space: nowrap;
}
.bumon-btn.active { background: #004d40; color: #fff; }
.bumon-btn[data-bumon="魚"]            { border-color: #1565c0; color: #1565c0; }
.bumon-btn[data-bumon="魚"].active     { background: #1565c0; color: #fff; border-color: #1565c0; }
.bumon-btn[data-bumon="天ぷら"]         { border-color: #f9a825; color: #f57f17; }
.bumon-btn[data-bumon="天ぷら"].active  { background: #f9a825; color: #fff; border-color: #f9a825; }
.bumon-btn[data-bumon="惣菜"]          { border-color: #2e7d32; color: #2e7d32; }
.bumon-btn[data-bumon="惣菜"].active   { background: #2e7d32; color: #fff; border-color: #2e7d32; }
.bumon-btn[data-bumon="唐揚"]          { border-color: #c62828; color: #c62828; }
.bumon-btn[data-bumon="唐揚"].active   { background: #c62828; color: #fff; border-color: #c62828; }

/* === 商品グリッド === */
.pos-shohin {
    overflow-y: auto;
    padding: 0.3em 0.5em;
    background: #fafafa;
    display: grid !important;
    grid-template-columns: repeat(3, 1fr);
    gap: 0.3em;
    align-content: start;
}
.shohin-item {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: 0.4em 0.3em;
    border: 2px solid #ddd;
    border-radius: 0.5em;
    background: #fff;
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
.shohin-item:hover  { filter: brightness(0.93); }
/* タップ時の一瞬フラッシュ */
.shohin-item.flash  { transform: scale(0.92); filter: brightness(0.8); }
.shohin-item .s-name { font-weight: bold; font-size: 0.95em; }
.shohin-item .s-price { font-weight: bold; color: #c62828; font-size: 0.85em; margin-top: 0.1em; }
.shohin-item .s-sale {
    display: inline-block; background: #c62828; color: #fff;
    font-size: 0.68em; font-weight: bold; padding: 1px 5px;
    border-radius: 0.3em; margin-left: 0.3em; vertical-align: middle;
    letter-spacing: 0.03em;
}
.shohin-item .s-tani { display: none !important; }
.shohin-item .s-sub  { display: none !important; }
/* カテゴリー別カラー */
.shohin-item[data-bumon="魚"]    { background: #e3f2fd; border-color: #90caf9; }
.shohin-item[data-bumon="天ぷら"] { background: #fff8e1; border-color: #ffe082; }
.shohin-item[data-bumon="惣菜"]   { background: #e8f5e9; border-color: #a5d6a7; }
.shohin-item[data-bumon="唐揚"]   { background: #fce4ec; border-color: #f48fb1; }

/* === カートエリア（画面下部固定） === */
.pos-cart {
    background: #fff;
    border-top: 2px solid #004d40;
    box-shadow: 0 -2px 8px rgba(0,0,0,.1);
    display: flex;
    flex-direction: column;
    max-height: 40vh;
    overflow: hidden;
}
.cart-items {
    overflow-y: auto;
    padding: 0.3em 0.5em;
    flex: 1;
    min-height: 0;
}
.cart-empty {
    text-align: center;
    color: #aaa;
    font-size: 0.85em;
    padding: 0.6em;
}
/* カート各行 */
.ci-row {
    display: flex;
    align-items: center;
    gap: 0.3em;
    padding: 0.25em 0;
    border-bottom: 1px solid #f0f0f0;
    font-size: 0.85em;
}
.ci-row:last-child { border-bottom: none; }
.ci-name {
    flex: 1;
    min-width: 0;
    font-weight: bold;
    overflow: hidden;
    white-space: nowrap;
    text-overflow: ellipsis;
}
.ci-qty {
    display: flex;
    align-items: center;
    gap: 0.2em;
}
.ci-qty-btn {
    width: 1.6em; height: 1.6em;
    border-radius: 50%;
    border: 1.5px solid #004d40;
    background: #fff;
    color: #004d40;
    font-size: 0.9em;
    font-weight: bold;
    cursor: pointer;
    display: flex;
    align-items: center;
    justify-content: center;
    line-height: 1;
    padding: 0;
}
.ci-qty-btn:hover { background: #004d40; color: #fff; }
.ci-qty-num {
    font-weight: bold;
    min-width: 1.3em;
    text-align: center;
    font-size: 1em;
}
.ci-neb {
    border: 1.5px solid #00897b;
    border-radius: 0.3em;
    padding: 0.1em 0.3em;
    font-size: 0.8em;
    min-width: 5em;
    color: #00897b;
    background: #fff;
}
.ci-subtotal {
    font-weight: bold;
    color: #c62828;
    white-space: nowrap;
    min-width: 3.5em;
    text-align: right;
}
.ci-del {
    border: none;
    background: #e53935;
    color: #fff;
    cursor: pointer;
    font-size: 0.78em;
    font-weight: bold;
    padding: 0.3em 0.55em;
    border-radius: 0.3em;
    white-space: nowrap;
    flex-shrink: 0;
    min-height: 2.2em;
    transition: background .1s;
    line-height: 1.2;
}
.ci-del:hover, .ci-del:active { background: #b71c1c; }
/* 削除アニメーション */
.ci-row.removing {
    animation: rowRemove .25s ease-out forwards;
}
@keyframes rowRemove {
    0%   { background: #ffebee; opacity: 1; }
    100% { background: #ffebee; opacity: 0; max-height: 0; padding: 0; }
}
/* 全消去ボタン */
.clear-cart-btn {
    border: none;
    background: none;
    color: #e53935;
    font-size: 0.78em;
    font-weight: bold;
    cursor: pointer;
    padding: 0.2em 0.5em;
    border-radius: 0.3em;
    border: 1.5px solid #e53935;
    white-space: nowrap;
    transition: all .1s;
    -webkit-touch-callout: none;
    -webkit-user-select: none;
    user-select: none;
}
.clear-cart-btn:hover { background: #e53935; color: #fff; }
/* カートフッター */
.cart-footer {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.4em 0.6em;
    padding-bottom: calc(0.4em + env(safe-area-inset-bottom, 0px));  /* iOS セーフエリア対応 */
    border-top: 1px solid #e0e0e0;
    background: #f8f8f8;
}
.cart-total-label { font-size: 0.85em; color: #555; }
.cart-total-amount { font-size: 1.3em; font-weight: bold; color: #c62828; }
.register-btn {
    background: #004d40;
    color: #fff;
    border: none;
    border-radius: 0.5em;
    padding: 0.5em 1.2em;
    font-size: 1em;
    font-weight: bold;
    cursor: pointer;
    white-space: nowrap;
    transition: background .15s;
    -webkit-touch-callout: none;
    -webkit-user-select: none;
    user-select: none;
}
.register-btn:hover { background: #00695c; }
.register-btn:disabled { background: #bbb; cursor: not-allowed; }
.register-btn.loading { background: #666; pointer-events: none; }

/* === トースト通知 === */
.toast-ok {
    position: fixed;
    top: 60px; left: 50%; transform: translateX(-50%);
    background: #2e7d32;
    color: #fff;
    padding: 0.7em 1.5em;
    border-radius: 0.6em;
    font-size: 1.1em;
    font-weight: bold;
    box-shadow: 0 4px 16px rgba(0,0,0,.25);
    z-index: 9999;
    opacity: 0;
    transition: opacity .3s;
    pointer-events: none;
}
.toast-ok.show { opacity: 1; }
.toast-err {
    position: fixed;
    top: 60px; left: 50%; transform: translateX(-50%);
    background: #c62828;
    color: #fff;
    padding: 0.7em 1.5em;
    border-radius: 0.6em;
    font-size: 1em;
    font-weight: bold;
    box-shadow: 0 4px 16px rgba(0,0,0,.25);
    z-index: 9999;
    opacity: 0;
    transition: opacity .3s;
    pointer-events: none;
}
.toast-err.show { opacity: 1; }

/* ===================== レシートオーバーレイ ===================== */
.receipt-overlay {
    display: none;
    position: fixed;
    inset: 0;
    z-index: 10000;
    background: #f0f4f0;
    overflow-y: auto;
    -webkit-overflow-scrolling: touch;
}
.receipt-overlay.show { display: block; }
.receipt-inner {
    max-width: 480px;
    margin: 0 auto;
    padding: 1em;
}

/* レシートカード */
.rcpt-card {
    background: #fff;
    border-radius: 0.6em;
    box-shadow: 0 4px 16px rgba(0,0,0,.12);
    padding: 1.2em 1em 1em;
    margin-bottom: 1em;
    position: relative;
}
.rcpt-card::before {
    content: '';
    display: block;
    height: 14px;
    background: radial-gradient(circle at 8px -4px, transparent 10px, #f0f4f0 11px) top left / 16px 14px repeat-x;
    position: absolute;
    top: -12px; left: 0; right: 0;
}
.rcpt-card::after {
    content: '';
    display: block;
    height: 14px;
    background: radial-gradient(circle at 8px 18px, transparent 10px, #f0f4f0 11px) bottom left / 16px 14px repeat-x;
    position: absolute;
    bottom: -12px; left: 0; right: 0;
}
.rcpt-header {
    text-align: center;
    border-bottom: 1px dashed #ccc;
    padding-bottom: 0.6em;
    margin-bottom: 0.6em;
}
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

/* 部門セクション */
.rcpt-bumon-section { margin-bottom: 0.8em; }
.rcpt-bumon-title {
    font-weight: bold; font-size: 0.85em;
    color: #fff; padding: 0.25em 0.6em;
    border-radius: 0.3em; margin-bottom: 0.3em;
    display: inline-block;
}
.rcpt-bumon-title[data-bumon="魚"]    { background: #1565c0; }
.rcpt-bumon-title[data-bumon="天ぷら"] { background: #f9a825; color: #333; }
.rcpt-bumon-title[data-bumon="惣菜"]   { background: #2e7d32; }
.rcpt-bumon-title[data-bumon="唐揚"]   { background: #c62828; }

.rcpt-items {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.8em;
    margin-bottom: 0.3em;
}
.rcpt-items th {
    font-size: 0.75em; color: #888; font-weight: normal;
    padding: 0.15em 0.2em; border-bottom: 1px solid #eee; text-align: left;
}
.rcpt-items th:nth-child(n+2) { text-align: right; }
.rcpt-items td {
    padding: 0.3em 0.2em;
    border-bottom: 1px solid #f5f5f5;
    vertical-align: top;
}
.rcpt-items td:nth-child(n+2) { text-align: right; white-space: nowrap; }
.rcpt-items .nebiki-cell { color: #e53935; font-size: 0.9em; }

.rcpt-bumon-subtotal {
    display: flex; justify-content: space-between; align-items: center;
    border-top: 1.5px solid #ccc; padding-top: 0.3em;
    font-size: 0.85em; font-weight: bold; margin-bottom: 0.3em;
}
.rcpt-bumon-subtotal .amt { color: #c62828; font-size: 1.1em; }
.rcpt-bumon-code {
    text-align: center; font-size: 0.7em; color: #666;
    margin-top: 0.2em;
}
.rcpt-bumon-code svg { display: block; margin: 0 auto; max-width: 260px; height: auto; }

/* 合計 */
.rcpt-grand-total {
    border-top: 2.5px solid #004d40;
    padding-top: 0.5em; margin-top: 0.3em;
    display: flex; justify-content: space-between; align-items: baseline;
}
.rcpt-grand-total .label { font-size: 0.9em; font-weight: bold; color: #555; }
.rcpt-grand-total .amount { font-size: 1.5em; font-weight: bold; color: #c62828; }
.rcpt-grand-total .tax { font-size: 0.6em; color: #888; display: block; text-align: right; }

.rcpt-footer-msg {
    text-align: center; margin-top: 0.6em;
    font-size: 0.65em; color: #aaa;
    border-top: 1px dashed #eee; padding-top: 0.4em;
}
.rcpt-corp {
    text-align: center; font-size: 0.6em; color: #888;
    margin-top: 0.5em; padding-top: 0.4em;
    border-top: 1px dashed #ddd; line-height: 1.6;
}

/* ボタンエリア */
.rcpt-buttons {
    display: flex; gap: 0.6em; margin-top: 0.8em;
}
.rcpt-btn-print {
    flex: 1; background: #1565c0; color: #fff; border: none;
    border-radius: 0.5em; padding: 0.7em; font-size: 1em;
    font-weight: bold; cursor: pointer;
}
.rcpt-btn-print:hover { background: #0d47a1; }

/* ===================== タブレット向け最適化（768px以上） ===================== */
/* スマホでは使用しない想定。縦1列・カート下固定レイアウトを維持しつつ拡大。 */
@media (min-width: 768px) {

    /* 商品グリッド：4列に */
    .pos-shohin {
        grid-template-columns: repeat(4, 1fr);
    }

    /* カートエリアを広く（50vh） */
    .pos-cart {
        max-height: 52vh;
    }

    /* 商品ボタンを大きく */
    .shohin-item {
        min-height: 4.2em;
        padding: 0.55em 0.3em;
        font-size: 1em;
    }
    .shohin-item .s-name  { font-size: 1.0em; }
    .shohin-item .s-price { font-size: 0.92em; }

    /* カート各行：1行目に商品名、2行目にボタン群 */
    .ci-row {
        flex-wrap: wrap;
        padding: 0.55em 0;
        gap: 0.45em;
    }
    /* 商品名：1行目・最大2行表示 */
    .ci-name {
        flex: none;
        width: 100%;
        white-space: normal;
        overflow: hidden;
        display: -webkit-box;
        -webkit-line-clamp: 2;
        -webkit-box-orient: vertical;
        font-size: 1.05em;
        line-height: 1.35;
    }
    /* ＋/－ ボタン */
    .ci-qty-btn {
        width: 2.9em;
        height: 2.9em;
        font-size: 1.15em;
    }
    .ci-qty-num {
        font-size: 1.2em;
        min-width: 2em;
    }
    /* 値引セレクト */
    .ci-neb {
        font-size: 1em;
        padding: 0.3em 0.5em;
        height: 2.9em;
        min-width: 5.8em;
    }
    /* 小計 */
    .ci-subtotal {
        font-size: 1.08em;
        min-width: 4.5em;
    }
    /* 削除ボタン */
    .ci-del {
        font-size: 0.9em;
        padding: 0.55em 1.0em;
        min-height: 2.9em;
    }
    /* 全消去ボタン */
    .clear-cart-btn {
        font-size: 0.9em;
        padding: 0.35em 0.7em;
    }
    /* 合計金額 */
    .cart-total-amount {
        font-size: 1.7em;
    }
    /* 登録ボタン */
    .register-btn {
        font-size: 1.15em;
        padding: 0.65em 1.8em;
    }
}

/* カートパネルヘッダーは非表示（縦1列レイアウトでは不要） */
.cart-panel-header { display: none; }

/* ===================== 横向きタブレット：左に商品・右にカート（全高） ===================== */
@media (orientation: landscape) {
    .pos-wrap {
        grid-template-columns: 1.7fr 1fr;
        grid-template-rows: auto 1fr;
        grid-template-areas:
            "tabs   cart"
            "shohin cart";
    }
    .pos-bumon  { grid-area: tabs; }
    .pos-shohin { grid-area: shohin; }
    .pos-cart {
        grid-area: cart;
        height: 100%;
        max-height: none;
        border-top: none;
        border-left: 2px solid #004d40;
        box-shadow: -2px 0 8px rgba(0,0,0,.1);
    }
    .cart-panel-header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 0.6em 0.8em;
        background: #004d40;
        color: #fff;
        font-weight: bold;
        font-size: 0.95em;
    }
}

/* ===================== 印刷用 CSS ===================== */
@media print {
    /* POS画面・ナビ・フッター非表示 */
    body > nav, .pos-wrap, .toast-ok, .toast-err,
    footer, .container, .rcpt-buttons { display: none !important; }

    body { margin: 0; padding: 0; background: #fff; font-size: 11px; }
    .receipt-overlay {
        display: block !important;
        position: static;
        background: #fff;
        overflow: visible;
    }
    .receipt-inner { max-width: 72mm; margin: 0; padding: 0; }
    .rcpt-card {
        box-shadow: none; border-radius: 0; padding: 2mm 1mm;
    }
    .rcpt-card::before, .rcpt-card::after { display: none; }
    .rcpt-bumon-title { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
    .rcpt-bumon-code svg { max-width: 58mm; }
    .rcpt-grand-total .amount { font-size: 1.3em; }
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
<div class="toast-ok" id="toast-ok"></div>
<div class="toast-err" id="toast-err"></div>

<!-- レシートオーバーレイ -->
<div class="receipt-overlay" id="receipt-overlay">
  <div class="receipt-inner" id="receipt-inner"></div>
</div>

<div class="pos-wrap">

  <!-- 1. カテゴリータブ -->
  <div class="pos-bumon" id="bumon-area">
    <button class="bumon-btn active" data-bumon="">すべて</button>
    <?php foreach ($bumon_list as $b): ?>
      <button class="bumon-btn" data-bumon="<?= htmlspecialchars($b, ENT_QUOTES) ?>">
        <?= htmlspecialchars($b) ?>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- 2. 商品グリッド -->
  <div class="pos-shohin" id="shohin-list">
    <?php foreach ($products as $p): ?>
      <button class="shohin-item"
              data-bumon="<?= htmlspecialchars($p['bumon'], ENT_QUOTES) ?>"
              data-name="<?= htmlspecialchars($p['name'],  ENT_QUOTES) ?>"
              data-tani="<?= htmlspecialchars($p['tani'],  ENT_QUOTES) ?>"
              data-price="<?= $p['price'] ?>">
        <span class="s-name">
          <?= htmlspecialchars($p['name']) ?>
          <?php if ($p['sale']): ?><span class="s-sale">セール</span><?php endif; ?>
        </span>
        <span class="s-price">¥<?= number_format($p['price']) ?></span>
      </button>
    <?php endforeach; ?>
  </div>

  <!-- 3. カート（スマホ：画面下部 / タブレット：右パネル） -->
  <div class="pos-cart" id="pos-cart">
    <!-- タブレット時のみ表示されるカートヘッダー -->
    <div class="cart-panel-header">
      <span>🛒 カート</span>
      <span id="cart-item-count" style="font-size:0.85em;opacity:.8;"></span>
    </div>
    <div class="cart-items" id="cart-items">
      <div class="cart-empty" id="cart-empty">商品をタップして追加</div>
    </div>
    <div class="cart-footer">
      <div>
        <span class="cart-total-label">合計</span>
        <span class="cart-total-amount" id="cart-total">¥0</span>
        <button type="button" class="clear-cart-btn" id="clear-cart-btn" style="display:none;margin-left:.5em;">🗑 全消去</button>
      </div>
      <button class="register-btn" id="reg-btn" disabled>登録する</button>
    </div>
  </div>

</div>

<script>
(function() {
'use strict';

/* --- 値引マスタ（PHP から引き継ぎ） --- */
const nebikiOptions = [
    {label:'値引なし', ritsu:0, gaku:0},
    <?php foreach ($nebiki_ritsu_master as $r): ?>
    {label:'<?= $r ?>%引', ritsu:<?= $r ?>, gaku:0},
    <?php endforeach; ?>
    <?php foreach ($nebiki_gaku_master as $g): ?>
    {label:'¥<?= number_format($g) ?>引', ritsu:0, gaku:<?= $g ?>},
    <?php endforeach; ?>
];

/* --- 状態 --- */
let cart = [];  // {name,bumon,tani,price,qty,nebikiIdx,nebiki_gaku,nebiki_ritsu,subtotal}

const $  = id => document.getElementById(id);
const elCartItems    = $('cart-items');
const elCartEmpty    = $('cart-empty');
const elCartTotal    = $('cart-total');
const elRegBtn       = $('reg-btn');
const elClearCartBtn = $('clear-cart-btn');
const elToastOk      = $('toast-ok');
const elToastErr     = $('toast-err');

/* ===================== カテゴリータブ ===================== */
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

/* ===================== 商品タップ → 即カート追加 ===================== */
$('shohin-list').addEventListener('click', function(e) {
    const btn = e.target.closest('.shohin-item');
    if (!btn) return;

    /* タップフラッシュ */
    btn.classList.add('flash');
    setTimeout(() => btn.classList.remove('flash'), 150);

    const name  = btn.dataset.name;
    const bumon = btn.dataset.bumon;
    const tani  = btn.dataset.tani;
    const price = parseInt(btn.dataset.price, 10);

    /* 同一商品（同名・同価格）がカートにあれば数量+1 */
    const exist = cart.find(it => it.name === name && it.price === price);
    if (exist) {
        exist.qty++;
        recalcItem(exist);
    } else {
        const item = {
            name, bumon, tani, price,
            qty: 1,
            nebikiIdx: 0,        // nebikiOptions の index
            nebiki_gaku: 0,
            nebiki_ritsu: 0,
            subtotal: price
        };
        cart.push(item);
    }
    renderCart();
});

/* ===================== カート描画 ===================== */
function renderCart() {
    /* 既存の行を全削除（cart-empty は残す） */
    elCartItems.querySelectorAll('.ci-row').forEach(el => el.remove());
    elCartEmpty.style.display = cart.length ? 'none' : '';

    cart.forEach((item, i) => {
        const row = document.createElement('div');
        row.className = 'ci-row';
        row.dataset.idx = i;

        /* 値引セレクト */
        let nebOpts = '';
        nebikiOptions.forEach((opt, oi) => {
            nebOpts += '<option value="' + oi + '"' + (oi === item.nebikiIdx ? ' selected' : '') + '>' + esc(opt.label) + '</option>';
        });

        row.innerHTML =
            '<span class="ci-name">' + esc(item.name) + '</span>' +
            '<div class="ci-qty">' +
                '<button type="button" class="ci-qty-btn ci-minus">－</button>' +
                '<span class="ci-qty-num">' + item.qty + '</span>' +
                '<button type="button" class="ci-qty-btn ci-plus">＋</button>' +
            '</div>' +
            '<select class="ci-neb">' + nebOpts + '</select>' +
            '<span class="ci-subtotal">¥' + item.subtotal.toLocaleString() + '</span>' +
            '<button type="button" class="ci-del">🗑 削除</button>';

        elCartItems.appendChild(row);
    });

    /* 合計 */
    const total = cart.reduce((s, it) => s + it.subtotal, 0);
    elCartTotal.textContent = '¥' + total.toLocaleString();
    elRegBtn.disabled = cart.length === 0;
    /* 全消去ボタン表示制御 */
    elClearCartBtn.style.display = cart.length > 0 ? '' : 'none';
    /* タブレット用カートヘッダーの件数表示 */
    const countEl = document.getElementById('cart-item-count');
    if (countEl) countEl.textContent = cart.length > 0 ? cart.length + '点' : '';
}

/* ===================== カート内操作（イベント委譲） ===================== */
elCartItems.addEventListener('click', function(e) {
    const row = e.target.closest('.ci-row');
    if (!row) return;
    const idx = parseInt(row.dataset.idx, 10);
    const item = cart[idx];
    if (!item) return;

    if (e.target.closest('.ci-plus')) {
        item.qty++;
        recalcItem(item);
        renderCart();
    } else if (e.target.closest('.ci-minus')) {
        item.qty = Math.max(1, item.qty - 1);
        recalcItem(item);
        renderCart();
    } else if (e.target.closest('.ci-del')) {
        /* 削除アニメーション付き */
        row.classList.add('removing');
        setTimeout(() => {
            cart.splice(idx, 1);
            renderCart();
        }, 200);
    }
});

/* 全消去ボタン */
elClearCartBtn.addEventListener('click', function() {
    if (cart.length === 0) return;
    if (!confirm('カートの商品をすべて削除しますか？')) return;
    cart = [];
    renderCart();
});

/* 値引セレクト変更 */
elCartItems.addEventListener('change', function(e) {
    if (!e.target.classList.contains('ci-neb')) return;
    const row = e.target.closest('.ci-row');
    if (!row) return;
    const idx = parseInt(row.dataset.idx, 10);
    const item = cart[idx];
    if (!item) return;

    const ni = parseInt(e.target.value, 10);
    const opt = nebikiOptions[ni];
    item.nebikiIdx = ni;
    if (opt.ritsu > 0) {
        item.nebiki_ritsu = opt.ritsu;
        item.nebiki_gaku  = Math.round(item.price * opt.ritsu / 100);
    } else {
        item.nebiki_ritsu = 0;
        item.nebiki_gaku  = opt.gaku;
    }
    recalcItem(item);
    renderCart();
});

/* 小計再計算 */
function recalcItem(item) {
    const unit = Math.max(0, item.price - item.nebiki_gaku);
    item.subtotal = unit * item.qty;
}

/* ===================== 登録（AJAX） ===================== */
elRegBtn.addEventListener('click', async function() {
    if (!cart.length) return;
    elRegBtn.disabled = true;
    elRegBtn.textContent = '送信中…';
    elRegBtn.classList.add('loading');

    try {
        const res = await fetch('sales_entry.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: JSON.stringify({ cart_items: cart })
        });
        const data = await res.json();

        if (data.ok) {
            showReceipt(cart, data.receipt_no, data.total, data.count);
            cart = [];
            renderCart();
        } else {
            showToast(elToastErr, '✕ ' + (data.error || '登録に失敗しました'));
        }
    } catch (err) {
        showToast(elToastErr, '✕ 通信エラー: ' + err.message);
    }

    elRegBtn.textContent = '登録する';
    elRegBtn.classList.remove('loading');
    elRegBtn.disabled = cart.length === 0;
});

/* トースト表示 */
function showToast(el, msg) {
    el.textContent = msg;
    el.classList.add('show');
    setTimeout(() => el.classList.remove('show'), 3000);
}

/* ユーティリティ */
function esc(s) {
    return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

/* ===================== インストアコード（JAN-13） ===================== */
/* フォーマット: 店舗部門コード(7桁) + 金額(5桁) + CD(1桁) = 13桁          */
/* 7桁コードは FM 店舗マスタで店舗ごとに管理（tenpo_API → PHP → ここに注入） */
const INSTORE_CODES = <?= json_encode($ic_dept, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?>;

/** JAN-13 チェックデジット計算（12桁ボディ → チェックデジット1桁） */
function jan13CheckDigit(digits12) {
    let sum = 0;
    for (let i = 0; i < 12; i++) {
        sum += parseInt(digits12[i], 10) * (i % 2 === 0 ? 1 : 3);
    }
    return (10 - (sum % 10)) % 10;
}

/**
 * インストアコード生成
 *   INSTORE_CODES[部門名] = 7桁店舗部門コード（例: '2004355'）
 *   body = 7桁 + 金額5桁 = 12桁 → + CD1桁 = 13桁
 *   未設定部門は null を返す（バーコード非表示）
 */
function makeInstoreCode(bumon, amount) {
    const prefix7 = INSTORE_CODES[bumon] || '';
    if (!prefix7) return null;                       // 未設定 → バーコードなし
    const amt  = Math.min(Math.max(0, Math.round(amount)), 99999);
    const body = prefix7 + String(amt).padStart(5, '0');   // 7 + 5 = 12桁
    return body + jan13CheckDigit(body);                    // + CD = 13桁
}

/* ===================== レシート表示 ===================== */
function showReceipt(items, receiptNo, grandTotal, count) {
    const overlay = document.getElementById('receipt-overlay');
    const inner   = document.getElementById('receipt-inner');

    /* 部門別にグループ化 */
    const groups = {};
    const order  = [];
    items.forEach(function(it) {
        const b = it.bumon || 'その他';
        if (!groups[b]) { groups[b] = []; order.push(b); }
        groups[b].push(it);
    });

    /* 表示順をカテゴリー順に並べ替え（bumon_API 並び順） */
    const catOrder = <?= json_encode($category_order, JSON_UNESCAPED_UNICODE) ?>;
    order.sort(function(a,b) {
        var ia = catOrder.indexOf(a); if (ia < 0) ia = 99;
        var ib = catOrder.indexOf(b); if (ib < 0) ib = 99;
        return ia - ib;
    });

    /* 現在日時 */
    var now = new Date();
    var dateStr = now.getFullYear() + '年' + (now.getMonth()+1) + '月' + now.getDate() + '日 '
                + String(now.getHours()).padStart(2,'0') + ':' + String(now.getMinutes()).padStart(2,'0');

    var html = '';
    html += '<div class="rcpt-card">';
    html += '<div class="rcpt-header">';
    html += '<div class="store">🐟 <?= htmlspecialchars($store_name) ?></div>';
    html += '<div class="date">' + esc(dateStr) + '　No.' + esc(receiptNo) + '</div>';
    html += '<div class="badge-ok">明細書</div>';
    html += '<div class="rcpt-not-receipt">領収書ではありません</div>';
    html += '</div>';

    /* 部門ごとのセクション */
    order.forEach(function(bumon) {
        var bItems = groups[bumon];
        var bSubtotal = bItems.reduce(function(s,it){ return s + it.subtotal; }, 0);
        var code = makeInstoreCode(bumon, bSubtotal);

        html += '<div class="rcpt-bumon-section">';
        html += '<div class="rcpt-bumon-title" data-bumon="' + esc(bumon) + '">' + esc(bumon) + '</div>';

        html += '<table class="rcpt-items">';
        html += '<thead><tr><th>商品名</th><th>単価</th><th>数量</th><th>値引額</th><th>値引詳細</th><th>請求小計</th></tr></thead>';
        html += '<tbody>';
        bItems.forEach(function(it) {
            var nebDetail = '-';
            var nebAmt = 0;
            if (it.nebiki_ritsu > 0) {
                nebDetail = it.nebiki_ritsu + '%引';
                nebAmt = it.nebiki_gaku || Math.round(it.price * it.nebiki_ritsu / 100);
            } else if (it.nebiki_gaku > 0) {
                nebDetail = '¥' + it.nebiki_gaku.toLocaleString() + '引';
                nebAmt = it.nebiki_gaku;
            }
            html += '<tr>';
            html += '<td>' + esc(it.name) + '</td>';
            html += '<td>¥' + it.price.toLocaleString() + '</td>';
            html += '<td>' + it.qty + '</td>';
            html += '<td class="nebiki-cell">' + (nebAmt > 0 ? '¥' + nebAmt.toLocaleString() : '-') + '</td>';
            html += '<td class="nebiki-cell">' + nebDetail + '</td>';
            html += '<td>¥' + it.subtotal.toLocaleString() + '</td>';
            html += '</tr>';
        });
        html += '</tbody></table>';

        html += '<div class="rcpt-bumon-subtotal">';
        html += '<span>' + esc(bumon) + ' 小計</span>';
        html += '<span class="amt">¥' + bSubtotal.toLocaleString() + '</span>';
        html += '</div>';

        if (code) {
            html += '<div class="rcpt-bumon-code">';
            html += '<svg id="bc-' + esc(bumon) + '"></svg>';
            html += '<span>' + code + '</span>';
            html += '</div>';
        }

        html += '</div>'; /* .rcpt-bumon-section */
    });

    /* 合計 */
    html += '<div class="rcpt-grand-total">';
    html += '<span class="label">合　計</span>';
    html += '<div>';
    html += '<span class="amount">¥' + grandTotal.toLocaleString() + '</span>';
    html += '<span class="tax">（本体価格）</span>';
    html += '</div></div>';

    html += '<div class="rcpt-footer-msg">ありがとうございました</div>';
    html += '<div class="rcpt-corp">';
    html += '<div style="font-weight:bold;">株式会社富惣</div>';
    html += '<div>大阪府堺市堺区遠里小野町３丁４番１号</div>';
    html += '<div>TEL 072-229-8800 / FAX 072-229-4700</div>';
    html += '<div>お問い合せ窓口 0120-014-868</div>';
    html += '</div>';
    html += '</div>'; /* .rcpt-card */

    /* StarWebPRNT 用にレシートデータをセット（未設定部門はスキップ） */
    var _barCodes = {};
    order.forEach(function(bumon) {
        var bItems = groups[bumon];
        var bSub   = bItems.reduce(function(s,it){ return s + it.subtotal; }, 0);
        var bc = makeInstoreCode(bumon, bSub);
        if (bc) _barCodes[bumon] = bc;
    });
    _starReceiptData = {
        storeName : '<?= htmlspecialchars($store_name, ENT_QUOTES) ?>',
        dateStr   : dateStr,
        receiptNo : String(receiptNo),
        groups    : groups,
        catOrder  : order,
        grandTotal: grandTotal,
        isKakunin : false,
        barCodes  : _barCodes,
        count     : count
    };

    /* ボタン */
    html += '<div class="rcpt-buttons">';
    html += '<button class="rcpt-btn-print" id="rcpt-print-btn">🖨 印刷</button>';
    html += '</div>';

    inner.innerHTML = html;
    overlay.classList.add('show');

    /* バーコード描画（未設定部門はスキップ） */
    order.forEach(function(bumon) {
        var bItems = groups[bumon];
        var bSubtotal = bItems.reduce(function(s,it){ return s + it.subtotal; }, 0);
        var code = makeInstoreCode(bumon, bSubtotal);
        if (!code) return;
        try {
            JsBarcode('#bc-' + CSS.escape(bumon), code, {
                format: 'EAN13',
                width: 2,
                height: 50,
                displayValue: false,
                margin: 4
            });
        } catch(e) { console.warn('Barcode error for ' + bumon + ':', e); }
    });

    /* 印刷ボタン（印刷実行後、レシートを閉じて売上登録画面に戻る＝次のお客様の入力へ） */
    document.getElementById('rcpt-print-btn').addEventListener('click', function() {
        starPrintReceipt(this);
        overlay.classList.remove('show');
    });
}

})();
</script>
<?php include __DIR__ . '/footer.php'; ?>
