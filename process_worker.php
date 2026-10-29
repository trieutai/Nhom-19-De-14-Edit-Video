<?php
require_once 'db.php';
require_once 'VideoProcessor.php';

$jobId = isset($_GET['job_id']) ? intval($_GET['job_id']) : 0;
if (!$jobId) die("Mã công việc không hợp lệ.");

$stmt = $pdo->prepare("
    SELECT j.*, v.filepath as video_path, p.width, p.height, p.keep_audio 
    FROM jobs j 
    JOIN videos v ON j.video_id = v.id 
    JOIN presets p ON j.preset_id = p.id 
    WHERE j.id = ?
");
$stmt->execute([$jobId]);
$job = $stmt->fetch();

if (!$job) die("Không tìm thấy công việc.");

$stmtAttemptCount = $pdo->prepare("SELECT COUNT(*) FROM job_attempts WHERE job_id = ?");
$stmtAttemptCount->execute([$jobId]);
$attemptNumber = intval($stmtAttemptCount->fetchColumn()) + 1;

$pdo->prepare("UPDATE jobs SET status = 'processing' WHERE id = ?")->execute([$jobId]);

$outputDir = 'outputs/';
if (!is_dir($outputDir)) { mkdir($outputDir, 0777, true); }

$processor = new VideoProcessor();
$outputFilename = 'output_' . time() . '_job' . $jobId . '_attempt' . $attemptNumber . '.mp4';
$outputPath = $outputDir . $outputFilename;

$result = $processor->processVideo(
    $job['video_path'],
    $outputPath,
    $job['start_time'],
    $job['end_time'],
    $job['width'],
    $job['height'],
    $job['keep_audio']
);

if ($result['success']) {
    $pdo->prepare("UPDATE jobs SET status = 'completed', error_message = NULL WHERE id = ?")->execute([$jobId]);
    
    $stmtLog = $pdo->prepare("INSERT INTO job_attempts (job_id, attempt_number, status, log_output) VALUES (?, ?, 'success', ?)");
    $stmtLog->execute([$jobId, $attemptNumber, $result['log']]);
    $attemptId = $pdo->lastInsertId();

    $fileSize = filesize($outputPath);
    $stmtOutput = $pdo->prepare("INSERT INTO outputs (job_id, attempt_id, output_path, file_size) VALUES (?, ?, ?, ?)");
    $stmtOutput->execute([$jobId, $attemptId, $outputPath, $fileSize]);

    echo "<script>alert('Xử lý video thành công!'); window.location.href='editor.php';</script>";
} else {
    $errorMsg = $result['error'];
    $pdo->prepare("UPDATE jobs SET status = 'error', error_message = ? WHERE id = ?")->execute([$errorMsg, $jobId]);
    
    $stmtLog = $pdo->prepare("INSERT INTO job_attempts (job_id, attempt_number, status, log_output) VALUES (?, ?, 'failed', ?)");
    $stmtLog->execute([$jobId, $attemptNumber, $errorMsg]);

    echo "<h3>Xử lý thất bại!</h3><pre>" . htmlspecialchars($errorMsg) . "</pre><a href='editor.php' class='btn btn-primary'>Quay lại</a>";
}
?>