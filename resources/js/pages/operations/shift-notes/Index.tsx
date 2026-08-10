import PageShell from '@/components/page-shell';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { Button as GuardrailButton } from '@/components/ui/button';
import {
    CardsView,
    EmptyState,
    type NoteHandlers,
} from './components/cards-view';
import { ListView } from './components/list-view';
import { NoteDetailDialog } from './components/note-detail-dialog';
import { NoteRail, computeCoverageGaps } from './components/note-rail';
import { NoteWizard, type WizardInitial } from './components/note-wizard';
import {
    type Catalogue,
    type CatalogueShift,
    type Filters,
    type ShiftNote,
    type StatusTab,
    type ViewMode,
    clientName,
    matchesTab,
    noteDate,
    ymd,
} from './components/shared';
import { ShiftNotesHero } from './components/shift-notes-hero';
import { Toolbar } from './components/toolbar';

type Props = {
    notes: ShiftNote[];
    weekStart: string;
    weekEnd: string;
    filters: { week: string };
    catalogue: Catalogue;
    can: { create: boolean; manage: boolean };
    currentUser: { id: number; name: string; is_manager: boolean };
};

const EMPTY_FILTERS: Filters = { client: null, staff: null, type: null };

export default function ShiftNotesIndex({
    notes = [],
    weekStart,
    catalogue,
    can = { create: false, manage: false },
    currentUser = { id: 0, name: '', is_manager: false },
}: Props) {
    const weekStartDate = useMemo(
        () => new Date(`${weekStart}T00:00:00`),
        [weekStart],
    );

    const [search, setSearch] = useState('');
    const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);
    const [tab, setTab] = useState<StatusTab>('all');
    const [view, setView] = useState<ViewMode>('cards');
    const [selectedDay, setSelectedDay] = useState<string | null>(null);
    const [wizardOpen, setWizardOpen] = useState(false);
    const [wizardInitial, setWizardInitial] = useState<WizardInitial | null>(
        null,
    );
    const [detailId, setDetailId] = useState<number | null>(null);

    const gaps = useMemo(
        () => computeCoverageGaps(catalogue.shifts, notes, weekStartDate),
        [catalogue.shifts, notes, weekStartDate],
    );

    const heroCounts = useMemo(() => {
        const reviewed = notes.filter((n) => n.reviewed_at).length;
        const awaiting = notes.length - reviewed;
        return {
            total: notes.length,
            reviewed,
            flagged: notes.filter((n) => n.is_flagged).length,
            gaps: gaps.length,
            awaiting,
            incidents: notes.filter((n) => n.type === 'incident').length,
            people: new Set(notes.map((n) => n.client?.id).filter(Boolean))
                .size,
            houses:
                new Set(notes.map((n) => n.site?.id).filter(Boolean)).size ||
                catalogue.sites.length,
            staffOnRoster: catalogue.staff.length,
        };
    }, [notes, gaps, catalogue.sites.length, catalogue.staff.length]);

    const tabCounts = useMemo(
        () => ({
            all: notes.length,
            flagged: notes.filter((n) => n.is_flagged).length,
            awaiting: notes.filter((n) => !n.reviewed_at).length,
            reviewed: notes.filter((n) => n.reviewed_at).length,
        }),
        [notes],
    );

    const baseFiltered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return notes.filter((n) => {
            if (filters.client != null && n.client?.id !== filters.client)
                return false;
            if (filters.staff != null && n.user?.id !== filters.staff)
                return false;
            if (filters.type != null && n.type !== filters.type) return false;
            if (selectedDay && ymd(noteDate(n)) !== selectedDay) return false;
            if (q) {
                const hay = [
                    n.body,
                    clientName(n.client),
                    n.user?.name,
                    n.site?.name,
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [notes, filters, search, selectedDay]);

    const filtered = useMemo(
        () => baseFiltered.filter((n) => matchesTab(n, tab)),
        [baseFiltered, tab],
    );

    const detailNote =
        detailId != null
            ? (notes.find((n) => n.id === detailId) ?? null)
            : null;

    const hasFilters =
        search.trim() !== '' ||
        filters.client != null ||
        filters.staff != null ||
        filters.type != null ||
        selectedDay != null ||
        tab !== 'all';

    const clearFilters = () => {
        setSearch('');
        setFilters(EMPTY_FILTERS);
        setSelectedDay(null);
        setTab('all');
    };

    const firstName = currentUser?.name?.split(' ')?.[0] ?? 'team';

    // ---- navigation + actions --------------------------------------------
    const goWeek = (week: Date) => {
        const target = ymd(week);
        setSelectedDay(null);
        if (target === weekStart) return;
        router.get(
            '/operations/shift-notes',
            { week: target },
            { preserveState: true, preserveScroll: true },
        );
    };

    const openNew = () => {
        setWizardInitial(null);
        setWizardOpen(true);
    };

    const openForShift = (shift: CatalogueShift) => {
        setWizardInitial({ client_id: shift.client_id, shift_id: shift.id });
        setWizardOpen(true);
    };

    const onExport = () => {
        window.location.href = `/operations/shift-notes/export?week=${weekStart}`;
    };

    const flagNote = (note: ShiftNote) =>
        router.patch(
            `/operations/shift-notes/${note.id}/flag`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () =>
                    toast.success(
                        note.is_flagged ? 'Flag removed' : 'Note flagged',
                    ),
            },
        );

    const reviewNote = (note: ShiftNote) =>
        router.patch(
            `/operations/shift-notes/${note.id}/review`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => toast.success('Note marked as reviewed'),
            },
        );

    const handlers: NoteHandlers = {
        onOpen: (n) => setDetailId(n.id),
        onFlag: flagNote,
        onReview: reviewNote,
    };

    return (
        <AppLayout>
            <Head title="Shift Notes" />
            <PageShell>
                <ShiftNotesHero
                    firstName={firstName}
                    weekStart={weekStartDate}
                    counts={heroCounts}
                    search={search}
                    onSearch={setSearch}
                    filters={filters}
                    onFilters={setFilters}
                    catalogue={catalogue}
                    onAddNote={openNew}
                    onWeekChange={goWeek}
                    onExport={onExport}
                    canCreate={can.create}
                />

                <div className="space-y-4">
                    <Toolbar
                        tab={tab}
                        onTab={setTab}
                        view={view}
                        onView={setView}
                        counts={tabCounts}
                        shown={filtered.length}
                        total={notes.length}
                        hasFilters={hasFilters}
                        onClearFilters={clearFilters}
                    />

                    {selectedDay ? (
                        <div className="flex items-center gap-3 text-[13px]">
                            <span className="font-semibold">
                                Showing{' '}
                                {new Date(
                                    `${selectedDay}T12:00:00`,
                                ).toLocaleDateString('en-NZ', {
                                    weekday: 'long',
                                    day: 'numeric',
                                    month: 'long',
                                })}
                            </span>
                            <GuardrailButton
                                unstyled
                                type="button"
                                onClick={() => setSelectedDay(null)}
                                className="font-medium text-primary hover:underline"
                            >
                                ← Back to whole week
                            </GuardrailButton>
                        </div>
                    ) : null}

                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px]">
                        <main className="min-w-0">
                            {filtered.length === 0 ? (
                                <EmptyState
                                    filtersActive={hasFilters}
                                    canCreate={can.create}
                                    onClearFilters={clearFilters}
                                    onAddNote={openNew}
                                />
                            ) : view === 'cards' ? (
                                <CardsView notes={filtered} {...handlers} />
                            ) : (
                                <ListView notes={filtered} {...handlers} />
                            )}
                        </main>
                        <NoteRail
                            weekNotes={notes}
                            gaps={gaps}
                            weekStart={weekStartDate}
                            selectedDay={selectedDay}
                            onSelectDay={setSelectedDay}
                            onOpen={(n) => setDetailId(n.id)}
                            onAddNoteForShift={openForShift}
                        />
                    </div>
                </div>
            </PageShell>

            <NoteDetailDialog
                note={detailNote}
                open={detailId != null}
                onOpenChange={(open) => !open && setDetailId(null)}
                currentUser={currentUser}
                onFlag={flagNote}
                onReview={reviewNote}
            />

            {wizardOpen ? (
                <NoteWizard
                    open={wizardOpen}
                    onOpenChange={(open) => {
                        if (!open) {
                            setWizardOpen(false);
                            setWizardInitial(null);
                        }
                    }}
                    initial={wizardInitial}
                    catalogue={catalogue}
                    onCreated={(week) => {
                        setTab('all');
                        goWeek(week);
                    }}
                />
            ) : null}
        </AppLayout>
    );
}
