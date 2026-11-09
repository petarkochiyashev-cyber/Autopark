<?php
declare(strict_types=1);
require __DIR__ . '/lib/app.php';

/* Serves a business-card photo to signed-in users only. */

if (!current_user()) {
    http_response_code(401);
    exit;
}
$f = (string)($_GET['f'] ?? '');
if (!preg_match('/^[a-f0-9]{32}\.(jpg|png|webp)$/', $f, $m) || !is_file(UPLOAD_DIR . '/' . $f)) {
    http_response_code(404);
    exit;
}
$path = UPLOAD_DIR . '/' . $f;
$etag = '"' . md5($f . filemtime($path)) . '"';
header('Content-Type: ' . ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$m[1]]);
header('Cache-Control: private, max-age=31536000, immutable');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (($_SERVER['HTTP_IF_NONE_MATCH'] ?? '') === $etag) {
    http_response_code(304);
    exit;
}
header('Content-Length: ' . filesize($path));
readfile($path);
