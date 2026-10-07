import {
    PageHeader,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderViewToggle,
} from '@/components/page';
import PageShell from '@/components/page-shell';
import {
    EntityFilter,
    WeekPicker,
    addDaysWP,
    weekNumberISO,
} from '@/components/rostering';
import { ErrorState } from '@/components/ui/error-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { LoadingState } from '@/components/ui/loading-state';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, formatDateTimeInZone } from '@/lib/datetime';
import { Head } from '@inertiajs/react';
import {
    CalendarDays,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock,
    Download,
    Flag,
    Layers,
    LayoutGrid,
    List,
    NotebookPen,
    Plus,
    Search,
    X,
} from 'lucide-react';
import { useMemo, useRef, useState } from 'react';
import {
    CardsView,
    EmptyState,
    type NoteHandlers,
} from './components/cards-view';
import { ListView } from './components/list-view';
import { NoteActionDialog } from './components/note-action-dialog';
import { NoteDetailDialog } from './components/note-detail-dialog';
import {
    type NoteEvidence,
    type NoteFilters,
    type NotePagination,
    type NoteSummary,
    noteExportUrl,
} from './components/note-query';
import { NoteRail, computeCoverageGaps } from './components/note-rail';
import { NoteWizard, type WizardInitial } from './components/note-wizard';
import {
    type Catalogue,
    type CatalogueShift,
    NOTE_TYPES,
    type ShiftNote,
    type StatusTab,
    TYPE_META,
    type ViewMode,
    clientName,
    ymd,
} from './components/shared';
import { useNoteFilters } from './components/use-note-filters';

type Props = {
    notes: ShiftNote[];
    weekStart: string;
    weekEnd: string;
    filters: NoteFilters;
    summary: NoteSummary;
    pagination: NotePagination;
    evidence: NoteEvidence;
    catalogue: Catalogue;
    can: { create: boolean; manage: boolean; flag: boolean; review: boolean };
    currentUser: { id: number; name: string; is_manager: boolean };
};
export default function ShiftNotesIndex({
    notes = [],
    weekStart,
    weekEnd,
    filters: loadedFilters,
    summary,
    pagination,
    evidence,
    catalogue,
    can,
    currentUser,
}: Props) {
    const query = useNoteFilters(loadedFilters);
    const filters = query.draft;
    const weekStartDate = useMemo(
        () => new Date(`${weekStart}T12:00:00`),
        [weekStart],
    );
    const [view, setView] = useState<ViewMode>('cards');
    const [pickerOpen, setPickerOpen] = useState(false);
    const weekButton = useRef<HTMLButtonElement>(null);
    const [wizardOpen, setWizardOpen] = useState(false);
    const [wizardInitial, setWizardInitial] = useState<WizardInitial | null>(
        null,
    );
    const [editNote, setEditNote] = useState<ShiftNote | null>(null);
    const [noteAction, setNoteAction] = useState<{
        note: ShiftNote;
        action: 'flag' | 'review';
    } | null>(null);
    const [detailId, setDetailId] = useState<number | null>(null);
    const detailNote = notes.find((note) => note.id === detailId) ?? null;
    const selectedDay =
        filters.date_from === filters.date_to ? filters.date_from : null;
    const changed = JSON.stringify(filters) !== JSON.stringify(loadedFilters);
    const hasFilters = !!(
        filters.q ||
        filters.type ||
        filters.client_id ||
        filters.author_id ||
        filters.site_id ||
        filters.date_from ||
        filters.date_to ||
        filters.flagged ||
        filters.status !== 'all'
    );
    const readNotice = query.error
        ? 'These counts belong to the last loaded selection. Your new choices are retained.'
        : changed
          ? 'Search choices changed. Use Search to update the records and counts.'
          : null;
    const rangeLabel =
        pagination.total === 0
            ? 'No matching notes'
            : `Showing ${pagination.from}–${pagination.to} of ${pagination.total} matching notes · Page ${pagination.current_page} of ${pagination.last_page}`;
    const goWeek = (date: Date) =>
        query.change({ week: ymd(date), date_from: null, date_to: null });
    const selectDay = (date: string | null) =>
        query.change({ date_from: date, date_to: date });
    const openNew = () => {
        setEditNote(null);
        setWizardInitial(null);
        setWizardOpen(true);
    };
    const openForShift = (shift: CatalogueShift) => {
        setEditNote(null);
        setWizardInitial({ client_id: shift.client_id, shift_id: shift.id });
        setWizardOpen(true);
    };
    const gaps = useMemo(
        () =>
            computeCoverageGaps(
                catalogue.shifts,
                notes,
                weekStartDate,
                catalogue.note_shift_ids,
                evidence.timezone,
            ),
        [
            catalogue.shifts,
            catalogue.note_shift_ids,
            notes,
            weekStartDate,
            evidence.timezone,
        ],
    );
    const flagNote = (note: ShiftNote) => {
        if (!can.flag || !note.can_flag) return;
        setDetailId(null);
        setNoteAction({ note, action: 'flag' });
    };
    const reviewNote = (note: ShiftNote) => {
        if (!can.review || !note.can_review || note.reviewed_at) return;
        setDetailId(null);
        setNoteAction({ note, action: 'review' });
    };
    const handlers: NoteHandlers = {
        onOpen: (note) => setDetailId(note.id),
        onFlag: flagNote,
        onReview: reviewNote,
        timeZone: evidence.timezone,
        canFlag: can.flag,
        canReview: can.review,
    };
    const rails: PageHeaderRailItem<StatusTab>[] = [
        { key: 'all', label: 'All notes', icon: Layers, count: summary.total },
        {
            key: 'flagged',
            label: 'Flagged',
            icon: Flag,
            count: summary.flagged,
        },
        {
            key: 'awaiting',
            label: 'Awaiting review',
            icon: Clock,
            count: summary.awaiting,
        },
        {
            key: 'reviewed',
            label: 'Reviewed',
            icon: CheckCircle2,
            count: summary.reviewed,
        },
    ];
    const meters: [StatusTab, string, number, string][] = [
        [
            'all',
            'Recorded notes',
            summary.total,
            'Matching filters, across every page',
        ],
        ['awaiting', 'Awaiting review', summary.awaiting, 'No review recorded'],
        ['flagged', 'Flagged', summary.flagged, 'Recorded flags for attention'],
        ['reviewed', 'Reviewed', summary.reviewed, 'Recorded reviews'],
    ];
    const options = (
        items: { id: number; name: string }[],
        id: number | null,
        label: string,
    ) =>
        id !== null && !items.some((item) => item.id === id)
            ? [...items, { id, name: `Selected ${label} unavailable` }]
            : items;
    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Shift notes', href: '/operations/shift-notes' },
            ]}
        >
            <Head title="Shift notes" />
            <PageShell>
                <PageHeader
                    variant="index"
                    frontline
                    icon={NotebookPen}
                    title="Shift notes"
                    subline={`${formatDateOnly(weekStart)} → ${formatDateOnly(weekEnd)} · ${evidence.timezone} · Read and document support for each shift`}
                    actions={
                        <>
                            <form
                                onSubmit={(event) => {
                                    event.preventDefault();
                                    query.change();
                                }}
                                className="w-full min-w-0 basis-full sm:w-auto sm:flex-1 sm:basis-auto"
                            >
                                <fieldset
                                    disabled={query.loading}
                                    className="flex min-w-0 flex-wrap gap-2"
                                >
                                    <PageHeaderSearch
                                        value={filters.q}
                                        onChange={query.editSearch}
                                        placeholder="Search notes, people or staff"
                                        className="min-w-0"
                                    />
                                    <PageHeaderGlassButton
                                        type="submit"
                                        icon={Search}
                                    >
                                        Search
                                    </PageHeaderGlassButton>
                                </fieldset>
                            </form>
                            <PageHeaderGlassButton
                                icon={Download}
                                disabled={
                                    query.loading || !!query.error || changed
                                }
                                onClick={() => {
                                    window.location.href =
                                        noteExportUrl(loadedFilters);
                                }}
                            >
                                Export matching notes
                            </PageHeaderGlassButton>
                            {can.create && (
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={openNew}
                                    disabled={query.loading}
                                >
                                    Add note
                                </PageHeaderPrimaryButton>
                            )}
                        </>
                    }
                    meters={
                        <div className="grid w-full min-w-0 grid-cols-2 gap-2 xl:grid-cols-4">
                            {meters.map(([key, label, value, caption]) => (
                                <PageHeaderMeterBlock
                                    key={key}
                                    label={label}
                                    ariaLabel={`View ${label.toLowerCase()}`}
                                    onClick={() => {
                                        if (!query.loading)
                                            query.change({ status: key });
                                    }}
                                >
                                    <PageHeaderMeterBig>
                                        {value}
                                    </PageHeaderMeterBig>
                                    <PageHeaderMeterCaption>
                                        {caption}
                                    </PageHeaderMeterCaption>
                                </PageHeaderMeterBlock>
                            ))}
                        </div>
                    }
                    filters={
                        <fieldset
                            disabled={query.loading}
                            className="flex w-full min-w-0 flex-wrap items-center gap-3"
                        >
                            <PageHeaderGlassButton
                                icon={ChevronLeft}
                                aria-label="Previous week"
                                onClick={() =>
                                    goWeek(addDaysWP(weekStartDate, -7))
                                }
                            >
                                Previous
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                ref={weekButton}
                                icon={CalendarDays}
                                aria-haspopup="dialog"
                                aria-expanded={pickerOpen}
                                onClick={() => setPickerOpen(!pickerOpen)}
                            >
                                Week {weekNumberISO(weekStartDate)} · Choose
                                week
                            </PageHeaderGlassButton>
                            <PageHeaderGlassButton
                                icon={ChevronRight}
                                aria-label="Next week"
                                onClick={() =>
                                    goWeek(addDaysWP(weekStartDate, 7))
                                }
                            >
                                Next
                            </PageHeaderGlassButton>
                            <EntityFilter
                                onDark
                                label="Client"
                                allLabel="All clients"
                                items={options(
                                    catalogue.clients.map((c) => ({
                                        id: c.id,
                                        name: clientName(c),
                                    })),
                                    filters.client_id,
                                    'client',
                                )}
                                value={filters.client_id}
                                onChange={(client_id) =>
                                    query.change({ client_id })
                                }
                            />
                            <EntityFilter
                                onDark
                                label="Author"
                                allLabel="All authors"
                                items={options(
                                    catalogue.staff,
                                    filters.author_id,
                                    'author',
                                )}
                                value={filters.author_id}
                                onChange={(author_id) =>
                                    query.change({ author_id })
                                }
                            />
                            <EntityFilter
                                onDark
                                label="Site"
                                allLabel="All sites"
                                items={options(
                                    catalogue.sites,
                                    filters.site_id,
                                    'site',
                                )}
                                value={filters.site_id}
                                onChange={(site_id) =>
                                    query.change({ site_id })
                                }
                            />
                            <PageHeaderFilterSelect
                                label="Note type"
                                value={filters.type ?? 'all'}
                                options={[
                                    { value: 'all', label: 'All types' },
                                    ...NOTE_TYPES.map((type) => ({
                                        value: type,
                                        label: TYPE_META[type].label,
                                    })),
                                ]}
                                onChange={(type) =>
                                    query.change({
                                        type: type === 'all' ? null : type,
                                    })
                                }
                            />
                            <PageHeaderViewToggle
                                value={view}
                                onChange={setView}
                                ariaLabel="Note display"
                                options={[
                                    {
                                        value: 'cards',
                                        label: 'Cards',
                                        icon: LayoutGrid,
                                    },
                                    {
                                        value: 'list',
                                        label: 'List',
                                        icon: List,
                                    },
                                ]}
                            />
                            {hasFilters && (
                                <PageHeaderGlassButton
                                    icon={X}
                                    onClick={query.clear}
                                >
                                    Clear filters
                                </PageHeaderGlassButton>
                            )}
                            {(filters.date_from || filters.date_to) && (
                                <PageHeaderGlassButton
                                    icon={CalendarDays}
                                    onClick={() => selectDay(null)}
                                >
                                    {formatDateOnly(filters.date_from)} →{' '}
                                    {formatDateOnly(filters.date_to)} · Whole
                                    week
                                </PageHeaderGlassButton>
                            )}
                            <p
                                role="status"
                                className="text-caption w-full text-band-foreground!"
                            >
                                {query.loading
                                    ? 'Updating notes…'
                                    : (readNotice ?? rangeLabel)}{' '}
                                · Counts cover all matching records before the
                                status filter · Updated{' '}
                                {formatDateTimeInZone(
                                    evidence.checked_at,
                                    evidence.timezone,
                                )}
                            </p>
                        </fieldset>
                    }
                    rail={
                        <fieldset
                            disabled={query.loading}
                            className="w-full min-w-0"
                        >
                            <PageHeaderRail
                                items={rails}
                                value={filters.status}
                                onSelect={(status) => query.change({ status })}
                                ariaLabel="Note views"
                            />
                        </fieldset>
                    }
                />
                {pickerOpen && (
                    <WeekPicker
                        selectedWeekStart={weekStartDate}
                        anchorRef={weekButton}
                        onSelect={(date) => {
                            goWeek(date);
                            setPickerOpen(false);
                        }}
                        onClose={() => setPickerOpen(false)}
                        showContextMenu={false}
                    />
                )}
                <div className="space-y-4" aria-busy={query.loading}>
                    {query.loading ? (
                        <LoadingState message="Loading matching notes…" />
                    ) : query.error ? (
                        <div role="alert">
                            <ErrorState
                                title="Notes could not be updated"
                                message={query.error}
                                onRetry={query.retry}
                            />
                        </div>
                    ) : (
                        <>
                            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                                <section
                                    aria-label="Records"
                                    className="min-w-0"
                                >
                                    {notes.length === 0 ? (
                                        <EmptyState
                                            filtersActive={hasFilters}
                                            canCreate={can.create}
                                            onClearFilters={query.clear}
                                            onAddNote={openNew}
                                        />
                                    ) : view === 'cards' ? (
                                        <CardsView
                                            notes={notes}
                                            {...handlers}
                                        />
                                    ) : (
                                        <ListView notes={notes} {...handlers} />
                                    )}
                                </section>
                                <NoteRail
                                    weekNotes={notes}
                                    gaps={gaps}
                                    weekStart={weekStartDate}
                                    selectedDay={selectedDay}
                                    onSelectDay={selectDay}
                                    onOpen={handlers.onOpen}
                                    onAddNoteForShift={
                                        can.create ? openForShift : undefined
                                    }
                                    timeZone={evidence.timezone}
                                    awaitingTotal={summary.awaiting}
                                    onAwaiting={() =>
                                        query.change({ status: 'awaiting' })
                                    }
                                    shiftResults={catalogue.shift_results}
                                />
                            </div>
                            <LaravelPagination
                                links={pagination.links}
                                lastPage={pagination.last_page}
                            />
                        </>
                    )}
                </div>
            </PageShell>
            <NoteDetailDialog
                note={detailNote}
                open={detailId !== null}
                onOpenChange={(open) => {
                    if (!open) setDetailId(null);
                }}
                timeZone={evidence.timezone}
                canFlag={can.flag}
                canReview={can.review}
                onEdit={(note) => {
                    if (!note.can_edit) return;
                    setDetailId(null);
                    setEditNote(note);
                    setWizardInitial(null);
                    setWizardOpen(true);
                }}
                onFlag={flagNote}
                onReview={reviewNote}
            />
            {wizardOpen && (
                <NoteWizard
                    open={wizardOpen}
                    onOpenChange={(open) => {
                        if (!open) {
                            setWizardOpen(false);
                            setWizardInitial(null);
                            setEditNote(null);
                        }
                    }}
                    key={editNote?.id ?? 'new'}
                    initial={wizardInitial}
                    editNote={editNote}
                    actorId={currentUser.id}
                    canSave={
                        editNote
                            ? notes.some(
                                  (note) =>
                                      note.id === editNote.id && note.can_edit,
                              )
                            : can.create
                    }
                    timeZone={evidence.timezone}
                    weekStart={weekStart}
                    catalogue={catalogue}
                    onCreated={(week) => {
                        query.change({
                            week: ymd(week),
                            status: 'all',
                            q: '',
                            type: null,
                            client_id: null,
                            author_id: null,
                            site_id: null,
                            date_from: null,
                            date_to: null,
                            flagged: false,
                        });
                    }}
                />
            )}
            {noteAction && (
                <NoteActionDialog
                    key={`${noteAction.action}:${noteAction.note.id}`}
                    {...noteAction}
                    allowed={notes.some(
                        (note) =>
                            note.id === noteAction.note.id &&
                            (noteAction.action === 'flag'
                                ? can.flag && note.can_flag
                                : can.review && note.can_review),
                    )}
                    actorId={currentUser.id}
                    timeZone={evidence.timezone}
                    weekStart={weekStart}
                    onClose={() => setNoteAction(null)}
                />
            )}
        </AppLayout>
    );
}
