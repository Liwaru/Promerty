<?php

namespace App\Services;

use App\Models\{AuditLog, User};
use App\Support\SecurityRollout;
use Illuminate\Support\Facades\{Gate, Log};

class SecurityAudit
{
    private const EVENTS=['auth.login','auth.failed','auth.logout','auth.logout_all','auth.password_reset','auth.google_linked','auth.google_registered',
        'mfa.enrollment_started','mfa.enrolled','mfa.recovery_used','mfa.disabled',
        'account.access_changed','account.email_changed','account.password_changed','account.directory_created','account.directory_updated','listing.ownership_changed',
        'listing.draft_saved','listing.published','listing.archived','listing.media_uploaded','listing.media_removed',
        'authorization.denied','agent.verification_changed','branch.access_changed','listing.media_linked','role.permissions_changed',
        'auth.otp_requested','auth.recovery_requested','auth.managed_reset_requested','listing.media_reordered','project.assigned','project.revoked','project.created','project.updated','branch.created','agent.account_created','content.moderated'];
    private const CONTROLS=['role','permission','agent_verification','branch','active_account','policy','session','ownership'];
    public static function events(): array { return self::EVENTS; }
    public function record(string $event,?User $actor=null,array $context=[]): void
    {
        if(!SecurityRollout::enabled())return;
        $this->persist($event,$actor,$context);
    }
    public function recordDirectoryChange(User $actor, User $subject, bool $created): void
    {
        $this->persist($created?'account.directory_created':'account.directory_updated',$actor,
            ['subject_user_id'=>$subject->id,'channel'=>'web']);
    }
    public function recordManagedReset(User $actor, User $subject): void
    {
        $this->persist('auth.managed_reset_requested', $actor, ['subject_user_id'=>$subject->id,'channel'=>'web']);
    }
    public function recordLocalPasswordRotation(User $subject): AuditLog
    {
        if (!app()->runningInConsole() || !app()->environment('local')) {
            throw new \LogicException('Local console required.');
        }
        return $this->persist('account.password_changed', null, ['subject_user_id'=>$subject->id,'channel'=>'local_console']);
    }

    public function recordLocalAgentEmailChange(User $subject): AuditLog
    {
        if (!app()->runningInConsole() || !app()->environment('local')
            || $subject->id !== 2 || !$subject->hasRole('agent')) {
            throw new \LogicException('Local Agent email update required.');
        }

        return $this->persist('account.email_changed', null, [
            'subject_user_id' => $subject->id, 'channel' => 'local_console',
        ]);
    }

    public function recordRolePermissionChange(User $actor, \App\Models\Role $role, array $before, array $after): AuditLog
    {
        return $this->persist('role.permissions_changed', $actor, ['channel'=>'web','role_id'=>$role->id,
            'role_name'=>$role->name,'permissions_before'=>$before,'permissions_after'=>$after]);
    }

    public function recordLocalRolePermissionBootstrap(\App\Models\Role $role, array $before, array $after): AuditLog
    {
        if (!app()->runningInConsole() || !app()->environment('local') || $role->name!=='admin') {
            throw new \LogicException('Local Admin bootstrap required.');
        }
        return $this->persist('role.permissions_changed', null, ['channel'=>'local_console','role_id'=>$role->id,
            'role_name'=>$role->name,'permissions_before'=>$before,'permissions_after'=>$after]);
    }

    public function recordLocalInteractionGrant(\App\Models\Role $role, array $before, array $after): AuditLog
    {
        if (!app()->runningInConsole() || !app()->environment('local')
            || !in_array($role->name, \App\Support\RoleCatalog::roles(), true)
            || array_values(array_diff($after, $before)) !== ['interact_posts']
            || array_diff($before, $after) !== []) {
            throw new \LogicException('Local interaction grant required.');
        }

        return $this->persist('role.permissions_changed', null, ['channel'=>'local_console','role_id'=>$role->id,
            'role_name'=>$role->name,'permissions_before'=>$before,'permissions_after'=>$after]);
    }

    public function recordLocalBranchLink(User $subject, int $branch): AuditLog
    {
        if (!app()->runningInConsole() || !app()->environment('local') || !$subject->hasRole('branch_manager') || $subject->branch_id!==$branch) {
            throw new \LogicException('Local Branch Manager update required.');
        }
        return $this->persist('branch.access_changed', null, ['channel'=>'local_console','subject_user_id'=>$subject->id,'branch_id'=>$branch]);
    }
    private function persist(string $event, ?User $actor, array $context): AuditLog
    {
        if(!in_array($event,self::EVENTS,true))throw new \InvalidArgumentException('Unknown security event.');
        $at=now();$payload=['event'=>$event,'actor_id'=>$actor?->id,'at'=>$at->copy()->utc()->toIso8601String()];
        $payload['outcome']=match($event){'auth.failed','authorization.denied'=>'denied',
            'auth.otp_requested','auth.recovery_requested','auth.managed_reset_requested'=>'requested','mfa.enrollment_started'=>'pending',default=>'success'};
        if(in_array($context['channel']??null,['web','api','google','phone','local_console'],true))$payload['channel']=$context['channel'];
        foreach(['subject_user_id','listing_id','branch_id','project_id']as$key)if(is_int($context[$key]??null)&&$context[$key]>0)$payload[$key]=$context[$key];
        if(in_array($context['control']??null,self::CONTROLS,true))$payload['control']=$context['control'];
        if ($event==='role.permissions_changed') {
            foreach (['role_id','role_name','permissions_before','permissions_after'] as $field) $payload[$field]=$context[$field];
        }
        $payload['signature']=hash_hmac('sha256',json_encode($payload,JSON_THROW_ON_ERROR),(string)config('app.key'));
        $record=new AuditLog(['user_id'=>$actor?->id,'action'=>$event,'new_values'=>$payload]);
        if ($event==='role.permissions_changed') $record->fill(['target_type'=>\App\Models\Role::class,'target_id'=>$payload['role_id']]);
        $record->created_at=$at;$record->saveOrFail();
        return $record;
    }
    /** An unauthenticated request is not proof of recipient identity or delivery. */
    public function publicRequest(string $event,string $channel): void
    {
        if(!in_array($event,['auth.otp_requested','auth.recovery_requested'],true))
            throw new \InvalidArgumentException('Unknown public request event.');
        try{$this->record($event,null,['channel'=>$channel]);}
        catch(\Throwable){Log::error('security.audit_write_failed',['event'=>$event]);}
    }
    public function authentic(AuditLog $record): bool
    {
        $p=$record->new_values;if(!is_array($p))return false;$signature=$p['signature']??null;unset($p['signature']);
        $fields=['event','actor_id','at','outcome','channel','subject_user_id','listing_id','branch_id','project_id','control'];
        if ($record->action==='role.permissions_changed') {
            $fields=[...$fields,'role_id','role_name','permissions_before','permissions_after'];
            if ($record->target_type!==\App\Models\Role::class || ($p['role_id']??null)!==$record->target_id) return false;
        }
        if(array_diff(array_keys($p),$fields)
            ||!in_array($p['event']??null,self::EVENTS,true)||!is_string($p['at']??null))return false;
        if(!is_string($signature)||($p['event']??null)!==$record->action||($p['actor_id']??null)!==$record->user_id
            ||!hash_equals(hash_hmac('sha256',json_encode($p,JSON_THROW_ON_ERROR),(string)config('app.key')),$signature))return false;
        try{
            $signedAt=\Carbon\CarbonImmutable::createFromFormat('Y-m-d\TH:i:sP',$p['at']);
            // Two seconds permit historical records written across a clock tick;
            // age changes cannot make a forged record eligible for retention.
            return $signedAt&&$record->created_at&&abs($signedAt->timestamp-$record->created_at->timestamp)<=2;
        }catch(\Throwable){return false;}
    }
    /** Denial stays denied even if its telemetry fails. Never persist exception details. */
    public function denied(?User $actor,string $control,array $context=[]): void
    {
        if(!config('security.qa_rollout'))return;
        $safe=[];foreach(['subject_user_id','listing_id','branch_id']as$key)
            if(is_int($context[$key]??null)&&$context[$key]>0)$safe[$key]=$context[$key];
        if(in_array($control,self::CONTROLS,true))$safe['control']=$control;
        // Persist after the controller transaction unwinds, so a denied write's
        // rollback cannot erase its audit event. Nothing is sent to the client.
        if(app()->bound('request')&&($request=app('request'))->route()){
            $pending=$request->attributes->get('promerty.audit_denials',[]);
            if(count($pending)>=64){Log::error('security.audit_denial_queue_full');return;}
            $pending[]=[$actor,$safe];$request->attributes->set('promerty.audit_denials',$pending);return;
        }
        $this->writeDenial($actor,$safe);
    }
    private function writeDenial(?User $actor,array $safe): void
    {
        try{$this->record('authorization.denied',$actor,$safe);}
        catch(\Throwable){Log::error('security.audit_write_failed',['event'=>'authorization.denied']);}
    }
    public function flushDenials(\Illuminate\Http\Request $request): void
    {
        $pending=$request->attributes->get('promerty.audit_denials',[]);
        $request->attributes->remove('promerty.audit_denials');
        foreach($pending as[$actor,$safe])$this->writeDenial($actor,$safe);
    }
    /** Read-only bounded inspection, not deletion or an immutable-log guarantee. */
    public function review(User $viewer,int $limit=1000,int $afterId=0): array
    {
        abort_unless(SecurityRollout::enabled(),404);
        Gate::forUser($viewer)->authorize('viewAny',AuditLog::class);
        $days=config('security.audit_retention_days');
        abort_unless(is_int($days)&&$days>=1&&$days<=3650&&$limit>=1&&$limit<=5000&&$afterId>=0,422);
        $upper=(int)AuditLog::max('id');$scanned=0;$invalid=0;$expired=0;$last=$afterId;$more=false;
        $cutoff=now()->subDays($days);
        $rows=AuditLog::where('id','>',$afterId)->where('id','<=',$upper)->orderBy('id')->limit($limit+1)
            ->select(['id','user_id','action','created_at'])
            ->selectRaw('CASE WHEN length(new_values) <= 8192 THEN new_values ELSE NULL END AS new_values')->cursor();
        foreach($rows as$row){
            if($scanned===$limit){$more=true;break;}
            $scanned++;$last=(int)$row->id;
            try{$valid=$this->authentic($row)&&$row->created_at!==null;if($valid&&$row->created_at->lt($cutoff))$expired++;}
            catch(\Throwable){$valid=false;}
            if(!$valid)$invalid++;
        }
        return ['status'=>$invalid?'FAIL':($more?'PARTIAL':'PASS_SCANNED_WINDOW'),'scanned_rows'=>$scanned,
            'invalid_rows'=>$invalid,'expired_valid_rows'=>$expired,'retention_days'=>$days,
            'window_upper_id'=>$upper,'next_after_id'=>$more?$last:null,'dry_run'=>true];
    }
}
