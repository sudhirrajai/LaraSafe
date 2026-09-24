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
        $startDate = Carbon::now()->subDays(29)->startOfDay();
        
        $trendData = Backup::whereIn('status', ['success', 'failed'])
            ->where('updated_at', '>=', $startDate)
            ->selectRaw('DATE(updated_at) as date, status, COUNT(*) as count')
            ->groupBy('date', 'status')
            ->get();

        $keyedData = [];
        foreach ($trendData as $row) {
            $keyedData[$row->date][$row->status] = (int) $row->count;
        }

        $days = [];
        $successful = [];
        $failed = [];
        
        for ($i = 29; $i >= 0; $i--) {
            $date = Carbon::now()->subDays($i);
            $dateKey = $date->format('Y-m-d');
            $days[] = $date->format('M j');
            
            $successful[] = $keyedData[$dateKey]['success'] ?? 0;
            $failed[] = $keyedData[$dateKey]['failed'] ?? 0;
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
        $backupPath = storage_path('app/private/backups');
        
        // Create directory if it doesn't exist
        if (!file_exists($backupPath)) {
            @mkdir($backupPath, 0755, true);
        }
        
        // Get disk information
        $totalSpace = @disk_total_space($backupPath) ?: 0;
        $freeSpace = @disk_free_space($backupPath) ?: 0;
        $usedSpace = max(0, $totalSpace - $freeSpace);
        
        // Get space used by LaraSafe local backups (for local drive calculation)
        $larasafeLocalUsed = CreatedBackup::where('storage_disk', 'local')->sum('size') ?? 0;
        // Total backups across all storage (local + cloud)
        $larasafeTotalUsed = CreatedBackup::sum('size') ?? 0;
        
        // Calculate percentages
        $usedPercentage = $totalSpace > 0 ? round(($usedSpace / $totalSpace) * 100, 1) : 0;
        // Cap at 100% and compare local storage against local disk
        $larasafePercentage = $totalSpace > 0 ? min(100, round(($larasafeLocalUsed / $totalSpace) * 100, 1)) : 0;
        $availablePercentage = max(0, 100 - $usedPercentage);
        
        // Get RAM information
        $ramInfo = $this->getRAMInfo();
        
        return [
            'total' => $totalSpace,
            'used' => $usedSpace,
            'free' => $freeSpace,
            'larasafe_used' => $larasafeTotalUsed,
            'larasafe_local_used' => $larasafeLocalUsed,
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
            'larasafe_used' => memory_get_usage(true),
            'larasafe_percentage' => 0,
        ];
        
        // Check if we're on a Linux system
        if (PHP_OS_FAMILY === 'Linux' && file_exists('/proc/meminfo')) {
            $meminfo = @file_get_contents('/proc/meminfo');
            
            if ($meminfo) {
                preg_match('/MemTotal:\s+(\d+)\s+kB/', $meminfo, $totalMatch);
                preg_match('/MemAvailable:\s+(\d+)\s+kB/', $meminfo, $availableMatch);
                
                if (!empty($totalMatch[1])) {
                    $totalKB = (int)$totalMatch[1];
                    $availableKB = !empty($availableMatch[1]) ? (int)$availableMatch[1] : 0;
                    
                    $ramInfo['total'] = $totalKB * 1024;
                    $ramInfo['free'] = $availableKB * 1024;
                    $ramInfo['used'] = max(0, $ramInfo['total'] - $ramInfo['free']);
                    
                    if ($ramInfo['total'] > 0) {
                        $ramInfo['used_percentage'] = round(($ramInfo['used'] / $ramInfo['total']) * 100, 1);
                        $ramInfo['free_percentage'] = round(($ramInfo['free'] / $ramInfo['total']) * 100, 1);
                        $ramInfo['larasafe_percentage'] = round(($ramInfo['larasafe_used'] / $ramInfo['total']) * 100, 2);
                    }
                    return $ramInfo;
                }
            }
        } 
        // Check if we're on Windows
        elseif (PHP_OS_FAMILY === 'Windows') {
            $output = @shell_exec('wmic OS get FreePhysicalMemory,TotalVisibleMemorySize /Value 2>nul');
            
            // Fallback for Windows 11 (24H2+) where wmic is removed
            if (!$output || !str_contains($output, 'TotalVisibleMemorySize')) {
                $output = @shell_exec('powershell -NoProfile -Command "Get-CimInstance Win32_OperatingSystem | Select-Object -Property FreePhysicalMemory,TotalVisibleMemorySize | Format-List" 2>nul');
            }
            
            if ($output) {
                preg_match('/FreePhysicalMemory\s*[:=]\s*(\d+)/i', $output, $freeMatch);
                preg_match('/TotalVisibleMemorySize\s*[:=]\s*(\d+)/i', $output, $totalMatch);
                
                if (!empty($totalMatch[1])) {
                    $totalKB = (int)$totalMatch[1];
                    $freeKB = !empty($freeMatch[1]) ? (int)$freeMatch[1] : 0;
                    
                    $ramInfo['total'] = $totalKB * 1024;
                    $ramInfo['free'] = $freeKB * 1024;
                    $ramInfo['used'] = max(0, $ramInfo['total'] - $ramInfo['free']);
                    
                    if ($ramInfo['total'] > 0) {
                        $ramInfo['used_percentage'] = round(($ramInfo['used'] / $ramInfo['total']) * 100, 1);
                        $ramInfo['free_percentage'] = round(($ramInfo['free'] / $ramInfo['total']) * 100, 1);
                        $ramInfo['larasafe_percentage'] = round(($ramInfo['larasafe_used'] / $ramInfo['total']) * 100, 2);
                    }
                    return $ramInfo;
                }
            }
        }
        
        // Fallback: Use PHP memory limit
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit && $memoryLimit != '-1') {
            $ramInfo['total'] = $this->convertToBytes($memoryLimit);
            $ramInfo['used'] = $ramInfo['larasafe_used'];
            $ramInfo['free'] = max(0, $ramInfo['total'] - $ramInfo['used']);
            if ($ramInfo['total'] > 0) {
                $ramInfo['used_percentage'] = round(($ramInfo['used'] / $ramInfo['total']) * 100, 1);
                $ramInfo['free_percentage'] = round(($ramInfo['free'] / $ramInfo['total']) * 100, 1);
                $ramInfo['larasafe_percentage'] = $ramInfo['used_percentage'];
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