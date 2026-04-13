import AppLayout from '@/layouts/app-layout';
import PageHeader from '@/components/page-header';
import PageShell from '@/components/page-shell';
import { Head, router, useForm } from '@inertiajs/react';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { Badge } from '@/components/ui/badge';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    AlertDialog,
    AlertDialogAction,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
    AlertDialogTrigger,
} from '@/components/ui/alert-dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    Cpu,
    Plus,
    Search,
    Pencil,
    Trash2,
    DoorOpen,
    Link2,
    Wifi,
    WifiOff,
    HelpCircle,
    Ban,
    RefreshCw,
    Settings,
    MonitorSmartphone,
    Server,
    CheckCircle,
    XCircle,
    ShieldAlert,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

// ---------------------------------------------------------------------------
// Types
// ---------------------------------------------------------------------------

type Site = { id: number; name: string; type: string };

type Room = { id: number; name: string; sort_order: number };

/** Canonical device from Security & Devices registry. */
type DeviceItem = {
    id: number;
    device_uid: string;
    name: string;
    domain: string;
    category: string;
    subcategory: string | null;
    manufacturer: string | null;
    model: string | null;
    serial_number: string | null;
    mac_address: string | null;
    asset_tag: string | null;
    status: string;
    health_status: string;
    provider: string | null;
    last_seen_at: string | null;
    battery_level: number | null;
    firmware_version: string | null;
    ip_address: string | null;
    notes: string | null;
    assignment_type: string | null;
    assignment_id: number | null;
    legacy_location_hardware_id: number | null;
};

/** @deprecated Legacy type — kept for backward compat with UniFi integration sections. */
type HardwareItem = {
    id: number;
    provider: string;
    category: string;
    name: string;
    asset_tag?: string;
    serial?: string;
    mac?: string;
    status: 'online' | 'offline' | 'unknown' | 'retired';
    last_seen_at?: string;
    room?: { id: number; name: string } | null;
    linked_asset?: { id: number; name: string; asset_tag?: string } | null;
    notes?: string;
    external_ref?: {
        provider_entity_id?: string | null;
        provider_type?: string | null;
        model?: string | null;
        firmware?: string | null;
        ip?: string | null;
    } | null;
    meta?: {
        provider_type?: string | null;
        model_long?: string | null;
        experience_score?: number | string | null;
        uptime?: number | null;
    } | null;
};

type IntegrationConfig = {
    id: number;
    provider: string;
    status: string;
    mapped_external_site_id?: string;
    mapped_external_site_name?: string;
    is_active: boolean;
    overrides?: {
        protect_host_id?: string | null;
        protect_host_name?: string | null;
        access_host_id?: string | null;
        access_host_name?: string | null;
    };
};

type DiscoveredSite = {
    external_id: string;
    name: string;
    meta?: {
        device_count?: number | null;
        health_status?: string | null;
        main_device_name?: string | null;
        main_device_model?: string | null;
        main_device_role?: string | null;
    };
};

type DiscoveredHost = {
    host_id: string;
    name: string;
    model?: string | null;
    role?: string | null;
    controllers?: string[] | null;
};

type UnifiTenantSecret = {
    status: 'connected' | 'disconnected' | 'error';
    secret_last4?: string;
    last_tested_at?: string;
    last_synced_at?: string;
    sites_synced_at?: string;
    last_error?: string | null;
} | null;

type UnifiAccessSecret = {
    id: number;
    base_url?: string | null;
    is_enabled?: boolean;
    secret_last4?: string | null;
    last_tested_at?: string;
    last_error?: string | null;
} | null;

type UnifiIntegration = {
    tenantSecret: UnifiTenantSecret;
    discoveredSites: DiscoveredSite[];
    discoveredHosts?: DiscoveredHost[];
    siteConfig: IntegrationConfig | null;
    accessSecret?: UnifiAccessSecret;
};

type AssetLite = { id: number; name: string; asset_tag?: string };

type Permissions = {
    manage_hardware?: boolean;
    manage_site_integrations?: boolean;
    manage_tenant_integrations?: boolean;
};

type Props = {
    site: Site;
    devices: DeviceItem[];
    hardware?: HardwareItem[]; // Legacy — may still be passed by some code paths
    rooms: Room[];
    integrations: IntegrationConfig[];
    assets: AssetLite[];
    categories: Record<string, string>;
    unifi: UnifiIntegration;
    can: Permissions;
};

// ---------------------------------------------------------------------------
// Status helpers
// ---------------------------------------------------------------------------

const statusConfig: Record<
    HardwareItem['status'],
    { label: string; className: string; icon: typeof Wifi }
> = {
    online: {
        label: 'Online',
        className: 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
        icon: Wifi,
    },
    offline: {
        label: 'Offline',
        className: 'bg-red-500/20 text-red-400 border-red-500/30',
        icon: WifiOff,
    },
    unknown: {
        label: 'Unknown',
        className: 'bg-slate-500/20 text-slate-300 border-slate-500/30',
        icon: HelpCircle,
    },
    retired: {
        label: 'Retired',
        className: 'bg-slate-500/10 text-slate-500 border-slate-500/20',
        icon: Ban,
    },
};

const statusDotColor: Record<HardwareItem['status'], string> = {
    online: 'bg-emerald-500',
    offline: 'bg-red-500',
    unknown: 'bg-slate-400',
    retired: 'bg-slate-500 opacity-50',
};

const connectionStatusConfig: Record<string, { label: string; className: string; icon: typeof CheckCircle }> = {
    connected: {
        label: 'Connected',
        className: 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30',
        icon: CheckCircle,
    },
    disconnected: {
        label: 'Disconnected',
        className: 'bg-slate-500/20 text-slate-300 border-slate-500/30',
        icon: XCircle,
    },
    error: {
        label: 'Error',
        className: 'bg-red-500/20 text-red-400 border-red-500/30',
        icon: ShieldAlert,
    },
};

function formatUnifiSiteLabel(site: DiscoveredSite): {
    primary: string;
    secondary?: string;
    displayName: string;
} {
    const siteName = site.name?.trim() || 'Unnamed UniFi site';
    const mainName = site.meta?.main_device_name?.trim() || '';
    const deviceCount = site.meta?.device_count;
    const primary = mainName || siteName;
    const secondaryParts: string[] = [];
    if (mainName && siteName && mainName !== siteName) {
        secondaryParts.push(`Site: ${siteName}`);
    }
    if (deviceCount !== undefined && deviceCount !== null) {
        secondaryParts.push(`${deviceCount} device${deviceCount === 1 ? '' : 's'}`);
    }
    const secondary = secondaryParts.join(' • ');
    const displayName = mainName && siteName && mainName !== siteName ? `${mainName} — ${siteName}` : primary;
    return { primary, secondary, displayName };
}

function formatUnifiHostLabel(host: DiscoveredHost): {
    primary: string;
    secondary?: string;
    displayName: string;
} {
    const hostName = host.name?.trim() || 'Unnamed Console';
    const model = host.model?.trim() || '';
    const role = host.role?.trim() || '';
    const primary = hostName;
    const secondaryParts: string[] = [];
    if (model) secondaryParts.push(model);
    if (role) secondaryParts.push(role.toUpperCase());
    const secondary = secondaryParts.join(' • ');
    const displayName = model && hostName !== model ? `${hostName} — ${model}` : hostName;
    return { primary, secondary, displayName };
}

// ---------------------------------------------------------------------------
// Main component
// ---------------------------------------------------------------------------

export default function SiteHardware({
    site,
    devices,
    hardware,
    rooms,
    integrations,
    assets,
    categories,
    unifi,
    can,
}: Props) {
    // Bridge: legacy code that still references `hardware` uses the
    // canonical devices list. UniFi sections may still use legacy hardware.
    const allHardware = hardware ?? [];
    // --- state -----------------------------------------------------------------
    const [search, setSearch] = useState('');
    const [filterStatus, setFilterStatus] = useState<string>('all');
    const [filterCategory, setFilterCategory] = useState<string>('all');
    const [filterProvider, setFilterProvider] = useState<string>('all');

    const [showAddDialog, setShowAddDialog] = useState(false);
    const [editingItem, setEditingItem] = useState<HardwareItem | null>(null);

    const [assignRoomItem, setAssignRoomItem] = useState<HardwareItem | null>(null);
    const [linkAssetItem, setLinkAssetItem] = useState<HardwareItem | null>(null);

    const [showAddRoom, setShowAddRoom] = useState(false);
    const [editingRoomId, setEditingRoomId] = useState<number | null>(null);
    const [editingRoomName, setEditingRoomName] = useState('');
    const [addingRoom, setAddingRoom] = useState(false);
    const [savingRoomId, setSavingRoomId] = useState<number | null>(null);

    const [syncingProvider, setSyncingProvider] = useState<string | null>(null);
    const [syncingSites, setSyncingSites] = useState(false);
    const [testingConnection, setTestingConnection] = useState(false);
    const [assigningDeviceId, setAssigningDeviceId] = useState<number | null>(null);
    const [syncingAccessEvents, setSyncingAccessEvents] = useState(false);

    // --- forms -----------------------------------------------------------------
    const hwForm = useForm<{
        name: string;
        category: string;
        provider: string;
        room_id: string;
        asset_tag: string;
        serial: string;
        mac: string;
        notes: string;
    }>({
        name: '',
        category: '',
        provider: 'manual',
        room_id: '',
        asset_tag: '',
        serial: '',
        mac: '',
        notes: '',
    });

    const roomForm = useForm<{ name: string }>({ name: '' });

    const assignRoomForm = useForm<{ room_id: string }>({ room_id: '' });
    const linkAssetForm = useForm<{ linked_asset_id: string }>({ linked_asset_id: '' });

    const mapForm = useForm<{
        mapped_external_site_id: string;
        mapped_external_site_name: string;
        is_active: boolean;
        protect_host_id: string;
        protect_host_name: string;
        access_host_id: string;
        access_host_name: string;
    }>({
        mapped_external_site_id: unifi?.siteConfig?.mapped_external_site_id ?? '',
        mapped_external_site_name: unifi?.siteConfig?.mapped_external_site_name ?? '',
        is_active: unifi?.siteConfig?.is_active ?? true,
        protect_host_id: unifi?.siteConfig?.overrides?.protect_host_id ?? '',
        protect_host_name: unifi?.siteConfig?.overrides?.protect_host_name ?? '',
        access_host_id: unifi?.siteConfig?.overrides?.access_host_id ?? '',
        access_host_name: unifi?.siteConfig?.overrides?.access_host_name ?? '',
    });

    const accessSecret = unifi?.accessSecret ?? null;
    const accessForm = useForm<{ base_url: string; secret: string; is_enabled: boolean }>({
        base_url: accessSecret?.base_url ?? '',
        secret: '',
        is_enabled: accessSecret?.is_enabled ?? true,
    });

    // --- derived ---------------------------------------------------------------
    const unifiSecret = unifi?.tenantSecret ?? null;
    const unifiConfig = unifi?.siteConfig ?? null;
    const unifiStatus = unifiSecret ? (connectionStatusConfig[unifiSecret.status] ?? connectionStatusConfig.disconnected) : null;
    const [discoveredSites, setDiscoveredSites] = useState<DiscoveredSite[]>(unifi?.discoveredSites ?? []);
    const [discoveredHosts, setDiscoveredHosts] = useState<DiscoveredHost[]>(unifi?.discoveredHosts ?? []);
    const mappedSiteLabel = useMemo(() => {
        if (!unifiConfig?.mapped_external_site_id) return null;
        const match = discoveredSites.find((s) => s.external_id === unifiConfig.mapped_external_site_id);
        if (match) return formatUnifiSiteLabel(match).displayName;
        return unifiConfig.mapped_external_site_name ?? null;
    }, [discoveredSites, unifiConfig?.mapped_external_site_id, unifiConfig?.mapped_external_site_name]);

    const protectHosts = useMemo(
        () =>
            discoveredHosts.filter((host) => {
                const role = host.role?.toLowerCase();
                const controllers = (host.controllers ?? []).map((c) => c.toLowerCase());
                return (
                    role === 'protect' ||
                    role === 'nvr' ||
                    role === 'nas' ||
                    controllers.includes('protect')
                );
            }),
        [discoveredHosts],
    );

    const accessHosts = useMemo(
        () =>
            discoveredHosts.filter((host) => {
                const role = host.role?.toLowerCase();
                const controllers = (host.controllers ?? []).map((c) => c.toLowerCase());
                const isAccess = role === 'access' || controllers.includes('access');
                if (!isAccess) return false;
                if (role === 'protect' || role === 'nvr' || role === 'nas') {
                    return controllers.includes('access');
                }
                return true;
            }),
        [discoveredHosts],
    );

    // Stats from canonical device list.
    const stats = useMemo(() => {
        const total = devices.length;
        const active = devices.filter((d) => d.status === 'active').length;
        const offline = devices.filter((d) => d.status === 'offline' || d.status === 'degraded').length;
        const unassignedRooms = devices.filter((d) => d.assignment_type !== 'room').length;
        return { total, online: active, offline, unassigned: unassignedRooms };
    }, [devices]);

    // UniFi devices still use legacy hardware list for integration-specific UI.
    const unifiDevices = useMemo(
        () => allHardware.filter((h) => h.provider === 'unifi'),
        [allHardware],
    );

    const providerOptions = useMemo(() => {
        const providers = Array.from(new Set(devices.map((d) => d.provider).filter(Boolean) as string[]));
        return providers.sort((a, b) => a.localeCompare(b));
    }, [devices]);

    // Filter over canonical devices.
    const filteredHardware = useMemo(() => {
        return devices.filter((d) => {
            if (filterStatus !== 'all' && d.status !== filterStatus) return false;
            if (filterCategory !== 'all' && d.category !== filterCategory) return false;
            if (filterProvider !== 'all' && d.provider !== filterProvider) return false;
            if (search) {
                const q = search.toLowerCase();
                const haystack = [d.name, d.device_uid, d.asset_tag, d.serial_number, d.mac_address, d.provider, d.category]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();
                if (!haystack.includes(q)) return false;
            }
            return true;
        });
    }, [devices, search, filterStatus, filterCategory, filterProvider]);

    const categoryKeys = Object.keys(categories);
    const canManageHardware = !!can?.manage_hardware;
    const canManageSiteIntegrations = !!can?.manage_site_integrations;
    const canManageTenantIntegrations = !!can?.manage_tenant_integrations;
    const canManageIntegrations = canManageSiteIntegrations || canManageTenantIntegrations;

    const [deviceRoomDraft, setDeviceRoomDraft] = useState<Record<number, string>>(
        () => unifiDevices.reduce<Record<number, string>>((acc, d) => ({
            ...acc,
            [d.id]: d.room?.id ? String(d.room.id) : 'unassigned',
        }), {}),
    );

    useEffect(() => {
        setDiscoveredSites(unifi?.discoveredSites ?? []);
    }, [unifi?.discoveredSites]);

    useEffect(() => {
        setDiscoveredHosts(unifi?.discoveredHosts ?? []);
    }, [unifi?.discoveredHosts]);

    useEffect(() => {
        if (!mapForm.data.mapped_external_site_id && discoveredSites.length === 1) {
            const onlySite = discoveredSites[0];
            const label = formatUnifiSiteLabel(onlySite);
            mapForm.setData({
                ...mapForm.data,
                mapped_external_site_id: onlySite.external_id,
                mapped_external_site_name: label.displayName,
                is_active: true,
            });
        }
    }, [discoveredSites]);

    useEffect(() => {
        setDeviceRoomDraft((prev) => {
            const next = { ...prev };
            unifiDevices.forEach((d) => {
                if (!(d.id in next)) {
                    next[d.id] = d.room?.id ? String(d.room.id) : 'unassigned';
                }
            });
            return next;
        });
    }, [unifiDevices]);

    // --- handlers --------------------------------------------------------------
    function openAdd() {
        hwForm.reset();
        hwForm.setData({
            name: '',
            category: categoryKeys[0] || '',
            provider: 'manual',
            room_id: '',
            asset_tag: '',
            serial: '',
            mac: '',
            notes: '',
        });
        setEditingItem(null);
        setShowAddDialog(true);
    }

    function openEdit(item: HardwareItem) {
        setEditingItem(item);
        hwForm.setData({
            name: item.name,
            category: item.category,
            provider: item.provider,
            room_id: item.room?.id?.toString() || '',
            asset_tag: item.asset_tag || '',
            serial: item.serial || '',
            mac: item.mac || '',
            notes: item.notes || '',
        });
        setShowAddDialog(true);
    }

    function closeHwDialog() {
        setShowAddDialog(false);
        setEditingItem(null);
        hwForm.reset();
    }

    function submitHardware(e: React.FormEvent) {
        e.preventDefault();
        if (editingItem) {
            hwForm.put(`/sites/${site.id}/hardware/${editingItem.id}`, {
                preserveScroll: true,
                onSuccess: closeHwDialog,
            });
        } else {
            hwForm.post(`/sites/${site.id}/hardware`, {
                preserveScroll: true,
                onSuccess: closeHwDialog,
            });
        }
    }

    function deleteHardware(id: number) {
        if (!confirm('Are you sure you want to delete this hardware item?')) return;
        router.delete(`/sites/${site.id}/hardware/${id}`, { preserveScroll: true });
    }

    // Rooms
    function submitRoom(e: React.FormEvent) {
        e.preventDefault();
        setAddingRoom(true);
        router.post(
            `/sites/${site.id}/hardware/rooms`,
            { action: 'add', name: roomForm.data.name },
            {
                preserveScroll: true,
                onSuccess: () => {
                    roomForm.reset();
                    setShowAddRoom(false);
                },
                onFinish: () => setAddingRoom(false),
            },
        );
    }

    function startEditRoom(room: Room) {
        setEditingRoomId(room.id);
        setEditingRoomName(room.name);
    }

    function submitEditRoom(roomId: number) {
        setSavingRoomId(roomId);
        router.post(
            `/sites/${site.id}/hardware/rooms`,
            { action: 'rename', room_id: roomId, name: editingRoomName },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setEditingRoomId(null);
                    setEditingRoomName('');
                },
                onFinish: () => setSavingRoomId(null),
            },
        );
    }

    function deleteRoom(roomId: number) {
        setSavingRoomId(roomId);
        router.post(
            `/sites/${site.id}/hardware/rooms`,
            { action: 'delete', room_id: roomId },
            {
                preserveScroll: true,
                onFinish: () => setSavingRoomId(null),
            },
        );
    }

    // Assign room
    function openAssignRoom(item: HardwareItem) {
        setAssignRoomItem(item);
        assignRoomForm.setData('room_id', item.room?.id?.toString() || '');
    }

    function submitAssignRoom(e: React.FormEvent) {
        e.preventDefault();
        if (!assignRoomItem) return;
        assignRoomForm.post(`/sites/${site.id}/hardware/${assignRoomItem.id}/assign-room`, {
            preserveScroll: true,
            onSuccess: () => {
                setAssignRoomItem(null);
                assignRoomForm.reset();
            },
        });
    }

    // Link asset
    function openLinkAsset(item: HardwareItem) {
        setLinkAssetItem(item);
        linkAssetForm.setData('linked_asset_id', item.linked_asset?.id?.toString() || '');
    }

    function submitLinkAsset(e: React.FormEvent) {
        e.preventDefault();
        if (!linkAssetItem) return;
        linkAssetForm.post(`/sites/${site.id}/hardware/${linkAssetItem.id}/link-asset`, {
            preserveScroll: true,
            onSuccess: () => {
                setLinkAssetItem(null);
                linkAssetForm.reset();
            },
        });
    }

    // Sync devices
    function syncDevices(provider: string) {
        setSyncingProvider(provider);
        router.post(`/sites/${site.id}/integrations/${provider}/sync-devices`, {}, {
            preserveScroll: true,
            onFinish: () => setSyncingProvider(null),
        });
    }

    function syncUnifiSites() {
        setSyncingSites(true);
        router.post(`/sites/${site.id}/integrations/unifi/sync-sites`, {}, {
            preserveScroll: true,
            onFinish: () => {
                setSyncingSites(false);
                refreshDiscoveredSites();
            },
        });
    }

    function testUnifiConnection() {
        setTestingConnection(true);
        router.post(`/sites/${site.id}/integrations/unifi/test`, {}, {
            preserveScroll: true,
            onFinish: () => setTestingConnection(false),
        });
    }

    function submitUnifiMapping(e: React.FormEvent) {
        e.preventDefault();
        mapForm.post(`/sites/${site.id}/integrations/unifi`, {
            preserveScroll: true,
        });
    }

    async function refreshDiscoveredSites() {
        try {
            const response = await fetch(`/sites/${site.id}/integrations`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });

            if (!response.ok) return;
            const payload = await response.json();
            const tenantSecrets = Array.isArray(payload?.tenantSecrets) ? payload.tenantSecrets : [];
            const unifiSecret = tenantSecrets.find((secret: { provider?: string }) => secret.provider === 'unifi');
            const sites = unifiSecret?.config?.discovered_sites ?? [];
            const hosts = unifiSecret?.config?.discovered_hosts ?? [];
            if (Array.isArray(sites)) {
                setDiscoveredSites(sites);
            }
            if (Array.isArray(hosts)) {
                setDiscoveredHosts(hosts);
            }
        } catch {
            // ignore fetch errors; UI will still allow manual entry
        }
    }

    useEffect(() => {
        if (!unifiSecret || discoveredSites.length > 0) return;
        refreshDiscoveredSites();
    }, [unifiSecret, discoveredSites.length]);

    function clearUnifiMapping() {
        router.post(
            `/sites/${site.id}/integrations/unifi`,
            {
                mapped_external_site_id: null,
                mapped_external_site_name: null,
                protect_host_id: null,
                protect_host_name: null,
                access_host_id: null,
                access_host_name: null,
                is_active: false,
            },
            {
                preserveScroll: true,
            },
        );
    }

    function saveAccessSecret(e: React.FormEvent) {
        e.preventDefault();
        accessForm.put(`/sites/${site.id}/integrations/unifi/secrets/access_api`, {
            preserveScroll: true,
            onSuccess: () => accessForm.reset('secret'),
        });
    }

    function syncAccessEvents() {
        setSyncingAccessEvents(true);
        router.post(
            `/sites/${site.id}/integrations/unifi/pull-events`,
            {},
            {
                preserveScroll: true,
                onFinish: () => setSyncingAccessEvents(false),
            },
        );
    }

    function saveUnifiRoom(deviceId: number) {
        const raw = deviceRoomDraft[deviceId];
        const roomId = !raw || raw === 'unassigned' ? null : Number(raw);
        setAssigningDeviceId(deviceId);
        router.post(
            `/sites/${site.id}/hardware/${deviceId}/assign-room`,
            { room_id: roomId },
            {
                preserveScroll: true,
                onFinish: () => setAssigningDeviceId(null),
            },
        );
    }

    // --- helpers ---------------------------------------------------------------
    function hwCountInRoom(roomId: number) {
        return hardware.filter((h) => h.room?.id === roomId).length;
    }

    function formatDate(dateStr?: string) {
        if (!dateStr) return null;
        try {
            return new Date(dateStr).toLocaleDateString(undefined, {
                year: 'numeric',
                month: 'short',
                day: 'numeric',
                hour: '2-digit',
                minute: '2-digit',
            });
        } catch {
            return dateStr;
        }
    }

    function resolveDeviceType(item: HardwareItem): string | null {
        return item.meta?.provider_type || item.external_ref?.provider_type || null;
    }

    function resolveDeviceModel(item: HardwareItem): string | null {
        return item.meta?.model_long || item.external_ref?.model || null;
    }

    // --- render ----------------------------------------------------------------
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Sites', href: '/sites' },
                { title: site.name, href: `/sites/${site.id}` },
                { title: 'Hardware & Configuration', href: `/sites/${site.id}/hardware` },
            ]}
        >
            <Head title={`${site.name} - Hardware & Configuration`} />

            <PageShell>
                <PageHeader
                    title="Location Hardware & Configuration"
                    actions={
                        <div className="flex items-center gap-2">
                            <Badge variant="outline" className="text-slate-300">
                                {stats.total} item{stats.total !== 1 ? 's' : ''}
                            </Badge>
                            <Button onClick={openAdd}>
                                <Plus className="w-4 h-4 mr-1" />
                                Add Hardware
                            </Button>
                        </div>
                    }
                />

                {/* ------------------------------------------------------------------ */}
                {/* Section 1 - Hardware Overview                                      */}
                {/* ------------------------------------------------------------------ */}

                {/* Stats row */}
                <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-slate-500/10 p-2">
                                    <Cpu className="w-5 h-5 text-slate-300" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold">{stats.total}</div>
                                    <div className="text-sm text-slate-400">Total Hardware</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="bg-emerald-500/5 border-emerald-500/20">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-emerald-500/10 p-2">
                                    <Wifi className="w-5 h-5 text-emerald-400" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold text-emerald-400">{stats.online}</div>
                                    <div className="text-sm text-slate-400">Online</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card className="bg-red-500/5 border-red-500/20">
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-red-500/10 p-2">
                                    <WifiOff className="w-5 h-5 text-red-400" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold text-red-400">{stats.offline}</div>
                                    <div className="text-sm text-slate-400">Offline</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                    <Card>
                        <CardContent className="p-4">
                            <div className="flex items-center gap-3">
                                <div className="rounded-lg bg-amber-500/10 p-2">
                                    <HelpCircle className="w-5 h-5 text-amber-400" />
                                </div>
                                <div>
                                    <div className="text-2xl font-bold text-amber-400">{stats.unassigned}</div>
                                    <div className="text-sm text-slate-400">Unassigned</div>
                                </div>
                            </div>
                        </CardContent>
                    </Card>
                </div>

                {/* ------------------------------------------------------------------ */}
                {/* UniFi Integration                                                  */}
                {/* ------------------------------------------------------------------ */}

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2">
                            <Wifi className="w-5 h-5" />
                            UniFi Integration
                        </CardTitle>
                        {unifiStatus && (
                            <Badge variant="outline" className={unifiStatus.className}>
                                <unifiStatus.icon className="w-3.5 h-3.5 mr-1" />
                                {unifiStatus.label}
                            </Badge>
                        )}
                    </CardHeader>
                    <CardContent className="grid gap-4 lg:grid-cols-2">
                        <div className="rounded-lg border p-4 space-y-3">
                            <div className="text-sm font-medium">API Key</div>
                            {!unifiSecret ? (
                                <div className="text-sm text-slate-400">
                                    No UniFi API key configured for this tenant.
                                </div>
                            ) : (
                                <>
                                    <div className="flex flex-wrap items-center gap-2 text-sm">
                                        <span>
                                            Key ending in{' '}
                                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                                •••{unifiSecret.secret_last4}
                                            </code>
                                        </span>
                                        {unifiStatus && (
                                            <Badge variant="outline" className={unifiStatus.className}>
                                                <unifiStatus.icon className="w-3 h-3 mr-1" />
                                                {unifiStatus.label}
                                            </Badge>
                                        )}
                                    </div>
                                    <div className="text-xs text-slate-400 space-y-1">
                                        <div>Last tested: {formatDate(unifiSecret.last_tested_at) || '—'}</div>
                                        <div>Last site sync: {formatDate(unifiSecret.sites_synced_at) || '—'}</div>
                                        <div>Last device sync: {formatDate(unifiSecret.last_synced_at) || '—'}</div>
                                    </div>
                                    {unifiSecret.last_error && (
                                        <div className="text-xs text-red-400">{unifiSecret.last_error}</div>
                                    )}
                                </>
                            )}

                            <div className="flex flex-wrap gap-2">
                                {!unifiSecret ? (
                                    <Button asChild size="sm" variant="outline">
                                        <a href="/settings/integrations/unifi">Configure API Key</a>
                                    </Button>
                                ) : (
                                    <>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={testUnifiConnection}
                                            disabled={!canManageIntegrations || testingConnection}
                                        >
                                            {testingConnection ? 'Testing...' : 'Test Connection'}
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={syncUnifiSites}
                                            disabled={!canManageIntegrations || syncingSites}
                                        >
                                            <RefreshCw
                                                className={`w-4 h-4 mr-1 ${syncingSites ? 'animate-spin' : ''}`}
                                            />
                                            {syncingSites ? 'Syncing...' : 'Sync UniFi Locations'}
                                        </Button>
                                    </>
                                )}
                            </div>

                            {unifiSecret && canManageIntegrations && (
                                <div className="mt-4 space-y-3 border-t border-slate-800 pt-4">
                                    <div className="text-sm font-medium">Access Console API (Entry/Exit)</div>
                                    <div className="text-xs text-slate-400">
                                        Configure the local UniFi Access controller URL (typically https://&lt;console-ip&gt;:12445) and API key.
                                    </div>

                                    {accessSecret && (
                                        <div className="text-xs text-slate-400 space-y-1">
                                            {accessSecret.secret_last4 && (
                                                <div>
                                                    Key ending in{' '}
                                                    <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                                        •••{accessSecret.secret_last4}
                                                    </code>
                                                </div>
                                            )}
                                            <div>Last sync: {formatDate(accessSecret.last_tested_at) || '—'}</div>
                                            {accessSecret.last_error && (
                                                <div className="text-xs text-red-400">{accessSecret.last_error}</div>
                                            )}
                                        </div>
                                    )}

                                    <form onSubmit={saveAccessSecret} className="grid gap-3 md:grid-cols-2">
                                        <div className="space-y-2">
                                            <Label>Access Controller URL</Label>
                                            <Input
                                                value={accessForm.data.base_url}
                                                onChange={(e) => accessForm.setData('base_url', e.target.value)}
                                                placeholder="https://192.168.1.10:12445"
                                            />
                                        </div>
                                        <div className="space-y-2">
                                            <Label>Access API Key</Label>
                                            <Input
                                                type="password"
                                                value={accessForm.data.secret}
                                                onChange={(e) => accessForm.setData('secret', e.target.value)}
                                                placeholder="Paste UniFi Access API key"
                                            />
                                        </div>
                                        <div className="md:col-span-2 flex flex-wrap gap-2">
                                            <Button type="submit" size="sm" disabled={accessForm.processing || !accessForm.data.secret}>
                                                {accessForm.processing ? 'Saving...' : 'Save Access API Key'}
                                            </Button>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={syncAccessEvents}
                                                disabled={!unifiSecret || !accessSecret?.base_url || syncingAccessEvents}
                                            >
                                                <RefreshCw className={`w-4 h-4 mr-1 ${syncingAccessEvents ? 'animate-spin' : ''}`} />
                                                {syncingAccessEvents ? 'Syncing...' : 'Sync Access Events'}
                                            </Button>
                                        </div>
                                    </form>
                                </div>
                            )}
                        </div>

                        <div className="rounded-lg border p-4 space-y-3">
                            <div className="text-sm font-medium">Location Mapping</div>
                            {!unifiSecret ? (
                                <div className="text-sm text-slate-400">
                                    Add an API key to map this location to a UniFi site.
                                </div>
                            ) : (
                                <>
                                    {unifiConfig?.mapped_external_site_id ? (
                                        <div className="text-sm text-slate-300">
                                            Mapped to{' '}
                                            <span className="font-medium">
                                                {mappedSiteLabel || 'Unnamed UniFi site'}
                                            </span>
                                            <div className="text-xs text-slate-500 font-mono">
                                                ID: {unifiConfig.mapped_external_site_id}
                                            </div>
                                            {unifiConfig?.overrides?.protect_host_id && (
                                                <div className="mt-2 text-xs text-slate-400">
                                                    Protect Console:{' '}
                                                    <span className="font-medium">
                                                        {unifiConfig.overrides.protect_host_name || 'Unnamed console'}
                                                    </span>
                                                    <div className="text-[11px] text-slate-500 font-mono">
                                                        ID: {unifiConfig.overrides.protect_host_id}
                                                    </div>
                                                </div>
                                            )}
                                            {unifiConfig?.overrides?.access_host_id && (
                                                <div className="mt-2 text-xs text-slate-400">
                                                    Access Console:{' '}
                                                    <span className="font-medium">
                                                        {unifiConfig.overrides.access_host_name || 'Unnamed console'}
                                                    </span>
                                                    <div className="text-[11px] text-slate-500 font-mono">
                                                        ID: {unifiConfig.overrides.access_host_id}
                                                    </div>
                                                </div>
                                            )}
                                        </div>
                                    ) : (
                                        <div className="text-sm text-slate-400">
                                            No UniFi site mapped to this location yet.
                                        </div>
                                    )}

                                    {canManageIntegrations && (
                                        <form onSubmit={submitUnifiMapping} className="space-y-3">
                                            <div className="space-y-2">
                                                <Label>Gateway (Network Site)</Label>
                                                {discoveredSites.length > 0 ? (
                                                    <Select
                                                        value={mapForm.data.mapped_external_site_id || undefined}
                                                        onValueChange={(v) => {
                                                            const selected = discoveredSites.find((s) => s.external_id === v);
                                                            const label = selected ? formatUnifiSiteLabel(selected) : null;
                                                            mapForm.setData({
                                                                ...mapForm.data,
                                                                mapped_external_site_id: v,
                                                                mapped_external_site_name: label?.displayName ?? selected?.name ?? '',
                                                                is_active: true,
                                                            });
                                                        }}
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select gateway site" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {discoveredSites.map((s) => {
                                                                const label = formatUnifiSiteLabel(s);
                                                                return (
                                                                    <SelectItem key={s.external_id} value={s.external_id} textValue={label.displayName}>
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium">{label.primary}</span>
                                                                            {label.secondary && <span className="text-xs text-muted-foreground">{label.secondary}</span>}
                                                                        </div>
                                                                    </SelectItem>
                                                                );
                                                            })}
                                                        </SelectContent>
                                                    </Select>
                                                ) : (
                                                    <Input
                                                        value={mapForm.data.mapped_external_site_id}
                                                        onChange={(e) => mapForm.setData('mapped_external_site_id', e.target.value)}
                                                        placeholder="Enter UniFi site ID"
                                                    />
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>Protect Console (NVR/NAS)</Label>
                                                {protectHosts.length > 0 ? (
                                                    <Select
                                                        value={mapForm.data.protect_host_id || undefined}
                                                        onValueChange={(v) => {
                                                            const selected = protectHosts.find((h) => h.host_id === v);
                                                            const label = selected ? formatUnifiHostLabel(selected) : null;
                                                            mapForm.setData({
                                                                ...mapForm.data,
                                                                protect_host_id: v,
                                                                protect_host_name: label?.displayName ?? selected?.name ?? '',
                                                            });
                                                        }}
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select Protect console" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {protectHosts.map((h) => {
                                                                const label = formatUnifiHostLabel(h);
                                                                return (
                                                                    <SelectItem key={h.host_id} value={h.host_id} textValue={label.displayName}>
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium">{label.primary}</span>
                                                                            {label.secondary && <span className="text-xs text-muted-foreground">{label.secondary}</span>}
                                                                        </div>
                                                                    </SelectItem>
                                                                );
                                                            })}
                                                        </SelectContent>
                                                    </Select>
                                                ) : (
                                                    <Input
                                                        value={mapForm.data.protect_host_id}
                                                        onChange={(e) => mapForm.setData('protect_host_id', e.target.value)}
                                                        placeholder="Enter Protect console host ID"
                                                    />
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>Access Console (Entry/Exit)</Label>
                                                {accessHosts.length > 0 ? (
                                                    <Select
                                                        value={mapForm.data.access_host_id || undefined}
                                                        onValueChange={(v) => {
                                                            const selected = accessHosts.find((h) => h.host_id === v);
                                                            const label = selected ? formatUnifiHostLabel(selected) : null;
                                                            mapForm.setData({
                                                                ...mapForm.data,
                                                                access_host_id: v,
                                                                access_host_name: label?.displayName ?? selected?.name ?? '',
                                                            });
                                                        }}
                                                    >
                                                        <SelectTrigger>
                                                            <SelectValue placeholder="Select Access console" />
                                                        </SelectTrigger>
                                                        <SelectContent>
                                                            {accessHosts.map((h) => {
                                                                const label = formatUnifiHostLabel(h);
                                                                return (
                                                                    <SelectItem key={h.host_id} value={h.host_id} textValue={label.displayName}>
                                                                        <div className="flex flex-col">
                                                                            <span className="font-medium">{label.primary}</span>
                                                                            {label.secondary && <span className="text-xs text-muted-foreground">{label.secondary}</span>}
                                                                        </div>
                                                                    </SelectItem>
                                                                );
                                                            })}
                                                        </SelectContent>
                                                    </Select>
                                                ) : (
                                                    <Input
                                                        value={mapForm.data.access_host_id}
                                                        onChange={(e) => mapForm.setData('access_host_id', e.target.value)}
                                                        placeholder="Enter Access console host ID"
                                                    />
                                                )}
                                            </div>

                                            <div className="space-y-2">
                                                <Label>UniFi Location Name (optional)</Label>
                                                <Input
                                                    value={mapForm.data.mapped_external_site_name}
                                                    onChange={(e) => mapForm.setData('mapped_external_site_name', e.target.value)}
                                                    placeholder="e.g., Head Office"
                                                />
                                            </div>
                                            <div className="flex flex-wrap gap-2">
                                                <Button type="submit" size="sm" disabled={mapForm.processing}>
                                                    {mapForm.processing ? 'Saving...' : 'Save Mapping'}
                                                </Button>
                                                {unifiConfig?.mapped_external_site_id && (
                                                    <Button type="button" size="sm" variant="ghost" onClick={clearUnifiMapping}>
                                                        Clear Mapping
                                                    </Button>
                                                )}
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => syncDevices('unifi')}
                                                    disabled={!canManageHardware || !unifiConfig?.mapped_external_site_id || syncingProvider === 'unifi'}
                                                >
                                                    <RefreshCw
                                                        className={`w-4 h-4 mr-1 ${syncingProvider === 'unifi' ? 'animate-spin' : ''}`}
                                                    />
                                                    {syncingProvider === 'unifi' ? 'Syncing...' : 'Sync Devices'}
                                                </Button>
                                            </div>
                                        </form>
                                    )}
                                </>
                            )}
                        </div>

                    </CardContent>
                </Card>

                {/* Search / Filter bar */}
                {hardware.length > 0 && (
                    <div className="flex flex-col gap-3 sm:flex-row">
                        <div className="relative flex-1">
                            <Search className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-400" />
                            <Input
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Search hardware by name, tag, serial, MAC..."
                                className="pl-10"
                            />
                        </div>
                        <Select value={filterStatus} onValueChange={setFilterStatus}>
                            <SelectTrigger className="w-full sm:w-40">
                                <SelectValue placeholder="Status" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">All Statuses</SelectItem>
                                <SelectItem value="online">Online</SelectItem>
                                <SelectItem value="offline">Offline</SelectItem>
                                <SelectItem value="unknown">Unknown</SelectItem>
                                <SelectItem value="retired">Retired</SelectItem>
                            </SelectContent>
                        </Select>
                        {providerOptions.length > 0 && (
                            <Select value={filterProvider} onValueChange={setFilterProvider}>
                                <SelectTrigger className="w-full sm:w-44">
                                    <SelectValue placeholder="Source" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Sources</SelectItem>
                                    {providerOptions.map((provider) => (
                                        <SelectItem key={provider} value={provider}>
                                            {provider}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                        {categoryKeys.length > 0 && (
                            <Select value={filterCategory} onValueChange={setFilterCategory}>
                                <SelectTrigger className="w-full sm:w-44">
                                    <SelectValue placeholder="Category" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All Categories</SelectItem>
                                    {categoryKeys.map((key) => (
                                        <SelectItem key={key} value={key}>
                                            {categories[key]}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        )}
                    </div>
                )}

                {/* Device list — sourced from canonical Security & Devices registry */}
                <Card>
                    <CardHeader>
                        <div className="flex items-center justify-between">
                            <CardTitle className="text-base">
                                Devices at this Site
                                {filteredHardware.length !== devices.length && (
                                    <span className="ml-2 text-sm font-normal text-slate-400">
                                        Showing {filteredHardware.length} of {devices.length}
                                    </span>
                                )}
                            </CardTitle>
                            <a
                                href={`/security-devices/devices/create?domain=&site_id=${site.id}`}
                                className="inline-flex items-center gap-1 rounded-md border border-input bg-background px-3 py-1.5 text-xs font-medium transition-colors hover:bg-accent hover:text-accent-foreground"
                            >
                                Manage in Security &amp; Devices →
                            </a>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {devices.length === 0 ? (
                            <div className="text-center py-12 text-slate-400">
                                <MonitorSmartphone className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p className="text-lg font-medium mb-1">No devices assigned to this site</p>
                                <p className="text-sm">Register and assign devices in Security &amp; Devices.</p>
                                <a href="/security-devices/devices/create" className="inline-flex items-center gap-1 mt-4 rounded-md bg-primary px-4 py-2 text-sm font-medium text-primary-foreground hover:bg-primary/90">
                                    <Plus className="w-4 h-4 mr-1" />
                                    Register Device
                                </a>
                            </div>
                        ) : filteredHardware.length === 0 ? (
                            <div className="text-center py-8 text-slate-400">
                                <Search className="w-10 h-10 mx-auto mb-3 opacity-50" />
                                <p>No devices match your filters.</p>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {filteredHardware.map((item) => {
                                    const isOnline = item.status === 'active';
                                    const isOffline = item.status === 'offline' || item.status === 'degraded';
                                    const roomName = item.assignment_type === 'room'
                                        ? rooms.find((r) => r.id === item.assignment_id)?.name
                                        : null;
                                    return (
                                        <a
                                            key={item.id}
                                            href={`/security-devices/devices/${item.id}`}
                                            className="block rounded-xl border p-4 hover:bg-muted/50 transition-colors"
                                        >
                                            <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                                <div className="flex-1 min-w-0">
                                                    <div className="flex items-center gap-2 flex-wrap">
                                                        <span className={`inline-block w-2.5 h-2.5 rounded-full ${isOnline ? 'bg-emerald-500' : isOffline ? 'bg-red-500' : 'bg-slate-400'}`} />
                                                        <span className="font-medium">{item.name}</span>
                                                        <Badge variant="outline" className="font-mono text-[10px]">{item.device_uid}</Badge>
                                                        <Badge variant="outline" className="text-xs">{item.category?.replace(/_/g, ' ')}</Badge>
                                                        {item.provider && (
                                                            <Badge variant="outline" className="text-xs border-indigo-500/30 text-indigo-300">{item.provider}</Badge>
                                                        )}
                                                        <Badge variant={isOnline ? 'default' : isOffline ? 'secondary' : 'outline'} className="text-[10px]">{item.status?.replace(/_/g, ' ')}</Badge>
                                                        <Badge variant={item.health_status === 'healthy' ? 'default' : item.health_status === 'critical' ? 'destructive' : 'outline'} className="text-[10px]">{item.health_status}</Badge>
                                                    </div>

                                                    <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-400">
                                                        {roomName && (
                                                            <span className="flex items-center gap-1">
                                                                <DoorOpen className="w-3.5 h-3.5" />
                                                                {roomName}
                                                            </span>
                                                        )}
                                                        {(item.manufacturer || item.model) && (
                                                            <span className="text-xs text-slate-500">
                                                                {[item.manufacturer, item.model].filter(Boolean).join(' ')}
                                                            </span>
                                                        )}
                                                        {item.mac_address && (
                                                            <span className="font-mono text-xs">MAC: {item.mac_address}</span>
                                                        )}
                                                        {item.serial_number && (
                                                            <span className="font-mono text-xs">S/N: {item.serial_number}</span>
                                                        )}
                                                        {item.asset_tag && (
                                                            <span className="font-mono text-xs">Tag: {item.asset_tag}</span>
                                                        )}
                                                        {item.ip_address && (
                                                            <span className="text-xs text-slate-500">IP: {item.ip_address}</span>
                                                        )}
                                                        {item.battery_level !== null && (
                                                            <span className="text-xs text-slate-500">Battery: {item.battery_level}%</span>
                                                        )}
                                                    </div>

                                                    {item.last_seen_at && (
                                                        <div className="mt-1 text-xs text-slate-500">
                                                            Last seen: {formatDate(item.last_seen_at)}
                                                        </div>
                                                    )}
                                                </div>
                                            </div>
                                        </a>
                                    );
                                })}
                            </div>
                        )}

                        {/* Legacy hardware action buttons removed — CRUD now in Security & Devices.
                            The original edit/delete/assign-room/link-asset buttons are no longer shown
                            here. Users should manage devices through Security & Devices module. */}
                    </CardContent>
                </Card>

                {/* ------------------------------------------------------------------ */}
                {/* UniFi Devices & Room Assignment                                    */}
                {/* ------------------------------------------------------------------ */}

                {(unifiDevices.length > 0 || unifiConfig?.mapped_external_site_id) && (
                    <Card>
                        <CardHeader className="flex flex-row items-center justify-between">
                            <CardTitle className="text-base flex items-center gap-2">
                                <Wifi className="w-4 h-4" />
                                UniFi Devices & Room Assignment
                            </CardTitle>
                            <div className="flex items-center gap-2">
                                <Badge variant="outline" className="text-slate-300">
                                    {unifiDevices.length} device{unifiDevices.length !== 1 ? 's' : ''}
                                </Badge>
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={() => syncDevices('unifi')}
                                    disabled={!canManageHardware || !unifiConfig?.mapped_external_site_id || syncingProvider === 'unifi'}
                                >
                                    <RefreshCw className={`w-4 h-4 mr-1 ${syncingProvider === 'unifi' ? 'animate-spin' : ''}`} />
                                    {syncingProvider === 'unifi' ? 'Syncing...' : 'Sync Devices'}
                                </Button>
                            </div>
                        </CardHeader>
                        <CardContent>
                            {unifiDevices.length === 0 ? (
                                <div className="text-center py-8 text-slate-400">
                                    <Wifi className="w-10 h-10 mx-auto mb-3 opacity-50" />
                                    <p>No UniFi devices synced yet for this site.</p>
                                    <p className="text-sm mt-1">Map a UniFi location and run Sync Devices.</p>
                                </div>
                            ) : rooms.length === 0 ? (
                                <div className="text-center py-8 text-slate-400">
                                    <DoorOpen className="w-10 h-10 mx-auto mb-3 opacity-50" />
                                    <p>No rooms configured yet.</p>
                                    <p className="text-sm mt-1">Add rooms below to assign UniFi hardware.</p>
                                </div>
                            ) : (
                                <div className="rounded-md border">
                                    <Table>
                                        <TableHeader>
                                            <TableRow>
                                                <TableHead>Device</TableHead>
                                                <TableHead>Category</TableHead>
                                                <TableHead>Status</TableHead>
                                                <TableHead>Room</TableHead>
                                                <TableHead>Last Seen</TableHead>
                                                <TableHead>Action</TableHead>
                                            </TableRow>
                                        </TableHeader>
                                        <TableBody>
                                            {unifiDevices.map((device) => {
                                                const sc = statusConfig[device.status];
                                                const StatusIcon = sc.icon;
                                                const model = resolveDeviceModel(device);
                                                const type = resolveDeviceType(device);
                                                const displayName = device.name && device.name !== 'Unknown Device'
                                                    ? device.name
                                                    : model || type || 'UniFi Device';
                                                return (
                                                    <TableRow key={device.id}>
                                                        <TableCell>
                                                            <div className="font-medium">{displayName}</div>
                                                            <div className="text-xs text-slate-500">
                                                                {[
                                                                    type ? `Type ${type}` : null,
                                                                    model ? `Model ${model}` : null,
                                                                    device.external_ref?.firmware ? `FW ${device.external_ref.firmware}` : null,
                                                                    device.external_ref?.ip ? `IP ${device.external_ref.ip}` : null,
                                                                ]
                                                                    .filter(Boolean)
                                                                    .join(' • ') || '—'}
                                                            </div>
                                                            <div className="text-xs text-slate-500 mt-1">
                                                                {[device.mac ? `MAC ${device.mac}` : null, device.serial ? `S/N ${device.serial}` : null, device.external_ref?.provider_entity_id ? `ID ${device.external_ref.provider_entity_id}` : null]
                                                                    .filter(Boolean)
                                                                    .join(' • ') || '—'}
                                                            </div>
                                                        </TableCell>
                                                        <TableCell>
                                                            {categories[device.category] || device.category}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Badge variant="outline" className={`text-xs ${sc.className}`}>
                                                                <StatusIcon className="w-3 h-3 mr-1" />
                                                                {sc.label}
                                                            </Badge>
                                                        </TableCell>
                                                        <TableCell className="min-w-[200px]">
                                                            <Select
                                                                value={deviceRoomDraft[device.id] || 'unassigned'}
                                                                onValueChange={(v) => setDeviceRoomDraft((prev) => ({ ...prev, [device.id]: v }))}
                                                                disabled={!canManageHardware}
                                                            >
                                                                <SelectTrigger>
                                                                    <SelectValue placeholder="Select room" />
                                                                </SelectTrigger>
                                                                <SelectContent>
                                                                    <SelectItem value="unassigned">Unassigned</SelectItem>
                                                                    {rooms.map((room) => (
                                                                        <SelectItem key={room.id} value={room.id.toString()}>
                                                                            {room.name}
                                                                        </SelectItem>
                                                                    ))}
                                                                </SelectContent>
                                                            </Select>
                                                        </TableCell>
                                                        <TableCell className="text-sm text-slate-400">
                                                            {formatDate(device.last_seen_at) || '—'}
                                                        </TableCell>
                                                        <TableCell>
                                                            <Button
                                                                size="sm"
                                                                variant="outline"
                                                                onClick={() => saveUnifiRoom(device.id)}
                                                                disabled={!canManageHardware || assigningDeviceId === device.id}
                                                            >
                                                                {assigningDeviceId === device.id ? 'Saving...' : 'Save Room'}
                                                            </Button>
                                                        </TableCell>
                                                    </TableRow>
                                                );
                                            })}
                                        </TableBody>
                                    </Table>
                                </div>
                            )}
                        </CardContent>
                    </Card>
                )}

                {/* ------------------------------------------------------------------ */}
                {/* Section 2 - Rooms                                                  */}
                {/* ------------------------------------------------------------------ */}

                <Card>
                    <CardHeader className="flex flex-row items-center justify-between">
                        <CardTitle className="flex items-center gap-2">
                            <DoorOpen className="w-5 h-5" />
                            Rooms
                            <Badge variant="outline" className="ml-1 text-slate-300">
                                {rooms.length}
                            </Badge>
                        </CardTitle>
                        {!showAddRoom && (
                            <Button variant="secondary" size="sm" onClick={() => setShowAddRoom(true)}>
                                <Plus className="w-4 h-4 mr-1" />
                                Add Room
                            </Button>
                        )}
                    </CardHeader>
                    <CardContent>
                        {/* Add room inline form */}
                        {showAddRoom && (
                            <form onSubmit={submitRoom} className="flex items-end gap-2 mb-4">
                                <div className="flex-1">
                                    <Label>Room Name</Label>
                                    <Input
                                        value={roomForm.data.name}
                                        onChange={(e) => roomForm.setData('name', e.target.value)}
                                        placeholder="e.g., Server Room, Office A"
                                        required
                                    />
                                </div>
                                <Button type="submit" disabled={addingRoom}>
                                    {addingRoom ? 'Adding...' : 'Add'}
                                </Button>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    onClick={() => {
                                        setShowAddRoom(false);
                                        roomForm.reset();
                                    }}
                                >
                                    Cancel
                                </Button>
                            </form>
                        )}

                        {rooms.length === 0 && !showAddRoom ? (
                            <div className="text-center py-8 text-slate-400">
                                <DoorOpen className="w-12 h-12 mx-auto mb-3 opacity-50" />
                                <p>No rooms configured yet.</p>
                                <Button
                                    variant="outline"
                                    className="mt-4"
                                    onClick={() => setShowAddRoom(true)}
                                >
                                    <Plus className="w-4 h-4 mr-1" />
                                    Add Your First Room
                                </Button>
                            </div>
                        ) : (
                            <div className="space-y-2">
                                {rooms.map((room) => {
                                    const count = hwCountInRoom(room.id);
                                    const isEditing = editingRoomId === room.id;
                                    return (
                                        <div
                                            key={room.id}
                                            className="flex items-center justify-between rounded-lg border p-3"
                                        >
                                            {isEditing ? (
                                                <div className="flex items-center gap-2 flex-1">
                                                    <Input
                                                        value={editingRoomName}
                                                        onChange={(e) => setEditingRoomName(e.target.value)}
                                                        className="max-w-xs"
                                                        autoFocus
                                                        onKeyDown={(e) => {
                                                            if (e.key === 'Enter') {
                                                                e.preventDefault();
                                                                submitEditRoom(room.id);
                                                            }
                                                            if (e.key === 'Escape') {
                                                                setEditingRoomId(null);
                                                            }
                                                        }}
                                                    />
                                                    <Button size="sm" onClick={() => submitEditRoom(room.id)} disabled={savingRoomId === room.id}>
                                                        {savingRoomId === room.id ? 'Saving...' : 'Save'}
                                                    </Button>
                                                    <Button
                                                        size="sm"
                                                        variant="ghost"
                                                        onClick={() => setEditingRoomId(null)}
                                                    >
                                                        Cancel
                                                    </Button>
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="flex items-center gap-3">
                                                        <span className="font-medium">{room.name}</span>
                                                        <span className="text-xs text-slate-500">
                                                            #{room.sort_order}
                                                        </span>
                                                        <Badge variant="outline" className="text-xs text-slate-400">
                                                            {count} device{count !== 1 ? 's' : ''}
                                                        </Badge>
                                                    </div>
                                                    <div className="flex items-center gap-1">
                                                        <Button
                                                            variant="ghost"
                                                            size="sm"
                                                            onClick={() => startEditRoom(room)}
                                                        >
                                                            <Pencil className="w-4 h-4" />
                                                        </Button>
                                                        <AlertDialog>
                                                            <AlertDialogTrigger asChild>
                                                                <Button
                                                                    variant="ghost"
                                                                    size="sm"
                                                                    className="text-red-400 hover:text-red-300"
                                                                >
                                                                    <Trash2 className="w-4 h-4" />
                                                                </Button>
                                                            </AlertDialogTrigger>
                                                            <AlertDialogContent>
                                                                <AlertDialogHeader>
                                                                    <AlertDialogTitle>Delete Room</AlertDialogTitle>
                                                                    <AlertDialogDescription>
                                                                        Delete &quot;{room.name}&quot;?
                                                                        {count > 0 &&
                                                                            ` This room has ${count} hardware item${count !== 1 ? 's' : ''} assigned. They will become unassigned.`}
                                                                    </AlertDialogDescription>
                                                                </AlertDialogHeader>
                                                                <AlertDialogFooter>
                                                                    <AlertDialogCancel>Cancel</AlertDialogCancel>
                                                                    <AlertDialogAction
                                                                        className="bg-red-600 hover:bg-red-700"
                                                                    onClick={() => deleteRoom(room.id)}
                                                                    disabled={savingRoomId === room.id}
                                                                >
                                                                        {savingRoomId === room.id ? 'Deleting...' : 'Delete'}
                                                                    </AlertDialogAction>
                                                                </AlertDialogFooter>
                                                            </AlertDialogContent>
                                                        </AlertDialog>
                                                    </div>
                                                </>
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </CardContent>
                </Card>

                {/* ------------------------------------------------------------------ */}
                {/* Section 3 - Active Integrations                                    */}
                {/* ------------------------------------------------------------------ */}

                {integrations.length > 0 && (
                    <div className="space-y-4">
                        <h2 className="text-lg font-semibold flex items-center gap-2">
                            <Server className="w-5 h-5" />
                            Active Integrations
                        </h2>
                        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {integrations.map((integration) => (
                                <Card key={integration.id}>
                                    <CardHeader className="pb-2">
                                        <CardTitle className="text-base flex items-center justify-between">
                                            <span className="capitalize">{integration.provider}</span>
                                            <Badge
                                                variant="outline"
                                                className={
                                                    integration.is_active
                                                        ? 'bg-emerald-500/20 text-emerald-400 border-emerald-500/30'
                                                        : 'bg-slate-500/20 text-slate-300 border-slate-500/30'
                                                }
                                            >
                                                {integration.status}
                                            </Badge>
                                        </CardTitle>
                                    </CardHeader>
                                    <CardContent className="space-y-3">
                                        {(integration.mapped_external_site_id ||
                                            integration.mapped_external_site_name) && (
                                            <div className="text-sm text-slate-400">
                                                <div>
                                                    Mapped to:{' '}
                                                    <span className="text-slate-300">
                                                        {integration.mapped_external_site_name ||
                                                            integration.mapped_external_site_id}
                                                    </span>
                                                </div>
                                                {integration.mapped_external_site_id &&
                                                    integration.mapped_external_site_name && (
                                                        <div className="text-xs text-slate-500 font-mono">
                                                            ID: {integration.mapped_external_site_id}
                                                        </div>
                                                    )}
                                            </div>
                                        )}
                                        <div className="flex items-center gap-2">
                                            <Button
                                                size="sm"
                                                onClick={() => syncDevices(integration.provider)}
                                                disabled={syncingProvider === integration.provider}
                                            >
                                                <RefreshCw
                                                    className={`w-4 h-4 mr-1 ${
                                                        syncingProvider === integration.provider ? 'animate-spin' : ''
                                                    }`}
                                                />
                                                {syncingProvider === integration.provider
                                                    ? 'Syncing...'
                                                    : 'Sync Devices'}
                                            </Button>
                                            <Button asChild variant="outline" size="sm">
                                                <a href="/settings/integrations">
                                                    <Settings className="w-4 h-4 mr-1" />
                                                    Configure
                                                </a>
                                            </Button>
                                        </div>
                                    </CardContent>
                                </Card>
                            ))}
                        </div>
                    </div>
                )}
            </PageShell>

            {/* ================================================================== */}
            {/* Dialogs                                                            */}
            {/* ================================================================== */}

            {/* Add / Edit Hardware Dialog */}
            <Dialog open={showAddDialog} onOpenChange={(open) => !open && closeHwDialog()}>
                <DialogContent className="sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>{editingItem ? 'Edit Hardware' : 'Add Hardware'}</DialogTitle>
                    </DialogHeader>
                    <form onSubmit={submitHardware} className="space-y-4">
                        <div className="grid gap-4 sm:grid-cols-2">
                            <div className="sm:col-span-2">
                                <Label>Name *</Label>
                                <Input
                                    value={hwForm.data.name}
                                    onChange={(e) => hwForm.setData('name', e.target.value)}
                                    placeholder="e.g., Main Router, Kitchen Camera"
                                    required
                                />
                                {hwForm.errors.name && (
                                    <p className="text-xs text-red-400 mt-1">{hwForm.errors.name}</p>
                                )}
                            </div>
                            <div>
                                <Label>Category *</Label>
                                <Select
                                    value={hwForm.data.category || undefined}
                                    onValueChange={(v) => hwForm.setData('category', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select category" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {categoryKeys.map((key) => (
                                            <SelectItem key={key} value={key}>
                                                {categories[key]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {hwForm.errors.category && (
                                    <p className="text-xs text-red-400 mt-1">{hwForm.errors.category}</p>
                                )}
                            </div>
                            <div>
                                <Label>Provider</Label>
                                <Input
                                    value={hwForm.data.provider}
                                    onChange={(e) => hwForm.setData('provider', e.target.value)}
                                    placeholder="manual"
                                />
                            </div>
                            <div>
                                <Label>Room</Label>
                                <Select
                                    value={hwForm.data.room_id || undefined}
                                    onValueChange={(v) => hwForm.setData('room_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="No room assigned" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {rooms.map((room) => (
                                            <SelectItem key={room.id} value={room.id.toString()}>
                                                {room.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div>
                                <Label>Asset Tag</Label>
                                <Input
                                    value={hwForm.data.asset_tag}
                                    onChange={(e) => hwForm.setData('asset_tag', e.target.value)}
                                    placeholder="Optional"
                                />
                            </div>
                            <div>
                                <Label>Serial Number</Label>
                                <Input
                                    value={hwForm.data.serial}
                                    onChange={(e) => hwForm.setData('serial', e.target.value)}
                                    placeholder="Optional"
                                />
                            </div>
                            <div>
                                <Label>MAC Address</Label>
                                <Input
                                    value={hwForm.data.mac}
                                    onChange={(e) => hwForm.setData('mac', e.target.value)}
                                    placeholder="AA:BB:CC:DD:EE:FF"
                                />
                            </div>
                        </div>
                        <div>
                            <Label>Notes</Label>
                            <Textarea
                                value={hwForm.data.notes}
                                onChange={(e) => hwForm.setData('notes', e.target.value)}
                                placeholder="Any additional notes..."
                                rows={3}
                            />
                        </div>
                        <div className="flex gap-2 justify-end">
                            <Button type="button" variant="outline" onClick={closeHwDialog}>
                                Cancel
                            </Button>
                            <Button type="submit" disabled={hwForm.processing}>
                                {hwForm.processing
                                    ? 'Saving...'
                                    : editingItem
                                      ? 'Save Changes'
                                      : 'Add Hardware'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>

            {/* Assign Room Dialog */}
            <Dialog
                open={!!assignRoomItem}
                onOpenChange={(open) => {
                    if (!open) {
                        setAssignRoomItem(null);
                        assignRoomForm.reset();
                    }
                }}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Assign Room</DialogTitle>
                    </DialogHeader>
                    {assignRoomItem && (
                        <form onSubmit={submitAssignRoom} className="space-y-4">
                            <p className="text-sm text-slate-400">
                                Assign <span className="text-slate-200 font-medium">{assignRoomItem.name}</span> to a
                                room.
                            </p>
                            <div>
                                <Label>Room</Label>
                                <Select
                                    value={assignRoomForm.data.room_id || undefined}
                                    onValueChange={(v) => assignRoomForm.setData('room_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select a room" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {rooms.map((room) => (
                                            <SelectItem key={room.id} value={room.id.toString()}>
                                                {room.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex gap-2 justify-end">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setAssignRoomItem(null);
                                        assignRoomForm.reset();
                                    }}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={assignRoomForm.processing}>
                                    {assignRoomForm.processing ? 'Saving...' : 'Assign'}
                                </Button>
                            </div>
                        </form>
                    )}
                </DialogContent>
            </Dialog>

            {/* Link Asset Dialog */}
            <Dialog
                open={!!linkAssetItem}
                onOpenChange={(open) => {
                    if (!open) {
                        setLinkAssetItem(null);
                        linkAssetForm.reset();
                    }
                }}
            >
                <DialogContent className="sm:max-w-sm">
                    <DialogHeader>
                        <DialogTitle>Link Asset</DialogTitle>
                    </DialogHeader>
                    {linkAssetItem && (
                        <form onSubmit={submitLinkAsset} className="space-y-4">
                            <p className="text-sm text-slate-400">
                                Link <span className="text-slate-200 font-medium">{linkAssetItem.name}</span> to an
                                asset record.
                            </p>
                            <div>
                                <Label>Asset</Label>
                                <Select
                                    value={linkAssetForm.data.linked_asset_id || undefined}
                                    onValueChange={(v) => linkAssetForm.setData('linked_asset_id', v)}
                                >
                                    <SelectTrigger>
                                        <SelectValue placeholder="Select an asset" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {assets.map((asset) => (
                                            <SelectItem key={asset.id} value={asset.id.toString()}>
                                                {asset.name}
                                                {asset.asset_tag ? ` (${asset.asset_tag})` : ''}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <div className="flex gap-2 justify-end">
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={() => {
                                        setLinkAssetItem(null);
                                        linkAssetForm.reset();
                                    }}
                                >
                                    Cancel
                                </Button>
                                <Button type="submit" disabled={linkAssetForm.processing}>
                                    {linkAssetForm.processing ? 'Saving...' : 'Link'}
                                </Button>
                            </div>
                        </form>
                    )}
                </DialogContent>
            </Dialog>
        </AppLayout>
    );
}
