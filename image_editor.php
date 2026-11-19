<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/db.php';
$uid=vtRequireLogin();
$message='';
if ($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action_type']??'')==='advanced_image_crop') {
    vtVerifyCsrf();
    $imageId=filter_var($_POST['image_path'] ?? null,FILTER_VALIDATE_INT);
    $x=filter_var($_POST['crop_x'] ?? null,FILTER_VALIDATE_INT);
    $y=filter_var($_POST['crop_y'] ?? null,FILTER_VALIDATE_INT);
    $w=filter_var($_POST['crop_w'] ?? null,FILTER_VALIDATE_INT);
    $h=filter_var($_POST['crop_h'] ?? null,FILTER_VALIDATE_INT);
    $stmt=$pdo->prepare('SELECT filepath FROM images WHERE id=? AND user_id=?');
    $stmt->execute([$imageId ?: 0,$uid]);$source=$stmt->fetchColumn();
    if (!$source || $x===false || $y===false || $w===false || $h===false || $x<0 || $y<0 || $w<1 || $h<1 || $w>10000 || $h>10000 || str_contains($source,'..')) {
        $message='<div class="alert alert-danger">Ảnh hoặc vùng cắt không hợp lệ.</div>';
    } else {
        $real=realpath(__DIR__.'/'.$source);
        $ownerBase=realpath(__DIR__.'/uploads/user_'.$uid);
        if (!$real || !$ownerBase || !str_starts_with($real,$ownerBase.DIRECTORY_SEPARATOR)) {
            $message='<div class="alert alert-danger">Không được phép truy cập ảnh này.</div>';
        } else {
            $outDir=__DIR__.'/outputs/user_'.$uid;
            if (!is_dir($outDir)) mkdir($outDir,0775,true);
            $relative='outputs/user_'.$uid.'/cropped_'.bin2hex(random_bytes(16)).'.jpg';
            $absolute=__DIR__.'/'.$relative;
            $cmd='ffmpeg -nostdin -v error -y -i '.escapeshellarg($real).' -vf '.escapeshellarg("crop=$w:$h:$x:$y").' '.escapeshellarg($absolute).' 2>&1';
            $lines=[];$code=1;exec($cmd,$lines,$code);
            if ($code===0 && is_file($absolute) && filesize($absolute)>0) {
                $pdo->prepare('INSERT INTO image_outputs(user_id,source_image_id,filepath) VALUES (?,?,?)')->execute([$uid,$imageId,$relative]);
                $outId=(int)$pdo->lastInsertId();
                $message='<div class="alert alert-success">Đã cắt ảnh thành công! <a href="media.php?type=image_output&amp;id='.$outId.'&amp;download=1">Tải ảnh xuống</a></div>';
            } else $message='<div class="alert alert-danger">Cắt ảnh thất bại. Kiểm tra tọa độ và FFmpeg.</div>';
        }
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
        <div><span class="small text-white me-3"><?=htmlspecialchars((string)($_SESSION['vt_user_email']??''),ENT_QUOTES,'UTF-8')?></span><a href="index.php" class="btn btn-outline-light btn-sm rounded-pill px-3">Trang chủ</a></div>
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
                                    <input type="hidden" name="action_type" value="advanced_image_crop"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars(vtCsrfToken(),ENT_QUOTES,'UTF-8')?>">
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
                    formData.append('csrf_token', <?=json_encode(vtCsrfToken())?>);
                    
                    fetch('upload_temp.php', { method: 'POST', body: formData })
                    .then(res => res.json())
                    .then(data => {
                        if (data.success) {
                            sourceImage.src = data.path;
                            imagePathInput.value = data.id;
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