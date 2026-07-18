import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { FilterX } from 'lucide-react';

export type EscalationQueueSummary = {
    id: number;
    name: string;
    code: string;
    tier: number;
    description: string | null;
    alert_count: number;
    breached_count: number;
    capacity: number;
    utilization_percent: number;
    pressure_label: string;
    capacity_explanation: string;
};

function pressureTone(queue: EscalationQueueSummary): string {
    if (queue.utilization_percent > 200) {
        return 'border-status-critical/35 bg-status-critical/10 text-status-critical';
    }
    if (queue.utilization_percent > 100) {
        return 'border-status-warning/35 bg-status-warning/10 text-status-warning';
    }
    return 'border-border bg-card text-foreground';
}

export function EscalationQueueFilters({
    queues,
    activeQueueId,
    totalAlerts,
    hasFilters,
    onSelect,
    onClear,
}: {
    queues: readonly EscalationQueueSummary[];
    activeQueueId: string | null;
    totalAlerts: number;
    hasFilters: boolean;
    onSelect: (queueId: string | null) => void;
    onClear: () => void;
}) {
    return (
        <section aria-labelledby="queue-pressure-heading" className="space-y-3">
            <div className="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h2 id="queue-pressure-heading" className="font-semibold">
                        Queue pressure
                    </h2>
                    <p className="text-sm text-muted-foreground">
                        Capacity is an operational display threshold, not a hard
                        limit. Every alert remains in the paginated worklist.
                    </p>
                </div>
                {hasFilters ? (
                    <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        onClick={onClear}
                    >
                        <FilterX className="h-4 w-4" aria-hidden />
                        Clear filters
                    </Button>
                ) : null}
            </div>

            <div
                data-testid="escalation-queue-filters"
                className="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:thin]"
            >
                {/* eslint-disable-next-line no-restricted-syntax -- Compact queue selector cards need native pressed-button semantics. */}
                <button
                    type="button"
                    aria-pressed={!activeQueueId}
                    onClick={() => onSelect(null)}
                    className={cn(
                        'min-h-11 min-w-max rounded-xl border px-3 py-2 text-left text-sm transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                        !activeQueueId
                            ? 'border-primary bg-primary/10 text-primary'
                            : 'bg-card hover:bg-muted',
                    )}
                >
                    <span className="font-semibold">All queues</span>
                    <span className="ml-2 tabular-nums">{totalAlerts}</span>
                </button>
                {queues.map((queue) => (
                    // eslint-disable-next-line no-restricted-syntax -- Compact queue selector cards need native pressed-button semantics.
                    <button
                        key={queue.id}
                        type="button"
                        aria-pressed={activeQueueId === String(queue.id)}
                        onClick={() => onSelect(String(queue.id))}
                        title={queue.capacity_explanation}
                        className={cn(
                            'relative min-h-11 min-w-[12rem] overflow-hidden rounded-xl border px-3 py-2 text-left transition-colors focus-visible:ring-2 focus-visible:ring-primary focus-visible:outline-none',
                            pressureTone(queue),
                            activeQueueId === String(queue.id) &&
                                'ring-2 ring-primary ring-offset-2',
                        )}
                    >
                        <span
                            className="absolute inset-y-0 left-0 bg-current opacity-[0.06]"
                            style={{
                                width: `${Math.min(queue.utilization_percent, 100)}%`,
                            }}
                            aria-hidden
                        />
                        <span className="relative flex items-center justify-between gap-3">
                            <span>
                                <span className="block text-xs font-semibold tracking-wide uppercase">
                                    Tier {queue.tier}
                                </span>
                                <span className="block text-sm font-semibold">
                                    {queue.name}
                                </span>
                            </span>
                            <span className="text-right text-xs tabular-nums">
                                <span className="block font-bold">
                                    {queue.alert_count}/{queue.capacity}
                                </span>
                                <span className="block">
                                    {queue.breached_count} breached
                                </span>
                            </span>
                        </span>
                        <span className="relative mt-1 block text-[11px] font-medium">
                            {queue.pressure_label} · {queue.utilization_percent}
                            %
                        </span>
                    </button>
                ))}
            </div>
        </section>
    );
}
