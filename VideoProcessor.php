<?php
class VideoProcessor {
    private $ffmpeg;
    private $ffprobe;
    public function __construct($ffmpeg = 'ffmpeg', $ffprobe = 'ffprobe') {
        $this->ffmpeg = $ffmpeg;
        $this->ffprobe = $ffprobe;
    }
    public function getMetadata($path) {
        if (!is_file($path) || filesize($path) <= 0) return null;
        $command = escapeshellcmd($this->ffprobe) . ' -v error -show_format -show_streams -of json ' . escapeshellarg($path) . ' 2>&1';
        $text = shell_exec($command);
        $data = json_decode((string)$text, true);
        if (!is_array($data) || empty($data['streams'])) return null;
        $width = $height = 0;
        $hasAudio = false;
        $streamDuration = 0;
        foreach ($data['streams'] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video' && $width === 0) {
                $width = (int)($stream['width'] ?? 0);
                $height = (int)($stream['height'] ?? 0);
                $streamDuration = (float)($stream['duration'] ?? 0);
            }
            if (($stream['codec_type'] ?? '') === 'audio') $hasAudio = true;
        }
        $duration = (float)($data['format']['duration'] ?? $streamDuration);
        if (!is_finite($duration) || $duration <= 0 || $width <= 0 || $height <= 0) return null;
        return ['duration'=>$duration,'width'=>$width,'height'=>$height,'has_audio'=>$hasAudio,'size'=>filesize($path)];
    }
    public static function validTrim($start,$end,$duration) {
        return is_numeric($start) && is_numeric($end) && is_finite((float)$start) && is_finite((float)$end)
          && (float)$start >= 0 && (float)$end > (float)$start
          && (float)$end <= (float)$duration + 0.05 && (float)$end - (float)$start >= 0.1;
    }
    public static function validDimensions($w,$h) {
        return $w >= 144 && $h >= 144 && $w <= 3840 && $h <= 3840 && $w % 2 === 0 && $h % 2 === 0 && $w*$h <= 8294400;
    }
    public function processVideo($input,$output,$thumbnail,$start,$end,$width,$height,$keepAudio=true,$shouldCancel=null) {
        $source = $this->getMetadata($input);
        if (!$source) return ['success'=>false,'error'=>'Video nguon khong doc duoc'];
        if (!self::validTrim($start,$end,$source['duration']) || !self::validDimensions((int)$width,(int)$height)) {
            return ['success'=>false,'error'=>'Tham so cat hoac kich thuoc khong hop le'];
        }
        $requested = (float)$end - (float)$start;
        // Scale to fit, pad instead of stretch; format yuv420p for broadly compatible output.
        $filter = sprintf('scale=%d:%d:force_original_aspect_ratio=decrease:force_divisible_by=2,pad=%d:%d:(ow-iw)/2:(oh-ih)/2,setsar=1', $width,$height,$width,$height);
        $args = [$this->ffmpeg,'-nostdin','-hide_banner','-loglevel','error','-y',
            '-ss',sprintf('%.3f',$start),'-i',$input,'-t',sprintf('%.3f',$requested),
            '-vf',$filter,'-c:v','libx264','-preset','veryfast','-crf','23','-pix_fmt','yuv420p'];
        if ($keepAudio && $source['has_audio']) array_push($args,'-c:a','aac','-b:a','128k');
        else $args[]='-an';
        array_push($args,'-movflags','+faststart',$output);
        $pipes = [];
        $process = @proc_open($args, [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipes, __DIR__);
        if (!is_resource($process)) return ['success'=>false,'error'=>'Khong khoi dong duoc FFmpeg'];
        fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $log=''; $cancelled=false; $lastCheck=0;
        while (true) {
            $log .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
            if (strlen($log) > 20000) $log = substr($log,-20000);
            $state = proc_get_status($process);
            if (!$state['running']) { $exitCode=$state['exitcode']; break; }
            if ($shouldCancel && microtime(true)-$lastCheck >= 0.5) {
                $lastCheck=microtime(true);
                try { $cancelled = (bool)$shouldCancel(); } catch (Throwable $e) { $cancelled = true; }
                if ($cancelled) { proc_terminate($process); }
            }
            usleep(200000);
        }
        $log .= stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        proc_close($process);
        if ($cancelled) { @unlink($output); return ['success'=>false,'cancelled'=>true,'error'=>'Da huy']; }
        if ($exitCode !== 0 || !is_file($output) || filesize($output) === 0) {
            @unlink($output); return ['success'=>false,'error'=>'FFmpeg that bai: '.substr($log,-1500)];
        }
        $meta = $this->getMetadata($output);
        // CFR/VFR, timestamp and container rounding can produce small duration deviations.
        $tolerance = max(0.35, min(0.75, $requested * 0.02));
        if (!$meta || abs($meta['duration']-$requested)>$tolerance || $meta['width'] !== (int)$width
            || $meta['height'] !== (int)$height || $meta['has_audio'] !== (bool)($keepAudio && $source['has_audio'])) {
            @unlink($output);
            return ['success'=>false,'error'=>'Dau ra khong dat xac minh FFprobe (thoi luong, kich thuoc hoac am thanh)'];
        }
        // Decode check in an observable subprocess: maintain heartbeat, honor cancellation.
        $decode = @proc_open([$this->ffmpeg,'-nostdin','-v','error','-xerror','-i',$output,'-map','0:v:0','-f','null','-'],
            [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$dPipes,__DIR__);
        if (!is_resource($decode)) { @unlink($output); return ['success'=>false,'error'=>'Khong kiem tra duoc dau ra']; }
        fclose($dPipes[0]); stream_set_blocking($dPipes[1],false); stream_set_blocking($dPipes[2],false);
        $decodeCancelled=false; $decodeTime=0;
        while (true) {
            stream_get_contents($dPipes[1]); stream_get_contents($dPipes[2]);
            $dStatus=proc_get_status($decode);
            if (!$dStatus['running']) { $decodeExit=$dStatus['exitcode']; break; }
            if ($shouldCancel && microtime(true)-$decodeTime>=0.5) {
                $decodeTime=microtime(true);
                try { $decodeCancelled=(bool)$shouldCancel(); } catch (Throwable $e) { $decodeCancelled=true; }
                if ($decodeCancelled) proc_terminate($decode);
            }
            usleep(200000);
        }
        fclose($dPipes[1]); fclose($dPipes[2]); proc_close($decode);
        if ($decodeCancelled) { @unlink($output); return ['success'=>false,'cancelled'=>true,'error'=>'Da huy']; }
        if ($decodeExit !== 0) { @unlink($output); return ['success'=>false,'error'=>'Video dau ra khong giai ma duoc']; }
        if ($shouldCancel && $shouldCancel()) { @unlink($output); return ['success'=>false,'cancelled'=>true,'error'=>'Da huy']; }
        $thumbnailOk = false;
        if ($thumbnail) {
            $cmd = escapeshellcmd($this->ffmpeg).' -nostdin -v error -y -ss '.escapeshellarg((string)($meta['duration']/2))
                .' -i '.escapeshellarg($output).' -frames:v 1 '.escapeshellarg($thumbnail).' 2>&1';
            exec($cmd,$unused,$thumbStatus);
            $thumbnailOk=$thumbStatus === 0 && is_file($thumbnail) && filesize($thumbnail)>0;
        }
        return ['success'=>true,'output'=>$output,'thumbnail'=>$thumbnailOk?$thumbnail:null,
            'metadata'=>$meta,'log'=>substr($log,-10000)];
    }
}
