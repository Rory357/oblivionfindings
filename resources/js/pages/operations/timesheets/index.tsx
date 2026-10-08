import { PageHeaderRail } from '@/components/page';
import PageShell from '@/components/page-shell';
import type { RosterTabItem } from '@/components/rostering/tab-strip';
import { TimesheetStatusBadge } from '@/components/timesheet-status-badge';
import CreateTimesheetDialog, {
    type ClientOption,
    type ShiftOption,
    type SiteOption,
} from '@/components/timesheets/create-timesheet-dialog';
import EditTimesheetDialog, {
    type EditTimesheetRow,
} from '@/components/timesheets/edit-timesheet-dialog';
import {
    TimesheetActionDialog,
    type TimesheetReviewAction,
} from '@/components/timesheets/timesheet-action-dialog';
import { timesheetEditContext } from '@/components/timesheets/timesheet-wizard';
import TimesheetsHero, {
    type TimesheetsHeroSummary,
} from '@/components/timesheets/timesheets-hero';
import {
    useTimesheetFilters,
    type TimesheetFilters,
} from '@/components/timesheets/use-timesheet-filters';
import ViewTimesheetDialog, {
    type ViewTimesheetRow,
} from '@/components/timesheets/view-timesheet-dialog';
import { Button } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/app-layout';
import { formatDateOnly, WORKER_TIMEZONE } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Head, router } from '@inertiajs/react';
import type { LucideIcon } from 'lucide-react';
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
    Eye,
    Link2,
    ListChecks,
    MapPin,
    Moon,
    MoreHorizontal,
    Pencil,
    RotateCcw,
    Send,
    Sun,
    User,
    Users,
    XCircle,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

// ─────────────────────────────────────────────────────────────────────
// Types
// ─────────────────────────────────────────────────────────────────────
export type TimesheetRow = ViewTimesheetRow & {
    hours?: number;
    total_hours?: number;
    staff_employee_profile_id?: number | null;
    staff_profile_url?: string | null;
};

type TabCounts = Record<string, number>;

type Props = {
    timesheets: { data: TimesheetRow[] };
    filters: TimesheetFilters & { view?: number | null; edit?: number | null };
    pagination: {
        current_page: number;
        last_page: number;
        total: number;
        from: number | null;
        to: number | null;
    };
    lists: { timesheets: { includes_detail_record: boolean; shown: number } };
    evidence: { scope: string; checked_at: string; timezone: string };
    tabCounts: TabCounts;
    heroSummary: TimesheetsHeroSummary;
    isOwnOnlyView: boolean;
    clients: ClientOption[];
    sites: SiteOption[];
    staff: Array<{ id: number; name: string }>;
    availableShifts: ShiftOption[];
    canApprove: boolean;
    canCreate: boolean;
    canSubmit?: boolean;
};

const TABS: Array<{
    key: string;
    label: string;
    icon: RosterTabItem['icon'];
    tone: RosterTabItem['tone'];
}> = [
    { key: 'all', label: 'All', icon: ListChecks, tone: 'primary' },
    { key: 'draft', label: 'Drafts', icon: Pencil, tone: 'info' },
    {
        key: 'submitted',
        label: 'Awaiting approval',
        icon: ClipboardCheck,
        tone: 'warning',
    },
    { key: 'returned', label: 'Returned', icon: RotateCcw, tone: 'critical' },
    { key: 'approved', label: 'Approved', icon: CheckCircle2, tone: 'success' },
    { key: 'rejected', label: 'Rejected', icon: XCircle, tone: 'critical' },
    { key: 'paid', label: 'Paid', icon: Banknote, tone: 'success' },
    { key: 'archived', label: 'Archive', icon: Archive, tone: 'primary' },
];

// Preserved export — index.test.ts references this constant.
export const needsApprovalBadgeClassName =
    'border-status-warning/30 bg-status-warning-bg text-[10px] text-status-warning';

export function canEditTimesheetRow(
    row: Pick<
        TimesheetRow,
        'can_edit' | 'can_update' | 'attendance_session_id'
    >,
): boolean {
    return Boolean(
        row.can_edit &&
        row.can_update === true &&
        row.attendance_session_id == null,
    );
}

export function formatTimesheetTime(iso: string, timezone = WORKER_TIMEZONE) {
    const date = new Date(iso);
    if (!iso || Number.isNaN(date.getTime())) return '—';
    return new Intl.DateTimeFormat('en-NZ', {
        timeZone: timezone,
        hour: 'numeric',
        minute: '2-digit',
    }).format(date);
}
function hasLinkedShift(row: TimesheetRow): boolean {
    return (row.shift_id ?? row.shift?.id ?? null) !== null;
}
function recordedActivityCount(row: TimesheetRow): number {
    return hasLinkedShift(row) ? 0 : (row.activity_items?.length ?? 0);
}

function fmtDate(iso: string) {
    return formatDateOnly(iso?.slice(0, 10));
}
function initials(name?: string | null) {
    if (!name) return '?';
    return name
        .split(' ')
        .map((w) => w[0])
        .slice(0, 2)
        .join('')
        .toUpperCase();
}
function hueFor(name?: string | null) {
    if (!name) return 200;
    let h = 0;
    for (let i = 0; i < name.length; i++)
        h = (h * 31 + name.charCodeAt(i)) % 360;
    return h;
}

// ─────────────────────────────────────────────────────────────────────
// Hover popover — appears on row hover after ~350ms.
// ─────────────────────────────────────────────────────────────────────
function HoverPopover({
    hover,
    timezone,
}: {
    hover: { row: TimesheetRow; rect: DOMRect } | null;
    timezone: string;
}) {
    if (!hover) return null;
    const { row: t, rect } = hover;
    const W = 340;
    const margin = 12;
    const left =
        rect.right + margin + W > window.innerWidth
            ? Math.max(margin, rect.left - W - margin)
            : rect.right + margin;
    const top = Math.max(
        margin,
        Math.min(rect.top, window.innerHeight - 360 - margin),
    );
    const hours = (t.total_hours ?? t.hours ?? 0) as number;
    const taskPct =
        (t.tasks_total ?? 0) > 0
            ? Math.round(
                  ((t.tasks_completed ?? 0) / (t.tasks_total ?? 1)) * 100,
              )
            : 0;
    const blurb: Record<string, string> = {
        draft: 'In progress — not yet submitted.',
        submitted: 'Awaiting manager decision.',
        returned: 'Returned to staff for changes.',
        approved: 'Approved · ready for payroll.',
        rejected: 'Rejected — see notes.',
        paid: 'Recorded as paid. Open the record for payment history.',
        archived: 'Archived from the active list.',
    };

    return (
        <div
            className="pointer-events-none fixed z-40"
            style={{ left, top, width: W }}
        >
            <GuardrailCard
                unstyled
                className="pointer-events-auto overflow-hidden rounded-xl border border-border bg-card shadow-2xl ring-1 ring-black/5"
            >
                <div className="flex items-center justify-between gap-2 border-b border-border px-3 py-2">
                    <div className="min-w-0">
                        <div className="text-[10.5px] tracking-wider text-muted-foreground uppercase">
                            Timesheet #{t.id}
                        </div>
                        <div className="truncate text-[13px] font-semibold">
                            {t.client
                                ? `${t.client.first_name} ${t.client.last_name}`
                                : (t.activity_type ?? 'Manual entry')}
                        </div>
                    </div>
                    <TimesheetStatusBadge status={t.status} />
                </div>
                <div className="space-y-2.5 px-3 py-3 text-xs">
                    <div className="text-[11.5px] text-muted-foreground italic">
                        {blurb[t.status] ?? ''}
                    </div>
                    <div className="grid grid-cols-3 gap-1.5">
                        <div className="rounded-md border border-border bg-muted/30 px-2 py-1.5">
                            <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                Hours
                            </div>
                            <div className="mt-0.5 text-[12.5px] font-semibold tabular-nums">
                                {hours.toFixed(2)}h
                            </div>
                        </div>
                        <div className="rounded-md border border-border bg-muted/30 px-2 py-1.5">
                            <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                Break
                            </div>
                            <div className="mt-0.5 text-[12.5px] font-semibold tabular-nums">
                                {t.break_minutes}m
                            </div>
                        </div>
                        <div className="rounded-md border border-border bg-muted/30 px-2 py-1.5">
                            <div className="text-[10px] tracking-wider text-muted-foreground uppercase">
                                Mileage
                            </div>
                            <div className="mt-0.5 text-[12.5px] font-semibold tabular-nums">
                                {(t.mileage_km ?? 0) > 0
                                    ? `${t.mileage_km}km`
                                    : '—'}
                            </div>
                        </div>
                    </div>
                    <div className="flex items-center gap-2 rounded-md bg-muted/40 px-2 py-1.5 text-[11.5px] text-muted-foreground">
                        <span className="tabular-nums">
                            {formatTimesheetTime(t.starts_at, timezone)} –{' '}
                            {formatTimesheetTime(t.ends_at, timezone)}
                        </span>
                        <span className="ml-auto">{fmtDate(t.work_date)}</span>
                    </div>
                    {t.shift ? (
                        <div className="rounded-md border border-border px-2 py-1.5">
                            <div className="flex items-center justify-between text-[11.5px]">
                                <span className="font-medium">
                                    Shift #{t.shift.id}
                                </span>
                                <span className="text-muted-foreground capitalize">
                                    {(t.shift.shift_type ?? 'standard').replace(
                                        '_',
                                        ' ',
                                    )}
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
                    {recordedActivityCount(t) > 0 ? (
                        <div className="text-[11.5px]">
                            Activity items: {recordedActivityCount(t)} recorded
                        </div>
                    ) : hasLinkedShift(t) && (t.tasks_total ?? 0) > 0 ? (
                        <div>
                            <div className="mb-1 flex items-center justify-between">
                                <span className="text-[11.5px] font-medium">
                                    Tasks pulled from shift
                                </span>
                                <span className="text-[11px] text-muted-foreground tabular-nums">
                                    {t.tasks_completed ?? 0}/
                                    {t.tasks_total ?? 0}
                                </span>
                            </div>
                            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                <div
                                    className={cn(
                                        'h-full rounded-full',
                                        taskPct === 100
                                            ? 'bg-status-success'
                                            : 'bg-primary',
                                    )}
                                    style={{ width: taskPct + '%' }}
                                />
                            </div>
                        </div>
                    ) : null}
                    <div className="rounded-md bg-muted/30 px-2 py-1.5 text-[11.5px] text-muted-foreground">
                        <div>
                            <span className="text-muted-foreground/70">
                                Worked by
                            </span>{' '}
                            <span className="font-medium">
                                {t.staff?.name ?? '—'}
                            </span>
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
            </GuardrailCard>
        </div>
    );
}

// ─────────────────────────────────────────────────────────────────────
// Right-click context menu — status-aware.
// ─────────────────────────────────────────────────────────────────────
type MenuItem = {
    id?: string;
    label?: string;
    icon?: LucideIcon;
    tone?: 'primary' | 'success' | 'warning' | 'danger';
    separator?: boolean;
};

export function menuItemsFor(t: TimesheetRow): MenuItem[] {
    const items: MenuItem[] = [
        { id: 'view', label: 'View timesheet', icon: Eye },
    ];
    if (t.shift)
        items.push({
            id: 'shift',
            label: `Open linked shift #${t.shift.id}`,
            icon: CalendarDays,
        });
    if (t.client)
        items.push({ id: 'client', label: 'Open client profile', icon: User });
    if (t.staff_profile_url)
        items.push({ id: 'staff', label: 'Open staff profile', icon: Users });
    if (t.can_mutate) {
        if (['draft', 'returned'].includes(t.status) && canEditTimesheetRow(t))
            items.push({
                id: 'edit',
                label: 'Edit hours & breaks',
                icon: Pencil,
            });
        if (['draft', 'returned'].includes(t.status) && t.can_submit === true)
            items.push({
                id: 'submit',
                label: 'Submit for approval',
                icon: Send,
            });
        if (t.status === 'submitted') {
            if (t.can_approve)
                items.push({
                    id: 'approve',
                    label: 'Approve',
                    icon: CheckCircle2,
                    tone: 'success',
                });
            if (t.can_return)
                items.push({
                    id: 'return',
                    label: 'Return for changes',
                    icon: RotateCcw,
                    tone: 'warning',
                });
            if (t.can_reject)
                items.push({
                    id: 'reject',
                    label: 'Reject',
                    icon: XCircle,
                    tone: 'danger',
                });
        }
        if (!t.archived_at && ['paid', 'rejected'].includes(t.status))
            items.push({
                id: 'archive',
                label: 'Archive timesheet',
                icon: Archive,
            });
        if (t.archived_at || t.status === 'archived')
            items.push({
                id: 'restore',
                label: 'Restore to active list',
                icon: ArchiveRestore,
            });
    }
    items.push({ id: 'copy', label: 'Copy timesheet link', icon: Link2 });
    return items;
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
            if (ref.current && !ref.current.contains(e.target as Node))
                onClose();
        };
        const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose();
        if (menu)
            ref.current
                ?.querySelector<HTMLButtonElement>('[role=menuitem]')
                ?.focus();
        document.addEventListener('mousedown', onAway);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onAway);
            document.removeEventListener('keydown', onKey);
        };
    }, [onClose, menu]);

    if (!menu) return null;
    const { x, y, row } = menu;
    const items = menuItemsFor(row);
    const W = 260;
    const H = Math.min(440, 36 * items.length + 56);
    const left = Math.max(8, Math.min(x, window.innerWidth - W - 8));
    const top = Math.max(8, Math.min(y, window.innerHeight - H - 8));

    const toneCls: Record<string, string> = {
        primary: 'text-foreground',
        success: 'text-status-success hover:bg-status-success-bg',
        warning: 'text-status-warning hover:bg-status-warning-bg',
        danger: 'text-status-critical hover:bg-status-critical-bg',
    };

    return (
        <GuardrailCard
            unstyled
            ref={ref}
            role="menu"
            aria-label={`Timesheet ${row.id} actions`}
            onKeyDown={(event) => {
                const buttons = Array.from(
                    ref.current?.querySelectorAll<HTMLButtonElement>(
                        '[role=menuitem]',
                    ) ?? [],
                );
                const current = buttons.indexOf(
                    document.activeElement as HTMLButtonElement,
                );
                const index =
                    event.key === 'Home'
                        ? 0
                        : event.key === 'End'
                          ? buttons.length - 1
                          : event.key === 'ArrowDown'
                            ? (current + 1) % buttons.length
                            : event.key === 'ArrowUp'
                              ? (current + buttons.length - 1) % buttons.length
                              : -1;
                if (index >= 0) {
                    event.preventDefault();
                    buttons[index]?.focus();
                }
            }}
            className="fixed z-[60] w-[260px] overflow-hidden rounded-xl border border-border bg-card py-1.5 shadow-2xl ring-1 ring-black/5"
            style={{ left, top }}
        >
            <div className="flex items-center justify-between gap-2 px-3 py-1.5">
                <div className="min-w-0">
                    <div className="truncate text-[11.5px] font-semibold">
                        #{row.id} ·{' '}
                        {row.client
                            ? `${row.client.first_name} ${row.client.last_name}`
                            : (row.activity_type ?? 'Manual')}
                    </div>
                    <div className="text-[10.5px] text-muted-foreground">
                        {row.staff?.name ?? 'Staff'} · {fmtDate(row.work_date)}
                    </div>
                </div>
                <TimesheetStatusBadge status={row.status} />
            </div>
            <div className="my-1 h-px bg-border" />
            {items.map((it, i) => {
                if (it.separator)
                    return (
                        <div key={'s' + i} className="my-1 h-px bg-border" />
                    );
                const Ic = it.icon;
                return (
                    <Button
                        unstyled
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
                    </Button>
                );
            })}
        </GuardrailCard>
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
    pagination,
    lists,
    evidence,
    tabCounts,
    heroSummary,
    isOwnOnlyView,
    clients,
    sites,
    staff,
    availableShifts,
    canApprove,
    canCreate,
    canSubmit = false,
}: Props) {
    const reads = useTimesheetFilters({
        tab: filters.tab ?? 'all',
        from: filters.from ?? null,
        to: filters.to ?? null,
        client_id: filters.client_id ?? null,
        staff_id: filters.staff_id ?? null,
        search: filters.search ?? '',
        page: filters.page ?? 1,
    });
    const tab = filters.tab ?? 'all';
    const [menu, setMenu] = useState<{
        x: number;
        y: number;
        row: TimesheetRow;
    } | null>(null);
    const [hover, setHover] = useState<{
        row: TimesheetRow;
        rect: DOMRect;
    } | null>(null);
    const [viewing, setViewing] = useState<TimesheetRow | null>(null);
    const [editing, setEditing] = useState<TimesheetRow | null>(null);
    const [reasonTarget, setReasonTarget] = useState<{
        action: TimesheetReviewAction;
        row: TimesheetRow;
    } | null>(null);
    const [createOpen, setCreateOpen] = useState(false);
    const [initialShiftId, setInitialShiftId] = useState<number | null>(null);
    const [selectedApprovalIds, setSelectedApprovalIds] = useState<number[]>(
        [],
    );
    const [approvalDecisionNotes, setApprovalDecisionNotes] = useState('');
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
            if (row && canEditTimesheetRow(row)) setEditing(row);
            else if (row) setViewing(row);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, []);

    // The legacy deep-link record is intentionally outside the filtered page.
    const rows = lists.timesheets.includes_detail_record
        ? timesheets.data.slice(1)
        : timesheets.data;
    const detailRecord = lists.timesheets.includes_detail_record
        ? timesheets.data[0]
        : null;
    const selectionKey = JSON.stringify(
        rows.map((row) => [row.id, row.status, row.can_approve]),
    );
    useEffect(() => {
        setSelectedApprovalIds([]);
        setMenu(null);
        setHover(null);
    }, [selectionKey]);
    useEffect(
        () => () => {
            if (hoverTimer.current) window.clearTimeout(hoverTimer.current);
        },
        [],
    );
    const selectableApprovalIds = rows
        .filter((row) => row.status === 'submitted' && row.can_approve)
        .map((row) => row.id);
    const allVisibleApprovalsSelected =
        selectableApprovalIds.length > 0 &&
        selectableApprovalIds.every((id) => selectedApprovalIds.includes(id));

    const currentViewed = timesheets.data.find((row) => row.id === viewing?.id);
    const currentEdited = timesheets.data.find((row) => row.id === editing?.id);
    const currentReason = timesheets.data.find(
        (row) => row.id === reasonTarget?.row.id,
    );
    const reasonAllowed = Boolean(
        currentReason?.can_mutate &&
        (reasonTarget?.action === 'submit'
            ? currentReason.can_submit === true
            : canApprove &&
              (reasonTarget?.action === 'approve'
                  ? currentReason.can_approve === true
                  : reasonTarget?.action === 'reject'
                    ? currentReason.can_reject === true
                    : currentReason.can_return === true)),
    );

    function toggleApprovalSelection(id: number, checked: boolean) {
        setSelectedApprovalIds((current) =>
            checked
                ? Array.from(new Set([...current, id]))
                : current.filter((selectedId) => selectedId !== id),
        );
    }

    function bulkApproveSelected() {
        if (selectedApprovalIds.length === 0) return;

        router.post(
            '/operations/timesheets/bulk-approve',
            {
                ids: selectedApprovalIds,
                decision_notes: approvalDecisionNotes || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => {
                    setSelectedApprovalIds([]);
                    setApprovalDecisionNotes('');
                },
            },
        );
    }

    function switchTab(next: string) {
        reads.change({ tab: next });
    }
    function gotoWeek(iso: string) {
        const start = weekStartFor(iso);
        reads.change({ from: start, to: addDaysIso(start, 6) });
    }
    function clearWeek() {
        reads.change({ from: null, to: null });
    }

    function handleAction(id: string, row: TimesheetRow) {
        if (
            !row.can_mutate &&
            [
                'edit',
                'submit',
                'approve',
                'return',
                'reject',
                'archive',
                'restore',
                'discard',
                'duplicate',
                'reassign',
                'reopen',
                'recreate',
                'correction',
            ].includes(id)
        ) {
            setViewing(row);
            return;
        }

        switch (id) {
            case 'view':
                setViewing(row);
                return;
            case 'edit':
                if (canEditTimesheetRow(row)) setEditing(row);
                else setViewing(row);
                return;
            case 'shift':
                if (row.shift)
                    router.visit(`/operations/shifts/${row.shift.id}`);
                return;
            case 'client':
                if (row.client)
                    router.visit(`/operations/clients/${row.client.id}`);
                return;
            case 'staff':
                if (row.staff_profile_url) router.visit(row.staff_profile_url);
                return;
            case 'submit':
            case 'approve':
            case 'return':
            case 'reject':
                setReasonTarget({ action: id, row });
                return;
            case 'archive':
                router.post(
                    `/operations/timesheets/${row.id}/archive`,
                    {},
                    { preserveScroll: true },
                );
                return;
            case 'restore':
                router.post(
                    `/operations/timesheets/${row.id}/restore`,
                    {},
                    { preserveScroll: true },
                );
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
        <AppLayout
            breadcrumbs={[
                { title: 'Home', href: '/dashboard' },
                {
                    title: isOwnOnlyView ? 'My timesheets' : 'Timesheets',
                    href: '/operations/timesheets',
                },
            ]}
        >
            <Head title={isOwnOnlyView ? 'My Timesheets' : 'Timesheets'} />

            <PageShell>
                <TimesheetsHero
                    summary={heroSummary}
                    counts={tabCounts}
                    filters={reads.draft}
                    clients={clients.map((client) => ({
                        id: client.id,
                        name: `${client.first_name} ${client.last_name}`,
                    }))}
                    staff={staff}
                    canCreate={canCreate}
                    canReviewAdjustments={canApprove}
                    ownOnly={isOwnOnlyView}
                    loading={reads.loading}
                    onCreateTimesheet={() => {
                        setInitialShiftId(null);
                        setCreateOpen(true);
                    }}
                    onSearch={reads.editSearch}
                    onSearchSubmit={() => reads.change()}
                    onChange={reads.change}
                    onClear={reads.clear}
                    onPrevWeek={() =>
                        gotoWeek(addDaysIso(heroSummary.week_start, -7))
                    }
                    onNextWeek={() =>
                        gotoWeek(addDaysIso(heroSummary.week_start, 7))
                    }
                    onPickWeek={(date) => gotoWeek(toLocalIsoDate(date))}
                    onClearWeek={clearWeek}
                    rangeLabel={`Showing ${pagination.from ?? 0}–${pagination.to ?? 0} of ${pagination.total} matching timesheets · Page ${pagination.current_page} of ${pagination.last_page}`}
                    notice={
                        reads.error
                            ? `${reads.error} The list still shows the previous successful results.`
                            : null
                    }
                    rail={
                        <fieldset
                            disabled={reads.loading}
                            className="w-full min-w-0"
                        >
                            <PageHeaderRail
                                value={tab}
                                onSelect={switchTab}
                                ariaLabel="Timesheet status"
                                items={TABS.map((item) => ({
                                    key: item.key,
                                    label: item.label,
                                    icon: item.icon,
                                    alert: ['submitted', 'returned'].includes(
                                        item.key,
                                    ),
                                    count: tabCounts[item.key] ?? 0,
                                }))}
                            />
                        </fieldset>
                    }
                />
                {reads.error && (
                    <Button
                        variant="outline"
                        disabled={reads.loading}
                        onClick={reads.retry}
                    >
                        Retry loading timesheets
                    </Button>
                )}
                <p className="text-subtle">
                    {evidence.scope === 'own_and_permitted_submitted_records'
                        ? 'This review queue includes your records and other staff’s submitted timesheets that you may review. Other status tabs keep their own permitted scope.'
                        : isOwnOnlyView
                          ? canApprove
                              ? 'Showing your records. The approval tab may include other submitted timesheets you are permitted to review.'
                              : 'Showing your records for permitted sites.'
                          : 'Showing records for sites you are permitted to view.'}{' '}
                    Status counts cover every matching page in each destination.
                </p>
                {detailRecord && (
                    <GuardrailCard className="flex flex-wrap items-center justify-between gap-2 p-3">
                        <p className="text-subtle">
                            Linked timesheet #{detailRecord.id} is outside these
                            filters.
                        </p>
                        <Button
                            variant="outline"
                            onClick={() => setViewing(detailRecord)}
                        >
                            Open linked timesheet
                        </Button>
                    </GuardrailCard>
                )}
                <section
                    aria-label="Timesheet records"
                    aria-busy={reads.loading}
                    className="rounded-2xl border border-border bg-card shadow-sm"
                >
                    {canApprove && selectedApprovalIds.length > 0 ? (
                        <div className="flex flex-wrap items-end gap-3 border-b border-border bg-status-warning-bg/40 px-4 py-3">
                            <div className="min-w-0 flex-1">
                                <label
                                    htmlFor="timesheet-approval-notes"
                                    className="mb-1 block text-xs font-semibold"
                                >
                                    Decision notes (optional)
                                </label>
                                <Input
                                    id="timesheet-approval-notes"
                                    value={approvalDecisionNotes}
                                    onChange={(event) =>
                                        setApprovalDecisionNotes(
                                            event.target.value,
                                        )
                                    }
                                    placeholder="Add one note for the selected timesheets"
                                    data-test="approvals-decision-notes"
                                />
                            </div>
                            <Button
                                type="button"
                                onClick={bulkApproveSelected}
                                data-test="approvals-bulk-approve"
                            >
                                <CheckCircle2 className="h-4 w-4" /> Approve{' '}
                                {selectedApprovalIds.length} selected
                            </Button>
                        </div>
                    ) : null}

                    <div className="space-y-3 p-3 md:hidden">
                        {rows.map((row) => (
                            <GuardrailCard
                                key={row.id}
                                className="space-y-3 p-3"
                                aria-label={`Timesheet ${row.id}`}
                            >
                                <div className="flex flex-wrap items-start justify-between gap-2">
                                    <div>
                                        <p className="font-semibold">
                                            {fmtDate(row.work_date)}
                                        </p>
                                        <p className="text-subtle">
                                            {formatTimesheetTime(
                                                row.starts_at,
                                                evidence.timezone,
                                            )}{' '}
                                            –{' '}
                                            {formatTimesheetTime(
                                                row.ends_at,
                                                evidence.timezone,
                                            )}
                                        </p>
                                    </div>
                                    <TimesheetStatusBadge status={row.status} />
                                </div>
                                {!isOwnOnlyView && (
                                    <p className="text-sm">
                                        {row.staff?.name ?? 'Staff unavailable'}
                                    </p>
                                )}
                                <p className="text-sm">
                                    {row.client
                                        ? `${row.client.first_name} ${row.client.last_name}`
                                        : (row.activity_type ?? 'Manual entry')}
                                    {row.shift?.location || row.site?.name
                                        ? ` · ${row.shift?.location ?? row.site?.name}`
                                        : ''}
                                </p>
                                <dl className="grid grid-cols-2 gap-2 text-sm">
                                    <div>
                                        <dt className="text-subtle">
                                            Recorded hours
                                        </dt>
                                        <dd>
                                            {Number(
                                                row.total_hours ??
                                                    row.hours ??
                                                    0,
                                            ).toFixed(2)}
                                            h
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-subtle">Break</dt>
                                        <dd>{row.break_minutes} min</dd>
                                    </div>
                                </dl>
                                <p className="text-subtle">
                                    {row.shift?.shift_type?.replaceAll(
                                        '_',
                                        ' ',
                                    ) ??
                                        row.activity_type ??
                                        'Manual activity'}
                                    {Number(row.mileage_km ?? 0) > 0
                                        ? ` · ${row.mileage_km}km`
                                        : ''}
                                    {row.sleepover ? ' · Sleepover' : ''}
                                    {row.on_call ? ' · On call' : ''}
                                </p>
                                {recordedActivityCount(row) > 0 ? (
                                    <p className="text-subtle">
                                        Activity items:{' '}
                                        {recordedActivityCount(row)} recorded
                                    </p>
                                ) : hasLinkedShift(row) &&
                                  (row.tasks_total ?? 0) > 0 ? (
                                    <p className="text-subtle">
                                        Recorded shift tasks:{' '}
                                        {row.tasks_completed ?? 0}/
                                        {row.tasks_total}
                                    </p>
                                ) : null}
                                {row.returned_notes &&
                                    row.status === 'returned' && (
                                        <p className="text-sm text-status-critical">
                                            Returned: {row.returned_notes}
                                        </p>
                                    )}
                                <div className="flex flex-wrap items-center gap-2">
                                    {canApprove &&
                                        row.status === 'submitted' &&
                                        row.can_approve && (
                                            <label className="frontline-tap flex items-center gap-2 text-sm">
                                                <input
                                                    type="checkbox"
                                                    checked={selectedApprovalIds.includes(
                                                        row.id,
                                                    )}
                                                    onChange={(event) =>
                                                        toggleApprovalSelection(
                                                            row.id,
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                />{' '}
                                                Select #{row.id}
                                            </label>
                                        )}
                                    <Button
                                        variant="outline"
                                        onClick={() => setViewing(row)}
                                    >
                                        Open timesheet #{row.id}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        onClick={(event) => {
                                            const rect =
                                                event.currentTarget.getBoundingClientRect();
                                            setMenu({
                                                x: rect.left,
                                                y: rect.bottom,
                                                row,
                                            });
                                        }}
                                    >
                                        Actions for #{row.id}
                                    </Button>
                                </div>
                            </GuardrailCard>
                        ))}
                        {rows.length === 0 && (
                            <p className="text-subtle p-4">
                                No timesheets match these filters. Choose
                                another status or clear filters.
                            </p>
                        )}
                    </div>
                    {/* Desktop table keeps the full comparison columns. */}
                    <div className="hidden overflow-x-auto md:block">
                        <table className="w-full text-sm">
                            <thead className="bg-muted/40 text-[11.5px] tracking-wider text-muted-foreground uppercase">
                                <tr className="text-left">
                                    <th className="w-10 py-2.5 pl-4">
                                        <input
                                            type="checkbox"
                                            aria-label="Select all"
                                            checked={
                                                allVisibleApprovalsSelected
                                            }
                                            disabled={
                                                !canApprove ||
                                                selectableApprovalIds.length ===
                                                    0
                                            }
                                            onChange={(event) =>
                                                setSelectedApprovalIds(
                                                    event.target.checked
                                                        ? selectableApprovalIds
                                                        : [],
                                                )
                                            }
                                        />
                                    </th>
                                    <th className="px-2 py-2.5">Date</th>
                                    {!isOwnOnlyView ? (
                                        <th className="px-2 py-2.5">Staff</th>
                                    ) : null}
                                    <th className="px-2 py-2.5">
                                        Client &amp; site
                                    </th>
                                    <th className="px-2 py-2.5">
                                        Shift / activity
                                    </th>
                                    <th className="px-2 py-2.5">Hours</th>
                                    <th className="px-2 py-2.5">
                                        Tasks / activities
                                    </th>
                                    <th className="px-2 py-2.5">Status</th>
                                    <th className="py-2.5 pr-4 text-right">
                                        Actions
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                {rows.map((t) => {
                                    const hours = (t.total_hours ??
                                        t.hours ??
                                        0) as number;
                                    const taskPct =
                                        (t.tasks_total ?? 0) > 0
                                            ? Math.round(
                                                  ((t.tasks_completed ?? 0) /
                                                      (t.tasks_total ?? 1)) *
                                                      100,
                                              )
                                            : 0;
                                    return (
                                        <tr
                                            key={t.id}
                                            data-test={
                                                t.status === 'submitted'
                                                    ? 'approvals-row'
                                                    : undefined
                                            }
                                            className="cursor-pointer border-t border-border transition-colors hover:bg-muted/30"
                                            onClick={() => setViewing(t)}
                                            onContextMenu={(e) => {
                                                e.preventDefault();
                                                setMenu({
                                                    x: e.clientX,
                                                    y: e.clientY,
                                                    row: t,
                                                });
                                            }}
                                            onMouseEnter={(e) => {
                                                const rect =
                                                    e.currentTarget.getBoundingClientRect();
                                                if (hoverTimer.current)
                                                    window.clearTimeout(
                                                        hoverTimer.current,
                                                    );
                                                hoverTimer.current =
                                                    window.setTimeout(
                                                        () =>
                                                            setHover({
                                                                row: t,
                                                                rect,
                                                            }),
                                                        350,
                                                    );
                                            }}
                                            onMouseLeave={() => {
                                                if (hoverTimer.current)
                                                    window.clearTimeout(
                                                        hoverTimer.current,
                                                    );
                                                setHover(null);
                                            }}
                                        >
                                            <td
                                                className="py-3 pl-4"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <input
                                                    type="checkbox"
                                                    aria-label={`Select timesheet ${t.id}`}
                                                    checked={selectedApprovalIds.includes(
                                                        t.id,
                                                    )}
                                                    disabled={
                                                        !canApprove ||
                                                        t.status !==
                                                            'submitted' ||
                                                        !t.can_approve
                                                    }
                                                    data-test={
                                                        t.status === 'submitted'
                                                            ? 'approvals-row-checkbox'
                                                            : undefined
                                                    }
                                                    onChange={(event) =>
                                                        toggleApprovalSelection(
                                                            t.id,
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                />
                                            </td>
                                            <td className="px-2 py-3">
                                                <div className="font-semibold">
                                                    {fmtDate(t.work_date)}
                                                </div>
                                                <div className="text-[11px] text-muted-foreground tabular-nums">
                                                    {formatTimesheetTime(
                                                        t.starts_at,
                                                        evidence.timezone,
                                                    )}{' '}
                                                    –{' '}
                                                    {formatTimesheetTime(
                                                        t.ends_at,
                                                        evidence.timezone,
                                                    )}
                                                </div>
                                            </td>
                                            {!isOwnOnlyView ? (
                                                <td className="px-2 py-3">
                                                    <div className="flex items-center gap-2">
                                                        <div
                                                            className="grid h-7 w-7 shrink-0 place-items-center rounded-full text-[11px] font-semibold text-white"
                                                            style={{
                                                                background: `oklch(0.55 0.14 ${hueFor(t.staff?.name)})`,
                                                            }}
                                                        >
                                                            {initials(
                                                                t.staff?.name,
                                                            )}
                                                        </div>
                                                        <div className="min-w-0">
                                                            <div className="truncate font-medium">
                                                                {t.staff
                                                                    ?.name ??
                                                                    '—'}
                                                            </div>
                                                            <div className="text-[11px] text-muted-foreground">
                                                                #{t.staff?.id}
                                                            </div>
                                                        </div>
                                                    </div>
                                                </td>
                                            ) : null}
                                            <td className="px-2 py-3">
                                                <div className="font-medium">
                                                    {t.client ? (
                                                        `${t.client.first_name} ${t.client.last_name}`
                                                    ) : (
                                                        <span className="text-muted-foreground italic">
                                                            {t.activity_type ??
                                                                'Manual entry'}
                                                        </span>
                                                    )}
                                                </div>
                                                {t.shift?.location ||
                                                t.site?.name ? (
                                                    <div className="inline-flex items-center gap-1 text-[11px] text-muted-foreground">
                                                        <MapPin className="h-3 w-3" />
                                                        {t.shift?.location ??
                                                            t.site?.name}
                                                    </div>
                                                ) : null}
                                            </td>
                                            <td className="px-2 py-3">
                                                <div className="text-[12px] capitalize">
                                                    {t.shift
                                                        ? (
                                                              t.shift
                                                                  .shift_type ??
                                                              'standard'
                                                          ).replace('_', ' ')
                                                        : (t.activity_type ??
                                                          'manual')}
                                                </div>
                                                <div className="text-[11px] text-muted-foreground">
                                                    {typeof t.shift
                                                        ?.service_context ===
                                                    'string'
                                                        ? t.shift
                                                              ?.service_context
                                                        : (t.shift
                                                              ?.service_context
                                                              ?.name ?? '')}
                                                </div>
                                            </td>
                                            <td className="px-2 py-3">
                                                <div className="font-semibold tabular-nums">
                                                    {hours.toFixed(2)}h
                                                </div>
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
                                            <td className="w-[120px] px-2 py-3">
                                                {recordedActivityCount(t) >
                                                0 ? (
                                                    <span className="text-[11px] text-muted-foreground">
                                                        {recordedActivityCount(
                                                            t,
                                                        )}{' '}
                                                        activity{' '}
                                                        {recordedActivityCount(
                                                            t,
                                                        ) === 1
                                                            ? 'item'
                                                            : 'items'}
                                                    </span>
                                                ) : hasLinkedShift(t) &&
                                                  (t.tasks_total ?? 0) > 0 ? (
                                                    <div className="flex items-center gap-1.5">
                                                        <div className="h-1.5 flex-1 overflow-hidden rounded-full bg-muted">
                                                            <div
                                                                className={cn(
                                                                    'h-full rounded-full',
                                                                    taskPct ===
                                                                        100
                                                                        ? 'bg-status-success'
                                                                        : 'bg-primary',
                                                                )}
                                                                style={{
                                                                    width:
                                                                        taskPct +
                                                                        '%',
                                                                }}
                                                            />
                                                        </div>
                                                        <span className="w-9 text-right text-[11px] text-muted-foreground tabular-nums">
                                                            {t.tasks_completed}/
                                                            {t.tasks_total}
                                                        </span>
                                                    </div>
                                                ) : (
                                                    <span className="text-[11px] text-muted-foreground/60">
                                                        —
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-2 py-3">
                                                <TimesheetStatusBadge
                                                    status={t.status}
                                                />
                                            </td>
                                            <td
                                                className="py-3 pr-4 text-right"
                                                onClick={(e) =>
                                                    e.stopPropagation()
                                                }
                                            >
                                                <div className="inline-flex items-center gap-1">
                                                    <Button
                                                        unstyled
                                                        onClick={() =>
                                                            setViewing(t)
                                                        }
                                                        aria-label="View timesheet"
                                                        title="View timesheet"
                                                        className="frontline-hit grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                                    >
                                                        <Eye className="h-3.5 w-3.5" />
                                                    </Button>
                                                    <Button
                                                        unstyled
                                                        onClick={(e) => {
                                                            const r =
                                                                e.currentTarget.getBoundingClientRect();
                                                            setMenu({
                                                                x: r.right,
                                                                y: r.bottom,
                                                                row: t,
                                                            });
                                                        }}
                                                        aria-label="Row actions"
                                                        title="More actions"
                                                        className="frontline-hit grid h-7 w-7 place-items-center rounded-md text-muted-foreground hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                                                    >
                                                        <MoreHorizontal className="h-3.5 w-3.5" />
                                                    </Button>
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
                                <div className="text-sm font-medium text-foreground">
                                    No timesheets in this tab
                                </div>
                                <div className="text-xs">
                                    Try switching to another status or clear
                                    filters.
                                </div>
                            </div>
                        ) : null}
                    </div>

                    <nav
                        aria-label="Timesheet pages"
                        className="text-subtle flex flex-wrap items-center justify-between gap-3 border-t border-border px-4 py-3"
                    >
                        <span>
                            Showing {pagination.from ?? 0}–{pagination.to ?? 0}{' '}
                            of {pagination.total} timesheets · Page{' '}
                            {pagination.current_page} of {pagination.last_page}
                        </span>
                        <div className="flex gap-2">
                            <Button
                                variant="outline"
                                disabled={
                                    reads.loading ||
                                    pagination.current_page <= 1
                                }
                                onClick={() =>
                                    reads.page(pagination.current_page - 1)
                                }
                            >
                                Previous page
                            </Button>
                            <Button
                                variant="outline"
                                disabled={
                                    reads.loading ||
                                    pagination.current_page >=
                                        pagination.last_page
                                }
                                onClick={() =>
                                    reads.page(pagination.current_page + 1)
                                }
                            >
                                Next page
                            </Button>
                        </div>
                    </nav>

                    <ContextMenu
                        menu={menu}
                        onClose={() => setMenu(null)}
                        onAction={handleAction}
                    />
                    <HoverPopover hover={hover} timezone={evidence.timezone} />
                </section>
            </PageShell>

            <ViewTimesheetDialog
                open={!!viewing}
                timesheet={currentViewed ?? viewing}
                onOpenChange={(o) => !o && setViewing(null)}
                canApprove={Boolean(
                    canApprove &&
                    currentViewed?.can_approve &&
                    currentViewed.can_mutate &&
                    currentViewed.status === 'submitted',
                )}
                canSubmit={Boolean(currentViewed?.can_submit === true)}
            />
            <EditTimesheetDialog
                open={!!editing}
                timesheet={editing as EditTimesheetRow | null}
                canEdit={Boolean(
                    currentEdited &&
                    canEditTimesheetRow(currentEdited) &&
                    timesheetEditContext(editing) ===
                        timesheetEditContext(currentEdited) &&
                    ['draft', 'returned'].includes(currentEdited.status),
                )}
                onOpenChange={(o) => !o && setEditing(null)}
                clients={clients}
                workerTimezone={evidence.timezone}
                canSubmit={currentEdited?.can_resubmit === true}
            />
            <CreateTimesheetDialog
                open={createOpen}
                onOpenChange={setCreateOpen}
                shifts={availableShifts}
                clients={clients}
                sites={sites}
                initialShiftId={initialShiftId}
                canCreate={canCreate}
                canSubmit={canSubmit}
                workerTimezone={evidence.timezone}
            />
            {reasonTarget && (
                <TimesheetActionDialog
                    open
                    action={reasonTarget.action}
                    record={currentReason ?? reasonTarget.row}
                    canAct={reasonAllowed}
                    onOpenChange={(open) => !open && setReasonTarget(null)}
                />
            )}
        </AppLayout>
    );
}
