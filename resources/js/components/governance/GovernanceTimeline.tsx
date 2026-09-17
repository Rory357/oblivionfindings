import { Link } from '@inertiajs/react';
import { ChevronDown, ChevronUp, History } from 'lucide-react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { ErrorState } from '@/components/ui/error-state';

export interface TimelineEvent {
    id: string;
    kind: 'action' | 'change' | string;
    actor: string;
    /** Plain verb phrase completing "{actor} …" (e.g. "voted on a resolution"). */
    type: string;
    /** Plain record name (e.g. "Resolution"). */
    entity_type: string;
    entity_id: number | null;
    description: string | null;
    occurred_at: string | null;
    /** NZ time, e.g. "5:00 pm". */
    occurred_label: string | null;
    /** NZ date, e.g. "7 September 2026". */
    day: string | null;
    href: string | null;
}

export interface TimelinePayload {
    since: {
        meeting_id: number;
        title: string;
        held_at: string | null;
        held_label: string | null;
    } | null;
    events: TimelineEvent[];
    /** True when the viewer has no audit access — nothing was sent. */
    restricted?: boolean;
}

interface GovernanceTimelineProps {
    timeline: TimelinePayload;
    defaultLimit?: number;
    onRetry?: () => void;
}

function actorInitials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0]?.toUpperCase() ?? '')
            .join('') || '·'
    );
}

/**
 * What has changed since the last meeting — audit and change events grouped
 * by NZ day. Events are filtered on the server to the records the viewer can
 * open, and only viewers with audit log access receive any.
 */
export function GovernanceTimeline({
    timeline,
    defaultLimit = 8,
    onRetry,
}: GovernanceTimelineProps) {
    const [expanded, setExpanded] = useState(false);
    const isArray = Array.isArray(timeline?.events);
    const events = useMemo(
        () => (isArray ? timeline.events : []),
        [isArray, timeline],
    );

    const grouped = useMemo(() => {
        if (!isArray) return [];
        const map = new Map<string, TimelineEvent[]>();
        for (const e of events.slice(
            0,
            expanded ? events.length : defaultLimit,
        )) {
            const day = e.day ?? 'Recently';
            const list = map.get(day) ?? [];
            list.push(e);
            map.set(day, list);
        }
        return Array.from(map.entries());
    }, [events, expanded, defaultLimit, isArray]);

    if (timeline?.restricted) return null;

    const since = timeline?.since ?? null;

    return (
        <Card data-dusk="cockpit-timeline">
            <CardHeader className="pb-3">
                <div className="flex flex-wrap items-start justify-between gap-2">
                    <div>
                        <CardTitle className="text-section-title">
                            What's changed since the last meeting
                        </CardTitle>
                        <CardDescription>
                            {since
                                ? `Since ${since.title}${since.held_label ? ` on ${since.held_label}` : ''}.`
                                : 'Activity in the last 30 days.'}
                        </CardDescription>
                    </div>
                    {since ? (
                        <Link
                            href={`/governance/meetings/${since.meeting_id}`}
                            className="text-xs font-medium text-primary hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Open last meeting
                        </Link>
                    ) : null}
                </div>
            </CardHeader>
            <CardContent>
                {!isArray ? (
                    <div data-dusk="timeline-error">
                        <ErrorState
                            title="Recent activity couldn't be loaded"
                            message="Nothing is shown rather than an incomplete list. Try again in a few minutes."
                            onRetry={onRetry}
                        />
                    </div>
                ) : events.length === 0 ? (
                    <EmptyState
                        variant="compact"
                        icon={History}
                        title={
                            since
                                ? `Nothing has changed since ${since.title}`
                                : 'Nothing has changed recently'
                        }
                        description="New activity will show here."
                    />
                ) : (
                    <div className="flex flex-col gap-5">
                        {grouped.map(([day, dayEvents]) => (
                            <div key={day}>
                                <p className="mb-2 text-caption font-medium">
                                    {day}
                                </p>
                                <ol className="relative space-y-3 border-l border-border pl-5">
                                    {dayEvents.map((event) => {
                                        const content = (
                                            <span className="flex items-start gap-3">
                                                <span
                                                    aria-hidden="true"
                                                    className="flex size-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold text-muted-foreground"
                                                >
                                                    {actorInitials(event.actor)}
                                                </span>
                                                <span className="min-w-0 flex-1">
                                                    <span className="block text-sm text-foreground">
                                                        <span className="font-medium">
                                                            {event.actor}
                                                        </span>{' '}
                                                        {event.type}
                                                    </span>
                                                    {event.description ? (
                                                        <span className="block text-caption">
                                                            {event.description}
                                                        </span>
                                                    ) : null}
                                                    <span className="block text-caption">
                                                        {[
                                                            event.entity_type,
                                                            event.occurred_label,
                                                        ]
                                                            .filter(Boolean)
                                                            .join(' · ')}
                                                    </span>
                                                </span>
                                            </span>
                                        );
                                        return (
                                            <li
                                                key={event.id}
                                                className="relative"
                                            >
                                                <span
                                                    className="absolute top-3 -left-[1.41rem] size-2 rounded-full bg-primary"
                                                    aria-hidden="true"
                                                />
                                                {event.href ? (
                                                    <Link
                                                        href={event.href}
                                                        className="-m-1 block rounded-md p-1 transition-colors hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                                    >
                                                        {content}
                                                    </Link>
                                                ) : (
                                                    <div className="-m-1 rounded-md p-1">
                                                        {content}
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
                                        <ChevronUp
                                            className="ml-1 size-4"
                                            aria-hidden="true"
                                        />
                                    </>
                                ) : (
                                    <>
                                        Show all {events.length} changes{' '}
                                        <ChevronDown
                                            className="ml-1 size-4"
                                            aria-hidden="true"
                                        />
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
