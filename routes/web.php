<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\AdminController;
use App\Http\Controllers\PharmacistController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\PrescriptionController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\UserController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
*/

// ROUTE RACINE
Route::get('/', function () {
    if (auth()->check()) {
        $user = auth()->user();
        if ($user->isAdmin()) {
            return redirect()->route('admin.dashboard');
        } else {
            return redirect()->route('pharmacist.dashboard');
        }
    }
    return redirect()->route('login');
});

// Authentication routes (sans middleware log.activity)
Route::get('/login', [AuthController::class, 'showLoginForm'])->name('login');
Route::post('/login', [AuthController::class, 'login']);
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

// Password reset routes
Route::get('/forgot-password', [AuthController::class, 'showForgotPasswordForm'])->name('password.forgot');
Route::post('/forgot-password', [AuthController::class, 'sendResetCode'])->name('password.send.code');
Route::get('/reset-password', [AuthController::class, 'showResetForm'])->name('password.reset.form');
Route::post('/reset-password', [AuthController::class, 'resetPassword'])->name('password.reset');

// Protected routes - SANS log.activity pour l'instant
Route::middleware('auth')->group(function () {
    
    // Dashboard routes
    Route::get('/admin/dashboard', [AdminController::class, 'index'])->name('admin.dashboard')->middleware('admin');
    Route::get('/pharmacist/dashboard', [PharmacistController::class, 'index'])->name('pharmacist.dashboard')->middleware('pharmacist');
    
    // Routes des rapports
    Route::prefix('rapports')->name('reports.')->group(function () {
        Route::get('/', [ReportController::class, 'index'])->name('index');
        Route::get('/ventes', [ReportController::class, 'sales'])->name('sales');
        Route::get('/inventaire', [ReportController::class, 'inventory'])->name('inventory');
        Route::get('/clients', [ReportController::class, 'clients'])->name('clients');
        Route::get('/ordonnances', [ReportController::class, 'prescriptions'])->name('prescriptions');
        Route::get('/financier', [ReportController::class, 'financial'])->name('financial');
        Route::get('/utilisateurs', [ReportController::class, 'users'])->name('users')->middleware('admin');
        Route::get('/fournisseurs', [ReportController::class, 'suppliers'])->name('suppliers')->middleware('admin');
    });

    // Notification routes
    Route::prefix('notifications')->name('notifications.')->group(function () {
        Route::get('/', [NotificationController::class, 'index'])->name('index');
        Route::get('/recent', [NotificationController::class, 'getRecent'])->name('recent');
        Route::get('/count', [NotificationController::class, 'getUnreadCount'])->name('count');
        Route::get('/settings', [NotificationController::class, 'settings'])->name('settings');
        Route::post('/settings', [NotificationController::class, 'updateSettings'])->name('settings.update');
        
        Route::post('/{id}/mark-read', [NotificationController::class, 'markAsRead'])->name('mark-read');
        Route::post('/mark-all-read', [NotificationController::class, 'markAllAsRead'])->name('mark-all-read');
        Route::post('/test', [NotificationController::class, 'createTest'])->name('test');
        
        Route::delete('/{id}', [NotificationController::class, 'destroy'])->name('destroy');
        Route::delete('/read/all', [NotificationController::class, 'deleteAllRead'])->name('delete-read');
    });
    
    // Inventory management routes
    Route::resource('inventory', ProductController::class)->names([
        'index' => 'inventory.index',
        'create' => 'inventory.create',
        'store' => 'inventory.store',
        'show' => 'inventory.show',
        'edit' => 'inventory.edit',
        'update' => 'inventory.update',
        'destroy' => 'inventory.destroy'
    ]);
    
    // Client management routes
    Route::resource('clients', ClientController::class);
    
    // Sales management routes
    Route::resource('sales', SaleController::class);
    Route::get('sales/{id}/print', [SaleController::class, 'print'])->name('sales.print');
    Route::get('sales/product/{id}', [SaleController::class, 'getProduct'])->name('sales.get-product');
    
    // Prescription management routes
    Route::resource('prescriptions', PrescriptionController::class);
    Route::get('prescriptions/{id}/deliver', [PrescriptionController::class, 'deliver'])->name('prescriptions.deliver');
    Route::post('prescriptions/{id}/deliver', [PrescriptionController::class, 'processDelivery'])->name('prescriptions.process-delivery');
    Route::get('prescriptions/{id}/print', [PrescriptionController::class, 'print'])->name('prescriptions.print');
    
    // Admin-only routes
    Route::middleware('admin')->group(function () {
        // Supplier management routes
        Route::resource('suppliers', SupplierController::class)->names([
            'index' => 'suppliers.index',
            'create' => 'suppliers.create',
            'store' => 'suppliers.store',
            'show' => 'suppliers.show',
            'edit' => 'suppliers.edit',
            'update' => 'suppliers.update',
            'destroy' => 'suppliers.destroy'
        ]);
        
        // Purchase management routes
        Route::resource('purchases', PurchaseController::class)->names([
            'index' => 'purchases.index',
            'create' => 'purchases.create',
            'store' => 'purchases.store',
            'show' => 'purchases.show',
            'edit' => 'purchases.edit',
            'update' => 'purchases.update',
            'destroy' => 'purchases.destroy'
        ]);
        
        Route::get('purchases/{id}/print', [PurchaseController::class, 'print'])->name('purchases.print');
        Route::get('purchases/{id}/receive', [PurchaseController::class, 'receive'])->name('purchases.receive');
        Route::post('purchases/{id}/receive', [PurchaseController::class, 'processReception'])->name('purchases.process-reception');
        Route::patch('purchases/{id}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel');
    });
    
    // Admin panel routes
    Route::prefix('admin')->name('admin.')->middleware('admin')->group(function () {
        Route::get('/dashboard', [AdminController::class, 'index'])->name('dashboard');
        Route::get('/administration', [AdminController::class, 'administration'])->name('administration');
        Route::get('/settings', [AdminController::class, 'settings'])->name('settings');
        Route::post('/settings', [AdminController::class, 'updateSettings'])->name('settings.update');
        
        // User management
        Route::resource('users', UserController::class)->names([
            'index' => 'users.index',
            'create' => 'users.create',
            'store' => 'users.store',
            'show' => 'users.show',
            'edit' => 'users.edit',
            'update' => 'users.update',
            'destroy' => 'users.destroy'
        ]);
        
        Route::get('users/export', [UserController::class, 'export'])->name('users.export');
        Route::patch('users/{id}/toggle-status', [UserController::class, 'toggleStatus'])->name('users.toggle-status');
        Route::patch('users/{id}/reset-password', [UserController::class, 'resetPassword'])->name('users.reset-password');
        Route::get('users/{id}/activity', [UserController::class, 'activityLogs'])->name('users.activity-logs');
        
        // Activity logs
        Route::get('/activity-logs', [AdminController::class, 'activityLogs'])->name('activity-logs');
        Route::get('/activity-logs/export', [AdminController::class, 'exportActivityLogs'])->name('export-activity-logs');
        Route::post('/clear-old-logs', [AdminController::class, 'clearOldLogs'])->name('clear-old-logs');
    });
});