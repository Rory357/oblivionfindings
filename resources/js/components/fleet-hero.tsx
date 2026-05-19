import type { ReactNode } from 'react';

import { PageHero } from '@/components/page';

type Props = {
    title: ReactNode;
    description?: ReactNode;
    subtitle?: ReactNode;
    icon?: ReactNode;
    backHref?: string;
    backLabel?: string;
    actions?: ReactNode;
    stats?: Array<{ label: string; value: string | number }>;
    children?: ReactNode;
};

/**
 * @deprecated Use `PageHero` from `@/components/page` directly. This shim maps
 * the legacy `FleetHero` API onto `PageHero` so existing call sites keep working
 * during the platform-wide hero standardisation rollout.
 *
 * Migration: replace `<FleetHero ... />` with `<PageHero ... />`. Props are
 * source-compatible; `subtitle` is an alias for `description`.
 */
export default function FleetHero({
    title,
    description,
    subtitle,
    icon,
    backHref,
    backLabel,
    actions,
    stats,
    children,
}: Props) {
    return (
        <PageHero
            title={title}
            description={description ?? subtitle}
            icon={icon}
            backHref={backHref}
            backLabel={backLabel}
            actions={actions}
            stats={stats?.map((stat) => ({ label: stat.label, value: stat.value }))}
        >
            {children}
        </PageHero>
    );
}
