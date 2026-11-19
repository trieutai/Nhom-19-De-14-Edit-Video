<?php require_once __DIR__.'/auth.php'; $vtUser=vtCurrentUserId(); ?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>VideoStudio Pro - Trang Chủ</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        /* Hiệu ứng nền màu động chạy liên tục */
        body {
            margin: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(-45deg, #ee7752, #e73c7e, #23a6d5, #23d5ab);
            background-size: 400% 400%;
            animation: gradientBG 15s ease infinite;
            min-height: 100vh;
            color: #fff;
        }
        @keyframes gradientBG {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .hero-section { padding: 120px 0; text-align: center; }
        
        /* Thẻ lựa chọn nhánh */
        .branch-card {
            background: rgba(255, 255, 255, 0.15);
            backdrop-filter: blur(12px);
            border-radius: 20px;
            padding: 40px 30px;
            border: 1px solid rgba(255, 255, 255, 0.3);
            transition: all 0.3s ease;
            color: white;
            text-decoration: none;
            display: block;
            height: 100%;
        }
        .branch-card:hover {
            transform: translateY(-8px);
            background: rgba(255, 255, 255, 0.25);
            color: white;
            box-shadow: 0 15px 30px rgba(0,0,0,0.3);
        }
    </style>
</head>
<body>
    <!-- Thanh điều hướng -->
    <nav class="navbar navbar-dark fixed-top px-4">
        <a class="navbar-brand fw-bold fs-4" href="index.php"><i class="fa-solid fa-clapperboard me-2"></i>DukTay Edit</a>
        <div class="d-flex gap-2">
            <?php if($vtUser): ?><span class="small align-self-center"><?=htmlspecialchars((string)($_SESSION['vt_user_email']??''),ENT_QUOTES,'UTF-8')?></span><form method="post" action="logout.php" class="m-0"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars(vtCsrfToken(),ENT_QUOTES,'UTF-8')?>"><button class="btn btn-warning btn-sm rounded-pill" type="submit">Đăng xuất</button></form><?php else: ?><a href="login.php" class="btn btn-warning btn-sm rounded-pill px-3">Đăng nhập / Đăng ký</a><?php endif; ?>
            <a href="video_editor.php" class="btn btn-outline-light btn-sm rounded-pill px-3">Video Studio</a>
            <a href="image_editor.php" class="btn btn-outline-light btn-sm rounded-pill px-3">Image Studio</a>
        </div>
    </nav>

    <!-- Khối nội dung chính với 2 lựa chọn -->
    <div class="container hero-section">
        <h1 class="display-3 fw-bold mb-3">Hệ Thống Xử Lý Đa Phương Tiện</h1>
        <p class="lead mb-5 opacity-75">Vui lòng lựa chọn phân vùng công việc bạn muốn thực hiện cho Đề tài 14:</p>
        
        <div class="row g-4 justify-content-center">
            <!-- Nhánh 1: Xử lý Video (Trọng tâm Đề tài 14) -->
            <div class="col-md-5">
                <a href="video_editor.php" class="branch-card text-center">
                    <i class="fa-solid fa-video fa-4x mb-3 text-warning"></i>
                    <h3>Nhánh Xử Lý Video</h3>
                    <p class="small opacity-75 mt-2">Tải lô video, đọc thông số ffprobe thực tế, xem trước trực quan và quản lý hàng đợi độc lập.</p>
                    <span class="btn btn-warning rounded-pill px-4 mt-3 fw-bold text-dark shadow-sm">Truy cập Video Studio</span>
                </a>
            </div>

            <!-- Nhánh 2: Xử lý Ảnh (Cắt kéo thả Cropper.js) -->
            <div class="col-md-5">
                <a href="image_editor.php" class="branch-card text-center">
                    <i class="fa-solid fa-crop fa-4x mb-3 text-info"></i>
                    <h3>Nhánh Xử Lý Ảnh</h3>
                    <p class="small opacity-75 mt-2">Tải ảnh lên và sử dụng công cụ kéo thả trực quan để cắt khung hình theo ý muốn một cách mượt mà.</p>
                    <span class="btn btn-info rounded-pill px-4 mt-3 fw-bold text-dark shadow-sm">Truy cập Image Studio</span>
                </a>
            </div>
        </div>
    </div>
</body>
</html>