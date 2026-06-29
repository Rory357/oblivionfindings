/* eslint-disable no-restricted-syntax -- The Performance hub hero is a bespoke
 * command band mirroring the Recruitment/People heroes: HeroStats are
 * link-buttons, quick-actions + "needs you" chips sit on the brand gradient, and
 * the right rail shows the compliance panel. Colours stay token-based (primary /
 * status-* / --hr-amber injected as a CSS var) so tenant white-label theming
 * still propagates. */
import {
    Award,
    CalendarDays,
    Check,
    Clock,
    Download,
    MessageSquare,
    Plus,
    ShieldCheck,
    Target,
    TrendingUp,
    UserCheck,
    type LucideIcon,
} from 'lucide-react';
import { type CSSProperties } from 'react';

import { cn } from '@/lib/utils';

export type PerfStat = {
    key: string;
    label: string;
    value: string | number;
    tab: string;
    status?: string;
    amber?: boolean;
};

export type PerfNeed = { label: string; icon: string; tab: string; status?: string };
export type PerfCompliance = { label: string; ok: boolean };

export type PerfHeroData = {
    stats: PerfStat[];
    compliance: PerfCompliance[];
    needs: PerfNeed[];
};

export type PerfHeroHandlers = {
    onStat?: (stat: PerfStat) => void;
    onNeed?: (need: PerfNeed) => void;
    onNewReview?: () => void;
    onLogSupervision?: () => void;
    onNewGoal?: () => void;
    onRequest360?: () => void;
    onStartPip?: () => void;
    onExport?: () => void;
};

const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow: '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

const NEED_ICONS: Record<string, LucideIcon> = {
    award: Award,
    supervision: UserCheck,
    trend: TrendingUp,
    message: MessageSquare,
    target: Target,
};

export function PerformanceHero({
    hero,
    subtitle,
    canManage,
    handlers,
}: {
    hero: PerfHeroData;
    subtitle: string;
    canManage: boolean;
    handlers?: PerfHeroHandlers;
}) {
    const dateLabel = new Date().toLocaleDateString('en-NZ', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
        year: 'numeric',
    });

    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-20 right-[22%] h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
                <div className="absolute -bottom-[90px] right-[6%] h-50 w-50 rounded-full bg-primary-foreground/[0.04]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[560px] p-[30px_34px]">
                    <div className="flex items-center gap-4">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <Award className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] font-bold leading-[1.05] tracking-tight">
                                Performance &amp; development
                            </h1>
                            <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] font-medium text-primary-foreground/75">
                                <span className="inline-flex items-center gap-1.5 font-semibold">
                                    <CalendarDays className="h-3.5 w-3.5" />
                                    {dateLabel}
                                </span>
                                <span className="text-primary-foreground/40">·</span>
                                <span className="inline-flex items-center gap-1.5">
                                    <ShieldCheck className="h-3.5 w-3.5" />
                                    {subtitle}
                                </span>
                            </p>
                        </div>
                    </div>

                    {/* stats */}
                    <div className="-ml-3 mt-[18px] flex flex-wrap gap-0.5">
                        {hero.stats.map((s) => (
                            <HeroStat
                                key={s.key}
                                label={s.label}
                                value={s.value}
                                amber={s.amber}
                                onClick={() => handlers?.onStat?.(s)}
                            />
                        ))}
                    </div>

                    {/* quick actions */}
                    <div className="mt-[18px] flex flex-wrap gap-2">
                        {canManage && handlers?.onNewReview ? (
                            <button
                                type="button"
                                onClick={handlers.onNewReview}
                                className="inline-flex h-[34px] items-center gap-2 rounded-[9px] bg-primary-foreground px-3.5 text-[12.5px] font-bold text-primary shadow-sm transition-transform hover:scale-[1.02]"
                            >
                                <Plus className="h-[15px] w-[15px]" />
                                New review
                            </button>
                        ) : null}
                        {canManage && handlers?.onLogSupervision ? (
                            <QuickAction icon={UserCheck} label="Log supervision" onClick={handlers.onLogSupervision} />
                        ) : null}
                        {canManage && handlers?.onNewGoal ? (
                            <QuickAction icon={Target} label="New goal / OKR" onClick={handlers.onNewGoal} />
                        ) : null}
                        {canManage && handlers?.onRequest360 ? (
                            <QuickAction icon={MessageSquare} label="Request 360" onClick={handlers.onRequest360} />
                        ) : null}
                        {canManage && handlers?.onStartPip ? (
                            <QuickAction icon={TrendingUp} label="Start PIP" onClick={handlers.onStartPip} />
                        ) : null}
                        {handlers?.onExport ? (
                            <QuickAction icon={Download} label="Export" onClick={handlers.onExport} />
                        ) : null}
                    </div>

                    {/* needs you */}
                    {hero.needs.length > 0 ? (
                        <div className="mt-[18px] flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/50">
                                Needs you
                            </span>
                            {hero.needs.map((chip) => {
                                const Icon = NEED_ICONS[chip.icon] ?? Award;
                                return (
                                    <button
                                        key={chip.label}
                                        type="button"
                                        onClick={() => handlers?.onNeed?.(chip)}
                                        className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/[0.13] py-1.5 pl-2.5 pr-3 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                                    >
                                        <span className="h-1.5 w-1.5 flex-none rounded-full bg-[color:var(--hr-amber)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--hr-amber)_32%,transparent)]" />
                                        <Icon className="h-[13px] w-[13px]" />
                                        {chip.label}
                                    </button>
                                );
                            })}
                        </div>
                    ) : null}
                </div>

                {/* ── right rail: compliance ── */}
                <div className="flex w-full flex-none flex-col gap-3 border-t border-primary-foreground/15 bg-black/[0.08] p-[24px] sm:w-[300px] sm:border-l sm:border-t-0">
                    <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/55">
                        Compliance
                    </span>
                    <div className="flex flex-col gap-2.5">
                        {hero.compliance.map((c) => (
                            <div
                                key={c.label}
                                className="flex items-start gap-2 text-xs font-semibold text-primary-foreground/90"
                            >
                                <span
                                    className={cn(
                                        'mt-px grid h-4 w-4 flex-none place-items-center rounded-full',
                                        c.ok
                                            ? 'bg-emerald-200/20 text-emerald-200'
                                            : 'bg-[color:var(--hr-amber)]/20 text-[color:var(--hr-amber)]',
                                    )}
                                >
                                    {c.ok ? <Check className="h-2.5 w-2.5" /> : <Clock className="h-2.5 w-2.5" />}
                                </span>
                                {c.label}
                            </div>
                        ))}
                    </div>
                </div>
            </div>
        </div>
    );
}

function HeroStat({
    label,
    value,
    amber,
    onClick,
}: {
    label: string;
    value: string | number;
    amber?: boolean;
    onClick?: () => void;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left transition-colors hover:bg-primary-foreground/10"
        >
            <span className="whitespace-nowrap text-[10px] font-bold uppercase tracking-[0.09em] text-primary-foreground/60">
                {label}
            </span>
            <span
                className={cn(
                    'text-[22px] font-bold tabular-nums',
                    amber && 'text-[color:var(--hr-amber)]',
                )}
            >
                {value}
            </span>
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

export default PerformanceHero;
