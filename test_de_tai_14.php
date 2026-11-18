<?php
// Run from CLI: php test_de_tai_14.php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }
require_once __DIR__.'/VideoProcessor.php';
$p=new VideoProcessor();
$root=sys_get_temp_dir().DIRECTORY_SEPARATOR.'vt14_test_'.bin2hex(random_bytes(4));
mkdir($root,0700,true);
function runCommand(array $args) {
    $cmd=implode(' ',array_map('escapeshellarg',$args));
    $out=[];$status=1;exec($cmd.' 2>&1',$out,$status);
    if ($status!==0) throw new RuntimeException('FFmpeg failed: '.implode("\n",$out));
}
function verifyCase($condition,$label) { echo ($condition?'PASS':'FAIL').': '.$label.PHP_EOL; if (!$condition) $GLOBALS['failCount']++; }
$GLOBALS['failCount']=0;
try {
    $source=$root.'/source30.mp4';
    $out=$root.'/cut15.mp4';
    $noAudio=$root.'/noaudio.mp4';
    runCommand(['ffmpeg','-nostdin','-v','error','-y','-f','lavfi','-i','testsrc=size=320x180:rate=15:duration=30',
        '-f','lavfi','-i','sine=frequency=440:duration=30','-c:v','libx264','-preset','ultrafast','-pix_fmt','yuv420p',
        '-c:a','aac','-shortest',$source]);
    $res=$p->processVideo($source,$out,$root.'/cutthumb.jpg',5,20,854,480,true);
    verifyCase(!empty($res['success']) && abs($res['metadata']['duration']-15)<0.35,'Cat 5-20s tu nguon 30s (sai so <= 0.35s)');
    verifyCase(!empty($res['success']) && $res['metadata']['width']===854 && $res['metadata']['height']===480,'Output 854x480 (scale+pad, no distortion)');
    verifyCase(!empty($res['success']) && $res['metadata']['has_audio']===true,'Giu am thanh neu co');
    $res2=$p->processVideo($source,$noAudio,null,2,4,854,480,false);
    verifyCase(!empty($res2['success']) && $res2['metadata']['has_audio']===false,'Bo am thanh khi yeu cau');
    verifyCase(!VideoProcessor::validTrim(5,40,30) && !VideoProcessor::validTrim(-1,10,30)
        && !VideoProcessor::validTrim(6,6,30),'Tu choi thoi gian khong hop le');
    verifyCase(!VideoProcessor::validDimensions(853,480) && !VideoProcessor::validDimensions(9000,9000),'Tu choi kich thuoc qua gioi han');
    $bad=$root.'/corrupt.mp4';file_put_contents($bad,'invalid video content');
    verifyCase($p->getMetadata($bad)===null,'Tu choi tep video hong');
    $cancelled=$p->processVideo($source,$root.'/cancel.mp4',null,0,20,854,480,true,fn()=>true);
    verifyCase(!empty($cancelled['cancelled']) && !file_exists($root.'/cancel.mp4'),'Huy qua trinh khong de lai output');
    echo 'Total failures: '.$GLOBALS['failCount'].PHP_EOL;
} finally {
    foreach (glob($root.'/*') as $path) @unlink($path);
    @rmdir($root);
}
exit($GLOBALS['failCount']?1:0);
