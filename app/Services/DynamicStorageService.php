<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Config;
use League\Flysystem\Filesystem;
use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;

class DynamicStorageService
{
    /**
     * Configure and return a storage disk dynamically
     */
    public function configureDisk(string $diskType): ?string
    {
        if ($diskType === 'local') {
            return 'local';
        }

        // Fetch cloud settings from database
        $settings = $this->getCloudSettings($diskType);
        
        if (!$settings) {
            \Log::warning("No settings found for disk type: {$diskType}");
            return null;
        }

        $config = json_decode($settings->config, true);
        
        if (!$config) {
            \Log::error("Invalid config JSON for disk type: {$diskType}");
            return null;
        }

        // Configure the disk dynamically
        $diskConfig = $this->buildDiskConfig($diskType, $config);
        
        if (!$diskConfig) {
            return null;
        }

        // Register the disk configuration
        Config::set("filesystems.disks.{$diskType}", $diskConfig);
        
        // Purge any cached disk instances
        Storage::forgetDisk($diskType);

        return $diskType;
    }

    /**
     * Get cloud settings from database
     */
    private function getCloudSettings(string $type)
    {
        return DB::table('cloud_settings')
            ->where('type', $type)
            ->first();
    }

    /**
     * Build disk configuration based on type
     */
    private function buildDiskConfig(string $type, array $config): ?array
    {
        // Decrypt sensitive fields
        $config = $this->decryptSensitiveFields($type, $config);

        switch ($type) {
            case 's3':
                return [
                    'driver' => 's3',
                    'key' => $config['access_key'] ?? null,
                    'secret' => $config['secret_key'] ?? null,
                    'region' => $config['region'] ?? 'us-east-1',
                    'bucket' => $config['bucket'] ?? null,
                    'endpoint' => $config['endpoint'] ?? null,
                    'use_path_style_endpoint' => false,
                    'throw' => false,
                ];

            case 'b2':
                // FIXED: Use the endpoint directly from config
                $endpoint = $config['endpoint'] ?? null;
                
                // Validate endpoint format
                if (!$endpoint || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
                    \Log::error("Invalid B2 endpoint", ['endpoint' => $endpoint]);
                    return null;
                }
                
                return [
                    'driver' => 's3',
                    'key' => $config['key_id'] ?? null,
                    'secret' => $config['app_key'] ?? null,
                    'region' => 'eu-central-003', // Match your bucket region
                    'bucket' => $config['bucket'] ?? null,
                    'endpoint' => $endpoint,
                    'use_path_style_endpoint' => true,
                    'throw' => false,
                ];

            case 'wasabi':
                // Wasabi endpoint format: https://s3.{region}.wasabisys.com
                $region = $config['region'] ?? 'us-east-1';
                $endpoint = "https://s3.{$region}.wasabisys.com";
                
                return [
                    'driver' => 's3',
                    'key' => $config['access_key'] ?? null,
                    'secret' => $config['secret_key'] ?? null,
                    'region' => $region,
                    'bucket' => $config['bucket'] ?? null,
                    'endpoint' => $endpoint,
                    'use_path_style_endpoint' => false,
                    'throw' => false,
                ];

            default:
                \Log::warning("Unknown disk type: {$type}");
                return null;
        }
    }

    /**
     * Decrypt sensitive fields in configuration
     */
    private function decryptSensitiveFields(string $type, array $config): array
    {
        $sensitiveFields = [
            's3' => ['secret_key'],
            'b2' => ['app_key'],
            'wasabi' => ['secret_key']
        ];

        if (isset($sensitiveFields[$type])) {
            foreach ($sensitiveFields[$type] as $field) {
                if (isset($config[$field]) && !empty($config[$field])) {
                    try {
                        // Check if the value is encrypted (not masked with *)
                        if (strpos($config[$field], '*') === false) {
                            $config[$field] = decrypt($config[$field]);
                        }
                    } catch (\Exception $e) {
                        \Log::warning("Failed to decrypt {$field} for {$type}", [
                            'error' => $e->getMessage()
                        ]);
                    }
                }
            }
        }

        return $config;
    }

    /**
     * Test connection to a storage provider
     */
    public function testConnection(string $diskType): array
    {
        try {
            $disk = $this->configureDisk($diskType);
            
            if (!$disk) {
                return [
                    'success' => false,
                    'message' => 'Failed to configure storage disk'
                ];
            }

            // Try to list files (will fail if credentials are wrong)
            Storage::disk($disk)->files();

            return [
                'success' => true,
                'message' => 'Connection successful'
            ];
        } catch (\Exception $e) {
            \Log::error("Storage connection test failed for {$diskType}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage()
            ];
        }
    }

    /**
     * Upload file to configured storage
     */
    public function uploadFile(string $diskType, string $localPath, string $remotePath): bool
    {
        try {
            $disk = $this->configureDisk($diskType);
            
            if (!$disk) {
                throw new \Exception("Failed to configure {$diskType} disk");
            }

            if (!file_exists($localPath)) {
                throw new \Exception("Local file not found: {$localPath}");
            }

            $fileStream = fopen($localPath, 'r');
            $result = Storage::disk($disk)->put($remotePath, $fileStream);
            
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }

            return $result;
        } catch (\Exception $e) {
            \Log::error("File upload failed", [
                'disk' => $diskType,
                'local_path' => $localPath,
                'remote_path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Download file from storage
     */
    public function downloadFile(string $diskType, string $remotePath, string $localPath): bool
    {
        try {
            $disk = $this->configureDisk($diskType);
            
            if (!$disk) {
                throw new \Exception("Failed to configure {$diskType} disk");
            }

            $contents = Storage::disk($disk)->get($remotePath);
            
            if (!$contents) {
                throw new \Exception("File not found on remote storage: {$remotePath}");
            }

            file_put_contents($localPath, $contents);
            
            return true;
        } catch (\Exception $e) {
            \Log::error("File download failed", [
                'disk' => $diskType,
                'remote_path' => $remotePath,
                'local_path' => $localPath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Delete file from storage
     */
    public function deleteFile(string $diskType, string $remotePath): bool
    {
        try {
            $disk = $this->configureDisk($diskType);
            
            if (!$disk) {
                throw new \Exception("Failed to configure {$diskType} disk");
            }

            return Storage::disk($disk)->delete($remotePath);
        } catch (\Exception $e) {
            \Log::error("File deletion failed", [
                'disk' => $diskType,
                'remote_path' => $remotePath,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}