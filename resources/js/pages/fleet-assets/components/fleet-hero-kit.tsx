/* Fleet & Assets shared hero kit.
 *
 * The generic command-centre primitives (shell, status pill, medallion, clusters,
 * tiles, segmented controls, summary strip, `fmt`) are truly module-agnostic in
 * `hs-hero-kit`, so they are re-exported from there — one source of hero chrome.
 * This file adds only the fleet-specific pieces:
 *   - FleetComplianceBadges — WOF / Rego / CoF / Insurance / Open-alert chips fed
 *     by raw counts (never pre-formatted strings), same spirit as the H&S
 *     HeroComplianceBadges. Chips are optionally clickable via `href`.
 *   - FleetHeroAction — the on-dark quick-action button/link used across the
 *     fleet heroes (Book vehicle, Log fuel, …) so the pages don't hand-roll it.
 *
 * Semantic tokens only (no raw hex/oklch); app-primary gradient via HeroShell. */
import { cn } from '@/lib/utils';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    Bell,
    CheckCircle2,
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

/** The five canonical NZ fleet compliance chips — WOF (expired outranks due-soon),
 *  Registration, CoF, Insurance and open Control-Room alerts. Fed by counts so every
 *  fleet hero reads identically; each chip goes green in its all-clear state.
 *  Pass `insuranceExpiring: null` (schema without the column) to hide that chip. */
export function FleetComplianceBadges({
    wofDue = 0,
    wofExpired = 0,
    regoDue = 0,
    cofDue = 0,
    insuranceExpiring = null,
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
    cofDue?: number;
    /** `null` hides the chip (insurance column not present in this schema). */
    insuranceExpiring?: number | null;
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
            icon: regoDue > 0 ? AlertTriangle : CheckCircle2,
            tone: regoDue > 0 ? 'warning' : 'success',
            label: regoDue > 0 ? `Rego · ${regoDue} due 30d` : 'Rego · Current',
            href: hrefs.rego,
        },
        {
            key: 'cof',
            icon: FileCheck2,
            tone: cofDue > 0 ? 'warning' : 'success',
            label: cofDue > 0 ? `CoF · ${cofDue} due 30d` : 'CoF · Current',
            href: hrefs.cof,
        },
        insuranceExpiring === null
            ? null
            : {
                  key: 'insurance',
                  icon: Umbrella,
                  tone: insuranceExpiring > 0 ? 'warning' : 'success',
                  label: insuranceExpiring > 0 ? `Insurance · ${insuranceExpiring} expiring` : 'Insurance · Current',
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
/*  Quick actions (on-dark)                                            */
/* ------------------------------------------------------------------ */

/** On-dark quick-action link for the fleet heroes. `emphasis` renders the solid
 *  primary-foreground variant (the single headline action); default is the
 *  translucent bordered variant. Links to existing pages/flows only. */
export function FleetHeroAction({
    href,
    icon: Icon,
    children,
    emphasis = false,
    external = false,
}: {
    href: string;
    icon: LucideIcon;
    children: ReactNode;
    emphasis?: boolean;
    /** Plain <a> for non-Inertia targets (e.g. CSV export downloads). */
    external?: boolean;
}) {
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
