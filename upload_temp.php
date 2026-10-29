<?php
header('Content-Type: application/json');
$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) { mkdir($uploadDir, 0777, true); }

if (isset($_FILES['temp_image'])) {
    $filename = time() . '_' . basename($_FILES['temp_image']['name']);
    $targetPath = $uploadDir . $filename;
    if (move_uploaded_file($_FILES['temp_image']['tmp_name'], $targetPath)) {
        echo json_encode(['success' => true, 'path' => $targetPath]);
        exit;
    }
}
echo json_encode(['success' => false]);
?>