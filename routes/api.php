<?php

use App\Http\Controllers\CashSessionController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\FinancialReportController;
use App\Http\Controllers\MobileApiController;
use App\Http\Controllers\MobileAuthController;
use App\Http\Controllers\OfflineController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductFamilyController;
use App\Http\Controllers\PurchaseOrderController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SalesAnalyticsController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockController;
use Illuminate\Support\Facades\Route;

Route::middleware('mobile.transport')->group(function (): void {
    Route::post('/auth/token', [MobileAuthController::class, 'store'])->middleware('throttle:mobile-login')->name('mobile.token');
    Route::middleware(['auth:sanctum', 'mobile.token', 'throttle:mobile-api', 'audit'])->group(function (): void {
        Route::get('/me', [MobileApiController::class, 'me'])->middleware('abilities:account:read');
        Route::get('/bootstrap', [MobileApiController::class, 'bootstrap'])->middleware('abilities:account:read,catalog:read');
        Route::get('/devices', [MobileAuthController::class, 'index'])->middleware('abilities:tokens:manage');
        Route::delete('/devices/{token}', [MobileAuthController::class, 'destroy'])->whereNumber('token')->middleware('abilities:tokens:manage');
        Route::delete('/auth/token', [MobileAuthController::class, 'destroy']);
        Route::get('/products', [MobileApiController::class, 'products'])->middleware('abilities:catalog:read');
        Route::get('/products/{product}', [MobileApiController::class, 'product'])->whereNumber('product')->middleware('abilities:catalog:read');
        Route::get('/settings/receipt-logo', [SettingsController::class, 'logo'])->middleware('abilities:catalog:read')->name('mobile.receipt-logo');
        Route::get('/customers', [MobileApiController::class, 'customers'])->middleware('abilities:customers:read');
        Route::post('/customers', [CustomerController::class, 'store'])->middleware(['abilities:customers:write', 'subscribed']);
        Route::get('/sales', [SaleController::class, 'index'])->middleware('abilities:sales:read');
        Route::get('/sales/{sale}', [SaleController::class, 'show'])->whereNumber('sale')->middleware('abilities:sales:read');
        Route::post('/sales', [SaleController::class, 'store'])->middleware(['abilities:sales:write', 'subscribed', 'mobile.idempotency']);
        Route::get('/offline/snapshot', [OfflineController::class, 'snapshot'])->middleware('abilities:catalog:read')->name('mobile.offline.snapshot');
        Route::post('/offline/sales', [OfflineController::class, 'store'])->middleware(['abilities:sales:write', 'mobile.idempotency'])->name('mobile.offline.sales');
        Route::get('/cash-sessions', [CashSessionController::class, 'index'])->middleware('abilities:cash:read');
        Route::post('/cash-sessions/open', [CashSessionController::class, 'open'])->middleware(['abilities:cash:write', 'subscribed', 'mobile.idempotency']);
        Route::post('/cash-sessions/{session}/adjustments', [CashSessionController::class, 'adjust'])->whereNumber('session')->middleware(['abilities:cash:write', 'mobile.idempotency']);
        Route::post('/cash-sessions/{session}/close', [CashSessionController::class, 'close'])->whereNumber('session')->middleware(['abilities:cash:write', 'mobile.idempotency']);
        Route::get('/reports/analytics', [SalesAnalyticsController::class, 'show'])->middleware('abilities:reports:read');

        Route::middleware('role:admin,manager')->group(function (): void {
            Route::post('/sales/{sale}/return', [SaleController::class, 'returnSale'])->whereNumber('sale')->middleware(['abilities:returns:write', 'mobile.idempotency']);
            Route::get('/suppliers', [MobileApiController::class, 'suppliers'])->middleware('abilities:suppliers:read');
            Route::get('/purchase-orders', [MobileApiController::class, 'purchases'])->middleware('abilities:purchases:read');
            Route::get('/purchase-orders/{order}', [MobileApiController::class, 'purchase'])->whereNumber('order')->middleware('abilities:purchases:read');
            Route::post('/purchase-orders/{order}/cancel', [PurchaseOrderController::class, 'cancel'])->whereNumber('order')->middleware('abilities:purchases:write');
            Route::get('/reports/financial', [FinancialReportController::class, 'show'])->middleware('abilities:financial:read');
            Route::middleware('subscribed')->group(function (): void {
                Route::post('/products', [ProductController::class, 'store'])->middleware('abilities:catalog:write');
                Route::put('/products/{product}', [ProductController::class, 'update'])->whereNumber('product')->middleware('abilities:catalog:write');
                Route::post('/product-families', [ProductFamilyController::class, 'store'])->middleware('abilities:catalog:write');
                Route::post('/product-families/{family}/variants', [ProductFamilyController::class, 'variants'])->whereNumber('family')->middleware('abilities:catalog:write');
                Route::post('/stock/adjust', [StockController::class, 'adjust'])->middleware(['abilities:inventory:write', 'mobile.idempotency']);
                Route::post('/stock/transfer', [StockController::class, 'transfer'])->middleware(['abilities:inventory:write', 'mobile.idempotency']);
                Route::post('/purchase-orders', [PurchaseOrderController::class, 'store'])->middleware(['abilities:purchases:write', 'mobile.idempotency']);
                Route::post('/purchase-orders/{order}/receive', [PurchaseOrderController::class, 'receive'])->whereNumber('order')->middleware(['abilities:purchases:write', 'mobile.idempotency']);
                Route::post('/purchase-orders/{order}/approve', [PurchaseOrderController::class, 'approve'])->whereNumber('order')->middleware('abilities:purchases:write');
                Route::post('/suppliers', [CustomerController::class, 'supplier'])->middleware('abilities:suppliers:write');
            });
        });
    });
});
