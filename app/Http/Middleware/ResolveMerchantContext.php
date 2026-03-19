<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Streeboga\PaymentData\Models\MerchantAccount;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ResolveMerchantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $merchantKey = $request->header('X-Merchant-Key');

        if (! $merchantKey) {
            throw new NotFoundHttpException('X-Merchant-Key header is required');
        }

        $merchant = MerchantAccount::where('key', $merchantKey)->first();

        if (! $merchant) {
            throw new NotFoundHttpException('Merchant not found');
        }

        $request->attributes->set('merchant_id', $merchant->id);
        $request->attributes->set('merchant_key', $merchant->key);

        $user = $request->user();

        if ($user && ! $user->hasAccessToMerchant($merchant->id)) {
            abort(403, 'You do not have access to this merchant');
        }

        // Set the user's role for this merchant on the request for RBAC checks
        $request->attributes->set('merchant_role', $user?->roleForMerchant($merchant->id));

        return $next($request);
    }
}
