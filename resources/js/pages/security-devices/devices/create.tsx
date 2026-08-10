import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Head, useForm } from '@inertiajs/react';
import { useMemo } from 'react';

type FilterOption = { value: string; label: string };
type Taxonomy = Record<string, Record<string, Record<string, string>>>;

type Props = {
    taxonomy: Taxonomy;
    domains: FilterOption[];
    statuses: FilterOption[];
    device?: DeviceFormData;
    prefillDomain?: string;
    isEdit?: boolean;
};

type DeviceFormData = {
    id?: number;
    name: string;
    domain: string;
    category: string;
    subcategory: string;
    manufacturer: string;
    model: string;
    serial_number: string;
    mac_address: string;
    imei: string;
    asset_tag: string;
    firmware_version: string;
    ip_address: string;
    status: string;
    health_status?: string;
    provider: string;
    location_description: string;
    notes: string;
};

const emptyDevice: DeviceFormData = {
    name: '', domain: '', category: '', subcategory: '',
    manufacturer: '', model: '', serial_number: '', mac_address: '',
    imei: '', asset_tag: '', firmware_version: '', ip_address: '',
    status: 'active', provider: '', location_description: '', notes: '',
};

export default function DeviceForm({ taxonomy, domains, statuses, device, prefillDomain = '', isEdit = false }: Props) {
    const initial = device ?? {
        ...emptyDevice,
        domain: prefillDomain,
    };
    const { data, setData, post, put, processing, errors } = useForm<DeviceFormData>(initial);

    const categories = useMemo(() => {
        if (!data.domain || !taxonomy[data.domain]) return [];
        return Object.keys(taxonomy[data.domain]).map((slug) => ({
            value: slug,
            label: slug.replace(/_/g, ' '),
        }));
    }, [data.domain, taxonomy]);

    const subcategories = useMemo(() => {
        if (!data.domain || !data.category || !taxonomy[data.domain]?.[data.category]) return [];
        return Object.entries(taxonomy[data.domain][data.category]).map(([slug, label]) => ({
            value: slug,
            label: label as string,
        }));
    }, [data.domain, data.category, taxonomy]);

    function submit(e: React.FormEvent) {
        e.preventDefault();
        if (isEdit && device?.id) {
            put(`/security-devices/devices/${device.id}`);
        } else {
            post('/security-devices/devices');
        }
    }

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Security & Devices', href: '/security-devices' },
                { title: 'Devices', href: '/security-devices/devices' },
                { title: isEdit ? 'Edit' : 'Register Device' },
            ]}
        >
            <Head title={`${isEdit ? 'Edit' : 'Register'} Device - Security & Devices`} />

            <PageShell>
                <PageHeader
                    title={isEdit ? `Edit: ${device?.name}` : 'Register Device'}
                    backHref="/security-devices/devices"
                    backLabel="Devices"
                />

                <form onSubmit={submit} className="space-y-6">
                    {/* Classification */}
                    <Card>
                        <CardHeader><CardTitle>Classification</CardTitle></CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <FormField label="Name" error={errors.name} required>
                                <Input value={data.name} onChange={(e) => setData('name', e.target.value)} />
                            </FormField>

                            <FormField label="Domain" error={errors.domain} required>
                                <Select value={data.domain} onValueChange={(v) => { setData({ ...data, domain: v, category: '', subcategory: '' }); }}>
                                    <SelectTrigger><SelectValue placeholder="Select domain" /></SelectTrigger>
                                    <SelectContent>
                                        {domains.map((d) => <SelectItem key={d.value} value={d.value}>{d.label}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </FormField>

                            <FormField label="Category" error={errors.category} required>
                                <Select value={data.category} onValueChange={(v) => { setData({ ...data, category: v, subcategory: '' }); }} disabled={!data.domain}>
                                    <SelectTrigger><SelectValue placeholder="Select category" /></SelectTrigger>
                                    <SelectContent>
                                        {categories.map((c) => <SelectItem key={c.value} value={c.value}>{c.label}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </FormField>

                            <FormField label="Subcategory" error={errors.subcategory}>
                                <Select value={data.subcategory || '_none'} onValueChange={(v) => setData('subcategory', v === '_none' ? '' : v)} disabled={subcategories.length === 0}>
                                    <SelectTrigger><SelectValue placeholder="Select subcategory" /></SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="_none">None</SelectItem>
                                        {subcategories.map((s) => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </FormField>

                            <FormField label="Status" error={errors.status}>
                                <Select value={data.status} onValueChange={(v) => setData('status', v)}>
                                    <SelectTrigger><SelectValue /></SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((s) => <SelectItem key={s.value} value={s.value}>{s.label}</SelectItem>)}
                                    </SelectContent>
                                </Select>
                            </FormField>
                        </CardContent>
                    </Card>

                    {/* Hardware identifiers */}
                    <Card>
                        <CardHeader><CardTitle>Hardware Identifiers</CardTitle></CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <FormField label="Manufacturer" error={errors.manufacturer}>
                                <Input value={data.manufacturer} onChange={(e) => setData('manufacturer', e.target.value)} />
                            </FormField>
                            <FormField label="Model" error={errors.model}>
                                <Input value={data.model} onChange={(e) => setData('model', e.target.value)} />
                            </FormField>
                            <FormField label="Serial Number" error={errors.serial_number}>
                                <Input value={data.serial_number} onChange={(e) => setData('serial_number', e.target.value)} />
                            </FormField>
                            <FormField label="MAC Address" error={errors.mac_address}>
                                <Input value={data.mac_address} onChange={(e) => setData('mac_address', e.target.value)} placeholder="AA:BB:CC:DD:EE:FF" />
                            </FormField>
                            <FormField label="IMEI" error={errors.imei}>
                                <Input value={data.imei} onChange={(e) => setData('imei', e.target.value)} />
                            </FormField>
                            <FormField label="Asset Tag" error={errors.asset_tag}>
                                <Input value={data.asset_tag} onChange={(e) => setData('asset_tag', e.target.value)} />
                            </FormField>
                            <FormField label="Firmware Version" error={errors.firmware_version}>
                                <Input value={data.firmware_version} onChange={(e) => setData('firmware_version', e.target.value)} />
                            </FormField>
                            <FormField label="IP Address" error={errors.ip_address}>
                                <Input value={data.ip_address} onChange={(e) => setData('ip_address', e.target.value)} placeholder="192.168.1.1" />
                            </FormField>
                        </CardContent>
                    </Card>

                    {/* Integration & location */}
                    <Card>
                        <CardHeader><CardTitle>Integration & Location</CardTitle></CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <FormField label="Provider" error={errors.provider}>
                                <Input value={data.provider} onChange={(e) => setData('provider', e.target.value)} placeholder="e.g. unifi, queclink, hikvision, manual" />
                            </FormField>
                            <FormField label="Location Description" error={errors.location_description}>
                                <Input value={data.location_description} onChange={(e) => setData('location_description', e.target.value)} placeholder="e.g. Server Room Rack A" />
                            </FormField>
                        </CardContent>
                    </Card>

                    {/* Notes */}
                    <Card>
                        <CardHeader><CardTitle>Notes</CardTitle></CardHeader>
                        <CardContent>
                            <textarea
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                                rows={4}
                                value={data.notes}
                                onChange={(e) => setData('notes', e.target.value)}
                                placeholder="Optional notes about this device..."
                            />
                            {errors.notes && <p className="mt-1 text-sm text-destructive">{errors.notes}</p>}
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" type="button" onClick={() => window.history.back()}>
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing ? 'Saving...' : isEdit ? 'Update Device' : 'Register Device'}
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}

function FormField({ label, error, required, children }: {
    label: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label className="text-sm font-medium mb-1.5 block">
                {label}
                {required && <span className="text-destructive ml-0.5">*</span>}
            </label>
            {children}
            {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
        </div>
    );
}
