<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use App\Models\Settings;

class SettingsController extends Controller
{
    public function index()
    {
        return Inertia::render('Settings/Settings', [
            // 'settings' => Settings::all()
        ]);
    }
        public function update($type, Request $request)
    {
        $validated = $request->all();

        // You can store this in a settings table or .env using Spatie/Laravel-Settings
        // Example: store in database
        \DB::table('cloud_settings')->updateOrInsert(
            ['type' => $type],
            ['config' => json_encode($validated)]
        );

        return response()->json(['message' => ucfirst($type) . ' settings saved successfully!']);
    }
}
