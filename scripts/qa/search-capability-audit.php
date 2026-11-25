<?php
/** Operational capability inventory; no operational Laravel bootstrap or writes. */
declare(strict_types=1);
$root=dirname(__DIR__,2);$private=$root.'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$private];require __DIR__.'/phase4.php';
$pdo=db(sourceConfig($root));$pdo->exec('START TRANSACTION READ ONLY');
try{
    echo json_encode(['database'=>'promerty','mode'=>'READ ONLY',
        'categories'=>rows($pdo,'SELECT id,name,slug FROM property_categories ORDER BY id'),
        'listing_count'=>rows($pdo,'SELECT COUNT(*) AS count FROM listings'),
        'search_columns'=>rows($pdo,"SELECT COLUMN_NAME,DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='promerty' AND TABLE_NAME='listings' AND COLUMN_NAME IN ('floors','bedrooms','bathrooms','price','price_period','land_area','building_area','additional_specs') ORDER BY ORDINAL_POSITION")
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
}finally{$pdo->rollBack();}
