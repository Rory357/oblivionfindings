import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function ClientsIndex({ clients }) {
    const { auth } = usePage().props as any;
    const { labels } = usePage().props as any;
    const role = auth?.user?.role;
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const can = auth?.can;

    const canCreate = !!can?.clients?.create;
    const canUpdate = !!can?.clients?.update;
    const canManage = canCreate || canUpdate;

    const [query, setQuery] = useState('');

    const filteredClients = useMemo(() => {
        const q = query.trim().toLowerCase();
        if (!q) return clients;
        return clients.filter((c) => {
            const name = `${c.first_name ?? ''} ${c.last_name ?? ''}`
                .trim()
                .toLowerCase();
            const site = (c.site?.name ?? '').toLowerCase();
            const status = (c.status ?? '').toLowerCase();
            return (
                name.includes(q) ||
                site.includes(q) ||
                status.includes(q)
            );
        });
    }, [clients, query]);

    const breadcrumbs = useMemo(
        () => [
            { title: labels?.['client.plural'] ?? 'Clients', href: '/clients' },
        ],
        [labels],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={labels?.['client.plural'] ?? 'Clients'} />

            <PageShell>
                <PageHeader
                    title={labels?.['client.plural'] ?? 'Clients'}
                    description={
                        role === 'support_worker'
                            ? 'Only clients assigned to you are shown.'
                            : 'View and manage client profiles, documents, medical info, and assignments.'
                    }
                    actions={
                        canManage ? (
                            <Button asChild>
                                <Link href="/clients/create">
                                    Add {labels?.['client.singular'] ?? 'Client'}
                                </Link>
                            </Button>
                        ) : null
                    }
                />

                <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div className="max-w-md">
                        <Input
                            placeholder="Search clients…"
                            value={query}
                            onChange={(e) => setQuery(e.target.value)}
                        />
                    </div>
                    <div className="text-sm text-muted-foreground">
                        Showing {filteredClients.length} of {clients.length}
                    </div>
                </div>

                {/* List */}
                <div className="space-y-2">
                    {filteredClients.map((client) => (
                        <div
                            key={client.id}
                            className="flex flex-col gap-3 rounded-md border p-4 sm:flex-row sm:items-center sm:justify-between"
                        >
                            <div>
                                <div className="text-sm font-medium">
                                    {client.first_name} {client.last_name}
                                </div>
                                <div className="text-xs text-slate-500">
                                    Status: {client.status}
                                </div>
                                <div className="mt-1 text-xs text-slate-500">
                                    {siteSingular}:{' '}
                                    {client.site ? (
                                        can?.sites?.update ? (
                                            <Link
                                                href={`/sites/${client.site.id}/edit`}
                                                className="text-indigo-300 hover:text-indigo-200"
                                            >
                                                {client.site.name}
                                            </Link>
                                        ) : (
                                            <span className="text-slate-300">
                                                {client.site.name}
                                            </span>
                                        )
                                    ) : (
                                        <span className="text-slate-500">—</span>
                                    )}
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Button variant="outline" size="sm" asChild>
                                    <Link href={`/clients/${client.id}`}>View</Link>
                                </Button>

                                {canManage && (
                                    <>
                                        <Link
                                            href={`/clients/${client.id}/edit`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Edit
                                        </Link>

                                        <Link
                                            href={`/clients/${client.id}/assignments`}
                                            className="rounded-md border px-3 py-2 text-xs hover:bg-muted"
                                        >
                                            Assign{' '}
                                            {labels?.['worker.plural'] ??
                                                'Workers'}
                                        </Link>
                                    </>
                                )}
                            </div>
                        </div>
                    ))}

                    {clients.length === 0 && (
                        <div className="rounded-md border p-4 text-sm text-slate-500">
                            No{' '}
                            {labels?.['client.plural']?.toLowerCase() ??
                                'clients'}{' '}
                            found.
                        </div>
                    )}

                    {clients.length > 0 && filteredClients.length === 0 && (
                        <div className="rounded-md border p-4 text-sm text-muted-foreground">
                            No clients match your search.
                        </div>
                    )}
                </div>

                {canManage ? (
                    <div className="rounded-md border bg-muted/30 p-4 text-sm text-muted-foreground">
                        Tip: open a client profile to access tabs for medical, support plan, documents, portal users, and timeline.
                    </div>
                ) : null}
            </PageShell>

        </AppLayout>
    );
}
