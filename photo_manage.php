<?php
/**
 * photo_manage.php  商品写真の登録・採用（店舗・本社共通）
 * ・店舗: 自店が取扱う発売中の商品に写真を登録（候補）。自分が登録した未採用の候補は削除可。
 * ・本社: 全商品に登録でき、採用・採用解除・候補の削除を行う。採用の決定は本社のみ。
 * ・候補が1枚だけで採用が無い商品は、自動でその1枚を採用する（photo_lib.php）。
 * 採用された写真は pub/img/{商品ID}.jpg に置かれ、コーポレートサイトの商品カタログに表示される。
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['role'])) { header('Location: login.php'); exit(); }
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/catalog_lib.php';
require_once __DIR__ . '/photo_lib.php';

$is_hq      = ($_SESSION['role'] === 'hq');
$store_id   = (string)($_SESSION['store_id'] ?? '');
$store_name = (string)($_SESSION['store_name'] ?? '');
$who        = (string)($_SESSION['user'] ?? '');

/* ---- POST（JSONで返す）---- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=UTF-8');
    if (!photo_csrf_ok($_POST['csrf'] ?? null)) { echo json_encode(['ok' => false, 'error' => '画面を開き直してください']); exit; }
    $action = $_POST['action'] ?? '';
    $pid    = (string)($_POST['pid'] ?? '');
    $id     = (string)($_POST['id'] ?? '');
    switch ($action) {
        case 'upload':
            // 店舗は自店取扱の商品のみ（サーバー側でも確認）
            if (!$is_hq && !photo_store_handles($pid, $store_id)) { echo json_encode(['ok' => false, 'error' => 'この商品は自店の取扱ではありません']); exit; }
            echo json_encode(photo_add($pid, $_FILES['photo'] ?? [], $store_id, $store_name, $who));
            break;
        case 'adopt':
            if (!$is_hq) { echo json_encode(['ok' => false, 'error' => '採用は本社のみ行えます']); exit; }
            echo json_encode(['ok' => photo_adopt($pid, $id)]);
            break;
        case 'unadopt':
            if (!$is_hq) { echo json_encode(['ok' => false, 'error' => '採用の解除は本社のみ行えます']); exit; }
            photo_unadopt($pid); echo json_encode(['ok' => true]);
            break;
        case 'delete':
            echo json_encode(['ok' => photo_delete($pid, $id, $is_hq ? null : $store_id)]);
            break;
        default:
            echo json_encode(['ok' => false, 'error' => 'unknown action']);
    }
    exit;
}

/** 店舗がその商品を取り扱っているか（hanbai_API のrecordIdで取得して確認） */
function photo_store_handles(string $pid, string $store_id): bool {
    global $host, $db, $layout_hanbai, $api_master_user, $api_master_pass;
    if (!photo_valid_pid($pid) || $store_id === '') return false;
    $fm = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
    $r  = $fm->getRecord((int)$pid);
    $f  = $r['result']['response']['data'][0]['fieldData'] ?? null;
    return $f !== null && in_array($store_id, getStorePositions($f), true);
}

/* ---- 表示用の商品一覧 ---- */
$fm  = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$res = $fm->getRecords(['_limit' => 1000]);
$items = [];
foreach ($res['result']['response']['data'] ?? [] as $row) {
    $f = $row['fieldData'];
    $n = trim((string)($f['商品名'] ?? ''));
    if ($n === '' || (int)($f['発売中'] ?? 1) !== 1) continue;
    if (!$is_hq && !in_array($store_id, getStorePositions($f), true)) continue;
    $pid  = (string)$row['recordId'];
    $meta = photo_meta($pid);
    $items[] = [
        'pid' => $pid, 'name' => $n,
        'bumon' => trim((string)($f['部門'] ?? '')) ?: 'その他',
        'adopted' => $meta['adopted'], 'cands' => $meta['cands'],
    ];
}
usort($items, fn($a, $b) => [$a['bumon'], $a['name']] <=> [$b['bumon'], $b['name']]);
$bumons = array_values(array_unique(array_column($items, 'bumon')));
$n_none = count(array_filter($items, fn($i) => !$i['cands']));
$n_wait = count(array_filter($items, fn($i) => $i['cands'] && !$i['adopted']));
$csrf = photo_csrf_token();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
include __DIR__ . ($is_hq ? '/hq_header.php' : '/header.php');
?>
<style>
.ph-wrap{max-width:760px;margin:0 auto;padding:0 .4em 3em;font-size:16px}
.ph-title{font-weight:bold;color:#004d40;font-size:1.15em;margin:.8em 0 .2em}
.ph-sub{color:#777;font-size:.85em}
.ph-filter{display:flex;flex-wrap:wrap;gap:.4em;margin:.8em 0}
.ph-filter select,.ph-filter input{padding:.35em .5em;border:1px solid #ccc;border-radius:.4em}
.ph-item{border:1px solid #e0e0e0;border-radius:.6em;padding:.6em;margin:.6em 0;background:#fff}
.ph-item .nm{font-weight:bold}.ph-item .bm{font-size:.75em;color:#004d40;margin-left:.5em}
.ph-row{display:flex;flex-wrap:wrap;gap:.5em;margin-top:.5em}
.ph-c{width:110px;font-size:.72em;text-align:center}
.ph-c img{width:110px;height:82px;object-fit:cover;border-radius:.4em;border:2px solid #ddd;display:block}
.ph-c.ad img{border-color:#c62828}
.ph-c .tag{display:inline-block;background:#c62828;color:#fff;border-radius:999px;padding:0 .6em;margin-top:.2em}
.ph-c .who{color:#888;margin:.15em 0}
.ph-c button{font-size:.95em;margin:.1em;padding:.15em .5em;border:1px solid #004d40;background:#fff;color:#004d40;border-radius:.3em}
.ph-c button.del{border-color:#c62828;color:#c62828}
.ph-none{width:110px;height:82px;border:2px dashed #ccc;border-radius:.4em;display:flex;align-items:center;justify-content:center;color:#999;font-size:.75em}
.ph-add{display:inline-block;margin-top:.5em;background:#004d40;color:#fff;border-radius:.4em;padding:.35em .9em;font-size:.9em;cursor:pointer}
.ph-add input{display:none}
.ph-msg{position:fixed;left:50%;bottom:1.2em;transform:translateX(-50%);background:#333;color:#fff;padding:.5em 1em;border-radius:.5em;display:none;z-index:99}
</style>
<div class="ph-wrap">
  <div class="ph-title">📷 商品写真の登録<?= $is_hq ? '・採用' : '' ?></div>
  <div class="ph-sub">
    <?= $is_hq ? '店舗・本社が登録した候補から、サイトに載せる1枚を採用します。' : 'お店の商品の写真を登録します。サイトに載せる写真は本社が決定します。' ?>
    写真が1枚だけの商品は、自動でその写真が採用されます。
  </div>
  <div class="ph-sub mt-1">対象 <?= count($items) ?> 品　未登録 <?= $n_none ?> 品<?= $is_hq ? "　採用待ち {$n_wait} 品" : '' ?></div>
  <div class="ph-filter">
    <select id="f-bumon"><option value="">全部門</option><?php foreach ($bumons as $b): ?><option><?= $h($b) ?></option><?php endforeach; ?></select>
    <select id="f-state">
      <option value="">すべて</option><option value="none">写真未登録</option>
      <?php if ($is_hq): ?><option value="wait">採用待ち（候補あり・未採用）</option><?php endif; ?>
      <option value="done">採用済み</option>
    </select>
    <input id="f-q" placeholder="商品名で検索">
  </div>
  <div id="list">
  <?php foreach ($items as $it):
      $state = !$it['cands'] ? 'none' : ($it['adopted'] ? 'done' : 'wait'); ?>
    <div class="ph-item" data-pid="<?= $h($it['pid']) ?>" data-bumon="<?= $h($it['bumon']) ?>" data-state="<?= $state ?>" data-name="<?= $h($it['name']) ?>">
      <div><span class="nm"><?= $h($it['name']) ?></span><span class="bm"><?= $h($it['bumon']) ?></span></div>
      <div class="ph-row">
        <?php if (!$it['cands']): ?><div class="ph-none">写真なし</div><?php endif; ?>
        <?php foreach ($it['cands'] as $c):
            $ad = ($it['adopted'] === $c['id']);
            $mine = ($c['sno'] === $store_id); ?>
          <div class="ph-c <?= $ad ? 'ad' : '' ?>">
            <a href="photo_img.php?pid=<?= $h($it['pid']) ?>&id=<?= $h($c['id']) ?>" target="_blank">
              <img src="photo_img.php?pid=<?= $h($it['pid']) ?>&id=<?= $h($c['id']) ?>" loading="lazy" alt=""></a>
            <?php if ($ad): ?><span class="tag">採用中</span><?php endif; ?>
            <div class="who"><?= $h($c['sname'] ?: '本社') ?><br><?= $h($c['at']) ?></div>
            <?php if ($is_hq && !$ad): ?><button data-act="adopt" data-id="<?= $h($c['id']) ?>">採用</button><?php endif; ?>
            <?php if ($is_hq && $ad): ?><button data-act="unadopt">採用解除</button><?php endif; ?>
            <?php if ($is_hq || ($mine && !$ad)): ?><button class="del" data-act="delete" data-id="<?= $h($c['id']) ?>">削除</button><?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
      <label class="ph-add">📷 写真を追加<input type="file" accept="image/*" data-up></label>
    </div>
  <?php endforeach; ?>
  <?php if (!$items): ?><p class="text-muted mt-3">対象の商品がありません。</p><?php endif; ?>
  </div>
</div>
<div class="ph-msg" id="msg"></div>
<script>
const CSRF = <?= json_encode($csrf) ?>;
const msg = t => { const m = document.getElementById('msg'); m.textContent = t; m.style.display = 'block'; setTimeout(() => m.style.display = 'none', 3500); };
const post = fd => { fd.append('csrf', CSRF); return fetch('photo_manage.php', {method: 'POST', body: fd}).then(r => r.json()); };

// 絞り込み
function applyFilter() {
  const b = document.getElementById('f-bumon').value, s = document.getElementById('f-state').value,
        q = document.getElementById('f-q').value.trim();
  document.querySelectorAll('.ph-item').forEach(el => {
    el.style.display = ((!b || el.dataset.bumon === b) && (!s || el.dataset.state === s) && (!q || el.dataset.name.includes(q))) ? '' : 'none';
  });
}
['f-bumon', 'f-state'].forEach(id => document.getElementById(id).onchange = applyFilter);
document.getElementById('f-q').oninput = applyFilter;

// 採用・解除・削除
document.getElementById('list').addEventListener('click', e => {
  const b = e.target.closest('button[data-act]'); if (!b) return;
  const act = b.dataset.act, item = b.closest('.ph-item');
  if (act === 'delete' && !confirm('この写真を削除しますか？')) return;
  const fd = new FormData(); fd.append('action', act); fd.append('pid', item.dataset.pid); fd.append('id', b.dataset.id || '');
  post(fd).then(d => { if (d.ok) location.reload(); else msg(d.error || '失敗しました'); }).catch(() => msg('通信に失敗しました'));
});

// 追加：ブラウザ側で長辺1600pxに縮小してから送る（スマホの大きな写真でも上限に掛からない）
function shrink(file, max = 1600) {
  return new Promise(res => {
    const img = new Image(), url = URL.createObjectURL(file);
    img.onload = () => {
      const s = Math.min(1, max / Math.max(img.width, img.height));
      const c = document.createElement('canvas'); c.width = Math.round(img.width * s); c.height = Math.round(img.height * s);
      c.getContext('2d').drawImage(img, 0, 0, c.width, c.height);
      URL.revokeObjectURL(url);
      c.toBlob(b => res(b || file), 'image/jpeg', 0.85);
    };
    img.onerror = () => { URL.revokeObjectURL(url); res(file); };
    img.src = url;
  });
}
document.querySelectorAll('input[data-up]').forEach(inp => inp.addEventListener('change', async () => {
  const f = inp.files[0]; if (!f) return;
  const item = inp.closest('.ph-item'); msg('アップロード中…');
  const blob = await shrink(f);
  const fd = new FormData(); fd.append('action', 'upload'); fd.append('pid', item.dataset.pid);
  fd.append('photo', blob, 'photo.jpg');
  post(fd).then(d => { if (d.ok) location.reload(); else { msg(d.error || '失敗しました'); inp.value = ''; } })
          .catch(() => { msg('通信に失敗しました'); inp.value = ''; });
}));
</script>
<?php include __DIR__ . '/footer.php'; ?>
