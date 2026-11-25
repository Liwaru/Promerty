<?php
/** Disposable account exclusively for the isolated QA login/history test. */
use App\Models\{User,Role};
use Illuminate\Support\Facades\DB;
$app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty_qa')throw new RuntimeException('QA database guard');
$file=base_path('storage/app/private/search-drawer-session.json');
if(($argv[1]??'')==='cleanup'){
    $id=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR)['id'];
    User::withTrashed()->whereKey($id)->where('email','search-drawer-qa@example.test')->forceDelete();unlink($file);echo "Disposable QA account removed.\n";exit;
}
if(is_file($file)||User::where('email','search-drawer-qa@example.test')->exists())throw new RuntimeException('Refuse duplicate QA account');
$user=User::create(['role_id'=>Role::where('name','customer')->firstOrFail()->id,'name'=>'QA Search Drawer Customer','email'=>'search-drawer-qa@example.test','password'=>'QaSearchOnly2026!','status'=>'active']);
file_put_contents($file,json_encode(['id'=>$user->id],JSON_THROW_ON_ERROR));echo "Disposable QA account created in promerty_qa.\n";
