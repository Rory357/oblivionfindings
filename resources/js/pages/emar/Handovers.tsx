/* eslint-disable no-restricted-syntax -- the eMAR handovers hero + activity feed are custom-layout
   bordered surfaces / chip buttons (not Card/Button); the cards/rail/detail/wizard are reused shared
   components. All colours are semantic tokens. */
import { AddClientDialog } from '@/components/clients/add-client-dialog';
import { EmarHubRail } from '@/components/emar/emar-hub-rail';
import {
    EmarMeters,
    EmarViewFilter,
} from '@/components/emar/workspace-navigation';
import {
    PageHeader,
    PageHeaderFilterButton,
    PageHeaderPrimaryButton,
    PageHeaderSearch,
    PageHeaderStatusChip,
} from '@/components/page/page-header';
import { EntityFilter, type RosterTabItem } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { useEmarBreadcrumbs } from '@/hooks/use-emar-breadcrumbs';
import AppLayout from '@/layouts/app-layout';
import { CardsView } from '@/pages/operations/handovers/components/cards-view';
import { HandoverDetailDialog } from '@/pages/operations/handovers/components/handover-detail-dialog';
import { HandoverRail } from '@/pages/operations/handovers/components/handover-rail';
import { HandoverWizard } from '@/pages/operations/handovers/components/handover-wizard';
import {
    clientName,
    ymd,
    type Catalogue,
    type Handover,
} from '@/pages/operations/handovers/components/shared';
import { Head, router } from '@inertiajs/react';
import {
    ArrowLeftRight,
    BellRing,
    CheckCircle2,
    ChevronLeft,
    ChevronRight,
    Clock3,
    FilePenLine,
    History,
    Layers,
    Pill,
    Plus,
    Send,
    ShieldAlert,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

type Props = {
    handovers: Handover[];
    weekStart: string;
    weekEnd: string;
    catalogue: Catalogue;
    can: { create: boolean; manage: boolean };
    currentUser: { id: number; name: string };
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

const fmtRange = (start: string, end: string) => {
    const s = new Date(`${start}T00:00:00`);
    const e = new Date(`${end}T00:00:00`);
    const sM = s.toLocaleDateString('en-NZ', { month: 'short' });
    const eM = e.toLocaleDateString('en-NZ', { month: 'short' });
    return sM === eM
        ? `${s.getDate()} – ${e.getDate()} ${eM}`
        : `${s.getDate()} ${sM} – ${e.getDate()} ${eM}`;
};
const relative = (iso: string | null) => {
    if (!iso) return '';
    const d = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);
    return d <= 0 ? 'today' : d === 1 ? 'yesterday' : `${d}d ago`;
};

type HandoverAlert = {
    kind: string;
    tone: 'critical' | 'warning';
    icon: typeof BellRing;
    message: string;
    tab?: string;
    href?: string;
};

const DISMISSED_ALERTS_KEY = 'handover-dismissed-alerts';

/** Per-session dismissed alert kinds (survives Inertia partial reloads + soft nav). */
function readDismissedAlerts(): string[] {
    if (typeof window === 'undefined') return [];
    try {
        const raw = window.sessionStorage.getItem(DISMISSED_ALERTS_KEY);
        return raw ? (JSON.parse(raw) as string[]) : [];
    } catch {
        return [];
    }
}

function persistDismissedAlerts(kinds: string[]): string[] {
    const unique = Array.from(new Set(kinds));
    if (typeof window !== 'undefined') {
        try {
            window.sessionStorage.setItem(
                DISMISSED_ALERTS_KEY,
                JSON.stringify(unique),
            );
        } catch {
            /* sessionStorage unavailable — dismissal stays in-memory only */
        }
    }
    return unique;
}

export default function Handovers({
    handovers = [],
    weekStart,
    weekEnd,
    catalogue,
    can = { create: false, manage: false },
    currentUser,
    sites,
    active_site: activeSite,
    site_brand_colour: brandColour,
}: Props) {
    const breadcrumbs = useEmarBreadcrumbs();
    const weekStartDate = useMemo(
        () => new Date(`${weekStart}T00:00:00`),
        [weekStart],
    );
    const [tab, setTab] = useState('all');
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<number | null>(
        activeSite?.id ?? null,
    );
    // Client + staff are filtered client-side over the loaded week (Site round-trips
    // to the server for its query scope + brand colour; mirror Operations' baseFiltered).
    const [clientFilter, setClientFilter] = useState<number | null>(null);
    const [staffFilter, setStaffFilter] = useState<number | null>(null);
    const [dismissed, setDismissed] = useState<string[]>(() =>
        readDismissedAlerts(),
    );
    const dismiss = (kind: string) =>
        setDismissed((prev) => persistDismissedAlerts([...prev, kind]));
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
            needsAck: handovers.filter(
                (h) =>
                    h.status === 'submitted' &&
                    h.incoming_staff?.id === currentUser?.id,
            ).length,
            incidents: handovers.reduce(
                (sum, h) => sum + (h.incidents_to_note?.length ?? 0),
                0,
            ),
            // Submitted/acknowledged handovers for a client with controlled drugs
            // (cd_required, stamped exactly at save time) that carry no two-person CD
            // count check.
            cdUnverified: handovers.filter(
                (h) =>
                    (h.status === 'submitted' || h.status === 'acknowledged') &&
                    h.cd_required &&
                    !h.cd_verification,
            ).length,
        }),
        [handovers, currentUser],
    );

    // Stacked, dismissible (per session) alert strip built from already-computed counts.
    const alertDefs: (HandoverAlert | false)[] = [
        counts.needsAck > 0 && {
            kind: 'needs_ack',
            tone: 'warning' as const,
            icon: BellRing,
            message: `${counts.needsAck} handover${counts.needsAck === 1 ? '' : 's'} awaiting your read-back acknowledgement.`,
            tab: 'needs_ack',
        },
        counts.openIncoming > 0 && {
            kind: 'open_incoming',
            tone: 'critical' as const,
            icon: ShieldAlert,
            message: `${counts.openIncoming} open incoming shift${counts.openIncoming === 1 ? '' : 's'} — needs cover.`,
            tab: 'open_incoming',
        },
        counts.cdUnverified > 0 && {
            kind: 'cd_unverified',
            tone: 'critical' as const,
            icon: Pill,
            message: `${counts.cdUnverified} submitted handover${counts.cdUnverified === 1 ? '' : 's'} with controlled drugs but no count check recorded.`,
            href: '/emar/controlled',
        },
    ];
    const alerts = alertDefs.filter(
        (a): a is HandoverAlert =>
            Boolean(a) && !dismissed.includes((a as HandoverAlert).kind),
    );

    // Unique clients + staff present in this week's handovers, for the hero filters.
    const clientItems = useMemo(() => {
        const map = new Map<number, string>();
        handovers.forEach((h) => {
            if (h.client) map.set(h.client.id, clientName(h.client));
        });
        return [...map.entries()]
            .map(([id, name]) => ({ id, name }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [handovers]);
    const staffItems = useMemo(() => {
        const map = new Map<number, string>();
        handovers.forEach((h) => {
            if (h.outgoing_staff)
                map.set(h.outgoing_staff.id, h.outgoing_staff.name);
            if (h.incoming_staff)
                map.set(h.incoming_staff.id, h.incoming_staff.name);
        });
        return [...map.entries()]
            .map(([id, name]) => ({ id, name }))
            .sort((a, b) => a.name.localeCompare(b.name));
    }, [handovers]);

    const searched = useMemo(() => {
        const q = search.trim().toLowerCase();
        return handovers.filter((h) => {
            if (clientFilter != null && h.client?.id !== clientFilter)
                return false;
            if (
                staffFilter != null &&
                h.outgoing_staff?.id !== staffFilter &&
                h.incoming_staff?.id !== staffFilter &&
                h.acknowledger?.id !== staffFilter
            )
                return false;
            if (
                q &&
                ![
                    h.handover_notes,
                    clientName(h.client),
                    h.outgoing_staff?.name,
                    h.incoming_staff?.name,
                    h.site?.name,
                    h.client_mood,
                    ...(h.medications_due ?? []),
                    ...(h.incidents_to_note ?? []),
                ]
                    .filter(Boolean)
                    .join(' ')
                    .toLowerCase()
                    .includes(q)
            )
                return false;
            return true;
        });
    }, [handovers, search, clientFilter, staffFilter]);

    const filtered = useMemo(() => {
        switch (tab) {
            case 'draft':
                return searched.filter((h) => h.status === 'draft');
            case 'submitted':
                return searched.filter((h) => h.status === 'submitted');
            case 'acknowledged':
                return searched.filter((h) => h.status === 'acknowledged');
            case 'needs_ack':
                return searched.filter(
                    (h) =>
                        h.status === 'submitted' &&
                        h.incoming_staff?.id === currentUser?.id,
                );
            case 'open_incoming':
                return searched.filter((h) => h.incoming_staff == null);
            default:
                return searched;
        }
    }, [searched, tab, currentUser]);

    const activity = useMemo(() => {
        const items: {
            actor: string;
            verb: string;
            subject: string;
            at: string | null;
            icon: 'send' | 'ack' | 'draft';
        }[] = [];
        handovers.forEach((h) => {
            const subject = clientName(h.client);
            if (h.acknowledged_at)
                items.push({
                    actor: h.acknowledger?.name ?? 'Incoming worker',
                    verb: 'acknowledged the handover for',
                    subject,
                    at: h.acknowledged_at,
                    icon: 'ack',
                });
            if (h.submitted_at)
                items.push({
                    actor: h.outgoing_staff?.name ?? 'Outgoing worker',
                    verb: 'submitted a handover for',
                    subject,
                    at: h.submitted_at,
                    icon: 'send',
                });
            if (h.status === 'draft' && h.created_at)
                items.push({
                    actor: h.outgoing_staff?.name ?? 'A worker',
                    verb: 'started a draft handover for',
                    subject,
                    at: h.created_at,
                    icon: 'draft',
                });
        });
        return items
            .sort((a, b) => (b.at ?? '').localeCompare(a.at ?? ''))
            .slice(0, 40);
    }, [handovers]);

    const detailHandover =
        detailId != null
            ? (handovers.find((h) => h.id === detailId) ?? null)
            : null;
    const editingHandover =
        editingId != null
            ? (handovers.find((h) => h.id === editingId) ?? null)
            : null;

    const goWeek = (week: Date) => {
        const target = ymd(week);
        if (target === weekStart) return;
        router.get(
            '/emar/handovers',
            { week: target, ...(siteFilter ? { site_id: siteFilter } : {}) },
            { preserveState: true, preserveScroll: true },
        );
    };
    const stepWeek = (delta: number) => {
        const d = new Date(weekStartDate);
        d.setDate(d.getDate() + delta * 7);
        goWeek(d);
    };
    const onSite = (id: number | null) => {
        setSiteFilter(id);
        router.get(
            '/emar/handovers',
            { week: weekStart, ...(id ? { site_id: id } : {}) },
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
        router.post(
            `/emar/handovers/${h.id}/submit`,
            {},
            {
                preserveScroll: true,
                onSuccess: () =>
                    toast.success('Draft submitted to incoming worker'),
            },
        );
    const acknowledgeHandover = (h: Handover) =>
        router.post(
            `/emar/handovers/${h.id}/acknowledge`,
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

    const TABS: RosterTabItem[] = [
        {
            id: 'all',
            label: 'All',
            icon: Layers,
            tone: 'primary',
            badge: counts.total || undefined,
        },
        {
            id: 'draft',
            label: 'Drafts',
            icon: FilePenLine,
            tone: 'warning',
            badge: counts.draft || undefined,
        },
        {
            id: 'submitted',
            label: 'Submitted',
            icon: Clock3,
            tone: 'info',
            badge: counts.submitted || undefined,
        },
        {
            id: 'acknowledged',
            label: 'Acknowledged',
            icon: CheckCircle2,
            tone: 'success',
            badge: counts.acknowledged || undefined,
        },
        {
            id: 'needs_ack',
            label: 'Needs acknowledgement',
            icon: BellRing,
            tone: 'warning',
            badge: counts.needsAck || undefined,
        },
        {
            id: 'open_incoming',
            label: 'Open incoming',
            icon: ShieldAlert,
            tone: 'critical',
            badge: counts.openIncoming || undefined,
        },
        { id: 'activity', label: 'Activity', icon: History, tone: 'info' },
    ];
    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Medication handovers" />
            <div className="flex flex-col gap-5">
                <PageHeader
                    frontline
                    rail={<EmarHubRail />}
                    brandColour={brandColour}
                    icon={ArrowLeftRight}
                    title="Medication handovers"
                    titleChip={
                        <PageHeaderStatusChip variant="neutral">
                            Selected week
                        </PageHeaderStatusChip>
                    }
                    subline={`${fmtRange(weekStart, weekEnd)} · Pacific/Auckland`}
                    meters={
                        <EmarMeters
                            items={[
                                {
                                    label: 'Handovers',
                                    value: counts.total,
                                    caption: 'In the selected week',
                                    onClick: () => setTab('all'),
                                },
                                {
                                    label: 'Submitted',
                                    value: counts.submitted,
                                    caption: 'Ready for the next shift',
                                    onClick: () => setTab('submitted'),
                                },
                                {
                                    label: 'Acknowledged',
                                    value: counts.acknowledged,
                                    caption: 'Received by the next shift',
                                    onClick: () => setTab('acknowledged'),
                                },
                                {
                                    label: 'Needs acknowledgement',
                                    value: counts.needsAck,
                                    caption: 'Waiting for you',
                                    tone:
                                        counts.needsAck > 0
                                            ? 'warning'
                                            : 'brand',
                                    onClick: () => setTab('needs_ack'),
                                },
                                {
                                    label: 'Open incoming',
                                    value: counts.openIncoming,
                                    caption: 'Incoming shifts still open',
                                    tone:
                                        counts.openIncoming > 0
                                            ? 'critical'
                                            : 'brand',
                                    onClick: () => setTab('open_incoming'),
                                },
                            ]}
                        />
                    }
                    actions={
                        <>
                            <PageHeaderSearch
                                value={search}
                                onChange={setSearch}
                                placeholder="Search person, staff or note…"
                                ariaLabel="Search handovers"
                            />
                            {can.create ? (
                                <PageHeaderPrimaryButton
                                    icon={Plus}
                                    onClick={openNew}
                                >
                                    New handover
                                </PageHeaderPrimaryButton>
                            ) : null}
                        </>
                    }
                    filters={
                        <div className="flex flex-wrap items-center justify-end gap-2">
                            <EmarViewFilter
                                value={tab}
                                onChange={setTab}
                                items={TABS}
                                label="Handover view"
                            />
                            <div className="flex items-center gap-2">
                                <PageHeaderFilterButton
                                    aria-label="Previous week"
                                    onClick={() => stepWeek(-1)}
                                >
                                    <ChevronLeft className="h-3.5 w-3.5" />
                                </PageHeaderFilterButton>
                                <span className="rounded-full border border-primary-foreground/30 bg-primary-foreground/15 px-3 py-1 text-xs font-medium text-primary-foreground">
                                    Wk · {fmtRange(weekStart, weekEnd)}
                                </span>
                                <PageHeaderFilterButton
                                    aria-label="Next week"
                                    onClick={() => stepWeek(1)}
                                >
                                    <ChevronRight className="h-3.5 w-3.5" />
                                </PageHeaderFilterButton>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {search && (
                                    <PageHeaderFilterButton
                                        onClick={() => setSearch('')}
                                    >
                                        Clear search
                                    </PageHeaderFilterButton>
                                )}
                                {sites.length > 0 && (
                                    <EntityFilter
                                        label="Site"
                                        allLabel="All sites"
                                        items={sites}
                                        value={siteFilter}
                                        onChange={onSite}
                                        onDark
                                    />
                                )}
                                {clientItems.length > 0 && (
                                    <EntityFilter
                                        label="Client"
                                        allLabel="All clients"
                                        items={clientItems}
                                        value={clientFilter}
                                        onChange={setClientFilter}
                                        onDark
                                    />
                                )}
                                {staffItems.length > 0 && (
                                    <EntityFilter
                                        label="Staff"
                                        allLabel="All staff"
                                        pluralLabel="staff"
                                        items={staffItems}
                                        value={staffFilter}
                                        onChange={setStaffFilter}
                                        onDark
                                    />
                                )}
                            </div>
                        </div>
                    }
                />

                {alerts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        {alerts.map((a) => (
                            <AlertRow
                                key={a.kind}
                                alert={a}
                                onReview={() =>
                                    a.href
                                        ? router.visit(a.href)
                                        : a.tab
                                          ? setTab(a.tab)
                                          : undefined
                                }
                                onDismiss={() => dismiss(a.kind)}
                            />
                        ))}
                    </div>
                )}

                {tab === 'activity' ? (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        {activity.length === 0 ? (
                            <div className="px-5 py-12 text-center text-sm text-muted-foreground">
                                No handover activity this week.
                            </div>
                        ) : (
                            <div className="flex flex-col">
                                {activity.map((e, i) => (
                                    <div
                                        key={i}
                                        className="flex items-center gap-3 border-b px-4 py-3 last:border-b-0"
                                    >
                                        <span
                                            className={`flex h-8 w-8 items-center justify-center rounded-lg ${e.icon === 'ack' ? 'bg-status-success-bg text-status-success' : e.icon === 'send' ? 'bg-status-info-bg text-status-info' : 'bg-muted text-muted-foreground'}`}
                                        >
                                            {e.icon === 'ack' ? (
                                                <CheckCircle2 className="h-4 w-4" />
                                            ) : e.icon === 'send' ? (
                                                <Send className="h-4 w-4" />
                                            ) : (
                                                <FilePenLine className="h-4 w-4" />
                                            )}
                                        </span>
                                        <div className="flex-1 text-sm">
                                            <span className="font-medium">
                                                {e.actor}
                                            </span>{' '}
                                            {e.verb}{' '}
                                            <span className="font-medium">
                                                {e.subject}
                                            </span>
                                        </div>
                                        <span className="text-xs text-muted-foreground">
                                            {relative(e.at)}
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px]">
                        <section
                            className="min-w-0"
                            aria-label="Medication handovers in this view"
                        >
                            {filtered.length === 0 ? (
                                <div className="rounded-2xl border border-dashed bg-card px-5 py-16 text-center">
                                    <Pill className="mx-auto mb-3 h-8 w-8 text-muted-foreground/40" />
                                    <div className="text-sm font-medium">
                                        No handovers in this view
                                    </div>
                                    <div className="mt-1 text-sm text-muted-foreground">
                                        {can.create
                                            ? 'Create a medication handover to get started.'
                                            : 'Nothing matches the current filters.'}
                                    </div>
                                    {can.create && (
                                        <Button
                                            className="mt-4"
                                            size="sm"
                                            onClick={openNew}
                                        >
                                            <Plus className="h-4 w-4" />
                                            New handover
                                        </Button>
                                    )}
                                </div>
                            ) : (
                                <CardsView handovers={filtered} {...handlers} />
                            )}
                        </section>
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

            <HandoverDetailDialog
                handover={detailHandover}
                open={detailId != null}
                onOpenChange={(open) => !open && setDetailId(null)}
                onEdit={openEdit}
                onSubmit={submitHandover}
                onAcknowledge={acknowledgeHandover}
                medicationSnapshotUrl="/emar/handovers/shift-medications"
                emarLens
            />

            {wizardOpen && (
                <HandoverWizard
                    open={wizardOpen}
                    onOpenChange={(open) => (open ? null : closeWizard())}
                    editing={editingHandover}
                    catalogue={catalogue}
                    currentUser={currentUser}
                    preselectClientId={pendingClientId}
                    onAddClient={() => setAddClientOpen(true)}
                    onSubmitted={(week) => goWeek(week)}
                    basePath="/emar/handovers"
                    medicationFocus
                />
            )}

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

/** One row of the hero alert strip — icon + message + Review jump + per-session
 *  dismiss. Mirrors /emar/controlled's AlertRow. */
function AlertRow({
    alert,
    onReview,
    onDismiss,
}: {
    alert: HandoverAlert;
    onReview: () => void;
    onDismiss: () => void;
}) {
    const Icon = alert.icon;
    const tone =
        alert.tone === 'critical'
            ? 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical'
            : 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning';
    return (
        <div
            className={`flex items-center justify-between gap-3 rounded-xl border px-4 py-3 ${tone}`}
        >
            <span className="flex items-center gap-2 text-sm font-medium">
                <Icon className="h-4 w-4 shrink-0" />
                {alert.message}
            </span>
            <span className="flex items-center gap-1.5">
                <Button size="sm" variant="outline" onClick={onReview}>
                    Review
                </Button>
                <button
                    type="button"
                    aria-label="Dismiss alert"
                    onClick={onDismiss}
                    className="grid h-7 w-7 place-items-center rounded-md opacity-70 hover:bg-foreground/10 hover:opacity-100"
                >
                    <X className="h-4 w-4" />
                </button>
            </span>
        </div>
    );
}
