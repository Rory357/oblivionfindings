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

import { PersonDisc } from '@/components/lists';
import { Button } from '@/components/ui/button';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { cn } from '@/lib/utils';

import type { JobPost, JobPostTaskKind } from './types';

interface JobCardProps {
    job: JobPost;
    /** Passed only when the viewer may claim. No handler = no Claim button. */
    onClaim?: (job: JobPost) => void;
    /** Passed only when the viewer may approve. No handler = no Approve button. */
    onApprove?: (job: JobPost) => void;
    onOpen?: (job: JobPost) => void;
    density?: 'comfortable' | 'compact';
}

function formatDateParts(iso: string | null): {
    weekday: string;
    dayMonth: string;
} {
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

const STATUS: Record<string, { label: string; variant: StatusVariant }> = {
    open: { label: 'Open', variant: 'warning' },
    claimed: { label: 'Awaiting approval', variant: 'info' },
    filled: { label: 'Filled', variant: 'success' },
    cancelled: { label: 'Cancelled', variant: 'neutral' },
};

const KIND_BADGE: Record<JobPostTaskKind, string> = {
    med: 'bg-status-critical-bg text-status-critical',
    meal: 'bg-status-warning-bg text-status-warning',
    care: 'bg-primary-fill/10 text-primary',
    access: 'bg-status-success-bg text-status-success',
};

function kindClass(kind: string): string {
    return KIND_BADGE[kind as JobPostTaskKind] ?? KIND_BADGE.care;
}

function EligibilityChip({
    eligibility,
}: {
    eligibility: JobPost['viewer_eligibility'];
}) {
    if (!eligibility) return null;
    if (!eligibility.is_eligible) {
        return (
            <StatusBadge
                variant="critical"
                size="sm"
                title={eligibility.blocked_reasons[0] ?? undefined}
            >
                <ShieldCheck className="size-3" /> Not eligible
            </StatusBadge>
        );
    }
    if (eligibility.warning_count > 0) {
        return (
            <StatusBadge variant="warning" size="sm">
                <AlertTriangle className="size-3" />
                {eligibility.warning_count}{' '}
                {eligibility.warning_count === 1 ? 'warning' : 'warnings'}
            </StatusBadge>
        );
    }
    return (
        <StatusBadge variant="success" size="sm">
            <Check className="size-3" strokeWidth={3} /> Eligible
        </StatusBadge>
    );
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
    const status = STATUS[job.status] ?? {
        label: job.status,
        variant: 'neutral' as const,
    };

    const isStrongMatch =
        job.status === 'open' &&
        !!job.viewer_eligibility?.is_eligible &&
        skillsOk &&
        !!schedule?.free &&
        !schedule.conflict &&
        !schedule.fatigue &&
        !schedule.time_off;

    const [tasksOpen, setTasksOpen] = useState<boolean>(isStrongMatch);

    const claimBlockedReason =
        job.viewer_eligibility?.blocked_reasons[0] ?? null;
    const claimDisabled =
        !!job.viewer_eligibility && !job.viewer_eligibility.is_eligible;
    const canShowSensitive = job.privacy.can_view_sensitive_details;
    const showClaim = job.status === 'open' && !!onClaim;
    const showApprove = job.status === 'claimed' && !!onApprove;

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
                    'bg-[linear-gradient(180deg,color-mix(in_oklch,var(--primary)_6%,var(--card))_0%,var(--card)_28%)]',
            )}
        >
            {isStrongMatch ? (
                <div className="absolute top-0 left-1/2 inline-flex -translate-x-1/2 -translate-y-1/2 items-center gap-1 rounded-full bg-primary-fill px-2.5 py-[3px] text-[10.5px] font-bold tracking-wide text-primary-fill-foreground uppercase shadow-[0_6px_14px_-6px_color-mix(in_oklch,var(--primary)_55%,transparent)]">
                    <Sparkles className="h-2.5 w-2.5" strokeWidth={2.5} />
                    Strong match
                </div>
            ) : null}

            {/* Says only that an existing assignment needs cover. Why the
                rostered person is away is private HR information and is
                never shown here (the server doesn't send it either). */}
            {job.replacement ? (
                <div
                    data-test="job-board-replacement-cover"
                    className="-mx-1 -mt-1 inline-flex items-center gap-1.5 self-start rounded-lg bg-status-warning-bg px-2.5 py-1.5 text-xs font-semibold text-status-warning"
                >
                    <AlertTriangle className="h-3 w-3" strokeWidth={2.5} />
                    Replacement cover
                </div>
            ) : null}

            <div className="flex items-start gap-3">
                <div
                    className={cn(
                        'flex w-[52px] shrink-0 flex-col items-center rounded-[10px] border border-border bg-muted px-1 py-1.5 text-center',
                        isStrongMatch &&
                            'border-[color-mix(in_oklch,var(--primary)_25%,var(--border))] bg-primary-fill/10',
                    )}
                >
                    <span
                        className={cn(
                            'text-[10px] font-bold tracking-[0.08em] text-muted-foreground uppercase',
                            isStrongMatch && 'text-primary',
                        )}
                    >
                        {dateParts.weekday}
                    </span>
                    <span className="mt-[1px] text-[13.5px] font-bold whitespace-nowrap text-foreground">
                        {dateParts.dayMonth}
                    </span>
                </div>

                <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                        <h3 className="m-0 min-w-0 flex-auto text-[14.5px] leading-[1.25] font-bold tracking-tight">
                            {job.title}
                        </h3>
                        <StatusBadge
                            variant={status.variant}
                            size="sm"
                            className="shrink-0"
                            data-test="job-board-card-status"
                        >
                            {status.label}
                        </StatusBadge>
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
                        schedule?.conflict ||
                        schedule?.fatigue ||
                        schedule?.time_off
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
                            ? skillsTotal === 0
                                ? 'No skills required'
                                : 'You have every required skill'
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

            {(job.required_skills.length > 0 ||
                job.coverage_roles.length > 0) && (
                <div className="flex flex-wrap gap-1">
                    {job.required_skills.map((skill) => {
                        const have = job.your_skills.includes(skill);
                        return (
                            <span
                                key={skill}
                                className={cn(
                                    'inline-flex items-center gap-1 rounded-md border px-2 py-[2px] text-[10.5px] font-semibold',
                                    have
                                        ? 'border-primary/15 bg-primary-fill/10 text-primary'
                                        : 'border-status-critical/30 bg-status-critical-bg text-status-critical',
                                )}
                            >
                                {have ? (
                                    <Check
                                        className="h-2.5 w-2.5"
                                        strokeWidth={3}
                                        aria-label="You have this skill"
                                    />
                                ) : (
                                    <X
                                        className="h-2.5 w-2.5"
                                        strokeWidth={3}
                                        aria-label="You don't have this skill yet"
                                    />
                                )}
                                {skill}
                            </span>
                        );
                    })}
                    {job.coverage_roles.map((role) => (
                        <span
                            key={`${job.id}-${role}`}
                            className="inline-flex items-center rounded-md border border-border bg-muted px-2 py-[2px] text-[10.5px] font-semibold text-muted-foreground capitalize"
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
                                setTasksOpen(
                                    (event.target as HTMLDetailsElement).open,
                                )
                            }
                        >
                            <summary className="flex cursor-pointer list-none items-center justify-between px-3 py-2 text-xs font-semibold text-foreground [&::-webkit-details-marker]:hidden">
                                <span className="inline-flex items-center gap-2">
                                    <CheckCircle2
                                        className="size-4 text-status-success"
                                        aria-hidden="true"
                                    />
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
                                                'self-center rounded px-1.5 py-[2px] text-center text-[9.5px] font-bold tracking-wide uppercase',
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
                            details show once a claim is approved
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
                    <PersonDisc name={job.claimed_by.name} size={28} />
                    <div className="flex min-w-0 flex-1 flex-col items-start gap-0.5">
                        <span className="truncate text-xs font-semibold text-foreground">
                            {job.status === 'filled'
                                ? 'Filled by'
                                : 'Claimed by'}{' '}
                            {job.claimed_by.name}
                        </span>
                        {job.eligibility ? (
                            <EligibilityChip eligibility={job.eligibility} />
                        ) : null}
                    </div>
                </div>
            )}

            <footer className="mt-auto flex items-center justify-between gap-2">
                <div className="min-w-0" data-test="viewer-eligibility">
                    <EligibilityChip eligibility={job.viewer_eligibility} />
                </div>
                <div className="flex gap-3">
                    {onOpen ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            className="frontline-hit text-xs"
                            onClick={() => onOpen(job)}
                        >
                            <Eye className="size-3" /> Details
                        </Button>
                    ) : null}
                    {showClaim && (
                        <Button
                            type="button"
                            size="sm"
                            data-test="job-board-claim-button"
                            className="frontline-hit text-xs"
                            disabled={claimDisabled}
                            title={claimBlockedReason ?? undefined}
                            onClick={() => {
                                if (!claimDisabled) onClaim?.(job);
                            }}
                        >
                            <Hand className="size-3" strokeWidth={2.5} />
                            {claimDisabled ? 'Not eligible' : 'Claim shift'}
                        </Button>
                    )}
                    {showApprove && (
                        <Button
                            type="button"
                            size="sm"
                            data-test="job-board-approve-button"
                            className="frontline-hit text-xs"
                            onClick={() => onApprove?.(job)}
                        >
                            <CheckCircle2
                                className="size-3"
                                strokeWidth={2.5}
                            />
                            Approve
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
    const mainTone =
        tone === 'warn' ? 'text-status-warning' : 'text-foreground';
    return (
        <div className="min-w-0">
            <dt
                className={cn(
                    'inline-flex items-center gap-1 text-[10.5px] font-semibold tracking-wide uppercase',
                    dtTone,
                )}
            >
                {icon}
                {label}
            </dt>
            <dd className="m-0 mt-0.5 flex flex-col text-[13px] font-semibold">
                <span
                    className={cn(
                        'text-[13px] leading-snug font-semibold',
                        mainTone,
                    )}
                >
                    {main}
                </span>
                <span className="mt-0.5 text-[11px] font-medium text-muted-foreground">
                    {sub}
                </span>
            </dd>
        </div>
    );
}

export default JobCard;
