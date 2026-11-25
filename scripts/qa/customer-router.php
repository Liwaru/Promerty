<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||($_SERVER['SERVER_ADDR']??'127.0.0.1')!=='127.0.0.1'||($_SERVER['SERVER_PORT']??'')!=='8011') { http_response_code(403); exit; }
$root=dirname(__DIR__,2);
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
// Serve only the dedicated public asset tree; never the private runtime or credentials.
if(str_starts_with($path,'/build/')||$path==='/favicon.ico')return false;
if(str_starts_with($path,'/storage/')){
    $base=realpath($root.'/storage/app/private/phase4-20261110-73e56946/customer-runtime/app/public');
    $file=$base?realpath($base.'/'.substr($path,9)):false;
    if(!$file||!str_starts_with($file,$base.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit;}
    header('Content-Type: '.(mime_content_type($file)?:'application/octet-stream'));readfile($file);exit;
}
$app=require __DIR__.'/customer-environment.php';
$app->handleRequest(Illuminate\Http\Request::capture());
