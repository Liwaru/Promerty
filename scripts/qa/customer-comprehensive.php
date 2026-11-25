<?php
/** Synthetic Customer QA only. No migrations, production DML, or provider calls. */
declare(strict_types=1);
$operation=$argv[1]??'';
$run=dirname(__DIR__,2).'/storage/app/private/phase4-20261110-73e56946';
$argv=[__FILE__,'library',$run];require __DIR__.'/phase4.php';
$qa=guardQa(json_decode(file_get_contents($private.'/qa-connection.json'),true,512,JSON_THROW_ON_ERROR));
$evidence=$root.'/docs/customer/qa';if(!is_dir($evidence))mkdir($evidence,0777,true);
$baseline=$private.'/customer-comprehensive-before.json';
if($operation==='prepare'){
    if(!is_file($baseline))saveJson($baseline,snapshot(db(sourceConfig($root)),'promerty'));
    $app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    if(config('database.connections.mysql.database')!=='promerty_qa'||config('cache.default')!=='file'||app()->runningUnitTests())throw new RuntimeException('Real HTTP QA guard failed');
    $role=App\Models\Role::where('name','customer')->firstOrFail();
    foreach(['verified','other'] as $kind){
        $file=$storage.'/comprehensive-'.$kind.'.json';
        if(is_file($file))continue;
        $password=bin2hex(random_bytes(24));
        $user=App\Models\User::create(['role_id'=>$role->id,'name'=>'SYNTHETIC QA Customer '.$kind,
            'username'=>'qa_customer_'.$kind,'email'=>'customer.'.$kind.'.comprehensive@example.test','password'=>$password,'status'=>'active',
            'phone_verified_at'=>$kind==='verified'?now():null]);
        file_put_contents($file,json_encode(['email'=>$user->email,'password'=>$password,'id'=>$user->id]),LOCK_EX);
    }
    $user=App\Models\User::where('username','qa_customer_verified')->firstOrFail();
    $other=App\Models\User::where('username','qa_customer_other')->firstOrFail();
    $post=App\Models\Post::where('status','published')->with('listing','media')->findOrFail(1);
    Illuminate\Support\Facades\DB::transaction(function()use($post,$user,$other){
        for($i=0;$i<32;$i++){
            $p=App\Models\Post::firstOrCreate(['caption'=>'SYNTHETIC COMPREHENSIVE QA '.$i.' — BUKAN PROPERTI NYATA'],['user_id'=>$post->user_id,'listing_id'=>$post->listing_id,
                'content_type'=>$i%4===0?'video':'photo','status'=>'published','moderation_status'=>'active','published_at'=>now()->subSeconds($i+1)]);
            $p->media()->firstOrCreate(['sort_order'=>1],['media_type'=>$p->content_type==='photo'?'image':'video','file_url'=>'qa-only/synthetic-placeholder.'.($p->content_type==='video'?'mp4':'jpg'),
                'thumbnail_url'=>'qa-only/synthetic-placeholder.jpg','width'=>900,'height'=>1200,'sort_order'=>1]);
        }
        for($i=1;$i<=25;$i++){
            App\Models\Notification::firstOrCreate(['user_id'=>$user->id,'title'=>'SYNTHETIC QA FIXTURE '.$i],['type'=>'system','message'=>'Fixture pagination — bukan notifikasi bisnis.', 'data'=>['private'=>'PRIVATE_NOTIFICATION_PAYLOAD']]);
        }
        $foreign=App\Models\Conversation::firstOrCreate(['type'=>'direct','created_by'=>$other->id],['status'=>'active']);
        $member=$foreign->members()->firstOrCreate(['user_id'=>$other->id],['member_role'=>'customer']);
        $foreign->members()->firstOrCreate(['user_id'=>$post->listing->agent_id],['member_role'=>'agent']);
        $foreign->messages()->firstOrCreate(['sender_member_id'=>$member->id,'body'=>'FOREIGN_PRIVATE_MESSAGE'],['message_type'=>'text']);
        App\Models\Notification::firstOrCreate(['user_id'=>$other->id,'title'=>'FOREIGN_PRIVATE_NOTIFICATION'],['type'=>'system']);
    });
    echo json_encode(['status'=>'PASS','database'=>'promerty_qa','environment'=>app()->environment(),'csrf_bypassed_for_tests'=>app()->runningUnitTests(),
        'cache'=>config('cache.default'),'verified_fixture'=>'preverified synthetic state, not SMS verification',
        'post_id'=>$post->id,'listing_id'=>$post->listing_id,'agent_id'=>$post->listing->agent_id,'verified_id'=>$user->id,'other_id'=>$other->id,
        'foreign_conversation'=>App\Models\Conversation::where('created_by',$other->id)->value('id'),
        'foreign_notification'=>App\Models\Notification::where('user_id',$other->id)->value('id')]);
}elseif($operation==='pagination'){
    $app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $user=App\Models\User::where('username','qa_customer_verified')->firstOrFail();
    $thread=App\Models\Conversation::where('created_by',$user->id)->where('type','direct')->firstOrFail();
    $member=$thread->members()->where('user_id',$user->id)->firstOrFail();
    Illuminate\Support\Facades\DB::transaction(function()use($user,$thread,$member){
        for($i=1;$i<=25;$i++){
            $thread->messages()->firstOrCreate(['sender_member_id'=>$member->id,'body'=>'SYNTHETIC QA PAGINATION '.$i],['message_type'=>'text']);
            App\Models\SurveyRequest::firstOrCreate(['customer_id'=>$user->id,'listing_id'=>1,'note'=>'SYNTHETIC QA PAGINATION — NOT A REAL VISIT '.$i],
                ['agent_id'=>2,'status'=>'pending','requested_at'=>now()->addDays(10)->addHours($i)]);
        }
    });echo json_encode(['status'=>'PASS','synthetic_message_and_survey_fixtures'=>25,'conversation'=>$thread->id]);
}elseif($operation==='performance'){
    $app=require __DIR__.'/customer-environment.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    $measurements=[];
    foreach([1,8,32] as $size){
        Illuminate\Support\Facades\DB::flushQueryLog();Illuminate\Support\Facades\DB::enableQueryLog();
        $start=hrtime(true);$posts=app(App\Services\FeedService::class)->query()->latest('published_at')->latest('id')->limit($size)->get();
        $request=Illuminate\Http\Request::create('/api/feed');foreach($posts as $post)(new App\Http\Resources\FeedPostResource($post))->resolve($request);
        $queries=Illuminate\Support\Facades\DB::getQueryLog();$measurements[]=['posts'=>$posts->count(),'queries'=>count($queries),'elapsed_ms'=>round((hrtime(true)-$start)/1e6,2),'sql_ms'=>round(array_sum(array_column($queries,'time')),2)];
        Illuminate\Support\Facades\DB::disableQueryLog();
    }
    $indexes=[];
    foreach(['posts','likes','saves','follows','comments','messages','conversation_members','survey_requests','notifications'] as $table){
        foreach($qa->query('SHOW INDEX FROM '.ident($table))->fetchAll(PDO::FETCH_ASSOC) as $index)$indexes[$table][$index['Key_name']][]=$index['Column_name'];
    }
    $result=['database'=>'promerty_qa','feed_resource'=>$measurements,'indexes'=>$indexes,'scope'=>'Local synthetic dataset; not a concurrency/load benchmark'];
    file_put_contents($evidence.'/performance.json',json_encode($result,JSON_PRETTY_PRINT)."\n");echo json_encode(['feed_resource'=>$measurements,'indexed_tables'=>count($indexes)]);
}elseif($operation==='final'){
    $before=json_decode(file_get_contents($baseline),true,512,JSON_THROW_ON_ERROR);$after=snapshot(db(sourceConfig($root)),'promerty');
    $result=['operational_database'=>'promerty','schema_counts_checksums_migrations_unchanged'=>$before===$after,'qa_account_operational_access'=>'DENIED',
        'server_version'=>$qa->query('SELECT VERSION()')->fetchColumn(),
        'qa_tables'=>(int)$qa->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_type='BASE TABLE'")->fetchColumn()];
    foreach(['roles','permissions','users','posts','messages','survey_requests','notifications'] as $table)$result['qa_row_counts'][$table]=(int)$qa->query('SELECT COUNT(*) FROM '.ident($table))->fetchColumn();
    file_put_contents($evidence.'/database.json',json_encode($result,JSON_PRETTY_PRINT)."\n");echo json_encode($result);if($before!==$after)exit(1);
}else throw new RuntimeException('Unsupported QA operation');
