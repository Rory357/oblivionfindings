import { PageHero, type PageHeroBadge } from '@/components/page';
import ShiftTimelineSummary from '@/components/shift-timeline-summary';
import {
    TimelineInteractions,
    type Comment,
    type ReactionGroup,
} from '@/components/timeline-interactions';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { useInitials } from '@/hooks/use-initials';
import AppLayout from '@/layouts/app-layout';
import {
    categorizeTimelineType,
    getEventDetailLabel,
    getTimelineCategoryEntry,
    TIMELINE_CATEGORY_ORDER,
    TIMELINE_CATEGORY_VOCAB,
    type TimelineCategory,
} from '@/lib/timeline-vocab';
import { Head, Link, router, useForm, usePage } from '@inertiajs/react';
import { ArrowLeft, Filter, Home, Search } from 'lucide-react';
import { useState } from 'react';

type EventDto = {
    id: number;
    type: string;
    occurred_at: string;
    subject?: string | null;
    body?: string | null;
    visibility?: string | null;
    actor?: { id: number; name: string } | null;
    client?: { id: number; first_name: string; last_name: string } | null;
    site?: { id: number; name: string } | null;
    meta?: any;
    comments?: Comment[];
    reactions?: ReactionGroup[];
};

type ClientInfo = {
    id: number;
    first_name: string;
    last_name: string;
    preferred_name?: string | null;
    nhi_number?: string | null;
    status: string;
    avatar?: string | null;
    profile_photo_url?: string | null;
    funding_type?: string | null;
    site?: { name: string } | null;
    service_context?: { name: string } | null;
};

type Props = {
    scope: { type: 'staff' | 'client' | 'site'; id: number; name: string };
    client?: ClientInfo | null;
    range: { from: string; to: string };
    events: EventDto[];
    filters?: { type?: string; from?: string | null; to?: string | null };
};

// Worker-facing category filter options. Raw backend types are still
// preserved on each event and shown as secondary detail; the primary
// row label uses the collapsed category set from `timeline-vocab`.
const CATEGORY_FILTER_OPTIONS: { value: TimelineCategory | 'all'; label: string }[] = [
    { value: 'all', label: 'All activity' },
    ...TIMELINE_CATEGORY_ORDER.map((c) => ({
        value: c,
        label: TIMELINE_CATEGORY_VOCAB[c].label,
    })),
];

function groupByDate(events: EventDto[]): Record<string, EventDto[]> {
    const groups: Record<string, EventDto[]> = {};
    for (const e of events) {
        const date = new Date(e.occurred_at).toLocaleDateString('en-NZ', {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
            year: 'numeric',
        });
        if (!groups[date]) groups[date] = [];
        groups[date].push(e);
    }
    return groups;
}

export default function TimelineIndex(props: Props) {
    const { auth } = usePage().props as any;
    const canCreate = !!auth?.can?.timeline?.create;
    const getInitials = useInitials();
    const c = props.client;
    const isClient = props.scope.type === 'client';
    const name = c ? `${c.first_name} ${c.last_name}`.trim() : props.scope.name;

    const [search, setSearch] = useState('');
    const [category, setCategory] = useState<TimelineCategory | 'all'>('all');
    const [showAddNote, setShowAddNote] = useState(false);

    const noteForm = useForm<{ body: string }>({ body: '' });
    const submitNote = () => {
        if (!isClient) return;
        noteForm.post(`/clients/${props.scope.id}/notes`, {
            preserveScroll: true,
            onSuccess: () => {
                noteForm.reset('body');
                setShowAddNote(false);
            },
        });
    };

    const updateFilter = (key: string, value: string | null) => {
        const params: any = { ...props.filters };
        if (value && value !== 'all') params[key] = value;
        else delete params[key];
        if (key === 'from' && value) params.from = value;
        if (key === 'to' && value) params.to = value;
        router.get(`/clients/${props.scope.id}/timeline`, params, {
            preserveState: true,
            replace: true,
        });
    };

    // Filter by category + free text client-side. Backend date-range filter
    // still controls which events arrive; category is a presentation-layer
    // filter and doesn't need a round-trip.
    const filteredEvents = props.events.filter((e) => {
        if (category !== 'all' && categorizeTimelineType(e.type) !== category) {
            return false;
        }
        if (!search) return true;
        const searchable = [e.subject, e.body, e.type, e.actor?.name]
            .filter(Boolean)
            .join(' ')
            .toLowerCase();
        return searchable.includes(search.toLowerCase());
    });

    const grouped = groupByDate(filteredEvents);

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Clients', href: '/clients' },
                ...(isClient
                    ? [
                          {
                              title: name,
                              href: `/operations/clients/${props.scope.id}`,
                          },
                      ]
                    : []),
                {
                    title: 'Timeline',
                    href: `/clients/${props.scope.id}/timeline`,
                },
            ]}
        >
            <Head title={`Timeline - ${name}`} />

            <div className="mx-auto max-w-5xl space-y-6 p-4 md:p-6">
                {/* Hero Header */}
                {isClient && c && (() => {
                    const badges: PageHeroBadge[] = [
                        { label: c.status, tone: c.status === 'active' ? 'success' : 'default' },
                    ];
                    if (c.funding_type) badges.push({ label: c.funding_type });
                    if (c.service_context) badges.push({ label: c.service_context.name });
                    if (c.site) badges.push({ label: c.site.name, icon: Home });
                    return (
                        <PageHero
                            avatar={{
                                src: c.avatar ?? c.profile_photo_url ?? undefined,
                                fallback: getInitials(name),
                            }}
                            title={name}
                            description={
                                [
                                    c.preferred_name && c.preferred_name !== name ? `Preferred: ${c.preferred_name}` : null,
                                    c.nhi_number ? `NHI: ${c.nhi_number}` : null,
                                ].filter(Boolean).join(' · ')
                            }
                            badges={badges}
                            actions={
                                <Button
                                    size="sm"
                                    variant="outline"
                                    className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground backdrop-blur-sm hover:bg-primary-foreground/20 hover:text-primary-foreground"
                                    asChild
                                >
                                    <Link href={`/operations/clients/${c.id}`}>
                                        <ArrowLeft className="mr-1.5 h-3.5 w-3.5" />
                                        Back
                                    </Link>
                                </Button>
                            }
                        />
                    );
                })()}

                {/* Filters */}
                <Card className="shadow-sm">
                    <CardContent className="space-y-2 p-3">
                    <div className="flex flex-wrap items-center gap-2">
                        <div className="relative flex-1">
                            <Search className="absolute top-2.5 left-2.5 h-3.5 w-3.5 text-muted-foreground" />
                            <Input
                                placeholder="Search events..."
                                className="h-9 pl-8 text-sm"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                            />
                        </div>
                        <Select
                            value={category}
                            onValueChange={(v) =>
                                setCategory(v as TimelineCategory | 'all')
                            }
                        >
                            <SelectTrigger className="h-9 w-[160px] text-xs">
                                <SelectValue placeholder="All activity" />
                            </SelectTrigger>
                            <SelectContent>
                                {CATEGORY_FILTER_OPTIONS.map((o) => (
                                    <SelectItem key={o.value} value={o.value}>
                                        {o.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        {isClient && canCreate && (
                            <Button
                                size="sm"
                                className="gap-1.5"
                                onClick={() => setShowAddNote(!showAddNote)}
                            >
                                {showAddNote ? 'Cancel' : 'Add Note'}
                            </Button>
                        )}
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Filter className="h-3.5 w-3.5 text-muted-foreground" />
                        <span className="text-xs text-muted-foreground">
                            Date range:
                        </span>
                        <Input
                            type="date"
                            className="h-8 w-[140px] text-xs"
                            value={props.filters?.from ?? ''}
                            onChange={(e) =>
                                updateFilter('from', e.target.value || null)
                            }
                        />
                        <span className="text-xs text-muted-foreground">
                            to
                        </span>
                        <Input
                            type="date"
                            className="h-8 w-[140px] text-xs"
                            value={props.filters?.to ?? ''}
                            onChange={(e) =>
                                updateFilter('to', e.target.value || null)
                            }
                        />
                        <span className="ml-2 text-xs text-muted-foreground">
                            {filteredEvents.length} event
                            {filteredEvents.length !== 1 ? 's' : ''}
                        </span>
                    </div>
                    </CardContent>
                </Card>

                {/* Add Note Form */}
                {showAddNote && (
                    <Card className="border-primary/20">
                        <CardContent className="space-y-3 p-4">
                            <Textarea
                                value={noteForm.data.body}
                                onChange={(e) =>
                                    noteForm.setData('body', e.target.value)
                                }
                                placeholder="Write a quick note..."
                                rows={3}
                            />
                            <div className="flex items-center gap-2">
                                <Button
                                    size="sm"
                                    onClick={submitNote}
                                    disabled={
                                        noteForm.processing ||
                                        !noteForm.data.body.trim()
                                    }
                                >
                                    Add note
                                </Button>
                                {noteForm.errors.body && (
                                    <span className="text-xs text-destructive">
                                        {noteForm.errors.body}
                                    </span>
                                )}
                            </div>
                        </CardContent>
                    </Card>
                )}

                {/* Timeline */}
                {filteredEvents.length > 0 ? (
                    <div className="space-y-6">
                        {Object.entries(grouped).map(([date, events]) => (
                            <div key={date}>
                                <div className="mb-3 flex items-center gap-3">
                                    <div className="h-2 w-2 rounded-full bg-primary" />
                                    <span className="text-xs font-semibold text-primary">
                                        {date}
                                    </span>
                                </div>
                                <div className="ml-[3px] space-y-2 border-l-2 border-border pl-4">
                                    {events.map((e) => {
                                        const cat = categorizeTimelineType(
                                            e.type,
                                        );
                                        const style = getTimelineCategoryEntry(
                                            cat,
                                        );
                                        const detailLabel = getEventDetailLabel(
                                            e.type,
                                        );
                                        // If there's no subject, fall back to
                                        // the specific event-type label so the
                                        // row still says *what* happened.
                                        const title = e.subject ?? detailLabel;
                                        // Only show the secondary detail chip
                                        // when it adds information beyond the
                                        // category + title combination.
                                        const showDetailChip =
                                            !!detailLabel &&
                                            detailLabel.toLowerCase() !==
                                                style.label.toLowerCase() &&
                                            detailLabel.toLowerCase() !==
                                                (title ?? '').toLowerCase();
                                        return (
                                            <div
                                                key={e.id}
                                                className={`relative rounded-xl border border-l-4 p-4 transition-all hover:shadow-sm ${style.bg}`}
                                            >
                                                <div className="flex items-start justify-between gap-3">
                                                    <div className="min-w-0 flex-1">
                                                        <div className="flex flex-wrap items-center gap-2">
                                                            <span
                                                                className={`h-2 w-2 rounded-full ${style.dot}`}
                                                            />
                                                            <span className="text-sm font-semibold">
                                                                {title}
                                                            </span>
                                                            <Badge
                                                                variant="outline"
                                                                className={`text-[9px] ${style.pill}`}
                                                            >
                                                                {style.label}
                                                            </Badge>
                                                            {showDetailChip && (
                                                                <span className="text-[10px] text-muted-foreground">
                                                                    {detailLabel}
                                                                </span>
                                                            )}
                                                            {e.visibility ===
                                                                'portal' && (
                                                                <Badge className="border-0 bg-status-info-bg text-[9px] text-status-info dark:bg-status-info-bg dark:text-status-info">
                                                                    Family
                                                                    visible
                                                                </Badge>
                                                            )}
                                                        </div>
                                                        <div className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                            <span>
                                                                {new Date(
                                                                    e.occurred_at,
                                                                ).toLocaleTimeString(
                                                                    'en-NZ',
                                                                    {
                                                                        hour: '2-digit',
                                                                        minute: '2-digit',
                                                                    },
                                                                )}
                                                            </span>
                                                            {e.actor && (
                                                                <>
                                                                    <span>
                                                                        ·
                                                                    </span>
                                                                    <span>
                                                                        {
                                                                            e
                                                                                .actor
                                                                                .name
                                                                        }
                                                                    </span>
                                                                </>
                                                            )}
                                                            {e.site && (
                                                                <>
                                                                    <span>
                                                                        ·
                                                                    </span>
                                                                    <span>
                                                                        {
                                                                            e
                                                                                .site
                                                                                .name
                                                                        }
                                                                    </span>
                                                                </>
                                                            )}
                                                        </div>
                                                    </div>
                                                </div>
                                                {e.body && (
                                                    <p className="mt-2 text-sm leading-relaxed whitespace-pre-wrap text-foreground dark:text-foreground">
                                                        {e.body.length > 300
                                                            ? e.body.slice(
                                                                  0,
                                                                  300,
                                                              ) + '...'
                                                            : e.body}
                                                    </p>
                                                )}
                                                <ShiftTimelineSummary
                                                    eventType={e.type}
                                                    meta={e.meta}
                                                />
                                                {e.meta?.emotions &&
                                                    (
                                                        e.meta
                                                            .emotions as string[]
                                                    ).length > 0 && (
                                                        <div className="mt-1.5 flex flex-wrap gap-1">
                                                            {(
                                                                e.meta
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
                                                {isClient && (
                                                    <TimelineInteractions
                                                        eventId={e.id}
                                                        comments={
                                                            e.comments ?? []
                                                        }
                                                        reactions={
                                                            e.reactions ?? []
                                                        }
                                                        currentUserId={
                                                            auth?.user?.id
                                                        }
                                                        commentUrl={`/clients/${props.scope.id}/timeline/${e.id}/comments`}
                                                        deleteCommentUrl={`/clients/${props.scope.id}/timeline/comments`}
                                                        likeCommentUrl={`/clients/${props.scope.id}/timeline/comments`}
                                                        reactUrl={`/clients/${props.scope.id}/timeline/${e.id}/react`}
                                                        canComment={canCreate}
                                                        canReact={true}
                                                        showStaffBadge={true}
                                                    />
                                                )}
                                            </div>
                                        );
                                    })}
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <Card className="border-dashed">
                        <CardContent className="flex flex-col items-center justify-center py-16">
                            <span className="mb-3 text-4xl">📋</span>
                            <p className="font-medium">No timeline activity</p>
                            <p className="mt-1 text-sm text-muted-foreground">
                                {search
                                    ? 'No events match your search.'
                                    : 'No events in this date range. Try adjusting the filters.'}
                            </p>
                        </CardContent>
                    </Card>
                )}
            </div>
        </AppLayout>
    );
}
