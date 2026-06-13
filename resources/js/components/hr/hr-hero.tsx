// HR hero preset. See docs/GOVERNANCE_HERO_GUIDE.md for the hero contract.
import { PageHero, type PageHeroProps } from '@/components/page/page-hero';

export type { PageHeroProps } from '@/components/page/page-hero';
export type { PageHeroStat } from '@/components/page/page-hero-stats';

/**
 * HR hero — a thin wrapper over the shared {@link PageHero} that defaults
 * `category="hr"` so every HR hub renders the HR-themed gradient (the design
 * spine for this module). Everything else is plain PageHero; pass `category`
 * explicitly to override. Use across all HR hubs in place of bare PageHero.
 */
export function HrHero(props: PageHeroProps) {
    return <PageHero {...props} category={props.category ?? 'hr'} />;
}

/**
 * Personalised greeting used across HR heroes — the repo convention is
 * "Kia ora {firstName}, …". Falls back to a generic noun when no name is known.
 */
export function kiaOra(firstName?: string | null, fallback = 'team'): string {
    const name = (firstName ?? '').trim();
    return `Kia ora ${name || fallback}`;
}

export default HrHero;
