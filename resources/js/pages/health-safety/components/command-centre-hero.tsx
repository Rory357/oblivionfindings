/* Health & Safety command-centre hero (WS2). Mirrors the handovers/shift-notes hero
 * idiom: PageHero category="ops" shell + live-dot eyebrow (children) + leading/lagging
 * stat clusters + a footer band (period range · site · role lens · "this week" strip).
 * Tokens only; plain string URLs (no wayfinder imports). The ＋Report launcher (WS6) and
 * board-export action (WS8) are intentionally omitted until their workstreams build them. */
import { PageHero } from '@/components/page';
import { EntityFilter } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Flame,
    HeartPulse,
    Plus,
    ShieldCheck,
    TrendingDown,
    type LucideIcon,
} from 'lucide-react';
import { type ReactNode, useState } from 'react';

export type HeroLeadingLagging = {
    lagging: {
        incidents: number;
        ltifr: number | null;
        trifr: number | null;
        injury_severity_rate: number | null;
        days_since_lti: number | null;
    };
    leading: {
        near_miss_ratio: number | null;
        actions_on_time_pct: number | null;
        training_pct: number | null;
        open_hazards: number;
    };
};

export type HeroFilters = {
    from: string;
    to: string;
    site: number | null;
    lens: string;
};

type Tone = 'success' | 'warning' | 'critical' | 'neutral';

const DOT_CLASS: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-primary-foreground/50',
};

const PERIOD_ITEMS = [
    { key: 'week', label: 'This week' },
    { key: '30d', label: '30 days' },
    { key: 'quarter', label: 'Quarter' },
    { key: 'custom', label: 'Custom range' },
] as const;

const LENS_ITEMS = [
    { key: 'governance', label: 'Governance' },
    { key: 'manager', label: 'Manager' },
    { key: 'frontline', label: 'Frontline' },
] as const;

function toISODate(d: Date): string {
    return d.toISOString().slice(0, 10);
}

function presetRange(key: string): { from: string; to: string } {
    const now = new Date();
    const from = new Date(now);
    if (key === 'week') {
        const mondayOffset = (now.getDay() + 6) % 7;
        from.setDate(now.getDate() - mondayOffset);
    } else if (key === 'quarter') {
        from.setDate(now.getDate() - 90);
    } else {
        from.setDate(now.getDate() - 30);
    }
    return { from: toISODate(from), to: toISODate(now) };
}

function fmt(value: number | null | undefined, suffix = ''): string {
    return value === null || value === undefined ? '—' : `${value}${suffix}`;
}

function ClusterTile({
    href,
    label,
    value,
    caption,
    tone,
}: {
    href: string;
    label: string;
    value: string;
    caption: string;
    tone: Tone;
}) {
    return (
        <Link
            href={href}
            className="flex flex-col gap-0.5 rounded-xl border border-primary-foreground/15 bg-primary-foreground/10 px-3 py-2.5 text-left transition-colors hover:bg-primary-foreground/20"
        >
            <span className="flex items-center gap-1.5">
                <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', DOT_CLASS[tone])} />
                <span className="text-[11px] font-medium text-primary-foreground/70">{label}</span>
            </span>
            <span className="text-xl font-bold tabular-nums text-primary-foreground">{value}</span>
            <span className="text-[10.5px] text-primary-foreground/55">{caption}</span>
        </Link>
    );
}

function Cluster({
    title,
    icon: Icon,
    children,
}: {
    title: string;
    icon: LucideIcon;
    children: ReactNode;
}) {
    return (
        <div className="rounded-2xl border border-primary-foreground/15 bg-primary-foreground/5 p-3">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                <Icon className="h-3.5 w-3.5" />
                {title}
            </div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">{children}</div>
        </div>
    );
}

function SummaryMetric({ tone, children }: { tone: Tone; children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className={cn('h-1.5 w-1.5 rounded-full', DOT_CLASS[tone])} />
            {children}
        </span>
    );
}

export function CommandCentreHero({
    leadingLagging,
    filters,
    sites,
    expiring,
    notifiableEvents,
    activeAlerts,
    onReport,
}: {
    leadingLagging: HeroLeadingLagging;
    filters: HeroFilters;
    sites: Array<{ id: number; name: string }>;
    expiring: Array<{ type: string }>;
    notifiableEvents: Array<{ status: string }>;
    activeAlerts: number;
    onReport?: () => void;
}) {
    const [customFrom, setCustomFrom] = useState(filters.from);
    const [customTo, setCustomTo] = useState(filters.to);

    const lagging = leadingLagging.lagging;
    const leading = leadingLagging.leading;

    const notifiableAwaiting = notifiableEvents.filter((n) => n.status === 'pending').length;
    const sdsExpiring = expiring.filter((e) => e.type === 'sds').length;
    const drillsDue = expiring.filter((e) => e.type === 'drill').length;

    const go = (params: Partial<HeroFilters> & { from?: string; to?: string }) => {
        const merged: Record<string, string | number> = {
            from: filters.from,
            to: filters.to,
            lens: filters.lens,
        };
        if (filters.site != null) merged.site = filters.site;
        for (const [k, v] of Object.entries(params)) {
            if (v == null) delete merged[k];
            else merged[k] = v as string | number;
        }
        router.get('/health-safety', merged, { preserveScroll: true });
    };

    const activePeriod = ((): string => {
        for (const key of ['week', '30d', 'quarter']) {
            if (presetRange(key).from === filters.from) return key;
        }
        return 'custom';
    })();

    const activeSite = filters.site != null ? sites.find((s) => s.id === filters.site) : null;
    const siteLabel = activeSite ? activeSite.name : 'All sites';

    const description = (
        <span>
            <span className="underline decoration-primary-foreground/40 underline-offset-4">
                {siteLabel}
            </span>
            {` · ${sites.length} site${sites.length === 1 ? '' : 's'} · PCBU duty-holder view`}
        </span>
    );

    const badges: { icon: LucideIcon; tone: 'success' | 'warning'; label: string }[] = [
        {
            icon: notifiableAwaiting > 0 ? AlertTriangle : CheckCircle2,
            tone: notifiableAwaiting > 0 ? 'warning' : 'success',
            label: `WorkSafe notifiable · ${notifiableAwaiting} awaiting`,
        },
        { icon: ShieldCheck, tone: 'success', label: 'Ngā Paerewa NZS 8134:2021 · Certified' },
        {
            icon: sdsExpiring > 0 ? AlertTriangle : CheckCircle2,
            tone: sdsExpiring > 0 ? 'warning' : 'success',
            label:
                sdsExpiring > 0
                    ? `Hazardous substances · ${sdsExpiring} SDS expiring`
                    : 'Hazardous substances · SDS current',
        },
        {
            icon: Flame,
            tone: drillsDue > 0 ? 'warning' : 'success',
            label: drillsDue > 0 ? `Fire · ${drillsDue} drill${drillsDue === 1 ? '' : 's'} due` : 'Fire · Drills current',
        },
        { icon: HeartPulse, tone: 'success', label: 'First aid · Cover OK' },
    ];

    const pillBase =
        'rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors';
    const pillActive = 'bg-primary-foreground/25 text-primary-foreground';
    const pillInactive =
        'bg-primary-foreground/10 text-primary-foreground/80 hover:bg-primary-foreground/20';
    const segBase = 'rounded-md px-2.5 py-1 text-xs font-semibold transition-colors';
    const segActive = 'bg-primary-foreground text-primary';
    const segInactive = 'text-primary-foreground/80 hover:text-primary-foreground';

    const footer = (
        <div className="flex flex-col gap-3 py-3">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                {/* Period range control */}
                <div className="flex flex-wrap items-center gap-1.5">
                    <span className="mr-1 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                        Period
                    </span>
                    {PERIOD_ITEMS.map((p) =>
                        p.key === 'custom' ? (
                            <Popover key={p.key}>
                                <PopoverTrigger asChild>
                                    {/* eslint-disable-next-line no-restricted-syntax -- segmented period pill on dark hero; not a shadcn Button. */}
                                    <button
                                        type="button"
                                        className={cn(pillBase, activePeriod === p.key ? pillActive : pillInactive)}
                                    >
                                        {p.label}
                                    </button>
                                </PopoverTrigger>
                                <PopoverContent align="start" className="w-auto space-y-2 p-3">
                                    <div className="flex items-end gap-2">
                                        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
                                            From
                                            <input
                                                type="date"
                                                value={customFrom}
                                                max={customTo}
                                                onChange={(e) => setCustomFrom(e.target.value)}
                                                className="rounded-md border border-border bg-background px-2 py-1 text-sm text-foreground"
                                            />
                                        </label>
                                        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
                                            To
                                            <input
                                                type="date"
                                                value={customTo}
                                                min={customFrom}
                                                onChange={(e) => setCustomTo(e.target.value)}
                                                className="rounded-md border border-border bg-background px-2 py-1 text-sm text-foreground"
                                            />
                                        </label>
                                    </div>
                                    <Button
                                        size="sm"
                                        className="w-full"
                                        onClick={() => go({ from: customFrom, to: customTo })}
                                    >
                                        Apply range
                                    </Button>
                                </PopoverContent>
                            </Popover>
                        ) : (
                            // eslint-disable-next-line no-restricted-syntax -- segmented period pill on dark hero; not a shadcn Button.
                            <button
                                key={p.key}
                                type="button"
                                onClick={() => go(presetRange(p.key))}
                                className={cn(pillBase, activePeriod === p.key ? pillActive : pillInactive)}
                            >
                                {p.label}
                            </button>
                        ),
                    )}
                </div>

                {/* Site filter + role lens */}
                <div className="flex flex-wrap items-center gap-2">
                    <EntityFilter
                        onDark
                        label="Site"
                        allLabel="All sites"
                        items={sites.map((s) => ({ id: s.id, name: s.name }))}
                        value={filters.site}
                        onChange={(v) => go({ site: v ?? undefined })}
                    />
                    <span className="ml-1 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                        Lens
                    </span>
                    <div className="inline-flex items-center gap-0.5 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 p-0.5">
                        {LENS_ITEMS.map((l) => (
                            // eslint-disable-next-line no-restricted-syntax -- segmented lens toggle on dark hero; not a shadcn Button.
                            <button
                                key={l.key}
                                type="button"
                                onClick={() => go({ lens: l.key })}
                                aria-pressed={filters.lens === l.key}
                                className={cn(segBase, filters.lens === l.key ? segActive : segInactive)}
                            >
                                {l.label}
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            {/* "This week" summary strip */}
            <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-primary-foreground/15 pt-2.5 text-xs text-primary-foreground/80">
                <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                    This week
                </span>
                <SummaryMetric tone="warning">{lagging.incidents} incidents</SummaryMetric>
                <SummaryMetric tone="critical">{notifiableAwaiting} WorkSafe-notifiable</SummaryMetric>
                <SummaryMetric tone="warning">{leading.open_hazards} hazards open</SummaryMetric>
                <SummaryMetric tone="warning">{drillsDue} drills due</SummaryMetric>
                <SummaryMetric tone={activeAlerts > 0 ? 'critical' : 'success'}>
                    {activeAlerts > 0
                        ? `${activeAlerts} lone-worker alert${activeAlerts === 1 ? '' : 's'}`
                        : 'lone-workers all checked in'}
                </SummaryMetric>
            </div>
        </div>
    );

    return (
        <PageHero
            category="ops"
            icon={ShieldCheck}
            title="Health & Safety command centre"
            description={description}
            badges={badges}
            actions={
                onReport ? (
                    <Button
                        onClick={onReport}
                        className="bg-primary-foreground text-primary shadow-sm hover:bg-primary-foreground/90"
                    >
                        <Plus className="mr-1.5 h-4 w-4" />
                        Report
                    </Button>
                ) : undefined
            }
            footer={footer}
        >
            <div className="space-y-4">
                <div className="inline-flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-primary-foreground/70 uppercase">
                    <span className="relative flex h-2 w-2">
                        <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-70 motion-reduce:animate-none" />
                        <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                    </span>
                    Safety system · synced just now
                </div>

                <div className="grid gap-3 lg:grid-cols-2">
                    <Cluster title="Lagging · outcomes" icon={TrendingDown}>
                        <ClusterTile
                            href="/incidents"
                            label="Incidents"
                            value={fmt(lagging.incidents)}
                            caption="this period"
                            tone={lagging.incidents > 0 ? 'warning' : 'success'}
                        />
                        <ClusterTile
                            href="/health-safety/injuries"
                            label="LTIFR"
                            value={fmt(lagging.ltifr)}
                            caption="per M hrs"
                            tone="neutral"
                        />
                        <ClusterTile
                            href="/health-safety/injuries"
                            label="TRIFR"
                            value={fmt(lagging.trifr)}
                            caption="per M hrs"
                            tone="neutral"
                        />
                        <ClusterTile
                            href="/health-safety/injuries"
                            label="Days LTI-free"
                            value={fmt(lagging.days_since_lti)}
                            caption="since last LTI"
                            tone={(lagging.days_since_lti ?? 0) >= 30 ? 'success' : 'warning'}
                        />
                    </Cluster>

                    <Cluster title="Leading · proactive" icon={Clock}>
                        <ClusterTile
                            href="/incidents?type=near_miss"
                            label="Near-miss"
                            value={fmt(leading.near_miss_ratio, '×')}
                            caption=": incident"
                            tone={(leading.near_miss_ratio ?? 0) >= 3 ? 'success' : 'warning'}
                        />
                        <ClusterTile
                            href="/health-safety/corrective-actions"
                            label="Actions on time"
                            value={fmt(leading.actions_on_time_pct, '%')}
                            caption="this period"
                            tone={(leading.actions_on_time_pct ?? 0) >= 90 ? 'success' : 'warning'}
                        />
                        <ClusterTile
                            href="/health-safety/worker-participation"
                            label="Train / audit"
                            value={fmt(leading.training_pct, '%')}
                            caption="compliance"
                            tone={(leading.training_pct ?? 0) >= 90 ? 'success' : 'warning'}
                        />
                        <ClusterTile
                            href="/compliance/hazards"
                            label="Open hazards"
                            value={fmt(leading.open_hazards)}
                            caption="open now"
                            tone={leading.open_hazards > 0 ? 'warning' : 'success'}
                        />
                    </Cluster>
                </div>
            </div>
        </PageHero>
    );
}

export default CommandCentreHero;
