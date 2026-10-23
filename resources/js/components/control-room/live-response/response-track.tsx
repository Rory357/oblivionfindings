import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { alertTargets, type AlertTarget } from '@/components/control-room/alert-record/alert-targets';
import { longDuration } from '@/components/control-room/live-response/response-clock';
import { formatDateTime, formatTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Check } from 'lucide-react';

type Step = {
    key: string;
    label: string;
    state: 'done' | 'missed' | 'due' | 'later';
    note: string;
    title?: string;
};

function sameDay(a: Date, b: Date) {
    return a.toDateString() === b.toDateString();
}

/** "9:32 am" today, else "Sun 9 Aug, 9:32 am". */
function when(iso: string, now: number): string {
    return sameDay(new Date(iso), new Date(now)) ? formatTime(iso) : formatDateTime(iso);
}

function stepFor(target: AlertTarget, label: string, now: number): Step {
    if (target.doneAt)
        return {
            key: target.key,
            label,
            state: 'done',
            note: when(target.doneAt, now),
            title: formatDateTime(target.doneAt),
        };
    if (target.state === 'missed' && target.deadline)
        return {
            key: target.key,
            label,
            state: 'missed',
            note: `Target missed by ${longDuration(now - new Date(target.deadline).getTime())}`,
            title: `Was due ${formatDateTime(target.deadline)}`,
        };
    if (target.state === 'due' && target.deadline)
        return {
            key: target.key,
            label,
            state: 'due',
            note: `Due in ${longDuration(new Date(target.deadline).getTime() - now)}`,
            title: `Due ${formatDateTime(target.deadline)}`,
        };
    return { key: target.key, label, state: 'later', note: 'No target set' };
}

/**
 * Where the alert is at, as a short track: Raised → Acknowledged →
 * Responding → Resolved, each with when it happened or how long is left
 * on its target. Replaces three separate "No target" blocks.
 */
export function ResponseTrack({
    detail,
    now,
    className,
}: {
    detail: AlertWorkspaceDetail;
    now: number;
    className?: string;
}) {
    const [ack, respond, resolve] = alertTargets(detail, now);
    const raisedAt = detail.alert.triggered_at ?? detail.alert.created_at;
    const steps: Step[] = [
        {
            key: 'raised',
            label: 'Raised',
            state: 'done',
            note: raisedAt ? when(raisedAt, now) : 'Time not recorded',
            title: raisedAt ? formatDateTime(raisedAt) : undefined,
        },
        stepFor(ack, 'Acknowledged', now),
        stepFor(respond, 'Responding', now),
        stepFor(resolve, 'Resolved', now),
    ];
    const current = steps.findIndex((step) => step.state !== 'done');

    return (
        <ol className={cn('grid gap-0', className)} aria-label="Where this alert is at">
            {steps.map((step, index) => {
                const isCurrent = index === current;
                return (
                    <li key={step.key} className="relative flex gap-3 pb-3 last:pb-0">
                        {index < steps.length - 1 ? (
                            <span
                                aria-hidden="true"
                                className={cn(
                                    'absolute top-5 bottom-0 left-[9px] w-0.5',
                                    step.state === 'done' ? 'bg-primary' : 'bg-border',
                                )}
                            />
                        ) : null}
                        <span
                            aria-hidden="true"
                            className={cn(
                                'relative z-[1] mt-0.5 grid size-5 shrink-0 place-items-center rounded-full border-2',
                                step.state === 'done' &&
                                    'border-primary-fill bg-primary-fill text-primary-fill-foreground',
                                step.state === 'missed' &&
                                    'border-status-critical bg-status-critical-bg',
                                step.state === 'due' &&
                                    (isCurrent
                                        ? 'border-primary bg-card ring-4 ring-primary/15'
                                        : 'border-border bg-card'),
                                step.state === 'later' && 'border-border bg-card',
                            )}
                        >
                            {step.state === 'done' ? <Check className="size-3" /> : null}
                        </span>
                        <span className="min-w-0 flex-1" title={step.title}>
                            <span
                                className={cn(
                                    'block text-sm leading-tight',
                                    isCurrent || step.state === 'done'
                                        ? 'font-semibold text-foreground'
                                        : 'text-muted-foreground',
                                )}
                            >
                                {step.label}
                                {isCurrent ? (
                                    <span className="sr-only"> (next step)</span>
                                ) : null}
                            </span>
                            <span
                                className={cn(
                                    'text-caption mt-0.5 block',
                                    step.state === 'missed' && 'font-semibold text-status-critical',
                                )}
                            >
                                {step.note}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}
