<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Exceptions\ApiAuthenticationException;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Symfony\Component\HttpFoundation\Response;

final class ResolveApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('api-key');

        if (! $apiKey || ! is_string($apiKey)) {
            throw new ApiAuthenticationException('API key is required', 'api_key_missing', 'authentication_error');
        }

        // Check admin key before length validation (admin keys may have any length)
        $adminKey = config('payswitch.admin_api_key');
        if ($adminKey && is_string($adminKey) && hash_equals($adminKey, $apiKey)) {
            $request->attributes->set('api_key_type', 'admin');

            return $next($request);
        }

        if (strlen($apiKey) < 10) {
            throw new ApiAuthenticationException('Invalid API key format', 'invalid_api_key', 'authentication_error');
        }

        $keyPrefix = substr($apiKey, 0, 20);

        $apiKeyModel = ApiKey::where('key_prefix', $keyPrefix)->first();

        if ($apiKeyModel) {
            if ($apiKeyModel->revoked_at !== null) {
                throw new ApiAuthenticationException('API key has been revoked', 'api_key_revoked', 'authentication_error');
            }

            if ($apiKeyModel->expires_at !== null && $apiKeyModel->expires_at->isPast()) {
                throw new ApiAuthenticationException('API key has expired', 'api_key_expired', 'authentication_error');
            }

            if (! hash_equals($apiKeyModel->key_hash, hash('sha256', $apiKey))) {
                throw new ApiAuthenticationException('Invalid API key', 'invalid_api_key', 'authentication_error');
            }

            $request->attributes->set('api_key_type', 'secret');
            $request->attributes->set('merchant_id', $apiKeyModel->merchant_account_id);
            $request->attributes->set('api_key', $apiKeyModel);

            return $next($request);
        }

        $merchantAccount = MerchantAccount::where('publishable_key', $apiKey)->first();

        if ($merchantAccount) {
            $request->attributes->set('api_key_type', 'publishable');
            $request->attributes->set('merchant_id', $merchantAccount->id);

            return $next($request);
        }

        throw new ApiAuthenticationException('Invalid API key', 'invalid_api_key', 'authentication_error');
    }
}
