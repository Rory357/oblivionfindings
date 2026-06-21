/* eslint-disable no-restricted-syntax -- The People hero is a bespoke workforce
 * command band: HeroStats are link-buttons, the quick-actions and "needs
 * attention" chips sit on the brand gradient, and the right rail renders an
 * employment-mix donut / compliance ring as inline SVG. These are custom
 * on-gradient layout surfaces (raw <button>/<svg>), not shadcn <Button>/<Card>
 * cases. Colours stay token-based (primary / status-* / --hr-amber injected as a
 * CSS var) so tenant white-label theming still propagates. Mirrors the My HR
 * hero gradient (resources/js/components/hr/my-hr-hero.tsx). */
import { router } from '@inertiajs/react';
import {
    Download,
    Send,
    Upload,
    UserPlus,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

import { cn } from '@/lib/utils';

export type PeopleSummary = {
    active: number;
    inactive: number;
    new_hires: number;
    on_probation: number;
    compliance_alerts: number;
    type_counts: Record<string, number>;
};

export type PeopleNeedChip = {
    key: string;
    label: string;
    onClick: () => void;
};

export type PeopleHeroHandlers = {
    onAdd?: () => void;
    onImport?: () => void;
    onExport?: () => void;
    onInvite?: () => void;
    onStatActive?: () => void;
    onStatNew?: () => void;
    onStatProbation?: () => void;
    onStatCompliance?: () => void;
};

/** Hero-scoped palette — `--primary` is the tenant brand so the gradient
 *  re-themes per tenant; the bright amber flags attention counts on the band. */
const HERO_STYLE: CSSProperties = {
    ['--hr-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow: '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

const TYPE_LABEL: Record<string, string> = {
    full_time: 'Full time',
    part_time: 'Part time',
    casual: 'Casual',
    fixed_term: 'Fixed term',
    contractor: 'Contractor',
};

/** Employment-type → token colour for the workforce-mix donut. */
const TYPE_COLOR: Record<string, string> = {
    full_time: 'var(--status-info)',
    part_time: 'var(--status-warning)',
    casual: 'var(--primary-foreground)',
    fixed_term: 'var(--status-success)',
    contractor: 'var(--hr-amber)',
};

function typeLabel(key: string): string {
    return (
        TYPE_LABEL[key] ??
        key.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase())
    );
}

type HeroRight = 'donut' | 'ring';

/**
 * The People hub hero — a brand-gradient workforce command band rendered above
 * the tab strip on `/hr/people`. Left: title, four glanceable HeroStats
 * (Active / New hires 30d / On probation / Compliance alerts), quick actions
 * and a "needs attention" chip row. Right: a toggle between the employment-mix
 * donut and a compliance ring (persisted to localStorage). No clock — this is
 * the admin/manager lens, not the personal one.
 */
export function PeopleHero({
    totalPeople,
    siteCount,
    summary,
    canManage,
    needs = [],
    handlers,
}: {
    totalPeople: number;
    siteCount: number;
    summary: PeopleSummary;
    canManage: boolean;
    needs?: PeopleNeedChip[];
    handlers?: PeopleHeroHandlers;
}) {
    const [right, setRight] = useState<HeroRight>('donut');

    // Restore the persisted right-rail treatment after mount (client-only).
    useEffect(() => {
        const stored = window.localStorage.getItem('hrp.heroRight');
        if (stored === 'donut' || stored === 'ring') setRight(stored);
    }, []);

    const setHero = (mode: HeroRight) => {
        setRight(mode);
        window.localStorage.setItem('hrp.heroRight', mode);
    };

    return (
        <div
            style={HERO_STYLE}
            className="relative overflow-hidden rounded-[24px] text-primary-foreground"
        >
            {/* decorative orb */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-20 right-[22%] h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
            </div>

            <div className="relative flex flex-wrap items-stretch">
                {/* ── left column ── */}
                <div className="min-w-0 flex-1 basis-[560px] p-[32px_36px]">
                    <div className="flex items-center gap-4">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <Users className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] font-bold leading-[1.05] tracking-tight">
                                People
                            </h1>
                            <p className="mt-1.5 text-[13px] font-medium text-primary-foreground/75">
                                Manage your workforce — {totalPeople}{' '}
                                {totalPeople === 1 ? 'person' : 'people'} across{' '}
                                {siteCount} {siteCount === 1 ? 'site' : 'sites'}
                            </p>
                        </div>
                    </div>

                    {/* stats */}
                    <div className="-ml-3 mt-[18px] flex flex-wrap gap-0.5">
                        <HeroStat
                            label="Active"
                            value={summary.active}
                            onClick={handlers?.onStatActive}
                        />
                        <HeroStat
                            label="New hires · 30d"
                            value={summary.new_hires}
                            onClick={handlers?.onStatNew}
                        />
                        <HeroStat
                            label="On probation"
                            value={summary.on_probation}
                            onClick={handlers?.onStatProbation}
                        />
                        <HeroStat
                            label="Compliance alerts"
                            value={summary.compliance_alerts}
                            amber={summary.compliance_alerts > 0}
                            onClick={handlers?.onStatCompliance}
                        />
                    </div>

                    {/* quick actions */}
                    <div className="mt-[18px] flex flex-wrap gap-2">
                        {canManage && handlers?.onAdd ? (
                            <button
                                type="button"
                                onClick={handlers.onAdd}
                                className="inline-flex h-[34px] items-center gap-2 rounded-[9px] bg-primary-foreground px-3.5 text-[12.5px] font-bold text-primary shadow-sm transition-transform hover:scale-[1.02]"
                            >
                                <UserPlus className="h-[15px] w-[15px]" />
                                Add employee
                            </button>
                        ) : null}
                        {handlers?.onImport ? (
                            <QuickAction
                                icon={Upload}
                                label="Import"
                                onClick={handlers.onImport}
                            />
                        ) : null}
                        {handlers?.onExport ? (
                            <QuickAction
                                icon={Download}
                                label="Export"
                                onClick={handlers.onExport}
                            />
                        ) : null}
                        {handlers?.onInvite ? (
                            <QuickAction
                                icon={Send}
                                label="Invite"
                                onClick={handlers.onInvite}
                            />
                        ) : null}
                    </div>

                    {/* needs attention */}
                    {needs.length > 0 ? (
                        <div className="mt-[18px] flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/50">
                                Needs attention
                            </span>
                            {needs.map((chip) => (
                                <button
                                    key={chip.key}
                                    type="button"
                                    onClick={chip.onClick}
                                    className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/[0.13] py-1.5 pl-2.5 pr-3 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                                >
                                    <span className="h-1.5 w-1.5 flex-none rounded-full bg-[color:var(--hr-amber)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--hr-amber)_32%,transparent)]" />
                                    {chip.label}
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>

                {/* ── right rail: workforce mix / compliance ── */}
                <div className="flex w-full flex-none flex-col border-t border-primary-foreground/15 bg-black/[0.08] p-[22px_24px] sm:w-[320px] sm:border-l sm:border-t-0">
                    <div className="mb-1.5 flex items-center justify-between">
                        <span className="text-[10px] font-bold uppercase tracking-[0.1em] text-primary-foreground/55">
                            Workforce
                        </span>
                        <div className="inline-flex gap-0.5 rounded-lg bg-primary-foreground/[0.12] p-0.5">
                            <RailTab
                                label="Mix"
                                active={right === 'donut'}
                                onClick={() => setHero('donut')}
                            />
                            <RailTab
                                label="Compliance"
                                active={right === 'ring'}
                                onClick={() => setHero('ring')}
                            />
                        </div>
                    </div>

                    {right === 'donut' ? (
                        <MixDonut total={totalPeople} typeCounts={summary.type_counts} />
                    ) : (
                        <ComplianceRing summary={summary} needs={needs} />
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
    total,
    typeCounts,
}: {
    total: number;
    typeCounts: Record<string, number>;
}) {
    const segments = Object.entries(typeCounts).filter(([, n]) => n > 0);
    const denom = segments.reduce((a, [, n]) => a + n, 0) || 1;
    const r = 54;
    const c = 2 * Math.PI * r;
    let accum = 0;
    const arcs = segments.map(([key, n]) => {
        const len = (n / denom) * c;
        const seg = {
            key,
            color: TYPE_COLOR[key] ?? 'var(--muted)',
            dash: `${len.toFixed(2)} ${(c - len).toFixed(2)}`,
            offset: (-accum).toFixed(2),
            count: n,
        };
        accum += len;
        return seg;
    });

    if (segments.length === 0) {
        return (
            <p className="mt-6 text-center text-xs text-primary-foreground/60">
                No workforce data yet.
            </p>
        );
    }

    return (
        <div className="mt-2 flex items-center gap-4">
            <div className="relative flex-none">
                <svg
                    width="118"
                    height="118"
                    viewBox="0 0 140 140"
                    style={{ transform: 'rotate(-90deg)' }}
                >
                    <circle
                        cx="70"
                        cy="70"
                        r={r}
                        fill="none"
                        stroke="color-mix(in oklch, var(--primary-foreground) 16%, transparent)"
                        strokeWidth="18"
                    />
                    {arcs.map((seg) => (
                        <circle
                            key={seg.key}
                            cx="70"
                            cy="70"
                            r={r}
                            fill="none"
                            stroke={seg.color}
                            strokeWidth="18"
                            strokeDasharray={seg.dash}
                            strokeDashoffset={seg.offset}
                            strokeLinecap="butt"
                        />
                    ))}
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-[26px] font-extrabold leading-none tabular-nums">
                        {total}
                    </span>
                    <span className="text-[10px] font-semibold text-primary-foreground/60">
                        staff
                    </span>
                </div>
            </div>
            <div className="flex min-w-0 flex-col gap-1.5">
                {arcs.map((seg) => (
                    <div
                        key={seg.key}
                        className="flex items-center gap-2 text-[11.5px] text-primary-foreground/85"
                    >
                        <span
                            className="h-2.5 w-2.5 flex-none rounded-[3px]"
                            style={{ background: seg.color }}
                        />
                        <span className="flex-1 whitespace-nowrap">
                            {typeLabel(seg.key)}
                        </span>
                        <span className="font-bold tabular-nums">{seg.count}</span>
                    </div>
                ))}
            </div>
        </div>
    );
}

function ComplianceRing({
    summary,
    needs,
}: {
    summary: PeopleSummary;
    needs: PeopleNeedChip[];
}) {
    const base = summary.active || 1;
    const compliant = Math.max(0, summary.active - summary.compliance_alerts);
    const pct = Math.round((compliant / base) * 100);
    const r = 42;
    const c = 2 * Math.PI * r;
    const dash = `${((pct / 100) * c).toFixed(2)} ${c.toFixed(2)}`;

    return (
        <div className="mt-2 flex items-center gap-4">
            <div className="relative flex-none">
                <svg
                    width="108"
                    height="108"
                    viewBox="0 0 108 108"
                    style={{ transform: 'rotate(-90deg)' }}
                >
                    <circle
                        cx="54"
                        cy="54"
                        r={r}
                        fill="none"
                        stroke="color-mix(in oklch, var(--primary-foreground) 16%, transparent)"
                        strokeWidth="11"
                    />
                    <circle
                        cx="54"
                        cy="54"
                        r={r}
                        fill="none"
                        stroke="var(--hr-amber)"
                        strokeWidth="11"
                        strokeLinecap="round"
                        strokeDasharray={dash}
                    />
                </svg>
                <div className="absolute inset-0 flex flex-col items-center justify-center">
                    <span className="text-[24px] font-extrabold leading-none tabular-nums">
                        {pct}%
                    </span>
                    <span className="text-[9px] font-semibold text-primary-foreground/60">
                        compliant
                    </span>
                </div>
            </div>
            <div className="flex min-w-0 flex-1 flex-col gap-1.5">
                {needs.length > 0 ? (
                    needs.map((chip) => (
                        <button
                            key={chip.key}
                            type="button"
                            onClick={chip.onClick}
                            className="flex items-center gap-2 rounded-lg border border-primary-foreground/20 bg-primary-foreground/10 px-2 py-1.5 text-left text-[11.5px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20"
                        >
                            <span className="flex-1">{chip.label}</span>
                        </button>
                    ))
                ) : (
                    <span className="text-[11.5px] text-primary-foreground/70">
                        No outstanding compliance items.
                    </span>
                )}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                            */
/* ------------------------------------------------------------------ */

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
    const inner = (
        <>
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

export default PeopleHero;
