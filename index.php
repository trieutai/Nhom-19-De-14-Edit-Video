<?php
session_start();

// Thiết lập thư mục riêng nếu đã đăng nhập
if (isset($_SESSION['username'])) {
    $current_user =$_SESSION['username'];
    $upload_dir = "uploads/" . $current_user . "/";
    $output_dir = "outputs/" . $current_user . "/";

    if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
    if (!is_dir($output_dir)) { mkdir($output_dir, 0777, true); }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>DukTay Edit - Trang Chủ</title>
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

        .branch-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            padding: 35px 25px;
            transition: all 0.25s ease;
            color: #1e293b;
            text-decoration: none;
            display: block;
            height: 100%;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
        }
        .branch-card:hover {
            transform: translateY(-4px);
            border-color: #3b82f6;
            box-shadow: 0 10px 20px -5px rgba(59, 130, 246, 0.15);
            color: #1e293b;
        }

        .info-card {
            background-color: #ffffff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 24px;
            height: 100%;
            box-shadow: 0 2px 4px rgba(0,0,0,0.02);
        }

        .step-number {
            width: 36px;
            height: 36px;
            background: rgba(59, 130, 246, 0.1);
            color: #2563eb;
            font-weight: bold;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }

        .feature-icon {
            font-size: 24px;
            color: #2563eb;
            margin-bottom: 12px;
        }

        footer {
            border-top: 1px solid #e2e8f0;
            background-color: #ffffff;
            color: #64748b;
            padding: 30px 0;
            font-size: 14px;
            margin-top: 80px;
        }
    </style>
</head>
<body>
    <!-- Thanh điều hướng -->
    <nav class="navbar navbar-expand fixed-top px-4 py-2 justify-content-between">
        <a class="navbar-brand fw-bold fs-6 text-dark" href="index.php">
            <i class="fa-solid fa-clapperboard me-2 text-primary"></i>DukTay Edit 
            <span class="badge bg-light text-dark border ms-2 font-monospace" style="font-size: 10px;">WORKSPACE</span>
        </a>
        
        <div class="d-flex align-items-center gap-3">
            <div class="d-flex gap-2">
                <a href="video_editor.php" class="btn btn-outline-primary btn-sm px-3">Video Studio</a>
                <a href="image_editor.php" class="btn btn-outline-secondary btn-sm px-3">Image Studio</a>
            </div>

            <!-- Khu vực tài khoản ở góc trên bên phải -->
            <div class="d-flex align-items-center gap-2 border-start ps-3 border-secondary border-opacity-25">
                <?php if (isset($_SESSION['username'])): ?>
                    <span class="text-dark small fw-semibold">
                        <i class="fa-solid fa-user-circle text-primary me-1"></i><?php echo htmlspecialchars($_SESSION['username']); ?>
                    </span>
                    <a href="logout.php" class="btn btn-danger btn-sm px-3 py-1" style="font-size: 12px;">Đăng xuất</a>
                <?php else: ?>
                    <a href="login.php" class="btn btn-primary btn-sm px-3 py-1" style="font-size: 12px;">Đăng nhập</a>
                    <a href="register.php" class="btn btn-success btn-sm px-3 py-1" style="font-size: 12px;">Đăng ký</a>
                <?php endif; ?>
            </div>
        </div>
    </nav>

    <!-- Khối nội dung chính (Hero Section) -->
    <div class="container" style="padding-top: 100px;">
        <div class="text-center py-5">
            <h1 class="display-5 fw-bold mb-3 text-dark">Hệ Thống Xử Lý Đa Phương Tiện</h1>
            <p class="text-muted lead mb-5" style="font-size: 16px;">Vui lòng lựa chọn phân vùng công việc bạn muốn thực hiện:</p>
            
            <div class="row g-4 justify-content-center">
                <!-- Nhánh 1: Xử lý Video -->
                <div class="col-md-5">
                    <a href="video_editor.php" class="branch-card text-center">
                        <i class="fa-solid fa-video fa-3x mb-3 text-primary"></i>
                        <h4 class="fw-semibold mb-2 text-dark">Nhánh Xử Lý Video</h4>
                        <p class="text-muted small mb-4">Tải lô video, đọc thông số ffprobe thực tế, xem trước trực quan và quản lý hàng đợi độc lập.</p>
                        <span class="btn btn-primary btn-sm px-4 fw-medium">Truy cập Video Studio</span>
                    </a>
                </div>

                <!-- Nhánh 2: Xử lý Ảnh -->
                <div class="col-md-5">
                    <a href="image_editor.php" class="branch-card text-center">
                        <i class="fa-solid fa-crop fa-3x mb-3 text-info"></i>
                        <h4 class="fw-semibold mb-2 text-dark">Nhánh Xử Lý Ảnh</h4>
                        <p class="text-muted small mb-4">Tải ảnh lên và sử dụng công cụ kéo thả trực quan để cắt khung hình theo ý muốn một cách mượt mà.</p>
                        <span class="btn btn-outline-info btn-sm px-4 fw-medium">Truy cập Image Studio</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- PHẦN 1: GIỚI THIỆU -->
        <div class="card p-4 mb-5 border-0 shadow-sm bg-white">
            <h3 class="fw-semibold text-dark mb-3 h5"><i class="fa-solid fa-circle-info text-primary me-2"></i>Dễ dàng chỉnh sửa video ngay trên trình duyệt</h3>
            <p class="text-muted small mb-3" style="line-height: 1.6;">
                Với công nghệ hiện đại, chỉnh sửa video trực tuyến không còn là điều viển vông. Bất kỳ ai cũng có thể cắt ghép và chỉnh sửa cảnh quay cá nhân hoặc dự án thương mại trên Internet mà không cần tải xuống bất kỳ phần mềm cồng kềnh nào. Công cụ hoạt động tối ưu, đáp ứng mọi nhu cầu từ người mới bắt đầu đến chuyên gia.
            </p>
            <p class="text-muted small m-0" style="line-height: 1.6;">
                Bạn có thể thực hiện mọi thao tác cần thiết một cách nhanh chóng: cắt video, thay đổi tỷ lệ khung hình, xử lý hàng đợi công việc độc lập một cách mượt mà và hiệu quả.
            </p>
        </div>

        <!-- PHẦN 2: HƯỚNG DẪN SỬ DỤNG -->
        <div class="mb-5">
            <h3 class="fw-semibold text-dark mb-4 h5"><i class="fa-solid fa-list-ol text-success me-2"></i>Hướng Dẫn Sử Dụng Nhanh</h3>
            <div class="row g-4">
                <div class="col-md-6">
                    <div class="info-card">
                        <div class="d-flex gap-3">
                            <div class="step-number">1</div>
                            <div>
                                <h6 class="fw-semibold mb-1 text-dark">Tải lên video</h6>
                                <p class="text-muted small m-0">Nhấp vào nút chọn tệp hoặc kéo thả video vào hệ thống. Hệ thống sẽ tự động dùng ffprobe để đọc thông số kỹ thuật thực tế của video.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-card">
                        <div class="d-flex gap-3">
                            <div class="step-number">2</div>
                            <div>
                                <h6 class="fw-semibold mb-1 text-dark">Xem trước & chọn mốc cắt</h6>
                                <p class="text-muted small m-0">Sử dụng trình phát trực quan để xem video, tua đến mốc thời gian mong muốn và lấy giá trị bắt đầu/kết thúc chính xác đến từng giây.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-card">
                        <div class="d-flex gap-3">
                            <div class="step-number">3</div>
                            <div>
                                <h6 class="fw-semibold mb-1 text-dark">Cấu hình & đưa vào hàng đợi</h6>
                                <p class="text-muted small m-0">Lựa chọn các preset đầu ra phù hợp (HD, Full HD, TikTok/Reels dọc) và bấm đưa vào hàng đợi xử lý độc lập thông qua FFmpeg.</p>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="info-card">
                        <div class="d-flex gap-3">
                            <div class="step-number">4</div>
                            <div>
                                <h6 class="fw-semibold mb-1 text-dark">Tải về kết quả</h6>
                                <p class="text-muted small m-0">Sau khi quá trình xử lý hoàn tất, hệ thống sẽ tự động tạo ảnh thu nhỏ (thumbnail) và cung cấp nút tải về video chất lượng cao ngay lập tức.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- PHẦN 3: TẠI SAO CHỌN CHÚNG TÔI -->
        <div class="mb-5">
            <h3 class="fw-semibold text-dark mb-4 h5"><i class="fa-solid fa-star text-warning me-2"></i>Tại sao nên lựa chọn hệ thống của chúng tôi?</h3>
            <div class="row g-4">
                <div class="col-md-4">
                    <div class="info-card">
                        <div class="feature-icon"><i class="fa-solid fa-shield-halved"></i></div>
                        <h6 class="fw-semibold mb-2 text-dark">Bảo Mật & Cô Lập Dữ Liệu</h6>
                        <p class="text-muted small m-0">Mỗi tài khoản sở hữu không gian lưu trữ và bảng dữ liệu riêng biệt (uploads/{username}). Dữ liệu của bạn được bảo mật tuyệt đối, các tài khoản khác không thể thấy.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-card">
                        <div class="feature-icon"><i class="fa-solid fa-bolt"></i></div>
                        <h6 class="fw-semibold mb-2 text-dark">Chuyển đổi siêu tốc</h6>
                        <p class="text-muted small m-0">Hệ thống xử lý phía máy chủ sử dụng FFmpeg tối ưu hóa tốc độ, giúp cắt ghép và xuất video cực kỳ nhanh chóng.</p>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="info-card">
                        <div class="feature-icon"><i class="fa-solid fa-file-video"></i></div>
                        <h6 class="fw-semibold mb-2 text-dark">Hỗ trợ mọi định dạng</h6>
                        <p class="text-muted small m-0">Hỗ trợ đầy đủ các định dạng video phổ biến hiện nay như MP4, MKV, AVI, MOV, WebM với độ phân giải linh hoạt.</p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Footer -->
    <footer class="text-center">
        <div class="container">
            <p class="mb-1 fw-semibold text-dark">DukTay Edit &mdash; Hệ Thống Xử Lý Đa Phương Tiện Chuyên Sâu</p>
            <p class="small text-muted m-0">Phát triển phục vụ học tập và nghiên cứu đề tài.</p>
        </div>
    </footer>
</body>
</html>