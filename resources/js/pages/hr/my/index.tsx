/* eslint-disable no-restricted-syntax -- The Overview "delight" surfaces use
 * bespoke tinted feature cards, a live shift timeline, reaction/CTA pills and
 * worklist rows sized to the design handoff; the shadcn <Button> can't express
 * these on-tint layouts. Every colour maps to a semantic token or a decorative
 * identity hue, as elsewhere in My HR. */
import { ApplicableProceduresPanel, type ApplicableProcedure } from '@/components/health-safety/applicable-procedures-panel';
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    CalendarPlus,
    CheckCircle2,
    ChevronRight,
    Clock,
    Leaf,
    MapPin,
    Megaphone,
    MessagesSquare,
    PartyPopper,
    PenLine,
    ShieldCheck,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';

import {
    MyHrAroundModal,
    MyHrLeaveWizard,
    MyHrShell,
    MyHrShoutoutSpotlight,
    hueFromId,
    useSendKudos,
    type AroundAnnouncement,
    type AroundCelebration,
    type AroundView,
    type AroundWhosOut,
    type LeaveBalanceLite,
    type MyHrShellData,
    type MyHrShoutout,
} from '@/components/hr';
import {
    ShiftContextMenu,
    type ShiftCtxState,
} from '@/components/rostering/shift-context-menu';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { fireConfetti } from '@/lib/confetti';
import { cn } from '@/lib/utils';

/* ------------------------------------------------------------------ */
/*  Types                                                              */
/* ------------------------------------------------------------------ */

type AttentionTone = 'critical' | 'warning' | 'info';

interface AttentionItem {
    id: string;
    tone: AttentionTone;
    icon: 'pen' | 'shield' | 'message' | 'alert';
    label: string;
    meta: string;
    badge: string;
    cta: string;
    go: string;
}

interface LeaveBalanceItem {
    leave_type: string;
    label: string;
    remaining_days: number;
    frac: number;
    token: string;
}

interface Colleague {
    id: number;
    name: string;
    first_name: string;
    initials: string;
}

interface TodayShift {
    id: number;
    starts_at: string | null;
    ends_at: string | null;
    site: string;
    shift_type: string;
}

interface Props {
    myHr: MyHrShellData;
    overview: {
        shoutouts: MyHrShoutout[];
        attention: AttentionItem[];
        leaveBalance: LeaveBalanceItem[];
        celebrations: AroundCelebration[];
        whosOut: AroundWhosOut[];
        todayShift: TodayShift | null;
        shiftColleagues: Colleague[];
        streak: number;
    };
    announcements: AroundAnnouncement[];
    balances: LeaveBalanceLite[];
    canViewFeed?: boolean;
    safeWorkProcedures?: ApplicableProcedure[];
}

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

const ATTENTION_ICON = {
    pen: PenLine,
    shield: ShieldCheck,
    message: MessagesSquare,
    alert: AlertTriangle,
} as const;

const ATTENTION_TONE: Record<
    AttentionTone,
    { chip: string; variant: StatusVariant; ctxBg: string; ctxColor: string }
> = {
    critical: {
        chip: 'bg-status-critical-bg text-status-critical',
        variant: 'critical',
        ctxBg: 'var(--status-critical-bg)',
        ctxColor: 'var(--status-critical)',
    },
    warning: {
        chip: 'bg-status-warning-bg text-status-warning',
        variant: 'warning',
        ctxBg: 'var(--status-warning-bg)',
        ctxColor: 'var(--status-warning)',
    },
    info: {
        chip: 'bg-status-info-bg text-status-info',
        variant: 'info',
        ctxBg: 'var(--status-info-bg)',
        ctxColor: 'var(--status-info)',
    },
};

/** Soft tinted surface (token-based, no raw hex). */
function tint(token: string, bgPct: number, borderPct: number) {
    return {
        background: `color-mix(in oklch, var(${token}) ${bgPct}%, var(--card))`,
        borderColor: `color-mix(in oklch, var(${token}) ${borderPct}%, var(--border))`,
    };
}

/** 12-hour clock label, e.g. "7:00a". */
function fmt12(iso: string | null | undefined): string {
    if (!iso) return '';
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return '';
    let h = d.getHours();
    const m = d.getMinutes();
    const ap = h < 12 ? 'a' : 'p';
    h = h % 12 === 0 ? 12 : h % 12;
    return `${h}:${String(m).padStart(2, '0')}${ap}`;
}

function shiftTypeLabel(shift: TodayShift): string {
    if (shift.shift_type === 'sleepover') return 'Sleepover';
    if (shift.shift_type === 'on_call') return 'On-call';
    const start = shift.starts_at ? new Date(shift.starts_at).getHours() : 9;
    return start < 12 ? 'Day support' : 'Evening support';
}

/* ------------------------------------------------------------------ */
/*  Next-shift feature card                                            */
/* ------------------------------------------------------------------ */

function NextShiftCard({
    shift,
    onToday,
    clockedIn,
    colleagues,
}: {
    shift: TodayShift | null;
    onToday: boolean;
    clockedIn: boolean;
    colleagues: Colleague[];
}) {
    const [now, setNow] = useState(() => Date.now());
    useEffect(() => {
        const id = setInterval(() => setNow(Date.now()), 30_000);
        return () => clearInterval(id);
    }, []);

    const start = shift?.starts_at ? new Date(shift.starts_at).getTime() : null;
    const end = shift?.ends_at ? new Date(shift.ends_at).getTime() : null;
    const hasWindow = start != null && end != null && end > start;
    const inWindow = hasWindow && now >= start! && now <= end!;
    const live = clockedIn || (onToday && inWindow);
    const showTimeline = onToday && hasWindow;
    const nowPct = hasWindow
        ? Math.max(0, Math.min(100, ((now - start!) / (end! - start!)) * 100))
        : 0;
    const midIso =
        hasWindow ? new Date(start! + (end! - start!) / 2).toISOString() : null;

    const durationH = hasWindow
        ? Math.round(((end! - start!) / 3_600_000) * 10) / 10
        : null;

    return (
        <div
            className="relative overflow-hidden rounded-[20px] border px-6 py-[22px]"
            style={{
                background:
                    'linear-gradient(140deg, color-mix(in oklch, var(--primary) 8%, var(--card)), var(--card) 58%)',
                borderColor:
                    'color-mix(in oklch, var(--primary) 20%, var(--border))',
                boxShadow:
                    '0 4px 20px -10px color-mix(in oklch, var(--primary) 45%, transparent)',
            }}
        >
            <div className="flex items-center gap-2.5">
                <span className="grid h-8 w-8 shrink-0 place-items-center rounded-[10px] bg-primary/15 text-primary">
                    <MapPin className="h-4 w-4" />
                </span>
                <div className="min-w-0">
                    <div className="text-[10px] font-bold uppercase tracking-[0.08em] text-muted-foreground">
                        Your day
                    </div>
                    <h2 className="mt-px truncate text-[16px] font-bold">
                        {shift
                            ? onToday
                                ? `On shift today · ${shift.site}`
                                : `Next shift · ${shift.site}`
                            : 'No upcoming shifts'}
                    </h2>
                </div>
                {live ? (
                    <span className="ml-auto inline-flex items-center gap-1.5 rounded-full bg-live-bg px-2.5 py-1 text-[11px] font-bold text-live">
                        <span className="h-1.5 w-1.5 rounded-full bg-live" /> In
                        progress
                    </span>
                ) : null}
            </div>

            {shift && hasWindow ? (
                <>
                    <div className="mt-4 flex items-baseline gap-3">
                        <div className="text-[30px] font-bold tabular-nums tracking-tight">
                            {fmt12(shift.starts_at)} – {fmt12(shift.ends_at)}
                        </div>
                        <div className="text-[13px] font-semibold text-muted-foreground">
                            {durationH}h · {shiftTypeLabel(shift)}
                        </div>
                    </div>

                    {showTimeline ? (
                        <div className="relative mt-4 pt-[22px]">
                            <div
                                className="absolute top-0 -translate-x-1/2 whitespace-nowrap"
                                style={{ left: `${nowPct}%` }}
                            >
                                <span className="inline-block rounded-md bg-primary px-1.5 py-0.5 text-[10px] font-bold text-primary-foreground">
                                    Now {fmt12(new Date(now).toISOString())}
                                </span>
                            </div>
                            <div className="relative h-2 rounded-full bg-muted">
                                <div
                                    className="absolute inset-y-0 left-0 rounded-full"
                                    style={{
                                        width: `${nowPct}%`,
                                        background:
                                            'linear-gradient(90deg, color-mix(in oklch, var(--primary) 70%, var(--card)), var(--primary))',
                                    }}
                                />
                                <div
                                    className="absolute top-1/2 h-4 w-4 -translate-x-1/2 -translate-y-1/2 rounded-full border-[3px] border-primary bg-card shadow"
                                    style={{ left: `${nowPct}%` }}
                                />
                            </div>
                            <div className="relative mt-2.5 h-8">
                                <div className="absolute left-0 text-left">
                                    <div className="text-[12px] font-bold">
                                        {fmt12(shift.starts_at)}
                                    </div>
                                    <div className="text-[10.5px] text-muted-foreground">
                                        Clock in {clockedIn || now >= start! ? '✓' : ''}
                                    </div>
                                </div>
                                <div className="absolute left-1/2 -translate-x-1/2 text-center">
                                    <div className="text-[12px] font-bold">
                                        {fmt12(midIso)}
                                    </div>
                                    <div className="text-[10.5px] text-muted-foreground">
                                        Break
                                    </div>
                                </div>
                                <div className="absolute right-0 text-right">
                                    <div className="text-[12px] font-bold">
                                        {fmt12(shift.ends_at)}
                                    </div>
                                    <div className="text-[10.5px] text-muted-foreground">
                                        Clock out
                                    </div>
                                </div>
                            </div>
                        </div>
                    ) : (
                        <p className="mt-3 text-[12.5px] text-muted-foreground">
                            Starts{' '}
                            {shift.starts_at
                                ? new Date(shift.starts_at).toLocaleDateString('en-NZ', {
                                      weekday: 'long',
                                  })
                                : 'soon'}
                            .
                        </p>
                    )}

                    <div className="mt-5 flex flex-wrap items-center gap-3">
                        {colleagues.length > 0 ? (
                            <div className="flex items-center gap-2">
                                <div className="flex">
                                    {colleagues.slice(0, 3).map((c, i) => (
                                        <span
                                            key={c.id}
                                            className="grid h-[30px] w-[30px] place-items-center rounded-full border-2 border-card text-[11px] font-bold text-white"
                                            style={{
                                                marginLeft: i === 0 ? 0 : -9,
                                                background: `oklch(0.62 0.17 ${hueFromId(c.id)})`,
                                            }}
                                        >
                                            {c.initials}
                                        </span>
                                    ))}
                                </div>
                                <span className="text-[12.5px] text-muted-foreground">
                                    On with{' '}
                                    {colleagues
                                        .slice(0, 2)
                                        .map((c) => c.first_name)
                                        .join(' & ')}
                                    {colleagues.length > 2
                                        ? ` +${colleagues.length - 2}`
                                        : ''}
                                </span>
                            </div>
                        ) : (
                            <span className="text-[12.5px] text-muted-foreground">
                                You’re on solo today.
                            </span>
                        )}
                        <div className="ml-auto flex gap-2">
                            <a
                                href={`/hr/my/time/shifts/${shift.id}/calendar`}
                                className="inline-flex items-center gap-1.5 rounded-[10px] border border-border bg-card px-3 py-2 text-[12.5px] font-semibold transition-colors hover:bg-muted"
                            >
                                <CalendarPlus className="h-3.5 w-3.5" /> Add to calendar
                            </a>
                            <button
                                type="button"
                                onClick={() => router.visit('/hr/my/time')}
                                className="rounded-[10px] bg-primary px-4 py-2 text-[12.5px] font-bold text-primary-foreground transition-colors hover:opacity-90"
                            >
                                View roster
                            </button>
                        </div>
                    </div>
                </>
            ) : (
                <div className="mt-4 flex flex-col items-start gap-3">
                    <p className="text-[13px] text-muted-foreground">
                        Nothing rostered right now — enjoy the breather. 🌿
                    </p>
                    <button
                        type="button"
                        onClick={() => router.visit('/hr/my/time')}
                        className="rounded-[10px] border border-border bg-card px-3 py-2 text-[12.5px] font-semibold transition-colors hover:bg-muted"
                    >
                        View roster
                    </button>
                </div>
            )}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function MyHrIndex({
    myHr,
    overview,
    announcements,
    balances,
    canViewFeed = false,
    safeWorkProcedures = [],
}: Props) {
    const openKudos = useSendKudos();
    const { weekly } = myHr;

    const [cleared, setCleared] = useState<Set<string>>(new Set());
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [congrats, setCongrats] = useState<Set<number>>(new Set());
    const [acked, setAcked] = useState<Set<number>>(new Set());
    const [aroundView, setAroundView] = useState<AroundView | null>(null);
    const [leaveOpen, setLeaveOpen] = useState(false);

    const visibleAttention = overview.attention.filter((a) => !cleared.has(a.id));

    function clearAttention(id: string) {
        setCleared((prev) => {
            const next = new Set(prev);
            next.add(id);
            if (overview.attention.every((a) => next.has(a.id))) {
                toast.success('Inbox zero! 🎉', {
                    description: "Every action cleared. You're all caught up.",
                });
                fireConfetti();
            }
            return next;
        });
    }

    function openAttentionCtx(e: React.MouseEvent, a: AttentionItem) {
        e.preventDefault();
        const tone = ATTENTION_TONE[a.tone];
        setCtx({
            x: e.clientX,
            y: e.clientY,
            tag: a.badge,
            tagBg: tone.ctxBg,
            tagColor: tone.ctxColor,
            meta: a.label,
            items: [
                {
                    icon: <MessagesSquare className="h-4 w-4" />,
                    label: 'View details',
                    onClick: () => router.visit(`/hr/my/${a.go}`),
                },
                {
                    icon: <CheckCircle2 className="h-4 w-4" />,
                    label: 'Mark as done',
                    kbd: 'D',
                    onClick: () => clearAttention(a.id),
                },
                {
                    icon: <Clock className="h-4 w-4" />,
                    label: 'Snooze 1 day',
                    onClick: () =>
                        toast.info('Snoozed 😴', {
                            description: "We'll remind you tomorrow.",
                        }),
                },
                {
                    icon: <X className="h-4 w-4" />,
                    label: 'Dismiss',
                    tone: 'critical',
                    onClick: () => clearAttention(a.id),
                },
            ],
        });
    }

    function congratulate(c: AroundCelebration) {
        if (congrats.has(c.user_id)) return;
        router.post(
            '/hr/my/kudos',
            {
                to_user_id: c.user_id,
                category: 'other',
                message: `${c.name.split(' ')[0]} — ${c.occasion.replace(/^[^\w]+/, '')}. Ngā mihi from the team! 🎉`,
            },
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setCongrats((p) => new Set(p).add(c.user_id));
                    toast.success('Kudos sent 🎉', {
                        description: `You congratulated ${c.name.split(' ')[0]}. That'll make their day.`,
                    });
                },
                onError: () => toast.error('Could not send kudos'),
            },
        );
    }

    function acknowledge(id: number) {
        router.post(
            `/hr/announcements/${id}/acknowledge`,
            {},
            {
                preserveScroll: true,
                preserveState: true,
                onSuccess: () => {
                    setAcked((p) => new Set(p).add(id));
                    toast.info('Acknowledged ✓', {
                        description: "Thanks — noted that you've seen it.",
                    });
                },
                onError: () => toast.error('Could not acknowledge'),
            },
        );
    }

    const workedPct = Math.min(
        100,
        weekly.target_hours > 0
            ? (weekly.total_hours / weekly.target_hours) * 100
            : 0,
    );
    const ringCirc = 2 * Math.PI * 40;

    const today = overview.todayShift;
    const shiftForCard: TodayShift | null = today
        ? today
        : myHr.nextShift
          ? {
                id: myHr.nextShift.id,
                starts_at: myHr.nextShift.starts_at,
                ends_at: myHr.nextShift.ends_at,
                site:
                    myHr.nextShift.location ??
                    myHr.nextShift.service_context_name ??
                    'Shift',
                shift_type: 'standard',
            }
          : null;

    const me = {
        initials: myHr.profile.initials,
        firstName: myHr.profile.first_name,
    };

    return (
        <MyHrShell
            active="overview"
            myHr={myHr}
            heroHandlers={{ onRequestLeave: () => setLeaveOpen(true) }}
        >
            <div className="flex flex-col gap-5">
                {/* ── Row 1 · Your day ── */}
                <div className="grid gap-4 lg:grid-cols-[1.7fr_1fr]">
                    <NextShiftCard
                        shift={shiftForCard}
                        onToday={!!today}
                        clockedIn={!!myHr.activeClock}
                        colleagues={overview.shiftColleagues}
                    />

                    {/* This week ring */}
                    <div
                        className="rounded-[20px] border p-[22px]"
                        style={tint('--primary', 4, 14)}
                    >
                        <div className="flex items-center gap-2.5">
                            <span className="grid h-[30px] w-[30px] place-items-center rounded-[9px] bg-primary/15 text-primary">
                                <Clock className="h-4 w-4" />
                            </span>
                            <h2 className="text-[15px] font-bold">This week</h2>
                            <span className="ml-auto text-[11px] text-muted-foreground">
                                Mon–Sun
                            </span>
                        </div>
                        <div className="mt-3.5 flex items-center gap-[18px]">
                            <div className="relative h-24 w-24 shrink-0">
                                <svg viewBox="0 0 96 96" className="h-24 w-24 -rotate-90">
                                    <circle
                                        cx="48"
                                        cy="48"
                                        r="40"
                                        fill="none"
                                        stroke="var(--muted)"
                                        strokeWidth="9"
                                    />
                                    <circle
                                        cx="48"
                                        cy="48"
                                        r="40"
                                        fill="none"
                                        stroke="var(--primary)"
                                        strokeWidth="9"
                                        strokeLinecap="round"
                                        strokeDasharray={ringCirc}
                                        strokeDashoffset={ringCirc * (1 - workedPct / 100)}
                                        className="transition-[stroke-dashoffset] duration-700"
                                    />
                                </svg>
                                <div className="absolute inset-0 flex flex-col items-center justify-center">
                                    <span className="text-xl font-bold leading-none">
                                        {weekly.total_hours.toFixed(1)}
                                    </span>
                                    <span className="text-[10px] text-muted-foreground">
                                        of {weekly.target_hours}h
                                    </span>
                                </div>
                            </div>
                            <div className="min-w-0 flex-1">
                                <div className="flex items-center gap-2 text-[12.5px]">
                                    <span className="h-2 w-2 rounded-full bg-primary" />
                                    {weekly.total_hours.toFixed(1)}h worked
                                </div>
                                <div className="mt-1.5 flex items-center gap-2 text-[12.5px] text-muted-foreground">
                                    <span className="h-2 w-2 rounded-full bg-muted" />
                                    {Math.max(
                                        0,
                                        weekly.target_hours - weekly.total_hours,
                                    ).toFixed(1)}
                                    h remaining
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                {/* ── Shout-out spotlight ── */}
                {overview.shoutouts.length > 0 ? (
                    <MyHrShoutoutSpotlight
                        shoutouts={overview.shoutouts}
                        perspective="received"
                        me={me}
                        onGiveShoutout={openKudos}
                    />
                ) : null}

                {/* ── Row 2 · Needs you + Leave balance ── */}
                <div className="grid gap-4 lg:grid-cols-[1.55fr_1fr]">
                    {/* Needs your attention */}
                    <div className="overflow-hidden rounded-[20px] border border-border bg-card shadow-[0_2px_14px_-8px_rgba(40,30,70,0.18)]">
                        <div className="flex items-center gap-2.5 px-[18px] pb-3 pt-4">
                            <span className="grid h-[30px] w-[30px] place-items-center rounded-[9px] bg-accent text-primary">
                                <Bell className="h-4 w-4" />
                            </span>
                            <div>
                                <h2 className="text-[15px] font-bold">
                                    Needs your attention
                                </h2>
                                <p className="text-[11.5px] text-muted-foreground">
                                    Right-click any item for quick actions
                                </p>
                            </div>
                            <span className="ml-auto text-[11px] font-bold text-muted-foreground">
                                {visibleAttention.length} open
                            </span>
                        </div>

                        {visibleAttention.length === 0 ? (
                            <div className="px-[18px] pb-9 pt-6 text-center">
                                <div className="mx-auto mb-3 grid h-[52px] w-[52px] place-items-center rounded-full bg-status-success-bg text-status-success">
                                    <CheckCircle2 className="h-6 w-6" />
                                </div>
                                <div className="text-[15px] font-bold">
                                    Inbox zero — ka pai!
                                </div>
                                <div className="mt-0.5 text-[12.5px] text-muted-foreground">
                                    Nothing needs you right now. Go enjoy your shift.
                                </div>
                            </div>
                        ) : (
                            <div className="px-2 pb-2">
                                {visibleAttention.map((a) => {
                                    const Icon = ATTENTION_ICON[a.icon];
                                    const tone = ATTENTION_TONE[a.tone];
                                    return (
                                        <div
                                            key={a.id}
                                            onContextMenu={(e) => openAttentionCtx(e, a)}
                                            className="flex items-center gap-3 rounded-[11px] px-2.5 py-2.5 transition-colors hover:bg-muted"
                                        >
                                            <span
                                                className={cn(
                                                    'grid h-[34px] w-[34px] shrink-0 place-items-center rounded-[9px]',
                                                    tone.chip,
                                                )}
                                            >
                                                <Icon className="h-4 w-4" />
                                            </span>
                                            <div className="min-w-0 flex-1">
                                                <div className="text-[13px] font-semibold leading-tight">
                                                    {a.label}
                                                </div>
                                                <div className="text-[11.5px] text-muted-foreground">
                                                    {a.meta}
                                                </div>
                                            </div>
                                            <StatusBadge
                                                variant={tone.variant}
                                                size="sm"
                                                className="hidden sm:inline-flex"
                                            >
                                                {a.badge}
                                            </StatusBadge>
                                            <button
                                                type="button"
                                                onClick={() => router.visit(`/hr/my/${a.go}`)}
                                                className="shrink-0 rounded-lg border border-border bg-card px-2.5 py-1.5 text-xs font-semibold text-primary transition-colors hover:bg-accent"
                                            >
                                                {a.cta}
                                            </button>
                                            <button
                                                type="button"
                                                onClick={() => clearAttention(a.id)}
                                                aria-label="Dismiss"
                                                title="Dismiss"
                                                className="grid h-[26px] w-[26px] shrink-0 place-items-center rounded-md text-muted-foreground transition-colors hover:bg-muted"
                                            >
                                                <X className="h-[15px] w-[15px]" />
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>

                    {/* Leave balance */}
                    <div
                        className="rounded-[20px] border p-[22px]"
                        style={{
                            background:
                                'linear-gradient(140deg, color-mix(in oklch, var(--category-hr) 6%, var(--card)), var(--card) 60%)',
                            borderColor:
                                'color-mix(in oklch, var(--category-hr) 18%, var(--border))',
                        }}
                    >
                        <div className="flex items-center gap-2.5">
                            <span
                                className="grid h-[30px] w-[30px] place-items-center rounded-[9px]"
                                style={{
                                    background:
                                        'color-mix(in oklch, var(--category-hr) 16%, var(--card))',
                                    color: 'var(--category-hr)',
                                }}
                            >
                                <Leaf className="h-4 w-4" />
                            </span>
                            <h2 className="text-[15px] font-bold">Leave balance</h2>
                            <button
                                type="button"
                                onClick={() => setLeaveOpen(true)}
                                className="ml-auto rounded-[9px] border bg-card px-2.5 py-1 text-[11.5px] font-bold text-category-hr transition-colors hover:bg-accent"
                                style={{
                                    borderColor:
                                        'color-mix(in oklch, var(--category-hr) 40%, var(--border))',
                                }}
                            >
                                Request
                            </button>
                        </div>
                        {overview.leaveBalance.length === 0 ? (
                            <p className="mt-3.5 text-[12.5px] text-muted-foreground">
                                No leave balances on record yet.
                            </p>
                        ) : (
                            <div className="mt-3.5 flex flex-col gap-3.5">
                                {overview.leaveBalance.map((l) => (
                                    <div key={l.leave_type}>
                                        <div className="flex items-baseline justify-between">
                                            <span className="text-[12.5px] font-semibold">
                                                {l.label}
                                            </span>
                                            <span className="text-[12.5px] text-muted-foreground">
                                                <span className="font-bold text-foreground">
                                                    {l.remaining_days}
                                                </span>{' '}
                                                {l.remaining_days === 1 ? 'day' : 'days'} left
                                            </span>
                                        </div>
                                        <div className="mt-1.5 h-2 overflow-hidden rounded-full bg-muted">
                                            <div
                                                className="h-full rounded-full transition-[width] duration-700"
                                                style={{
                                                    width: `${Math.round(l.frac * 100)}%`,
                                                    background: `var(${l.token})`,
                                                }}
                                            />
                                        </div>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                </div>

                {/* ── Around your team (one calm card) ── */}
                <div className="rounded-[18px] border border-border bg-card p-[22px] shadow-[0_2px_14px_-8px_rgba(40,30,70,0.18)]">
                    <div className="flex items-center gap-2.5">
                        <span
                            className="grid h-[30px] w-[30px] place-items-center rounded-[9px]"
                            style={{
                                background:
                                    'color-mix(in oklch, var(--category-hr) 14%, var(--card))',
                                color: 'var(--category-hr)',
                            }}
                        >
                            <Leaf className="h-4 w-4" />
                        </span>
                        <div>
                            <h2 className="text-[15px] font-bold">
                                Around {myHr.profile.site_name ?? 'your team'}
                            </h2>
                            <p className="text-[11.5px] text-muted-foreground">
                                Your people, this week 💛
                            </p>
                        </div>
                    </div>

                    <div className="mt-4 grid gap-0 md:grid-cols-3">
                        {/* Celebrations */}
                        <AroundColumn
                            icon={<PartyPopper className="h-3.5 w-3.5" />}
                            label="Celebrations"
                            count={overview.celebrations.length}
                            onSeeAll={() => setAroundView('celebrations')}
                        >
                            {overview.celebrations.length === 0 ? (
                                <Empty>No celebrations this week.</Empty>
                            ) : (
                                overview.celebrations.slice(0, 2).map((c) => {
                                    const done = congrats.has(c.user_id);
                                    return (
                                        <div
                                            key={c.id}
                                            className="flex items-center gap-2.5 py-1.5"
                                        >
                                            <Avatar id={c.user_id} initials={c.initials} />
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-[12px] font-semibold">
                                                    {c.name}
                                                </div>
                                                <div className="truncate text-[11px] text-muted-foreground">
                                                    {c.occasion}
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => congratulate(c)}
                                                disabled={done}
                                                className={cn(
                                                    'shrink-0 rounded-lg border px-2 py-1 text-[11px] font-bold transition-colors',
                                                    done
                                                        ? 'border-status-success bg-status-success-bg text-status-success'
                                                        : 'border-primary bg-card text-primary hover:bg-accent',
                                                )}
                                            >
                                                {done ? 'Sent ✓' : 'Congratulate'}
                                            </button>
                                        </div>
                                    );
                                })
                            )}
                        </AroundColumn>

                        {/* Who's out */}
                        <AroundColumn
                            icon={<Users className="h-3.5 w-3.5" />}
                            label="Who's out"
                            count={overview.whosOut.length}
                            onSeeAll={() => setAroundView('whosOut')}
                            divider
                        >
                            {overview.whosOut.length === 0 ? (
                                <Empty>Everyone’s in this week.</Empty>
                            ) : (
                                overview.whosOut.slice(0, 3).map((w) => {
                                    const badge = leaveTone(w.leave_type);
                                    return (
                                        <div
                                            key={`${w.user_id}-${w.range}`}
                                            className="flex items-center gap-2.5 py-1.5"
                                        >
                                            <Avatar id={w.user_id} initials={w.initials} />
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-[12px] font-semibold">
                                                    {w.name}
                                                </div>
                                                <div className="truncate text-[11px] text-muted-foreground">
                                                    {w.range}
                                                </div>
                                            </div>
                                            <StatusBadge variant={badge.variant} size="sm">
                                                {badge.label}
                                            </StatusBadge>
                                        </div>
                                    );
                                })
                            )}
                        </AroundColumn>

                        {/* Announcements */}
                        <AroundColumn
                            icon={<Megaphone className="h-3.5 w-3.5" />}
                            label="Announcements"
                            count={announcements.length}
                            onSeeAll={() => setAroundView('announcements')}
                            divider
                        >
                            {announcements.length === 0 ? (
                                <Empty>All caught up.</Empty>
                            ) : (
                                announcements.slice(0, 2).map((a) => {
                                    const seen = acked.has(a.id) || a.acknowledged;
                                    return (
                                        <div
                                            key={a.id}
                                            className="flex items-center gap-2.5 py-1.5"
                                        >
                                            <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-live" />
                                            <div className="min-w-0 flex-1">
                                                <div className="truncate text-[12px] font-semibold leading-tight">
                                                    {a.title}
                                                </div>
                                                <div className="truncate text-[11px] text-muted-foreground">
                                                    {a.byline}
                                                </div>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={() => acknowledge(a.id)}
                                                disabled={seen}
                                                className={cn(
                                                    'shrink-0 rounded-lg border px-2 py-1 text-[11px] font-semibold transition-colors',
                                                    seen
                                                        ? 'border-status-success bg-status-success-bg text-status-success'
                                                        : 'border-border bg-card hover:bg-muted',
                                                )}
                                            >
                                                {seen ? 'Seen ✓' : 'Acknowledge'}
                                            </button>
                                        </div>
                                    );
                                })
                            )}
                        </AroundColumn>
                    </div>
                </div>

                {safeWorkProcedures.length > 0 ? (
                    <ApplicableProceduresPanel
                        procedures={safeWorkProcedures}
                        subtitle="Applicable to your role(s) — open any to read the controlled document, then acknowledge"
                        showAcknowledge
                    />
                ) : null}
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}

            <MyHrAroundModal
                view={aroundView}
                onClose={() => setAroundView(null)}
                celebrations={overview.celebrations}
                whosOut={overview.whosOut}
                announcements={announcements}
                congrats={congrats}
                acked={acked}
                canViewFeed={canViewFeed}
                onCongratulate={congratulate}
                onAcknowledge={acknowledge}
                onSendKudos={openKudos}
                onRequestLeave={() => {
                    setAroundView(null);
                    setLeaveOpen(true);
                }}
            />

            <MyHrLeaveWizard
                open={leaveOpen}
                onClose={() => setLeaveOpen(false)}
                balances={balances}
            />
        </MyHrShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Small presentational helpers                                       */
/* ------------------------------------------------------------------ */

function Avatar({ id, initials }: { id: number; initials: string }) {
    return (
        <span
            className="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-full text-xs font-bold text-white"
            style={{ background: `oklch(0.62 0.17 ${hueFromId(id)})` }}
        >
            {initials}
        </span>
    );
}

function Empty({ children }: { children: React.ReactNode }) {
    return <p className="py-1.5 text-[12px] text-muted-foreground">{children}</p>;
}

function AroundColumn({
    icon,
    label,
    count,
    onSeeAll,
    divider,
    children,
}: {
    icon: React.ReactNode;
    label: string;
    count: number;
    onSeeAll: () => void;
    divider?: boolean;
    children: React.ReactNode;
}) {
    return (
        <div
            className={cn(
                'px-0 md:px-6 first:md:pl-0 last:md:pr-0',
                divider && 'md:border-l md:border-border',
            )}
        >
            <div className="mb-2 flex items-center gap-2">
                <span className="inline-flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-[0.07em] text-muted-foreground">
                    {icon} {label}
                </span>
                <button
                    type="button"
                    onClick={onSeeAll}
                    className="ml-auto inline-flex items-center gap-0.5 text-[11px] font-bold text-primary hover:underline"
                >
                    See all {count} <ChevronRight className="h-3 w-3" />
                </button>
            </div>
            <div className="flex flex-col">{children}</div>
        </div>
    );
}

function leaveTone(type: string): { label: string; variant: StatusVariant } {
    const map: Record<string, { label: string; variant: StatusVariant }> = {
        annual: { label: 'Annual', variant: 'success' },
        sick: { label: 'Sick', variant: 'warning' },
        parental: { label: 'Parental', variant: 'info' },
        bereavement: { label: 'Bereavement', variant: 'critical' },
    };
    return (
        map[type] ?? {
            label: type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase()),
            variant: 'neutral',
        }
    );
}
