<?php

use App\Http\Controllers\ApproveSellerNotesQuoteController;
use App\Http\Controllers\DownloadSellerNotesTemplateController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\StoreSellerQuoteNotesController;
use App\Http\Middleware\AuthorizeSellerNotesUpload;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class)->name('home');
Route::get('/seller-notes/template.txt', DownloadSellerNotesTemplateController::class)
    ->name('seller-notes.template');

Route::middleware(AuthorizeSellerNotesUpload::class)->group(function (): void {
    Route::post('/seller-notes', StoreSellerQuoteNotesController::class)
        ->name('seller-notes.store');
    Route::post('/seller-notes/quotes/{quote}/approve', ApproveSellerNotesQuoteController::class)
        ->name('seller-notes.quotes.approve');
});
