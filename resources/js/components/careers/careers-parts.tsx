// Careers inside the public website (mockup v2, page-careers.tsx, approved by
// Stephan 23 Oct 2026). Shared by the job list, the job page and the
// application status page. Every colour is a token.
import { employmentTypeLabels } from '@/lib/job-posting-constants';
import { Briefcase, CalendarClock, Check, MapPin } from 'lucide-react';

export type PublicJobSite = { id: number; name: string } | null;

/** A role as the careers list shows it. */
export type JobListing = {
    id: number;
    title: string;
    slug: string;
    position_role: string | null;
    employment_type: string;
    summary: string | null;
    site: PublicJobSite;
    published_at: string | null;
    closing_at: string | null;
};

/** A role as its own page and the apply wizard show it. */
export type PublicJob = {
    id: number;
    title: string;
    slug: string;
    position_role: string | null;
    employment_type: string;
    summary: string | null;
    description: string | null;
    requirements: string | null;
    responsibilities: string | null;
    screening_questions: string[];
    site: PublicJobSite;
    /** Only when the role shows its pay on the ad. */
    pay?: string | null;
    closing_at: string | null;
};

export const jobUrl = (slug: string) => `/careers/jobs/${slug}`;
export const applyUrl = (slug: string) => `/careers/jobs/${slug}/apply`;

/** "Support Worker · Kāpiti Lodge", or just the title without a Site. */
export function jobHeading(job: { title: string; site: PublicJobSite }) {
    return job.site ? `${job.title} · ${job.site.name}` : job.title;
}

/** The stored labels are Title Case ("Part Time"); public copy is sentence case. */
export function employmentType(type: string): string {
    const label = employmentTypeLabels[type] ?? type.replace(/_/g, ' ');
    return label.charAt(0).toUpperCase() + label.slice(1).toLowerCase();
}

/** "2026-11-14" → "14 November 2026" (a calendar date, no time zone shift). */
export function formatDate(iso: string | null | undefined): string | null {
    if (!iso) return null;
    const [year, month, day] = iso.slice(0, 10).split('-').map(Number);
    if (!year || !month || !day) return iso;
    return new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    }).format(new Date(year, month - 1, day));
}

/** "Applications close 14 November 2026", or "Open until filled". */
export function closingText(closingAt: string | null): string {
    const date = formatDate(closingAt);
    return date ? `Applications close ${date}` : 'Open until filled';
}

/** Free text typed in Recruitment, one item per line (bullets stripped). */
export function textLines(text: string | null | undefined): string[] {
    return (text ?? '')
        .split(/\r?\n/)
        .map((line) => line.replace(/^\s*(?:[-*•‣–]|\d+[.)])\s*/, '').trim())
        .filter(Boolean);
}

/** Free text typed in Recruitment, split into paragraphs at blank lines. */
export function paragraphs(text: string | null | undefined): string[] {
    return (text ?? '')
        .split(/\r?\n\s*\r?\n/)
        .map((block) => block.trim())
        .filter(Boolean);
}

/** Where, what kind of role, and when applications close. */
export function JobFacts({
    job,
}: {
    job: {
        site: PublicJobSite;
        employment_type: string;
        closing_at: string | null;
    };
}) {
    return (
        <ul className="flex flex-wrap gap-x-5 gap-y-2 text-sm">
            {job.site && (
                <li className="flex items-center gap-1.5">
                    <MapPin className="size-4 shrink-0" aria-hidden />
                    {job.site.name}
                </li>
            )}
            <li className="flex items-center gap-1.5">
                <Briefcase className="size-4 shrink-0" aria-hidden />
                {employmentType(job.employment_type)}
            </li>
            <li className="flex items-center gap-1.5">
                <CalendarClock className="size-4 shrink-0" aria-hidden />
                {closingText(job.closing_at)}
            </li>
        </ul>
    );
}

/** How applying works, from application to the first shift. */
export const hiringSteps = [
    {
        title: 'Apply online',
        body: 'About ten minutes. A CV helps but isn’t required.',
    },
    {
        title: 'A chat with us',
        body: 'If you’re shortlisted, we’ll call to arrange an interview.',
    },
    {
        title: 'Checks',
        body: 'Two references and a New Zealand Police vet, with your consent.',
    },
    {
        title: 'Welcome aboard',
        body: 'Paid induction and training, then shadow shifts with your team.',
    },
] as const;

/**
 * Progress on the Journey pathway: done = filled, now = the ring with a halo,
 * next = hollow. `current` past the last step marks every step done.
 */
export function ProgressPathway({
    steps,
    current,
}: {
    steps: readonly { title: string; body: string }[];
    current: number;
}) {
    return (
        <ol className="journey-site-progress">
            {steps.map((step, index) => {
                const state =
                    index < current
                        ? 'done'
                        : index === current
                          ? 'now'
                          : 'next';
                return (
                    <li
                        key={step.title}
                        data-state={state}
                        aria-current={state === 'now' ? 'step' : undefined}
                    >
                        <span className="journey-site-progress-dot" aria-hidden>
                            {state === 'done' && <Check className="size-3.5" />}
                        </span>
                        <span>
                            <span className="block font-semibold">
                                {step.title}{' '}
                                <span className="sr-only">
                                    {state === 'done'
                                        ? '(done)'
                                        : state === 'now'
                                          ? '(where it is now)'
                                          : '(still to come)'}
                                </span>
                            </span>
                            <span className="block text-sm text-muted-foreground">
                                {step.body}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
