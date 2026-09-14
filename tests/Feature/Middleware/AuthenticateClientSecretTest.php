<?php

use App\Http\Middleware\AuthenticateClientSecret;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\MerchantAccount;
use Streeboga\PaymentData\Models\Organization;
use Streeboga\PaymentData\Models\PaymentIntent;

uses(RefreshDatabase::class);

beforeEach(function () {
    $org = Organization::create(['name' => 'Org']);
    $this->merchant = MerchantAccount::create([
        'org_id' => $org->id,
        'name' => 'Test Merchant',
    ]);
    $this->payment = PaymentIntent::create([
        'merchant_account_id' => $this->merchant->id,
        'amount' => 10000,
        'currency' => 'RUB',
        'status' => PaymentStatus::RequiresPaymentMethod,
        'session_expiry' => 900,
        'expires_on' => now()->addMinutes(15),
    ]);
});

test('passes with valid client_secret in body', function () {
    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST', [
        'client_secret' => $this->payment->client_secret,
    ]);
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class($this->payment->key)
    {
        public function __construct(private string $key) {}

        public function parameter(string $name)
        {
            return $this->key;
        }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
    expect($request->attributes->get('payment_intent')->id)->toBe($this->payment->id);
});

test('passes with valid client_secret in query param', function () {
    $request = Request::create(
        '/api/v1/payments/'.$this->payment->key.'?client_secret='.$this->payment->client_secret,
        'GET'
    );
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class($this->payment->key)
    {
        public function __construct(private string $key) {}

        public function parameter(string $name)
        {
            return $this->key;
        }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

test('rejects missing client_secret', function () {
    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST');
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class($this->payment->key)
    {
        public function __construct(private string $key) {}

        public function parameter(string $name)
        {
            return $this->key;
        }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors'][0]['detail'])->toContain('client_secret');
    expect($response->getData(true)['errors'][0]['code'])->toBe('client_secret_required');
});

test('rejects invalid client_secret', function () {
    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST', [
        'client_secret' => 'wrong_secret',
    ]);
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class($this->payment->key)
    {
        public function __construct(private string $key) {}

        public function parameter(string $name)
        {
            return $this->key;
        }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors'][0]['code'])->toBe('invalid_client_secret');
});

test('rejects expired payment session', function () {
    $this->payment->update(['expires_on' => now()->subMinute()]);

    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST', [
        'client_secret' => $this->payment->client_secret,
    ]);
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $this->merchant->id);
    $request->setRouteResolver(fn () => new class($this->payment->key)
    {
        public function __construct(private string $key) {}

        public function parameter(string $name)
        {
            return $this->key;
        }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors'][0]['detail'])->toContain('expired');
    expect($response->getData(true)['errors'][0]['code'])->toBe('session_expired');
});

test('rejects when publishable key merchant does not own payment', function () {
    $otherOrg = Organization::create(['name' => 'Other']);
    $otherMerchant = MerchantAccount::create(['org_id' => $otherOrg->id, 'name' => 'Other']);

    $request = Request::create('/api/v1/payments/'.$this->payment->key.'/confirm', 'POST', [
        'client_secret' => $this->payment->client_secret,
    ]);
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $otherMerchant->id);
    $request->setRouteResolver(fn () => new class($this->payment->key)
    {
        public function __construct(private string $key) {}

        public function parameter(string $name)
        {
            return $this->key;
        }
    });

    $middleware = new AuthenticateClientSecret;
    $response = $middleware->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors'][0]['code'])->toBe('invalid_client_secret');
});

// С8: client_secret в query оседает в access-логах — виджет шлёт его заголовком.

function clientSecretGetRequest(string $uri, PaymentIntent $payment, MerchantAccount $merchant): Request
{
    $request = Request::create($uri, 'GET');
    $request->attributes->set('api_key_type', 'publishable');
    $request->attributes->set('merchant_id', $merchant->id);
    $request->setRouteResolver(fn () => new class($payment->key)
    {
        public function __construct(private string $key) {}

        public function parameter(string $name)
        {
            return $this->key;
        }
    });

    return $request;
}

test('passes with valid client_secret in X-Client-Secret header', function () {
    $request = clientSecretGetRequest('/api/v1/payments/'.$this->payment->key, $this->payment, $this->merchant);
    $request->headers->set('X-Client-Secret', $this->payment->client_secret);

    $response = (new AuthenticateClientSecret)->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(200);
});

test('X-Client-Secret header takes priority over query', function () {
    $request = clientSecretGetRequest(
        '/api/v1/payments/'.$this->payment->key.'?client_secret='.$this->payment->client_secret,
        $this->payment,
        $this->merchant,
    );
    $request->headers->set('X-Client-Secret', 'wrong_secret');

    $response = (new AuthenticateClientSecret)->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
    expect($response->getData(true)['errors'][0]['code'])->toBe('invalid_client_secret');
});

test('CORS preflight allows X-Client-Secret header', function () {
    $response = $this->call('OPTIONS', '/api/v1/payments/'.$this->payment->key, server: [
        'HTTP_ORIGIN' => 'http://localhost:3000',
        'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'GET',
        'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'api-key,x-client-secret',
    ]);

    expect(strtolower((string) $response->headers->get('Access-Control-Allow-Headers')))->toContain('x-client-secret');
});

test('non-string client_secret is rejected without error', function () {
    $request = clientSecretGetRequest('/api/v1/payments/'.$this->payment->key.'?client_secret[]=x', $this->payment, $this->merchant);

    $response = (new AuthenticateClientSecret)->handle($request, fn ($req) => new JsonResponse(['ok' => true]));

    expect($response->getStatusCode())->toBe(403);
});
