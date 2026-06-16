/* eslint-disable no-restricted-syntax -- the directory card/table + clickable rows are
   custom-layout surfaces (not Card/Button); all colours are semantic tokens. */
import { MED_TABS, matchesTab, type ClientOption, type MedCan, type MedRow } from '@/components/emar/medications/types';
import { PageHero, type PageHeroBadge, type PageHeroMetaItem, type PageHeroStat } from '@/components/page';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import {
    AddMedicationDialog,
    DiscontinueDialog,
    EditMedicationDialog,
    ImportCsvDialog,
    InteractionsDialog,
    MedicationDetailDialog,
    RejectOrderDialog,
} from '@/pages/emar/_dialogs';
import { Head, router } from '@inertiajs/react';
import { Activity, AlertTriangle, BadgeCheck, Ban, Clock, Eye, FileText, FileUp, Flame, Layers, Package, Pencil, Pill, Plus, Search, Shield, User, Users, X } from 'lucide-react';
import { useMemo, useState, type MouseEvent as ReactMouseEvent } from 'react';

type Modal =
    | { type: 'add' }
    | { type: 'import' }
    | { type: 'interactions' }
    | { type: 'detail' | 'edit' | 'discontinue' | 'reject'; med: MedRow }
    | null;

type Props = {
    medications: MedRow[];
    clients: ClientOption[];
    staff: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
    witnesses: { id: number; name: string }[];
    can: MedCan;
};

const TAB_ICONS: Record<string, typeof Layers> = {
    all: Layers,
    active: Activity,
    prn: Clock,
    controlled: Shield,
    high_risk: Flame,
    awaiting: BadgeCheck,
};

function hue(id: number): number {
    return Math.round((id * 137.508) % 360);
}
function initials(name: string): string {
    return name.split(/[\s,]+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('');
}

function StateBadge({ med }: { med: MedRow }) {
    const tone = med.state === 'active' ? 'bg-status-success-bg text-status-success' : med.state === 'paused' ? 'bg-status-warning-bg text-status-warning' : 'bg-muted text-muted-foreground';
    return (
        <div className="flex flex-col items-start gap-0.5">
            <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold capitalize ${tone}`}>{med.state}</span>
            {med.approval_status === 'pending_verification' && (
                <span className="rounded border border-status-warning/40 px-1.5 py-0.5 text-[10px] font-medium text-status-warning">Awaiting verification</span>
            )}
            {med.approval_status === 'rejected' && (
                <span className="rounded border border-status-critical/40 px-1.5 py-0.5 text-[10px] font-medium text-status-critical">Rejected</span>
            )}
        </div>
    );
}

function Flag({ label, tone }: { label: string; tone: string }) {
    return <span className={`rounded px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide ${tone}`}>{label}</span>;
}

/** Dismissible hero alert row — mirrors the /emar/controlled strip. The Review
 *  action jumps to the relevant tab/filter; the ✕ hides it for the session. */
function AlertStripRow({ tone, icon: Icon, message, onReview, onDismiss }: { tone: 'warning' | 'critical'; icon: typeof Layers; message: string; onReview: () => void; onDismiss: () => void }) {
    const toneClass =
        tone === 'critical'
            ? 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical'
            : 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning';
    return (
        <div className={`flex items-center justify-between gap-3 rounded-xl border px-4 py-3 ${toneClass}`}>
            <span className="flex items-center gap-2 text-sm font-medium">
                <Icon className="h-4 w-4" />
                {message}
            </span>
            <span className="flex items-center gap-1.5">
                <Button size="sm" variant="outline" onClick={onReview}>
                    Review
                </Button>
                <Button size="icon" variant="ghost" className="h-7 w-7" aria-label="Dismiss alert" onClick={onDismiss}>
                    <X className="h-4 w-4" />
                </Button>
            </span>
        </div>
    );
}

export default function Medications(props: Props) {
    const { medications, clients, sites, active_site: activeSite, site_brand_colour: brandColour, can } = props;

    const [activeTab, setActiveTab] = useState('all');
    const [search, setSearch] = useState('');
    const [clientFilter, setClientFilter] = useState<number | null>(null);
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [sort, setSort] = useState<'medication' | 'client' | 'stock'>('medication');
    const [lowStockOnly, setLowStockOnly] = useState(false);
    const [modal, setModal] = useState<Modal>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    // Per-session dismissal of the hero alert strip (resets on reload).
    const [alertDismissed, setAlertDismissed] = useState<{ awaiting: boolean; lowStock: boolean }>({ awaiting: false, lowStock: false });

    const counts = useMemo(
        () => ({
            active: medications.filter((m) => m.state === 'active').length,
            prn: medications.filter((m) => m.is_prn).length,
            controlled: medications.filter((m) => m.controlled_drug).length,
            awaiting: medications.filter((m) => m.approval_status === 'pending_verification').length,
            lowStock: medications.filter((m) => m.stock?.low).length,
            clients: new Set(medications.map((m) => m.client_id)).size,
        }),
        [medications],
    );

    const visible = useMemo(() => {
        const q = search.toLowerCase();
        const list = medications.filter((m) => {
            if (!matchesTab(m, activeTab)) return false;
            if (clientFilter != null && m.client_id !== clientFilter) return false;
            if (lowStockOnly && !m.stock?.low) return false;
            if (q && !`${m.name} ${m.brand_name ?? ''} ${m.client_name}`.toLowerCase().includes(q)) return false;
            return true;
        });
        list.sort((a, b) => {
            if (sort === 'client') return a.client_name.localeCompare(b.client_name);
            if (sort === 'stock') return Number(a.stock?.on_hand ?? Infinity) - Number(b.stock?.on_hand ?? Infinity);
            return a.name.localeCompare(b.name);
        });
        return list;
    }, [medications, activeTab, clientFilter, search, sort, lowStockOnly]);

    const TABS: RosterTabItem[] = MED_TABS.map((t) => ({
        id: t.id,
        label: t.label,
        icon: TAB_ICONS[t.id] ?? Layers,
        tone: t.tone,
        badge: medications.filter((m) => matchesTab(m, t.id)).length || undefined,
    }));

    const heroMeta: PageHeroMetaItem[] = [{ icon: Users, label: `${counts.clients} client${counts.clients === 1 ? '' : 's'}` }, { icon: Package, label: `${medications.length} orders` }];

    const heroBadges: PageHeroBadge[] = [
        counts.awaiting > 0 ? { tone: 'warning' as const, label: `${counts.awaiting} to verify` } : null,
        counts.lowStock > 0 ? { tone: 'critical' as const, label: `${counts.lowStock} low on stock` } : null,
    ].filter(Boolean) as PageHeroBadge[];

    const heroStats: PageHeroStat[] = [
        { label: 'Active', value: counts.active },
        { label: 'PRN', value: counts.prn },
        { label: 'Controlled', value: counts.controlled },
        { label: 'To verify', value: counts.awaiting, tone: counts.awaiting > 0 ? 'warning' : 'neutral' },
    ];

    const verify = (med: MedRow) => {
        router.post(`/emar/medications/${med.id}/verify`, {}, { preserveScroll: true });
        setModal(null);
    };

    // Right-click row menu — mirrors PRN's openRowCtx (PrnRecords.tsx). In-page
    // actions (detail/edit/verify/reject/discontinue) open modals; only View
    // client / Open on MAR / View stock navigate off-page.
    const openRowCtx = (e: ReactMouseEvent, m: MedRow) => {
        e.preventDefault();
        const pending = m.approval_status === 'pending_verification';
        const dose = [m.dosage, m.dose_unit].filter(Boolean).join(' ');
        const tag = pending
            ? { label: 'Awaiting', bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' }
            : m.approval_status === 'rejected'
              ? { label: 'Rejected', bg: 'var(--status-critical-bg)', color: 'var(--status-critical)' }
              : m.state === 'active'
                ? { label: 'Active', bg: 'var(--status-success-bg)', color: 'var(--status-success)' }
                : m.state === 'paused'
                  ? { label: 'Paused', bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' }
                  : { label: m.state, bg: 'var(--muted)', color: 'var(--muted-foreground)' };
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View details', sub: dose || 'Order detail', tone: 'primary', onClick: () => setModal({ type: 'detail', med: m }) },
            { icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit order', onClick: () => setModal({ type: 'edit', med: m }) },
            ...(pending && can.verify_orders
                ? [
                      { icon: <BadgeCheck className="h-3.5 w-3.5" />, label: 'Verify order', tone: 'primary', onClick: () => verify(m) } satisfies ShiftCtxItem,
                      { icon: <Ban className="h-3.5 w-3.5" />, label: 'Reject order', tone: 'critical', onClick: () => setModal({ type: 'reject', med: m }) } satisfies ShiftCtxItem,
                  ]
                : []),
            { icon: <FileText className="h-3.5 w-3.5" />, label: 'Open on MAR chart', onClick: () => router.visit(`/emar/mar?client_id=${m.client_id}`) },
            { sep: true },
            { icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => router.visit(`/operations/clients/${m.client_id}/care`) },
            { icon: <Package className="h-3.5 w-3.5" />, label: 'View stock', onClick: () => router.visit('/emar/stock') },
            ...(m.state === 'active'
                ? [
                      { sep: true } satisfies ShiftCtxItem,
                      { icon: <Ban className="h-3.5 w-3.5" />, label: 'Discontinue', tone: 'critical', onClick: () => setModal({ type: 'discontinue', med: m }) } satisfies ShiftCtxItem,
                  ]
                : []),
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: tag.label, tagBg: tag.bg, tagColor: tag.color, meta: `${m.client_name} · ${m.name}${dose ? ` · ${dose}` : ''}`, items });
    };

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Medications', href: '/emar/medications' }]}>
            <Head title="Medications Database" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Pill}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Medication register
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                The medication register for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description={`${medications.length} medications across ${counts.clients} clients. ${counts.awaiting} awaiting prescriber verification and ${counts.lowStock} low on stock.`}
                    meta={heroMeta}
                    badges={heroBadges}
                    stats={heroStats}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'add' })}>
                                <Plus className="h-4 w-4" />
                                Add medication
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setModal({ type: 'import' })}>
                                <FileUp className="h-4 w-4" />
                                Import
                            </Button>
                        </>
                    }
                    footer={
                        <div className="flex flex-wrap items-center justify-end gap-2 py-3">
                            <div className="relative w-full max-w-xs md:w-[280px]">
                                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                {/* eslint-disable-next-line no-restricted-syntax -- white pill search on the dark hero (shared eMAR filter-row idiom, mirrors /emar/prn). */}
                                <input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search medication, brand or client…"
                                    aria-label="Search medications"
                                    className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-8 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
                                />
                                {search ? (
                                    // eslint-disable-next-line no-restricted-syntax -- inline clear affordance inside the pill search input.
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
                                <EntityFilter
                                    label="Site"
                                    allLabel="All sites"
                                    items={sites}
                                    value={siteFilter}
                                    onChange={(id) => {
                                        setSiteFilter(id);
                                        router.get('/emar/medications', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true });
                                    }}
                                    onDark
                                />
                            ) : null}
                            <EntityFilter
                                label="Client"
                                allLabel="All clients"
                                items={clients.map((c) => ({ id: c.id, name: `${c.last_name}, ${c.first_name}` }))}
                                value={clientFilter}
                                onChange={setClientFilter}
                                onDark
                            />
                        </div>
                    }
                />

                {(counts.awaiting > 0 && !alertDismissed.awaiting) || (counts.lowStock > 0 && !alertDismissed.lowStock) ? (
                    <div className="flex flex-col gap-2">
                        {counts.awaiting > 0 && !alertDismissed.awaiting && (
                            <AlertStripRow
                                tone="warning"
                                icon={BadgeCheck}
                                message={`${counts.awaiting} order${counts.awaiting === 1 ? '' : 's'} awaiting prescriber verification.`}
                                onReview={() => setActiveTab('awaiting')}
                                onDismiss={() => setAlertDismissed((d) => ({ ...d, awaiting: true }))}
                            />
                        )}
                        {counts.lowStock > 0 && !alertDismissed.lowStock && (
                            <AlertStripRow
                                tone="critical"
                                icon={AlertTriangle}
                                message={`${counts.lowStock} medication${counts.lowStock === 1 ? '' : 's'} low or out of stock.`}
                                onReview={() => {
                                    setLowStockOnly(true);
                                    setSort('stock');
                                    setActiveTab('all');
                                }}
                                onDismiss={() => setAlertDismissed((d) => ({ ...d, lowStock: true }))}
                            />
                        )}
                    </div>
                ) : null}

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Medication register views" />

                <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                    <div className="flex flex-wrap items-center gap-2.5 border-b p-3.5">
                        <span className="text-sm font-semibold">Medication register</span>
                        <Select value={sort} onValueChange={(v) => setSort(v as typeof sort)}>
                            <SelectTrigger className="h-9 w-44" aria-label="Sort medications">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                <SelectItem value="medication">Sort: Medication</SelectItem>
                                <SelectItem value="client">Sort: Client</SelectItem>
                                <SelectItem value="stock">Sort: Stock (low first)</SelectItem>
                            </SelectContent>
                        </Select>
                        {lowStockOnly && (
                            <button
                                type="button"
                                onClick={() => setLowStockOnly(false)}
                                className="inline-flex items-center gap-1.5 rounded-full border border-status-critical/40 bg-status-critical-bg px-3 py-1 text-xs font-semibold text-status-critical transition-colors hover:bg-status-critical-bg/70"
                                aria-label="Clear low-stock filter"
                            >
                                Low / out of stock
                                <X className="h-3 w-3" />
                            </button>
                        )}
                        <div className="ml-auto flex items-center gap-2">
                            <span className="text-xs text-muted-foreground">
                                {visible.length} of {medications.length}
                            </span>
                            <Button variant="outline" size="sm" onClick={() => setModal({ type: 'interactions' })}>
                                <AlertTriangle className="h-4 w-4" />
                                Interactions
                            </Button>
                        </div>
                    </div>

                    {visible.length === 0 ? (
                        <div className="flex flex-col items-center gap-3 px-5 py-16 text-center">
                            <span className="grid h-12 w-12 place-items-center rounded-full bg-muted text-muted-foreground">
                                <Pill className="h-6 w-6" />
                            </span>
                            <div className="text-sm font-medium">
                                {medications.length === 0 ? 'No medications in the register yet.' : 'No medications match these filters.'}
                            </div>
                            <p className="max-w-sm text-xs text-muted-foreground">
                                {medications.length === 0
                                    ? 'Chart the first medication order to start the register.'
                                    : 'Try clearing the search, site or client filters — or chart a new order.'}
                            </p>
                            <Button onClick={() => setModal({ type: 'add' })}>
                                <Plus className="h-4 w-4" />
                                Add medication
                            </Button>
                        </div>
                    ) : (
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[980px] text-sm">
                                <thead>
                                    <tr className="bg-muted text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                        <th className="px-4 py-2.5">Medication</th>
                                        <th className="px-4 py-2.5">Client</th>
                                        <th className="px-4 py-2.5">Dose</th>
                                        <th className="px-4 py-2.5">Frequency</th>
                                        <th className="px-4 py-2.5">Flags</th>
                                        <th className="px-4 py-2.5">State</th>
                                        <th className="px-4 py-2.5">Stock</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {visible.map((m) => (
                                        <tr
                                            key={m.id}
                                            tabIndex={0}
                                            className="cursor-pointer border-b last:border-b-0 hover:bg-muted/60 focus:bg-muted/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary"
                                            onClick={() => setModal({ type: 'detail', med: m })}
                                            onKeyDown={(e) => {
                                                if (e.key === 'Enter' || e.key === ' ') {
                                                    e.preventDefault();
                                                    setModal({ type: 'detail', med: m });
                                                }
                                            }}
                                            onContextMenu={(e) => openRowCtx(e, m)}
                                        >
                                            <td className="px-4 py-3">
                                                <div className="font-medium">{m.name}</div>
                                                <div className="truncate text-xs text-muted-foreground">{[m.brand_name, m.instructions].filter(Boolean).join(' · ')}</div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <span className="flex items-center gap-2">
                                                    <span className="flex h-6 w-6 items-center justify-center rounded-full text-[10px] font-bold text-primary-foreground" style={{ backgroundColor: `oklch(0.52 0.16 ${hue(m.client_id)})` }}>
                                                        {initials(m.client_name)}
                                                    </span>
                                                    {m.client_name}
                                                </span>
                                            </td>
                                            <td className="px-4 py-3 tabular-nums">{[m.dosage, m.dose_unit].filter(Boolean).join(' ') || '—'}</td>
                                            <td className="px-4 py-3 text-muted-foreground">{m.is_prn ? 'PRN' : m.frequency ?? '—'}</td>
                                            <td className="px-4 py-3">
                                                <div className="flex flex-wrap items-center gap-1">
                                                    {m.is_prn && <Flag label="PRN" tone="bg-muted text-muted-foreground" />}
                                                    {m.controlled_drug && <Flag label="CD" tone="bg-status-critical-bg text-status-critical" />}
                                                    {m.high_risk && <Flag label="High-risk" tone="bg-status-warning-bg text-status-warning" />}
                                                    {m.witness_required && <Flag label="Witness" tone="bg-status-info-bg text-status-info" />}
                                                    {m.interaction_severity && <AlertTriangle className="h-3.5 w-3.5 text-status-warning" />}
                                                </div>
                                            </td>
                                            <td className="px-4 py-3">
                                                <StateBadge med={m} />
                                            </td>
                                            <td className="px-4 py-3">
                                                {m.stock ? (
                                                    Number(m.stock.on_hand) === 0 ? (
                                                        <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">Out of stock</span>
                                                    ) : (
                                                        <span className={m.stock.low ? 'text-status-warning' : 'text-muted-foreground'}>
                                                            {m.stock.on_hand} {m.stock.unit}
                                                            {m.stock.low ? ' · low' : ''}
                                                        </span>
                                                    )
                                                ) : (
                                                    <span className="text-muted-foreground">—</span>
                                                )}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </div>
            </div>

            {/* Modals */}
            {modal?.type === 'add' && <AddMedicationDialog clients={clients} onClose={() => setModal(null)} />}
            {modal?.type === 'import' && <ImportCsvDialog onClose={() => setModal(null)} />}
            {modal?.type === 'interactions' && <InteractionsDialog medications={medications} onClose={() => setModal(null)} />}
            {modal?.type === 'edit' && <EditMedicationDialog medication={modal.med} onClose={() => setModal(null)} />}
            {modal?.type === 'discontinue' && <DiscontinueDialog medication={modal.med} onClose={() => setModal(null)} />}
            {modal?.type === 'reject' && <RejectOrderDialog medication={modal.med} onClose={() => setModal(null)} />}
            {modal?.type === 'detail' && (
                <MedicationDetailDialog
                    medication={modal.med}
                    canVerify={!!can.verify_orders}
                    onClose={() => setModal(null)}
                    onEdit={() => setModal({ type: 'edit', med: modal.med })}
                    onDiscontinue={() => setModal({ type: 'discontinue', med: modal.med })}
                    onReject={() => setModal({ type: 'reject', med: modal.med })}
                    onVerify={() => verify(modal.med)}
                />
            )}
            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}
