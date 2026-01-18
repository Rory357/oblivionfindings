import AppLayout from '@/layouts/app-layout';
import { Head, useForm } from '@inertiajs/react';

export default function CreateClient() {
    const { data, setData, post, processing, errors } = useForm({
        first_name: '',
        last_name: '',
        status: 'active',
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/clients');
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
                { title: 'Add Client', href: '/clients/create' },
            ]}
        >
            <Head title="Add Client" />

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
                        {processing ? 'Saving…' : 'Create client'}
                    </button>
                </form>
            </div>
        </AppLayout>
    );
}
