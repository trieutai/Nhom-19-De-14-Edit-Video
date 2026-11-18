<?php
// Image Studio: accept only actual images, not arbitrary executable uploads.
header('Content-Type: application/json; charset=utf-8');
function imageUploadFail($error, $code=400) {
    http_response_code($code);
    echo json_encode(['success'=>false,'error'=>$error],JSON_UNESCAPED_UNICODE);
    exit;
}
if ($_SERVER['REQUEST_METHOD']!=='POST' || !isset($_FILES['temp_image'])) imageUploadFail('Thiếu tệp ảnh');
$f=$_FILES['temp_image'];
if ($f['error']!==UPLOAD_ERR_OK || $f['size']<1 || $f['size']>10485760 || !is_uploaded_file($f['tmp_name'])) imageUploadFail('Tệp quá lớn hoặc lỗi tải lên');
$info=@getimagesize($f['tmp_name']);
$types=[IMAGETYPE_JPEG=>'.jpg',IMAGETYPE_PNG=>'.png',IMAGETYPE_WEBP=>'.webp'];
if (!$info || !isset($types[$info[2]]) || $info[0]>10000 || $info[1]>10000) imageUploadFail('Chỉ nhận JPG, PNG, WEBP hợp lệ');
$dir=__DIR__.'/uploads';
if (!is_dir($dir) && !mkdir($dir,0775,true)) imageUploadFail('Không tạo được thư mục',500);
$filename='img_'.bin2hex(random_bytes(16)).$types[$info[2]];
$relative='uploads/'.$filename;
if (!move_uploaded_file($f['tmp_name'],__DIR__.'/'.$relative)) imageUploadFail('Không lưu được tệp',500);
echo json_encode(['success'=>true,'path'=>$relative],JSON_UNESCAPED_UNICODE);
