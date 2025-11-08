<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Models\CreatedBackup;
use App\Services\DynamicStorageService;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class AutoDeleteOldBackupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 3600; // 1 hour timeout
    public $tries = 3;

    protected $storageService;

    public function __construct()
    {
        //
    }

    public function handle(DynamicStorageService $storageService): void
    {
        $this->storageService = $storageService;

        Log::info('Starting auto-delete old backups job');

        // Get all backup configurations with auto-delete enabled
        $backupConfigs = Backup::where('auto_delete_enabled', true)
            ->whereNotNull('auto_delete_after_days')
            ->get();

        $totalDeleted = 0;
        $totalFreedSpace = 0;
        $stats = [];

        foreach ($backupConfigs as $config) {
            $result = $this->processBackupConfig($config);
            $totalDeleted += $result['deleted'];
            $totalFreedSpace += $result['freed_space'];
            
            if ($result['deleted'] > 0) {
                $stats[] = [
                    'config' => $config->file_name,
                    'project' => $config->project->name ?? 'Unknown',
                    'deleted' => $result['deleted'],
                    'freed_space' => $this->formatBytes($result['freed_space'])
                ];
            }
        }

        Log::info('Auto-delete job completed', [
            'total_deleted' => $totalDeleted,
            'total_freed_space' => $this->formatBytes($totalFreedSpace),
            'configs_processed' => $backupConfigs->count(),
            'details' => $stats
        ]);
    }

    protected function processBackupConfig(Backup $config): array
    {
        $deletedCount = 0;
        $freedSpace = 0;
        $cutoffDate = Carbon::now()->subDays($config->auto_delete_after_days);

        Log::info("Processing auto-delete for backup config", [
            'config_id' => $config->id,
            'file_name' => $config->file_name,
            'delete_after_days' => $config->auto_delete_after_days,
            'cutoff_date' => $cutoffDate->toDateTimeString()
        ]);

        // Get old backups for this configuration
        $oldBackups = CreatedBackup::where('backup_id', $config->id)
            ->where('created_at', '<', $cutoffDate)
            ->get();

        foreach ($oldBackups as $backup) {
            try {
                $freedSpace += $backup->size ?? 0;
                
                // Delete physical file
                if ($this->deleteBackupFile($backup)) {
                    // Delete database record
                    $backup->delete();
                    $deletedCount++;

                    Log::info("Auto-deleted old backup", [
                        'backup_id' => $backup->id,
                        'file_name' => $backup->file_name,
                        'created_at' => $backup->created_at,
                        'age_days' => Carbon::now()->diffInDays($backup->created_at),
                        'size' => $this->formatBytes($backup->size ?? 0)
                    ]);
                } else {
                    Log::warning("Failed to delete backup file, skipping record deletion", [
                        'backup_id' => $backup->id,
                        'file_path' => $backup->file_path
                    ]);
                }
            } catch (\Exception $e) {
                Log::error("Error auto-deleting backup", [
                    'backup_id' => $backup->id,
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        return [
            'deleted' => $deletedCount,
            'freed_space' => $freedSpace
        ];
    }

    protected function deleteBackupFile(CreatedBackup $backup): bool
    {
        $filePath = $backup->file_path;
        $disk = $backup->storage_disk ?? 'local';

        if (!$filePath) {
            return true; // No file to delete
        }

        try {
            if ($disk === 'local') {
                return $this->deleteLocalFile($filePath);
            } else {
                // Cloud storage deletion
                $deleted = $this->storageService->deleteFile($disk, $filePath);
                
                Log::info("Cloud storage auto-delete", [
                    'disk' => $disk,
                    'path' => $filePath,
                    'success' => $deleted
                ]);
                
                return $deleted;
            }
        } catch (\Exception $e) {
            Log::error("Error deleting backup file", [
                'path' => $filePath,
                'disk' => $disk,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    protected function deleteLocalFile(string $filePath): bool
    {
        $attempts = [
            function($path) {
                if (Storage::disk('local')->exists($path)) {
                    return Storage::disk('local')->delete($path);
                }
                return false;
            },
            function($path) {
                $fullPath = storage_path("app/{$path}");
                if (file_exists($fullPath)) {
                    return @unlink($fullPath);
                }
                return false;
            },
            function($path) {
                $altPath = str_replace('private/', '', $path);
                $fullPath = storage_path("app/{$altPath}");
                if (file_exists($fullPath)) {
                    return @unlink($fullPath);
                }
                return false;
            },
            function($path) {
                if (!str_starts_with($path, 'private/')) {
                    $altPath = "private/{$path}";
                    $fullPath = storage_path("app/{$altPath}");
                    if (file_exists($fullPath)) {
                        return @unlink($fullPath);
                    }
                }
                return false;
            }
        ];

        foreach ($attempts as $index => $attempt) {
            try {
                if ($attempt($filePath)) {
                    return true;
                }
            } catch (\Exception $e) {
                Log::debug("Delete attempt failed", [
                    'path' => $filePath,
                    'attempt' => $index + 1
                ]);
            }
        }

        return false;
    }

    protected function formatBytes($bytes, $precision = 2): string
    {
        if ($bytes === 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, $precision) . ' ' . $units[$i];
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Auto-delete job failed', [
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}