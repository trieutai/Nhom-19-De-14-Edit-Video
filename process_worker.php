<?php
// CLI worker. Start with: C:\xampp\php\php.exe C:\xampp\htdocs\video_tool\process_worker.php
// --once: process at most one queued job (for scheduled execution / demonstrations).
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__.'/db.php';
require_once __DIR__.'/VideoProcessor.php';
$once = in_array('--once', $argv, true);
$processor = new VideoProcessor();
$dir = __DIR__.'/outputs';
if (!is_dir($dir)) mkdir($dir,0775,true);
function recoverInterruptedJobs(PDO $pdo) {
    // A worker updates its heartbeat during encoding. Requeue only truly abandoned tasks.
    $pdo->beginTransaction();
    try {
        $st = $pdo->query("SELECT id, active_attempt_id FROM jobs WHERE status='processing' AND heartbeat_at < DATE_SUB(NOW(), INTERVAL 120 SECOND) FOR UPDATE");
        foreach ($st->fetchAll() as $j) {
            if ($j['active_attempt_id']) $pdo->prepare("UPDATE job_attempts SET status='failed',log_output='Worker heartbeat expired',finished_at=NOW() WHERE id=? AND status='processing'")->execute([$j['active_attempt_id']]);
            $pdo->prepare("UPDATE jobs SET status=IF(cancel_requested=1,'cancelled','pending'), active_attempt_id=NULL, heartbeat_at=NULL, error_message='Worker was interrupted' WHERE id=?")->execute([$j['id']]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
function claimJob(PDO $pdo) {
    $pdo->beginTransaction();
    try {
        $job = $pdo->query("SELECT j.id,j.video_id,j.preset_id,j.start_time,j.end_time,
               v.filepath AS video_path,v.duration AS source_duration,p.width,p.height,p.keep_audio
            FROM jobs j JOIN videos v ON v.id=j.video_id JOIN presets p ON p.id=j.preset_id
            WHERE j.status='pending' ORDER BY j.id ASC LIMIT 1 FOR UPDATE")->fetch();
        if (!$job) { $pdo->commit(); return null; }
        $count = $pdo->prepare('SELECT COALESCE(MAX(attempt_number),0)+1 FROM job_attempts WHERE job_id=?');
        $count->execute([$job['id']]);
        $attemptNumber = (int)$count->fetchColumn();
        $pdo->prepare("INSERT INTO job_attempts (job_id,attempt_number,status,log_output) VALUES (?,?,'processing','')")
            ->execute([$job['id'],$attemptNumber]);
        $attemptId=(int)$pdo->lastInsertId();
        $pdo->prepare("UPDATE jobs SET status='processing',active_attempt_id=?,cancel_requested=0,heartbeat_at=NOW(),error_message=NULL WHERE id=? AND status='pending'")
            ->execute([$attemptId,$job['id']]);
        $pdo->commit();
        $job['attempt_id']=$attemptId;
        $job['attempt_number']=$attemptNumber;
        return $job;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}
function processJob(PDO $pdo, VideoProcessor $processor, array $job, string $dir) {
    $id=(int)$job['id']; $attempt=(int)$job['attempt_id'];
    $name='job'.$id.'_attempt'.$attempt;
    $output=$dir.'/output_'.$name.'.mp4';
    $thumb=$dir.'/thumb_'.$name.'.jpg';
    $relativeOutput='outputs/'.basename($output);
    $relativeThumb='outputs/'.basename($thumb);
    $input=realpath(__DIR__.'/'.$job['video_path']);
    $uploads=realpath(__DIR__.'/uploads');
    $sourceOk=$input && $uploads && strncmp($input,$uploads.DIRECTORY_SEPARATOR,strlen($uploads.DIRECTORY_SEPARATOR))===0;
    $cancel=function() use($pdo,$id,$attempt) {
        $pdo->prepare("UPDATE jobs SET heartbeat_at=NOW() WHERE id=? AND active_attempt_id=? AND status='processing'")->execute([$id,$attempt]);
        $stmt=$pdo->prepare('SELECT status,cancel_requested,active_attempt_id FROM jobs WHERE id=?');
        $stmt->execute([$id]);
        $row=$stmt->fetch();
        return !$row || $row['status']!=='processing' || (int)$row['cancel_requested']===1 || (int)$row['active_attempt_id']!==$attempt;
    };
    try {
        if (!$sourceOk) $result=['success'=>false,'error'=>'Duong dan nguon khong an toan'];
        else $result=$processor->processVideo($input,$output,$thumb,(float)$job['start_time'],(float)$job['end_time'],(int)$job['width'],(int)$job['height'],(bool)$job['keep_audio'],$cancel);
    } catch (Throwable $e) { $result=['success'=>false,'error'=>'Loi xu ly: '.$e->getMessage()]; }
    $pdo->beginTransaction();
    try {
        $stmt=$pdo->prepare('SELECT status,cancel_requested,active_attempt_id FROM jobs WHERE id=? FOR UPDATE');
        $stmt->execute([$id]); $current=$stmt->fetch();
        $stale=!$current || (int)$current['active_attempt_id']!==$attempt || $current['status']!=='processing';
        $isCancelled=$stale || (bool)$current['cancel_requested'] || !empty($result['cancelled']);
        $attemptStatus=$isCancelled?'cancelled':(!empty($result['success'])?'success':'failed');
        $pdo->prepare('UPDATE job_attempts SET status=?,log_output=?,finished_at=NOW() WHERE id=?')
            ->execute([$attemptStatus,substr((string)($result['log'] ?? $result['error'] ?? ''),0,20000),$attempt]);
        if (!$stale) {
            if ($isCancelled) $pdo->prepare("UPDATE jobs SET status='cancelled',active_attempt_id=NULL,cancel_requested=0,error_message=NULL WHERE id=?")->execute([$id]);
            elseif (!empty($result['success'])) {
                $m=$result['metadata'];
                $pdo->prepare('INSERT INTO outputs (job_id,attempt_id,output_path,thumbnail_path,file_size,duration,width,height,has_audio) VALUES (?,?,?,?,?,?,?,?,?)')
                    ->execute([$id,$attempt,$relativeOutput,!empty($result['thumbnail'])?$relativeThumb:null,$m['size'],$m['duration'],$m['width'],$m['height'],(int)$m['has_audio']]);
                $pdo->prepare("UPDATE jobs SET status='completed',active_attempt_id=NULL,error_message=NULL WHERE id=?")->execute([$id]);
            } else {
                $pdo->prepare("UPDATE jobs SET status='error',active_attempt_id=NULL,error_message=? WHERE id=?")
                    ->execute([substr((string)($result['error'] ?? 'Unknown FFmpeg error'),0,1500),$id]);
            }
        }
        $pdo->commit();
        if ($isCancelled || empty($result['success'])) { @unlink($output); @unlink($thumb); }
        echo 'Job #'.$id.' attempt #'.$attempt.': '.$attemptStatus.PHP_EOL;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        @unlink($output); @unlink($thumb);
        throw $e;
    }
}
fwrite(STDOUT,"Video Tool worker started. Press Ctrl+C to stop.\n");
while (true) {
    try {
        recoverInterruptedJobs($pdo);
        $job=claimJob($pdo);
        if ($job) processJob($pdo,$processor,$job,$dir);
        else { if ($once) break; sleep(2); }
    } catch (Throwable $e) {
        fwrite(STDERR,'Worker error: '.$e->getMessage().PHP_EOL);
        if ($once) exit(1);
        sleep(3);
    }
    if ($once) break;
}
