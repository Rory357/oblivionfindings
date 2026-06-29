import {
    TrainingWizardDialog,
    type WizardCourse,
    type WizardLookups,
    type WizardType,
} from '@/components/hr/training/training-wizard-dialog';
import AppLayout from '@/layouts/app-layout';
import { type BreadcrumbItem } from '@/types';
import { Head, router, usePage } from '@inertiajs/react';
import {
    BookOpen,
    Calendar,
    CheckSquare,
    ChevronRight,
    Download,
    LayoutDashboard,
    MoreVertical,
    Plus,
    Search,
    UserPlus,
} from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';

/* ---------------------------------------------------------------- types -- */
interface Summary {
    total_courses: number;
    mandatory_courses: number;
    total_enrollments: number;
    completed_enrollments: number;
    upcoming_sessions: number;
    overdue_assignments: number;
    expiring_soon: number;
    completion_rate: number;
}
interface DashboardData {
    mandatoryCurrentPct: number;
    overdueCount: number;
    expiringCount: number;
    spendYtd: number;
    renewals: { course: string; site: string; overdue: number; due_soon: number }[];
    completionBySite: { site: string; completion: number }[];
    upcomingSessions: { id: number; course: string; date: string | null; seats: number | null }[];
}
interface Course {
    id: number;
    title: string;
    code: string;
    category: string | null;
    delivery_method: string;
    duration_hours: number;
    provider: string | null;
    cost: number | null;
    is_mandatory: boolean;
    is_active: boolean;
    requires_renewal: boolean;
    validity_period_months: number | null;
    cpd_points: number | null;
    sessions_count: number;
    enrol: number;
    completion: number;
    expiring: number;
}
interface Assignment {
    id: number;
    person: string;
    course: string;
    source: string;
    due: string | null;
    status: string;
    score: number | null;
}
interface Props {
    summary: Summary;
    dashboard: DashboardData;
    courses: Course[];
    assignments: Assignment[];
    categories: string[];
    deliveryMethods: { value: string; label: string }[];
    lookups: WizardLookups;
    filters: { search: string; sort: string };
    can: { manage: boolean; enroll: boolean; record: boolean; claim: boolean };
}

type Tab = 'dashboard' | 'catalog' | 'assignments';

const breadcrumbs: BreadcrumbItem[] = [
    { title: 'HR', href: '/hr' },
    { title: 'Training', href: '/hr/training/catalog' },
];

const NZD = new Intl.NumberFormat('en-NZ', { style: 'currency', currency: 'NZD', maximumFractionDigits: 0 });
const fmtNzd = (n: number | null) => (n && n > 0 ? NZD.format(n) : 'Free');

const DELIVERY_LABELS: Record<string, string> = {
    online: 'Online',
    in_person: 'In person',
    blended: 'Blended',
    self_paced: 'Self-paced',
};

const STATUS_BADGE: Record<string, string> = {
    completed: 'bg-status-success-bg text-status-success border-status-success/30',
    in_progress: 'bg-status-info-bg text-status-info border-status-info/30',
    assigned: 'bg-muted text-muted-foreground border-border',
    overdue: 'bg-status-critical-bg text-status-critical border-status-critical/30',
    waived: 'bg-status-warning-bg text-status-warning border-status-warning/30',
};
const STATUS_LABEL: Record<string, string> = {
    completed: 'Completed',
    in_progress: 'In progress',
    assigned: 'Assigned',
    overdue: 'Overdue',
    waived: 'Waived',
};
const SOURCE_LABEL: Record<string, string> = {
    manual: 'Manual',
    role_rule: 'Role rule',
    hs_requirement: 'H&S requirement',
};

const today = new Date().toLocaleDateString('en-NZ', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' });

function completionTone(pct: number) {
    return pct >= 88 ? 'success' : pct >= 75 ? 'warning' : 'critical';
}
function fmtDate(v: string | null) {
    if (!v) return '—';
    const d = new Date(v);
    return isNaN(d.getTime()) ? v : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
}

/* ----------------------------------------------------------- ctx menu --- */
interface CtxItem {
    label: string;
    kbd?: string;
    tone?: 'danger' | 'muted';
    onClick: () => void;
}
interface CtxState {
    x: number;
    y: number;
    items: CtxItem[];
}

/* ----------------------------------------------------------- sheet ------ */
interface SheetData {
    id: number;
    title: string;
    code: string;
    provider: string | null;
    delivery_method: string;
    duration_hours: number;
    cost: number | null;
    is_mandatory: boolean;
    requires_renewal: boolean;
    validity_period_months: number | null;
    metrics: { enrol: number; completion: number; expiring: number };
    sessions: { id: number; session_date: string | null; start_time: string | null; end_time: string | null; location: string | null; trainer: string | null; status: string; seats: number | null }[];
    enrollments: { id: number; name: string; status: string; score: number | null }[];
}

export default function TrainingHub({ summary, dashboard, courses, assignments, lookups, filters, can }: Props) {
    const { props } = usePage<{ flash?: { success?: string; error?: string } }>();

    const [tab, setTab] = useState<Tab>('dashboard');
    const [defaultTab, setDefaultTab] = useState<Tab>('dashboard');
    const [pinned, setPinned] = useState<Tab[]>([]);
    const [view, setView] = useState<'cards' | 'table'>('cards');
    const [search, setSearch] = useState(filters.search ?? '');
    const [sort, setSort] = useState(filters.sort ?? 'title');
    const [selected, setSelected] = useState<number[]>([]);
    const [asgStatus, setAsgStatus] = useState<string>('all');
    const [ctx, setCtx] = useState<CtxState | null>(null);
    const [wizard, setWizard] = useState<{ type: WizardType; course: WizardCourse | null } | null>(null);
    const [sheetId, setSheetId] = useState<number | null>(null);
    const [sheet, setSheet] = useState<SheetData | null>(null);
    const searchRef = useRef<HTMLInputElement>(null);

    /* localStorage tab prefs + deep-link */
    useEffect(() => {
        try {
            const d = (localStorage.getItem('th_default') as Tab) || 'dashboard';
            setDefaultTab(d);
            setTab(d);
            setPinned(JSON.parse(localStorage.getItem('th_pinned') || '[]'));
        } catch {
            /* ignore */
        }
        const url = new URL(window.location.href);
        const courseParam = url.searchParams.get('course');
        if (courseParam) {
            setTab('catalog');
            setSheetId(Number(courseParam));
        }
        if (url.searchParams.get('open') === 'create' && can.manage) {
            setTab('catalog');
            setWizard({ type: 'createCourse', course: null });
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    /* flash → toast */
    useEffect(() => {
        if (props.flash?.success) toast.success(props.flash.success);
        else if (props.flash?.error) toast.error(props.flash.error);
    }, [props.flash]);

    /* keyboard shortcuts + global click closes ctx */
    useEffect(() => {
        const onClick = () => setCtx(null);
        const onKey = (e: KeyboardEvent) => {
            const t = (document.activeElement?.tagName ?? '') as string;
            if (e.key === 'Escape') {
                if (wizard) setWizard(null);
                else if (ctx) setCtx(null);
                else if (sheetId) setSheetId(null);
            } else if (e.key === '/' && !wizard && t !== 'INPUT' && t !== 'TEXTAREA') {
                e.preventDefault();
                searchRef.current?.focus();
            } else if ((e.key === 'n' || e.key === 'N') && !wizard && t !== 'INPUT' && t !== 'TEXTAREA' && can.manage) {
                openWizard('createCourse');
            }
        };
        document.addEventListener('click', onClick);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('click', onClick);
            document.removeEventListener('keydown', onKey);
        };
    });

    /* fetch course detail for the sheet */
    useEffect(() => {
        if (!sheetId) {
            setSheet(null);
            return;
        }
        setSheet(null);
        fetch(`/hr/training/courses/${sheetId}/detail`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => d && setSheet(d))
            .catch(() => undefined);
    }, [sheetId]);

    const openWizard = (type: WizardType, course: WizardCourse | null = null) => {
        setCtx(null);
        setWizard({ type, course });
    };
    const onSaved = () => router.reload();

    const openCtx = useCallback((e: React.MouseEvent, items: CtxItem[]) => {
        e.preventDefault();
        e.stopPropagation();
        const w = 234;
        const h = items.length * 37 + 12;
        let x = e.clientX;
        let y = e.clientY;
        if (x + w > window.innerWidth) x = window.innerWidth - w - 8;
        if (y + h > window.innerHeight) y = window.innerHeight - h - 8;
        setCtx({ x, y, items });
    }, []);

    const setAsDefault = (id: Tab) => {
        try {
            localStorage.setItem('th_default', id);
        } catch {
            /* ignore */
        }
        setDefaultTab(id);
        toast.success('Default view set');
    };
    const togglePin = (id: Tab) => {
        const next = pinned.includes(id) ? pinned.filter((p) => p !== id) : [...pinned, id];
        try {
            localStorage.setItem('th_pinned', JSON.stringify(next));
        } catch {
            /* ignore */
        }
        setPinned(next);
        toast.success(pinned.includes(id) ? 'Tab unpinned' : 'Tab pinned');
    };

    /* mutations */
    const toggleArchive = (c: Course) => {
        router.patch(`/hr/training/courses/${c.id}/toggle`, {}, { preserveScroll: true, onSuccess: () => router.reload() });
    };
    const cancelSession = (sessionId: number) => {
        const reason = window.prompt('Reason for cancelling this session?') ?? undefined;
        router.delete(`/hr/training/sessions/${sessionId}`, { data: { reason }, preserveScroll: true, onSuccess: () => { router.reload(); if (sheetId) refetchSheet(); } });
    };
    const refetchSheet = () => {
        if (!sheetId) return;
        fetch(`/hr/training/courses/${sheetId}/detail`, { headers: { Accept: 'application/json' } })
            .then((r) => (r.ok ? r.json() : null))
            .then((d) => d && setSheet(d))
            .catch(() => undefined);
    };
    const remindAssignment = (a: Assignment) => {
        router.post(`/hr/training/assignments/${a.id}/remind`, {}, { preserveScroll: true, onSuccess: () => router.reload() });
    };
    const waiveAssignment = (a: Assignment) => {
        const reason = window.prompt(`Waive ${a.course} for ${a.person} — reason?`);
        if (!reason) return;
        router.patch(`/hr/training/assignments/${a.id}/waive`, { reason }, { preserveScroll: true, onSuccess: () => router.reload() });
    };
    const doExport = (type: string) => {
        window.location.href = `/hr/training/export?type=${type}`;
    };
    const bulkArchive = () => {
        router.post('/hr/training/courses/bulk-archive', { course_ids: selected, active: false }, { preserveScroll: true, onSuccess: () => { setSelected([]); router.reload(); } });
    };

    /* derived: filtered + sorted courses (client-side for snappiness) */
    const visibleCourses = (() => {
        const q = search.trim().toLowerCase();
        let list = courses.filter((c) =>
            !q ? true : [c.title, c.code, c.provider, c.category].some((f) => (f ?? '').toLowerCase().includes(q)),
        );
        const sorters: Record<string, (a: Course, b: Course) => number> = {
            title: (a, b) => a.title.localeCompare(b.title),
            completion: (a, b) => b.completion - a.completion,
            enrol: (a, b) => b.enrol - a.enrol,
            cost: (a, b) => (b.cost ?? 0) - (a.cost ?? 0),
            expiring: (a, b) => b.expiring - a.expiring,
        };
        return [...list].sort(sorters[sort] ?? sorters.title);
    })();

    const visibleAssignments = assignments.filter((r) => asgStatus === 'all' || r.status === asgStatus);

    const courseCtx = (c: Course, e: React.MouseEvent) =>
        openCtx(e, [
            { label: 'Open course', kbd: '↵', onClick: () => setSheetId(c.id) },
            ...(can.manage
                ? [
                      { label: 'Edit course', kbd: 'E', onClick: () => openWizard('editCourse', c) },
                      { label: 'Add session', kbd: 'S', onClick: () => openWizard('session', c) },
                  ]
                : []),
            ...(can.enroll ? [{ label: 'Assign training', kbd: 'A', onClick: () => openWizard('assign', c) }] : []),
            ...(can.record ? [{ label: 'Record completion', kbd: 'R', onClick: () => openWizard('record', c) }] : []),
            ...(can.claim ? [{ label: 'Claim course fee', onClick: () => openWizard('claim', c) }] : []),
            ...(can.manage
                ? [{ label: c.is_active ? 'Archive' : 'Activate', tone: c.is_active ? ('danger' as const) : undefined, onClick: () => toggleArchive(c) }]
                : []),
            { label: 'Export catalog', onClick: () => doExport('catalog') },
        ]);

    const asgCtx = (a: Assignment, e: React.MouseEvent) =>
        openCtx(e, [
            ...(can.record ? [{ label: 'Record completion', onClick: () => { const c = courses.find((x) => x.title === a.course) ?? null; openWizard('record', c); } }] : []),
            ...(can.enroll ? [{ label: 'Send reminder', onClick: () => remindAssignment(a) }] : []),
            ...(can.record || can.manage ? [{ label: 'Waive (reason…)', tone: 'danger' as const, onClick: () => waiveAssignment(a) }] : []),
        ]);

    const heroStats: { label: string; value: string | number; tab?: Tab; amber?: boolean }[] = [
        { label: 'Courses', value: summary.total_courses, tab: 'catalog' },
        { label: 'Mandatory', value: summary.mandatory_courses, tab: 'catalog' },
        { label: 'Enrolments', value: summary.total_enrollments.toLocaleString('en-NZ'), tab: 'assignments' },
        { label: 'Completion', value: `${summary.completion_rate}%`, tab: 'dashboard' },
        { label: 'Expiring ≤90d', value: summary.expiring_soon, tab: 'dashboard' },
        { label: 'Overdue', value: summary.overdue_assignments, tab: 'assignments', amber: true },
    ];

    const TABS: { id: Tab; label: string; icon: typeof BookOpen; badge?: string; tone: 'primary' | 'warning' }[] = [
        { id: 'dashboard', label: 'Dashboard', icon: LayoutDashboard, tone: 'primary' },
        { id: 'catalog', label: 'Catalog', icon: BookOpen, badge: String(courses.length), tone: 'primary' },
        { id: 'assignments', label: 'Assignments', icon: CheckSquare, badge: String(summary.overdue_assignments), tone: 'warning' },
    ];

    return (
        <AppLayout breadcrumbs={breadcrumbs}>
            <Head title="Training & development" />
            <div className="space-y-[18px] p-4 lg:p-6">
                {/* ───────── HERO ───────── */}
                <div className="relative overflow-hidden rounded-[24px] text-white shadow-[var(--shadow-hero,0_24px_60px_-22px_rgba(80,40,160,.45))]" style={{ background: 'linear-gradient(120deg,color-mix(in oklch,var(--primary) 72%,black 22%),var(--primary) 60%,color-mix(in oklch,var(--primary) 92%,white 6%))' }}>
                    <div className="pointer-events-none absolute top-[-80px] right-[22%] h-[240px] w-[240px] rounded-full bg-white/5" />
                    <div className="relative px-[34px] pt-[30px] pb-1">
                        <div className="flex flex-wrap items-start justify-between gap-5">
                            <div className="min-w-0">
                                <h1 className="m-0 text-[27px] font-bold leading-[1.05] tracking-[-.5px]">Training &amp; development</h1>
                                <p className="mt-[9px] flex flex-wrap items-center gap-x-[14px] gap-y-2 text-[13px] text-white/[.78]">
                                    <span className="inline-flex items-center gap-[6px] font-semibold">
                                        <Calendar className="h-[14px] w-[14px]" />
                                        {today}
                                    </span>
                                    <span className="opacity-40">·</span>
                                    <span>All sites</span>
                                </p>
                            </div>
                            <div className="flex flex-wrap gap-[9px]">
                                {can.manage && <HeroBtn icon={Plus} label="New course" onClick={() => openWizard('createCourse')} />}
                                {can.enroll && <HeroBtn icon={UserPlus} label="Assign training" onClick={() => openWizard('assign')} />}
                                {can.record && <HeroBtn icon={CheckSquare} label="Record completion" onClick={() => openWizard('record')} />}
                                <HeroBtn icon={Download} label="Export" onClick={() => doExport('catalog')} />
                            </div>
                        </div>
                        <div className="mt-[18px] mb-1 -ml-3 flex flex-wrap gap-[2px]">
                            {heroStats.map((s) => (
                                <button key={s.label} type="button" onClick={() => s.tab && setTab(s.tab)} className="flex flex-col items-start gap-[2px] rounded-[10px] px-[13px] py-2 text-left hover:bg-white/10">
                                    <span className="text-[10px] font-bold tracking-[.09em] text-white/[.62] uppercase">{s.label}</span>
                                    <span className="text-[21px] font-bold tabular-nums" style={s.amber ? { color: 'var(--hr-amber,oklch(0.86 0.13 90))' } : undefined}>
                                        {s.value}
                                    </span>
                                </button>
                            ))}
                        </div>
                    </div>
                    <div className="relative flex flex-wrap items-center justify-between gap-3 border-t border-white/15 bg-black/[.08] px-[22px] py-[11px]">
                        <span className="text-[11.5px] text-white/70">
                            Compliance: <strong className="text-white">{dashboard.mandatoryCurrentPct}%</strong> of mandatory training current
                        </span>
                        {summary.overdue_assignments > 0 && (
                            <button type="button" onClick={() => setTab('assignments')} className="inline-flex items-center gap-2 rounded-[9px] border border-white/25 bg-white/15 px-[11px] py-[6px] text-[12px] font-bold text-white">
                                <span className="h-[6px] w-[6px] rounded-full" style={{ background: 'var(--hr-amber,oklch(0.86 0.13 90))', boxShadow: '0 0 0 3px color-mix(in oklch,var(--hr-amber,oklch(0.86 0.13 90)) 32%,transparent)' }} />
                                {summary.overdue_assignments} overdue renewals
                                <ChevronRight className="h-3 w-3" />
                            </button>
                        )}
                    </div>
                </div>

                {/* ───────── TAB STRIP ───────── */}
                <div role="tablist" className="flex flex-wrap items-center gap-1 rounded-[14px] border border-border bg-card p-[6px] shadow-sm">
                    {TABS.map((t) => {
                        const active = tab === t.id;
                        const toneVar = t.tone === 'warning' ? 'var(--status-warning)' : 'var(--primary)';
                        const Icon = t.icon;
                        return (
                            <button
                                key={t.id}
                                type="button"
                                role="tab"
                                onClick={() => setTab(t.id)}
                                onContextMenu={(e) =>
                                    openCtx(e, [
                                        { label: 'Open', kbd: '↵', onClick: () => setTab(t.id) },
                                        { label: defaultTab === t.id ? 'Default view' : 'Set as default view', tone: defaultTab === t.id ? 'muted' : undefined, onClick: () => setAsDefault(t.id) },
                                        { label: pinned.includes(t.id) ? 'Unpin tab' : 'Pin tab', onClick: () => togglePin(t.id) },
                                    ])
                                }
                                className="relative inline-flex items-center gap-2 rounded-[9px] px-3 py-2 text-[13px] font-semibold"
                                style={active ? { background: `color-mix(in oklch,${toneVar} 12%,transparent)`, color: toneVar } : { color: 'var(--muted-foreground)' }}
                            >
                                <span className="inline-flex h-[22px] w-[22px] items-center justify-center rounded-md" style={active ? { background: toneVar, color: '#fff' } : { background: 'var(--muted)', color: 'var(--muted-foreground)' }}>
                                    <Icon className="h-[14px] w-[14px]" />
                                </span>
                                <span>{t.label}</span>
                                {t.badge && (
                                    <span className="ml-[2px] rounded-full px-[6px] py-[2px] text-[10px] font-bold tabular-nums" style={t.tone === 'warning' ? { background: 'var(--status-warning-bg)', color: 'var(--status-warning)' } : { background: 'color-mix(in oklch,var(--muted) 80%,transparent)' }}>
                                        {t.badge}
                                    </span>
                                )}
                                {defaultTab === t.id && <span title="Default view" className="opacity-70">★</span>}
                                {pinned.includes(t.id) && <span title="Pinned" className="opacity-70">📌</span>}
                            </button>
                        );
                    })}
                    <span className="ml-auto pr-[6px] text-[11px] text-muted-foreground">Right-click a tab to pin or set default</span>
                </div>

                {/* ───────── DASHBOARD ───────── */}
                {tab === 'dashboard' && (
                    <div className="space-y-4">
                        <div className="grid grid-cols-2 gap-[14px] lg:grid-cols-4">
                            <KpiTile label="Mandatory current" value={`${dashboard.mandatoryCurrentPct}%`} sub="of assignments" onClick={() => setTab('assignments')} />
                            <KpiTile label="Overdue renewals" value={dashboard.overdueCount} tone="critical" sub="needs action" onClick={() => setTab('assignments')} />
                            <KpiTile label="Expiring ≤90 days" value={dashboard.expiringCount} tone="warning" sub="plan renewals now" onClick={() => setTab('catalog')} />
                            <KpiTile label="Training spend YTD" value={fmtNzd(dashboard.spendYtd)} sub={`${dashboard.upcomingSessions.length} upcoming sessions`} />
                        </div>
                        <div className="grid gap-4 lg:grid-cols-[1.3fr_1fr]">
                            <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                                <div className="flex items-center justify-between border-b border-border px-4 py-[14px]">
                                    <div className="text-sm font-semibold">Overdue &amp; due-soon renewals</div>
                                    <button type="button" onClick={() => setTab('assignments')} className="text-[12px] font-semibold text-primary">
                                        View all →
                                    </button>
                                </div>
                                {dashboard.renewals.length === 0 ? (
                                    <p className="px-4 py-10 text-center text-sm text-muted-foreground">No renewals due — everything is current.</p>
                                ) : (
                                    <table className="w-full text-[13px]">
                                        <thead>
                                            <tr className="text-left text-muted-foreground">
                                                <Th>Course</Th>
                                                <Th>Site</Th>
                                                <Th right>Overdue</Th>
                                                <Th right>Due ≤30d</Th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            {dashboard.renewals.map((r, i) => (
                                                <tr key={i} className="border-t border-border">
                                                    <td className="px-4 py-[10px] font-medium">{r.course}</td>
                                                    <td className="px-4 py-[10px] text-muted-foreground">{r.site}</td>
                                                    <td className="px-4 py-[10px] text-right font-semibold text-status-critical">{r.overdue}</td>
                                                    <td className="px-4 py-[10px] text-right">{r.due_soon}</td>
                                                </tr>
                                            ))}
                                        </tbody>
                                    </table>
                                )}
                            </div>
                            <div className="rounded-[14px] border border-border bg-card p-4">
                                <div className="mb-3 text-sm font-semibold">Completion by site</div>
                                <div className="flex flex-col gap-[14px]">
                                    {dashboard.completionBySite.length === 0 && <p className="text-sm text-muted-foreground">No enrolment data yet.</p>}
                                    {dashboard.completionBySite.map((s) => (
                                        <div key={s.site}>
                                            <div className="mb-[5px] flex justify-between text-[12.5px]">
                                                <span className="font-medium">{s.site}</span>
                                                <span className="font-semibold">{s.completion}%</span>
                                            </div>
                                            <div className="h-[7px] overflow-hidden rounded-full bg-muted">
                                                <div className="h-full rounded-full" style={{ width: `${s.completion}%`, background: `var(--status-${completionTone(s.completion)})` }} />
                                            </div>
                                        </div>
                                    ))}
                                </div>
                                <div className="mt-[18px] mb-[10px] text-sm font-semibold">Upcoming sessions</div>
                                <div className="flex flex-col gap-2">
                                    {dashboard.upcomingSessions.length === 0 && <p className="text-sm text-muted-foreground">No sessions scheduled.</p>}
                                    {dashboard.upcomingSessions.map((s) => (
                                        <div key={s.id} className="flex items-center justify-between text-[12.5px]">
                                            <span>
                                                {s.course} · {fmtDate(s.date)}
                                            </span>
                                            <span className="text-muted-foreground">{s.seats != null ? `${s.seats} seats` : '—'}</span>
                                        </div>
                                    ))}
                                </div>
                            </div>
                        </div>
                    </div>
                )}

                {/* ───────── CATALOG ───────── */}
                {tab === 'catalog' && (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center gap-[10px]">
                            <div className="relative min-w-[240px] flex-1">
                                <Search className="absolute top-1/2 left-[11px] h-[15px] w-[15px] -translate-y-1/2 text-muted-foreground" />
                                <input ref={searchRef} value={search} onChange={(e) => setSearch(e.target.value)} placeholder="Search title, code, provider…  ( / )" className="h-10 w-full rounded-[9px] border border-input bg-background pr-3 pl-[33px] text-[13.5px] outline-none focus:outline-2 focus:outline-ring focus:-outline-offset-1" />
                            </div>
                            <select value={sort} onChange={(e) => setSort(e.target.value)} className="h-10 rounded-[9px] border border-input bg-background px-3 text-[13.5px] font-semibold outline-none">
                                <option value="title">Sort: Title</option>
                                <option value="completion">Sort: Completion</option>
                                <option value="enrol">Sort: Enrolments</option>
                                <option value="cost">Sort: Cost</option>
                                <option value="expiring">Sort: Expiring</option>
                            </select>
                            <div className="flex h-10 overflow-hidden rounded-[9px] border border-border">
                                <button type="button" onClick={() => setView('cards')} className={`px-[11px] ${view === 'cards' ? 'bg-muted' : 'bg-card'}`} title="Cards">
                                    <GridIcon />
                                </button>
                                <button type="button" onClick={() => setView('table')} className={`border-l border-border px-[11px] ${view === 'table' ? 'bg-muted' : 'bg-card'}`} title="Table">
                                    <ListIcon />
                                </button>
                            </div>
                            {can.manage && (
                                <button type="button" onClick={() => openWizard('createCourse')} className="inline-flex h-10 items-center gap-[7px] rounded-[9px] bg-primary px-[15px] text-[13px] font-semibold text-white">
                                    <Plus className="h-[15px] w-[15px]" />
                                    New course
                                </button>
                            )}
                        </div>

                        {selected.length > 0 && (
                            <div className="pop flex items-center gap-3 rounded-[11px] border bg-accent px-[14px] py-[9px]" style={{ borderColor: 'color-mix(in oklch,var(--primary) 30%,var(--border))' }}>
                                <span className="text-[13px] font-semibold">{selected.length} selected</span>
                                {can.enroll && <BulkBtn label="Assign to cohort" onClick={() => openWizard('assign')} />}
                                <BulkBtn label="Export" onClick={() => doExport('catalog')} />
                                {can.manage && <BulkBtn label="Archive" danger onClick={bulkArchive} />}
                                <button type="button" onClick={() => setSelected([])} className="ml-auto text-[12.5px] text-muted-foreground">
                                    Clear
                                </button>
                            </div>
                        )}

                        {visibleCourses.length === 0 ? (
                            <div className="rounded-[14px] border border-dashed border-border py-16 text-center">
                                <p className="font-medium">No courses found</p>
                                <p className="mt-1 text-sm text-muted-foreground">{search ? 'No courses match your search.' : 'Create your first training course to get started.'}</p>
                            </div>
                        ) : view === 'cards' ? (
                            <div className="grid gap-[14px]" style={{ gridTemplateColumns: 'repeat(auto-fill,minmax(330px,1fr))' }}>
                                {visibleCourses.map((c) => {
                                    const tone = completionTone(c.completion);
                                    return (
                                        <div key={c.id} className="lift flex cursor-pointer flex-col gap-[11px] rounded-[14px] border border-border bg-card p-[15px]" onClick={() => setSheetId(c.id)} onContextMenu={(e) => courseCtx(c, e)}>
                                            <div className="flex items-start gap-[10px]">
                                                <button
                                                    type="button"
                                                    onClick={(e) => { e.stopPropagation(); setSelected((s) => (s.includes(c.id) ? s.filter((x) => x !== c.id) : [...s, c.id])); }}
                                                    className="mt-[2px] flex h-[17px] w-[17px] flex-none items-center justify-center rounded-[5px] border-[1.5px] bg-card"
                                                    style={{ borderColor: selected.includes(c.id) ? 'var(--primary)' : 'var(--border)' }}
                                                >
                                                    {selected.includes(c.id) && <CheckIcon />}
                                                </button>
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-[15px] font-semibold leading-[1.25]">{c.title}</div>
                                                    <div className="mt-[2px] text-[12px] text-muted-foreground">
                                                        {c.code}
                                                        {c.provider ? ` · ${c.provider}` : ''}
                                                    </div>
                                                </div>
                                                <button type="button" onClick={(e) => courseCtx(c, e)} className="flex h-7 w-7 flex-none items-center justify-center rounded-[7px] text-muted-foreground hover:bg-muted">
                                                    <MoreVertical className="h-4 w-4" />
                                                </button>
                                            </div>
                                            <div className="flex flex-wrap gap-[6px]">
                                                <Pill tone={c.is_mandatory ? 'info' : 'neutral'}>{c.is_mandatory ? 'Mandatory' : 'Optional'}</Pill>
                                                {c.category && <Pill tone="category">{c.category}</Pill>}
                                                <Pill tone="outline">
                                                    {DELIVERY_LABELS[c.delivery_method] ?? c.delivery_method} · {c.duration_hours}h
                                                </Pill>
                                                <Pill tone={c.is_active ? 'success' : 'neutral'}>{c.is_active ? 'Active' : 'Inactive'}</Pill>
                                            </div>
                                            <div>
                                                <div className="mb-1 flex justify-between text-[11.5px]">
                                                    <span className="text-muted-foreground">
                                                        {c.enrol} enrolled · {c.requires_renewal && c.validity_period_months ? `Renew ${c.validity_period_months}mo` : 'No renewal'}
                                                    </span>
                                                    <span className="font-bold" style={{ color: `var(--status-${tone})` }}>
                                                        {c.completion}%
                                                    </span>
                                                </div>
                                                <div className="h-[6px] overflow-hidden rounded-full bg-muted">
                                                    <div className="h-full rounded-full" style={{ width: `${c.completion}%`, background: `var(--status-${tone})` }} />
                                                </div>
                                            </div>
                                            <div className="flex items-center justify-between border-t border-border pt-[10px]">
                                                <span className="text-[13px] font-bold">{fmtNzd(c.cost)}</span>
                                                <div className="flex gap-[6px]">
                                                    {can.manage && (
                                                        <button type="button" onClick={(e) => { e.stopPropagation(); openWizard('editCourse', c); }} className="rounded-[7px] border border-border bg-card px-[10px] py-[5px] text-[12px] font-semibold">
                                                            Edit
                                                        </button>
                                                    )}
                                                    {can.enroll && (
                                                        <button type="button" onClick={(e) => { e.stopPropagation(); openWizard('assign', c); }} className="rounded-[7px] bg-primary px-[10px] py-[5px] text-[12px] font-semibold text-white">
                                                            Assign
                                                        </button>
                                                    )}
                                                </div>
                                            </div>
                                        </div>
                                    );
                                })}
                            </div>
                        ) : (
                            <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                                <table className="w-full text-[13px]">
                                    <thead>
                                        <tr className="bg-muted text-left text-muted-foreground">
                                            <Th>Course</Th>
                                            <Th>Category</Th>
                                            <Th>Delivery</Th>
                                            <Th right>Enrolled</Th>
                                            <Th right>Completion</Th>
                                            <Th right>Fee</Th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visibleCourses.map((c) => (
                                            <tr key={c.id} className="cursor-pointer border-t border-border hover:bg-muted" onClick={() => setSheetId(c.id)} onContextMenu={(e) => courseCtx(c, e)}>
                                                <td className="px-[14px] py-[11px]">
                                                    <div className="font-semibold">{c.title}</div>
                                                    <div className="text-[11.5px] text-muted-foreground">
                                                        {c.code}
                                                        {c.provider ? ` · ${c.provider}` : ''}
                                                    </div>
                                                </td>
                                                <td className="px-[14px] py-[11px]">{c.category ? <Pill tone="category">{c.category}</Pill> : '—'}</td>
                                                <td className="px-[14px] py-[11px] text-muted-foreground">{DELIVERY_LABELS[c.delivery_method] ?? c.delivery_method}</td>
                                                <td className="px-[14px] py-[11px] text-right tabular-nums">{c.enrol}</td>
                                                <td className="px-[14px] py-[11px] text-right font-bold" style={{ color: `var(--status-${completionTone(c.completion)})` }}>
                                                    {c.completion}%
                                                </td>
                                                <td className="px-[14px] py-[11px] text-right font-semibold">{fmtNzd(c.cost)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </div>
                )}

                {/* ───────── ASSIGNMENTS ───────── */}
                {tab === 'assignments' && (
                    <div className="space-y-4">
                        <div className="flex flex-wrap items-center gap-2">
                            {['all', 'assigned', 'in_progress', 'overdue', 'completed', 'waived'].map((s) => (
                                <button key={s} type="button" onClick={() => setAsgStatus(s)} className="rounded-full px-[13px] py-[6px] text-[12.5px] font-semibold" style={asgStatus === s ? { background: 'var(--primary)', color: '#fff' } : { background: 'var(--muted)', color: 'var(--muted-foreground)' }}>
                                    {s === 'all' ? 'All' : STATUS_LABEL[s]}
                                </button>
                            ))}
                            {can.enroll && (
                                <button type="button" onClick={() => openWizard('assign')} className="ml-auto inline-flex items-center gap-[7px] rounded-[9px] bg-primary px-[14px] py-[7px] text-[13px] font-semibold text-white">
                                    <Plus className="h-[15px] w-[15px]" />
                                    Assign training
                                </button>
                            )}
                        </div>
                        <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                            {visibleAssignments.length === 0 ? (
                                <p className="px-4 py-12 text-center text-sm text-muted-foreground">No assignments in this view.</p>
                            ) : (
                                <table className="w-full text-[13px]">
                                    <thead>
                                        <tr className="bg-muted text-left text-muted-foreground">
                                            <Th>Person</Th>
                                            <Th>Course</Th>
                                            <Th>Source</Th>
                                            <Th>Due</Th>
                                            <Th>Status</Th>
                                            <Th right>Score</Th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {visibleAssignments.map((r) => (
                                            <tr key={r.id} className="cursor-pointer border-t border-border hover:bg-muted" onContextMenu={(e) => asgCtx(r, e)}>
                                                <td className="px-[14px] py-[11px] font-semibold">{r.person}</td>
                                                <td className="px-[14px] py-[11px]">{r.course}</td>
                                                <td className="px-[14px] py-[11px] text-muted-foreground">{SOURCE_LABEL[r.source] ?? r.source}</td>
                                                <td className="px-[14px] py-[11px] text-muted-foreground">{fmtDate(r.due)}</td>
                                                <td className="px-[14px] py-[11px]">
                                                    <span className={`inline-flex rounded-full border px-[10px] py-[2px] text-[11px] font-semibold ${STATUS_BADGE[r.status] ?? STATUS_BADGE.assigned}`}>{STATUS_LABEL[r.status] ?? r.status}</span>
                                                </td>
                                                <td className="px-[14px] py-[11px] text-right tabular-nums">{r.score != null ? `${r.score}%` : '—'}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            )}
                        </div>
                        <p className="text-[12px] text-muted-foreground">Right-click a row to record completion, send a reminder, or waive.</p>
                    </div>
                )}
            </div>

            {/* ───────── CONTEXT MENU ───────── */}
            {ctx && (
                <div className="pop fixed z-[80] min-w-[222px] rounded-[11px] border border-border bg-popover p-[5px] shadow-2xl" style={{ left: ctx.x, top: ctx.y }} onClick={(e) => e.stopPropagation()}>
                    {ctx.items.map((it, i) => (
                        <button key={i} type="button" onClick={() => { setCtx(null); it.onClick(); }} className="flex w-full items-center gap-[10px] rounded-[7px] px-[10px] py-2 text-left text-[13px] font-medium hover:bg-muted" style={{ color: it.tone === 'danger' ? 'var(--status-critical)' : it.tone === 'muted' ? 'var(--muted-foreground)' : 'var(--foreground)' }}>
                            <span className="flex-1">{it.label}</span>
                            {it.kbd && <kbd className="rounded-[5px] border border-border bg-muted px-[5px] py-[1px] font-mono text-[10.5px] text-muted-foreground">{it.kbd}</kbd>}
                        </button>
                    ))}
                </div>
            )}

            {/* ───────── SHEET ───────── */}
            {sheetId && (
                <>
                    <div className="ovl fixed inset-0 z-[70] bg-[rgba(20,10,40,.4)]" onClick={() => setSheetId(null)} />
                    <div className="slide thin fixed inset-y-0 right-0 z-[71] w-[min(560px,94vw)] overflow-y-auto bg-background shadow-2xl">
                        <div className="relative px-[26px] py-6 text-white" style={{ background: 'linear-gradient(120deg,color-mix(in oklch,var(--primary) 72%,black 18%),var(--primary))' }}>
                            <button type="button" onClick={() => setSheetId(null)} className="absolute top-[18px] right-[18px] flex h-[30px] w-[30px] items-center justify-center rounded-lg bg-white/[.18] text-[15px] text-white">
                                ✕
                            </button>
                            {sheet ? (
                                <>
                                    <div className="text-[12px] font-semibold opacity-80">
                                        {sheet.code}
                                        {sheet.provider ? ` · ${sheet.provider}` : ''}
                                    </div>
                                    <h2 className="mt-[6px] max-w-[90%] text-[22px] font-bold leading-[1.15]">{sheet.title}</h2>
                                    <div className="mt-3 flex flex-wrap gap-[7px] text-[12px]">
                                        <SheetChip>{sheet.is_mandatory ? 'Mandatory' : 'Optional'}</SheetChip>
                                        <SheetChip>{DELIVERY_LABELS[sheet.delivery_method] ?? sheet.delivery_method} · {sheet.duration_hours}h</SheetChip>
                                        <SheetChip>{sheet.requires_renewal && sheet.validity_period_months ? `Renew ${sheet.validity_period_months}mo` : 'No renewal'}</SheetChip>
                                        <SheetChip>{fmtNzd(sheet.cost)}</SheetChip>
                                    </div>
                                </>
                            ) : (
                                <div className="text-sm opacity-80">Loading…</div>
                            )}
                        </div>
                        {sheet && (
                            <div className="px-[26px] py-5">
                                <div className="mb-[18px] flex flex-wrap gap-2">
                                    {can.manage && <SheetBtn label="Edit course" onClick={() => openWizard('editCourse', sheet as unknown as WizardCourse)} />}
                                    {can.manage && <SheetBtn label="Add session" onClick={() => openWizard('session', sheet as unknown as WizardCourse)} />}
                                    {can.enroll && <SheetBtn label="Assign" onClick={() => openWizard('assign', sheet as unknown as WizardCourse)} />}
                                    {can.record && <SheetBtn label="Record" onClick={() => openWizard('record', sheet as unknown as WizardCourse)} />}
                                    {can.claim && <SheetBtn label="Claim fee" onClick={() => openWizard('claim', sheet as unknown as WizardCourse)} />}
                                </div>
                                <div className="mb-5 grid grid-cols-3 gap-[10px]">
                                    <MiniStat label="Enrolled" value={sheet.metrics.enrol} />
                                    <MiniStat label="Completion" value={`${sheet.metrics.completion}%`} />
                                    <MiniStat label="Expiring ≤90d" value={sheet.metrics.expiring} tone="warning" />
                                </div>
                                <div className="mb-2 text-[13px] font-bold">Sessions</div>
                                <div className="mb-[18px] overflow-hidden rounded-[14px] border border-border">
                                    {sheet.sessions.length === 0 ? (
                                        <div className="px-[14px] py-4 text-[13px] text-muted-foreground">No sessions scheduled.</div>
                                    ) : (
                                        sheet.sessions.map((s) => (
                                            <div key={s.id} className="flex items-center justify-between border-b border-border px-[14px] py-[11px] text-[13px] last:border-b-0 hover:bg-muted" onContextMenu={(e) => can.manage && openCtx(e, [{ label: 'Cancel session', tone: 'danger', onClick: () => cancelSession(s.id) }])}>
                                                <div>
                                                    <div className="font-semibold">
                                                        {fmtDate(s.session_date)}
                                                        {s.start_time ? ` · ${s.start_time}${s.end_time ? `–${s.end_time}` : ''}` : ''}
                                                    </div>
                                                    <div className="text-[11.5px] text-muted-foreground">{[s.location, s.trainer].filter(Boolean).join(' · ') || '—'}</div>
                                                </div>
                                                <span className={`inline-flex rounded-full border px-[9px] py-[2px] text-[11px] font-semibold ${s.status === 'cancelled' ? STATUS_BADGE.overdue : STATUS_BADGE.completed}`}>
                                                    {s.status === 'cancelled' ? 'Cancelled' : s.seats != null ? `${s.seats} seats` : 'Scheduled'}
                                                </span>
                                            </div>
                                        ))
                                    )}
                                </div>
                                <div className="mb-2 text-[13px] font-bold">Recent enrolments</div>
                                <div className="overflow-hidden rounded-[14px] border border-border">
                                    {sheet.enrollments.length === 0 ? (
                                        <div className="px-[14px] py-4 text-[13px] text-muted-foreground">No enrolments yet.</div>
                                    ) : (
                                        sheet.enrollments.map((e) => (
                                            <div key={e.id} className="flex items-center justify-between border-b border-border px-[14px] py-[10px] text-[13px] last:border-b-0">
                                                <span>{e.name}</span>
                                                <span className={`inline-flex rounded-full border px-[9px] py-[2px] text-[11px] font-semibold ${STATUS_BADGE[e.status] ?? STATUS_BADGE.assigned}`}>
                                                    {(STATUS_LABEL[e.status] ?? e.status)}
                                                    {e.score != null ? ` · ${e.score}%` : ''}
                                                </span>
                                            </div>
                                        ))
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                </>
            )}

            {/* ───────── WIZARD ───────── */}
            <TrainingWizardDialog
                type={wizard?.type ?? null}
                course={wizard?.course ?? null}
                lookups={lookups}
                courses={courses as unknown as WizardCourse[]}
                onClose={() => setWizard(null)}
                onSaved={onSaved}
            />
        </AppLayout>
    );
}

/* ---------------------------------------------------------- subcomponents */
function HeroBtn({ icon: Icon, label, onClick }: { icon: typeof Plus; label: string; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} className="inline-flex items-center gap-[7px] rounded-[10px] border border-white/25 bg-white/[.16] px-[14px] py-[9px] text-[12.5px] font-semibold text-white hover:bg-white/25">
            <Icon className="h-[15px] w-[15px]" />
            {label}
        </button>
    );
}
function KpiTile({ label, value, sub, tone, onClick }: { label: string; value: string | number; sub?: string; tone?: 'critical' | 'warning'; onClick?: () => void }) {
    return (
        <div className={`lift rounded-[14px] border border-border bg-card p-4 ${onClick ? 'cursor-pointer' : ''}`} onClick={onClick}>
            <div className="text-[11px] font-bold tracking-[.08em] text-muted-foreground uppercase">{label}</div>
            <div className="mt-[6px] text-[30px] font-bold tabular-nums" style={tone ? { color: `var(--status-${tone})` } : undefined}>
                {value}
            </div>
            {sub && <div className="mt-[2px] text-[12px] text-muted-foreground">{sub}</div>}
        </div>
    );
}
function Th({ children, right }: { children: React.ReactNode; right?: boolean }) {
    return <th className={`px-4 py-[9px] text-[11px] font-semibold tracking-[.05em] uppercase ${right ? 'text-right' : ''}`}>{children}</th>;
}
function Pill({ children, tone }: { children: React.ReactNode; tone: 'info' | 'neutral' | 'success' | 'outline' | 'category' }) {
    const styles: Record<string, string> = {
        info: 'bg-status-info-bg text-status-info border border-status-info/30',
        neutral: 'bg-muted text-muted-foreground border border-border',
        success: 'bg-status-success-bg text-status-success border border-status-success/30',
        outline: 'border border-border text-muted-foreground',
    };
    const style = tone === 'category' ? { background: 'var(--category-hr-bg)', color: 'var(--category-hr)' } : undefined;
    return (
        <span className={`inline-flex items-center rounded-full px-[9px] py-[2px] text-[11px] font-semibold ${tone === 'category' ? '' : styles[tone]}`} style={style}>
            {children}
        </span>
    );
}
function BulkBtn({ label, onClick, danger }: { label: string; onClick: () => void; danger?: boolean }) {
    return (
        <button type="button" onClick={onClick} className="rounded-[8px] border border-border bg-card px-[11px] py-[6px] text-[12.5px] font-semibold" style={danger ? { color: 'var(--status-critical)' } : undefined}>
            {label}
        </button>
    );
}
function SheetChip({ children }: { children: React.ReactNode }) {
    return <span className="rounded-full bg-white/[.18] px-[10px] py-[3px] font-semibold">{children}</span>;
}
function SheetBtn({ label, onClick }: { label: string; onClick: () => void }) {
    return (
        <button type="button" onClick={onClick} className="rounded-[8px] border border-border bg-card px-3 py-[7px] text-[12.5px] font-semibold">
            {label}
        </button>
    );
}
function MiniStat({ label, value, tone }: { label: string; value: string | number; tone?: 'warning' }) {
    return (
        <div className="rounded-[14px] border border-border bg-card p-[13px]">
            <div className="text-[11px] font-semibold text-muted-foreground">{label}</div>
            <div className="text-[22px] font-bold" style={tone ? { color: `var(--status-${tone})` } : undefined}>
                {value}
            </div>
        </div>
    );
}
function GridIcon() {
    return (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <rect x="3" y="3" width="7" height="7" />
            <rect x="14" y="3" width="7" height="7" />
            <rect x="3" y="14" width="7" height="7" />
            <rect x="14" y="14" width="7" height="7" />
        </svg>
    );
}
function ListIcon() {
    return (
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2">
            <path d="M3 5h18M3 12h18M3 19h18" />
        </svg>
    );
}
function CheckIcon() {
    return (
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="var(--primary)" strokeWidth="3">
            <path d="M20 6 9 17l-5-5" />
        </svg>
    );
}
