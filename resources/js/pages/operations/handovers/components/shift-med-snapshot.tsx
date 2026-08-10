/* Shared "Medications this shift" snapshot type + read-only summary panel, used
 * by the eMAR handover wizard (pre-fill source) and the detail dialog (read-only
 * lens). Fed by GET /emar/handovers/shift-medications?shift_id=… — see
 * app/Services/Emar/ShiftMedicationSnapshotService. Operations never wires the
 * snapshot URL, so this is eMAR-only in practice. Semantic tokens throughout. */
import { AlertTriangle, Loader2, Pill } from 'lucide-react';

import { cn } from '@/lib/utils';

export type ShiftMedSnapshot = {
    window: { start: string; end: string };
    counts: {
        due: number;
        given: number;
        missed: number;
        refused: number;
        cd_due: number;
        prn_given: number;
        reviews_outstanding: number;
        omissions: number;
    };
    due: { name: string; time: string; state: string; controlled: boolean }[];
    alerts: { kind: string; tone: string; message: string }[];
    generated_at: string;
};

function ShiftMedStat({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone?: 'critical' | 'warning';
}) {
    const toneClass =
        tone === 'critical' && value > 0
            ? 'text-status-critical'
            : tone === 'warning' && value > 0
              ? 'text-status-warning'
              : 'text-foreground';
    return (
        // eslint-disable-next-line no-restricted-syntax -- compact stat tile (number + label), not a Card surface
        <div className="rounded-lg border border-border bg-background px-2.5 py-2 text-center">
            <div
                className={cn('text-[17px] font-bold tabular-nums', toneClass)}
            >
                {value}
            </div>
            <div className="text-[10.5px] font-medium text-muted-foreground">
                {label}
            </div>
        </div>
    );
}

/** Read-only "Medications this shift" picture — the live MAR state for the
 *  outgoing shift's window. `note` adds a contextual line (e.g. the wizard's
 *  pre-fill hint); `noShiftHint` overrides the no-shift message. */
export function ShiftMedSummary({
    snapshot,
    loading,
    hasShift,
    note,
    noShiftHint = 'No outgoing shift linked — nothing to chart.',
}: {
    snapshot: ShiftMedSnapshot | null;
    loading: boolean;
    hasShift: boolean;
    note?: string;
    noShiftHint?: string;
}) {
    return (
        <div className="rounded-xl border border-primary/20 bg-accent/40 p-3">
            <div className="mb-2 flex items-center gap-2 text-[13px] font-semibold">
                <span className="flex h-6 w-6 items-center justify-center rounded-md bg-primary/15 text-primary">
                    <Pill className="h-3.5 w-3.5" />
                </span>
                Medications this shift
                <span className="ml-auto text-[11px] font-normal text-muted-foreground">
                    auto-surfaced from the MAR
                </span>
            </div>
            {!hasShift ? (
                <div className="text-[12.5px] text-muted-foreground">
                    {noShiftHint}
                </div>
            ) : loading ? (
                <div className="flex items-center gap-2 text-[12.5px] text-muted-foreground">
                    <Loader2 className="h-3.5 w-3.5 animate-spin" />
                    Loading the shift's medication state…
                </div>
            ) : !snapshot ? (
                <div className="text-[12.5px] text-muted-foreground">
                    No medication data for this shift's window.
                </div>
            ) : (
                <div className="space-y-2.5">
                    <div className="grid grid-cols-4 gap-1.5">
                        <ShiftMedStat label="Due" value={snapshot.counts.due} />
                        <ShiftMedStat
                            label="Given"
                            value={snapshot.counts.given}
                        />
                        <ShiftMedStat
                            label="Missed"
                            value={snapshot.counts.missed}
                            tone="critical"
                        />
                        <ShiftMedStat
                            label="Refused"
                            value={snapshot.counts.refused}
                            tone="warning"
                        />
                        <ShiftMedStat
                            label="PRN given"
                            value={snapshot.counts.prn_given}
                        />
                        <ShiftMedStat
                            label="Reviews due"
                            value={snapshot.counts.reviews_outstanding}
                            tone="warning"
                        />
                        <ShiftMedStat
                            label="Omissions"
                            value={snapshot.counts.omissions}
                            tone="critical"
                        />
                        <ShiftMedStat
                            label="CD due"
                            value={snapshot.counts.cd_due}
                            tone="warning"
                        />
                    </div>
                    {note ? (
                        <div className="text-[11.5px] text-muted-foreground">
                            {note}
                        </div>
                    ) : null}
                    {snapshot.alerts.length > 0 ? (
                        <div className="space-y-1">
                            {snapshot.alerts.map((a, i) => (
                                <div
                                    key={i}
                                    className={cn(
                                        'flex items-center gap-1.5 rounded-md px-2 py-1 text-[11.5px]',
                                        a.tone === 'critical'
                                            ? 'bg-status-critical-bg text-status-critical'
                                            : 'bg-status-warning-bg text-status-warning',
                                    )}
                                >
                                    <AlertTriangle className="h-3 w-3 shrink-0" />
                                    {a.message}
                                </div>
                            ))}
                        </div>
                    ) : null}
                </div>
            )}
        </div>
    );
}
