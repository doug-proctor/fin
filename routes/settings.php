<?php

use App\Http\Controllers\Monzo\MonzoConnectionController;
use App\Http\Controllers\Monzo\MonzoSyncController;
use App\Http\Controllers\Settings\ConnectionController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\SecurityController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {
    Route::redirect('settings', '/settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
});

Route::middleware(['auth', 'verified'])->group(function () {
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/security', [SecurityController::class, 'edit'])
        ->name('security.edit');

    Route::put('settings/password', [SecurityController::class, 'update'])
        ->middleware('throttle:6,1')
        ->name('user-password.update');

    Route::inertia('settings/appearance', 'settings/appearance')->name('appearance.edit');

    Route::get('settings/connections', [ConnectionController::class, 'edit'])->name('connections.edit');

    Route::get('settings/connections/monzo', [MonzoConnectionController::class, 'create'])->name('monzo.connect');
    Route::get('settings/connections/monzo/callback', [MonzoConnectionController::class, 'store'])->name('monzo.callback');
    Route::post('settings/connections/monzo/retry', [MonzoConnectionController::class, 'update'])->name('monzo.retry');
    Route::delete('settings/connections/monzo', [MonzoConnectionController::class, 'destroy'])->name('monzo.disconnect');
    Route::post('settings/connections/monzo/sync', [MonzoSyncController::class, 'store'])->name('monzo.sync');
});

Route::get('.well-known/passkey-endpoints', function () {
    return response()->json([
        'enroll' => route('security.edit'),
        'manage' => route('security.edit'),
    ]);
})->name('well-known.passkeys');
