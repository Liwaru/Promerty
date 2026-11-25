<?php
/** Phase 4 CLI tooling. No operational application bootstrap or operational writes. */
declare(strict_types=1);
require dirname(__DIR__, 2).'/vendor/autoload.php';

const OPERATIONAL_DB = 'promerty';
$root = dirname(__DIR__, 2);
$private = $argv[2] ?? '';
$action = $argv[1] ?? '';
if (PHP_SAPI !== 'cli' || !is_dir($private) || !str_starts_with(realpath($private), realpath($root.'/storage/app/private').DIRECTORY_SEPARATOR)) {
    fwrite(STDERR, "Invalid private run directory.\n"); exit(1);
}
function saveJson(string $path, mixed $data): void {
    if (file_exists($path)) throw new RuntimeException('Refusing to overwrite artifact');
    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n", LOCK_EX);
}
function ident(string $v): string { return '`'.str_replace('`', '``', $v).'`'; }
function db(array $c): PDO {
    if (($c['host']??'') !== '127.0.0.1' || ($c['port']??0) !== 3306 || !preg_match('/^[a-z0-9_]+$/D', $c['database']??'')) throw new RuntimeException('Connection guard');
    $p = new PDO('mysql:host=127.0.0.1;port=3306;dbname='.$c['database'].';charset=utf8mb4', $c['user'], $c['password'], [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>5]);
    if ($p->query('SELECT DATABASE()')->fetchColumn() !== $c['database']) throw new RuntimeException('Identity mismatch');
    return $p;
}
function rows(PDO $p, string $sql, array $params=[]): array { $s=$p->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_NUM); }
function metadata(PDO $p, string $name): array {
    $r=[];
    $queries=[
        'tables'=>'SELECT TABLE_NAME,ENGINE,TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME',
        'columns'=>'SELECT TABLE_NAME,COLUMN_NAME,ORDINAL_POSITION,COLUMN_TYPE,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_KEY,EXTRA,COLLATION_NAME,CHARACTER_SET_NAME,GENERATION_EXPRESSION FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,ORDINAL_POSITION',
        'indexes'=>'SELECT TABLE_NAME,INDEX_NAME,NON_UNIQUE,SEQ_IN_INDEX,COLUMN_NAME,SUB_PART,COLLATION,INDEX_TYPE FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,INDEX_NAME,SEQ_IN_INDEX',
        'keys'=>'SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,ORDINAL_POSITION,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION',
        'foreign_keys'=>'SELECT TABLE_NAME,CONSTRAINT_NAME,REFERENCED_TABLE_NAME,UPDATE_RULE,DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? ORDER BY TABLE_NAME,CONSTRAINT_NAME',
        'checks'=>'SELECT TABLE_NAME,CONSTRAINT_NAME,CHECK_CLAUSE FROM information_schema.CHECK_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=? ORDER BY TABLE_NAME,CONSTRAINT_NAME',
        'triggers'=>'SELECT TRIGGER_NAME,ACTION_TIMING,EVENT_MANIPULATION,EVENT_OBJECT_TABLE,ACTION_STATEMENT FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? ORDER BY TRIGGER_NAME',
    ];
    foreach($queries as $k=>$sql)$r[$k]=rows($p,$sql,[$name]);
    $r['ddl']=[];
    foreach($r['tables'] as $t){$ddl=rows($p,'SHOW CREATE TABLE '.ident($t[0]))[0][1];$r['ddl'][$t[0]]=preg_replace('/ AUTO_INCREMENT=\d+/','',$ddl);}
    return $r;
}
function snapshot(PDO $p, string $name): array {
    $p->exec('START TRANSACTION READ ONLY');
    try{$r=['schema'=>metadata($p,$name),'row_counts'=>[],'checksums'=>[]];foreach($r['schema']['tables'] as $t){$r['row_counts'][$t[0]]=(int)$p->query('SELECT COUNT(*) FROM '.ident($t[0]))->fetchColumn();$r['checksums'][$t[0]]=rows($p,'CHECKSUM TABLE '.ident($t[0]))[0][1];}$r['migrations']=rows($p,'SELECT migration,batch FROM migrations ORDER BY id');return $r;}finally{$p->rollBack();}
}
function sourceConfig(string $root): array {
    if(is_file($root.'/bootstrap/cache/config.php'))throw new RuntimeException('Cached source configuration requires review');
    $e=Dotenv\Dotenv::parse(file_get_contents($root.'/.env'));
    $g=fn($k,$d=null)=>getenv($k)!==false?getenv($k):($e[$k]??$d);
    if($g('DB_URL')||$g('DATABASE_URL')||$g('DB_CONNECTION')!=='mysql'||$g('DB_DATABASE')!==OPERATIONAL_DB||$g('DB_HOST')!=='127.0.0.1'||$g('DB_PORT')!=='3306')throw new RuntimeException('Source configuration guard');
    return ['host'=>'127.0.0.1','port'=>3306,'database'=>OPERATIONAL_DB,'user'=>$g('DB_USERNAME'),'password'=>$g('DB_PASSWORD')];
}
function client(array $c,string $private,string $program,array $args,?string $input=null): void {
    $defaults=$private.'/client-'.bin2hex(random_bytes(6)).'.cnf';
    $escape=fn($v)=>'"'.str_replace(['\\','"',"\r","\n"],['\\\\','\\"','\\r','\\n'],(string)$v).'"';
    $f=fopen($defaults,'x');fwrite($f,"[client]\nhost=127.0.0.1\nport=3306\nprotocol=tcp\nuser=".$escape($c['user'])."\npassword=".$escape($c['password'])."\n");fclose($f);
    try{
        $cmd=array_merge(['C:/xampp/mysql/bin/'.$program.'.exe','--defaults-extra-file='.$defaults],$args);
        $inputHandle=$input===null?['pipe','r']:['file',$input,'r'];
        $proc=proc_open($cmd,[0=>$inputHandle,1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($proc))throw new RuntimeException('Client launch failed');
        if(isset($pipes[0]))fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
        if($code!==0){file_put_contents($private.'/client-error-'.bin2hex(random_bytes(4)).'.log',$err);throw new RuntimeException('Database client failed; private diagnostics retained');}
    }finally{if(is_file($defaults))unlink($defaults);}
}
function guardQa(array $c): PDO {
    if(($c['database']??'')===OPERATIONAL_DB||!preg_match('/^promerty_(qa|recovery_p4_[a-f0-9]{8}|verify_p4_[a-f0-9]{8})$/D',$c['database']??''))throw new RuntimeException('Non-QA target rejected');
    $p=db($c);$p->exec("SET SESSION sql_mode='STRICT_TRANS_TABLES,ERROR_FOR_DIVISION_BY_ZERO,NO_ENGINE_SUBSTITUTION'");$grants=rows($p,'SHOW GRANTS');$schemaGrants=[];
    foreach($grants as $r){$g=$r[0];if(str_contains($g,' WITH GRANT OPTION'))throw new RuntimeException('QA grant option rejected');if(str_starts_with($g,'GRANT USAGE ON *.* '))continue;if(preg_match('/^GRANT .+ ON `([^`]+)`\.\* TO /',$g,$m)){$scope=str_replace('\\','',$m[1]);if($scope!==$c['database'])throw new RuntimeException('Cross-schema QA grant rejected');$schemaGrants[]=$scope;}else throw new RuntimeException('Unexpected global, table, routine, proxy, or role grant rejected');}
    if($schemaGrants!==[$c['database']])throw new RuntimeException('Missing/excess QA schema grant');
    $global=rows($p,'SELECT PRIVILEGE_TYPE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE=CONCAT(CHAR(39),SUBSTRING_INDEX(CURRENT_USER(),CHAR(64),1),CHAR(39),CHAR(64),CHAR(39),SUBSTRING_INDEX(CURRENT_USER(),CHAR(64),-1),CHAR(39)) AND PRIVILEGE_TYPE<>\'USAGE\'');
    if($global)throw new RuntimeException('Effective global privilege rejected');
    $denied=false;try{$p->query('SELECT COUNT(*) FROM promerty.roles')->fetchColumn();}catch(PDOException $e){$denied=in_array($e->errorInfo[1]??null,[1044,1142],true);}
    if(!$denied)throw new RuntimeException('Operational database readable by QA; stop');
    return $p;
}
function assertRestoreSql(string $file): void {
    $sql=file_get_contents($file);
    if($sql===false||preg_match('/\b(?:CREATE|DROP)\s+DATABASE\b|\bUSE\s+[`a-z]|`promerty`\s*\./i',$sql))throw new RuntimeException('Unsafe SQL restore scope');
}
if ($action === 'library') return;
try{
    if($action==='backup'){
        $c=sourceConfig($root);$p=db($c);$before=snapshot($p,OPERATIONAL_DB);
        if(count($before['schema']['tables'])!==45||count($before['schema']['columns'])!==399||count($before['schema']['foreign_keys'])!==69)throw new RuntimeException('Unexpected source counts');
        if($before['schema']['triggers']||rows($p,"SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA='promerty'")||rows($p,"SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA='promerty'"))throw new RuntimeException('Programmable objects need review');
        saveJson($private.'/source-before.json',$before);
        $flags=['--single-transaction','--quick','--skip-lock-tables','--skip-add-locks','--hex-blob','--default-character-set=utf8mb4','--skip-comments'];
        $full=$private.'/promerty-full.sql';$schema=$private.'/schema-only.sql';
        foreach([$full,$schema] as $f)if(file_exists($f))throw new RuntimeException('Backup destination exists');
        client($c,$private,'mysqldump',array_merge($flags,['--routines','--events','--triggers','--result-file='.$full,OPERATIONAL_DB]));
        client($c,$private,'mysqldump',array_merge($flags,['--no-data','--result-file='.$schema,OPERATIONAL_DB]));
        assertRestoreSql($full);assertRestoreSql($schema);
        $baseline=$root.'/database/schema/mysql-schema.sql';if(file_exists($baseline))throw new RuntimeException('Baseline already exists');
        $sql=preg_replace('/ AUTO_INCREMENT=\d+/','',file_get_contents($schema));
        foreach(rows($p,'SELECT id,migration,batch FROM migrations ORDER BY id')as$m)$sql.="\nINSERT INTO `migrations` (`id`,`migration`,`batch`) VALUES (".$m[0].','.$p->quote($m[1]).','.$m[2].");\n";
        if(!is_dir(dirname($baseline)))mkdir(dirname($baseline),0777,true);file_put_contents($baseline,$sql,LOCK_EX);
        $references=[];foreach(['roles','permissions','role_permissions']as$t){$stmt=$p->query('SELECT * FROM '.ident($t).' ORDER BY id');$references[$t]=$stmt->fetchAll(PDO::FETCH_ASSOC);}saveJson($private.'/reference-data.json',$references);
        $report=['source'=>OPERATIONAL_DB,'server'=>rows($p,'SELECT VERSION()')[0][0],'created_utc'=>gmdate(DATE_ATOM),'consistent_method'=>'mysqldump --single-transaction --quick --skip-lock-tables; all source tables InnoDB','files'=>[]];foreach([$full,$schema,$baseline]as$f)$report['files'][]=['path'=>$f,'bytes'=>filesize($f),'sha256'=>hash_file('sha256',$f)];
        saveJson($private.'/backup-evidence.json',$report);echo json_encode(['backup'=>'PASS','files'=>$report['files']]);
    }elseif($action==='provision'){
        $c=sourceConfig($root);$p=db($c);$suffix=substr(basename($private),-8);$targets=['qa'=>'promerty_qa','recovery'=>'promerty_recovery_p4_'.$suffix,'verify'=>'promerty_verify_p4_'.$suffix];
        foreach($targets as$key=>$name){if(rows($p,'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?',[$name]))throw new RuntimeException('QA target exists; refusing overwrite');$user='pm'.$key.'_'.$suffix;if(rows($p,'SELECT 1 FROM mysql.user WHERE User=?',[$user]))throw new RuntimeException('QA account exists');}
        $configs=[];foreach($targets as$key=>$name){$user='pm'.$key.'_'.$suffix;$secret=bin2hex(random_bytes(32));$account=$p->quote($user)."@'127.0.0.1'";$p->exec('CREATE DATABASE '.ident($name).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$p->exec('CREATE USER '.$account.' IDENTIFIED BY '.$p->quote($secret));$grantName=str_replace(['_','%'],['\\_','\\%'],$name);$p->exec('GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,INDEX,ALTER,REFERENCES,CREATE TEMPORARY TABLES,LOCK TABLES ON '.ident($grantName).'.* TO '.$account);$configs[$key]=['host'=>'127.0.0.1','port'=>3306,'database'=>$name,'user'=>$user,'password'=>$secret];saveJson($private.'/'.$key.'-connection.json',$configs[$key]);guardQa($configs[$key]);}
        saveJson($private.'/isolation-evidence.json',['targets'=>$targets,'separate_accounts'=>true,'global_privileges'=>false,'grant_option'=>false,'operational_select_denied'=>true,'credential_values_disclosed'=>false]);echo json_encode(['isolation'=>'PASS','targets'=>$targets]);
    }elseif($action==='restore'){
        $before=json_decode(file_get_contents($private.'/source-before.json'),true,512,JSON_THROW_ON_ERROR);$results=[];
        foreach(['recovery','qa']as$key){$c=json_decode(file_get_contents($private.'/'.$key.'-connection.json'),true,512,JSON_THROW_ON_ERROR);$p=guardQa($c);if(rows($p,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=?',[$c['database']]))throw new RuntimeException('Restore target not empty');$sql=$key==='recovery'?$private.'/promerty-full.sql':$root.'/database/schema/mysql-schema.sql';assertRestoreSql($sql);client($c,$private,'mysql',['--binary-mode','--default-character-set=utf8mb4','--database='.$c['database']],$sql);$after=snapshot($p,$c['database']);if($after['schema']!==$before['schema'])throw new RuntimeException('Restored schema mismatch: '.$key);if($after['migrations']!==$before['migrations'])throw new RuntimeException('Migration metadata mismatch');if($key==='recovery'&&($after['row_counts']!==$before['row_counts']||$after['checksums']!==$before['checksums']))throw new RuntimeException('Recovery content mismatch');if($key==='qa')foreach($after['row_counts']as$t=>$n)if($t!=='migrations'&&$n!==0)throw new RuntimeException('Operational data leaked into general QA');$results[$key]=['schema_equal'=>true,'migration_metadata_equal'=>true,'full_content_equal'=>$key==='recovery'?true:null,'operational_data_excluded'=>$key==='qa'?true:null];}
        saveJson($private.'/restore-evidence.json',$results);echo json_encode(['restore'=>'PASS','results'=>$results]);
    }elseif($action==='final'){
        $p=db(sourceConfig($root));$after=snapshot($p,OPERATIONAL_DB);$before=json_decode(file_get_contents($private.'/source-before.json'),true,512,JSON_THROW_ON_ERROR);$equal=$after===$before;saveJson($private.'/source-after.json',$after);saveJson($private.'/operational-integrity.json',['schema_unchanged'=>$after['schema']===$before['schema'],'row_counts_unchanged'=>$after['row_counts']===$before['row_counts'],'checksums_unchanged'=>$after['checksums']===$before['checksums'],'migration_metadata_unchanged'=>$after['migrations']===$before['migrations']]);if(!$equal)throw new RuntimeException('Operational snapshot changed; investigate external activity');echo json_encode(['operational_unchanged'=>'PASS']);
    }else throw new RuntimeException('Unknown action');
}catch(Throwable $e){fwrite(STDERR,json_encode(['status'=>'FAIL','reason'=>$e instanceof PDOException?'Database operation failed; credential output suppressed':$e->getMessage(),'type'=>get_class($e),'code'=>(string)$e->getCode()])."\n");exit(1);}
