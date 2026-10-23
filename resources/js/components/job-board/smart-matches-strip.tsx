import { Check, Hand, Sparkles } from 'lucide-react';

import { Button as GuardrailButton } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import type { JobPost } from './types';

interface SmartMatchesStripProps {
    jobs: JobPost[];
    totalMatches: number;
    /** Passed only when the viewer may claim; without it the matches are
     * shown for information and nothing on them can be selected. */
    onQuickClaim?: (job: JobPost) => void;
    onSeeAll?: () => void;
}

function formatChipDate(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(`${iso}T00:00:00`);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-NZ', { weekday: 'short' });
}

const TILE =
    'flex flex-col items-start gap-1 rounded-xl border border-primary/20 bg-[linear-gradient(180deg,color-mix(in_oklch,var(--primary)_6%,var(--card))_0%,var(--card)_100%)] p-3 text-left';

function MatchBody({ job, claimable }: { job: JobPost; claimable: boolean }) {
    const total = job.required_skills.length;
    const location = job.location?.split(' · ')[0] ?? '';
    return (
        <>
            <div className="text-[11px] font-bold tracking-wider text-primary uppercase">
                {formatChipDate(job.date)} · {job.start_time}–{job.end_time}
            </div>
            <div className="text-sm font-bold text-foreground">{job.title}</div>
            <div className="text-xs text-muted-foreground">
                {location}
                {job.coverage ? ` · ${job.coverage}` : ''}
            </div>
            <ul className="m-0 mt-1.5 flex list-none flex-col gap-0.5 p-0">
                {['Eligible', `${total}/${total} skills`, 'No conflict'].map(
                    (fact) => (
                        <li
                            key={fact}
                            className="inline-flex items-center gap-1.5 text-[11px] font-medium text-status-success"
                        >
                            <Check className="h-2.5 w-2.5" strokeWidth={3} />
                            {fact}
                        </li>
                    ),
                )}
            </ul>
            {claimable ? (
                <span className="mt-1 inline-flex items-center gap-1 self-start rounded-full bg-primary-fill px-2 py-0.5 text-[11px] font-bold text-primary-fill-foreground">
                    <Hand className="h-2.5 w-2.5" strokeWidth={2.5} />
                    Quick claim
                </span>
            ) : null}
        </>
    );
}

export function SmartMatchesStrip({
    jobs,
    totalMatches,
    onQuickClaim,
    onSeeAll,
}: SmartMatchesStripProps) {
    if (jobs.length === 0) return null;

    return (
        <section
            aria-labelledby="job-board-smart-matches"
            className="rounded-xl border border-border bg-card p-4 shadow-sm"
        >
            <header className="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div className="inline-flex flex-wrap items-center gap-2">
                    <Sparkles
                        className="size-4 text-primary"
                        strokeWidth={2.5}
                        aria-hidden="true"
                    />
                    <h2
                        id="job-board-smart-matches"
                        className="text-sm font-bold text-foreground"
                    >
                        Smart matches
                    </h2>
                    <span className="text-caption">
                        Eligible · skills match · no schedule conflict
                    </span>
                </div>
                {onSeeAll && totalMatches > jobs.length ? (
                    <GuardrailButton
                        unstyled
                        type="button"
                        className="frontline-hit rounded text-xs font-semibold text-primary outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                        onClick={onSeeAll}
                    >
                        See all {totalMatches} matches →
                    </GuardrailButton>
                ) : null}
            </header>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {jobs.map((job) =>
                    onQuickClaim ? (
                        <GuardrailButton
                            unstyled
                            key={job.id}
                            type="button"
                            data-test="job-board-quick-claim"
                            onClick={() => onQuickClaim(job)}
                            className={cn(
                                TILE,
                                'transition-all outline-none hover:-translate-y-px hover:border-primary/40 hover:shadow-md focus-visible:ring-2 focus-visible:ring-ring',
                            )}
                        >
                            <MatchBody job={job} claimable />
                        </GuardrailButton>
                    ) : (
                        <div
                            key={job.id}
                            data-test="job-board-smart-match"
                            className={TILE}
                        >
                            <MatchBody job={job} claimable={false} />
                        </div>
                    ),
                )}
            </div>
        </section>
    );
}

export default SmartMatchesStrip;
