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
require_once __DIR__ . '/auth_lib.php';

$error_message = '';
$account  = trim($_POST['account']  ?? '');
$password = trim($_POST['password'] ?? '');

$MSG_BAD    = 'アカウント名またはパスワードが間違っています。';
$MSG_LOCKED = 'ログインの失敗が続いたため、しばらくしてからやり直してください。';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $account !== '' && $password !== '') {

    $ip = auth_client_ip();

    if (auth_is_locked($account, $ip)) {
        $error_message = $MSG_LOCKED;

    } elseif (!auth_valid_account($account)) {
        auth_dummy_verify($password);
        auth_record_failure($account, $ip);
        $error_message = $MSG_BAD;

    // ---- 本社アカウント（fm_config_secret.php の $hq_accounts。ハッシュで照合）----
    } elseif (isset($hq_accounts) && is_array($hq_accounts) && isset($hq_accounts[$account])) {
        $hq = $hq_accounts[$account];
        if (password_verify($password, (string)($hq['hash'] ?? ''))) {
            auth_clear_failures($account, $ip);
            session_regenerate_id(true);
            $_SESSION['user']       = $account;
            $_SESSION['store_id']   = '000';
            $_SESSION['store_name'] = (string)($hq['name'] ?? '本社');
            $_SESSION['role']       = 'hq';
            header('Location: hq_top.php');
            exit();
        }
        auth_record_failure($account, $ip);
        $error_message = $MSG_BAD;

    // ---- 店舗アカウント（account_API で照合）----
    } else {
        $fm = new fmRESTor($host, $db, $layout_account,
                           $api_master_user, $api_master_pass, ['allowInsecure' => true]);
        // 全店のアカウントを読み込まず、該当アカウントだけを完全一致で検索する
        $result  = $fm->findRecords(['query' => [['account' => '==' . $account]], 'limit' => 2]);
        $records = $result['result']['response']['data'] ?? [];

        $rec     = (count($records) === 1) ? $records[0] : null;   // 0件・重複は認証失敗
        $matched = $rec['fieldData'] ?? null;
        $ok      = false;
        $migrate = false;

        if ($matched !== null) {
            $plain = (string)($matched['password']      ?? '');
            $hash  = (string)($matched['password_hash'] ?? '');
            if ($plain !== '') {
                // 平文が入っている＝未移行、または本社がFileMakerで新しいパスワードを入力した状態
                $ok      = hash_equals($plain, $password);
                $migrate = $ok;
            } elseif ($hash !== '') {
                $ok = password_verify($password, $hash);
                if ($ok && password_needs_rehash($hash, PASSWORD_DEFAULT)) $migrate = true;
            } else {
                auth_dummy_verify($password);   // パスワード未設定のアカウントはログイン不可
            }
        } else {
            auth_dummy_verify($password);
        }

        if (!$ok) {
            auth_record_failure($account, $ip);
            $error_message = $MSG_BAD;
        } elseif (trim((string)($matched['営業状態'] ?? '')) === '閉店') {
            $error_message = 'この店舗は閉店済みのためログインできません。';
        } else {
            // 平文 → ハッシュへ自動移行（account_API に password_hash フィールドがある場合のみ。
            // 書き込みが失敗した場合は何も変わらず、平文のまま残る）
            if ($migrate) {
                $fm->editRecord($rec['recordId'], ['fieldData' => [
                    'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                    'password'      => '',
                ]]);
            }
            auth_clear_failures($account, $ip);
            $eigyo_status = trim((string)($matched['営業状態'] ?? ''));   // '営業中' or '閉店'
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

/* 入力リセットボタン（ログインボタンより控えめに） */
.login-reset {
    display: block;
    width: 100%;
    padding: 0.6em;
    margin-top: 0.8rem;
    background: #fff;
    color: #555;
    border: 1px solid #bbb;
    border-radius: 0.6rem;
    font-size: 0.95rem;
    cursor: pointer;
}
.login-reset:active { background: #f0f0f0; }
.login-help { margin-top: 1.2rem; font-size: 0.85rem; color: #555; }
.login-help summary { cursor: pointer; color: #004d40; }
.login-help p { margin: 0.6rem 0 0; line-height: 1.6; }
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

            <!-- 他店舗の番号やパスワードを誤って入れたとき用：入力欄を空にしてやり直す -->
            <button type="button" id="login-reset" class="login-reset">入力をリセット（やり直す）</button>

        </form>

        <details class="login-help">
            <summary>他店舗のパスワードが自動で入ってしまうとき</summary>
            <p>
                誤って入れたパスワードを、タブレットの「Google パスワード マネージャー」が
                保存していることがあります。
                上の「入力をリセット」で入力欄は空になりますが、保存された分は残ります。
            </p>
            <p>
                <strong>保存分の消し方（Android）</strong><br>
                1. タブレットの「設定」を開く<br>
                2. 「Google」→「すべてのサービス」→「自動入力」→「Google パスワード マネージャー」を開く<br>
                3. 一覧から、このサイト（kei1.me）の間違った保存分をタップする<br>
                4. 「削除」をタップし、画面ロックなどで本人確認をする
            </p>
            <p>
                ※メニューの名前は、機種やAndroidのバージョンで少し違います。
                見つからないときや、操作がわからないときは、本社までご連絡ください。
            </p>
        </details>

        <script>
        (function () {
            var btn = document.getElementById('login-reset');
            if (!btn) return;
            btn.addEventListener('click', function () {
                var a = document.getElementById('account');
                var p = document.getElementById('password');
                a.value = '';
                p.value = '';
                a.focus();
            });
        })();
        </script>
    </div>
</div>

<?php include __DIR__ . '/footer.php'; ?>
