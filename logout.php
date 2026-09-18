<?php
/**
 * File: logout.php
 * セッションを破棄してログアウト処理を行う
 */
require_once __DIR__ . '/session_config.php';
session_start();

// 1. セッション変数をすべて空にする
$_SESSION = array();

// 2. ブラウザのクッキーに保存されているセッションIDも削除する
if (isset($_COOKIE[session_name()])) {
    setcookie(session_name(), '', time() - 42000, '/');
}

// 3. 最終的にセッションそのものを破棄する
session_destroy();

// 4. ログイン画面へリダイレクト
header('Location: login.php');
exit();