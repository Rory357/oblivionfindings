import { useCallback, useRef, useState, type ReactNode } from 'react';

import { PrnWizard } from '@/pages/meds/today/components/prn-wizard';
import { RecordDoseWizard } from '@/pages/meds/today/components/record-dose-wizard';
import type {
    ClientInfo,
    CompetencyNotice,
    NotGivenReasonOption,
    PrnMedication,
    ScheduleRow,
    WitnessOption,
} from '@/pages/meds/today/types';

/**
 * P02's ONE way into recording a dose. Every P02 surface — the profile's MAR
 * day view, the record's chart and header, the hub — records through this
 * hook and nothing else.
 *
 * Today it opens the recorders that work now: Meds today's RecordDoseWizard
 * for an exact scheduled dose (with its scheduled time) and PrnWizard for an
 * as-needed dose. When P01's Record-a-dose dialog lands, Lane C's C5 replaces
 * only this file's body (same API), and every caller follows.
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
        window.setTimeout(() => back?.focus(), 0);
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
                <RecordDoseWizard
                    row={target.row}
                    client={context.client}
                    date={context.date}
                    witnesses={context.witnesses}
                    notGivenReasons={context.notGivenReasons}
                    signedAs={context.signedAs}
                    initialOutcome={target.outcome}
                    onClose={close}
                />
            ) : (
                <PrnWizard
                    medications={context.prnMedications}
                    clients={new Map([[context.client.id, context.client]])}
                    date={context.date}
                    witnesses={context.witnesses}
                    signedAs={context.signedAs}
                    initialMedId={target.medicationId}
                    onClose={close}
                />
            );
    }

    return { recordScheduled, recordAsNeeded, element };
}
