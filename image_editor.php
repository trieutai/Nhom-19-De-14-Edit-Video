<?php
require_once 'db.php';
$message = '';

// Xử lý cắt ảnh trực quan
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_type']) && $_POST['action_type'] === 'advanced_image_crop') {
    $relative = (string)($_POST['image_path'] ?? '');
    $root = realpath(__DIR__.'/uploads');
    $inputPath = realpath(__DIR__.'/'.$relative);
    if (!$root || !$inputPath || !str_starts_with($inputPath,$root.DIRECTORY_SEPARATOR) || !is_file($inputPath)) {
        http_response_code(400); exit('Đường dẫn ảnh không hợp lệ');
    }
    $x = intval($_POST['crop_x']);
    $y = intval($_POST['crop_y']);
    $w = intval($_POST['crop_w']);
    $h = intval($_POST['crop_h']);
    $size = @getimagesize($inputPath);
    if (!$size || $x<0 || $y<0 || $w<1 || $h<1 || $x+$w>$size[0] || $y+$h>$size[1]) {
        http_response_code(400); exit('Thông số cắt ảnh vượt giới hạn');
    }

    $outputDir = 'outputs/';
    if (!is_dir($outputDir)) { mkdir($outputDir, 0777, true); }
    $outputPath = $outputDir . 'cropped_' . time() . '.jpg';

    $cmd = sprintf('ffmpeg -y -i %s -vf "crop=%d:%d:%d:%d" %s 2>&1', escapeshellarg($inputPath), $w, $h, $x, $y, escapeshellarg($outputPath));
    exec($cmd);

    if (file_exists($outputPath)) {
        $message = "<div class='alert alert-success shadow-sm rounded-4'>Cắt ảnh thành công! <a href='$outputPath' class='fw-bold' download>Tải xuống ngay</a></div>";
    } else {
        $message = "<div class='alert alert-danger shadow-sm rounded-4'>Lỗi thực thi cắt ảnh qua FFmpeg.</div>";
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Image Studio - Trình Chỉnh Sửa Ảnh</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.css">
    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .navbar { background: #212529; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
    </style>
</head>
<body>
    <nav class="navbar navbar-dark px-4 py-3 shadow-sm">
        <a class="navbar-brand fw-bold" href="index.php"><i class="fa-solid fa-crop me-2 text-info"></i>VideoStudio Pro <span class="badge bg-info text-dark ms-2">Image Branch</span></a>
        <a href="index.php" class="btn btn-outline-light btn-sm rounded-pill px-3"><i class="fa-solid fa-house me-1"></i>Trang Chủ</a>
    </nav>

    <div class="container my-4">
        <h3 class="fw-bold mb-4 text-center">🖼️ Nhánh Chuyên Sâu: Cắt & Tối Ưu Hóa Ảnh</h3>
        <?= $message ?>

        <div class="row justify-content-center">
            <div class="col-lg-10">
                <div class="card p-4 bg-white">
                    <div class="d-flex justify-content-between align-items-center mb-3">
                        <h5 class="text-success fw-bold m-0"><i class="fa-solid fa-crop me-2"></i>Trình Cắt Ảnh Trực Quan</h5>
                        <span class="text-muted small">Kéo thả khung cắt trực tiếp trên ảnh</span>
                    </div>

                    <div class="mb-3">
                        <input class="form-control" type="file" id="imageInput" accept="image/*">
                    </div>

                    <div class="row g-4">
                        <div class="col-lg-8">
                            <div class="workspace bg-dark rounded-3 overflow-hidden d-flex align-items-center justify-content-center" style="min-height: 400px; max-height: 500px;">
                                <img id="sourceImage" src="" style="max-width: 100%; display: none;">
                                <div id="placeholderText" class="text-muted text-center p-5">
                                    <i class="fa-solid fa-image fa-3x mb-3 opacity-50"></i>
                                    <p>Vui lòng chọn một tệp ảnh để bắt đầu kéo thả cắt ghép.</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-4 d-flex flex-column justify-content-center">
                            <div class="p-3 bg-light rounded-3 border">
                                <h6 class="fw-bold mb-3"><i class="fa-solid fa-wand-magic-sparkles me-2 text-primary"></i>Hành động</h6>
                                <p class="small text-muted mb-4">Khung cắt đã sẵn sàng. Tương tác trực tiếp trên ảnh rồi bấm nút lưu bên dưới.</p>
                                
                                <form action="image_editor.php" method="POST">
                                    <input type="hidden" name="action_type" value="advanced_image_crop">
                                    <input type="hidden" name="image_path" id="imagePathInput">
                                    <input type="hidden" name="crop_x" id="cropX">
                                    <input type="hidden" name="crop_y" id="cropY">
                                    <input type="hidden" name="crop_w" id="cropW">
                                    <input type="hidden" name="crop_h" id="cropH">

                                    <button type="submit" class="btn btn-success w-100 fw-bold py-3 rounded-pill shadow-sm" id="cropBtn" disabled>
                                        <i class="fa-solid fa-scissors me-2"></i>Lưu & Tải Ảnh Đã Cắt
                                    </button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.5.13/cropper.min.js"></script>
    <script>
        let cropper;
        const imageInput = document.getElementById('imageInput');
        const sourceImage = document.getElementById('sourceImage');
        const placeholderText = document.getElementById('placeholderText');
        const imagePathInput = document.getElementById('imagePathInput');

        if (imageInput) {
            imageInput.addEventListener('change', function (e) {
                const file = e.target.files[0];
                if (file) {
                    const formData = new FormData();
                    formData.append('temp_image', file);
                    
                    fetch('upload_temp.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            sourceImage.src = data.path;
                            imagePathInput.value = data.path;
                            placeholderText.style.display = 'none';
                            sourceImage.style.display = 'block';

                            if (cropper) cropper.destroy();
                            
                            cropper = new Cropper(sourceImage, {
                                viewMode: 1,
                                dragMode: 'crop',
                                autoCropArea: 0.8,
                                responsive: true,
                                crop(e) {
                                    document.getElementById('cropX').value = Math.round(e.detail.x);
                                    document.getElementById('cropY').value = Math.round(e.detail.y);
                                    document.getElementById('cropW').value = Math.round(e.detail.width);
                                    document.getElementById('cropH').value = Math.round(e.detail.height);
                                    document.getElementById('cropBtn').removeAttribute('disabled');
                                }
                            });
                        }
                    });
                }
            });
        }
    </script>
</body>
</html>