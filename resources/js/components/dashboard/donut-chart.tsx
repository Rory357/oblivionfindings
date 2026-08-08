import { useMemo } from 'react';

type DonutSegment = {
    label: string;
    value: number;
    color: string;
};

type DonutChartProps = {
    data: DonutSegment[];
    size?: number;
    thickness?: number;
    centerLabel?: string;
    centerValue?: string | number;
};

export function DonutChart({
    data,
    size = 160,
    thickness = 24,
    centerLabel,
    centerValue,
}: DonutChartProps) {
    const cx = size / 2;
    const cy = size / 2;
    const r = (size - thickness) / 2;
    const circumference = 2 * Math.PI * r;

    const segments = useMemo(() => {
        const total = data.reduce((sum, d) => sum + (d.value || 0), 0);
        if (!total) return { items: [], total: 0 };

        let offset = 0;
        const items = data
            .filter((d) => d.value > 0)
            .map((d) => {
                const frac = d.value / total;
                const dash = frac * circumference;
                const seg = { ...d, dash, gap: circumference - dash, offset };
                offset += dash;
                return seg;
            });

        return { items, total };
    }, [data, circumference]);

    return (
        <div className="flex flex-col items-center gap-4">
            <svg width={size} height={size} className="shrink-0">
                {/* background ring */}
                <circle
                    cx={cx}
                    cy={cy}
                    r={r}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={thickness}
                    className="text-muted/30"
                />

                {/* segments */}
                {segments.items.map((s, i) => (
                    <circle
                        key={`${s.label}-${i}`}
                        cx={cx}
                        cy={cy}
                        r={r}
                        fill="none"
                        stroke={s.color}
                        strokeWidth={thickness}
                        strokeDasharray={`${s.dash} ${s.gap}`}
                        strokeDashoffset={-s.offset}
                        strokeLinecap="butt"
                        transform={`rotate(-90 ${cx} ${cy})`}
                    />
                ))}

                {/* center text */}
                {centerValue !== undefined && (
                    <text
                        x={cx}
                        y={centerLabel ? cy - 4 : cy + 4}
                        textAnchor="middle"
                        className="fill-foreground text-xl font-bold"
                        dominantBaseline="middle"
                    >
                        {centerValue}
                    </text>
                )}
                {centerLabel && (
                    <text
                        x={cx}
                        y={cy + 16}
                        textAnchor="middle"
                        className="fill-muted-foreground text-[10px]"
                        dominantBaseline="middle"
                    >
                        {centerLabel}
                    </text>
                )}
            </svg>

            {/* legend */}
            {segments.items.length > 0 && (
                <div className="flex flex-wrap justify-center gap-x-4 gap-y-1">
                    {segments.items.map((s, i) => (
                        <div
                            key={`${s.label}-${i}`}
                            className="flex items-center gap-1.5 text-xs"
                        >
                            <span
                                className="inline-block h-2.5 w-2.5 shrink-0 rounded-full"
                                style={{ backgroundColor: s.color }}
                            />
                            <span className="text-muted-foreground">
                                {s.label}
                            </span>
                            <span className="font-medium">{s.value}</span>
                        </div>
                    ))}
                </div>
            )}
        </div>
    );
}
