<?php
/** DB0 only: metadata SELECT/SHOW, no Laravel bootstrap, business-row reads or mutations. */
declare(strict_types=1);
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, json_encode(['status'=>'BLOCKED','type'=>get_class($e),'reason'=>'Read-only DB0 inspection failed; credential and SQL details suppressed'])."\n"); exit(1);
});
$root=dirname(__DIR__,2);$run=$argv[1]??'';
if(PHP_SAPI!=='cli'||!is_dir($run)||!str_starts_with((string)realpath($run),realpath($root.'/storage/app/private').DIRECTORY_SEPARATOR)||!str_starts_with(basename($run),'db0-'))throw new RuntimeException('Private DB0 CLI run required');
require $root.'/vendor/autoload.php';
if(is_file($root.'/bootstrap/cache/config.php'))throw new RuntimeException('Main config cache requires a separate effective-config review; not altered');
$env=Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
if(($env['DB_CONNECTION']??'')!=='mysql'||($env['DB_DATABASE']??'')!=='promerty'||($env['DB_HOST']??'')!=='127.0.0.1'||(string)($env['DB_PORT']??'')!=='3306'||!empty($env['DB_URL'])||!empty($env['DATABASE_URL'])||!empty($env['DB_SOCKET']))throw new RuntimeException('Main configuration guard');
$main=['host'=>'127.0.0.1','port'=>3306,'database'=>'promerty','user'=>$env['DB_USERNAME']??'','password'=>$env['DB_PASSWORD']??''];
$qa=json_decode(file_get_contents($root.'/storage/app/private/phase4-20261110-73e56946/qa-connection.json'),true,512,JSON_THROW_ON_ERROR);
$restoreFile=$root.'/storage/app/private/pre-cutover-3b-20261117-31542bb4/restore-connection.json';
$restore=is_file($restoreFile)?json_decode(file_get_contents($restoreFile),true,512,JSON_THROW_ON_ERROR):null;
function connectDb0(array $c,string $expected):PDO {
    if($c['host']!=='127.0.0.1'||(int)$c['port']!==3306||$c['database']!==$expected)throw new RuntimeException('Known local credential scope guard');
    // The audit selects information_schema, never a business database.
    return new PDO('mysql:host=127.0.0.1;port=3306;dbname=information_schema;charset=utf8mb4',$c['user'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5,PDO::ATTR_PERSISTENT=>false]);
}
function queryDb0(PDO $p,string $sql,array $params=[]):array {
    if(!preg_match('/^\s*(SELECT|SHOW)\b/i',$sql)||preg_match('/\b(INTO\s+(OUTFILE|DUMPFILE)|LOAD_FILE|SLEEP|BENCHMARK)\s*\(?/i',$sql))throw new RuntimeException('Metadata query-only guard');
    $s=$p->prepare($sql);$s->execute($params);return$s->fetchAll(PDO::FETCH_ASSOC);
}
function principalDb0(PDO $p):array {
    $id=queryDb0($p,'SELECT CURRENT_USER() AS effectiveAccount, USER() AS clientAccount, CURRENT_ROLE() AS currentRole, DATABASE() AS auditSchema')[0];
    $at=strrpos($id['effectiveAccount'],'@');$u=substr($id['effectiveAccount'],0,$at);$h=substr($id['effectiveAccount'],$at+1);$grantee="'".str_replace("'","''",$u)."'@'".str_replace("'","''",$h)."'";
    $global=queryDb0($p,'SELECT PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=? ORDER BY PRIVILEGE_TYPE',[$grantee]);
    $schemas=queryDb0($p,'SELECT TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE=? ORDER BY TABLE_SCHEMA,PRIVILEGE_TYPE',[$grantee]);
    $tables=queryDb0($p,'SELECT TABLE_SCHEMA,TABLE_NAME,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE=? ORDER BY TABLE_SCHEMA,TABLE_NAME,PRIVILEGE_TYPE',[$grantee]);
    $columns=queryDb0($p,'SELECT TABLE_SCHEMA,PRIVILEGE_TYPE,IS_GRANTABLE FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE=?',[$grantee]);
    return$id+['global'=>$global,'schema'=>$schemas,'table'=>$tables,'column'=>$columns,'transport'=>$p->getAttribute(PDO::ATTR_CONNECTION_STATUS)];
}
$admin=connectDb0($main,'promerty');
$server=queryDb0($admin,'SELECT VERSION() AS version, @@port AS port')[0];
$variables=[];foreach(queryDb0($admin,"SHOW VARIABLES WHERE Variable_name IN ('bind_address','skip_networking','skip_name_resolve','skip_grant_tables','named_pipe','shared_memory','require_secure_transport','have_ssl','log_bin','general_log')")as$v)$variables[$v['Variable_name']]=$v['Value'];
$ids=['main'=>$main,'qa'=>$qa];if($restore)$ids['restore']=$restore;
$principals=[];foreach($ids as$tag=>$config){try{$p=$tag==='main'?$admin:connectDb0($config,$tag==='qa'?'promerty_qa':'promerty_restore_3b_755e5a4e');$principals[$tag]=['configuredDatabase'=>$config['database'],'configuredUsername'=>$config['user'],'passwordConfigured'=>strlen((string)$config['password'])>0,'authenticationSucceeded'=>true]+principalDb0($p);}catch(PDOException $e){$principals[$tag]=['configuredDatabase'=>$config['database'],'authenticationSucceeded'=>false,'status'=>'BLOCKED','sqlstate'=>(string)$e->getCode()];}}
// Only plugin names and boolean empty-material flags leave the server; no auth material selected.
$names=array_values(array_unique(array_column($ids,'user')));$marks=implode(',',array_fill(0,count($names),'?'));
$accounts=queryDb0($admin,"SELECT User,Host,plugin,(COALESCE(authentication_string,'')='') AS authMaterialEmpty,(COALESCE(Password,'')='') AS legacyMaterialEmpty FROM mysql.user WHERE User IN ($marks) ORDER BY User,Host",$names);
$alternatives=queryDb0($admin,"SELECT User,Host,COALESCE(JSON_LENGTH(Priv,'$.auth_or'),0) AS alternativeEntryCount,JSON_EXTRACT(Priv,'$.auth_or[*].plugin') AS alternativePluginNames FROM mysql.global_priv WHERE User IN ($marks) ORDER BY User,Host",$names);
$roles=queryDb0($admin,"SELECT Host,User,Role,Admin_option FROM mysql.roles_mapping WHERE User IN ($marks)",$names);
$proxies=queryDb0($admin,"SELECT Host,User,Proxied_host,Proxied_user,With_grant FROM mysql.proxies_priv WHERE User IN ($marks)",$names);
$routineGrants=queryDb0($admin,"SELECT User,Host,Db,Routine_type,Proc_priv FROM mysql.procs_priv WHERE User IN ($marks)",$names);
$rootScopes=queryDb0($admin,"SELECT Host,plugin,(COALESCE(authentication_string,'')='') AS authMaterialEmpty FROM mysql.user WHERE User='root' ORDER BY Host");
$anon=queryDb0($admin,"SELECT COUNT(*) AS count FROM mysql.user WHERE User=''")[0];
// SHOW CREATE DATABASE is schema metadata only: no business table or row is read.
$visibility=[];foreach($ids as$tag=>$config){if(empty($principals[$tag]['authenticationSucceeded']))continue;$p=$tag==='main'?$admin:connectDb0($config,$tag==='qa'?'promerty_qa':'promerty_restore_3b_755e5a4e');foreach(['promerty','promerty_qa','promerty_restore_3b_755e5a4e','mysql']as$db){try{queryDb0($p,'SHOW CREATE DATABASE `'.$db.'`');$visibility[$tag][$db]=['metadataVisible'=>true];}catch(PDOException $e){$visibility[$tag][$db]=['metadataVisible'=>false,'serverError'=>(int)($e->errorInfo[1]??0),'sqlstate'=>(string)$e->getCode()];}}}
$out=['checkedAtUtc'=>gmdate(DATE_ATOM),'mode'=>'READ_ONLY_METADATA','server'=>$server,'variables'=>$variables,'principals'=>$principals,'accountAuthenticationMetadata'=>$accounts,'alternativeAuthenticationMetadata'=>$alternatives,'rootHostScopes'=>$rootScopes,'anonymousAccountCount'=>(int)$anon['count'],'roleMappings'=>$roles,'proxyMappings'=>$proxies,'routinePrivileges'=>$routineGrants,'databaseMetadataVisibility'=>$visibility,
    'configuration'=>['mainConfigCachePresent'=>false,'mainSessionDriver'=>$env['SESSION_DRIVER']??'database','mainSessionConnection'=>$env['SESSION_CONNECTION']??null,'mainCacheStore'=>$env['CACHE_STORE']??'database','mainCacheConnection'=>$env['DB_CACHE_CONNECTION']??null,'mainCacheLockConnection'=>$env['DB_CACHE_LOCK_CONNECTION']??null,'qaConfiguredSessionDriver'=>'file','qaConfiguredCacheStore'=>'file','qaRuntimeDatabase'=>'promerty_qa','qaIdentityEvidence'=>'existing staging bootstrap config + direct known-credential verification, not a new Apache diagnostic endpoint'],
    'safety'=>['laravelBootstrapped'=>false,'businessTablesRead'=>false,'mutatingQueriesRun'=>false,'showCreateUserRun'=>false,'rawShowGrantsExported'=>false,'credentialFilesCreated'=>false,'passwordGuesses'=>false,'newCredentialsUsed'=>false,'a0Retried'=>false]];
file_put_contents($root.'/docs/qa/db0-metadata-results.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo json_encode(['status'=>'METADATA_AUDIT_COMPLETED','server'=>$server,'mainAuthSucceeded'=>$principals['main']['authenticationSucceeded']??false,'mainPasswordConfigured'=>$principals['main']['passwordConfigured']??null,'mainEffectiveAccount'=>$principals['main']['effectiveAccount']??null,'qaAuthSucceeded'=>$principals['qa']['authenticationSucceeded']??false,'restoreAuthSucceeded'=>$principals['restore']['authenticationSucceeded']??false,'roleMappings'=>count($roles),'businessTablesRead'=>false])."\n";
