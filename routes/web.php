<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectsController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\UserManagementController;

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.post');
});

Route::middleware(['auth'])->group(function () {
    Route::post('logout', [AuthController::class, 'logout'])->name('logout');

    Route::prefix('profile')->group(function () {
        Route::get('/', [ProfileController::class, 'edit'])->name('profile.edit');
        Route::patch('/update', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
        Route::put('/password', [ProfileController::class, 'updatePassword'])->name('profile.password.update');
    });

    Route::get('/', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard.view');
    
    Route::prefix('/projects')->group(function () {
        Route::get('/manage-projects', [ProjectsController::class, 'index'])->name('manage-projects');
        Route::get('/create-project', [ProjectsController::class, 'createProject'])->name('create-project')->middleware('permission:create project');
        Route::post('/store-project', [ProjectsController::class, 'storeProject'])->name('store-project')->middleware('permission:create project');
        Route::get('/edit-project/{id}', [ProjectsController::class, 'editProject'])->name('edit-project')->middleware('permission:edit project');
        Route::delete('/delete-project/{id}', [ProjectsController::class, 'destroyProject'])->name('delete-project')->middleware('permission:delete project');
        Route::get('/view-project/{id}', [ProjectsController::class, 'viewProject'])->name('view-project');
        Route::put('/update-project/{id}', [ProjectsController::class, 'updateProject'])->name('update-project')->middleware('permission:edit project');
    });

    Route::prefix('/backups')->group(function () {
        Route::get('/manage-backups', [BackupController::class, 'index'])->name('manage-backups');
        Route::get('/create-backup', [BackupController::class, 'createBackup'])->name('create-backup')->middleware('permission:create backup');
        Route::get('/view-backup/{id}', [BackupController::class, 'viewBackups'])->name('view-backup');
        Route::post('/store-backup', [BackupController::class, 'storeBackup'])->name('store-backup')->middleware('permission:create backup');
        Route::post('/retry-backup/{id}', [BackupController::class, 'retryBackup'])->name('retry-backup');
        Route::delete('/delete-backup/{id}', [BackupController::class, 'destroy'])->name('backups.destroy')->middleware('permission:delete backup');
        Route::get('/download/{id}', [BackupController::class, 'download'])->name('download.backup')->middleware('permission:download backup');
        Route::get('/edit-backup/{id}', [BackupController::class, 'edit'])->name('backups.edit')->middleware('permission:edit backup');
        Route::put('/update-backup/{id}', [BackupController::class, 'updateBackup'])->name('backups.update')->middleware('permission:edit backup');
        Route::delete('/delete-created-backup/{id}', [BackupController::class, 'destroySubBackup'])->name('backups.destroyCreatedBackup')->middleware('permission:delete backup');
        Route::post('/test-db-connection', [BackupController::class, 'testDatabaseConnection'])->name('test-db-connection');
        Route::post('/restore', [BackupController::class, 'restoreBackup'])->name('restore-backup')->middleware('permission:restore backup');
        Route::post('/test-cloud-connection', [BackupController::class, 'testCloudConnection'])->name('backups.test-cloud');
    });

    Route::prefix('/settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('settings');
        Route::get('/settings', [SettingsController::class, 'getSettings']);
        Route::post('/{type}', [SettingsController::class, 'update']);
        Route::post('/cloud/test-connection', [SettingsController::class, 'testConnection'])->name('settings.cloud.test');
    });

    // User Management Routes - IMPORTANT: More specific routes MUST come before generic ones
    Route::prefix('user-management')->middleware('permission:manage users')->group(function () {
        Route::get('/', [UserManagementController::class, 'index'])->name('user-management');
        Route::get('/create', [UserManagementController::class, 'create'])->name('user-management.create');
        Route::post('/', [UserManagementController::class, 'store'])->name('user-management.store');
        
        // IMPORTANT: Put these BEFORE the /{user} routes
        Route::get('/{user}/permissions', [UserManagementController::class, 'permissions'])->name('user-management.permissions');
        Route::put('/{user}/permissions', [UserManagementController::class, 'updatePermissions'])->name('user-management.permissions.update');
        
        // Generic routes come last
        Route::get('/{user}/edit', [UserManagementController::class, 'edit'])->name('user-management.edit');
        Route::put('/{user}', [UserManagementController::class, 'update'])->name('user-management.update');
        Route::delete('/{user}', [UserManagementController::class, 'destroy'])->name('user-management.destroy');
    });
});