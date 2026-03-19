<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\DataTransferObjects\Payment\ConfirmPaymentData;
use App\Http\Resources\PaymentIntentResource;
use App\Http\Resources\PublicPaymentIntentResource;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Streeboga\PaymentData\Models\MerchantConnectorAccount;

final class PublicPaymentController extends Controller
{
    public function __construct(
        private readonly PaymentService $paymentService,
    ) {}

    public function show(string $paymentKey, Request $request): JsonResponse
    {
        if ($request->attributes->get('api_key_type') === 'secret') {
            $merchantAccountId = $request->attributes->get('merchant_id');
            $payment = $this->paymentService->find($paymentKey, $merchantAccountId);

            return (new PaymentIntentResource($payment))->toResponse($request);
        }

        $payment = $request->attributes->get('payment_intent');

        return (new PublicPaymentIntentResource($payment))->toResponse($request);
    }

    public function confirm(string $paymentKey, Request $request): JsonResponse
    {
        $request->validate([
            'payment_method' => ['required', 'string'],
            'payment_method_data' => ['sometimes', 'array'],
            'connector' => ['sometimes', 'string'],
        ]);

        $dto = ConfirmPaymentData::from($request->only([
            'payment_method',
            'payment_method_data',
            'connector',
        ]));

        $merchantAccountId = $request->attributes->get('merchant_id');
        $payment = $this->paymentService->confirm($paymentKey, $dto, $merchantAccountId);

        if ($request->attributes->get('api_key_type') === 'secret') {
            return (new PaymentIntentResource($payment))->toResponse($request);
        }

        return (new PublicPaymentIntentResource($payment))->toResponse($request);
    }

    public function paymentMethods(string $paymentKey, Request $request): JsonResponse
    {
        $payment = $request->attributes->get('payment_intent')
            ?? $this->paymentService->find($paymentKey, $request->attributes->get('merchant_id'));

        $connectors = MerchantConnectorAccount::query()
            ->where('business_profile_id', $payment->business_profile_id)
            ->where('disabled', false)
            ->whereNotNull('payment_methods_enabled')
            ->get();

        $methods = $connectors
            ->flatMap(fn ($mca) => $mca->payment_methods_enabled ?? [])
            ->unique('payment_method')
            ->values();

        return response()->json(['data' => $methods]);
    }
}
