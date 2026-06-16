/* eslint-disable no-restricted-syntax -- CD register tab tables/cards are custom-layout
   bordered surfaces (not Card/Button); all colours are semantic tokens. */
import { statusTone, type CdDestruction, type CdDiscrepancy, type CdEntry, type CdLossReport, type CdMedication, type ClientOption, type StaffOption } from '@/components/emar/controlled/types';
import { CdDetailDialog, type CdDetailSubject } from '@/components/emar/cd-detail-dialog';
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import { BalanceCheckDialog, CdPill, LossActionDialog, RecordCdEntryDialog, RecordDestructionDialog, ReportLossDialog, ResolveDiscrepancyDialog } from '@/pages/emar/_cd-dialogs';
import { DayPickerChip, addDays, parseYmd } from '@/components/meds/day-picker-chip';
import { useOfflineQueueState } from '@/hooks/use-offline-queue';
import { Head, router } from '@inertiajs/react';
import { Activity, AlertTriangle, ArrowUpRight, ChevronLeft, ChevronRight, ClipboardCheck, Eye, FileWarning, Lock, Package, Plus, Printer, Search, ShieldCheck, Trash2, User, X } from 'lucide-react';
import { useMemo, useState, type MouseEvent as ReactMouseEvent } from 'react';

type Props = {
    medications: CdMedication[];
    recentEntries: CdEntry[];
    discrepancies: CdDiscrepancy[];
    destructions: CdDestruction[];
    lossReports: CdLossReport[];
    staff: StaffOption[];
    clients: ClientOption[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
    date: string;
    today: string;
    is_today: boolean;
    date_label: string;
    client_id: number | null;
    q: string | null;
    current_user: { id: number; name: string } | null;
};

type Modal =
    | { type: 'entry' | 'balance' | 'loss' | 'destruction' }
    | { type: 'balanceMed'; medId: number }
    | { type: 'resolveDisc'; disc: CdDiscrepancy }
    | { type: 'lossAction'; report: CdLossReport; action: 'investigate' | 'resolve' }
    | { type: 'detail'; subject: CdDetailSubject }
    | null;

/** Context-menu tag colours (semantic token CSS vars), keyed by tone. */
const CTX_TAG: Record<'critical' | 'warning' | 'info' | 'success' | 'muted', { bg: string; color: string }> = {
    critical: { bg: 'var(--status-critical-bg)', color: 'var(--status-critical)' },
    warning: { bg: 'var(--status-warning-bg)', color: 'var(--status-warning)' },
    info: { bg: 'var(--status-info-bg)', color: 'var(--status-info)' },
    success: { bg: 'var(--status-success-bg)', color: 'var(--status-success)' },
    muted: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
};

/** Case-insensitive match of a query against a row's text fields. */
function matchq(q: string, ...parts: (string | null | undefined)[]): boolean {
    return !q || parts.filter(Boolean).join(' ').toLowerCase().includes(q);
}

type CdAlert = { kind: string; tone: 'critical' | 'warning'; icon: typeof AlertTriangle; message: string; tab: string };

const DISMISSED_ALERTS_KEY = 'cd-dismissed-alerts';

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
            window.sessionStorage.setItem(DISMISSED_ALERTS_KEY, JSON.stringify(unique));
        } catch {
            /* sessionStorage unavailable — dismissal stays in-memory only */
        }
    }
    return unique;
}

export default function ControlledDrugs(props: Props) {
    const { medications, recentEntries, discrepancies, destructions, lossReports, staff, clients, sites, active_site: activeSite, site_brand_colour: brandColour, date, today, is_today: isToday } = props;

    const [activeTab, setActiveTab] = useState('register');
    const [search, setSearch] = useState(props.q ?? '');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [clientFilter, setClientFilter] = useState<number | null>(props.client_id ?? null);
    const [modal, setModal] = useState<Modal>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    // Calendar + Site + Client round-trip to the server (the movement lists are
    // server-windowed to the selected day); the text search stays client-side over
    // the loaded rows so it filters every tab — including the always-current Register
    // and Reconciliation surfaces — without a reload. `over` keys set to undefined
    // are dropped from the query.
    const reload = (over: Record<string, string | number | undefined>) => {
        const params: Record<string, string | number | undefined> = {
            ...(siteFilter ? { site_id: siteFilter } : {}),
            ...(clientFilter ? { client_id: clientFilter } : {}),
            ...(date !== today ? { date } : {}),
            ...(search ? { q: search } : {}),
            ...over,
        };
        Object.keys(params).forEach((k) => params[k] === undefined && delete params[k]);
        router.get('/emar/controlled', params, { preserveState: true, preserveScroll: true });
    };
    const goDate = (ymd: string) => reload({ date: ymd === today ? undefined : ymd });
    const onSite = (id: number | null) => { setSiteFilter(id); reload({ site_id: id ?? undefined }); };
    const onClient = (id: number | null) => { setClientFilter(id); reload({ client_id: id ?? undefined }); };
    const stepLabel = (ymd: string) => parseYmd(ymd).toLocaleDateString('en-NZ', { weekday: 'short', day: 'numeric' });

    // Client-side text filter shared by every tab (client or controlled-drug name).
    const q = search.trim().toLowerCase();
    const medsF = useMemo(() => medications.filter((m) => matchq(q, m.name, m.client_name)), [medications, q]);
    const entriesF = useMemo(() => recentEntries.filter((e) => matchq(q, e.medication_name, e.client_name)), [recentEntries, q]);
    const discF = useMemo(() => discrepancies.filter((d) => matchq(q, d.medication?.name, d.client ? `${d.client.first_name} ${d.client.last_name}` : '')), [discrepancies, q]);
    const destF = useMemo(() => destructions.filter((d) => matchq(q, d.medication_name, d.client_name)), [destructions, q]);
    const lossF = useMemo(() => lossReports.filter((l) => matchq(q, l.medication_name, l.client ? `${l.client.first_name} ${l.client.last_name}` : '')), [lossReports, q]);

    // Reconciliation derives from the always-current per-med balance-check state the
    // server computes (decoupled from the day-scoped movement list).
    const reconciliation = useMemo(
        () => medsF.map((m) => ({ med: m, lastAt: m.last_balance_check_at ?? null, days: m.days_since_check ?? null, overdue: !!m.overdue_check })),
        [medsF],
    );

    const openLosses = lossReports.filter((l) => ['reported', 'investigating'].includes(l.investigation_status));
    const overdueChecks = medications.filter((m) => m.overdue_check).length;

    // Device sync state for the hero eyebrow (truthful "synced" badge). The shared
    // offline queue is global; CD wizards currently post directly via Inertia, so a
    // CD-specific pending count isn't surfaced — TODO(Gx) convergence (don't rewrite
    // the queue here). See docs/CONTROLLED_GAP_ANALYSIS.md (B5).
    const { online, pendingCount, syncing } = useOfflineQueueState();
    // Literal Tailwind classes (no dynamic interpolation — keeps the JIT scanner happy).
    const sync: { label: string; dotClass: string; pingClass: string | null } = !online
        ? { label: pendingCount > 0 ? `offline · ${pendingCount} queued` : 'offline', dotClass: 'bg-status-warning', pingClass: null }
        : syncing
          ? { label: pendingCount > 0 ? `syncing ${pendingCount}…` : 'syncing…', dotClass: 'bg-status-info', pingClass: 'bg-status-info/70' }
          : pendingCount > 0
            ? { label: `${pendingCount} queued to sync`, dotClass: 'bg-status-info', pingClass: null }
            : { label: 'synced', dotClass: 'bg-status-success', pingClass: 'bg-status-success/70' };

    // CDs at/below reorder level or expiring within 30 days (stock-risk alert → Register).
    const stockRisks = useMemo(
        () => medications.filter((m) => {
            const s = m.stock;
            if (!s) return false;
            const onHand = s.on_hand == null ? null : Number(s.on_hand);
            const lowStock = s.reorder_level != null && onHand != null && onHand <= Number(s.reorder_level);
            const expiring = !!s.expiry_date && (parseYmd(s.expiry_date).getTime() - Date.now()) / 86_400_000 <= 30;
            return lowStock || expiring;
        }),
        [medications],
    );

    // Stacked, dismissible (per session) alert strip built from already-loaded data.
    const [dismissed, setDismissed] = useState<string[]>(() => readDismissedAlerts());
    const dismiss = (kind: string) => setDismissed((prev) => persistDismissedAlerts([...prev, kind]));
    const alerts: CdAlert[] = [
        discrepancies.length > 0 && { kind: 'disc', tone: 'critical' as const, icon: AlertTriangle, message: `${discrepancies.length} open controlled-drug discrepanc${discrepancies.length === 1 ? 'y' : 'ies'} — investigate and resolve.`, tab: 'discrepancies' },
        openLosses.length > 0 && { kind: 'loss', tone: 'critical' as const, icon: FileWarning, message: `${openLosses.length} open loss investigation${openLosses.length === 1 ? '' : 's'} awaiting follow-up.`, tab: 'loss' },
        overdueChecks > 0 && { kind: 'overdue', tone: 'warning' as const, icon: ShieldCheck, message: `${overdueChecks} controlled drug${overdueChecks === 1 ? '' : 's'} overdue a balance check (≥ 7 days).`, tab: 'reconciliation' },
        stockRisks.length > 0 && { kind: 'stock', tone: 'warning' as const, icon: Package, message: `${stockRisks.length} controlled drug${stockRisks.length === 1 ? '' : 's'} at/below reorder level or expiring within 30 days.`, tab: 'register' },
    ].filter((a): a is CdAlert => Boolean(a) && !dismissed.includes((a as CdAlert).kind));

    // ── Row interactions (parity with PRN): click → read-only detail, right-click
    // → ShiftContextMenu, View client → care page. Shared across all 7 tabs. ──
    const medForEntry = (e: CdEntry) => medications.find((m) => m.client_id === e.client_id && m.name === e.medication_name);
    const openDetail = (subject: CdDetailSubject) => setModal({ type: 'detail', subject });
    const viewClient = (id: number | null | undefined) => id && router.visit(`/operations/clients/${id}?tab=mar`);
    const exportRegister = () => window.open('/emar/pdf/controlled-register', '_blank');

    /** Build + open the right-click menu for a row. `readOnly` (Audit tab) shows only
     * view/navigate actions — no record/resolve. */
    const openRowCtx = (event: ReactMouseEvent, subject: CdDetailSubject, readOnly = false) => {
        event.preventDefault();
        const clientId =
            subject.kind === 'medication' ? subject.med.client_id
            : subject.kind === 'entry' ? subject.entry.client_id
            : subject.kind === 'discrepancy' ? subject.disc.client?.id ?? null
            : subject.kind === 'destruction' ? subject.destruction.client_id ?? null
            : subject.loss.client?.id ?? null;
        const view: ShiftCtxItem = { icon: <Eye className="h-3.5 w-3.5" />, label: 'View details', tone: 'primary', onClick: () => openDetail(subject) };
        const nav: ShiftCtxItem[] = [
            { sep: true },
            ...(clientId ? [{ icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => viewClient(clientId) } satisfies ShiftCtxItem] : []),
            { icon: <Printer className="h-3.5 w-3.5" />, label: 'Export CD register', sub: 'PDF', onClick: exportRegister },
        ];

        let tone: keyof typeof CTX_TAG = 'muted';
        let tag = 'CD';
        let meta = '';
        const actions: ShiftCtxItem[] = [];
        const critical: ShiftCtxItem[] = [];

        if (subject.kind === 'medication' || subject.kind === 'entry') {
            const med = subject.kind === 'medication' ? subject.med : medForEntry(subject.entry);
            const drug = subject.kind === 'medication' ? subject.med.name : subject.entry.medication_name ?? 'CD';
            const client = subject.kind === 'medication' ? subject.med.client_name : subject.entry.client_name;
            if (subject.kind === 'medication') {
                tone = subject.med.overdue_check ? 'warning' : 'muted';
                tag = subject.med.controlled_drug ? 'CD' : 'MED';
                meta = `${client} · ${drug}${subject.med.stock ? ` · ${subject.med.stock.on_hand ?? '—'} ${subject.med.stock.unit ?? ''}`.trimEnd() : ''}`;
            } else {
                tone = 'info';
                tag = subject.entry.entry_type.replace(/_/g, ' ');
                meta = `${client} · ${drug} · ${subject.entry.on_hand_before ?? '—'}→${subject.entry.on_hand_after ?? '—'}`;
            }
            if (med) actions.push({ icon: <ClipboardCheck className="h-3.5 w-3.5" />, label: 'Check balance', onClick: () => setModal({ type: 'balanceMed', medId: med.id }) });
            actions.push({ icon: <Package className="h-3.5 w-3.5" />, label: 'Record movement', onClick: () => setModal({ type: 'entry' }) });
            actions.push({ icon: <Lock className="h-3.5 w-3.5" />, label: 'View full register for this CD', onClick: () => { setSearch(drug); setActiveTab('recent'); } });
            critical.push({ icon: <AlertTriangle className="h-3.5 w-3.5" />, label: 'Report discrepancy', tone: 'critical', onClick: () => setModal(med ? { type: 'balanceMed', medId: med.id } : { type: 'balance' }) });
            critical.push({ icon: <FileWarning className="h-3.5 w-3.5" />, label: 'Report loss', tone: 'critical', onClick: () => setModal({ type: 'loss' }) });
        } else if (subject.kind === 'discrepancy') {
            const d = subject.disc;
            tone = 'critical';
            tag = d.status;
            meta = `${d.client ? `${d.client.first_name} ${d.client.last_name}` : ''} · ${d.medication?.name ?? 'CD'} · diff ${d.difference ?? '—'}`;
            if (d.status !== 'closed' && d.status !== 'resolved') actions.push({ icon: <ShieldCheck className="h-3.5 w-3.5" />, label: 'Resolve discrepancy', onClick: () => setModal({ type: 'resolveDisc', disc: d }) });
        } else if (subject.kind === 'destruction') {
            const d = subject.destruction;
            tone = 'warning';
            tag = 'Destroyed';
            meta = `${d.client_name} · ${d.medication_name ?? 'CD'} · ${d.quantity ?? '—'} ${d.unit ?? ''}`.trimEnd();
            actions.push({ icon: <Trash2 className="h-3.5 w-3.5" />, label: 'Record destruction', onClick: () => setModal({ type: 'destruction' }) });
        } else {
            const l = subject.loss;
            tone = 'critical';
            tag = l.investigation_status;
            meta = `${l.client ? `${l.client.first_name} ${l.client.last_name}` : ''} · ${l.medication_name ?? 'CD'} · ${l.quantity_lost ?? '—'} lost`;
            if (l.investigation_status === 'reported') actions.push({ icon: <FileWarning className="h-3.5 w-3.5" />, label: 'Investigate', onClick: () => setModal({ type: 'lossAction', report: l, action: 'investigate' }) });
            if (l.investigation_status !== 'resolved') actions.push({ icon: <ShieldCheck className="h-3.5 w-3.5" />, label: 'Resolve', onClick: () => setModal({ type: 'lossAction', report: l, action: 'resolve' }) });
        }

        const items: ShiftCtxItem[] = readOnly
            ? [view, ...nav]
            : [view, ...actions, ...nav, ...(critical.length ? [{ sep: true } as ShiftCtxItem, ...critical] : [])];
        const t = CTX_TAG[tone];
        setCtx({ x: event.clientX, y: event.clientY, tag: tag.toUpperCase(), tagBg: t.bg, tagColor: t.color, meta, items });
    };

    // Shared interactivity (click → detail, right-click → menu). Rows/cards are
    // plain clickable surfaces with no interactive role/tabindex (matches PRN), so
    // their inner controls (Check balance / Resolve) aren't nested inside an
    // interactive ancestor — keeps the accessibility tree clean (no nested-interactive).
    const interactive = (subject: CdDetailSubject, readOnly = false) => ({
        onClick: () => openDetail(subject),
        onContextMenu: (e: ReactMouseEvent) => openRowCtx(e, subject, readOnly),
    });
    const rowProps = (subject: CdDetailSubject, readOnly = false) => ({
        ...interactive(subject, readOnly),
        className: 'cursor-pointer border-b transition-colors last:border-b-0 hover:bg-muted/40',
    });

    const TABS: RosterTabItem[] = [
        { id: 'register', label: 'Register', icon: Lock, tone: 'primary', badge: medications.length || undefined },
        { id: 'recent', label: 'Recent Entries', icon: Package, tone: 'info', badge: recentEntries.length || undefined },
        { id: 'reconciliation', label: 'Reconciliation', icon: ShieldCheck, tone: 'success', badge: overdueChecks || undefined },
        { id: 'discrepancies', label: 'Discrepancies', icon: AlertTriangle, tone: 'critical', badge: discrepancies.length || undefined },
        { id: 'destructions', label: 'Destructions', icon: Trash2, tone: 'warning', badge: destructions.length || undefined },
        { id: 'loss', label: 'Loss Reports', icon: FileWarning, tone: 'critical', badge: openLosses.length || undefined },
        { id: 'audit', label: 'Audit Trail', icon: Activity, tone: 'primary' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Active CDs', value: medications.length },
        { label: 'Open discrepancies', value: discrepancies.length, tone: discrepancies.length > 0 ? 'critical' : 'neutral' },
        { label: 'Overdue checks', value: overdueChecks, tone: overdueChecks > 0 ? 'warning' : 'neutral' },
        { label: 'Loss investigations', value: openLosses.length, tone: openLosses.length > 0 ? 'critical' : 'neutral' },
    ];

    // Per-tab primary create actions (Add-Client style) — reused in each panel
    // header and its empty-state CTA.
    const btnEntry = <Button size="sm" onClick={() => setModal({ type: 'entry' })}><Plus className="h-4 w-4" />Record CD entry</Button>;
    const btnBalance = <Button size="sm" onClick={() => setModal({ type: 'balance' })}><ClipboardCheck className="h-4 w-4" />Balance check</Button>;
    const btnReportDisc = <Button size="sm" onClick={() => setModal({ type: 'balance' })}><AlertTriangle className="h-4 w-4" />Report discrepancy</Button>;
    const btnDestruction = <Button size="sm" onClick={() => setModal({ type: 'destruction' })}><Trash2 className="h-4 w-4" />Record destruction</Button>;
    const btnLoss = <Button size="sm" onClick={() => setModal({ type: 'loss' })}><FileWarning className="h-4 w-4" />Report loss</Button>;

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Controlled Drugs', href: '/emar/controlled' }]}>
            <Head title="Controlled Drug Register" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={Lock}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    {sync.pingClass ? <span className={`absolute inset-0 animate-ping rounded-full ${sync.pingClass}`} /> : null}
                                    <span className={`relative inline-flex h-2 w-2 rounded-full ${sync.dotClass}`} />
                                </span>
                                Controlled drug register · {sync.label}
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                CD register for{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? 'your services'}</span>
                            </span>
                        </span>
                    }
                    description="Running balances, two-person witness, reconciliation, discrepancies, destructions and loss investigations — append-only and audit-ready."
                    stats={heroStats}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'entry' })}>
                                <Plus className="h-4 w-4" />
                                Record CD entry
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setModal({ type: 'balance' })}>
                                <ClipboardCheck className="h-4 w-4" />
                                Balance check
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => setModal({ type: 'loss' })}>
                                <FileWarning className="h-4 w-4" />
                                Report loss
                            </Button>
                        </>
                    }
                    footer={
                        <div className="flex flex-col items-stretch gap-2 py-3 md:flex-row md:items-center md:justify-between">
                            <div className="flex flex-wrap items-center gap-1.5">
                                {/* eslint-disable no-restricted-syntax -- segmented day-stepper on the dark hero; not a shadcn Button (rostering idiom). */}
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                                    onClick={() => goDate(addDays(date, -1))}
                                >
                                    <ChevronLeft className="h-3.5 w-3.5" />
                                    {stepLabel(addDays(date, -1))}
                                </button>
                                <DayPickerChip date={date} isToday={isToday} onPick={goDate} caption="Register & movements are for the selected day; stock & CD balance checks always show today." />
                                <button
                                    type="button"
                                    className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/20 bg-primary-foreground/10 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/20"
                                    onClick={() => goDate(addDays(date, 1))}
                                >
                                    {stepLabel(addDays(date, 1))}
                                    <ChevronRight className="h-3.5 w-3.5" />
                                </button>
                                {!isToday ? (
                                    <button
                                        type="button"
                                        className="inline-flex items-center gap-1 rounded-md border border-primary-foreground/35 bg-primary-foreground/20 px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary-foreground/30"
                                        onClick={() => goDate(today)}
                                    >
                                        Back to today
                                    </button>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-2 md:ml-auto md:justify-end">
                                <div className="relative w-full max-w-xs md:w-[260px]">
                                    <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                    {/* eslint-disable-next-line no-restricted-syntax -- white pill search on the dark hero per the design handoff. */}
                                    <input
                                        value={search}
                                        onChange={(e) => setSearch(e.target.value)}
                                        placeholder="Search client or controlled drug…"
                                        aria-label="Search controlled drug register"
                                        className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-3 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
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
                                    <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />
                                ) : null}
                                <EntityFilter
                                    label="Client"
                                    allLabel="All clients"
                                    items={clients.map((c) => ({ id: c.id, name: `${c.first_name} ${c.last_name}` }))}
                                    value={clientFilter}
                                    onChange={onClient}
                                    onDark
                                />
                            </div>
                        </div>
                    }
                />

                {alerts.length > 0 && (
                    <div className="flex flex-col gap-2">
                        {alerts.map((a) => (
                            <AlertRow key={a.kind} alert={a} onReview={() => setActiveTab(a.tab)} onDismiss={() => dismiss(a.kind)} />
                        ))}
                    </div>
                )}

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Controlled drug views" />

                {activeTab === 'register' && (
                    <TableCard head={['Client', 'Medication', 'On hand', 'Last checked', '']} title="Active controlled drugs" count={medications.length} action={btnEntry} cta={medications.length === 0 ? btnEntry : undefined} empty={medsF.length === 0 ? (medications.length === 0 ? 'No active controlled drugs.' : 'No controlled drugs match your search.') : null}>
                        {medsF.map((m) => {
                            const rec = reconciliation.find((r) => r.med.id === m.id);
                            return (
                                <tr key={m.id} {...rowProps({ kind: 'medication', med: m })}>
                                    <td className="px-4 py-3">{m.client_name}</td>
                                    <td className="px-4 py-3 font-medium"><DrugCell name={m.name} controlled={m.controlled_drug} schedule={m.schedule} /></td>
                                    <td className="px-4 py-3 tabular-nums"><div>{m.stock ? `${m.stock.on_hand ?? '—'} ${m.stock.unit ?? ''}` : '—'}</div><ExpiryNote value={m.stock?.expiry_date} /></td>
                                    <td className="px-4 py-3">{rec?.overdue ? <span className="text-status-warning">{rec.days === null ? 'Never' : `${rec.days}d ago`}</span> : <span className="text-muted-foreground">{rec?.days}d ago</span>}</td>
                                    <td className="px-4 py-3 text-right"><Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); setModal({ type: 'balanceMed', medId: m.id }); }}><ClipboardCheck className="h-3.5 w-3.5" />Check balance</Button></td>
                                </tr>
                            );
                        })}
                    </TableCard>
                )}

                {activeTab === 'recent' && (
                    <TableCard head={['Date', 'Client', 'Medication', 'Type', 'Qty', 'Balance', 'Recorded by', 'Witness']} title={`Movements · ${props.date_label}`} count={recentEntries.length} action={btnEntry} cta={recentEntries.length === 0 ? btnEntry : undefined} empty={entriesF.length === 0 ? (recentEntries.length === 0 ? `No register movements on ${props.date_label}.` : 'No movements match your search.') : null}>
                        {entriesF.map((e) => (
                            <tr key={e.id} {...rowProps({ kind: 'entry', entry: e, med: medForEntry(e) })}>
                                <td className="px-4 py-3 text-muted-foreground">{e.recorded_at ? new Date(e.recorded_at).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'}</td>
                                <td className="px-4 py-3">{e.client_name}</td>
                                <td className="px-4 py-3 font-medium"><DrugCell name={e.medication_name} controlled={e.controlled_drug ?? true} schedule={medForEntry(e)?.schedule} /><ExpiryNote value={e.expiry_date} /></td>
                                <td className="px-4 py-3 capitalize text-muted-foreground">{e.entry_type.replace('_', ' ')}</td>
                                <td className="px-4 py-3 tabular-nums">{e.quantity} {e.unit}</td>
                                <td className="px-4 py-3 tabular-nums text-muted-foreground">{e.on_hand_before ?? '—'} → {e.on_hand_after ?? '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">{e.recorded_by_name ?? '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">{e.witnessed_by_name ?? '—'}</td>
                            </tr>
                        ))}
                    </TableCard>
                )}

                {activeTab === 'reconciliation' && (
                    <TableCard head={['Client', 'Medication', 'On hand', 'Last balance check', 'Status']} title="Stock reconciliation" count={medications.length} action={btnBalance} cta={medications.length === 0 ? btnBalance : undefined} empty={reconciliation.length === 0 ? (medications.length === 0 ? 'No controlled drugs to reconcile.' : 'No controlled drugs match your search.') : null}>
                        {reconciliation.map((r) => (
                            <tr key={r.med.id} {...rowProps({ kind: 'medication', med: r.med })}>
                                <td className="px-4 py-3">{r.med.client_name}</td>
                                <td className="px-4 py-3 font-medium"><DrugCell name={r.med.name} controlled={r.med.controlled_drug} schedule={r.med.schedule} /></td>
                                <td className="px-4 py-3 tabular-nums"><div>{r.med.stock ? `${r.med.stock.on_hand ?? '—'} ${r.med.stock.unit ?? ''}` : '—'}</div><ExpiryNote value={r.med.stock?.expiry_date} /></td>
                                <td className="px-4 py-3 text-muted-foreground">{r.lastAt ? new Date(r.lastAt).toLocaleDateString('en-NZ') : 'Never'}</td>
                                <td className="px-4 py-3">{r.overdue ? <CdPill label="Overdue" tone="bg-status-warning-bg text-status-warning" /> : <CdPill label="Current" tone="bg-status-success-bg text-status-success" />}</td>
                            </tr>
                        ))}
                    </TableCard>
                )}

                {activeTab === 'discrepancies' && (
                    <div className="flex flex-col gap-3">
                        <TabHeader title="Open discrepancies" count={discrepancies.length} action={btnReportDisc} />
                        {discF.length === 0 ? (
                            <EmptyCard text={discrepancies.length === 0 ? 'No open discrepancies.' : 'No discrepancies match your search.'} cta={discrepancies.length === 0 ? btnReportDisc : undefined} />
                        ) : (
                            discF.map((d) => (
                                <div key={d.id} {...interactive({ kind: 'discrepancy', disc: d })} className="flex cursor-pointer flex-wrap items-center justify-between gap-3 rounded-2xl border bg-card p-4 shadow-sm transition-colors hover:bg-muted/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                    <div>
                                        <div className="font-medium">{d.medication?.name ?? 'CD'} <span className="text-sm text-muted-foreground">· {d.client ? `${d.client.first_name} ${d.client.last_name}` : ''}</span></div>
                                        <div className="text-xs text-muted-foreground">Difference {d.difference} · {d.reason} · reported {d.reported_at ? new Date(d.reported_at).toLocaleDateString('en-NZ') : ''}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <CdPill label={d.status} tone={statusTone(d.status)} />
                                        <Button size="sm" onClick={(e) => { e.stopPropagation(); setModal({ type: 'resolveDisc', disc: d }); }}>Resolve</Button>
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                )}

                {activeTab === 'destructions' && (
                    <div className="flex flex-col gap-3">
                        {/* The standalone /emar/destructions register is the canonical disposal
                            surface (all medications + reports & export). This tab is a count +
                            deep-link + read-only summary; "Record destruction" uses the same
                            shared RecordDestructionDialog so it behaves identically here. */}
                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-2xl border bg-card p-4 shadow-sm">
                            <div className="flex items-start gap-3">
                                <span className="grid h-10 w-10 shrink-0 place-items-center rounded-xl bg-status-warning-bg text-status-warning"><Trash2 className="h-5 w-5" /></span>
                                <div>
                                    <div className="text-sm font-semibold">Destruction register</div>
                                    <div className="text-xs text-muted-foreground">{destructions.length} controlled-drug destruction{destructions.length === 1 ? '' : 's'} on {props.date_label}. The full disposal register — all medications, reports &amp; export — is the canonical record.</div>
                                </div>
                            </div>
                            <div className="flex items-center gap-2">
                                {btnDestruction}
                                <Button size="sm" variant="outline" onClick={() => router.visit('/emar/destructions')}><ArrowUpRight className="h-4 w-4" />Open destructions register</Button>
                            </div>
                        </div>
                        <TableCard head={['Date', 'Client', 'Medication', 'Qty', 'Reason', 'Destroyed by', 'Witness']} title={`CD destructions · ${props.date_label}`} count={destructions.length} cta={destructions.length === 0 ? btnDestruction : undefined} empty={destF.length === 0 ? (destructions.length === 0 ? `No CD destructions on ${props.date_label}.` : 'No destructions match your search.') : null}>
                            {destF.map((d) => (
                                <tr key={d.id} {...rowProps({ kind: 'destruction', destruction: d })}>
                                    <td className="px-4 py-3 text-muted-foreground">{d.destroyed_at ? new Date(d.destroyed_at).toLocaleDateString('en-NZ') : '—'}</td>
                                    <td className="px-4 py-3">{d.client_name}</td>
                                    <td className="px-4 py-3 font-medium">{d.medication_name}</td>
                                    <td className="px-4 py-3 tabular-nums">{d.quantity} {d.unit}</td>
                                    <td className="px-4 py-3 text-muted-foreground">{d.reason}</td>
                                    <td className="px-4 py-3 text-muted-foreground">{d.destroyed_by_name ?? '—'}</td>
                                    <td className="px-4 py-3 text-muted-foreground">{d.witness_name ?? '—'}</td>
                                </tr>
                            ))}
                        </TableCard>
                    </div>
                )}

                {activeTab === 'loss' && (
                    <div className="flex flex-col gap-3">
                        <TabHeader title="Loss reports" count={lossReports.length} action={btnLoss} />
                        {lossF.length === 0 ? (
                            <EmptyCard text={lossReports.length === 0 ? 'No loss reports.' : 'No loss reports match your search.'} cta={lossReports.length === 0 ? btnLoss : undefined} />
                        ) : (
                            lossF.map((l) => (
                                <div key={l.id} {...interactive({ kind: 'loss', loss: l })} className="flex cursor-pointer flex-wrap items-center justify-between gap-3 rounded-2xl border bg-card p-4 shadow-sm transition-colors hover:bg-muted/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                    <div>
                                        <div className="font-medium">{l.medication_name} <span className="text-sm text-muted-foreground">· {l.quantity_lost} {l.unit} lost</span></div>
                                        <div className="text-xs text-muted-foreground">{l.circumstances}{l.reported_to_police ? ` · Police ${l.police_reference ?? 'ref'}` : ''}</div>
                                    </div>
                                    <div className="flex items-center gap-2">
                                        <CdPill label={l.investigation_status} tone={statusTone(l.investigation_status)} />
                                        {l.investigation_status === 'reported' && <Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); setModal({ type: 'lossAction', report: l, action: 'investigate' }); }}>Investigate</Button>}
                                        {l.investigation_status !== 'resolved' && <Button size="sm" onClick={(e) => { e.stopPropagation(); setModal({ type: 'lossAction', report: l, action: 'resolve' }); }}>Resolve</Button>}
                                    </div>
                                </div>
                            ))
                        )}
                    </div>
                )}

                {activeTab === 'audit' && (
                    <TableCard head={['When', 'Medication', 'Movement', 'Balance', 'By · witness']} title={`Audit trail · ${props.date_label}`} count={recentEntries.length} action={btnEntry} cta={recentEntries.length === 0 ? btnEntry : undefined} empty={entriesF.length === 0 ? (recentEntries.length === 0 ? `No audit entries on ${props.date_label}.` : 'No audit entries match your search.') : null}>
                        {entriesF.map((e) => (
                            <tr key={e.id} {...rowProps({ kind: 'entry', entry: e, med: medForEntry(e) }, true)}>
                                <td className="px-4 py-3 text-muted-foreground">{e.recorded_at ? new Date(e.recorded_at).toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }) : '—'}</td>
                                <td className="px-4 py-3 font-medium">{e.medication_name}</td>
                                <td className="px-4 py-3 capitalize text-muted-foreground">{e.entry_type.replace('_', ' ')} {e.quantity}</td>
                                <td className="px-4 py-3 tabular-nums text-muted-foreground">{e.on_hand_after ?? '—'}</td>
                                <td className="px-4 py-3 text-muted-foreground">{e.recorded_by_name ?? '—'}{e.witnessed_by_name ? ` · ${e.witnessed_by_name}` : ''}</td>
                            </tr>
                        ))}
                    </TableCard>
                )}
            </div>

            {modal?.type === 'entry' && <RecordCdEntryDialog medications={medications} staff={staff} currentUserId={props.current_user?.id ?? null} onClose={() => setModal(null)} />}
            {modal?.type === 'balance' && <BalanceCheckDialog medications={medications} staff={staff} currentUserId={props.current_user?.id ?? null} onClose={() => setModal(null)} />}
            {modal?.type === 'balanceMed' && <BalanceCheckDialog medications={medications} staff={staff} currentUserId={props.current_user?.id ?? null} presetMedId={modal.medId} onClose={() => setModal(null)} />}
            {modal?.type === 'loss' && <ReportLossDialog medications={medications} onClose={() => setModal(null)} />}
            {modal?.type === 'destruction' && <RecordDestructionDialog medications={medications} staff={staff} currentUserId={props.current_user?.id ?? null} onClose={() => setModal(null)} />}
            {modal?.type === 'resolveDisc' && <ResolveDiscrepancyDialog discrepancy={modal.disc} onClose={() => setModal(null)} />}
            {modal?.type === 'lossAction' && <LossActionDialog report={modal.report} action={modal.action} onClose={() => setModal(null)} />}
            {modal?.type === 'detail' && (
                <CdDetailDialog
                    subject={modal.subject}
                    onClose={() => setModal(null)}
                    onCheckBalance={(medId) => setModal({ type: 'balanceMed', medId })}
                    onRecordMovement={() => setModal({ type: 'entry' })}
                    onResolveDiscrepancy={(disc) => setModal({ type: 'resolveDisc', disc })}
                    onLossAction={(report, action) => setModal({ type: 'lossAction', report, action })}
                />
            )}
            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}

/** Panel header above a tab's content — title (+ optional count) and a primary
 * create action, Add-Client style. Mirrors the destructions header idiom. */
function TabHeader({ title, count, action }: { title: string; count?: number; action: React.ReactNode }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-2">
            <span className="text-sm font-semibold">
                {title}
                {count != null ? <span className="ml-2 text-xs font-normal text-muted-foreground">{count}</span> : null}
            </span>
            {action}
        </div>
    );
}

function TableCard({ head, empty, title, count, action, cta, children }: { head: string[]; empty: string | null; title?: string; count?: number; action?: React.ReactNode; cta?: React.ReactNode; children: React.ReactNode }) {
    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            {title || action ? (
                <div className="flex flex-wrap items-center gap-2.5 border-b p-3.5">
                    {title ? (
                        <span className="text-sm font-semibold">
                            {title}
                            {count != null ? <span className="ml-2 text-xs font-normal text-muted-foreground">{count}</span> : null}
                        </span>
                    ) : null}
                    {action ? <span className="ml-auto">{action}</span> : null}
                </div>
            ) : null}
            {empty ? (
                <div className="flex flex-col items-center gap-4 px-5 py-12 text-center">
                    <p className="text-sm text-muted-foreground">{empty}</p>
                    {cta}
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[720px] text-sm">
                        <thead>
                            <tr className="bg-muted text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                {head.map((h, i) => (
                                    <th key={i} className="px-4 py-2.5">{h}</th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>{children}</tbody>
                    </table>
                </div>
            )}
        </div>
    );
}

/** Drug name with the controlled-drug lock glyph + CD badge + schedule chip (semantic tokens). */
function DrugCell({ name, controlled, schedule }: { name: string | null; controlled?: boolean; schedule?: number | null }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            {controlled ? <Lock className="h-3 w-3 shrink-0 text-status-critical" aria-label="Controlled drug" /> : null}
            <span>{name ?? '—'}</span>
            {controlled ? <span className="rounded bg-status-critical-bg px-1 py-0.5 text-[9px] font-bold text-status-critical">CD</span> : null}
            {schedule ? <span className="rounded bg-muted px-1 py-0.5 text-[9px] font-bold text-muted-foreground" title={`Controlled Drug Schedule ${schedule}`}>S{schedule}</span> : null}
        </span>
    );
}

/** Inline stock-expiry note — warns when expiring within 30 days or already past. */
function ExpiryNote({ value }: { value?: string | null }) {
    if (!value) return null;
    const exp = parseYmd(value);
    if (Number.isNaN(exp.getTime())) return null;
    const warn = (exp.getTime() - Date.now()) / 86_400_000 <= 30;
    return <span className={warn ? 'mt-0.5 block text-[11px] text-status-warning' : 'mt-0.5 block text-[11px] text-muted-foreground'}>Exp {exp.toLocaleDateString('en-NZ')}</span>;
}

function EmptyCard({ text, cta }: { text: string; cta?: React.ReactNode }) {
    return (
        <div className="flex flex-col items-center gap-4 rounded-2xl border bg-card px-5 py-12 text-center">
            <p className="text-sm text-muted-foreground">{text}</p>
            {cta}
        </div>
    );
}

/** One row of the hero alert strip — icon + message + Review jump + per-session dismiss. */
function AlertRow({ alert, onReview, onDismiss }: { alert: CdAlert; onReview: () => void; onDismiss: () => void }) {
    const Icon = alert.icon;
    const tone = alert.tone === 'critical'
        ? 'border-status-critical/30 bg-status-critical-bg/60 text-status-critical'
        : 'border-status-warning/30 bg-status-warning-bg/60 text-status-warning';
    return (
        <div className={`flex items-center justify-between gap-3 rounded-xl border px-4 py-3 ${tone}`}>
            <span className="flex items-center gap-2 text-sm font-medium">
                <Icon className="h-4 w-4 shrink-0" />
                {alert.message}
            </span>
            <span className="flex items-center gap-1.5">
                <Button size="sm" variant="outline" onClick={onReview}>Review</Button>
                {/* eslint-disable-next-line no-restricted-syntax -- inline dismiss affordance on the alert strip. */}
                <button type="button" aria-label="Dismiss alert" onClick={onDismiss} className="grid h-7 w-7 place-items-center rounded-md opacity-70 hover:bg-foreground/10 hover:opacity-100">
                    <X className="h-4 w-4" />
                </button>
            </span>
        </div>
    );
}
