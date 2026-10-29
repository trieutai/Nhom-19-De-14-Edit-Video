<?php
require_once 'db.php';

// Lấy toàn bộ dữ liệu từ các bảng trong cơ sở dữ liệu
$videos =$pdo->query("SELECT * FROM videos ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$presets =$pdo->query("SELECT * FROM presets ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$jobs =$pdo->query("SELECT * FROM jobs ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$job_attempts =$pdo->query("SELECT * FROM job_attempts ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$outputs =$pdo->query("SELECT * FROM outputs ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Kiểm tra Dữ liệu MySQL - DukTay Edit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f8f9fa; font-family: 'Segoe UI', sans-serif; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    </style>
</head>
<body class="p-4">
    <div class="container">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h3 class="fw-bold m-0"><i class="fa-solid fa-database me-2 text-primary"></i>Trang Kiểm Tra Dữ Liệu MySQL</h3>
            <a href="editor.php" class="btn btn-outline-dark rounded-pill px-4"><i class="fa-solid fa-arrow-left me-1"></i>Quay lại Trình Biên Tập</a>
        </div>

        <!-- 1. BẢNG VIDEOS -->
        <div class="card mb-4 p-3 bg-white">
            <h5 class="text-primary fw-bold mb-3">1. Bảng `videos` (Tệp video nguồn)</h5>
            <div class="table-responsive">
                <table class="table table-hover table-bordered small align-middle">
                    <thead class="table-dark">
                        <tr><th>ID</th><th>Tên tệp</th><th>Đường dẫn</th><th>Thời lượng</th><th>Kích thước</th><th>Dung lượng</th><th>Thời gian tạo</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($videos)): ?><tr><td colspan="7" class="text-center text-muted">Chưa có dữ liệu</td></tr><?php endif; ?>
                        <?php foreach($videos as$v): ?>
                        <tr>
                            <td>#<?= $v['id'] ?></td>
                            <td><?= htmlspecialchars($v['filename']) ?></td>
                            <td><code><?= htmlspecialchars($v['filepath']) ?></code></td>
                            <td><?= round($v['duration'], 2) ?>s</td>
                            <td><?= $v['width'] ?>x<?=$v['height'] ?></td>
                            <td><?= round($v['file_size']/1024/1024, 2) ?> MB</td>
                            <td><?= $v['created_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 2. BẢNG PRESETS -->
        <div class="card mb-4 p-3 bg-white">
            <h5 class="text-success fw-bold mb-3">2. Bảng `presets` (Cấu hình đầu ra)</h5>
            <div class="table-responsive">
                <table class="table table-hover table-bordered small align-middle">
                    <thead class="table-success text-dark">
                        <tr><th>ID</th><th>Tên Preset</th><th>Chiều rộng (Width)</th><th>Chiều cao (Height)</th><th>Giữ âm thanh</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach($presets as$p): ?>
                        <tr>
                            <td>#<?= $p['id'] ?></td>
                            <td><?= htmlspecialchars($p['name']) ?></td>
                            <td><?= $p['width'] ?>px</td>
                            <td><?= $p['height'] ?>px</td>
                            <td><?= $p['keep_audio'] ? 'Có' : 'Không' ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 3. BẢNG JOBS -->
        <div class="card mb-4 p-3 bg-white">
            <h5 class="text-warning text-dark fw-bold mb-3">3. Bảng `jobs` (Hàng đợi công việc)</h5>
            <div class="table-responsive">
                <table class="table table-hover table-bordered small align-middle">
                    <thead class="table-warning">
                        <tr><th>ID</th><th>Video ID</th><th>Preset ID</th><th>Bắt đầu</th><th>Kết thúc</th><th>Trạng thái</th><th>Lỗi</th><th>Thời gian tạo</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($jobs)): ?><tr><td colspan="8" class="text-center text-muted">Chưa có công việc nào</td></tr><?php endif; ?>
                        <?php foreach($jobs as$j): ?>
                        <tr>
                            <td>#<?= $j['id'] ?></td>
                            <td>#<?= $j['video_id'] ?></td>
                            <td>#<?= $j['preset_id'] ?></td>
                            <td><?= $j['start_time'] ?>s</td>
                            <td><?= $j['end_time'] ?>s</td>
                            <td><span class="badge bg-secondary"><?= $j['status'] ?></span></td>
                            <td class="text-danger"><?= htmlspecialchars($j['error_message'] ?? '') ?></td>
                            <td><?= $j['created_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 4. BẢNG JOB_ATTEMPTS -->
        <div class="card mb-4 p-3 bg-white">
            <h5 class="text-info text-dark fw-bold mb-3">4. Bảng `job_attempts` (Lịch sử các lần thử chạy)</h5>
            <div class="table-responsive">
                <table class="table table-hover table-bordered small align-middle">
                    <thead class="table-info">
                        <tr><th>ID</th><th>Job ID</th><th>Lần thử số</th><th>Trạng thái</th><th>Nhật ký thực thi (Log)</th><th>Thời gian</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($job_attempts)): ?><tr><td colspan="6" class="text-center text-muted">Chưa có lịch sử chạy</td></tr><?php endif; ?>
                        <?php foreach($job_attempts as$ja): ?>
                        <tr>
                            <td>#<?= $ja['id'] ?></td>
                            <td>#<?= $ja['job_id'] ?></td>
                            <td><?= $ja['attempt_number'] ?></td>
                            <td><?= $ja['status'] ?></td>
                            <td><pre style="max-height: 80px; font-size: 11px;" class="m-0"><?= htmlspecialchars($ja['log_output']) ?></pre></td>
                            <td><?= $ja['executed_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- 5. BẢNG OUTPUTS -->
        <div class="card mb-4 p-3 bg-white">
            <h5 class="text-dark fw-bold mb-3">5. Bảng `outputs` (Kết quả đầu ra)</h5>
            <div class="table-responsive">
                <table class="table table-hover table-bordered small align-middle">
                    <thead class="table-secondary">
                        <tr><th>ID</th><th>Job ID</th><th>Attempt ID</th><th>Đường dẫn tệp kết quả</th><th>Dung lượng</th><th>Thời gian</th></tr>
                    </thead>
                    <tbody>
                        <?php if(empty($outputs)): ?><tr><td colspan="6" class="text-center text-muted">Chưa có tệp kết quả nào</td></tr><?php endif; ?>
                        <?php foreach($outputs as$o): ?>
                        <tr>
                            <td>#<?= $o['id'] ?></td>
                            <td>#<?= $o['job_id'] ?></td>
                            <td>#<?= $o['attempt_id'] ?></td>
                            <td><a href="<?= $o['output_path'] ?>" target="_blank"><?= $o['output_path'] ?></a></td>
                            <td><?= round($o['file_size']/1024/1024, 2) ?> MB</td>
                            <td><?= $o['created_at'] ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</body>
</html>