<?php
/** Authorized synthetic QA fixture preparation; never modifies operational records. */
declare(strict_types=1);
$run=dirname(__DIR__,2).'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
$p=guardQa(json_decode(file_get_contents($private.'/qa-connection.json'),true));
if(!is_file($private.'/customer-operational-before.json'))saveJson($private.'/customer-operational-before.json',snapshot(db(sourceConfig($root)),'promerty'));
$app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(config('database.connections.mysql.database')!=='promerty_qa'||config('session.driver')!=='file')throw new RuntimeException('Runtime isolation guard');
$u=App\Models\User::where('username','qa_customer')->where('email','customer.phase4@example.test')->firstOrFail();
$credentials=$storage.'/browser-login.json';
if(!is_file($credentials)){
    $password=bin2hex(random_bytes(24));
    $u->update(['password'=>$password]); // synthetic QA account only; no phone verification bypass.
    file_put_contents($credentials,json_encode(['email'=>$u->email,'password'=>$password]),LOCK_EX);
}
// A locally drawn synthetic QA image, not a real listing or externally downloaded media.
$image=imagecreatetruecolor(900,1200);$sky=imagecolorallocate($image,205,215,217);$stone=imagecolorallocate($image,216,208,191);$ink=imagecolorallocate($image,32,43,48);$green=imagecolorallocate($image,81,104,85);
imagefill($image,0,0,$sky);imagefilledrectangle($image,0,850,900,1200,$green);
imagefilledrectangle($image,100,350,800,900,$stone);imagefilledrectangle($image,200,460,740,700,$ink);imagefilledrectangle($image,170,735,780,820,$ink);
imagestring($image,5,45,60,'PROMERTY / SYNTHETIC QA - NOT A REAL LISTING',$ink);
imagejpeg($image,$storage.'/app/public/qa-only/synthetic-placeholder.jpg',92);imagedestroy($image);
$p->exec("UPDATE post_media SET thumbnail_url='qa-only/synthetic-placeholder.jpg' WHERE file_url='qa-only/synthetic-placeholder.mp4'");
echo json_encode(['status'=>'PASS','database'=>'promerty_qa','operational_snapshot'=>'read-only','credentials'=>'private file only','phone_verification'=>'unchanged']);
