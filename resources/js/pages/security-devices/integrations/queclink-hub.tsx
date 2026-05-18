import PageShell from '@/components/page-shell';
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
import { type BreadcrumbItem } from '@/types';
import { Head, router, useForm } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowDownLeft,
    ArrowUpRight,
    CheckCircle,
    Clock,
    Copy,
    Database,
    Gauge,
    Inbox,
    LayoutDashboard,
    Loader2,
    Play,
    RadioTower,
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
    ReactNode,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';

// ── Types ──────────────────────────────────────────────────────────

type DeviceStatus = 'pending' | 'paired' | 'rejected';
type PairingType = 'vehicle' | 'staff' | 'client';
type Direction = 'inbound' | 'outbound';
type FrameType = 'RESP' | 'ACK' | 'SACK' | 'BUFF' | 'AT' | 'unknown';

type Device = {
    id: number;
    imei: string;
    status: DeviceStatus;
    model_hint: string | null;
    protocol_version: string | null;
    firmware_version: string | null;
    connection_state: 'connected' | 'disconnected';
    first_seen_at: string | null;
    last_seen_at: string | null;
    last_frame_at: string | null;
    remote_address: string | null;
    assignment: {
        type: PairingType;
        target_id: number;
        assigned_at: string | null;
        label: string;
    } | null;
    configuration?: DeviceConfiguration | null;
    recent_commands?: RecentCommand[];
};

type Target = { id: number; label: string };

type Frame = {
    id: number;
    imei: string | null;
    direction: Direction;
    frame_type: FrameType;
    command_word: string | null;
    raw_frame: string;
    parse_ok: boolean;
    parse_error: string | null;
    created_at: string | null;
};

type DeviceConfigurationSection = {
    name: string;
    values: string[];
};

type DeviceConfiguration = {
    available: boolean;
    received_at: string | null;
    raw: string;
    sections: Record<string, DeviceConfigurationSection>;
    summary: {
        server: Record<string, string> | null;
        global: Record<string, string> | null;
    };
};

type RecentCommand = {
    id: number;
    command_word: string;
    raw_command: string;
    serial_number: string;
    status: 'queued' | 'sent' | 'acked' | 'failed' | 'expired';
    created_at: string | null;
    sent_at: string | null;
    acked_at: string | null;
    expires_at: string | null;
    failed_reason: string | null;
};

type Props = {
    listener: {
        port: number;
        public_hostname: string;
        service_state: string;
        connected_count: number;
    };
    devices: {
        paired: Device[];
        pending: Device[];
        rejected: Device[];
        total: number;
    };
    statistics: {
        frames_last_hour: number;
        last_frame_at: string | null;
    };
    imsCloud: {
        status: 'connected' | 'disconnected' | 'error';
        secret_last4?: string | null;
        last_tested_at?: string | null;
    } | null;
    targets: {
        vehicles: Target[];
        staff: Target[];
        clients: Target[];
    };
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

function framesUrl(imeiFilter: string): string {
    const params = new URLSearchParams();
    if (imeiFilter) params.set('imei', imeiFilter);

    return `/security-devices/integrations/queclink/frames${params.toString() ? '?' + params.toString() : ''}`;
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
    imsCloud,
    targets,
    can,
}: Props) {
    const [activeTab, setActiveTab] = useState<string>(
        devices.pending.length > 0 ? 'pending' : 'overview',
    );

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Queclink Integration" />
            <PageShell>
                <div
                    data-testid="queclink-page-shell"
                    className="w-full space-y-6"
                >
                    {/* ── Hero Header ──────────────────────────────── */}
                    <div
                        data-testid="queclink-hero"
                        className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 p-6 text-primary-foreground md:p-8"
                    >
                        <div className="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
                        <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-primary-foreground/5" />
                        <div className="pointer-events-none absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-primary-foreground/5" />

                        <div className="relative flex flex-col items-center gap-6 md:flex-row md:items-start">
                            <div className="flex h-24 w-24 shrink-0 items-center justify-center rounded-full border-4 border-primary-foreground/20 bg-primary-foreground/10 shadow-xl md:h-28 md:w-28">
                                <Satellite className="h-12 w-12 text-primary-foreground md:h-14 md:w-14" />
                            </div>

                            <div className="min-w-0 flex-1 text-center md:text-left">
                                <h1 className="text-2xl font-bold md:text-3xl">
                                    Queclink Integration
                                </h1>
                                <p className="mt-1 flex flex-wrap items-center justify-center gap-1.5 text-sm text-primary-foreground/65 md:justify-start">
                                    <RadioTower className="h-3.5 w-3.5" />
                                    Direct device-to-server TCP intake for
                                    vehicle, lone-worker, and client
                                    safeguarding trackers.
                                </p>

                                <div className="mt-3 flex flex-wrap items-center justify-center gap-2 md:justify-start">
                                    <Badge className="border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90">
                                        <Server className="mr-1 h-3 w-3" />
                                        Direct TCP listener
                                    </Badge>
                                    <Badge className="border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90">
                                        Port {listener.port}
                                    </Badge>
                                    <Badge
                                        className={
                                            listener.service_state === 'active'
                                                ? 'border-status-success/30 bg-status-success-bg text-status-success'
                                                : listener.service_state ===
                                                    'not_applicable'
                                                  ? 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90'
                                                  : 'border-status-warning/30 bg-status-warning-bg text-status-warning'
                                        }
                                    >
                                        {listener.service_state === 'active' ? (
                                            <CheckCircle className="mr-1 h-3 w-3" />
                                        ) : (
                                            <XCircle className="mr-1 h-3 w-3" />
                                        )}
                                        {listener.service_state ===
                                        'not_applicable'
                                            ? 'Manual listener mode'
                                            : `Listener ${listener.service_state.replaceAll('_', ' ')}`}
                                    </Badge>
                                </div>
                            </div>

                            <div className="flex flex-col items-center gap-3 md:items-end">
                                <div className="grid grid-cols-2 gap-4 text-center sm:flex sm:gap-6">
                                    <HeroStat
                                        label="Paired"
                                        value={devices.paired.length}
                                    />
                                    <HeroStat
                                        label="Pending"
                                        value={devices.pending.length}
                                    />
                                    <HeroStat
                                        label="Connected"
                                        value={listener.connected_count}
                                    />
                                    <HeroStat
                                        label="Frames/hr"
                                        value={statistics.frames_last_hour}
                                    />
                                </div>
                            </div>
                        </div>
                    </div>

                    {/* ── Tabs ────────────────────────────────────── */}
                    <Tabs
                        value={activeTab}
                        onValueChange={setActiveTab}
                        className="space-y-4"
                    >
                        <div className="relative">
                            <TabsList
                                data-testid="queclink-tab-list"
                                className="scrollbar-pretty flex h-auto w-full justify-start gap-1 overflow-x-auto rounded-none border-b bg-transparent p-0 pb-1"
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
                                    {devices.pending.length > 0 && (
                                        <Badge
                                            variant="outline"
                                            className="ml-1 px-1.5 py-0 text-xs"
                                        >
                                            {devices.pending.length}
                                        </Badge>
                                    )}
                                </TabsTrigger>
                                <TabsTrigger
                                    value="devices"
                                    className={hubTabClassName}
                                >
                                    <Smartphone className="h-4 w-4" />
                                    Devices ({devices.paired.length})
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
                                    IMS cloud
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
                                targets={targets}
                                can={can}
                            />
                        </TabsContent>

                        <TabsContent value="devices" className="space-y-6 pt-6">
                            <DevicesTab paired={devices.paired} can={can} />
                        </TabsContent>

                        <TabsContent
                            value="settings"
                            className="space-y-6 pt-6"
                        >
                            <DeviceSettingsTab
                                devices={devices.paired}
                                listener={listener}
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
                            <ImsCloudTab imsCloud={imsCloud} can={can} />
                        </TabsContent>
                    </Tabs>
                </div>
            </PageShell>
        </AppLayout>
    );
}

// ── Listener status pill ──────────────────────────────────────────

function HeroStat({ label, value }: { label: string; value: number | string }) {
    return (
        <div>
            <p className="text-2xl font-bold">{value}</p>
            <p className="text-xs text-primary-foreground/60">{label}</p>
        </div>
    );
}

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

function OverviewTab({
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
        public_hostname: listener.public_hostname,
    });
    const [provisioning, setProvisioning] = useState<{
        config_string: string;
        instructions: string[];
    } | null>(null);
    const [family, setFamily] = useState<'gv500cg' | 'gl30m'>('gv500cg');
    const [provisioningLoading, setProvisioningLoading] = useState(false);

    const portChanged = settings.data.port !== listener.port;

    function generateProvisioning() {
        setProvisioningLoading(true);
        fetch(
            `/security-devices/integrations/queclink/provisioning?family=${family}`,
        )
            .then((r) => r.json())
            .then((d) => {
                if (d.error) {
                    alert(d.error);
                    setProvisioning(null);
                } else {
                    setProvisioning(d);
                }
            })
            .finally(() => setProvisioningLoading(false));
    }

    return (
        <>
            <div className="grid gap-4 md:grid-cols-4">
                <StatCard
                    label="Paired devices"
                    value={devices.paired.length}
                />
                <StatCard
                    label="Pending"
                    value={devices.pending.length}
                    tone={devices.pending.length > 0 ? 'warning' : undefined}
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
                                Default: 8090. Devices must be configured with{' '}
                                <code className="rounded bg-muted px-1 py-0.5">
                                    AT+GTSRI
                                </code>{' '}
                                to point at this port.
                            </p>
                            {portChanged && devices.paired.length > 0 && (
                                <p className="flex items-start gap-1 rounded-md border border-status-warning/30 bg-status-warning-bg p-2 text-xs text-status-warning">
                                    <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                                    <span>
                                        {devices.paired.length} paired{' '}
                                        {devices.paired.length === 1
                                            ? 'device'
                                            : 'devices'}{' '}
                                        will need to be reconfigured to dial
                                        port {settings.data.port} before they
                                        reconnect.
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
                                The address devices dial into. Used in the
                                provisioning string generator below.
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
                    <CardTitle>Provisioning string</CardTitle>
                    <CardDescription>
                        Generate the{' '}
                        <code className="rounded bg-muted px-1 py-0.5">
                            AT+GTSRI
                        </code>{' '}
                        command to paste into the @Track MT Setup tool when
                        configuring a new device.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="flex items-end gap-3">
                        <div className="space-y-2">
                            <Label>Device family</Label>
                            <Select
                                value={family}
                                onValueChange={(v) =>
                                    setFamily(v as 'gv500cg' | 'gl30m')
                                }
                            >
                                <SelectTrigger className="w-48">
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
                            disabled={provisioningLoading}
                        >
                            {provisioningLoading ? (
                                <Loader2 className="mr-2 h-4 w-4 animate-spin" />
                            ) : (
                                <Play className="mr-2 h-4 w-4" />
                            )}
                            Generate
                        </Button>
                    </div>
                    {provisioning && (
                        <div className="space-y-3 rounded-lg border p-4">
                            <div className="flex items-center justify-between gap-2">
                                <code className="block max-w-full overflow-x-auto rounded bg-muted px-2 py-1.5 font-mono text-xs">
                                    {provisioning.config_string}
                                </code>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={() =>
                                        navigator.clipboard.writeText(
                                            provisioning.config_string,
                                        )
                                    }
                                >
                                    <Copy className="h-3 w-3" />
                                </Button>
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
                            program it into the device with{' '}
                            <code className="rounded bg-muted px-1 py-0.5 text-xs">
                                AT+GTBSI=&lt;password&gt;,&lt;APN&gt;,&lt;user&gt;,&lt;pass&gt;,,,,0,,,FFFF$
                            </code>{' '}
                            (factory password is{' '}
                            <code className="rounded bg-muted px-1 py-0.5 text-xs">
                                gv500cg
                            </code>{' '}
                            for GV-series,{' '}
                            <code className="rounded bg-muted px-1 py-0.5 text-xs">
                                gl30
                            </code>{' '}
                            for GL-series).
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
                            is set, then generate the provisioning string with
                            the button below. Paste it into the @Track MT Setup
                            tool's command field and click Send. The device will
                            ACK and reboot.
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
                            filter by the new IMEI. You should see{' '}
                            <code className="rounded bg-muted px-1 py-0.5 text-xs">
                                +RESP:GTHBD
                            </code>{' '}
                            heartbeats every 5 minutes and{' '}
                            <code className="rounded bg-muted px-1 py-0.5 text-xs">
                                +RESP:GTFRI
                            </code>{' '}
                            location reports every 30 seconds (defaults —
                            adjustable per device).
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

function PendingTab({
    pending,
    targets,
    can,
}: {
    pending: Device[];
    targets: Props['targets'];
    can: Props['can'];
}) {
    const [claiming, setClaiming] = useState<Device | null>(null);

    if (pending.length === 0) {
        return (
            <Card>
                <CardContent className="flex flex-col items-center gap-2 p-12 text-center">
                    <Inbox className="h-10 w-10 text-muted-foreground" />
                    <p className="text-sm font-medium">
                        No devices waiting for pairing
                    </p>
                    <p className="max-w-md text-xs text-muted-foreground">
                        When a device dials in for the first time it lands here.
                        Configure it with the provisioning string from the
                        Overview tab.
                    </p>
                </CardContent>
            </Card>
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
                                        {d.imei}
                                    </TableCell>
                                    <TableCell>{d.model_hint ?? '—'}</TableCell>
                                    <TableCell className="text-xs">
                                        {fmtRel(d.first_seen_at)}
                                    </TableCell>
                                    <TableCell className="text-xs">
                                        {fmtRel(d.last_seen_at)}
                                    </TableCell>
                                    <TableCell className="font-mono text-xs text-muted-foreground">
                                        {d.remote_address ?? '—'}
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
                                            onClick={() => {
                                                if (
                                                    confirm(
                                                        `Reject IMEI ${d.imei}? It will be ignored until manually re-allowed.`,
                                                    )
                                                ) {
                                                    router.post(
                                                        `/security-devices/integrations/queclink/devices/${d.id}/reject`,
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    );
                                                }
                                            }}
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

            {claiming && (
                <ClaimDialog
                    device={claiming}
                    targets={targets}
                    onClose={() => setClaiming(null)}
                />
            )}
        </>
    );
}

function ClaimDialog({
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
        pairing_type: 'vehicle',
        target_id: '',
        consent_id: '',
    });

    const availableTargets = useMemo(() => {
        return form.data.pairing_type === 'vehicle'
            ? targets.vehicles
            : form.data.pairing_type === 'staff'
              ? targets.staff
              : targets.clients;
    }, [form.data.pairing_type, targets]);

    return (
        <Dialog open onOpenChange={onClose}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Claim device</DialogTitle>
                    <DialogDescription>
                        IMEI{' '}
                        <code className="rounded bg-muted px-1 py-0.5 text-xs">
                            {device.imei}
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
                    <div className="space-y-2">
                        <Label>What is this device?</Label>
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
                            <SelectTrigger>
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
                    </div>

                    <div className="space-y-2">
                        <Label>
                            {form.data.pairing_type === 'vehicle'
                                ? 'Vehicle'
                                : form.data.pairing_type === 'staff'
                                  ? 'Staff member'
                                  : 'Client'}
                        </Label>
                        <Select
                            value={form.data.target_id}
                            onValueChange={(v) => form.setData('target_id', v)}
                        >
                            <SelectTrigger>
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
                    </div>

                    {form.data.pairing_type === 'client' && (
                        <div className="space-y-2">
                            <Label>Consent record ID (optional)</Label>
                            <Input
                                type="number"
                                value={form.data.consent_id}
                                onChange={(e) =>
                                    form.setData('consent_id', e.target.value)
                                }
                                placeholder="e.g. 42"
                            />
                            <p className="text-xs text-muted-foreground">
                                Location data is consent-gated. Without a valid
                                consent record, the device will connect but
                                lat/lng will not be stored.
                            </p>
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

function DevicesTab({ paired, can }: { paired: Device[]; can: Props['can'] }) {
    if (paired.length === 0) {
        return (
            <Card>
                <CardContent className="flex flex-col items-center gap-2 p-12 text-center">
                    <Satellite className="h-10 w-10 text-muted-foreground" />
                    <p className="text-sm font-medium">No devices paired yet</p>
                    <p className="text-xs text-muted-foreground">
                        Pair a device from the Pending tab to see it here.
                    </p>
                </CardContent>
            </Card>
        );
    }

    return (
        <Card>
            <CardHeader>
                <CardTitle>Paired devices</CardTitle>
                <CardDescription>
                    Devices actively reporting to Oblivion. Releasing a device
                    returns it to the pending tray.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <Table>
                    <TableHeader>
                        <TableRow>
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
                                <TableCell className="font-mono text-xs">
                                    {d.imei}
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
                                        <Badge variant="outline">offline</Badge>
                                    )}
                                </TableCell>
                                <TableCell className="text-right">
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        disabled={!can.manage}
                                        onClick={() => {
                                            if (
                                                confirm(
                                                    `Release ${d.imei}? It will return to the pending tray and stop receiving commands.`,
                                                )
                                            ) {
                                                router.post(
                                                    `/security-devices/integrations/queclink/devices/${d.id}/release`,
                                                    {},
                                                    { preserveScroll: true },
                                                );
                                            }
                                        }}
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
    const current = device?.configuration?.summary.server ?? {};
    const host = str(
        current.main_host,
        listener.public_hostname || 'oblivionfindings.com',
    );
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
    const current = device?.configuration?.summary.global ?? {};

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

function commandStatusBadge(status: RecentCommand['status']) {
    const classes = {
        queued: 'bg-status-info-bg text-status-info',
        sent: 'bg-status-warning-bg text-status-warning',
        acked: 'bg-status-success-bg text-status-success',
        failed: 'bg-status-critical-bg text-status-critical',
        expired: 'bg-muted text-muted-foreground',
    };

    return <Badge className={classes[status]}>{status}</Badge>;
}

export function DeviceSettingsTab({
    devices,
    listener,
    can,
}: {
    devices: Device[];
    listener: Props['listener'];
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

    const post = (path: string, payload: Record<string, string>) => {
        router.post(path, payload, { preserveScroll: true });
    };

    const config = target?.configuration;
    const recentCommands = target?.recent_commands ?? [];

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
                                    Live GL30 configuration, queued updates, and
                                    readback status.
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
                                            {device.imei}
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
                                    config?.available
                                        ? fmtRel(config.received_at)
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
                                    Queue server settings
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
                                    Queue global settings
                                </Button>
                            </CardContent>
                        </Card>
                    </div>

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
                            <Textarea
                                readOnly
                                value={
                                    config?.available
                                        ? config.raw
                                        : 'No configuration readback has been received yet.'
                                }
                                className="min-h-40 font-mono text-xs"
                            />
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
                                <CardTitle>Command status</CardTitle>
                                <CardDescription>
                                    Recent queued AT commands for the selected
                                    device.
                                </CardDescription>
                            </div>
                        </div>
                    </CardHeader>
                    <CardContent>
                        {recentCommands.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No commands queued yet.
                            </p>
                        ) : (
                            <div className="max-h-[680px] space-y-3 overflow-y-auto pr-1">
                                {recentCommands.map((command) => (
                                    <div
                                        key={command.id}
                                        className="rounded-lg border bg-background p-3 shadow-xs"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <span className="font-medium">
                                                {command.command_word}
                                            </span>
                                            {commandStatusBadge(command.status)}
                                        </div>
                                        <p className="mt-1 font-mono text-xs break-all text-muted-foreground">
                                            {command.raw_command}
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
                                        </dl>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
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
        <div className="rounded-lg border bg-background p-3 shadow-xs">
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
        </div>
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

// ── Debug Console tab ─────────────────────────────────────────────

export function DebugConsoleTab({
    devices,
    can,
}: {
    devices: Device[];
    can: Props['can'];
}) {
    const [frames, setFrames] = useState<Frame[]>([]);
    const [imeiFilter, setImeiFilter] = useState<string>('');
    const [streaming, setStreaming] = useState(true);
    const esRef = useRef<EventSource | null>(null);
    const containerRef = useRef<HTMLDivElement>(null);
    const [autoscroll, setAutoscroll] = useState(true);
    const loadRecentFrames = useCallback(
        async (signal?: AbortSignal) => {
            let response: Response;

            try {
                response = await fetch(framesUrl(imeiFilter), {
                    headers: { Accept: 'application/json' },
                    signal,
                });
            } catch {
                return;
            }

            if (!response.ok) return;
            const payload = (await response.json()) as { frames?: Frame[] };
            setFrames((prev) => mergeFrames(prev, payload.frames ?? []));
        },
        [imeiFilter],
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
            return;
        }
        const params = new URLSearchParams();
        if (imeiFilter) params.set('imei', imeiFilter);
        const url = `/security-devices/integrations/queclink/stream${params.toString() ? '?' + params.toString() : ''}`;
        const es = new EventSource(url);
        es.onmessage = (e) => {
            try {
                const frame = JSON.parse(e.data) as Frame;
                setFrames((prev) => mergeFrames(prev, [frame]));
            } catch {
                /* heartbeat or malformed line — ignore */
            }
        };
        es.onerror = () => {
            /* browser will auto-reconnect via the retry: header */
        };
        esRef.current = es;
        return () => {
            es.close();
            esRef.current = null;
        };
    }, [streaming, imeiFilter]);

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

    return (
        <div className="grid gap-6 lg:grid-cols-3">
            {/* Live frame stream */}
            <Card className="lg:col-span-2">
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
                            <Select
                                value={imeiFilter || 'all'}
                                onValueChange={(v) =>
                                    setImeiFilter(v === 'all' ? '' : v)
                                }
                            >
                                <SelectTrigger className="w-48">
                                    <SelectValue placeholder="All devices" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="all">
                                        All devices
                                    </SelectItem>
                                    {devices.map((d) => (
                                        <SelectItem key={d.id} value={d.imei}>
                                            {d.imei}
                                            {d.assignment?.label
                                                ? ` — ${d.assignment.label}`
                                                : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <Button
                                size="sm"
                                variant={streaming ? 'default' : 'outline'}
                                onClick={() => setStreaming((s) => !s)}
                            >
                                {streaming ? (
                                    <>
                                        <RefreshCw className="mr-2 h-3 w-3 animate-spin" />
                                        Live
                                    </>
                                ) : (
                                    'Resume'
                                )}
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
                <CardContent>
                    <div
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
                                Waiting for frames…
                            </div>
                        ) : (
                            <div className="divide-y">
                                {frames.map((f) => (
                                    <FrameLine key={f.id} frame={f} />
                                ))}
                            </div>
                        )}
                    </div>
                </CardContent>
            </Card>

            {/* AT Command REPL */}
            <CommandRepl devices={devices} can={can} />
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
            <div className="w-28 shrink-0 truncate text-[10px] text-muted-foreground">
                {frame.imei ?? '—'}
            </div>
            <div className="flex-1 break-all">
                <code className={frame.parse_ok ? '' : 'text-status-critical'}>
                    {frame.raw_frame}
                </code>
                {frame.parse_error && (
                    <p className="mt-0.5 text-[10px] text-status-critical">
                        {frame.parse_error}
                    </p>
                )}
            </div>
        </div>
    );
}

function CommandRepl({
    devices,
    can,
}: {
    devices: Device[];
    can: Props['can'];
}) {
    const [target, setTarget] = useState<Device | null>(devices[0] ?? null);
    const [mode, setMode] = useState<'preset' | 'raw'>('preset');
    const presetForm = useForm<{
        mode: 'preset';
        preset: 'request_location' | 'reboot' | 'set_interval';
        interval_seconds: number;
    }>({
        mode: 'preset',
        preset: 'request_location',
        interval_seconds: 60,
    });
    const rawForm = useForm<{ mode: 'raw'; raw: string }>({
        mode: 'raw',
        raw: '',
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle>Send command</CardTitle>
                <CardDescription>
                    Queue an{' '}
                    <code className="rounded bg-muted px-1 py-0.5 text-xs">
                        AT+GTXXX
                    </code>{' '}
                    command — it sends on the device's next frame and expires
                    after 5 minutes if unsent.
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
                                    {d.imei}
                                    {d.assignment?.label
                                        ? ` — ${d.assignment.label}`
                                        : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>

                <div className="space-y-2">
                    <Label>Mode</Label>
                    <Select
                        value={mode}
                        onValueChange={(v) => setMode(v as 'preset' | 'raw')}
                    >
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="preset">
                                Preset (one-click commands)
                            </SelectItem>
                            <SelectItem value="raw">
                                Raw AT+ command (advanced)
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <Separator />

                {mode === 'preset' ? (
                    <div className="space-y-3">
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full justify-start"
                            disabled={
                                !can.manage || !target || presetForm.processing
                            }
                            onClick={() => {
                                presetForm.setData(
                                    'preset',
                                    'request_location',
                                );
                                presetForm.transform(() => ({
                                    mode: 'preset',
                                    preset: 'request_location',
                                }));
                                presetForm.post(
                                    `/security-devices/integrations/queclink/devices/${target!.id}/command`,
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <Send className="mr-2 h-3 w-3" /> Request current
                            location
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className="w-full justify-start"
                            disabled={
                                !can.manage || !target || presetForm.processing
                            }
                            onClick={() => {
                                if (
                                    !confirm(
                                        'Reboot the device? It will be offline for ~60 seconds.',
                                    )
                                )
                                    return;
                                presetForm.transform(() => ({
                                    mode: 'preset',
                                    preset: 'reboot',
                                }));
                                presetForm.post(
                                    `/security-devices/integrations/queclink/devices/${target!.id}/command`,
                                    { preserveScroll: true },
                                );
                            }}
                        >
                            <Send className="mr-2 h-3 w-3" /> Reboot device
                        </Button>
                        <div className="space-y-2 rounded-md border p-3">
                            <Label className="text-xs">
                                Set reporting interval (seconds)
                            </Label>
                            <div className="flex gap-2">
                                <Input
                                    type="number"
                                    min={5}
                                    max={86400}
                                    value={presetForm.data.interval_seconds}
                                    onChange={(e) =>
                                        presetForm.setData(
                                            'interval_seconds',
                                            parseInt(
                                                e.target.value || '60',
                                                10,
                                            ),
                                        )
                                    }
                                />
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        !can.manage ||
                                        !target ||
                                        presetForm.processing
                                    }
                                    onClick={() => {
                                        presetForm.transform(() => ({
                                            mode: 'preset',
                                            preset: 'set_interval',
                                            interval_seconds:
                                                presetForm.data
                                                    .interval_seconds,
                                        }));
                                        presetForm.post(
                                            `/security-devices/integrations/queclink/devices/${target!.id}/command`,
                                            { preserveScroll: true },
                                        );
                                    }}
                                >
                                    Set
                                </Button>
                            </div>
                        </div>
                    </div>
                ) : (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            if (!target) return;
                            rawForm.post(
                                `/security-devices/integrations/queclink/devices/${target.id}/command`,
                                {
                                    preserveScroll: true,
                                    onSuccess: () => rawForm.reset('raw'),
                                },
                            );
                        }}
                        className="space-y-2"
                    >
                        <Label className="text-xs">Raw command</Label>
                        <Textarea
                            value={rawForm.data.raw}
                            onChange={(e) =>
                                rawForm.setData('raw', e.target.value)
                            }
                            placeholder="AT+GTRTO=gv500cg,1,,,,,$"
                            className="font-mono text-xs"
                            rows={3}
                        />
                        {rawForm.errors.raw && (
                            <p className="text-xs text-status-critical">
                                {rawForm.errors.raw}
                            </p>
                        )}
                        <Button
                            type="submit"
                            disabled={
                                !can.manage ||
                                !target ||
                                rawForm.processing ||
                                !rawForm.data.raw
                            }
                            className="w-full"
                        >
                            <Send className="mr-2 h-3 w-3" /> Queue command
                        </Button>
                        <p className="text-xs text-muted-foreground">
                            Append{' '}
                            <code className="rounded bg-muted px-1 py-0.5">
                                $
                            </code>{' '}
                            if missing. A 4-hex-char serial is appended
                            automatically if not provided.
                        </p>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}

// ── IMS Cloud tab (preserves existing scaffold behaviour) ─────────

function ImsCloudTab({
    imsCloud,
    can,
}: {
    imsCloud: Props['imsCloud'];
    can: Props['can'];
}) {
    const form = useForm<{ api_key: string; base_url: string }>({
        api_key: '',
        base_url: '',
    });

    return (
        <Card>
            <CardHeader>
                <CardTitle>Queclink IMS cloud (optional)</CardTitle>
                <CardDescription>
                    Direct TCP intake is the primary path. IMS credentials are
                    kept here for parity — useful when you need device-fleet
                    sync from Queclink's cloud account in addition to live
                    telemetry.
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                {imsCloud ? (
                    <div className="flex flex-wrap items-center gap-3 text-sm">
                        <span>
                            Key ending in{' '}
                            <code className="rounded bg-muted px-1.5 py-0.5 text-xs">
                                •••{imsCloud.secret_last4}
                            </code>
                        </span>
                        <Badge>{imsCloud.status}</Badge>
                        {imsCloud.last_tested_at && (
                            <span className="text-xs text-muted-foreground">
                                Last tested {fmt(imsCloud.last_tested_at)}
                            </span>
                        )}
                    </div>
                ) : (
                    <form
                        onSubmit={(e) => {
                            e.preventDefault();
                            form.post(
                                '/security-devices/integrations/queclink/key',
                                {
                                    preserveScroll: true,
                                    onSuccess: () => form.reset('api_key'),
                                },
                            );
                        }}
                        className="space-y-3"
                    >
                        <div className="space-y-2">
                            <Label>Base URL (optional)</Label>
                            <Input
                                type="url"
                                value={form.data.base_url}
                                onChange={(e) =>
                                    form.setData('base_url', e.target.value)
                                }
                                placeholder="https://ims.queclink.com"
                            />
                        </div>
                        <div className="space-y-2">
                            <Label>API key</Label>
                            <Input
                                type="password"
                                value={form.data.api_key}
                                onChange={(e) =>
                                    form.setData('api_key', e.target.value)
                                }
                                required
                            />
                        </div>
                        <Button
                            type="submit"
                            disabled={
                                !can.manage ||
                                form.processing ||
                                !form.data.api_key
                            }
                        >
                            Save IMS credentials
                        </Button>
                    </form>
                )}
            </CardContent>
        </Card>
    );
}
