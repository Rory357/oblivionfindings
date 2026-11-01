/**
 * G2 (Stephan's approved Overview header, 31 Oct): the chart row under the four boxes. Each chart loads in its own
 * background request, so a slow one never holds up the others, and each opens its list. People who can't open incidents
 * get the hazard version of every chart (kind "hazards").
 */
import { Link } from '@inertiajs/react';
import type { ReactNode } from 'react';

import { DeferredPanel } from '@/components/deferred-panel';
import { cn } from '@/lib/utils';

type Kind = 'incidents' | 'hazards';
interface Tally {
    key: string;
    label: string;
    count: number;
}
export interface SeverityChart {
    kind: Kind;
    items: Tally[];
}
export interface TypesChart {
    kind: Kind;
    items: Tally[];
    other: number;
}
export interface DaysChart {
    kind: Kind | 'actions';
    days: Array<{ date: string; count: number }>;
    total: number;
    previous_total?: number;
    change_pct?: number | null;
}

/** Colours stay readable on the dark sky: status hues lifted to a lightness floor, like the meter tones. */
const SEVERITY_FILL: Record<string, string> = {
    critical: 'oklch(from var(--status-critical) max(l, 0.68) c h)',
    high: 'oklch(from color-mix(in oklch, var(--status-critical), var(--status-warning)) max(l, 0.74) c h)',
    medium: 'oklch(from var(--status-warning) max(l, 0.84) c h)',
    low: 'color-mix(in oklch, var(--band-foreground) 60%, transparent)',
};
const TYPE_FILL = ['var(--chart-1)', 'var(--chart-2)', 'var(--chart-3)', 'var(--chart-4)', 'var(--chart-5)'];

const BLOCK = 'eh-meter min-h-[118px] content-start gap-2.5 outline-none focus-visible:ring-2 focus-visible:ring-band-foreground/70';

function ChartBlock({ title, sub, href, label, children }: { title: string; sub: string; href: string; label: string; children: ReactNode }) {
    return (
        <Link href={href} aria-label={label} className={BLOCK}>
            <span className="flex items-baseline gap-1.5 text-[13px] font-semibold text-band-foreground">
                {title}
                <span className="text-xs font-normal text-band-foreground/70">{sub}</span>
            </span>
            {children}
        </Link>
    );
}

function Waiting({ title }: { title: string }) {
    return (
        <div className={cn(BLOCK, 'cursor-default')} aria-busy="true">
            <span className="text-[13px] font-semibold text-band-foreground">{title}</span>
            <span className="h-[54px] w-full animate-pulse rounded-md bg-band-foreground/10" />
            <span className="text-xs text-band-foreground/70">Counting…</span>
        </div>
    );
}

function Bars({ days, hot }: { days: DaysChart['days']; hot?: (i: number, n: number) => boolean }) {
    const max = Math.max(...days.map((d) => d.count), 1);
    return (
        <span className="flex h-[54px] min-w-0 flex-1 items-end gap-[2px] border-b border-band-foreground/20" aria-hidden="true">
            {days.map((d, i) => (
                <i
                    key={d.date}
                    className={cn('block min-w-0 flex-1 rounded-t-[2px]', hot?.(i, d.count) ? '' : 'bg-band-foreground/45')}
                    style={{
                        height: d.count === 0 ? 2 : `${Math.max(8, Math.round((d.count / max) * 100))}%`,
                        ...(hot?.(i, d.count) ? { background: SEVERITY_FILL.medium } : {}),
                    }}
                />
            ))}
        </span>
    );
}

const plural = (n: number, one: string, many = one + 's') => `${n} ${n === 1 ? one : many}`;

export function SeverityBlock({ data, href }: { data: SeverityChart; href: string }) {
    const total = data.items.reduce((sum, item) => sum + item.count, 0);
    const what = data.kind === 'hazards' ? 'Hazard risk' : 'Incident severity';
    return (
        <ChartBlock
            title={what}
            sub={data.kind === 'hazards' ? 'open hazards' : 'open incidents'}
            href={href}
            label={`${what}: ${data.items.map((i) => `${i.count} ${i.label}`).join(', ')}. Open the list`}
        >
            <span className="flex h-[22px] w-full overflow-hidden rounded-md bg-band-foreground/10" aria-hidden="true">
                {total > 0 && data.items.map((item) => (item.count > 0 ? <b key={item.key} style={{ flex: item.count, background: SEVERITY_FILL[item.key] }} /> : null))}
            </span>
            <span className="flex flex-wrap gap-x-3.5 gap-y-1 text-xs text-band-foreground">
                {data.items.map((item) => (
                    <span key={item.key} className="inline-flex items-center gap-1.5">
                        <i className="block size-2 rounded-full" style={{ background: SEVERITY_FILL[item.key] }} aria-hidden="true" />
                        {item.count} {item.label}
                    </span>
                ))}
            </span>
        </ChartBlock>
    );
}

export function OverTimeBlock({ data, href }: { data: DaysChart; href: string }) {
    const noun = data.kind === 'hazards' ? 'hazard' : 'incident';
    const change =
        data.change_pct === null || data.change_pct === undefined
            ? null
            : data.change_pct === 0
              ? 'Same as the month before'
              : `${data.change_pct > 0 ? '↑' : '↓'} ${Math.abs(data.change_pct)}% on the month before`;
    return (
        <ChartBlock
            title={data.kind === 'hazards' ? 'Hazards reported' : 'Incidents over time'}
            sub="each day"
            href={href}
            label={`${plural(data.total, noun)} reported in the last 30 days${change ? '. ' + change : ''}. Open the list`}
        >
            <span className="flex w-full items-end gap-3.5">
                <Bars days={data.days} />
                <span className="w-[104px] shrink-0">
                    <span className="eh-meter-big block text-[22px] leading-none font-bold tabular-nums">{data.total}</span>
                    <span className="mt-1 block text-xs leading-snug text-band-foreground/80">reported in the last 30 days</span>
                    {change ? <span className="mt-1 block text-xs font-semibold text-band-foreground">{change}</span> : null}
                </span>
            </span>
        </ChartBlock>
    );
}

export function DueBlock({ data, href }: { data: DaysChart; href: string }) {
    const noun = data.kind === 'hazards' ? 'hazard' : 'action';
    return (
        <ChartBlock
            title={data.kind === 'hazards' ? 'Hazards due soon' : 'Actions due soon'}
            sub="each day ahead"
            href={href}
            label={`${plural(data.total, noun)} due in the next 30 days. Open the list`}
        >
            <span className="flex w-full items-end gap-3.5">
                <Bars days={data.days} hot={(i, n) => n > 0 && i < 7} />
                <span className="w-[104px] shrink-0">
                    <span className="eh-meter-big block text-[22px] leading-none font-bold tabular-nums">{data.total}</span>
                    <span className="mt-1 block text-xs leading-snug text-band-foreground/80">due in the next 30 days</span>
                </span>
            </span>
        </ChartBlock>
    );
}

export function TypesBlock({ data, href }: { data: TypesChart; href: string }) {
    const items = [...data.items, ...(data.other > 0 ? [{ key: 'other', label: 'Other', count: data.other }] : [])];
    const total = items.reduce((sum, item) => sum + item.count, 0);
    let at = 0;
    const cone = items
        .map((item, i) => {
            const from = at;
            at += (item.count / Math.max(total, 1)) * 360;
            return `${TYPE_FILL[i % TYPE_FILL.length]} ${from}deg ${at}deg`;
        })
        .join(', ');
    const what = data.kind === 'hazards' ? 'Top hazard types' : 'Top incident types';
    return (
        <ChartBlock title={what} sub="open" href={href} label={`${what}: ${items.map((i) => `${i.label} ${i.count}`).join(', ') || 'none open'}. Open the list`}>
            {total === 0 ? (
                <span className="text-xs text-band-foreground/80">Nothing open.</span>
            ) : (
                <span className="grid w-full grid-cols-[auto_minmax(0,1fr)] items-center gap-4">
                    <span
                        className="relative block size-[68px] rounded-full"
                        style={{ background: `conic-gradient(${cone})` }}
                        aria-hidden="true"
                    >
                        <span className="absolute inset-[16px] rounded-full bg-primary-strong" />
                    </span>
                    <ul className="grid min-w-0 gap-0.5 text-xs text-band-foreground">
                        {items.map((item, i) => (
                            <li key={item.key} className="grid grid-cols-[10px_minmax(0,1fr)_auto] items-center gap-1.5">
                                <i className="block size-2 rounded-full" style={{ background: TYPE_FILL[i % TYPE_FILL.length] }} aria-hidden="true" />
                                <span className="truncate">{item.label}</span>
                                <b className="tabular-nums">{item.count}</b>
                            </li>
                        ))}
                    </ul>
                </span>
            )}
        </ChartBlock>
    );
}

/** The row of four, each from its own background part with its own waiting state. */
export function OverviewHeaderCharts({ hazards, link }: { hazards: boolean; link: (path: string, params?: Record<string, string>) => string }) {
    const list = hazards ? link('/compliance/hazards', { tab: 'active' }) : link('/incidents', { tab: 'open' });
    return (
        <div className="grid w-full grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4" data-testid="hs-header-charts">
            <DeferredPanel<{ header_chart_severity: SeverityChart }>
                data="header_chart_severity"
                label={hazards ? 'Hazard risk' : 'Incident severity'}
                fallback={<Waiting title={hazards ? 'Hazard risk' : 'Incident severity'} />}
            >
                {({ header_chart_severity }) => <SeverityBlock data={header_chart_severity} href={list} />}
            </DeferredPanel>
            <DeferredPanel<{ header_chart_over_time: DaysChart }>
                data="header_chart_over_time"
                label={hazards ? 'Hazards reported' : 'Incidents over time'}
                fallback={<Waiting title={hazards ? 'Hazards reported' : 'Incidents over time'} />}
            >
                {({ header_chart_over_time }) => (
                    <OverTimeBlock data={header_chart_over_time} href={hazards ? link('/compliance/hazards') : link('/incidents')} />
                )}
            </DeferredPanel>
            <DeferredPanel<{ header_chart_due: DaysChart }>
                data="header_chart_due"
                label={hazards ? 'Hazards due soon' : 'Actions due soon'}
                fallback={<Waiting title={hazards ? 'Hazards due soon' : 'Actions due soon'} />}
            >
                {({ header_chart_due }) => (
                    <DueBlock data={header_chart_due} href={hazards ? link('/compliance/hazards', { tab: 'active' }) : link('/health-safety/corrective-actions', { tab: 'open' })} />
                )}
            </DeferredPanel>
            <DeferredPanel<{ header_chart_types: TypesChart }>
                data="header_chart_types"
                label={hazards ? 'Top hazard types' : 'Top incident types'}
                fallback={<Waiting title={hazards ? 'Top hazard types' : 'Top incident types'} />}
            >
                {({ header_chart_types }) => <TypesBlock data={header_chart_types} href={list} />}
            </DeferredPanel>
        </div>
    );
}
