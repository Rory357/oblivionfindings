import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    ChevronLeft,
    ChevronRight,
    Copy,
    Edit3,
    FileText,
    Megaphone,
    Minus,
    Play,
    Plus,
    RefreshCcw,
    Repeat,
    Send,
    Users,
    X,
} from 'lucide-react';
import type { ReactNode } from 'react';
import { useMemo, useState } from 'react';

import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';

import { avatarHueStyle } from './avatar-hue';
import {
    ShiftContextMenu,
    type ShiftCtxItem,
    type ShiftCtxState,
} from './shift-context-menu';

export type GridShiftStatus =
    | 'scheduled'
    | 'in_progress'
    | 'completed'
    | 'draft'
    | 'open'
    | 'leave'
    | 'cancelled';

export type GridShift = {
    id: number;
    status: GridShiftStatus;
    starts_at: string;
    ends_at: string;
    client: string | null;
    staff?: string | null;
    conflict?: boolean;
    conflictPeers?: GridConflictPeer[];
    incident?: boolean;
    blocked?: number;
    timesheet_id?: number | null;
    href?: string;
};

export type ComplianceBadgeState = {
    state: 'ok' | 'warning' | 'expired';
    expiring?: number;
    expired?: number;
};

export type GridConflictPeer = {
    id: number;
    status: GridShiftStatus;
    starts_at: string;
    ends_at: string;
    client: string | null;
    staff?: string | null;
    timesheet_id?: number | null;
    href?: string;
};

export type GridStaffRow = {
    id: number;
    name: string;
    role: string | null;
    initials: string;
    hue: number;
    complianceBadge?: ComplianceBadgeState | null;
    open?: boolean;
    shifts: Record<string, GridShift[]>;
};

export type WeekGridPaneProps = {
    days: Date[];
    rows: GridStaffRow[];
    /** Same shifts grouped by site. When provided, a Staff/Site toggle appears. */
    siteRows?: GridStaffRow[];
    todayKey: string | null;
    canManage: boolean;
    onAssignOpen?: (shift: GridShift) => void;
    onUnassign?: (shift: GridShift) => void;
    onCancelShift?: (shift: GridShift) => void;
    onCreateShift?: (ctx?: {
        day?: Date;
        staffId?: number;
        siteId?: number;
    }) => void;
    onResolveConflict?: (shift: GridShift) => void;
    onReassign?: (shift: GridShift) => void;
    onDuplicateShift?: (shift: GridShift) => void;
    onCopyShiftToDay?: (shift: GridShift) => void;
    onReopenShift?: (shift: GridShift) => void;
    onMarkEndedEarly?: (shift: GridShift) => void;
    onAutoFillShift?: (shift: GridShift) => void;
    onReopenCompletedForCorrection?: (shift: GridShift) => void;
    onPublishShift?: (shift: GridShift) => void;
    onMakeRecurring?: (shift: GridShift) => void;
    onBroadcastShift?: (shift: GridShift) => void;
    onRequestReplacement?: (shift: GridShift) => void;
    onEditShift?: (shift: GridShift) => void;
    onReportIncident?: (shift: GridShift) => void;
    /** Open the read-only timesheet popup for a completed shift's timesheet. */
    onViewTimesheet?: (shift: GridShift) => void;
    actionEndSlot?: ReactNode;
};

function fmtTime(iso: string): string {
    const d = new Date(iso);
    return d.toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
}

function fmtDay(d: Date) {
    return d.toLocaleDateString(undefined, {
        weekday: 'short',
        day: '2-digit',
        month: 'short',
    });
}

function ymdKey(d: Date) {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

const STATUS_CLASS: Record<GridShiftStatus, string> = {
    scheduled: 'bg-primary/10 text-primary border-primary/25',
    in_progress: 'bg-status-info-bg text-status-info border-status-info/35',
    completed:
        'bg-status-success-bg text-status-success border-status-success/35',
    draft: 'bg-muted text-muted-foreground border-dashed border-border',
    open: 'bg-status-warning-bg text-status-warning border-status-warning/50',
    leave: 'bg-[repeating-linear-gradient(45deg,var(--muted)_0_6px,transparent_6px_12px)] text-muted-foreground border-border',
    cancelled: 'bg-muted text-muted-foreground border-border line-through',
};

export const STATUS_LABELS: Record<GridShiftStatus, string> = {
    scheduled: 'Scheduled',
    in_progress: 'In progress',
    completed: 'Completed',
    open: 'Open',
    draft: 'Draft',
    leave: 'Leave',
    cancelled: 'Cancelled',
};

export const STATUS_CTX_TONE: Record<
    GridShiftStatus,
    { bg: string; color: string }
> =
    {
        scheduled: {
            bg: 'color-mix(in oklch, var(--primary) 15%, transparent)',
            color: 'var(--primary)',
        },
        in_progress: {
            bg: 'var(--status-info-bg)',
            color: 'var(--status-info)',
        },
        completed: {
            bg: 'var(--status-success-bg)',
            color: 'var(--status-success)',
        },
        open: {
            bg: 'var(--status-warning-bg)',
            color: 'var(--status-warning)',
        },
        draft: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
        leave: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
        cancelled: { bg: 'var(--muted)', color: 'var(--muted-foreground)' },
    };

export type ShiftActionCallbacks = {
    onAssignOpen?: (s: GridShift) => void;
    onUnassign?: (s: GridShift) => void;
    onCancelShift?: (s: GridShift) => void;
    onResolveConflict?: (s: GridShift) => void;
    onReassign?: (s: GridShift) => void;
    onDuplicateShift?: (s: GridShift) => void;
    onCopyShiftToDay?: (s: GridShift) => void;
    onReopenShift?: (s: GridShift) => void;
    onMarkEndedEarly?: (s: GridShift) => void;
    onAutoFillShift?: (s: GridShift) => void;
    onReopenCompletedForCorrection?: (s: GridShift) => void;
    onPublishShift?: (s: GridShift) => void;
    onMakeRecurring?: (s: GridShift) => void;
    onBroadcastShift?: (s: GridShift) => void;
    onRequestReplacement?: (s: GridShift) => void;
    onEditShift?: (s: GridShift) => void;
    onReportIncident?: (s: GridShift) => void;
    onViewTimesheet?: (s: GridShift) => void;
};

export function buildShiftActions(
    shift: GridShift,
    callbacks: {
        onAssignOpen?: (s: GridShift) => void;
        onUnassign?: (s: GridShift) => void;
        onCancelShift?: (s: GridShift) => void;
        onResolveConflict?: (s: GridShift) => void;
        onReassign?: (s: GridShift) => void;
        onDuplicateShift?: (s: GridShift) => void;
        onCopyShiftToDay?: (s: GridShift) => void;
        onReopenShift?: (s: GridShift) => void;
        onMarkEndedEarly?: (s: GridShift) => void;
        onAutoFillShift?: (s: GridShift) => void;
        onReopenCompletedForCorrection?: (s: GridShift) => void;
        onPublishShift?: (s: GridShift) => void;
        onMakeRecurring?: (s: GridShift) => void;
        onBroadcastShift?: (s: GridShift) => void;
        onRequestReplacement?: (s: GridShift) => void;
        onEditShift?: (s: GridShift) => void;
        onReportIncident?: (s: GridShift) => void;
        onViewTimesheet?: (s: GridShift) => void;
    },
): ShiftCtxItem[] {
    const items: ShiftCtxItem[] = [];
    const detailHref = shift.href ?? `/operations/shifts/${shift.id}`;
    const navAction = (
        label: string,
        icon: ReactNode,
        href: string,
    ): ShiftCtxItem => ({
        icon,
        label,
        tone: 'primary',
        kbd: '↵',
        onClick: () => {
            window.location.href = href;
        },
    });
    const editAction = (label: string): ShiftCtxItem => ({
        icon: <Edit3 className="h-3.5 w-3.5" />,
        label,
        tone: 'primary',
        kbd: '↵',
        onClick: () => {
            if (callbacks.onEditShift) {
                callbacks.onEditShift(shift);
            } else {
                window.location.href = detailHref;
            }
        },
    });
    const pushDuplicateAction = () => {
        if (callbacks.onDuplicateShift) {
            items.push({
                icon: <Copy className="h-3.5 w-3.5" />,
                label: 'Duplicate as draft',
                sub: 'Creates an unassigned copy',
                onClick: () => callbacks.onDuplicateShift?.(shift),
            });
        }
        if (callbacks.onCopyShiftToDay) {
            items.push({
                icon: <Copy className="h-3.5 w-3.5" />,
                label: 'Copy to day…',
                sub: 'Pick a target date for the copy',
                onClick: () => callbacks.onCopyShiftToDay?.(shift),
            });
        }
    };

    if (shift.conflict) {
        items.push({
            icon: <AlertTriangle className="h-3.5 w-3.5" />,
            label: 'Resolve overlap…',
            sub: 'Reassign or unassign one shift',
            tone: 'critical',
            onClick: () => callbacks.onResolveConflict?.(shift),
        });
        items.push({ sep: true });
    }

    if (shift.status === 'open') {
        items.push({
            icon: <Plus className="h-3.5 w-3.5" />,
            label: 'Assign staff…',
            sub: 'Pick from eligible candidates',
            tone: 'primary',
            kbd: '↵',
            onClick: () => callbacks.onAssignOpen?.(shift),
        });
        if (callbacks.onAutoFillShift) {
            items.push({
                icon: <RefreshCcw className="h-3.5 w-3.5" />,
                label: 'Auto-fill best match',
                sub: 'Assign the top eligible candidate',
                onClick: () => callbacks.onAutoFillShift?.(shift),
            });
        }
        if (callbacks.onBroadcastShift) {
            items.push({
                icon: <Megaphone className="h-3.5 w-3.5" />,
                label: 'Broadcast to staff…',
                sub: 'Notify every eligible candidate',
                onClick: () => callbacks.onBroadcastShift?.(shift),
            });
        }
        items.push({ sep: true });
        items.push(editAction('Edit shift'));
        pushDuplicateAction();
        items.push({ sep: true });
        items.push({
            icon: <X className="h-3.5 w-3.5" />,
            label: 'Delete open shift',
            tone: 'critical',
            onClick: () => callbacks.onCancelShift?.(shift),
        });
    } else if (shift.status === 'leave') {
        items.push(
            navAction(
                'Open leave request',
                <FileText className="h-3.5 w-3.5" />,
                detailHref,
            ),
        );
    } else if (shift.status === 'in_progress') {
        items.push(
            navAction(
                'Open live shift',
                <Play className="h-3.5 w-3.5" />,
                detailHref,
            ),
        );
        items.push({
            icon: <RefreshCcw className="h-3.5 w-3.5" />,
            label: 'Reassign staff…',
            onClick: () => callbacks.onReassign?.(shift),
        });
        items.push({
            icon: <Users className="h-3.5 w-3.5" />,
            label: 'Request replacement…',
            sub: callbacks.onRequestReplacement
                ? 'Notify eligible staff to cover'
                : 'Open shift detail',
            onClick: () => {
                if (callbacks.onRequestReplacement) {
                    callbacks.onRequestReplacement(shift);
                } else {
                    window.location.href = detailHref;
                }
            },
        });
        if (callbacks.onMarkEndedEarly) {
            items.push({
                icon: <AlertTriangle className="h-3.5 w-3.5" />,
                label: 'Mark ended early…',
                sub: 'Complete now with a reason',
                onClick: () => callbacks.onMarkEndedEarly?.(shift),
            });
        }
        items.push({ sep: true });
        items.push({
            icon: <AlertTriangle className="h-3.5 w-3.5" />,
            label: 'Report incident',
            tone: 'critical',
            onClick: () => callbacks.onReportIncident?.(shift),
        });
    } else if (shift.status === 'completed') {
        items.push(
            navAction(
                'Open shift detail',
                <FileText className="h-3.5 w-3.5" />,
                detailHref,
            ),
        );
        items.push({
            icon: <FileText className="h-3.5 w-3.5" />,
            label: 'View timesheet',
            onClick: () => {
                // Open the read-only popup inline when there's a linked
                // timesheet; otherwise fall back to the shift detail page.
                if (shift.timesheet_id && callbacks.onViewTimesheet) {
                    callbacks.onViewTimesheet(shift);
                } else {
                    window.location.href = detailHref;
                }
            },
        });
        if (callbacks.onReopenCompletedForCorrection) {
            items.push({
                icon: <RefreshCcw className="h-3.5 w-3.5" />,
                label: 'Reopen for correction…',
                sub: 'Revert and amend with a reason',
                onClick: () =>
                    callbacks.onReopenCompletedForCorrection?.(shift),
            });
        }
        items.push({
            icon: <AlertTriangle className="h-3.5 w-3.5" />,
            label: 'Report incident',
            onClick: () => callbacks.onReportIncident?.(shift),
        });
    } else if (shift.status === 'cancelled') {
        items.push(
            navAction(
                'Open cancelled shift',
                <FileText className="h-3.5 w-3.5" />,
                detailHref,
            ),
        );
        if (callbacks.onReopenShift) {
            items.push({
                icon: <RefreshCcw className="h-3.5 w-3.5" />,
                label: 'Reopen cancelled shift',
                sub: 'Restore this occurrence to planning',
                tone: 'primary',
                onClick: () => callbacks.onReopenShift?.(shift),
            });
        }
    } else if (shift.status === 'draft') {
        items.push(editAction('Edit draft'));
        if (callbacks.onPublishShift) {
            items.push({
                icon: <Send className="h-3.5 w-3.5" />,
                label: 'Publish shift',
                sub: 'Make this draft visible to staff',
                tone: 'primary',
                onClick: () => callbacks.onPublishShift?.(shift),
            });
        }
        pushDuplicateAction();
        if (callbacks.onMakeRecurring) {
            items.push({
                icon: <Repeat className="h-3.5 w-3.5" />,
                label: 'Make recurring…',
                sub: 'Promote to a weekly series',
                onClick: () => callbacks.onMakeRecurring?.(shift),
            });
        }
        items.push({ sep: true });
        items.push({
            icon: <X className="h-3.5 w-3.5" />,
            label: 'Delete draft',
            tone: 'critical',
            onClick: () => callbacks.onCancelShift?.(shift),
        });
    } else {
        items.push(editAction('Edit shift'));
        pushDuplicateAction();
        if (callbacks.onMakeRecurring) {
            items.push({
                icon: <Repeat className="h-3.5 w-3.5" />,
                label: 'Make recurring…',
                sub: 'Promote to a weekly series',
                onClick: () => callbacks.onMakeRecurring?.(shift),
            });
        }
        items.push({
            icon: <RefreshCcw className="h-3.5 w-3.5" />,
            label: 'Reassign staff…',
            sub: 'Replace with eligible cover',
            onClick: () => callbacks.onReassign?.(shift),
        });
        items.push({
            icon: <Minus className="h-3.5 w-3.5" />,
            label: 'Unassign · make open',
            onClick: () => callbacks.onUnassign?.(shift),
        });
        items.push({
            icon: <Users className="h-3.5 w-3.5" />,
            label: 'Request replacement…',
            sub: callbacks.onRequestReplacement
                ? 'Notify eligible staff to cover'
                : 'Open shift detail',
            onClick: () => {
                if (callbacks.onRequestReplacement) {
                    callbacks.onRequestReplacement(shift);
                } else {
                    window.location.href = detailHref;
                }
            },
        });
        items.push({ sep: true });
        items.push({
            icon: <AlertTriangle className="h-3.5 w-3.5" />,
            label: 'Report incident',
            onClick: () => callbacks.onReportIncident?.(shift),
        });
        items.push({
            icon: <X className="h-3.5 w-3.5" />,
            label: 'Cancel shift',
            tone: 'critical',
            onClick: () => callbacks.onCancelShift?.(shift),
        });
    }

    return items;
}

type GridView = 'week' | 'day' | 'list';

const GRID_VIEWS: { value: GridView; label: string }[] = [
    { value: 'week', label: 'Week' },
    { value: 'day', label: 'Day' },
    { value: 'list', label: 'List' },
];

type GroupBy = 'staff' | 'site';

const GROUP_VIEWS: { value: GroupBy; label: string }[] = [
    { value: 'staff', label: 'Staff' },
    { value: 'site', label: 'Site' },
];

function buildEmptyCellActions(
    staffName: string,
    dayLabel: string,
    onCreate?: () => void,
): ShiftCtxItem[] {
    return [
        {
            icon: <Plus className="h-3.5 w-3.5" />,
            label: 'Add shift here',
            sub: `${staffName} · ${dayLabel}`,
            tone: 'primary',
            kbd: '↵',
            onClick: onCreate,
        },
    ];
}

export function WeekGridPane({
    days,
    rows,
    siteRows,
    todayKey,
    canManage,
    onAssignOpen,
    onUnassign,
    onCancelShift,
    onCreateShift,
    onResolveConflict,
    onReassign,
    onDuplicateShift,
    onCopyShiftToDay,
    onReopenShift,
    onMarkEndedEarly,
    onAutoFillShift,
    onReopenCompletedForCorrection,
    onPublishShift,
    onMakeRecurring,
    onBroadcastShift,
    onRequestReplacement,
    onEditShift,
    onReportIncident,
    onViewTimesheet,
    actionEndSlot,
}: WeekGridPaneProps) {
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [view, setView] = useState<GridView>('week');
    const [groupBy, setGroupBy] = useState<GroupBy>('staff');
    const [dayCursor, setDayCursor] = useState<string | null>(null);

    // When grouping by site, render the site-grouped rows instead of staff rows.
    // The toggle only appears when siteRows is supplied, so this safely falls
    // back to staff rows otherwise.
    const activeRows = groupBy === 'site' && siteRows ? siteRows : rows;
    const groupedBySite = groupBy === 'site' && !!siteRows;

    // Resolve which day the single-day view focuses on. Falls back to today (when
    // it is inside the current week) and otherwise the first day, so the cursor
    // stays valid even after navigating to a different week.
    const todayIdx = todayKey
        ? days.findIndex((d) => ymdKey(d) === todayKey)
        : -1;
    const cursorIdx = (() => {
        if (dayCursor) {
            const i = days.findIndex((d) => ymdKey(d) === dayCursor);
            if (i >= 0) return i;
        }
        return todayIdx >= 0 ? todayIdx : 0;
    })();
    const visibleDays =
        view === 'day' && days.length > 0 ? [days[cursorIdx]] : days;
    const gridCols = `220px repeat(${visibleDays.length}, minmax(0, 1fr))`;
    const goDay = (delta: number) => {
        if (days.length === 0) return;
        const next = Math.min(days.length - 1, Math.max(0, cursorIdx + delta));
        setDayCursor(ymdKey(days[next]));
    };

    // Flatten every shift into a single chronological stream grouped by day for
    // the List view.
    const listGroups = useMemo(() => {
        const items: Array<{ shift: GridShift; staffName: string }> = [];
        for (const row of activeRows) {
            for (const dayShifts of Object.values(row.shifts)) {
                for (const s of dayShifts) {
                    items.push({ shift: s, staffName: s.staff ?? row.name });
                }
            }
        }
        items.sort(
            (a, b) =>
                new Date(a.shift.starts_at).getTime() -
                new Date(b.shift.starts_at).getTime(),
        );
        const map = new Map<
            string,
            Array<{ shift: GridShift; staffName: string }>
        >();
        for (const it of items) {
            const k = ymdKey(new Date(it.shift.starts_at));
            if (!map.has(k)) map.set(k, []);
            map.get(k)!.push(it);
        }
        return Array.from(map.entries());
    }, [activeRows]);

    const onShiftCtx = (
        e: React.MouseEvent,
        s: GridShift,
        staffName: string,
    ) => {
        e.preventDefault();
        e.stopPropagation();
        const tone = STATUS_CTX_TONE[s.status];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: STATUS_LABELS[s.status] ?? 'Shift',
            tagBg: tone.bg,
            tagColor: tone.color,
            meta: `${s.client ?? 'Shift'} · ${fmtTime(s.starts_at)}–${fmtTime(s.ends_at)}${staffName ? ` · ${staffName}` : ''}`,
            items: buildShiftActions(s, {
                onAssignOpen,
                onUnassign,
                onCancelShift,
                onResolveConflict,
                onReassign,
                onDuplicateShift,
                onCopyShiftToDay,
                onReopenShift,
                onMarkEndedEarly,
                onAutoFillShift,
                onReopenCompletedForCorrection,
                onPublishShift,
                onMakeRecurring,
                onBroadcastShift,
                onRequestReplacement,
                onEditShift,
                onReportIncident,
                onViewTimesheet,
            }),
        });
    };

    const onCellCtx = (e: React.MouseEvent, row: GridStaffRow, day: Date) => {
        e.preventDefault();
        e.stopPropagation();
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: 'Empty',
            tagBg: 'var(--muted)',
            tagColor: 'var(--muted-foreground)',
            meta: `${row.name} · ${fmtDay(day)}`,
            items: buildEmptyCellActions(row.name, fmtDay(day), () =>
                onCreateShift?.(
                    groupedBySite
                        ? { day, siteId: row.id > 0 ? row.id : undefined }
                        : row.open
                          ? { day }
                          : { day, staffId: row.id },
                ),
            ),
        });
    };

    return (
        <div className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-[14px] border border-border bg-card px-4 py-2.5">
                <div className="flex flex-wrap items-center gap-2">
                    {/* eslint-disable-next-line no-restricted-syntax -- segmented Week/Day/List selector container, not a Card. */}
                    <div
                        role="tablist"
                        aria-label="Roster layout"
                        className="inline-flex rounded-md border border-border bg-background p-0.5"
                    >
                        {GRID_VIEWS.map((opt) => {
                            const active = view === opt.value;
                            return (
                                // eslint-disable-next-line no-restricted-syntax -- segmented Week/Day/List selector; not a shadcn Button.
                                <button
                                    key={opt.value}
                                    type="button"
                                    role="tab"
                                    aria-selected={active}
                                    onClick={() => setView(opt.value)}
                                    className={cn(
                                        'rounded-sm px-3 py-1 text-xs font-semibold transition-colors',
                                        active
                                            ? 'bg-primary text-primary-foreground'
                                            : 'text-muted-foreground hover:bg-accent',
                                    )}
                                >
                                    {opt.label}
                                </button>
                            );
                        })}
                    </div>
                    {siteRows ? (
                        // eslint-disable-next-line no-restricted-syntax -- segmented Staff/Site selector container, not a Card.
                        <div
                            role="tablist"
                            aria-label="Group roster by"
                            className="inline-flex rounded-md border border-border bg-background p-0.5"
                        >
                            {GROUP_VIEWS.map((opt) => {
                                const active = groupBy === opt.value;
                                return (
                                    // eslint-disable-next-line no-restricted-syntax -- segmented Staff/Site selector; not a shadcn Button.
                                    <button
                                        key={opt.value}
                                        type="button"
                                        role="tab"
                                        aria-selected={active}
                                        onClick={() => setGroupBy(opt.value)}
                                        className={cn(
                                            'rounded-sm px-3 py-1 text-xs font-semibold transition-colors',
                                            active
                                                ? 'bg-primary text-primary-foreground'
                                                : 'text-muted-foreground hover:bg-accent',
                                        )}
                                    >
                                        {opt.label}
                                    </button>
                                );
                            })}
                        </div>
                    ) : null}
                </div>
                <div className="flex flex-wrap items-center gap-3 text-[11px] text-muted-foreground">
                    <LegendDot color="var(--primary)" label="Scheduled" />
                    <LegendDot color="var(--status-info)" label="In progress" />
                    <LegendDot
                        color="var(--status-success)"
                        label="Completed"
                    />
                    <LegendDot color="var(--status-warning)" label="Open" />
                    <LegendDot color="var(--muted)" label="Leave" />
                </div>
                <div className="flex items-center gap-2">
                    <span className="hidden text-[11px] text-muted-foreground/80 italic md:inline">
                        Right-click any shift for quick actions
                    </span>
                    {canManage && onCreateShift ? (
                        <Button
                            variant="outline"
                            size="sm"
                            onClick={() => onCreateShift()}
                        >
                            <Plus className="mr-1 h-3.5 w-3.5" /> Add shift
                        </Button>
                    ) : null}
                    {actionEndSlot}
                </div>
            </div>

            {view === 'day' ? (
                <div className="flex items-center justify-between rounded-[14px] border border-border bg-card px-3 py-2">
                    {/* eslint-disable-next-line no-restricted-syntax -- compact inline day-stepper, not a shadcn Button. */}
                    <button
                        type="button"
                        onClick={() => goDay(-1)}
                        disabled={cursorIdx <= 0}
                        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-muted-foreground transition-colors hover:bg-accent disabled:opacity-40"
                    >
                        <ChevronLeft className="h-3.5 w-3.5" /> Prev day
                    </button>
                    <div className="text-sm font-semibold">
                        {visibleDays[0]?.toLocaleDateString(undefined, {
                            weekday: 'long',
                            day: 'numeric',
                            month: 'long',
                        })}
                    </div>
                    {/* eslint-disable-next-line no-restricted-syntax -- compact inline day-stepper, not a shadcn Button. */}
                    <button
                        type="button"
                        onClick={() => goDay(1)}
                        disabled={cursorIdx >= days.length - 1}
                        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold text-muted-foreground transition-colors hover:bg-accent disabled:opacity-40"
                    >
                        Next day <ChevronRight className="h-3.5 w-3.5" />
                    </button>
                </div>
            ) : null}

            {view === 'list' ? (
                <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                    {activeRows.length === 0 ? (
                        <EmptyRoster />
                    ) : (
                        <div className="divide-y divide-border">
                            {listGroups.map(([dayKey, items]) => (
                                <div key={dayKey}>
                                    <div className="flex items-center justify-between bg-muted/50 px-4 py-2">
                                        <span className="text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                                            {fmtListDay(dayKey)}
                                        </span>
                                        <span className="text-[11px] text-muted-foreground tabular-nums">
                                            {items.length} shift
                                            {items.length === 1 ? '' : 's'}
                                        </span>
                                    </div>
                                    <ul className="divide-y divide-border">
                                        {items.map(({ shift, staffName }) => (
                                            <ShiftListRow
                                                key={shift.id}
                                                s={shift}
                                                staffName={staffName}
                                                onContextMenu={(e) =>
                                                    onShiftCtx(
                                                        e,
                                                        shift,
                                                        staffName,
                                                    )
                                                }
                                            />
                                        ))}
                                    </ul>
                                </div>
                            ))}
                        </div>
                    )}
                </div>
            ) : (
                <div className="overflow-hidden rounded-[14px] border border-border bg-card">
                    <div
                        className="sticky top-0 z-10 grid border-b border-border bg-muted/50"
                        style={{ gridTemplateColumns: gridCols }}
                    >
                        <div className="px-3 py-2 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                            {groupedBySite
                                ? `Sites · ${activeRows.length}`
                                : `Staff · ${activeRows.filter((r) => !r.open).length} rostered`}
                        </div>
                        {visibleDays.map((d, i) => {
                            const key = ymdKey(d);
                            const isToday = todayKey === key;
                            return (
                                <div
                                    key={i}
                                    className={cn(
                                        'px-2 py-2 text-center text-[11px]',
                                        isToday && 'bg-primary/10 text-primary',
                                    )}
                                >
                                    <div className="font-semibold tracking-wider uppercase">
                                        {d
                                            .toLocaleDateString(undefined, {
                                                weekday: 'short',
                                            })
                                            .toUpperCase()}
                                    </div>
                                    <div className="mt-0.5 text-xs font-bold tabular-nums">
                                        {d.toLocaleDateString(undefined, {
                                            day: '2-digit',
                                            month: 'short',
                                        })}
                                    </div>
                                </div>
                            );
                        })}
                    </div>
                    <div className="divide-y divide-border">
                        {activeRows.length === 0 ? <EmptyRoster /> : null}
                        {activeRows.map((row) => (
                            <div
                                key={row.id}
                                className={cn(
                                    'grid min-h-[68px]',
                                    row.open &&
                                        'border-t-2 border-dashed border-status-warning/40 bg-status-warning-bg/40',
                                )}
                                style={{ gridTemplateColumns: gridCols }}
                            >
                                <div className="flex items-center gap-2 px-3 py-2">
                                    <div
                                        className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-xs font-bold uppercase"
                                        style={avatarHueStyle(row.hue)}
                                    >
                                        {row.initials}
                                    </div>
                                    <div className="min-w-0">
                                        <div className="flex min-w-0 flex-wrap items-center gap-1.5">
                                            <div className="truncate text-sm font-semibold">
                                                {row.name}
                                            </div>
                                            <ComplianceChip
                                                badge={row.complianceBadge}
                                            />
                                        </div>
                                        {row.role ? (
                                            <div className="truncate text-[11px] text-muted-foreground">
                                                {row.role}
                                            </div>
                                        ) : null}
                                    </div>
                                </div>
                                {visibleDays.map((d, di) => {
                                    const key = ymdKey(d);
                                    const isToday = todayKey === key;
                                    const cellShifts = row.shifts[key] ?? [];
                                    return (
                                        <div
                                            key={di}
                                            className={cn(
                                                'space-y-1 border-l border-border p-1.5',
                                                isToday && 'bg-primary/5',
                                            )}
                                            onContextMenu={
                                                cellShifts.length === 0 &&
                                                canManage
                                                    ? (e) =>
                                                          onCellCtx(e, row, d)
                                                    : undefined
                                            }
                                        >
                                            {cellShifts.length === 0 ? (
                                                <div className="h-full min-h-[44px] rounded-md" />
                                            ) : (
                                                cellShifts.map((s) => (
                                                    <ShiftBlock
                                                        key={s.id}
                                                        s={s}
                                                        onContextMenu={(e) =>
                                                            onShiftCtx(
                                                                e,
                                                                s,
                                                                row.name,
                                                            )
                                                        }
                                                    />
                                                ))
                                            )}
                                        </div>
                                    );
                                })}
                            </div>
                        ))}
                    </div>
                </div>
            )}

            {ctx ? (
                <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} />
            ) : null}
        </div>
    );
}

function ComplianceChip({ badge }: { badge?: ComplianceBadgeState | null }) {
    if (!badge || badge.state === 'ok') return null;

    const expired = badge.expired ?? 0;
    const expiring = badge.expiring ?? 0;
    const isExpired = badge.state === 'expired' || expired > 0;
    const label = isExpired ? 'Expired compliance' : 'Expiring soon';
    const title = isExpired
        ? `${expired || 1} expired compliance item${(expired || 1) === 1 ? '' : 's'}`
        : `${expiring || 1} compliance item${(expiring || 1) === 1 ? '' : 's'} expiring soon`;

    return (
        <span
            className={cn(
                'inline-flex max-w-full items-center rounded-full border px-1.5 py-0.5 text-[10px] leading-none font-semibold',
                isExpired
                    ? 'border-status-critical/30 bg-status-critical-bg text-status-critical'
                    : 'border-status-warning/30 bg-status-warning-bg text-status-warning',
            )}
            title={title}
        >
            {label}
        </span>
    );
}

function ShiftBlock({
    s,
    onContextMenu,
}: {
    s: GridShift;
    onContextMenu: (e: React.MouseEvent) => void;
}) {
    const inner = (
        <div
            className={cn(
                'group relative rounded-md border px-2 py-1.5 text-[11.5px] leading-tight',
                STATUS_CLASS[s.status],
                s.conflict && 'ring-1 ring-status-critical',
            )}
            onContextMenu={onContextMenu}
        >
            <div className="flex items-center justify-between gap-1.5">
                <span className="font-bold tabular-nums">
                    {fmtTime(s.starts_at)}–{fmtTime(s.ends_at)}
                </span>
                <span className="flex items-center gap-1">
                    {s.conflict ? (
                        <span
                            className="inline-flex h-4 min-w-4 items-center justify-center rounded bg-status-critical text-[9px] font-bold text-white"
                            title="Overlap conflict"
                        >
                            !
                        </span>
                    ) : null}
                    {s.incident ? (
                        <span
                            className="inline-flex h-4 min-w-4 items-center justify-center rounded bg-status-warning text-[9px] font-bold text-white"
                            title="Incident recorded"
                        >
                            i
                        </span>
                    ) : null}
                    {s.blocked ? (
                        <span
                            className="inline-flex h-4 min-w-4 items-center justify-center rounded bg-status-critical px-1 text-[9px] font-bold text-white"
                            title="Blocked candidates"
                        >
                            ×{s.blocked}
                        </span>
                    ) : null}
                </span>
            </div>
            <div className="mt-0.5 truncate font-medium">{s.client ?? ''}</div>
        </div>
    );

    return s.href ? (
        <Link href={s.href} className="block">
            {inner}
        </Link>
    ) : (
        inner
    );
}

function LegendDot({ color, label }: { color: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span
                className="inline-block h-2 w-2 rounded-full"
                style={{ background: color }}
                aria-hidden="true"
            />
            <span>{label}</span>
        </span>
    );
}

function EmptyRoster() {
    return (
        <div className="p-6 text-center">
            <div className="text-sm font-semibold">No shifts this week</div>
            <div className="mt-1 text-xs text-muted-foreground">
                Auto-schedule, paste from last week, or add a shift to start
                building this roster.
            </div>
        </div>
    );
}

function fmtListDay(key: string): string {
    const d = new Date(key + 'T00:00:00');
    return d.toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

function ShiftListRow({
    s,
    staffName,
    onContextMenu,
}: {
    s: GridShift;
    staffName: string;
    onContextMenu: (e: React.MouseEvent) => void;
}) {
    const tone = STATUS_CTX_TONE[s.status];
    const inner = (
        <div
            onContextMenu={onContextMenu}
            className="group flex cursor-pointer items-center gap-3 px-4 py-2.5 transition-colors hover:bg-muted/40"
        >
            <div className="w-[108px] shrink-0 text-sm font-semibold tabular-nums">
                {fmtTime(s.starts_at)}
                <span className="font-normal text-muted-foreground">–</span>
                {fmtTime(s.ends_at)}
            </div>
            <span
                className="h-8 w-[3px] shrink-0 rounded-full"
                style={{ background: tone.color }}
                aria-hidden="true"
            />
            <div className="min-w-0 flex-1">
                <div className="truncate text-sm font-medium">
                    {s.client ?? 'Shift'}
                </div>
                <div className="truncate text-[11px] text-muted-foreground">
                    {staffName}
                </div>
            </div>
            {s.conflict ? (
                <span
                    className="inline-flex h-4 min-w-4 items-center justify-center rounded bg-status-critical text-[9px] font-bold text-white"
                    title="Overlap conflict"
                >
                    !
                </span>
            ) : null}
            <span
                className="inline-flex shrink-0 items-center rounded-full px-2 py-0.5 text-[11px] font-semibold"
                style={{ background: tone.bg, color: tone.color }}
            >
                {STATUS_LABELS[s.status]}
            </span>
        </div>
    );

    return (
        <li>
            {s.href ? (
                <Link href={s.href} className="block">
                    {inner}
                </Link>
            ) : (
                inner
            )}
        </li>
    );
}

export default WeekGridPane;
