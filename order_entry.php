<?php
/**
 * order_entry.php  発注入力画面（店舗 → 本社・工場）
 * ・納品日ごと／便ごとに、発注可能商品（hq_order_items.php で本社が管理）の数量を入力
 * ・便の締め時刻を過ぎたら編集不可（サーバー側でも拒否する）
 * ・本社が「確定」した発注は店舗側では編集できない
 * ・数量0（空欄）の商品はレコードを作らない。0に戻した場合は削除
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['store_id'])) { header('Location: login.php'); exit(); }
if (($_SESSION['role'] ?? '') === 'hq') { header('Location: hq_order_list.php'); exit(); }

require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/order_lib.php';

$store_id   = $_SESSION['store_id'];
$store_name = $_SESSION['store_name'];

// ---- 納品日（既定は翌日。今日〜ORDER_MAX_DAYS_AHEAD日先まで） ----
$today = new DateTime('today');
$max   = (clone $today)->modify('+' . ORDER_MAX_DAYS_AHEAD . ' days');
$dt    = order_parse_date($_GET['date'] ?? $_POST['date'] ?? null) ?: (clone $today)->modify('+1 day');
if ($dt < $today) $dt = clone $today;
if ($dt > $max)   $dt = clone $max;
$date_fm   = $dt->format('m/d/Y');
$week_ja   = ['日','月','火','水','木','金','土'];
$date_jp   = $dt->format('Y年n月j日') . '（' . $week_ja[(int)$dt->format('w')] . '）';
$prev_fm   = (clone $dt)->modify('-1 day')->format('m/d/Y');
$next_fm   = (clone $dt)->modify('+1 day')->format('m/d/Y');
$can_prev  = ($dt > $today);
$can_next  = ($dt < $max);

$bins   = order_fetch_bins();
$items  = order_fetch_items($store_id);
$orders = order_fetch_orders($store_id, $date_fm);

// ---- POST 保存 ----
$msg = ''; $msg_class = 'alert-success';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_order') {
    if (!order_csrf_ok($_POST['csrf'] ?? null)) {
        $msg = '❌ 画面の有効期限が切れました。もう一度開いてから入力してください。'; $msg_class = 'alert-danger';
    } else {
        $fmOrder = order_fm($layout_order);
        $qtys    = $_POST['qty'] ?? [];          // qty[便ID][商品No] => 数量
        $now_fm  = date('m/d/Y H:i:s');
        $saved = $skipped_closed = $skipped_locked = $bad = $fail = 0;
        $by_key = [];
        foreach ($items as $it) $by_key[$it['bin_id'] . ':' . $it['pno']] = $it;

        foreach ($by_key as $key => $it) {
            $raw = $qtys[$it['bin_id']][$it['pno']] ?? null;
            if ($raw === null) continue;                       // 画面に出ていない商品
            $qty = order_parse_qty($raw);
            if ($qty === null) { $bad++; continue; }
            $ex  = $orders[$key] ?? null;
            if (($ex['qty'] ?? 0) === $qty) continue;          // 変更なし

            $bin = $bins[$it['bin_id']] ?? null;
            if (!$bin || !order_bin_is_open($bin, $dt)) { $skipped_closed++; continue; }
            if ($ex && $ex['state'] !== ORDER_STATE_OPEN) { $skipped_locked++; continue; }

            if ($qty > 0 && $ex) {
                $res = $fmOrder->editRecord($ex['record_id'], ['fieldData' => ['数量' => $qty, '更新日時' => $now_fm]]);
            } elseif ($qty > 0) {
                $res = $fmOrder->createRecord(['fieldData' => [
                    'fk_店舗No' => $store_id,
                    '店舗名'    => $store_name,
                    '納品日'    => $date_fm,
                    '便ID'      => $it['bin_id'],
                    '便名'      => $bin['name'],
                    '販売部門'  => $it['bumon'],
                    '商品No'    => $it['pno'],
                    '品名'      => $it['name'],
                    '数量'      => $qty,
                    '状態'      => ORDER_STATE_OPEN,
                    '作成日時'  => $now_fm,
                    '更新日時'  => $now_fm,
                ]]);
            } elseif ($ex) {
                $res = $fmOrder->deleteRecord($ex['record_id']);
            } else {
                continue;
            }
            if ($fmOrder->isError($res)) $fail++; else $saved++;
        }

        $orders = order_fetch_orders($store_id, $date_fm);     // 保存後の状態を再取得
        $notes = [];
        if ($skipped_closed) $notes[] = "締切済みの便の {$skipped_closed} 件は変更できませんでした";
        if ($skipped_locked) $notes[] = "本社で確定済みの {$skipped_locked} 件は変更できませんでした";
        if ($bad)            $notes[] = "数量が正しくない {$bad} 件は保存していません（0〜" . ORDER_MAX_QTY . "の整数）";
        if ($fail) {
            $msg = "❌ {$fail} 件の保存に失敗しました。もう一度保存してください。" . ($notes ? '（' . implode('／', $notes) . '）' : '');
            $msg_class = 'alert-danger';
        } elseif ($notes) {
            $msg = "⚠ {$saved} 件を保存しました。" . implode('／', $notes) . '。';
            $msg_class = 'alert-warning';
        } else {
            $msg = $saved > 0 ? "💾 {$saved} 件を保存しました。" : '変更はありませんでした。';
        }
    }
}

// ---- 便ごとにまとめる ----
$grouped = []; // bin_id => bumon => [items]
foreach ($items as $it) $grouped[$it['bin_id']][$it['bumon']][] = $it;
ksort($grouped);

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
include __DIR__ . '/header.php';
?>

<style>
.od-wrap { font-size: 17px; max-width: 680px; margin: 0 auto; padding: 0 0.4em 3em; }
.od-header { text-align: center; padding: 0.7em 0 0.5em; border-bottom: 2px solid #e0e0e0; margin-bottom: 0.8em; }
.od-header .od-title { font-size: 1.1em; font-weight: bold; color: #004d40; }
.od-header .od-sub { font-size: 0.82em; color: #888; margin-top: 0.2em; }
.date-nav { display: flex; align-items: center; justify-content: center; gap: 0.4em; margin-top: 0.5em; }
.date-nav-btn { background: #004d40; color: #fff; border-radius: 0.4em; padding: 0.25em 0.7em; font-weight: bold; text-decoration: none; line-height: 1.6; }
.date-nav-btn.disabled { background: #ccc; pointer-events: none; }
.date-nav input[type=date] { font-size: 0.88em; padding: 0.25em 0.5em; border: 2px solid #004d40; border-radius: 0.4em; color: #004d40; font-weight: bold; }
.od-bin-head { display: flex; justify-content: space-between; align-items: center; background: #004d40; color: #fff;
               padding: 0.4em 0.8em; margin-top: 1em; border-radius: 0.5em; font-size: 0.9em; font-weight: bold; }
.od-bin-head.closed { background: #757575; }
.od-bin-head .od-cut { font-size: 0.8em; font-weight: normal; }
.od-bumon { font-size: 0.8em; font-weight: bold; color: #004d40; border-left: 3px solid #004d40; padding-left: 0.5em; margin: 0.7em 0 0.2em; }
.od-row { display: grid; grid-template-columns: 1fr auto; align-items: center; gap: 0.4em; padding: 0.45em 0.3em; border-bottom: 1px solid #e0ece9; }
.od-row .od-name { font-size: 0.9em; color: #333; }
.od-row .od-lock { font-size: 0.7em; color: #e65100; margin-left: 0.4em; }
.od-input { width: 3.6em; font-size: 1.1em; font-weight: bold; text-align: right; padding: 0.25em 0.4em;
            border: 2px solid #ccc; border-radius: 0.4em; background: #fafafa; color: #004d40; }
.od-input:focus { outline: none; border-color: #004d40; background: #fff; }
.od-input:disabled { background: #eee; color: #888; }
.od-total { display: flex; justify-content: space-between; padding: 0.6em 0.3em 0; border-top: 2px solid #004d40; margin-top: 0.8em; font-weight: bold; color: #004d40; }
.save-btn { display: block; width: 100%; padding: 0.65em; margin-top: 0.8em; background: #004d40; color: #fff; border: none; border-radius: 0.5em; font-size: 0.95em; font-weight: bold; }
.save-btn:hover { background: #00695c; }
.spec-note { font-size: 0.76em; color: #555; background: #fffde7; border-left: 3px solid #f9a825; border-radius: 0 0.4em 0.4em 0; padding: 0.45em 0.7em; margin-top: 0.6em; line-height: 1.6; }
</style>

<div class="od-wrap">
  <div class="od-header">
    <div class="od-title">📦 発注入力</div>
    <div class="od-sub"><?= $h($store_name) ?> ／ 納品日 <?= $h($date_jp) ?></div>
    <div class="date-nav">
      <a class="date-nav-btn <?= $can_prev ? '' : 'disabled' ?>" href="?date=<?= urlencode($prev_fm) ?>">◀</a>
      <input type="date" value="<?= $dt->format('Y-m-d') ?>" min="<?= $today->format('Y-m-d') ?>"
             max="<?= $max->format('Y-m-d') ?>" onchange="goDate(this.value)">
      <a class="date-nav-btn <?= $can_next ? '' : 'disabled' ?>" href="?date=<?= urlencode($next_fm) ?>">▶</a>
    </div>
    <div class="mt-2"><a href="order_history.php" style="font-size:.8em;">🗂 発注履歴を見る</a></div>
  </div>

  <?php if ($msg): ?>
    <div class="alert <?= $msg_class ?> py-2 text-center mb-2" style="font-size:.88em;"><?= $h($msg) ?></div>
  <?php endif; ?>

  <div class="spec-note">
    入力した日付が<strong>納品を希望する日</strong>です。便ごとの締め時刻を過ぎると変更できません。
    変更が必要なときは本社へご連絡ください。数量が空欄の商品は発注なしとして扱います。
  </div>

  <?php if (!$items): ?>
    <div class="alert alert-info mt-3" style="font-size:.9em;">
      この店舗の発注可能商品がまだ登録されていません。本社にご連絡ください。
    </div>
  <?php else: ?>
  <form method="post" id="form-order">
    <input type="hidden" name="action" value="save_order">
    <input type="hidden" name="date" value="<?= $h($date_fm) ?>">
    <input type="hidden" name="csrf" value="<?= $h(order_csrf_token()) ?>">

    <?php foreach ($grouped as $bin_id => $bumons):
        $bin  = $bins[$bin_id] ?? ['id' => $bin_id, 'name' => "便{$bin_id}", 'cutoff' => '', 'lead' => 1];
        $open = order_bin_is_open($bin, $dt);
    ?>
      <div class="od-bin-head <?= $open ? '' : 'closed' ?>">
        <span>🚚 <?= $h($bin['name']) ?></span>
        <span class="od-cut"><?= $h(order_cutoff_label($bin, $dt)) ?></span>
      </div>
      <?php foreach ($bumons as $bumon => $list): ?>
        <div class="od-bumon">■ <?= $h($bumon !== '' ? $bumon : '(部門未設定)') ?></div>
        <?php foreach ($list as $it):
            $ex     = $orders[$bin_id . ':' . $it['pno']] ?? null;
            $locked = $ex && $ex['state'] !== ORDER_STATE_OPEN;
            $qty    = ($ex['qty'] ?? 0) > 0 ? (string)$ex['qty'] : '';
        ?>
          <div class="od-row">
            <span class="od-name"><?= $h($it['name']) ?><?php if ($locked): ?><span class="od-lock">🔒<?= $h($ex['state']) ?></span><?php endif; ?></span>
            <input class="od-input" type="number" inputmode="numeric" min="0" max="<?= ORDER_MAX_QTY ?>"
                   name="qty[<?= $bin_id ?>][<?= $it['pno'] ?>]" value="<?= $h($qty) ?>"
                   <?= ($open && !$locked) ? '' : 'disabled' ?>>
          </div>
        <?php endforeach; ?>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="od-total"><span>発注数合計</span><span id="od-sum">0</span></div>
    <button type="submit" class="save-btn">💾 保存する</button>
  </form>
  <?php endif; ?>
</div>

<script>
function sumQty() {
    let t = 0;
    document.querySelectorAll('.od-input').forEach(el => { t += parseInt(el.value || 0, 10) || 0; });
    const s = document.getElementById('od-sum'); if (s) s.textContent = t.toLocaleString();
}
document.querySelectorAll('.od-input').forEach(el => el.addEventListener('input', sumQty));
sumQty();

function goDate(val) {
    const p = (val || '').split('-');
    if (p.length === 3) location.href = '?date=' + encodeURIComponent(p[1] + '/' + p[2] + '/' + p[0]);
}
// 保存し忘れ防止
let dirty = false;
document.querySelectorAll('.od-input').forEach(el => el.addEventListener('input', () => { dirty = true; }));
const f = document.getElementById('form-order');
if (f) f.addEventListener('submit', () => { dirty = false; });
window.addEventListener('beforeunload', e => { if (dirty) { e.preventDefault(); e.returnValue = ''; } });
</script>

<?php include __DIR__ . '/footer.php'; ?>
