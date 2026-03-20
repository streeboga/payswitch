<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use App\Http\Controllers\TestPspController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [LoginController::class, 'store'])
    ->middleware('throttle:login');

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth');

Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware('throttle:two-factor');

// Test PSP simulator — approve/decline payments for test connector
Route::get('/test-psp/{paymentKey}', [TestPspController::class, 'show']);
Route::post('/test-psp/{paymentKey}/complete', [TestPspController::class, 'complete']);
