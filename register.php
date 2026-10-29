<?php
require_once 'db.php';
session_start();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];

    if (empty($username) || empty($email) || empty($password) || empty($confirm_password)) {
        $error = "Vui lòng điền đầy đủ tất cả thông tin!";
    } elseif ($password !== $confirm_password) {
        $error = "Mật khẩu nhập lại không khớp!";
    } else {
        // Kiểm tra xem tên đăng nhập đã bị trùng chưa
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->rowCount() > 0) {
            $error = "Tên đăng nhập này đã tồn tại, vui lòng chọn tên khác!";
        } else {
            // Mã hóa mật khẩu bảo mật
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Lưu vào database
            $stmt = $pdo->prepare("INSERT INTO users (username, email, password) VALUES (?, ?, ?)");
            if ($stmt->execute([$username, $email, $hashed_password])) {
                // Tự động tạo thư mục riêng cho tài khoản
                $upload_dir = "uploads/" . $username;
                $output_dir = "outputs/" . $username;
                if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
                if (!is_dir($output_dir)) { mkdir($output_dir, 0777, true); }

                $success = "Đăng ký thành công! Bạn có thể <a href='login.php'>đăng nhập ngay</a>.";
            } else {
                $error = "Đã có lỗi xảy ra, vui lòng thử lại.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng ký tài khoản</title>
    <style>
        body { font-family: Arial, sans-serif; background: #f4f6f9; display: flex; justify-content: center; align-items: center; height: 100vh; margin: 0; }
        .box { background: white; padding: 30px; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.1); width: 320px; }
        h2 { text-align: center; color: #333; margin-bottom: 20px; }
        input { width: 100%; padding: 10px; margin: 10px 0; border: 1px solid #ccc; border-radius: 4px; box-sizing: border-box; }
        button { width: 100%; padding: 10px; background: #28a745; color: white; border: none; border-radius: 4px; font-weight: bold; cursor: pointer; margin-top: 10px; }
        button:hover { background: #218838; }
        .error { color: #dc3545; font-size: 14px; text-align: center; margin-bottom: 10px; }
        .success { color: #28a745; font-size: 14px; text-align: center; margin-bottom: 10px; }
        .link { text-align: center; margin-top: 15px; font-size: 14px; }
    </style>
</head>
<body>
<div class="box">
    <h2>Đăng Ký</h2>
    <?php if(!empty($error)) echo "<p class='error'>$error</p>"; ?>
    <?php if(!empty($success)) echo "<p class='success'>$success</p>"; ?>
    <form method="POST">
        <input type="text" name="username" placeholder="Tên đăng nhập" required>
        <input type="email" name="email" placeholder="Email thực (Gmail)" required>
        <input type="password" name="password" placeholder="Mật khẩu" required>
        <input type="password" name="confirm_password" placeholder="Nhập lại mật khẩu" required>
        <button type="submit">Đăng Ký</button>
    </form>
    <div class="link">Đã có tài khoản? <a href="login.php">Đăng nhập</a></div>
</div>
</body>
</html>