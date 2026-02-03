import AppLayout from '@/layouts/app-layout';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Tabs } from '@/components/ui/tabs';
import { Label } from '@/components/ui/label';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import InputError from '@/components/input-error';
import { useMemo } from 'react';

type Asset = {
    id: number;
    qr_token?: string | null;
    qr_png_url?: string | null;
    qr_svg_url?: string | null;
    qr_download_url?: string | null;
    name: string;
    asset_tag?: string | null;
    status: string;
    risk_level: string;
    category?: string | null;
    description?: string | null;
    manufacturer?: string | null;
    model?: string | null;
    serial_number?: string | null;
    purchase_date?: string | null;
    warranty_expires_at?: string | null;
    location?: string | null;
    requires_inspection: boolean;
    inspection_due_at?: string | null;
    requires_maintenance: boolean;
    maintenance_due_at?: string | null;
    notes?: string | null;
    site?: { id: number; name: string } | null;
    client?: { id: number; name: string } | null;
};

export default function AssetShow() {
    const { asset, inspections, maintenance, documents, trackers, alerts, scan_events, geofences, can } = usePage().props as any;

    const a: Asset = asset;

    const inspectionForm = useForm({
        inspected_at: new Date().toISOString().slice(0, 16),
        result: 'pass',
        next_due_at: '',
        notes: '',
    });

    const maintenanceForm = useForm({
        performed_at: new Date().toISOString().slice(0, 16),
        type: '',
        vendor: '',
        cost: '',
        next_due_at: '',
        notes: '',
    });

    const docForm = useForm({
        file: null as any,
        title: '',
        category: '',
        version: '',
        effective_date: '',
        expiry_date: '',
        notes: '',
    });

    const trackerForm = useForm({
        vendor: '',
        device_uid: '',
        imei: '',
        serial_number: '',
        consent_id: '',
    });

    const headerBadges = useMemo(() => {
        const badges: string[] = [];
        if (a.asset_tag) badges.push(`#${a.asset_tag}`);
        badges.push(a.status);
        badges.push(a.risk_level);
        if (a.category) badges.push(a.category);
        return badges;
    }, [a]);

    function deleteAsset() {
        if (!confirm('Delete this asset?')) return;
        router.delete(`/assets/${a.id}`);
    }

    return (
        <AppLayout breadcrumbs={[{ title: 'Assets', href: '/assets' }, { title: a.name, href: `/assets/${a.id}` }]}>
            <Head title={a.name} />
            <div className="space-y-4 p-4">
                <div className="flex items-start justify-between gap-4">
                    <div className="min-w-0">
                        <h1 className="truncate text-xl font-semibold">{a.name}</h1>
                        <div className="mt-1 flex flex-wrap gap-2">
                            {headerBadges.map((b) => (
                                <span key={b} className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">
                                    {b}
                                </span>
                            ))}
                        </div>
                        <div className="mt-2 text-sm text-slate-500">
                            {a.site ? `Site: ${a.site.name}` : 'Site: —'}
                            {a.client ? ` • Client: ${a.client.name}` : ''}
                        </div>
                    </div>
                    <div className="flex shrink-0 items-center gap-2">
                        {can?.update ? (
                            <Link href={`/assets/${a.id}/edit`}>
                                <Button variant="secondary">Edit</Button>
                            </Link>
                        ) : null}
                        {can?.delete ? (
                            <Button variant="destructive" onClick={deleteAsset}>
                                Delete
                            </Button>
                        ) : null}
                    </div>
                </div>

                <Tabs
                    tabs={[
                        {
                            key: 'overview',
                            label: 'Overview',
                            content: (
                                <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">Details</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2 text-sm">
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Manufacturer</div>
                                                <div className="text-right">{a.manufacturer ?? '—'}</div>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Model</div>
                                                <div className="text-right">{a.model ?? '—'}</div>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Serial</div>
                                                <div className="text-right">{a.serial_number ?? '—'}</div>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Location</div>
                                                <div className="text-right">{a.location ?? '—'}</div>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Purchase date</div>
                                                <div className="text-right">{a.purchase_date ?? '—'}</div>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Warranty expires</div>
                                                <div className="text-right">{a.warranty_expires_at ?? '—'}</div>
                                            </div>
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">Compliance</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2 text-sm">
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Inspection required</div>
                                                <div className="text-right">{a.requires_inspection ? 'Yes' : 'No'}</div>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Inspection due</div>
                                                <div className="text-right">{a.inspection_due_at ?? '—'}</div>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Maintenance required</div>
                                                <div className="text-right">{a.requires_maintenance ? 'Yes' : 'No'}</div>
                                            </div>
                                            <div className="flex justify-between gap-3">
                                                <div className="text-slate-500">Maintenance due</div>
                                                <div className="text-right">{a.maintenance_due_at ?? '—'}</div>
                                            </div>

                                            {a.notes ? (
                                                <div className="pt-2">
                                                    <div className="text-slate-500">Notes</div>
                                                    <div className="mt-1 whitespace-pre-wrap rounded-md border p-2 text-sm">{a.notes}</div>
                                                </div>
                                            ) : null}

                                            {a.description ? (
                                                <div className="pt-2">
                                                    <div className="text-slate-500">Description</div>
                                                    <div className="mt-1 whitespace-pre-wrap rounded-md border p-2 text-sm">{a.description}</div>
                                                </div>
                                            ) : null}
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">QR Code</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-3">
                                            {a.qr_png_url ? (
                                                <div className="flex items-center justify-center rounded-md border bg-white p-4">
                                                    <img src={a.qr_png_url} alt="Asset QR" className="h-56 w-56" />
                                                </div>
                                            ) : (
                                                <div className="text-sm text-slate-500">QR code not available.</div>
                                            )}
                                            <div className="flex flex-wrap gap-2">
                                                {can?.downloadQr && a.qr_download_url ? (
                                                    <a href={a.qr_download_url}>
                                                        <Button variant="secondary">Download PNG</Button>
                                                    </a>
                                                ) : null}
                                                {can?.downloadQr && a.qr_svg_url ? (
                                                    <a href={a.qr_svg_url} target="_blank" rel="noreferrer">
                                                        <Button variant="secondary">Open SVG</Button>
                                                    </a>
                                                ) : null}
                                            </div>
                                            <div className="text-xs text-slate-500">Scan to open this asset in Oblivion Findings.</div>
                                        </CardContent>
                                    </Card>
                                </div>
                            ),
                        },
                        {
                            key: 'inspections',
                            label: `Inspections (${inspections?.length ?? 0})`,
                            content: (
                                <div className="space-y-4">
                                    {can?.recordInspection ? (
                                        <Card>
                                            <CardHeader>
                                                <CardTitle className="text-base">Record inspection</CardTitle>
                                            </CardHeader>
                                            <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                <div className="space-y-1">
                                                    <Label>Inspected at *</Label>
                                                    <Input
                                                        type="datetime-local"
                                                        value={inspectionForm.data.inspected_at}
                                                        onChange={(e) => inspectionForm.setData('inspected_at', e.target.value)}
                                                    />
                                                    <InputError message={inspectionForm.errors.inspected_at} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Result *</Label>
                                                    <Select value={inspectionForm.data.result} onValueChange={(v) => inspectionForm.setData('result', v as any)}>
                                                        <SelectTrigger>
                                                            <SelectValue />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            <SelectItem value="pass">Pass</SelectItem>
                                                            <SelectItem value="fail">Fail</SelectItem>
                                                            <SelectItem value="needs_followup">Needs follow up</SelectItem>
                                                        </SelectContent>
                                                    </Select>
                                                    <InputError message={inspectionForm.errors.result} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Next due</Label>
                                                    <Input
                                                        type="date"
                                                        value={inspectionForm.data.next_due_at}
                                                        onChange={(e) => inspectionForm.setData('next_due_at', e.target.value)}
                                                    />
                                                    <InputError message={inspectionForm.errors.next_due_at} />
                                                </div>
                                                <div className="space-y-1 md:col-span-2">
                                                    <Label>Notes</Label>
                                                    <Textarea value={inspectionForm.data.notes} onChange={(e) => inspectionForm.setData('notes', e.target.value)} />
                                                    <InputError message={inspectionForm.errors.notes} />
                                                </div>
                                                <div className="md:col-span-2">
                                                    <Button
                                                        onClick={() => inspectionForm.post(`/assets/${a.id}/inspections`, { preserveScroll: true })}
                                                        disabled={inspectionForm.processing}
                                                    >
                                                        Save inspection
                                                    </Button>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ) : null}

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">History</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {inspections?.length ? (
                                                inspections.map((i: any) => (
                                                    <div key={i.id} className="rounded-md border p-3">
                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                            <div className="text-sm font-medium">
                                                                {i.inspected_at} • {i.result}
                                                            </div>
                                                            <div className="text-xs text-slate-500">
                                                                {i.inspected_by ? i.inspected_by.name : '—'}
                                                            </div>
                                                        </div>
                                                        {i.next_due_at ? <div className="mt-1 text-xs text-slate-600">Next due: {i.next_due_at}</div> : null}
                                                        {i.notes ? (
                                                            <div className="mt-2 whitespace-pre-wrap rounded-md bg-slate-50 p-2 text-sm">{i.notes}</div>
                                                        ) : null}
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="text-sm text-slate-500">No inspections recorded.</div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </div>
                            ),
                        },
                        {
                            key: 'maintenance',
                            label: `Maintenance (${maintenance?.length ?? 0})`,
                            content: (
                                <div className="space-y-4">
                                    {can?.recordMaintenance ? (
                                        <Card>
                                            <CardHeader>
                                                <CardTitle className="text-base">Record maintenance</CardTitle>
                                            </CardHeader>
                                            <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                <div className="space-y-1">
                                                    <Label>Performed at *</Label>
                                                    <Input
                                                        type="datetime-local"
                                                        value={maintenanceForm.data.performed_at}
                                                        onChange={(e) => maintenanceForm.setData('performed_at', e.target.value)}
                                                    />
                                                    <InputError message={maintenanceForm.errors.performed_at} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Type</Label>
                                                    <Input value={maintenanceForm.data.type} onChange={(e) => maintenanceForm.setData('type', e.target.value)} />
                                                    <InputError message={maintenanceForm.errors.type} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Vendor</Label>
                                                    <Input value={maintenanceForm.data.vendor} onChange={(e) => maintenanceForm.setData('vendor', e.target.value)} />
                                                    <InputError message={maintenanceForm.errors.vendor} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Cost</Label>
                                                    <Input value={maintenanceForm.data.cost} onChange={(e) => maintenanceForm.setData('cost', e.target.value)} />
                                                    <InputError message={maintenanceForm.errors.cost} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Next due</Label>
                                                    <Input
                                                        type="date"
                                                        value={maintenanceForm.data.next_due_at}
                                                        onChange={(e) => maintenanceForm.setData('next_due_at', e.target.value)}
                                                    />
                                                    <InputError message={maintenanceForm.errors.next_due_at} />
                                                </div>
                                                <div className="space-y-1 md:col-span-2">
                                                    <Label>Notes</Label>
                                                    <Textarea value={maintenanceForm.data.notes} onChange={(e) => maintenanceForm.setData('notes', e.target.value)} />
                                                    <InputError message={maintenanceForm.errors.notes} />
                                                </div>
                                                <div className="md:col-span-2">
                                                    <Button
                                                        onClick={() => maintenanceForm.post(`/assets/${a.id}/maintenance`, { preserveScroll: true })}
                                                        disabled={maintenanceForm.processing}
                                                    >
                                                        Save maintenance
                                                    </Button>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ) : null}

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">History</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {maintenance?.length ? (
                                                maintenance.map((m: any) => (
                                                    <div key={m.id} className="rounded-md border p-3">
                                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                                            <div className="text-sm font-medium">{m.performed_at}</div>
                                                            <div className="text-xs text-slate-500">
                                                                {m.performed_by ? m.performed_by.name : '—'}
                                                            </div>
                                                        </div>
                                                        <div className="mt-1 text-xs text-slate-600">
                                                            {m.type ? `Type: ${m.type}` : 'Type: —'}
                                                            {m.vendor ? ` • Vendor: ${m.vendor}` : ''}
                                                            {m.cost ? ` • Cost: ${m.cost}` : ''}
                                                        </div>
                                                        {m.next_due_at ? <div className="mt-1 text-xs text-slate-600">Next due: {m.next_due_at}</div> : null}
                                                        {m.notes ? (
                                                            <div className="mt-2 whitespace-pre-wrap rounded-md bg-slate-50 p-2 text-sm">{m.notes}</div>
                                                        ) : null}
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="text-sm text-slate-500">No maintenance recorded.</div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </div>
                            ),
                        },
                        {
                            key: 'documents',
                            label: `Documents (${documents?.length ?? 0})`,
                            content: (
                                <div className="space-y-4">
                                    {can?.manageDocuments ? (
                                        <Card>
                                            <CardHeader>
                                                <CardTitle className="text-base">Upload document</CardTitle>
                                            </CardHeader>
                                            <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                <div className="space-y-1 md:col-span-2">
                                                    <Label>File *</Label>
                                                    <Input type="file" onChange={(e) => docForm.setData('file', (e.target as any).files?.[0] ?? null)} />
                                                    <InputError message={docForm.errors.file} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Title *</Label>
                                                    <Input value={docForm.data.title} onChange={(e) => docForm.setData('title', e.target.value)} />
                                                    <InputError message={docForm.errors.title} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Category</Label>
                                                    <Input value={docForm.data.category} onChange={(e) => docForm.setData('category', e.target.value)} />
                                                    <InputError message={docForm.errors.category} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Version</Label>
                                                    <Input value={docForm.data.version} onChange={(e) => docForm.setData('version', e.target.value)} />
                                                    <InputError message={docForm.errors.version} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Effective date</Label>
                                                    <Input
                                                        type="date"
                                                        value={docForm.data.effective_date}
                                                        onChange={(e) => docForm.setData('effective_date', e.target.value)}
                                                    />
                                                    <InputError message={docForm.errors.effective_date} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Expiry date</Label>
                                                    <Input
                                                        type="date"
                                                        value={docForm.data.expiry_date}
                                                        onChange={(e) => docForm.setData('expiry_date', e.target.value)}
                                                    />
                                                    <InputError message={docForm.errors.expiry_date} />
                                                </div>
                                                <div className="space-y-1 md:col-span-2">
                                                    <Label>Notes</Label>
                                                    <Textarea value={docForm.data.notes} onChange={(e) => docForm.setData('notes', e.target.value)} />
                                                    <InputError message={docForm.errors.notes} />
                                                </div>
                                                <div className="md:col-span-2">
                                                    <Button
                                                        onClick={() =>
                                                            docForm.post(`/assets/${a.id}/documents`, {
                                                                preserveScroll: true,
                                                                forceFormData: true,
                                                                onSuccess: () => docForm.reset('file', 'title', 'category', 'version', 'effective_date', 'expiry_date', 'notes'),
                                                            })
                                                        }
                                                        disabled={docForm.processing}
                                                    >
                                                        Upload
                                                    </Button>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ) : null}

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">Files</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {documents?.length ? (
                                                documents.map((d: any) => (
                                                    <div key={d.id} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                                        <div className="min-w-0">
                                                            <div className="text-sm font-medium">{d.title}</div>
                                                            <div className="mt-1 text-xs text-slate-500">
                                                                {d.category ? `Category: ${d.category}` : 'Category: —'}
                                                                {d.version ? ` • Version: ${d.version}` : ''}
                                                                {d.expiry_date ? ` • Expires: ${d.expiry_date}` : ''}
                                                            </div>
                                                            <div className="mt-1 text-xs text-slate-500">
                                                                {d.uploaded_by ? d.uploaded_by.name : '—'}
                                                                {d.original_name ? ` • ${d.original_name}` : ''}
                                                            </div>
                                                            {d.notes ? (
                                                                <div className="mt-2 whitespace-pre-wrap rounded-md bg-slate-50 p-2 text-sm">{d.notes}</div>
                                                            ) : null}
                                                        </div>
                                                        <div className="flex shrink-0 items-center gap-2">
                                                            <a href={d.download_url} className="text-sm text-blue-600 hover:underline">
                                                                Download
                                                            </a>
                                                            {can?.manageDocuments ? (
                                                                <button
                                                                    className="text-sm text-red-600 hover:underline"
                                                                    onClick={() => {
                                                                        if (!confirm('Delete this document?')) return;
                                                                        router.delete(`/assets/${a.id}/documents/${d.id}`, { preserveScroll: true });
                                                                    }}
                                                                >
                                                                    Delete
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="text-sm text-slate-500">No documents uploaded.</div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </div>
                            ),
                        },
                        {
                            key: 'tracking',
                            label: `Tracking (${trackers?.length ?? 0})`,
                            content: (
                                <div className="space-y-4">
                                    {can?.manageTrackers ? (
                                        <Card>
                                            <CardHeader>
                                                <CardTitle className="text-base">Pair tracker</CardTitle>
                                            </CardHeader>
                                            <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
                                                <div className="space-y-1">
                                                    <Label>Vendor</Label>
                                                    <Input value={trackerForm.data.vendor} onChange={(e) => trackerForm.setData('vendor', e.target.value)} />
                                                    <InputError message={trackerForm.errors.vendor} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Device UID</Label>
                                                    <Input value={trackerForm.data.device_uid} onChange={(e) => trackerForm.setData('device_uid', e.target.value)} />
                                                    <InputError message={trackerForm.errors.device_uid} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>IMEI</Label>
                                                    <Input value={trackerForm.data.imei} onChange={(e) => trackerForm.setData('imei', e.target.value)} />
                                                    <InputError message={trackerForm.errors.imei} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Serial number</Label>
                                                    <Input value={trackerForm.data.serial_number} onChange={(e) => trackerForm.setData('serial_number', e.target.value)} />
                                                    <InputError message={trackerForm.errors.serial_number} />
                                                </div>
                                                <div className="space-y-1">
                                                    <Label>Consent ID</Label>
                                                    <Input value={trackerForm.data.consent_id} onChange={(e) => trackerForm.setData('consent_id', e.target.value)} />
                                                    <InputError message={trackerForm.errors.consent_id} />
                                                </div>
                                                <div className="md:col-span-2">
                                                    <Button
                                                        onClick={() =>
                                                            trackerForm.post(`/assets/${a.id}/trackers/pair`, {
                                                                preserveScroll: true,
                                                                onSuccess: () => trackerForm.reset('vendor', 'device_uid', 'imei', 'serial_number', 'consent_id'),
                                                            })
                                                        }
                                                        disabled={trackerForm.processing}
                                                    >
                                                        Pair tracker
                                                    </Button>
                                                </div>
                                            </CardContent>
                                        </Card>
                                    ) : null}

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">Trackers</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {trackers?.length ? (
                                                trackers.map((t: any) => (
                                                    <div key={t.id} className="flex items-start justify-between gap-3 rounded-md border p-3">
                                                        <div className="min-w-0">
                                                            <div className="text-sm font-medium">
                                                                {t.vendor} â€¢ {t.device_uid}
                                                            </div>
                                                            <div className="mt-1 text-xs text-slate-500">
                                                                Status: {t.status}
                                                                {t.last_seen_at ? ` â€¢ Last seen: ${t.last_seen_at}` : ''}
                                                            </div>
                                                        </div>
                                                        {can?.manageTrackers && t.status === 'paired' ? (
                                                            <Button
                                                                variant="secondary"
                                                                onClick={() =>
                                                                    router.post(`/assets/${a.id}/trackers/${t.id}/unpair`, {}, { preserveScroll: true })
                                                                }
                                                            >
                                                                Unpair
                                                            </Button>
                                                        ) : null}
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="text-sm text-slate-500">No trackers paired.</div>
                                            )}
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">Recent alerts</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {alerts?.length ? (
                                                alerts.map((al: any) => (
                                                    <div key={al.id} className="rounded-md border p-3">
                                                        <div className="flex flex-wrap items-center gap-2 text-sm">
                                                            <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{al.alert_type}</span>
                                                            <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{al.severity}</span>
                                                            <span className="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-700">{al.status}</span>
                                                        </div>
                                                        <div className="mt-1 text-xs text-slate-500">{al.triggered_at ?? ''}</div>
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="text-sm text-slate-500">No alerts.</div>
                                            )}
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">Scan events</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {scan_events?.length ? (
                                                scan_events.map((s: any) => (
                                                    <div key={s.id} className="rounded-md border p-3 text-sm">
                                                        {s.scanned_at} â€¢ {s.qr_token}
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="text-sm text-slate-500">No scans recorded.</div>
                                            )}
                                        </CardContent>
                                    </Card>

                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">Geofences</CardTitle>
                                        </CardHeader>
                                        <CardContent className="space-y-2">
                                            {geofences?.length ? (
                                                geofences.map((g: any) => (
                                                    <div key={g.id} className="rounded-md border p-3 text-sm">
                                                        {g.name} â€¢ {g.type} â€¢ {g.breach_type} â€¢ {g.is_active ? 'active' : 'inactive'}
                                                    </div>
                                                ))
                                            ) : (
                                                <div className="text-sm text-slate-500">No geofences configured.</div>
                                            )}
                                        </CardContent>
                                    </Card>
                                </div>
                            ),
                        },
                    ]}
                />
            </div>
        </AppLayout>
    );
}
