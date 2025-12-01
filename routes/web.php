<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\Auth\LoginController as AdminLoginController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Client\AnalyticsController;
use App\Http\Controllers\Client\BillingController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\KnowledgeBaseController;
use App\Http\Controllers\Client\LeadController;
use App\Http\Controllers\Client\WidgetController;
use App\Http\Middleware\AdminAuthenticate;
use App\Models\Tenant;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

// Route model binding for admin client routes
Route::bind('client', function ($value) {
    return Tenant::findOrFail($value);
});

Route::get('/', function () {
    return Inertia::render('Welcome');
})->name('home');

// Guest routes
Route::middleware('guest')->group(function () {
    Route::get('register', [RegisterController::class, 'create'])->name('register');
    Route::post('register', [RegisterController::class, 'store'])->name('register.store');

    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])->name('password.email');

    Route::get('reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'store'])->name('password.store');
});

// Authenticated routes (Client Dashboard)
Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Knowledge Base
    Route::prefix('knowledge')->name('client.knowledge.')->group(function () {
        Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
        Route::get('/create', [KnowledgeBaseController::class, 'create'])->name('create');
        Route::post('/', [KnowledgeBaseController::class, 'store'])->name('store');
        Route::get('/{item}', [KnowledgeBaseController::class, 'show'])->name('show');
        Route::get('/{item}/edit', [KnowledgeBaseController::class, 'edit'])->name('edit');
        Route::put('/{item}', [KnowledgeBaseController::class, 'update'])->name('update');
        Route::delete('/{item}', [KnowledgeBaseController::class, 'destroy'])->name('destroy');
        Route::post('/{item}/reprocess', [KnowledgeBaseController::class, 'reprocess'])->name('reprocess');
    });

    // Widget Settings
    Route::prefix('widget-settings')->name('client.widget.')->group(function () {
        Route::get('/', [WidgetController::class, 'index'])->name('index');
        Route::put('/', [WidgetController::class, 'update'])->name('update');
        Route::post('/regenerate-key', [WidgetController::class, 'regenerateApiKey'])->name('regenerate-key');
    });

    // Leads
    Route::prefix('leads')->name('client.leads.')->group(function () {
        Route::get('/', [LeadController::class, 'index'])->name('index');
        Route::get('/export', [LeadController::class, 'export'])->name('export');
        Route::get('/{lead}', [LeadController::class, 'show'])->name('show');
        Route::get('/{lead}/export', [LeadController::class, 'exportSingle'])->name('export-single');
        Route::put('/{lead}', [LeadController::class, 'update'])->name('update');
        Route::delete('/{lead}', [LeadController::class, 'destroy'])->name('destroy');
    });

    // Analytics
    Route::get('/analytics', [AnalyticsController::class, 'index'])->name('client.analytics.index');

    // Billing
    Route::prefix('billing')->name('client.billing.')->group(function () {
        Route::get('/', [BillingController::class, 'index'])->name('index');
        Route::get('/plans', [BillingController::class, 'plans'])->name('plans');
        Route::get('/subscribe/{plan}', [BillingController::class, 'subscribe'])->name('subscribe');
        Route::post('/subscribe/{plan}', [BillingController::class, 'submitPayment'])->name('submit-payment');
        Route::get('/transactions', [BillingController::class, 'transactions'])->name('transactions');
    });
});

// Admin routes
Route::prefix('admin')->name('admin.')->group(function () {
    Route::middleware('guest:admin')->group(function () {
        Route::get('login', [AdminLoginController::class, 'create'])->name('login');
        Route::post('login', [AdminLoginController::class, 'store'])->name('login.store');
    });

    Route::middleware(AdminAuthenticate::class)->group(function () {
        Route::post('logout', [AdminLoginController::class, 'destroy'])->name('logout');
        Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

        // Client Management
        Route::get('clients', [AdminClientController::class, 'index'])->name('clients.index');
        Route::get('clients/{client}', [AdminClientController::class, 'show'])->name('clients.show');
        Route::put('clients/{client}/status', [AdminClientController::class, 'updateStatus'])->name('clients.update-status');
        Route::put('clients/{client}/plan', [AdminClientController::class, 'updatePlan'])->name('clients.update-plan');
        Route::put('clients/{client}/bot-personality', [AdminClientController::class, 'updateBotPersonality'])->name('clients.update-bot-personality');

        // Transaction Approval
        Route::get('transactions', [AdminTransactionController::class, 'index'])->name('transactions.index');
        Route::get('transactions/{transaction}', [AdminTransactionController::class, 'show'])->name('transactions.show');
        Route::post('transactions/{transaction}/approve', [AdminTransactionController::class, 'approve'])->name('transactions.approve');
        Route::post('transactions/{transaction}/reject', [AdminTransactionController::class, 'reject'])->name('transactions.reject');

        // Activity Logs
        Route::get('logs', [AdminActivityLogController::class, 'index'])->name('logs.index');
    });
});
