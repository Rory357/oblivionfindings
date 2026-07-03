/* Compact fleet hero band — the single-row variant of the full HeroShell used
 * on thick form/detail pages (transport create/show, outing create) where the
 * page content should keep the vertical space. Same app-primary gradient
 * chrome as HeroShell / the map page's slim band; semantic tokens only. */
import { cn } from '@/lib/utils';
import { DOT_CLASS, HeroStatusPill, type Tone } from '@/pages/fleet-assets/components/fleet-hero-kit';
import { Link } from '@inertiajs/react';
import { ArrowLeft } from 'lucide-react';
import { type ReactNode } from 'react';

/** Compact dot-led metric for the `stats` slot of FleetCompactHero — value-first,
 *  no tile chrome, so the band stays a single slim row. `href` makes it a link. */
export function CompactHeroStat({
    label,
    value,
    tone,
    href,
}: {
    label: string;
    value: string;
    tone: Tone;
    href?: string;
}) {
    const inner = (
        <>
            <span className={cn('h-1.5 w-1.5 shrink-0 rounded-full', DOT_CLASS[tone])} />
            <span className="text-lg leading-none font-bold tabular-nums text-primary-foreground">{value}</span>
            <span className="text-[10.5px] font-semibold tracking-wide text-primary-foreground/70 uppercase">{label}</span>
        </>
    );
    const base = 'inline-flex items-center gap-1.5 rounded-lg px-2 py-1';
    return href ? (
        <Link href={href} className={cn(base, 'transition-colors hover:bg-primary-foreground/15')}>
            {inner}
        </Link>
    ) : (
        <span className={base}>{inner}</span>
    );
}

export function FleetCompactHero({
    pill,
    title,
    backHref,
    backLabel = 'Back',
    stats,
    actions,
}: {
    /** Contextual status-pill text (e.g. "Resident transports · new entry"). */
    pill: ReactNode;
    title: ReactNode;
    backHref?: string;
    backLabel?: string;
    /** Optional slim on-dark stat row (e.g. the live-map counts) — right-aligned before `actions`. */
    stats?: ReactNode;
    /** Optional on-dark actions rendered at the end of the band. */
    actions?: ReactNode;
}) {
    return (
        <div className="relative shrink-0 overflow-hidden rounded-2xl bg-gradient-to-br from-primary/90 via-primary to-primary/80 text-primary-foreground shadow-[0_24px_60px_-28px_color-mix(in_oklch,var(--primary)_55%,transparent)]">
            <div className="pointer-events-none absolute inset-0 overflow-hidden rounded-2xl">
                <div className="absolute -top-20 -right-16 h-48 w-48 rounded-full bg-primary-foreground/5" />
                <div className="absolute -bottom-24 left-1/3 h-40 w-40 rounded-full bg-primary-foreground/5" />
            </div>
            <div className="relative flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5">
                {backHref && (
                    <Link
                        href={backHref}
                        className="inline-flex h-[30px] items-center gap-1.5 rounded-lg border border-primary-foreground/25 bg-primary-foreground/10 px-2.5 text-[12px] font-semibold text-primary-foreground transition-colors hover:bg-primary-foreground/20 focus-visible:ring-2 focus-visible:ring-primary-foreground/40 focus-visible:outline-none"
                    >
                        <ArrowLeft className="h-3.5 w-3.5" />
                        {backLabel}
                    </Link>
                )}
                <HeroStatusPill>{pill}</HeroStatusPill>
                <h1 className="text-lg leading-none font-bold tracking-tight">{title}</h1>
                {stats && (
                    <div className="ml-auto flex flex-wrap items-center gap-1">{stats}</div>
                )}
                {actions && (
                    <div className={cn('flex flex-wrap items-center gap-2', !stats && 'ml-auto')}>{actions}</div>
                )}
            </div>
        </div>
    );
}
