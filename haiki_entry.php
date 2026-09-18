<?php
/**
 * haiki_entry.php  廃棄数入力画面
 * ・商品単位・1日1回まとめて入力（daily_report_entry.php と同じ日付ナビ運用）
 * ・廃棄が発生した商品のみ haiki_API に保存（0件の商品はレコードを作らない）
 */
use fmRESTor\fmRESTor;
session_start();
if (!isset($_SESSION['store_id'])) { header('Location: login.php'); exit(); }
if (($_SESSION['role'] ?? '') === 'hq') { header('Location: hq_top.php'); exit(); }

require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/bumon_master.php';

$store_id   = $_SESSION['store_id'];
$store_name = $_SESSION['store_name'];

// ---- 対象日付 ----
$target_date_fm = $_GET['date'] ?? date('m/d/Y');
$dt = \DateTime::createFromFormat('m/d/Y', $target_date_fm) ?: new \DateTime();
$week_ja = ['Sunday'=>'日','Monday'=>'月','Tuesday'=>'火','Wednesday'=>'水',
            'Thursday'=>'木','Friday'=>'金','Saturday'=>'土'];
$target_date_jp = $dt->format('Y年n月j日') . '（' . ($week_ja[$dt->format('l')] ?? '') . '）';

$today_dt  = new DateTime(); $today_dt->setTime(0,0,0);
$dt_clone  = clone $dt;     $dt_clone->setTime(0,0,0);
$is_future_page = ($dt_clone > $today_dt);
$prev_dt   = (clone $dt_clone)->modify('-1 day');
$next_dt   = (clone $dt_clone)->modify('+1 day');
$max_nav_dt      = (clone $today_dt)->modify('+30 days');
$next_is_too_far = ($next_dt > $max_nav_dt);
$prev_date_fm  = $prev_dt->format('m/d/Y');
$next_date_fm  = $next_dt->format('m/d/Y');
$date_html     = $dt_clone->format('Y-m-d');
$date_html_max = $max_nav_dt->format('Y-m-d');

// ---- 部門マスタ（並び順） ----
$bumon_master   = fetch_bumon_master($host, $db, $layout_bumon, $api_master_user, $api_master_pass);
$category_order = bumon_names($bumon_master);

// ---- 自店の取扱商品一覧（hanbai_API、sales_entry.php と同じ絞り込み方式） ----
$fmHanbai = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$resHanbai = $fmHanbai->getRecords(['_limit' => 1000]);
$products = [];
foreach ($resHanbai['result']['response']['data'] ?? [] as $row) {
    $f = $row['fieldData'];
    $n = trim($f['商品名'] ?? '');
    if ($n === '') continue;
    if ((int)($f['発売中'] ?? 0) !== 1) continue;
    $positions = getStorePositions($f);
    if (!in_array($store_id, $positions, true)) continue;

    $products[] = [
        'bumon' => trim($f['部門'] ?? ''),
        'name'  => $n,
        'yomi'  => trim($f['よみがな'] ?? ''),
    ];
}
$cat_pos = array_flip($category_order);
usort($products, function($a, $b) use ($cat_pos) {
    $pa = $cat_pos[$a['bumon']] ?? 999;
    $pb = $cat_pos[$b['bumon']] ?? 999;
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['yomi'], $b['yomi']);
});

// ---- 当日の既存 haiki_API レコード（商品名 => ['recordId'=>, 'count'=>]） ----
$fmHaiki = new fmRESTor($host, $db, $layout_haiki, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$qr = $fmHaiki->findRecords([
    'query' => [['fk_店舗No' => $store_id, '売上日' => $target_date_fm]],
    'limit' => 1000,
]);
$existing = []; // 商品名 => ['recordId'=>, 'count'=>]
if (($qr['result']['messages'][0]['code'] ?? '0') !== '401') {
    foreach ($qr['result']['response']['data'] ?? [] as $row) {
        $f = $row['fieldData'];
        $n = trim($f['商品名'] ?? '');
        if ($n === '') continue;
        $existing[$n] = ['recordId' => $row['recordId'], 'count' => (int)($f['廃棄数'] ?? 0)];
    }
}

// ---- POST 保存 ----
$success_msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_haiki' && !$is_future_page) {
    $pnames = $_POST['pname'] ?? [];
    $counts = $_POST['haiki'] ?? [];
    $fail = 0;
    foreach ($pnames as $i => $pname) {
        $pname = trim((string)$pname);
        if ($pname === '') continue;
        $val = (int)($counts[$i] ?? 0);
        $ex  = $existing[$pname] ?? null;

        if ($val > 0) {
            if ($ex) {
                $res = $fmHaiki->editRecord($ex['recordId'], ['fieldData' => ['廃棄数' => $val]]);
            } else {
                $res = $fmHaiki->createRecord(['fieldData' => [
                    'fk_店舗No' => $store_id,
                    '店舗名'    => $store_name,
                    '売上日'    => $target_date_fm,
                    '商品名'    => $pname,
                    '廃棄数'    => $val,
                ]]);
            }
            if ($fmHaiki->isError($res)) $fail++;
        } elseif ($ex) {
            // 0件に戻した場合はレコードごと削除（廃棄が発生した商品のみ保存する方針）
            $res = $fmHaiki->deleteRecord($ex['recordId']);
            if ($fmHaiki->isError($res)) $fail++;
        }
    }

    if ($fail === 0) {
        $success_msg = '💾 保存しました。';
        // 保存後の状態を再取得
        $qr2 = $fmHaiki->findRecords([
            'query' => [['fk_店舗No' => $store_id, '売上日' => $target_date_fm]],
            'limit' => 1000,
        ]);
        $existing = [];
        if (($qr2['result']['messages'][0]['code'] ?? '0') !== '401') {
            foreach ($qr2['result']['response']['data'] ?? [] as $row) {
                $f = $row['fieldData'];
                $n = trim($f['商品名'] ?? '');
                if ($n === '') continue;
                $existing[$n] = ['recordId' => $row['recordId'], 'count' => (int)($f['廃棄数'] ?? 0)];
            }
        }
    } else {
        $success_msg = '❌ 一部の保存に失敗しました（' . $fail . '件）。';
    }
}

function hv(array $existing, string $name): string {
    $v = $existing[$name]['count'] ?? 0;
    return $v > 0 ? (string)$v : '';
}

include __DIR__ . '/header.php';
?>

<style>
:root { --pos-font-size: 17px; }
.hk-wrap {
    font-size: var(--pos-font-size);
    max-width: 680px;
    margin: 0 auto;
    padding: 0 0.4em 3em;
}
.hk-header {
    text-align: center;
    padding: 0.7em 0 0.5em;
    border-bottom: 2px solid #e0e0e0;
    margin-bottom: 0.8em;
}
.hk-header .store-name { font-size: 1.1em; font-weight: bold; color: #b71c1c; }
.hk-header .target-date { font-size: 0.82em; color: #888; margin-top: 0.2em; }

.date-nav {
    display: flex; align-items: center; justify-content: center;
    gap: 0.4em; margin-top: 0.5em;
}
.date-nav-btn {
    background: #b71c1c; color: #fff;
    border: none; border-radius: 0.4em;
    padding: 0.25em 0.7em; font-size: 1em; font-weight: bold;
    cursor: pointer; text-decoration: none; line-height: 1.6;
}
.date-nav-btn:hover { background: #c62828; color: #fff; }
.date-nav-btn.disabled { background: #ccc; cursor: default; pointer-events: none; }
.date-input-wrap input[type=date] {
    font-size: 0.88em; padding: 0.25em 0.5em;
    border: 2px solid #b71c1c; border-radius: 0.4em;
    color: #b71c1c; font-weight: bold; cursor: pointer; background: #fff5f5;
}

.hk-bumon-head {
    background: #b71c1c; color: #fff;
    padding: 0.35em 0.8em; margin-top: 0.8em;
    border-radius: 0.5em; font-size: 0.85em; font-weight: bold;
}
.hk-row {
    display: grid;
    grid-template-columns: 1fr 4.5em;
    align-items: center;
    gap: 0.4em;
    padding: 0.45em 0.3em;
    border-bottom: 1px solid #f0e0e0;
}
.hk-row .hk-name { font-size: 0.9em; color: #333; }
.hk-row .hk-input-wrap { display: flex; align-items: center; gap: 0.2em; justify-content: flex-end; }
.hk-input {
    width: 3.2em; font-size: 1.1em; font-weight: bold; text-align: right;
    padding: 0.25em 0.4em; border: 2px solid #ccc; border-radius: 0.4em;
    background: #fafafa; color: #b71c1c;
}
.hk-input:focus { outline: none; border-color: #b71c1c; background: #fff; }
.hk-unit { font-size: 0.75em; color: #888; }

.hk-total-bar {
    display: flex; justify-content: space-between; align-items: center;
    padding: 0.6em 0.3em 0; border-top: 2px solid #b71c1c; margin-top: 0.8em;
}
.hk-total-bar .t-label { font-size: 0.9em; font-weight: bold; color: #b71c1c; }
.hk-total-bar .t-val { font-size: 1.3em; font-weight: bold; color: #b71c1c; }

.save-btn {
    display: block; width: 100%;
    padding: 0.65em; margin-top: 0.8em;
    background: #b71c1c; color: #fff;
    border: none; border-radius: 0.5em;
    font-size: 0.95em; font-weight: bold; cursor: pointer;
}
.save-btn:hover { background: #c62828; }

.spec-note {
    font-size: 0.76em; color: #555; background: #fffde7;
    border-left: 3px solid #f9a825; border-radius: 0 0.4em 0.4em 0;
    padding: 0.45em 0.7em; margin-top: 0.6em; line-height: 1.6;
}
.spec-note .spec-note-title { font-weight: bold; color: #e65100; margin-bottom: 0.2em; }
.spec-note ul { margin: 0.2em 0 0 1.2em; padding: 0; }
</style>

<div class="hk-wrap">
  <div class="hk-header">
    <div class="store-name">🗑 廃棄数入力</div>
    <div class="target-date">
      <?= htmlspecialchars($store_name) ?> ／ <?= htmlspecialchars($target_date_jp) ?>
    </div>
    <div class="date-nav">
      <a class="date-nav-btn" href="?date=<?= urlencode($prev_date_fm) ?>">◀</a>
      <span class="date-input-wrap">
        <input type="date" id="date-picker" value="<?= $date_html ?>"
               max="<?= $date_html_max ?>" onchange="goDate(this.value)">
      </span>
      <a class="date-nav-btn <?= $next_is_too_far ? 'disabled' : '' ?>"
         href="?date=<?= urlencode($next_date_fm) ?>">▶</a>
    </div>
  </div>

  <?php if ($success_msg): ?>
    <div class="alert <?= str_starts_with($success_msg, '❌') ? 'alert-danger' : 'alert-success' ?> py-2 text-center mb-2" style="font-size:.88em;"><?= $success_msg ?></div>
  <?php endif; ?>

  <div class="spec-note">
    <div class="spec-note-title">ℹ 廃棄数入力について</div>
    <ul>
      <li>その日に売場の陳列ケースから廃棄した商品の数量を、商品ごとに入力してください。</li>
      <li>廃棄が無かった商品は空欄のままで構いません（0件として扱われます）。</li>
      <li>1日1回、まとめての入力を想定しています。</li>
    </ul>
  </div>

  <?php if ($is_future_page): ?>
    <div class="alert alert-info py-2 text-center mb-2" style="font-size:.88em;">
      📅 この日付はまだ到来していません。入力は当日以降に行ってください。
    </div>
  <?php endif; ?>

  <form method="post" id="form-haiki">
    <input type="hidden" name="action" value="save_haiki">
    <?php
    $cur_bumon = null;
    foreach ($products as $i => $p):
        if ($p['bumon'] !== $cur_bumon):
            $cur_bumon = $p['bumon'];
    ?>
    <div class="hk-bumon-head">■ <?= htmlspecialchars($cur_bumon !== '' ? $cur_bumon : '(部門未設定)') ?></div>
    <?php endif; ?>
    <div class="hk-row">
      <span class="hk-name"><?= htmlspecialchars($p['name']) ?></span>
      <div class="hk-input-wrap">
        <input type="hidden" name="pname[<?= $i ?>]" value="<?= htmlspecialchars($p['name']) ?>">
        <input class="hk-input haiki-input" type="number" name="haiki[<?= $i ?>]"
               inputmode="numeric" min="0" value="<?= hv($existing, $p['name']) ?>"
               <?= $is_future_page ? 'disabled' : '' ?>>
        <span class="hk-unit">個</span>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="hk-total-bar">
      <span class="t-label">廃棄合計</span>
      <span class="t-val" id="haiki-goukei">0 個</span>
    </div>

    <?php if (!$is_future_page): ?>
      <button type="submit" class="save-btn">💾 保存する</button>
    <?php endif; ?>
  </form>
</div>

<script>
function calcGoukei() {
    let total = 0;
    document.querySelectorAll('.haiki-input').forEach(el => {
        total += parseInt(el.value || 0, 10);
    });
    document.getElementById('haiki-goukei').textContent = total.toLocaleString() + ' 個';
}
document.querySelectorAll('.haiki-input').forEach(el => el.addEventListener('input', calcGoukei));
calcGoukei();

function goDate(val) {
    if (!val) return;
    const parts = val.split('-');
    if (parts.length !== 3) return;
    const fm = parts[1] + '/' + parts[2] + '/' + parts[0];
    location.href = '?date=' + encodeURIComponent(fm);
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
