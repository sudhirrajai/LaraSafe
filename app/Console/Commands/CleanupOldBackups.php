<?php

namespace App\Console\Commands;

use App\Jobs\AutoDeleteOldBackupsJob;
use Illuminate\Console\Command;

class CleanupOldBackups extends Command
{
    protected $signature = 'backups:cleanup
                          {--force : Force cleanup without confirmation}';

    protected $description = 'Manually trigger cleanup of old backups based on auto-delete settings';

    public function handle()
    {
        if (!$this->option('force')) {
            if (!$this->confirm('This will delete old backups based on auto-delete settings. Continue?')) {
                $this->info('Cleanup cancelled.');
                return 0;
            }
        }

        $this->info('Starting backup cleanup...');
        
        AutoDeleteOldBackupsJob::dispatch();
        
        $this->info('Cleanup job dispatched successfully!');
        $this->comment('Check logs for detailed results.');
        
        return 0;
    }
}