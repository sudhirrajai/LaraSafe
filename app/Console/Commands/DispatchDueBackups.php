<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use App\Models\Backup;
use App\Jobs\BackupProjectJob;

class DispatchDueBackups extends Command
{
    protected $signature = 'backups:dispatch-due';
    protected $description = 'Dispatch backups for projects whose backup date is due';

    public function handle(): int
    {
        $now = now();

        $backups = Backup::whereNotNull('backup_frequency')
            ->whereNotNull('next_backup_at')
            ->where('next_backup_at', '<=', $now)
            ->get();

        if ($backups->isEmpty()) {
            $this->info('No due backups found.');
            return Command::SUCCESS;
        }

        foreach ($backups as $backup) {
            BackupProjectJob::dispatch($backup);

            // Preserve scheduled time of day
            $baseTime = $backup->next_backup_at ? Carbon::parse($backup->next_backup_at) : $now;
            if ($backup->backup_time && str_contains($backup->backup_time, ':')) {
                [$hour, $minute] = explode(':', $backup->backup_time);
                $baseTime = $baseTime->setTime((int) $hour, (int) $minute, 0);
            }

            $next = match ($backup->backup_frequency) {
                'daily'   => $baseTime->copy()->addDay(),
                'weekly'  => $baseTime->copy()->addWeek(),
                'monthly' => $baseTime->copy()->addMonth(),
                default   => $baseTime->copy()->addDay(),
            };

            // If calculated time is in the past, step forward until in the future
            while ($next <= $now) {
                $next = match ($backup->backup_frequency) {
                    'daily'   => $next->addDay(),
                    'weekly'  => $next->addWeek(),
                    'monthly' => $next->addMonth(),
                    default   => $next->addDay(),
                };
            }

            $backup->update(['next_backup_at' => $next]);

            $projectName = $backup->project->name ?? 'Unknown Project';
            $this->info("Backup job dispatched for {$projectName}. Next scheduled: {$next->toDateTimeString()}");
        }

        return Command::SUCCESS;
    }
}