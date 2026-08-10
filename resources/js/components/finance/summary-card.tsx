import type { ComponentType, ReactNode } from 'react';

import { Card, CardContent } from '@/components/ui/card';
import { cn } from '@/lib/utils';

/**
 * The finance hub KPI/summary card — a tinted icon badge beside a label and a
 * bold value. Extracted verbatim from the identical blocks that were copy-pasted
 * across the Receivables (invoices) and Payables (bills) index heroes, so the
 * card renders identically everywhere and future tweaks live in one place.
 *
 * Tones map to the design-token status palette; never pass raw colours.
 */
export type FinanceSummaryTone =
    | 'info'
    | 'success'
    | 'warning'
    | 'critical'
    | 'muted';

const TONE: Record<FinanceSummaryTone, { bg: string; fg: string }> = {
    info: { bg: 'bg-status-info-bg', fg: 'text-status-info' },
    success: { bg: 'bg-status-success-bg', fg: 'text-status-success' },
    warning: { bg: 'bg-status-warning-bg', fg: 'text-status-warning' },
    critical: { bg: 'bg-status-critical-bg', fg: 'text-status-critical' },
    muted: { bg: 'bg-muted', fg: 'text-muted-foreground' },
};

export function FinanceSummaryCard({
    icon: Icon,
    tone = 'info',
    label,
    value,
}: {
    icon: ComponentType<{ className?: string }>;
    tone?: FinanceSummaryTone;
    label: string;
    value: ReactNode;
}) {
    const palette = TONE[tone];

    return (
        <Card>
            <CardContent className="pt-6">
                <div className="flex items-center gap-3">
                    <div className={cn('rounded-lg p-2', palette.bg)}>
                        <Icon className={cn('h-5 w-5', palette.fg)} />
                    </div>
                    <div>
                        <p className="text-sm text-muted-foreground">{label}</p>
                        <p className="text-xl font-bold text-foreground">
                            {value}
                        </p>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

export default FinanceSummaryCard;
