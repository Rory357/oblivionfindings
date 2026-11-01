/**
 * Blocks the S&D device pages share (drawings pg-sdr-3, 16, 17, 20): a graph over time with one
 * point, how often a device answered (a donut), the drawn map of what it's connected to, and the
 * "next step" box. Built on the signature Surface: no coloured line on any edge (DESIGN.md
 * non-negotiable 8); state is a ring or dot beside words, never colour alone.
 */
import { KindIcon } from '@/components/security-devices/device-kind-icon';
import { Ring, Surface, type SgStatusTone, type SgTone } from '@/components/signature/blocks';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { formatDateOnly, formatTime, WORKER_TIMEZONE } from '@/lib/datetime';
import { seenTime } from '@/lib/security-devices/device-time';
import {
    deviceStateLabel,
    type DeviceKind,
    type DeviceState,
} from '@/lib/security-devices/device-words';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import { useMemo, useState, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Graph over time                                                    */
/* ------------------------------------------------------------------ */

export type TrendRangeKey = '24h' | '7d' | '30d';

export const TREND_RANGE_LABEL: Record<TrendRangeKey, string> = {
    '24h': '24 hours',
    '7d': '7 days',
    '30d': '30 days',
};

/** A point's time on the x axis, in NZ wall-clock time. */
function pointLabel(at: Date, step: number): string {
    if (step < 21_600) return formatTime(at).replace(':00', '');
    if (step < 86_400) {
        return at.toLocaleDateString('en-NZ', { timeZone: WORKER_TIMEZONE, weekday: 'short' });
    }
    return at.toLocaleDateString('en-NZ', { timeZone: WORKER_TIMEZONE, day: 'numeric', month: 'short' });
}

function pointWhen(at: Date, step: number): string {
    return step < 86_400 ? seenTime(at.toISOString()) : formatDateOnly(at.toISOString().slice(0, 10));
}

function round(value: number): string {
    return Number.isInteger(value) ? String(value) : value.toFixed(value < 10 ? 1 : 0);
}

function median(values: number[]): number {
    const sorted = [...values].sort((a, b) => a - b);
    const middle = Math.floor(sorted.length / 2);
    return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

/**
 * One series over time, in a surface: its range switch, the line with gaps where nothing was
 * recorded, and one sentence on what stands out. Hover or focus a point for its value.
 */
export function TrendChart({
    title,
    sub,
    start,
    step,
    points,
    unit,
    range,
    ranges,
    onRange,
    peakWords = 'Highest',
    action,
}: {
    title: string;
    sub?: ReactNode;
    start: string;
    step: number;
    points: (number | null)[];
    unit: string;
    range?: TrendRangeKey;
    ranges?: TrendRangeKey[];
    onRange?: (range: TrendRangeKey) => void;
    /** How the sentence names the top point, e.g. "Slowest". */
    peakWords?: string;
    action?: ReactNode;
}) {
    const [hover, setHover] = useState<number | null>(null);
    const startMs = new Date(start).getTime();
    const times = useMemo(() => points.map((_, index) => new Date(startMs + index * step * 1000)), [points, startMs, step]);
    const values = points.filter((value): value is number => value !== null);
    const max = unit === '%' ? 100 : Math.max(1, ...values) * 1.15;
    const width = 1000;
    const height = 180;
    const x = (index: number) => (points.length < 2 ? width / 2 : (index / (points.length - 1)) * width);
    const y = (value: number) => height - (value / max) * (height - 10);
    const suffix = unit ? (unit === '%' ? '%' : ` ${unit}`) : '';

    // Runs of recorded points become separate lines, so a gap stays a gap.
    const runs: string[] = [];
    let run: string[] = [];
    points.forEach((value, index) => {
        if (value === null) {
            if (run.length) runs.push(run.join(' '));
            run = [];
        } else {
            run.push(`${x(index).toFixed(1)},${y(value).toFixed(1)}`);
        }
    });
    if (run.length) runs.push(run.join(' '));

    const peak = values.length ? Math.max(...values) : null;
    const peakIndex = peak === null ? -1 : points.indexOf(peak);
    const usual = values.length ? median(values) : null;
    const latestIndex = points.reduce<number>((found, value, index) => (value !== null ? index : found), -1);
    const labelEvery = Math.max(1, Math.ceil(points.length / 8));
    const summary = `${title}: ${values.length} points over ${range ? TREND_RANGE_LABEL[range] : 'the period'}; usually ${usual !== null ? round(usual) : '—'}${suffix}, ${peakWords.toLowerCase()} ${peak !== null ? round(peak) : '—'}${suffix}`;

    return (
        <Surface title={title} sub={sub}>
            {ranges && range && onRange ? (
                <div className="flex justify-end px-[18px] pt-2">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        size="sm"
                        aria-label={`${title}: time range`}
                        value={range}
                        onValueChange={(next) => {
                            if (next) onRange(next as TrendRangeKey);
                        }}
                    >
                        {ranges.map((key) => (
                            <ToggleGroupItem key={key} value={key} className="px-2.5 text-xs">
                                {TREND_RANGE_LABEL[key]}
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                    {action}
                </div>
            ) : null}
            <div className="grid grid-cols-[52px_minmax(0,1fr)] gap-1.5 px-[18px] pt-3">
                <div aria-hidden="true" className="text-caption flex h-[180px] flex-col justify-between text-right tabular-nums">
                    <span>{round(max)}{suffix}</span>
                    <span>{round(max / 2)}{suffix}</span>
                    <span>0{suffix}</span>
                </div>
                <div className="relative h-[180px]" onMouseLeave={() => setHover(null)}>
                    <svg viewBox={`0 0 ${width} ${height}`} preserveAspectRatio="none" role="img" aria-label={summary} className="h-full w-full overflow-visible">
                        {[0, 0.5, 1].map((fraction) => (
                            <line key={fraction} x1="0" x2={width} y1={10 + fraction * (height - 10)} y2={10 + fraction * (height - 10)} className="stroke-border" strokeWidth={1} vectorEffect="non-scaling-stroke" />
                        ))}
                        {runs.map((line, index) =>
                            line.includes(' ') ? (
                                <polyline key={index} points={line} fill="none" style={{ stroke: 'var(--chart-1)' }} strokeWidth={2} strokeLinejoin="round" strokeLinecap="round" vectorEffect="non-scaling-stroke" />
                            ) : (
                                <circle key={index} cx={line.split(',')[0]} cy={line.split(',')[1]} r={4} style={{ fill: 'var(--chart-1)' }} vectorEffect="non-scaling-stroke" />
                            ),
                        )}
                        {hover !== null && points[hover] !== null ? (
                            <line x1={x(hover)} x2={x(hover)} y1={10} y2={height} className="stroke-muted-foreground" strokeWidth={1} strokeDasharray="3 3" vectorEffect="non-scaling-stroke" />
                        ) : null}
                    </svg>
                    {/* Hit areas wider than the line: one per point. */}
                    <div className="absolute inset-0 flex">
                        {points.map((value, index) => (
                            <span
                                key={index}
                                className="h-full flex-1"
                                onMouseEnter={() => setHover(index)}
                                aria-hidden="true"
                            />
                        ))}
                    </div>
                    {hover !== null ? (
                        <div
                            role="status"
                            className="pointer-events-none absolute top-1 z-10 -translate-x-1/2 rounded-md border border-border bg-popover px-2 py-1 text-xs whitespace-nowrap text-popover-foreground shadow-sm"
                            style={{ left: `${(x(hover) / width) * 100}%` }}
                        >
                            <span className="text-muted-foreground">{pointWhen(times[hover], step)}</span>{' '}
                            <b className="font-semibold tabular-nums">{points[hover] === null ? 'Not recorded' : `${round(points[hover] as number)}${suffix}`}</b>
                        </div>
                    ) : null}
                </div>
            </div>
            <div aria-hidden="true" className="text-caption flex justify-between py-1 pr-[18px] pl-[76px]">
                {times.map((time, index) =>
                    index % labelEvery === 0 || index === points.length - 1 ? (
                        <span key={index}>{index === points.length - 1 ? 'Now' : pointLabel(time, step)}</span>
                    ) : null,
                )}
            </div>
            <p className="text-subtle border-t border-border px-[18px] py-3">
                {peak !== null && usual !== null && peakIndex >= 0 ? (
                    <>
                        <b className="font-semibold text-foreground">
                            {peakWords} {pointWhen(times[peakIndex], step)}: {round(peak)}
                            {suffix}.
                        </b>{' '}
                        Usually about {round(usual)}
                        {suffix}
                        {latestIndex >= 0 && latestIndex !== peakIndex ? `; the latest is ${round(points[latestIndex] as number)}${suffix}` : ''}.
                    </>
                ) : (
                    'Nothing recorded in this range.'
                )}
            </p>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  How often it answered                                              */
/* ------------------------------------------------------------------ */

/** Answered and missed checks as a donut, with the share in the middle. */
export function AnsweredDonut({
    title,
    answered,
    missed,
    percent,
    days,
    lastMissedAt,
}: {
    title: string;
    answered: number;
    missed: number;
    percent: number;
    days: number;
    lastMissedAt: string | null;
}) {
    const total = answered + missed || 1;
    const answeredShare = (answered / total) * 100;
    const words = (count: number) => `${count.toLocaleString('en-NZ')} ${count === 1 ? 'check' : 'checks'}`;

    return (
        <Surface title={title} sub={days < 30 ? `Since it came to this Site, ${days} ${days === 1 ? 'day' : 'days'} ago` : undefined}>
            <div className="flex items-center gap-5 px-[18px] py-4">
                <div
                    role="img"
                    aria-label={`Answered ${percent}% of its checks`}
                    className="relative grid size-[120px] shrink-0 place-items-center rounded-full"
                    style={{
                        background: `conic-gradient(var(--status-success) 0% ${answeredShare}%, var(--status-critical) ${answeredShare}% 100%)`,
                    }}
                >
                    <span className="absolute inset-[18px] rounded-full bg-card" />
                    <span className="relative grid justify-items-center">
                        <b className="text-xl font-bold tracking-tight tabular-nums">{percent}%</b>
                        <small className="text-caption">answered</small>
                    </span>
                </div>
                <dl className="grid min-w-0 flex-1 gap-2 text-sm">
                    <div className="flex items-center gap-2">
                        <Ring tone="success" />
                        <dt>Answered</dt>
                        <dd className="ml-auto font-semibold tabular-nums">{words(answered)}</dd>
                    </div>
                    <div className="flex items-center gap-2">
                        <Ring tone={missed > 0 ? 'critical' : 'none'} />
                        <dt>Missed</dt>
                        <dd className="ml-auto font-semibold tabular-nums">{words(missed)}</dd>
                    </div>
                </dl>
            </div>
            <p className="text-subtle border-t border-border px-[18px] py-3">
                {missed === 0 ? (
                    'It answered every check.'
                ) : lastMissedAt ? (
                    <>
                        Last missed check: <b className="font-semibold text-foreground">{seenTime(lastMissedAt)}</b>.
                    </>
                ) : (
                    'Some checks were missed.'
                )}
            </p>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  The drawn map                                                      */
/* ------------------------------------------------------------------ */

export type MapNodeData = {
    id: number | string;
    name: string;
    kind: DeviceKind | 'hub' | 'site';
    sub: string;
    state: DeviceState | 'hub_online' | 'hub_offline';
    href: string | null;
};

const NODE_TONE: Record<string, SgTone> = {
    online: 'success',
    hub_online: 'success',
    problem: 'warning',
    offline: 'critical',
    hub_offline: 'critical',
    cant_check: 'none',
    not_watched: 'none',
    retired: 'none',
};

function nodeStateWords(state: MapNodeData['state']): string {
    if (state === 'hub_online') return 'Online';
    if (state === 'hub_offline') return 'Offline';
    return deviceStateLabel[state];
}

function MapNode({ node, selected }: { node: MapNodeData; selected: boolean }) {
    const body = (
        <>
            <KindIcon kind={node.kind === 'site' ? 'other' : node.kind} className="size-4 shrink-0 text-muted-foreground" />
            <span className="grid min-w-0">
                <b className="truncate text-xs font-[650]">{node.name}</b>
                <small className="text-caption truncate">{node.sub}</small>
            </span>
            <Ring tone={NODE_TONE[node.state] ?? 'none'} label={nodeStateWords(node.state)} />
        </>
    );
    const className = cn(
        'flex max-w-[200px] min-w-[120px] items-center gap-2 rounded-xl border border-border bg-card px-2.5 py-1.5 shadow-sm',
        selected && 'ring-2 ring-ring/40',
        node.state === 'cant_check' && 'text-muted-foreground',
    );
    return node.href ? (
        <Link href={node.href} className={cn(className, 'outline-none hover:bg-muted/40 focus-visible:ring-2 focus-visible:ring-ring')}>
            {body}
        </Link>
    ) : (
        <div className={className} aria-current={selected ? 'true' : undefined}>
            {body}
        </div>
    );
}

/**
 * Rows of devices from what's in front (top) to what's behind (bottom), joined by plain lines.
 * The device this page is about is ringed.
 */
export function DeviceMap({
    title,
    sub,
    levels,
    selectedId,
    more,
    legend,
}: {
    title: string;
    sub?: ReactNode;
    levels: MapNodeData[][];
    selectedId: number | string;
    more?: string | null;
    legend: { tone: SgTone; label: string }[];
}) {
    const rows = levels.filter((row) => row.length > 0);
    return (
        <Surface title={title} sub={sub}>
            <div className="mx-[18px] mt-3 rounded-xl border border-border bg-muted/30 px-3 py-4">
                <ol className="grid justify-items-center gap-0" aria-label={title}>
                    {rows.map((row, index) => (
                        <li key={index} className="grid w-full justify-items-center">
                            {index > 0 ? <span aria-hidden="true" className="h-5 w-px bg-border" /> : null}
                            <ul className="flex w-full flex-wrap justify-center gap-2">
                                {row.map((node) => (
                                    <li key={node.id}>
                                        <MapNode node={node} selected={node.id === selectedId} />
                                    </li>
                                ))}
                            </ul>
                        </li>
                    ))}
                </ol>
                {more ? <p className="text-caption mt-2 text-center">{more}</p> : null}
            </div>
            <div className="text-caption flex flex-wrap gap-4 px-[18px] py-3">
                {legend.map((item) => (
                    <span key={item.label} className="inline-flex items-center gap-1.5">
                        <Ring tone={item.tone} />
                        {item.label}
                    </span>
                ))}
            </div>
        </Surface>
    );
}

/* ------------------------------------------------------------------ */
/*  The next step                                                      */
/* ------------------------------------------------------------------ */

/** What's happening and what to do next: a ringed label, a title, a line, then the next step. */
export function NextStep({
    tone,
    label,
    title,
    sub,
    then,
    actions,
}: {
    tone?: SgStatusTone;
    label: ReactNode;
    title: string;
    sub?: ReactNode;
    then?: ReactNode;
    actions?: ReactNode;
}) {
    return (
        <Surface tone={tone}>
            <div className="grid gap-1.5 px-[18px] py-4">
                <p className="text-caption flex items-center gap-2 font-semibold">
                    <Ring tone={tone ?? 'info'} />
                    {label}
                </p>
                <h3 className="text-section-title">{title}</h3>
                {sub ? <p className="text-subtle">{sub}</p> : null}
                {actions ? <div className="mt-1 flex flex-wrap gap-2">{actions}</div> : null}
                {then ? <p className="mt-1 border-t border-dashed border-border pt-2.5 text-sm">{then}</p> : null}
            </div>
        </Surface>
    );
}
