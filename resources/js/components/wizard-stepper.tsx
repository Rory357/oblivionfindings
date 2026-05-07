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
    const compact = steps.length > 4;
    return (
        <ol
            className="flex w-full min-w-0 items-center gap-2"
            aria-label="Progress"
        >
            {steps.map((step, index) => {
                const isComplete = index < current;
                const isCurrent = index === current;
                // When there are many steps, only show the active label so it
                // can never overflow its column.
                const showLabel = compact ? isCurrent : true;
                return (
                    <li
                        key={step.key}
                        className={`flex min-w-0 items-center gap-2 ${showLabel ? 'flex-1' : 'shrink-0'}`}
                    >
                        <div className="flex min-w-0 items-center gap-2">
                            <span
                                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors ${
                                    isComplete
                                        ? 'bg-status-success text-white'
                                        : isCurrent
                                          ? 'bg-primary text-primary-foreground'
                                          : 'bg-muted text-muted-foreground'
                                }`}
                                aria-current={isCurrent ? 'step' : undefined}
                                aria-label={
                                    !showLabel ? step.label : undefined
                                }
                                title={!showLabel ? step.label : undefined}
                            >
                                {isComplete ? (
                                    <Check className="h-4 w-4" />
                                ) : (
                                    index + 1
                                )}
                            </span>
                            {showLabel && (
                                <span
                                    className={`hidden truncate text-xs font-medium sm:inline ${
                                        isCurrent
                                            ? 'text-foreground'
                                            : 'text-muted-foreground'
                                    }`}
                                >
                                    {step.label}
                                </span>
                            )}
                        </div>
                        {index < steps.length - 1 && (
                            <span
                                className={`h-0.5 flex-1 min-w-2 rounded-full ${
                                    isComplete ? 'bg-status-success' : 'bg-muted'
                                }`}
                            />
                        )}
                    </li>
                );
            })}
        </ol>
    );
}
