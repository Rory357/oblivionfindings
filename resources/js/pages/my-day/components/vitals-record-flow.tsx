import { ChevronRight, Stethoscope } from 'lucide-react';
import { useEffect, useState } from 'react';

import ObservationRecordSheet from '@/components/clinical/observation-record-sheet';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { cn } from '@/lib/utils';

import type { MyDayResident } from '../lib/types';

import { ResidentDot } from './resident-dot';

interface VitalsRecordFlowProps {
    /** Residents the worker can pick from. Pass the site's residents on a
     *  multi-resident shift, or `[singleResident]` on a 1:1 shift. */
    residents: MyDayResident[];
    /** Active shift id — passed to the observation endpoint so the record is
     *  linked back to the shift, not just the client. */
    shiftId?: number | null;
    /** Worker has `clinical.observations.record` (basic observation types). */
    canRecordObservation: boolean;
    /** Worker has `clinical.observations.recordClinical` (vitals + pain). */
    canRecordClinical: boolean;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}

/**
 * Two-step flow that lets a worker on a multi-resident shift pick a resident
 * and then record a clinical observation against them — without leaving
 * /my-day. The recording form itself is the existing
 * `ObservationRecordSheet`, so the back-end contract (and permission model)
 * stays identical to the one used on the client profile page.
 *
 * On a 1:1 shift (residents.length === 1) we skip the picker and open the
 * record sheet directly. When the user has no permission to record any
 * observation type the picker still shows so a manager doing oversight can
 * land on the client profile through the sheet's Cancel-to-link path.
 */
export function VitalsRecordFlow({
    residents,
    shiftId,
    canRecordObservation,
    canRecordClinical,
    open,
    onOpenChange,
}: VitalsRecordFlowProps) {
    const [selectedResident, setSelectedResident] =
        useState<MyDayResident | null>(null);

    // On opening the picker for a single-resident shift, jump straight to
    // the record form so the worker doesn't see a one-row "picker".
    useEffect(() => {
        if (!open) {
            setSelectedResident(null);
            return;
        }
        if (residents.length === 1 && selectedResident == null) {
            setSelectedResident(residents[0]);
        }
    }, [open, residents, selectedResident]);

    const showPicker = open && selectedResident == null;
    const showRecord = open && selectedResident != null;

    return (
        <>
            <Sheet
                open={showPicker}
                onOpenChange={(next) => {
                    if (!next) onOpenChange(false);
                }}
            >
                <SheetContent
                    className="overflow-y-auto sm:max-w-md"
                    data-test="my-day-vitals-picker"
                >
                    <SheetHeader>
                        <SheetTitle className="flex items-center gap-2">
                            <Stethoscope className="h-4 w-4" />
                            Record vitals & observations
                        </SheetTitle>
                        <SheetDescription>
                            Choose a resident to record an observation for.
                        </SheetDescription>
                    </SheetHeader>

                    <div className="mt-4">
                        {residents.length === 0 ? (
                            <p className="rounded-lg border border-dashed bg-background/70 px-3 py-4 text-sm text-muted-foreground">
                                No residents on this shift.
                            </p>
                        ) : (
                            <ul className="flex flex-col gap-1.5">
                                {residents.map((r) => (
                                    <li key={r.id}>
                                        {/* eslint-disable-next-line no-restricted-syntax -- list-item button with avatar + chevron, not a shadcn Button. */}
                                        <button
                                            type="button"
                                            onClick={() => setSelectedResident(r)}
                                            className={cn(
                                                'group flex w-full items-center gap-3 rounded-xl border border-border bg-card px-3 py-2.5 text-left transition-colors',
                                                'hover:border-primary/40 hover:bg-accent focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-primary/40',
                                            )}
                                        >
                                            <ResidentDot
                                                hue={r.hue}
                                                initials={r.initials}
                                            />
                                            <span className="min-w-0 flex-1">
                                                <span className="block truncate text-[13.5px] font-semibold">
                                                    {r.name}
                                                </span>
                                                {r.care_note_preview ? (
                                                    <span className="mt-0.5 block truncate text-[11px] text-muted-foreground">
                                                        {r.care_note_preview}
                                                    </span>
                                                ) : null}
                                            </span>
                                            <ChevronRight className="h-4 w-4 shrink-0 text-muted-foreground transition-transform group-hover:translate-x-0.5" />
                                        </button>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </SheetContent>
            </Sheet>

            {selectedResident && canRecordObservation ? (
                <ObservationRecordSheet
                    clientId={selectedResident.id}
                    shiftId={shiftId ?? undefined}
                    canRecordClinical={canRecordClinical}
                    open={showRecord}
                    onOpenChange={(next) => {
                        if (!next) {
                            // Multi-resident flow: cancelling the record form
                            // returns the worker to the picker so they can
                            // choose a different resident. 1:1 shift: cancel
                            // closes the whole flow.
                            if (residents.length > 1) {
                                setSelectedResident(null);
                            } else {
                                onOpenChange(false);
                            }
                        }
                    }}
                    onRecorded={() => onOpenChange(false)}
                />
            ) : null}
        </>
    );
}

export default VitalsRecordFlow;
