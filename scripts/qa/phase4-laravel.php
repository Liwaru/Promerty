<?php
/** Isolated CLI bootstrap: never reads the operational .env or writes operational caches. */
declare(strict_types=1);
$run=$argv[1]??'';$target=$argv[2]??'';$operation=$argv[3]??'';$probe=$argv[4]??'';
$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
try {
    if(!in_array($target,['qa','verify'],true)||!in_array($operation,['migrate','probe-up','probe-down'],true))throw new RuntimeException('Unsupported QA command');
    $c=json_decode(file_get_contents($private.'/'.$target.'-connection.json'),true,512,JSON_THROW_ON_ERROR);guardQa($c);
    $storage=$private.'/laravel-'.$target;
    foreach(['framework/cache/data','framework/sessions','framework/views','logs','bootstrap'] as $dir)if(!is_dir($storage.'/'.$dir))mkdir($storage.'/'.$dir,0777,true);
    $variables=['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3306','DB_DATABASE'=>$c['database'],'DB_USERNAME'=>$c['user'],'DB_PASSWORD'=>$c['password'],'DB_URL'=>'','DATABASE_URL'=>'','DB_SOCKET'=>'','CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','QUEUE_CONNECTION'=>'sync','MAIL_MAILER'=>'array','LOG_CHANNEL'=>'single','FILESYSTEM_DISK'=>'local','VIEW_COMPILED_PATH'=>$storage.'/framework/views','APP_CONFIG_CACHE'=>$storage.'/bootstrap/config.php','APP_PACKAGES_CACHE'=>$storage.'/bootstrap/packages.php','APP_SERVICES_CACHE'=>$storage.'/bootstrap/services.php','APP_ROUTES_CACHE'=>$storage.'/bootstrap/routes.php','APP_EVENTS_CACHE'=>$storage.'/bootstrap/events.php'];
    foreach($variables as $k=>$v){putenv($k.'='.$v);$_ENV[$k]=$v;$_SERVER[$k]=$v;}
    // QA-only schema loader: absolute client path, credentials never in process arguments.
    Illuminate\Database\Connection::resolverFor('mysql',function($pdo,$database,$prefix,$config)use($private){
        $connection=new class($pdo,$database,$prefix,$config) extends Illuminate\Database\MySqlConnection {
            public string $phase4Private;
            public function getSchemaState(?Illuminate\Filesystem\Filesystem $files=null,?callable $processFactory=null){
                $state=new class($this,$files,$processFactory) extends Illuminate\Database\Schema\MySqlSchemaState {
                    public string $phase4Private;
                    public function load($path){
                        $config=$this->connection->getConfig();$safe=['host'=>$config['host'],'port'=>(int)$config['port'],'database'=>$config['database'],'user'=>$config['username'],'password'=>$config['password']];
                        guardQa($safe);assertRestoreSql($path);
                        client($safe,$this->phase4Private,'mysql',['--binary-mode','--default-character-set=utf8mb4','--database='.$safe['database']],$path);
                    }
                };
                $state->phase4Private=$this->phase4Private;return $state;
            }
        };
        $connection->phase4Private=$private;return $connection;
    });
    $app=Illuminate\Foundation\Application::configure(basePath:$root)->withExceptions()->create();
    // Laravel's cache-prefix check needs the Windows drive prefix explicitly.
    $app->addAbsoluteCachePathPrefix(substr($storage,0,3));
    $app->useEnvironmentPath($private);$app->loadEnvironmentFrom('qa-no-operational-env');$app->useStoragePath($storage);
    $kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
    if(config('database.default')!=='mysql'||config('database.connections.mysql.database')!==$c['database']||config('database.connections.mysql.username')!==$c['user']||config('cache.default')!=='array'||config('session.driver')!=='array')throw new RuntimeException('Effective Laravel isolation guard');
    if(Illuminate\Support\Facades\DB::connection()->selectOne('SELECT DATABASE() AS db')->db!==$c['database'])throw new RuntimeException('Effective database guard');
    $args=['command'=>'migrate','--database'=>'mysql','--force'=>true,'--no-interaction'=>true];
    if($operation==='migrate')$args['--schema-path']=$root.'/database/schema/mysql-schema.sql';
    else {
        if($target!=='verify'||!is_dir($probe)||!str_starts_with(realpath($probe),realpath($private).DIRECTORY_SEPARATOR))throw new RuntimeException('Probe path guard');
        $args['--path']=[$probe];$args['--realpath']=true;
        if($operation==='probe-down'){$args['command']='migrate:rollback';$args['--step']=1;}
    }
    $output=new Symfony\Component\Console\Output\BufferedOutput();$code=$kernel->handle(new Symfony\Component\Console\Input\ArrayInput($args),$output);$kernel->terminate(new Symfony\Component\Console\Input\ArrayInput($args),$code);
    $log=$storage.'/logs/'.$operation.'-'.bin2hex(random_bytes(4)).'.txt';file_put_contents($log,$output->fetch());
    if($code!==0)throw new RuntimeException('Laravel QA command failed; see private logs');
    echo json_encode(['status'=>'PASS','target'=>$c['database'],'operation'=>$operation,'storage_isolated'=>true]);
}catch(Throwable $e){fwrite(STDERR,json_encode(['status'=>'FAIL','type'=>get_class($e),'code'=>(string)$e->getCode(),'reason'=>$e instanceof PDOException?'QA database failure':$e->getMessage()])."\n");exit(1);}
