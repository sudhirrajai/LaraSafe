<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

class CleanupExpiredBackups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:cleanup-expired-backups';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Clean up expired backup files from storage and database';

    public function handle(): int
    {
        $this->info('Checking for expired backups...');

        $expiredBackups = \App\Models\CreatedBackup::whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        if ($expiredBackups->isEmpty()) {
            $this->info('No expired backups found.');
            return Command::SUCCESS;
        }

        $deletedCount = 0;
        $freedSpace = 0;

        foreach ($expiredBackups as $backup) {
            $freedSpace += $backup->size ?? 0;
            $fileName = $backup->file_name;
            
            // Delete physical file (supports both local and cloud disks)
            $backup->deleteFile();

            // Delete database record
            $backup->delete();
            $deletedCount++;

            $this->line("Deleted expired backup: {$fileName}");
        }

        \Illuminate\Support\Facades\Log::info("Expired backups cleanup completed: {$deletedCount} backups removed.");
        $this->info("Cleaned up {$deletedCount} expired backup(s).");

        return Command::SUCCESS;
    }
}
