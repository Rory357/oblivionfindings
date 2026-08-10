/**
 * Canonical finance chart palette — CSS variables only, so charts follow the
 * design tokens in light AND dark mode. Replaces the hardcoded hex
 * `CHART_COLORS` arrays that drifted per page. SVG `fill`/`stroke` attributes
 * resolve `var()` at paint time, so these work directly as recharts colour
 * props.
 */
export const CHART_PALETTE: string[] = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
    'var(--category-finance)',
    'var(--status-info)',
    'var(--status-warning)',
];

export const chartColor = (index: number): string =>
    CHART_PALETTE[index % CHART_PALETTE.length];
