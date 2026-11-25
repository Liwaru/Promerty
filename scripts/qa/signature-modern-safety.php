<?php
declare(strict_types=1);
$operation=$argv[1]??'';
if(!in_array($operation,['before','after'],true))throw new RuntimeException('Invalid operation');
$run=dirname(__DIR__,2).'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
guardQa(json_decode(file_get_contents($private.'/qa-connection.json'),true,512,JSON_THROW_ON_ERROR));
$current=snapshot(db(sourceConfig($root)),'promerty');$path=$private.'/signature-modern-20261110/database-before.json';
if($operation==='before'){saveJson($path,$current);$result=['qa'=>'promerty_qa','operational_access_from_qa'=>'DENIED','snapshot'=>'read-only'];}
else{$before=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);$result=['qa'=>'promerty_qa'];foreach(['schema','row_counts','checksums','migrations'] as $key)$result[$key.'_unchanged']=$current[$key]===$before[$key];}
saveJson($root.'/docs/customer/signature-modern/database-'.$operation.'.json',$result);echo json_encode($result);
