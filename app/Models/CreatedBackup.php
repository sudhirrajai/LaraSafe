<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class CreatedBackup extends Model
{
    // UUID Configuration
    protected $keyType = 'string';
    public $incrementing = false;

    protected $fillable = [
        'id', 'backup_id', 'file_name', 'file_path', 'size',
        'storage_disk', 'checksum', 'expires_at',
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

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
            'size' => 'integer',
        ];
    }

    public function backup(): BelongsTo
    {
        return $this->belongsTo(Backup::class);
    }

    /**
     * Ensure storage disk is configured dynamically if needed
     */
    protected function ensureDiskConfigured(): ?string
    {
        $disk = $this->storage_disk ?? 'local';
        if ($disk === 'local') {
            return 'local';
        }

        try {
            return app(\App\Services\DynamicStorageService::class)->configureDisk($disk);
        } catch (\Exception $e) {
            \Log::error("Failed to configure disk {$disk}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Calculate SHA256 checksum for the stored backup file using streaming
     */
    public function calculateChecksum(): ?string
    {
        if (empty($this->file_path)) {
            return null;
        }

        $disk = $this->ensureDiskConfigured();
        if (!$disk) {
            return null;
        }

        try {
            if (!Storage::disk($disk)->exists($this->file_path)) {
                return null;
            }

            $stream = Storage::disk($disk)->readStream($this->file_path);
            if (!$stream) {
                return null;
            }

            $ctx = hash_init('sha256');
            hash_update_stream($ctx, $stream);
            fclose($stream);
            return hash_final($ctx);
        } catch (\Exception $e) {
            \Log::error("Checksum calculation error for backup {$this->id}: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Verify backup file integrity using checksum (works for both local and cloud storage via streams)
     */
    public function verifyIntegrity(): bool
    {
        if (!$this->checksum || empty($this->file_path)) {
            return false;
        }

        $currentChecksum = $this->calculateChecksum();
        if (!$currentChecksum) {
            return false;
        }

        return hash_equals($this->checksum, $currentChecksum);
    }

    /**
     * Check if backup file exists on disk
     */
    public function fileExists(): bool
    {
        if (empty($this->file_path)) {
            return false;
        }

        $disk = $this->ensureDiskConfigured();
        if (!$disk) {
            return false;
        }

        try {
            return Storage::disk($disk)->exists($this->file_path);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get human readable file size
     */
    protected function fileSizeHuman(): \Illuminate\Database\Eloquent\Casts\Attribute
    {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(
            get: fn () => $this->formatBytes($this->size),
        );
    }

    /**
     * Check if backup has expired
     */
    public function hasExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Delete the backup file from storage
     */
    public function deleteFile(): bool
    {
        if (empty($this->file_path)) {
            return true;
        }

        $disk = $this->ensureDiskConfigured();
        if (!$disk) {
            return false;
        }

        try {
            if (Storage::disk($disk)->exists($this->file_path)) {
                return Storage::disk($disk)->delete($this->file_path);
            }
            return true;
        } catch (\Exception $e) {
            \Log::error("Failed to delete backup file {$this->file_path} from disk {$disk}: " . $e->getMessage());
            return false;
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

}