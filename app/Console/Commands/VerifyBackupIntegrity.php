<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class VerifyBackupIntegrity extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backups:verify-integrity {--limit=50 : Maximum number of backups to verify}';

    protected $description = 'Verify SHA256 checksum integrity of backup files';

    public function handle(): int
    {
        $limit = (int) $this->option('limit');
        $this->info("Scanning up to {$limit} backups for integrity verification...");

        $backups = \App\Models\CreatedBackup::with('backup.project')
            ->whereNotNull('checksum')
            ->latest()
            ->limit($limit)
            ->get();

        if ($backups->isEmpty()) {
            $this->info('No backups with checksums found.');
            return Command::SUCCESS;
        }

        $passed = 0;
        $failed = 0;

        foreach ($backups as $backup) {
            $projectName = $backup->backup->project->name ?? 'Unknown';
            $this->line("Verifying [{$backup->storage_disk}] {$projectName}/{$backup->file_name}...");

            if ($backup->verifyIntegrity()) {
                $passed++;
                $this->info("  ✓ Integrity OK");
            } else {
                $failed++;
                $this->error("  ✗ Checksum verification failed or file missing!");
                \Illuminate\Support\Facades\Log::warning("Backup integrity check failed", [
                    'backup_id' => $backup->id,
                    'file_name' => $backup->file_name,
                    'storage_disk' => $backup->storage_disk,
                ]);
            }
        }

        $this->info("\nVerification complete. Passed: {$passed}, Failed: {$failed}");
        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }
}
