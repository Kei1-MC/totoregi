<?php
/**
 * order_lib.php — 発注機能（店舗→本社・工場）共通処理
 *
 * ■ レイアウト（fm_setting.php）
 *   order_API       発注。店舗×納品日×便×商品で1レコード（数量0はレコードを作らない）
 *   order_item_API  発注可能商品。店舗×便×部門×商品（本社が hq_order_items.php で管理）
 *   order_bin_API   出荷便マスタ。ID / 出荷便名 / 締め時刻(HH:MM) / 納品までの日数
 *
 * ■ 締めのルール
 *   締め日時 ＝ 納品日 − 「納品までの日数」日 の「締め時刻」
 *   例）納品日 10/15・納品までの日数 1・締め時刻 14:00 → 10/14 14:00 まで受付
 *   締め時刻が未設定の便は受付不可（誤って締め後に受け付けないため）
 *
 * ■ 状態
 *   入力中（店舗が編集できる）→ 確定（本社が確定。店舗は編集不可）→ 取込済（納品明細へ反映。将来用）
 */
use fmRESTor\fmRESTor;

date_default_timezone_set('Asia/Tokyo');

const ORDER_STATE_OPEN      = '入力中';
const ORDER_STATE_CONFIRMED = '確定';
const ORDER_STATE_IMPORTED  = '取込済';
const ORDER_MAX_DAYS_AHEAD  = 14;   // 何日先の納品日まで入力できるか
const ORDER_MAX_QTY         = 9999; // 1商品あたりの上限（打ち間違い防止）

/** FileMaker 接続を作る */
function order_fm(string $layout): fmRESTor {
    global $host, $db, $api_master_user, $api_master_pass;
    return new fmRESTor($host, $db, $layout, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
}

/** findRecords の結果から data 配列を取り出す（0件=401 は空配列） */
function order_rows(array $res): array {
    if (($res['result']['messages'][0]['code'] ?? '0') === '401') return [];
    return $res['result']['response']['data'] ?? [];
}

/** m/d/Y（FM形式）の納品日を DateTime にする。不正なら null */
function order_parse_date(?string $s): ?DateTime {
    if ($s === null || $s === '') return null;
    $dt = DateTime::createFromFormat('!m/d/Y', $s);
    if (!$dt || $dt->format('m/d/Y') !== $s) return null;
    return $dt;
}

/**
 * 出荷便マスタを取得
 * @return array  [ID => ['id'=>int,'name'=>string,'cutoff'=>'HH:MM'|'','lead'=>int], ...]
 */
function order_fetch_bins(): array {
    global $layout_order_bin;
    $res  = order_fm($layout_order_bin)->getRecords(['_limit' => 100]);
    $bins = [];
    foreach ($res['result']['response']['data'] ?? [] as $row) {
        $f  = $row['fieldData'] ?? [];
        $id = (int)($f['ID'] ?? 0);
        if ($id <= 0) continue;
        $cut = trim((string)($f['締め時刻'] ?? ''));
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(:[0-5]\d)?$/', $cut, $m)) $cut = '';
        else $cut = sprintf('%02d:%s', (int)$m[1], $m[2]);
        $lead = trim((string)($f['納品までの日数'] ?? ''));
        $bins[$id] = [
            'id'     => $id,
            'name'   => trim((string)($f['出荷便名'] ?? '')) ?: "便{$id}",
            'cutoff' => $cut,
            'lead'   => ($lead === '') ? 1 : max(0, (int)$lead),
        ];
    }
    ksort($bins);
    return $bins;
}

/**
 * 便の締め日時を返す。締め時刻が未設定なら null
 */
function order_cutoff(array $bin, DateTime $delivery_date): ?DateTime {
    if (($bin['cutoff'] ?? '') === '') return null;
    [$h, $mi] = array_map('intval', explode(':', $bin['cutoff']));
    $c = clone $delivery_date;
    $c->setTime(0, 0, 0)->modify('-' . (int)$bin['lead'] . ' days')->setTime($h, $mi, 0);
    return $c;
}

/** その便がいま受付中か（締め時刻未設定・締め済みは false） */
function order_bin_is_open(array $bin, DateTime $delivery_date, ?DateTime $now = null): bool {
    $c = order_cutoff($bin, $delivery_date);
    if ($c === null) return false;
    return ($now ?? new DateTime()) < $c;
}

/** 画面表示用：締めの状態文言 */
function order_cutoff_label(array $bin, DateTime $delivery_date): string {
    $c = order_cutoff($bin, $delivery_date);
    if ($c === null) return '締め時刻未設定（受付不可）';
    $w = ['日','月','火','水','木','金','土'][(int)$c->format('w')];
    $s = $c->format('n/j') . "（{$w}）" . $c->format('H:i');
    return order_bin_is_open($bin, $delivery_date) ? "{$s} まで受付中" : "{$s} で締切済み";
}

/**
 * 店舗の発注可能商品を取得
 * @return array  [ ['record_id','bin_id','bumon','pno','name','order'], ...]（便→部門→表示順）
 */
function order_fetch_items(string $store_id): array {
    global $layout_order_item;
    $res = order_fm($layout_order_item)->findRecords([
        'query' => [['fk_店舗No' => '==' . $store_id, '有効' => '1']],
        'limit' => 1000,
    ]);
    $items = [];
    foreach (order_rows($res) as $row) {
        $f   = $row['fieldData'];
        $pno = (int)($f['商品No'] ?? 0);
        if ($pno <= 0) continue;
        $items[] = [
            'record_id' => $row['recordId'],
            'bin_id'    => (int)($f['便ID'] ?? 0),
            'bumon'     => trim((string)($f['販売部門'] ?? '')),
            'pno'       => $pno,
            'name'      => trim((string)($f['品名'] ?? '')),
            'order'     => (int)($f['表示順'] ?? 0),
        ];
    }
    usort($items, fn($a, $b) =>
        [$a['bin_id'], $a['bumon'], $a['order'], $a['pno']] <=> [$b['bin_id'], $b['bumon'], $b['order'], $b['pno']]);
    return $items;
}

/**
 * 店舗×納品日の発注レコードを取得
 * @return array  ["{便ID}:{商品No}" => ['record_id','qty','state']]
 */
function order_fetch_orders(string $store_id, string $date_fm): array {
    global $layout_order;
    $res = order_fm($layout_order)->findRecords([
        'query' => [['fk_店舗No' => '==' . $store_id, '納品日' => $date_fm]],
        'limit' => 1000,
    ]);
    $out = [];
    foreach (order_rows($res) as $row) {
        $f = $row['fieldData'];
        $out[(int)($f['便ID'] ?? 0) . ':' . (int)($f['商品No'] ?? 0)] = [
            'record_id' => $row['recordId'],
            'qty'       => (int)($f['数量'] ?? 0),
            'state'     => trim((string)($f['状態'] ?? '')) ?: ORDER_STATE_OPEN,
        ];
    }
    return $out;
}

/** 数量の入力値を検証。不正なら null、空欄・0 は 0 */
function order_parse_qty($v): ?int {
    $v = trim((string)$v);
    if ($v === '') return 0;
    if (!preg_match('/^\d{1,4}$/', $v)) return null;
    $n = (int)$v;
    return ($n <= ORDER_MAX_QTY) ? $n : null;
}

/** CSRF トークン（セッションに1つ持つ） */
function order_csrf_token(): string {
    if (empty($_SESSION['order_csrf'])) $_SESSION['order_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['order_csrf'];
}

function order_csrf_ok(?string $t): bool {
    return is_string($t) && !empty($_SESSION['order_csrf']) && hash_equals($_SESSION['order_csrf'], $t);
}
