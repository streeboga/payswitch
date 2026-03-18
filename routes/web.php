<?php

use App\Http\Controllers\Dashboard\MerchantsController;
use App\Http\Controllers\Dashboard\OverviewController;
use App\Http\Controllers\Dashboard\PaymentsController;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Features;

Route::inertia('/', 'welcome', [
    'canRegister' => Features::enabled(Features::registration()),
])->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::get('/dashboard', OverviewController::class)->name('dashboard');
    Route::get('/dashboard/payments', [PaymentsController::class, 'index'])->name('dashboard.payments');
    Route::get('/dashboard/payments/{paymentKey}', [PaymentsController::class, 'show'])->name('dashboard.payments.show');
    Route::get('/dashboard/merchants', [MerchantsController::class, 'index'])->name('dashboard.merchants');
});

require __DIR__.'/settings.php';
