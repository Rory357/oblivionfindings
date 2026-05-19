import { Utensils } from 'lucide-react';
import type { ComponentType, ReactNode } from 'react';

import { PageHero } from '@/components/page';

type HeroBadge = {
    icon?: ComponentType<{ className?: string }>;
    label: string;
    tone?: 'default' | 'warning';
};

type HeroStat = {
    value: ReactNode;
    label: string;
};

/**
 * @deprecated Use `PageHero` from `@/components/page` directly. This component
 * is preserved only to keep the existing catering pages working during the
 * platform-wide hero standardisation rollout.
 */
export function CateringHero({
    title = 'Meal Planner',
    subtitle = 'Cross-site overview of meal plans, kitchen inventory and the catering library.',
    badges = [],
    stats = [],
}: {
    title?: string;
    subtitle?: string;
    badges?: HeroBadge[];
    stats?: HeroStat[];
}) {
    return (
        <PageHero
            icon={Utensils}
            title={title}
            description={subtitle}
            badges={badges.map((b) => ({
                icon: b.icon,
                label: b.label,
                tone: b.tone === 'warning' ? 'warning' : 'default',
            }))}
            stats={stats.map((s) => ({ label: s.label, value: s.value }))}
        />
    );
}
