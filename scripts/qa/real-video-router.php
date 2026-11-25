<?php
/** Loopback-only, Basic-authenticated QA runtime. Not registered in production routes. */
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||($_SERVER['SERVER_PORT']??'')!=='8017'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)||!in_array($_SERVER['HTTP_HOST']??'',['127.0.0.1:8017','localhost:8017'],true)){http_response_code(403);exit;}
$root=dirname(__DIR__,2);$run=$root.'/storage/app/private/real-video-20261113';
$access=json_decode(file_get_contents($run.'/access.json'),true,512,JSON_THROW_ON_ERROR);
if(!hash_equals($access['username'],$_SERVER['PHP_AUTH_USER']??'')||!hash_equals($access['password'],$_SERVER['PHP_AUTH_PW']??'')){
    header('WWW-Authenticate: Basic realm="Promerty Private Video QA"');header('Cache-Control: no-store');http_response_code(401);echo 'Private QA. Credentials are in the local private run directory.';exit;
}
$manifest=json_decode(file_get_contents($run.'/manifest.json'),true,512,JSON_THROW_ON_ERROR);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
require_once $root.'/vendor/autoload.php';
// Exact two-file allowlist: no directory browsing, no arbitrary private path mapping.
foreach($manifest['media'] as $media)if($path===$media['url']){
    $base=realpath($root.'/storage/app/qa-media/videos');$file=realpath($base.DIRECTORY_SEPARATOR.$media['source']);
    if(!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit;}
    // PHP's Windows development server has one worker. Bound each open-ended
    // media range so a preloaded, paused video cannot monopolize that worker.
    $size=filesize($file);$start=0;$end=$size-1;$range=$_SERVER['HTTP_RANGE']??'';
    header('Content-Type: video/mp4');header('Cache-Control: private, no-store');header('X-Content-Type-Options: nosniff');header('Accept-Ranges: bytes');
    if($range!==''){
        if(!preg_match('/^bytes=(\d*)-(\d*)$/D',$range,$match)||($match[1]===''&&$match[2]==='')){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        if($match[1]===''){$start=max(0,$size-(int)$match[2]);}else{$start=(int)$match[1];if($match[2]!=='')$end=min($end,(int)$match[2]);}
        if($start>=$size||$start>$end){header('Content-Range: bytes */'.$size);http_response_code(416);exit;}
        $end=min($end,$start+262143);http_response_code(206);header("Content-Range: bytes $start-$end/$size");
    }
    header('Content-Length: '.($end-$start+1));
    if(($_SERVER['REQUEST_METHOD']??'GET')!=='HEAD'){$handle=fopen($file,'rb');fseek($handle,$start);$remaining=$end-$start+1;while($remaining>0&&!feof($handle)&&!connection_aborted()){$bytes=fread($handle,min(65536,$remaining));echo $bytes;$remaining-=strlen($bytes);}fclose($handle);}exit;
}
if(str_starts_with($path,'/storage/')){http_response_code(404);exit;}
if(str_starts_with($path,'/build/')||str_starts_with($path,'/images/')||preg_match('#^/favicon(?:-[0-9]+x[0-9]+\.png|\.ico)$#D',$path)){
    $base=realpath($root.'/public');$file=realpath($base.$path);
    if(!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit;}
    $mime=match(strtolower(pathinfo($file,PATHINFO_EXTENSION))){'js'=>'text/javascript','css'=>'text/css','svg'=>'image/svg+xml',default=>(new finfo(FILEINFO_MIME_TYPE))->file($file)};
    header('Content-Type: '.$mime);readfile($file);exit;
}
$app=require __DIR__.'/customer-environment.php';
$runtime=$run.'/runtime';
foreach(['framework/views','framework/sessions','framework/cache/data','logs'] as $dir)if(!is_dir($runtime.'/'.$dir))mkdir($runtime.'/'.$dir,0700,true);
$app->useStoragePath($runtime);
foreach(['LOG_CHANNEL'=>'stderr','CACHE_STORE'=>'array','VIEW_COMPILED_PATH'=>$runtime.'/framework/views'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty_qa')throw new RuntimeException('QA database identity guard');
config(['app.url'=>'http://127.0.0.1:8017','session.cookie'=>'promerty_real_video_qa','session.files'=>$runtime.'/framework/sessions','sanctum.stateful'=>['127.0.0.1:8017','localhost:8017']]);
$app->handleRequest(Illuminate\Http\Request::capture());
