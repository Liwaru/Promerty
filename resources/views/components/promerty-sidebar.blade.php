@props(['active' => ''])
@php
    $viewer = auth('web')->user();
    $role = $viewer?->isActive() ? $viewer->role?->name : null;
@endphp
@if($role === 'agent')
    <x-workspace-sidebar label="Ruang Agent" :active="$active" agent
        :groups="['Workspace Agent' => \App\Http\Controllers\Web\AgentWorkspaceController::navigation($viewer, app(\App\Services\ProjectAssignments::class))]" />
@elseif(in_array($role, ['branch_manager', 'admin', 'owner'], true))
    <x-operations-navigation :role="$role" :section="$active"
        :sections="\App\Http\Controllers\Web\OperationsWorkspaceController::availableSections($viewer)" />
@else
    <x-workspace-sidebar :label="$role === 'customer' ? 'Ruang Customer' : 'Promerty'" :active="$active" />
@endif
