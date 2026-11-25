<?php
/** Explicitly authorized local development accounts, never a production seeder. */
declare(strict_types=1);
$devAction=$argv[1]??'audit';$root=dirname(__DIR__,2);$run=$root.'/storage/app/private/development-login-20261113';
if(PHP_SAPI!=='cli'||!in_array($devAction,['audit','backup','verify-web','create','final'],true))throw new RuntimeException('CLI action required');
if(!is_dir($run))mkdir($run,0700,true);
$argv=[__FILE__,'library',$run];require $root.'/scripts/qa/phase4.php';
$config=sourceConfig($root);$pdo=db($config);
$app=require $root.'/bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
set_exception_handler(function(Throwable $error):void{fwrite(STDERR,($error instanceof PDOException?'Database operation failed; credentials suppressed':$error->getMessage()).PHP_EOL);exit(1);});
if(!in_array($app->environment(),['local','development'],true)||config('database.default')!=='mysql'||config('database.connections.mysql.database')!=='promerty'||config('database.connections.mysql.host')!=='127.0.0.1'||(string)config('database.connections.mysql.port')!=='3306'||Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty')throw new RuntimeException('Effective local database guard failed');
$listeners=[];exec('netstat -ano -p TCP',$listeners,$code);
if($code!==0||!preg_match('/127\.0\.0\.1:8000\s+0\.0\.0\.0:0\s+LISTENING\s+2168\s*$/m',implode("\n",$listeners)))throw new RuntimeException('Verified loopback development server no longer present');
$emails=['customer@gmail.com'=>'customer','agent@gmail.com'=>'agent','branch@gmail.com'=>'branch_manager','admin@gmail.com'=>'admin','owner@gmail.com'=>'owner'];
$conflicts=App\Models\User::withTrashed()->whereIn('email',array_keys($emails))->get(['id','email','role_id','deleted_at'])->toArray();
function persistDev(string $file,mixed $value):void{file_put_contents($file,json_encode($value,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));}
if($devAction==='audit'){
    $r=['environment'=>$app->environment(),'database'=>Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db,'host'=>config('database.connections.mysql.host'),'port'=>config('database.connections.mysql.port'),'server'=>rows($pdo,'SELECT @@hostname,@@port,@@version'),'roles'=>rows($pdo,'SELECT id,name,level FROM roles ORDER BY id'),'permissions'=>rows($pdo,'SELECT r.name,p.name FROM role_permissions rp JOIN roles r ON r.id=rp.role_id JOIN permissions p ON p.id=rp.permission_id ORDER BY r.name,p.name'),'conflicts'=>$conflicts,'users_count'=>App\Models\User::withTrashed()->count(),'active_branches'=>App\Models\Branch::where('status','active')->count(),'session_driver'=>config('session.driver'),'cache_store'=>config('cache.default'),'schema_columns'=>rows($pdo,"SELECT COLUMN_NAME,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='promerty' AND TABLE_NAME='users' ORDER BY ORDINAL_POSITION")];
    persistDev($run.'/audit.json',$r);echo json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);exit;
}
if($devAction==='backup'){
    if($conflicts)throw new RuntimeException('Email conflict; no accounts or existing passwords changed');
    if(is_file($run.'/backup-verification.json'))throw new RuntimeException('Backup already exists; do not overwrite');
    $before=snapshot($pdo,'promerty');foreach($before['schema']['tables'] as $table)if($table[1]!=='InnoDB')throw new RuntimeException('Non-InnoDB table requires a revised consistent backup');
    persistDev($run.'/before.json',$before);persistDev($run.'/protected-files.json',['env'=>hash_file('sha256',$root.'/.env'),'app_key_hash'=>hash('sha256',config('app.key'))]);
    $file=$run.'/promerty-before-accounts.sql';if(file_exists($file))throw new RuntimeException('Backup path already exists');
    client($config,$run,'mysqldump',['--single-transaction','--quick','--skip-lock-tables','--skip-add-locks','--hex-blob','--default-character-set=utf8mb4','--routines','--events','--triggers','--result-file='.$file,'promerty']);
    $sql=file_get_contents($file);preg_match_all('/CREATE TABLE `([^`]+)`/',$sql,$m);$tables=$m[1];sort($tables);$expected=array_column($before['schema']['tables'],0);sort($expected);
    if(strlen($sql)<1000||$tables!==$expected||preg_match('/\b(?:CREATE|DROP)\s+DATABASE\b|\bUSE\s+`?promerty\b/i',$sql))throw new RuntimeException('Backup structure/scope validation failed');
    $r=['database'=>'promerty','file'=>$file,'bytes'=>filesize($file),'sha256'=>hash_file('sha256',$file),'tables'=>count($tables),'consistent_transaction'=>true,'client_exit_success'=>true,'table_definitions_match'=>true,'restore_test_performed'=>false];persistDev($run.'/backup-verification.json',$r);echo json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);exit;
}
$backup=json_decode(file_get_contents($run.'/backup-verification.json'),true,512,JSON_THROW_ON_ERROR);
if(hash_file('sha256',$backup['file'])!==$backup['sha256'])throw new RuntimeException('Backup digest changed');
if($devAction==='verify-web'){
    // A real HTTP session cookie, decrypted with the unchanged local key, must
    // identify a row in this database. No debug route is added to the app.
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);$cookie=rawurldecode($input['cookie']);
    $decrypted=Illuminate\Support\Facades\Crypt::decryptString($cookie);$sessionId=substr($decrypted,strrpos($decrypted,'|')+1);
    $session=Illuminate\Support\Facades\DB::table('sessions')->where('id',$sessionId)->first();
    if(!$session||$session->ip_address!=='127.0.0.1')throw new RuntimeException('HTTP session database identity not proved');
    $r=['origin'=>'http://localhost:8000','database'=>'promerty','http_session_found_in_database'=>true,'app_key_matches_http_cookie'=>true,'cookie_value_disclosed'=>false];persistDev($run.'/web-identity.json',$r);echo json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);exit;
}
if($devAction==='create'){
    if(!is_file($run.'/web-identity.json'))throw new RuntimeException('Verify actual HTTP database identity before provisioning');
    if($conflicts)throw new RuntimeException('Existing email conflict: refusing all account writes');
    $input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);if(count($input)!==5)throw new RuntimeException('Exactly five input accounts required');
    $seen=[];foreach($input as $item){if(($emails[$item['email']]??null)!==$item['role']||isset($seen[$item['email']]))throw new RuntimeException('Account allowlist mismatch');$seen[$item['email']]=true;}
    $accounts=[];$branch=App\Models\Branch::where('status','active')->first();
    Illuminate\Support\Facades\DB::transaction(function()use($input,$branch,&$accounts){
        foreach($input as $item){if(App\Models\User::withTrashed()->where('email',$item['email'])->lockForUpdate()->exists())throw new RuntimeException('Concurrent email conflict; transaction rolled back');
            $role=App\Models\Role::where('name',$item['role'])->firstOrFail();
            $user=App\Models\User::create(['name'=>'Development '.ucwords(str_replace('_',' ',$item['role'])),'username'=>'dev-local-'.$item['role'].'-'.bin2hex(random_bytes(3)),'email'=>$item['email'],'password'=>Illuminate\Support\Facades\Hash::make($item['password']),'role_id'=>$role->id,'branch_id'=>in_array($item['role'],['agent','branch_manager'])?$branch?->id:null,'status'=>'active']);
            if(!Illuminate\Support\Facades\Hash::check($item['password'],$user->password))throw new RuntimeException('Hash validation failed');
            $accounts[]=['id'=>$user->id,'email'=>$user->email,'role'=>$role->name,'branch_id'=>$user->branch_id,'hash_verified'=>true];
        }
    });persistDev($run.'/created.json',$accounts);echo json_encode(['database'=>'promerty','created'=>$accounts],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);exit;
}
$before=json_decode(file_get_contents($run.'/before.json'),true,512,JSON_THROW_ON_ERROR);$after=snapshot($pdo,'promerty');$technical=['users','sessions','cache','cache_locks'];$changed=[];
foreach($before['checksums'] as $table=>$hash)if(!in_array($table,$technical,true)&&($after['checksums'][$table]!==$hash||$after['row_counts'][$table]!==$before['row_counts'][$table]))$changed[]=$table;
$files=json_decode(file_get_contents($run.'/protected-files.json'),true,512,JSON_THROW_ON_ERROR);
$r=['database'=>'promerty','schema_unchanged'=>$before['schema']===$after['schema'],'unrelated_business_tables_changed'=>$changed,'user_count_before'=>$before['row_counts']['users'],'user_count_after'=>$after['row_counts']['users'],'users_added'=>$after['row_counts']['users']-$before['row_counts']['users'],'technical_tables_allowed'=>$technical,'env_unchanged'=>$files['env']===hash_file('sha256',$root.'/.env'),'app_key_unchanged'=>$files['app_key_hash']===hash('sha256',config('app.key')),'backup_digest_unchanged'=>hash_file('sha256',$backup['file'])===$backup['sha256']];
persistDev($run.'/after.json',$after);persistDev($run.'/integrity.json',$r);echo json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR);
if($changed||!$r['schema_unchanged']||!$r['env_unchanged']||!$r['app_key_unchanged']||$r['users_added']!==5)exit(1);
