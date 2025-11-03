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
                    // Show only first 4 and last 4 characters
                    $value = $config[$field];
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
            // Encrypt sensitive data
            $encryptedConfig = $this->encryptSensitiveData($validated, $type);

            $existingSetting = DB::table('cloud_settings')
                ->where('type', $type)
                ->first();

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
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'message' => 'Error saving settings: ' . $e->getMessage()
            ], 500);
        }
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
                    // Only encrypt if it's not already masked
                    if (strpos($data[$field], '*') === false) {
                        $data[$field] = encrypt($data[$field]);
                    }
                }
            }
        }

        return $data;
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
                'error' => $e->getMessage()
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
                    'endpoint' => 'nullable|string|max:255',
                ];
            case 'wasabi':
                return [
                    'access_key' => 'required|string|max:255',
                    'secret_key' => 'required|string|max:255',
                    'bucket' => 'required|string|max:255',
                    'region' => 'nullable|string|max:255',
                ];
            default:
                return [];
        }
    }
}