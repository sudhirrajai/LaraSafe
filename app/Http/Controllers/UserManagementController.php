<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Illuminate\Support\Facades\Hash;

class UserManagementController extends Controller
{
    public function index()
    {
        return Inertia::render('Users/Index', [
            'users' => User::with('roles', 'permissions')->get(),
        ]);
    }

    public function create()
    {
        return Inertia::render('Users/Create', [
            'roles' => Role::all(),
            'allPermissions' => Permission::all()->groupBy(function($permission) {
                // Group permissions by category
                if (str_contains($permission->name, 'user')) return 'User Management';
                if (str_contains($permission->name, 'project')) return 'Project Management';
                if (str_contains($permission->name, 'backup')) return 'Backup Management';
                return 'Other';
            }),
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users',
            'password' => 'required|string|min:8|confirmed',
            'role' => 'required|exists:roles,name',
        ]);

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
        ]);

        // Assign the selected role (which comes with default permissions)
        $user->assignRole($request->role);

        return redirect()->route('user-management')->with('success', 'User created successfully with default permissions');
    }

    public function edit(User $user)
    {
        return Inertia::render('Users/Edit', [
            'user' => $user->load('roles', 'permissions'),
            'roles' => Role::all(),
            'allPermissions' => Permission::all()->groupBy(function($permission) {
                if (str_contains($permission->name, 'user')) return 'User Management';
                if (str_contains($permission->name, 'project')) return 'Project Management';
                if (str_contains($permission->name, 'backup')) return 'Backup Management';
                return 'Other';
            }),
        ]);
    }

    public function update(Request $request, User $user)
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|exists:roles,name',
        ];

        // Only validate password if provided
        if ($request->filled('password')) {
            $rules['password'] = 'required|min:8|confirmed';
        }

        $request->validate($rules);

        // Update basic info
        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        // Update password if provided
        if ($request->filled('password')) {
            $user->update([
                'password' => bcrypt($request->password),
            ]);
        }

        // Sync role
        $user->syncRoles([$request->role]);

        return redirect()->route('user-management')->with('success', 'User updated successfully' . ($request->filled('password') ? ' (Password changed)' : ''));
    }

    public function destroy(User $user)
    {
        // Prevent deletion of own account
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account');
        }

        $user->delete();

        return redirect()->route('user-management')->with('success', 'User deleted successfully');
    }

    // New method to view/edit user permissions
public function permissions(User $user)
{
    return Inertia::render('Users/Permissions', [
        'user' => $user->load('roles', 'permissions'),
        'allPermissions' => Permission::all()->groupBy(function($permission) {
            if (str_contains($permission->name, 'user')) return 'User Management';
            if (str_contains($permission->name, 'project')) return 'Project Management';
            if (str_contains($permission->name, 'backup')) return 'Backup Management';
            return 'Other';
        }),
        'userPermissions' => $user->getAllPermissions()->pluck('name')->toArray(),
    ]);
}

    // New method to update user permissions
    public function updatePermissions(Request $request, User $user)
    {
        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        // Sync permissions (this will override role permissions)
        $user->syncPermissions($request->permissions);

        return redirect()->route('user-management')->with('success', 'User permissions updated successfully');
    }
}