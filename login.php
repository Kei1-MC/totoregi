<?php
/**
 * File: login.php
 * ととレジ ログイン処理（スマホ最適化版）
 */
use fmRESTor\fmRESTor;

require_once __DIR__ . '/session_config.php';
session_start();

if (isset($_SESSION['store_id'])) {
    $dest = (($_SESSION['role'] ?? '') === 'hq') ? 'hq_top.php' : 'top.php';
    header('Location: ' . $dest);
    exit();
}

require_once __DIR__ . '/src/fmRESTor.php';
require_once __DIR__ . '/fm_setting.php';

$error_message = '';
$account  = trim($_POST['account']  ?? '');
$password = trim($_POST['password'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account !== '' && $password !== '') {

    // ---- 本社アカウント（固定）----
    if ($account === '000' && $password === '000') {
        session_regenerate_id(true);
        $_SESSION['user']       = '000';
        $_SESSION['store_id']   = '000';
        $_SESSION['store_name'] = '本社';
        $_SESSION['role']       = 'hq';
        header('Location: hq_top.php');
        exit();
    }

    // ---- 店舗アカウント（account_API で照合）----
    $fm = new fmRESTor($host, $db, $layout_account,
                       $api_master_user, $api_master_pass, ['allowInsecure' => true]);
    $result  = $fm->getRecords(['_limit' => 300]);
    $records = $result['result']['response']['data'] ?? [];

    $matched = null;
    foreach ($records as $rec) {
        if ((string)($rec['fieldData']['account'] ?? '') === $account) {
            $matched = $rec['fieldData'];
            break;
        }
    }

    if ($matched !== null) {
        $fm_password   = (string)($matched['password']   ?? '');
        $eigyo_status  = trim((string)($matched['営業状態'] ?? ''));   // '営業中' or '閉店'

        if ($password !== $fm_password) {
            $error_message = 'アカウント名またはパスワードが間違っています。';
        } elseif ($eigyo_status === '閉店') {
            $error_message = 'この店舗は閉店済みのためログインできません。';
        } else {
            session_regenerate_id(true);
            $_SESSION['user']          = $account;
            $_SESSION['store_id']      = (string)($matched['店舗Ｎｏ'] ?? '');
            $_SESSION['store_name']    = $matched['店舗名'] ?? '';
            $_SESSION['role']          = 'store';
            $_SESSION['eigyo_status']  = $eigyo_status;   // '営業中' など
            $_SESSION['heiten_bi']     = trim((string)($matched['閉店日'] ?? ''));
            header('Location: top.php');
            exit();
        }
    } else {
        $error_message = 'アカウント名またはパスワードが間違っています。';
    }
}

include __DIR__ . '/header.php';
?>

<style>
/* ログイン画面専用：上余白を最小化してフォームを上げる */
.login-outer {
    min-height: calc(100vh - 70px);
    display: flex;
    align-items: flex-start;      /* 上寄せ */
    justify-content: center;
    padding: 1.5rem 1rem 2rem;    /* 上余白を削減 */
    background: #f0f4f0;
}
.login-card {
    width: 100%;
    max-width: 420px;
    background: #fff;
    border-radius: 1rem;
    box-shadow: 0 4px 20px rgba(0,0,0,.1);
    padding: 2rem 1.8rem;
}
.login-logo {
    text-align: center;
    margin-bottom: 1.5rem;
}
.login-logo .brand {
    font-size: 1.4rem;
    font-weight: bold;
    color: #004d40;
}
.login-logo .tagline {
    font-size: 0.85rem;
    color: #888;
    margin-top: 0.3rem;
}

/* フォームラベル */
.login-label {
    font-size: 0.9rem;
    font-weight: bold;
    color: #444;
    margin-bottom: 0.3rem;
    display: block;
}

/* 数字入力フィールド：大きめでタップしやすく */
.login-input {
    display: block;
    width: 100%;
    font-size: 1.4rem;          /* 大きく見やすく */
    font-weight: bold;
    letter-spacing: 0.15em;     /* 数字が読みやすい間隔 */
    text-align: center;
    padding: 0.6em 0.8em;
    border: 2px solid #ccc;
    border-radius: 0.6rem;
    background: #fafafa;
    color: #004d40;
    transition: border-color .15s;
    -webkit-appearance: none;
}
.login-input:focus {
    outline: none;
    border-color: #004d40;
    background: #fff;
    box-shadow: 0 0 0 3px rgba(0,77,64,.1);
}

/* ログインボタン */
.login-btn {
    display: block;
    width: 100%;
    padding: 0.8em;
    margin-top: 1.5rem;
    background: #004d40;
    color: #fff;
    border: none;
    border-radius: 0.6rem;
    font-size: 1.1rem;
    font-weight: bold;
    cursor: pointer;
    transition: background .15s;
}
.login-btn:hover, .login-btn:active { background: #00695c; }
</style>

<div class="login-outer">
    <div class="login-card">

        <div class="login-logo">
            <div class="brand">🐟 ととレジ</div>
            <div class="tagline">おつかれさまです</div>
        </div>

        <?php if ($error_message !== '') : ?>
            <div class="alert alert-danger py-2 text-center" style="font-size:.9rem;">
                <?= htmlspecialchars($error_message) ?>
            </div>
        <?php endif; ?>

        <form method="post" action="login.php" autocomplete="on">

            <div class="mb-3">
                <label for="account" class="login-label">アカウント番号</label>
                <input
                    type="text"
                    id="account"
                    name="account"
                    class="login-input"
                    value="<?= htmlspecialchars($account) ?>"
                    inputmode="numeric"      /* スマホで数字キーボード */
                    pattern="[0-9]*"
                    autocomplete="username"
                    autofocus
                    required
                    placeholder="000"
                >
            </div>

            <div class="mb-2">
                <label for="password" class="login-label">パスワード</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="login-input"
                    inputmode="numeric"      /* スマホで数字キーボード */
                    pattern="[0-9]*"
                    autocomplete="current-password"
                    required
                    placeholder="••••"
                >
            </div>

            <button type="submit" class="login-btn">ログイン</button>

        </form>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
