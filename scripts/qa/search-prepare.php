<?php
/** Idempotent synthetic search pagination fixture, restricted to promerty_qa. */
declare(strict_types=1);
$run=dirname(__DIR__,2).'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
$pdo=guardQa(json_decode(file_get_contents($private.'/qa-connection.json'),true,512,JSON_THROW_ON_ERROR));
$source=$pdo->query("SELECT p.* FROM posts p JOIN listings l ON l.id=p.listing_id JOIN users u ON u.id=p.user_id WHERE p.content_type='video' AND p.status='published' AND p.moderation_status='active' AND p.deleted_at IS NULL AND l.title LIKE 'SYNTHETIC QA%' AND u.name LIKE 'SYNTHETIC QA%' ORDER BY p.id LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if(!$source)throw new RuntimeException('No legitimate synthetic source');
$media=$pdo->query('SELECT * FROM post_media WHERE post_id='.(int)$source['id']." AND media_type='video' ORDER BY sort_order LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$local=\App\Services\FeedMedia::localPath($media['file_url']??null);
if(!$local||!is_file($private.'/customer-runtime/app/public/'.$local))throw new RuntimeException('Playable fixture file unavailable');
$count=(int)$pdo->query("SELECT COUNT(*) FROM posts WHERE caption LIKE 'SYNTHETIC QA SEARCH pagination %'")->fetchColumn();
if($count>0){$align=$pdo->prepare("UPDATE posts SET published_at=? WHERE caption LIKE 'SYNTHETIC QA SEARCH pagination %'");$align->execute([$source['published_at']]);echo json_encode(['status'=>'existing','database'=>'promerty_qa','synthetic_search_posts'=>$count,'published_time_aligned_to_existing_fixture'=>true]);exit;}
$pdo->beginTransaction();
try{
    $post=$pdo->prepare("INSERT INTO posts (user_id,listing_id,caption,content_type,status,moderation_status,published_at,created_at,updated_at) VALUES (?, ?, ?, 'video','published','active',?,UTC_TIMESTAMP(),UTC_TIMESTAMP())");
    $insertMedia=$pdo->prepare('INSERT INTO post_media (post_id,media_type,file_url,thumbnail_url,sort_order,file_size_bytes,duration_seconds,width,height,created_at,updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(),NOW())');
    for($i=1;$i<=16;$i++){$post->execute([$source['user_id'],$source['listing_id'],'SYNTHETIC QA SEARCH pagination '.$i.' — not a real listing',$source['published_at']]);$id=$pdo->lastInsertId();$insertMedia->execute([$id,$media['media_type'],$media['file_url'],$media['thumbnail_url'],$media['sort_order'],$media['file_size_bytes'],$media['duration_seconds'],$media['width'],$media['height']]);}
    $pdo->commit();echo json_encode(['status'=>'PASS','database'=>'promerty_qa','synthetic_posts_added'=>16,'existing_media_reused'=>true]);
}catch(Throwable $e){$pdo->rollBack();throw new RuntimeException('QA fixture creation failed; sensitive diagnostics suppressed');}
