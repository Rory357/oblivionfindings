import { Button } from '@/components/ui/button';
import {
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type WizardStep,
} from '@/components/wizard/shell';
import { ChevronLeft, ChevronRight } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { DiscardDialog, RequestErrors, type useReviewCommand } from './_ui';
import { firstError } from './model';

type Command = ReturnType<typeof useReviewCommand>;
export function ReviewFormShell({
    title,
    description,
    steps,
    body,
    validate,
    onSave,
    command,
    dirty,
    onClose,
    saveLabel,
    successTitle,
    successBlurb,
}: {
    title: string;
    description: string;
    steps: WizardStep[];
    body: (step: number, edit: (index: number) => void) => ReactNode;
    validate: (step: number) => Record<string, string>;
    onSave: () => void;
    command: Command;
    dirty: boolean;
    onClose: () => void;
    saveLabel: string;
    successTitle: string;
    successBlurb: ReactNode;
}) {
    const [step, setStep] = useState(0);
    const [unlocked, setUnlocked] = useState(0);
    const [discard, setDiscard] = useState(false);
    const close = () => {
        if (!command.processing) {
            if (dirty && !command.saved) setDiscard(true);
            else onClose();
        }
    };
    const next = () => {
        const errors = validate(step);
        command.setErrors(errors);
        if (Object.keys(errors).length) {
            firstError(errors);
            return;
        }
        setStep(step + 1);
        setUnlocked(Math.max(unlocked, step + 1));
    };
    const save = () => {
        for (let index = 0; index < steps.length - 1; index++) {
            const errors = validate(index);
            if (Object.keys(errors).length) {
                command.setErrors(errors);
                setStep(index);
                firstError(errors);
                return;
            }
        }
        onSave();
    };
    return (
        <>
            <WizardShell
                open
                onClose={close}
                title={title}
                description={description}
                railIcon={steps[0].icon}
                railTitle={title}
                railSub={description}
                steps={steps.map((item, index) => ({
                    ...item,
                    disabled: command.processing || index > unlocked,
                }))}
                stepIndex={step}
                onStepClick={(index) =>
                    index <= unlocked && !command.processing && setStep(index)
                }
                pct={Math.round((step / Math.max(1, steps.length - 1)) * 100)}
                footerStart={
                    step > 0 ? (
                        <Button
                            type="button"
                            variant="ghost"
                            disabled={command.processing}
                            onClick={() => setStep(step - 1)}
                        >
                            <ChevronLeft className="size-4" /> Back
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            variant="outline"
                            disabled={command.processing}
                            onClick={close}
                        >
                            Cancel
                        </Button>
                    )
                }
                footerEnd={
                    step < steps.length - 1 ? (
                        <Button
                            type="button"
                            disabled={command.processing}
                            onClick={next}
                        >
                            Continue <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button
                            type="button"
                            disabled={command.processing}
                            onClick={save}
                        >
                            {command.processing ? 'Saving…' : saveLabel}
                        </Button>
                    )
                }
                success={
                    command.saved ? (
                        <WizardSuccessPane
                            title={successTitle}
                            blurb={successBlurb}
                            actions={
                                <Button
                                    type="button"
                                    autoFocus
                                    onClick={onClose}
                                >
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane>
                    <fieldset
                        disabled={command.processing}
                        className="min-w-0 space-y-4"
                    >
                        {body(step, setStep)}
                        <RequestErrors errors={command.errors} />
                    </fieldset>
                </WizardStepPane>
            </WizardShell>
            <DiscardDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onDiscard={() => {
                    setDiscard(false);
                    onClose();
                }}
            />
        </>
    );
}
