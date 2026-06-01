import {
    type ReactNode,
    type RefObject,
    type KeyboardEvent as ReactKeyboardEvent,
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
} from 'react';
import { createPortal } from 'react-dom';
import {
    ChevronLeft,
    ChevronRight,
    Copy,
    Download,
    Eraser,
    LayoutGrid,
    Lock,
    Stamp,
    Wand2,
    Zap,
} from 'lucide-react';

import { cn } from '@/lib/utils';

const MONTHS = [
    'January',
    'February',
    'March',
    'April',
    'May',
    'June',
    'July',
    'August',
    'September',
    'October',
    'November',
    'December',
];
const MONTHS_SHORT = [
    'Jan',
    'Feb',
    'Mar',
    'Apr',
    'May',
    'Jun',
    'Jul',
    'Aug',
    'Sep',
    'Oct',
    'Nov',
    'Dec',
];
const DAYS_SHORT = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

export function startOfWeek(d: Date): Date {
    const date = new Date(d);
    const day = (date.getDay() + 6) % 7;
    date.setHours(0, 0, 0, 0);
    date.setDate(date.getDate() - day);
    return date;
}

export function addDaysWP(d: Date, n: number): Date {
    const r = new Date(d);
    r.setDate(r.getDate() + n);
    return r;
}

function sameDay(a: Date, b: Date) {
    return (
        a.getFullYear() === b.getFullYear() &&
        a.getMonth() === b.getMonth() &&
        a.getDate() === b.getDate()
    );
}

function fmtDayShort(d: Date) {
    return `${DAYS_SHORT[(d.getDay() + 6) % 7]} ${String(d.getDate()).padStart(2, '0')} ${MONTHS_SHORT[d.getMonth()]}`;
}

function fmtDayShortYear(d: Date) {
    return `${fmtDayShort(d)} ${d.getFullYear()}`;
}

export function formatWeekRange(weekStart: Date) {
    const end = addDaysWP(weekStart, 6);
    return { startLabel: fmtDayShort(weekStart), endLabel: fmtDayShortYear(end), start: weekStart, end };
}

export function weekNumberISO(d: Date): number {
    const date = new Date(Date.UTC(d.getFullYear(), d.getMonth(), d.getDate()));
    const dayNum = (date.getUTCDay() + 6) % 7;
    date.setUTCDate(date.getUTCDate() - dayNum + 3);
    const firstThu = new Date(Date.UTC(date.getUTCFullYear(), 0, 4));
    return (
        1 +
        Math.round(
            ((date.getTime() - firstThu.getTime()) / 86400000 -
                3 +
                ((firstThu.getUTCDay() + 6) % 7)) /
                7,
        )
    );
}

export function weekLabel(weekStart: Date): string {
    return `Wk ${weekNumberISO(weekStart)}`;
}

export function ymd(d: Date): string {
    const yyyy = d.getFullYear();
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const dd = String(d.getDate()).padStart(2, '0');
    return `${yyyy}-${mm}-${dd}`;
}

export type WeekRowAction = {
    icon: ReactNode;
    label: string;
    sub?: string;
    kbd?: string;
    tone?: 'primary' | 'critical';
    disabled?: boolean;
    onClick?: (weekStart: Date) => void;
};

export type WeekPickerProps = {
    selectedWeekStart: Date;
    anchorRef: RefObject<HTMLElement | null>;
    onSelect: (weekStart: Date) => void;
    onClose: () => void;
    today?: Date;
    canPublishWeek?: boolean;
};

export function WeekPicker({
    selectedWeekStart,
    anchorRef,
    onSelect,
    onClose,
    today: todayProp,
    canPublishWeek = false,
}: WeekPickerProps) {
    const today = useMemo(() => todayProp ?? new Date(), [todayProp]);
    const [viewMonth, setViewMonth] = useState(
        () =>
            new Date(
                selectedWeekStart.getFullYear(),
                selectedWeekStart.getMonth(),
                1,
            ),
    );
    const [hoverWeek, setHoverWeek] = useState<Date | null>(null);
    const [ctxMenu, setCtxMenu] = useState<{
        week: Date;
        x: number;
        y: number;
    } | null>(null);
    const popRef = useRef<HTMLDivElement | null>(null);

    // Autofocus the dialog on mount so ArrowDown/Enter route to
    // handleDialogKeyDown immediately. Without this, focus stays on the
    // trigger button that opened the picker and the keyboard handler
    // never fires until the user manually Tabs into the dialog.
    //
    // Defer through requestAnimationFrame: a synchronous focus() in
    // useEffect races the browser's native click-target focus on the
    // trigger button (verified in Chrome — focus ended up on <body>).
    // rAF runs after the click event finishes propagating, so we win
    // the race deterministically.
    useEffect(() => {
        const id = requestAnimationFrame(() => {
            popRef.current?.focus();
        });
        return () => cancelAnimationFrame(id);
    }, []);

    const [pos, setPos] = useState({ top: 0, left: 0 });
    useEffect(() => {
        if (!anchorRef?.current) return;
        const update = () => {
            const el = anchorRef.current;
            if (!el) return;
            // The popover is `position: fixed`, so coordinates must be
            // viewport-relative. `getBoundingClientRect()` already returns
            // viewport coords — adding window.scrollY/X here caused the
            // picker to drift down/right as the page scrolled.
            const r = el.getBoundingClientRect();
            setPos({
                top: r.bottom + 8,
                left: r.left,
            });
        };
        update();
        window.addEventListener('scroll', update, true);
        window.addEventListener('resize', update);
        return () => {
            window.removeEventListener('scroll', update, true);
            window.removeEventListener('resize', update);
        };
    }, [anchorRef]);

    useEffect(() => {
        const onDown = (e: MouseEvent) => {
            const target = e.target as Node;
            if (
                popRef.current &&
                !popRef.current.contains(target) &&
                !anchorRef.current?.contains(target)
            ) {
                onClose();
            }
        };
        const onKey = (e: KeyboardEvent) => {
            if (e.key === 'Escape') {
                if (ctxMenu) setCtxMenu(null);
                else onClose();
            }
        };
        document.addEventListener('mousedown', onDown);
        document.addEventListener('keydown', onKey);
        return () => {
            document.removeEventListener('mousedown', onDown);
            document.removeEventListener('keydown', onKey);
        };
    }, [onClose, anchorRef, ctxMenu]);

    const grid = useMemo(() => {
        const firstOfMonth = new Date(
            viewMonth.getFullYear(),
            viewMonth.getMonth(),
            1,
        );
        const gridStart = startOfWeek(firstOfMonth);
        const rows: Array<{ weekStart: Date; days: Date[] }> = [];
        for (let r = 0; r < 6; r++) {
            const weekStart = addDaysWP(gridStart, r * 7);
            const days = Array.from({ length: 7 }, (_, i) =>
                addDaysWP(weekStart, i),
            );
            rows.push({ weekStart, days });
        }
        return rows;
    }, [viewMonth]);

    const goMonth = useCallback((delta: number) =>
        setViewMonth(
            new Date(viewMonth.getFullYear(), viewMonth.getMonth() + delta, 1),
        ), [viewMonth]);

    const focusWeek = useCallback((weekStart: Date) => {
        setHoverWeek(weekStart);
        setViewMonth((current) => {
            if (
                current.getFullYear() === weekStart.getFullYear() &&
                current.getMonth() === weekStart.getMonth()
            ) {
                return current;
            }

            return new Date(weekStart.getFullYear(), weekStart.getMonth(), 1);
        });
    }, []);

    const handleDialogKeyDown = (
        event: ReactKeyboardEvent<HTMLDivElement>,
    ) => {
        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            goMonth(-1);
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            goMonth(1);
        } else if (event.key === 'ArrowUp') {
            event.preventDefault();
            focusWeek(addDaysWP(hoverWeek ?? selectedWeekStart, -7));
        } else if (event.key === 'ArrowDown') {
            event.preventDefault();
            focusWeek(addDaysWP(hoverWeek ?? selectedWeekStart, 7));
        } else if (event.key === 'Enter') {
            event.preventDefault();
            onSelect(hoverWeek ?? selectedWeekStart);
            onClose();
        }
    };

    const focusedWeek = hoverWeek ?? selectedWeekStart;
    const focusedRange = formatWeekRange(focusedWeek);
    const focusedYear = focusedWeek.getFullYear();
    const isThisWeek = sameDay(focusedWeek, startOfWeek(today));

    const openCtx = (e: React.MouseEvent, weekStart: Date) => {
        e.preventDefault();
        if (!popRef.current) return;
        const popRect = popRef.current.getBoundingClientRect();
        setCtxMenu({
            week: weekStart,
            x: e.clientX - popRect.left,
            y: e.clientY - popRect.top,
        });
    };

    const closeCtx = useCallback(() => setCtxMenu(null), []);

    return createPortal(
        <div
            ref={popRef}
            tabIndex={-1}
            className={cn(
                'fixed z-50 w-[360px] rounded-[14px] border border-border bg-popover p-3.5 text-popover-foreground shadow-lg outline-none',
                'animate-in fade-in-0 slide-in-from-top-1 zoom-in-95 duration-150',
            )}
            style={{ top: pos.top, left: pos.left }}
            role="dialog"
            aria-label="Pick a week"
            onKeyDown={handleDialogKeyDown}
        >
            <div className="mb-3 flex items-center gap-3 rounded-[11px] bg-gradient-to-br from-primary/90 to-primary p-3 text-primary-foreground shadow-sm">
                <div className="flex min-w-[50px] flex-col items-center rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/20 px-2 py-1">
                    <span className="text-[10px] font-semibold uppercase tracking-wider opacity-80">
                        Wk
                    </span>
                    <span className="text-[22px] font-extrabold tabular-nums leading-none">
                        {weekNumberISO(focusedWeek)}
                    </span>
                </div>
                <div className="min-w-0 flex-1">
                    <div className="text-sm font-semibold tracking-tight">
                        {focusedRange.startLabel}{' '}
                        <span className="opacity-60">→</span>{' '}
                        {focusedRange.endLabel}
                    </div>
                    <div className="mt-0.5 text-[11px] opacity-80">
                        {isThisWeek ? (
                            <span className="text-emerald-100">
                                ● Current week
                            </span>
                        ) : (
                            <span>{focusedYear} · ISO week</span>
                        )}
                        {hoverWeek ? (
                            <span className="opacity-80">
                                {' '}
                                · click to jump
                            </span>
                        ) : null}
                    </div>
                </div>
            </div>

            <div className="mb-2.5 flex items-center justify-between">
                <button
                    type="button"
                    onClick={() => goMonth(-1)}
                    className="inline-flex h-7 w-7 items-center justify-center rounded-md border border-border bg-background text-muted-foreground hover:bg-accent hover:text-foreground"
                    aria-label="Previous month"
                >
                    <ChevronLeft className="h-4 w-4" />
                </button>
                <div className="text-sm font-bold">
                    {MONTHS[viewMonth.getMonth()]} {viewMonth.getFullYear()}
                </div>
                <button
                    type="button"
                    onClick={() => goMonth(+1)}
                    className="inline-flex h-7 w-7 items-center justify-center rounded-md border border-border bg-background text-muted-foreground hover:bg-accent hover:text-foreground"
                    aria-label="Next month"
                >
                    <ChevronRight className="h-4 w-4" />
                </button>
            </div>

            <div className="mb-1 grid grid-cols-[32px_repeat(7,1fr)] text-[10.5px] font-bold uppercase tracking-wider text-muted-foreground/70">
                <span className="text-center">Wk</span>
                {DAYS_SHORT.map((d) => (
                    <span key={d} className="text-center">
                        {d.slice(0, 1)}
                    </span>
                ))}
            </div>

            <div className="flex flex-col gap-0.5">
                {grid.map((row) => {
                    const isSelected = sameDay(row.weekStart, selectedWeekStart);
                    const isHover =
                        hoverWeek && sameDay(row.weekStart, hoverWeek);
                    const containsToday = row.days.some((d) =>
                        sameDay(d, today),
                    );
                    return (
                        <button
                            type="button"
                            key={ymd(row.weekStart)}
                            className={cn(
                                'grid grid-cols-[32px_repeat(7,1fr)] items-center rounded-md border border-transparent transition',
                                isSelected
                                    ? 'border-primary bg-primary/15 font-semibold text-primary'
                                    : 'hover:border-primary/30 hover:bg-primary/8',
                                containsToday &&
                                    !isSelected &&
                                    'shadow-[inset_0_0_0_1px_color-mix(in_oklch,var(--primary)_25%,transparent)]',
                                isHover && 'border-primary/40',
                            )}
                            onMouseEnter={() => setHoverWeek(row.weekStart)}
                            onMouseLeave={() => setHoverWeek(null)}
                            onClick={() => {
                                onSelect(row.weekStart);
                                onClose();
                            }}
                            onContextMenu={(e) => openCtx(e, row.weekStart)}
                            aria-label={`Select ${weekLabel(row.weekStart)} starting ${fmtDayShort(row.weekStart)}`}
                        >
                            <span className="py-1.5 text-center text-[10.5px] font-bold tabular-nums text-muted-foreground/70">
                                {weekNumberISO(row.weekStart)}
                            </span>
                            {row.days.map((d, i) => {
                                const otherMonth =
                                    d.getMonth() !== viewMonth.getMonth();
                                const isToday = sameDay(d, today);
                                return (
                                    <span
                                        key={i}
                                        className={cn(
                                            'relative py-1.5 text-center text-[12.5px] tabular-nums',
                                            otherMonth && 'opacity-55',
                                            isToday && 'font-bold',
                                        )}
                                    >
                                        {d.getDate()}
                                        {isToday ? (
                                            <span
                                                className="absolute bottom-0.5 left-1/2 h-1 w-1 -translate-x-1/2 rounded-full bg-primary"
                                                aria-hidden="true"
                                            />
                                        ) : null}
                                    </span>
                                );
                            })}
                        </button>
                    );
                })}
            </div>

            <div className="mt-3 flex items-center justify-between border-t border-border pt-3">
                <span className="text-[11px] italic text-muted-foreground/80">
                    Right-click a week for options
                </span>
                <div className="flex items-center gap-2">
                    <button
                        type="button"
                        onClick={() => {
                            onSelect(startOfWeek(today));
                            onClose();
                        }}
                        className="rounded-md border border-border bg-background px-3 py-1.5 text-xs font-semibold text-foreground hover:bg-accent"
                    >
                        This week
                    </button>
                    <button
                        type="button"
                        onClick={onClose}
                        className="rounded-md bg-primary px-3 py-1.5 text-xs font-semibold text-primary-foreground hover:bg-primary/90"
                    >
                        Done
                    </button>
                </div>
            </div>

            {ctxMenu ? (
                <WeekRowContextMenu
                    ctx={ctxMenu}
                    onClose={closeCtx}
                    onSelect={onSelect}
                    onPickerClose={onClose}
                    canPublishWeek={canPublishWeek}
                />
            ) : null}
        </div>,
        document.body,
    );
}

type CtxMenuState = { week: Date; x: number; y: number };

type WeekRowContextMenuProps = {
    ctx: CtxMenuState;
    onClose: () => void;
    onSelect: (week: Date) => void;
    onPickerClose: () => void;
    canPublishWeek: boolean;
};

function WeekRowContextMenu({
    ctx,
    onClose,
    onSelect,
    onPickerClose,
    canPublishWeek,
}: WeekRowContextMenuProps) {
    const menuRef = useRef<HTMLDivElement | null>(null);
    const range = formatWeekRange(ctx.week);
    const wk = weekLabel(ctx.week);

    useEffect(() => {
        const onDown = (e: MouseEvent) => {
            if (menuRef.current && !menuRef.current.contains(e.target as Node)) {
                onClose();
            }
        };
        document.addEventListener('mousedown', onDown);
        return () => document.removeEventListener('mousedown', onDown);
    }, [onClose]);

    type Item =
        | { sep: true }
        | {
              sep?: false;
              icon: ReactNode;
              label: string;
              sub?: string;
              kbd?: string;
              tone?: 'primary' | 'critical';
              onClick?: () => void;
          };

    const items: Item[] = [
        {
            icon: <ChevronRight className="h-3.5 w-3.5" />,
            label: `Jump to ${wk}`,
            kbd: '↵',
            onClick: () => {
                onSelect(ctx.week);
                onPickerClose();
            },
        },
        {
            icon: <LayoutGrid className="h-3.5 w-3.5" />,
            label: 'Open in shifts view',
            onClick: () => {
                onSelect(ctx.week);
                onPickerClose();
            },
        },
        { sep: true },
        {
            icon: <Copy className="h-3.5 w-3.5" />,
            label: 'Duplicate last week here',
            sub: "Copies the prior week's pattern",
            // Not yet wired — leaving the entry visible but inert so the
            // menu is consistent with future capability work.
        },
        {
            icon: <Stamp className="h-3.5 w-3.5" />,
            label: 'Apply roster template…',
            sub: 'Choose from saved templates',
        },
        {
            icon: <Zap className="h-3.5 w-3.5" />,
            label: 'Auto-schedule this week',
            sub: 'Suggest fills based on availability',
        },
        { sep: true },
        ...(canPublishWeek
            ? [
                  {
                      icon: <Wand2 className="h-3.5 w-3.5" />,
                      label: 'Publish week',
                      tone: 'primary' as const,
                  },
              ]
            : []),
        {
            icon: <Lock className="h-3.5 w-3.5" />,
            label: 'Lock for edits',
        },
        {
            icon: <Download className="h-3.5 w-3.5" />,
            label: 'Export · CSV / iCal',
        },
        { sep: true },
        {
            icon: <Eraser className="h-3.5 w-3.5" />,
            label: 'Clear week',
            tone: 'critical' as const,
            sub: 'Removes all draft shifts',
        },
    ];

    return (
        <div
            ref={menuRef}
            className="absolute z-50 w-[270px] rounded-[12px] border border-border bg-popover p-1.5 text-popover-foreground shadow-lg animate-in fade-in-0 zoom-in-95 duration-100"
            style={{ top: ctx.y, left: ctx.x }}
            role="menu"
        >
            <div className="mb-1 flex items-center gap-2 px-2 py-1.5 border-b border-border/60">
                <span className="rounded bg-primary/15 px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wider text-primary">
                    {wk}
                </span>
                <span className="text-[11px] text-muted-foreground">
                    {range.startLabel} → {range.endLabel}
                </span>
            </div>
            <ul className="space-y-px">
                {items.map((it, i) =>
                    it.sep ? (
                        <li
                            key={i}
                            role="separator"
                            className="my-1 h-px bg-border/60"
                        />
                    ) : (
                        <li
                            key={i}
                            role="menuitem"
                            aria-disabled={!it.onClick}
                            onClick={() => {
                                if (!it.onClick) return; // disabled items: leave menu open, do nothing
                                it.onClick();
                                onClose();
                            }}
                            className={cn(
                                'grid grid-cols-[24px_1fr_auto] items-center gap-2.5 rounded-md px-2 py-1.5 text-[12.5px] transition-colors',
                                it.onClick
                                    ? 'cursor-pointer hover:bg-accent'
                                    : 'cursor-not-allowed opacity-50',
                                it.tone === 'primary' && 'text-primary',
                                it.tone === 'critical' &&
                                    'text-status-critical',
                            )}
                        >
                            <span
                                className={cn(
                                    'inline-flex h-[22px] w-[22px] items-center justify-center rounded-md',
                                    it.tone === 'primary'
                                        ? 'bg-primary/15 text-primary'
                                        : it.tone === 'critical'
                                          ? 'bg-status-critical-bg text-status-critical'
                                          : 'bg-muted text-muted-foreground',
                                )}
                            >
                                {it.icon}
                            </span>
                            <span className="min-w-0">
                                <span className="block leading-tight">
                                    {it.label}
                                </span>
                                {it.sub ? (
                                    <span className="mt-0.5 block text-[10.5px] text-muted-foreground">
                                        {it.sub}
                                    </span>
                                ) : null}
                            </span>
                            {it.kbd ? (
                                <span className="rounded border border-border bg-background px-1.5 py-0.5 text-[10px] font-semibold text-muted-foreground">
                                    {it.kbd}
                                </span>
                            ) : null}
                        </li>
                    ),
                )}
            </ul>
        </div>
    );
}

export default WeekPicker;
