<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\File;

class CleanupTempBackups extends Command
{
    protected $signature = 'backup:cleanup-temp {--age=24 : Delete files older than this many hours}';
    protected $description = 'Clean up orphaned temporary backup files';

    public function handle()
    {
        $ageHours = (int) $this->option('age');
        $tempPath = storage_path('app/temp');
        
        if (!is_dir($tempPath)) {
            $this->info('No temp directory found. Nothing to clean.');
            return 0;
        }

        $this->info("Scanning for temporary files older than {$ageHours} hours...");
        
        $files = File::files($tempPath);
        $deletedCount = 0;
        $deletedSize = 0;
        $cutoffTime = now()->subHours($ageHours)->timestamp;

        foreach ($files as $file) {
            $fileTime = $file->getMTime();
            
            if ($fileTime < $cutoffTime) {
                $fileSize = $file->getSize();
                $fileName = $file->getFilename();
                
                if (unlink($file->getPathname())) {
                    $deletedCount++;
                    $deletedSize += $fileSize;
                    $this->line("Deleted: {$fileName} (" . $this->formatBytes($fileSize) . ")");
                } else {
                    $this->error("Failed to delete: {$fileName}");
                }
            }
        }

        if ($deletedCount > 0) {
            $this->info("\nCleanup complete!");
            $this->info("Deleted {$deletedCount} files (" . $this->formatBytes($deletedSize) . " freed)");
        } else {
            $this->info('No old temporary files found.');
        }

        return 0;
    }

    private function formatBytes($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);
        $bytes /= pow(1024, $pow);
        return round($bytes, $precision) . ' ' . $units[$pow];
    }
}