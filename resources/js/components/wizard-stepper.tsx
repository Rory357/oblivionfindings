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
        <ol className="flex w-full items-center gap-2" aria-label="Progress">
            {steps.map((step, index) => {
                const isComplete = index < current;
                const isCurrent = index === current;
                return (
                    <li key={step.key} className="flex flex-1 items-center gap-2">
                        <div className="flex items-center gap-2">
                            <span
                                className={`flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-xs font-semibold transition-colors ${
                                    isComplete
                                        ? 'bg-status-success text-white'
                                        : isCurrent
                                          ? 'bg-primary text-primary-foreground'
                                          : 'bg-muted text-muted-foreground'
                                }`}
                                aria-current={isCurrent ? 'step' : undefined}
                            >
                                {isComplete ? <Check className="h-4 w-4" /> : index + 1}
                            </span>
                            <span
                                className={`hidden text-xs font-medium sm:inline ${
                                    isCurrent ? 'text-foreground' : 'text-muted-foreground'
                                }`}
                            >
                                {step.label}
                            </span>
                        </div>
                        {index < steps.length - 1 && (
                            <span
                                className={`h-0.5 flex-1 rounded-full ${
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
