<?php
/**
 * session_config.php
 * レジ運用のため、セッション有効期限を60分に延長する。
 * session_start() を呼ぶ全ページの先頭で session_start() より前に require すること。
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.gc_maxlifetime', 3600);
    ini_set('session.use_strict_mode', 1);   // サーバーが発行していないセッションIDは受け付けない
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_set_cookie_params([
        'lifetime' => 3600,
        'path'     => '/',
        'secure'   => $https,    // HTTPS のときはCookieをHTTPSでのみ送る
        'httponly' => true,      // JavaScript からCookieを読めないようにする
        'samesite' => 'Lax',     // 他サイトからの送信（CSRF）を抑える
    ]);
}
