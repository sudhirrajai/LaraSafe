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
        $users = User::with('roles', 'permissions')
                 ->whereDoesntHave('roles', function ($q) {
                     $q->where('name', 'admin');
                 })
                 ->get();
        return Inertia::render('Users/Index', [
            'users' => $users,
        ]);
    }

    public function create()
    {
        // Get available roles based on current user's role
        $availableRoles = $this->getAvailableRoles();
        
        return Inertia::render('Users/Create', [
            'roles' => $availableRoles,
            'allPermissions' => Permission::all()->groupBy(function($permission) {
                if (str_contains($permission->name, 'user')) return 'User Management';
                if (str_contains($permission->name, 'project')) return 'Project Management';
                if (str_contains($permission->name, 'backup')) return 'Backup Management';
                return 'Other';
            }),
            'currentUserRole' => auth()->user()->roles->first()?->name,
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

        // Check if current user can assign this role
        if (!$this->canAssignRole($request->role)) {
            return back()->withErrors(['role' => 'You do not have permission to assign this role.']);
        }

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => bcrypt($request->password),
        ]);

        $user->assignRole($request->role);

        return redirect()->route('user-management')->with('success', 'User created successfully with default permissions');
    }

    public function edit(User $user)
    {
        // Prevent managers from editing admins or other managers
        if (!$this->canEditUser($user)) {
            return redirect()->route('user-management')
                ->with('error', 'You do not have permission to edit this user.');
        }

        $availableRoles = $this->getAvailableRoles();

        return Inertia::render('Users/Edit', [
            'user' => $user->load('roles', 'permissions'),
            'roles' => $availableRoles,
            'allPermissions' => Permission::all()->groupBy(function($permission) {
                if (str_contains($permission->name, 'user')) return 'User Management';
                if (str_contains($permission->name, 'project')) return 'Project Management';
                if (str_contains($permission->name, 'backup')) return 'Backup Management';
                return 'Other';
            }),
            'currentUserRole' => auth()->user()->roles->first()?->name,
        ]);
    }

    public function update(Request $request, User $user)
    {
        // Prevent managers from editing admins or other managers
        if (!$this->canEditUser($user)) {
            return redirect()->route('user-management')
                ->with('error', 'You do not have permission to edit this user.');
        }

        $rules = [
            'name' => 'required|string|max:255',
            'email' => 'required|string|email|max:255|unique:users,email,' . $user->id,
            'role' => 'required|exists:roles,name',
        ];

        if ($request->filled('password')) {
            $rules['password'] = 'required|min:8|confirmed';
        }

        $request->validate($rules);

        // Check if current user can assign this role
        if (!$this->canAssignRole($request->role)) {
            return back()->withErrors(['role' => 'You do not have permission to assign this role.']);
        }

        // Prevent managers from promoting users to admin or manager
        $currentRole = $user->roles->first()?->name;
        if ($currentRole !== $request->role && !$this->canAssignRole($request->role)) {
            return back()->withErrors(['role' => 'You cannot promote users to this role.']);
        }

        $user->update([
            'name' => $request->name,
            'email' => $request->email,
        ]);

        if ($request->filled('password')) {
            $user->update([
                'password' => bcrypt($request->password),
            ]);
        }

        $user->syncRoles([$request->role]);

        return redirect()->route('user-management')->with('success', 'User updated successfully' . ($request->filled('password') ? ' (Password changed)' : ''));
    }

    public function destroy(User $user)
    {
        // Prevent deletion of own account
        if ($user->id === auth()->id()) {
            return back()->with('error', 'You cannot delete your own account');
        }

        // Prevent managers from deleting admins or other managers
        if (!$this->canEditUser($user)) {
            return back()->with('error', 'You do not have permission to delete this user.');
        }

        $user->delete();

        return redirect()->route('user-management')->with('success', 'User deleted successfully');
    }

    public function permissions(User $user)
    {
        // Prevent managers from editing permissions of admins or other managers
        if (!$this->canEditUser($user)) {
            return redirect()->route('user-management')
                ->with('error', 'You do not have permission to edit this user\'s permissions.');
        }

        return Inertia::render('Users/Permissions', [
            'user' => $user->load('roles', 'permissions'),
            'allPermissions' => Permission::all()->groupBy(function($permission) {
                if (str_contains($permission->name, 'user')) return 'User Management';
                if (str_contains($permission->name, 'project')) return 'Project Management';
                if (str_contains($permission->name, 'backup')) return 'Backup Management';
                return 'Other';
            }),
            'userPermissions' => $user->getAllPermissions()->pluck('name')->toArray(),
            'currentUserRole' => auth()->user()->roles->first()?->name,
        ]);
    }

    public function updatePermissions(Request $request, User $user)
    {
        // Prevent managers from editing permissions of admins or other managers
        if (!$this->canEditUser($user)) {
            return redirect()->route('user-management')
                ->with('error', 'You do not have permission to edit this user\'s permissions.');
        }

        $request->validate([
            'permissions' => 'required|array',
            'permissions.*' => 'exists:permissions,name',
        ]);

        $user->syncPermissions($request->permissions);

        return redirect()->route('user-management')->with('success', 'User permissions updated successfully');
    }

    /**
     * Get roles that the current user can assign
     */
    private function getAvailableRoles()
    {
        $currentUser = auth()->user();
        
        // Admins can see all roles
        if ($currentUser->hasRole('admin')) {
            return Role::all();
        }
        
        // Managers can only assign 'user' and 'viewer' roles
        if ($currentUser->hasRole('manager')) {
            return Role::whereIn('name', ['user', 'viewer'])->get();
        }
        
        // Others cannot assign any roles
        return collect([]);
    }

    /**
     * Check if current user can assign a specific role
     */
    private function canAssignRole($roleName)
    {
        $currentUser = auth()->user();
        
        // Admins can assign any role
        if ($currentUser->hasRole('admin')) {
            return true;
        }
        
        // Managers can only assign 'user' and 'viewer' roles
        if ($currentUser->hasRole('manager')) {
            return in_array($roleName, ['user', 'viewer']);
        }
        
        return false;
    }

    /**
     * Check if current user can edit a specific user
     */
    private function canEditUser(User $user)
    {
        $currentUser = auth()->user();
        
        // Admins can edit anyone
        if ($currentUser->hasRole('admin')) {
            return true;
        }
        
        // Managers cannot edit admins or other managers
        if ($currentUser->hasRole('manager')) {
            $targetUserRoles = $user->roles->pluck('name')->toArray();
            return !in_array('admin', $targetUserRoles) && !in_array('manager', $targetUserRoles);
        }
        
        return false;
    }
}