import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, router } from '@inertiajs/react';
import { useMemo } from 'react';

type AuditLog = {
    id: number;
    action: string;
    description: string;
    event: string | null;
    module: string;
    created_at: string;
    actor?: { id: number; name: string } | null;
    client?: { id: number; name: string } | null;
    subject_type: string | null;
    subject_id: number | null;
    properties: {
        fields: string[];
        before: Record<string, unknown>;
        after: Record<string, unknown>;
    };
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
        module?: string;
        date_from?: string;
        date_to?: string;
        q?: string;
    };
    filter_options: {
        users: Array<{ id: number; name: string }>;
        clients: Array<{ id: number; name: string }>;
    };
};

const MODULES = [
    { value: 'all', label: 'All modules' },
    { value: 'operations', label: 'Operations' },
    { value: 'hr', label: 'HR' },
    { value: 'fleet', label: 'Fleet' },
    { value: 'settings', label: 'Settings' },
    { value: 'finance', label: 'Finance' },
    { value: 'it', label: 'IT & Support' },
    { value: 'security_devices', label: 'Security & Devices' },
    { value: 'monitoring', label: 'Monitoring' },
    { value: 'default', label: 'General' },
];

function Pagination({
    links,
}: {
    links?: Array<{ url: string | null; label: string; active: boolean }>;
}) {
    if (!links || links.length <= 3) return null;

    return (
        <div className="flex flex-wrap items-center gap-1">
            {links.map((l, idx) => {
                const label = l.label
                    .replace('&laquo;', '«')
                    .replace('&raquo;', '»');
                const disabled = !l.url;

                return (
                    <Button
                        key={idx}
                        variant={l.active ? 'default' : 'outline'}
                        size="sm"
                        disabled={disabled}
                        onClick={() => {
                            if (l.url)
                                router.visit(l.url, {
                                    preserveScroll: true,
                                    preserveState: true,
                                });
                        }}
                        dangerouslySetInnerHTML={{ __html: label }}
                    />
                );
            })}
        </div>
    );
}

export default function AuditIndex({ logs, filters, filter_options }: Props) {
    const breadcrumbs = useMemo(
        () => [{ title: 'Audit Logs', href: '/audit-logs' }],
        [],
    );

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

            <div className="space-y-4 p-4">
                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <div className="text-lg font-semibold">Audit Logs</div>
                        <div className="text-sm text-muted-foreground">
                            Application-wide read-only trace of access and
                            changes.
                        </div>
                    </div>
                </div>

                <div className="space-y-3 rounded-md border p-3">
                    <div className="grid grid-cols-1 gap-3 md:grid-cols-7">
                        <div className="space-y-1 md:col-span-2">
                            <div className="text-xs text-muted-foreground">
                                Search
                            </div>
                            <Input
                                value={filters.q ?? ''}
                                placeholder="action, user or client…"
                                onChange={(e) =>
                                    apply({ q: e.target.value || undefined })
                                }
                            />
                        </div>

                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">
                                Action contains
                            </div>
                            <Input
                                value={filters.action ?? ''}
                                placeholder="clients.view"
                                onChange={(e) =>
                                    apply({
                                        action: e.target.value || undefined,
                                    })
                                }
                            />
                        </div>

                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">
                                User
                            </div>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={filters.user_id ?? ''}
                                onChange={(e) =>
                                    apply({
                                        user_id: e.target.value
                                            ? Number(e.target.value)
                                            : undefined,
                                    })
                                }
                            >
                                <option value="">All</option>
                                {filter_options.users.map((u) => (
                                    <option key={u.id} value={u.id}>
                                        {u.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">
                                Client
                            </div>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={filters.client_id ?? ''}
                                onChange={(e) =>
                                    apply({
                                        client_id: e.target.value
                                            ? Number(e.target.value)
                                            : undefined,
                                    })
                                }
                            >
                                <option value="">All</option>
                                {filter_options.clients.map((c) => (
                                    <option key={c.id} value={c.id}>
                                        {c.name}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">
                                Module
                            </div>
                            <select
                                className="w-full rounded-md border bg-background p-2 text-sm"
                                value={filters.module ?? 'all'}
                                onChange={(e) =>
                                    apply({
                                        module:
                                            e.target.value === 'all'
                                                ? undefined
                                                : e.target.value,
                                    })
                                }
                            >
                                {MODULES.map((module) => (
                                    <option
                                        key={module.value}
                                        value={module.value}
                                    >
                                        {module.label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="space-y-1">
                            <div className="text-xs text-muted-foreground">
                                From
                            </div>
                            <Input
                                type="date"
                                value={filters.date_from ?? ''}
                                onChange={(e) =>
                                    apply({
                                        date_from: e.target.value || undefined,
                                    })
                                }
                            />
                        </div>
                    </div>

                    <div className="grid grid-cols-1 gap-3 md:grid-cols-6">
                        <div className="space-y-1 md:col-span-2">
                            <div className="text-xs text-muted-foreground">
                                To
                            </div>
                            <Input
                                type="date"
                                value={filters.date_to ?? ''}
                                onChange={(e) =>
                                    apply({
                                        date_to: e.target.value || undefined,
                                    })
                                }
                            />
                        </div>

                        <div className="flex items-end justify-end gap-2 md:col-span-4">
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.get(
                                        '/audit-logs',
                                        {},
                                        { preserveState: true, replace: true },
                                    )
                                }
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
                                <th className="p-3 text-left font-medium">
                                    When
                                </th>
                                <th className="p-3 text-left font-medium">
                                    Action
                                </th>
                                <th className="p-3 text-left font-medium">
                                    User
                                </th>
                                <th className="p-3 text-left font-medium">
                                    Client
                                </th>
                                <th className="p-3 text-left font-medium">
                                    Details
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {logs.data.map((l) => (
                                <tr key={l.id} className="border-t">
                                    <td className="p-3 whitespace-nowrap">
                                        <div className="font-medium">
                                            {new Date(
                                                l.created_at,
                                            ).toLocaleString()}
                                        </div>
                                    </td>
                                    <td className="p-3">
                                        <div className="font-medium">
                                            {l.action}
                                        </div>
                                        {l.subject_type ? (
                                            <div className="text-xs text-muted-foreground">
                                                {l.subject_type}
                                                {l.subject_id
                                                    ? `#${l.subject_id}`
                                                    : ''}
                                            </div>
                                        ) : null}
                                    </td>
                                    <td className="p-3">
                                        {l.actor ? (
                                            <div className="font-medium">
                                                {l.actor.name}
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {l.client ? (
                                            <Link
                                                className="underline"
                                                href={`/clients/${l.client.id}`}
                                            >
                                                {l.client.name}
                                            </Link>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                    <td className="p-3">
                                        {l.properties.fields.length > 0 ? (
                                            <div className="text-xs text-muted-foreground">
                                                Fields:{' '}
                                                {l.properties.fields.join(', ')}
                                            </div>
                                        ) : (
                                            <span className="text-muted-foreground">
                                                —
                                            </span>
                                        )}
                                    </td>
                                </tr>
                            ))}

                            {logs.data.length === 0 ? (
                                <tr>
                                    <td
                                        colSpan={5}
                                        className="p-6 text-center text-muted-foreground"
                                    >
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
