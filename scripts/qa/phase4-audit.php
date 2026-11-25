<?php
/** Read-only post-fixture audit and guard regression checks. */
declare(strict_types=1);
$run=$argv[1]??'';$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
try{
    $c=json_decode(file_get_contents($private.'/qa-connection.json'),true,512,JSON_THROW_ON_ERROR);$p=guardQa($c);$s=snapshot($p,$c['database']);$p->exec('START TRANSACTION READ ONLY');$orphans=[];
    foreach($s['schema']['keys']as$k){if(!$k[4])continue;$orphans[$k[1]]=(int)$p->query('SELECT COUNT(*) FROM '.ident($k[0]).' c LEFT JOIN '.ident($k[4]).' p ON c.'.ident($k[2]).'=p.'.ident($k[5]).' WHERE c.'.ident($k[2]).' IS NOT NULL AND p.'.ident($k[5]).' IS NULL')->fetchColumn();}
    $unique=[];foreach($s['schema']['indexes']as$i){if((int)$i[2]!==0)continue;$unique[$i[0].'.'.$i[1]]['table']=$i[0];$unique[$i[0].'.'.$i[1]]['columns'][]=$i[4];}$duplicates=[];
    foreach($unique as$name=>$i){$cols=array_map('ident',$i['columns']);$where=implode(' AND ',array_map(fn($col)=>$col.' IS NOT NULL',$cols));$duplicates[$name]=(int)$p->query('SELECT COUNT(*) FROM (SELECT 1 FROM '.ident($i['table']).' WHERE '.$where.' GROUP BY '.implode(',',$cols).' HAVING COUNT(*)>1) q')->fetchColumn();}
    $queries=[
        'listing_targets'=>"SELECT COUNT(*) FROM listings WHERE NOT ((listing_type='primary' AND project_id IS NOT NULL AND secondary_property_id IS NULL) OR (listing_type='secondary' AND project_id IS NULL AND secondary_property_id IS NOT NULL))",
        'agent_branch'=>"SELECT COUNT(*) FROM listings l JOIN users u ON l.agent_id=u.id LEFT JOIN roles r ON r.id=u.role_id WHERE r.name IS NULL OR r.name<>'agent' OR u.branch_id IS NULL OR u.branch_id<>l.branch_id",
        'post_owner'=>"SELECT COUNT(*) FROM posts p JOIN listings l ON p.listing_id=l.id WHERE p.user_id<>l.agent_id",
        'media_types'=>"SELECT COUNT(*) FROM post_media m JOIN posts p ON m.post_id=p.id WHERE (p.content_type='video' AND m.media_type<>'video') OR (p.content_type='photo' AND m.media_type<>'image')",
        'message_membership'=>"SELECT COUNT(*) FROM messages m JOIN conversation_members c ON m.sender_member_id=c.id WHERE m.conversation_id<>c.conversation_id",
        'survey_agent'=>"SELECT COUNT(*) FROM survey_requests s JOIN listings l ON s.listing_id=l.id WHERE s.agent_id<>l.agent_id",
        'comment_parent'=>"SELECT COUNT(*) FROM comments c JOIN comments p ON c.parent_id=p.id WHERE c.post_id<>p.post_id",
        'synthetic_identity'=>"SELECT COUNT(*) FROM users WHERE name NOT LIKE 'SYNTHETIC QA %' OR email NOT LIKE '%@example.test' OR phone IS NOT NULL",
    ];$counts=[];foreach($queries as$key=>$sql)$counts[$key]=(int)$p->query($sql)->fetchColumn();$p->rollBack();
    if(array_sum($orphans)+array_sum($duplicates)+array_sum($counts)!==0)throw new RuntimeException('QA data audit failed');
    $bad=$c;$bad['database']='promerty';$denied=false;try{guardQa($bad);}catch(RuntimeException){$denied=true;}if(!$denied)throw new RuntimeException('Operational target guard regression');
    $e=json_decode(file_get_contents($private.'/qa-fixture-evidence.json'),true,512,JSON_THROW_ON_ERROR);$expected=['fk'=>1452,'unique'=>1062,'enum'=>1265,'not_null'=>1048,'json_check'=>4025];foreach($expected as$key=>$value)if(($e['constraints'][$key]['driver_code']??null)!==$value)throw new RuntimeException('Constraint evidence mismatch');
    $report=['status'=>'PASS','orphan_checks'=>count($orphans),'orphan_total'=>array_sum($orphans),'unique_checks'=>count($duplicates),'duplicate_total'=>array_sum($duplicates),'synthetic_relation_checks'=>$counts,'operational_target_guard_rejected'=>true,'constraint_rejection_codes_verified'=>true];saveJson($private.'/qa-audit-evidence.json',$report);echo json_encode($report);
}catch(Throwable $e){if(isset($p)&&$p->inTransaction())$p->rollBack();fwrite(STDERR,json_encode(['status'=>'FAIL','reason'=>$e instanceof PDOException?'QA audit query failed':$e->getMessage()])."\n");exit(1);}
