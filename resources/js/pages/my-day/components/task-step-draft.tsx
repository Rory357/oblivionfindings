import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Plus, X } from 'lucide-react';

export type DraftStep = { id: string; label: string };

export function TaskStepDraft({
    steps,
    onChange,
}: {
    steps: DraftStep[];
    onChange: (steps: DraftStep[]) => void;
}) {
    return (
        <section className="space-y-3" aria-label="Task steps">
            {steps.length > 0 && (
                <>
                    <div>
                        <h3 className="text-sm font-semibold">
                            Steps to complete
                        </h3>
                        <p className="text-sm text-muted-foreground">
                            Keep each step short. They share this task’s person,
                            time and owner.
                        </p>
                    </div>
                    <ol className="space-y-2">
                        {steps.map((step, index) => (
                            <li
                                key={step.id}
                                className="flex items-center gap-2"
                            >
                                <span className="w-5 text-sm text-muted-foreground">
                                    {index + 1}.
                                </span>
                                <Input
                                    aria-label={`Step ${index + 1}`}
                                    value={step.label}
                                    maxLength={180}
                                    placeholder="For example, pack the drink bottle"
                                    onChange={(event) =>
                                        onChange(
                                            steps.map((item) =>
                                                item.id === step.id
                                                    ? {
                                                          ...item,
                                                          label: event.target
                                                              .value,
                                                      }
                                                    : item,
                                            ),
                                        )
                                    }
                                />
                                <Button
                                    type="button"
                                    size="icon"
                                    variant="ghost"
                                    aria-label={`Remove step ${index + 1}`}
                                    onClick={() =>
                                        onChange(
                                            steps.filter(
                                                (item) => item.id !== step.id,
                                            ),
                                        )
                                    }
                                >
                                    <X className="size-4" />
                                </Button>
                            </li>
                        ))}
                    </ol>
                </>
            )}
            <Button
                type="button"
                variant="outline"
                disabled={steps.length >= 20}
                onClick={() =>
                    onChange([...steps, { id: crypto.randomUUID(), label: '' }])
                }
            >
                <Plus className="size-4" />
                {steps.length ? 'Add another step' : 'Add steps (optional)'}
            </Button>
            {steps.length >= 20 && (
                <p className="text-sm text-muted-foreground">
                    20 steps added. Split larger work into another task.
                </p>
            )}
        </section>
    );
}
