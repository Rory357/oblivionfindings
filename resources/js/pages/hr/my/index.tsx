/* eslint-disable no-restricted-syntax -- Overview "delight" surfaces use bespoke
 * tinted cards, reaction chips, congratulate/acknowledge and CTA pills sized to
 * the design handoff; the shadcn <Button> can't express these on-tint layouts. */
import { router } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    CheckCircle2,
    Clock,
    Heart,
    Leaf,
    MapPin,
    Megaphone,
    MessagesSquare,
    PartyPopper,
    PenLine,
    Send,
    ShieldCheck,
    Sparkles,
    Users,
    X,
} from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';

import {
    MyHrShell,
    hueFromId,
    timeAgo,
    useSendKudos,
    type MyHrShellData,
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

interface Celebration {
    id: string;
    user_id: number;
    name: string;
    initials: string;
    occasion: string;
}

interface WhosOut {
    user_id: number;
    name: string;
    initials: string;
    range: string;
    leave_type: string;
}

interface LatestKudos {
    id: number;
    from_id: number;
    from: string | null;
    from_initials: string;
    category: string;
    message: string;
    created_at: string | null;
}

interface Announcement {
    id: number;
    title: string;
    priority: string;
    published_at: string;
}

interface Props {
    myHr: MyHrShellData;
    overview: {
        latestKudos: LatestKudos | null;
        celebrations: Celebration[];
        whosOut: WhosOut[];
        streak: number;
        attention: AttentionItem[];
    };
    announcements: Announcement[];
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

const KUDOS_LABEL: Record<string, string> = {
    teamwork: 'Teamwork',
    innovation: 'Innovation',
    leadership: 'Leadership',
    customer_focus: 'Customer Focus',
    going_above: 'Going Above & Beyond',
    other: 'Recognition',
};

function leaveBadge(type: string): { label: string; variant: StatusVariant } {
    const label = type.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
    if (type === 'annual') return { label: 'Annual', variant: 'success' };
    if (type === 'sick') return { label: 'Sick', variant: 'warning' };
    if (type === 'parental') return { label: 'Parental', variant: 'info' };
    return { label, variant: 'neutral' };
}

/** Soft tinted surface for the delight cards (token-based, no raw hex). */
function tint(token: string, bgPct: number, borderPct: number) {
    return {
        background: `color-mix(in oklch, var(${token}) ${bgPct}%, var(--card))`,
        borderColor: `color-mix(in oklch, var(${token}) ${borderPct}%, var(--border))`,
    };
}

function avatarStyle(id: number) {
    return { backgroundColor: `oklch(0.62 0.17 ${hueFromId(id)})` };
}

/* ------------------------------------------------------------------ */
/*  Delight card chrome                                                */
/* ------------------------------------------------------------------ */

function DelightCard({
    token,
    icon: Icon,
    title,
    aside,
    children,
}: {
    token: string;
    icon: typeof Heart;
    title: string;
    aside?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div
            className="flex flex-col rounded-[18px] border p-[17px] shadow-[0_2px_12px_-6px_rgba(40,30,70,0.18)]"
            style={tint(token, 5, 18)}
        >
            <div className="flex items-center gap-2.5">
                <span
                    className="grid h-[30px] w-[30px] shrink-0 place-items-center rounded-[9px]"
                    style={{
                        background: `color-mix(in oklch, var(${token}) 16%, var(--card))`,
                        color: `var(${token})`,
                    }}
                >
                    <Icon className="h-4 w-4" />
                </span>
                <h3 className="text-[13.5px] font-bold">{title}</h3>
                {aside ? (
                    <span className="ml-auto text-[11px] text-muted-foreground">
                        {aside}
                    </span>
                ) : null}
            </div>
            {children}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Component                                                          */
/* ------------------------------------------------------------------ */

export default function MyHrIndex({ myHr, overview, announcements }: Props) {
    const openKudos = useSendKudos();
    const { weekly, nextShift } = myHr;
    const firstName = myHr.profile.first_name;

    const [cleared, setCleared] = useState<Set<string>>(new Set());
    const [ctx, setCtx] = useState<ShiftCtxState | null>(null);
    const [reactions, setReactions] = useState({ heart: 0, party: 0, hands: 0 });
    const [congrats, setCongrats] = useState<Set<number>>(new Set());
    const [acked, setAcked] = useState<Set<number>>(new Set());

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

    function congratulate(c: Celebration) {
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

    return (
        <MyHrShell active="overview" myHr={myHr}>
            <div className="flex flex-col gap-5">
                {/* ── Warm welcome strip ── */}
                <div
                    className="flex items-center gap-4 rounded-[18px] border px-5 py-4"
                    style={{
                        background:
                            'linear-gradient(120deg, color-mix(in oklch, var(--category-hr) 15%, var(--card)), color-mix(in oklch, var(--category-hr) 5%, var(--card)))',
                        borderColor:
                            'color-mix(in oklch, var(--category-hr) 22%, var(--border))',
                    }}
                >
                    <span
                        className="grid h-12 w-12 shrink-0 place-items-center rounded-[15px] text-2xl"
                        style={{
                            background:
                                'color-mix(in oklch, var(--category-hr) 22%, var(--card))',
                        }}
                    >
                        <Leaf className="h-6 w-6 text-category-hr" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="text-[15.5px] font-bold">
                            {visibleAttention.length > 0
                                ? `You're having a good run, ${firstName}.`
                                : `You're all clear, ${firstName}. Ka pai.`}
                        </div>
                        <div className="mt-0.5 text-[12.5px] leading-relaxed text-muted-foreground">
                            {visibleAttention.length > 0
                                ? 'A few things need you today, then you’re all clear'
                                : 'Nothing needs you right now'}
                            {overview.streak > 0
                                ? ` — and you’re on a ${overview.streak}-day streak of on-time clock-ins. Ka pai. 💛`
                                : '. 💛'}
                        </div>
                    </div>
                    <div
                        className="flex shrink-0 gap-5 border-l pl-5"
                        style={{
                            borderColor:
                                'color-mix(in oklch, var(--category-hr) 22%, var(--border))',
                        }}
                    >
                        <div className="text-center">
                            <div className="text-xl font-bold text-category-hr">
                                {overview.streak}
                            </div>
                            <div className="text-[10px] font-semibold text-muted-foreground">
                                day streak 🔥
                            </div>
                        </div>
                        <div className="text-center">
                            <div className="text-xl font-bold text-primary">
                                {myHr.counts.kudosThisMonth}
                            </div>
                            <div className="text-[10px] font-semibold text-muted-foreground">
                                kudos · month
                            </div>
                        </div>
                    </div>
                </div>

                {/* ── Attention + This week ── */}
                <div className="grid gap-5 lg:grid-cols-[1.55fr_1fr]">
                    {/* Needs your attention */}
                    <div className="overflow-hidden rounded-[18px] border border-border bg-card shadow-[0_2px_14px_-8px_rgba(40,30,70,0.18)]">
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
                                                onClick={() =>
                                                    router.visit(`/hr/my/${a.go}`)
                                                }
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

                    {/* This week */}
                    <div
                        className="rounded-[18px] border p-[18px]"
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
                                <svg
                                    viewBox="0 0 96 96"
                                    className="h-24 w-24 -rotate-90"
                                >
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
                                        strokeDashoffset={
                                            ringCirc * (1 - workedPct / 100)
                                        }
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
                                {nextShift?.starts_at ? (
                                    <div className="mt-3 border-t border-border pt-3">
                                        <div className="text-[10px] font-bold uppercase tracking-wide text-muted-foreground">
                                            Next shift
                                        </div>
                                        <div className="mt-1 flex items-center gap-2 text-[13px] font-semibold">
                                            <MapPin className="h-3.5 w-3.5 text-primary" />
                                            {[
                                                nextShift.location ??
                                                    nextShift.service_context_name ??
                                                    'Shift',
                                                new Date(
                                                    nextShift.starts_at,
                                                ).toLocaleDateString('en-NZ', {
                                                    weekday: 'short',
                                                }),
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </div>
                                    </div>
                                ) : null}
                            </div>
                        </div>
                    </div>
                </div>

                {/* ── Delight strip ── */}
                <div>
                    <div className="mb-3 flex items-center gap-2.5">
                        <span
                            className="grid h-[30px] w-[30px] place-items-center rounded-[9px]"
                            style={{
                                background:
                                    'color-mix(in oklch, var(--category-hr) 16%, var(--card))',
                                color: 'var(--category-hr)',
                            }}
                        >
                            <Sparkles className="h-4 w-4" />
                        </span>
                        <div>
                            <h2 className="text-base font-bold">
                                Around {myHr.profile.site_name ?? 'your team'}
                            </h2>
                            <p className="text-[11.5px] text-muted-foreground">
                                Your people, this week 💛
                            </p>
                        </div>
                    </div>

                    <div className="grid gap-4 md:grid-cols-2">
                        {/* Kudos for you */}
                        <DelightCard
                            token="--category-hr"
                            icon={Heart}
                            title="Kudos for you"
                            aside={`${myHr.counts.kudosThisMonth} · month`}
                        >
                            {overview.latestKudos ? (
                                <>
                                    <div className="mt-3 rounded-xl bg-accent p-3.5">
                                        <div className="flex items-center gap-2.5">
                                            <span
                                                className="grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold text-white"
                                                style={avatarStyle(
                                                    overview.latestKudos.from_id,
                                                )}
                                            >
                                                {overview.latestKudos.from_initials}
                                            </span>
                                            <div className="min-w-0">
                                                <div className="text-[12.5px] font-bold">
                                                    {overview.latestKudos.from ??
                                                        'A teammate'}
                                                </div>
                                                <div className="text-[11px] text-muted-foreground">
                                                    {timeAgo(
                                                        overview.latestKudos
                                                            .created_at,
                                                    )}
                                                </div>
                                            </div>
                                            <span className="ml-auto rounded-full bg-card px-2.5 py-1 text-[10px] font-bold text-primary">
                                                {KUDOS_LABEL[
                                                    overview.latestKudos.category
                                                ] ?? 'Recognition'}
                                            </span>
                                        </div>
                                        <p className="mt-2.5 text-[12.5px] leading-relaxed">
                                            “{overview.latestKudos.message}”
                                        </p>
                                        <div className="mt-2.5 flex gap-1.5">
                                            {(
                                                [
                                                    ['heart', '❤️'],
                                                    ['party', '🎉'],
                                                    ['hands', '🙌'],
                                                ] as const
                                            ).map(([key, emoji]) => (
                                                <button
                                                    key={key}
                                                    type="button"
                                                    onClick={() =>
                                                        setReactions((r) => ({
                                                            ...r,
                                                            [key]: r[key] + 1,
                                                        }))
                                                    }
                                                    className="rounded-full border border-border bg-card px-2.5 py-1 text-xs font-semibold transition-colors hover:bg-muted"
                                                >
                                                    {emoji} {reactions[key]}
                                                </button>
                                            ))}
                                        </div>
                                    </div>
                                    <button
                                        type="button"
                                        onClick={openKudos}
                                        className="mt-3 flex w-full items-center justify-center gap-1.5 rounded-[10px] border border-primary bg-card py-2.5 text-[12.5px] font-bold text-primary transition-colors hover:bg-accent"
                                    >
                                        <Send className="h-3.5 w-3.5" />
                                        Send kudos to a teammate
                                    </button>
                                </>
                            ) : (
                                <div className="mt-3 flex flex-col items-center gap-2 py-4 text-center">
                                    <p className="text-[12.5px] text-muted-foreground">
                                        No kudos yet — be the one to start it.
                                    </p>
                                    <button
                                        type="button"
                                        onClick={openKudos}
                                        className="inline-flex items-center gap-1.5 rounded-[10px] border border-primary bg-card px-3 py-2 text-[12.5px] font-bold text-primary transition-colors hover:bg-accent"
                                    >
                                        <Send className="h-3.5 w-3.5" />
                                        Send kudos to a teammate
                                    </button>
                                </div>
                            )}
                        </DelightCard>

                        {/* Celebrations */}
                        <DelightCard
                            token="--status-warning"
                            icon={PartyPopper}
                            title="Celebrations"
                        >
                            {overview.celebrations.length === 0 ? (
                                <p className="mt-3 text-[12.5px] text-muted-foreground">
                                    No celebrations this week.
                                </p>
                            ) : (
                                <div className="mt-2.5 flex flex-col gap-1">
                                    {overview.celebrations.map((c) => {
                                        const done = congrats.has(c.user_id);
                                        return (
                                            <div
                                                key={c.id}
                                                className="flex items-center gap-2.5 rounded-[11px] px-2 py-2 transition-colors hover:bg-muted"
                                            >
                                                <span
                                                    className="grid h-[34px] w-[34px] shrink-0 place-items-center rounded-full text-xs font-bold text-white"
                                                    style={avatarStyle(c.user_id)}
                                                >
                                                    {c.initials}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-[12.5px] font-semibold">
                                                        {c.name}
                                                    </div>
                                                    <div className="text-[11px] text-muted-foreground">
                                                        {c.occasion}
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => congratulate(c)}
                                                    disabled={done}
                                                    className={cn(
                                                        'shrink-0 rounded-lg border px-2.5 py-1.5 text-[11.5px] font-bold transition-colors',
                                                        done
                                                            ? 'border-status-success bg-status-success-bg text-status-success'
                                                            : 'border-primary bg-card text-primary hover:bg-accent',
                                                    )}
                                                >
                                                    {done ? 'Sent ✓' : 'Congratulate'}
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </DelightCard>

                        {/* Who's out */}
                        <DelightCard
                            token="--status-info"
                            icon={Users}
                            title="Who's out this week"
                        >
                            {overview.whosOut.length === 0 ? (
                                <p className="mt-3 text-[12.5px] text-muted-foreground">
                                    Everyone’s in this week.
                                </p>
                            ) : (
                                <div className="mt-2.5 flex flex-col gap-2.5">
                                    {overview.whosOut.map((w) => {
                                        const badge = leaveBadge(w.leave_type);
                                        return (
                                            <div
                                                key={`${w.user_id}-${w.range}`}
                                                className="flex items-center gap-2.5"
                                            >
                                                <span
                                                    className="grid h-8 w-8 shrink-0 place-items-center rounded-full text-[11px] font-bold text-white"
                                                    style={avatarStyle(w.user_id)}
                                                >
                                                    {w.initials}
                                                </span>
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-[12.5px] font-semibold">
                                                        {w.name}
                                                    </div>
                                                    <div className="text-[11px] text-muted-foreground">
                                                        {w.range}
                                                    </div>
                                                </div>
                                                <StatusBadge
                                                    variant={badge.variant}
                                                    size="sm"
                                                >
                                                    {badge.label}
                                                </StatusBadge>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </DelightCard>

                        {/* Announcements */}
                        <DelightCard
                            token="--live"
                            icon={Megaphone}
                            title="Announcements"
                        >
                            {announcements.length === 0 ? (
                                <p className="mt-3 text-[12.5px] text-muted-foreground">
                                    All caught up.
                                </p>
                            ) : (
                                <div className="mt-2.5 flex flex-col gap-2">
                                    {announcements.map((a) => {
                                        const seen = acked.has(a.id);
                                        return (
                                            <div
                                                key={a.id}
                                                className="flex items-start gap-2.5 rounded-[11px] border border-border p-2.5"
                                            >
                                                <span className="mt-1 h-2 w-2 shrink-0 rounded-full bg-live" />
                                                <div className="min-w-0 flex-1">
                                                    <div className="text-[12.5px] font-semibold">
                                                        {a.title}
                                                    </div>
                                                    <div className="text-[11px] text-muted-foreground">
                                                        {new Date(
                                                            a.published_at,
                                                        ).toLocaleDateString('en-NZ', {
                                                            day: 'numeric',
                                                            month: 'short',
                                                        })}
                                                    </div>
                                                </div>
                                                <button
                                                    type="button"
                                                    onClick={() => acknowledge(a.id)}
                                                    disabled={seen}
                                                    className={cn(
                                                        'shrink-0 rounded-lg border px-2.5 py-1.5 text-[11px] font-semibold transition-colors',
                                                        seen
                                                            ? 'border-status-success bg-status-success-bg text-status-success'
                                                            : 'border-border bg-card hover:bg-muted',
                                                    )}
                                                >
                                                    {seen ? 'Seen ✓' : 'Acknowledge'}
                                                </button>
                                            </div>
                                        );
                                    })}
                                </div>
                            )}
                        </DelightCard>
                    </div>
                </div>
            </div>

            {ctx ? <ShiftContextMenu ctx={ctx} onClose={() => setCtx(null)} /> : null}
        </MyHrShell>
    );
}
