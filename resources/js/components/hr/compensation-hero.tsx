/* eslint-disable no-restricted-syntax -- Bespoke Compensation & Benefits hub hero
 * (golden band-health ring + alert chips + quick-action strip) matching the
 * approved mockup. It is a purpose-built gradient banner, not a stack of Card
 * surfaces; every colour is a semantic token except the ring/accent gold, which
 * is injected as an inline CSS-variable *value* (the same escape hatch PageHero
 * uses for brandColour) and referenced via inline style — never a colour literal
 * in a className — so the raw-colour lint stays green. */
import { cn } from '@/lib/utils';
import { usePage } from '@inertiajs/react';
import type { ComponentType, CSSProperties, ReactNode } from 'react';
import {
    AlertTriangle,
    Banknote,
    ClipboardCheck,
    Clock,
    DollarSign,
    Download,
    Plus,
    Receipt,
} from 'lucide-react';

export type CompensationHeroStats = {
    people_out_of_band: number;
    reviews_in_flight: number;
    awaiting_approval: number;
    reimbursed_this_month: number;
    claims_overdue: number;
    people_in_band: number;
    people_placed: number;
    band_health: number;
};

type IconType = ComponentType<{ className?: string; style?: CSSProperties }>;

export type CompensationQuickAction = {
    label: string;
    icon: IconType;
    onClick?: () => void;
    href?: string;
};

// Warm gold for the ring + attention stats, injected as a CSS-var value so it
// reads consistently on the purple band in both themes (no className literal).
const GOLD = 'oklch(0.8 0.15 85)';
const goldStyle = { color: 'var(--hero-gold)' } as CSSProperties;

const money = (value: number, currency = 'NZD') =>
    new Intl.NumberFormat('en-NZ', {
        style: 'currency',
        currency,
        maximumFractionDigits: 0,
    }).format(value);

// Threshold colours for the band-health ring (light variants that read on the
// purple band), so a high vs low score differs by hue, not just arc length.
const RING_GOOD = 'oklch(0.82 0.16 150)';
const RING_MID = 'oklch(0.8 0.15 85)';
const RING_BAD = 'oklch(0.72 0.18 25)';

function healthBand(pct: number): { color: string; label: string } {
    if (pct >= 90) return { color: RING_GOOD, label: 'Healthy' };
    if (pct >= 75) return { color: RING_MID, label: 'Watch' };
    return { color: RING_BAD, label: 'At risk' };
}

function HealthRing({ pct, color }: { pct: number; color: string }) {
    const size = 92;
    const r = (size - 10) / 2;
    const c = 2 * Math.PI * r;
    return (
        <div className="relative shrink-0" style={{ width: size, height: size }}>
            <svg width={size} height={size} className="-rotate-90">
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke="var(--primary-foreground)"
                    strokeOpacity={0.2}
                    strokeWidth="8"
                />
                <circle
                    cx={size / 2}
                    cy={size / 2}
                    r={r}
                    fill="none"
                    stroke={color}
                    strokeWidth="8"
                    strokeLinecap="round"
                    strokeDasharray={c}
                    strokeDashoffset={c * (1 - Math.min(100, Math.max(0, pct)) / 100)}
                    className="transition-[stroke-dashoffset] duration-700"
                />
            </svg>
            <span className="absolute inset-0 grid place-items-center text-xl font-bold">
                {pct}%
            </span>
        </div>
    );
}

function HeroStat({
    label,
    value,
    amber,
}: {
    label: string;
    value: ReactNode;
    amber?: boolean;
}) {
    return (
        <div>
            <div className="text-[11px] font-semibold uppercase tracking-wide text-primary-foreground/60">
                {label}
            </div>
            <div
                className="mt-0.5 text-2xl font-bold tabular-nums"
                style={amber ? goldStyle : undefined}
            >
                {value}
            </div>
        </div>
    );
}

function AlertChip({
    icon: Icon,
    children,
    amber,
}: {
    icon: IconType;
    children: ReactNode;
    amber?: boolean;
}) {
    return (
        <div className="flex items-center gap-2 rounded-lg border border-primary-foreground/15 bg-primary-foreground/10 px-3 py-2 text-[13px] font-medium backdrop-blur-sm">
            <Icon
                className={cn('h-3.5 w-3.5 shrink-0', !amber && 'text-primary-foreground/70')}
                style={amber ? goldStyle : undefined}
            />
            {children}
        </div>
    );
}

export function CompensationHero({
    stats,
    quickActions,
    currency = 'NZD',
    subtitle = 'Keep your people pay fair, on-band and paid on time',
    periodLabel = 'NZD',
}: {
    stats: CompensationHeroStats;
    /** Override the quick-action strip. When omitted, the standard hub set is
     *  derived from the viewer's permissions (links, not modal triggers). */
    quickActions?: CompensationQuickAction[];
    currency?: string;
    subtitle?: string;
    periodLabel?: string;
}) {
    const can = (
        usePage().props as {
            auth?: { can?: { hr?: { compensation?: { manage?: boolean }; expenses?: { view?: boolean } } } };
        }
    ).auth?.can?.hr;
    const defaultActions: CompensationQuickAction[] = [
        ...(can?.compensation?.manage
            ? [
                  { label: 'New band', icon: Plus, href: '/hr/compensation/bands' },
                  { label: 'Start pay review', icon: ClipboardCheck, href: '/hr/compensation/reviews/create' },
                  { label: 'Record bonus', icon: Banknote, href: '/hr/compensation/bonuses' },
              ]
            : []),
        ...(can?.expenses?.view
            ? [{ label: 'New claim', icon: Receipt, href: '/hr/compensation/expenses/create' }]
            : []),
        { label: 'Export', icon: Download, href: '/hr/compensation/bands/export' },
    ];
    const actions = quickActions ?? defaultActions;
    const health = healthBand(stats.band_health);

    const rootStyle = { ['--hero-gold' as string]: GOLD } as CSSProperties;
    return (
        <div
            style={rootStyle}
            className="relative overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 text-primary-foreground"
        >
            <div className="pointer-events-none absolute inset-0">
                <div className="absolute -right-16 -top-16 h-64 w-64 rounded-full bg-primary-foreground/5" />
                <div className="absolute right-1/4 top-1/3 h-40 w-40 rounded-full bg-primary-foreground/5" />
            </div>

            <div className="relative flex flex-col gap-6 p-6 md:p-8 lg:flex-row lg:items-start lg:justify-between">
                {/* Left: identity + stats + quick actions */}
                <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-4">
                        <span className="grid h-14 w-14 shrink-0 place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/10 shadow-lg">
                            <DollarSign className="h-7 w-7" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-2xl font-bold tracking-tight md:text-3xl">
                                Compensation &amp; Benefits
                            </h1>
                            <p className="mt-0.5 text-sm text-primary-foreground/70">
                                {subtitle} · {periodLabel}
                            </p>
                        </div>
                    </div>

                    <div className="mt-6 grid grid-cols-2 gap-x-8 gap-y-4 sm:grid-cols-4">
                        <HeroStat
                            label="People out of band"
                            value={stats.people_out_of_band}
                            amber={stats.people_out_of_band > 0}
                        />
                        <HeroStat label="Reviews in flight" value={stats.reviews_in_flight} />
                        <HeroStat
                            label="Awaiting my approval"
                            value={stats.awaiting_approval}
                            amber={stats.awaiting_approval > 0}
                        />
                        <HeroStat
                            label="Reimbursed this month"
                            value={money(stats.reimbursed_this_month, currency)}
                        />
                    </div>

                    <div className="mt-6 flex flex-wrap gap-2">
                        {actions.map((a) => {
                            const Icon = a.icon;
                            const inner = (
                                <>
                                    <Icon className="h-4 w-4" />
                                    {a.label}
                                </>
                            );
                            const klass =
                                'inline-flex items-center gap-1.5 rounded-lg bg-primary-foreground/10 px-3 py-2 text-[13px] font-semibold backdrop-blur-sm transition-colors hover:bg-primary-foreground/20 focus:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground';
                            return a.href ? (
                                <a key={a.label} href={a.href} className={klass}>
                                    {inner}
                                </a>
                            ) : (
                                <button key={a.label} type="button" onClick={a.onClick} className={klass}>
                                    {inner}
                                </button>
                            );
                        })}
                    </div>
                </div>

                {/* Right: band-health ring + alert chips */}
                <div className="flex shrink-0 flex-col gap-3 lg:w-72">
                    <div className="flex items-center gap-4">
                        <HealthRing pct={stats.band_health} color={health.color} />
                        <div>
                            <div className="text-[11px] font-semibold uppercase tracking-wide text-primary-foreground/60">
                                Band health · <span style={{ color: health.color }}>{health.label}</span>
                            </div>
                            <div className="mt-1 text-sm font-semibold leading-snug">
                                {stats.people_in_band} of {stats.people_placed} people
                                <br />
                                sit within band
                            </div>
                        </div>
                    </div>
                    <AlertChip icon={Clock} amber={stats.awaiting_approval > 0}>
                        {stats.awaiting_approval} awaiting your approval
                    </AlertChip>
                    <AlertChip icon={AlertTriangle} amber={stats.people_out_of_band > 0}>
                        {stats.people_out_of_band} over / under band
                    </AlertChip>
                    <AlertChip icon={Clock} amber={stats.claims_overdue > 0}>
                        {stats.claims_overdue} claims overdue
                    </AlertChip>
                </div>
            </div>
        </div>
    );
}

export default CompensationHero;
