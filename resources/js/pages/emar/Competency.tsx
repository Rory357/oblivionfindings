/* eslint-disable no-restricted-syntax -- competency tables, KPI cards, coverage matrix and the
   hero search/chip controls are custom-layout bordered surfaces / chip buttons (not Card/Button);
   all colours are semantic tokens. */
import { PageHero, type PageHeroStat } from '@/components/page';
import { EntityFilter, ShiftContextMenu, TabStrip, type RosterTabItem, type ShiftCtxItem, type ShiftCtxState } from '@/components/rostering';
import { Button } from '@/components/ui/button';
import AppLayout from '@/layouts/app-layout';
import {
    AssessmentWizardDialog,
    COMPETENCY_AREAS,
    statusChip,
    ViewAssessmentDialog,
    type AssessmentRow,
    type StaffOpt,
} from '@/pages/emar/_competency-dialogs';
import { Head, router } from '@inertiajs/react';
import { Award, AlertTriangle, CalendarClock, CheckCircle2, Download, Eye, GraduationCap, LayoutGrid, Lock, Pencil, Plus, RotateCcw, Search, ShieldCheck, Trash2, User, UserX, X } from 'lucide-react';
import { useMemo, useState, type MouseEvent as ReactMouseEvent } from 'react';

type Kpis = { total_staff: number; in_date: number; in_date_pct: number; expiring: number; expired: number; unassessed: number; cd_witnesses: number };
type UnassessedStaff = { id: number; name: string; role: string | null };

type Props = {
    assessments: AssessmentRow[];
    staffWithoutAssessment: UnassessedStaff[];
    staff: StaffOpt[];
    kpis: Kpis;
    sites: { id: number; name: string }[];
    active_site: { id: number; name: string } | null;
    site_brand_colour: string | null;
};

type Modal =
    | { type: 'new'; userId?: number }
    | { type: 'edit'; assessment: AssessmentRow }
    | { type: 'renew'; assessment: AssessmentRow }
    | { type: 'view'; assessment: AssessmentRow }
    | null;

const initials = (n: string) => n.split(' ').filter(Boolean).slice(0, 2).map((p) => p[0]).join('').toUpperCase() || '?';
const fmtDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' }) : '—');
const daysTo = (iso: string | null) => (iso ? Math.round((new Date(iso).getTime() - Date.now()) / 86400000) : null);
const STATUS_OPTS = [{ id: 0, name: 'In date' }, { id: 1, name: 'Supervised' }, { id: 2, name: 'Expired' }, { id: 3, name: 'Failed' }];

type CompAlert = { kind: string; tone: 'critical' | 'warning'; icon: typeof AlertTriangle; message: string; tab: string };
const DISMISSED_ALERTS_KEY = 'competency-dismissed-alerts';

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

function csvCell(v: unknown): string { const s = v == null ? '' : String(v); return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; }
function exportCsv(rows: AssessmentRow[]) {
    const head = ['Staff', 'Role', 'Type', 'Score', 'Status', 'Expiry', 'Unsupervised', 'CD witness', 'Restricted', 'Assessor'];
    const lines = rows.map((a) => [a.user_name, a.user_role, a.assessment_type, `${a.total_score ?? 0}/${a.pass_threshold ?? 12}`, statusChip(a).label, a.expiry_date, a.can_administer_unsupervised ? 'Yes' : 'No', a.can_witness_controlled ? 'Yes' : 'No', a.restricted ? 'Yes' : 'No', a.assessor_name].map(csvCell).join(','));
    const blob = new Blob([[head.join(','), ...lines].join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `competency-register-${new Date().toISOString().slice(0, 10)}.csv`; link.click(); URL.revokeObjectURL(url);
}

export default function Competency({ assessments, staffWithoutAssessment, staff, kpis, active_site: activeSite, site_brand_colour: brandColour }: Props) {
    const [activeTab, setActiveTab] = useState('all');
    const [search, setSearch] = useState('');
    const [roleFilter, setRoleFilter] = useState<number | null>(null);
    const [statusFilter, setStatusFilter] = useState<number | null>(null);
    const [modal, setModal] = useState<Modal>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);

    // Shared row actions — every surface (table, expiring, unassessed, coverage)
    // and the right-click menu funnel through these so behaviour stays identical.
    const viewAssessment = (a: AssessmentRow) => setModal({ type: 'view', assessment: a });
    const renewAssessment = (a: AssessmentRow) => setModal({ type: 'renew', assessment: a });
    const editAssessment = (a: AssessmentRow) => setModal({ type: 'edit', assessment: a });
    const deleteAssessment = (a: AssessmentRow) => { if (confirm(`Delete ${a.user_name}'s assessment? This cannot be undone.`)) router.delete(`/emar/competency/${a.id}`, { preserveScroll: true }); };
    const startAssessment = (userId?: number) => setModal({ type: 'new', userId });
    const viewStaff = (userId: number) => router.visit(`/staff/${userId}`);

    // Right-click menu for an assessment row (copies PRN's openRowCtx idiom). The
    // staff-centric "View staff member" replaces the cross-module "View client".
    const openAssessmentCtx = (e: ReactMouseEvent, a: AssessmentRow) => {
        e.preventDefault();
        const t = ctxStatusTag(a);
        const items: ShiftCtxItem[] = [
            { icon: <Eye className="h-3.5 w-3.5" />, label: 'View assessment', sub: `${a.assessment_type ?? 'assessment'} · ${a.total_score ?? 0}/${a.pass_threshold ?? 12}`, tone: 'primary', onClick: () => viewAssessment(a) },
            { icon: <RotateCcw className="h-3.5 w-3.5" />, label: 'Renew / reassess', onClick: () => renewAssessment(a) },
            { icon: <Pencil className="h-3.5 w-3.5" />, label: 'Edit', onClick: () => editAssessment(a) },
            { sep: true },
            { icon: <User className="h-3.5 w-3.5" />, label: 'View staff member', sub: a.user_role ?? undefined, onClick: () => viewStaff(a.user_id) },
            { sep: true },
            { icon: <Trash2 className="h-3.5 w-3.5" />, label: 'Delete assessment', tone: 'critical', onClick: () => deleteAssessment(a) },
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: t.tag, tagBg: t.tagBg, tagColor: t.tagColor, meta: `${a.user_name} · ${a.user_role ?? 'Staff'} · expires ${fmtDate(a.expiry_date)}`, items });
    };

    // Right-click menu for an unassessed staff member — no assessment to view yet.
    const openUnassessedCtx = (e: ReactMouseEvent, s: UnassessedStaff) => {
        e.preventDefault();
        const items: ShiftCtxItem[] = [
            { icon: <Plus className="h-3.5 w-3.5" />, label: 'Start assessment', tone: 'primary', onClick: () => startAssessment(s.id) },
            { sep: true },
            { icon: <User className="h-3.5 w-3.5" />, label: 'View staff member', sub: s.role ?? undefined, onClick: () => viewStaff(s.id) },
        ];
        setCtx({ x: e.clientX, y: e.clientY, tag: 'Unassessed', tagBg: 'var(--status-warning-bg)', tagColor: 'var(--status-warning)', meta: `${s.name} · ${s.role ?? 'Staff'} · no current assessment`, items });
    };

    const roleItems = useMemo(() => {
        const set = new Set<string>();
        assessments.forEach((a) => a.user_role && set.add(a.user_role));
        staffWithoutAssessment.forEach((s) => s.role && set.add(s.role));
        return [...set].sort().map((r, i) => ({ id: i, name: r }));
    }, [assessments, staffWithoutAssessment]);

    const visible = useMemo(() => {
        const q = search.trim().toLowerCase();
        const role = roleFilter !== null ? roleItems[roleFilter]?.name : null;
        const status = statusFilter !== null ? STATUS_OPTS[statusFilter]?.name : null;
        return assessments.filter((a) => {
            if (role && a.user_role !== role) return false;
            if (status && statusChip(a).label !== status) return false;
            if (q && !`${a.user_name} ${a.user_role ?? ''} ${a.assessor_name ?? ''}`.toLowerCase().includes(q)) return false;
            return true;
        });
    }, [assessments, search, roleFilter, statusFilter, roleItems]);

    const inDate = visible.filter((a) => a.is_passed);
    const expiringList = visible.filter((a) => a.is_passed && (daysTo(a.expiry_date) ?? 999) <= 30 && (daysTo(a.expiry_date) ?? -1) >= 0);
    const expiredList = visible.filter((a) => a.is_expired);
    const latestByUser = useMemo(() => { const m = new Map<number, AssessmentRow>(); assessments.forEach((a) => { if (!m.has(a.user_id)) m.set(a.user_id, a); }); return [...m.values()]; }, [assessments]);

    const filteredUnassessed = useMemo(() => {
        const q = search.trim().toLowerCase();
        const role = roleFilter !== null ? roleItems[roleFilter]?.name : null;
        return staffWithoutAssessment.filter((s) => (!role || s.role === role) && (!q || s.name.toLowerCase().includes(q)));
    }, [staffWithoutAssessment, search, roleFilter, roleItems]);

    // Stacked, dismissible (per session) alert strip built from the headline KPIs
    // (the standing oversight counts, independent of role/status/search filters).
    const [dismissed, setDismissed] = useState<string[]>(() => readDismissedAlerts());
    const dismiss = (kind: string) => setDismissed((prev) => persistDismissedAlerts([...prev, kind]));
    const alerts: CompAlert[] = [
        kpis.expired > 0 && { kind: 'expired', tone: 'critical' as const, icon: AlertTriangle, message: `${kpis.expired} staff competenc${kpis.expired === 1 ? 'y has' : 'ies have'} expired — those staff must not administer medication unsupervised until reassessed.`, tab: 'expired' },
        kpis.expiring > 0 && { kind: 'expiring', tone: 'warning' as const, icon: CalendarClock, message: `${kpis.expiring} competenc${kpis.expiring === 1 ? 'y expires' : 'ies expire'} within 30 days — schedule reassessment.`, tab: 'expiring' },
        kpis.unassessed > 0 && { kind: 'unassessed', tone: 'warning' as const, icon: UserX, message: `${kpis.unassessed} staff member${kpis.unassessed === 1 ? ' has' : 's have'} no current medication competency assessment.`, tab: 'unassessed' },
    ].filter((a): a is CompAlert => Boolean(a) && !dismissed.includes((a as CompAlert).kind));

    const TABS: RosterTabItem[] = [
        { id: 'all', label: 'All assessments', icon: LayoutGrid, tone: 'primary', badge: assessments.length || undefined },
        { id: 'in_date', label: 'In date', icon: CheckCircle2, tone: 'success', badge: inDate.length || undefined },
        { id: 'expiring', label: 'Expiring soon', icon: CalendarClock, tone: 'warning', badge: expiringList.length || undefined },
        { id: 'expired', label: 'Expired', icon: AlertTriangle, tone: 'critical', badge: expiredList.length || undefined },
        { id: 'unassessed', label: 'Unassessed staff', icon: UserX, tone: 'info', badge: staffWithoutAssessment.length || undefined },
        { id: 'coverage', label: 'Coverage matrix', icon: ShieldCheck, tone: 'primary' },
    ];

    const heroStats: PageHeroStat[] = [
        { label: 'In date', value: `${kpis.in_date_pct}%` },
        { label: 'Expiring', value: kpis.expiring, tone: kpis.expiring > 0 ? 'warning' : 'neutral' },
        { label: 'Unassessed', value: kpis.unassessed, tone: kpis.unassessed > 0 ? 'warning' : 'neutral' },
        { label: 'CD witnesses', value: kpis.cd_witnesses },
    ];
    const description = `${kpis.in_date} of ${kpis.total_staff} staff are medication-competent and in date (${kpis.in_date_pct}%). ${kpis.expiring} expire within 30 days and ${kpis.unassessed} have no current assessment.`;

    return (
        <AppLayout breadcrumbs={[{ title: 'eMAR', href: '/emar' }, { title: 'Competency', href: '/emar/competency' }]}>
            <Head title="eMAR - Medication Competency" />
            <div className="flex flex-col gap-6 p-6">
                <PageHero
                    variant="hero"
                    category="ops"
                    brandColour={brandColour}
                    icon={GraduationCap}
                    title={
                        <span>
                            <span className="flex items-center gap-2 text-[10.5px] font-semibold uppercase tracking-wide text-primary-foreground/80">
                                <span aria-hidden className="relative inline-flex h-2 w-2">
                                    <span className="absolute inset-0 animate-ping rounded-full bg-status-success/70" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                Medication competency oversight · live
                            </span>
                            <span className="mt-1 block text-[26px] font-bold leading-tight">
                                Team medication competency{activeSite ? ' for ' : ''}
                                {activeSite && <span className="border-b-2 border-primary-foreground/40">{activeSite.name}</span>}
                            </span>
                        </span>
                    }
                    description={description}
                    stats={heroStats}
                    actions={
                        <>
                            <Button className="bg-primary-foreground text-primary hover:bg-primary-foreground/90" onClick={() => setModal({ type: 'new' })}>
                                <Plus className="h-4 w-4" />
                                New assessment
                            </Button>
                            <Button variant="outline" className="border-primary-foreground/30 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20" onClick={() => exportCsv(assessments)} disabled={assessments.length === 0}>
                                <Download className="h-4 w-4" />
                                Export register
                            </Button>
                        </>
                    }
                    footer={
                        <div className="flex flex-col gap-3 py-3 lg:flex-row lg:items-center lg:justify-between">
                            <div className="relative w-full max-w-xs md:w-[260px]">
                                <Search className="pointer-events-none absolute top-1/2 left-3 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                                <input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    placeholder="Search staff, role or assessor…"
                                    aria-label="Search competency assessments"
                                    className="h-8 w-full rounded-full border-0 bg-primary-foreground pr-8 pl-9 text-[13px] text-foreground shadow-sm outline-none placeholder:text-muted-foreground/80 focus:ring-2 focus:ring-primary-foreground/50"
                                />
                                {search ? (
                                    <button type="button" aria-label="Clear search" onClick={() => setSearch('')} className="absolute top-1/2 right-2 grid h-5 w-5 -translate-y-1/2 place-items-center rounded-full text-muted-foreground hover:bg-muted">
                                        <X className="h-3.5 w-3.5" />
                                    </button>
                                ) : null}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                {roleItems.length > 0 && <EntityFilter label="Role" allLabel="All roles" items={roleItems} value={roleFilter} onChange={setRoleFilter} onDark />}
                                <EntityFilter label="Status" allLabel="All statuses" items={STATUS_OPTS} value={statusFilter} onChange={setStatusFilter} onDark />
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

                <div className="grid gap-3 sm:grid-cols-3 lg:grid-cols-6">
                    <Kpi icon={CheckCircle2} label="In date" value={`${kpis.in_date_pct}%`} tone="success" />
                    <Kpi icon={Award} label="Current" value={kpis.in_date} tone="info" />
                    <Kpi icon={CalendarClock} label="Expiring 30d" value={kpis.expiring} tone="warning" />
                    <Kpi icon={AlertTriangle} label="Expired" value={kpis.expired} tone="critical" />
                    <Kpi icon={UserX} label="Never assessed" value={kpis.unassessed} tone="warning" />
                    <Kpi icon={Lock} label="CD witnesses" value={kpis.cd_witnesses} tone="info" />
                </div>

                <TabStrip value={activeTab} onChange={setActiveTab} items={TABS} ariaLabel="Competency views" />

                {['all', 'in_date', 'expired'].includes(activeTab) && (
                    <AssessmentTable rows={activeTab === 'in_date' ? inDate : activeTab === 'expired' ? expiredList : visible} onView={viewAssessment} onRenew={renewAssessment} onEdit={editAssessment} onDelete={deleteAssessment} onCtx={openAssessmentCtx} />
                )}

                {activeTab === 'expiring' && (
                    <ListCard empty={expiringList.length === 0 ? 'No assessments expiring in the next 30 days.' : null}>
                        {expiringList.map((a) => (
                            <div key={a.id} role="button" tabIndex={0} className="flex cursor-pointer items-center justify-between gap-3 border-b px-4 py-3 last:border-b-0 hover:bg-muted/30 focus:outline-none focus-visible:bg-muted/30" onClick={() => viewAssessment(a)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); viewAssessment(a); } }} onContextMenu={(e) => openAssessmentCtx(e, a)}>
                                <StaffCell a={a} sub={`Expires ${fmtDate(a.expiry_date)} · ${daysTo(a.expiry_date)}d`} />
                                <Button size="sm" onClick={(e) => { e.stopPropagation(); renewAssessment(a); }}><RotateCcw className="h-3.5 w-3.5" />Schedule reassessment</Button>
                            </div>
                        ))}
                    </ListCard>
                )}

                {activeTab === 'unassessed' && (
                    <ListCard empty={filteredUnassessed.length === 0 ? 'Every staff member has a current assessment.' : null}>
                        {filteredUnassessed.map((s) => (
                            <div key={s.id} role="button" tabIndex={0} className="flex cursor-pointer items-center justify-between gap-3 border-b px-4 py-3 last:border-b-0 hover:bg-muted/30 focus:outline-none focus-visible:bg-muted/30" onClick={() => startAssessment(s.id)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); startAssessment(s.id); } }} onContextMenu={(e) => openUnassessedCtx(e, s)}>
                                <div className="flex items-center gap-3">
                                    <span className="flex h-9 w-9 items-center justify-center rounded-full bg-status-warning-bg text-xs font-bold text-status-warning">{initials(s.name)}</span>
                                    <div><div className="text-sm font-medium">{s.name}</div><div className="text-xs text-muted-foreground">{s.role ?? 'Staff'} · no current assessment</div></div>
                                </div>
                                <Button size="sm" onClick={(e) => { e.stopPropagation(); startAssessment(s.id); }}><Plus className="h-3.5 w-3.5" />Start assessment</Button>
                            </div>
                        ))}
                    </ListCard>
                )}

                {activeTab === 'coverage' && <CoverageMatrix rows={latestByUser} onView={viewAssessment} onCtx={openAssessmentCtx} />}
            </div>

            {modal?.type === 'new' && <AssessmentWizardDialog staff={staff} mode="new" defaultUserId={modal.userId} onClose={() => setModal(null)} />}
            {modal?.type === 'edit' && <AssessmentWizardDialog staff={staff} mode="edit" assessment={modal.assessment} onClose={() => setModal(null)} />}
            {modal?.type === 'renew' && <AssessmentWizardDialog staff={staff} mode="renew" assessment={modal.assessment} onClose={() => setModal(null)} />}
            {modal?.type === 'view' && <ViewAssessmentDialog assessment={modal.assessment} onClose={() => setModal(null)} onRenew={() => renewAssessment(modal.assessment)} onEdit={() => editAssessment(modal.assessment)} onViewStaff={() => viewStaff(modal.assessment.user_id)} />}

            {ctx && <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />}
        </AppLayout>
    );
}

function Kpi({ icon: Icon, label, value, tone }: { icon: typeof Award; label: string; value: number | string; tone: 'critical' | 'warning' | 'success' | 'info' }) {
    const cls = { critical: 'bg-status-critical-bg text-status-critical', warning: 'bg-status-warning-bg text-status-warning', success: 'bg-status-success-bg text-status-success', info: 'bg-status-info-bg text-status-info' }[tone];
    return (
        <div className="flex items-center gap-2.5 rounded-2xl border bg-card p-3 shadow-sm">
            <span className={`flex h-9 w-9 items-center justify-center rounded-xl ${cls}`}><Icon className="h-5 w-5" /></span>
            <div><div className="text-xl font-bold tabular-nums">{value}</div><div className="text-[11px] text-muted-foreground">{label}</div></div>
        </div>
    );
}

function StaffCell({ a, sub }: { a: AssessmentRow; sub?: string }) {
    return (
        <div className="flex items-center gap-3">
            <span className="flex h-9 w-9 items-center justify-center rounded-full bg-primary/10 text-xs font-bold text-primary">{initials(a.user_name)}</span>
            <div><div className="text-sm font-medium">{a.user_name}</div><div className="text-xs text-muted-foreground">{sub ?? `${a.user_role ?? 'Staff'}`}</div></div>
        </div>
    );
}

// Status tag for the right-click menu header — same labels as statusChip but
// surfaced as token CSS vars (the menu paints tagBg/tagColor inline) and with an
// explicit "Expiring" state for in-date assessments inside the 30-day window.
function ctxStatusTag(a: AssessmentRow): { tag: string; tagBg: string; tagColor: string } {
    if (a.is_expired) return { tag: 'Expired', tagBg: 'var(--status-critical-bg)', tagColor: 'var(--status-critical)' };
    if (a.is_passed) {
        if (a.restricted) return { tag: 'Supervised', tagBg: 'var(--status-warning-bg)', tagColor: 'var(--status-warning)' };
        const d = daysTo(a.expiry_date);
        if (d !== null && d >= 0 && d <= 30) return { tag: 'Expiring', tagBg: 'var(--status-warning-bg)', tagColor: 'var(--status-warning)' };
        return { tag: 'In date', tagBg: 'var(--status-success-bg)', tagColor: 'var(--status-success)' };
    }
    return { tag: 'Failed', tagBg: 'var(--status-critical-bg)', tagColor: 'var(--status-critical)' };
}

function permissionChips(a: AssessmentRow): { label: string; cls: string }[] {
    const c: { label: string; cls: string }[] = [];
    if (a.can_administer_unsupervised) c.push({ label: 'Unsupervised', cls: 'bg-status-success-bg text-status-success' });
    else c.push({ label: 'Supervised', cls: 'bg-status-warning-bg text-status-warning' });
    if (a.can_witness_controlled) c.push({ label: 'CD witness', cls: 'bg-accent text-primary' });
    if (a.restricted) c.push({ label: 'Restricted', cls: 'bg-status-warning-bg text-status-warning' });
    return c;
}

function AssessmentTable({ rows, onView, onRenew, onEdit, onDelete, onCtx }: { rows: AssessmentRow[]; onView: (a: AssessmentRow) => void; onRenew: (a: AssessmentRow) => void; onEdit: (a: AssessmentRow) => void; onDelete: (a: AssessmentRow) => void; onCtx: (e: ReactMouseEvent, a: AssessmentRow) => void }) {
    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            {rows.length === 0 ? <div className="px-5 py-12 text-center text-sm text-muted-foreground">No assessments match the current filters.</div> : (
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[920px] text-sm">
                        <thead>
                            <tr className="bg-muted/50 text-left text-[11px] uppercase tracking-wide text-muted-foreground">
                                <th className="px-4 py-2.5">Staff</th><th className="px-4 py-2.5">Type</th><th className="px-4 py-2.5">Score</th><th className="px-4 py-2.5">Observed</th><th className="px-4 py-2.5">Status</th><th className="px-4 py-2.5">Expiry</th><th className="px-4 py-2.5">Permissions</th><th className="px-4 py-2.5 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((a) => {
                                const sc = statusChip(a); const dte = daysTo(a.expiry_date);
                                return (
                                    <tr key={a.id} className="cursor-pointer border-b last:border-b-0 hover:bg-muted/30" onClick={() => onView(a)} onContextMenu={(e) => onCtx(e, a)}>
                                        <td className="px-4 py-3"><StaffCell a={a} /></td>
                                        <td className="px-4 py-3"><span className="rounded-full border px-2 py-0.5 text-xs capitalize text-muted-foreground">{a.assessment_type ?? '—'}</span></td>
                                        <td className="px-4 py-3">
                                            <div className="flex items-center gap-2"><div className="h-1.5 w-16 overflow-hidden rounded-full bg-muted"><div className="h-full rounded-full bg-primary" style={{ width: `${((a.total_score ?? 0) / 12) * 100}%` }} /></div><span className="tabular-nums text-xs">{a.total_score ?? 0}/{a.pass_threshold ?? 12}</span></div>
                                        </td>
                                        <td className="px-4 py-3 text-muted-foreground"><span className="inline-flex items-center gap-1"><Eye className="h-3.5 w-3.5" />{a.observed_rounds.length}</span></td>
                                        <td className="px-4 py-3"><span className={`rounded-full px-2 py-0.5 text-[11px] font-semibold ${sc.cls}`}>{sc.label}</span></td>
                                        <td className="px-4 py-3 text-muted-foreground">{fmtDate(a.expiry_date)}{dte !== null && dte >= 0 && dte <= 30 && <span className="ml-1 text-status-warning">· {dte}d</span>}</td>
                                        <td className="px-4 py-3"><div className="flex flex-wrap gap-1">{permissionChips(a).map((c) => <span key={c.label} className={`rounded-full px-1.5 py-0.5 text-[10px] font-semibold ${c.cls}`}>{c.label}</span>)}</div></td>
                                        <td className="px-4 py-3" onClick={(e) => e.stopPropagation()}>
                                            <div className="flex items-center justify-end gap-1">
                                                <Button size="sm" variant="outline" onClick={() => onRenew(a)}><RotateCcw className="h-3.5 w-3.5" />Renew</Button>
                                                <Button size="sm" variant="ghost" onClick={() => onEdit(a)} title="Edit"><Pencil className="h-3.5 w-3.5" /></Button>
                                                <Button size="sm" variant="ghost" onClick={() => onDelete(a)} title="Delete"><span className="text-status-critical">✕</span></Button>
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
    );
}

function ListCard({ empty, children }: { empty: string | null; children?: React.ReactNode }) {
    return <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">{empty ? <div className="px-5 py-12 text-center text-sm text-muted-foreground">{empty}</div> : <div className="flex flex-col">{children}</div>}</div>;
}

function CoverageMatrix({ rows, onView, onCtx }: { rows: AssessmentRow[]; onView: (a: AssessmentRow) => void; onCtx: (e: ReactMouseEvent, a: AssessmentRow) => void }) {
    const cell = (a: AssessmentRow, key: string) => {
        if (a.not_seen_areas.includes(key)) return <span className="text-muted-foreground/50">–</span>;
        return (a as unknown as Record<string, boolean>)[key] ? <span className="text-status-success">✓</span> : <span className="text-status-critical">✕</span>;
    };
    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-3 text-xs text-muted-foreground">
                <span className="flex items-center gap-1"><span className="text-status-success">✓</span> Competent</span>
                <span className="flex items-center gap-1"><span className="text-status-critical">✕</span> Not met</span>
                <span className="flex items-center gap-1"><span className="text-muted-foreground/50">–</span> Not seen</span>
            </div>
            <div className="overflow-x-auto rounded-2xl border bg-card shadow-sm">
                {rows.length === 0 ? <div className="px-5 py-12 text-center text-sm text-muted-foreground">No assessments to chart.</div> : (
                    <table className="w-full min-w-[1100px] text-center text-xs">
                        <thead>
                            <tr className="bg-muted/50 text-muted-foreground">
                                <th className="sticky left-0 bg-muted/50 px-3 py-2 text-left">Staff</th>
                                {COMPETENCY_AREAS.map((a) => <th key={a.key} className="px-2 py-2 font-medium" title={a.label}>{a.label.split(' ').map((w) => w[0]).join('')}</th>)}
                                <th className="px-3 py-2">Score</th>
                            </tr>
                        </thead>
                        <tbody>
                            {rows.map((a) => (
                                <tr key={a.id} tabIndex={0} className="cursor-pointer border-b last:border-b-0 hover:bg-muted/30 focus:outline-none focus-visible:bg-muted/30" onClick={() => onView(a)} onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onView(a); } }} onContextMenu={(e) => onCtx(e, a)}>
                                    <td className="sticky left-0 bg-card px-3 py-2 text-left font-medium">{a.user_name}</td>
                                    {COMPETENCY_AREAS.map((ar) => <td key={ar.key} className="px-2 py-2 font-semibold">{cell(a, ar.key)}</td>)}
                                    <td className="px-3 py-2 tabular-nums text-muted-foreground">{a.total_score ?? 0}/12</td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                )}
            </div>
        </div>
    );
}

/** One row of the hero alert strip — icon + message + Review jump + per-session dismiss. */
function AlertRow({ alert, onReview, onDismiss }: { alert: CompAlert; onReview: () => void; onDismiss: () => void }) {
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
