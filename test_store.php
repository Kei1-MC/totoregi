<?php
/**
 * test_store.php  テスト店舗の扱い（本社の集計に反映しない規則）
 *
 * 店舗番号 999 は動作確認用のテスト店舗。ととレジで売上・日報を登録してもよいが、
 * 本社側の集計・一覧・公開カタログには一切反映しない（2026-10-18 規則化）。
 *
 * 規則:
 *   - 本社の集計系画面（成績表・時間帯別・単品管理・本社入力・店舗別日報・発注一覧）の
 *     店舗一覧と集計対象から、このファイルの TEST_STORE_IDS を除外する。
 *   - 集計系の画面・APIを新設するときは、必ず is_test_store() で除外すること。
 *   - 店舗マスタ保守(hq_tenpo_maint.php)・商品マスタ保守は、設定のため 999 も表示する（集計ではない）。
 *   - 999 のテスト売上は sales_list.php の訂正画面から削除して片付ける。
 */
const TEST_STORE_IDS = ['999'];

function is_test_store($store_id): bool {
    return in_array(trim((string)$store_id), TEST_STORE_IDS, true);
}
