<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CloudSettingsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $settings = [
            [
                'id' => (string) Str::uuid(),
                'type' => 's3',
                'config' => json_encode([
                    'access_key' => '',
                    'secret_key' => '',
                    'bucket' => '',
                    'region' => 'us-east-1'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'type' => 'b2',
                'config' => json_encode([
                    'key_id' => '',
                    'app_key' => '',
                    'bucket' => '',
                    'endpoint' => ''
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => (string) Str::uuid(),
                'type' => 'wasabi',
                'config' => json_encode([
                    'access_key' => '',
                    'secret_key' => '',
                    'bucket' => '',
                    'region' => 'us-east-1'
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        foreach ($settings as $setting) {
            DB::table('cloud_settings')->updateOrInsert(
                ['type' => $setting['type']],
                $setting
            );
        }
    }
}