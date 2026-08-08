/* eslint-disable no-restricted-syntax -- The IT hub hero mirrors the gold-standard
 * People hero (resources/js/components/hr/people-hero.tsx): stat chips are
 * link-buttons, the quick-actions / "needs you" chips are bespoke on-gradient
 * surfaces (raw <button>), and the right rail renders a status donut / SLA
 * compliance ring as inline SVG. Every colour is a design token (primary /
 * status-* / --it-amber injected as a CSS var) so application theming
 * still propagates. */
import { router } from '@inertiajs/react';
import { Plus, Server, Sparkles, type LucideIcon } from 'lucide-react';
import { useEffect, useState, type CSSProperties } from 'react';

/** The slice of the server summary the hero reads (structural subset of the
 *  page's Summary — passing the fuller object is fine). */
export interface ItHeroSummary {
    my: { open: number; waiting: number; resolved_30d: number };
    tickets?: {
        open: number;
        unassigned: number;
        urgent_unassigned: number;
        at_risk: number;
        breached: number;
        awaiting_reply: number;
        resolved_30d: number;
        measured_30d?: number;
        met_30d: number;
        by_status: Record<string, number>;
    };
    provisioning?: {
        pending: number;
        failed: number;
        pending_over_7d: number;
    };
}

/** Hero-scoped palette — `--primary` is the application brand; the bright
 *  amber flags attention counts on the band. */
const HERO_STYLE: CSSProperties = {
    ['--it-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow:
        '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

/** Live-queue statuses shown in the donut, in workflow order, with a token
 *  colour each. Settled (resolved/closed) tickets are excluded — the donut is
 *  the *current* workload mix, not an all-time tally. */
const DONUT_STATUSES: { key: string; label: string; color: string }[] = [
    { key: 'open', label: 'Open', color: 'var(--status-warning)' },
    { key: 'in_progress', label: 'In progress', color: 'var(--status-info)' },
    { key: 'waiting', label: 'Waiting', color: 'var(--it-amber)' },
];

type HeroRight = 'donut' | 'ring';
type StatChip = {
    label: string;
    value: number;
    href?: string;
    amber?: boolean;
};
type NeedChip = { key: string; label: string; href: string };

const go = (href: string) =>
    router.get(
        href,
        {},
        { preserveState: true, preserveScroll: true, replace: true },
    );

/**
 * The IT & Support hub hero — a brand-gradient command band above the tab
 * strip. Left: glanceable stat chips (each a deep-link into the filtered queue),
 * a "needs you" attention row and quick actions. Right (agents only): a toggle
 * between the live-queue status donut and a 30-day SLA-compliance ring, persisted
 * to localStorage. Requesters get a compact three-stat variant + a Raise CTA.
 */
export function ItHero({
    summary,
    can,
    onRaise,
    onLog,
}: {
    summary: ItHeroSummary;
    can: { view: boolean; manage: boolean; request: boolean };
    onRaise: () => void;
    onLog: () => void;
}) {
    const t = summary.tickets;
    const p = summary.provisioning;
    const isAgent = can.view;

    const agentStats: StatChip[] =
        t && p
            ? [
                  {
                      label: 'Open tickets',
                      value: t.open,
                      href: '/it?tab=tickets&view=all_open',
                  },
                  {
                      label: 'Unassigned',
                      value: t.unassigned,
                      href: '/it?tab=tickets&view=unassigned',
                  },
                  {
                      label: 'Breaching soon',
                      value: t.at_risk,
                      href: '/it?tab=tickets&view=breaching',
                      amber: t.at_risk > 0,
                  },
                  {
                      label: 'Breached',
                      value: t.breached,
                      href: '/it?tab=tickets&view=breached',
                      amber: t.breached > 0,
                  },
                  {
                      label: 'Awaiting reply',
                      value: t.awaiting_reply,
                      href: '/it?tab=tickets&view=awaiting_reply',
                  },
                  {
                      label: 'Pending provisioning',
                      value: p.pending,
                      href: '/it?tab=provisioning&status=pending',
                  },
                  {
                      label: 'Resolved · 30d',
                      value: t.resolved_30d,
                      href: '/it?tab=tickets&view=recently_resolved',
                  },
              ]
            : [];

    // "Needs you" chips only surface when there's actually something to chase.
    const needs: NeedChip[] =
        t && p
            ? ([
                  t.urgent_unassigned > 0 && {
                      key: 'urgent',
                      label: `${t.urgent_unassigned} unassigned urgent`,
                      href: '/it?tab=tickets&view=unassigned&ticket_priority=urgent',
                  },
                  t.at_risk > 0 && {
                      key: 'atrisk',
                      label: `${t.at_risk} SLA at risk`,
                      href: '/it?tab=tickets&view=breaching',
                  },
                  t.awaiting_reply > 0 && {
                      key: 'awaiting',
                      label: `${t.awaiting_reply} awaiting your reply`,
                      href: '/it?tab=tickets&view=awaiting_reply',
                  },
                  p.pending_over_7d > 0 && {
                      key: 'aging',
                      label: `${p.pending_over_7d} provisioning pending >7d`,
                      href: '/it?tab=provisioning&status=pending',
                  },
                  p.failed > 0 && {
                      key: 'provisioning-failed',
                      label: `${p.failed} provisioning failed`,
                      href: '/it?tab=provisioning&status=failed',
                  },
              ].filter(Boolean) as NeedChip[])
            : [];

    const requesterStats: StatChip[] = [
        {
            label: 'My open tickets',
            value: summary.my.open,
            href: '/it?tab=my-tickets',
        },
        {
            label: 'Awaiting my reply',
            value: summary.my.waiting,
            href: '/it?tab=my-tickets',
            amber: summary.my.waiting > 0,
        },
        { label: 'Resolved · 30d', value: summary.my.resolved_30d },
    ];

    const stats = isAgent ? agentStats : requesterStats;
    const subtitle = isAgent
        ? 'Account, access & equipment requests — and the IT helpdesk queue.'
        : 'Raise an IT ticket and track it here — IT sees new ones instantly.';

    // Right-rail treatment, restored from localStorage after mount (client-only).
    const [right, setRight] = useState<HeroRight>('donut');
    useEffect(() => {
        const stored = window.localStorage.getItem('it.heroRight');
        if (stored === 'donut' || stored === 'ring') setRight(stored);
    }, []);
    const setHero = (mode: HeroRight) => {
        setRight(mode);
        window.localStorage.setItem('it.heroRight', mode);
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
                <div className="min-w-0 flex-1 basis-[560px] p-[30px_34px]">
                    <div className="flex flex-wrap items-center justify-between gap-4">
                        <div className="flex items-center gap-4">
                            <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                                <Server className="h-[26px] w-[26px]" />
                            </span>
                            <div className="min-w-0">
                                <h1 className="text-[28px] leading-[1.05] font-bold tracking-tight">
                                    IT &amp; Support
                                </h1>
                                <p className="mt-1.5 text-[13px] font-medium text-primary-foreground/75">
                                    {subtitle}
                                </p>
                            </div>
                        </div>

                        {/* quick actions */}
                        <div className="flex flex-wrap gap-2">
                            {can.manage ? (
                                <QuickAction
                                    icon={Sparkles}
                                    label="Log & triage"
                                    onClick={onLog}
                                    solid
                                />
                            ) : null}
                            {can.request || can.manage ? (
                                <QuickAction
                                    icon={Plus}
                                    label="Raise a ticket"
                                    onClick={onRaise}
                                    solid={!can.manage}
                                />
                            ) : null}
                        </div>
                    </div>

                    {/* stats */}
                    <div className="mt-[18px] -ml-3 flex flex-wrap gap-0.5">
                        {stats.map((s) => (
                            <HeroStat key={s.label} {...s} />
                        ))}
                    </div>

                    {/* needs attention (agents) */}
                    {needs.length > 0 ? (
                        <div className="mt-[18px] flex flex-wrap items-center gap-2">
                            <span className="text-[10px] font-bold tracking-[0.1em] text-primary-foreground/50 uppercase">
                                Needs you
                            </span>
                            {needs.map((chip) => (
                                <button
                                    key={chip.key}
                                    type="button"
                                    onClick={() => go(chip.href)}
                                    className="inline-flex items-center gap-2 rounded-[9px] border border-primary-foreground/25 bg-primary-foreground/[0.13] py-1.5 pr-3 pl-2.5 text-xs font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/25"
                                >
                                    <span className="h-1.5 w-1.5 flex-none rounded-full bg-[color:var(--it-amber)] shadow-[0_0_0_3px_color-mix(in_oklch,var(--it-amber)_32%,transparent)]" />
                                    {chip.label}
                                </button>
                            ))}
                        </div>
                    ) : null}
                </div>

                {/* ── right rail: status donut / SLA ring (agents) ── */}
                {isAgent && t ? (
                    <div className="flex w-full flex-none flex-col border-t border-primary-foreground/15 bg-black/[0.08] p-[22px_24px] sm:w-[300px] sm:border-t-0 sm:border-l">
                        <div className="mb-1.5 flex items-center justify-between">
                            <span className="text-[10px] font-bold tracking-[0.1em] text-primary-foreground/55 uppercase">
                                Helpdesk
                            </span>
                            <div className="inline-flex gap-0.5 rounded-lg bg-primary-foreground/[0.12] p-0.5">
                                <RailTab
                                    label="Status"
                                    active={right === 'donut'}
                                    onClick={() => setHero('donut')}
                                />
                                <RailTab
                                    label="SLA"
                                    active={right === 'ring'}
                                    onClick={() => setHero('ring')}
                                />
                            </div>
                        </div>
                        {right === 'donut' ? (
                            <StatusDonut
                                byStatus={t.by_status}
                                openTotal={t.open}
                            />
                        ) : (
                            <ComplianceRing
                                met={t.met_30d}
                                measured={t.measured_30d ?? t.resolved_30d}
                            />
                        )}
                    </div>
                ) : null}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Right-rail treatments                                             */
/* ------------------------------------------------------------------ */

/** Live-queue mix — open / in progress / waiting, as inline-SVG arcs. */
function StatusDonut({
    byStatus,
    openTotal,
}: {
    byStatus: Record<string, number>;
    openTotal: number;
}) {
    const segments = DONUT_STATUSES.map((s) => ({
        ...s,
        count: byStatus[s.key] ?? 0,
    })).filter((s) => s.count > 0);
    const denom = segments.reduce((a, s) => a + s.count, 0) || 1;
    const r = 54;
    const c = 2 * Math.PI * r;
    let accum = 0;
    const arcs = segments.map((s) => {
        const len = (s.count / denom) * c;
        const arc = {
            ...s,
            dash: `${len.toFixed(2)} ${(c - len).toFixed(2)}`,
            offset: (-accum).toFixed(2),
        };
        accum += len;
        return arc;
    });

    if (segments.length === 0) {
        return (
            <p className="mt-6 text-center text-xs text-primary-foreground/60">
                The queue is clear — no open tickets.
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
                    <span className="text-[26px] leading-none font-extrabold tabular-nums">
                        {openTotal}
                    </span>
                    <span className="text-[10px] font-semibold text-primary-foreground/60">
                        open
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
                            {seg.label}
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

/** 30-day SLA compliance — share of measured settled tickets that met target. */
function ComplianceRing({ met, measured }: { met: number; measured: number }) {
    if (measured === 0) {
        return (
            <p className="mt-6 text-center text-xs text-primary-foreground/60">
                No measured SLA outcomes in the last 30 days yet.
            </p>
        );
    }
    const pct = Math.round((met / measured) * 100);
    const r = 42;
    const c = 2 * Math.PI * r;
    const dash = `${((pct / 100) * c).toFixed(2)} ${c.toFixed(2)}`;
    const tone =
        pct >= 90
            ? 'var(--status-success)'
            : pct >= 70
              ? 'var(--it-amber)'
              : 'var(--status-critical)';

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
                        stroke={tone}
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
                        within SLA
                    </span>
                </div>
            </div>
            <p className="flex-1 text-[11.5px] leading-relaxed text-primary-foreground/80">
                <span className="font-bold tabular-nums">{met}</span> of{' '}
                <span className="font-bold tabular-nums">{measured}</span>{' '}
                measured tickets settled within SLA in the last 30 days.
            </p>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Pieces                                                            */
/* ------------------------------------------------------------------ */

function HeroStat({ label, value, href, amber }: StatChip) {
    const inner = (
        <>
            <span className="text-[10px] font-bold tracking-[0.09em] whitespace-nowrap text-primary-foreground/60 uppercase">
                {label}
            </span>
            <span
                className={
                    amber
                        ? 'text-[22px] font-bold text-[color:var(--it-amber)] tabular-nums'
                        : 'text-[22px] font-bold tabular-nums'
                }
            >
                {value}
            </span>
        </>
    );
    if (!href) {
        return (
            <span className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left">
                {inner}
            </span>
        );
    }
    return (
        <button
            type="button"
            onClick={() => go(href)}
            className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left transition-colors hover:bg-primary-foreground/10 focus-visible:bg-primary-foreground/10 focus-visible:outline-none"
        >
            {inner}
        </button>
    );
}

function QuickAction({
    icon: Icon,
    label,
    onClick,
    solid,
}: {
    icon: LucideIcon;
    label: string;
    onClick: () => void;
    solid?: boolean;
}) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={
                solid
                    ? 'inline-flex h-[34px] items-center gap-2 rounded-[9px] bg-primary-foreground px-3.5 text-[12.5px] font-bold text-primary shadow-sm transition-transform hover:scale-[1.02]'
                    : 'inline-flex h-[34px] items-center gap-2 rounded-[9px] border border-primary-foreground/[0.28] bg-primary-foreground/[0.12] px-3.5 text-[12.5px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20'
            }
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
            className={
                active
                    ? 'h-6 rounded-md bg-primary-foreground px-2.5 text-[11px] font-bold text-primary transition-colors'
                    : 'h-6 rounded-md px-2.5 text-[11px] font-bold text-primary-foreground/80 transition-colors hover:text-primary-foreground'
            }
        >
            {label}
        </button>
    );
}

export default ItHero;
