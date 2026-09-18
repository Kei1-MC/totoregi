<?php
/**
 * sales_list.php  レシート単位折りたたみ版
 * - レシート番号でグループ化
 * - クリックで明細展開/折りたたみ
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['store_id'])) { header('Location: login.php'); exit(); }
if (($_SESSION['role'] ?? '') === 'hq') { header('Location: hq_top.php'); exit(); }
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';

$store_id   = $_SESSION['store_id'];
$store_name = $_SESSION['store_name'];

$selected_date_raw = $_GET['date'] ?? date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selected_date_raw)) {
    $selected_date_raw = date('Y-m-d');
}
$dt = DateTime::createFromFormat('Y-m-d', $selected_date_raw);
$selected_date_fm = $dt->format('m/d/Y');
$selected_date_jp = $dt->format('Y年n月j日');
$is_today     = ($selected_date_raw === date('Y-m-d'));
$edited_receipt = $_GET['edited'] ?? '';  // 訂正完了後のメッセージ用

// FileMakerから取得（500件超に対応するため offset でページング全件取得）
function fetchAllPosRecords(fmRESTor $fm, array $query, array $sort, int $chunk = 500, int $maxPages = 50): array {
    $all = []; $offset = 1;
    for ($i = 0; $i < $maxPages; $i++) {
        $r = $fm->findRecords(['query' => [$query], 'sort' => $sort, 'limit' => $chunk, 'offset' => $offset]);
        if (($r['result']['messages'][0]['code'] ?? '0') === '401') break;
        $data = $r['result']['response']['data'] ?? [];
        if (!$data) break;
        foreach ($data as $row) $all[] = $row;
        if (count($data) < $chunk) break;
        $offset += $chunk;
    }
    return $all;
}

$fm = new fmRESTor($host, $db, $layout_pos, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$data = fetchAllPosRecords(
    $fm,
    ['店舗No' => $store_id, '販売日時' => $selected_date_fm],
    [
        ['fieldName' => 'レシート番号',         'sortOrder' => 'ascend'],
        ['fieldName' => '作成情報タイムスタンプ','sortOrder' => 'ascend'],
    ]
);
$records = [];
$total   = 0;

foreach ($data as $row) {
    $f = $row['fieldData'];
    $records[] = [
        'record_id'    => $row['recordId'] ?? '',   // ← 削除用に追加
        'receipt_no'   => $f['レシート番号'] ?? '',
        'timestamp'    => $f['作成情報タイムスタンプ'] ?? '',
        'name'         => $f['商品名']    ?? '',
        'bumon'        => $f['部門']      ?? '',
        'tani'         => $f['販売単位']  ?? '',
        'qty'          => (int)($f['数量']     ?? 0),
        'price'        => (int)($f['本体価格'] ?? 0),
        'nebiki_gaku'  => (int)($f['値引額']   ?? 0),
        'nebiki_ritsu' => (float)($f['値引率'] ?? 0),
        'kingaku'      => (int)($f['販売金額'] ?? 0),
    ];
    $total += (int)($f['販売金額'] ?? 0);
}

// レシート番号でグループ化
$receipts = [];
foreach ($records as $rec) {
    $rno = $rec['receipt_no'] ?: 'no_receipt';
    if (!isset($receipts[$rno])) {
        $receipts[$rno] = ['items' => [], 'total' => 0, 'time' => '', 'record_ids' => []];
    }
    $receipts[$rno]['items'][] = $rec;
    $receipts[$rno]['total']  += $rec['kingaku'];
    if ($rec['record_id']) $receipts[$rno]['record_ids'][] = $rec['record_id'];
    // 時刻は最初のレコードから
    if ($receipts[$rno]['time'] === '' && $rec['timestamp']) {
        $parts = explode(' ', $rec['timestamp']);
        $receipts[$rno]['time'] = isset($parts[1]) ? substr($parts[1], 0, 5) : '';
    }
}

$receipt_count = count($receipts);
$detail_count  = count($records);

include __DIR__ . '/header.php';
?>
<style>
:root { --pos-font-size: 18px; }
.list-wrap { font-size: var(--pos-font-size); padding: 0; }

.summary-bar {
    background: #004d40; color: #fff;
    padding: 0.6em 1em;
    display: flex; align-items: center; flex-wrap: wrap; gap: 0.5em 1.5em;
}
.summary-bar .s-label { font-size: 0.7em; opacity:.8; display:block; }
.summary-bar .s-val   { font-size: 1.3em; font-weight: bold; }

.date-bar {
    background: #f0f4f0; border-bottom: 1px solid #c8d8c8;
    padding: 0.5em 1em;
    display: flex; align-items: center; gap: 0.6em; flex-wrap: wrap;
}
.date-bar label { font-size: 0.85em; font-weight: bold; color: #004d40; }
.date-input {
    border: 2px solid #004d40; border-radius: 0.4em;
    padding: 0.3em 0.6em; font-size: 0.9em; color: #004d40; font-weight: bold;
}
.date-btn {
    background: #004d40; color: #fff; border: none;
    border-radius: 0.4em; padding: 0.35em 1em;
    font-size: 0.85em; font-weight: bold; cursor: pointer;
}
.today-btn {
    background: #fff; color: #004d40; border: 2px solid #004d40;
    border-radius: 0.4em; padding: 0.3em 0.8em;
    font-size: 0.8em; font-weight: bold; cursor: pointer;
    text-decoration: none; white-space: nowrap;
}

.receipts-wrap { padding: 0.5em 1em 2em; }

/* レシートカード */
.receipt-card {
    border: 2px solid #ddd;
    border-radius: 0.6em;
    margin-bottom: 0.5em;
    overflow: hidden;
    background: #fff;
}
.receipt-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5em 0.8em;
    cursor: pointer;
    user-select: none;
    background: #fff;
    transition: background .15s;
    gap: 0.5em;
}
.receipt-header:hover { background: #f5faf5; }
.receipt-header.open  { background: #e8f5e9; border-bottom: 2px solid #c8d8c8; }

.rh-left  { display:flex; align-items:center; gap: 0.6em; flex:1; min-width:0; }
.rh-no    { font-size: 0.7em; color: #888; white-space: nowrap; }
.rh-time  { font-size: 0.9em; font-weight: bold; color: #004d40; white-space: nowrap; }
.rh-items { font-size: 0.8em; color: #555; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.rh-total { font-size: 1em; font-weight: bold; color: #c62828; white-space: nowrap; }
.rh-arrow { font-size: 0.8em; color: #888; transition: transform .2s; flex-shrink:0; }
.receipt-header.open .rh-arrow { transform: rotate(180deg); }

/* 訂正ボタン */
.btn-edit {
    flex-shrink: 0;
    background: #c62828;
    color: #fff;
    border: none;
    border-radius: 0.4em;
    padding: 0.35em 0.85em;
    font-size: 0.85em;
    font-weight: bold;
    cursor: pointer;
    white-space: nowrap;
    transition: all .15s;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 0.25em;
    box-shadow: 0 1px 3px rgba(0,0,0,.2);
}
.btn-edit:hover { background: #b71c1c; }

/* 明細テーブル */
.detail-table-wrap { display: none; padding: 0.3em 0.5em 0.5em; }
.detail-table-wrap.open { display: block; }
.detail-table {
    width: 100%; border-collapse: collapse; font-size: 0.85em;
}
.detail-table th {
    background: #f0f4f0; color: #555;
    padding: 0.3em 0.6em; text-align: left;
    font-weight: bold; font-size: 0.9em;
    border-bottom: 1px solid #ddd;
}
.detail-table th.right { text-align: right; }
.detail-table td {
    padding: 0.4em 0.6em;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}
.detail-table td.right { text-align: right; }
.bumon-badge {
    display: inline-block; background: #e8f5e9; color: #004d40;
    font-size: 0.8em; padding: 1px 6px; border-radius: 1em; font-weight: bold;
}
.nebiki-text { color: #e53935; font-size: 0.85em; }
.kingaku-text { font-weight: bold; color: #c62828; }

.detail-subtotal {
    text-align: right; padding: 0.4em 0.6em;
    font-weight: bold; color: #c62828;
    border-top: 1px solid #004d40;
    font-size: 0.9em;
}

.empty-msg { text-align: center; padding: 3em 1em; color: #888; font-size: 0.9em; }

/* 全体合計行 */
.grand-total {
    background: #004d40; color: #fff;
    border-radius: 0.6em;
    padding: 0.6em 1em;
    display: flex; justify-content: space-between; align-items: center;
    margin-top: 0.5em;
    font-size: 1em; font-weight: bold;
}
.grand-total .amount { font-size: 1.3em; }
</style>

<div class="list-wrap">

  <!-- サマリーバー -->
  <div class="summary-bar">
    <div>
      <span class="s-label">表示日</span>
      <span class="s-val" style="font-size:1em;">
        <?= htmlspecialchars($selected_date_jp) ?>
        <?php if ($is_today): ?>
          <span style="font-size:.65em; background:#00897b; padding:1px 8px; border-radius:1em; margin-left:.3em;">本日</span>
        <?php endif; ?>
      </span>
    </div>
    <div>
      <span class="s-label">売上合計</span>
      <span class="s-val">¥<?= number_format($total) ?></span>
    </div>
    <div>
      <span class="s-label">レシート</span>
      <span class="s-val"><?= $receipt_count ?> 枚</span>
    </div>
    <div>
      <span class="s-label">明細</span>
      <span class="s-val"><?= $detail_count ?> 件</span>
    </div>
    <div style="margin-left:auto;">
      <span class="s-label"><?= htmlspecialchars($store_name) ?></span>
    </div>
  </div>

  <!-- 日付選択 -->
  <div class="date-bar">
    <label>日付：</label>
    <form method="get" style="display:flex; align-items:center; gap:.5em; flex-wrap:wrap;">
      <input type="date" name="date" class="date-input"
             value="<?= htmlspecialchars($selected_date_raw) ?>"
             max="<?= date('Y-m-d') ?>">
      <button type="submit" class="date-btn">表示</button>
    </form>
    <?php if (!$is_today): ?>
      <a href="sales_list.php" class="today-btn">← 本日</a>
    <?php endif; ?>
  </div>

  <?php if ($edited_receipt): ?>
    <div class="alert alert-success py-2 px-3 mx-3 mt-2" style="font-size:.9em;">
      ✅ レシート No.<?= htmlspecialchars($edited_receipt) ?> を訂正しました。
    </div>
  <?php endif; ?>

  <!-- レシート一覧 -->
  <div class="receipts-wrap">
    <?php if (empty($receipts)): ?>
      <div class="empty-msg"><?= htmlspecialchars($selected_date_jp) ?> の売上データはありません。</div>
    <?php else: ?>

      <?php $r_idx = 0; foreach ($receipts as $rno => $receipt): $r_idx++; ?>
        <div class="receipt-card">

          <!-- レシートヘッダー（クリックで展開） -->
          <div class="receipt-header <?= $rno !== 'no_receipt' ? 'has-edit' : '' ?>"
               onclick="toggleReceipt(<?= $r_idx ?>)">
            <div class="rh-left">
              <span class="rh-no">No.<?= htmlspecialchars($rno) ?></span>
              <span class="rh-time"><?= htmlspecialchars($receipt['time']) ?></span>
              <span class="rh-items">
                <?= implode('・', array_map(fn($it) => htmlspecialchars($it['name']), $receipt['items'])) ?>
              </span>
            </div>
            <span class="rh-total">¥<?= number_format($receipt['total']) ?></span>
            <?php if ($rno !== 'no_receipt'): ?>
              <?php
                $edit_data = json_encode([
                    'receipt_no' => $rno,
                    'record_ids' => $receipt['record_ids'],
                    'items'      => $receipt['items'],
                    'date'       => $selected_date_raw,
                ]);
              ?>
              <a class="btn-edit" href="sales_edit.php?data=<?= urlencode($edit_data) ?>"
                 onclick="event.stopPropagation();">
                ✏ 訂正
              </a>
            <?php endif; ?>
            <span class="rh-arrow">▼</span>
          </div>

          <!-- 明細（折りたたみ） -->
          <div class="detail-table-wrap" id="detail-<?= $r_idx ?>">
            <table class="detail-table">
              <thead>
                <tr>
                  <th>商品名</th>
                  <th>部門</th>
                  <th class="right">数量</th>
                  <th class="right">値引き</th>
                  <th class="right">販売金額</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($receipt['items'] as $item):
                  $neb_str = '';
                  if ($item['nebiki_gaku']  > 0) $neb_str = '-¥' . number_format($item['nebiki_gaku']);
                  if ($item['nebiki_ritsu'] > 0) $neb_str = '-' . $item['nebiki_ritsu'] . '%';
                ?>
                <tr>
                  <td><?= htmlspecialchars($item['name']) ?></td>
                  <td><span class="bumon-badge"><?= htmlspecialchars($item['bumon']) ?></span></td>
                  <td class="right"><?= $item['qty'] ?></td>
                  <td class="right"><span class="nebiki-text"><?= htmlspecialchars($neb_str) ?></span></td>
                  <td class="right kingaku-text">¥<?= number_format($item['kingaku']) ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
            <div class="detail-subtotal">
              小計（<?= count($receipt['items']) ?>点）　¥<?= number_format($receipt['total']) ?>
            </div>
          </div>

        </div>
      <?php endforeach; ?>

      <!-- 全体合計 -->
      <div class="grand-total">
        <span>合計（<?= $receipt_count ?>枚 / <?= $detail_count ?>件）</span>
        <span class="amount">¥<?= number_format($total) ?></span>
      </div>

    <?php endif; ?>
  </div>

</div>

<script>
function toggleReceipt(idx) {
    const header = document.querySelector('.receipt-header[onclick="toggleReceipt(' + idx + ')"]');
    const detail = document.getElementById('detail-' + idx);
    const isOpen = detail.classList.contains('open');
    detail.classList.toggle('open', !isOpen);
    header.classList.toggle('open', !isOpen);
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
