import { Head, Link } from '@inertiajs/react';
import AppLayout from '@/layouts/app-layout';
import type { BreadcrumbItem } from '@/types';

interface Merchant {
    id: string;
    name: string;
    organization: string;
    publishable_key: string;
    created_at: string;
}

interface PaginatedMerchants {
    data: Merchant[];
    total: number;
    last_page: number;
    links: { url: string | null; label: string; active: boolean }[];
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Dashboard', href: '/dashboard' },
    { title: 'Мерчанты', href: '/dashboard/merchants' },
];

export default function MerchantsIndex({
    merchants,
}: {
    merchants: PaginatedMerchants;
}) {
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Мерчанты" />
            <div className="p-6">
                <h1 className="mb-4 text-2xl font-bold">Мерчанты</h1>
                <p className="mb-4 text-sm text-gray-500">
                    Всего: {merchants.total}
                </p>

                <div className="overflow-hidden rounded-xl border border-sidebar-border/70 bg-white dark:border-sidebar-border dark:bg-neutral-900">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-gray-50 dark:bg-neutral-800">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                    Merchant ID
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                    Название
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                    Организация
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                    Publishable Key
                                </th>
                                <th className="px-4 py-3 text-left font-medium text-gray-600 dark:text-gray-400">
                                    Дата
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {merchants.data.map((m) => (
                                <tr
                                    key={m.id}
                                    className="border-b last:border-0 hover:bg-gray-50 dark:hover:bg-neutral-800"
                                >
                                    <td className="px-4 py-3 font-mono text-xs">
                                        {m.id}
                                    </td>
                                    <td className="px-4 py-3 font-medium">
                                        {m.name}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500">
                                        {m.organization}
                                    </td>
                                    <td className="px-4 py-3 font-mono text-xs text-gray-500">
                                        {m.publishable_key}
                                    </td>
                                    <td className="px-4 py-3 text-gray-500">
                                        {m.created_at}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>

                {/* Pagination */}
                {merchants.last_page > 1 && (
                    <div className="mt-4 flex justify-center gap-1">
                        {merchants.links.map((link, i) => (
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
