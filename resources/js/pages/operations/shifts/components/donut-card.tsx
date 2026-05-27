import { ArrowRight, ChevronRight } from 'lucide-react';

export type DonutSegment = {
    key: string;
    label: string;
    value: number;
    color: string;
};

type Props = {
    tone: 'primary' | 'warning' | 'success';
    title: string;
    subtitle: string;
    segments: DonutSegment[];
    centerValue: number | string;
    centerLabel: string;
    cta?: string;
    active?: boolean;
    onClick?: () => void;
};

const TONE_COLOR: Record<Props['tone'], string> = {
    primary: 'var(--primary)',
    warning: 'var(--status-warning)',
    success: 'var(--status-success)',
};

export function DonutCard({
    tone,
    title,
    subtitle,
    segments,
    centerValue,
    centerLabel,
    cta,
    active = false,
    onClick,
}: Props) {
    const total = segments.reduce((a, s) => a + s.value, 0) || 1;
    const radius = 38;
    const circumference = 2 * Math.PI * radius;
    let offset = 0;
    const accent = TONE_COLOR[tone];

    return (
        <button
            type="button"
            onClick={onClick}
            className={[
                'relative overflow-hidden rounded-xl border bg-card p-4 text-left transition-all focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring',
                active
                    ? 'border-primary shadow-sm ring-1 ring-primary/30'
                    : 'border-border hover:border-primary/40',
            ].join(' ')}
        >
            <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                    <div className="flex items-center gap-2">
                        <span
                            className="h-2 w-2 rounded-full"
                            style={{ background: accent }}
                        />
                        <div className="text-sm font-semibold text-foreground">
                            {title}
                        </div>
                    </div>
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        {subtitle}
                    </div>
                </div>
                <ChevronRight className="h-4 w-4 text-muted-foreground" />
            </div>

            <div className="mt-3 flex items-center gap-4">
                <div className="relative shrink-0">
                    <svg
                        width="100"
                        height="100"
                        viewBox="0 0 100 100"
                        style={{ transform: 'rotate(-90deg)' }}
                    >
                        <circle
                            cx="50"
                            cy="50"
                            r={radius}
                            fill="none"
                            stroke="var(--muted)"
                            strokeWidth="12"
                        />
                        {segments.map((s, i) => {
                            const len = (s.value / total) * circumference;
                            const el = (
                                <circle
                                    key={s.key + i}
                                    cx="50"
                                    cy="50"
                                    r={radius}
                                    fill="none"
                                    stroke={s.color}
                                    strokeWidth="12"
                                    strokeDasharray={`${len} ${circumference - len}`}
                                    strokeDashoffset={-offset}
                                    strokeLinecap="butt"
                                />
                            );
                            offset += len;
                            return el;
                        })}
                    </svg>
                    <div className="absolute inset-0 flex flex-col items-center justify-center">
                        <div className="text-xl font-bold tracking-tight text-foreground tabular-nums">
                            {centerValue}
                        </div>
                        <div className="-mt-0.5 text-[10px] text-muted-foreground">
                            {centerLabel}
                        </div>
                    </div>
                </div>
                <ul className="min-w-0 flex-1 space-y-1.5">
                    {segments.length === 0 ? (
                        <li className="text-xs text-muted-foreground">
                            Nothing here yet
                        </li>
                    ) : (
                        segments.map((s) => (
                            <li
                                key={s.key}
                                className="flex items-center justify-between gap-2 text-xs"
                            >
                                <span className="flex min-w-0 items-center gap-1.5">
                                    <span
                                        className="h-2 w-2 shrink-0 rounded-full"
                                        style={{ background: s.color }}
                                    />
                                    <span className="truncate text-foreground">
                                        {s.label}
                                    </span>
                                </span>
                                <span className="shrink-0 font-medium text-foreground tabular-nums">
                                    {s.value}
                                </span>
                            </li>
                        ))
                    )}
                </ul>
            </div>

            {cta ? (
                <div className="mt-3 flex items-center justify-between border-t border-border pt-3 text-xs">
                    <span className="text-muted-foreground">{cta}</span>
                    <span className="inline-flex items-center gap-1 font-medium text-primary">
                        View <ArrowRight className="h-3 w-3" />
                    </span>
                </div>
            ) : null}
        </button>
    );
}
