import { Link } from '@inertiajs/react';
import { AlertOctagon, CheckCircle2, Circle, Clock } from 'lucide-react';

import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Progress } from '@/components/ui/progress';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { resolveActionVerb } from '@/lib/governance-action-verbs';
import { humaniseGovernanceValue } from '@/lib/governance-labels';
import { cn } from '@/lib/utils';

export interface MeetingChecklistItem {
    key: string;
    label: string;
    status: 'done' | 'todo' | 'in_progress' | 'blocked' | string;
    detail: string;
    action_label: string;
    action_url: string;
    blocked_by: string | null;
}

/**
 * The viewer's own preparation for the next meeting — derived server-side
 * from the same permitted records as their meeting workspace
 * (`GovernancePresenter::memberMeetingReadiness`).
 */
export interface MemberMeetingReadiness {
    workspace_href: string;
    pack: {
        published: boolean;
        read: boolean;
        revision_number: number | null;
        href: string | null;
    };
    /** Agenda items the viewer can see (the Agenda tab). */
    agenda?: { count: number; href: string };
    /** Resolutions the viewer can see (the Decision papers tab). */
    papers: { count: number; href: string };
    votes: { available: boolean; open: number; href: string };
    conflicts: {
        is_member: boolean;
        declared: number;
        decisions_to_check: number;
        href: string;
    };
    rsvp: { invited: boolean; response: string | null } | null;
}

export interface NextMeetingPayload {
    meeting: {
        id: number;
        title: string;
        scheduled_at: string | null;
        scheduled_label: string | null;
        days_until: number | null;
        status: string;
        status_label?: string;
        type_label?: string;
        location: string | null;
        chair: string | null;
        secretary: string | null;
        href: string;
    };
    progress: {
        done: number;
        total: number;
        percent: number;
        remaining: number;
        blocked: number;
    };
    checklist: MeetingChecklistItem[];
    next_step: MeetingChecklistItem | null;
    member_readiness?: MemberMeetingReadiness | null;
}

interface MeetingReadinessPanelProps {
    nextMeeting: NextMeetingPayload | null;
}

const STATUS_ICON: Record<string, { icon: typeof CheckCircle2; cls: string }> =
    {
        done: {
            icon: CheckCircle2,
            cls: 'text-status-success bg-status-success-bg',
        },
        todo: { icon: Circle, cls: 'text-muted-foreground bg-muted' },
        in_progress: { icon: Clock, cls: 'text-status-info bg-status-info-bg' },
        blocked: {
            icon: AlertOctagon,
            cls: 'text-status-warning bg-status-warning-bg',
        },
    };

const STATUS_BADGE: Record<string, { label: string; variant: StatusVariant }> =
    {
        done: { label: 'Done', variant: 'success' },
        todo: { label: 'To do', variant: 'neutral' },
        in_progress: { label: 'In progress', variant: 'info' },
        blocked: { label: 'Waiting', variant: 'warning' },
        not_applicable: { label: 'Not needed', variant: 'neutral' },
    };

/**
 * Map a checklist item key to a verb area so the action picks a specific
 * verb (we don't have priority/status here, just the key).
 */
function areaForChecklistKey(key: string): string {
    if (key.includes('agenda')) return 'meeting';
    if (key.includes('pack')) return 'pack';
    if (key.includes('ceo')) return 'ceo_report';
    if (key.includes('minutes')) return 'meeting';
    if (key.includes('quorum')) return 'meeting';
    if (key.includes('resolution')) return 'resolution';
    if (key.includes('follow')) return 'action';
    return 'meeting';
}

/**
 * The chair and secretary's preparation steps for the next meeting — agenda,
 * CEO report, board pack, attendance and minutes. Only meeting managers
 * (`governance.meetings.manage`) see this on Home; ordinary members get
 * `NextMeetingCard` instead. Renders nothing when there is no next meeting
 * (the Next meeting card owns that empty state).
 */
export function MeetingReadinessPanel({
    nextMeeting,
}: MeetingReadinessPanelProps) {
    if (!nextMeeting) {
        return null;
    }

    const { meeting, progress, checklist, next_step } = nextMeeting;

    return (
        <Card data-dusk="cockpit-meeting-readiness">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-section-title">
                            Meeting preparation checklist
                        </CardTitle>
                        <CardDescription>
                            What the chair and secretary still need to do for{' '}
                            <Link
                                href={meeting.href}
                                className="font-medium text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {meeting.title}
                            </Link>
                            .
                        </CardDescription>
                    </div>
                    <StatusBadge
                        variant={
                            progress.blocked > 0
                                ? 'warning'
                                : progress.remaining > 0
                                  ? 'neutral'
                                  : 'success'
                        }
                    >
                        {progress.done} of {progress.total} steps done
                    </StatusBadge>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                <Progress
                    value={progress.percent}
                    aria-label={`Meeting preparation: ${progress.done} of ${progress.total} steps done`}
                />

                {next_step ? (
                    <div className="rounded-lg border border-primary/20 bg-primary/5 p-3">
                        <p className="text-xs font-medium text-primary">
                            Next step
                        </p>
                        <p className="mt-1 text-sm font-medium text-foreground">
                            {next_step.label}
                        </p>
                        <p className="mt-0.5 text-caption">
                            {next_step.detail}
                        </p>
                    </div>
                ) : null}

                <ul className="flex flex-col gap-2">
                    {checklist.map((item) => {
                        const meta =
                            STATUS_ICON[item.status] ?? STATUS_ICON.todo;
                        const badge = STATUS_BADGE[item.status] ?? {
                            label: humaniseGovernanceValue(item.status) || 'To do',
                            variant: 'neutral' as const,
                        };
                        const StatusIcon = meta.icon;
                        const isBlocked = item.status === 'blocked';
                        const isDone =
                            item.status === 'done' ||
                            item.status === 'not_applicable';
                        const verb = resolveActionVerb(
                            areaForChecklistKey(item.key),
                            'pending',
                            item.action_label,
                        );

                        return (
                            <li
                                key={item.key}
                                className="flex items-start gap-3 rounded-lg border border-border p-3"
                                data-dusk={`cockpit-meeting-step-${item.key}`}
                            >
                                <div
                                    className={cn('rounded-md p-1.5', meta.cls)}
                                >
                                    <StatusIcon
                                        className="size-4"
                                        aria-hidden="true"
                                    />
                                </div>
                                <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p
                                            className={cn(
                                                'inline-flex items-center gap-1 text-sm font-medium text-foreground',
                                                isDone &&
                                                    'text-muted-foreground',
                                            )}
                                        >
                                            {item.label}
                                            {item.key === 'quorum' ? (
                                                <GovernanceTermHint term="quorum" />
                                            ) : null}
                                        </p>
                                        <StatusBadge
                                            size="sm"
                                            variant={badge.variant}
                                        >
                                            {badge.label}
                                        </StatusBadge>
                                    </div>
                                    <p className="text-caption">
                                        {item.detail}
                                    </p>
                                    {isBlocked && item.blocked_by ? (
                                        <p className="text-xs text-status-warning">
                                            Waiting on: {item.blocked_by}
                                        </p>
                                    ) : null}
                                </div>
                                {!isDone && !isBlocked && (
                                    <Button
                                        asChild
                                        size="sm"
                                        variant="outline"
                                        className="shrink-0"
                                    >
                                        <Link href={item.action_url}>
                                            {verb}
                                        </Link>
                                    </Button>
                                )}
                            </li>
                        );
                    })}
                </ul>
            </CardContent>
        </Card>
    );
}

export default MeetingReadinessPanel;
