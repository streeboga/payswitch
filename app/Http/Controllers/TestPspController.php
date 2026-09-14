<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Events\PaymentStatusChanged;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentConnectors\Drivers\TestConnector;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Models\PaymentAttempt;
use Streeboga\PaymentData\Models\PaymentIntent;
use Streeboga\PaymentData\StateMachine\PaymentStateMachine;

/**
 * JSON API for the Test PSP simulator.
 * The React SPA at /test-psp/{paymentKey} consumes these endpoints.
 *
 * Маршрут открыт без авторизации, а ключ платежа знает плательщик. Поэтому
 * симулятор видит только платежи, которые проводит тестовый коннектор: иначе
 * одним POST боевой платёж становился `succeeded`, и мерчанту уходил
 * подписанный `payment_succeeded` на деньги, которых нет.
 */
final class TestPspController extends Controller
{
    public function show(string $paymentKey): JsonResponse
    {
        $payment = $this->findTestPayment($paymentKey);

        return response()->json([
            'data' => [
                'type' => 'test_psp_payment',
                'id' => $payment->key,
                'attributes' => [
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status->value,
                    'description' => $payment->description,
                    'return_url' => $payment->return_url,
                ],
            ],
        ]);
    }

    public function complete(string $paymentKey, Request $request): JsonResponse
    {
        $request->validate([
            'action' => ['required', 'in:approve,decline'],
        ]);

        $action = $request->input('action');
        $approve = $action === 'approve';
        $target = $approve ? PaymentStatus::Succeeded : PaymentStatus::Failed;

        $found = $this->findTestPayment($paymentKey);

        $result = DB::transaction(function () use ($found, $approve, $target) {
            $payment = PaymentIntent::whereKey($found->id)->lockForUpdate()->firstOrFail();

            // Без проверки перехода повторный approve (или approve после decline)
            // давал второй `payment_succeeded` с новым event_id.
            if (! PaymentStateMachine::canTransition($payment->status, $target)) {
                return null;
            }

            $previousStatus = $payment->status->value;

            $payment->update($approve
                ? ['status' => $target, 'amount_received' => $payment->amount, 'amount_capturable' => 0]
                : ['status' => $target, 'error_code' => 'declined', 'error_message' => 'Payment declined by customer']);

            PaymentAttempt::where('payment_intent_id', $payment->id)
                ->where('status', 'requires_action')
                ->update($approve
                    ? ['status' => 'succeeded']
                    : ['status' => 'failed', 'error_code' => 'declined', 'error_message' => 'Payment declined by customer']);

            return [$payment, $previousStatus];
        });

        if ($result === null) {
            abort(409, 'Payment cannot be completed from its current status');
        }

        [$payment, $previousStatus] = $result;

        // Без этого события тестовый платёж проходил молча: ни вебхука
        // мерчанту, ни пополнения кошелька — то есть проверить сквозной путь
        // тестовым коннектором было нельзя.
        event(new PaymentStatusChanged($payment, $previousStatus));

        $returnUrl = $payment->return_url;
        if ($returnUrl) {
            $separator = str_contains($returnUrl, '?') ? '&' : '?';
            $returnUrl .= $separator.'payment_id='.$payment->key.'&status='.($approve ? 'success' : 'failed');
        }

        $dashboardUrl = rtrim((string) config('app.frontend_url', 'http://localhost:3000'), '/');

        return response()->json([
            'data' => [
                'type' => 'test_psp_payment',
                'id' => $payment->key,
                'attributes' => [
                    'amount' => $payment->amount,
                    'currency' => $payment->currency,
                    'status' => $payment->status->value,
                    'success' => $approve,
                    'return_url' => $returnUrl,
                    'dashboard_url' => $dashboardUrl.'/payments/'.$payment->key,
                ],
            ],
        ]);
    }

    /**
     * Платёж, который проводит тестовый коннектор (`test`, `test_sbp`), — по
     * коннектору платежа, а если он не записан, по последней попытке. Любой
     * другой, в том числе без коннектора вовсе, для симулятора не существует.
     */
    private function findTestPayment(string $paymentKey): PaymentIntent
    {
        $payment = PaymentIntent::where('key', $paymentKey)->firstOrFail();

        $connector = $payment->connector
            ?? $payment->paymentAttempts()->latest('id')->value('connector');

        if (! is_string($connector) || config("payswitch.connectors.{$connector}") !== TestConnector::class) {
            abort(404);
        }

        return $payment;
    }
}
