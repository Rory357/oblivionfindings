import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarCheck,
    CheckCircle2,
    ClipboardList,
    Flag,
    MessageSquare,
    Search,
    UserRound,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

export type ClientDailyNote = {
    id: number;
    type?: string | null;
    category?: string | null;
    subject?: string | null;
    body?: string | null;
    occurred_at?: string | null;
    created_at?: string | null;
    updated_at?: string | null;
    visibility?: string | null;
    is_flagged?: boolean;
    flagged_reason?: string | null;
    reviewed_at?: string | null;
    is_draft?: boolean;
    mood_rating?: number | null;
    behaviour_tags?: string[] | null;
    concerns_flags?: string[] | null;
    follow_up_action?: string | null;
    follow_up_due_at?: string | null;
    contact_person?: string | null;
    contact_relationship?: string | null;
    contact_method?: string | null;
    author?: { id: number; name: string } | null;
};

export type DailyNotesSummary = {
    total?: number;
    flagged_open?: number;
    drafts?: number;
    communication?: number;
    open_follow_ups?: number;
};

type DailyNotesTabProps = {
    clientId: number;
    notes: ClientDailyNote[];
    summary: DailyNotesSummary;
    canReview?: boolean;
    canUpdate?: boolean;
    onCreateDaily: () => void;
    onCreateQuick: () => void;
    filterPreset?: DailyNotesFilter;
    onFilterChange?: (filter: DailyNotesFilter) => void;
    onShowReviewQueue?: () => void;
    isLoading?: boolean;
    legacyProgressNotes?: any[];
};

type DailyNotesFilter = 'all' | 'flagged' | 'follow_up' | 'drafts';

function dateLabel(value?: string | null) {
    if (!value) return 'No date';
    return new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
}

function categoryLabel(value?: string | null) {
    return String(value ?? 'other').replace(/_/g, ' ');
}

const statCards = [
    {
        key: 'total',
        label: 'Daily notes',
        icon: ClipboardList,
        tone: 'text-primary bg-primary/10',
    },
    {
        key: 'flagged_open',
        label: 'Need review',
        icon: AlertTriangle,
        tone: 'text-status-warning bg-status-warning-bg',
    },
    {
        key: 'open_follow_ups',
        label: 'Follow-ups',
        icon: CalendarCheck,
        tone: 'text-status-info bg-status-info-bg',
    },
    {
        key: 'drafts',
        label: 'Drafts',
        icon: Flag,
        tone: 'text-muted-foreground bg-muted',
    },
];

function filterFromQuery(): DailyNotesFilter {
    if (typeof window === 'undefined') return 'all';

    const params = new URLSearchParams(window.location.search);
    if (params.get('flagged') === '1' && params.get('reviewed') === '0') {
        return 'flagged';
    }
    if (params.get('drafts') === '1') return 'drafts';
    if (params.get('follow_up') === '1') return 'follow_up';

    return 'all';
}

export function DailyNotesTab({
    clientId,
    notes,
    summary,
    canReview = false,
    canUpdate = false,
    onCreateDaily,
    onCreateQuick,
    filterPreset,
    onFilterChange,
    onShowReviewQueue,
    isLoading = false,
    legacyProgressNotes = [],
}: DailyNotesTabProps) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<DailyNotesFilter>(
        () => filterPreset ?? filterFromQuery(),
    );

    useEffect(() => {
        if (filterPreset) {
            setFilter(filterPreset);
        }
    }, [filterPreset]);

    const handleFilterChange = (value: string) => {
        const next = value as DailyNotesFilter;
        setFilter(next);
        onFilterChange?.(next);
    };

    const filteredNotes = useMemo(() => {
        const search = query.trim().toLowerCase();

        return notes.filter((note) => {
            if (filter === 'flagged' && !note.is_flagged) return false;
            if (filter === 'drafts' && !note.is_draft) return false;
            if (filter === 'follow_up' && !note.follow_up_action?.trim()) {
                return false;
            }
            if (!search) return true;

            return [
                note.subject,
                note.body,
                note.category,
                note.author?.name,
                note.flagged_reason,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search);
        });
    }, [filter, notes, query]);

    const reviewQueue = notes.filter(
        (note) => note.is_flagged && !note.reviewed_at,
    );
    const draftNotes = notes.filter((note) => note.is_draft);

    if (isLoading) {
        return (
            <div
                className="space-y-6"
                aria-busy="true"
                data-test="client-daily-notes-skeleton"
            >
                <div className="grid gap-3 md:grid-cols-4">
                    {[0, 1, 2, 3].map((item) => (
                        <div key={item} className="rounded-lg border p-4">
                            <Skeleton className="h-3 w-24" />
                            <Skeleton className="mt-3 h-8 w-14" />
                        </div>
                    ))}
                </div>
                <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                    <div className="space-y-3">
                        {[0, 1, 2].map((item) => (
                            <Skeleton key={item} className="h-36 rounded-lg" />
                        ))}
                    </div>
                    <Skeleton className="h-64 rounded-lg" />
                </div>
            </div>
        );
    }

    const markReviewed = (noteId: number) => {
        router.post(
            `/operations/clients/${clientId}/daily-notes/${noteId}/review`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    window.dispatchEvent(
                        new CustomEvent('client-profile:review-cleared', {
                            detail: { note_id: noteId },
                        }),
                    ),
            },
        );
    };

    const clearFlag = (noteId: number) => {
        router.post(
            `/operations/clients/${clientId}/daily-notes/${noteId}/flag`,
            { is_flagged: false },
            { preserveScroll: true },
        );
    };

    return (
        <div className="space-y-6" data-test="client-daily-notes-tab">
            <div className="grid gap-3 md:grid-cols-4">
                {statCards.map((stat) => {
                    const Icon = stat.icon;
                    const value =
                        summary[stat.key as keyof DailyNotesSummary] ?? 0;

                    return (
                        <div
                            key={stat.key}
                            className="rounded-lg border bg-card p-4"
                        >
                            <div className="flex items-center justify-between gap-3">
                                <div>
                                    <p className="text-xs text-muted-foreground">
                                        {stat.label}
                                    </p>
                                    <p className="mt-1 text-2xl font-semibold">
                                        {value}
                                    </p>
                                </div>
                                <span
                                    className={cn('rounded-lg p-2', stat.tone)}
                                >
                                    <Icon className="h-5 w-5" />
                                </span>
                            </div>
                        </div>
                    );
                })}
            </div>

            <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                <div className="flex flex-1 flex-col gap-2 sm:flex-row">
                    <div className="relative max-w-xl flex-1">
                        <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                        <Input
                            value={query}
                            onChange={(event) => setQuery(event.target.value)}
                            placeholder="Search notes"
                            className="min-h-11 pl-9"
                        />
                    </div>
                    <Select value={filter} onValueChange={handleFilterChange}>
                        <SelectTrigger className="min-h-11 sm:w-44">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="all">All notes</SelectItem>
                            <SelectItem value="flagged">
                                Needs review
                            </SelectItem>
                            <SelectItem value="follow_up">
                                Follow-ups
                            </SelectItem>
                            <SelectItem value="drafts">Drafts</SelectItem>
                        </SelectContent>
                    </Select>
                </div>

                <div className="flex flex-wrap gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        onClick={onCreateQuick}
                        className="min-h-11"
                        data-test="client-daily-notes-quick-note-button"
                    >
                        <MessageSquare className="mr-2 h-4 w-4" />
                        Quick Note
                    </Button>
                    <Button
                        type="button"
                        onClick={onCreateDaily}
                        className="min-h-11"
                        data-test="client-daily-notes-daily-note-button"
                    >
                        <ClipboardList className="mr-2 h-4 w-4" />
                        Daily Note
                    </Button>
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="space-y-3">
                    {filteredNotes.length > 0 ? (
                        filteredNotes.map((note) => (
                            <article
                                key={note.id}
                                className={cn(
                                    'rounded-lg border bg-card p-4',
                                    note.is_flagged &&
                                        !note.reviewed_at &&
                                        'border-status-warning/40 bg-status-warning-bg/40',
                                )}
                            >
                                <div className="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
                                    <div className="min-w-0 space-y-2">
                                        <div className="flex flex-wrap items-center gap-2">
                                            <Badge variant="secondary">
                                                {categoryLabel(note.category)}
                                            </Badge>
                                            {note.is_draft ? (
                                                <Badge variant="outline">
                                                    Draft
                                                </Badge>
                                            ) : null}
                                            {note.is_flagged ? (
                                                <Badge className="bg-status-warning-bg text-status-warning">
                                                    Needs review
                                                </Badge>
                                            ) : null}
                                            {note.reviewed_at ? (
                                                <Badge className="bg-status-success-bg text-status-success">
                                                    Reviewed
                                                </Badge>
                                            ) : null}
                                        </div>
                                        {note.subject ? (
                                            <h3 className="text-base font-semibold">
                                                {note.subject}
                                            </h3>
                                        ) : null}
                                    </div>
                                    <p className="shrink-0 text-sm text-muted-foreground">
                                        {dateLabel(
                                            note.occurred_at ?? note.created_at,
                                        )}
                                    </p>
                                </div>

                                <p className="mt-3 text-sm leading-6 whitespace-pre-wrap text-foreground">
                                    {note.body}
                                </p>

                                <div className="mt-4 flex flex-wrap gap-2">
                                    {(note.behaviour_tags ?? []).map((tag) => (
                                        <Badge key={tag} variant="outline">
                                            {tag}
                                        </Badge>
                                    ))}
                                    {(note.concerns_flags ?? []).map((flag) => (
                                        <Badge
                                            key={flag}
                                            className="bg-status-critical-bg text-status-critical"
                                        >
                                            {flag}
                                        </Badge>
                                    ))}
                                    {note.mood_rating ? (
                                        <Badge variant="outline">
                                            Mood {note.mood_rating}/10
                                        </Badge>
                                    ) : null}
                                </div>

                                {note.follow_up_action ? (
                                    <div className="mt-4 rounded-lg border bg-background p-3 text-sm">
                                        <p className="font-medium">Follow-up</p>
                                        <p className="mt-1 text-muted-foreground">
                                            {note.follow_up_action}
                                        </p>
                                        {note.follow_up_due_at ? (
                                            <p className="mt-2 text-xs text-muted-foreground">
                                                Due{' '}
                                                {dateLabel(
                                                    note.follow_up_due_at,
                                                )}
                                            </p>
                                        ) : null}
                                    </div>
                                ) : null}

                                <div className="mt-4 flex flex-wrap items-center justify-between gap-3 text-xs text-muted-foreground">
                                    <span className="inline-flex items-center gap-1.5">
                                        <UserRound className="h-3.5 w-3.5" />
                                        {note.author?.name ?? 'Unknown worker'}
                                    </span>
                                    {canReview &&
                                    note.is_flagged &&
                                    !note.reviewed_at ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            onClick={() =>
                                                markReviewed(note.id)
                                            }
                                        >
                                            <CheckCircle2 className="mr-2 h-4 w-4" />
                                            Mark Reviewed
                                        </Button>
                                    ) : canUpdate &&
                                      note.is_flagged &&
                                      note.reviewed_at ? (
                                        <Button
                                            type="button"
                                            size="sm"
                                            variant="outline"
                                            onClick={() => clearFlag(note.id)}
                                        >
                                            Clear Flag
                                        </Button>
                                    ) : null}
                                </div>
                            </article>
                        ))
                    ) : (
                        <EmptyState
                            icon={ClipboardList}
                            title="No daily notes match"
                            description="Change the filters or add a note from the action buttons above."
                        />
                    )}
                </div>

                <aside className="space-y-4">
                    <Card>
                        <CardHeader>
                            <div className="flex items-center justify-between gap-3">
                                <CardTitle className="flex items-center gap-2 text-base">
                                    <AlertTriangle className="h-4 w-4 text-status-warning" />
                                    Review Queue
                                </CardTitle>
                                {reviewQueue.length > 0 ? (
                                    <Button
                                        type="button"
                                        size="sm"
                                        variant="outline"
                                        onClick={() => {
                                            handleFilterChange('flagged');
                                            onShowReviewQueue?.();
                                        }}
                                        data-test="client-daily-notes-review-filter"
                                    >
                                        Show Queue
                                    </Button>
                                ) : null}
                            </div>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {reviewQueue.length > 0 ? (
                                reviewQueue.map((note) => (
                                    <div
                                        key={note.id}
                                        className="rounded-lg border p-3 text-sm"
                                    >
                                        <p className="font-medium">
                                            {note.subject ||
                                                categoryLabel(note.category)}
                                        </p>
                                        <p className="mt-1 line-clamp-3 text-muted-foreground">
                                            {note.flagged_reason || note.body}
                                        </p>
                                        <div className="mt-3 flex items-center justify-between gap-2">
                                            <span className="text-xs text-muted-foreground">
                                                {dateLabel(
                                                    note.created_at ??
                                                        note.occurred_at,
                                                )}
                                            </span>
                                            {canReview ? (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        markReviewed(note.id)
                                                    }
                                                >
                                                    Review
                                                </Button>
                                            ) : null}
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No daily notes are waiting for review.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle className="flex items-center gap-2 text-base">
                                <Flag className="h-4 w-4 text-muted-foreground" />
                                My Drafts
                            </CardTitle>
                        </CardHeader>
                        <CardContent className="space-y-3">
                            {draftNotes.length > 0 ? (
                                draftNotes.slice(0, 5).map((note) => (
                                    <div
                                        key={note.id}
                                        className="rounded-lg border p-3 text-sm"
                                    >
                                        <p className="font-medium">
                                            {note.subject ||
                                                categoryLabel(note.category)}
                                        </p>
                                        <p className="mt-1 line-clamp-3 text-muted-foreground">
                                            {note.body}
                                        </p>
                                        <div className="mt-3 flex items-center justify-between gap-2">
                                            <span className="text-xs text-muted-foreground">
                                                {dateLabel(
                                                    note.updated_at ??
                                                        note.created_at ??
                                                        note.occurred_at,
                                                )}
                                            </span>
                                            <Button
                                                type="button"
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    handleFilterChange('drafts')
                                                }
                                            >
                                                Show drafts
                                            </Button>
                                        </div>
                                    </div>
                                ))
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    No draft daily notes are waiting.
                                </p>
                            )}
                        </CardContent>
                    </Card>

                    {legacyProgressNotes.length > 0 ? (
                        <Card>
                            <CardHeader>
                                <CardTitle className="text-base">
                                    Historical Progress Notes
                                </CardTitle>
                            </CardHeader>
                            <CardContent className="space-y-3">
                                {legacyProgressNotes.slice(0, 5).map((note) => (
                                    <div
                                        key={note.id}
                                        className="rounded-lg border p-3 text-sm"
                                    >
                                        <div className="flex items-center justify-between gap-2">
                                            <p className="font-medium">
                                                {note.goal?.title ??
                                                    'Progress note'}
                                            </p>
                                            <span className="text-xs text-muted-foreground">
                                                {dateLabel(note.created_at)}
                                            </span>
                                        </div>
                                        <p className="mt-1 line-clamp-3 text-muted-foreground">
                                            {note.body ?? note.content}
                                        </p>
                                    </div>
                                ))}
                            </CardContent>
                        </Card>
                    ) : null}
                </aside>
            </div>
        </div>
    );
}
