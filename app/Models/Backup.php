<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

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
    ];

    protected $casts = [
        'last_backup_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'next_backup_at' => 'datetime',
        'include_database' => 'boolean',
        'database_config'  => 'array',
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($backup) {
            if (empty($backup->id)) {
                $backup->id = (string) Str::uuid();
            }
            $validDisks = ['local', 's3', 'b2', 'wasabi'];
            if (!in_array($backup->storage_disk, $validDisks)) {
                throw new \Exception("Invalid storage disk: {$backup->storage_disk}");
            }
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