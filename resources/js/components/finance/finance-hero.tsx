// Finance hero preset. See design_styles/GOVERNANCE_HERO_GUIDE.md for the hero contract.
import { PageHero, type PageHeroProps } from '@/components/page/page-hero';

export type { PageHeroProps } from '@/components/page/page-hero';
export type { PageHeroStat } from '@/components/page/page-hero-stats';

/**
 * Finance hero — a thin wrapper over the shared {@link PageHero} that defaults
 * `category="finance"` so every Finance hub renders the finance-themed gradient
 * (the design spine for this module, mirroring HrHero for HR). Everything else
 * is plain PageHero; pass `category` explicitly to override. Use across all
 * Finance hubs in place of bare PageHero.
 */
export function FinanceHero(props: PageHeroProps) {
    return <PageHero {...props} category={props.category ?? 'finance'} />;
}

export default FinanceHero;
