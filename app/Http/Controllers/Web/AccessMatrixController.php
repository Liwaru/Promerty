<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\{AuditLog, Permission, Role, User};
use App\Services\SecurityAudit;
use App\Support\{AccessMatrix, RoleCatalog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\{Rule, ValidationException};

final class AccessMatrixController extends Controller
{
    public function index(Request $request, SecurityAudit $audit)
    {
        $filters = $request->validate(['q'=>['nullable','string','max:100'],
            'role'=>['nullable',Rule::in(array_keys(AccessMatrix::LABELS))],
            'category'=>['nullable',Rule::in([...array_keys(AccessMatrix::GROUPS),'Lainnya'])]]);
        $roles = Role::with('permissions')->whereIn('name',array_keys(AccessMatrix::LABELS))->get()->keyBy('name');
        $permissionNames = Permission::pluck('name')->all();
        $rows = AccessMatrix::GROUPS;
        $additional = array_diff($permissionNames, array_keys(array_merge(...array_values($rows))));
        $rows['Lainnya'] = array_combine($additional, array_map(fn ($code)=>str($code)->replace('_',' ')->headline()->toString(),$additional));
        $roles = collect(AccessMatrix::LABELS)->map(fn ($label,$name)=>$roles->get($name))->filter();
        $grants = $roles->map(fn (Role $role)=>$role->permissions->pluck('name')->sort()->values()->all());
        $revisions = $grants->map(fn ($permissions,$name)=>$this->revision($name,$permissions));
        if ($grants->has('owner')) $grants['owner'] = array_intersect($grants['owner'], [...RoleCatalog::permissions('owner'),'manage_project_assignments']);
        $history = AuditLog::where('action','role.permissions_changed')->latest('id')->limit(30)->get()
            ->filter(fn (AuditLog $entry)=>$audit->authentic($entry));
        $sections=OperationsWorkspaceController::availableSections($request->user());
        return view('operations.access-matrix', compact('roles','rows','grants','revisions','permissionNames','history','filters','sections'));
    }

    public function update(Request $request, string $role)
    {
        abort_unless(isset(AccessMatrix::EDITABLE[$role]), 403);
        $data = $request->validate(['changes'=>['required','array','min:1','max:20'],'changes.*'=>['required','boolean'],
            'revision'=>['required','string','size:64'],'confirm_change'=>['accepted']]);
        $changes = array_map(fn ($enabled)=>(bool)$enabled,$data['changes']);
        $this->apply($request->user(),$role,$changes,$data['revision']);
        return redirect()->route('admin.access.index',['role'=>$role])->with('workspace_status','Hak akses disimpan.');
    }

    public function rollback(Request $request, AuditLog $entry, SecurityAudit $audit)
    {
        $request->validate(['confirm_change'=>['accepted']]);
        abort_unless($entry->action==='role.permissions_changed' && $audit->authentic($entry), 404);
        $state=$entry->new_values;
        $role=$state['role_name'];
        abort_unless(isset(AccessMatrix::EDITABLE[$role]), 403);
        $changed=array_unique([...array_diff($state['permissions_before'],$state['permissions_after']),
            ...array_diff($state['permissions_after'],$state['permissions_before'])]);
        $changes=[];
        foreach ($changed as $code) $changes[$code]=in_array($code,$state['permissions_before'],true);
        $this->apply($request->user(),$role,$changes,$this->revision($role,$state['permissions_after']),$entry);
        return redirect()->route('admin.access.index',['role'=>$role])->with('workspace_status','Hak akses sebelumnya dipulihkan.');
    }

    private function apply(User $actor, string $name, array $changes, string $revision, ?AuditLog $entry = null): void
    {
        abort_if(array_diff(array_keys($changes),AccessMatrix::EDITABLE[$name]),403);
        DB::transaction(function () use ($actor,$name,$changes,$revision,$entry) {
            $currentActor=User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            abort_unless($currentActor->isActive() && $currentActor->hasRole('admin') && $currentActor->hasPermission('manage_permissions'),403);
            $role=Role::where('name',$name)->lockForUpdate()->firstOrFail();
            $before=$role->permissions()->orderBy('permissions.name')->pluck('permissions.name')->all();
            $latest=$entry ? AuditLog::where('action','role.permissions_changed')->where('target_type',Role::class)
                ->where('target_id',$role->id)->max('id') : null;
            if (!hash_equals($this->revision($name,$before),$revision) || ($entry && (int)$latest!==$entry->id)) {
                throw ValidationException::withMessages(['changes'=>'Hak akses sudah berubah. Muat ulang sebelum melanjutkan.']);
            }
            $ids=Permission::whereIn('name',array_keys($changes))->pluck('id','name');
            abort_unless($ids->count()===count($changes),422,'Permission tidak tersedia.');
            foreach ($changes as $code=>$enabled) {
                if ($enabled===in_array($code,$before,true)) continue;
                if ($enabled) $role->permissions()->attach($ids[$code]);
                else $role->permissions()->detach($ids[$code]);
            }
            $after=$role->permissions()->orderBy('permissions.name')->pluck('permissions.name')->all();
            if ($before!==$after) app(SecurityAudit::class)->recordRolePermissionChange($currentActor,$role,$before,$after);
        });
    }

    private function revision(string $role, array $permissions): string
    {
        sort($permissions,SORT_STRING);
        return hash_hmac('sha256',json_encode([$role,$permissions],JSON_THROW_ON_ERROR),(string)config('app.key'));
    }
}
