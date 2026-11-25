<?php
declare(strict_types=1);
$side=$argv[1]??'before';if(!in_array($side,['before','after'],true))throw new RuntimeException('Invalid side');
$root=dirname(__DIR__,2);$private=$root.'/storage/app/private/gold-visual-20261116';
$argv=[__FILE__,'library',$private];require __DIR__.'/phase4.php';
$state=snapshot(db(sourceConfig($root)),'promerty');file_put_contents($private.'/db-'.$side.'.json',json_encode($state,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['database'=>'promerty','read_only_audit'=>true,'tables'=>count($state['row_counts']),'side'=>$side]).PHP_EOL;
