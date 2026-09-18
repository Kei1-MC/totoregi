<?php
/**
 * sales_confirm.php
 * 売上登録確認画面（レシートイメージ）
 * sales_entry.php からセッション経由でデータを受け取る
 */
require_once __DIR__ . '/session_config.php';
session_start();

if (!isset($_SESSION['store_id']) || !isset($_SESSION['last_sale'])) {
    header('Location: sales_entry.php');
    exit();
}

$sale       = $_SESSION['last_sale'];
$items      = $sale['items']      ?? [];
$store_name = $sale['store_name'] ?? '';
$date       = $sale['date']       ?? '';

// 合計金額
$total = array_reduce($items, fn($s, $it) => $s + $it['subtotal'], 0);
$count = count($items);

// 確認後はセッションから削除（F5 再表示防止）
unset($_SESSION['last_sale']);

include __DIR__ . '/header.php';
?>
<style>
:root { --pos-font-size: 20px; }

.confirm-wrap {
    font-size: var(--pos-font-size);
    display: flex;
    flex-direction: column;
    align-items: center;
    padding: 1em;
    min-height: calc(100vh - 80px);
    background: #f0f4f0;
}

/* レシート */
.receipt {
    background: #fff;
    border-radius: 0.6em;
    box-shadow: 0 4px 16px rgba(0,0,0,.12);
    width: 100%;
    max-width: 480px;
    padding: 1.5em 1.5em 1em;
    margin-bottom: 1.5em;
    position: relative;
}
/* レシート上部のギザギザ */
.receipt::before {
    content: '';
    display: block;
    height: 16px;
    background:
        radial-gradient(circle at 8px -4px, transparent 10px, #f0f4f0 11px) top left / 16px 16px repeat-x;
    position: absolute;
    top: -14px; left: 0; right: 0;
}
/* レシート下部のギザギザ */
.receipt::after {
    content: '';
    display: block;
    height: 16px;
    background:
        radial-gradient(circle at 8px 20px, transparent 10px, #f0f4f0 11px) bottom left / 16px 16px repeat-x;
    position: absolute;
    bottom: -14px; left: 0; right: 0;
}

.receipt-header {
    text-align: center;
    border-bottom: 1px dashed #ccc;
    padding-bottom: 0.8em;
    margin-bottom: 0.8em;
}
.receipt-header .store { font-size: 1.1em; font-weight: bold; color: #004d40; }
.receipt-header .date  { font-size: 0.7em; color: #888; margin-top: 0.2em; }
.receipt-header .badge-ok {
    display: block;
    font-size: 1.1em;
    font-weight: bold;
    color: #222;
    margin-top: 0.4em;
}

.rcpt-not-receipt {
    font-size: 0.72em; color: #555;
    margin-top: 0.1em;
}
.receipt-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.9em;
    margin-bottom: 0.8em;
}
.receipt-table th {
    font-size: 0.75em;
    color: #888;
    font-weight: normal;
    padding: 0.2em 0.3em;
    border-bottom: 1px solid #eee;
    text-align: left;
}
.receipt-table th:last-child { text-align: right; }
.receipt-table td {
    padding: 0.45em 0.3em;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: top;
}
.receipt-table td:last-child { text-align: right; font-weight: bold; white-space: nowrap; }
.item-name { font-weight: bold; }
.item-sub  { font-size: 0.75em; color: #888; display: block; }
.item-nebiki { font-size: 0.75em; color: #e53935; }

.receipt-total {
    border-top: 2px solid #004d40;
    padding-top: 0.6em;
    display: flex;
    justify-content: space-between;
    align-items: baseline;
}
.receipt-total .label { font-size: 0.85em; font-weight: bold; color: #555; }
.receipt-total .amount {
    font-size: 1.6em;
    font-weight: bold;
    color: #c62828;
}
.receipt-total .tax {
    font-size: 0.65em;
    color: #888;
    display: block;
    text-align: right;
}

.receipt-footer {
    text-align: center;
    margin-top: 0.8em;
    font-size: 0.7em;
    color: #aaa;
    border-top: 1px dashed #eee;
    padding-top: 0.6em;
}

/* ボタン */
.btn-next {
    background: #004d40;
    color: #fff;
    border: none;
    border-radius: 0.6em;
    padding: 0.7em 2em;
    font-size: 1.1em;
    font-weight: bold;
    cursor: pointer;
    width: 100%;
    max-width: 480px;
    transition: background .15s;
}
.btn-next:hover { background: #00695c; }
</style>

<div class="confirm-wrap">

  <!-- レシート -->
  <div class="receipt">
    <div class="receipt-header">
      <div class="store">🐟 <?= htmlspecialchars($store_name) ?></div>
      <div class="date"><?= htmlspecialchars($date) ?></div>
      <div class="badge-ok">明細書</div>
      <div class="rcpt-not-receipt">領収書ではありません</div>
    </div>

    <table class="receipt-table">
      <thead>
        <tr>
          <th>商品</th>
          <th style="text-align:center;">数量</th>
          <th>金額</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($items as $item):
            $neb_str = '';
            if (($item['nebiki_gaku']  ?? 0) > 0) $neb_str = '値引 ¥' . number_format($item['nebiki_gaku']);
            if (($item['nebiki_ritsu'] ?? 0) > 0) $neb_str = '値引 ' . $item['nebiki_ritsu'] . '%';
        ?>
        <tr>
          <td>
            <span class="item-name"><?= htmlspecialchars($item['name']) ?></span>
            <span class="item-sub"><?= htmlspecialchars($item['tani'] ?? '') ?></span>
            <?php if ($neb_str): ?>
              <span class="item-nebiki">▼ <?= htmlspecialchars($neb_str) ?></span>
            <?php endif; ?>
          </td>
          <td style="text-align:center;">× <?= (int)$item['qty'] ?></td>
          <td>¥<?= number_format((int)$item['subtotal']) ?></td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="receipt-total">
      <span class="label">合　計</span>
      <div>
        <span class="amount">¥<?= number_format($total) ?></span>
        <span class="tax">（本体価格）</span>
      </div>
    </div>

    <div class="receipt-footer">
      ありがとうございました
    </div>
  </div>

  <!-- 次のお客様へボタン -->
  <button class="btn-next" onclick="location.href='sales_entry.php'">
    次のお客様へ →
  </button>

</div>

<?php include __DIR__ . '/footer.php'; ?>
