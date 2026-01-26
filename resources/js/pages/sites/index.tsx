import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';

type Site = {
    id: number;
    name: string;
    address_line_1?: string | null;
    address_line_2?: string | null;
    suburb?: string | null;
    city?: string | null;
    postcode?: string | null;
    country?: string | null;
    is_active: boolean;
};

type PageProps = {
    sites: Site[];
    filters?: {
        status?: 'all' | 'active' | 'inactive';
    };
    auth: { can?: any };
};

function addressFor(site: Site): string {
    const parts = [
        site.address_line_1,
        site.address_line_2,
        site.suburb,
        site.city,
        site.postcode,
        site.country,
    ].filter((v) => typeof v === 'string' && v.trim() !== '');
    return parts.join(', ');
}

export default function SitesIndex({ sites }: { sites: Site[] }) {
    const { auth, filters, labels } = usePage<PageProps & { labels?: Record<string, string> }>().props;
    const can = auth?.can ?? {};
    const status = filters?.status ?? 'all';

    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const sitePlural = labels?.['site.plural'] ?? 'Sites';

    return (
        <AppLayout breadcrumbs={[{ title: sitePlural, href: '/sites' }]}>
            <Head title={sitePlural} />

            <div className="m-4 space-y-4">
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold">{sitePlural}</h1>
                    {can?.sites?.create && (
                        <Link
                            href="/sites/create"
                            className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white"
                        >
                            Add {siteSingular}
                        </Link>
                    )}
                </div>

                <div className="flex flex-wrap items-center gap-2">
                    <Link
                        href="/sites"
                        className={`rounded-full border px-3 py-1 text-xs ${
                            status === 'all'
                                ? 'border-indigo-500/40 text-indigo-200'
                                : 'border-slate-500/30 text-slate-300 hover:bg-muted'
                        }`}
                    >
                        All
                    </Link>
                    <Link
                        href="/sites?status=active"
                        className={`rounded-full border px-3 py-1 text-xs ${
                            status === 'active'
                                ? 'border-emerald-500/40 text-emerald-200'
                                : 'border-slate-500/30 text-slate-300 hover:bg-muted'
                        }`}
                    >
                        Active
                    </Link>
                    <Link
                        href="/sites?status=inactive"
                        className={`rounded-full border px-3 py-1 text-xs ${
                            status === 'inactive'
                                ? 'border-slate-400/50 text-slate-100'
                                : 'border-slate-500/30 text-slate-300 hover:bg-muted'
                        }`}
                    >
                        Inactive
                    </Link>
                </div>

                <div className="overflow-hidden rounded-xl border">
                    <table className="w-full text-sm">
                        <thead className="border-b bg-slate-50/5">
                            <tr>
                                <th className="px-4 py-3 text-left font-medium">Name</th>
                                <th className="px-4 py-3 text-left font-medium">Address</th>
                                <th className="px-4 py-3 text-left font-medium">Status</th>
                                <th className="px-4 py-3" />
                            </tr>
                        </thead>
                        <tbody>
                            {sites.length === 0 ? (
                                <tr>
                                    <td
                                        className="px-4 py-6 text-slate-400"
                                        colSpan={4}
                                    >
                                        No {sitePlural.toLowerCase()} yet.
                                    </td>
                                </tr>
                            ) : (
                                sites.map((s) => (
                                    <tr key={s.id} className="border-b last:border-b-0">
                                        <td className="px-4 py-3 font-medium">
                                            <Link
                                                href={`/sites/${s.id}`}
                                                className="hover:underline"
                                            >
                                                {s.name}
                                            </Link>
                                        </td>
                                        <td className="px-4 py-3 text-slate-300">
                                            {addressFor(s) || (
                                                <span className="text-slate-500">—</span>
                                            )}
                                        </td>
                                        <td className="px-4 py-3">
                                            <span
                                                className={`inline-flex rounded-full border px-2 py-0.5 text-xs ${
                                                    s.is_active
                                                        ? 'border-emerald-500/30 text-emerald-300'
                                                        : 'border-slate-500/30 text-slate-300'
                                                }`}
                                            >
                                                {s.is_active ? 'Active' : 'Inactive'}
                                            </span>
                                        </td>
                                        <td className="px-4 py-3 text-right space-x-3">
                                            <Link
                                                href={`/sites/${s.id}`}
                                                className="text-slate-200 hover:text-slate-100"
                                            >
                                                View
                                            </Link>
                                            {can?.sites?.update && (
                                                <Link
                                                    href={`/sites/${s.id}/edit`}
                                                    className="text-indigo-300 hover:text-indigo-200"
                                                >
                                                    Edit
                                                </Link>
                                            )}
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </div>
        </AppLayout>
    );
}
