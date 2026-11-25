<?php
/** Dedicated Customer QA principal. Existing role and permissions are never modified. */
declare(strict_types=1);
$app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty_qa')throw new RuntimeException('QA database guard');
$file=dirname(__DIR__,2).'/storage/app/private/real-video-20261113/viewer-customer.json';
if(is_file($file)){echo "Existing QA Customer retained.\n";exit;}
$role=App\Models\Role::where('name','customer')->firstOrFail();$password=bin2hex(random_bytes(20));
$user=App\Models\User::create(['role_id'=>$role->id,'name'=>'QA Viewer Customer — sintetis','username'=>'qa.viewer.20261113','email'=>'qa.viewer.20261113@example.invalid','password'=>$password,'status'=>'active','phone_verified_at'=>now()]);
file_put_contents($file,json_encode(['id'=>$user->id,'email'=>$user->email,'password'=>$password],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['database'=>'promerty_qa','customer_id'=>$user->id,'existing_role'=>'customer']).PHP_EOL;
