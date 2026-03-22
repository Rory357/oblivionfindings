import { useMemo } from 'react';

type MiniSparklineProps = {
    data: number[];
    width?: number;
    height?: number;
    color?: string;
    fillOpacity?: number;
};

export function MiniSparkline({
    data,
    width = 80,
    height = 32,
    color = 'currentColor',
    fillOpacity = 0.15,
}: MiniSparklineProps) {
    const { linePath, fillPath } = useMemo(() => {
        if (!data.length) return { linePath: '', fillPath: '' };

        const min = Math.min(...data);
        const max = Math.max(...data);
        const range = max - min || 1;
        const padY = 2;
        const innerH = height - padY * 2;
        const step = data.length > 1 ? width / (data.length - 1) : 0;

        const points = data.map((v, i) => ({
            x: i * step,
            y: padY + innerH - ((v - min) / range) * innerH,
        }));

        const line = points
            .map((p, i) => `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(1)} ${p.y.toFixed(1)}`)
            .join(' ');

        const fill =
            line +
            ` L ${points[points.length - 1].x.toFixed(1)} ${height}` +
            ` L 0 ${height} Z`;

        return { linePath: line, fillPath: fill };
    }, [data, width, height]);

    if (!data.length) return null;

    return (
        <svg
            width={width}
            height={height}
            viewBox={`0 0 ${width} ${height}`}
            className="shrink-0"
        >
            {fillOpacity > 0 && (
                <path d={fillPath} fill={color} opacity={fillOpacity} />
            )}
            <path
                d={linePath}
                fill="none"
                stroke={color}
                strokeWidth={1.5}
                strokeLinecap="round"
                strokeLinejoin="round"
            />
        </svg>
    );
}
