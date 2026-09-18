<?php
/**
 * bumon_master.php — 部門マスタ（bumon_API）共通処理
 *
 * FileMaker レイアウト: bumon_API
 *   部門CD : 数字（一意）
 *   部門名 : テキスト（表示・カテゴリー分類に使用）
 *   並び順 : 数字（タブ・レシート表示順）
 */

/**
 * 部門マスタ一覧を取得（並び順の昇順）
 * @return array  [ ['cd'=>int, 'name'=>string, 'order'=>int], ... ]
 */
function fetch_bumon_master(string $host, string $db, string $layout_bumon,
                             string $api_master_user, string $api_master_pass): array {
    try {
        $fm  = new \fmRESTor\fmRESTor($host, $db, $layout_bumon,
                                       $api_master_user, $api_master_pass,
                                       ['allowInsecure' => true]);
        $res  = $fm->getRecords(['_limit' => 200]);
        $rows = $res['result']['response']['data'] ?? [];
    } catch (\Throwable $e) {
        return [];
    }

    $list = [];
    foreach ($rows as $row) {
        $f    = $row['fieldData'] ?? [];
        $name = trim((string)($f['部門名'] ?? ''));
        if ($name === '') continue;
        $list[] = [
            'cd'    => (int)($f['部門CD'] ?? 0),
            'name'  => $name,
            'order' => (int)($f['並び順'] ?? 0),
        ];
    }
    usort($list, fn($a, $b) => $a['order'] <=> $b['order']);
    return $list;
}

/** 部門名だけの配列（並び順昇順） */
function bumon_names(array $bumon_master): array {
    return array_map(fn($b) => $b['name'], $bumon_master);
}

/** account_API 用フィールド名マップ [部門名 => 'インストアコード_<部門名>'] */
function bumon_ic_field_map(array $bumon_master): array {
    $map = [];
    foreach ($bumon_master as $b) {
        $map[$b['name']] = 'インストアコード_' . $b['name'];
    }
    return $map;
}
