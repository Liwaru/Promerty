<?php
namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{Agency,AgentProfile,Branch,Listing,Role,User,Project,ProjectAssignment,Post,Developer,Location};
use App\Services\{SecurityAudit,ProjectAssignments};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class OperationsWorkspaceController extends Controller
{
    private const SECTIONS=[
        'branch_manager'=>['overview'=>['Dashboard Cabang','view_branch_dashboard'],'agents'=>['Agent Cabang','view_branch_agents'],
            'listings'=>['Listing cabang','view_branch_listings'],'projects'=>['Penugasan proyek','manage_project_assignments']],
        'admin'=>['overview'=>['Ringkasan','view_operational_analytics'],'users'=>['Pengguna','manage_users'],
            'customers'=>['Data Customer','manage_users'],'agent-data'=>['Data Agent','manage_users'],
            'agents'=>['Verifikasi Agent','verify_agents'],'branches'=>['Cabang','manage_branches'],
            'listings'=>['Monitoring listing','view_operational_analytics'],'audit'=>['Audit keamanan','view_audit_logs'],
            'projects'=>['Penugasan proyek','manage_project_assignments'],'catalog'=>['Proyek','manage_master_data'],'content'=>['Moderasi konten','moderate_content']],
        'owner'=>['overview'=>['Ringkasan bisnis','view_global_analytics'],'reports'=>['Laporan cabang','view_global_analytics'],
            'projects'=>['Penugasan proyek','manage_project_assignments']],
    ];
    private function context(Request $request,string $section):array
    {
        $role=$request->user()->role?->name;$sections=self::SECTIONS[$role]??[];
        abort_unless(isset($sections[$section])&&$request->user()->hasPermission($sections[$section][1]),403);
        if($role==='branch_manager')abort_unless($request->user()->branch_id&&$request->user()->branch?->status==='active',403);
        return [$role,self::availableSections($request->user())];
    }

    public static function availableSections(User $user): array
    {
        if ($user->hasRole('branch_manager') && $user->branch?->status !== 'active') return [];
        return array_filter(self::SECTIONS[$user->role?->name]??[],fn ($item)=>$user->hasPermission($item[1]));
    }
    public function index(Request $request,string $section='overview')
    {
        [$role,$sections]=$this->context($request,$section);
        $f=$request->validate(['q'=>['nullable','string','max:150'],'status'=>['nullable',Rule::in(['draft','active','archived','inactive','suspended'])],
            'agent_id'=>['nullable','integer','min:1'],'branch_id'=>['nullable','integer','min:1'],
            'account_role'=>['nullable',Rule::in(['branch_manager'])],
            'verification'=>['nullable',Rule::in(['pending','verified','rejected'])],
            'from'=>['nullable','date_format:Y-m-d'],'to'=>['nullable','date_format:Y-m-d',...($request->filled('from')?['after_or_equal:from']:[])]]);
        $branch=$role==='branch_manager'?$request->user()->branch_id:($f['branch_id']??null);
        $listings=Listing::query()->when($branch,fn($q)=>$q->where('branch_id',$branch));
        if($f['from']??null)$listings->whereDate('created_at','>=',$f['from']);
        if($f['to']??null)$listings->whereDate('created_at','<=',$f['to']);
        $agents=User::whereHas('role',fn($q)=>$q->where('name','agent'))->when($branch,fn($q)=>$q->where('branch_id',$branch));
        $metrics=[];$rows=null;$audit=null;$agencies=collect();$assignments=collect();$projectReady=false;$developers=collect();$locations=collect();
        if($section==='catalog'){
            $query=Project::query();if($f['q']??null)$query->where('name','like','%'.$f['q'].'%');
            $rows=$query->orderBy('name')->paginate(20,['id','name','status','description'])->withQueryString();
            $developers=Developer::orderBy('name')->limit(500)->get(['id','name']);
            $locations=Location::where('city','Batam')->orderBy('label')->limit(500)->get(['id','label']);
        }
        if($section==='projects'){
            $projectReady=app(ProjectAssignments::class)->available();
            $query=Project::where('status','active');if($f['q']??null)$query->where('name','like','%'.$f['q'].'%');
            $rows=$query->orderBy('name')->paginate(20,['id','name','status'])->withQueryString();
            if($projectReady)$assignments=ProjectAssignment::with(['branch:id,name','agent:id,name'])->whereIn('project_id',$rows->pluck('id'))
                ->where('active',true)->when($role==='branch_manager',fn($q)=>$q->where('branch_id',$branch))->get()->groupBy('project_id');
        }
        if($section==='content'){
            $query=Post::query();if($f['q']??null)$query->where('caption','like','%'.$f['q'].'%');
            $rows=$query->with('listing:id,title')->latest('id')->paginate(20,['id','listing_id','caption','status','moderation_status','user_id'])->withQueryString();
        }
        if(in_array($section,['overview','reports'],true)){
            $counts=(clone $listings)->selectRaw('status,count(*) as total')->groupBy('status')->pluck('total','status');
            $metrics=['Agent'=>$agents->count(),'Listing dibuat'=>array_sum($counts->all()),'Aktif'=>(int)($counts['active']??0),
                'Draft'=>(int)($counts['draft']??0),'Arsip'=>(int)($counts['archived']??0)];
            if($role!=='branch_manager'){
                $branchCount=Branch::when($branch,fn($q)=>$q->whereKey($branch));
                $metrics=['Cabang'=>$branchCount->count(),...$metrics];
                if($role==='owner')$metrics=['Cabang aktif'=>(clone $branchCount)->where('status','active')->count(),...$metrics];
            }
        }
        if($section==='listings'){
            if($f['q']??null)$listings->where('title','like','%'.$f['q'].'%');
            if($f['status']??null)$listings->where('status',$f['status']);
            if($f['agent_id']??null)$listings->where('agent_id',$f['agent_id']);
            $rows=$listings->with(['agent:id,name','branch:id,name'])->latest('id')->paginate(20,
                ['id','title','agent_id','branch_id','status','price','listing_type','created_at'])->withQueryString();
        }
        if(in_array($section,['users','agents'],true)){
            $query=$section==='agents'?$agents:User::whereHas('role',fn($q)=>$q->whereIn('name',['customer','agent','branch_manager']));
            if($section==='users' && ($f['account_role']??null)==='branch_manager'){
                $query->whereHas('role',fn($role)=>$role->where('name','branch_manager'));
                $sections['users'][0]='Data Branch Manager';
            }
            if($f['q']??null)$query->where('name','like','%'.$f['q'].'%');
            if($f['status']??null)$query->where('status',$f['status']);
            $rows=$query->with(['role:id,name','branch:id,name','agentProfile'])->latest('id')->paginate(20,
                ['id','name','role_id','branch_id','status'])->withQueryString();
        }
        if(in_array($section,['customers','agent-data'],true)){
            $query=$section==='agent-data' ? (clone $agents)->withCount('listings')
                : User::whereHas('role',fn($q)=>$q->where('name','customer'));
            if ($f['q']??null) {
                $term='%'.str_replace(['!','%','_'],['!!','!%','!_'],$f['q']).'%';
                $query->where(fn($q)=>$q->whereRaw("name LIKE ? ESCAPE '!'",[$term])->orWhereRaw("email LIKE ? ESCAPE '!'",[$term]));
            }
            if($f['status']??null)$query->where('status',$f['status']);
            if($section==='agent-data'&&($f['verification']??null))
                $query->whereHas('agentProfile',fn($q)=>$q->where('verification_status',$f['verification']));
            $rows=$query->with(['branch:id,name,status','agentProfile'])->latest('id')->paginate(20)->withQueryString();
        }
        if($section==='branches'||$section==='reports'){
            $query=Branch::query()->when($branch,fn($q)=>$q->whereKey($branch));
            if($f['q']??null)$query->where('name','like','%'.$f['q'].'%');
            $rows=$query->withCount(['users as agents_count'=>fn($q)=>$q->whereHas('role',fn($q)=>$q->where('name','agent')),
                'listings'=>function($q)use($f){if($f['from']??null)$q->whereDate('created_at','>=',$f['from']);if($f['to']??null)$q->whereDate('created_at','<=',$f['to']);}])
                ->orderBy('name')->paginate(20,['id','name','status','agency_id'])->withQueryString();
            if($section==='branches')$agencies=Agency::orderBy('name')->get(['id','name']);
        }
        if($section==='audit'){
            if(config('security.qa_rollout'))$audit=app(SecurityAudit::class)->review($request->user(),100,(int)$request->query('after_id',0));
        }
        $branchOptions=Branch::query()->when($role==='branch_manager',fn($q)=>$q->whereKey($branch))->orderBy('name')->limit(500)->get(['id','name','status']);
        $agentOptions=in_array($section,['projects','listings'],true)?(clone $agents)->with('branch:id,name')->orderBy('name')->limit(500)->get(['id','name','branch_id']):collect();
        return view('operations.workspace',compact('role','section','sections','metrics','rows','audit','agencies','f','assignments','projectReady','branchOptions','agentOptions','developers','locations'));
    }

    public function saveDirectoryAccount(Request $request, string $kind, ?User $user = null)
    {
        $actor=$request->user();
        abort_unless($actor->hasRole('admin')&&$actor->hasPermission('manage_users'),403);
        $agent=$kind==='agent';
        if ($user) {
            abort_unless($user->hasRole($kind),404);
            Gate::forUser($actor)->authorize('update',$user);
        } elseif ($agent) {
            abort_unless($actor->hasPermission('verify_agents'),403);
        }
        if (!$user && is_string($request->input('email'))) $request->merge(['email'=>strtolower(trim($request->input('email')))]);
        $data=$request->validate([
            'name'=>['required','string','max:150'],
            'email'=>$user ? ['prohibited'] : ['required','email','max:255',Rule::unique('users','email')],
            'status'=>$user ? ['required',Rule::in(['active','inactive','suspended'])] : ['prohibited'],
            'confirm_status'=>['sometimes','accepted'],
            'branch_id'=>$agent ? [$user?'nullable':'required','integer',Rule::exists('branches','id')->where('status','active')] : ['prohibited'],
            'role'=>['prohibited'],'role_id'=>['prohibited'],'password'=>['prohibited'],'password_confirmation'=>['prohibited'],
            'email_verified_at'=>['prohibited'],'provider_user_id'=>['prohibited'],
        ]);
        try {
            DB::transaction(function () use($actor,$user,$kind,$agent,$data,$request){
                $attrs=['name'=>$data['name']];
                if (!$user) {
                    if (User::withTrashed()->whereRaw('LOWER(email) = ?', [$data['email']])->exists()) {
                        throw ValidationException::withMessages(['email'=>'Email sudah digunakan.']);
                    }
                    $subject=User::create([...$attrs,'email'=>$data['email'],'status'=>'inactive',
                        'branch_id'=>$agent?$data['branch_id']:null,'role_id'=>Role::where('name',$kind)->firstOrFail()->id]);
                    if($agent)AgentProfile::create(['user_id'=>$subject->id,'verification_status'=>'pending']);
                } else {
                    $subject=User::whereKey($user->id)->lockForUpdate()->firstOrFail();
                    abort_unless($subject->hasRole($kind),404);
                    Gate::forUser($actor)->authorize('update',$subject);
                    if ($subject->status!==$data['status'] && !$request->boolean('confirm_status')) {
                        throw ValidationException::withMessages(['confirm_status'=>'Konfirmasi perubahan status akun.']);
                    }
                    $attrs['status']=$data['status'];
                    if($agent && isset($data['branch_id']) && (int)$data['branch_id']!==$subject->branch_id){
                        abort_unless($actor->hasPermission('manage_branches')&&$actor->hasPermission('verify_agents'),403);
                        $assignments=app(ProjectAssignments::class);
                        if(!$assignments->available() || $subject->listings()->withTrashed()->exists()
                            || ProjectAssignment::where('agent_id',$subject->id)->exists()){
                            throw ValidationException::withMessages(['branch_id'=>'Tinjau riwayat listing dan penugasan sebelum memindahkan cabang.']);
                        }
                        $attrs['branch_id']=$data['branch_id'];
                    }
                    $subject->update($attrs);
                    if($subject->wasChanged('status') && $subject->status!=='active') $subject->tokens()->delete();
                }
                app(SecurityAudit::class)->recordDirectoryChange($actor,$subject,$user===null);
            });
        } catch(UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email'=>'Email sudah digunakan.']);
        }
        return redirect($agent?'/admin/agent-data':'/admin/customers')->with('workspace_status',
            $user?'Data akun diperbarui.':'Akun dibuat dalam keadaan tidak aktif. Gunakan pemulihan password privat setelah verifikasi dan aktivasi.');
    }
    public function createProject(Request $request)
    {
        abort_unless($request->user()->hasRole('admin')&&$request->user()->hasPermission('manage_master_data'),403);
        $data=$request->validate(['name'=>['required','string','max:180'],'description'=>['nullable','string','max:10000'],
            'developer_id'=>['required','integer',Rule::exists('developers','id')->whereNull('deleted_at')],
            'location_id'=>['required','integer',Rule::exists('locations','id')->where('city','Batam')->whereNull('deleted_at')]]);
        \Illuminate\Support\Facades\DB::transaction(function()use($data,$request){
            $source=Location::whereKey($data['location_id'])->where('city','Batam')->lockForUpdate()->firstOrFail();
            abort_unless($source->label,422,'Kawasan proyek belum tersedia.');
            // Never convert an Agent's resale location into shared inventory or copy its private address.
            $location=Location::create(['label'=>$source->label,'city'=>'Batam','visibility'=>'approximate','created_by'=>$request->user()->id]);
            $project=Project::create([...$data,'location_id'=>$location->id,'status'=>'active','created_by'=>$request->user()->id]);
            app(SecurityAudit::class)->record('project.created',$request->user(),['project_id'=>$project->id]);
        });
        return redirect('/admin/catalog')->with('workspace_status','Proyek dibuat. Penugasan Agent/cabang dikelola terpisah.');
    }
    public function updateProject(Request $request,Project $project)
    {
        abort_unless($request->user()->hasRole('admin')&&$request->user()->hasPermission('manage_master_data'),403);
        $data=$request->validate(['name'=>['required','string','max:180'],'description'=>['nullable','string','max:10000'],
            'status'=>['required',Rule::in(['active','inactive','completed'])]]);
        \Illuminate\Support\Facades\DB::transaction(function()use($data,$request,$project){
            Project::whereKey($project->id)->lockForUpdate()->firstOrFail()->update($data);
            app(SecurityAudit::class)->record('project.updated',$request->user(),['project_id'=>$project->id]);
        });
        return redirect('/admin/catalog')->with('workspace_status','Proyek diperbarui. Listing existing tidak diarsipkan otomatis.');
    }
    public function assignProject(Request $request,Project $project)
    {
        // Reject role/permission before looking up any client-supplied branch/Agent.
        $actor=$request->user();abort_unless(in_array($actor->role?->name,['admin','owner','branch_manager'],true)&&$actor->hasPermission('manage_project_assignments'),403);
        $data=$request->validate(['branch_id'=>['required','integer','min:1'],'agent_id'=>['nullable','integer','min:1']]);
        app(ProjectAssignments::class)->authorizeManager($actor,(int)$data['branch_id']);
        app(ProjectAssignments::class)->assign($actor,$project,Branch::findOrFail($data['branch_id']),
            ($data['agent_id']??null)?User::findOrFail($data['agent_id']):null);
        return back()->with('workspace_status','Penugasan proyek disimpan.');
    }
    public function createAgent(Request $request)
    {
        abort_unless($request->user()->hasRole('admin')&&$request->user()->hasPermission('verify_agents'),403);
        \Illuminate\Support\Facades\DB::transaction(function()use($request){
            $response=app(\App\Http\Controllers\Api\Admin\AgentController::class)->store($request);
            app(SecurityAudit::class)->record('agent.account_created',$request->user(),['subject_user_id'=>$response->getData(true)['agent']['id']]);
        });
        return redirect('/admin/agents')->with('workspace_status','Akun Agent dibuat, menunggu verifikasi. Akses login mengikuti OTP/pemulihan existing; tidak ada pesan otomatis dikirim.');
    }
    public function moderate(Request $request,Post $post)
    {
        abort_unless($request->user()->hasRole('admin')&&$request->user()->hasPermission('moderate_content'),403);
        $data=$request->validate(['moderation_status'=>['required',Rule::in(['active','under_review','removed'])],
            'moderation_reason'=>['required','string','max:1000']]);
        \Illuminate\Support\Facades\DB::transaction(function()use($request,$post,$data){
            $post=Post::whereKey($post->id)->lockForUpdate()->firstOrFail();
            if($post->moderation_status==='removed'&&$data['moderation_status']==='active')abort_unless($request->user()->hasPermission('restore_content'),403);
            $post->update([...$data,'moderated_by'=>$request->user()->id,'moderated_at'=>now()]);
            app(SecurityAudit::class)->record('content.moderated',$request->user(),['listing_id'=>$post->listing_id]);
        });
        return redirect('/admin/content')->with('workspace_status','Status moderasi disimpan.');
    }
    public function revokeProject(Request $request,ProjectAssignment $assignment)
    {
        app(ProjectAssignments::class)->revoke($request->user(),$assignment);
        return back()->with('workspace_status','Penugasan proyek dicabut. Listing existing tidak diarsipkan otomatis.');
    }
    public function userStatus(Request $request,User $user)
    {
        abort_unless($request->user()->hasRole('admin')&&$request->user()->hasPermission('manage_users'),403);
        Gate::authorize('update',$user);
        abort_unless(in_array($user->role?->name,['customer','agent','branch_manager'],true),403);
        $data=$request->validate(['status'=>['required',Rule::in(['active','inactive','suspended'])]]);
        \Illuminate\Support\Facades\DB::transaction(function()use($user,$data,$request){
            $current=User::whereKey($user->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($request->user())->authorize('update',$current);
            abort_unless(in_array($current->role?->name,['customer','agent','branch_manager'],true),403);
            $current->update($data);
        });
        return redirect('/admin/users')->with('workspace_status','Status akun diperbarui.');
    }
    public function branchStore(Request $request)
    {
        abort_unless($request->user()->hasRole('admin')&&$request->user()->hasPermission('manage_branches'),403);
        $data=$request->validate(['name'=>['required','string','max:150'],'agency_id'=>['required','integer','exists:agencies,id']]);
        \Illuminate\Support\Facades\DB::transaction(function()use($data,$request){
            $branch=Branch::create([...$data,'status'=>'active']);
            app(SecurityAudit::class)->record('branch.created',$request->user(),['branch_id'=>$branch->id]);
        });
        return redirect('/admin/branches')->with('workspace_status','Cabang dibuat.');
    }
    public function verifyAgent(Request $request,User $user)
    {
        abort_unless($request->user()->hasRole('admin')&&$request->user()->hasPermission('verify_agents'),403);
        app(\App\Http\Controllers\Api\Admin\AgentController::class)->verify($request,$user);
        return redirect('/admin/agents')->with('workspace_status','Verifikasi Agent disimpan.');
    }
}
