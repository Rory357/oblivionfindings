import { OpsStatCard } from '@/components/ops-stat-card';
import PageShell from '@/components/page-shell';
import { ReasonDialog } from '@/components/reason-dialog';
import { TabStrip, type RosterTabItem } from '@/components/rostering/tab-strip';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import CreateTimesheetDialog, {
    type ClientOption,
    type ShiftOption,
    type SiteOption,
} from '@/components/timesheets/create-timesheet-dialog';
import EditTimesheetDialog, {
    type EditTimesheetRow,
} from '@/components/timesheets/edit-timesheet-dialog';
import TimesheetsHero from '@/components/timesheets/timesheets-hero';
import ViewTimesheetDialog, { type ViewTimesheetRow } from '@/components/timesheets/view-timesheet-dialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import {
    AlertTriangle,
    Archive,
    ArchiveRestore,
    Banknote,
    CalendarDays,
    Car,
    CheckCircle2,
    ClipboardCheck,
    Coffee,
    Copy,
    DollarSign,
    Eye,
    FileDown,
    FileText,
    Filter,
    Link2,
    ListChecks,
    MapPin,
    MessageSquareWarning,
    Moon,
    MoreHorizontal,
    Pencil,
    Receipt,
    RotateCcw,
    Search,
    Send,
    Sun,
    Trash2,
    Undo2,
    User,
    UserPlus,
    Users,
    XCircle,
} from 'lucide-react';
import React, { useEffect, useRef, useState } from 'react';

// ─────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────
type TimesheetRow = ViewTimesheetRow & {
    hours?: number;
    total_hours?: number;
};

type HeroSummary = {
    firstName: string;
    week_start: string;
    week_end: string;
    week_number: number;
    timesheets_total: number;
    timesheets_submitted: number;
    timesheets_approved: number;
    timesheets_returned: number;
    unapproved: number;
    hours_this_week: number;
    hours_target: number;
    next_payroll_date: string;
    sites_count: number;
    regions_count: number;
    rostered_today: number;
    staff_on_shift: number;
};

type TabCounts = Record<string, number>;

type Props = {
    timesheets: { data: TimesheetRow[]; meta?: any };
    filters: { tab?: string; from?: string; to?: string; client_id?: string; staff_id?: string; search?: string };
    tabCounts: TabCounts;
    heroSummary: HeroSummary;
    isOwnOnlyView: boolean;
    clients: ClientOption[];
    sites: SiteOption[];
    staff: Array<{ id: number; name: string }>;
    availableShifts: ShiftOption[];
    canApprove: boolean;
    canCreate: boolean;
};

const TABS: Array<{
    key: string;
    label: string;
    icon: RosterTabItem['icon'];
    tone: RosterTabItem['tone'];
}> = [
    { key: 'all', label: 'All', icon: ListChecks, tone: 'primary' },
    { key: 'draft', label: 'Drafts', icon: Pencil, tone: 'info' },
    { key: 'submitted', label: 'Pending', icon: ClipboardCheck, tone: 'warning' },
    { key: 'returned', label: 'Returned', icon: RotateCcw, tone: 'critical' },
    { key: 'approved', label: 'Approved', icon: CheckCircle2, tone: 'success' },
    { key: 'paid', label: 'Paid', icon: Banknote, tone: 'success' },
    { key: 'archived', label: 'Archive', icon: Archive, tone: 'primary' },
];

// Preserved export — index.test.ts references this constant.
export const needsApprovalBadgeClassName =
    'border-status-warning/30 bg-status-warning-bg text-[10px] text-status-warning';

function fmtTime(iso: string) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString('en-NZ', { hour: '2-digit', minute: '2-digit' });
}
function fmtDate(iso: string) {
    if (!iso) return '';
    return new Date(iso).toLocaleDateString('en-NZ', { day: 'numeric', month: 'short', year: 'numeric' });
}
function initials(name?: string | null) {
    if (!name) return '?';
    return name.split(' ').map((w) => w[0]).slice(0, 2).join('').toUpperCase();
}
function hueFor(name?: string | null) {
    if (!name) return 200;
    let h = 0;
    for (let i = 0; i < name.length; i++) h = (h * 31 + name.charCodeAt(i)) % 360;
    return h;
}

// ─────────────────────────────────────────────────────────────────────
// Hover popover — appears on row hover after ~350ms.
// ─────────────────────────────────────────────────────────────────────
function HoverPopover({ hover }: { hover: { row: TimesheetRow; rect: DOMRect } | null }) {
    if (!hover) return null;
    const { row: t, rect } = hover;
    const W = 340;
    const margin = 12;
    const left = rect.right + margin + W > window.innerWidth ? Math.max(margin, rect.left - W - margin) : rect.right + margin;
    const top = Math.max(margin, Math.min(rect.top, window.innerHeight - 360 - margin));
    const hours = (t.total_hours ?? t.hours ?? 0) as number;
    const taskPct = (t.tasks_total ?? 0) > 0 ? Math.round(((t.tasks_completed ?? 0) / (t.tasks_total ?? 1)) * 100) : 0;
    const blurb: Record<string, string> = {
        draft: 'In progress — not yet submitted.',
        submitted: 'Awaiting manager decision.',
        returned: 'Returned to staff for changes.',
        approved: 'Approved · ready for payroll.',
        rejected: 'Rejected — see notes.',
        paid: 'Paid in the most recent pay run.',
        archived: 'Archived from the active list.',
    };

    return (
        <div className="pointer-events-none fixed z-40" style={{ left, top, width: W }}>
            <div className="pointer-events-auto overflow-hidden rounded-xl border border-border bg-card shadow-2xl ring-1 ring-black/5">
                <div className="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                    <div className="min-w-0">
                        <div className="text-[10.5px] uppercase tracking-wider text-muted-foreground">Timesheet #{t.id}</div>
                        <div className="truncate text-[13px] font-semibold">
                            {t.client ? `${t.client.first_name} ${t.client.last_name}` : t.activity_type ?? 'Manual entry'}
                        </div>
                    </div>
                    <TimesheetStatusBadge status={t.status} />
                </div>
                <div className="space-y-2.5 px-3 py-3 text-xs">
                    <div className="text-[11.5px] italic text-muted-foreground">{blurb[t.status] ?? ''}</div>
                    <div className="grid grid-cols-3 gap-1.5">
                        <div className="rounded-md border border-border bg-muted/30 px-2 py-1.5">
                            <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Hours</div>
                            <div className="mt-0.5 text-[12.5px] font-semibold tabular-nums">{hours.toFixed(2)}h</div>
                        </div>
                        <div className="rounded-md border border-border bg-muted/30 px-2 py-1.5">
                            <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Break</div>
                            <div className="mt-0.5 text-[12.5px] font-semibold tabular-nums">{t.break_minutes}m</div>
                        </div>
                        <div className="rounded-md border border-border bg-muted/30 px-2 py-1.5">
                            <div className="text-[10px] uppercase tracking-wider text-muted-foreground">Mileage</div>
                            <div className="mt-0.5 text-[12.5px] font-semibold tabular-nums">
                                {(t.mileage_km ?? 0) > 0 ? `${t.mileage_km}km` : '—'}
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 rounded-md bg-muted/40 px-2 py-1.5 text-[11.5px] text-muted-foreground">
                        <span className="tabular-nums">
                            {fmtTime(t.starts_at)} – {fmtTime(t.ends_at)}
                        </span>
                        <span className="ml-auto">{fmtDate(t.work_date)}</span>
                    </div>
                    {t.shift ? (
                        <div className="rounded-md border border-border px-2 py-1.5">
                            <div className="flex items-center justify-between text-[11.5px]">
                                <span className="font-medium">Shift #{t.shift.id}</span>
                                <span className="capitalize text-muted-foreground">
                                    {(t.shift.shift_type ?? 'standard').replace('_', ' ')}
                                </span>
                            </div>
                            {t.shift.location ? (
                                <div className="mt-0.5 inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                    <MapPin className="h-3 w-3" />
                                    {t.shift.location}
                                </div>
                            ) : null}
                        </div>
                    ) : null}
                    {(t.tasks_total ?? 0) > 0 ? (
                        <div>
                            <div className="mb-1 flex items-center justify-between">
                                <span className="text-[11.5px] font-medium">Tasks pulled from shift</span>
                                <span className="text-[11px] tabular-nums text-muted-foreground">
                                    {t.tasks_completed ?? 0}/{t.tasks_total ?? 0}
                                </span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={cn('h-full rounded-full', taskPct === 100 ? 'bg-status-success' : 'bg-primary')}
                                    style={{ width: taskPct + '%' }}
                                />
                            </div>
                        </div>
                    ) : null}
                    <div className="rounded-md bg-muted/30 px-2 py-1.5 text-[11.5px] text-muted-foreground">
                        <div>
                            <span className="text-muted-foreground/70">Worked by</span>{' '}
                            <span className="font-medium">{t.staff?.name ?? '—'}</span>
                        </div>
                    </div>
                    {t.status === 'returned' && t.returned_notes ? (
                                <div className="flex items-start gap-1.5 rounded-md bg-status-critical-bg px-2 py-1.5 text-[11.5px] text-status-critical">
                            <AlertTriangle className="mt-0.5 h-3 w-3 shrink-0" />
                            <span>{t.returned_notes}</span>
                        </div>
                    ) : null}
                </div>
                <div className="border-t border-border bg-muted/40 px-3 py-1.5 text-[10.5px] text-muted-foreground">
                    Click to open · right-click for actions
                </div>
            </div>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────
// Right-click context menu — status-aware.
// ─────────────────────────────────────────────────────────────────────
type MenuItem = { id?: string; label?: string; icon?: any; tone?: 'primary' | 'success' | 'warning' | 'danger'; separator?: boolean };

function menuItemsFor(t: TimesheetRow): MenuItem[] {
    const common: MenuItem[] = [
        { id: 'view', label: 'View timesheet', icon: Eye },
        { id: 'shift', label: 'Open linked shift → #' + (t.shift?.id ?? '—'), icon: CalendarDays },
        { id: 'client', label: 'Open client profile', icon: User },
        { id: 'staff', label: 'Open staff profile', icon: Users },
        { separator: true },
    ];
    const tail: MenuItem[] = [
        { separator: true },
        { id: 'copy', label: 'Copy timesheet link', icon: Link2 },
        { id: 'pdf', label: 'Export as PDF', icon: FileDown },
    ];
    const byStatus: Record<string, MenuItem[]> = {
        draft: [
            { id: 'edit', label: 'Edit hours & breaks', icon: Pencil },
            { id: 'submit', label: 'Submit for approval', icon: Send, tone: 'primary' },
            { id: 'duplicate', label: 'Duplicate as new draft', icon: Copy },
            { id: 'discard', label: 'Discard draft', icon: Trash2, tone: 'danger' },
        ],
        submitted: [
            { id: 'approve', label: 'Approve', icon: CheckCircle2, tone: 'success' },
            { id: 'return', label: 'Return for changes…', icon: RotateCcw, tone: 'warning' },
            { id: 'reject', label: 'Reject…', icon: XCircle, tone: 'danger' },
            { id: 'reassign', label: 'Re-assign approver', icon: UserPlus },
        ],
        returned: [
            { id: 'edit', label: 'Edit & resubmit', icon: Pencil },
            { id: 'notes', label: 'View return notes', icon: MessageSquareWarning },
            { id: 'discard', label: 'Discard timesheet', icon: Trash2, tone: 'danger' },
        ],
        approved: [
            { id: 'reopen', label: 'Re-open for correction', icon: Undo2 },
            { id: 'payroll', label: 'View payroll impact', icon: DollarSign },
        ],
        paid: [
            { id: 'payslip', label: 'View payslip line', icon: Receipt },
            { id: 'archive', label: 'Archive timesheet', icon: Archive },
            { id: 'correction', label: 'Raise correction request', icon: AlertTriangle, tone: 'warning' },
        ],
        rejected: [
            { id: 'reason', label: 'View rejection reason', icon: MessageSquareWarning },
            { id: 'recreate', label: 'Recreate from this', icon: Copy },
            { id: 'archive', label: 'Archive timesheet', icon: Archive },
        ],
        archived: [
            { id: 'restore', label: 'Restore to active list', icon: ArchiveRestore, tone: 'primary' },
            { id: 'pdf', label: 'Download archived copy', icon: FileDown },
        ],
    };
    return [...common, ...(byStatus[t.status] ?? []), ...tail];
}

function ContextMenu({
    menu,
    onClose,
    onAction,
}: {
    menu: { x: number; y: number; row: TimesheetRow } | null;
    onClose: () => void;
    onAction: (id: string, row: TimesheetRow) => void;
}) {
    const ref = useRef<HTMLDivElement | null>(null);
    useEffect(() => {
        const onAway = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) onClose();
        };
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        document.addEventListener('mousedown', onAway);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onAway);
            document.removeEventListener('keydown', onKey);
        };
    }, [onClose]);

    if (!menu) return null;
    const { x, y, row } = menu;
    const items = menuItemsFor(row);
    const W = 260;
    const H = Math.min(440, 36 * items.length + 56);
    const left = Math.min(x, window.innerWidth - W - 8);
    const top = Math.min(y, window.innerHeight - H - 8);

    const toneCls: Record<string, string> = {
        primary: 'text-foreground',
        success: 'text-emerald-700 hover:bg-emerald-50',
        warning: 'text-amber-700 hover:bg-amber-50',
        danger: 'text-rose-700 hover:bg-rose-50',
    };

    return (
        <div
            ref={ref}
            role="menu"
            className="fixed z-[60] w-[260px] overflow-hidden rounded-xl border border-border bg-card py-1.5 shadow-2xl ring-1 ring-black/5"
            style={{ left, top }}
        >
            <div className="flex items-center justify-between gap-2 px-3 py-1.5">
                <div className="min-w-0">
                    <div className="truncate text-[11.5px] font-semibold">
                        #{row.id} ·{' '}
                        {row.client ? `${row.client.first_name} ${row.client.last_name}` : row.activity_type ?? 'Manual'}
                    </div>
                    <div className="text-[10.5px] text-muted-foreground">
                        {row.staff?.name ?? 'Staff'} · {fmtDate(row.work_date)}
                    </div>
                </div>
                <TimesheetStatusBadge status={row.status} />
            </div>
            <div className="my-1 h-px bg-border" />
            {items.map((it, i) => {
                if (it.separator) return <div key={'s' + i} className="my-1 h-px bg-border" />;
                const Ic = it.icon;
                return (
                    <button
                        key={i}
                        onClick={() => {
                            if (it.id) onAction(it.id, row);
                            onClose();
                        }}
                        className={cn(
                            'flex w-full items-center gap-2.5 px-3 py-1.5 text-left text-[12.5px] hover:bg-muted',
                            toneCls[it.tone ?? 'primary'] ?? 'text-foreground',
                        )}
                        role="menuitem"
                    >
                        {Ic ? <Ic className="h-3.5 w-3.5 opacity-80" /> : null}
                        <span className="flex-1 truncate">{it.label}</span>
                    </button>
                );
            })}
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────
// Week helpers (same local-date arithmetic as the Shifts page — avoid
// toISOString(), which rolls back a day east of UTC).
// ─────────────────────────────────────────────────────────────────────
function toLocalIsoDate(d: Date): string {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

function addDaysIso(iso: string, days: number): string {
    const d = new Date(iso + 'T00:00:00');
    d.setDate(d.getDate() + days);
    return toLocalIsoDate(d);
}

function weekStartFor(iso: string): string {
    const d = new Date(iso + 'T00:00:00');
    const dow = d.getDay(); // 0=Sun..6=Sat
    const monOffset = dow === 0 ? -6 : 1 - dow;
    d.setDate(d.getDate() + monOffset);
    return toLocalIsoDate(d);
}

// ─────────────────────────────────────────────────────────────────────
// Page
// ─────────────────────────────────────────────────────────────────────
export default function TimesheetsIndex({
    timesheets,
    filters,
    tabCounts,
    heroSummary,
    isOwnOnlyView,
    clients,
    sites,
    staff,
    availableShifts,
    canApprove,
    canCreate,
}: Props) {
    const [tab, setTab] = useState(filters.tab ?? 'all');
    const [search, setSearch] = useState(filters.search ?? '');
    const [menu, setMenu] = useState<{ x: number; y: number; row: TimesheetRow } | null>(null);
    const [hover, setHover] = useState<{ row: TimesheetRow; rect: DOMRect } | null>(null);
    const [viewing, setViewing] = useState<TimesheetRow | null>(null);
    const [editing, setEditing] = useState<TimesheetRow | null>(null);
    const [reasonTarget, setReasonTarget] = useState<{ action: 'reject' | 'return'; row: TimesheetRow } | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [initialShiftId, setInitialShiftId] = useState<number | null>(null);
    const hoverTimer = useRef<number | null>(null);

    // Dialog deep links: ?create=1 (shift detail), ?view={id} (attendance,
    // dashboards), ?edit={id} (return banners, legacy /edit page redirect).
    useEffect(() => {
        const params = new URLSearchParams(window.location.search);
        if (params.get('create') === '1') {
            const sid = params.get('shift_id');
            setInitialShiftId(sid ? Number(sid) : null);
            setCreateOpen(true);
        }
        const viewId = params.get('view');
        if (viewId) {
            const row = timesheets.data.find((r) => String(r.id) === viewId);
            if (row) setViewing(row);
        }
        const editId = params.get('edit');
        if (editId) {
            const row = timesheets.data.find((r) => String(r.id) === editId);
            if (row) setEditing(row);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    const rows = timesheets.data;
    const submittedCount = tabCounts.submitted ?? 0;

    function switchTab(next: string) {
        setTab(next);
        router.get(
            '/operations/timesheets',
            { ...filters, tab: next, search: search || undefined },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    function submitSearch() {
        router.get(
            '/operations/timesheets',
            { ...filters, tab, search: search || undefined },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    // Week stepper — writes the existing from/to filters (one source of
    // truth); the default view stays unfiltered so the approval queue never
    // week-hides unless the user explicitly steps into a week.
    const weekFilterActive = Boolean(
        filters.from &&
            filters.to &&
            weekStartFor(filters.from) === filters.from &&
            addDaysIso(filters.from, 6) === filters.to,
    );

    function gotoWeek(iso: string) {
        const start = weekStartFor(iso);
        router.get(
            '/operations/timesheets',
            { ...filters, tab, search: search || undefined, from: start, to: addDaysIso(start, 6) },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    function clearWeek() {
        router.get(
            '/operations/timesheets',
            { ...filters, tab, search: search || undefined, from: undefined, to: undefined },
            { preserveScroll: true, preserveState: true, replace: true },
        );
    }

    function handleAction(id: string, row: TimesheetRow) {
        switch (id) {
            case 'view':
                setViewing(row);
                return;
            case 'edit':
                setEditing(row);
                return;
            case 'shift':
                if (row.shift) router.visit(`/operations/shifts/${row.shift.id}`);
                return;
            case 'client':
                if (row.client) router.visit(`/operations/clients/${row.client.id}`);
                return;
            case 'staff':
                if (row.staff) router.visit(`/hr/people/${row.staff.id}`);
                return;
            case 'submit':
                router.post(`/operations/timesheets/${row.id}/submit`, {}, { preserveScroll: true });
                return;
            case 'approve':
                router.post(`/operations/timesheets/${row.id}/approve`, {}, { preserveScroll: true });
                return;
            case 'return':
                setReasonTarget({ action: 'return', row });
                return;
            case 'reject':
                setReasonTarget({ action: 'reject', row });
                return;
            case 'archive':
                router.post(`/operations/timesheets/${row.id}/archive`, {}, { preserveScroll: true });
                return;
            case 'restore':
                router.post(`/operations/timesheets/${row.id}/restore`, {}, { preserveScroll: true });
                return;
            case 'copy': {
                const url = `${window.location.origin}/operations/timesheets?view=${row.id}`;
                navigator.clipboard?.writeText(url);
                return;
            }
            default:
                return;
        }
    }

    return (
        <AppLayout breadcrumbs={[{ title: isOwnOnlyView ? 'My timesheets' : 'Timesheets', href: '/operations/timesheets' }]}>
            <Head title={isOwnOnlyView ? 'My Timesheets' : 'Timesheets'} />

            <PageShell>
                <TimesheetsHero
                    summary={heroSummary}
                    canCreate={canCreate}
                    sitesCount={sites?.length ?? heroSummary.sites_count}
                    staffCount={staff?.length ?? 0}
                    onCreateTimesheet={() => {
                        setInitialShiftId(null);
                        setCreateOpen(true);
                    }}
                    onPrevWeek={() => gotoWeek(addDaysIso(heroSummary.week_start, -7))}
                    onNextWeek={() => gotoWeek(addDaysIso(heroSummary.week_start, 7))}
                    onPickWeek={(d) => gotoWeek(toLocalIsoDate(d))}
                    onClearWeek={weekFilterActive ? clearWeek : undefined}
                    weekFilterActive={weekFilterActive}
                />

                {/* KPI strip */}
                <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <OpsStatCard label="Awaiting approval" value={heroSummary.timesheets_submitted} icon={ClipboardCheck} color="amber" />
                    <OpsStatCard label="Returned to staff" value={heroSummary.timesheets_returned} icon={AlertTriangle} color="red" />
                    <OpsStatCard label="Approved this week" value={heroSummary.timesheets_approved} icon={Send} color="emerald" />
                    <OpsStatCard label="Hours logged" value={`${heroSummary.hours_this_week}h`} icon={DollarSign} color="indigo" />
                </div>

                {/* Table */}
                <section className="rounded-2xl border border-border bg-card shadow-sm">
                    {/* Tab strip */}
                    <div className="flex flex-wrap items-center justify-between gap-2 border-b border-border px-4 py-2">
                        <TabStrip
                            value={tab}
                            onChange={switchTab}
                            ariaLabel="Timesheet status"
                            className="border-0 bg-transparent p-0 shadow-none"
                            items={TABS.map((tabDef) => ({
                                id: tabDef.key,
                                label: tabDef.label,
                                icon: tabDef.icon,
                                tone: tabDef.tone,
                                badge: tabCounts[tabDef.key] ?? 0,
                            }))}
                        />
                        <div className="flex items-center gap-2">
                            <div className="relative">
                                <Search className="absolute left-2.5 top-1/2 h-3.5 w-3.5 -translate-y-1/2 text-muted-foreground" />
                                <Input
                                    value={search}
                                    onChange={(e) => setSearch(e.target.value)}
                                    onKeyDown={(e) => {
                                        if (e.key === 'Enter') submitSearch();
                                    }}
                                    placeholder="Search timesheets…"
                                    className="h-8 w-56 pl-8 text-xs"
                                />
                            </div>
                            <Button variant="outline" size="sm" className="gap-1.5 text-xs" onClick={submitSearch}>
                                <Filter className="h-3.5 w-3.5" /> Search
                            </Button>
                            {submittedCount > 0 ? (
                                <Button size="sm" className="gap-1.5 text-xs" onClick={() => switchTab('submitted')}>
                                    Review {submittedCount} pending
                                </Button>
                            ) : null}
                        </div>
                    </div>

                    {/* Table */}
                    <div className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 text-[11.5px] uppercase tracking-wider text-muted-foreground">
                                <tr className="text-left">
                                    <th className="w-10 py-2.5 pl-4">
                                        <input type="checkbox" aria-label="Select all" />
                                    </th>
                                    <th className="py-2.5 px-2">Date</th>
                                    {!isOwnOnlyView ? <th className="py-2.5 px-2">Staff</th> : null}
                                    <th className="py-2.5 px-2">Client &amp; site</th>
                                    <th className="py-2.5 px-2">Shift / activity</th>
                                    <th className="py-2.5 px-2">Hours</th>
                                    <th className="py-2.5 px-2">Tasks</th>
                                    <th className="py-2.5 px-2">Status</th>
                                    <th className="py-2.5 pr-4 text-right">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((t) => {
                                    const hours = (t.total_hours ?? t.hours ?? 0) as number;
                                    const taskPct = (t.tasks_total ?? 0) > 0 ? Math.round(((t.tasks_completed ?? 0) / (t.tasks_total ?? 1)) * 100) : 0;
                                    return (
                                        <tr
                                            key={t.id}
                                            className="cursor-pointer border-t border-border transition-colors hover:bg-muted/30"
                                            onClick={() => setViewing(t)}
                                            onContextMenu={(e) => {
                                                e.preventDefault();
                                                setMenu({ x: e.clientX, y: e.clientY, row: t });
                                            }}
                                            onMouseEnter={(e) => {
                                                const rect = e.currentTarget.getBoundingClientRect();
                                                if (hoverTimer.current) window.clearTimeout(hoverTimer.current);
                                                hoverTimer.current = window.setTimeout(() => setHover({ row: t, rect }), 350);
                                            }}
                                            onMouseLeave={() => {
                                                if (hoverTimer.current) window.clearTimeout(hoverTimer.current);
                                                setHover(null);
                                            }}
                                        >
                                            <td className="py-3 pl-4" onClick={(e) => e.stopPropagation()}>
                                                <input type="checkbox" />
                                            </td>
                                            <td className="py-3 px-2">
                                                <div className="font-semibold">{fmtDate(t.work_date)}</div>
                                                <div className="text-[11px] tabular-nums text-muted-foreground">
                                                    {fmtTime(t.starts_at)} – {fmtTime(t.ends_at)}
                                                </div>
                                            </td>
                                            {!isOwnOnlyView ? (
                                                <td className="py-3 px-2">
                                                    <div className="flex items-center gap-2">
                                                        <div
                                                            className="grid h-7 w-7 shrink-0 place-items-center rounded-full text-[11px] font-semibold text-white"
                                                            style={{ background: `oklch(0.55 0.14 ${hueFor(t.staff?.name)})` }}
                                                        >
                                                            {initials(t.staff?.name)}
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="truncate font-medium">{t.staff?.name ?? '—'}</div>
                                                            <div className="text-[11px] text-muted-foreground">#{t.staff?.id}</div>
                                                        </div>
                                                    </div>
                                                </td>
                                            ) : null}
                                            <td className="py-3 px-2">
                                                <div className="font-medium">
                                                    {t.client ? `${t.client.first_name} ${t.client.last_name}` : (
                                                        <span className="text-muted-foreground italic">{t.activity_type ?? 'Manual entry'}</span>
                                                    )}
                                                </div>
                                                {t.shift?.location || t.site?.name ? (
                                                    <div className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                                        <MapPin className="h-3 w-3" />
                                                        {t.shift?.location ?? t.site?.name}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="py-3 px-2">
                                                <div className="text-[12px] capitalize">
                                                    {t.shift
                                                        ? (t.shift.shift_type ?? 'standard').replace('_', ' ')
                                                        : t.activity_type ?? 'manual'}
                                                </div>
                                                <div className="text-[11px] text-muted-foreground">
                                                    {typeof t.shift?.service_context === 'string'
                                                        ? t.shift?.service_context
                                                        : t.shift?.service_context?.name ?? ''}
                                                </div>
                                            </td>
                                            <td className="py-3 px-2">
                                                <div className="font-semibold tabular-nums">{hours.toFixed(2)}h</div>
                                                <div className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                                    <Coffee className="h-3 w-3" />
                                                    {t.break_minutes}m
                                                    {(t.mileage_km ?? 0) > 0 ? (
                                                        <>
                                                            <span>·</span>
                                                            <Car className="h-3 w-3" />
                                                            {t.mileage_km}km
                                                        </>
                                                    ) : null}
                                                    {t.sleepover ? (
                                                        <>
                                                            <span>·</span>
                                                            <Moon className="h-3 w-3" />
                                                            sleepover
                                                        </>
                                                    ) : null}
                                                </div>
                                            </td>
                                            <td className="py-3 px-2 w-[120px]">
                                                {(t.tasks_total ?? 0) > 0 ? (
                                                    <div className="flex items-center gap-1.5">
                                                        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                                            <div
                                                                className={cn('h-full rounded-full', taskPct === 100 ? 'bg-status-success' : 'bg-primary')}
                                                                style={{ width: taskPct + '%' }}
                                                            />
                                                        </div>
                                                        <span className="w-9 text-right text-[11px] tabular-nums text-muted-foreground">
                                                            {t.tasks_completed}/{t.tasks_total}
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-[11px] text-muted-foreground/60">—</span>
                                                )}
                                            </td>
                                            <td className="py-3 px-2">
                                                <TimesheetStatusBadge status={t.status} />
                                            </td>
                                            <td className="py-3 pr-4 text-right" onClick={(e) => e.stopPropagation()}>
                                                <div className="inline-flex items-center gap-1">
                                                    <button
                                                        onClick={() => setViewing(t)}
                                                        aria-label="View timesheet"
                                                        title="View timesheet"
                                                        className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                    </button>
                                                    <button
                                                        onClick={(e) => {
                                                            const r = e.currentTarget.getBoundingClientRect();
                                                            setMenu({ x: r.right, y: r.bottom, row: t });
                                                        }}
                                                        aria-label="Row actions"
                                                        title="More actions"
                                                        className="grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted"
                                                    >
                                                        <MoreHorizontal className="h-3.5 w-3.5" />
                                                    </button>
                                                </div>
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                        {rows.length === 0 ? (
                            <div className="grid place-items-center px-6 py-14 text-center text-muted-foreground">
                                    <Sun className="mb-2 h-8 w-8 text-status-warning" />
                                <div className="text-sm font-medium text-foreground">No timesheets in this tab</div>
                                <div className="text-xs">Try switching to another status or clear filters.</div>
                            </div>
                        ) : null}
                    </div>

                    {/* Pagination footer */}
                    <div className="flex items-center justify-between border-t border-border px-4 py-2.5 text-xs text-muted-foreground">
                        <span>
                            Showing <span className="font-medium text-foreground">{rows.length}</span> of{' '}
                            <span className="font-medium text-foreground">{tabCounts[tab] ?? rows.length}</span> timesheets ·{' '}
                            <span>right-click any row for actions</span>
                        </span>
                    </div>

                    <ContextMenu menu={menu} onClose={() => setMenu(null)} onAction={handleAction} />
                    <HoverPopover hover={hover} />
                </section>
            </PageShell>

            <ViewTimesheetDialog
                open={!!viewing}
                timesheet={viewing}
                onOpenChange={(o) => !o && setViewing(null)}
                canApprove={canApprove}
            />
            <EditTimesheetDialog
                open={!!editing}
                timesheet={editing as EditTimesheetRow | null}
                onOpenChange={(o) => !o && setEditing(null)}
                clients={clients}
            />
            <CreateTimesheetDialog
                open={createOpen}
                onOpenChange={setCreateOpen}
                shifts={availableShifts}
                clients={clients}
                sites={sites}
                initialShiftId={initialShiftId}
            />
            <ReasonDialog
                open={reasonTarget !== null}
                onClose={() => setReasonTarget(null)}
                title={reasonTarget?.action === 'reject' ? 'Reject timesheet?' : 'Return for changes?'}
                description={
                    reasonTarget?.action === 'reject'
                        ? 'The staff member will see this timesheet as rejected, with your reason.'
                        : 'The timesheet goes back to the staff member to fix and resubmit.'
                }
                label={reasonTarget?.action === 'reject' ? 'Reason for rejection' : 'What needs changing?'}
                confirmLabel={reasonTarget?.action === 'reject' ? 'Reject timesheet' : 'Return to staff'}
                destructive={reasonTarget?.action === 'reject'}
                onConfirm={(reason) => {
                    if (!reasonTarget) return;
                    const { action, row } = reasonTarget;
                    router.post(
                        `/operations/timesheets/${row.id}/${action}`,
                        action === 'reject' ? { decision_notes: reason } : { returned_notes: reason },
                        { preserveScroll: true },
                    );
                    setReasonTarget(null);
                }}
            />
        </AppLayout>
    );
}
