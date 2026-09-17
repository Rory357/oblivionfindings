import { Link } from '@inertiajs/react';
import {
    BookOpen,
    CalendarDays,
    CheckCircle2,
    FileText,
    ListOrdered,
    MapPin,
    Scale,
    UserCheck,
    Vote,
    type LucideIcon,
} from 'lucide-react';
import type { ReactNode } from 'react';

import { GovernanceTermHint } from '@/components/governance/GovernanceTermHint';
import type { GovernanceTermKey } from '@/lib/governance-glossary';
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
import { governanceStatus } from '@/lib/governance-labels';

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
    term,
    detail,
    status,
    action,
    dusk,
}: {
    icon: LucideIcon;
    label: string;
    term?: GovernanceTermKey;
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
                    <p className="inline-flex items-center gap-1 text-sm font-medium text-foreground">
                        {label}
                        {term ? <GovernanceTermHint term={term} /> : null}
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
 * Next meeting on Governance Home: the date and time (NZ), only the
 * preparation that is the viewer's own — the board pack sent to them, the
 * agenda, decision papers, votes open for them, conflicts of interest and
 * their reply to the invitation — and one "Prepare for meeting" entry into
 * that meeting's workspace. The chair and secretary's preparation steps live
 * in `MeetingReadinessPanel`.
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
                        description="There's no upcoming meeting you're invited to. Earlier meetings, resolutions and minutes are still available."
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
                            term="board_pack"
                            status={
                                !readiness.pack.published
                                    ? { label: 'Not sent yet', variant: 'neutral' }
                                    : readiness.pack.read
                                      ? { label: 'Read', variant: 'success' }
                                      : { label: 'To read', variant: 'warning' }
                            }
                            detail={
                                !readiness.pack.published
                                    ? "The board pack hasn't been sent to you yet."
                                    : readiness.pack.read
                                      ? `You confirmed you read version ${readiness.pack.revision_number ?? 1}.`
                                      : `Read version ${readiness.pack.revision_number ?? 1} and confirm you've read it.`
                            }
                            action={
                                readiness.pack.published && readiness.pack.href ? (
                                    <RowLink href={readiness.pack.href}>
                                        {readiness.pack.read ? 'Open pack' : 'Read pack'}
                                    </RowLink>
                                ) : undefined
                            }
                        />
                        {readiness.agenda ? (
                            <ReadinessRow
                                dusk="next-meeting-agenda"
                                icon={ListOrdered}
                                label="Agenda"
                                status={
                                    readiness.agenda.count > 0
                                        ? {
                                              label: `${readiness.agenda.count} ${plural(readiness.agenda.count, 'item', 'items')}`,
                                              variant: 'neutral',
                                          }
                                        : { label: 'Not ready yet', variant: 'neutral' }
                                }
                                detail={
                                    readiness.agenda.count > 0
                                        ? "What the board will talk about, in order."
                                        : "The agenda hasn't been added yet."
                                }
                                action={
                                    readiness.agenda.count > 0 ? (
                                        <RowLink href={readiness.agenda.href}>
                                            Open agenda
                                        </RowLink>
                                    ) : undefined
                                }
                            />
                        ) : null}
                        <ReadinessRow
                            dusk="next-meeting-papers"
                            icon={FileText}
                            label="Decision papers"
                            status={
                                readiness.papers.count > 0
                                    ? {
                                          label: `${readiness.papers.count} ${plural(readiness.papers.count, 'paper', 'papers')}`,
                                          variant: 'neutral',
                                      }
                                    : { label: 'None yet', variant: 'neutral' }
                            }
                            detail={
                                readiness.papers.count > 0
                                    ? `${readiness.papers.count} ${plural(readiness.papers.count, 'paper is', 'papers are')} ready for you to read.`
                                    : 'No decision papers yet. Papers appear here when the secretary adds them.'
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
                                    ? { label: 'Not available', variant: 'neutral' }
                                    : readiness.votes.open > 0
                                      ? {
                                            label: `${readiness.votes.open} open`,
                                            variant: 'warning',
                                        }
                                      : { label: 'None open', variant: 'neutral' }
                            }
                            detail={
                                !readiness.votes.available
                                    ? "Your votes couldn't be loaded — open the meeting to check."
                                    : readiness.votes.open > 0
                                      ? `${readiness.votes.open} ${plural(readiness.votes.open, 'resolution is', 'resolutions are')} open for your vote.`
                                      : 'Nothing is open for your vote at this meeting yet.'
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
                                term="conflict_of_interest"
                                status={
                                    readiness.conflicts.declared > 0
                                        ? {
                                              label: `${readiness.conflicts.declared} declared`,
                                              variant: 'neutral',
                                          }
                                        : { label: 'None declared', variant: 'neutral' }
                                }
                                detail={
                                    readiness.conflicts.decisions_to_check > 0
                                        ? `${readiness.conflicts.decisions_to_check} ${plural(readiness.conflicts.decisions_to_check, 'decision is', 'decisions are')} on this agenda — declare a conflict of interest if you have one.`
                                        : 'No decisions on this agenda are waiting for you.'
                                }
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
                                    readiness.rsvp.response
                                        ? governanceStatus('rsvp_response', readiness.rsvp.response)
                                        : { label: 'Not replied', variant: 'warning' }
                                }
                                detail={
                                    readiness.rsvp.response
                                        ? 'Your reply is recorded on the meeting.'
                                        : "Let the secretary know if you're attending, or send apologies."
                                }
                                action={
                                    readiness.rsvp.response ? undefined : (
                                        <RowLink href={`${workspaceHref}?tab=attendance`}>
                                            Reply
                                        </RowLink>
                                    )
                                }
                            />
                        ) : null}
                    </ul>
                ) : (
                    <p className="flex items-center gap-2 text-subtle">
                        <CheckCircle2 className="size-4" aria-hidden="true" />
                        Open the meeting to see its agenda, papers and resolutions.
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
