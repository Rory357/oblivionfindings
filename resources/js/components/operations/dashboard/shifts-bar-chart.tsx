import { Link } from '@inertiajs/react';
import { ArrowRight } from 'lucide-react';
import { useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';

import { HoverPopover, useHoverPopover } from './hover-popover';
import type { HoverPopoverContent, ShiftsPerDay, TopSite } from './types';
import { Card as GuardrailCard } from '@/components/ui/card';

function pctOf(value: number, max: number): number {
    if (max <= 0) return 0;
    return Math.max(2, Math.min(100, (value / max) * 100));
}

function TooltipPortal({
    rect,
    payload,
}: {
    rect: DOMRect | null;
    payload: ShiftsPerDay;
}) {
    const tipRef = useRef<HTMLDivElement>(null);
    const [pos, setPos] = useState<{ left: number; top: number } | null>(null);

    useLayoutEffect(() => {
        if (!rect || !tipRef.current) return;
        const tip = tipRef.current.getBoundingClientRect();
        const vw = window.innerWidth;
        let left = rect.left + rect.width / 2 - tip.width / 2;
        let top = rect.top - tip.height - 8;
        if (top < 8) top = rect.bottom + 8;
        left = Math.max(8, Math.min(vw - tip.width - 8, left));
        setPos({ left, top });
    }, [rect]);

    if (!rect || typeof window === 'undefined') return null;

    const delivered = payload.delivered;
    const diff = payload.scheduled - payload.target;
    const diffColor = diff >= 0 ? 'var(--status-success)' : 'var(--status-warning)';

    return createPortal(
        <GuardrailCard unstyled
            ref={tipRef}
            className="pointer-events-none fixed z-40 rounded-lg border bg-card px-3 py-2 shadow-lg"
            style={{
                left: pos?.left ?? -9999,
                top: pos?.top ?? -9999,
                opacity: pos ? 1 : 0,
                borderColor: 'var(--border)',
                boxShadow: '0 8px 24px -6px rgba(76, 29, 149, 0.18)',
                transition: 'opacity .1s ease',
            }}
        >
            <div className="text-[11px] font-semibold">
                {payload.date}
                {payload.is_today ? <span className="text-primary"> · today</span> : null}
            </div>
            <div className="mt-1 grid grid-cols-[auto_auto] gap-x-3 gap-y-0.5 text-[10.5px]">
                <span className="text-muted-foreground">Scheduled</span>
                <span className="text-right font-semibold tabular-nums">{payload.scheduled}</span>
                <span className="text-muted-foreground">Delivered</span>
                <span className="text-right font-semibold tabular-nums">
                    {delivered === null || delivered === undefined ? '—' : delivered}
                </span>
                <span className="text-muted-foreground">Target</span>
                <span className="text-right font-semibold tabular-nums">
                    {payload.target}{' '}
                    <span style={{ color: diffColor }}>
                        ({diff >= 0 ? '+' : ''}
                        {diff})
                    </span>
                </span>
                <span className="text-muted-foreground">Staff</span>
                <span className="text-right font-semibold tabular-nums">{payload.staff}</span>
            </div>
            <div
                className="mt-1 border-t pt-1 text-[10px] font-semibold text-primary"
                style={{ borderColor: 'var(--border)' }}
            >
                Click bar to open day
            </div>
        </GuardrailCard>,
        document.body,
    );
}

function TopSiteRow({ site, totalHours }: { site: TopSite; totalHours: number }) {
    const anchor = useRef<HTMLAnchorElement>(null);
    const { open, onEnter, onLeave, popEnter, popLeave } = useHoverPopover();
    const widthPct = totalHours > 0 ? (site.hours / totalHours) * 100 : 0;

    const popover: HoverPopoverContent = {
        icon: 'building-2',
        tone: site.region?.toLowerCase().includes('whang') ? 'critical' : 'info',
        title: site.name,
        sub: `${site.hours}h · ${site.pct}%`,
        rows: [
            { time: 'Region', site: site.region ?? site.city ?? '—', detail: `${site.client_count} clients` },
            { time: 'Hours', site: `${site.hours}h`, detail: 'This week · approved' },
            { time: 'Share', site: `${site.pct}%`, detail: 'Of total delivered' },
        ],
        cta: 'Open site profile',
        href: `/sites/${site.id}`,
    };

    return (
        <>
            <a
                ref={anchor}
                href={`/sites/${site.id}`}
                onMouseEnter={onEnter}
                onMouseLeave={onLeave}
                className="grid items-center gap-3 rounded px-1 py-1 hover:bg-muted/50"
                style={{ gridTemplateColumns: '1fr 2.5fr auto' }}
            >
                <div className="truncate text-[11.5px] font-medium">{site.name}</div>
                <div className="relative h-4 overflow-hidden rounded-sm bg-muted">
                    <div
                        className="h-full rounded-sm transition-[filter]"
                        style={{
                            width: `${Math.max(2, widthPct)}%`,
                            background: `color-mix(in oklch, var(--primary) ${Math.max(40, 100 - widthPct * 0.6)}%, white)`,
                        }}
                    />
                </div>
                <div className="shrink-0 text-right text-[11px] tabular-nums">
                    <span className="font-semibold">{site.hours}h</span>{' '}
                    <span className="text-muted-foreground">· {site.pct}%</span>
                </div>
            </a>
            <HoverPopover
                open={open}
                anchorRef={anchor}
                content={popover}
                onMouseEnter={popEnter}
                onMouseLeave={popLeave}
                placement="right"
            />
        </>
    );
}

type Props = {
    shiftsPerDay: ShiftsPerDay[];
    topSites: TopSite[];
    deliveredHours: number;
    deliveredDeltaPct: number;
    avgShiftHours: number;
};

export function ShiftsBarChart({
    shiftsPerDay,
    topSites,
    deliveredHours,
    deliveredDeltaPct,
    avgShiftHours,
}: Props) {
    const max = Math.max(250, ...shiftsPerDay.map((d) => Math.max(d.scheduled, d.target)));
    const [hover, setHover] = useState<{ rect: DOMRect; day: ShiftsPerDay } | null>(null);
    const totalScheduled = shiftsPerDay.reduce((sum, d) => sum + d.scheduled, 0);
    const totalSiteHours = topSites.reduce((sum, s) => sum + s.hours, 0);

    return (
        <GuardrailCard unstyled
            className="rounded-xl border bg-card lg:col-span-3"
            style={{ borderColor: 'var(--border)' }}
        >
            <div
                className="flex items-center justify-between border-b px-4 py-3"
                style={{ borderColor: 'var(--border)' }}
            >
                <div>
                    <h3 className="text-[14px] font-semibold">Shifts &amp; hours · next 7 days</h3>
                    <p className="text-[11px] text-muted-foreground">
                        Scheduled vs delivered · target band shown in lavender
                    </p>
                </div>
                <div className="flex items-center gap-3 text-[11px] text-muted-foreground">
                    <span className="inline-flex items-center gap-1">
                        <span className="h-2 w-2 rounded-sm" style={{ background: 'var(--primary)' }} /> Scheduled
                    </span>
                    <span className="inline-flex items-center gap-1">
                        <span
                            className="h-2 w-2 rounded-sm"
                            style={{ background: 'var(--accent)', outline: '1px dashed var(--primary)' }}
                        />{' '}
                        Target
                    </span>
                </div>
            </div>

            <div className="p-4">
                <div className="relative ml-8 h-44">
                    <div className="absolute inset-0 flex flex-col justify-between text-[9px] tabular-nums text-muted-foreground">
                        {[250, 200, 150, 100, 50, 0].map((v) => (
                            <div key={v} className="flex w-7 -translate-x-7 items-center justify-end">
                                <span>{v}</span>
                            </div>
                        ))}
                    </div>
                    <div className="pointer-events-none absolute inset-0 flex flex-col justify-between">
                        {[0, 1, 2, 3, 4].map((i) => (
                            <div
                                key={i}
                                className="border-t border-dashed"
                                style={{ borderColor: 'var(--border)' }}
                            />
                        ))}
                        <div className="border-t" style={{ borderColor: 'var(--border)' }} />
                    </div>
                    <div className="absolute inset-0 flex items-end gap-3 px-2">
                        {shiftsPerDay.map((d) => {
                            const scheduledPct = pctOf(d.scheduled, max);
                            const targetPct = pctOf(d.target, max);
                            return (
                                <div
                                    key={d.iso}
                                    className="flex h-full flex-1 cursor-pointer flex-col items-center gap-1"
                                    onMouseEnter={(e) => {
                                        const barEl = (e.currentTarget.querySelector(
                                            '[data-bar="scheduled"]',
                                        ) as HTMLElement | null);
                                        if (barEl) setHover({ rect: barEl.getBoundingClientRect(), day: d });
                                    }}
                                    onMouseLeave={() => setHover(null)}
                                >
                                    <div className="flex h-full w-full items-end justify-center gap-0.5">
                                        <div
                                            className="w-3.5 rounded-t"
                                            style={{
                                                height: `${targetPct}%`,
                                                background: 'var(--accent)',
                                                outline: '1px dashed var(--primary)',
                                                opacity: d.is_forecast ? 0.7 : 1,
                                            }}
                                        />
                                        <div
                                            data-bar="scheduled"
                                            className="relative w-4 rounded-t transition-[filter]"
                                            style={{
                                                height: `${scheduledPct}%`,
                                                background: 'var(--primary)',
                                                opacity: d.is_forecast ? 0.7 : 1,
                                                filter:
                                                    hover && hover.day.iso === d.iso
                                                        ? 'brightness(1.12)'
                                                        : undefined,
                                                boxShadow:
                                                    hover && hover.day.iso === d.iso
                                                        ? '0 0 0 2px color-mix(in oklch, var(--primary) 30%, transparent)'
                                                        : undefined,
                                            }}
                                        >
                                            {d.is_today ? (
                                                <span className="absolute -top-4 left-1/2 -translate-x-1/2 text-[9px] font-semibold tabular-nums">
                                                    {d.scheduled}
                                                </span>
                                            ) : null}
                                        </div>
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                </div>

                <div className="ml-8 mt-2 flex text-[10.5px] tabular-nums text-muted-foreground">
                    {shiftsPerDay.map((d) => (
                        <div key={d.iso} className="flex-1 text-center">
                            <div
                                className={d.is_today ? 'font-semibold text-foreground' : undefined}
                            >
                                {d.date_short}
                            </div>
                            <div>{String(d.date_num).padStart(2, '0')}</div>
                        </div>
                    ))}
                </div>

                <div
                    className="mt-4 grid grid-cols-3 gap-4 border-t pt-3 text-center"
                    style={{ borderColor: 'var(--border)' }}
                >
                    <div>
                        <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
                            Scheduled (7d)
                        </div>
                        <div className="text-lg font-bold tabular-nums">
                            {totalScheduled.toLocaleString()}{' '}
                            <span className="text-[11px] font-normal text-muted-foreground">shifts</span>
                        </div>
                    </div>
                    <div>
                        <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
                            Delivered hours
                        </div>
                        <div className="text-lg font-bold tabular-nums">
                            {deliveredHours.toLocaleString()}{' '}
                            <span
                                className="text-[11px] font-normal"
                                style={{
                                    color: deliveredDeltaPct >= 0 ? 'var(--status-success)' : 'var(--status-warning)',
                                }}
                            >
                                {deliveredDeltaPct >= 0 ? '+' : ''}
                                {deliveredDeltaPct}%
                            </span>
                        </div>
                    </div>
                    <div>
                        <div className="text-[10px] uppercase tracking-wider text-muted-foreground">
                            Avg shift length
                        </div>
                        <div className="text-lg font-bold tabular-nums">
                            {avgShiftHours}h{' '}
                            <span className="text-[11px] font-normal text-muted-foreground">/ shift</span>
                        </div>
                    </div>
                </div>

                <div className="mt-5 border-t pt-4" style={{ borderColor: 'var(--border)' }}>
                    <div className="mb-2.5 flex items-center justify-between">
                        <div>
                            <div className="text-[12px] font-semibold">Top sites by hours this week</div>
                            <div className="text-[10.5px] text-muted-foreground">
                                Ranked · % of total delivered hours
                            </div>
                        </div>
                        <Link
                            href="/sites"
                            className="inline-flex items-center gap-0.5 text-[11px] font-medium text-primary"
                        >
                            All sites <ArrowRight className="h-3 w-3" />
                        </Link>
                    </div>
                    <div className="space-y-1.5">
                        {topSites.length === 0 ? (
                            <div className="rounded border border-dashed py-4 text-center text-[11px] text-muted-foreground">
                                No site activity recorded this week.
                            </div>
                        ) : (
                            topSites.map((site) => (
                                <TopSiteRow key={site.id} site={site} totalHours={totalSiteHours} />
                            ))
                        )}
                    </div>
                </div>
            </div>

            {hover ? <TooltipPortal rect={hover.rect} payload={hover.day} /> : null}
        </GuardrailCard>
    );
}
