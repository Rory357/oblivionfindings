import { useEffect, useMemo, useState } from 'react';

type CircularGaugeProps = {
    value: number;
    max: number;
    label: string;
    unit?: string;
    size?: number;
    thickness?: number;
    color?: string;
};

export function CircularGauge({
    value,
    max,
    label,
    unit = 'hrs',
    size = 120,
    thickness = 10,
    color,
}: CircularGaugeProps) {
    const [mounted, setMounted] = useState(false);

    useEffect(() => {
        const id = requestAnimationFrame(() => setMounted(true));
        return () => cancelAnimationFrame(id);
    }, []);

    const cx = size / 2;
    const cy = size / 2;
    const r = (size - thickness) / 2;
    const circumference = 2 * Math.PI * r;

    const { remaining, fillColor, dashOffset } = useMemo(() => {
        const safeMax = max || 1;
        const remaining = Math.max(0, safeMax - value);
        const percentage = Math.min(100, (remaining / safeMax) * 100);

        const usedFraction = Math.min(1, value / safeMax);
        const dashOffset = circumference * (1 - usedFraction);

        let fillColor = color;
        if (!fillColor) {
            if (percentage > 50) fillColor = '#10b981';
            else if (percentage > 20) fillColor = '#f59e0b';
            else fillColor = '#ef4444';
        }

        return { remaining, fillColor, dashOffset };
    }, [value, max, circumference, color]);

    const displayValue =
        remaining % 1 === 0 ? remaining.toFixed(0) : remaining.toFixed(1);

    return (
        <div className="flex flex-col items-center gap-1.5">
            <svg
                width={size}
                height={size}
                viewBox={`0 0 ${size} ${size}`}
                className="shrink-0"
            >
                {/* Background ring */}
                <circle
                    cx={cx}
                    cy={cy}
                    r={r}
                    fill="none"
                    stroke="currentColor"
                    strokeWidth={thickness}
                    className="text-muted/20"
                />

                {/* Used portion arc */}
                <circle
                    cx={cx}
                    cy={cy}
                    r={r}
                    fill="none"
                    stroke={fillColor}
                    strokeWidth={thickness}
                    strokeDasharray={circumference}
                    strokeDashoffset={mounted ? dashOffset : circumference}
                    strokeLinecap="round"
                    transform={`rotate(-90 ${cx} ${cy})`}
                    style={{
                        transition: 'stroke-dashoffset 0.8s ease-out',
                    }}
                />

                {/* Centre text — value */}
                <text
                    x={cx}
                    y={cy - 4}
                    textAnchor="middle"
                    dominantBaseline="middle"
                    className="fill-foreground text-xl font-bold"
                >
                    {displayValue}
                </text>

                {/* Centre text — unit */}
                <text
                    x={cx}
                    y={cy + 14}
                    textAnchor="middle"
                    dominantBaseline="middle"
                    className="fill-muted-foreground text-[10px]"
                >
                    {unit} left
                </text>
            </svg>

            {/* Label below */}
            <span className="max-w-[120px] truncate text-center text-xs font-medium text-muted-foreground">
                {label}
            </span>
        </div>
    );
}
