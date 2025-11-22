<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;

class Backup extends Model
{
    use HasFactory;

    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id',
        'project_id',
        'file_name',
        'storage_disk',
        'size',
        'status',
        'backup_frequency',
        'backup_time',
        'last_backup_at',
        'next_backup_at',
        'include_database',
        'database_config',
        'auto_delete_enabled',
        'auto_delete_after_days',
        'error_message', // Added for better error tracking
        'last_created_backup_id',
    ];

    protected $casts = [
        'last_backup_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'next_backup_at' => 'datetime',
        'include_database' => 'boolean',
        'database_config'  => 'array',
        'auto_delete_enabled' => 'boolean',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($backup) {
            if (empty($backup->id)) {
                $backup->id = (string) Str::uuid();
            }
            
            // FIXED: More lenient validation that allows 'other' and logs the disk being used
            $validDisks = ['local', 's3', 'b2', 'wasabi', 'other'];
            if (!in_array($backup->storage_disk, $validDisks)) {
                Log::error("Invalid storage disk attempted", [
                    'disk' => $backup->storage_disk,
                    'valid_disks' => $validDisks
                ]);
                throw new \Exception("Invalid storage disk: {$backup->storage_disk}. Valid options: " . implode(', ', $validDisks));
            }
            
            Log::info("Creating backup with disk: {$backup->storage_disk}");
        });
        
        // ADDED: Log when backup is saved
        static::saved(function ($backup) {
            Log::info("Backup saved", [
                'id' => $backup->id,
                'storage_disk' => $backup->storage_disk,
                'status' => $backup->status
            ]);
        });
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function createdBackups()
    {
        return $this->hasMany(CreatedBackup::class);
    }

    public function getCreatedAtAttribute($value)
    {
        return Carbon::parse($value)->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }

    public function getUpdatedAtAttribute($value)
    {
        return Carbon::parse($value)->timezone(config('app.timezone'))->format('Y-m-d H:i:s');
    }
}