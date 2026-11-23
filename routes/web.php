<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'dashboard' : 'login');
})->name('home');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:5,1');
});

Route::middleware(['auth', 'active'])->group(function (): void {
    Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->name('logout');
    Route::view('/dashboard', 'dashboard')->name('dashboard');

    Route::prefix('admin')->name('admin.')->group(function (): void {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{user}', [UserController::class, 'show'])->name('users.show');
        Route::get('/users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{user}', [UserController::class, 'update'])->name('users.update');
        Route::patch('/users/{user}/roles', [UserController::class, 'updateRoles'])->name('users.roles.update');
        Route::patch('/users/{user}/status', [UserController::class, 'updateStatus'])->name('users.status.update');
        Route::put('/users/{user}/password', [UserController::class, 'resetPassword'])->name('users.password.update');
        Route::resource('departments', DepartmentController::class)->except(['show', 'destroy'])->names('departments');
        Route::patch('/departments/{department}/status', [DepartmentController::class, 'updateStatus'])->name('departments.status.update');
        Route::prefix('master/{resource}')->name('master.')->controller(MasterDataController::class)->group(function (): void {
            Route::get('/', 'index')->name('index');
            Route::get('/create', 'create')->name('create');
            Route::post('/', 'store')->name('store');
            Route::get('/{record}/edit', 'edit')->name('edit');
            Route::put('/{record}', 'update')->name('update');
            Route::patch('/{record}/status', 'updateStatus')->name('status.update');
            Route::delete('/{record}', 'destroy')->name('destroy');
        });
        Route::get('/audit-logs', function () {
            Gate::authorize('audit_logs.view');

            return view('admin.audit-logs.index', ['auditLogs' => AuditLog::query()->with('user')->latest('created_at')->paginate(25)]);
        })->name('audit-logs.index');
    });
});
