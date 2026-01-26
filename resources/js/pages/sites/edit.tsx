import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function EditSite({ site }) {
    const { labels } = usePage().props as any;
    const siteSingular = labels?.['site.singular'] ?? 'Site';
    const sitePlural = labels?.['site.plural'] ?? 'Sites';

    const { data, setData, put, processing, errors } = useForm({
        name: site.name ?? '',
        phone: site.phone ?? '',
        email: site.email ?? '',
        manager_name: site.manager_name ?? '',
        manager_phone: site.manager_phone ?? '',
        after_hours_phone: site.after_hours_phone ?? '',
        emergency_plan_location: site.emergency_plan_location ?? '',
        medication_storage_location: site.medication_storage_location ?? '',
        notes: site.notes ?? '',
        address_line_1: site.address_line_1 ?? '',
        address_line_2: site.address_line_2 ?? '',
        suburb: site.suburb ?? '',
        city: site.city ?? '',
        postcode: site.postcode ?? '',
        country: site.country ?? 'New Zealand',
        is_active: site.is_active ?? true,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        put(`/sites/${site.id}`);
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: sitePlural, href: '/sites' },
                { title: `Edit ${siteSingular}`, href: `/sites/${site.id}/edit` },
            ]}
        >
            <Head title={`Edit ${siteSingular}`} />

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
                            <label className="text-sm font-medium">Phone</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.phone}
                                onChange={(e) => setData('phone', e.target.value)}
                            />
                            {errors.phone && (
                                <div className="mt-1 text-xs text-red-400">{errors.phone}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Email</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.email}
                                onChange={(e) => setData('email', e.target.value)}
                            />
                            {errors.email && (
                                <div className="mt-1 text-xs text-red-400">{errors.email}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Manager name</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.manager_name}
                                onChange={(e) => setData('manager_name', e.target.value)}
                            />
                            {errors.manager_name && (
                                <div className="mt-1 text-xs text-red-400">{errors.manager_name}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Manager phone</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.manager_phone}
                                onChange={(e) => setData('manager_phone', e.target.value)}
                            />
                            {errors.manager_phone && (
                                <div className="mt-1 text-xs text-red-400">{errors.manager_phone}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">After-hours phone</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.after_hours_phone}
                                onChange={(e) => setData('after_hours_phone', e.target.value)}
                            />
                            {errors.after_hours_phone && (
                                <div className="mt-1 text-xs text-red-400">{errors.after_hours_phone}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Emergency plan location</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.emergency_plan_location}
                                onChange={(e) => setData('emergency_plan_location', e.target.value)}
                            />
                            {errors.emergency_plan_location && (
                                <div className="mt-1 text-xs text-red-400">{errors.emergency_plan_location}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Medication storage location</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.medication_storage_location}
                                onChange={(e) => setData('medication_storage_location', e.target.value)}
                            />
                            {errors.medication_storage_location && (
                                <div className="mt-1 text-xs text-red-400">{errors.medication_storage_location}</div>
                            )}
                        </div>

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
                        {processing ? 'Saving…' : 'Save changes'}
                    </button>
                </form>
            </div>
        </AppLayout>
    );
}
