<?php
/** Read-only operational integrity evidence for the resumed visual QA. */
declare(strict_types=1);
$operation=$argv[1]??'';
$run=dirname(__DIR__,2).'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
guardQa(json_decode(file_get_contents($private.'/qa-connection.json'),true,512,JSON_THROW_ON_ERROR));
$file=$private.'/signature-resume-20261110-1525-before.json';
$current=snapshot(db(sourceConfig($root)),'promerty');
$old=json_decode(file_get_contents($private.'/customer-signature-before.json'),true,512,JSON_THROW_ON_ERROR);
$delta=[];
foreach($current['row_counts'] as $table=>$count){
    if($count!==($old['row_counts'][$table]??null)||$current['checksums'][$table]!==($old['checksums'][$table]??null)){
        $delta[]=['table'=>$table,'old_count'=>$old['row_counts'][$table]??null,'current_count'=>$count,'checksum_equal'=>$current['checksums'][$table]===($old['checksums'][$table]??null)];
    }
}
if($operation==='before'){
    saveJson($file,$current);
    $result=['qa_database'=>'promerty_qa','operational_access_from_qa'=>'DENIED','historical_schema_equal'=>$old['schema']===$current['schema'],'historical_migrations_equal'=>$old['migrations']===$current['migrations'],'historical_data_delta'=>$delta];
}elseif($operation==='after'){
    $before=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $result=['qa_database'=>'promerty_qa','operational_access_from_qa'=>'DENIED','resume_schema_equal'=>$before['schema']===$current['schema'],'resume_counts_equal'=>$before['row_counts']===$current['row_counts'],'resume_checksums_equal'=>$before['checksums']===$current['checksums'],'resume_migrations_equal'=>$before['migrations']===$current['migrations'],'historical_data_delta'=>$delta];
}else throw new RuntimeException('Unsupported operation');
saveJson($root.'/docs/customer/signature/resume-20261110-1525/database-'.$operation.'.json',$result);
echo json_encode($result,JSON_PRETTY_PRINT);
