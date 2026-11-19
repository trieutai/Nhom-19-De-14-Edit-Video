<?php
require_once __DIR__.'/auth.php';
require_once __DIR__.'/db.php';
$uid=vtRequireLogin();
$type=(string)($_GET['type'] ?? '');
$id=filter_var($_GET['id'] ?? null,FILTER_VALIDATE_INT);
if (!$id || $id<=0) { http_response_code(404); exit; }
$path=null; $download=false;
switch ($type) {
    case 'source': case 'source_thumb':
        $stmt=$pdo->prepare('SELECT filepath,thumbnail_path FROM videos WHERE id=? AND user_id=?');
        $stmt->execute([$id,$uid]); $r=$stmt->fetch();
        if ($r) $path=$type==='source'?$r['filepath']:$r['thumbnail_path'];
        break;
    case 'output': case 'output_thumb':
        $stmt=$pdo->prepare('SELECT o.output_path,o.thumbnail_path FROM outputs o JOIN jobs j ON o.job_id=j.id JOIN videos v ON j.video_id=v.id WHERE o.id=? AND j.user_id=? AND v.user_id=?');
        $stmt->execute([$id,$uid,$uid]); $r=$stmt->fetch();
        if ($r) { $path=$type==='output'?$r['output_path']:$r['thumbnail_path']; $download=$type==='output' && isset($_GET['download']); }
        break;
    case 'image': case 'image_output':
        $table=$type==='image'?'images':'image_outputs';
        $stmt=$pdo->prepare("SELECT filepath FROM `$table` WHERE id=? AND user_id=?");
        $stmt->execute([$id,$uid]); $path=$stmt->fetchColumn() ?: null;
        $download=$type==='image_output' && isset($_GET['download']);
        break;
}
if (!$path || str_contains($path,'..') || str_contains($path,'\\') || !preg_match('#^(uploads|outputs)/[a-zA-Z0-9_./-]+$#D',$path)) { http_response_code(404); exit; }
$real=realpath(__DIR__.'/'.$path);
$root=realpath(__DIR__.'/'.strtok($path,'/'));
if (!$real || !$root || !is_file($real) || strncmp($real,$root.DIRECTORY_SEPARATOR,strlen($root.DIRECTORY_SEPARATOR))!==0) {http_response_code(404); exit;}
$ext=strtolower(pathinfo($real,PATHINFO_EXTENSION));
$types=['mp4'=>'video/mp4','mov'=>'video/quicktime','avi'=>'video/x-msvideo','webm'=>'video/webm','mkv'=>'video/x-matroska','jpg'=>'image/jpeg','jpeg'=>'image/jpeg','png'=>'image/png'];
if (!isset($types[$ext])) { http_response_code(415); exit; }
header('Content-Type: '.$types[$ext]);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store');
header('Content-Disposition: '.($download?'attachment':'inline').'; filename="'.basename($real).'"');
$size=filesize($real);
if ($size===false) { http_response_code(404); exit; }
$start=0;$end=$size-1;
if (isset($_SERVER['HTTP_RANGE']) && preg_match('/^bytes=(\d*)-(\d*)$/',$_SERVER['HTTP_RANGE'],$m)) {
    if ($m[1]==='' && $m[2]==='') { http_response_code(416);exit; }
    if ($m[1]==='') { $suffix=(int)$m[2]; if ($suffix<=0) {http_response_code(416);exit;} $start=max(0,$size-$suffix); }
    else { $start=(int)$m[1]; if ($m[2]!=='') $end=min($end,(int)$m[2]); }
    if ($start>$end || $start>=$size) {header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
    http_response_code(206);header("Content-Range: bytes $start-$end/$size");
}
header('Accept-Ranges: bytes');header('Content-Length: '.($end-$start+1));
$fp=fopen($real,'rb');if (!$fp) exit;
fseek($fp,$start);$remaining=$end-$start+1;
while ($remaining>0 && !feof($fp) && !connection_aborted()) {
    $chunk=fread($fp,min(262144,$remaining));if ($chunk===false || $chunk==='') break;
    echo $chunk;$remaining-=strlen($chunk);flush();
}
fclose($fp);
