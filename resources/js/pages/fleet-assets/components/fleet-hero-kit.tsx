/* Fleet & Assets shared hero kit.
 *
 * The generic command-centre primitives (shell, status pill, medallion, clusters,
 * tiles, segmented controls, summary strip, `fmt`) are truly module-agnostic in
 * `hs-hero-kit`, so they are re-exported from there — one source of hero chrome.
 * This file adds only the fleet-specific pieces:
 *   - FleetComplianceBadges — WOF / Rego / CoF / Insurance / Open-alert chips fed
 *     by raw counts (never pre-formatted strings), same spirit as the H&S
 *     HeroComplianceBadges. Chips are optionally clickable via `href`.
 *   - FleetAttentionStrip — the conditional "Needs attention" escalation band
 *     (overdue returns / outings past return / critical alerts) rendered under
 *     the hero identity row; always org-wide, hidden when all clear.
 *   - FleetHeroAction — the on-dark quick-action button/link used across the
 *     fleet heroes (Book vehicle, Log fuel, …) so the pages don't hand-roll it.
 *
 * Semantic tokens only (no raw hex/oklch); app-primary gradient via HeroShell. */
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    Car,
    CheckCircle2,
    Clock,
    FileCheck2,
    type LucideIcon,
    ShieldCheck,
    Umbrella,
} from 'lucide-react';
import { type ReactNode } from 'react';

export {
    DOT_CLASS,
    fmt,
    HeroCluster,
    HeroClusterTile,
    HeroMedallion,
    HeroSegmented,
    HeroShell,
    HeroStatusPill,
    HeroSummaryMetric,
    HeroSummaryStrip,
    type HeroSegItem,
    type Tone,
} from '@/pages/health-safety/components/hs-hero-kit';

/* ------------------------------------------------------------------ */
/*  Fleet compliance badges                                            */
/* ------------------------------------------------------------------ */

type BadgeTone = 'success' | 'warning' | 'critical';

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

type FleetBadge = {
    key: string;
    icon: LucideIcon;
    tone: BadgeTone;
    label: string;
    href?: string;
};

/** Optional per-chip link targets — omit a key and that chip renders as a static span. */
export type FleetComplianceHrefs = {
    wof?: string;
    rego?: string;
    cof?: string;
    insurance?: string;
    alerts?: string;
};

/** The five canonical NZ fleet compliance chips — WOF, Registration, CoF,
 *  Insurance (expired outranks due-soon for each) and open Control-Room alerts. Fed by counts so every
 *  fleet hero reads identically; each chip goes green in its all-clear state.
 *  Pass `insuranceExpiring: null` (schema without the column) to hide that chip. */
export function FleetComplianceBadges({
    wofDue = 0,
    wofExpired = 0,
    regoDue = 0,
    regoExpired = 0,
    cofDue = 0,
    cofExpired = 0,
    insuranceExpiring = null,
    insuranceExpired = null,
    openAlerts = 0,
    criticalAlerts = 0,
    hrefs = {},
    className,
}: {
    /** WOF expiring within 30 days (not yet expired) → warning. */
    wofDue?: number;
    /** WOF already past its date → critical; outranks `wofDue`. */
    wofExpired?: number;
    regoDue?: number;
    regoExpired?: number;
    cofDue?: number;
    cofExpired?: number;
    /** `null` hides the chip (insurance column not present in this schema). */
    insuranceExpiring?: number | null;
    /** `null` hides the chip when `insuranceExpiring` is also null. */
    insuranceExpired?: number | null;
    openAlerts?: number;
    /** Any critical among the open alerts escalates the alert chip to critical. */
    criticalAlerts?: number;
    hrefs?: FleetComplianceHrefs;
    className?: string;
}) {
    const wofTone: BadgeTone = wofExpired > 0 ? 'critical' : wofDue > 0 ? 'warning' : 'success';
    const wofLabel =
        wofExpired > 0
            ? `WOF · ${wofExpired} expired`
            : wofDue > 0
              ? `WOF · ${wofDue} due 30d`
              : 'WOF · Current';

    const regoTone: BadgeTone = regoExpired > 0 ? 'critical' : regoDue > 0 ? 'warning' : 'success';
    const regoLabel =
        regoExpired > 0
            ? `Rego · ${regoExpired} expired`
            : regoDue > 0
              ? `Rego · ${regoDue} due 30d`
              : 'Rego · Current';

    const cofTone: BadgeTone = cofExpired > 0 ? 'critical' : cofDue > 0 ? 'warning' : 'success';
    const cofLabel =
        cofExpired > 0
            ? `CoF · ${cofExpired} expired`
            : cofDue > 0
              ? `CoF · ${cofDue} due 30d`
              : 'CoF · Current';

    const insuranceSupported = insuranceExpiring !== null || insuranceExpired !== null;
    const insuranceExpiredCount = insuranceExpired ?? 0;
    const insuranceExpiringCount = insuranceExpiring ?? 0;
    const insuranceTone: BadgeTone =
        insuranceExpiredCount > 0 ? 'critical' : insuranceExpiringCount > 0 ? 'warning' : 'success';
    const insuranceLabel =
        insuranceExpiredCount > 0
            ? `Insurance · ${insuranceExpiredCount} expired`
            : insuranceExpiringCount > 0
              ? `Insurance · ${insuranceExpiringCount} expiring`
              : 'Insurance · Current';

    const alertTone: BadgeTone = criticalAlerts > 0 ? 'critical' : openAlerts > 0 ? 'warning' : 'success';

    const badges: (FleetBadge | null)[] = [
        {
            key: 'wof',
            icon: wofTone === 'success' ? CheckCircle2 : AlertTriangle,
            tone: wofTone,
            label: wofLabel,
            href: hrefs.wof,
        },
        {
            key: 'rego',
            icon: regoTone === 'success' ? CheckCircle2 : AlertTriangle,
            tone: regoTone,
            label: regoLabel,
            href: hrefs.rego,
        },
        {
            key: 'cof',
            icon: FileCheck2,
            tone: cofTone,
            label: cofLabel,
            href: hrefs.cof,
        },
        !insuranceSupported
            ? null
            : {
                  key: 'insurance',
                  icon: Umbrella,
                  tone: insuranceTone,
                  label: insuranceLabel,
                  href: hrefs.insurance,
              },
        {
            key: 'alerts',
            icon: alertTone === 'success' ? ShieldCheck : Bell,
            tone: alertTone,
            label: openAlerts > 0 ? `Alerts · ${openAlerts} open` : 'Alerts · All clear',
            href: hrefs.alerts,
        },
    ];

    return (
        <div className={cn('flex flex-wrap gap-2', className)}>
            {badges
                .filter((b): b is FleetBadge => b !== null)
                .map((b) => {
                    const chipClass = cn(
                        'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium',
                        CHIP_CLASS[b.tone],
                        b.href && 'transition-colors hover:bg-primary-foreground/20',
                    );
                    const inner = (
                        <>
                            <b.icon className={cn('h-3.5 w-3.5', CHIP_ICON[b.tone])} />
                            {b.label}
                        </>
                    );
                    return b.href ? (
                        <Link key={b.key} href={b.href} className={chipClass}>
                            {inner}
                        </Link>
                    ) : (
                        <span key={b.key} className={chipClass}>
                            {inner}
                        </span>
                    );
                })}
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Attention strip                                                    */
/* ------------------------------------------------------------------ */

/** Optional per-chip link overrides for the attention strip. */
export type FleetAttentionHrefs = {
    overdue?: string;
    outings?: string;
    alerts?: string;
};

/** The hero's escalation band — overdue vehicle returns, outings past their
 *  return time and critical Control-Room alerts, as deep-linked chips under a
 *  "Needs attention" eyebrow. Renders nothing when everything is clear, sits
 *  directly under the identity row, and is always fed org-wide counts (the
 *  safeguarding signal out-ranks any scope lens). Tone is never colour-only:
 *  every chip carries its count + noun. */
export function FleetAttentionStrip({
    overdueReturns = 0,
    outingsPastReturn = 0,
    criticalAlerts = 0,
    hrefs = {},
    className,
}: {
    /** Checked-out bookings past their end time → critical chip. */
    overdueReturns?: number;
    /** Active outings past their planned return → warning chip. */
    outingsPastReturn?: number;
    /** Open critical Control-Room alerts → critical chip. */
    criticalAlerts?: number;
    hrefs?: FleetAttentionHrefs;
    className?: string;
}) {
    if (overdueReturns <= 0 && outingsPastReturn <= 0 && criticalAlerts <= 0) {
        return null;
    }

    const chips: FleetBadge[] = [];
    if (overdueReturns > 0) {
        chips.push({
            key: 'overdue',
            icon: Car,
            tone: 'critical',
            label: `${overdueReturns} overdue vehicle return${overdueReturns === 1 ? '' : 's'}`,
            href: hrefs.overdue ?? '/fleet-assets/bookings',
        });
    }
    if (outingsPastReturn > 0) {
        chips.push({
            key: 'outings',
            icon: Clock,
            tone: 'warning',
            label: `${outingsPastReturn} outing${outingsPastReturn === 1 ? '' : 's'} past return time`,
            href: hrefs.outings ?? '/fleet-assets/outings',
        });
    }
    if (criticalAlerts > 0) {
        chips.push({
            key: 'alerts',
            icon: AlertTriangle,
            tone: 'critical',
            label: `${criticalAlerts} critical alert${criticalAlerts === 1 ? '' : 's'}`,
            href: hrefs.alerts ?? '/fleet-assets/alerts',
        });
    }

    return (
        <div
            role="status"
            className={cn(
                'flex flex-wrap items-center gap-2.5 rounded-xl border border-status-critical/50 bg-status-critical/20 px-3.5 py-2.5',
                className,
            )}
        >
            <AlertTriangle className="h-4 w-4 shrink-0 text-primary-foreground" />
            <span className="text-[11px] font-bold tracking-[0.07em] text-primary-foreground uppercase">
                Needs attention
            </span>
            <div className="flex flex-wrap gap-2">
                {chips.map((chip) => (
                    <Link
                        key={chip.key}
                        href={chip.href!}
                        className={cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-medium transition-colors hover:bg-primary-foreground/20',
                            CHIP_CLASS[chip.tone],
                        )}
                    >
                        <chip.icon className={cn('h-3.5 w-3.5', CHIP_ICON[chip.tone])} />
                        {chip.label}
                    </Link>
                ))}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Reference chip                                                     */
/* ------------------------------------------------------------------ */

/** Monospace reference-number chip (e.g. WO-2026-0001) — the single canonical
 *  rendering for stored fleet reference numbers on light surfaces. Renders '—'
 *  when the record has no reference. */
export function RefChip({ value, className }: { value: string | null | undefined; className?: string }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded-md border border-border bg-muted/60 px-1.5 py-0.5 font-mono text-[11px] font-medium text-muted-foreground',
                className,
            )}
        >
            {value ?? '—'}
        </span>
    );
}

/* ------------------------------------------------------------------ */
/*  Quick actions (on-dark)                                            */
/* ------------------------------------------------------------------ */

type FleetHeroActionProps = {
    icon: LucideIcon;
    children: ReactNode;
    emphasis?: boolean;
} & (
    | {
          href: string;
          /** Plain <a> for non-Inertia targets (e.g. CSV export downloads). */
          external?: boolean;
          onClick?: never;
      }
    | {
          href?: never;
          external?: never;
          onClick: () => void;
      }
);

/** On-dark quick action for the fleet heroes. `emphasis` renders the solid
 *  primary-foreground variant (the single headline action); default is the
 *  translucent bordered variant. Supports links, downloads, and true button
 *  actions without duplicating the shared focus and colour tokens. */
export function FleetHeroAction(props: FleetHeroActionProps) {
    const { icon: Icon, children, emphasis = false } = props;
    const className = cn(
        'inline-flex h-[34px] items-center gap-2 rounded-lg px-3.5 text-[12.5px] font-semibold transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary-foreground/40',
        emphasis
            ? 'bg-primary-foreground font-extrabold text-primary shadow-sm hover:bg-primary-foreground/90'
            : 'border border-primary-foreground/25 bg-primary-foreground/10 text-primary-foreground hover:bg-primary-foreground/20',
    );
    const inner = (
        <>
            <Icon className="h-[15px] w-[15px]" />
            {children}
        </>
    );

    if ('onClick' in props) {
        return (
            // eslint-disable-next-line no-restricted-syntax -- shared on-dark hero action needs the same chrome for button and link semantics.
            <button type="button" onClick={props.onClick} className={className}>
                {inner}
            </button>
        );
    }

    const { href, external = false } = props;

    return external ? (
        <a href={href} className={className}>
            {inner}
        </a>
    ) : (
        <Link href={href} className={className}>
            {inner}
        </Link>
    );
}
