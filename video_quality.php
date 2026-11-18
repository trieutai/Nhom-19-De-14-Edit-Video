<?php
// Chuyển mã bản xem trước theo yêu cầu, lưu cache ở outputs/quality.
// Không ghi đè video gốc và không thay đổi preset xuất file.
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');
function failQuality($message, $code = 400) { http_response_code($code); echo json_encode(['success'=>false,'error'=>$message], JSON_UNESCAPED_UNICODE); exit; }
if ($_SERVER['REQUEST_METHOD'] !== 'POST') failQuality('Yêu cầu không hợp lệ', 405);
$id = filter_input(INPUT_POST, 'video_id', FILTER_VALIDATE_INT);
$height = filter_input(INPUT_POST, 'height', FILTER_VALIDATE_INT);
if (!$id || !in_array($height, [480, 720, 1080], true)) failQuality('Video hoặc độ phân giải không hợp lệ');
$stmt = $pdo->prepare('SELECT filepath FROM videos WHERE id = ?');
$stmt->execute([$id]);
$video = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$video) failQuality('Không tìm thấy video', 404);
$uploads = realpath(__DIR__ . '/uploads');
$input = realpath(__DIR__ . '/' . $video['filepath']);
if (!$input || !$uploads || strpos($input, $uploads . DIRECTORY_SEPARATOR) !== 0 || !is_file($input)) failQuality('Tệp nguồn không tồn tại', 404);
$cacheDir = __DIR__ . '/outputs/quality';
if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true)) failQuality('Không tạo được thư mục cache', 500);
$filename = 'v' . $id . '_' . $height . 'p_' . filemtime($input) . '.mp4';
$output = $cacheDir . '/' . $filename;
if (!is_file($output) || filesize($output) === 0) {
    set_time_limit(0);
    $lockPath = $output . '.lock';
    $lock = fopen($lockPath, 'c');
    if (!$lock || !flock($lock, LOCK_EX)) failQuality('Không khóa được tiến trình xử lý', 500);
    if (!is_file($output) || filesize($output) === 0) {
        $temp = $output . '.tmp.mp4';
        // -2 giữ chiều rộng chẵn, tránh kéo méo hình; tăng độ phân giải không thêm chi tiết thật.
        $filter = 'scale=-2:' . $height . ':flags=lanczos,setsar=1';
        $cmd = 'ffmpeg -nostdin -hide_banner -loglevel error -y -i ' . escapeshellarg($input)
            . ' -vf ' . escapeshellarg($filter)
            . ' -c:v libx264 -preset veryfast -crf 23 -pix_fmt yuv420p -c:a aac -b:a 128k -movflags +faststart '
            . escapeshellarg($temp) . ' 2>&1';
        exec($cmd, $logs, $status);
        if ($status !== 0 || !is_file($temp) || filesize($temp) === 0) {
            @unlink($temp); flock($lock, LOCK_UN); fclose($lock);
            failQuality('FFmpeg không tạo được video. Kiểm tra đường dẫn FFmpeg và định dạng nguồn.', 500);
        }
        if (!rename($temp, $output)) { @unlink($temp); flock($lock, LOCK_UN); fclose($lock); failQuality('Không lưu được bản video.', 500); }
    }
    flock($lock, LOCK_UN); fclose($lock);
}
// ffprobe xác nhận kích thước thực tế (trường hợp đầu vào xoay khung hình).
$width = null;
$probe = 'ffprobe -v error -select_streams v:0 -show_entries stream=width -of default=noprint_wrappers=1:nokey=1 ' . escapeshellarg($output) . ' 2>&1';
$widthResult = trim((string)shell_exec($probe));
if (ctype_digit($widthResult)) $width = (int)$widthResult;
echo json_encode(['success'=>true,'url'=>'outputs/quality/' . rawurlencode($filename),'width'=>$width,'height'=>$height], JSON_UNESCAPED_UNICODE);
