/* eslint-disable no-restricted-syntax -- The footer calendar is a bespoke
 * popover: custom day-cell buttons, an agenda list with per-row kebab menus, a
 * floating right-click context menu and a hover preview. These are custom
 * layout surfaces, not shadcn <Button>/<Card> cases, so raw <button>/<div> is
 * intentional. Event colours (shift = --primary, leave/holiday) are injected as
 * CSS variables on the root so they stay token-driven and white-label safe. */
import { router } from '@inertiajs/react';
import {
    CalendarDays,
    CalendarPlus,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    Clock,
    Eye,
    MapPin,
    MoreVertical,
    Trash2,
    Users,
    type LucideIcon,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    type CSSProperties,
} from 'react';
import { toast } from 'sonner';

import { cn } from '@/lib/utils';

import type { MyHrCalendarEvent, MyHrCalendarFeed } from './my-hr-types';

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const WEEKDAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
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

const pad = (n: number) => String(n).padStart(2, '0');
const isoOf = (y: number, m: number, d: number) =>
    `${y}-${pad(m + 1)}-${pad(d)}`;
const monthKeyOf = (y: number, m: number) => `${y}-${pad(m + 1)}`;

function todayIso(): string {
    const d = new Date();
    return isoOf(d.getFullYear(), d.getMonth(), d.getDate());
}

/** Per-type colour + label, all token-driven (see CSS vars on the root). */
const TYPE_META: Record<
    MyHrCalendarEvent['type'],
    { dot: string; bar: string; chip: string; tint: string; label: string }
> = {
    shift: {
        dot: 'bg-primary',
        bar: 'bg-primary',
        chip: 'bg-primary',
        tint: 'bg-primary/[0.07]',
        label: 'Shift',
    },
    leave: {
        dot: 'bg-[color:var(--hr-leave)]',
        bar: 'bg-[color:var(--hr-leave)]',
        chip: 'bg-[color:var(--hr-leave)]',
        tint: 'bg-[color:var(--hr-leave-bg)]',
        label: 'Leave',
    },
    holiday: {
        dot: 'bg-[color:var(--hr-holiday)]',
        bar: 'bg-[color:var(--hr-holiday)]',
        chip: 'bg-[color:var(--hr-holiday)]',
        tint: 'bg-[color:var(--hr-holiday-bg)]',
        label: 'Holiday',
    },
};

const PALETTE: CSSProperties = {
    ['--hr-leave' as string]: 'oklch(0.5 0.13 150)',
    ['--hr-leave-bg' as string]:
        'color-mix(in oklch, oklch(0.5 0.13 150) 11%, var(--card))',
    ['--hr-holiday' as string]: 'oklch(0.62 0.14 70)',
    ['--hr-holiday-bg' as string]:
        'color-mix(in oklch, oklch(0.62 0.14 70) 12%, var(--card))',
};

type MenuEntry =
    | { divider: true }
    | {
          divider?: false;
          label: string;
          icon: LucideIcon;
          onClick: () => void;
          danger?: boolean;
      };

type MenuState = {
    x: number;
    y: number;
    title: string;
    items: MenuEntry[];
} | null;
type HoverState = { iso: string; x: number; y: number } | null;

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

/**
 * The hero footer calendar popover — a month grid (Mon-first, NZ) of the
 * employee's shifts / approved leave / public holidays, an agenda for the
 * selected day, a month+year picker, hover previews and right-click + kebab
 * menus wired to the real My HR routes. Anchored below the calendar pill; the
 * current month is seeded by the shell and other months page in from
 * `GET /hr/my/calendar?month=YYYY-MM`.
 */
export function MyHrCalendar({
    open,
    onClose,
    feed,
}: {
    open: boolean;
    onClose: () => void;
    feed: MyHrCalendarFeed;
}) {
    const today = useMemo(() => todayIso(), []);

    const [anchorY, anchorM] = useMemo(() => {
        const [y, m] = feed.month.split('-').map(Number);
        return [y, (m || 1) - 1];
    }, [feed.month]);

    const [calY, setCalY] = useState(anchorY);
    const [calM, setCalM] = useState(anchorM);
    const [selDate, setSelDate] = useState<string>(() => {
        // Prefer today when it sits in the anchor month, else the 1st.
        const [ty, tm] = today.split('-').map(Number);
        return ty === anchorY && tm - 1 === anchorM
            ? today
            : isoOf(anchorY, anchorM, 1);
    });
    const [pickerOpen, setPickerOpen] = useState(false);
    const [pickerYear, setPickerYear] = useState(anchorY);

    const [eventsByDate, setEventsByDate] = useState<
        Record<string, MyHrCalendarEvent[]>
    >(feed.events);
    const loadedMonths = useRef<Set<string>>(new Set([feed.month]));
    const [loading, setLoading] = useState(false);

    const [menu, setMenu] = useState<MenuState>(null);
    const [hover, setHover] = useState<HoverState>(null);

    // Re-seed if the shell feed changes (e.g. after an Inertia visit).
    useEffect(() => {
        setEventsByDate((prev) => ({ ...prev, ...feed.events }));
        loadedMonths.current.add(feed.month);
    }, [feed]);

    // Lazily fetch a month's grid window the first time it's viewed.
    useEffect(() => {
        if (!open) return;
        const key = monthKeyOf(calY, calM);
        if (loadedMonths.current.has(key)) return;
        let cancelled = false;
        setLoading(true);
        fetch(`/hr/my/calendar?month=${key}`, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        })
            .then((r) => (r.ok ? r.json() : null))
            .then((data: MyHrCalendarFeed | null) => {
                if (cancelled || !data) return;
                loadedMonths.current.add(key);
                setEventsByDate((prev) => ({ ...prev, ...data.events }));
            })
            .catch(() => {})
            .finally(() => {
                if (!cancelled) setLoading(false);
            });
        return () => {
            cancelled = true;
        };
    }, [open, calY, calM]);

    // Esc closes the menu first, then the popover. Reset transient UI on close.
    useEffect(() => {
        if (!open) {
            setMenu(null);
            setHover(null);
            setPickerOpen(false);
            return;
        }
        const onKey = (e: KeyboardEvent) => {
            if (e.key !== 'Escape') return;
            if (menu) setMenu(null);
            else if (pickerOpen) setPickerOpen(false);
            else onClose();
        };
        document.addEventListener('keydown', onKey);
        return () => document.removeEventListener('keydown', onKey);
    }, [open, menu, pickerOpen, onClose]);

    const closeMenu = useCallback(() => setMenu(null), []);
    const openMenuAt = useCallback(
        (x: number, y: number, title: string, items: MenuEntry[]) => {
            const width = 224;
            const height = items.length * 40 + 56;
            setMenu({
                x: Math.max(8, Math.min(x, window.innerWidth - width - 8)),
                y: Math.max(8, Math.min(y, window.innerHeight - height - 8)),
                title,
                items,
            });
        },
        [],
    );

    const prevMonth = () =>
        setCalM((m) => {
            if (m === 0) {
                setCalY((y) => y - 1);
                return 11;
            }
            return m - 1;
        });
    const nextMonth = () =>
        setCalM((m) => {
            if (m === 11) {
                setCalY((y) => y + 1);
                return 0;
            }
            return m + 1;
        });
    const goToday = () => {
        const [ty, tm] = today.split('-').map(Number);
        setCalY(ty);
        setCalM(tm - 1);
        setSelDate(today);
    };

    const selectDay = (iso: string) => {
        const [y, m] = iso.split('-').map(Number);
        setCalY(y);
        setCalM(m - 1);
        setSelDate(iso);
    };

    /* ---- action wiring (real My HR routes) ---- */
    const requestLeaveOn = (iso: string) => {
        router.visit(`/hr/my/leave?date=${iso}`);
    };
    const viewShift = () => router.visit('/hr/my/time');
    const addShiftToCalendar = (refId?: number | null) => {
        if (!refId) {
            toast.info('Calendar export unavailable for this shift');
            return;
        }
        // ICS download (non-Inertia file route) — let the browser handle it.
        window.location.href = `/hr/my/time/shifts/${refId}/calendar`;
        toast.success('Downloading calendar invite');
    };
    const viewLeave = () => router.visit('/hr/my/leave');
    const cancelLeave = (refId?: number | null) => {
        if (!refId) return;
        if (!window.confirm('Cancel this approved leave request?')) return;
        router.delete(`/hr/my/leave/${refId}`, {
            preserveScroll: true,
            onSuccess: () => toast.success('Leave request cancelled'),
            onError: () => toast.error('Could not cancel leave'),
        });
    };

    const openDayMenu = (e: React.MouseEvent, iso: string) => {
        e.preventDefault();
        const [y, m, d] = iso.split('-').map(Number);
        const label = new Date(y, m - 1, d).toLocaleDateString('en-NZ', {
            weekday: 'short',
            day: 'numeric',
            month: 'long',
        });
        const evs = eventsByDate[iso] ?? [];
        const items: MenuEntry[] = [
            {
                label: 'Request leave on this day',
                icon: CalendarDays,
                onClick: () => requestLeaveOn(iso),
            },
        ];
        if (evs.some((ev) => ev.type === 'shift')) {
            items.push({
                label: 'View in roster',
                icon: Eye,
                onClick: viewShift,
            });
        }
        openMenuAt(e.clientX, e.clientY, label, items);
    };

    const openEventMenu = (e: React.MouseEvent, ev: MyHrCalendarEvent) => {
        e.preventDefault();
        const rect = e.currentTarget.getBoundingClientRect();
        const x =
            'clientX' in e && e.clientX ? e.clientX : Math.round(rect.right);
        const y =
            'clientY' in e && e.clientY
                ? e.clientY
                : Math.round(rect.bottom + 4);
        let items: MenuEntry[];
        if (ev.type === 'shift') {
            items = [
                { label: 'View shift details', icon: Eye, onClick: viewShift },
                {
                    label: 'Add to calendar',
                    icon: CalendarPlus,
                    onClick: () => addShiftToCalendar(ev.ref_id),
                },
            ];
        } else if (ev.type === 'leave') {
            items = [
                { label: 'View request', icon: Eye, onClick: viewLeave },
                {
                    label: 'Cancel leave',
                    icon: Trash2,
                    onClick: () => cancelLeave(ev.ref_id),
                    danger: true,
                },
            ];
        } else {
            items = [
                {
                    label: 'View',
                    icon: Eye,
                    onClick: () =>
                        toast.info(ev.title, {
                            description: ev.note ?? 'Public holiday',
                        }),
                },
            ];
        }
        openMenuAt(x, y, ev.title, items);
    };

    /* ---- derived view data ---- */
    const cells = useMemo(() => {
        const first = new Date(calY, calM, 1);
        const startOffset = (first.getDay() + 6) % 7; // Monday-first
        const gridStart = new Date(calY, calM, 1 - startOffset);
        return Array.from({ length: 42 }, (_, i) => {
            const dt = new Date(gridStart);
            dt.setDate(gridStart.getDate() + i);
            const iso = isoOf(dt.getFullYear(), dt.getMonth(), dt.getDate());
            return {
                iso,
                day: dt.getDate(),
                inMonth: dt.getMonth() === calM,
                isToday: iso === today,
                isSel: iso === selDate,
                events: eventsByDate[iso] ?? [],
            };
        });
    }, [calY, calM, selDate, today, eventsByDate]);

    const agenda = eventsByDate[selDate] ?? [];
    const monthLabel = new Date(calY, calM, 1).toLocaleDateString('en-NZ', {
        month: 'long',
        year: 'numeric',
    });
    const selDateParts = selDate.split('-').map(Number);
    const agendaHeading = new Date(
        selDateParts[0],
        selDateParts[1] - 1,
        selDateParts[2],
    ).toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

    if (!open) return null;

    return (
        <div style={PALETTE}>
            {/* outside-click catcher */}
            <button
                type="button"
                aria-label="Close calendar"
                onClick={onClose}
                className="fixed inset-0 z-40 cursor-default bg-black/15"
            />

            <div
                role="dialog"
                aria-label={`Calendar — ${monthLabel}`}
                className="absolute top-full left-[22px] z-50 mt-2.5 flex w-[668px] max-w-[calc(100vw-96px)] animate-in overflow-hidden rounded-2xl border border-border bg-popover text-popover-foreground shadow-[0_34px_80px_-24px_rgba(20,10,40,0.5)] duration-200 fade-in-0 slide-in-from-top-1 motion-reduce:animate-none"
            >
                {/* ---- month grid pane ---- */}
                <div className="relative w-[348px] flex-none border-r border-border p-[18px_18px_14px]">
                    <div className="mb-3.5 flex items-center justify-between">
                        <div className="flex items-center gap-1">
                            <GridNavButton
                                label="Previous month"
                                onClick={prevMonth}
                            >
                                <ChevronLeft className="h-[15px] w-[15px]" />
                            </GridNavButton>
                            <GridNavButton
                                label="Next month"
                                onClick={nextMonth}
                            >
                                <ChevronRight className="h-[15px] w-[15px]" />
                            </GridNavButton>
                        </div>
                        <button
                            type="button"
                            onClick={() => {
                                setPickerYear(calY);
                                setPickerOpen((v) => !v);
                            }}
                            aria-expanded={pickerOpen}
                            aria-haspopup="true"
                            className="inline-flex items-center gap-1.5 rounded-md px-1.5 py-1 text-[14.5px] font-bold tracking-tight transition-colors hover:bg-muted"
                        >
                            {monthLabel}
                            <ChevronDown className="h-3 w-3" />
                        </button>
                        <button
                            type="button"
                            onClick={goToday}
                            className="rounded-md px-1.5 py-1 text-[11.5px] font-semibold text-primary transition-colors hover:bg-muted"
                        >
                            Today
                        </button>
                    </div>

                    {/* month + year picker overlay */}
                    {pickerOpen ? (
                        <div className="absolute inset-x-3 top-[52px] bottom-3 z-[6] rounded-xl bg-popover p-1.5 shadow-[0_18px_44px_-14px_rgba(20,10,40,0.4)]">
                            <div className="mb-2.5 flex items-center justify-between px-0.5 py-1">
                                <GridNavButton
                                    label="Previous year"
                                    onClick={() => setPickerYear((y) => y - 1)}
                                >
                                    <ChevronLeft className="h-[15px] w-[15px]" />
                                </GridNavButton>
                                <span className="text-[15px] font-bold tabular-nums">
                                    {pickerYear}
                                </span>
                                <GridNavButton
                                    label="Next year"
                                    onClick={() => setPickerYear((y) => y + 1)}
                                >
                                    <ChevronRight className="h-[15px] w-[15px]" />
                                </GridNavButton>
                            </div>
                            <div className="grid grid-cols-3 gap-1.5">
                                {MONTHS_SHORT.map((label, i) => {
                                    const active =
                                        i === calM && pickerYear === calY;
                                    return (
                                        <button
                                            key={label}
                                            type="button"
                                            onClick={() => {
                                                setCalY(pickerYear);
                                                setCalM(i);
                                                setPickerOpen(false);
                                            }}
                                            className={cn(
                                                'rounded-lg py-2.5 text-[12.5px] font-semibold transition-colors',
                                                active
                                                    ? 'bg-primary text-primary-foreground'
                                                    : 'text-foreground hover:bg-muted',
                                            )}
                                        >
                                            {label}
                                        </button>
                                    );
                                })}
                            </div>
                        </div>
                    ) : null}

                    {/* weekday header */}
                    <div className="mb-1 grid grid-cols-7 gap-0.5">
                        {WEEKDAYS.map((w) => (
                            <span
                                key={w}
                                className="text-center text-[10px] font-bold tracking-wide text-muted-foreground uppercase"
                            >
                                {w}
                            </span>
                        ))}
                    </div>

                    {/* day cells */}
                    <div className="grid grid-cols-7 gap-0.5">
                        {cells.map((cell) => (
                            <button
                                key={cell.iso}
                                type="button"
                                onClick={() => selectDay(cell.iso)}
                                onContextMenu={(e) => openDayMenu(e, cell.iso)}
                                onMouseEnter={(e) => {
                                    const r =
                                        e.currentTarget.getBoundingClientRect();
                                    setHover({
                                        iso: cell.iso,
                                        x: Math.round(r.left + r.width / 2),
                                        y: Math.round(r.top),
                                    });
                                }}
                                onMouseLeave={() =>
                                    setHover((h) =>
                                        h?.iso === cell.iso ? null : h,
                                    )
                                }
                                className={cn(
                                    'flex h-[38px] flex-col items-center justify-center rounded-[9px] transition-colors',
                                    cell.isSel
                                        ? 'bg-primary'
                                        : 'hover:bg-muted',
                                    cell.isToday &&
                                        !cell.isSel &&
                                        'shadow-[inset_0_0_0_1.5px_var(--primary)]',
                                )}
                            >
                                <span
                                    className={cn(
                                        'text-[12.5px] leading-none',
                                        cell.isSel
                                            ? 'font-bold text-primary-foreground'
                                            : !cell.inMonth
                                              ? 'font-medium text-muted-foreground/55'
                                              : cell.isToday
                                                ? 'font-bold text-primary'
                                                : 'font-medium text-foreground',
                                    )}
                                >
                                    {cell.day}
                                </span>
                                <span className="mt-[3px] flex h-[5px] items-center justify-center gap-[3px]">
                                    {cell.events.slice(0, 3).map((ev, i) => (
                                        <span
                                            key={i}
                                            className={cn(
                                                'h-[5px] w-[5px] rounded-full',
                                                cell.isSel
                                                    ? 'bg-primary-foreground'
                                                    : TYPE_META[ev.type].dot,
                                            )}
                                        />
                                    ))}
                                </span>
                            </button>
                        ))}
                    </div>

                    {/* legend */}
                    <div className="mt-3.5 flex gap-3.5 border-t border-border pt-3">
                        <LegendDot className="bg-primary" label="Shift" />
                        <LegendDot
                            className="bg-[color:var(--hr-leave)]"
                            label="Leave"
                        />
                        <LegendDot
                            className="bg-[color:var(--hr-holiday)]"
                            label="Holiday"
                        />
                        {loading ? (
                            <span className="ml-auto text-[10.5px] text-muted-foreground">
                                Loading…
                            </span>
                        ) : null}
                    </div>
                </div>

                {/* ---- agenda pane ---- */}
                <div className="max-h-[372px] min-w-[250px] flex-1 overflow-y-auto p-[18px_20px]">
                    <div className="mb-3.5 flex items-center gap-2">
                        <h3 className="text-sm font-bold tracking-tight">
                            {agendaHeading}
                        </h3>
                        {selDate === today ? (
                            <span className="rounded-full bg-primary/10 px-[7px] py-0.5 text-[9.5px] font-bold tracking-wide text-primary uppercase">
                                Today
                            </span>
                        ) : null}
                    </div>

                    {agenda.length === 0 ? (
                        <div className="flex flex-col items-center justify-center px-3 py-9 text-center text-muted-foreground">
                            <CalendarDays className="mb-2.5 h-[30px] w-[30px] opacity-50" />
                            <p className="text-[13px] font-semibold text-foreground/80">
                                Nothing scheduled
                            </p>
                            <p className="mt-1 text-[11.5px]">
                                A clear day — ka pai.
                            </p>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-2.5">
                            {agenda.map((ev, i) => {
                                const meta = TYPE_META[ev.type];
                                return (
                                    <div
                                        key={i}
                                        onContextMenu={(e) =>
                                            openEventMenu(e, ev)
                                        }
                                        className={cn(
                                            'flex gap-3 rounded-xl p-[12px_13px]',
                                            meta.tint,
                                        )}
                                    >
                                        <div
                                            className={cn(
                                                'w-1 flex-none rounded-full',
                                                meta.bar,
                                            )}
                                        />
                                        <div className="min-w-0 flex-1">
                                            <div className="flex items-center justify-between gap-2">
                                                <span className="text-[13.5px] font-bold">
                                                    {ev.title}
                                                </span>
                                                <div className="flex flex-none items-center gap-1.5">
                                                    <span
                                                        className={cn(
                                                            'rounded-full px-[7px] py-0.5 text-[9.5px] font-bold tracking-wide text-primary-foreground uppercase',
                                                            meta.chip,
                                                        )}
                                                    >
                                                        {meta.label}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        aria-label={`Actions for ${ev.title}`}
                                                        aria-haspopup="menu"
                                                        onClick={(e) =>
                                                            openEventMenu(e, ev)
                                                        }
                                                        className="inline-flex h-6 w-6 items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-muted"
                                                    >
                                                        <MoreVertical className="h-[15px] w-[15px]" />
                                                    </button>
                                                </div>
                                            </div>
                                            {ev.time ? (
                                                <div className="mt-1.5 inline-flex items-center gap-1.5 text-xs font-semibold text-foreground/80">
                                                    <Clock className="h-[13px] w-[13px]" />
                                                    {ev.time}
                                                </div>
                                            ) : null}
                                            {ev.site ? (
                                                <div className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    <MapPin className="h-[13px] w-[13px]" />
                                                    {ev.site}
                                                </div>
                                            ) : null}
                                            {ev.colleagues ? (
                                                <div className="mt-1 flex items-center gap-1.5 text-xs text-muted-foreground">
                                                    <Users className="h-[13px] w-[13px]" />
                                                    {ev.colleagues}
                                                </div>
                                            ) : null}
                                            {ev.note ? (
                                                <div
                                                    className={cn(
                                                        'mt-1.5 text-[11.5px] font-semibold',
                                                        ev.type === 'shift'
                                                            ? 'text-primary'
                                                            : ev.type ===
                                                                'leave'
                                                              ? 'text-[color:var(--hr-leave)]'
                                                              : 'text-[color:var(--hr-holiday)]',
                                                    )}
                                                >
                                                    {ev.note}
                                                </div>
                                            ) : null}
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>

            {/* ---- hover preview ---- */}
            {hover && !pickerOpen && !menu ? (
                <HoverPreview
                    state={hover}
                    events={eventsByDate[hover.iso] ?? []}
                />
            ) : null}

            {/* ---- right-click / kebab context menu ---- */}
            {menu ? (
                <>
                    <button
                        type="button"
                        aria-label="Close menu"
                        onClick={closeMenu}
                        onContextMenu={(e) => {
                            e.preventDefault();
                            closeMenu();
                        }}
                        className="fixed inset-0 z-[60] cursor-default"
                    />
                    <div
                        role="menu"
                        aria-label={menu.title}
                        style={{ left: menu.x, top: menu.y }}
                        className="fixed z-[61] min-w-[214px] animate-in rounded-xl border border-border bg-popover p-1.5 shadow-[0_22px_52px_-16px_rgba(20,10,40,0.42)] duration-150 fade-in-0 zoom-in-95 motion-reduce:animate-none"
                    >
                        <div className="mb-1 border-b border-border px-[9px] pt-[7px] pb-2 text-[10.5px] font-bold tracking-wide text-muted-foreground uppercase">
                            {menu.title}
                        </div>
                        {menu.items.map((item, i) =>
                            'divider' in item && item.divider ? (
                                <div
                                    key={i}
                                    className="mx-1.5 my-1 h-px bg-border"
                                />
                            ) : (
                                <button
                                    key={i}
                                    type="button"
                                    role="menuitem"
                                    onClick={() => {
                                        closeMenu();
                                        (
                                            item as { onClick: () => void }
                                        ).onClick();
                                    }}
                                    className={cn(
                                        'flex w-full items-center gap-2.5 rounded-lg px-[9px] py-2 text-left text-[12.5px] font-semibold transition-colors hover:bg-muted',
                                        (item as { danger?: boolean }).danger
                                            ? 'text-status-critical'
                                            : 'text-foreground',
                                    )}
                                >
                                    {(() => {
                                        const Icon = (
                                            item as { icon: LucideIcon }
                                        ).icon;
                                        return (
                                            <Icon className="h-[15px] w-[15px]" />
                                        );
                                    })()}
                                    {(item as { label: string }).label}
                                </button>
                            ),
                        )}
                    </div>
                </>
            ) : null}
        </div>
    );
}

function GridNavButton({
    label,
    onClick,
    children,
}: {
    label: string;
    onClick: () => void;
    children: React.ReactNode;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            onClick={onClick}
            className="inline-flex h-7 w-7 items-center justify-center rounded-lg border border-border text-muted-foreground transition-colors hover:bg-muted"
        >
            {children}
        </button>
    );
}

function LegendDot({ className, label }: { className: string; label: string }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-[10.5px] font-semibold text-muted-foreground">
            <span className={cn('h-[7px] w-[7px] rounded-full', className)} />
            {label}
        </span>
    );
}

function HoverPreview({
    state,
    events,
}: {
    state: { iso: string; x: number; y: number };
    events: MyHrCalendarEvent[];
}) {
    const [y, m, d] = state.iso.split('-').map(Number);
    const heading = new Date(y, m - 1, d).toLocaleDateString('en-NZ', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
    return (
        <div
            style={{ left: state.x, top: state.y }}
            className="pointer-events-none fixed z-[70] max-w-[240px] min-w-[148px] -translate-x-1/2 -translate-y-[calc(100%+9px)] rounded-[10px] bg-[oklch(0.19_0.02_277)] p-[9px_11px] text-primary-foreground shadow-[0_16px_38px_-12px_rgba(0,0,0,0.55)]"
        >
            <div className="mb-1.5 text-[10px] font-bold tracking-wide text-primary-foreground/60 uppercase">
                {heading}
            </div>
            {events.length === 0 ? (
                <div className="text-xs text-primary-foreground/75">
                    Nothing scheduled
                </div>
            ) : (
                events.map((ev, i) => (
                    <div
                        key={i}
                        className="mt-[3px] flex items-center gap-1.5 text-xs"
                    >
                        <span
                            className={cn(
                                'h-1.5 w-1.5 flex-none rounded-full',
                                TYPE_META[ev.type].dot,
                            )}
                        />
                        <span className="font-semibold">{ev.title}</span>
                        {ev.time || ev.note ? (
                            <span className="text-primary-foreground/65">
                                {ev.time ?? ev.note}
                            </span>
                        ) : null}
                    </div>
                ))
            )}
        </div>
    );
}

export default MyHrCalendar;
