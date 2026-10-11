<?php

use App\Http\Controllers\DeveloperAuthController;
use App\Http\Controllers\DeveloperController;
use App\Http\Controllers\SiteContentController;
use Illuminate\Support\Facades\Route;

Route::get('/developer/login', [DeveloperAuthController::class, 'loginForm'])->name('developer.login');
Route::post('/developer/login', [DeveloperAuthController::class, 'login'])->middleware('throttle:6,1,developer-login');
Route::get('/developer/setup/{token}', [DeveloperAuthController::class, 'setupForm'])->middleware('throttle:12,1,developer-setup-view')->name('developer.setup');
Route::post('/developer/setup', [DeveloperAuthController::class, 'setup'])->middleware('throttle:6,1,developer-setup')->name('developer.setup.store');

Route::middleware(['auth:developer', 'developer', 'auth.session'])->group(function (): void {
    Route::post('/developer/logout', [DeveloperAuthController::class, 'logout'])->name('developer.logout');
    Route::get('/developer/content/preview', [SiteContentController::class, 'preview'])->name('developer.content.preview');
    Route::get('/developer/{section?}', [DeveloperController::class, 'show'])->name('developer');
    Route::prefix('api/developer')->middleware('throttle:120,1,developer-api')->group(function (): void {
        Route::get('/bootstrap', [DeveloperController::class, 'bootstrap']);
        Route::get('/companies/{tenant}', [DeveloperController::class, 'company'])->whereNumber('tenant');
        Route::post('/companies/{tenant}/{action}', [DeveloperController::class, 'mutate'])
            ->whereNumber('tenant')->whereIn('action', ['pause', 'resume', 'plan', 'cancel-renewal', 'resume-renewal', 'invoice'])
            ->middleware('throttle:30,1,developer-mutation');
        Route::get('/content', [SiteContentController::class, 'show']);
        Route::put('/content', [SiteContentController::class, 'update'])->middleware('throttle:30,1,developer-mutation');
        Route::post('/content/publish', [SiteContentController::class, 'publish'])->middleware('throttle:12,1,developer-publish');
    });
});
