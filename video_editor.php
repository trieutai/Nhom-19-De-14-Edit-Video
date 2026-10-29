<?php
// Bật hiển thị lỗi chi tiết để dễ dàng kiểm tra
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// Bắt đầu session và kiểm tra đăng nhập
session_start();
if (!isset($_SESSION['username'])) {
    header("Location: login.php");
    exit();
}
$current_user = $_SESSION['username'];

// ==========================================
// 1. KẾT NỐI CSDL & TỰ ĐỘNG TẠO/CẬP NHẬT BẢNG
// ==========================================
$host = 'localhost';
$dbname = 'VideoToolDB';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `$dbname`");

    // Tạo các bảng (có cột username để phân tách tài khoản)
    $pdo->exec("CREATE TABLE IF NOT EXISTS videos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        filename VARCHAR(255) NOT NULL,
        filepath VARCHAR(255) NOT NULL,
        duration FLOAT DEFAULT 0,
        width INT DEFAULT 0,
        height INT DEFAULT 0,
        has_audio TINYINT(1) DEFAULT 1,
        thumbnail_path VARCHAR(255),
        file_size BIGINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS presets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        width INT NOT NULL,
        height INT NOT NULL,
        keep_audio TINYINT(1) DEFAULT 1
    )");

    if ($pdo->query("SELECT COUNT(*) FROM presets")->fetchColumn() == 0) {
        $pdo->exec("INSERT INTO presets (name, width, height, keep_audio) VALUES 
        ('HD 720p (1280x720)', 1280, 720, 1),
        ('Full HD 1080p (1920x1080)', 1920, 1080, 1),
        ('TikTok / Reels Dọc (1080x1920)', 1080, 1920, 1)");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS jobs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) NOT NULL,
        video_id INT NOT NULL,
        preset_id INT NOT NULL,
        start_time FLOAT DEFAULT 0,
        end_time FLOAT DEFAULT 10,
        status ENUM('pending', 'processing', 'completed', 'error', 'cancelled') DEFAULT 'pending',
        error_message TEXT,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS job_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        attempt_number INT NOT NULL,
        status ENUM('success', 'failed') NOT NULL,
        log_output TEXT,
        executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE IF NOT EXISTS outputs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        job_id INT NOT NULL,
        attempt_id INT,
        output_path VARCHAR(255) NOT NULL,
        thumbnail_path VARCHAR(255),
        file_size BIGINT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // Tự động bổ sung các cột còn thiếu nếu bảng cũ đã tồn tại
    try { $pdo->exec("ALTER TABLE outputs ADD COLUMN thumbnail_path VARCHAR(255)"); } catch (PDOException $ex) {}
    try { $pdo->exec("ALTER TABLE videos ADD COLUMN has_audio TINYINT(1) DEFAULT 1"); } catch (PDOException $ex) {}
    try { $pdo->exec("ALTER TABLE videos ADD COLUMN thumbnail_path VARCHAR(255)"); } catch (PDOException $ex) {}
    try { $pdo->exec("ALTER TABLE videos ADD COLUMN username VARCHAR(50) NOT NULL"); } catch (PDOException $ex) {}
    try { $pdo->exec("ALTER TABLE jobs ADD COLUMN username VARCHAR(50) NOT NULL"); } catch (PDOException $ex) {}

} catch (PDOException $e) {
    die("<div style='color:red; padding:20px; font-family:sans-serif;'><h3>Lỗi kết nối Cơ sở dữ liệu:</h3><p>" . $e->getMessage() . "</p></div>");
}

// ==========================================
// 2. LỚP XỬ LÝ FFmpeg & FFPROBE
// ==========================================
class VideoProcessor {
    private $ffmpegPath;
    private $ffprobePath;

    public function __construct($ffmpeg = 'ffmpeg', $ffprobe = 'ffprobe') {
        $this->ffmpegPath = $ffmpeg;
        $this->ffprobePath = $ffprobe;
    }

    public function getMetadata($filePath) {
        if (!file_exists($filePath)) return null;
        $cmd = sprintf('%s -v quiet -print_format json -show_format -show_streams %s', 
            escapeshellcmd($this->ffprobePath), 
            escapeshellarg($filePath)
        );
        $output = shell_exec($cmd);
        $data = json_decode($output, true);
        if (!$data || !isset($data['format'])) return null;

        $duration = isset($data['format']['duration']) ? floatval($data['format']['duration']) : 0;
        $width = 0; $height = 0; $hasAudio = false;

        foreach ($data['streams'] as $stream) {
            if ($stream['codec_type'] === 'video') {
                $width = isset($stream['width']) ? intval($stream['width']) : 0;
                $height = isset($stream['height']) ? intval($stream['height']) : 0;
            }
            if ($stream['codec_type'] === 'audio') {
                $hasAudio = true;
            }
        }

        if ($width <= 0 || $height <= 0 || $duration <= 0) return null;

        return [
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
            'has_audio' => $hasAudio,
            'size' => filesize($filePath)
        ];
    }

    public function processVideo($inputPath, $outputPath, $thumbnailPath, $startTime, $endTime, $targetWidth, $targetHeight, $keepAudio = true) {
        if (!file_exists($inputPath)) return ['success' => false, 'error' => 'Tệp nguồn không tồn tại.'];
        $duration = $endTime - $startTime;
        if ($duration <= 0) return ['success' => false, 'error' => 'Thời gian cắt không hợp lệ.'];

        $videoFilter = sprintf("scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2", 
            $targetWidth, $targetHeight, $targetWidth, $targetHeight
        );
        $audioOption = $keepAudio ? "-c:a aac" : "-an";

        $cmd = sprintf('%s -y -ss %f -i %s -t %f -vf "%s" -c:v libx264 %s %s 2>&1',
            escapeshellcmd($this->ffmpegPath),
            $startTime,
            escapeshellarg($inputPath),
            $duration,
            $videoFilter,
            $audioOption,
            escapeshellarg($outputPath)
        );

        $outputLog = [];
        $returnCode = 0;
        exec($cmd, $outputLog, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            $thumbCmd = sprintf('%s -y -ss %f -i %s -vframes 1 %s 2>&1',
                escapeshellcmd($this->ffmpegPath),
                max(0, $duration / 2),
                escapeshellarg($outputPath),
                escapeshellarg($thumbnailPath)
            );
            exec($thumbCmd);
            return ['success' => true, 'output' => $outputPath, 'thumbnail' => $thumbnailPath, 'log' => implode("\n", $outputLog)];
        } else {
            return ['success' => false, 'error' => implode("\n", $outputLog)];
        }
    }
}

$processor = new VideoProcessor();
$message = '';
$presets = $pdo->query("SELECT * FROM presets")->fetchAll(PDO::FETCH_ASSOC);

// Lấy danh sách video CHỈ CỦA TÀI KHOẢN HIỆN TẠI
$stmtVideos = $pdo->prepare("SELECT * FROM videos WHERE username = ? ORDER BY id DESC");
$stmtVideos->execute([$current_user]);
$videos = $stmtVideos->fetchAll(PDO::FETCH_ASSOC);

// ==========================================
// 3. XỬ LÝ POST & GET REQUESTS
// ==========================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action_type'] ?? '';
    $uploadDir = 'uploads/' . $current_user . '/';
    $outputDir = 'outputs/' . $current_user . '/';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0777, true);
    if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

    if ($action === 'batch_upload' && isset($_FILES['video_files'])) {
        $successCount = 0; $errorCount = 0;
        foreach ($_FILES['video_files']['name'] as $i => $filename) {
            if ($_FILES['video_files']['error'][$i] === UPLOAD_ERR_OK) {
                $tmpName = $_FILES['video_files']['tmp_name'][$i];
                $safeName = time() . '_' . preg_replace('/[^a-zA-Z0-9_\.-]/', '_', basename($filename));
                $targetPath = $uploadDir . $safeName;
                $fileExt = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));
                
                if (in_array($fileExt, ['mp4', 'avi', 'mov', 'mkv', 'webm'])) {
                    if (move_uploaded_file($tmpName, $targetPath)) {
                        $metadata = $processor->getMetadata($targetPath);
                        if ($metadata) {
                            $thumbSrcName = $uploadDir . 'thumb_src_' . time() . '_' . rand(100,999) . '.jpg';
                            $thumbCmd = sprintf('ffmpeg -y -ss 1 -i %s -vframes 1 %s 2>&1', escapeshellarg($targetPath), escapeshellarg($thumbSrcName));
                            exec($thumbCmd);
                            $thumbPathToDb = file_exists($thumbSrcName) ? $thumbSrcName : null;

                            $stmt = $pdo->prepare("INSERT INTO videos (username, filename, filepath, duration, width, height, has_audio, thumbnail_path, file_size) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                            $stmt->execute([$current_user, $filename, $targetPath, $metadata['duration'], $metadata['width'], $metadata['height'], $metadata['has_audio'] ? 1 : 0, $thumbPathToDb, $metadata['size']]);
                            $successCount++;
                        } else {
                            @unlink($targetPath);
                            $errorCount++;
                        }
                    }
                } else {
                    $errorCount++;
                }
            }
        }
        $message = "<div class='alert alert-success border-0 bg-success bg-opacity-15 text-success small py-2 mb-3'>Tải lên lô thành công: <strong>$successCount</strong> tệp, lỗi: <strong>$errorCount</strong> tệp.</div>";
        $stmtVideos = $pdo->prepare("SELECT * FROM videos WHERE username = ? ORDER BY id DESC");
        $stmtVideos->execute([$current_user]);
        $videos = $stmtVideos->fetchAll(PDO::FETCH_ASSOC);
    }

    if ($action === 'create_job') {
        $videoId = intval($_POST['video_id']);
        $startTime = floatval($_POST['start_time']);
        $endTime = floatval($_POST['end_time']);
        $presetId = intval($_POST['preset_id']);

        $checkVideo = $pdo->prepare("SELECT id FROM videos WHERE id = ? AND username = ?");
        $checkVideo->execute([$videoId, $current_user]);

        if ($videoId > 0 && $endTime > $startTime && $checkVideo->rowCount() > 0) {
            $stmt = $pdo->prepare("INSERT INTO jobs (username, video_id, preset_id, start_time, end_time, status) VALUES (?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$current_user, $videoId, $presetId, $startTime, $endTime]);
            $message = "<div class='alert alert-success border-0 bg-success bg-opacity-15 text-success small py-2 mb-3'>Đã thêm công việc vào hàng đợi xử lý!</div>";
        } else {
            $message = "<div class='alert alert-danger border-0 bg-danger bg-opacity-15 text-danger small py-2 mb-3'>Khoảng thời gian cắt không hợp lệ hoặc video không tồn tại.</div>";
        }
    }
}

if (isset($_GET['action']) && isset($_GET['job_id'])) {
    $jobId = intval($_GET['job_id']);
    if ($_GET['action'] === 'cancel') {
        $pdo->prepare("UPDATE jobs SET status = 'cancelled' WHERE id = ? AND username = ? AND status = 'pending'")->execute([$jobId, $current_user]);
    }
    if ($_GET['action'] === 'run') {
        $stmt = $pdo->prepare("
            SELECT j.*, v.filepath as video_path, p.width, p.height, p.keep_audio 
            FROM jobs j 
            JOIN videos v ON j.video_id = v.id 
            JOIN presets p ON j.preset_id = p.id 
            WHERE j.id = ? AND j.username = ?
        ");
        $stmt->execute([$jobId, $current_user]);
        $job = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($job) {
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM job_attempts WHERE job_id = ?");
            $cnt->execute([$jobId]);
            $attemptNumber = intval($cnt->fetchColumn()) + 1;

            $pdo->prepare("UPDATE jobs SET status = 'processing' WHERE id = ? AND username = ?")->execute([$jobId, $current_user]);

            $outputDir = 'outputs/' . $current_user . '/';
            if (!is_dir($outputDir)) mkdir($outputDir, 0777, true);

            $outputFilename = 'output_' . time() . '_job' . $jobId . '_att' . $attemptNumber . '.mp4';
            $outputPath = $outputDir . $outputFilename;
            $thumbPath = $outputDir . 'thumb_' . time() . '_job' . $jobId . '.jpg';

            $result = $processor->processVideo(
                $job['video_path'],
                $outputPath,
                $thumbPath,
                $job['start_time'],
                $job['end_time'],
                $job['width'],
                $job['height'],
                $job['keep_audio']
            );

            if ($result['success']) {
                $pdo->prepare("UPDATE jobs SET status = 'completed', error_message = NULL WHERE id = ? AND username = ?")->execute([$jobId, $current_user]);
                
                $logStmt = $pdo->prepare("INSERT INTO job_attempts (job_id, attempt_number, status, log_output) VALUES (?, ?, 'success', ?)");
                $logStmt->execute([$jobId, $attemptNumber, $result['log']]);
                $attemptId = $pdo->lastInsertId();

                $outStmt = $pdo->prepare("INSERT INTO outputs (job_id, attempt_id, output_path, thumbnail_path, file_size) VALUES (?, ?, ?, ?, ?)");
                $outStmt->execute([$jobId, $attemptId, $outputPath, $thumbPath, filesize($outputPath)]);
            } else {
                $pdo->prepare("UPDATE jobs SET status = 'error', error_message = ? WHERE id = ? AND username = ?")->execute([$result['error'], $jobId, $current_user]);
                
                $logStmt = $pdo->prepare("INSERT INTO job_attempts (job_id, attempt_number, status, log_output) VALUES (?, ?, 'failed', ?)");
                $logStmt->execute([$jobId, $attemptNumber, $result['error']]);
            }
        }
    }
    header("Location: video_editor.php");
    exit;
}

// Lấy danh sách hàng đợi CHỈ CỦA TÀI KHOẢN HIỆN TẠI
$stmtJobs = $pdo->prepare("
    SELECT j.*, v.filename, v.filepath as source_filepath, v.duration as orig_duration, v.thumbnail_path as source_thumb, 
           p.name as preset_name, o.output_path, o.thumbnail_path as output_thumb, o.file_size 
    FROM jobs j 
    JOIN videos v ON j.video_id = v.id 
    JOIN presets p ON j.preset_id = p.id 
    LEFT JOIN outputs o ON o.job_id = j.id 
    WHERE j.username = ?
    ORDER BY j.id DESC
");
$stmtJobs->execute([$current_user]);
$jobs = $stmtJobs->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Video Studio - Trình Chỉnh Sửa Video Chuyên Sâu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f8fafc;
            color: #1e293b;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            min-height: 100vh;
        }

        .navbar {
            background-color: #ffffff !important;
            border-bottom: 1px solid #e2e8f0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.05);
        }

        .card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -1px rgba(0, 0, 0, 0.03);
        }

        .form-control, .form-select {
            background-color: #ffffff !important;
            border: 1px solid #cbd5e1 !important;
            color: #1e293b !important;
            border-radius: 6px;
            font-size: 14px;
        }
        .form-control:focus, .form-select:focus {
            border-color: #3b82f6 !important;
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.2) !important;
        }
        .form-text {
            color: #64748b !important;
            font-size: 12px;
        }

        video {
            width: 100%;
            border-radius: 6px;
            background: #000;
            max-height: 240px;
            border: 1px solid #e2e8f0;
        }

        .thumb-img {
            width: 50px;
            height: 35px;
            object-fit: cover;
            border-radius: 4px;
            border: 1px solid #cbd5e1;
            cursor: pointer;
            transition: opacity 0.2s;
        }
        .thumb-img:hover {
            opacity: 0.8;
        }

        .table {
            color: #1e293b !important;
            border-color: #e2e8f0 !important;
            font-size: 13px;
        }
        .table-dark {
            background-color: #1e293b !important;
            color: #fff !important;
        }
        .table > :not(caption) > * > * {
            background-color: transparent;
            color: #1e293b;
            border-bottom-color: #e2e8f0;
        }

        /* Timeline Scrubber Styles */
        .timeline-track {
            position: relative;
            background: #e2e8f0;
            height: 40px;
            border-radius: 6px;
            cursor: pointer;
            overflow: hidden;
        }
        .timeline-selection {
            position: absolute;
            top: 0;
            height: 100%;
            background: rgba(37, 99, 235, 0.25);
            border-left: 2px solid #2563eb;
            border-right: 2px solid #2563eb;
            pointer-events: none;
        }
        .timeline-playhead {
            position: absolute;
            top: 0;
            width: 2px;
            height: 100%;
            background: #dc3545;
            pointer-events: none;
            z-index: 3;
        }

        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
        }
        ::-webkit-scrollbar-track {
            background: #f8fafc;
        }
        ::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 3px;
        }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand fixed-top px-4 py-2 justify-content-between">
        <a class="navbar-brand fw-bold fs-6 text-dark" href="index.php">
            <i class="fa-solid fa-clapperboard me-2 text-primary"></i>DukTay Edit 
            <span class="badge bg-light text-dark border ms-2 font-monospace" style="font-size: 10px;">VIDEO STUDIO</span>
        </a>
        
        <div class="d-flex align-items-center gap-3">
            <a href="index.php" class="btn btn-outline-secondary btn-sm px-3 text-dark border-secondary"><i class="fa-solid fa-house me-1"></i>Trang Chủ</a>
            
            <div class="d-flex align-items-center gap-2 border-start ps-3 border-secondary border-opacity-25">
                <span class="text-dark small fw-semibold">
                    <i class="fa-solid fa-user-circle text-primary me-1"></i><?php echo htmlspecialchars($current_user); ?>
                </span>
                <a href="logout.php" class="btn btn-outline-danger btn-sm px-3 py-1" style="font-size: 12px;"><i class="fa-solid fa-right-from-bracket me-1"></i>Đăng xuất</a>
            </div>
        </div>
    </nav>

    <div class="container my-5" style="max-width: 1400px; padding-top: 40px;">
        <div class="d-flex align-items-center justify-content-between mb-4">
            <h4 class="fw-bold m-0 text-dark"><i class="fa-solid fa-sliders me-2 text-primary"></i>Trình Biên Tập & Xử Lý Video Chuyên Sâu</h4>
        </div>
        
        <?= $message ?>

        <div class="row g-3">
            <div class="col-lg-4">
                <div class="card p-3 mb-3">
                    <h6 class="text-dark fw-semibold mb-3 border-bottom pb-2"><i class="fa-solid fa-upload me-2 text-primary"></i>1. Tải Lên Video & Kiểm Tra</h6>
                    <form action="video_editor.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action_type" value="batch_upload">
                        <div class="mb-3">
                            <label class="form-label small text-muted">Chọn tệp Video (MP4, MKV, AVI...):</label>
                            <input class="form-control form-control-sm" type="file" name="video_files[]" multiple required>
                            <div class="form-text mt-1">Hệ thống tự động dùng ffprobe trích xuất thông số kỹ thuật.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 btn-sm py-2"><i class="fa-solid fa-cloud-arrow-up me-1"></i>Tải Lên & Đọc Thông Số</button>
                    </form>
                </div>

                <div class="card p-3">
                    <h6 class="text-dark fw-semibold mb-3 border-bottom pb-2"><i class="fa-solid fa-video me-2 text-info"></i>2. Xem Trước & Chọn Mốc Cắt</h6>
                    <?php if (empty($videos)): ?>
                        <p class="text-muted text-center py-3 small m-0">Chưa có video nào trong hệ thống.</p>
                    <?php else: ?>
                        <div class="mb-3">
                            <select class="form-select form-select-sm" id="videoSelector">
                                <option value="">-- Chọn video --</option>
                                <?php foreach ($videos as $v): ?>
                                    <option value="<?= htmlspecialchars($v['filepath']) ?>" data-id="<?= $v['id'] ?>" data-dur="<?= $v['duration'] ?>">
                                        <?= htmlspecialchars($v['filename']) ?> (<?= round($v['duration'], 1) ?>s)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <video id="previewPlayer" controls style="display: none;"></video>
                            <div id="playerPlaceholder" class="text-center text-muted bg-light p-3 rounded border small">
                                <i class="fa-solid fa-film fa-lg mb-1 opacity-50"></i>
                                <p class="m-0 text-muted" style="font-size: 12px;">Chọn video để phát và chọn mốc thời gian.</p>
                            </div>
                        </div>

                        <!-- Thanh Timeline trực quan -->
                        <div class="mb-3" id="timelineSection" style="display: none;">
                            <label class="form-label small text-muted d-flex justify-content-between m-0 mb-1">
                                <span>Thanh thời gian (Timeline):</span>
                                <span class="font-monospace text-primary fw-bold" id="timeIndicator">0.0s / 0.0s</span>
                            </label>
                            <div class="timeline-track" id="timelineTrack" title="Bấm để tua video">
                                <div class="timeline-selection" id="timelineSelection"></div>
                                <div class="timeline-playhead" id="timelinePlayhead"></div>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" onclick="setStartTime()"><i class="fa-solid fa-flag text-danger me-1"></i> Lấy Bắt đầu</button>
                            <button type="button" class="btn btn-outline-secondary btn-sm flex-fill" onclick="setEndTime()"><i class="fa-solid fa-flag text-success me-1"></i> Lấy Kết thúc</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="col-lg-8">
                <div class="card p-3 mb-3">
                    <h6 class="text-dark fw-semibold mb-3 border-bottom pb-2"><i class="fa-solid fa-scissors me-2 text-success"></i>3. Cấu Hình Đầu Ra & Gửi Công Việc</h6>
                    <form action="video_editor.php" method="POST">
                        <input type="hidden" name="action_type" value="create_job">
                        <input type="hidden" name="video_id" id="formVideoId">
                        
                        <div class="row g-2 align-items-center mb-3">
                            <div class="col-6">
                                <label class="form-label small text-muted">Bắt đầu (giây):</label>
                                <input type="number" step="0.1" class="form-control form-control-sm" name="start_time" id="startTimeInput" value="0" required>
                                <input type="range" class="form-range mt-1" id="startRange" min="0" max="10" step="0.1" value="0">
                            </div>
                            <div class="col-6">
                                <label class="form-label small text-muted">Kết thúc (giây):</label>
                                <input type="number" step="0.1" class="form-control form-control-sm" name="end_time" id="endTimeInput" value="10" required>
                                <input type="range" class="form-range mt-1" id="endRange" min="0" max="10" step="0.1" value="10">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small text-muted">Cấu hình Preset đầu ra:</label>
                            <select class="form-select form-select-sm" name="preset_id" required>
                                <?php foreach ($presets as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['width'] ?>x<?= $p['height'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <button type="submit" class="btn btn-success w-100 btn-sm py-2" id="submitJobBtn" disabled>
                            <i class="fa-solid fa-play me-1"></i>Đưa Vào Hàng Đợi Xử Lý (Queue)
                        </button>
                    </form>
                </div>

                <div class="card p-3">
                    <h6 class="text-dark fw-semibold mb-3 border-bottom pb-2"><i class="fa-solid fa-list-check me-2 text-warning"></i>4. Hàng Đợi Công Việc & Trạng Thái</h6>
                    <div class="table-responsive" style="max-height: 320px; overflow-y: auto;">
                        <table class="table table-hover align-middle mb-0">
                            <thead class="table-dark text-uppercase font-monospace" style="font-size: 11px;">
                                <tr>
                                    <th>ID</th>
                                    <th>Thumbnail</th>
                                    <th>Tên Video</th>
                                    <th>Cắt (s)</th>
                                    <th>Trạng Thái</th>
                                    <th class="text-end">Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($jobs)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-4 small">Chưa có công việc nào trong hàng đợi.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($jobs as $j): 
                                        $displayThumb = '';
                                        $previewVideo = '';
                                        if (!empty($j['output_thumb']) && file_exists($j['output_thumb'])) {
                                            $displayThumb = $j['output_thumb'];
                                            $previewVideo = $j['output_path'];
                                        } elseif (!empty($j['source_thumb']) && file_exists($j['source_thumb'])) {
                                            $displayThumb = $j['source_thumb'];
                                            $previewVideo = $j['source_filepath'];
                                        }
                                    ?>
                                        <tr>
                                            <td class="fw-bold font-monospace">#<?= $j['id'] ?></td>
                                            <td>
                                                <?php if ($displayThumb): ?>
                                                    <img src="<?= htmlspecialchars($displayThumb) ?>" class="thumb-img" alt="Thumb" title="Xem trước" onclick="openPreview('<?= htmlspecialchars($previewVideo) ?>', 'Video: <?= htmlspecialchars($j['filename']) ?>')">
                                                <?php else: ?>
                                                    <span class="text-muted small">--</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-truncate" style="max-width: 180px;" title="<?= htmlspecialchars($j['filename']) ?>"><?= htmlspecialchars($j['filename']) ?></td>
                                            <td class="font-monospace text-muted"><?= $j['start_time'] ?>s - <?= $j['end_time'] ?>s</td>
                                            <td>
                                                <?php if ($j['status'] === 'completed'): ?>
                                                    <span class="badge bg-success bg-opacity-25 text-success border border-success border-opacity-25 font-monospace">Hoàn thành</span>
                                                <?php elseif ($j['status'] === 'processing'): ?>
                                                    <span class="badge bg-warning bg-opacity-25 text-dark border border-warning border-opacity-25 font-monospace">Đang xử lý</span>
                                                <?php elseif ($j['status'] === 'error'): ?>
                                                    <span class="badge bg-danger bg-opacity-25 text-danger border border-danger border-opacity-25 font-monospace" title="<?= htmlspecialchars($j['error_message']) ?>">Lỗi</span>
                                                <?php elseif ($j['status'] === 'cancelled'): ?>
                                                    <span class="badge bg-secondary bg-opacity-25 text-secondary font-monospace">Đã hủy</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info bg-opacity-25 text-dark border border-info border-opacity-25 font-monospace">Chờ xử lý</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-end">
                                                <?php if ($j['status'] === 'pending'): ?>
                                                    <a href="video_editor.php?action=run&job_id=<?= $j['id'] ?>" class="btn btn-outline-success btn-sm py-0 px-2" style="font-size: 12px;">Chạy</a>
                                                    <a href="video_editor.php?action=cancel&job_id=<?= $j['id'] ?>" class="btn btn-outline-danger btn-sm py-0 px-2" style="font-size: 12px;">Hủy</a>
                                                <?php elseif ($j['status'] === 'error' || $j['status'] === 'cancelled'): ?>
                                                    <a href="video_editor.php?action=run&job_id=<?= $j['id'] ?>" class="btn btn-outline-warning btn-sm py-0 px-2" style="font-size: 12px;">Thử lại</a>
                                                <?php elseif ($j['status'] === 'completed' && !empty($j['output_path']) && file_exists($j['output_path'])): ?>
                                                    <a href="<?= htmlspecialchars($j['output_path']) ?>" class="btn btn-outline-primary btn-sm py-0 px-2 font-monospace" style="font-size: 12px;" download>Tải về</a>
                                                <?php else: ?>
                                                    <span class="text-muted">--</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal xem trước video -->
    <div class="modal fade" id="previewModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content text-white border-0 shadow-lg">
          <div class="modal-header bg-dark py-2">
            <h6 class="modal-title font-monospace text-muted small" id="previewModalTitle">Xem trước video</h6>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center p-0 bg-black">
            <video id="modalVideoPlayer" controls class="w-100" style="max-height: 420px;"></video>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const videoSelector = document.getElementById('videoSelector');
        const previewPlayer = document.getElementById('previewPlayer');
        const playerPlaceholder = document.getElementById('playerPlaceholder');
        const timelineSection = document.getElementById('timelineSection');
        const formVideoId = document.getElementById('formVideoId');
        const submitJobBtn = document.getElementById('submitJobBtn');

        const startTimeInput = document.getElementById('startTimeInput');
        const endTimeInput = document.getElementById('endTimeInput');
        const startRange = document.getElementById('startRange');
        const endRange = document.getElementById('endRange');
        const timelineSelection = document.getElementById('timelineSelection');
        const timelinePlayhead = document.getElementById('timelinePlayhead');
        const timeIndicator = document.getElementById('timeIndicator');

        if (videoSelector) {
            videoSelector.addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                if (this.value) {
                    previewPlayer.src = this.value;
                    previewPlayer.style.display = 'block';
                    playerPlaceholder.style.display = 'none';
                    timelineSection.style.display = 'block';
                    formVideoId.value = opt.getAttribute('data-id');
                    submitJobBtn.removeAttribute('disabled');

                    previewPlayer.onloadedmetadata = function() {
                        const dur = previewPlayer.duration;
                        startRange.max = dur;
                        endRange.max = dur;
                        startRange.value = 0;
                        endRange.value = dur;
                        startTimeInput.value = 0;
                        endTimeInput.value = dur.toFixed(1);
                        updateTimelineVisual();
                    };
                } else {
                    previewPlayer.style.display = 'none';
                    playerPlaceholder.style.display = 'block';
                    timelineSection.style.display = 'none';
                    formVideoId.value = '';
                    submitJobBtn.setAttribute('disabled', 'true');
                }
            });
        }

        // Đồng bộ thanh kéo Start
        startRange.addEventListener('input', function() {
            let start = parseFloat(this.value);
            let end = parseFloat(endRange.value);
            if (start >= end) {
                start = end - 0.1;
                this.value = start;
            }
            startTimeInput.value = start.toFixed(1);
            previewPlayer.currentTime = start;
            updateTimelineVisual();
        });

        // Đồng bộ thanh kéo End
        endRange.addEventListener('input', function() {
            let end = parseFloat(this.value);
            let start = parseFloat(startRange.value);
            if (end <= start) {
                end = start + 0.1;
                this.value = end;
            }
            endTimeInput.value = end.toFixed(1);
            previewPlayer.currentTime = end;
            updateTimelineVisual();
        });

        // Đồng bộ khi nhập số trực tiếp
        startTimeInput.addEventListener('input', function() {
            let val = parseFloat(this.value) || 0;
            startRange.value = val;
            updateTimelineVisual();
        });
        endTimeInput.addEventListener('input', function() {
            let val = parseFloat(this.value) || 0;
            endRange.value = val;
            updateTimelineVisual();
        });

        // Cập nhật vị trí playhead khi video đang chạy
        previewPlayer.addEventListener('timeupdate', function() {
            const dur = previewPlayer.duration || 1;
            const cur = previewPlayer.currentTime;
            timeIndicator.innerText = cur.toFixed(1) + 's / ' + dur.toFixed(1) + 's';
            const percent = (cur / dur) * 100;
            timelinePlayhead.style.left = percent + '%';
        });

        // Bấm vào thanh timeline để tua video
        document.getElementById('timelineTrack').addEventListener('click', function(e) {
            const rect = this.getBoundingClientRect();
            const pos = (e.clientX - rect.left) / rect.width;
            const dur = previewPlayer.duration || 0;
            if (dur > 0) {
                previewPlayer.currentTime = pos * dur;
            }
        });

        function updateTimelineVisual() {
            const dur = parseFloat(previewPlayer.duration) || 1;
            const start = parseFloat(startRange.value) || 0;
            const end = parseFloat(endRange.value) || dur;

            const leftPercent = (start / dur) * 100;
            const widthPercent = ((end - start) / dur) * 100;

            timelineSelection.style.left = leftPercent + '%';
            timelineSelection.style.width = widthPercent + '%';
        }

        function setStartTime() {
            if (previewPlayer) {
                const cur = previewPlayer.currentTime;
                startTimeInput.value = cur.toFixed(1);
                startRange.value = cur;
                updateTimelineVisual();
            }
        }

        function setEndTime() {
            if (previewPlayer) {
                const cur = previewPlayer.currentTime;
                endTimeInput.value = cur.toFixed(1);
                endRange.value = cur;
                updateTimelineVisual();
            }
        }

        function openPreview(videoUrl, title) {
            const player = document.getElementById('modalVideoPlayer');
            document.getElementById('previewModalTitle').innerText = title;
            player.src = videoUrl;
            player.load();
            const modal = new bootstrap.Modal(document.getElementById('previewModal'));
            modal.show();
        }

        document.getElementById('previewModal').addEventListener('hidden.bs.modal', function () {
            const player = document.getElementById('modalVideoPlayer');
            player.pause();
            player.src = '';
        });
    </script>
</body>
</html>