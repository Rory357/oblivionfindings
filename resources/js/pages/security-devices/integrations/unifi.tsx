import { ConfirmDialog } from '@/components/confirm-dialog';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import {
    AlertDialog,
    AlertDialogCancel,
    AlertDialogContent,
    AlertDialogDescription,
    AlertDialogFooter,
    AlertDialogHeader,
    AlertDialogTitle,
} from '@/components/ui/alert-dialog';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { RadioGroup, RadioGroupItem } from '@/components/ui/radio-group';
import { ReviewRow } from '@/components/wizard/shell';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    Building2,
    CheckCircle,
    LayoutGrid,
    List,
    Plug,
    RefreshCw,
    ShieldAlert,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import type { SiteCredentialRow } from './site-credentials';
import { useUnifiAction } from './unifi-actions';
import { UnifiDiscoveredRecords, UnifiRecords } from './unifi-records';
import { UnifiRoomDialog } from './unifi-room-dialog';
import {
    UnifiSetupWizard,
    type UnifiSetupChange,
    type UnifiSetupKind,
    type UnifiSetupValues,
} from './unifi-setup-wizard';

type ProviderConnection = {
    status: 'connected' | 'disconnected' | 'disabled' | 'error';
    secret_last4?: string | null;
    last_tested_at?: string | null;
    last_synced_at?: string | null;
    sites_synced_at?: string | null;
    disabled_at?: string | null;
    disabled_reason?: DisableReason | null;
    requires_credential_replacement?: boolean;
    recovery_credentials_replaced_at?: string | null;
    defaults?: {
        refresh_interval_minutes?: number;
        alert_motion_events?: boolean;
        alert_device_offline?: boolean;
        quiet_hours_start?: string;
        quiet_hours_end?: string;
    };
} | null;

type DisableReason =
    | 'provider_outage'
    | 'credential_compromise'
    | 'planned_maintenance'
    | 'security_review'
    | 'other_operational_reason';

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
    site_id: number | null;
    site_name: string;
    site_type?: string | null;
    room_id?: number | null;
    name: string;
    domain: string;
    category: string;
    subcategory: string | null;
    manufacturer: string | null;
    firmware_version: string | null;
    room_name: string | null;
    detail_url: string;
    status: string | null;
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
    started_at: string | null;
    completed_at?: string | null;
};

export type UnifiProps = {
    actor_id: number;
    actionOutcome?: {
        actor_id: number;
        request_id: string;
        method: string;
        path: string;
        outcome: 'saved';
    } | null;
    as_of: string;
    can: { manage: boolean };
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
    { title: 'Home', href: '/dashboard' },
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
    disabled: {
        label: 'Disabled',
        className:
            'bg-status-warning-bg text-status-warning dark:bg-status-warning-bg dark:text-status-warning',
        icon: ShieldAlert,
    },
};

const disableReasons: Array<{ value: DisableReason; label: string }> = [
    { value: 'provider_outage', label: 'Provider outage or instability' },
    { value: 'credential_compromise', label: 'Credential compromise' },
    { value: 'planned_maintenance', label: 'Planned maintenance' },
    { value: 'security_review', label: 'Security review' },
    { value: 'other_operational_reason', label: 'Other operational reason' },
];

function disableReasonLabel(reason?: DisableReason | null): string {
    return (
        disableReasons.find((option) => option.value === reason)?.label ??
        'Operational decision'
    );
}

function fmt(value?: string | null): string {
    if (!value) return 'Not recorded';
    if (!/(Z|[+-]\d{2}:\d{2})$/.test(value)) return 'Time zone not recorded';
    return formatDateTimeLong(value, 'Time unavailable');
}

const unifiPath = '/security-devices/integrations/unifi';
const views = [
    { key: 'connection', label: 'Connection' },
    { key: 'mappings', label: 'Site mappings' },
    { key: 'devices', label: 'Devices' },
    { key: 'defaults', label: 'Defaults' },
    { key: 'history', label: 'Sync history' },
    { key: 'credentials', label: 'Site credentials' },
] as const;
type UnifiView = (typeof views)[number]['key'];

export default function UnifiIntegration(data: UnifiProps) {
    const { auth } = usePage<{
        auth?: {
            user?: { id: number } | null;
            can?: {
                securityDevices?: {
                    viewAny?: boolean;
                    integrationsManage?: boolean;
                };
            };
        };
    }>().props;
    const [denied, setDenied] = useState(false),
        [notice, setNotice] = useState('');
    const alive = useRef(true),
        active = useRef<string | null>(null);
    const actor = auth?.user?.id;
    const [sourceActor] = useState(data.actor_id);
    const permitted = Boolean(
        actor &&
        sourceActor === data.actor_id &&
        data.actor_id === actor &&
        data.can.manage &&
        auth?.can?.securityDevices?.viewAny === true &&
        auth.can.securityDevices?.integrationsManage === true,
    );
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
            active.current = null;
        };
    }, []);
    useEffect(
        () =>
            router.on('invalid', (event) => {
                if (!alive.current || active.current === null) return;
                const response = event.detail.response;
                const headers = response.config.headers;
                const responseMarker =
                    typeof headers?.get === 'function'
                        ? headers.get('X-Obli-UniFi-Read')
                        : headers?.['X-Obli-UniFi-Read'];
                if (responseMarker !== active.current) return;
                if (
                    response.config.method?.toLowerCase() !== 'get' ||
                    typeof response.config.url !== 'string'
                )
                    return;
                let target: URL;
                try {
                    target = new URL(
                        response.config.url,
                        window.location.origin,
                    );
                } catch {
                    return;
                }
                if (
                    target.origin !== window.location.origin ||
                    target.pathname !== unifiPath
                )
                    return;
                event.preventDefault();
                active.current = null;
                if ([401, 403, 404].includes(response.status)) {
                    setDenied(true);
                    setNotice('');
                } else
                    setNotice(
                        'Refresh failed. Previous records remain dated by their read time. Try Refresh again.',
                    );
            }),
        [],
    );
    const refresh = () => {
        if (active.current !== null) return;
        const token = crypto.randomUUID();
        active.current = token;
        setNotice('');
        router.visit(unifiPath + window.location.search, {
            method: 'get',
            headers: { 'X-Obli-UniFi-Read': token },
            preserveScroll: true,
            preserveState: false,
            onSuccess: (page) => {
                if (!alive.current || active.current !== token) return;
                const next = page.props as unknown as UnifiProps & {
                    auth?: {
                        user?: { id: number };
                        can?: {
                            securityDevices?: {
                                viewAny?: boolean;
                                integrationsManage?: boolean;
                            };
                        };
                    };
                };
                const valid =
                    page.component === 'security-devices/integrations/unifi' &&
                    next.actor_id === actor &&
                    next.auth?.user?.id === actor &&
                    next.can?.manage === true &&
                    next.auth.can?.securityDevices?.viewAny === true &&
                    next.auth.can?.securityDevices?.integrationsManage === true;
                setDenied(!valid);
            },
            onFinish: () => {
                if (alive.current && active.current === token)
                    active.current = null;
            },
        });
    };
    if (!permitted || denied)
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="UniFi Integration" />
                <div className="space-y-5">
                    <PageHeader
                        icon={Plug}
                        title="UniFi Integration"
                        subline="Connection management is restricted."
                        actions={
                            <PageHeaderGlassButton onClick={refresh}>
                                <RefreshCw className="size-4" />
                                Refresh
                            </PageHeaderGlassButton>
                        }
                    />
                    <p
                        role="status"
                        className="rounded-xl border bg-card p-5 text-sm"
                    >
                        UniFi records are restricted. Ask an administrator to
                        restore access, then Refresh.
                    </p>
                </div>
            </AppLayout>
        );
    return (
        <UnifiWorkspace
            key={String(actor)}
            {...data}
            refresh={refresh}
            notice={notice}
            onAccessDenied={() => setDenied(true)}
        />
    );
}

function UnifiWorkspace({
    providerConnection,
    discoveredSites,
    siteConfigs,
    sites,
    rooms,
    syncedDevices,
    syncLogs,
    siteCredentials,
    as_of,
    actor_id,
    refresh,
    notice,
    onAccessDenied,
}: UnifiProps & {
    refresh: () => void;
    notice: string;
    onAccessDenied: () => void;
}) {
    const [scope, setScope] = useState<UnifiView>('connection');
    const [layout, setLayout] = useState<'cards' | 'table'>('table');
    const [search, setSearch] = useState('');
    const [deviceSiteFilter, setDeviceSiteFilter] = useState('all');
    const [setupKind, setSetupKind] = useState<UnifiSetupKind | null>(null);
    const [setupSuccess, setSetupSuccess] = useState<{
        title: string;
        description: string;
    } | null>(null);
    const [roomDeviceId, setRoomDeviceId] = useState<number | null>(null);
    const [roomDraft, setRoomDraft] = useState<{
        id: number;
        site_id: number | null;
        room_id: number | null;
        value: string;
    } | null>(null);
    const [removeMappingId, setRemoveMappingId] = useState<number | null>(null);
    const [disableOpen, setDisableOpen] = useState(false);
    const [disableReason, setDisableReason] = useState<DisableReason | null>(
        null,
    );
    const { auth } = usePage<{
        auth?: { can?: { securityDevices?: { devicesView?: boolean } } };
    }>().props;
    const canViewDevices = auth?.can?.securityDevices?.devicesView === true;
    const action = useUnifiAction(actor_id, onAccessDenied);
    const keyForm = useForm({ api_key: '' });
    const rotateForm = useForm({ api_key: '' });
    const mapForm = useForm({ site_id: '', mapping_token: '' });
    const defaultValues = useMemo(
        () => ({
            refresh_interval_minutes:
                providerConnection?.defaults?.refresh_interval_minutes ?? 15,
            alert_motion_events:
                providerConnection?.defaults?.alert_motion_events ?? false,
            alert_device_offline:
                providerConnection?.defaults?.alert_device_offline ?? true,
            quiet_hours_start:
                providerConnection?.defaults?.quiet_hours_start ?? '',
            quiet_hours_end:
                providerConnection?.defaults?.quiet_hours_end ?? '',
        }),
        [providerConnection?.defaults],
    );
    const defaultsForm = useForm(defaultValues);
    useEffect(() => {
        if (
            !defaultsForm.isDirty &&
            !action.pending &&
            JSON.stringify(defaultsForm.data) !== JSON.stringify(defaultValues)
        ) {
            defaultsForm.setDefaults(defaultValues);
            defaultsForm.setData(defaultValues);
        }
    }, [action.pending, defaultValues, defaultsForm]);
    const selectedDevice =
        syncedDevices.find(
            (d) =>
                d.id === roomDeviceId &&
                sites.some((site) => site.id === d.site_id),
        ) ?? null;
    const currentRoom = (d: SyncedDevice) =>
        d.room_id ? String(d.room_id) : 'unassigned';
    const roomValue =
        selectedDevice &&
        roomDraft?.id === selectedDevice.id &&
        roomDraft.site_id === selectedDevice.site_id &&
        roomDraft.room_id === (selectedDevice.room_id ?? null)
            ? roomDraft.value
            : selectedDevice
              ? currentRoom(selectedDevice)
              : 'unassigned';
    const roomDirty =
        !!selectedDevice && roomValue !== currentRoom(selectedDevice);
    const leave = useSettingsLeaveConfirmation(
        keyForm.isDirty ||
            rotateForm.isDirty ||
            mapForm.isDirty ||
            defaultsForm.isDirty ||
            roomDirty,
        'Discard unsaved UniFi settings?',
        'Your unsaved key, mapping, room and default entries will be discarded. A save already sent may still finish; review saved records before retrying.',
        () => {
            keyForm.reset();
            rotateForm.reset();
            mapForm.reset();
            defaultsForm.setDefaults(defaultValues);
            defaultsForm.setData(defaultValues);
            setRoomDraft(null);
            setRoomDeviceId(null);
            setSetupKind(null);
            setSetupSuccess(null);
        },
    );
    const hasKey = providerConnection !== null;
    const connectionDisabled =
        providerConnection?.status === 'disabled' ||
        providerConnection?.requires_credential_replacement === true;
    const collectionReady =
        providerConnection?.status === 'connected' && !connectionDisabled;
    const connStatus = providerConnection
        ? (connectionStatusConfig[providerConnection.status] ??
          connectionStatusConfig.disconnected)
        : null;
    const matches = (record: unknown) =>
        JSON.stringify(record)
            .toLocaleLowerCase()
            .includes(search.trim().toLocaleLowerCase());
    const visibleMappings = siteConfigs.filter(matches),
        visibleLogs = syncLogs.filter(matches),
        visibleCredentials = siteCredentials.filter(matches),
        visibleDiscovered = discoveredSites.filter(matches);
    const filteredDevices = syncedDevices.filter(
        (d) =>
            (deviceSiteFilter === 'all' ||
                d.site_id === Number(deviceSiteFilter)) &&
            matches(d),
    );
    const selectedMapping =
        siteConfigs.find((c) => c.id === removeMappingId) ?? null;
    const blocked = action.pending
        ? 'Another request is pending.'
        : action.uncertain
          ? 'Refresh the saved status before repeating an action.'
          : undefined;
    const selectView = (view: UnifiView) => {
        if (view !== scope)
            leave.request(() => {
                setScope(view);
                setSearch('');
                setRoomDeviceId(null);
            });
    };
    const openSetup = (kind: UnifiSetupKind, token?: string) => {
        if (action.pending || action.uncertain) return;
        leave.request(() => {
            action.clearMessage();
            setSetupSuccess(null);
            if (token) mapForm.setData('mapping_token', token);
            setSetupKind(kind);
        });
    };
    const setupValues: UnifiSetupValues = {
        api_key:
            setupKind === 'rotate'
                ? rotateForm.data.api_key
                : setupKind === 'key'
                  ? keyForm.data.api_key
                  : '',
        site_id: mapForm.data.site_id,
        mapping_token: mapForm.data.mapping_token,
        ...defaultsForm.data,
    };
    const changeSetup: UnifiSetupChange = (field, value) => {
        if (field === 'api_key' && typeof value === 'string') {
            if (setupKind === 'rotate') rotateForm.setData('api_key', value);
            else keyForm.setData('api_key', value);
        } else if (
            (field === 'site_id' || field === 'mapping_token') &&
            typeof value === 'string'
        )
            mapForm.setData(field, value);
        else if (
            field === 'refresh_interval_minutes' &&
            typeof value === 'number'
        )
            defaultsForm.setData(field, value);
        else if (
            (field === 'alert_motion_events' ||
                field === 'alert_device_offline') &&
            typeof value === 'boolean'
        )
            defaultsForm.setData(field, value);
        else if (
            (field === 'quiet_hours_start' || field === 'quiet_hours_end') &&
            (typeof value === 'string' || value === null)
        )
            defaultsForm.setData(field, value ?? '');
    };
    const submitSetup = () => {
        if (!setupKind || action.pending || action.uncertain) return;
        if (setupKind === 'key' || setupKind === 'rotate') {
            const form = setupKind === 'key' ? keyForm : rotateForm,
                key = form.data.api_key,
                kind = setupKind;
            if (!key.trim() || key.length > 4096) return;
            action.execute({
                method: 'post',
                path: unifiPath + (kind === 'key' ? '/key' : '/rotate'),
                payload: { api_key: key },
                label: kind === 'key' ? 'Key save' : 'Key replacement',
                confirmed: (next) =>
                    !!next.providerConnection &&
                    next.providerConnection.secret_last4 === key.slice(-4) &&
                    next.providerConnection.status === 'disconnected',
                onConfirmed: () => {
                    form.reset();
                    setSetupSuccess({
                        title: 'Key saved',
                        description:
                            'Test the connection before collection can resume.',
                    });
                },
            });
        } else if (setupKind === 'mapping') {
            if (
                !sites.some(
                    (site) => String(site.id) === mapForm.data.site_id,
                ) ||
                !discoveredSites.some(
                    (site) => site.mapping_token === mapForm.data.mapping_token,
                )
            )
                return;
            const siteId = Number(mapForm.data.site_id);
            const locationName = discoveredSites.find(
                (site) => site.mapping_token === mapForm.data.mapping_token,
            )!.name;
            action.execute({
                method: 'post',
                path: unifiPath + '/map-site',
                payload: {
                    site_id: mapForm.data.site_id,
                    mapping_token: mapForm.data.mapping_token,
                },
                label: 'Site mapping',
                confirmed: (next) =>
                    next.siteConfigs?.some(
                        (c) =>
                            c.site_id === siteId &&
                            c.is_active &&
                            c.mapped_external_site_name === locationName,
                    ) === true,
                onConfirmed: () => {
                    mapForm.reset();
                    setSetupSuccess({
                        title: 'Site mapped',
                        description:
                            'The mapping is saved. Device collection remains a separate action.',
                    });
                },
            });
        } else {
            const config = {
                refresh_interval_minutes:
                    defaultsForm.data.refresh_interval_minutes,
                alert_motion_events: defaultsForm.data.alert_motion_events,
                alert_device_offline: defaultsForm.data.alert_device_offline,
                quiet_hours_start: defaultsForm.data.quiet_hours_start || null,
                quiet_hours_end: defaultsForm.data.quiet_hours_end || null,
            };
            action.execute({
                method: 'put',
                path: unifiPath + '/defaults',
                payload: { config },
                label: 'Default settings',
                confirmed: (next) =>
                    !!next.providerConnection?.defaults &&
                    Object.entries(config).every(
                        ([key, value]) =>
                            (
                                next.providerConnection!.defaults as Record<
                                    string,
                                    unknown
                                >
                            )[key] === value ||
                            (value === null &&
                                !(
                                    next.providerConnection!.defaults as Record<
                                        string,
                                        unknown
                                    >
                                )[key]),
                    ),
                onConfirmed: (next) => {
                    const d = next.providerConnection!.defaults!;
                    const saved = {
                        refresh_interval_minutes:
                            d.refresh_interval_minutes ?? 15,
                        alert_motion_events: d.alert_motion_events ?? false,
                        alert_device_offline: d.alert_device_offline ?? true,
                        quiet_hours_start: d.quiet_hours_start ?? '',
                        quiet_hours_end: d.quiet_hours_end ?? '',
                    };
                    defaultsForm.setDefaults(saved);
                    defaultsForm.setData(saved);
                    setSetupSuccess({
                        title: 'Defaults saved',
                        description:
                            'The saved settings are shown in this record.',
                    });
                },
            });
        }
    };
    const syncMapping = (c: SiteConfig) => {
        if (!c.is_active || !collectionReady) return;
        action.execute({
            method: 'post',
            path: unifiPath + '/sync-devices',
            payload: { site_config_id: c.id },
            label: 'Device sync',
        });
    };
    const saveRoom = () => {
        const d = selectedDevice;
        if (!d || !sites.some((site) => site.id === d.site_id)) return;
        const roomId = roomValue === 'unassigned' ? null : Number(roomValue);
        if (
            roomId !== null &&
            !rooms.some(
                (room) => room.id === roomId && room.site_id === d.site_id,
            )
        )
            return;
        action.execute({
            method: 'put',
            path: unifiPath + `/hardware/${d.id}/room`,
            payload: { room_id: roomId },
            label: 'Room assignment',
            confirmed: (next) =>
                next.syncedDevices?.some(
                    (device) =>
                        device.id === d.id &&
                        device.site_id === d.site_id &&
                        (device.room_id ?? null) === roomId,
                ) === true,
            onConfirmed: () => {
                setRoomDraft(null);
                setRoomDeviceId(null);
            },
        });
    };
    const disableConnection = () => {
        if (!disableReason) return;
        action.execute({
            method: 'post',
            path: unifiPath + '/disable',
            payload: { reason: disableReason },
            label: 'Connection containment',
            confirmed: (next) => next.providerConnection?.status === 'disabled',
            onConfirmed: () => setDisableOpen(false),
        });
    };
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="UniFi Integration" />
            <div className="min-w-0 space-y-5">
                <PageHeader
                    icon={Plug}
                    title="UniFi Integration"
                    subline="Provider connection, approved Site mappings and device records."
                    actions={
                        <>
                            {scope !== 'connection' && scope !== 'defaults' && (
                                <PageHeaderSearch
                                    value={search}
                                    onChange={setSearch}
                                    placeholder="Search this view…"
                                    ariaLabel="Search UniFi records"
                                />
                            )}
                            {scope === 'connection' && (
                                <PageHeaderGlassButton
                                    disabled={!!blocked}
                                    onClick={() =>
                                        openSetup(hasKey ? 'rotate' : 'key')
                                    }
                                >
                                    {connectionDisabled
                                        ? 'Replace Key to Recover'
                                        : hasKey
                                          ? 'Rotate Key'
                                          : 'Set up connection'}
                                </PageHeaderGlassButton>
                            )}
                            {scope === 'defaults' && (
                                <PageHeaderGlassButton
                                    disabled={!!blocked || !hasKey}
                                    onClick={() => openSetup('defaults')}
                                >
                                    Edit Defaults
                                </PageHeaderGlassButton>
                            )}
                            {scope === 'mappings' && (
                                <>
                                    <PageHeaderGlassButton
                                        disabled={!!blocked || !collectionReady}
                                        onClick={() =>
                                            action.execute({
                                                method: 'post',
                                                path: unifiPath + '/sync-sites',
                                                payload: {},
                                                label: 'Provider Site discovery',
                                            })
                                        }
                                    >
                                        Sync UniFi Locations
                                    </PageHeaderGlassButton>
                                    <PageHeaderGlassButton
                                        disabled={
                                            !!blocked ||
                                            !discoveredSites.length ||
                                            !sites.length
                                        }
                                        onClick={() => openSetup('mapping')}
                                    >
                                        Map Location
                                    </PageHeaderGlassButton>
                                </>
                            )}
                            <PageHeaderGlassButton
                                onClick={() => leave.request(refresh)}
                                disabled={action.pending}
                            >
                                <RefreshCw className="size-4" />
                                Refresh
                            </PageHeaderGlassButton>
                        </>
                    }
                    meters={[
                        {
                            label: 'Connection',
                            value: connStatus?.label ?? 'Not configured',
                            caption:
                                'Connection state; device health is separate',
                            view: 'connection',
                        },
                        {
                            label: 'Discovered locations',
                            value: discoveredSites.length,
                            caption: 'Last collected provider catalogue',
                            view: 'mappings',
                        },
                        {
                            label: 'Active mappings',
                            value: siteConfigs.filter((c) => c.is_active)
                                .length,
                            caption: 'Approved application Site bindings',
                            view: 'mappings',
                        },
                        {
                            label: 'Imported devices',
                            value: syncedDevices.length,
                            caption: 'Saved device records',
                            view: 'devices',
                        },
                        {
                            label: 'Recent sync runs',
                            value: syncLogs.length,
                            caption: 'Up to 20 retained runs in this read',
                            view: 'history',
                        },
                        {
                            label: 'Site credentials',
                            value: siteCredentials.length,
                            caption: 'Saved status records',
                            view: 'credentials',
                        },
                    ].map((m) => (
                        <PageHeaderMeterBlock
                            key={m.label}
                            label={m.label}
                            onClick={() => selectView(m.view as UnifiView)}
                            ariaLabel={
                                'Open ' +
                                views.find((v) => v.key === m.view)?.label +
                                ' view'
                            }
                        >
                            <PageHeaderMeterBig>{m.value}</PageHeaderMeterBig>
                            <PageHeaderMeterCaption>
                                {m.caption}
                            </PageHeaderMeterCaption>
                        </PageHeaderMeterBlock>
                    ))}
                    mobileSummary={
                        (connStatus?.label ?? 'Not configured') +
                        ' · ' +
                        syncedDevices.length +
                        ' imported devices'
                    }
                    filters={
                        <>
                            {[
                                'mappings',
                                'devices',
                                'history',
                                'credentials',
                            ].includes(scope) && (
                                <PageHeaderViewToggle
                                    value={layout}
                                    onChange={setLayout}
                                    options={[
                                        {
                                            value: 'cards',
                                            label: 'Cards',
                                            icon: LayoutGrid,
                                        },
                                        {
                                            value: 'table',
                                            label: 'Table',
                                            icon: List,
                                        },
                                    ]}
                                />
                            )}
                            <PageHeaderFilterButton
                                onClick={() => {
                                    setSearch('');
                                    setDeviceSiteFilter('all');
                                }}
                            >
                                Reset filters
                            </PageHeaderFilterButton>
                            {scope === 'devices' && (
                                <PageHeaderFilterSelect
                                    label="Site"
                                    value={deviceSiteFilter}
                                    onChange={setDeviceSiteFilter}
                                    options={[
                                        {
                                            value: 'all',
                                            label: 'All approved Sites',
                                        },
                                        ...sites.map((site) => ({
                                            value: String(site.id),
                                            label: site.name,
                                        })),
                                    ]}
                                />
                            )}
                        </>
                    }
                    rail={
                        <PageHeaderRail
                            items={[...views]}
                            value={scope}
                            onSelect={selectView}
                            ariaLabel="UniFi views"
                        />
                    }
                />
                <p className="text-subtle">
                    Read started {fmt(as_of)} · Latest saved records. Live
                    status has not been checked here.
                </p>
                {(notice || action.notice) && (
                    <p
                        role="status"
                        className="rounded-xl border bg-card p-4 text-sm"
                    >
                        {notice || action.notice}
                    </p>
                )}
                {action.error && !setupKind && !selectedDevice && (
                    <p
                        role="alert"
                        className="rounded-xl border bg-card p-4 text-sm"
                    >
                        {action.error}
                    </p>
                )}
                {scope === 'connection' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Connection</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            {hasKey ? (
                                <>
                                    <ReviewRow
                                        label="Saved status"
                                        value={
                                            connStatus?.label ?? 'Not recorded'
                                        }
                                    />
                                    <ReviewRow
                                        label="Key ending in"
                                        value={
                                            '•••' +
                                            (providerConnection?.secret_last4 ??
                                                'Not recorded')
                                        }
                                    />
                                    <ReviewRow
                                        label="Last tested"
                                        value={fmt(
                                            providerConnection?.last_tested_at,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Last sync"
                                        value={fmt(
                                            providerConnection?.last_synced_at,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Provider Sites last collected"
                                        value={fmt(
                                            providerConnection?.sites_synced_at,
                                        )}
                                    />
                                    <ReviewRow
                                        label="Replacement key saved"
                                        value={fmt(
                                            providerConnection?.recovery_credentials_replaced_at,
                                        )}
                                    />
                                    {!connectionDisabled &&
                                        providerConnection?.recovery_credentials_replaced_at &&
                                        providerConnection.status !==
                                            'connected' && (
                                            <p
                                                role="status"
                                                className="text-subtle"
                                            >
                                                The replacement key is saved.
                                                Test Connection must pass before
                                                collection resumes.
                                            </p>
                                        )}
                                    {connectionDisabled && (
                                        <div
                                            role="status"
                                            className="rounded-xl border border-status-warning/30 bg-status-warning-bg p-4"
                                        >
                                            <p className="font-semibold text-status-warning">
                                                Provider traffic is disabled
                                            </p>
                                            <ReviewRow
                                                label="Disabled at"
                                                value={fmt(
                                                    providerConnection?.disabled_at,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Reason"
                                                value={disableReasonLabel(
                                                    providerConnection?.disabled_reason,
                                                )}
                                            />
                                            <p className="text-subtle">
                                                Replace the key and test the
                                                connection before collection
                                                resumes. Saved device records,
                                                Site mappings and history remain
                                                available.
                                            </p>
                                        </div>
                                    )}
                                    <div className="flex flex-wrap gap-3">
                                        <Button
                                            disabled={
                                                !!blocked || connectionDisabled
                                            }
                                            onClick={() =>
                                                action.execute({
                                                    method: 'post',
                                                    path: unifiPath + '/test',
                                                    payload: {},
                                                    label: 'Connection test',
                                                })
                                            }
                                        >
                                            Test Connection
                                        </Button>
                                        {!connectionDisabled && (
                                            <Button
                                                variant="destructive"
                                                disabled={!!blocked}
                                                onClick={() => {
                                                    setDisableReason(null);
                                                    setDisableOpen(true);
                                                }}
                                            >
                                                Disable connection
                                            </Button>
                                        )}
                                    </div>
                                </>
                            ) : (
                                <p role="status">
                                    No provider key is saved. Set up the
                                    connection, then test it before collecting
                                    records.
                                </p>
                            )}
                        </CardContent>
                    </Card>
                )}
                {scope === 'mappings' && (
                    <>
                        {!collectionReady && (
                            <p
                                role="status"
                                className="rounded-xl border bg-card p-4"
                            >
                                Save and test the provider API key before
                                discovering new locations. Saved locations
                                remain available for mapping.
                            </p>
                        )}
                        <UnifiDiscoveredRecords
                            rows={visibleDiscovered}
                            total={discoveredSites.length}
                            view={layout}
                            disabled={
                                blocked ||
                                (!sites.length
                                    ? 'No approved Site is available.'
                                    : undefined)
                            }
                            onMap={(token) => openSetup('mapping', token)}
                        />
                        <UnifiRecords
                            kind="mapping"
                            rows={visibleMappings.map((record) => ({
                                kind: 'mapping',
                                record,
                            }))}
                            view={layout}
                            total={siteConfigs.length}
                            actionsFor={(row) =>
                                row.kind === 'mapping'
                                    ? [
                                          {
                                              label: 'Sync Devices',
                                              icon: RefreshCw,
                                              disabled: !row.record.is_active
                                                  ? 'This mapping is inactive. Remove it and map the location again before syncing devices.'
                                                  : !collectionReady
                                                    ? 'Connect and test the UniFi key first.'
                                                    : blocked,
                                              onClick: () =>
                                                  syncMapping(row.record),
                                          },
                                          {
                                              label: 'Remove mapping',
                                              icon: XCircle,
                                              danger: true,
                                              disabled: blocked,
                                              onClick: () => {
                                                  action.clearMessage();
                                                  setRemoveMappingId(
                                                      row.record.id,
                                                  );
                                              },
                                          },
                                      ]
                                    : []
                            }
                        />
                    </>
                )}
                {scope === 'devices' && (
                    <UnifiRecords
                        kind="device"
                        rows={filteredDevices.map((record) => ({
                            kind: 'device',
                            record,
                        }))}
                        view={layout}
                        total={syncedDevices.length}
                        canViewDevices={canViewDevices}
                        actionsFor={(row) =>
                            row.kind === 'device'
                                ? [
                                      {
                                          label: 'Assign room',
                                          icon: Building2,
                                          disabled:
                                              blocked ||
                                              (!sites.some(
                                                  (site) =>
                                                      site.id ===
                                                      row.record.site_id,
                                              )
                                                  ? 'Assign this device to an approved Site before choosing a room.'
                                                  : undefined),
                                          onClick: () =>
                                              leave.request(() => {
                                                  action.clearMessage();
                                                  setRoomDraft(null);
                                                  setRoomDeviceId(
                                                      row.record.id,
                                                  );
                                              }),
                                      },
                                  ]
                                : []
                        }
                    />
                )}
                {scope === 'history' && (
                    <UnifiRecords
                        kind="history"
                        rows={visibleLogs.map((record) => ({
                            kind: 'history',
                            record,
                        }))}
                        view={layout}
                        total={syncLogs.length}
                        actionsFor={() => []}
                    />
                )}
                {scope === 'credentials' && (
                    <UnifiRecords
                        kind="credential"
                        rows={visibleCredentials.map((record) => ({
                            kind: 'credential',
                            record,
                        }))}
                        view={layout}
                        total={siteCredentials.length}
                        actionsFor={() => []}
                    />
                )}
                {scope === 'defaults' && (
                    <Card>
                        <CardHeader>
                            <CardTitle>Collection and alert defaults</CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-5">
                            <ReviewRow
                                label="Refresh interval"
                                value={
                                    providerConnection?.defaults
                                        ?.refresh_interval_minutes !== undefined
                                        ? providerConnection.defaults
                                              .refresh_interval_minutes +
                                          ' minutes'
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Motion alerts"
                                value={
                                    typeof providerConnection?.defaults
                                        ?.alert_motion_events === 'boolean'
                                        ? providerConnection.defaults
                                              .alert_motion_events
                                            ? 'Enabled'
                                            : 'Disabled'
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Offline alerts"
                                value={
                                    typeof providerConnection?.defaults
                                        ?.alert_device_offline === 'boolean'
                                        ? providerConnection.defaults
                                              .alert_device_offline
                                            ? 'Enabled'
                                            : 'Disabled'
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Quiet hours start"
                                value={
                                    providerConnection?.defaults
                                        ? providerConnection.defaults
                                              .quiet_hours_start ||
                                          'Not configured'
                                        : 'Not recorded'
                                }
                            />
                            <ReviewRow
                                label="Quiet hours end"
                                value={
                                    providerConnection?.defaults
                                        ? providerConnection.defaults
                                              .quiet_hours_end ||
                                          'Not configured'
                                        : 'Not recorded'
                                }
                            />
                            <p className="text-subtle">
                                Quiet-hour clock values have no time zone
                                recorded in this response.
                            </p>
                        </CardContent>
                    </Card>
                )}
                {setupKind && (
                    <UnifiSetupWizard
                        key={setupKind}
                        kind={setupKind}
                        values={setupValues}
                        onChange={changeSetup}
                        providerConnection={providerConnection}
                        sites={sites}
                        discoveredSites={discoveredSites}
                        pending={action.pending}
                        uncertain={action.uncertain}
                        error={action.error}
                        success={setupSuccess}
                        onSubmit={submitSetup}
                        onClose={() => {
                            if (!action.pending)
                                leave.request(() => {
                                    setSetupKind(null);
                                    setSetupSuccess(null);
                                });
                        }}
                    />
                )}
                {selectedDevice && (
                    <UnifiRoomDialog
                        key={JSON.stringify([
                            selectedDevice.id,
                            selectedDevice.site_id,
                            selectedDevice.room_id,
                        ])}
                        device={selectedDevice}
                        rooms={rooms}
                        value={roomValue}
                        onValue={(value) =>
                            setRoomDraft({
                                id: selectedDevice.id,
                                site_id: selectedDevice.site_id,
                                room_id: selectedDevice.room_id ?? null,
                                value,
                            })
                        }
                        onSave={saveRoom}
                        onClose={() => {
                            if (!action.pending)
                                leave.request(() => setRoomDeviceId(null));
                        }}
                        pending={action.pending}
                        error={action.error}
                        uncertain={action.uncertain}
                    />
                )}
                <ConfirmDialog
                    open={!!selectedMapping}
                    title="Remove UniFi mapping?"
                    description={
                        selectedMapping
                            ? 'Remove the saved provider mapping for ' +
                              selectedMapping.site_name +
                              '. Device records and history remain available.'
                            : ''
                    }
                    confirmText="Remove mapping"
                    variant="destructive"
                    processing={action.pending}
                    onClose={() => {
                        if (!action.pending) setRemoveMappingId(null);
                    }}
                    onConfirm={() => {
                        if (!selectedMapping || action.uncertain) return;
                        const id = selectedMapping.id;
                        action.execute({
                            method: 'delete',
                            path: unifiPath + `/map-site/${id}`,
                            label: 'Mapping removal',
                            onUnconfirmed: () => setRemoveMappingId(null),
                            confirmed: (next) =>
                                !next.siteConfigs?.some((c) => c.id === id),
                            onConfirmed: () => setRemoveMappingId(null),
                        });
                    }}
                />
                <AlertDialog
                    open={disableOpen}
                    onOpenChange={(open) => {
                        if (!action.pending) setDisableOpen(open);
                    }}
                >
                    <AlertDialogContent>
                        <AlertDialogHeader>
                            <AlertDialogTitle>
                                Disable UniFi connection?
                            </AlertDialogTitle>
                            <AlertDialogDescription>
                                Stop provider collection and new intake. Saved
                                records and mappings remain available. Recovery
                                needs a replacement key and a successful test.
                            </AlertDialogDescription>
                        </AlertDialogHeader>
                        <Label id="unifi-disable-reason-label">Reason</Label>
                        <RadioGroup
                            value={disableReason ?? ''}
                            onValueChange={(value) =>
                                setDisableReason(
                                    disableReasons.find(
                                        (reason) => reason.value === value,
                                    )?.value ?? null,
                                )
                            }
                            disabled={action.pending}
                            aria-labelledby="unifi-disable-reason-label"
                        >
                            {disableReasons.map((reason) => (
                                <div
                                    key={reason.value}
                                    className="flex items-center gap-3 rounded-xl border p-3"
                                >
                                    <RadioGroupItem
                                        id={'unifi-reason-' + reason.value}
                                        value={reason.value}
                                    />
                                    <Label
                                        htmlFor={'unifi-reason-' + reason.value}
                                    >
                                        {reason.label}
                                    </Label>
                                </div>
                            ))}
                        </RadioGroup>
                        {action.error && (
                            <p role="alert" className="text-status-critical">
                                {action.error}
                            </p>
                        )}
                        <AlertDialogFooter>
                            <AlertDialogCancel disabled={action.pending}>
                                Cancel
                            </AlertDialogCancel>
                            <Button
                                variant="destructive"
                                disabled={
                                    !disableReason ||
                                    action.pending ||
                                    action.uncertain
                                }
                                onClick={disableConnection}
                            >
                                Confirm containment
                            </Button>
                        </AlertDialogFooter>
                    </AlertDialogContent>
                </AlertDialog>
                {leave.confirmation}
            </div>
        </AppLayout>
    );
}
