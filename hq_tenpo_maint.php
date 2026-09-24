<?php
/**
 * hq_tenpo_maint.php  本部 店舗マスター管理
 * 機能: 店舗一覧・営業状態管理・インストアコード設定
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

$fm = new fmRESTor($host, $db, $layout_account,
                   $api_master_user, $api_master_pass, ['allowInsecure' => true]);

// 部門コードのカラム定義（bumon_API から取得。並び順昇順）
// key は部門CD由来（部門名は日本語のため HTML id / JS 変数名には使えない）
$bumon_master = fetch_bumon_master($host, $db, $layout_bumon, $api_master_user, $api_master_pass);
$ic_cols = array_map(fn($b) => [
    'key'   => 'ic_' . $b['cd'],
    'label' => $b['name'],
    'field' => 'インストアコード_' . $b['name'],
], $bumon_master);

// 部門タブ設定（レジ画面の表示/非表示・並び順。店舗ごとにaccount_APIへ保存）
$tab_cols = array_map(fn($b) => [
    'key_hide'    => 'hide_' . $b['cd'],
    'key_order'   => 'ord_'  . $b['cd'],
    'label'       => $b['name'],
    'field_hide'  => '非表示_' . $b['name'],
    'field_order' => '表示順_' . $b['name'],
], $bumon_master);

/* =====================================================================
   AJAX ハンドラー（POST）
   ===================================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ini_set('display_errors', 0);
    header('Content-Type: application/json; charset=UTF-8');

    $action = $_POST['action'] ?? '';
    $rid    = (int)trim($_POST['record_id'] ?? '');

    /* ── 店舗情報保存 ── */
    if ($action === 'save' && $rid > 0) {
        $heiten_bi = trim($_POST['heiten_bi'] ?? '');

        $fields = ['営業状態' => trim($_POST['eigyo_status'] ?? '')];
        foreach ($ic_cols as $c) {
            $fields[$c['field']] = trim($_POST[$c['key']] ?? '');
        }
        // 閉店日（空の場合は空文字のままFMに渡す）
        $fields['閉店日'] = $heiten_bi;

        // 部門タブ設定（表示/非表示・並び順）
        foreach ($tab_cols as $c) {
            $fields[$c['field_hide']]  = ((int)($_POST[$c['key_hide']] ?? 0) === 1) ? 1 : 0;
            $fields[$c['field_order']] = trim($_POST[$c['key_order']] ?? '');
        }

        $res  = $fm->editRecord($rid, ['fieldData' => $fields]);
        $code = $res['result']['messages'][0]['code'] ?? '500';
        echo json_encode(['ok' => ($code === '0'), 'code' => $code]);
        exit();
    }

    echo json_encode(['ok' => false, 'error' => 'unknown action']);
    exit();
}

/* =====================================================================
   一覧取得
   ===================================================================== */
$result  = $fm->getRecords(['_limit' => 300]);
$raw     = $result['result']['response']['data'] ?? [];

// 店舗No 昇順でソート
usort($raw, function($a, $b) {
    return (int)($a['fieldData']['店舗Ｎｏ'] ?? 0) - (int)($b['fieldData']['店舗Ｎｏ'] ?? 0);
});

include __DIR__ . '/hq_header.php';
?>
<style>
:root { --hq-accent: #1a237e; }

.tm-wrap {
    max-width: 1100px;
    margin: 0 auto;
    padding: 1em 0.8em 3em;
}
.tm-title {
    font-size: 1.2em;
    font-weight: bold;
    color: var(--hq-accent);
    border-left: 4px solid var(--hq-accent);
    padding-left: 0.6em;
    margin: 0.8em 0 1em;
}

/* テーブル */
.tm-table-wrap { overflow-x: auto; }
.tm-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 0.88em;
    min-width: 780px;
}
.tm-table th {
    background: var(--hq-accent);
    color: #fff;
    padding: 0.5em 0.6em;
    white-space: nowrap;
    text-align: center;
}
.tm-table td {
    padding: 0.45em 0.6em;
    border-bottom: 1px solid #e0e0e0;
    vertical-align: middle;
}
.tm-table tbody tr:hover { background: #f0f4ff; }
.tm-table tbody tr.heiten { background: #f5f5f5; color: #999; }
.tm-table tbody tr.heiten:hover { background: #ececec; }

/* 営業状態バッジ */
.badge-eigyo {
    display: inline-block;
    padding: 0.2em 0.7em;
    border-radius: 1em;
    font-size: 0.82em;
    font-weight: bold;
}
.badge-eigyo.open  { background: #e8f5e9; color: #2e7d32; }
.badge-eigyo.close { background: #fce4ec; color: #c62828; }
.badge-eigyo.other { background: #f5f5f5; color: #666; }

/* インストアコード表示 */
.ic-val {
    font-family: monospace;
    font-size: 0.9em;
    color: #333;
}
.ic-val.empty { color: #bbb; }

/* 編集ボタン */
.btn-edit {
    padding: 0.25em 0.8em;
    border: 1.5px solid var(--hq-accent);
    background: #fff;
    color: var(--hq-accent);
    border-radius: 0.4em;
    font-size: 0.85em;
    cursor: pointer;
    white-space: nowrap;
}
.btn-edit:hover { background: var(--hq-accent); color: #fff; }

/* 編集パネル（行内展開） */
.edit-row { display: none; background: #f8f9ff; }
.edit-row.open { display: table-row; }
.edit-panel {
    padding: 1em;
    border: 2px solid var(--hq-accent);
    border-radius: 0.5em;
    margin: 0.4em 0;
}
.edit-panel h4 {
    margin: 0 0 0.8em;
    font-size: 1em;
    color: var(--hq-accent);
}
.ep-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 0.6em;
}
.ep-field label {
    display: block;
    font-size: 0.78em;
    color: #666;
    margin-bottom: 0.2em;
}
.ep-field input, .ep-field select {
    width: 100%;
    padding: 0.35em 0.5em;
    border: 1.5px solid #ccc;
    border-radius: 0.35em;
    font-size: 0.95em;
    font-family: monospace;
}
.ep-field input:focus, .ep-field select:focus {
    outline: none;
    border-color: var(--hq-accent);
}
.ep-actions {
    margin-top: 0.8em;
    display: flex;
    gap: 0.5em;
    flex-wrap: wrap;
}
.btn-save {
    padding: 0.4em 1.4em;
    background: var(--hq-accent);
    color: #fff;
    border: none;
    border-radius: 0.4em;
    font-size: 0.9em;
    cursor: pointer;
}
.btn-save:hover { background: #283593; }
.btn-cancel-edit {
    padding: 0.4em 1em;
    background: #fff;
    color: #666;
    border: 1.5px solid #ccc;
    border-radius: 0.4em;
    font-size: 0.9em;
    cursor: pointer;
}
.btn-cancel-edit:hover { background: #f0f0f0; }
.save-msg {
    font-size: 0.85em;
    padding: 0.3em 0.6em;
    border-radius: 0.3em;
    display: none;
}
.save-msg.ok  { background: #e8f5e9; color: #2e7d32; }
.save-msg.err { background: #fce4ec; color: #c62828; }

/* 部門タブ設定（表示・並び順） */
.tab-settings { margin-top: 1em; }
.tab-settings h5 {
    font-size: 0.88em; color: var(--hq-accent); margin: 0 0 0.5em;
    border-left: 3px solid var(--hq-accent); padding-left: 0.5em;
}
.tab-settings-table { width: 100%; border-collapse: collapse; font-size: 0.85em; }
.tab-settings-table th {
    background: #eceffd; color: var(--hq-accent);
    padding: 0.3em 0.5em; text-align: center; font-weight: bold;
}
.tab-settings-table td { padding: 0.25em 0.5em; border-bottom: 1px solid #eee; text-align: center; }
.tab-settings-table td.label-cell { text-align: left; font-weight: bold; }
.tab-hide-cb { width: 1.2em; height: 1.2em; cursor: pointer; }
.tab-order-input { width: 4em; padding: 0.2em 0.3em; border: 1.5px solid #ccc; border-radius: 0.3em; text-align: center; }

/* 凡例 */
.legend {
    font-size: 0.8em;
    color: #888;
    margin-bottom: 0.8em;
}
</style>

<div class="tm-wrap">
    <div class="tm-title">🏪 店舗マスター管理</div>

    <div class="legend">
        インストアコード: <strong>7桁テキスト</strong>（例: 2004355）
        営業状態: <strong>営業中</strong> / <strong>閉店</strong>
    </div>

    <div class="tm-table-wrap">
    <table class="tm-table" id="tenpoTable">
        <thead>
            <tr>
                <th>店舗No</th>
                <th>店舗名</th>
                <th>営業状態</th>
                <th>閉店日</th>
                <?php foreach ($ic_cols as $c): ?>
                    <th><?= $c['label'] ?></th>
                <?php endforeach; ?>
                <th>操作</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($raw as $rec):
            $f         = $rec['fieldData'];
            $rid       = $rec['recordId'];
            $tenpo_no  = (string)($f['店舗Ｎｏ']  ?? '');
            $tenpo_mei = $f['店舗名']    ?? '';
            $eigyo     = trim($f['営業状態'] ?? '');
            $heiten_bi = trim($f['閉店日']   ?? '');
            $is_heiten = ($eigyo === '閉店');

            $badge_class = match($eigyo) {
                '営業中' => 'open',
                '閉店'   => 'close',
                default  => 'other',
            };
            $row_class = $is_heiten ? 'heiten' : '';
        ?>
        <tr class="data-row <?= $row_class ?>" data-rid="<?= (int)$rid ?>">
            <td style="text-align:center; font-weight:bold;"><?= htmlspecialchars($tenpo_no) ?></td>
            <td>
                <?php if ($is_heiten): ?>
                    <span style="color:#c62828;font-weight:bold;">★ <?= htmlspecialchars($tenpo_mei) ?></span>
                <?php else: ?>
                    <?= htmlspecialchars($tenpo_mei) ?>
                <?php endif; ?>
            </td>
            <td style="text-align:center;">
                <span class="badge-eigyo <?= $badge_class ?>">
                    <?= $eigyo !== '' ? htmlspecialchars($eigyo) : '未設定' ?>
                </span>
            </td>
            <td style="text-align:center; font-size:0.85em; color:#666;">
                <?= $heiten_bi !== '' ? htmlspecialchars($heiten_bi) : '' ?>
            </td>
            <?php foreach ($ic_cols as $c):
                $v = trim($f[$c['field']] ?? '');
            ?>
            <td style="text-align:center;">
                <span class="ic-val <?= $v === '' ? 'empty' : '' ?>">
                    <?= $v !== '' ? htmlspecialchars($v) : '—' ?>
                </span>
            </td>
            <?php endforeach; ?>
            <td style="text-align:center;">
                <button class="btn-edit" onclick="toggleEdit(<?= (int)$rid ?>)">✏️ 編集</button>
            </td>
        </tr>

        <!-- 編集パネル行 -->
        <tr class="edit-row" id="edit-<?= (int)$rid ?>">
            <td colspan="<?= 4 + count($ic_cols) + 1 ?>">
                <div class="edit-panel">
                    <h4>✏️ 編集: <?= htmlspecialchars($tenpo_no) ?> <?= htmlspecialchars($tenpo_mei) ?></h4>

                    <div class="ep-grid">
                        <!-- 営業状態 -->
                        <div class="ep-field" style="grid-column: span 1;">
                            <label>営業状態</label>
                            <select name="eigyo_status" id="es-<?= (int)$rid ?>">
                                <option value="営業中" <?= $eigyo === '営業中' ? 'selected' : '' ?>>営業中</option>
                                <option value="閉店"   <?= $eigyo === '閉店'   ? 'selected' : '' ?>>閉店</option>
                                <option value=""       <?= $eigyo === ''       ? 'selected' : '' ?>>(未設定)</option>
                            </select>
                        </div>

                        <!-- 閉店日 -->
                        <div class="ep-field">
                            <label>閉店日（閉店時のみ）</label>
                            <input type="text"
                                   id="hb-<?= (int)$rid ?>"
                                   value="<?= htmlspecialchars($heiten_bi) ?>"
                                   placeholder="例: 2024/03/31">
                        </div>

                        <!-- インストアコード 各部門 -->
                        <?php foreach ($ic_cols as $c):
                            $v = trim($f[$c['field']] ?? '');
                        ?>
                        <div class="ep-field">
                            <label>インストアコード_<?= $c['label'] ?>（7桁）</label>
                            <input type="text"
                                   id="<?= $c['key'] ?>-<?= (int)$rid ?>"
                                   value="<?= htmlspecialchars($v) ?>"
                                   maxlength="7"
                                   placeholder="例: 2004355">
                        </div>
                        <?php endforeach; ?>
                    </div>

                    <!-- 部門タブ設定（レジ画面の表示・並び順。色は全店共通のため部門マスタ側で設定） -->
                    <div class="tab-settings">
                        <h5>📱 レジ画面：部門タブの表示・並び順（この店舗のみ）</h5>
                        <table class="tab-settings-table">
                            <thead>
                                <tr><th style="text-align:left;">部門</th><th>非表示</th><th>表示順</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($tab_cols as $c):
                                    $hide_v  = (int)($f[$c['field_hide']]  ?? 0);
                                    $order_v = trim((string)($f[$c['field_order']] ?? ''));
                                ?>
                                <tr>
                                    <td class="label-cell"><?= htmlspecialchars($c['label']) ?></td>
                                    <td>
                                        <input type="checkbox" class="tab-hide-cb"
                                               id="<?= $c['key_hide'] ?>-<?= (int)$rid ?>"
                                               <?= $hide_v === 1 ? 'checked' : '' ?>>
                                    </td>
                                    <td>
                                        <input type="number" class="tab-order-input"
                                               id="<?= $c['key_order'] ?>-<?= (int)$rid ?>"
                                               value="<?= htmlspecialchars($order_v) ?>"
                                               placeholder="既定順">
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="ep-actions">
                        <button class="btn-save" onclick="saveStore(<?= (int)$rid ?>)">💾 保存</button>
                        <button class="btn-cancel-edit" onclick="toggleEdit(<?= (int)$rid ?>)">キャンセル</button>
                        <span class="save-msg" id="msg-<?= (int)$rid ?>"></span>
                    </div>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div><!-- .tm-table-wrap -->
</div><!-- .tm-wrap -->

<script>
'use strict';

function toggleEdit(rid) {
    var row = document.getElementById('edit-' + rid);
    if (!row) return;
    row.classList.toggle('open');
}

function saveStore(rid) {
    var eigyo   = document.getElementById('es-'        + rid)?.value ?? '';
    var heiten  = document.getElementById('hb-'        + rid)?.value ?? '';
    <?php foreach ($ic_cols as $c): ?>
    var <?= $c['key'] ?> = document.getElementById('<?= $c['key'] ?>-' + rid)?.value ?? '';
    <?php endforeach; ?>
    <?php foreach ($tab_cols as $c): ?>
    var <?= $c['key_hide'] ?>  = document.getElementById('<?= $c['key_hide']  ?>-' + rid)?.checked ? '1' : '0';
    var <?= $c['key_order'] ?> = document.getElementById('<?= $c['key_order'] ?>-' + rid)?.value ?? '';
    <?php endforeach; ?>

    var fd = new FormData();
    fd.append('action',       'save');
    fd.append('record_id',    rid);
    fd.append('eigyo_status', eigyo);
    fd.append('heiten_bi',    heiten);
    <?php foreach ($ic_cols as $c): ?>
    fd.append('<?= $c['key'] ?>', <?= $c['key'] ?>);
    <?php endforeach; ?>
    <?php foreach ($tab_cols as $c): ?>
    fd.append('<?= $c['key_hide']  ?>', <?= $c['key_hide']  ?>);
    fd.append('<?= $c['key_order'] ?>', <?= $c['key_order'] ?>);
    <?php endforeach; ?>

    var msgEl = document.getElementById('msg-' + rid);
    msgEl.style.display = 'none';

    fetch('hq_tenpo_maint.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                msgEl.textContent = '✅ 保存しました';
                msgEl.className   = 'save-msg ok';
                msgEl.style.display = 'inline-block';
                // 一覧の表示も更新
                setTimeout(() => location.reload(), 800);
            } else {
                msgEl.textContent = '❌ 保存に失敗しました（code: ' + (data.code || '?') + '）';
                msgEl.className   = 'save-msg err';
                msgEl.style.display = 'inline-block';
            }
        })
        .catch(() => {
            msgEl.textContent = '❌ 通信エラー';
            msgEl.className   = 'save-msg err';
            msgEl.style.display = 'inline-block';
        });
}
</script>

<?php include __DIR__ . '/footer.php'; ?>
