/* eslint-disable no-restricted-syntax -- register tables, reassess/agreement/per-med cards, the
   activity feed, hero search and the dismissible alert strip are custom-layout bordered surfaces /
   chip buttons (not Card/Button); all colours are semantic tokens. */
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import {
    AssessmentWizardDialog,
    categoryMeta,
    MedScopeDialog,
    scopeMeta,
    SignAgreementDialog,
    ViewSelfAdminDialog,
    type ClientOpt,
    type SelfAdminRow,
} from '@/pages/emar/_self-admin-dialogs';
import { Head, router } from '@inertiajs/react';
import { AlarmClock, ClipboardCheck, Eye, FileSignature, FileText, History, ListChecks, Pill, Plus, Printer, RotateCcw, Search, User, X } from 'lucide-react';
import { useMemo, useState, type KeyboardEvent as ReactKeyboardEvent, type MouseEvent as ReactMouseEvent } from 'react';

type ActivityItem = { actor: string; text: string; subject: string; at: string | null; icon: string };
type Kpis = { self_managing: number; supervised: number; administered: number; due_now: number; independent: number; independent_pct: number; unsigned: number; total: number };

type Props = {
    assessments: SelfAdminRow[];
    activity: ActivityItem[];
    kpis: Kpis;
    clients: ClientOpt[];
    staff: { id: number; name: string }[];
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal =
    | { type: 'new' }
    | { type: 'reassess'; assessment: SelfAdminRow }
    | { type: 'view'; assessment: SelfAdminRow }
    | { type: 'agreement'; assessment: SelfAdminRow }
    | { type: 'scope'; assessment: SelfAdminRow }
    | null;

// ── Stacked, dismissible alert strip (mirror /emar/controlled) ────────────────
type SaAlert = { kind: string; tone: 'critical' | 'warning'; icon: typeof AlarmClock; message: string; tab: string };
const DISMISSED_ALERTS_KEY = 'self-admin-dismissed-alerts';

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

/** Outcome → context-menu header tag colour (semantic token CSS vars). */
function catCtxTag(outcome: string): { tag: string; tagBg: string; tagColor: string } {
    const map: Record<string, { tag: string; tagBg: string; tagColor: string }> = {
        independent: { tag: 'Independent', tagBg: 'var(--status-success-bg)', tagColor: 'var(--status-success)' },
        prompted: { tag: 'Prompted', tagBg: 'var(--status-info-bg)', tagColor: 'var(--status-info)' },
        supervised: { tag: 'Supervised', tagBg: 'var(--status-warning-bg)', tagColor: 'var(--status-warning)' },
        administered: { tag: 'Staff-admin', tagBg: 'var(--status-critical-bg)', tagColor: 'var(--status-critical)' },
    };
    return map[outcome] ?? { tag: 'Not assessed', tagBg: 'var(--muted)', tagColor: 'var(--muted-foreground)' };
}

const initials = (n: string) => n.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';
const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const relative = (iso: string | null) => { if (!iso) return ''; const d = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000); return d <= 0 ? 'today' : d === 1 ? 'yesterday' : `${d}d ago`; };

export default function SelfAdmin({ assessments, activity, kpis, clients, sites, active_site: activeSite, site_brand_colour: brandColour }: Props) {
    const [activeTab, setActiveTab] = useState('assessments');
    const [search, setSearch] = useState('');
    const [siteFilter, setSiteFilter] = useState<number | null>(activeSite?.id ?? null);
    const [clientFilter, setClientFilter] = useState<number | null>(null);
    const [modal, setModal] = useState<Modal>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [dismissed, setDismissed] = useState<string[]>(() => readDismissedAlerts());
    const dismiss = (kind: string) => setDismissed((prev) => persistDismissedAlerts([...prev, kind]));

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        return assessments.filter((a) =>
            (!q || `${a.client_name} ${a.nhi ?? ''} ${a.assessor_name ?? ''}`.toLowerCase().includes(q)) &&
            (!clientFilter || a.client_id === clientFilter),
        );
    }, [assessments, search, clientFilter]);

    const dueList = visible.filter((a) => a.reassessment_due);
    const agreements = visible.filter((a) => a.outcome === 'independent' || a.outcome === 'prompted');
    const selfManaging = visible.filter((a) => a.outcome !== 'administered' && a.client_medications.length > 0);

    const onSite = (id: number | null) => { setSiteFilter(id); router.get('/emar/self-admin', id ? { site_id: id } : {}, { preserveState: true, preserveScroll: true }); };

    // ── Row interactions (parity with PRN/Controlled) — shared across every list. ──
    const openView = (a: SelfAdminRow) => setModal({ type: 'view', assessment: a });
    const viewClient = (id: number) => router.visit(`/operations/clients/${id}?tab=mar`);
    const openMar = (id: number) => router.visit(`/emar/mar?client_id=${id}`);

    const openRowCtx = (e: ReactMouseEvent, a: SelfAdminRow) => {
        e.preventDefault();
        const t = catCtxTag(a.outcome);
        const isSelfManaging = a.outcome === 'independent' || a.outcome === 'prompted';
        const canScope = a.outcome !== 'administered' && a.client_medications.length > 0;
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View assessment', sub: categoryMeta(a.outcome).label, tone: 'primary', onClick: () => openView(a) },
            { icon: <RotateCcw className="h-3.5 w-3.5" />, label: 'Reassess', onClick: () => setModal({ type: 'reassess', assessment: a }) },
            ...(isSelfManaging && !a.agreement_signed_at ? [{ icon: <FileSignature className="h-3.5 w-3.5" />, label: 'Sign agreement', sub: 'Awaiting signature', onClick: () => setModal({ type: 'agreement', assessment: a }) } satisfies ShiftCtxItem] : []),
            ...(canScope ? [{ icon: <Pill className="h-3.5 w-3.5" />, label: 'Set medication scope', onClick: () => setModal({ type: 'scope', assessment: a }) } satisfies ShiftCtxItem] : []),
            { sep: true },
            { icon: <User className="h-3.5 w-3.5" />, label: 'View client', onClick: () => viewClient(a.client_id) },
            { icon: <FileText className="h-3.5 w-3.5" />, label: 'Open on MAR chart', onClick: () => openMar(a.client_id) },
            { sep: true },
            { icon: <Printer className="h-3.5 w-3.5" />, label: 'Print assessment', onClick: () => window.print() },
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: t.tag, tagBg: t.tagBg, tagColor: t.tagColor, meta: `${a.client_name}${a.nhi ? ` · NHI ${a.nhi}` : ''}${a.reassessment_date ? ` · review ${fmtDate(a.reassessment_date)}` : ''}`, items });
    };

    /** Spread onto a card/row/header to make it behave like the assessments table
     * (click → detail, right-click → context menu, keyboard-focusable). Inline
     * action buttons inside the surface must stopPropagation. */
    const rowInteract = (a: SelfAdminRow) => ({
        role: 'button' as const,
        tabIndex: 0,
        onClick: () => openView(a),
        onContextMenu: (e: ReactMouseEvent) => openRowCtx(e, a),
        onKeyDown: (e: ReactKeyboardEvent) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openView(a); } },
    });

    const TABS: RosterTabItem[] = [
        { id: 'assessments', label: 'Assessments', icon: ListChecks, tone: 'primary', badge: assessments.length || undefined },
        { id: 'reassess', label: 'Reassessments due', icon: AlarmClock, tone: 'warning', badge: dueList.length || undefined },
        { id: 'agreements', label: 'Agreements', icon: FileSignature, tone: 'info', badge: kpis.unsigned || undefined },
        { id: 'permed', label: 'Per-medication scope', icon: Pill, tone: 'primary' },
        { id: 'activity', label: 'Activity', icon: History, tone: 'primary' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'Self-managing', value: kpis.self_managing },
        { label: 'Supervised', value: kpis.supervised },
        { label: 'Due now', value: kpis.due_now, tone: kpis.due_now > 0 ? 'critical' : 'neutral' },
        { label: 'Independent', value: `${kpis.independent_pct}%` },
    ];

    const alerts: SaAlert[] = [
        kpis.due_now > 0 && { kind: 'due', tone: 'warning' as const, icon: AlarmClock, message: `${kpis.due_now} self-administration assessment${kpis.due_now === 1 ? '' : 's'} due for reassessment.`, tab: 'reassess' },
        kpis.unsigned > 0 && { kind: 'unsigned', tone: 'critical' as const, icon: FileSignature, message: `${kpis.unsigned} self-managing client${kpis.unsigned === 1 ? '' : 's'} awaiting a signed self-administration agreement.`, tab: 'agreements' },
    ].filter((a): a is SaAlert => Boolean(a) && !dismissed.includes((a as SaAlert).kind));

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Self-Administration', href: '/emar/self-admin' }]}>
            <Head title="eMAR - Self-Administration" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={ClipboardCheck}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Self-administration oversight · live
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Self-administration across{' '}
                                <span className="border-b-2 border-primary-foreground/40">{activeSite?.name ?? `${kpis.total} clients`}</span>
                            </span>
                        </span>
                    }
                    description="Independence first; staff step in only where the risk assessment says so. Consent-first, NZ MOH medicines-management categories."
                    stats={heroStats}
                    actions={
                        <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'new' })}>
                            <Plus className="h-4 w-4" />
                            New assessment
                        </Button>
                    }
                    footer={
                        <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="relative w-full max-w-xs lg:w-[260px]">
                                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search client or NHI…"
                                    aria-label="Search self-administration assessments"
                                    className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-3 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
                                />
                                {search ? (
                                    <button type="button" aria-label="Clear search" onClick={() => setSearch('')} className="absolute top-1/2 right-2 grid h-5 w-5 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-muted">
                                        <X className="h-3.5 w-3.5" />
                                    </button>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-2 lg:ml-auto lg:justify-end">
                                {sites.length > 0 && <EntityFilter label="Site" allLabel="All sites" items={sites} value={siteFilter} onChange={onSite} onDark />}
                                <EntityFilter
                                    label="Client"
                                    allLabel="All clients"
                                    items={clients.map((c) => ({ id: c.id, name: `${c.first_name} ${c.last_name}` }))}
                                    value={clientFilter}
                                    onChange={setClientFilter}
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

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Self-administration views" />

                {activeTab === 'assessments' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        {visible.length === 0 ? <div className="px-5 py-12 text-center text-sm text-muted-foreground">No assessments match the current filters.</div> : (
                            <div className="overflow-x-auto">
                                <table className="w-full min-w-[920px] text-sm">
                                    <thead>
                                        <tr className="bg-muted/50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                            <th className="px-4 py-2.5">Client</th><th className="px-4 py-2.5">Category</th><th className="px-4 py-2.5">Capacity</th><th className="px-4 py-2.5">Capability</th><th className="px-4 py-2.5">Consent</th><th className="px-4 py-2.5">Reassessment</th><th className="px-4 py-2.5">Assessor</th><th className="px-4 py-2.5 text-right">Actions</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visible.map((a) => {
                                            const cat = categoryMeta(a.outcome);
                                            const capPct = (a.total_score / 25) * 100;
                                            const capCount = [a.can_identify_medications, a.can_read_labels, a.can_open_packaging, a.can_manage_timing, a.can_store_safely, a.willing_to_self_admin].filter(Boolean).length;
                                            return (
                                                <tr key={a.id} className="cursor-pointer border-b last:border-b-0 hover:bg-muted/30" onClick={() => openView(a)} onContextMenu={(e) => openRowCtx(e, a)}>
                                                    <td className="px-4 py-3"><div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(a.client_name)}</span><div><div className="font-medium">{a.client_name}</div><div className="text-xs text-muted-foreground">{a.nhi ? `NHI ${a.nhi} · ` : ''}{a.site_name ?? ''}</div></div></div></td>
                                                    <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${cat.cls}`}>{cat.label}</span></td>
                                                    <td className="px-4 py-3"><div className="flex items-center gap-2"><div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted"><div className={`h-full rounded-full ${capPct >= 80 ? 'bg-status-success' : capPct >= 55 ? 'bg-status-warning' : 'bg-status-critical'}`} style={{ width: `${capPct}%` }} /></div><span className="tabular-nums text-xs">{a.total_score}/25</span></div></td>
                                                    <td className="px-4 py-3"><span className={`text-xs font-semibold ${capCount >= 5 ? 'text-status-success' : capCount >= 3 ? 'text-status-warning' : 'text-status-critical'}`}>{capCount}/6</span></td>
                                                    <td className="px-4 py-3 text-xs">{a.wishes_to_self_administer ? <span className="text-status-success">Wishes to self-admin</span> : <span className="text-muted-foreground">Declined</span>}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{fmtDate(a.reassessment_date)}{a.reassessment_due && <span className="ml-1 rounded-full bg-status-critical-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-critical">Due</span>}</td>
                                                    <td className="px-4 py-3 text-muted-foreground">{a.assessor_name ?? '—'}</td>
                                                    <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                                                        <div className="flex items-center justify-end gap-1">
                                                            <Button size="sm" variant="ghost" onClick={() => openView(a)} title="View"><Eye className="h-3.5 w-3.5" /></Button>
                                                            <Button size="sm" variant="ghost" onClick={() => setModal({ type: 'reassess', assessment: a })} title="Reassess"><RotateCcw className="h-3.5 w-3.5" /></Button>
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
                )}

                {activeTab === 'reassess' && (
                    dueList.length === 0 ? <Empty text="No reassessments due." /> : (
                        <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                            {dueList.map((a) => {
                                const cat = categoryMeta(a.outcome);
                                return (
                                    <div key={a.id} {...rowInteract(a)} className="cursor-pointer rounded-2xl border border-l-4 border-l-status-critical bg-card p-4 shadow-sm transition-colors hover:bg-muted/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary">
                                        <div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(a.client_name)}</span><div><div className="font-medium">{a.client_name}</div><span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${cat.cls}`}>{cat.label}</span></div></div>
                                        <div className="mt-3 text-xs text-muted-foreground">Reassessment was due {fmtDate(a.reassessment_date)}.{a.reassessment_trigger ? ` Trigger: ${a.reassessment_trigger}.` : ''}</div>
                                        <div className="mt-3 flex gap-2"><Button size="sm" onClick={(e) => { e.stopPropagation(); setModal({ type: 'reassess', assessment: a }); }}>Start reassessment</Button><Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); openView(a); }}>View</Button></div>
                                    </div>
                                );
                            })}
                        </div>
                    )
                )}

                {activeTab === 'agreements' && (
                    agreements.length === 0 ? <Empty text="No self-managing clients yet." /> : (
                        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                            {agreements.map((a) => {
                                const signed = !!a.agreement_signed_at;
                                return (
                                    <div key={a.id} {...rowInteract(a)} className="flex cursor-pointer items-center justify-between gap-3 border-b px-4 py-3 transition-colors last:border-b-0 hover:bg-muted/30 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary">
                                        <div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(a.client_name)}</span><div><div className="text-sm font-medium">{a.client_name}</div><div className="text-xs text-muted-foreground">{categoryMeta(a.outcome).label}{a.ordering_responsibility ? ` · ordering: ${a.ordering_responsibility}` : ''}</div></div></div>
                                        <div className="flex items-center gap-2">
                                            {signed ? <span className="rounded-full bg-status-success-bg px-2 py-0.5 text-[11px] font-semibold text-status-success">Signed</span> : <span className="rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-semibold text-status-critical">Awaiting signature</span>}
                                            {signed ? <Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); openView(a); }}>View</Button> : <Button size="sm" onClick={(e) => { e.stopPropagation(); setModal({ type: 'agreement', assessment: a }); }}>Sign agreement</Button>}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )
                )}

                {activeTab === 'permed' && (
                    selfManaging.length === 0 ? <Empty text="No self-managing clients with active medications." /> : (
                        <div className="flex flex-col gap-4">
                            {selfManaging.map((a) => (
                                <div key={a.id} className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                                    <div {...rowInteract(a)} className="flex cursor-pointer items-center justify-between gap-3 border-b bg-muted/40 px-4 py-3 transition-colors hover:bg-muted/60 focus:outline-none focus-visible:ring-2 focus-visible:ring-inset focus-visible:ring-primary">
                                        <div className="flex items-center gap-3"><span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(a.client_name)}</span><div><div className="text-sm font-semibold">{a.client_name}</div><span className={`rounded-full px-2 py-0.5 text-[10px] font-semibold ${categoryMeta(a.outcome).cls}`}>{categoryMeta(a.outcome).label}</span></div></div>
                                        <Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); setModal({ type: 'scope', assessment: a }); }}><Pill className="h-3.5 w-3.5" />Set scope</Button>
                                    </div>
                                    <div className="flex flex-col">
                                        {a.client_medications.map((m) => { const sm = scopeMeta(m.scope); return (
                                            <div key={m.id} className="flex items-center justify-between gap-3 border-b px-4 py-2.5 last:border-b-0">
                                                <div className="flex items-center gap-2"><Pill className="h-4 w-4 text-muted-foreground" /><span className="text-sm font-medium">{m.name}</span>{m.controlled && <span className="rounded-full bg-status-critical-bg px-1.5 py-0.5 text-[10px] font-semibold text-status-critical">CD</span>}{m.dosage && <span className="text-xs text-muted-foreground">{m.dosage}</span>}</div>
                                                <span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${sm.cls}`}>{sm.label}</span>
                                            </div>
                                        ); })}
                                    </div>
                                </div>
                            ))}
                        </div>
                    )
                )}

                {activeTab === 'activity' && (
                    <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
                        <div className="border-b bg-muted/40 px-4 py-3 text-xs text-muted-foreground">Entries are amendable but never deleted.</div>
                        {activity.length === 0 ? <Empty text="No activity yet." /> : (
                            <div className="flex flex-col">
                                {activity.map((e, i) => (
                                    <div key={i} className="flex items-center gap-3 border-b px-4 py-3 last:border-b-0">
                                        <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-accent text-primary">{e.icon === 'file' ? <FileText className="h-4 w-4" /> : <ClipboardCheck className="h-4 w-4" />}</span>
                                        <div className="flex-1 text-sm"><span className="font-medium">{e.actor}</span> {e.text} <span className="font-medium">{e.subject}</span></div>
                                        <span className="text-xs text-muted-foreground">{relative(e.at)}</span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </div>

            {modal?.type === 'new' && <AssessmentWizardDialog clients={clients} mode="new" onClose={() => setModal(null)} />}
            {modal?.type === 'reassess' && <AssessmentWizardDialog clients={clients} mode="reassess" assessment={modal.assessment} onClose={() => setModal(null)} />}
            {modal?.type === 'view' && (
                <ViewSelfAdminDialog
                    assessment={modal.assessment}
                    onClose={() => setModal(null)}
                    onReassess={() => setModal({ type: 'reassess', assessment: modal.assessment })}
                    onSignAgreement={() => setModal({ type: 'agreement', assessment: modal.assessment })}
                    onSetScope={() => setModal({ type: 'scope', assessment: modal.assessment })}
                />
            )}
            {modal?.type === 'agreement' && <SignAgreementDialog assessment={modal.assessment} onClose={() => setModal(null)} />}
            {modal?.type === 'scope' && <MedScopeDialog assessment={modal.assessment} onClose={() => setModal(null)} />}
            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}

/** One row of the hero alert strip — icon + message + Review jump + per-session dismiss. */
function AlertRow({ alert, onReview, onDismiss }: { alert: SaAlert; onReview: () => void; onDismiss: () => void }) {
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
                <button type="button" aria-label="Dismiss alert" onClick={onDismiss} className="grid h-7 w-7 place-items-center rounded-md opacity-70 hover:bg-foreground/10 hover:opacity-100">
                    <X className="h-4 w-4" />
                </button>
            </span>
        </div>
    );
}

function Empty({ text }: { text: string }) {
    return <div className="rounded-2xl border border-dashed bg-card px-5 py-12 text-center text-sm text-muted-foreground">{text}</div>;
}
