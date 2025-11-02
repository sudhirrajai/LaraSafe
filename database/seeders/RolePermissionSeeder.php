<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class RolePermissionSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        $this->command->info('Creating permissions...');

        // Create permissions
        $permissions = [
            // User Management
            'manage users',
            'create users',
            'edit users',
            'delete users',
            'view users',
            
            // Project Management
            'create project',
            'edit project',
            'delete project',
            'view project',
            
            // Backup Management
            'create backup',
            'edit backup',
            'delete backup',
            'download backup',
            'restore backup',
            'view backup',
        ];

        foreach ($permissions as $permission) {
            Permission::firstOrCreate(
                ['name' => $permission],
                ['guard_name' => 'web']
            );
        }

        $this->command->info('Permissions created!');
        $this->command->info('Creating roles...');

        // Admin Role - has all permissions
        $adminRole = Role::firstOrCreate(
            ['name' => 'admin'],
            ['guard_name' => 'web']
        );
        $adminRole->syncPermissions(Permission::all());

        // Manager Role - can manage users, projects, and backups but CANNOT delete projects
        $managerRole = Role::firstOrCreate(
            ['name' => 'manager'],
            ['guard_name' => 'web']
        );
        $managerRole->syncPermissions([
            'manage users',
            'create users',
            'edit users',
            'view users',
            'create project',
            'edit project',
            'view project',
            // NO 'delete project'
            'create backup',
            'edit backup',
            'delete backup',
            'download backup',
            'restore backup',
            'view backup',
        ]);

        // User Role - can create and download backups only
        $userRole = Role::firstOrCreate(
            ['name' => 'user'],
            ['guard_name' => 'web']
        );
        $userRole->syncPermissions([
            'view project',
            'create backup',
            'download backup',
            'view backup',
        ]);

        // Viewer Role - read-only access (view and download only)
        $viewerRole = Role::firstOrCreate(
            ['name' => 'viewer'],
            ['guard_name' => 'web']
        );
        $viewerRole->syncPermissions([
            'view project',
            'view backup',
            'download backup',
        ]);

        $this->command->info('Roles created!');
        $this->command->info('Setting up admin user...');

        // Find or create admin user
        $adminUser = User::where('email', 'sudhirrajai@proton.me')->first();
        
        if (!$adminUser) {
            $adminUser = User::create([
                'name' => 'Admin User',
                'email' => 'sudhirrajai@proton.me',
                'password' => bcrypt('12345678'),
            ]);
            $this->command->info('Admin user created!');
        } else {
            $this->command->info('Admin user already exists!');
        }

        // Remove all existing roles
        DB::table('model_has_roles')
            ->where('model_type', User::class)
            ->where('model_id', $adminUser->id)
            ->delete();

        // Assign admin role
        $adminUser->assignRole('admin');
        $adminUser->syncPermissions(Permission::all());

        // Verify assignment
        $adminUser->refresh();
        $rolesCount = $adminUser->roles()->count();
        $permissionsCount = $adminUser->getAllPermissions()->count();

        $this->command->info('Admin user assigned admin role!');
        $this->command->info("User has {$rolesCount} role(s) and {$permissionsCount} permission(s)");
        $this->command->info('-----------------------------------');
        $this->command->info('Role Permissions Summary:');
        $this->command->info('Admin: Full access to everything');
        $this->command->info('Manager: Can manage users, projects (except delete), and all backups');
        $this->command->info('User: Can create and download backups, view projects');
        $this->command->info('Viewer: Can only view and download');
        $this->command->info('-----------------------------------');
    }
}