<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Route;

final class LoginDestination
{
    public static function for(User $user): array
    {
        $name=$user->role?->name;
        $route=match($name){'agent'=>'agent.dashboard','branch_manager'=>'branch.dashboard','admin'=>'admin.dashboard','owner'=>'owner.dashboard',default=>null};
        $available=$route && Route::has($route);
        if ($name === 'agent') $available = $available && $user->agentProfile?->isVerified()
            && $user->branch?->status === 'active' && $user->hasPermission('manage_own_listings');
        if ($name === 'branch_manager') $available = $available && $user->branch?->status==='active' && $user->hasPermission('view_branch_dashboard');
        if ($name === 'admin') $available = $available && $user->hasPermission('view_operational_analytics');
        if ($name === 'owner') $available = $available && $user->hasPermission('view_global_analytics');
        return ['redirect_to'=>$available?route($route,absolute:false):'/', 'dashboard_available'=>$name==='customer'||(bool)$available];
    }
}
