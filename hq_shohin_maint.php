<?php
/**
 * hq_shohin_maint.php  本部 販売商品メンテナンス（フル装備）
 * 機能: 全商品の一覧・追加・編集・削除
 *       発売中/セールのトグル、取扱店舗チェックボックス（複数選択）、インストアコードプレビュー
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hq') {
    header('Location: login.php'); exit();
}
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';
require_once __DIR__ . '/bumon_master.php';

// 部門定義（bumon_API から取得。並び順昇順）
$bumon_master = fetch_bumon_master($host, $db, $layout_bumon, $api_master_user, $api_master_pass);
$bumon_list   = bumon_names($bumon_master);

/* =====================================================================
   AJAX ハンドラー（POST）
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0); // PHP エラーを JSON に混入させない
    header('Content-Type: application/json; charset=UTF-8');
    $action = $_POST['action'] ?? '';
    $rid    = (int)trim($_POST['record_id'] ?? '');
    $fm = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);

    /* ── 発売中トグル ── */
    if ($action === 'toggle_hanbai') {
        $val  = ((int)($_POST['val'] ?? 0) === 1) ? 1 : 0;
        $res  = $fm->editRecord($rid, ['fieldData' => ['発売中' => $val]]);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        echo json_encode(['ok' => ($code === '0')]);
        exit();
    }

    /* ── セールトグル ── */
    if ($action === 'toggle_sale') {
        $val  = ((int)($_POST['val'] ?? 0) === 1) ? 1 : 0;
        $res  = $fm->editRecord($rid, ['fieldData' => ['セール' => $val]]);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        echo json_encode(['ok' => ($code === '0')]);
        exit();
    }

    /* ── 新規 / 編集保存（取扱店舗チェックボックス対応）── */
    if ($action === 'save') {
        $selectedStoreIds = $_POST['selected_stores'] ?? []; // 配列
        $selectedStoreIds = array_values(array_filter(array_map('trim', (array)$selectedStoreIds)));

        $fields = [
            '商品名'   => trim($_POST['name']  ?? ''),
            '部門'     => trim($_POST['bumon'] ?? ''),
            'よみがな' => trim($_POST['yomi']  ?? ''),
            '本体価格' => (int)($_POST['price'] ?? 0),
            '販売単位' => trim($_POST['tani']  ?? ''),
            '発売中'   => (int)($_POST['hanbai_chu'] ?? 1),
            'セール'   => (int)($_POST['sale'] ?? 0),
        ];

        $dbg = ['rid' => $rid, 'selected_stores' => $selectedStoreIds];
        if ($rid) {
            // 編集: ページロード時に渡されたポジション情報を使用（getRecord 不要）
            $currentPositions    = json_decode($_POST['current_positions']    ?? '{}', true) ?: [];
            $currentSalePrices   = json_decode($_POST['current_sale_prices']  ?? '{}', true) ?: [];
            $currentHonbaiPrices = json_decode($_POST['current_honbai_prices'] ?? '{}', true) ?: [];
            $storeData = buildStoreFieldData($selectedStoreIds, $currentPositions, $currentSalePrices,
                                              $currentHonbaiPrices, $fields['本体価格']);
            $dbg['current_positions']  = $currentPositions;
            $dbg['store_data_sample']  = array_slice($storeData, 0, 4, true); // 最初の4フィールドのみ
            $res = $fm->editRecord($rid, ['fieldData' => array_merge($fields, $storeData)]);
        } else {
            // 新規: 空のポジションマップで構築（選択店舗には本部設定の本体価格を初期値にする）
            $storeData = buildStoreFieldData($selectedStoreIds, [], [], [], $fields['本体価格']);
            $dbg['store_data_sample']  = array_slice($storeData, 0, 4, true);
            $res = $fm->createRecord(['fieldData' => array_merge($fields, $storeData)]);
        }
        $code = $res['result']['messages'][0]['code'] ?? '500';
        $dbg['fm_code']     = $code;
        $dbg['fm_messages'] = $res['result']['messages'] ?? [];
        $dbg['post_cp_raw'] = substr($_POST['current_positions'] ?? 'NOT_SET', 0, 80);
        echo json_encode(['ok' => ($code === '0'), 'code' => $code, 'debug' => $dbg]);
        exit();
    }

    /* ── 削除 ── */
    if ($action === 'delete') {
        $res  = $fm->deleteRecord($rid);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        echo json_encode(['ok' => ($code === '0')]);
        exit();
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit();
}

/* =====================================================================
   店舗一覧取得（account レイアウトから）
   ===================================================================== */
$fm_t   = new fmRESTor($host, $db, 'account', $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$res_t  = $fm_t->getRecords(['_limit' => 300]);
$stores = [];
foreach ($res_t['result']['response']['data'] ?? [] as $row) {
    $f  = $row['fieldData'];
    $no = (string)($f['店舗Ｎｏ'] ?? '');
    $nm = (string)($f['店舗名']  ?? '');
    if ($no !== '' && $no !== '000' && $nm !== '') {
        $stores[$no] = $nm; // 同一店舗が複数アカウントあっても上書きで重複排除
    }
}
asort($stores);

/* =====================================================================
   商品一覧取得（全件）
   ===================================================================== */
$fm  = new fmRESTor($host, $db, $layout_hanbai, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$res = $fm->getRecords(['_limit' => 1000]);

$products = [];
foreach ($res['result']['response']['data'] ?? [] as $row) {
    $f = $row['fieldData'];
    $n = trim($f['商品名'] ?? '');
    if ($n === '') continue;

    $pos_map     = getStorePositions($f);               // [pos => storeId]
    $sp_map      = getRepeatValues($f, 'セール価格');   // [pos => price]
    $hp_map      = getRepeatValues($f, '店舗本体価格'); // [pos => 店舗別本体価格]
    $store_ids   = array_values($pos_map);              // ['101', '102', ...]
    $store_names = array_map(fn($id) => $stores[$id] ?? $id, $store_ids);

    $products[] = [
        'record_id'     => $row['recordId'],
        'name'          => $n,
        'bumon'         => trim($f['部門']     ?? ''),
        'yomi'          => trim($f['よみがな'] ?? ''),
        'price'         => (int)($f['本体価格'] ?? 0),
        'tani'          => trim($f['販売単位'] ?? ''),
        'hanbai_chu'    => (int)($f['発売中']  ?? 1),
        'sale'          => (int)($f['セール']  ?? 0),
        'store_ids'     => $store_ids,
        'store_names'   => $store_names,
        'positions'     => $pos_map,   // pos→storeId（保存時に getRecord 不要にするため）
        'sale_prices'   => $sp_map,    // pos→price（セール価格保持のため）
        'honbai_prices' => $hp_map,    // pos→price（店舗別本体価格保持のため）
    ];
}

// 部門→よみがな順ソート
usort($products, function($a, $b) use ($bumon_list) {
    $pa = array_search($a['bumon'], $bumon_list); $pa = ($pa === false) ? 99 : $pa;
    $pb = array_search($b['bumon'], $bumon_list); $pb = ($pb === false) ? 99 : $pb;
    if ($pa !== $pb) return $pa - $pb;
    return strcmp($a['yomi'] ?: $a['name'], $b['yomi'] ?: $b['name']);
});

// 統計
$stat_total  = count($products);
$stat_hanbai = count(array_filter($products, fn($p) => $p['hanbai_chu']));
$stat_stop   = $stat_total - $stat_hanbai;
$stat_sale   = count(array_filter($products, fn($p) => $p['sale']));

include __DIR__ . '/hq_header.php';
?>
<style>
.maint-wrap { max-width: 1100px; margin: 0 auto; padding-bottom: 5em; }

/* ── 統計バー ── */
.stat-bar {
    background: #1a237e; color: #fff;
    padding: 0.5em 1em;
    display: flex; gap: 1.5em; flex-wrap: wrap; align-items: center;
}
.stat-item { text-align: center; }
.stat-item .s-num { font-size: 1.4em; font-weight: bold; line-height: 1.1; }
.stat-item .s-lbl { font-size: 0.7em; opacity: .8; }
.stat-bar .btn-add-top {
    margin-left: auto;
    background: #fff; color: #1a237e;
    border: none; border-radius: 0.4em;
    padding: 0.4em 1.2em; font-size: 0.9em; font-weight: bold; cursor: pointer;
}
.stat-bar .btn-add-top:hover { background: #e8eaf6; }

/* ── フィルターバー ── */
.filter-bar {
    background: #e8eaf6; border-bottom: 1px solid #c5cae9;
    padding: 0.5em 0.8em;
    display: flex; flex-wrap: wrap; gap: 0.5em; align-items: center;
    position: sticky; top: 48px; z-index: 10;
}
.filter-bar label { font-size: 0.78em; font-weight: bold; color: #3949ab; white-space: nowrap; }
.filter-select {
    border: 1.5px solid #9fa8da; border-radius: 0.4em;
    padding: 0.2em 0.5em; font-size: 0.82em; color: #1a237e;
    background: #fff; cursor: pointer;
}

/* ── 部門タブ ── */
.dept-tabs {
    display: flex; flex-wrap: wrap; gap: 0.3em;
    padding: 0.4em 0.8em; background: #f3f4fb;
    border-bottom: 1px solid #c5cae9;
}
.dept-tab {
    background: #fff; border: 1px solid #c5cae9;
    border-radius: 1em; padding: 0.2em 0.8em;
    font-size: 0.8em; font-weight: bold; color: #3949ab; cursor: pointer;
}
.dept-tab:hover  { background: #e8eaf6; }
.dept-tab.active { background: #1a237e; color: #fff; border-color: #1a237e; }

/* ── 商品テーブル ── */
.product-table { width: 100%; border-collapse: collapse; font-size: 0.9em; }
.product-table thead th {
    background: #e8eaf6; color: #1a237e;
    padding: 0.4em 0.6em; font-size: 0.8em; font-weight: bold;
    border-bottom: 2px solid #c5cae9; white-space: nowrap;
}
.product-table tbody tr { border-bottom: 1px solid #eee; }
.product-table tbody tr:hover { background: #f3f4fb; }
.product-table tbody tr.inactive { background: #fafafa; }
.product-table td { padding: 0.4em 0.6em; vertical-align: middle; }

/* ── トグル（小） ── */
.toggle-sm { position: relative; display: inline-block; width: 2.6em; height: 1.4em; cursor: pointer; margin: 0; }
.toggle-sm input { opacity: 0; width: 0; height: 0; }
.toggle-sm .sl { position: absolute; inset: 0; background: #ccc; border-radius: 1em; transition: background .2s; }
.toggle-sm .sl::before { content: ''; position: absolute; width: 1em; height: 1em; left: 0.18em; bottom: 0.18em; background: #fff; border-radius: 50%; transition: transform .2s; }
.toggle-sm input:checked + .sl { background: #00897b; }
.toggle-sm input:checked + .sl::before { transform: translateX(1.2em); }
.toggle-sm.sale-toggle input:checked + .sl { background: #c62828; }

/* ── バッジ ── */
.bumon-badge { display: inline-block; background: #e8eaf6; color: #1a237e; font-size: 0.72em; padding: 1px 7px; border-radius: 1em; font-weight: bold; }
.sale-badge  { display: inline-block; background: #c62828; color: #fff; font-size: 0.68em; font-weight: bold; padding: 1px 6px; border-radius: 0.3em; }
.store-badge {
    display: inline-block; background: #e8eaf6; color: #3949ab;
    font-size: 0.7em; padding: 1px 6px; border-radius: 0.3em;
    white-space: nowrap; margin: 1px 2px 1px 0;
}
.store-badge.none { background: #f3e5f5; color: #7b1fa2; font-style: italic; }
.store-count-more { font-size: 0.7em; color: #888; }

.price-cell { text-align: right; font-weight: bold; color: #c62828; white-space: nowrap; }
.inactive .product-name { color: #aaa; }

.btn-edit-row {
    background: #e8eaf6; color: #1a237e; border: 1px solid #9fa8da;
    border-radius: 0.35em; padding: 0.25em 0.8em; font-size: 0.8em; font-weight: bold;
    cursor: pointer; white-space: nowrap;
}
.btn-edit-row:hover { background: #c5cae9; }

.table-wrap { padding: 0 0.8em; overflow-x: auto; }

/* ── モーダル ── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.5); z-index: 1000;
    align-items: center; justify-content: center; padding: 1em;
}
.modal-overlay.open { display: flex; }
.modal-box {
    background: #fff; border-radius: 0.7em;
    width: 100%; max-width: 580px;
    max-height: 94vh; overflow-y: auto;
    box-shadow: 0 8px 32px rgba(0,0,0,.3);
}
.modal-header {
    display: flex; align-items: center;
    background: #1a237e; color: #fff;
    padding: 0.6em 1em; border-radius: 0.7em 0.7em 0 0;
}
.modal-header-title { flex: 1; font-weight: bold; }
.modal-close { background: none; border: none; color: #fff; font-size: 1.3em; cursor: pointer; }
.modal-body  { padding: 1em 1.2em; }

.form-group { margin-bottom: 0.85em; }
.form-group label { display: block; font-size: 0.8em; font-weight: bold; color: #3949ab; margin-bottom: 0.2em; }
.form-group .required { color: #c62828; }
.form-group input[type=text],
.form-group input[type=number],
.form-group select {
    width: 100%; border: 1.5px solid #c5cae9; border-radius: 0.4em;
    padding: 0.4em 0.7em; font-size: 1em; color: #222; outline: none;
}
.form-group input:focus, .form-group select:focus { border-color: #1a237e; }
.form-row { display: flex; gap: 0.6em; }
.form-row .form-group { flex: 1; }

.check-label {
    display: flex; align-items: center; gap: 0.5em;
    font-size: 0.9em; color: #333; cursor: pointer; margin-bottom: 0.4em;
}
.check-label input[type=checkbox] { width: 1.25em; height: 1.25em; accent-color: #1a237e; cursor: pointer; }

/* ── 取扱店舗チェックボックスグリッド ── */
.store-check-section {
    border: 1.5px solid #c5cae9; border-radius: 0.5em;
    padding: 0.6em 0.8em; background: #f8f9ff;
}
.store-check-header {
    display: flex; align-items: center; justify-content: space-between;
    margin-bottom: 0.5em;
}
.store-check-title { font-size: 0.82em; font-weight: bold; color: #3949ab; }
.store-check-actions { display: flex; gap: 0.5em; }
.btn-check-all, .btn-uncheck-all {
    background: none; border: 1px solid #9fa8da; border-radius: 0.3em;
    padding: 0.15em 0.6em; font-size: 0.75em; color: #3949ab; cursor: pointer;
}
.btn-check-all:hover, .btn-uncheck-all:hover { background: #e8eaf6; }

.store-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(8em, 1fr));
    gap: 0.3em 0.5em;
}
.store-grid-item {
    display: flex; align-items: center; gap: 0.35em;
    font-size: 0.82em; color: #222; cursor: pointer;
    padding: 0.2em 0.3em; border-radius: 0.3em; user-select: none;
}
.store-grid-item:hover { background: #e8eaf6; }
.store-grid-item input[type=checkbox] { accent-color: #1a237e; cursor: pointer; flex-shrink: 0; }
.selected-count { font-size: 0.78em; color: #1a237e; font-weight: bold; margin-top: 0.3em; }

.modal-footer {
    display: flex; gap: 0.5em; justify-content: flex-end;
    padding: 0.7em 1.2em 1em; border-top: 1px solid #eee;
}
.btn-modal-cancel { background: #f5f5f5; color: #555; border: 1px solid #ddd; border-radius: 0.4em; padding: 0.45em 1.2em; font-size: 0.9em; cursor: pointer; }
.btn-modal-save   { background: #1a237e; color: #fff; border: none; border-radius: 0.4em; padding: 0.45em 1.5em; font-size: 0.9em; font-weight: bold; cursor: pointer; }
.btn-modal-save:hover { background: #283593; }
.btn-modal-delete { background: #ffebee; color: #c62828; border: 1px solid #ef9a9a; border-radius: 0.4em; padding: 0.45em 1.1em; font-size: 0.9em; cursor: pointer; margin-right: auto; }
.btn-modal-delete:hover { background: #ffcdd2; }
</style>

<div class="maint-wrap">

  <!-- 統計バー -->
  <div class="stat-bar">
    <div class="stat-item">
      <div class="s-num"><?= $stat_total ?></div>
      <div class="s-lbl">全商品</div>
    </div>
    <div class="stat-item">
      <div class="s-num" style="color:#80cbc4;"><?= $stat_hanbai ?></div>
      <div class="s-lbl">発売中</div>
    </div>
    <div class="stat-item">
      <div class="s-num" style="color:#90a4ae;"><?= $stat_stop ?></div>
      <div class="s-lbl">停止中</div>
    </div>
    <div class="stat-item">
      <div class="s-num" style="color:#ef9a9a;"><?= $stat_sale ?></div>
      <div class="s-lbl">セール</div>
    </div>
    <button class="btn-add-top" onclick="openModal(null)">＋ 商品追加</button>
  </div>

  <!-- フィルターバー -->
  <div class="filter-bar">
    <label>状態:</label>
    <select class="filter-select" id="filter-hanbai" onchange="applyFilter()">
      <option value="">全て</option>
      <option value="1">発売中のみ</option>
      <option value="0">停止中のみ</option>
    </select>
    <label>セール:</label>
    <select class="filter-select" id="filter-sale" onchange="applyFilter()">
      <option value="">全て</option>
      <option value="1">セール中のみ</option>
    </select>
    <label>取扱店舗:</label>
    <select class="filter-select" id="filter-store" onchange="applyFilter()">
      <option value="">全て</option>
      <option value="_none">未設定</option>
      <?php foreach ($stores as $no => $name): ?>
        <option value="<?= htmlspecialchars($no, ENT_QUOTES) ?>"><?= htmlspecialchars($name) ?></option>
      <?php endforeach; ?>
    </select>
  </div>

  <!-- 部門タブ -->
  <div class="dept-tabs">
    <button class="dept-tab active" data-dept="">全て (<?= $stat_total ?>)</button>
    <?php foreach ($bumon_list as $b):
        $cnt = count(array_filter($products, fn($p) => $p['bumon'] === $b));
        if ($cnt === 0) continue;
    ?>
      <button class="dept-tab" data-dept="<?= htmlspecialchars($b, ENT_QUOTES) ?>">
        <?= htmlspecialchars($b) ?> (<?= $cnt ?>)
      </button>
    <?php endforeach; ?>
  </div>

  <!-- 商品テーブル -->
  <div class="table-wrap">
    <table class="product-table">
      <thead>
        <tr>
          <th>発売中</th>
          <th>セール</th>
          <th>商品名</th>
          <th>部門</th>
          <th>価格</th>
          <th>単位</th>
          <th>取扱店舗</th>
          <th></th>
        </tr>
      </thead>
      <tbody id="product-tbody">
        <?php foreach ($products as $p):
            $store_ids_json = json_encode($p['store_ids'], JSON_UNESCAPED_UNICODE);
        ?>
          <tr class="product-row <?= $p['hanbai_chu'] ? '' : 'inactive' ?>"
              data-dept="<?= htmlspecialchars($p['bumon'],  ENT_QUOTES) ?>"
              data-hanbai="<?= $p['hanbai_chu'] ?>"
              data-sale="<?= $p['sale'] ?>"
              data-stores="<?= htmlspecialchars($store_ids_json, ENT_QUOTES) ?>">

            <!-- 発売中トグル -->
            <td>
              <label class="toggle-sm">
                <input type="checkbox" class="toggle-hanbai"
                       data-rid="<?= $p['record_id'] ?>"
                       <?= $p['hanbai_chu'] ? 'checked' : '' ?>>
                <span class="sl"></span>
              </label>
            </td>

            <!-- セールトグル -->
            <td>
              <label class="toggle-sm sale-toggle">
                <input type="checkbox" class="toggle-sale"
                       data-rid="<?= $p['record_id'] ?>"
                       <?= $p['sale'] ? 'checked' : '' ?>>
                <span class="sl"></span>
              </label>
            </td>

            <!-- 商品名 -->
            <td class="product-name">
              <?= htmlspecialchars($p['name']) ?>
              <?php if ($p['sale']): ?><span class="sale-badge">セール</span><?php endif; ?>
            </td>

            <!-- 部門 -->
            <td><span class="bumon-badge"><?= htmlspecialchars($p['bumon']) ?></span></td>

            <!-- 価格 -->
            <td class="price-cell">¥<?= number_format($p['price']) ?></td>

            <!-- 単位 -->
            <td style="font-size:0.82em; color:#888;"><?= htmlspecialchars($p['tani']) ?></td>

            <!-- 取扱店舗（最大3件 + ＋N） -->
            <td>
              <?php if (empty($p['store_ids'])): ?>
                <span class="store-badge none">未設定</span>
              <?php else:
                  $show = array_slice($p['store_names'], 0, 3);
                  $more = count($p['store_names']) - 3;
                  foreach ($show as $sn):
              ?>
                <span class="store-badge"><?= htmlspecialchars($sn) ?></span>
              <?php endforeach;
                  if ($more > 0): ?>
                <span class="store-count-more">＋<?= $more ?></span>
              <?php endif; endif; ?>
            </td>

            <!-- 編集 -->
            <td>
              <button class="btn-edit-row"
                      onclick='openModal(<?= htmlspecialchars(json_encode($p, JSON_UNESCAPED_UNICODE), ENT_QUOTES) ?>)'>
                ✏ 編集
              </button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div id="no-result" style="display:none; text-align:center; padding:2em; color:#888;">
      該当する商品がありません
    </div>
  </div>

</div>

<!-- ========== 編集・追加モーダル ========== -->
<div id="modal-overlay" class="modal-overlay">
  <div class="modal-box" onclick="event.stopPropagation()">
    <div class="modal-header">
      <span class="modal-header-title" id="modal-title">商品を追加</span>
      <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body">
      <form id="product-form" onsubmit="saveProduct(event)">
        <input type="hidden" id="f-record-id" name="record_id" value="">

        <!-- 商品名 -->
        <div class="form-group">
          <label>商品名 <span class="required">*</span></label>
          <input type="text" id="f-name" name="name" placeholder="商品名を入力" required maxlength="50">
        </div>

        <!-- 部門・単位 -->
        <div class="form-row">
          <div class="form-group">
            <label>部門 <span class="required">*</span></label>
            <select id="f-bumon" name="bumon" required>
              <option value="">-- 選択 --</option>
              <?php foreach ($bumon_list as $b): ?>
                <option value="<?= htmlspecialchars($b, ENT_QUOTES) ?>"><?= htmlspecialchars($b) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group">
            <label>販売単位</label>
            <input type="text" id="f-tani" name="tani" placeholder="例: 枚, 匹" maxlength="10">
          </div>
        </div>

        <!-- よみがな -->
        <div class="form-group">
          <label>よみがな（並び順に使用）</label>
          <input type="text" id="f-yomi" name="yomi" placeholder="ひらがなで入力" maxlength="50">
        </div>

        <!-- 価格 -->
        <div class="form-group">
          <label>本体価格（円） <span class="required">*</span></label>
          <input type="number" id="f-price" name="price" placeholder="例: 800"
                 min="0" max="99999" step="1" required>
        </div>

        <!-- 発売中 / セール -->
        <div class="form-group" style="margin-top:0.9em;">
          <label class="check-label">
            <input type="checkbox" id="f-hanbai-chu" name="hanbai_chu" value="1" checked>
            発売中（POSの商品ボタンに表示）
          </label>
          <label class="check-label">
            <input type="checkbox" id="f-sale" name="sale" value="1">
            セール中（ボタンに【セール】と表示）
          </label>
        </div>

        <!-- 取扱店舗チェックボックス -->
        <div class="form-group">
          <label>取扱店舗（複数選択可）</label>
          <div class="store-check-section">
            <div class="store-check-header">
              <span class="store-check-title">店舗を選択</span>
              <div class="store-check-actions">
                <button type="button" class="btn-check-all"   onclick="checkAllStores(true)">全選択</button>
                <button type="button" class="btn-uncheck-all" onclick="checkAllStores(false)">全解除</button>
              </div>
            </div>
            <div class="store-grid" id="store-grid">
              <?php foreach ($stores as $no => $name): ?>
                <label class="store-grid-item">
                  <input type="checkbox" name="selected_stores[]"
                         value="<?= htmlspecialchars($no, ENT_QUOTES) ?>"
                         class="store-cb"
                         onchange="updateSelectedCount()">
                  <?= htmlspecialchars($name) ?>
                </label>
              <?php endforeach; ?>
            </div>
            <div class="selected-count" id="selected-count">0 店舗選択中</div>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" id="btn-delete" class="btn-modal-delete"
                  onclick="deleteProduct()" style="display:none;">🗑 削除</button>
          <button type="button" class="btn-modal-cancel" onclick="closeModal()">キャンセル</button>
          <button type="submit" class="btn-modal-save">💾 保存</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
/* ================================================================
   発売中トグル
   ================================================================ */
document.querySelectorAll('.toggle-hanbai').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var rid = this.dataset.rid, val = this.checked ? 1 : 0;
        var row = this.closest('tr');
        row.classList.toggle('inactive', !this.checked);
        row.dataset.hanbai = val;
        var me = this;
        fetch('hq_shohin_maint.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=toggle_hanbai&record_id=' + encodeURIComponent(rid) + '&val=' + val
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.ok) { me.checked = !me.checked; row.classList.toggle('inactive', !me.checked); row.dataset.hanbai = me.checked ? 1 : 0; alert('保存に失敗しました。'); }
        });
    });
});

/* ================================================================
   セールトグル
   ================================================================ */
document.querySelectorAll('.toggle-sale').forEach(function(cb) {
    cb.addEventListener('change', function() {
        var rid = this.dataset.rid, val = this.checked ? 1 : 0;
        var row = this.closest('tr');
        row.dataset.sale = val;
        var nameCell = row.querySelector('.product-name');
        var badge    = nameCell.querySelector('.sale-badge');
        if (this.checked && !badge) {
            var sp = document.createElement('span');
            sp.className = 'sale-badge'; sp.textContent = 'セール';
            nameCell.appendChild(sp);
        } else if (!this.checked && badge) { badge.remove(); }
        var me = this;
        fetch('hq_shohin_maint.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=toggle_sale&record_id=' + encodeURIComponent(rid) + '&val=' + val
        }).then(function(r) { return r.json(); }).then(function(d) {
            if (!d.ok) { me.checked = !me.checked; alert('保存に失敗しました。'); }
        });
    });
});

/* ================================================================
   フィルター（部門タブ + ドロップダウン）
   ================================================================ */
document.querySelectorAll('.dept-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        document.querySelectorAll('.dept-tab').forEach(function(t) { t.classList.remove('active'); });
        this.classList.add('active');
        applyFilter();
    });
});
function applyFilter() {
    var dept   = document.querySelector('.dept-tab.active').dataset.dept;
    var hanbai = document.getElementById('filter-hanbai').value;
    var sale   = document.getElementById('filter-sale').value;
    var store  = document.getElementById('filter-store').value;
    var visible = 0;

    document.querySelectorAll('.product-row').forEach(function(row) {
        var ok = true;
        if (dept   && row.dataset.dept !== dept) ok = false;
        if (hanbai !== '' && row.dataset.hanbai !== hanbai) ok = false;
        if (sale === '1' && row.dataset.sale !== '1') ok = false;
        if (store) {
            var storeIds = JSON.parse(row.dataset.stores || '[]');
            if (store === '_none') {
                if (storeIds.length > 0) ok = false;
            } else {
                if (storeIds.indexOf(store) === -1) ok = false;
            }
        }
        row.style.display = ok ? '' : 'none';
        if (ok) visible++;
    });
    document.getElementById('no-result').style.display = (visible === 0) ? '' : 'none';
}

/* ================================================================
   取扱店舗 全選択 / 全解除
   ================================================================ */
function checkAllStores(checked) {
    document.querySelectorAll('.store-cb').forEach(function(cb) { cb.checked = checked; });
    updateSelectedCount();
}
function updateSelectedCount() {
    var n = document.querySelectorAll('.store-cb:checked').length;
    document.getElementById('selected-count').textContent = n + ' 店舗選択中';
}

/* ================================================================
   モーダル
   ================================================================ */
var _editData = null;
function openModal(data) {
    _editData = data;
    document.getElementById('product-form').reset();

    // まず全チェックを外す
    document.querySelectorAll('.store-cb').forEach(function(cb) { cb.checked = false; });

    if (data) {
        document.getElementById('modal-title').textContent      = '商品を編集';
        document.getElementById('f-record-id').value            = data.record_id;
        document.getElementById('f-name').value                 = data.name;
        document.getElementById('f-bumon').value                = data.bumon;
        document.getElementById('f-yomi').value                 = data.yomi;
        document.getElementById('f-price').value                = data.price;
        document.getElementById('f-tani').value                 = data.tani;
        document.getElementById('f-hanbai-chu').checked         = (data.hanbai_chu === 1);
        document.getElementById('f-sale').checked               = (data.sale === 1);
        document.getElementById('btn-delete').style.display     = '';

        // 取扱店舗チェックボックスを復元
        var storeIds = data.store_ids || [];
        document.querySelectorAll('.store-cb').forEach(function(cb) {
            cb.checked = storeIds.indexOf(cb.value) !== -1;
        });
    } else {
        document.getElementById('modal-title').textContent     = '商品を追加';
        document.getElementById('f-record-id').value           = '';
        document.getElementById('f-hanbai-chu').checked        = true;
        document.getElementById('f-sale').checked              = false;
        document.getElementById('btn-delete').style.display    = 'none';
    }

    updateSelectedCount();
    document.getElementById('modal-overlay').classList.add('open');
    document.getElementById('f-name').focus();
}
function closeModal() {
    document.getElementById('modal-overlay').classList.remove('open');
    _editData = null;
}
document.getElementById('modal-overlay').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});

/* ================================================================
   保存
   ================================================================ */
function saveProduct(e) {
    e.preventDefault();
    var btn = document.querySelector('.btn-modal-save');
    btn.disabled = true; btn.textContent = '⏳ 保存中...';

    var params = new URLSearchParams({
        action              : 'save',
        record_id           : document.getElementById('f-record-id').value,
        name                : document.getElementById('f-name').value,
        bumon               : document.getElementById('f-bumon').value,
        yomi                : document.getElementById('f-yomi').value,
        price               : document.getElementById('f-price').value,
        tani                : document.getElementById('f-tani').value,
        hanbai_chu          : document.getElementById('f-hanbai-chu').checked ? '1' : '0',
        sale                : document.getElementById('f-sale').checked ? '1' : '0',
        // ページロード時の positions/sale_prices/honbai_prices をそのまま返す（getRecord 不要）
        current_positions     : JSON.stringify(_editData ? (_editData.positions     || {}) : {}),
        current_sale_prices   : JSON.stringify(_editData ? (_editData.sale_prices   || {}) : {}),
        current_honbai_prices : JSON.stringify(_editData ? (_editData.honbai_prices || {}) : {}),
    });
    // 選択された店舗を追加（配列として）
    document.querySelectorAll('.store-cb:checked').forEach(function(cb) {
        params.append('selected_stores[]', cb.value);
    });

    fetch('hq_shohin_maint.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: params.toString()
    }).then(function(r) { return r.text(); }).then(function(t) {
        var d;
        try { d = JSON.parse(t); } catch(e) { alert('【PHPエラー】\n' + t.substring(0,500)); btn.disabled=false; btn.textContent='💾 保存'; return; }
        if (d.ok) { location.reload(); }
        else {
            var msg = '保存に失敗しました\n\n';
            msg += 'FMコード: ' + (d.code||'?') + '\n';
            if (d.debug) msg += 'DEBUG:\n' + JSON.stringify(d.debug, null, 2);
            alert(msg);
            btn.disabled = false; btn.textContent = '💾 保存';
        }
    }).catch(function(err) { alert('通信エラー: ' + err); btn.disabled = false; btn.textContent = '💾 保存'; });
}

/* ================================================================
   削除
   ================================================================ */
function deleteProduct() {
    if (!_editData) return;
    if (!confirm('「' + _editData.name + '」を削除しますか？\nこの操作は元に戻せません。')) return;
    fetch('hq_shohin_maint.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: 'action=delete&record_id=' + encodeURIComponent(_editData.record_id)
    }).then(function(r) { return r.json(); }).then(function(d) {
        if (d.ok) { location.reload(); } else { alert('削除に失敗しました。'); }
    });
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
