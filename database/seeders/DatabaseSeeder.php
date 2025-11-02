<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Create default user
        // $user = User::factory()->create([
        //     'id' => (string) Str::uuid(),
        //     'name' => 'LaraSafe',
        //     'email' => 'sudhirrajai@proton.me',
        //     'email_verified_at' => now(),
        //     'password' => bcrypt('12345678'),
        // ]);

        // Seed cloud settings
        $this->call([
            CloudSettingsSeeder::class,
        ]);
        $this->call([
            RolePermissionSeeder::class,
        ]);
    }
}