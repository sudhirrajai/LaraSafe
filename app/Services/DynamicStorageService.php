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

        // Decrypt sensitive fields BEFORE building config
        $config = $this->decryptSensitiveFields($diskType, $config);

        // Log decrypted values (for debugging - remove in production)
        \Log::info("Decrypted config for {$diskType}", [
            'has_key' => isset($config['key_id']) || isset($config['access_key']),
            'has_secret' => isset($config['app_key']) || isset($config['secret_key']),
            'bucket' => $config['bucket'] ?? 'not set',
            'endpoint' => $config['endpoint'] ?? 'not set',
        ]);

        // Configure the disk dynamically
        $diskConfig = $this->buildDiskConfig($diskType, $config);
        
        if (!$diskConfig) {
            \Log::error("Failed to build disk config for: {$diskType}");
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
                $endpoint = $config['endpoint'] ?? null;
                
                // Validate endpoint format
                if (!$endpoint || !filter_var($endpoint, FILTER_VALIDATE_URL)) {
                    \Log::error("Invalid B2 endpoint", ['endpoint' => $endpoint]);
                    return null;
                }

                // Extract region from endpoint (e.g., eu-central-003 from https://s3.eu-central-003.backblazeb2.com)
                $region = 'us-west-000'; // Default B2 region
                if (preg_match('/s3\.([a-z0-9\-]+)\.backblazeb2\.com/', $endpoint, $matches)) {
                    $region = $matches[1];
                }
                
                \Log::info("B2 Configuration", [
                    'endpoint' => $endpoint,
                    'region' => $region,
                    'bucket' => $config['bucket'] ?? 'not set',
                    'key_id_length' => isset($config['key_id']) ? strlen($config['key_id']) : 0,
                    'app_key_length' => isset($config['app_key']) ? strlen($config['app_key']) : 0,
                ]);
                
                return [
                    'driver' => 's3',
                    'key' => $config['key_id'] ?? null,
                    'secret' => $config['app_key'] ?? null,
                    'region' => $region,
                    'bucket' => $config['bucket'] ?? null,
                    'endpoint' => $endpoint,
                    'use_path_style_endpoint' => false, // Changed to false for B2
                    'throw' => true, // Changed to true for better error messages
                    'version' => 'latest',
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
                    // Check if the value is masked (contains *)
                    if (strpos($config[$field], '*') !== false) {
                        \Log::error("Attempted to use masked value for {$field} in {$type}");
                        throw new \Exception("Cannot use masked credential value. Please re-enter the {$field}.");
                    }
                    
                    try {
                        // Only decrypt if it looks like encrypted data
                        $value = $config[$field];
                        
                        // Laravel encrypted strings typically start with "eyJp" (base64 encoded {"i...)
                        if (strpos($value, 'eyJp') === 0 || $this->looksEncrypted($value)) {
                            $decrypted = decrypt($value);
                            $config[$field] = $decrypted;
                            
                            \Log::info("Successfully decrypted {$field} for {$type}", [
                                'length' => strlen($decrypted)
                            ]);
                        } else {
                            // If it doesn't look encrypted, use as-is (might be plain text in DB)
                            \Log::warning("{$field} for {$type} doesn't appear to be encrypted");
                        }
                    } catch (\Exception $e) {
                        \Log::error("Failed to decrypt {$field} for {$type}", [
                            'error' => $e->getMessage(),
                            'value_start' => substr($config[$field], 0, 20)
                        ]);
                        throw new \Exception("Failed to decrypt credentials. Please re-enter your {$field}.");
                    }
                }
            }
        }

        return $config;
    }

    /**
     * Check if a value looks like it's encrypted
     */
    private function looksEncrypted(string $value): bool
    {
        // Laravel encrypted values are base64 encoded JSON
        // They typically contain certain patterns
        if (strlen($value) < 50) {
            return false; // Too short to be encrypted
        }
        
        // Try to base64 decode and check if it's valid JSON
        try {
            $decoded = base64_decode($value, true);
            if ($decoded === false) {
                return false;
            }
            
            $json = json_decode($decoded, true);
            return json_last_error() === JSON_ERROR_NONE && 
                   isset($json['iv']) && 
                   isset($json['value']);
        } catch (\Exception $e) {
            return false;
        }
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
            // Use root path for testing
            $files = Storage::disk($disk)->files('');

            return [
                'success' => true,
                'message' => 'Connection successful! Found ' . count($files) . ' files.'
            ];
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            // Parse AWS error for more helpful messages
            if (strpos($errorMessage, 'SignatureDoesNotMatch') !== false) {
                $errorMessage = 'Invalid credentials. Please check your Key ID and Application Key.';
            } elseif (strpos($errorMessage, '403') !== false) {
                $errorMessage = 'Access denied. Please verify your credentials and bucket permissions.';
            } elseif (strpos($errorMessage, '404') !== false) {
                $errorMessage = 'Bucket not found. Please check your bucket name.';
            }
            
            \Log::error("Storage connection test failed for {$diskType}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => $errorMessage
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