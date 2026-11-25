<?php
/** Operational checks are SELECT/CHECKSUM only, under an enforced read-only transaction. */
declare(strict_types=1);
$root=dirname(__DIR__,2);$private=$root.'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$private];require __DIR__.'/phase4.php';
$pdo=db(sourceConfig($root));$pdo->exec('START TRANSACTION READ ONLY');
try{
    $r=['database'=>'promerty','access'=>'READ ONLY','roles'=>rows($pdo,'SELECT r.name,r.level,COUNT(u.id) AS users FROM roles r LEFT JOIN users u ON u.role_id=r.id GROUP BY r.id,r.name,r.level ORDER BY r.level'),
        'requested_qa_emails_found'=>rows($pdo,"SELECT email,COUNT(*) FROM users WHERE email IN ('customer@gmail.com','agent@gmail.com','branch@gmail.com','admin@gmail.com','owner@gmail.com') GROUP BY email"),
        'role_dependencies'=>rows($pdo,"SELECT TABLE_NAME,COLUMN_NAME,REFERENCED_TABLE_NAME,REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA='promerty' AND REFERENCED_TABLE_NAME='roles' ORDER BY TABLE_NAME,COLUMN_NAME"),
        'checksums'=>rows($pdo,'CHECKSUM TABLE users,roles,permissions,role_permissions')];
    file_put_contents($root.'/docs/customer/qa/five-role-operational-audit.json',json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
    echo json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{$pdo->rollBack();}
