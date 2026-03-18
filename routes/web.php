<?php

use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\TwoFactorChallengeController;
use Illuminate\Support\Facades\Route;

Route::post('/login', [LoginController::class, 'store'])
    ->middleware('throttle:login');

Route::post('/logout', [LoginController::class, 'destroy'])
    ->middleware('auth');

Route::post('/two-factor-challenge', [TwoFactorChallengeController::class, 'store'])
    ->middleware('throttle:two-factor');
