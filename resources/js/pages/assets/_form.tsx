import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useMemo } from 'react';

type Site = { id: number; name: string };
type Client = {
    id: number;
    first_name: string;
    last_name: string;
    site_id?: number | null;
};
type AssetCategory = { id: number; name: string };

type Mode = 'create' | 'edit';

export default function AssetForm({ mode }: { mode: Mode }) {
    const props = usePage().props as any;

    const sites: Site[] = props.sites ?? [];
    const clients: Client[] = useMemo(
        () => props.clients ?? [],
        [props.clients],
    );
    const categories: AssetCategory[] = props.categories ?? [];
    const asset = props.asset ?? null;
    const prefill = props.prefill ?? {};

    const form = useForm({
        site_id: asset?.site_id ?? prefill.site_id ?? '',
        client_id: asset?.client_id ?? prefill.client_id ?? '',
        asset_tag: asset?.asset_tag ?? '',
        name: asset?.name ?? '',
        category: asset?.category ?? '',
        asset_category_id: asset?.asset_category_id ?? '',
        description: asset?.description ?? '',
        manufacturer: asset?.manufacturer ?? '',
        model: asset?.model ?? '',
        serial_number: asset?.serial_number ?? '',
        purchase_date: asset?.purchase_date ?? '',
        warranty_expires_at: asset?.warranty_expires_at ?? '',
        status: asset?.status ?? 'active',
        risk_level: asset?.risk_level ?? 'medium',
        location: asset?.location ?? '',
        requires_inspection: !!(asset?.requires_inspection ?? false),
        inspection_due_at: asset?.inspection_due_at ?? '',
        requires_maintenance: !!(asset?.requires_maintenance ?? false),
        maintenance_due_at: asset?.maintenance_due_at ?? '',
        notes: asset?.notes ?? '',
    });

    const selectedSiteId = form.data.site_id ? Number(form.data.site_id) : null;

    const filteredClients = useMemo(() => {
        if (!selectedSiteId) return clients;
        return clients.filter((c) => c.site_id === selectedSiteId);
    }, [clients, selectedSiteId]);

    function submit() {
        if (mode === 'create') {
            form.post('/assets');
        } else {
            form.put(`/assets/${asset.id}`);
        }
    }

    const title = mode === 'create' ? 'Create Asset' : 'Edit Asset';

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Assets', href: '/assets' },
                {
                    title,
                    href:
                        mode === 'create'
                            ? '/assets/create'
                            : `/assets/${asset?.id}/edit`,
                },
            ]}
        >
            <Head title={title} />
            <div className="space-y-4 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">{title}</h1>
                        <p className="text-sm text-muted-foreground">
                            Add site-level or client-level assets. Client assets
                            inherit the client’s site.
                        </p>
                    </div>
                    {mode === 'edit' && asset ? (
                        <Link href={`/assets/${asset.id}`}>
                            <Button variant="secondary">Back</Button>
                        </Link>
                    ) : (
                        <Link href="/assets">
                            <Button variant="secondary">Back</Button>
                        </Link>
                    )}
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">Ownership</CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div className="space-y-1">
                            <Label>Site</Label>
                            <Select
                                value={
                                    form.data.site_id
                                        ? String(form.data.site_id)
                                        : 'none'
                                }
                                onValueChange={(v) =>
                                    form.setData(
                                        'site_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select site (optional if client selected)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">—</SelectItem>
                                    {sites.map((s) => (
                                        <SelectItem
                                            key={s.id}
                                            value={String(s.id)}
                                        >
                                            {s.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.site_id} />
                        </div>

                        <div className="space-y-1">
                            <Label>Client</Label>
                            <Select
                                value={
                                    form.data.client_id
                                        ? String(form.data.client_id)
                                        : 'none'
                                }
                                onValueChange={(v) =>
                                    form.setData(
                                        'client_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select client (optional)" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">—</SelectItem>
                                    {filteredClients.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.first_name} {c.last_name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <div className="mt-1 text-xs text-muted-foreground">
                                If you select a client, the site will be set
                                automatically on save.
                            </div>
                            <InputError message={form.errors.client_id} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Asset details
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div className="space-y-1">
                            <Label>Name *</Label>
                            <Input
                                value={form.data.name}
                                onChange={(e) =>
                                    form.setData('name', e.target.value)
                                }
                            />
                            <InputError message={form.errors.name} />
                        </div>
                        <div className="space-y-1">
                            <Label>Asset tag</Label>
                            <Input
                                value={form.data.asset_tag}
                                onChange={(e) =>
                                    form.setData('asset_tag', e.target.value)
                                }
                            />
                            <InputError message={form.errors.asset_tag} />
                        </div>
                        <div className="space-y-1">
                            <Label>Category</Label>
                            <Input
                                value={form.data.category}
                                onChange={(e) =>
                                    form.setData('category', e.target.value)
                                }
                            />
                            <InputError message={form.errors.category} />
                        </div>
                        <div className="space-y-1">
                            <Label>Category type</Label>
                            <Select
                                value={
                                    form.data.asset_category_id
                                        ? String(form.data.asset_category_id)
                                        : 'none'
                                }
                                onValueChange={(v) =>
                                    form.setData(
                                        'asset_category_id',
                                        v === 'none' ? '' : v,
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue placeholder="Select category type" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="none">None</SelectItem>
                                    {categories.map((c) => (
                                        <SelectItem
                                            key={c.id}
                                            value={String(c.id)}
                                        >
                                            {c.name}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError
                                message={form.errors.asset_category_id}
                            />
                        </div>
                        <div className="space-y-1">
                            <Label>Status</Label>
                            <Select
                                value={form.data.status}
                                onValueChange={(v) =>
                                    form.setData('status', v as any)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="active">
                                        Active
                                    </SelectItem>
                                    <SelectItem value="out_of_service">
                                        Out of service
                                    </SelectItem>
                                    <SelectItem value="retired">
                                        Retired
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.status} />
                        </div>

                        <div className="space-y-1">
                            <Label>Risk level</Label>
                            <Select
                                value={form.data.risk_level}
                                onValueChange={(v) =>
                                    form.setData('risk_level', v as any)
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="low">Low</SelectItem>
                                    <SelectItem value="medium">
                                        Medium
                                    </SelectItem>
                                    <SelectItem value="high">High</SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError message={form.errors.risk_level} />
                        </div>

                        <div className="space-y-1">
                            <Label>Location</Label>
                            <Input
                                value={form.data.location}
                                onChange={(e) =>
                                    form.setData('location', e.target.value)
                                }
                            />
                            <InputError message={form.errors.location} />
                        </div>

                        <div className="space-y-1">
                            <Label>Manufacturer</Label>
                            <Input
                                value={form.data.manufacturer}
                                onChange={(e) =>
                                    form.setData('manufacturer', e.target.value)
                                }
                            />
                            <InputError message={form.errors.manufacturer} />
                        </div>
                        <div className="space-y-1">
                            <Label>Model</Label>
                            <Input
                                value={form.data.model}
                                onChange={(e) =>
                                    form.setData('model', e.target.value)
                                }
                            />
                            <InputError message={form.errors.model} />
                        </div>

                        <div className="space-y-1">
                            <Label>Serial number</Label>
                            <Input
                                value={form.data.serial_number}
                                onChange={(e) =>
                                    form.setData(
                                        'serial_number',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.serial_number} />
                        </div>
                        <div className="space-y-1">
                            <Label>Purchase date</Label>
                            <Input
                                type="date"
                                value={form.data.purchase_date}
                                onChange={(e) =>
                                    form.setData(
                                        'purchase_date',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError message={form.errors.purchase_date} />
                        </div>

                        <div className="space-y-1">
                            <Label>Warranty expires</Label>
                            <Input
                                type="date"
                                value={form.data.warranty_expires_at}
                                onChange={(e) =>
                                    form.setData(
                                        'warranty_expires_at',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.warranty_expires_at}
                            />
                        </div>

                        <div className="space-y-1 md:col-span-2">
                            <Label>Description</Label>
                            <Textarea
                                value={form.data.description}
                                onChange={(e) =>
                                    form.setData('description', e.target.value)
                                }
                            />
                            <InputError message={form.errors.description} />
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Inspection & maintenance
                        </CardTitle>
                    </CardHeader>
                    <CardContent className="grid grid-cols-1 gap-3 md:grid-cols-2">
                        <div className="flex items-start gap-2">
                            <Checkbox
                                checked={form.data.requires_inspection}
                                onCheckedChange={(v) =>
                                    form.setData('requires_inspection', !!v)
                                }
                            />
                            <div className="space-y-1">
                                <Label>Requires inspection</Label>
                                <div className="text-xs text-muted-foreground">
                                    Track an inspection due date and record
                                    inspection events.
                                </div>
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Inspection due</Label>
                            <Input
                                type="date"
                                value={form.data.inspection_due_at}
                                onChange={(e) =>
                                    form.setData(
                                        'inspection_due_at',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.inspection_due_at}
                            />
                        </div>

                        <div className="flex items-start gap-2">
                            <Checkbox
                                checked={form.data.requires_maintenance}
                                onCheckedChange={(v) =>
                                    form.setData('requires_maintenance', !!v)
                                }
                            />
                            <div className="space-y-1">
                                <Label>Requires maintenance</Label>
                                <div className="text-xs text-muted-foreground">
                                    Track a maintenance due date and record
                                    maintenance events.
                                </div>
                            </div>
                        </div>
                        <div className="space-y-1">
                            <Label>Maintenance due</Label>
                            <Input
                                type="date"
                                value={form.data.maintenance_due_at}
                                onChange={(e) =>
                                    form.setData(
                                        'maintenance_due_at',
                                        e.target.value,
                                    )
                                }
                            />
                            <InputError
                                message={form.errors.maintenance_due_at}
                            />
                        </div>

                        <div className="space-y-1 md:col-span-2">
                            <Label>Notes</Label>
                            <Textarea
                                value={form.data.notes}
                                onChange={(e) =>
                                    form.setData('notes', e.target.value)
                                }
                            />
                            <InputError message={form.errors.notes} />
                        </div>

                        <div className="md:col-span-2">
                            <Button onClick={submit} disabled={form.processing}>
                                {mode === 'create' ? 'Create' : 'Save changes'}
                            </Button>
                            {form.hasErrors ? (
                                <div className="mt-2 text-xs text-status-critical">
                                    Please fix the errors above.
                                </div>
                            ) : null}
                        </div>
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
