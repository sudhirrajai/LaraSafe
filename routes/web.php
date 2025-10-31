<?php

use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProjectsController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\SettingsController;
use Spatie\ResponseCache\Middlewares\CacheResponse;

// Route::get('/login', [DashboardController::class, 'index'])->name('login');

Route::middleware('guest')->group(function () {
    Route::get('login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('login', [AuthController::class, 'login'])->name('login.post');
});

Route::middleware(['auth', CacheResponse::class])->group(function () {
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
        Route::get('/create-project', [ProjectsController::class, 'createProject'])->name('create-project');
        Route::post('/store-project', [ProjectsController::class, 'storeProject'])->name('store-project');
        Route::get('/edit-project/{id}', [ProjectsController::class, 'editProject'])->name('edit-project');
        Route::delete('/delete-project/{id}', [ProjectsController::class, 'destroyProject'])->name('delete-project');
        Route::get('/view-project/{id}', [ProjectsController::class, 'viewProject'])->name('view-project');
        Route::put('/update-project/{id}', [ProjectsController::class, 'updateProject'])->name('update-project');
    });

    Route::prefix('/backups')->group(function () {
        Route::get('/manage-backups', [BackupController::class, 'index'])->name('manage-backups');
        Route::get('/create-backup', [BackupController::class, 'createBackup'])->name('create-backup');
        Route::get('/view-backup/{id}', [BackupController::class, 'viewBackups'])->name('view-backup');
        Route::post('/store-backup', [BackupController::class, 'storeBackup'])->name('store-backup');
        Route::post('/retry-backup/{id}', [BackupController::class, 'retryBackup'])->name('retry-backup');
        Route::delete('/delete-backup/{id}', [BackupController::class, 'destroy'])->name('backups.destroy');
        Route::get('/download/{id}', [BackupController::class, 'download'])->name('download.backup');
        Route::get('/edit-backup/{id}', [BackupController::class, 'edit'])->name('backups.edit');
        Route::put('/update-backup/{id}', [BackupController::class, 'updateBackup'])->name('backups.update');
        Route::delete('/delete-created-backup/{id}', [BackupController::class, 'destroySubBackup'])->name('backups.destroyCreatedBackup');
        Route::post('/test-db-connection', [BackupController::class, 'testDatabaseConnection'])->name('test-db-connection');
        Route::post('/restore', [BackupController::class, 'restoreBackup'])->name('restore-backup');
    });

    Route::prefix('/settings')->group(function () {
        Route::get('/', [SettingsController::class, 'index'])->name('settings');
        Route::get('/settings', [SettingsController::class, 'getSettings']);
        Route::post('/{type}', [SettingsController::class, 'update']);
    });

});