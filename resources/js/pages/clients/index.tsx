import AppLayout from '@/layouts/app-layout';
import { Head, Link, usePage } from '@inertiajs/react';

export default function ClientsIndex({ clients }) {
    const { auth } = usePage().props as any;
    const can = auth?.can;

    const canCreate = !!can?.clients?.create;
    const canUpdate = !!can?.clients?.update;
    const canManage = canCreate || canUpdate;

    return (
        <AppLayout breadcrumbs={[{ title: 'Clients', href: '/clients' }]}>
            <Head title="Clients" />

            <div className="space-y-4">
                {/* Header */}
                <div className="flex items-center justify-between">
                    <h1 className="text-lg font-semibold">Clients</h1>

                    {canManage && (
                        <Link
                            href="/clients/create"
                            className="rounded-md bg-indigo-600 px-3 py-2 text-sm font-medium text-white hover:bg-indigo-500"
                        >
                            Add client
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

                            {canManage && (
                                <div className="flex items-center gap-2">
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
                                        Assign workers
                                    </Link>
                                </div>
                            )}
                        </div>
                    ))}

                    {clients.length === 0 && (
                        <div className="rounded-md border p-4 text-sm text-slate-500">
                            No clients found.
                        </div>
                    )}
                </div>
            </div>
        </AppLayout>
    );
}
