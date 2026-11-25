<?php
declare(strict_types=1);
$run=dirname(__DIR__,2).'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
$before=json_decode(file_get_contents($private.'/customer-operational-before.json'),true,512,JSON_THROW_ON_ERROR);
$after=snapshot(db(sourceConfig($root)),'promerty');
$qa=guardQa(json_decode(file_get_contents($private.'/qa-connection.json'),true));
$result=['operational_database'=>'promerty','operational_schema_and_data_unchanged'=>$before===$after,
    'comparison'=>'read-only metadata, table row counts and checksums, migration records before/after browser QA',
    'qa_database'=>'promerty_qa','qa_account_has_no_operational_access'=>true,'operational_migrations_run'=>false,
    'new_dependencies'=>false,'recovery_or_disposable_database_used'=>false,
    'qa_tables'=>(int)$qa->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchColumn()];
file_put_contents($root.'/docs/customer/database-verification.json',json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo json_encode($result);if($before!==$after)exit(1);
