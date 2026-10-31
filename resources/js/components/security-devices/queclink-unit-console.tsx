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
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime, formatRelative } from '@/lib/datetime';
import type {
    QueclinkConsoleCommandSpec,
    QueclinkUnitConsoleData,
} from '@/pages/security-devices/devices/device-profile';
import axios from 'axios';
import {
    AlertTriangle,
    ExternalLink,
    Loader2,
    MapPin,
    RefreshCw,
    Satellite,
    Send,
    Settings2,
    Terminal,
} from 'lucide-react';
import { useEffect, useState } from 'react';

function connectionVariant(state: string | null | undefined): StatusVariant {
    if (state === 'connected' || state === 'paired' || state === 'active') {
        return 'success';
    }
    if (state === 'stale' || state === 'pending' || state === 'queued' || state === 'sent') {
        return 'warning';
    }
    if (state === 'failed' || state === 'expired' || state === 'offline') {
        return 'critical';
    }
    return 'neutral';
}

function commandStatusVariant(status: string): StatusVariant {
    if (status === 'fulfilled' || status === 'acked') {
        return 'success';
    }
    if (status === 'queued' || status === 'sent') {
        return 'warning';
    }
    if (status === 'failed' || status === 'expired') {
        return 'critical';
    }
    return 'neutral';
}

function formatKeyLabel(key: string): string {
    return key
        .replace(/_/g, ' ')
        .replace(/\b\w/, (letter) => letter.toUpperCase());
}

function SettingBlock({
    title,
    values,
}: {
    title: string;
    values: Record<string, unknown> | null;
}) {
    const entries = Object.entries(values ?? {}).filter(
        ([, val]) => val !== null && val !== undefined && val !== '',
    );

    return (
        <div className="rounded-lg bg-muted/30 p-3">
            <p className="text-xs font-semibold text-foreground">{title}</p>
            {entries.length === 0 ? (
                <p className="mt-1 text-xs text-muted-foreground">
                    Not reported yet
                </p>
            ) : (
                <dl className="mt-2 space-y-1 text-xs">
                    {entries.map(([key, value]) => (
                        <div
                            key={key}
                            className="flex items-baseline justify-between gap-2"
                        >
                            <dt className="text-muted-foreground">
                                {formatKeyLabel(key)}
                            </dt>
                            <dd className="font-mono text-foreground">
                                {typeof value === 'boolean'
                                    ? value
                                        ? 'Yes'
                                        : 'No'
                                    : String(value)}
                            </dd>
                        </div>
                    ))}
                </dl>
            )}
        </div>
    );
}

export function QueclinkUnitConsole({
    consoleData,
}: {
    consoleData: QueclinkUnitConsoleData;
}) {
    const [data, setData] = useState<QueclinkUnitConsoleData>(consoleData);
    const [refreshing, setRefreshing] = useState(false);
    const [sendingKey, setSendingKey] = useState<string | null>(null);
    const [feedback, setFeedback] = useState<{
        tone: 'success' | 'error';
        text: string;
    } | null>(null);

    const [readSection, setReadSection] = useState<string>(
        consoleData.commands.sections[0] ?? 'ALL',
    );
    const [riskyTarget, setRiskyTarget] =
        useState<QueclinkConsoleCommandSpec | null>(null);
    const [intervalSeconds, setIntervalSeconds] = useState('300');
    const [watchdogDays, setWatchdogDays] = useState('7');
    const [watchdogTime, setWatchdogTime] = useState('0200');
    const [reason, setReason] = useState('');
    const [dialogError, setDialogError] = useState<string | null>(null);

    useEffect(() => {
        setData(consoleData);
    }, [consoleData]);

    const refreshConsole = async () => {
        setRefreshing(true);
        setFeedback(null);
        try {
            const response = await axios.get<QueclinkUnitConsoleData>(
                data.fetch_url,
            );
            setData(response.data);
        } catch {
            setFeedback({
                tone: 'error',
                text: 'Could not refresh the unit console right now.',
            });
        } finally {
            setRefreshing(false);
        }
    };

    const submitCommand = async (
        spec: QueclinkConsoleCommandSpec,
        options?: {
            parameters?: Record<string, unknown>;
            reason?: string;
            confirmed?: boolean;
        },
    ): Promise<boolean> => {
        setSendingKey(spec.key);
        setFeedback(null);
        setDialogError(null);
        try {
            const response = await axios.post<{
                message: string;
                console?: QueclinkUnitConsoleData;
            }>(data.command_url, {
                command: spec.key,
                parameters: options?.parameters ?? {},
                reason: options?.reason ?? null,
                confirmed: options?.confirmed ?? false,
            });
            if (response.data.console) {
                setData(response.data.console);
            }
            setFeedback({
                tone: 'success',
                text: response.data.message,
            });
            return true;
        } catch (error: unknown) {
            let message = 'Could not queue command for this tracker.';
            if (axios.isAxiosError(error) && error.response?.data) {
                const payload = error.response.data as {
                    message?: string;
                    errors?: Record<string, string[]>;
                };
                const firstFieldError = payload.errors
                    ? Object.values(payload.errors).flat()[0]
                    : undefined;
                message = firstFieldError ?? payload.message ?? message;
            }
            if (options?.confirmed) {
                setDialogError(message);
            } else {
                setFeedback({
                    tone: 'error',
                    text: message,
                });
            }
            return false;
        } finally {
            setSendingKey(null);
        }
    };

    const openRiskyDialog = (spec: QueclinkConsoleCommandSpec) => {
        setRiskyTarget(spec);
        setReason('');
        setDialogError(null);
    };

    const confirmRiskyCommand = async () => {
        if (!riskyTarget) return;
        const trimmedReason = reason.trim();
        if (trimmedReason.length < 4) {
            setDialogError(
                'Enter a brief reason (at least 4 characters) for the audit log.',
            );
            return;
        }

        const parameters: Record<string, unknown> = {};
        if (riskyTarget.key === 'update_reporting_interval') {
            const parsed = Number.parseInt(intervalSeconds, 10);
            if (!Number.isFinite(parsed) || parsed < 30 || parsed > 86400) {
                setDialogError(
                    'Enter a reporting interval between 30 and 86,400 seconds.',
                );
                return;
            }
            parameters.interval_seconds = parsed;
        } else if (riskyTarget.key === 'update_watchdog') {
            const parsedDays = Number.parseInt(watchdogDays, 10);
            if (!Number.isFinite(parsedDays) || parsedDays < 1 || parsedDays > 30) {
                setDialogError('Enter a reboot interval between 1 and 30 days.');
                return;
            }
            if (!/^(?:[01]\d|2[0-3])[0-5]\d$/.test(watchdogTime.trim())) {
                setDialogError(
                    'Enter a 24-hour HHMM reboot time such as 0200.',
                );
                return;
            }
            parameters.mode = 1;
            parameters.reboot_interval = parsedDays;
            parameters.reboot_time = watchdogTime.trim();
        }

        const ok = await submitCommand(riskyTarget, {
            parameters,
            reason: trimmedReason,
            confirmed: true,
        });
        if (ok) {
            setRiskyTarget(null);
            setReason('');
        }
    };

    const safeCommands = data.commands.supported.filter((cmd) => !cmd.risky);
    const riskyCommands = data.commands.supported.filter((cmd) => cmd.risky);

    return (
        <div className="space-y-5">
            <Card>
                <CardHeader>
                    <div className="flex flex-wrap items-start justify-between gap-3">
                        <div className="space-y-1">
                            <CardTitle className="flex items-center gap-2">
                                <Satellite className="h-4 w-4" />
                                Unit console
                            </CardTitle>
                            <CardDescription>
                                Protocol family {data.family} ·{' '}
                                {data.protocol_spec}
                            </CardDescription>
                        </div>
                        <div className="flex flex-wrap items-center gap-2">
                            {data.unit ? (
                                <StatusBadge
                                    variant={connectionVariant(
                                        data.unit.connection_state,
                                    )}
                                >
                                    {data.unit.connection_state.replace(
                                        /_/g,
                                        ' ',
                                    )}
                                </StatusBadge>
                            ) : (
                                <StatusBadge variant="neutral">
                                    Unpaired
                                </StatusBadge>
                            )}
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={refreshConsole}
                                disabled={refreshing}
                            >
                                {refreshing ? (
                                    <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                ) : (
                                    <RefreshCw className="mr-1.5 h-3.5 w-3.5" />
                                )}
                                Refresh console
                            </Button>
                        </div>
                    </div>
                </CardHeader>
                <CardContent>
                    {data.unit ? (
                        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                            <div className="rounded-lg bg-muted/30 p-3">
                                <p className="text-xs text-muted-foreground">
                                    Masked IMEI
                                </p>
                                <p className="mt-1 font-mono text-sm font-medium">
                                    {data.unit.imei_masked}
                                </p>
                            </div>
                            <div className="rounded-lg bg-muted/30 p-3">
                                <p className="text-xs text-muted-foreground">
                                    Model / firmware
                                </p>
                                <p className="mt-1 text-sm font-medium">
                                    {data.unit.model_hint ?? data.family}
                                    {data.unit.firmware_version
                                        ? ` · ${data.unit.firmware_version}`
                                        : ''}
                                </p>
                            </div>
                            <div className="rounded-lg bg-muted/30 p-3">
                                <p className="text-xs text-muted-foreground">
                                    Last seen
                                </p>
                                <p className="mt-1 text-sm font-medium">
                                    {formatRelative(
                                        data.unit.last_seen_at,
                                        Date.now(),
                                        'Not seen yet',
                                    )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatDateTime(
                                        data.unit.last_seen_at,
                                        'No timestamp',
                                    )}
                                </p>
                            </div>
                            <div className="rounded-lg bg-muted/30 p-3">
                                <p className="text-xs text-muted-foreground">
                                    Last frame
                                </p>
                                <p className="mt-1 text-sm font-medium">
                                    {formatRelative(
                                        data.unit.last_frame_at,
                                        Date.now(),
                                        'No frames received',
                                    )}
                                </p>
                                <p className="text-xs text-muted-foreground">
                                    {formatDateTime(
                                        data.unit.last_frame_at,
                                        'No timestamp',
                                    )}
                                </p>
                            </div>
                        </div>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            This tracker has not reported in to the Queclink
                            listener yet. Once it sends its first heartbeat or
                            position frame, unit details and raw messages will
                            appear here.
                        </p>
                    )}
                </CardContent>
            </Card>

            {feedback && (
                <Alert
                    variant={
                        feedback.tone === 'error' ? 'destructive' : 'default'
                    }
                >
                    <AlertDescription>{feedback.text}</AlertDescription>
                </Alert>
            )}

            <div className="grid gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <MapPin className="h-4 w-4" />
                            Last known position
                        </CardTitle>
                        <CardDescription>
                            {data.last_position.message}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="space-y-3">
                        {data.last_position.state === 'available' &&
                        data.last_position.latitude !== null &&
                        data.last_position.longitude !== null ? (
                            <>
                                <div className="grid grid-cols-2 gap-3 text-sm">
                                    <div className="rounded-lg bg-muted/30 p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Coordinates
                                        </p>
                                        <p className="mt-1 font-mono text-xs font-medium">
                                            {data.last_position.latitude.toFixed(
                                                6,
                                            )}
                                            ,{' '}
                                            {data.last_position.longitude.toFixed(
                                                6,
                                            )}
                                        </p>
                                    </div>
                                    <div className="rounded-lg bg-muted/30 p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Recorded
                                        </p>
                                        <p className="mt-1 text-xs font-medium">
                                            {formatDateTime(
                                                data.last_position.occurred_at,
                                                'Unknown time',
                                            )}
                                        </p>
                                    </div>
                                    <div className="rounded-lg bg-muted/30 p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Accuracy / speed
                                        </p>
                                        <p className="mt-1 text-xs font-medium">
                                            {data.last_position.accuracy_m !==
                                            null
                                                ? `±${data.last_position.accuracy_m} m`
                                                : 'Accuracy unknown'}
                                            {data.last_position.speed_kph !==
                                            null
                                                ? ` · ${data.last_position.speed_kph} km/h`
                                                : ''}
                                        </p>
                                    </div>
                                    <div className="rounded-lg bg-muted/30 p-3">
                                        <p className="text-xs text-muted-foreground">
                                            Battery
                                        </p>
                                        <p className="mt-1 text-xs font-medium">
                                            {data.last_position.battery_pct !==
                                            null
                                                ? `${data.last_position.battery_pct}%`
                                                : 'Not reported'}
                                        </p>
                                    </div>
                                </div>
                                {data.last_position.address && (
                                    <p className="text-xs text-muted-foreground">
                                        {data.last_position.address}
                                    </p>
                                )}
                                {data.last_position.map_url && (
                                    <div>
                                        <Button
                                            asChild
                                            variant="outline"
                                            size="sm"
                                        >
                                            <a
                                                href={
                                                    data.last_position.map_url
                                                }
                                                target="_blank"
                                                rel="noopener noreferrer"
                                            >
                                                <ExternalLink className="mr-1.5 h-3.5 w-3.5" />
                                                Open in OpenStreetMap
                                            </a>
                                        </Button>
                                    </div>
                                )}
                            </>
                        ) : (
                            <div className="rounded-lg bg-muted/30 p-3 text-xs text-muted-foreground">
                                {data.last_position.battery_pct !== null ? (
                                    <p>
                                        Latest battery reading:{' '}
                                        <span className="font-medium text-foreground">
                                            {data.last_position.battery_pct}%
                                        </span>
                                    </p>
                                ) : (
                                    <p>
                                        Coordinates are hidden or have not been
                                        reported yet.
                                    </p>
                                )}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Settings2 className="h-4 w-4" />
                            Unit settings
                        </CardTitle>
                        <CardDescription>
                            {data.settings.available
                                ? `Last reported ${formatDateTime(data.settings.received_at, 'recently')} (${data.settings.sections.join(', ') || 'summary'})`
                                : 'No configuration reply received yet. Request configuration below to read settings from the unit.'}
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="grid gap-3 sm:grid-cols-2">
                        <SettingBlock
                            title="Server (SRI)"
                            values={data.settings.summary.server}
                        />
                        <SettingBlock
                            title="Global (CFG)"
                            values={data.settings.summary.global}
                        />
                        <SettingBlock
                            title="Watchdog (DOG)"
                            values={data.settings.summary.watchdog}
                        />
                        <SettingBlock
                            title="Movement & firmware"
                            values={{
                                ...(data.settings.summary.non_movement ?? {}),
                                ...(data.settings.summary.firmware ?? {}),
                            }}
                        />
                    </CardContent>
                </Card>
            </div>

            <Card>
                <CardHeader>
                    <CardTitle className="flex items-center gap-2 text-base">
                        <Send className="h-4 w-4" />
                        Send command
                    </CardTitle>
                    <CardDescription>
                        Commands queue for delivery on the tracker&apos;s next
                        connection or heartbeat. Configuration changes and
                        reboots require confirmation and an audit reason.
                    </CardDescription>
                </CardHeader>
                <CardContent className="space-y-4">
                    {!data.commands.tracker_ready &&
                        data.commands.unavailable_reason && (
                            <Alert>
                                <AlertDescription>
                                    {data.commands.unavailable_reason}
                                </AlertDescription>
                            </Alert>
                        )}

                    <div className="space-y-3">
                        <p className="text-xs font-semibold text-muted-foreground">
                            Read-only queries
                        </p>
                        <div className="grid gap-3 md:grid-cols-3">
                            {safeCommands.map((spec) => (
                                <div
                                    key={spec.key}
                                    className="flex flex-col justify-between gap-3 rounded-lg bg-muted/30 p-3"
                                >
                                    <div className="space-y-1">
                                        <p className="text-sm font-medium">
                                            {spec.label}
                                        </p>
                                        <p className="text-xs text-muted-foreground">
                                            {spec.description}
                                        </p>
                                        <p className="font-mono text-[11px] text-muted-foreground">
                                            {spec.at_preview}
                                        </p>
                                    </div>
                                    <div className="space-y-2">
                                        {spec.key === 'read_config' && (
                                            <Select
                                                value={readSection}
                                                onValueChange={setReadSection}
                                                disabled={!spec.enabled}
                                            >
                                                <SelectTrigger
                                                    aria-label="Configuration section"
                                                    className="h-8 text-xs"
                                                >
                                                    <SelectValue />
                                                </SelectTrigger>
                                                <SelectContent>
                                                    {data.commands.sections.map(
                                                        (section) => (
                                                            <SelectItem
                                                                key={section}
                                                                value={section}
                                                            >
                                                                Section:{' '}
                                                                {section}
                                                            </SelectItem>
                                                        ),
                                                    )}
                                                </SelectContent>
                                            </Select>
                                        )}
                                        <Button
                                            type="button"
                                            variant="outline"
                                            size="sm"
                                            className="w-full"
                                            disabled={
                                                !spec.enabled ||
                                                sendingKey !== null
                                            }
                                            onClick={() =>
                                                void submitCommand(spec, {
                                                    parameters:
                                                        spec.key ===
                                                        'read_config'
                                                            ? {
                                                                  section:
                                                                      readSection,
                                                              }
                                                            : {},
                                                })
                                            }
                                        >
                                            {sendingKey === spec.key && (
                                                <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                                            )}
                                            {spec.label}
                                        </Button>
                                    </div>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="space-y-3 pt-2">
                        <p className="text-xs font-semibold text-muted-foreground">
                            Settings &amp; reboot (confirmation required)
                        </p>
                        <div className="grid gap-3 md:grid-cols-3">
                            {riskyCommands.map((spec) => (
                                <div
                                    key={spec.key}
                                    className="flex flex-col justify-between gap-3 rounded-lg bg-muted/30 p-3"
                                >
                                    <div className="space-y-1">
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="text-sm font-medium">
                                                {spec.label}
                                            </p>
                                            <StatusBadge variant="warning">
                                                Confirm
                                            </StatusBadge>
                                        </div>
                                        <p className="text-xs text-muted-foreground">
                                            {spec.description}
                                        </p>
                                        <p className="font-mono text-[11px] text-muted-foreground">
                                            {spec.at_preview}
                                        </p>
                                    </div>
                                    <Button
                                        type="button"
                                        variant="outline"
                                        size="sm"
                                        disabled={
                                            !spec.enabled || sendingKey !== null
                                        }
                                        onClick={() => openRiskyDialog(spec)}
                                    >
                                        {spec.label}…
                                    </Button>
                                </div>
                            ))}
                        </div>
                    </div>
                </CardContent>
            </Card>

            <div className="grid gap-5 lg:grid-cols-2">
                <Card>
                    <CardHeader>
                        <CardTitle className="text-base">
                            Recent command replies
                        </CardTitle>
                        <CardDescription>
                            Commands queued from Oblivion Findings and the
                            tracker&apos;s acknowledgements (passwords masked).
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {data.recent_commands.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No commands have been queued for this tracker
                                yet.
                            </p>
                        ) : (
                            <div className="space-y-2.5">
                                {data.recent_commands.map((cmd) => (
                                    <div
                                        key={cmd.id}
                                        className="space-y-1.5 rounded-lg bg-muted/30 p-3 text-xs"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <span className="font-mono font-semibold">
                                                {cmd.command_word} #
                                                {cmd.serial_number}
                                            </span>
                                            <StatusBadge
                                                variant={commandStatusVariant(
                                                    cmd.status,
                                                )}
                                            >
                                                {cmd.status}
                                            </StatusBadge>
                                        </div>
                                        <p className="break-all font-mono text-[11px] text-muted-foreground">
                                            {cmd.raw_command}
                                        </p>
                                        {cmd.ack_response && (
                                            <p className="break-all font-mono text-[11px] text-foreground">
                                                Reply: {cmd.ack_response}
                                            </p>
                                        )}
                                        {cmd.failed_reason && (
                                            <p className="text-status-critical-foreground">
                                                {cmd.failed_reason}
                                            </p>
                                        )}
                                        <p className="text-[11px] text-muted-foreground">
                                            Queued{' '}
                                            {formatDateTime(
                                                cmd.created_at,
                                                'Unknown time',
                                            )}
                                            {cmd.created_by
                                                ? ` by ${cmd.created_by}`
                                                : ''}
                                        </p>
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Terminal className="h-4 w-4" />
                            Recent raw messages
                        </CardTitle>
                        <CardDescription>
                            Inbound and outbound @Track ASCII frames with IMEIs,
                            passwords, and restricted coordinates masked.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {data.raw_messages.length === 0 ? (
                            <p className="text-sm text-muted-foreground">
                                No raw protocol messages recorded yet.
                            </p>
                        ) : (
                            <div className="space-y-2.5">
                                {data.raw_messages.map((msg) => (
                                    <div
                                        key={msg.id}
                                        className="space-y-1.5 rounded-lg bg-muted/30 p-3 text-xs"
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-2">
                                            <div className="flex items-center gap-2">
                                                <StatusBadge
                                                    variant={
                                                        msg.direction ===
                                                        'outbound'
                                                            ? 'warning'
                                                            : 'neutral'
                                                    }
                                                >
                                                    {msg.direction}
                                                </StatusBadge>
                                                <span className="font-mono font-semibold">
                                                    {msg.command_word ??
                                                        msg.frame_type ??
                                                        'FRAME'}
                                                </span>
                                            </div>
                                            <span className="text-[11px] text-muted-foreground">
                                                {formatDateTime(
                                                    msg.created_at,
                                                    'Unknown time',
                                                )}
                                            </span>
                                        </div>
                                        <p className="break-all font-mono text-[11px] text-foreground">
                                            {msg.raw_frame}
                                        </p>
                                        {!msg.parse_ok && msg.parse_error && (
                                            <p className="text-[11px] text-status-warning-foreground">
                                                Parse warning: {msg.parse_error}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>

            <Dialog
                open={riskyTarget !== null}
                onOpenChange={(open) => {
                    if (!open && sendingKey === null) {
                        setRiskyTarget(null);
                    }
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <AlertTriangle className="h-4 w-4 text-status-warning-foreground" />
                            Confirm {riskyTarget?.label.toLowerCase()}
                        </DialogTitle>
                        <DialogDescription>
                            {riskyTarget?.description}
                        </DialogDescription>
                    </DialogHeader>

                    {riskyTarget && (
                        <div className="space-y-4 py-1">
                            <div className="rounded-lg bg-muted/40 p-3 text-xs">
                                <p className="font-mono text-foreground">
                                    {riskyTarget.at_preview}
                                </p>
                                <p className="mt-1 text-muted-foreground">
                                    Reference: {riskyTarget.spec_ref}
                                </p>
                            </div>

                            {riskyTarget.key ===
                                'update_reporting_interval' && (
                                <div className="space-y-1.5">
                                    <Label htmlFor="queclink-interval-seconds">
                                        Reporting interval (seconds)
                                    </Label>
                                    <Input
                                        id="queclink-interval-seconds"
                                        type="number"
                                        min={30}
                                        max={86400}
                                        value={intervalSeconds}
                                        onChange={(event) =>
                                            setIntervalSeconds(
                                                event.target.value,
                                            )
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        Shorter intervals give fresher tracking
                                        updates but use more battery (30 to
                                        86,400 seconds).
                                    </p>
                                </div>
                            )}

                            {riskyTarget.key === 'update_watchdog' && (
                                <div className="grid gap-3 sm:grid-cols-2">
                                    <div className="space-y-1.5">
                                        <Label htmlFor="queclink-watchdog-days">
                                            Reboot interval (days)
                                        </Label>
                                        <Input
                                            id="queclink-watchdog-days"
                                            type="number"
                                            min={1}
                                            max={30}
                                            value={watchdogDays}
                                            onChange={(event) =>
                                                setWatchdogDays(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                    <div className="space-y-1.5">
                                        <Label htmlFor="queclink-watchdog-time">
                                            Reboot time (24h HHMM)
                                        </Label>
                                        <Input
                                            id="queclink-watchdog-time"
                                            value={watchdogTime}
                                            maxLength={4}
                                            placeholder="0200"
                                            onChange={(event) =>
                                                setWatchdogTime(
                                                    event.target.value,
                                                )
                                            }
                                        />
                                    </div>
                                </div>
                            )}

                            <div className="space-y-1.5">
                                <Label htmlFor="queclink-command-reason">
                                    Reason for audit log
                                </Label>
                                <Textarea
                                    id="queclink-command-reason"
                                    rows={3}
                                    placeholder="Explain why this command is needed…"
                                    value={reason}
                                    onChange={(event) =>
                                        setReason(event.target.value)
                                    }
                                />
                            </div>

                            {dialogError && (
                                <Alert variant="destructive">
                                    <AlertDescription>
                                        {dialogError}
                                    </AlertDescription>
                                </Alert>
                            )}
                        </div>
                    )}

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={sendingKey !== null}
                            onClick={() => setRiskyTarget(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            type="button"
                            variant="destructive"
                            disabled={sendingKey !== null}
                            onClick={() => void confirmRiskyCommand()}
                        >
                            {sendingKey !== null && (
                                <Loader2 className="mr-1.5 h-3.5 w-3.5 animate-spin" />
                            )}
                            Queue command
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
