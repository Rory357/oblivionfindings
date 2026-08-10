/* Shared types, RAG/type/status maps, progress maths and small presentational
 * atoms for the Goals & OKR hub. Imported by the hub page, the detail page and
 * the objective / check-in / development wizards so every surface speaks the
 * same vocabulary. Colours come from semantic design tokens. */
import { cn } from '@/lib/utils';

/* ------------------------------------------------------------------ */
/*  Types                                                             */
/* ------------------------------------------------------------------ */

export type Confidence = 'on_track' | 'at_risk' | 'off_track';
export type GoalType = 'company' | 'team' | 'individual';
export type GoalStatus =
    | 'draft'
    | 'active'
    | 'on_hold'
    | 'blocked'
    | 'completed'
    | 'cancelled';
export type KrType =
    | 'number'
    | 'percent'
    | 'currency'
    | 'milestone'
    | 'boolean';

export interface ObjectiveTemplate {
    id: number;
    name: string;
    title: string;
    description: string | null;
    goal_type: GoalType;
    category: string | null;
    priority: 'low' | 'medium' | 'high';
    key_results: Array<{
        title: string;
        kr_type: KrType;
        start_value: number;
        target_value: number;
        unit: string | null;
        weight: number;
    }>;
}

export interface KeyResult {
    id: number;
    title: string;
    kr_type: KrType;
    start_value: number;
    current_value: number;
    target_value: number;
    unit: string | null;
    weight: number;
    progress_percentage: number;
    status: string;
    confidence: Confidence;
    owner: { id: number; name: string } | null;
}

export interface Objective {
    id: number;
    title: string;
    description: string | null;
    goal_type: GoalType;
    category: string | null;
    status: GoalStatus;
    confidence: Confidence;
    priority: 'low' | 'medium' | 'high';
    tags: string[];
    progress_percentage: number;
    target_value: number | null;
    current_value: number | null;
    unit: string | null;
    start_date: string | null;
    due_date: string | null;
    checkin_frequency: string | null;
    last_checkin_at: string | null;
    last_checkin_days: number | null;
    user: { id: number; name: string } | null;
    parent_goal_id: number | null;
    parent_goal: { id: number; title: string } | null;
    cycle: { id: number; name: string } | null;
    cycle_id: number | null;
    key_results_count: number;
    development_count: number;
    key_results: KeyResult[];
}

export interface DevelopmentPlan {
    id: number;
    title: string;
    competency_area: string | null;
    category:
        | 'growth'
        | 'performance'
        | 'leadership'
        | 'compliance'
        | 'capability';
    status:
        | 'not_started'
        | 'in_progress'
        | 'blocked'
        | 'completed'
        | 'cancelled';
    progress_percent: number;
    current_level: number | null;
    target_level: number | null;
    review_frequency: string | null;
    due_date: string | null;
    next_review_at: string | null;
    competency_id: number | null;
    competency: { id: number; name: string } | null;
    employee: { id: number; name: string } | null;
    manager: { id: number; name: string } | null;
    hr_goal_id: number | null;
    linked_objective: { id: number; title: string } | null;
}

export interface Cycle {
    id: number;
    name: string;
    type: string;
    status: string;
    starts_at: string | null;
    ends_at: string | null;
    meta?: string;
}

export interface CascadeNode {
    id: number;
    title: string;
    goal_type: GoalType;
    status: GoalStatus;
    priority: string;
    confidence: Confidence;
    progress_percentage: number;
    due_date: string | null;
    parent_goal_id: number | null;
    user: { id: number; name: string } | null;
    key_results_count: number;
    children: CascadeNode[];
}

/* ------------------------------------------------------------------ */
/*  Vocabulary maps                                                   */
/* ------------------------------------------------------------------ */

export const RAG: Record<
    Confidence,
    { label: string; dot: string; text: string; chip: string }
> = {
    on_track: {
        label: 'On track',
        dot: 'bg-status-success',
        text: 'text-status-success',
        chip: 'bg-status-success-bg text-status-success',
    },
    at_risk: {
        label: 'At risk',
        dot: 'bg-status-warning',
        text: 'text-status-warning',
        chip: 'bg-status-warning-bg text-status-warning',
    },
    off_track: {
        label: 'Off track',
        dot: 'bg-status-critical',
        text: 'text-status-critical',
        chip: 'bg-status-critical-bg text-status-critical',
    },
};

export const TYPE_BADGE: Record<GoalType, string> = {
    company: 'bg-primary/10 text-primary',
    team: 'bg-status-info-bg text-status-info',
    individual: 'bg-status-success-bg text-status-success',
};

export const TYPE_LABEL: Record<GoalType, string> = {
    company: 'Company',
    team: 'Team',
    individual: 'Individual',
};

export const STATUS_BADGE: Record<string, string> = {
    draft: 'bg-muted text-muted-foreground',
    active: 'bg-status-info-bg text-status-info',
    on_hold: 'bg-status-warning-bg text-status-warning',
    blocked: 'bg-status-critical-bg text-status-critical',
    completed: 'bg-status-success-bg text-status-success',
    cancelled: 'bg-status-critical-bg text-status-critical',
};

export const STATUS_LABEL: Record<string, string> = {
    draft: 'Draft',
    active: 'Active',
    on_hold: 'On hold',
    blocked: 'Blocked',
    completed: 'Completed',
    cancelled: 'Cancelled',
};

export const PRIORITY_DOT: Record<string, string> = {
    high: 'bg-status-critical',
    medium: 'bg-status-warning',
    low: 'bg-muted-foreground/50',
};

export const DEV_STATUS_BADGE: Record<string, string> = {
    not_started: 'bg-muted text-muted-foreground',
    in_progress: 'bg-status-info-bg text-status-info',
    blocked: 'bg-status-critical-bg text-status-critical',
    completed: 'bg-status-success-bg text-status-success',
    cancelled: 'bg-muted text-muted-foreground',
};

export const DEV_CAT_BADGE: Record<string, string> = {
    growth: 'bg-status-success-bg text-status-success',
    performance: 'bg-status-info-bg text-status-info',
    leadership: 'bg-primary/10 text-primary',
    compliance: 'bg-status-warning-bg text-status-warning',
    capability: 'bg-status-info-bg text-status-info',
};

export const KR_TYPES: { value: KrType; label: string }[] = [
    { value: 'number', label: 'Number' },
    { value: 'percent', label: 'Percent' },
    { value: 'currency', label: 'Currency' },
    { value: 'milestone', label: 'Milestone' },
    { value: 'boolean', label: 'Yes / No' },
];

export const CADENCES = ['weekly', 'fortnightly', 'monthly', 'quarterly'];

/* ------------------------------------------------------------------ */
/*  Progress maths                                                    */
/* ------------------------------------------------------------------ */

export function clamp(n: number, a: number, b: number) {
    return Math.max(a, Math.min(b, n));
}

/** Baseline-aware KR progress, mirroring HrKeyResult::recalculateProgress. */
export function krProgress(k: {
    start_value: number;
    current_value: number;
    target_value: number;
}) {
    const denom = k.target_value - k.start_value;
    if (denom === 0) return k.current_value >= k.target_value ? 100 : 0;
    return Math.round(
        clamp((k.current_value - k.start_value) / denom, 0, 1) * 100,
    );
}

/** Weighted roll-up across KRs, mirroring GoalService::recalculateGoalProgress. */
export function rollup(
    krs: {
        weight: number;
        start_value: number;
        current_value: number;
        target_value: number;
    }[],
) {
    if (!krs.length) return 0;
    let w = 0;
    let s = 0;
    krs.forEach((k) => {
        const weight = Math.max(1, k.weight || 1);
        w += weight;
        s += weight * krProgress(k);
    });
    return w ? Math.round(s / w) : 0;
}

export function barColor(pct: number) {
    if (pct >= 70) return 'bg-status-success';
    if (pct >= 40) return 'bg-status-warning';
    return 'bg-status-critical';
}

export function initials(name?: string | null) {
    if (!name) return '—';
    return name
        .split(' ')
        .map((x) => x[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

export function formatDate(d: string | null, withYear = false) {
    if (!d) return '—';
    return new Date(d).toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
        ...(withYear ? { year: 'numeric' } : {}),
    });
}

export function checkinLabel(days: number | null) {
    if (days == null) return 'No check-ins';
    if (days <= 0) return 'Checked in today';
    return `${days}d ago`;
}

export function formatKrMeasure(k: KeyResult) {
    const u = k.unit ?? '';
    const fmt = (n: number) => `${n}${u}`;
    return `${fmt(k.start_value)} → ${fmt(k.current_value)} → ${fmt(k.target_value)}`;
}

/* ------------------------------------------------------------------ */
/*  Presentational atoms                                              */
/* ------------------------------------------------------------------ */

export function Avatar({
    name,
    className,
}: {
    name?: string | null;
    className?: string;
}) {
    return (
        <span
            className={cn(
                'inline-grid shrink-0 place-items-center rounded-full bg-primary/10 text-[9.5px] font-bold text-primary',
                className ?? 'h-[22px] w-[22px]',
            )}
        >
            {initials(name)}
        </span>
    );
}

export function RagPill({ confidence }: { confidence: Confidence }) {
    const r = RAG[confidence];
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1.5 text-[11px] font-semibold',
                r.text,
            )}
        >
            <span className={cn('h-1.5 w-1.5 rounded-full', r.dot)} /> {r.label}
        </span>
    );
}

export function ProgressBar({
    pct,
    className,
}: {
    pct: number;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'h-1.5 flex-1 overflow-hidden rounded-full bg-muted',
                className,
            )}
        >
            <div
                className={cn(
                    'h-full rounded-full transition-all',
                    barColor(pct),
                )}
                style={{ width: `${pct}%` }}
            />
        </div>
    );
}

export function TypeBadge({ type }: { type: GoalType }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold',
                TYPE_BADGE[type],
            )}
        >
            {TYPE_LABEL[type]}
        </span>
    );
}
