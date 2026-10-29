<?php
class VideoProcessor {
    private $ffmpegPath;
    private $ffprobePath;

    public function __construct($ffmpeg = 'ffmpeg', $ffprobe = 'ffprobe') {
        $this->ffmpegPath = $ffmpeg;
        $this->ffprobePath = $ffprobe;
    }

    // Đọc thông số thực tế bằng ffprobe và kiểm tra tệp lỗi
    public function getMetadata($filePath) {
        if (!file_exists($filePath)) return null;
        
        $cmd = sprintf('%s -v quiet -print_format json -show_format -show_streams %s', 
            escapeshellcmd($this->ffprobePath), 
            escapeshellarg($filePath)
        );
        
        $output = shell_exec($cmd);
        $data = json_decode($output, true);
        
        if (!$data || !isset($data['format'])) return null;

        $duration = isset($data['format']['duration']) ? floatval($data['format']['duration']) : 0;
        $width = 0; $height = 0; $hasAudio = false;

        foreach ($data['streams'] as $stream) {
            if ($stream['codec_type'] === 'video') {
                $width = isset($stream['width']) ? intval($stream['width']) : 0;
                $height = isset($stream['height']) ? intval($stream['height']) : 0;
            }
            if ($stream['codec_type'] === 'audio') {
                $hasAudio = true;
            }
        }

        if ($width <= 0 || $height <= 0 || $duration <= 0) return null;

        return [
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
            'has_audio' => $hasAudio,
            'size' => filesize($filePath)
        ];
    }

    // Cắt video giữ tỷ lệ (scale & pad) và xử lý âm thanh
    public function processVideo($inputPath, $outputPath, $thumbnailPath, $startTime, $endTime, $targetWidth, $targetHeight, $keepAudio = true) {
        if (!file_exists($inputPath)) return ['success' => false, 'error' => 'Tệp nguồn không tồn tại.'];

        $duration = $endTime - $startTime;
        if ($duration <= 0) return ['success' => false, 'error' => 'Thời gian cắt không hợp lệ.'];

        // Bộ lọc giữ nguyên tỷ lệ khung hình, không làm méo hình
        $videoFilter = sprintf("scale=%d:%d:force_original_aspect_ratio=decrease,pad=%d:%d:(ow-iw)/2:(oh-ih)/2", 
            $targetWidth, $targetHeight, $targetWidth, $targetHeight
        );
        
        $audioOption = $keepAudio ? "-c:a aac" : "-an";

        // Lệnh FFmpeg cắt video
        $cmd = sprintf('%s -y -ss %f -i %s -t %f -vf "%s" -c:v libx264 %s %s 2>&1',
            escapeshellcmd($this->ffmpegPath),
            $startTime,
            escapeshellarg($inputPath),
            $duration,
            $videoFilter,
            $audioOption,
            escapeshellarg($outputPath)
        );

        $outputLog = [];
        $returnCode = 0;
        exec($cmd, $outputLog, $returnCode);

        if ($returnCode === 0 && file_exists($outputPath) && filesize($outputPath) > 0) {
            // Sinh ảnh đại diện (Thumbnail) tại mốc giữa video kết quả
            $thumbCmd = sprintf('%s -y -ss %f -i %s -vframes 1 %s 2>&1',
                escapeshellcmd($this->ffmpegPath),
                $duration / 2,
                escapeshellarg($outputPath),
                escapeshellarg($thumbnailPath)
            );
            exec($thumbCmd);

            return ['success' => true, 'output' => $outputPath, 'thumbnail' => $thumbnailPath, 'log' => implode("\n", $outputLog)];
        } else {
            return ['success' => false, 'error' => implode("\n", $outputLog)];
        }
    }
}
?>