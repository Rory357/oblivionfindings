/**
 * Two-tier navigation registry for the Health & Clinical command-centre.
 *
 * Mirrors the client-profile `pages/operations/clients/tabs/_groups.ts` pattern:
 * a flat tab list tagged with a group, consumed by the hero group pills + the
 * tier-2 `TabStrip`. `?tab=` deep links map to a tab id; the owning group is
 * derived via `groupForTab()`.
 *
 * `href: null` marks a tab whose backend/panel is not built yet — it is declared
 * here for the full IA but is NOT rendered (no "coming soon" stubs). Tabs flip to
 * a real href as each redesign step lands. Build order per
 * docs/health-clinical-redesign/PROGRESS.md §5.
 */
import {
    Activity,
    AlertTriangle,
    Brain,
    ClipboardList,
    HeartPulse,
    LayoutDashboard,
    Stethoscope,
    TrendingUp,
    Workflow,
    type LucideIcon,
} from 'lucide-react';

import type { RosterTabTone } from '@/components/rostering';

export type HcTabId =
    | 'overview'
    | 'observations'
    | 'clinical_events'
    | 'health_monitoring'
    | 'care_plans'
    | 'protocols'
    | 'assessments'
    | 'behaviour'
    | 'trends';

export type HcGroupKey = 'monitor' | 'plan' | 'analyse';

/** The shared `auth.can.clinical` capability flags used to gate tab visibility. */
export type ClinicalCan = {
    dashboard?: boolean;
    observationsViewAny?: boolean;
    observationsViewAssigned?: boolean;
    eventsViewAny?: boolean;
    monitoringViewAny?: boolean;
    behaviourViewAny?: boolean;
    protocolsViewAny?: boolean;
    protocolsManage?: boolean;
    assessmentsViewAny?: boolean;
};

export type HcTab = {
    id: HcTabId;
    label: string;
    icon: LucideIcon;
    tone: RosterTabTone;
    group: HcGroupKey;
    /** Route the tab navigates to; `null` until the panel is built (not rendered). */
    href: string | null;
    /** Capability predicate — the tab is only shown when the user can open its route. */
    requires?: (can: ClinicalCan) => boolean;
};

export const HC_GROUPS: { key: HcGroupKey; label: string }[] = [
    { key: 'monitor', label: 'Monitor' },
    { key: 'plan', label: 'Plan' },
    { key: 'analyse', label: 'Analyse' },
];

export const HC_TABS: HcTab[] = [
    // ── Monitor ──────────────────────────────────────────────────────────
    { id: 'overview', label: 'Overview', icon: LayoutDashboard, tone: 'primary', group: 'monitor', href: '/health-clinical', requires: (c) => !!c.dashboard },
    { id: 'observations', label: 'Observations', icon: Activity, tone: 'primary', group: 'monitor', href: '/health-clinical/observations', requires: (c) => !!c.observationsViewAny },
    { id: 'clinical_events', label: 'Clinical Events', icon: Stethoscope, tone: 'warning', group: 'monitor', href: '/health-clinical/events', requires: (c) => !!c.eventsViewAny },
    { id: 'health_monitoring', label: 'Health Monitoring', icon: HeartPulse, tone: 'info', group: 'monitor', href: '/health-clinical/health-monitoring', requires: (c) => !!c.monitoringViewAny },
    // ── Plan ─────────────────────────────────────────────────────────────
    { id: 'care_plans', label: 'Care Plans', icon: ClipboardList, tone: 'success', group: 'plan', href: '/health-clinical/care-plans', requires: (c) => !!c.dashboard },
    { id: 'protocols', label: 'Protocols', icon: Workflow, tone: 'primary', group: 'plan', href: '/health-clinical/protocols', requires: (c) => !!(c.protocolsViewAny || c.protocolsManage) },
    { id: 'assessments', label: 'Assessments & Risk', icon: AlertTriangle, tone: 'critical', group: 'plan', href: '/health-clinical/assessments', requires: (c) => !!c.assessmentsViewAny },
    // ── Analyse ──────────────────────────────────────────────────────────
    { id: 'behaviour', label: 'Behaviour', icon: Brain, tone: 'violet', group: 'analyse', href: '/health-clinical/behaviour', requires: (c) => !!c.behaviourViewAny },
    { id: 'trends', label: 'Trends', icon: TrendingUp, tone: 'info', group: 'analyse', href: '/health-clinical/trends', requires: (c) => !!(c.observationsViewAny || c.observationsViewAssigned) },
];

export function tabById(id: HcTabId): HcTab | undefined {
    return HC_TABS.find((t) => t.id === id);
}

export function groupForTab(id: HcTabId): HcGroupKey {
    return tabById(id)?.group ?? 'monitor';
}

/**
 * Built tabs (href set) for a group, in declaration order. When `can` is
 * provided, tabs the user cannot open (per their `requires` predicate) are
 * filtered out so the UI never offers a tab that 403s on click.
 */
export function builtTabsForGroup(group: HcGroupKey, can?: ClinicalCan): HcTab[] {
    return HC_TABS.filter(
        (t) => t.group === group && t.href && (!can || !t.requires || t.requires(can)),
    );
}

/** Groups that currently have at least one built (and permitted) tab. */
export function groupsWithBuiltTabs(can?: ClinicalCan): { key: HcGroupKey; label: string }[] {
    return HC_GROUPS.filter((g) => builtTabsForGroup(g.key, can).length > 0);
}
