<?php
/**
 * hq_top.php  本社ダッシュボード
 */
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hq') {
    header('Location: login.php'); exit();
}

include __DIR__ . '/hq_header.php';
?>
<style>
:root { --hq-accent: #1a237e; }
.hq-wrap {
    font-size: 18px;
    max-width: 640px;
    margin: 0 auto;
    padding: 0 0.5em 2em;
}

/* ヘッダー */
.hq-page-header {
    text-align: center;
    padding: 1em 0 0.8em;
    border-bottom: 2px solid #e0e0e0;
    margin-bottom: 1.2em;
}
.hq-page-header .hq-title {
    font-size: 1.2em;
    font-weight: bold;
    color: var(--hq-accent);
}
.hq-page-header .hq-sub {
    font-size: 0.85em;
    color: #888;
    margin-top: 0.2em;
}

/* セクションラベル */
.section-label {
    font-size: 0.8em;
    font-weight: bold;
    color: #888;
    letter-spacing: .1em;
    text-transform: uppercase;
    margin: 1em 0 0.5em;
    padding-left: 0.2em;
    border-left: 3px solid var(--hq-accent);
    padding-left: 0.5em;
}

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
    padding: 1.1em 0.5em;
    border-radius: 0.8em;
    border: 2px solid var(--hq-accent);
    background: #fff;
    color: var(--hq-accent);
    font-size: 1em;
    font-weight: bold;
    text-decoration: none;
    cursor: pointer;
    transition: all .15s;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
}
.nav-btn:hover { background: var(--hq-accent); color: #fff; }
.nav-btn .nav-icon { font-size: 1.8em; line-height: 1; }
.nav-btn.primary {
    background: var(--hq-accent); color: #fff;
}
.nav-btn.primary:hover { background: #283593; }
</style>

<div class="hq-wrap">

  <!-- ページヘッダー -->
  <div class="hq-page-header">
    <div class="hq-title">🏢 本社メニュー</div>
    <div class="hq-sub"><?= date('Y年n月j日') ?></div>
  </div>

  <!-- 売上集計 -->
  <div class="section-label">売上集計</div>
  <div class="nav-buttons">
    <a href="hq_seiseki.php" class="nav-btn primary">
      <span class="nav-icon">🏆</span>
      成績一覧
    </a>
    <a href="hq_nyuryoku.php" class="nav-btn">
      <span class="nav-icon">📥</span>
      投入確認
    </a>
    <a href="hq_jikanbetsu.php" class="nav-btn">
      <span class="nav-icon">🕐</span>
      時間別
    </a>
    <a href="hq_store.php" class="nav-btn">
      <span class="nav-icon">🏪</span>
      店舗別集計
    </a>
    <a href="hq_tanpin.php" class="nav-btn">
      <span class="nav-icon">🔍</span>
      単品管理
    </a>
  </div>

  <!-- マスター管理 -->
  <div class="section-label" style="margin-top:1.4em;">マスター管理</div>
  <div class="nav-buttons">
    <a href="hq_shohin_maint.php" class="nav-btn">
      <span class="nav-icon">📦</span>
      商品メンテ
    </a>
    <a href="hq_tenpo_maint.php" class="nav-btn">
      <span class="nav-icon">🏪</span>
      店舗マスター
    </a>
  </div>

</div>

<?php include __DIR__ . '/footer.php'; ?>
