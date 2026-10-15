<?php
/**
 * catalog_lib.php  公開カタログ（コーポレートサイト用）の生成
 *
 * 販売商品(hanbai_API)の「発売中」かつ取扱店舗に含まれる商品を、運用中店舗ごとにまとめる。
 * 公開してよい項目だけを出す（商品名・部門・税込価格・販売単位・セール・写真URL）。
 * 設計: tomiso_ローカル資料_サーバ非公開/設計_コーポレートサイト商品ラインアップ連携.md
 */
use fmRESTor\fmRESTor;

const CATALOG_TAX_RATE_PCT = 108;   // 軽減税率8%（イートインなし）
const CATALOG_CACHE_TTL    = 300;   // 秒。保険の再生成間隔
const CATALOG_ACTIVE_DAYS  = 31;    // ととレジの売上明細がこの日数以内にある店舗を「運用中」とする
const CATALOG_ACTIVE_TTL   = 3600;  // 運用中店舗の判定結果を保持する秒数

function catalog_cache_path(): string { return __DIR__ . '/pub/_cache/catalog.json'; }

/** キャッシュを無効化（商品メンテで保存・トグルした時に呼ぶ） */
function catalog_invalidate(): void {
    $p = catalog_cache_path();
    if (is_file($p)) @unlink($p);
}

/** 本体価格(税抜)→税込。$mode: 切り捨て(既定)／四捨五入／切り上げ */
function catalog_tax_in(int $price, string $mode = '切り捨て'): int {
    $v = $price * CATALOG_TAX_RATE_PCT;
    if ($mode === '四捨五入') return intdiv($v + 50, 100);
    if ($mode === '切り上げ') return intdiv($v + 99, 100);
    return intdiv($v, 100);
}

function catalog_norm_rounding(string $s): string {
    $s = trim($s);
    return in_array($s, ['切り捨て', '切捨て', '切捨'], true) ? '切り捨て'
         : (in_array($s, ['四捨五入'], true) ? '四捨五入'
         : (in_array($s, ['切り上げ', '切上げ', '切上'], true) ? '切り上げ' : '切り捨て'));
}

/** @return array キャッシュ（古ければ再生成）。失敗時は直前のキャッシュまたは空 */
function catalog_get(string $base_url): array {
    $p = catalog_cache_path();
    if (is_file($p) && (time() - filemtime($p)) < CATALOG_CACHE_TTL) {
        $d = json_decode((string)file_get_contents($p), true);
        if (is_array($d)) return $d;
    }
    $d = catalog_build($base_url);
    if ($d !== null) {
        $tmp = $p . '.' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, json_encode($d, JSON_UNESCAPED_UNICODE)) !== false) @rename($tmp, $p);
        return $d;
    }
    if (is_file($p)) {
        $old = json_decode((string)file_get_contents($p), true);
        if (is_array($old)) return $old;
    }
    return ['updated' => null, 'stores' => [], 'products' => []];
}

/**
 * ととレジの売上明細（pos_API）が直近にある店舗Noの集合 [sno => true]。
 * 判定は店舗ごとに1件だけ検索する。結果は1時間キャッシュする。
 */
function catalog_active_stores(array $candidates): array {
    global $host, $db, $layout_pos, $api_master_user, $api_master_pass;
    $cp = __DIR__ . '/pub/_cache/active.json';
    if (is_file($cp) && (time() - filemtime($cp)) < CATALOG_ACTIVE_TTL) {
        $d = json_decode((string)file_get_contents($cp), true);
        if (is_array($d)) return $d;
    }
    $fp = new fmRESTor($host, $db, $layout_pos, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
    $range = date('m/d/Y', strtotime('-' . CATALOG_ACTIVE_DAYS . ' days')) . '...' . date('m/d/Y');
    $active = [];
    foreach ($candidates as $no) {
        $r = $fp->findRecords(['query' => [['店舗No' => '==' . $no, '販売日時' => $range]], 'limit' => 1]);
        if (!empty($r['result']['response']['data'])) $active[(string)$no] = true;
    }
    @file_put_contents($cp, json_encode($active));
    return $active;
}

/** FileMakerから生成。取得失敗は null */
function catalog_build(string $base_url): ?array {
    global $host, $db, $layout_account, $layout_hanbai, $layout_daily_report, $api_master_user, $api_master_pass;
    $opt = ['allowInsecure' => true];

    // ---- 店舗（account_API）: 名称・営業状態・端数処理 ----
    $fa  = new fmRESTor($host, $db, $layout_account, $api_master_user, $api_master_pass, $opt);
    $ra  = $fa->getRecords(['_limit' => 300]);
    if (!isset($ra['result']['response']['data'])) return null;
    $acct = []; // sno => [name, closed, rounding]
    foreach ($ra['result']['response']['data'] as $row) {
        $f  = $row['fieldData'];
        $no = (string)($f['店舗Ｎｏ'] ?? '');
        $nm = trim((string)($f['店舗名'] ?? ''));
        if ($no === '' || $no === '000' || $nm === '') continue;
        $a = $acct[$no] ?? ['name' => $nm, 'closed' => false, 'rounding' => ''];
        if (trim((string)($f['営業状態'] ?? '')) === '閉店') $a['closed'] = true;
        $r = trim((string)($f['端数処理'] ?? ''));
        if ($r !== '' && $a['rounding'] === '') $a['rounding'] = $r;
        $acct[$no] = $a;
    }

    // ---- 運用中店舗（ととレジの売上明細がある店舗）と本日の定休日 ----
    // 日報は全店分が取り込まれているため、運用中の判定には使わない（レジ売上の有無で判定）。
    $active = catalog_active_stores(array_keys($acct));
    // テスト売上などで運用中と誤判定される店舗を除外する場合: pub/catalog_config.json {"exclude":["102"]}
    $cfg = is_file(__DIR__ . '/pub/catalog_config.json')
         ? (json_decode((string)file_get_contents(__DIR__ . '/pub/catalog_config.json'), true) ?: []) : [];
    foreach ((array)($cfg['exclude'] ?? []) as $ex) unset($active[(string)$ex]);
    $fd = new fmRESTor($host, $db, $layout_daily_report, $api_master_user, $api_master_pass, $opt);
    $to = date('m/d/Y');
    $closed_today = [];
    $rd = $fd->findRecords(['query' => [['売上日' => $to]], 'limit' => 500]);
    foreach ($rd['result']['response']['data'] ?? [] as $row) {
        $f = $row['fieldData'];
        if ((int)($f['定休日'] ?? 0) === 1) $closed_today[(string)($f['fk_店舗No'] ?? '')] = true;
    }
    $stores = [];
    foreach ($acct as $no => $a) {
        if (!isset($active[$no]) || $a['closed']) continue;
        $stores[$no] = [
            'sno'          => (string)$no,
            'name'         => $a['name'],
            'rounding'     => catalog_norm_rounding($a['rounding']),
            'closed_today' => isset($closed_today[$no]),
        ];
    }

    // ---- 商品（hanbai_API） ----
    $fh = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, $opt);
    $rh = $fh->getRecords(['_limit' => 1000]);
    if (!isset($rh['result']['response']['data'])) return null;

    $products = [];
    foreach ($rh['result']['response']['data'] as $row) {
        $f = $row['fieldData'];
        $name = trim((string)($f['商品名'] ?? ''));
        if ($name === '' || (int)($f['発売中'] ?? 1) !== 1) continue;
        $bumon  = trim((string)($f['部門'] ?? ''));        // 販売部門
        $master = (int)($f['本体価格'] ?? 0);
        $pid    = (string)$row['recordId'];
        $sale_flag = (int)($f['セール'] ?? 0) === 1;

        $by_store = [];
        foreach (getStorePositions($f) as $pos => $sno) {
            if (!isset($stores[$sno])) continue;
            $hp = (int)($f[repeatKey('店舗本体価格', $pos)] ?? 0);
            $sp = (int)($f[repeatKey('セール価格', $pos)] ?? 0);
            $base = $hp > 0 ? $hp : $master;
            if ($base <= 0) continue;
            $mode = $stores[$sno]['rounding'];
            $by_store[$sno] = [
                'price'      => catalog_tax_in($sp > 0 ? $sp : $base, $mode),
                'org_price'  => ($sp > 0 && $sp < $base) ? catalog_tax_in($base, $mode) : null,
            ];
        }
        if (!$by_store) continue;

        $photo = is_file(__DIR__ . "/pub/img/{$pid}.jpg") ? "{$base_url}/pub/img/{$pid}.jpg" : null;
        $products[] = [
            'id'     => $pid,
            'name'   => $name,
            'bumon'  => $bumon !== '' ? $bumon : 'その他',
            'yomi'   => trim((string)($f['よみがな'] ?? '')),
            'unit'   => trim((string)($f['販売単位'] ?? '')),
            'sale'   => $sale_flag,
            'photo'  => $photo,
            'stores' => $by_store,
        ];
    }
    return [
        'updated'  => date('c'),
        'tax_note' => '税込価格（軽減税率8%）',
        'stores'   => array_values($stores),
        'products' => $products,
    ];
}
