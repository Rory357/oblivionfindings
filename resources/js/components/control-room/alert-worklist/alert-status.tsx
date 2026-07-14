import {
    ALERT_SEVERITY_VOCAB,
    ALERT_SLA_VOCAB,
    ALERT_STATUS_VOCAB,
    type StatusTone,
    vocabularyFor,
} from '@/lib/control-room-vocab';
import { cn } from '@/lib/utils';

const TONE_CLASS: Record<StatusTone, string> = {
    success:
        'border-status-success/30 bg-status-success/10 text-status-success-foreground',
    warning:
        'border-status-warning/40 bg-status-warning/10 text-status-warning-foreground',
    critical:
        'border-status-critical/40 bg-status-critical/10 text-status-critical-foreground',
    info: 'border-primary/30 bg-primary/10 text-primary',
    neutral: 'border-border bg-muted text-muted-foreground',
};

function StatusChip({ item }: { item: ReturnType<typeof vocabularyFor> }) {
    const Icon = item.icon;
    return (
        <span
            className={cn(
                'inline-flex items-center gap-1 rounded-full border px-2 py-1 text-xs font-medium',
                TONE_CLASS[item.tone],
            )}
        >
            <Icon className="h-3.5 w-3.5" aria-hidden />
            {item.label}
        </span>
    );
}

export function AlertStatus({
    status,
    severity,
    slaStatus,
}: {
    status: string;
    severity: string;
    slaStatus?: string | null;
}) {
    return (
        <div role="status" className="flex flex-wrap items-center gap-1.5">
            <StatusChip item={vocabularyFor(ALERT_STATUS_VOCAB, status)} />
            <StatusChip item={vocabularyFor(ALERT_SEVERITY_VOCAB, severity)} />
            {slaStatus ? (
                <StatusChip item={vocabularyFor(ALERT_SLA_VOCAB, slaStatus)} />
            ) : null}
        </div>
    );
}
