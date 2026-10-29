<?php
require_once 'db.php';
session_start();

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $login_input = trim($_POST['login_input']);
    $password = $_POST['password'];

    if (empty($login_input) || empty($password)) {
        $error = "Vui lòng nhập đầy đủ thông tin!";
    } else {
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$login_input, $login_input]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            
            if (!is_dir("uploads/" . $user['username'])) { mkdir("uploads/" . $user['username'], 0777, true); }
            if (!is_dir("outputs/" . $user['username'])) { mkdir("outputs/" . $user['username'], 0777, true); }

            header("Location: index.php");
            exit();
        } else {
            $error = "Tên đăng nhập, email hoặc mật khẩu không chính xác!";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Đăng nhập - DukTay Edit</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #0b0f19;
            color: #f3f4f6;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
        }
        .auth-card {
            background-color: #161e2e;
            border: 1px solid #2d3748;
            border-radius: 12px;
            padding: 40px;
            width: 100%;
            max-width: 420px;
            box-shadow: 0 10px 25px -5px rgba(0, 0, 0, 0.4);
        }
        .form-label {
            color: #cbd5e1 !important; /* Đổi màu nhãn sáng hơn, dễ đọc */
            font-weight: 500;
        }
        .form-control {
            background-color: #0b0f19 !important;
            border: 1px solid #4a5568 !important;
            color: #fff !important;
            border-radius: 6px;
            padding: 11px 14px;
            font-size: 14px;
        }
        .form-control:focus {
            background-color: #0b0f19 !important;
            border-color: #3b82f6 !important;
            color: #fff !important;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25) !important;
        }
        .form-control::placeholder {
            color: #718096 !important;
        }
        .btn-primary {
            background-color: #2563eb;
            border-color: #2563eb;
            font-weight: 600;
            padding: 11px;
            border-radius: 6px;
            transition: background 0.2s;
        }
        .btn-primary:hover {
            background-color: #1d4ed8;
            border-color: #1d4ed8;
        }
        .brand-logo {
            font-size: 26px;
            font-weight: 600;
            color: #fff;
            text-align: center;
            margin-bottom: 8px;
        }
        .subtitle {
            color: #94a3b8;
            text-align: center;
            font-size: 14px;
            margin-bottom: 28px;
        }
    </style>
</head>
<body>

<div class="auth-card">
    <div class="brand-logo">
        <i class="fa-solid fa-clapperboard text-primary me-2"></i>VideoStudio
    </div>
    
    <div class="subtitle">Đăng nhập vào không gian làm việc của bạn</div>

    <?php if(!empty($error)): ?>
        <div class="alert alert-danger border-0 bg-danger bg-opacity-25 text-danger small py-2 mb-3 text-center">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <form method="POST">
        <div class="mb-3">
            <label class="form-label small">Tên đăng nhập hoặc Email</label>
            <input type="text" name="login_input" class="form-control" placeholder="Nhập username hoặc email" required autocomplete="username">
        </div>
        <div class="mb-4">
            <label class="form-label small">Mật khẩu</label>
            <input type="password" name="password" class="form-control" placeholder="••••••••" required autocomplete="current-password">
        </div>
        <button type="submit" class="btn btn-primary w-100 mb-3 shadow-sm">Đăng Nhập</button>
    </form>

    <div class="text-center small" style="color: #94a3b8;">
        Chưa có tài khoản? <a href="register.php" class="text-primary text-decoration-none fw-semibold">Đăng ký ngay</a>
    </div>
</div>

</body>
</html>