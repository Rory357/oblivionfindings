import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Badge } from '@/components/ui/badge';
import { Progress } from '@/components/ui/progress';
import { Button } from '@/components/ui/button';
import { Link } from '@inertiajs/react';
import { CalendarDays, CheckCircle2, Circle, Clock, AlertOctagon } from 'lucide-react';
import { cn } from '@/lib/utils';
import { resolveActionVerb } from '@/lib/governance-action-verbs';

export interface MeetingChecklistItem {
    key: string;
    label: string;
    status: 'done' | 'todo' | 'in_progress' | 'blocked' | string;
    detail: string;
    action_label: string;
    action_url: string;
    blocked_by: string | null;
}

export interface NextMeetingPayload {
    meeting: {
        id: number;
        title: string;
        scheduled_at: string | null;
        scheduled_label: string | null;
        days_until: number | null;
        status: string;
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
}

interface MeetingReadinessPanelProps {
    nextMeeting: NextMeetingPayload | null;
    canScheduleMeeting?: boolean;
}

const STATUS_ICON: Record<string, { icon: typeof CheckCircle2; cls: string }> = {
    done: { icon: CheckCircle2, cls: 'text-status-success bg-status-success-bg' },
    todo: { icon: Circle, cls: 'text-muted-foreground bg-muted' },
    in_progress: { icon: Clock, cls: 'text-status-info bg-status-info-bg' },
    blocked: { icon: AlertOctagon, cls: 'text-status-critical bg-status-critical-bg' },
};

const STATUS_LABEL: Record<string, string> = {
    done: 'Done',
    todo: 'Pending',
    in_progress: 'In progress',
    blocked: 'Blocked',
};

/**
 * Map a checklist item key to a verb area so NextActionButton can pick a
 * specific verb (we don't have priority/status here, just the key).
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
 * Visual lifecycle of the next board meeting — agenda → pack → pre-read →
 * RSVP/quorum → minutes draft → minutes signed. Each step shows its status,
 * an explanation, and a specific-verb action button.
 */
export function MeetingReadinessPanel({
    nextMeeting,
    canScheduleMeeting = false,
}: MeetingReadinessPanelProps) {
    if (!nextMeeting) {
        return (
            <Card data-dusk="cockpit-meeting-readiness">
                <CardHeader>
                    <CardTitle className="text-lg">Next Meeting Readiness</CardTitle>
                    <CardDescription>Preparation status for the next board meeting.</CardDescription>
                </CardHeader>
                <CardContent>
                    <div className="rounded-lg border border-dashed border-border p-8 text-center">
                        <CalendarDays className="mx-auto h-6 w-6 text-muted-foreground" aria-hidden="true" />
                        <p className="mt-2 text-sm font-medium text-foreground">No meeting scheduled</p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            Schedule the next board meeting to begin pre-read preparation.
                        </p>
                        {canScheduleMeeting && (
                            <Button asChild size="sm" className="mt-4">
                                <Link href="/governance/meetings/create">Schedule meeting</Link>
                            </Button>
                        )}
                    </div>
                </CardContent>
            </Card>
        );
    }

    const { meeting, progress, checklist, next_step } = nextMeeting;
    const daysUntil = meeting.days_until ?? 0;
    const urgency =
        daysUntil <= 3 && progress.percent < 80
            ? 'critical'
            : daysUntil <= 7 && progress.percent < 60
              ? 'warning'
              : 'good';
    const urgencyTone = urgency === 'critical' ? 'critical' : urgency === 'warning' ? 'warning' : 'info';

    return (
        <Card data-dusk="cockpit-meeting-readiness">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <CardTitle className="text-lg">Next Meeting Readiness</CardTitle>
                        <CardDescription>
                            <Link href={meeting.href} className="font-medium text-foreground hover:underline">
                                {meeting.title}
                            </Link>
                            {meeting.scheduled_label ? ` · ${meeting.scheduled_label}` : ''}
                        </CardDescription>
                    </div>
                    <Badge
                        className={cn(
                            'border',
                            urgencyTone === 'critical' && 'border-status-critical/30 bg-status-critical-bg text-status-critical',
                            urgencyTone === 'warning' && 'border-status-warning/30 bg-status-warning-bg text-status-warning',
                            urgencyTone === 'info' && 'border-status-info/30 bg-status-info-bg text-status-info',
                        )}
                    >
                        {daysUntil <= 0
                            ? 'Today'
                            : daysUntil === 1
                              ? '1 day to go'
                              : `${daysUntil} days to go`}
                    </Badge>
                </div>
            </CardHeader>
            <CardContent className="space-y-4">
                <div className="space-y-1.5">
                    <div className="flex items-center justify-between text-xs text-muted-foreground">
                        <span>
                            {progress.done} of {progress.total} steps complete
                        </span>
                        <span className="font-medium text-foreground">{progress.percent}%</span>
                    </div>
                    <Progress value={progress.percent} aria-label="Meeting readiness progress" />
                </div>

                {next_step ? (
                    <div className="rounded-lg border border-primary/20 bg-primary/5 p-3">
                        <p className="text-xs font-medium uppercase tracking-wide text-primary">Next step</p>
                        <p className="mt-1 text-sm font-medium text-foreground">{next_step.label}</p>
                        <p className="mt-0.5 text-xs text-muted-foreground">{next_step.detail}</p>
                    </div>
                ) : null}

                <div className="space-y-2">
                    {checklist.map((item) => {
                        const meta = STATUS_ICON[item.status] ?? STATUS_ICON.todo;
                        const StatusIcon = meta.icon;
                        const isBlocked = item.status === 'blocked';
                        const isDone = item.status === 'done';
                        const verb = resolveActionVerb(areaForChecklistKey(item.key), 'pending', item.action_label);

                        return (
                            <div
                                key={item.key}
                                className={cn(
                                    'flex items-start gap-3 rounded-lg border border-border p-3',
                                    isDone && 'bg-status-success-bg/30',
                                )}
                                data-dusk={`cockpit-meeting-step-${item.key}`}
                            >
                                <div className={cn('rounded-md p-1.5', meta.cls)}>
                                    <StatusIcon className="h-4 w-4" aria-hidden="true" />
                                </div>
                                <div className="min-w-0 flex-1 space-y-0.5">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className={cn('text-sm font-medium', isDone && 'text-muted-foreground line-through')}>
                                            {item.label}
                                        </p>
                                        <Badge variant="outline" className="text-[10px] uppercase">
                                            {STATUS_LABEL[item.status] ?? item.status}
                                        </Badge>
                                    </div>
                                    <p className="text-xs text-muted-foreground">{item.detail}</p>
                                    {isBlocked && item.blocked_by ? (
                                        <p className="text-xs italic text-status-critical">Blocked by: {item.blocked_by}</p>
                                    ) : null}
                                </div>
                                {!isDone && !isBlocked && (
                                    <Button
                                        asChild
                                        size="sm"
                                        variant={item.status === 'in_progress' ? 'default' : 'outline'}
                                        className="shrink-0"
                                    >
                                        <Link href={item.action_url}>{verb}</Link>
                                    </Button>
                                )}
                            </div>
                        );
                    })}
                </div>
            </CardContent>
        </Card>
    );
}

export default MeetingReadinessPanel;
