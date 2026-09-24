<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Project extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'user_id', 'name', 'description', 'path'
    ];

    protected static function boot()
    {
        parent::boot();
        
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) Str::uuid();
            }
        });
    }

    public function backups()
    {
        return $this->hasMany(Backup::class);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    public function createdBackups()
    {
        return $this->hasManyThrough(
            CreatedBackup::class,
            Backup::class,
            'project_id',
            'backup_id',
            'id',
            'id'
        );
    }
}
