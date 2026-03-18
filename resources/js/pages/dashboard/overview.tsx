import { Head } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

interface Stats {
    total_payments: number;
    succeeded_payments: number;
    failed_payments: number;
    total_revenue: number;
    total_refunds: number;
    total_refunded: number;
    total_merchants: number;
    total_customers: number;
}

interface RecentPayment {
    id: string;
    amount: number;
    currency: string;
    status: string;
    connector: string | null;
    created_at: string;
}

interface Props {
    stats: Stats;
    recent_payments: RecentPayment[];
}

const breadcrumbs: BreadcrumbItem[] = [
    {
        title: 'Dashboard',
        href: '/dashboard',
    },
];

const statusColors: Record<string, string> = {
    succeeded: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-800',
    processing: 'bg-blue-100 text-blue-800',
    requires_payment_method: 'bg-yellow-100 text-yellow-800',
    requires_capture: 'bg-indigo-100 text-indigo-800',
    requires_confirmation: 'bg-orange-100 text-orange-800',
};

function formatAmount(amount: number, currency: string): string {
    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency: currency,
        minimumFractionDigits: 2,
    }).format(amount / 100);
}

function StatCard({
    title,
    value,
    color,
}: {
    title: string;
    value: string | number;
    color?: string;
}) {
    return (
        <div className="rounded-xl border border-sidebar-border/70 bg-white p-4 dark:border-sidebar-border dark:bg-neutral-900">
            <p className="text-sm text-gray-500 dark:text-gray-400">{title}</p>
            <p
                className={`mt-1 text-2xl font-bold ${color || 'text-gray-900 dark:text-gray-100'}`}
            >
                {value}
            </p>
        </div>
    );
}

export default function Overview({ stats, recent_payments }: Props) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Payswitch — Overview" />

            <div className="space-y-6 p-6">
                <h1 className="text-2xl font-bold">Payswitch Dashboard</h1>

                {/* Stats Grid */}
                <div className="grid grid-cols-2 gap-4 md:grid-cols-4">
                    <StatCard
                        title="Всего платежей"
                        value={stats.total_payments}
                    />
                    <StatCard
                        title="Успешных"
                        value={stats.succeeded_payments}
                        color="text-emerald-600"
                    />
                    <StatCard
                        title="Неудачных"
                        value={stats.failed_payments}
                        color="text-red-600"
                    />
                    <StatCard
                        title="Выручка"
                        value={formatAmount(stats.total_revenue, 'USD')}
                        color="text-emerald-600"
                    />
                    <StatCard title="Рефандов" value={stats.total_refunds} />
                    <StatCard
                        title="Возвращено"
                        value={formatAmount(stats.total_refunded, 'USD')}
                        color="text-orange-600"
                    />
                    <StatCard title="Мерчантов" value={stats.total_merchants} />
                    <StatCard title="Клиентов" value={stats.total_customers} />
                </div>

                {/* Recent Payments */}
                <div>
                    <h2 className="mb-3 text-lg font-semibold">
                        Последние платежи
                    </h2>
                    <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                        <table className="w-full text-sm">
                            <thead className="border-b bg-gray-50 dark:bg-neutral-800">
                                <tr>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                        ID
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                        Сумма
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                        Статус
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                        Коннектор
                                    </th>
                                    <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                        Время
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {recent_payments.map((p) => (
                                    <tr
                                        key={p.id}
                                        className="border-b last:border-0 hover:bg-gray-50 dark:hover:bg-neutral-800"
                                    >
                                        <td className="px-4 py-3 font-mono text-xs">
                                            {p.id}
                                        </td>
                                        <td className="px-4 py-3">
                                            {formatAmount(p.amount, p.currency)}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`rounded-full px-2 py-1 text-xs font-medium ${statusColors[p.status] || 'bg-gray-100'}`}
                                            >
                                                {p.status}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-gray-500">
                                            {p.connector || '\u2014'}
                                        </td>
                                        <td className="px-4 py-3 text-gray-500">
                                            {p.created_at}
                                        </td>
                                    </tr>
                                ))}
                                {recent_payments.length === 0 && (
                                    <tr>
                                        <td
                                            colSpan={5}
                                            className="px-4 py-8 text-center text-gray-400"
                                        >
                                            Нет платежей. Запустите{' '}
                                            <code className="rounded bg-gray-100 px-1 dark:bg-neutral-800">
                                                php artisan payswitch:seed
                                            </code>
                                        </td>
                                    </tr>
                                )}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </AppLayout>
    );
}
