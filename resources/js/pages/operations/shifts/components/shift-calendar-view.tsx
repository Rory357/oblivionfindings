import { MoreVertical, Plus, UserPlus } from 'lucide-react';
import { useMemo } from 'react';

import { Button as GuardrailButton } from '@/components/ui/button';
import { Card as GuardrailCard } from '@/components/ui/card';
import {
    clientFullName,
    isOpenShift,
    shiftDayKey,
    shiftEndTime,
    shiftStartTime,
    type ShiftRow,
} from './shift-row-types';
import { StaffAvatar } from './staff-avatar';

type Props = {
    shifts: ShiftRow[];
    days: string[];
    todayKey: string;
    onShiftClick: (s: ShiftRow) => void;
    onContextMenu: (s: ShiftRow, e: React.MouseEvent) => void;
    onCreateOnDay?: (date: string) => void;
};

export function ShiftCalendarView({
    shifts,
    days,
    todayKey,
    onShiftClick,
    onContextMenu,
    onCreateOnDay,
}: Props) {
    const byDay = useMemo(() => {
        const m = new Map<string, ShiftRow[]>(days.map((d) => [d, []]));
        for (const s of shifts) {
            const key = shiftDayKey(s.starts_at);
            if (m.has(key)) m.get(key)!.push(s);
        }
        for (const list of m.values()) {
            list.sort((a, b) => a.starts_at.localeCompare(b.starts_at));
        }
        return m;
    }, [shifts, days]);

    function onColumnContext(d: string, e: React.MouseEvent) {
        const target = e.target as HTMLElement;
        if (target.closest('[data-shift-block]')) return;
        e.preventDefault();
        onCreateOnDay?.(d);
    }

    return (
        <GuardrailCard
            unstyled
            className="overflow-hidden rounded-xl border border-border bg-card"
        >
            <div className="flex items-center justify-between border-b border-border bg-muted/30 px-3 py-1.5 text-[11px] text-muted-foreground">
                <span>
                    Week of{' '}
                    {new Date(days[0] + 'T00:00:00').toLocaleDateString(
                        'en-NZ',
                        { day: 'numeric', month: 'short' },
                    )}
                </span>
                <span className="inline-flex items-center gap-1.5">
                    <MoreVertical className="h-3 w-3" />
                    Right-click a shift for actions
                </span>
            </div>
            <div className="grid grid-cols-7 border-b border-border bg-muted/40">
                {days.map((d) => {
                    const date = new Date(d + 'T00:00:00');
                    const isToday = d === todayKey;
                    return (
                        <div
                            key={d}
                            className={`border-r border-border px-3 py-2 text-center last:border-r-0 ${isToday ? 'bg-primary/10' : ''}`}
                        >
                            <div
                                className={`text-[11px] font-semibold tracking-wider uppercase ${isToday ? 'text-primary' : 'text-muted-foreground'}`}
                            >
                                {date.toLocaleDateString('en-NZ', {
                                    weekday: 'short',
                                })}
                            </div>
                            <div
                                className={`text-lg font-bold tabular-nums ${isToday ? 'text-primary' : 'text-foreground'}`}
                            >
                                {date.getDate()}
                            </div>
                        </div>
                    );
                })}
            </div>
            <div className="grid min-h-[420px] grid-cols-7">
                {days.map((d) => {
                    const list = byDay.get(d) || [];
                    return (
                        <div
                            key={d}
                            onContextMenu={(e) => onColumnContext(d, e)}
                            className="group/col relative min-h-full space-y-1.5 border-r border-border p-2 last:border-r-0"
                        >
                            {list.map((s) => (
                                <CalendarShiftBlock
                                    key={s.id}
                                    shift={s}
                                    onClick={() => onShiftClick(s)}
                                    onContextMenu={onContextMenu}
                                />
                            ))}
                            {list.length === 0 && onCreateOnDay ? (
                                <GuardrailButton
                                    unstyled
                                    type="button"
                                    onClick={() => onCreateOnDay(d)}
                                    className="flex h-full min-h-[80px] w-full items-center justify-center gap-1 rounded-md border border-dashed border-transparent text-center text-[11px] text-muted-foreground opacity-0 transition group-hover/col:opacity-100 hover:border-primary/30 hover:bg-primary/5 hover:text-primary focus-visible:opacity-100 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
                                >
                                    <Plus className="h-3 w-3" /> Add shift
                                </GuardrailButton>
                            ) : null}
                        </div>
                    );
                })}
            </div>
        </GuardrailCard>
    );
}

function CalendarShiftBlock({
    shift,
    onClick,
    onContextMenu,
}: {
    shift: ShiftRow;
    onClick: () => void;
    onContextMenu: (s: ShiftRow, e: React.MouseEvent) => void;
}) {
    const open = isOpenShift(shift);
    const tone = open
        ? {
              bg: 'var(--status-critical-bg)',
              border: 'color-mix(in oklch, var(--status-critical) 40%, transparent)',
              text: 'var(--status-critical)',
          }
        : shift.status === 'in_progress'
          ? {
                bg: 'var(--status-warning-bg)',
                border: 'color-mix(in oklch, var(--status-warning) 40%, transparent)',
                text: 'var(--status-warning)',
            }
          : shift.status === 'completed'
            ? {
                  bg: 'var(--status-success-bg)',
                  border: 'color-mix(in oklch, var(--status-success) 40%, transparent)',
                  text: 'var(--status-success)',
              }
            : {
                  bg: 'var(--accent)',
                  border: 'color-mix(in oklch, var(--primary) 35%, transparent)',
                  text: 'var(--primary)',
              };

    return (
        <div
            data-shift-block
            onContextMenu={(e) => {
                e.preventDefault();
                e.stopPropagation();
                onContextMenu(shift, e);
            }}
            onClick={onClick}
            className="group/blk relative cursor-pointer rounded-md border px-2 py-1.5 text-[11px] transition hover:brightness-95"
            style={{
                background: tone.bg,
                borderColor: tone.border,
                color: tone.text,
            }}
        >
            <div className="pr-5 font-semibold tabular-nums">
                {shiftStartTime(shift.starts_at)} –{' '}
                {shiftEndTime(shift.ends_at)}
            </div>
            <div className="truncate font-medium text-foreground">
                {clientFullName(shift.client)}
            </div>
            <div className="mt-0.5 flex items-center gap-1 text-muted-foreground">
                {shift.staff ? (
                    <>
                        <StaffAvatar name={shift.staff.name} size="sm" />
                        <span className="truncate">{shift.staff.name}</span>
                    </>
                ) : (
                    <span
                        className="inline-flex items-center gap-1 font-medium"
                        style={{ color: tone.text }}
                    >
                        <UserPlus className="h-3 w-3" /> Open
                    </span>
                )}
            </div>
            <GuardrailButton
                unstyled
                type="button"
                aria-label="Shift actions"
                onClick={(e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    onContextMenu(shift, e);
                }}
                className="absolute top-1 right-1 inline-flex h-5 w-5 items-center justify-center rounded border border-border bg-background/80 text-foreground opacity-0 shadow-sm transition group-hover/blk:opacity-100 hover:bg-background"
            >
                <MoreVertical className="h-3 w-3" />
            </GuardrailButton>
        </div>
    );
}
