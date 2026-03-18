<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Streeboga\PaymentData\Exceptions\ApiAuthenticationException;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Symfony\Component\HttpFoundation\Response;

class ResolveApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $apiKey = $request->header('api-key');

        if (! $apiKey) {
            throw new ApiAuthenticationException('API key is required', 'api_key_missing', 'authentication_error');
        }

        $adminKey = config('payswitch.admin_api_key');
        if ($adminKey && hash_equals($adminKey, $apiKey)) {
            $request->attributes->set('api_key_type', 'admin');

            return $next($request);
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

            if (! Hash::check($apiKey, $apiKeyModel->key_hash)) {
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
