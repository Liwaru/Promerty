<?php
/** Read-only DB snapshots for Customer visual QA; no application migration/seed. */
declare(strict_types=1);
$operation=$argv[1]??'';
$run=dirname(__DIR__,2).'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
guardQa(json_decode(file_get_contents($private.'/qa-connection.json'),true,512,JSON_THROW_ON_ERROR));
$path=$private.'/customer-signature-before.json';
$current=snapshot(db(sourceConfig($root)),'promerty');
if($operation==='before'){
    if(file_exists($path))throw new RuntimeException('Baseline exists; refusing overwrite');
    saveJson($path,$current);echo json_encode(['status'=>'PASS','operational_snapshot'=>'read-only','qa'=>'promerty_qa']);
}elseif($operation==='after'){
    $before=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
    $result=['database'=>'promerty','metadata_counts_checksums_migrations_unchanged'=>$before===$current,'qa_database'=>'promerty_qa','qa_operational_access'=>'DENIED'];
    file_put_contents($root.'/docs/customer/signature/database.json',json_encode($result,JSON_PRETTY_PRINT)."\n");echo json_encode($result);if($before!==$current)exit(1);
}else throw new RuntimeException('Unsupported operation');
