import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    Check,
    CheckCircle2,
    ChevronDown,
    Clock,
    Eye,
    Hand,
    Route,
    ShieldCheck,
    Sparkles,
    X,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import type { JobPost, JobPostTaskKind } from './types';

interface JobCardProps {
    job: JobPost;
    onClaim?: (job: JobPost) => void;
    onApprove?: (job: JobPost) => void;
    onOpen?: (job: JobPost) => void;
    density?: 'comfortable' | 'compact';
}

function formatDateParts(iso: string | null): { weekday: string; dayMonth: string } {
    if (!iso) return { weekday: '—', dayMonth: '—' };
    const date = new Date(`${iso}T00:00:00`);
    if (Number.isNaN(date.getTime())) return { weekday: '—', dayMonth: '—' };
    const weekday = date.toLocaleDateString('en-NZ', { weekday: 'short' });
    const dayMonth = date.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
    return { weekday, dayMonth };
}

function hours(start: string | null, end: string | null): number {
    if (!start || !end) return 0;
    const [sh, sm] = start.split(':').map(Number);
    const [eh, em] = end.split(':').map(Number);
    let mins = eh * 60 + em - (sh * 60 + sm);
    if (mins < 0) mins += 24 * 60;
    return Math.round((mins / 60) * 10) / 10;
}

const STATUS_LABEL: Record<string, string> = {
    open: 'Open',
    claimed: 'Pending',
    filled: 'Filled',
    cancelled: 'Cancelled',
};

const STATUS_BADGE: Record<string, string> = {
    open: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    claimed: 'border-primary/25 bg-accent text-[var(--brand-deep,var(--primary))]',
    filled: 'border-status-success/30 bg-status-success-bg text-status-success',
    cancelled: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
};

const STATUS_DOT: Record<string, string> = {
    open: 'bg-status-warning',
    claimed: 'bg-primary',
    filled: 'bg-status-success',
    cancelled: 'bg-status-critical',
};

const KIND_BADGE: Record<JobPostTaskKind, string> = {
    med: 'bg-status-critical-bg text-status-critical',
    meal: 'bg-status-warning-bg text-status-warning',
    care: 'bg-accent text-[var(--brand-deep,var(--primary))]',
    access: 'bg-status-success-bg text-status-success',
};

function kindClass(kind: string): string {
    return KIND_BADGE[(kind as JobPostTaskKind)] ?? KIND_BADGE.care;
}

function EligibilityChip({
    eligibility,
}: {
    eligibility: JobPost['viewer_eligibility'];
}) {
    if (!eligibility) return null;
    if (!eligibility.is_eligible) {
        return (
            <span
                className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-[2px] text-[10.5px] font-bold uppercase tracking-wide text-status-critical"
                title={eligibility.blocked_reasons[0] ?? undefined}
            >
                <ShieldCheck className="h-2.5 w-2.5" /> Blocked
            </span>
        );
    }
    if (eligibility.warning_count > 0) {
        return (
            <span className="inline-flex items-center gap-1 rounded-full bg-status-warning-bg px-2 py-[2px] text-[10.5px] font-bold uppercase tracking-wide text-status-warning">
                <AlertTriangle className="h-2.5 w-2.5" />
                {eligibility.warning_count}{' '}
                {eligibility.warning_count === 1 ? 'warning' : 'warnings'}
            </span>
        );
    }
    return (
        <span className="inline-flex items-center gap-1 rounded-full bg-status-success-bg px-2 py-[2px] text-[10.5px] font-bold uppercase tracking-wide text-status-success">
            <Check className="h-2.5 w-2.5" strokeWidth={3} /> Eligible
        </span>
    );
}

function initialsOf(name: string): string {
    return name
        .split(' ')
        .map((part) => part[0] ?? '')
        .filter(Boolean)
        .slice(0, 2)
        .join('')
        .toUpperCase();
}

export function JobCard({
    job,
    onClaim,
    onApprove,
    onOpen,
    density = 'comfortable',
}: JobCardProps) {
    const dateParts = formatDateParts(job.date);
    const hrs = hours(job.start_time, job.end_time);
    const schedule = job.your_schedule;
    const skillsMatch = job.your_skills?.length ?? 0;
    const skillsTotal = job.required_skills.length;
    const skillsOk = skillsTotal === 0 || skillsMatch >= skillsTotal;

    const isStrongMatch =
        job.status === 'open' &&
        !!job.viewer_eligibility?.is_eligible &&
        skillsOk &&
        !!schedule?.free &&
        !schedule.conflict &&
        !schedule.fatigue &&
        !schedule.time_off;

    const [tasksOpen, setTasksOpen] = useState<boolean>(isStrongMatch);

    const claimBlockedReason = job.viewer_eligibility?.blocked_reasons[0] ?? null;
    const claimDisabled = !!job.viewer_eligibility && !job.viewer_eligibility.is_eligible;
    const canShowSensitive = job.privacy.can_view_sensitive_details;

    const handleClaim = () => {
        if (claimDisabled) return;
        if (onClaim) {
            onClaim(job);
            return;
        }
        router.post(
            `/operations/job-board/${job.id}/claim`,
            {},
            { preserveScroll: true },
        );
    };

    const handleApprove = () => {
        if (onApprove) {
            onApprove(job);
            return;
        }
        router.post(
            `/operations/job-board/${job.id}/approve`,
            {},
            { preserveScroll: true },
        );
    };

    const padding = density === 'compact' ? 'p-3.5' : 'p-[18px]';

    return (
        <article
            data-test="job-board-card"
            data-strong-match={isStrongMatch ? 'true' : 'false'}
            className={cn(
                'relative flex flex-col gap-3 rounded-xl border border-border bg-card shadow-sm transition-all',
                padding,
                'hover:-translate-y-px hover:border-border hover:shadow-md',
                isStrongMatch &&
                    'border-[color-mix(in_oklch,var(--primary)_25%,var(--border))] bg-[linear-gradient(180deg,color-mix(in_oklch,var(--primary)_4%,var(--card))_0%,var(--card)_28%)]',
                job.status === 'filled' && 'opacity-85 hover:translate-y-0',
                job.status === 'claimed' &&
                    'bg-[linear-gradient(180deg,var(--accent)_0%,var(--card)_28%)]',
            )}
        >
            {isStrongMatch ? (
                <div className="absolute left-1/2 top-0 inline-flex -translate-x-1/2 -translate-y-1/2 items-center gap-1 rounded-full bg-primary px-2.5 py-[3px] text-[10.5px] font-bold uppercase tracking-wide text-primary-foreground shadow-[0_6px_14px_-6px_color-mix(in_oklch,var(--primary)_55%,transparent)]">
                    <Sparkles className="h-2.5 w-2.5" strokeWidth={2.5} />
                    Strong match
                </div>
            ) : null}

            {job.replacement ? (
                <div className="-mx-1 -mt-1 inline-flex items-center gap-1.5 self-start rounded-lg bg-status-warning-bg px-2.5 py-1.5 text-xs font-semibold text-status-warning">
                    <AlertTriangle className="h-3 w-3" strokeWidth={2.5} />
                    Short-notice replacement
                    {job.replacement.reason ? ` · ${job.replacement.reason}` : ''}
                </div>
            ) : null}

            <div className="flex items-start gap-3">
                <div
                    className={cn(
                        'flex w-[52px] shrink-0 flex-col items-center rounded-[10px] border border-border bg-muted px-1 py-1.5 text-center',
                        isStrongMatch &&
                            'border-[color-mix(in_oklch,var(--primary)_25%,var(--border))] bg-accent',
                    )}
                >
                    <span
                        className={cn(
                            'text-[10px] font-bold uppercase tracking-[0.08em] text-muted-foreground',
                            isStrongMatch && 'text-[var(--brand-deep,var(--primary))]',
                        )}
                    >
                        {dateParts.weekday}
                    </span>
                    <span className="mt-[1px] whitespace-nowrap text-[13.5px] font-bold text-foreground">
                        {dateParts.dayMonth}
                    </span>
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <h3 className="m-0 min-w-0 flex-auto text-[14.5px] font-bold leading-[1.25] tracking-tight">
                            {job.title}
                        </h3>
                        <span
                            className={cn(
                                'inline-flex shrink-0 items-center gap-1 rounded-full border px-2 py-[2px] text-[10.5px] font-bold uppercase tracking-wide',
                                STATUS_BADGE[job.status] ??
                                    'border-border bg-muted text-muted-foreground',
                            )}
                        >
                            <span
                                className={cn(
                                    'h-1.5 w-1.5 rounded-full',
                                    STATUS_DOT[job.status] ?? 'bg-muted-foreground',
                                )}
                            />
                            {STATUS_LABEL[job.status] ?? job.status}
                        </span>
                    </div>
                    {(job.client || job.location) && (
                        <p className="mt-0.5 truncate text-xs text-muted-foreground">
                            {job.client ? job.client.display_name : ''}
                            {job.client?.is_redacted ? ' (privacy)' : ''}
                            {job.client && job.location ? ' · ' : ''}
                            {job.location ?? ''}
                        </p>
                    )}
                </div>
            </div>

            <dl className="m-0 grid grid-cols-2 gap-x-3.5 gap-y-2.5 border-y border-dashed border-border py-3">
                <Fact
                    label="When"
                    icon={<Clock className="h-3 w-3" />}
                    main={`${job.start_time ?? '—'}–${job.end_time ?? '—'}`}
                    sub={`${hrs}h${job.coverage ? ` · ${job.coverage}` : ''}`}
                />
                <Fact
                    label="Your schedule"
                    icon={<Calendar className="h-3 w-3" />}
                    tone={
                        schedule?.conflict || schedule?.fatigue || schedule?.time_off
                            ? 'warn'
                            : schedule?.free
                              ? 'ok'
                              : 'default'
                    }
                    main={
                        schedule?.conflict
                            ? 'Conflict'
                            : schedule?.fatigue
                              ? 'Fatigue flag'
                                : schedule?.time_off
                                  ? 'On leave'
                                  : schedule?.free
                                    ? 'Free'
                                    : 'Schedule unknown'
                    }
                    sub={
                        schedule?.conflict?.label ??
                        schedule?.fatigue?.label ??
                        schedule?.time_off?.label ??
                        (schedule?.free ? 'No double booking' : 'Check roster')
                    }
                />
                <Fact
                    label="Skills match"
                    icon={<ShieldCheck className="h-3 w-3" />}
                    tone={skillsOk ? 'ok' : 'warn'}
                    main={`${skillsMatch}/${skillsTotal} required`}
                    sub={
                        skillsOk
                            ? 'All certifications current'
                            : `Missing: ${job.required_skills
                                  .filter((s) => !job.your_skills.includes(s))
                                  .join(', ')}`
                    }
                />
                <Fact
                    label="History here"
                    icon={<Route className="h-3 w-3" />}
                    main={`${job.past_shifts_here} past ${
                        job.past_shifts_here === 1 ? 'shift' : 'shifts'
                    }`}
                    sub={job.site_familiar ? 'Site is familiar' : 'First visit'}
                />
            </dl>

            {(job.required_skills.length > 0 || job.coverage_roles.length > 0) && (
                <div className="flex flex-wrap gap-1">
                    {job.required_skills.map((skill) => {
                        const have = job.your_skills.includes(skill);
                        return (
                            <span
                                key={skill}
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-md border px-2 py-[2px] text-[10.5px] font-semibold',
                                    have
                                        ? 'border-[color-mix(in_oklch,var(--primary)_15%,transparent)] bg-accent text-[var(--brand-deep,var(--primary))]'
                                        : 'border-status-critical/30 bg-status-critical-bg text-status-critical',
                                )}
                            >
                                {have ? (
                                    <Check
                                        className="h-2.5 w-2.5"
                                        strokeWidth={3}
                                    />
                                ) : (
                                    <X className="h-2.5 w-2.5" strokeWidth={3} />
                                )}
                                {skill}
                            </span>
                        );
                    })}
                    {job.coverage_roles.map((role) => (
                        <span
                            key={`${job.id}-${role}`}
                            className="inline-flex items-center rounded-md border border-border bg-muted px-2 py-[2px] text-[10.5px] font-semibold capitalize text-muted-foreground"
                        >
                            {role.replace(/_/g, ' ')}
                        </span>
                    ))}
                </div>
            )}

            {job.status === 'open' && job.tasks_total > 0 && (
                <div className="overflow-hidden rounded-[10px] border border-border bg-muted">
                    {canShowSensitive && job.tasks.length > 0 ? (
                        <details
                            className="group"
                            open={tasksOpen}
                            onToggle={(event) =>
                                setTasksOpen((event.target as HTMLDetailsElement).open)
                            }
                        >
                            <summary className="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-xs font-semibold text-foreground [&::-webkit-details-marker]:hidden">
                                <span className="inline-flex items-center gap-2">
                                    <span className="inline-grid h-4 w-4 place-items-center rounded bg-status-success text-[10px] font-extrabold text-white">
                                        ✓
                                    </span>
                                    {job.tasks_total}{' '}
                                    {job.tasks_total === 1 ? 'task' : 'tasks'}{' '}
                                    planned for this shift
                                </span>
                                <ChevronDown
                                    className={cn(
                                        'h-3 w-3 text-muted-foreground transition-transform',
                                        'group-open:rotate-180',
                                    )}
                                />
                            </summary>
                            <ul className="m-0 flex flex-col gap-1.5 border-t border-border px-3 py-2 text-xs">
                                {job.tasks.map((task, idx) => (
                                    <li
                                        key={idx}
                                        className="grid grid-cols-[64px_1fr] items-baseline gap-2.5"
                                    >
                                        <span
                                            className={cn(
                                                'self-center rounded px-1.5 py-[2px] text-center text-[9.5px] font-bold uppercase tracking-wide',
                                                kindClass(task.kind),
                                            )}
                                        >
                                            {task.kind}
                                        </span>
                                        <span className="text-foreground">
                                            {task.label}
                                        </span>
                                    </li>
                                ))}
                            </ul>
                        </details>
                    ) : (
                        <div className="px-3 py-2 text-xs text-muted-foreground">
                            {job.tasks_total}{' '}
                            {job.tasks_total === 1 ? 'task' : 'tasks'} planned ·
                            details visible after claim approval
                        </div>
                    )}
                </div>
            )}

            {job.viewer_eligibility?.blocked_reasons?.[0] ? (
                <div
                    data-test="viewer-eligibility-reason"
                    className="inline-flex items-center gap-1.5 rounded-lg bg-status-critical-bg px-2.5 py-1.5 text-[11.5px] font-semibold text-status-critical"
                >
                    <ShieldCheck className="h-3 w-3" />
                    {job.viewer_eligibility.blocked_reasons[0]}
                </div>
            ) : null}

            {job.viewer_eligibility?.first_warning ? (
                <div className="inline-flex items-center gap-1.5 rounded-lg bg-status-warning-bg px-2.5 py-1.5 text-[11.5px] font-semibold text-status-warning">
                    <AlertTriangle className="h-3 w-3" />
                    {job.viewer_eligibility.first_warning}
                </div>
            ) : null}

            {job.claimed_by && (
                <div className="flex items-center gap-2.5 rounded-lg border border-border bg-muted px-2.5 py-2">
                    <div className="grid h-7 w-7 shrink-0 place-items-center rounded-full bg-gradient-to-br from-amber-400 to-pink-500 text-[10.5px] font-bold text-white">
                        {initialsOf(job.claimed_by.name)}
                    </div>
                    <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span className="truncate text-xs font-semibold text-foreground">
                            {job.status === 'filled' ? 'Filled by' : 'Claimed by'}{' '}
                            {job.claimed_by.name}
                        </span>
                        {job.eligibility ? (
                            <EligibilityChip eligibility={job.eligibility} />
                        ) : null}
                    </div>
                </div>
            )}

            <footer className="mt-auto flex items-center justify-between gap-2">
                <div className="min-w-0">
                    <EligibilityChip eligibility={job.viewer_eligibility} />
                </div>
                <div className="flex gap-1.5">
                    {onOpen ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="h-8 px-2 text-xs"
                            onClick={() => onOpen?.(job)}
                        >
                            <Eye className="mr-1 h-3 w-3" /> Details
                        </Button>
                    ) : null}
                    {job.status === 'open' && (
                        <Button
                            type="button"
                            size="sm"
                            data-test="job-board-claim-button"
                            className="h-8 px-3 text-xs"
                            disabled={claimDisabled}
                            title={claimBlockedReason ?? 'Claim this shift'}
                            onClick={handleClaim}
                        >
                            <Hand className="mr-1 h-3 w-3" strokeWidth={2.5} />
                            {claimDisabled ? 'Blocked' : 'Claim shift'}
                        </Button>
                    )}
                    {job.status === 'claimed' && (
                        <Button
                            type="button"
                            size="sm"
                            className="h-8 px-3 text-xs"
                            onClick={handleApprove}
                        >
                            <CheckCircle2
                                className="mr-1 h-3 w-3"
                                strokeWidth={2.5}
                            />
                            Approve
                        </Button>
                    )}
                    {job.status === 'filled' && (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="h-8 px-3 text-xs"
                            disabled
                        >
                            <Check className="mr-1 h-3 w-3" strokeWidth={2.5} />
                            Filled
                        </Button>
                    )}
                </div>
            </footer>
        </article>
    );
}

function Fact({
    label,
    icon,
    main,
    sub,
    tone = 'default',
}: {
    label: string;
    icon: ReactNode;
    main: ReactNode;
    sub: ReactNode;
    tone?: 'default' | 'ok' | 'warn';
}) {
    const dtTone =
        tone === 'ok'
            ? 'text-status-success'
            : tone === 'warn'
              ? 'text-status-warning'
              : 'text-muted-foreground';
    const mainTone = tone === 'warn' ? 'text-status-warning' : 'text-foreground';
    return (
        <div className="min-w-0">
            <dt
                className={cn(
                    'inline-flex items-center gap-1 text-[10.5px] font-semibold uppercase tracking-wide',
                    dtTone,
                )}
            >
                {icon}
                {label}
            </dt>
            <dd className="m-0 mt-0.5 flex flex-col text-[13px] font-semibold">
                <span
                    className={cn('text-[13px] font-semibold leading-snug', mainTone)}
                >
                    {main}
                </span>
                <span className="mt-0.5 text-[11px] font-medium text-muted-foreground/80">
                    {sub}
                </span>
            </dd>
        </div>
    );
}

export default JobCard;
