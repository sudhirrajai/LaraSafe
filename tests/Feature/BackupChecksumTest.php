<?php

namespace Tests\Feature;

use App\Models\CreatedBackup;
use App\Models\Backup;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupChecksumTest extends TestCase
{
    use RefreshDatabase;

    public function test_calculate_checksum_and_verify_integrity(): void
    {
        Storage::fake('local');

        $content = 'LaraSafe Test Backup Payload ' . uniqid();
        $expectedChecksum = hash('sha256', $content);
        $path = 'private/backups/test-backup.zip';

        Storage::disk('local')->put($path, $content);

        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'Test Project',
            'path' => 'D:/dummy',
            'type' => 'laravel',
            'status' => 'active',
        ]);

        $backup = Backup::create([
            'project_id' => $project->id,
            'file_name' => 'test-backup',
            'storage_disk' => 'local',
            'status' => 'pending',
        ]);

        $createdBackup = CreatedBackup::create([
            'backup_id' => $backup->id,
            'file_name' => 'test-backup.zip',
            'file_path' => $path,
            'file_size' => strlen($content),
            'storage_disk' => 'local',
            'type' => 'full',
            'checksum' => $expectedChecksum,
            'status' => 'completed',
        ]);

        // Verify that calculated checksum matches expected sha256
        $calculated = $createdBackup->calculateChecksum();
        $this->assertEquals($expectedChecksum, $calculated);

        // Verify integrity returns true
        $this->assertTrue($createdBackup->verifyIntegrity());

        // Corrupt file and verify integrity returns false
        Storage::disk('local')->put($path, 'Corrupted data');
        $this->assertFalse($createdBackup->verifyIntegrity());
    }

    public function test_verify_backup_integrity_console_command(): void
    {
        Storage::fake('local');

        $content = 'Sample Archive Content';
        $checksum = hash('sha256', $content);
        $path = 'private/backups/verified.zip';
        Storage::disk('local')->put($path, $content);

        $user = User::factory()->create();
        $project = Project::create([
            'user_id' => $user->id,
            'name' => 'Integrity Project',
            'path' => 'D:/dummy',
            'type' => 'laravel',
            'status' => 'active',
        ]);

        $backup = Backup::create([
            'project_id' => $project->id,
            'file_name' => 'integrity-backup',
            'storage_disk' => 'local',
            'status' => 'pending',
        ]);

        CreatedBackup::create([
            'backup_id' => $backup->id,
            'file_name' => 'verified.zip',
            'file_path' => $path,
            'file_size' => strlen($content),
            'storage_disk' => 'local',
            'type' => 'full',
            'checksum' => $checksum,
            'status' => 'completed',
        ]);

        $this->artisan('backups:verify-integrity')
            ->expectsOutputToContain('Verification complete')
            ->assertExitCode(0);
    }
}
