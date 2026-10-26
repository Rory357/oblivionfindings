/**
 * Where a safeguarding concern is up to — shared by the register rows (stage
 * dots, drawing pg-hsn-21) and the concern record (stage line and next step,
 * pg-hsn-22). Everything here reads the server's own fields; nothing is
 * guessed. The six stages mirror SafeguardingConcernController::STAGE_INDEX
 * (referred_external sits beside Investigating, no_action_required beside
 * Triaged).
 */
import type { StatusVariant } from '@/components/ui/status-badge';

export const CONCERN_STAGES = [
    'Reported',
    'Triaged',
    'Investigating',
    'Action plan',
    'Monitoring',
    'Closed',
] as const;

export const CONCERN_STATUS_LABELS: Record<string, string> = {
    reported: 'Awaiting triage',
    triaged: 'Triaged',
    investigating: 'Under investigation',
    action_plan: 'Action plan',
    monitoring: 'Monitoring',
    referred_external: 'Referred external',
    closed: 'Closed',
    no_action_required: 'No further action',
};

export const SEVERITY_LABELS: Record<string, string> = {
    low: 'Low',
    medium: 'Medium',
    high: 'High',
    critical: 'Critical',
};

export const SEVERITY_VARIANT: Record<string, StatusVariant> = {
    low: 'success',
    medium: 'warning',
    high: 'critical',
    critical: 'critical',
};

/** Risk levels read the same way as severity (very high / high → critical). */
export function riskVariant(level: string | null | undefined): StatusVariant {
    if (!level) return 'neutral';
    if (['high', 'very_high', 'critical', 'severe'].includes(level)) return 'critical';
    if (['medium', 'moderate'].includes(level)) return 'warning';
    if (['low', 'none', 'minimal'].includes(level)) return 'success';
    return 'neutral';
}

export const TERMINAL_STATUSES = ['closed', 'no_action_required'];

export function isTerminal(status: string): boolean {
    return TERMINAL_STATUSES.includes(status);
}

export function titleCase(value: string | null | undefined): string {
    return (value ?? '')
        .replace(/[_-]/g, ' ')
        .replace(/\b\w/g, (c) => c.toUpperCase());
}

/** "Abuse · Psychological" — the concern type with its category. */
export function concernKind(type: string | null | undefined, category?: string | null): string {
    return [titleCase(type), category ? titleCase(category) : null]
        .filter(Boolean)
        .join(' · ');
}

export interface ConcernRowFlags {
    has_alert: boolean;
    requires_referral: boolean;
    referral_overdue: boolean;
    review_due: boolean;
    action_overdue: boolean;
}

/** The register row's stage dots: stage 1–6, the status in words, and the flags that need someone. */
export function concernRowStage(row: {
    status: string;
    flags: ConcernRowFlags;
}): {
    stage: number;
    label: string;
    tone?: 'critical' | 'warning' | 'success';
    note?: string;
} {
    const index: Record<string, number> = {
        reported: 0,
        triaged: 1,
        investigating: 2,
        action_plan: 3,
        monitoring: 4,
        closed: 5,
        referred_external: 2,
        no_action_required: 1,
    };
    const notes = [
        row.flags.referral_overdue ? 'Referral not logged' : null,
        row.flags.has_alert ? 'Protective alert active' : null,
        row.flags.action_overdue ? 'Action overdue' : null,
        row.flags.review_due ? 'Risk review due' : null,
    ].filter(Boolean) as string[];
    const tone =
        row.flags.referral_overdue || row.flags.action_overdue
            ? 'critical'
            : row.status === 'reported' || row.flags.review_due
              ? 'warning'
              : row.status === 'closed'
                ? 'success'
                : undefined;
    return {
        stage: (index[row.status] ?? 0) + 1,
        label: CONCERN_STATUS_LABELS[row.status] ?? titleCase(row.status),
        tone,
        note: notes.slice(0, 2).join(' · ') || undefined,
    };
}
