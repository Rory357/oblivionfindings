import { Check } from 'lucide-react';

export type WizardStep = {
    key: string;
    label: string;
};

type Props = {
    steps: WizardStep[];
    current: number;
};

export default function WizardStepper({ steps, current }: Props) {
    return (
        <ol
            className="grid w-full min-w-0 grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-8"
            aria-label="Progress"
        >
            {steps.map((step, index) => {
                const isComplete = index < current;
                const isCurrent = index === current;
                return (
                    <li key={step.key} className="min-w-0">
                        <div
                            className={`flex h-full min-w-0 items-center gap-2 rounded-md border px-2 py-2 ${
                                isCurrent
                                    ? 'border-primary bg-primary/10'
                                    : isComplete
                                      ? 'border-status-success/30 bg-status-success-bg/40'
                                      : 'border-border bg-background'
                            }`}
                        >
                            <span
                                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors ${
                                    isComplete
                                        ? 'bg-status-success text-primary-foreground'
                                        : isCurrent
                                          ? 'bg-primary text-primary-foreground'
                                          : 'bg-muted text-muted-foreground'
                                }`}
                                aria-current={isCurrent ? 'step' : undefined}
                            >
                                {isComplete ? (
                                    <Check className="h-4 w-4" />
                                ) : (
                                    index + 1
                                )}
                            </span>
                            <span
                                className={`min-w-0 text-xs leading-tight font-medium break-words ${
                                    isCurrent
                                        ? 'text-foreground'
                                        : 'text-muted-foreground'
                                }`}
                            >
                                {step.label}
                            </span>
                        </div>
                    </li>
                );
            })}
        </ol>
    );
}
