/* Governance register kit — the shared gold-standard chrome for the Health &
 * Safety governance registers (Events, Corrective actions, …). Extracted from
 * the Events redesign so every sibling register reads as one product and can't
 * drift apart again. Presentational only: the gradient hero shell, hero
 * clusters/tiles, the design tab strip, the on-dark footer filter controls, and
 * the shared row primitives (flag badges, entity avatars). NZ-only, web-only. */
import { ChevronDown, Search, X, type LucideIcon } from 'lucide-react';
import type { ChangeEvent, ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Tones                                                              */
/* ------------------------------------------------------------------ */

/** Semantic status tone used by severity / priority chips and dots. */
export type Tone = 'success' | 'warning' | 'critical' | 'neutral';

export const TONE_BG: Record<Tone, string> = {
    success: 'bg-status-success-bg text-status-success',
    warning: 'bg-status-warning-bg text-status-warning',
    critical: 'bg-status-critical-bg text-status-critical',
    neutral: 'bg-muted text-muted-foreground',
};

export const TONE_DOT: Record<Tone, string> = {
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    neutral: 'bg-muted-foreground',
};

/** Accent tone used by hero tiles and the tab strip. */
export type DesignTone = 'primary' | 'info' | 'warning' | 'critical' | 'success';

export type DesignTabItem = {
    id: string;
    label: string;
    icon: LucideIcon;
    tone: DesignTone;
    badge?: number;
};

const HERO_DOT: Record<DesignTone | 'neutral', string> = {
    primary: 'bg-primary-foreground/80',
    info: 'bg-status-info',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    success: 'bg-status-success',
    neutral: 'bg-primary-foreground/55',
};

const TAB_TONE: Record<DesignTone, { active: string; icon: string; bar: string }> = {
    primary: { active: 'bg-primary/10 text-primary', icon: 'bg-primary text-primary-foreground', bar: 'bg-primary' },
    info: { active: 'bg-status-info-bg text-status-info', icon: 'bg-status-info text-primary-foreground', bar: 'bg-status-info' },
    warning: { active: 'bg-status-warning-bg text-status-warning', icon: 'bg-status-warning text-primary-foreground', bar: 'bg-status-warning' },
    critical: { active: 'bg-status-critical-bg text-status-critical', icon: 'bg-status-critical text-primary-foreground', bar: 'bg-status-critical' },
    success: { active: 'bg-status-success-bg text-status-success', icon: 'bg-status-success text-primary-foreground', bar: 'bg-status-success' },
};

/* ------------------------------------------------------------------ */
/*  Helpers                                                            */
/* ------------------------------------------------------------------ */

export function titleCase(s: string): string {
    return s.replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

export function fmt(value: number | null | undefined): string {
    return value === null || value === undefined ? '—' : String(value);
}

const ENTITY_TONE = [
    'bg-primary text-primary-foreground',
    'bg-status-info text-primary-foreground',
    'bg-status-success text-primary-foreground',
    'bg-status-critical text-primary-foreground',
];

export function initials(label: string | null | undefined): string {
    if (!label) return 'HS';
    const parts = label.split(/\s+/).filter(Boolean);
    const text = parts.length > 1 ? `${parts[0][0]}${parts[1][0]}` : label.slice(0, 2);
    return text.toUpperCase();
}

/** Deterministic avatar tone keyed off a stable id so a row keeps its colour. */
export function entityTone(id: number): string {
    return ENTITY_TONE[id % ENTITY_TONE.length];
}

/* ------------------------------------------------------------------ */
/*  Hero shell                                                         */
/* ------------------------------------------------------------------ */

/** The brand gradient hero scaffold: medallion + eyebrow + title + description,
 *  an optional corner badge, the two-up cluster grid, and the on-dark footer
 *  filter band. Both registers share this so the chrome is pixel-identical. */
export function DesignHeroSection({
    medallion: Medallion,
    eyebrow,
    title,
    description,
    cornerBadge,
    clusters,
    footer,
}: {
    medallion: LucideIcon;
    eyebrow: ReactNode;
    title: ReactNode;
    description: ReactNode;
    cornerBadge?: { icon: LucideIcon; label: string };
    clusters: ReactNode;
    footer: ReactNode;
}) {
    const CornerIcon = cornerBadge?.icon;
    return (
        <section className="relative overflow-hidden rounded-[18px] bg-[linear-gradient(135deg,oklch(51.1%_0.262_277/.94),oklch(48%_0.255_280),oklch(44%_0.235_286))] text-primary-foreground shadow-[0_24px_60px_-28px_oklch(51.1%_0.262_277/.55)]">
            <div className="pointer-events-none absolute -top-16 -right-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
            <div className="pointer-events-none absolute -bottom-20 -left-20 h-48 w-48 rounded-full bg-primary-foreground/5" />
            <div className="pointer-events-none absolute top-1/4 right-1/3 h-24 w-24 rounded-full bg-primary-foreground/5" />

            <div className="relative flex flex-col gap-5 p-5 md:p-6">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div className="flex items-start gap-4">
                        <span className="hidden h-[72px] w-[72px] shrink-0 items-center justify-center rounded-full border-4 border-primary-foreground/20 bg-primary-foreground/10 shadow-xl sm:flex">
                            <Medallion className="h-9 w-9" />
                        </span>
                        <div className="max-w-[720px]">
                            <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-foreground/15 px-2.5 py-1 text-[11px] font-bold tracking-[0.07em] text-primary-foreground/90 uppercase">
                                <span className="relative flex h-2 w-2">
                                    <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-status-success opacity-70 motion-reduce:animate-none" />
                                    <span className="relative inline-flex h-2 w-2 rounded-full bg-status-success" />
                                </span>
                                {eyebrow}
                            </span>
                            <h1 className="mt-4 text-2xl font-bold text-primary-foreground md:text-[30px]">{title}</h1>
                            <p className="mt-2 max-w-[760px] text-sm leading-6 text-primary-foreground/80">{description}</p>
                        </div>
                    </div>
                    {cornerBadge && CornerIcon ? (
                        <span className="inline-flex items-center gap-2 rounded-[11px] bg-primary-foreground/12 px-3.5 py-2 text-xs font-semibold text-primary-foreground/90">
                            <CornerIcon className="h-3.5 w-3.5" />
                            {cornerBadge.label}
                        </span>
                    ) : null}
                </div>

                <div className="grid gap-3 lg:grid-cols-2">{clusters}</div>
            </div>

            <div className="relative flex flex-wrap items-center gap-2 border-t border-primary-foreground/15 px-5 py-3 md:px-6">{footer}</div>
        </section>
    );
}

export function DesignHeroCluster({ title, icon: Icon, children }: { title: string; icon: LucideIcon; children: ReactNode }) {
    return (
        <div className="rounded-2xl border border-primary-foreground/15 bg-primary-foreground/5 p-3">
            <div className="mb-2 flex items-center gap-1.5 text-[11px] font-bold tracking-wide text-primary-foreground/62 uppercase">
                <Icon className="h-3.5 w-3.5" />
                {title}
            </div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">{children}</div>
        </div>
    );
}

export function DesignHeroTile({
    href,
    label,
    value,
    caption,
    tone,
}: {
    /** When omitted the tile renders as a static panel (non-navigating metric). */
    href?: string;
    label: string;
    value: string;
    caption: string;
    tone: DesignTone | 'neutral';
}) {
    const cls = 'flex min-h-[76px] flex-col gap-0.5 rounded-xl border border-primary-foreground/15 bg-primary-foreground/10 px-3 py-2.5 text-left';
    const body = (
        <>
            <span className="flex items-center gap-1.5 text-[10px] font-bold tracking-wide text-primary-foreground/70 uppercase">
                <span className={`h-1.5 w-1.5 rounded-full ${HERO_DOT[tone]}`} />
                {label}
            </span>
            <span className="text-[25px] leading-tight font-bold tabular-nums text-primary-foreground">{value}</span>
            <span className="text-[10.5px] font-semibold text-primary-foreground/62">{caption}</span>
        </>
    );
    return href ? (
        <a href={href} className={`${cls} transition-colors hover:bg-primary-foreground/18`}>
            {body}
        </a>
    ) : (
        <div className={cls}>{body}</div>
    );
}

/* ------------------------------------------------------------------ */
/*  On-dark footer filter controls                                     */
/* ------------------------------------------------------------------ */

export function HeroFilterLabel({ children }: { children: ReactNode }) {
    return <span className="mr-1 text-[11px] font-bold tracking-wide text-primary-foreground/60 uppercase">{children}</span>;
}

export function HeroRangePill({ active, onClick, children }: { active: boolean; onClick: () => void; children: ReactNode }) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- on-dark hero filter pill; <Button> can't render the gradient-surface affordance
        <button
            type="button"
            onClick={onClick}
            className={`h-8 rounded-full border px-3.5 text-xs font-bold transition-colors ${
                active
                    ? 'border-primary-foreground/35 bg-primary-foreground/24 text-primary-foreground'
                    : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/85 hover:bg-primary-foreground/16'
            }`}
        >
            {children}
        </button>
    );
}

export function HeroSelect({
    icon: Icon,
    value,
    onChange,
    ariaLabel,
    className,
    children,
}: {
    icon: LucideIcon;
    value: string | number;
    onChange: (e: ChangeEvent<HTMLSelectElement>) => void;
    ariaLabel: string;
    className?: string;
    children: ReactNode;
}) {
    return (
        <label className={`inline-flex h-8 items-center gap-2 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 px-3 text-xs font-bold text-primary-foreground ${className ?? ''}`}>
            <Icon className="h-3.5 w-3.5" />
            <select value={value} onChange={onChange} className="max-w-40 appearance-none bg-transparent font-bold outline-none [&>option]:text-foreground" aria-label={ariaLabel}>
                {children}
            </select>
            <ChevronDown className="h-3.5 w-3.5" />
        </label>
    );
}

export function HeroToggle({ active, icon: Icon, onClick, children }: { active: boolean; icon: LucideIcon; onClick: () => void; children: ReactNode }) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- on-dark hero toggle; <Button> can't render the gradient-surface affordance
        <button
            type="button"
            aria-pressed={active}
            onClick={onClick}
            className={`inline-flex h-8 items-center gap-2 rounded-full border px-3 text-xs font-bold ${
                active
                    ? 'border-primary-foreground/40 bg-primary-foreground/25 text-primary-foreground'
                    : 'border-primary-foreground/20 bg-primary-foreground/10 text-primary-foreground/90 hover:bg-primary-foreground/16'
            }`}
        >
            <Icon className="h-3.5 w-3.5" />
            {children}
        </button>
    );
}

export function HeroSearch({ placeholder, defaultValue, onSubmit }: { placeholder: string; defaultValue: string; onSubmit: (value: string | null) => void }) {
    return (
        <div className="relative">
            <Search className="pointer-events-none absolute top-1/2 left-3 h-3.5 w-3.5 -translate-y-1/2 text-primary-foreground/60" />
            <input
                type="search"
                placeholder={placeholder}
                defaultValue={defaultValue}
                onKeyDown={(e) => {
                    if (e.key === 'Enter') onSubmit((e.target as HTMLInputElement).value || null);
                }}
                className="h-8 w-44 rounded-full border border-primary-foreground/20 bg-primary-foreground/10 pr-3 pl-8 text-xs font-semibold text-primary-foreground placeholder:text-primary-foreground/45 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
            />
        </div>
    );
}

export function HeroClear({ onClick }: { onClick: () => void }) {
    return (
        // eslint-disable-next-line no-restricted-syntax -- on-dark hero clear affordance; <Button> can't render the gradient-surface affordance
        <button type="button" onClick={onClick} className="inline-flex h-8 items-center gap-1 rounded-full px-2.5 text-xs font-semibold text-primary-foreground/75 hover:text-primary-foreground">
            <X className="h-3.5 w-3.5" />
            Clear
        </button>
    );
}

/* ------------------------------------------------------------------ */
/*  Tab strip                                                          */
/* ------------------------------------------------------------------ */

export function DesignTabStrip({ value, items, onChange, ariaLabel }: { value: string; items: DesignTabItem[]; onChange: (id: string) => void; ariaLabel: string }) {
    return (
        <div role="tablist" aria-label={ariaLabel} className="flex flex-wrap items-center gap-1 rounded-2xl border border-border bg-card p-1.5 shadow-sm">
            {items.map((item) => {
                const active = item.id === value;
                const Icon = item.icon;
                const tone = TAB_TONE[item.tone];
                return (
                    // eslint-disable-next-line no-restricted-syntax -- segmented tab control; <Button> can't render the active-bar/icon-chip affordance
                    <button
                        key={item.id}
                        type="button"
                        role="tab"
                        aria-selected={active}
                        onClick={() => onChange(item.id)}
                        className={`relative inline-flex h-8 items-center gap-2 rounded-[10px] px-3 text-xs font-semibold transition-colors ${
                            active ? tone.active : 'text-muted-foreground hover:bg-muted/70 hover:text-foreground'
                        }`}
                    >
                        <span className={`grid h-5 w-5 place-items-center rounded-md ${active ? tone.icon : 'bg-muted text-muted-foreground'}`}>
                            <Icon className="h-3.5 w-3.5" />
                        </span>
                        {item.label}
                        {item.badge ? (
                            <span className={`rounded-full px-1.5 py-0.5 text-[10px] font-bold tabular-nums ${active ? tone.icon : 'bg-muted text-muted-foreground'}`}>{item.badge}</span>
                        ) : null}
                        {active ? <span className={`absolute right-3 bottom-0 left-3 h-0.5 rounded-full ${tone.bar}`} /> : null}
                    </button>
                );
            })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Row primitives                                                     */
/* ------------------------------------------------------------------ */

export function FlagBadge({ icon: Icon, children, tone, title }: { icon: LucideIcon; children: ReactNode; tone: 'critical' | 'warning' | 'success' | 'info' | 'neutral'; title: string }) {
    const cls =
        {
            critical: 'bg-status-critical-bg text-status-critical',
            warning: 'bg-status-warning-bg text-status-warning',
            success: 'bg-status-success-bg text-status-success',
            info: 'bg-status-info-bg text-status-info',
            neutral: 'bg-muted text-muted-foreground',
        }[tone] ?? 'bg-muted text-muted-foreground';

    return (
        <span title={title} className={`inline-flex items-center gap-1 rounded-md px-2 py-1 text-[11px] font-bold whitespace-nowrap ${cls}`}>
            <Icon className="h-3 w-3" />
            {children}
        </span>
    );
}

/** Card-header strip shared by the register tables: an accent-tiled title plus a
 *  hint to the right (e.g. "Right-click a row for governance actions"). */
export function RegisterTableHeader({ icon: Icon, title, subtitle, hint, hintIcon: HintIcon }: { icon: LucideIcon; title: string; subtitle?: string; hint?: string; hintIcon?: LucideIcon }) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 border-b border-border px-4 py-3 md:px-5">
            <div className="flex items-center gap-2.5">
                <span className="grid h-8 w-8 place-items-center rounded-lg bg-primary/10 text-primary">
                    <Icon className="h-4 w-4" />
                </span>
                <div className="flex flex-wrap items-baseline gap-1.5">
                    <h2 className="text-sm font-bold text-foreground">{title}</h2>
                    {subtitle ? <span className="text-xs font-semibold text-muted-foreground">· {subtitle}</span> : null}
                </div>
            </div>
            {hint ? (
                <span className="inline-flex items-center gap-1.5 text-xs font-medium text-muted-foreground">
                    {HintIcon ? <HintIcon className="h-3.5 w-3.5" /> : null}
                    {hint}
                </span>
            ) : null}
        </div>
    );
}
