/* eslint-disable no-restricted-syntax -- the count tiles are custom bordered
   stat cells inside a Dialog, not standalone Cards. All colours are tokens. */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { cn } from '@/lib/utils';
import { ArrowRight, Pill, Printer } from 'lucide-react';
import RoundAuditTimeline, {
    auditMetaFromRound,
    cellsToAuditEntries,
} from './round-audit-timeline';
import { RoundStatusBadge } from './round-bits';
import { roundCounts, type RoundSummary } from './types';

type Props = {
    round: RoundSummary;
    canExport: boolean;
    onClose: () => void;
    onOpenGuided?: (id: number) => void;
    onPrint: () => void;
};

function CountTile({
    label,
    value,
    tone,
}: {
    label: string;
    value: number;
    tone: string;
}) {
    return (
        <div className="rounded-xl border bg-background p-2.5 text-center">
            <div className={cn('text-[17px] font-bold', tone)}>{value}</div>
            <div className="text-[10.5px] text-muted-foreground">{label}</div>
        </div>
    );
}

export default function RoundAuditDialog({
    round,
    canExport,
    onClose,
    onOpenGuided,
    onPrint,
}: Props) {
    const counts = roundCounts(round.cells);

    return (
        <Dialog open onOpenChange={(o) => !o && onClose()}>
            <DialogContent
                className="frontline-dialog flex max-h-[88vh] flex-col gap-0 overflow-hidden p-0"
                style={{
                    width: 'min(92vw, 720px)',
                    maxWidth: 'min(92vw, 720px)',
                }}
            >
                <DialogHeader className="shrink-0 space-y-0 border-b p-5 pr-12">
                    <DialogDescription className="sr-only">
                        Medication round outcomes and its recorded audit trail.
                    </DialogDescription>
                    <div className="flex items-start justify-between gap-3">
                        <div className="flex items-center gap-3">
                            <span className="grid h-9 w-9 place-items-center rounded-xl bg-accent text-primary">
                                <Pill className="h-[18px] w-[18px]" />
                            </span>
                            <div>
                                <DialogTitle className="text-base font-bold">
                                    {round.name}
                                </DialogTitle>
                                <p className="text-xs text-muted-foreground">
                                    Audit &amp; timeline ·{' '}
                                    {round.scheduled_time} · ±
                                    {round.window_minutes} min
                                </p>
                            </div>
                        </div>
                        <RoundStatusBadge
                            status={round.status}
                            className="mt-1"
                        />
                    </div>
                </DialogHeader>

                <div className="grid shrink-0 grid-cols-2 gap-2 px-5 pt-4 sm:grid-cols-4">
                    <CountTile
                        label="Given"
                        value={counts.given}
                        tone="text-status-success"
                    />
                    <CountTile
                        label="Refused"
                        value={counts.refused}
                        tone="text-status-warning"
                    />
                    <CountTile
                        label="Held"
                        value={counts.held}
                        tone="text-status-warning"
                    />
                    <CountTile
                        label="Due/Missed"
                        value={counts.due + counts.missed}
                        tone="text-status-critical"
                    />
                </div>

                <div className="min-h-0 overflow-y-auto px-5 py-5">
                    <div className="mb-3 text-[11.5px] font-bold tracking-wide text-muted-foreground uppercase">
                        Audit trail
                    </div>
                    <RoundAuditTimeline
                        meta={auditMetaFromRound(round)}
                        entries={cellsToAuditEntries(round.cells)}
                    />
                </div>

                <DialogFooter className="shrink-0 border-t bg-muted/40 p-4">
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {canExport ? (
                        <Button variant="outline" onClick={onPrint}>
                            <Printer className="h-4 w-4" />
                            Print round sheet
                        </Button>
                    ) : null}
                    {onOpenGuided ? (
                        <Button onClick={() => onOpenGuided(round.id)}>
                            <ArrowRight className="h-4 w-4" />
                            Open guided round
                        </Button>
                    ) : null}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
