import { SettingsNotice } from '@/components/settings/settings-notice';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { formatDateTime } from '@/lib/datetime';
import { readableKey } from '@/lib/security-devices-labels';
import axios from 'axios';
import { Activity, AlertTriangle, Clock3, Gauge } from 'lucide-react';
import { useEffect, useId, useState } from 'react';

export type HistoryRangeKey = '1h' | '24h' | '7d' | '30d';

export type MonitorHistoryTarget = {
    monitorId: number;
    monitorName: string;
    deviceName?: string | null;
};

export type MonitorHistoryPoint = {
    observed_at: string | null;
    state: string;
    value: number | null;
    raw_value: number | null;
    unit: string | null;
    latency_ms: number | null;
    in_bps: number | null;
    out_bps: number | null;
    in_utilization_pct: number | null;
    out_utilization_pct: number | null;
    errors: number | null;
    discards: number | null;
    packet_loss_pct: number | null;
};

export type MonitorHistoryPayload = {
    monitor: {
        id: number;
        name: string;
        kind: string;
        enabled: boolean;
        reported_state: string;
        effective_state: string;
        stale_after_seconds: number;
        last_observation_at: string | null;
        device: {
            id: number;
            name: string;
            domain: string | null;
            category: string | null;
            href: string;
        } | null;
    };
    range: {
        key: HistoryRangeKey;
        seconds: number;
        from: string;
        to: string;
        total_observations: number;
        sampled_observations: number;
        shown_points: number;
        downsampled: boolean;
        truncated_read: boolean;
    };
    primary_metric: {
        key: string;
        label: string;
        unit: string | null;
        is_interface: boolean;
    };
    thresholds: {
        source_kind: string;
        rule_name: string | null;
        metric_key: string;
        direction: 'above' | 'below';
        warning_rising: number | null;
        warning_falling: number | null;
        critical_rising: number | null;
        critical_falling: number | null;
    };
    maintenance: {
        active_window: {
            id: number;
            name: string;
            starts_at: string | null;
            ends_at: string | null;
            timezone: string;
            life_safety_protected: boolean;
        } | null;
        upcoming_window: {
            id: number;
            name: string;
            starts_at: string | null;
            ends_at: string | null;
            timezone: string;
            life_safety_protected: boolean;
        } | null;
    };
    summary: {
        sample_count: number;
        min: number | null;
        max: number | null;
        average: number | null;
        latest: number | null;
        unit: string | null;
        availability_pct: number | null;
        healthy_samples: number;
        degraded_samples: number;
        failed_samples: number;
        freshness: string;
    };
    series: MonitorHistoryPoint[];
    transitions: Array<{
        observed_at: string | null;
        from_state: string;
        to_state: string;
        value: number | null;
        unit: string | null;
    }>;
    gaps: Array<{
        from: string;
        to: string;
        duration_seconds: number;
    }>;
};

const RANGE_OPTIONS: Array<{ key: HistoryRangeKey; label: string }> = [
    { key: '1h', label: 'Last hour' },
    { key: '24h', label: 'Last 24 hours' },
    { key: '7d', label: 'Last 7 days' },
    { key: '30d', label: 'Last 30 days' },
];

function stateVariant(state: string): StatusVariant {
    if (['healthy', 'fresh'].includes(state)) return 'success';
    if (['failed', 'critical'].includes(state)) return 'critical';
    if (['degraded', 'stale', 'suppressed', 'paused'].includes(state)) {
        return 'warning';
    }
    return 'neutral';
}

function formatMetric(value: number | null, unit: string | null): string {
    if (value === null) return 'Not numeric';
    return unit ? `${value} ${unit}` : String(value);
}

function formatRate(bps: number | null): string {
    if (bps === null) return 'Not collected';
    if (bps >= 1_000_000_000) return `${(bps / 1_000_000_000).toFixed(2)} Gbps`;
    if (bps >= 1_000_000) return `${(bps / 1_000_000).toFixed(2)} Mbps`;
    if (bps >= 1_000) return `${(bps / 1_000).toFixed(1)} kbps`;
    return `${Math.round(bps)} bps`;
}

function formatDuration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    if (seconds < 3600) return `${Math.round(seconds / 60)} min`;
    const hours = (seconds / 3600).toFixed(1);
    return `${hours} hr`;
}

function HistoryChart({ history }: { history: MonitorHistoryPayload }) {
    const chartTitleId = useId();
    const numericPoints = history.series.filter(
        (point): point is MonitorHistoryPoint & { value: number } =>
            typeof point.value === 'number' && Number.isFinite(point.value),
    );

    if (numericPoints.length < 2) {
        return (
            <div className="rounded-xl border border-dashed border-border bg-muted/20 p-4 text-sm text-muted-foreground">
                {history.series.length === 0
                    ? 'No retained observations were recorded in this window.'
                    : 'At least two numeric observations are required to plot a trend line. State history and summary values remain available below.'}
            </div>
        );
    }

    const values = numericPoints.map((point) => point.value);
    const outValues = history.primary_metric.is_interface
        ? history.series
              .map((point) => point.out_utilization_pct)
              .filter(
                  (val): val is number =>
                      typeof val === 'number' && Number.isFinite(val),
              )
        : [];
    const boundsCandidates = [
        ...values,
        ...outValues,
        ...(history.thresholds.warning_rising !== null
            ? [history.thresholds.warning_rising]
            : []),
        ...(history.thresholds.critical_rising !== null
            ? [history.thresholds.critical_rising]
            : []),
    ];
    const minVal = Math.min(...boundsCandidates);
    const maxVal = Math.max(...boundsCandidates);
    const span = maxVal - minVal || 1;
    const paddedMin = Math.min(0, minVal);
    const paddedMax = maxVal + span * 0.12;
    const domainSpan = paddedMax - paddedMin || 1;

    const width = 760;
    const height = 210;
    const padLeft = 48;
    const padRight = 18;
    const padTop = 18;
    const padBottom = 26;
    const plotWidth = width - padLeft - padRight;
    const plotHeight = height - padTop - padBottom;

    const xAt = (index: number, count: number) =>
        padLeft + (count <= 1 ? 0 : (index / (count - 1)) * plotWidth);
    const yAt = (value: number) =>
        padTop + plotHeight - ((value - paddedMin) / domainSpan) * plotHeight;

    const primaryPolyline = numericPoints
        .map(
            (point, index) =>
                `${xAt(index, numericPoints.length).toFixed(1)},${yAt(point.value).toFixed(1)}`,
        )
        .join(' ');

    const secondaryPoints = history.primary_metric.is_interface
        ? history.series.filter(
              (
                  point,
              ): point is MonitorHistoryPoint & {
                  out_utilization_pct: number;
              } =>
                  typeof point.out_utilization_pct === 'number' &&
                  Number.isFinite(point.out_utilization_pct),
          )
        : [];
    const secondaryPolyline =
        secondaryPoints.length >= 2
            ? secondaryPoints
                  .map(
                      (point, index) =>
                          `${xAt(index, secondaryPoints.length).toFixed(1)},${yAt(point.out_utilization_pct).toFixed(1)}`,
                  )
                  .join(' ')
            : null;

    const unit = history.primary_metric.unit ?? '';

    return (
        <Card className="py-4 shadow-none">
            <CardContent className="space-y-2 px-4">
                <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-muted-foreground">
                <span className="font-medium text-foreground">
                    {history.primary_metric.label}
                    {unit ? ` (${unit})` : ''}
                </span>
                <div className="flex flex-wrap items-center gap-3">
                    <span className="inline-flex items-center gap-1.5">
                        <span
                            aria-hidden="true"
                            className="inline-block h-0.5 w-4"
                            style={{ backgroundColor: 'var(--chart-1)' }}
                        />
                        {history.primary_metric.is_interface
                            ? 'Inbound / peak utilisation'
                            : 'Observed value'}
                    </span>
                    {secondaryPolyline ? (
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                aria-hidden="true"
                                className="inline-block h-0.5 w-4 border-t-2 border-dashed"
                                style={{ borderColor: 'var(--chart-2)' }}
                            />
                            Outbound utilisation
                        </span>
                    ) : null}
                    {history.thresholds.warning_rising !== null ? (
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                aria-hidden="true"
                                className="inline-block h-0.5 w-4 border-t border-dashed"
                                style={{ borderColor: 'var(--status-warning)' }}
                            />
                            Warning ({history.thresholds.warning_rising}
                            {unit})
                        </span>
                    ) : null}
                    {history.thresholds.critical_rising !== null ? (
                        <span className="inline-flex items-center gap-1.5">
                            <span
                                aria-hidden="true"
                                className="inline-block h-0.5 w-4 border-t-2 border-dotted"
                                style={{
                                    borderColor: 'var(--status-critical)',
                                }}
                            />
                            Critical ({history.thresholds.critical_rising}
                            {unit})
                        </span>
                    ) : null}
                </div>
            </div>
            <svg
                viewBox={`0 0 ${width} ${height}`}
                role="img"
                aria-labelledby={chartTitleId}
                className="h-52 w-full overflow-visible"
            >
                <title id={chartTitleId}>
                    {history.monitor.name} trend over {history.range.key}
                </title>
                {[0, 0.5, 1].map((step) => {
                    const value = paddedMin + domainSpan * step;
                    const y = yAt(value);
                    return (
                        <g key={step}>
                            <line
                                x1={padLeft}
                                y1={y}
                                x2={width - padRight}
                                y2={y}
                                stroke="var(--border)"
                                strokeWidth="1"
                            />
                            <text
                                x={padLeft - 6}
                                y={y + 4}
                                textAnchor="end"
                                fill="var(--muted-foreground)"
                                fontSize="11"
                            >
                                {Math.round(value * 10) / 10}
                            </text>
                        </g>
                    );
                })}
                {history.thresholds.warning_rising !== null ? (
                    <line
                        x1={padLeft}
                        y1={yAt(history.thresholds.warning_rising)}
                        x2={width - padRight}
                        y2={yAt(history.thresholds.warning_rising)}
                        stroke="var(--status-warning)"
                        strokeWidth="1.5"
                        strokeDasharray="6 4"
                    />
                ) : null}
                {history.thresholds.critical_rising !== null ? (
                    <line
                        x1={padLeft}
                        y1={yAt(history.thresholds.critical_rising)}
                        x2={width - padRight}
                        y2={yAt(history.thresholds.critical_rising)}
                        stroke="var(--status-critical)"
                        strokeWidth="1.75"
                        strokeDasharray="2 3"
                    />
                ) : null}
                <polyline
                    fill="none"
                    stroke="var(--chart-1)"
                    strokeWidth="2.25"
                    strokeLinejoin="round"
                    strokeLinecap="round"
                    points={primaryPolyline}
                />
                {secondaryPolyline ? (
                    <polyline
                        fill="none"
                        stroke="var(--chart-2)"
                        strokeWidth="2"
                        strokeDasharray="4 3"
                        strokeLinejoin="round"
                        strokeLinecap="round"
                        points={secondaryPolyline}
                    />
                ) : null}
                </svg>
            </CardContent>
        </Card>
    );
}

export function MonitorHistoryDialog({
    target,
    open,
    onOpenChange,
}: {
    target: MonitorHistoryTarget | null;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [range, setRange] = useState<HistoryRangeKey>('24h');
    const [history, setHistory] = useState<MonitorHistoryPayload | null>(null);
    const [loading, setLoading] = useState(false);
    const [error, setError] = useState('');

    useEffect(() => {
        if (!open || !target) {
            setHistory(null);
            setError('');
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        setError('');
        axios
            .get<{ history: MonitorHistoryPayload }>(
                `/security-devices/monitoring/monitors/${target.monitorId}/history`,
                {
                    params: { range },
                    signal: controller.signal,
                },
            )
            .then((response) => {
                if (!controller.signal.aborted) {
                    setHistory(response.data.history);
                }
            })
            .catch((failure) => {
                if (!controller.signal.aborted) {
                    setHistory(null);
                    setError(
                        axios.isAxiosError(failure)
                            ? String(
                                  failure.response?.data?.message ??
                                      'Retained history could not be loaded for this check.',
                              )
                            : 'Retained history could not be loaded for this check.',
                    );
                }
            })
            .finally(() => {
                if (!controller.signal.aborted) {
                    setLoading(false);
                }
            });

        return () => controller.abort();
    }, [open, range, target]);

    const latestPoint = history?.series[history.series.length - 1] ?? null;

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent
                className="max-h-[90vh] overflow-y-auto"
                style={{
                    maxWidth: 'min(92vw, 900px)',
                    width: 'min(92vw, 900px)',
                }}
            >
                <DialogHeader>
                    <DialogTitle>
                        {target
                            ? `Retained history · ${target.monitorName}`
                            : 'Retained history'}
                    </DialogTitle>
                    <DialogDescription>
                        {target?.deviceName
                            ? `${target.deviceName} · bounded retained observations and state transitions.`
                            : 'Bounded retained observations and state transitions.'}
                    </DialogDescription>
                </DialogHeader>

                <div
                    role="group"
                    aria-label="History time range"
                    className="flex flex-wrap items-center gap-2"
                >
                    {RANGE_OPTIONS.map((option) => (
                        <Button
                            key={option.key}
                            type="button"
                            size="sm"
                            variant={
                                range === option.key ? 'default' : 'outline'
                            }
                            aria-pressed={range === option.key}
                            onClick={() => setRange(option.key)}
                        >
                            {option.label}
                        </Button>
                    ))}
                </div>

                {loading ? (
                    <p
                        role="status"
                        className="rounded-lg border border-border bg-muted/20 p-4 text-sm text-muted-foreground"
                    >
                        Loading retained history…
                    </p>
                ) : null}

                {error ? (
                    <p
                        role="alert"
                        className="rounded-lg border border-status-critical/30 bg-status-critical-bg p-4 text-sm text-status-critical"
                    >
                        {error}
                    </p>
                ) : null}

                {!loading && !error && history ? (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            <StatusBadge
                                variant={stateVariant(
                                    history.monitor.effective_state,
                                )}
                            >
                                {readableKey(history.monitor.effective_state)}
                            </StatusBadge>
                            <StatusBadge
                                variant={stateVariant(
                                    history.summary.freshness,
                                )}
                            >
                                {readableKey(history.summary.freshness)}
                            </StatusBadge>
                            {history.maintenance.active_window ? (
                                <StatusBadge variant="warning">
                                    Active maintenance:{' '}
                                    {history.maintenance.active_window.name}
                                </StatusBadge>
                            ) : history.maintenance.upcoming_window ? (
                                <StatusBadge variant="info">
                                    Upcoming maintenance:{' '}
                                    {history.maintenance.upcoming_window.name}
                                </StatusBadge>
                            ) : null}
                            {history.range.downsampled ? (
                                <span className="text-caption">
                                    Showing {history.range.shown_points}{' '}
                                    representative points from{' '}
                                    {history.range.total_observations} retained
                                    observations
                                </span>
                            ) : null}
                        </div>

                        <HistoryChart history={history} />

                        {history.gaps.length > 0 ? (
                            <SettingsNotice role="note">
                                <span className="inline-flex items-center gap-1.5 font-medium">
                                    <AlertTriangle
                                        className="size-4 text-status-warning"
                                        aria-hidden="true"
                                    />
                                    {history.gaps.length}{' '}
                                    {history.gaps.length === 1
                                        ? 'collection gap'
                                        : 'collection gaps'}{' '}
                                    detected in this window
                                </span>{' '}
                                · latest gap{' '}
                                {formatDateTime(
                                    history.gaps[history.gaps.length - 1].from,
                                )}{' '}
                                to{' '}
                                {formatDateTime(
                                    history.gaps[history.gaps.length - 1].to,
                                )}{' '}
                                (
                                {formatDuration(
                                    history.gaps[history.gaps.length - 1]
                                        .duration_seconds,
                                )}
                                ).
                            </SettingsNotice>
                        ) : null}

                        <div className="grid gap-4 md:grid-cols-2">
                            <ReviewCard icon={Gauge} title="Summary metrics">
                                <ReviewRow
                                    label="Availability"
                                    value={
                                        history.summary.availability_pct ===
                                        null
                                            ? 'No samples'
                                            : `${history.summary.availability_pct}% (${history.summary.healthy_samples} healthy, ${history.summary.degraded_samples} degraded, ${history.summary.failed_samples} failed)`
                                    }
                                />
                                <ReviewRow
                                    label="Latest value"
                                    value={formatMetric(
                                        history.summary.latest,
                                        history.summary.unit,
                                    )}
                                />
                                <ReviewRow
                                    label="Average"
                                    value={formatMetric(
                                        history.summary.average,
                                        history.summary.unit,
                                    )}
                                />
                                <ReviewRow
                                    label="Minimum / maximum"
                                    value={`${formatMetric(history.summary.min, history.summary.unit)} / ${formatMetric(history.summary.max, history.summary.unit)}`}
                                />
                                <ReviewRow
                                    label="Retained samples"
                                    value={history.summary.sample_count}
                                />
                            </ReviewCard>

                            <ReviewCard
                                icon={Activity}
                                title={
                                    history.primary_metric.is_interface
                                        ? 'Interface counters & thresholds'
                                        : 'Thresholds & observation bounds'
                                }
                            >
                                <ReviewRow
                                    label="Threshold source"
                                    value={
                                        history.thresholds.rule_name ??
                                        readableKey(
                                            history.thresholds.source_kind,
                                        )
                                    }
                                />
                                <ReviewRow
                                    label="Warning / critical"
                                    value={
                                        history.thresholds.warning_rising ===
                                            null &&
                                        history.thresholds.critical_rising ===
                                            null
                                            ? 'Reported check state only'
                                            : `Warning ${history.thresholds.warning_rising ?? 'none'} · critical ${history.thresholds.critical_rising ?? 'none'}`
                                    }
                                />
                                {history.primary_metric.is_interface &&
                                latestPoint ? (
                                    <>
                                        <ReviewRow
                                            label="Latest inbound / outbound"
                                            value={`${formatRate(latestPoint.in_bps)} / ${formatRate(latestPoint.out_bps)}`}
                                        />
                                        <ReviewRow
                                            label="Errors / discards"
                                            value={`${latestPoint.errors ?? 0} errors · ${latestPoint.discards ?? 0} discards`}
                                        />
                                    </>
                                ) : (
                                    <ReviewRow
                                        label="Last observed"
                                        value={
                                            history.monitor.last_observation_at
                                                ? formatDateTime(
                                                      history.monitor
                                                          .last_observation_at,
                                                  )
                                                : 'Not observed'
                                        }
                                    />
                                )}
                            </ReviewCard>
                        </div>

                        <ReviewCard icon={Clock3} title="State transitions">
                            {history.transitions.length === 0 ? (
                                <p className="text-sm text-muted-foreground">
                                    No state changes occurred during this
                                    window.
                                </p>
                            ) : (
                                <div className="space-y-2">
                                    {history.transitions
                                        .slice(0, 12)
                                        .map((transition, index) => (
                                            <div
                                                key={`${transition.observed_at}-${index}`}
                                                className="flex flex-wrap items-center justify-between gap-2 border-b border-border py-1.5 text-sm last:border-b-0"
                                            >
                                                <div className="flex flex-wrap items-center gap-2">
                                                    <StatusBadge
                                                        variant={stateVariant(
                                                            transition.from_state,
                                                        )}
                                                    >
                                                        {readableKey(
                                                            transition.from_state,
                                                        )}
                                                    </StatusBadge>
                                                    <span aria-hidden="true">
                                                        →
                                                    </span>
                                                    <StatusBadge
                                                        variant={stateVariant(
                                                            transition.to_state,
                                                        )}
                                                    >
                                                        {readableKey(
                                                            transition.to_state,
                                                        )}
                                                    </StatusBadge>
                                                    {transition.value !==
                                                    null ? (
                                                        <span className="text-caption">
                                                            (
                                                            {formatMetric(
                                                                transition.value,
                                                                transition.unit,
                                                            )}
                                                            )
                                                        </span>
                                                    ) : null}
                                                </div>
                                                <span className="text-caption">
                                                    {transition.observed_at
                                                        ? formatDateTime(
                                                              transition.observed_at,
                                                          )
                                                        : 'Time unavailable'}
                                                </span>
                                            </div>
                                        ))}
                                </div>
                            )}
                        </ReviewCard>
                    </div>
                ) : null}

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        onClick={() => onOpenChange(false)}
                    >
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
