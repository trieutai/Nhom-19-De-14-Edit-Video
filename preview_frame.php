<?php
// Tạo thumbnail theo mốc tua, chỉ đọc video đã đăng ký trong MySQL.
require_once __DIR__ . '/db.php';
$id = filter_input(INPUT_GET, 'video_id', FILTER_VALIDATE_INT);
$time = filter_input(INPUT_GET, 't', FILTER_VALIDATE_FLOAT);
if (!$id || $time === false || $time === null || $time < 0) { http_response_code(400); exit; }
$stmt = $pdo->prepare('SELECT filepath, duration FROM videos WHERE id = ?');
$stmt->execute([$id]);
$video = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$video) { http_response_code(404); exit; }
$input = realpath(__DIR__ . '/' . $video['filepath']);
$uploads = realpath(__DIR__ . '/uploads');
if (!$input || !$uploads || strpos($input, $uploads . DIRECTORY_SEPARATOR) !== 0 || !is_file($input)) { http_response_code(404); exit; }
$time = min((float)$video['duration'], floor((float)$time / 2) * 2);
$cacheDir = __DIR__ . '/outputs/preview_frames';
if (!is_dir($cacheDir)) mkdir($cacheDir, 0775, true);
$file = $cacheDir . '/v' . $id . '_' . (int)$time . '.jpg';
if (!is_file($file)) {
    $tmp = $file . '.tmp.jpg';
    $cmd = 'ffmpeg -nostdin -hide_banner -loglevel error -y -ss ' . escapeshellarg((string)$time) . ' -i ' . escapeshellarg($input) . ' -frames:v 1 -vf ' . escapeshellarg('scale=320:-2') . ' ' . escapeshellarg($tmp) . ' 2>&1';
    exec($cmd, $logs, $status);
    if ($status !== 0 || !is_file($tmp)) { @unlink($tmp); http_response_code(500); exit; }
    @rename($tmp, $file);
}
header('Content-Type: image/jpeg');
header('Cache-Control: private, max-age=86400');
readfile($file);
