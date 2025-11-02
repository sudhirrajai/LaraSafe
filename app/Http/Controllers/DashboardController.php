<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Project;
use App\Models\Backup;
use App\Models\CreatedBackup;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = auth()->user();

        // Get dashboard statistics
        $stats = $this->getDashboardStats();
        
        // Get recent backups
        $recentBackups = $this->getRecentBackups();
        
        // Get backup schedule (next 7 days)
        $upcomingBackups = $this->getUpcomingBackups();
        
        // Get project statistics
        $projectStats = $this->getProjectStats();
        
        // Get storage usage by project
        $storageUsage = $this->getStorageUsage();
        
        // Get backup success rate over time (last 30 days)
        $backupTrends = $this->getBackupTrends();
        
        // Get server storage information
        $serverStorage = $this->getServerStorage();

        return Inertia::render('Home', [
            'stats' => $stats,
            'recentBackups' => $recentBackups,
            'upcomingBackups' => $upcomingBackups,
            'projectStats' => $projectStats,
            'storageUsage' => $storageUsage,
            'backupTrends' => $backupTrends,
            'serverStorage' => $serverStorage,
        ]);
    }

    private function getDashboardStats()
    {
        $totalProjects = Project::count();
        $totalBackups = CreatedBackup::count();
        $totalSize = CreatedBackup::sum('size');
        $successfulBackups = Backup::where('status', 'success')->count();
        $failedBackups = Backup::where('status', 'failed')->count();
        $pendingBackups = Backup::where('status', 'pending')->count();
        
        // Calculate success rate
        $totalBackupAttempts = $successfulBackups + $failedBackups + $pendingBackups;
        $successRate = $totalBackupAttempts > 0 ? round(($successfulBackups / $totalBackupAttempts) * 100, 1) : 0;
        
        // Today's backups
        $todayBackups = CreatedBackup::whereDate('created_at', today())->count();
        
        // This week's backups
        $weekBackups = CreatedBackup::whereBetween('created_at', [
            Carbon::now()->startOfWeek(),
            Carbon::now()->endOfWeek()
        ])->count();

        return [
            'totalProjects' => $totalProjects,
            'totalBackups' => $totalBackups,
            'totalSize' => $totalSize,
            'successRate' => $successRate,
            'todayBackups' => $todayBackups,
            'weekBackups' => $weekBackups,
            'successfulBackups' => $successfulBackups,
            'failedBackups' => $failedBackups,
            'pendingBackups' => $pendingBackups,
        ];
    }

    private function getRecentBackups()
    {
        return CreatedBackup::with(['backup.project'])
            ->latest()
            ->limit(10)
            ->get()
            ->map(function ($backup) {
                return [
                    'id' => $backup->id,
                    'project_name' => $backup->backup->project->name ?? 'Unknown',
                    'file_name' => $backup->file_name,
                    'size' => $backup->size,
                    'status' => $backup->backup->status ?? 'unknown',
                    'created_at' => $backup->created_at,
                    'expires_at' => $backup->expires_at,
                ];
            });
    }

    private function getUpcomingBackups()
    {
        return Backup::with('project')
            ->whereNotNull('next_backup_at')
            ->where('next_backup_at', '>=', now())
            ->where('next_backup_at', '<=', now()->addDays(7))
            ->orderBy('next_backup_at')
            ->limit(10)
            ->get()
            ->map(function ($backup) {
                return [
                    'id' => $backup->id,
                    'project_name' => $backup->project->name,
                    'next_backup_at' => $backup->next_backup_at,
                    'frequency' => $backup->backup_frequency,
                    'backup_time' => $backup->backup_time,
                ];
            });
    }

    private function getProjectStats()
    {
        return Project::select('projects.*')
            ->withCount('backups')
            ->withSum('createdBackups as total_size', 'size')
            ->with(['createdBackups' => function ($query) {
                $query->latest()->limit(1);
            }])
            ->limit(5)
            ->get()
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'path' => $project->path,
                    'backups_count' => $project->backups_count,
                    'total_size' => $project->total_size ?? 0,
                    'last_backup' => $project->createdBackups->first()?->created_at,
                ];
            });
    }
    

    private function getStorageUsage()
    {
        return DB::table('created_backups')
            ->join('backups', 'created_backups.backup_id', '=', 'backups.id')
            ->join('projects', 'backups.project_id', '=', 'projects.id')
            ->select('projects.name as project_name', DB::raw('SUM(created_backups.size) as total_size'))
            ->groupBy('projects.id', 'projects.name')
            ->orderByDesc('total_size')
            ->limit(5)
            ->get();
    }

    private function getBackupTrends()
    {
        $days = [];
        $successful = [];
        $failed = [];
        
        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $days[] = $date->format('M j');
            
            $daySuccessful = Backup::where('status', 'success')
                ->whereDate('updated_at', $date)
                ->count();
            $dayFailed = Backup::where('status', 'failed')
                ->whereDate('updated_at', $date)
                ->count();
                
            $successful[] = $daySuccessful;
            $failed[] = $dayFailed;
        }
        
        return [
            'labels' => $days,
            'successful' => $successful,
            'failed' => $failed,
        ];
    }
    
    private function getServerStorage()
    {
        // Get the storage path where backups are stored
        // You can customize this to your backup storage location
        $backupPath = storage_path('app/backups');
        
        // Create directory if it doesn't exist
        if (!file_exists($backupPath)) {
            mkdir($backupPath, 0755, true);
        }
        
        // Get disk information
        $totalSpace = disk_total_space($backupPath);
        $freeSpace = disk_free_space($backupPath);
        $usedSpace = $totalSpace - $freeSpace;
        
        // Get space used by Larasafe backups
        $larasafeUsed = CreatedBackup::sum('size') ?? 0;
        
        // Calculate percentages
        $usedPercentage = $totalSpace > 0 ? round(($usedSpace / $totalSpace) * 100, 1) : 0;
        $larasafePercentage = $totalSpace > 0 ? round(($larasafeUsed / $totalSpace) * 100, 1) : 0;
        $availablePercentage = 100 - $usedPercentage;
        
        // Get RAM information
        $ramInfo = $this->getRAMInfo();
        
        return [
            'total' => $totalSpace,
            'used' => $usedSpace,
            'free' => $freeSpace,
            'larasafe_used' => $larasafeUsed,
            'used_percentage' => $usedPercentage,
            'available_percentage' => $availablePercentage,
            'larasafe_percentage' => $larasafePercentage,
            'ram' => $ramInfo,
        ];
    }
    
    private function getRAMInfo()
    {
        $ramInfo = [
            'total' => 0,
            'used' => 0,
            'free' => 0,
            'used_percentage' => 0,
            'free_percentage' => 0,
            'larasafe_used' => 0,
            'larasafe_percentage' => 0,
        ];
        
        // Check if we're on a Linux system
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/meminfo')) {
            $meminfo = file_get_contents('/proc/meminfo');
            
            // Parse meminfo
            preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $totalMatch);
            preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $availableMatch);
            
            if (!empty($totalMatch[1])) {
                $totalKB = (int)$totalMatch[1];
                $availableKB = !empty($availableMatch[1]) ? (int)$availableMatch[1] : 0;
                
                // Convert KB to bytes
                $ramInfo['total'] = $totalKB * 1024;
                $ramInfo['free'] = $availableKB * 1024;
                $ramInfo['used'] = $ramInfo['total'] - $ramInfo['free'];
                
                // Get current PHP process memory usage (Larasafe)
                $ramInfo['larasafe_used'] = memory_get_usage(true);
                
                // Calculate percentages
                if ($ramInfo['total'] > 0) {
                    $ramInfo['used_percentage'] = round(($ramInfo['used'] / $ramInfo['total']) * 100, 1);
                    $ramInfo['free_percentage'] = round(($ramInfo['free'] / $ramInfo['total']) * 100, 1);
                    $ramInfo['larasafe_percentage'] = round(($ramInfo['larasafe_used'] / $ramInfo['total']) * 100, 2);
                }
            }
        } 
        // Check if we're on Windows
        elseif (PHP_OS_FAMILY === 'Windows') {
            // Use wmic command to get memory info
            $output = shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value');
            
            if ($output) {
                preg_match('/FreePhysicalMemory=(\d+)/', $output, $freeMatch);
                preg_match('/TotalVisibleMemorySize=(\d+)/', $output, $totalMatch);
                
                if (!empty($totalMatch[1])) {
                    $totalKB = (int)$totalMatch[1];
                    $freeKB = !empty($freeMatch[1]) ? (int)$freeMatch[1] : 0;
                    
                    // Convert KB to bytes
                    $ramInfo['total'] = $totalKB * 1024;
                    $ramInfo['free'] = $freeKB * 1024;
                    $ramInfo['used'] = $ramInfo['total'] - $ramInfo['free'];
                    
                    // Get current PHP process memory usage (Larasafe)
                    $ramInfo['larasafe_used'] = memory_get_usage(true);
                    
                    // Calculate percentages
                    if ($ramInfo['total'] > 0) {
                        $ramInfo['used_percentage'] = round(($ramInfo['used'] / $ramInfo['total']) * 100, 1);
                        $ramInfo['free_percentage'] = round(($ramInfo['free'] / $ramInfo['total']) * 100, 1);
                        $ramInfo['larasafe_percentage'] = round(($ramInfo['larasafe_used'] / $ramInfo['total']) * 100, 2);
                    }
                }
            }
        }
        // Fallback: Use PHP memory limit as approximation
        else {
            $memoryLimit = ini_get('memory_limit');
            if ($memoryLimit != '-1') {
                $ramInfo['larasafe_used'] = memory_get_usage(true);
                // Note: This is just PHP memory limit, not actual system RAM
                $ramInfo['total'] = $this->convertToBytes($memoryLimit);
            }
        }
        
        return $ramInfo;
    }
    
    private function convertToBytes($value)
    {
        $value = trim($value);
        $last = strtolower($value[strlen($value) - 1]);
        $value = (int)$value;
        
        switch ($last) {
            case 'g':
                $value *= 1024 * 1024 * 1024;
                break;
            case 'm':
                $value *= 1024 * 1024;
                break;
            case 'k':
                $value *= 1024;
                break;
        }
        
        return $value;
    }
}