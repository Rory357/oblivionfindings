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

    const { remaining, percentage, fillColor, dashArray, dashOffset } = useMemo(() => {
        const safeMax = max || 1;
        const remaining = Math.max(0, safeMax - value);
        const percentage = Math.min(100, (remaining / safeMax) * 100);

        const cx = size / 2;
        const cy = size / 2;
        const r = (size - thickness) / 2;
        const circumference = 2 * Math.PI * r;

        const usedFraction = Math.min(1, value / safeMax);
        const dashArray = `${circumference}`;
        const dashOffset = circumference * (1 - usedFraction);

        // Colour based on remaining percentage
        let fillColor = color;
        if (!fillColor) {
            if (percentage > 50) fillColor = '#10b981'; // emerald
            else if (percentage > 20) fillColor = '#f59e0b'; // amber
            else fillColor = '#ef4444'; // red
        }

        return { remaining, percentage, fillColor, dashArray, dashOffset };
    }, [value, max, size, thickness, color]);

    const cx = size / 2;
    const cy = size / 2;
    const r = (size - thickness) / 2;

    return (
        <div className="flex flex-col items-center gap-1.5">
            <svg width={size} height={size} className="shrink-0 -rotate-90">
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
                    strokeDasharray={dashArray}
                    strokeDashoffset={mounted ? dashOffset : dashArray}
                    strokeLinecap="round"
                    style={{
                        transition: 'stroke-dashoffset 0.8s ease-out',
                    }}
                />
            </svg>

            {/* Centre text overlay */}
            <div
                className="absolute flex flex-col items-center justify-center"
                style={{ width: size, height: size }}
            >
                <span className="text-lg font-bold leading-none">
                    {remaining.toFixed(remaining % 1 === 0 ? 0 : 1)}
                </span>
                <span className="text-[10px] text-muted-foreground">{unit} left</span>
            </div>

            {/* Label below */}
            <span className="max-w-[120px] truncate text-center text-xs font-medium text-muted-foreground">
                {label}
            </span>
        </div>
    );
}
