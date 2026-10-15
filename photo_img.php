<?php
/** photo_img.php  候補写真の表示（ログイン者のみ）。?pid=商品ID&id=候補ID */
require_once __DIR__ . '/session_config.php';
session_start();
if (!isset($_SESSION['role'])) { http_response_code(403); exit; }
require_once __DIR__ . '/photo_lib.php';
$pid = (string)($_GET['pid'] ?? ''); $id = (string)($_GET['id'] ?? '');
if (!photo_valid_pid($pid) || !photo_valid_id($id)) { http_response_code(404); exit; }
$f = photo_dir($pid) . "/{$id}.jpg";
if (!is_file($f)) { http_response_code(404); exit; }
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=3600');
header('X-Content-Type-Options: nosniff');
readfile($f);
