<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/db.php';
$next=(string)($_POST['next'] ?? $_GET['next'] ?? 'index.php');
if (!in_array($next,['index.php','video_editor.php','image_editor.php'],true)) $next='index.php';
if (vtCurrentUserId()) { header('Location: '.$next); exit; }
$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    vtVerifyCsrf();
    $mode=(string)($_POST['mode'] ?? 'login');
    $email=strtolower(trim((string)($_POST['email'] ?? '')));
    $password=(string)($_POST['password'] ?? '');
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>190 || strlen($password)<8 || strlen($password)>200) {
        $error='Email hoặc mật khẩu không hợp lệ (mật khẩu ít nhất 8 ký tự).';
    } elseif ($mode==='register') {
        try {
            $pdo->prepare('INSERT INTO users(email,password_hash) VALUES (?,?)')->execute([$email,password_hash($password,PASSWORD_DEFAULT)]);
            session_regenerate_id(true);
            $_SESSION['vt_user_id']=(int)$pdo->lastInsertId();
            $_SESSION['vt_user_email']=$email;
            header('Location: '.$next); exit;
        } catch (PDOException $e) { $error='Email đã được đăng ký hoặc không thể tạo tài khoản.'; }
    } else {
        $stmt=$pdo->prepare('SELECT id,email,password_hash FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]); $user=$stmt->fetch();
        if (!$user || !password_verify($password,$user['password_hash'])) $error='Email hoặc mật khẩu không đúng.';
        else {
            session_regenerate_id(true);
            $_SESSION['vt_user_id']=(int)$user['id'];
            $_SESSION['vt_user_email']=$user['email'];
            header('Location: '.$next); exit;
        }
    }
}
?>
<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Tài khoản - DukTay Edit</title>
<style>*{box-sizing:border-box}body{font-family:Segoe UI,Arial,sans-serif;background:#101318;color:#f2f4fa;display:grid;place-items:center;min-height:100vh;margin:0}.box{width:min(430px,94vw);background:#1b2029;border:1px solid #363d49;border-radius:18px;padding:32px}h1{margin:0 0 12px}p{color:#aeb7c6;line-height:1.6}label{display:block;margin:18px 0 7px}input{width:100%;padding:12px;background:#11151b;border:1px solid #505866;color:#fff;border-radius:8px}button{cursor:pointer;border:0;border-radius:8px;background:#ffdb47;padding:12px 18px;font-weight:700;color:#111;margin-top:22px;width:100%}a{color:#ffe06f}.error{padding:10px;background:#47262c;color:#ffd5dc;border-radius:8px}small{color:#aeb7c6}</style></head>
<body><main class="box"><a href="index.php">← Trang chủ</a><h1>DukTay Edit</h1><p>Đăng nhập để quản lý video và kết quả của riêng bạn.</p>
<?php if($error):?><div class="error"><?=htmlspecialchars($error,ENT_QUOTES,'UTF-8')?></div><?php endif;?>
<form method="post"><input type="hidden" name="csrf_token" value="<?=htmlspecialchars(vtCsrfToken(),ENT_QUOTES,'UTF-8')?>"><input type="hidden" name="next" value="<?=htmlspecialchars($next,ENT_QUOTES,'UTF-8')?>"><label>Email</label><input type="email" name="email" required maxlength="190" autocomplete="username"><label>Mật khẩu</label><input type="password" name="password" required minlength="8" autocomplete="current-password"><button name="mode" value="login">Đăng nhập</button><button name="mode" value="register" style="margin-top:10px;background:#2b3442;color:#fff;border:1px solid #647088">Tạo tài khoản mới</button></form><p><small>Mỗi tài khoản chỉ được truy cập video và file của chính mình.</small></p></main></body></html>
