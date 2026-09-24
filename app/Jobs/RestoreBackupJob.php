<?php

namespace App\Jobs;

use ZipArchive;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use App\Models\CreatedBackup;
use App\Services\DynamicStorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Exception;

class RestoreBackupJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $createdBackup;
    public $timeout = 3600; // 1 hour for large restores
    public $tries = 2;

    /**
     * Create a new job instance.
     */
    public function __construct(CreatedBackup $createdBackup)
    {
        $this->createdBackup = $createdBackup;
    }

    /**
     * Execute the job.
     */
    public function handle(DynamicStorageService $storageService)
    {
        try {
            $storageDisk = $this->createdBackup->storage_disk ?? 'local';
            $remotePath = $this->createdBackup->file_path;
            
            // Determine local file path
            if ($storageDisk === 'local') {
                $filePath = storage_path('app/' . $remotePath);
            } else {
                // Download from cloud storage to temp directory
                $baseName = basename($remotePath);
                if (!str_ends_with($baseName, '.zip')) {
                    $baseName .= '.zip';
                }
                $tempFileName = 'restore_' . $this->createdBackup->id . '_' . $baseName;
                $filePath = storage_path('app/temp/' . $tempFileName);
                
                // Ensure temp directory exists
                $tempDir = dirname($filePath);
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }

                Log::info("Downloading backup from {$storageDisk}", [
                    'backup_id' => $this->createdBackup->id,
                    'remote_path' => $remotePath,
                    'local_path' => $filePath
                ]);

                // Download the file
                $downloadSuccess = $storageService->downloadFile($storageDisk, $remotePath, $filePath);
                
                if (!$downloadSuccess || !file_exists($filePath)) {
                    throw new Exception("Failed to download backup from {$storageDisk}");
                }

                Log::info("Backup downloaded successfully", [
                    'size' => filesize($filePath),
                    'storage' => $storageDisk
                ]);

                // Verify checksum if available
                if ($this->createdBackup->checksum) {
                    $currentChecksum = hash_file('sha256', $filePath);
                    if ($currentChecksum !== $this->createdBackup->checksum) {
                        unlink($filePath);
                        throw new Exception("Backup file integrity check failed. File may be corrupted.");
                    }
                    Log::info("Checksum verified successfully");
                }

                // Schedule cleanup of temp file after restore
                register_shutdown_function(function() use ($filePath) {
                    if (file_exists($filePath)) {
                        unlink($filePath);
                        Log::info("Temp restore file cleaned up", ['path' => $filePath]);
                    }
                });
            }

            // Verify the backup file exists
            if (!file_exists($filePath)) {
                throw new Exception("Backup file not found at: {$filePath}");
            }

            // Determine project restore path
            $projectPath = $this->determineProjectPath();
            
            Log::info("Restoring backup", [
                'backup_id' => $this->createdBackup->id,
                'source' => $filePath,
                'destination' => $projectPath,
                'storage' => $storageDisk
            ]);

            // Ensure target directory exists
            if (!file_exists($projectPath)) {
                mkdir($projectPath, 0755, true);
            }

            // Extract the backup archive (supports .tar.gz and legacy .zip)
            $this->extractArchive($filePath, $projectPath);

            Log::info("Files extracted successfully", ['project_path' => $projectPath]);

            // Check for and restore database dump
            $this->restoreDatabase($projectPath);

            Log::info("Backup restored successfully", [
                'backup_id' => $this->createdBackup->id,
                'project_id' => $this->createdBackup->backup->project->id,
                'storage' => $storageDisk
            ]);

            // Update backup status if column exists
            if (\Illuminate\Support\Facades\Schema::hasColumn('backups', 'last_restored_at')) {
                $this->createdBackup->backup->update([
                    'last_restored_at' => now()
                ]);
            }

        } catch (Exception $e) {
            Log::error("Backup restore failed", [
                'backup_id' => $this->createdBackup->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            // Re-throw to mark job as failed
            throw $e;
        }
    }

    /**
     * Determine the project restore path
     */
    private function determineProjectPath(): string
    {
        $projectPathFromDb = $this->createdBackup->backup->project->path ?? null;

        if (!$projectPathFromDb) {
            $basePath = env('PROJECTS_BASE_PATH', base_path('projects'));
            $projectDirectory = $this->createdBackup->backup->project->directory 
                ?? $this->createdBackup->backup->project->name;
            return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $projectDirectory;
        }

        // Handle tilde (~) paths
        if (str_starts_with($projectPathFromDb, '~')) {
            $homeDir = getenv('HOME') ?: (function_exists('posix_getpwuid') 
                ? posix_getpwuid(posix_getuid())['dir'] 
                : null);
            
            if ($homeDir) {
                return $homeDir . DIRECTORY_SEPARATOR . ltrim($projectPathFromDb, '~/');
            }
            return $projectPathFromDb;
        }

        // Absolute path (Unix or Windows drive letter)
        if (str_starts_with($projectPathFromDb, '/') || preg_match('/^[a-zA-Z]:[\\\\\/]/', $projectPathFromDb)) {
            return $projectPathFromDb;
        }

        // Relative path
        $basePath = env('PROJECTS_BASE_PATH', base_path('projects'));
        return rtrim($basePath, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $projectPathFromDb;
    }

    /**
     * Restore database from SQL dump files
     */
    private function restoreDatabase(string $projectPath): void
    {
        try {
            // Look for SQL files in the extracted backup
            $sqlFiles = glob($projectPath . '/*.sql');
            
            if (!empty($sqlFiles)) {
                foreach ($sqlFiles as $sqlFilePath) {
                    Log::info("Restoring database from dump", ['file' => basename($sqlFilePath)]);

                    // Get database credentials from backup config or extracted project .env
                    $dbCredentials = $this->getDatabaseCredentials($projectPath);
                    
                    if ($dbCredentials) {
                        $this->importDatabaseDump($sqlFilePath, $dbCredentials);
                    } else {
                        Log::error("Database dump found but target database credentials could not be resolved. Skipping DB restore to avoid corrupting host database.", [
                            'file' => basename($sqlFilePath),
                            'backup_id' => $this->createdBackup->id,
                            'project_path' => $projectPath
                        ]);
                    }

                    // Remove the SQL file after import or attempted restore
                    @unlink($sqlFilePath);
                }
            } else {
                Log::info("No database dump files found in backup");
            }

            // Clean up standalone recovery scripts from project directory if present
            if (file_exists($projectPath . '/restore.sh')) @unlink($projectPath . '/restore.sh');
            if (file_exists($projectPath . '/restore.bat')) @unlink($projectPath . '/restore.bat');
            if (file_exists($projectPath . '/RESTORE.md')) @unlink($projectPath . '/RESTORE.md');

        } catch (Exception $e) {
            Log::error("Database restore failed", [
                'error' => $e->getMessage(),
                'backup_id' => $this->createdBackup->id
            ]);
            // Don't throw - allow file restore to succeed even if DB fails
        }
    }

    /**
     * Extract backup archive supporting both .tar.gz and legacy .zip formats
     */
    private function extractArchive(string $archivePath, string $destinationPath): void
    {
        $isTar = str_ends_with($archivePath, '.tar.gz') || 
                 str_ends_with($archivePath, '.tgz') || 
                 str_ends_with($archivePath, '.tar');

        if ($isTar) {
            if ($this->isTarCliAvailable()) {
                $tarCmd = sprintf(
                    'tar -xzf %s -C %s',
                    escapeshellarg(str_replace('\\', '/', $archivePath)),
                    escapeshellarg(str_replace('\\', '/', $destinationPath))
                );
                $output = [];
                $returnCode = 0;
                exec($tarCmd, $output, $returnCode);

                if ($returnCode === 0) {
                    Log::info("Extracted tar.gz via system tar to {$destinationPath}");
                    return;
                }
                Log::warning("System tar extract returned {$returnCode}, attempting PharData fallback");
            }

            try {
                $phar = new \PharData($archivePath);
                $phar->extractTo($destinationPath, null, true);
                Log::info("Extracted tar.gz via PharData to {$destinationPath}");
                return;
            } catch (\Exception $e) {
                throw new \Exception("Failed to extract tar.gz archive: " . $e->getMessage());
            }
        }

        // Standard ZIP extraction for legacy archives
        $zip = new ZipArchive();
        $res = $zip->open($archivePath);
        if ($res === true) {
            $zip->extractTo($destinationPath);
            $zip->close();
            Log::info("Extracted ZIP archive to {$destinationPath}");
            return;
        }

        throw new Exception("Failed to open backup ZIP archive. Error code: {$res}");
    }

    private function isTarCliAvailable(): bool
    {
        $output = [];
        $returnCode = 1;
        @exec('tar --version', $output, $returnCode);
        return $returnCode === 0;
    }

    /**
     * Get database credentials from backup config or project .env
     */
    private function getDatabaseCredentials(string $projectPath): ?array
    {
        $dbConfig = $this->createdBackup->backup->database_config;
        
        // 1. Check custom credentials in backup config
        if ($dbConfig && isset($dbConfig['credentials'])) {
            try {
                if (is_string($dbConfig['credentials'])) {
                    $decrypted = decrypt($dbConfig['credentials']);
                    $decoded = json_decode($decrypted, true);
                    if ($decoded && !empty($decoded['database'])) {
                        return $decoded;
                    }
                } elseif (is_array($dbConfig['credentials']) && !empty($dbConfig['credentials']['database'])) {
                    return $dbConfig['credentials'];
                }
            } catch (Exception $e) {
                Log::warning("Failed to decrypt database credentials from config", [
                    'error' => $e->getMessage()
                ]);
            }
        }

        // 2. Fall back to extracted project's .env file
        $envPath = rtrim($projectPath, '/\\') . DIRECTORY_SEPARATOR . '.env';
        if (file_exists($envPath)) {
            $credentials = $this->parseEnvDatabaseCredentials($envPath);
            if ($credentials && !empty($credentials['database'])) {
                Log::info("Using target database credentials from restored .env", [
                    'database' => $credentials['database'],
                    'host' => $credentials['host']
                ]);
                return $credentials;
            }
        }

        return null;
    }

    /**
     * Parse database credentials from a project .env file
     */
    private function parseEnvDatabaseCredentials(string $envPath): ?array
    {
        try {
            $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            if (!$lines) return null;

            $credentials = [
                'host' => '127.0.0.1',
                'port' => 3306,
                'database' => '',
                'username' => '',
                'password' => ''
            ];

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line) || str_starts_with($line, '#')) continue;

                if (str_contains($line, '=')) {
                    [$key, $value] = explode('=', $line, 2);
                    $key = trim($key);
                    $value = trim($value, " \t\n\r\0\x0B\"'");

                    match ($key) {
                        'DB_HOST' => $credentials['host'] = $value,
                        'DB_PORT' => $credentials['port'] = (int) $value,
                        'DB_DATABASE' => $credentials['database'] = $value,
                        'DB_USERNAME' => $credentials['username'] = $value,
                        'DB_PASSWORD' => $credentials['password'] = $value,
                        default => null
                    };
                }
            }

            return !empty($credentials['database']) ? $credentials : null;
        } catch (Exception $e) {
            Log::error("Failed to parse target .env credentials: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Import database dump using mysql via secure proc_open
     */
    private function importDatabaseDump(string $sqlFilePath, array $credentials): bool
    {
        try {
            $host = $credentials['host'] ?? '127.0.0.1';
            $port = (int) ($credentials['port'] ?? 3306);
            $database = $credentials['database'] ?? '';
            $username = $credentials['username'] ?? '';
            $password = (string) ($credentials['password'] ?? '');

            if (!file_exists($sqlFilePath) || filesize($sqlFilePath) === 0) {
                Log::error('Database SQL file missing or empty for restore', ['file' => $sqlFilePath]);
                return false;
            }

            $args = [
                'mysql',
                '--host=' . $host,
                '--port=' . $port,
                '--user=' . $username,
                '--default-character-set=utf8mb4',
                $database,
            ];

            $descriptors = [
                0 => ['file', $sqlFilePath, 'r'], // stdin directly from SQL file
                1 => ['pipe', 'w'],                // stdout
                2 => ['pipe', 'w'],                // stderr
            ];

            $env = array_merge($_ENV, $_SERVER, [
                'MYSQL_PWD' => $password,
            ]);

            $command = implode(' ', array_map('escapeshellarg', $args));
            $process = proc_open($command, $descriptors, $pipes, null, $env);

            if (!is_resource($process)) {
                Log::error('Failed to spawn mysql restore process');
                return false;
            }

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $returnCode = proc_close($process);

            if ($returnCode !== 0) {
                Log::error('Database restore via mysql failed with exit code ' . $returnCode, [
                    'stderr' => $stderr,
                    'stdout' => $stdout,
                ]);
                return false;
            }

            Log::info('Database restored successfully from ' . basename($sqlFilePath));
            return true;

        } catch (Exception $e) {
            Log::error('Exception importing database dump: ' . $e->getMessage());
            return false;
        }
    }
}