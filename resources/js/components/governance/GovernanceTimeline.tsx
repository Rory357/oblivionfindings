import { Avatar, AvatarFallback } from '@/components/ui/avatar';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronUp, History } from 'lucide-react';
import { useMemo, useState } from 'react';

export interface TimelineEvent {
    id: string;
    kind: 'action' | 'change' | string;
    actor: string;
    type: string;
    entity_type: string;
    entity_id: number | null;
    description: string | null;
    occurred_at: string | null;
    occurred_label: string | null;
    day: string | null;
    href: string | null;
}

export interface TimelinePayload {
    since: {
        meeting_id: number;
        title: string;
        held_at: string;
        held_label: string;
    } | null;
    events: TimelineEvent[];
}

interface GovernanceTimelineProps {
    timeline: TimelinePayload;
    defaultLimit?: number;
}

function actorInitials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

/**
 * What changed since the last board meeting — audit + change events
 * grouped by day, with actor avatars and a "show more" toggle.
 */
export function GovernanceTimeline({
    timeline,
    defaultLimit = 8,
}: GovernanceTimelineProps) {
    const [expanded, setExpanded] = useState(false);
    const events = timeline.events;

    const grouped = useMemo(() => {
        const map = new Map<string, TimelineEvent[]>();
        for (const e of events.slice(
            0,
            expanded ? events.length : defaultLimit,
        )) {
            const day = e.day ?? 'Recent';
            const list = map.get(day) ?? [];
            list.push(e);
            map.set(day, list);
        }
        return Array.from(map.entries());
    }, [events, expanded, defaultLimit]);

    return (
        <Card data-dusk="cockpit-timeline">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-lg">
                            Governance Timeline
                        </CardTitle>
                        <CardDescription>
                            {timeline.since
                                ? `What has changed since ${timeline.since.title} on ${timeline.since.held_label}.`
                                : 'Recent governance activity across the organisation.'}
                        </CardDescription>
                    </div>
                    {timeline.since && (
                        <Link
                            href={`/governance/meetings/${timeline.since.meeting_id}`}
                            className="text-xs font-medium text-primary hover:underline"
                        >
                            View last meeting
                        </Link>
                    )}
                </div>
            </CardHeader>
            <CardContent>
                {events.length === 0 ? (
                    <div className="rounded-lg border border-dashed border-border p-8 text-center">
                        <History
                            className="mx-auto h-5 w-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <p className="mt-2 text-sm font-medium text-foreground">
                            Nothing has changed
                            {timeline.since
                                ? ` since ${timeline.since.title}`
                                : ' recently'}
                            .
                        </p>
                        <p className="mt-1 text-xs text-muted-foreground">
                            New governance activity will appear here
                            automatically.
                        </p>
                    </div>
                ) : (
                    <div className="space-y-5">
                        {grouped.map(([day, dayEvents]) => (
                            <div key={day}>
                                <p className="mb-2 text-xs font-semibold tracking-wide text-muted-foreground uppercase">
                                    {day}
                                </p>
                                <ol className="relative space-y-3 border-l border-border pl-5">
                                    {dayEvents.map((event) => {
                                        const Content = (
                                            <div className="flex items-start gap-3">
                                                <Avatar className="h-8 w-8 shrink-0">
                                                    <AvatarFallback className="bg-primary/10 text-[10px] font-semibold text-primary">
                                                        {actorInitials(
                                                            event.actor,
                                                        )}
                                                    </AvatarFallback>
                                                </Avatar>
                                                <div className="min-w-0 flex-1 space-y-0.5">
                                                    <div className="flex flex-wrap items-center gap-1.5">
                                                        <span className="text-sm font-medium text-foreground">
                                                            {event.actor}
                                                        </span>
                                                        <Badge
                                                            variant="outline"
                                                            className="text-[10px] uppercase"
                                                        >
                                                            {event.type}
                                                        </Badge>
                                                        <Badge
                                                            variant="outline"
                                                            className="text-[10px]"
                                                        >
                                                            {event.entity_type}
                                                        </Badge>
                                                    </div>
                                                    {event.description ? (
                                                        <p className="text-xs text-muted-foreground">
                                                            {event.description}
                                                        </p>
                                                    ) : null}
                                                    {event.occurred_label ? (
                                                        <p className="text-[10px] tracking-wide text-muted-foreground/70 uppercase">
                                                            {
                                                                event.occurred_label
                                                            }
                                                        </p>
                                                    ) : null}
                                                </div>
                                            </div>
                                        );
                                        return (
                                            <li
                                                key={event.id}
                                                className="relative"
                                            >
                                                <span
                                                    className="absolute top-2 -left-[1.41rem] h-2 w-2 rounded-full bg-primary"
                                                    aria-hidden="true"
                                                />
                                                {event.href ? (
                                                    <Link
                                                        href={event.href}
                                                        className="-m-1 block rounded-md p-1 transition hover:bg-muted"
                                                    >
                                                        {Content}
                                                    </Link>
                                                ) : (
                                                    <div className="-m-1 rounded-md p-1">
                                                        {Content}
                                                    </div>
                                                )}
                                            </li>
                                        );
                                    })}
                                </ol>
                            </div>
                        ))}

                        {events.length > defaultLimit ? (
                            <Button
                                variant="ghost"
                                size="sm"
                                className="w-full"
                                onClick={() => setExpanded((v) => !v)}
                            >
                                {expanded ? (
                                    <>
                                        Show less{' '}
                                        <ChevronUp className="ml-1 h-4 w-4" />
                                    </>
                                ) : (
                                    <>
                                        Show all {events.length} events{' '}
                                        <ChevronDown className="ml-1 h-4 w-4" />
                                    </>
                                )}
                            </Button>
                        ) : null}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

export default GovernanceTimeline;
