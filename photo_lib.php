<?php
/**
 * photo_lib.php  商品写真（候補の登録・採用）の共通処理
 *
 * 候補: photo_data/{商品ID}/{候補ID}.jpg と meta.json（Web非公開。photo_img.php 経由でログイン者のみ閲覧）
 * 採用: pub/img/{商品ID}.jpg（公開カタログが参照）。採用の決定は本社のみ。
 * 商品IDは販売商品(hanbai_API)のrecordId（catalog_lib.php と同じ）。
 */
const PHOTO_MAX_SIDE   = 800;      // 長辺の上限(px)
const PHOTO_MAX_BYTES  = 12582912; // 受け付ける原寸の上限 12MB（実際はブラウザ側で縮小して送る）
const PHOTO_MAX_CANDS  = 8;        // 1商品あたりの候補上限

function photo_dir(string $pid): string { return __DIR__ . '/photo_data/' . $pid; }
function photo_pub_path(string $pid): string { return __DIR__ . '/pub/img/' . $pid . '.jpg'; }
function photo_valid_pid(string $pid): bool { return (bool)preg_match('/^[0-9]{1,12}$/', $pid); }
function photo_valid_id(string $id): bool { return (bool)preg_match('/^[0-9a-f]{16}$/', $id); }

/** @return array{adopted:?string,cands:array} */
function photo_meta(string $pid): array {
    $f = photo_dir($pid) . '/meta.json';
    $m = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
    if (!is_array($m)) $m = [];
    return ['adopted' => $m['adopted'] ?? null, 'cands' => array_values($m['cands'] ?? [])];
}

/** meta を排他ロックして更新する。$fn(array $meta): array を渡す */
function photo_update(string $pid, callable $fn): array {
    $d = photo_dir($pid);
    if (!is_dir($d)) mkdir($d, 0755, true);
    $fh = fopen($d . '/meta.lock', 'c');
    flock($fh, LOCK_EX);
    $meta = $fn(photo_meta($pid));
    // 採用が無く候補がちょうど1枚なら、その1枚を自動で採用する
    if (empty($meta['adopted']) && count($meta['cands']) === 1) {
        $meta['adopted'] = $meta['cands'][0]['id'];
    }
    file_put_contents($d . '/meta.json', json_encode($meta, JSON_UNESCAPED_UNICODE));
    photo_sync_public($pid, $meta);
    flock($fh, LOCK_UN); fclose($fh);
    if (function_exists('catalog_invalidate')) catalog_invalidate();
    return $meta;
}

/** 採用画像を公開ディレクトリへ反映（採用なしなら削除） */
function photo_sync_public(string $pid, array $meta): void {
    $dst = photo_pub_path($pid);
    $ad  = $meta['adopted'] ?? null;
    if ($ad && photo_valid_id($ad) && is_file(photo_dir($pid) . "/{$ad}.jpg")) {
        if (!is_dir(dirname($dst))) mkdir(dirname($dst), 0755, true);
        copy(photo_dir($pid) . "/{$ad}.jpg", $dst . '.tmp');
        rename($dst . '.tmp', $dst);
    } elseif (is_file($dst)) {
        unlink($dst);
    }
}

/**
 * アップロード画像を検証・縮小してJPEGで保存し、候補に追加する。
 * @return array{ok:bool,error?:string,id?:string,adopted?:bool}
 */
function photo_add(string $pid, array $file, string $sno, string $sname, string $by): array {
    if (!photo_valid_pid($pid)) return ['ok' => false, 'error' => '商品が不正です'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return ['ok' => false, 'error' => '画像を受け取れませんでした（サイズが大きすぎる可能性があります）'];
    if (($file['size'] ?? 0) > PHOTO_MAX_BYTES) return ['ok' => false, 'error' => '画像が大きすぎます'];
    $info = @getimagesize($file['tmp_name']);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        return ['ok' => false, 'error' => 'JPEG・PNG・WebPの画像のみ登録できます'];
    }
    if (count(photo_meta($pid)['cands']) >= PHOTO_MAX_CANDS) {
        return ['ok' => false, 'error' => '候補が上限（' . PHOTO_MAX_CANDS . '枚）に達しています。不要な写真を削除してください'];
    }
    $src = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($file['tmp_name']),
        IMAGETYPE_PNG  => @imagecreatefrompng($file['tmp_name']),
        default        => @imagecreatefromwebp($file['tmp_name']),
    };
    if (!$src) return ['ok' => false, 'error' => '画像を読み込めませんでした'];
    // 向き補正（JPEGのEXIF）
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $ex = @exif_read_data($file['tmp_name']);
        $o  = (int)($ex['Orientation'] ?? 1);
        $ang = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($ang) { $rot = imagerotate($src, $ang, 0); if ($rot) { $src = $rot; } }
    }
    $w = imagesx($src); $h = imagesy($src);
    $scale = min(1, PHOTO_MAX_SIDE / max($w, $h));
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // PNG透過は白背景
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);

    $id = bin2hex(random_bytes(8));
    $d  = photo_dir($pid);
    if (!is_dir($d)) mkdir($d, 0755, true);
    $ok = imagejpeg($dst, "{$d}/{$id}.jpg", 82);
    if (!$ok) return ['ok' => false, 'error' => '画像を保存できませんでした'];

    $meta = photo_update($pid, function (array $m) use ($id, $sno, $sname, $by) {
        $m['cands'][] = ['id' => $id, 'sno' => $sno, 'sname' => $sname, 'by' => $by, 'at' => date('Y-m-d H:i')];
        return $m;
    });
    return ['ok' => true, 'id' => $id, 'adopted' => ($meta['adopted'] === $id)];
}

function photo_adopt(string $pid, string $id): bool {
    if (!photo_valid_pid($pid) || !photo_valid_id($id)) return false;
    $found = false;
    photo_update($pid, function (array $m) use ($id, &$found) {
        foreach ($m['cands'] as $c) if ($c['id'] === $id) $found = true;
        if ($found) $m['adopted'] = $id;
        return $m;
    });
    return $found;
}

function photo_unadopt(string $pid): void {
    if (!photo_valid_pid($pid)) return;
    photo_update($pid, function (array $m) { $m['adopted'] = null; return $m; });
}

/** 候補を削除。$only_sno を渡すと、その店舗が登録した未採用の候補だけ削除できる（店舗用） */
function photo_delete(string $pid, string $id, ?string $only_sno = null): bool {
    if (!photo_valid_pid($pid) || !photo_valid_id($id)) return false;
    $deleted = false;
    photo_update($pid, function (array $m) use ($pid, $id, $only_sno, &$deleted) {
        $keep = [];
        foreach ($m['cands'] as $c) {
            if ($c['id'] === $id) {
                $mine = ($only_sno === null) || ($c['sno'] === $only_sno && ($m['adopted'] ?? null) !== $id);
                if ($mine) { @unlink(photo_dir($pid) . "/{$id}.jpg"); $deleted = true; continue; }
            }
            $keep[] = $c;
        }
        $m['cands'] = $keep;
        if ($deleted && ($m['adopted'] ?? null) === $id) $m['adopted'] = null;
        return $m;
    });
    return $deleted;
}

function photo_csrf_token(): string {
    if (empty($_SESSION['photo_csrf'])) $_SESSION['photo_csrf'] = bin2hex(random_bytes(16));
    return $_SESSION['photo_csrf'];
}
function photo_csrf_ok(?string $t): bool {
    return is_string($t) && !empty($_SESSION['photo_csrf']) && hash_equals($_SESSION['photo_csrf'], $t);
}
