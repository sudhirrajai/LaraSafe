<?php

namespace App\Jobs;

use App\Models\Backup;
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

    public function __construct(Backup $backup)
    {
        $this->backup = $backup;
    }

    public function handle(): void
    {
        $user = User::all()->first();
        $project = $this->backup->project;
        $sourceDir = rtrim($project->path, '/');
        $baseName = pathinfo($this->backup->file_name, PATHINFO_FILENAME);
        $disk = $this->backup->storage_disk ?? 'local';

        // Create folder inside private storage for this project's backups
        $backupFolder = "private/backups/{$project->name}";
        Storage::disk($disk)->makeDirectory($backupFolder);

        // Use timestamp to make unique filename
        $timestamp = now()->format('Y_m_d_H_i_s');
        $fileName = $baseName . '_' . $timestamp . '.zip';
        
        // Store relative path in database for security
        $relativePath = "{$backupFolder}/{$fileName}";
        $fullPath = storage_path("app/{$relativePath}");

        // Make sure folder exists
        $dirPath = dirname($fullPath);
        if (!is_dir($dirPath)) {
            mkdir($dirPath, 0755, true);
        }

        $zip = new ZipArchive();
        $openResult = $zip->open($fullPath, ZipArchive::CREATE);

        if ($openResult === true) {
            // Add project files
            $this->addProjectFilesToZip($zip, $sourceDir);

            // Add database backup if enabled
            if ($this->backup->include_database) {
                $this->addDatabaseBackupToZip($zip, $project);
            }

            $zip->close();

            // Generate checksum for integrity verification
            $checksum = hash_file('sha256', $fullPath);

            // Save in created_backups with correct file path
            $createdBackup = $this->backup->createdBackups()->create([
                'file_name' => pathinfo($fileName, PATHINFO_FILENAME),
                'file_path' => $relativePath,
                'size' => filesize($fullPath),
                'storage_disk' => $disk,
                'checksum' => $checksum,
                'expires_at' => now()->addDays($this->backup->retention_days ?? 30),
            ]);

            // Update main backup status
            $this->backup->update([
                'status' => 'success',
                'size' => filesize($fullPath),
                'last_created_backup_id' => $createdBackup->id,
                'last_backup_at' => now(),
            ]);

            Log::info('Backup created successfully', [
                'backup_id' => $this->backup->id,
                'file_path' => $relativePath,
                'size' => filesize($fullPath),
                'includes_database' => $this->backup->include_database
            ]);

            try {
                Mail::to($user->email)
                    ->send(new BackupStatusMail($this->backup, $createdBackup));
            } catch (Exception $e) {
                Log::error('Failed to send backup status email', [
                    'backup_id' => $this->backup->id,
                    'error' => $e->getMessage(),
                ]);
            }

        } else {
            $this->handleBackupFailure($openResult, $fullPath);
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
    
            // Create database dump with a cleaner filename
            $timestamp = now()->format('Y_m_d_H_i_s');
            $databaseName = $dbCredentials['database'] ?? 'database';
            $dumpFileName = "{$databaseName}_backup_{$timestamp}.sql";
            $tempDumpPath = storage_path("app/temp/{$dumpFileName}");
            
            // Ensure temp directory exists
            $tempDir = dirname($tempDumpPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
            }
    
            if ($this->createDatabaseDump($dbCredentials, $tempDumpPath, $dbConfig)) {
                // Add database dump to the ROOT of the zip (not in subfolder)
                $zip->addFile($tempDumpPath, $dumpFileName);
                
                Log::info('Database backup added to zip root', [
                    'backup_id' => $this->backup->id,
                    'dump_file' => $dumpFileName,
                    'location' => 'root'
                ]);
    
                // Clean up temp file after adding to zip
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
                // Implement if you have project-specific DB configs
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
        // Implement if you store DB credentials in project configuration
        // This is a placeholder for future implementation
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
    
            // Test database connection
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
    
            // Ensure output directory is writable
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
    
            // Add table selection if specified
            if (isset($dbConfig['tables']) && $dbConfig['tables'] === 'selected' && isset($dbConfig['selected_tables'])) {
                $tables = implode(' ', array_map('escapeshellarg', $dbConfig['selected_tables']));
                $command = str_replace(
                    escapeshellarg($database),
                    escapeshellarg($database) . ' ' . $tables,
                    $command
                );
            }
    
            // Execute the command
            $output = [];
            $returnCode = 0;
            exec($command, $output, $returnCode);
    
            if ($returnCode !== 0) {
                Log::error('mysqldump command failed', [
                    'command' => $command,
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
        $user = User::all()->first();
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
            Mail::to($user->email)
                ->send(new BackupStatusMail($this->backup));
        } catch (Exception $e) {
            Log::error('Failed to send backup status email', [
                'backup_id' => $this->backup->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
