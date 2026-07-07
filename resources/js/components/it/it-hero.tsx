/* eslint-disable no-restricted-syntax -- The IT hub hero mirrors the gold-standard
 * People hero (resources/js/components/hr/people-hero.tsx): stat chips are
 * link-buttons and the quick-actions / "needs you" chips are bespoke on-gradient
 * surfaces (raw <button>), not shadcn <Button> cases. Every colour is a design
 * token (primary / status-* / --it-amber injected as a CSS var) so tenant
 * white-label theming still propagates. The right-rail donut⇄ring lands in 10b. */
import { router } from '@inertiajs/react';
import { Plus, Server, Sparkles, type LucideIcon } from 'lucide-react';
import { type CSSProperties } from 'react';

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
    };
    provisioning?: {
        pending: number;
        pending_over_7d: number;
    };
}

/** Hero-scoped palette — `--primary` is the tenant brand so the gradient
 *  re-themes per tenant; the bright amber flags attention counts on the band. */
const HERO_STYLE: CSSProperties = {
    ['--it-amber' as string]: 'oklch(0.86 0.13 90)',
    background:
        'linear-gradient(120deg, color-mix(in oklch, var(--primary) 72%, black 22%), var(--primary) 58%, color-mix(in oklch, var(--primary) 90%, white 8%))',
    boxShadow: '0 28px 64px -30px color-mix(in oklch, var(--primary) 86%, black)',
};

type StatChip = { label: string; value: number; href?: string; amber?: boolean };
type NeedChip = { key: string; label: string; href: string };

const go = (href: string) =>
    router.get(href, {}, { preserveState: true, preserveScroll: true, replace: true });

/**
 * The IT & Provisioning hub hero — a brand-gradient command band above the tab
 * strip. Agents get glanceable stat chips (each a deep-link into the filtered
 * queue), a "needs you" attention row and quick actions; requesters get a
 * compact three-stat variant + a Raise CTA. No clock — this is the ops lens.
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

    const agentStats: StatChip[] = t && p
        ? [
              { label: 'Open tickets', value: t.open, href: '/it?tab=tickets&view=all_open' },
              { label: 'Unassigned', value: t.unassigned, href: '/it?tab=tickets&view=unassigned' },
              { label: 'Breaching soon', value: t.at_risk, href: '/it?tab=tickets&view=breaching', amber: t.at_risk > 0 },
              { label: 'Breached', value: t.breached, href: '/it?tab=tickets&view=breached', amber: t.breached > 0 },
              { label: 'Awaiting reply', value: t.awaiting_reply, href: '/it?tab=tickets&view=awaiting_reply' },
              { label: 'Pending provisioning', value: p.pending, href: '/it?tab=provisioning&status=pending' },
              { label: 'Resolved · 30d', value: t.resolved_30d, href: '/it?tab=tickets&view=recently_resolved' },
          ]
        : [];

    // "Needs you" chips only surface when there's actually something to chase.
    const needs: NeedChip[] = t && p
        ? [
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
          ].filter(Boolean) as NeedChip[]
        : [];

    const requesterStats: StatChip[] = [
        { label: 'My open tickets', value: summary.my.open, href: '/it?tab=my-tickets' },
        { label: 'Awaiting my reply', value: summary.my.waiting, href: '/it?tab=my-tickets', amber: summary.my.waiting > 0 },
        { label: 'Resolved · 30d', value: summary.my.resolved_30d },
    ];

    const isAgent = can.view;
    const stats = isAgent ? agentStats : requesterStats;
    const subtitle = isAgent
        ? 'Account, access & equipment requests — and the IT helpdesk queue.'
        : 'Raise an IT ticket and track it here — IT sees new ones instantly.';

    return (
        <div style={HERO_STYLE} className="relative overflow-hidden rounded-[24px] text-primary-foreground">
            {/* decorative orb */}
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-[24px]">
                <div className="absolute -top-20 right-[22%] h-60 w-60 rounded-full bg-primary-foreground/[0.05]" />
            </div>

            <div className="relative p-[30px_34px]">
                <div className="flex flex-wrap items-center justify-between gap-4">
                    <div className="flex items-center gap-4">
                        <span className="grid h-[54px] w-[54px] flex-none place-items-center rounded-2xl border border-primary-foreground/20 bg-primary-foreground/15">
                            <Server className="h-[26px] w-[26px]" />
                        </span>
                        <div className="min-w-0">
                            <h1 className="text-[28px] leading-[1.05] font-bold tracking-tight">
                                IT &amp; Provisioning
                            </h1>
                            <p className="mt-1.5 text-[13px] font-medium text-primary-foreground/75">
                                {subtitle}
                            </p>
                        </div>
                    </div>

                    {/* quick actions */}
                    <div className="flex flex-wrap gap-2">
                        {can.manage ? (
                            <QuickAction icon={Sparkles} label="Log & triage" onClick={onLog} solid />
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
                        ? 'text-[22px] font-bold tabular-nums text-[color:var(--it-amber)]'
                        : 'text-[22px] font-bold tabular-nums'
                }
            >
                {value}
            </span>
        </>
    );
    if (!href) {
        return <span className="flex flex-col items-start gap-0.5 rounded-[10px] px-3 py-2 text-left">{inner}</span>;
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

export default ItHero;
