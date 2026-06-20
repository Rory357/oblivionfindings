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

export type HcTab = {
    id: HcTabId;
    label: string;
    icon: LucideIcon;
    tone: RosterTabTone;
    group: HcGroupKey;
    /** Route the tab navigates to; `null` until the panel is built (not rendered). */
    href: string | null;
};

export const HC_GROUPS: { key: HcGroupKey; label: string }[] = [
    { key: 'monitor', label: 'Monitor' },
    { key: 'plan', label: 'Plan' },
    { key: 'analyse', label: 'Analyse' },
];

export const HC_TABS: HcTab[] = [
    // ── Monitor ──────────────────────────────────────────────────────────
    { id: 'overview', label: 'Overview', icon: LayoutDashboard, tone: 'primary', group: 'monitor', href: '/health-clinical' },
    { id: 'observations', label: 'Observations', icon: Activity, tone: 'primary', group: 'monitor', href: '/health-clinical/observations' },
    { id: 'clinical_events', label: 'Clinical Events', icon: Stethoscope, tone: 'warning', group: 'monitor', href: '/health-clinical/events' },
    { id: 'health_monitoring', label: 'Health Monitoring', icon: HeartPulse, tone: 'info', group: 'monitor', href: '/health-clinical/health-monitoring' },
    // ── Plan ─────────────────────────────────────────────────────────────
    { id: 'care_plans', label: 'Care Plans', icon: ClipboardList, tone: 'success', group: 'plan', href: '/health-clinical/care-plans' },
    { id: 'protocols', label: 'Protocols', icon: Workflow, tone: 'primary', group: 'plan', href: '/health-clinical/protocols' },
    { id: 'assessments', label: 'Assessments & Risk', icon: AlertTriangle, tone: 'critical', group: 'plan', href: null },
    // ── Analyse ──────────────────────────────────────────────────────────
    { id: 'behaviour', label: 'Behaviour', icon: Brain, tone: 'violet', group: 'analyse', href: '/health-clinical/behaviour' },
    { id: 'trends', label: 'Trends', icon: TrendingUp, tone: 'info', group: 'analyse', href: null },
];

export function tabById(id: HcTabId): HcTab | undefined {
    return HC_TABS.find((t) => t.id === id);
}

export function groupForTab(id: HcTabId): HcGroupKey {
    return tabById(id)?.group ?? 'monitor';
}

/** Built tabs (href set) for a group, in declaration order. */
export function builtTabsForGroup(group: HcGroupKey): HcTab[] {
    return HC_TABS.filter((t) => t.group === group && t.href);
}

/** Groups that currently have at least one built tab (so empty groups don't render). */
export function groupsWithBuiltTabs(): { key: HcGroupKey; label: string }[] {
    return HC_GROUPS.filter((g) => builtTabsForGroup(g.key).length > 0);
}
