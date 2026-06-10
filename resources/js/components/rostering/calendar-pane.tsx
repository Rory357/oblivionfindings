import { Link, router, usePage } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarDays,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ChevronsLeft,
    ChevronsRight,
    Clock,
    Edit3,
    List,
    MapPin,
    MoreHorizontal,
    Plus,
    Repeat,
    Users,
} from 'lucide-react';
import { useCallback, useEffect, useLayoutEffect, useMemo, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { toast } from 'sonner';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { useCreateShiftLauncher } from '@/pages/operations/shifts/components/use-create-shift-launcher';

import {
    CAL_MONTHS,
    CAL_WEEKDAYS,
    type CalendarGap,
    type CalendarShift,
    calendarStatusMeta,
    fmtHours,
    monthMatrix,
    parseDateKey,
    ymdKey,
} from './calendar-shared';
import { DayDetailDialog } from './day-detail-dialog';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from './shift-context-menu';
import {
    buildShiftActions,
    type GridConflictPeer,
    type GridShift,
    type GridShiftStatus,
    type ShiftActionCallbacks,
} from './week-grid-pane';

/**
 * Rostering → Calendar tab: a month grid over the same Shift data as the
 * Planner, fetched from operations.rostering.calendar.events. Day cells open
 * the Day Detail Dialog; chips support peek, right-click quick actions and
 * drag-to-move; every action routes through the SAME page-level handlers and
 * dialogs the Planner tab uses (assign, unassign, cancel, duplicate, edit via
 * the shared CreateShiftDialog, …).
 */

type RawCalendarEvent = {
    id: number | string;
    title?: string;
    start: string | null;
    end: string | null;
    extendedProps?: Record<string, any>;
};

export type CalendarPaneProps = ShiftActionCallbacks & {
    canManageAny: boolean;
    /**
     * The rostering workspace's hero-banner filters (staff/client/site). The
     * calendar deliberately has no filter controls of its own — the hero is
     * the single source of truth, so the month obeys the same scoping as
     * every other tab. staff_id/client_id are forwarded to the events
     * endpoint; site_ids are applied client-side (the feed carries site_id
     * per shift but the endpoint has no site param).
     */
    filters?: {
        staff_id?: number | null;
        client_id?: number | null;
        site_ids?: number[];
    };
    onCreateShift?: (ctx?: { day?: Date }) => void;
};

function getCsrfToken() {
    return (
        document.querySelector(
            'meta[name="csrf-token"]',
        ) as HTMLMetaElement | null
    )?.content;
}

async function jsonRequest<T>(
    url: string,
    opts: { method: string; body?: unknown },
): Promise<T> {
    const token = getCsrfToken();
    const res = await fetch(url, {
        method: opts.method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    if (!res.ok) {
        let message = `Request failed (${res.status})`;
        try {
            const data = await res.json();
            message =
                data?.message ||
                (Object.values(data?.errors ?? {}) as string[][])?.flat?.()?.[0] ||
                message;
        } catch {
            // keep the generic message
        }
        throw new Error(message);
    }
    return res.json();
}

function toLocalDatetimeInput(iso: string): string {
    const d = new Date(iso);
    const p = (n: number) => String(n).padStart(2, '0');
    return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`;
}

function mapShift(ev: RawCalendarEvent): CalendarShift | null {
    const ext = ev.extendedProps ?? {};
    if (ext.event_type === 'coverage_gap') return null;
    if (!ev.start || !ev.end) return null;
    const start = new Date(ev.start);
    const end = new Date(ev.end);
    if (Number.isNaN(start.getTime()) || Number.isNaN(end.getTime())) {
        return null;
    }

    let status = (ext.status ?? 'scheduled') as GridShiftStatus;
    if (ext.is_open_shift && status === 'scheduled') status = 'open';
    const staffName: string | null = ext.user_id ? (ext.staff ?? null) : null;

    const fmt = (d: Date) =>
        d.toLocaleTimeString(undefined, {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        });

    return {
        id: Number(ev.id),
        status,
        starts_at: ev.start,
        ends_at: ev.end,
        client: ext.client ?? null,
        staff: staffName,
        dateKey: ymdKey(start),
        start: fmt(start),
        end: fmt(end),
        durationH: Math.max(0, (end.getTime() - start.getTime()) / 36e5),
        clientId: ext.client_id ?? null,
        siteId: ext.site_id ?? null,
        siteName: ext.site_name ?? null,
        context: ext.service_context ?? null,
        shiftType: String(ext.shift_type ?? 'standard').replace('_', ' '),
        staffId: ext.user_id ?? null,
        recurring: !!ext.is_recurring,
        replacement: !!ext.has_active_replacement,
        tasksTotal: Number(ext.tasks_total ?? 0),
        tasksDone: Number(ext.tasks_completed ?? 0),
        incidents: Number(ext.incidents_count ?? 0),
        isRespite: !!ext.is_respite,
    };
}

function mapGap(ev: RawCalendarEvent): CalendarGap | null {
    const ext = ev.extendedProps ?? {};
    if (ext.event_type !== 'coverage_gap' || !ev.start || !ev.end) return null;
    const start = new Date(ev.start);
    if (Number.isNaN(start.getTime())) return null;
    return {
        key: String(ev.id),
        dateKey: ymdKey(start),
        startsAt: ev.start,
        endsAt: ev.end,
        siteId: ext.site_id ?? null,
        siteName: ext.site_name ?? null,
        ruleId: ext.coverage_rule_id ?? null,
        ruleName: ext.rule_name ?? null,
        windowLabel: ext.coverage_window_label ?? null,
        missingStaff: Number(ext.coverage_missing_staff ?? 0),
        requiredStaff: ext.coverage_required_staff ?? null,
        assignedStaff: ext.coverage_assigned_staff ?? null,
        preferredClientId: ext.coverage_preferred_client_id ?? null,
        recommendedFillAction: ext.coverage_recommended_fill_action ?? null,
        roleShortages: Array.isArray(ext.coverage_planned_role_shortages) &&
        ext.coverage_planned_role_shortages.length > 0
            ? ext.coverage_planned_role_shortages
            : (ext.coverage_role_shortages ?? []),
    };
}

/** Same-staff overlap hint (display only — the server blocks real conflicts
 *  at write time via ShiftConflictService). */
function annotateConflicts(shifts: CalendarShift[]): CalendarShift[] {
    const byStaff = new Map<number, CalendarShift[]>();
    for (const s of shifts) {
        if (!s.staffId) continue;
        if (s.status !== 'scheduled' && s.status !== 'in_progress') continue;
        if (!byStaff.has(s.staffId)) byStaff.set(s.staffId, []);
        byStaff.get(s.staffId)!.push(s);
    }
    const peers = new Map<number, GridConflictPeer[]>();
    for (const list of byStaff.values()) {
        list.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        for (let i = 0; i < list.length; i++) {
            for (let j = i + 1; j < list.length; j++) {
                const a = list[i];
                const b = list[j];
                if (b.starts_at >= a.ends_at) break;
                const peerOf = (x: CalendarShift): GridConflictPeer => ({
                    id: x.id,
                    status: x.status,
                    starts_at: x.starts_at,
                    ends_at: x.ends_at,
                    client: x.client,
                    staff: x.staff ?? null,
                });
                if (!peers.has(a.id)) peers.set(a.id, []);
                if (!peers.has(b.id)) peers.set(b.id, []);
                peers.get(a.id)!.push(peerOf(b));
                peers.get(b.id)!.push(peerOf(a));
            }
        }
    }
    if (peers.size === 0) return shifts;
    return shifts.map((s) =>
        peers.has(s.id)
            ? { ...s, conflict: true, conflictPeers: peers.get(s.id) }
            : s,
    );
}

const DRAGGABLE_STATUSES: GridShiftStatus[] = ['scheduled', 'draft', 'open'];

const LEGEND: Array<{ label: string; color: string }> = [
    { label: 'Scheduled', color: 'var(--primary)' },
    { label: 'In progress', color: 'var(--live)' },
    { label: 'Open · unassigned', color: 'var(--status-critical)' },
    { label: 'Replacement', color: 'var(--status-warning)' },
    { label: 'Completed', color: 'var(--status-success)' },
    { label: 'Cancelled', color: 'var(--status-neutral)' },
];

/* ── shift chip ─────────────────────────────────────────────────────────── */

function ShiftChip({
    s,
    draggable,
    onPeek,
    onCtx,
    onDragStart,
}: {
    s: CalendarShift;
    draggable: boolean;
    onPeek: (s: CalendarShift, rect: DOMRect) => void;
    onCtx: (s: CalendarShift, x: number, y: number) => void;
    onDragStart: (e: React.DragEvent, s: CalendarShift) => void;
}) {
    const m = calendarStatusMeta(s);
    return (
        // eslint-disable-next-line no-restricted-syntax -- bespoke calendar chip with status accent bar, not a shadcn Button.
        <button
            type="button"
            draggable={draggable}
            onDragStart={(e) => onDragStart(e, s)}
            onClick={(e) => {
                e.stopPropagation();
                onPeek(s, e.currentTarget.getBoundingClientRect());
            }}
            onContextMenu={(e) => {
                e.preventDefault();
                e.stopPropagation();
                onCtx(s, e.clientX, e.clientY);
            }}
            className="group/chip relative flex w-full cursor-pointer flex-col gap-0.5 rounded-lg border border-border bg-card px-1.5 py-1 pl-2 text-left shadow-xs transition-all hover:z-[2] hover:-translate-y-px hover:shadow-md"
            style={{
                borderLeftWidth: 3,
                borderLeftStyle: m.dashed ? 'dashed' : 'solid',
                borderLeftColor: m.accent,
                opacity: m.muted ? 0.62 : 1,
            }}
        >
            <span className="flex min-w-0 items-center gap-1.5">
                <span
                    className="relative h-[7px] w-[7px] shrink-0 rounded-full"
                    style={{ background: m.accent }}
                >
                    {m.live ? (
                        <span
                            className="absolute inset-0 animate-ping rounded-full"
                            style={{ background: m.accent }}
                        />
                    ) : null}
                </span>
                <span
                    className={cn(
                        'min-w-0 flex-1 truncate text-xs font-semibold text-foreground',
                        m.muted && 'line-through',
                    )}
                >
                    {s.client ?? 'Shift'}
                </span>
                {s.recurring ? (
                    <Repeat className="h-[11px] w-[11px] shrink-0 text-muted-foreground" />
                ) : null}
            </span>
            <span className="flex min-w-0 items-center gap-1 pl-3 text-[10.5px] text-muted-foreground">
                <span className="font-semibold tabular-nums">{s.start}</span>
                <span className="opacity-50">·</span>
                <span className="truncate">
                    {s.staff ?? (s.status === 'open' ? 'Unassigned' : 'Draft')}
                </span>
            </span>
            {(s.status === 'open' ||
                s.replacement ||
                s.incidents > 0 ||
                s.conflict) && (
                <span className="flex flex-wrap gap-1 pl-3">
                    {s.status === 'open' ? (
                        <span className="rounded bg-status-critical-bg px-1 text-[8.5px] font-extrabold tracking-wider text-status-critical">
                            OPEN
                        </span>
                    ) : null}
                    {s.replacement ? (
                        <span className="rounded bg-status-warning-bg px-1 text-[8.5px] font-extrabold tracking-wider text-status-warning">
                            REPL
                        </span>
                    ) : null}
                    {s.incidents > 0 ? (
                        <span className="rounded bg-status-critical-bg px-1 text-[8.5px] font-extrabold tracking-wider text-status-critical">
                            {s.incidents} INC
                        </span>
                    ) : null}
                    {s.conflict ? (
                        <span className="rounded bg-status-critical-bg px-1 text-[8.5px] font-extrabold tracking-wider text-status-critical">
                            CLASH
                        </span>
                    ) : null}
                </span>
            )}
        </button>
    );
}

/* ── day cell ───────────────────────────────────────────────────────────── */

const CHIP_CAP = 3;

function DayCell({
    date,
    inMonth,
    isToday,
    focused,
    shifts,
    hasGap,
    canCreate,
    canDrag,
    dragOver,
    onOpen,
    onQuickAdd,
    onPeek,
    onCtx,
    onDragStart,
    onDragOverDay,
    onDropDay,
}: {
    date: Date;
    inMonth: boolean;
    isToday: boolean;
    focused: boolean;
    shifts: CalendarShift[];
    hasGap: boolean;
    canCreate: boolean;
    canDrag: boolean;
    dragOver: boolean;
    onOpen: (key: string) => void;
    onQuickAdd: (key: string) => void;
    onPeek: (s: CalendarShift, rect: DOMRect) => void;
    onCtx: (s: CalendarShift, x: number, y: number) => void;
    onDragStart: (e: React.DragEvent, s: CalendarShift) => void;
    onDragOverDay: (key: string | null) => void;
    onDropDay: (key: string) => void;
}) {
    const key = ymdKey(date);
    const dow = date.getDay();
    const weekend = dow === 0 || dow === 6;
    const shown = shifts.slice(0, CHIP_CAP);
    const more = shifts.length - shown.length;
    const open = shifts.filter((s) => s.status === 'open').length;

    return (
        <div
            className={cn(
                'group/cell relative flex min-h-[132px] cursor-pointer flex-col gap-1 bg-card p-1.5 transition-colors hover:bg-secondary/50',
                weekend && 'bg-secondary/30',
                !inMonth && 'bg-muted/40',
                isToday && 'bg-primary/[0.04]',
                dragOver && 'bg-accent ring-2 ring-primary/50 ring-inset',
                focused && 'bg-primary/5 ring-2 ring-primary ring-inset',
            )}
            title="View day"
            onClick={() => onOpen(key)}
            onDragOver={(e) => {
                e.preventDefault();
                onDragOverDay(key);
            }}
            onDragLeave={() => onDragOverDay(null)}
            onDrop={(e) => {
                e.preventDefault();
                onDropDay(key);
            }}
        >
            <div className="flex min-h-[22px] items-center justify-between px-0.5">
                <span
                    className={cn(
                        'inline-flex h-6 w-6 items-center justify-center rounded-lg text-[13px] font-semibold',
                        isToday && 'bg-primary font-bold text-primary-foreground',
                        !inMonth && !isToday && 'text-muted-foreground/50',
                    )}
                >
                    {date.getDate()}
                </span>
                <span className="flex items-center gap-1">
                    {hasGap ? (
                        <span
                            className="rounded-md bg-status-warning-bg px-1.5 py-0.5 text-[9.5px] font-bold tracking-wide text-status-warning uppercase"
                            title="Site coverage gap in this day"
                        >
                            Gap
                        </span>
                    ) : null}
                    {open > 0 ? (
                        <span
                            className="rounded-md bg-status-critical-bg px-1.5 py-0.5 text-[9.5px] font-bold tracking-wide text-status-critical uppercase"
                            title={`${open} open shift${open > 1 ? 's' : ''}`}
                        >
                            {open} open
                        </span>
                    ) : null}
                    {canCreate ? (
                        // eslint-disable-next-line no-restricted-syntax -- 22px hover-reveal quick-add affordance inside the day cell header.
                        <button
                            type="button"
                            aria-label={`New shift on ${date.toLocaleDateString()}`}
                            title="New shift"
                            onClick={(e) => {
                                e.stopPropagation();
                                onQuickAdd(key);
                            }}
                            className="inline-flex h-[22px] w-[22px] items-center justify-center rounded-[7px] bg-accent text-primary opacity-0 transition-all group-hover/cell:opacity-100 hover:bg-primary hover:text-primary-foreground focus-visible:opacity-100"
                        >
                            <Plus className="h-[13px] w-[13px]" />
                        </button>
                    ) : null}
                </span>
            </div>
            <div className="flex flex-1 flex-col gap-1">
                {shown.map((s) => (
                    <ShiftChip
                        key={s.id}
                        s={s}
                        draggable={canDrag && DRAGGABLE_STATUSES.includes(s.status)}
                        onPeek={onPeek}
                        onCtx={onCtx}
                        onDragStart={onDragStart}
                    />
                ))}
                {more > 0 ? (
                    // eslint-disable-next-line no-restricted-syntax -- inline "+N more" overflow link styled per design.
                    <button
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onOpen(key);
                        }}
                        className="self-start rounded-md px-1 py-0.5 text-[11px] font-semibold text-muted-foreground hover:bg-accent hover:text-primary"
                    >
                        +{more} more
                    </button>
                ) : null}
            </div>
            {shifts.length > 0 ? (
                <div
                    className="mt-0.5 flex h-[3px] gap-0.5"
                    title={`${shifts.filter((s) => s.staff && s.status !== 'cancelled').length} filled · ${open} open`}
                >
                    {shifts.map((s) => (
                        <span
                            key={s.id}
                            className="min-w-1 flex-1 rounded-sm"
                            style={{
                                background: calendarStatusMeta(s).accent,
                                opacity: s.status === 'cancelled' ? 0.3 : 0.85,
                            }}
                        />
                    ))}
                </div>
            ) : null}
        </div>
    );
}

/* ── peek popover ───────────────────────────────────────────────────────── */

export type PeekState = {
    shift: CalendarShift;
    rect: { top: number; bottom: number; left: number; right: number };
    key: number;
};

function PeekPopover({
    peek,
    canManage,
    onClose,
    onEdit,
    onAssign,
    onMore,
}: {
    peek: PeekState;
    canManage: boolean;
    onClose: () => void;
    onEdit?: (s: CalendarShift) => void;
    onAssign?: (s: CalendarShift) => void;
    onMore: (s: CalendarShift) => void;
}) {
    const ref = useRef<HTMLDivElement | null>(null);
    const [pos, setPos] = useState<{ top: number; left: number } | null>(null);

    useLayoutEffect(() => {
        const el = ref.current;
        if (!el) return;
        const r = el.getBoundingClientRect();
        const gap = 10;
        let left = peek.rect.right + gap;
        if (left + r.width > window.innerWidth - 8) {
            left = peek.rect.left - r.width - gap;
        }
        if (left < 8) left = 8;
        let top = peek.rect.top;
        if (top + r.height > window.innerHeight - 8) {
            top = Math.max(8, window.innerHeight - r.height - 8);
        }
        setPos({ top, left });
    }, [peek]);

    useEffect(() => {
        const down = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                onClose();
            }
        };
        const key = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('mousedown', down);
        document.addEventListener('keydown', key);
        return () => {
            document.removeEventListener('mousedown', down);
            document.removeEventListener('keydown', key);
        };
    }, [onClose]);

    const s = peek.shift;
    const m = calendarStatusMeta(s);
    const taskPct = s.tasksTotal
        ? Math.round((s.tasksDone / s.tasksTotal) * 100)
        : 0;
    const assignable = canManage && (s.status === 'open' || s.status === 'draft');

    return createPortal(
        <div
            ref={ref}
            role="dialog"
            aria-label={`Shift preview: ${s.client ?? 'shift'} ${s.start}–${s.end}`}
            className="pointer-events-auto fixed z-[60] flex w-[320px] overflow-hidden rounded-2xl border border-border bg-popover shadow-xl animate-in fade-in-0 zoom-in-95 duration-150"
            style={
                pos
                    ? { top: pos.top, left: pos.left }
                    : { top: 0, left: 0, visibility: 'hidden' }
            }
            onClick={(e) => e.stopPropagation()}
        >
            <div className="w-[5px] shrink-0" style={{ background: m.accent }} />
            <div className="min-w-0 flex-1 p-4">
                <div className="mb-3 flex items-start justify-between gap-2.5">
                    <div className="min-w-0">
                        <div className="truncate text-[15.5px] font-bold tracking-tight">
                            {s.client ?? 'Shift'}
                        </div>
                        <div className="mt-px truncate text-xs text-muted-foreground">
                            {[s.siteName, s.context].filter(Boolean).join(' · ') ||
                                'No site set'}
                        </div>
                    </div>
                    <span
                        className="inline-flex shrink-0 items-center gap-1.5 rounded-full px-2 py-1 text-[11px] font-bold whitespace-nowrap"
                        style={{ background: m.tint, color: m.accent }}
                    >
                        {m.live ? (
                            <span
                                className="h-1.5 w-1.5 animate-pulse rounded-full"
                                style={{ background: m.accent }}
                            />
                        ) : null}
                        {m.label}
                    </span>
                </div>

                <div className="mb-3 grid grid-cols-2 gap-x-3.5 gap-y-2.5">
                    <PeekCell
                        icon={<Clock className="h-[13px] w-[13px]" />}
                        k="Time"
                        v={`${s.start}–${s.end} · ${fmtHours(s.durationH)}`}
                    />
                    <PeekCell
                        icon={<Users className="h-[13px] w-[13px]" />}
                        k="Staff"
                        v={s.staff ?? '—'}
                    />
                    <PeekCell
                        icon={<MapPin className="h-[13px] w-[13px]" />}
                        k="Type"
                        v={s.shiftType}
                        capitalize
                    />
                    <PeekCell
                        icon={<List className="h-[13px] w-[13px]" />}
                        k="Tasks"
                        v={`${s.tasksDone}/${s.tasksTotal}`}
                    />
                </div>

                {s.tasksTotal > 0 ? (
                    <div className="mb-3 h-[5px] overflow-hidden rounded-full bg-muted">
                        <span
                            className="block h-full rounded-full"
                            style={{ width: `${taskPct}%`, background: m.accent }}
                        />
                    </div>
                ) : null}

                {s.conflict || s.incidents > 0 ? (
                    <div className="mb-3 flex items-center gap-2 rounded-[9px] bg-status-critical-bg px-2.5 py-1.5 text-xs font-semibold text-status-critical">
                        <AlertTriangle className="h-[13px] w-[13px] shrink-0" />
                        {s.conflict
                            ? 'Scheduling clash on this slot'
                            : `${s.incidents} incident${s.incidents === 1 ? '' : 's'} logged`}
                    </div>
                ) : null}

                <div className="flex gap-2">
                    {assignable && onAssign ? (
                        <Button
                            size="sm"
                            className="flex-1"
                            onClick={() => {
                                onAssign(s);
                                onClose();
                            }}
                        >
                            <Plus className="mr-1 h-3.5 w-3.5" /> Assign
                        </Button>
                    ) : onEdit && canManage ? (
                        <Button
                            size="sm"
                            className="flex-1"
                            onClick={() => {
                                onEdit(s);
                                onClose();
                            }}
                        >
                            <Edit3 className="mr-1 h-3.5 w-3.5" /> Edit
                        </Button>
                    ) : (
                        <Button
                            size="sm"
                            className="flex-1"
                            onClick={() => {
                                window.location.href = `/operations/shifts/${s.id}`;
                            }}
                        >
                            Open shift
                        </Button>
                    )}
                    <Button
                        size="sm"
                        variant="outline"
                        className="flex-1"
                        onClick={() => onMore(s)}
                    >
                        <MoreHorizontal className="mr-1 h-3.5 w-3.5" /> More
                    </Button>
                </div>
            </div>
        </div>,
        document.body,
    );
}

function PeekCell({
    icon,
    k,
    v,
    capitalize,
}: {
    icon: React.ReactNode;
    k: string;
    v: string;
    capitalize?: boolean;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5">
            <span className="inline-flex items-center gap-1.5 text-[10.5px] font-semibold tracking-wide text-muted-foreground uppercase">
                {icon}
                {k}
            </span>
            <span
                className={cn(
                    'truncate text-[13px] font-semibold text-foreground',
                    capitalize && 'capitalize',
                )}
            >
                {v}
            </span>
        </div>
    );
}

/* ── mini date picker ───────────────────────────────────────────────────── */

function MiniCalendar({
    value,
    today,
    busyDays,
    onPick,
    onClose,
}: {
    value: Date;
    today: Date;
    busyDays: Set<string>;
    onPick: (d: Date) => void;
    onClose: () => void;
}) {
    const [vm, setVm] = useState(
        () => new Date(value.getFullYear(), value.getMonth(), 1),
    );
    const [yearMode, setYearMode] = useState(false);
    const ref = useRef<HTMLDivElement | null>(null);

    useEffect(() => {
        const down = (e: MouseEvent) => {
            if (ref.current && !ref.current.contains(e.target as Node)) {
                onClose();
            }
        };
        const key = (e: KeyboardEvent) => {
            if (e.key === 'Escape') onClose();
        };
        document.addEventListener('mousedown', down);
        document.addEventListener('keydown', key);
        return () => {
            document.removeEventListener('mousedown', down);
            document.removeEventListener('keydown', key);
        };
    }, [onClose]);

    const weeks = monthMatrix(vm.getFullYear(), vm.getMonth());
    const stepM = (d: number) =>
        setVm((m) => new Date(m.getFullYear(), m.getMonth() + d, 1));
    const stepY = (d: number) =>
        setVm((m) => new Date(m.getFullYear() + d, m.getMonth(), 1));
    const yearStart = vm.getFullYear() - 6;
    const years = Array.from({ length: 12 }, (_, i) => yearStart + i);
    const todayKey = ymdKey(today);

    return (
        <div
            ref={ref}
            className="absolute top-[calc(100%+8px)] left-0 z-[60] w-[276px] rounded-2xl border border-border bg-popover p-3 shadow-xl animate-in fade-in-0 zoom-in-95 duration-100"
            onClick={(e) => e.stopPropagation()}
        >
            <div className="mb-2.5 flex items-center gap-0.5">
                {/* eslint-disable-next-line no-restricted-syntax -- compact mini-picker stepper buttons. */}
                <button
                    type="button"
                    aria-label="Previous year"
                    onClick={() => (yearMode ? stepY(-12) : stepY(-1))}
                    className="inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground"
                >
                    <ChevronsLeft className="h-3.5 w-3.5" />
                </button>
                {!yearMode ? (
                    // eslint-disable-next-line no-restricted-syntax -- compact mini-picker stepper buttons.
                    <button
                        type="button"
                        aria-label="Previous month"
                        onClick={() => stepM(-1)}
                        className="inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground"
                    >
                        <ChevronLeft className="h-4 w-4" />
                    </button>
                ) : null}
                {/* eslint-disable-next-line no-restricted-syntax -- month/year title toggles year-grid mode. */}
                <button
                    type="button"
                    onClick={() => setYearMode((y) => !y)}
                    className="inline-flex flex-1 items-center justify-center gap-1 rounded-lg px-2 py-1 text-sm font-bold hover:bg-secondary"
                >
                    {yearMode
                        ? `${years[0]} – ${years[years.length - 1]}`
                        : `${CAL_MONTHS[vm.getMonth()]} ${vm.getFullYear()}`}
                    <ChevronDown
                        className={cn(
                            'h-3 w-3 text-muted-foreground transition-transform',
                            yearMode && 'rotate-180',
                        )}
                    />
                </button>
                {!yearMode ? (
                    // eslint-disable-next-line no-restricted-syntax -- compact mini-picker stepper buttons.
                    <button
                        type="button"
                        aria-label="Next month"
                        onClick={() => stepM(1)}
                        className="inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground"
                    >
                        <ChevronRight className="h-4 w-4" />
                    </button>
                ) : null}
                {/* eslint-disable-next-line no-restricted-syntax -- compact mini-picker stepper buttons. */}
                <button
                    type="button"
                    aria-label="Next year"
                    onClick={() => (yearMode ? stepY(12) : stepY(1))}
                    className="inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg text-muted-foreground hover:bg-secondary hover:text-foreground"
                >
                    <ChevronsRight className="h-3.5 w-3.5" />
                </button>
            </div>
            {yearMode ? (
                <div className="grid grid-cols-3 gap-1">
                    {years.map((y) => (
                        // eslint-disable-next-line no-restricted-syntax -- year tile in the mini picker grid.
                        <button
                            key={y}
                            type="button"
                            onClick={() => {
                                setVm((m) => new Date(y, m.getMonth(), 1));
                                setYearMode(false);
                            }}
                            className={cn(
                                'rounded-[9px] py-2.5 text-[13px] font-semibold transition-colors hover:bg-accent hover:text-primary',
                                y === today.getFullYear() &&
                                    'shadow-[inset_0_0_0_1.5px_var(--primary)] text-primary',
                                y === vm.getFullYear() &&
                                    'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground',
                            )}
                        >
                            {y}
                        </button>
                    ))}
                </div>
            ) : (
                <>
                    <div className="mb-1 grid grid-cols-7">
                        {['M', 'T', 'W', 'T', 'F', 'S', 'S'].map((d, i) => (
                            <span
                                key={i}
                                className="text-center text-[10px] font-bold tracking-wider text-muted-foreground"
                            >
                                {d}
                            </span>
                        ))}
                    </div>
                    <div className="grid grid-cols-7 gap-0.5">
                        {weeks.flat().map((date) => {
                            const k = ymdKey(date);
                            const out = date.getMonth() !== vm.getMonth();
                            const sel =
                                value.getFullYear() === date.getFullYear() &&
                                value.getMonth() === date.getMonth() &&
                                value.getDate() === date.getDate();
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- day tile in the mini picker grid.
                                <button
                                    key={k}
                                    type="button"
                                    onClick={() => onPick(date)}
                                    className={cn(
                                        'relative flex aspect-square items-center justify-center rounded-[9px] text-[12.5px] font-semibold transition-colors hover:bg-accent hover:text-primary',
                                        out && 'text-muted-foreground/45',
                                        k === todayKey &&
                                            'shadow-[inset_0_0_0_1.5px_var(--primary)] text-primary',
                                        sel &&
                                            'bg-primary text-primary-foreground hover:bg-primary hover:text-primary-foreground',
                                    )}
                                >
                                    {date.getDate()}
                                    {busyDays.has(k) ? (
                                        <span
                                            className={cn(
                                                'absolute bottom-1 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-current opacity-55',
                                                sel && 'opacity-80',
                                            )}
                                        />
                                    ) : null}
                                </button>
                            );
                        })}
                    </div>
                </>
            )}
            <div className="mt-2.5 flex justify-center border-t border-border pt-2.5">
                {/* eslint-disable-next-line no-restricted-syntax -- inline "jump to today" text action. */}
                <button
                    type="button"
                    onClick={() => onPick(new Date(today))}
                    className="rounded-lg px-2.5 py-1 text-[12.5px] font-bold text-primary hover:bg-accent"
                >
                    Jump to today
                </button>
            </div>
        </div>
    );
}

/* ── filter select ──────────────────────────────────────────────────────── */

/* ── pane ───────────────────────────────────────────────────────────────── */

export function CalendarPane(props: CalendarPaneProps) {
    const { onCreateShift } = props;
    const page = usePage();
    const auth = (page.props as any)?.auth;
    const canManageAny = !!(props.canManageAny && auth?.can?.shifts?.manageAny);
    const canCreate = !!auth?.can?.shifts?.create && !!onCreateShift;
    const canUpdate = !!auth?.can?.shifts?.update;
    const canViewSiteCalendar = !!auth?.can?.calendar?.viewAny;

    const today = useMemo(() => new Date(), []);
    const [cursor, setCursor] = useState(
        () => new Date(today.getFullYear(), today.getMonth(), 1),
    );
    // Hero-banner filter values (see CalendarPaneProps.filters). Staff/client
    // scoping mirrors the old embed's rule: only managers may scope to other
    // people; everyone else is already scoped server-side to their own shifts.
    const staffIdFilter = canManageAny ? (props.filters?.staff_id ?? null) : null;
    const clientIdFilter = canManageAny
        ? (props.filters?.client_id ?? null)
        : null;
    const siteIdsKey = (props.filters?.site_ids ?? []).join(',');

    const [events, setEvents] = useState<RawCalendarEvent[]>([]);
    const [loading, setLoading] = useState(true);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [refreshTick, setRefreshTick] = useState(0);
    const refetch = useCallback(() => setRefreshTick((t) => t + 1), []);

    const [peek, setPeek] = useState<PeekState | null>(null);
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [openDayKey, setOpenDayKey] = useState<string | null>(null);
    const [legendOpen, setLegendOpen] = useState(false);
    const [pickerOpen, setPickerOpen] = useState(false);
    const [focusDate, setFocusDate] = useState<string | null>(null);
    const [dragOver, setDragOver] = useState<string | null>(null);
    const dragShift = useRef<CalendarShift | null>(null);
    const lastRect = useRef(
        new Map<number, { top: number; bottom: number; left: number; right: number }>(),
    );
    const focusTimer = useRef<number | null>(null);

    const createShiftLauncher = useCreateShiftLauncher();

    const weeks = useMemo(
        () => monthMatrix(cursor.getFullYear(), cursor.getMonth()),
        [cursor],
    );

    // Fetch the whole visible 6-week range so leading/trailing days are real.
    const rangeStartKey = ymdKey(weeks[0][0]);
    const rangeEndKey = ymdKey(
        new Date(
            weeks[5][6].getFullYear(),
            weeks[5][6].getMonth(),
            weeks[5][6].getDate() + 1,
        ),
    );

    useEffect(() => {
        let cancelled = false;
        const controller = new AbortController();
        setLoading(true);
        (async () => {
            try {
                const params = new URLSearchParams({
                    start: rangeStartKey,
                    end: rangeEndKey,
                });
                if (staffIdFilter != null) {
                    params.set('staff_id', String(staffIdFilter));
                }
                if (clientIdFilter != null) {
                    params.set('client_id', String(clientIdFilter));
                }
                const res = await fetch(
                    `/operations/rostering/calendar/events?${params.toString()}`,
                    {
                        headers: { Accept: 'application/json' },
                        credentials: 'same-origin',
                        signal: controller.signal,
                    },
                );
                if (!res.ok) {
                    throw new Error(`Failed to load events (${res.status})`);
                }
                const data = (await res.json()) as RawCalendarEvent[];
                if (!cancelled) {
                    setEvents(Array.isArray(data) ? data : []);
                    setLoadError(null);
                }
            } catch (e) {
                if (!cancelled && (e as Error).name !== 'AbortError') {
                    setLoadError(
                        (e as Error).message || 'Failed to load the calendar.',
                    );
                }
            } finally {
                if (!cancelled) setLoading(false);
            }
        })();
        return () => {
            cancelled = true;
            controller.abort();
        };
    }, [rangeStartKey, rangeEndKey, staffIdFilter, clientIdFilter, refreshTick]);

    // Every shift mutation in this workspace goes through Inertia
    // (assign/unassign/cancel/duplicate/publish/… and the shared
    // CreateShiftDialog submit). Refetch the JSON feed whenever one succeeds.
    useEffect(() => {
        return router.on('success', () => refetch());
    }, [refetch]);

    const allShifts = useMemo(() => {
        const mapped = events
            .map(mapShift)
            .filter((s): s is CalendarShift => s !== null);
        return annotateConflicts(mapped);
    }, [events]);

    const gaps = useMemo(
        () =>
            events
                .map(mapGap)
                .filter((g): g is CalendarGap => g !== null),
        [events],
    );

    const shifts = useMemo(() => {
        if (!siteIdsKey) return allShifts;
        const ids = new Set(siteIdsKey.split(',').map(Number));
        return allShifts.filter((s) => s.siteId != null && ids.has(s.siteId));
    }, [allShifts, siteIdsKey]);

    const visibleGaps = useMemo(() => {
        if (!siteIdsKey) return gaps;
        const ids = new Set(siteIdsKey.split(',').map(Number));
        return gaps.filter((g) => g.siteId != null && ids.has(g.siteId));
    }, [gaps, siteIdsKey]);

    const byDay = useMemo(() => {
        const m = new Map<string, CalendarShift[]>();
        for (const s of shifts) {
            if (!m.has(s.dateKey)) m.set(s.dateKey, []);
            m.get(s.dateKey)!.push(s);
        }
        for (const list of m.values()) {
            list.sort((a, b) => a.start.localeCompare(b.start));
        }
        return m;
    }, [shifts]);

    const gapsByDay = useMemo(() => {
        const m = new Map<string, CalendarGap[]>();
        for (const g of visibleGaps) {
            if (!m.has(g.dateKey)) m.set(g.dateKey, []);
            m.get(g.dateKey)!.push(g);
        }
        return m;
    }, [visibleGaps]);

    const busyDays = useMemo(() => new Set(byDay.keys()), [byDay]);

    const monthPrefix = `${cursor.getFullYear()}-${String(cursor.getMonth() + 1).padStart(2, '0')}`;
    const stats = useMemo(() => {
        const r = {
            total: 0,
            hours: 0,
            scheduled: 0,
            live: 0,
            completed: 0,
            open: 0,
            gaps: 0,
        };
        for (const s of shifts) {
            if (!s.dateKey.startsWith(monthPrefix)) continue;
            r.total++;
            if (s.status !== 'cancelled') r.hours += s.durationH;
            if (s.status === 'scheduled') r.scheduled++;
            else if (s.status === 'in_progress') r.live++;
            else if (s.status === 'completed') r.completed++;
            else if (s.status === 'open') r.open++;
        }
        r.hours = Math.round(r.hours * 10) / 10;
        for (const g of visibleGaps) {
            if (g.dateKey.startsWith(monthPrefix)) r.gaps++;
        }
        return r;
    }, [shifts, visibleGaps, monthPrefix]);

    /* ── interactions ── */

    const closeFloating = useCallback(() => {
        setPeek(null);
        setCtx(null);
    }, []);

    // Wrap the page-level handlers so any floating UI closes before a dialog
    // opens on top.
    const actionCallbacks = useMemo(() => {
        const wrap = (fn?: (s: GridShift) => void) =>
            fn
                ? (s: GridShift) => {
                      closeFloating();
                      fn(s);
                  }
                : undefined;
        return {
            onAssignOpen: wrap(props.onAssignOpen),
            onUnassign: wrap(props.onUnassign),
            onCancelShift: wrap(props.onCancelShift),
            onResolveConflict: wrap(props.onResolveConflict),
            onReassign: wrap(props.onReassign),
            onDuplicateShift: wrap(props.onDuplicateShift),
            onCopyShiftToDay: wrap(props.onCopyShiftToDay),
            onReopenShift: wrap(props.onReopenShift),
            onMarkEndedEarly: wrap(props.onMarkEndedEarly),
            onAutoFillShift: wrap(props.onAutoFillShift),
            onReopenCompletedForCorrection: wrap(
                props.onReopenCompletedForCorrection,
            ),
            onPublishShift: wrap(props.onPublishShift),
            onMakeRecurring: wrap(props.onMakeRecurring),
            onBroadcastShift: wrap(props.onBroadcastShift),
            onRequestReplacement: wrap(props.onRequestReplacement),
            onEditShift: wrap(props.onEditShift),
            onReportIncident: wrap(props.onReportIncident),
            onViewTimesheet: wrap(props.onViewTimesheet),
        };
    }, [
        closeFloating,
        props.onAssignOpen,
        props.onUnassign,
        props.onCancelShift,
        props.onResolveConflict,
        props.onReassign,
        props.onDuplicateShift,
        props.onCopyShiftToDay,
        props.onReopenShift,
        props.onMarkEndedEarly,
        props.onAutoFillShift,
        props.onReopenCompletedForCorrection,
        props.onPublishShift,
        props.onMakeRecurring,
        props.onBroadcastShift,
        props.onRequestReplacement,
        props.onEditShift,
        props.onReportIncident,
        props.onViewTimesheet,
    ]);

    const openCtxAt = useCallback(
        (s: CalendarShift, x: number, y: number) => {
            const m = calendarStatusMeta(s);
            setPeek(null);
            setCtx({
                x,
                y,
                tag: m.label,
                tagBg: m.tint,
                tagColor: m.accent,
                meta: `${s.client ?? 'Shift'} · ${s.start}–${s.end}${s.staff ? ` · ${s.staff}` : ''}`,
                items: buildShiftActions(s, actionCallbacks),
            });
        },
        [actionCallbacks],
    );

    const onChipPeek = useCallback(
        (
            s: CalendarShift,
            rect: { top: number; bottom: number; left: number; right: number },
        ) => {
            setCtx(null);
            lastRect.current.set(s.id, rect);
            setPeek({ shift: s, rect, key: Date.now() });
        },
        [],
    );

    const onMoreFromPeek = useCallback(
        (s: CalendarShift) => {
            const r = lastRect.current.get(s.id);
            setPeek(null);
            openCtxAt(s, r ? r.left + 12 : 80, r ? r.bottom : 120);
        },
        [openCtxAt],
    );

    const onDragStart = useCallback(
        (e: React.DragEvent, s: CalendarShift) => {
            dragShift.current = s;
            e.dataTransfer.effectAllowed = 'move';
            try {
                e.dataTransfer.setData('text/plain', String(s.id));
            } catch {
                // some browsers throw on setData with non-standard types
            }
        },
        [],
    );

    const onDropDay = useCallback(
        async (dateKey: string) => {
            const s = dragShift.current;
            dragShift.current = null;
            setDragOver(null);
            if (!s || s.dateKey === dateKey || !canUpdate) return;
            if (!DRAGGABLE_STATUSES.includes(s.status)) return;

            const oldStart = new Date(s.starts_at);
            const oldEnd = new Date(s.ends_at);
            const target = parseDateKey(dateKey);
            // Preserve the wall-clock start time on the new day; keep duration.
            const newStart = new Date(
                target.getFullYear(),
                target.getMonth(),
                target.getDate(),
                oldStart.getHours(),
                oldStart.getMinutes(),
            );
            const newEnd = new Date(
                newStart.getTime() + (oldEnd.getTime() - oldStart.getTime()),
            );

            // Optimistic move so the chip lands instantly.
            setEvents((prev) =>
                prev.map((ev) =>
                    Number(ev.id) === s.id &&
                    ev.extendedProps?.event_type !== 'coverage_gap'
                        ? {
                              ...ev,
                              start: newStart.toISOString(),
                              end: newEnd.toISOString(),
                          }
                        : ev,
                ),
            );

            try {
                await jsonRequest(
                    `/operations/rostering/calendar/shifts/${s.id}`,
                    {
                        method: 'PATCH',
                        body: {
                            starts_at: newStart.toISOString(),
                            ends_at: newEnd.toISOString(),
                        },
                    },
                );
                toast.success(
                    `Moved ${s.client ?? 'shift'} to ${target.toLocaleDateString('en-NZ', { day: 'numeric', month: 'short' })}`,
                );
                refetch();
            } catch (e) {
                toast.error((e as Error).message || 'Could not move the shift');
                refetch();
            }
        },
        [canUpdate, refetch],
    );

    const quickAdd = useCallback(
        (dateKey: string) => {
            closeFloating();
            onCreateShift?.({ day: parseDateKey(dateKey) });
        },
        [closeFloating, onCreateShift],
    );

    const openDay = useCallback(
        (dateKey: string) => {
            closeFloating();
            setOpenDayKey(dateKey);
        },
        [closeFloating],
    );

    const jumpToDay = useCallback(
        (d: Date) => {
            closeFloating();
            setOpenDayKey(ymdKey(d));
            setCursor((c) =>
                c.getFullYear() === d.getFullYear() &&
                c.getMonth() === d.getMonth()
                    ? c
                    : new Date(d.getFullYear(), d.getMonth(), 1),
            );
        },
        [closeFloating],
    );

    const navDay = useCallback(
        (delta: number) => {
            if (!openDayKey) return;
            const d = parseDateKey(openDayKey);
            d.setDate(d.getDate() + delta);
            jumpToDay(d);
        },
        [openDayKey, jumpToDay],
    );

    const moveMonth = (delta: number) => {
        closeFloating();
        setCursor((c) => new Date(c.getFullYear(), c.getMonth() + delta, 1));
    };

    const pickDate = (d: Date) => {
        setCursor(new Date(d.getFullYear(), d.getMonth(), 1));
        setPickerOpen(false);
        setFocusDate(ymdKey(d));
        if (focusTimer.current) window.clearTimeout(focusTimer.current);
        focusTimer.current = window.setTimeout(() => setFocusDate(null), 2400);
    };

    const fillGap = useCallback(
        (gap: CalendarGap) => {
            void createShiftLauncher.openWith({
                site_id: gap.siteId,
                coverage_rule_id: gap.ruleId,
                client_id: gap.preferredClientId,
                starts_at: toLocalDatetimeInput(gap.startsAt),
                ends_at: toLocalDatetimeInput(gap.endsAt),
                coverage_rule_name: gap.ruleName ?? 'Coverage gap',
                coverage_required_staff: gap.requiredStaff,
                coverage_missing_staff: gap.missingStaff,
                coverage_role_shortages: JSON.stringify(gap.roleShortages ?? []),
            });
        },
        [createShiftLauncher],
    );

    const todayKey = ymdKey(today);
    const openDayShifts = openDayKey ? (byDay.get(openDayKey) ?? []) : [];
    const openDayGaps = openDayKey ? (gapsByDay.get(openDayKey) ?? []) : [];

    const statTiles = [
        { k: 'Shifts', v: String(stats.total) },
        { k: 'Hours', v: stats.hours.toFixed(1) },
        { k: 'Scheduled', v: String(stats.scheduled) },
        { k: 'In progress', v: String(stats.live), accent: 'var(--live)' },
        {
            k: 'Completed',
            v: String(stats.completed),
            accent: 'var(--status-success)',
        },
        {
            k: 'Coverage gaps',
            v: String(stats.gaps),
            accent: stats.gaps > 0 ? 'var(--status-critical)' : undefined,
            alarm: stats.gaps > 0,
        },
    ];

    return (
        // eslint-disable-next-line no-restricted-syntax -- the calendar shell is a custom layout surface, not a Card.
        <div
            data-testid="rostering-calendar"
            className="overflow-hidden rounded-2xl border border-border bg-card shadow-sm"
        >
            {/* ── toolbar ── */}
            <div className="flex flex-wrap items-center justify-between gap-3 px-4 py-3.5">
                <div className="flex items-center gap-3">
                    <div className="inline-flex items-center gap-1 rounded-[11px] border border-border bg-secondary p-[3px]">
                        {/* eslint-disable-next-line no-restricted-syntax -- segmented month-nav cluster per design. */}
                        <button
                            type="button"
                            aria-label="Previous month"
                            onClick={() => moveMonth(-1)}
                            className="inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-card hover:text-foreground hover:shadow-xs"
                        >
                            <ChevronLeft className="h-[17px] w-[17px]" />
                        </button>
                        {/* eslint-disable-next-line no-restricted-syntax -- segmented month-nav cluster per design. */}
                        <button
                            type="button"
                            onClick={() => {
                                closeFloating();
                                setCursor(
                                    new Date(
                                        today.getFullYear(),
                                        today.getMonth(),
                                        1,
                                    ),
                                );
                            }}
                            className="h-[30px] rounded-lg px-3.5 text-[12.5px] font-semibold transition-colors hover:bg-card hover:shadow-xs"
                        >
                            Today
                        </button>
                        {/* eslint-disable-next-line no-restricted-syntax -- segmented month-nav cluster per design. */}
                        <button
                            type="button"
                            aria-label="Next month"
                            onClick={() => moveMonth(1)}
                            className="inline-flex h-[30px] w-[30px] items-center justify-center rounded-lg text-muted-foreground transition-colors hover:bg-card hover:text-foreground hover:shadow-xs"
                        >
                            <ChevronRight className="h-[17px] w-[17px]" />
                        </button>
                    </div>
                    <div className="relative">
                        {/* eslint-disable-next-line no-restricted-syntax -- month title doubles as the date-picker trigger per design. */}
                        <button
                            type="button"
                            aria-haspopup="dialog"
                            aria-expanded={pickerOpen}
                            onClick={() => {
                                closeFloating();
                                setLegendOpen(false);
                                setPickerOpen((o) => !o);
                            }}
                            className="inline-flex items-center gap-1.5 rounded-[10px] border border-transparent px-2 py-1 text-[22px] font-semibold tracking-tight transition-colors hover:border-border hover:bg-secondary"
                        >
                            {CAL_MONTHS[cursor.getMonth()]}{' '}
                            <span className="font-medium text-muted-foreground">
                                {cursor.getFullYear()}
                            </span>
                            <ChevronDown
                                className={cn(
                                    'h-3.5 w-3.5 text-muted-foreground transition-transform',
                                    pickerOpen && 'rotate-180',
                                )}
                            />
                        </button>
                        {pickerOpen ? (
                            <MiniCalendar
                                value={cursor}
                                today={today}
                                busyDays={busyDays}
                                onPick={pickDate}
                                onClose={() => setPickerOpen(false)}
                            />
                        ) : null}
                    </div>
                </div>

                {/* Staff/client/site scoping lives in the page hero (the same
                    filters every rostering tab obeys) — the toolbar keeps only
                    calendar-specific tools. */}
                <div className="flex flex-wrap items-center gap-2">
                    <div className="relative">
                        <Button
                            variant="outline"
                            size="sm"
                            className="h-9 text-muted-foreground"
                            onClick={() => {
                                setPickerOpen(false);
                                setLegendOpen((o) => !o);
                            }}
                        >
                            <List className="mr-1 h-3.5 w-3.5" /> Legend
                        </Button>
                        {legendOpen ? (
                            <div className="absolute top-[calc(100%+6px)] right-0 z-[60] flex min-w-[180px] flex-col gap-2 rounded-xl border border-border bg-popover px-3 py-2.5 shadow-lg animate-in fade-in-0 zoom-in-95 duration-100">
                                {LEGEND.map((l) => (
                                    <div
                                        key={l.label}
                                        className="flex items-center gap-2 text-[12.5px]"
                                    >
                                        <span
                                            className="h-2.5 w-2.5 shrink-0 rounded"
                                            style={{ background: l.color }}
                                        />
                                        {l.label}
                                    </div>
                                ))}
                            </div>
                        ) : null}
                    </div>
                    {canViewSiteCalendar ? (
                        <Link
                            href="/calendar"
                            title="Appointments, inspections and other site obligations live on the Site Calendar"
                        >
                            <Button
                                variant="outline"
                                size="sm"
                                className="h-9 text-muted-foreground"
                            >
                                <CalendarDays className="mr-1 h-3.5 w-3.5" />
                                Site calendar
                            </Button>
                        </Link>
                    ) : null}
                    {canCreate ? (
                        <Button
                            size="sm"
                            className="h-9 shadow-[0_6px_16px_-6px_color-mix(in_oklch,var(--primary)_50%,transparent)]"
                            onClick={() => quickAdd(todayKey)}
                        >
                            <Plus className="mr-1 h-[15px] w-[15px]" /> New shift
                        </Button>
                    ) : null}
                </div>
            </div>

            {/* ── summary ribbon ── */}
            <div className="grid grid-cols-3 border-y border-border bg-secondary/50 lg:grid-cols-6">
                {statTiles.map((t, i) => (
                    <div
                        key={t.k}
                        className={cn(
                            'flex flex-col gap-1 border-border px-4 py-3',
                            i > 0 && 'border-l',
                            i === 3 && 'max-lg:border-l-0',
                            t.alarm && 'bg-status-critical-bg/40',
                        )}
                    >
                        <span className="text-[10.5px] font-semibold tracking-[0.06em] text-muted-foreground uppercase">
                            {t.k}
                        </span>
                        <span
                            className="text-[22px] leading-none font-bold tracking-tight tabular-nums"
                            style={t.accent ? { color: t.accent } : undefined}
                        >
                            {t.v}
                        </span>
                    </div>
                ))}
            </div>

            {loadError ? (
                <div className="mx-4 mt-4 flex items-center justify-between gap-3 rounded-xl border border-status-critical/30 bg-status-critical-bg px-4 py-3 text-sm text-status-critical">
                    <span className="flex items-center gap-2">
                        <AlertTriangle className="h-4 w-4 shrink-0" />
                        {loadError}
                    </span>
                    <Button size="sm" variant="outline" onClick={refetch}>
                        Retry
                    </Button>
                </div>
            ) : null}

            {/* ── month grid ── */}
            <div className="p-3.5" aria-busy={loading}>
                <div className="mb-2 grid grid-cols-7">
                    {CAL_WEEKDAYS.map((d) => (
                        <div
                            key={d}
                            className={cn(
                                'px-2 pb-1.5 text-[11px] font-bold tracking-[0.08em] text-muted-foreground uppercase',
                                (d === 'Sat' || d === 'Sun') &&
                                    'text-muted-foreground/65',
                            )}
                        >
                            {d}
                        </div>
                    ))}
                </div>
                <div
                    className={cn(
                        'flex flex-col divide-y divide-border overflow-hidden rounded-[13px] border border-border transition-opacity',
                        loading && 'opacity-60',
                    )}
                >
                    {weeks.map((row, wi) => (
                        <div
                            key={wi}
                            className="grid grid-cols-7 divide-x divide-border"
                        >
                            {row.map((date) => {
                                const key = ymdKey(date);
                                return (
                                    <DayCell
                                        key={key}
                                        date={date}
                                        inMonth={
                                            date.getMonth() === cursor.getMonth()
                                        }
                                        isToday={key === todayKey}
                                        focused={focusDate === key}
                                        shifts={byDay.get(key) ?? []}
                                        hasGap={gapsByDay.has(key)}
                                        canCreate={canCreate}
                                        canDrag={canUpdate}
                                        dragOver={dragOver === key}
                                        onOpen={openDay}
                                        onQuickAdd={quickAdd}
                                        onPeek={onChipPeek}
                                        onCtx={openCtxAt}
                                        onDragStart={onDragStart}
                                        onDragOverDay={setDragOver}
                                        onDropDay={(k) => void onDropDay(k)}
                                    />
                                );
                            })}
                        </div>
                    ))}
                </div>
                {!canManageAny ? (
                    <div className="mt-3 text-xs text-muted-foreground">
                        You're seeing only your own shifts.
                    </div>
                ) : null}
            </div>

            {/* ── floating layers ── */}
            {peek ? (
                <PeekPopover
                    key={peek.key}
                    peek={peek}
                    canManage={canManageAny}
                    onClose={() => setPeek(null)}
                    onEdit={actionCallbacks.onEditShift}
                    onAssign={actionCallbacks.onAssignOpen}
                    onMore={onMoreFromPeek}
                />
            ) : null}
            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}

            <DayDetailDialog
                open={Boolean(openDayKey)}
                dayKey={openDayKey ?? todayKey}
                todayKey={todayKey}
                shifts={openDayShifts}
                gaps={openDayGaps}
                popoverOpen={Boolean(peek || ctx)}
                canManage={canManageAny}
                canCreate={canCreate}
                onClose={() => setOpenDayKey(null)}
                onNav={navDay}
                onJumpToday={() => jumpToDay(today)}
                onPeek={onChipPeek}
                onCtx={openCtxAt}
                onAssign={actionCallbacks.onAssignOpen}
                onEdit={actionCallbacks.onEditShift}
                onNewShift={() => openDayKey && quickAdd(openDayKey)}
                onFillGap={fillGap}
            />

            {createShiftLauncher.dialog}
        </div>
    );
}
