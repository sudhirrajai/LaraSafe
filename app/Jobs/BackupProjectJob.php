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
        
        try {
            Log::info('Starting backup job', [
                'backup_id' => $this->backup->id,
                'storage_disk' => $this->backup->storage_disk,
                'project' => $this->backup->project->name
            ]);

            $user = User::first();
            $project = $this->backup->project;
            $sourceDir = rtrim($project->path, '/');
            $baseName = pathinfo($this->backup->file_name, PATHINFO_FILENAME);
            $disk = $this->backup->storage_disk ?? 'local';

            // Validate source directory exists
            if (!is_dir($sourceDir)) {
                throw new Exception("Project directory not found: {$sourceDir}");
            }

            // Create timestamp for unique filename
            $timestamp = now()->format('Y_m_d_H_i_s');
            $fileName = $baseName . '_' . $timestamp . '.zip';
            
            // FIXED: Consistent path handling for local vs cloud
            $tempPath = storage_path("app/temp/{$fileName}");
            
            // Ensure temp directory exists
            $tempDir = dirname($tempPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
                Log::info("Created temp directory: {$tempDir}");
            }

            Log::info('Creating ZIP file', [
                'temp_path' => $tempPath,
                'source_dir' => $sourceDir
            ]);

            // Create the ZIP file in temp first (for both local and cloud)
            $zip = new ZipArchive();
            $openResult = $zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

            if ($openResult !== true) {
                $this->handleBackupFailure($openResult, $tempPath);
                return;
            }

            try {
                // Add project files
                Log::info('Adding project files to ZIP');
                $this->addProjectFilesToZip($zip, $sourceDir);

                // Add database backup if enabled
                if ($this->backup->include_database) {
                    Log::info('Adding database backup to ZIP');
                    $this->addDatabaseBackupToZip($zip, $project);
                }

                $zip->close();
                Log::info('ZIP file created successfully', ['size' => filesize($tempPath)]);

                // Verify ZIP was created
                if (!file_exists($tempPath) || filesize($tempPath) === 0) {
                    throw new Exception('ZIP file was not created or is empty');
                }

                // Generate checksum
                $checksum = hash_file('sha256', $tempPath);
                $fileSize = filesize($tempPath);

                Log::info('ZIP file details', [
                    'size' => $fileSize,
                    'checksum' => $checksum
                ]);

                // Handle storage based on disk type
                if ($disk === 'local') {
                    // FIXED: For local storage, move to proper location
                    $backupFolder = "private/backups/{$project->name}";
                    $relativePath = "{$backupFolder}/{$fileName}";
                    $finalPath = storage_path("app/{$relativePath}");
                    
                    // Ensure backup directory exists
                    $backupDir = dirname($finalPath);
                    if (!is_dir($backupDir)) {
                        mkdir($backupDir, 0755, true);
                        Log::info("Created backup directory: {$backupDir}");
                    }

                    // Move from temp to final location
                    if (!rename($tempPath, $finalPath)) {
                        throw new Exception("Failed to move backup file to final location: {$finalPath}");
                    }

                    Log::info('Backup moved to final location', [
                        'from' => $tempPath,
                        'to' => $finalPath
                    ]);

                    $finalStoragePath = $relativePath;
                } else {
                    // For cloud storage, upload then delete temp
                    $relativePath = "backups/{$project->name}/{$fileName}";
                    
                    Log::info('Uploading to cloud storage', [
                        'disk' => $disk,
                        'remote_path' => $relativePath
                    ]);

                    $uploadSuccess = $this->uploadToCloudStorage($disk, $tempPath, $relativePath);
                    
                    if (!$uploadSuccess) {
                        throw new Exception("Failed to upload backup to {$disk}");
                    }
                    
                    Log::info('Cloud upload successful, deleting temp file');
                    
                    // Delete temp file after successful upload
                    if (file_exists($tempPath)) {
                        unlink($tempPath);
                        Log::info('Temp file deleted');
                    }

                    $finalStoragePath = $relativePath;
                }

                // Save in created_backups table
                $createdBackup = $this->backup->createdBackups()->create([
                    'file_name' => $fileName,
                    'file_path' => $finalStoragePath,
                    'size' => $fileSize,
                    'storage_disk' => $disk,
                    'checksum' => $checksum,
                    'expires_at' => now()->addDays($this->backup->auto_delete_after_days ?? 30),
                ]);

                Log::info('Created backup record', [
                    'id' => $createdBackup->id,
                    'file_path' => $finalStoragePath
                ]);

                // Update main backup status
                $this->backup->update([
                    'status' => 'success',
                    'size' => $fileSize,
                    'last_created_backup_id' => $createdBackup->id,
                    'last_backup_at' => now(),
                    'error_message' => null, // Clear any previous errors
                ]);

                Log::info('Backup completed successfully', [
                    'backup_id' => $this->backup->id,
                    'file_path' => $finalStoragePath,
                    'size' => $fileSize,
                    'storage' => $disk,
                    'includes_database' => $this->backup->include_database
                ]);

                // Send email notification
                try {
                    if ($user && $user->email) {
                        Mail::to($user->email)->send(new BackupStatusMail($this->backup, $createdBackup));
                        Log::info('Backup notification email sent');
                    }
                } catch (Exception $e) {
                    Log::error('Failed to send backup status email', [
                        'backup_id' => $this->backup->id,
                        'error' => $e->getMessage(),
                    ]);
                }

            } catch (Exception $e) {
                // Close ZIP if still open
                if ($zip) {
                    @$zip->close();
                }
                
                // Clean up temp file
                if (file_exists($tempPath)) {
                    @unlink($tempPath);
                    Log::info('Cleaned up temp file after error');
                }
                
                throw $e;
            }

        } catch (Exception $e) {
            Log::error('Backup job failed', [
                'backup_id' => $this->backup->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Update backup status to failed
            $this->backup->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'last_backup_at' => now(),
            ]);

            // Send failure email
            try {
                $user = User::first();
                if ($user && $user->email) {
                    Mail::to($user->email)->send(new BackupStatusMail($this->backup));
                }
            } catch (Exception $mailError) {
                Log::error('Failed to send failure email', [
                    'error' => $mailError->getMessage()
                ]);
            }

            // Re-throw to mark job as failed
            throw $e;
        }
    }

    private function uploadToCloudStorage(string $disk, string $localPath, string $remotePath): bool
    {
        try {
            Log::info("Uploading backup to {$disk}", [
                'local_path' => $localPath,
                'remote_path' => $remotePath,
                'file_exists' => file_exists($localPath),
                'file_size' => file_exists($localPath) ? filesize($localPath) : 0
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
        $fileCount = 0;
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if ($file->isFile()) {
                $filePath = $file->getRealPath();
                $relativePath = substr($filePath, strlen($sourceDir) + 1);
                
                // Skip certain directories/files
                if (strpos($relativePath, 'node_modules') !== false ||
                    strpos($relativePath, '.git') !== false ||
                    strpos($relativePath, 'vendor') !== false) {
                    continue;
                }
                
                $zip->addFile($filePath, $relativePath);
                $fileCount++;
            }
        }

        Log::info("Added {$fileCount} files to ZIP");
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
                    'dump_file' => $dumpFileName,
                    'size' => filesize($tempDumpPath)
                ]);
    
                // Schedule cleanup
                register_shutdown_function(function() use ($tempDumpPath) {
                    if (file_exists($tempDumpPath)) {
                        @unlink($tempDumpPath);
                    }
                });
            }
    
        } catch (Exception $e) {
            Log::error('Error creating database backup', [
                'backup_id' => $this->backup->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
                    try {
                        return json_decode(decrypt($dbConfig['credentials']), true);
                    } catch (Exception $e) {
                        Log::error('Failed to decrypt DB credentials', [
                            'error' => $e->getMessage()
                        ]);
                        return null;
                    }
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
        // Implement if you have project-specific config
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
    
            // Test connection first
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
    
            // Verify output directory is writable
            $outputDir = dirname($outputPath);
            if (!is_writable($outputDir)) {
                Log::error('Output directory not writable', ['path' => $outputDir]);
                return false;
            }
    
            // Build mysqldump command
            $command = sprintf(
                'mysqldump -h%s -P%d -u%s %s %s > %s 2>&1',
                escapeshellarg($host),
                $port,
                escapeshellarg($username),
                $password ? '-p' . escapeshellarg($password) : '',
                escapeshellarg($database),
                escapeshellarg($outputPath)
            );
    
            // Add specific tables if selected
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
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false;
        }
    }

    private function handleBackupFailure($openResult, $fullPath): void
    {
        $errorMessages = [
            ZipArchive::ER_EXISTS => 'File already exists',
            ZipArchive::ER_INCONS => 'Zip archive inconsistent',
            ZipArchive::ER_INVAL => 'Invalid argument',
            ZipArchive::ER_MEMORY => 'Malloc failure',
            ZipArchive::ER_NOENT => 'No such file',
            ZipArchive::ER_NOZIP => 'Not a zip archive',
            ZipArchive::ER_OPEN => 'Can\'t open file',
            ZipArchive::ER_READ => 'Read error',
            ZipArchive::ER_SEEK => 'Seek error',
        ];

        $errorMessage = $errorMessages[$openResult] ?? "Unknown error code: {$openResult}";

        Log::error('ZipArchive failed to open', [
            'fullPath' => $fullPath,
            'code' => $openResult,
            'error' => $errorMessage,
            'directory_exists' => is_dir(dirname($fullPath)),
            'directory_writable' => is_writable(dirname($fullPath))
        ]);

        $this->backup->update([
            'status' => 'failed',
            'error_message' => "Unable to create zip file: {$errorMessage}",
            'last_backup_at' => now(),
        ]);

        try {
            $user = User::first();
            if ($user && $user->email) {
                Mail::to($user->email)->send(new BackupStatusMail($this->backup));
            }
        } catch (Exception $e) {
            Log::error('Failed to send backup failure email', [
                'backup_id' => $this->backup->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function failed(Exception $exception)
    {
        Log::error('Backup job permanently failed', [
            'backup_id' => $this->backup->id,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);

        $this->backup->update([
            'status' => 'failed',
            'error_message' => $exception->getMessage(),
            'last_backup_at' => now(),
        ]);
    }
}