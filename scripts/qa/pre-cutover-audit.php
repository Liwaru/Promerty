<?php
/** Explicit Phase 3B tooling. Main database is read-only; restore has a unique new target. */
declare(strict_types=1);
$project = dirname(__DIR__, 2);
$mode = $argv[1] ?? '';
$run = $argv[2] ?? '';
if (PHP_SAPI !== 'cli' || !in_array($mode, ['main', 'qa', 'qa-final', 'restore', 'verify'], true)
    || !is_dir($run) || !str_starts_with((string) realpath($run), realpath($project.'/storage/app/private').DIRECTORY_SEPARATOR)
    || !str_starts_with(basename($run), 'pre-cutover-3b-')) {
    fwrite(STDERR, "Explicit private Phase 3B run directory required.\n"); exit(1);
}
$args = $argv; $argv = [__FILE__, 'library', $run];
require $project.'/scripts/qa/phase4.php';
$argv = $args;
set_exception_handler(static function (Throwable $e): void {
    fwrite(STDERR, json_encode(['status'=>'FAIL', 'type'=>get_class($e), 'reason'=>$e instanceof PDOException ? 'Database operation failed; details suppressed' : $e->getMessage()])."\n"); exit(1);
});
function record3b(string $file, mixed $value): void {
    if (file_exists($file)) throw new RuntimeException('Refusing to overwrite evidence');
    file_put_contents($file, json_encode($value, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n", LOCK_EX);
}
function fingerprint3b(PDO $p, string $database): array {
    if ($p->query('SELECT DATABASE()')->fetchColumn() !== $database) throw new RuntimeException('Database identity mismatch');
    $p->exec('SET TRANSACTION READ ONLY');
    $p->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
    try {
        $schema = metadata($p, $database); $tables = [];
        foreach ($schema['tables'] as $table) {
            if ($table[1] !== 'InnoDB') throw new RuntimeException('Non-InnoDB table requires revised consistency plan');
            $hashes = [];
            $statement = $p->query('SELECT * FROM '.ident($table[0]));
            while ($row = $statement->fetch(PDO::FETCH_NUM)) {
                $cells = array_map(static fn ($v) => $v === null ? null : base64_encode((string) $v), $row);
                $hashes[] = hash('sha256', json_encode($cells, JSON_THROW_ON_ERROR));
            }
            sort($hashes, SORT_STRING);
            $tables[$table[0]] = ['rows'=>count($hashes), 'sha256'=>hash('sha256', implode("\n", $hashes))];
        }
        return ['method'=>'sha256-v1: SELECT * column order; NULL or base64(raw cell bytes); JSON row; sorted row SHA256 with newline separators; duplicates retained',
            'database'=>$database, 'schemaSha256'=>hash('sha256', json_encode($schema, JSON_THROW_ON_ERROR)), 'tables'=>$tables,
            'dataSha256'=>hash('sha256', json_encode($tables, JSON_THROW_ON_ERROR)), 'allInnoDb'=>true];
    } finally { $p->rollBack(); }
}
function compare3b(array $a, array $b): array {
    $changed=[]; foreach ($a['tables'] as $name=>$value) if (($b['tables'][$name]??null) !== $value) $changed[]=$name;
    foreach (array_diff(array_keys($b['tables']), array_keys($a['tables'])) as $name) $changed[]=$name;
    return ['schemaEqual'=>$a['schemaSha256']===$b['schemaSha256'], 'changedTables'=>array_values(array_unique($changed)), 'dataEqual'=>$a['tables']===$b['tables']];
}
function qaConfig3b(string $project): array {
    $c=json_decode(file_get_contents($project.'/storage/app/private/phase4-20261110-73e56946/qa-connection.json'),true,512,JSON_THROW_ON_ERROR);
    if ($c['database']!=='promerty_qa') throw new RuntimeException('QA source guard');
    return $c;
}
function checkForeignKeys3b(PDO $p, string $name): array {
    $groups=[];
    foreach (rows($p, 'SELECT TABLE_NAME,CONSTRAINT_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=? AND REFERENCED_TABLE_NAME IS NOT NULL ORDER BY TABLE_NAME,CONSTRAINT_NAME,ORDINAL_POSITION',[$name]) as $r) $groups[$r[0].'/'.$r[1]][]=$r;
    $violations=[];
    foreach($groups as $key=>$parts) {
        $join=[];$nonnull=[];
        foreach($parts as $r){$join[]='c.'.ident($r[2]).'=p.'.ident($r[4]);$nonnull[]='c.'.ident($r[2]).' IS NOT NULL';}
        $sql='SELECT COUNT(*) FROM '.ident($parts[0][0]).' c LEFT JOIN '.ident($parts[0][3]).' p ON '.implode(' AND ',$join).' WHERE '.implode(' AND ',$nonnull).' AND p.'.ident($parts[0][4]).' IS NULL';
        $n=(int)$p->query($sql)->fetchColumn();if($n)$violations[$key]=$n;
    }
    return ['constraintsChecked'=>count($groups),'violations'=>$violations];
}
if ($mode==='main') {
    $p=db(sourceConfig($project)); $fp=fingerprint3b($p,'promerty');
    $label=$argv[3]??'before';if(!in_array($label,['before','after'],true))throw new RuntimeException('Invalid evidence label');
    record3b($run.'/main-'.$label.'-fingerprint.json',$fp);
    $current=snapshot($p,'promerty');
    $paths=['developmentAccounts'=>$project.'/storage/app/private/development-login-20261113/after.json',
        'phase3a'=>sys_get_temp_dir().'/promerty-apache-phase3a-20261116/resume-main-snapshot-private.json'];
    $history=[];
    foreach($paths as $tag=>$path) {
        if(!is_file($path)){$history[$tag]=['available'=>false];continue;}
        $old=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$diff=[];
        foreach(['schema','row_counts','checksums','migrations'] as $section) {
            foreach(array_unique(array_merge(array_keys($old[$section]??[]),array_keys($current[$section]))) as $k)
                if(($old[$section][$k]??null)!==($current[$section][$k]??null))$diff[$section][]=$k;
        }
        $history[$tag]=['available'=>true,'differences'=>$diff,'historicalEvidence'=>'schema metadata, row counts and MariaDB CHECKSUM TABLE; no historical SHA256 row baseline'];
    }
    $env=Dotenv\Dotenv::parse(file_get_contents($project.'/.env'));
    $transient=['sessionDriver'=>$env['SESSION_DRIVER']??'database','cacheStore'=>$env['CACHE_STORE']??'database',
        'sessions'=>['rows'=>0,'loopbackRows'=>0,'authenticatedRows'=>0,'csrfPayloadRows'=>0,'chromeAgentRows'=>0,'lastActivityUtc'=>[]],
        'cache'=>['rows'=>0,'expiredRows'=>0,'integerValues'=>0,'timerKeyRows'=>0,'opaqueHashSuffixRows'=>0]];
    foreach($p->query('SELECT ip_address,user_agent,payload,last_activity,user_id FROM sessions') as $r) {
        $s=&$transient['sessions'];$s['rows']++;if(in_array($r['ip_address'],['127.0.0.1','::1'],true))$s['loopbackRows']++;
        if($r['user_id']!==null)$s['authenticatedRows']++;if(str_contains((string)$r['user_agent'],'Chrome/'))$s['chromeAgentRows']++;
        $payload=base64_decode((string)$r['payload'],true);$data=$payload===false?false:@unserialize($payload,['allowed_classes'=>false]);
        if(is_array($data)&&isset($data['_token']))$s['csrfPayloadRows']++;$s['lastActivityUtc'][]=gmdate(DATE_ATOM,(int)$r['last_activity']);unset($s);
    }
    foreach($p->query('SELECT `key`,value,expiration FROM cache') as $r) {
        $c=&$transient['cache'];$c['rows']++;if((int)$r['expiration']<time())$c['expiredRows']++;
        $v=@unserialize((string)$r['value'],['allowed_classes'=>false]);if(is_int($v))$c['integerValues']++;
        if(str_ends_with($r['key'],':timer'))$c['timerKeyRows']++;if(preg_match('/[a-f0-9]{32,64}(:timer)?$/i',$r['key']))$c['opaqueHashSuffixRows']++;unset($c);
    }
    $result=['server'=>rows($p,'SELECT VERSION()')[0][0],'schemaTables'=>count($fp['tables']),'history'=>$history,'transient'=>$transient,
        'attribution'=>'Configuration and payload structure support normal Laravel writes; individual historical writer/request not proven. No session IDs, tokens, cache keys or values exported.'];
    if($label==='after')$result['duringPhase3b']=compare3b(json_decode(file_get_contents($run.'/main-before-fingerprint.json'),true,512,JSON_THROW_ON_ERROR),$fp);
    record3b($run.'/main-'.$label.'-reconciliation.json',$result);echo json_encode($result,JSON_THROW_ON_ERROR)."\n";exit;
}
if ($mode==='qa') {
    $c=qaConfig3b($project);$p=guardQa($c);$fp=fingerprint3b($p,$c['database']);record3b($run.'/qa-before-fingerprint.json',$fp);
    $schema=metadata($p,$c['database']);$result=['server'=>rows($p,'SELECT VERSION()')[0][0],'fingerprint'=>$fp,'foreignKeys'=>checkForeignKeys3b($p,$c['database']),
        'programmableObjects'=>['triggers'=>count($schema['triggers']),'routines'=>rows($p,'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=?',[$c['database']]),'events'=>rows($p,'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=?',[$c['database']])],
        'dataBytes'=>rows($p,'SELECT COALESCE(SUM(DATA_LENGTH+INDEX_LENGTH),0) FROM information_schema.TABLES WHERE TABLE_SCHEMA=?',[$c['database']])[0][0]];
    record3b($run.'/qa-audit.json',$result);echo json_encode(['database'=>$c['database'],'tables'=>count($fp['tables']),'foreignKeys'=>$result['foreignKeys'],'programmableObjects'=>$result['programmableObjects']])."\n";exit;
}
if ($mode==='qa-final') {
    $c=qaConfig3b($project);$p=guardQa($c);$before=json_decode(file_get_contents($run.'/qa-before-fingerprint.json'),true,512,JSON_THROW_ON_ERROR);
    $result=['database'=>'promerty_qa','comparison'=>compare3b($before,fingerprint3b($p,'promerty_qa'))];
    record3b($run.'/qa-final-integrity.json',$result);echo json_encode($result)."\n";exit;
}
if ($mode==='restore') {
    if(is_file($run.'/restore-target.json'))throw new RuntimeException('Restore run already provisioned');
    $qa=qaConfig3b($project);$source=guardQa($qa);$audit=json_decode(file_get_contents($run.'/qa-audit.json'),true,512,JSON_THROW_ON_ERROR);
    if($audit['programmableObjects']['triggers']||$audit['programmableObjects']['routines']||$audit['programmableObjects']['events'])throw new RuntimeException('Programmable objects require revised review');
    $admin=db(sourceConfig($project));
    $objects=['triggers'=>rows($admin,'SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=?',['promerty_qa']),
        'routines'=>rows($admin,'SELECT ROUTINE_NAME FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA=?',['promerty_qa']),
        'events'=>rows($admin,'SELECT EVENT_NAME FROM information_schema.EVENTS WHERE EVENT_SCHEMA=?',['promerty_qa'])];
    if($objects['triggers']||$objects['routines']||$objects['events'])throw new RuntimeException('Privileged metadata found programmable objects; revise backup plan');
    record3b($run.'/restore-programmable-preflight.json',['schema'=>'promerty_qa','privilegedMetadataReadOnly'=>true,'objects'=>$objects]);
    $backup=$run.'/synthetic-qa-verified.sql';if(file_exists($backup))throw new RuntimeException('Backup already exists');$start=microtime(true);
    // No extra EVENT/TRIGGER privilege granted: authoritative metadata proves none to back up.
    client($qa,$run,'mysqldump',['--single-transaction','--quick','--skip-lock-tables','--skip-add-locks','--hex-blob','--default-character-set=utf8mb4','--skip-comments','--skip-routines','--skip-events','--skip-triggers','--result-file='.$backup,'promerty_qa']);
    $dumpSeconds=microtime(true)-$start;$sql=file_get_contents($backup);
    if(preg_match('/\b(?:CREATE|DROP|ALTER)\s+DATABASE\b|\bUSE\s+[`a-z]|\b(?:GRANT|CREATE\s+USER)\b|`(?:promerty|promerty_qa)`\s*\./i',$sql))throw new RuntimeException('Unsafe restore SQL scope');
    $sourceAfter=fingerprint3b($source,'promerty_qa');$before=json_decode(file_get_contents($run.'/qa-before-fingerprint.json'),true,512,JSON_THROW_ON_ERROR);
    $sourceComparison=compare3b($before,$sourceAfter);
    if(!$sourceComparison['dataEqual']||!$sourceComparison['schemaEqual'])throw new RuntimeException('QA source changed during backup; preserve dump for investigation');
    $suffix=substr(hash('sha256',basename($run)),0,8);$name='promerty_restore_3b_'.$suffix;$user='pm3b_'.$suffix;
    if(rows($admin,'SELECT SCHEMA_NAME FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=?',[$name])||rows($admin,'SELECT 1 FROM mysql.user WHERE User=?',[$user]))throw new RuntimeException('Destination conflict; no overwrite');
    $secret=bin2hex(random_bytes(32));$account=$admin->quote($user)."@'127.0.0.1'";
    $admin->exec('CREATE DATABASE '.ident($name).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $admin->exec('CREATE USER '.$account.' IDENTIFIED BY '.$admin->quote($secret));
    $admin->exec('GRANT SELECT,INSERT,UPDATE,DELETE,CREATE,DROP,INDEX,ALTER,REFERENCES,CREATE TEMPORARY TABLES,LOCK TABLES ON '.ident(str_replace('_','\\_',$name)).'.* TO '.$account);
    $target=['host'=>'127.0.0.1','port'=>3306,'database'=>$name,'user'=>$user,'password'=>$secret];record3b($run.'/restore-connection.json',$target);
    record3b($run.'/restore-target.json',['database'=>$name,'principal'=>$user,'createdBy'=>'Phase 3B isolated synthetic restore','cleanupAuthorized'=>false]);
    $p=db($target);$denied=[];foreach(['promerty','promerty_qa'] as $protected){try{$p->query('SELECT 1 FROM '.ident($protected).'.users LIMIT 0');$denied[$protected]=false;}catch(PDOException $e){$denied[$protected]=in_array((int)($e->errorInfo[1]??0),[1044,1142],true);}}
    if(in_array(false,$denied,true))throw new RuntimeException('Restore principal isolation failed');
    $start=microtime(true);client($target,$run,'mysql',['--binary-mode','--default-character-set=utf8mb4','--database='.$name],$backup);$restoreSeconds=microtime(true)-$start;
    $restored=fingerprint3b($p,$name);$comparison=compare3b($sourceAfter,$restored);$fk=checkForeignKeys3b($p,$name);
    $result=['database'=>$name,'source'=>'promerty_qa','backupBytes'=>filesize($backup),'backupSha256'=>hash_file('sha256',$backup),'dumpSeconds'=>$dumpSeconds,'restoreSeconds'=>$restoreSeconds,'comparison'=>$comparison,'foreignKeys'=>$fk,'protectedDatabaseSelectDenied'=>$denied,'cleanupPerformed'=>false];
    record3b($run.'/restore-fingerprint.json',$restored);record3b($run.'/restore-result.json',$result);
    if(!$comparison['schemaEqual']||!$comparison['dataEqual']||$fk['violations'])throw new RuntimeException('Restored data/schema/FK validation failed');echo json_encode($result)."\n";exit;
}
if ($mode==='verify') {
    $target=json_decode(file_get_contents($run.'/restore-connection.json'),true,512,JSON_THROW_ON_ERROR);
    $owned=json_decode(file_get_contents($run.'/restore-target.json'),true,512,JSON_THROW_ON_ERROR);
    if($target['database']!==$owned['database']||!preg_match('/^promerty_restore_3b_[a-f0-9]{8}$/D',$target['database']))throw new RuntimeException('Owned target guard');
    // A fresh Laravel instance with no production .env bootstrap or global environment writes.
    $runtime=$run.'/laravel';foreach(['bootstrap','views','sessions','cache','logs']as$d)if(!is_dir($runtime.'/'.$d))mkdir($runtime.'/'.$d,0700,true);
    $vars=['APP_ENV'=>'restore_qa','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(random_bytes(32)),'APP_URL'=>'http://127.0.0.1',
        'DB_CONNECTION'=>'mysql','DB_HOST'=>'127.0.0.1','DB_PORT'=>'3306','DB_DATABASE'=>$target['database'],'DB_USERNAME'=>$target['user'],'DB_PASSWORD'=>$target['password'],'DB_URL'=>'','DATABASE_URL'=>'','DB_SOCKET'=>'',
        'SESSION_DRIVER'=>'array','CACHE_STORE'=>'array','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync','LOG_CHANNEL'=>'stderr','VIEW_COMPILED_PATH'=>$runtime.'/views'];
    foreach(['CONFIG','PACKAGES','SERVICES','ROUTES','EVENTS']as$v)$vars['APP_'.$v.'_CACHE']=$runtime.'/bootstrap/'.strtolower($v).'.php';
    Illuminate\Support\Env::disablePutenv();foreach($vars as$k=>$v){$_ENV[$k]=$v;$_SERVER[$k]=$v;}
    $app=require $project.'/bootstrap/app.php';$app->useStoragePath($runtime);$app->useEnvironmentPath($runtime);$app->loadEnvironmentFrom('no-main-env');$app->addAbsoluteCachePathPrefix(substr($runtime,0,3));$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() db')->db!==$target['database'])throw new RuntimeException('Laravel restore DB guard');
    $logins=[];foreach(['customer','agent','branch','admin','owner']as$name){$u=App\Models\User::where('email',$name.'@gmail.com')->first();$guard=Illuminate\Support\Facades\Auth::guard('web');$ok=$u?$guard->attempt(['email'=>$name.'@gmail.com','password'=>$name,'status'=>'active']):false;$logins[]=['account'=>$name,'available'=>(bool)$u,'guardLogin'=>$ok,'sessionAuthenticated'=>$guard->check(),'role'=>$guard->user()?->role?->name];$guard->logout();}
    $p=db($target);$before=json_decode(file_get_contents($run.'/restore-fingerprint.json'),true,512,JSON_THROW_ON_ERROR);$after=fingerprint3b($p,$target['database']);
    $out=['laravelBootstrap'=>'PASS','database'=>$target['database'],'qaCredentialHashChecks'=>$logins,'webSessionLoginTested'=>false,'afterBootstrap'=>compare3b($before,$after),'emailsSent'=>false];record3b($run.'/laravel-restore-result.json',$out);echo json_encode($out)."\n";
}
