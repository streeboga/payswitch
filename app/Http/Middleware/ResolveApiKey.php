<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\ApiKeyType;
use Closure;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Streeboga\PaymentData\Exceptions\ApiAuthenticationException;
use Streeboga\PaymentData\Models\ApiKey;
use Streeboga\PaymentData\Models\MerchantAccount;
use Symfony\Component\HttpFoundation\Response;

final class ResolveApiKey
{
    public function handle(Request $request, Closure $next): Response
    {
        // Лимит payswitch-api считается после аутентификации и неверные ключи
        // не видит. Неудачные попытки считаются здесь, по IP — но режут только
        // неудачи. Genesis, invoicing и apihub ходят с одного адреса: один
        // проект с отозванным ключом не должен отнимать API у остальных.
        $failuresKey = 'payswitch-api-auth-failures:'.$request->ip();
        $maxFailures = (int) config('payswitch.rate_limit.unauthenticated', 30);

        try {
            $this->resolve($request);
        } catch (ApiAuthenticationException $e) {
            if (RateLimiter::tooManyAttempts($failuresKey, $maxFailures)) {
                throw new ThrottleRequestsException('Too Many Attempts.', null, [
                    'Retry-After' => RateLimiter::availableIn($failuresKey),
                ]);
            }

            RateLimiter::hit($failuresKey);

            throw $e;
        }

        return $next($request);
    }

    /**
     * На проде admin-ключ был дословно тестовым значением из .env.example.
     * Слабый или известный ключ в production не открывает admin API вовсе —
     * лучше отказ, чем открытая дверь.
     */
    private function adminKeyAcceptable(string $adminKey): bool
    {
        if (! app()->environment('production')) {
            return true;
        }

        return strlen($adminKey) >= 32 && $adminKey !== 'admin_test_key_for_development';
    }

    private function resolve(Request $request): void
    {
        $apiKey = $request->header('api-key');

        if (! $apiKey) {
            throw new ApiAuthenticationException('API key is required', 'api_key_missing', 'authentication_error');
        }

        // Check admin key before length validation (admin keys may have any length)
        $adminKey = config('payswitch.admin_api_key');
        if ($adminKey && is_string($adminKey) && $this->adminKeyAcceptable($adminKey) && hash_equals($adminKey, $apiKey)) {
            $request->attributes->set('api_key_type', 'admin');

            return;
        }

        if (strlen($apiKey) < 10) {
            throw new ApiAuthenticationException('Invalid API key format', 'invalid_api_key', 'authentication_error');
        }

        $keyPrefix = substr($apiKey, 0, 20);

        // Префикс не уникален: у ULID-ключей одной миллисекунды первые 20
        // символов совпадают, и first() брал чужую запись — вечный 401.
        // Отзыв и срок проверяются только у записи с совпавшим хэшем, иначе по
        // одному префиксу (панель его показывает) видно, отозван ли ключ.
        $keyHash = hash('sha256', $apiKey);
        $apiKeyModel = ApiKey::where('key_prefix', $keyPrefix)
            ->get()
            ->first(fn (ApiKey $candidate) => hash_equals($candidate->key_hash, $keyHash));

        if ($apiKeyModel) {
            if ($apiKeyModel->revoked_at !== null) {
                throw new ApiAuthenticationException('API key has been revoked', 'api_key_revoked', 'authentication_error');
            }

            if ($apiKeyModel->expires_at !== null && $apiKeyModel->expires_at->isPast()) {
                throw new ApiAuthenticationException('API key has expired', 'api_key_expired', 'authentication_error');
            }

            // Тип — из самой записи. admin из таблицы глобального admin API не
            // даёт никогда (он только у ключа из env) и работает как secret
            // мерчанта — так такие ключи и вели себя до сих пор.
            $request->attributes->set('api_key_type', $apiKeyModel->type === ApiKeyType::Publishable ? 'publishable' : 'secret');
            $request->attributes->set('merchant_id', $apiKeyModel->merchant_account_id);
            $request->attributes->set('api_key', $apiKeyModel);

            return;
        }

        $merchantAccount = MerchantAccount::where('publishable_key', $apiKey)->first();

        if ($merchantAccount) {
            $request->attributes->set('api_key_type', 'publishable');
            $request->attributes->set('merchant_id', $merchantAccount->id);

            return;
        }

        throw new ApiAuthenticationException('Invalid API key', 'invalid_api_key', 'authentication_error');
    }
}
