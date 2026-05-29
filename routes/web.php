<?php

use App\Http\Controllers\Admin\ActivityLogController as AdminActivityLogController;
use App\Http\Controllers\Admin\ClientController as AdminClientController;
use App\Http\Controllers\Admin\DashboardController as AdminDashboardController;
use App\Http\Controllers\Admin\EnterpriseInquiryController as AdminEnterpriseInquiryController;
use App\Http\Controllers\Admin\PlanController as AdminPlanController;
use App\Http\Controllers\Admin\TransactionController as AdminTransactionController;
use App\Http\Controllers\Auth\ChooseRoleController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\RegisterController;
use App\Http\Controllers\Auth\ResetPasswordController;
use App\Http\Controllers\Client\AnalyticsController;
use App\Http\Controllers\Client\BillingController;
use App\Http\Controllers\Client\ConversationController;
use App\Http\Controllers\Client\DashboardController;
use App\Http\Controllers\Client\DkBankQrController;
use App\Http\Controllers\Client\EnterpriseInquiryController;
use App\Http\Controllers\Client\KnowledgeBaseController;
use App\Http\Controllers\Client\LeadController;
use App\Http\Controllers\Client\WebsiteIndexingController;
use App\Http\Controllers\Client\WidgetController;
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
    Route::post('register', [RegisterController::class, 'store'])
        ->middleware('throttle:5,1,register')
        ->name('register.store');

    Route::get('login', [LoginController::class, 'create'])->name('login');
    Route::post('login', [LoginController::class, 'store'])->name('login.store');

    Route::get('forgot-password', [ForgotPasswordController::class, 'create'])->name('password.request');
    Route::post('forgot-password', [ForgotPasswordController::class, 'store'])
        ->middleware('throttle:5,1,forgot')
        ->name('password.email');

    Route::get('reset-password/{token}', [ResetPasswordController::class, 'create'])->name('password.reset');
    Route::post('reset-password', [ResetPasswordController::class, 'store'])->name('password.store');
});

// Authenticated routes (Client Dashboard)
Route::middleware('auth')->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');

    Route::get('dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Dual-role chooser — shown when a user holds both super_admin + a tenant role
    Route::get('/login/choose', [ChooseRoleController::class, 'create'])->name('login.choose');
    Route::post('/login/choose', [ChooseRoleController::class, 'store'])->name('login.choose.store');

    // Knowledge Base
    Route::prefix('knowledge')->name('client.knowledge.')->group(function () {
        Route::get('/', [KnowledgeBaseController::class, 'index'])->name('index');
        Route::get('/create', [KnowledgeBaseController::class, 'create'])->name('create');
        Route::post('/', [KnowledgeBaseController::class, 'store'])->middleware('check.limits:knowledge_items')->name('store');
        Route::get('/{item}', [KnowledgeBaseController::class, 'show'])->name('show');
        Route::get('/{item}/edit', [KnowledgeBaseController::class, 'edit'])->name('edit');
        Route::put('/{item}', [KnowledgeBaseController::class, 'update'])->middleware('block.expired')->name('update');
        Route::delete('/{item}', [KnowledgeBaseController::class, 'destroy'])->middleware('block.expired')->name('destroy');
        Route::post('/{item}/reprocess', [KnowledgeBaseController::class, 'reprocess'])->middleware('block.expired')->name('reprocess');
        Route::post('/{item}/retry', [KnowledgeBaseController::class, 'retry'])->middleware('block.expired')->name('retry');
    });

    // Widget Settings
    Route::prefix('widget-settings')->name('client.widget.')->group(function () {
        Route::get('/', [WidgetController::class, 'index'])->name('index');
        Route::put('/', [WidgetController::class, 'update'])->name('update');
        Route::post('/regenerate-key', [WidgetController::class, 'regenerateApiKey'])->name('regenerate-key');
    });

    // Website Indexing
    Route::patch('/widget-settings/website-indexing', [WebsiteIndexingController::class, 'update'])->name('widget.indexing.update');
    Route::post('/widget-settings/website-indexing/recrawl', [WebsiteIndexingController::class, 'recrawl'])->name('widget.indexing.recrawl');
    Route::get('/widget-settings/website-indexing/status', [WebsiteIndexingController::class, 'latestStatus'])->name('widget.indexing.status');

    // Leads
    Route::prefix('leads')->name('client.leads.')->group(function () {
        Route::get('/', [LeadController::class, 'index'])->name('index');
        Route::get('/export', [LeadController::class, 'export'])->name('export');
        Route::get('/{lead}', [LeadController::class, 'show'])->name('show');
        Route::get('/{lead}/export', [LeadController::class, 'exportSingle'])->name('export-single');
        Route::put('/{lead}', [LeadController::class, 'update'])->name('update');
        Route::delete('/{lead}', [LeadController::class, 'destroy'])->name('destroy');
    });

    Route::prefix('conversations')->name('client.conversations.')->group(function () {
        Route::get('/', [ConversationController::class, 'index'])->name('index');
        Route::get('/{conversation}', [ConversationController::class, 'show'])->name('show');
        Route::get('/{conversation}/export', [ConversationController::class, 'export'])->name('export');
        Route::put('/{conversation}/archive', [ConversationController::class, 'archive'])->name('archive');
        Route::put('/{conversation}/unarchive', [ConversationController::class, 'unarchive'])->name('unarchive');
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
        Route::get('/transactions/{transaction}/receipt', [BillingController::class, 'downloadReceipt'])->name('receipt');
        Route::post('/start-free-plan', [BillingController::class, 'startFreePlan'])->name('start-free-plan');
        Route::post('/enterprise-inquiry', [EnterpriseInquiryController::class, 'store'])->name('enterprise-inquiry');

        // DK Bank QR payment flow
        Route::post('/dk-qr/{plan}', [DkBankQrController::class, 'start'])
            ->name('dk-qr.start');

        Route::get('/dk-qr/transaction/{transaction}', [DkBankQrController::class, 'show'])
            ->name('dk-qr.show');

        Route::get('/dk-qr/{transaction}/status', [DkBankQrController::class, 'status'])
            ->name('dk-qr.status')
            ->middleware('throttle:60,1');

        Route::post('/dk-qr/{transaction}/verify-rrn', [DkBankQrController::class, 'verifyRrn'])
            ->name('dk-qr.verify-rrn')
            ->middleware('throttle:dk-rrn-verify');
    });
});

// Admin routes — protected by single web guard + RequireSuperAdmin middleware
// Pitfall 7: Stripe/Cashier webhook routes are auto-registered by Cashier's service provider
// at /stripe/webhook (outside this group) and must NOT be placed here.
Route::prefix('admin')->name('admin.')->middleware(['auth', 'require.super_admin'])->group(function () {
    Route::post('logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('dashboard', [AdminDashboardController::class, 'index'])->name('dashboard');

    // Client Management
    Route::get('clients', [AdminClientController::class, 'index'])->name('clients.index');
    Route::get('clients/{client}', [AdminClientController::class, 'show'])->name('clients.show');
    Route::put('clients/{client}/status', [AdminClientController::class, 'updateStatus'])->name('clients.update-status');
    Route::put('clients/{client}/plan', [AdminClientController::class, 'updatePlan'])->name('clients.update-plan');
    Route::put('clients/{client}/bot-personality', [AdminClientController::class, 'updateBotPersonality'])->name('clients.update-bot-personality');
    Route::post('clients/{id}/restore', [AdminClientController::class, 'restore'])->name('clients.restore');

    // Plan Management
    Route::get('plans', [AdminPlanController::class, 'index'])->name('plans.index');
    Route::get('plans/create', [AdminPlanController::class, 'create'])->name('plans.create');
    Route::post('plans', [AdminPlanController::class, 'store'])->name('plans.store');
    Route::get('plans/{plan}/edit', [AdminPlanController::class, 'edit'])->name('plans.edit');
    Route::put('plans/{plan}', [AdminPlanController::class, 'update'])->name('plans.update');
    Route::patch('plans/{plan}/toggle', [AdminPlanController::class, 'toggleStatus'])->name('plans.toggle');

    // Transaction Approval
    Route::get('transactions', [AdminTransactionController::class, 'index'])->name('transactions.index');
    Route::get('transactions/{transaction}', [AdminTransactionController::class, 'show'])->name('transactions.show');
    Route::post('transactions/{transaction}/approve', [AdminTransactionController::class, 'approve'])->name('transactions.approve');
    Route::post('transactions/{transaction}/reject', [AdminTransactionController::class, 'reject'])->name('transactions.reject');

    // Enterprise Inquiries
    Route::get('inquiries', [AdminEnterpriseInquiryController::class, 'index'])->name('inquiries.index');
    Route::put('inquiries/{inquiry}', [AdminEnterpriseInquiryController::class, 'update'])->name('inquiries.update');

    // Activity Logs
    Route::get('activity-logs', [AdminActivityLogController::class, 'index'])->name('logs.index');
});
