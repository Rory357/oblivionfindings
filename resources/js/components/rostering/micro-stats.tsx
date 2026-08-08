import type { ReactNode } from 'react';

import { cn } from '@/lib/utils';

export type MicroStatTone = 'ok' | 'info' | 'warn' | 'crit';

export type MicroStat = {
    label: ReactNode;
    value: ReactNode;
    suffix?: string;
    tone: MicroStatTone;
};

const BAR: Record<MicroStatTone, string> = {
    ok: 'bg-status-success',
    info: 'bg-status-info',
    warn: 'bg-status-warning',
    crit: 'bg-status-critical',
};

const TONE_VALUE: Record<MicroStatTone, string> = {
    ok: 'text-status-success',
    info: 'text-status-info',
    warn: 'text-status-warning',
    crit: 'text-status-critical',
};

export function MicroStats({
    stats,
    className,
}: {
    stats: MicroStat[];
    className?: string;
}) {
    return (
        <div className={cn('grid grid-cols-2 gap-3 md:grid-cols-4', className)}>
            {stats.map((s, i) => (
                <div
                    key={i}
                    className="relative overflow-hidden rounded-[14px] border border-border bg-card p-4 pl-5"
                >
                    <span
                        aria-hidden="true"
                        className={cn(
                            'absolute inset-y-0 left-0 w-1 rounded-l-[14px]',
                            BAR[s.tone],
                        )}
                    />
                    <div
                        className={cn(
                            'text-2xl font-bold tracking-tight tabular-nums',
                            TONE_VALUE[s.tone],
                        )}
                    >
                        {s.value}
                        {s.suffix ?? ''}
                    </div>
                    <div className="mt-0.5 text-[11px] font-semibold tracking-wider text-muted-foreground uppercase">
                        {s.label}
                    </div>
                </div>
            ))}
        </div>
    );
}

export default MicroStats;
