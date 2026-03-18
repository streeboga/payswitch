import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

interface Payment {
    id: string;
    amount: number;
    currency: string;
    status: string;
    connector: string | null;
    customer_id: string | null;
    created_at: string;
}

interface PaginatedPayments {
    data: Payment[];
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Платежи', href: '/dashboard/payments' },
];

const statusColors: Record<string, string> = {
    succeeded: 'bg-emerald-100 text-emerald-800',
    failed: 'bg-red-100 text-red-800',
    cancelled: 'bg-gray-100 text-gray-800',
    processing: 'bg-blue-100 text-blue-800',
    requires_payment_method: 'bg-yellow-100 text-yellow-800',
    requires_capture: 'bg-indigo-100 text-indigo-800',
};

function formatAmount(amount: number, currency: string): string {
    return new Intl.NumberFormat('ru-RU', {
        style: 'currency',
        currency,
        minimumFractionDigits: 2,
    }).format(amount / 100);
}

export default function PaymentsIndex({
    payments,
}: {
    payments: PaginatedPayments;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Платежи" />
            <div className="p-6">
                <h1 className="mb-4 text-2xl font-bold">Платежи</h1>
                <p className="mb-4 text-sm text-gray-500">
                    Всего: {payments.total}
                </p>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-gray-50 dark:bg-neutral-800">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                    Payment ID
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
                                    Клиент
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                    Дата
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {payments.data.map((p) => (
                                <tr
                                    key={p.id}
                                    className="border-b last:border-0 hover:bg-gray-50 dark:hover:bg-neutral-800"
                                >
                                    <td className="px-4 py-3">
                                        <Link
                                            href={`/dashboard/payments/${p.id}`}
                                            className="font-mono text-xs text-blue-600 hover:underline"
                                        >
                                            {p.id}
                                        </Link>
                                    </td>
                                    <td className="px-4 py-3 font-medium">
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
                                    <td className="px-4 py-3 font-mono text-xs text-gray-500">
                                        {p.customer_id || '\u2014'}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500">
                                        {p.created_at}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {payments.last_page > 1 && (
                    <div className="mt-4 flex justify-center gap-1">
                        {payments.links.map((link, i) => (
                            <Link
                                key={i}
                                href={link.url || '#'}
                                className={`rounded px-3 py-1 text-sm ${
                                    link.active
                                        ? 'bg-blue-600 text-white'
                                        : 'border bg-white hover:bg-gray-50 dark:bg-neutral-900'
                                } ${!link.url ? 'pointer-events-none opacity-50' : ''}`}
                                dangerouslySetInnerHTML={{ __html: link.label }}
                            />
                        ))}
                    </div>
                )}
            </div>
        </AppLayout>
    );
}
