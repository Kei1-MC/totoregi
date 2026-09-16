<?php
/**
 * session_config.php
 * レジ運用のため、セッション有効期限を120分に延長する。
 * session_start() を呼ぶ全ページの先頭で session_start() より前に require すること。
 */
if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.gc_maxlifetime', 7200);
    session_set_cookie_params(['lifetime' => 7200, 'path' => '/']);
}
