<?php
/**
 * File: header.php
 * ととレジ 共通ヘッダー
 */
$accountName = $_SESSION['store_name'] ?? $_SESSION['user'] ?? "未ログイン";
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>ととレジ</title>

    <!-- Bootstrap -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.0/font/bootstrap-icons.css">

    <!-- ととレジ スタイル（読み込み順序が重要）-->
    <link rel="stylesheet" href="css/variables.css">   <!-- 1. デザイントークン -->
    <link rel="stylesheet" href="css/base.css">        <!-- 2. 共通基盤 -->
    <link rel="stylesheet" href="css/components.css">  <!-- 3. UIコンポーネント -->
    <!-- 画面固有CSSは各PHPファイルで <link rel="stylesheet" href="css/xxx.css"> を追加 -->
</head>
<body>

<nav class="navbar navbar-dark shadow-sm" style="background-color: #004d40; min-height:48px;">
    <div class="container-fluid px-2">

        <!-- ブランド名（スマホでも表示） -->
        <a class="navbar-brand fw-bold" href="top.php" style="font-size:1.1rem;">
            🐟 ととレジ
        </a>

        <!-- スマホ：店舗名を中央に小さく表示 -->
        <span class="text-white mx-auto d-lg-none" style="font-size:0.85rem; opacity:.85;">
            <?= htmlspecialchars($accountName) ?>
        </span>

        <!-- ハンバーガーボタン -->
        <button class="navbar-toggler border-0" type="button"
                data-bs-toggle="collapse" data-bs-target="#navbarNav"
                style="padding:4px 8px;">
            <span class="navbar-toggler-icon"></span>
        </button>

        <!-- メニュー（ハンバーガー内） -->
        <div class="collapse navbar-collapse" id="navbarNav">
            <ul class="navbar-nav me-auto mt-1 mb-1">
                <li class="nav-item">
                    <a class="nav-link py-1" href="top.php">🏠 ホーム</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="sales_entry.php">➕ 売上登録</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="sales_list.php">📋 売上一覧</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="daily_report_entry.php">📊 売上日報入力</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="order_entry.php">📦 発注入力</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="daily_report_mystore.php">📈 自店舗成績</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="shohin_maint.php">📦 商品メンテ</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link py-1" href="photo_manage.php">📷 商品写真登録</a>
                </li>
                <li class="nav-item border-top border-secondary mt-1 pt-1">
                    <a class="nav-link py-1 text-warning" href="logout.php">🚪 ログアウト</a>
                </li>
            </ul>
        </div>

    </div>
</nav>

<div class="tomiso-container">
