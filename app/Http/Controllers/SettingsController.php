<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class SettingsController extends Controller
{
    public function index()
    {
        // Load existing settings from database
        $settings = $this->getAllSettings();
        
        return Inertia::render('Settings/Settings', [
            'savedSettings' => $settings
        ]);
    }

    public function getSettings()
    {
        // API endpoint to fetch settings
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
            $settings[$setting->type] = json_decode($setting->config, true);
        }
        
        return $settings;
    }

    public function update($type, Request $request)
    {
        // Validate the type
        if (!in_array($type, ['s3', 'b2', 'wasabi'])) {
            return response()->json([
                'message' => 'Invalid storage type'
            ], 400);
        }

        // Validation rules based on type
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
            // Check if record exists
            $existingSetting = DB::table('cloud_settings')
                ->where('type', $type)
                ->first();

            if ($existingSetting) {
                // Update existing record
                DB::table('cloud_settings')
                    ->where('type', $type)
                    ->update([
                        'config' => json_encode($validated),
                        'updated_at' => now()
                    ]);
            } else {
                // Insert new record with UUID
                DB::table('cloud_settings')->insert([
                    'id' => (string) Str::uuid(),
                    'type' => $type,
                    'config' => json_encode($validated),
                    'created_at' => now(),
                    'updated_at' => now()
                ]);
            }

            return response()->json([
                'message' => ucfirst($type) . ' settings saved successfully!',
                'data' => $validated
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Error saving settings: ' . $e->getMessage()
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