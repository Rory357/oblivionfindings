import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function EditClient({ client, sites = [] }) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const { data, setData, put, processing, errors } = useForm({
        site_id: (client.site_id ?? null) as number | null,
        first_name: client.first_name ?? '',
        last_name: client.last_name ?? '',
        status: client.status ?? 'active',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/clients/${client.id}`);
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: clientPlural, href: '/clients' },
                { title: `Edit ${clientSingular}`, href: `/clients/${client.id}/edit` },
            ]}
        >
            <Head title={`Edit ${clientSingular}`} />

            <div className="m-4">
                <form
                    onSubmit={submit}
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                >
                    <div>
                        <label className="text-sm font-medium">{siteSingular}</label>
                        <select
                            className="mt-1 w-full rounded-md border bg-transparent p-2"
                            value={data.site_id ?? ''}
                            onChange={(e) =>
                                setData(
                                    'site_id',
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                                )
                            }
                        >
                            <option value="">—</option>
                            {sites.map((s) => (
                                <option key={s.id} value={s.id}>
                                    {s.name}
                                    {s.is_active === false ? ' (inactive)' : ''}
                                </option>
                            ))}
                        </select>
                        {errors.site_id && (
                            <div className="mt-1 text-xs text-red-400">
                                {errors.site_id}
                            </div>
                        )}
                    </div>

                    <div>
                        <label className="text-sm font-medium">
                            First name
                        </label>
                        <input
                            className="mt-1 w-full rounded-md border bg-transparent p-2"
                            value={data.first_name}
                            onChange={(e) =>
                                setData('first_name', e.target.value)
                            }
                        />
                        {errors.first_name && (
                            <div className="mt-1 text-xs text-red-400">
                                {errors.first_name}
                            </div>
                        )}
                    </div>

                    <div>
                        <label className="text-sm font-medium">Last name</label>
                        <input
                            className="mt-1 w-full rounded-md border bg-transparent p-2"
                            value={data.last_name}
                            onChange={(e) =>
                                setData('last_name', e.target.value)
                            }
                        />
                        {errors.last_name && (
                            <div className="mt-1 text-xs text-red-400">
                                {errors.last_name}
                            </div>
                        )}
                    </div>

                    <div>
                        <label className="text-sm font-medium">Status</label>
                        <select
                            className="mt-1 w-full rounded-md border bg-transparent p-2"
                            value={data.status}
                            onChange={(e) => setData('status', e.target.value)}
                        >
                            <option value="active">active</option>
                            <option value="inactive">inactive</option>
                        </select>
                        {errors.status && (
                            <div className="mt-1 text-xs text-red-400">
                                {errors.status}
                            </div>
                        )}
                    </div>

                    <button
                        disabled={processing}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                        type="submit"
                    >
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </form>
            </div>
        </AppLayout>
    );
}
