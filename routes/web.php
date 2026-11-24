<?php

use App\Http\Controllers\Admin\DepartmentController;
use App\Http\Controllers\Admin\MasterDataController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\CashflowController;
use App\Http\Controllers\OpeningBalanceController;
use App\Http\Controllers\OperationalAdvanceController;
use App\Http\Controllers\TransactionController;
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
    Route::get('/transactions', [TransactionController::class, 'index'])->name('transactions.index');
    Route::get('/transactions/review', [TransactionController::class, 'reviewQueue'])->name('transactions.review-queue');
    Route::get('/transactions/create/{type?}', [TransactionController::class, 'create'])->whereIn('type', ['income', 'expense', 'transfer'])->name('transactions.create');
    Route::post('/transactions', [TransactionController::class, 'store'])->name('transactions.store');
    Route::get('/transactions/{transaction}/corrections/create', [TransactionController::class, 'createCorrection'])->name('transactions.corrections.create');
    Route::post('/transactions/{transaction}/corrections', [TransactionController::class, 'storeCorrection'])->name('transactions.corrections.store');
    Route::get('/transactions/{transaction}', [TransactionController::class, 'show'])->name('transactions.show');
    Route::get('/transactions/{transaction}/edit', [TransactionController::class, 'edit'])->name('transactions.edit');
    Route::put('/transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
    Route::post('/transactions/{transaction}/submit', [TransactionController::class, 'submit'])->name('transactions.submit');
    Route::post('/transactions/{transaction}/review', [TransactionController::class, 'review'])->name('transactions.review');
    Route::post('/transactions/{transaction}/post', [TransactionController::class, 'post'])->name('transactions.post');
    Route::post('/transactions/{transaction}/cancel', [TransactionController::class, 'cancel'])->name('transactions.cancel');
    Route::get('/transactions/{transaction}/attachments/{attachment}/{mode?}', [TransactionController::class, 'attachment'])
        ->whereIn('mode', ['preview', 'download'])->name('transactions.attachments');
    Route::get('/cashflow', [CashflowController::class, 'index'])->name('cashflow.index');
    Route::get('/operational-advances', [OperationalAdvanceController::class, 'index'])->name('operational-advances.index');
    Route::get('/operational-advances/create', [OperationalAdvanceController::class, 'create'])->name('operational-advances.create');
    Route::post('/operational-advances', [OperationalAdvanceController::class, 'store'])->name('operational-advances.store');
    Route::get('/operational-advances/{operationalAdvance}/attachments/{attachment}/{mode?}', [OperationalAdvanceController::class, 'attachment'])
        ->whereIn('mode', ['preview', 'download'])->name('operational-advances.attachments');
    Route::get('/operational-advances/{operationalAdvance}/edit', [OperationalAdvanceController::class, 'edit'])->name('operational-advances.edit');
    Route::put('/operational-advances/{operationalAdvance}', [OperationalAdvanceController::class, 'update'])->name('operational-advances.update');
    Route::post('/operational-advances/{operationalAdvance}/submit', [OperationalAdvanceController::class, 'submit'])->name('operational-advances.submit');
    Route::post('/operational-advances/{operationalAdvance}/review', [OperationalAdvanceController::class, 'review'])->name('operational-advances.review');
    Route::post('/operational-advances/{operationalAdvance}/approve', [OperationalAdvanceController::class, 'approve'])->name('operational-advances.approve');
    Route::post('/operational-advances/{operationalAdvance}/cancel', [OperationalAdvanceController::class, 'cancel'])->name('operational-advances.cancel');
    Route::get('/operational-advances/{operationalAdvance}', [OperationalAdvanceController::class, 'show'])->name('operational-advances.show');
    Route::post('/cash-accounts/opening-balances', [OpeningBalanceController::class, 'store'])->name('cash-accounts.opening-balances.store');
    Route::post('/cash-accounts/opening-balances/{openingBalanceRequest:public_id}/review', [OpeningBalanceController::class, 'review'])->name('cash-accounts.opening-balances.review');

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
            Route::get('/{record}', 'show')->name('show');
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
