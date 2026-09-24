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

    protected function authorizeBackup(Backup $backup): void
    {
        $user = auth()->user();
        if ($user && ($user->hasRole('admin') || $user->can('manage users'))) {
            return;
        }
        $project = $backup->project;
        if ($user && $project && $project->user_id && $project->user_id !== $user->id) {
            abort(403, 'Unauthorized access to this backup.');
        }
    }

    protected function authorizeCreatedBackup(CreatedBackup $createdBackup): void
    {
        $user = auth()->user();
        if ($user && ($user->hasRole('admin') || $user->can('manage users'))) {
            return;
        }
        $project = $createdBackup->backup?->project;
        if ($user && $project && $project->user_id && $project->user_id !== $user->id) {
            abort(403, 'Unauthorized access to this backup file.');
        }
    }

    protected function authorizeProject(Project $project): void
    {
        $user = auth()->user();
        if ($user && ($user->hasRole('admin') || $user->can('manage users'))) {
            return;
        }
        if ($user && $project->user_id && $project->user_id !== $user->id) {
            abort(403, 'Unauthorized access to this project.');
        }
    }

    public function index()
    {
        $user = auth()->user();
        $query = Backup::with('project', 'createdBackups');
        if ($user && !$user->hasRole('admin') && !$user->can('manage users')) {
            $query->whereHas('project', function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereNull('user_id');
            });
        }
        $backups = $query->get();
        return Inertia::render('Backups/Backups', [
            'backups' => $backups,
        ]);
    }

    public function createBackup()
    {
        $user = auth()->user();
        $query = Project::query();
        if ($user && !$user->hasRole('admin') && !$user->can('manage users')) {
            $query->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereNull('user_id');
            });
        }
        $projects = $query->get();
        return Inertia::render('Backups/CreateBackup', [
            'projects' => $projects,
        ]);
    }

    public function storeBackup(Request $request)
    {
        $project = Project::findOrFail($request->project_id);
        $this->authorizeProject($project);

        $rules = [
            'project_id'       => 'required|exists:projects,id',
            'file_name'        => 'required|string|max:255',
            'storage_disk'     => 'required|in:local,s3,b2,wasabi,other',
            'include_database' => 'boolean',
            'frequency'        => 'nullable|in:daily,weekly,monthly',
            'time'             => 'nullable|date_format:H:i',
            'auto_delete_enabled' => 'boolean',
            'auto_delete_after_days' => 'nullable|integer|min:1',
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
            'auto_delete_enabled' => $request->boolean('auto_delete_enabled'),
            'auto_delete_after_days' => $request->input('auto_delete_after_days', 7),
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
                \PDO::ATTR_TIMEOUT => 5,
            ]);
            return response()->json(['success' => true, 'message' => 'Connection successful']);
        } catch (\PDOException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 400);
        }
    }

    public function run(Project $project)
    {
        $this->authorizeProject($project);
        $this->backupService->runBackup($project);
        return back()->with('status', 'Backup created successfully!');
    }

    public function retryBackup($id)
    {
        $backup = Backup::with('project')->findOrFail($id);
        $this->authorizeBackup($backup);

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
            $this->authorizeCreatedBackup($createdBackup);

            // Path traversal guard
            if (empty($createdBackup->file_path) || str_contains($createdBackup->file_path, '..') || str_contains($createdBackup->file_path, "\0")) {
                abort(400, 'Invalid backup file path.');
            }

            $disk = $createdBackup->storage_disk ?? 'local';
            $timestamp = $createdBackup->created_at ? $createdBackup->created_at->format('Y-m-d_H-i-s') : now()->format('Y-m-d_H-i-s');
            $ext = str_ends_with($createdBackup->file_name, '.tar.gz') 
                ? 'tar.gz' 
                : (pathinfo($createdBackup->file_name, PATHINFO_EXTENSION) ?: 'tar.gz');
            $projectName = $createdBackup->backup->project->name ?? 'project';
            $downloadName = "{$projectName}_{$timestamp}.{$ext}";
            $headers = $ext === 'tar.gz' ? ['Content-Type' => 'application/gzip'] : [];

            if ($disk === 'local') {
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

                \Log::info("Backup downloaded", [
                    'backup_id' => $id,
                    'project' => $projectName,
                    'storage' => 'local'
                ]);

                return response()->download($filePath, $downloadName, $headers);
            } else {
                // Cloud storage download - download to temp first
                $tempPath = storage_path("app/temp/download_{$id}_{$createdBackup->file_name}");
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

                \Log::info("Cloud backup downloaded successfully", [
                    'backup_id' => $id,
                    'storage' => $disk,
                    'size' => filesize($tempPath)
                ]);

                // Delete temp file after download
                return response()->download($tempPath, $downloadName, $headers)->deleteFileAfterSend(true);
            }
        } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface $e) {
            throw $e;
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

        $this->authorizeBackup($backup);

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
                        if ($disk === 'local') {
                            // Handle local storage deletion
                            $deleted = $this->deleteLocalFile($filePath);
                        } else {
                            // Handle cloud storage deletion (S3, B2, Wasabi, etc.)
                            $deleted = $this->storageService->deleteFile($disk, $filePath);

                            \Log::info("Cloud storage deletion attempt", [
                                'disk' => $disk,
                                'path' => $filePath,
                                'success' => $deleted
                            ]);
                        }
                    }

                    if ($deleted) {
                        $deletedFiles++;
                        \Log::info("Successfully deleted backup file", [
                            'id' => $createdBackup->id,
                            'path' => $filePath,
                            'disk' => $disk
                        ]);
                    } else {
                        $failedFiles++;
                        \Log::warning("Failed to delete backup file", [
                            'id' => $createdBackup->id,
                            'path' => $filePath,
                            'disk' => $disk
                        ]);
                    }
                } catch (\Exception $e) {
                    $failedFiles++;
                    \Log::error("Exception while deleting backup file", [
                        'id' => $createdBackup->id,
                        'error' => $e->getMessage(),
                        'trace' => $e->getTraceAsString()
                    ]);
                }
            }

            // Clear circular foreign key reference before deletion to prevent constraint violation
            $backup->update(['last_created_backup_id' => null]);

            // Delete the backup record (this will cascade delete created_backups)
            $backup->delete();

            \DB::commit();

            // Prepare success message
            $message = "Backup '{$backupName}' for project '{$projectName}' deleted successfully.";
            if ($deletedFiles > 0) {
                $message .= " Removed {$deletedFiles} backup files (" . $this->formatBytes($freedSpace) . " freed).";
            }
            if ($failedFiles > 0) {
                $message .= " Warning: {$failedFiles} files could not be deleted from storage.";
            }

            return redirect()->back()->with('success', $message);
        } catch (\Exception $e) {
            \DB::rollBack();

            \Log::error("Error deleting backup: {$backup->file_name}", [
                'backup_id' => $backup->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
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
        $this->authorizeCreatedBackup($createdBackup);

        try {
            $fileName = $createdBackup->file_name;
            $size = $createdBackup->size ?? 0;
            $projectName = $createdBackup->backup->project->name ?? 'Unknown Project';
            $filePath = $createdBackup->file_path;
            $storageDisk = $createdBackup->storage_disk ?? 'local';

            $fileDeleted = false;

            if ($filePath) {
                if ($storageDisk === 'local') {
                    // Local storage deletion
                    $fileDeleted = $this->deleteLocalFile($filePath);
                } else {
                    // Cloud storage deletion
                    $fileDeleted = $this->storageService->deleteFile($storageDisk, $filePath);

                    \Log::info("Cloud backup file deletion", [
                        'id' => $id,
                        'disk' => $storageDisk,
                        'path' => $filePath,
                        'success' => $fileDeleted
                    ]);
                }
            } else {
                $fileDeleted = true; // No file path, consider it deleted
            }

            // Delete DB record
            $createdBackup->delete();

            \Log::info("Backup record deleted", [
                'id' => $id,
                'file' => $filePath,
                'disk' => $storageDisk,
                'file_deleted' => $fileDeleted,
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
        $this->authorizeBackup($backup);

        $user = auth()->user();
        $projectsQuery = Project::query();
        if ($user && !$user->hasRole('admin') && !$user->can('manage users')) {
            $projectsQuery->where(function ($q) use ($user) {
                $q->where('user_id', $user->id)
                  ->orWhereNull('user_id');
            });
        }
        $projects = $projectsQuery->get();

        // Decrypt credentials if they exist and mask password
        if (!empty($backup->database_config)) {
            $dbConfig = $backup->database_config;

            if (!empty($dbConfig['credentials'])) {
                try {
                    if (is_string($dbConfig['credentials'])) {
                        $decrypted = decrypt($dbConfig['credentials']);
                        $decoded = json_decode($decrypted, true);

                        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                            $dbConfig['credentials'] = $decoded;
                        } else {
                            $dbConfig['credentials'] = null;
                        }
                    }

                    // Mask sensitive password before sending to frontend
                    if (isset($dbConfig['credentials']) && is_array($dbConfig['credentials'])) {
                        if (!empty($dbConfig['credentials']['password'])) {
                            $dbConfig['credentials']['password'] = '********';
                        }
                    }
                } catch (\Throwable $e) {
                    \Log::warning("Decryption failed for backup ID {$id}: " . $e->getMessage());
                    $dbConfig['credentials'] = null;
                }
            } else {
                $dbConfig['credentials'] = null;
            }

            $backup->database_config = $dbConfig;
        }

        return Inertia::render('Backups/EditBackups', [
            'backup' => $backup,
            'projects' => $projects,
        ]);
    }

    public function updateBackup(Request $request, $id)
    {
        $backup = Backup::with('project')->findOrFail($id);
        $this->authorizeBackup($backup);

        $rules = [
            'project_id'       => 'required|exists:projects,id',
            'file_name'        => 'required|string|max:255',
            'storage_disk'     => 'required|in:local,s3,b2,wasabi,other',
            'include_database' => 'boolean',
            'frequency'        => 'nullable|in:daily,weekly,monthly',
            'time'             => 'nullable|date_format:H:i',
            'auto_delete_enabled' => 'boolean',
            'auto_delete_after_days' => 'nullable|integer|min:1',
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

        // Check ownership of new project
        $selectedProject = Project::findOrFail($validated['project_id']);
        $this->authorizeProject($selectedProject);

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
                // Preserve existing password if not updated or masked
                $existingPassword = '';
                if (!empty($backup->database_config['credentials'])) {
                    try {
                        $oldCreds = is_string($backup->database_config['credentials'])
                            ? json_decode(decrypt($backup->database_config['credentials']), true)
                            : $backup->database_config['credentials'];
                        $existingPassword = $oldCreds['password'] ?? '';
                    } catch (\Throwable $e) {}
                }

                $rawPass = $request->db_password;
                $finalPassword = ($rawPass === '********' || empty($rawPass)) ? $existingPassword : $rawPass;

                $encryptedCredentials = encrypt(json_encode([
                    'host'     => $request->db_host,
                    'port'     => $request->db_port,
                    'database' => $request->db_name,
                    'username' => $request->db_username,
                    'password' => $finalPassword,
                ]));

                $dbConfig['credentials'] = $encryptedCredentials;
            }

            if ($validated['db_tables'] === 'selected') {
                $dbConfig['selected_tables'] = array_map('trim', explode(',', $request->selected_tables));
            }
        }

        // Update backup record WITH auto-delete fields
        $backup->update([
            'project_id'       => $validated['project_id'],
            'file_name'        => $validated['file_name'],
            'storage_disk'     => $validated['storage_disk'],
            'backup_frequency' => $validated['frequency'] ?? null,
            'backup_time'      => $validated['time'] ?? null,
            'next_backup_at'   => $nextBackup,
            'include_database' => $includeDatabase,
            'database_config'  => $dbConfig,
            'auto_delete_enabled' => $request->boolean('auto_delete_enabled'),
            'auto_delete_after_days' => $request->input('auto_delete_after_days', 7),
        ]);

        // BackupProjectJob::dispatch($backup);

        // try {
        //     Mail::to($backup->project->user->email ?? 'sudhirrajai@proton.me')
        //         ->send(new \App\Mail\BackupStatusMail($backup));
        // } catch (\Exception $e) {
        //     \Log::info("Error sending backup update email: " . $e->getMessage());
        // }

        return redirect()
            ->route('manage-backups')
            ->with('status', 'Backup updated successfully!');
    }

    /**
     * View all backups for a specific backup configuration
     */
    public function viewBackups($id)
    {
        $backup = Backup::with('project')->findOrFail($id);
        $this->authorizeBackup($backup);

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
            // Delete physical file (handles local and cloud disks)
            $backup->deleteFile();

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
        $this->authorizeCreatedBackup($createdBackup);

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

    /**
     * Helper method to delete local files with multiple fallback attempts
     */
    private function deleteLocalFile(string $filePath): bool
    {
        $attempts = [
            // Attempt 1: Laravel Storage facade
            function ($path) {
                if (Storage::disk('local')->exists($path)) {
                    return Storage::disk('local')->delete($path);
                }
                return false;
            },
            // Attempt 2: Direct filesystem with storage_path
            function ($path) {
                $fullPath = storage_path("app/{$path}");
                if (file_exists($fullPath)) {
                    return @unlink($fullPath);
                }
                return false;
            },
            // Attempt 3: Try without 'private/' prefix if it exists
            function ($path) {
                $altPath = str_replace('private/', '', $path);
                $fullPath = storage_path("app/{$altPath}");
                if (file_exists($fullPath)) {
                    return @unlink($fullPath);
                }
                return false;
            },
            // Attempt 4: Try with 'private/' prefix if not exists
            function ($path) {
                if (!str_starts_with($path, 'private/')) {
                    $altPath = "private/{$path}";
                    $fullPath = storage_path("app/{$altPath}");
                    if (file_exists($fullPath)) {
                        return @unlink($fullPath);
                    }
                }
                return false;
            },
            // Attempt 5: Public storage
            function ($path) {
                $publicPath = public_path("storage/{$path}");
                if (file_exists($publicPath)) {
                    return @unlink($publicPath);
                }
                return false;
            }
        ];

        foreach ($attempts as $index => $attempt) {
            try {
                if ($attempt($filePath)) {
                    \Log::info("File deleted successfully", [
                        'path' => $filePath,
                        'attempt' => $index + 1
                    ]);
                    return true;
                }
            } catch (\Exception $e) {
                \Log::debug("Delete attempt failed", [
                    'path' => $filePath,
                    'attempt' => $index + 1,
                    'error' => $e->getMessage()
                ]);
            }
        }

        \Log::warning("All deletion attempts failed for local file", [
            'path' => $filePath
        ]);

        return false;
    }
}
