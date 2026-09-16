<?php
/**
 * top.php  店舗ダッシュボード
 */
use fmRESTor\fmRESTor;
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['user'])) { header('Location: login.php'); exit(); }
if (($_SESSION['role'] ?? '') === 'hq') { header('Location: hq_top.php'); exit(); }
require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';

$store_id   = $_SESSION['store_id']   ?? '';
$store_name = $_SESSION['store_name'] ?? '';
$today_fm   = date('m/d/Y');
$today_jp   = date('Y年n月j日（D）', strtotime('today'));

// 曜日を日本語に
$week_en = ['Sun'=>'日','Mon'=>'月','Tue'=>'火','Wed'=>'水','Thu'=>'木','Fri'=>'金','Sat'=>'土'];
foreach ($week_en as $en => $ja) {
    $today_jp = str_replace($en, $ja, $today_jp);
}

// 本日売上取得
$today_total  = 0;
$today_count  = 0;
$receipt_count = 0;

$fm = new fmRESTor($host, $db, $layout_pos, $api_master_user, $api_master_pass, ['allowInsecure' => true]);
$query = [
    'query' => [[ '店舗No' => $store_id, '販売日時' => $today_fm ]],
    'limit' => 500,
];
$result  = $fm->findRecords($query);
$fm_code = $result['result']['messages'][0]['code'] ?? '0';
if ($fm_code !== '401') {
    $data = $result['result']['response']['data'] ?? [];
    $receipt_nos = [];
    foreach ($data as $row) {
        $f = $row['fieldData'];
        $today_total += (int)($f['販売金額'] ?? 0);
        $today_count++;
        $rno = $f['レシート番号'] ?? '';
        if ($rno !== '' && !in_array($rno, $receipt_nos)) $receipt_nos[] = $rno;
    }
    $receipt_count = count($receipt_nos);
}

// 未確定日報チェック（昨日以前・直近60日）
$unconfirmed_dates = [];
try {
    $fm_dr    = new fmRESTor($host, $db, $layout_daily_report,
                             $api_master_user, $api_master_pass, ['allowInsecure' => true]);
    $dr_from  = date('m/d/Y', strtotime('-60 days'));
    $dr_yest  = date('m/d/Y', strtotime('-1 day'));
    $dr_res   = $fm_dr->findRecords([
        'query' => [['fk_店舗No' => $store_id,
                     '売上日'    => $dr_from . '...' . $dr_yest]],
        'limit' => 120,
    ]);
    $dr_code  = $dr_res['result']['messages'][0]['code'] ?? '0';
    if ($dr_code !== '401') {
        foreach ($dr_res['result']['response']['data'] ?? [] as $drec) {
            $df     = $drec['fieldData'];
            $status = trim($df['入力状態'] ?? '');
            $uribi  = trim($df['売上日']   ?? '');
            if ($status !== '確定' && $uribi !== '') {
                $ts = strtotime($uribi);
                if ($ts) {
                    $unconfirmed_dates[] = [
                        'fm' => $uribi,
                        'jp' => date('Y年n月j日', $ts),
                        'ts' => $ts,
                    ];
                }
            }
        }
        usort($unconfirmed_dates, fn($a, $b) => $a['ts'] - $b['ts']);
    }
} catch (Throwable $e) { /* 取得失敗時は非表示 */ }

include __DIR__ . '/header.php';
?>
<style>
:root { --pos-font-size: 18px; }
.top-wrap {
    font-size: var(--pos-font-size);
    max-width: 640px;
    margin: 0 auto;
    padding: 0 0.5em 2em;
}

/* 店舗・日付ヘッダー */
.store-header {
    text-align: center;
    padding: 1em 0 0.8em;
    border-bottom: 2px solid #e0e0e0;
    margin-bottom: 1em;
}
.store-header .store-name {
    font-size: 1.2em;
    font-weight: bold;
    color: #004d40;
}
.store-header .today-date {
    font-size: 0.85em;
    color: #888;
    margin-top: 0.2em;
}

/* 売上サマリーカード */
.summary-cards {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.6em;
    margin-bottom: 1.2em;
}
.summary-card {
    background: #fff;
    border-radius: 0.8em;
    border: 2px solid #e0e0e0;
    padding: 0.8em 1em;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.summary-card.main {
    grid-column: 1 / -1;
    border-color: #004d40;
    background: linear-gradient(135deg, #004d40, #00695c);
    color: #fff;
}
.summary-card .label {
    font-size: 0.7em;
    opacity: .75;
    margin-bottom: 0.2em;
}
.summary-card.main .label { color: #fff; }
.summary-card .value {
    font-size: 1.8em;
    font-weight: bold;
    line-height: 1.1;
}
.summary-card.main .value { color: #fff; font-size: 2.2em; }
.summary-card .sub {
    font-size: 0.72em;
    color: #888;
    margin-top: 0.3em;
}
.summary-card.main .sub { color: rgba(255,255,255,.7); }

/* ナビボタン */
.nav-buttons {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.6em;
}
.nav-btn {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: 0.3em;
    padding: 1em 0.5em;
    border-radius: 0.8em;
    border: 2px solid #004d40;
    background: #fff;
    color: #004d40;
    font-size: 1em;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
    transition: all .15s;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.nav-btn:hover { background: #004d40; color: #fff; }
.nav-btn .nav-icon { font-size: 1.8em; line-height: 1; }
.nav-btn.primary {
    background: #004d40; color: #fff;
}
.nav-btn.primary:hover { background: #00695c; }

/* お知らせ */
.oshirase-wrap {
    margin: 1.2em 0;
    border-radius: 0.8em;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,.07);
}
.oshirase-title {
    background: #37474f;
    color: #fff;
    font-size: 0.82em;
    font-weight: bold;
    padding: 0.4em 0.9em;
    letter-spacing: .06em;
}
.oshirase-item {
    display: flex;
    align-items: flex-start;
    gap: 0.7em;
    padding: 0.8em 1em;
    background: #fff;
    border-left: 4px solid #ccc;
}
.oshirase-item.warn {
    border-left-color: #e65100;
    background: #fff8f5;
}
.oshirase-icon { font-size: 1.4em; flex-shrink: 0; }
.oshirase-head {
    font-weight: bold;
    font-size: 0.9em;
    color: #bf360c;
    margin-bottom: 0.35em;
}
.oshirase-dates {
    display: flex;
    flex-wrap: wrap;
    gap: 0.4em;
}
.date-chip {
    display: inline-block;
    padding: 0.2em 0.7em;
    border-radius: 1em;
    background: #e65100;
    color: #fff;
    font-size: 0.78em;
    font-weight: bold;
    text-decoration: none;
    white-space: nowrap;
}
.date-chip:hover { background: #bf360c; color: #fff; }
</style>

<div class="top-wrap">

  <!-- 店舗・日付 -->
  <div class="store-header">
    <div class="store-name">🐟 <?= htmlspecialchars($store_name) ?></div>
    <div class="today-date"><?= $today_jp ?></div>
  </div>

  <!-- 売上サマリー -->
  <div class="summary-cards">

    <!-- 本日売上合計（大） -->
    <div class="summary-card main">
      <div class="label">本日の売上合計</div>
      <div class="value">¥<?= number_format($today_total) ?></div>
      <div class="sub">
        レシート <?= $receipt_count ?> 枚 ／ 明細 <?= $today_count ?> 件
      </div>
    </div>

    <!-- レシート枚数 -->
    <div class="summary-card">
      <div class="label">レシート枚数</div>
      <div class="value" style="color:#004d40;"><?= $receipt_count ?></div>
      <div class="sub">枚</div>
    </div>

    <!-- 明細件数 -->
    <div class="summary-card">
      <div class="label">明細件数</div>
      <div class="value" style="color:#004d40;"><?= $today_count ?></div>
      <div class="sub">件</div>
    </div>

  </div>

  <!-- ナビゲーション -->
  <div class="nav-buttons">
    <a href="sales_entry.php" class="nav-btn primary">
      <span class="nav-icon">＋</span>
      レジ
    </a>
    <a href="sales_list.php" class="nav-btn">
      <span class="nav-icon">≡</span>
      売上一覧
    </a>
    <a href="daily_report_entry.php" class="nav-btn">
      <span class="nav-icon">📋</span>
      売上日報入力
    </a>
    <a href="haiki_entry.php" class="nav-btn">
      <span class="nav-icon">🗑</span>
      廃棄数入力
    </a>
    <a href="daily_report_mystore.php" class="nav-btn">
      <span class="nav-icon">📈</span>
      自店舗成績
    </a>
    <a href="shohin_maint.php" class="nav-btn">
      <span class="nav-icon">📦</span>
      商品メンテ
    </a>
  </div>

  <!-- お知らせ -->
  <?php if (!empty($unconfirmed_dates)): ?>
  <div class="oshirase-wrap">
    <div class="oshirase-title">📢 お知らせ</div>
    <div class="oshirase-item warn">
      <div class="oshirase-icon">⚠️</div>
      <div>
        <div class="oshirase-head">売上日報入力未確定</div>
        <div class="oshirase-dates">
          <?php foreach ($unconfirmed_dates as $item): ?>
            <a href="daily_report_entry.php?date=<?= urlencode($item['fm']) ?>"
               class="date-chip">
              <?= htmlspecialchars($item['jp']) ?>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <!-- プリンター設定 -->
  <div style="margin-top:1.4em; text-align:center;">
    <a href="https://play.google.com/store/apps/details?id=com.starmicronics.starquicksetuputility&pli=1"
       target="_blank" rel="noopener"
       style="display:inline-flex; align-items:center; gap:0.5em;
              padding:0.6em 1.2em; border-radius:0.6em;
              border:1.5px solid #ccc; background:#f9f9f9;
              color:#444; font-size:0.82em; text-decoration:none;">
      🖨️ Star レシートプリンター設定アプリ（Android）
    </a>
  </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>
