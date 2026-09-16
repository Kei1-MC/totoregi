<?php
/**
 * File: hq_header.php
 * ととレジ 本社用共通ヘッダー
 */
require_once __DIR__ . '/session_config.php';
if (session_status() === PHP_SESSION_NONE) session_start();
if (!isset($_SESSION['role']) || $_SESSION['role'] !== 'hq') {
    header('Location: login.php'); exit();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ととレジ 本社</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    <!-- ととレジ スタイル -->
    <link rel="stylesheet" href="css/variables.css">
    <link rel="stylesheet" href="css/base.css">
    <link rel="stylesheet" href="css/components.css">
</head>
<body>

<nav class="navbar navbar-dark shadow-sm" style="background-color: #1a237e; min-height:48px;">
    <div class="container-fluid px-2">

        <!-- ブランド名 -->
        <a class="navbar-brand fw-bold" href="hq_top.php" style="font-size:1.1rem;">
            🏢 ととレジ 本社
        </a>

        <!-- ハンバーガーボタン -->
        <button class="navbar-toggler border-0" type="button"
                data-bs-toggle="collapse" data-bs-target="#navbarHq"
                style="padding:4px 8px;">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- メニュー -->
        <div class="collapse navbar-collapse" id="navbarHq">
            <ul class="navbar-nav me-auto mt-1 mb-1">
                <li class="nav-item">
                    <a class="nav-link py-1" href="hq_top.php">🏠 ホーム</a>
                </li>
                <li class="nav-item">
                    <span class="nav-link py-1 text-white-50" style="font-size:.8em; cursor:default;">── 売上集計 ──</span>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="hq_seiseki.php">🏆 成績一覧</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="hq_nyuryoku.php">📥 投入確認</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="hq_jikanbetsu.php">🕐 時間別</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="hq_store.php">🏪 店舗別集計</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="hq_tanpin.php">🔍 単品管理</a>
                </li>
                <li class="nav-item border-top border-secondary mt-1 pt-1">
                    <span class="nav-link py-1 text-white-50" style="font-size:.8em; cursor:default;">── マスター管理 ──</span>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="hq_shohin_maint.php">📦 商品メンテ</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="hq_tenpo_maint.php">🏪 店舗マスター</a>
                </li>
                <li class="nav-item border-top border-secondary mt-1 pt-1">
                    <a class="nav-link py-1 text-warning" href="logout.php">🚪 ログアウト</a>
                </li>
            </ul>
        </div>

    </div>
</nav>

<div class="tomiso-container">
