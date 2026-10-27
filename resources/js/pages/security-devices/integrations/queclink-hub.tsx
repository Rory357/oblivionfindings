import { ConfirmDialog } from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import {
    EntityCard,
    EntityCardGrid,
    EntityChip,
    EntityContextMenu,
    EntityTable,
    ListCaption,
    useEntityContextMenu,
    type MenuItem,
} from '@/components/lists';
import {
    PageHeader,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderMeterDonut,
    PageHeaderRail,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { TabsRoot as Tabs, TabsContent } from '@/components/ui/tabs';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
} from '@/components/wizard/shell';
import AppLayout from '@/layouts/app-layout';
import { formatDateTimeLong, formatRelative, formatTime } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowDownLeft,
    ArrowUpRight,
    CheckCircle,
    Clock,
    Database,
    Eye,
    Inbox,
    LayoutDashboard,
    LayoutGrid,
    List,
    Loader2,
    Play,
    RefreshCw,
    Satellite,
    Send,
    Server,
    Settings2,
    ShieldCheck,
    Smartphone,
    Trash2,
    Unlink,
    XCircle,
} from 'lucide-react';
import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useId,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    assignmentTypeLabel,
    CommandStatusBadge,
    connectionStateLabel,
    pageScopeHint,
    pairingStateLabel,
    plainLabel,
    QueclinkRequestBoundary,
    QueclinkSettingsWorkspace,
    REQUEST_FAILURE_MESSAGES,
    requestFailure,
    sectionReadLabels,
    useQueclinkRequestLedger,
} from './_queclink-settings';
import {
    SiteCredentialsCard,
    type SiteCredentialRow,
} from './site-credentials';

// ── Types ──────────────────────────────────────────────────────────

type DeviceStatus = 'pending' | 'paired' | 'rejected';
type PairingType = 'vehicle' | 'staff' | 'client';
/** DeviceAssignment::TARGET_* — a claim pairs vehicle/staff/client; Device Management can also assign a Site or room. */
type AssignmentType = PairingType | 'site' | 'room';
/** SafeOperationalData::serviceState() — the only listener states the server sends. */
type ListenerServiceState = 'active' | 'inactive' | 'unavailable';
type Direction = 'inbound' | 'outbound';
type FrameType = 'RESP' | 'ACK' | 'SACK' | 'BUFF' | 'AT' | 'unknown';

export type Device = {
    id: number;
    canonical_device_id?: number | null;
    reference: string;
    status: DeviceStatus;
    pending_pairing_type?: PairingType | null;
    model_hint: string | null;
    protocol_version: string | null;
    firmware_version: string | null;
    connection_state: 'connected' | 'disconnected';
    first_seen_at: string | null;
    last_seen_at: string | null;
    last_frame_at: string | null;
    assignment: {
        type: AssignmentType;
        assigned_at: string | null;
        label: string;
    } | null;
    configuration?: DeviceConfiguration | null;
    recent_commands?: RecentCommand[];
};

type Target = { id: number; label: string };

type Frame = {
    id: number;
    direction: Direction;
    frame_type: FrameType;
    command_word: string | null;
    parse_ok: boolean;
    failure_category: string | null;
    created_at: string | null;
};

type DeviceConfigurationSummary =
    | Record<string, string>
    | Record<string, string>[]
    | null;

type DeviceConfiguration = {
    state: 'observed' | 'not_observed';
    observed_at: string | null;
    sections: string[];
};

export type RecentCommand = {
    id: number;
    command_word: string;
    status: 'queued' | 'sent' | 'acked' | 'failed' | 'expired' | 'cancelled';
    created_at: string | null;
    sent_at: string | null;
    acked_at: string | null;
    fulfilled_at: string | null;
    cancelled_at?: string | null;
    expires_at: string | null;
    governed: boolean;
    failure_category: string | null;
};

export type Preset = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    target_category: string;
    is_system: boolean;
    sections: string[];
    profile_uuid?: string | null;
    profile_version?: number | null;
    payload_hash?: string | null;
    created_at: string | null;
};

export type RetiredPreset = Preset & {
    retired_at: string | null;
    retired_by: string | null;
    retirement_reason: string | null;
};

type DevicePagination = {
    current_page: number;
    last_page: number;
    per_page: number;
    total: number;
    prev_page_url: string | null;
    next_page_url: string | null;
};

export type Props = {
    actor_id: number;
    as_of: string;
    listener: {
        port: number;
        endpoint_configured: boolean;
        service_state: ListenerServiceState;
        connected_count: number;
    };
    devices: {
        paired: Device[];
        pending: Device[];
        rejected: Device[];
        total: number;
        counts?: Record<DeviceStatus, number>;
        search?: string;
        pagination?: Record<DeviceStatus, DevicePagination>;
    };
    statistics: {
        frames_last_hour: number;
        last_frame_at: string | null;
    };
    cloudIntegration: {
        status: 'unavailable';
        legacy_credential_stored: boolean;
        legacy_credential_last4: string | null;
    };
    siteCredentials: SiteCredentialRow[];
    targets: {
        vehicles: Target[];
        staff: Target[];
        clients: Target[];
    };
    presets: Preset[];
    retiredPresets?: RetiredPreset[];
    can: { manage: boolean };
};

// ── Helpers ────────────────────────────────────────────────────────

function mergeFrames(existing: Frame[], incoming: Frame[]): Frame[] {
    const byId = new Map<number, Frame>();
    for (const frame of existing) {
        byId.set(frame.id, frame);
    }
    for (const frame of incoming) {
        byId.set(frame.id, frame);
    }

    return [...byId.values()].sort((a, b) => a.id - b.id).slice(-500);
}

type FrameFilters = {
    direction: 'all' | Direction;
    commandWord: string;
    parseStatus: 'all' | 'ok' | 'error';
};

type FrameStreamState =
    | 'connecting'
    | 'live'
    | 'reconnecting'
    | 'error'
    | 'paused';

function framesUrl(filters: FrameFilters): string {
    const params = new URLSearchParams();
    if (filters.direction !== 'all') params.set('direction', filters.direction);
    if (filters.commandWord.trim()) {
        params.set('command_word', filters.commandWord.trim().toUpperCase());
    }
    if (filters.parseStatus !== 'all') {
        params.set('parse_status', filters.parseStatus);
    }

    return `/security-devices/integrations/queclink/frames${params.toString() ? '?' + params.toString() : ''}`;
}

function frameStreamUrl(filters: FrameFilters): string {
    const params = new URLSearchParams();
    if (filters.direction !== 'all') params.set('direction', filters.direction);
    if (filters.commandWord.trim()) {
        params.set('command_word', filters.commandWord.trim().toUpperCase());
    }
    if (filters.parseStatus !== 'all') {
        params.set('parse_status', filters.parseStatus);
    }

    return `/security-devices/integrations/queclink/stream${params.toString() ? '?' + params.toString() : ''}`;
}

function fmt(iso: string | null): string {
    return formatDateTimeLong(iso);
}

function fmtRel(iso: string | null): string {
    return formatRelative(iso, Date.now(), 'Not observed');
}

function trackerContextKey(device: Device): string {
    return JSON.stringify([
        device.id,
        device.canonical_device_id ?? null,
        device.status,
        device.assignment?.type ?? null,
        device.assignment?.assigned_at ?? null,
    ]);
}
function presetContextKey(preset: Preset): string {
    return JSON.stringify([
        preset.id,
        preset.profile_uuid ?? null,
        preset.profile_version ?? null,
        preset.payload_hash ?? null,
        preset.target_category,
        preset.is_system,
        [...preset.sections].sort(),
    ]);
}

function deviceCount(devices: Props['devices'], status: DeviceStatus): number {
    return devices.counts?.[status] ?? devices[status].length;
}

function listenerLabel(state: ListenerServiceState): string {
    return state === 'active'
        ? 'Listener active'
        : state === 'inactive'
          ? 'Listener stopped'
          : 'Listener status unavailable';
}

/** Server refusals and failed reads for hub actions, shown above the views. */
const HubNoticeContext = createContext<(message: string) => void>(() => {});
const useHubNotice = () => useContext(HubNoticeContext);

/** The first server validation message for a refused hub action. */
function firstError(errors: Record<string, string | undefined>): string {
    const message = Object.values(errors).find(
        (value): value is string =>
            typeof value === 'string' &&
            value.trim() !== '' &&
            value.length <= 300,
    );
    return message ?? REQUEST_FAILURE_MESSAGES.refused;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Home', href: '/dashboard' },
    { title: 'Security & Devices', href: '/security-devices' },
    { title: 'Integrations', href: '/security-devices/integrations' },
    { title: 'Queclink', href: '/security-devices/integrations/queclink' },
];

type HubView =
    | 'overview'
    | 'pending'
    | 'devices'
    | 'rejected'
    | 'settings'
    | 'console'
    | 'ims';
type RecordLayout = 'cards' | 'table';
const hubPath = '/security-devices/integrations/queclink';
// Every partial read returns current authority and the header summary with it:
// "Read started" (as_of) must never stamp header numbers from an older read.
const authorityProps = [
    'actor_id',
    'as_of',
    'auth',
    'can',
    'listener',
    'statistics',
];
// Writes the hub's own views send with plain router/useForm calls. Settings
// requests own their replies (see useQueclinkRequest) and are not listed.
const hubWritePath =
    /^\/security-devices\/integrations\/queclink\/(?:devices\/\d+\/(?:claim|reject|restore|release|command)|bulk|settings|key)$/;

// The payload belongs to the returned actor. Current shared permissions must
// independently allow this manager-only hub, including after partial visits.
export default function QueclinkHub(data: Props) {
    const { auth } = usePage<{
        auth?: {
            user?: { id?: number } | null;
            can?: {
                securityDevices?: {
                    viewAny?: boolean;
                    integrationsManage?: boolean;
                    devicesView?: boolean;
                };
            };
        };
    }>().props;
    const requestLedger = useQueclinkRequestLedger();
    const [denied, setDenied] = useState(false);
    const [notice, setNotice] = useState('');
    const [sourceActor] = useState(data.actor_id);
    const denyAccess = useCallback(() => {
        setNotice('');
        setDenied(true);
    }, []);
    const [activeTab, setActiveTab] = useState<HubView>(
        deviceCount(data.devices, 'pending') > 0 ? 'pending' : 'overview',
    );
    const [layout, setLayout] = useState<RecordLayout>('table');
    const visible =
        !denied &&
        Boolean(
            data.actor_id > 0 &&
            sourceActor === data.actor_id &&
            auth?.user?.id === data.actor_id &&
            data.can.manage &&
            auth.can?.securityDevices?.viewAny === true &&
            auth.can.securityDevices.integrationsManage === true,
        );
    useEffect(
        () =>
            router.on('invalid', (event) => {
                const response = event.detail.response;
                if (typeof response.config.url !== 'string') return;
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
                    (target.pathname !== hubPath &&
                        !target.pathname.startsWith(hubPath + '/'))
                )
                    return;
                const failure = requestFailure(response);
                if (failure === 'denied') {
                    // Lost access, or the hub page itself was refused: clear
                    // every private record and draft.
                    event.preventDefault();
                    denyAccess();
                    return;
                }
                const method = (response.config.method ?? 'get').toLowerCase();
                if (method === 'get' && target.pathname === hubPath) {
                    // A search, page or refresh read failed; nothing changed.
                    event.preventDefault();
                    setNotice(
                        'Current records could not be loaded. The details shown are from the previous read. Try again in a moment.',
                    );
                } else if (hubWritePath.test(target.pathname)) {
                    // Hub actions without their own reply handling. Settings
                    // requests explain their own replies beside the form.
                    event.preventDefault();
                    setNotice(REQUEST_FAILURE_MESSAGES[failure]);
                }
            }),
        [denyAccess],
    );
    if (!visible)
        return (
            <AppLayout breadcrumbs={breadcrumbs}>
                <Head title="Queclink integration" />
                <PageLayout
                    hero={
                        <PageHeader
                            icon={Satellite}
                            title="Queclink integration"
                            subline="Approved tracker intake and assignment"
                            titleChip={
                                <PageHeaderStatusChip variant="neutral">
                                    Access unavailable
                                </PageHeaderStatusChip>
                            }
                        />
                    }
                >
                    <EmptyState
                        icon={ShieldCheck}
                        title="Queclink access is unavailable"
                        description="Your current account needs Security & Devices access and permission to manage integrations. Refresh to check access again."
                        action={
                            <Button
                                variant="outline"
                                onClick={() =>
                                    router.get(
                                        hubPath,
                                        {},
                                        { preserveState: false },
                                    )
                                }
                            >
                                Refresh access
                            </Button>
                        }
                    />
                </PageLayout>
            </AppLayout>
        );
    // Ordinary response DTOs retain active forms until Inertia delivers their
    // callbacks. Current role and resource withdrawal boundaries remain explicit.
    return (
        <QueclinkRequestBoundary ledger={requestLedger}>
            <HubNoticeContext.Provider value={setNotice}>
                <QueclinkWorkspace
                    key={JSON.stringify([
                        data.actor_id,
                        data.can.manage,
                        auth?.can?.securityDevices,
                    ])}
                    data={data}
                    canViewDevices={
                        auth?.can?.securityDevices?.devicesView === true
                    }
                    activeTab={activeTab}
                    setActiveTab={setActiveTab}
                    layout={layout}
                    setLayout={setLayout}
                    onAccessDenied={denyAccess}
                    notice={notice}
                    onDismissNotice={() => setNotice('')}
                />
            </HubNoticeContext.Provider>
        </QueclinkRequestBoundary>
    );
}

function QueclinkWorkspace({
    data,
    canViewDevices,
    activeTab,
    setActiveTab,
    layout,
    setLayout,
    onAccessDenied,
    notice = '',
    onDismissNotice,
}: {
    data: Props;
    canViewDevices: boolean;
    activeTab: HubView;
    setActiveTab: (view: HubView) => void;
    layout: RecordLayout;
    setLayout: (layout: RecordLayout) => void;
    onAccessDenied: () => void;
    notice?: string;
    onDismissNotice?: () => void;
}) {
    const {
        listener,
        devices,
        statistics,
        cloudIntegration,
        siteCredentials,
        targets,
        presets,
        retiredPresets = [],
        can,
        as_of,
    } = data;
    const [deviceSearch, setDeviceSearch] = useState(devices.search ?? '');
    const pendingCount = deviceCount(devices, 'pending');
    const pairedCount = deviceCount(devices, 'paired');
    const rejectedCount = deviceCount(devices, 'rejected');
    const searchDevices = () =>
        router.get(
            hubPath,
            { device_search: deviceSearch },
            {
                only: ['devices', ...authorityProps],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    const listView = ['pending', 'devices', 'rejected'].includes(activeTab);
    // Device settings mounts on first visit and then stays mounted (hidden)
    // for this authority, so its drafts are not lost on a view switch.
    const [settingsOpened, setSettingsOpened] = useState(
        activeTab === 'settings',
    );
    if (activeTab === 'settings' && !settingsOpened) setSettingsOpened(true);
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Queclink integration" />
            <Tabs
                value={activeTab}
                onValueChange={(v) => setActiveTab(v as HubView)}
            >
                <PageLayout
                    hero={
                        <PageHeader
                            icon={Satellite}
                            title="Queclink integration"
                            wrapTitle
                            subline="Tracker intake · vehicle, lone-worker and client assignments"
                            titleChip={
                                <PageHeaderStatusChip
                                    variant={
                                        listener.service_state === 'active'
                                            ? 'success'
                                            : listener.service_state ===
                                                'inactive'
                                              ? 'critical'
                                              : 'neutral'
                                    }
                                >
                                    {listenerLabel(listener.service_state)}
                                </PageHeaderStatusChip>
                            }
                            actions={
                                <>
                                    <PageHeaderSearch
                                        value={deviceSearch}
                                        onChange={setDeviceSearch}
                                        placeholder="Search devices…"
                                        ariaLabel="Search devices"
                                        onKeyDown={(event) => {
                                            if (event.key === 'Enter')
                                                searchDevices();
                                        }}
                                    />
                                    <PageHeaderGlassButton
                                        onClick={searchDevices}
                                    >
                                        Search devices
                                    </PageHeaderGlassButton>
                                    <PageHeaderGlassButton
                                        icon={RefreshCw}
                                        onClick={() =>
                                            router.get(
                                                hubPath,
                                                {},
                                                {
                                                    preserveState: false,
                                                    preserveScroll: true,
                                                },
                                            )
                                        }
                                    >
                                        Refresh
                                    </PageHeaderGlassButton>
                                </>
                            }
                            meters={
                                <>
                                    <PageHeaderMeterBlock
                                        label="Paired devices"
                                        value={pairedCount}
                                        onClick={() => setActiveTab('devices')}
                                    >
                                        <PageHeaderMeterDonut
                                            percent={
                                                devices.total
                                                    ? (pairedCount /
                                                          devices.total) *
                                                      100
                                                    : 0
                                            }
                                        />
                                        <PageHeaderMeterCaption>
                                            {pairedCount} of {devices.total}{' '}
                                            matching trackers paired
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Pending"
                                        tone={
                                            pendingCount ? 'warning' : 'brand'
                                        }
                                        onClick={() => setActiveTab('pending')}
                                    >
                                        <PageHeaderMeterBig>
                                            {pendingCount}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Waiting for an authorised claim
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Rejected"
                                        onClick={() => setActiveTab('rejected')}
                                    >
                                        <PageHeaderMeterBig>
                                            {rejectedCount}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Review or restore a recognised
                                            tracker
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Connected now"
                                        onClick={() => setActiveTab('overview')}
                                    >
                                        <PageHeaderMeterBig>
                                            {listener.connected_count}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Listener scope · returned connection
                                            state
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                    <PageHeaderMeterBlock
                                        label="Frames last hour"
                                        onClick={() => setActiveTab('console')}
                                    >
                                        <PageHeaderMeterBig>
                                            {statistics.frames_last_hour}
                                        </PageHeaderMeterBig>
                                        <PageHeaderMeterCaption>
                                            Last frame:{' '}
                                            {fmtRel(statistics.last_frame_at)}
                                        </PageHeaderMeterCaption>
                                    </PageHeaderMeterBlock>
                                </>
                            }
                            filters={
                                <>
                                    {(listView || activeTab === 'settings') && (
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
                                            ariaLabel="Tracker layout"
                                        />
                                    )}
                                    <span className="text-caption text-band-foreground/80">
                                        Read started {fmt(as_of)} · NZ time
                                        {devices.search
                                            ? ' · device counts match the search'
                                            : ''}
                                    </span>
                                </>
                            }
                            rail={
                                <PageHeaderRail
                                    value={activeTab}
                                    onSelect={setActiveTab}
                                    ariaLabel="Queclink views"
                                    items={[
                                        {
                                            key: 'overview',
                                            label: 'Overview',
                                            icon: LayoutDashboard,
                                        },
                                        {
                                            key: 'pending',
                                            label: 'Pending',
                                            icon: Inbox,
                                            count: pendingCount,
                                        },
                                        {
                                            key: 'devices',
                                            label: 'Devices',
                                            icon: Smartphone,
                                            count: pairedCount,
                                        },
                                        {
                                            key: 'rejected',
                                            label: 'Rejected',
                                            icon: XCircle,
                                            count: rejectedCount,
                                        },
                                        {
                                            key: 'settings',
                                            label: 'Device settings',
                                            icon: Settings2,
                                        },
                                        {
                                            key: 'console',
                                            label: 'Debug console',
                                            icon: Activity,
                                        },
                                        {
                                            key: 'ims',
                                            label: 'Cloud API',
                                            icon: Database,
                                        },
                                    ]}
                                />
                            }
                        />
                    }
                >
                    <div
                        data-testid="queclink-page-shell"
                        className="w-full space-y-5"
                    >
                        {notice && (
                            <Alert variant="warning">
                                <AlertTriangle aria-hidden="true" />
                                <AlertTitle>Request not completed</AlertTitle>
                                <AlertDescription>
                                    <p>{notice}</p>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        onClick={onDismissNotice}
                                    >
                                        Dismiss
                                    </Button>
                                </AlertDescription>
                            </Alert>
                        )}
                        <TabsContent value="overview" className="space-y-5">
                            <SiteCredentialsCard rows={siteCredentials} />
                            <OverviewTab
                                listener={listener}
                                devices={devices}
                                statistics={statistics}
                                can={can}
                                onAccessDenied={onAccessDenied}
                            />
                        </TabsContent>
                        <TabsContent value="pending" className="space-y-5">
                            <PendingTab
                                pending={devices.pending}
                                total={pendingCount}
                                pagination={devices.pagination?.pending}
                                targets={targets}
                                can={can}
                                layout={layout}
                                canViewDevices={canViewDevices}
                            />
                        </TabsContent>
                        <TabsContent value="devices" className="space-y-5">
                            <DevicesTab
                                paired={devices.paired}
                                total={pairedCount}
                                pagination={devices.pagination?.paired}
                                presets={presets}
                                can={can}
                                layout={layout}
                                canViewDevices={canViewDevices}
                            />
                        </TabsContent>
                        <TabsContent value="rejected" className="space-y-5">
                            <RejectedTab
                                rejected={devices.rejected}
                                total={rejectedCount}
                                pagination={devices.pagination?.rejected}
                                can={can}
                                layout={layout}
                                canViewDevices={canViewDevices}
                            />
                        </TabsContent>
                        {/* Once opened, kept mounted while hidden: "Keep
                            draft" values and the chosen tracker survive a
                            switch to another view and back. */}
                        <TabsContent
                            value="settings"
                            forceMount
                            hidden={activeTab !== 'settings'}
                            className="space-y-5"
                        >
                            {settingsOpened && (
                                <DeviceSettingsTab
                                    devices={devices.paired}
                                    total={pairedCount}
                                    listener={listener}
                                    presets={presets}
                                    retiredPresets={retiredPresets}
                                    can={can}
                                    layout={layout}
                                    onAccessDenied={onAccessDenied}
                                />
                            )}
                        </TabsContent>
                        <TabsContent value="console" className="space-y-5">
                            <DebugConsoleTab
                                key={devices.paired
                                    .map(trackerContextKey)
                                    .sort()
                                    .join('|')}
                                devices={devices.paired}
                                total={pairedCount}
                                can={can}
                                onAccessDenied={onAccessDenied}
                            />
                        </TabsContent>
                        <TabsContent value="ims" className="space-y-5">
                            <CloudIntegrationTab
                                cloudIntegration={cloudIntegration}
                                can={can}
                            />
                        </TabsContent>
                    </div>
                </PageLayout>
            </Tabs>
        </AppLayout>
    );
}

// ── Listener status pill ──────────────────────────────────────────

function ListenerStatusBadge({ listener }: { listener: Props['listener'] }) {
    const state = listener.service_state;
    const variant =
        state === 'active'
            ? 'success'
            : state === 'inactive'
              ? 'critical'
              : 'neutral';
    return (
        <StatusBadge variant={variant}>
            <Server aria-hidden="true" className="size-3" />
            {listenerLabel(state)} · {listener.connected_count} connected
        </StatusBadge>
    );
}

// ── Overview tab ──────────────────────────────────────────────────

export function OverviewTab({
    listener,
    devices,
    statistics,
    can,
    onAccessDenied,
}: {
    listener: Props['listener'];
    devices: Props['devices'];
    statistics: Props['statistics'];
    can: Props['can'];
    onAccessDenied?: () => void;
}) {
    const settings = useForm<{ port: number; public_hostname: string }>({
        port: listener.port,
        public_hostname: '',
    });
    const [provisioning, setProvisioning] = useState<{
        state: string;
        instructions: string[];
    } | null>(null);
    const [provisioningError, setProvisioningError] = useState<string | null>(
        null,
    );
    const [family, setFamily] = useState<'gv500cg' | 'gl30m'>('gv500cg');
    const [provisioningLoading, setProvisioningLoading] = useState(false);
    const provisioningRequest = useRef(0);

    useEffect(
        () => () => {
            provisioningRequest.current += 1;
        },
        [],
    );

    const portChanged = settings.data.port !== listener.port;

    async function generateProvisioning() {
        const requestId = ++provisioningRequest.current;
        setProvisioningLoading(true);
        setProvisioning(null);
        setProvisioningError(null);

        try {
            const response = await fetch(
                `/security-devices/integrations/queclink/provisioning?family=${family}`,
                { headers: { Accept: 'application/json' } },
            );
            if ([401, 403, 404].includes(response.status)) {
                onAccessDenied?.();
                return;
            }
            const payload: unknown = await response.json().catch(() => null);

            if (requestId !== provisioningRequest.current) return;

            const body =
                payload && typeof payload === 'object'
                    ? (payload as Record<string, unknown>)
                    : {};
            const safeError =
                typeof body.error === 'string' && body.error.length <= 200
                    ? body.error.trim()
                    : '';

            if (!response.ok || safeError !== '') {
                setProvisioningError(
                    safeError ||
                        'Readiness could not be checked. Review the listener settings and try again.',
                );
                return;
            }

            if (
                body.state !== 'ready_for_secure_provisioning' ||
                !Array.isArray(body.instructions) ||
                !body.instructions.every(
                    (instruction) =>
                        typeof instruction === 'string' &&
                        instruction.length > 0 &&
                        instruction.length <= 300,
                )
            ) {
                setProvisioningError(
                    'Readiness returned an unexpected response. Try again or review runtime health.',
                );
                return;
            }

            setProvisioning({
                state: body.state,
                instructions: body.instructions as string[],
            });
        } catch {
            if (requestId === provisioningRequest.current) {
                setProvisioningError(
                    'Readiness could not be checked. Confirm the application is online and try again.',
                );
            }
        } finally {
            if (requestId === provisioningRequest.current) {
                setProvisioningLoading(false);
            }
        }
    }

    return (
        <>
            <Alert role="region" aria-label="Listener observation">
                <Server aria-hidden="true" />
                <AlertTitle>Listener observation</AlertTitle>
                <AlertDescription>
                    <ListenerStatusBadge listener={listener} />
                    <p className="mt-2">
                        Port {listener.port} · protected endpoint{' '}
                        {listener.endpoint_configured
                            ? 'configured'
                            : 'not configured'}{' '}
                        · last frame {fmt(statistics.last_frame_at)} (NZ time).
                    </p>
                    <p className="mt-1">
                        A connected listener does not confirm a tracker location
                        or a completed device action.
                    </p>
                </AlertDescription>
            </Alert>
            <Card>
                <CardHeader>
                    <CardTitle>Listener settings</CardTitle>
                    <CardDescription>
                        These are the details trackers connect to. Changing the
                        port restarts the listener, and every paired tracker
                        must then be set to the new port.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            settings.post(
                                '/security-devices/integrations/queclink/settings',
                                { preserveScroll: true },
                            );
                        }}
                        className="grid gap-4 md:grid-cols-2"
                    >
                        <div className="space-y-2">
                            <Label htmlFor="port">TCP port</Label>
                            <Input
                                id="port"
                                type="number"
                                min={1024}
                                max={65535}
                                value={settings.data.port}
                                onChange={(e) =>
                                    settings.setData(
                                        'port',
                                        parseInt(e.target.value || '0', 10),
                                    )
                                }
                                disabled={!can.manage}
                            />
                            <p className="text-xs text-muted-foreground">
                                Default: 8090. Apply this port through the
                                approved secure device-management process.
                            </p>
                            <InputError message={settings.errors.port} />
                            {portChanged &&
                                deviceCount(devices, 'paired') > 0 && (
                                    <Alert variant="warning" role="status">
                                        <AlertTriangle aria-hidden="true" />
                                        <AlertDescription>
                                            {deviceCount(devices, 'paired')}{' '}
                                            paired{' '}
                                            {deviceCount(devices, 'paired') ===
                                            1
                                                ? 'device'
                                                : 'devices'}{' '}
                                            will need to be reconfigured to dial
                                            port {settings.data.port} before
                                            they reconnect.
                                        </AlertDescription>
                                    </Alert>
                                )}
                        </div>
                        <div className="space-y-2">
                            <Label htmlFor="hostname">Public hostname</Label>
                            <Input
                                id="hostname"
                                type="text"
                                placeholder="oblivion.example.co.nz"
                                value={settings.data.public_hostname}
                                onChange={(e) =>
                                    settings.setData(
                                        'public_hostname',
                                        e.target.value,
                                    )
                                }
                                disabled={!can.manage}
                            />
                            <p className="text-xs text-muted-foreground">
                                The address trackers connect to. An existing
                                hostname remains protected; enter a value only
                                to set or replace it.
                            </p>
                            <InputError
                                message={settings.errors.public_hostname}
                            />
                        </div>
                        <div className="md:col-span-2">
                            <Button
                                type="submit"
                                disabled={!can.manage || settings.processing}
                            >
                                {settings.processing
                                    ? 'Saving…'
                                    : 'Save listener settings'}
                            </Button>
                        </div>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle role="heading" aria-level={2}>
                        Provisioning readiness
                    </CardTitle>
                    <CardDescription>
                        Confirm that the protected listener endpoint is ready
                        before applying the server configuration through the
                        approved secure device-management process. No hostname,
                        credential, or raw command is displayed here.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="flex items-end gap-3">
                        <div className="space-y-2">
                            <Label>Device family</Label>
                            <Select
                                value={family}
                                onValueChange={(v) => {
                                    provisioningRequest.current += 1;
                                    setFamily(v as 'gv500cg' | 'gl30m');
                                    setProvisioning(null);
                                    setProvisioningError(null);
                                    setProvisioningLoading(false);
                                }}
                            >
                                <SelectTrigger
                                    aria-label="Device family"
                                    className="w-48"
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="gv500cg">
                                        GV500CG (vehicle)
                                    </SelectItem>
                                    <SelectItem value="gl30m">
                                        GL30M (personal)
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={generateProvisioning}
                            disabled={!can.manage || provisioningLoading}
                            className="frontline-focus min-h-11"
                        >
                            {provisioningLoading ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Play className="mr-2 h-4 w-4" />
                            )}
                            Check readiness
                        </Button>
                    </div>
                    {provisioningError ? (
                        <Alert variant="destructive">
                            <XCircle aria-hidden="true" />
                            <AlertTitle>Readiness check failed</AlertTitle>
                            <AlertDescription>
                                {provisioningError}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    {provisioning && (
                        <Alert
                            variant="success"
                            role="status"
                            aria-live="polite"
                        >
                            <ShieldCheck aria-hidden="true" />
                            <AlertTitle>
                                Ready for secure provisioning
                            </AlertTitle>
                            <AlertDescription>
                                <ol className="space-y-1">
                                    {provisioning.instructions.map(
                                        (step, i) => (
                                            <li key={i}>
                                                <span className="mr-2 font-medium">
                                                    {i + 1}.
                                                </span>
                                                {step}
                                            </li>
                                        ),
                                    )}
                                </ol>
                            </AlertDescription>
                        </Alert>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle>
                        Setting up a new device — step by step
                    </CardTitle>
                    <CardDescription>
                        First-time provisioning for a fresh-out-of-the-box
                        GV500CG or GL30M.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3 text-sm">
                    <ol className="space-y-3">
                        <Step n={1} title="Insert and activate the SIM card">
                            Insert a data SIM (any NZ carrier — One NZ, Spark,
                            2degrees). Confirm the APN with your carrier and
                            complete carrier provisioning using the approved
                            secure device-management process. Credentials and
                            command content are not displayed in this workspace.
                        </Step>
                        <Step n={2} title="Connect via USB">
                            Plug the CH340G USB cable into the device's config
                            port and into your laptop. Install the CH340G driver
                            if Windows doesn't recognise it. The device will
                            appear as a COM port.
                        </Step>
                        <Step n={3} title="Open the @Track MT Setup tool">
                            Launch the @Track MT Setup tool (in the Queclink
                            folder). Pick the COM port and click Read — the
                            device should respond with its current config.
                        </Step>
                        <Step n={4} title="Point the device at this server">
                            Make sure the <strong>Public hostname</strong> above
                            is set, then check provisioning readiness. Use the
                            approved secure device-management process to apply
                            the protected server configuration.
                        </Step>
                        <Step
                            n={5}
                            title="Wait for it to appear in the Pending tab"
                        >
                            Within ~60 seconds of boot the device will dial in
                            and land in the <strong>Pending</strong> tab. Click{' '}
                            <strong>Claim</strong> and choose what it's
                            tracking: Vehicle, Staff (lone worker), or Client
                            (with consent record).
                        </Step>
                        <Step n={6} title="Confirm in the Debug console">
                            Open the <strong>Debug console</strong> tab and
                            confirm bounded heartbeat and location-report
                            states. Raw frame and device identifiers remain
                            protected.
                        </Step>
                    </ol>
                    <Alert variant="warning" role="note" className="mt-4">
                        <AlertTriangle aria-hidden="true" />
                        <AlertTitle>Safety note</AlertTitle>
                        <AlertDescription>
                            Any device that knows this server&apos;s address and
                            port can land in the Pending tab. Claim only IMEIs
                            you recognise. Rejected devices are inert — they
                            stay connected but receive no acknowledgements and
                            contribute nothing to the system.
                        </AlertDescription>
                    </Alert>
                </CardContent>
            </Card>
        </>
    );
}

function Step({
    n,
    title,
    children,
}: {
    n: number;
    title: string;
    children: React.ReactNode;
}) {
    return (
        <li className="flex gap-3">
            <span className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold">
                {n}
            </span>
            <div className="flex-1 leading-6">
                <p className="font-medium">{title}</p>
                <p className="text-muted-foreground">{children}</p>
            </div>
        </li>
    );
}

function DevicePager({
    pagination,
    label,
}: {
    pagination?: DevicePagination;
    label: string;
}) {
    if (!pagination || pagination.last_page <= 1) return null;

    const visit = (url: string | null) => {
        if (!url) return;
        router.get(
            url,
            {},
            {
                only: ['devices', ...authorityProps],
                preserveScroll: true,
                preserveState: true,
                replace: true,
            },
        );
    };

    return (
        <div className="flex items-center justify-between gap-3 pt-3">
            <Button
                type="button"
                variant="outline"
                disabled={!pagination.prev_page_url}
                onClick={() => visit(pagination.prev_page_url)}
                aria-label={`Previous ${label}`}
            >
                Previous
            </Button>
            <p className="text-sm text-muted-foreground">
                Page {pagination.current_page} of {pagination.last_page}
            </p>
            <Button
                type="button"
                variant="outline"
                disabled={!pagination.next_page_url}
                onClick={() => visit(pagination.next_page_url)}
                aria-label={`Next ${label}`}
            >
                Next
            </Button>
        </div>
    );
}

function TrackerRecords({
    rows,
    total,
    title,
    layout = 'table',
    actionsFor,
    selection,
    canViewDevices = false,
}: {
    rows: Device[];
    total: number;
    title: string;
    layout?: RecordLayout;
    actionsFor: (device: Device) => MenuItem[];
    selection?: {
        keys: ReadonlySet<string | number>;
        onToggle: (device: Device, checked: boolean) => void;
        labelFor: (device: Device) => string;
    };
    canViewDevices?: boolean;
}) {
    const [readingId, setReadingId] = useState<number | null>(null);
    const reading = rows.find((row) => row.id === readingId);
    const context = useEntityContextMenu<Device>();
    const contextDevice = context.ctx?.record;
    const contextRecord = contextDevice
        ? rows.find(
              (row) =>
                  row.id === contextDevice.id &&
                  trackerContextKey(row) === trackerContextKey(contextDevice),
          )
        : undefined;
    const actions = (device: Device): MenuItem[] => [
        {
            label: 'View tracker details',
            icon: Eye,
            onClick: () => setReadingId(device.id),
        },
        ...actionsFor(device),
    ];
    const connection = (device: Device) => (
        <StatusBadge
            variant={
                device.connection_state === 'connected' ? 'info' : 'neutral'
            }
        >
            {connectionStateLabel(device.connection_state)}
        </StatusBadge>
    );
    return (
        <>
            <ListCaption
                title={title}
                caption={rows.length + ' of ' + total + ' shown'}
            />
            {layout === 'cards' ? (
                <EntityCardGrid>
                    {rows.map((device) => (
                        <EntityCard
                            key={device.id}
                            icon={Satellite}
                            name={device.reference}
                            subline={device.model_hint ?? 'Model not observed'}
                            meridian="neutral"
                            actions={actions(device)}
                            onContextMenu={(event) =>
                                context.open(event, device)
                            }
                            onOpen={() => setReadingId(device.id)}
                            selection={
                                selection
                                    ? {
                                          checked: selection.keys.has(
                                              device.id,
                                          ),
                                          label: selection.labelFor(device),
                                          onToggle: (checked) =>
                                              selection.onToggle(
                                                  device,
                                                  checked,
                                              ),
                                      }
                                    : undefined
                            }
                            chips={
                                <>
                                    {connection(device)}
                                    <EntityChip>
                                        {pairingStateLabel(device.status)}
                                    </EntityChip>
                                    {device.assignment && (
                                        <EntityChip>
                                            {assignmentTypeLabel(
                                                device.assignment.type,
                                            )}
                                        </EntityChip>
                                    )}
                                </>
                            }
                            footer={{
                                primary:
                                    device.assignment?.label ??
                                    'No current assignment',
                                secondary:
                                    'Last seen ' + fmtRel(device.last_seen_at),
                                personIcon: Satellite,
                            }}
                        />
                    ))}
                </EntityCardGrid>
            ) : (
                <EntityTable
                    rows={rows}
                    rowKey={(device) => device.id}
                    identityLabel="Tracker"
                    wrapIdentity
                    identity={(device) => ({
                        name: device.reference,
                        subline: device.model_hint ?? 'Model not observed',
                        icon: Satellite,
                    })}
                    columns={[
                        {
                            key: 'assignment',
                            label: 'Current assignment',
                            width: '1.7fr',
                            cell: (device) => (
                                <span>
                                    {device.assignment?.label ??
                                        'No current assignment'}
                                    {device.assignment && (
                                        <span className="text-caption block">
                                            {assignmentTypeLabel(
                                                device.assignment.type,
                                            )}
                                        </span>
                                    )}
                                </span>
                            ),
                        },
                        {
                            key: 'seen',
                            label: 'Last seen',
                            width: '1.5fr',
                            cell: (device) => (
                                <span title={fmt(device.last_seen_at)}>
                                    {fmtRel(device.last_seen_at)}
                                </span>
                            ),
                        },
                        {
                            key: 'first',
                            label: 'First seen',
                            width: '1.5fr',
                            cell: (device) => (
                                <span title={fmt(device.first_seen_at)}>
                                    {fmtRel(device.first_seen_at)}
                                </span>
                            ),
                        },
                        {
                            key: 'connection',
                            label: 'Connection',
                            width: '1fr',
                            cell: connection,
                        },
                    ]}
                    actionsFor={actions}
                    onOpen={(device) => setReadingId(device.id)}
                    onRowContextMenu={context.open}
                    selection={selection}
                    rowHeight="content"
                />
            )}
            {context.ctx && contextRecord && (
                <EntityContextMenu
                    x={context.ctx.x}
                    y={context.ctx.y}
                    title={contextRecord.reference}
                    icon={Satellite}
                    items={actions(contextRecord)}
                    onClose={context.close}
                />
            )}
            {reading && (
                <TrackerReader
                    key={reading.id}
                    device={reading}
                    onClose={() => setReadingId(null)}
                    canViewDevices={canViewDevices}
                />
            )}
        </>
    );
}

function TrackerReader({
    device,
    onClose,
    canViewDevices,
}: {
    device: Device;
    onClose: () => void;
    canViewDevices: boolean;
}) {
    const [section, setSection] = useState(0);
    return (
        <WizardShell
            open
            onClose={onClose}
            title={'Tracker details · ' + device.reference}
            description="Returned observations and current assignment. Location history and consent remain in their owning records."
            railIcon={Satellite}
            railTitle={device.reference}
            railSub="Tracker observations"
            sequential={false}
            stepIndex={section}
            onStepClick={setSection}
            headerLabel={
                section === 0
                    ? 'Tracker and assignment'
                    : 'Technical observations'
            }
            steps={[
                {
                    key: 'tracker',
                    label: 'Tracker and assignment',
                    blurb: 'Identity, connection and source times',
                    icon: Satellite,
                },
                {
                    key: 'technical',
                    label: 'Technical observations',
                    blurb: 'Configuration and command receipts',
                    icon: Settings2,
                },
            ]}
            footerEnd={
                <Button variant="outline" onClick={onClose}>
                    Close details
                </Button>
            }
        >
            <WizardStepPane>
                <div className="space-y-5">
                    {section === 0 ? (
                        <>
                            <ReviewCard icon={Satellite} title="Tracker">
                                <ReviewRow
                                    label="Reference"
                                    value={device.reference}
                                />
                                <ReviewRow
                                    label="Pairing state"
                                    value={pairingStateLabel(device.status)}
                                />
                                <ReviewRow
                                    label="Model"
                                    value={device.model_hint}
                                />
                                <ReviewRow
                                    label="Connection"
                                    value={connectionStateLabel(
                                        device.connection_state,
                                    )}
                                />
                                <ReviewRow
                                    label="First seen"
                                    value={fmt(device.first_seen_at)}
                                />
                                <ReviewRow
                                    label="Last seen"
                                    value={fmt(device.last_seen_at)}
                                />
                                <ReviewRow
                                    label="Last frame"
                                    value={fmt(device.last_frame_at)}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Current assignment"
                            >
                                <ReviewRow
                                    label="Type"
                                    value={
                                        device.assignment
                                            ? assignmentTypeLabel(
                                                  device.assignment.type,
                                              )
                                            : device.pending_pairing_type
                                              ? `${assignmentTypeLabel(device.pending_pairing_type)} (expected)`
                                              : null
                                    }
                                />
                                <ReviewRow
                                    label="Assigned to"
                                    value={device.assignment?.label}
                                />
                                <ReviewRow
                                    label="Assigned at"
                                    value={fmt(
                                        device.assignment?.assigned_at ?? null,
                                    )}
                                />
                                <p className="text-caption mt-3">
                                    Times are shown in NZ time. Current
                                    assignment does not establish who carried
                                    the tracker at an earlier event. Provider
                                    address and raw identifiers remain
                                    protected.
                                </p>
                            </ReviewCard>
                            {canViewDevices && device.canonical_device_id && (
                                <Button asChild variant="outline">
                                    <Link
                                        href={
                                            '/security-devices/devices/' +
                                            device.canonical_device_id
                                        }
                                    >
                                        Open device record
                                    </Link>
                                </Button>
                            )}
                        </>
                    ) : (
                        <>
                            <ReviewCard
                                icon={Settings2}
                                title="Observed configuration"
                            >
                                <ReviewRow
                                    label="Protocol version"
                                    value={device.protocol_version}
                                />
                                <ReviewRow
                                    label="Firmware version"
                                    value={device.firmware_version}
                                />
                                <ReviewRow
                                    label="Configuration state"
                                    value={
                                        device.configuration?.state ===
                                        'observed'
                                            ? 'Observed'
                                            : 'Not observed'
                                    }
                                />
                                <ReviewRow
                                    label="Observed at"
                                    value={fmt(
                                        device.configuration?.observed_at ??
                                            null,
                                    )}
                                />
                                <ReviewRow
                                    label="Sections"
                                    value={
                                        device.configuration
                                            ? sectionReadLabels(
                                                  device.configuration.sections,
                                                  SECTION_READ_OPTIONS,
                                              )
                                            : undefined
                                    }
                                />
                            </ReviewCard>
                            <ListCaption
                                title="Recent command observations"
                                caption={
                                    (device.recent_commands?.length ?? 0) +
                                    ' returned'
                                }
                            />
                            {(device.recent_commands ?? []).length === 0 ? (
                                <EmptyState
                                    variant="compact"
                                    icon={Inbox}
                                    title="No recent command observations"
                                    description="No command receipt was returned for this tracker."
                                />
                            ) : (
                                (device.recent_commands ?? []).map(
                                    (command) => (
                                        <ReviewCard
                                            key={command.id}
                                            icon={Send}
                                            title={command.command_word}
                                        >
                                            <ReviewRow
                                                label="Status"
                                                value={
                                                    <CommandStatusBadge
                                                        status={command.status}
                                                    />
                                                }
                                            />
                                            <ReviewRow
                                                label="Started from"
                                                value={
                                                    command.governed
                                                        ? 'Device Management'
                                                        : 'Older Queclink console'
                                                }
                                            />
                                            <ReviewRow
                                                label="Created"
                                                value={fmt(command.created_at)}
                                            />
                                            <ReviewRow
                                                label="Sent"
                                                value={fmt(command.sent_at)}
                                            />
                                            <ReviewRow
                                                label="Acknowledged at"
                                                value={fmt(command.acked_at)}
                                            />
                                            <ReviewRow
                                                label="Fulfilled"
                                                value={fmt(
                                                    command.fulfilled_at,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Cancelled"
                                                value={fmt(
                                                    command.cancelled_at ??
                                                        null,
                                                )}
                                            />
                                            <ReviewRow
                                                label="Expires"
                                                value={fmt(command.expires_at)}
                                            />
                                            <ReviewRow
                                                label="Reason it failed"
                                                value={
                                                    command.failure_category
                                                        ? plainLabel(
                                                              command.failure_category,
                                                          )
                                                        : null
                                                }
                                            />
                                        </ReviewCard>
                                    ),
                                )
                            )}
                            <p className="text-caption">
                                Transport acknowledgement does not confirm that
                                the intended device change took effect.
                            </p>
                        </>
                    )}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

function PendingTab({
    pending,
    pagination,
    total,
    targets,
    can,
    layout = 'table',
    canViewDevices = false,
}: {
    pending: Device[];
    pagination?: DevicePagination;
    total?: number;
    targets: Props['targets'];
    can: Props['can'];
    layout?: RecordLayout;
    canViewDevices?: boolean;
}) {
    const notify = useHubNotice();
    const [claimingId, setClaimingId] = useState<number | null>(null);
    const [rejectingId, setRejectingId] = useState<number | null>(null);
    const claiming = can.manage
        ? pending.find((device) => device.id === claimingId)
        : null;
    const rejecting = can.manage
        ? pending.find((device) => device.id === rejectingId)
        : null;
    if (!can.manage)
        return (
            <EmptyState
                icon={ShieldCheck}
                title="Tracker access is unavailable"
                description="Permission to manage this integration is required."
            />
        );
    return (
        <>
            <Alert>
                <ShieldCheck aria-hidden="true" />
                <AlertTitle>Recognise the tracker before claiming</AlertTitle>
                <AlertDescription>
                    Only claim a known tracker for an approved Site and target.
                    Client pairing requires current location-tracking consent in
                    the client record.
                </AlertDescription>
            </Alert>
            {pending.length ? (
                <TrackerRecords
                    rows={pending}
                    total={total ?? pagination?.total ?? pending.length}
                    title="Pending pairings"
                    layout={layout}
                    canViewDevices={canViewDevices}
                    actionsFor={(device) => [
                        {
                            label: 'Claim device',
                            icon: ShieldCheck,
                            onClick: () => setClaimingId(device.id),
                        },
                        {
                            label: 'Reject tracker',
                            icon: Trash2,
                            danger: true,
                            onClick: () => setRejectingId(device.id),
                        },
                    ]}
                />
            ) : (
                <EmptyState
                    icon={Inbox}
                    title="No devices waiting for pairing"
                    description="A recognised tracker appears here when its first connection is received. Check protected provisioning readiness in Overview."
                />
            )}
            <DevicePager pagination={pagination} label="pending" />
            {claiming && (
                <ClaimDialog
                    key={claiming.id}
                    device={claiming}
                    targets={targets}
                    onClose={() => setClaimingId(null)}
                />
            )}
            <ConfirmDialog
                open={Boolean(rejecting)}
                onClose={() => setRejectingId(null)}
                onConfirm={() => {
                    if (rejecting && can.manage)
                        router.post(
                            hubPath + '/devices/' + rejecting.id + '/reject',
                            {},
                            {
                                preserveScroll: true,
                                onError: (errors) => notify(firstError(errors)),
                            },
                        );
                }}
                variant="destructive"
                title="Reject tracker?"
                description={
                    rejecting
                        ? 'Reject “' +
                          rejecting.reference +
                          '”? It will be ignored until an authorised operator restores it to the pending tray.'
                        : ''
                }
                confirmText="Reject tracker"
            />
        </>
    );
}

export function RejectedTab({
    rejected,
    pagination,
    total,
    can,
    layout = 'table',
    canViewDevices = false,
}: {
    rejected: Device[];
    pagination?: DevicePagination;
    total?: number;
    can: Props['can'];
    layout?: RecordLayout;
    canViewDevices?: boolean;
}) {
    const notify = useHubNotice();
    const [restoringId, setRestoringId] = useState<number | null>(null);
    const restoring = can.manage
        ? rejected.find((device) => device.id === restoringId)
        : null;
    if (!can.manage)
        return (
            <EmptyState
                icon={ShieldCheck}
                title="Tracker access is unavailable"
                description="Permission to manage this integration is required."
            />
        );
    return (
        <>
            {rejected.length ? (
                <TrackerRecords
                    rows={rejected}
                    total={total ?? pagination?.total ?? rejected.length}
                    title="Rejected devices"
                    layout={layout}
                    canViewDevices={canViewDevices}
                    actionsFor={(device) => [
                        {
                            label: 'Restore',
                            icon: RefreshCw,
                            onClick: () => setRestoringId(device.id),
                        },
                    ]}
                />
            ) : (
                <EmptyState
                    icon={ShieldCheck}
                    title="No rejected devices"
                    description="Rejected trackers remain here for authorised review and recovery."
                />
            )}
            <DevicePager pagination={pagination} label="rejected" />
            <ConfirmDialog
                open={Boolean(restoring)}
                onClose={() => setRestoringId(null)}
                onConfirm={() => {
                    if (restoring && can.manage)
                        router.post(
                            hubPath + '/devices/' + restoring.id + '/restore',
                            {},
                            {
                                preserveScroll: true,
                                onError: (errors) => notify(firstError(errors)),
                            },
                        );
                }}
                title="Restore tracker to pending?"
                description={
                    restoring
                        ? 'Restore “' +
                          restoring.reference +
                          '” to the pending tray? It will still require an authorised claim before it receives commands.'
                        : ''
                }
                confirmText="Restore tracker"
                variant="default"
            />
        </>
    );
}

export function ClaimDialog({
    device,
    targets,
    onClose,
}: {
    device: Device;
    targets: Props['targets'];
    onClose: () => void;
}) {
    const form = useForm<{
        pairing_type: PairingType;
        target_id: string;
        consent_id: string;
    }>({
        pairing_type: device.pending_pairing_type ?? 'vehicle',
        target_id: '',
        consent_id: '',
    });
    const [targetSearch, setTargetSearch] = useState('');
    const [step, setStep] = useState(0);
    const [discardOpen, setDiscardOpen] = useState(false);
    const dirty =
        form.isDirty ||
        targetSearch.trim() !== '' ||
        form.data.target_id !== '' ||
        form.data.consent_id !== '' ||
        form.data.pairing_type !== (device.pending_pairing_type ?? 'vehicle');
    const close = () => {
        if (!form.processing) {
            if (dirty) setDiscardOpen(true);
            else onClose();
        }
    };
    const submit = () => {
        if (form.processing || !form.data.target_id) return;
        form.post(hubPath + '/devices/' + device.id + '/claim', {
            preserveScroll: true,
            onError: () => setStep(0),
            onSuccess: onClose,
        });
    };
    const errorSummaryRef = useRef<HTMLDivElement>(null);
    const claimErrors = Object.entries(form.errors).filter(
        (error): error is [string, string] => Boolean(error[1]),
    );
    const pairingTypeId = `claim-device-${device.id}-pairing-type`;
    const pairingTypeErrorId = `${pairingTypeId}-error`;
    const targetId = `claim-device-${device.id}-target`;
    const targetErrorId = `${targetId}-error`;
    const consentId = `claim-device-${device.id}-consent`;
    const consentHelpId = `${consentId}-help`;
    const consentErrorId = `${consentId}-error`;

    useEffect(() => {
        if (Object.values(form.errors).some(Boolean)) {
            errorSummaryRef.current?.focus();
        }
    }, [form.errors, step]);

    const availableTargets = useMemo(() => {
        return form.data.pairing_type === 'vehicle'
            ? targets.vehicles
            : form.data.pairing_type === 'staff'
              ? targets.staff
              : targets.clients;
    }, [form.data.pairing_type, targets]);

    const selectedTarget = availableTargets.find(
        (target) => String(target.id) === form.data.target_id,
    );
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Claim device"
                description={
                    device.reference +
                    ' — choose the approved target for this tracker.'
                }
                railIcon={Satellite}
                railTitle="Claim device"
                railSub={device.reference}
                steps={[
                    {
                        key: 'assignment',
                        label: 'Assignment',
                        blurb: 'Approved target and current consent',
                        icon: ShieldCheck,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check before claiming the tracker',
                        icon: CheckCircle,
                    },
                ]}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!form.processing) setStep(index);
                }}
                pct={form.data.target_id ? 100 : 0}
                pctLabel="Required details"
                footerStart={
                    <>
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={form.processing}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                disabled={form.processing}
                                onClick={() => setStep(0)}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step === 0 ? (
                        <Button
                            type="button"
                            disabled={form.processing || !form.data.target_id}
                            onClick={() => setStep(1)}
                        >
                            Continue to review
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            disabled={form.processing || !form.data.target_id}
                            onClick={submit}
                        >
                            {form.processing ? 'Claiming…' : 'Claim device'}
                        </Button>
                    )
                }
                onOpenAutoFocus={(event) => {
                    if (claimErrors.length > 0) {
                        event.preventDefault();
                        errorSummaryRef.current?.focus();
                    }
                }}
            >
                <WizardStepPane>
                    {step === 0 ? (
                        <form
                            onSubmit={(event) => {
                                event.preventDefault();
                                if (form.data.target_id) setStep(1);
                            }}
                            className="space-y-5"
                        >
                            {claimErrors.length > 0 && (
                                <div
                                    ref={errorSummaryRef}
                                    role="alert"
                                    tabIndex={-1}
                                    aria-labelledby={`claim-device-${device.id}-error-title`}
                                    className="flex gap-2.5 rounded-lg border border-status-critical/35 bg-status-critical-bg p-3 text-sm text-foreground outline-none focus-visible:ring-2 focus-visible:ring-status-critical focus-visible:ring-offset-2"
                                >
                                    <AlertTriangle
                                        className="mt-0.5 h-4 w-4 shrink-0 text-status-critical"
                                        aria-hidden="true"
                                    />
                                    <div>
                                        <p
                                            id={`claim-device-${device.id}-error-title`}
                                            className="font-semibold text-status-critical"
                                        >
                                            We couldn't claim this device
                                        </p>
                                        <p className="mt-1">
                                            Check the highlighted details below
                                            and try again.
                                        </p>
                                        <ul className="mt-1 list-disc space-y-1 pl-4">
                                            {claimErrors.map(
                                                ([field, error]) => (
                                                    <li key={field}>{error}</li>
                                                ),
                                            )}
                                        </ul>
                                    </div>
                                </div>
                            )}

                            <div className="space-y-2">
                                <Label htmlFor={pairingTypeId}>
                                    What is this device?
                                </Label>
                                <Select
                                    value={form.data.pairing_type}
                                    onValueChange={(v) => {
                                        form.setData({
                                            pairing_type: v as PairingType,
                                            target_id: '',
                                            consent_id: '',
                                        });
                                    }}
                                >
                                    <SelectTrigger
                                        id={pairingTypeId}
                                        aria-invalid={Boolean(
                                            form.errors.pairing_type,
                                        )}
                                        aria-describedby={
                                            form.errors.pairing_type
                                                ? pairingTypeErrorId
                                                : undefined
                                        }
                                    >
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="vehicle">
                                            Vehicle (fleet asset)
                                        </SelectItem>
                                        <SelectItem value="staff">
                                            Staff member (lone worker)
                                        </SelectItem>
                                        <SelectItem value="client">
                                            Client (safeguarding)
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                                {form.errors.pairing_type && (
                                    <p
                                        id={pairingTypeErrorId}
                                        className="text-sm text-destructive"
                                    >
                                        {form.errors.pairing_type}
                                    </p>
                                )}
                                {form.data.pairing_type === 'staff' && (
                                    <p className="text-xs text-muted-foreground">
                                        Staff appear only when they have an
                                        active HR profile with a primary site.
                                        Update the staff profile before pairing
                                        if they are missing.
                                    </p>
                                )}
                                {form.data.pairing_type === 'client' && (
                                    <p className="text-xs text-muted-foreground">
                                        Clients appear only when their profile
                                        has a current site. Update the client
                                        profile before pairing if they are
                                        missing.
                                    </p>
                                )}
                            </div>

                            <div className="space-y-2">
                                <Label htmlFor={targetId}>
                                    {form.data.pairing_type === 'vehicle'
                                        ? 'Vehicle'
                                        : form.data.pairing_type === 'staff'
                                          ? 'Staff member'
                                          : 'Client'}
                                </Label>
                                <div className="flex gap-2">
                                    <Input
                                        value={targetSearch}
                                        onChange={(event) =>
                                            setTargetSearch(event.target.value)
                                        }
                                        aria-label="Search assignment targets"
                                        placeholder={`Search ${form.data.pairing_type === 'vehicle' ? 'vehicles' : form.data.pairing_type === 'staff' ? 'staff' : 'clients'}…`}
                                    />
                                    <Button
                                        type="button"
                                        variant="outline"
                                        onClick={() =>
                                            router.get(
                                                '/security-devices/integrations/queclink',
                                                {
                                                    target_type:
                                                        form.data.pairing_type,
                                                    target_search: targetSearch,
                                                    selected_target_id:
                                                        form.data.target_id ||
                                                        undefined,
                                                },
                                                {
                                                    only: [
                                                        'targets',
                                                        ...authorityProps,
                                                    ],
                                                    preserveScroll: true,
                                                    preserveState: true,
                                                    replace: true,
                                                },
                                            )
                                        }
                                    >
                                        Search
                                    </Button>
                                </div>
                                <Select
                                    value={form.data.target_id}
                                    onValueChange={(v) =>
                                        form.setData('target_id', v)
                                    }
                                >
                                    <SelectTrigger
                                        id={targetId}
                                        aria-invalid={Boolean(
                                            form.errors.target_id,
                                        )}
                                        aria-describedby={
                                            form.errors.target_id
                                                ? targetErrorId
                                                : undefined
                                        }
                                    >
                                        <SelectValue placeholder="Select…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {availableTargets.map((t) => (
                                            <SelectItem
                                                key={t.id}
                                                value={String(t.id)}
                                            >
                                                {t.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                {form.errors.target_id && (
                                    <p
                                        id={targetErrorId}
                                        className="text-sm text-destructive"
                                    >
                                        {form.errors.target_id}
                                    </p>
                                )}
                            </div>

                            {form.data.pairing_type === 'client' && (
                                <div className="space-y-2">
                                    <Label htmlFor={consentId}>
                                        Consent record ID (optional when current
                                        consent exists)
                                    </Label>
                                    <Input
                                        id={consentId}
                                        type="number"
                                        value={form.data.consent_id}
                                        onChange={(e) =>
                                            form.setData(
                                                'consent_id',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="e.g. 42"
                                        aria-invalid={Boolean(
                                            form.errors.consent_id,
                                        )}
                                        aria-describedby={`${consentHelpId}${form.errors.consent_id ? ` ${consentErrorId}` : ''}`}
                                    />
                                    <p
                                        id={consentHelpId}
                                        className="text-xs text-muted-foreground"
                                    >
                                        A current location-tracking consent is
                                        required before you can claim this
                                        device. Leave this blank to use the
                                        client's current consent, or enter
                                        another current consent record ID. If
                                        there is no current consent, record it
                                        in the client's profile first.
                                    </p>
                                    {form.errors.consent_id && (
                                        <p
                                            id={consentErrorId}
                                            className="text-sm text-destructive"
                                        >
                                            {form.errors.consent_id}
                                        </p>
                                    )}
                                </div>
                            )}
                        </form>
                    ) : (
                        <div className="space-y-5">
                            <ReviewCard
                                icon={Satellite}
                                title="Tracker"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Reference"
                                    value={device.reference}
                                />
                                <ReviewRow
                                    label="Model"
                                    value={device.model_hint}
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={ShieldCheck}
                                title="Assignment"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Type"
                                    value={assignmentTypeLabel(
                                        form.data.pairing_type,
                                    )}
                                />
                                <ReviewRow
                                    label="Target"
                                    value={
                                        selectedTarget?.label ??
                                        (form.data.target_id
                                            ? 'Selected record #' +
                                              form.data.target_id
                                            : null)
                                    }
                                />
                                {form.data.pairing_type === 'client' && (
                                    <ReviewRow
                                        label="Consent"
                                        value={
                                            form.data.consent_id
                                                ? 'Record #' +
                                                  form.data.consent_id +
                                                  ' · validation required'
                                                : 'Use current tracking consent · validation required'
                                        }
                                    />
                                )}
                            </ReviewCard>
                            <p className="text-subtle">
                                The server checks current Site access, record
                                ownership and any required consent when you
                                claim. Review does not establish a saved
                                assignment.
                            </p>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discardOpen}
                onClose={() => setDiscardOpen(false)}
                onConfirm={onClose}
                variant="destructive"
                title="Discard this draft?"
                description="The unsent tracker assignment will be discarded."
                confirmText="Discard draft"
                cancelText="Keep editing"
            />
        </>
    );
}

// ── Devices tab ───────────────────────────────────────────────────

function DevicesTab({
    paired,
    pagination,
    total,
    presets,
    can,
    layout = 'table',
    canViewDevices = false,
}: {
    paired: Device[];
    pagination?: DevicePagination;
    total?: number;
    presets: Preset[];
    can: Props['can'];
    layout?: RecordLayout;
    canViewDevices?: boolean;
}) {
    const notify = useHubNotice();
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [releasingId, setReleasingId] = useState<number | null>(null);
    const selectedDevices = paired.filter((device) =>
        selectedIds.includes(device.id),
    );
    const releasing = can.manage
        ? paired.find((device) => device.id === releasingId)
        : null;
    if (!can.manage)
        return (
            <EmptyState
                icon={ShieldCheck}
                title="Tracker access is unavailable"
                description="Permission to manage this integration is required."
            />
        );
    return (
        <>
            <div className="flex flex-wrap items-center justify-between gap-3">
                <p className="text-subtle">
                    Releasing a tracker returns it to pending. Paired status
                    does not confirm a recent location.
                </p>
                <div className="flex items-center gap-3">
                    <Button
                        variant="outline"
                        onClick={() =>
                            setSelectedIds(
                                selectedDevices.length === paired.length
                                    ? []
                                    : paired.map((device) => device.id),
                            )
                        }
                        disabled={!paired.length}
                    >
                        {' '}
                        {selectedDevices.length === paired.length &&
                        paired.length
                            ? 'Clear selection'
                            : 'Select all on this page'}
                    </Button>
                    <Button
                        disabled={!selectedDevices.length}
                        onClick={() => setBulkOpen(true)}
                    >
                        <Send className="mr-2 size-4" />
                        Bulk apply ({selectedDevices.length})
                    </Button>
                </div>
            </div>
            {paired.length ? (
                <TrackerRecords
                    rows={paired}
                    total={total ?? pagination?.total ?? paired.length}
                    title="Paired devices"
                    layout={layout}
                    canViewDevices={canViewDevices}
                    selection={{
                        keys: new Set(selectedIds),
                        labelFor: (device) => 'Select ' + device.reference,
                        onToggle: (device, checked) =>
                            setSelectedIds((current) =>
                                checked
                                    ? [...new Set([...current, device.id])]
                                    : current.filter((id) => id !== device.id),
                            ),
                    }}
                    actionsFor={(device) => [
                        {
                            label: 'Release',
                            icon: Unlink,
                            onClick: () => setReleasingId(device.id),
                        },
                    ]}
                />
            ) : (
                <EmptyState
                    icon={Satellite}
                    title="No devices paired yet"
                    description="Claim a recognised tracker from the Pending view to see it here."
                />
            )}
            <DevicePager pagination={pagination} label="devices" />
            {bulkOpen && selectedDevices.length > 0 && (
                <BulkActionDialog
                    devices={selectedDevices}
                    presets={presets}
                    onClose={() => setBulkOpen(false)}
                />
            )}
            <ConfirmDialog
                open={Boolean(releasing)}
                onClose={() => setReleasingId(null)}
                onConfirm={() => {
                    if (releasing && can.manage)
                        router.post(
                            hubPath + '/devices/' + releasing.id + '/release',
                            {},
                            {
                                preserveScroll: true,
                                onError: (errors) => notify(firstError(errors)),
                            },
                        );
                }}
                variant="default"
                title="Release tracker assignment?"
                description={
                    releasing
                        ? 'Release “' +
                          releasing.reference +
                          '”? It will return to the pending tray and stop receiving remote commands until it is claimed again.'
                        : ''
                }
                confirmText="Release tracker"
            />
        </>
    );
}

type BulkAction =
    | 'read_configuration'
    | 'reboot'
    | 'resident_safety_profile'
    | 'apply_preset';
const BULK_ACTION_LABELS: Record<BulkAction, string> = {
    read_configuration: 'Read full configuration',
    apply_preset: 'Apply a preset',
    resident_safety_profile: 'Apply resident safety profile',
    reboot: 'Reboot selected devices',
};

function BulkActionDialog({
    devices,
    presets,
    onClose,
}: {
    devices: Device[];
    presets: Preset[];
    onClose: () => void;
}) {
    const [action, setAction] = useState<BulkAction>('read_configuration');
    const [section, setSection] = useState('all');
    const [presetId, setPresetId] = useState<string>(
        presets[0] ? String(presets[0].id) : '',
    );

    const [sourceTargets] = useState(() =>
        devices.map(trackerContextKey).sort().join('|'),
    );
    const [sourcePreset, setSourcePreset] = useState(() =>
        presets[0] ? presetContextKey(presets[0]) : null,
    );
    const validTargets =
        sourceTargets === devices.map(trackerContextKey).sort().join('|');
    const validPreset =
        action !== 'apply_preset' ||
        !presetId ||
        presets.some(
            (preset) =>
                String(preset.id) === presetId &&
                presetContextKey(preset) === sourcePreset,
        );
    useEffect(() => {
        if (!validTargets || !validPreset) onClose();
    }, [validTargets, validPreset, onClose]);
    const [step, setStep] = useState(0);
    const [discardOpen, setDiscardOpen] = useState(false);
    const [processing, setProcessing] = useState(false);
    const [failure, setFailure] = useState('');
    const dirty =
        action !== 'read_configuration' ||
        section !== 'all' ||
        presetId !== (presets[0] ? String(presets[0].id) : '');
    const close = () => {
        if (!processing) {
            if (dirty) setDiscardOpen(true);
            else onClose();
        }
    };
    const submit = () => {
        if (
            processing ||
            devices.length === 0 ||
            (action === 'apply_preset' && !presetId)
        )
            return;
        setFailure('');
        setProcessing(true);
        router.post(
            hubPath + '/bulk',
            {
                device_ids: devices.map((device) => device.id),
                action,
                section: action === 'read_configuration' ? section : undefined,
                preset_id:
                    action === 'apply_preset' ? Number(presetId) : undefined,
            },
            {
                preserveScroll: true,
                onSuccess: onClose,
                onError: (errors) =>
                    setFailure(
                        `${firstError(errors)} Your choices remain here. Review the selected trackers and try again.`,
                    ),
                onFinish: () => setProcessing(false),
            },
        );
    };
    if (!validTargets || !validPreset) return null;
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title="Review bulk management"
                description="Choose the intended action, then continue to Device Management for Site checks, change control, independent approval and protected verification."
                railIcon={Send}
                railTitle="Bulk management"
                railSub={devices.length + ' selected trackers'}
                steps={[
                    {
                        key: 'action',
                        label: 'Action',
                        blurb: 'Choose supported management intent',
                        icon: Settings2,
                    },
                    {
                        key: 'review',
                        label: 'Review',
                        blurb: 'Check selected trackers and handoff',
                        icon: ShieldCheck,
                    },
                ]}
                stepIndex={step}
                onStepClick={(index) => {
                    if (!processing) setStep(index);
                }}
                pct={action === 'apply_preset' && !presetId ? 50 : 100}
                footerStart={
                    <>
                        <Button
                            variant="ghost"
                            disabled={processing}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                        {step > 0 && (
                            <Button
                                variant="outline"
                                disabled={processing}
                                onClick={() => setStep(0)}
                            >
                                Back
                            </Button>
                        )}
                    </>
                }
                footerEnd={
                    step === 0 ? (
                        <Button
                            disabled={
                                processing ||
                                devices.length === 0 ||
                                (action === 'apply_preset' && !presetId)
                            }
                            onClick={() => setStep(1)}
                        >
                            Continue to review
                        </Button>
                    ) : (
                        <Button
                            disabled={
                                processing ||
                                devices.length === 0 ||
                                (action === 'apply_preset' && !presetId)
                            }
                            onClick={submit}
                        >
                            {processing
                                ? 'Saving handoff…'
                                : 'Review ' +
                                  devices.length +
                                  ' device' +
                                  (devices.length === 1 ? '' : 's')}
                        </Button>
                    )
                }
            >
                <WizardStepPane>
                    {failure && (
                        <Alert variant="destructive">
                            <AlertTitle>Handoff unavailable</AlertTitle>
                            <AlertDescription>{failure}</AlertDescription>
                        </Alert>
                    )}
                    {step === 0 ? (
                        <fieldset disabled={processing}>
                            <div className="space-y-4">
                                <div className="rounded-md border bg-muted/20 p-3">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Selected devices
                                    </p>
                                    <div className="mt-2 flex max-h-24 flex-wrap gap-2 overflow-y-auto">
                                        {devices.map((device) => (
                                            <Badge
                                                key={device.id}
                                                variant="outline"
                                                className="font-mono"
                                            >
                                                {device.reference}
                                            </Badge>
                                        ))}
                                    </div>
                                </div>

                                <div className="space-y-2">
                                    <Label>Action</Label>
                                    <Select
                                        value={action}
                                        onValueChange={(value) =>
                                            setAction(value as BulkAction)
                                        }
                                    >
                                        <SelectTrigger>
                                            <SelectValue />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {(
                                                Object.keys(
                                                    BULK_ACTION_LABELS,
                                                ) as BulkAction[]
                                            ).map((key) => (
                                                <SelectItem
                                                    key={key}
                                                    value={key}
                                                >
                                                    {BULK_ACTION_LABELS[key]}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>

                                {action === 'read_configuration' && (
                                    <div className="space-y-2">
                                        <Label>Read section</Label>
                                        <Select
                                            value={section}
                                            onValueChange={setSection}
                                        >
                                            <SelectTrigger>
                                                <SelectValue />
                                            </SelectTrigger>
                                            <SelectContent>
                                                <SelectItem value="all">
                                                    All
                                                </SelectItem>
                                                {SECTION_READ_OPTIONS.map(
                                                    (option) => (
                                                        <SelectItem
                                                            key={option.code}
                                                            value={option.code}
                                                        >
                                                            {option.label}
                                                        </SelectItem>
                                                    ),
                                                )}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}

                                {action === 'apply_preset' && (
                                    <div className="space-y-2">
                                        <Label>Preset</Label>
                                        {presets.length === 0 ? (
                                            <p className="text-xs text-muted-foreground">
                                                No presets available yet. Save
                                                one from the Device settings tab
                                                first.
                                            </p>
                                        ) : (
                                            <Select
                                                value={presetId}
                                                onValueChange={(value) => {
                                                    setPresetId(value);
                                                    const preset = presets.find(
                                                        (preset) =>
                                                            String(
                                                                preset.id,
                                                            ) === value,
                                                    );
                                                    setSourcePreset(
                                                        preset
                                                            ? presetContextKey(
                                                                  preset,
                                                              )
                                                            : null,
                                                    );
                                                }}
                                            >
                                                <SelectTrigger>
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {presets.map((preset) => (
                                                        <SelectItem
                                                            key={preset.id}
                                                            value={String(
                                                                preset.id,
                                                            )}
                                                        >
                                                            {preset.name}
                                                            {preset.is_system
                                                                ? ' (built-in)'
                                                                : ''}
                                                        </SelectItem>
                                                    ))}
                                                </SelectContent>
                                            </Select>
                                        )}
                                    </div>
                                )}
                            </div>
                        </fieldset>
                    ) : (
                        <div className="space-y-5">
                            <ReviewCard
                                icon={Send}
                                title="Management intent"
                                onEdit={() => setStep(0)}
                            >
                                <ReviewRow
                                    label="Action"
                                    value={BULK_ACTION_LABELS[action]}
                                />
                                <ReviewRow
                                    label="Read section"
                                    value={
                                        action === 'read_configuration'
                                            ? section === 'all'
                                                ? 'All sections'
                                                : (SECTION_READ_OPTIONS.find(
                                                      (option) =>
                                                          option.code ===
                                                          section,
                                                  )?.label ?? section)
                                            : null
                                    }
                                />
                                <ReviewRow
                                    label="Preset"
                                    value={
                                        action === 'apply_preset'
                                            ? presets.find(
                                                  (preset) =>
                                                      String(preset.id) ===
                                                      presetId,
                                              )?.name
                                            : null
                                    }
                                />
                            </ReviewCard>
                            <ReviewCard
                                icon={Satellite}
                                title="Selected trackers"
                                onEdit={() => setStep(0)}
                            >
                                {devices.map((device) => (
                                    <ReviewRow
                                        key={device.id}
                                        label={device.reference}
                                        value={
                                            device.assignment?.label ??
                                            'No current assignment'
                                        }
                                    />
                                ))}
                            </ReviewCard>
                            <p className="text-subtle">
                                This creates a management handoff for further
                                review. It does not send a command or confirm a
                                completed device action.
                            </p>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discardOpen}
                onClose={() => setDiscardOpen(false)}
                onConfirm={onClose}
                variant="destructive"
                title="Discard this draft?"
                description="The unsent management choices will be discarded."
                confirmText="Discard draft"
                cancelText="Keep editing"
            />
        </>
    );
}

// ── Configuration presets ──────────────────────────────────────────

// ── Device Settings tab ────────────────────────────────────────────

export type ServerSettingsForm = {
    report_mode: string;
    manual_netreg: string;
    buffer_mode: string;
    main_host: string;
    main_port: string;
    backup_host: string;
    backup_port: string;
    sms_gateway: string;
    heartbeat_interval_minutes: string;
    sack_enable: string;
    sms_ack_enable: string;
    psm_network_hold_time_seconds: string;
    protocol_format: string;
};

export type GlobalSettingsForm = {
    device_name: string;
    gnss_timeout_seconds: string;
    event_mask: string;
    report_item_mask: string;
    mode_selection: string;
    continuous_send_interval_seconds: string;
    start_mode: string;
    specified_time_of_day: string;
    wakeup_interval_hours: string;
    gnss_enable: string;
    agps_mode: string;
    gsm_report: string;
    battery_low_percentage: string;
    function_button_mode: string;
    sos_report_mode: string;
    wifi_report: string;
    led_on: string;
    charge_standby_mode: string;
};

// Draft starting values. Device readback stays protected, so drafts always
// start from these documented GL30 defaults, never from observed values.
function serverDefaults(
    _device: Device | null,
    listener: Props['listener'],
): ServerSettingsForm {
    const port = String(listener.port || 8090);

    return {
        report_mode: '3',
        manual_netreg: '0',
        buffer_mode: '1',
        main_host: '',
        main_port: port,
        backup_host: '',
        backup_port: port,
        sms_gateway: '',
        heartbeat_interval_minutes: '5',
        sack_enable: '1',
        sms_ack_enable: '0',
        psm_network_hold_time_seconds: '30',
        protocol_format: '0',
    };
}

function globalDefaults(device: Device | null): GlobalSettingsForm {
    return {
        device_name: device?.model_hint || 'GL30MEU',
        gnss_timeout_seconds: '150',
        event_mask: '08E3',
        report_item_mask: '006F',
        mode_selection: '1',
        continuous_send_interval_seconds: '30',
        start_mode: '0',
        specified_time_of_day: '1200',
        wakeup_interval_hours: '1',
        gnss_enable: '1',
        agps_mode: '1',
        gsm_report: '0000',
        battery_low_percentage: '10',
        function_button_mode: '1',
        sos_report_mode: '1',
        wifi_report: '2',
        led_on: '1',
        charge_standby_mode: '0',
    };
}

export type SectionReadOption = {
    section: string;
    label: string;
    code: string;
};

const SECTION_READ_OPTIONS: SectionReadOption[] = [
    { section: 'identity', label: 'Identity', code: 'BSI' },
    { section: 'server', label: 'Server', code: 'SRI' },
    { section: 'tracking', label: 'Tracking', code: 'CFG' },
    { section: 'identity', label: 'SIM PIN', code: 'PIN' },
    { section: 'server', label: 'Watchdog', code: 'DOG' },
    { section: 'tracking', label: 'Non-movement', code: 'NMD' },
    { section: 'power', label: 'Power', code: 'PDS' },
    { section: 'alarms', label: 'Geofence', code: 'GEO' },
    { section: 'connectivity', label: 'Wi-Fi', code: 'WFI' },
    { section: 'bluetooth', label: 'Bluetooth', code: 'BTS' },
    { section: 'bluetooth', label: 'BLE accessories', code: 'BID' },
    { section: 'alarms', label: 'Phone allow-list', code: 'WLT' },
    { section: 'firmware', label: 'Firmware update', code: 'UPC' },
    { section: 'firmware', label: 'Firmware version', code: 'FVR' },
];

export type AdvancedField = {
    name: string;
    label: string;
    type?: 'text' | 'number' | 'textarea';
    options?: Array<[string, string]>;
    placeholder?: string;
};

export type AdvancedCommandDefinition = {
    key: string;
    section: string;
    label: string;
    command: string;
    summaryKey: string;
    description: string;
    fields: AdvancedField[];
    defaults: Record<string, string>;
    listFields?: string[];
};

const ADVANCED_COMMANDS: AdvancedCommandDefinition[] = [
    {
        key: 'dog',
        section: 'server',
        label: 'Watchdog auto-reboot',
        command: 'dog',
        summaryKey: 'dog',
        description:
            'Keeps a pendant recoverable when it has stopped checking in.',
        defaults: {
            mode: '1',
            reboot_interval: '7',
            reboot_time: '0200',
            report_before_reboot: '1',
            unit: '0',
            send_failure_timeout: '60',
        },
        fields: [
            {
                name: 'mode',
                label: 'Mode',
                options: [
                    ['1', 'Enabled'],
                    ['0', 'Disabled'],
                ],
            },
            {
                name: 'reboot_interval',
                label: 'Reboot interval',
                type: 'number',
            },
            { name: 'reboot_time', label: 'Reboot time' },
            {
                name: 'report_before_reboot',
                label: 'Report before reboot',
                options: [
                    ['1', 'Yes'],
                    ['0', 'No'],
                ],
            },
            {
                name: 'unit',
                label: 'Interval unit',
                options: [
                    ['0', 'Days'],
                    ['1', 'Hours'],
                ],
            },
            {
                name: 'send_failure_timeout',
                label: 'Send failure timeout minutes',
                type: 'number',
            },
        ],
    },
    {
        key: 'time',
        section: 'identity',
        label: 'Time zone',
        command: 'time',
        summaryKey: 'time',
        description: 'Sets the GL30 local-time offset used in reports.',
        defaults: {
            sign: '+',
            hour_offset: '12',
            minute_offset: '0',
            daylight_saving: '0',
            utc_time: '',
        },
        fields: [
            {
                name: 'sign',
                label: 'Sign',
                options: [
                    ['+', '+'],
                    ['-', '-'],
                ],
            },
            { name: 'hour_offset', label: 'Hour offset', type: 'number' },
            { name: 'minute_offset', label: 'Minute offset', type: 'number' },
            {
                name: 'daylight_saving',
                label: 'Daylight saving',
                options: [
                    ['0', 'Off'],
                    ['1', 'On'],
                ],
            },
            {
                name: 'utc_time',
                label: 'UTC time override',
                placeholder: 'YYYYMMDDHHMMSS',
            },
        ],
    },
    {
        key: 'non_movement',
        section: 'tracking',
        label: 'Non-movement detection',
        command: 'non_movement',
        summaryKey: 'non_movement',
        description: 'Controls stillness detection and safe-check reporting.',
        defaults: {
            sensor_enable: '0',
            mode: '0',
            non_movement_duration: '3',
            movement_duration: '3',
            movement_threshold: '2',
            rest_send_interval: '1440',
            report_mode: '2',
            safe_check: '0',
            location_ignore: '',
        },
        fields: [
            {
                name: 'sensor_enable',
                label: 'Sensor',
                options: [
                    ['0', 'Disabled'],
                    ['1', 'Enabled'],
                ],
            },
            { name: 'mode', label: 'Mode', type: 'number' },
            {
                name: 'non_movement_duration',
                label: 'Still duration minutes',
                type: 'number',
            },
            {
                name: 'movement_duration',
                label: 'Movement duration seconds',
                type: 'number',
            },
            {
                name: 'movement_threshold',
                label: 'Movement threshold',
                type: 'number',
            },
            {
                name: 'rest_send_interval',
                label: 'Rest send interval minutes',
                type: 'number',
            },
            { name: 'report_mode', label: 'Report mode', type: 'number' },
            { name: 'safe_check', label: 'Safe check', type: 'number' },
            {
                name: 'location_ignore',
                label: 'Location ignore',
                type: 'number',
            },
        ],
    },
    {
        key: 'power',
        section: 'power',
        label: 'Power saving',
        command: 'power',
        summaryKey: 'power',
        description: 'Controls the PDS sleep profile mask.',
        defaults: { mode: '1', mask: '00000011' },
        fields: [
            {
                name: 'mode',
                label: 'Mode',
                options: [
                    ['1', 'Enabled'],
                    ['0', 'Disabled'],
                ],
            },
            { name: 'mask', label: 'Power mask' },
        ],
    },
    {
        key: 'geo',
        section: 'alarms',
        label: 'On-device geofence',
        command: 'geo',
        summaryKey: 'geofences',
        description: 'Queues one GL30 GEO fence slot.',
        defaults: {
            slot: '0',
            mode: '0',
            longitude: '',
            latitude: '',
            radius: '100',
        },
        fields: [
            { name: 'slot', label: 'Slot', type: 'number' },
            {
                name: 'mode',
                label: 'Mode',
                options: [
                    ['0', 'Disabled'],
                    ['1', 'Enter'],
                    ['2', 'Exit'],
                    ['3', 'Enter and exit'],
                ],
            },
            { name: 'longitude', label: 'Longitude', type: 'number' },
            { name: 'latitude', label: 'Latitude', type: 'number' },
            { name: 'radius', label: 'Radius metres', type: 'number' },
        ],
    },
    {
        key: 'wifi',
        section: 'connectivity',
        label: 'Wi-Fi fallback',
        command: 'wifi',
        summaryKey: 'wifi',
        description: 'Configures GL30 Wi-Fi positioning scan behaviour.',
        defaults: {
            mode: '0',
            scan_interval: '10',
            send_interval: '0',
            lost_times: '2',
            alarm_scan_interval: '10',
            start_index: '1',
            end_index: '1',
            entries: '',
        },
        listFields: ['entries'],
        fields: [
            { name: 'mode', label: 'Mode', type: 'number' },
            {
                name: 'scan_interval',
                label: 'Scan interval minutes',
                type: 'number',
            },
            {
                name: 'send_interval',
                label: 'Send interval minutes',
                type: 'number',
            },
            { name: 'lost_times', label: 'Lost times', type: 'number' },
            {
                name: 'alarm_scan_interval',
                label: 'Alarm scan interval minutes',
                type: 'number',
            },
            { name: 'start_index', label: 'Start index', type: 'number' },
            { name: 'end_index', label: 'End index', type: 'number' },
            {
                name: 'entries',
                label: 'SSID/MAC entries',
                type: 'textarea',
                placeholder: 'One entry per line',
            },
        ],
    },
    {
        key: 'bluetooth',
        section: 'bluetooth',
        label: 'Bluetooth settings',
        command: 'bluetooth',
        summaryKey: 'bluetooth',
        description:
            'Uses GTBTS; GL30 v2.04 does not define a separate GTBT write.',
        defaults: {
            mode: '0',
            bluetooth_name: 'GL30MEU_BT',
            discoverable_mode: '0',
            discoverable_time: '0',
            advertising_interval: '1000',
            advertising_data_type: '0',
        },
        fields: [
            { name: 'mode', label: 'Mode', type: 'number' },
            { name: 'bluetooth_name', label: 'Bluetooth name' },
            {
                name: 'discoverable_mode',
                label: 'Discoverable mode',
                options: [
                    ['0', 'Off'],
                    ['8', 'Temporary'],
                    ['9', 'Always'],
                ],
            },
            {
                name: 'discoverable_time',
                label: 'Discoverable minutes',
                type: 'number',
            },
            {
                name: 'advertising_interval',
                label: 'Advertising interval ms',
                type: 'number',
            },
            {
                name: 'advertising_data_type',
                label: 'Advertising data type',
                type: 'number',
            },
        ],
    },
    {
        key: 'beacons',
        section: 'bluetooth',
        label: 'BLE accessories',
        command: 'beacons',
        summaryKey: 'beacons',
        description: 'Configures paired BLE accessory scanning.',
        defaults: {
            enable: '0',
            beacon_id_model: '4',
            append_mask: '000A',
            scan_interval: '30',
            beacon_accessory_model: '',
            mac_list: '',
        },
        listFields: ['mac_list'],
        fields: [
            {
                name: 'enable',
                label: 'Enable',
                options: [
                    ['0', 'Disabled'],
                    ['1', 'Enabled'],
                ],
            },
            {
                name: 'beacon_id_model',
                label: 'Beacon ID model',
                options: [
                    ['4', 'Model 4'],
                    ['10', 'Model 10'],
                ],
            },
            { name: 'append_mask', label: 'Append mask' },
            {
                name: 'scan_interval',
                label: 'Scan interval seconds',
                type: 'number',
            },
            { name: 'beacon_accessory_model', label: 'Accessory model' },
            {
                name: 'mac_list',
                label: 'MAC list',
                type: 'textarea',
                placeholder: 'One 12-character MAC per line',
            },
        ],
    },
    {
        key: 'allowlist',
        section: 'alarms',
        label: 'Phone allow-list',
        command: 'allowlist',
        summaryKey: 'allowlist',
        description: 'Controls numbers allowed to call or SMS the pendant.',
        defaults: {
            number_filter: '0',
            phone_number_start: '1',
            phone_number_end: '1',
            phone_numbers: '',
        },
        listFields: ['phone_numbers'],
        fields: [
            {
                name: 'number_filter',
                label: 'Number filter',
                options: [
                    ['0', 'Disabled'],
                    ['1', 'Enabled'],
                ],
            },
            {
                name: 'phone_number_start',
                label: 'Start index',
                type: 'number',
            },
            { name: 'phone_number_end', label: 'End index', type: 'number' },
            {
                name: 'phone_numbers',
                label: 'Phone numbers',
                type: 'textarea',
                placeholder: 'One number per line',
            },
        ],
    },
    {
        key: 'firmware_update',
        section: 'firmware',
        label: 'Firmware update',
        command: 'firmware_update',
        summaryKey: 'firmware_update',
        description: 'Queues the GL30 OTA update URL command.',
        defaults: {
            max_download_retry: '0',
            download_timeout_minutes: '10',
            download_protocol: '0',
            report_enable: '0',
            update_interval_hours: '0',
            download_url: '',
            mode: '0',
            extended_status_report: '0',
            identifier_number: '',
        },
        fields: [
            {
                name: 'max_download_retry',
                label: 'Max retries',
                type: 'number',
            },
            {
                name: 'download_timeout_minutes',
                label: 'Download timeout minutes',
                type: 'number',
            },
            {
                name: 'download_protocol',
                label: 'Protocol',
                options: [
                    ['0', 'HTTP'],
                    ['2', 'HTTPS'],
                ],
            },
            {
                name: 'report_enable',
                label: 'Report enable',
                options: [
                    ['0', 'Disabled'],
                    ['1', 'Enabled'],
                ],
            },
            {
                name: 'update_interval_hours',
                label: 'Update interval hours',
                type: 'number',
            },
            { name: 'download_url', label: 'Download URL' },
            { name: 'mode', label: 'Mode', type: 'number' },
            {
                name: 'extended_status_report',
                label: 'Extended status',
                type: 'number',
            },
            { name: 'identifier_number', label: 'Identifier number' },
        ],
    },
    {
        key: 'pin',
        section: 'identity',
        label: 'SIM PIN',
        command: 'pin',
        summaryKey: 'pin',
        description: 'Stores SIM PIN unlock settings.',
        defaults: { auto_unlock_pin: '0', pin: '' },
        fields: [
            {
                name: 'auto_unlock_pin',
                label: 'Auto unlock',
                options: [
                    ['0', 'Disabled'],
                    ['1', 'Enabled'],
                ],
            },
            { name: 'pin', label: 'PIN' },
        ],
    },
];

function commandDefaults(
    definition: AdvancedCommandDefinition,
): Record<string, string> {
    return { ...definition.defaults };
}

function splitList(value: string): string[] {
    return value
        .split(/[\n,]+/)
        .map((item) => item.trim())
        .filter(Boolean);
}

function advancedPayload(
    definition: AdvancedCommandDefinition,
    values: Record<string, string>,
): Record<string, string | string[]> {
    const payload: Record<string, string | string[]> = {
        command: definition.command,
    };

    for (const [key, value] of Object.entries(values)) {
        if (definition.listFields?.includes(key)) {
            payload[key] = splitList(value);
        } else {
            payload[key] = value;
        }
    }

    return payload;
}

export function DeviceSettingsTab(props: {
    devices: Device[];
    /** Paired trackers across every page of the current search. */
    total?: number;
    listener: Props['listener'];
    presets: Preset[];
    retiredPresets?: RetiredPreset[];
    can: Props['can'];
    layout?: 'cards' | 'table';
    onAccessDenied?: () => void;
}) {
    return (
        <QueclinkSettingsWorkspace
            {...props}
            serverDefaults={serverDefaults}
            globalDefaults={globalDefaults}
            definitions={ADVANCED_COMMANDS}
            readOptions={SECTION_READ_OPTIONS}
            payloadFor={advancedPayload}
        />
    );
}

// ── Debug Console tab ─────────────────────────────────────────────

export function DebugConsoleTab({
    devices,
    total,
    can,
    onAccessDenied,
}: {
    devices: Device[];
    /** Paired trackers across every page of the current search. */
    total?: number;
    can: Props['can'];
    onAccessDenied?: () => void;
}) {
    const [frames, setFrames] = useState<Frame[]>([]);
    const [filters, setFilters] = useState<FrameFilters>({
        direction: 'all',
        commandWord: '',
        parseStatus: 'all',
    });
    const [streaming, setStreaming] = useState(true);
    const [streamState, setStreamState] =
        useState<FrameStreamState>('connecting');
    const [streamError, setStreamError] = useState<string | null>(null);
    const [historyError, setHistoryError] = useState<string | null>(null);
    const [connectionAttempt, setConnectionAttempt] = useState(0);
    const esRef = useRef<EventSource | null>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    const [autoscroll, setAutoscroll] = useState(true);
    const updateFilter = <K extends keyof FrameFilters>(
        key: K,
        value: FrameFilters[K],
    ) => {
        setFilters((current) => ({ ...current, [key]: value }));
    };
    const loadRecentFrames = useCallback(
        async (signal?: AbortSignal) => {
            try {
                const response = await fetch(framesUrl(filters), {
                    headers: { Accept: 'application/json' },
                    signal,
                });

                if ([401, 403, 404].includes(response.status)) {
                    setFrames([]);
                    esRef.current?.close();
                    setStreaming(false);
                    onAccessDenied?.();
                    throw new Error('Current frame access is unavailable');
                }
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const payload: unknown = await response.json();
                if (
                    !payload ||
                    typeof payload !== 'object' ||
                    !Array.isArray((payload as { frames?: unknown }).frames)
                ) {
                    throw new Error('Unexpected frame history response');
                }

                if (signal?.aborted) return;
                setFrames((previous) =>
                    mergeFrames(
                        previous,
                        (payload as { frames: Frame[] }).frames,
                    ),
                );
                setHistoryError(null);
            } catch (caught) {
                if (
                    signal?.aborted ||
                    (caught as { name?: string })?.name === 'AbortError'
                ) {
                    return;
                }

                setHistoryError(
                    'Recent frames could not be loaded. Live connection status is shown separately.',
                );
            }
        },
        [filters, onAccessDenied],
    );

    useEffect(() => {
        const controller = new AbortController();
        setFrames([]);
        void loadRecentFrames(controller.signal);

        return () => controller.abort();
    }, [loadRecentFrames]);

    useEffect(() => {
        if (!streaming) {
            esRef.current?.close();
            esRef.current = null;
            setStreamState('paused');
            setStreamError(null);
            return;
        }

        let active = true;
        setStreamState('connecting');
        setStreamError(null);
        const url = frameStreamUrl(filters);
        const es = new EventSource(url);
        es.onopen = () => {
            if (!active) return;
            setStreamState('live');
            setStreamError(null);
        };
        es.onmessage = (e) => {
            if (!active) return;
            try {
                const frame = JSON.parse(e.data) as Frame;
                setFrames((prev) => mergeFrames(prev, [frame]));
            } catch {
                /* heartbeat or malformed line — ignore */
            }
        };
        es.onerror = () => {
            if (!active) return;

            if (es.readyState === EventSource.CLOSED) {
                setStreamState('error');
                setStreamError(
                    'The live frame connection is unavailable. Reconnect after checking listener runtime health.',
                );
                return;
            }

            setStreamState('reconnecting');
            setStreamError(
                'The live frame connection was interrupted. The browser is reconnecting automatically.',
            );
        };
        esRef.current = es;
        return () => {
            active = false;
            es.close();
            esRef.current = null;
        };
    }, [streaming, filters, connectionAttempt]);

    useEffect(() => {
        if (!streaming) return;

        const timer = window.setInterval(() => {
            void loadRecentFrames();
        }, 10_000);

        return () => window.clearInterval(timer);
    }, [loadRecentFrames, streaming]);

    useEffect(() => {
        if (autoscroll && containerRef.current) {
            containerRef.current.scrollTop = containerRef.current.scrollHeight;
        }
    }, [frames, autoscroll]);

    const streamStatus = (
        {
            connecting: {
                label: 'Connecting',
                icon: Loader2,
                variant: 'info',
            },
            live: { label: 'Live', icon: Activity, variant: 'success' },
            reconnecting: {
                label: 'Reconnecting',
                icon: RefreshCw,
                variant: 'warning',
            },
            error: { label: 'Unavailable', icon: XCircle, variant: 'critical' },
            paused: { label: 'Paused', icon: Clock, variant: 'neutral' },
        } as const satisfies Record<
            FrameStreamState,
            { label: string; icon: typeof Activity; variant: StatusVariant }
        >
    )[streamState];
    const StreamStatusIcon = streamStatus.icon;

    const toggleStreaming = () => {
        if (streamState === 'error') {
            setStreamState('connecting');
            setStreamError(null);
            setConnectionAttempt((attempt) => attempt + 1);
            return;
        }

        if (streaming) {
            setStreamState('paused');
            setStreaming(false);
            return;
        }

        setStreamState('connecting');
        setStreamError(null);
        setStreaming(true);
    };

    return (
        <div className="grid gap-6 2xl:grid-cols-[minmax(0,1fr)_22rem] 2xl:items-start">
            {/* Live frame stream */}
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <div>
                            <CardTitle>Live frame stream</CardTitle>
                            <CardDescription>
                                Real-time @Track frames as they arrive on the
                                listener.
                            </CardDescription>
                        </div>
                        <div className="flex items-center gap-2">
                            <StatusBadge
                                variant={streamStatus.variant}
                                role="status"
                                aria-live="polite"
                            >
                                <StreamStatusIcon
                                    className={`size-3 ${
                                        streamState === 'connecting' ||
                                        streamState === 'reconnecting'
                                            ? 'animate-spin motion-reduce:animate-none'
                                            : ''
                                    }`}
                                    aria-hidden="true"
                                />
                                {streamStatus.label}
                            </StatusBadge>
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={toggleStreaming}
                            >
                                {streamState === 'error'
                                    ? 'Reconnect'
                                    : streaming
                                      ? 'Pause'
                                      : 'Resume'}
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setFrames([])}
                            >
                                Clear
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    {historyError || streamError ? (
                        <Alert variant="destructive" role="alert">
                            <AlertTriangle aria-hidden="true" />
                            <AlertTitle>
                                Frame transport needs attention
                            </AlertTitle>
                            <AlertDescription className="space-y-1">
                                {historyError ? <p>{historyError}</p> : null}
                                {streamError ? <p>{streamError}</p> : null}
                            </AlertDescription>
                        </Alert>
                    ) : null}
                    <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        <div className="space-y-2">
                            <Label className="text-xs">Direction</Label>
                            <Select
                                value={filters.direction}
                                onValueChange={(value) =>
                                    updateFilter(
                                        'direction',
                                        value as FrameFilters['direction'],
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All directions
                                    </SelectItem>
                                    <SelectItem value="inbound">
                                        Inbound
                                    </SelectItem>
                                    <SelectItem value="outbound">
                                        Outbound
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-2">
                            <Label className="text-xs">Command word</Label>
                            <Input
                                value={filters.commandWord}
                                placeholder="GTFRI, GTALM, GTRTO"
                                onChange={(event) =>
                                    updateFilter(
                                        'commandWord',
                                        event.target.value,
                                    )
                                }
                            />
                        </div>
                        <div className="space-y-2">
                            <Label className="text-xs">Parse status</Label>
                            <Select
                                value={filters.parseStatus}
                                onValueChange={(value) =>
                                    updateFilter(
                                        'parseStatus',
                                        value as FrameFilters['parseStatus'],
                                    )
                                }
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    <SelectItem value="ok">Parsed</SelectItem>
                                    <SelectItem value="error">
                                        Parse errors
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <Card
                        unstyled
                        ref={containerRef}
                        className="h-[480px] overflow-y-auto rounded-md border bg-background font-mono text-xs"
                        onScroll={(e) => {
                            const el = e.currentTarget;
                            const atBottom =
                                el.scrollHeight -
                                    el.scrollTop -
                                    el.clientHeight <
                                50;
                            setAutoscroll(atBottom);
                        }}
                    >
                        {frames.length === 0 ? (
                            <div className="flex h-full items-center justify-center text-muted-foreground">
                                {streamState === 'live'
                                    ? 'Waiting for frames…'
                                    : streamState === 'paused'
                                      ? 'Frame stream paused.'
                                      : streamState === 'error'
                                        ? 'Live frames are unavailable.'
                                        : 'Establishing the live frame connection…'}
                            </div>
                        ) : (
                            <div className="divide-y">
                                {frames.map((f) => (
                                    <FrameLine key={f.id} frame={f} />
                                ))}
                            </div>
                        )}
                    </Card>
                </CardContent>
            </Card>

            <GovernedCommandHandoff devices={devices} total={total} can={can} />
        </div>
    );
}

function FrameLine({ frame }: { frame: Frame }) {
    const isIn = frame.direction === 'inbound';
    return (
        <div className="flex items-start gap-2 px-3 py-1.5">
            <div className="w-16 shrink-0 text-xs text-muted-foreground">
                {formatTime(frame.created_at, '')}
            </div>
            <div className="w-4 shrink-0">
                {isIn ? (
                    <ArrowDownLeft className="h-3 w-3 text-status-success" />
                ) : (
                    <ArrowUpRight className="h-3 w-3 text-status-info" />
                )}
            </div>
            <div className="w-16 shrink-0 text-xs text-muted-foreground">
                {frame.command_word ?? frame.frame_type}
            </div>
            <div className="flex-1 break-all">
                <code className={frame.parse_ok ? '' : 'text-status-critical'}>
                    {frame.frame_type} frame received
                </code>
                {frame.failure_category && (
                    <p className="mt-0.5 text-xs text-status-critical">
                        Frame parsing failed
                    </p>
                )}
            </div>
        </div>
    );
}

export function GovernedCommandHandoff({
    devices,
    total = devices.length,
    can,
}: {
    devices: Device[];
    total?: number;
    can: Props['can'];
}) {
    const [target, setTarget] = useState<Device | null>(devices[0] ?? null);
    const pickerId = useId();
    const pageLimited = total > devices.length;
    const locationForm = useForm<{
        mode: 'preset';
        preset: 'request_location';
    }>({
        mode: 'preset',
        preset: 'request_location',
    });
    const restartForm = useForm<{
        mode: 'preset';
        preset: 'reboot';
    }>({
        mode: 'preset',
        preset: 'reboot',
    });
    const actionErrors = {
        ...locationForm.errors,
        ...restartForm.errors,
    } as Record<string, string | undefined>;
    const actionError = Object.values(actionErrors).some(Boolean)
        ? firstError(actionErrors)
        : null;

    return (
        <Card className="order-first 2xl:order-last">
            <CardHeader>
                <CardTitle>Protected device actions</CardTitle>
                <CardDescription>
                    Continue in the Device Management workspace so identity
                    confirmation, reason, approval or IT Change, provider
                    delivery, fresh evidence, and audit stay together.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="space-y-2">
                    <Label htmlFor={pickerId}>Target device</Label>
                    <Select
                        value={target ? String(target.id) : ''}
                        onValueChange={(v) =>
                            setTarget(
                                devices.find((d) => String(d.id) === v) ?? null,
                            )
                        }
                    >
                        <SelectTrigger
                            id={pickerId}
                            aria-describedby={
                                pageLimited ? `${pickerId}-scope` : undefined
                            }
                        >
                            <SelectValue placeholder="Pick a paired device…" />
                        </SelectTrigger>
                        <SelectContent>
                            {devices.map((d) => (
                                <SelectItem key={d.id} value={String(d.id)}>
                                    {d.reference}
                                    {d.assignment?.label
                                        ? ` — ${d.assignment.label}`
                                        : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    {pageLimited && (
                        <p id={`${pickerId}-scope`} className="text-caption">
                            {pageScopeHint(devices.length, total)}
                        </p>
                    )}
                </div>

                <div className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                    Raw and configuration-change commands are not exposed from
                    this provider console. Only capabilities with an approved
                    Device adapter appear in Management.
                </div>

                {actionError && (
                    <Alert variant="destructive">
                        <XCircle aria-hidden="true" />
                        <AlertTitle>Action not started</AlertTitle>
                        <AlertDescription>{actionError}</AlertDescription>
                    </Alert>
                )}

                <div className="grid gap-2">
                    <Button
                        type="button"
                        className="w-full"
                        disabled={
                            !can.manage ||
                            !target ||
                            locationForm.processing ||
                            restartForm.processing
                        }
                        onClick={() => {
                            if (!target) return;

                            locationForm.post(
                                `/security-devices/integrations/queclink/devices/${target.id}/command`,
                            );
                        }}
                    >
                        <ShieldCheck className="mr-2 h-4 w-4" /> Review location
                        refresh
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        className="w-full"
                        disabled={
                            !can.manage ||
                            !target ||
                            locationForm.processing ||
                            restartForm.processing
                        }
                        onClick={() => {
                            if (!target) return;

                            restartForm.post(
                                `/security-devices/integrations/queclink/devices/${target.id}/command`,
                            );
                        }}
                    >
                        <RefreshCw className="mr-2 h-4 w-4" /> Review device
                        restart
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

// ── Cloud API boundary ─────────────────────────────────────────────

export function CloudIntegrationTab({
    cloudIntegration,
    can,
}: {
    cloudIntegration: Props['cloudIntegration'];
    can: Props['can'];
}) {
    const notify = useHubNotice();
    const [removeLegacyCredentialOpen, setRemoveLegacyCredentialOpen] =
        useState(false);

    return (
        <>
            <Card className="border-status-warning/30">
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <CardTitle>Queclink cloud API</CardTitle>
                        <StatusBadge variant="neutral">
                            Cloud API unavailable
                        </StatusBadge>
                    </div>
                    <CardDescription>
                        Direct TCP intake remains the primary path. No verified
                        public Queclink cloud contract is enabled, so Oblivion
                        Findings does not save, test, rotate, or use new cloud
                        credentials and does not imply cloud inventory or sync.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    <div className="rounded-lg border bg-muted/30 p-4 text-sm">
                        <p className="font-medium">
                            Native operations remain available
                        </p>
                        <p className="mt-1 text-muted-foreground">
                            Listener health, direct tracker telemetry, device
                            assignment, configuration reads, protected profiles,
                            and Device Management continue through their
                            existing native contracts.
                        </p>
                    </div>

                    {cloudIntegration.legacy_credential_stored ? (
                        <Alert variant="warning" role="note">
                            <AlertTriangle aria-hidden="true" />
                            <AlertTitle>
                                Legacy cloud credential ending in{' '}
                                {cloudIntegration.legacy_credential_last4 ??
                                    'unknown'}
                            </AlertTitle>
                            <AlertDescription>
                                <p>
                                    This retained credential is not used,
                                    tested, or considered connected. Remove it
                                    after confirming no external process depends
                                    on the retired cloud connection.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={!can.manage}
                                    onClick={() =>
                                        setRemoveLegacyCredentialOpen(true)
                                    }
                                >
                                    <Trash2 className="mr-2 h-4 w-4" />
                                    Remove legacy cloud credential
                                </Button>
                            </AlertDescription>
                        </Alert>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            No legacy cloud credential is stored. There is
                            nothing to configure or clean up here.
                        </p>
                    )}
                </CardContent>
            </Card>
            <ConfirmDialog
                open={removeLegacyCredentialOpen}
                onClose={() => setRemoveLegacyCredentialOpen(false)}
                onConfirm={() =>
                    router.delete(
                        '/security-devices/integrations/queclink/key',
                        {
                            preserveScroll: true,
                            onError: (errors) => notify(firstError(errors)),
                        },
                    )
                }
                variant="destructive"
                title="Remove legacy Queclink cloud credential?"
                description="Remove the unused retired cloud credential? Native TCP monitoring and Device Management continue unchanged. This credential cannot be recovered from Oblivion Findings."
                confirmText="Remove legacy credential"
            />
        </>
    );
}
