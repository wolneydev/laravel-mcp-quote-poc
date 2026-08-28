<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\McpClientTokenController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteController;
use App\Http\Controllers\Api\QuoteDraftPdfController;
use App\Mcp\Middleware\AuthenticateMcpTokenAdministration;
use Illuminate\Support\Facades\Route;

Route::apiResource('products', ProductController::class)->only(['index', 'show']);
Route::apiResource('customers', CustomerController::class)->only(['index', 'show']);
Route::apiResource('accounts', AccountController::class)->only(['index', 'show']);

Route::middleware(AuthenticateMcpTokenAdministration::class)->group(function (): void {
    Route::apiResource('mcp-client-tokens', McpClientTokenController::class);
});

Route::get('quotes/{quote}/draft-pdf', QuoteDraftPdfController::class)
    ->middleware('signed')
    ->name('quotes.draft-pdf');

Route::middleware('auth')->group(function (): void {
    Route::post('quotes/{quote}/submit', [QuoteController::class, 'submit'])->name('quotes.submit');
    Route::post('quotes/{quote}/approve', [QuoteController::class, 'approve'])->name('quotes.approve');
    Route::post('quotes/{quote}/reject', [QuoteController::class, 'reject'])->name('quotes.reject');
    Route::apiResource('quotes', QuoteController::class);
});
