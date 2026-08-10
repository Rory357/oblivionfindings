import { ConfirmDialog } from '@/components/confirm-dialog';
import { PageHero, PageLayout } from '@/components/page';
import { ReasonDialog } from '@/components/reason-dialog';
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
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Separator } from '@/components/ui/separator';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import {
    TabsRoot as Tabs,
    TabsContent,
    TabsList,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import AppLayout from '@/layouts/app-layout';
import { formatDateTime } from '@/lib/datetime';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    Archive,
    ArrowDownLeft,
    ArrowUpRight,
    BookMarked,
    CheckCircle,
    Clock,
    Database,
    Gauge,
    Inbox,
    LayoutDashboard,
    Loader2,
    Play,
    RefreshCw,
    Satellite,
    Save,
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
    ReactNode,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import {
    SiteCredentialsCard,
    type SiteCredentialRow,
} from './site-credentials';

// ── Types ──────────────────────────────────────────────────────────

type DeviceStatus = 'pending' | 'paired' | 'rejected';
type PairingType = 'vehicle' | 'staff' | 'client';
type Direction = 'inbound' | 'outbound';
type FrameType = 'RESP' | 'ACK' | 'SACK' | 'BUFF' | 'AT' | 'unknown';

type Device = {
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
        type: PairingType;
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

type RecentCommand = {
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

type Preset = {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    target_category: string;
    is_system: boolean;
    sections: string[];
    profile_version?: number | null;
    payload_hash?: string | null;
    created_at: string | null;
};

type RetiredPreset = Preset & {
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

type Props = {
    listener: {
        port: number;
        endpoint_configured: boolean;
        service_state: string;
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
    if (!iso) return '—';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? iso : d.toLocaleString();
}

function fmtRel(iso: string | null): string {
    if (!iso) return 'never';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return iso;
    const diff = Date.now() - d.getTime();
    if (diff < 60_000) return 'just now';
    if (diff < 3_600_000) return `${Math.floor(diff / 60_000)}m ago`;
    if (diff < 86_400_000) return `${Math.floor(diff / 3_600_000)}h ago`;
    return `${Math.floor(diff / 86_400_000)}d ago`;
}

function deviceCount(devices: Props['devices'], status: DeviceStatus): number {
    return devices.counts?.[status] ?? devices[status].length;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'Security & Devices', href: '/security-devices' },
    { title: 'APIs & Integrations', href: '/security-devices/integrations' },
    { title: 'Queclink', href: '/security-devices/integrations/queclink' },
];

const hubTabClassName =
    'inline-flex h-auto shrink-0 items-center gap-1.5 rounded-md border-0 border-b-2 border-transparent bg-transparent px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-accent hover:text-accent-foreground data-[state=active]:border-primary data-[state=active]:bg-primary/10 data-[state=active]:text-primary data-[state=active]:shadow-none';

// ── Page ───────────────────────────────────────────────────────────

export default function QueclinkHub({
    listener,
    devices,
    statistics,
    cloudIntegration,
    siteCredentials,
    targets,
    presets,
    retiredPresets = [],
    can,
}: Props) {
    const [deviceSearch, setDeviceSearch] = useState(devices.search ?? '');
    const pendingCount = deviceCount(devices, 'pending');
    const pairedCount = deviceCount(devices, 'paired');
    const rejectedCount = deviceCount(devices, 'rejected');
    const [activeTab, setActiveTab] = useState<string>(
        pendingCount > 0 ? 'pending' : 'overview',
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Queclink Integration" />
            <PageLayout
                hero={
                    <PageHero
                        variant="compact"
                        backHref="/security-devices/integrations"
                        backLabel="Back to APIs & Integrations"
                        title="Queclink Integration"
                        description="Direct device-to-server TCP intake for vehicle, lone-worker, and client safeguarding trackers."
                    />
                }
            >
                <div
                    data-testid="queclink-page-shell"
                    className="w-full space-y-6"
                >
                    <SiteCredentialsCard rows={siteCredentials} />
                    <form
                        className="flex max-w-xl gap-2"
                        onSubmit={(event) => {
                            event.preventDefault();
                            router.get(
                                '/security-devices/integrations/queclink',
                                { device_search: deviceSearch },
                                {
                                    only: ['devices'],
                                    preserveScroll: true,
                                    preserveState: true,
                                    replace: true,
                                },
                            );
                        }}
                    >
                        <Input
                            value={deviceSearch}
                            onChange={(event) =>
                                setDeviceSearch(event.target.value)
                            }
                            placeholder="Search devices…"
                            aria-label="Search devices"
                        />
                        <Button type="submit" variant="outline">
                            Search devices
                        </Button>
                    </form>
                    <DevicePager
                        pagination={devices.pagination?.paired}
                        label="devices"
                    />
                    {/* ── Tabs ────────────────────────────────────── */}
                    <Tabs
                        value={activeTab}
                        onValueChange={setActiveTab}
                        className="space-y-4"
                    >
                        <div className="relative">
                            <TabsList
                                data-testid="queclink-tab-list"
                                className="flex h-auto w-full flex-wrap justify-start gap-1 rounded-none border-b bg-transparent p-0 pb-1"
                            >
                                <TabsTrigger
                                    value="overview"
                                    className={hubTabClassName}
                                >
                                    <LayoutDashboard className="h-4 w-4" />
                                    Overview
                                </TabsTrigger>
                                <TabsTrigger
                                    value="pending"
                                    className={hubTabClassName}
                                >
                                    <Inbox className="h-4 w-4" />
                                    Pending
                                    {pendingCount > 0 && (
                                        <Badge
                                            variant="outline"
                                            className="ml-1 px-1.5 py-0 text-xs"
                                        >
                                            {pendingCount}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger
                                    value="devices"
                                    className={hubTabClassName}
                                >
                                    <Smartphone className="h-4 w-4" />
                                    Devices ({pairedCount})
                                </TabsTrigger>
                                <TabsTrigger
                                    value="rejected"
                                    className={hubTabClassName}
                                >
                                    <XCircle className="h-4 w-4" />
                                    Rejected ({rejectedCount})
                                </TabsTrigger>
                                <TabsTrigger
                                    value="settings"
                                    className={hubTabClassName}
                                >
                                    <Settings2 className="h-4 w-4" />
                                    Device settings
                                </TabsTrigger>
                                <TabsTrigger
                                    value="console"
                                    className={hubTabClassName}
                                >
                                    <Activity className="h-4 w-4" />
                                    Debug console
                                </TabsTrigger>
                                <TabsTrigger
                                    value="ims"
                                    className={hubTabClassName}
                                >
                                    <Database className="h-4 w-4" />
                                    Cloud API
                                </TabsTrigger>
                            </TabsList>
                        </div>

                        <TabsContent
                            value="overview"
                            className="space-y-6 pt-6"
                        >
                            <OverviewTab
                                listener={listener}
                                devices={devices}
                                statistics={statistics}
                                can={can}
                            />
                        </TabsContent>

                        <TabsContent value="pending" className="space-y-6 pt-6">
                            <PendingTab
                                pending={devices.pending}
                                pagination={devices.pagination?.pending}
                                targets={targets}
                                can={can}
                            />
                        </TabsContent>

                        <TabsContent value="devices" className="space-y-6 pt-6">
                            <DevicesTab
                                paired={devices.paired}
                                presets={presets}
                                can={can}
                            />
                        </TabsContent>

                        <TabsContent
                            value="rejected"
                            className="space-y-6 pt-6"
                        >
                            <RejectedTab
                                rejected={devices.rejected}
                                pagination={devices.pagination?.rejected}
                                can={can}
                            />
                        </TabsContent>

                        <TabsContent
                            value="settings"
                            className="space-y-6 pt-6"
                        >
                            <DeviceSettingsTab
                                devices={devices.paired}
                                listener={listener}
                                presets={presets}
                                retiredPresets={retiredPresets}
                                can={can}
                            />
                        </TabsContent>

                        <TabsContent value="console" className="space-y-6 pt-6">
                            <DebugConsoleTab
                                devices={devices.paired}
                                can={can}
                            />
                        </TabsContent>

                        <TabsContent value="ims" className="space-y-6 pt-6">
                            <CloudIntegrationTab
                                cloudIntegration={cloudIntegration}
                                can={can}
                            />
                        </TabsContent>
                    </Tabs>
                </div>
            </PageLayout>
        </AppLayout>
    );
}

// ── Listener status pill ──────────────────────────────────────────

function ListenerStatusBadge({ listener }: { listener: Props['listener'] }) {
    const state = listener.service_state;
    const isActive = state === 'active';
    const isInactive =
        state === 'inactive' || state === 'failed' || state === 'unknown';
    const isDev = state === 'not_applicable';

    if (isDev) {
        return (
            <Badge className="bg-muted text-muted-foreground" variant="outline">
                <Server className="mr-1.5 h-3 w-3" />
                Dev host — run{' '}
                <code className="mx-1 rounded bg-background px-1 py-0.5">
                    php artisan queclink:listen
                </code>{' '}
                manually
            </Badge>
        );
    }
    return (
        <Badge
            className={
                isActive
                    ? 'bg-status-success-bg text-status-success'
                    : isInactive
                      ? 'bg-status-critical-bg text-status-critical'
                      : 'bg-status-warning-bg text-status-warning'
            }
        >
            {isActive ? (
                <CheckCircle className="mr-1.5 h-3 w-3" />
            ) : (
                <XCircle className="mr-1.5 h-3 w-3" />
            )}
            Listener {state} · {listener.connected_count} connected
        </Badge>
    );
}

// ── Overview tab ──────────────────────────────────────────────────

export function OverviewTab({
    listener,
    devices,
    statistics,
    can,
}: {
    listener: Props['listener'];
    devices: Props['devices'];
    statistics: Props['statistics'];
    can: Props['can'];
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
            <div className="grid gap-4 md:grid-cols-4">
                <StatCard
                    label="Paired devices"
                    value={deviceCount(devices, 'paired')}
                />
                <StatCard
                    label="Pending"
                    value={deviceCount(devices, 'pending')}
                    tone={
                        deviceCount(devices, 'pending') > 0
                            ? 'warning'
                            : undefined
                    }
                />
                <StatCard
                    label="Connected now"
                    value={listener.connected_count}
                />
                <StatCard
                    label="Frames (last hour)"
                    value={statistics.frames_last_hour}
                />
            </div>

            <Card>
                <CardHeader>
                    <CardTitle>Listener settings</CardTitle>
                    <CardDescription>
                        These are the values devices dial into. Changing the
                        port will rewrite the systemd unit and restart the
                        listener — all paired devices must be reconfigured to
                        match.
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
                            {portChanged &&
                                deviceCount(devices, 'paired') > 0 && (
                                    <p className="flex items-start gap-1 rounded-md border border-status-warning/30 bg-status-warning-bg p-2 text-xs text-status-warning">
                                        <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                                        <span>
                                            {deviceCount(devices, 'paired')}{' '}
                                            paired{' '}
                                            {deviceCount(devices, 'paired') ===
                                            1
                                                ? 'device'
                                                : 'devices'}{' '}
                                            will need to be reconfigured to dial
                                            port {settings.data.port} before
                                            they reconnect.
                                        </span>
                                    </p>
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
                                The address devices dial into. An existing
                                hostname remains protected; enter a value only
                                to set or replace it.
                            </p>
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
                        <div
                            className="space-y-3 rounded-lg border border-status-success/30 bg-status-success-bg p-4"
                            aria-live="polite"
                        >
                            <div className="flex items-center gap-2 font-medium text-status-success">
                                <ShieldCheck
                                    className="h-4 w-4"
                                    aria-hidden="true"
                                />
                                Ready for secure provisioning
                            </div>
                            <ol className="space-y-1 text-sm text-muted-foreground">
                                {provisioning.instructions.map((step, i) => (
                                    <li key={i}>
                                        <span className="mr-2 font-medium text-foreground">
                                            {i + 1}.
                                        </span>
                                        {step}
                                    </li>
                                ))}
                            </ol>
                        </div>
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
                        <Step n={6} title="Confirm in the Debug Console">
                            Open the <strong>Debug console</strong> tab and
                            confirm bounded heartbeat and location-report
                            states. Raw frame and device identifiers remain
                            protected.
                        </Step>
                    </ol>
                    <div className="mt-4 rounded-md border border-status-warning/30 bg-status-warning-bg p-3 text-xs text-status-warning">
                        <strong>Safety note:</strong> any device that knows this
                        server's address and port can land in the Pending tab.
                        Claim only IMEIs you recognise. Rejected devices are
                        inert — they stay connected but receive no
                        acknowledgements and contribute nothing to the system.
                    </div>
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

function StatCard({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'warning';
}) {
    return (
        <Card>
            <CardContent className="p-4">
                <p className="text-xs text-muted-foreground">{label}</p>
                <p
                    className={`mt-1 text-2xl font-semibold ${
                        tone === 'warning' && value > 0
                            ? 'text-status-warning'
                            : ''
                    }`}
                >
                    {value}
                </p>
            </CardContent>
        </Card>
    );
}

// ── Pending tab ───────────────────────────────────────────────────

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
                only: ['devices'],
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

function PendingTab({
    pending,
    pagination,
    targets,
    can,
}: {
    pending: Device[];
    pagination?: DevicePagination;
    targets: Props['targets'];
    can: Props['can'];
}) {
    const [claiming, setClaiming] = useState<Device | null>(null);
    const [rejecting, setRejecting] = useState<Device | null>(null);

    if (pending.length === 0) {
        return (
            <>
                <Card>
                    <CardContent className="flex flex-col items-center gap-2 p-12 text-center">
                        <Inbox className="h-10 w-10 text-muted-foreground" />
                        <p className="text-sm font-medium">
                            No devices waiting for pairing
                        </p>
                        <p className="max-w-md text-xs text-muted-foreground">
                            When a device dials in for the first time it lands
                            here. Configure it with the provisioning string from
                            the Overview tab.
                        </p>
                    </CardContent>
                </Card>
                <DevicePager pagination={pagination} label="pending" />
            </>
        );
    }

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle>Pending pairings</CardTitle>
                    <CardDescription>
                        New IMEIs detected by the listener. Claim only ones you
                        recognise — any device that knows your server address
                        can land here.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead>IMEI</TableHead>
                                <TableHead>Model</TableHead>
                                <TableHead>First seen</TableHead>
                                <TableHead>Last seen</TableHead>
                                <TableHead>From</TableHead>
                                <TableHead className="text-right">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {pending.map((d) => (
                                <TableRow key={d.id}>
                                    <TableCell className="font-mono text-xs">
                                        {d.reference}
                                    </TableCell>
                                    <TableCell>{d.model_hint ?? '—'}</TableCell>
                                    <TableCell className="text-xs">
                                        {fmtRel(d.first_seen_at)}
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        {fmtRel(d.last_seen_at)}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        Provider address protected
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            size="sm"
                                            disabled={!can.manage}
                                            onClick={() => setClaiming(d)}
                                        >
                                            Claim
                                        </Button>
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            className="ml-1 text-status-critical hover:text-status-critical"
                                            disabled={!can.manage}
                                            aria-label={`Reject tracker ${d.reference}`}
                                            onClick={() => setRejecting(d)}
                                        >
                                            <Trash2 className="h-3 w-3" />
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
            <DevicePager pagination={pagination} label="pending" />

            {claiming && (
                <ClaimDialog
                    device={claiming}
                    targets={targets}
                    onClose={() => setClaiming(null)}
                />
            )}
            <ConfirmDialog
                open={rejecting !== null}
                onClose={() => setRejecting(null)}
                onConfirm={() => {
                    if (rejecting) {
                        router.post(
                            `/security-devices/integrations/queclink/devices/${rejecting.id}/reject`,
                            {},
                            { preserveScroll: true },
                        );
                    }
                }}
                title="Reject tracker?"
                description={
                    rejecting
                        ? `Reject “${rejecting.reference}”? It will be ignored until an authorised operator restores it to the pending tray.`
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
    can,
}: {
    rejected: Device[];
    pagination?: DevicePagination;
    can: Props['can'];
}) {
    const [restoring, setRestoring] = useState<Device | null>(null);

    return (
        <>
            <Card>
                <CardHeader>
                    <CardTitle>Rejected devices</CardTitle>
                    <CardDescription>
                        Devices blocked from pairing. Restore a recognised
                        tracker to return it to the pending tray for review.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {rejected.length === 0 ? (
                        <div className="flex flex-col items-center gap-2 p-10 text-center">
                            <ShieldCheck className="h-10 w-10 text-muted-foreground" />
                            <p className="text-sm font-medium">
                                No rejected devices
                            </p>
                            <p className="text-xs text-muted-foreground">
                                Rejected trackers remain visible here for safe
                                review and recovery.
                            </p>
                        </div>
                    ) : (
                        <Table>
                            <TableHeader>
                                <TableRow>
                                    <TableHead>IMEI</TableHead>
                                    <TableHead>Model</TableHead>
                                    <TableHead>Last seen</TableHead>
                                    <TableHead>Connection</TableHead>
                                    <TableHead className="text-right">
                                        Actions
                                    </TableHead>
                                </TableRow>
                            </TableHeader>
                            <TableBody>
                                {rejected.map((device) => (
                                    <TableRow key={device.id}>
                                        <TableCell className="font-mono text-xs">
                                            {device.reference}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {device.model_hint ?? '—'}
                                        </TableCell>
                                        <TableCell className="text-xs">
                                            {fmtRel(device.last_seen_at)}
                                        </TableCell>
                                        <TableCell>
                                            <Badge variant="outline">
                                                {device.connection_state ===
                                                'connected'
                                                    ? 'online'
                                                    : 'offline'}
                                            </Badge>
                                        </TableCell>
                                        <TableCell className="text-right">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                disabled={!can.manage}
                                                onClick={() =>
                                                    setRestoring(device)
                                                }
                                            >
                                                <RefreshCw className="mr-1 h-3 w-3" />
                                                Restore
                                            </Button>
                                        </TableCell>
                                    </TableRow>
                                ))}
                            </TableBody>
                        </Table>
                    )}
                </CardContent>
            </Card>
            <DevicePager pagination={pagination} label="rejected" />
            <ConfirmDialog
                open={restoring !== null}
                onClose={() => setRestoring(null)}
                onConfirm={() => {
                    if (restoring) {
                        router.post(
                            `/security-devices/integrations/queclink/devices/${restoring.id}/restore`,
                            {},
                            { preserveScroll: true },
                        );
                    }
                }}
                title="Restore tracker to pending?"
                description={
                    restoring
                        ? `Restore “${restoring.reference}” to the pending tray? It will still require an authorised claim before it receives commands.`
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
    }, [form.errors]);

    const availableTargets = useMemo(() => {
        return form.data.pairing_type === 'vehicle'
            ? targets.vehicles
            : form.data.pairing_type === 'staff'
              ? targets.staff
              : targets.clients;
    }, [form.data.pairing_type, targets]);

    return (
        <Dialog open onOpenChange={onClose}>
            <DialogContent
                className="sm:max-w-md"
                onOpenAutoFocus={(event) => {
                    if (claimErrors.length > 0) {
                        event.preventDefault();
                        errorSummaryRef.current?.focus();
                    }
                }}
            >
                <DialogHeader>
                    <DialogTitle>Claim device</DialogTitle>
                    <DialogDescription>
                        IMEI{' '}
                        <code className="rounded bg-muted px-1 py-0.5 text-xs">
                            {device.reference}
                        </code>{' '}
                        — choose what this device is tracking.
                    </DialogDescription>
                </DialogHeader>

                <form
                    onSubmit={(e) => {
                        e.preventDefault();
                        form.post(
                            `/security-devices/integrations/queclink/devices/${device.id}/claim`,
                            {
                                preserveScroll: true,
                                onSuccess: () => onClose(),
                            },
                        );
                    }}
                    className="space-y-4"
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
                                    Check the highlighted details below and try
                                    again.
                                </p>
                                <ul className="mt-1 list-disc space-y-1 pl-4">
                                    {claimErrors.map(([field, error]) => (
                                        <li key={field}>{error}</li>
                                    ))}
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
                                aria-invalid={Boolean(form.errors.pairing_type)}
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
                                Staff appear only when they have an active HR
                                profile with a primary site. Update the staff
                                profile before pairing if they are missing.
                            </p>
                        )}
                        {form.data.pairing_type === 'client' && (
                            <p className="text-xs text-muted-foreground">
                                Clients appear only when their profile has a
                                current site. Update the client profile before
                                pairing if they are missing.
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
                                placeholder={`Search ${form.data.pairing_type === 'vehicle' ? 'vehicles' : form.data.pairing_type === 'staff' ? 'staff' : 'clients'}…`}
                            />
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() =>
                                    router.get(
                                        '/security-devices/integrations/queclink',
                                        {
                                            target_type: form.data.pairing_type,
                                            target_search: targetSearch,
                                            selected_target_id:
                                                form.data.target_id ||
                                                undefined,
                                        },
                                        {
                                            only: ['targets'],
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
                            onValueChange={(v) => form.setData('target_id', v)}
                        >
                            <SelectTrigger
                                id={targetId}
                                aria-invalid={Boolean(form.errors.target_id)}
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
                                    <SelectItem key={t.id} value={String(t.id)}>
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
                                Consent record ID (optional when current consent
                                exists)
                            </Label>
                            <Input
                                id={consentId}
                                type="number"
                                value={form.data.consent_id}
                                onChange={(e) =>
                                    form.setData('consent_id', e.target.value)
                                }
                                placeholder="e.g. 42"
                                aria-invalid={Boolean(form.errors.consent_id)}
                                aria-describedby={`${consentHelpId}${form.errors.consent_id ? ` ${consentErrorId}` : ''}`}
                            />
                            <p
                                id={consentHelpId}
                                className="text-xs text-muted-foreground"
                            >
                                A current location-tracking consent is required
                                before you can claim this device. Leave this
                                blank to use the client's current consent, or
                                enter another current consent record ID. If
                                there is no current consent, record it in the
                                client's profile first.
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

                    <DialogFooter>
                        <Button type="button" variant="ghost" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button
                            type="submit"
                            disabled={form.processing || !form.data.target_id}
                        >
                            {form.processing ? 'Claiming…' : 'Claim device'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}

// ── Devices tab ───────────────────────────────────────────────────

function DevicesTab({
    paired,
    presets,
    can,
}: {
    paired: Device[];
    presets: Preset[];
    can: Props['can'];
}) {
    const [selectedIds, setSelectedIds] = useState<number[]>([]);
    const [bulkOpen, setBulkOpen] = useState(false);
    const [releasing, setReleasing] = useState<Device | null>(null);
    const allSelected =
        paired.length > 0 && selectedIds.length === paired.length;
    const selectedDevices = paired.filter((device) =>
        selectedIds.includes(device.id),
    );

    if (paired.length === 0) {
        return (
            <>
                <Card>
                    <CardContent className="flex flex-col items-center gap-2 p-12 text-center">
                        <Satellite className="h-10 w-10 text-muted-foreground" />
                        <p className="text-sm font-medium">
                            No devices paired yet
                        </p>
                        <p className="text-xs text-muted-foreground">
                            Pair a device from the Pending tab to see it here.
                        </p>
                    </CardContent>
                </Card>
            </>
        );
    }

    return (
        <>
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div>
                            <CardTitle>Paired devices</CardTitle>
                            <CardDescription>
                                Devices actively reporting to Oblivion.
                                Releasing a device returns it to the pending
                                tray.
                            </CardDescription>
                        </div>
                        <Button
                            type="button"
                            disabled={
                                !can.manage || selectedDevices.length === 0
                            }
                            onClick={() => setBulkOpen(true)}
                        >
                            <Send className="mr-2 h-3 w-3" />
                            Bulk apply ({selectedDevices.length})
                        </Button>
                    </div>
                </CardHeader>
                <CardContent>
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-10">
                                    <input
                                        aria-label="Select all paired devices"
                                        type="checkbox"
                                        checked={allSelected}
                                        onChange={(event) =>
                                            setSelectedIds(
                                                event.target.checked
                                                    ? paired.map(
                                                          (device) => device.id,
                                                      )
                                                    : [],
                                            )
                                        }
                                    />
                                </TableHead>
                                <TableHead>IMEI</TableHead>
                                <TableHead>Type</TableHead>
                                <TableHead>Linked to</TableHead>
                                <TableHead>Model</TableHead>
                                <TableHead>Last seen</TableHead>
                                <TableHead>Connection</TableHead>
                                <TableHead className="text-right">
                                    Actions
                                </TableHead>
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {paired.map((d) => (
                                <TableRow key={d.id}>
                                    <TableCell>
                                        <input
                                            aria-label={`Select ${d.reference}`}
                                            type="checkbox"
                                            checked={selectedIds.includes(d.id)}
                                            onChange={(event) =>
                                                setSelectedIds((current) =>
                                                    event.target.checked
                                                        ? [...current, d.id]
                                                        : current.filter(
                                                              (id) =>
                                                                  id !== d.id,
                                                          ),
                                                )
                                            }
                                        />
                                    </TableCell>
                                    <TableCell className="font-mono text-xs">
                                        {d.reference}
                                    </TableCell>
                                    <TableCell className="text-xs capitalize">
                                        {d.assignment?.type ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-sm">
                                        {d.assignment?.label ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        {d.model_hint ?? '—'}
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        {fmtRel(d.last_seen_at)}
                                    </TableCell>
                                    <TableCell>
                                        {d.connection_state === 'connected' ? (
                                            <Badge className="bg-status-success-bg text-status-success">
                                                online
                                            </Badge>
                                        ) : (
                                            <Badge variant="outline">
                                                offline
                                            </Badge>
                                        )}
                                    </TableCell>
                                    <TableCell className="text-right">
                                        <Button
                                            size="sm"
                                            variant="ghost"
                                            disabled={!can.manage}
                                            onClick={() => setReleasing(d)}
                                        >
                                            <Unlink className="mr-1 h-3 w-3" />
                                            Release
                                        </Button>
                                    </TableCell>
                                </TableRow>
                            ))}
                        </TableBody>
                    </Table>
                </CardContent>
            </Card>
            {bulkOpen && (
                <BulkActionDialog
                    devices={selectedDevices}
                    presets={presets}
                    onClose={() => setBulkOpen(false)}
                />
            )}
            <ConfirmDialog
                open={releasing !== null}
                onClose={() => setReleasing(null)}
                onConfirm={() => {
                    if (releasing) {
                        router.post(
                            `/security-devices/integrations/queclink/devices/${releasing.id}/release`,
                            {},
                            { preserveScroll: true },
                        );
                    }
                }}
                title="Release tracker assignment?"
                description={
                    releasing
                        ? `Release “${releasing.reference}”? It will return to the pending tray and stop receiving governed commands until it is claimed again.`
                        : ''
                }
                confirmText="Release tracker"
            />
        </>
    );
}

function BulkActionDialog({
    devices,
    presets,
    onClose,
}: {
    devices: Device[];
    presets: Preset[];
    onClose: () => void;
}) {
    const [action, setAction] = useState<
        | 'read_configuration'
        | 'reboot'
        | 'resident_safety_profile'
        | 'apply_preset'
    >('read_configuration');
    const [section, setSection] = useState('all');
    const [presetId, setPresetId] = useState<string>(
        presets[0] ? String(presets[0].id) : '',
    );

    return (
        <Dialog open onOpenChange={onClose}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Review bulk management</DialogTitle>
                    <DialogDescription>
                        Choose the intended action, then continue to Device
                        Management for Site checks, change control, independent
                        approval, and protected verification.
                    </DialogDescription>
                </DialogHeader>

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
                                setAction(
                                    value as
                                        | 'read_configuration'
                                        | 'reboot'
                                        | 'resident_safety_profile'
                                        | 'apply_preset',
                                )
                            }
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="read_configuration">
                                    Read full configuration
                                </SelectItem>
                                <SelectItem value="apply_preset">
                                    Apply a preset
                                </SelectItem>
                                <SelectItem value="resident_safety_profile">
                                    Apply resident safety profile
                                </SelectItem>
                                <SelectItem value="reboot">
                                    Reboot selected devices
                                </SelectItem>
                            </SelectContent>
                        </Select>
                    </div>

                    {action === 'read_configuration' && (
                        <div className="space-y-2">
                            <Label>Read section</Label>
                            <Select value={section} onValueChange={setSection}>
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">All</SelectItem>
                                    {SECTION_READ_OPTIONS.map((option) => (
                                        <SelectItem
                                            key={option.code}
                                            value={option.code}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    )}

                    {action === 'apply_preset' && (
                        <div className="space-y-2">
                            <Label>Preset</Label>
                            {presets.length === 0 ? (
                                <p className="text-xs text-muted-foreground">
                                    No presets available yet. Save one from the
                                    Device settings tab first.
                                </p>
                            ) : (
                                <Select
                                    value={presetId}
                                    onValueChange={setPresetId}
                                >
                                    <SelectTrigger>
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {presets.map((preset) => (
                                            <SelectItem
                                                key={preset.id}
                                                value={String(preset.id)}
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

                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={
                            devices.length === 0 ||
                            (action === 'apply_preset' && !presetId)
                        }
                        onClick={() => {
                            router.post(
                                '/security-devices/integrations/queclink/bulk',
                                {
                                    device_ids: devices.map(
                                        (device) => device.id,
                                    ),
                                    action,
                                    section:
                                        action === 'read_configuration'
                                            ? section
                                            : undefined,
                                    preset_id:
                                        action === 'apply_preset'
                                            ? Number(presetId)
                                            : undefined,
                                },
                                {
                                    preserveScroll: true,
                                    onSuccess: onClose,
                                },
                            );
                        }}
                    >
                        Review {devices.length} device
                        {devices.length === 1 ? '' : 's'}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ── Configuration presets ──────────────────────────────────────────

function PresetsCard({
    presets,
    retiredPresets,
    target,
    can,
    serverForm,
    globalForm,
}: {
    presets: Preset[];
    retiredPresets: RetiredPreset[];
    target: Device | null;
    can: Props['can'];
    serverForm: ServerSettingsForm;
    globalForm: GlobalSettingsForm;
}) {
    const [saveOpen, setSaveOpen] = useState(false);
    const [confirm, setConfirm] = useState<Preset | null>(null);
    const [retiring, setRetiring] = useState<Preset | null>(null);

    const applyPreset = (preset: Preset) => {
        if (!target) return;
        router.post(
            `/security-devices/integrations/queclink/devices/${target.id}/presets/${preset.id}/apply`,
            {},
            { preserveScroll: true, onSuccess: () => setConfirm(null) },
        );
    };

    const retirePreset = (preset: Preset, reason: string, done: () => void) => {
        router.delete(
            `/security-devices/integrations/queclink/presets/${preset.id}`,
            {
                data: { reason },
                preserveScroll: true,
                onSuccess: () => setRetiring(null),
                onFinish: done,
            },
        );
    };

    return (
        <Card className="shadow-sm">
            <CardHeader>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex items-start gap-3">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                            <BookMarked className="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle>Configuration presets</CardTitle>
                            <CardDescription>
                                Reuse an encrypted, immutable profile for{' '}
                                {target
                                    ? target.reference
                                    : 'the selected device'}
                                . Applying it always continues through governed
                                Device Management.
                            </CardDescription>
                        </div>
                    </div>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={!can.manage}
                        onClick={() => setSaveOpen(true)}
                    >
                        <Save className="mr-2 h-3 w-3" />
                        Save current as preset
                    </Button>
                </div>
            </CardHeader>
            <CardContent>
                {presets.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No presets yet. Set up the Server and Global cards
                        below, then save them as a reusable preset.
                    </p>
                ) : (
                    <div className="grid gap-3 md:grid-cols-2">
                        {presets.map((preset) => (
                            <div
                                key={preset.id}
                                className="flex flex-col gap-3 rounded-lg border bg-muted/10 p-4"
                            >
                                <div className="flex items-start justify-between gap-2">
                                    <div>
                                        <div className="flex items-center gap-2">
                                            <p className="text-sm font-semibold">
                                                {preset.name}
                                            </p>
                                            <Badge
                                                variant={
                                                    preset.is_system
                                                        ? 'secondary'
                                                        : 'outline'
                                                }
                                                className="text-[10px]"
                                            >
                                                {preset.is_system
                                                    ? 'Built-in'
                                                    : 'Custom'}
                                            </Badge>
                                        </div>
                                        {preset.description && (
                                            <p className="mt-1 text-xs text-muted-foreground">
                                                {preset.description}
                                            </p>
                                        )}
                                    </div>
                                    {!preset.is_system && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="icon"
                                            className="h-7 w-7 text-muted-foreground hover:text-destructive"
                                            disabled={!can.manage}
                                            onClick={() => setRetiring(preset)}
                                            aria-label={`Retire ${preset.name}`}
                                        >
                                            <Trash2 className="h-3.5 w-3.5" />
                                        </Button>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-1">
                                    {preset.sections.map((sectionName) => (
                                        <Badge
                                            key={sectionName}
                                            variant="outline"
                                            className="font-mono text-[10px] uppercase"
                                        >
                                            {sectionName}
                                        </Badge>
                                    ))}
                                </div>
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    className="self-start"
                                    disabled={!can.manage || !target}
                                    onClick={() => setConfirm(preset)}
                                >
                                    <Play className="mr-2 h-3 w-3" />
                                    Review application
                                </Button>
                            </div>
                        ))}
                    </div>
                )}

                {retiredPresets.length > 0 ? (
                    <div className="mt-6 space-y-3 border-t pt-5">
                        <div className="flex items-start gap-2">
                            <Archive
                                className="mt-0.5 h-4 w-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            <div>
                                <p className="text-sm font-medium">
                                    Retired preset history
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    Retired profiles cannot be selected or
                                    executed. Their immutable version, actor and
                                    reason remain available here.
                                </p>
                            </div>
                        </div>
                        <div className="space-y-2">
                            {retiredPresets.map((preset) => (
                                <div
                                    key={preset.id}
                                    className="rounded-md border bg-muted/20 p-3 text-sm"
                                >
                                    <div className="flex flex-wrap items-start justify-between gap-2">
                                        <div>
                                            <p className="font-medium">
                                                {preset.name}
                                            </p>
                                            <p className="text-xs text-muted-foreground">
                                                Retired{' '}
                                                {formatDateTime(
                                                    preset.retired_at,
                                                )}
                                                {preset.retired_by
                                                    ? ` · ${preset.retired_by}`
                                                    : ''}
                                                {preset.profile_version
                                                    ? ` · Profile v${preset.profile_version}`
                                                    : ''}
                                            </p>
                                        </div>
                                        <Badge
                                            variant="outline"
                                            className="gap-1.5"
                                        >
                                            <Archive
                                                className="h-3.5 w-3.5"
                                                aria-hidden="true"
                                            />
                                            Retired
                                        </Badge>
                                    </div>
                                    {preset.retirement_reason ? (
                                        <p className="mt-2 rounded-md bg-background px-3 py-2 text-xs">
                                            <span className="font-medium">
                                                Reason:{' '}
                                            </span>
                                            {preset.retirement_reason}
                                        </p>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    </div>
                ) : null}
            </CardContent>

            {confirm && (
                <Dialog open onOpenChange={() => setConfirm(null)}>
                    <DialogContent className="sm:max-w-md">
                        <DialogHeader>
                            <DialogTitle>
                                Review “{confirm.name}” in Device Management?
                            </DialogTitle>
                            <DialogDescription>
                                This does not send a tracker command. It opens a
                                governed request for{' '}
                                <span className="font-mono">
                                    {target?.reference}
                                </span>
                                , where the profile, Site, approved change,
                                independent approval, and protected readback are
                                checked before and after execution.
                            </DialogDescription>
                        </DialogHeader>
                        <div className="flex flex-wrap gap-2">
                            {confirm.sections.map((sectionName) => (
                                <Badge key={sectionName} variant="outline">
                                    {sectionName}
                                </Badge>
                            ))}
                        </div>
                        <DialogFooter>
                            <Button
                                type="button"
                                variant="ghost"
                                onClick={() => setConfirm(null)}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="button"
                                onClick={() => applyPreset(confirm)}
                            >
                                <Play className="mr-2 h-3 w-3" />
                                Continue to governed review
                            </Button>
                        </DialogFooter>
                    </DialogContent>
                </Dialog>
            )}

            {saveOpen && (
                <SavePresetDialog
                    serverForm={serverForm}
                    globalForm={globalForm}
                    onClose={() => setSaveOpen(false)}
                />
            )}

            <ReasonDialog
                open={retiring !== null}
                onClose={() => setRetiring(null)}
                onConfirm={(reason, done) => {
                    if (retiring) retirePreset(retiring, reason, done);
                }}
                title="Retire configuration preset?"
                description={
                    retiring
                        ? `Retire “${retiring.name}” so it can no longer be selected or executed? Its immutable configuration versions and reasoned history will be retained.`
                        : ''
                }
                label="Reason for retiring this preset"
                placeholder="For example: replaced by the approved current configuration baseline."
                confirmLabel="Retire preset"
            />
        </Card>
    );
}

function SavePresetDialog({
    serverForm,
    globalForm,
    onClose,
}: {
    serverForm: ServerSettingsForm;
    globalForm: GlobalSettingsForm;
    onClose: () => void;
}) {
    const [name, setName] = useState('');
    const [description, setDescription] = useState('');
    const [includeServer, setIncludeServer] = useState(false);
    const [includeTracking, setIncludeTracking] = useState(true);

    const canSubmit =
        name.trim().length > 0 && (includeServer || includeTracking);

    const submit = () => {
        const sections: Record<string, Record<string, string>> = {};
        if (includeTracking)
            sections.tracking = { ...globalForm } as Record<string, string>;
        if (includeServer)
            sections.server = { ...serverForm } as Record<string, string>;

        router.post(
            '/security-devices/integrations/queclink/presets',
            { name, description, sections },
            { preserveScroll: true, onSuccess: onClose },
        );
    };

    return (
        <Dialog open onOpenChange={onClose}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Save configuration preset</DialogTitle>
                    <DialogDescription>
                        Capture the current Server and Global form values as a
                        reusable preset for your team.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-4">
                    <div className="space-y-2">
                        <Label>Name</Label>
                        <Input
                            value={name}
                            onChange={(event) => setName(event.target.value)}
                            placeholder="e.g. Night shift"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label>Description</Label>
                        <Textarea
                            value={description}
                            onChange={(event) =>
                                setDescription(event.target.value)
                            }
                            rows={2}
                            placeholder="Optional — what is this preset for?"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label>Sections to include</Label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-input"
                                checked={includeTracking}
                                onChange={(event) =>
                                    setIncludeTracking(event.target.checked)
                                }
                            />
                            Global tracking (cadence, GNSS, panic button,
                            battery)
                        </label>
                        <label className="flex items-center gap-2 text-sm">
                            <input
                                type="checkbox"
                                className="h-4 w-4 rounded border-input"
                                checked={includeServer}
                                onChange={(event) =>
                                    setIncludeServer(event.target.checked)
                                }
                            />
                            Server connection (hosts, ports, heartbeat)
                        </label>
                    </div>
                </div>
                <DialogFooter>
                    <Button type="button" variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        type="button"
                        disabled={!canSubmit}
                        onClick={submit}
                    >
                        <Save className="mr-2 h-3 w-3" />
                        Save preset
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

// ── Device Settings tab ────────────────────────────────────────────

type ServerSettingsForm = {
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

type GlobalSettingsForm = {
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

function str(value: unknown, fallback: string): string {
    return value === null || value === undefined || value === ''
        ? fallback
        : String(value);
}

function serverDefaults(
    device: Device | null,
    listener: Props['listener'],
): ServerSettingsForm {
    const current: Record<string, string> = {};
    const host = str(current.main_host, '');
    const port = str(current.main_port, String(listener.port || 8090));

    return {
        report_mode: str(current.report_mode, '3'),
        manual_netreg: str(current.manual_netreg, '0'),
        buffer_mode: str(current.buffer_mode, '1'),
        main_host: host,
        main_port: port,
        backup_host: str(current.backup_host, host),
        backup_port: str(current.backup_port, port),
        sms_gateway: str(current.sms_gateway, ''),
        heartbeat_interval_minutes: str(
            current.heartbeat_interval_minutes,
            '5',
        ),
        sack_enable: str(current.sack_enable, '1'),
        sms_ack_enable: str(current.sms_ack_enable, '0'),
        psm_network_hold_time_seconds: str(
            current.psm_network_hold_time_seconds,
            '30',
        ),
        protocol_format: str(current.protocol_format, '0'),
    };
}

function globalDefaults(device: Device | null): GlobalSettingsForm {
    const current: Record<string, string> = {};

    return {
        device_name: str(current.device_name, device?.model_hint || 'GL30MEU'),
        gnss_timeout_seconds: str(current.gnss_timeout_seconds, '150'),
        event_mask: str(current.event_mask, '08E3'),
        report_item_mask: str(current.report_item_mask, '006F'),
        mode_selection: str(current.mode_selection, '1'),
        continuous_send_interval_seconds: str(
            current.continuous_send_interval_seconds,
            '30',
        ),
        start_mode: str(current.start_mode, '0'),
        specified_time_of_day: str(current.specified_time_of_day, '1200'),
        wakeup_interval_hours: str(current.wakeup_interval_hours, '1'),
        gnss_enable: str(current.gnss_enable, '1'),
        agps_mode: str(current.agps_mode, '1'),
        gsm_report: str(current.gsm_report, '0000'),
        battery_low_percentage: str(current.battery_low_percentage, '10'),
        function_button_mode: str(current.function_button_mode, '1'),
        sos_report_mode: str(current.sos_report_mode, '1'),
        wifi_report: str(current.wifi_report, '2'),
        led_on: str(current.led_on, '1'),
        charge_standby_mode: str(current.charge_standby_mode, '0'),
    };
}

type SectionReadOption = {
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

type AdvancedField = {
    name: string;
    label: string;
    type?: 'text' | 'number' | 'textarea';
    options?: Array<[string, string]>;
    placeholder?: string;
};

type AdvancedCommandDefinition = {
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

function commandStatusBadge(status: RecentCommand['status']) {
    const classes = {
        queued: 'bg-status-info-bg text-status-info',
        sent: 'bg-status-warning-bg text-status-warning',
        acked: 'bg-status-success-bg text-status-success',
        failed: 'bg-status-critical-bg text-status-critical',
        expired: 'bg-muted text-muted-foreground',
        cancelled: 'bg-muted text-muted-foreground',
    };

    return <Badge className={classes[status]}>{status}</Badge>;
}

export function DeviceSettingsTab({
    devices,
    listener,
    presets,
    retiredPresets = [],
    can,
}: {
    devices: Device[];
    listener: Props['listener'];
    presets: Preset[];
    retiredPresets?: RetiredPreset[];
    can: Props['can'];
}) {
    const [targetId, setTargetId] = useState<string>(
        devices[0] ? String(devices[0].id) : '',
    );
    const target = useMemo(
        () => devices.find((device) => String(device.id) === targetId) ?? null,
        [devices, targetId],
    );
    const [serverForm, setServerForm] = useState<ServerSettingsForm>(() =>
        serverDefaults(devices[0] ?? null, listener),
    );
    const [globalForm, setGlobalForm] = useState<GlobalSettingsForm>(() =>
        globalDefaults(devices[0] ?? null),
    );
    const [selectedCommand, setSelectedCommand] =
        useState<RecentCommand | null>(null);

    useEffect(() => {
        setServerForm(serverDefaults(target, listener));
        setGlobalForm(globalDefaults(target));
    }, [listener, target]);

    if (devices.length === 0) {
        return (
            <Card>
                <CardHeader>
                    <CardTitle>Device settings</CardTitle>
                    <CardDescription>
                        Pair a Queclink device before configuring device
                        settings.
                    </CardDescription>
                </CardHeader>
            </Card>
        );
    }

    const post = (path: string, payload: Record<string, string | string[]>) => {
        router.post(path, payload, { preserveScroll: true });
    };

    const config = target?.configuration;
    const recentCommands = target?.recent_commands ?? [];
    const readSection = (option: SectionReadOption) => {
        if (!target) return;
        post(
            `/security-devices/integrations/queclink/devices/${target.id}/configuration/${option.section}/read`,
            { command: option.code },
        );
    };

    return (
        <div className="space-y-6">
            <Card className="overflow-hidden border-primary/10 shadow-sm">
                <CardHeader className="border-b bg-gradient-to-r from-background via-muted/30 to-background">
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-3">
                            <div className="flex h-10 w-10 shrink-0 items-center justify-center rounded-lg bg-primary/10 text-primary">
                                <Settings2 className="h-5 w-5" />
                            </div>
                            <div>
                                <CardTitle>Device settings</CardTitle>
                                <CardDescription>
                                    Protected GL30 readback and configuration
                                    profiles governed through Device Management.
                                </CardDescription>
                            </div>
                        </div>
                        <Button
                            type="button"
                            disabled={!can.manage || !target}
                            onClick={() => {
                                if (!target) return;
                                post(
                                    `/security-devices/integrations/queclink/devices/${target.id}/configuration/read`,
                                    { section: 'all' },
                                );
                            }}
                        >
                            <RefreshCw className="mr-2 h-3 w-3" />
                            Read full config
                        </Button>
                    </div>
                </CardHeader>
                <CardContent className="space-y-5 p-6">
                    <div className="grid gap-4 lg:grid-cols-[minmax(260px,420px)_1fr] lg:items-end">
                        <div className="grid gap-2">
                            <Label>Target device</Label>
                            <Select
                                value={targetId}
                                onValueChange={setTargetId}
                            >
                                <SelectTrigger>
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {devices.map((device) => (
                                        <SelectItem
                                            key={device.id}
                                            value={String(device.id)}
                                        >
                                            {device.reference}
                                            {device.assignment?.label
                                                ? ` — ${device.assignment.label}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>

                        <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
                            <SettingsMetric
                                icon={<ShieldCheck className="h-4 w-4" />}
                                label="Connection health"
                                value={target?.connection_state ?? '—'}
                                tone={
                                    target?.connection_state === 'connected'
                                        ? 'success'
                                        : 'muted'
                                }
                            />
                            <SettingsMetric
                                icon={<Smartphone className="h-4 w-4" />}
                                label="Model"
                                value={target?.model_hint ?? 'Unknown'}
                            />
                            <SettingsMetric
                                icon={<Clock className="h-4 w-4" />}
                                label="Last frame"
                                value={fmtRel(target?.last_frame_at ?? null)}
                            />
                            <SettingsMetric
                                icon={<Database className="h-4 w-4" />}
                                label="Config read"
                                value={
                                    config?.state === 'observed'
                                        ? fmtRel(config.observed_at)
                                        : 'not read yet'
                                }
                            />
                            <SettingsMetric
                                icon={<Send className="h-4 w-4" />}
                                label="Queued commands"
                                value={recentCommands.length}
                            />
                        </div>
                    </div>
                </CardContent>
            </Card>

            <Card className="shadow-sm">
                <CardHeader>
                    <div className="flex items-start gap-3">
                        <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                            <RefreshCw className="h-4 w-4" />
                        </div>
                        <div>
                            <CardTitle>Read one section</CardTitle>
                            <CardDescription>
                                Request a protected GTRTO readback through the
                                same governed Device Management lifecycle.
                            </CardDescription>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-7">
                        {SECTION_READ_OPTIONS.map((option) => (
                            <Button
                                key={`${option.section}-${option.code}`}
                                type="button"
                                variant="outline"
                                className="justify-start"
                                disabled={!can.manage || !target}
                                onClick={() => readSection(option)}
                            >
                                <RefreshCw className="mr-2 h-3 w-3" />
                                {option.label}
                            </Button>
                        ))}
                    </div>
                </CardContent>
            </Card>

            <PresetsCard
                presets={presets}
                retiredPresets={retiredPresets}
                target={target}
                can={can}
                serverForm={serverForm}
                globalForm={globalForm}
            />

            <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
                <div className="space-y-6">
                    <div className="grid gap-6 2xl:grid-cols-2">
                        <Card className="shadow-sm">
                            <CardHeader>
                                <div className="flex items-start gap-3">
                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                        <Server className="h-4 w-4" />
                                    </div>
                                    <div>
                                        <CardTitle>Server connection</CardTitle>
                                        <CardDescription>
                                            GL30 SRI settings that keep the
                                            device connected to Oblivion.
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="grid gap-4 md:grid-cols-2">
                                    <Field
                                        label="Main host"
                                        value={serverForm.main_host}
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                main_host: value,
                                            })
                                        }
                                    />
                                    <Field
                                        label="Main port"
                                        type="number"
                                        value={serverForm.main_port}
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                main_port: value,
                                            })
                                        }
                                    />
                                    <Field
                                        label="Backup host"
                                        value={serverForm.backup_host}
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                backup_host: value,
                                            })
                                        }
                                    />
                                    <Field
                                        label="Backup port"
                                        type="number"
                                        value={serverForm.backup_port}
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                backup_port: value,
                                            })
                                        }
                                    />
                                    <Field
                                        label="Heartbeat minutes"
                                        type="number"
                                        value={
                                            serverForm.heartbeat_interval_minutes
                                        }
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                heartbeat_interval_minutes:
                                                    value,
                                            })
                                        }
                                    />
                                    <Field
                                        label="PSM hold seconds"
                                        type="number"
                                        value={
                                            serverForm.psm_network_hold_time_seconds
                                        }
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                psm_network_hold_time_seconds:
                                                    value,
                                            })
                                        }
                                    />
                                    <SelectField
                                        label="Report mode"
                                        value={serverForm.report_mode}
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                report_mode: value,
                                            })
                                        }
                                        options={[
                                            ['3', 'TCP long connection'],
                                            ['7', 'TCP long + backup'],
                                            ['2', 'TCP short forced'],
                                        ]}
                                    />
                                    <SelectField
                                        label="SACK"
                                        value={serverForm.sack_enable}
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                sack_enable: value,
                                            })
                                        }
                                        options={[
                                            ['1', 'Enable and check'],
                                            ['2', 'Enable no serial check'],
                                            ['0', 'Disabled'],
                                        ]}
                                    />
                                    <SelectField
                                        label="Manual network"
                                        value={serverForm.manual_netreg}
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                manual_netreg: value,
                                            })
                                        }
                                        options={[
                                            ['0', 'Disabled'],
                                            ['1', 'Enabled'],
                                        ]}
                                    />
                                    <SelectField
                                        label="Buffer mode"
                                        value={serverForm.buffer_mode}
                                        onChange={(value) =>
                                            setServerForm({
                                                ...serverForm,
                                                buffer_mode: value,
                                            })
                                        }
                                        options={[
                                            ['1', 'Low priority'],
                                            ['2', 'High priority'],
                                            ['0', 'Disabled'],
                                        ]}
                                    />
                                </div>
                                <Button
                                    type="button"
                                    disabled={!can.manage || !target}
                                    onClick={() => {
                                        if (!target) return;
                                        post(
                                            `/security-devices/integrations/queclink/devices/${target.id}/configuration/server`,
                                            serverForm,
                                        );
                                    }}
                                >
                                    <Send className="mr-2 h-3 w-3" />
                                    Review server profile
                                </Button>
                            </CardContent>
                        </Card>

                        <Card className="shadow-sm">
                            <CardHeader>
                                <div className="flex items-start gap-3">
                                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                        <Gauge className="h-4 w-4" />
                                    </div>
                                    <div>
                                        <CardTitle>Global tracking</CardTitle>
                                        <CardDescription>
                                            GL30 CFG settings for testing
                                            cadence, GNSS, Wi-Fi fallback,
                                            alerts, and LEDs.
                                        </CardDescription>
                                    </div>
                                </div>
                            </CardHeader>
                            <CardContent className="space-y-5">
                                <div className="flex justify-end">
                                    <Button
                                        type="button"
                                        variant="outline"
                                        disabled={!can.manage || !target}
                                        onClick={() => {
                                            if (!target) return;
                                            post(
                                                `/security-devices/integrations/queclink/devices/${target.id}/configuration/resident-safety-profile`,
                                                {},
                                            );
                                        }}
                                    >
                                        <ShieldCheck className="mr-2 h-3 w-3" />
                                        Resident safety profile
                                    </Button>
                                </div>
                                <div className="space-y-4">
                                    <div className="grid gap-4 md:grid-cols-2">
                                        <Field
                                            label="Device name"
                                            value={globalForm.device_name}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    device_name: value,
                                                })
                                            }
                                        />
                                        <Field
                                            label="Send interval seconds"
                                            type="number"
                                            value={
                                                globalForm.continuous_send_interval_seconds
                                            }
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    continuous_send_interval_seconds:
                                                        value,
                                                })
                                            }
                                        />
                                        <SelectField
                                            label="Mode"
                                            value={globalForm.mode_selection}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    mode_selection: value,
                                                })
                                            }
                                            options={[
                                                ['1', 'Continuous'],
                                                ['0', 'Power saving'],
                                            ]}
                                        />
                                        <Field
                                            label="GNSS timeout seconds"
                                            type="number"
                                            value={
                                                globalForm.gnss_timeout_seconds
                                            }
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    gnss_timeout_seconds: value,
                                                })
                                            }
                                        />
                                        <SelectField
                                            label="GNSS"
                                            value={globalForm.gnss_enable}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    gnss_enable: value,
                                                })
                                            }
                                            options={[
                                                ['1', 'Enabled'],
                                                ['0', 'Disabled'],
                                            ]}
                                        />
                                        <SelectField
                                            label="AGPS"
                                            value={globalForm.agps_mode}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    agps_mode: value,
                                                })
                                            }
                                            options={[
                                                ['1', 'Enabled'],
                                                ['0', 'Disabled'],
                                            ]}
                                        />
                                        <SelectField
                                            label="Wi-Fi fallback"
                                            value={globalForm.wifi_report}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    wifi_report: value,
                                                })
                                            }
                                            options={[
                                                [
                                                    '2',
                                                    'Report Wi-Fi if GNSS fails',
                                                ],
                                                ['1', 'Always report GTFRI'],
                                                ['4', 'Only Wi-Fi report'],
                                                [
                                                    '8',
                                                    'Wi-Fi assisted position',
                                                ],
                                            ]}
                                        />
                                        <SelectField
                                            label="LEDs"
                                            value={globalForm.led_on}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    led_on: value,
                                                })
                                            }
                                            options={[
                                                ['1', 'Normal'],
                                                ['0', 'Reduced GNSS LED'],
                                                ['2', 'Mostly off'],
                                            ]}
                                        />
                                        <Field
                                            label="Report item mask"
                                            value={globalForm.report_item_mask}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    report_item_mask:
                                                        value.toUpperCase(),
                                                })
                                            }
                                        />
                                        <Field
                                            label="Event mask"
                                            value={globalForm.event_mask}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    event_mask:
                                                        value.toUpperCase(),
                                                })
                                            }
                                        />
                                    </div>

                                    <Separator />

                                    <div className="grid gap-4 md:grid-cols-2">
                                        <SelectField
                                            label="Start mode"
                                            value={globalForm.start_mode}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    start_mode: value,
                                                })
                                            }
                                            options={[
                                                ['0', 'First wakeup at time'],
                                                ['1', 'Wake by interval'],
                                                ['2', 'Wake by both'],
                                            ]}
                                        />
                                        <Field
                                            label="Specified time"
                                            value={
                                                globalForm.specified_time_of_day
                                            }
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    specified_time_of_day:
                                                        value,
                                                })
                                            }
                                        />
                                        <Field
                                            label="Wakeup interval hours"
                                            type="number"
                                            value={
                                                globalForm.wakeup_interval_hours
                                            }
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    wakeup_interval_hours:
                                                        value,
                                                })
                                            }
                                        />
                                        <Field
                                            label="Battery low percentage"
                                            type="number"
                                            value={
                                                globalForm.battery_low_percentage
                                            }
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    battery_low_percentage:
                                                        value,
                                                })
                                            }
                                        />
                                        <Field
                                            label="GSM report mask"
                                            value={globalForm.gsm_report}
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    gsm_report:
                                                        value.toUpperCase(),
                                                })
                                            }
                                        />
                                        <SelectField
                                            label="Charge standby"
                                            value={
                                                globalForm.charge_standby_mode
                                            }
                                            onChange={(value) =>
                                                setGlobalForm({
                                                    ...globalForm,
                                                    charge_standby_mode: value,
                                                })
                                            }
                                            options={[
                                                ['0', 'Disabled'],
                                                ['1', 'Enabled'],
                                            ]}
                                        />
                                    </div>
                                </div>
                                <Button
                                    type="button"
                                    disabled={!can.manage || !target}
                                    onClick={() => {
                                        if (!target) return;
                                        post(
                                            `/security-devices/integrations/queclink/devices/${target.id}/configuration/global`,
                                            globalForm,
                                        );
                                    }}
                                >
                                    <Send className="mr-2 h-3 w-3" />
                                    Review global profile
                                </Button>
                            </CardContent>
                        </Card>
                    </div>

                    <AdvancedQueclinkSectionForm
                        target={target}
                        can={can}
                        post={post}
                    />

                    <Card className="shadow-sm">
                        <CardHeader>
                            <div className="flex items-start gap-3">
                                <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                    <Database className="h-4 w-4" />
                                </div>
                                <div>
                                    <CardTitle>
                                        Configuration snapshot
                                    </CardTitle>
                                    <CardDescription>
                                        Last parsed +RESP:GTALM response from
                                        the selected device.
                                    </CardDescription>
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent>
                            <p className="text-sm text-muted-foreground">
                                {config?.state === 'observed'
                                    ? `Configuration observed. Sections: ${config.sections.join(', ') || 'none reported'}. Values are protected.`
                                    : 'No configuration readback has been received yet.'}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <Card className="shadow-sm xl:sticky xl:top-6 xl:self-start">
                    <CardHeader>
                        <div className="flex items-start gap-3">
                            <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                                <Send className="h-4 w-4" />
                            </div>
                            <div>
                                <CardTitle>Command history</CardTitle>
                                <CardDescription>
                                    Protected delivery history. New or repeated
                                    actions must start from Device Management.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {target?.canonical_device_id ? (
                            <Button asChild size="sm" variant="outline">
                                <Link
                                    href={`/security-devices/devices/${target.canonical_device_id}?section=management`}
                                >
                                    <ShieldCheck className="mr-1 h-3 w-3" />
                                    Open Device Management
                                </Link>
                            </Button>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                Link this tracker to a canonical Device before
                                starting a governed action.
                            </p>
                        )}
                        {recentCommands.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No commands queued yet.
                            </p>
                        ) : (
                            <div className="max-h-[680px] space-y-3 overflow-y-auto pr-1">
                                {recentCommands.map((command) => (
                                    <Card
                                        unstyled
                                        key={command.id}
                                        className="rounded-lg border bg-background p-3 shadow-xs"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium">
                                                {command.command_word}
                                            </span>
                                            {commandStatusBadge(command.status)}
                                        </div>
                                        <p className="mt-1 text-xs text-muted-foreground">
                                            {command.governed
                                                ? 'Governed Device execution detail · content protected'
                                                : command.status === 'queued'
                                                  ? 'Legacy provider-console command · cancellation only · content protected'
                                                  : 'Legacy provider-console history · read only · content protected'}
                                        </p>
                                        <dl className="mt-2 grid grid-cols-2 gap-2 text-xs text-muted-foreground">
                                            <div>
                                                <dt>Created</dt>
                                                <dd>
                                                    {fmtRel(command.created_at)}
                                                </dd>
                                            </div>
                                            <div>
                                                <dt>ACK</dt>
                                                <dd>
                                                    {command.acked_at
                                                        ? fmtRel(
                                                              command.acked_at,
                                                          )
                                                        : 'waiting'}
                                                </dd>
                                            </div>
                                            {command.failure_category && (
                                                <div className="col-span-2">
                                                    <dt>Failure</dt>
                                                    <dd>
                                                        Provider operation
                                                        failed
                                                    </dd>
                                                </div>
                                            )}
                                            {command.cancelled_at && (
                                                <div className="col-span-2">
                                                    <dt>Cancelled</dt>
                                                    <dd>
                                                        {fmtRel(
                                                            command.cancelled_at,
                                                        )}
                                                    </dd>
                                                </div>
                                            )}
                                        </dl>
                                        <div className="mt-3 flex flex-wrap gap-2">
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    setSelectedCommand(command)
                                                }
                                            >
                                                <Database className="mr-1 h-3 w-3" />
                                                Inspect
                                            </Button>
                                            {command.status === 'queued' &&
                                                !command.governed && (
                                                    <Button
                                                        type="button"
                                                        size="sm"
                                                        variant="outline"
                                                        disabled={!can.manage}
                                                        onClick={() =>
                                                            router.post(
                                                                `/security-devices/integrations/queclink/commands/${command.id}/cancel`,
                                                                {},
                                                                {
                                                                    preserveScroll: true,
                                                                },
                                                            )
                                                        }
                                                    >
                                                        <XCircle className="mr-1 h-3 w-3" />
                                                        Cancel
                                                    </Button>
                                                )}
                                        </div>
                                    </Card>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
            <Dialog
                open={selectedCommand !== null}
                onOpenChange={(open) => {
                    if (!open) setSelectedCommand(null);
                }}
            >
                <DialogContent className="max-w-2xl">
                    <DialogHeader>
                        <DialogTitle>Command status</DialogTitle>
                        <DialogDescription>
                            Bounded delivery state. Command and ACK content are
                            protected.
                        </DialogDescription>
                    </DialogHeader>
                    <p className="text-sm text-muted-foreground">
                        {selectedCommand?.failure_category
                            ? 'Provider operation failed.'
                            : 'No failure category is recorded.'}
                    </p>
                    {!selectedCommand?.governed && (
                        <p className="text-sm text-muted-foreground">
                            This is read-only legacy provider-console history.
                            Review current Device state and start any new action
                            from Device Management.
                        </p>
                    )}
                </DialogContent>
            </Dialog>
        </div>
    );
}

function SettingsMetric({
    icon,
    label,
    value,
    tone = 'default',
}: {
    icon: ReactNode;
    label: string;
    value: ReactNode;
    tone?: 'default' | 'success' | 'muted';
}) {
    return (
        <Card
            unstyled
            className="rounded-lg border bg-background p-3 shadow-xs"
        >
            <div
                className={
                    tone === 'success'
                        ? 'mb-2 flex h-8 w-8 items-center justify-center rounded-md bg-status-success-bg text-status-success'
                        : tone === 'muted'
                          ? 'mb-2 flex h-8 w-8 items-center justify-center rounded-md bg-muted text-muted-foreground'
                          : 'mb-2 flex h-8 w-8 items-center justify-center rounded-md bg-primary/10 text-primary'
                }
            >
                {icon}
            </div>
            <p className="text-xs text-muted-foreground">{label}</p>
            <p className="mt-0.5 truncate text-sm font-semibold">{value}</p>
        </Card>
    );
}

function Field({
    label,
    value,
    onChange,
    type = 'text',
}: {
    label: string;
    value: string;
    onChange: (value: string) => void;
    type?: 'text' | 'number';
}) {
    return (
        <div className="space-y-2">
            <Label className="text-xs">{label}</Label>
            <Input
                type={type}
                value={value}
                onChange={(event) => onChange(event.target.value)}
            />
        </div>
    );
}

function SelectField({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: Array<[string, string]>;
    onChange: (value: string) => void;
}) {
    return (
        <div className="space-y-2">
            <Label className="text-xs">{label}</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    {options.map(([optionValue, label]) => (
                        <SelectItem key={optionValue} value={optionValue}>
                            {label}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}

function AdvancedQueclinkSectionForm({
    target,
    can,
    post,
}: {
    target: Device | null;
    can: Props['can'];
    post: (path: string, payload: Record<string, string | string[]>) => void;
}) {
    const [commandKey, setCommandKey] = useState(ADVANCED_COMMANDS[0].key);
    const definition =
        ADVANCED_COMMANDS.find((command) => command.key === commandKey) ??
        ADVANCED_COMMANDS[0];
    const [values, setValues] = useState<Record<string, string>>(() =>
        commandDefaults(definition),
    );

    useEffect(() => {
        setValues(commandDefaults(definition));
    }, [definition]);

    const snapshot = null;

    return (
        <Card className="shadow-sm">
            <CardHeader>
                <div className="flex items-start gap-3">
                    <div className="flex h-9 w-9 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
                        <Settings2 className="h-4 w-4" />
                    </div>
                    <div>
                        <CardTitle>Advanced GL30 sections</CardTitle>
                        <CardDescription>
                            Build an encrypted profile for sections beyond SRI
                            and CFG, then review it in Device Management.
                        </CardDescription>
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-5">
                <div className="grid gap-4 lg:grid-cols-[minmax(220px,320px)_1fr]">
                    <div className="space-y-2">
                        <Label className="text-xs">Section command</Label>
                        <Select
                            value={commandKey}
                            onValueChange={setCommandKey}
                        >
                            <SelectTrigger>
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {ADVANCED_COMMANDS.map((command) => (
                                    <SelectItem
                                        key={command.key}
                                        value={command.key}
                                    >
                                        {command.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-xs text-muted-foreground">
                            {definition.description}
                        </p>
                    </div>

                    <SnapshotSummary value={snapshot} />
                </div>

                <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {definition.fields.map((field) => {
                        const value = values[field.name] ?? '';

                        if (field.options) {
                            return (
                                <SelectField
                                    key={field.name}
                                    label={field.label}
                                    value={value}
                                    options={field.options}
                                    onChange={(nextValue) =>
                                        setValues((current) => ({
                                            ...current,
                                            [field.name]: nextValue,
                                        }))
                                    }
                                />
                            );
                        }

                        if (field.type === 'textarea') {
                            return (
                                <div
                                    key={field.name}
                                    className="space-y-2 md:col-span-2 xl:col-span-3"
                                >
                                    <Label className="text-xs">
                                        {field.label}
                                    </Label>
                                    <Textarea
                                        value={value}
                                        placeholder={field.placeholder}
                                        onChange={(event) =>
                                            setValues((current) => ({
                                                ...current,
                                                [field.name]:
                                                    event.target.value,
                                            }))
                                        }
                                        className="min-h-24 font-mono text-xs"
                                    />
                                </div>
                            );
                        }

                        return (
                            <Field
                                key={field.name}
                                label={field.label}
                                type={
                                    field.type === 'number' ? 'number' : 'text'
                                }
                                value={value}
                                onChange={(nextValue) =>
                                    setValues((current) => ({
                                        ...current,
                                        [field.name]: nextValue,
                                    }))
                                }
                            />
                        );
                    })}
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        disabled={!can.manage || !target}
                        onClick={() => {
                            if (!target) return;
                            post(
                                `/security-devices/integrations/queclink/devices/${target.id}/configuration/${definition.section}`,
                                advancedPayload(definition, values),
                            );
                        }}
                    >
                        <Send className="mr-2 h-3 w-3" />
                        Review {definition.label}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        disabled={!can.manage || !target}
                        onClick={() => setValues(commandDefaults(definition))}
                    >
                        Revert fields
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

function SnapshotSummary({ value }: { value: DeviceConfigurationSummary }) {
    if (!value || (Array.isArray(value) && value.length === 0)) {
        return (
            <div className="rounded-md border bg-muted/20 p-3 text-xs text-muted-foreground">
                No readback for this section yet.
            </div>
        );
    }

    const rows = Array.isArray(value) ? value : [value];
    const pairs = rows.flatMap((row, rowIndex) =>
        Object.entries(row).map(([key, item]) => ({
            key: rows.length > 1 ? `${rowIndex + 1}.${key}` : key,
            value: item,
        })),
    );

    return (
        <div className="rounded-md border bg-muted/20 p-3">
            <p className="mb-2 text-xs font-medium text-muted-foreground">
                Current device value
            </p>
            <div className="flex max-h-32 flex-wrap gap-2 overflow-y-auto">
                {pairs.slice(0, 18).map((pair) => (
                    <Badge
                        key={`${pair.key}-${pair.value}`}
                        variant="outline"
                        className="max-w-full truncate font-mono"
                    >
                        {pair.key}: {pair.value || 'blank'}
                    </Badge>
                ))}
                {pairs.length > 18 && (
                    <Badge variant="outline">+{pairs.length - 18} more</Badge>
                )}
            </div>
        </div>
    );
}

// ── Debug Console tab ─────────────────────────────────────────────

export function DebugConsoleTab({
    devices,
    can,
}: {
    devices: Device[];
    can: Props['can'];
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
        [filters],
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

    const streamStatus = {
        connecting: {
            label: 'Connecting',
            icon: Loader2,
            className: 'text-status-info',
        },
        live: {
            label: 'Live',
            icon: Activity,
            className: 'text-status-success',
        },
        reconnecting: {
            label: 'Reconnecting',
            icon: RefreshCw,
            className: 'text-status-warning',
        },
        error: {
            label: 'Unavailable',
            icon: XCircle,
            className: 'text-status-critical',
        },
        paused: {
            label: 'Paused',
            icon: Clock,
            className: 'text-muted-foreground',
        },
    }[streamState];
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
                            <Badge
                                variant="outline"
                                role="status"
                                aria-live="polite"
                                className={streamStatus.className}
                            >
                                <StreamStatusIcon
                                    className={`mr-1 h-3 w-3 ${
                                        streamState === 'connecting' ||
                                        streamState === 'reconnecting'
                                            ? 'animate-spin motion-reduce:animate-none'
                                            : ''
                                    }`}
                                    aria-hidden="true"
                                />
                                {streamStatus.label}
                            </Badge>
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

            <GovernedCommandHandoff devices={devices} can={can} />
        </div>
    );
}

function FrameLine({ frame }: { frame: Frame }) {
    const isIn = frame.direction === 'inbound';
    return (
        <div className="flex items-start gap-2 px-3 py-1.5">
            <div className="w-16 shrink-0 text-[10px] text-muted-foreground">
                {frame.created_at
                    ? new Date(frame.created_at).toLocaleTimeString()
                    : ''}
            </div>
            <div className="w-4 shrink-0">
                {isIn ? (
                    <ArrowDownLeft className="h-3 w-3 text-status-success" />
                ) : (
                    <ArrowUpRight className="h-3 w-3 text-status-info" />
                )}
            </div>
            <div className="w-16 shrink-0 text-[10px] text-muted-foreground">
                {frame.command_word ?? frame.frame_type}
            </div>
            <div className="flex-1 break-all">
                <code className={frame.parse_ok ? '' : 'text-status-critical'}>
                    {frame.frame_type} frame received
                </code>
                {frame.failure_category && (
                    <p className="mt-0.5 text-[10px] text-status-critical">
                        Frame parsing failed
                    </p>
                )}
            </div>
        </div>
    );
}

export function GovernedCommandHandoff({
    devices,
    can,
}: {
    devices: Device[];
    can: Props['can'];
}) {
    const [target, setTarget] = useState<Device | null>(devices[0] ?? null);
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

    return (
        <Card className="order-first 2xl:order-last">
            <CardHeader>
                <CardTitle>Governed device actions</CardTitle>
                <CardDescription>
                    Continue in the canonical Device Management workspace so
                    identity confirmation, reason, approval or IT Change,
                    provider delivery, fresh evidence, and audit stay together.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="space-y-2">
                    <Label>Target device</Label>
                    <Select
                        value={target ? String(target.id) : ''}
                        onValueChange={(v) =>
                            setTarget(
                                devices.find((d) => String(d.id) === v) ?? null,
                            )
                        }
                    >
                        <SelectTrigger>
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
                </div>

                <div className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
                    Raw and configuration-change commands are not exposed from
                    this provider console. Only capabilities with a governed
                    Device adapter appear in Management.
                </div>

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
                        <ShieldCheck className="mr-2 h-4 w-4" /> Review governed
                        location refresh
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
                        <RefreshCw className="mr-2 h-4 w-4" /> Review governed
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
    const [removeLegacyCredentialOpen, setRemoveLegacyCredentialOpen] =
        useState(false);

    return (
        <>
            <Card className="border-status-warning/30">
                <CardHeader>
                    <div className="flex flex-wrap items-center justify-between gap-3">
                        <CardTitle>Queclink cloud API</CardTitle>
                        <Badge variant="outline">Cloud API unavailable</Badge>
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
                            Listener health, direct tracker telemetry, canonical
                            assignment, configuration reads, protected profiles,
                            and governed Device Management continue through
                            their existing native contracts.
                        </p>
                    </div>

                    {cloudIntegration.legacy_credential_stored ? (
                        <div className="space-y-3 rounded-lg border border-status-warning/30 bg-status-warning/5 p-4 text-sm">
                            <p className="font-medium">
                                Legacy cloud credential ending in{' '}
                                {cloudIntegration.legacy_credential_last4 ??
                                    'unknown'}
                            </p>
                            <p className="text-muted-foreground">
                                This retained credential is not used, tested, or
                                considered connected. Remove it after confirming
                                no external process depends on the retired
                                scaffold.
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
                        </div>
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
                        { preserveScroll: true },
                    )
                }
                title="Remove legacy Queclink cloud credential?"
                description="Remove the unused retired cloud credential? Native TCP monitoring and governed Device Management continue unchanged. This credential cannot be recovered from Oblivion Findings."
                confirmText="Remove legacy credential"
            />
        </>
    );
}
