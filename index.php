<?php
/**
 * File: index.php
 * ログイン状態をチェックし、適切なページへ振り分ける
 */
require_once __DIR__ . '/session_config.php';
session_start();

// すでにログイン済みの場合は top.php へ
if (isset($_SESSION['user'])) {
    header('Location: top.php');
    exit();
} else {
    // ログインしていない場合は login.php へ強制移動
    header('Location: login.php');
    exit();
}