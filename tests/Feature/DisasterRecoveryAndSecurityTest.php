<?php

namespace Tests\Feature;

use App\Jobs\BackupProjectJob;
use App\Jobs\RestoreBackupJob;
use App\Models\Backup;
use App\Models\CreatedBackup;
use App\Models\Project;
use App\Models\User;
use App\Services\DynamicStorageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DisasterRecoveryAndSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Seed roles & permissions
        $permissions = [
            'view project', 'create project', 'edit project', 'delete project',
            'view backup', 'create backup', 'edit backup', 'delete backup',
            'download backup', 'restore backup', 'manage settings'
        ];

        foreach ($permissions as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'web']);
        }

        $adminRole = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());

        $userRole = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);
        $userRole->syncPermissions(['view project', 'view backup', 'download backup', 'create backup']);
    }

    public function test_settings_routes_require_manage_settings_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        // Normal user should be rejected (403 or redirect with error)
        $response = $this->actingAs($user)->get('/settings');
        $this->assertTrue(in_array($response->status(), [302, 403]));

        // Admin user can view settings
        $admin = User::factory()->create();
        $admin->assignRole('admin');
        $adminResponse = $this->actingAs($admin)->get('/settings');
        $adminResponse->assertStatus(200);
    }

    public function test_idor_protection_prevents_unauthorized_backup_download(): void
    {
        $userA = User::factory()->create();
        $userA->assignRole('user');

        $userB = User::factory()->create();
        $userB->assignRole('user');

        $projectB = Project::create([
            'user_id' => $userB->id,
            'name' => 'Project B',
            'path' => 'D:/dummy/b',
        ]);

        $backupB = Backup::create([
            'project_id' => $projectB->id,
            'file_name' => 'backup_b',
            'storage_disk' => 'local',
            'status' => 'completed',
        ]);

        Storage::fake('local');
        $filePath = 'private/backups/Project B/backup_b.tar.gz';
        Storage::disk('local')->put($filePath, 'fake content');

        $createdBackupB = CreatedBackup::create([
            'backup_id' => $backupB->id,
            'file_name' => 'backup_b.tar.gz',
            'file_path' => $filePath,
            'size' => 12,
            'storage_disk' => 'local',
            'checksum' => hash('sha256', 'fake content'),
        ]);

        // User A attempts to download User B's backup -> 403 Forbidden
        $response = $this->actingAs($userA)->get("/backups/download/{$createdBackupB->id}");
        $response->assertStatus(403);

        // User B can access their own backup
        // Local path in test storage exists
        $userBResponse = $this->actingAs($userB)->get("/backups/download/{$createdBackupB->id}");
        // Even if file doesn't exist on physical disk during test, authorization passed (did not return 403)
        $this->assertNotEquals(403, $userBResponse->status());
    }

    public function test_backup_creates_tar_gz_with_disaster_recovery_bundle_and_restores(): void
    {
        // 1. Create a dummy project directory to back up
        $projectDir = storage_path('app/temp/test_project_source_' . uniqid());
        mkdir($projectDir, 0755, true);
        file_put_contents($projectDir . '/index.php', '<?php echo "Hello Disaster Recovery";');
        file_put_contents($projectDir . '/.env', "DB_HOST=127.0.0.1\nDB_DATABASE=test_db\nDB_USERNAME=root\nDB_PASSWORD=secret");

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $project = Project::create([
            'user_id' => $admin->id,
            'name' => 'DR Project',
            'path' => $projectDir,
        ]);

        $backup = Backup::create([
            'project_id' => $project->id,
            'file_name' => 'dr_backup',
            'storage_disk' => 'local',
            'status' => 'pending',
            'include_database' => false,
        ]);

        // Execute BackupJob
        $storageService = app(DynamicStorageService::class);
        $job = new BackupProjectJob($backup);
        $job->handle($storageService);

        $backup->refresh();
        $this->assertEquals('success', $backup->status);

        $createdBackup = $backup->createdBackups()->first();
        $this->assertNotNull($createdBackup);
        $this->assertStringEndsWith('.tar.gz', $createdBackup->file_name);
        $this->assertNotEmpty($createdBackup->checksum);

        // Verify the physical backup archive exists
        $archivePath = storage_path('app/' . $createdBackup->file_path);
        $this->assertFileExists($archivePath);

        // 2. Test Restoration
        $restoreDir = storage_path('app/temp/test_project_restore_' . uniqid());
        $project->update(['path' => $restoreDir]);

        $restoreJob = new RestoreBackupJob($createdBackup);
        $restoreJob->handle($storageService);

        // Verify restored files exist
        $this->assertFileExists($restoreDir . '/index.php');
        $this->assertStringEqualsFile($restoreDir . '/index.php', '<?php echo "Hello Disaster Recovery";');

        // Verify temporary restore scripts were cleaned up from the project root
        $this->assertFileDoesNotExist($restoreDir . '/restore.sh');
        $this->assertFileDoesNotExist($restoreDir . '/restore.bat');

        // Cleanup test directories and archives
        @unlink($projectDir . '/index.php');
        @unlink($projectDir . '/.env');
        @rmdir($projectDir);

        @unlink($restoreDir . '/index.php');
        @unlink($restoreDir . '/.env');
        @rmdir($restoreDir);

        if (file_exists($archivePath)) {
            @unlink($archivePath);
            @rmdir(dirname($archivePath));
        }
    }

    public function test_nextjs_and_react_projects_full_directory_backup_and_recovery(): void
    {
        // Create simulated Next.js / React project directory
        $projectDir = storage_path('app/temp/test_nextjs_project_' . uniqid());
        mkdir($projectDir . '/pages', 0755, true);
        mkdir($projectDir . '/public', 0755, true);
        mkdir($projectDir . '/node_modules/sample-pkg', 0755, true);

        $packageJson = json_encode([
            'name' => 'my-nextjs-app',
            'version' => '1.0.0',
            'scripts' => [
                'dev' => 'next dev',
                'build' => 'next build',
                'start' => 'next start'
            ],
            'dependencies' => [
                'next' => '14.0.0',
                'react' => '^18.2.0',
                'react-dom' => '^18.2.0'
            ]
        ], JSON_PRETTY_PRINT);

        file_put_contents($projectDir . '/package.json', $packageJson);
        file_put_contents($projectDir . '/pages/index.js', 'export default function Home() { return <h1>Hello Next.js</h1>; }');
        file_put_contents($projectDir . '/public/robots.txt', 'User-agent: *');
        file_put_contents($projectDir . '/node_modules/sample-pkg/index.js', 'module.exports = "sample";');

        $admin = User::factory()->create();
        $admin->assignRole('admin');

        $project = Project::create([
            'user_id' => $admin->id,
            'name' => 'NextJS React App',
            'path' => $projectDir,
        ]);

        $backup = Backup::create([
            'project_id' => $project->id,
            'file_name' => 'nextjs_backup',
            'storage_disk' => 'local',
            'status' => 'pending',
            'include_database' => false,
        ]);

        $storageService = app(DynamicStorageService::class);
        $job = new BackupProjectJob($backup);
        $job->handle($storageService);

        $backup->refresh();
        $this->assertEquals('success', $backup->status);

        $createdBackup = $backup->createdBackups()->first();
        $this->assertNotNull($createdBackup);
        $this->assertStringEndsWith('.tar.gz', $createdBackup->file_name);

        $archivePath = storage_path('app/' . $createdBackup->file_path);
        $this->assertFileExists($archivePath);

        // Restore into a clean destination directory
        $restoreDir = storage_path('app/temp/test_nextjs_restore_' . uniqid());
        $project->update(['path' => $restoreDir]);

        $restoreJob = new RestoreBackupJob($createdBackup);
        $restoreJob->handle($storageService);

        // Verify full project directory restored including Next.js files and node_modules
        $this->assertFileExists($restoreDir . '/package.json');
        $this->assertFileExists($restoreDir . '/pages/index.js');
        $this->assertFileExists($restoreDir . '/public/robots.txt');
        $this->assertFileExists($restoreDir . '/node_modules/sample-pkg/index.js');
        $this->assertStringContainsString('my-nextjs-app', file_get_contents($restoreDir . '/package.json'));

        // Cleanup
        $this->deleteTestDirectory($projectDir);
        $this->deleteTestDirectory($restoreDir);
        if (file_exists($archivePath)) {
            @unlink($archivePath);
            @rmdir(dirname($archivePath));
        }
    }

    private function deleteTestDirectory(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($path) ? $this->deleteTestDirectory($path) : @unlink($path);
        }
        @rmdir($dir);
    }
}
