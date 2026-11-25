<?php
declare(strict_types=1);
$run=$argv[1]??'';$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
function laravelQa(string $private,string $target,string $operation,string $probe=''): array {
    $cmd=[PHP_BINARY,__DIR__.'/phase4-laravel.php',$private,$target,$operation,$probe];
    $p=proc_open($cmd,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    if($exit!==0){file_put_contents($private.'/laravel-failure-'.bin2hex(random_bytes(3)).'.txt',$out."\n".$err);throw new RuntimeException('Isolated Laravel test failed; private diagnostics retained');}
    return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function insertQa(PDO $p,string $table,array $values): int {
    $cols=array_keys($values);$s=$p->prepare('INSERT INTO '.ident($table).' ('.implode(',',array_map('ident',$cols)).') VALUES ('.implode(',',array_fill(0,count($cols),'?')).')');$s->execute(array_values($values));return (int)$p->lastInsertId();
}
try {
    $before=json_decode(file_get_contents($private.'/source-before.json'),true,512,JSON_THROW_ON_ERROR);$configs=[];foreach(['qa','verify']as$key)$configs[$key]=json_decode(file_get_contents($private.'/'.$key.'-connection.json'),true,512,JSON_THROW_ON_ERROR);
    $qa=guardQa($configs['qa']);$verify=guardQa($configs['verify']);
    if(rows($verify,'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=?',[$configs['verify']['database']]))throw new RuntimeException('Disposable target is not empty');
    $results=[];$results['qa_migration_noop']=laravelQa($private,'qa','migrate');
    if(snapshot($qa,$configs['qa']['database'])['schema']!==$before['schema'])throw new RuntimeException('QA no-op changed schema');
    $results['empty_database_baseline']=laravelQa($private,'verify','migrate');
    if(snapshot($verify,$configs['verify']['database'])['schema']!==$before['schema'])throw new RuntimeException('Laravel baseline schema differs');
    $results['repeat_migration_noop']=laravelQa($private,'verify','migrate');
    if(snapshot($verify,$configs['verify']['database'])['migrations']!==$before['migrations'])throw new RuntimeException('Historical migration metadata differs');
    $probe=$private.'/migration-probe';if(is_dir($probe))throw new RuntimeException('Probe already exists');mkdir($probe);
    $expected=var_export($configs['verify']['database'],true);
    $code='<?php return new class extends \\Illuminate\\Database\\Migrations\\Migration { public function up(): void { if(config("database.connections.mysql.database") !== '.$expected.') throw new \\RuntimeException("QA guard"); \\Illuminate\\Support\\Facades\\Schema::create("phase4_probe", function(\\Illuminate\\Database\\Schema\\Blueprint $t){$t->id();$t->string("marker")->unique();}); } public function down(): void { if(config("database.connections.mysql.database") !== '.$expected.') throw new \\RuntimeException("QA guard"); \\Illuminate\\Support\\Facades\\Schema::drop("phase4_probe"); }};';
    file_put_contents($probe.'/2026_11_10_000000_phase4_probe.php',$code);
    $results['incremental_up']=laravelQa($private,'verify','probe-up',$probe);$results['incremental_down']=laravelQa($private,'verify','probe-down',$probe);
    $after=snapshot($verify,$configs['verify']['database']);if($after['schema']!==$before['schema']||$after['migrations']!==$before['migrations'])throw new RuntimeException('Probe rollback failed to restore baseline');
    saveJson($private.'/migration-evidence.json',$results);
    if((int)$qa->query('SELECT COUNT(*) FROM users')->fetchColumn()!==0||(int)$qa->query('SELECT COUNT(*) FROM roles')->fetchColumn()!==0)throw new RuntimeException('QA fixture destination contains data');
    $qa->beginTransaction();
    try{
        $refs=json_decode(file_get_contents($private.'/reference-data.json'),true,512,JSON_THROW_ON_ERROR);foreach($refs as$table=>$records)foreach($records as$record)insertQa($qa,$table,$record);
        $now=gmdate('Y-m-d H:i:s');$time=['created_at'=>$now,'updated_at'=>$now];
        $agency=insertQa($qa,'agencies',['name'=>'SYNTHETIC QA Agency','slug'=>'synthetic-qa-agency','status'=>'active',...$time]);
        $branch=insertQa($qa,'branches',['agency_id'=>$agency,'name'=>'Cabang QA Sintetis','status'=>'active',...$time]);
        $users=[];foreach(['customer','agent','branch_manager','admin','super_admin','owner']as$role){$roleId=(int)$qa->query('SELECT id FROM roles WHERE name='.$qa->quote($role))->fetchColumn();$users[$role]=insertQa($qa,'users',['role_id'=>$roleId,'branch_id'=>in_array($role,['agent','branch_manager'])?$branch:null,'name'=>'SYNTHETIC QA '.$role,'username'=>'qa_'.$role,'email'=>$role.'.phase4@example.test','password'=>password_hash(bin2hex(random_bytes(24)),PASSWORD_BCRYPT),'status'=>'active',...$time]);}
        insertQa($qa,'agent_profiles',['user_id'=>$users['agent'],'professional_name'=>'SYNTHETIC QA Agent','verification_status'=>'verified','verified_by'=>$users['admin'],'verified_at'=>$now,...$time]);
        $categories=[];foreach(['rumah','ruko','gudang','apartemen']as$name)$categories[$name]=insertQa($qa,'property_categories',['name'=>ucfirst($name),'slug'=>$name,...$time]);
        $location=insertQa($qa,'locations',['label'=>'LOKASI SINTETIS QA - BUKAN LISTING NYATA','city'=>'Kota QA','latitude'=>0,'longitude'=>0,'public_latitude'=>0,'public_longitude'=>0,'public_radius_m'=>1000,'visibility'=>'approximate','created_by'=>$users['admin'],...$time]);
        $developer=insertQa($qa,'developers',['name'=>'Developer Sintetis QA','created_by'=>$users['admin'],...$time]);
        $project=insertQa($qa,'projects',['developer_id'=>$developer,'location_id'=>$location,'name'=>'Project Sintetis QA','status'=>'active','created_by'=>$users['admin'],...$time]);
        $secondary=insertQa($qa,'secondary_properties',['property_code'=>'QA-SECONDARY-001','category_id'=>$categories['ruko'],'location_id'=>$location,'title'=>'Properti Secondary Sintetis QA','created_by'=>$users['agent'],...$time]);
        $common=['agent_id'=>$users['agent'],'branch_id'=>$branch,'status'=>'active',...$time];
        $primaryListing=insertQa($qa,'listings',['listing_code'=>'QA-PRIMARY-SALE','listing_type'=>'primary','transaction_type'=>'sale','category_id'=>$categories['rumah'],'project_id'=>$project,'title'=>'SYNTHETIC QA Rumah Primary Dijual','price'=>'1200000000.00','price_period'=>'total',...$common]);
        $secondaryListing=insertQa($qa,'listings',['listing_code'=>'QA-SECONDARY-RENT','listing_type'=>'secondary','transaction_type'=>'rent','category_id'=>$categories['ruko'],'secondary_property_id'=>$secondary,'title'=>'SYNTHETIC QA Ruko Secondary Disewa','price'=>'50000000.00','price_period'=>'year',...$common]);
        $postCommon=['user_id'=>$users['agent'],'status'=>'published','moderation_status'=>'active','published_at'=>$now,...$time];
        $video=insertQa($qa,'posts',['listing_id'=>$primaryListing,'caption'=>'Data sintetis QA; tidak mewakili properti perusahaan.','content_type'=>'video',...$postCommon]);
        $photo=insertQa($qa,'posts',['listing_id'=>$secondaryListing,'caption'=>'Data sintetis QA; media bukan unggahan nyata.','content_type'=>'photo',...$postCommon]);
        insertQa($qa,'post_media',['post_id'=>$video,'media_type'=>'video','file_url'=>'qa-only/synthetic-placeholder.mp4','sort_order'=>1,'width'=>1080,'height'=>1920,'duration_seconds'=>30,...$time]);
        insertQa($qa,'post_media',['post_id'=>$photo,'media_type'=>'image','file_url'=>'qa-only/synthetic-placeholder.jpg','sort_order'=>1,'width'=>1200,'height'=>800,...$time]);
        insertQa($qa,'likes',['user_id'=>$users['customer'],'post_id'=>$video,'created_at'=>$now]);insertQa($qa,'saves',['user_id'=>$users['customer'],'post_id'=>$video,'created_at'=>$now]);insertQa($qa,'follows',['follower_id'=>$users['customer'],'following_id'=>$users['agent'],'created_at'=>$now]);insertQa($qa,'comments',['user_id'=>$users['customer'],'post_id'=>$video,'content'=>'Komentar sintetis untuk QA.',...$time]);
        $conversation=insertQa($qa,'conversations',['type'=>'direct','created_by'=>$users['customer'],'status'=>'active',...$time]);
        $member=insertQa($qa,'conversation_members',['conversation_id'=>$conversation,'user_id'=>$users['customer'],'member_role'=>'customer','joined_at'=>$now,...$time]);insertQa($qa,'conversation_members',['conversation_id'=>$conversation,'user_id'=>$users['agent'],'member_role'=>'agent','joined_at'=>$now,...$time]);
        insertQa($qa,'messages',['conversation_id'=>$conversation,'sender_member_id'=>$member,'message_type'=>'text','body'=>'Pesan sintetis QA.','listing_id'=>$primaryListing,...$time]);insertQa($qa,'survey_requests',['customer_id'=>$users['customer'],'agent_id'=>$users['agent'],'listing_id'=>$primaryListing,'requested_at'=>gmdate('Y-m-d H:i:s',time()+7*86400),'note'=>'Survei sintetis QA, bukan janji nyata.','status'=>'pending',...$time]);
        $qa->commit();
    }catch(Throwable $e){if($qa->inTransaction())$qa->rollBack();throw $e;}
    $constraintTests=[];
    $expectedCodes=['fk'=>1452,'unique'=>1062,'enum'=>1265,'not_null'=>1048,'json_check'=>4025];
    foreach(['fk'=>fn()=>insertQa($qa,'likes',['user_id'=>999999999,'post_id'=>$video,'created_at'=>$now]),'unique'=>fn()=>insertQa($qa,'likes',['user_id'=>$users['customer'],'post_id'=>$video,'created_at'=>$now]),'enum'=>fn()=>insertQa($qa,'posts',['listing_id'=>$primaryListing,'user_id'=>$users['agent'],'content_type'=>'invalid',...$time]),'not_null'=>fn()=>insertQa($qa,'listings',['listing_code'=>'QA-INVALID-NULL','listing_type'=>'primary','agent_id'=>$users['agent'],'branch_id'=>$branch,'category_id'=>$categories['rumah'],'title'=>null,...$time]),'json_check'=>fn()=>insertQa($qa,'notifications',['user_id'=>$users['customer'],'type'=>'qa','title'=>'QA','message'=>'QA','data'=>'{invalid','created_at'=>$now])]as$name=>$test){$qa->beginTransaction();try{$test();throw new RuntimeException('Constraint accepted invalid QA data: '.$name);}catch(PDOException $e){$driverCode=$e->errorInfo[1]??null;if($driverCode!==$expectedCodes[$name])throw new RuntimeException('Unexpected rejection reason: '.$name);$constraintTests[$name]=['rejected'=>true,'driver_code'=>$driverCode];}finally{if($qa->inTransaction())$qa->rollBack();}}
    $qa->beginTransaction();try{$id=insertQa($qa,'post_media',['post_id'=>$video,'media_type'=>'video','file_url'=>'qa-only/default-check.mp4','sort_order'=>2,...$time]);$nullable=$qa->query('SELECT thumbnail_url FROM post_media WHERE id='.$id)->fetchColumn();if($nullable!==null)throw new RuntimeException('Nullable mismatch');$constraintTests['nullable']=['pass'=>true];$id=insertQa($qa,'posts',['listing_id'=>$primaryListing,'user_id'=>$users['agent'],'content_type'=>'photo',...$time]);$defaults=rows($qa,'SELECT status,moderation_status FROM posts WHERE id=?',[$id])[0];if($defaults!==['draft','active'])throw new RuntimeException('Default mismatch');$constraintTests['defaults']=['pass'=>true];}finally{$qa->rollBack();}
    $fixtureSnapshot=snapshot($qa,$configs['qa']['database']);if($fixtureSnapshot['schema']!==$before['schema'])throw new RuntimeException('Fixture altered schema');
    saveJson($private.'/qa-fixture-evidence.json',['synthetic'=>true,'external_apis_used'=>false,'media_files_are_placeholders'=>true,'credentials_disclosed'=>false,'row_counts'=>$fixtureSnapshot['row_counts'],'constraints'=>$constraintTests]);
    echo json_encode(['migration'=>'PASS','synthetic_fixture'=>'PASS','constraint_tests'=>$constraintTests,'qa_row_counts'=>$fixtureSnapshot['row_counts']]);
}catch(Throwable $e){fwrite(STDERR,json_encode(['status'=>'FAIL','reason'=>$e instanceof PDOException?'QA database operation failed':$e->getMessage(),'type'=>get_class($e),'code'=>(string)$e->getCode()])."\n");exit(1);}
