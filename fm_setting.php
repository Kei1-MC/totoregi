<?php
// ============================================================
// fm_setting.php  FileMaker 接続設定
// ============================================================

// --- サーバー & データベース ---
$host = 'sys.kei1.me';
$db   = 'tomiso';

// --- レイアウト定義 ---
//
// ■ 認証・ユーザー管理
$layout_tenpo   = 'tenpo_API';        // _21_店舗マスタ（ログイン・店舗情報取得）
$layout_account = 'account_API';      // Webユーザー管理（将来用）

// ■ 店舗POS（販売商品テーブル 46件）
$layout_pos     = 'pos_API';          // 店舗売上明細（売上登録・参照）
$layout_hanbai  = 'hanbai_API';       // 販売商品（POS商品一覧 ※46件。上代単価フィールドあり）
$layout_bumon   = 'bumon_API';        // 部門マスタ（部門名・並び順。インストアコード部門定義の元）
$layout_haiki   = 'haiki_API';        // 廃棄日報（商品単位・1日1回入力）

// ■ 発注・受注（工場向け）
$layout_product = 'product_API';      // 商品マスタ（発注用 ※12,403件）

// ■ 売上日報（店舗入力・本部集計）
$layout_daily_report     = 'daily_report_API';      // _24_売上日報（CRUD全操作）
$layout_daily_report_sum = 'daily_report_sum_API';  // _24_売上日報（集計・昨対クエリ）

// ============================================================
// 注意：FileMaker 側で各 _API レイアウトを作成する際、
//       そのレイアウトのベーステーブルを以下に合わせてください。
//
//   tenpo_API            → _21_店舗マスタ
//   pos_API              → 店舗売上明細
//   hanbai_API           → 販売商品
//   bumon_API            → 部門マスタ（部門CD/部門名/並び順）
//   haiki_API            → _25_廃棄日報（fk_店舗No/店舗名/売上日/商品名/廃棄数）
//   product_API          → 商品マスタ
//   daily_report_API     → _24_売上日報（全フィールド配置）
//   daily_report_sum_API → _24_売上日報（週番号・曜日・年 の計算フィールドを必ず配置）
// ============================================================

// --- API 機密情報の読み込み ---
require_once __DIR__ . '/fm_config_secret.php';

$api_master_user = $api_master_user;
$api_master_pass = $api_master_pass;

// ============================================================
// 繰り返しフィールド ヘルパー（取扱店舗 / セール価格）
// ============================================================
define('MAX_STORE_REPS', 50); // FileMaker 繰り返し数に合わせる

/** 繰り返しフィールドのキー名を返す（FM Data API は全 pos に (n) サフィックスを付ける） */
function repeatKey(string $field, int $pos): string {
    return "{$field}({$pos})";
}

/** fieldData から繰り返しフィールドを [pos => value] 配列で取得
 *  FM Data API は値を整数で返すことがあるため (string) でキャスト */
function getRepeatValues(array $f, string $field, int $max = MAX_STORE_REPS): array {
    $result = [];
    for ($i = 1; $i <= $max; $i++) {
        $raw = $f[repeatKey($field, $i)] ?? '';
        $v   = trim((string)$raw);
        if ($v !== '') $result[$i] = $v;
    }
    return $result;
}

/** 取扱店舗の [pos => storeId] マップを取得 */
function getStorePositions(array $f): array {
    return getRepeatValues($f, '取扱店舗');
}

/** 特定店舗のセール価格（0=未設定）を取得 */
function getStoreSalePrice(array $f, string $storeId): int {
    $positions = getStorePositions($f);
    $pos = array_search($storeId, $positions);
    if ($pos === false) return 0;
    return (int)($f[repeatKey('セール価格', $pos)] ?? 0);
}

/** 特定店舗の本体価格（店舗別。0=未設定＝本部設定の本体価格を使う）を取得 */
function getStoreHonbaiPrice(array $f, string $storeId): int {
    $positions = getStorePositions($f);
    $pos = array_search($storeId, $positions);
    if ($pos === false) return 0;
    return (int)($f[repeatKey('店舗本体価格', $pos)] ?? 0);
}

/**
 * 取扱店舗チェックボックスの保存用 fieldData を構築
 * @param array $selectedStoreIds   選択された店舗IDの配列（順序不問）
 * @param array $currentPositions   現在の [pos => storeId]
 * @param array $currentSalePrices  現在の [pos => salePrice] を保持するため
 * @param array $currentHonbaiPrices 現在の [pos => 店舗本体価格] を保持するため
 * @param int   $masterHonbaiPrice  本部設定の本体価格（新規に選択された店舗の初期値）
 */
function buildStoreFieldData(array $selectedStoreIds, array $currentPositions = [], array $currentSalePrices = [],
                              array $currentHonbaiPrices = [], int $masterHonbaiPrice = 0): array {
    // 選択店舗を既存ポジション優先で割り当て
    $newPositions = []; // pos => storeId
    // 既存ポジションで選択済みのものを維持
    foreach ($currentPositions as $pos => $sid) {
        if (in_array($sid, $selectedStoreIds)) {
            $newPositions[$pos] = $sid;
        }
    }
    // 新規追加分を空きに詰める
    foreach ($selectedStoreIds as $sid) {
        if (!in_array($sid, $newPositions)) {
            for ($i = 1; $i <= MAX_STORE_REPS; $i++) {
                if (!isset($newPositions[$i])) {
                    $newPositions[$i] = $sid;
                    break;
                }
            }
        }
    }

    // fieldData を構築（全50ポジション分）
    $data = [];
    for ($i = 1; $i <= MAX_STORE_REPS; $i++) {
        $data[repeatKey('取扱店舗', $i)]  = $newPositions[$i] ?? '';
        // セール価格は既存ポジションが維持された場合のみ保持
        $data[repeatKey('セール価格', $i)] = isset($newPositions[$i]) ? ($currentSalePrices[$i] ?? '') : '';
        // 店舗本体価格は既存値を保持。値が無い（新規選択・未設定）場合は本部設定の本体価格を初期値にする
        if (isset($newPositions[$i])) {
            $existing = $currentHonbaiPrices[$i] ?? '';
            $data[repeatKey('店舗本体価格', $i)] = ($existing !== '' && $existing !== 0) ? $existing : $masterHonbaiPrice;
        } else {
            $data[repeatKey('店舗本体価格', $i)] = '';
        }
    }
    return $data;
}

/** 取扱店舗に1店舗追加する（次の空きポジションに。店舗本体価格は本部設定価格を初期値にする）*/
function addStoreFieldData(array $f, string $storeId, int $masterHonbaiPrice = 0): array {
    $positions = getStorePositions($f);
    if (in_array($storeId, $positions)) return []; // 既存
    for ($i = 1; $i <= MAX_STORE_REPS; $i++) {
        if (!isset($positions[$i])) {
            return [
                repeatKey('取扱店舗', $i)   => $storeId,
                repeatKey('店舗本体価格', $i) => $masterHonbaiPrice,
            ];
        }
    }
    return []; // 満杯
}

/** 取扱店舗から1店舗削除する */
function removeStoreFieldData(array $f, string $storeId): array {
    $positions = getStorePositions($f);
    $pos = array_search($storeId, $positions);
    if ($pos === false) return [];
    return [
        repeatKey('取扱店舗', $pos)  => '',
        repeatKey('セール価格', $pos) => '',
        repeatKey('店舗本体価格', $pos) => '',
    ];
}

/** findRecords 用OR クエリ（いずれかの繰り返しに storeId が含まれる商品を検索）*/
function buildStoreFindQuery(string $storeId, array $extraConditions = []): array {
    $queries = [];
    for ($i = 1; $i <= MAX_STORE_REPS; $i++) {
        $queries[] = array_merge([repeatKey('取扱店舗', $i) => $storeId], $extraConditions);
    }
    return $queries;
}