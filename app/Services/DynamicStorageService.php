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

        // Log configuration (sanitized)
        \Log::info("Configuring disk for {$diskType}", [
            'has_key' => isset($config['key_id']) || isset($config['access_key']),
            'has_secret' => isset($config['app_key']) || isset($config['secret_key']),
            'bucket' => $config['bucket'] ?? 'not set',
            'endpoint' => $config['endpoint'] ?? 'not set',
            'region' => $config['region'] ?? 'not set',
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
                $diskConfig = [
                    'driver' => 's3',
                    'key' => $config['access_key'] ?? null,
                    'secret' => $config['secret_key'] ?? null,
                    'region' => $config['region'] ?? 'us-east-1',
                    'bucket' => $config['bucket'] ?? null,
                    'use_path_style_endpoint' => false,
                    'throw' => true,
                    'version' => 'latest',
                ];
                
                // Add custom endpoint if provided
                if (!empty($config['endpoint'])) {
                    $diskConfig['endpoint'] = $config['endpoint'];
                }
                
                \Log::info("S3 Configuration built", [
                    'region' => $diskConfig['region'],
                    'bucket' => $diskConfig['bucket'],
                    'has_endpoint' => !empty($config['endpoint']),
                ]);
                
                return $diskConfig;

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
                
                \Log::info("B2 Configuration built", [
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
                    'use_path_style_endpoint' => false,
                    'throw' => true,
                    'version' => 'latest',
                ];

            case 'wasabi':
                // Wasabi endpoint format: https://s3.{region}.wasabisys.com
                $region = $config['region'] ?? 'us-east-1';
                $endpoint = "https://s3.{$region}.wasabisys.com";
                
                \Log::info("Wasabi Configuration built", [
                    'endpoint' => $endpoint,
                    'region' => $region,
                    'bucket' => $config['bucket'] ?? 'not set',
                ]);
                
                return [
                    'driver' => 's3',
                    'key' => $config['access_key'] ?? null,
                    'secret' => $config['secret_key'] ?? null,
                    'region' => $region,
                    'bucket' => $config['bucket'] ?? null,
                    'endpoint' => $endpoint,
                    'use_path_style_endpoint' => false,
                    'throw' => true,
                    'version' => 'latest',
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
            \Log::info("Testing connection for {$diskType}");
            
            $disk = $this->configureDisk($diskType);
            
            if (!$disk) {
                return [
                    'success' => false,
                    'message' => 'Failed to configure storage disk. Please check your settings.'
                ];
            }

            // Create a test file to verify write permissions
            $testFileName = '.larasafe_connection_test_' . time() . '.txt';
            $testContent = 'LaraSafe Connection Test - ' . date('Y-m-d H:i:s');
            
            \Log::info("Attempting to write test file: {$testFileName}");
            
            // Try to write a test file
            $writeSuccess = Storage::disk($disk)->put($testFileName, $testContent);
            
            if (!$writeSuccess) {
                throw new \Exception('Failed to write test file. Check bucket permissions.');
            }
            
            // Try to read the test file back
            $readContent = Storage::disk($disk)->get($testFileName);
            
            if ($readContent !== $testContent) {
                throw new \Exception('File content verification failed.');
            }
            
            // Delete the test file
            Storage::disk($disk)->delete($testFileName);
            
            \Log::info("Connection test successful for {$diskType}");

            return [
                'success' => true,
                'message' => '✓ Connection successful! Storage is working properly.'
            ];
            
        } catch (\Aws\S3\Exception\S3Exception $e) {
            $errorCode = $e->getAwsErrorCode();
            $errorMessage = $e->getMessage();
            
            \Log::error("AWS S3 Exception during {$diskType} connection test", [
                'code' => $errorCode,
                'message' => $errorMessage
            ]);
            
            // Provide user-friendly error messages
            if ($errorCode === 'InvalidAccessKeyId') {
                $message = 'Invalid Access Key ID. Please verify your credentials.';
            } elseif ($errorCode === 'SignatureDoesNotMatch') {
                $message = 'Invalid Secret Key or Application Key. Please check your credentials.';
            } elseif ($errorCode === 'NoSuchBucket') {
                $message = 'Bucket not found. Please verify the bucket name is correct.';
            } elseif ($errorCode === 'AccessDenied' || $errorCode === 'AllAccessDisabled') {
                $message = 'Access denied. Please check bucket permissions and credentials.';
            } elseif (strpos($errorMessage, 'Could not resolve host') !== false) {
                $message = 'Invalid endpoint URL. Please check the endpoint configuration.';
            } else {
                $message = "Connection failed: {$errorMessage}";
            }
            
            return [
                'success' => false,
                'message' => $message
            ];
            
        } catch (\Exception $e) {
            $errorMessage = $e->getMessage();
            
            \Log::error("Connection test failed for {$diskType}", [
                'error' => $errorMessage,
                'trace' => $e->getTraceAsString()
            ]);
            
            // Parse common error patterns
            if (strpos($errorMessage, 'masked credential') !== false) {
                $message = 'Please re-enter your credentials to test the connection.';
            } elseif (strpos($errorMessage, 'Could not resolve host') !== false) {
                $message = 'Invalid endpoint URL. Please verify the endpoint is correct.';
            } elseif (strpos($errorMessage, 'Connection refused') !== false) {
                $message = 'Connection refused. Please check your endpoint and network settings.';
            } elseif (strpos($errorMessage, '403') !== false) {
                $message = 'Access denied. Please verify credentials and bucket permissions.';
            } elseif (strpos($errorMessage, '404') !== false) {
                $message = 'Bucket not found. Please check the bucket name.';
            } else {
                $message = "Connection test failed: {$errorMessage}";
            }

            return [
                'success' => false,
                'message' => $message
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

            \Log::info("Uploading file to {$diskType}", [
                'local_path' => $localPath,
                'remote_path' => $remotePath,
                'size' => filesize($localPath)
            ]);

            $fileStream = fopen($localPath, 'r');
            $result = Storage::disk($disk)->put($remotePath, $fileStream);
            
            if (is_resource($fileStream)) {
                fclose($fileStream);
            }

            if ($result) {
                \Log::info("File uploaded successfully to {$diskType}");
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

            \Log::info("Downloading file from {$diskType}", [
                'remote_path' => $remotePath,
                'local_path' => $localPath
            ]);

            $contents = Storage::disk($disk)->get($remotePath);
            
            if (!$contents) {
                throw new \Exception("File not found on remote storage: {$remotePath}");
            }

            // Ensure directory exists
            $dir = dirname($localPath);
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }

            file_put_contents($localPath, $contents);
            
            \Log::info("File downloaded successfully from {$diskType}", [
                'size' => filesize($localPath)
            ]);
            
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

            \Log::info("Deleting file from {$diskType}", [
                'remote_path' => $remotePath
            ]);

            $result = Storage::disk($disk)->delete($remotePath);
            
            if ($result) {
                \Log::info("File deleted successfully from {$diskType}");
            }
            
            return $result;
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