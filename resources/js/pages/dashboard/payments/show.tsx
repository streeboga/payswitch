import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

interface Attempt {
    connector: string;
    status: string;
    amount: number;
    error_code: string | null;
    created_at: string;
}

interface RefundItem {
    id: string;
    amount: number;
    status: string;
    reason: string | null;
    created_at: string;
}

interface PaymentDetail {
    id: string;
    amount: number;
    amount_received: number | null;
    amount_capturable: number | null;
    currency: string;
    status: string;
    capture_method: string;
    connector: string | null;
    customer_id: string | null;
    description: string | null;
    error_code: string | null;
    error_message: string | null;
    metadata: Record<string, string> | null;
    attempt_count: number;
    created_at: string;
    attempts: Attempt[];
    refunds: RefundItem[];
}

const statusColors: Record<string, string> = {
    succeeded: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-800',
    processing: 'bg-blue-100 text-blue-800',
    requires_capture: 'bg-indigo-100 text-indigo-800',
};

function fmt(amount: number, currency: string) {
    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
    }).format(amount / 100);
}

function Detail({ label, value }: { label: string; value: string }) {
    return (
        <div className="flex justify-between text-sm">
            <span className="text-gray-500 dark:text-gray-400">{label}</span>
            <span className="font-medium">{value}</span>
        </div>
    );
}

export default function PaymentShow({ payment }: { payment: PaymentDetail }) {
    const breadcrumbs: BreadcrumbItem[] = [
        { title: 'Dashboard', href: '/dashboard' },
        { title: 'Платежи', href: '/dashboard/payments' },
        { title: payment.id, href: `/dashboard/payments/${payment.id}` },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={`Платёж ${payment.id}`} />
            <div className="space-y-6 p-6">
                <div className="flex items-center gap-3">
                    <Link
                        href="/dashboard/payments"
                        className="text-sm text-blue-600 hover:underline"
                    >
                        &larr; Назад
                    </Link>
                    <h1 className="font-mono text-xl font-bold">
                        {payment.id}
                    </h1>
                    <span
                        className={`rounded-full px-2 py-1 text-xs font-medium ${statusColors[payment.status] || 'bg-gray-100'}`}
                    >
                        {payment.status}
                    </span>
                </div>

                {/* Details */}
                <div className="grid gap-6 md:grid-cols-2">
                    <div className="space-y-3 rounded-xl border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-neutral-900">
                        <h2 className="font-semibold text-gray-700 dark:text-gray-300">
                            Детали платежа
                        </h2>
                        <Detail
                            label="Сумма"
                            value={fmt(payment.amount, payment.currency)}
                        />
                        <Detail
                            label="Получено"
                            value={
                                payment.amount_received
                                    ? fmt(
                                          payment.amount_received,
                                          payment.currency,
                                      )
                                    : '\u2014'
                            }
                        />
                        <Detail
                            label="К захвату"
                            value={
                                payment.amount_capturable
                                    ? fmt(
                                          payment.amount_capturable,
                                          payment.currency,
                                      )
                                    : '\u2014'
                            }
                        />
                        <Detail
                            label="Capture method"
                            value={payment.capture_method}
                        />
                        <Detail
                            label="Коннектор"
                            value={payment.connector || '\u2014'}
                        />
                        <Detail
                            label="Клиент"
                            value={payment.customer_id || '\u2014'}
                        />
                        <Detail
                            label="Описание"
                            value={payment.description || '\u2014'}
                        />
                        <Detail
                            label="Попыток"
                            value={String(payment.attempt_count)}
                        />
                        <Detail label="Создан" value={payment.created_at} />
                    </div>

                    {(payment.error_code ||
                        (payment.metadata &&
                            Object.keys(payment.metadata).length > 0)) && (
                        <div className="space-y-3 rounded-xl border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-neutral-900">
                            {payment.error_code && (
                                <>
                                    <h2 className="font-semibold text-red-600">
                                        Ошибка
                                    </h2>
                                    <Detail
                                        label="Код"
                                        value={payment.error_code}
                                    />
                                    <Detail
                                        label="Сообщение"
                                        value={
                                            payment.error_message || '\u2014'
                                        }
                                    />
                                </>
                            )}
                            {payment.metadata &&
                                Object.keys(payment.metadata).length > 0 && (
                                    <>
                                        <h2 className="mt-4 font-semibold text-gray-700 dark:text-gray-300">
                                            Metadata
                                        </h2>
                                        <pre className="overflow-auto rounded bg-gray-50 p-2 text-xs dark:bg-neutral-800">
                                            {JSON.stringify(
                                                payment.metadata,
                                                null,
                                                2,
                                            )}
                                        </pre>
                                    </>
                                )}
                        </div>
                    )}
                </div>

                {/* Attempts */}
                {payment.attempts.length > 0 && (
                    <div>
                        <h2 className="mb-2 font-semibold">Попытки оплаты</h2>
                        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-gray-50 dark:bg-neutral-800">
                                    <tr>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Коннектор
                                        </th>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Статус
                                        </th>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Сумма
                                        </th>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Ошибка
                                        </th>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Время
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {payment.attempts.map((a, i) => (
                                        <tr
                                            key={i}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-2">
                                                {a.connector}
                                            </td>
                                            <td className="px-4 py-2">
                                                <span
                                                    className={`rounded px-2 py-0.5 text-xs ${a.status === 'succeeded' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}`}
                                                >
                                                    {a.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2">
                                                {fmt(
                                                    a.amount,
                                                    payment.currency,
                                                )}
                                            </td>
                                            <td className="px-4 py-2 text-xs text-red-500">
                                                {a.error_code || '\u2014'}
                                            </td>
                                            <td className="px-4 py-2 text-gray-500">
                                                {a.created_at}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {/* Refunds */}
                {payment.refunds.length > 0 && (
                    <div>
                        <h2 className="mb-2 font-semibold">Рефанды</h2>
                        <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                            <table className="w-full text-sm">
                                <thead className="border-b bg-gray-50 dark:bg-neutral-800">
                                    <tr>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Refund ID
                                        </th>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Сумма
                                        </th>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Статус
                                        </th>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Причина
                                        </th>
                                        <th className="px-4 py-2 text-left text-gray-600 dark:text-gray-400">
                                            Дата
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {payment.refunds.map((r) => (
                                        <tr
                                            key={r.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="px-4 py-2 font-mono text-xs">
                                                {r.id}
                                            </td>
                                            <td className="px-4 py-2">
                                                {fmt(
                                                    r.amount,
                                                    payment.currency,
                                                )}
                                            </td>
                                            <td className="px-4 py-2">
                                                <span
                                                    className={`rounded px-2 py-0.5 text-xs ${r.status === 'succeeded' ? 'bg-emerald-100 text-emerald-700' : 'bg-red-100 text-red-700'}`}
                                                >
                                                    {r.status}
                                                </span>
                                            </td>
                                            <td className="px-4 py-2 text-gray-500">
                                                {r.reason || '\u2014'}
                                            </td>
                                            <td className="px-4 py-2 text-gray-500">
                                                {r.created_at}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
