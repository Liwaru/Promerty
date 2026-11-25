<?php
/** QA-only account provisioning. Password input arrives over STDIN and is never persisted in plaintext. */
declare(strict_types=1);
use App\Models\{Role,Permission,User,Branch,AgentProfile};
use Illuminate\Support\Facades\{DB,Hash};
use App\Support\RoleCatalog;
$app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(DB::connection()->getDatabaseName()!=='promerty_qa'||DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty_qa')throw new RuntimeException('QA identity guard');
$denied=false;try{DB::select('SELECT 1 FROM promerty.users LIMIT 0');}catch(Throwable $e){$denied=in_array((int)($e->errorInfo[1]??0),[1044,1142],true);}if(!$denied)throw new RuntimeException('QA principal has unexpected operational access');
$root=dirname(__DIR__,2);$run=$root.'/storage/app/private/real-video-20261113';
$pid=trim(file_get_contents($run.'/server.pid'));$listeners=[];$exitCode=1;
if(PHP_OS_FAMILY!=='Windows'||!ctype_digit($pid))throw new RuntimeException('Verified Windows loopback runtime required');
exec('netstat -ano -p TCP',$listeners,$exitCode);
if($exitCode!==0||!preg_match('/127\.0\.0\.1:8017\s+0\.0\.0\.0:0\s+LISTENING\s+'.preg_quote($pid,'/').'\s*$/m',implode("\n",$listeners)))throw new RuntimeException('Loopback listener not verified; refusing simple passwords');
$router=file_get_contents(__DIR__.'/real-video-router.php');
if(!str_contains($router,"'127.0.0.1'")||!str_contains($router,'PHP_AUTH_PW'))throw new RuntimeException('Protected local runtime required');
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);if(count($input)!==5)throw new RuntimeException('Exactly five accounts required');
$before=['roles'=>Role::with('permissions:id,name')->get()->toArray(),'users_by_role'=>DB::table('users')->join('roles','roles.id','=','users.role_id')->selectRaw('roles.name, COUNT(*) AS count')->groupBy('roles.name')->get()->toArray()];
$accounts=[];$branch=Branch::where('status','active')->firstOrFail();
DB::transaction(function()use($input,$branch,&$accounts){
    foreach(RoleCatalog::PERMISSIONS as $name=>$permissions){$role=Role::where('name',$name)->firstOrFail();$ids=Permission::whereIn('name',$permissions)->pluck('id');if(count($ids)!==count($permissions))throw new RuntimeException('Missing permission reference');$role->permissions()->sync($ids);}
    foreach($input as $item){if(!in_array($item['role'],RoleCatalog::roles(),true))throw new RuntimeException('Unexpected role');
        $user=User::withTrashed()->where('email',$item['email'])->first();
        if($user&&($user->trashed()||!str_starts_with($user->username??'','qa-final-five-')))throw new RuntimeException('Existing account conflict; refusing overwrite');
        $role=Role::where('name',$item['role'])->firstOrFail();
        $user??=new User();$user->fill(['name'=>'QA '.ucwords(str_replace('_',' ',$item['role'])).' — sintetis','username'=>'qa-final-five-'.$item['role'],'email'=>$item['email'],'role_id'=>$role->id,'branch_id'=>in_array($item['role'],['agent','branch_manager'])?$branch->id:null,'status'=>'active','password'=>Hash::make($item['password'])]);$user->save();
        if(!Hash::check($item['password'],$user->password))throw new RuntimeException('Password hash verification failed');
        if($item['role']==='agent')AgentProfile::updateOrCreate(['user_id'=>$user->id],['professional_name'=>'QA Agent','verification_status'=>'verified','verification_note'=>'Synthetic QA account, not a production Agent.']);
        $accounts[]=['id'=>$user->id,'email'=>$user->email,'role'=>$role->name,'branch_id'=>$user->branch_id,'hash_verified'=>true];
    }
});
$report=['database'=>'promerty_qa','operational_access_denied'=>$denied,'before'=>$before,'accounts'=>$accounts,'target_roles'=>RoleCatalog::roles(),'legacy_super_admin_retained'=>Role::where('name','super_admin')->exists(),'dashboards'=>'No internal dashboard routes exist; fallback is /'];
file_put_contents($run.'/five-role-manifest.json',json_encode($report,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));
echo json_encode(['database'=>'promerty_qa','accounts'=>$accounts,'legacy_super_admin_retained'=>$report['legacy_super_admin_retained']],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
