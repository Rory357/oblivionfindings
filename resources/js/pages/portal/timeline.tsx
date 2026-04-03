import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { Clock, Filter, Inbox } from 'lucide-react';

type EventItem = {
    id: number;
    type: string;
    subject: string;
    body?: string | null;
    occurred_at: string;
    actor_name?: string | null;
};

type Props = {
    client: { id: number; first_name: string; last_name: string };
    events: {
        data: EventItem[];
        links: any;
        meta: any;
    };
    filter?: string | null;
};

function formatFullDate(iso: string): string {
    return new Date(iso).toLocaleDateString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

function relativeTime(iso: string): string {
    const now = new Date();
    const date = new Date(iso);
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;
    return formatFullDate(iso);
}

const filterPills = [
    { label: 'All', value: 'all' },
    { label: 'Care', value: 'care' },
    { label: 'Visits', value: 'visits' },
    { label: 'Other', value: 'other' },
] as const;

export default function Timeline({ client, events, filter }: Props) {
    const clientName = `${client.first_name} ${client.last_name}`.trim();
    const currentFilter = filter || 'all';

    const applyFilter = (type: string) => {
        router.get(
            `/portal/clients/${client.id}/timeline`,
            { type },
            { preserveState: true, preserveScroll: true },
        );
    };

    const loadMore = () => {
        if (events.links?.next) {
            router.get(events.links.next, {}, { preserveState: true, preserveScroll: true });
        }
    };

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Portal', href: '/portal' },
                { title: clientName, href: `/portal/clients/${client.id}/dashboard` },
                { title: 'Timeline', href: `/portal/clients/${client.id}/timeline` },
            ]}
        >
            <Head title={`${clientName} - Timeline`} />

            <div className="mx-auto max-w-4xl space-y-6 p-4 md:p-6">
                {/* Filter Pills */}
                <div className="flex items-center gap-2">
                    <Filter className="h-4 w-4 text-muted-foreground" />
                    <div className="flex gap-1 rounded-lg border p-0.5">
                        {filterPills.map((pill) => (
                            <button
                                key={pill.value}
                                onClick={() => applyFilter(pill.value)}
                                className={`rounded-md px-3 py-1 text-xs font-medium transition-colors ${
                                    currentFilter === pill.value
                                        ? 'bg-primary text-primary-foreground'
                                        : 'text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {pill.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* Timeline */}
                <Card>
                    <CardHeader className="pb-3">
                        <CardTitle className="flex items-center gap-2 text-base">
                            <Clock className="h-4 w-4 text-primary" />
                            Activity Timeline
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        {events.data.length > 0 ? (
                            <div className="relative space-y-0">
                                {events.data.map((event, idx) => (
                                    <div key={event.id} className="relative flex gap-4 pb-4 last:pb-0">
                                        {/* Timeline line */}
                                        {idx < events.data.length - 1 && (
                                            <div className="absolute left-[11px] top-6 bottom-0 w-px bg-border" />
                                        )}
                                        {/* Dot */}
                                        <div className="relative z-10 mt-1.5 h-[9px] w-[9px] shrink-0 rounded-full border-2 border-primary bg-background" />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-start justify-between gap-2">
                                                <p className="text-sm font-medium leading-tight">
                                                    {event.subject || event.type}
                                                </p>
                                                <span className="shrink-0 text-xs text-muted-foreground">
                                                    {relativeTime(event.occurred_at)}
                                                </span>
                                            </div>
                                            {event.body && (
                                                <p className="mt-0.5 line-clamp-3 text-xs text-muted-foreground">
                                                    {event.body}
                                                </p>
                                            )}
                                            <div className="mt-1 flex items-center gap-2">
                                                {event.actor_name && (
                                                    <p className="text-xs text-muted-foreground/70">
                                                        By {event.actor_name}
                                                    </p>
                                                )}
                                                <Badge variant="outline" className="text-[10px] capitalize">
                                                    {event.type}
                                                </Badge>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        ) : (
                            <div className="flex flex-col items-center justify-center py-12 text-center">
                                <Inbox className="mb-2 h-8 w-8 text-muted-foreground/40" />
                                <p className="text-sm text-muted-foreground">No activity to show</p>
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
