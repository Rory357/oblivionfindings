/* eslint-disable no-restricted-syntax -- the tab cards/tables are custom-layout
   bordered surfaces (not Card/Button), and the hero carries the white pill search
   on the dark band (native input/button); all colours are semantic tokens. */
import { OrderDetailDialog } from '@/components/emar/prescriptions/order-detail-dialog';
import {
    countersignHoursLeft,
    needsCountersign,
    orderStatusTone,
    type ClientOption,
    type CovertAuth,
    type MedOption,
    type PrescriptionOrder,
    type StaffOption,
} from '@/components/emar/prescriptions/types';
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { CountersignDialog, CovertDialog, DispenseDialog, LinkMarDialog, NewOrderDialog } from '@/pages/emar/_prescription-dialogs';
import { Head, router } from '@inertiajs/react';
import { AlertTriangle, Ban, Eye, FileText, LineChart, Link2, Package, PenTool, Pill, Plus, Search, ShieldCheck, User, X } from 'lucide-react';
import { useMemo, useState, type KeyboardEvent as ReactKeyboardEvent, type MouseEvent as ReactMouseEvent } from 'react';

type Modal =
    | { type: 'order' }
    | { type: 'covert' }
    | { type: 'detail' | 'countersign' | 'dispense' | 'link'; order: PrescriptionOrder }
    | null;

type Props = {
    orders: PrescriptionOrder[];
    covert: CovertAuth[];
    clients: ClientOption[];
    staff: StaffOption[];
    medications: MedOption[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

const STATUS_FILTERS = [
    { id: 'all', label: 'All' },
    { id: 'pending', label: 'Pending' },
    { id: 'confirmed', label: 'Confirmed' },
    { id: 'dispensed', label: 'Dispensed' },
    { id: 'cancelled', label: 'Cancelled' },
    { id: 'expired', label: 'Expired' },
];

const ALERT_TONE: Record<string, string> = {
    critical: 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical',
    warning: 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning',
    info: 'border-status-info/30 bg-status-info-bg/60 text-status-info',
};

function hue(id: number): number {
    return Math.round((id * 137.508) % 360);
}
function initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('');
}
function Avatar({ id, name }: { id: number; name: string }) {
    return (
        <span className="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-primary-foreground" style={{ backgroundColor: `oklch(0.52 0.16 ${hue(id)})` }}>
            {initials(name)}
        </span>
    );
}
function Pill2({ label, tone }: { label: string; tone: string }) {
    return <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${tone}`}>{label}</span>;
}

/** Order → context-menu header tag (semantic token CSS vars), mirroring PRN. */
function ctxTag(o: PrescriptionOrder): { tag: string; tagBg: string; tagColor: string } {
    if (needsCountersign(o)) {
        const hrs = countersignHoursLeft(o);
        if (hrs !== null && hrs < 0) return { tag: 'Overdue', tagBg: 'var(--status-critical-bg)', tagColor: 'var(--status-critical)' };
        return { tag: hrs !== null ? `Sign ${hrs}h` : 'Awaiting', tagBg: 'var(--status-warning-bg)', tagColor: 'var(--status-warning)' };
    }
    switch (o.status) {
        case 'dispensed':
            return { tag: 'Dispensed', tagBg: 'var(--status-success-bg)', tagColor: 'var(--status-success)' };
        case 'confirmed':
            return { tag: 'Confirmed', tagBg: 'var(--status-info-bg)', tagColor: 'var(--status-info)' };
        case 'cancelled':
            return { tag: 'Cancelled', tagBg: 'var(--muted)', tagColor: 'var(--muted-foreground)' };
        case 'expired':
            return { tag: 'Expired', tagBg: 'var(--status-critical-bg)', tagColor: 'var(--status-critical)' };
        default:
            return { tag: 'Pending', tagBg: 'var(--status-warning-bg)', tagColor: 'var(--status-warning)' };
    }
}

/** Standard empty state: icon + message + optional CTA (parity with shared idiom). */
function EmptyState({ icon: Icon, message, cta }: { icon: typeof FileText; message: string; cta?: { label: string; onClick: () => void } }) {
    return (
        <div className="flex flex-col items-center gap-3 rounded-2xl border bg-card px-5 py-12 text-center">
            <span className="grid h-11 w-11 place-items-center rounded-full bg-muted text-muted-foreground">
                <Icon className="h-5 w-5" />
            </span>
            <p className="text-sm text-muted-foreground">{message}</p>
            {cta ? <Button size="sm" variant="outline" onClick={cta.onClick}>{cta.label}</Button> : null}
        </div>
    );
}

export default function Prescriptions(props: Props) {
    const { orders, covert, clients, staff, medications, sites, active_site: activeSite, site_brand_colour: brandColour } = props;

    const [activeTab, setActiveTab] = useState('orders');
    const [search, setSearch] = useState('');
    const [statusFilter, setStatusFilter] = useState('all');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [clientFilter, setClientFilter] = useState<number | null>(null);
    const [modal, setModal] = useState<Modal>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [dismissed, setDismissed] = useState<Set<string>>(new Set());

    const onSite = (id: number | null) => {
        setSiteFilter(id);
        router.get('/emar/prescriptions', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true });
    };

    const clientItems = useMemo(
        () => clients.map((c) => ({ id: c.id, name: `${c.last_name}, ${c.first_name}`, description: c.site_name ?? undefined })),
        [clients],
    );

    // Counts are computed from the UNFILTERED payload so the hero stats, tab
    // badges and alert strip always reflect the true site totals; the footer
    // search/client filters only narrow the rendered rows (parity with PRN).
    const counts = useMemo(() => {
        const awaiting = orders.filter(needsCountersign);
        return {
            awaiting: awaiting.length,
            overdue: awaiting.filter((o) => (countersignHoursLeft(o) ?? 1) < 0).length,
            active: orders.filter((o) => ['pending', 'confirmed'].includes(o.status)).length,
            toDispense: orders.filter((o) => o.status === 'confirmed').length,
            covert: covert.length,
        };
    }, [orders, covert]);

    // Footer match — client + free-text search, applied to every tab's rows.
    const matchesFooter = useMemo(() => {
        const q = search.toLowerCase().trim();
        return (o: PrescriptionOrder) => {
            if (clientFilter && o.client_id !== clientFilter) return false;
            if (q && !`${o.medication_name ?? ''} ${o.client_name} ${o.prescriber_name ?? ''}`.toLowerCase().includes(q)) return false;
            return true;
        };
    }, [search, clientFilter]);

    const ordersFiltered = useMemo(
        () => orders.filter((o) => (statusFilter === 'all' || o.status === statusFilter) && matchesFooter(o)),
        [orders, statusFilter, matchesFooter],
    );
    const awaitingOrders = useMemo(() => orders.filter((o) => needsCountersign(o) && matchesFooter(o)), [orders, matchesFooter]);
    const toDispense = useMemo(() => orders.filter((o) => o.status === 'confirmed' && matchesFooter(o)), [orders, matchesFooter]);
    const dispensed = useMemo(() => orders.filter((o) => o.status === 'dispensed' && matchesFooter(o)), [orders, matchesFooter]);
    const activity = useMemo(() => orders.filter(matchesFooter).slice(0, 40), [orders, matchesFooter]);
    const covertFiltered = useMemo(() => {
        const q = search.toLowerCase().trim();
        return covert.filter((c) => (!clientFilter || c.client_id === clientFilter) && (!q || `${c.medication_name ?? ''} ${c.client_name}`.toLowerCase().includes(q)));
    }, [covert, search, clientFilter]);

    const covertFor = (o: PrescriptionOrder): CovertAuth | null =>
        covert.find((c) => c.client_id === o.client_id && (c.medication_name ?? '').toLowerCase() === (o.medication_name ?? '').toLowerCase()) ?? null;
    const linkedMedName = (o: PrescriptionOrder): string | null =>
        o.client_medication_id ? (medications.find((m) => m.id === o.client_medication_id)?.name ?? null) : null;

    const TABS: RosterTabItem[] = [
        { id: 'orders', label: 'Prescriber Orders', icon: FileText, tone: 'primary', badge: orders.length || undefined },
        { id: 'countersign', label: 'Awaiting Countersign', icon: PenTool, tone: 'warning', badge: counts.awaiting || undefined },
        { id: 'dispensing', label: 'Dispensing', icon: Package, tone: 'success', badge: counts.toDispense || undefined },
        { id: 'covert', label: 'Covert', icon: ShieldCheck, tone: 'critical', badge: counts.covert || undefined },
        { id: 'activity', label: 'Activity', icon: LineChart, tone: 'info' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Awaiting countersign', value: counts.awaiting, tone: counts.overdue > 0 ? 'critical' : counts.awaiting > 0 ? 'warning' : 'neutral' },
        { label: 'Active orders', value: counts.active },
        { label: 'To dispense', value: counts.toDispense },
        { label: 'Covert active', value: counts.covert, tone: counts.covert > 0 ? 'warning' : 'neutral' },
    ];

    const confirm = (o: PrescriptionOrder) => router.put(`/emar/prescriptions/${o.id}`, { status: 'confirmed' }, { preserveScroll: true });
    const cancel = (o: PrescriptionOrder) => router.delete(`/emar/prescriptions/${o.id}`, { preserveScroll: true });
    const revoke = (c: CovertAuth) => router.post(`/emar/prescriptions/covert/${c.id}/revoke`, {}, { preserveScroll: true });

    const openDetail = (o: PrescriptionOrder) => setModal({ type: 'detail', order: o });
    const rowKey = (fn: () => void) => (e: ReactKeyboardEvent) => {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            fn();
        }
    };

    // Right-click context menu on an order row (any tab) — mirrors PRN openRowCtx.
    const openRowCtx = (e: ReactMouseEvent, o: PrescriptionOrder) => {
        e.preventDefault();
        const t = ctxTag(o);
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View details', sub: `${o.medication_name ?? 'Order'}${o.order_date ? ` · ${o.order_date}` : ''}`, tone: 'primary', onClick: () => openDetail(o) },
            ...(needsCountersign(o) ? [{ icon: <PenTool className="h-3.5 w-3.5" />, label: 'Countersign', sub: 'Prescriber sign-off', onClick: () => setModal({ type: 'countersign', order: o }) } satisfies ShiftCtxItem] : []),
            ...(o.status === 'pending' && !needsCountersign(o) ? [{ icon: <FileText className="h-3.5 w-3.5" />, label: 'Confirm order', onClick: () => confirm(o) } satisfies ShiftCtxItem] : []),
            ...(o.status === 'confirmed' ? [{ icon: <Package className="h-3.5 w-3.5" />, label: 'Record dispensing', onClick: () => setModal({ type: 'dispense', order: o }) } satisfies ShiftCtxItem] : []),
            ...(['pending', 'confirmed'].includes(o.status) ? [{ icon: <Link2 className="h-3.5 w-3.5" />, label: 'Link to MAR', onClick: () => setModal({ type: 'link', order: o }) } satisfies ShiftCtxItem] : []),
            { sep: true },
            { icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => router.visit(`/operations/clients/${o.client_id}?tab=mar`) },
            { icon: <FileText className="h-3.5 w-3.5" />, label: 'Open on MAR chart', onClick: () => router.visit(`/clients/${o.client_id}/mar`) },
            ...(['pending', 'confirmed'].includes(o.status)
                ? [{ sep: true } satisfies ShiftCtxItem, { icon: <Ban className="h-3.5 w-3.5" />, label: 'Cancel order', tone: 'critical', onClick: () => cancel(o) } satisfies ShiftCtxItem]
                : []),
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: t.tag, tagBg: t.tagBg, tagColor: t.tagColor, meta: `${o.client_name} · ${o.medication_name ?? '—'}${o.prescriber_name ? ` · ${o.prescriber_name}` : ''}`, items });
    };

    // Lighter context menu for a covert authorisation card (not an order row).
    const openCovertCtx = (e: ReactMouseEvent, c: CovertAuth) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            { icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => router.visit(`/operations/clients/${c.client_id}?tab=mar`) },
            { icon: <FileText className="h-3.5 w-3.5" />, label: 'Open on MAR chart', onClick: () => router.visit(`/clients/${c.client_id}/mar`) },
            { sep: true },
            { icon: <Ban className="h-3.5 w-3.5" />, label: 'Revoke authorisation', tone: 'critical', onClick: () => revoke(c) },
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: c.review_overdue ? 'Review overdue' : 'Active', tagBg: 'var(--status-critical-bg)', tagColor: 'var(--status-critical)', meta: `${c.client_name} · ${c.medication_name ?? '—'}`, items });
    };

    // Alert strip — stacked, dismissible, mirrors /emar/controlled. "Awaiting"
    // excludes the overdue rows so the two counts don't double-count the same
    // orders; each row deep-links to the relevant tab.
    type Alert = { key: string; tone: 'critical' | 'warning' | 'info'; tab: string; text: string };
    const awaitingNotOverdue = counts.awaiting - counts.overdue;
    const alerts: Alert[] = [];
    if (counts.overdue > 0) alerts.push({ key: 'overdue', tone: 'critical', tab: 'countersign', text: `${counts.overdue} verbal/telephone order${counts.overdue === 1 ? ' is' : 's are'} overdue for prescriber countersignature.` });
    if (awaitingNotOverdue > 0) alerts.push({ key: 'awaiting', tone: 'warning', tab: 'countersign', text: `${awaitingNotOverdue} order${awaitingNotOverdue === 1 ? '' : 's'} awaiting prescriber countersignature.` });
    if (counts.toDispense > 0) alerts.push({ key: 'dispense', tone: 'info', tab: 'dispensing', text: `${counts.toDispense} confirmed order${counts.toDispense === 1 ? '' : 's'} ready to dispense.` });
    if (counts.covert > 0) alerts.push({ key: 'covert', tone: 'warning', tab: 'covert', text: `${counts.covert} covert administration authorisation${counts.covert === 1 ? '' : 's'} active.` });
    const visibleAlerts = alerts.filter((a) => !dismissed.has(a.key));

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Prescriptions', href: '/emar/prescriptions' }]}>
            <Head title="Prescriptions & Orders" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={FileText}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Prescriptions &amp; orders · live
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Prescriber orders for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description="Prescriber orders, verbal/telephone countersignatures, dispensing, and covert administration authorisations."
                    stats={heroStats}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'order' })}>
                                <Plus className="h-4 w-4" />
                                New prescriber order
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setModal({ type: 'covert' })}>
                                <ShieldCheck className="h-4 w-4" />
                                New covert authorisation
                            </Button>
                        </>
                    }
                    footer={
                        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-end">
                            <div className="flex flex-wrap items-center gap-2 md:ml-auto md:justify-end">
                                <div className="relative w-full max-w-xs md:w-[280px]">
                                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    <input
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Search client, medication or prescriber…"
                                        aria-label="Search prescriber orders"
                                        className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-3 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
                                    />
                                    {search ? (
                                        <button
                                            type="button"
                                            aria-label="Clear search"
                                            onClick={() => setSearch('')}
                                            className="absolute top-1/2 right-2 grid h-5 w-5 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-muted"
                                        >
                                            <X className="h-3.5 w-3.5" />
                                        </button>
                                    ) : null}
                                </div>
                                {sites.length > 0 ? (
                                    <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />
                                ) : null}
                                <EntityFilter label="Client" allLabel="All clients" items={clientItems} value={clientFilter} onChange={setClientFilter} onDark />
                            </div>
                        </div>
                    }
                />

                {visibleAlerts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        {visibleAlerts.map((a) => (
                            <div key={a.key} className={`flex items-center justify-between gap-3 rounded-xl border px-4 py-3 ${ALERT_TONE[a.tone]}`}>
                                <span className="flex items-center gap-2 text-sm font-medium">
                                    <AlertTriangle className="h-4 w-4 shrink-0" />
                                    {a.text}
                                </span>
                                <span className="flex items-center gap-1.5">
                                    <Button size="sm" variant="outline" onClick={() => setActiveTab(a.tab)}>Review</Button>
                                    <button type="button" aria-label="Dismiss alert" onClick={() => setDismissed((prev) => new Set(prev).add(a.key))} className="grid h-7 w-7 place-items-center rounded-md text-current/70 hover:bg-foreground/10">
                                        <X className="h-3.5 w-3.5" />
                                    </button>
                                </span>
                            </div>
                        ))}
                    </div>
                )}

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Prescription views" />

                {activeTab === 'orders' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="flex flex-wrap items-center gap-2.5 border-b p-3.5">
                            <span className="text-sm font-semibold">Prescriber orders</span>
                            <div className="flex flex-wrap gap-1">
                                {STATUS_FILTERS.map((f) => (
                                    <Button key={f.id} size="sm" variant={statusFilter === f.id ? 'secondary' : 'ghost'} onClick={() => setStatusFilter(f.id)}>{f.label}</Button>
                                ))}
                            </div>
                            <span className="ml-auto text-xs text-muted-foreground">{ordersFiltered.length} of {orders.length}</span>
                        </div>
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[920px] text-sm">
                                <thead>
                                    <tr className="bg-muted text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                        <th className="px-4 py-2.5">Date</th>
                                        <th className="px-4 py-2.5">Resident</th>
                                        <th className="px-4 py-2.5">Medication</th>
                                        <th className="px-4 py-2.5">Type</th>
                                        <th className="px-4 py-2.5">Prescriber</th>
                                        <th className="px-4 py-2.5">Status</th>
                                        <th className="px-4 py-2.5">Countersign</th>
                                        <th className="px-4 py-2.5"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {ordersFiltered.length === 0 ? (
                                        <tr><td colSpan={8} className="px-4 py-12 text-center text-muted-foreground">No orders match these filters.</td></tr>
                                    ) : ordersFiltered.map((o) => {
                                        const hrs = countersignHoursLeft(o);
                                        return (
                                            <tr
                                                key={o.id}
                                                tabIndex={0}
                                                aria-label={`Order: ${o.medication_name ?? 'medication'} for ${o.client_name}. Open detail.`}
                                                className="cursor-pointer border-b last:border-b-0 hover:bg-muted/40 focus:bg-muted/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                                                onClick={() => openDetail(o)}
                                                onContextMenu={(e) => openRowCtx(e, o)}
                                                onKeyDown={rowKey(() => openDetail(o))}
                                            >
                                                <td className="px-4 py-3 text-muted-foreground">{o.order_date}</td>
                                                <td className="px-4 py-3"><span className="flex items-center gap-2"><Avatar id={o.client_id} name={o.client_name} />{o.client_name}</span></td>
                                                <td className="px-4 py-3"><div className="font-medium">{o.medication_name}</div><div className="text-xs text-muted-foreground">{[o.dose, o.route, o.frequency].filter(Boolean).join(' · ')}</div></td>
                                                <td className="px-4 py-3">{['verbal', 'telephone'].includes(o.order_type) ? <Pill2 label={o.order_type} tone="bg-status-warning-bg text-status-warning" /> : <span className="text-muted-foreground capitalize">{o.order_type}</span>}</td>
                                                <td className="px-4 py-3"><div>{o.prescriber_name}</div>{o.prescriber_registration && <div className="text-xs text-muted-foreground">{o.prescriber_registration}</div>}</td>
                                                <td className="px-4 py-3"><Pill2 label={o.status} tone={orderStatusTone(o.status)} /></td>
                                                <td className="px-4 py-3">
                                                    {!o.requires_countersign ? <span className="text-muted-foreground">—</span>
                                                        : o.countersigned_at ? <span className="text-status-success">✓ Signed</span>
                                                        : <Button size="sm" variant={hrs !== null && hrs < 0 ? 'destructive' : 'default'} onClick={(e) => { e.stopPropagation(); setModal({ type: 'countersign', order: o }); }}>{hrs !== null && hrs < 0 ? 'Overdue — sign' : `Sign · ${hrs}h`}</Button>}
                                                </td>
                                                <td className="px-4 py-3 text-right">
                                                    <div className="flex items-center justify-end gap-1">
                                                        {o.status === 'pending' && !needsCountersign(o) && <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); confirm(o); }}>Confirm</Button>}
                                                        {o.status === 'confirmed' && <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setModal({ type: 'dispense', order: o }); }}><Package className="h-3.5 w-3.5" />Dispense</Button>}
                                                        {['pending', 'confirmed'].includes(o.status) && <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); setModal({ type: 'link', order: o }); }} aria-label="Link to MAR"><Link2 className="h-3.5 w-3.5" /></Button>}
                                                        {['pending', 'confirmed'].includes(o.status) && <Button size="sm" variant="ghost" className="text-status-critical" onClick={(e) => { e.stopPropagation(); cancel(o); }}>Cancel</Button>}
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                    </div>
                )}

                {activeTab === 'countersign' && (
                    <div className="flex flex-col gap-4">
                        <div className="flex items-center gap-2 rounded-xl border border-status-warning/30 bg-status-warning-bg/50 px-4 py-2.5 text-sm text-status-warning">
                            <AlertTriangle className="h-4 w-4" />
                            Verbal &amp; telephone orders must be countersigned by the prescriber within 24 hours.
                        </div>
                        {awaitingOrders.length === 0 ? (
                            <EmptyState icon={PenTool} message="Nothing awaiting countersignature." />
                        ) : (
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                {awaitingOrders.map((o) => {
                                    const hrs = countersignHoursLeft(o);
                                    const overdue = hrs !== null && hrs < 0;
                                    return (
                                        <div
                                            key={o.id}
                                            tabIndex={0}
                                            aria-label={`Awaiting countersignature: ${o.medication_name ?? 'medication'} for ${o.client_name}. Open detail.`}
                                            className="flex cursor-pointer flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm transition-colors hover:border-primary/50 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary"
                                            onClick={() => openDetail(o)}
                                            onContextMenu={(e) => openRowCtx(e, o)}
                                            onKeyDown={rowKey(() => openDetail(o))}
                                        >
                                            <div className="flex items-center justify-between">
                                                <span className="flex items-center gap-2 font-medium"><Avatar id={o.client_id} name={o.client_name} />{o.client_name}</span>
                                                <Pill2 label={o.order_type} tone="bg-status-warning-bg text-status-warning" />
                                            </div>
                                            <div className="text-sm"><span className="font-medium">{o.medication_name}</span> <span className="text-muted-foreground">{[o.dose, o.frequency].filter(Boolean).join(' · ')}</span></div>
                                            <div className="text-xs text-muted-foreground">Prescriber: {o.prescriber_name} · Taken {o.order_date} by {o.received_by_name ?? '—'}{o.read_back_confirmed ? ' · read-back ✓' : ''}</div>
                                            <div>
                                                <div className={`mb-1 text-[11px] font-medium ${overdue ? 'text-status-critical' : 'text-status-warning'}`}>{overdue ? `Overdue by ${Math.abs(hrs!)}h` : `${hrs}h remaining`}</div>
                                                <div className="h-1.5 overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full ${overdue ? 'bg-status-critical' : 'bg-status-warning'}`} style={{ width: `${overdue ? 100 : Math.max(5, 100 - ((hrs ?? 0) / 24) * 100)}%` }} /></div>
                                            </div>
                                            <Button className="mt-1" onClick={(e) => { e.stopPropagation(); setModal({ type: 'countersign', order: o }); }}>Countersign now</Button>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                )}

                {activeTab === 'dispensing' && (
                    <div className="flex flex-col gap-4">
                        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                            <div className="border-b px-5 py-3.5 text-[15px] font-bold">Awaiting dispense</div>
                            {toDispense.length === 0 ? <div className="px-5 py-8 text-center text-sm text-muted-foreground">No confirmed orders awaiting dispense.</div> : (
                                <ul className="divide-y">
                                    {toDispense.map((o) => (
                                        <li
                                            key={o.id}
                                            tabIndex={0}
                                            aria-label={`Awaiting dispense: ${o.medication_name ?? 'medication'} for ${o.client_name}. Open detail.`}
                                            className="flex cursor-pointer items-center justify-between px-5 py-3 hover:bg-muted/40 focus:bg-muted/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                                            onClick={() => openDetail(o)}
                                            onContextMenu={(e) => openRowCtx(e, o)}
                                            onKeyDown={rowKey(() => openDetail(o))}
                                        >
                                            <span className="flex items-center gap-2 text-sm"><Avatar id={o.client_id} name={o.client_name} /><span className="font-medium">{o.client_name}</span> · {o.medication_name}</span>
                                            <Button size="sm" onClick={(e) => { e.stopPropagation(); setModal({ type: 'dispense', order: o }); }}><Package className="h-4 w-4" />Record dispensing</Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
                        </div>
                        {dispensed.length > 0 && (
                            <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                                <div className="border-b px-5 py-3.5 text-[15px] font-bold">Recently dispensed</div>
                                <table className="w-full text-sm">
                                    <thead><tr className="border-b text-left text-[11px] uppercase tracking-wide text-muted-foreground"><th className="px-5 py-2">Resident · Medication</th><th className="px-5 py-2">Pharmacy</th><th className="px-5 py-2">Batch</th><th className="px-5 py-2">Dispensed by</th></tr></thead>
                                    <tbody>
                                        {dispensed.map((o) => (
                                            <tr
                                                key={o.id}
                                                tabIndex={0}
                                                aria-label={`Dispensed: ${o.medication_name ?? 'medication'} for ${o.client_name}. Open detail.`}
                                                className="cursor-pointer border-b last:border-b-0 hover:bg-muted/40 focus:bg-muted/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                                                onClick={() => openDetail(o)}
                                                onContextMenu={(e) => openRowCtx(e, o)}
                                                onKeyDown={rowKey(() => openDetail(o))}
                                            >
                                                <td className="px-5 py-2.5"><span className="font-medium">{o.client_name}</span> · {o.medication_name}</td>
                                                <td className="px-5 py-2.5 text-muted-foreground">{o.pharmacy_name ?? '—'}</td>
                                                <td className="px-5 py-2.5 text-muted-foreground">{o.batch_number ?? '—'}</td>
                                                <td className="px-5 py-2.5 text-muted-foreground">{o.dispensed_by_name ?? '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {activeTab === 'covert' && (
                    <div className="flex flex-col gap-4">
                        <div className="flex items-start gap-2 rounded-xl border border-status-critical/30 bg-status-critical-bg/50 px-4 py-3 text-sm text-status-critical">
                            <AlertTriangle className="mt-0.5 h-4 w-4 shrink-0" />
                            <span>Covert administration is a restrictive practice requiring capacity assessment, a best-interest MDT decision, pharmacist advice, and regular review. Medication must always be offered overtly first.</span>
                        </div>
                        {covertFiltered.length === 0 ? <EmptyState icon={ShieldCheck} message="No active covert authorisations." cta={{ label: 'New covert authorisation', onClick: () => setModal({ type: 'covert' }) }} /> : (
                            <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
                                {covertFiltered.map((c) => (
                                    <div key={c.id} className="flex flex-col gap-3 rounded-2xl border bg-card p-4 shadow-sm" onContextMenu={(e) => openCovertCtx(e, c)}>
                                        <div className="flex items-center justify-between">
                                            <span className="flex items-center gap-2 font-medium"><Avatar id={c.client_id} name={c.client_name} />{c.client_name}</span>
                                            <Pill2 label="Active" tone="bg-status-critical-bg text-status-critical" />
                                        </div>
                                        <div className="text-sm font-medium">{c.medication_name}</div>
                                        {c.administration_method && <div className="rounded-lg border bg-background px-3 py-2 text-xs"><span className="font-medium">Method:</span> {c.administration_method}</div>}
                                        <div className="text-xs text-muted-foreground">Authorised by {c.authorised_by_name} · {c.authorised_date}</div>
                                        <div className={`text-xs ${c.review_overdue ? 'font-medium text-status-critical' : 'text-muted-foreground'}`}>Next review: {c.review_date}{c.review_overdue ? ' · Overdue' : ''}</div>
                                        <Button variant="outline" className="mt-1 text-status-critical" onClick={() => revoke(c)}>Revoke authorisation</Button>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}

                {activeTab === 'activity' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="border-b px-5 py-3.5 text-[15px] font-bold">Order activity</div>
                        {activity.length === 0 ? <div className="px-5 py-10 text-center text-sm text-muted-foreground">No order activity matches these filters.</div> : (
                            <ul className="divide-y">
                                {activity.map((o) => (
                                    <li
                                        key={o.id}
                                        tabIndex={0}
                                        aria-label={`Activity: ${o.status} — ${o.medication_name ?? 'medication'} for ${o.client_name}. Open detail.`}
                                        className="flex cursor-pointer items-center justify-between px-5 py-3 text-sm hover:bg-muted/40 focus:bg-muted/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                                        onClick={() => openDetail(o)}
                                        onContextMenu={(e) => openRowCtx(e, o)}
                                        onKeyDown={rowKey(() => openDetail(o))}
                                    >
                                        <span className="flex items-center gap-2">
                                            <span className={`flex h-7 w-7 items-center justify-center rounded-full ${orderStatusTone(o.status)}`}><Pill className="h-3.5 w-3.5" /></span>
                                            <span><span className="font-medium capitalize">{o.status}</span> · {o.medication_name} <span className="text-muted-foreground">— {o.client_name}</span></span>
                                        </span>
                                        <span className="text-xs text-muted-foreground">{o.dispensed_at ? `dispensed` : o.countersigned_at ? 'signed' : o.order_date}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                )}
            </div>

            {modal?.type === 'order' && <NewOrderDialog clients={clients} staff={staff} onClose={() => setModal(null)} />}
            {modal?.type === 'covert' && <CovertDialog clients={clients} medications={medications} onClose={() => setModal(null)} />}
            {modal?.type === 'detail' && (
                <OrderDetailDialog
                    order={modal.order}
                    covert={covertFor(modal.order)}
                    linkedMedName={linkedMedName(modal.order)}
                    onClose={() => setModal(null)}
                    onCountersign={() => setModal({ type: 'countersign', order: modal.order })}
                    onDispense={() => setModal({ type: 'dispense', order: modal.order })}
                    onLink={() => setModal({ type: 'link', order: modal.order })}
                />
            )}
            {modal?.type === 'countersign' && <CountersignDialog order={modal.order} onClose={() => setModal(null)} />}
            {modal?.type === 'dispense' && <DispenseDialog order={modal.order} staff={staff} onClose={() => setModal(null)} />}
            {modal?.type === 'link' && <LinkMarDialog order={modal.order} medications={medications} onClose={() => setModal(null)} />}
            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}
