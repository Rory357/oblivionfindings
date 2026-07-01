/* eslint-disable no-restricted-syntax -- The compliance hero is a bespoke
 * brand-gradient command band (chips, stat cluster, quick-action row, "needs you"
 * footer) cloned from my-hr-hero.tsx with the clock removed, per the design
 * handoff. Raw on-gradient <button>/<div> surfaces; every colour is a token. */
import { cn } from '@/lib/utils';
import { ShieldCheck, type LucideIcon } from 'lucide-react';
import type { CSSProperties, ReactNode } from 'react';

/** Tenant-brand gradient (re-themes with --primary), amber for attention values. */
export const heroGradientStyle: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    ['--hr-amber-soft' as string]:
        'color-mix(in oklch, oklch(0.86 0.13 90) 25%, transparent)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 60%, color-mix(in oklch, var(--primary) 92%, white 6%))',
    boxShadow: '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

const CHIP_DOT: Record<string, string> = {
    success: 'oklch(0.78 0.16 150)',
    warning: 'var(--hr-amber)',
    critical: 'oklch(0.74 0.18 25)',
};

export type HeroStat = {
    label: string;
    value: string | number;
    amber?: boolean;
    onClick?: () => void;
};

export type HeroAction = { icon: LucideIcon; label: string; onClick: () => void };
export type HeroChip = { key: string; label: string; tone: string };
export type HeroNeed = { key: string; label: string; onClick: () => void };

/**
 * Full hub hero — title row + compliance chips + 6-stat cluster + quick-action
 * row + amber "needs you" footer strip. No clock (per handoff).
 */
export function ComplianceHubHero({
    today,
    role,
    site,
    chips,
    stats,
    actions,
    needs,
}: {
    today: string;
    role: string;
    site: string;
    chips: HeroChip[];
    stats: HeroStat[];
    actions: HeroAction[];
    needs: HeroNeed[];
}) {
    return (
        <div style={heroGradientStyle} className="relative rounded-[24px] text-primary-foreground">
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute right-[22%] -top-20 h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
            </div>

            <div className="relative px-[34px] pt-[30px]">
                {/* title */}
                <div className="flex items-center gap-3.5">
                    <span className="grid h-[54px] w-[54px] place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/[0.16]">
                        <ShieldCheck className="h-[26px] w-[26px]" />
                    </span>
                    <div>
                        <h1 className="text-[27px] font-bold leading-[1.05] tracking-tight">
                            Staff compliance
                        </h1>
                        <p className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1.5 text-[13px] text-primary-foreground/75">
                            <span className="font-semibold">{today}</span>
                            <span className="text-primary-foreground/40">·</span>
                            <span>{role}</span>
                            <span className="text-primary-foreground/40">·</span>
                            <span>{site}</span>
                        </p>
                    </div>
                </div>

                {/* chips */}
                {chips.length > 0 ? (
                    <div className="mt-4 flex flex-wrap gap-2">
                        {chips.map((c) => (
                            <span
                                key={c.key}
                                className="inline-flex items-center whitespace-nowrap rounded-full border border-primary-foreground/20 bg-primary-foreground/[0.12] px-3 py-[5px] text-[12px] font-semibold"
                            >
                                <span
                                    className="mr-[7px] inline-block h-[7px] w-[7px] flex-none rounded-full"
                                    style={{ background: CHIP_DOT[c.tone] ?? CHIP_DOT.warning }}
                                />
                                {c.label}
                            </span>
                        ))}
                    </div>
                ) : null}

                {/* stat cluster */}
                <div className="-mx-2.5 mt-[18px] flex flex-wrap gap-0.5">
                    {stats.map((s) => (
                        <button
                            key={s.label}
                            type="button"
                            onClick={s.onClick}
                            disabled={!s.onClick}
                            className="flex flex-col items-start gap-[3px] rounded-xl px-3.5 py-2.5 text-left transition-colors hover:bg-primary-foreground/10 disabled:cursor-default disabled:hover:bg-transparent"
                        >
                            <span className="whitespace-nowrap text-[10px] font-bold uppercase tracking-[0.09em] text-primary-foreground/60">
                                {s.label}
                            </span>
                            <span
                                className={cn(
                                    'text-[23px] font-bold tabular-nums',
                                    s.amber && 'text-[color:var(--hr-amber)]',
                                )}
                            >
                                {s.value}
                            </span>
                        </button>
                    ))}
                </div>

                {/* quick actions */}
                <div className="mt-[18px] flex flex-wrap gap-x-[18px] gap-y-2 pb-5 text-[12.5px] font-semibold">
                    {actions.map((a) => (
                        <button
                            key={a.label}
                            type="button"
                            onClick={a.onClick}
                            className="inline-flex items-center gap-[7px] text-primary-foreground/90 transition-colors hover:text-primary-foreground"
                        >
                            <a.icon className="h-[15px] w-[15px]" />
                            {a.label}
                        </button>
                    ))}
                </div>
            </div>

            {/* needs-you footer */}
            <div className="relative flex flex-wrap items-center gap-3 rounded-b-[24px] border-t border-primary-foreground/15 bg-black/[0.08] px-[22px] py-[11px]">
                <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/50">
                    Needs you
                </span>
                {needs.length === 0 ? (
                    <span className="text-xs font-semibold text-primary-foreground/75">
                        All caught up
                    </span>
                ) : (
                    needs.map((n) => (
                        <button
                            key={n.key}
                            type="button"
                            onClick={n.onClick}
                            className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/15 px-[11px] py-1.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                        >
                            <span className="h-1.5 w-1.5 rounded-full bg-[color:var(--hr-amber)] shadow-[0_0_0_3px_var(--hr-amber-soft)]" />
                            {n.label}
                        </button>
                    ))
                )}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Compact hero band (staff-detail + driver-detail headers)           */
/* ------------------------------------------------------------------ */

export function CompactHeroBand({ children }: { children: ReactNode }) {
    return (
        <div style={heroGradientStyle} className="relative overflow-hidden rounded-[24px] text-primary-foreground">
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute right-[18%] -top-16 h-52 w-52 rounded-full bg-primary-foreground/[0.05]" />
            </div>
            <div className="relative flex flex-wrap items-center justify-between gap-[18px] px-7 py-6">
                {children}
            </div>
        </div>
    );
}

export function HeroInitials({ name, size = 60 }: { name: string; size?: number }) {
    const init =
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0]?.toUpperCase() ?? '')
            .join('') || '?';
    return (
        <span
            className="grid place-items-center rounded-full border-2 border-primary-foreground/25 bg-primary-foreground/[0.16] font-bold"
            style={{ height: size, width: size, fontSize: size > 56 ? 20 : 19 }}
        >
            {init}
        </span>
    );
}

export function HeroGhostButton({
    onClick,
    children,
}: {
    onClick: () => void;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="rounded-[9px] border border-primary-foreground/30 bg-primary-foreground/[0.12] px-3 py-[7px] text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
        >
            {children}
        </button>
    );
}

export function HeroSolidButton({
    onClick,
    icon: Icon,
    children,
}: {
    onClick: () => void;
    icon?: LucideIcon;
    children: ReactNode;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className="inline-flex items-center gap-[7px] rounded-[9px] bg-primary-foreground px-3.5 py-2 text-[12.5px] font-bold text-primary transition-transform hover:scale-[1.02]"
        >
            {Icon ? <Icon className="h-[15px] w-[15px]" /> : null}
            {children}
        </button>
    );
}
