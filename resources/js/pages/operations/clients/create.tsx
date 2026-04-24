import AppLayout from '@/layouts/app-layout';
import { Head, useForm, usePage } from '@inertiajs/react';

export default function CreateClient({
    sites = [],
    serviceContexts = [],
    defaultServiceContextId = null,
}: {
    sites: any[];
    serviceContexts?: any[];
    defaultServiceContextId?: number | null;
}) {
    const { labels } = usePage().props as any;
    const clientSingular = labels?.['client.singular'] ?? 'Client';
    const clientPlural = labels?.['client.plural'] ?? 'Clients';
    const siteSingular = labels?.['site.singular'] ?? 'Site';

    const { data, setData, post, processing, errors } = useForm({
        site_id: null as number | null,
        service_context_id: (defaultServiceContextId ?? null) as number | null,
        first_name: '',
        last_name: '',
        preferred_name: '',
        date_of_birth: '',
        gender: '',
        status: 'active',
        phone: '',
        email: '',
        address_line_1: '',
        address_line_2: '',
        suburb: '',
        city: '',
        postcode: '',
        funding_type: '',
        funding_notes: '',
        create_client_portal_user: false,
    });

    function submit(e: React.FormEvent) {
        e.preventDefault();
        post('/clients');
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: clientPlural, href: '/clients' },
                { title: `Add ${clientSingular}`, href: '/clients/create' },
            ]}
        >
            <Head title={`Add ${clientSingular}`} />

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
                                </option>
                            ))}
                        </select>
                        {errors.site_id && (
                            <div className="mt-1 text-xs text-status-critical">
                                {errors.site_id}
                            </div>
                        )}
                    </div>

                    <div>
                        <label className="text-sm font-medium">Service context</label>
                        <select
                            className="mt-1 w-full rounded-md border bg-transparent p-2"
                            value={data.service_context_id ?? ''}
                            onChange={(e) =>
                                setData(
                                    'service_context_id',
                                    e.target.value === ''
                                        ? null
                                        : Number(e.target.value),
                                )
                            }
                        >
                            <option value="">—</option>
                            {serviceContexts.map((sc) => (
                                <option key={sc.id} value={sc.id}>
                                    {sc.name}
                                </option>
                            ))}
                        </select>
                        {errors.service_context_id && (
                            <div className="mt-1 text-xs text-status-critical">
                                {errors.service_context_id}
                            </div>
                        )}
                        <div className="mt-1 text-xs text-muted-foreground">
                            Residential / home support / respite classification (used for audit and reporting).
                        </div>
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
                            <div className="mt-1 text-xs text-status-critical">
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
                            <div className="mt-1 text-xs text-status-critical">
                                {errors.last_name}
                            </div>
                        )}
                    </div>

                    <div>
                        <label className="text-sm font-medium">Preferred name</label>
                        <input
                            className="mt-1 w-full rounded-md border bg-transparent p-2"
                            value={data.preferred_name}
                            onChange={(e) => setData('preferred_name', e.target.value)}
                        />
                        {errors.preferred_name && (
                            <div className="mt-1 text-xs text-status-critical">{errors.preferred_name}</div>
                        )}
                    </div>

                    <div className="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label className="text-sm font-medium">Date of birth</label>
                            <input
                                type="date"
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.date_of_birth}
                                onChange={(e) => setData('date_of_birth', e.target.value)}
                            />
                            {errors.date_of_birth && (
                                <div className="mt-1 text-xs text-status-critical">{errors.date_of_birth}</div>
                            )}
                        </div>

                        <div>
                            <label className="text-sm font-medium">Gender</label>
                            <input
                                className="mt-1 w-full rounded-md border bg-transparent p-2"
                                value={data.gender}
                                onChange={(e) => setData('gender', e.target.value)}
                            />
                            {errors.gender && (
                                <div className="mt-1 text-xs text-status-critical">{errors.gender}</div>
                            )}
                        </div>
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
                            <div className="mt-1 text-xs text-status-critical">
                                {errors.status}
                            </div>
                        )}
                    </div>

                    <div className="rounded-lg border p-3">
                        <div className="text-sm font-medium">Contact</div>
                        <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
                            <div>
                                <label className="text-sm font-medium">Phone</label>
                                <input
                                    className="mt-1 w-full rounded-md border bg-transparent p-2"
                                    value={data.phone}
                                    onChange={(e) => setData('phone', e.target.value)}
                                />
                                {errors.phone && (
                                    <div className="mt-1 text-xs text-status-critical">{errors.phone}</div>
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
                                    <div className="mt-1 text-xs text-status-critical">{errors.email}</div>
                                )}
                            </div>
                        </div>
                    </div>

                    <div className="rounded-lg border p-3">
                        <div className="text-sm font-medium">Portal Access (Client)</div>
                        <label className="mt-3 flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                checked={data.create_client_portal_user}
                                onChange={(e) => setData('create_client_portal_user', e.target.checked)}
                            />
                            Create login account for this {clientSingular.toLowerCase()}
                        </label>
                        <div className="mt-2 text-xs text-muted-foreground">
                            Uses the Contact email above. Contact email is required when this is checked.
                        </div>
                    </div>

                    <div className="rounded-lg border p-3">
                        <div className="text-sm font-medium">Address</div>
                        <div className="mt-3 space-y-3">
                            <div>
                                <label className="text-sm font-medium">Address line 1</label>
                                <input
                                    className="mt-1 w-full rounded-md border bg-transparent p-2"
                                    value={data.address_line_1}
                                    onChange={(e) => setData('address_line_1', e.target.value)}
                                />
                                {errors.address_line_1 && (
                                    <div className="mt-1 text-xs text-status-critical">{errors.address_line_1}</div>
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
                                    <div className="mt-1 text-xs text-status-critical">{errors.address_line_2}</div>
                                )}
                            </div>
                            <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                <div>
                                    <label className="text-sm font-medium">Suburb</label>
                                    <input
                                        className="mt-1 w-full rounded-md border bg-transparent p-2"
                                        value={data.suburb}
                                        onChange={(e) => setData('suburb', e.target.value)}
                                    />
                                    {errors.suburb && (
                                        <div className="mt-1 text-xs text-status-critical">{errors.suburb}</div>
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
                                        <div className="mt-1 text-xs text-status-critical">{errors.city}</div>
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
                                        <div className="mt-1 text-xs text-status-critical">{errors.postcode}</div>
                                    )}
                                </div>
                            </div>
                        </div>
                    </div>

                    <div className="rounded-lg border p-3">
                        <div className="text-sm font-medium">Funding</div>
                        <div className="mt-3 space-y-3">
                            <div>
                                <label className="text-sm font-medium">Funding type</label>
                                <input
                                    className="mt-1 w-full rounded-md border bg-transparent p-2"
                                    value={data.funding_type}
                                    onChange={(e) => setData('funding_type', e.target.value)}
                                />
                                {errors.funding_type && (
                                    <div className="mt-1 text-xs text-status-critical">{errors.funding_type}</div>
                                )}
                            </div>
                            <div>
                                <label className="text-sm font-medium">Funding notes</label>
                                <textarea
                                    className="mt-1 w-full rounded-md border bg-transparent p-2"
                                    rows={4}
                                    value={data.funding_notes}
                                    onChange={(e) => setData('funding_notes', e.target.value)}
                                />
                                {errors.funding_notes && (
                                    <div className="mt-1 text-xs text-status-critical">{errors.funding_notes}</div>
                                )}
                            </div>
                        </div>
                    </div>

                    <button
                        disabled={processing}
                        className="rounded-md bg-primary px-4 py-2 text-sm font-medium text-white disabled:opacity-60"
                        type="submit"
                    >
                        {processing ? 'Saving…' : 'Create client'}
                    </button>
                </form>
            </div>
        </AppLayout>
    );
}
