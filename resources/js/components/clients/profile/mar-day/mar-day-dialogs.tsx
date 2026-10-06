import { router } from '@inertiajs/react';
import { ClipboardList, ExternalLink, Pill } from 'lucide-react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewRow } from '@/components/wizard/shell';

import { CELL_META, cellKind, cellLabel, clockLabel } from './dose-cell';
import type { DayDose, DayMedicine } from './types';

const DETAIL_WIDTH = {
    maxWidth: 'min(92vw, 480px)',
    width: 'min(92vw, 480px)',
};

function HeaderTile({ icon: Icon }: { icon: typeof Pill }) {
    return (
        <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-muted text-primary">
            <Icon className="size-5" />
        </span>
    );
}

/** A recorded dose: who, when, the second person and what they noted. */
export function DoseRecordDialog({
    dose,
    medicine,
    dayLabel,
    isToday,
    onClose,
}: {
    dose: DayDose | null;
    medicine: DayMedicine | null;
    dayLabel: string;
    isToday: boolean;
    onClose: () => void;
}) {
    const open = dose !== null && medicine !== null;
    return (
        <Dialog open={open} onOpenChange={(next) => !next && onClose()}>
            <DialogContent style={DETAIL_WIDTH}>
                {dose && medicine ? (
                    <>
                        <DialogHeader>
                            <div className="flex items-center gap-3">
                                <HeaderTile icon={ClipboardList} />
                                <div className="min-w-0">
                                    <DialogTitle className="break-words">
                                        {medicine.name}
                                    </DialogTitle>
                                    <DialogDescription>
                                        {clockLabel(dose.time)} dose ·{' '}
                                        {dayLabel} · times in NZ time
                                    </DialogDescription>
                                </div>
                            </div>
                        </DialogHeader>
                        <div>
                            <ReviewRow
                                label="Outcome"
                                value={
                                    <StatusBadge
                                        variant={
                                            CELL_META[cellKind(dose, isToday)]
                                                .tone
                                        }
                                        size="sm"
                                    >
                                        {cellLabel(
                                            dose,
                                            cellKind(dose, isToday),
                                        )}
                                    </StatusBadge>
                                }
                            />
                            <ReviewRow
                                label="Recorded at"
                                value={clockLabel(dose.recorded?.time)}
                            />
                            <ReviewRow
                                label="Recorded by"
                                value={dose.recorded?.by}
                            />
                            <ReviewRow
                                label="Second person"
                                value={dose.recorded?.witness}
                            />
                            <ReviewRow
                                label="Reason"
                                value={
                                    dose.recorded?.reason_label ??
                                    dose.recorded?.reason
                                }
                            />
                            <ReviewRow
                                label="Note"
                                value={dose.recorded?.notes}
                            />
                            <ReviewRow label="Amount" value={medicine.dose} />
                        </div>
                        <DialogFooter>
                            {dose.mar_url ? (
                                <Button
                                    variant="outline"
                                    onClick={() => router.visit(dose.mar_url!)}
                                >
                                    <ExternalLink className="size-4" />
                                    Open in the MAR chart
                                </Button>
                            ) : null}
                            <Button onClick={onClose}>Close</Button>
                        </DialogFooter>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}

/** What the day view knows about a medicine, read-only. */
export function MedicineDetailsDialog({
    medicine,
    isToday,
    onClose,
}: {
    medicine: DayMedicine | null;
    isToday: boolean;
    onClose: () => void;
}) {
    const doses = medicine
        ? Object.entries(medicine.cells)
              .sort(([a], [b]) => a.localeCompare(b))
              .flatMap(([, list]) => list)
        : [];
    const marUrl = doses.find((dose) => dose.mar_url)?.mar_url ?? null;
    return (
        <Dialog
            open={medicine !== null}
            onOpenChange={(next) => !next && onClose()}
        >
            <DialogContent style={DETAIL_WIDTH}>
                {medicine ? (
                    <>
                        <DialogHeader>
                            <div className="flex items-center gap-3">
                                <HeaderTile icon={Pill} />
                                <div className="min-w-0">
                                    <DialogTitle className="break-words">
                                        {medicine.name}
                                    </DialogTitle>
                                    <DialogDescription>
                                        Scheduled medicine · orders are changed
                                        in Orders &amp; reviews
                                    </DialogDescription>
                                </div>
                            </div>
                        </DialogHeader>
                        <div>
                            <ReviewRow label="Amount" value={medicine.dose} />
                            <ReviewRow label="Route" value={medicine.route} />
                            <ReviewRow
                                label="Dose times"
                                value={doses
                                    .map((dose) => clockLabel(dose.time))
                                    .join(' · ')}
                            />
                            <ReviewRow
                                label="Controlled"
                                value={medicine.is_controlled ? 'Yes' : 'No'}
                            />
                            <ReviewRow
                                label="Second person"
                                value={
                                    medicine.requires_witness
                                        ? 'Needed when given'
                                        : 'Not needed'
                                }
                            />
                        </div>
                        <div className="space-y-1.5">
                            <div className="text-caption font-semibold text-muted-foreground">
                                This day
                            </div>
                            <ul className="flex flex-wrap gap-1.5">
                                {doses.map((dose) => {
                                    const kind = cellKind(dose, isToday);
                                    return (
                                        <li key={dose.key}>
                                            <StatusBadge
                                                variant={CELL_META[kind].tone}
                                                size="sm"
                                            >
                                                {clockLabel(dose.time)} ·{' '}
                                                {cellLabel(dose, kind)}
                                            </StatusBadge>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                        <DialogFooter>
                            {marUrl ? (
                                <Button
                                    variant="outline"
                                    onClick={() => router.visit(marUrl)}
                                >
                                    <ExternalLink className="size-4" />
                                    Open in the MAR chart
                                </Button>
                            ) : null}
                            <Button onClick={onClose}>Close</Button>
                        </DialogFooter>
                    </>
                ) : null}
            </DialogContent>
        </Dialog>
    );
}
