import LeafletMap from '@/components/leaflet-map';
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
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import axios from 'axios';
import {
    ArrowDown,
    ArrowLeft,
    ArrowRight,
    ArrowUp,
    BatteryCharging,
    Camera,
    Cctv,
    Cpu,
    DoorOpen,
    ExternalLink,
    Gauge,
    GitBranch,
    Home,
    KeyRound,
    Laptop,
    Loader2,
    Lock,
    MapPin,
    Network,
    Satellite,
    Settings2,
    ShieldAlert,
    ShieldCheck,
    Siren,
    Square,
    Thermometer,
    Unlock,
    ZoomIn,
    ZoomOut,
} from 'lucide-react';
import { useState } from 'react';
import type {
    DeviceGovernedQuickAction,
    DeviceNetworkNeighbourItem,
    DeviceProfile,
    DeviceProfileSectionKey,
    DeviceTypeProfile,
} from './device-profile';
import { deviceCategoryIcon } from './device-profile-header';

function humanise(value: string | null | undefined): string {
    if (!value) return 'Not recorded';

    const spaced = value.replace(/_/g, ' ').trim().toLowerCase();
    if (!spaced) return 'Not recorded';

    return spaced.charAt(0).toUpperCase() + spaced.slice(1);
}

function statusVariantForState(
    state: string | null | undefined,
): StatusVariant {
    switch (state) {
        case 'healthy':
        case 'active':
        case 'fresh':
        case 'verified':
        case 'locked':
        case 'closed':
        case 'armed':
        case 'good':
        case 'available':
        case 'normal':
        case 'aligned':
        case 'up':
            return 'success';
        case 'warning':
        case 'degraded':
        case 'stale':
        case 'low':
        case 'unlocked':
        case 'open':
        case 'bypassed':
        case 'configured_unverified':
        case 'pending_enrolment':
        case 'enrolled_awaiting_heartbeat':
        case 'consent_required':
        case 'collection_paused':
            return 'warning';
        case 'critical':
        case 'failed':
        case 'offline':
        case 'triggered':
        case 'fault':
        case 'tamper':
        case 'forced':
        case 'revoked':
        case 'down':
            return 'critical';
        case 'disarmed':
        case 'observed':
        case 'info':
            return 'info';
        default:
            return 'neutral';
    }
}

function protocolBadgeLabel(protocol: string): string {
    switch (protocol) {
        case 'lldp':
            return 'LLDP';
        case 'cdp':
            return 'CDP';
        case 'unifi':
            return 'UniFi';
        case 'bridge':
            return 'Switch port';
        case 'arp':
            return 'ARP gateway';
        case 'lan_subnet':
            return 'LAN subnet';
        case 'manual':
            return 'Linked';
        default:
            return humanise(protocol);
    }
}

export function DeviceTypeOverview({
    profile,
    deviceId,
    deviceName,
    canSetupAgent,
    onOpenSection,
    onOpenInstallation,
}: {
    profile: DeviceProfile;
    deviceId: number;
    deviceName: string;
    canSetupAgent: boolean;
    onOpenSection: (section: DeviceProfileSectionKey) => void;
    onOpenInstallation?: () => void;
}) {
    const typeProfile = profile.typeProfile;
    if (!typeProfile) return null;

    switch (typeProfile.kind) {
        case 'network':
            return (
                <NetworkProfileOverview
                    profile={profile}
                    typeProfile={typeProfile}
                    onOpenSection={onOpenSection}
                />
            );
        case 'camera':
            return (
                <CameraProfileOverview
                    typeProfile={typeProfile}
                    deviceName={deviceName}
                />
            );
        case 'alarm_panel':
            return (
                <AlarmPanelProfileOverview
                    profile={profile}
                    typeProfile={typeProfile}
                    deviceId={deviceId}
                    deviceName={deviceName}
                    onOpenSection={onOpenSection}
                />
            );
        case 'door':
            return (
                <DoorProfileOverview
                    profile={profile}
                    typeProfile={typeProfile}
                    deviceId={deviceId}
                    deviceName={deviceName}
                    onOpenSection={onOpenSection}
                />
            );
        case 'sensor':
            return (
                <SensorProfileOverview
                    typeProfile={typeProfile}
                    onOpenSection={onOpenSection}
                />
            );
        case 'tracker':
            return (
                <TrackerProfileOverview
                    typeProfile={typeProfile}
                    deviceName={deviceName}
                    onOpenSection={onOpenSection}
                />
            );
        case 'computer':
            return (
                <ComputerProfileOverview
                    typeProfile={typeProfile}
                    canSetupAgent={canSetupAgent}
                    onOpenSection={onOpenSection}
                    onOpenInstallation={onOpenInstallation}
                />
            );
        case 'other':
        default:
            return <OtherProfileOverview typeProfile={typeProfile} />;
    }
}

// ── 1. Network layout ──────────────────────────────────────────────

function NetworkNeighbourList({
    title,
    items,
    emptyText,
}: {
    title: string;
    items: DeviceNetworkNeighbourItem[];
    emptyText: string;
}) {
    return (
        <div className="space-y-2">
            <p className="text-caption font-semibold tracking-wide uppercase">
                {title} ({items.length})
            </p>
            {items.length === 0 ? (
                <p className="text-caption rounded-lg bg-muted/30 p-3">
                    {emptyText}
                </p>
            ) : (
                <div className="space-y-2">
                    {items.map((item) => {
                        const Icon = deviceCategoryIcon(item.category);
                        return (
                            <div
                                key={`${title}-${item.id}`}
                                className="flex flex-wrap items-center justify-between gap-2 rounded-lg border p-2.5"
                            >
                                <div className="flex min-w-0 items-center gap-2.5">
                                    <Icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                                    <div className="min-w-0">
                                        <Link
                                            href={item.href}
                                            className="truncate text-sm font-medium text-primary underline-offset-2 hover:underline"
                                        >
                                            {item.name}
                                        </Link>
                                        <p className="text-caption">
                                            {[item.ip_address, item.port]
                                                .filter(Boolean)
                                                .join(' · ') ||
                                                'Port not reported'}
                                        </p>
                                    </div>
                                </div>
                                <div className="flex items-center gap-1.5">
                                    <StatusBadge variant="neutral">
                                        {protocolBadgeLabel(item.protocol)}
                                    </StatusBadge>
                                    <StatusBadge
                                        variant={statusVariantForState(
                                            item.status,
                                        )}
                                    >
                                        {humanise(item.status)}
                                    </StatusBadge>
                                </div>
                            </div>
                        );
                    })}
                </div>
            )}
        </div>
    );
}

function NetworkProfileOverview({
    profile,
    typeProfile,
    onOpenSection,
}: {
    profile: DeviceProfile;
    typeProfile: Extract<DeviceTypeProfile, { kind: 'network' }>;
    onOpenSection: (section: DeviceProfileSectionKey) => void;
}) {
    const previewInterfaces = profile.interfacesSensors.slice(0, 4);

    return (
        <div
            data-testid="device-layout-network"
            className="grid gap-4 lg:grid-cols-2"
        >
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <GitBranch className="h-4 w-4" />
                                Network neighbours
                            </CardTitle>
                            <CardDescription>
                                Upstream and downstream devices discovered from
                                LLDP, CDP, switch ports, UniFi, or manual links.
                            </CardDescription>
                        </div>
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => onOpenSection('topology')}
                        >
                            Open topology
                        </Button>
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    <NetworkNeighbourList
                        title="Upstream uplink"
                        items={typeProfile.neighbours.upstream}
                        emptyText="No upstream gateway or switch reported yet."
                    />
                    <NetworkNeighbourList
                        title="Downstream connected devices"
                        items={typeProfile.neighbours.downstream}
                        emptyText="No downstream switches, access points, or devices reported yet."
                    />
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Network className="h-4 w-4" />
                                Interfaces &amp; configuration
                            </CardTitle>
                            <CardDescription>
                                {[
                                    typeProfile.ipAddress
                                        ? `IP ${typeProfile.ipAddress}`
                                        : null,
                                    typeProfile.macAddress
                                        ? `MAC ${typeProfile.macAddress}`
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' · ') ||
                                    'Network identity and saved configuration state.'}
                            </CardDescription>
                        </div>
                        <div className="flex flex-wrap gap-1.5">
                            {profile.sections.some(
                                (s) => s.key === 'interfaces-sensors',
                            ) && (
                                <Button
                                    type="button"
                                    size="sm"
                                    variant="outline"
                                    onClick={() =>
                                        onOpenSection('interfaces-sensors')
                                    }
                                >
                                    Interfaces ({typeProfile.interfaceCount})
                                </Button>
                            )}
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => onOpenSection('configuration')}
                            >
                                Configuration
                            </Button>
                            {profile.management.actions.some(
                                (action) =>
                                    action.key === 'network.config.backup',
                            ) && (
                                <Button
                                    type="button"
                                    size="sm"
                                    onClick={() => {
                                        if (typeof window !== 'undefined') {
                                            const url = new URL(
                                                window.location.href,
                                            );
                                            url.searchParams.set(
                                                'action',
                                                'network.config.backup',
                                            );
                                            window.history.replaceState(
                                                window.history.state,
                                                '',
                                                url.toString(),
                                            );
                                        }
                                        onOpenSection('management');
                                    }}
                                >
                                    Back up configuration
                                </Button>
                            )}
                        </div>
                    </div>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="grid grid-cols-2 gap-2 text-sm">
                        <div className="rounded-lg bg-muted/30 p-3">
                            <p className="text-caption">Configuration state</p>
                            <p className="mt-1 font-semibold">
                                {humanise(typeProfile.configState)}
                            </p>
                            {typeProfile.latestSnapshotAt && (
                                <p className="text-caption">
                                    Backup{' '}
                                    {formatRelative(
                                        typeProfile.latestSnapshotAt,
                                    )}
                                </p>
                            )}
                        </div>
                        <div className="rounded-lg bg-muted/30 p-3">
                            <p className="text-caption">Firmware</p>
                            <p className="mt-1 font-semibold">
                                {typeProfile.firmwareVersion ?? 'Not recorded'}
                            </p>
                            <p className="text-caption">
                                {typeProfile.interfaceCount}{' '}
                                {typeProfile.interfaceCount === 1
                                    ? 'interface'
                                    : 'interfaces'}{' '}
                                tracked
                            </p>
                        </div>
                    </div>

                    {previewInterfaces.length > 0 ? (
                        <div className="space-y-1.5">
                            <p className="text-caption font-semibold tracking-wide uppercase">
                                Tracked interfaces
                            </p>
                            {previewInterfaces.map((iface) => (
                                <div
                                    key={iface.monitorId}
                                    className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                                >
                                    <span className="truncate font-medium">
                                        {iface.name}
                                    </span>
                                    <StatusBadge
                                        variant={statusVariantForState(
                                            iface.operationalStatus ??
                                                iface.state,
                                        )}
                                    >
                                        {humanise(
                                            iface.operationalStatus ??
                                                iface.state,
                                        )}
                                    </StatusBadge>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <p className="text-caption rounded-lg bg-muted/30 p-3">
                            No SNMP interface checks are configured on this
                            network device yet.
                        </p>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

// ── 2. Camera layout ───────────────────────────────────────────────

type SnapshotPayload = {
    ok: boolean;
    message?: string;
    data_uri?: string | null;
    captured_at?: string | null;
    source?: string | null;
};

function CameraProfileOverview({
    typeProfile,
    deviceName,
}: {
    typeProfile: Extract<DeviceTypeProfile, { kind: 'camera' }>;
    deviceName: string;
}) {
    const [capturing, setCapturing] = useState(false);
    const [snapshot, setSnapshot] = useState<SnapshotPayload | null>(null);
    const [ptzBusy, setPtzBusy] = useState<string | null>(null);
    const [ptzFeedback, setPtzFeedback] = useState<{
        ok: boolean;
        message: string;
    } | null>(null);

    const captureSnapshot = async () => {
        if (!typeProfile.snapshotUrl || capturing) return;
        setCapturing(true);
        try {
            const response = await axios.post<SnapshotPayload>(
                typeProfile.snapshotUrl,
            );
            setSnapshot(response.data);
        } catch (error: unknown) {
            let message =
                'Could not capture an ONVIF snapshot from this camera.';
            if (axios.isAxiosError(error) && error.response?.data) {
                const data = error.response.data as { message?: string };
                if (data.message) message = data.message;
            }
            setSnapshot({ ok: false, message });
        } finally {
            setCapturing(false);
        }
    };

    const sendPtz = async (action: string) => {
        if (!typeProfile.ptzUrl || ptzBusy !== null) return;
        setPtzBusy(action);
        try {
            const response = await axios.post<{ ok: boolean; message: string }>(
                typeProfile.ptzUrl,
                { action },
            );
            setPtzFeedback({
                ok: response.data.ok,
                message: response.data.message,
            });
        } catch (error: unknown) {
            let message = 'PTZ command could not be completed by the camera.';
            if (axios.isAxiosError(error) && error.response?.data) {
                const data = error.response.data as { message?: string };
                if (data.message) message = data.message;
            }
            setPtzFeedback({ ok: false, message });
        } finally {
            setPtzBusy(null);
        }
    };

    const streamLabel =
        typeProfile.streamState === 'verified'
            ? 'Stream verified'
            : typeProfile.streamState === 'configured_unverified'
              ? 'Stream configured (not verified yet)'
              : 'Stream not configured';

    return (
        <div
            data-testid="device-layout-camera"
            className="grid gap-4 lg:grid-cols-2"
        >
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Cctv className="h-4 w-4" />
                                {typeProfile.isRecorder
                                    ? 'Recorder & stream source'
                                    : 'Live view & stream source'}
                            </CardTitle>
                            <CardDescription>
                                RTSP and ONVIF video stream configuration for{' '}
                                {deviceName}.
                            </CardDescription>
                        </div>
                        <StatusBadge
                            variant={statusVariantForState(
                                typeProfile.streamState,
                            )}
                        >
                            {streamLabel}
                        </StatusBadge>
                    </div>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="rounded-lg bg-muted/30 p-3 text-sm">
                        <p className="font-medium">
                            {typeProfile.sourceProvider
                                ? `Source type: ${humanise(typeProfile.sourceProvider)}`
                                : 'No RTSP or ONVIF stream URL saved yet.'}
                        </p>
                        <p className="text-caption mt-1">
                            {typeProfile.streamVerifiedAt
                                ? `Last verified ${formatDateTime(typeProfile.streamVerifiedAt)}.`
                                : 'Connect the camera stream source to watch live video inside Oblivion Findings.'}
                        </p>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {typeProfile.liveHref && (
                            <Button size="sm" asChild>
                                <Link href={typeProfile.liveHref}>
                                    Watch live
                                </Link>
                            </Button>
                        )}
                        {typeProfile.setupHref && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={typeProfile.setupHref}>
                                    Set up stream
                                </Link>
                            </Button>
                        )}
                        {typeProfile.recordingReferencesHref && (
                            <Button size="sm" variant="outline" asChild>
                                <Link
                                    href={typeProfile.recordingReferencesHref}
                                >
                                    Recording references
                                </Link>
                            </Button>
                        )}
                    </div>
                </CardContent>
            </Card>

            {!typeProfile.isRecorder && (
                <Card>
                    <CardHeader className="pb-3">
                        <div className="flex flex-wrap items-start justify-between gap-2">
                            <div className="space-y-1">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <Camera className="h-4 w-4" />
                                    ONVIF snapshot
                                    {typeProfile.ptzSupported ? ' & PTZ' : ''}
                                </CardTitle>
                                <CardDescription>
                                    Capture a live still frame directly from the
                                    camera over ONVIF Media.
                                </CardDescription>
                            </div>
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                disabled={
                                    !typeProfile.snapshotUrl ||
                                    !typeProfile.hasIpAddress ||
                                    capturing
                                }
                                onClick={() => void captureSnapshot()}
                            >
                                {capturing && (
                                    <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                )}
                                Capture snapshot
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {!typeProfile.canViewMedia ? (
                            <p className="text-caption rounded-lg bg-muted/30 p-3">
                                Camera media view permission is required to
                                capture snapshots.
                            </p>
                        ) : !typeProfile.hasIpAddress ? (
                            <p className="text-caption rounded-lg bg-muted/30 p-3">
                                Add an IP address to this camera first to
                                request an ONVIF snapshot.
                            </p>
                        ) : snapshot?.ok && snapshot.data_uri ? (
                            <div className="space-y-2">
                                <img
                                    src={snapshot.data_uri}
                                    alt={`Snapshot from ${deviceName}`}
                                    className="max-h-64 w-full rounded-lg border bg-muted/30 object-contain"
                                />
                                <p className="text-caption">
                                    Captured{' '}
                                    {formatDateTime(snapshot.captured_at)} via{' '}
                                    {snapshot.source ?? 'ONVIF Media'}
                                </p>
                            </div>
                        ) : snapshot && !snapshot.ok ? (
                            <div className="rounded-lg border border-status-warning/30 bg-status-warning-bg p-3 text-sm">
                                <p className="font-medium text-foreground">
                                    Snapshot unavailable
                                </p>
                                <p className="text-caption mt-1">
                                    {snapshot.message}
                                </p>
                            </div>
                        ) : (
                            <p className="text-caption rounded-lg bg-muted/30 p-3">
                                Select Capture snapshot to pull a fresh frame
                                from the camera. Every snapshot request is
                                recorded in the audit log.
                            </p>
                        )}

                        {typeProfile.ptzSupported && (
                            <div className="space-y-2 border-t pt-3">
                                <div className="flex items-center justify-between gap-2">
                                    <p className="text-sm font-semibold">
                                        Pan, tilt &amp; zoom (ONVIF PTZ)
                                    </p>
                                    {!typeProfile.canOperatePtz && (
                                        <StatusBadge variant="neutral">
                                            Operator permission required
                                        </StatusBadge>
                                    )}
                                </div>
                                <div className="flex flex-wrap gap-1.5">
                                    {[
                                        {
                                            key: 'pan_left',
                                            label: 'Left',
                                            icon: ArrowLeft,
                                        },
                                        {
                                            key: 'tilt_up',
                                            label: 'Up',
                                            icon: ArrowUp,
                                        },
                                        {
                                            key: 'tilt_down',
                                            label: 'Down',
                                            icon: ArrowDown,
                                        },
                                        {
                                            key: 'pan_right',
                                            label: 'Right',
                                            icon: ArrowRight,
                                        },
                                        {
                                            key: 'zoom_in',
                                            label: 'Zoom in',
                                            icon: ZoomIn,
                                        },
                                        {
                                            key: 'zoom_out',
                                            label: 'Zoom out',
                                            icon: ZoomOut,
                                        },
                                        {
                                            key: 'home',
                                            label: 'Home',
                                            icon: Home,
                                        },
                                        {
                                            key: 'stop',
                                            label: 'Stop',
                                            icon: Square,
                                        },
                                    ].map((ctrl) => {
                                        const Icon = ctrl.icon;
                                        return (
                                            <Button
                                                key={ctrl.key}
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                disabled={
                                                    !typeProfile.ptzUrl ||
                                                    ptzBusy !== null
                                                }
                                                onClick={() =>
                                                    void sendPtz(ctrl.key)
                                                }
                                            >
                                                <Icon className="mr-1 h-3.5 w-3.5" />
                                                {ctrl.label}
                                            </Button>
                                        );
                                    })}
                                </div>
                                {ptzFeedback && (
                                    <p
                                        className={
                                            ptzFeedback.ok
                                                ? 'text-caption'
                                                : 'text-sm text-status-critical'
                                        }
                                    >
                                        {ptzFeedback.message}
                                    </p>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>
            )}
        </div>
    );
}

// ── 3. Alarm panel layout ──────────────────────────────────────────

function AlarmPanelProfileOverview({
    profile,
    typeProfile,
    deviceId,
    deviceName,
    onOpenSection,
}: {
    profile: DeviceProfile;
    typeProfile: Extract<DeviceTypeProfile, { kind: 'alarm_panel' }>;
    deviceId: number;
    deviceName: string;
    onOpenSection: (section: DeviceProfileSectionKey) => void;
}) {
    const [dialogAction, setDialogAction] =
        useState<DeviceGovernedQuickAction | null>(null);
    const [area, setArea] = useState('main');
    const [reason, setReason] = useState('');
    const [impactAck, setImpactAck] = useState(false);
    const [confirmName, setConfirmName] = useState('');
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const openActionDialog = (action: DeviceGovernedQuickAction) => {
        setDialogAction(action);
        setArea('main');
        setReason('');
        setImpactAck(false);
        setConfirmName('');
        setError(null);
    };

    const submitAlarmCommand = () => {
        if (!dialogAction) return;
        const suffix =
            typeof crypto !== 'undefined' && crypto.randomUUID
                ? crypto.randomUUID()
                : `${Date.now()}`;
        setSubmitting(true);
        setError(null);
        router.post(
            typeProfile.commandStoreUrl,
            {
                ...(profile.management.actorId
                    ? { expected_actor_id: profile.management.actorId }
                    : {}),
                capability: dialogAction.key,
                parameters: { area: area.trim() || 'main' },
                reason: reason.trim(),
                idempotency_key: `command_${deviceId}_${suffix}`,
                impact_acknowledged: impactAck,
                ...(dialogAction.confirmationMode === 'type_device_name'
                    ? { confirmation_text: confirmName }
                    : {}),
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setDialogAction(null);
                },
                onError: (errors) => {
                    setError(
                        Object.values(errors)[0] ??
                            'The alarm request could not be created.',
                    );
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const panelStateLabel =
        typeProfile.panelState === 'not_reported'
            ? 'No state reported yet'
            : humanise(typeProfile.panelState);

    return (
        <div
            data-testid="device-layout-alarm-panel"
            className="grid gap-4 lg:grid-cols-2"
        >
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Siren className="h-4 w-4" />
                                Alarm panel state &amp; controls
                            </CardTitle>
                            <CardDescription>
                                Arming and disarming always require permission,
                                confirmation, an operational reason, and an
                                audit record.
                            </CardDescription>
                        </div>
                        <StatusBadge
                            variant={statusVariantForState(
                                typeProfile.panelState,
                            )}
                        >
                            {panelStateLabel}
                        </StatusBadge>
                    </div>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="rounded-lg bg-muted/30 p-3 text-sm">
                        <p className="font-medium">
                            Current state: {panelStateLabel}
                        </p>
                        <p className="text-caption mt-1">
                            {typeProfile.panelStateAt
                                ? `Last reported ${formatDateTime(typeProfile.panelStateAt)}.`
                                : 'No arm, disarm, or alarm event has been received from this panel yet.'}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={!typeProfile.armAction?.available}
                            onClick={() =>
                                typeProfile.armAction &&
                                openActionDialog(typeProfile.armAction)
                            }
                        >
                            <Lock className="mr-1.5 h-3.5 w-3.5" />
                            Arm alarm
                        </Button>
                        <Button
                            type="button"
                            size="sm"
                            variant="destructive"
                            disabled={!typeProfile.disarmAction?.available}
                            onClick={() =>
                                typeProfile.disarmAction &&
                                openActionDialog(typeProfile.disarmAction)
                            }
                        >
                            <Unlock className="mr-1.5 h-3.5 w-3.5" />
                            Disarm alarm
                        </Button>
                        {profile.management.visible && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => onOpenSection('management')}
                            >
                                Open Management
                            </Button>
                        )}
                    </div>

                    {(!typeProfile.armAction?.available ||
                        !typeProfile.disarmAction?.available) && (
                        <p className="text-caption rounded-lg bg-muted/30 p-3">
                            {typeProfile.disarmAction?.reason ??
                                typeProfile.armAction?.reason ??
                                'Alarm control requires security control permission, recent panel state, and an active alarm adapter.'}
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="pb-3">
                    <CardTitle className="flex items-center gap-2 text-base">
                        <ShieldAlert className="h-4 w-4" />
                        Alarm zones ({typeProfile.zones.length})
                    </CardTitle>
                    <CardDescription>
                        Zone status reported by the alarm panel.
                    </CardDescription>
                </CardHeader>
                <CardContent>
                    {typeProfile.zones.length === 0 ? (
                        <EmptyState
                            icon={ShieldAlert}
                            title="No zones reported yet"
                            description="Zones appear here automatically once the alarm panel reports zone events."
                            variant="compact"
                        />
                    ) : (
                        <div className="space-y-2">
                            {typeProfile.zones.map((zone) => (
                                <div
                                    key={zone.zone}
                                    className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                                >
                                    <div>
                                        <p className="font-medium">
                                            {zone.label}
                                        </p>
                                        <p className="text-caption">
                                            Zone {zone.zone}
                                            {zone.observedAt
                                                ? ` · ${formatRelative(zone.observedAt)}`
                                                : ''}
                                        </p>
                                    </div>
                                    <StatusBadge
                                        variant={statusVariantForState(
                                            zone.state,
                                        )}
                                    >
                                        {humanise(zone.state)}
                                    </StatusBadge>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Dialog
                open={dialogAction !== null}
                onOpenChange={(open) => !open && setDialogAction(null)}
            >
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 540px)',
                        width: 'min(92vw, 540px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {dialogAction?.label ?? 'Confirm alarm action'}
                        </DialogTitle>
                        <DialogDescription>
                            Confirm the alarm area, acknowledge the operational
                            impact, and record a permanent audit reason for{' '}
                            {deviceName}.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <label
                                htmlFor="alarm-command-area"
                                className="text-sm font-medium"
                            >
                                Alarm area / partition
                            </label>
                            <Input
                                id="alarm-command-area"
                                maxLength={80}
                                value={area}
                                onChange={(e) => setArea(e.target.value)}
                                placeholder="e.g. main"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <label
                                htmlFor="alarm-command-reason"
                                className="text-sm font-medium"
                            >
                                Operational reason (minimum 10 characters)
                            </label>
                            <textarea
                                id="alarm-command-reason"
                                rows={3}
                                maxLength={1000}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                placeholder="Why is this alarm change required right now?"
                            />
                        </div>
                        <label className="flex items-start gap-2.5 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1 h-4 w-4"
                                checked={impactAck}
                                onChange={(e) => setImpactAck(e.target.checked)}
                            />
                            <span>
                                I understand this request is permanently audited
                                and changes physical alarm protection on site.
                            </span>
                        </label>
                        {dialogAction?.confirmationMode ===
                            'type_device_name' && (
                            <div className="space-y-1.5">
                                <label
                                    htmlFor="alarm-confirm-device-name"
                                    className="text-sm font-medium"
                                >
                                    Type{' '}
                                    <span className="font-mono">
                                        {deviceName}
                                    </span>{' '}
                                    to confirm disarm
                                </label>
                                <Input
                                    id="alarm-confirm-device-name"
                                    value={confirmName}
                                    onChange={(e) =>
                                        setConfirmName(e.target.value)
                                    }
                                />
                            </div>
                        )}
                        {error && (
                            <p className="text-sm text-status-critical">
                                {error}
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setDialogAction(null)}
                            disabled={submitting}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant={
                                dialogAction?.key === 'alarm.disarm'
                                    ? 'destructive'
                                    : 'default'
                            }
                            onClick={submitAlarmCommand}
                            disabled={
                                submitting ||
                                !area.trim() ||
                                reason.trim().length < 10 ||
                                !impactAck ||
                                (dialogAction?.confirmationMode ===
                                    'type_device_name' &&
                                    confirmName !== deviceName)
                            }
                        >
                            {submitting
                                ? 'Submitting…'
                                : (dialogAction?.label ?? 'Confirm')}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

// ── 4. Door layout ─────────────────────────────────────────────────

function DoorProfileOverview({
    profile,
    typeProfile,
    deviceId,
    deviceName,
    onOpenSection,
}: {
    profile: DeviceProfile;
    typeProfile: Extract<DeviceTypeProfile, { kind: 'door' }>;
    deviceId: number;
    deviceName: string;
    onOpenSection: (section: DeviceProfileSectionKey) => void;
}) {
    const [unlockOpen, setUnlockOpen] = useState(false);
    const [durationSeconds, setDurationSeconds] = useState('10');
    const [reason, setReason] = useState('');
    const [impactAck, setImpactAck] = useState(false);
    const [submitting, setSubmitting] = useState(false);
    const [error, setError] = useState<string | null>(null);

    const submitTimedUnlock = () => {
        const suffix =
            typeof crypto !== 'undefined' && crypto.randomUUID
                ? crypto.randomUUID()
                : `${Date.now()}`;
        setSubmitting(true);
        setError(null);
        router.post(
            typeProfile.commandStoreUrl,
            {
                ...(profile.management.actorId
                    ? { expected_actor_id: profile.management.actorId }
                    : {}),
                capability: 'access.door.unlock_timed',
                parameters: { duration_seconds: Number(durationSeconds) },
                reason: reason.trim(),
                idempotency_key: `command_${deviceId}_${suffix}`,
                impact_acknowledged: impactAck,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setUnlockOpen(false);
                    setReason('');
                    setImpactAck(false);
                },
                onError: (errors) => {
                    setError(
                        Object.values(errors)[0] ??
                            'The timed unlock request could not be created.',
                    );
                },
                onFinish: () => setSubmitting(false),
            },
        );
    };

    const doorStateLabel =
        typeProfile.doorState === 'not_reported'
            ? 'No state reported yet'
            : humanise(typeProfile.doorState);

    return (
        <div
            data-testid="device-layout-door"
            className="grid gap-4 lg:grid-cols-2"
        >
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <KeyRound className="h-4 w-4" />
                                Door state &amp; timed unlock
                            </CardTitle>
                            <CardDescription>
                                Timed unlock always requires permission,
                                confirmation, an operational reason, and an
                                audit record.
                            </CardDescription>
                        </div>
                        <StatusBadge
                            variant={statusVariantForState(
                                typeProfile.doorState,
                            )}
                        >
                            {doorStateLabel}
                        </StatusBadge>
                    </div>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="rounded-lg bg-muted/30 p-3 text-sm">
                        <p className="font-medium">
                            Current door state: {doorStateLabel}
                        </p>
                        <p className="text-caption mt-1">
                            {typeProfile.doorStateAt
                                ? `Last reported ${formatDateTime(typeProfile.doorStateAt)}.`
                                : 'No lock, unlock, or door-contact state has been reported yet.'}
                        </p>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <Button
                            type="button"
                            size="sm"
                            disabled={!typeProfile.unlockAction?.available}
                            onClick={() => {
                                setDurationSeconds('10');
                                setReason('');
                                setImpactAck(false);
                                setError(null);
                                setUnlockOpen(true);
                            }}
                        >
                            <Unlock className="mr-1.5 h-3.5 w-3.5" />
                            Unlock door temporarily
                        </Button>
                        {profile.management.visible && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => onOpenSection('management')}
                            >
                                Open Management
                            </Button>
                        )}
                    </div>

                    {!typeProfile.unlockAction?.available && (
                        <p className="text-caption rounded-lg bg-muted/30 p-3">
                            {typeProfile.unlockAction?.reason ??
                                'Timed unlock requires access control permission, recent door state, and an active door controller adapter.'}
                        </p>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <DoorOpen className="h-4 w-4" />
                                Recent door events
                            </CardTitle>
                            <CardDescription>
                                Latest access and contact events recorded for
                                this door.
                            </CardDescription>
                        </div>
                        {profile.sections.some((s) => s.key === 'events') && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => onOpenSection('events')}
                            >
                                All events
                            </Button>
                        )}
                    </div>
                </CardHeader>
                <CardContent>
                    {typeProfile.recentEvents.length === 0 ? (
                        <EmptyState
                            icon={DoorOpen}
                            title="No door events yet"
                            description="Badge swipes, door-contact changes, and forced-door alerts will appear here."
                            variant="compact"
                        />
                    ) : (
                        <div className="space-y-2">
                            {typeProfile.recentEvents.slice(0, 5).map((ev) => (
                                <div
                                    key={ev.id}
                                    className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                                >
                                    <div className="min-w-0">
                                        <p className="truncate font-medium">
                                            {humanise(ev.eventType)}
                                        </p>
                                        <p className="text-caption">
                                            {[
                                                ev.source
                                                    ? humanise(ev.source)
                                                    : null,
                                                ev.occurredAt
                                                    ? formatRelative(
                                                          ev.occurredAt,
                                                      )
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </p>
                                    </div>
                                    <StatusBadge
                                        variant={statusVariantForState(
                                            ev.severity,
                                        )}
                                    >
                                        {humanise(ev.severity)}
                                    </StatusBadge>
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Dialog open={unlockOpen} onOpenChange={setUnlockOpen}>
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 520px)',
                        width: 'min(92vw, 520px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>Unlock door temporarily</DialogTitle>
                        <DialogDescription>
                            Choose how long {deviceName} should remain unlocked
                            before relocking automatically, and record a
                            permanent audit reason.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <label
                                htmlFor="door-unlock-duration"
                                className="text-sm font-medium"
                            >
                                Unlock duration
                            </label>
                            <Select
                                value={durationSeconds}
                                onValueChange={setDurationSeconds}
                            >
                                <SelectTrigger id="door-unlock-duration">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem value="5">5 seconds</SelectItem>
                                    <SelectItem value="10">
                                        10 seconds
                                    </SelectItem>
                                    <SelectItem value="15">
                                        15 seconds
                                    </SelectItem>
                                    <SelectItem value="30">
                                        30 seconds
                                    </SelectItem>
                                    <SelectItem value="60">
                                        60 seconds
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div className="space-y-1.5">
                            <label
                                htmlFor="door-unlock-reason"
                                className="text-sm font-medium"
                            >
                                Operational reason (minimum 10 characters)
                            </label>
                            <textarea
                                id="door-unlock-reason"
                                rows={3}
                                maxLength={1000}
                                value={reason}
                                onChange={(e) => setReason(e.target.value)}
                                className="w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                                placeholder="Who is being admitted and why?"
                            />
                        </div>
                        <label className="flex items-start gap-2.5 text-sm">
                            <input
                                type="checkbox"
                                className="mt-1 h-4 w-4"
                                checked={impactAck}
                                onChange={(e) => setImpactAck(e.target.checked)}
                            />
                            <span>
                                I confirm the doorway is safe to release and
                                understand this action is permanently audited.
                            </span>
                        </label>
                        {error && (
                            <p className="text-sm text-status-critical">
                                {error}
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={() => setUnlockOpen(false)}
                            disabled={submitting}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            onClick={submitTimedUnlock}
                            disabled={
                                submitting ||
                                reason.trim().length < 10 ||
                                !impactAck
                            }
                        >
                            {submitting
                                ? 'Requesting…'
                                : 'Confirm timed unlock'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}

// ── 5. Sensor layout ───────────────────────────────────────────────

function SensorProfileOverview({
    typeProfile,
    onOpenSection,
}: {
    typeProfile: Extract<DeviceTypeProfile, { kind: 'sensor' }>;
    onOpenSection: (section: DeviceProfileSectionKey) => void;
}) {
    return (
        <div
            data-testid="device-layout-sensor"
            className="grid gap-4 lg:grid-cols-2"
        >
            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Thermometer className="h-4 w-4" />
                                Sensor readings
                            </CardTitle>
                            <CardDescription>
                                Latest numeric telemetry and environmental
                                measurements.
                            </CardDescription>
                        </div>
                        {typeProfile.readings.length > 0 && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() =>
                                    onOpenSection('interfaces-sensors')
                                }
                            >
                                Full sensor list
                            </Button>
                        )}
                    </div>
                </CardHeader>
                <CardContent>
                    {typeProfile.readings.length === 0 ? (
                        <EmptyState
                            icon={Gauge}
                            title="No readings reported yet"
                            description="Temperature, humidity, occupancy, or contact readings will appear here once the sensor reports data."
                            variant="compact"
                        />
                    ) : (
                        <div className="grid gap-2.5 sm:grid-cols-2">
                            {typeProfile.readings.map((reading) => (
                                <div
                                    key={reading.id}
                                    className="rounded-lg border p-3"
                                >
                                    <div className="flex items-center justify-between gap-2">
                                        <span className="text-caption truncate font-medium">
                                            {reading.label}
                                        </span>
                                        {reading.state && (
                                            <StatusBadge
                                                variant={statusVariantForState(
                                                    reading.state,
                                                )}
                                            >
                                                {humanise(reading.state)}
                                            </StatusBadge>
                                        )}
                                    </div>
                                    <p className="mt-1.5 text-lg font-semibold">
                                        {reading.value === null
                                            ? '—'
                                            : `${reading.value}${reading.unit ? ` ${reading.unit}` : ''}`}
                                    </p>
                                    {reading.observedAt && (
                                        <p className="text-caption">
                                            {formatRelative(reading.observedAt)}
                                        </p>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}
                </CardContent>
            </Card>

            <Card>
                <CardHeader className="pb-3">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2 text-base">
                                <BatteryCharging className="h-4 w-4" />
                                Battery &amp; recent triggers
                            </CardTitle>
                            <CardDescription>
                                Wireless sensor battery health and recent
                                alerts.
                            </CardDescription>
                        </div>
                        <StatusBadge
                            variant={statusVariantForState(
                                typeProfile.batteryState,
                            )}
                        >
                            {typeProfile.batteryLevel !== null
                                ? `${typeProfile.batteryLevel}% (${humanise(typeProfile.batteryState)})`
                                : 'Battery not reported'}
                        </StatusBadge>
                    </div>
                </CardHeader>
                <CardContent className="space-y-3">
                    <div className="rounded-lg bg-muted/30 p-3 text-sm">
                        <p className="font-medium">
                            Battery level:{' '}
                            {typeProfile.batteryLevel !== null
                                ? `${typeProfile.batteryLevel}%`
                                : 'Not reported (mains powered or awaiting first report)'}
                        </p>
                        {typeProfile.batteryUpdatedAt && (
                            <p className="text-caption mt-1">
                                Updated{' '}
                                {formatDateTime(typeProfile.batteryUpdatedAt)}
                            </p>
                        )}
                    </div>

                    {typeProfile.recentTriggers.length > 0 && (
                        <div className="space-y-1.5">
                            <p className="text-caption font-semibold tracking-wide uppercase">
                                Recent sensor events
                            </p>
                            {typeProfile.recentTriggers
                                .slice(0, 4)
                                .map((trigger) => (
                                    <div
                                        key={trigger.id}
                                        className="flex items-center justify-between gap-2 rounded-lg border px-3 py-2 text-sm"
                                    >
                                        <span className="truncate font-medium">
                                            {humanise(trigger.eventType)}
                                        </span>
                                        <span className="text-caption">
                                            {formatRelative(trigger.occurredAt)}
                                        </span>
                                    </div>
                                ))}
                        </div>
                    )}
                </CardContent>
            </Card>
        </div>
    );
}

// ── 6. Tracker layout ──────────────────────────────────────────────

function TrackerProfileOverview({
    typeProfile,
    deviceName,
    onOpenSection,
}: {
    typeProfile: Extract<DeviceTypeProfile, { kind: 'tracker' }>;
    deviceName: string;
    onOpenSection: (section: DeviceProfileSectionKey) => void;
}) {
    const pos = typeProfile.position;
    const hasCoords =
        pos.state === 'available' &&
        typeof pos.latitude === 'number' &&
        typeof pos.longitude === 'number';

    return (
        <Card data-testid="device-layout-tracker">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="space-y-1">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <MapPin className="h-4 w-4" />
                            Tracker position
                            {typeProfile.imeiMasked
                                ? ` · IMEI ${typeProfile.imeiMasked}`
                                : ''}
                        </CardTitle>
                        <CardDescription>
                            Location access follows personal-tracker consent and
                            Fleet / Client permissions.
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <StatusBadge variant={statusVariantForState(pos.state)}>
                            {humanise(pos.state)}
                        </StatusBadge>
                        {typeProfile.hasConsole && (
                            <Button
                                type="button"
                                size="sm"
                                variant="outline"
                                onClick={() => onOpenSection('console')}
                            >
                                <Satellite className="mr-1.5 h-3.5 w-3.5" />
                                Unit console
                            </Button>
                        )}
                    </div>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                {hasCoords ? (
                    <>
                        <LeafletMap
                            center={{
                                lat: pos.latitude!,
                                lng: pos.longitude!,
                            }}
                            zoom={15}
                            height={260}
                            markers={[
                                {
                                    id: `tracker-${deviceName}`,
                                    lat: pos.latitude!,
                                    lng: pos.longitude!,
                                    title: deviceName,
                                    type: 'asset',
                                    status: 'online',
                                    speed: pos.speed_kph ?? undefined,
                                    heading: pos.heading_deg ?? undefined,
                                },
                            ]}
                        />
                        <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-muted/30 p-3 text-sm">
                            <div className="space-y-0.5">
                                <p className="font-mono text-xs font-medium">
                                    {pos.latitude!.toFixed(5)},{' '}
                                    {pos.longitude!.toFixed(5)}
                                    {pos.accuracy_m !== null
                                        ? ` (±${Math.round(pos.accuracy_m)} m)`
                                        : ''}
                                </p>
                                <p className="text-caption">
                                    {[
                                        pos.occurred_at
                                            ? `Reported ${formatDateTime(pos.occurred_at)}`
                                            : null,
                                        pos.speed_kph !== null
                                            ? `${Math.round(pos.speed_kph)} km/h`
                                            : null,
                                        pos.battery_pct !== null
                                            ? `Battery ${pos.battery_pct}%`
                                            : null,
                                    ]
                                        .filter(Boolean)
                                        .join(' · ')}
                                </p>
                            </div>
                            {pos.map_url && (
                                <Button size="sm" variant="outline" asChild>
                                    <a
                                        href={pos.map_url}
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
                                        OpenStreetMap
                                    </a>
                                </Button>
                            )}
                        </div>
                    </>
                ) : (
                    <div
                        data-testid="tracker-position-gated"
                        className="rounded-lg bg-muted/30 p-4 text-sm"
                    >
                        <p className="font-medium text-foreground">
                            {pos.message}
                        </p>
                        <p className="text-caption mt-1">
                            Coordinates are hidden unless assignment, active
                            consent, and viewer permissions all allow location
                            display.
                        </p>
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

// ── 7. Computer layout ─────────────────────────────────────────────

function ComputerProfileOverview({
    typeProfile,
    canSetupAgent,
    onOpenSection,
    onOpenInstallation,
}: {
    typeProfile: Extract<DeviceTypeProfile, { kind: 'computer' }>;
    canSetupAgent: boolean;
    onOpenSection: (section: DeviceProfileSectionKey) => void;
    onOpenInstallation?: () => void;
}) {
    return (
        <Card data-testid="device-layout-computer">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div className="space-y-1">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Laptop className="h-4 w-4" />
                            Computer &amp; Obli agent posture
                        </CardTitle>
                        <CardDescription>
                            Windows computer health, services, scheduled
                            restarts, and approved software via Obli.
                        </CardDescription>
                    </div>
                    <StatusBadge
                        variant={statusVariantForState(typeProfile.agentState)}
                    >
                        {humanise(typeProfile.agentState)}
                    </StatusBadge>
                </div>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-3">
                    <div className="rounded-lg bg-muted/30 p-3">
                        <p className="text-caption">Hostname</p>
                        <p className="mt-1 text-sm font-semibold">
                            {typeProfile.hostname ?? 'Not reported yet'}
                        </p>
                    </div>
                    <div className="rounded-lg bg-muted/30 p-3">
                        <p className="text-caption">Operating system</p>
                        <p className="mt-1 text-sm font-semibold">
                            {typeProfile.osVersion ?? 'Not reported yet'}
                        </p>
                    </div>
                    <div className="rounded-lg bg-muted/30 p-3">
                        <p className="text-caption">Remote script execution</p>
                        <p className="mt-1 flex items-center gap-1.5 text-sm font-semibold">
                            <ShieldCheck className="h-4 w-4 text-muted-foreground" />
                            Turned off by policy
                        </p>
                    </div>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        onClick={() => onOpenSection('endpoint')}
                    >
                        <Settings2 className="mr-1.5 h-3.5 w-3.5" />
                        Open computer posture &amp; controls
                    </Button>
                    {!typeProfile.agentEnrolled &&
                        canSetupAgent &&
                        onOpenInstallation && (
                            <Button
                                type="button"
                                size="sm"
                                onClick={onOpenInstallation}
                            >
                                Set up Obli
                            </Button>
                        )}
                </div>
            </CardContent>
        </Card>
    );
}

// ── 8. Other (generic) layout ──────────────────────────────────────

function OtherProfileOverview({
    typeProfile,
}: {
    typeProfile: Extract<DeviceTypeProfile, { kind: 'other' }>;
}) {
    return (
        <Card data-testid="device-layout-other">
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-base">
                    <Cpu className="h-4 w-4" />
                    Device summary
                </CardTitle>
                <CardDescription>
                    {[typeProfile.categoryLabel, typeProfile.domainLabel]
                        .filter(Boolean)
                        .join(' · ') || 'Connected device details'}
                </CardDescription>
            </CardHeader>
            <CardContent className="space-y-3">
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <div className="rounded-lg bg-muted/30 p-3">
                        <p className="text-caption">IP address</p>
                        <p className="mt-1 font-mono text-sm font-semibold">
                            {typeProfile.ipAddress ?? 'Not recorded'}
                        </p>
                    </div>
                    <div className="rounded-lg bg-muted/30 p-3">
                        <p className="text-caption">MAC address</p>
                        <p className="mt-1 font-mono text-sm font-semibold">
                            {typeProfile.macAddress ?? 'Not recorded'}
                        </p>
                    </div>
                    <div className="rounded-lg bg-muted/30 p-3">
                        <p className="text-caption">Serial number</p>
                        <p className="mt-1 text-sm font-semibold">
                            {typeProfile.serialNumber ?? 'Not recorded'}
                        </p>
                    </div>
                    <div className="rounded-lg bg-muted/30 p-3">
                        <p className="text-caption">Firmware</p>
                        <p className="mt-1 text-sm font-semibold">
                            {typeProfile.firmwareVersion ?? 'Not recorded'}
                        </p>
                    </div>
                </div>
                {typeProfile.notes && (
                    <p className="rounded-lg bg-muted/30 p-3 text-sm text-muted-foreground">
                        {typeProfile.notes}
                    </p>
                )}
            </CardContent>
        </Card>
    );
}
