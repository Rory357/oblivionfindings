import { Button } from '@/components/ui/button';
import { ClipboardCheck, History } from 'lucide-react';
import {
    ticketResolutionLabel,
    type TicketResolution,
} from './ticket-resolution';

/** Private work metadata; the page supplies only a currently authorized record. */
export function TicketResolutionSummary({
    resolution,
    onHistory,
}: {
    resolution: TicketResolution | null | undefined;
    onHistory: () => void;
}) {
    return (
        <section
            aria-label="Recorded resolution"
            className="mb-5 space-y-4 rounded-2xl border border-border bg-card p-5"
        >
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h2 className="flex items-center gap-2 text-sm font-semibold">
                    <ClipboardCheck
                        className="size-4 text-primary"
                        aria-hidden
                    />
                    Recorded resolution
                </h2>
                <Button variant="outline" size="sm" onClick={onHistory}>
                    <History className="size-4" aria-hidden />
                    View history
                </Button>
            </div>
            <dl className="space-y-4 text-sm">
                {[
                    [
                        'Outcome',
                        resolution?.code
                            ? ticketResolutionLabel(resolution.code)
                            : null,
                    ],
                    ['Explanation', resolution?.summary],
                    ['How it was checked', resolution?.verification],
                ].map(([label, value]) => (
                    <div key={label}>
                        <dt className="text-xs font-medium text-muted-foreground">
                            {label}
                        </dt>
                        <dd className="mt-1 break-words whitespace-pre-wrap">
                            {value?.trim() ? (
                                value
                            ) : (
                                <span className="text-muted-foreground">
                                    Not recorded
                                </span>
                            )}
                        </dd>
                    </div>
                ))}
            </dl>
        </section>
    );
}
