<?php

namespace App\Support;

/** Target roles. Legacy database roles are retained, never silently renamed. */
final class RoleCatalog
{
    public const PERSONAL_INTERACTION_ROLES = ['branch_manager', 'admin', 'owner'];

    public const PERMISSIONS = [
        'customer'=>['interact_posts','message_agents','request_surveys','contact_support'],
        'agent'=>['interact_posts','message_agents','request_surveys','contact_support','create_listings','manage_own_listings','create_posts','manage_own_posts','handle_own_surveys','view_own_analytics'],
        'branch_manager'=>['interact_posts','contact_support','view_branch_dashboard','view_branch_agents','view_branch_listings','view_branch_surveys'],
        'admin'=>['interact_posts','contact_support','verify_agents','manage_users','handle_support','moderate_content','manage_master_data','manage_project_assignments','restore_content','view_operational_analytics','manage_agencies','manage_branches','view_audit_logs','manage_system_settings','manage_permissions'],
        'owner'=>['interact_posts','contact_support','view_global_analytics','view_operational_analytics'],
    ];
    public static function permissions(string $role): array { return self::PERMISSIONS[$role] ?? []; }
    public static function roles(): array { return array_keys(self::PERMISSIONS); }
}
