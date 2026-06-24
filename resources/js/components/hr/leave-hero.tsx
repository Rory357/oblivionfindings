/* eslint-disable no-restricted-syntax -- The Leave hero is a bespoke command
 * band: HeroStats are link-buttons, the quick-actions and "needs you" chips sit
 * on the brand gradient, and the right rail renders an on-leave mix donut /
 * coverage ring as inline SVG. These are custom on-gradient layout surfaces
 * (raw <button>/<svg>), not shadcn <Button>/<Card> cases. Colours stay
 * token-based (primary / status-* / --hr-amber injected as a CSS var) so tenant
 * white-label theming still propagates. Mirrors the People hero gradient
 * (resources/js/components/hr/people-hero.tsx) — the approved Leave Hub design. */
import {
    CalendarCheck,
    CalendarDays,
    CalendarOff,
    Download,
    Plus,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

import { cn } from '@/lib/utils';

/** Hero-scoped palette — `--primary` is the tenant brand so the gradient
 *  re-themes per tenant; the bright gold flags counts that need a decision. */
const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow:
        '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

/** Leave-type → on-gradient legend colour for the on-leave mix donut. Light
 *  oklch tones read clearly against the dark brand band. */
const TYPE_COLOR: Record<string, string> = {
    annual: 'oklch(0.82 0.10 277)',
    sick: 'oklch(0.70 0.16 25)',
    bereavement: 'oklch(0.74 0.13 300)',
    family_violence: 'oklch(0.72 0.15 12)',
    parental: 'oklch(0.86 0.13 90)',
    public_holiday: 'oklch(0.80 0.13 150)',
    alternative: 'oklch(0.78 0.12 230)',
    toil: 'oklch(0.80 0.11 195)',
    unpaid: 'oklch(0.74 0.02 277)',
    other: 'oklch(0.70 0.03 277)',
};

const TYPE_LABEL: Record<string, string> = {
    annual: 'Annual',
    sick: 'Sick',
    bereavement: 'Bereavement',
    family_violence: 'Family violence',
    parental: 'Parental',
    public_holiday: 'Public holiday',
    alternative: 'Alt / lieu',
    toil: 'TOIL',
    unpaid: 'Unpaid',
    other: 'Other',
};

function typeLabel(key: string): string {
    return (
        TYPE_LABEL[key] ??
        key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
    );
}

function typeColor(key: string): string {
    return TYPE_COLOR[key] ?? 'oklch(0.74 0.02 277)';
}

export type LeaveHeroStat = {
    label: string;
    value: string | number;
    amber?: boolean;
    onClick?: () => void;
};

export type LeaveNeedChip = {
    key: string;
    label: string;
    onClick: () => void;
};

export type LeaveMixSegment = { type: string; count: number };

export type LeaveHeroHandlers = {
    onRequestLeave?: () => void;
    onReviewApprovals?: () => void;
    onOpenCalendar?: () => void;
    onExport?: () => void;
};

type HeroRight = 'mix' | 'rate';

/**
 * The Leave & Absence hub hero — a brand-gradient command band rendered above
 * the tab strip on every `/hr/leave` surface. Left: title, four glanceable
 * stats (Awaiting your decision / On leave today / Upcoming · 7d / Absence
 * rate), quick actions and a "needs you" chip row. Right: a toggle between the
 * on-leave mix donut and a coverage-rate ring (persisted to localStorage).
 */
export function LeaveHero({
    siteCount,
    stats,
    needs = [],
    mix,
    coveragePct,
    coverageLegend,
    handlers,
    canCreate = false,
}: {
    siteCount: number;
    stats: LeaveHeroStat[];
    needs?: LeaveNeedChip[];
    mix: LeaveMixSegment[];
    coveragePct: number;
    coverageLegend: Array<{ label: string; value: number; color: string }>;
    handlers?: LeaveHeroHandlers;
    canCreate?: boolean;
}) {
    const [right, setRight] = useState<HeroRight>('mix');

    useEffect(() => {
        const stored = window.localStorage.getItem('hrLeave.heroRight');
        if (stored === 'mix' || stored === 'rate') setRight(stored);
    }, []);

    const setHero = (mode: HeroRight) => {
        setRight(mode);
        window.localStorage.setItem('hrLeave.heroRight', mode);
    };

    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            {/* decorative orb */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-20 right-[20%] h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[460px] p-[30px_34px]">
                    <div className="flex items-center gap-[15px]">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <CalendarOff className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] leading-[1.05] font-extrabold tracking-tight">
                                Leave &amp; Absence
                            </h1>
                            <p className="mt-1.5 text-[13px] font-medium text-primary-foreground/[0.78]">
                                Plan cover, approve fast and keep balances
                                accurate — across {siteCount}{' '}
                                {siteCount === 1 ? 'site' : 'sites'}
                            </p>
                        </div>
                    </div>

                    {/* stats */}
                    <div className="mt-[18px] -ml-3 flex flex-wrap gap-0.5">
                        {stats.map((s) => (
                            <HeroStat key={s.label} {...s} />
                        ))}
                    </div>

                    {/* quick actions */}
                    <div className="mt-[18px] flex flex-wrap gap-2">
                        {canCreate && handlers?.onRequestLeave ? (
                            <button
                                type="button"
                                onClick={handlers.onRequestLeave}
                                className="inline-flex h-[34px] items-center gap-2 rounded-[9px] bg-primary-foreground px-3.5 text-[12.5px] font-extrabold text-primary shadow-sm transition-transform hover:scale-[1.02]"
                            >
                                <Plus className="h-[15px] w-[15px]" />
                                Request leave
                            </button>
                        ) : null}
                        {handlers?.onReviewApprovals ? (
                            <QuickAction
                                icon={CalendarCheck}
                                label="Review approvals"
                                onClick={handlers.onReviewApprovals}
                            />
                        ) : null}
                        {handlers?.onOpenCalendar ? (
                            <QuickAction
                                icon={CalendarDays}
                                label="Open calendar"
                                onClick={handlers.onOpenCalendar}
                            />
                        ) : null}
                        {handlers?.onExport ? (
                            <QuickAction
                                icon={Download}
                                label="Export"
                                onClick={handlers.onExport}
                            />
                        ) : null}
                    </div>

                    {/* needs you */}
                    {needs.length > 0 ? (
                        <div className="mt-[18px] flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold tracking-[0.1em] text-primary-foreground/50 uppercase">
                                Needs you
                            </span>
                            {needs.map((chip) => (
                                <button
                                    key={chip.key}
                                    type="button"
                                    onClick={chip.onClick}
                                    className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/[0.13] py-1.5 pr-3 pl-2.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                                >
                                    <span className="h-1.5 w-1.5 flex-none rounded-full bg-[color:var(--hr-amber)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--hr-amber)_32%,transparent)]" />
                                    {chip.label}
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>

                {/* ── right rail: on-leave mix / coverage rate ── */}
                <div className="flex w-full flex-none flex-col border-t border-primary-foreground/15 bg-black/[0.08] p-[22px_24px] sm:w-[320px] sm:border-t-0 sm:border-l">
                    <div className="mb-1 flex items-center justify-between">
                        <span className="text-[10px] font-bold tracking-[0.1em] text-primary-foreground/55 uppercase">
                            On leave
                        </span>
                        <div className="inline-flex gap-0.5 rounded-lg bg-primary-foreground/[0.12] p-0.5">
                            <RailTab
                                label="Mix"
                                active={right === 'mix'}
                                onClick={() => setHero('mix')}
                            />
                            <RailTab
                                label="Rate"
                                active={right === 'rate'}
                                onClick={() => setHero('rate')}
                            />
                        </div>
                    </div>

                    {right === 'mix' ? (
                        <MixDonut mix={mix} />
                    ) : (
                        <CoverageRing
                            pct={coveragePct}
                            legend={coverageLegend}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Right-rail treatments                                             */
/* ------------------------------------------------------------------ */

function MixDonut({ mix }: { mix: LeaveMixSegment[] }) {
    const segments = mix.filter((s) => s.count > 0);
    const total = segments.reduce((a, s) => a + s.count, 0);
    const r = 54;
    const c = 2 * Math.PI * r;
    let accum = 0;
    const arcs = segments.map((s) => {
        const len = (s.count / (total || 1)) * c;
        const seg = {
            type: s.type,
            color: typeColor(s.type),
            dash: `${len.toFixed(2)} ${(c - len).toFixed(2)}`,
            offset: (-accum).toFixed(2),
            count: s.count,
        };
        accum += len;
        return seg;
    });

    if (segments.length === 0) {
        return (
            <p className="mt-6 text-center text-xs text-primary-foreground/60">
                Nobody on leave this week.
            </p>
        );
    }

    return (
        <div className="mt-2 flex items-center gap-4">
            <div className="relative flex-none">
                <svg
                    width="112"
                    height="112"
                    viewBox="0 0 140 140"
                    style={{ transform: 'rotate(-90deg)' }}
                >
                    <circle
                        cx="70"
                        cy="70"
                        r={r}
                        fill="none"
                        stroke="color-mix(in oklch, var(--primary-foreground) 16%, transparent)"
                        strokeWidth="16"
                    />
                    {arcs.map((seg) => (
                        <circle
                            key={seg.type}
                            cx="70"
                            cy="70"
                            r={r}
                            fill="none"
                            stroke={seg.color}
                            strokeWidth="16"
                            strokeDasharray={seg.dash}
                            strokeDashoffset={seg.offset}
                            strokeLinecap="butt"
                        />
                    ))}
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-[23px] leading-none font-extrabold tabular-nums">
                        {total}
                    </span>
                    <span className="text-[10px] font-semibold text-primary-foreground/60">
                        this week
                    </span>
                </div>
            </div>
            <div className="flex min-w-0 flex-col gap-1.5">
                {arcs.map((seg) => (
                    <div
                        key={seg.type}
                        className="flex items-center gap-2 text-[11.5px] text-primary-foreground/85"
                    >
                        <span
                            className="h-2.5 w-2.5 flex-none rounded-[3px]"
                            style={{ background: seg.color }}
                        />
                        <span className="flex-1 whitespace-nowrap">
                            {typeLabel(seg.type)}
                        </span>
                        <span className="font-bold tabular-nums">
                            {seg.count}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function CoverageRing({
    pct,
    legend,
}: {
    pct: number;
    legend: Array<{ label: string; value: number; color: string }>;
}) {
    const r = 42;
    const c = 2 * Math.PI * r;
    const dash = `${((pct / 100) * c).toFixed(2)} ${c.toFixed(2)}`;

    return (
        <div className="mt-2 flex items-center gap-4">
            <div className="relative flex-none">
                <svg
                    width="112"
                    height="112"
                    viewBox="0 0 112 112"
                    style={{ transform: 'rotate(-90deg)' }}
                >
                    <circle
                        cx="56"
                        cy="56"
                        r={r}
                        fill="none"
                        stroke="color-mix(in oklch, var(--primary-foreground) 16%, transparent)"
                        strokeWidth="11"
                    />
                    <circle
                        cx="56"
                        cy="56"
                        r={r}
                        fill="none"
                        stroke="var(--hr-amber)"
                        strokeWidth="11"
                        strokeLinecap="round"
                        strokeDasharray={dash}
                    />
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-[24px] leading-none font-extrabold tabular-nums">
                        {pct}%
                    </span>
                    <span className="text-[9px] font-semibold text-primary-foreground/60">
                        covered
                    </span>
                </div>
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                {legend.map((l) => (
                    <div
                        key={l.label}
                        className="flex items-center gap-2 text-[11.5px] text-primary-foreground/85"
                    >
                        <span
                            className="h-2.5 w-2.5 flex-none rounded-[3px]"
                            style={{ background: l.color }}
                        />
                        <span className="flex-1 whitespace-nowrap">
                            {l.label}
                        </span>
                        <span className="font-bold tabular-nums">
                            {l.value}
                        </span>
                    </div>
                ))}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                            */
/* ------------------------------------------------------------------ */

function HeroStat({ label, value, amber, onClick }: LeaveHeroStat) {
    const inner = (
        <>
            <span className="text-[10px] font-bold tracking-[0.09em] whitespace-nowrap text-primary-foreground/60 uppercase">
                {label}
            </span>
            <span
                className={cn(
                    'text-[23px] font-extrabold tabular-nums',
                    amber && 'text-[color:var(--hr-amber)]',
                )}
            >
                {value}
            </span>
        </>
    );
    if (!onClick) {
        return (
            <span className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left">
                {inner}
            </span>
        );
    }
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left transition-colors hover:bg-primary-foreground/10"
        >
            {inner}
        </button>
    );
}

function QuickAction({
    icon: Icon,
    label,
    onClick,
}: {
    icon: LucideIcon;
    label: string;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex h-[34px] items-center gap-2 rounded-[9px] border border-primary-foreground/[0.28] bg-primary-foreground/[0.12] px-3.5 text-[12.5px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
        >
            <Icon className="h-[15px] w-[15px]" />
            {label}
        </button>
    );
}

function RailTab({
    label,
    active,
    onClick,
}: {
    label: string;
    active: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            aria-pressed={active}
            className={cn(
                'h-6 rounded-md px-2.5 text-[11px] font-bold transition-colors',
                active
                    ? 'bg-primary-foreground text-primary'
                    : 'text-primary-foreground/80 hover:text-primary-foreground',
            )}
        >
            {label}
        </button>
    );
}

export default LeaveHero;
