@props(['role','sections','section','branchManagers'=>false])
@php
    $prefix=['branch_manager'=>'branch','admin'=>'admin','owner'=>'owner'][$role];
    $roleLabel=['branch_manager'=>'Ruang Cabang','admin'=>'Administrasi','owner'=>'Ruang Owner'][$role];
    $groups=[];
    $layout = match($role) {
        'branch_manager'=>['Dashboard'=>['overview'],'Operasional cabang'=>['agents','listings','projects']],
        'admin'=>['Dashboard'=>['overview'],'Manajemen pengguna'=>['customers','agent-data','branch-managers','agents','users'],
            'Manajemen properti'=>['listings','catalog','projects','content'],'Sistem'=>['access','branches','audit']],
        'owner'=>['Dashboard'=>['overview','reports'],'Operasional'=>['projects']],
    };
    if ($role==='admin' && auth('web')->user()?->hasPermission('manage_permissions')) $sections['access']=['Hak Akses','manage_permissions'];
    foreach ($layout as $label=>$keys) {
        if ($role==='admin' && isset($sections['users']) && in_array('branch-managers',$keys,true)) {
            $sections['branch-managers']=['Data Branch Manager','manage_users'];
        }
        foreach($keys as $key) if(isset($sections[$key])){
            if ($key==='audit' && !config('security.qa_rollout')) continue;
            $icon=match($key){'overview'=>'monitor','customers','agent-data','agents','users','branch-managers'=>'user','reports','listings'=>'saved','content','audit'=>'activity','access'=>'lock',default=>'home'};
            $href=$key==='branch-managers'?'/admin/users?account_role=branch_manager':'/'.$prefix.'/'.$key;
            $groups[$label][$key]=[$key==='users'?'Semua pengguna':$sections[$key][0],$href,$icon];
        }
    }
    if ($role==='branch_manager' && isset($sections['overview'])) {
        $groups['Operasional cabang']['branch-activity']=['Aktivitas Cabang','/branch/overview#branch-activity','activity'];
    }
@endphp
<x-workspace-sidebar :label="$roleLabel" :groups="$groups" :active="$branchManagers?'branch-managers':$section" />
