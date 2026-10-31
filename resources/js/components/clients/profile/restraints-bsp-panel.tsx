/* Read-only "Restrictive practice & behaviour support" panel for the client
 * profile (Behaviour / ABC tab). Self-fetches a summary from the restraint
 * register and deep-links every row into it — no mutation here. Hidden entirely
 * when the viewer lacks restraints.view (403) or the client has no restraint data. */
import { Button as GuardrailButton } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';
import { restraintHref } from '@/lib/health-safety/restraint-navigation';
import {
    REVIEW_STATE_META,
    severityMeta,
    typeMeta,
} from '@/pages/health-safety/restraints/shared';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    BookOpen,
    ChevronRight,
    HeartPulse,
    ShieldAlert,
} from 'lucide-react';
import { useEffect, useState } from 'react';

export type ActivePlan = {
    id: number;
    reference: string;
    title: string;
    status: string;
    review_date: string | null;
    review_state: 'ok' | 'due' | 'overdue' | 'unknown';
    published_version_id: number | null;
    authorization_state: string;
};
export type RecentEvent = {
    id: number;
    reference: string;
    restraint_type: string;
    severity: string;
    started_at: string | null;
    within_support_plan: boolean;
    injury_occurred: boolean;
    reviewed_at: string | null;
    source_revision: number;
    needs_review: boolean;
};
export type RestraintSummary = {
    active_plan: ActivePlan | null;
    recent_events: RecentEvent[];
    total_events: number;
};

export function RestraintsBspPanel({ clientId }: { clientId: number }) {
    const [summary, setSummary] = useState<{
        clientId: number;
        data: RestraintSummary;
    } | null>(null);
    const [loaded, setLoaded] = useState(false);

    useEffect(() => {
        let live = true;
        (async () => {
            try {
                const res = await fetch(
                    `/health-safety/restraints/clients/${clientId}/summary`,
                    { headers: { Accept: 'application/json' } },
                );
                if (!res.ok) throw new Error('forbidden');
                const json: RestraintSummary = await res.json();
                if (live) setSummary({ clientId, data: json });
            } catch {
                if (live) setSummary(null);
            } finally {
                if (live) setLoaded(true);
            }
        })();
        return () => {
            live = false;
        };
    }, [clientId]);

    // Hide entirely when not permitted or the client has no restrictive-practice data.
    if (
        !loaded ||
        !summary ||
        summary.clientId !== clientId ||
        (!summary.data.active_plan && summary.data.recent_events.length === 0)
    )
        return null;

    return (
        <RestraintsBspSummary
            summary={summary.data}
            clientId={clientId}
            origin={`/operations/clients/${clientId}?tab=observations`}
        />
    );
}

/** Already-authorised source facts; no mutation or inherited module grant. */
export function RestraintsBspSummary({
    summary,
    clientId,
    origin,
}: {
    summary: RestraintSummary;
    clientId: number;
    origin: string;
}) {
    const plan = summary.active_plan;
    const reviewState = plan ? REVIEW_STATE_META[plan.review_state] : null;

    return (
        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
            <div className="mb-3 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2.5">
                    <span className="grid h-9 w-9 place-items-center rounded-xl bg-primary/10 text-primary">
                        <ShieldAlert className="h-4.5 w-4.5" />
                    </span>
                    <div>
                        <div className="text-sm font-bold">
                            Restrictive practice &amp; behaviour support
                        </div>
                        <div className="text-xs text-muted-foreground">
                            {summary.total_events} restraint event
                            {summary.total_events === 1 ? '' : 's'} on record
                        </div>
                    </div>
                </div>
                <GuardrailButton
                    unstyled
                    asChild
                    className="frontline-hit text-subtle inline-flex items-center gap-1 font-semibold text-primary hover:underline"
                >
                    <Link
                        href={`/health-safety/restraints?client_id=${clientId}`}
                    >
                        Open register{' '}
                        <ChevronRight className="h-3.5 w-3.5" aria-hidden />
                    </Link>
                </GuardrailButton>
            </div>

            {/* Active BSP */}
            {plan ? (
                <GuardrailButton
                    unstyled
                    asChild
                    className="frontline-tap mb-3 flex w-full items-center gap-3 rounded-xl border border-border bg-card/60 p-3 text-left transition-colors hover:border-primary/40 focus-visible:ring-2 focus-visible:ring-ring"
                >
                    <Link
                        href={restraintHref('plans', plan.id, {
                            returnTo: origin,
                        })}
                    >
                        <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-primary/10 text-primary">
                            <BookOpen className="h-4 w-4" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="truncate text-sm font-semibold">
                                {plan.title}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {plan.reference} · Active plan record
                            </div>
                            <p className="text-caption text-muted-foreground">
                                {plan.authorization_state === 'unknown'
                                    ? 'Approval has not been established here.'
                                    : 'Open the plan for its current approval and publication evidence.'}
                            </p>
                        </div>
                        {reviewState && plan.review_state !== 'ok' ? (
                            <StatusBadge
                                variant={
                                    plan.review_state === 'overdue'
                                        ? 'critical'
                                        : 'warning'
                                }
                                label={reviewState.label}
                            />
                        ) : null}
                        {plan.review_state === 'unknown' ? (
                            <StatusBadge
                                status="unknown"
                                label="Review date unknown"
                            />
                        ) : null}
                    </Link>
                </GuardrailButton>
            ) : (
                <div className="mb-3 flex items-center gap-2.5 rounded-xl border border-dashed border-status-warning/40 bg-status-warning-bg/40 p-3 text-[13px] text-status-warning">
                    <AlertTriangle className="h-4 w-4 shrink-0" />
                    No active behaviour-support plan is recorded here.
                </div>
            )}

            {/* Recent events */}
            {summary.recent_events.length ? (
                <div className="flex flex-col gap-1.5">
                    <div className="text-[11px] font-bold tracking-wide text-muted-foreground uppercase">
                        Recent events
                    </div>
                    {summary.recent_events.map((e) => {
                        const type = typeMeta(e.restraint_type);
                        const sev = severityMeta(e.severity);
                        return (
                            <GuardrailButton
                                unstyled
                                asChild
                                key={e.id}
                                className="frontline-tap flex items-center gap-2.5 rounded-lg border border-border bg-card/40 px-3 py-2 text-left transition-colors hover:border-primary/40 hover:bg-card focus-visible:ring-2 focus-visible:ring-ring"
                            >
                                <Link
                                    href={restraintHref('events', e.id, {
                                        returnTo: origin,
                                    })}
                                >
                                    <type.icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                                    <div className="min-w-0 flex-1">
                                        <div className="truncate text-[13px] font-medium">
                                            {type.label} · {e.reference}
                                        </div>
                                        <div className="text-[11px] text-muted-foreground">
                                            {formatDateTimeLong(e.started_at) ||
                                                'Event time not recorded'}
                                        </div>
                                        {e.needs_review ? (
                                            <p className="text-caption text-status-warning">
                                                Current record needs review
                                            </p>
                                        ) : null}
                                    </div>
                                    <div className="flex shrink-0 items-center gap-1">
                                        {e.injury_occurred ? (
                                            <span className="text-caption text-status-critical">
                                                <HeartPulse
                                                    className="inline h-3.5 w-3.5"
                                                    aria-hidden
                                                />{' '}
                                                Injury
                                            </span>
                                        ) : null}
                                        {!e.within_support_plan ? (
                                            <span className="text-caption text-status-critical">
                                                <AlertTriangle
                                                    className="inline h-3.5 w-3.5"
                                                    aria-hidden
                                                />{' '}
                                                Outside plan
                                            </span>
                                        ) : null}
                                        <StatusBadge
                                            status={e.severity}
                                            label={sev.label}
                                        />
                                    </div>
                                </Link>
                            </GuardrailButton>
                        );
                    })}
                </div>
            ) : null}
        </div>
    );
}
