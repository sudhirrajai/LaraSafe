<?php

namespace App\Http\Middleware;

use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that is loaded on the first page visit.
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determine the current asset version.
     */
    public function version(Request $request): string|null
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        
        return array_merge(parent::share($request), [
            'flash' => [
                'success' => fn () => $request->session()->get('success') ?? $request->session()->get('flash.success'),
                'error'   => fn () => $request->session()->get('error') ?? $request->session()->get('flash.error'),
            ],
            'auth' => [
                'user' => $user ? [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'avatar' => $user->avatar ?? null,
                    // Return as simple array of strings
                    'roles' => $user->roles->pluck('name')->values()->toArray(),
                    'permissions' => $user->getAllPermissions()->pluck('name')->values()->toArray(),
                ] : null,
            ],
        ]);
    }
}