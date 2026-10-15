<?php
/**
 * pub_catalog.php  公開カタログJSON（ログイン不要・読み取り専用）
 *   ?scope=all            全店（stores・products）
 *   ?scope=store&sno=101  1店舗分（その店舗の商品だけ）
 * コーポレートサイト（https://www.tomiso.co.jp）からのfetchを許可する。
 */
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/catalog_lib.php';

$allow = ['https://www.tomiso.co.jp', 'https://tomiso.co.jp'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allow, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=60');
ini_set('display_errors', 0);

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
$base  = ($https ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'kei1.me')
       . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

$data = catalog_get($base);

if (($_GET['scope'] ?? 'all') === 'store') {
    $sno = (string)($_GET['sno'] ?? '');
    $store = null;
    foreach ($data['stores'] as $s) if ($s['sno'] === $sno) $store = $s;
    if (!$store) { http_response_code(404); echo json_encode(['error' => 'store not found']); exit; }
    $items = [];
    foreach ($data['products'] as $p) {
        if (!isset($p['stores'][$sno])) continue;
        $q = $p; unset($q['stores']);
        $q['price']     = $p['stores'][$sno]['price'];
        $q['org_price'] = $p['stores'][$sno]['org_price'];
        $items[] = $q;
    }
    echo json_encode(['updated' => $data['updated'], 'tax_note' => $data['tax_note'] ?? '',
                      'store' => $store, 'products' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}
echo json_encode($data, JSON_UNESCAPED_UNICODE);
