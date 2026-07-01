/* eslint-disable no-restricted-syntax -- The hub hero, segmented cycle selector,
 * objective rows and right-click menu mirror the Goals & OKR design prototype and
 * use styled native controls. Every colour is a semantic design token. */
import { PerformanceTabs } from '@/components/hr';
import {
    Avatar,
    type CascadeNode,
    type Confidence,
    type Cycle,
    DEV_CAT_BADGE,
    DEV_STATUS_BADGE,
    type DevelopmentPlan,
    formatDate,
    type GoalType,
    initials,
    type Objective,
    type ObjectiveTemplate,
    PRIORITY_DOT,
    ProgressBar,
    RAG,
    RagPill,
    STATUS_BADGE,
    STATUS_LABEL,
    TYPE_LABEL,
    TypeBadge,
    barColor,
    checkinLabel,
    formatKrMeasure,
} from '@/components/hr/goals/okr-shared';
import { CheckinWizard } from '@/components/hr/goals/checkin-wizard';
import { DevelopmentWizard } from '@/components/hr/goals/development-wizard';
import { ObjectiveWizard } from '@/components/hr/goals/objective-wizard';
import { PageHero, PageLayout } from '@/components/page';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { type BreadcrumbItem } from '@/types';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    ArrowRight,
    Ban,
    BarChart3,
    Check,
    CheckCircle2,
    ChevronDown,
    ChevronRight,
    Copy,
    Download,
    LayoutGrid,
    Layers,
    List,
    MoreVertical,
    PauseCircle,
    Pencil,
    Pin,
    Plus,
    Rows3,
    Search,
    Sprout,
    Star,
    Tag as TagIcon,
    Target,
    Trash2,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';

interface Analytics {
    total: number;
    active: number;
    completed: number;
    draft: number;
    overdue: number;
    on_track: number;
    at_risk: number;
    off_track: number;
    avg_progress: number;
    completion_rate: number;
    progress_by_type: Array<{ type: string; avg_progress: number; count: number }>;
}

interface Props {
    objectives: Objective[];
    developmentPlans: DevelopmentPlan[];
    users: Array<{ id: number; name: string }>;
    parentGoals: Array<{ id: number; title: string }>;
    cycles: Cycle[];
    templates: ObjectiveTemplate[];
    allTags: string[];
    competencies: Array<{ id: number; name: string }>;
    selectedCycleId: number | null;
    currentCycleId: number | null;
    analytics: Analytics;
    cascadeTree: CascadeNode[];
    can: { manage: boolean };
    defaultTab: string | null;
}

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Goals & OKRs', href: '/hr/goals' },
];

type HubTab = 'objectives' | 'alignment' | 'development' | 'analytics';

interface CtxItem {
    label?: string;
    icon?: typeof Target;
    danger?: boolean;
    divider?: boolean;
    onClick?: () => void;
}

/* ------------------------------------------------------------------ */
/*  Toast (lightweight)                                               */
/* ------------------------------------------------------------------ */

function useToast() {
    const [toast, setToast] = useState<{ msg: string; tone: 'ok' | 'warn' } | null>(null);
    const show = (msg: string, tone: 'ok' | 'warn' = 'ok') => {
        setToast({ msg, tone });
        window.setTimeout(() => setToast(null), 2600);
    };
    return { toast, show };
}

export default function GoalsHub({
    objectives,
    developmentPlans,
    users,
    parentGoals,
    cycles,
    templates,
    allTags,
    competencies,
    selectedCycleId,
    currentCycleId,
    analytics,
    cascadeTree,
    can,
    defaultTab,
}: Props) {
    /* ---- tab + persisted prefs ---- */
    const [tab, setTab] = useState<HubTab>('objectives');
    const [defaultTabPref, setDefaultTabPref] = useState<HubTab>('objectives');
    const [pins, setPins] = useState<Record<string, boolean>>({});

    useEffect(() => {
        let dt: HubTab = 'objectives';
        try {
            dt = (localStorage.getItem('okr_default_tab') as HubTab) || 'objectives';
            setPins(JSON.parse(localStorage.getItem('okr_pins') || '{}'));
        } catch {
            /* ignore */
        }
        setDefaultTabPref(dt);
        // ?tab= deep-link wins over the persisted default.
        const initial = (defaultTab as HubTab) || dt;
        setTab(['objectives', 'alignment', 'development', 'analytics'].includes(initial) ? initial : 'objectives');
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /* ---- toolbar state (client-side) ---- */
    const [search, setSearch] = useState('');
    const [fStatus, setFStatus] = useState('');
    const [fType, setFType] = useState('');
    const [fConfidence, setFConfidence] = useState('');
    const [fOwner, setFOwner] = useState('');
    const [fTag, setFTag] = useState('');
    const [sort, setSort] = useState('progress');
    const [view, setView] = useState<'list' | 'table' | 'board'>('list');
    const [expanded, setExpanded] = useState<Record<number, boolean>>({});
    const [selected, setSelected] = useState<Record<number, boolean>>({});

    /* ---- development toolbar ---- */
    const [devSearch, setDevSearch] = useState('');
    const [devStatus, setDevStatus] = useState('');
    const [devCat, setDevCat] = useState('');

    /* ---- modals ---- */
    const [objWizard, setObjWizard] = useState<{ open: boolean; goal: Objective | null; parentId: number | null }>({
        open: false,
        goal: null,
        parentId: null,
    });
    const [checkin, setCheckin] = useState<{ open: boolean; obj: Objective | null }>({ open: false, obj: null });
    const [devWizard, setDevWizard] = useState(false);
    const [reparent, setReparent] = useState<Objective | null>(null);

    /* ---- context menu + toast ---- */
    const [ctx, setCtx] = useState<{ open: boolean; x: number; y: number; title: string; items: CtxItem[] }>({
        open: false,
        x: 0,
        y: 0,
        title: '',
        items: [],
    });
    const { toast, show } = useToast();

    const openCtx = (e: React.MouseEvent, title: string, items: CtxItem[]) => {
        e.preventDefault();
        e.stopPropagation();
        const x = Math.min(e.clientX, window.innerWidth - 244);
        const y = Math.min(e.clientY, window.innerHeight - (items.length * 38 + 60));
        setCtx({ open: true, x: Math.max(8, x), y: Math.max(8, y), title, items });
    };
    const closeCtx = () => setCtx((c) => ({ ...c, open: false }));

    /* ---- cycle selector ---- */
    const changeCycle = (id: number | 'all') => {
        router.get('/hr/goals', { cycle: id === 'all' ? 'all' : id, tab }, { preserveScroll: true, preserveState: false });
    };
    const selectedCycle = cycles.find((c) => c.id === selectedCycleId);

    /* ---- objectives filter/sort ---- */
    const ownerOptions = useMemo(() => [...new Set(objectives.map((o) => o.user?.name).filter(Boolean))] as string[], [objectives]);

    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        const list = objectives.filter((o) => {
            if (fStatus && o.status !== fStatus) return false;
            if (fType && o.goal_type !== fType) return false;
            if (fConfidence && o.confidence !== fConfidence) return false;
            if (fOwner && o.user?.name !== fOwner) return false;
            if (fTag && !(o.tags ?? []).includes(fTag)) return false;
            if (q) {
                const hay = `${o.title} ${o.user?.name ?? ''} ${o.category ?? ''} ${o.description ?? ''}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });
        const pr: Record<string, number> = { high: 0, medium: 1, low: 2 };
        const rg: Record<string, number> = { off_track: 0, at_risk: 1, on_track: 2 };
        return [...list].sort((a, b) => {
            if (sort === 'progress') return b.progress_percentage - a.progress_percentage;
            if (sort === 'due') return (a.due_date ?? '').localeCompare(b.due_date ?? '');
            if (sort === 'priority') return pr[a.priority] - pr[b.priority];
            if (sort === 'owner') return (a.user?.name ?? '').localeCompare(b.user?.name ?? '');
            if (sort === 'confidence') return rg[a.confidence] - rg[b.confidence];
            if (sort === 'checkin') return (b.last_checkin_days ?? 999) - (a.last_checkin_days ?? 999);
            return 0;
        });
    }, [objectives, search, fStatus, fType, fConfidence, fOwner, fTag, sort]);

    const selectedIds = Object.keys(selected)
        .filter((k) => selected[Number(k)])
        .map(Number);

    /* ---- actions ---- */
    const openObjective = (id: number) => router.visit(`/hr/goals/${id}`);
    const markStatus = (o: Objective, status: string) => {
        router.put(
            `/hr/goals/${o.id}`,
            { status },
            { preserveScroll: true, onSuccess: () => show(status === 'completed' ? 'Objective completed 🎉' : 'Objective archived', status === 'cancelled' ? 'warn' : 'ok') },
        );
    };
    const duplicate = (o: Objective) =>
        router.post(`/hr/goals/${o.id}/duplicate`, { cycle_id: selectedCycleId, with_key_results: true }, { preserveScroll: true, onSuccess: () => show('Objective duplicated') });
    const destroy = (o: Objective) => {
        if (confirm(`Delete “${o.title}”? This cannot be undone.`)) {
            router.delete(`/hr/goals/${o.id}`, { preserveScroll: true, onSuccess: () => show('Objective deleted', 'warn') });
        }
    };
    const bulk = (action: string, extra: Record<string, unknown> = {}) => {
        router.post('/hr/goals/bulk', { action, ids: selectedIds, ...extra }, { preserveScroll: true, onSuccess: () => { setSelected({}); show('Bulk action applied'); } });
    };
    const exportCsv = () => {
        const q = selectedCycleId ? `?cycle=${selectedCycleId}` : '';
        window.location.href = `/hr/goals/export${q}`;
    };

    const objMenu = (o: Objective): CtxItem[] => [
        { label: 'Open', icon: ArrowRight, onClick: () => openObjective(o.id) },
        { label: 'Log check-in', icon: CheckCircle2, onClick: () => setCheckin({ open: true, obj: o }) },
        ...(can.manage
            ? [
                  { label: 'Edit', icon: Pencil, onClick: () => setObjWizard({ open: true, goal: o, parentId: null }) },
                  { label: 'Add child objective', icon: Layers, onClick: () => setObjWizard({ open: true, goal: null, parentId: o.id }) },
                  { label: 'Move under…', icon: Layers, onClick: () => setReparent(o) },
                  { divider: true },
                  { label: 'Duplicate into cycle', icon: Copy, onClick: () => duplicate(o) },
                  { label: o.status === 'on_hold' ? 'Resume (active)' : 'Put on hold', icon: PauseCircle, onClick: () => markStatus(o, o.status === 'on_hold' ? 'active' : 'on_hold') },
                  { label: o.status === 'blocked' ? 'Unblock (active)' : 'Mark blocked', icon: Ban, onClick: () => markStatus(o, o.status === 'blocked' ? 'active' : 'blocked') },
                  { label: 'Mark complete', icon: Check, onClick: () => markStatus(o, 'completed') },
                  { label: 'Archive', icon: Trash2, danger: true, onClick: () => markStatus(o, 'cancelled') },
                  { label: 'Delete', icon: Trash2, danger: true, onClick: () => destroy(o) },
              ]
            : []),
    ];

    const setTabAndStore = (t: HubTab) => setTab(t);
    const tabMenu = (id: HubTab): CtxItem[] => [
        { label: 'Open', icon: ArrowRight, onClick: () => setTabAndStore(id) },
        {
            label: defaultTabPref === id ? 'Default view ✓' : 'Set as default view',
            icon: Star,
            onClick: () => {
                try {
                    localStorage.setItem('okr_default_tab', id);
                } catch {
                    /* ignore */
                }
                setDefaultTabPref(id);
                show('Default view set');
            },
        },
        {
            label: pins[id] ? 'Unpin' : 'Pin tab',
            icon: Pin,
            onClick: () => {
                const next = { ...pins, [id]: !pins[id] };
                if (!next[id]) delete next[id];
                try {
                    localStorage.setItem('okr_pins', JSON.stringify(next));
                } catch {
                    /* ignore */
                }
                setPins(next);
                show(pins[id] ? 'Tab unpinned' : 'Tab pinned');
            },
        },
    ];

    /* ---- hero derived ---- */
    const needsYou = useMemo(() => {
        const active = objectives.filter((o) => o.status === 'active');
        const checkinsDue = active.filter((o) => o.last_checkin_days == null || o.last_checkin_days > 14).length;
        const blocked = developmentPlans.filter((d) => d.status === 'blocked').length;
        return [
            { label: `${analytics.at_risk} at-risk objectives`, onClick: () => { setTab('objectives'); setFConfidence('at_risk'); } },
            { label: `${checkinsDue} check-ins due`, onClick: () => { setTab('objectives'); setSort('checkin'); } },
            { label: `${analytics.overdue} overdue`, onClick: () => { setTab('objectives'); setSort('due'); } },
            { label: `${blocked} plan${blocked === 1 ? '' : 's'} blocked`, onClick: () => setTab('development') },
        ];
    }, [objectives, developmentPlans, analytics]);

    const tabDefs: Array<{ id: HubTab; label: string; icon: typeof Target; count: number | null }> = [
        { id: 'objectives', label: 'Objectives', icon: Target, count: objectives.length },
        { id: 'alignment', label: 'Alignment', icon: Layers, count: objectives.filter((o) => o.goal_type === 'company').length },
        { id: 'development', label: 'Development', icon: Sprout, count: developmentPlans.length },
        { id: 'analytics', label: 'Analytics', icon: BarChart3, count: null },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Goals & OKRs" />

            <PageLayout
                hero={
                    <PageHero
                        category="hr"
                        icon={Target}
                        title="Goals & development"
                        description="Objectives, key results, alignment and growth across the organisation."
                        stats={[
                            { label: 'Active', value: analytics.active },
                            { label: 'On track', value: analytics.on_track },
                            { label: 'At risk', value: analytics.at_risk },
                            { label: 'Avg progress', value: `${analytics.avg_progress}%` },
                        ]}
                        actions={
                            <div className="flex items-center gap-2">
                                <button
                                    type="button"
                                    onClick={exportCsv}
                                    className="inline-flex items-center gap-1.5 rounded-lg border border-white/25 bg-white/10 px-3 py-2 text-[13px] font-semibold text-white hover:bg-white/20"
                                >
                                    <Download className="h-4 w-4" /> Export
                                </button>
                                {can.manage && (
                                    <button
                                        type="button"
                                        onClick={() => setObjWizard({ open: true, goal: null, parentId: null })}
                                        className="inline-flex items-center gap-1.5 rounded-lg bg-white px-3 py-2 text-[13px] font-semibold text-primary hover:bg-white/90"
                                    >
                                        <Plus className="h-4 w-4" /> New objective
                                    </button>
                                )}
                            </div>
                        }
                    />
                }
            >
                <PerformanceTabs active={tab === 'development' ? 'development' : 'goals'} />

                {/* cycle selector + needs-you ribbon */}
                <div className="mb-4 flex flex-wrap items-center gap-3 rounded-xl border border-border bg-card p-3 shadow-sm">
                    <span className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">OKR cycle</span>
                    <div className="inline-flex flex-wrap gap-1 rounded-lg bg-muted p-1">
                        {cycles.map((c) => {
                            const active = selectedCycleId === c.id;
                            return (
                                <button
                                    key={c.id}
                                    type="button"
                                    onClick={() => changeCycle(c.id)}
                                    className={cn(
                                        'rounded-md px-3 py-1.5 text-[12.5px] font-bold transition-colors',
                                        active ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    {c.name}
                                </button>
                            );
                        })}
                        <button
                            type="button"
                            onClick={() => changeCycle('all')}
                            className={cn(
                                'rounded-md px-3 py-1.5 text-[12.5px] font-bold transition-colors',
                                selectedCycleId === null ? 'bg-card text-foreground shadow-sm' : 'text-muted-foreground hover:text-foreground',
                            )}
                        >
                            All
                        </button>
                    </div>
                    <span className="text-[11.5px] text-muted-foreground">
                        {selectedCycle ? `${selectedCycle.meta} · ${objectives.length} objectives` : `All cycles · ${objectives.length} objectives`}
                    </span>
                    <div className="ml-auto flex flex-wrap items-center gap-2">
                        <span className="text-[10px] font-bold uppercase tracking-wider text-muted-foreground">Needs you</span>
                        {needsYou.map((n) => (
                            <button
                                key={n.label}
                                type="button"
                                onClick={n.onClick}
                                className="inline-flex items-center gap-2 rounded-lg border border-border bg-card px-2.5 py-1.5 text-[12px] font-semibold hover:bg-muted/60"
                            >
                                <span className="h-1.5 w-1.5 rounded-full bg-status-warning ring-2 ring-status-warning/30" />
                                {n.label}
                            </button>
                        ))}
                    </div>
                </div>

                {/* tab strip */}
                <div className="mb-4 flex flex-wrap items-center gap-2.5">
                    <div role="tablist" className="inline-flex flex-wrap items-center gap-1 rounded-xl border border-border bg-card p-1.5 shadow-sm">
                        {tabDefs.map((t) => {
                            const active = tab === t.id;
                            const Icon = t.icon;
                            return (
                                <button
                                    key={t.id}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    onClick={() => setTab(t.id)}
                                    onContextMenu={(e) => openCtx(e, `${t.label} tab`, tabMenu(t.id))}
                                    className={cn(
                                        'inline-flex items-center gap-2 rounded-lg px-3 py-2 text-[13px] font-semibold transition-colors',
                                        active ? 'bg-primary/10 text-primary' : 'text-muted-foreground hover:text-foreground',
                                    )}
                                >
                                    <span className={cn('grid h-[22px] w-[22px] place-items-center rounded-md', active ? 'bg-primary text-primary-foreground' : 'bg-muted text-muted-foreground')}>
                                        <Icon className="h-3.5 w-3.5" />
                                    </span>
                                    {t.label}
                                    <span className="inline-flex items-center rounded-full bg-muted/70 px-1.5 py-0.5 text-[10px] font-bold tabular-nums">
                                        {t.count ?? '·'}
                                    </span>
                                    {defaultTabPref === t.id && <Star className="h-3 w-3 fill-status-warning text-status-warning" />}
                                    {pins[t.id] && <Pin className="h-3 w-3 text-muted-foreground" />}
                                </button>
                            );
                        })}
                    </div>
                    <span className="text-[11.5px] text-muted-foreground">Right-click a tab to pin or set default · Right-click any row for actions</span>
                </div>

                {tab === 'objectives' && (
                    <ObjectivesTab
                        list={filtered}
                        total={objectives.length}
                        {...{ search, setSearch, fStatus, setFStatus, fType, setFType, fConfidence, setFConfidence, fOwner, setFOwner, fTag, setFTag, allTags, sort, setSort, view, setView, expanded, setExpanded, selected, setSelected, ownerOptions, can }}
                        selectedIds={selectedIds}
                        onOpen={openObjective}
                        onCheckin={(o) => setCheckin({ open: true, obj: o })}
                        onMenu={(e, o) => openCtx(e, o.title, objMenu(o))}
                        onAddKr={(o) => openObjective(o.id)}
                        onBulk={bulk}
                        onNew={() => setObjWizard({ open: true, goal: null, parentId: null })}
                    />
                )}

                {tab === 'alignment' && (
                    <AlignmentTab
                        objectives={objectives}
                        can={can}
                        onOpen={openObjective}
                        onAddChild={(id) => setObjWizard({ open: true, goal: null, parentId: id })}
                        onMenu={(e, o) =>
                            openCtx(e, o.title, [
                                { label: 'Open', icon: ArrowRight, onClick: () => openObjective(o.id) },
                                ...(can.manage
                                    ? [
                                          { label: 'Add child objective', icon: Layers, onClick: () => setObjWizard({ open: true, goal: null, parentId: o.id }) },
                                          { label: 'Move under…', icon: Layers, onClick: () => setReparent(o) },
                                      ]
                                    : []),
                                { label: 'Log check-in', icon: CheckCircle2, onClick: () => setCheckin({ open: true, obj: o }) },
                            ])
                        }
                        onNew={() => setObjWizard({ open: true, goal: null, parentId: null })}
                    />
                )}

                {tab === 'development' && (
                    <DevelopmentTab
                        plans={developmentPlans}
                        {...{ devSearch, setDevSearch, devStatus, setDevStatus, devCat, setDevCat, can }}
                        onNew={() => setDevWizard(true)}
                    />
                )}

                {tab === 'analytics' && (
                    <AnalyticsTab
                        analytics={analytics}
                        objectives={objectives}
                        selectedCycle={selectedCycle}
                        onDrill={(patch) => {
                            setTab('objectives');
                            if (patch.confidence !== undefined) setFConfidence(patch.confidence);
                            if (patch.status !== undefined) setFStatus(patch.status);
                            if (patch.type !== undefined) setFType(patch.type);
                        }}
                        onOpen={openObjective}
                    />
                )}
            </PageLayout>

            {/* ---- context menu ---- */}
            {ctx.open && (
                <>
                    <div className="fixed inset-0 z-[80]" onClick={closeCtx} onContextMenu={(e) => { e.preventDefault(); closeCtx(); }} />
                    <div
                        role="menu"
                        className="fixed z-[81] min-w-[222px] rounded-xl border border-border bg-card p-1.5 shadow-2xl"
                        style={{ left: ctx.x, top: ctx.y }}
                    >
                        {ctx.title && <div className="truncate px-2.5 pb-1.5 pt-1 text-[11px] font-bold text-muted-foreground">{ctx.title}</div>}
                        {ctx.items.map((it, i) =>
                            it.divider ? (
                                <div key={i} className="my-1 h-px bg-border" />
                            ) : (
                                <button
                                    key={i}
                                    type="button"
                                    onClick={() => {
                                        closeCtx();
                                        it.onClick?.();
                                    }}
                                    className={cn(
                                        'flex w-full items-center gap-2.5 rounded-lg px-2.5 py-2 text-left text-[12.5px] font-semibold hover:bg-muted',
                                        it.danger ? 'text-status-critical' : 'text-foreground',
                                    )}
                                >
                                    {it.icon && <it.icon className={cn('h-[15px] w-[15px]', it.danger ? 'text-status-critical' : 'text-muted-foreground')} />}
                                    {it.label}
                                </button>
                            ),
                        )}
                    </div>
                </>
            )}

            {/* ---- toast ---- */}
            {toast && (
                <div className="fixed bottom-6 left-1/2 z-[90] flex -translate-x-1/2 items-center gap-2.5 rounded-xl border border-border bg-card px-4 py-2.5 shadow-lg">
                    {toast.tone === 'ok' ? <Check className="h-4 w-4 text-status-success" /> : <AlertTriangle className="h-4 w-4 text-status-warning" />}
                    <span className="text-[13px] font-semibold">{toast.msg}</span>
                </div>
            )}

            {/* ---- modals ---- */}
            {can.manage && (
                <ObjectiveWizard
                    open={objWizard.open}
                    onClose={() => setObjWizard({ open: false, goal: null, parentId: null })}
                    owners={users}
                    parentGoals={parentGoals}
                    cycles={cycles}
                    templates={templates}
                    defaultCycleId={selectedCycleId ?? currentCycleId}
                    prefillParentId={objWizard.parentId}
                    goal={objWizard.goal}
                />
            )}
            <CheckinWizard open={checkin.open} onClose={() => setCheckin({ open: false, obj: null })} objective={checkin.obj} />
            {can.manage && (
                <DevelopmentWizard
                    open={devWizard}
                    onClose={() => setDevWizard(false)}
                    staff={users}
                    objectives={objectives.map((o) => ({ id: o.id, title: o.title }))}
                    competencies={competencies}
                />
            )}
            {can.manage && reparent && (
                <ReparentDialog
                    objective={reparent}
                    options={objectives}
                    onClose={() => setReparent(null)}
                    onMove={(parentId) =>
                        router.patch(`/hr/goals/${reparent.id}/parent`, { parent_goal_id: parentId }, { preserveScroll: true, onSuccess: () => { setReparent(null); show('Objective moved'); } })
                    }
                />
            )}
        </AppLayout>
    );
}

/* ================================================================== */
/*  Objectives tab                                                    */
/* ================================================================== */

const SELECT_CLS = 'h-9 w-auto min-w-[120px] text-xs';

function ObjectivesTab(props: {
    list: Objective[];
    total: number;
    search: string;
    setSearch: (v: string) => void;
    fStatus: string;
    setFStatus: (v: string) => void;
    fType: string;
    setFType: (v: string) => void;
    fConfidence: string;
    setFConfidence: (v: string) => void;
    fOwner: string;
    setFOwner: (v: string) => void;
    fTag: string;
    setFTag: (v: string) => void;
    allTags: string[];
    sort: string;
    setSort: (v: string) => void;
    view: 'list' | 'table' | 'board';
    setView: (v: 'list' | 'table' | 'board') => void;
    expanded: Record<number, boolean>;
    setExpanded: React.Dispatch<React.SetStateAction<Record<number, boolean>>>;
    selected: Record<number, boolean>;
    setSelected: React.Dispatch<React.SetStateAction<Record<number, boolean>>>;
    ownerOptions: string[];
    selectedIds: number[];
    can: { manage: boolean };
    onOpen: (id: number) => void;
    onCheckin: (o: Objective) => void;
    onMenu: (e: React.MouseEvent, o: Objective) => void;
    onAddKr: (o: Objective) => void;
    onBulk: (action: string, extra?: Record<string, unknown>) => void;
    onNew: () => void;
}) {
    const { list, view, expanded, setExpanded, selected, setSelected, selectedIds, can } = props;
    const ALL = '__all__';

    const sel = (id: number) => setSelected((p) => ({ ...p, [id]: !p[id] }));
    const visIds = list.map((o) => o.id);
    const allSel = visIds.length > 0 && visIds.every((id) => selected[id]);
    const toggleAll = () => {
        if (allSel) setSelected({});
        else setSelected(Object.fromEntries(visIds.map((id) => [id, true])));
    };

    return (
        <div className="motion-safe:animate-in motion-safe:fade-in-0">
            {/* toolbar */}
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative min-w-[220px] max-w-[340px] flex-1">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        value={props.search}
                        onChange={(e) => props.setSearch(e.target.value)}
                        placeholder="Search objectives, owners, categories…"
                        className="h-9 w-full rounded-lg border border-border bg-card pl-8 pr-3 text-[13px] outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                    />
                </div>
                <FilterSelect value={props.fStatus} onChange={props.setFStatus} placeholder="All statuses" all={ALL} options={[['active', 'Active'], ['draft', 'Draft'], ['on_hold', 'On hold'], ['blocked', 'Blocked'], ['completed', 'Completed'], ['cancelled', 'Cancelled']]} />
                <FilterSelect value={props.fType} onChange={props.setFType} placeholder="All types" all={ALL} options={[['company', 'Company'], ['team', 'Team'], ['individual', 'Individual']]} />
                <FilterSelect value={props.fConfidence} onChange={props.setFConfidence} placeholder="Any confidence" all={ALL} options={[['on_track', 'On track'], ['at_risk', 'At risk'], ['off_track', 'Off track']]} />
                <FilterSelect value={props.fOwner} onChange={props.setFOwner} placeholder="All owners" all={ALL} options={props.ownerOptions.map((o) => [o, o] as [string, string])} />
                {props.allTags.length > 0 && (
                    <FilterSelect value={props.fTag} onChange={props.setFTag} placeholder="All tags" all={ALL} options={props.allTags.map((t) => [t, t] as [string, string])} />
                )}
                <div className="flex-1" />
                <Select value={props.sort} onValueChange={props.setSort}>
                    <SelectTrigger className={SELECT_CLS}>
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="progress">Sort: Progress</SelectItem>
                        <SelectItem value="due">Sort: Due date</SelectItem>
                        <SelectItem value="priority">Sort: Priority</SelectItem>
                        <SelectItem value="owner">Sort: Owner</SelectItem>
                        <SelectItem value="confidence">Sort: Confidence</SelectItem>
                        <SelectItem value="checkin">Sort: Last check-in</SelectItem>
                    </SelectContent>
                </Select>
                <div className="inline-flex gap-1 rounded-lg border border-border bg-card p-1">
                    {([['list', List], ['table', Rows3], ['board', LayoutGrid]] as const).map(([k, Icon]) => (
                        <button
                            key={k}
                            type="button"
                            aria-label={k}
                            title={k === 'table' ? 'Table (dense)' : k}
                            aria-pressed={view === k}
                            onClick={() => props.setView(k)}
                            className={cn('grid h-7 w-8 place-items-center rounded-md', view === k ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted')}
                        >
                            <Icon className="h-4 w-4" />
                        </button>
                    ))}
                </div>
            </div>

            {/* bulk bar */}
            {can.manage && selectedIds.length > 0 && (
                <div className="mt-2.5 flex flex-wrap items-center gap-2 rounded-xl border border-primary/35 bg-primary/[0.07] px-3 py-2.5">
                    <span className="text-[12.5px] font-bold text-primary">{selectedIds.length} selected</span>
                    <span className="h-4 w-px bg-border" />
                    <BulkBtn onClick={() => props.onBulk('recycle')}>Move to next cycle</BulkBtn>
                    <BulkBtn onClick={() => props.onBulk('request_checkin')}>Request check-in</BulkBtn>
                    <BulkBtn onClick={() => props.onBulk('archive')}>Archive</BulkBtn>
                    <div className="flex-1" />
                    <button type="button" onClick={() => setSelected({})} className="text-[12px] font-semibold text-muted-foreground hover:text-foreground">
                        Clear
                    </button>
                </div>
            )}

            {view === 'board' ? (
                <BoardView list={list} onOpen={props.onOpen} onMenu={props.onMenu} />
            ) : (
                <div className="mt-3.5 overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                    <div className="flex items-center gap-3 border-b border-border bg-sidebar px-4 py-2.5 text-[10.5px] font-bold uppercase tracking-wide text-muted-foreground">
                        {can.manage ? (
                            <button type="button" onClick={toggleAll} aria-label="Select all" className={cn('grid h-[18px] w-[18px] place-items-center rounded border-[1.5px]', allSel ? 'border-primary bg-primary text-white' : 'border-border bg-card')}>
                                {allSel && <Check className="h-3 w-3" />}
                            </button>
                        ) : (
                            <span className="w-[18px]" />
                        )}
                        <span className="flex-1">Objective</span>
                        <span className="hidden w-[120px] lg:block">Owner</span>
                        <span className="w-[88px] text-center">Confidence</span>
                        <span className="w-[150px]">Progress</span>
                        <span className="hidden w-[90px] lg:block">Due</span>
                        <span className="w-[26px]" />
                    </div>

                    {list.length === 0 ? (
                        <div className="px-5 py-14 text-center">
                            <div className="mx-auto mb-3 grid h-12 w-12 place-items-center rounded-xl bg-muted text-muted-foreground">
                                <Search className="h-5 w-5" />
                            </div>
                            <p className="text-sm font-bold">No objectives match</p>
                            <p className="mt-1 text-[13px] text-muted-foreground">Try clearing filters or search — or create a new objective.</p>
                        </div>
                    ) : (
                        list.map((o) => (
                            <ObjectiveRow
                                key={o.id}
                                o={o}
                                can={can}
                                dense={view === 'table'}
                                selected={!!selected[o.id]}
                                expanded={view !== 'table' && !!expanded[o.id]}
                                onSelect={() => sel(o.id)}
                                onExpand={() => setExpanded((p) => ({ ...p, [o.id]: !p[o.id] }))}
                                onOpen={() => props.onOpen(o.id)}
                                onCheckin={() => props.onCheckin(o)}
                                onMenu={(e) => props.onMenu(e, o)}
                                onAddKr={() => props.onAddKr(o)}
                            />
                        ))
                    )}
                </div>
            )}
        </div>
    );
}

function FilterSelect({ value, onChange, placeholder, all, options }: { value: string; onChange: (v: string) => void; placeholder: string; all: string; options: [string, string][] }) {
    return (
        <Select value={value || all} onValueChange={(v) => onChange(v === all ? '' : v)}>
            <SelectTrigger className={SELECT_CLS}>
                <SelectValue placeholder={placeholder} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={all}>{placeholder}</SelectItem>
                {options.map(([v, l]) => (
                    <SelectItem key={v} value={v}>
                        {l}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function BulkBtn({ onClick, children }: { onClick: () => void; children: React.ReactNode }) {
    return (
        <button type="button" onClick={onClick} className="rounded-lg border border-border bg-card px-2.5 py-1.5 text-[12px] font-semibold hover:bg-muted">
            {children}
        </button>
    );
}

function ObjectiveRow({
    o,
    can,
    selected,
    expanded,
    onSelect,
    onExpand,
    onOpen,
    onCheckin,
    onMenu,
    onAddKr,
    dense = false,
}: {
    o: Objective;
    can: { manage: boolean };
    dense?: boolean;
    selected: boolean;
    expanded: boolean;
    onSelect: () => void;
    onExpand: () => void;
    onOpen: () => void;
    onCheckin: () => void;
    onMenu: (e: React.MouseEvent) => void;
    onAddKr: () => void;
}) {
    return (
        <div className="border-b border-border/60 last:border-0">
            <div
                onContextMenu={onMenu}
                className={cn('flex items-center gap-3 px-4 transition-colors hover:bg-muted/40', dense ? 'py-1.5' : 'py-2.5', selected && 'bg-primary/[0.06]')}
            >
                {can.manage ? (
                    <button type="button" onClick={onSelect} aria-label="Select objective" className={cn('grid h-[18px] w-[18px] shrink-0 place-items-center rounded border-[1.5px]', selected ? 'border-primary bg-primary text-white' : 'border-border bg-card')}>
                        {selected && <Check className="h-3 w-3" />}
                    </button>
                ) : (
                    <span className="w-[18px]" />
                )}
                {dense ? (
                    <span className="w-[18px] shrink-0" />
                ) : (
                    <button type="button" onClick={onExpand} aria-label="Expand key results" className="grid h-[18px] w-[18px] shrink-0 place-items-center text-muted-foreground">
                        {expanded ? <ChevronDown className="h-4 w-4" /> : <ChevronRight className="h-4 w-4" />}
                    </button>
                )}
                <div className="min-w-0 flex-1 cursor-pointer" onClick={onOpen}>
                    <div className="flex items-center gap-2">
                        <span className={cn('truncate font-semibold', dense ? 'text-[12.5px]' : 'text-[13.5px]')}>{o.title}</span>
                        <span className={cn('h-2 w-2 shrink-0 rounded-full', PRIORITY_DOT[o.priority])} title={`${o.priority} priority`} />
                        {dense && o.status !== 'active' && (
                            <span className={cn('inline-flex items-center rounded px-1 py-0.5 text-[9px] font-bold capitalize', STATUS_BADGE[o.status])}>{STATUS_LABEL[o.status]}</span>
                        )}
                    </div>
                    {!dense && (
                        <div className="mt-1 flex flex-wrap items-center gap-1.5">
                            <TypeBadge type={o.goal_type} />
                            {o.cycle && <span className="text-[11px] text-muted-foreground">{o.cycle.name}</span>}
                            {o.parent_goal && <span className="max-w-[200px] truncate text-[11px] text-muted-foreground">↳ {o.parent_goal.title}</span>}
                            {o.key_results_count > 0 && <span className="text-[11px] text-muted-foreground">· {o.key_results_count} KR</span>}
                            <span className="text-[11px] text-muted-foreground">· {checkinLabel(o.last_checkin_days)}</span>
                            {(o.tags ?? []).map((t) => (
                                <span key={t} className="inline-flex items-center gap-0.5 rounded bg-muted px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">
                                    <TagIcon className="h-2.5 w-2.5" />
                                    {t}
                                </span>
                            ))}
                        </div>
                    )}
                </div>
                <div className="hidden w-[120px] items-center gap-1.5 lg:flex">
                    <Avatar name={o.user?.name} />
                    <span className="truncate text-xs">{o.user?.name ?? '—'}</span>
                </div>
                <div className="flex w-[88px] justify-center">
                    <RagPill confidence={o.confidence} />
                </div>
                <div className="flex w-[150px] items-center gap-2">
                    <ProgressBar pct={o.progress_percentage} className="h-1.5" />
                    <span className="w-8 text-right text-xs font-bold tabular-nums">{o.progress_percentage}%</span>
                </div>
                <div className="hidden w-[90px] text-xs text-muted-foreground lg:block">{formatDate(o.due_date)}</div>
                <button type="button" onClick={onMenu} aria-label="Row actions" className="grid h-7 w-7 shrink-0 place-items-center rounded-md text-muted-foreground hover:bg-muted">
                    <MoreVertical className="h-4 w-4" />
                </button>
            </div>

            {expanded && (
                <div className="border-t border-border/60 bg-muted/30 py-1.5 pl-14 pr-4">
                    {o.key_results.length === 0 ? (
                        <p className="py-2 text-[12px] text-muted-foreground">No key results — progress is manual.</p>
                    ) : (
                        o.key_results.map((k) => (
                            <div key={k.id} className="flex items-center gap-3 border-b border-border/50 py-2 last:border-0">
                                <div className="min-w-0 flex-1">
                                    <div className="text-[12.5px] font-semibold">{k.title}</div>
                                    <div className="mt-0.5 text-[11px] text-muted-foreground">
                                        {formatKrMeasure(k)} · weight {k.weight} · {k.owner?.name ?? '—'}
                                    </div>
                                </div>
                                <RagPill confidence={k.confidence} />
                                <div className="flex w-[130px] items-center gap-2">
                                    <ProgressBar pct={k.progress_percentage} className="h-1.5" />
                                    <span className="w-7 text-right text-[11.5px] font-bold tabular-nums">{k.progress_percentage}%</span>
                                </div>
                                <button type="button" onClick={onCheckin} className="rounded-md border border-border bg-card px-2 py-1 text-[11px] font-semibold hover:bg-muted">
                                    Check in
                                </button>
                            </div>
                        ))
                    )}
                    <div className="mt-2 flex gap-2">
                        <button type="button" onClick={onAddKr} className="rounded-lg border border-dashed border-border px-2.5 py-1.5 text-[11.5px] font-semibold text-primary hover:bg-muted/40">
                            + Add key result
                        </button>
                        <button type="button" onClick={onOpen} className="rounded-lg px-2 py-1.5 text-[11.5px] font-semibold text-muted-foreground hover:bg-muted/40">
                            Open objective →
                        </button>
                    </div>
                </div>
            )}
        </div>
    );
}

function BoardView({ list, onOpen, onMenu }: { list: Objective[]; onOpen: (id: number) => void; onMenu: (e: React.MouseEvent, o: Objective) => void }) {
    const cols: { key: Confidence; label: string }[] = [
        { key: 'on_track', label: 'On track' },
        { key: 'at_risk', label: 'At risk' },
        { key: 'off_track', label: 'Off track' },
    ];
    return (
        <div className="mt-3.5 grid grid-cols-1 items-start gap-3.5 md:grid-cols-3">
            {cols.map((c) => {
                const items = list.filter((o) => o.confidence === c.key);
                return (
                    <div key={c.key} className="overflow-hidden rounded-xl border border-border bg-sidebar">
                        <div className="flex items-center justify-between border-b border-border px-3 py-2.5">
                            <span className="inline-flex items-center gap-2 text-[12.5px] font-bold">
                                <span className={cn('h-2 w-2 rounded-full', RAG[c.key].dot)} /> {c.label}
                            </span>
                            <span className="text-[11px] font-bold tabular-nums text-muted-foreground">{items.length}</span>
                        </div>
                        <div className="flex flex-col gap-2.5 p-2.5">
                            {items.map((o) => (
                                <div
                                    key={o.id}
                                    onClick={() => onOpen(o.id)}
                                    onContextMenu={(e) => onMenu(e, o)}
                                    className="cursor-pointer rounded-xl border border-border bg-card p-3 shadow-sm transition-shadow hover:shadow-md"
                                >
                                    <div className="mb-1.5 flex items-center gap-2">
                                        <TypeBadge type={o.goal_type} />
                                        <span className={cn('h-1.5 w-1.5 rounded-full', RAG[o.confidence].dot)} />
                                    </div>
                                    <div className="mb-2 text-[13px] font-semibold leading-snug">{o.title}</div>
                                    <div className="flex items-center gap-2">
                                        <ProgressBar pct={o.progress_percentage} className="h-1.5" />
                                        <span className="text-[11.5px] font-bold tabular-nums">{o.progress_percentage}%</span>
                                    </div>
                                    <div className="mt-2 flex items-center justify-between text-[11px] text-muted-foreground">
                                        <span className="inline-flex items-center gap-1.5">
                                            <Avatar name={o.user?.name} className="h-4 w-4 text-[8px]" />
                                            {o.user?.name ?? '—'}
                                        </span>
                                        <span>{formatDate(o.due_date)}</span>
                                    </div>
                                </div>
                            ))}
                            {items.length === 0 && <p className="px-1 py-4 text-center text-[11.5px] text-muted-foreground">Nothing here</p>}
                        </div>
                    </div>
                );
            })}
        </div>
    );
}

/* ================================================================== */
/*  Alignment tab                                                     */
/* ================================================================== */

function AlignmentTab({
    objectives,
    can,
    onOpen,
    onAddChild,
    onMenu,
    onNew,
}: {
    objectives: Objective[];
    can: { manage: boolean };
    onOpen: (id: number) => void;
    onAddChild: (id: number) => void;
    onMenu: (e: React.MouseEvent, o: Objective) => void;
    onNew: () => void;
}) {
    // Flattened DFS over the in-cycle objectives.
    const flat = useMemo(() => {
        const byParent = new Map<number | null, Objective[]>();
        objectives.forEach((o) => {
            const key = o.parent_goal_id;
            if (!byParent.has(key)) byParent.set(key, []);
            byParent.get(key)!.push(o);
        });
        const out: { o: Objective; depth: number }[] = [];
        const ids = new Set(objectives.map((o) => o.id));
        const walk = (o: Objective, depth: number) => {
            out.push({ o, depth });
            (byParent.get(o.id) ?? []).forEach((c) => walk(c, depth + 1));
        };
        // Roots: top-level OR parent not in current cycle list.
        objectives.filter((o) => o.parent_goal_id == null || !ids.has(o.parent_goal_id)).forEach((o) => walk(o, 0));
        return out;
    }, [objectives]);

    return (
        <div className="motion-safe:animate-in motion-safe:fade-in-0">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2.5">
                <div>
                    <p className="text-[15px] font-bold">Alignment tree</p>
                    <p className="mt-0.5 text-[12.5px] text-muted-foreground">Company → team → individual, with weighted roll-up at each node. Right-click a node to add a child or re-parent.</p>
                </div>
                {can.manage && (
                    <button type="button" onClick={onNew} className="inline-flex items-center gap-1.5 rounded-lg border border-border bg-card px-3 py-2 text-[12.5px] font-semibold shadow-sm hover:bg-muted">
                        <Plus className="h-4 w-4 text-primary" /> New objective
                    </button>
                )}
            </div>
            <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                {flat.length === 0 ? (
                    <p className="px-5 py-12 text-center text-[13px] text-muted-foreground">No objectives in this cycle.</p>
                ) : (
                    flat.map(({ o, depth }) => (
                        <div
                            key={o.id}
                            onContextMenu={(e) => onMenu(e, o)}
                            className="flex items-center gap-3 border-b border-border/60 px-4 py-3 transition-colors last:border-0 hover:bg-muted/40"
                        >
                            <div className="flex min-w-0 flex-1 items-center" style={{ paddingLeft: depth * 28 }}>
                                {depth > 0 && <span className="-ml-5 mr-2 h-px w-4 shrink-0 bg-border" />}
                                <div className="min-w-0 cursor-pointer" onClick={() => onOpen(o.id)}>
                                    <div className="flex items-center gap-2">
                                        <span className={cn('h-2 w-2 shrink-0 rounded-full', RAG[o.confidence].dot)} title={RAG[o.confidence].label} />
                                        <span className="truncate text-[13.5px] font-semibold">{o.title}</span>
                                        <TypeBadge type={o.goal_type} />
                                    </div>
                                    <div className="mt-0.5 flex items-center gap-1.5 text-[11px] text-muted-foreground">
                                        <Avatar name={o.user?.name} className="h-4 w-4 text-[8px]" />
                                        {o.user?.name ?? '—'} · {o.key_results_count} KR
                                    </div>
                                </div>
                            </div>
                            <div className="flex w-[170px] items-center gap-2">
                                <ProgressBar pct={o.progress_percentage} className="h-1.5" />
                                <span className="w-8 text-right text-xs font-bold tabular-nums">{o.progress_percentage}%</span>
                            </div>
                            {can.manage && (
                                <button type="button" onClick={() => onAddChild(o.id)} aria-label="Add child objective" className="grid h-7 w-7 shrink-0 place-items-center rounded-lg border border-dashed border-border text-primary hover:bg-muted">
                                    <Plus className="h-3.5 w-3.5" />
                                </button>
                            )}
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}

/* ================================================================== */
/*  Development tab                                                   */
/* ================================================================== */

function DevelopmentTab({
    plans,
    devSearch,
    setDevSearch,
    devStatus,
    setDevStatus,
    devCat,
    setDevCat,
    can,
    onNew,
}: {
    plans: DevelopmentPlan[];
    devSearch: string;
    setDevSearch: (v: string) => void;
    devStatus: string;
    setDevStatus: (v: string) => void;
    devCat: string;
    setDevCat: (v: string) => void;
    can: { manage: boolean };
    onNew: () => void;
}) {
    const ALL = '__all__';
    const filtered = plans.filter((d) => {
        if (devStatus && d.status !== devStatus) return false;
        if (devCat && d.category !== devCat) return false;
        if (devSearch) {
            const hay = `${d.employee?.name ?? ''} ${d.manager?.name ?? ''} ${d.competency_area ?? ''}`.toLowerCase();
            if (!hay.includes(devSearch.toLowerCase())) return false;
        }
        return true;
    });

    return (
        <div className="motion-safe:animate-in motion-safe:fade-in-0">
            <div className="mb-3 flex flex-wrap items-center gap-2">
                <div className="relative min-w-[220px] max-w-[320px] flex-1">
                    <Search className="pointer-events-none absolute left-3 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                    <input
                        value={devSearch}
                        onChange={(e) => setDevSearch(e.target.value)}
                        placeholder="Search people, managers, competencies…"
                        className="h-9 w-full rounded-lg border border-border bg-card pl-8 pr-3 text-[13px] outline-none focus:border-primary focus:ring-2 focus:ring-primary/20"
                    />
                </div>
                <FilterSelect value={devStatus} onChange={setDevStatus} placeholder="Any status" all={ALL} options={[['not_started', 'Not started'], ['in_progress', 'In progress'], ['blocked', 'Blocked'], ['completed', 'Completed']]} />
                <FilterSelect value={devCat} onChange={setDevCat} placeholder="All categories" all={ALL} options={[['growth', 'Growth'], ['performance', 'Performance'], ['leadership', 'Leadership'], ['compliance', 'Compliance'], ['capability', 'Capability']]} />
                <div className="flex-1" />
                {can.manage && (
                    <button type="button" onClick={onNew} className="inline-flex items-center gap-1.5 rounded-lg bg-primary px-3.5 py-2 text-[12.5px] font-semibold text-primary-foreground shadow-sm">
                        <Plus className="h-4 w-4" /> New development plan
                    </button>
                )}
            </div>
            <div className="overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <div className="flex items-center gap-3 border-b border-border bg-sidebar px-4 py-2.5 text-[10.5px] font-bold uppercase tracking-wide text-muted-foreground">
                    <span className="flex-1">Person &amp; competency</span>
                    <span className="hidden w-[150px] lg:block">Level</span>
                    <span className="w-[120px]">Status</span>
                    <span className="hidden w-[150px] lg:block">Linked objective</span>
                    <span className="w-[80px]">Review</span>
                </div>
                {filtered.length === 0 ? (
                    <p className="px-5 py-12 text-center text-[13px] text-muted-foreground">No development plans match.</p>
                ) : (
                    filtered.map((d) => (
                        <div key={d.id} className="flex items-center gap-3 border-b border-border/60 px-4 py-3 transition-colors last:border-0 hover:bg-muted/40">
                            <div className="flex min-w-0 flex-1 items-center gap-2.5">
                                <Avatar name={d.employee?.name} className="h-[34px] w-[34px] text-[12px]" />
                                <div className="min-w-0">
                                    <div className="text-[13.5px] font-semibold">{d.employee?.name ?? '—'}</div>
                                    <div className="mt-0.5 flex flex-wrap items-center gap-1.5">
                                        <span className="text-[12px] text-muted-foreground">{d.competency_area ?? '—'}</span>
                                        <span className={cn('inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold', DEV_CAT_BADGE[d.category])}>{d.category}</span>
                                    </div>
                                </div>
                            </div>
                            <div className="hidden w-[150px] items-center gap-1 lg:flex">
                                {[1, 2, 3, 4, 5].map((n) => (
                                    <span key={n} className={cn('h-2 w-3.5 rounded-sm', d.current_level && n <= d.current_level ? 'bg-primary' : d.target_level && n <= d.target_level ? 'bg-primary/25' : 'bg-muted')} />
                                ))}
                                <span className="ml-1.5 text-[11px] font-bold tabular-nums text-muted-foreground">
                                    {d.current_level ?? 0}→{d.target_level ?? 0}
                                </span>
                            </div>
                            <div className="w-[120px]">
                                <span className={cn('inline-flex items-center rounded-md px-1.5 py-0.5 text-[10px] font-bold', DEV_STATUS_BADGE[d.status])}>{d.status.replace('_', ' ')}</span>
                            </div>
                            <div className="hidden w-[150px] truncate text-[11.5px] text-muted-foreground lg:block">{d.linked_objective ? `↳ ${d.linked_objective.title}` : ''}</div>
                            <div className="w-[80px] text-[11.5px] capitalize text-muted-foreground">{d.review_frequency ?? '—'}</div>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}

/* ================================================================== */
/*  Analytics tab                                                     */
/* ================================================================== */

function AnalyticsTab({
    analytics,
    objectives,
    selectedCycle,
    onDrill,
    onOpen,
}: {
    analytics: Analytics;
    objectives: Objective[];
    selectedCycle: Cycle | undefined;
    onDrill: (patch: { confidence?: string; status?: string; type?: string }) => void;
    onOpen: (id: number) => void;
}) {
    const kpis = [
        { label: 'Active objectives', value: analytics.active, tone: 'text-primary', bg: 'bg-primary/10', icon: Target, drill: { status: 'active' } },
        { label: 'On track', value: analytics.on_track, tone: 'text-status-success', bg: 'bg-status-success-bg', icon: CheckCircle2, drill: { confidence: 'on_track' } },
        { label: 'At risk', value: analytics.at_risk, tone: 'text-status-warning', bg: 'bg-status-warning-bg', icon: AlertTriangle, drill: { confidence: 'at_risk' } },
        { label: 'Completion', value: `${analytics.completion_rate}%`, tone: 'text-status-info', bg: 'bg-status-info-bg', icon: BarChart3, drill: { status: 'completed' } },
    ];

    const byType = analytics.progress_by_type.map((b) => ({
        label: TYPE_LABEL[b.type as GoalType] ?? b.type,
        value: b.avg_progress,
        count: b.count,
    }));

    const conf = [
        { key: 'on_track', label: 'On track', value: analytics.on_track, color: 'bg-status-success' },
        { key: 'at_risk', label: 'At risk', value: analytics.at_risk, color: 'bg-status-warning' },
        { key: 'off_track', label: 'Off track', value: analytics.off_track, color: 'bg-status-critical' },
    ];
    const confTotal = Math.max(1, conf.reduce((a, c) => a + c.value, 0));

    const atRisk = objectives
        .filter((o) => o.status === 'active' && o.confidence !== 'on_track')
        .sort((a, b) => a.progress_percentage - b.progress_percentage)
        .slice(0, 6);

    return (
        <div className="motion-safe:animate-in motion-safe:fade-in-0">
            <p className="mb-3 text-[12.5px] text-muted-foreground">
                Every number deep-links into a filtered Objectives view. Showing <strong className="text-foreground">{selectedCycle ? selectedCycle.name : 'all cycles'}</strong>.
            </p>
            <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
                {kpis.map((k) => (
                    <button key={k.label} type="button" onClick={() => onDrill(k.drill)} className="rounded-xl border border-border bg-card p-4 text-left shadow-sm transition-shadow hover:shadow-md">
                        <span className={cn('inline-grid h-9 w-9 place-items-center rounded-lg', k.bg, k.tone)}>
                            <k.icon className="h-4 w-4" />
                        </span>
                        <div className="mt-2.5 text-[28px] font-extrabold leading-none tabular-nums">{k.value}</div>
                        <div className="mt-1 text-[12px] text-muted-foreground">
                            {k.label} <span className="text-primary">›</span>
                        </div>
                    </button>
                ))}
            </div>

            <div className="mt-3.5 grid gap-3.5 lg:grid-cols-2">
                <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
                    <p className="mb-3.5 text-[13.5px] font-bold">Average progress by type</p>
                    {byType.length === 0 ? (
                        <p className="py-6 text-center text-[13px] text-muted-foreground">No active objectives.</p>
                    ) : (
                        byType.map((b) => (
                            <div key={b.label} className="mb-3 last:mb-0">
                                <div className="mb-1.5 flex justify-between text-[12px]">
                                    <span className="font-semibold">
                                        {b.label} <span className="font-normal text-muted-foreground">· {b.count}</span>
                                    </span>
                                    <span className="font-bold tabular-nums">{b.value}%</span>
                                </div>
                                <div className="h-2.5 overflow-hidden rounded-full bg-muted">
                                    <div className={cn('h-full rounded-full', barColor(b.value))} style={{ width: `${b.value}%` }} />
                                </div>
                            </div>
                        ))
                    )}
                </div>

                <div className="rounded-xl border border-border bg-card p-4 shadow-sm">
                    <p className="mb-3.5 text-[13.5px] font-bold">Confidence distribution</p>
                    <div className="mb-3.5 flex h-4 overflow-hidden rounded-full">
                        {conf.map((c) => (
                            <div key={c.key} className={c.color} style={{ flex: `${c.value} 0 0` }} title={c.label} />
                        ))}
                    </div>
                    <div className="flex flex-col gap-2.5">
                        {conf.map((c) => (
                            <button key={c.key} type="button" onClick={() => onDrill({ confidence: c.key })} className="flex items-center gap-2.5 text-left">
                                <span className={cn('h-2.5 w-2.5 rounded-full', c.color)} />
                                <span className="flex-1 text-[12.5px]">{c.label}</span>
                                <span className="text-[13px] font-bold tabular-nums">{c.value}</span>
                                <span className="text-[11px] text-muted-foreground">{Math.round((c.value / confTotal) * 100)}%</span>
                            </button>
                        ))}
                    </div>
                </div>
            </div>

            <div className="mt-3.5 overflow-hidden rounded-xl border border-border bg-card shadow-sm">
                <div className="flex items-center gap-2 border-b border-border px-4 py-3.5">
                    <AlertTriangle className="h-4 w-4 text-status-warning" />
                    <p className="text-[13.5px] font-bold">At-risk &amp; off-track queue</p>
                </div>
                {atRisk.length === 0 ? (
                    <p className="px-5 py-10 text-center text-[13px] text-muted-foreground">Nothing at risk — everything's on track. 🎉</p>
                ) : (
                    atRisk.map((o) => (
                        <div key={o.id} onClick={() => onOpen(o.id)} className="flex cursor-pointer items-center gap-3 border-b border-border/60 px-4 py-2.5 last:border-0 hover:bg-muted/40">
                            <span className={cn('h-2 w-2 rounded-full', RAG[o.confidence].dot)} />
                            <span className="flex-1 truncate text-[13px] font-semibold">{o.title}</span>
                            <span className="hidden items-center gap-1.5 text-[11.5px] text-muted-foreground lg:flex">
                                <Avatar name={o.user?.name} className="h-4 w-4 text-[8px]" />
                                {o.user?.name ?? '—'}
                            </span>
                            <div className="flex w-[120px] items-center gap-2">
                                <ProgressBar pct={o.progress_percentage} className="h-1.5" />
                                <span className="w-7 text-right text-[11.5px] font-bold tabular-nums">{o.progress_percentage}%</span>
                            </div>
                            <span className="w-[60px] text-right text-[11.5px] text-muted-foreground">{formatDate(o.due_date)}</span>
                        </div>
                    ))
                )}
            </div>
        </div>
    );
}

/* ================================================================== */
/*  Re-parent dialog                                                  */
/* ================================================================== */

function ReparentDialog({ objective, options, onClose, onMove }: { objective: Objective; options: Objective[]; onClose: () => void; onMove: (parentId: number | null) => void }) {
    const [parentId, setParentId] = useState<string>(objective.parent_goal_id ? String(objective.parent_goal_id) : '__none__');
    const valid = options.filter((o) => o.id !== objective.id);

    return (
        <div className="fixed inset-0 z-[100] grid place-items-center bg-black/40 p-6" onClick={onClose}>
            <div className="w-full max-w-md rounded-2xl border border-border bg-card p-5 shadow-2xl" onClick={(e) => e.stopPropagation()}>
                <h3 className="text-base font-bold">Move objective</h3>
                <p className="mt-1 text-[13px] text-muted-foreground">Re-parent “{objective.title}”. Roll-ups recompute on both branches.</p>
                <div className="mt-4">
                    <Select value={parentId} onValueChange={setParentId}>
                        <SelectTrigger>
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="__none__">Top-level (no parent)</SelectItem>
                            {valid.map((o) => (
                                <SelectItem key={o.id} value={String(o.id)}>
                                    {initials(o.user?.name)} · {o.title}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="mt-5 flex justify-end gap-2">
                    <button type="button" onClick={onClose} className="rounded-lg px-3 py-2 text-sm font-semibold text-muted-foreground hover:bg-muted">
                        Cancel
                    </button>
                    <button
                        type="button"
                        onClick={() => onMove(parentId === '__none__' ? null : Number(parentId))}
                        className="rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-primary-foreground"
                    >
                        Move
                    </button>
                </div>
            </div>
        </div>
    );
}
