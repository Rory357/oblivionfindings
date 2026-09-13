import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDurationMinutes } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    CheckCircle2,
    Clock3,
    FileText,
    ListChecks,
    MessagesSquare,
} from 'lucide-react';
import type { MyDayTimesheet } from '../lib/types';

export interface OutgoingHandoverSummary {
    shift_id: number;
    id: number | null;
    status: string | null;
    review_url: string | null;
    people: {
        id: number;
        name: string;
        state: 'recorded' | 'not_supported' | 'not_started';
    }[];
}

export function BeforeYouFinish({
    summary,
    timesheet,
    workLeft,
    followedUp,
    onNotes,
    onWork,
    onTime,
    unavailable,
}: {
    summary?: OutgoingHandoverSummary | null;
    timesheet?: MyDayTimesheet | null;
    workLeft: number;
    followedUp: number;
    onNotes?: () => void;
    onWork: () => void;
    onTime?: () => void;
    unavailable?: boolean;
}) {
    const sent =
        summary?.status === 'submitted' || summary?.status === 'acknowledged';
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-section-title">
                    Before you finish
                </CardTitle>
                <p className="text-subtle">
                    Your notes, remaining work and time for this shift.
                </p>
            </CardHeader>
            <CardContent className="divide-y">
                {unavailable && (
                    <p
                        role="alert"
                        className="pb-4 text-sm text-status-warning"
                    >
                        Some shift information is unavailable. Retry loading
                        above before reviewing this summary.
                    </p>
                )}
                <div className="flex flex-wrap items-start justify-between gap-3 py-4">
                    <div className="min-w-0 flex-1">
                        <h3 className="flex items-center gap-2 font-semibold">
                            <FileText className="size-4" />
                            Notes for each person
                        </h3>
                        {summary ? (
                            <ul className="mt-3 space-y-2">
                                {summary.people.map((person) => (
                                    <li
                                        key={person.id}
                                        className="flex flex-wrap items-center justify-between gap-2 text-sm"
                                    >
                                        <span>{person.name}</span>
                                        <StatusBadge
                                            variant={
                                                person.state === 'not_started'
                                                    ? 'neutral'
                                                    : 'success'
                                            }
                                        >
                                            {person.state === 'not_supported'
                                                ? 'Not supported this shift'
                                                : person.state === 'recorded'
                                                  ? sent
                                                      ? 'Included in handover'
                                                      : 'Draft saved'
                                                  : 'Not started'}
                                        </StatusBadge>
                                    </li>
                                ))}
                            </ul>
                        ) : (
                            <p className="text-subtle mt-2">
                                Open shift notes to check each person's section.
                            </p>
                        )}
                    </div>
                    {onNotes && !sent && (
                        <Button
                            variant="outline"
                            className="frontline-tap"
                            onClick={onNotes}
                        >
                            Write shift notes
                        </Button>
                    )}
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3 py-4">
                    <div>
                        <h3 className="flex items-center gap-2 font-semibold">
                            <ListChecks className="size-4" />
                            Remaining work
                        </h3>
                        <p className="text-subtle mt-1">
                            {unavailable
                                ? 'Refresh to check remaining work.'
                                : `${workLeft} to review · ${followedUp} being followed up`}
                        </p>
                    </div>
                    <Button
                        variant="outline"
                        className="frontline-tap"
                        onClick={onWork}
                    >
                        Review remaining work
                    </Button>
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3 py-4">
                    <div>
                        <h3 className="flex items-center gap-2 font-semibold">
                            <MessagesSquare className="size-4" />
                            Handover to the next worker
                        </h3>
                        <p className="text-subtle mt-1">
                            {sent
                                ? summary?.status === 'acknowledged'
                                    ? 'Read by the incoming worker'
                                    : 'Sent · waiting to be read'
                                : summary?.id
                                  ? 'Private draft · not sent yet'
                                  : 'Not started'}
                        </p>
                    </div>
                    {summary?.review_url && (
                        <Button
                            variant="outline"
                            className="frontline-tap"
                            asChild
                        >
                            <Link href={summary.review_url}>
                                {sent ? (
                                    <CheckCircle2 className="size-4" />
                                ) : null}
                                {sent ? 'View handover' : 'Review and send'}
                            </Link>
                        </Button>
                    )}
                </div>
                <div className="flex flex-wrap items-center justify-between gap-3 pt-4">
                    <div>
                        <h3 className="flex items-center gap-2 font-semibold">
                            <Clock3 className="size-4" />
                            This shift's timesheet
                        </h3>
                        <p className="text-subtle mt-1">
                            {timesheet
                                ? `${formatDurationMinutes(timesheet.paid_minutes ?? timesheet.hours * 60)} ${timesheet.clock_running ? 'planned · still clocked in' : timesheet.status === 'submitted' ? 'sent for approval' : timesheet.status === 'returned' ? '· needs your changes' : '· ready to review'}`
                                : 'Review the people you supported and your time.'}
                        </p>
                    </div>
                    {onTime && (
                        <Button
                            variant="outline"
                            className="frontline-tap"
                            onClick={onTime}
                        >
                            Review my timesheet
                        </Button>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}
