import { Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

import { clockLabel } from './dose-cell';
import type { DayPrnMedication } from './types';

function limitLine(med: DayPrnMedication): string {
    if (!med.is_today) {
        if (med.given_on_day === 0) return 'None given that day';
        const times =
            med.given_on_day === 1 ? 'once' : `${med.given_on_day} times`;
        return `Given ${times} that day · last ${clockLabel(med.last_given_on_day)}`;
    }
    const count =
        med.max_per_day !== null
            ? `${med.given_last_24h} of ${med.max_per_day} in the last 24 hours`
            : `${med.given_last_24h} in the last 24 hours`;
    const last = med.last_given_at
        ? ` · last ${new Date(med.last_given_at).toLocaleTimeString('en-NZ', {
              hour: 'numeric',
              minute: '2-digit',
              timeZone: 'Pacific/Auckland',
          })}`
        : '';
    return `${count}${last}`;
}

/** The as-needed medicines under the day grid (P02-1b). */
export function PrnStrip({
    rows,
    hidden,
    recordBlocked,
    canRecordControlled,
    onRecord,
}: {
    rows: DayPrnMedication[];
    hidden: number;
    /** Why this reader can't record here now, or null when they can. */
    recordBlocked: string | null;
    canRecordControlled: boolean;
    onRecord: (medicationId: number) => void;
}) {
    if (rows.length === 0 && hidden === 0) return null;

    return (
        <TooltipProvider delayDuration={150}>
            <section aria-label="As-needed medicines" className="space-y-2">
                <div className="flex flex-wrap items-baseline justify-between gap-2">
                    <h3 className="text-section-title">As needed</h3>
                    {hidden > 0 ? (
                        <span className="text-caption text-muted-foreground">
                            {hidden} controlled not shown — needs
                            controlled-medicine access
                        </span>
                    ) : null}
                </div>
                <ul className="grid gap-2 md:grid-cols-2 2xl:grid-cols-3">
                    {rows.map((med) => {
                        const blocked =
                            recordBlocked ??
                            (med.is_controlled && !canRecordControlled
                                ? 'Controlled doses need controlled-medicine recording access.'
                                : null);
                        const record = (
                            <Button
                                size="sm"
                                variant="outline"
                                className="frontline-tap shrink-0"
                                aria-disabled={blocked ? true : undefined}
                                onClick={() => {
                                    if (!blocked) onRecord(med.id);
                                }}
                            >
                                <Plus className="size-4" />
                                Record as-needed dose
                            </Button>
                        );
                        return (
                            <li
                                key={med.id}
                                className="flex items-center gap-3 rounded-lg border border-border bg-card px-3 py-2.5"
                            >
                                <div className="min-w-0 flex-1">
                                    <div className="flex flex-wrap items-center gap-1.5">
                                        <span
                                            className="font-medium break-words"
                                            title={med.name}
                                        >
                                            {med.name}
                                        </span>
                                        {med.dose ? (
                                            <span className="text-caption text-muted-foreground">
                                                {med.dose}
                                            </span>
                                        ) : null}
                                        {med.is_today && med.over_limit ? (
                                            <StatusBadge
                                                variant="critical"
                                                size="sm"
                                            >
                                                Limit reached
                                            </StatusBadge>
                                        ) : med.is_today &&
                                          med.interval_blocked &&
                                          med.next_allowed_label ? (
                                            <StatusBadge
                                                variant="warning"
                                                size="sm"
                                            >
                                                Next from{' '}
                                                {med.next_allowed_label}
                                            </StatusBadge>
                                        ) : null}
                                    </div>
                                    <div className="text-caption text-muted-foreground">
                                        {limitLine(med)}
                                    </div>
                                </div>
                                {med.is_today ? (
                                    blocked ? (
                                        <Tooltip>
                                            <TooltipTrigger asChild>
                                                {record}
                                            </TooltipTrigger>
                                            <TooltipContent>
                                                {blocked}
                                            </TooltipContent>
                                        </Tooltip>
                                    ) : (
                                        record
                                    )
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
            </section>
        </TooltipProvider>
    );
}
