<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Services\RobokassaReturnService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class RobokassaReturnController
{
    public function __construct(private readonly RobokassaReturnService $service) {}

    /** Buyer's browser back from Robokassa (SuccessURL / FailURL; GET or POST). */
    public function __invoke(Request $request, string $merchantKey, string $mcaKey): RedirectResponse|Response
    {
        $found = $this->service->resolve($merchantKey, $mcaKey, $request->all());
        if ($found === null) {
            return response('Payment not found', 404);
        }
        if ($found['return_url'] !== null) {
            return redirect()->away($found['return_url']);
        }

        return response('<!doctype html><meta charset="utf-8"><title>Payment</title><p>'
            .e($found['payment_id']).': '.e($found['status']).'</p>', 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
