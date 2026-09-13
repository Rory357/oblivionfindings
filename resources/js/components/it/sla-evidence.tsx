import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTime } from '@/lib/datetime';

export type SlaState =
    | 'ok'
    | 'at_risk'
    | 'breached'
    | 'met'
    | 'paused'
    | 'unmeasured';
export interface SlaClock {
    state: SlaState;
    reason: string | null;
    due_at: string | null;
    completed_at: string | null;
    breached_at: string | null;
    ever_breached: boolean;
    policy_recorded: boolean;
    paused: boolean;
    paused_minutes: number | null;
    remaining_minutes: number | null;
}
export interface SlaVerdict {
    state: SlaState;
    coverage: 'none' | 'partial' | 'full';
    ever_breached: boolean;
    evaluated_at: string;
    clocks: { first_response: SlaClock; resolution: SlaClock };
}
export interface SlaSummary {
    total: number;
    by_state: Record<SlaState, number>;
    by_coverage: { none: number; partial: number; full: number };
    ever_breached: number;
    clocks: Record<
        'first_response' | 'resolution',
        { by_state: Record<SlaState, number> }
    >;
    evaluated_at: string;
}
export interface SlaWatchdog {
    state: 'fresh' | 'stale' | 'failed' | 'running' | 'unmeasured';
    last_success_at: string | null;
    latest_status: string | null;
    latest_started_at: string | null;
    required_since: string;
    evaluated_at: string;
    grace_seconds: number;
}
export const SLA_LABELS: Record<SlaState, string> = {
    ok: 'On track',
    at_risk: 'At risk',
    breached: 'Breached',
    met: 'Met',
    paused: 'Paused',
    unmeasured: 'Unmeasured',
};
export const SLA_VARIANTS: Record<SlaState, StatusVariant> = {
    ok: 'info',
    at_risk: 'warning',
    breached: 'critical',
    met: 'success',
    paused: 'neutral',
    unmeasured: 'neutral',
};
const REASONS: Record<string, string> = {
    clock_not_recorded: 'The clock was not recorded.',
    invalid_clock_order: 'The recorded timestamps need review.',
    reopen_clock_policy_required:
        'A resolution clock for this reopened ticket has not been agreed.',
    pause_calendar_not_recorded:
        'The original pause calendar was not recorded.',
    completion_not_recorded: 'The completion time was not recorded.',
    risk_calendar_not_recorded:
        'The original target or calendar was not recorded.',
    calendar_cannot_measure: 'The recorded calendar cannot measure this clock.',
};

export function SlaWatchdogNote({
    watchdog,
}: {
    watchdog?: SlaWatchdog | null;
}) {
    const labels = {
        fresh: 'SLA watchdog current',
        stale: 'SLA watchdog stale',
        failed: 'SLA watchdog failed',
        running: 'SLA watchdog running; no successful check recorded',
        unmeasured: 'SLA watchdog has no successful check recorded',
    };
    return (
        <p className="text-xs text-muted-foreground">
            {labels[watchdog?.state ?? 'unmeasured']}
            {watchdog?.last_success_at
                ? ` · Last successful check ${formatDateTime(watchdog.last_success_at)}`
                : ''}
            . Ticket clocks are calculated when this page loads.
        </p>
    );
}

export function SlaEvidence({ sla }: { sla?: SlaVerdict }) {
    if (!sla)
        return (
            <p className="text-sm text-muted-foreground">
                SLA unmeasured. Clock evidence is unavailable.
            </p>
        );
    return (
        <div className="space-y-3">
            <p className="text-xs text-muted-foreground">
                {sla.coverage === 'full'
                    ? 'Both clocks measured'
                    : sla.coverage === 'partial'
                      ? 'One clock unmeasured'
                      : 'Both clocks unmeasured'}
                {' · '}Checked {formatDateTime(sla.evaluated_at)}
            </p>
            {(['first_response', 'resolution'] as const).map((name) => {
                const clock = sla.clocks[name];
                return (
                    <div key={name} className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2 text-sm font-medium">
                            {name === 'first_response'
                                ? 'First response'
                                : 'Resolution'}
                            <StatusBadge
                                variant={SLA_VARIANTS[clock.state]}
                                size="sm"
                            >
                                {SLA_LABELS[clock.state]}
                            </StatusBadge>
                        </div>
                        <p className="text-xs text-muted-foreground">
                            {clock.reason
                                ? (REASONS[clock.reason] ??
                                  'Clock evidence needs review.')
                                : clock.completed_at
                                  ? `Completed ${formatDateTime(clock.completed_at)}`
                                  : clock.paused
                                    ? 'Paused while waiting. The first-response clock is independent.'
                                    : clock.due_at
                                      ? `Due ${formatDateTime(clock.due_at)}`
                                      : 'No deadline recorded.'}
                        </p>
                        {clock.ever_breached && (
                            <p className="text-xs text-muted-foreground">
                                Earlier breach retained
                                {clock.breached_at
                                    ? ` · ${formatDateTime(clock.breached_at)}`
                                    : ''}
                                .
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
