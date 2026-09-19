<?php
/**
 * daily_report_entry.php  売上日報 入力画面
 * ・12時/15時/17時/閉店後を縦並び表示
 * ・右列に前年同週同曜日データを表示
 * ・部門選択はアコーディオン形式（localStorage保存）
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
$is_reflect_ajax = (($_GET['ajax'] ?? '') === 'reflect_regi');
if (!isset($_SESSION['user'])) {
    if ($is_reflect_ajax) {
        header('Content-Type: application/json');
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'session_expired']);
        exit();
    }
    header('Location: login.php'); exit();
}
if (($_SESSION['role'] ?? '') === 'hq') { header('Location: hq_top.php'); exit(); }

require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';

$store_id   = $_SESSION['store_id']   ?? '';
$store_name = $_SESSION['store_name'] ?? '';

// ---- 対象日付 ----
$target_date_fm = $_GET['date'] ?? date('m/d/Y');
$dt = \DateTime::createFromFormat('m/d/Y', $target_date_fm) ?: new \DateTime();
$week_ja = ['Sunday'=>'日','Monday'=>'月','Tuesday'=>'火','Wednesday'=>'水',
            'Thursday'=>'木','Friday'=>'金','Saturday'=>'土'];
$target_date_jp = $dt->format('Y年n月j日') . '（' . ($week_ja[$dt->format('l')] ?? '') . '）';

// 日付ナビ用
$today_dt   = new DateTime(); $today_dt->setTime(0,0,0);
$dt_clone   = clone $dt;     $dt_clone->setTime(0,0,0);
$prev_dt    = (clone $dt_clone)->modify('-1 day');
$next_dt    = (clone $dt_clone)->modify('+1 day');
$is_today_page  = ($dt_clone == $today_dt);
$is_future_page = ($dt_clone > $today_dt);
// ▶ボタン：30日先まで進めるよう制限
$max_nav_dt      = (clone $today_dt)->modify('+30 days');
$next_is_too_far = ($next_dt > $max_nav_dt);
$prev_date_fm   = $prev_dt->format('m/d/Y');
$next_date_fm   = $next_dt->format('m/d/Y');
$date_html      = $dt_clone->format('Y-m-d'); // HTML date input 用
$date_html_max  = $max_nav_dt->format('Y-m-d'); // date picker 上限

// ---- 前年同週同曜日の日付を計算 ----
$cur_ts  = $dt->getTimestamp();
$cur_week = (int)date('W', $cur_ts);
$cur_dow  = (int)date('N', $cur_ts);   // 1=月 … 7=日
$py_year  = (int)$dt->format('Y') - 1;

// 前年 ISO week1 の月曜日を求める
$py_jan4     = mktime(0, 0, 0, 1, 4, $py_year);
$py_w1_mon   = $py_jan4 - ((int)date('N', $py_jan4) - 1) * 86400;
$py_target_ts = $py_w1_mon + (($cur_week - 1) * 7 + ($cur_dow - 1)) * 86400;
$py_target_fm = date('m/d/Y', $py_target_ts);
$py_date_jp   = date('Y年n月j日', $py_target_ts) . '（' . ($week_ja[date('l', $py_target_ts)] ?? '') . '）';

// ---- 全部門定義（表示対象のみ） ----
$all_busho = [
    '売上_天ぷら'         => '天ぷら',
    '売上_魚'             => '魚',
    '売上_唐揚'           => '唐揚',
    '売上_冷惣菜'         => '冷惣菜',
    '売上_催事'           => '催事',
    '売上_イカ焼'         => 'イカ焼',
    '売上_エキタカ'       => 'エキタカ',
    '売上_くじら'         => 'くじら',
    '売上_コンビニデリカ' => 'コンビニデリカ',
    '売上_セルフ唐揚'     => 'セルフ唐揚',
    '売上_セルフ天丼'     => 'セルフ天丼',
    '売上_セルフ惣菜'     => 'セルフ惣菜',
    '売上_フライ'         => 'フライ',
    '売上_串揚'           => '串揚',
    '売上_丼'             => '丼',
    '売上_個食'           => '個食',
    '売上_弁当'           => '弁当',
    '売上_弁当Ⅱ'         => '弁当Ⅱ',
    '売上_生串揚'         => '生串揚',
    '売上_鯛'             => '鯛',
];

// ---- 上代（毎日1回・「今日はこれだけ売るつもり」の合計目標額。部門別ではなく合計のみ入力） ----

// ---- 時間帯別 客数・累計売上フィールド（12時/15時/17時。累計売上は部門別ではなく合計のみ入力） ----
$time_slots = [
    '12' => ['label' => '🕛 12時', 'action' => 'save_12', 'fm_suffix' => '12時', 'field_kyaku' => '客数_12時', 'field_uriage' => '売上累計_12時', 'cutoff' => '12:00:00'],
    '15' => ['label' => '🕒 15時', 'action' => 'save_15', 'fm_suffix' => '15時', 'field_kyaku' => '客数_15時', 'field_uriage' => '売上累計_15時', 'cutoff' => '15:00:00'],
    '17' => ['label' => '🕔 17時', 'action' => 'save_17', 'fm_suffix' => '17時', 'field_kyaku' => '客数_17時', 'field_uriage' => '売上累計_17時', 'cutoff' => '17:00:00'],
];
function bushoTimeField(string $bushoField, string $fmSuffix): string {
    return $bushoField . '_' . $fmSuffix;
}
// 部門フィールド一覧（閉店後＋各時間帯）。FMレイアウト未登録時のフォールバック判定に使用
$busho_and_time_keys = array_keys($all_busho);
foreach ($time_slots as $slot) {
    foreach (array_keys($all_busho) as $bf) {
        $busho_and_time_keys[] = bushoTimeField($bf, $slot['fm_suffix']);
    }
}

// ---- FM 接続 ----
$fm = new fmRESTor($host, $db, $layout_daily_report,
                   $api_master_user, $api_master_pass, ['allowInsecure' => true]);

// 当日レコード取得
$record_id      = null;
$fd             = [];
$nyuryoku_jotai = '未入力';

$qr = $fm->findRecords([
    'query' => [['fk_店舗No' => $store_id, '売上日' => $target_date_fm]],
    'limit' => 1,
]);
if (($qr['result']['messages'][0]['code'] ?? '0') !== '401') {
    $rec = $qr['result']['response']['data'][0] ?? null;
    if ($rec) {
        $record_id      = $rec['recordId'];
        $fd             = $rec['fieldData'];
        $nyuryoku_jotai = $fd['入力状態'] ?? '未入力';
    }
}
$is_kakutei = ($nyuryoku_jotai === '確定');

// 前年同週同曜日レコード取得
$py_fd = [];
$qr2 = $fm->findRecords([
    'query' => [['fk_店舗No' => $store_id, '売上日' => $py_target_fm]],
    'limit' => 1,
]);
if (($qr2['result']['messages'][0]['code'] ?? '0') !== '401') {
    $rec2 = $qr2['result']['response']['data'][0] ?? null;
    if ($rec2) $py_fd = $rec2['fieldData'];
}

// ---- ととレジ実績（pos_API 明細から時刻カットオフ集計、参照専用） ----
$fmPos = new fmRESTor($host, $db, $layout_pos,
                      $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$qrPos = $fmPos->findRecords([
    'query' => [['店舗No' => $store_id, '販売日時' => $target_date_fm]],
    'limit' => 2000,
]);
$pos_records = (($qrPos['result']['messages'][0]['code'] ?? '0') !== '401')
    ? ($qrPos['result']['response']['data'] ?? [])
    : [];

$tr_12  = totoregiAgg($pos_records, $time_slots['12']['cutoff'], $all_busho);
$tr_15  = totoregiAgg($pos_records, $time_slots['15']['cutoff'], $all_busho);
$tr_17  = totoregiAgg($pos_records, $time_slots['17']['cutoff'], $all_busho);
$tr_all = totoregiAgg($pos_records, null, $all_busho);

// ---- AJAX: 「レジの実績を反映」ボタン用。ページ読み込み時点のキャッシュではなく、
//      クリックの都度ここで最新の pos_records を再集計して返す（反映ボタンを押す
//      タイミングが多少前後しても、常にその時点の最新実績が返る） ----
if ($is_reflect_ajax) {
    header('Content-Type: application/json');
    $slot_key = $_GET['slot'] ?? '';
    if (!isset($time_slots[$slot_key])) {
        echo json_encode(['ok' => false, 'error' => 'invalid_slot']);
        exit();
    }
    $agg = totoregiAgg($pos_records, $time_slots[$slot_key]['cutoff'], $all_busho);
    echo json_encode(['ok' => true, 'kyaku' => $agg['count'], 'sum' => $agg['sum']]);
    exit();
}

// ---- POST 処理 ----
$success_msg = '';

// GET msg（確定解除後リダイレクト）
if (($_GET['msg'] ?? '') === 'kaijo') {
    $success_msg = '🔓 確定を解除しました。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ---- 確定解除（定休日フラグも一緒に解除。フィールド未登録環境向けにフォールバックあり） ----
    if ($action === 'kaijo' && $is_kakutei && $record_id !== null) {
        $r = $fm->editRecord($record_id, ['fieldData' => ['入力状態' => '入力中', '定休日' => 0]]);
        if ($fm->isError($r)) {
            $fm->editRecord($record_id, ['fieldData' => ['入力状態' => '入力中']]);
        }
        header('Location: ?date=' . urlencode($target_date_fm) . '&msg=kaijo');
        exit();
    }

    $save   = [];
    // 上代は確定後も編集可能（実績とは別の「目標額」のため）
    if ($is_kakutei && $action !== 'save_joudai') goto skip_save;

    $time_slot_by_action = array_column($time_slots, null, 'action');

    if ($action === 'save_joudai') {
        $save['上代合計'] = _int('上代合計');
    } elseif (isset($time_slot_by_action[$action])) {
        $slot = $time_slot_by_action[$action];
        $save[$slot['field_kyaku']]  = _int($slot['field_kyaku']);
        $save[$slot['field_uriage']] = _int($slot['field_uriage']);
    } elseif ($action === 'save_heiten' || $action === 'kakutei') {
        foreach (array_keys($all_busho) as $f) {
            $save[$f] = _int($f);
        }
        // 合計売上はFM計算フィールドのため送信しない
        $save['客数_閉店後'] = _int('客数_閉店後');
        if ($action === 'kakutei') {
            $save['入力状態'] = '確定';
            $save['確定日時'] = date('m/d/Y H:i:s');
        }
    } elseif ($action === 'teikyubi') {
        // 定休日：入力内容に関わらず売上・客数をすべて0にして確定する
        foreach (array_keys($all_busho) as $f) {
            $save[$f] = 0;
        }
        $save['客数_閉店後']   = 0;
        foreach ($time_slots as $slot) {
            $save[$slot['field_kyaku']]  = 0;
            $save[$slot['field_uriage']] = 0;
            foreach (array_keys($all_busho) as $bf) {
                $save[bushoTimeField($bf, $slot['fm_suffix'])] = 0;
            }
        }
        $save['定休日']        = 1;
        $save['入力状態']      = '確定';
        $save['確定日時']      = date('m/d/Y H:i:s');
    }

    if (!empty($save)) {
        // 上代の保存は目標額の更新のみで、確定ステータスには影響させない
        if ($action !== 'save_joudai') {
            $save['入力状態'] = $save['入力状態'] ?? '入力中';
        }

        $fm_ok = false;
        $fm_err_code = '';
        $fm_err_msg  = '';

        if ($record_id === null) {
            // 新規作成
            $save['fk_店舗No'] = $store_id;
            $save['店舗名']    = $store_name;
            $save['売上日']    = $target_date_fm;
            $res = $fm->createRecord(['fieldData' => $save]);
            $fm_err_code = (string)($res['result']['messages'][0]['code'] ?? '?');
            $fm_err_msg  = $res['result']['messages'][0]['message'] ?? '';
            if ($fm_err_code === '0') {
                $fm_ok     = true;
                $record_id = $res['result']['response']['recordId'] ?? null;
            } else {
                // 部門フィールドを除いてリトライ（レイアウト未登録フィールド対策）
                $save_base = array_filter($save, fn($k) => !in_array($k, $busho_and_time_keys, true) && $k !== '定休日', ARRAY_FILTER_USE_KEY);
                $res2b = $fm->createRecord(['fieldData' => $save_base]);
                $code2b = (string)($res2b['result']['messages'][0]['code'] ?? '?');
                if ($code2b === '0') {
                    $fm_ok      = true;
                    $record_id  = $res2b['result']['response']['recordId'] ?? null;
                    $fm_err_msg = '⚠️ 一部の項目はFMレイアウトに未登録のため保存できませんでした（FM code: ' . $fm_err_code . '）。FMレイアウト daily_report_API にフィールドを追加してください。';
                }
            }
        } else {
            // 既存レコード更新
            $res = $fm->editRecord($record_id, ['fieldData' => $save]);
            $fm_err_code = (string)($res['result']['messages'][0]['code'] ?? '?');
            $fm_err_msg  = $res['result']['messages'][0]['message'] ?? '';
            if ($fm_err_code === '0') {
                $fm_ok = true;
            } else {
                // 部門フィールドを除いてリトライ
                $save_base = array_filter($save, fn($k) => !in_array($k, $busho_and_time_keys, true) && $k !== '定休日', ARRAY_FILTER_USE_KEY);
                $res2b = $fm->editRecord($record_id, ['fieldData' => $save_base]);
                $code2b = (string)($res2b['result']['messages'][0]['code'] ?? '?');
                if ($code2b === '0') {
                    $fm_ok      = true;
                    $fm_err_msg = '⚠️ 一部の項目はFMレイアウトに未登録のため保存できませんでした（FM code: ' . $fm_err_code . ' / ' . $res['result']['messages'][0]['message'] . '）。FMレイアウト daily_report_API にフィールドを追加してください。';
                }
            }
        }

        if ($fm_ok && $record_id) {
            $res3 = $fm->getRecord($record_id);
            $r3   = $res3['result']['response']['data'][0] ?? null;
            if ($r3) {
                $fd             = $r3['fieldData'];
                $nyuryoku_jotai = $fd['入力状態'] ?? '入力中';
                $is_kakutei     = ($nyuryoku_jotai === '確定');
            }
        }

        if (!$fm_ok) {
            $success_msg = '❌ 保存エラー（FM code: ' . $fm_err_code . ' / ' . $fm_err_msg . '）';
        } else {
            $base_msg = match ($action) {
                'kakutei'  => '✅ 確定しました。',
                'teikyubi' => '🏠 定休日として確定しました。',
                default    => '💾 保存しました。',
            };
            $success_msg = $base_msg;
            if ($fm_err_msg !== '') {
                $success_msg .= '<br><small style="color:#e65100;">' . htmlspecialchars($fm_err_msg) . '</small>';
            }
        }
    }
    skip_save:;
}

// ---- 定休日一覧（自店舗分のみ。POST保存後の最新状態を反映するためここで取得） ----
$teikyu_list = [];
$qrTeikyu = $fm->findRecords([
    'query' => [['fk_店舗No' => $store_id, '定休日' => 1]],
    'sort'  => [['fieldName' => '売上日', 'sortOrder' => 'ascend']],
    'limit' => 200,
]);
if (($qrTeikyu['result']['messages'][0]['code'] ?? '0') !== '401') {
    foreach ($qrTeikyu['result']['response']['data'] ?? [] as $row) {
        $d = $row['fieldData']['売上日'] ?? '';
        if ($d !== '') $teikyu_list[] = $d;
    }
}

// ---- 今月の店舗売上ランキング（自店の順位＋前後の店舗のみ。金額は非公開） ----
// 表示中の日付が属する月の月初〜月末で全店舗を単純合計してランキングする。
// 今月（進行中）なら未来日はまだデータが無いため自然に「現時点までの累計」になり、
// 過去の（既に終わった）月ならその月の全日が揃っているため自然に「月末の確定順位」になる
// ―― どちらも同じロジックで成立するため、進行中／過去で分岐する必要はない。
$rank_year     = (int)$dt->format('Y');
$rank_month    = (int)$dt->format('n');
$rank_from_fm  = sprintf('%02d/01/%04d', $rank_month, $rank_year);
$rank_last_day = (int)date('t', mktime(0, 0, 0, $rank_month, 1, $rank_year));
$rank_to_fm    = sprintf('%02d/%02d/%04d', $rank_month, $rank_last_day, $rank_year);

$rank_list = []; // 売上>0の店舗のみ、降順。各要素 ['sn','name','sales']
$qrRank = $fm->findRecords([
    'query' => [['売上日' => "{$rank_from_fm}...{$rank_to_fm}"]],
    'limit' => 2000,
]);
if (($qrRank['result']['messages'][0]['code'] ?? '0') !== '401') {
    // 店舗No+売上日が重複するレコードは1件に集約（hq_seiseki.phpと同様、二重登録データ対策）
    $rank_rows_by_key = [];
    foreach ($qrRank['result']['response']['data'] ?? [] as $row) {
        $f  = $row['fieldData'];
        $sn = (string)($f['fk_店舗No'] ?? '');
        if ($sn === '') continue;
        $rank_rows_by_key[$sn . '|' . ($f['売上日'] ?? '')] = $f;
    }
    $rank_sums = []; // sn => ['sn','name','sales']
    foreach ($rank_rows_by_key as $f) {
        $sn = (string)($f['fk_店舗No'] ?? '');
        if (!isset($rank_sums[$sn])) {
            $rank_sums[$sn] = ['sn' => $sn, 'name' => $f['店舗名'] ?? $sn, 'sales' => 0];
        }
        $rank_sums[$sn]['sales'] += (int)($f['合計売上'] ?? 0);
    }
    // まだ今月の売上実績が無い店舗（新規出店・入力前）はランキング対象外
    $rank_list = array_values(array_filter($rank_sums, fn($s) => $s['sales'] > 0));
    usort($rank_list, fn($a, $b) => $a['sales'] !== $b['sales']
        ? $b['sales'] <=> $a['sales']
        : strcmp($a['sn'], $b['sn']));
}

$rank_total = count($rank_list);
$rank_my_index = null;
foreach ($rank_list as $i => $r) {
    if ($r['sn'] === $store_id) { $rank_my_index = $i; break; }
}

// 自店を中心に3件（自店＋前後1件ずつ）のウインドウを作る。自店が1位・最下位付近で
// 片側が無い場合は、常に3件（可能な限り）表示できるよう反対側にずらす
$rank_window = [];
if ($rank_my_index !== null) {
    $win_start = $rank_my_index - 1;
    $win_end   = $rank_my_index + 1;
    if ($win_start < 0) { $win_end += -$win_start; $win_start = 0; }
    if ($win_end > $rank_total - 1) { $win_start -= ($win_end - ($rank_total - 1)); $win_end = $rank_total - 1; }
    $win_start = max(0, $win_start);
    for ($i = $win_start; $i <= $win_end; $i++) $rank_window[] = $i;
}

// ---- 実績のある部門（自店の取扱部門設定が未選択でも、日報保存済みの値や
//      レジの実績がある部門は非表示にしない） ----
$busho_has_data = [];
foreach (array_keys($all_busho) as $bf) {
    $has = ((int)($fd[$bf] ?? 0) > 0)
        || (($tr_all['by_field'][$bf] ?? 0) > 0);
    if ($has) $busho_has_data[] = $bf;
}

// ---- ヘルパー ----
// 入力欄は位取りカンマ表示のため type="text" で送信されるので、カンマを除去してから数値化する
function _int(string $key): int { return (int)str_replace(',', '', (string)($_POST[$key] ?? '0')); }

function fv(array $fd, string $key): string {
    $v = (int)($fd[$key] ?? 0);
    return $v > 0 ? number_format($v) : '';
}
function rankMedal(int $rank): string {
    return match ($rank) { 1 => '👑', 2 => '🥈', 3 => '🥉', default => '' };
}
function py(array $py_fd, string $key): string {
    $v = (int)($py_fd[$key] ?? 0);
    return $v > 0 ? number_format($v) : '―';
}

/**
 * pos_API 明細を「作成情報タイムスタンプ」で時刻カットオフ集計する。
 * $cutoff_hm = 'HH:MM:SS' 形式。null なら全日（カットオフなし）。
 * 客数はレシート番号（伝票単位）の distinct 件数、累計売上は販売金額の合計。
 * $all_busho を渡すと、部門別（売上日報のフィールドキー単位）の内訳も by_field で返す
 * （「レジの実績を反映」ボタン用。pos_API の「部門」はレジ側の表示カテゴリー名で
 * 記録されており、売上日報の部門ラベルと同じ文字列であることを前提にしている）。
 */
function totoregiAgg(array $pos_records, ?string $cutoff_hm, array $all_busho = []): array {
    $label_to_field = array_flip($all_busho);
    $receipts = [];
    $sum = 0;
    $by_field = array_fill_keys(array_keys($all_busho), 0);
    foreach ($pos_records as $row) {
        $f  = $row['fieldData'];
        $ts = $f['作成情報タイムスタンプ'] ?? '';
        if ($cutoff_hm !== null) {
            $dt = \DateTime::createFromFormat('m/d/Y H:i:s', $ts);
            if (!$dt || $dt->format('H:i:s') > $cutoff_hm) continue;
        }
        $rno = trim((string)($f['レシート番号'] ?? ''));
        if ($rno !== '') $receipts[$rno] = true;
        $kingaku = (int)($f['販売金額'] ?? 0);
        $sum += $kingaku;
        $label = trim((string)($f['部門'] ?? ''));
        if (isset($label_to_field[$label])) {
            $by_field[$label_to_field[$label]] += $kingaku;
        }
    }
    return ['count' => count($receipts), 'sum' => $sum, 'by_field' => $by_field];
}
function trCount(array $tr): string {
    return $tr['count'] > 0 ? '<span class="tr-val">' . number_format($tr['count']) . '</span>件' : '<span class="tr-none">―</span>';
}
function trSum(array $tr): string {
    return $tr['sum'] > 0 ? '<span class="tr-val">¥' . number_format($tr['sum']) . '</span>' : '<span class="tr-none">―</span>';
}

// 当年・前年 部門合計
function bushoGoukei(array $fd, array $keys): int {
    return array_sum(array_map(fn($k) => (int)($fd[$k] ?? 0), $keys));
}
$busho_keys   = array_keys($all_busho);
$goukei_this  = bushoGoukei($fd,    $busho_keys);
$goukei_py    = bushoGoukei($py_fd, $busho_keys);
// FM計算フィールド「合計売上」を表示用に使用（PHP集計より正確）
$goukei_fm    = (int)($fd['合計売上']    ?? 0);
$goukei_py_fm = (int)($py_fd['合計売上'] ?? 0);
// 上代合計（店舗が直接入力する合計目標額。部門別ではなく単一の数値フィールド）
$joudai_goukei_fm = (int)($fd['上代合計'] ?? 0);

$badge_map = ['未入力' => 'secondary', '入力中' => 'warning text-dark', '確定' => 'success'];
$badge_cls = $badge_map[$nyuryoku_jotai] ?? 'secondary';

include __DIR__ . '/header.php';
?>

<style>
:root { --pos-font-size: 17px; }
.dr-wrap {
    font-size: var(--pos-font-size);
    max-width: 680px;
    margin: 0 auto;
    padding: 0 0.4em 3em;
}

/* ページヘッダー */
.dr-header {
    text-align: center;
    padding: 0.7em 0 0.5em;
    border-bottom: 2px solid #e0e0e0;
    margin-bottom: 0.8em;
}
.dr-header .store-name { font-size: 1.1em; font-weight: bold; color: #004d40; }
.dr-header .target-date { font-size: 0.82em; color: #888; margin-top: 0.2em; }

/* セクション */
.dr-section {
    background: #fff;
    border-radius: 0.8em;
    border: 2px solid #e0e0e0;
    margin-bottom: 0.8em;
    overflow: hidden;
    box-shadow: 0 2px 6px rgba(0,0,0,.04);
}
.dr-section-head {
    background: #004d40;
    color: #fff;
    padding: 0.5em 0.9em;
    font-size: 0.88em;
    font-weight: bold;
    display: flex;
    align-items: center;
    justify-content: space-between;
}
.dr-section-head .done-mark {
    font-size: 0.85em;
    background: #4caf50;
    border-radius: 1em;
    padding: 0.1em 0.6em;
}
.dr-section-body { padding: 0.7em 0.9em; }

/* 比較グリッド（ラベル | 入力 | ととレジ実績 | 前年） */
.cmp-grid {
    display: grid;
    grid-template-columns: 4em 1fr 4.5em 4.5em;
    align-items: center;
    gap: 0.4em 0.4em;
    margin-bottom: 0.3em;
}
.cmp-label {
    font-size: 0.85em;
    color: #444;
    text-align: right;
    white-space: nowrap;
}
.cmp-input {
    font-size: 1.25em;
    font-weight: bold;
    text-align: right;
    padding: 0.3em 0.5em;
    border: 2px solid #ccc;
    border-radius: 0.5em;
    background: #fafafa;
    color: #004d40;
    width: 100%;
    box-sizing: border-box;
    -webkit-appearance: none;
}
.cmp-input:focus {
    outline: none;
    border-color: #004d40;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(0,77,64,.12);
}
.cmp-input:disabled {
    background: #f0f0f0;
    color: #999;
    border-color: #ddd;
}
.cmp-unit {
    font-size: 0.75em;
    color: #888;
    display: inline;
    margin-left: 0.2em;
}
.cmp-py {
    font-size: 0.88em;
    text-align: right;
    color: #555;
    white-space: nowrap;
}
.cmp-py .py-val { font-weight: bold; color: #1565c0; }
.cmp-py .py-none { color: #ccc; }

/* ととレジ実績（参照専用・pos_API集計） */
.cmp-tr {
    font-size: 0.88em;
    text-align: right;
    color: #2e7d32;
    white-space: nowrap;
}
.cmp-tr .tr-val { font-weight: bold; color: #2e7d32; }
.cmp-tr .tr-none { color: #ccc; }

/* ヘッダー行 */
.cmp-header {
    display: grid;
    grid-template-columns: 4em 1fr 4.5em 4.5em;
    gap: 0.4em 0.4em;
    margin-bottom: 0.1em;
}
.cmp-header span {
    font-size: 0.72em;
    color: #aaa;
    text-align: right;
}
.cmp-header .h-input { text-align: center; }

/* 合計行 */
.total-bar {
    display: grid;
    grid-template-columns: 4em 1fr minmax(4.5em, auto) minmax(4.5em, auto);
    align-items: center;
    gap: 0.4em 0.4em;
    padding: 0.4em 0 0;
    border-top: 2px solid #004d40;
    margin-top: 0.5em;
}
.total-bar .t-label { font-size: 0.85em; font-weight: bold; color: #004d40; }
.total-bar .t-this  { font-size: 1.25em; font-weight: bold; color: #004d40; }
.total-bar .t-tr    { font-size: 0.88em; color: #2e7d32; font-weight: bold; text-align: right; white-space: nowrap; }
.total-bar .t-py    { font-size: 0.88em; color: #1565c0; font-weight: bold; text-align: right; white-space: nowrap; }

/* 保存ボタン */
.save-btn {
    display: block; width: 100%;
    padding: 0.65em;
    margin-top: 0.6em;
    background: #004d40; color: #fff;
    border: none; border-radius: 0.5em;
    font-size: 0.95em; font-weight: bold;
    cursor: pointer; transition: background .15s;
}
.save-btn:hover { background: #00695c; }
.save-btn.kakutei { background: #b71c1c; margin-top: 0.35em; }
.save-btn.kakutei:hover { background: #c62828; }
.save-btn:disabled { background: #ccc; cursor: default; }

/* レジ実績 反映ボタン */
.reflect-btn {
    display: block; width: 100%;
    padding: 0.6em;
    margin-top: 0.6em;
    background: #fff; color: #1565c0;
    border: 1px solid #1565c0; border-radius: 0.5em;
    font-size: 0.9em; font-weight: bold;
    cursor: pointer; transition: background .15s;
}
.reflect-btn:hover { background: #e3f2fd; }
.reflect-btn:disabled { background: #eee; color: #999; border-color: #ccc; cursor: default; }

/* アコーディオン（部門設定） */
.accord-wrap { margin-bottom: 0.6em; }
.accord-head {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 0.5em 0.7em;
    background: #f0f8f0;
    border: 1px solid #c8d8c8;
    border-radius: 0.5em;
    cursor: pointer;
    font-size: 0.82em;
    font-weight: bold;
    color: #004d40;
    user-select: none;
}
.accord-head .accord-arrow { transition: transform .2s; }
.accord-head.open .accord-arrow { transform: rotate(180deg); }
.accord-body {
    display: none;
    padding: 0.7em;
    border: 1px solid #c8d8c8;
    border-top: none;
    border-radius: 0 0 0.5em 0.5em;
    background: #fafff8;
}
.accord-body.open { display: block; }

.teikyu-list { list-style: none; margin: 0; padding: 0; font-size: 0.85em; }
.teikyu-list li { padding: 0.3em 0.1em; border-bottom: 1px solid #eee; }
.teikyu-list li:last-child { border-bottom: none; }
.teikyu-list a { color: #004d40; text-decoration: none; font-weight: bold; }
.teikyu-list a:hover { text-decoration: underline; }

.bumon-grid {
    display: grid;
    grid-template-columns: 1fr 1fr 1fr;
    gap: 0.35em;
    margin-bottom: 0.6em;
}
.bumon-cb-label {
    display: flex; align-items: center; gap: 0.35em;
    font-size: 0.88em;
    padding: 0.25em 0.4em;
    border-radius: 0.35em;
    border: 1px solid #ddd;
    cursor: pointer;
    background: #fff;
}
.bumon-cb-label:hover { background: #f0f8f0; }
.bumon-cb-label input { width: 1.1em; height: 1.1em; flex-shrink: 0; }
.accord-save-btn {
    background: #004d40; color: #fff;
    border: none; border-radius: 0.4em;
    padding: 0.5em 1.2em;
    font-size: 0.88em; font-weight: bold;
    cursor: pointer;
}
.accord-save-btn:hover { background: #00695c; }

/* 部門行（未選択は非表示） */
.busho-cmp { display: none; }
.busho-cmp.active { display: contents; }

/* 店舗ランキング（自店の順位＋前後の店舗のみ・金額は非公開） */
.rank-list { list-style: none; margin: 0; padding: 0; }
.rank-row {
    display: flex; align-items: center; gap: 0.7em;
    padding: 0.45em 0.3em;
    font-size: 0.9em;
    border-bottom: 1px solid #eee;
}
.rank-row:last-child { border-bottom: none; }
.rank-row .rank-num {
    flex-shrink: 0; min-width: 3em; text-align: right;
    font-weight: bold; color: #888; white-space: nowrap;
}
.rank-row.rank-me {
    background: #e0f2f1;
    border-radius: 0.5em;
    font-weight: bold;
}
.rank-row.rank-me .rank-num { color: #004d40; }
.rank-row.rank-me .rank-name { color: #004d40; }

/* 確定バナー */
.kakutei-banner {
    background: #e8f5e9; border: 2px solid #4caf50;
    border-radius: 0.7em; padding: 0.8em;
    text-align: center; font-weight: bold;
    color: #2e7d32; margin-bottom: 0.8em;
}
.kaijo-btn {
    display: inline-block;
    margin-top: 0.5em;
    padding: 0.35em 1.2em;
    background: #fff3e0;
    color: #e65100;
    border: 2px solid #e65100;
    border-radius: 0.5em;
    font-size: 0.82em;
    font-weight: bold;
    cursor: pointer;
}
.kaijo-btn:hover { background: #ffe0b2; }

/* 日付ナビ */
.date-nav {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.4em;
    margin-top: 0.5em;
}
.date-nav-btn {
    background: #004d40; color: #fff;
    border: none; border-radius: 0.4em;
    padding: 0.25em 0.7em;
    font-size: 1em; font-weight: bold;
    cursor: pointer; text-decoration: none;
    line-height: 1.6;
}
.date-nav-btn:hover { background: #00695c; color:#fff; }
.date-nav-btn.disabled {
    background: #ccc; cursor: default; pointer-events: none;
}
.date-input-wrap input[type=date] {
    font-size: 0.88em;
    padding: 0.25em 0.5em;
    border: 2px solid #004d40;
    border-radius: 0.4em;
    color: #004d40;
    font-weight: bold;
    cursor: pointer;
    background: #f0faf8;
}

/* 前年日付ラベル */
.py-date-label {
    font-size: 0.72em; color: #aaa; text-align: right;
    margin-bottom: 0.3em;
}

/* 注意書き */
.spec-note {
    font-size: 0.76em;
    color: #555;
    background: #fffde7;
    border-left: 3px solid #f9a825;
    border-radius: 0 0.4em 0.4em 0;
    padding: 0.45em 0.7em;
    margin-top: 0.4em;
    line-height: 1.6;
}
.spec-note .spec-note-title {
    font-weight: bold;
    color: #e65100;
    margin-bottom: 0.2em;
}
.spec-note ul {
    margin: 0.2em 0 0 1.2em;
    padding: 0;
}
.spec-note li { margin-bottom: 0.1em; }
</style>

<div class="dr-wrap">

  <!-- ページヘッダー -->
  <div class="dr-header">
    <div class="store-name">📋 売上日報入力</div>
    <div class="target-date">
      <?= htmlspecialchars($store_name) ?> ／ <?= htmlspecialchars($target_date_jp) ?>
      <span class="badge bg-<?= $badge_cls ?> ms-1" style="font-size:.75em;"><?= $nyuryoku_jotai ?></span>
    </div>
    <!-- 日付ナビ -->
    <div class="date-nav">
      <a class="date-nav-btn" href="?date=<?= urlencode($prev_date_fm) ?>">◀</a>
      <span class="date-input-wrap">
        <input type="date" id="date-picker" value="<?= $date_html ?>"
               max="<?= $date_html_max ?>"
               onchange="goDate(this.value)">
      </span>
      <a class="date-nav-btn <?= $next_is_too_far ? 'disabled' : '' ?>"
         href="?date=<?= urlencode($next_date_fm) ?>">▶</a>
    </div>
    <!-- 日付ナビ仕様注意書き -->
    <div class="spec-note" style="text-align:left; margin-top:0.5em;">
      <div class="spec-note-title">ℹ 日付ナビゲーションについて</div>
      <ul>
        <li>◀▶ で前後の日付に移動できます。</li>
        <li>▶ は本日から最大 <strong>30日先</strong> まで進めることができます。</li>
        <li>未来の日付は <strong>前年データの参照のみ</strong> 可能です（入力・保存はできません）。</li>
      </ul>
    </div>
  </div>

  <?php if ($success_msg): ?>
    <?php
      $alert_cls = (str_starts_with($success_msg, '❌')) ? 'alert-danger'
                 : (str_starts_with($success_msg, '⚠️') ? 'alert-warning' : 'alert-success');
    ?>
    <div class="alert <?= $alert_cls ?> py-2 text-center mb-2" style="font-size:.88em;"><?= $success_msg ?></div>
  <?php endif; ?>
  <?php if ($is_future_page && !$is_kakutei): ?>
    <div class="kakutei-banner" style="background:#e3f2fd; border-left-color:#1565c0; color:#1565c0;">
      📅 この日付はまだ到来していません。前年データの参照と、定休日の設定のみ可能です。
    </div>
  <?php endif; ?>
  <?php if ($is_kakutei): ?>
    <div class="kakutei-banner">
      <?php if ((int)($fd['定休日'] ?? 0) === 1): ?>
        🏠 この日は定休日として確定済みです。
      <?php else: ?>
        ✅ この日の売上日報は確定済みです。
      <?php endif; ?>
      <br>
      <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="kaijo">
        <button type="button" class="kaijo-btn"
                onclick="if(confirm('確定を解除すると再入力が可能になります。\nよろしいですか？')) this.closest('form').submit()">
          🔓 確定を解除する
        </button>
      </form>
    </div>
  <?php endif; ?>

  <!-- 今月の店舗ランキング -->
  <div class="dr-section">
    <div class="dr-section-head">🏆 今月の店舗ランキング（<?= $rank_month ?>月<?= $rank_total > 0 ? '・全' . $rank_total . '店舗中' : '' ?>）</div>
    <div class="dr-section-body">
      <?php if ($rank_my_index === null): ?>
        <div style="font-size:0.85em; color:#888; text-align:center; padding:0.3em 0;">
          今月はまだ売上実績がありません。
        </div>
      <?php else: ?>
        <ul class="rank-list">
          <?php foreach ($rank_window as $i):
            $is_me = ($i === $rank_my_index);
            $r     = $rank_list[$i];
            $rank  = $i + 1;
          ?>
            <li class="rank-row <?= $is_me ? 'rank-me' : '' ?>">
              <span class="rank-num"><?= $rank ?>位　<?= rankMedal($rank) ?></span>
              <span class="rank-name">
                <?= $is_me ? htmlspecialchars($store_name) . '（自店）' : htmlspecialchars($r['name']) ?>
              </span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <div class="dr-section">
    <div class="dr-section-head">🏠 定休日</div>
    <div class="dr-section-body">
      <?php if (!$is_kakutei): ?>
        <div class="spec-note">
          <div class="spec-note-title">ℹ 定休日について</div>
          <ul>
            <li>この日が定休日（休業日）の場合は、下のボタンで確定してください。</li>
            <li>売上・客数はすべて0円・0人として確定されます（個別入力は不要です）。</li>
            <li>先の予定が分かっている場合は、未来の日付（最大30日先まで）でも設定できます。</li>
          </ul>
        </div>
        <form method="post">
          <input type="hidden" name="action" value="teikyubi">
          <button type="button" class="save-btn kakutei"
                  onclick="if(confirm('この日（<?= htmlspecialchars($target_date_jp) ?>）を定休日として確定します。\n売上・客数はすべて0で確定されます。よろしいですか？')) this.closest('form').submit()">
            🏠 この日を定休日として確定する
          </button>
        </form>
      <?php endif; ?>

      <!-- ▼ 定休日一覧アコーディオン（自店舗分のみ） -->
      <div class="accord-wrap" style="margin-top:0.7em;">
        <div class="accord-head" onclick="this.classList.toggle('open'); this.nextElementSibling.classList.toggle('open')">
          📅 定休日一覧（<?= count($teikyu_list) ?>件・クリックで開閉）
          <span class="accord-arrow">▼</span>
        </div>
        <div class="accord-body">
          <?php if (empty($teikyu_list)): ?>
            <div style="font-size:0.85em; color:#888;">登録されている定休日はありません。</div>
          <?php else: ?>
            <ul class="teikyu-list">
              <?php foreach ($teikyu_list as $tk_fm):
                $tk_dt = \DateTime::createFromFormat('m/d/Y', $tk_fm);
                if (!$tk_dt) continue;
                $tk_label = $tk_dt->format('Y年n月j日') . '（' . ($week_ja[$tk_dt->format('l')] ?? '') . '）';
                $tk_is_past = $tk_dt < $today_dt;
              ?>
              <li<?= $tk_is_past ? ' style="color:#999;"' : '' ?>>
                <a href="?date=<?= urlencode($tk_fm) ?>"><?= htmlspecialchars($tk_label) ?></a>
              </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

  <!-- 上代（毎日1回・時間帯に関係なく入力可・合計のみ） -->
  <div class="dr-section">
    <div class="dr-section-head">
      🎯 本日の上代
      <?php if ($joudai_goukei_fm > 0): ?>
        <span class="done-mark">✓ 入力済</span>
      <?php endif; ?>
    </div>
    <div class="dr-section-body">
      <div class="spec-note">
        <div class="spec-note-title">ℹ 上代について</div>
        <ul>
          <li>「今日はこれだけ売るつもり」という金額（仕入れ額に見合った販売目標額）の合計を入力してください。</li>
          <li>時間帯に関係なく、1日1回まとめて設定・修正できます（確定後も編集可能です）。</li>
        </ul>
      </div>
      <div class="cmp-header">
        <span></span>
        <span class="h-input">上代（目標額）</span>
        <span></span>
        <span></span>
      </div>
      <form method="post" id="form-joudai">
        <input type="hidden" name="action" value="save_joudai">
        <div class="cmp-grid">
          <span class="cmp-label">上代合計</span>
          <div>
            <input class="cmp-input" type="text" inputmode="numeric" name="上代合計"
                   value="<?= fv($fd, '上代合計') ?>"
                   <?= $is_future_page ? 'disabled' : '' ?>>
            <span class="cmp-unit">円</span>
          </div>
          <div class="cmp-py"></div>
        </div>

        <?php if (!$is_future_page): ?>
          <button type="submit" class="save-btn">💾 上代を保存</button>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <!-- 前年日付表示 -->
  <div class="py-date-label">前年同週同曜日：<?= htmlspecialchars($py_date_jp) ?></div>
  <!-- 前年データ仕様注意書き -->
  <div class="spec-note" style="margin-bottom:0.6em;">
    <div class="spec-note-title">ℹ 前年データ（右列）について</div>
    <ul>
      <li>右列に表示される前年データは <strong>前年同週同曜日</strong> のデータです（同じ曜日・ISO週）。</li>
      <li>前年データは参照のみで、編集はできません。</li>
    </ul>
  </div>
  <!-- ととレジ実績 仕様注意書き -->
  <div class="spec-note" style="margin-bottom:0.6em;">
    <div class="spec-note-title">ℹ ととレジ実績（緑色の列）について</div>
    <ul>
      <li>ととレジで登録された実際の売上を、その時刻までの分だけ自動集計して表示しています（参照のみ・保存はされません）。</li>
      <li>客数はレシート枚数（会計回数）です。</li>
      <li>本日分は表示のたびにリアルタイムで再集計されます。</li>
    </ul>
  </div>

  <?php
  // 比較ヘッダー行（共通）
  $cmp_header = '<div class="cmp-header">
      <span></span>
      <span class="h-input">本　年</span>
      <span>ととレジ</span>
      <span>前年同曜日</span>
  </div>';
  // 部門別行のヘッダー（ととレジ列は部門別実績が無いため空欄。閉店後で使用）
  $cmp_header_bumon = '<div class="cmp-header">
      <span></span>
      <span class="h-input">本　年</span>
      <span></span>
      <span>前年同曜日</span>
  </div>';

  // 時間帯セクション出力関数（客数・累計売上とも時間帯合計のみ入力。部門別入力は閉店後のみ）
  function timeSec(string $suffix, array $slot,
                   array $fd, array $py_fd, bool $is_kakutei,
                   string $cmp_header, array $tr): void {
      $label        = $slot['label'];
      $action       = $slot['action'];
      $field_kyaku  = $slot['field_kyaku'];
      $field_uriage = $slot['field_uriage'];

      $uriage_val    = (int)($fd[$field_uriage] ?? 0);
      $py_uriage_val = (int)($py_fd[$field_uriage] ?? 0);

      $done = (int)($fd[$field_kyaku] ?? 0) > 0 || $uriage_val > 0;
      echo '<div class="dr-section" id="dr-section-' . $suffix . '">';
      echo '<div class="dr-section-head">' . $label;
      if ($done) echo ' <span class="done-mark">✓ 入力済</span>';
      echo '</div>';
      echo '<div class="dr-section-body">';
      echo '<form method="post">';
      echo '<input type="hidden" name="action" value="' . $action . '">';

      // 客数（時間帯合計のみ）
      echo $cmp_header;
      $pyv_k = (int)($py_fd[$field_kyaku] ?? 0);
      echo '<div class="cmp-grid">';
      echo '<span class="cmp-label">客　数</span>';
      echo '<div><input class="cmp-input" id="kyaku-input-' . $suffix . '" type="text" inputmode="numeric" name="' . $field_kyaku . '" value="' . fv($fd, $field_kyaku) . '" ' . ($is_kakutei ? 'disabled' : '') . '><span class="cmp-unit">人</span></div>';
      echo '<div class="cmp-tr">' . trCount($tr) . '</div>';
      echo '<div class="cmp-py">' . ($pyv_k > 0 ? '<span class="py-val">' . number_format($pyv_k) . '</span> 人' : '<span class="py-none">―</span>') . '</div>';
      echo '</div>';

      // 累計売上（部門別ではなく時間帯合計のみ）
      echo '<div class="cmp-grid">';
      echo '<span class="cmp-label">累計売上</span>';
      echo '<div><input class="cmp-input" id="uriage-input-' . $suffix . '" type="text" inputmode="numeric" name="' . $field_uriage . '" value="' . fv($fd, $field_uriage) . '" ' . ($is_kakutei ? 'disabled' : '') . '><span class="cmp-unit">円</span></div>';
      echo '<div class="cmp-tr">' . trSum($tr) . '</div>';
      echo '<div class="cmp-py">' . ($py_uriage_val > 0 ? '<span class="py-val">¥' . number_format($py_uriage_val) . '</span>' : '<span class="py-none">―</span>') . '</div>';
      echo '</div>';

      if (!$is_kakutei) {
          $plain_label = preg_replace('/[🕛🕒🕔]\s*/', '', $label);
          echo '<button type="button" class="reflect-btn" onclick="reflectRegi(\'' . $suffix . '\', this)">🧾 レジの実績を反映</button>';
          echo '<button type="submit" class="save-btn">💾 ' . $plain_label . ' を保存</button>';
      }
      echo '</form>';
      echo '</div></div>';
  }
  ?>

  <?php foreach ($time_slots as $suffix => $slot): ?>
    <?php timeSec($suffix, $slot, $fd, $py_fd, $is_kakutei || $is_future_page,
        $cmp_header, ${'tr_' . $suffix}); ?>
  <?php endforeach; ?>

  <!-- 閉店後 -->
  <div class="dr-section">
    <div class="dr-section-head">
      🔒 閉店後
      <?php if ($goukei_fm > 0): ?>
        <span class="done-mark">✓ 入力済</span>
      <?php endif; ?>
    </div>
    <div class="dr-section-body">

      <!-- ▼ 部門設定アコーディオン -->
      <div class="accord-wrap">
        <div class="accord-head" id="accord-head" onclick="toggleAccord()">
          ⚙ 自店の取扱部門を設定（クリックで開閉）
          <span class="accord-arrow">▼</span>
        </div>
        <div class="accord-body" id="accord-body">
          <div class="bumon-grid">
            <?php foreach ($all_busho as $field => $label): ?>
            <label class="bumon-cb-label">
              <input type="checkbox" class="bumon-cb" value="<?= $field ?>">
              <?= $label ?>
            </label>
            <?php endforeach; ?>
          </div>
          <button type="button" class="accord-save-btn" onclick="saveBumonSetting()">
            この設定で保存して閉じる
          </button>
          <div class="spec-note" style="margin-top:0.6em;">
            <div class="spec-note-title">ℹ 部門設定について</div>
            <ul>
              <li>チェックした部門のみ入力欄に表示されます。</li>
              <li>設定は <strong>このブラウザ（端末）に保存</strong> されます。</li>
              <li>別の端末やブラウザでは再設定が必要です。</li>
            </ul>
          </div>
        </div>
      </div>

      <!-- 部門別売上フォーム -->
      <form method="post" id="form-heiten">
        <input type="hidden" name="action" id="heiten-action" value="save_heiten">

        <!-- 部門一覧（部門別のととレジ実績は対象外のため列は空欄） -->
        <?= $cmp_header_bumon ?>
        <?php foreach ($all_busho as $field => $label): ?>
        <?php $pyv = (int)($py_fd[$field] ?? 0); ?>
        <div class="cmp-grid busho-cmp" data-field="<?= $field ?>">
          <span class="cmp-label"><?= $label ?></span>
          <div>
            <input class="cmp-input busho-input" type="text" inputmode="numeric" name="<?= $field ?>"
                   value="<?= fv($fd, $field) ?>"
                   <?= ($is_kakutei || $is_future_page) ? 'disabled' : '' ?>>
            <span class="cmp-unit">円</span>
          </div>
          <div class="cmp-py">
            <?= $pyv > 0 ? '<span class="py-val">¥' . number_format($pyv) . '</span>' : '<span class="py-none">―</span>' ?>
          </div>
        </div>
        <?php endforeach; ?>

        <!-- 合計行 -->
        <div class="total-bar">
          <span class="t-label">合　計</span>
          <span class="t-this" id="busho-goukei">
            <?= $goukei_fm > 0 ? '¥' . number_format($goukei_fm) : '―' ?>
          </span>
          <span class="t-tr"><?= trSum($tr_all) ?></span>
          <span class="t-py">
            <?= $goukei_py_fm > 0 ? '¥' . number_format($goukei_py_fm) : '' ?>
          </span>
        </div>

        <!-- 客数合計 -->
        <div style="margin-top:0.9em;">
          <?= $cmp_header ?>
          <?php $pyv_k = (int)($py_fd['客数_閉店後'] ?? 0); ?>
          <div class="cmp-grid">
            <span class="cmp-label">客数合計</span>
            <div>
              <input class="cmp-input" type="text" inputmode="numeric" name="客数_閉店後"
                     value="<?= fv($fd, '客数_閉店後') ?>"
                     <?= ($is_kakutei || $is_future_page) ? 'disabled' : '' ?>>
              <span class="cmp-unit">人</span>
            </div>
            <div class="cmp-tr"><?= trCount($tr_all) ?></div>
            <div class="cmp-py">
              <?= $pyv_k > 0 ? '<span class="py-val">' . number_format($pyv_k) . '</span> 人' : '<span class="py-none">―</span>' ?>
            </div>
          </div>
        </div>

        <!-- 保存・確定ボタン -->
        <?php if (!$is_kakutei && !$is_future_page): ?>
          <button type="button" class="save-btn"
                  onclick="submitHeiten('save_heiten')">💾 閉店後データを保存</button>
          <button type="button" class="save-btn kakutei"
                  onclick="if(confirm('確定すると変更できません。よろしいですか？')) submitHeiten('kakutei')">
            🔒 本日分を確定する
          </button>
        <?php endif; ?>
      </form>

    </div>
  </div>

</div><!-- /.dr-wrap -->

<script>
const STORE_ID  = '<?= htmlspecialchars($store_id) ?>';
const BUMON_KEY = 'dr_bumon_' + STORE_ID;
const DR_DATE   = <?= json_encode($target_date_fm) ?>;
// 取扱部門の設定（チェックボックス）に関わらず、実績（保存済みの値・レジ実績）が
// ある部門は常に表示する
const BUSHO_HAS_DATA = <?= json_encode($busho_has_data, JSON_UNESCAPED_UNICODE) ?>;

// ---- 数字入力欄の位取りカンマ表示 ----
function digitsOnly(v) { return String(v || '').replace(/[^0-9]/g, ''); }
function formatComma(v) {
    const d = digitsOnly(v);
    return d === '' ? '' : parseInt(d, 10).toLocaleString('en-US');
}
document.querySelectorAll('.cmp-input').forEach(el => {
    el.addEventListener('input', () => { el.value = formatComma(el.value); });
});

// ---- レジの実績を反映 ----
// ボタンを押した時点で毎回サーバーに最新の pos_records を問い合わせて集計するため、
// ページを開いてからしばらく経っていたり、押すタイミングが12/15/17時ちょうどから
// 多少前後していても、その時点までの実績が正しく反映される。
async function reflectRegi(suffix, btn) {
    const origText = btn.textContent;
    btn.disabled = true;
    btn.textContent = '取得中…';
    try {
        const url = '?date=' + encodeURIComponent(DR_DATE) +
                    '&ajax=reflect_regi&slot=' + encodeURIComponent(suffix);
        const res = await fetch(url);
        const data = await res.json();
        if (!data.ok) {
            if (data.error === 'session_expired') {
                alert('セッションが切れています。ページを再読み込みしてログインし直してください。');
            } else {
                alert('レジ実績の取得に失敗しました。');
            }
            return;
        }
        const kyakuInput = document.getElementById('kyaku-input-' + suffix);
        if (kyakuInput && !kyakuInput.disabled) kyakuInput.value = formatComma(data.kyaku);
        const uriageInput = document.getElementById('uriage-input-' + suffix);
        if (uriageInput && !uriageInput.disabled) uriageInput.value = formatComma(data.sum);
    } catch (err) {
        alert('通信エラー: ' + err.message);
    } finally {
        btn.disabled = false;
        btn.textContent = origText;
    }
}

// ---- アコーディオン ----
function toggleAccord() {
    const head = document.getElementById('accord-head');
    const body = document.getElementById('accord-body');
    head.classList.toggle('open');
    body.classList.toggle('open');
}

// ---- 閉店後フォーム送信 ----
function submitHeiten(action) {
    const btns = document.querySelectorAll('#form-heiten .save-btn');
    btns.forEach(b => b.disabled = true);
    document.getElementById('heiten-action').value = action;
    document.getElementById('form-heiten').submit();
}

// ---- 二重送信防止（連打・ダブルタップでレコードが重複作成されるのを防ぐ） ----
document.addEventListener('submit', function (e) {
    const form = e.target;
    form.querySelectorAll('button[type="submit"], input[type="submit"]').forEach(btn => {
        btn.disabled = true;
    });
}, true);

// ---- 部門合計リアルタイム計算（閉店後） ----
// 非表示の部門でも送信される（0として計上）ため、合計は表示状態に関係なく全フィールドで計算する
function calcGoukeiFor(inputClass, totalElId) {
    let total = 0;
    document.querySelectorAll('.' + inputClass).forEach(el => {
        total += parseInt(digitsOnly(el.value) || '0', 10);
    });
    const el = document.getElementById(totalElId);
    if (el) el.textContent = total > 0 ? '¥' + total.toLocaleString() : '―';
}
document.querySelectorAll('.busho-input').forEach(el => {
    el.addEventListener('input', () => calcGoukeiFor('busho-input', 'busho-goukei'));
});

// ---- 部門設定 localStorage ----
function loadBumonSetting() {
    const saved = localStorage.getItem(BUMON_KEY);
    const active = saved ? JSON.parse(saved) : [];
    if (!saved && BUSHO_HAS_DATA.length === 0) {
        // 初回かつ実績もまだ無い：アコーディオンを自動オープン
        document.getElementById('accord-head').classList.add('open');
        document.getElementById('accord-body').classList.add('open');
    }
    applyBumonSetting(active);
    // チェックボックスは「保存済み設定」∪「実績がある部門」を反映
    const effective = new Set([...active, ...BUSHO_HAS_DATA]);
    document.querySelectorAll('.bumon-cb').forEach(cb => {
        cb.checked = effective.has(cb.value);
    });
}

function applyBumonSetting(activeFields) {
    // チェックした部門に加えて、実績（保存済みの値・レジ実績）がある部門は
    // 未選択でも常に表示する（手動設定漏れによる集計ミスを防ぐため）
    const effective = new Set([...activeFields, ...BUSHO_HAS_DATA]);
    document.querySelectorAll('.busho-cmp').forEach(row => {
        row.classList.toggle('active', effective.has(row.dataset.field));
    });
    // calcGoukeiFor() は初期化時に呼ばない。
    // FM合計売上（サーバーレンダリング値）を保持するため、
    // ユーザーが入力フィールドを編集したときのみ更新する。
}

function saveBumonSetting() {
    const selected = [];
    document.querySelectorAll('.bumon-cb:checked').forEach(cb => {
        selected.push(cb.value);
    });
    if (selected.length === 0) {
        alert('少なくとも1つ選択してください。');
        return;
    }
    localStorage.setItem(BUMON_KEY, JSON.stringify(selected));
    applyBumonSetting(selected);
    // アコーディオンを閉じる
    document.getElementById('accord-head').classList.remove('open');
    document.getElementById('accord-body').classList.remove('open');
}

// ---- 日付ナビ ----
function goDate(val) {
    if (!val) return;
    const parts = val.split('-'); // YYYY-MM-DD
    if (parts.length !== 3) return;
    const fm = parts[1] + '/' + parts[2] + '/' + parts[0]; // MM/DD/YYYY
    location.href = '?date=' + encodeURIComponent(fm);
}

// ---- 初期化 ----
loadBumonSetting();
</script>

<?php include __DIR__ . '/footer.php'; ?>
