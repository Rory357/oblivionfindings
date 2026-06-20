/* Health & Safety command-centre hero. Composes the shared `hs-hero-kit` primitives (the gold
 * standard, now extracted): eyebrow status pill + action row (＋Report · Export board summary),
 * medallion + title + NZ compliance badges, the Leading/Lagging stat clusters, and a footer band
 * (period range · site · role lens · "this week" summary strip). Semantic tokens only; plain
 * string URLs. The kit is the single implementation — analytics composes the same pieces. */
import { EntityFilter } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { type SharedData } from '@/types';
import { Link, router, usePage } from '@inertiajs/react';
import {
    BarChart3,
    ChevronDown,
    Clock,
    Download,
    Plus,
    ShieldCheck,
    TrendingDown,
    Truck,
} from 'lucide-react';
import { useState } from 'react';

import {
    fmt,
    HeroCluster,
    HeroClusterTile,
    HeroComplianceBadges,
    HeroMedallion,
    type HeroSegItem,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
} from './hs-hero-kit';

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

const LENS_ITEMS: HeroSegItem[] = [
    { key: 'governance', label: 'Governance' },
    { key: 'manager', label: 'Manager' },
    { key: 'frontline', label: 'Frontline' },
];

const GOV_REPORTS = [
    { label: 'Board summary', href: '/health-safety/reports/board-summary' },
    { label: 'WorkSafe register', href: '/health-safety/reports/worksafe-register' },
    { label: 'Investigation outcomes', href: '/health-safety/reports/investigation-outcomes' },
    { label: 'Corrective-action traceability', href: '/health-safety/reports/corrective-action-traceability' },
    { label: 'Risk-assessment register', href: '/health-safety/reports/risk-assessment-register' },
];

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

export function CommandCentreHero({
    leadingLagging,
    filters,
    sites,
    expiring,
    notifiableEvents,
    activeAlerts,
    openSafeguarding,
    fleetUnresolved,
    fleetIncidents30d,
    onReport,
    orgName,
}: {
    leadingLagging: HeroLeadingLagging;
    filters: HeroFilters;
    sites: Array<{ id: number; name: string }>;
    expiring: Array<{ type: string }>;
    notifiableEvents: Array<{ status: string }>;
    activeAlerts: number;
    openSafeguarding: number;
    fleetUnresolved: number;
    fleetIncidents30d: number;
    onReport?: () => void;
    orgName?: string | null;
}) {
    // The board-summary export targets governance.view-gated report routes; hide
    // it for register-only roles so they don't hit a 403 from the command centre.
    const canViewBoardReports = usePage<SharedData>().props.auth.can?.governance?.view ?? false;

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

    const periodItems: HeroSegItem[] = [
        { key: 'week', label: 'This week' },
        { key: '30d', label: '30 days' },
        { key: 'quarter', label: 'Quarter' },
        {
            key: 'custom',
            label: 'Custom range',
            popover: (
                <>
                    <div className="flex items-end gap-2">
                        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
                            From
                            <input type="date" value={customFrom} max={customTo} onChange={(e) => setCustomFrom(e.target.value)} className="rounded-md border border-border bg-background px-2 py-1 text-sm text-foreground" />
                        </label>
                        <label className="flex flex-col gap-1 text-xs text-muted-foreground">
                            To
                            <input type="date" value={customTo} min={customFrom} onChange={(e) => setCustomTo(e.target.value)} className="rounded-md border border-border bg-background px-2 py-1 text-sm text-foreground" />
                        </label>
                    </div>
                    <Button size="sm" className="w-full" onClick={() => go({ from: customFrom, to: customTo })}>
                        Apply range
                    </Button>
                </>
            ),
        },
    ];

    const footer = (
        <>
            <div className="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
                {/* Period range control */}
                <HeroSegmented variant="pill" label="Period" ariaLabel="Period range" items={periodItems} value={activePeriod} onChange={(k) => go(presetRange(k))} />

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
                    <HeroSegmented variant="segmented" label="Lens" ariaLabel="Role lens" items={LENS_ITEMS} value={filters.lens} onChange={(k) => go({ lens: k })} />
                </div>
            </div>

            {/* "This week" summary strip */}
            <HeroSummaryStrip label="This week">
                <HeroSummaryMetric tone="warning">{lagging.incidents} incidents</HeroSummaryMetric>
                <HeroSummaryMetric tone="critical">{notifiableAwaiting} WorkSafe-notifiable</HeroSummaryMetric>
                <HeroSummaryMetric tone="warning">{leading.open_hazards} hazards open</HeroSummaryMetric>
                <HeroSummaryMetric tone="warning">{drillsDue} drills due</HeroSummaryMetric>
                <HeroSummaryMetric tone={activeAlerts > 0 ? 'critical' : 'success'}>
                    {activeAlerts > 0 ? `${activeAlerts} lone-worker alert${activeAlerts === 1 ? '' : 's'}` : 'lone-workers all checked in'}
                </HeroSummaryMetric>
            </HeroSummaryStrip>
        </>
    );

    return (
        <HeroShell footer={footer}>
            {/* Eyebrow + action row */}
            <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <HeroStatusPill>Safety system · synced just now</HeroStatusPill>

                <div className="flex flex-wrap items-center gap-2">
                    {onReport ? (
                        <Button
                            onClick={onReport}
                            className="bg-primary-foreground text-primary shadow-sm hover:bg-primary-foreground/90"
                        >
                            <Plus className="mr-1.5 h-4 w-4" />
                            Report
                        </Button>
                    ) : null}
                    <Link
                        href="/health-safety/analytics"
                        className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                    >
                        <BarChart3 className="h-4 w-4" />
                        View analytics
                    </Link>
                    {canViewBoardReports ? (
                        <Popover>
                            <PopoverTrigger asChild>
                                {/* eslint-disable-next-line no-restricted-syntax -- translucent action pill on the dark hero; not a shadcn Button. */}
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1.5 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-2 text-sm font-medium text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                                >
                                    <Download className="h-4 w-4" />
                                    Export board summary
                                    <ChevronDown className="h-3.5 w-3.5" />
                                </button>
                            </PopoverTrigger>
                            <PopoverContent align="end" className="w-60 p-1">
                                {GOV_REPORTS.map((r) => (
                                    <Link
                                        key={r.href}
                                        href={r.href}
                                        className="block rounded-md px-2.5 py-2 text-[13px] text-foreground transition-colors hover:bg-muted"
                                    >
                                        {r.label}
                                    </Link>
                                ))}
                            </PopoverContent>
                        </Popover>
                    ) : null}
                </div>
            </div>

            {/* Title block */}
            <div className="flex items-start gap-4 md:gap-5">
                <HeroMedallion icon={ShieldCheck} />
                <div className="min-w-0 flex-1">
                    <h1 className="text-2xl font-bold tracking-tight md:text-[28px]">Health &amp; Safety command centre</h1>
                    <p className="mt-1 text-sm text-primary-foreground/75">
                        <span className="underline decoration-primary-foreground/40 underline-offset-4">{siteLabel}</span>
                        {orgName ? ` · ${orgName}` : ''}
                        {` · ${sites.length} site${sites.length === 1 ? '' : 's'} · PCBU duty-holder view`}
                    </p>
                    <HeroComplianceBadges
                        worksafeAwaiting={notifiableAwaiting}
                        sdsExpiring={sdsExpiring}
                        drillsDue={drillsDue}
                    />
                </div>
            </div>

            {/* Stat clusters */}
            <div className="grid gap-3 lg:grid-cols-2">
                <HeroCluster title="Lagging · outcomes" icon={TrendingDown}>
                    <HeroClusterTile href="/incidents" label="Incidents" value={fmt(lagging.incidents)} caption="this period" tone={lagging.incidents > 0 ? 'warning' : 'success'} />
                    <HeroClusterTile href="/health-safety/injuries" label="LTIFR" value={fmt(lagging.ltifr)} caption="per M hrs" tone="neutral" />
                    <HeroClusterTile href="/health-safety/injuries" label="TRIFR" value={fmt(lagging.trifr)} caption="per M hrs" tone="neutral" />
                    <HeroClusterTile href="/health-safety/injuries" label="Days LTI-free" value={fmt(lagging.days_since_lti)} caption="since last LTI" tone={(lagging.days_since_lti ?? 0) >= 30 ? 'success' : 'warning'} />
                </HeroCluster>

                <HeroCluster title="Leading · proactive" icon={Clock}>
                    <HeroClusterTile href="/incidents?type=near_miss" label="Near-miss" value={fmt(leading.near_miss_ratio, '×')} caption=": incident" tone={(leading.near_miss_ratio ?? 0) >= 3 ? 'success' : 'warning'} />
                    <HeroClusterTile href="/health-safety/corrective-actions" label="Actions on time" value={fmt(leading.actions_on_time_pct, '%')} caption="30-day" tone={(leading.actions_on_time_pct ?? 0) >= 90 ? 'success' : 'warning'} />
                    <HeroClusterTile href="/health-safety/worker-participation" label="Train / audit" value={fmt(leading.training_pct, '%')} caption="compliance" tone={(leading.training_pct ?? 0) >= 90 ? 'success' : 'warning'} />
                    <HeroClusterTile href="/compliance/hazards" label="Open hazards" value={fmt(leading.open_hazards)} caption="open now" tone={leading.open_hazards > 0 ? 'warning' : 'success'} />
                </HeroCluster>
            </div>

            {/* Cross-module · related safety workflows reachable from the hub */}
            <HeroCluster title="Across the safety system" icon={ShieldCheck}>
                <HeroClusterTile href="/safeguarding" label="Safeguarding" value={fmt(openSafeguarding)} caption="open concerns" tone={openSafeguarding > 0 ? 'warning' : 'success'} />
                <HeroClusterTile href="/fleet-assets/incidents" label="Fleet incidents" value={fmt(fleetUnresolved)} caption="unresolved" tone={fleetUnresolved > 0 ? 'warning' : 'success'} />
                <HeroClusterTile href="/fleet-assets/incidents" label="Fleet · 30d" value={fmt(fleetIncidents30d)} caption="reported this period" tone={fleetIncidents30d > 0 ? 'warning' : 'success'} />
                <HeroClusterTile href="/health-safety/analytics" label="Analytics" value="View" caption="trends & root cause" tone="neutral" />
            </HeroCluster>
        </HeroShell>
    );
}

export default CommandCentreHero;
