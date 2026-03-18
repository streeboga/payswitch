<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\MerchantAccount;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolveMerchantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $merchantKey = $request->header('X-Merchant-Key');

        if (! $merchantKey || ! is_string($merchantKey)) {
            throw new NotFoundHttpException('X-Merchant-Key header is required');
        }

        $merchant = MerchantAccount::where('key', $merchantKey)->first();

        if (! $merchant) {
            throw new NotFoundHttpException('Merchant not found');
        }

        $request->attributes->set('merchant_id', $merchant->id);

        return $next($request);
    }
}
