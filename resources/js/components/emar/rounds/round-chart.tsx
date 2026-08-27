/* eslint-disable no-restricted-syntax -- the chart is a custom sticky-column
   matrix table (clickable cells), not a Card/Button. All colours are tokens. */
import { ClientAvatar } from '@/components/meds/board-bits';
import { cn } from '@/lib/utils';
import type { MouseEvent } from 'react';
import { DoseDot } from './round-bits';
import {
    roundCounts,
    type Resident,
    type RoundCell,
    type RoundSummary,
} from './types';

type Props = {
    residents: Resident[];
    rounds: RoundSummary[];
    canRecord: boolean;
    onOpen: (id: number) => void;
    onContext: (e: MouseEvent, round: RoundSummary) => void;
};

function shortName(name: string): string {
    return name.replace(/\s*round$/i, '').trim() || name;
}

export default function RoundChart({
    residents,
    rounds,
    canRecord,
    onOpen,
    onContext,
}: Props) {
    if (residents.length === 0 || rounds.length === 0) {
        return (
            <div className="px-5 py-12 text-center text-sm text-muted-foreground">
                No scheduled doses match the current filters.
            </div>
        );
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full border-collapse">
                <thead>
                    <tr className="bg-muted">
                        <th className="sticky left-0 z-[1] bg-muted px-4 py-2.5 text-left text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">
                            Resident
                        </th>
                        {rounds.map((r) => (
                            <th
                                key={r.id}
                                className="min-w-[108px] border-l px-2 py-2 text-[11px] font-semibold text-foreground"
                            >
                                <div>{shortName(r.name)}</div>
                                <div className="text-[10.5px] font-medium text-muted-foreground">
                                    {r.scheduled_time}
                                </div>
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {residents.map((res) => (
                        <tr key={res.id} className="border-t">
                            <td className="sticky left-0 z-[1] bg-card px-4 py-2">
                                <div className="flex items-center gap-2.5">
                                    <ClientAvatar
                                        name={res.name}
                                        clientId={res.id}
                                        className="h-7 w-7 text-[10px]"
                                    />
                                    <div className="min-w-0">
                                        <div className="text-[12.5px] font-semibold whitespace-nowrap">
                                            {res.name}
                                        </div>
                                        <div className="text-[10.5px] text-muted-foreground">
                                            {res.site_name ?? '—'}
                                        </div>
                                    </div>
                                </div>
                            </td>
                            {rounds.map((r) => {
                                const counts = roundCounts(r.cells);
                                const completed =
                                    r.status === 'completed' ||
                                    (counts.total > 0 &&
                                        counts.due === 0 &&
                                        counts.recorded > 0);
                                const canOpen = canRecord || completed;
                                const cells: RoundCell[] = r.cells.filter(
                                    (c) => c.resident_id === res.id,
                                );
                                if (cells.length === 0) {
                                    return (
                                        <td
                                            key={r.id}
                                            className="border-l text-center text-muted-foreground/40"
                                        >
                                            ·
                                        </td>
                                    );
                                }
                                const anyDue = cells.some(
                                    (c) => c.status === 'due',
                                );
                                return (
                                    <td
                                        key={r.id}
                                        onClick={() => canOpen && onOpen(r.id)}
                                        onContextMenu={(e) => onContext(e, r)}
                                        className={cn(
                                            'border-l p-1.5',
                                            canOpen && 'cursor-pointer',
                                        )}
                                    >
                                        <div
                                            className={cn(
                                                'flex flex-wrap items-center justify-center gap-1 rounded-lg px-1 py-1.5',
                                                anyDue && 'bg-muted',
                                            )}
                                        >
                                            {cells.map((c) => (
                                                <DoseDot
                                                    key={`${c.medication_id}-${c.scheduled_for}`}
                                                    status={c.status}
                                                    title={`${c.medication_name} — ${c.status}`}
                                                />
                                            ))}
                                        </div>
                                    </td>
                                );
                            })}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}
