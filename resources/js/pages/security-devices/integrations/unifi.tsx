import { PageHero, PageLayout } from '@/components/page';
import { Badge } from '@/components/ui/badge';
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
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Building2,
    CheckCircle,
    Loader2,
    RefreshCw,
    ShieldAlert,
    XCircle,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import {
    SiteCredentialsCard,
    type SiteCredentialRow,
} from './site-credentials';

type ProviderConnection = {
    status: 'connected' | 'disconnected' | 'error';
    secret_last4?: string;
    last_tested_at?: string;
    last_synced_at?: string;
    sites_synced_at?: string;
    defaults?: {
        refresh_interval_minutes?: number;
        alert_motion_events?: boolean;
        alert_device_offline?: boolean;
        quiet_hours_start?: string;
        quiet_hours_end?: string;
    };
} | null;

type DiscoveredSite = {
    mapping_token: string;
    name: string;
    device_count?: number | null;
};

type SiteConfig = {
    id: number;
    site_id: number;
    site_name: string;
    site_type?: string | null;
    status: string;
    mapped_external_site_name?: string;
    is_active: boolean;
};

type SiteLite = { id: number; name: string; type?: string };
type RoomLite = { id: number; site_id: number; name: string };
type SyncedDevice = {
    id: number;
    site_id: number;
    site_name: string;
    site_type?: string | null;
    room_id?: number | null;
    name: string;
    category: string;
    status: string;
    health_status?: string | null;
    model?: string | null;
    last_seen_at?: string | null;
};

type SyncLog = {
    id: number;
    action: string;
    status: string;
    items_processed: number;
    items_created: number;
    items_updated: number;
    items_errored: number;
    failure_category?: string | null;
    started_at: string;
    completed_at?: string | null;
};

type Props = {
    providerConnection: ProviderConnection;
    discoveredSites: DiscoveredSite[];
    siteConfigs: SiteConfig[];
    sites: SiteLite[];
    rooms: RoomLite[];
    syncedDevices: SyncedDevice[];
    syncLogs: SyncLog[];
    siteCredentials: SiteCredentialRow[];
};

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Security & Devices', href: '/security-devices' },
    { title: 'APIs & Integrations', href: '/security-devices/integrations' },
    { title: 'UniFi', href: '/security-devices/integrations/unifi' },
];

const connectionStatusConfig: Record<
    string,
    { label: string; className: string; icon: typeof CheckCircle }
> = {
    connected: {
        label: 'Connected',
        className:
            'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
        icon: CheckCircle,
    },
    disconnected: {
        label: 'Disconnected',
        className:
            'bg-muted text-foreground dark:bg-muted/50 dark:text-muted-foreground',
        icon: XCircle,
    },
    error: {
        label: 'Error',
        className:
            'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
        icon: ShieldAlert,
    },
};

const syncStatusConfig: Record<string, string> = {
    success:
        'bg-status-success-bg text-status-success dark:bg-status-success-bg dark:text-status-success',
    partial:
        'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
    failed: 'bg-status-critical-bg text-status-critical dark:bg-status-critical-bg dark:text-status-critical',
    started:
        'bg-status-info-bg text-status-info dark:bg-status-info-bg dark:text-status-info',
};

function siteTypeLabel(type?: string | null): string {
    if (type === 'head_office') return 'Head Office';
    if (type === 'house') return 'House';
    if (type === 'facility') return 'Facility';
    return 'Location';
}

function fmt(value?: string | null): string {
    if (!value) return '---';
    const d = new Date(value);
    return Number.isNaN(d.getTime()) ? value : d.toLocaleString();
}

function deviceStatusBadge(status?: string | null): {
    label: string;
    className: string;
} {
    switch (status) {
        case 'active':
            return {
                label: 'Online',
                className: 'border-status-success/30 text-status-success',
            };
        case 'offline':
            return {
                label: 'Offline',
                className: 'border-status-critical/30 text-status-critical',
            };
        case 'degraded':
            return {
                label: 'Degraded',
                className: 'border-status-warning/30 text-status-warning',
            };
        case 'maintenance':
            return {
                label: 'Maintenance',
                className: 'border-status-info/30 text-status-info',
            };
        case 'decommissioned':
            return {
                label: 'Retired',
                className: 'border-border/30 text-muted-foreground',
            };
        default:
            return {
                label: status || 'Unknown',
                className: 'border-border/30 text-muted-foreground',
            };
    }
}

function formatUnifiSiteLabel(site: DiscoveredSite): {
    primary: string;
    secondary?: string;
    displayName: string;
} {
    const siteName = site.name?.trim() || 'Unnamed UniFi site';
    const deviceCount = site.device_count;
    const primary = siteName;
    const secondaryParts: string[] = [];
    if (deviceCount !== undefined && deviceCount !== null) {
        secondaryParts.push(
            `${deviceCount} device${deviceCount === 1 ? '' : 's'}`,
        );
    }
    const secondary = secondaryParts.join(' • ');
    const displayName = primary;
    return { primary, secondary, displayName };
}

export default function UnifiIntegration({
    providerConnection,
    discoveredSites,
    siteConfigs,
    sites,
    rooms,
    syncedDevices,
    syncLogs,
    siteCredentials,
}: Props) {
    const [showRotateForm, setShowRotateForm] = useState(false);
    const [showMapForm, setShowMapForm] = useState(false);
    const [testingConnection, setTestingConnection] = useState(false);
    const [syncingSites, setSyncingSites] = useState(false);
    const [syncingSiteConfigId, setSyncingSiteConfigId] = useState<
        number | null
    >(null);
    const [assigningDeviceId, setAssigningDeviceId] = useState<number | null>(
        null,
    );
    const [savingDefaults, setSavingDefaults] = useState(false);
    const [deviceSiteFilter, setDeviceSiteFilter] = useState<string>('all');
    const [deviceRoomDraft, setDeviceRoomDraft] = useState<
        Record<number, string>
    >(() =>
        syncedDevices.reduce<Record<number, string>>(
            (acc, d) => ({
                ...acc,
                [d.id]: d.room_id ? String(d.room_id) : 'unassigned',
            }),
            {},
        ),
    );

    const hasKey = !!providerConnection;
    const connStatus = providerConnection
        ? (connectionStatusConfig[providerConnection.status] ??
          connectionStatusConfig.disconnected)
        : null;
    const roomsBySite = useMemo(
        () =>
            rooms.reduce<Record<number, RoomLite[]>>((acc, room) => {
                (acc[room.site_id] ??= []).push(room);
                return acc;
            }, {}),
        [rooms],
    );
    const filteredDevices = useMemo(() => {
        if (deviceSiteFilter === 'all') return syncedDevices;
        const siteId = Number(deviceSiteFilter);
        return syncedDevices.filter((d) => d.site_id === siteId);
    }, [syncedDevices, deviceSiteFilter]);

    const saveKeyForm = useForm({ api_key: '' });
    const rotateKeyForm = useForm({ api_key: '' });
    const mapForm = useForm({ site_id: '', mapping_token: '' });
    const defaultsForm = useForm({
        refresh_interval_minutes:
            providerConnection?.defaults?.refresh_interval_minutes ?? 15,
        alert_motion_events:
            providerConnection?.defaults?.alert_motion_events ?? false,
        alert_device_offline:
            providerConnection?.defaults?.alert_device_offline ?? true,
        quiet_hours_start: providerConnection?.defaults?.quiet_hours_start ?? '',
        quiet_hours_end: providerConnection?.defaults?.quiet_hours_end ?? '',
    });

    const handleSaveDefaults = (e: React.FormEvent) => {
        e.preventDefault();
        setSavingDefaults(true);
        router.put(
            '/security-devices/integrations/unifi/defaults',
            {
                config: {
                    refresh_interval_minutes:
                        defaultsForm.data.refresh_interval_minutes,
                    alert_motion_events: defaultsForm.data.alert_motion_events,
                    alert_device_offline:
                        defaultsForm.data.alert_device_offline,
                    quiet_hours_start:
                        defaultsForm.data.quiet_hours_start || null,
                    quiet_hours_end: defaultsForm.data.quiet_hours_end || null,
                },
            },
            {
                preserveScroll: true,
                onFinish: () => setSavingDefaults(false),
            },
        );
    };

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="UniFi Integration" />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/security-devices/integrations"
                        backLabel="Back to APIs & Integrations"
                        title="UniFi Integration"
                        description="Dynamic location to room assignment for synced UniFi devices."
                    />
                }
            >
                <SiteCredentialsCard rows={siteCredentials} />
                <Card>
                    <CardHeader>
                        <CardTitle>API Key</CardTitle>
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!hasKey ? (
                            <form
                                onSubmit={(e) => {
                                    e.preventDefault();
                                    saveKeyForm.post(
                                        '/security-devices/integrations/unifi/key',
                                        {
                                            preserveScroll: true,
                                            onSuccess: () =>
                                                saveKeyForm.reset('api_key'),
                                        },
                                    );
                                }}
                                className="space-y-4"
                            >
                                <div className="space-y-2">
                                    <Label htmlFor="api_key">API Key</Label>
                                    <Input
                                        id="api_key"
                                        type="password"
                                        value={saveKeyForm.data.api_key}
                                        onChange={(e) =>
                                            saveKeyForm.setData(
                                                'api_key',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Enter UniFi API key"
                                    />
                                </div>
                                <Button
                                    type="submit"
                                    disabled={
                                        saveKeyForm.processing ||
                                        !saveKeyForm.data.api_key
                                    }
                                >
                                    {saveKeyForm.processing
                                        ? 'Saving...'
                                        : 'Save Key'}
                                </Button>
                            </form>
                        ) : (
                            <>
                                <div className="flex flex-wrap items-center gap-3">
                                    <span className="text-sm">
                                        Key ending in{' '}
                                        <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                            •••{providerConnection?.secret_last4}
                                        </code>
                                    </span>
                                    {connStatus && (
                                        <Badge className={connStatus.className}>
                                            <connStatus.icon className="mr-1 h-3 w-3" />
                                            {connStatus.label}
                                        </Badge>
                                    )}
                                </div>
                                <div className="space-y-1 text-sm text-muted-foreground">
                                    <p>
                                        Last tested:{' '}
                                        {fmt(providerConnection?.last_tested_at)}
                                    </p>
                                    <p>
                                        Last sync:{' '}
                                        {fmt(providerConnection?.last_synced_at)}
                                    </p>
                                </div>
                                <div className="flex flex-wrap gap-2">
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() => {
                                            setTestingConnection(true);
                                            router.post(
                                                '/security-devices/integrations/unifi/test',
                                                {},
                                                {
                                                    preserveScroll: true,
                                                    onFinish: () =>
                                                        setTestingConnection(
                                                            false,
                                                        ),
                                                },
                                            );
                                        }}
                                        disabled={testingConnection}
                                    >
                                        {testingConnection ? (
                                            <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                        ) : (
                                            <RefreshCw className="mr-2 h-4 w-4" />
                                        )}
                                        Test Connection
                                    </Button>
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        onClick={() =>
                                            setShowRotateForm((p) => !p)
                                        }
                                    >
                                        Rotate Key
                                    </Button>
                                </div>
                                {showRotateForm && (
                                    <form
                                        onSubmit={(e) => {
                                            e.preventDefault();
                                            rotateKeyForm.post(
                                                '/security-devices/integrations/unifi/rotate',
                                                {
                                                    preserveScroll: true,
                                                    onSuccess: () => {
                                                        rotateKeyForm.reset(
                                                            'api_key',
                                                        );
                                                        setShowRotateForm(
                                                            false,
                                                        );
                                                    },
                                                },
                                            );
                                        }}
                                        className="space-y-3 rounded-lg border p-4"
                                    >
                                        <Label htmlFor="rotate_api_key">
                                            New API Key
                                        </Label>
                                        <Input
                                            id="rotate_api_key"
                                            type="password"
                                            value={rotateKeyForm.data.api_key}
                                            onChange={(e) =>
                                                rotateKeyForm.setData(
                                                    'api_key',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        <div className="flex gap-2">
                                            <Button
                                                type="submit"
                                                size="sm"
                                                disabled={
                                                    rotateKeyForm.processing ||
                                                    !rotateKeyForm.data.api_key
                                                }
                                            >
                                                {rotateKeyForm.processing
                                                    ? 'Saving...'
                                                    : 'Save New Key'}
                                            </Button>
                                            <Button
                                                type="button"
                                                variant="ghost"
                                                size="sm"
                                                onClick={() => {
                                                    setShowRotateForm(false);
                                                    rotateKeyForm.reset(
                                                        'api_key',
                                                    );
                                                }}
                                            >
                                                Cancel
                                            </Button>
                                        </div>
                                    </form>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>

                {hasKey && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <CardTitle>Location Mapping</CardTitle>
                            <Button
                                variant="outline"
                                size="sm"
                                onClick={() => {
                                    setSyncingSites(true);
                                    router.post(
                                        '/security-devices/integrations/unifi/sync-sites',
                                        {},
                                        {
                                            preserveScroll: true,
                                            onFinish: () =>
                                                setSyncingSites(false),
                                        },
                                    );
                                }}
                                disabled={syncingSites}
                            >
                                {syncingSites ? (
                                    <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                                ) : (
                                    <RefreshCw className="mr-2 h-4 w-4" />
                                )}
                                Sync UniFi Locations
                            </Button>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Location</TableHead>
                                            <TableHead>UniFi Site</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Actions</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {siteConfigs.map((c) => (
                                            <TableRow key={c.id}>
                                                <TableCell>
                                                    <div className="font-medium">
                                                        {c.site_name}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {siteTypeLabel(
                                                            c.site_type,
                                                        )}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="font-medium">
                                                        {c.mapped_external_site_name ||
                                                            'Provider location'}
                                                    </div>
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        variant={
                                                            c.is_active
                                                                ? 'default'
                                                                : 'secondary'
                                                        }
                                                    >
                                                        {c.is_active
                                                            ? 'Active'
                                                            : 'Inactive'}
                                                    </Badge>
                                                </TableCell>
                                                <TableCell>
                                                    <div className="space-y-1">
                                                        <div className="flex flex-wrap gap-2">
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                aria-describedby={
                                                                    !c.is_active
                                                                        ? `unifi-site-config-${c.id}-sync-help`
                                                                        : undefined
                                                                }
                                                                onClick={() => {
                                                                    setSyncingSiteConfigId(
                                                                        c.id,
                                                                    );
                                                                    router.post(
                                                                        '/security-devices/integrations/unifi/sync-devices',
                                                                        {
                                                                            site_config_id:
                                                                                c.id,
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                            onFinish:
                                                                                () =>
                                                                                    setSyncingSiteConfigId(
                                                                                        null,
                                                                                    ),
                                                                        },
                                                                    );
                                                                }}
                                                                disabled={
                                                                    !c.is_active ||
                                                                    syncingSiteConfigId ===
                                                                        c.id
                                                                }
                                                            >
                                                                {!c.is_active
                                                                    ? 'Sync unavailable'
                                                                    : syncingSiteConfigId ===
                                                                        c.id
                                                                      ? 'Syncing...'
                                                                      : 'Sync Devices'}
                                                            </Button>
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    router.delete(
                                                                        `/security-devices/integrations/unifi/map-site/${c.id}`,
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Remove
                                                            </Button>
                                                        </div>
                                                        {!c.is_active && (
                                                            <p
                                                                id={`unifi-site-config-${c.id}-sync-help`}
                                                                className="max-w-xs text-xs text-muted-foreground"
                                                            >
                                                                This mapping is
                                                                inactive. Remove
                                                                it and map the
                                                                location again
                                                                before syncing
                                                                devices.
                                                            </p>
                                                        )}
                                                    </div>
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                        {siteConfigs.length === 0 && (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={4}
                                                    className="text-sm text-muted-foreground"
                                                >
                                                    No mapped locations yet.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                            {!showMapForm ? (
                                <Button
                                    variant="outline"
                                    size="sm"
                                    onClick={() => setShowMapForm(true)}
                                >
                                    <Building2 className="mr-2 h-4 w-4" />
                                    Map Location
                                </Button>
                            ) : (
                                <form
                                    onSubmit={(e) => {
                                        e.preventDefault();
                                        mapForm.post(
                                            '/security-devices/integrations/unifi/map-site',
                                            {
                                                preserveScroll: true,
                                                onSuccess: () => {
                                                    mapForm.reset();
                                                    setShowMapForm(false);
                                                },
                                            },
                                        );
                                    }}
                                    className="space-y-4 rounded-lg border p-4"
                                >
                                    <div className="grid gap-4 md:grid-cols-3">
                                        <div className="space-y-2">
                                            <Label>Platform Location</Label>
                                            <Select
                                                value={
                                                    mapForm.data.site_id ||
                                                    undefined
                                                }
                                                onValueChange={(v) =>
                                                    mapForm.setData(
                                                        'site_id',
                                                        v,
                                                    )
                                                }
                                            >
                                                <SelectTrigger>
                                                    <SelectValue placeholder="Select location" />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {sites.map((s) => (
                                                        <SelectItem
                                                            key={s.id}
                                                            value={String(s.id)}
                                                        >
                                                            {s.name} (
                                                            {siteTypeLabel(
                                                                s.type,
                                                            )}
                                                            )
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        </div>
                                        <div className="space-y-2 md:col-span-2">
                                            <Label>UniFi Location</Label>
                                            {discoveredSites.length > 0 ? (
                                                <Select
                                                    value={
                                                        mapForm.data
                                                            .mapping_token ||
                                                        undefined
                                                    }
                                                    onValueChange={(v) => {
                                                        mapForm.setData(
                                                            'mapping_token',
                                                            v,
                                                        );
                                                    }}
                                                >
                                                    <SelectTrigger>
                                                        <SelectValue placeholder="Select UniFi location" />
                                                    </SelectTrigger>
                                                    <SelectContent>
                                                        {discoveredSites.map(
                                                            (d) => {
                                                                const label =
                                                                    formatUnifiSiteLabel(
                                                                        d,
                                                                    );
                                                                return (
                                                                    <SelectItem
                                                                        key={
                                                                            d.mapping_token
                                                                        }
                                                                        value={
                                                                            d.mapping_token
                                                                        }
                                                                        textValue={
                                                                            label.displayName
                                                                        }
                                                                    >
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium">
                                                                                {
                                                                                    label.primary
                                                                                }
                                                                            </span>
                                                                            {label.secondary && (
                                                                                <span className="text-xs text-muted-foreground">
                                                                                    {
                                                                                        label.secondary
                                                                                    }
                                                                                </span>
                                                                            )}
                                                                        </div>
                                                                    </SelectItem>
                                                                );
                                                            },
                                                        )}
                                                    </SelectContent>
                                                </Select>
                                            ) : (
                                                <p className="text-sm text-muted-foreground">
                                                    Sync UniFi locations before
                                                    creating a mapping.
                                                </p>
                                            )}
                                        </div>
                                    </div>
                                    <div className="flex gap-2">
                                        <Button
                                            type="submit"
                                            size="sm"
                                            disabled={mapForm.processing}
                                        >
                                            {mapForm.processing
                                                ? 'Saving...'
                                                : 'Save Mapping'}
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            onClick={() => {
                                                setShowMapForm(false);
                                                mapForm.reset();
                                            }}
                                        >
                                            Cancel
                                        </Button>
                                    </div>
                                </form>
                            )}
                        </CardContent>
                    </Card>
                )}

                {hasKey && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between space-y-0">
                            <CardTitle>
                                Synced Devices & Room Assignment
                            </CardTitle>
                            <Select
                                value={deviceSiteFilter}
                                onValueChange={setDeviceSiteFilter}
                            >
                                <SelectTrigger className="w-56">
                                    <SelectValue placeholder="Filter by location" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All Locations
                                    </SelectItem>
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
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Location</TableHead>
                                            <TableHead>Room</TableHead>
                                            <TableHead>Device</TableHead>
                                            <TableHead>Type</TableHead>
                                            <TableHead>Model</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Last Seen</TableHead>
                                            <TableHead>Action</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {filteredDevices.map((d) => {
                                            const badge = deviceStatusBadge(
                                                d.status,
                                            );

                                            return (
                                                <TableRow key={d.id}>
                                                    <TableCell>
                                                        <div className="font-medium">
                                                            {d.site_name}
                                                        </div>
                                                        <div className="text-xs text-muted-foreground">
                                                            {siteTypeLabel(
                                                                d.site_type,
                                                            )}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell className="min-w-[190px]">
                                                        <Select
                                                            value={
                                                                deviceRoomDraft[
                                                                    d.id
                                                                ] ||
                                                                'unassigned'
                                                            }
                                                            onValueChange={(
                                                                v,
                                                            ) =>
                                                                setDeviceRoomDraft(
                                                                    (p) => ({
                                                                        ...p,
                                                                        [d.id]: v,
                                                                    }),
                                                                )
                                                            }
                                                        >
                                                            <SelectTrigger>
                                                                <SelectValue placeholder="Select room" />
                                                            </SelectTrigger>
                                                            <SelectContent>
                                                                <SelectItem value="unassigned">
                                                                    Unassigned
                                                                </SelectItem>
                                                                {(
                                                                    roomsBySite[
                                                                        d
                                                                            .site_id
                                                                    ] ?? []
                                                                ).map((r) => (
                                                                    <SelectItem
                                                                        key={
                                                                            r.id
                                                                        }
                                                                        value={String(
                                                                            r.id,
                                                                        )}
                                                                    >
                                                                        {r.name}
                                                                    </SelectItem>
                                                                ))}
                                                            </SelectContent>
                                                        </Select>
                                                    </TableCell>
                                                    <TableCell>
                                                        <div className="font-medium">
                                                            {d.name}
                                                        </div>
                                                    </TableCell>
                                                    <TableCell>
                                                        {d.category || '---'}
                                                    </TableCell>
                                                    <TableCell>
                                                        {d.model || '---'}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Badge
                                                            variant="outline"
                                                            className={
                                                                badge.className
                                                            }
                                                        >
                                                            {badge.label}
                                                        </Badge>
                                                    </TableCell>
                                                    <TableCell>
                                                        {fmt(d.last_seen_at)}
                                                    </TableCell>
                                                    <TableCell>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() => {
                                                                const raw =
                                                                    deviceRoomDraft[
                                                                        d.id
                                                                    ];
                                                                const roomId =
                                                                    !raw ||
                                                                    raw ===
                                                                        'unassigned'
                                                                        ? null
                                                                        : Number(
                                                                              raw,
                                                                          );
                                                                setAssigningDeviceId(
                                                                    d.id,
                                                                );
                                                                router.put(
                                                                    `/security-devices/integrations/unifi/hardware/${d.id}/room`,
                                                                    {
                                                                        room_id:
                                                                            roomId,
                                                                    },
                                                                    {
                                                                        preserveScroll: true,
                                                                        onFinish:
                                                                            () =>
                                                                                setAssigningDeviceId(
                                                                                    null,
                                                                                ),
                                                                    },
                                                                );
                                                            }}
                                                            disabled={
                                                                assigningDeviceId ===
                                                                d.id
                                                            }
                                                        >
                                                            {assigningDeviceId ===
                                                            d.id
                                                                ? 'Saving...'
                                                                : 'Save Room'}
                                                        </Button>
                                                    </TableCell>
                                                </TableRow>
                                            );
                                        })}
                                        {filteredDevices.length === 0 && (
                                            <TableRow>
                                                <TableCell
                                                    colSpan={8}
                                                    className="text-sm text-muted-foreground"
                                                >
                                                    No synced devices for this
                                                    filter yet.
                                                </TableCell>
                                            </TableRow>
                                        )}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}

                {hasKey && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Refresh & Alert Defaults</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <form
                                onSubmit={handleSaveDefaults}
                                className="space-y-6"
                            >
                                <div className="grid gap-4 sm:grid-cols-3">
                                    <div className="space-y-2">
                                        <Label htmlFor="refresh_interval">
                                            Refresh Interval (minutes)
                                        </Label>
                                        <Input
                                            id="refresh_interval"
                                            type="number"
                                            min={1}
                                            value={
                                                defaultsForm.data
                                                    .refresh_interval_minutes
                                            }
                                            onChange={(e) =>
                                                defaultsForm.setData(
                                                    'refresh_interval_minutes',
                                                    Math.max(
                                                        1,
                                                        parseInt(
                                                            e.target.value ||
                                                                '1',
                                                            10,
                                                        ),
                                                    ),
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="quiet_start">
                                            Quiet Hours Start
                                        </Label>
                                        <Input
                                            id="quiet_start"
                                            type="time"
                                            value={
                                                defaultsForm.data
                                                    .quiet_hours_start
                                            }
                                            onChange={(e) =>
                                                defaultsForm.setData(
                                                    'quiet_hours_start',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="quiet_end">
                                            Quiet Hours End
                                        </Label>
                                        <Input
                                            id="quiet_end"
                                            type="time"
                                            value={
                                                defaultsForm.data
                                                    .quiet_hours_end
                                            }
                                            onChange={(e) =>
                                                defaultsForm.setData(
                                                    'quiet_hours_end',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                                <div className="space-y-3">
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="alert_motion"
                                            checked={
                                                defaultsForm.data
                                                    .alert_motion_events
                                            }
                                            onCheckedChange={(v) =>
                                                defaultsForm.setData(
                                                    'alert_motion_events',
                                                    !!v,
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor="alert_motion"
                                            className="cursor-pointer"
                                        >
                                            Alert on motion events
                                        </Label>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <Checkbox
                                            id="alert_offline"
                                            checked={
                                                defaultsForm.data
                                                    .alert_device_offline
                                            }
                                            onCheckedChange={(v) =>
                                                defaultsForm.setData(
                                                    'alert_device_offline',
                                                    !!v,
                                                )
                                            }
                                        />
                                        <Label
                                            htmlFor="alert_offline"
                                            className="cursor-pointer"
                                        >
                                            Alert when a device goes offline
                                        </Label>
                                    </div>
                                </div>
                                <Button type="submit" disabled={savingDefaults}>
                                    {savingDefaults
                                        ? 'Saving...'
                                        : 'Save Defaults'}
                                </Button>
                            </form>
                        </CardContent>
                    </Card>
                )}

                {syncLogs.length > 0 && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent Sync Activity</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <div className="rounded-md border">
                                <Table>
                                    <TableHeader>
                                        <TableRow>
                                            <TableHead>Action</TableHead>
                                            <TableHead>Status</TableHead>
                                            <TableHead>Items</TableHead>
                                            <TableHead>Started</TableHead>
                                            <TableHead>Completed</TableHead>
                                        </TableRow>
                                    </TableHeader>
                                    <TableBody>
                                        {syncLogs.map((log) => (
                                            <TableRow key={log.id}>
                                                <TableCell className="font-medium">
                                                    {log.action}
                                                </TableCell>
                                                <TableCell>
                                                    <Badge
                                                        className={
                                                            syncStatusConfig[
                                                                log.status
                                                            ] ??
                                                            syncStatusConfig.started
                                                        }
                                                    >
                                                        {log.status}
                                                    </Badge>
                                                    {log.failure_category && (
                                                        <p className="mt-1 text-xs text-status-critical">
                                                            Provider operation
                                                            failed. Retry or
                                                            review the bounded
                                                            diagnostics.
                                                        </p>
                                                    )}
                                                </TableCell>
                                                <TableCell className="text-xs text-muted-foreground">
                                                    {log.items_processed}{' '}
                                                    processed
                                                    {log.items_created > 0 &&
                                                        `, ${log.items_created} created`}
                                                    {log.items_updated > 0 &&
                                                        `, ${log.items_updated} updated`}
                                                    {log.items_errored > 0 &&
                                                        `, ${log.items_errored} errored`}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {log.started_at}
                                                </TableCell>
                                                <TableCell className="text-sm">
                                                    {log.completed_at ?? '---'}
                                                </TableCell>
                                            </TableRow>
                                        ))}
                                    </TableBody>
                                </Table>
                            </div>
                        </CardContent>
                    </Card>
                )}
            </PageLayout>
        </AppLayout>
    );
}
