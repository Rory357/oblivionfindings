import LeafletMap, { MapMarker } from '@/components/leaflet-map';
import FleetHero from '@/components/fleet-hero';
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
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle,
    Clock,
    Cpu,
    Download,
    Edit,
    FileText,
    Info,
    MapPin,
    Radio,
    Shield,
    Upload,
    UserPlus,
    Wifi,
    Wrench,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { formatDate, formatDateTime, formatDistance } from '@/lib/fleet-utils';


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
    uploaded_at: string;
    url: string;
};

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
type LinkedDevice = {
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

/** @deprecated Use LinkedDevice. Kept as alias for backward compat. */
type Tracker = LinkedDevice;

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
        home_site: { id: number; name: string; latitude?: number; longitude?: number } | null;
        primary_driver: { id: number; name: string; email: string | null } | null;
        trackers: Tracker[];
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
};

function statusVariant(status: string): 'default' | 'secondary' | 'destructive' | 'outline' {
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
}: Props) {
    const [activeTab, setActiveTab] = useState('overview');
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
    const pairForm = useForm({
        vendor: '',
        device_uid: '',
        imei: '',
        serial_number: '',
    });

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
                    status: t.status,
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
        if (onlineTracker) return { lat: Number(onlineTracker.lat!), lng: Number(onlineTracker.lng!) };
        if (asset.home_site?.latitude) return { lat: Number(asset.home_site.latitude), lng: Number(asset.home_site.longitude) };
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
                {/* Header Banner Card */}
                <div className={cn(
                    'rounded-lg border px-5 py-4',
                    asset.status === 'active' ? 'bg-purple-50 border-purple-200 text-purple-900 dark:bg-purple-950/30 dark:border-purple-800 dark:text-purple-200' :
                    asset.status === 'out_of_service' ? 'bg-red-50 border-red-200 text-red-900 dark:bg-red-950/30 dark:border-red-800 dark:text-red-200' :
                    'bg-slate-50 border-slate-200 text-slate-900 dark:bg-slate-950/30 dark:border-slate-800 dark:text-slate-200'
                )}>
                    <div className="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                        <div>
                            <div className="flex flex-wrap items-center gap-2">
                                <h1 className="text-xl font-bold">{asset.name}</h1>
                                {asset.asset_tag && (
                                    <Badge variant="outline" className="font-mono bg-white/50 dark:bg-black/20">{asset.asset_tag}</Badge>
                                )}
                            </div>
                            <div className="mt-2 flex flex-wrap items-center gap-2">
                                <Badge variant={statusVariant(asset.status)} className="text-xs">
                                    {asset.status.replace(/_/g, ' ')}
                                </Badge>
                                <Badge variant="secondary" className="text-xs capitalize">{asset.category}</Badge>
                                {asset.registration_number && (
                                    <Badge variant="outline" className="text-xs bg-white/50 dark:bg-black/20">Rego: {asset.registration_number}</Badge>
                                )}
                                {asset.risk_level && (
                                    <Badge variant={asset.risk_level === 'high' ? 'destructive' : 'secondary'} className="text-xs">
                                        Risk: {asset.risk_level}
                                    </Badge>
                                )}
                            </div>
                            {/* Key metrics row */}
                            <div className="mt-3 flex flex-wrap gap-4 text-sm">
                                {asset.odometer_km != null && (
                                    <span><span className="opacity-60">Odometer:</span> <span className="font-semibold">{formatDistance(asset.odometer_km)}</span></span>
                                )}
                                {asset.fuel_type && (
                                    <span><span className="opacity-60">Fuel:</span> <span className="font-semibold capitalize">{asset.fuel_type}</span></span>
                                )}
                                {asset.site && (
                                    <span><span className="opacity-60">Site:</span> <span className="font-semibold">{asset.site.name}</span></span>
                                )}
                            </div>
                        </div>
                        <div className="flex items-center gap-2">
                            {can_edit && (
                                <Button variant="outline" size="sm" asChild className="bg-white/50 dark:bg-black/20">
                                    <Link href={`/fleet-assets/assets/${asset.id}/edit`}>
                                        <Edit className="mr-2 h-4 w-4" />
                                        Edit
                                    </Link>
                                </Button>
                            )}
                            <Button variant="outline" size="sm" className="bg-white/50 dark:bg-black/20" onClick={() => setActiveTab('assignments')}>
                                <UserPlus className="mr-2 h-4 w-4" />
                                Assign
                            </Button>
                        </div>
                    </div>
                </div>

                {/* Vehicle-specific compliance warnings */}
                {asset.category === 'vehicle' && (isExpired(asset.wof_expires_at) || isExpiringSoon(asset.wof_expires_at) || isExpired(asset.registration_expires_at) || isExpiringSoon(asset.registration_expires_at)) && (
                    <div className="flex flex-wrap gap-2">
                        {asset.wof_expires_at && (isExpired(asset.wof_expires_at) || isExpiringSoon(asset.wof_expires_at)) && (
                            <div className={cn('flex items-center gap-2 rounded-md border px-3 py-2 text-sm', isExpired(asset.wof_expires_at) ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400' : 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400')}>
                                <AlertTriangle className="h-4 w-4" />
                                WOF {isExpired(asset.wof_expires_at) ? 'Expired' : 'Expiring soon'}: {formatDate(asset.wof_expires_at)}
                            </div>
                        )}
                        {asset.registration_expires_at && (isExpired(asset.registration_expires_at) || isExpiringSoon(asset.registration_expires_at)) && (
                            <div className={cn('flex items-center gap-2 rounded-md border px-3 py-2 text-sm', isExpired(asset.registration_expires_at) ? 'border-red-300 bg-red-50 text-red-800 dark:border-red-800 dark:bg-red-950/30 dark:text-red-400' : 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-800 dark:bg-amber-950/30 dark:text-amber-400')}>
                                <AlertTriangle className="h-4 w-4" />
                                Rego {isExpired(asset.registration_expires_at) ? 'Expired' : 'Expiring soon'}: {formatDate(asset.registration_expires_at)}
                            </div>
                        )}
                    </div>
                )}

                {/* Tabs */}
                <Tabs value={activeTab} onValueChange={setActiveTab}>
                    <TabsList className="flex-wrap h-auto gap-1 p-1">
                        <TabsTrigger value="overview">Overview</TabsTrigger>
                        <TabsTrigger value="lifecycle">Lifecycle</TabsTrigger>
                        <TabsTrigger value="documents">Documents</TabsTrigger>
                        <TabsTrigger value="maintenance">Maintenance</TabsTrigger>
                        <TabsTrigger value="inspections">Inspections</TabsTrigger>
                        <TabsTrigger value="trackers">Trackers</TabsTrigger>
                        <TabsTrigger value="alerts">Archived Alerts</TabsTrigger>
                        <TabsTrigger value="assignments">Assignments</TabsTrigger>
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
                                                    <dt className="text-muted-foreground">Manufacturer</dt>
                                                    <dd className="font-medium">{asset.manufacturer}</dd>
                                                </div>
                                            )}
                                            {asset.model && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">Model</dt>
                                                    <dd className="font-medium">{asset.model}</dd>
                                                </div>
                                            )}
                                            {asset.serial_number && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">Serial Number</dt>
                                                    <dd className="font-mono font-medium">{asset.serial_number}</dd>
                                                </div>
                                            )}
                                            <div className="flex justify-between">
                                                <dt className="text-muted-foreground">Category</dt>
                                                <dd className="font-medium capitalize">{asset.category}</dd>
                                            </div>
                                            {asset.site && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">Site</dt>
                                                    <dd className="font-medium">{asset.site.name}</dd>
                                                </div>
                                            )}
                                            {asset.home_site && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">Home Base</dt>
                                                    <dd className="font-medium">{asset.home_site.name}</dd>
                                                </div>
                                            )}
                                            {asset.risk_level && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">Risk Level</dt>
                                                    <dd>
                                                        <Badge variant={asset.risk_level === 'high' ? 'destructive' : 'secondary'}>
                                                            {asset.risk_level}
                                                        </Badge>
                                                    </dd>
                                                </div>
                                            )}
                                            {asset.category === 'vehicle' && (
                                                <>
                                                    {asset.fuel_type && (
                                                        <div className="flex justify-between">
                                                            <dt className="text-muted-foreground">Fuel Type</dt>
                                                            <dd className="font-medium capitalize">{asset.fuel_type}</dd>
                                                        </div>
                                                    )}
                                                    {asset.odometer_km != null && (
                                                        <div className="flex justify-between">
                                                            <dt className="text-muted-foreground">Odometer</dt>
                                                            <dd className="font-medium">{formatDistance((asset.odometer_km ?? 0))}</dd>
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                            {asset.purchase_date && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">Purchase Date</dt>
                                                    <dd className="font-medium">{formatDate(asset.purchase_date)}</dd>
                                                </div>
                                            )}
                                            {asset.warranty_expires_at && (
                                                <div className="flex justify-between">
                                                    <dt className="text-muted-foreground">Warranty Expiry</dt>
                                                    <dd className="font-medium">{formatDate(asset.warranty_expires_at)}</dd>
                                                </div>
                                            )}
                                        </dl>
                                    </CardContent>
                                </Card>

                                {/* Device Status — canonical linked devices from Security & Devices */}
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="flex items-center gap-2 text-base">
                                            <Cpu className="h-4 w-4" />
                                            Device Status
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {linkedDevices.length > 0 ? (
                                            <div className="space-y-2">
                                                {linkedDevices.map((device) => (
                                                    <a
                                                        key={device.id}
                                                        href={device.detail_url ?? `/security-devices/devices/${device.id}`}
                                                        className="flex items-center justify-between rounded-md border p-3 text-sm hover:bg-muted/50 transition-colors"
                                                    >
                                                        <div className="min-w-0 flex-1">
                                                            <div className="flex items-center gap-2">
                                                                <span className="font-medium">{device.name ?? device.device_uid}</span>
                                                                <Badge variant="outline" className="font-mono text-[10px]">{device.device_uid}</Badge>
                                                                {device.link_type && (
                                                                    <Badge variant="outline" className="text-[10px]">{device.link_type.replace(/_/g, ' ')}</Badge>
                                                                )}
                                                            </div>
                                                            <div className="mt-0.5 flex items-center gap-3 text-xs text-muted-foreground">
                                                                {device.vendor && <span>{device.vendor}</span>}
                                                                {device.last_seen_at && <span>Seen: {formatDateTime(device.last_seen_at)}</span>}
                                                                {device.battery_level !== null && device.battery_level !== undefined && (
                                                                    <span>Battery: {device.battery_level}%</span>
                                                                )}
                                                            </div>
                                                        </div>
                                                        <div className="flex flex-col items-end gap-1 shrink-0">
                                                            <Badge variant={device.status === 'active' ? 'default' : device.status === 'offline' ? 'secondary' : 'outline'} className="text-[10px]">
                                                                {device.status?.replace(/_/g, ' ') ?? 'unknown'}
                                                            </Badge>
                                                            {device.health_status && (
                                                                <Badge variant={device.health_status === 'healthy' ? 'default' : device.health_status === 'critical' ? 'destructive' : 'outline'} className="text-[10px]">
                                                                    {device.health_status}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                    </a>
                                                ))}
                                            </div>
                                        ) : (
                                            <div className="text-center py-6 text-muted-foreground">
                                                <Cpu className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                                <p className="text-sm font-medium">No linked devices</p>
                                                <p className="text-xs mt-1">
                                                    Link devices to this asset in{' '}
                                                    <a href="/security-devices/devices" className="text-primary hover:underline">Security &amp; Devices</a>.
                                                </p>
                                            </div>
                                        )}
                                    </CardContent>
                                </Card>
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
                                            <CardTitle className="text-base">QR Code</CardTitle>
                                        </CardHeader>
                                        <CardContent className="flex justify-center">
                                            <img src={asset.qr_code_url} alt="Asset QR Code" className="h-32 w-32" />
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
                                                <div className="font-medium">{asset.current_assignment.assignee.name}</div>
                                                <div className="text-muted-foreground">
                                                    Since {formatDate(asset.current_assignment.assigned_at)}
                                                </div>
                                                {asset.current_assignment.purpose && (
                                                    <div className="mt-1 text-muted-foreground">{asset.current_assignment.purpose}</div>
                                                )}
                                            </div>
                                        ) : (
                                            <p className="text-sm text-muted-foreground">Not currently assigned.</p>
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
                                            <div key={event.id} className="relative">
                                                <div className="absolute -left-[31px] top-1 h-4 w-4 rounded-full border-2 border-background bg-primary" />
                                                <div className="rounded-lg border p-3">
                                                    <div className="flex items-center justify-between">
                                                        <span className="text-sm font-medium capitalize">
                                                            {event.type.replace(/_/g, ' ')}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {event.date ? formatDate(event.date) : '---'}
                                                        </span>
                                                    </div>
                                                    <p className="mt-1 text-sm text-muted-foreground">{event.summary}</p>
                                                </div>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No lifecycle events recorded.</p>
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
                                    <Button variant="outline" size="sm">
                                        <Upload className="mr-2 h-4 w-4" />
                                        Upload
                                    </Button>
                                )}
                            </CardHeader>
                            <CardContent>
                                {(documents ?? []).length > 0 ? (
                                    <div className="space-y-2">
                                        {documents.map((doc) => (
                                            <div key={doc.id} className="flex items-center justify-between rounded-md border p-3">
                                                <div className="flex items-center gap-3">
                                                    <FileText className="h-5 w-5 text-muted-foreground" />
                                                    <div>
                                                        <div className="text-sm font-medium">{doc.name}</div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {doc.type} &middot; Uploaded {formatDate(doc.uploaded_at)}
                                                        </div>
                                                    </div>
                                                </div>
                                                <Button variant="ghost" size="sm" asChild>
                                                    <a href={doc.url} target="_blank" rel="noopener noreferrer">
                                                        <Download className="h-4 w-4" />
                                                    </a>
                                                </Button>
                                            </div>
                                        ))}
                                    </div>
                                ) : (
                                    <p className="text-sm text-muted-foreground">No documents uploaded.</p>
                                )}
                            </CardContent>
                        </Card>
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
                                    {asset.requires_maintenance && asset.maintenance_due_at ? (
                                        <div className="flex items-center gap-2 text-sm">
                                            <Wrench className="h-4 w-4 text-muted-foreground" />
                                            <span>Next due: {formatDate(asset.maintenance_due_at)}</span>
                                            {isExpired(asset.maintenance_due_at) && (
                                                <Badge variant="destructive">Overdue</Badge>
                                            )}
                                            {isExpiringSoon(asset.maintenance_due_at) && !isExpired(asset.maintenance_due_at) && (
                                                <Badge variant="default">Due soon</Badge>
                                            )}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">No upcoming maintenance scheduled.</p>
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
                                            {service_schedules.map((schedule) => (
                                                <div key={schedule.id} className="flex items-center justify-between rounded-md border p-3 text-sm">
                                                    <div>
                                                        <div className="font-medium">{schedule.name}</div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {schedule.interval_km && `Every ${formatDistance((schedule.interval_km ?? 0))}`}
                                                            {schedule.interval_km && schedule.interval_days && ' or '}
                                                            {schedule.interval_days && `Every ${schedule.interval_days} days`}
                                                        </div>
                                                    </div>
                                                    <div className="text-right text-xs text-muted-foreground">
                                                        {schedule.next_due_at && (
                                                            <div>
                                                                Next: {formatDate(schedule.next_due_at)}
                                                                {isExpired(schedule.next_due_at) && (
                                                                    <Badge variant="destructive" className="ml-1">Overdue</Badge>
                                                                )}
                                                            </div>
                                                        )}
                                                        {schedule.last_completed_at && (
                                                            <div>Last: {formatDate(schedule.last_completed_at)}</div>
                                                        )}
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">No service schedules configured.</p>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Work Orders */}
                            <Card>
                                <CardHeader className="flex flex-row items-center justify-between">
                                    <CardTitle>Work Orders</CardTitle>
                                    <Button variant="outline" size="sm" asChild>
                                        <Link href={`/fleet-assets/maintenance/work-orders/create?asset_id=${asset.id}`}>
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
                                                        <div className="font-medium">{wo.title}</div>
                                                        <div className="flex gap-2 mt-1">
                                                            <Badge variant={statusVariant(wo.status)}>{wo.status}</Badge>
                                                            <Badge variant="outline">{wo.priority}</Badge>
                                                            <Badge variant="secondary">{wo.category}</Badge>
                                                        </div>
                                                    </div>
                                                    {wo.due_at && (
                                                        <span className="text-xs text-muted-foreground">
                                                            Due: {formatDate(wo.due_at)}
                                                        </span>
                                                    )}
                                                </Link>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">No work orders.</p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Inspections Tab */}
                    <TabsContent value="inspections">
                        <div className="space-y-4">
                            {asset.requires_inspection && asset.inspection_due_at && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Next Inspection Due</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="flex items-center gap-2 text-sm">
                                            <Shield className="h-4 w-4" />
                                            <span>{formatDate(asset.inspection_due_at)}</span>
                                            {isExpired(asset.inspection_due_at) && (
                                                <Badge variant="destructive">Overdue</Badge>
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
                                                <div key={insp.id} className="flex items-center justify-between rounded-md border p-3 text-sm">
                                                    <div className="flex items-center gap-3">
                                                        {insp.result === 'pass' ? (
                                                            <CheckCircle className="h-5 w-5 text-green-500" />
                                                        ) : (
                                                            <XCircle className="h-5 w-5 text-red-500" />
                                                        )}
                                                        <div>
                                                            <div className="font-medium">{insp.type}</div>
                                                            <div className="text-xs text-muted-foreground">
                                                                {formatDate(insp.inspected_at)}
                                                                {insp.inspector && ` by ${insp.inspector}`}
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <Badge variant={insp.result === 'pass' ? 'default' : 'destructive'}>
                                                        {insp.result}
                                                    </Badge>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">No inspections recorded.</p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Trackers Tab */}
                    <TabsContent value="trackers">
                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <div className="flex items-center justify-between">
                                        <CardTitle>Linked Devices</CardTitle>
                                        <a
                                            href="/security-devices/devices"
                                            className="text-xs text-primary hover:underline"
                                        >
                                            Manage in Security &amp; Devices
                                        </a>
                                    </div>
                                </CardHeader>
                                <CardContent>
                                    {linkedDevices.length > 0 ? (
                                        <div className="space-y-2">
                                            {linkedDevices.map((device) => (
                                                <a
                                                    key={device.id}
                                                    href={device.detail_url ?? `/security-devices/devices/${device.id}`}
                                                    className="flex items-center justify-between rounded-md border p-3 text-sm hover:bg-muted/50 transition-colors"
                                                >
                                                    <div className="flex items-center gap-3">
                                                        <Cpu className={`h-5 w-5 ${device.status === 'active' ? 'text-green-500' : 'text-gray-400'}`} />
                                                        <div>
                                                            <div className="font-medium">{device.name ?? device.vendor ?? 'Device'}</div>
                                                            <div className="text-xs text-muted-foreground font-mono">
                                                                {device.device_uid}
                                                                {device.imei && ` | IMEI: ${device.imei}`}
                                                            </div>
                                                            {device.link_type && (
                                                                <div className="mt-0.5">
                                                                    <Badge variant="outline" className="text-[10px]">{device.link_type.replace(/_/g, ' ')}</Badge>
                                                                </div>
                                                            )}
                                                        </div>
                                                    </div>
                                                    <div className="text-right">
                                                        <div className="flex items-center gap-1 justify-end">
                                                            <Badge variant={statusVariant(device.status ?? 'unknown')}>{device.status?.replace(/_/g, ' ') ?? 'unknown'}</Badge>
                                                            {device.health_status && (
                                                                <Badge variant={device.health_status === 'healthy' ? 'default' : device.health_status === 'critical' ? 'destructive' : 'outline'} className="text-[10px]">
                                                                    {device.health_status}
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        {device.last_seen_at && (
                                                            <div className="mt-1 text-xs text-muted-foreground">
                                                                Seen: {formatDateTime(device.last_seen_at)}
                                                            </div>
                                                        )}
                                                        {device.battery_level !== null && device.battery_level !== undefined && (
                                                            <div className="mt-0.5 text-xs text-muted-foreground">
                                                                Battery: {device.battery_level}%
                                                            </div>
                                                        )}
                                                    </div>
                                                </a>
                                            ))}
                                        </div>
                                    ) : (
                                        <div className="text-center py-6 text-muted-foreground">
                                            <Cpu className="h-8 w-8 mx-auto mb-2 opacity-40" />
                                            <p className="text-sm font-medium">No linked devices</p>
                                            <p className="text-xs mt-1">
                                                Link devices to this asset in{' '}
                                                <a href="/security-devices/devices" className="text-primary hover:underline">Security &amp; Devices</a>.
                                            </p>
                                        </div>
                                    )}
                                </CardContent>
                            </Card>

                            {/* Telemetry Snapshot */}
                            {trackers?.some((t) => t.lat && t.lng) && (
                                <Card>
                                    <CardHeader>
                                        <CardTitle className="text-base">Telemetry Snapshot</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        {trackers.filter((t) => t.lat && t.lng).map((t) => (
                                            <div key={t.id} className="grid gap-2 text-sm sm:grid-cols-4">
                                                <div>
                                                    <div className="text-muted-foreground">Position</div>
                                                    <div className="font-mono">{t.lat}, {t.lng}</div>
                                                </div>
                                                <div>
                                                    <div className="text-muted-foreground">Speed</div>
                                                    <div>{t.speed_kph ?? 0} kph</div>
                                                </div>
                                                <div>
                                                    <div className="text-muted-foreground">Battery</div>
                                                    <div>{t.battery_pct ?? 0}%</div>
                                                </div>
                                                <div>
                                                    <div className="text-muted-foreground">Status</div>
                                                    <Badge variant={statusVariant(t.status)}>{t.status}</Badge>
                                                </div>
                                            </div>
                                        ))}
                                    </CardContent>
                                </Card>
                            )}

                            {/* Pair Device Form */}
                            <Card>
                                <CardHeader>
                                    <CardTitle className="text-base">Pair New Device</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            pairForm.post(`/fleet-assets/assets/${asset.id}/pair-device`, {
                                                preserveScroll: true,
                                                onSuccess: () => pairForm.reset(),
                                            });
                                        }}
                                        className="grid gap-3 sm:grid-cols-2"
                                    >
                                        <div>
                                            <label className="text-sm font-medium">Vendor</label>
                                            <Input
                                                value={pairForm.data.vendor}
                                                onChange={(e) => pairForm.setData('vendor', e.target.value)}
                                                placeholder="e.g. Digital Matter"
                                            />
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">Device UID</label>
                                            <Input
                                                value={pairForm.data.device_uid}
                                                onChange={(e) => pairForm.setData('device_uid', e.target.value)}
                                                placeholder="Device identifier"
                                            />
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">IMEI</label>
                                            <Input
                                                value={pairForm.data.imei}
                                                onChange={(e) => pairForm.setData('imei', e.target.value)}
                                                placeholder="Optional"
                                            />
                                        </div>
                                        <div>
                                            <label className="text-sm font-medium">Serial Number</label>
                                            <Input
                                                value={pairForm.data.serial_number}
                                                onChange={(e) => pairForm.setData('serial_number', e.target.value)}
                                                placeholder="Optional"
                                            />
                                        </div>
                                        <div className="sm:col-span-2">
                                            <Button type="submit" size="sm" disabled={pairForm.processing}>
                                                <Radio className="mr-2 h-4 w-4" />
                                                Pair Device
                                            </Button>
                                        </div>
                                    </form>
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>

                    {/* Alerts Tab */}
                    <TabsContent value="alerts">
                        <div className="space-y-4">
                            <Card>
                                <CardHeader>
                                    <CardTitle>Archived Asset Alert History</CardTitle>
                                </CardHeader>
                                <CardContent>
                                    <p className="mb-3 text-sm text-muted-foreground">
                                        These legacy asset alerts are retained for history only. Active operational alerts now live in
                                        {' '}
                                        <Link href="/fleet-assets/alerts" className="text-primary hover:underline">Fleet Alerts</Link>
                                        {' '}
                                        and
                                        {' '}
                                        <Link href="/control-room" className="text-primary hover:underline">Control Room</Link>.
                                    </p>
                                    {(alerts ?? []).length > 0 ? (
                                        <div className="space-y-2">
                                            {alerts.map((alert) => (
                                                <div key={alert.id} className="flex items-center justify-between rounded-md border p-3 text-sm">
                                                    <div>
                                                        <div className="font-medium">{alert.alert_type.replace(/_/g, ' ')}</div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {formatDateTime(alert.triggered_at)}
                                                        </div>
                                                    </div>
                                                    <div className="flex items-center gap-2">
                                                        <Badge variant={statusVariant(alert.severity)}>{alert.severity}</Badge>
                                                        <Badge variant={statusVariant(alert.status)}>{alert.status}</Badge>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">No archived asset alerts recorded.</p>
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
                                        <CardTitle className="text-base">Current Assignment</CardTitle>
                                    </CardHeader>
                                    <CardContent>
                                        <div className="text-sm">
                                            <div className="font-medium">{asset.current_assignment.assignee.name}</div>
                                            <div className="text-muted-foreground">
                                                Since {formatDate(asset.current_assignment.assigned_at)}
                                            </div>
                                            {asset.current_assignment.purpose && (
                                                <div className="mt-1 text-muted-foreground">{asset.current_assignment.purpose}</div>
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
                                        <div className="overflow-x-auto">
                                            <table className="w-full text-sm">
                                                <thead>
                                                    <tr className="border-b text-left">
                                                        <th className="pb-2 font-medium">Assignee</th>
                                                        <th className="pb-2 font-medium">Assigned</th>
                                                        <th className="pb-2 font-medium">Returned</th>
                                                        <th className="pb-2 font-medium">Purpose</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    {assignments.map((a) => (
                                                        <tr key={a.id} className="border-b">
                                                            <td className="py-2">{a.assignee.name}</td>
                                                            <td className="py-2">{formatDate(a.assigned_at)}</td>
                                                            <td className="py-2">
                                                                {a.returned_at ? formatDate(a.returned_at) : <Badge variant="default">Active</Badge>}
                                                            </td>
                                                            <td className="py-2 text-muted-foreground">{a.purpose ?? '—'}</td>
                                                        </tr>
                                                    ))}
                                                </tbody>
                                            </table>
                                        </div>
                                    ) : (
                                        <p className="text-sm text-muted-foreground">No assignment history.</p>
                                    )}
                                </CardContent>
                            </Card>
                        </div>
                    </TabsContent>
                </Tabs>
            </PageShell>
        </AppLayout>
    );
}
