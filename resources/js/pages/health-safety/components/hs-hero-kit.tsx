/* Health & Safety shared hero kit — the single source of the H&S hero chrome.
 *
 * Extracted from the dashboard's command-centre hero (the gold standard) so both
 * `/health-safety` and `/health-safety/analytics` compose the *identical* eyebrow
 * pill, medallion, stat clusters, NZ compliance badges, segmented controls and
 * summary strip. Neither page may hand-roll a primitive the other also has.
 *
 * Semantic tokens only (no raw oklch / hex); app-primary gradient only (no per-site
 * brand tint). NZ frameworks only: LTIFR / TRIFR (never TRIR), WorkSafe notifiable,
 * Ngā Paerewa NZS 8134:2021, Hazardous Substances Regs 2017, ACC. Web-only. */
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Flame,
    HeartPulse,
    type LucideIcon,
    ShieldCheck,
    Sparkles,
} from 'lucide-react';
import { type ReactNode } from 'react';

export type Tone = 'success' | 'warning' | 'critical' | 'neutral';

export const DOT_CLASS: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-primary-foreground/50',
};

// On the dark primary gradient the base status tokens read as a saturated tinted-white
// (≈70% L in dark mode) — the same choice PageHeroStats makes for tone values. Used for
// the optional per-tile delta line so analytics' period-over-period arrows stay legible.
const DELTA_TEXT: Record<Tone, string> = {
    success: 'text-status-success',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
    neutral: 'text-primary-foreground/70',
};

/** Shared formatter — em-dash for null/undefined, optional suffix otherwise. */
export function fmt(value: number | null | undefined, suffix = ''): string {
    return value === null || value === undefined ? '—' : `${value}${suffix}`;
}

/* ------------------------------------------------------------------ */
/*  Shell + eyebrow + medallion                                        */
/* ------------------------------------------------------------------ */

/** Gradient banner wrapper: app-primary gradient, decorative orbs, drop-shadow and an
 *  optional footer band (border-top, same padding) below the main content. */
export function HeroShell({ children, footer }: { children: ReactNode; footer?: ReactNode }) {
    return (
        <div className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 text-primary-foreground shadow-[0_24px_60px_-28px_color-mix(in_oklch,var(--primary)_55%,transparent)]">
            {/* decorative orbs */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                <div className="absolute -top-16 -right-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
                <div className="absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-primary-foreground/5" />
                <div className="absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-primary-foreground/5" />
            </div>

            <div className="relative flex flex-col gap-5 p-6 md:p-7">{children}</div>

            {footer != null ? (
                <div className="relative flex flex-col gap-3 border-t border-primary-foreground/15 px-6 py-3 md:px-7">
                    {footer}
                </div>
            ) : null}
        </div>
    );
}

/** Animated green `status-success` ping dot + uppercase eyebrow. Label text passed in
 *  (dashboard = "Safety system · synced just now"; analytics = "Safety analytics · {range}"). */
export function HeroStatusPill({ children }: { children: ReactNode }) {
    return (
        <div className="inline-flex items-center gap-1.5 self-start rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-semibold tracking-[0.07em] text-primary-foreground/85 uppercase">
            <span className="relative flex h-2 w-2">
                <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-70 motion-reduce:animate-none" />
                <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
            </span>
            {children}
        </div>
    );
}

/** 72–80px circular icon medallion, hidden below `sm` (dashboard scale). */
export function HeroMedallion({ icon: Icon }: { icon: LucideIcon }) {
    return (
        <div className="hidden h-[72px] w-[72px] shrink-0 items-center justify-center rounded-full border-4 border-primary-foreground/20 bg-primary-foreground/10 shadow-xl sm:flex md:h-20 md:w-20">
            <Icon className="h-9 w-9 text-primary-foreground md:h-10 md:w-10" />
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Leading / Lagging stat clusters                                    */
/* ------------------------------------------------------------------ */

/** One KPI tile inside a cluster. `href` makes it a link (dashboard registers); omit for a
 *  static tile. Optional `delta` renders a ▲/▼ line under the value (analytics trend deltas);
 *  dashboard tiles pass none and render unchanged. */
export function HeroClusterTile({
    href,
    label,
    value,
    caption,
    tone,
    delta,
    deltaTone = 'neutral',
}: {
    href?: string;
    label: string;
    value: string;
    caption: string;
    tone: Tone;
    delta?: string;
    deltaTone?: Tone;
}) {
    const base =
        'flex flex-col gap-0.5 rounded-xl border border-primary-foreground/15 bg-primary-foreground/10 px-3 py-2.5 text-left';
    const inner = (
        <>
            <span className="flex items-center gap-1.5">
                <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', DOT_CLASS[tone])} />
                <span className="text-[10.5px] font-semibold tracking-wide text-primary-foreground/70 uppercase">{label}</span>
            </span>
            <span className="text-[25px] leading-tight font-bold tabular-nums text-primary-foreground">{value}</span>
            {delta ? <span className={cn('text-[10.5px] font-semibold', DELTA_TEXT[deltaTone])}>{delta}</span> : null}
            <span className="text-[10.5px] text-primary-foreground/60">{caption}</span>
        </>
    );
    return href ? (
        <Link href={href} className={cn(base, 'transition-colors hover:bg-primary-foreground/20')}>
            {inner}
        </Link>
    ) : (
        <div className={base}>{inner}</div>
    );
}

// Static class map — Tailwind can't see interpolated `sm:grid-cols-${n}`.
const CLUSTER_COLUMNS: Record<number, string> = {
    2: 'grid grid-cols-2 gap-2',
    3: 'grid grid-cols-2 gap-2 sm:grid-cols-3',
    4: 'grid grid-cols-2 gap-2 sm:grid-cols-4',
};

/** A labelled cluster card (Lagging · outcomes / Leading · proactive) wrapping its tiles.
 *  `columns` (2–4, default 4) sets the ≥sm tile grid so a 3-tile cluster doesn't leave
 *  an empty 4th column. */
export function HeroCluster({
    title,
    icon: Icon,
    children,
    columns = 4,
}: {
    title: string;
    icon: LucideIcon;
    children: ReactNode;
    columns?: number;
}) {
    return (
        <div className="rounded-2xl border border-primary-foreground/15 bg-primary-foreground/5 p-3">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">
                <Icon className="h-3.5 w-3.5" />
                {title}
            </div>
            <div className={CLUSTER_COLUMNS[columns] ?? CLUSTER_COLUMNS[4]}>{children}</div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  NZ compliance badges                                               */
/* ------------------------------------------------------------------ */

type BadgeTone = 'success' | 'warning' | 'critical';

/** A single compliance chip. Pages with a bespoke compliance story (e.g. Lone Workers)
 *  pass an `items` array of these to override the canonical module row below. */
export type HeroComplianceBadge = { icon: LucideIcon; tone: BadgeTone; label: string };

const CHIP_CLASS: Record<BadgeTone, string> = {
    success: 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90',
    warning: 'border-status-warning/50 bg-status-warning/25 text-primary-foreground',
    critical: 'border-status-critical/50 bg-status-critical/25 text-primary-foreground',
};
const CHIP_ICON: Record<BadgeTone, string> = {
    success: 'text-primary-foreground/80',
    warning: 'text-status-warning',
    critical: 'text-status-critical',
};

/** The five canonical NZ compliance chips — one tone map, one label set, fed by counts/booleans
 *  (never pre-formatted strings) so both H&S heroes read identically. */
export function HeroComplianceBadges({
    items,
    worksafeAwaiting = 0,
    sdsExpiring = 0,
    drillsDue = 0,
    drillsOverdue = 0,
    ngaPaerewaCertified = true,
    firstAidOk = true,
}: {
    /** Optional override — render this exact chip set instead of the canonical module
     *  row. Lets a page tell its own compliance story while keeping identical chip chrome. */
    items?: HeroComplianceBadge[];
    worksafeAwaiting?: number;
    sdsExpiring?: number;
    /** Drills due-soon → warning. */
    drillsDue?: number;
    /** Drills past their cadence → critical; outranks `drillsDue`. */
    drillsOverdue?: number;
    ngaPaerewaCertified?: boolean;
    firstAidOk?: boolean;
}) {
    // Fire-drill threshold, defined once: overdue (critical) outranks due-soon (warning),
    // else current (success).
    const fireTone: BadgeTone = drillsOverdue > 0 ? 'critical' : drillsDue > 0 ? 'warning' : 'success';
    const fireLabel =
        drillsOverdue > 0
            ? `Fire · ${drillsOverdue} drill${drillsOverdue === 1 ? '' : 's'} overdue`
            : drillsDue > 0
              ? `Fire · ${drillsDue} drill${drillsDue === 1 ? '' : 's'} due`
              : 'Fire · Drills current';

    const badges: HeroComplianceBadge[] = items ?? [
        {
            icon: worksafeAwaiting > 0 ? AlertTriangle : CheckCircle2,
            tone: worksafeAwaiting > 0 ? 'warning' : 'success',
            label: `WorkSafe notifiable · ${worksafeAwaiting} awaiting`,
        },
        {
            icon: ShieldCheck,
            tone: ngaPaerewaCertified ? 'success' : 'warning',
            label: `Ngā Paerewa NZS 8134:2021 · ${ngaPaerewaCertified ? 'Certified' : 'Review due'}`,
        },
        {
            icon: sdsExpiring > 0 ? AlertTriangle : CheckCircle2,
            tone: sdsExpiring > 0 ? 'warning' : 'success',
            label:
                sdsExpiring > 0
                    ? `Hazardous substances · ${sdsExpiring} SDS expiring`
                    : 'Hazardous substances · SDS current',
        },
        { icon: Flame, tone: fireTone, label: fireLabel },
        {
            icon: HeartPulse,
            tone: firstAidOk ? 'success' : 'warning',
            label: firstAidOk ? 'First aid · Cover OK' : 'First aid · Cover gaps',
        },
    ];

    return (
        <div className="mt-3 flex flex-wrap gap-2">
            {badges.map((b, i) => (
                <span
                    key={i}
                    className={cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium',
                        CHIP_CLASS[b.tone],
                    )}
                >
                    <b.icon className={cn('h-3.5 w-3.5', CHIP_ICON[b.tone])} />
                    {b.label}
                </span>
            ))}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Segmented controls (period / lens)                                 */
/* ------------------------------------------------------------------ */

const PILL_BASE =
    'rounded-lg px-2.5 py-1.5 text-xs font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/40';
const PILL_ACTIVE = 'bg-primary-foreground/25 text-primary-foreground';
const PILL_INACTIVE = 'bg-primary-foreground/10 text-primary-foreground/80 hover:bg-primary-foreground/20';
const SEG_BASE =
    'rounded-md px-2.5 py-1 text-xs font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/40';
const SEG_ACTIVE = 'bg-primary-foreground text-primary';
const SEG_INACTIVE = 'text-primary-foreground/80 hover:text-primary-foreground';

export type HeroSegItem = {
    key: string;
    label: string;
    /** Pill variant only — render this item as a popover trigger (e.g. the custom-range picker). */
    popover?: ReactNode;
};

/** The shared period/lens control (dashboard look). `pill` = standalone pills (period, one item
 *  may open a popover); `segmented` = bordered box (lens). `onDark`, keyboard + `aria-pressed`. */
export function HeroSegmented({
    label,
    items,
    value,
    onChange,
    ariaLabel,
    variant = 'segmented',
}: {
    label?: string;
    items: readonly HeroSegItem[];
    value: string;
    onChange: (key: string) => void;
    ariaLabel: string;
    variant?: 'pill' | 'segmented';
}) {
    if (variant === 'pill') {
        // Self-contained container (label + pills) — matches the dashboard's period block.
        return (
            <div role="group" aria-label={ariaLabel} className="flex flex-wrap items-center gap-1.5">
                {label ? (
                    <span className="mr-1 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">{label}</span>
                ) : null}
                {items.map((it) => {
                    const active = value === it.key;
                    const cls = cn(PILL_BASE, active ? PILL_ACTIVE : PILL_INACTIVE);
                    if (it.popover) {
                        return (
                            <Popover key={it.key}>
                                <PopoverTrigger asChild>
                                    {/* eslint-disable-next-line no-restricted-syntax -- segmented period pill on the dark hero; not a shadcn Button. */}
                                    <button type="button" aria-pressed={active} className={cls}>
                                        {it.label}
                                    </button>
                                </PopoverTrigger>
                                <PopoverContent align="start" className="w-auto space-y-2 p-3">
                                    {it.popover}
                                </PopoverContent>
                            </Popover>
                        );
                    }
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- segmented period pill on the dark hero; not a shadcn Button.
                        <button key={it.key} type="button" aria-pressed={active} onClick={() => onChange(it.key)} className={cls}>
                            {it.label}
                        </button>
                    );
                })}
            </div>
        );
    }

    // Segmented variant is a fragment so the label + bordered box sit as siblings in the
    // caller's flex row (label `ml-1` then the box) — matches the dashboard's lens control
    // sitting beside the Site filter exactly.
    return (
        <>
            {label ? (
                <span className="ml-1 text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">{label}</span>
            ) : null}
            <div role="group" aria-label={ariaLabel} className="inline-flex items-center gap-0.5 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 p-0.5">
                {items.map((it) => {
                    const active = value === it.key;
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- segmented toggle on the dark hero; not a shadcn Button.
                        <button
                            key={it.key}
                            type="button"
                            aria-pressed={active}
                            onClick={() => onChange(it.key)}
                            className={cn(SEG_BASE, active ? SEG_ACTIVE : SEG_INACTIVE)}
                        >
                            {it.label}
                        </button>
                    );
                })}
            </div>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Summary strip                                                      */
/* ------------------------------------------------------------------ */

/** One dot-led metric inside the summary strip. */
export function HeroSummaryMetric({ tone, children }: { tone: Tone; children: ReactNode }) {
    return (
        <span className="inline-flex items-center gap-1.5">
            <span className={cn('h-1.5 w-1.5 rounded-full', DOT_CLASS[tone])} />
            {children}
        </span>
    );
}

/** The dot-led summary strip. Optional `onToggle`/`collapsed` adds the "Hide summary"
 *  affordance (analytics) — when absent the strip is always shown (dashboard). */
export function HeroSummaryStrip({
    label,
    children,
    collapsed = false,
    onToggle,
    toggleLabel = 'summary',
}: {
    label?: string;
    children: ReactNode;
    collapsed?: boolean;
    onToggle?: () => void;
    toggleLabel?: string;
}) {
    return (
        <div className="flex flex-wrap items-center gap-x-3 gap-y-1.5 border-t border-primary-foreground/15 pt-2.5 text-xs text-primary-foreground/80">
            {label ? (
                <span className="text-[11px] font-semibold tracking-wide text-primary-foreground/60 uppercase">{label}</span>
            ) : null}
            {collapsed ? null : children}
            {onToggle ? (
                // eslint-disable-next-line no-restricted-syntax -- onDark summary toggle, custom hero-footer affordance
                <button
                    type="button"
                    onClick={onToggle}
                    aria-pressed={!collapsed}
                    className="ml-auto inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-medium text-primary-foreground/70 transition-colors hover:text-primary-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/40"
                >
                    <Sparkles className="h-3 w-3" /> {collapsed ? 'Show' : 'Hide'} {toggleLabel}
                </button>
            ) : null}
        </div>
    );
}
