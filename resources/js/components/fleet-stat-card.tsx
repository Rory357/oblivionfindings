/**
 * @deprecated Use `StatTile` from `@/components/page` directly. This shim
 * keeps the 134 existing `FleetStatCard` call sites working by mapping the
 * legacy `color` prop onto `StatTile`'s `tone` vocabulary.
 */

import type { LucideIcon } from 'lucide-react';

import { StatTile, type StatTileTone } from '@/components/page';

const COLOR_TO_TONE: Record<string, StatTileTone> = {
    purple: 'primary',
    blue: 'info',
    amber: 'warning',
    cyan: 'info',
    red: 'critical',
    slate: 'neutral',
};

interface FleetStatCardProps {
    label: string;
    value: string | number;
    icon: LucideIcon;
    color?: keyof typeof COLOR_TO_TONE;
    subtitle?: string;
    trend?: number[];
    href?: string;
    valueClassName?: string;
}

export function FleetStatCard({
    label,
    value,
    icon,
    color = 'purple',
    subtitle,
    trend,
    href,
    valueClassName,
}: FleetStatCardProps) {
    const tone = COLOR_TO_TONE[color] ?? 'primary';
    return (
        <StatTile
            label={label}
            value={value}
            icon={icon}
            tone={tone}
            subtitle={subtitle}
            trend={trend}
            href={href}
            valueClassName={valueClassName}
        />
    );
}
