<?php
/**
 * tax_lib.php  税込金額の端数処理
 *   店舗ごとの端数処理（切り捨て／四捨五入／切り上げ）は account_API の「端数処理」で管理（本社が設定）。
 *   未設定の店舗は「切り上げ」（2026-10-18 富惣さん確認: 切り捨ては東京大丸・梅田大丸・神戸大丸・栄松坂屋・上野松坂屋・松山髙島屋のみ）。
 *   ※ catalog_lib.php の catalog_norm_rounding() は未設定=切り捨て。公開カタログ側の統一は別途確認。
 */
use fmRESTor\fmRESTor;

const TAX_RATE_PERCENT = 8;   // 軽減税率（持ち帰り惣菜）

function tax_norm_rounding(string $s): string {
    $s = trim($s);
    if (in_array($s, ['切り捨て', '切捨て', '切捨'], true)) return '切り捨て';
    if ($s === '四捨五入') return '四捨五入';
    return '切り上げ';
}

/** 店舗の端数処理を account_API から取得。取得失敗・未設定は切り上げ */
function tax_store_rounding(string $store_id, string $host, string $db, string $layout_account,
                            string $user, string $pass): string {
    try {
        $fm  = new fmRESTor($host, $db, $layout_account, $user, $pass, ['allowInsecure' => true]);
        $res = $fm->findRecords(['query' => [['店舗Ｎｏ' => $store_id]]]);
        foreach ($res['result']['response']['data'] ?? [] as $row) {
            $r = trim((string)($row['fieldData']['端数処理'] ?? ''));
            if ($r !== '') return tax_norm_rounding($r);
        }
    } catch (\Throwable $e) {}
    return '切り上げ';
}

/** 本体価格合計 → 税込金額（整数演算。JS 側 taxIncluded() と同じ式） */
function tax_included(int $total, string $rounding): int {
    $x = $total * (100 + TAX_RATE_PERCENT);
    if ($rounding === '切り捨て') return intdiv($x, 100);
    if ($rounding === '四捨五入') return intdiv($x + 50, 100);
    return intdiv($x + 99, 100);
}
