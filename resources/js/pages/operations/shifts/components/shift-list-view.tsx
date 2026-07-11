import { useMemo } from 'react';
import { CalendarClock, Eye, MapPin, MoreVertical, Pencil, Plus } from 'lucide-react';

import { ShiftStatusBadge } from '@/components/shift-status-badge';
import { shiftTypeMeta } from '@/lib/shift-types';

import { StaffAvatar } from './staff-avatar';
import {
    clientFullName,
    effectiveStatus,
    isOpenShift,
    shiftDayKey,
    shiftEndTime,
    shiftHours,
    shiftStartTime,
    type ShiftRow,
} from './shift-row-types';
import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';

type Props = {
    shifts: ShiftRow[];
    todayKey: string;
    dense?: boolean;
    onShiftClick: (s: ShiftRow) => void;
    onAssignOpen: (s: ShiftRow) => void;
    onFindCover?: (s: ShiftRow) => void;
    onContextMenu: (s: ShiftRow, e: React.MouseEvent) => void;
    onEditClick: (s: ShiftRow) => void;
    onCreateOnDay?: (date: string) => void;
};

export function ShiftListView({
    shifts,
    todayKey,
    dense = false,
    onShiftClick,
    onAssignOpen,
    onFindCover,
    onContextMenu,
    onEditClick,
    onCreateOnDay,
}: Props) {
    const groups = useMemo(() => {
        const m = new Map<string, ShiftRow[]>();
        for (const s of shifts) {
            const key = shiftDayKey(s.starts_at);
            if (!m.has(key)) m.set(key, []);
            m.get(key)!.push(s);
        }
        for (const list of m.values()) {
            list.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        }
        return Array.from(m.entries()).sort((a, b) =>
            a[0].localeCompare(b[0]),
        );
    }, [shifts]);

    if (shifts.length === 0) {
        return (
            <GuardrailCard unstyled className="rounded-xl border border-border bg-card p-10 text-center">
                <CalendarClock className="mx-auto h-10 w-10 text-muted-foreground" />
                <h3 className="mt-3 text-lg font-semibold text-foreground">
                    No shifts match these filters
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    Try clearing a filter, or create a new shift.
                </p>
            </GuardrailCard>
        );
    }

    return (
        <div className="space-y-5">
            {groups.map(([date, list]) => (
                <DayBlock
                    key={date}
                    date={date}
                    list={list}
                    isToday={date === todayKey}
                    onShiftClick={onShiftClick}
                    onAssignOpen={onAssignOpen}
                    onFindCover={onFindCover}
                    onContextMenu={onContextMenu}
                    onEditClick={onEditClick}
                    onCreateOnDay={onCreateOnDay}
                    dense={dense}
                />
            ))}
        </div>
    );
}

function fmtDayHeading(yyyymmdd: string): string {
    const d = new Date(yyyymmdd + 'T00:00:00');
    return d.toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });
}

function DayBlock({
    date,
    list,
    isToday,
    onShiftClick,
    onAssignOpen,
    onFindCover,
    onContextMenu,
    onEditClick,
    onCreateOnDay,
    dense,
}: {
    date: string;
    list: ShiftRow[];
    isToday: boolean;
    onShiftClick: (s: ShiftRow) => void;
    onAssignOpen: (s: ShiftRow) => void;
    onFindCover?: (s: ShiftRow) => void;
    onContextMenu: (s: ShiftRow, e: React.MouseEvent) => void;
    onEditClick: (s: ShiftRow) => void;
    onCreateOnDay?: (date: string) => void;
    dense: boolean;
}) {
    const hours = list.reduce(
        (acc, s) => acc + shiftHours(s.starts_at, s.ends_at),
        0,
    );
    const openCount = list.filter(isOpenShift).length;

    return (
        <section className="overflow-hidden rounded-xl border border-border bg-card">
            <header
                className={[
                    'flex items-center justify-between border-b border-border px-4 py-3',
                    isToday ? 'bg-primary/10' : 'bg-muted/40',
                ].join(' ')}
            >
                <div className="flex min-w-0 items-center gap-3">
                    <div className="text-lg font-semibold text-foreground">
                        {fmtDayHeading(date)}
                    </div>
                    {isToday ? (
                        <span className="inline-flex items-center rounded-full bg-status-info-bg px-2 py-0.5 text-[11px] font-medium text-status-info">
                            Today
                        </span>
                    ) : null}
                    <span className="text-xs text-muted-foreground">
                        {list.length} shift{list.length === 1 ? '' : 's'} ·{' '}
                        {hours.toFixed(1)}h
                    </span>
                    {openCount > 0 ? (
                        <span className="inline-flex items-center rounded-full bg-status-critical-bg px-2 py-0.5 text-[11px] font-medium text-status-critical">
                            {openCount} open
                        </span>
                    ) : null}
                </div>
                {onCreateOnDay ? (
                    <GuardrailButton unstyled
                        type="button"
                        onClick={() => onCreateOnDay(date)}
                        className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary hover:bg-primary/5"
                    >
                        <Plus className="h-3.5 w-3.5" /> Add to day
                    </GuardrailButton>
                ) : null}
            </header>
            <ul>
                {list.map((s, i) => (
                    <ShiftRowItem
                        key={s.id}
                        shift={s}
                        last={i === list.length - 1}
                        onClick={() => onShiftClick(s)}
                        onAssign={() => onAssignOpen(s)}
                        onFindCover={() => onFindCover?.(s)}
                        onContextMenu={onContextMenu}
                        onEditClick={() => onEditClick(s)}
                        dense={dense}
                    />
                ))}
            </ul>
        </section>
    );
}

function ShiftRowItem({
    shift,
    last,
    onClick,
    onAssign,
    onFindCover,
    onContextMenu,
    onEditClick,
    dense,
}: {
    shift: ShiftRow;
    last: boolean;
    onClick: () => void;
    onAssign: () => void;
    onFindCover?: () => void;
    onContextMenu: (s: ShiftRow, e: React.MouseEvent) => void;
    onEditClick: () => void;
    dense: boolean;
}) {
    const open = isOpenShift(shift);
    const inProgress = shift.status === 'in_progress';
    const locked =
        shift.status === 'completed' || shift.status === 'cancelled';
    const coverRequested = Boolean(shift.cover_requested);
    const hours = shiftHours(shift.starts_at, shift.ends_at);
    const start = shiftStartTime(shift.starts_at);
    const end = shiftEndTime(shift.ends_at);
    const type = shiftTypeMeta(shift.shift_type);
    const TypeIcon = type.icon;
    const railColor = open
        ? 'var(--status-critical)'
        : inProgress
          ? 'var(--status-warning)'
          : 'var(--primary)';
    const railOpacity = open || inProgress ? 1 : 0.35;

    return (
        <li
            className={[
                'group flex cursor-pointer items-center gap-3 px-4 transition-colors hover:bg-muted/40',
                last ? '' : 'border-b border-border',
                dense ? 'py-2' : 'py-3',
                open ? 'bg-status-critical-bg/40' : '',
            ].join(' ')}
            onClick={onClick}
            onContextMenu={(e) => onContextMenu(shift, e)}
        >
            <div className="w-[88px] shrink-0">
                <div className="whitespace-nowrap text-sm font-semibold text-foreground tabular-nums">
                    {start}
                    <span className="font-normal text-muted-foreground">–</span>
                    {end}
                </div>
                <div className="text-[11px] text-muted-foreground tabular-nums">
                    {hours > 0 ? `${hours.toFixed(1)}h` : '—'}
                </div>
            </div>

            <div
                className="h-9 w-[3px] shrink-0 rounded-full"
                style={{ background: railColor, opacity: railOpacity }}
                aria-hidden="true"
            />

            <div className="min-w-0 flex-1">
                <div className="flex min-w-0 items-center gap-1.5">
                    <span className="truncate font-medium text-foreground">
                        {clientFullName(shift.client)}
                    </span>
                    <span className="inline-flex items-center gap-1 rounded-md border border-border bg-background px-1.5 py-0.5 text-[11px] font-medium text-muted-foreground">
                        <TypeIcon className="h-3 w-3" />
                        {type.label}
                    </span>
                </div>
                <div className="mt-0.5 truncate text-xs text-muted-foreground">
                    <MapPin className="-mt-0.5 mr-1 inline-block h-3 w-3" />
                    {shift.site?.name ?? shift.location ?? '—'}
                </div>
            </div>

            <div className="hidden w-[160px] shrink-0 items-center gap-2 sm:flex">
                <StaffAvatar name={shift.staff?.name ?? null} />
                {shift.staff ? (
                    <div className="min-w-0">
                        <div className="truncate text-sm text-foreground">
                            {shift.staff.name}
                        </div>
                        <ShiftStatusBadge status={effectiveStatus(shift)} />
                    </div>
                ) : (
                    <div className="min-w-0">
                        <GuardrailButton unstyled
                            type="button"
                            onClick={(e) => {
                                e.stopPropagation();
                                if (coverRequested || !onFindCover) {
                                    return;
                                }
                                onFindCover();
                            }}
                            disabled={coverRequested || !onFindCover}
                            className={[
                                'block truncate text-left text-xs font-medium',
                                coverRequested
                                    ? 'text-muted-foreground'
                                    : 'text-status-critical hover:underline',
                            ].join(' ')}
                        >
                            {coverRequested ? 'Cover requested' : 'Find cover'}
                        </GuardrailButton>
                        <ShiftStatusBadge status={effectiveStatus(shift)} />
                    </div>
                )}
            </div>

            <div className="flex shrink-0 items-center gap-0.5">
                <GuardrailButton unstyled
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        onClick();
                    }}
                    className="hidden h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground group-hover:inline-flex"
                    aria-label="View"
                >
                    <Eye className="h-4 w-4" />
                </GuardrailButton>
                {locked ? null : (
                    <GuardrailButton unstyled
                        type="button"
                        onClick={(e) => {
                            e.stopPropagation();
                            onEditClick();
                        }}
                        className="hidden h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground group-hover:inline-flex"
                        aria-label="Edit"
                    >
                        <Pencil className="h-4 w-4" />
                    </GuardrailButton>
                )}
                <GuardrailButton unstyled
                    type="button"
                    onClick={(e) => {
                        e.stopPropagation();
                        onContextMenu(shift, e);
                    }}
                    className="inline-flex h-8 w-8 items-center justify-center rounded-md text-muted-foreground transition hover:bg-muted hover:text-foreground"
                    aria-label="More"
                >
                    <MoreVertical className="h-4 w-4" />
                </GuardrailButton>
            </div>
        </li>
    );
}
