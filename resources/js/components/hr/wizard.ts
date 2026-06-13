import { useCallback, useMemo, useState } from 'react';

// HR wizards are built on the shared Add-Client modal kit. Re-export the whole
// kit from one HR entry point so every HR stepper-modal stays visually identical
// to the reference (resources/js/components/clients/add-client-dialog.tsx).
export {
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    ReviewCard,
    ReviewRow,
} from '@/components/wizard/shell';
export type { WizardStep } from '@/components/wizard/shell';
export {
    Field,
    FieldErr,
    Segmented,
    ChipMulti,
    TilePicker,
    SelectInput,
    StepHead,
    SubHead,
    InfoCard,
    Ring,
} from '@/components/wizard/primitives';
export type { IconType } from '@/components/wizard/primitives';

/**
 * Step state machine for HR wizards built on WizardShell — keeps Back / Continue
 * / Submit and rail navigation consistent across every HR modal workflow.
 */
export function useWizard(stepCount: number) {
    const [index, setIndex] = useState(0);
    const clamp = useCallback(
        (i: number) => Math.max(0, Math.min(stepCount - 1, i)),
        [stepCount],
    );
    const goTo = useCallback((i: number) => setIndex(clamp(i)), [clamp]);
    const next = useCallback(() => setIndex((i) => clamp(i + 1)), [clamp]);
    const back = useCallback(() => setIndex((i) => clamp(i - 1)), [clamp]);
    const reset = useCallback(() => setIndex(0), []);

    return useMemo(
        () => ({
            index,
            goTo,
            next,
            back,
            reset,
            isFirst: index === 0,
            isLast: index === stepCount - 1,
            progress:
                stepCount > 0
                    ? Math.round(((index + 1) / stepCount) * 100)
                    : 0,
        }),
        [index, goTo, next, back, reset, stepCount],
    );
}
