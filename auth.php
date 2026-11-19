<?php
// Session and access control shared by all web endpoints.
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ini_set('session.cookie_secure', '1');
    session_start();
}
function vtCurrentUserId(): int { return (int)($_SESSION['vt_user_id'] ?? 0); }
function vtRequireLogin(bool $json=false): int {
    $id=vtCurrentUserId();
    if ($id>0) return $id;
    if ($json) { http_response_code(401); header('Content-Type: application/json; charset=utf-8'); echo json_encode(['success'=>false,'error'=>'Vui lòng đăng nhập'],JSON_UNESCAPED_UNICODE); exit; }
    header('Location: login.php?next='.rawurlencode(basename(parse_url($_SERVER['REQUEST_URI'] ?? 'video_editor.php',PHP_URL_PATH)))); exit;
}
function vtCsrfToken(): string {
    if (empty($_SESSION['video_tool_csrf'])) $_SESSION['video_tool_csrf']=bin2hex(random_bytes(24));
    return (string)$_SESSION['video_tool_csrf'];
}
function vtVerifyCsrf(): void {
    if (!hash_equals(vtCsrfToken(),(string)($_POST['csrf_token'] ?? ''))) { http_response_code(403); exit('CSRF token không hợp lệ'); }
}
function vtSafeDeletePath(?string $relative): bool {
    if (!$relative || strpos($relative, "\0")!==false || str_contains($relative,'..') || str_contains($relative,'\\')) return false;
    if (!preg_match('#^(uploads|outputs)/[a-zA-Z0-9_./-]+$#D',$relative)) return false;
    $base=realpath(__DIR__);
    $target=realpath(__DIR__.DIRECTORY_SEPARATOR.$relative);
    if (!$base || !$target || !is_file($target)) return false;
    $uploads=realpath(__DIR__.'/uploads'); $outputs=realpath(__DIR__.'/outputs');
    $safe=false;
    foreach ([$uploads,$outputs] as $root) if ($root && strncmp($target,$root.DIRECTORY_SEPARATOR,strlen($root.DIRECTORY_SEPARATOR))===0) $safe=true;
    return $safe && @unlink($target);
}
