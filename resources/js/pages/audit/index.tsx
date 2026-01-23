import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Head, Link, router, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

type AuditLog = {
    id: number;
    action: string;
    created_at: string;
    ip_address: string | null;
    user_agent: string | null;
    meta: any;
    user?: { id: number; name: string; email: string } | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    auditable_type: string | null;
    auditable_id: number | null;
};

type Props = {
    logs: {
        data: AuditLog[];
        links?: Array<{ url: string | null; label: string; active: boolean }>;
    };
    filters: {
        action?: string;
        user_id?: number;
        client_id?: number;
        date_from?: string;
        date_to?: string;
        q?: string;
    };
    filter_options: {
        users: Array<{ id: number; name: string; email: string }>;
        clients: Array<{ id: number; first_name: string; last_name: string }>;
    };
};

function Pagination({ links }: { links?: Array<{ url: string | null; label: string; active: boolean }> }) {
    if (!links || links.length <= 3) return null;

    return (
        <div className="flex flex-wrap items-center gap-1">
            {links.map((l, idx) => {
                const label = l.label.replace('&laquo;', '«').replace('&raquo;', '»');
                const disabled = !l.url;

                return (
                    <Button
                        key={idx}
                        variant={l.active ? 'default' : 'outline'}
                        size="sm"
                        disabled={disabled}
                        onClick={() => {
                            if (l.url) router.visit(l.url, { preserveScroll: true, preserveState: true });
                        }}
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                );
            })}
        </div>
    );
}

export default function AuditIndex({ logs, filters, filter_options }: Props) {
    const { labels } = usePage().props as any;

    const breadcrumbs = useMemo(() => [{ title: 'Audit Logs', href: '/audit-logs' }], []);

    const apply = (next: Partial<Props['filters']>) => {
        router.get(
            '/audit-logs',
            {
                ...filters,
                ...next,
            },
            { preserveState: true, replace: true },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Audit Logs" />

            <div className="p-4 space-y-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="text-lg font-semibold">Audit Logs</div>
                        <div className="text-sm text-muted-foreground">
                            Read-only trace of access and changes.
                        </div>
                    </div>
                </div>

                <div className="rounded-md border p-3 space-y-3">
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
                        <div className="space-y-1 md:col-span-2">
                            <div className="text-xs text-muted-foreground">Search</div>
                            <Input
                                value={filters.q ?? ''}
                                placeholder="action, user, client, ip…"
                                onChange={(e) => apply({ q: e.target.value || undefined })}
                            />
                        </div>

                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">Action contains</div>
                            <Input
                                value={filters.action ?? ''}
                                placeholder="clients.view"
                                onChange={(e) => apply({ action: e.target.value || undefined })}
                            />
                        </div>

                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">User</div>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={filters.user_id ?? ''}
                                onChange={(e) => apply({ user_id: e.target.value ? Number(e.target.value) : undefined })}
                            >
                                <option value="">All</option>
                                {filter_options.users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name} ({u.email})
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">Client</div>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={filters.client_id ?? ''}
                                onChange={(e) => apply({ client_id: e.target.value ? Number(e.target.value) : undefined })}
                            >
                                <option value="">All</option>
                                {filter_options.clients.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.first_name} {c.last_name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">From</div>
                            <Input type="date" value={filters.date_from ?? ''} onChange={(e) => apply({ date_from: e.target.value || undefined })} />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
                        <div className="space-y-1 md:col-span-2">
                            <div className="text-xs text-muted-foreground">To</div>
                            <Input type="date" value={filters.date_to ?? ''} onChange={(e) => apply({ date_to: e.target.value || undefined })} />
                        </div>

                        <div className="md:col-span-4 flex items-end justify-end gap-2">
                            <Button
                                variant="outline"
                                onClick={() => router.get('/audit-logs', {}, { preserveState: true, replace: true })}
                            >
                                Clear
                            </Button>
                        </div>
                    </div>
                </div>

                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted/40">
                            <tr>
                                <th className="p-3 text-left font-medium">When</th>
                                <th className="p-3 text-left font-medium">Action</th>
                                <th className="p-3 text-left font-medium">User</th>
                                <th className="p-3 text-left font-medium">Client</th>
                                <th className="p-3 text-left font-medium">Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((l) => (
                                <tr key={l.id} className="border-t">
                                    <td className="p-3 whitespace-nowrap">
                                        <div className="font-medium">
                                            {new Date(l.created_at).toLocaleString()}
                                        </div>
                                        {l.ip_address ? (
                                            <div className="text-xs text-muted-foreground">{l.ip_address}</div>
                                        ) : null}
                                    </td>
                                    <td className="p-3">
                                        <div className="font-medium">{l.action}</div>
                                        {l.auditable_type ? (
                                            <div className="text-xs text-muted-foreground">
                                                {l.auditable_type}#{l.auditable_id}
                                            </div>
                                        ) : null}
                                    </td>
                                    <td className="p-3">
                                        {l.user ? (
                                            <div>
                                                <div className="font-medium">{l.user.name}</div>
                                                <div className="text-xs text-muted-foreground">{l.user.email}</div>
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {l.client ? (
                                            <Link className="underline" href={`/clients/${l.client.id}`}>
                                                {l.client.first_name} {l.client.last_name}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {l.meta?.fields ? (
                                            <div className="text-xs text-muted-foreground">
                                                Fields: {Array.isArray(l.meta.fields) ? l.meta.fields.join(', ') : String(l.meta.fields)}
                                            </div>
                                        ) : l.meta ? (
                                            <div className="text-xs text-muted-foreground">
                                                {JSON.stringify(l.meta)}
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">—</span>
                                        )}
                                    </td>
                                </tr>
                            ))}

                            {logs.data.length === 0 ? (
                                <tr>
                                    <td colSpan={5} className="p-6 text-center text-muted-foreground">
                                        No audit logs found.
                                    </td>
                                </tr>
                            ) : null}
                        </tbody>
                    </table>
                </div>

                <div className="flex items-center justify-between">
                    <div className="text-xs text-muted-foreground">
                        Showing {logs.data.length} items
                    </div>
                    <Pagination links={logs.links} />
                </div>
            </div>
        </AppLayout>
    );
}
