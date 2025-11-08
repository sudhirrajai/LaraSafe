<?php

namespace App\Http\Controllers;

use App\Models\Backup;
use App\Models\Project;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use App\Models\CreatedBackup;
use App\Services\DynamicStorageService;

class ProjectsController extends Controller
{
    protected $storageService;

    public function __construct(DynamicStorageService $storageService)
    {
        $this->storageService = $storageService;
    }

    public function index()
    {
        $projects = Project::all();
        return Inertia::render('Projects/Projects', [
            'projects' => $projects
        ]);
    }

    public function createProject()
    {
        return Inertia::render('Projects/CreateProject');
    }

    public function storeProject(Request $request)
    {
        $validation = $request->validate([
            'name' => 'required',
            'description' => 'nullable|string',
            'path' => 'required|string',
        ]);

        $project = Project::create($validation);

        return redirect()->route('manage-projects')->with('success', 'Project created successfully');
    }

    public function editProject($id)
    {
        $project = Project::findOrFail($id);
        return Inertia::render('Projects/EditProject', [
            'project' => $project,
            'projects' => Project::all()
        ]);
    }

    public function updateProject(Request $request, $id)
    {
        $project = Project::findOrFail($id);

        $validation = $request->validate([
            'name' => 'required',
            'description' => 'nullable|string',
            'path' => 'required|string',
        ]);

        $project->update($validation);

        return redirect()->route('manage-projects')->with('success', 'Project updated successfully');
    }

    public function destroyProject($id)
    {
        $project = Project::with(['backups.createdBackups'])->find($id);

        if (!$project) {
            return redirect()->route('manage-projects')->with('error', 'Project not found');
        }

        try {
            \DB::beginTransaction();

            $deletedFilesCount = 0;
            $failedFilesCount = 0;
            $totalSize = 0;
            $deletedFolders = [];
            $projectName = $project->name;

            \Log::info("Starting project deletion", [
                'project_id' => $project->id,
                'project_name' => $projectName,
                'backups_count' => $project->backups->count(),
            ]);

            // Delete all backup files for this project
            foreach ($project->backups as $backup) {
                foreach ($backup->createdBackups as $createdBackup) {
                    try {
                        $totalSize += $createdBackup->size ?? 0;
                        $filePath = $createdBackup->file_path;
                        $disk = $createdBackup->storage_disk ?? 'local';
                        $deleted = false;

                        \Log::info("Processing backup file", [
                            'id' => $createdBackup->id,
                            'file_name' => $createdBackup->file_name,
                            'file_path' => $filePath,
                            'storage_disk' => $disk
                        ]);

                        if ($filePath) {
                            if ($disk === 'local') {
                                // Use improved local file deletion
                                $deleted = $this->deleteLocalFile($filePath);
                            } else {
                                // Use cloud storage deletion
                                $deleted = $this->storageService->deleteFile($disk, $filePath);
                                
                                \Log::info("Cloud storage deletion", [
                                    'disk' => $disk,
                                    'path' => $filePath,
                                    'success' => $deleted
                                ]);
                            }
                        }

                        if ($deleted) {
                            $deletedFilesCount++;
                            \Log::info("Successfully deleted backup file", [
                                'file_name' => $createdBackup->file_name,
                                'disk' => $disk
                            ]);
                        } else {
                            $failedFilesCount++;
                            \Log::warning("Failed to delete backup file", [
                                'file_name' => $createdBackup->file_name,
                                'disk' => $disk
                            ]);
                        }
                    } catch (\Exception $e) {
                        $failedFilesCount++;
                        \Log::error("Exception deleting backup file", [
                            'file_name' => $createdBackup->file_name,
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }

            // Clean up project backup folders
            $this->cleanupProjectBackupFolder($project, $deletedFolders);

            // Delete the project (cascade deletes backups and created_backups)
            $project->delete();

            \DB::commit();

            // Prepare success message
            $message = "Project '{$projectName}' deleted successfully.";
            if ($deletedFilesCount > 0) {
                $sizeFormatted = $this->formatBytes($totalSize);
                $message .= " Cleaned up {$deletedFilesCount} backup files ({$sizeFormatted}).";
            }
            if ($failedFilesCount > 0) {
                $message .= " Warning: {$failedFilesCount} backup files could not be deleted.";
            }
            if (!empty($deletedFolders)) {
                $message .= " Removed " . count($deletedFolders) . " backup folder(s).";
            }

            \Log::info("Project deletion completed", [
                'project_name' => $projectName,
                'deleted_files' => $deletedFilesCount,
                'failed_files' => $failedFilesCount,
                'size_freed' => $this->formatBytes($totalSize)
            ]);

            return redirect()->route('manage-projects')->with('success', $message);
        } catch (\Exception $e) {
            \DB::rollBack();

            \Log::error("Error deleting project", [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->route('manage-projects')
                ->with('error', 'Failed to delete project. Please check the logs for details.');
        }
    }

    /**
     * Delete local files with multiple fallback attempts
     */
    private function deleteLocalFile(string $filePath): bool
    {
        $attempts = [
            // Attempt 1: Laravel Storage facade
            function($path) {
                if (Storage::disk('local')->exists($path)) {
                    return Storage::disk('local')->delete($path);
                }
                return false;
            },
            // Attempt 2: Direct filesystem with storage_path
            function($path) {
                $fullPath = storage_path("app/{$path}");
                if (file_exists($fullPath)) {
                    return @unlink($fullPath);
                }
                return false;
            },
            // Attempt 3: Try without 'private/' prefix
            function($path) {
                $altPath = str_replace('private/', '', $path);
                $fullPath = storage_path("app/{$altPath}");
                if (file_exists($fullPath)) {
                    return @unlink($fullPath);
                }
                return false;
            },
            // Attempt 4: Try with 'private/' prefix
            function($path) {
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
            function($path) {
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

    /**
     * Cleanup project backup folders
     */
    private function cleanupProjectBackupFolder($project, &$deletedFolders = [])
    {
        try {
            // Possible folder locations
            $possibleFolders = [
                "private/backups/{$project->name}",
                "backups/{$project->name}",
                "private/backups/" . str_replace(' ', '_', $project->name),
                "backups/" . str_replace(' ', '_', $project->name),
                "private/backups/" . strtolower($project->name),
                "backups/" . strtolower($project->name),
            ];

            foreach ($possibleFolders as $backupFolder) {
                if (Storage::disk('local')->exists($backupFolder)) {
                    \Log::info("Found backup folder", ['folder' => $backupFolder]);

                    // Delete all files in the folder
                    $files = Storage::disk('local')->files($backupFolder);
                    foreach ($files as $file) {
                        try {
                            if (Storage::disk('local')->delete($file)) {
                                \Log::info("Deleted file", ['file' => $file]);
                            } else {
                                // Try direct deletion
                                $fullPath = storage_path("app/{$file}");
                                if (file_exists($fullPath) && @unlink($fullPath)) {
                                    \Log::info("Direct deletion successful", ['file' => $file]);
                                }
                            }
                        } catch (\Exception $e) {
                            \Log::error("Error deleting file", [
                                'file' => $file,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    // Delete subdirectories recursively
                    $directories = Storage::disk('local')->directories($backupFolder);
                    foreach ($directories as $directory) {
                        try {
                            Storage::disk('local')->deleteDirectory($directory);
                        } catch (\Exception $e) {
                            \Log::error("Error deleting subdirectory", [
                                'directory' => $directory,
                                'error' => $e->getMessage()
                            ]);
                        }
                    }

                    // Delete the main folder
                    if (Storage::disk('local')->deleteDirectory($backupFolder)) {
                        $deletedFolders[] = $backupFolder;
                        \Log::info("Deleted backup folder", ['folder' => $backupFolder]);
                    } else {
                        // Try direct filesystem deletion
                        $fullPath = storage_path("app/{$backupFolder}");
                        if (is_dir($fullPath) && $this->deleteDirectoryRecursive($fullPath)) {
                            $deletedFolders[] = $backupFolder;
                            \Log::info("Direct folder deletion successful", ['folder' => $backupFolder]);
                        }
                    }
                }
            }

            // Check direct filesystem for folders Laravel might miss
            $directPaths = [
                storage_path("app/private/backups/{$project->name}"),
                storage_path("app/backups/{$project->name}"),
            ];

            foreach ($directPaths as $directPath) {
                if (is_dir($directPath)) {
                    \Log::info("Found direct filesystem folder", ['path' => $directPath]);
                    if ($this->deleteDirectoryRecursive($directPath)) {
                        $deletedFolders[] = basename(dirname($directPath)) . '/' . basename($directPath);
                        \Log::info("Direct filesystem folder deleted", ['path' => $directPath]);
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::error("Error in cleanup", [
                'project_name' => $project->name,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Recursively delete a directory using native PHP
     */
    private function deleteDirectoryRecursive($dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        try {
            $files = array_diff(scandir($dir), ['.', '..']);
            foreach ($files as $file) {
                $filePath = $dir . DIRECTORY_SEPARATOR . $file;
                if (is_dir($filePath)) {
                    $this->deleteDirectoryRecursive($filePath);
                } else {
                    @unlink($filePath);
                }
            }
            return @rmdir($dir);
        } catch (\Exception $e) {
            \Log::error("Error in recursive directory deletion", [
                'dir' => $dir,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Format bytes to human-readable size
     */
    private function formatBytes($size, $precision = 2): string
    {
        if ($size === 0) return '0 B';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        for ($i = 0; $size > 1024 && $i < count($units) - 1; $i++) {
            $size /= 1024;
        }
        return round($size, $precision) . ' ' . $units[$i];
    }

    public function viewProject($id)
    {
        $project = Project::with([
            'backups' => function($query) {
                $query->with('createdBackups')
                      ->orderBy('created_at', 'desc');
            }
        ])->findOrFail($id);
    
        // Get all created backup files for this project
        $createdBackups = CreatedBackup::whereHas('backup', function($query) use ($id) {
            $query->where('project_id', $id);
        })
        ->with('backup')
        ->orderBy('created_at', 'desc')
        ->get();
    
        return Inertia::render('Projects/ViewProject', [
            'project' => $project,
            'createdBackups' => $createdBackups,
            'backupConfigs' => $project->backups
        ]);
    }
}