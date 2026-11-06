<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Observability\FallbackSignals;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class SignalsController
{
    public function __invoke(Request $request, FallbackSignals $signals): Response
    {
        $token = (string) config('observability.metrics_token');
        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        return new Response($signals->render(), 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }
}
