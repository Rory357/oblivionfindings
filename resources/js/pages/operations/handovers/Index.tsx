import { AddClientDialog } from '@/components/clients/add-client-dialog';
import { PageHeaderRail } from '@/components/page';
import PageShell from '@/components/page-shell';
import { ErrorState } from '@/components/ui/error-state';
import { LaravelPagination } from '@/components/ui/laravel-pagination';
import { LoadingState } from '@/components/ui/loading-state';
import AppLayout from '@/layouts/app-layout';
import { Head, router } from '@inertiajs/react';
import { CheckCircle2, ListChecks, Pencil, Send } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import {
    useHandoverFilters,
    type HandoverFilters,
} from './components/use-handover-filters';

import { BoardView } from './components/board-view';
import { CardsView } from './components/cards-view';
import { HandoverDetailDialog } from './components/handover-detail-dialog';
import { HandoverRail } from './components/handover-rail';
import { HandoverWizard } from './components/handover-wizard';
import type { HeroCounts } from './components/handovers-hero';
import { HandoversHero } from './components/handovers-hero';
import { ListView } from './components/list-view';
import {
    clientName,
    ymd,
    type Catalogue,
    type Handover,
    type StatusTab,
    type ViewMode,
} from './components/shared';

type Props = {
    handovers: Handover[];
    weekStart: string;
    weekEnd: string;
    filters: HandoverFilters;
    summary: HeroCounts;
    evidence: { checked_at: string; timezone: string };
    handoverPagination: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
        links: { url: string | null; label: string; active: boolean }[];
    };
    catalogue: Catalogue;
    can: { create: boolean; manage: boolean; view_medications?: boolean };
    currentUser: { id: number; name: string };
};

export default function HandoversIndex(props: Props) {
    return <HandoversBody key={props.currentUser.id} {...props} />;
}

function HandoversBody({
    handovers = [],
    weekStart,
    catalogue,
    can = { create: false, manage: false },
    currentUser,
    filters: serverFilters,
    summary: counts,
    evidence,
    handoverPagination,
}: Props) {
    const weekStartDate = useMemo(
        () => new Date(`${weekStart}T00:00:00`),
        [weekStart],
    );

    const query = useHandoverFilters(serverFilters);
    const { draft: filters } = query;
    const [view, setView] = useState<ViewMode>('cards');
    const [wizardOpen, setWizardOpen] = useState(false);
    const [editingId, setEditingId] = useState<number | null>(null);
    const [detailId, setDetailId] = useState<number | null>(() => {
        const requested =
            typeof window === 'undefined'
                ? null
                : Number(
                      new URLSearchParams(window.location.search).get(
                          'handover',
                      ),
                  );
        return requested &&
            handovers.some((handover) => handover.id === requested)
            ? requested
            : null;
    });
    const [addClientOpen, setAddClientOpen] = useState(false);
    const [pendingClientId, setPendingClientId] = useState<number | null>(null);

    const detailHandover =
        detailId != null
            ? (handovers.find((h) => h.id === detailId) ?? null)
            : null;
    const editingHandover =
        editingId != null
            ? (handovers.find((h) => h.id === editingId) ?? null)
            : null;

    const hasFilters =
        filters.q.trim() !== '' ||
        filters.staff != null ||
        filters.client != null ||
        filters.site != null ||
        filters.status !== 'all';

    const shownView =
        view === 'board' && serverFilters.status !== 'all' ? 'cards' : view;
    const unapplied = ['week', 'q', 'staff', 'client', 'site', 'status'].some(
        (key) =>
            filters[key as keyof HandoverFilters] !==
            serverFilters[key as keyof HandoverFilters],
    );
    const readNotice = query.error
        ? 'Results were not updated. Counts refer to the last loaded filters.'
        : unapplied && !query.loading
          ? 'Choices changed. Select Search to apply them; counts refer to the last loaded filters.'
          : null;
    const rangeLabel =
        handoverPagination.total === 0
            ? 'No matching handovers'
            : `Showing ${handoverPagination.from ?? 0}–${handoverPagination.to ?? 0} of ${handoverPagination.total} · Page ${handoverPagination.current_page} of ${handoverPagination.last_page}`;
    const selectStatus = (status: StatusTab) => {
        if (query.change({ status }) && view === 'board') setView('cards');
    };
    const selectView = (next: ViewMode) => {
        if (query.loading) return;
        if (next === 'board' && filters.status !== 'all') {
            if (!query.change({ status: 'all' })) return;
        }
        setView(next);
    };
    const goWeek = (week: Date) => {
        const target = ymd(week);
        if (target !== weekStart) query.change({ week: target });
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
                onSuccess: () =>
                    toast.success('Draft submitted to incoming worker'),
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
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                { title: 'Handovers', href: '/operations/handovers' },
            ]}
        >
            <Head title="Shift Handovers" />
            <PageShell>
                <HandoversHero
                    weekStart={weekStartDate}
                    counts={counts}
                    search={filters.q}
                    onSearch={query.editSearch}
                    onSearchSubmit={() => query.change()}
                    filters={filters}
                    onFilter={(changes) => query.change(changes)}
                    catalogue={catalogue}
                    onNewHandover={openNew}
                    onWeekChange={goWeek}
                    canCreate={can.create}
                    onStatus={selectStatus}
                    evidence={evidence}
                    view={shownView}
                    onView={selectView}
                    hasFilters={hasFilters}
                    onClear={query.clear}
                    loading={query.loading}
                    rangeLabel={rangeLabel}
                    readNotice={readNotice}
                    rail={
                        <fieldset
                            disabled={query.loading}
                            className="w-full min-w-0"
                        >
                            <PageHeaderRail
                                value={filters.status}
                                onSelect={selectStatus}
                                items={[
                                    {
                                        key: 'all',
                                        label: 'All',
                                        icon: ListChecks,
                                        count: counts.total,
                                    },
                                    {
                                        key: 'draft',
                                        label: 'Drafts',
                                        icon: Pencil,
                                        count: counts.draft,
                                    },
                                    {
                                        key: 'submitted',
                                        label: 'Awaiting acknowledgement',
                                        icon: Send,
                                        count: counts.submitted,
                                    },
                                    {
                                        key: 'acknowledged',
                                        label: 'Acknowledged',
                                        icon: CheckCircle2,
                                        count: counts.acknowledged,
                                    },
                                ]}
                            />
                        </fieldset>
                    }
                />
                <div className="space-y-4" aria-busy={query.loading}>
                    {query.loading ? (
                        <LoadingState message="Loading matching handovers…" />
                    ) : query.error ? (
                        <div role="alert">
                            <ErrorState
                                title="Handovers could not be updated"
                                message={query.error}
                                onRetry={query.retry}
                            />
                        </div>
                    ) : (
                        <>
                            {shownView === 'board' ? (
                                <div className="space-y-3">
                                    <p className="text-caption">
                                        Board columns show records on this page.
                                        Use the status tabs to see every
                                        matching handover in that status.
                                    </p>
                                    <BoardView
                                        timeZone={evidence.timezone}
                                        handovers={handovers}
                                        {...handlers}
                                    />
                                </div>
                            ) : (
                                <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px]">
                                    <section
                                        aria-label="Records"
                                        className="min-w-0"
                                    >
                                        {shownView === 'cards' ? (
                                            <CardsView
                                                timeZone={evidence.timezone}
                                                handovers={handovers}
                                                {...handlers}
                                            />
                                        ) : (
                                            <ListView
                                                timeZone={evidence.timezone}
                                                handovers={handovers}
                                                {...handlers}
                                            />
                                        )}
                                    </section>
                                    <HandoverRail
                                        timeZone={evidence.timezone}
                                        handovers={handovers}
                                        counts={counts}
                                        weekStart={weekStartDate}
                                        onAwaiting={() =>
                                            selectStatus('submitted')
                                        }
                                        {...handlers}
                                    />
                                </div>
                            )}
                            <LaravelPagination
                                links={handoverPagination.links}
                                lastPage={handoverPagination.last_page}
                            />
                        </>
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
                // Same live MAR lens the eMAR handover view shows, so the ops
                // side isn't blind to "5 meds overdue" on the same shift.
                medicationSnapshotUrl={
                    can.view_medications
                        ? '/emar/handovers/shift-medications'
                        : undefined
                }
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
