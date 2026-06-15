/* eslint-disable no-restricted-syntax -- the MAR time-grid uses custom-styled
   <button> dose cells (84×46 tap targets) and a bordered panel that intentionally
   diverge from <Button>/<Card>; see docs/POPUP_STYLE_GUIDE.md and the MAR handoff. */
import { cn } from '@/lib/utils';
import type { DoseStatus, ScheduleRow } from '@/pages/meds/today/types';
import { Pill } from 'lucide-react';
import type { MouseEvent as ReactMouseEvent } from 'react';

/** Rich per-medication metadata (from `marData.scheduled`) keyed alongside the
 *  flat `schedule` dose slots that drive each cell's status + record action. */
export type MarGridMed = {
    id: number;
    name: string;
    dosage: string;
    route: string | null;
    frequency: string;
    instructions: string | null;
    controlled_drug: boolean;
    high_risk: boolean;
    witness_required: boolean;
    is_inr: boolean;
    requires_observation: boolean;
    dose_times: string[];
};

type Props = {
    meds: MarGridMed[];
    schedule: ScheduleRow[];
    onRecord: (row: ScheduleRow) => void;
    onContext: (event: ReactMouseEvent, row: ScheduleRow) => void;
};

const STATUS_CELL: Record<DoseStatus, { label: string; className: string }> = {
    given: { label: 'Given', className: 'border-status-success/40 bg-status-success-bg text-status-success' },
    refused: { label: 'Refused', className: 'border-status-warning/40 bg-status-warning-bg text-status-warning' },
    withheld: { label: 'Withheld', className: 'border-status-warning/40 bg-status-warning-bg text-status-warning' },
    missed: { label: 'Missed', className: 'border-status-critical/40 bg-status-critical-bg text-status-critical' },
    overdue: { label: 'Overdue', className: 'border-status-critical/40 bg-status-critical-bg text-status-critical' },
    due: { label: 'Due', className: 'border-dashed border-border bg-card text-foreground' },
    upcoming: { label: 'Due', className: 'border-border/70 bg-muted/40 text-muted-foreground' },
};

function MedFlag({ label, tone }: { label: string; tone: 'cd' | 'risk' | 'inr' | 'obs' }) {
    const toneClass =
        tone === 'cd'
            ? 'bg-status-critical-bg text-status-critical'
            : tone === 'risk'
              ? 'bg-status-warning-bg text-status-warning'
              : tone === 'inr'
                ? 'bg-status-info-bg text-status-info'
                : 'bg-accent text-accent-foreground';
    return (
        <span className={cn('rounded px-1.5 py-0.5 text-[9.5px] font-bold uppercase tracking-wide', toneClass)}>
            {label}
        </span>
    );
}

function LegendItem({ label, className }: { label: string; className: string }) {
    return (
        <span className="inline-flex items-center gap-1.5 text-[11px] text-muted-foreground">
            <span className={cn('h-3 w-3 rounded border', className)} />
            {label}
        </span>
    );
}

export default function MarGrid({ meds, schedule, onRecord, onContext }: Props) {
    // Columns = the sorted union of every scheduled dose time on the chart.
    const times = Array.from(new Set(meds.flatMap((m) => m.dose_times))).sort((a, b) => a.localeCompare(b));

    // Slot lookup: `${medicationId}|${HH:MM}` → the ScheduleRow for that cell.
    const slots = new Map<string, ScheduleRow>();
    for (const row of schedule) {
        slots.set(`${row.medication_id}|${row.time}`, row);
    }

    return (
        <div className="overflow-hidden rounded-2xl border bg-card shadow-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-4">
                <div className="flex items-center gap-2.5">
                    <span className="flex h-8 w-8 items-center justify-center rounded-lg bg-primary/10 text-primary">
                        <Pill className="h-4 w-4" />
                    </span>
                    <div>
                        <div className="text-[15px] font-bold leading-tight">Scheduled medications</div>
                        <div className="text-xs text-muted-foreground">
                            {meds.length} active order{meds.length === 1 ? '' : 's'} · tap a cell to record · right-click for quick actions
                        </div>
                    </div>
                </div>
                <div className="flex flex-wrap items-center gap-3">
                    <LegendItem label="Given" className="border-status-success/40 bg-status-success-bg" />
                    <LegendItem label="Due" className="border-dashed border-border bg-card" />
                    <LegendItem label="Overdue" className="border-status-critical/40 bg-status-critical-bg" />
                    <LegendItem label="Not given" className="border-status-warning/40 bg-status-warning-bg" />
                </div>
            </div>

            {meds.length === 0 ? (
                <div className="px-5 py-12 text-center text-sm text-muted-foreground">
                    No scheduled medications for this day.
                </div>
            ) : (
                <div className="overflow-x-auto">
                    <table className="w-full border-collapse">
                        <thead>
                            <tr className="border-b">
                                <th className="sticky left-0 z-10 bg-card px-5 py-2.5 text-left text-[11px] font-semibold uppercase tracking-wide text-muted-foreground">
                                    Medication
                                </th>
                                {times.map((time) => (
                                    <th
                                        key={time}
                                        className="px-2 py-2.5 text-center text-[11px] font-semibold uppercase tracking-wide text-muted-foreground"
                                    >
                                        {time}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {meds.map((med) => (
                                <tr key={med.id} className="border-b last:border-b-0">
                                    <td className="sticky left-0 z-10 max-w-[260px] bg-card px-5 py-3 align-top">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <span className="text-[13.5px] font-bold leading-tight">{med.name}</span>
                                            {med.controlled_drug && <MedFlag label="CD" tone="cd" />}
                                            {med.high_risk && <MedFlag label="High-risk" tone="risk" />}
                                            {med.is_inr && <MedFlag label="INR" tone="inr" />}
                                            {med.requires_observation && <MedFlag label="Obs" tone="obs" />}
                                        </div>
                                        <div className="mt-0.5 text-xs text-muted-foreground">
                                            {[med.dosage, med.route, med.frequency].filter(Boolean).join(' · ')}
                                        </div>
                                        {med.instructions && (
                                            <div className="mt-0.5 text-[11.5px] italic text-muted-foreground">
                                                {med.instructions}
                                            </div>
                                        )}
                                    </td>
                                    {times.map((time) => {
                                        const row = slots.get(`${med.id}|${time}`);
                                        if (!row) {
                                            return (
                                                <td key={time} className="px-2 py-3 text-center text-muted-foreground/40">
                                                    ·
                                                </td>
                                            );
                                        }
                                        const cell = STATUS_CELL[row.status];
                                        const recordedTime = row.recorded?.time ?? row.time;
                                        return (
                                            <td key={time} className="px-2 py-2 text-center">
                                                <button
                                                    type="button"
                                                    data-cell={`${med.id}|${time}`}
                                                    onClick={() => onRecord(row)}
                                                    onContextMenu={(event) => onContext(event, row)}
                                                    title={`${med.name} — ${cell.label} ${recordedTime}`}
                                                    className={cn(
                                                        'mx-auto flex h-[46px] w-[84px] flex-col items-center justify-center rounded-lg border text-[11px] font-semibold transition hover:ring-2 hover:ring-primary/30',
                                                        cell.className,
                                                    )}
                                                >
                                                    <span>{cell.label}</span>
                                                    <span className="text-[10px] font-normal opacity-80">{recordedTime}</span>
                                                </button>
                                            </td>
                                        );
                                    })}
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
        </div>
    );
}
