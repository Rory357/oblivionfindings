/* eslint-disable no-restricted-syntax -- The Asset hero mirrors the Leave hero
 * command band (resources/js/components/hr/leave-hero.tsx): HeroStats are
 * link-buttons, quick-actions and "needs you" chips sit on the brand gradient,
 * and the right rail renders a status-mix donut / HR-owned-value ring as inline
 * SVG. Raw <button>/<svg> on-gradient surfaces, not shadcn cases. Colours stay
 * token-based so tenant white-label theming propagates. */
import { Box, Download, Plus, UserCheck } from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

import { cn } from '@/lib/utils';

import { nzd, STATUS_META, type AssetHero, type AssetStatus } from './asset-parts';

const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow:
        '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

export type AssetHeroStat = {
    label: string;
    value: string | number;
    amber?: boolean;
    onClick?: () => void;
};

export type AssetNeedChip = { key: string; label: string; onClick: () => void };

export type AssetHeroHandlers = {
    onNewAsset?: () => void;
    onAssign?: () => void;
    onOpenInventory?: () => void;
    onExport?: () => void;
};

type HeroRight = 'mix' | 'value';

/**
 * The Asset Management hub hero — a brand-gradient command band above the tab
 * strip. Left: title, four glanceable stats, quick actions and a "needs you"
 * chip row. Right: a toggle between the status-mix donut and an HR-owned-value
 * ring (persisted to localStorage).
 */
export function AssetsHero({
    hero,
    stats,
    needs = [],
    handlers,
    canManage = false,
}: {
    hero: AssetHero;
    stats: AssetHeroStat[];
    needs?: AssetNeedChip[];
    handlers?: AssetHeroHandlers;
    canManage?: boolean;
}) {
    const [right, setRight] = useState<HeroRight>('mix');

    useEffect(() => {
        const stored = window.localStorage.getItem('hrAssets.heroRight');
        if (stored === 'mix' || stored === 'value') setRight(stored);
    }, []);

    const setHero = (mode: HeroRight) => {
        setRight(mode);
        window.localStorage.setItem('hrAssets.heroRight', mode);
    };

    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-20 right-[18%] h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
                <div className="absolute -bottom-28 left-[30%] h-56 w-56 rounded-full bg-primary-foreground/[0.035]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[480px] p-[30px_34px]">
                    <div className="flex items-center gap-[15px]">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <Box className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] leading-[1.05] font-extrabold tracking-tight">
                                Asset Management
                            </h1>
                            <p className="mt-1.5 text-[13px] font-medium text-primary-foreground/[0.78]">
                                Track staff equipment, assignments, maintenance
                                and warranties — across {hero.site_count}{' '}
                                {hero.site_count === 1 ? 'site' : 'sites'}
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
                        {canManage && handlers?.onNewAsset ? (
                            <button
                                type="button"
                                onClick={handlers.onNewAsset}
                                className="inline-flex h-[34px] items-center gap-2 rounded-[9px] bg-primary-foreground px-3.5 text-[12.5px] font-extrabold text-primary shadow-sm transition-transform hover:scale-[1.02]"
                            >
                                <Plus className="h-[15px] w-[15px]" />
                                New asset
                            </button>
                        ) : null}
                        {canManage && handlers?.onAssign ? (
                            <QuickAction
                                icon={UserCheck}
                                label="Assign"
                                onClick={handlers.onAssign}
                            />
                        ) : null}
                        {handlers?.onOpenInventory ? (
                            <QuickAction
                                icon={Box}
                                label="Open inventory"
                                onClick={handlers.onOpenInventory}
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
                            <span className="text-[10px] font-bold tracking-[0.1em] text-primary-foreground/70 uppercase">
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

                {/* ── right rail ── */}
                <div className="flex w-full flex-none flex-col border-t border-primary-foreground/15 bg-black/[0.08] p-[22px_24px] sm:w-[330px] sm:border-t-0 sm:border-l">
                    <div className="mb-1 flex items-center justify-between">
                        <span className="text-[10px] font-bold tracking-[0.1em] text-primary-foreground/70 uppercase">
                            {right === 'mix' ? 'Status mix' : 'Register value'}
                        </span>
                        <div className="inline-flex gap-0.5 rounded-lg bg-primary-foreground/[0.12] p-0.5">
                            <RailTab
                                label="Mix"
                                active={right === 'mix'}
                                onClick={() => setHero('mix')}
                            />
                            <RailTab
                                label="Value"
                                active={right === 'value'}
                                onClick={() => setHero('value')}
                            />
                        </div>
                    </div>

                    {right === 'mix' ? (
                        <MixDonut mix={hero.status_mix} total={hero.total} />
                    ) : (
                        <ValueRing hero={hero} />
                    )}
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Right-rail treatments                                             */
/* ------------------------------------------------------------------ */

function MixDonut({
    mix,
    total,
}: {
    mix: { status: AssetStatus; count: number }[];
    total: number;
}) {
    const segments = mix.filter((s) => s.count > 0);
    const sum = segments.reduce((a, s) => a + s.count, 0) || 1;
    const r = 54;
    const c = 2 * Math.PI * r;
    let accum = 0;
    const arcs = segments.map((s) => {
        const len = (s.count / sum) * c;
        const seg = {
            status: s.status,
            color: STATUS_META[s.status].ring,
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
                No assets in the register yet.
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
                    aria-hidden="true"
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
                            key={seg.status}
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
                        assets
                    </span>
                </div>
            </div>
            <div className="flex min-w-0 flex-col gap-1.5">
                {arcs.map((seg) => (
                    <div
                        key={seg.status}
                        className="flex items-center gap-2 text-[11.5px] text-primary-foreground/85"
                    >
                        <span
                            className="h-2.5 w-2.5 flex-none rounded-[3px]"
                            style={{ background: seg.color }}
                        />
                        <span className="flex-1 whitespace-nowrap capitalize">
                            {seg.status}
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

function ValueRing({ hero }: { hero: AssetHero }) {
    const pct =
        hero.total_value > 0
            ? Math.round((hero.owned_value / hero.total_value) * 100)
            : 0;
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
                    aria-hidden="true"
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
                    <span className="text-[15px] leading-tight font-extrabold tabular-nums">
                        {nzd(hero.owned_value)}
                    </span>
                    <span className="text-[9px] font-semibold text-primary-foreground/60">
                        HR-owned
                    </span>
                </div>
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-2 text-[11.5px] text-primary-foreground/85">
                <div>
                    <div className="text-[13px] font-bold">
                        {nzd(hero.total_value)}
                    </div>
                    <div className="text-primary-foreground/60">
                        Total incl. fleet
                    </div>
                </div>
                <div>
                    <div className="text-[13px] font-bold tabular-nums">
                        {hero.warranties_90d}
                    </div>
                    <div className="text-primary-foreground/60">
                        Warranties ≤90 days
                    </div>
                </div>
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                            */
/* ------------------------------------------------------------------ */

function HeroStat({ label, value, amber, onClick }: AssetHeroStat) {
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
    icon: typeof Box;
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

export default AssetsHero;
