<?php

use App\Http\Controllers\SocialAuthController;
use App\Http\Middleware\ProtectSocialAuthResponses;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::middleware(ProtectSocialAuthResponses::class)->group(function (): void {
    Route::get('/auth/{provider}/redirect', [SocialAuthController::class, 'redirect'])
        ->whereIn('provider', ['google', 'apple'])->middleware('throttle:12,1,social-start:')->block(40, 10)->name('auth.social.redirect');
    Route::get('/auth/google/callback', [SocialAuthController::class, 'googleCallback'])
        ->middleware('throttle:20,1,social-google-callback:')->block(40, 10)->name('auth.social.google.callback');
    Route::post('/auth/apple/callback', [SocialAuthController::class, 'appleCallback'])
        ->withoutMiddleware([StartSession::class, ShareErrorsFromSession::class, PreventRequestForgery::class])
        ->middleware('throttle:20,1,social-apple-callback:')->name('auth.social.apple.callback');
    Route::get('/auth/apple/complete', [SocialAuthController::class, 'appleComplete'])
        ->middleware('throttle:20,1,social-apple-complete:')->block(40, 10)->name('auth.social.apple.complete');
    Route::middleware('guest:web')->group(function (): void {
        Route::get('/auth/register/complete', [SocialAuthController::class, 'registerForm'])->name('auth.social.register.form');
        Route::post('/auth/register/complete', [SocialAuthController::class, 'register'])
            ->middleware('throttle:6,1,social-register:')->block(40, 10)->name('auth.social.register.complete');
    });
    Route::middleware(['auth:web', 'auth.session', 'tenant'])->group(function (): void {
        Route::get('/account/connections', [SocialAuthController::class, 'connections'])->name('auth.social.connections');
        Route::post('/account/connections/{provider}', [SocialAuthController::class, 'connect'])
            ->whereIn('provider', ['google', 'apple'])->middleware('throttle:6,1,social-connect:')->block(40, 10)->name('auth.social.connect');
    });
});
