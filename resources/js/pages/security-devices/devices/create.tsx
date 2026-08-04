import { PageHero } from '@/components/page';
import PageShell from '@/components/page-shell';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
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
    name: '',
    domain: '',
    category: '',
    subcategory: '',
    manufacturer: '',
    model: '',
    serial_number: '',
    mac_address: '',
    imei: '',
    asset_tag: '',
    firmware_version: '',
    ip_address: '',
    status: 'active',
    provider: '',
    location_description: '',
    notes: '',
};

export default function DeviceForm({
    taxonomy,
    domains,
    statuses,
    device,
    prefillDomain = '',
    isEdit = false,
}: Props) {
    const initial = device ?? {
        ...emptyDevice,
        domain: prefillDomain,
    };
    const { data, setData, post, put, processing, errors } =
        useForm<DeviceFormData>(initial);

    const categories = useMemo(() => {
        if (!data.domain || !taxonomy[data.domain]) return [];
        return Object.keys(taxonomy[data.domain]).map((slug) => ({
            value: slug,
            label: slug.replace(/_/g, ' '),
        }));
    }, [data.domain, taxonomy]);

    const subcategories = useMemo(() => {
        if (
            !data.domain ||
            !data.category ||
            !taxonomy[data.domain]?.[data.category]
        )
            return [];
        return Object.entries(taxonomy[data.domain][data.category]).map(
            ([slug, label]) => ({
                value: slug,
                label: label as string,
            }),
        );
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
            <Head
                title={`${isEdit ? 'Edit' : 'Register'} Device - Security & Devices`}
            />

            <PageShell>
                <PageHero
                    variant="compact"
                    title={isEdit ? `Edit: ${device?.name}` : 'Register Device'}
                    backHref="/security-devices/devices"
                    backLabel="Devices"
                />

                <form onSubmit={submit} className="space-y-6">
                    {/* Classification */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Classification</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <FormField
                                id="device-name"
                                label="Name"
                                error={errors.name}
                                required
                            >
                                <Input
                                    id="device-name"
                                    value={data.name}
                                    onChange={(e) =>
                                        setData('name', e.target.value)
                                    }
                                />
                            </FormField>

                            <FormField
                                id="device-domain"
                                label="Domain"
                                error={errors.domain}
                                required
                            >
                                <Select
                                    value={data.domain}
                                    onValueChange={(v) => {
                                        setData({
                                            ...data,
                                            domain: v,
                                            category: '',
                                            subcategory: '',
                                        });
                                    }}
                                >
                                    <SelectTrigger id="device-domain">
                                        <SelectValue placeholder="Select domain" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {domains.map((d) => (
                                            <SelectItem
                                                key={d.value}
                                                value={d.value}
                                            >
                                                {d.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>

                            <FormField
                                id="device-category"
                                label="Category"
                                error={errors.category}
                                required
                            >
                                <Select
                                    value={data.category}
                                    onValueChange={(v) => {
                                        setData({
                                            ...data,
                                            category: v,
                                            subcategory: '',
                                        });
                                    }}
                                    disabled={!data.domain}
                                >
                                    <SelectTrigger id="device-category">
                                        <SelectValue placeholder="Select category" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {categories.map((c) => (
                                            <SelectItem
                                                key={c.value}
                                                value={c.value}
                                            >
                                                {c.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>

                            <FormField
                                id="device-subcategory"
                                label="Subcategory"
                                error={errors.subcategory}
                            >
                                <Select
                                    value={data.subcategory || '_none'}
                                    onValueChange={(v) =>
                                        setData(
                                            'subcategory',
                                            v === '_none' ? '' : v,
                                        )
                                    }
                                    disabled={subcategories.length === 0}
                                >
                                    <SelectTrigger id="device-subcategory">
                                        <SelectValue placeholder="Select subcategory" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="_none">
                                            None
                                        </SelectItem>
                                        {subcategories.map((s) => (
                                            <SelectItem
                                                key={s.value}
                                                value={s.value}
                                            >
                                                {s.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>

                            <FormField
                                id="device-status"
                                label="Status"
                                error={errors.status}
                            >
                                <Select
                                    value={data.status}
                                    onValueChange={(v) => setData('status', v)}
                                >
                                    <SelectTrigger id="device-status">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {statuses.map((s) => (
                                            <SelectItem
                                                key={s.value}
                                                value={s.value}
                                            >
                                                {s.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </FormField>
                        </CardContent>
                    </Card>

                    {/* Hardware identifiers */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Hardware Identifiers</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            <FormField
                                id="device-manufacturer"
                                label="Manufacturer"
                                error={errors.manufacturer}
                            >
                                <Input
                                    id="device-manufacturer"
                                    value={data.manufacturer}
                                    onChange={(e) =>
                                        setData('manufacturer', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="device-model"
                                label="Model"
                                error={errors.model}
                            >
                                <Input
                                    id="device-model"
                                    value={data.model}
                                    onChange={(e) =>
                                        setData('model', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="device-serial-number"
                                label="Serial Number"
                                error={errors.serial_number}
                            >
                                <Input
                                    id="device-serial-number"
                                    value={data.serial_number}
                                    onChange={(e) =>
                                        setData('serial_number', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="device-mac-address"
                                label="MAC Address"
                                error={errors.mac_address}
                            >
                                <Input
                                    id="device-mac-address"
                                    value={data.mac_address}
                                    onChange={(e) =>
                                        setData('mac_address', e.target.value)
                                    }
                                    placeholder="AA:BB:CC:DD:EE:FF"
                                />
                            </FormField>
                            <FormField
                                id="device-imei"
                                label="IMEI"
                                error={errors.imei}
                            >
                                <Input
                                    id="device-imei"
                                    value={data.imei}
                                    onChange={(e) =>
                                        setData('imei', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="device-asset-tag"
                                label="Asset Tag"
                                error={errors.asset_tag}
                            >
                                <Input
                                    id="device-asset-tag"
                                    value={data.asset_tag}
                                    onChange={(e) =>
                                        setData('asset_tag', e.target.value)
                                    }
                                />
                            </FormField>
                            <FormField
                                id="device-firmware-version"
                                label="Firmware Version"
                                error={errors.firmware_version}
                            >
                                <Input
                                    id="device-firmware-version"
                                    value={data.firmware_version}
                                    onChange={(e) =>
                                        setData(
                                            'firmware_version',
                                            e.target.value,
                                        )
                                    }
                                />
                            </FormField>
                            <FormField
                                id="device-ip-address"
                                label="IP Address"
                                error={errors.ip_address}
                            >
                                <Input
                                    id="device-ip-address"
                                    value={data.ip_address}
                                    onChange={(e) =>
                                        setData('ip_address', e.target.value)
                                    }
                                    placeholder="192.168.1.1"
                                />
                            </FormField>
                        </CardContent>
                    </Card>

                    {/* Integration & location */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Integration & Location</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <FormField
                                id="device-provider"
                                label="Provider"
                                error={errors.provider}
                            >
                                <Input
                                    id="device-provider"
                                    value={data.provider}
                                    onChange={(e) =>
                                        setData('provider', e.target.value)
                                    }
                                    placeholder="e.g. unifi, queclink, hikvision, manual"
                                />
                            </FormField>
                            <FormField
                                id="device-location-description"
                                label="Location Description"
                                error={errors.location_description}
                            >
                                <Input
                                    id="device-location-description"
                                    value={data.location_description}
                                    onChange={(e) =>
                                        setData(
                                            'location_description',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="e.g. Server Room Rack A"
                                />
                            </FormField>
                        </CardContent>
                    </Card>

                    {/* Notes */}
                    <Card>
                        <CardHeader>
                            <CardTitle>Notes</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <textarea
                                aria-label="Notes"
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm ring-offset-background focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                rows={4}
                                value={data.notes}
                                onChange={(e) =>
                                    setData('notes', e.target.value)
                                }
                                placeholder="Optional notes about this device..."
                            />
                            {errors.notes && (
                                <p className="mt-1 text-sm text-destructive">
                                    {errors.notes}
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {/* Submit */}
                    <div className="flex justify-end gap-2">
                        <Button
                            variant="outline"
                            type="button"
                            onClick={() => window.history.back()}
                        >
                            Cancel
                        </Button>
                        <Button type="submit" disabled={processing}>
                            {processing
                                ? 'Saving...'
                                : isEdit
                                  ? 'Update Device'
                                  : 'Register Device'}
                        </Button>
                    </div>
                </form>
            </PageShell>
        </AppLayout>
    );
}

function FormField({
    id,
    label,
    error,
    required,
    children,
}: {
    id: string;
    label: string;
    error?: string;
    required?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div>
            <label htmlFor={id} className="mb-1.5 block text-sm font-medium">
                {label}
                {required && <span className="ml-0.5 text-destructive">*</span>}
            </label>
            {children}
            {error && <p className="mt-1 text-xs text-destructive">{error}</p>}
        </div>
    );
}
