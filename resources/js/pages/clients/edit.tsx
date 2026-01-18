import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';

export default function EditClient({ client }) {
    const { data, setData, put, processing, errors } = useForm({
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
                { title: 'Clients', href: '/clients' },
                { title: 'Edit Client', href: `/clients/${client.id}/edit` },
            ]}
        >
            <Head title="Edit Client" />

            <div className="m-4">
                <form
                    onSubmit={submit}
                    className="max-w-xl space-y-4 rounded-xl border p-4"
                >
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
