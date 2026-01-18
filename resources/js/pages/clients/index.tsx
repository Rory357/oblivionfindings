import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import ClientViewModal from '@/pages/clients/client-view-modal';
import { Head, Link, usePage } from '@inertiajs/react';
import { useMemo, useState } from 'react';

export default function ClientsIndex({ clients }) {
    const { auth } = usePage().props as any;
    const { labels } = usePage().props as any;
    const can = auth?.can;

    const canCreate = !!can?.clients?.create;
    const canUpdate = !!can?.clients?.update;
    const canManage = canCreate || canUpdate;

    const [viewOpen, setViewOpen] = useState(false);
    const [selected, setSelected] = useState<any | null>(null);

    const breadcrumbs = useMemo(
        () => [
            { title: labels?.['client.plural'] ?? 'Clients', href: '/clients' },
        ],
        [labels],
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title={labels?.['client.plural'] ?? 'Clients'} />

            <div className="m-4 space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold">
                        {labels?.['client.plural'] ?? 'Clients'}
                    </h1>

                    {canManage && (
                        <Link
                            href="/clients/create"
                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            Add {labels?.['client.singular'] ?? 'Client'}
                        </Link>
                    )}
                </div>

                {/* List */}
                <div className="space-y-2">
                    {clients.map((client) => (
                        <div
                            key={client.id}
                            className="flex items-center justify-between rounded-md border p-3"
                        >
                            <div>
                                <div className="text-sm font-medium">
                                    {client.first_name} {client.last_name}
                                </div>
                                <div className="text-xs text-slate-500">
                                    Status: {client.status}
                                </div>
                            </div>

                            <div className="flex items-center gap-2">
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => {
                                        setSelected(client);
                                        setViewOpen(true);
                                    }}
                                >
                                    View
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
                </div>
            </div>

            <ClientViewModal
                open={viewOpen}
                onOpenChange={setViewOpen}
                client={selected}
                labels={labels ?? {}}
            />
        </AppLayout>
    );
}
