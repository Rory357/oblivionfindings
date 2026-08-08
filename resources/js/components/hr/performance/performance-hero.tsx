/* eslint-disable no-restricted-syntax -- The Performance hub hero is a bespoke
 * command band mirroring the Recruitment/People heroes: HeroStats are
 * link-buttons, quick-actions + "needs you" chips sit on the brand gradient, and
 * the right rail shows the compliance panel. Colours stay token-based (primary /
 * status-* / --hr-amber injected as a CSS var) so tenant white-label theming
 * still propagates. */
import { Link } from '@inertiajs/react';
import {
    ArrowLeft,
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
import { type CSSProperties, type ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type PerfStat = {
    key: string;
    label: string;
    value: string | number;
    tab: string;
    status?: string;
    amber?: boolean;
};

export type PerfNeed = {
    label: string;
    icon: string;
    tab: string;
    status?: string;
};
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
    boxShadow:
        '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
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
                <div className="absolute right-[6%] -bottom-[90px] h-50 w-50 rounded-full bg-primary-foreground/[0.04]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[560px] p-[30px_34px]">
                    <div className="flex items-center gap-4">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <Award className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] leading-[1.05] font-bold tracking-tight">
                                Performance &amp; development
                            </h1>
                            <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-[13px] font-medium text-primary-foreground/75">
                                <span className="inline-flex items-center gap-1.5 font-semibold">
                                    <CalendarDays className="h-3.5 w-3.5" />
                                    {dateLabel}
                                </span>
                                <span className="text-primary-foreground/40">
                                    ·
                                </span>
                                <span className="inline-flex items-center gap-1.5">
                                    <ShieldCheck className="h-3.5 w-3.5" />
                                    {subtitle}
                                </span>
                            </p>
                        </div>
                    </div>

                    {/* stats */}
                    <div className="mt-[18px] -ml-3 flex flex-wrap gap-0.5">
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
                            <QuickAction
                                icon={UserCheck}
                                label="Log supervision"
                                onClick={handlers.onLogSupervision}
                            />
                        ) : null}
                        {canManage && handlers?.onNewGoal ? (
                            <QuickAction
                                icon={Target}
                                label="New goal / OKR"
                                onClick={handlers.onNewGoal}
                            />
                        ) : null}
                        {canManage && handlers?.onRequest360 ? (
                            <QuickAction
                                icon={MessageSquare}
                                label="Request 360"
                                onClick={handlers.onRequest360}
                            />
                        ) : null}
                        {canManage && handlers?.onStartPip ? (
                            <QuickAction
                                icon={TrendingUp}
                                label="Start PIP"
                                onClick={handlers.onStartPip}
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
                    {hero.needs.length > 0 ? (
                        <div className="mt-[18px] flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold tracking-[0.1em] text-primary-foreground/50 uppercase">
                                Needs you
                            </span>
                            {hero.needs.map((chip) => {
                                const Icon = NEED_ICONS[chip.icon] ?? Award;
                                return (
                                    <button
                                        key={chip.label}
                                        type="button"
                                        onClick={() => handlers?.onNeed?.(chip)}
                                        className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/[0.13] py-1.5 pr-3 pl-2.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
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
                <div className="flex w-full flex-none flex-col gap-3 border-t border-primary-foreground/15 bg-black/[0.08] p-[24px] sm:w-[300px] sm:border-t-0 sm:border-l">
                    <span className="text-[10px] font-bold tracking-[0.1em] text-primary-foreground/55 uppercase">
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
                                    {c.ok ? (
                                        <Check className="h-2.5 w-2.5" />
                                    ) : (
                                        <Clock className="h-2.5 w-2.5" />
                                    )}
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
            <span className="text-[10px] font-bold tracking-[0.09em] whitespace-nowrap text-primary-foreground/60 uppercase">
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

/* ------------------------------------------------------------------ */
/*  Satellite hero — slimmed variant of the hub command band for the   */
/*  Performance deep-dive pages (competencies, PIPs, skills, detail    */
/*  pages). Same gradient/token chrome + a back-to-hub breadcrumb so   */
/*  hub ↔ satellite feels continuous.                                  */
/* ------------------------------------------------------------------ */

export type SatelliteStat = {
    label: string;
    value: string | number;
    amber?: boolean;
};

export function PerformanceSatelliteHero({
    icon: Icon,
    title,
    description,
    backHref = '/hr/performance',
    backLabel = 'Performance & development',
    stats = [],
    actions,
}: {
    icon: LucideIcon;
    title: string;
    description?: string;
    backHref?: string;
    backLabel?: string;
    stats?: SatelliteStat[];
    actions?: ReactNode;
}) {
    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-24 right-[18%] h-56 w-56 rounded-full bg-primary-foreground/[0.05]" />
                <div className="absolute right-[4%] -bottom-[80px] h-44 w-44 rounded-full bg-primary-foreground/[0.04]" />
            </div>

            <div className="relative flex flex-wrap items-center gap-x-6 gap-y-4 p-[24px_28px]">
                <div className="min-w-0 flex-1 basis-[420px]">
                    <Link
                        href={backHref}
                        className="inline-flex items-center gap-1.5 text-[12px] font-semibold text-primary-foreground/70 transition-colors hover:text-primary-foreground"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        {backLabel}
                    </Link>
                    <div className="mt-2.5 flex items-center gap-3.5">
                        <span className="grid h-[46px] w-[46px] flex-none place-items-center rounded-[14px] border border-primary-foreground/20 bg-primary-foreground/15">
                            <Icon className="h-[22px] w-[22px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[22px] leading-[1.1] font-bold tracking-tight">
                                {title}
                            </h1>
                            {description ? (
                                <p className="mt-1 text-[13px] font-medium text-primary-foreground/75">
                                    {description}
                                </p>
                            ) : null}
                        </div>
                    </div>
                    {stats.length > 0 ? (
                        <div className="mt-3 -ml-3 flex flex-wrap gap-0.5">
                            {stats.map((s) => (
                                <div
                                    key={s.label}
                                    className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-1.5 text-left"
                                >
                                    <span className="text-[10px] font-bold tracking-[0.09em] whitespace-nowrap text-primary-foreground/60 uppercase">
                                        {s.label}
                                    </span>
                                    <span
                                        className={cn(
                                            'text-[19px] font-bold tabular-nums',
                                            s.amber &&
                                                'text-[color:var(--hr-amber)]',
                                        )}
                                    >
                                        {s.value}
                                    </span>
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>

                {actions ? (
                    <div className="flex flex-none flex-wrap items-center gap-2">
                        {actions}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

/** Ghost action button styled for the gradient band (satellite heroes). */
export function SatelliteHeroAction({
    icon: Icon,
    label,
    onClick,
    href,
    primary,
}: {
    icon?: LucideIcon;
    label: string;
    onClick?: () => void;
    href?: string;
    primary?: boolean;
}) {
    const className = cn(
        'inline-flex h-[34px] items-center gap-2 rounded-[9px] px-3.5 text-[12.5px] transition-colors',
        primary
            ? 'bg-primary-foreground font-bold text-primary shadow-sm transition-transform hover:scale-[1.02]'
            : 'border border-primary-foreground/[0.28] bg-primary-foreground/[0.12] font-semibold text-primary-foreground hover:bg-primary-foreground/20',
    );
    const inner = (
        <>
            {Icon ? <Icon className="h-[15px] w-[15px]" /> : null}
            {label}
        </>
    );
    if (href) {
        return (
            <Link href={href} className={className}>
                {inner}
            </Link>
        );
    }
    return (
        <button type="button" onClick={onClick} className={className}>
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

export default PerformanceHero;
