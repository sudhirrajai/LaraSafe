<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Services\DynamicStorageService;

class SettingsController extends Controller
{
    protected $storageService;

    public function __construct(DynamicStorageService $storageService)
    {
        $this->storageService = $storageService;
    }

    public function index()
    {
        $settings = $this->getAllSettings();
        
        return Inertia::render('Settings/Settings', [
            'savedSettings' => $settings
        ]);
    }

    public function getSettings()
    {
        return response()->json($this->getAllSettings());
    }

    private function getAllSettings()
    {
        $settingsData = DB::table('cloud_settings')->get();
        
        $settings = [
            's3' => null,
            'b2' => null,
            'wasabi' => null
        ];
        
        foreach ($settingsData as $setting) {
            $config = json_decode($setting->config, true);
            
            // Decrypt sensitive fields for display (mask them)
            if ($config) {
                $config = $this->maskSensitiveData($config, $setting->type);
            }
            
            $settings[$setting->type] = $config;
        }
        
        return $settings;
    }

    private function maskSensitiveData(array $config, string $type): array
    {
        $sensitiveFields = [
            's3' => ['secret_key'],
            'b2' => ['app_key'],
            'wasabi' => ['secret_key']
        ];

        if (isset($sensitiveFields[$type])) {
            foreach ($sensitiveFields[$type] as $field) {
                if (isset($config[$field]) && !empty($config[$field])) {
                    $value = $config[$field];
                    
                    // Try to decrypt first (it might be encrypted)
                    try {
                        if (strpos($value, '*') === false) {
                            $value = decrypt($value);
                        }
                    } catch (\Exception $e) {
                        // If decryption fails, use the value as-is
                    }
                    
                    // Show only first 4 and last 4 characters
                    if (strlen($value) > 8) {
                        $config[$field] = substr($value, 0, 4) . str_repeat('*', strlen($value) - 8) . substr($value, -4);
                    } else {
                        $config[$field] = str_repeat('*', strlen($value));
                    }
                }
            }
        }

        return $config;
    }

    public function update($type, Request $request)
    {
        if (!in_array($type, ['s3', 'b2', 'wasabi'])) {
            return response()->json([
                'message' => 'Invalid storage type'
            ], 400);
        }

        $rules = $this->getValidationRules($type);
        $validator = Validator::make($request->all(), $rules);
        
        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $validated = $validator->validated();

        try {
            // Get existing settings to preserve unchanged sensitive fields
            $existingSetting = DB::table('cloud_settings')
                ->where('type', $type)
                ->first();

            $existingConfig = $existingSetting ? json_decode($existingSetting->config, true) : [];

            // Merge with existing config, handling masked sensitive fields
            $finalConfig = $this->mergeWithExistingConfig($validated, $existingConfig, $type);

            // Encrypt sensitive data
            $encryptedConfig = $this->encryptSensitiveData($finalConfig, $type);

            if ($existingSetting) {
                DB::table('cloud_settings')
                    ->where('type', $type)
                    ->update([
                        'config' => json_encode($encryptedConfig),
                        'updated_at' => now()
                    ]);
            } else {
                DB::table('cloud_settings')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => $type,
                    'config' => json_encode($encryptedConfig),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            return response()->json([
                'message' => ucfirst($type) . ' settings saved successfully!',
                'data' => $this->maskSensitiveData($validated, $type)
            ]);
        } catch (\Exception $e) {
            \Log::error("Error saving {$type} settings", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'message' => 'Error saving settings: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Merge new config with existing, handling masked sensitive fields
     */
    private function mergeWithExistingConfig(array $newConfig, array $existingConfig, string $type): array
    {
        $sensitiveFields = [
            's3' => ['secret_key'],
            'b2' => ['app_key'],
            'wasabi' => ['secret_key']
        ];

        if (isset($sensitiveFields[$type])) {
            foreach ($sensitiveFields[$type] as $field) {
                // If the new value is masked, use the existing encrypted value
                if (isset($newConfig[$field]) && strpos($newConfig[$field], '*') !== false) {
                    if (isset($existingConfig[$field])) {
                        $newConfig[$field] = $existingConfig[$field];
                    }
                }
            }
        }

        return $newConfig;
    }

    private function encryptSensitiveData(array $data, string $type): array
    {
        $sensitiveFields = [
            's3' => ['secret_key'],
            'b2' => ['app_key'],
            'wasabi' => ['secret_key']
        ];

        if (isset($sensitiveFields[$type])) {
            foreach ($sensitiveFields[$type] as $field) {
                if (isset($data[$field]) && !empty($data[$field])) {
                    // Check if already encrypted (starts with "eyJp" which is base64)
                    // or if it contains special encryption markers
                    $isEncrypted = $this->isAlreadyEncrypted($data[$field]);
                    
                    if (!$isEncrypted && strpos($data[$field], '*') === false) {
                        $data[$field] = encrypt($data[$field]);
                    }
                }
            }
        }

        return $data;
    }

    /**
     * Check if a value is already encrypted
     */
    private function isAlreadyEncrypted(string $value): bool
    {
        // Laravel's encrypted values start with "eyJp" (base64 of {"i)
        if (strpos($value, 'eyJp') === 0) {
            return true;
        }

        // Try to decrypt to verify
        try {
            decrypt($value);
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function testConnection(Request $request)
    {
        $request->validate([
            'type' => 'required|in:s3,b2,wasabi'
        ]);

        try {
            $result = $this->storageService->testConnection($request->type);
            return response()->json($result);
        } catch (\Exception $e) {
            \Log::error("Connection test failed for {$request->type}", [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Connection test failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function getValidationRules($type)
    {
        switch ($type) {
            case 's3':
                return [
                    'access_key' => 'required|string|max:255',
                    'secret_key' => 'required|string|max:255',
                    'bucket' => 'required|string|max:255',
                    'region' => 'required|string|max:255',
                ];
            case 'b2':
                return [
                    'key_id' => 'required|string|max:255',
                    'app_key' => 'required|string|max:255',
                    'bucket' => 'required|string|max:255',
                    'endpoint' => 'required|string|max:255', // Changed to required
                ];
            case 'wasabi':
                return [
                    'access_key' => 'required|string|max:255',
                    'secret_key' => 'required|string|max:255',
                    'bucket' => 'required|string|max:255',
                    'region' => 'required|string|max:255', // Changed to required
                ];
            default:
                return [];
        }
    }
}