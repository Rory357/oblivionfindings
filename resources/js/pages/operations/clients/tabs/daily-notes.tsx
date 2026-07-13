import {
    DailyNoteEntry,
    type ClientDailyNote,
} from '@/components/daily-note-entry';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { EmptyState } from '@/components/ui/empty-state';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Skeleton } from '@/components/ui/skeleton';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarCheck,
    ClipboardList,
    Eye,
    Flag,
    MessageSquare,
    Search,
    UserRound,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { NOTE_CATEGORIES } from '../dialogs/_note-category-picker';

// Re-export ClientDailyNote from the canonical home so existing call sites
// (`import type { ClientDailyNote } from './daily-notes'`) keep working
// without churn while the component file becomes the source of truth.
export type { ClientDailyNote };

export type DailyNotesSummary = {
    total?: number;
    loaded?: number;
    has_more?: boolean;
    flagged_open?: number;
    drafts?: number;
    communication?: number;
    communication_loaded?: number;
    communication_has_more?: boolean;
    open_follow_ups?: number;
};

type DailyNotesTabProps = {
    clientId: number;
    notes: ClientDailyNote[];
    summary: DailyNotesSummary;
    canReview?: boolean;
    canUpdate?: boolean;
    currentUserId?: number;
    onCreateDaily?: () => void;
    onCreateQuick?: () => void;
    onEditNote?: (note: ClientDailyNote) => void;
    filterPreset?: DailyNotesFilter;
    onFilterChange?: (filter: DailyNotesFilter) => void;
    onShowReviewQueue?: () => void;
    isLoading?: boolean;
};

type DailyNotesFilter = 'all' | 'flagged' | 'follow_up' | 'drafts';

function categoryLabel(value?: string | null) {
    return String(value ?? 'other').replace(/_/g, ' ');
}

function dateLabel(value?: string | null) {
    if (!value) return 'No date';
    return new Intl.DateTimeFormat('en-NZ', {
        day: 'numeric',
        month: 'short',
        hour: 'numeric',
        minute: '2-digit',
    }).format(new Date(value));
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

/** Note-type buckets — progress notes & handovers are first-class note types
 * on this feed since the standalone progress-note page was retired. */
type NoteTypeFilter = 'all' | 'daily' | 'progress' | 'handover';

const NOTE_TYPE_BUCKET: Record<string, Exclude<NoteTypeFilter, 'all'>> = {
    daily_note: 'daily',
    quick: 'daily',
    note: 'daily',
    progress_note: 'progress',
    handover: 'handover',
};

function noteTypeBucket(type?: string | null): Exclude<NoteTypeFilter, 'all'> {
    return NOTE_TYPE_BUCKET[type ?? 'daily_note'] ?? 'daily';
}

function noteTypeFromQuery(): NoteTypeFilter {
    if (typeof window === 'undefined') return 'all';
    const value = new URLSearchParams(window.location.search).get('type');
    if (value === 'progress' || value === 'handover' || value === 'daily') {
        return value;
    }
    return 'all';
}

export function DailyNotesTab({
    clientId,
    notes,
    summary,
    canReview = false,
    canUpdate = false,
    currentUserId,
    onCreateDaily,
    onCreateQuick,
    onEditNote,
    filterPreset,
    onFilterChange,
    onShowReviewQueue,
    isLoading = false,
}: DailyNotesTabProps) {
    const [query, setQuery] = useState('');
    const [filter, setFilter] = useState<DailyNotesFilter>(
        () => filterPreset ?? filterFromQuery(),
    );
    const [noteType, setNoteType] = useState<NoteTypeFilter>(() =>
        noteTypeFromQuery(),
    );
    const [category, setCategory] = useState<string>('all');
    const [mineOnly, setMineOnly] = useState(false);
    const [familyVisibleOnly, setFamilyVisibleOnly] = useState(false);
    const [dateFrom, setDateFrom] = useState('');
    const [dateTo, setDateTo] = useState('');

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

    const clearFilters = () => {
        setQuery('');
        setFilter('all');
        setNoteType('all');
        setCategory('all');
        setMineOnly(false);
        setFamilyVisibleOnly(false);
        setDateFrom('');
        setDateTo('');
        onFilterChange?.('all');
    };

    const activeFilterCount = useMemo(() => {
        let count = 0;
        if (query.trim()) count += 1;
        if (filter !== 'all') count += 1;
        if (noteType !== 'all') count += 1;
        if (category !== 'all') count += 1;
        if (mineOnly) count += 1;
        if (familyVisibleOnly) count += 1;
        if (dateFrom) count += 1;
        if (dateTo) count += 1;
        return count;
    }, [
        query,
        filter,
        noteType,
        category,
        mineOnly,
        familyVisibleOnly,
        dateFrom,
        dateTo,
    ]);

    const filteredNotes = useMemo(() => {
        const search = query.trim().toLowerCase();
        const fromTs = dateFrom ? new Date(dateFrom).getTime() : null;
        const toTs = dateTo ? new Date(`${dateTo}T23:59:59`).getTime() : null;

        return notes.filter((note) => {
            if (filter === 'flagged' && !note.is_flagged) return false;
            if (filter === 'drafts' && !note.is_draft) return false;
            if (filter === 'follow_up' && !note.follow_up_action?.trim()) {
                return false;
            }
            if (noteType !== 'all' && noteTypeBucket(note.type) !== noteType) {
                return false;
            }
            if (category !== 'all' && (note.category ?? 'other') !== category) {
                return false;
            }
            if (mineOnly) {
                const noteAuthorId = note.author?.id ?? note.user_id ?? null;
                if (!currentUserId || noteAuthorId !== currentUserId) {
                    return false;
                }
            }
            if (familyVisibleOnly && note.visibility !== 'portal') {
                return false;
            }
            if (fromTs || toTs) {
                const when = note.occurred_at ?? note.created_at;
                if (!when) return false;
                const whenTs = new Date(when).getTime();
                if (fromTs && whenTs < fromTs) return false;
                if (toTs && whenTs > toTs) return false;
            }
            if (!search) return true;

            return [
                note.subject,
                note.body,
                note.category,
                note.author?.name,
                note.flagged_reason,
                note.contact_person,
            ]
                .filter(Boolean)
                .join(' ')
                .toLowerCase()
                .includes(search);
        });
    }, [
        category,
        currentUserId,
        dateFrom,
        dateTo,
        familyVisibleOnly,
        filter,
        mineOnly,
        noteType,
        notes,
        query,
    ]);

    const noteTypeCounts = useMemo(() => {
        const counts: Record<Exclude<NoteTypeFilter, 'all'>, number> = {
            daily: 0,
            progress: 0,
            handover: 0,
        };
        notes.forEach((note) => {
            counts[noteTypeBucket(note.type)] += 1;
        });
        return counts;
    }, [notes]);

    const reviewQueue = notes.filter(
        (note) => !note.is_draft && note.is_flagged && !note.reviewed_at,
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
                        // eslint-disable-next-line no-restricted-syntax -- MiniStat tile per the profile pattern language, not a Card
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
                                <span className={`rounded-lg p-2 ${stat.tone}`}>
                                    <Icon className="h-5 w-5" />
                                </span>
                            </div>
                        </div>
                    );
                })}
            </div>

            {summary.has_more ? (
                <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
                    Showing the latest {summary.loaded ?? notes.length} of{' '}
                    {summary.total ?? notes.length} daily notes.
                </p>
            ) : null}

            <div className="space-y-3">
                <div className="flex flex-col gap-3 md:flex-row md:items-center md:justify-between">
                    <div className="flex flex-1 flex-col gap-2 sm:flex-row">
                        <div className="relative max-w-xl flex-1">
                            <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                            <Input
                                value={query}
                                onChange={(event) =>
                                    setQuery(event.target.value)
                                }
                                placeholder="Search notes"
                                className="min-h-11 pl-9"
                                data-test="client-daily-notes-search"
                            />
                        </div>
                        <Select
                            value={filter}
                            onValueChange={handleFilterChange}
                        >
                            <SelectTrigger
                                className="min-h-11 sm:w-44"
                                data-test="client-daily-notes-filter"
                            >
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
                        <Select value={category} onValueChange={setCategory}>
                            <SelectTrigger
                                className="min-h-11 sm:w-44"
                                data-test="client-daily-notes-category-filter"
                            >
                                <SelectValue placeholder="All categories" />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="all">
                                    All categories
                                </SelectItem>
                                {NOTE_CATEGORIES.map((cat) => (
                                    <SelectItem key={cat.key} value={cat.key}>
                                        {cat.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>

                    {onCreateQuick || onCreateDaily ? (
                        <div className="flex flex-wrap gap-2">
                            {onCreateQuick ? (
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
                            ) : null}
                            {onCreateDaily ? (
                                <Button
                                    type="button"
                                    onClick={onCreateDaily}
                                    className="min-h-11"
                                    data-test="client-daily-notes-daily-note-button"
                                >
                                    <ClipboardList className="mr-2 h-4 w-4" />
                                    Daily Note
                                </Button>
                            ) : null}
                        </div>
                    ) : null}
                </div>

                {/* Note-type filter — progress notes & handovers are first-class
                    here since the standalone progress-note page was retired. */}
                <div className="flex flex-wrap items-center gap-1.5">
                    {(
                        [
                            ['all', 'All', notes.length],
                            ['daily', 'Daily notes', noteTypeCounts.daily],
                            [
                                'progress',
                                'Progress notes',
                                noteTypeCounts.progress,
                            ],
                            ['handover', 'Handovers', noteTypeCounts.handover],
                        ] as [NoteTypeFilter, string, number][]
                    ).map(([key, label, count]) => {
                        const active = noteType === key;
                        return (
                            // eslint-disable-next-line no-restricted-syntax -- filter chip pill, not a standard button
                            <button
                                key={key}
                                type="button"
                                aria-pressed={active}
                                onClick={() => setNoteType(key)}
                                data-test={`client-daily-notes-type-${key}`}
                                className={`inline-flex shrink-0 items-center gap-1.5 rounded-full px-3 py-1.5 text-sm font-medium transition-colors ${
                                    active
                                        ? 'bg-primary text-primary-foreground'
                                        : 'bg-muted text-muted-foreground hover:text-foreground'
                                }`}
                            >
                                {label}
                                {count > 0 ? (
                                    <span
                                        className={`rounded-full px-1.5 text-[10px] font-bold ${
                                            active
                                                ? 'bg-primary-foreground/20'
                                                : 'bg-card'
                                        }`}
                                    >
                                        {count}
                                    </span>
                                ) : null}
                            </button>
                        );
                    })}
                </div>

                <div className="flex flex-wrap items-end gap-3">
                    <div className="flex flex-col gap-1">
                        <Label
                            htmlFor="client-daily-notes-date-from"
                            className="text-xs text-muted-foreground"
                        >
                            From
                        </Label>
                        <Input
                            id="client-daily-notes-date-from"
                            type="date"
                            value={dateFrom}
                            onChange={(event) =>
                                setDateFrom(event.target.value)
                            }
                            className="h-10 w-40"
                            data-test="client-daily-notes-date-from"
                        />
                    </div>
                    <div className="flex flex-col gap-1">
                        <Label
                            htmlFor="client-daily-notes-date-to"
                            className="text-xs text-muted-foreground"
                        >
                            To
                        </Label>
                        <Input
                            id="client-daily-notes-date-to"
                            type="date"
                            value={dateTo}
                            onChange={(event) => setDateTo(event.target.value)}
                            className="h-10 w-40"
                            data-test="client-daily-notes-date-to"
                        />
                    </div>
                    <Button
                        type="button"
                        size="sm"
                        variant={mineOnly ? 'default' : 'outline'}
                        onClick={() => setMineOnly((value) => !value)}
                        disabled={!currentUserId}
                        className="h-10"
                        data-test="client-daily-notes-mine-toggle"
                    >
                        <UserRound className="mr-1.5 h-3.5 w-3.5" />
                        Mine
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant={familyVisibleOnly ? 'default' : 'outline'}
                        onClick={() => setFamilyVisibleOnly((value) => !value)}
                        className="h-10"
                        data-test="client-daily-notes-family-visible-toggle"
                    >
                        <Eye className="mr-1.5 h-3.5 w-3.5" />
                        Family visible
                    </Button>
                    {activeFilterCount > 0 ? (
                        <Button
                            type="button"
                            size="sm"
                            variant="ghost"
                            onClick={clearFilters}
                            className="h-10"
                            data-test="client-daily-notes-clear-filters"
                        >
                            Clear filters ({activeFilterCount})
                        </Button>
                    ) : null}
                </div>
            </div>

            <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_22rem]">
                <div className="space-y-3">
                    {filteredNotes.length > 0 ? (
                        filteredNotes.map((note) => (
                            <DailyNoteEntry
                                key={note.id}
                                note={note}
                                canReview={canReview}
                                canUpdate={canUpdate}
                                onMarkReviewed={markReviewed}
                                onClearFlag={clearFlag}
                                onEdit={onEditNote}
                            />
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
                                            {(note.can?.review ?? canReview) ? (
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
                                            {(note.can?.update ?? canUpdate) &&
                                            onEditNote ? (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        onEditNote(note)
                                                    }
                                                >
                                                    Resume draft
                                                </Button>
                                            ) : (
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        handleFilterChange(
                                                            'drafts',
                                                        )
                                                    }
                                                >
                                                    Show drafts
                                                </Button>
                                            )}
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
                </aside>
            </div>
        </div>
    );
}
