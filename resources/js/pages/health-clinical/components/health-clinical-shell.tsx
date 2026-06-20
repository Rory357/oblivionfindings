/**
 * Shared command-centre shell for every Health & Clinical sub-tab.
 *
 * Composes the H&S hero kit (`hs-hero-kit`) — the single source of the command-
 * centre hero chrome shared with /health-safety and /emar — plus a two-tier
 * navigation (Monitor / Plan / Analyse group pills over a tier-2 `TabStrip`).
 * Each register page renders its panel as `children`; navigating a tab is an
 * Inertia visit to that tab's route, which keeps the governance deep-link
 * `/health-clinical/events?event_type=…` a real, stable URL.
 *
 * Semantic tokens only; app-primary gradient. NZ / web-only / need-to-know.
 */
import AppLayout from '@/layouts/app-layout';
import { Button } from '@/components/ui/button';
import {
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroShell,
    HeroStatusPill,
    fmt,
} from '@/pages/health-safety/components/hs-hero-kit';
import {
    TabStrip,
    type RosterTabItem,
} from '@/components/rostering';
import { cn } from '@/lib/utils';
import { RecordEventDialog } from '@/pages/health-clinical/components/record-event-dialog';
import { RecordObservationDialog } from '@/pages/health-clinical/components/record-observation-dialog';
import { Head, router, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    Eye,
    HeartPulse,
    Lock,
    ShieldCheck,
    Stethoscope,
    type LucideIcon,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

import {
    builtTabsForGroup,
    groupForTab,
    groupsWithBuiltTabs,
    type HcGroupKey,
    type HcTabId,
} from '@/pages/health-clinical/lib/tab-groups';

/* ------------------------------------------------------------------ */
/*  Hero KPI payload (returned by every sub-tab controller)            */
/* ------------------------------------------------------------------ */

export type HealthClinicalKpis = {
    // Compliance & coverage
    protocols_active: number;
    observations_7d: number;
    observations_today: number;
    schedules_due: number;
    schedules_overdue: number;
    compliance_rate_30d: number;
    // Clinical risk & events
    events_30d: number;
    events_high_severity_30d: number;
    // Optional — populated as later steps land (NEWS2 watch, sign-off, governance chips)
    clients_on_watch?: number | null;
    events_unreviewed?: number | null;
    events_pending_followup?: number | null;
    nga_paerewa_certified?: boolean | null;
    restraint_register_current?: boolean | null;
};

export type HealthClinicalShellProps = {
    activeTab: HcTabId;
    kpis: HealthClinicalKpis;
    /** Per-tab attention badges (sum bubbles up to the owning group pill). */
    tabCounts?: Partial<Record<HcTabId, number>>;
    children: ReactNode;
};

type ClinicalAbilities = {
    observationsRecord?: boolean;
    observationsRecordClinical?: boolean;
    eventsRecord?: boolean;
};

/* ------------------------------------------------------------------ */
/*  Bespoke clinical compliance chips                                  */
/*  (NZ-clinical labels — not the H&S HeroComplianceBadges set; only   */
/*   the token maps are mirrored, per the redesign de-dup note.)       */
/* ------------------------------------------------------------------ */

type ChipTone = 'success' | 'warning' | 'critical' | 'neutral';

const CHIP_CLASS: Record<ChipTone, string> = {
    success: 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90',
    warning: 'border-status-warning/50 bg-status-warning/25 text-primary-foreground',
    critical: 'border-status-critical/50 bg-status-critical/25 text-primary-foreground',
    neutral: 'border-primary-foreground/20 bg-primary-foreground/5 text-primary-foreground/80',
};
const CHIP_ICON: Record<ChipTone, string> = {
    success: 'text-primary-foreground/80',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    neutral: 'text-primary-foreground/70',
};

function ClinicalChips({ kpis }: { kpis: HealthClinicalKpis }) {
    const onWatch = kpis.clients_on_watch ?? null;
    const signOff = kpis.events_unreviewed ?? null;
    const ngaCertified = kpis.nga_paerewa_certified ?? true;
    const restraintCurrent = kpis.restraint_register_current ?? true;

    const chips: { icon: LucideIcon; tone: ChipTone; label: string }[] = [
        {
            icon: ShieldCheck,
            tone: ngaCertified ? 'success' : 'warning',
            label: `Ngā Paerewa · ${ngaCertified ? 'Certified' : 'Review due'}`,
        },
        {
            icon: onWatch && onWatch > 0 ? AlertTriangle : HeartPulse,
            tone: onWatch === null ? 'neutral' : onWatch > 0 ? 'warning' : 'success',
            label:
                onWatch === null
                    ? 'Deterioration watch · NEWS2'
                    : onWatch > 0
                      ? `Deterioration watch · ${onWatch} on watch`
                      : 'Deterioration watch · all stable',
        },
        {
            icon: signOff && signOff > 0 ? AlertTriangle : CheckCircle2,
            tone: signOff === null ? 'neutral' : signOff > 0 ? 'warning' : 'success',
            label:
                signOff === null
                    ? 'Sign-off backlog'
                    : signOff > 0
                      ? `Sign-off backlog · ${signOff} due`
                      : 'Sign-off · up to date',
        },
        {
            icon: Lock,
            tone: restraintCurrent ? 'success' : 'warning',
            label: `Restraint register · ${restraintCurrent ? 'current' : 'review due'}`,
        },
    ];

    return (
        <div className="mt-1 flex flex-wrap gap-2">
            {chips.map((c, i) => (
                <span
                    key={i}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium',
                        CHIP_CLASS[c.tone],
                    )}
                >
                    <c.icon className={cn('h-3.5 w-3.5', CHIP_ICON[c.tone])} />
                    {c.label}
                </span>
            ))}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Two-tier navigation (group pills + tier-2 tab strip)               */
/* ------------------------------------------------------------------ */

function complianceTone(rate: number): 'success' | 'warning' | 'critical' {
    return rate >= 90 ? 'success' : rate >= 70 ? 'warning' : 'critical';
}

function GroupPills({
    activeGroup,
    tabCounts,
    onSelect,
}: {
    activeGroup: HcGroupKey;
    tabCounts?: Partial<Record<HcTabId, number>>;
    onSelect: (group: HcGroupKey) => void;
}) {
    const groups = groupsWithBuiltTabs();
    return (
        <div className="flex flex-wrap items-center gap-1.5" role="tablist" aria-label="Clinical navigation groups">
            {groups.map((g) => {
                const active = g.key === activeGroup;
                const attention = builtTabsForGroup(g.key).reduce(
                    (sum, t) => sum + (tabCounts?.[t.id] ?? 0),
                    0,
                );
                return (
                    // eslint-disable-next-line no-restricted-syntax -- tier-1 group pill: an intentional segmented tab control, not a shadcn Button
                    <button
                        key={g.key}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onSelect(g.key)}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full px-3.5 py-1.5 text-[13px] font-semibold transition-colors',
                            active
                                ? 'bg-primary text-primary-foreground shadow-sm'
                                : 'bg-muted text-muted-foreground hover:bg-accent hover:text-foreground',
                        )}
                    >
                        {g.label}
                        {attention > 0 ? (
                            <span
                                className={cn(
                                    'inline-flex min-w-[18px] items-center justify-center rounded-full px-1 py-0.5 text-[10px] font-bold tabular-nums',
                                    active ? 'bg-primary-foreground/20 text-primary-foreground' : 'bg-background text-foreground',
                                )}
                            >
                                {attention}
                            </span>
                        ) : null}
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Shell                                                              */
/* ------------------------------------------------------------------ */

export function HealthClinicalShell({
    activeTab,
    kpis,
    tabCounts,
    children,
}: HealthClinicalShellProps) {
    const activeGroup = groupForTab(activeTab);
    const page = usePage<{ auth?: { can?: { clinical?: ClinicalAbilities } } }>();
    const can = page.props.auth?.can?.clinical ?? {};
    const canRecordObs = !!(can.observationsRecord || can.observationsRecordClinical);
    const canRecordEvent = !!can.eventsRecord;
    const [obsOpen, setObsOpen] = useState(false);
    const [eventOpen, setEventOpen] = useState(false);

    const go = (href: string | null) => {
        if (href) router.visit(href, { preserveScroll: true });
    };

    const selectGroup = (group: HcGroupKey) => {
        const first = builtTabsForGroup(group)[0];
        if (first && first.id !== activeTab) go(first.href);
    };

    const tabItems: RosterTabItem[] = builtTabsForGroup(activeGroup).map((t) => ({
        id: t.id,
        label: t.label,
        icon: t.icon,
        tone: t.tone,
        badge: tabCounts?.[t.id] || undefined,
    }));

    return (
        <AppLayout breadcrumbs={[{ title: 'Health & Clinical', href: '/health-clinical' }]}>
            <Head title="Health & Clinical" />

            <div className="flex flex-col gap-6 p-6">
                {/* ── Command-centre hero ── */}
                <HeroShell>
                    <div className="flex flex-wrap items-start justify-between gap-4">
                        <div className="flex items-start gap-4">
                            <HeroMedallion icon={HeartPulse} />
                            <div className="flex flex-col gap-1.5">
                                <HeroStatusPill>Clinical command centre · synced just now</HeroStatusPill>
                                <h1 className="text-2xl font-bold tracking-tight text-primary-foreground md:text-[28px]">
                                    Health &amp; Clinical
                                </h1>
                                <p className="max-w-xl text-sm text-primary-foreground/70">
                                    Observations, deterioration watch and clinical events for registered nurses and
                                    clinical leads — record at the point of care and close the loop on sign-off.
                                </p>
                            </div>
                        </div>

                        {canRecordObs || canRecordEvent ? (
                            <div className="flex flex-wrap items-center gap-2">
                                {canRecordObs ? (
                                    <Button
                                        size="sm"
                                        onClick={() => setObsOpen(true)}
                                        className="bg-primary-foreground text-primary hover:bg-primary-foreground/90"
                                    >
                                        <Activity className="mr-1.5 h-4 w-4" /> Record observation
                                    </Button>
                                ) : null}
                                {canRecordEvent ? (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        onClick={() => setEventOpen(true)}
                                        className="border border-primary-foreground/30 text-primary-foreground hover:bg-primary-foreground/10"
                                    >
                                        <Stethoscope className="mr-1.5 h-4 w-4" /> Log clinical event
                                    </Button>
                                ) : null}
                            </div>
                        ) : null}
                    </div>

                    {/* stat clusters */}
                    <div className="grid gap-3 lg:grid-cols-2">
                        <HeroCluster title="Compliance & coverage" icon={ShieldCheck}>
                            <HeroClusterTile
                                href="/health-clinical/protocols"
                                label="Active protocols"
                                value={fmt(kpis.protocols_active)}
                                caption="across all clients"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/health-clinical/observations"
                                label="Observations 7d"
                                value={fmt(kpis.observations_7d)}
                                caption={`${kpis.observations_today} today`}
                                tone="neutral"
                            />
                            <HeroClusterTile
                                label="Compliance 30d"
                                value={fmt(kpis.compliance_rate_30d, '%')}
                                caption="protocols on time"
                                tone={complianceTone(kpis.compliance_rate_30d)}
                            />
                            <HeroClusterTile
                                href="/health-clinical/observations"
                                label="Overdue"
                                value={fmt(kpis.schedules_overdue)}
                                caption={kpis.schedules_due > 0 ? `${kpis.schedules_due} due soon` : 'all on track'}
                                tone={kpis.schedules_overdue > 0 ? 'critical' : 'success'}
                            />
                        </HeroCluster>
                        <HeroCluster title="Clinical risk & events" icon={Activity}>
                            <HeroClusterTile
                                href="/health-clinical/events"
                                label="Events 30d"
                                value={fmt(kpis.events_30d)}
                                caption="last 30 days"
                                tone="neutral"
                            />
                            <HeroClusterTile
                                href="/health-clinical/events"
                                label="High severity"
                                value={fmt(kpis.events_high_severity_30d)}
                                caption="last 30 days"
                                tone={kpis.events_high_severity_30d > 0 ? 'critical' : 'success'}
                            />
                            <HeroClusterTile
                                label="On watch"
                                value={fmt(kpis.clients_on_watch ?? null)}
                                caption="NEWS2 ≥ medium"
                                tone={(kpis.clients_on_watch ?? 0) > 0 ? 'critical' : 'success'}
                            />
                            <HeroClusterTile
                                href="/health-clinical/events"
                                label="Sign-off due"
                                value={fmt(kpis.events_unreviewed ?? null)}
                                caption="awaiting review"
                                tone={(kpis.events_unreviewed ?? 0) > 0 ? 'warning' : 'success'}
                            />
                        </HeroCluster>
                    </div>

                    <ClinicalChips kpis={kpis} />
                </HeroShell>

                {/* ── Two-tier navigation ── */}
                <div className="flex flex-col gap-2.5">
                    <GroupPills activeGroup={activeGroup} tabCounts={tabCounts} onSelect={selectGroup} />
                    <TabStrip
                        value={activeTab}
                        onChange={(id) => go(builtTabsForGroup(activeGroup).find((t) => t.id === id)?.href ?? null)}
                        items={tabItems}
                        ariaLabel="Clinical views"
                    />
                </div>

                {/* ── Active panel ── */}
                {children}
            </div>

            <RecordObservationDialog
                open={obsOpen}
                onClose={() => setObsOpen(false)}
                canRecordClinical={!!can.observationsRecordClinical}
            />
            <RecordEventDialog open={eventOpen} onClose={() => setEventOpen(false)} />
        </AppLayout>
    );
}

/* ------------------------------------------------------------------ */
/*  Register stat strip (per-tab context below the global hero)        */
/* ------------------------------------------------------------------ */

export type RegisterStat = {
    label: string;
    value: string | number;
    tone?: 'default' | 'warning' | 'critical' | 'success';
};

const STAT_TONE: Record<NonNullable<RegisterStat['tone']>, string> = {
    default: 'text-foreground',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    success: 'text-status-success',
};

/** A compact strip of register-specific counts, shown above a register's filters. */
export function RegisterStatStrip({ stats }: { stats: RegisterStat[] }) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact inline metric strip, not a Card content surface
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 rounded-xl border border-border bg-card px-4 py-2.5">
            {stats.map((s, i) => (
                <div key={i} className="flex items-baseline gap-1.5">
                    <span className={cn('text-[15px] font-bold tabular-nums', STAT_TONE[s.tone ?? 'default'])}>
                        {s.value}
                    </span>
                    <span className="text-[11px] font-medium tracking-wide text-muted-foreground uppercase">
                        {s.label}
                    </span>
                </div>
            ))}
        </div>
    );
}

export default HealthClinicalShell;
