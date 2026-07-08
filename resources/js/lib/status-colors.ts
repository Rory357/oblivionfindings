/**
 * Status-colour classes for badges and pills.
 *
 * Values are built from semantic tokens (bg-status-*, text-status-*,
 * border-status-*) defined in resources/css/app.css, so every badge across
 * the app re-tints when the Branding page changes --primary. No raw
 * Tailwind colour classes live here any more.
 *
 * Severity mapping:
 *   success / active / approved / completed / verified / low / closed (when resolved)
 *     → bg-status-success-bg text-status-success border-status-success/30
 *   warning / pending / medium / corrective_action / under_review / investigating / monitoring
 *     → bg-status-warning-bg text-status-warning border-status-warning/30
 *   critical / overdue / rejected / high / extreme
 *     → bg-status-critical-bg text-status-critical border-status-critical/30
 *   info / open / in_progress / findings_recorded
 *     → bg-status-info-bg text-status-info border-status-info/30
 *   neutral / draft / cancelled / archived / superseded / closed (inactive)
 *     → bg-muted text-muted-foreground border-border
 */

const SUCCESS = 'bg-status-success-bg text-status-success border-status-success/30';
const WARNING = 'bg-status-warning-bg text-status-warning border-status-warning/30';
const CRITICAL = 'bg-status-critical-bg text-status-critical border-status-critical/30';
const INFO = 'bg-status-info-bg text-status-info border-status-info/30';
const NEUTRAL = 'bg-muted text-muted-foreground border-border';

export const statusColors: Record<string, string> = {
    // General statuses
    draft: NEUTRAL,
    pending: WARNING,
    active: SUCCESS,
    approved: SUCCESS,
    completed: INFO,
    rejected: CRITICAL,
    cancelled: NEUTRAL,
    overdue: CRITICAL,
    in_progress: INFO,
    under_review: WARNING,
    archived: NEUTRAL,

    // Severity levels
    critical: CRITICAL,
    high: CRITICAL,
    medium: WARNING,
    low: SUCCESS,
    extreme: CRITICAL,

    // H&S specific statuses
    open: INFO,
    investigating: WARNING,
    corrective_action: WARNING,
    monitoring: INFO,
    closed: NEUTRAL,
    verified: SUCCESS,
    findings_recorded: INFO,
    superseded: NEUTRAL,

    // Finance statuses (AR/AP/banking/tax) — additive; each maps to the same
    // severity semantics so <StatusBadge status={x}/> is a clean drop-in for the
    // ~38 finance pages that used to hand-roll a local statusConfig colour map.
    sent: INFO,
    viewed: INFO,
    paid: SUCCESS,
    billed: INFO,
    partially_paid: WARNING,
    unpaid: WARNING,
    awaiting_approval: WARNING,
    awaiting_payment: WARNING,
    processing: INFO,
    failed: CRITICAL,
    validated: INFO,
    submitted: WARNING,
    accepted: SUCCESS,
    error: CRITICAL,
    applied: SUCCESS,
    declined: CRITICAL,
    expired: NEUTRAL,
    converted: SUCCESS,
    disposed: NEUTRAL,
    fully_depreciated: NEUTRAL,
    partially_received: WARNING,
    received: SUCCESS,
    reconciled: SUCCESS,
    unreconciled: WARNING,
    matched: SUCCESS,
    unmatched: WARNING,
    posted: SUCCESS,
    prepared: INFO,
    finalised: INFO,
    finalized: INFO,
    filed: SUCCESS,
    locked: INFO,
    simulated: WARNING,
    amended: INFO,
    reversed: CRITICAL,
    discrepancy: CRITICAL,
    generating: INFO,
    eliminated: INFO,
    partial: WARNING,
    final: SUCCESS,
    fully_spent: WARNING,
    returned: NEUTRAL,
};

export function getStatusColor(status: string): string {
    const normalized = status.toLowerCase().replace(/[\s-]/g, '_');
    return statusColors[normalized] ?? statusColors.draft ?? NEUTRAL;
}

/**
 * Risk score heatmap. Still uses severity semantics but via the semantic
 * tokens — matches the rest of the app when branding changes.
 */
export const riskScoreColor = (score: number): string => {
    if (score >= 20) return 'bg-status-critical-bg text-status-critical-foreground';
    if (score >= 15) return 'bg-status-warning-bg text-status-warning-foreground';
    if (score >= 10) return 'bg-status-warning-bg text-status-warning';
    return 'bg-status-success-bg text-status-success-foreground';
};
