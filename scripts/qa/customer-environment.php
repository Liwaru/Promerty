<?php
/** Local-only Customer QA runtime. Never uses the operational environment or bypasses authentication. */
declare(strict_types=1);
$root=dirname(__DIR__,2);
require_once $root.'/vendor/autoload.php';
$private=$root.'/storage/app/private/phase4-20261110-73e56946';
$config=json_decode(file_get_contents($private.'/qa-connection.json'),true,512,JSON_THROW_ON_ERROR);
if(($config['database']??'')!=='promerty_qa'||($config['host']??'')!=='127.0.0.1'||(int)($config['port']??0)!==3306)throw new RuntimeException('QA configuration guard');
$storage=$private.'/customer-runtime';
foreach(['framework/cache/data','framework/sessions','framework/views','logs','bootstrap','app/public/qa-only'] as $dir)if(!is_dir($storage.'/'.$dir))mkdir($storage.'/'.$dir,0777,true);
$keyFile=$storage.'/key';
if(!is_file($keyFile))file_put_contents($keyFile,'base64:'.base64_encode(random_bytes(32)),LOCK_EX);
$vars=['APP_ENV'=>'customer_qa','APP_DEBUG'=>'false','APP_URL'=>'http://127.0.0.1:8011','APP_KEY'=>file_get_contents($keyFile),
    'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3306','DB_DATABASE'=>'promerty_qa','DB_USERNAME'=>$config['user'],'DB_PASSWORD'=>$config['password'],
    'DB_URL'=>'','DATABASE_URL'=>'','DB_SOCKET'=>'','CACHE_STORE'=>'file','SESSION_DRIVER'=>'file','SESSION_COOKIE'=>'promerty_customer_qa','SESSION_DOMAIN'=>'','SESSION_SECURE_COOKIE'=>'false',
    'SANCTUM_STATEFUL_DOMAINS'=>'127.0.0.1:8011','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'single',
    'VIEW_COMPILED_PATH'=>$storage.'/framework/views'];
foreach(['CONFIG','PACKAGES','SERVICES','ROUTES','EVENTS'] as $cache)$vars['APP_'.$cache.'_CACHE']=$storage.'/bootstrap/'.strtolower($cache).'.php';
foreach($vars as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
$app=require $root.'/bootstrap/app.php';
$app->addAbsoluteCachePathPrefix(substr($storage,0,3));
$app->useEnvironmentPath($private);$app->loadEnvironmentFrom('customer-no-operational-env');$app->useStoragePath($storage);
return $app;
