import { Link } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    CheckCircle2,
    FileText,
    MapPin,
    Scale,
    UserCheck,
    Vote,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateTimeLong } from '@/lib/datetime';

import type { NextMeetingPayload } from './MeetingReadinessPanel';

interface NextMeetingCardProps {
    nextMeeting: NextMeetingPayload | null;
    canViewMeetings?: boolean;
    canViewRecords?: boolean;
    canScheduleMeeting?: boolean;
}

function countdown(daysUntil: number | null): {
    label: string;
    variant: StatusVariant;
} {
    if (daysUntil === null) return { label: 'Date to be confirmed', variant: 'neutral' };
    if (daysUntil <= 0) return { label: 'Today', variant: 'warning' };
    if (daysUntil === 1) return { label: 'Tomorrow', variant: 'warning' };
    return {
        label: `In ${daysUntil} days`,
        variant: daysUntil <= 7 ? 'info' : 'neutral',
    };
}

const plural = (count: number, one: string, many: string) =>
    count === 1 ? one : many;

function ReadinessRow({
    icon: Icon,
    label,
    detail,
    status,
    action,
    dusk,
}: {
    icon: LucideIcon;
    label: string;
    detail: string;
    status: { label: string; variant: StatusVariant };
    action?: ReactNode;
    dusk: string;
}) {
    return (
        <li
            className="flex items-start gap-3 rounded-lg border border-border p-3"
            data-dusk={dusk}
        >
            <div className="rounded-md bg-muted p-1.5 text-muted-foreground">
                <Icon className="size-4" aria-hidden="true" />
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-0.5">
                <div className="flex flex-wrap items-center gap-2">
                    <p className="text-sm font-medium text-foreground">
                        {label}
                    </p>
                    <StatusBadge size="sm" variant={status.variant}>
                        {status.label}
                    </StatusBadge>
                </div>
                <p className="text-caption">{detail}</p>
            </div>
            {action ? <div className="shrink-0">{action}</div> : null}
        </li>
    );
}

function RowLink({ href, children }: { href: string; children: ReactNode }) {
    return (
        <Button asChild size="sm" variant="outline">
            <Link href={href}>{children}</Link>
        </Button>
    );
}

/**
 * Member-focused Next meeting card for Governance Home: the date and time
 * (NZ), only the readiness that is the viewer's own to act on — the pack
 * published to them, papers to read, votes open for them, conflicts to
 * check, attendance — and one "Prepare for meeting" entry into that exact
 * meeting's workspace. Administrative preparation (CEO report, pack
 * generation, signing) lives in `MeetingReadinessPanel`, shown to managers.
 */
export function NextMeetingCard({
    nextMeeting,
    canViewMeetings = false,
    canViewRecords = false,
    canScheduleMeeting = false,
}: NextMeetingCardProps) {
    if (!nextMeeting) {
        return (
            <Card data-dusk="cockpit-next-meeting">
                <CardHeader className="pb-0">
                    <CardTitle className="text-section-title">
                        Next meeting
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <EmptyState
                        variant="compact"
                        icon={CalendarDays}
                        title="No upcoming meeting"
                        description="There is no upcoming meeting you are invited to. Earlier meetings, decisions and minutes stay available."
                        action={
                            canViewMeetings || canViewRecords || canScheduleMeeting ? (
                                <div className="flex flex-wrap items-center justify-center gap-2">
                                    {canScheduleMeeting ? (
                                        <Button asChild size="sm">
                                            <Link href="/governance/meetings/create">
                                                Schedule meeting
                                            </Link>
                                        </Button>
                                    ) : null}
                                    {canViewMeetings ? (
                                        <Button asChild size="sm" variant="outline">
                                            <Link href="/governance/meetings">
                                                View past meetings
                                            </Link>
                                        </Button>
                                    ) : null}
                                    {canViewRecords ? (
                                        <Button asChild size="sm" variant="outline">
                                            <Link href="/governance/records">
                                                Search records
                                            </Link>
                                        </Button>
                                    ) : null}
                                </div>
                            ) : undefined
                        }
                    />
                </CardContent>
            </Card>
        );
    }

    const { meeting } = nextMeeting;
    const readiness = nextMeeting.member_readiness ?? null;
    const workspaceHref = readiness?.workspace_href ?? meeting.href;
    const when = countdown(meeting.days_until);

    return (
        <Card data-dusk="cockpit-next-meeting">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="flex min-w-0 flex-col gap-1">
                        <CardTitle className="text-section-title">
                            Next meeting
                        </CardTitle>
                        <CardDescription className="flex flex-col gap-0.5">
                            <Link
                                href={workspaceHref}
                                className="text-sm font-medium text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {meeting.title}
                            </Link>
                            <span className="text-subtle">
                                <time dateTime={meeting.scheduled_at ?? undefined}>
                                    {formatDateTimeLong(
                                        meeting.scheduled_at,
                                        'Date to be confirmed',
                                    )}
                                </time>
                                {meeting.location ? (
                                    <span className="ml-2 inline-flex items-center gap-1">
                                        <MapPin
                                            className="size-3.5"
                                            aria-hidden="true"
                                        />
                                        {meeting.location}
                                    </span>
                                ) : null}
                            </span>
                        </CardDescription>
                    </div>
                    <StatusBadge variant={when.variant}>{when.label}</StatusBadge>
                </div>
            </CardHeader>
            <CardContent className="flex flex-col gap-4">
                {readiness ? (
                    <ul
                        className="flex flex-col gap-2"
                        aria-label="Your preparation"
                    >
                        <ReadinessRow
                            dusk="next-meeting-pack"
                            icon={BookOpen}
                            label="Board pack"
                            status={
                                !readiness.pack.published
                                    ? { label: 'Not published', variant: 'neutral' }
                                    : readiness.pack.read
                                      ? { label: 'Read', variant: 'success' }
                                      : { label: 'To read', variant: 'warning' }
                            }
                            detail={
                                !readiness.pack.published
                                    ? 'The pack has not been published to you yet.'
                                    : readiness.pack.read
                                      ? `You have acknowledged revision ${readiness.pack.revision_number ?? 1}.`
                                      : `Revision ${readiness.pack.revision_number ?? 1} is published — reading acknowledgement required.`
                            }
                            action={
                                readiness.pack.published && readiness.pack.href ? (
                                    <RowLink href={readiness.pack.href}>
                                        {readiness.pack.read ? 'Open pack' : 'Read pack'}
                                    </RowLink>
                                ) : undefined
                            }
                        />
                        <ReadinessRow
                            dusk="next-meeting-papers"
                            icon={FileText}
                            label="Papers"
                            status={
                                readiness.papers.count > 0
                                    ? {
                                          label: `${readiness.papers.count} ${plural(readiness.papers.count, 'paper', 'papers')}`,
                                          variant: 'info',
                                      }
                                    : { label: 'None yet', variant: 'neutral' }
                            }
                            detail={
                                readiness.papers.count > 0
                                    ? `${readiness.papers.count} agenda ${plural(readiness.papers.count, 'item is', 'items are')} available for you to read.`
                                    : 'No agenda papers are available to you yet.'
                            }
                            action={
                                readiness.papers.count > 0 ? (
                                    <RowLink href={readiness.papers.href}>
                                        Read papers
                                    </RowLink>
                                ) : undefined
                            }
                        />
                        <ReadinessRow
                            dusk="next-meeting-votes"
                            icon={Vote}
                            label="Votes"
                            status={
                                !readiness.votes.available
                                    ? { label: 'Unavailable', variant: 'neutral' }
                                    : readiness.votes.open > 0
                                      ? {
                                            label: `${readiness.votes.open} open`,
                                            variant: 'warning',
                                        }
                                      : { label: 'None open', variant: 'neutral' }
                            }
                            detail={
                                !readiness.votes.available
                                    ? 'Voting status could not be loaded — open the meeting to check.'
                                    : readiness.votes.open > 0
                                      ? `${readiness.votes.open} ${plural(readiness.votes.open, 'decision is', 'decisions are')} open for your vote.`
                                      : 'No votes are open for you on this meeting.'
                            }
                            action={
                                readiness.votes.available && readiness.votes.open > 0 ? (
                                    <RowLink href={readiness.votes.href}>
                                        Vote
                                    </RowLink>
                                ) : undefined
                            }
                        />
                        {readiness.conflicts.is_member ? (
                            <ReadinessRow
                                dusk="next-meeting-conflicts"
                                icon={Scale}
                                label="Conflicts of interest"
                                status={
                                    readiness.conflicts.decisions_to_check > 0
                                        ? {
                                              label: `${readiness.conflicts.decisions_to_check} to check`,
                                              variant: 'info',
                                          }
                                        : {
                                              label:
                                                  readiness.conflicts.declared > 0
                                                      ? `${readiness.conflicts.declared} declared`
                                                      : 'Nothing to check',
                                              variant: 'neutral',
                                          }
                                }
                                detail={[
                                    readiness.conflicts.decisions_to_check > 0
                                        ? `Check ${readiness.conflicts.decisions_to_check} ${plural(readiness.conflicts.decisions_to_check, 'decision', 'decisions')} and declare any conflict before voting.`
                                        : 'No decisions on this meeting need a conflict check.',
                                    readiness.conflicts.declared > 0
                                        ? `You have declared ${readiness.conflicts.declared}.`
                                        : null,
                                ]
                                    .filter(Boolean)
                                    .join(' ')}
                                action={
                                    readiness.conflicts.decisions_to_check > 0 ? (
                                        <RowLink href={readiness.conflicts.href}>
                                            Review decisions
                                        </RowLink>
                                    ) : undefined
                                }
                            />
                        ) : null}
                        {readiness.rsvp?.invited ? (
                            <ReadinessRow
                                dusk="next-meeting-rsvp"
                                icon={UserCheck}
                                label="Attendance"
                                status={
                                    readiness.rsvp.response === 'accepted'
                                        ? { label: 'Attending', variant: 'success' }
                                        : readiness.rsvp.response === 'declined'
                                          ? { label: 'Apologies sent', variant: 'neutral' }
                                          : readiness.rsvp.response === 'tentative'
                                            ? { label: 'Tentative', variant: 'info' }
                                            : { label: 'Not confirmed', variant: 'warning' }
                                }
                                detail={
                                    readiness.rsvp.response
                                        ? 'Your response is recorded on the meeting.'
                                        : 'Confirm your attendance or send apologies.'
                                }
                                action={
                                    readiness.rsvp.response ? undefined : (
                                        <RowLink href={workspaceHref}>
                                            Respond
                                        </RowLink>
                                    )
                                }
                            />
                        ) : null}
                    </ul>
                ) : (
                    <p className="flex items-center gap-2 text-subtle">
                        <CheckCircle2 className="size-4" aria-hidden="true" />
                        Open the meeting to see its agenda, papers and decisions.
                    </p>
                )}

                <div className="flex flex-wrap items-center gap-2">
                    <Button asChild data-dusk="next-meeting-prepare">
                        <Link href={workspaceHref}>Prepare for meeting</Link>
                    </Button>
                    {canViewMeetings ? (
                        <Button asChild variant="ghost" size="sm">
                            <Link href="/governance/meetings">All meetings</Link>
                        </Button>
                    ) : null}
                </div>
            </CardContent>
        </Card>
    );
}

export default NextMeetingCard;
