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
        $zip = null; // Initialize to null
        $tempPath = null;
        
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

            // Create timestamp for unique filename with .tar.gz (high compression ratio)
            $timestamp = now()->format('Y_m_d_H_i_s');
            $fileName = $baseName . '_' . $timestamp . '.tar.gz';
            
            // Consistent path handling for local vs cloud
            $tempPath = storage_path("app/temp/{$fileName}");
            $tempDir = dirname($tempPath);
            if (!is_dir($tempDir)) {
                mkdir($tempDir, 0755, true);
                Log::info("Created temp directory: {$tempDir}");
            }

            // Prepare staging directory for standalone disaster recovery assets
            $stagingDir = storage_path("app/temp/dr_staging_{$this->backup->id}_{$timestamp}");
            if (!is_dir($stagingDir)) {
                mkdir($stagingDir, 0755, true);
            }

            // Dump database into staging if enabled
            $dbName = null;
            if ($this->backup->include_database) {
                Log::info('Creating database dump for disaster recovery package');
                $dbName = $this->createDatabaseDumpToStaging($stagingDir, $project);
            }

            // Generate disaster recovery instructions and scripts inside staging
            $this->generateDisasterRecoveryAssets($stagingDir, $project, $fileName, (bool) $this->backup->include_database, $dbName);

            // Build .tar.gz archive
            Log::info('Building compressed tar.gz archive with disaster recovery assets', [
                'temp_path' => $tempPath,
                'source_dir' => $sourceDir,
                'staging_dir' => $stagingDir,
            ]);
            $this->buildTarGzArchive($tempPath, $sourceDir, $stagingDir);

            // Clean up staging directory
            $this->deleteDirectory($stagingDir);
            $stagingDir = null;

            // Verify archive was created
            if (!file_exists($tempPath) || filesize($tempPath) === 0) {
                throw new Exception('Backup archive was not created or is empty');
            }

            // Generate checksum
            $checksum = hash_file('sha256', $tempPath);
            $fileSize = filesize($tempPath);

            Log::info('Backup archive created successfully', [
                'size' => $fileSize,
                'checksum' => $checksum
            ]);

            // Handle storage based on disk type
            if ($disk === 'local') {
                // For local storage, move to proper location
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
                $tempPath = null; // File has been moved, don't try to delete it later
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
                    $tempPath = null;
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
            Log::error('Backup job failed', [
                'backup_id' => $this->backup->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Close ZIP if still open and valid
            if ($zip instanceof ZipArchive) {
                try {
                    @$zip->close();
                    Log::info('Closed ZIP archive after error');
                } catch (Exception $zipError) {
                    Log::warning('Could not close ZIP after error', [
                        'error' => $zipError->getMessage()
                    ]);
                }
            }
            
            // Clean up staging directory if it still exists
            if (isset($stagingDir) && $stagingDir && is_dir($stagingDir)) {
                $this->deleteDirectory($stagingDir);
            }

            // Clean up temp file if it still exists
            if ($tempPath && file_exists($tempPath)) {
                try {
                    @unlink($tempPath);
                    Log::info('Cleaned up temp file after error', ['path' => $tempPath]);
                } catch (Exception $cleanupError) {
                    Log::warning('Could not delete temp file', [
                        'path' => $tempPath,
                        'error' => $cleanupError->getMessage()
                    ]);
                }
            }

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

    private function createDatabaseDumpToStaging(string $stagingDir, $project): ?string
    {
        $dbConfig = $this->backup->database_config;
        $dbCredentials = $this->getDatabaseCredentials($dbConfig, $project);

        if (!$dbCredentials) {
            Log::warning('Could not retrieve database credentials for backup', [
                'backup_id' => $this->backup->id,
                'source' => $dbConfig['source'] ?? 'unknown'
            ]);
            return null;
        }

        $dumpPath = $stagingDir . DIRECTORY_SEPARATOR . 'database.sql';
        $success = $this->createDatabaseDump($dbCredentials, $dumpPath, $dbConfig ?? []);

        return $success ? ($dbCredentials['database'] ?? 'database') : null;
    }

    /**
     * Generate standalone disaster recovery documentation and executable restore scripts
     */
    private function generateDisasterRecoveryAssets(string $stagingDir, $project, string $archiveName, bool $hasDb, ?string $dbName): void
    {
        $projectName = $project->name ?? 'Application';
        $targetDb = $dbName ?? 'database_name';
        $dateStr = now()->toDateTimeString();
        $dbNotice = $hasDb ? 'Yes (`database.sql`)' : 'No';

        // 1. Standalone Disaster Recovery Guide (RESTORE.md)
        $restoreMd = <<<MARKDOWN
# 🛡️ Standalone Disaster Recovery Guide: {$projectName}

**Generated on:** {$dateStr}  
**Archive:** `{$archiveName}`  
**Includes Database:** {$dbNotice}  

If this server was compromised, lost, or inaccessible, you can restore this entire project on any clean Linux, macOS, or Windows server directly using this archive without needing LaraSafe installed.

---

## ⚡ Method 1: Automated 1-Click Restore

### On Linux or macOS:
1. Extract the archive into your target directory:
   ```bash
   tar -xzf {$archiveName} -C /var/www/{$projectName}
   cd /var/www/{$projectName}
   ```
2. Execute the included restoration script:
   ```bash
   bash restore.sh
   ```

### On Windows:
1. Extract the archive into your target directory.
2. In Command Prompt, run:
   ```cmd
   restore.bat
   ```

---

## 🛠️ Method 2: Manual Restore

### Step 1: Extract Files
```bash
tar -xzf {$archiveName} -C /path/to/destination
cd /path/to/destination
```

### Step 2: Restore Database
If this backup contains `database.sql`:
```bash
# 1. Create target database:
mysql -h 127.0.0.1 -u root -p -e "CREATE DATABASE IF NOT EXISTS \`{$targetDb}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# 2. Import database dump:
mysql -h 127.0.0.1 -u root -p {$targetDb} < database.sql
```

### Step 3: Application Setup
```bash
# 1. Prepare environment:
cp .env.example .env

# 2. Update DB credentials in .env:
# DB_HOST=127.0.0.1
# DB_DATABASE={$targetDb}
# DB_USERNAME=your_db_username
# DB_PASSWORD=your_db_password

# 3. Install composer packages & set up keys:
composer install --no-dev --optimize-autoloader
php artisan key:generate
php artisan storage:link
php artisan config:cache
```

---
*Created by LaraSafe Backup & Disaster Recovery System*
MARKDOWN;

        file_put_contents($stagingDir . DIRECTORY_SEPARATOR . 'RESTORE.md', $restoreMd);

        // 2. Linux / macOS Bash Restore Script (restore.sh)
        $restoreSh = <<<BASH
#!/usr/bin/env bash
set -e

echo "=========================================================="
echo "  LaraSafe Standalone Disaster Recovery"
echo "  Project: {$projectName}"
echo "=========================================================="
echo ""

if [ ! -f "database.sql" ]; then
    echo "[!] database.sql was not found in this archive."
    echo "[*] Project files are restored and ready."
    exit 0
fi

echo "Enter target database credentials:"
read -p "Database Host [127.0.0.1]: " DB_HOST
DB_HOST=\${DB_HOST:-127.0.0.1}
read -p "Database Port [3306]: " DB_PORT
DB_PORT=\${DB_PORT:-3306}
read -p "Database Name [{$targetDb}]: " DB_NAME
DB_NAME=\${DB_NAME:-{$targetDb}}
read -p "Database User [root]: " DB_USER
DB_USER=\${DB_USER:-root}
read -s -p "Database Password: " DB_PASS
echo ""

echo "[*] Creating database '\$DB_NAME' if not exists..."
MYSQL_PWD="\$DB_PASS" mysql -h"\$DB_HOST" -P"\$DB_PORT" -u"\$DB_USER" -e "CREATE DATABASE IF NOT EXISTS \\\`\$DB_NAME\\\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo "[*] Importing database.sql into '\$DB_NAME'..."
MYSQL_PWD="\$DB_PASS" mysql -h"\$DB_HOST" -P"\$DB_PORT" -u"\$DB_USER" "\$DB_NAME" < database.sql

echo "✓ Database successfully restored!"
echo ""
echo "Next: Configure .env and run 'composer install'."
BASH;

        $shPath = $stagingDir . DIRECTORY_SEPARATOR . 'restore.sh';
        file_put_contents($shPath, str_replace("\r\n", "\n", $restoreSh));
        @chmod($shPath, 0755);

        // 3. Windows Batch Restore Script (restore.bat)
        $restoreBat = <<<BAT
@echo off
echo ==========================================================
echo   LaraSafe Standalone Disaster Recovery
echo   Project: {$projectName}
echo ==========================================================
echo.

if not exist "database.sql" (
    echo Notice: database.sql was not found in this archive.
    echo Project files are restored and ready.
    pause
    exit /b 0
)

set /p DB_HOST="Database Host [127.0.0.1]: "
if "%DB_HOST%"=="" set DB_HOST=127.0.0.1
set /p DB_PORT="Database Port [3306]: "
if "%DB_PORT%"=="" set DB_PORT=3306
set /p DB_NAME="Database Name [{$targetDb}]: "
if "%DB_NAME%"=="" set DB_NAME={$targetDb}
set /p DB_USER="Database User [root]: "
if "%DB_USER%"=="" set DB_USER=root
set /p DB_PASS="Database Password: "
echo.

echo [*] Creating database '%DB_NAME%' if not exists...
set MYSQL_PWD=%DB_PASS%
mysql -h%DB_HOST% -P%DB_PORT% -u%DB_USER% -e "CREATE DATABASE IF NOT EXISTS `%DB_NAME%` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

echo [*] Importing database.sql into '%DB_NAME%'...
mysql -h%DB_HOST% -P%DB_PORT% -u%DB_USER% %DB_NAME% < database.sql
set MYSQL_PWD=

echo [OK] Database successfully restored!
echo.
pause
BAT;

        file_put_contents($stagingDir . DIRECTORY_SEPARATOR . 'restore.bat', $restoreBat);
    }

    /**
     * Build compressed .tar.gz archive
     */
    private function buildTarGzArchive(string $archivePath, string $sourceDir, string $stagingDir): void
    {
        if ($this->isTarCliAvailable()) {
            $sourceDirNorm = rtrim(str_replace('\\', '/', $sourceDir), '/');
            $stagingDirNorm = rtrim(str_replace('\\', '/', $stagingDir), '/');
            $archivePathNorm = str_replace('\\', '/', $archivePath);

            $cmd = sprintf(
                'tar -czf %s --exclude="vendor" --exclude="node_modules" --exclude=".git" --exclude="storage/app/*" --exclude="storage/framework/*" --exclude="storage/logs/*" -C %s . -C %s .',
                escapeshellarg($archivePathNorm),
                escapeshellarg($sourceDirNorm),
                escapeshellarg($stagingDirNorm)
            );

            $output = [];
            $returnCode = 0;
            exec($cmd, $output, $returnCode);

            if ($returnCode === 0 && file_exists($archivePath) && filesize($archivePath) > 0) {
                Log::info("Created .tar.gz archive via system tar: {$archivePath} (" . filesize($archivePath) . " bytes)");
                return;
            }

            Log::warning("System tar returned code {$returnCode}, falling back to PharData", [
                'output' => implode("\n", $output)
            ]);
        }

        // Fallback: PharData or ZipArchive
        try {
            $this->buildTarGzViaPhar($archivePath, $sourceDir, $stagingDir);
        } catch (\Throwable $e) {
            Log::warning("PharData failed to build tar.gz: " . $e->getMessage() . ", falling back to ZipArchive");
            $this->buildZipArchive($archivePath, $sourceDir, $stagingDir);
        }
    }

    private function isTarCliAvailable(): bool
    {
        $output = [];
        $returnCode = 1;
        @exec('tar --version', $output, $returnCode);
        return $returnCode === 0;
    }

    private function buildTarGzViaPhar(string $archivePath, string $sourceDir, string $stagingDir): void
    {
        $tarPath = preg_replace('/\.gz$/i', '', $archivePath);
        if ($tarPath === $archivePath) {
            $tarPath .= '.tar';
        }

        if (file_exists($tarPath)) {
            @unlink($tarPath);
        }
        if (file_exists($archivePath)) {
            @unlink($archivePath);
        }

        $tar = new \PharData($tarPath);

        // Add files from sourceDir
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($files as $file) {
            if (!$file->isFile()) continue;
            $realPath = $file->getRealPath();
            $relative = ltrim(substr($realPath, strlen($sourceDir)), '/\\');
            $normalized = str_replace('\\', '/', $relative);

            if (
                str_contains($normalized, 'node_modules') ||
                str_contains($normalized, '.git') ||
                str_contains($normalized, 'vendor') ||
                str_starts_with($normalized, 'storage/app') ||
                str_starts_with($normalized, 'storage/framework') ||
                str_starts_with($normalized, 'storage/logs') ||
                !is_readable($realPath)
            ) {
                continue;
            }

            $tar->addFile($realPath, $normalized);
        }

        // Add recovery files from stagingDir
        if (is_dir($stagingDir)) {
            $stagingFiles = scandir($stagingDir);
            foreach ($stagingFiles as $sFile) {
                if ($sFile === '.' || $sFile === '..') continue;
                $sRealPath = $stagingDir . DIRECTORY_SEPARATOR . $sFile;
                if (is_file($sRealPath) && is_readable($sRealPath)) {
                    $tar->addFile($sRealPath, $sFile);
                }
            }
        }

        // Compress to .gz
        $tar->compress(\Phar::GZ);
        unset($tar);
        if (file_exists($tarPath)) {
            @unlink($tarPath);
        }
    }

    private function buildZipArchive(string $archivePath, string $sourceDir, string $stagingDir): void
    {
        $zip = new ZipArchive();
        $res = $zip->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($res !== true) {
            throw new Exception("Failed to open ZIP archive for writing. Error code: {$res}");
        }

        $this->addProjectFilesToZip($zip, $sourceDir);

        if (is_dir($stagingDir)) {
            $stagingFiles = scandir($stagingDir);
            foreach ($stagingFiles as $sFile) {
                if ($sFile === '.' || $sFile === '..') continue;
                $sRealPath = $stagingDir . DIRECTORY_SEPARATOR . $sFile;
                if (is_file($sRealPath) && is_readable($sRealPath)) {
                    $zip->addFile($sRealPath, $sFile);
                }
            }
        }

        $zip->close();
    }

    private function addProjectFilesToZip(ZipArchive $zip, string $sourceDir): void
    {
        $fileCount = 0;
        $skippedCount = 0;
        
        try {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($sourceDir, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isFile()) {
                    continue;
                }

                $filePath = $file->getRealPath();
                $relativePath = ltrim(substr($filePath, strlen($sourceDir)), '/\\');
                $normalizedRelative = str_replace('\\', '/', $relativePath);
                
                // Skip certain directories/files to prevent bloat and recursive loops
                if (
                    str_contains($normalizedRelative, 'node_modules') ||
                    str_contains($normalizedRelative, '.git') ||
                    str_contains($normalizedRelative, 'vendor') ||
                    str_starts_with($normalizedRelative, 'storage/app') ||
                    str_starts_with($normalizedRelative, 'storage/framework') ||
                    str_starts_with($normalizedRelative, 'storage/logs')
                ) {
                    $skippedCount++;
                    continue;
                }
                
                if (!is_readable($filePath)) {
                    Log::warning("Skipping unreadable file: {$relativePath}");
                    $skippedCount++;
                    continue;
                }
                
                $zip->addFile($filePath, $relativePath);
                $fileCount++;
            }

            Log::info("Added {$fileCount} files to ZIP", [
                'skipped' => $skippedCount
            ]);
        } catch (Exception $e) {
            Log::error('Error adding files to ZIP', [
                'error' => $e->getMessage(),
                'files_added' => $fileCount
            ]);
            throw $e;
        }
    }

    private function deleteDirectory(string $dir): bool
    {
        if (!file_exists($dir)) return true;
        if (!is_dir($dir)) return @unlink($dir);

        $files = array_diff(scandir($dir) ?: [], ['.', '..']);
        foreach ($files as $file) {
            $fullPath = $dir . DIRECTORY_SEPARATOR . $file;
            is_dir($fullPath) ? $this->deleteDirectory($fullPath) : @unlink($fullPath);
        }
        return @rmdir($dir);
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
    
            // Build mysqldump arguments without password on CLI to avoid leaking in ps/tasklist
            $args = [
                'mysqldump',
                '--host=' . $host,
                '--port=' . (int) $port,
                '--user=' . $username,
                '--single-transaction',
                '--quick',
                '--default-character-set=utf8mb4',
            ];

            // Add specific tables if selected
            if (isset($dbConfig['tables']) && $dbConfig['tables'] === 'selected' && !empty($dbConfig['selected_tables'])) {
                $args[] = $database;
                foreach ($dbConfig['selected_tables'] as $tbl) {
                    $args[] = trim($tbl);
                }
            } else {
                $args[] = $database;
            }

            $descriptors = [
                0 => ['pipe', 'r'],              // stdin
                1 => ['file', $outputPath, 'w'], // stdout -> clean SQL dump ONLY
                2 => ['pipe', 'w'],              // stderr -> separate warnings/errors
            ];

            $env = array_merge($_ENV, $_SERVER, [
                'MYSQL_PWD' => (string) $password,
            ]);

            $command = implode(' ', array_map('escapeshellarg', $args));
            $process = proc_open($command, $descriptors, $pipes, null, $env);

            if (!is_resource($process)) {
                Log::error('Failed to start mysqldump process');
                return false;
            }

            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $returnCode = proc_close($process);

            if ($returnCode !== 0) {
                Log::error('mysqldump command failed', [
                    'return_code' => $returnCode,
                    'stderr' => $stderr
                ]);
                if (file_exists($outputPath)) {
                    @unlink($outputPath);
                }
                return false;
            }

            if (!file_exists($outputPath) || filesize($outputPath) === 0) {
                Log::error('Database dump file is missing or empty', ['stderr' => $stderr]);
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