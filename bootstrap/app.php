<?php

use App\Http\Middleware\AuthenticateAdminApiKey;
use App\Http\Middleware\AuthenticateSecretApiKey;
use App\Http\Middleware\ForceJsonApiContentType;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveApiKey;
use App\Providers\RepositoryServiceProvider;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Validation\ValidationException;
use Streeboga\PaymentData\Exceptions\ApiAuthenticationException;
use Streeboga\PaymentData\Exceptions\ConnectorException;
use Streeboga\PaymentData\Exceptions\PaymentException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([
        RepositoryServiceProvider::class,
    ])
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'json-api' => ForceJsonApiContentType::class,
            'auth.api_key' => ResolveApiKey::class,
            'auth.admin_api_key' => AuthenticateAdminApiKey::class,
            'auth.secret_api_key' => AuthenticateSecretApiKey::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->renderable(function (ApiAuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'errors' => [[
                        'status' => '401',
                        'code' => $e->errorCode,
                        'title' => 'Authentication Error',
                        'detail' => $e->getMessage(),
                    ]],
                ], 401)->header('Content-Type', 'application/vnd.api+json');
            }
        });

        $exceptions->renderable(function (PaymentException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'errors' => [[
                        'status' => (string) $e->httpStatus,
                        'code' => $e->errorCode,
                        'title' => 'Payment Error',
                        'detail' => $e->getMessage(),
                    ]],
                ], $e->httpStatus)->header('Content-Type', 'application/vnd.api+json');
            }
        });

        $exceptions->renderable(function (ConnectorException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'errors' => [[
                        'status' => '502',
                        'code' => 'connector_error',
                        'title' => 'Connector Error',
                        'detail' => $e->getMessage(),
                    ]],
                ], 502)->header('Content-Type', 'application/vnd.api+json');
            }
        });

        $exceptions->renderable(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'errors' => [[
                        'status' => '404',
                        'code' => 'not_found',
                        'title' => 'Not Found',
                        'detail' => $e->getMessage() ?: 'The requested resource was not found.',
                    ]],
                ], 404)->header('Content-Type', 'application/vnd.api+json');
            }
        });

        $exceptions->renderable(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                $errors = [];
                foreach ($e->errors() as $field => $messages) {
                    // Convert dot-notation field to JSON pointer
                    $pointer = str_starts_with($field, 'data.attributes.')
                        ? '/'.str_replace('.', '/', $field)
                        : "/data/attributes/{$field}";

                    foreach ($messages as $message) {
                        $errors[] = [
                            'status' => '422',
                            'code' => 'validation_error',
                            'title' => 'Validation Error',
                            'detail' => $message,
                            'source' => [
                                'pointer' => $pointer,
                            ],
                        ];
                    }
                }

                return response()->json(['errors' => $errors], 422)
                    ->header('Content-Type', 'application/vnd.api+json');
            }
        });

        $exceptions->renderable(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'errors' => [[
                        'status' => '401',
                        'code' => 'unauthenticated',
                        'title' => 'Authentication Error',
                        'detail' => $e->getMessage() ?: 'Unauthenticated.',
                    ]],
                ], 401)->header('Content-Type', 'application/vnd.api+json');
            }
        });
    })->create();
