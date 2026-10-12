import { Pill, Plus } from 'lucide-react';

import { Button } from '@/components/ui/button';
import { EmptyState } from '@/components/ui/empty-state';
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
    limitReachedOnly = false,
    onRecord,
}: {
    rows: DayPrnMedication[];
    hidden: number;
    /** Why this reader can't record here now, or null when they can. */
    recordBlocked: string | null;
    canRecordControlled: boolean;
    /** P02 "Show limit reached only" (Lane C9f): today's medicines at their 24-hour limit. */
    limitReachedOnly?: boolean;
    onRecord: (medicationId: number) => void;
}) {
    if (rows.length === 0 && hidden === 0) return null;
    const shown = limitReachedOnly
        ? rows.filter((med) => med.is_today && med.over_limit)
        : rows;
    const onToday = rows.some((med) => med.is_today);

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
                {limitReachedOnly && rows.length > 0 && shown.length === 0 ? (
                    <EmptyState
                        icon={Pill}
                        variant="compact"
                        title="No as-needed medicine is at its limit"
                        description={
                            onToday
                                ? 'Turn off “Show limit reached only” to see every as-needed medicine.'
                                : 'Limits count the last 24 hours, so they show on today’s chart. Turn off “Show limit reached only” to see this day.'
                        }
                    />
                ) : null}
                <ul className="grid gap-2 md:grid-cols-2 2xl:grid-cols-3">
                    {shown.map((med) => {
                        const blocked =
                            recordBlocked ??
                            (med.is_controlled && !canRecordControlled
                                ? 'Controlled doses need controlled-medicine recording access.'
                                : null);
                        const record = (
                            <Button
                                size="sm"
                                variant="outline"
                                className="shrink-0"
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
                                key={med.key ?? med.id}
                                className="flex flex-col items-stretch gap-3 rounded-lg border border-border bg-card px-3 py-2.5 sm:flex-row sm:items-center"
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
