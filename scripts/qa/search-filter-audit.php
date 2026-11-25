<?php
/** Operational location audit: SELECT only, inside an enforced read-only transaction. */
declare(strict_types=1);
$root=dirname(__DIR__,2);$private=$root.'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$private];require __DIR__.'/phase4.php';
$pdo=db(sourceConfig($root));$pdo->exec('START TRANSACTION READ ONLY');
try {
    $result=['database'=>'promerty','access'=>'READ ONLY',
        'location_columns'=>rows($pdo,"SELECT COLUMN_NAME,DATA_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='promerty' AND TABLE_NAME='locations' ORDER BY ORDINAL_POSITION"),
        'city_province_counts'=>rows($pdo,'SELECT city,province,COUNT(*) FROM locations WHERE deleted_at IS NULL GROUP BY city,province'),
        'checksums'=>rows($pdo,'CHECKSUM TABLE locations,listings,projects,secondary_properties')];
    echo json_encode($result,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} finally {$pdo->rollBack();}
