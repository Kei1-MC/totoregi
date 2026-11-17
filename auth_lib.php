<?php
/**
 * auth_lib.php — ログイン補助（試行回数の制限・パスワード検証）
 *
 * - ログイン失敗の回数を、アカウント+接続元IP と 接続元IP の2系統で数え、一定回数で一時ロックする
 * - 記録は一時ディレクトリ（sys_get_temp_dir）に置く。消えた場合は制限がリセットされるだけ
 * - REMOTE_ADDR のみを使う（X-Forwarded-For は偽装できるため信用しない）
 */

const AUTH_WINDOW_SEC      = 900;   // 失敗を数える期間（15分）
const AUTH_LIMIT_ACCOUNT   = 5;     // アカウント+IP ごとの上限
const AUTH_LIMIT_IP        = 30;    // IP ごとの上限（アカウントを変えながらの総当たり対策）


function auth_client_ip(): string {
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

function auth_dir(): string {
    $d = sys_get_temp_dir() . '/tomiso_auth';
    if (!is_dir($d)) @mkdir($d, 0700, true);
    return $d;
}

/** 期間内の失敗時刻の一覧を返す（古いものは捨てる） */
function _auth_load(string $key): array {
    $f = auth_dir() . '/' . hash('sha256', $key);
    if (!is_file($f)) return [];
    $arr = json_decode((string)@file_get_contents($f), true);
    if (!is_array($arr)) return [];
    $limit = time() - AUTH_WINDOW_SEC;
    return array_values(array_filter($arr, fn($t) => is_int($t) && $t >= $limit));
}

function _auth_save(string $key, array $times): void {
    $f = auth_dir() . '/' . hash('sha256', $key);
    if (!$times) { @unlink($f); return; }
    @file_put_contents($f, json_encode($times), LOCK_EX);
}

function auth_is_locked(string $account, string $ip): bool {
    return count(_auth_load('a|' . $account . '|' . $ip)) >= AUTH_LIMIT_ACCOUNT
        || count(_auth_load('ip|' . $ip)) >= AUTH_LIMIT_IP;
}

function auth_record_failure(string $account, string $ip): void {
    foreach (['a|' . $account . '|' . $ip, 'ip|' . $ip] as $key) {
        $t = _auth_load($key);
        $t[] = time();
        _auth_save($key, $t);
    }
}

/** ログイン成功時：そのアカウント+IP の失敗回数だけ消す（IP全体の回数は残す） */
function auth_clear_failures(string $account, string $ip): void {
    _auth_save('a|' . $account . '|' . $ip, []);
}

/** アカウント名として許す文字（FileMakerの検索演算子を含めない） */
function auth_valid_account(string $account): bool {
    return (bool)preg_match('/^[A-Za-z0-9_\-]{1,32}$/', $account);
}

/** 存在しないアカウントでも、存在するアカウントと処理時間を揃えるためのダミー照合 */
function auth_dummy_verify(string $password): void {
    static $hash = null;
    if ($hash === null) $hash = password_hash('dummy-password', PASSWORD_DEFAULT);
    password_verify($password, $hash);
}
