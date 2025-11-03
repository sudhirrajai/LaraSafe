<?php

namespace App\Jobs;

use App\Models\Backup;
use App\Services\DynamicStorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use ZipArchive;
use App\Mail\BackupStatusMail;
use Illuminate\Support\Facades\Mail;
use Exception;
use App\Models\User;

class BackupProjectJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $backup;
    public $timeout = 1800; // 30 minutes
    public $tries = 2;

    protected $storageService;

    public function __construct(Backup $backup)
    {
        $this->backup = $backup;
    }

    public function handle(DynamicStorageService $storageService): void
    {
        $this->storageService = $storageService;
        
        $user = User::first();
        $project = $this->backup->project;
        $sourceDir = rtrim($project->path, '/');
        $baseName = pathinfo($this->backup->file_name, PATHINFO_FILENAME);
        $disk = $this->backup->storage_disk ?? 'local';

        // Create timestamp for unique filename
        $timestamp = now()->format('Y_m_d_H_i_s');
        $fileName = $baseName . '_' . $timestamp . '.zip';
        
        // For local storage, use private folder
        if ($disk === 'local') {
            $backupFolder = "private/backups/{$project->name}";
            $relativePath = "{$backupFolder}/{$fileName}";
            $fullPath = storage_path("app/{$relativePath}");
            
            // Ensure directory exists
            $dirPath = dirname($fullPath);
            if (!is_dir($dirPath)) {
                mkdir($dirPath, 0755, true);
            }
        } else {
            // For cloud storage, create in temp directory first
            $relativePath = "backups/{$project->name}/{$fileName}";
            $fullPath = storage_path("app/temp/{$fileName}");
            
            // Ensure temp directory exists
            $tempDir = dirname($fullPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
        }

        // Create the ZIP file
        $zip = new ZipArchive();
        $openResult = $zip->open($fullPath, ZipArchive::CREATE);

        if ($openResult === true) {
            try {
                // Add project files
                $this->addProjectFilesToZip($zip, $sourceDir);

                // Add database backup if enabled
                if ($this->backup->include_database) {
                    $this->addDatabaseBackupToZip($zip, $project);
                }

                $zip->close();

                // Generate checksum
                $checksum = hash_file('sha256', $fullPath);
                $fileSize = filesize($fullPath);

                // Upload to cloud storage if not local
                $finalPath = $relativePath;
                if ($disk !== 'local') {
                    $uploadSuccess = $this->uploadToCloudStorage($disk, $fullPath, $relativePath);
                    
                    if (!$uploadSuccess) {
                        throw new Exception("Failed to upload backup to {$disk}");
                    }
                    
                    // Delete local temp file after successful upload
                    if (file_exists($fullPath)) {
                        unlink($fullPath);
                    }
                }

                // Save in created_backups table
                $createdBackup = $this->backup->createdBackups()->create([
                    'file_name' => pathinfo($fileName, PATHINFO_FILENAME),
                    'file_path' => $finalPath,
                    'size' => $fileSize,
                    'storage_disk' => $disk,
                    'checksum' => $checksum,
                    'expires_at' => now()->addDays($this->backup->retention_days ?? 30),
                ]);

                // Update main backup status
                $this->backup->update([
                    'status' => 'success',
                    'size' => $fileSize,
                    'last_created_backup_id' => $createdBackup->id,
                    'last_backup_at' => now(),
                ]);

                Log::info('Backup created successfully', [
                    'backup_id' => $this->backup->id,
                    'file_path' => $finalPath,
                    'size' => $fileSize,
                    'storage' => $disk,
                    'includes_database' => $this->backup->include_database
                ]);

                // Send email notification
                try {
                    Mail::to($user->email)->send(new BackupStatusMail($this->backup, $createdBackup));
                } catch (Exception $e) {
                    Log::error('Failed to send backup status email', [
                        'backup_id' => $this->backup->id,
                        'error' => $e->getMessage(),
                    ]);
                }

            } catch (Exception $e) {
                $zip->close();
                
                // Clean up files
                if (file_exists($fullPath)) {
                    unlink($fullPath);
                }
                
                throw $e;
            }

        } else {
            $this->handleBackupFailure($openResult, $fullPath);
        }
    }

    private function uploadToCloudStorage(string $disk, string $localPath, string $remotePath): bool
    {
        try {
            Log::info("Uploading backup to {$disk}", [
                'local_path' => $localPath,
                'remote_path' => $remotePath
            ]);

            $result = $this->storageService->uploadFile($disk, $localPath, $remotePath);
            
            if ($result) {
                Log::info("Backup uploaded successfully to {$disk}");
            } else {
                Log::error("Upload returned false for {$disk}");
            }
            
            return $result;
            
        } catch (Exception $e) {
            Log::error("Failed to upload to {$disk}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function addProjectFilesToZip(ZipArchive $zip, string $sourceDir): void
    {
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir) + 1);
                $zip->addFile($filePath, $relativePath);
            }
        }
    }

    private function addDatabaseBackupToZip(ZipArchive $zip, $project): void
    {
        try {
            $dbConfig = $this->backup->database_config;
            $dbCredentials = $this->getDatabaseCredentials($dbConfig, $project);
    
            if (!$dbCredentials) {
                Log::warning('Could not retrieve database credentials', [
                    'backup_id' => $this->backup->id,
                    'source' => $dbConfig['source'] ?? 'unknown'
                ]);
                return;
            }
    
            $timestamp = now()->format('Y_m_d_H_i_s');
            $databaseName = $dbCredentials['database'] ?? 'database';
            $dumpFileName = "{$databaseName}_backup_{$timestamp}.sql";
            $tempDumpPath = storage_path("app/temp/{$dumpFileName}");
            
            $tempDir = dirname($tempDumpPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
    
            if ($this->createDatabaseDump($dbCredentials, $tempDumpPath, $dbConfig)) {
                $zip->addFile($tempDumpPath, $dumpFileName);
                
                Log::info('Database backup added to zip', [
                    'backup_id' => $this->backup->id,
                    'dump_file' => $dumpFileName
                ]);
    
                register_shutdown_function(function() use ($tempDumpPath) {
                    if (file_exists($tempDumpPath)) {
                        unlink($tempDumpPath);
                    }
                });
            }
    
        } catch (Exception $e) {
            Log::error('Error creating database backup', [
                'backup_id' => $this->backup->id,
                'error' => $e->getMessage()
            ]);
        }
    }

    private function getDatabaseCredentials($dbConfig, $project): ?array
    {
        if (!$dbConfig || !isset($dbConfig['source'])) {
            return null;
        }

        switch ($dbConfig['source']) {
            case 'env':
                return $this->getCredentialsFromEnv($project);
            
            case 'custom':
                if (isset($dbConfig['credentials'])) {
                    return json_decode(decrypt($dbConfig['credentials']), true);
                }
                break;
            
            case 'project_config':
                return $this->getCredentialsFromProjectConfig($project);
        }

        return null;
    }

    private function getCredentialsFromEnv($project): ?array
    {
        $envPath = rtrim($project->path, '/') . '/.env';
        
        if (!file_exists($envPath)) {
            Log::warning('Project .env file not found', [
                'project_id' => $project->id,
                'env_path' => $envPath
            ]);
            return null;
        }

        try {
            $envContent = file_get_contents($envPath);
            $envLines = explode("\n", $envContent);
            
            $credentials = [
                'host' => 'localhost',
                'port' => 3306,
                'database' => '',
                'username' => '',
                'password' => ''
            ];

            foreach ($envLines as $line) {
                $line = trim($line);
                if (empty($line) || strpos($line, '#') === 0) continue;

                if (strpos($line, '=') !== false) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, '"\'');

                    switch ($key) {
                        case 'DB_HOST':
                            $credentials['host'] = $value;
                            break;
                        case 'DB_PORT':
                            $credentials['port'] = (int)$value;
                            break;
                        case 'DB_DATABASE':
                            $credentials['database'] = $value;
                            break;
                        case 'DB_USERNAME':
                            $credentials['username'] = $value;
                            break;
                        case 'DB_PASSWORD':
                            $credentials['password'] = $value;
                            break;
                    }
                }
            }

            return $credentials['database'] ? $credentials : null;

        } catch (Exception $e) {
            Log::error('Error reading .env file', [
                'project_id' => $project->id,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    private function getCredentialsFromProjectConfig($project): ?array
    {
        return null;
    }

    private function createDatabaseDump(array $credentials, string $outputPath, array $dbConfig): bool
    {
        try {
            $host = $credentials['host'];
            $port = $credentials['port'];
            $database = $credentials['database'];
            $username = $credentials['username'];
            $password = $credentials['password'];
    
            $mysqli = new \mysqli($host, $username, $password, $database, $port);
            if ($mysqli->connect_error) {
                Log::error('Database connection failed', [
                    'error' => $mysqli->connect_error,
                    'host' => $host,
                    'database' => $database
                ]);
                return false;
            }
            $mysqli->close();
    
            $outputDir = dirname($outputPath);
            if (!is_writable($outputDir)) {
                Log::error('Output directory not writable', ['path' => $outputDir]);
                return false;
            }
    
            $command = sprintf(
                'mysqldump -h%s -P%d -u%s %s %s > %s 2>&1',
                escapeshellarg($host),
                $port,
                escapeshellarg($username),
                $password ? '-p' . escapeshellarg($password) : '',
                escapeshellarg($database),
                escapeshellarg($outputPath)
            );
    
            if (isset($dbConfig['tables']) && $dbConfig['tables'] === 'selected' && isset($dbConfig['selected_tables'])) {
                $tables = implode(' ', array_map('escapeshellarg', $dbConfig['selected_tables']));
                $command = str_replace(
                    escapeshellarg($database),
                    escapeshellarg($database) . ' ' . $tables,
                    $command
                );
            }
    
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
    
            if ($returnCode !== 0) {
                Log::error('mysqldump command failed', [
                    'return_code' => $returnCode,
                    'output' => implode("\n", $output)
                ]);
                return false;
            }
    
            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                Log::error('Database dump file is missing or empty', ['path' => $outputPath]);
                return false;
            }
    
            Log::info('Database dump created successfully', [
                'path' => $outputPath,
                'size' => filesize($outputPath)
            ]);
            return true;
    
        } catch (Exception $e) {
            Log::error('Error creating database dump', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    private function handleBackupFailure($openResult, $fullPath): void
    {
        $user = User::first();
        Log::error('ZipArchive failed to open', [
            'fullPath' => $fullPath,
            'code' => $openResult,
        ]);

        $this->backup->update([
            'status' => 'failed',
            'error_message' => 'Unable to create zip file',
            'last_backup_at' => now(),
        ]);

        try {
            Mail::to($user->email)->send(new BackupStatusMail($this->backup));
        } catch (Exception $e) {
            Log::error('Failed to send backup status email', [
                'backup_id' => $this->backup->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}