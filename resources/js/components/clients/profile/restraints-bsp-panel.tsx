/* Read-only "Restrictive practice & behaviour support" panel for the client
 * profile (Behaviour / ABC tab). Self-fetches a summary from the restraint
 * register and deep-links every row into it — no mutation here. Hidden entirely
 * when the viewer lacks restraints.view (403) or the client has no restraint data. */
import { CHIP, REVIEW_STATE_META, severityMeta, titleCase, typeMeta, whenLabel, type ChipTone } from '@/pages/health-safety/restraints/shared';
import { router } from '@inertiajs/react';
import { AlertTriangle, BookOpen, ChevronRight, HeartPulse, ShieldAlert } from 'lucide-react';
import { useEffect, useState } from 'react';

type ActivePlan = { id: number; reference: string; title: string; status: string; review_date: string | null; review_state: 'ok' | 'due' | 'overdue' };
type RecentEvent = { id: number; reference: string; restraint_type: string; severity: string; started_at: string | null; within_support_plan: boolean; injury_occurred: boolean; reviewed_at: string | null };
type Summary = { active_plan: ActivePlan | null; recent_events: RecentEvent[]; total_events: number };

export function RestraintsBspPanel({ clientId }: { clientId: number }) {
    const [summary, setSummary] = useState<Summary | null>(null);
    const [loaded, setLoaded] = useState(false);

    useEffect(() => {
        let live = true;
        (async () => {
            try {
                const res = await fetch(`/health-safety/restraints/clients/${clientId}/summary`, { headers: { Accept: 'application/json' } });
                if (!res.ok) throw new Error('forbidden');
                const json: Summary = await res.json();
                if (live) setSummary(json);
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
    if (!loaded || !summary || (!summary.active_plan && summary.recent_events.length === 0)) return null;

    const plan = summary.active_plan;
    const reviewState = plan ? REVIEW_STATE_META[plan.review_state] ?? REVIEW_STATE_META.ok : null;

    return (
        <div className="rounded-2xl border border-border bg-card p-5 shadow-sm">
            <div className="mb-3 flex items-center justify-between gap-3">
                <div className="flex items-center gap-2.5">
                    <span className="grid h-9 w-9 place-items-center rounded-xl bg-primary/10 text-primary">
                        <ShieldAlert className="h-4.5 w-4.5" />
                    </span>
                    <div>
                        <div className="text-sm font-bold">Restrictive practice &amp; behaviour support</div>
                        <div className="text-xs text-muted-foreground">{summary.total_events} restraint event{summary.total_events === 1 ? '' : 's'} on record</div>
                    </div>
                </div>
                <button
                    type="button"
                    onClick={() => router.visit(`/health-safety/restraints?client_id=${clientId}`)}
                    className="inline-flex items-center gap-1 text-[13px] font-semibold text-primary hover:underline"
                >
                    Open register <ChevronRight className="h-3.5 w-3.5" />
                </button>
            </div>

            {/* Active BSP */}
            {plan ? (
                <button
                    type="button"
                    onClick={() => router.visit(`/health-safety/restraints?lens=plans&plan=${plan.id}`)}
                    className="mb-3 flex w-full items-center gap-3 rounded-xl border border-border bg-card/60 p-3 text-left transition-colors hover:border-primary/40"
                >
                    <span className="grid h-8 w-8 shrink-0 place-items-center rounded-lg bg-status-success-bg text-status-success">
                        <BookOpen className="h-4 w-4" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="truncate text-sm font-semibold">{plan.title}</div>
                        <div className="text-xs text-muted-foreground">
                            {plan.reference} · Active behaviour support plan
                        </div>
                    </div>
                    {reviewState && plan.review_state !== 'ok' ? (
                        <span className={`inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${CHIP[reviewState.tone]}`}>{reviewState.label}</span>
                    ) : null}
                </button>
            ) : (
                <div className="mb-3 flex items-center gap-2.5 rounded-xl border border-dashed border-status-warning/40 bg-status-warning-bg/40 p-3 text-[13px] text-status-warning">
                    <AlertTriangle className="h-4 w-4 shrink-0" />
                    No active behaviour support plan — restrictive practice should be governed by a current plan.
                </div>
            )}

            {/* Recent events */}
            {summary.recent_events.length ? (
                <div className="flex flex-col gap-1.5">
                    <div className="text-[11px] font-bold uppercase tracking-wide text-muted-foreground">Recent events</div>
                    {summary.recent_events.map((e) => {
                        const type = typeMeta(e.restraint_type);
                        const sev = severityMeta(e.severity);
                        return (
                            <button
                                key={e.id}
                                type="button"
                                onClick={() => router.visit(`/health-safety/restraints?event=${e.id}`)}
                                className="flex items-center gap-2.5 rounded-lg border border-border bg-card/40 px-3 py-2 text-left transition-colors hover:border-primary/40 hover:bg-card"
                            >
                                <type.icon className="h-4 w-4 shrink-0 text-muted-foreground" />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-[13px] font-medium">
                                        {type.label} · {e.reference}
                                    </div>
                                    <div className="text-[11px] text-muted-foreground">{whenLabel(e.started_at)}</div>
                                </div>
                                <div className="flex shrink-0 items-center gap-1">
                                    {e.injury_occurred ? <HeartPulse className="h-3.5 w-3.5 text-status-critical" /> : null}
                                    {!e.within_support_plan ? <AlertTriangle className="h-3.5 w-3.5 text-status-critical" /> : null}
                                    <span className={`inline-flex items-center rounded-full px-2 py-0.5 text-[11px] font-medium ${CHIP[sev.tone as ChipTone]}`}>{sev.label}</span>
                                </div>
                            </button>
                        );
                    })}
                </div>
            ) : null}
        </div>
    );
}
