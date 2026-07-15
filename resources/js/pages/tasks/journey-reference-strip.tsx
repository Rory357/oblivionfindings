import { cn } from '@/lib/utils';
import type { IncidentJourney } from './types';

const LABELS: Array<[keyof IncidentJourney['references'], string]> = [
    ['control_room', 'Control Room'],
    ['incident', 'Incident'],
    ['health_safety', 'H&S'],
];

export function JourneyReferenceStrip({
    journey,
    compact = false,
}: {
    journey?: IncidentJourney | null;
    compact?: boolean;
}) {
    const references = LABELS.flatMap(([key, label]) => {
        const value = journey?.references[key];
        return value ? [{ key, label, value }] : [];
    });

    if (references.length === 0) return null;

    return (
        <div
            aria-label="Journey references"
            className={cn(
                'flex flex-wrap items-center gap-1.5',
                compact && 'mt-1 gap-1',
            )}
        >
            {references.map(({ key, label, value }) => (
                <span
                    key={key}
                    className={cn(
                        'inline-flex items-center gap-1 rounded-md border border-border bg-muted/60 px-1.5 py-0.5 text-[11px] text-muted-foreground',
                        compact && 'border-0 bg-transparent p-0 text-[10px]',
                    )}
                >
                    <span className="font-medium">{label}</span>
                    <span className="font-mono font-semibold text-foreground">
                        {value}
                    </span>
                </span>
            ))}
        </div>
    );
}
