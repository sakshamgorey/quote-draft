<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\DraftController;
use App\Http\Controllers\DraftLineController;
use App\Http\Controllers\PriceRuleController;
use App\Http\Controllers\ProductSearchController;
use Illuminate\Support\Facades\Route;

Route::get('/', [DraftController::class, 'create'])->name('drafts.create');

// One LLM call per request: keep it cheap to abuse.
Route::post('/drafts', [DraftController::class, 'store'])
    ->middleware('throttle:20,1')
    ->name('drafts.store');

Route::get('/drafts', [DraftController::class, 'index'])->name('drafts.index');
Route::get('/drafts/{draft}', [DraftController::class, 'show'])->name('drafts.show');
Route::patch('/drafts/{draft}', [DraftController::class, 'update'])->name('drafts.update');
Route::post('/drafts/{draft}/finalize', [DraftController::class, 'finalize'])->name('drafts.finalize');
Route::post('/drafts/{draft}/zoho', [DraftController::class, 'sendToZoho'])->name('drafts.zoho');

// Optional second LLM call (DRAFT_AI_SUGGESTIONS): suggestions only, never prices.
Route::post('/drafts/{draft}/suggestions', [DraftController::class, 'suggest'])
    ->middleware('throttle:20,1')
    ->name('drafts.suggest');

// Review edits: Postgres only, no LLM.
Route::scopeBindings()->group(function () {
    Route::post('/drafts/{draft}/lines', [DraftLineController::class, 'store'])->name('drafts.lines.store');
    Route::patch('/drafts/{draft}/lines/{line}', [DraftLineController::class, 'update'])->name('drafts.lines.update');
    Route::delete('/drafts/{draft}/lines/{line}', [DraftLineController::class, 'destroy'])->name('drafts.lines.destroy');
});

Route::get('/accounts/lookup', [AccountController::class, 'lookup'])->middleware('throttle:120,1')->name('accounts.lookup');
Route::resource('accounts', AccountController::class)->except('show');

Route::get('/pricing', [PriceRuleController::class, 'index'])->name('pricing.index');
Route::post('/pricing', [PriceRuleController::class, 'store'])->name('pricing.store');
Route::patch('/pricing/{rule}', [PriceRuleController::class, 'update'])->name('pricing.update');
Route::delete('/pricing/{rule}', [PriceRuleController::class, 'destroy'])->name('pricing.destroy');

Route::get('/products', [ProductSearchController::class, 'index'])->name('products.index');
Route::get('/products/lookup', [ProductSearchController::class, 'lookup'])
    ->middleware('throttle:120,1')
    ->name('products.lookup');
