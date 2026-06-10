/* Read-only detail of a recorded dose — opened from the schedule row context
 * menu. Amendments stay on the MAR chart (corrections flow); this is just the
 * fast "what happened" view. */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Link } from '@inertiajs/react';
import { FileText } from 'lucide-react';

import { StatusPill } from '@/components/meds/board-bits';
import { SummaryRow } from '@/components/meds/wizard-shell';
import type { ScheduleRow } from '../types';

export function RecordedDetailDialog({
    row,
    canViewMar,
    onClose,
}: {
    row: ScheduleRow;
    canViewMar: boolean;
    onClose: () => void;
}) {
    const recorded = row.recorded;

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        MAR entry
                        <StatusPill status={row.status} />
                    </DialogTitle>
                    <DialogDescription>
                        {row.client_name} · {row.medication_name}
                        {row.dose ? ` ${row.dose}` : ''}
                    </DialogDescription>
                </DialogHeader>

                <div className="rounded-lg border border-border p-4">
                    <SummaryRow
                        label="Scheduled"
                        value={`${row.time} · ${row.round_label}`}
                    />
                    <SummaryRow
                        label="Recorded at"
                        value={recorded?.time ?? '—'}
                    />
                    <SummaryRow label="Recorded by" value={recorded?.by ?? '—'} />
                    {recorded?.witness ? (
                        <SummaryRow label="Witness" value={recorded.witness} />
                    ) : null}
                    {recorded?.reason_label ? (
                        <SummaryRow
                            label="Reason"
                            value={recorded.reason_label}
                        />
                    ) : null}
                    {recorded?.reason ? (
                        <SummaryRow label="Detail" value={recorded.reason} />
                    ) : null}
                    {recorded?.notes ? (
                        <SummaryRow label="Notes" value={recorded.notes} />
                    ) : null}
                </div>

                <DialogFooter className="gap-2 sm:gap-0">
                    {canViewMar ? (
                        <Button type="button" variant="outline" asChild>
                            <Link href={row.mar_url}>
                                <FileText className="h-4 w-4" />
                                Open MAR chart
                            </Link>
                        </Button>
                    ) : null}
                    <Button type="button" onClick={onClose}>
                        Close
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default RecordedDetailDialog;
