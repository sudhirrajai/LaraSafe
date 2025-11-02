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

class ProjectsController extends Controller
{
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
            'projects' => Project::all() // Included for consistency with other components
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
        // Load project with all related backup data
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

            \Log::info("Starting project deletion debug", [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'backups_count' => $project->backups->count(),
                'total_created_backups' => $project->backups->sum(fn($b) => $b->createdBackups->count())
            ]);

            // Delete all backup files for this project
            foreach ($project->backups as $backup) {
                foreach ($backup->createdBackups as $createdBackup) {
                    try {
                        // Calculate total size before deletion
                        $totalSize += $createdBackup->size ?? 0;

                        // DEBUG: Log the file path and storage disk
                        \Log::info("Processing backup file", [
                            'id' => $createdBackup->id,
                            'file_name' => $createdBackup->file_name,
                            'file_path' => $createdBackup->file_path,
                            'storage_disk' => $createdBackup->storage_disk,
                            'full_path' => Storage::disk($createdBackup->storage_disk)->path($createdBackup->file_path ?? ''),
                            'exists_check' => $createdBackup->file_path ? Storage::disk($createdBackup->storage_disk)->exists($createdBackup->file_path) : false
                        ]);

                        // Check if file exists and delete it
                        if ($createdBackup->file_path && Storage::disk($createdBackup->storage_disk)->exists($createdBackup->file_path)) {
                            if (Storage::disk($createdBackup->storage_disk)->delete($createdBackup->file_path)) {
                                $deletedFilesCount++;
                                \Log::info("✅ Successfully deleted backup file: {$createdBackup->file_name}", [
                                    'file_path' => $createdBackup->file_path
                                ]);
                            } else {
                                $failedFilesCount++;
                                \Log::error("❌ Failed to delete backup file: {$createdBackup->file_name}", [
                                    'file_path' => $createdBackup->file_path,
                                    'storage_disk' => $createdBackup->storage_disk
                                ]);
                            }
                        } else {
                            // File doesn't exist according to Laravel
                            $failedFilesCount++;
                            \Log::warning("⚠️ Backup file not found by Laravel Storage: {$createdBackup->file_path}");

                            // Try direct file system check
                            $fullPath = storage_path("app/{$createdBackup->file_path}");
                            if (file_exists($fullPath)) {
                                \Log::info("🔍 File exists in filesystem, trying direct deletion: {$fullPath}");
                                if (unlink($fullPath)) {
                                    $deletedFilesCount++;
                                    \Log::info("✅ Direct deletion successful: {$fullPath}");
                                } else {
                                    \Log::error("❌ Direct deletion failed: {$fullPath}");
                                }
                            }
                        }
                    } catch (\Exception $e) {
                        $failedFilesCount++;
                        \Log::error("💥 Exception deleting backup file: {$createdBackup->file_name}", [
                            'project_id' => $project->id,
                            'error' => $e->getMessage(),
                            'trace' => $e->getTraceAsString()
                        ]);
                    }
                }
            }

            // Clean up the project backup folder with more aggressive approach
            $this->cleanupProjectBackupFolderAggressive($project, $deletedFolders);

            // Delete the project (this will cascade delete backups and created_backups due to foreign keys)
            $projectName = $project->name; // Store name before deletion
            $project->delete();

            \DB::commit();

            // Prepare success message with file deletion details
            $message = "Project '{$projectName}' deleted successfully.";
            if ($deletedFilesCount > 0) {
                $sizeFormatted = $this->formatBytes($totalSize);
                $message .= " Cleaned up {$deletedFilesCount} backup files ({$sizeFormatted}).";
            }
            if ($failedFilesCount > 0) {
                $message .= " Warning: {$failedFilesCount} backup files could not be deleted.";
            }
            if (!empty($deletedFolders)) {
                $message .= " Removed " . count($deletedFolders) . " empty backup folder(s).";
            }

            return redirect()->route('manage-projects')->with('success', $message);
        } catch (\Exception $e) {
            \DB::rollBack();

            \Log::error("💥 Error deleting project: {$project->name}", [
                'project_id' => $project->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return redirect()->route('manage-projects')
                ->with('error', 'Failed to delete project. Please check the logs for details.');
        }
    }

    /**
     * More aggressive cleanup of project backup folders
     */
    private function cleanupProjectBackupFolderAggressive($project, &$deletedFolders = [])
    {
        try {
            // Try multiple possible folder structures
            $possibleFolders = [
                "private/backups/{$project->name}",
                "backups/{$project->name}",
                "private/backups/" . str_replace(' ', '_', $project->name),
                "backups/" . str_replace(' ', '_', $project->name)
            ];

            foreach ($possibleFolders as $backupFolder) {
                if (Storage::disk('local')->exists($backupFolder)) {
                    \Log::info("🔍 Found backup folder: {$backupFolder}");

                    // Get all files and subdirectories in the folder
                    $files = Storage::disk('local')->files($backupFolder);
                    $directories = Storage::disk('local')->directories($backupFolder);

                    \Log::info("📁 Folder contents", [
                        'folder' => $backupFolder,
                        'files' => $files,
                        'directories' => $directories
                    ]);

                    // Force delete all files first
                    foreach ($files as $file) {
                        try {
                            if (Storage::disk('local')->delete($file)) {
                                \Log::info("✅ Deleted file: {$file}");
                            } else {
                                \Log::error("❌ Failed to delete file: {$file}");
                                // Try direct filesystem deletion
                                $fullPath = storage_path("app/{$file}");
                                if (file_exists($fullPath) && unlink($fullPath)) {
                                    \Log::info("✅ Direct deletion successful: {$file}");
                                }
                            }
                        } catch (\Exception $e) {
                            \Log::error("💥 Error deleting file: {$file}", ['error' => $e->getMessage()]);
                        }
                    }

                    // Then delete the folder
                    if (Storage::disk('local')->deleteDirectory($backupFolder)) {
                        $deletedFolders[] = $backupFolder;
                        \Log::info("✅ Deleted backup folder: {$backupFolder}");
                    } else {
                        \Log::error("❌ Failed to delete backup folder: {$backupFolder}");
                        // Try direct filesystem deletion
                        $fullPath = storage_path("app/{$backupFolder}");
                        if (is_dir($fullPath)) {
                            if ($this->deleteDirectoryRecursive($fullPath)) {
                                $deletedFolders[] = $backupFolder;
                                \Log::info("✅ Direct folder deletion successful: {$backupFolder}");
                            }
                        }
                    }
                }
            }

            // Also check for direct filesystem folders (in case Laravel Storage doesn't see them)
            $directPath = storage_path("app/private/backups/{$project->name}");
            if (is_dir($directPath)) {
                \Log::info("🔍 Found direct filesystem folder: {$directPath}");
                if ($this->deleteDirectoryRecursive($directPath)) {
                    $deletedFolders[] = "private/backups/{$project->name}";
                    \Log::info("✅ Direct filesystem folder deletion successful");
                }
            }
        } catch (\Exception $e) {
            \Log::error("💥 Error in aggressive cleanup", [
                'project_id' => $project->id,
                'project_name' => $project->name,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Recursively delete a directory using native PHP functions
     */
    private function deleteDirectoryRecursive($dir): bool
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $filePath = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($filePath)) {
                $this->deleteDirectoryRecursive($filePath);
            } else {
                unlink($filePath);
            }
        }
        return rmdir($dir);
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
            'backupConfigs' => $project->backups // If you need the configurations too
        ]);
    }
    
    
}