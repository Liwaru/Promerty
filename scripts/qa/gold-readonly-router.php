<?php
/** Private visual runtime: real Laravel views/queries, DB-enforced read-only transaction. */
declare(strict_types=1);
if(PHP_SAPI!=='cli-server'||($_SERVER['SERVER_PORT']??'')!=='8026'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(403);exit;}
$root=dirname(__DIR__,2);$run=$root.'/storage/app/private/gold-visual-20261116';
$access=json_decode(file_get_contents($run.'/access.json'),true,512,JSON_THROW_ON_ERROR);
if(!hash_equals($access['username'],$_SERVER['PHP_AUTH_USER']??'')||!hash_equals($access['password'],$_SERVER['PHP_AUTH_PW']??'')){header('WWW-Authenticate: Basic realm="Promerty Readonly Visual QA"');http_response_code(401);exit;}
if(!in_array($_SERVER['REQUEST_METHOD'],['GET','HEAD'],true)){http_response_code(405);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if(str_starts_with($path,'/build/')||str_starts_with($path,'/images/')||str_starts_with($path,'/favicon')){$public=realpath($root.'/public');$file=realpath($public.$path);if(!$file||!str_starts_with($file,$public.DIRECTORY_SEPARATOR)||!is_file($file)){http_response_code(404);exit;}return false;}
require $root.'/vendor/autoload.php';
foreach(['SESSION_DRIVER'=>'file','SESSION_COOKIE'=>'promerty_gold_readonly','CACHE_STORE'=>'array','LOG_CHANNEL'=>'stderr','MAIL_MAILER'=>'array','VIEW_COMPILED_PATH'=>$run.'/framework/views'] as $key=>$value){putenv($key.'='.$value);$_ENV[$key]=$value;$_SERVER[$key]=$value;}
$app=require $root.'/bootstrap/app.php';$app->useStoragePath($run);$app->addAbsoluteCachePathPrefix(substr($run,0,3));$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('local')||config('database.connections.mysql.host')!=='127.0.0.1'||Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty')throw new RuntimeException('Readonly local database guard');
config(['session.files'=>$run.'/framework/sessions','sanctum.stateful'=>['localhost:8000','127.0.0.1:8026']]);
$db=Illuminate\Support\Facades\DB::connection();$db->statement('SET SESSION TRANSACTION READ ONLY');$db->beginTransaction();
try{$kernel=$app->make(Illuminate\Contracts\Http\Kernel::class);$request=Illuminate\Http\Request::capture();$response=$kernel->handle($request);$response->send();$kernel->terminate($request,$response);}finally{if($db->transactionLevel())$db->rollBack();}
