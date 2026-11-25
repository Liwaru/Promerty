<?php
namespace App\Services;
use App\Models\{Branch,Project,ProjectAssignment,User};
use Illuminate\Support\Facades\{DB,Schema};
class ProjectAssignments
{
    public function available():bool{return Schema::hasTable('project_assignments');}
    public function projects(User $agent)
    {
        abort_unless($agent->hasRole('agent')&&$agent->isActive()&&$agent->agentProfile?->isVerified()
            &&$agent->branch?->status==='active'&&$agent->hasPermission('create_listings'),403);
        abort_unless($this->available(),503,'Penugasan proyek belum tersedia pada lingkungan ini.');
        return Project::where('status','active')->whereIn('id',ProjectAssignment::where('active',true)
            ->where('branch_id',$agent->branch_id)->where(fn($q)=>$q->whereNull('agent_id')->orWhere('agent_id',$agent->id))->select('project_id'));
    }
    public function assigned(User $agent,int $project):Project
    {
        $query=$this->projects($agent)->whereKey($project);
        if(DB::transactionLevel()>0)$query->lockForUpdate();
        return $query->firstOrFail();
    }
    public function authorizeManager(User $actor,int $branch):void
    {
        abort_unless($actor->isActive()&&in_array($actor->role?->name,['admin','owner','branch_manager'],true)&&$actor->hasPermission('manage_project_assignments'),403);
        if($actor->hasRole('branch_manager'))abort_unless($actor->branch_id===$branch&&$actor->branch?->status==='active',403);
    }
    public function assign(User $actor,Project $project,Branch $branch,?User $agent):ProjectAssignment
    {
        $this->authorizeManager($actor,$branch->id);abort_unless($this->available(),503);
        abort_unless($project->status==='active'&&$branch->status==='active',422);
        abort_unless(!$agent||($agent->hasRole('agent')&&$agent->branch_id===$branch->id&&$agent->isActive()),422);
        return DB::transaction(function()use($actor,$project,$branch,$agent){
            $project=Project::whereKey($project->id)->lockForUpdate()->firstOrFail();
            $branch=Branch::whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $users=User::whereIn('id',array_filter([$actor->id,$agent?->id]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $actor=$users->get($actor->id);abort_unless($actor,403);
            $this->authorizeManager($actor,$branch->id);
            if($agent){$agent=$users->get($agent->id);abort_unless($agent&&$agent->hasRole('agent')&&$agent->branch_id===$branch->id&&$agent->isActive(),422);}
            abort_unless($project->status==='active'&&$branch->status==='active',422);
            $key=$agent?'agent:'.$agent->id:'branch:'.$branch->id;
            $assignment=ProjectAssignment::updateOrCreate(['project_id'=>$project->id,'assignment_key'=>$key],
                ['branch_id'=>$branch->id,'agent_id'=>$agent?->id,'assigned_by'=>$actor->id,'active'=>true]);
            app(SecurityAudit::class)->record('project.assigned',$actor,['project_id'=>$project->id,'branch_id'=>$branch->id,'subject_user_id'=>$agent?->id]);
            return $assignment;
        });
    }
    public function revoke(User $actor,ProjectAssignment $assignment):void
    {
        $this->authorizeManager($actor,$assignment->branch_id);
        DB::transaction(function()use($actor,$assignment){
            Project::whereKey($assignment->project_id)->lockForUpdate()->firstOrFail();
            $current=ProjectAssignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            abort_unless($current->project_id===$assignment->project_id,409);
            $actor=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->authorizeManager($actor,$current->branch_id);$assignment=$current;
            $assignment->update(['active'=>false]);
            app(SecurityAudit::class)->record('project.revoked',$actor,['project_id'=>$assignment->project_id,'branch_id'=>$assignment->branch_id,'subject_user_id'=>$assignment->agent_id]);
        });
    }
}
