import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { useId } from 'react';
import type { Answers, FormQuestion } from './form-types';

export function FormQuestions({
    fields,
    answers,
    onChange,
    errors = {},
}: {
    fields: FormQuestion[];
    answers: Answers;
    onChange: (answers: Answers) => void;
    errors?: Record<string, string>;
}) {
    const prefix = useId();
    return (
        <div className="space-y-5">
            {fields.map((field) => {
                const value = answers[field.id];
                const change = (value: Answers[string]) =>
                    onChange({ ...answers, [field.id]: value });
                const title = `${field.label}${field.required === 'intake' ? ' (required at intake)' : field.required === 'handover' ? ' (needed before handover)' : ' (optional)'}`;
                const helpId = `${prefix}-${field.id}-help`;
                const errorId = `${prefix}-${field.id}-error`;
                const error = errors[`answers.${field.id}`];
                const describedBy =
                    [field.help ? helpId : '', error ? errorId : '']
                        .filter(Boolean)
                        .join(' ') || undefined;
                return (
                    <div
                        key={field.id}
                        className="space-y-2"
                        role="group"
                        aria-describedby={describedBy}
                    >
                        <Field label={title} error={error} errorId={errorId}>
                            {field.type === 'text' ? (
                                <Textarea
                                    aria-describedby={describedBy}
                                    aria-invalid={!!error}
                                    value={String(value ?? '')}
                                    maxLength={2000}
                                    onChange={(event) =>
                                        change(event.target.value)
                                    }
                                />
                            ) : field.type === 'date' ? (
                                <DatePicker
                                    id={`${prefix}-question-${field.id}`}
                                    label={field.label}
                                    describedBy={describedBy}
                                    invalid={!!error}
                                    value={String(value ?? '')}
                                    onChange={change}
                                    allowClear
                                />
                            ) : field.type === 'number' ? (
                                <Input
                                    aria-describedby={describedBy}
                                    aria-invalid={!!error}
                                    type="number"
                                    step="any"
                                    min={-1000000000}
                                    max={1000000000}
                                    value={String(value ?? '')}
                                    onChange={(event) =>
                                        change(event.target.value)
                                    }
                                />
                            ) : (
                                <SelectInput
                                    ariaLabel={title}
                                    value={
                                        value === null ||
                                        value === undefined ||
                                        value === ''
                                            ? '__unanswered'
                                            : String(value)
                                    }
                                    onChange={(next) =>
                                        change(
                                            next === '__unanswered'
                                                ? null
                                                : field.type === 'checkbox'
                                                  ? next === 'true'
                                                  : next,
                                        )
                                    }
                                    placeholder="Choose an answer"
                                    options={[
                                        {
                                            value: '__unanswered',
                                            label: 'Not answered yet',
                                        },
                                        ...(field.type === 'checkbox'
                                            ? [
                                                  {
                                                      value: 'true',
                                                      label: 'Yes',
                                                  },
                                                  {
                                                      value: 'false',
                                                      label: 'No',
                                                  },
                                              ]
                                            : field.options.map((option) => ({
                                                  value: option,
                                                  label: option,
                                              }))),
                                    ]}
                                />
                            )}
                        </Field>
                        {field.help && (
                            <p
                                id={helpId}
                                className="text-subtle text-muted-foreground"
                            >
                                {field.help}
                            </p>
                        )}
                    </div>
                );
            })}
        </div>
    );
}
