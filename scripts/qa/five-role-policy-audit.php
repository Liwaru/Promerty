<?php
/** Read-only policy checks with actual QA accounts; no new routes or dashboard stubs. */
declare(strict_types=1);
$app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(Illuminate\Support\Facades\DB::selectOne('SELECT DATABASE() AS db')->db!=='promerty_qa')throw new RuntimeException('QA database guard');
$user=fn($email)=>App\Models\User::where('email',$email)->firstOrFail();
$admin=$user('admin@gmail.com');$owner=$user('owner@gmail.com');$agent=$user('agent@gmail.com');$manager=$user('branch@gmail.com');
$same=new App\Models\Listing(['agent_id'=>$agent->id,'branch_id'=>$manager->branch_id]);$other=new App\Models\Listing(['agent_id'=>999999,'branch_id'=>999999]);
$allows=fn($actor,$ability,$target)=>Illuminate\Support\Facades\Gate::forUser($actor)->allows($ability,$target);
$r=['database'=>'promerty_qa','admin_update_owner'=>$allows($admin,'update',$owner),'admin_delete_owner'=>$allows($admin,'delete',$owner),'owner_technical_settings'=>$owner->hasPermission('manage_system_settings'),'owner_business_analytics'=>$owner->hasPermission('view_global_analytics'),'admin_technical_settings'=>$admin->hasPermission('manage_system_settings'),'agent_update_own'=>$allows($agent,'update',$same),'agent_update_other'=>$allows($agent,'update',$other),'manager_same_branch'=>$allows($manager,'viewInternal',$same),'manager_other_branch'=>$allows($manager,'viewInternal',$other),'branch_id'=>$manager->branch_id,'agent_branch_id'=>$agent->branch_id,'record_mutations'=>false];
file_put_contents(dirname(__DIR__,2).'/docs/customer/qa/five-role-policy-audit.json',json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR));echo json_encode($r,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
