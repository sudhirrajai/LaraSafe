<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\Project;
use App\Services\ProjectBackupService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Jobs\BackupProjectJob;
use App\Mail\BackupStatusMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Carbon\Carbon;
use App\Models\CreatedBackup;
use App\Jobs\RestoreBackupJob;
use App\Services\DynamicStorageService;

class BackupController extends Controller
{
    protected $backupService;
    protected $storageService;

    public function __construct(ProjectBackupService $backupService, DynamicStorageService $storageService)
    {
        $this->backupService = $backupService;
        $this->storageService = $storageService;
    }

    public function index()
    {
        $backups = Backup::with('project', 'createdBackups')->get();
        logger($backups->toArray());
        return Inertia::render('Backups/Backups', [
            'backups' => $backups,
        ]);
    }

    public function createBackup()
    {
        $projects = Project::all();
        return Inertia::render('Backups/CreateBackup', [
            'projects' => $projects,
        ]);
    }

    public function storeBackup(Request $request)
    {
        $rules = [
            'project_id'       => 'required|exists:projects,id',
            'file_name'        => 'required|string|max:255',
            'storage_disk' => 'required|in:local,s3,b2,wasabi,other',
            'frequency' => 'nullable|in:daily,weekly,monthly',
            'include_database' => 'boolean',
        ];

        $includeDatabase = (bool) $request->input('include_database');

        if ($includeDatabase) {
            $rules['db_source'] = 'required|in:env,custom,project_config';

            if ($request->db_source === 'custom') {
                $rules = array_merge($rules, [
                    'db_host'     => 'required|string',
                    'db_port'     => 'required|integer|between:1,65535',
                    'db_name'     => 'required|string',
                    'db_username' => 'required|string',
                    'db_password' => 'nullable|string',
                ]);
            }

            $rules['db_tables'] = 'required|in:all,selected';

            if ($request->db_tables === 'selected') {
                $rules['selected_tables'] = 'required|string';
            }
        }

        $request->validate($rules);

        // Calculate next backup time if scheduling
        $nextBackup = null;
        if ($request->frequency) {
            $time = $request->time ?: now()->format('H:i');
            $todayWithTime = Carbon::parse($time);
            $nextBackup = match ($request->frequency) {
                'daily'   => $todayWithTime->copy()->addDay(),
                'weekly'  => $todayWithTime->copy()->addWeek(),
                'monthly' => $todayWithTime->copy()->addMonth(),
                default   => null,
            };
        }

        // Prepare database config payload
        $dbConfig = null;
        if ($includeDatabase) {
            $dbConfig = [
                'source' => $request->db_source,
                'tables' => $request->db_tables,
            ];

            if ($request->db_source === 'custom') {
                // Encrypt credentials safely as a string
                $encryptedCredentials = encrypt(json_encode([
                    'host'     => $request->db_host,
                    'port'     => $request->db_port,
                    'database' => $request->db_name,
                    'username' => $request->db_username,
                    'password' => $request->db_password,
                ]));

                $dbConfig['credentials'] = $encryptedCredentials;
            }

            if ($request->db_tables === 'selected') {
                $dbConfig['selected_tables'] = array_map('trim', explode(',', $request->selected_tables));
            }
        }

        // Save backup entry
        $backup = Backup::create([
            'project_id'       => $request->project_id,
            'file_name'        => $request->file_name,
            'storage_disk'     => $request->storage_disk,
            'status'           => 'pending',
            'backup_frequency' => $request->frequency,
            'backup_time'      => $request->time,
            'next_backup_at'   => $nextBackup,
            'include_database' => $includeDatabase,
            'database_config'  => $dbConfig,
        ]);

        // Dispatch job and notify user
        BackupProjectJob::dispatch($backup);

        // Mail::to($backup->project->user->email ?? 'sudhirrajai@proton.me')
        //     ->send(new \App\Mail\BackupStatusMail($backup));

        return back()->with('status', 'Backup created successfully!');
    }

    public function testDatabaseConnection(Request $request)
    {
        $request->validate([
            'db_host'     => 'required|string',
            'db_port'     => 'required|integer',
            'db_name'     => 'required|string',
            'db_username' => 'required|string',
            'db_password' => 'nullable|string',
        ]);

        $host = $request->db_host === 'localhost' ? '127.0.0.1' : $request->db_host;
        $dsn  = "mysql:host={$host};port={$request->db_port};dbname={$request->db_name}";

        try {
            $pdo = new \PDO($dsn, $request->db_username, $request->db_password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            return response()->json(['success' => true, 'message' => 'Connection successful']);
        } catch (\PDOException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function run(Project $project)
    {
        $this->backupService->runBackup($project);
        return back()->with('status', 'Backup created successfully!');
    }

    public function retryBackup($id)
    {
        $backup = Backup::findOrFail($id);
        $backup->update(['status' => 'pending']);

        Mail::to($backup->project->user->email ?? 'sudhirrajai@proton.me')
            ->send(new \App\Mail\BackupStatusMail($backup));
        BackupProjectJob::dispatch($backup);

        return back()->with('status', 'Backup retry initiated successfully.');
    }

    /**
     * Download a specific backup file
     */
    public function download($id)
    {
        try {
            $createdBackup = CreatedBackup::with('backup.project')->findOrFail($id);
            $disk = $createdBackup->storage_disk ?? 'local';

            if ($disk === 'local') {
                // Local storage download
                $filePath = storage_path("app/{$createdBackup->file_path}");

                if (!file_exists($filePath)) {
                    \Log::error("Backup file not found", [
                        'backup_id' => $id,
                        'expected_path' => $filePath
                    ]);
                    return redirect()->back()->with('error', 'Backup file not found on server.');
                }

                // Verify integrity if checksum exists
                if ($createdBackup->checksum) {
                    $currentChecksum = hash_file('sha256', $filePath);
                    if ($currentChecksum !== $createdBackup->checksum) {
                        \Log::warning("Backup file integrity check failed", [
                            'backup_id' => $id,
                            'expected_checksum' => $createdBackup->checksum,
                            'actual_checksum' => $currentChecksum
                        ]);
                        return redirect()->back()->with('error', 'Backup file may be corrupted.');
                    }
                }

                $timestamp = $createdBackup->created_at->format('Y-m-d_H-i-s');
                $downloadName = "{$createdBackup->backup->project->name}_{$timestamp}.zip";

                \Log::info("Backup downloaded", [
                    'backup_id' => $id,
                    'project' => $createdBackup->backup->project->name,
                    'storage' => 'local'
                ]);

                return response()->download($filePath, $downloadName);
            } else {
                // Cloud storage download - download to temp first
                $tempPath = storage_path("app/temp/download_{$id}_{$createdBackup->file_name}.zip");
                $tempDir = dirname($tempPath);
                
                if (!is_dir($tempDir)) {
                    mkdir($tempDir, 0755, true);
                }

                \Log::info("Downloading from cloud storage", [
                    'backup_id' => $id,
                    'storage' => $disk,
                    'remote_path' => $createdBackup->file_path
                ]);

                $downloadSuccess = $this->storageService->downloadFile(
                    $disk,
                    $createdBackup->file_path,
                    $tempPath
                );

                if (!$downloadSuccess || !file_exists($tempPath)) {
                    \Log::error("Failed to download from cloud storage", [
                        'backup_id' => $id,
                        'storage' => $disk
                    ]);
                    return redirect()->back()->with('error', 'Failed to download backup from cloud storage.');
                }

                // Verify integrity if checksum exists
                if ($createdBackup->checksum) {
                    $currentChecksum = hash_file('sha256', $tempPath);
                    if ($currentChecksum !== $createdBackup->checksum) {
                        unlink($tempPath);
                        return redirect()->back()->with('error', 'Downloaded backup file is corrupted.');
                    }
                }

                $timestamp = $createdBackup->created_at->format('Y-m-d_H-i-s');
                $downloadName = "{$createdBackup->backup->project->name}_{$timestamp}.zip";

                \Log::info("Cloud backup downloaded successfully", [
                    'backup_id' => $id,
                    'storage' => $disk,
                    'size' => filesize($tempPath)
                ]);

                // Delete temp file after download
                return response()->download($tempPath, $downloadName)->deleteFileAfterSend(true);
            }

        } catch (\Exception $e) {
            \Log::error("Download failed", [
                'backup_id' => $id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()->with('error', 'Failed to download backup: ' . $e->getMessage());
        }
    }

    public function destroy($id)
    {
        $backup = Backup::with(['createdBackups', 'project'])->find($id);

        if (!$backup) {
            return redirect()->back()->with('error', 'Backup not found');
        }

        try {
            \DB::beginTransaction();

            $deletedFiles = 0;
            $failedFiles = 0;
            $freedSpace = 0;
            $backupName = $backup->file_name;
            $projectName = $backup->project->name;

            // Delete all created backup files for this backup
            foreach ($backup->createdBackups as $createdBackup) {
                try {
                    $freedSpace += $createdBackup->size ?? 0;

                    $filePath = $createdBackup->file_path;
                    $disk = $createdBackup->storage_disk ?? 'local';
                    $deleted = false;

                    if ($filePath) {
                        // Try Laravel Storage
                        if (Storage::disk($disk)->exists($filePath)) {
                            $deleted = Storage::disk($disk)->delete($filePath);
                        }

                        // Fallback: Direct delete
                        $fullPath = storage_path("app/{$filePath}");
                        if (!$deleted && file_exists($fullPath)) {
                            $deleted = @unlink($fullPath);
                        }

                        // Final: Public link
                        if (!$deleted) {
                            $publicPath = public_path("storage/{$filePath}");
                            if (file_exists($publicPath)) {
                                $deleted = @unlink($publicPath);
                            }
                        }
                    } else {
                        $deleted = true;
                    }

                    if ($deleted) {
                        $deletedFiles++;
                    } else {
                        $failedFiles++;
                    }
                } catch (\Exception $e) {
                    $failedFiles++;
                    \Log::error("File delete error", ['error' => $e->getMessage()]);
                }
            }

            // Delete the backup record (this will cascade delete created_backups)
            $backup->delete();

            \DB::commit();

            // Prepare success message
            $message = "Backup '{$backupName}' for project '{$projectName}' deleted successfully.";
            if ($deletedFiles > 0) {
                $message .= " Removed {$deletedFiles} backup files (" . $this->formatBytes($freedSpace) . " freed).";
            }
            if ($failedFiles > 0) {
                $message .= " Warning: {$failedFiles} files could not be deleted.";
            }

            return redirect()->back()->with('success', $message);
        } catch (\Exception $e) {
            \DB::rollBack();

            \Log::error("Error deleting backup: {$backup->file_name}", [
                'backup_id' => $backup->id,
                'error' => $e->getMessage()
            ]);

            return redirect()->back()->with('error', 'Failed to delete backup. Please try again.');
        }
    }

    /**
     * Delete individual created backup file
     */
    public function destroyCreatedBackup($id)
    {
        $createdBackup = CreatedBackup::with(['backup.project'])->findOrFail($id);

        try {
            $fileName = $createdBackup->file_name;
            $size = $createdBackup->size ?? 0;
            $projectName = $createdBackup->backup->project->name ?? 'Unknown Project';
            $filePath = $createdBackup->file_path;
            $storageDisk = $createdBackup->storage_disk ?? 'local';

            $fileDeleted = false;
            $deletedVia = 'none';

            if ($filePath) {
                if ($storageDisk === 'local') {
                    // Local storage deletion
                    if (Storage::disk('local')->exists($filePath)) {
                        if (Storage::disk('local')->delete($filePath)) {
                            $fileDeleted = true;
                            $deletedVia = 'laravel-storage';
                        }
                    }

                    if (!$fileDeleted) {
                        $fullPath = storage_path("app/{$filePath}");
                        if (file_exists($fullPath) && unlink($fullPath)) {
                            $fileDeleted = true;
                            $deletedVia = 'direct-unlink';
                        }
                    }

                    if (!$fileDeleted) {
                        $publicPath = public_path("storage/{$filePath}");
                        if (file_exists($publicPath) && unlink($publicPath)) {
                            $fileDeleted = true;
                            $deletedVia = 'public-storage';
                        }
                    }
                } else {
                    // Cloud storage deletion
                    $fileDeleted = $this->storageService->deleteFile($storageDisk, $filePath);
                    $deletedVia = $fileDeleted ? 'cloud-storage' : 'failed';
                }
            } else {
                $fileDeleted = true;
                $deletedVia = 'no-path';
            }

            // Delete DB record
            $createdBackup->delete();

            \Log::info("Backup file deleted", [
                'id' => $id,
                'file' => $filePath,
                'disk' => $storageDisk,
                'method' => $deletedVia,
                'size' => $size
            ]);

            $message = "Backup '{$fileName}' deleted successfully";
            if ($size > 0) {
                $message .= " (" . $this->formatBytes($size) . " freed)";
            }
            if (!$fileDeleted) {
                $message .= " (Warning: File may still exist on {$storageDisk})";
            }

            if (request()->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $message
                ]);
            }

            return redirect()->back()->with('success', $message);

        } catch (\Exception $e) {
            \Log::error("Failed to delete backup file", [
                'id' => $id,
                'error' => $e->getMessage()
            ]);

            $errorMessage = 'Failed to delete backup: ' . $e->getMessage();

            if (request()->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage
                ], 500);
            }

            return redirect()->back()->with('error', $errorMessage);
        }
    }

    private function formatBytes($size, $precision = 2): string
    {
        if ($size === 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        return round($size, $precision) . ' ' . $units[$i];
    }

    public function edit($id)
    {
        $backup = Backup::with('project')->findOrFail($id);
        $projects = Project::all();

        // Decrypt credentials if they exist
        if (!empty($backup->database_config)) {
            $dbConfig = $backup->database_config;

            if (!empty($dbConfig['credentials'])) {
                try {
                    // Check if credentials is a string (encrypted) or already an array
                    if (is_string($dbConfig['credentials'])) {
                        // It's encrypted, decrypt it
                        $decrypted = decrypt($dbConfig['credentials']);
                        
                        // The decrypted value should be JSON, decode it
                        $decoded = json_decode($decrypted, true);
                        
                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $dbConfig['credentials'] = $decoded;
                        } else {
                            \Log::warning("Invalid JSON format in decrypted credentials for backup ID {$id}");
                            $dbConfig['credentials'] = null;
                        }
                    } elseif (is_array($dbConfig['credentials'])) {
                        // Already decrypted/decoded (possibly from model casting)
                        // No action needed, it's ready to use
                        \Log::info("Credentials already in array format for backup ID {$id}");
                    } else {
                        \Log::warning("Unexpected credentials format for backup ID {$id}");
                        $dbConfig['credentials'] = null;
                    }
                } catch (\Illuminate\Contracts\Encryption\DecryptException $e) {
                    \Log::warning("Decryption failed for backup ID {$id}: " . $e->getMessage());
                    $dbConfig['credentials'] = null;
                } catch (\Throwable $e) {
                    \Log::error("Unexpected error processing credentials for backup ID {$id}: " . $e->getMessage());
                    $dbConfig['credentials'] = null;
                }
            } else {
                $dbConfig['credentials'] = null;
            }

            // Reassign the modified config back to the model
            $backup->database_config = $dbConfig;
        }

        return Inertia::render('Backups/EditBackups', [
            'backup' => $backup,
            'projects' => $projects,
        ]);
    }

    public function updateBackup(Request $request, $id)
    {
        $backup = Backup::findOrFail($id);

        $rules = [
            'project_id'       => 'required|exists:projects,id',
            'file_name'        => 'required|string|max:255',
            'storage_disk'     => 'required|in:local,s3,other',
            'include_database' => 'boolean',
            'frequency'        => 'nullable|in:daily,weekly,monthly',
            'time'             => 'nullable|date_format:H:i',
        ];

        $includeDatabase = (bool) $request->input('include_database');

        if ($includeDatabase) {
            $rules['db_source'] = 'required|in:env,custom,project_config';

            if ($request->db_source === 'custom') {
                $rules = array_merge($rules, [
                    'db_host'     => 'required|string',
                    'db_port'     => 'required|integer|between:1,65535',
                    'db_name'     => 'required|string',
                    'db_username' => 'required|string',
                    'db_password' => 'nullable|string',
                ]);
            }

            $rules['db_tables'] = 'required|in:all,selected';

            if ($request->db_tables === 'selected') {
                $rules['selected_tables'] = 'required|string';
            }
        }

        $validated = $request->validate($rules);

        // Calculate next backup time if scheduling
        $nextBackup = null;
        if ($validated['frequency'] ?? false) {
            $time = $validated['time'] ?: now()->format('H:i');
            $todayWithTime = Carbon::parse($time);
            $nextBackup = match ($validated['frequency']) {
                'daily'   => $todayWithTime->copy()->addDay(),
                'weekly'  => $todayWithTime->copy()->addWeek(),
                'monthly' => $todayWithTime->copy()->addMonth(),
                default   => null,
            };
        }

        // Prepare database config payload
        $dbConfig = null;
        if ($includeDatabase) {
            $dbConfig = [
                'source' => $validated['db_source'],
                'tables' => $validated['db_tables'],
            ];

            if ($validated['db_source'] === 'custom') {
                // Encrypt as JSON-safe string
                $encryptedCredentials = encrypt(json_encode([
                    'host'     => $request->db_host,
                    'port'     => $request->db_port,
                    'database' => $request->db_name,
                    'username' => $request->db_username,
                    'password' => $request->db_password,
                ]));

                $dbConfig['credentials'] = $encryptedCredentials;
            }

            if ($validated['db_tables'] === 'selected') {
                $dbConfig['selected_tables'] = array_map('trim', explode(',', $request->selected_tables));
            }
        }

        // Update backup record
        $backup->update([
            'project_id'       => $validated['project_id'],
            'file_name'        => $validated['file_name'],
            'storage_disk'     => $validated['storage_disk'],
            'backup_frequency' => $validated['frequency'] ?? null,
            'backup_time'      => $validated['time'] ?? null,
            'next_backup_at'   => $nextBackup,
            'include_database' => $includeDatabase,
            'database_config'  => $dbConfig,
        ]);

        BackupProjectJob::dispatch($backup);

        try {
            Mail::to($backup->project->user->email ?? 'sudhirrajai@proton.me')
                ->send(new \App\Mail\BackupStatusMail($backup));
        } catch (\Exception $e) {
            \Log::info("Error sending backup update email: " . $e->getMessage());
        }

        return redirect()
            ->route('manage-backups')
            ->with('status', 'Backup updated successfully!');
    }


    /**
     * View all backups for a specific backup configuration
     */
    public function viewBackups($id)
    {
        $backups = CreatedBackup::with('backup.project')
            ->where('backup_id', $id)
            ->orderBy('created_at', 'desc')
            ->get();

        return Inertia::render('Backups/View-Backups', [
            'backups' => $backups,
        ]);
    }

    /**
     * Clean up expired backups
     */
    public function cleanupExpiredBackups()
    {
        $expiredBackups = CreatedBackup::where('expires_at', '<', now())->get();

        foreach ($expiredBackups as $backup) {
            // Delete physical file
            $disk = $backup->storage_disk ?? 'local';
            if (Storage::disk($disk)->exists($backup->file_path)) {
                Storage::disk($disk)->delete($backup->file_path);
            }

            // Delete database record
            $backup->delete();
        }

        return response()->json([
            'message' => "Cleaned up {$expiredBackups->count()} expired backups"
        ]);
    }

    public function destroySubBackup($id)
    {
        $createdBackup = CreatedBackup::with(['backup.project'])->findOrFail($id);

        try {
            $fileName = $createdBackup->file_name;
            $size = $createdBackup->size ?? 0;
            $projectName = $createdBackup->backup->project->name ?? 'Unknown Project';

            // Delete the actual file from storage
            $fileDeleted = false;
            $filePath = $createdBackup->file_path;

            if ($filePath) {
                $storageDisk = $createdBackup->storage_disk ?? 'local';

                // Try Laravel Storage first
                if (Storage::disk($storageDisk)->exists($filePath)) {
                    $fileDeleted = Storage::disk($storageDisk)->delete($filePath);
                    \Log::info("Deleted backup file via Storage facade", [
                        'file_path' => $filePath,
                        'storage_disk' => $storageDisk
                    ]);
                }
                // Try direct file system deletion as fallback
                else {
                    $fullPath = storage_path("app/{$filePath}");
                    if (file_exists($fullPath)) {
                        $fileDeleted = unlink($fullPath);
                        \Log::info("Deleted backup file via direct filesystem", [
                            'full_path' => $fullPath
                        ]);
                    } else {
                        // File doesn't exist, consider it "deleted"
                        $fileDeleted = true;
                        \Log::info("Backup file already missing", [
                            'file_path' => $filePath
                        ]);
                    }
                }
            }

            // Delete the database record
            $createdBackup->delete();

            // Prepare success message
            $message = "Backup '{$fileName}' deleted successfully";
            if ($size > 0) {
                $message .= " (" . $this->formatBytes($size) . " freed)";
            }
            if (!$fileDeleted) {
                $message .= ". Warning: Physical file could not be removed from storage.";
            }

            // Check if this is an AJAX/JSON request (from Vue.js)
            if (request()->expectsJson()) {
                return response()->json([
                    'success' => true,
                    'message' => $message
                ]);
            }

            return redirect()->back()->with('success', $message);
        } catch (\Exception $e) {
            \Log::error("Error deleting created backup", [
                'created_backup_id' => $id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            $errorMessage = 'Failed to delete backup: ' . $e->getMessage();

            if (request()->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => $errorMessage
                ], 500);
            }

            return redirect()->back()->with('error', $errorMessage);
        }
    }

    public function restoreBackup(Request $request)
    {
        $request->validate([
            'created_backup_id' => 'required|exists:created_backups,id',
        ]);

        $createdBackup = CreatedBackup::with('backup.project')->findOrFail($request->created_backup_id);

        // Dispatch a job to restore the selected backup asynchronously
        RestoreBackupJob::dispatch($createdBackup);

        return back()->with('status', 'Backup restore initiated successfully.');
    }

    /**
     * Test cloud storage connection
     */
    public function testCloudConnection(Request $request)
    {
        $request->validate([
            'storage_type' => 'required|in:s3,b2,wasabi'
        ]);

        $result = $this->storageService->testConnection($request->storage_type);

        return response()->json($result);
    }

}
