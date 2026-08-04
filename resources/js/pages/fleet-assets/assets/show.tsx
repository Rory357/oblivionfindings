import {
    AssetFinanceTechnologyProjectionPanel,
    type AssetFinanceTechnologyProjection,
} from '@/components/assets/asset-finance-technology-projection';
import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import PageShell from '@/components/page-shell';
import { Badge } from '@/components/ui/badge';
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
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { WizardShell, WizardStepPane } from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDate, formatDateTime, formatDistance } from '@/lib/fleet-utils';
import { cn } from '@/lib/utils';
import { AssetWizardDialog } from '@/pages/fleet-assets/assets/components/asset-wizard-dialog';
import { FleetCompactHero } from '@/pages/fleet-assets/components/fleet-compact-hero';
import { Head, Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle,
    Cpu,
    Download,
    Edit,
    ExternalLink,
    FileText,
    Info,
    LockKeyhole,
    MapPin,
    Package,
    Shield,
    Upload,
    UserPlus,
    Wrench,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type TimelineEvent = {
    id: number;
    type: string;
    summary: string;
    date: string | null;
};

type Document = {
    id: number;
    name: string;
    type: string;
    uploaded_at: string | null;
    url: string;
};

const uploadDocumentSteps = [
    {
        key: 'file',
        label: 'File & type',
        blurb: 'Choose and describe the document',
        icon: Upload,
    },
    {
        key: 'review',
        label: 'Review',
        blurb: 'Confirm before uploading',
        icon: CheckCircle,
    },
] as const;

export function UploadAssetDocumentWizard({
    open,
    file,
    title,
    category,
    error,
    submitting,
    onFileChange,
    onTitleChange,
    onCategoryChange,
    onClose,
    onSubmit,
}: {
    open: boolean;
    file: File | null;
    title: string;
    category: string;
    error: string;
    submitting: boolean;
    onFileChange: (file: File | null) => void;
    onTitleChange: (title: string) => void;
    onCategoryChange: (category: string) => void;
    onClose: () => void;
    onSubmit: () => void;
}) {
    const [stepIndex, setStepIndex] = useState(0);
    const canReview = file !== null && title.trim().length > 0;
    const categoryLabel = category.replace(/_/g, ' ');
    const close = () => {
        setStepIndex(0);
        onClose();
    };

    return (
        <WizardShell
            open={open}
            onClose={close}
            title="Upload asset document"
            description="Attach a file to this Fleet asset and review its document details before uploading."
            railIcon={Upload}
            railTitle="Asset document"
            railSub="Fleet record"
            steps={uploadDocumentSteps}
            stepIndex={stepIndex}
            onStepClick={(index) => {
                if (index === 0 || canReview) setStepIndex(index);
            }}
            footerStart={
                <Button
                    type="button"
                    variant="outline"
                    onClick={close}
                    disabled={submitting}
                >
                    Cancel
                </Button>
            }
            footerEnd={
                stepIndex === 0 ? (
                    <Button
                        type="button"
                        disabled={!canReview || submitting}
                        onClick={() => setStepIndex(1)}
                    >
                        Continue
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setStepIndex(0)}
                            disabled={submitting}
                        >
                            Back
                        </Button>
                        <Button
                            type="button"
                            onClick={onSubmit}
                            disabled={submitting}
                        >
                            {submitting ? 'Uploading…' : 'Upload document'}
                        </Button>
                    </>
                )
            }
        >
            {stepIndex === 0 ? (
                <WizardStepPane>
                    <div className="space-y-5">
                        <div className="space-y-1.5">
                            <label
                                htmlFor="asset-document-file"
                                className="text-sm font-medium"
                            >
                                File
                            </label>
                            <Input
                                id="asset-document-file"
                                type="file"
                                onChange={(event) =>
                                    onFileChange(
                                        event.target.files?.[0] ?? null,
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label
                                htmlFor="asset-document-title"
                                className="text-sm font-medium"
                            >
                                Title
                            </label>
                            <Input
                                id="asset-document-title"
                                value={title}
                                onChange={(event) =>
                                    onTitleChange(event.target.value)
                                }
                                placeholder="e.g. Registration certificate"
                                required
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label
                                htmlFor="asset-document-category"
                                className="text-sm font-medium"
                            >
                                Category
                            </label>
                            <Select
                                value={category}
                                onValueChange={onCategoryChange}
                            >
                                <SelectTrigger id="asset-document-category">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="manual">
                                        Manual
                                    </SelectItem>
                                    <SelectItem value="compliance">
                                        Compliance
                                    </SelectItem>
                                    <SelectItem value="photo">Photo</SelectItem>
                                    <SelectItem value="service">
                                        Service record
                                    </SelectItem>
                                    <SelectItem value="other">Other</SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        {error ? (
                            <p className="text-sm text-destructive">{error}</p>
                        ) : null}
                    </div>
                </WizardStepPane>
            ) : (
                <WizardStepPane>
                    <dl className="space-y-3 rounded-xl border border-border bg-card/70 p-4 text-sm">
                        <div>
                            <dt className="text-muted-foreground">File</dt>
                            <dd className="font-medium">{file?.name}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Title</dt>
                            <dd className="font-medium">{title.trim()}</dd>
                        </div>
                        <div>
                            <dt className="text-muted-foreground">Category</dt>
                            <dd className="font-medium capitalize">
                                {categoryLabel}
                            </dd>
                        </div>
                    </dl>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

type WorkOrder = {
    id: number;
    title: string;
    status: string;
    priority: string;
    category: string;
    due_at: string | null;
    assigned_to: string | null;
};

type Inspection = {
    id: number;
    type: string;
    result: string;
    inspected_at: string;
    inspector: string | null;
    notes: string | null;
};

/** Linked device from canonical Security & Devices registry via device_asset_links. */
export type LinkedDevice = {
    id: number;
    device_uid: string;
    name: string | null;
    vendor: string | null;
    status: string | null;
    health_status: string | null;
    last_seen_at: string | null;
    battery_level: number | null;
    link_type: string | null;
    linked_at: string | null;
    detail_url: string | null;
    // Legacy compat fields.
    imei?: string | null;
    serial_number?: string | null;
    lat?: number | null;
    lng?: number | null;
    speed_kph?: number | null;
    battery_pct?: number | null;
};

export function AssetDeviceStatusCard({
    linkedDevices,
    canViewTechnology,
    devicesHref,
}: {
    linkedDevices: LinkedDevice[];
    canViewTechnology: boolean;
    devicesHref: string | null;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="flex items-center gap-2 text-base">
                    <Cpu className="h-4 w-4" />
                    Device Status
                </CardTitle>
            </CardHeader>
            <CardContent>
                {!canViewTechnology ? (
                    <div className="py-6 text-center text-muted-foreground">
                        <LockKeyhole className="mx-auto mb-2 h-8 w-8 opacity-50" />
                        <p className="text-sm font-medium text-foreground">
                            Technology access restricted
                        </p>
                        <p className="mx-auto mt-1 max-w-sm text-xs">
                            Security &amp; Devices permission is required to
                            view installed technology. This does not mean the
                            Asset has no linked Devices.
                        </p>
                    </div>
                ) : linkedDevices.length > 0 ? (
                    <div className="space-y-2">
                        {linkedDevices.map((device) => (
                            <a
                                key={device.id}
                                href={
                                    device.detail_url ??
                                    `/security-devices/devices/${device.id}`
                                }
                                className="frontline-tap flex items-center justify-between rounded-md border p-3 text-sm transition-colors hover:bg-muted/50 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex items-center gap-2">
                                        <span className="font-medium">
                                            {device.name ?? device.device_uid}
                                        </span>
                                        <Badge
                                            variant="outline"
                                            className="font-mono text-[10px]"
                                        >
                                            {device.device_uid}
                                        </Badge>
                                        {device.link_type && (
                                            <Badge
                                                variant="outline"
                                                className="text-[10px]"
                                            >
                                                {device.link_type.replace(
                                                    /_/g,
                                                    ' ',
                                                )}
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                        {device.vendor && (
                                            <span>{device.vendor}</span>
                                        )}
                                        {device.last_seen_at && (
                                            <span>
                                                Seen:{' '}
                                                {formatDateTime(
                                                    device.last_seen_at,
                                                )}
                                            </span>
                                        )}
                                        {device.battery_level !== null &&
                                            device.battery_level !==
                                                undefined && (
                                                <span>
                                                    Battery:{' '}
                                                    {device.battery_level}%
                                                </span>
                                            )}
                                    </div>
                                </div>
                                <div className="flex shrink-0 flex-col items-end gap-1">
                                    <Badge
                                        variant={
                                            device.status === 'active'
                                                ? 'default'
                                                : device.status === 'offline'
                                                  ? 'secondary'
                                                  : 'outline'
                                        }
                                        className="text-[10px]"
                                    >
                                        {device.status?.replace(/_/g, ' ') ??
                                            'unknown'}
                                    </Badge>
                                    {device.health_status && (
                                        <Badge
                                            variant={
                                                device.health_status ===
                                                'healthy'
                                                    ? 'default'
                                                    : device.health_status ===
                                                        'critical'
                                                      ? 'destructive'
                                                      : 'outline'
                                            }
                                            className="text-[10px]"
                                        >
                                            {device.health_status}
                                        </Badge>
                                    )}
                                </div>
                            </a>
                        ))}
                    </div>
                ) : (
                    <div className="py-6 text-center text-muted-foreground">
                        <Cpu className="mx-auto mb-2 h-8 w-8 opacity-40" />
                        <p className="text-sm font-medium text-foreground">
                            No linked devices
                        </p>
                        <p className="mt-1 text-xs">
                            No canonical Devices are currently linked to this
                            Asset.
                        </p>
                        {devicesHref && (
                            <a
                                href={devicesHref}
                                className="frontline-tap mt-3 inline-flex items-center rounded-md text-sm font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                Open Security &amp; Devices
                            </a>
                        )}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

type Alert = {
    id: number;
    alert_type: string;
    severity: string;
    status: string;
    triggered_at: string | null;
    resolved_at: string | null;
};

type Assignment = {
    id: number;
    assignee: { id: number; name: string };
    assigned_at: string;
    returned_at: string | null;
    purpose: string | null;
};

type ServiceSchedule = {
    id: number;
    name: string;
    interval_km: number | null;
    interval_days: number | null;
    last_completed_at: string | null;
    next_due_at: string | null;
};

type Props = {
    asset: {
        id: number;
        name: string;
        asset_tag: string;
        category: string;
        status: string;
        risk_level: string | null;
        description: string | null;
        manufacturer: string | null;
        model: string | null;
        serial_number: string | null;
        location: string | null;
        registration_number: string | null;
        registration_expires_at: string | null;
        wof_expires_at: string | null;
        cof_expires_at: string | null;
        fuel_type: string | null;
        odometer_km: number | null;
        purchase_date: string | null;
        warranty_expires_at: string | null;
        requires_inspection: boolean;
        inspection_due_at: string | null;
        requires_maintenance: boolean;
        maintenance_due_at: string | null;
        notes: string | null;
        site: { id: number; name: string } | null;
        home_site: {
            id: number;
            name: string;
            latitude?: number;
            longitude?: number;
        } | null;
        primary_driver: {
            id: number;
            name: string;
            email: string | null;
        } | null;
        trackers: LinkedDevice[];
        inspections: Inspection[];
        documents: Document[];
        assignments: Assignment[];
        archived_alerts: Alert[];
        work_orders: WorkOrder[];
        service_schedules: ServiceSchedule[];
        geofences: any[];
        fleet_state: Record<string, any> | null;
        category_ref: { id: number; name: string; slug: string } | null;
        [key: string]: any;
    };
    timeline: TimelineEvent[];
    /** HR-register wrapper federating to this canonical Fleet asset, if any. */
    hr_asset: {
        id: number;
        asset_tag: string | null;
        status: string | null;
        current_holder_name: string | null;
    } | null;
    can_view_hr_assets: boolean;
    asset_finance_technology: AssetFinanceTechnologyProjection;
    /** Option lists for the edit wizard. */
    sites?: Array<{ id: number; name: string }>;
    clients?: Array<{
        id: number;
        first_name: string;
        last_name: string;
        site_id?: number | null;
    }>;
};

function statusVariant(
    status: string,
): 'default' | 'secondary' | 'destructive' | 'outline' {
    switch (status) {
        case 'active':
        case 'online':
        case 'pass':
        case 'resolved':
            return 'default';
        case 'out_of_service':
        case 'critical':
        case 'fail':
            return 'destructive';
        case 'retired':
        case 'offline':
            return 'secondary';
        default:
            return 'outline';
    }
}

function isExpiringSoon(dateStr: string | null): boolean {
    if (!dateStr) return false;
    const date = new Date(dateStr);
    const now = new Date();
    const diffDays = (date.getTime() - now.getTime()) / (1000 * 60 * 60 * 24);
    return diffDays <= 30 && diffDays >= 0;
}

function isExpired(dateStr: string | null): boolean {
    if (!dateStr) return false;
    return new Date(dateStr) < new Date();
}

export default function AssetShow({
    asset,
    timeline,
    hr_asset,
    can_view_hr_assets,
    asset_finance_technology,
    sites,
    clients,
}: Props) {
    const [activeTab, setActiveTab] = useState(() => {
        if (typeof window === 'undefined') return 'overview';
        const requested = new URLSearchParams(window.location.search).get(
            'tab',
        );
        return requested &&
            [
                'overview',
                'lifecycle',
                'documents',
                'maintenance',
                'inspections',
                'technology',
                'alerts',
                'assignments',
            ].includes(requested)
            ? requested
            : 'overview';
    });
    const openTab = (tab: string) => {
        setActiveTab(tab);
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            if (tab === 'overview') url.searchParams.delete('tab');
            else url.searchParams.set('tab', tab);
            window.history.replaceState(window.history.state, '', url);
        }
    };
    // /fleet-assets/assets/{id}/edit now redirects here with ?edit=1 — open the
    // edit wizard on mount (the Edit button opens the same dialog).
    const [editOpen, setEditOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            new URLSearchParams(window.location.search).has('edit'),
    );

    const closeEdit = () => {
        setEditOpen(false);
        // Strip the shim param so a refresh doesn't reopen the wizard.
        if (typeof window !== 'undefined') {
            const url = new URL(window.location.href);
            url.searchParams.delete('edit');
            window.history.replaceState({}, '', url);
        }
    };
    const linkedDevices: LinkedDevice[] = asset?.trackers ?? [];
    // Legacy alias for map marker logic and existing references.
    const trackers = linkedDevices;
    const inspections = asset?.inspections ?? [];
    const documents = asset?.documents ?? [];
    const assignments = asset?.assignments ?? [];
    const alerts = asset?.archived_alerts ?? [];
    const work_orders = asset?.work_orders ?? [];
    const service_schedules = asset?.service_schedules ?? [];
    const can_edit = true;
    const can_upload = true;
    const [docOpen, setDocOpen] = useState(false);
    const [docFile, setDocFile] = useState<File | null>(null);
    const [docTitle, setDocTitle] = useState('');
    const [docCategory, setDocCategory] = useState('manual');
    const [docSubmitting, setDocSubmitting] = useState(false);
    const [docError, setDocError] = useState('');

    const submitDocument = () => {
        if (!docFile) {
            setDocError('Choose a file.');
            return;
        }

        if (!docTitle.trim()) {
            setDocError('Title is required.');
            return;
        }

        setDocSubmitting(true);
        setDocError('');

        const formData = new FormData();
        formData.append('file', docFile);
        formData.append('title', docTitle.trim());
        if (docCategory) {
            formData.append('category', docCategory);
        }

        router.post(`/assets/${asset.id}/documents`, formData, {
            forceFormData: true,
            preserveScroll: true,
            onSuccess: () => {
                setDocOpen(false);
                setDocFile(null);
                setDocTitle('');
                setDocCategory('manual');
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                setDocError(
                    Array.isArray(firstError)
                        ? firstError[0]
                        : String(firstError ?? 'Upload failed.'),
                );
            },
            onFinish: () => setDocSubmitting(false),
        });
    };

    const trackerMarkers = useMemo<MapMarker[]>(() => {
        const result: MapMarker[] = [];
        (trackers ?? []).forEach((t) => {
            if (t.lat && t.lng) {
                result.push({
                    id: `tracker-${t.id}`,
                    lat: Number(t.lat),
                    lng: Number(t.lng),
                    title: `${t.vendor} - ${t.device_uid}`,
                    type: 'vehicle',
                    status: t.status ?? undefined,
                });
            }
        });
        if (asset.home_site?.latitude && asset.home_site?.longitude) {
            result.push({
                id: `home-${asset.home_site.id}`,
                lat: Number(asset.home_site.latitude),
                lng: Number(asset.home_site.longitude),
                title: asset.home_site.name,
                type: 'house',
            });
        }
        return result;
    }, [trackers, asset.home_site]);

    const mapCenter = useMemo(() => {
        const onlineTracker = (trackers ?? []).find((t) => t.lat && t.lng);
        if (onlineTracker)
            return {
                lat: Number(onlineTracker.lat!),
                lng: Number(onlineTracker.lng!),
            };
        if (asset.home_site?.latitude)
            return {
                lat: Number(asset.home_site.latitude),
                lng: Number(asset.home_site.longitude),
            };
        return { lat: -36.8485, lng: 174.7633 };
    }, [trackers, asset.home_site]);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Fleet & Assets', href: '/fleet-assets' },
                { title: 'Assets', href: '/fleet-assets/assets' },
                { title: asset.name, href: `/fleet-assets/assets/${asset.id}` },
            ]}
        >
            <Head title={`Asset: ${asset.name}`} />
            <PageShell>
                <FleetCompactHero
                    pill={`Asset register · ${asset.category}`}
                    title={asset.name}
                    backHref="/fleet-assets/assets"
                    backLabel="Assets"
                />

                {/* Header Banner Card */}
                <div
                    className={cn(
                        'rounded-lg border px-5 py-4',
                        asset.status === 'active'
                            ? 'border-primary bg-primary/10 text-primary dark:border-primary/30 dark:bg-primary/30 dark:text-primary/70'
                            : asset.status === 'out_of_service'
                              ? 'border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical'
                              : 'border-border bg-muted text-foreground dark:border-border dark:bg-muted/30 dark:text-foreground',
                    )}
                >
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-bold">
                                    {asset.name}
                                </h1>
                                {asset.asset_tag && (
                                    <Badge
                                        variant="outline"
                                        className="bg-white/50 font-mono dark:bg-black/20"
                                    >
                                        {asset.asset_tag}
                                    </Badge>
                                )}
                            </div>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <Badge
                                    variant={statusVariant(asset.status)}
                                    className="text-xs"
                                >
                                    {asset.status.replace(/_/g, ' ')}
                                </Badge>
                                <Badge
                                    variant="secondary"
                                    className="text-xs capitalize"
                                >
                                    {asset.category}
                                </Badge>
                                {asset.registration_number && (
                                    <Badge
                                        variant="outline"
                                        className="bg-white/50 text-xs dark:bg-black/20"
                                    >
                                        Rego: {asset.registration_number}
                                    </Badge>
                                )}
                                {asset.risk_level && (
                                    <Badge
                                        variant={
                                            asset.risk_level === 'high'
                                                ? 'destructive'
                                                : 'secondary'
                                        }
                                        className="text-xs"
                                    >
                                        Risk: {asset.risk_level}
                                    </Badge>
                                )}
                                {hr_asset &&
                                    (can_view_hr_assets ? (
                                        <Link
                                            href={`/hr/assets/${hr_asset.id}`}
                                            className="inline-flex items-center gap-1.5 rounded-[8px] border border-border bg-white/50 px-2.5 py-1 text-xs font-semibold hover:bg-white/70 dark:bg-black/20 dark:hover:bg-black/30"
                                        >
                                            <Package className="h-3.5 w-3.5" />
                                            Also tracked in HR Assets
                                            {hr_asset.current_holder_name
                                                ? ` · with ${hr_asset.current_holder_name}`
                                                : ''}
                                            <ExternalLink className="h-3 w-3" />
                                        </Link>
                                    ) : (
                                        <span className="inline-flex items-center gap-1.5 rounded-[8px] border border-border bg-white/50 px-2.5 py-1 text-xs font-semibold dark:bg-black/20">
                                            <Package className="h-3.5 w-3.5" />
                                            Also tracked in HR Assets
                                            {hr_asset.current_holder_name
                                                ? ` · with ${hr_asset.current_holder_name}`
                                                : ''}
                                        </span>
                                    ))}
                            </div>
                            {/* Key metrics row */}
                            <div className="mt-3 flex flex-wrap gap-4 text-sm">
                                {asset.odometer_km != null && (
                                    <span>
                                        <span className="opacity-60">
                                            Odometer:
                                        </span>{' '}
                                        <span className="font-semibold">
                                            {formatDistance(asset.odometer_km)}
                                        </span>
                                    </span>
                                )}
                                {asset.fuel_type && (
                                    <span>
                                        <span className="opacity-60">
                                            Fuel:
                                        </span>{' '}
                                        <span className="font-semibold capitalize">
                                            {asset.fuel_type}
                                        </span>
                                    </span>
                                )}
                                {asset.site && (
                                    <span>
                                        <span className="opacity-60">
                                            Site:
                                        </span>{' '}
                                        <span className="font-semibold">
                                            {asset.site.name}
                                        </span>
                                    </span>
                                )}
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            {can_edit && (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    className="bg-white/50 dark:bg-black/20"
                                    onClick={() => setEditOpen(true)}
                                >
                                    <Edit className="mr-2 h-4 w-4" />
                                    Edit
                                </Button>
                            )}
                            <Button
                                variant="outline"
                                size="sm"
                                className="bg-white/50 dark:bg-black/20"
                                onClick={() => setActiveTab('assignments')}
                            >
                                <UserPlus className="mr-2 h-4 w-4" />
                                Assign
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Vehicle-specific compliance warnings */}
                {asset.category === 'vehicle' &&
                    (isExpired(asset.wof_expires_at) ||
                        isExpiringSoon(asset.wof_expires_at) ||
                        isExpired(asset.registration_expires_at) ||
                        isExpiringSoon(asset.registration_expires_at)) && (
                        <div className="flex flex-wrap gap-2">
                            {asset.wof_expires_at &&
                                (isExpired(asset.wof_expires_at) ||
                                    isExpiringSoon(asset.wof_expires_at)) && (
                                    <div
                                        className={cn(
                                            'flex items-center gap-2 rounded-md border px-3 py-2 text-sm',
                                            isExpired(asset.wof_expires_at)
                                                ? 'border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical'
                                                : 'border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning',
                                        )}
                                    >
                                        <AlertTriangle className="h-4 w-4" />
                                        WOF{' '}
                                        {isExpired(asset.wof_expires_at)
                                            ? 'Expired'
                                            : 'Expiring soon'}
                                        : {formatDate(asset.wof_expires_at)}
                                    </div>
                                )}
                            {asset.registration_expires_at &&
                                (isExpired(asset.registration_expires_at) ||
                                    isExpiringSoon(
                                        asset.registration_expires_at,
                                    )) && (
                                    <div
                                        className={cn(
                                            'flex items-center gap-2 rounded-md border px-3 py-2 text-sm',
                                            isExpired(
                                                asset.registration_expires_at,
                                            )
                                                ? 'border-status-critical/30 bg-status-critical-bg text-status-critical dark:border-status-critical/30 dark:bg-status-critical-bg dark:text-status-critical'
                                                : 'border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning',
                                        )}
                                    >
                                        <AlertTriangle className="h-4 w-4" />
                                        Rego{' '}
                                        {isExpired(
                                            asset.registration_expires_at,
                                        )
                                            ? 'Expired'
                                            : 'Expiring soon'}
                                        :{' '}
                                        {formatDate(
                                            asset.registration_expires_at,
                                        )}
                                    </div>
                                )}
                        </div>
                    )}

                {/* Tabs */}
                <Tabs value={activeTab} onValueChange={openTab}>
                    <TabsList className="h-auto flex-wrap gap-1 p-1">
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="lifecycle">Lifecycle</TabsTrigger>
                        <TabsTrigger value="documents">Documents</TabsTrigger>
                        <TabsTrigger value="maintenance">
                            Maintenance
                        </TabsTrigger>
                        <TabsTrigger value="inspections">
                            Inspections
                        </TabsTrigger>
                        <TabsTrigger value="technology">
                            Technology &amp; finance
                        </TabsTrigger>
                        <TabsTrigger value="alerts">
                            Archived Alerts
                        </TabsTrigger>
                        <TabsTrigger value="assignments">
                            Assignments
                        </TabsTrigger>
                    </TabsList>

                    {/* Overview Tab */}
                    <TabsContent value="overview">
                        <div className="grid gap-6 lg:grid-cols-[3fr_2fr]">
                            <div className="space-y-4">
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Info className="h-4 w-4" />
                                            Details
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <dl className="grid gap-2 text-sm">
                                            {asset.manufacturer && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">
                                                        Manufacturer
                                                    </dt>
                                                    <dd className="font-medium">
                                                        {asset.manufacturer}
                                                    </dd>
                                                </div>
                                            )}
                                            {asset.model && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">
                                                        Model
                                                    </dt>
                                                    <dd className="font-medium">
                                                        {asset.model}
                                                    </dd>
                                                </div>
                                            )}
                                            {asset.serial_number && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">
                                                        Serial Number
                                                    </dt>
                                                    <dd className="font-mono font-medium">
                                                        {asset.serial_number}
                                                    </dd>
                                                </div>
                                            )}
                                            <div className="flex justify-between">
                                                <dt className="text-muted-foreground">
                                                    Category
                                                </dt>
                                                <dd className="font-medium capitalize">
                                                    {asset.category}
                                                </dd>
                                            </div>
                                            {asset.site && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">
                                                        Site
                                                    </dt>
                                                    <dd className="font-medium">
                                                        {asset.site.name}
                                                    </dd>
                                                </div>
                                            )}
                                            {asset.home_site && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">
                                                        Home Base
                                                    </dt>
                                                    <dd className="font-medium">
                                                        {asset.home_site.name}
                                                    </dd>
                                                </div>
                                            )}
                                            {asset.risk_level && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">
                                                        Risk Level
                                                    </dt>
                                                    <dd>
                                                        <Badge
                                                            variant={
                                                                asset.risk_level ===
                                                                'high'
                                                                    ? 'destructive'
                                                                    : 'secondary'
                                                            }
                                                        >
                                                            {asset.risk_level}
                                                        </Badge>
                                                    </dd>
                                                </div>
                                            )}
                                            {asset.category === 'vehicle' && (
                                                <>
                                                    {asset.fuel_type && (
                                                        <div className="flex justify-between">
                                                            <dt className="text-muted-foreground">
                                                                Fuel Type
                                                            </dt>
                                                            <dd className="font-medium capitalize">
                                                                {
                                                                    asset.fuel_type
                                                                }
                                                            </dd>
                                                        </div>
                                                    )}
                                                    {asset.odometer_km !=
                                                        null && (
                                                        <div className="flex justify-between">
                                                            <dt className="text-muted-foreground">
                                                                Odometer
                                                            </dt>
                                                            <dd className="font-medium">
                                                                {formatDistance(
                                                                    asset.odometer_km ??
                                                                        0,
                                                                )}
                                                            </dd>
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                            {asset.purchase_date && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">
                                                        Purchase Date
                                                    </dt>
                                                    <dd className="font-medium">
                                                        {formatDate(
                                                            asset.purchase_date,
                                                        )}
                                                    </dd>
                                                </div>
                                            )}
                                            {asset.warranty_expires_at && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">
                                                        Warranty Expiry
                                                    </dt>
                                                    <dd className="font-medium">
                                                        {formatDate(
                                                            asset.warranty_expires_at,
                                                        )}
                                                    </dd>
                                                </div>
                                            )}
                                        </dl>
                                    </CardContent>
                                </Card>

                                <AssetDeviceStatusCard
                                    linkedDevices={linkedDevices}
                                    canViewTechnology={
                                        asset_finance_technology.permissions
                                            .technology
                                    }
                                    devicesHref={
                                        asset_finance_technology.links.devices
                                    }
                                />
                            </div>

                            <div className="space-y-4">
                                {/* Location Map */}
                                {trackerMarkers.length > 0 && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="flex items-center gap-2 text-base">
                                                <MapPin className="h-4 w-4" />
                                                Location
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <LeafletMap
                                                center={mapCenter}
                                                zoom={14}
                                                markers={trackerMarkers}
                                                height={250}
                                            />
                                        </CardContent>
                                    </Card>
                                )}

                                {/* QR Code */}
                                {asset.qr_code_url && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                QR Code
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent className="flex justify-center">
                                            <img
                                                src={asset.qr_code_url}
                                                alt="Asset QR Code"
                                                className="h-32 w-32"
                                            />
                                        </CardContent>
                                    </Card>
                                )}

                                {/* Current Assignment */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <UserPlus className="h-4 w-4" />
                                            Current Assignment
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {asset.current_assignment ? (
                                            <div className="text-sm">
                                                <div className="font-medium">
                                                    {
                                                        asset.current_assignment
                                                            .assignee.name
                                                    }
                                                </div>
                                                <div className="text-muted-foreground">
                                                    Since{' '}
                                                    {formatDate(
                                                        asset.current_assignment
                                                            .assigned_at,
                                                    )}
                                                </div>
                                                {asset.current_assignment
                                                    .purpose && (
                                                    <div className="mt-1 text-muted-foreground">
                                                        {
                                                            asset
                                                                .current_assignment
                                                                .purpose
                                                        }
                                                    </div>
                                                )}
                                            </div>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">
                                                Not currently assigned.
                                            </p>
                                        )}
                                    </CardContent>
                                </Card>
                            </div>
                        </div>
                    </TabsContent>

                    {/* Lifecycle Tab */}
                    <TabsContent value="lifecycle">
                        <Card>
                            <CardHeader>
                                <CardTitle>Lifecycle Timeline</CardTitle>
                            </CardHeader>
                            <CardContent>
                                {(timeline ?? []).length > 0 ? (
                                    <div className="relative space-y-4 border-l-2 border-muted pl-6">
                                        {timeline.map((event) => (
                                            <div
                                                key={event.id}
                                                className="relative"
                                            >
                                                <div className="absolute top-1 -left-[31px] h-4 w-4 rounded-full border-2 border-background bg-primary" />
                                                <div className="rounded-lg border p-3">
                                                    <div className="flex items-center justify-between">
                                                        <span className="text-sm font-medium capitalize">
                                                            {event.type.replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {event.date
                                                                ? formatDate(
                                                                      event.date,
                                                                  )
                                                                : '---'}
                                                        </span>
                                                    </div>
                                                    <p className="mt-1 text-sm text-muted-foreground">
                                                        {event.summary}
                                                    </p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No lifecycle events recorded.
                                    </p>
                                )}
                            </CardContent>
                        </Card>
                    </TabsContent>

                    {/* Documents Tab */}
                    <TabsContent value="documents">
                        <Card>
                            <CardHeader className="flex flex-row items-center justify-between">
                                <CardTitle>Documents</CardTitle>
                                {can_upload && (
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setDocOpen(true);
                                            setDocError('');
                                        }}
                                    >
                                        <Upload className="mr-2 h-4 w-4" />
                                        Upload
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                {(documents ?? []).length > 0 ? (
                                    <div className="space-y-2">
                                        {documents.map((doc) => (
                                            <div
                                                key={doc.id}
                                                className="flex items-center justify-between rounded-md border p-3"
                                            >
                                                <div className="flex items-center gap-3">
                                                    <FileText className="h-5 w-5 text-muted-foreground" />
                                                    <div>
                                                        <div className="text-sm font-medium">
                                                            {doc.name}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {doc.type} &middot;
                                                            Uploaded{' '}
                                                            {formatDate(
                                                                doc.uploaded_at,
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                                <Button
                                                    variant="ghost"
                                                    size="sm"
                                                    asChild
                                                >
                                                    <a
                                                        href={doc.url}
                                                        target="_blank"
                                                        rel="noopener noreferrer"
                                                    >
                                                        <Download className="h-4 w-4" />
                                                    </a>
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">
                                        No documents uploaded.
                                    </p>
                                )}
                            </CardContent>
                        </Card>

                        <UploadAssetDocumentWizard
                            open={docOpen}
                            file={docFile}
                            title={docTitle}
                            category={docCategory}
                            error={docError}
                            submitting={docSubmitting}
                            onFileChange={setDocFile}
                            onTitleChange={setDocTitle}
                            onCategoryChange={setDocCategory}
                            onClose={() => setDocOpen(false)}
                            onSubmit={submitDocument}
                        />
                    </TabsContent>

                    {/* Maintenance Tab */}
                    <TabsContent value="maintenance">
                        <div className="space-y-4">
                            {/* Upcoming Maintenance */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="flex items-center gap-2 text-base">
                                        <Calendar className="h-4 w-4" />
                                        Upcoming Maintenance
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {asset.requires_maintenance &&
                                    asset.maintenance_due_at ? (
                                        <div className="flex items-center gap-2 text-sm">
                                            <Wrench className="h-4 w-4 text-muted-foreground" />
                                            <span>
                                                Next due:{' '}
                                                {formatDate(
                                                    asset.maintenance_due_at,
                                                )}
                                            </span>
                                            {isExpired(
                                                asset.maintenance_due_at,
                                            ) && (
                                                <Badge variant="destructive">
                                                    Overdue
                                                </Badge>
                                            )}
                                            {isExpiringSoon(
                                                asset.maintenance_due_at,
                                            ) &&
                                                !isExpired(
                                                    asset.maintenance_due_at,
                                                ) && (
                                                    <Badge variant="default">
                                                        Due soon
                                                    </Badge>
                                                )}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No upcoming maintenance scheduled.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Service Schedules */}
                            <Card>
                                <CardHeader>
                                    <CardTitle>Service Schedules</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {(service_schedules ?? []).length > 0 ? (
                                        <div className="space-y-2">
                                            {service_schedules.map(
                                                (schedule) => (
                                                    <div
                                                        key={schedule.id}
                                                        className="flex items-center justify-between rounded-md border p-3 text-sm"
                                                    >
                                                        <div>
                                                            <div className="font-medium">
                                                                {schedule.name}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {schedule.interval_km &&
                                                                    `Every ${formatDistance(schedule.interval_km ?? 0)}`}
                                                                {schedule.interval_km &&
                                                                    schedule.interval_days &&
                                                                    ' or '}
                                                                {schedule.interval_days &&
                                                                    `Every ${schedule.interval_days} days`}
                                                            </div>
                                                        </div>
                                                        <div className="text-right text-xs text-muted-foreground">
                                                            {schedule.next_due_at && (
                                                                <div>
                                                                    Next:{' '}
                                                                    {formatDate(
                                                                        schedule.next_due_at,
                                                                    )}
                                                                    {isExpired(
                                                                        schedule.next_due_at,
                                                                    ) && (
                                                                        <Badge
                                                                            variant="destructive"
                                                                            className="ml-1"
                                                                        >
                                                                            Overdue
                                                                        </Badge>
                                                                    )}
                                                                </div>
                                                            )}
                                                            {schedule.last_completed_at && (
                                                                <div>
                                                                    Last:{' '}
                                                                    {formatDate(
                                                                        schedule.last_completed_at,
                                                                    )}
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                ),
                                            )}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No service schedules configured.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Work Orders */}
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <CardTitle>Work Orders</CardTitle>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link
                                            href={`/fleet-assets/maintenance/work-orders/create?asset_id=${asset.id}`}
                                        >
                                            Create
                                        </Link>
                                    </Button>
                                </CardHeader>
                                <CardContent>
                                    {(work_orders ?? []).length > 0 ? (
                                        <div className="space-y-2">
                                            {work_orders.map((wo) => (
                                                <Link
                                                    key={wo.id}
                                                    href={`/fleet-assets/maintenance/work-orders/${wo.id}`}
                                                    className="flex items-center justify-between rounded-md border p-3 text-sm hover:bg-muted/50"
                                                >
                                                    <div>
                                                        <div className="font-medium">
                                                            {wo.title}
                                                        </div>
                                                        <div className="mt-1 flex gap-2">
                                                            <Badge
                                                                variant={statusVariant(
                                                                    wo.status,
                                                                )}
                                                            >
                                                                {wo.status}
                                                            </Badge>
                                                            <Badge variant="outline">
                                                                {wo.priority}
                                                            </Badge>
                                                            <Badge variant="secondary">
                                                                {wo.category}
                                                            </Badge>
                                                        </div>
                                                    </div>
                                                    {wo.due_at && (
                                                        <span className="text-xs text-muted-foreground">
                                                            Due:{' '}
                                                            {formatDate(
                                                                wo.due_at,
                                                            )}
                                                        </span>
                                                    )}
                                                </Link>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No work orders.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Inspections Tab */}
                    <TabsContent value="inspections">
                        <div className="space-y-4">
                            {asset.requires_inspection &&
                                asset.inspection_due_at && (
                                    <Card>
                                        <CardHeader>
                                            <CardTitle className="text-base">
                                                Next Inspection Due
                                            </CardTitle>
                                        </CardHeader>
                                        <CardContent>
                                            <div className="flex items-center gap-2 text-sm">
                                                <Shield className="h-4 w-4" />
                                                <span>
                                                    {formatDate(
                                                        asset.inspection_due_at,
                                                    )}
                                                </span>
                                                {isExpired(
                                                    asset.inspection_due_at,
                                                ) && (
                                                    <Badge variant="destructive">
                                                        Overdue
                                                    </Badge>
                                                )}
                                            </div>
                                        </CardContent>
                                    </Card>
                                )}

                            <Card>
                                <CardHeader>
                                    <CardTitle>Inspection History</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {(inspections ?? []).length > 0 ? (
                                        <div className="space-y-2">
                                            {inspections.map((insp) => (
                                                <div
                                                    key={insp.id}
                                                    className="flex items-center justify-between rounded-md border p-3 text-sm"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        {insp.result ===
                                                        'pass' ? (
                                                            <CheckCircle className="h-5 w-5 text-status-success" />
                                                        ) : (
                                                            <XCircle className="h-5 w-5 text-status-critical" />
                                                        )}
                                                        <div>
                                                            <div className="font-medium">
                                                                {insp.type}
                                                            </div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {formatDate(
                                                                    insp.inspected_at,
                                                                )}
                                                                {insp.inspector &&
                                                                    ` by ${insp.inspector}`}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <Badge
                                                        variant={
                                                            insp.result ===
                                                            'pass'
                                                                ? 'default'
                                                                : 'destructive'
                                                        }
                                                    >
                                                        {insp.result}
                                                    </Badge>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No inspections recorded.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Technology & finance — read-only cross-module reconciliation */}
                    <TabsContent value="technology">
                        <AssetFinanceTechnologyProjectionPanel
                            projection={asset_finance_technology}
                        />
                    </TabsContent>

                    {/* Alerts Tab */}
                    <TabsContent value="alerts">
                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>
                                        Archived Asset Alert History
                                    </CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="mb-3 text-sm text-muted-foreground">
                                        These legacy asset alerts are retained
                                        for history only. Active operational
                                        alerts now live in{' '}
                                        <Link
                                            href="/fleet-assets/alerts"
                                            className="text-primary hover:underline"
                                        >
                                            Fleet Alerts
                                        </Link>{' '}
                                        and{' '}
                                        <Link
                                            href="/control-room"
                                            className="text-primary hover:underline"
                                        >
                                            Control Room
                                        </Link>
                                        .
                                    </p>
                                    {(alerts ?? []).length > 0 ? (
                                        <div className="space-y-2">
                                            {alerts.map((alert) => (
                                                <div
                                                    key={alert.id}
                                                    className="flex items-center justify-between rounded-md border p-3 text-sm"
                                                >
                                                    <div>
                                                        <div className="font-medium">
                                                            {alert.alert_type.replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {formatDateTime(
                                                                alert.triggered_at,
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Badge
                                                            variant={statusVariant(
                                                                alert.severity,
                                                            )}
                                                        >
                                                            {alert.severity}
                                                        </Badge>
                                                        <Badge
                                                            variant={statusVariant(
                                                                alert.status,
                                                            )}
                                                        >
                                                            {alert.status}
                                                        </Badge>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No archived asset alerts recorded.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Assignments Tab */}
                    <TabsContent value="assignments">
                        <div className="space-y-4">
                            {asset.current_assignment && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">
                                            Current Assignment
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="text-sm">
                                            <div className="font-medium">
                                                {
                                                    asset.current_assignment
                                                        .assignee.name
                                                }
                                            </div>
                                            <div className="text-muted-foreground">
                                                Since{' '}
                                                {formatDate(
                                                    asset.current_assignment
                                                        .assigned_at,
                                                )}
                                            </div>
                                            {asset.current_assignment
                                                .purpose && (
                                                <div className="mt-1 text-muted-foreground">
                                                    {
                                                        asset.current_assignment
                                                            .purpose
                                                    }
                                                </div>
                                            )}
                                        </div>
                                    </CardContent>
                                </Card>
                            )}

                            <Card>
                                <CardHeader>
                                    <CardTitle>Assignment History</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    {(assignments ?? []).length > 0 ? (
                                        <div
                                            data-fleet-narrow-strategy="horizontal-scroll"
                                            className="overflow-x-auto"
                                        >
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="border-b text-left">
                                                        <th className="pb-2 font-medium">
                                                            Assignee
                                                        </th>
                                                        <th className="pb-2 font-medium">
                                                            Assigned
                                                        </th>
                                                        <th className="pb-2 font-medium">
                                                            Returned
                                                        </th>
                                                        <th className="pb-2 font-medium">
                                                            Purpose
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {assignments.map((a) => (
                                                        <tr
                                                            key={a.id}
                                                            className="border-b"
                                                        >
                                                            <td className="py-2">
                                                                {
                                                                    a.assignee
                                                                        .name
                                                                }
                                                            </td>
                                                            <td className="py-2">
                                                                {formatDate(
                                                                    a.assigned_at,
                                                                )}
                                                            </td>
                                                            <td className="py-2">
                                                                {a.returned_at ? (
                                                                    formatDate(
                                                                        a.returned_at,
                                                                    )
                                                                ) : (
                                                                    <Badge variant="default">
                                                                        Active
                                                                    </Badge>
                                                                )}
                                                            </td>
                                                            <td className="py-2 text-muted-foreground">
                                                                {a.purpose ??
                                                                    '—'}
                                                            </td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">
                                            No assignment history.
                                        </p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                </Tabs>

                <AssetWizardDialog
                    open={editOpen}
                    onClose={closeEdit}
                    sites={sites ?? []}
                    clients={clients}
                    asset={{
                        id: asset.id,
                        name: asset.name,
                        asset_tag: asset.asset_tag,
                        category: asset.category,
                        status: asset.status,
                        risk_level: asset.risk_level,
                        site_id: asset.site_id ?? null,
                        home_site_id: asset.home_site_id ?? null,
                        client_id: asset.client_id ?? asset.client?.id ?? null,
                        location: asset.location,
                        manufacturer: asset.manufacturer,
                        model: asset.model,
                        serial_number: asset.serial_number,
                        description: asset.description,
                        registration_number: asset.registration_number,
                        registration_expires_at: asset.registration_expires_at,
                        wof_expires_at: asset.wof_expires_at,
                        cof_expires_at: asset.cof_expires_at,
                        fuel_type: asset.fuel_type,
                        odometer_km: asset.odometer_km,
                        purchase_date: asset.purchase_date,
                        warranty_expires_at: asset.warranty_expires_at,
                        requires_inspection: asset.requires_inspection,
                        inspection_due_at: asset.inspection_due_at,
                        requires_maintenance: asset.requires_maintenance,
                        maintenance_due_at: asset.maintenance_due_at,
                        notes: asset.notes,
                    }}
                />
            </PageShell>
        </AppLayout>
    );
}
