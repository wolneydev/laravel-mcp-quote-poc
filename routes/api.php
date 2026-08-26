<?php

use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\CustomerController;
use App\Http\Controllers\Api\ProductController;
use App\Http\Controllers\Api\QuoteController;
use Illuminate\Support\Facades\Route;

Route::apiResource('products', ProductController::class);
Route::apiResource('customers', CustomerController::class);
Route::apiResource('accounts', AccountController::class);

Route::middleware('auth')->group(function (): void {
    Route::post('quotes/{quote}/submit', [QuoteController::class, 'submit'])->name('quotes.submit');
    Route::post('quotes/{quote}/approve', [QuoteController::class, 'approve'])->name('quotes.approve');
    Route::post('quotes/{quote}/reject', [QuoteController::class, 'reject'])->name('quotes.reject');
    Route::apiResource('quotes', QuoteController::class);
});
