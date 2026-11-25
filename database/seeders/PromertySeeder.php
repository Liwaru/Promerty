<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PromertySeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            ['name' => 'customer', 'level' => 1],
            ['name' => 'agent', 'level' => 2],
            ['name' => 'branch_manager', 'level' => 3],
            ['name' => 'admin', 'level' => 4],
            ['name' => 'owner', 'level' => 6],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(
                ['name' => $role['name']],
                ['level' => $role['level']]
            );
        }

        $permissions = [
            'interact_posts' => 'Like, comment, save, follow, share, dan download.',
            'message_agents' => 'Mengirim DM kepada Agent sesuai aturan verifikasi.',
            'request_surveys' => 'Mengajukan jadwal survey properti.',
            'contact_support' => 'Menghubungi Customer Service.',

            'create_listings' => 'Membuat listing properti.',
            'manage_own_listings' => 'Mengelola listing milik sendiri.',
            'create_posts' => 'Membuat post foto atau video.',
            'manage_own_posts' => 'Mengelola post milik sendiri.',
            'handle_own_surveys' => 'Menangani permintaan survey pada listing sendiri.',
            'view_own_analytics' => 'Melihat analytics Agent sendiri.',

            'view_branch_dashboard' => 'Melihat dashboard cabang.',
            'view_branch_agents' => 'Melihat Agent pada cabang.',
            'view_branch_listings' => 'Melihat listing pada cabang.',
            'view_branch_surveys' => 'Melihat survey pada cabang.',

            'verify_agents' => 'Memverifikasi dan mengaktifkan Agent.',
            'manage_users' => 'Mengelola akun pengguna sesuai kewenangan.',
            'handle_support' => 'Menangani Customer Service.',
            'moderate_content' => 'Memoderasi post dan komentar.',
            'manage_master_data' => 'Mengelola developer, project, kategori, dan data pendukung.',
            'manage_project_assignments' => 'Mengelola penugasan proyek dengan batas role dan cabang.',
            'restore_content' => 'Memulihkan data yang dihapus sesuai kewenangan.',
            'view_operational_analytics' => 'Melihat analytics operasional.',

            'manage_agencies' => 'Mengelola agency.',
            'manage_branches' => 'Mengelola cabang.',
            'manage_roles' => 'Mengelola role.',
            'manage_permissions' => 'Mengelola permission.',
            'view_global_analytics' => 'Melihat analytics global.',
            'view_audit_logs' => 'Melihat audit log.',
            'manage_system_settings' => 'Mengelola pengaturan sistem.',
            'manage_super_admins' => 'Mengelola akun Super Admin.',
        ];

        foreach ($permissions as $name => $description) {
            Permission::updateOrCreate(
                ['name' => $name],
                ['description' => $description]
            );
        }

        $map = \App\Support\RoleCatalog::PERMISSIONS;

        DB::transaction(function () use ($map) {
            foreach ($map as $roleName => $permissionNames) {
                $role = Role::where('name', $roleName)->firstOrFail();

                $permissionIds = Permission::whereIn('name', $permissionNames)
                    ->pluck('id');

                $role->permissions()->sync($permissionIds);
            }
        });
    }
}
