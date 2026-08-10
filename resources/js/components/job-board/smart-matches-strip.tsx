import { Check, Hand, Sparkles } from 'lucide-react';

import { Button as GuardrailButton } from '@/components/ui/button';
import type { JobPost } from './types';

interface SmartMatchesStripProps {
    jobs: JobPost[];
    totalMatches: number;
    onQuickClaim: (job: JobPost) => void;
    onSeeAll?: () => void;
}

function formatChipDate(iso: string | null): string {
    if (!iso) return '—';
    const d = new Date(`${iso}T00:00:00`);
    if (Number.isNaN(d.getTime())) return '—';
    return d.toLocaleDateString('en-NZ', { weekday: 'short' });
}

export function SmartMatchesStrip({
    jobs,
    totalMatches,
    onQuickClaim,
    onSeeAll,
}: SmartMatchesStripProps) {
    if (jobs.length === 0) return null;

    return (
        <section className="rounded-xl border border-border bg-card p-4 shadow-sm">
            <header className="mb-3 flex items-center justify-between">
                <div className="inline-flex items-center gap-2 text-sm font-bold text-foreground">
                    <Sparkles
                        className="h-4 w-4 text-primary"
                        strokeWidth={2.5}
                    />
                    Smart matches
                    <span className="ml-1 text-xs font-medium text-muted-foreground">
                        Eligible · skills match · no schedule conflict
                    </span>
                </div>
                {onSeeAll && totalMatches > jobs.length ? (
                    <GuardrailButton
                        unstyled
                        type="button"
                        className="text-xs font-semibold text-primary hover:underline"
                        onClick={onSeeAll}
                    >
                        See all {totalMatches} matches →
                    </GuardrailButton>
                ) : null}
            </header>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                {jobs.map((job) => {
                    const total = job.required_skills.length;
                    const location = job.location?.split(' · ')[0] ?? '';
                    return (
                        <GuardrailButton
                            unstyled
                            key={job.id}
                            type="button"
                            data-test="job-board-quick-claim"
                            onClick={() => onQuickClaim(job)}
                            className="flex flex-col items-start gap-1 rounded-xl border border-[color-mix(in_oklch,var(--primary)_15%,transparent)] bg-gradient-to-b from-accent to-card p-3 text-left transition-all hover:-translate-y-px hover:border-[color-mix(in_oklch,var(--primary)_30%,transparent)] hover:shadow-md"
                        >
                            <div className="text-[11px] font-bold tracking-wider text-[var(--brand-deep,var(--primary))] uppercase">
                                {formatChipDate(job.date)} · {job.start_time}–
                                {job.end_time}
                            </div>
                            <div className="text-sm font-bold text-foreground">
                                {job.title}
                            </div>
                            <div className="text-xs text-muted-foreground">
                                {location}
                                {job.coverage ? ` · ${job.coverage}` : ''}
                            </div>
                            <ul className="m-0 mt-1.5 flex list-none flex-col gap-0.5 p-0">
                                <li className="inline-flex items-center gap-1.5 text-[11px] font-medium text-status-success">
                                    <Check
                                        className="h-2.5 w-2.5"
                                        strokeWidth={3}
                                    />
                                    Eligible
                                </li>
                                <li className="inline-flex items-center gap-1.5 text-[11px] font-medium text-status-success">
                                    <Check
                                        className="h-2.5 w-2.5"
                                        strokeWidth={3}
                                    />
                                    {total}/{total} skills
                                </li>
                                <li className="inline-flex items-center gap-1.5 text-[11px] font-medium text-status-success">
                                    <Check
                                        className="h-2.5 w-2.5"
                                        strokeWidth={3}
                                    />
                                    No conflict
                                </li>
                            </ul>
                            <span className="mt-1 inline-flex items-center gap-1 self-start rounded-full bg-primary px-2 py-0.5 text-[11px] font-bold text-primary-foreground">
                                <Hand
                                    className="h-2.5 w-2.5"
                                    strokeWidth={2.5}
                                />
                                Quick claim
                            </span>
                        </GuardrailButton>
                    );
                })}
            </div>
        </section>
    );
}

export default SmartMatchesStrip;
