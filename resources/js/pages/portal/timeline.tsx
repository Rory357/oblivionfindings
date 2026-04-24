import ShiftTimelineSummary, {
    isShiftTimelineEvent,
} from '@/components/shift-timeline-summary';
import {
    TimelineInteractions,
    type Comment,
    type ReactionGroup,
} from '@/components/timeline-interactions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router, usePage } from '@inertiajs/react';
import { Filter } from 'lucide-react';

type EventItem = {
    id: number;
    type: string;
    subject: string;
    body?: string | null;
    occurred_at: string;
    actor_name?: string | null;
    meta?: any;
    comments: Comment[];
    reactions: ReactionGroup[];
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    events: {
        data: EventItem[];
        links: any;
        meta: any;
    };
    filter?: string | null;
    showShiftSchedule?: boolean;
};

function relativeTime(iso: string): string {
    const now = new Date();
    const date = new Date(iso);
    const diffMs = now.getTime() - date.getTime();
    if (diffMs < 0) {
        const futureMins = Math.floor(Math.abs(diffMs) / 60000);
        if (futureMins < 1) return 'soon';
        if (futureMins < 60) return `in ${futureMins}m`;
        const futureHours = Math.floor(futureMins / 60);
        if (futureHours < 24) return `in ${futureHours}h`;
        return new Date(iso).toLocaleDateString([], {
            weekday: 'short',
            month: 'short',
            day: 'numeric',
            hour: '2-digit',
            minute: '2-digit',
        });
    }
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;
    return new Date(iso).toLocaleDateString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

const filterPills = [
    { label: 'All', value: 'all' },
    { label: 'Shifts', value: 'shifts' },
    { label: 'Care', value: 'care' },
    { label: 'Visits', value: 'visits' },
    { label: 'Other', value: 'other' },
] as const;

const eventTypeEmojis: Record<string, string> = {
    shift: '🗓️',
    shift_started: '🟢',
    shift_completed: '✅',
    shift_cancelled: '⚠️',
    care: '💊',
    visits: '👋',
    other: '📌',
    progress_note: '📝',
    shift_note: '📝',
    handover: '🔄',
    visit_requested: '👋',
    visit_approved: '✅',
    visit_cancelled: '🚫',
};

const eventTypeLabels: Record<string, string> = {
    shift: 'shift',
    shift_started: 'arrival',
    shift_completed: 'completed',
    shift_cancelled: 'cancelled',
    progress_note: 'note',
    shift_note: 'shift note',
    handover: 'handover',
    visit_requested: 'visit',
    visit_approved: 'visit',
    visit_cancelled: 'visit',
};

export default function Timeline({
    client,
    events,
    filter,
    showShiftSchedule = true,
}: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();
    const currentFilter = filter || 'all';
    const { auth } = usePage<{ auth: { user: { id: number } } }>().props;
    const currentUserId = auth.user.id;
    const visibleFilterPills = filterPills.filter(
        (pill) => showShiftSchedule || pill.value !== 'shifts',
    );

    const applyFilter = (type: string) => {
        router.get(
            `/portal/clients/${client.id}/timeline`,
            { type },
            { preserveState: true, preserveScroll: true },
        );
    };

    const loadMore = () => {
        if (events.links?.next) {
            router.get(
                events.links.next,
                {},
                { preserveState: true, preserveScroll: true },
            );
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                {
                    title: clientName,
                    href: `/portal/clients/${client.id}/dashboard`,
                },
                {
                    title: 'Timeline',
                    href: `/portal/clients/${client.id}/timeline`,
                },
            ]}
        >
            <Head title={`${clientName} - Timeline`} />

            <div className="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
                {/* Filter Pills */}
                <div className="flex items-center gap-2">
                    <Filter className="h-4 w-4 text-muted-foreground" />
                    <div className="flex gap-1 rounded-lg border p-0.5">
                        {visibleFilterPills.map((pill) => (
                            <Button
                                type="button"
                                variant={
                                    currentFilter === pill.value
                                        ? 'default'
                                        : 'ghost'
                                }
                                size="xs"
                                key={pill.value}
                                onClick={() => applyFilter(pill.value)}
                                className={`h-auto rounded-md px-3 py-1 text-xs font-medium ${
                                    currentFilter === pill.value
                                        ? ''
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {pill.label}
                            </Button>
                        ))}
                    </div>
                </div>

                {/* Timeline */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <span>📰</span>
                            Activity Timeline
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {events.data.length > 0 ? (
                            <div className="relative space-y-0">
                                {events.data.map((event, idx) => {
                                    const typeEmoji =
                                        eventTypeEmojis[event.type] ?? '📌';
                                    return (
                                        <div
                                            key={event.id}
                                            className="relative flex gap-4 pb-5 last:pb-0"
                                        >
                                            {/* Timeline line */}
                                            {idx < events.data.length - 1 && (
                                                <div className="absolute top-6 bottom-0 left-[11px] w-px bg-border" />
                                            )}
                                            {/* Dot */}
                                            <div className="relative z-10 mt-1.5 h-[9px] w-[9px] shrink-0 rounded-full border-2 border-primary bg-background" />
                                            <div className="min-w-0 flex-1">
                                                <div className="flex items-start justify-between gap-2">
                                                    <p className="text-sm leading-tight font-medium">
                                                        <span className="mr-1">
                                                            {typeEmoji}
                                                        </span>
                                                        {event.subject ||
                                                            event.type}
                                                    </p>
                                                    <span className="shrink-0 text-xs text-muted-foreground">
                                                        {relativeTime(
                                                            event.occurred_at,
                                                        )}
                                                    </span>
                                                </div>
                                                {event.body && (
                                                    <p className="mt-0.5 line-clamp-3 text-xs text-muted-foreground">
                                                        {event.body}
                                                    </p>
                                                )}
                                                <ShiftTimelineSummary
                                                    eventType={event.type}
                                                    meta={event.meta}
                                                />
                                                <div className="mt-1 flex items-center gap-2">
                                                    {event.actor_name && (
                                                        <p className="text-xs text-muted-foreground/70">
                                                            By{' '}
                                                            {event.actor_name}
                                                        </p>
                                                    )}
                                                    <Badge
                                                        variant="outline"
                                                        className="text-[10px] capitalize"
                                                    >
                                                        {eventTypeLabels[
                                                            event.type
                                                        ] ??
                                                            event.type.replace(
                                                                /_/g,
                                                                ' ',
                                                            )}
                                                    </Badge>
                                                    {isShiftTimelineEvent(
                                                        event.type,
                                                    ) && (
                                                        <Badge
                                                            variant="outline"
                                                            className="text-[10px]"
                                                        >
                                                            Support update
                                                        </Badge>
                                                    )}
                                                </div>
                                                {event.meta?.emotions &&
                                                    (
                                                        event.meta
                                                            .emotions as string[]
                                                    ).length > 0 && (
                                                        <div className="mt-1.5 flex flex-wrap gap-1">
                                                            {(
                                                                event.meta
                                                                    .emotions as string[]
                                                            ).map(
                                                                (
                                                                    em: string,
                                                                ) => {
                                                                    const emojiMap: Record<
                                                                        string,
                                                                        string
                                                                    > = {
                                                                        happy: '😊',
                                                                        calm: '😌',
                                                                        excited:
                                                                            '🤩',
                                                                        tired: '😴',
                                                                        anxious:
                                                                            '😰',
                                                                        sad: '😢',
                                                                        frustrated:
                                                                            '😤',
                                                                        confused:
                                                                            '😕',
                                                                    };
                                                                    const colorMap: Record<
                                                                        string,
                                                                        string
                                                                    > = {
                                                                        happy: 'bg-status-success-bg text-status-success',
                                                                        calm: 'bg-status-info-bg text-status-info',
                                                                        excited:
                                                                            'bg-status-warning-bg text-status-warning',
                                                                        tired: 'bg-primary/10 text-primary',
                                                                        anxious:
                                                                            'bg-status-warning-bg text-status-warning',
                                                                        sad: 'bg-status-info-bg text-status-info',
                                                                        frustrated:
                                                                            'bg-status-critical-bg text-status-critical',
                                                                        confused:
                                                                            'bg-primary/10 text-primary',
                                                                    };
                                                                    return (
                                                                        <span
                                                                            key={
                                                                                em
                                                                            }
                                                                            className={`inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-medium ${colorMap[em] ?? 'bg-muted'}`}
                                                                        >
                                                                            {emojiMap[
                                                                                em
                                                                            ] ??
                                                                                em}{' '}
                                                                            {em}
                                                                        </span>
                                                                    );
                                                                },
                                                            )}
                                                        </div>
                                                    )}

                                                <TimelineInteractions
                                                    eventId={event.id}
                                                    comments={event.comments}
                                                    reactions={event.reactions}
                                                    currentUserId={
                                                        currentUserId
                                                    }
                                                    commentUrl={`/portal/clients/${client.id}/timeline/${event.id}/comments`}
                                                    deleteCommentUrl={`/portal/clients/${client.id}/timeline/comments`}
                                                    likeCommentUrl={`/portal/clients/${client.id}/timeline/comments`}
                                                    reactUrl={`/portal/clients/${client.id}/timeline/${event.id}/react`}
                                                    canComment={true}
                                                    canReact={true}
                                                    showStaffBadge={true}
                                                />
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <span className="mb-2 text-3xl">🌻</span>
                                <p className="text-sm text-muted-foreground">
                                    Nothing to show here yet &mdash; check back
                                    soon!
                                </p>
                                {currentFilter !== 'all' && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        className="mt-3"
                                        onClick={() => applyFilter('all')}
                                    >
                                        Clear filter
                                    </Button>
                                )}
                            </div>
                        )}

                        {/* Load More */}
                        {events.links?.next && (
                            <div className="mt-6 flex justify-center">
                                <Button variant="outline" onClick={loadMore}>
                                    Load more
                                </Button>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </AppLayout>
    );
}
