import { AddClientDialog } from '@/components/clients/add-client-dialog';
import PageShell from '@/components/page-shell';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import { BoardView } from './components/board-view';
import { CardsView } from './components/cards-view';
import { HandoverDetailDialog } from './components/handover-detail-dialog';
import { HandoverRail } from './components/handover-rail';
import { HandoverWizard } from './components/handover-wizard';
import { HandoversHero } from './components/handovers-hero';
import { ListView } from './components/list-view';
import {
    type Catalogue,
    type Filters,
    type Handover,
    type StatusTab,
    type ViewMode,
    clientName,
    ymd,
} from './components/shared';
import { Toolbar } from './components/toolbar';

type Props = {
    handovers: Handover[];
    weekStart: string;
    weekEnd: string;
    filters: { week: string };
    catalogue: Catalogue;
    can: { create: boolean; manage: boolean };
    currentUser: { id: number; name: string };
};

const EMPTY_FILTERS: Filters = { staff: null, client: null, site: null };

export default function HandoversIndex({
    handovers = [],
    weekStart,
    catalogue,
    can = { create: false, manage: false },
    currentUser,
}: Props) {
    const weekStartDate = useMemo(
        () => new Date(`${weekStart}T00:00:00`),
        [weekStart],
    );

    const [search, setSearch] = useState('');
    const [filters, setFilters] = useState<Filters>(EMPTY_FILTERS);
    const [tab, setTab] = useState<StatusTab>('all');
    const [view, setView] = useState<ViewMode>('cards');
    const [wizardOpen, setWizardOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [detailId, setDetailId] = useState<number | null>(null);
    const [addClientOpen, setAddClientOpen] = useState(false);
    const [pendingClientId, setPendingClientId] = useState<number | null>(null);

    const counts = useMemo(
        () => ({
            total: handovers.length,
            draft: handovers.filter((h) => h.status === 'draft').length,
            submitted: handovers.filter((h) => h.status === 'submitted').length,
            acknowledged: handovers.filter((h) => h.status === 'acknowledged')
                .length,
            openIncoming: handovers.filter((h) => h.incoming_staff == null)
                .length,
            incidents: handovers.reduce(
                (sum, h) => sum + (h.incidents_to_note?.length ?? 0),
                0,
            ),
        }),
        [handovers],
    );

    const baseFiltered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return handovers.filter((h) => {
            if (
                filters.staff != null &&
                h.outgoing_staff?.id !== filters.staff &&
                h.incoming_staff?.id !== filters.staff &&
                h.acknowledger?.id !== filters.staff
            )
                return false;
            if (filters.client != null && h.client?.id !== filters.client)
                return false;
            if (filters.site != null && h.site?.id !== filters.site)
                return false;
            if (q) {
                const hay = [
                    h.handover_notes,
                    clientName(h.client),
                    h.outgoing_staff?.name,
                    h.incoming_staff?.name,
                    h.site?.name,
                    h.client_mood,
                    ...(h.medications_due ?? []),
                    ...(h.incidents_to_note ?? []),
                    ...(h.follow_up_items ?? []),
                    ...(h.tasks_pending ?? []),
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
    }, [handovers, filters, search]);

    const filtered = useMemo(
        () =>
            tab === 'all'
                ? baseFiltered
                : baseFiltered.filter((h) => h.status === tab),
        [baseFiltered, tab],
    );

    const detailHandover =
        detailId != null
            ? (handovers.find((h) => h.id === detailId) ?? null)
            : null;
    const editingHandover =
        editingId != null
            ? (handovers.find((h) => h.id === editingId) ?? null)
            : null;

    const hasFilters =
        search.trim() !== '' ||
        filters.staff != null ||
        filters.client != null ||
        filters.site != null;

    const firstName = currentUser?.name?.split(' ')?.[0] ?? 'team';

    // ---- navigation + actions --------------------------------------------
    const goWeek = (week: Date) => {
        const target = ymd(week);
        if (target === weekStart) return;
        router.get(
            '/operations/handovers',
            { week: target },
            { preserveState: true, preserveScroll: true },
        );
    };

    const openNew = () => {
        setEditingId(null);
        setPendingClientId(null);
        setWizardOpen(true);
    };

    const openEdit = (h: Handover) => {
        setDetailId(null);
        setEditingId(h.id);
        setWizardOpen(true);
    };

    const closeWizard = () => {
        setWizardOpen(false);
        setEditingId(null);
        setPendingClientId(null);
    };

    const submitHandover = (h: Handover) =>
        router.patch(
            `/operations/handovers/${h.id}/submit`,
            {},
            {
                preserveScroll: true,
                onSuccess: () => toast.success('Draft submitted to incoming worker'),
            },
        );

    const acknowledgeHandover = (h: Handover) =>
        router.patch(
            `/operations/handovers/${h.id}/acknowledge`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success(
                        `Handover for ${clientName(h.client)} acknowledged`,
                    ),
            },
        );

    const handlers = {
        onOpen: (h: Handover) => setDetailId(h.id),
        onSubmit: submitHandover,
        onAcknowledge: acknowledgeHandover,
        onEdit: openEdit,
    };

    return (
        <AppLayout>
            <Head title="Shift Handovers" />
            <PageShell>
                <HandoversHero
                    firstName={firstName}
                    weekStart={weekStartDate}
                    counts={counts}
                    search={search}
                    onSearch={setSearch}
                    filters={filters}
                    onFilters={setFilters}
                    catalogue={catalogue}
                    onNewHandover={openNew}
                    onWeekChange={goWeek}
                    canCreate={can.create}
                />

                <div className="space-y-4">
                    <Toolbar
                        tab={tab}
                        onTab={setTab}
                        view={view}
                        onView={setView}
                        counts={counts}
                        shown={(view === 'board' ? baseFiltered : filtered).length}
                        total={counts.total}
                        hasFilters={hasFilters}
                        onClearFilters={() => {
                            setFilters(EMPTY_FILTERS);
                            setSearch('');
                        }}
                    />

                    {view === 'board' ? (
                        <BoardView handovers={baseFiltered} {...handlers} />
                    ) : (
                        <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px]">
                            <main className="min-w-0">
                                {view === 'cards' ? (
                                    <CardsView
                                        handovers={filtered}
                                        {...handlers}
                                    />
                                ) : (
                                    <ListView
                                        handovers={filtered}
                                        {...handlers}
                                    />
                                )}
                            </main>
                            <HandoverRail
                                handovers={handovers}
                                counts={counts}
                                weekStart={weekStartDate}
                                onOpen={(h) => setDetailId(h.id)}
                                onSubmit={submitHandover}
                                onAcknowledge={acknowledgeHandover}
                                onEdit={openEdit}
                            />
                        </div>
                    )}
                </div>
            </PageShell>

            <HandoverDetailDialog
                handover={detailHandover}
                open={detailId != null}
                onOpenChange={(open) => !open && setDetailId(null)}
                onEdit={openEdit}
                onSubmit={submitHandover}
                onAcknowledge={acknowledgeHandover}
            />

            {wizardOpen ? (
                <HandoverWizard
                    open={wizardOpen}
                    onOpenChange={(open) => (open ? null : closeWizard())}
                    editing={editingHandover}
                    catalogue={catalogue}
                    currentUser={currentUser}
                    preselectClientId={pendingClientId}
                    onAddClient={() => setAddClientOpen(true)}
                    onSubmitted={(week) => goWeek(week)}
                />
            ) : null}

            <AddClientDialog
                isOpen={addClientOpen}
                onClose={() => setAddClientOpen(false)}
                sites={catalogue.sites}
                serviceContexts={catalogue.serviceContexts.map((s) => ({
                    id: s.id,
                    name: s.name,
                    type: s.type ?? undefined,
                }))}
                keyWorkers={catalogue.staff.map((s) => ({
                    id: s.id,
                    name: s.name,
                }))}
                geofences={[]}
                defaultServiceContextId={
                    catalogue.serviceContexts[0]?.id ?? null
                }
                onSaved={(id) => {
                    setAddClientOpen(false);
                    router.reload({
                        only: ['catalogue'],
                        onSuccess: () => setPendingClientId(id),
                    });
                }}
            />
        </AppLayout>
    );
}
