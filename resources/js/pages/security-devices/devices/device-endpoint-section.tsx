import { ReasonDialog } from '@/components/reason-dialog';
import { Alert, AlertDescription } from '@/components/ui/alert';
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
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Cloud,
    Cpu,
    Download,
    ExternalLink,
    HardDrive,
    Link2,
    Monitor,
    Package,
    Search,
    Settings2,
    ShieldAlert,
    ShieldCheck,
    Unlink,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import type { DeviceEndpointPosture } from './device-profile';
import { installationJSON } from './installation-profile';

const UUID_PATTERN =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

export type EndpointBadgeVariant =
    | 'success'
    | 'warning'
    | 'critical'
    | 'info'
    | 'neutral';

export function endpointAgentBadgeMeta(state: string | null | undefined): {
    label: string;
    variant: EndpointBadgeVariant;
} {
    switch (state) {
        case 'fresh':
            return { label: 'Agent connected', variant: 'success' };
        case 'stale':
            return { label: 'Heartbeat overdue', variant: 'warning' };
        case 'pending_enrolment':
            return { label: 'Awaiting enrolment', variant: 'info' };
        case 'enrolment_expired':
            return { label: 'Enrolment expired', variant: 'warning' };
        case 'enrolled_awaiting_heartbeat':
            return { label: 'Awaiting first heartbeat', variant: 'info' };
        case 'assignment_drift':
            return { label: 'Re-enrolment required', variant: 'critical' };
        case 'revoked':
            return { label: 'Agent revoked', variant: 'critical' };
        case 'not_enrolled':
        default:
            return { label: 'Not enrolled', variant: 'neutral' };
    }
}

function formatBytes(bytes: number | null | undefined): string {
    if (bytes === null || bytes === undefined || !Number.isFinite(bytes)) {
        return 'Not reported';
    }
    if (bytes <= 0) return '0 B';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    const exponent = Math.min(
        units.length - 1,
        Math.floor(Math.log(bytes) / Math.log(1024)),
    );
    const value = bytes / Math.pow(1024, exponent);
    return `${value >= 100 || exponent === 0 ? Math.round(value) : value.toFixed(1)} ${units[exponent]}`;
}

function formatUptime(seconds: number | null | undefined): string {
    if (seconds === null || seconds === undefined || seconds < 0) {
        return 'Not reported';
    }
    const days = Math.floor(seconds / 86400);
    const hours = Math.floor((seconds % 86400) / 3600);
    const minutes = Math.floor((seconds % 3600) / 60);
    if (days > 0) return `${days}d ${hours}h`;
    if (hours > 0) return `${hours}h ${minutes}m`;
    return `${minutes}m`;
}

function intuneSyncBadgeMeta(
    linked: boolean,
    syncStatus: string | null,
): { label: string; variant: EndpointBadgeVariant } {
    if (!linked) return { label: 'Not linked', variant: 'neutral' };
    switch (syncStatus) {
        case 'current':
            return { label: 'Synced', variant: 'success' };
        case 'pending':
            return { label: 'Awaiting sync', variant: 'info' };
        case 'missing':
            return { label: 'Not found in Intune', variant: 'warning' };
        case 'failed':
            return { label: 'Refresh failed', variant: 'warning' };
        default:
            return { label: 'Linked', variant: 'neutral' };
    }
}

function complianceBadgeMeta(state: string | null | undefined): {
    label: string;
    variant: EndpointBadgeVariant;
} {
    if (!state) return { label: 'Not reported', variant: 'neutral' };
    const normalised = state.toLowerCase();
    if (normalised === 'compliant') {
        return { label: 'Compliant', variant: 'success' };
    }
    if (normalised === 'noncompliant') {
        return { label: 'Non-compliant', variant: 'critical' };
    }
    if (normalised === 'ingraceperiod') {
        return { label: 'In grace period', variant: 'warning' };
    }
    return {
        label: state.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
        variant: 'neutral',
    };
}

function DetailItem({
    label,
    value,
    detail,
}: {
    label: string;
    value: string;
    detail?: string | null;
}) {
    return (
        <div>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5 font-medium break-words">{value}</dd>
            {detail ? (
                <dd className="text-xs text-muted-foreground">{detail}</dd>
            ) : null}
        </div>
    );
}

export function DeviceEndpointSection({
    posture,
    deviceId,
    deviceName,
    canSetupAgent,
    onOpenInstallation,
}: {
    posture: DeviceEndpointPosture | null | undefined;
    deviceId: number;
    deviceName: string;
    canSetupAgent: boolean;
    onOpenInstallation?: () => void;
}) {
    const [serviceSearch, setServiceSearch] = useState('');
    const [serviceFilter, setServiceFilter] = useState<
        'all' | 'approved' | 'running' | 'stopped'
    >('all');
    const [softwareSearch, setSoftwareSearch] = useState('');
    const [revokeOpen, setRevokeOpen] = useState(false);
    const [revokeError, setRevokeError] = useState<string | null>(null);
    const [intuneDialogOpen, setIntuneDialogOpen] = useState(false);
    const [intuneDeviceId, setIntuneDeviceId] = useState('');
    const [intuneReason, setIntuneReason] = useState('');
    const [intuneSubmitting, setIntuneSubmitting] = useState(false);
    const [intuneError, setIntuneError] = useState<string | null>(null);
    const [intuneUnlinkOpen, setIntuneUnlinkOpen] = useState(false);

    const inventory = posture?.inventory ?? null;

    const filteredServices = useMemo(() => {
        if (!inventory) return [];
        const needle = serviceSearch.trim().toLowerCase();
        return inventory.services.filter((svc) => {
            if (serviceFilter === 'approved' && !svc.approved) return false;
            if (serviceFilter === 'running' && svc.state !== 'running') {
                return false;
            }
            if (serviceFilter === 'stopped' && svc.state !== 'stopped') {
                return false;
            }
            if (!needle) return true;
            return (
                svc.name.toLowerCase().includes(needle) ||
                (svc.label?.toLowerCase().includes(needle) ?? false)
            );
        });
    }, [inventory, serviceFilter, serviceSearch]);

    const filteredSoftware = useMemo(() => {
        if (!inventory) return [];
        const needle = softwareSearch.trim().toLowerCase();
        if (!needle) return inventory.software;
        return inventory.software.filter(
            (item) =>
                item.name.toLowerCase().includes(needle) ||
                (item.version?.toLowerCase().includes(needle) ?? false) ||
                (item.publisher?.toLowerCase().includes(needle) ?? false),
        );
    }, [inventory, softwareSearch]);

    if (!posture) {
        return (
            <EmptyState
                icon={Monitor}
                title="Endpoint posture unavailable"
                description="Endpoint posture is available for authorised IT workstations and servers."
                variant="compact"
            />
        );
    }

    const { agent, policies, intune, links } = posture;
    const badge = endpointAgentBadgeMeta(agent.state);

    const memoryUsedBytes =
        inventory &&
        inventory.memoryTotalBytes !== null &&
        inventory.memoryAvailableBytes !== null
            ? Math.max(
                  0,
                  inventory.memoryTotalBytes - inventory.memoryAvailableBytes,
              )
            : null;
    const memoryUsedPercent =
        memoryUsedBytes !== null &&
        inventory?.memoryTotalBytes &&
        inventory.memoryTotalBytes > 0
            ? Math.round((memoryUsedBytes / inventory.memoryTotalBytes) * 100)
            : null;

    const confirmRevokeAgent = (reason: string, done: () => void) => {
        setRevokeError(null);
        const controller = new AbortController();
        installationJSON(deviceId, 'revoke', controller.signal, {
            reason,
            ...(agent.uuid ? { expected_agent_uuid: agent.uuid } : {}),
        })
            .then(() => {
                setRevokeOpen(false);
                router.reload({ preserveScroll: true });
            })
            .catch(() => {
                setRevokeError(
                    'The computer agent could not be revoked. Refresh this page and check your access.',
                );
            })
            .finally(done);
    };

    const openLinkIntuneDialog = () => {
        setIntuneDeviceId(intune?.managedDeviceId ?? '');
        setIntuneReason('');
        setIntuneError(null);
        setIntuneDialogOpen(true);
    };

    const submitIntuneLink = () => {
        if (!intune?.linkHref) return;
        const trimmed = intuneDeviceId.trim();
        if (!UUID_PATTERN.test(trimmed)) {
            setIntuneError(
                'Enter a valid Microsoft Intune managed device UUID.',
            );
            return;
        }
        setIntuneSubmitting(true);
        setIntuneError(null);
        router.post(
            intune.linkHref,
            {
                managed_device_id: trimmed,
                reason: intuneReason.trim() || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setIntuneDialogOpen(false);
                },
                onError: (errors) => {
                    const first = Object.values(errors)[0];
                    setIntuneError(
                        Array.isArray(first)
                            ? first[0]
                            : String(
                                  first ??
                                      'Could not save the Microsoft Intune link.',
                              ),
                    );
                },
                onFinish: () => setIntuneSubmitting(false),
            },
        );
    };

    const confirmIntuneUnlink = (reason: string, done: () => void) => {
        if (!intune?.linkHref) {
            done();
            return;
        }
        if (reason.trim().length < 10) {
            setIntuneError('Provide a reason of at least 10 characters.');
            done();
            return;
        }
        router.delete(intune.linkHref, {
            data: { reason: reason.trim() },
            preserveScroll: true,
            onSuccess: () => {
                setIntuneUnlinkOpen(false);
            },
            onFinish: done,
        });
    };

    return (
        <div className="space-y-5">
            {/* ── Computer agent & enrolment posture ────────────── */}
            <Card data-testid="endpoint-agent-card">
                <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                    <div>
                        <CardTitle className="flex flex-wrap items-center gap-2">
                            <Monitor className="h-4 w-4" />
                            <span>Windows computer agent</span>
                            <StatusBadge variant={badge.variant}>
                                {badge.label}
                            </StatusBadge>
                        </CardTitle>
                        <CardDescription>
                            Enrolment, heartbeat freshness, and native action
                            readiness for {deviceName}.
                        </CardDescription>
                    </div>
                    <div className="flex flex-wrap gap-2">
                        {canSetupAgent && onOpenInstallation && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={onOpenInstallation}
                            >
                                Set up Obli
                            </Button>
                        )}
                        {agent.canRevoke && (
                            <Button
                                size="sm"
                                variant="outline"
                                onClick={() => {
                                    setRevokeError(null);
                                    setRevokeOpen(true);
                                }}
                            >
                                Revoke agent
                            </Button>
                        )}
                        {links.agentDownloadsHref && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={links.agentDownloadsHref}>
                                    <Download className="mr-1.5 h-3.5 w-3.5" />
                                    Agent downloads
                                </Link>
                            </Button>
                        )}
                        {links.remoteSupportHref && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={links.remoteSupportHref}>
                                    <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
                                    Remote support
                                </Link>
                            </Button>
                        )}
                        {links.approvedSoftwareHref && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={links.approvedSoftwareHref}>
                                    <Package className="mr-1.5 h-3.5 w-3.5" />
                                    Approved software
                                </Link>
                            </Button>
                        )}
                    </div>
                </CardHeader>
                <CardContent className="space-y-4">
                    {agent.state === 'assignment_drift' && (
                        <Alert>
                            <AlertTriangle className="h-4 w-4" />
                            <AlertDescription>
                                This computer’s Site or assignment changed after
                                enrolment. Previous diagnostic inventory is
                                withheld until an administrator re-enrols the
                                computer for its current assignment.
                            </AlertDescription>
                        </Alert>
                    )}
                    {revokeError && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {revokeError}
                        </p>
                    )}
                    <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-6">
                        <DetailItem
                            label="Platform"
                            value={
                                agent.platform === 'windows'
                                    ? 'Windows'
                                    : agent.platform
                            }
                        />
                        <DetailItem
                            label="Agent version"
                            value={agent.agentVersion ?? 'Not reported'}
                        />
                        <DetailItem
                            label="Last heartbeat"
                            value={
                                agent.lastSeenAt
                                    ? formatRelative(agent.lastSeenAt)
                                    : 'Never reported'
                            }
                            detail={
                                agent.lastSeenAt
                                    ? formatDateTime(agent.lastSeenAt)
                                    : null
                            }
                        />
                        <DetailItem
                            label="Snapshot observed"
                            value={formatDateTime(
                                agent.observedAt,
                                'Never reported',
                            )}
                        />
                        <DetailItem
                            label="Heartbeat sequence"
                            value={
                                agent.sequence > 0
                                    ? `#${agent.sequence}`
                                    : 'None'
                            }
                        />
                        <DetailItem
                            label="Enrolled"
                            value={formatDateTime(
                                agent.enrolledAt,
                                agent.enrolmentExpiresAt
                                    ? `Expires ${formatDateTime(agent.enrolmentExpiresAt)}`
                                    : 'Not enrolled',
                            )}
                        />
                    </dl>
                    <div className="flex flex-wrap items-center gap-2 border-t pt-3 text-xs">
                        <span className="font-medium text-muted-foreground">
                            Installed executors:
                        </span>
                        <StatusBadge
                            variant={
                                agent.serviceActionsReady
                                    ? 'success'
                                    : 'neutral'
                            }
                        >
                            Windows SCM:{' '}
                            {agent.serviceActionsReady
                                ? 'Ready'
                                : 'Not reported'}
                        </StatusBadge>
                        <StatusBadge
                            variant={
                                agent.restartActionsReady
                                    ? 'success'
                                    : 'neutral'
                            }
                        >
                            Scheduled restart:{' '}
                            {agent.restartActionsReady
                                ? 'Ready'
                                : 'Not reported'}
                        </StatusBadge>
                        <StatusBadge
                            variant={
                                agent.softwareActionsReady
                                    ? 'success'
                                    : 'neutral'
                            }
                        >
                            Approved MSI:{' '}
                            {agent.softwareActionsReady
                                ? 'Ready'
                                : 'Not reported'}
                        </StatusBadge>
                        <StatusBadge variant="neutral">
                            Script execution: Disabled
                        </StatusBadge>
                    </div>
                </CardContent>
            </Card>

            {/* ── Per-computer action policies ──────────────────── */}
            <Card data-testid="endpoint-policies-card">
                <CardHeader className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
                    <div>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <ShieldCheck className="h-4 w-4" />
                            <span>Computer action policies</span>
                        </CardTitle>
                        <CardDescription>
                            Consent mode and release availability for governed
                            actions on this computer.
                        </CardDescription>
                    </div>
                    {links.remoteSupportHref &&
                        (policies.service.canManage ||
                            policies.restart.canManage ||
                            policies.software.canManage) && (
                            <Button size="sm" variant="outline" asChild>
                                <Link href={links.remoteSupportHref}>
                                    <Settings2 className="mr-1.5 h-3.5 w-3.5" />
                                    Configure policies
                                </Link>
                            </Button>
                        )}
                </CardHeader>
                <CardContent>
                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                        <div className="rounded-lg border p-3 text-sm">
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-medium">
                                    Windows services
                                </span>
                                <StatusBadge
                                    variant={
                                        policies.service.enabled &&
                                        policies.service.available
                                            ? 'success'
                                            : 'neutral'
                                    }
                                >
                                    {policies.service.enabled
                                        ? policies.service.available
                                            ? 'Enabled'
                                            : 'Release off'
                                        : 'Disabled'}
                                </StatusBadge>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {policies.service.consentMode === 'unattended'
                                    ? 'Unattended'
                                    : 'Attended consent'}{' '}
                                · {policies.service.catalogueCount} approved
                                services
                            </p>
                        </div>

                        <div className="rounded-lg border p-3 text-sm">
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-medium">
                                    Scheduled restarts
                                </span>
                                <StatusBadge
                                    variant={
                                        policies.restart.enabled &&
                                        policies.restart.available
                                            ? 'success'
                                            : 'neutral'
                                    }
                                >
                                    {policies.restart.enabled
                                        ? policies.restart.available
                                            ? 'Enabled'
                                            : 'Release off'
                                        : 'Disabled'}
                                </StatusBadge>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {policies.restart.consentMode === 'unattended'
                                    ? 'Unattended'
                                    : 'Attended consent'}{' '}
                                · Min {policies.restart.minimumWarningSeconds}s
                                warning
                            </p>
                        </div>

                        <div className="rounded-lg border p-3 text-sm">
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-medium">
                                    Approved MSI software
                                </span>
                                <StatusBadge
                                    variant={
                                        policies.software.enabled &&
                                        policies.software.available
                                            ? 'success'
                                            : 'neutral'
                                    }
                                >
                                    {policies.software.enabled
                                        ? policies.software.available
                                            ? 'Enabled'
                                            : 'Release off'
                                        : 'Disabled'}
                                </StatusBadge>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                {policies.software.consentMode === 'unattended'
                                    ? 'Unattended'
                                    : 'Attended consent'}{' '}
                                · Reviewed catalogue only
                            </p>
                        </div>

                        <div className="rounded-lg border bg-muted/40 p-3 text-sm">
                            <div className="flex items-center justify-between gap-2">
                                <span className="font-medium">
                                    Script execution
                                </span>
                                <StatusBadge variant="neutral">
                                    Disabled
                                </StatusBadge>
                            </div>
                            <p className="mt-1 text-xs text-muted-foreground">
                                Arbitrary and catalogue script execution remain
                                turned off in this release.
                            </p>
                        </div>
                    </div>
                </CardContent>
            </Card>

            {/* ── Diagnostic inventory (permission-scoped) ──────── */}
            {posture.diagnosticsRestricted ? (
                <Card data-testid="endpoint-diagnostics-restricted">
                    <CardContent className="pt-6">
                        <EmptyState
                            icon={ShieldAlert}
                            title="Diagnostic inventory access required"
                            description="Detailed CPU, memory, disk, Windows service, and installed software snapshots require the computer diagnostics permission."
                            variant="compact"
                        />
                    </CardContent>
                </Card>
            ) : !inventory ? (
                <Card data-testid="endpoint-diagnostics-empty">
                    <CardContent className="pt-6">
                        <EmptyState
                            icon={Cpu}
                            title="No current diagnostic snapshot"
                            description="System resources, Windows services, and installed software will appear here once an enrolled agent reports a heartbeat for this assignment."
                            variant="compact"
                        />
                    </CardContent>
                </Card>
            ) : (
                <>
                    {inventory.probeErrors.length > 0 && (
                        <Alert>
                            <AlertTriangle className="h-4 w-4" />
                            <AlertDescription>
                                Partial probe collection reported for:{' '}
                                {inventory.probeErrors.join(', ')}.
                            </AlertDescription>
                        </Alert>
                    )}

                    {/* System & storage */}
                    <Card data-testid="endpoint-system-card">
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <HardDrive className="h-4 w-4" />
                                <span>System & storage</span>
                            </CardTitle>
                            <CardDescription>
                                Reported operating system, compute utilisation,
                                and local disk capacity.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="space-y-4">
                            <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-6">
                                <DetailItem
                                    label="Hostname"
                                    value={inventory.hostname ?? 'Not reported'}
                                />
                                <DetailItem
                                    label="Operating system"
                                    value={
                                        inventory.osVersion ?? 'Not reported'
                                    }
                                />
                                <DetailItem
                                    label="Architecture"
                                    value={
                                        inventory.architecture ?? 'Not reported'
                                    }
                                />
                                <DetailItem
                                    label="Uptime"
                                    value={formatUptime(
                                        inventory.uptimeSeconds,
                                    )}
                                />
                                <DetailItem
                                    label="CPU utilisation"
                                    value={
                                        inventory.cpuPercent !== null
                                            ? `${inventory.cpuPercent}%`
                                            : 'Not reported'
                                    }
                                />
                                <DetailItem
                                    label="Memory used"
                                    value={
                                        memoryUsedBytes !== null &&
                                        inventory.memoryTotalBytes !== null
                                            ? `${formatBytes(memoryUsedBytes)} / ${formatBytes(inventory.memoryTotalBytes)}`
                                            : 'Not reported'
                                    }
                                    detail={
                                        memoryUsedPercent !== null
                                            ? `${memoryUsedPercent}% in use`
                                            : null
                                    }
                                />
                            </dl>

                            {inventory.disks.length > 0 && (
                                <div className="space-y-2 border-t pt-3">
                                    <p className="text-xs font-medium text-muted-foreground">
                                        Local volumes
                                    </p>
                                    <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                        {inventory.disks.map((disk) => {
                                            const pct = disk.usedPercent ?? 0;
                                            const barTone =
                                                pct >= 90
                                                    ? 'bg-destructive'
                                                    : pct >= 80
                                                      ? 'bg-status-warning'
                                                      : 'bg-primary';
                                            return (
                                                <div
                                                    key={disk.name}
                                                    className="rounded-lg border p-3 text-sm"
                                                >
                                                    <div className="flex items-center justify-between gap-2">
                                                        <span className="font-medium">
                                                            {disk.name}
                                                        </span>
                                                        <span className="text-xs text-muted-foreground">
                                                            {disk.usedPercent !==
                                                            null
                                                                ? `${disk.usedPercent}% used`
                                                                : 'Capacity unknown'}
                                                        </span>
                                                    </div>
                                                    <div className="mt-2 h-2 overflow-hidden rounded-full bg-muted">
                                                        <div
                                                            className={`h-full rounded-full ${barTone}`}
                                                            style={{
                                                                width: `${Math.min(100, Math.max(0, pct))}%`,
                                                            }}
                                                        />
                                                    </div>
                                                    <p className="mt-1.5 text-xs text-muted-foreground">
                                                        {formatBytes(
                                                            disk.freeBytes,
                                                        )}{' '}
                                                        free of{' '}
                                                        {formatBytes(
                                                            disk.totalBytes,
                                                        )}
                                                    </p>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Windows services */}
                    <Card data-testid="endpoint-services-card">
                        <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <CardTitle className="text-base">
                                    Windows services ({inventory.servicesTotal})
                                </CardTitle>
                                <CardDescription>
                                    Reported Windows SCM services. Services in
                                    the approved catalogue can be started,
                                    stopped, or restarted through governed
                                    commands.
                                </CardDescription>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="relative">
                                    <Search className="pointer-events-none absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                    <Input
                                        value={serviceSearch}
                                        onChange={(e) =>
                                            setServiceSearch(e.target.value)
                                        }
                                        placeholder="Filter services…"
                                        aria-label="Filter Windows services"
                                        className="h-9 w-52 pl-8"
                                    />
                                </div>
                                <div className="flex rounded-md border p-0.5 text-xs">
                                    {(
                                        [
                                            ['all', 'All'],
                                            ['approved', 'Approved'],
                                            ['running', 'Running'],
                                            ['stopped', 'Stopped'],
                                        ] as const
                                    ).map(([key, label]) => (
                                        <Button
                                            key={key}
                                            type="button"
                                            size="sm"
                                            variant={
                                                serviceFilter === key
                                                    ? 'secondary'
                                                    : 'ghost'
                                            }
                                            onClick={() =>
                                                setServiceFilter(key)
                                            }
                                            className="h-7 px-2.5 text-xs"
                                        >
                                            {label}
                                        </Button>
                                    ))}
                                </div>
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {(!inventory.servicesComplete ||
                                inventory.servicesTruncated) && (
                                <p className="rounded-lg border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-xs text-status-warning">
                                    {!inventory.servicesComplete
                                        ? 'The agent reported a partial service list on the latest probe.'
                                        : `Showing ${inventory.services.length} of ${inventory.servicesTotal} reported services. Approved catalogue services are listed first.`}
                                </p>
                            )}
                            {filteredServices.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No matching Windows services.
                                </p>
                            ) : (
                                <div className="max-h-80 divide-y overflow-y-auto rounded-lg border">
                                    {filteredServices.map((svc) => (
                                        <div
                                            key={svc.name}
                                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm"
                                        >
                                            <div className="min-w-0">
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <span className="font-medium">
                                                        {svc.label ?? svc.name}
                                                    </span>
                                                    {svc.label && (
                                                        <span className="font-mono text-xs text-muted-foreground">
                                                            {svc.name}
                                                        </span>
                                                    )}
                                                    {svc.approved && (
                                                        <StatusBadge variant="info">
                                                            Approved catalogue
                                                        </StatusBadge>
                                                    )}
                                                </div>
                                            </div>
                                            <div className="flex items-center gap-2">
                                                <StatusBadge
                                                    variant={
                                                        svc.state === 'running'
                                                            ? 'success'
                                                            : svc.state ===
                                                                'stopped'
                                                              ? 'neutral'
                                                              : 'warning'
                                                    }
                                                >
                                                    {svc.state === 'running'
                                                        ? 'Running'
                                                        : svc.state ===
                                                            'stopped'
                                                          ? 'Stopped'
                                                          : 'Unknown'}
                                                </StatusBadge>
                                                {svc.manageHref && (
                                                    <Button
                                                        size="sm"
                                                        variant="outline"
                                                        asChild
                                                    >
                                                        <Link
                                                            href={
                                                                svc.manageHref
                                                            }
                                                            preserveState={
                                                                false
                                                            }
                                                        >
                                                            Manage service
                                                        </Link>
                                                    </Button>
                                                )}
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>

                    {/* Installed software */}
                    <Card data-testid="endpoint-software-card">
                        <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <CardTitle className="text-base">
                                    Installed software ({inventory.softwareTotal}
                                    )
                                </CardTitle>
                                <CardDescription>
                                    Applications reported by the Windows
                                    computer agent.
                                </CardDescription>
                            </div>
                            <div className="relative">
                                <Search className="pointer-events-none absolute top-2.5 left-2.5 h-4 w-4 text-muted-foreground" />
                                <Input
                                    value={softwareSearch}
                                    onChange={(e) =>
                                        setSoftwareSearch(e.target.value)
                                    }
                                    placeholder="Filter installed software…"
                                    aria-label="Filter installed software"
                                    className="h-9 w-60 pl-8"
                                />
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {(!inventory.softwareComplete ||
                                inventory.softwareTruncated) && (
                                <p className="rounded-lg border border-status-warning/30 bg-status-warning-bg px-3 py-2 text-xs text-status-warning">
                                    {!inventory.softwareComplete
                                        ? 'The agent reported a partial software list on the latest probe.'
                                        : `Showing ${inventory.software.length} of ${inventory.softwareTotal} reported applications.`}
                                </p>
                            )}
                            {filteredSoftware.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No matching installed software.
                                </p>
                            ) : (
                                <div className="max-h-80 divide-y overflow-y-auto rounded-lg border">
                                    {filteredSoftware.map((item, idx) => (
                                        <div
                                            key={`${item.name}-${item.version ?? ''}-${idx}`}
                                            className="flex flex-wrap items-center justify-between gap-2 px-3 py-2.5 text-sm"
                                        >
                                            <div className="min-w-0">
                                                <p className="truncate font-medium">
                                                    {item.name}
                                                </p>
                                                <p className="text-xs text-muted-foreground">
                                                    {item.publisher ??
                                                        'Publisher not recorded'}
                                                </p>
                                            </div>
                                            <span className="font-mono text-xs text-muted-foreground">
                                                {item.version ??
                                                    'Version not recorded'}
                                            </span>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </CardContent>
                    </Card>
                </>
            )}

            {/* ── Microsoft Intune read-only posture ────────────── */}
            {intune && (
                <Card data-testid="endpoint-intune-card">
                    <CardHeader className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                        <div>
                            <CardTitle className="flex flex-wrap items-center gap-2 text-base">
                                <Cloud className="h-4 w-4" />
                                <span>Microsoft Intune posture</span>
                                <StatusBadge
                                    variant={
                                        intuneSyncBadgeMeta(
                                            intune.linked,
                                            intune.syncStatus,
                                        ).variant
                                    }
                                >
                                    {
                                        intuneSyncBadgeMeta(
                                            intune.linked,
                                            intune.syncStatus,
                                        ).label
                                    }
                                </StatusBadge>
                            </CardTitle>
                            <CardDescription>
                                Read-only compliance and encryption posture from
                                Microsoft Intune for explicitly linked
                                computers.
                            </CardDescription>
                        </div>
                        {intune.canManageLink && intune.linkHref && (
                            <div className="flex flex-wrap gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    onClick={openLinkIntuneDialog}
                                >
                                    <Link2 className="mr-1.5 h-3.5 w-3.5" />
                                    {intune.linked
                                        ? 'Update Intune link'
                                        : 'Link Intune device'}
                                </Button>
                                {intune.linked && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            setIntuneUnlinkOpen(true)
                                        }
                                    >
                                        <Unlink className="mr-1.5 h-3.5 w-3.5" />
                                        Remove link
                                    </Button>
                                )}
                            </div>
                        )}
                    </CardHeader>
                    <CardContent className="space-y-4">
                        {!intune.linked ? (
                            <p className="text-sm text-muted-foreground">
                                No Microsoft Intune managed device UUID is
                                linked to this computer.
                                {!intune.integrationEnabled
                                    ? ' Scheduled Intune refresh is currently turned off in deployment configuration.'
                                    : ''}
                            </p>
                        ) : (
                            <>
                                {intune.syncStatus === 'failed' && (
                                    <Alert>
                                        <AlertTriangle className="h-4 w-4" />
                                        <AlertDescription>
                                            The latest Microsoft Intune refresh
                                            failed. Previous observations remain
                                            shown with their original sync
                                            timestamp.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                {intune.syncStatus === 'missing' && (
                                    <Alert>
                                        <AlertTriangle className="h-4 w-4" />
                                        <AlertDescription>
                                            This managed device ID was not
                                            returned by Microsoft Intune during
                                            the latest refresh. Check the UUID
                                            or device enrolment in Intune.
                                        </AlertDescription>
                                    </Alert>
                                )}
                                <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                    <DetailItem
                                        label="Managed device ID"
                                        value={
                                            intune.managedDeviceId ??
                                            'Not recorded'
                                        }
                                    />
                                    <DetailItem
                                        label="Last successful sync"
                                        value={formatDateTime(
                                            intune.syncedAt,
                                            'Not yet synced',
                                        )}
                                    />
                                    <DetailItem
                                        label="Last sync attempt"
                                        value={formatDateTime(
                                            intune.lastAttemptAt,
                                            'Not yet attempted',
                                        )}
                                    />
                                    <DetailItem
                                        label="Scheduled refresh"
                                        value={
                                            intune.integrationEnabled
                                                ? 'Configured'
                                                : 'Not configured'
                                        }
                                    />
                                </dl>

                                {intune.snapshot && (
                                    <div className="space-y-3 border-t pt-3">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <StatusBadge
                                                variant={
                                                    complianceBadgeMeta(
                                                        intune.snapshot
                                                            .complianceState,
                                                    ).variant
                                                }
                                            >
                                                Compliance:{' '}
                                                {
                                                    complianceBadgeMeta(
                                                        intune.snapshot
                                                            .complianceState,
                                                    ).label
                                                }
                                            </StatusBadge>
                                            <StatusBadge
                                                variant={
                                                    intune.snapshot
                                                        .isEncrypted === true
                                                        ? 'success'
                                                        : intune.snapshot
                                                                .isEncrypted ===
                                                            false
                                                          ? 'critical'
                                                          : 'neutral'
                                                }
                                            >
                                                Encryption:{' '}
                                                {intune.snapshot.isEncrypted ===
                                                true
                                                    ? 'Encrypted'
                                                    : intune.snapshot
                                                            .isEncrypted ===
                                                        false
                                                      ? 'Not encrypted'
                                                      : 'Not reported'}
                                            </StatusBadge>
                                        </div>
                                        <dl className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                                            <DetailItem
                                                label="Intune device name"
                                                value={
                                                    intune.snapshot
                                                        .deviceName ??
                                                    'Not reported'
                                                }
                                            />
                                            <DetailItem
                                                label="Operating system"
                                                value={
                                                    [
                                                        intune.snapshot
                                                            .operatingSystem,
                                                        intune.snapshot
                                                            .osVersion,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' ') ||
                                                    'Not reported'
                                                }
                                            />
                                            <DetailItem
                                                label="Hardware"
                                                value={
                                                    [
                                                        intune.snapshot
                                                            .manufacturer,
                                                        intune.snapshot.model,
                                                    ]
                                                        .filter(Boolean)
                                                        .join(' ') ||
                                                    'Not reported'
                                                }
                                                detail={
                                                    intune.snapshot.serialNumber
                                                        ? `Serial: ${intune.snapshot.serialNumber}`
                                                        : null
                                                }
                                            />
                                            <DetailItem
                                                label="Intune check-in"
                                                value={formatDateTime(
                                                    intune.snapshot
                                                        .lastSyncDateTime,
                                                    'Not reported',
                                                )}
                                                detail={
                                                    intune.snapshot
                                                        .managedDeviceOwnerType
                                                        ? `Owner: ${intune.snapshot.managedDeviceOwnerType}`
                                                        : null
                                                }
                                            />
                                        </dl>
                                    </div>
                                )}
                            </>
                        )}
                    </CardContent>
                </Card>
            )}

            {/* ── Link Microsoft Intune dialog ──────────────────── */}
            <Dialog open={intuneDialogOpen} onOpenChange={setIntuneDialogOpen}>
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                >
                    <DialogHeader>
                        <DialogTitle>
                            {intune?.linked
                                ? 'Update Microsoft Intune link'
                                : 'Link Microsoft Intune device'}
                        </DialogTitle>
                        <DialogDescription>
                            Bind this computer to a Microsoft Intune managed
                            device UUID for read-only compliance and encryption
                            visibility.
                        </DialogDescription>
                    </DialogHeader>
                    <div className="space-y-4">
                        <div className="space-y-1.5">
                            <Label htmlFor="intune-managed-device-id">
                                Managed device ID (UUID)
                            </Label>
                            <Input
                                id="intune-managed-device-id"
                                value={intuneDeviceId}
                                onChange={(e) => {
                                    setIntuneDeviceId(e.target.value);
                                    setIntuneError(null);
                                }}
                                placeholder="e.g. 00000000-0000-0000-0000-000000000000"
                                autoComplete="off"
                            />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="intune-link-reason">
                                Notes (optional)
                            </Label>
                            <Input
                                id="intune-link-reason"
                                value={intuneReason}
                                onChange={(e) =>
                                    setIntuneReason(e.target.value)
                                }
                                placeholder="e.g. Matched serial number in Intune portal"
                            />
                        </div>
                        {intuneError && (
                            <p
                                role="alert"
                                className="text-sm text-status-critical"
                            >
                                {intuneError}
                            </p>
                        )}
                    </div>
                    <DialogFooter>
                        <Button
                            variant="ghost"
                            onClick={() => setIntuneDialogOpen(false)}
                            disabled={intuneSubmitting}
                        >
                            Cancel
                        </Button>
                        <Button
                            onClick={submitIntuneLink}
                            disabled={
                                intuneSubmitting || !intuneDeviceId.trim()
                            }
                        >
                            {intuneSubmitting ? 'Saving…' : 'Save link'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>

            {/* ── Remove Intune link ReasonDialog ───────────────── */}
            <ReasonDialog
                open={intuneUnlinkOpen}
                onClose={() => setIntuneUnlinkOpen(false)}
                title="Remove Microsoft Intune link"
                description="Removing this link clears retained Microsoft Intune compliance observations from this computer record. Enter a reason of at least 10 characters."
                label="Reason"
                placeholder="Explain why this Microsoft Intune link is being removed…"
                confirmLabel="Remove link"
                onConfirm={confirmIntuneUnlink}
            />

            {/* ── Revoke computer agent ReasonDialog ────────────── */}
            <ReasonDialog
                open={revokeOpen}
                onClose={() => setRevokeOpen(false)}
                title="Revoke computer agent"
                description={`Revoking the agent immediately invalidates ${deviceName}’s machine credential and stops future heartbeats or commands until re-enrolled. Enter a reason of at least 10 characters.`}
                label="Reason"
                placeholder="Explain why this computer agent is being revoked…"
                confirmLabel="Revoke agent"
                onConfirm={confirmRevokeAgent}
            />
        </div>
    );
}
