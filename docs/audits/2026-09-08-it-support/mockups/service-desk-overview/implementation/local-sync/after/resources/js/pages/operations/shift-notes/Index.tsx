import { ListCaption } from '@/components/lists';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderFilterSelect,
    PageHeaderGlassButton,
    PageHeaderMeterBig,
    PageHeaderMeterBlock,
    PageHeaderMeterCaption,
    PageHeaderPrimaryButton,
    PageHeaderRail,
    type PageHeaderRailItem,
    PageHeaderSearch,
    PageHeaderStatusChip,
    PageHeaderViewToggle,
    PageLayout,
} from '@/components/page';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Building2,
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
    Users,
} from 'lucide-react';
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
    NOTE_TYPES,
    type NoteType,
    type ShiftNote,
    type StatusTab,
    TYPE_META,
    type ViewMode,
    clientName,
    matchesTab,
    noteDate,
    ymd,
} from './components/shared';

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

    const shiftWeek = (days: number) => {
        const next = new Date(weekStartDate);
        next.setDate(next.getDate() + days);
        goWeek(next);
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

    /* ---------------- Event Horizon header ---------------- */

    const weekLabel = weekStartDate.toLocaleDateString('en-NZ', {
        day: 'numeric',
        month: 'short',
    });
    const weekEndLabel = new Date(
        weekStartDate.getTime() + 6 * 86400000,
    ).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' });

    const railItems: PageHeaderRailItem<StatusTab>[] = [
        { key: 'all', label: 'All notes', icon: Layers, count: tabCounts.all },
        {
            key: 'flagged',
            label: 'Flagged',
            icon: Flag,
            count: tabCounts.flagged,
            alert: true,
        },
        {
            key: 'awaiting',
            label: 'Awaiting review',
            icon: Clock,
            count: tabCounts.awaiting,
        },
        {
            key: 'reviewed',
            label: 'Reviewed',
            icon: CheckCircle2,
            count: tabCounts.reviewed,
        },
    ];

    const currentViewLabel =
        railItems.find((v) => v.key === tab)?.label ?? 'All notes';

    const titleChip =
        heroCounts.flagged > 0 ? (
            <PageHeaderStatusChip variant="critical">
                {heroCounts.flagged} flagged
            </PageHeaderStatusChip>
        ) : heroCounts.awaiting > 0 ? (
            <PageHeaderStatusChip variant="warning">
                {heroCounts.awaiting} awaiting review
            </PageHeaderStatusChip>
        ) : (
            <PageHeaderStatusChip variant="success">
                All reviewed
            </PageHeaderStatusChip>
        );

    const clientOptions = [
        { value: 'all', label: 'All clients' },
        ...catalogue.clients.map((c) => ({
            value: String(c.id),
            label: `${c.first_name} ${c.last_name}`,
        })),
    ];
    const staffOptions = [
        { value: 'all', label: 'All staff' },
        ...catalogue.staff.map((s) => ({
            value: String(s.id),
            label: s.name,
        })),
    ];
    const typeOptions = [
        { value: 'all', label: 'All types' },
        ...NOTE_TYPES.map((t) => ({ value: t, label: TYPE_META[t].label })),
    ];

    const header = (
        <PageHeader
            icon={NotebookPen}
            title="Shift notes"
            titleChip={titleChip}
            subline={`${weekLabel} → ${weekEndLabel} · ${heroCounts.people} ${
                heroCounts.people === 1 ? 'client' : 'clients'
            } · ${heroCounts.houses} ${heroCounts.houses === 1 ? 'house' : 'houses'} · ${heroCounts.staffOnRoster} staff on roster`}
            actions={
                <>
                    <PageHeaderSearch
                        value={search}
                        onChange={setSearch}
                        placeholder="Search notes, clients, staff…"
                    />
                    <PageHeaderGlassButton icon={Download} onClick={onExport}>
                        Export
                    </PageHeaderGlassButton>
                    {can.create ? (
                        <PageHeaderPrimaryButton icon={Plus} onClick={openNew}>
                            Add note
                        </PageHeaderPrimaryButton>
                    ) : null}
                </>
            }
            meters={
                <>
                    <PageHeaderMeterBlock
                        label="Notes this week"
                        ariaLabel="View all notes"
                        onClick={() => setTab('all')}
                    >
                        <PageHeaderMeterBig>
                            {heroCounts.total}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            across {heroCounts.houses}{' '}
                            {heroCounts.houses === 1 ? 'house' : 'houses'}
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Awaiting review"
                        tone={heroCounts.awaiting > 0 ? 'warning' : 'success'}
                        ariaLabel="View notes awaiting review"
                        onClick={() => setTab('awaiting')}
                    >
                        <PageHeaderMeterBig>
                            {heroCounts.awaiting}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            not yet signed off
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Flagged"
                        tone={heroCounts.flagged > 0 ? 'critical' : 'success'}
                        ariaLabel="View flagged notes"
                        onClick={() => setTab('flagged')}
                    >
                        <PageHeaderMeterBig>
                            {heroCounts.flagged}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            need manager attention
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Coverage gaps"
                        tone={heroCounts.gaps > 0 ? 'warning' : 'success'}
                        ariaLabel="View the week rail with coverage gaps"
                        onClick={() =>
                            document
                                .getElementById('shift-notes-rail')
                                ?.scrollIntoView({
                                    behavior: 'smooth',
                                    block: 'start',
                                })
                        }
                    >
                        <PageHeaderMeterBig>
                            {heroCounts.gaps}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            shifts without a note yet
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                    <PageHeaderMeterBlock
                        label="Incidents"
                        tone={heroCounts.incidents > 0 ? 'warning' : 'success'}
                        ariaLabel="Filter to incident notes"
                        onClick={() =>
                            setFilters((prev) => ({
                                ...prev,
                                type: 'incident' as NoteType,
                            }))
                        }
                    >
                        <PageHeaderMeterBig>
                            {heroCounts.incidents}
                        </PageHeaderMeterBig>
                        <PageHeaderMeterCaption>
                            incident notes this week
                        </PageHeaderMeterCaption>
                    </PageHeaderMeterBlock>
                </>
            }
            filters={
                <>
                    <PageHeaderFilterButton
                        icon={ChevronLeft}
                        aria-label="Previous week"
                        onClick={() => shiftWeek(-7)}
                    />
                    <PageHeaderFilterButton
                        icon={CalendarDays}
                        onClick={() => goWeek(new Date())}
                    >
                        {weekLabel} → {weekEndLabel}
                    </PageHeaderFilterButton>
                    <PageHeaderFilterButton
                        icon={ChevronRight}
                        aria-label="Next week"
                        onClick={() => shiftWeek(7)}
                    />
                    <PageHeaderFilterSelect
                        icon={Users}
                        label="All clients"
                        value={
                            filters.client != null
                                ? String(filters.client)
                                : 'all'
                        }
                        options={clientOptions}
                        onChange={(v) =>
                            setFilters((prev) => ({
                                ...prev,
                                client: v === 'all' ? null : Number(v),
                            }))
                        }
                    />
                    <PageHeaderFilterSelect
                        icon={Building2}
                        label="All staff"
                        value={
                            filters.staff != null
                                ? String(filters.staff)
                                : 'all'
                        }
                        options={staffOptions}
                        onChange={(v) =>
                            setFilters((prev) => ({
                                ...prev,
                                staff: v === 'all' ? null : Number(v),
                            }))
                        }
                    />
                    <PageHeaderFilterSelect
                        icon={AlertTriangle}
                        label="All types"
                        value={filters.type ?? 'all'}
                        options={typeOptions}
                        onChange={(v) =>
                            setFilters((prev) => ({
                                ...prev,
                                type: v === 'all' ? null : (v as NoteType),
                            }))
                        }
                    />
                    <PageHeaderViewToggle
                        value={view}
                        onChange={setView}
                        ariaLabel="Layout"
                        options={[
                            {
                                value: 'cards',
                                label: 'Cards',
                                icon: LayoutGrid,
                            },
                            { value: 'list', label: 'List', icon: List },
                        ]}
                    />
                </>
            }
            rail={
                <PageHeaderRail
                    items={railItems}
                    value={tab}
                    onSelect={setTab}
                    ariaLabel="Note views"
                />
            }
        />
    );

    return (
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Operations', href: '/operations' },
                { title: 'Shift notes', href: '/operations/shift-notes' },
            ]}
        >
            <Head title="Shift notes" />

            <PageLayout hero={header}>
                <div className="space-y-4">
                    <ListCaption
                        title={currentViewLabel}
                        caption={`${filtered.length} of ${notes.length} shown`}
                        right={
                            hasFilters ? (
                                <GuardrailButton
                                    variant="outline"
                                    size="sm"
                                    onClick={clearFilters}
                                    className="text-xs text-muted-foreground"
                                >
                                    Clear filters
                                </GuardrailButton>
                            ) : undefined
                        }
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
                        <div id="shift-notes-rail" className="min-w-0">
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
                </div>
            </PageLayout>

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
