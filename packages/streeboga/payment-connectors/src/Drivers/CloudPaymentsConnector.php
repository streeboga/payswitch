<?php

declare(strict_types=1);

namespace Streeboga\PaymentConnectors\Drivers;

use Illuminate\Support\Facades\Http;
use Streeboga\PaymentConnectors\ConnectorCapabilities;
use Streeboga\PaymentConnectors\DirectMethod;
use Streeboga\PaymentConnectors\PaymentSessionResult;
use Streeboga\PaymentData\Contracts\ConnectorInterface;
use Streeboga\PaymentData\Contracts\WebhookAcknowledging;
use Streeboga\PaymentData\Contracts\WebhookEventReading;
use Streeboga\PaymentData\Enums\AmountUnit;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\SessionResultType;

final class CloudPaymentsConnector implements ConnectorInterface, WebhookAcknowledging, WebhookEventReading
{
    private string $publicId;

    private string $apiSecret;

    private array $credentials;

    private string $baseUrl = 'https://api.cloudpayments.ru';

    /** @param  array<string, string>  $credentials */
    public function __construct(array $credentials)
    {
        $this->credentials = $credentials;
        $this->publicId = $credentials['public_id'] ?? '';
        $this->apiSecret = $credentials['api_secret'] ?? $credentials['api_key'] ?? '';
    }

    public static function capabilities(): ConnectorCapabilities
    {
        return new ConnectorCapabilities(
            defaultDisplayName: ['ru' => 'CloudPayments', 'en' => 'CloudPayments'],
            logoPath: '/logos/cloudpayments.svg',
            directMethods: [
                'card' => new DirectMethod(SessionResultType::EmbeddedWidget),
                'sbp' => new DirectMethod(SessionResultType::EmbeddedWidget),
            ],
            fallbackSessionType: SessionResultType::EmbeddedWidget,
            amountUnit: AmountUnit::Rubles,
        );
    }

    public function getName(): string
    {
        return 'cloudpayments';
    }

    public function purchase(array $params): array
    {
        return $this->makeRequest('/payments/cards/charge', [
            'Amount' => ($params['amount'] ?? 0) / 100,
            'Currency' => $params['currency'] ?? 'RUB',
            'CardCryptogramPacket' => $params['payment_method_data']['card']['cryptogram'] ?? $params['token'] ?? '',
            'Name' => $params['payment_method_data']['card']['card_holder_name'] ?? '',
            'IpAddress' => $params['ip_address'] ?? '127.0.0.1',
            'Description' => $params['description'] ?? '',
            'InvoiceId' => $params['payment_id'] ?? '',
            'JsonData' => json_encode(($params['metadata'] ?? []) + $this->receiptData($params)),
        ] + $this->payerFields($params));
    }

    public function authorize(array $params): array
    {
        return $this->makeRequest('/payments/cards/auth', [
            'Amount' => ($params['amount'] ?? 0) / 100,
            'Currency' => $params['currency'] ?? 'RUB',
            'CardCryptogramPacket' => $params['payment_method_data']['card']['cryptogram'] ?? $params['token'] ?? '',
            'Name' => $params['payment_method_data']['card']['card_holder_name'] ?? '',
            'IpAddress' => $params['ip_address'] ?? '127.0.0.1',
            'Description' => $params['description'] ?? '',
            'InvoiceId' => $params['payment_id'] ?? '',
        ] + $this->payerFields($params) + (($data = $this->receiptData($params)) === [] ? [] : ['JsonData' => json_encode($data)]));
    }

    public function capture(array $params): array
    {
        return $this->makeRequest('/payments/confirm', [
            'TransactionId' => $params['transaction_id'] ?? '',
            'Amount' => ($params['amount'] ?? 0) / 100,
        ]);
    }

    public function refund(array $params): array
    {
        // У payments/refund три параметра: TransactionId, Amount и JsonData — «любые другие
        // данные, в том числе инструкции для формирования онлайн-чека». Своего поля причины
        // нет: она уходит в JsonData.comment. Состав чека возврата — тот же CustomerReceipt;
        // без него касса строит чек по исходному, поэтому при частичном возврате платежа с
        // чеком состав передаёт мерчант.
        // @see https://developers.cloudpayments.ru/#vozvrat-deneg
        $data = array_filter(['comment' => $params['reason'] ?? null]) + $this->receiptData($params);

        // X-Request-ID — идемпотентность CloudPayments: повтор с тем же id отдаёт первый ответ.
        return $this->makeRequest('/payments/refund', [
            'TransactionId' => $params['transaction_id'] ?? '',
            'Amount' => ($params['amount'] ?? 0) / 100,
        ] + ($data === [] ? [] : ['JsonData' => json_encode($data)]), $params['refund_id'] ?? null);
    }

    public function void(array $params): array
    {
        return $this->makeRequest('/payments/void', [
            'TransactionId' => $params['transaction_id'] ?? '',
        ]);
    }

    /**
     * CloudPayments signs the raw body with HMAC-SHA256 over the API secret, base64, in
     * the Content-HMAC header.
     *
     * @see https://developers.cloudpayments.ru/#proverka-uvedomleniy
     */
    public function verifyWebhookSignature(string $payload, array $headers): bool
    {
        $hmac = $headers['content-hmac'] ?? null;
        if (! $hmac) {
            // Was `! app()->environment('production')`, which silently opened every
            // stand that happened not to be flagged production. Now it takes saying so.
            return (bool) config('payswitch.allow_unsigned_webhooks', false);
        }

        $apiSecret = $this->credentials['api_secret'] ?? $this->credentials['api_key'] ?? '';
        $expected = base64_encode(hash_hmac('sha256', $payload, $apiSecret, true));

        return hash_equals($expected, $hmac);
    }

    /**
     * CloudPayments reads the body of our answer, not the status code.
     *
     * The check notification is a question — "shall I charge this?" — and anything but
     * code 0 is a no. A 200 carrying `{"status":"ok"}` is a no as well: there is no code
     * in it, so the payer sees «Платеж не может быть принят» and no money moves.
     *
     * The refusal codes are theirs and they are not interchangeable: 12 shows up in the
     * merchant's cabinet as InvalidAmount, 13 as NotAccepted, 20 as Expired. Saying which
     * one it was is the difference between "the payer changed the price" and "something
     * went wrong".
     *
     * @see https://developers.cloudpayments.ru/#uvedomleniya
     *
     * @return array<string, mixed>
     */
    public function webhookAck(?string $refusal, array $payload = []): array
    {
        return ['code' => match ($refusal) {
            null => 0,
            'amount' => 12,
            'expired' => 20,
            default => 13,
        }];
    }

    /**
     * CloudPayments has no event field: which notification it is shows only in which fields
     * are there. Check and Pay both carry Status (Completed, or Authorized for two-stage),
     * only Pay has AuthCode. Refund is marked by OperationType.
     *
     * `type` is not theirs — it is honoured first so that a hand-made notification can
     * still name its event.
     *
     * @see https://developers.cloudpayments.ru/#uvedomleniya
     */
    public function webhookEventType(array $payload): string
    {
        if (isset($payload['type'])) {
            return (string) $payload['type'];
        }

        // Чек кассы (CloudKassir): свои поля, которых нет ни у одного платёжного уведомления.
        // Раньше остальных: у чека возврата тоже есть TransactionId и InvoiceId.
        if (isset($payload['FiscalSign']) || isset($payload['QrCodeUrl'])) {
            return self::RECEIPT;
        }

        // Before Status: money going back to the payer must never read as a payment.
        if (($payload['OperationType'] ?? null) === 'Refund') {
            return 'refund.succeeded';
        }

        $status = $payload['Status'] ?? null;

        if ($status === 'Completed' || $status === 'Authorized') {
            if (! isset($payload['AuthCode'])) {
                return self::CHECK;
            }

            return $status === 'Completed' ? 'payment.succeeded' : 'payment.waiting_for_capture';
        }

        // Fail comes with the decline reason and, unlike Pay and Check, without Status.
        if ($status === 'Declined' || isset($payload['ReasonCode'])) {
            return 'payment.failed';
        }

        // Cancel (an authorization voided) has nothing of its own, only the bare set below.
        // Taken strictly: a receipt notification also carries TransactionId and InvoiceId,
        // and must not void a payment.
        $cancelFields = ['TransactionId', 'Amount', 'DateTime', 'InvoiceId', 'AccountId', 'Email', 'Data', 'JsonData'];
        if (isset($payload['TransactionId']) && array_diff(array_keys($payload), $cancelFields) === []) {
            return 'payment.canceled';
        }

        return '';
    }

    public function mapWebhookEventToStatus(string $eventType): ?PaymentStatus
    {
        return match ($eventType) {
            'payment.succeeded' => PaymentStatus::Succeeded,
            'payment.failed' => PaymentStatus::Failed,
            'payment.canceled' => PaymentStatus::Cancelled,
            'payment.waiting_for_capture' => PaymentStatus::RequiresCapture,
            default => null,
        };
    }

    public function extractPaymentIdFromWebhook(array $payload): ?string
    {
        return $payload['InvoiceId'] ?? ($payload['data']['InvoiceId'] ?? null);
    }

    /**
     * `/payments/get` takes a TransactionId; `/v2/payments/find` takes an InvoiceId (our
     * payment id) and lists every transaction on it. The old call sent a TransactionId to
     * `/payments/find`, which looks up by InvoiceId, so it never found anything.
     *
     * Sync reads the provider status from `data.status`; CloudPayments keeps it in
     * `Model.Status`, so it is copied there.
     *
     * @see https://developers.cloudpayments.ru/
     */
    public function getPaymentStatus(array $params): array
    {
        $transactionId = $params['transaction_id'] ?? null;

        $result = $transactionId
            ? $this->makeRequest('/payments/get', ['TransactionId' => $transactionId])
            : $this->makeRequest('/v2/payments/find', ['InvoiceId' => $params['payment_id'] ?? '']);

        $model = $result['data'] ?? null;
        if (! is_array($model)) {
            return $result;
        }

        if (array_is_list($model)) {
            // Several tries on one invoice: the one that took money is the answer, if any.
            $charged = array_values(array_filter(
                $model,
                fn ($txn) => in_array($txn['Status'] ?? null, ['Completed', 'Authorized'], true),
            ));
            $model = $charged[0] ?? end($model) ?: [];
            $result['transaction_id'] = $model['TransactionId'] ?? null;
        }

        $result['data'] = $model + ['status' => $model['Status'] ?? null];

        return $result;
    }

    public function createPaymentSession(array $params): PaymentSessionResult
    {
        return PaymentSessionResult::embeddedWidget(
            provider: 'cloudpayments',
            scriptUrl: 'https://widget.cloudpayments.ru/bundles/cloudpayments.js',
            params: [
                'publicId' => $this->publicId,
                'amount' => ($params['amount'] ?? 0) / 100,
                'currency' => $params['currency'] ?? 'RUB',
                'description' => $params['description'] ?? '',
                'invoiceId' => $params['payment_id'] ?? '',
            ] + array_change_key_case($this->payerFields($params)) + (($data = $this->receiptData($params)) === [] ? [] : ['data' => $data]),
        );
    }

    /**
     * Плательщик: AccountId — наш customer_id, Email — куда касса шлёт чек.
     * Ключи — как в API; виджету те же поля нужны с маленькой буквы (accountId, email).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, string>
     */
    private function payerFields(array $params): array
    {
        return array_filter([
            'AccountId' => $params['customer_id'] ?? null,
            'Email' => $params['receipt']['email'] ?? null,
        ]);
    }

    /**
     * Состав чека для CloudKassir: объект CustomerReceipt в обёртке CloudPayments — в `data`
     * виджета и в `JsonData` оплаты по криптограмме. Без receipt — пусто, чек не заказывается.
     *
     * Словарь payswitch → коды кассы; суммы у кассы в рублях. `vat: none` — это null
     * («НДС не облагается»), а не 0 («НДС 0 %»).
     *
     * @see https://developers.cloudkassir.ru/#customerreceipt
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function receiptData(array $params): array
    {
        $receipt = $params['receipt'] ?? null;
        if (! is_array($receipt) || empty($receipt['items'])) {
            return [];
        }

        $items = array_map(fn (array $item): array => [
            'label' => $item['label'],
            'price' => $item['price'] / 100,
            'quantity' => $item['quantity'] + 0,
            'amount' => $item['amount'] / 100,
            'vat' => $item['vat'] === 'none' ? null : (int) $item['vat'],
            'method' => ['full_prepayment' => 1, 'prepayment' => 2, 'advance' => 3, 'full_payment' => 4][$item['payment_method']],
            'object' => ['commodity' => 1, 'service' => 4, 'payment' => 10, 'another' => 13][$item['payment_object']],
        ], $receipt['items']);

        return ['CloudPayments' => ['CustomerReceipt' => array_filter([
            'items' => $items,
            'taxationSystem' => ['osn' => 0, 'usn_income' => 1, 'usn_income_outcome' => 2, 'esn' => 4, 'patent' => 5][$receipt['taxation_system']],
            'email' => $receipt['email'] ?? null,
            'amounts' => ['electronic' => array_sum(array_column($items, 'amount'))],
        ], fn ($value) => $value !== null)]];
    }

    public function mapPaymentStatusToInternal(string $rawStatus): ?PaymentStatus
    {
        return match ($rawStatus) {
            'AwaitingAuthentication' => PaymentStatus::RequiresCustomerAction,
            'Completed' => PaymentStatus::Succeeded,
            'Declined' => PaymentStatus::Failed,
            'Authorized' => PaymentStatus::RequiresCapture,
            'Cancelled' => PaymentStatus::Cancelled,
            default => null,
        };
    }

    public function testConnection(): array
    {
        try {
            $response = Http::withBasicAuth($this->publicId, $this->apiSecret)
                ->timeout(10)
                ->post($this->baseUrl.'/test');

            $body = $response->json();
            $success = ($body['Success'] ?? false) === true;

            return [
                'success' => $success,
                'message' => $success ? 'Connection successful' : ($body['Message'] ?? 'Authentication failed'),
            ];
        } catch (\Exception $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    private function makeRequest(string $endpoint, array $data, ?string $requestId = null): array
    {
        try {
            $response = Http::withBasicAuth($this->publicId, $this->apiSecret)
                ->withHeaders($requestId ? ['X-Request-ID' => $requestId] : [])
                ->timeout(30)
                ->post($this->baseUrl.$endpoint, $data);

            $body = $response->json();

            $success = ($body['Success'] ?? false) === true;
            $model = $body['Model'] ?? [];

            if ($model['AcsUrl'] ?? null) {
                return [
                    'success' => false,
                    'transaction_id' => $model['TransactionId'] ?? null,
                    'message' => '3-D Secure authentication required',
                    'code' => 'requires_action',
                    'data' => [
                        'redirect_url' => $model['AcsUrl'],
                        'redirect_method' => 'POST',
                        'redirect_params' => [
                            'PaReq' => $model['PaReq'] ?? null,
                            'MD' => $model['TransactionId'] ?? null,
                            'TermUrl' => $model['TermUrl'] ?? null,
                        ],
                        'transaction_id' => $model['TransactionId'] ?? null,
                        'pa_req' => $model['PaReq'] ?? null,
                    ],
                ];
            }

            return [
                'success' => $success,
                'transaction_id' => $model['TransactionId'] ?? null,
                'message' => $body['Message'] ?? ($model['CardHolderMessage'] ?? 'Unknown'),
                'code' => $success ? 'ok' : ($model['ReasonCode'] ?? (string) ($body['Message'] ?? 'error')),
                'data' => $model,
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'transaction_id' => null,
                'message' => $e->getMessage(),
                'code' => 'connector_error',
            ];
        }
    }
}
