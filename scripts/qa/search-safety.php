<?php
/** Only read operational metadata; preserve unique before/after snapshots. */
declare(strict_types=1);
$operation=$argv[1]??'';
if(!in_array($operation,['before','after'],true))throw new RuntimeException('Invalid operation');
$run=dirname(__DIR__,2).'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
guardQa(json_decode(file_get_contents($private.'/qa-connection.json'),true,512,JSON_THROW_ON_ERROR));
$snapshot=snapshot(db(sourceConfig($root)),'promerty');
$file=$private.'/search-20261110-s1/database-before.json';
if($operation==='before'){saveJson($file,$snapshot);$result=['qa_database'=>'promerty_qa','qa_operational_access'=>'DENIED','operational_snapshot'=>'read-only'];}
else{
    $before=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
    $result=['qa_database'=>'promerty_qa','schema_equal'=>$before['schema']===$snapshot['schema'],'counts_equal'=>$before['row_counts']===$snapshot['row_counts'],'checksums_equal'=>$before['checksums']===$snapshot['checksums'],'migrations_equal'=>$before['migrations']===$snapshot['migrations']];
}
saveJson($root.'/docs/customer/search/database-'.$operation.'.json',$result);echo json_encode($result);
