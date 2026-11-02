import { EntityKebab, type MenuItem } from '@/components/lists';
import { KindIcon } from '@/components/security-devices/device-kind-icon';
import { Checkbox } from '@/components/ui/checkbox';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    durationWords,
    seenTime,
    timedText,
} from '@/lib/security-devices/device-time';
import { cn } from '@/lib/utils';
import { Building2 } from 'lucide-react';
import type { MouseEvent } from 'react';
import type { DeviceCardData } from './_types';

/** The icon tile takes the state's tint; normal devices stay quiet. */
const ICON_TONE: Record<string, string> = {
    critical: 'bg-status-critical-bg text-status-critical',
    warning: 'bg-status-warning-bg text-status-warning',
    neutral: 'bg-muted text-muted-foreground',
    success: 'bg-primary/10 text-primary',
    info: 'bg-primary/10 text-primary',
};

const HOUR_MARK: Record<string, string> = {
    o: 'bg-status-success',
    c: 'bg-status-critical',
    n: 'bg-muted-foreground/35',
};

/** One mark per hour, oldest first. */
export function HoursStrip({ hours, label }: { hours: string; label: string }) {
    const answered = [...hours].filter((mark) => mark === 'o').length;
    const failed = [...hours].filter((mark) => mark === 'c').length;
    return (
        <div>
            <div
                role="img"
                aria-label={`${label}: answered in ${answered} of 24 hours${failed ? `, failed in ${failed}` : ''}`}
                className="flex h-[22px] items-stretch gap-[2px]"
            >
                {[...hours].map((mark, index) => (
                    <span key={index} className={cn('flex-1 rounded-[2px]', HOUR_MARK[mark] ?? HOUR_MARK.n)} />
                ))}
            </div>
            <div className="text-caption mt-1 flex justify-between">
                <span>24 h ago</span>
                <span>Now</span>
            </div>
        </div>
    );
}

/** A sensor's hourly averages as a line; gaps stay gaps. */
export function Spark({ values, label }: { values: (number | null)[]; label: string }) {
    const numbers = values.filter((value): value is number => value !== null);
    const min = Math.min(...numbers);
    const max = Math.max(...numbers);
    const span = max - min || 1;
    const points = values
        .map((value, index) =>
            value === null ? null : `${(index / (values.length - 1)) * 100},${28 - ((value - min) / span) * 24}`,
        )
        .filter(Boolean)
        .join(' ');
    return (
        <div>
            <svg role="img" aria-label={label} viewBox="0 0 100 30" preserveAspectRatio="none" className="h-[22px] w-full">
                <polyline points={points} fill="none" className="stroke-primary" strokeWidth={1.5} vectorEffect="non-scaling-stroke" />
            </svg>
            <div className="text-caption mt-1 flex justify-between">
                <span>24 h ago</span>
                <span>Now</span>
            </div>
        </div>
    );
}

export function DeviceCard({
    device,
    actions,
    onOpen,
    onContextMenu,
    selectable,
    selected,
    onToggle,
    now,
}: {
    device: DeviceCardData;
    actions: MenuItem[];
    onOpen: () => void;
    onContextMenu: (event: MouseEvent) => void;
    selectable: boolean;
    selected: boolean;
    onToggle: (checked: boolean) => void;
    now: Date;
}) {
    const graphNote = timedText(device.graph.note, now);
    const reading = device.reading;
    const readingValue = reading?.value ?? (reading?.from ? durationWords(reading.from, now) : null);

    return (
        <article
            aria-label={device.name}
            onClick={() => (selectable ? onToggle(!selected) : onOpen())}
            onContextMenu={onContextMenu}
            className={cn(
                'flex min-w-0 cursor-pointer flex-col rounded-[14px] border border-border bg-card shadow-sm transition-shadow hover:shadow-md focus-within:ring-2 focus-within:ring-ring',
                selected && 'border-primary ring-2 ring-primary/40',
            )}
        >
            <div className="flex flex-1 flex-col gap-3 p-4">
                <div className="flex items-start gap-3">
                    {selectable ? (
                        <label className="flex min-h-9 shrink-0 items-center" onClick={(event) => event.stopPropagation()}>
                            <Checkbox
                                checked={selected}
                                onCheckedChange={(checked) => onToggle(checked === true)}
                                aria-label={`Select ${device.name}`}
                            />
                        </label>
                    ) : null}
                    <span className={cn('flex size-9 shrink-0 items-center justify-center rounded-[10px]', ICON_TONE[device.badge.tone] ?? ICON_TONE.neutral)}>
                        <KindIcon kind={device.kind} className="size-[18px]" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <h3 className="truncate text-[14.5px] leading-tight font-[650]">
                            <a
                                href={device.href}
                                onClick={(event) => {
                                    event.preventDefault();
                                    event.stopPropagation();
                                    if (selectable) onToggle(!selected);
                                    else onOpen();
                                }}
                                className="outline-none hover:underline focus-visible:underline"
                            >
                                {device.name}
                            </a>
                        </h3>
                        <p className="truncate text-xs text-muted-foreground" title={device.sub}>
                            {device.sub}
                        </p>
                    </div>
                    <EntityKebab actions={actions} label={`Actions for ${device.name}`} className="-mt-1 -mr-1" />
                </div>

                <div>
                    <StatusBadge variant={device.badge.tone} size="sm">
                        {device.badge.label}
                    </StatusBadge>
                    <p className="mt-1.5 text-[12.5px] text-muted-foreground">{timedText(device.note, now)}</p>
                </div>

                <div className="flex min-h-[30px] items-baseline gap-2">
                    {readingValue ? (
                        <>
                            <span className="text-xl leading-none font-bold tracking-tight tabular-nums">{readingValue}</span>
                            {reading?.sub ? <span className="text-xs text-muted-foreground">{reading.sub}</span> : null}
                        </>
                    ) : (
                        <span className="text-sm text-muted-foreground">Reading: Not available</span>
                    )}
                </div>

                <div className="mt-auto">
                    <div className="mb-1.5 flex items-baseline justify-between gap-2 text-[11.5px]">
                        <span className="text-muted-foreground">{device.graph.label}</span>
                        {graphNote ? <span className="font-semibold">{graphNote}</span> : null}
                    </div>
                    {device.graph.spark ? (
                        <Spark values={device.graph.spark} label={`${device.graph.label}: ${graphNote}`} />
                    ) : device.graph.hours ? (
                        <HoursStrip hours={device.graph.hours} label={device.graph.label} />
                    ) : (
                        <p className="flex h-[38px] items-center text-xs text-muted-foreground">
                            Not available · no checks have run on it
                        </p>
                    )}
                </div>
            </div>
            <footer className="flex items-center justify-between gap-2 border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                <span className="flex min-w-0 items-center gap-1.5">
                    <Building2 aria-hidden="true" className="size-3.5 shrink-0" />
                    <span className="truncate">{device.site ?? 'No Site'}</span>
                </span>
                <span className="shrink-0">
                    {device.seen.at ? `${device.seen.label} ${seenTime(device.seen.at, now)}` : 'Not seen yet'}
                </span>
            </footer>
        </article>
    );
}
