/* eslint-disable no-restricted-syntax -- The Performance & Development hub is a
 * bespoke command surface (golden hero, command bars, competency heatmap, 9-box
 * talent grid) built on shared primitives. Status colours flow through
 * StatusBadge; all other colours are semantic design tokens. */
import { Head, router } from '@inertiajs/react';
import {
    Award,
    Check,
    Download,
    GitBranch,
    Gauge,
    MessageSquare,
    MoreVertical,
    Plus,
    Rows3,
    Search,
    Sparkles,
    Sprout,
    Star,
    Target,
    TrendingUp,
    UserCheck,
    ArrowRight,
    Pencil,
    Bell,
    XCircle,
    Send,
    Trash2,
    Pin,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState, type MouseEvent, type ReactNode } from 'react';
import { toast } from 'sonner';

import { HrTabs, useHrTab, type HrTabItem } from '@/components/hr/hr-tabs';
import {
    PerformanceHero,
    type PerfHeroData,
    type PerfNeed,
    type PerfStat,
} from '@/components/hr/performance/performance-hero';
import {
    PerformanceWizards,
    type WizardKind,
    type WizardState,
    type WizardSupport,
    type Opt,
} from '@/components/hr/performance/performance-wizards';
import PageShell from '@/components/page-shell';
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type ReviewRow = { id: number; employee: string; role: string; type: string; period: string; rating: number | null; status: string };
type SupRow = { id: number; employee: string; role: string; last: string; next: string; status: string };
type GoalRow = { id: number; title: string; owner: string; type: string; kr: number; progress: number; due: string; status: string };
type DevRow = { id: number; employee: string; role: string; area: string; cur: number; tgt: number; progress: number; course: string; status: string };
type FeedbackRow = { id: number; ids: number[]; subject: string; subject_user_id: number; role: string; type: string; reviewers: number; responded: number; due: string; status: string };
type PipRow = { id: number; employee: string; role: string; reason: string; milestones: string; progress: number; review: string; status: string };

type Supervision = {
    rows: SupRow[];
    overdue_count: number;
    due_soon_count: number;
    sessions_quarter: number;
    sla_pct: number;
    spark: number[];
};
type Competencies = {
    matrix: { staff: { profile_id: number; name: string }[]; competencies: { id: number; name: string }[]; levels: Record<string, number> };
    coverage: { id: number; name: string; category: string; covered: number; total: number }[];
    skills: { id: number; name: string; count: number }[];
};
type Succession = {
    box: Record<string, string[]>;
    readiness: { label: string; count: number; tone: string }[];
    critical_roles: { id: number; role: string; risk: string; cover: string; uncovered: boolean }[];
};

type Props = {
    hero: PerfHeroData;
    reviews: ReviewRow[];
    supervision: Supervision;
    goals: GoalRow[];
    development: DevRow[];
    feedback: FeedbackRow[];
    pips: PipRow[];
    competencies: Competencies;
    succession: Succession;
    staff: Opt[];
    successionEmployees: Opt[];
    competencyOptions: Opt[];
    can: { manage: boolean };
};

/* ------------------------------------------------------------------ */
/*  Status helpers                                                     */
/* ------------------------------------------------------------------ */

const VAR: Record<string, StatusVariant> = {
    draft: 'neutral', cancelled: 'neutral', archived: 'neutral', inactive: 'neutral',
    active: 'success', approved: 'success', signed_off: 'success', acknowledged: 'success', on_track: 'success', met: 'success', ready_now: 'success', low: 'success',
    completed: 'info', in_progress: 'info', scheduled: 'info', open: 'info',
    pending: 'warning', monitoring: 'warning', at_risk: 'warning', due_soon: 'warning', developing: 'warning', medium: 'warning', probation: 'warning',
    overdue: 'critical', rejected: 'critical', declined: 'critical', expired: 'critical', off_track: 'critical', high: 'critical', critical: 'critical', uncovered: 'critical',
};
const variantOf = (s: string): StatusVariant => VAR[s] ?? 'neutral';
const pretty = (s: string) => s.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());

// Static class strings so Tailwind keeps them (no dynamic concatenation).
const CHIP_ON: Record<StatusVariant, string> = {
    success: 'border-status-success/40 bg-status-success-bg text-status-success',
    warning: 'border-status-warning/40 bg-status-warning-bg text-status-warning',
    critical: 'border-status-critical/40 bg-status-critical-bg text-status-critical',
    info: 'border-status-info/40 bg-status-info-bg text-status-info',
    neutral: 'border-primary bg-primary/10 text-primary',
};

/* ------------------------------------------------------------------ */
/*  Tab config                                                         */
/* ------------------------------------------------------------------ */

const TAB_ITEMS: HrTabItem[] = [
    { id: 'reviews', label: 'Reviews', icon: Award, tone: 'info' },
    { id: 'supervision', label: 'Supervision', icon: UserCheck, tone: 'primary' },
    { id: 'goals', label: 'Goals & OKRs', icon: Target, tone: 'success' },
    { id: 'development', label: 'Development', icon: Sprout, tone: 'info' },
    { id: 'competencies', label: 'Competencies & Skills', icon: Gauge, tone: 'violet' },
    { id: 'feedback', label: '360 Feedback', icon: MessageSquare, tone: 'warning' },
    { id: 'pips', label: 'PIPs', icon: TrendingUp, tone: 'critical' },
    { id: 'succession', label: 'Succession', icon: GitBranch, tone: 'info' },
];

const PRIMARY_NEW: Record<string, WizardKind> = {
    reviews: 'review', supervision: 'supervision', goals: 'goal', development: 'development',
    competencies: 'assess', feedback: 'feedback', pips: 'pip', succession: 'succession',
};

const initials = (n: string) => n.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();

/* ================================================================== */
/*  Page                                                              */
/* ================================================================== */

export default function PerformanceHub(props: Props) {
    const { can } = props;
    const [tab, setTab] = useHrTab('reviews', { param: 'tab', syncUrl: true });
    const [search, setSearch] = useState('');
    const [sortKey, setSortKey] = useState('');
    const [density, setDensity] = useState<'comfortable' | 'compact'>('comfortable');
    const [statusFilter, setStatusFilter] = useState('all');
    const [selected, setSelected] = useState<number[]>([]);
    const [compSub, setCompSub] = useState<'matrix' | 'competencies' | 'skills'>('matrix');
    const [wizard, setWizard] = useState<WizardState | null>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [defaultTab, setDefaultTab] = useState('reviews');
    const [pinned, setPinned] = useState<string[]>([]);

    // Restore default-view + pins from localStorage.
    useEffect(() => {
        try {
            const def = window.localStorage.getItem('perfhub:def');
            if (def) {
                setDefaultTab(def);
                if (!window.location.search.includes('tab=')) setTab(def);
            }
            setPinned(JSON.parse(window.localStorage.getItem('perfhub:pins') || '[]'));
        } catch {
            /* ignore */
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const support: WizardSupport = {
        staff: props.staff,
        reviewTypes: [
            { value: 'annual', label: 'Annual' },
            { value: 'mid_year', label: 'Mid-year' },
            { value: 'quarterly', label: 'Quarterly' },
            { value: 'ad_hoc', label: 'Ad hoc' },
        ],
        competencyOptions: props.competencyOptions,
        successionEmployees: props.successionEmployees,
    };

    const goTab = (next: string, status?: string) => {
        setTab(next);
        setStatusFilter(status ?? 'all');
        setSearch('');
        setSelected([]);
        setCompSub('matrix');
    };

    const openWiz = (kind: WizardKind, context?: WizardState['context']) => {
        if (!can.manage) {
            toast.error('You do not have permission to do that.');
            return;
        }
        setCtx(null);
        setWizard({ kind, context });
    };

    const post = (url: string, data: Record<string, unknown>, msg: string) => {
        router.post(url, data as Record<string, never>, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => toast.success(msg),
            onError: () => toast.error('Action failed.'),
        });
    };
    const del = (url: string, msg: string) => {
        router.delete(url, { preserveScroll: true, preserveState: true, onSuccess: () => toast.success(msg), onError: () => toast.error('Action failed.') });
    };

    // Hero handlers ------------------------------------------------------
    const heroHandlers = {
        onStat: (s: PerfStat) => goTab(s.tab, s.status),
        onNeed: (n: PerfNeed) => goTab(n.tab, n.status),
        onNewReview: () => openWiz('review'),
        onLogSupervision: () => openWiz('supervision'),
        onNewGoal: () => openWiz('goal'),
        onRequest360: () => openWiz('feedback'),
        onStartPip: () => openWiz('pip'),
        onExport: () => exportCsv(),
    };

    // Keyboard: `/` focus search, `n` new -------------------------------
    useEffect(() => {
        const onKey = (e: KeyboardEvent) => {
            const tag = (e.target as HTMLElement)?.tagName ?? '';
            if (/input|textarea|select/i.test(tag)) return;
            if (e.key === '/') {
                e.preventDefault();
                document.getElementById('ph-search')?.focus();
            } else if (e.key === 'n' && !wizard) {
                const k = PRIMARY_NEW[tab];
                if (k) openWiz(k);
            } else if (e.key === 'Escape') {
                setCtx(null);
            }
        };
        window.addEventListener('keydown', onKey);
        return () => window.removeEventListener('keydown', onKey);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [tab, wizard]);

    /* ---- filtering ---- */
    function filterRows<T extends { status: string }>(rows: T[], fields: (keyof T)[]): T[] {
        const q = search.trim().toLowerCase();
        let out = rows.filter((r) => {
            if (statusFilter !== 'all' && r.status !== statusFilter) return false;
            if (!q) return true;
            return fields.some((f) => String(r[f] ?? '').toLowerCase().includes(q));
        });
        if (sortKey) {
            out = [...out].sort((a, b) =>
                String(a[sortKey as keyof T]).localeCompare(String(b[sortKey as keyof T]), undefined, { numeric: true }),
            );
        }
        return out;
    }

    const currentRows = (): { id: number }[] => {
        switch (tab) {
            case 'reviews': return filterRows(props.reviews, ['employee', 'type', 'status']);
            case 'supervision': return filterRows(props.supervision.rows, ['employee', 'status']);
            case 'goals': return filterRows(props.goals, ['title', 'owner', 'status']);
            case 'development': return filterRows(props.development, ['employee', 'area', 'status']);
            case 'feedback': return filterRows(props.feedback, ['subject', 'type', 'status']);
            case 'pips': return filterRows(props.pips, ['employee', 'reason', 'status']);
            default: return [];
        }
    };

    const exportCsv = () => {
        const rows = currentRows();
        if (!rows.length) {
            toast.error('Nothing to export.');
            return;
        }
        const keys = Object.keys(rows[0]).filter((k) => k !== 'ids');
        const csv = [keys.join(','), ...rows.map((r) => keys.map((k) => JSON.stringify((r as Record<string, unknown>)[k] ?? '')).join(','))].join('\n');
        const blob = new Blob([csv], { type: 'text/csv' });
        const a = document.createElement('a');
        a.href = URL.createObjectURL(blob);
        a.download = `performance-${tab}.csv`;
        a.click();
        URL.revokeObjectURL(a.href);
        toast.success('Exported CSV');
    };

    /* ---- context menus ---- */
    const openRowCtx = (e: MouseEvent, tag: string, meta: string, items: ShiftCtxItem[]) => {
        e.preventDefault();
        e.stopPropagation();
        setCtx({ x: e.clientX, y: e.clientY, tag, meta, items });
    };

    const rowMenu = (kind: string, row: Record<string, unknown>): ShiftCtxItem[] => {
        const id = row.id as number;
        const open = (url: string): ShiftCtxItem => ({ icon: <ArrowRight className="h-4 w-4" />, label: 'Open', onClick: () => router.visit(url) });
        switch (kind) {
            case 'reviews':
                return [
                    open(`/hr/performance/reviews/${id}`),
                    { icon: <Pencil className="h-4 w-4" />, label: 'Edit', onClick: () => router.visit(`/hr/performance/reviews/${id}`) },
                    { icon: <Send className="h-4 w-4" />, label: 'Submit', onClick: () => post(`/hr/performance/reviews/${id}/submit`, {}, 'Review submitted') },
                    { icon: <Check className="h-4 w-4" />, label: 'Sign off…', tone: 'primary', onClick: () => openWiz('signoff', { reviewId: id }) },
                    { icon: <TrendingUp className="h-4 w-4" />, label: 'Start PIP from review', onClick: () => openWiz('pip', { prefill: { employee: undefined } }) },
                    { sep: true },
                    { icon: <Check className="h-4 w-4" />, label: 'Acknowledge', onClick: () => post(`/hr/performance/reviews/${id}/acknowledge`, {}, 'Review acknowledged') },
                ];
            case 'supervision':
                return [
                    open(`/hr/performance/supervision/${id}`),
                    { icon: <Check className="h-4 w-4" />, label: 'Acknowledge', onClick: () => post(`/hr/performance/supervision/${id}/acknowledge`, {}, 'Note acknowledged') },
                    { icon: <UserCheck className="h-4 w-4" />, label: 'Schedule next', onClick: () => openWiz('supervision') },
                ];
            case 'goals':
                return [
                    open(`/hr/goals/${id}`),
                    { icon: <Pencil className="h-4 w-4" />, label: 'Edit', onClick: () => router.visit(`/hr/goals/${id}`) },
                    { icon: <Check className="h-4 w-4" />, label: 'Activate', onClick: () => post(`/hr/goals/${id}/transition`, { action: 'activate' }, 'Goal activated') },
                    { icon: <Check className="h-4 w-4" />, label: 'Complete', onClick: () => post(`/hr/goals/${id}/transition`, { action: 'complete' }, 'Goal completed') },
                    { sep: true },
                    { icon: <XCircle className="h-4 w-4" />, label: 'Cancel', tone: 'critical', onClick: () => post(`/hr/goals/${id}/transition`, { action: 'cancel' }, 'Goal cancelled') },
                ];
            case 'development':
                return [
                    { icon: <ArrowRight className="h-4 w-4" />, label: 'Open development', onClick: () => router.visit('/hr/goals/development') },
                    { icon: <Download className="h-4 w-4" />, label: 'Claim expense', onClick: () => router.visit('/hr/compensation/expenses/create') },
                ];
            case 'feedback':
                return [
                    { icon: <MessageSquare className="h-4 w-4" />, label: 'View summary', onClick: () => router.visit(`/hr/feedback/summary/${row.subject_user_id}`) },
                    { icon: <Bell className="h-4 w-4" />, label: 'Remind reviewers', onClick: () => (row.ids as number[]).forEach((rid) => post(`/hr/feedback/${rid}/remind`, {}, 'Reminder sent')) },
                    { sep: true },
                    { icon: <XCircle className="h-4 w-4" />, label: 'Decline', tone: 'critical', onClick: () => (row.ids as number[]).forEach((rid) => post(`/hr/feedback/${rid}/decline`, {}, 'Request declined')) },
                    { icon: <Trash2 className="h-4 w-4" />, label: 'Cancel cycle', tone: 'critical', onClick: () => (row.ids as number[]).forEach((rid) => post(`/hr/feedback/${rid}/cancel`, {}, 'Request cancelled')) },
                ];
            case 'pips':
                return [
                    open(`/hr/performance/pips/${id}`),
                    { icon: <Pencil className="h-4 w-4" />, label: 'Open & edit', onClick: () => router.visit(`/hr/performance/pips/${id}`) },
                    { icon: <Check className="h-4 w-4" />, label: 'Acknowledge', onClick: () => post(`/hr/performance/pips/${id}/acknowledge`, {}, 'Plan acknowledged') },
                    { sep: true },
                    { icon: <XCircle className="h-4 w-4" />, label: 'Cancel', tone: 'critical', onClick: () => post(`/hr/performance/pips/${id}/cancel`, {}, 'Plan cancelled') },
                ];
            default:
                return [open('#')];
        }
    };

    const tabMenu = (id: string): ShiftCtxItem[] => [
        { icon: <ArrowRight className="h-4 w-4" />, label: 'Open', onClick: () => goTab(id) },
        {
            icon: <Star className="h-4 w-4" />,
            label: defaultTab === id ? 'Default view ✓' : 'Set as default view',
            onClick: () => {
                setDefaultTab(id);
                try { window.localStorage.setItem('perfhub:def', id); } catch { /* ignore */ }
                toast.success('Default view set');
            },
        },
        {
            icon: <Pin className="h-4 w-4" />,
            label: pinned.includes(id) ? 'Unpin tab' : 'Pin tab',
            onClick: () => {
                const next = pinned.includes(id) ? pinned.filter((x) => x !== id) : [...pinned, id];
                setPinned(next);
                try { window.localStorage.setItem('perfhub:pins', JSON.stringify(next)); } catch { /* ignore */ }
            },
        },
    ];

    /* ---- render ---- */
    const subtitle = `People & Culture · ${props.staff.length} staff`;

    return (
        <AppLayout breadcrumbs={[{ title: 'HR', href: '/hr' }, { title: 'Performance', href: '/hr/performance' }]}>
            <Head title="Performance & Development" />
            <PageShell>
                <div className="flex flex-col gap-5">
                    <PerformanceHero hero={props.hero} subtitle={subtitle} canManage={can.manage} handlers={heroHandlers} />

                    <HrTabs
                        value={tab}
                        onChange={(t) => goTab(t)}
                        items={TAB_ITEMS}
                        onItemContextMenu={(id, e) => {
                            const t = TAB_ITEMS.find((x) => x.id === id);
                            openRowCtx(e, t?.label ?? 'Tab', 'Tab options', tabMenu(id));
                        }}
                        decorations={Object.fromEntries(
                            TAB_ITEMS.filter((t) => pinned.includes(t.id) || defaultTab === t.id).map((t) => [
                                t.id,
                                <span key={t.id} className="inline-flex items-center gap-0.5">
                                    {pinned.includes(t.id) ? <Pin className="h-3 w-3" /> : null}
                                    {defaultTab === t.id ? <Star className="h-3 w-3 text-amber-500" /> : null}
                                </span>,
                            ]),
                        )}
                    />

                    <div key={tab} className="motion-safe:animate-in motion-safe:fade-in-0">
                        <Body
                            tab={tab}
                            props={props}
                            compSub={compSub}
                            setCompSub={setCompSub}
                            search={search}
                            setSearch={setSearch}
                            sortKey={sortKey}
                            setSortKey={setSortKey}
                            density={density}
                            setDensity={setDensity}
                            statusFilter={statusFilter}
                            setStatusFilter={setStatusFilter}
                            selected={selected}
                            setSelected={setSelected}
                            canManage={can.manage}
                            filterRows={filterRows}
                            openWiz={openWiz}
                            openRowCtx={openRowCtx}
                            rowMenu={rowMenu}
                            exportCsv={exportCsv}
                            post={post}
                            del={del}
                        />
                    </div>
                </div>
            </PageShell>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
            {wizard ? <PerformanceWizards state={wizard} support={support} onClose={() => setWizard(null)} /> : null}
        </AppLayout>
    );
}

/* ================================================================== */
/*  Body — per-tab content                                            */
/* ================================================================== */

type BodyProps = {
    tab: string;
    props: Props;
    compSub: 'matrix' | 'competencies' | 'skills';
    setCompSub: (s: 'matrix' | 'competencies' | 'skills') => void;
    search: string;
    setSearch: (s: string) => void;
    sortKey: string;
    setSortKey: (s: string) => void;
    density: 'comfortable' | 'compact';
    setDensity: (d: 'comfortable' | 'compact') => void;
    statusFilter: string;
    setStatusFilter: (s: string) => void;
    selected: number[];
    setSelected: (s: number[]) => void;
    canManage: boolean;
    filterRows: <T extends { status: string }>(rows: T[], fields: (keyof T)[]) => T[];
    openWiz: (k: WizardKind, ctx?: WizardState['context']) => void;
    openRowCtx: (e: MouseEvent, tag: string, meta: string, items: ShiftCtxItem[]) => void;
    rowMenu: (kind: string, row: Record<string, unknown>) => ShiftCtxItem[];
    exportCsv: () => void;
    post: (url: string, data: Record<string, unknown>, msg: string) => void;
    del: (url: string, msg: string) => void;
};

function Body(b: BodyProps) {
    const { tab, props } = b;

    if (tab === 'supervision') return <SupervisionBody {...b} />;
    if (tab === 'competencies') return <CompetenciesBody {...b} />;
    if (tab === 'succession') return <SuccessionBody {...b} />;

    // Generic table tabs ------------------------------------------------
    const configs: Record<string, { title: string; sub: string; newKey: WizardKind; newLabel: string; placeholder: string; statuses: string[]; sort: [string, string][]; cols: Col[]; rows: Record<string, unknown>[] }> = {
        reviews: {
            title: 'Reviews', sub: 'Performance reviews across all sites — submit, sign off and lock.',
            newKey: 'review', newLabel: 'New review', placeholder: 'Search reviews…',
            statuses: ['draft', 'pending', 'in_progress', 'completed', 'signed_off', 'overdue'],
            sort: [['employee', 'Employee'], ['type', 'Type'], ['status', 'Status']],
            cols: [
                { key: 'employee', label: 'Employee', kind: 'person', sub: 'role' },
                { key: 'type', label: 'Type', kind: 'strong' },
                { key: 'period', label: 'Period', kind: 'muted' },
                { key: 'rating', label: 'Rating', kind: 'rating' },
                { key: 'status', label: 'Status', kind: 'badge' },
            ],
            rows: b.filterRows(props.reviews, ['employee', 'type', 'status']),
        },
        goals: {
            title: 'Goals & OKRs', sub: 'Objectives & key results with check-in history and cascade.',
            newKey: 'goal', newLabel: 'New goal', placeholder: 'Search goals & OKRs…',
            statuses: ['on_track', 'at_risk', 'completed', 'draft'],
            sort: [['title', 'Objective'], ['progress', 'Progress'], ['status', 'Status']],
            cols: [
                { key: 'title', label: 'Objective', kind: 'title' },
                { key: 'type', label: 'Type', kind: 'muted' },
                { key: 'progress', label: 'Progress', kind: 'progress' },
                { key: 'due', label: 'Due', kind: 'muted' },
                { key: 'status', label: 'Status', kind: 'badge' },
            ],
            rows: b.filterRows(props.goals, ['title', 'owner', 'status']),
        },
        development: {
            title: 'Development', sub: 'Individual growth plans linked to competencies and courses.',
            newKey: 'development', newLabel: 'New dev goal', placeholder: 'Search development goals…',
            statuses: ['active', 'at_risk', 'completed'],
            sort: [['employee', 'Employee'], ['progress', 'Progress']],
            cols: [
                { key: 'employee', label: 'Employee', kind: 'person', sub: 'role' },
                { key: 'area', label: 'Focus area', kind: 'strong' },
                { key: 'level', label: 'Level', kind: 'level' },
                { key: 'progress', label: 'Progress', kind: 'progress' },
                { key: 'status', label: 'Status', kind: 'badge' },
            ],
            rows: b.filterRows(props.development, ['employee', 'area', 'status']),
        },
        feedback: {
            title: '360 Feedback', sub: '360-degree feedback cycles — request, remind, decline, summarise.',
            newKey: 'feedback', newLabel: 'Request 360', placeholder: 'Search 360 requests…',
            statuses: ['in_progress', 'completed', 'declined', 'expired'],
            sort: [['subject', 'Subject'], ['status', 'Status']],
            cols: [
                { key: 'subject', label: 'Subject', kind: 'person', sub: 'role' },
                { key: 'type', label: 'Type', kind: 'strong' },
                { key: 'reviewers', label: 'Responses', kind: 'reviewers' },
                { key: 'due', label: 'Due', kind: 'muted' },
                { key: 'status', label: 'Status', kind: 'badge' },
            ],
            rows: b.filterRows(props.feedback, ['subject', 'type', 'status']),
        },
        pips: {
            title: 'PIPs', sub: 'Performance improvement plans with milestones and outcomes.',
            newKey: 'pip', newLabel: 'Start PIP', placeholder: 'Search PIPs…',
            statuses: ['active', 'monitoring', 'completed', 'cancelled'],
            sort: [['employee', 'Employee'], ['status', 'Status']],
            cols: [
                { key: 'employee', label: 'Employee', kind: 'person', sub: 'role' },
                { key: 'reason', label: 'Reason', kind: 'strong' },
                { key: 'milestones', label: 'Milestones', kind: 'muted' },
                { key: 'progress', label: 'Progress', kind: 'progress' },
                { key: 'review', label: 'Review date', kind: 'muted' },
                { key: 'status', label: 'Status', kind: 'badge' },
            ],
            rows: b.filterRows(props.pips, ['employee', 'reason', 'status']),
        },
    };

    const cfg = configs[tab];
    if (!cfg) return null;

    return (
        <div>
            <SectionHead title={cfg.title} sub={cfg.sub} />
            <CommandBar b={b} placeholder={cfg.placeholder} statuses={cfg.statuses} sort={cfg.sort} newKey={cfg.newKey} newLabel={cfg.newLabel} />
            <DataTable b={b} kind={tab} cols={cfg.cols} rows={cfg.rows} />
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Shared pieces                                                      */
/* ------------------------------------------------------------------ */

function SectionHead({ title, sub, right }: { title: string; sub?: string; right?: ReactNode }) {
    return (
        <div className="mb-3.5 flex items-end justify-between gap-3">
            <div>
                <h2 className="text-lg font-bold tracking-tight">{title}</h2>
                {sub ? <p className="mt-0.5 text-[13px] text-muted-foreground">{sub}</p> : null}
            </div>
            {right}
        </div>
    );
}

function CommandBar({
    b,
    placeholder,
    statuses,
    sort,
    newKey,
    newLabel,
}: {
    b: BodyProps;
    placeholder: string;
    statuses: string[];
    sort: [string, string][];
    newKey: WizardKind;
    newLabel: string;
}) {
    const chips = ['all', ...statuses];
    return (
        <div className="mb-3.5 flex flex-col gap-2.5">
            <div className="flex flex-wrap items-center gap-2.5">
                <div className="relative max-w-[380px] flex-1 basis-[260px]">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-[15px] w-[15px] -translate-y-1/2 text-muted-foreground" />
                    <input
                        id="ph-search"
                        value={b.search}
                        placeholder={placeholder}
                        onChange={(e) => b.setSearch(e.target.value)}
                        className="w-full rounded-[10px] border border-border bg-card py-2 pl-9 pr-3 text-[13px] outline-none focus:border-ring"
                    />
                </div>
                <select
                    value={b.sortKey}
                    onChange={(e) => b.setSortKey(e.target.value)}
                    aria-label="Sort"
                    className="rounded-[10px] border border-border bg-card px-2.5 py-2 text-[13px] text-foreground outline-none"
                >
                    <option value="">Sort</option>
                    {sort.map((s) => (
                        <option key={s[0]} value={s[0]}>
                            {s[1]}
                        </option>
                    ))}
                </select>
                <button
                    type="button"
                    title="Toggle density"
                    onClick={() => b.setDensity(b.density === 'comfortable' ? 'compact' : 'comfortable')}
                    className={cn(
                        'grid h-9 w-9 place-items-center rounded-[10px] border border-border bg-card',
                        b.density === 'compact' ? 'text-primary' : 'text-muted-foreground',
                    )}
                >
                    <Rows3 className="h-[15px] w-[15px]" />
                </button>
                <button
                    type="button"
                    onClick={b.exportCsv}
                    className="inline-flex items-center gap-2 rounded-[10px] border border-border bg-card px-3 py-2 text-[13px] font-semibold"
                >
                    <Download className="h-[14px] w-[14px]" />
                    Export
                </button>
                {b.canManage ? (
                    <button
                        type="button"
                        onClick={() => b.openWiz(newKey)}
                        className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-3.5 py-2 text-[13px] font-semibold text-primary-foreground shadow-sm"
                    >
                        <Plus className="h-[15px] w-[15px]" />
                        {newLabel}
                    </button>
                ) : null}
            </div>

            <div className="flex flex-wrap gap-1.5">
                {chips.map((s) => {
                    const on = b.statusFilter === s;
                    const v = s === 'all' ? null : variantOf(s);
                    return (
                        <button
                            key={s}
                            type="button"
                            onClick={() => b.setStatusFilter(s)}
                            className={cn(
                                'rounded-full border px-3 py-1 text-xs font-semibold transition-colors',
                                on
                                    ? CHIP_ON[v ?? 'neutral']
                                    : 'border-border bg-card text-muted-foreground hover:text-foreground',
                            )}
                        >
                            {s === 'all' ? 'All' : pretty(s)}
                        </button>
                    );
                })}
            </div>

            {b.selected.length ? (
                <div className="flex items-center gap-3 rounded-[10px] border border-primary/30 bg-primary/[0.08] px-3 py-2 motion-safe:animate-in motion-safe:fade-in-0">
                    <span className="text-[13px] font-bold text-primary">{b.selected.length} selected</span>
                    <button type="button" onClick={() => toast.success('Bulk reminder sent')} className="text-[13px] font-semibold text-primary">Remind</button>
                    <button type="button" onClick={b.exportCsv} className="text-[13px] font-semibold text-primary">Export</button>
                    <button type="button" onClick={() => b.setSelected([])} className="ml-auto text-[13px] font-semibold text-muted-foreground">Clear</button>
                </div>
            ) : null}
        </div>
    );
}

type Col = { key: string; label: string; kind: 'person' | 'badge' | 'rating' | 'progress' | 'level' | 'reviewers' | 'muted' | 'strong' | 'title'; sub?: string; align?: 'left' | 'right' };

function DataTable({ b, kind, cols, rows }: { b: BodyProps; kind: string; cols: Col[]; rows: Record<string, unknown>[] }) {
    const compact = b.density === 'compact';
    const pad = compact ? 'px-3.5 py-2' : 'px-3.5 py-3';
    if (!rows.length) return <EmptyState onClear={() => { b.setSearch(''); b.setStatusFilter('all'); }} />;

    const allChecked = b.selected.length === rows.length && rows.length > 0;

    return (
        <div className="overflow-hidden rounded-[14px] border border-border bg-card shadow-sm">
            <table className="w-full border-collapse" style={{ fontSize: compact ? 12.5 : 13 }}>
                <thead>
                    <tr className="border-b border-border bg-muted/40">
                        <th className={cn('w-10', pad)}>
                            <input
                                type="checkbox"
                                checked={allChecked}
                                onChange={(e) => b.setSelected(e.target.checked ? rows.map((r) => r.id as number) : [])}
                                className="h-3.5 w-3.5 accent-[var(--primary)]"
                            />
                        </th>
                        {cols.map((c) => (
                            <th key={c.key} className={cn('text-left text-[10.5px] font-bold uppercase tracking-wide text-muted-foreground', pad, c.align === 'right' && 'text-right')}>
                                {c.label}
                            </th>
                        ))}
                        <th className="w-8" />
                    </tr>
                </thead>
                <tbody>
                    {rows.map((r, ri) => {
                        const checked = b.selected.includes(r.id as number);
                        const tagName = String(r.employee ?? r.subject ?? r.title ?? 'Item');
                        return (
                            <tr
                                key={r.id as number}
                                onContextMenu={(e) => b.openRowCtx(e, kind, tagName, b.rowMenu(kind, r))}
                                className={cn('cursor-pointer border-b border-border transition-colors last:border-0 hover:bg-muted/50', checked && 'bg-primary/[0.05]')}
                            >
                                <td className={pad} onClick={(e) => e.stopPropagation()}>
                                    <input
                                        type="checkbox"
                                        checked={checked}
                                        onChange={(e) => b.setSelected(e.target.checked ? [...b.selected, r.id as number] : b.selected.filter((x) => x !== r.id))}
                                        className="h-3.5 w-3.5 accent-[var(--primary)]"
                                    />
                                </td>
                                {cols.map((c) => (
                                    <td key={c.key} className={cn(pad, c.align === 'right' && 'text-right')}>
                                        <Cell col={c} row={r} />
                                    </td>
                                ))}
                                <td className={cn(pad, 'text-right')} onClick={(e) => { e.stopPropagation(); b.openRowCtx(e, kind, tagName, b.rowMenu(kind, r)); }}>
                                    <MoreVertical className="inline h-4 w-4 text-muted-foreground" />
                                </td>
                            </tr>
                        );
                    })}
                </tbody>
            </table>
        </div>
    );
}

function Cell({ col, row }: { col: Col; row: Record<string, unknown> }) {
    const v = row[col.key];
    if (col.kind === 'person') {
        const name = String(v ?? '');
        return (
            <div className="flex items-center gap-2.5">
                <span className="grid h-[30px] w-[30px] flex-none place-items-center rounded-[9px] bg-accent text-[11px] font-bold text-accent-foreground">
                    {initials(name)}
                </span>
                <div className="min-w-0">
                    <div className="font-semibold text-foreground">{name}</div>
                    {col.sub ? <div className="text-[11.5px] text-muted-foreground">{String(row[col.sub] ?? '')}</div> : null}
                </div>
            </div>
        );
    }
    if (col.kind === 'badge') return <StatusBadge variant={variantOf(String(v))} label={pretty(String(v))} size="sm" />;
    if (col.kind === 'rating')
        return v == null ? (
            <span className="text-muted-foreground">—</span>
        ) : (
            <span className="inline-flex items-center gap-1 font-bold">
                <Star className="h-3.5 w-3.5 text-amber-500" />
                {Number(v).toFixed(1)}
                <span className="text-[11px] font-medium text-muted-foreground">/5</span>
            </span>
        );
    if (col.kind === 'progress') {
        const p = Number(v);
        return (
            <div className="flex min-w-[130px] items-center gap-2.5">
                <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                    <div
                        className={cn('h-full rounded-full', p >= 100 ? 'bg-status-success' : p < 35 ? 'bg-status-warning' : 'bg-primary')}
                        style={{ width: `${p}%` }}
                    />
                </div>
                <span className="w-8 text-right text-[11.5px] font-bold text-muted-foreground">{p}%</span>
            </div>
        );
    }
    if (col.kind === 'level')
        return (
            <span className="inline-flex items-center gap-1.5 font-semibold">
                L{String(row.cur)} <ArrowRight className="h-3 w-3" /> <span className="text-primary">L{String(row.tgt)}</span>
            </span>
        );
    if (col.kind === 'reviewers')
        return (
            <span className="inline-flex items-center gap-1.5">
                <UserCheck className="h-3.5 w-3.5 text-muted-foreground" />
                <span className="font-semibold">{String(row.responded)} / {String(row.reviewers)}</span>
                <span className="text-[11px] text-muted-foreground">responded</span>
            </span>
        );
    if (col.kind === 'title')
        return (
            <div>
                <div className="max-w-[340px] font-semibold">{String(v)}</div>
                <div className="mt-0.5 flex gap-2 text-[11.5px] text-muted-foreground">
                    <span>{String(row.owner ?? '')}</span>
                    {row.kr ? <span>· {String(row.kr)} KR</span> : null}
                </div>
            </div>
        );
    if (col.kind === 'muted') return <span className="text-muted-foreground">{v ? String(v) : '—'}</span>;
    if (col.kind === 'strong') return <span className="font-semibold text-foreground">{String(v)}</span>;
    return <span>{v == null ? '—' : String(v)}</span>;
}

function EmptyState({ onClear }: { onClear: () => void }) {
    return (
        <div className="rounded-[14px] border border-dashed border-border bg-card px-6 py-14 text-center">
            <div className="mx-auto mb-3.5 grid h-12 w-12 place-items-center rounded-[14px] bg-muted text-muted-foreground">
                <Search className="h-6 w-6" />
            </div>
            <div className="text-[15px] font-bold">Nothing matches your filters</div>
            <p className="mt-1 text-[13px] text-muted-foreground">Try clearing the search or status filter.</p>
            <button type="button" onClick={onClear} className="mt-4 rounded-[10px] border border-border bg-card px-3 py-2 text-[13px] font-semibold">
                Clear filters
            </button>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Supervision body (trend cards + table)                            */
/* ------------------------------------------------------------------ */

function SupervisionBody(b: BodyProps) {
    const s = b.props.supervision;
    const max = Math.max(1, ...s.spark);
    return (
        <div>
            <SectionHead title="Supervision" sub="1:1 supervision cadence, acknowledgements and trends." />
            <div className="mb-4 grid grid-cols-1 gap-3.5 md:grid-cols-3">
                <MiniCard>
                    <div className="flex items-start justify-between">
                        <div>
                            <div className="text-xs font-semibold text-muted-foreground">Sessions logged</div>
                            <div className="text-[11px] text-muted-foreground/80">this quarter</div>
                        </div>
                    </div>
                    <div className="mt-2.5 flex items-end gap-3">
                        <div className="text-3xl font-bold leading-none tracking-tight">{s.sessions_quarter}</div>
                        <div className="flex h-10 flex-1 items-end gap-0.5">
                            {s.spark.map((d, i) => (
                                <div
                                    key={i}
                                    className={cn('flex-1 rounded-sm', i === s.spark.length - 1 ? 'bg-primary' : 'bg-primary/30')}
                                    style={{ height: `${(d / max) * 100}%` }}
                                />
                            ))}
                        </div>
                    </div>
                </MiniCard>
                <MiniCard>
                    <div className="text-xs font-semibold text-muted-foreground">1:1 acknowledgement SLA</div>
                    <div className="mt-2 flex items-center gap-3.5">
                        <Gauge45 pct={s.sla_pct} />
                        <div className="text-xs text-muted-foreground">target 95%</div>
                    </div>
                </MiniCard>
                <MiniCard>
                    <div className="text-xs font-semibold text-muted-foreground">Overdue 1:1s</div>
                    <div className={cn('mt-2 text-3xl font-bold tracking-tight', s.overdue_count > 0 ? 'text-status-warning' : 'text-foreground')}>{s.overdue_count}</div>
                    <div className="mt-0.5 text-xs text-muted-foreground">{s.due_soon_count} due in 7 days</div>
                </MiniCard>
            </div>
            <CommandBar
                b={b}
                placeholder="Search supervision notes…"
                statuses={['scheduled', 'pending', 'acknowledged', 'overdue', 'draft']}
                sort={[['employee', 'Employee'], ['next', 'Next due'], ['status', 'Status']]}
                newKey="supervision"
                newLabel="Log supervision"
            />
            <DataTable
                b={b}
                kind="supervision"
                cols={[
                    { key: 'employee', label: 'Employee', kind: 'person', sub: 'role' },
                    { key: 'last', label: 'Last session', kind: 'muted' },
                    { key: 'next', label: 'Next due', kind: 'strong' },
                    { key: 'status', label: 'Status', kind: 'badge' },
                ]}
                rows={b.filterRows(s.rows, ['employee', 'status'])}
            />
        </div>
    );
}

function MiniCard({ children }: { children: ReactNode }) {
    return <div className="rounded-[14px] border border-border bg-card p-4 shadow-sm">{children}</div>;
}

function Gauge45({ pct }: { pct: number }) {
    const r = 26;
    const c = 2 * Math.PI * r;
    return (
        <div className="relative h-16 w-16">
            <svg width={64} height={64} className="-rotate-90">
                <circle cx={32} cy={32} r={r} fill="none" stroke="var(--muted)" strokeWidth={7} />
                <circle
                    cx={32}
                    cy={32}
                    r={r}
                    fill="none"
                    stroke={pct >= 90 ? 'var(--status-success)' : 'var(--status-warning)'}
                    strokeWidth={7}
                    strokeLinecap="round"
                    strokeDasharray={c}
                    strokeDashoffset={c * (1 - pct / 100)}
                />
            </svg>
            <span className="absolute inset-0 grid place-items-center text-[15px] font-bold">{pct}%</span>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Competencies body (sub-tabs + matrix)                             */
/* ------------------------------------------------------------------ */

function CompetenciesBody(b: BodyProps) {
    const c = b.props.competencies;
    const subTabs: [typeof b.compSub, string][] = [
        ['matrix', 'Matrix'],
        ['competencies', 'Competencies'],
        ['skills', 'Skills'],
    ];
    const colors = ['bg-muted text-muted-foreground', 'bg-status-critical-bg text-status-critical', 'bg-status-warning-bg text-status-warning', 'bg-status-info-bg text-status-info', 'bg-status-success-bg text-status-success', 'bg-status-success-bg text-status-success'];

    return (
        <div>
            <SectionHead
                title="Competencies & Skills"
                sub="Capability matrix, sign-off and skills coverage."
                right={
                    b.canManage ? (
                        <button type="button" onClick={() => b.openWiz('assess')} className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-3.5 py-2 text-[13px] font-semibold text-primary-foreground shadow-sm">
                            <Plus className="h-[15px] w-[15px]" />
                            Assess
                        </button>
                    ) : null
                }
            />
            <div className="mb-4 inline-flex gap-1 rounded-[11px] border border-border bg-card p-1">
                {subTabs.map(([k, label]) => (
                    <button
                        key={k}
                        type="button"
                        onClick={() => b.setCompSub(k)}
                        className={cn('rounded-lg px-3.5 py-1.5 text-[13px] font-semibold', b.compSub === k ? 'bg-primary text-primary-foreground' : 'text-muted-foreground')}
                    >
                        {label}
                    </button>
                ))}
            </div>

            {b.compSub === 'matrix' ? (
                c.matrix.staff.length === 0 || c.matrix.competencies.length === 0 ? (
                    <div className="rounded-[14px] border border-dashed border-border bg-card px-6 py-14 text-center text-[13px] text-muted-foreground">
                        No competency assessments yet. Use <span className="font-semibold text-foreground">Assess</span> to record the first.
                    </div>
                ) : (
                    <div className="overflow-auto rounded-[14px] border border-border bg-card shadow-sm">
                        <table className="w-full border-collapse text-[12.5px]">
                            <thead>
                                <tr>
                                    <th className="sticky left-0 z-10 border-b border-border bg-card px-3.5 py-3 text-left text-[10.5px] font-bold uppercase tracking-wide text-muted-foreground">Staff</th>
                                    {c.matrix.competencies.map((comp) => (
                                        <th key={comp.id} className="min-w-[78px] border-b border-border px-2 py-3 text-[10.5px] font-bold text-muted-foreground">{comp.name}</th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {c.matrix.staff.map((st, si) => (
                                    <tr key={st.profile_id} className={cn(si < c.matrix.staff.length - 1 && 'border-b border-border')}>
                                        <td className="sticky left-0 z-10 border-r border-border bg-card px-3.5 py-2.5 font-semibold whitespace-nowrap">
                                            <span className="inline-flex items-center gap-2">
                                                <span className="grid h-6 w-6 place-items-center rounded-lg bg-accent text-[10px] font-bold text-accent-foreground">{initials(st.name)}</span>
                                                {st.name}
                                            </span>
                                        </td>
                                        {c.matrix.competencies.map((comp) => {
                                            const lvl = c.matrix.levels[`${st.profile_id}-${comp.id}`] ?? 0;
                                            return (
                                                <td key={comp.id} className="p-1.5 text-center">
                                                    <div className={cn('grid h-[34px] place-items-center rounded-lg border border-border text-[12px] font-bold', colors[lvl] ?? colors[0])}>
                                                        {lvl === 0 ? '—' : `L${lvl}`}
                                                    </div>
                                                </td>
                                            );
                                        })}
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <div className="flex flex-wrap items-center gap-3.5 border-t border-border px-3.5 py-2.5 text-[11px] text-muted-foreground">
                            {[['L1', 'bg-status-critical'], ['L2', 'bg-status-warning'], ['L3', 'bg-status-info'], ['L4', 'bg-status-success']].map(([l, bg]) => (
                                <span key={l} className="inline-flex items-center gap-1.5">
                                    <span className={cn('h-2.5 w-2.5 rounded-sm', bg)} />
                                    {l}
                                </span>
                            ))}
                            <span className="ml-auto">Right-click a row to assess or sign off</span>
                        </div>
                    </div>
                )
            ) : b.compSub === 'competencies' ? (
                c.coverage.length === 0 ? (
                    <div className="rounded-[14px] border border-dashed border-border bg-card px-6 py-14 text-center text-[13px] text-muted-foreground">No competencies defined yet.</div>
                ) : (
                    <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        {c.coverage.map((d) => {
                            const pct = d.total ? Math.round((d.covered / d.total) * 100) : 0;
                            return (
                                <MiniCard key={d.id}>
                                    <div className="flex items-start justify-between gap-2">
                                        <div className="font-bold">{d.name}</div>
                                        <StatusBadge variant={pct >= 90 ? 'success' : pct >= 60 ? 'warning' : 'critical'} label={pct >= 90 ? 'Met' : pct >= 60 ? 'Developing' : 'Gap'} size="sm" />
                                    </div>
                                    <div className="mb-3 mt-1 text-[11.5px] text-muted-foreground">{d.category} · {d.covered} of {d.total} staff competent</div>
                                    <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                        <div className={cn('h-full rounded-full', pct >= 90 ? 'bg-status-success' : pct >= 60 ? 'bg-primary' : 'bg-status-warning')} style={{ width: `${pct}%` }} />
                                    </div>
                                </MiniCard>
                            );
                        })}
                    </div>
                )
            ) : c.skills.length === 0 ? (
                <div className="rounded-[14px] border border-dashed border-border bg-card px-6 py-14 text-center text-[13px] text-muted-foreground">No skills recorded yet.</div>
            ) : (
                <div className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    {c.skills.map((sk) => (
                        <MiniCard key={sk.id}>
                            <div className="flex items-center gap-2.5">
                                <span className="grid h-9 w-9 place-items-center rounded-[10px] bg-accent text-primary">
                                    <Sparkles className="h-[18px] w-[18px]" />
                                </span>
                                <div>
                                    <div className="text-[13px] font-bold">{sk.name}</div>
                                    <div className="text-[11.5px] text-muted-foreground">{sk.count} staff hold this</div>
                                </div>
                            </div>
                        </MiniCard>
                    ))}
                </div>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Succession body (9-box + readiness + critical roles)              */
/* ------------------------------------------------------------------ */

function SuccessionBody(b: BodyProps) {
    const s = b.props.succession;
    const xLab = ['Low', 'Medium', 'High'];
    const cellTone = (x: number, y: number) => {
        const sum = x + y;
        return sum >= 3 ? 'bg-status-success-bg' : sum >= 2 ? 'bg-status-info-bg' : sum >= 1 ? 'bg-status-warning-bg' : 'bg-muted';
    };
    const readinessBar: Record<string, string> = { success: 'bg-status-success', info: 'bg-status-info', warning: 'bg-status-warning', neutral: 'bg-muted-foreground' };
    const maxReady = Math.max(1, ...s.readiness.map((r) => r.count));

    return (
        <div>
            <SectionHead
                title="Succession"
                sub="9-box talent grid, readiness pipeline and critical-role cover."
                right={
                    b.canManage ? (
                        <button type="button" onClick={() => b.openWiz('succession')} className="inline-flex items-center gap-2 rounded-[10px] bg-primary px-3.5 py-2 text-[13px] font-semibold text-primary-foreground shadow-sm">
                            <Plus className="h-[15px] w-[15px]" />
                            New plan
                        </button>
                    ) : null
                }
            />
            <div className="grid grid-cols-1 gap-4 lg:grid-cols-[1.5fr_1fr]">
                <MiniCard>
                    <div className="mb-3 text-[13px] font-bold">Performance × Potential</div>
                    <div className="flex gap-2">
                        <div className="grid place-items-center text-[10px] font-bold uppercase tracking-wide text-muted-foreground [writing-mode:vertical-rl] [transform:rotate(180deg)]">Potential</div>
                        <div className="flex-1">
                            <div className="grid grid-cols-3 gap-1.5">
                                {[2, 1, 0].map((y) =>
                                    [0, 1, 2].map((x) => {
                                        const people = s.box[`${x}-${y}`] ?? [];
                                        return (
                                            <div
                                                key={`${x}-${y}`}
                                                onContextMenu={(e) => b.openRowCtx(e, 'Succession', `Performance ${xLab[x]} · Potential ${xLab[y]}`, [{ icon: <Plus className="h-4 w-4" />, label: 'New plan', onClick: () => b.openWiz('succession') }])}
                                                className={cn('flex min-h-[74px] flex-col gap-1 rounded-[10px] border border-border p-1.5', cellTone(x, y))}
                                            >
                                                {people.map((p, i) => (
                                                    <span key={`${p}-${i}`} className="rounded-md bg-card px-1.5 py-1 text-[11px] font-semibold shadow-sm">{p}</span>
                                                ))}
                                            </div>
                                        );
                                    }),
                                )}
                            </div>
                            <div className="mt-1.5 grid grid-cols-3 text-center text-[10px] font-bold text-muted-foreground">
                                {xLab.map((l) => <span key={l}>{l}</span>)}
                            </div>
                            <div className="mt-1 text-center text-[10px] font-bold uppercase tracking-wide text-muted-foreground">Performance</div>
                        </div>
                    </div>
                </MiniCard>

                <div className="flex flex-col gap-4">
                    <MiniCard>
                        <div className="mb-3 text-[13px] font-bold">Readiness pipeline</div>
                        {s.readiness.map((r) => (
                            <div key={r.label} className="mb-2.5 flex items-center gap-2.5">
                                <span className="w-[90px] text-xs font-semibold text-muted-foreground">{r.label}</span>
                                <div className="h-2 flex-1 overflow-hidden rounded-full bg-muted">
                                    <div className={cn('h-full rounded-full', readinessBar[r.tone] ?? 'bg-muted-foreground')} style={{ width: `${(r.count / maxReady) * 100}%` }} />
                                </div>
                                <span className="w-5 text-right text-xs font-bold">{r.count}</span>
                            </div>
                        ))}
                    </MiniCard>
                    <MiniCard>
                        <div className="mb-2.5 text-[13px] font-bold">Critical roles</div>
                        {s.critical_roles.length === 0 ? (
                            <div className="py-3 text-[13px] text-muted-foreground">No succession plans yet.</div>
                        ) : (
                            s.critical_roles.map((r) => (
                                <div
                                    key={r.id}
                                    onContextMenu={(e) => b.openRowCtx(e, r.role, `Cover: ${r.cover}`, [
                                        { icon: <ArrowRight className="h-4 w-4" />, label: 'Open plan', onClick: () => router.visit(`/hr/succession/${r.id}`) },
                                        { icon: <Trash2 className="h-4 w-4" />, label: 'Delete plan', tone: 'critical', onClick: () => b.del(`/hr/succession/${r.id}`, 'Plan deleted') },
                                    ])}
                                    className="flex items-center justify-between gap-2 border-b border-border py-2 last:border-0"
                                >
                                    <span className="text-[12.5px] font-semibold">{r.role}</span>
                                    <span className="inline-flex items-center gap-2">
                                        <span className="text-[11.5px] text-muted-foreground">{r.cover}</span>
                                        <StatusBadge variant={variantOf(r.risk)} label={pretty(r.risk)} size="sm" />
                                    </span>
                                </div>
                            ))
                        )}
                    </MiniCard>
                </div>
            </div>
        </div>
    );
}
