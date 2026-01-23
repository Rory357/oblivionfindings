import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function CreateSite() {
    const { labels } = usePage().props as any;
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const sitePlural = labels?.['site.plural'] ?? 'Sites';

    const { data, setData, post, processing, errors } = useForm({
        name: '',
        address_line_1: '',
        address_line_2: '',
        suburb: '',
        city: '',
        postcode: '',
        country: 'New Zealand',
        is_active: true,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/sites');
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: sitePlural, href: '/sites' },
                { title: `Add ${siteSingular}`, href: '/sites/create' },
            ]}
        >
            <Head title={`Add ${siteSingular}`} />

            <div className="m-4">
                <form
                    onSubmit={submit}
                    className="max-w-2xl space-y-4 rounded-xl border p-4"
                >
                    <div>
                        <label className="text-sm font-medium">Name</label>
                        <input
                            className="mt-1 w-full rounded-md border bg-transparent p-2"
                            value={data.name}
                            onChange={(e) => setData('name', e.target.value)}
                        />
                        {errors.name && (
                            <div className="mt-1 text-xs text-red-400">{errors.name}</div>
                        )}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="text-sm font-medium">Address line 1</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.address_line_1}
                                onChange={(e) => setData('address_line_1', e.target.value)}
                            />
                            {errors.address_line_1 && (
                                <div className="mt-1 text-xs text-red-400">{errors.address_line_1}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Address line 2</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.address_line_2}
                                onChange={(e) => setData('address_line_2', e.target.value)}
                            />
                            {errors.address_line_2 && (
                                <div className="mt-1 text-xs text-red-400">{errors.address_line_2}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Suburb</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.suburb}
                                onChange={(e) => setData('suburb', e.target.value)}
                            />
                            {errors.suburb && (
                                <div className="mt-1 text-xs text-red-400">{errors.suburb}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">City</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.city}
                                onChange={(e) => setData('city', e.target.value)}
                            />
                            {errors.city && (
                                <div className="mt-1 text-xs text-red-400">{errors.city}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Postcode</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.postcode}
                                onChange={(e) => setData('postcode', e.target.value)}
                            />
                            {errors.postcode && (
                                <div className="mt-1 text-xs text-red-400">{errors.postcode}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Country</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.country}
                                onChange={(e) => setData('country', e.target.value)}
                            />
                            {errors.country && (
                                <div className="mt-1 text-xs text-red-400">{errors.country}</div>
                            )}
                        </div>
                    </div>

                    <div>
                        <label className="text-sm font-medium">Status</label>
                        <select
                            className="mt-1 w-full rounded-md border bg-transparent p-2"
                            value={data.is_active ? '1' : '0'}
                            onChange={(e) => setData('is_active', e.target.value === '1')}
                        >
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                        {errors.is_active && (
                            <div className="mt-1 text-xs text-red-400">{errors.is_active}</div>
                        )}
                    </div>

                    <button
                        disabled={processing}
                        className="rounded-md bg-indigo-600 px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                        type="submit"
                    >
                        {processing ? 'Saving…' : `Create ${siteSingular}`}
                    </button>
                </form>
            </div>
        </AppLayout>
    );
}
