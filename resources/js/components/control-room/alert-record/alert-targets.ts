import type { AlertWorkspaceDetail } from '@/components/control-room/alert-record/alert-sections';
import { formatDateTime, formatTime } from '@/lib/datetime';

export type AlertTargetKey = 'acknowledge' | 'respond' | 'resolve';

export type AlertTarget = {
    key: AlertTargetKey;
    label: string;
    state: 'met' | 'missed' | 'due' | 'none';
    deadline: string | null;
    doneAt: string | null;
};

/**
 * The alert's three response targets, read the same way as the timeline:
 * met when the step happened, missed when the deadline passed first (or the
 * server already marked it), otherwise due.
 */
export function alertTargets(
    d: AlertWorkspaceDetail,
    now: number = Date.now(),
): AlertTarget[] {
    const a = d.alert;
    const sla = d.sla;
    const respondedAt =
        a.status === 'triaging' || a.resolved_at
            ? (a.resolved_at ?? a.updated_at)
            : null;
    const build = (
        key: AlertTargetKey,
        label: string,
        deadline: string | null,
        breached: boolean,
        doneAt: string | null,
    ): AlertTarget => {
        if (!sla) return { key, label, state: 'none', deadline, doneAt };
        if (doneAt) return { key, label, state: 'met', deadline, doneAt };
        if (breached || (deadline && new Date(deadline).getTime() <= now))
            return { key, label, state: 'missed', deadline, doneAt };
        if (deadline) return { key, label, state: 'due', deadline, doneAt };
        return { key, label, state: 'none', deadline, doneAt };
    };
    return [
        build(
            'acknowledge',
            'Acknowledge',
            sla?.acknowledge_deadline ?? null,
            Boolean(sla?.acknowledge_breached),
            a.acknowledged_at,
        ),
        build(
            'respond',
            'Respond',
            sla?.response_deadline ?? null,
            Boolean(sla?.response_breached),
            respondedAt,
        ),
        build(
            'resolve',
            'Resolve',
            sla?.resolution_deadline ?? null,
            Boolean(sla?.resolution_breached),
            a.resolved_at,
        ),
    ];
}

/** Big word for a target, e.g. "Missed", "Met", "Due 9:39 am", "No target". */
export function targetHeadline(target: AlertTarget): string {
    switch (target.state) {
        case 'met':
            return 'Met';
        case 'missed':
            return 'Missed';
        case 'due':
            return target.deadline ? `Due ${formatTime(target.deadline)}` : 'Due';
        default:
            return 'No target';
    }
}

/** The line under it, e.g. "was due Tue 20 Oct 2026, 9:29 am". */
export function targetCaption(target: AlertTarget): string {
    if (target.state === 'met' && target.doneAt)
        return `done ${formatDateTime(target.doneAt)}`;
    if (target.state === 'missed' && target.deadline)
        return `was due ${formatDateTime(target.deadline)}`;
    if (target.deadline) return `due ${formatDateTime(target.deadline)}`;
    return 'none set for this alert';
}

/** The first target still open, for "what's next" summaries. */
export function nextOpenTarget(targets: AlertTarget[]): AlertTarget | null {
    return (
        targets.find((t) => t.state === 'missed') ??
        targets.find((t) => t.state === 'due') ??
        null
    );
}
