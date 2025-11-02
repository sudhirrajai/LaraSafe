<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckPermission
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        // Ensure user is authenticated
        if (!$request->user()) {
            if ($request->inertia()) {
                return redirect()->route('login')->with('error', 'Please log in to continue.');
            }
            abort(403, 'Unauthorized action.');
        }

        // Ensure user has the required permission
        if (!$request->user()->can($permission)) {
            if ($request->inertia()) {
                return redirect()->back()->with('error', 'You do not have permission to perform this action.');
            }
            abort(403, 'You do not have permission to perform this action.');
        }

        return $next($request);
    }
}
