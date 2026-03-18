<?php

declare(strict_types=1);

namespace App\Repositories\Eloquent;

use App\DataTransferObjects\Analytics\PeriodFilter;
use App\Repositories\Contracts\AnalyticsRepositoryInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Streeboga\PaymentData\Enums\PaymentStatus;
use Streeboga\PaymentData\Enums\RefundStatus;

final class AnalyticsRepository implements AnalyticsRepositoryInterface
{
    private const string SUCCEEDED = PaymentStatus::Succeeded->value;

    private const string FAILED = PaymentStatus::Failed->value;

    private const string REQUIRES_PAYMENT_METHOD = PaymentStatus::RequiresPaymentMethod->value;

    private const string REQUIRES_CAPTURE = PaymentStatus::RequiresCapture->value;

    private const string PARTIALLY_CAPTURED = PaymentStatus::PartiallyCaptured->value;

    private const string PARTIALLY_CAPTURED_AND_CAPTURABLE = PaymentStatus::PartiallyCapturedAndCapturable->value;

    private const string REFUND_SUCCEEDED = RefundStatus::Succeeded->value;

    public function overview(int|string $merchantId, PeriodFilter $period): array
    {
        $s = self::SUCCEEDED;
        $f = self::FAILED;
        $rs = self::REFUND_SUCCEEDED;

        $payments = $this->paymentsQuery($merchantId, $period)
            ->selectRaw('COUNT(*) as total_count')
            ->selectRaw("SUM(CASE WHEN status = '{$s}' THEN 1 ELSE 0 END) as successful_count")
            ->selectRaw("SUM(CASE WHEN status = '{$f}' THEN 1 ELSE 0 END) as failed_count")
            ->selectRaw("SUM(CASE WHEN status = '{$s}' THEN amount ELSE 0 END) as total_amount")
            ->selectRaw("SUM(CASE WHEN status = '{$s}' THEN net_amount ELSE 0 END) as net_amount")
            ->first();

        $refunds = DB::table('refunds')
            ->where('merchant_account_id', $merchantId)
            ->whereBetween('created_at', [$period->from, $period->to])
            ->selectRaw('COUNT(*) as refund_count')
            ->selectRaw("SUM(CASE WHEN status = '{$rs}' THEN amount ELSE 0 END) as refund_amount")
            ->first();

        $successRate = $payments->total_count > 0
            ? round(($payments->successful_count / $payments->total_count) * 100, 2)
            : 0;

        return [
            'total_count' => (int) $payments->total_count,
            'successful_count' => (int) $payments->successful_count,
            'failed_count' => (int) $payments->failed_count,
            'total_amount' => (int) $payments->total_amount,
            'net_amount' => (int) $payments->net_amount,
            'success_rate' => $successRate,
            'refund_count' => (int) $refunds->refund_count,
            'refund_amount' => (int) $refunds->refund_amount,
        ];
    }

    public function charts(int|string $merchantId, PeriodFilter $period): array
    {
        $s = self::SUCCEEDED;
        $f = self::FAILED;

        $rows = $this->paymentsQuery($merchantId, $period)
            ->selectRaw('DATE(created_at) as date')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw("SUM(CASE WHEN status = '{$s}' THEN amount ELSE 0 END) as amount")
            ->selectRaw("SUM(CASE WHEN status = '{$s}' THEN 1 ELSE 0 END) as successful")
            ->selectRaw("SUM(CASE WHEN status = '{$f}' THEN 1 ELSE 0 END) as failed")
            ->groupByRaw('DATE(created_at)')
            ->orderByRaw('DATE(created_at)')
            ->get();

        return $rows->map(fn ($row) => [
            'date' => $row->date,
            'count' => (int) $row->count,
            'amount' => (int) $row->amount,
            'successful' => (int) $row->successful,
            'failed' => (int) $row->failed,
        ])->toArray();
    }

    public function funnel(int|string $merchantId, PeriodFilter $period): array
    {
        $rpm = self::REQUIRES_PAYMENT_METHOD;
        $s = self::SUCCEEDED;
        $rc = self::REQUIRES_CAPTURE;
        $pc = self::PARTIALLY_CAPTURED;
        $pcac = self::PARTIALLY_CAPTURED_AND_CAPTURABLE;

        $stages = $this->paymentsQuery($merchantId, $period)
            ->selectRaw('COUNT(*) as created')
            ->selectRaw("SUM(CASE WHEN status NOT IN ('{$rpm}') THEN 1 ELSE 0 END) as confirmed")
            ->selectRaw("SUM(CASE WHEN status IN ('{$s}', '{$rc}', '{$pc}', '{$pcac}') THEN 1 ELSE 0 END) as authorized")
            ->selectRaw("SUM(CASE WHEN status = '{$s}' THEN 1 ELSE 0 END) as captured")
            ->first();

        return [
            'created' => (int) $stages->created,
            'confirmed' => (int) $stages->confirmed,
            'authorized' => (int) $stages->authorized,
            'captured' => (int) $stages->captured,
        ];
    }

    public function paymentMethods(int|string $merchantId, PeriodFilter $period): array
    {
        $rows = $this->paymentsQuery($merchantId, $period)
            ->where('status', self::SUCCEEDED)
            ->whereNotNull('connector')
            ->selectRaw('connector as method')
            ->selectRaw('COUNT(*) as count')
            ->selectRaw('SUM(amount) as amount')
            ->groupBy('connector')
            ->orderByDesc('count')
            ->get();

        return $rows->map(fn ($row) => [
            'method' => $row->method,
            'count' => (int) $row->count,
            'amount' => (int) $row->amount,
        ])->toArray();
    }

    public function failureReasons(int|string $merchantId, PeriodFilter $period): array
    {
        $rows = $this->paymentsQuery($merchantId, $period)
            ->where('status', self::FAILED)
            ->whereNotNull('error_code')
            ->selectRaw('error_code as code')
            ->selectRaw('error_message as message')
            ->selectRaw('COUNT(*) as count')
            ->groupBy('error_code', 'error_message')
            ->orderByDesc('count')
            ->limit(20)
            ->get();

        return $rows->map(fn ($row) => [
            'code' => $row->code,
            'message' => $row->message,
            'count' => (int) $row->count,
        ])->toArray();
    }

    private function paymentsQuery(int|string $merchantId, PeriodFilter $period): Builder
    {
        return DB::table('payment_intents')
            ->where('merchant_account_id', $merchantId)
            ->whereBetween('created_at', [$period->from, $period->to]);
    }
}
