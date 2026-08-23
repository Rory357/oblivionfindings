/**
 * @deprecated Use `StatTile` from `@/components/page` directly. This module
 * is preserved as a deprecation shim because ~50 dashboard pages import
 * `OpsStatCard`, `DonutChart`, `BarChart`, `SparklineChart`, or `OPS_COLORS`
 * from here. The shim maps the old `color` prop onto `StatTile`'s `tone`
 * vocabulary; the chart helpers re-export from `@/components/charts/ops-charts`.
 */

import type { LucideIcon } from 'lucide-react';

import { StatTile, type StatTileTone } from '@/components/page';

// Re-export chart primitives from their new home so existing imports keep working.
export {
    BarChart,
    DonutChart,
    OPS_COLORS,
    SparklineChart,
    type DonutSegment,
} from '@/components/charts/ops-charts';

const COLOR_TO_TONE: Record<string, StatTileTone> = {
    indigo: 'primary',
    violet: 'primary',
    purple: 'primary',
    blue: 'info',
    cyan: 'info',
    amber: 'warning',
    red: 'critical',
    emerald: 'success',
    slate: 'neutral',
};

interface OpsStatCardProps {
    label: string;
    value: string | number;
    icon: LucideIcon;
    color?: keyof typeof COLOR_TO_TONE;
    subtitle?: string;
    trend?: number[];
    href?: string;
    valueClassName?: string;
    staticValue?: boolean;
}

export function OpsStatCard({
    label,
    value,
    icon,
    color = 'indigo',
    subtitle,
    trend,
    href,
    valueClassName,
    staticValue,
}: OpsStatCardProps) {
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
            staticValue={staticValue}
        />
    );
}
