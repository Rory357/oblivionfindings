import { useCallback, useRef, useState, type ReactNode } from 'react';

import { AsNeededPicker } from '@/components/emar/record-dose/dialogs';
import { RecordDoseDialog } from '@/components/emar/record-dose/record-dose-dialog';
import type { EntryPoint } from '@/components/emar/record-dose/types';
import type {
    ClientInfo,
    CompetencyNotice,
    NotGivenReasonOption,
    PrnMedication,
    ScheduleRow,
    WitnessOption,
} from '@/pages/meds/today/types';
import { router } from '@inertiajs/react';

/**
 * P02's ONE way into recording a dose. Every P02 surface — the profile's MAR
 * day view, the record's chart and header, the hub — records through this
 * hook and nothing else.
 *
 * Requirements and saving both use P01's live, server-checked shared dialog.
 */
export interface DoseRecorderContext {
    client: ClientInfo;
    /** The NZ day (Y-m-d) recorded times are anchored to. */
    date: string;
    witnesses: WitnessOption[];
    notGivenReasons: NotGivenReasonOption[];
    signedAs: {
        name: string;
        role_label: string | null;
        competency_notice?: CompetencyNotice | null;
    };
    prnMedications: PrnMedication[];
    entry?: EntryPoint;
}

type Outcome = 'given' | 'refused' | 'withheld';

type Target =
    | { kind: 'scheduled'; row: ScheduleRow; outcome: Outcome }
    | { kind: 'as_needed'; medicationId: number | null };

export interface DoseRecorder {
    /** Record this exact scheduled dose. */
    recordScheduled: (row: ScheduleRow, outcome?: Outcome) => void;
    /** Record an as-needed dose (a medicine, or the picker). */
    recordAsNeeded: (medicationId?: number | null) => void;
    /** The open recorder, to render once. */
    element: ReactNode;
}

export function useDoseRecorder(
    context: DoseRecorderContext | null,
    /** Called when the recorder closes, so the caller can refresh its view. */
    onClosed?: () => void,
): DoseRecorder {
    const [target, setTarget] = useState<Target | null>(null);
    // Focus goes back to whatever opened the recorder.
    const opener = useRef<HTMLElement | null>(null);

    const remember = () => {
        opener.current =
            document.activeElement instanceof HTMLElement
                ? document.activeElement
                : null;
    };
    const close = useCallback(() => {
        setTarget(null);
        onClosed?.();
        const back = opener.current;
        opener.current = null;
        // After the dialog has unmounted and released focus.
        window.setTimeout(() => back?.isConnected && back.focus(), 0);
    }, [onClosed]);

    const recordScheduled = useCallback(
        (row: ScheduleRow, outcome: Outcome = 'given') => {
            remember();
            setTarget({ kind: 'scheduled', row, outcome });
        },
        [],
    );
    const recordAsNeeded = useCallback((medicationId?: number | null) => {
        remember();
        setTarget({ kind: 'as_needed', medicationId: medicationId ?? null });
    }, []);

    let element: ReactNode = null;
    if (target && context) {
        element =
            target.kind === 'scheduled' ? (
                <RecordDoseDialog
                    key={`${target.row.medication_id}:${target.row.scheduled_for}:${target.outcome}`}
                    target={{
                        kind: 'scheduled',
                        orderId: target.row.medication_id,
                        scheduledFor: target.row.scheduled_for,
                        label: {
                            person: context.client.name,
                            medicine: target.row.medication_name,
                        },
                    }}
                    entry={context.entry ?? 'mar'}
                    mode={target.outcome === 'given' ? 'record' : 'notgiven'}
                    signedAs={context.signedAs}
                    returnFocus={() => opener.current}
                    onRecorded={(result) => {
                        if (result.status !== 'queued')
                            router.reload({ preserveScroll: true });
                    }}
                    onClose={close}
                />
            ) : target.medicationId === null ? (
                <AsNeededPicker
                    choices={context.prnMedications}
                    onClose={close}
                    onPick={(medicationId) =>
                        setTarget({ kind: 'as_needed', medicationId })
                    }
                />
            ) : (
                <RecordDoseDialog
                    key={`prn:${target.medicationId}`}
                    target={{
                        kind: 'prn',
                        orderId: target.medicationId,
                        label: {
                            person: context.client.name,
                            medicine:
                                context.prnMedications.find(
                                    (med) => med.id === target.medicationId,
                                )?.name ?? 'As-needed medicine',
                        },
                    }}
                    entry={context.entry ?? 'mar'}
                    signedAs={context.signedAs}
                    returnFocus={() => opener.current}
                    onRecorded={(result) => {
                        if (result.status !== 'queued')
                            router.reload({ preserveScroll: true });
                    }}
                    onClose={close}
                />
            );
    }

    return { recordScheduled, recordAsNeeded, element };
}
