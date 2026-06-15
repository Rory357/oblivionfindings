/* eslint-disable no-restricted-syntax -- stock list/order/reconciliation surfaces are custom-layout
   bordered tables and chip buttons (not Card/Button); all colours are semantic tokens. */
import { StockDetailDialog, stockStatusPill, type OpenOrderSummary } from '@/components/emar/stock-detail-dialog';
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import {
    AdjustStockDialog,
    NewPharmacyOrderDialog,
    ReceiveStockDialog,
    StockCountDialog,
    type ClientOpt,
    type StaffOpt,
    type StockMed,
    type StockRow,
} from '@/pages/emar/_stock-dialogs';
import { Head, router } from '@inertiajs/react';
import { AlertOctagon, AlertTriangle, Barcode, CalendarX2, Check, ClipboardCheck, Clock, Eye, FileText, Package, Pencil, Plus, Search, ShieldCheck, ShoppingCart, Snowflake, Truck, User } from 'lucide-react';
import { useMemo, useState, type MouseEvent as ReactMouseEvent } from 'react';

type ControlledRegisterRow = {
    id: number;
    medication_id: number;
    medication_name: string | null;
    client_id: number | null;
    client_name: string;
    cd_class: string | null;
    register_balance: number;
    on_hand: number;
    unit: string;
    last_check_at: string | null;
    last_check_witness: string | null;
    discrepancy: number | null;
};
type OrderRow = {
    id: number;
    medication_id: number | null;
    client_name: string;
    medication_name: string | null;
    pharmacy_name: string | null;
    order_type: string | null;
    status: string;
    quantity_ordered: number | null;
    quantity_received: number | null;
    ordered_at: string | null;
    submitted_at: string | null;
    confirmed_at: string | null;
    dispensed_at: string | null;
    delivered_at: string | null;
};

type Props = {
    stockItems: StockRow[];
    lowStockCount: number;
    expiringCount: number;
    expiredCount: number;
    controlledRegister: ControlledRegisterRow[];
    pharmacyOrders: OrderRow[];
    clients: ClientOpt[];
    activeMedications: StockMed[];
    witnesses: StaffOpt[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal =
    | { type: 'order'; clientId?: number; medId?: number }
    | { type: 'receive'; medId?: number }
    | { type: 'count'; medId?: number; controlledOnly?: boolean }
    | { type: 'adjust'; item: StockRow }
    | { type: 'detail'; item: StockRow }
    | null;

const STAGES = ['draft', 'submitted', 'confirmed', 'dispensed', 'delivered'];
const STAGE_LABELS = ['Ordered', 'Submitted', 'Confirmed', 'Dispensed', 'Delivered'];
const NEXT_LABEL: Record<string, string> = { draft: 'Submit to pharmacy', submitted: 'Mark confirmed', confirmed: 'Mark dispensed', dispensed: 'Receive stock' };
const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' }) : '—');
const initials = (name: string) => name.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';

export default function StockManagement({ stockItems, lowStockCount, expiringCount, expiredCount, controlledRegister, pharmacyOrders, clients, activeMedications, witnesses, sites, active_site: activeSite, site_brand_colour: brandColour }: Props) {
    const [activeTab, setActiveTab] = useState('all');
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [chip, setChip] = useState<'all' | 'controlled' | 'cold_chain'>('all');
    const [modal, setModal] = useState<Modal>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    const openOrders = pharmacyOrders.filter((o) => o.status !== 'delivered');
    const cdDiscrepancies = controlledRegister.filter((r) => r.discrepancy !== null && r.discrepancy !== 0).length;

    const stockByMed = useMemo(() => new Map(stockItems.map((s) => [s.medication_id, s])), [stockItems]);
    const openOrderFor = (medId: number | null): OpenOrderSummary | null => {
        if (medId == null) return null;
        const o = openOrders.find((ord) => ord.medication_id === medId);
        return o ? { status: o.status, pharmacy_name: o.pharmacy_name, order_type: o.order_type, quantity_ordered: o.quantity_ordered, ordered_at: o.ordered_at } : null;
    };
    const runCount = (s: StockRow) => setModal({ type: 'count', medId: s.medication_id, controlledOnly: s.controlled });

    // Header tag colours for the row context menu — semantic tokens (CSS vars).
    const ctxTagStyle = (s: StockRow) => {
        if (s.is_expired) return { tag: 'Expired', tagBg: 'var(--status-critical-bg)', tagColor: 'var(--status-critical)' };
        if (s.is_low) return { tag: 'Low', tagBg: 'var(--status-warning-bg)', tagColor: 'var(--status-warning)' };
        if (s.is_expiring_soon) return { tag: 'Expiring', tagBg: 'var(--status-warning-bg)', tagColor: 'var(--status-warning)' };
        return { tag: 'OK', tagBg: 'var(--status-success-bg)', tagColor: 'var(--status-success)' };
    };

    const openStockCtx = (e: ReactMouseEvent, s: StockRow) => {
        e.preventDefault();
        const order = openOrderFor(s.medication_id);
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View details', sub: `${s.medication_name ?? 'Stock'} · ${s.on_hand} ${s.unit}`, tone: 'primary', onClick: () => setModal({ type: 'detail', item: s }) },
            { icon: <Pencil className="h-3.5 w-3.5" />, label: 'Adjust stock', onClick: () => setModal({ type: 'adjust', item: s }) },
            { icon: <ClipboardCheck className="h-3.5 w-3.5" />, label: s.controlled ? 'Run CD balance check' : 'Run count', onClick: () => runCount(s) },
            { icon: <ShoppingCart className="h-3.5 w-3.5" />, label: 'Order more', onClick: () => setModal({ type: 'order', clientId: s.client_id ?? undefined, medId: s.medication_id }) },
            ...(order ? [{ icon: <Truck className="h-3.5 w-3.5" />, label: 'Receive against order', onClick: () => setModal({ type: 'receive', medId: s.medication_id }) } satisfies ShiftCtxItem] : []),
            { sep: true },
            ...(s.client_id ? [{ icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => router.visit(`/operations/clients/${s.client_id}/care`) } satisfies ShiftCtxItem] : []),
            ...(s.mar_url ? [{ icon: <FileText className="h-3.5 w-3.5" />, label: 'Open on MAR', onClick: () => router.visit(s.mar_url!) } satisfies ShiftCtxItem] : []),
            ...(s.is_expired ? [{ sep: true } satisfies ShiftCtxItem, { icon: <AlertOctagon className="h-3.5 w-3.5" />, label: 'Mark expired / quarantine', sub: 'Adjust out & record reason', tone: 'critical', onClick: () => setModal({ type: 'adjust', item: s }) } satisfies ShiftCtxItem] : []),
        ];
        const t = ctxTagStyle(s);
        setCtx({ x: e.clientX, y: e.clientY, tag: t.tag, tagBg: t.tagBg, tagColor: t.tagColor, meta: `${s.client_name} · ${s.medication_name ?? '—'} · ${s.on_hand} ${s.unit}`, items });
    };

    const openCdCtx = (e: ReactMouseEvent, r: ControlledRegisterRow) => {
        e.preventDefault();
        const s = stockByMed.get(r.medication_id);
        const reconciled = r.discrepancy === null || r.discrepancy === 0;
        const items: ShiftCtxItem[] = [
            ...(s ? [{ icon: <Eye className="h-3.5 w-3.5" />, label: 'View details', sub: `${r.medication_name ?? 'CD'} · ${r.register_balance} ${r.unit}`, tone: 'primary', onClick: () => setModal({ type: 'detail', item: s }) } satisfies ShiftCtxItem] : []),
            { icon: <ShieldCheck className="h-3.5 w-3.5" />, label: 'Record CD balance check', onClick: () => setModal({ type: 'count', medId: r.medication_id, controlledOnly: true }) },
            ...(s ? [{ icon: <Pencil className="h-3.5 w-3.5" />, label: 'Adjust stock', onClick: () => setModal({ type: 'adjust', item: s }) } satisfies ShiftCtxItem] : []),
            ...(s ? [{ icon: <ShoppingCart className="h-3.5 w-3.5" />, label: 'Order more', onClick: () => setModal({ type: 'order', clientId: r.client_id ?? undefined, medId: r.medication_id }) } satisfies ShiftCtxItem] : []),
            { sep: true },
            ...(r.client_id ? [{ icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => router.visit(`/operations/clients/${r.client_id}/care`) } satisfies ShiftCtxItem] : []),
            { icon: <FileText className="h-3.5 w-3.5" />, label: 'Open CD register', onClick: () => router.visit('/emar/controlled') },
            ...(!reconciled ? [{ sep: true } satisfies ShiftCtxItem, { icon: <AlertOctagon className="h-3.5 w-3.5" />, label: 'Investigate discrepancy', tone: 'critical', onClick: () => router.visit('/emar/controlled') } satisfies ShiftCtxItem] : []),
        ];
        const tag = reconciled
            ? { tag: 'CD OK', tagBg: 'var(--status-success-bg)', tagColor: 'var(--status-success)' }
            : { tag: 'CD discrepancy', tagBg: 'var(--status-critical-bg)', tagColor: 'var(--status-critical)' };
        setCtx({ x: e.clientX, y: e.clientY, tag: tag.tag, tagBg: tag.tagBg, tagColor: tag.tagColor, meta: `${r.client_name} · ${r.medication_name ?? 'CD'} · ${r.register_balance} ${r.unit}`, items });
    };

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        return stockItems.filter((s) => {
            if (activeTab === 'low' && !s.is_low) return false;
            if (activeTab === 'expiring' && !s.is_expiring_soon) return false;
            if (activeTab === 'expired' && !s.is_expired) return false;
            if (chip === 'controlled' && !s.controlled) return false;
            if (chip === 'cold_chain' && !s.requires_cold_chain) return false;
            if (q && !`${s.medication_name ?? ''} ${s.client_name} ${s.batch_number ?? ''}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [stockItems, activeTab, chip, search]);

    const byClient = useMemo(() => {
        const groups = new Map<number, { client_id: number; client_name: string; site_name: string | null; rows: StockRow[] }>();
        filtered.forEach((s) => {
            const key = s.client_id ?? 0;
            if (!groups.has(key)) groups.set(key, { client_id: key, client_name: s.client_name || 'Unknown', site_name: s.site_name, rows: [] });
            groups.get(key)!.rows.push(s);
        });
        return [...groups.values()].sort((a, b) => a.client_name.localeCompare(b.client_name));
    }, [filtered]);

    const advance = (id: number) => router.post(`/emar/stock/pharmacy-orders/${id}/advance`, {}, { preserveScroll: true, only: ['pharmacyOrders', 'stockItems', 'lowStockCount', 'expiringCount', 'expiredCount'] });
    const onSite = (id: number | null) => { setSiteFilter(id); router.get('/emar/stock', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); };

    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All stock', icon: Package, tone: 'primary', badge: stockItems.length || undefined },
        { id: 'low', label: 'Low stock', icon: AlertTriangle, tone: 'warning', badge: lowStockCount || undefined },
        { id: 'expiring', label: 'Expiring', icon: Clock, tone: 'warning', badge: expiringCount || undefined },
        { id: 'expired', label: 'Expired', icon: CalendarX2, tone: 'critical', badge: expiredCount || undefined },
        { id: 'controlled', label: 'Controlled drugs', icon: ShieldCheck, tone: 'primary', badge: controlledRegister.length || undefined },
        { id: 'orders', label: 'Pharmacy orders', icon: ShoppingCart, tone: 'info', badge: openOrders.length || undefined },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Tracked', value: stockItems.length },
        { label: 'Low', value: lowStockCount, tone: lowStockCount > 0 ? 'warning' : 'neutral' },
        { label: 'Expiring', value: expiringCount, tone: expiringCount > 0 ? 'warning' : 'neutral' },
        { label: 'Orders', value: openOrders.length },
    ];

    const description = `${stockItems.length} item${stockItems.length === 1 ? '' : 's'} tracked${activeSite ? ` at ${activeSite.name}` : ' across your services'}. ${lowStockCount} below reorder level, ${expiringCount} expiring within 30 days${cdDiscrepancies > 0 ? `, and ${cdDiscrepancies} controlled-drug count${cdDiscrepancies === 1 ? '' : 's'} needs investigation` : ''}.`;

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Stock Management', href: '/emar/stock' }]}>
            <Head title="eMAR - Stock Management" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Package}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Live stock board · live
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Medication stock for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description={description}
                    stats={heroStats}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'order' })}>
                                <Plus className="h-4 w-4" />
                                New pharmacy order
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setModal({ type: 'receive' })}>
                                <Truck className="h-4 w-4" />
                                Receive stock
                            </Button>
                        </>
                    }
                    footer={
                        <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="flex flex-wrap items-center gap-2">
                                {([['all', 'All items'], ['controlled', 'Controlled only'], ['cold_chain', 'Cold chain']] as const).map(([id, label]) => (
                                    <button key={id} onClick={() => setChip(id)} className={`rounded-full px-3 py-1 text-xs font-medium transition ${chip === id ? 'bg-primary-foreground text-primary' : 'border border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20'}`}>
                                        {label}
                                    </button>
                                ))}
                                <Button size="sm" variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setModal({ type: 'count' })}>
                                    <Barcode className="h-3.5 w-3.5" />
                                    Run stock count
                                </Button>
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex items-center gap-2 rounded-full bg-primary-foreground px-3 py-1.5">
                                    <Search className="h-3.5 w-3.5 text-muted-foreground" />
                                    <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search medication, client or batch…" className="w-56 bg-transparent text-sm text-foreground outline-none placeholder:text-muted-foreground" />
                                </div>
                                {sites.length > 0 && <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />}
                            </div>
                        </div>
                    }
                />

                {cdDiscrepancies > 0 && (
                    <div className="flex items-center justify-between gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/60 px-4 py-3">
                        <span className="flex items-center gap-2 text-sm font-medium text-status-critical">
                            <AlertTriangle className="h-4 w-4" />
                            {cdDiscrepancies} controlled-drug count{cdDiscrepancies === 1 ? '' : 's'} with an unreconciled balance — investigate before close of shift.
                        </span>
                        <Button size="sm" variant="outline" onClick={() => setActiveTab('controlled')}>Review</Button>
                    </div>
                )}

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Stock views" />

                {['all', 'low', 'expiring', 'expired'].includes(activeTab) && (
                    byClient.length === 0 ? (
                        <div className="rounded-2xl border border-dashed bg-card px-5 py-12 text-center text-sm text-muted-foreground">No stock matches the current filters.</div>
                    ) : (
                        <div className="flex flex-col gap-4">
                            {byClient.map((g) => (
                                <div key={g.client_id} className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                                    <div className="flex items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3">
                                        {g.client_id ? (
                                            <button
                                                type="button"
                                                onClick={() => router.visit(`/operations/clients/${g.client_id}/care`)}
                                                className="group flex items-center gap-3 rounded-lg text-left focus:outline-none focus-visible:ring-2 focus-visible:ring-primary/40"
                                                title={`Open ${g.client_name}'s care profile`}
                                            >
                                                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(g.client_name)}</span>
                                                <div>
                                                    <div className="text-sm font-semibold group-hover:underline">{g.client_name}</div>
                                                    <div className="text-xs text-muted-foreground">{[g.site_name, `${g.rows.length} item${g.rows.length === 1 ? '' : 's'}`].filter(Boolean).join(' · ')}</div>
                                                </div>
                                            </button>
                                        ) : (
                                            <div className="flex items-center gap-3">
                                                <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(g.client_name)}</span>
                                                <div>
                                                    <div className="text-sm font-semibold">{g.client_name}</div>
                                                    <div className="text-xs text-muted-foreground">{[g.site_name, `${g.rows.length} item${g.rows.length === 1 ? '' : 's'}`].filter(Boolean).join(' · ')}</div>
                                                </div>
                                            </div>
                                        )}
                                        <Button size="sm" variant="outline" onClick={() => setModal({ type: 'order', clientId: g.client_id })}><ShoppingCart className="h-3.5 w-3.5" />Order</Button>
                                    </div>
                                    <div className="overflow-x-auto">
                                        <table className="w-full min-w-[760px] text-sm">
                                            <thead>
                                                <tr className="bg-muted/50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                                    <th className="px-4 py-2.5">Medication</th>
                                                    <th className="px-4 py-2.5">Batch · expiry</th>
                                                    <th className="px-4 py-2.5">On hand</th>
                                                    <th className="px-4 py-2.5">Reorder at</th>
                                                    <th className="px-4 py-2.5">Status</th>
                                                    <th className="px-4 py-2.5 text-right">Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {g.rows.map((s) => <StockRowView key={s.id} s={s} onView={() => setModal({ type: 'detail', item: s })} onCount={() => runCount(s)} onAdjust={() => setModal({ type: 'adjust', item: s })} onCtx={(e) => openStockCtx(e, s)} />)}
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            ))}
                        </div>
                    )
                )}

                {activeTab === 'controlled' && (
                    <div className="flex flex-col gap-4">
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg/50 px-4 py-3">
                            <span className="text-sm text-status-critical">Controlled-drug register — running-balance reconciliation. Any non-zero discrepancy must be investigated and witnessed before close of shift.</span>
                            <Button size="sm" onClick={() => setModal({ type: 'count', controlledOnly: true })}><ShieldCheck className="h-3.5 w-3.5" />Record CD balance check</Button>
                        </div>
                        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                            {controlledRegister.length === 0 ? (
                                <div className="px-5 py-12 text-center text-sm text-muted-foreground">No controlled drugs in stock.</div>
                            ) : (
                                <div className="overflow-x-auto">
                                    <table className="w-full min-w-[760px] text-sm">
                                        <thead>
                                            <tr className="bg-muted text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                                <th className="px-4 py-2.5">Medication</th>
                                                <th className="px-4 py-2.5">Client</th>
                                                <th className="px-4 py-2.5">Register balance</th>
                                                <th className="px-4 py-2.5">Last witnessed check</th>
                                                <th className="px-4 py-2.5">Reconciliation</th>
                                                <th className="px-4 py-2.5 text-right">Actions</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {controlledRegister.map((r) => {
                                                const reconciled = r.discrepancy === null || r.discrepancy === 0;
                                                const detail = stockByMed.get(r.medication_id);
                                                const openDetail = () => detail && setModal({ type: 'detail', item: detail });
                                                return (
                                                    <tr
                                                        key={r.id}
                                                        onClick={openDetail}
                                                        onContextMenu={(e) => openCdCtx(e, r)}
                                                        onKeyDown={(e) => { if (detail && (e.key === 'Enter' || e.key === ' ')) { e.preventDefault(); openDetail(); } }}
                                                        tabIndex={detail ? 0 : undefined}
                                                        role={detail ? 'button' : undefined}
                                                        aria-label={detail ? `View ${r.medication_name ?? 'controlled drug'} details for ${r.client_name}` : undefined}
                                                        className={`border-b transition-colors last:border-b-0 ${detail ? 'cursor-pointer hover:bg-muted/40 focus:bg-muted/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/40' : ''}`}
                                                    >
                                                        <td className="px-4 py-3 font-medium">{r.medication_name}{r.cd_class && <span className="ml-2 rounded-full bg-status-critical-bg px-2 py-0.5 text-[10px] font-semibold text-status-critical">Class {r.cd_class}</span>}</td>
                                                        <td className="px-4 py-3 text-muted-foreground">{r.client_name}</td>
                                                        <td className="px-4 py-3 font-mono font-semibold tabular-nums">{r.register_balance} {r.unit}</td>
                                                        <td className="px-4 py-3 text-muted-foreground">{r.last_check_at ? `${fmtDate(r.last_check_at)}${r.last_check_witness ? ` · ${r.last_check_witness}` : ''}` : 'Never'}</td>
                                                        <td className="px-4 py-3">{reconciled ? <span className="rounded-full bg-status-success-bg px-2 py-0.5 text-[11px] font-semibold text-status-success">Reconciled</span> : <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">Discrepancy {r.discrepancy! > 0 ? '+' : ''}{r.discrepancy}</span>}</td>
                                                        <td className="px-4 py-3 text-right">
                                                            <div className="flex items-center justify-end gap-2">
                                                                <Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); setModal({ type: 'count', medId: r.medication_id, controlledOnly: true }); }}>Count</Button>
                                                                {!reconciled && <a href="/emar/controlled" onClick={(e) => e.stopPropagation()} className="text-xs font-medium text-status-critical underline">Investigate</a>}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                );
                                            })}
                                        </tbody>
                                    </table>
                                </div>
                            )}
                        </div>
                    </div>
                )}

                {activeTab === 'orders' && (
                    <div className="flex flex-col gap-4">
                        <div className="flex items-center justify-between">
                            <span className="text-sm text-muted-foreground">Tracking {openOrders.length} open order{openOrders.length === 1 ? '' : 's'}.</span>
                            <Button size="sm" onClick={() => setModal({ type: 'order' })}><Plus className="h-3.5 w-3.5" />New pharmacy order</Button>
                        </div>
                        {pharmacyOrders.length === 0 ? (
                            <div className="rounded-2xl border border-dashed bg-card px-5 py-12 text-center text-sm text-muted-foreground">No pharmacy orders.</div>
                        ) : (
                            pharmacyOrders.map((o) => <OrderCard key={o.id} o={o} onAdvance={() => advance(o.id)} />)
                        )}
                    </div>
                )}
            </div>

            {modal?.type === 'order' && <NewPharmacyOrderDialog clients={clients} medications={activeMedications} stockItems={stockItems} defaultClientId={modal.clientId} defaultMedId={modal.medId} onClose={() => setModal(null)} />}
            {modal?.type === 'receive' && <ReceiveStockDialog medications={activeMedications} defaultMedId={modal.medId} onClose={() => setModal(null)} />}
            {modal?.type === 'count' && <StockCountDialog medications={activeMedications} stockItems={stockItems} witnesses={witnesses} defaultMedId={modal.medId} controlledOnly={modal.controlledOnly} onClose={() => setModal(null)} />}
            {modal?.type === 'adjust' && <AdjustStockDialog item={modal.item} onClose={() => setModal(null)} />}
            {modal?.type === 'detail' && (
                <StockDetailDialog
                    item={modal.item}
                    openOrder={openOrderFor(modal.item.medication_id)}
                    onClose={() => setModal(null)}
                    onAdjust={() => setModal({ type: 'adjust', item: modal.item })}
                    onCount={() => runCount(modal.item)}
                    onOrder={() => setModal({ type: 'order', clientId: modal.item.client_id ?? undefined, medId: modal.item.medication_id })}
                />
            )}

            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}

function StockRowView({ s, onView, onCount, onAdjust, onCtx }: { s: StockRow; onView: () => void; onCount: () => void; onAdjust: () => void; onCtx: (e: ReactMouseEvent) => void }) {
    const reorder = s.reorder_level ?? 0;
    const ratio = reorder > 0 ? Math.min(100, (s.on_hand / (reorder * 2)) * 100) : 100;
    const barTone = s.is_low ? 'bg-status-critical' : s.on_hand <= reorder * 1.4 ? 'bg-status-warning' : 'bg-status-success';
    const statusPill = s.is_expired
        ? { label: 'Expired', cls: 'bg-status-critical-bg text-status-critical' }
        : s.is_low
          ? { label: 'Reorder now', cls: 'bg-status-warning-bg text-status-warning' }
          : s.is_expiring_soon
            ? { label: 'Expiring', cls: 'bg-status-warning-bg text-status-warning' }
            : { label: 'In stock', cls: 'bg-status-success-bg text-status-success' };
    const expiryTone = s.is_expired ? 'text-status-critical' : s.is_expiring_soon ? 'text-status-warning' : 'text-muted-foreground';
    return (
        <tr
            onClick={onView}
            onContextMenu={onCtx}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onView(); } }}
            tabIndex={0}
            role="button"
            aria-label={`View ${s.medication_name ?? 'stock item'} details for ${s.client_name}`}
            className="cursor-pointer border-b transition-colors last:border-b-0 hover:bg-muted/40 focus:bg-muted/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary/40"
        >
            <td className="px-4 py-3">
                <div className="flex items-center gap-1.5 font-medium">
                    {s.medication_name}
                    {s.controlled && <span className="rounded-full bg-status-critical-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-critical">CD</span>}
                    {s.requires_cold_chain && <Snowflake className="h-3.5 w-3.5 text-status-info" aria-label="Cold chain" />}
                </div>
                {s.medication_dose && <div className="text-xs text-muted-foreground">{s.medication_dose}</div>}
            </td>
            <td className="px-4 py-3">
                <div className="font-mono text-xs">{s.batch_number ?? '—'}</div>
                {s.expiry_date && <div className={`text-xs ${expiryTone}`}>{new Date(s.expiry_date).toLocaleDateString('en-NZ')}{s.is_expiring_soon && !s.is_expired ? ' · FEFO' : ''}</div>}
            </td>
            <td className="px-4 py-3">
                <div className={`font-mono tabular-nums ${s.is_low ? 'font-semibold text-status-critical' : ''}`}>{s.on_hand} {s.unit}</div>
                <div className="mt-1 h-1.5 w-24 overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full ${barTone}`} style={{ width: `${ratio}%` }} /></div>
            </td>
            <td className="px-4 py-3 font-mono tabular-nums text-muted-foreground">{s.reorder_level ?? '—'}</td>
            <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${statusPill.cls}`}>{statusPill.label}</span></td>
            <td className="px-4 py-3">
                <div className="flex items-center justify-end gap-1">
                    <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); onCount(); }} title="Record count"><ClipboardCheck className="h-3.5 w-3.5" /></Button>
                    <Button size="sm" variant="ghost" onClick={(e) => { e.stopPropagation(); onAdjust(); }} title="Adjust / edit"><Pencil className="h-3.5 w-3.5" /></Button>
                </div>
            </td>
        </tr>
    );
}

function OrderCard({ o, onAdvance }: { o: OrderRow; onAdvance: () => void }) {
    const stageIndex = Math.max(0, STAGES.indexOf(o.status));
    const typeTone = o.order_type === 'urgent' ? 'bg-status-critical-bg text-status-critical' : o.order_type === 'repeat' ? 'bg-primary/10 text-primary' : 'bg-muted text-muted-foreground';
    const nextLabel = NEXT_LABEL[o.status];
    const stageTimes = [o.ordered_at, o.submitted_at, o.confirmed_at, o.dispensed_at, o.delivered_at];
    return (
        <div className="rounded-2xl border bg-card p-4 shadow-sm">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <div className="flex items-center gap-2 font-semibold">{o.medication_name ?? '—'}{o.order_type && <span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold capitalize ${typeTone}`}>{o.order_type}</span>}</div>
                    <div className="text-xs text-muted-foreground">{o.client_name} · {o.pharmacy_name ?? 'pharmacy'} · ordered {fmtDate(o.ordered_at)}</div>
                </div>
                <div className="text-right text-sm">
                    <div className="font-mono font-semibold tabular-nums">{o.quantity_ordered ?? '—'} units</div>
                    {o.status === 'delivered' && o.quantity_received != null && <div className="text-xs text-status-success">{o.quantity_received} received</div>}
                </div>
            </div>
            <div className="mt-4 flex items-center">
                {STAGE_LABELS.map((label, i) => {
                    const done = i < stageIndex;
                    const current = i === stageIndex;
                    return (
                        <div key={label} className="flex flex-1 items-center last:flex-none">
                            <div className="flex flex-col items-center gap-1">
                                <span className={`flex h-6 w-6 items-center justify-center rounded-full text-[11px] font-semibold ${done ? 'bg-primary text-primary-foreground' : current ? 'border-2 border-primary bg-card text-primary' : 'border border-border bg-card text-muted-foreground'}`}>{done ? <Check className="h-3.5 w-3.5" /> : i + 1}</span>
                                <span className={`text-[10px] ${current ? 'font-semibold text-foreground' : 'text-muted-foreground'}`}>{label}</span>
                                <span className="text-[9px] text-muted-foreground">{fmtDate(stageTimes[i])}</span>
                            </div>
                            {i < STAGE_LABELS.length - 1 && <div className={`mx-1 h-0.5 flex-1 ${i < stageIndex ? 'bg-primary' : 'bg-border'}`} />}
                        </div>
                    );
                })}
            </div>
            {nextLabel && (
                <div className="mt-4 flex justify-end">
                    <Button size="sm" onClick={onAdvance}>{o.status === 'dispensed' ? <Truck className="h-3.5 w-3.5" /> : <Check className="h-3.5 w-3.5" />}{nextLabel}</Button>
                </div>
            )}
        </div>
    );
}
