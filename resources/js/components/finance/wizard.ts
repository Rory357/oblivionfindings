import { useCallback, useMemo, useState } from 'react';

// Finance wizards are built on the SAME shared Add-Client modal kit as HR — the
// reference is resources/js/components/clients/add-client-dialog.tsx. Re-export
// the kit from one Finance entry point so every Finance stepper-modal stays
// visually identical across modules. (This re-exports the shared
// components/wizard kit directly — it is NOT a fork of HR's wrapper.)
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
 * Step state machine for Finance wizards built on WizardShell — keeps Back /
 * Continue / Submit and rail navigation consistent across every Finance modal
 * workflow. (Generic step counter mirroring HR's useWizard; kept finance-local
 * to avoid coupling two concurrently-evolving loops.)
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
                stepCount > 0 ? Math.round(((index + 1) / stepCount) * 100) : 0,
        }),
        [index, goTo, next, back, reset, stepCount],
    );
}
