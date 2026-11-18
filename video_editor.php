<?php
session_start();
require_once __DIR__.'/db.php';
require_once __DIR__.'/VideoProcessor.php';
if (empty($_SESSION['video_tool_csrf'])) $_SESSION['video_tool_csrf'] = bin2hex(random_bytes(24));
$csrf = $_SESSION['video_tool_csrf'];
function vtEscape($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
$processor = new VideoProcessor();
$message='';
// 500 MiB per file; PHP upload_max_filesize and post_max_size must also allow this.
const VT_MAX_UPLOAD = 524288000;
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!hash_equals($csrf,(string)($_POST['csrf_token'] ?? ''))) {
        http_response_code(403); exit('CSRF token khong hop le. Tai lai trang.');
    }
    $action = (string)($_POST['action_type'] ?? '');
    if ($action==='batch_upload') {
        $success=0; $failed=0;
        $items=$_FILES['video_files'] ?? null;
        if (is_array($items) && isset($items['name']) && is_array($items['name'])) {
            if (!is_dir(__DIR__.'/uploads')) mkdir(__DIR__.'/uploads',0775,true);
            foreach ($items['name'] as $i=>$original) {
                $tmp=$items['tmp_name'][$i] ?? '';
                $size=(int)($items['size'][$i] ?? 0);
                $ext=strtolower(pathinfo((string)$original,PATHINFO_EXTENSION));
                if (($items['error'][$i] ?? 1)!==UPLOAD_ERR_OK || !is_uploaded_file($tmp)
                    || $size<1 || $size>VT_MAX_UPLOAD || !in_array($ext,['mp4','mov','avi','mkv','webm'],true)) { $failed++; continue; }
                $safeFile=bin2hex(random_bytes(16)).'.'.$ext;
                $relative='uploads/'.$safeFile;
                $absolute=__DIR__.'/'.$relative;
                if (!move_uploaded_file($tmp,$absolute)) { $failed++; continue; }
                $meta=$processor->getMetadata($absolute);
                if (!$meta || $meta['duration']>14400 || $meta['width']>7680 || $meta['height']>4320) {
                    @unlink($absolute); $failed++; continue;
                }
                $thumbRel='uploads/thumb_'.$safeFile.'.jpg';
                $cmd='ffmpeg -nostdin -v error -y -ss 0.5 -i '.escapeshellarg($absolute)
                   .' -frames:v 1 '.escapeshellarg(__DIR__.'/'.$thumbRel).' 2>&1';
                $unused=[]; $ret=1; exec($cmd,$unused,$ret);
                if ($ret!==0 || !is_file(__DIR__.'/'.$thumbRel)) $thumbRel=null;
                $stmt=$pdo->prepare('INSERT INTO videos (filename,filepath,duration,width,height,has_audio,thumbnail_path,file_size) VALUES (?,?,?,?,?,?,?,?)');
                $stmt->execute([substr(basename((string)$original),0,240),$relative,$meta['duration'],$meta['width'],$meta['height'],(int)$meta['has_audio'],$thumbRel,$meta['size']]);
                $success++;
            }
        }
        $message='<div class="alert alert-info">Tải lên: '.$success.' thành công, '.$failed.' bị từ chối (giới hạn 500 MiB/tệp, 4 giờ/video).</div>';
    } elseif ($action==='create_job') {
        $videoId=filter_var($_POST['video_id'] ?? null,FILTER_VALIDATE_INT);
        $presetId=filter_var($_POST['preset_id'] ?? null,FILTER_VALIDATE_INT);
        $start=filter_var($_POST['start_time'] ?? null,FILTER_VALIDATE_FLOAT);
        $end=filter_var($_POST['end_time'] ?? null,FILTER_VALIDATE_FLOAT);
        $q=$pdo->prepare('SELECT duration FROM videos WHERE id=?'); $q->execute([$videoId ?: 0]); $video=$q->fetch();
        $q=$pdo->prepare('SELECT width,height FROM presets WHERE id=?'); $q->execute([$presetId ?: 0]); $preset=$q->fetch();
        if (!$video || !$preset || $start===false || $end===false
            || !VideoProcessor::validTrim($start,$end,(float)$video['duration'])
            || !VideoProcessor::validDimensions((int)$preset['width'],(int)$preset['height'])) {
            $message='<div class="alert alert-danger">Thời gian cắt, video hoặc preset không hợp lệ. Kết thúc phải nằm trong thời lượng video.</div>';
        } else {
            $pdo->prepare("INSERT INTO jobs (video_id,preset_id,start_time,end_time,status) VALUES (?,?,?,?,'pending')")
                ->execute([$videoId,$presetId,$start,$end]);
            $message='<div class="alert alert-success">Đã xếp công việc vào hàng đợi. Worker sẽ tự xử lý.</div>';
        }
    } elseif (in_array($action,['cancel','retry'],true)) {
        $id=filter_var($_POST['job_id'] ?? null,FILTER_VALIDATE_INT);
        if ($id) {
            if ($action==='cancel') {
                $pdo->prepare("UPDATE jobs SET status='cancelled' WHERE id=? AND status='pending'")->execute([$id]);
                $pdo->prepare("UPDATE jobs SET cancel_requested=1 WHERE id=? AND status='processing'")->execute([$id]);
            } else {
                $pdo->prepare("UPDATE jobs SET status='pending',active_attempt_id=NULL,cancel_requested=0,error_message=NULL WHERE id=? AND status IN ('error','cancelled')")
                    ->execute([$id]);
            }
        }
        header('Location: video_editor.php'); exit;
    }
}
$presets=$pdo->query('SELECT * FROM presets ORDER BY id')->fetchAll();
$videos=$pdo->query('SELECT * FROM videos ORDER BY id DESC')->fetchAll();
$jobs=$pdo->query("SELECT j.*,v.filename,v.filepath AS source_filepath,v.duration AS orig_duration,
    v.thumbnail_path AS source_thumb,p.name AS preset_name,p.width AS preset_width,p.height AS preset_height,
    p.keep_audio, o.output_path,o.thumbnail_path AS output_thumb,o.file_size,
    o.duration AS output_duration,o.width AS output_width,o.height AS output_height,o.has_audio AS output_audio
    FROM jobs j JOIN videos v ON v.id=j.video_id JOIN presets p ON p.id=j.preset_id
    LEFT JOIN outputs o ON o.attempt_id=(
      SELECT o2.attempt_id FROM outputs o2 WHERE o2.job_id=j.id ORDER BY o2.id DESC LIMIT 1
    )
    ORDER BY j.id DESC LIMIT 200")->fetchAll();
?>
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="UTF-8">
    <title>Video Studio - Trình Chỉnh Sửa Video Chuyên Sâu</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f4f6f9; font-family: 'Segoe UI', sans-serif; }
        .navbar { background: #212529; }
        .card { border: none; border-radius: 12px; box-shadow: 0 4px 15px rgba(0,0,0,0.05); }
        
        /* KHUNG XEM TRƯỚC VIDEO: CĂN GIỮA VÀ PHÓNG TO HƠN */
        .preview-stage {
            width: 100%;
            min-height: 520px;
            padding: 20px;
            border-radius: 16px;
            background: #111827;
            border: 1px solid #dfe3e8;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #previewPlayer {
            width: 100%;
            max-width: 1000px;
            max-height: 500px;
            border-radius: 12px;
            background: #000;
            display: block;
            margin: 0 auto;
            box-shadow: 0 8px 24px rgba(0,0,0,0.18);
        }

        #playerPlaceholder {
            width: 100%;
            max-width: 1000px;
            min-height: 420px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            border-radius: 12px;
            background: #f8f9fa;
        }


        /* Trình phát riêng: xem trước khi tua, chọn tốc độ và chất lượng. */
        .yt-player { width: 100%; max-width: 1000px; position: relative; background: #000; border-radius: 12px; overflow: hidden; color: #fff; }
        .yt-player video { width: 100%; height: auto; max-height: 500px; min-height: 280px; object-fit: contain; display: block; cursor: pointer; }
        .yt-controls { position: relative; padding: 16px 16px 8px; background: linear-gradient(transparent, rgba(0,0,0,.92) 28%); }
        .yt-progress-wrap { position: relative; height: 18px; display:flex; align-items: center; cursor: pointer; touch-action:none; }
        .yt-progress { width: 100%; height: 5px; background: rgba(255,255,255,.35); border-radius: 5px; position: relative; }
        .yt-buffer, .yt-played { position:absolute; height:100%; top:0; left:0; border-radius:5px; }
        .yt-buffer { background: rgba(255,255,255,.32); width:0; }
        .yt-played { background: #ff0033; width:0; }
        .yt-progress-wrap:hover .yt-progress { height: 7px; }
        .yt-thumb { position:absolute; left:0; top:50%; width:12px; height:12px; border-radius:50%; background:#ff0033; transform:translate(-50%,-50%); pointer-events:none; }
        .yt-preview-tooltip { position:absolute; bottom: 100%; width:170px; max-width:50vw; padding:5px; border:1px solid #555; border-radius:7px; background:#161616; display:none; z-index:5; text-align:center; transform:translateX(-50%); pointer-events:none; }
        .yt-preview-tooltip img { width:160px; max-width:100%; aspect-ratio:16/9; object-fit:contain; background:#000; display:block; margin:auto; }
        .yt-preview-tooltip span { font-size:12px; }
        .yt-bottom { display:flex; align-items:center; justify-content:space-between; gap:8px; min-height:36px; }
        .yt-left, .yt-right { display:flex; align-items:center; gap:9px; }
        .yt-btn { border:0; background:transparent; color:#fff; border-radius:5px; padding:5px 7px; font-size:16px; cursor:pointer; }
        .yt-btn:hover, .yt-btn:focus-visible { background:rgba(255,255,255,.2); }
        .yt-time { font-size:13px; font-variant-numeric: tabular-nums; white-space:nowrap; }
        .yt-volume { width:75px; accent-color:white; }
        .yt-settings { position:absolute; right:12px; bottom:57px; width:240px; background:rgba(22,22,22,.97); border-radius:9px; box-shadow:0 4px 20px #0009; padding:12px; z-index:8; font-size:13px; }
        .yt-settings label { display:block; margin-bottom:5px; }
        .yt-settings select { background:#242424; color:#fff; border:1px solid #777; width:100%; padding:7px; border-radius:4px; }
        .yt-settings .hint { color:#bcbcbc; font-size:11px; margin-top:8px; }
        .yt-quality-status { margin:6px 0 0; min-height:16px; font-size:11px; color:#ddd; }
        .yt-player:fullscreen { display:flex; flex-direction:column; justify-content:center; }
        .yt-player:fullscreen video { max-height:calc(100vh - 75px); }
        @media(max-width:650px) { .preview-stage { min-height: 260px; padding:8px; } .yt-player video { min-height:190px; } .yt-volume { width:45px; } .yt-time { font-size:11px; } .yt-left,.yt-right { gap:2px; } }

        .thumb-img { width: 60px; height: 40px; object-fit: cover; border-radius: 4px; border: 1px solid #ddd; transition: transform 0.2s; cursor: pointer; }
        .thumb-img:hover { transform: scale(1.1); border-color: #0d6efd; }

        /* GIAI DOAN 1 - WORKSPACE DARK, GIU NGUYEN CAC FORM PHP */
        :root { color-scheme: dark; --studio-bg:#0e1015; --studio-panel:#16191f; --studio-edge:#2b303a; --studio-txt:#e9edf5; --studio-muted:#9ba3b4; --studio-yellow:#ffdb47; }
        body {background:var(--studio-bg); color:var(--studio-txt); min-width:320px; }
        .studio-topbar {height:66px; padding:0 22px; background:#15171c; border-bottom:1px solid var(--studio-edge); display:flex; justify-content:space-between; gap:20px; flex-wrap:nowrap;}
        .studio-topbar .navbar-brand {display:flex; gap:9px; align-items:center; font-size:19px; margin:0; color:white; white-space:nowrap;}
        .studio-mark {width:33px;height:33px;display:grid;place-items:center;color:var(--studio-yellow);background:#303035;border-radius:9px;}
        .studio-brand-sub {font-size:10px;border:1px solid #3a3c40;padding:3px 7px;border-radius:5px;color:#b6b7c0;letter-spacing:1px;}
        .studio-top-center {max-width:42vw; overflow:hidden; text-overflow:ellipsis; white-space:nowrap; font-size:13px; color:#bbc2d0;}
        .studio-dot {color:#606673;margin:0 12px;} .studio-top-actions{display:flex;align-items:center;gap:18px;white-space:nowrap;}
        .studio-top-link{font-size:13px;text-decoration:none;color:#c6cbd7;} .studio-top-link:hover{color:white;}
        .studio-export-button{border:0;border-radius:10px;background:var(--studio-yellow);color:#1b1b1b;font-weight:700;padding:10px 19px;}
        .studio-export-button:hover{background:#ffe674;}
        .studio-shell{height:calc(100vh - 66px);min-height:590px; display:grid;grid-template-columns:74px 320px minmax(0,1fr);}
        .studio-rail{border-right:1px solid var(--studio-edge);display:flex;flex-direction:column;align-items:center;gap:8px;padding:17px 6px;background:#121419;}
        .studio-rail-item{border:0;background:transparent;color:#9ca3b2;width:62px;min-height:64px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:7px;border-radius:9px;font-size:11px;}
        .studio-rail-item i{font-size:20px;} .studio-rail-item:hover,.studio-rail-item.active{color:#fff;background:#252a35;}
        .studio-rail-item.active i{color:var(--studio-yellow);}
        .studio-sidebar{min-width:0;overflow-y:auto;background:var(--studio-panel);border-right:1px solid var(--studio-edge);padding:24px 18px;}
        .studio-panel{display:none;} .studio-panel.active{display:block;} .studio-panel-title{display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:24px;}
        .studio-panel-title h2{font-size:19px;margin:0;font-weight:750;} .studio-panel-title span{color:#7c8494;font-size:10px;letter-spacing:1.3px;}
        .studio-panel-divider{height:1px;background:#30343c;margin:23px 0;}
        .studio-field-title{font-size:13px;font-weight:650;margin-bottom:12px;color:#dfe3eb;}
        .studio-help {font-size:12px;color:#a7afbf;line-height:1.65;margin:9px 0 15px;}
        .studio-tool{width:100%;border:1px solid #343943;background:#20242b;color:#e3e7ef;border-radius:9px;padding:13px 12px;margin-bottom:10px;display:flex;align-items:center;gap:11px;text-align:left;font-size:12px;}
        .studio-tool i:first-child{color:#b4b8c5;width:18px;text-align:center;}.studio-tool i:last-child{margin-left:auto;color:#777d89;font-size:10px;}
        .studio-tool:hover{background:#2b303a;border-color:#656d79;}
        .studio-sidebar .card{padding:0!important;background:transparent!important;box-shadow:none!important;color:var(--studio-txt);}
        .studio-sidebar .card h5{display:none!important;}
        .studio-sidebar .form-label{color:#b9c1ce;font-size:12px!important;} .studio-sidebar .form-text{color:#9fa6b5;}
        .studio-sidebar .form-control,.studio-sidebar .form-select{background:#242832;border:1px solid #444b58;color:#f5f5f5;max-width:100%;font-size:13px;}
        .studio-sidebar .form-control:focus,.studio-sidebar .form-select:focus{border-color:#b9a64e;box-shadow:0 0 0 2px #ffdd4433;}
        .studio-sidebar .btn {border-radius:9px!important;font-size:12px;}
        .studio-sidebar #studioJobsSlot{overflow-x:auto;}
        .studio-sidebar #studioJobsSlot .table{color:#f0f2f6;min-width:650px;font-size:11px;}
        .studio-sidebar #studioJobsSlot td{color:#dce1e9;}
        .studio-main{min-width:0;display:flex;flex-direction:column;background:#0d0f14;}
        .studio-viewer{min-height:0;flex:1 1 64%;display:flex;flex-direction:column;padding:15px 22px 12px;}
        .studio-viewer-head,.studio-viewer-foot{display:flex;justify-content:space-between;gap:10px;align-items:center;font-size:12px;color:#9ca4b3;min-height:27px;}
        .studio-viewer-head span:first-child{font-size:13px;color:#e5e8ee;font-weight:650;}
        .studio-viewer-head i{color:#9ca6b7;margin-right:6px;}
        #studioStageSlot{min-height:0;flex:1;display:flex;align-items:center;justify-content:center;overflow:hidden;padding:12px 3px;}
        #studioStageSlot .preview-stage{min-height:0;width:100%;height:100%;background:transparent;border:0;padding:0;}
        #studioStageSlot .yt-player{max-width:none;width:min(100%,980px);max-height:100%;background:#050505;display:flex;flex-direction:column;justify-content:center;box-shadow:0 15px 45px #0005;border-radius:6px;}
        #studioStageSlot .yt-player[style*="display: none"]{display:none!important;}
        #studioStageSlot .yt-player video{width:100%;height:auto;min-height:0;max-height:calc(100vh - 370px);object-fit:contain;}
        #studioStageSlot #playerPlaceholder{max-width:700px;min-height:200px;background:#1a1d25!important;border:1px dashed #515664!important;color:#a7b0bf!important;}
        .studio-viewer-foot{border-top:1px solid #252a32;padding-top:9px;font-size:11px;}
        .studio-timeline{min-height:220px;max-height:32vh;flex:0 0 28%;display:flex;flex-direction:column;border-top:1px solid #2e323b;background:#14171d;}
        .studio-timeline-toolbar{display:flex;align-items:center;justify-content:space-between;gap:8px;border-bottom:1px solid #292d35;padding:12px 18px;}
        .studio-timeline-actions,.studio-timeline-zoom{display:flex;align-items:center;gap:11px;}
        .studio-timeline-actions strong{font-size:13px;margin-right:12px;}
        .studio-timeline-actions strong i{color:var(--studio-yellow);margin-right:5px;}
        .studio-timeline-actions button{background:#242832;border:1px solid #414651;border-radius:7px;color:#d6dce7;font-size:11px;padding:7px 11px;}
        .studio-timeline-actions button:hover{border-color:var(--studio-yellow);}
        .studio-timeline-zoom{font-size:11px;color:#adb5c3;}.studio-timeline-zoom input{width:90px;accent-color:var(--studio-yellow);}
        .studio-timeline-scroll{position:relative;overflow:auto;flex:1;min-height:120px;padding:18px 24px 12px;}
        .studio-timeline-track{min-width:100%;display:none;position:relative;}
        .studio-timeline-ruler{height:28px;display:flex;justify-content:space-between;color:#868e9c;font-size:10px;border-bottom:1px solid #353a44;}
        .studio-timeline-clip{height:88px;position:relative;border:2px solid var(--studio-yellow);border-radius:9px;background:#242a31;overflow:visible;margin-top:9px;cursor:crosshair;touch-action:none;}
        .studio-timeline-filmstrip{display:flex;width:100%;height:100%;overflow:hidden;pointer-events:none;border-radius:7px;}
        .studio-timeline-filmstrip img{width:12.5%;height:100%;object-fit:cover;flex:none;}
        .studio-timeline-dim{position:absolute;top:0;height:100%;background:#030508a6;pointer-events:none;}
        .studio-timeline-dim.left{left:0;}.studio-timeline-dim.right{right:0;}
        .studio-timeline-bound{position:absolute;top:-2px;height:calc(100% + 4px);width:12px;background:var(--studio-yellow);border:2px solid #ffe873;z-index:4;cursor:ew-resize;touch-action:none;}
        .studio-timeline-bound.left{left:0;border-radius:7px 0 0 7px;transform:translateX(-30%);}
        .studio-timeline-bound.right{right:0;border-radius:0 7px 7px 0;transform:translateX(30%);}
        .studio-timeline-playhead{position:absolute;z-index:5;top:-30px;bottom:-5px;border-left:2px solid #ff554e;pointer-events:none;left:0;}
        .studio-timeline-playhead::before{content:'';width:12px;height:13px;background:#ff554e;position:absolute;top:0;left:-7px;border-radius:3px 3px 6px 6px;}
        .studio-timeline-empty{display:flex;justify-content:center;align-items:center;flex-direction:column;gap:12px;color:#8f98aa;font-size:13px;min-height:116px;}
        .studio-timeline-empty i{font-size:24px;}
        .studio-timeline-footer{display:flex;justify-content:space-between;gap:10px;padding:8px 20px;color:#858e9f;font-size:11px;border-top:1px solid #292d35;}
        #legacyWorkspace[hidden]{display:none!important;}
        .studio-sidebar #studioExportSlot .form-text{line-height:1.6;}
        .studio-sidebar #studioExportSlot input[type=number]{padding:8px 6px;}
        @media(max-width:1100px){.studio-shell{grid-template-columns:65px 265px minmax(0,1fr);} .studio-rail-item{width:54px;}.studio-viewer{padding:10px;} .studio-top-center{display:none;}}
        @media(max-width:750px){.studio-shell{height:auto;min-height:100vh;grid-template-columns:58px minmax(0,1fr);}.studio-rail{grid-row:1 / span 2;}.studio-sidebar{grid-column:2;max-height:350px;border-bottom:1px solid var(--studio-edge);}.studio-main{grid-column:2;min-height:650px;}.studio-viewer{min-height:360px;}.studio-timeline{min-height:240px;}.studio-timeline-actions button{font-size:10px;padding:5px;}.studio-timeline-zoom label{display:none;}.studio-top-link{display:none;}}


        /* Canvas biên tập dạng 123apps: video tách khỏi thanh điều khiển. */
        .studio-viewer{padding:8px 0 0;flex:1 1 70%;}
        .studio-viewer-head{padding:0 22px 6px;min-height:26px;}
        .studio-viewer-foot{padding:5px 21px 7px;border-top:0;min-height:24px;background:#101217;}
        #studioStageSlot{padding:0;align-items:stretch;justify-content:stretch;min-height:0;}
        #studioStageSlot .preview-stage{height:100%;padding:0;background:#101115;min-height:0;display:flex;align-items:stretch;justify-content:stretch;}
        #studioStageSlot .yt-player{height:100%;max-height:none;max-width:none;width:100%;border:0;border-radius:0;box-shadow:none;background:transparent;display:flex;flex-direction:column;overflow:visible;justify-content:stretch;}
        #studioStageSlot .yt-player[style*="display: block"]{display:flex!important;}
        #studioStageSlot .yt-player[style*="display:none"],#studioStageSlot .yt-player[style*="display: none"]{display:none!important;}
        .studio-canvas-area{min-height:0;flex:1 1 auto;display:flex;justify-content:center;align-items:flex-start;padding:8px 18px 18px;overflow:hidden;background:#101115;}
        .studio-canvas{position:relative;flex:none;display:flex;align-items:center;justify-content:center;background:#08090b;border:0;box-shadow:none;max-width:100%;max-height:100%;min-width:220px;min-height:124px;}
        #studioStageSlot .studio-canvas video{display:block!important;width:100%;height:100%;min-height:0;max-height:none;max-width:none;object-fit:contain;border:0;border-radius:0;box-shadow:none;background:#050506;cursor:pointer;margin:0;}
        .studio-canvas-corner{display:none;}
        .studio-canvas-corner.top-left{left:-5px;top:-5px}.studio-canvas-corner.top-right{right:-5px;top:-5px}
        .studio-canvas-corner.bottom-left{left:-5px;bottom:-5px}.studio-canvas-corner.bottom-right{right:-5px;bottom:-5px}
        #studioStageSlot .yt-controls{flex:none;position:relative;background:#14161b;border-top:1px solid #292c33;padding:0 18px 1px;min-height:68px;display:flex;flex-direction:column;justify-content:center;}
        #studioStageSlot .yt-progress-wrap{height:14px;margin-top:0;order:2;}
        #studioStageSlot .yt-progress{height:3px;background:#424751;}
        #studioStageSlot .yt-progress-wrap:hover .yt-progress{height:5px;}
        #studioStageSlot .yt-played,#studioStageSlot .yt-thumb{background:#ffdb47;}
        #studioStageSlot .yt-bottom{order:1;position:relative;min-height:48px;display:grid;grid-template-columns:minmax(0,1fr) auto minmax(0,1fr);gap:12px;align-items:center;}
        #studioStageSlot .yt-left{justify-self:start;min-width:0;}
        #studioStageSlot .yt-right{justify-self:end;}
        #studioStageSlot .yt-transport{display:flex;align-items:center;justify-content:center;gap:15px;white-space:nowrap;}
        #studioStageSlot .yt-btn{color:#cbd0dc;font-size:14px;padding:8px 9px;transition:background .15s,color .15s;}
        #studioStageSlot .yt-btn:hover{background:#2a2e37;color:#fff;}
        #studioStageSlot .yt-main-play{font-size:20px;color:#fff;padding:7px 15px;}
        #studioStageSlot .yt-skip{font-size:14px;}
        #studioStageSlot .yt-current-time,#studioStageSlot .yt-duration{font-size:12px;color:#a5aab6;font-variant-numeric:tabular-nums;min-width:45px;text-align:center;}
        #studioStageSlot .yt-volume{width:88px;accent-color:#e4e5ea;}
        #studioStageSlot .yt-settings{right:16px;bottom:75px;background:#202127;border:1px solid #41444d;}
        #studioStageSlot .yt-preview-tooltip{bottom:21px;}
        #studioStageSlot .yt-player:fullscreen{background:#101115;}
        #studioStageSlot .yt-player:fullscreen .studio-canvas-area{flex:1;}
        .studio-viewer-hint{font-size:11px;opacity:.7;}
        @media(max-width:850px){.studio-canvas-area{padding:8px 10px 14px;}#studioStageSlot .yt-controls{padding:0 10px 1px;}#studioStageSlot .yt-transport{gap:5px;}#studioStageSlot .yt-volume{width:48px;}#studioStageSlot .yt-current-time,#studioStageSlot .yt-duration{min-width:34px;font-size:10px;}}
        @media(max-width:580px){#studioStageSlot .yt-left .yt-volume{display:none;}#studioStageSlot .yt-transport{gap:0;}#studioStageSlot .yt-btn{padding:6px;}#studioStageSlot .yt-bottom{gap:3px;}}

    </style>
</head>
<body>
    <nav class="navbar studio-topbar">
        <a class="navbar-brand" href="index.php"><span class="studio-mark"><i class="fa-solid fa-clapperboard"></i></span><strong>DukTay Edit</strong><span class="studio-brand-sub">STUDIO</span></a>
        <div class="studio-top-center"><i class="fa-solid fa-folder-open"></i> Dự án video <span class="studio-dot">/</span> <span id="studioProjectName">Chưa chọn video</span></div>
        <div class="studio-top-actions">
            <a href="index.php" class="studio-top-link"><i class="fa-solid fa-house"></i> Trang chủ</a>
            <button type="button" class="studio-export-button" id="goToExport"><i class="fa-solid fa-file-export"></i> Xuất video</button>
        </div>
    </nav>

    <div class="studio-shell" id="studioShell">
        <aside class="studio-rail" aria-label="Danh mục công cụ">
            <button class="studio-rail-item active" data-panel="media" type="button"><i class="fa-solid fa-film"></i><span>Video</span></button>
            <button class="studio-rail-item" data-panel="export" type="button"><i class="fa-solid fa-sliders"></i><span>Xuất file</span></button>
            <button class="studio-rail-item" data-panel="jobs" type="button"><i class="fa-solid fa-list-check"></i><span>Công việc</span></button>
        </aside>
        <aside class="studio-sidebar" aria-label="Bảng công cụ">
            <section class="studio-panel active" id="studioPanelMedia">
                <div class="studio-panel-title"><h2>Thư viện video</h2><span>NGUỒN</span></div>
                <div id="studioUploadSlot"></div>
                <div class="studio-panel-divider"></div>
                <h3 class="studio-field-title"><i class="fa-solid fa-folder"></i> Chọn video để chỉnh sửa</h3>
                <div id="studioSelectSlot"></div>
                <p class="studio-help">Chọn video để hiển thị nội dung trên timeline. Phiên bản này chỉnh sửa một video mỗi lần.</p>
                <div class="studio-panel-divider"></div>
                <h3 class="studio-field-title">Công cụ nhanh</h3>
                <button class="studio-tool" type="button" id="studioTrimAction"><i class="fa-solid fa-scissors"></i><span>Cắt video theo khoảng chọn</span><i class="fa-solid fa-chevron-right"></i></button>
                <button class="studio-tool" type="button" id="studioShowExport"><i class="fa-solid fa-expand"></i><span>Độ phân giải đầu ra</span><i class="fa-solid fa-chevron-right"></i></button>
            </section>
            <section class="studio-panel" id="studioPanelExport">
                <div class="studio-panel-title"><h2>Cấu hình xuất</h2><span>FFMPEG</span></div>
                <p class="studio-help">Đánh dấu khoảng cắt trên timeline và chọn chất lượng xuất. Chức năng xử lý và hàng đợi giữ nguyên PHP/FFmpeg.</p>
                <div id="studioExportSlot"></div>
            </section>
            <section class="studio-panel" id="studioPanelJobs">
                <div class="studio-panel-title"><h2>Hàng đợi xử lý</h2><span>JOBS</span></div>
                <p class="studio-help">Chạy, hủy, xem trạng thái và tải video đã xuất.</p>
                <div id="studioJobsSlot"></div>
            </section>
        </aside>
        <main class="studio-main">
            <section class="studio-viewer">
                <div class="studio-viewer-head"><span><i class="fa-solid fa-display"></i> Xem trước</span><span class="studio-viewer-hint">Chọn video tại bảng bên trái để bắt đầu</span></div>
                <div id="studioStageSlot"></div>
                <div class="studio-viewer-foot"><span id="studioFileInfo">Không có video đang mở</span><span>PHP + FFmpeg · Local</span></div>
            </section>
            <section class="studio-timeline" aria-label="Timeline video">
                <div class="studio-timeline-toolbar">
                    <div class="studio-timeline-actions">
                        <strong><i class="fa-solid fa-layer-group"></i> Timeline</strong>
                        <button id="timelineSetIn" type="button" title="Đặt điểm bắt đầu tại thời gian hiện tại"><i class="fa-solid fa-angles-left"></i> Đầu đoạn</button>
                        <button id="timelineSetOut" type="button" title="Đặt điểm kết thúc tại thời gian hiện tại"><i class="fa-solid fa-angles-right"></i> Cuối đoạn</button>
                    </div>
                    <div class="studio-timeline-zoom"><label for="timelineZoom">Thu phóng</label><i class="fa-solid fa-magnifying-glass-minus"></i><input id="timelineZoom" type="range" min="100" max="300" step="25" value="100"><i class="fa-solid fa-magnifying-glass-plus"></i></div>
                </div>
                <div class="studio-timeline-scroll" id="timelineViewport">
                    <div class="studio-timeline-track" id="timelineTrack">
                        <div class="studio-timeline-ruler" id="timelineRuler"></div>
                        <div class="studio-timeline-clip" id="timelineClip" role="group" aria-label="Video trên dòng thời gian">
                            <div class="studio-timeline-filmstrip" id="timelineFrames"></div>
                            <div class="studio-timeline-dim left" id="timelineLeftDim"></div>
                            <div class="studio-timeline-dim right" id="timelineRightDim"></div>
                            <div class="studio-timeline-bound left" id="timelineStartHandle" title="Kéo để đổi điểm bắt đầu"></div>
                            <div class="studio-timeline-bound right" id="timelineEndHandle" title="Kéo để đổi điểm kết thúc"></div>
                            <div class="studio-timeline-playhead" id="timelinePlayhead"></div>
                        </div>
                    </div>
                    <div class="studio-timeline-empty" id="timelineEmpty"><i class="fa-solid fa-circle-play"></i> Chọn video để tạo timeline</div>
                </div>
                <div class="studio-timeline-footer"><span>Vùng được viền vàng là đoạn video sẽ xuất</span><span id="timelineTrimInfo">0:00 – 0:00</span></div>
            </section>
        </main>
    </div>

    <div class="container my-4" id="legacyWorkspace" hidden>
        <h3 class="fw-bold mb-4 text-center">🎬 Trình Biên Tập & Xử Lý Video Chuyên Sâu</h3>
        <?= $message ?>

        <!-- KHỐI 1: XEM TRƯỚC & CHỌN MỐC CẮT (ĐÃ ĐƯA RA GIỮA & PHÓNG TO) -->
        <div class="row justify-content-center mb-4">
            <div class="col-lg-12">
                <div class="card p-4 bg-white">
                    <h5 class="text-primary fw-bold mb-3 text-center"><i class="fa-solid fa-play-circle me-2"></i>2. Xem Trước & Chọn Mốc Cắt Video</h5>
                    <?php if (empty($videos)): ?>
                        <p class="text-muted text-center py-3 small">Chưa có video nào trong hệ thống. Hãy tải lên ở mục bên dưới để bắt đầu.</p>
                    <?php else: ?>
                        <div class="mb-3 col-md-9 mx-auto">
                            <select class="form-select form-select-sm" id="videoSelector">
                                <option value="">-- Chọn tệp video để xem trước --</option>
                                <?php foreach ($videos as $v): ?>
                                    <option value="<?= $v['filepath'] ?>" data-id="<?= $v['id'] ?>" data-dur="<?= $v['duration'] ?>">
                                        <?= htmlspecialchars($v['filename']) ?> (<?= round($v['duration'], 1) ?>s - <?= $v['width'] ?>x<?= $v['height'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <div class="preview-stage">
                                <div class="yt-player" id="ytPlayer" style="display:none" tabindex="0" aria-label="Trình phát video">
                                    <div class="studio-canvas-area" id="studioCanvasArea">
                                        <div class="studio-canvas" id="studioCanvas" aria-label="Khung hình đang chỉnh sửa">
                                            <video id="previewPlayer" playsinline preload="metadata"></video>
                                            <span class="studio-canvas-corner top-left" aria-hidden="true"></span>
                                            <span class="studio-canvas-corner top-right" aria-hidden="true"></span>
                                            <span class="studio-canvas-corner bottom-left" aria-hidden="true"></span>
                                            <span class="studio-canvas-corner bottom-right" aria-hidden="true"></span>
                                        </div>
                                    </div>
                                    <div class="yt-controls">
                                        <div class="yt-progress-wrap" id="seekBar" role="slider" aria-label="Tua video" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0" tabindex="0">
                                            <div class="yt-preview-tooltip" id="seekTip"><img id="seekThumb" alt="Khung hình xem trước"><span id="seekTime">0:00</span></div>
                                            <div class="yt-progress"><div class="yt-buffer" id="bufferBar"></div><div class="yt-played" id="playedBar"></div><div class="yt-thumb" id="seekDot"></div></div>
                                        </div>
                                        <div class="yt-bottom">
                                            <div class="yt-left">
                                                <button type="button" class="yt-btn" id="muteToggle" aria-label="Bật hoặc tắt tiếng" title="Bật/tắt âm thanh"><i class="fa-solid fa-volume-high"></i></button>
                                                <input type="range" class="yt-volume" id="volumeBar" min="0" max="1" step="0.05" value="1" aria-label="Âm lượng">
                                            </div>
                                            <div class="yt-transport">
                                                <span class="yt-current-time" id="videoCurrent">00:00</span>
                                                <button type="button" class="yt-btn yt-skip" id="seekBackward" aria-label="Lùi 5 giây" title="Lùi 5 giây"><i class="fa-solid fa-backward-step"></i></button>
                                                <button type="button" class="yt-btn yt-main-play" id="playPause" aria-label="Phát hoặc tạm dừng"><i class="fa-solid fa-play"></i></button>
                                                <button type="button" class="yt-btn yt-skip" id="seekForward" aria-label="Tiến 5 giây" title="Tiến 5 giây"><i class="fa-solid fa-forward-step"></i></button>
                                                <span class="yt-duration" id="videoDuration">00:00</span>
                                                <span id="videoTime" class="visually-hidden">0:00 / 0:00</span>
                                            </div>
                                            <div class="yt-right">
                                                <button type="button" class="yt-btn" id="settingsToggle" aria-label="Cài đặt phát" title="Tốc độ và chất lượng"><i class="fa-solid fa-gear"></i></button>
                                                <button type="button" class="yt-btn" id="fullscreenToggle" aria-label="Toàn màn hình" title="Toàn màn hình"><i class="fa-solid fa-expand"></i></button>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="yt-settings" id="settingsPanel" hidden>
                                        <label for="speedSelect">Tốc độ phát</label>
                                        <select id="speedSelect"><option value="0.5">0,5x</option><option value="0.75">0,75x</option><option value="1" selected>Bình thường (1x)</option><option value="1.25">1,25x</option><option value="1.5">1,5x</option><option value="2">2x</option></select>
                                        <label for="qualitySelect" class="mt-2">Chất lượng xem trước</label>
                                        <select id="qualitySelect"><option value="original">Gốc</option><option value="480">480p</option><option value="720">720p</option><option value="1080">1080p</option></select>
                                        <div class="yt-quality-status" id="qualityStatus" role="status"></div>
                                        <div class="hint">Chọn chất lượng khác sẽ tạo bản phát bằng FFmpeg và lưu đệm. Chất lượng xuất file chọn riêng ở mục 3.</div>
                                    </div>
                                </div>
                                <div id="playerPlaceholder" class="text-center text-muted p-5 rounded border">
                                    <i class="fa-solid fa-film fa-3x mb-2 opacity-50"></i>
                                    <p class="m-0">Chọn video ở ô bên trên để phát, tua và chọn mốc thời gian.</p>
                                </div>
                            </div>
                        </div>
                        <div class="d-flex justify-content-center gap-3 col-md-6 mx-auto">
                            <button type="button" class="btn btn-outline-danger btn-sm px-4 rounded-pill fw-semibold" onclick="setStartTime()"><i class="fa-solid fa-flag me-1"></i> Lấy Bắt Đầu</button>
                            <button type="button" class="btn btn-outline-success btn-sm px-4 rounded-pill fw-semibold" onclick="setEndTime()"><i class="fa-solid fa-flag me-1"></i> Lấy Kết Thúc</button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- KHỐI 2: TẢI LÊN, CẤU HÌNH & HÀNG ĐỢI -->
        <div class="row g-4 justify-content-center">
            <div class="col-lg-5">
                <div class="card p-4 mb-4">
                    <h5 class="text-primary fw-bold mb-3"><i class="fa-solid fa-upload me-2"></i>1. Tải Lên Lô Video & Kiểm Tra</h5>
                    <form action="video_editor.php" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action_type" value="batch_upload">
                        <input type="hidden" name="csrf_token" value="<?= vtEscape($csrf) ?>">
                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Chọn nhiều tệp Video:</label>
                            <input class="form-control" type="file" name="video_files[]" accept="video/*,.mkv,.avi" multiple required>
                            <div class="form-text">Hệ thống dùng ffprobe đọc thông số thực và từ chối tệp lỗi.</div>
                        </div>
                        <button type="submit" class="btn btn-primary w-100 fw-bold py-2"><i class="fa-solid fa-cloud-arrow-up me-1"></i>Tải Lên Lô & Đọc Thông Số</button>
                    </form>
                </div>

                <div class="card p-4">
                    <h5 class="text-success fw-bold mb-3"><i class="fa-solid fa-scissors me-2"></i>3. Cấu Hình Đầu Ra & Gửi Công Việc</h5>
                    <form action="video_editor.php" method="POST">
                        <input type="hidden" name="action_type" value="create_job">
                        <input type="hidden" name="csrf_token" value="<?= vtEscape($csrf) ?>">
                        <input type="hidden" name="video_id" id="formVideoId">
                        
                        <div class="row">
                            <div class="col-6 mb-3">
                                <label class="form-label small fw-semibold">Bắt đầu (giây):</label>
                                <input type="number" step="0.1" class="form-control" name="start_time" id="startTimeInput" value="0" required>
                            </div>
                            <div class="col-6 mb-3">
                                <label class="form-label small fw-semibold">Kết thúc (giây):</label>
                                <input type="number" step="0.1" class="form-control" name="end_time" id="endTimeInput" value="10" required>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label small fw-semibold">Cấu hình Preset (Giữ tỷ lệ chuẩn):</label>
                            <select class="form-select" name="preset_id" required>
                                <?php foreach ($presets as $p): ?>
                                    <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (<?= $p['width'] ?>x<?= $p['height'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-text mb-3">480p / 720p / 1080p là độ phân giải xuất. Nâng từ video nguồn thấp lên 1080p chỉ tăng số điểm ảnh, không phục hồi được chi tiết gốc.</div>
                        <button type="submit" class="btn btn-success w-100 fw-bold py-2 shadow-sm" id="submitJobBtn" disabled>
                            <i class="fa-solid fa-play me-1"></i>Đưa Vào Hàng Đợi Công Việc
                        </button>
                    </form>
                </div>
            </div>

            <div class="col-lg-7">
                <div class="card p-4 h-100">
                    <h5 class="fw-bold mb-3"><i class="fa-solid fa-list-check me-2"></i>4. Hàng Đợi & Quản Lý Trạng Thái <button type="button" class="btn btn-sm btn-outline-info float-end" onclick="location.reload()">Cập nhật</button></h5>
                    <div class="table-responsive" style="max-height: 450px; overflow-y: auto;">
                        <table class="table table-hover align-middle small">
                            <thead class="table-dark">
                                <tr>
                                    <th>ID</th>
                                    <th>Thumbnail</th>
                                    <th>Tên Video</th>
                                    <th>Khoảng Cắt</th>
                                    <th>Trạng Thái</th>
                                    <th>Thao Tác</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($jobs)): ?>
                                    <tr><td colspan="6" class="text-center text-muted py-3">Chưa có công việc nào trong hàng đợi.</td></tr>
                                <?php else: ?>
                                    <?php foreach ($jobs as $j): 
                                        $displayThumb = '';
                                        $previewVideo = '';
                                        if (!empty($j['output_thumb']) && file_exists($j['output_thumb'])) {
                                            $displayThumb = $j['output_thumb'];
                                            $previewVideo = $j['output_path'];
                                        } elseif (!empty($j['source_thumb']) && file_exists($j['source_thumb'])) {
                                            $displayThumb = $j['source_thumb'];
                                            $previewVideo = $j['source_filepath'];
                                        }
                                    ?>
                                        <tr>
                                            <td class="fw-bold">#<?= $j['id'] ?></td>
                                            <td>
                                                <?php if ($displayThumb): ?>
                                                    <img src="<?= $displayThumb ?>" class="thumb-img" alt="Thumbnail" title="Bấm để xem trước video" onclick="openPreview(<?= vtEscape(json_encode($previewVideo,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)) ?>, <?= vtEscape(json_encode('Video: '.$j['filename'].' (Job #'.$j['id'].')',JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP)) ?>)">
                                                <?php else: ?>
                                                    <span class="text-muted small">--</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= vtEscape($j['filename']) ?><?php if ($j['status']==='completed' && $j['output_width']): ?><div class="small text-muted"><?= (int)$j['output_width'] ?>×<?= (int)$j['output_height'] ?> | <?= round((float)$j['output_duration'],2) ?>s | <?= $j['output_audio']?'Có âm thanh':'Không âm thanh' ?></div><?php endif; ?></td>
                                            <td><?= $j['start_time'] ?>s - <?= $j['end_time'] ?>s</td>
                                            <td>
                                                <?php if ($j['status'] === 'completed'): ?>
                                                    <span class="badge bg-success">Hoàn thành</span>
                                                <?php elseif ($j['status'] === 'processing'): ?>
                                                    <span class="badge bg-warning text-dark"><?= !empty($j['cancel_requested']) ? 'Đang hủy' : 'Đang xử lý' ?></span>
                                                <?php elseif ($j['status'] === 'error'): ?>
                                                    <span class="badge bg-danger" title="<?= htmlspecialchars($j['error_message']) ?>">Lỗi</span>
                                                <?php elseif ($j['status'] === 'cancelled'): ?>
                                                    <span class="badge bg-secondary">Đã hủy</span>
                                                <?php else: ?>
                                                    <span class="badge bg-info text-dark">Chờ xử lý</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($j['status'] === 'pending'): ?>
                                                    <span class="text-info">Đợi worker</span>
                                                    <form method="post" action="video_editor.php" class="d-inline"><input type="hidden" name="csrf_token" value="<?= vtEscape($csrf) ?>"><input type="hidden" name="action_type" value="cancel"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>"><button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1">Hủy</button></form>
                                                <?php elseif ($j['status'] === 'processing'): ?>
                                                    <form method="post" action="video_editor.php" class="d-inline"><input type="hidden" name="csrf_token" value="<?= vtEscape($csrf) ?>"><input type="hidden" name="action_type" value="cancel"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>"><button type="submit" class="btn btn-xs btn-outline-danger py-0 px-1">Hủy</button></form>
                                                <?php elseif ($j['status'] === 'error' || $j['status'] === 'cancelled'): ?>
                                                    <form method="post" action="video_editor.php" class="d-inline"><input type="hidden" name="csrf_token" value="<?= vtEscape($csrf) ?>"><input type="hidden" name="action_type" value="retry"><input type="hidden" name="job_id" value="<?= (int)$j['id'] ?>"><button type="submit" class="btn btn-xs btn-warning py-0 px-1">Thử lại</button></form>
                                                <?php elseif ($j['status'] === 'completed' && !empty($j['output_path']) && file_exists($j['output_path'])): ?>
                                                    <a href="<?= $j['output_path'] ?>" class="btn btn-xs btn-primary py-0 px-1" download>Tải về</a>
                                                <?php else: ?>
                                                    <span class="text-muted">--</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal xem trước video -->
    <div class="modal fade" id="previewModal" tabindex="-1">
      <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content bg-dark text-white">
          <div class="modal-header border-secondary">
            <h5 class="modal-title fs-6" id="previewModalTitle">Xem trước video</h5>
            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
          </div>
          <div class="modal-body text-center p-0">
            <video id="modalVideoPlayer" controls class="w-100" style="max-height: 450px; background: #000;"></video>
          </div>
        </div>
      </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const videoSelector = document.getElementById('videoSelector');
        const previewPlayer = document.getElementById('previewPlayer');
        const playerPlaceholder = document.getElementById('playerPlaceholder');
        const formVideoId = document.getElementById('formVideoId');
        const submitJobBtn = document.getElementById('submitJobBtn');

        const ytPlayer = document.getElementById('ytPlayer');
        const seekBar = document.getElementById('seekBar');
        const seekTip = document.getElementById('seekTip');
        const seekThumb = document.getElementById('seekThumb');
        const seekTime = document.getElementById('seekTime');
        const qualitySelect = document.getElementById('qualitySelect');
        const qualityStatus = document.getElementById('qualityStatus');
        const settingsPanel = document.getElementById('settingsPanel');
        const playPause = document.getElementById('playPause');
        const bufferBar = document.getElementById('bufferBar');
        const playedBar = document.getElementById('playedBar');
        const seekDot = document.getElementById('seekDot');
        let currentVideoId = null;
        let selectedSource = '';
        let qualityRequestId = 0;
        let thumbTimer = null;
        let draggingSeek = false;

        function timeLabel(value) {
            if (!Number.isFinite(value) || value < 0) return '0:00';
            const seconds = Math.floor(value);
            const hours = Math.floor(seconds / 3600);
            const minutes = Math.floor(seconds % 3600 / 60);
            const secs = String(seconds % 60).padStart(2, '0');
            return hours ? `${hours}:${String(minutes).padStart(2,'0')}:${secs}` : `${minutes}:${secs}`;
        }
        function updateControls() {
            if (!previewPlayer) return;
            const duration = Number.isFinite(previewPlayer.duration) ? previewPlayer.duration : 0;
            const progress = duration ? Math.min(100, (previewPlayer.currentTime / duration) * 100) : 0;
            playedBar.style.width = progress + '%';
            seekDot.style.left = progress + '%';
            document.getElementById('videoTime').textContent = timeLabel(previewPlayer.currentTime) + ' / ' + timeLabel(duration);
            document.getElementById('videoCurrent').textContent = timeLabel(previewPlayer.currentTime);
            document.getElementById('videoDuration').textContent = timeLabel(duration);
            seekBar.setAttribute('aria-valuemax', String(Math.round(duration)));
            seekBar.setAttribute('aria-valuenow', String(Math.round(previewPlayer.currentTime)));
            let buffered = 0;
            if (duration && previewPlayer.buffered.length) buffered = (previewPlayer.buffered.end(previewPlayer.buffered.length - 1) / duration) * 100;
            bufferBar.style.width = Math.min(100, buffered) + '%';
            playPause.innerHTML = previewPlayer.paused ? '<i class="fa-solid fa-play"></i>' : '<i class="fa-solid fa-pause"></i>';
            document.getElementById('muteToggle').innerHTML = previewPlayer.muted || !previewPlayer.volume ? '<i class="fa-solid fa-volume-xmark"></i>' : '<i class="fa-solid fa-volume-high"></i>';
        }
        function loadPlayback(url, resumeTime = 0, autoplay = false) {
            previewPlayer.pause();
            previewPlayer.src = url;
            previewPlayer.load();
            previewPlayer.addEventListener('loadedmetadata', function restore() {
                previewPlayer.removeEventListener('loadedmetadata', restore);
                if (Number.isFinite(resumeTime) && resumeTime > 0) previewPlayer.currentTime = Math.min(resumeTime, previewPlayer.duration || resumeTime);
                if (autoplay) previewPlayer.play().catch(() => {});
                updateControls();
            });
            updateControls();
        }
        if (videoSelector) {
            videoSelector.addEventListener('change', function() {
                const opt = this.options[this.selectedIndex];
                qualityRequestId++;
                qualitySelect.value = 'original';
                qualitySelect.disabled = false;
                qualityStatus.textContent = '';
                settingsPanel.hidden = true;
                if (this.value) {
                    selectedSource = this.value;
                    currentVideoId = opt.getAttribute('data-id');
                    ytPlayer.style.display = 'block';
                    playerPlaceholder.style.display = 'none';
                    loadPlayback(this.value);
                    formVideoId.value = currentVideoId;
                    submitJobBtn.removeAttribute('disabled');
                    document.getElementById('endTimeInput').value = parseFloat(opt.getAttribute('data-dur')).toFixed(1);
                } else {
                    selectedSource = '';
                    currentVideoId = null;
                    previewPlayer.pause();
                    previewPlayer.removeAttribute('src');
                    previewPlayer.load();
                    ytPlayer.style.display = 'none';
                    playerPlaceholder.style.display = 'flex';
                    formVideoId.value = '';
                    submitJobBtn.setAttribute('disabled', 'true');
                }
            });
            previewPlayer.addEventListener('timeupdate', updateControls);
            previewPlayer.addEventListener('progress', updateControls);
            previewPlayer.addEventListener('loadedmetadata', updateControls);
            previewPlayer.addEventListener('play', updateControls);
            previewPlayer.addEventListener('pause', updateControls);
            previewPlayer.addEventListener('volumechange', updateControls);
            previewPlayer.addEventListener('click', () => previewPlayer.paused ? previewPlayer.play() : previewPlayer.pause());
            playPause.addEventListener('click', () => previewPlayer.paused ? previewPlayer.play() : previewPlayer.pause());
            document.getElementById('seekBackward').addEventListener('click', () => { previewPlayer.currentTime = Math.max(0, previewPlayer.currentTime - 5); });
            document.getElementById('seekForward').addEventListener('click', () => { previewPlayer.currentTime = Math.min(previewPlayer.duration || 0, previewPlayer.currentTime + 5); });
            document.getElementById('muteToggle').addEventListener('click', () => { previewPlayer.muted = !previewPlayer.muted; });
            document.getElementById('volumeBar').addEventListener('input', function () { previewPlayer.volume = Number(this.value); previewPlayer.muted = previewPlayer.volume === 0; });
            document.getElementById('speedSelect').addEventListener('change', function() { previewPlayer.playbackRate = Number(this.value); });
            document.getElementById('settingsToggle').addEventListener('click', () => { settingsPanel.hidden = !settingsPanel.hidden; });
            document.getElementById('fullscreenToggle').addEventListener('click', () => {
                if (document.fullscreenElement) document.exitFullscreen();
                else if (ytPlayer.requestFullscreen) ytPlayer.requestFullscreen();
            });
            ytPlayer.addEventListener('keydown', function(e) {
                if (['SELECT','INPUT','BUTTON'].includes(document.activeElement.tagName)) return;
                if (e.key === ' ' || e.key.toLowerCase() === 'k') { e.preventDefault(); previewPlayer.paused ? previewPlayer.play() : previewPlayer.pause(); }
                if (e.key === 'ArrowRight') { e.preventDefault(); previewPlayer.currentTime = Math.min(previewPlayer.duration || 0, previewPlayer.currentTime + 5); }
                if (e.key === 'ArrowLeft') { e.preventDefault(); previewPlayer.currentTime = Math.max(0, previewPlayer.currentTime - 5); }
            });
            function positionFromEvent(e) {
                const rect = seekBar.getBoundingClientRect();
                return Math.min(1, Math.max(0, (e.clientX - rect.left) / rect.width));
            }
            function showSeekPreview(e) {
                if (!currentVideoId || !Number.isFinite(previewPlayer.duration)) return;
                const fraction = positionFromEvent(e);
                const at = fraction * previewPlayer.duration;
                seekTime.textContent = timeLabel(at);
                seekTip.style.left = Math.max(85, Math.min(seekBar.clientWidth - 85, fraction * seekBar.clientWidth)) + 'px';
                seekTip.style.display = 'block';
                clearTimeout(thumbTimer);
                const id = currentVideoId;
                thumbTimer = setTimeout(() => {
                    if (id === currentVideoId) seekThumb.src = 'preview_frame.php?video_id=' + encodeURIComponent(id) + '&t=' + encodeURIComponent(Math.floor(at / 2) * 2);
                }, 180);
            }
            seekBar.addEventListener('pointermove', e => { showSeekPreview(e); if (draggingSeek && previewPlayer.duration) previewPlayer.currentTime = positionFromEvent(e) * previewPlayer.duration; });
            seekBar.addEventListener('pointerleave', () => { if (!draggingSeek) seekTip.style.display = 'none'; clearTimeout(thumbTimer); });
            seekBar.addEventListener('pointerdown', e => { if (!previewPlayer.duration) return; draggingSeek = true; seekBar.setPointerCapture(e.pointerId); previewPlayer.currentTime = positionFromEvent(e) * previewPlayer.duration; showSeekPreview(e); });
            seekBar.addEventListener('pointerup', () => { draggingSeek = false; seekTip.style.display = 'none'; });
            seekBar.addEventListener('keydown', e => { if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); previewPlayer.currentTime = Math.max(0, Math.min(previewPlayer.duration || 0, previewPlayer.currentTime + (e.key === 'ArrowRight' ? 5 : -5))); } });
            qualitySelect.addEventListener('change', async function () {
                const level = this.value;
                const requestId = ++qualityRequestId;
                const id = currentVideoId;
                const position = previewPlayer.currentTime;
                const wasPlaying = !previewPlayer.paused;
                if (level === 'original') { qualityStatus.textContent = 'Đang phát file gốc'; loadPlayback(selectedSource, position, wasPlaying); return; }
                qualitySelect.disabled = true;
                qualityStatus.textContent = 'Đang tạo bản ' + level + 'p bằng FFmpeg, lần đầu có thể lâu...';
                try {
                    const data = new URLSearchParams({video_id: id, height: level});
                    const response = await fetch('video_quality.php', {method:'POST', body:data});
                    const json = await response.json();
                    if (requestId !== qualityRequestId || id !== currentVideoId) return;
                    if (!response.ok || !json.success) throw new Error(json.error || 'Không thể chuyển chất lượng');
                    qualityStatus.textContent = 'Đang phát ' + level + 'p (' + json.width + 'x' + json.height + ')';
                    loadPlayback(json.url, position, wasPlaying);
                } catch(e) {
                    if (requestId === qualityRequestId) { qualityStatus.textContent = 'Lỗi: ' + e.message; qualitySelect.value = 'original'; }
                } finally { if (requestId === qualityRequestId) qualitySelect.disabled = false; }
            });
        }

        function setStartTime() {
            if (previewPlayer) {
                document.getElementById('startTimeInput').value = previewPlayer.currentTime.toFixed(1);
            }
        }

        function setEndTime() {
            if (previewPlayer) {
                document.getElementById('endTimeInput').value = previewPlayer.currentTime.toFixed(1);
            }
        }

        function openPreview(videoUrl, title) {
            const player = document.getElementById('modalVideoPlayer');
            document.getElementById('previewModalTitle').innerText = title;
            player.src = videoUrl;
            player.load();
            const modal = new bootstrap.Modal(document.getElementById('previewModal'));
            modal.show();
        }

        document.getElementById('previewModal').addEventListener('hidden.bs.modal', function () {
            const player = document.getElementById('modalVideoPlayer');
            player.pause();
            player.src = '';
        });
    </script>

    <script>
    /* Giai doan 1: thay doi bo cuc DOM, khong thay doi business logic va backend */
    (() => {
        const legacy = document.getElementById('legacyWorkspace');
        const previewCard = legacy.querySelector('.row.justify-content-center.mb-4 .card');
        const previewStage = previewCard && previewCard.querySelector('.preview-stage');
        const select = document.getElementById('videoSelector');
        const uploadForm = legacy.querySelector('form input[value="batch_upload"]')?.closest('form');
        const exportForm = legacy.querySelector('form input[value="create_job"]')?.closest('form');
        const jobTable = legacy.querySelector('.table-responsive');
        if (uploadForm) document.getElementById('studioUploadSlot').appendChild(uploadForm);
        if (exportForm) document.getElementById('studioExportSlot').appendChild(exportForm);
        if (jobTable) document.getElementById('studioJobsSlot').appendChild(jobTable);
        if (select) document.getElementById('studioSelectSlot').appendChild(select);
        if (previewStage) document.getElementById('studioStageSlot').appendChild(previewStage);
        else {
            const empty = previewCard?.querySelector('p');
            if (empty) document.getElementById('studioStageSlot').appendChild(empty);
        }
        const message = legacy.querySelector('.alert');
        if (message) document.getElementById('studioPanelMedia').insertBefore(message, document.getElementById('studioUploadSlot'));

        function showPanel(which) {
            document.querySelectorAll('.studio-panel').forEach(panel => panel.classList.toggle('active', panel.id === 'studioPanel' + which.charAt(0).toUpperCase() + which.slice(1)));
            document.querySelectorAll('.studio-rail-item').forEach(btn => btn.classList.toggle('active', btn.dataset.panel === which));
        }
        document.querySelectorAll('.studio-rail-item').forEach(btn => btn.addEventListener('click', () => showPanel(btn.dataset.panel)));
        document.getElementById('goToExport').addEventListener('click', () => showPanel('export'));
        document.getElementById('studioShowExport').addEventListener('click', () => showPanel('export'));
        document.getElementById('studioTrimAction').addEventListener('click', () => showPanel('export'));
        if (uploadForm) {
            const fileInput = uploadForm.querySelector('input[type=file]');
            if (fileInput) fileInput.accept = 'video/mp4,video/webm,video/quicktime,video/x-matroska,video/x-msvideo';
        }
        const video = document.getElementById('previewPlayer');
        // Duy trì tỷ lệ gốc và tự co vừa vùng canvas khi cửa sổ thay đổi.
        const studioCanvas = document.getElementById('studioCanvas');
        const studioCanvasArea = document.getElementById('studioCanvasArea');
        function fitStudioCanvas() {
            if (!studioCanvas || !studioCanvasArea) return;
            const w = video?.videoWidth || 16, h = video?.videoHeight || 9;
            const areaW = Math.max(120, studioCanvasArea.clientWidth);
            const areaH = Math.max(90, studioCanvasArea.clientHeight);
            const usableW = Math.max(120, areaW - 24);
            const usableH = Math.max(90, areaH - 32);
            const scale = Math.min(usableW / w, usableH / h);
            const width = Math.max(1, Math.floor(w * scale));
            const height = Math.max(1, Math.floor(h * scale));
            studioCanvas.style.width = width + 'px';
            studioCanvas.style.height = height + 'px';
        }
        if (video) video.addEventListener('loadedmetadata', fitStudioCanvas);
        if (typeof ResizeObserver !== 'undefined' && studioCanvasArea) new ResizeObserver(fitStudioCanvas).observe(studioCanvasArea);
        window.addEventListener('resize', fitStudioCanvas);
        requestAnimationFrame(fitStudioCanvas);

        const clip = document.getElementById('timelineClip');
        const track = document.getElementById('timelineTrack');
        const frames = document.getElementById('timelineFrames');
        const viewport = document.getElementById('timelineViewport');
        const empty = document.getElementById('timelineEmpty');
        const ruler = document.getElementById('timelineRuler');
        const playhead = document.getElementById('timelinePlayhead');
        const inInput = document.getElementById('startTimeInput');
        const outInput = document.getElementById('endTimeInput');
        const inHandle = document.getElementById('timelineStartHandle');
        const outHandle = document.getElementById('timelineEndHandle');
        const leftDim = document.getElementById('timelineLeftDim');
        const rightDim = document.getElementById('timelineRightDim');
        const trimInfo = document.getElementById('timelineTrimInfo');
        const zoom = document.getElementById('timelineZoom');
        let dragMode = null;
        function duration() {
            if (video && Number.isFinite(video.duration) && video.duration > 0) return video.duration;
            if (select) return Number(select.selectedOptions[0]?.dataset.dur) || 0;
            return 0;
        }
        function clamp(value,min,max) {return Math.min(max,Math.max(min,value));}
        function fmt(t){t=Math.max(0,Number(t)||0);return Math.floor(t/60)+':'+String(Math.floor(t%60)).padStart(2,'0');}
        function updateTrim() {
            const d=duration(); if (!d || !inInput || !outInput) return;
            let start=clamp(Number(inInput.value)||0,0,d), end=clamp(Number(outInput.value)||0,0,d);
            if (end<start) end=start;
            const a=start/d*100,b=end/d*100;
            inHandle.style.left=a+'%';outHandle.style.left=b+'%';
            leftDim.style.width=a+'%';rightDim.style.left=b+'%';rightDim.style.width=(100-b)+'%';
            trimInfo.textContent=fmt(start)+' – '+fmt(end)+' ('+(end-start).toFixed(1)+'s)';
        }
        function updatePlayhead() { if (video && duration()) playhead.style.left=clamp(video.currentTime/duration()*100,0,100)+'%'; }
        function showFilmstrip() {
            const d=duration(), id=select?.selectedOptions[0]?.dataset.id;
            if (!d || !id) {track.style.display='none';empty.style.display='flex';frames.replaceChildren();return;}
            empty.style.display='none';track.style.display='block';frames.replaceChildren();ruler.replaceChildren();
            const label=document.getElementById('studioProjectName');
            label.textContent = select.selectedOptions[0].textContent.trim().split(' (')[0];
            document.getElementById('studioFileInfo').textContent = select.selectedOptions[0].textContent.trim();
            for(let i=0;i<8;i++) {
                const img=document.createElement('img');
                img.alt='Khung hình '+(i+1);
                img.loading='lazy';
                img.src='preview_frame.php?video_id='+encodeURIComponent(id)+'&t='+encodeURIComponent(Math.min(Math.max(0,d-0.2), d*(i+0.5)/8).toFixed(1));
                frames.appendChild(img);
            }
            for(let i=0;i<=8;i++) {
                const mark=document.createElement('span');mark.textContent=fmt(d*i/8);ruler.appendChild(mark);
            }
            zoom.dispatchEvent(new Event('input'));
            updateTrim();updatePlayhead();
        }
        if (select) select.addEventListener('change', () => setTimeout(showFilmstrip,100));
        if (video) {
            video.addEventListener('loadedmetadata', () => {if(select?.value) showFilmstrip();});
            video.addEventListener('timeupdate', updatePlayhead);
            video.addEventListener('seeked',updatePlayhead);
        }
        if(inInput) inInput.addEventListener('input', updateTrim);
        if(outInput) outInput.addEventListener('input', updateTrim);
        document.getElementById('timelineSetIn').addEventListener('click',()=>{if(video && duration()) {inInput.value=Math.min(video.currentTime,Number(outInput.value)||duration()).toFixed(1);updateTrim();}});
        document.getElementById('timelineSetOut').addEventListener('click',()=>{if(video && duration()) {outInput.value=Math.max(video.currentTime,Number(inInput.value)||0).toFixed(1);updateTrim();}});
        if(zoom) zoom.addEventListener('input',()=>{track.style.width=zoom.value+'%';});
        function proportion(e) {const r=clip.getBoundingClientRect();return clamp((e.clientX-r.left)/r.width,0,1);}
        if(clip) {
            clip.addEventListener('pointerdown',(e)=>{
                if(!duration())return;
                dragMode=e.target===inHandle?'start':e.target===outHandle?'end':'seek';
                clip.setPointerCapture(e.pointerId);
                applyPointer(e);
            });
            clip.addEventListener('pointermove',(e)=>{if(dragMode)applyPointer(e);});
            clip.addEventListener('pointerup',()=>{dragMode=null;});
            clip.addEventListener('pointercancel',()=>{dragMode=null;});
        }
        function applyPointer(e) {
            const t=proportion(e)*duration();
            if(dragMode==='start') inInput.value=Math.min(t,Number(outInput.value)||duration()).toFixed(1);
            if(dragMode==='end') outInput.value=Math.max(t,Number(inInput.value)||0).toFixed(1);
            if(dragMode==='seek' && video) video.currentTime=t;
            updateTrim();updatePlayhead();
        }
        // The original player JS owns playback and quality switching. This code only handles layout/timeline.
        if(select?.value) showFilmstrip();
    })();
    </script>
</body>
</html>