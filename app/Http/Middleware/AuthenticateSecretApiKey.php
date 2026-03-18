<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateSecretApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->attributes->get('api_key_type') !== 'secret') {
            return response()->json([
                'errors' => [[
                    'status' => '403',
                    'code' => 'secret_key_required',
                    'title' => 'Authorization Error',
                    'detail' => 'Secret API key required for this operation',
                ]],
            ], 403)->header('Content-Type', 'application/vnd.api+json');
        }

        return $next($request);
    }
}
