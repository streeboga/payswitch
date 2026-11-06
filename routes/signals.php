<?php

declare(strict_types=1);

use App\Http\Controllers\SignalsController;
use Illuminate\Support\Facades\Route;

Route::get('/internal/signals', SignalsController::class);
