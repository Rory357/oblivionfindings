/* eslint-disable no-restricted-syntax -- The Community & Recognition hero is a
 * bespoke brand-gradient band (mirrors the My HR hero idiom): on-gradient stat
 * links, quick-action pills and a celebrations strip are custom layout surfaces
 * (raw <button>/<div>), not shadcn <Button>/<Card> cases. Colours stay
 * token-based (primary / primary-foreground; amber injected as a CSS var) so
 * tenant white-label theming still propagates. The strict white/black token rule
 * applies to components/page/** only. */
import {
    BarChart3,
    Heart,
    type LucideIcon,
    Megaphone,
    PartyPopper,
    Sparkles,
} from 'lucide-react';
import { type CSSProperties } from 'react';

import { cn } from '@/lib/utils';

export type FeedMetrics = {
    kudos_this_month: number;
    participation: number;
    celebrations: number;
    posts_this_week: number;
};

export type FeedCelebration = {
    user_id: number;
    user_name: string;
    sublabel: string;
    kind: 'anniversary' | 'birthday' | 'new_hire';
};

/** Hero-scoped palette — `--primary` is the tenant brand so the gradient
 *  re-themes per tenant; amber is tuned for the participation figure on purple. */
const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 60%, color-mix(in oklch, var(--primary) 92%, white 6%))',
    boxShadow:
        '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

function greetingFor(hour: number): string {
    if (hour < 12) return 'Mōrena';
    if (hour < 17) return 'Kia ora';
    return 'Pō mārie';
}

function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

const CELEBRATION_ICON: Record<FeedCelebration['kind'], string> = {
    anniversary: '🎉',
    birthday: '🎂',
    new_hire: '👋',
};

/**
 * The Community & Recognition hero — golden brand-gradient band with a time-aware
 * te-reo eyebrow, the four recognition KPIs, the four create quick-actions, and a
 * "this week's celebrations" strip whose rows open the recognition wizard
 * pre-filled to congratulate the celebrant.
 */
export function FeedHero({
    metrics,
    celebrations,
    canAnnounce,
    onGiveRecognition,
    onPostUpdate,
    onMakeAnnouncement,
    onViewInsights,
    onCongratulate,
}: {
    metrics: FeedMetrics;
    celebrations: FeedCelebration[];
    canAnnounce: boolean;
    onGiveRecognition: () => void;
    onPostUpdate: () => void;
    onMakeAnnouncement: () => void;
    onViewInsights: () => void;
    onCongratulate: (celebration: FeedCelebration) => void;
}) {
    const now = new Date();
    const eyebrow = `${greetingFor(now.getHours())} · ${now.toLocaleDateString(
        'en-NZ',
        {
            weekday: 'long',
            day: 'numeric',
            month: 'long',
        },
    )}`;

    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            {/* decorative orb */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-24 right-[18%] h-72 w-72 rounded-full bg-primary-foreground/[0.06]" />
            </div>

            <div className="relative p-[32px_34px]">
                <div className="flex items-start gap-4">
                    <span className="grid h-[52px] w-[52px] flex-none place-items-center rounded-[16px] bg-primary-foreground/15 ring-1 ring-primary-foreground/25">
                        <Sparkles className="h-7 w-7 text-[color:var(--hr-amber)]" />
                    </span>
                    <div className="min-w-0">
                        <div className="text-[11px] font-bold tracking-[0.12em] text-primary-foreground/60 uppercase">
                            {eyebrow}
                        </div>
                        <h1 className="mt-1 text-[28px] leading-[1.05] font-bold tracking-tight">
                            Community &amp; Recognition
                        </h1>
                        <p className="mt-1.5 text-[13.5px] text-primary-foreground/80">
                            🎉 Celebrate wins, share updates and keep the team
                            connected.
                        </p>
                    </div>
                </div>

                {/* KPIs */}
                <div className="mt-6 -ml-3 flex flex-wrap gap-0.5">
                    <HeroStat
                        label="Kudos this month"
                        value={metrics.kudos_this_month}
                    />
                    <HeroStat
                        label="Participation"
                        value={`${metrics.participation}%`}
                        amber
                    />
                    <HeroStat
                        label="Celebrations"
                        value={metrics.celebrations}
                    />
                    <HeroStat
                        label="Posts this week"
                        value={metrics.posts_this_week}
                    />
                </div>

                {/* quick actions */}
                <div className="mt-6 flex flex-wrap gap-2.5">
                    <HeroAction
                        icon={Heart}
                        label="Give recognition"
                        onClick={onGiveRecognition}
                        primary
                    />
                    <HeroAction
                        icon={Sparkles}
                        label="Post update"
                        onClick={onPostUpdate}
                    />
                    {canAnnounce ? (
                        <HeroAction
                            icon={Megaphone}
                            label="Make announcement"
                            onClick={onMakeAnnouncement}
                        />
                    ) : null}
                    <HeroAction
                        icon={BarChart3}
                        label="View insights"
                        onClick={onViewInsights}
                    />
                </div>
            </div>

            {/* celebrations strip */}
            {celebrations.length > 0 ? (
                <div className="relative border-t border-primary-foreground/15 bg-black/[0.1] px-[26px] py-4">
                    <div className="mb-2.5 flex items-center gap-2">
                        <span className="text-[10px] font-bold tracking-[0.12em] text-primary-foreground/55 uppercase">
                            This week&apos;s celebrations
                        </span>
                        <span className="grid h-[18px] min-w-[18px] place-items-center rounded-full bg-primary-foreground/20 px-1 text-[11px] font-bold">
                            {celebrations.length}
                        </span>
                    </div>
                    <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                        {celebrations.slice(0, 6).map((c) => (
                            <div
                                key={`${c.kind}-${c.user_id}`}
                                className="flex items-center gap-3 rounded-[12px] border border-primary-foreground/15 bg-primary-foreground/[0.07] px-3 py-2"
                            >
                                <span className="grid h-9 w-9 flex-none place-items-center rounded-full bg-primary-foreground/15 text-[12px] font-bold">
                                    {initials(c.user_name)}
                                </span>
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-[13px] font-semibold">
                                        {c.user_name}
                                    </div>
                                    <div className="truncate text-[11.5px] text-primary-foreground/70">
                                        {CELEBRATION_ICON[c.kind]} {c.sublabel}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => onCongratulate(c)}
                                    aria-label={`Congratulate ${c.user_name}`}
                                    title={`Congratulate ${c.user_name}`}
                                    className="grid h-8 w-8 flex-none place-items-center rounded-[9px] bg-primary-foreground/15 text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                                >
                                    <PartyPopper className="h-4 w-4" />
                                </button>
                            </div>
                        ))}
                    </div>
                </div>
            ) : null}
        </div>
    );
}

function HeroStat({
    label,
    value,
    amber,
}: {
    label: string;
    value: string | number;
    amber?: boolean;
}) {
    return (
        <div className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2">
            <span className="text-[10px] font-bold tracking-[0.09em] whitespace-nowrap text-primary-foreground/60 uppercase">
                {label}
            </span>
            <span
                className={cn(
                    'text-2xl font-bold tabular-nums',
                    amber && 'text-[color:var(--hr-amber)]',
                )}
            >
                {value}
            </span>
        </div>
    );
}

function HeroAction({
    icon: Icon,
    label,
    onClick,
    primary,
}: {
    icon: LucideIcon;
    label: string;
    onClick: () => void;
    primary?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={cn(
                'inline-flex items-center gap-2 rounded-[11px] px-4 py-2.5 text-[13px] font-bold transition-colors',
                primary
                    ? 'bg-primary-foreground text-primary shadow-sm hover:bg-primary-foreground/90'
                    : 'border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
            )}
        >
            <Icon className="h-4 w-4" />
            {label}
        </button>
    );
}

export default FeedHero;
