<?php

use App\Http\Controllers\CategoryController;
use App\Http\Controllers\CategoryRuleController;
use App\Http\Controllers\CategoryTargetController;
use App\Http\Controllers\SyncReportController;
use App\Http\Controllers\TransactionController;
use App\Http\Controllers\TransactionImportController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('transactions', [TransactionController::class, 'index'])->name('transactions.index');

    Route::get('categories', [CategoryController::class, 'index'])->name('categories.index');
    Route::post('categories', [CategoryController::class, 'store'])->name('categories.store');
    Route::patch('categories/{category}', [CategoryController::class, 'update'])->name('categories.update');

    Route::post('transactions/targets', [CategoryTargetController::class, 'store'])->name('category-targets.store');

    Route::get('transactions/sync-reports', [SyncReportController::class, 'index'])->name('sync-reports.index');

    Route::get('rules', [CategoryRuleController::class, 'index'])->name('category-rules.index');
    Route::post('rules', [CategoryRuleController::class, 'store'])->name('category-rules.store');
    Route::post('rules/apply', [CategoryRuleController::class, 'apply'])->name('category-rules.apply');
    Route::post('rules/{categoryRule}/apply', [CategoryRuleController::class, 'applyOne'])->name('category-rules.apply-one');
    Route::patch('rules/{categoryRule}', [CategoryRuleController::class, 'update'])->name('category-rules.update');
    Route::delete('rules/{categoryRule}', [CategoryRuleController::class, 'destroy'])->name('category-rules.destroy');

    Route::post('transactions/import', [TransactionImportController::class, 'store'])->name('transactions.import.store');

    Route::post('transactions/mark-processed', [TransactionController::class, 'markProcessed'])->name('transactions.mark-processed');

    Route::patch('transactions/{transaction}', [TransactionController::class, 'update'])->name('transactions.update');
});
