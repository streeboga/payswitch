<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('json-api')->group(function () {
    Route::get('/health', function () {
        try {
            DB::connection()->getPdo();
            $dbStatus = 'connected';
        } catch (\Throwable) {
            $dbStatus = 'disconnected';
        }

        return response()->json([
            'status' => $dbStatus === 'connected' ? 'healthy' : 'unhealthy',
            'timestamp' => now()->toIso8601String(),
            'database' => $dbStatus,
        ], $dbStatus === 'connected' ? 200 : 503);
    });
});
