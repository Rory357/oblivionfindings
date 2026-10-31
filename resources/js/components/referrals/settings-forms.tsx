import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { Plus } from 'lucide-react';
import { useState } from 'react';
import { FormQuestions } from './form-questions';
import {
    applicableQuestions,
    type Answers,
    type ConfiguredQuestion,
} from './form-types';
import type { ReferralConfiguration } from './settings-types';
import { EditorFrame, OnOff } from './settings-ui';
import { label } from './types';

export function SettingsForms({
    value,
    onChange,
    disabled,
    readOnly = false,
    embedded = false,
}: {
    value: ReferralConfiguration;
    onChange: (next: ReferralConfiguration) => void;
    disabled: boolean;
    readOnly?: boolean;
    /** Inside a settings dialog: plain sections instead of a card. */
    embedded?: boolean;
}) {
    const fields = value.forms?.fields ?? [];
    const [previewService, setPreviewService] = useState(
        value.services[0]?.id ?? 'unsure',
    );
    const [previewPurpose, setPreviewPurpose] = useState('support');
    const [previewAnswers, setPreviewAnswers] = useState<Answers>({});
    const previewFields = applicableQuestions(
        fields.filter((field) => field.enabled && field.public),
        previewService,
        previewPurpose,
        previewPurpose === 'business',
    );
    function edit(index: number, patch: Partial<ConfiguredQuestion>) {
        onChange({
            ...value,
            forms: {
                fields: fields.map((field, position) =>
                    position === index ? { ...field, ...patch } : field,
                ),
            },
        });
    }
    return (
        <EditorFrame
            embedded={embedded}
            header={
                <>
                    <h2 className="text-section-title">
                        Additional intake questions
                    </h2>
                    <p className="text-subtle">
                        Ask only what is needed. Process enquiries collect no
                        additional personal answers. Answers stay in the
                        selected referral audience; they never approve funding,
                        establish consent or overwrite the person’s profile.
                    </p>
                </>
            }
            contentClassName="space-y-4"
        >
            <fieldset disabled={disabled || readOnly} className="space-y-4">
                {!fields.length && (
                    <p className="text-subtle">
                        No additional questions configured. The standard intake
                        form remains available.
                    </p>
                )}
                {fields.map((field, index) => (
                    <details key={index} className="rounded-lg border p-4">
                        <summary className="text-subtle cursor-pointer rounded py-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                            {field.label || 'New question'} ·{' '}
                            {field.enabled ? 'Enabled' : 'Archived'} ·{' '}
                            {label(field.audience)}
                        </summary>
                        <div className="mt-4 space-y-4">
                            {readOnly ? (
                                <dl className="space-y-3">
                                    {Object.entries({
                                        Reference: field.id,
                                        Type: label(field.type),
                                        Help: field.help || 'None',
                                        Required: label(field.required),
                                        Destination: label(field.destination),
                                        Website: field.public
                                            ? 'Shown'
                                            : 'Staff only',
                                        Services:
                                            field.service_ids.join(', ') ||
                                            'All services',
                                        Purposes: field.purposes
                                            .map(label)
                                            .join(', '),
                                        Options:
                                            field.options.join(', ') ||
                                            'Not applicable',
                                    }).map(([key, value]) => (
                                        <div key={key}>
                                            <dt className="text-caption text-muted-foreground">
                                                {key}
                                            </dt>
                                            <dd className="text-subtle break-words">
                                                {value}
                                            </dd>
                                        </div>
                                    ))}
                                </dl>
                            ) : (
                                <>
                                    <Field
                                        label="Stable reference"
                                        hint="Published references cannot be removed or repurposed. Archive and add a new question when its meaning changes."
                                    >
                                        <Input
                                            value={field.id}
                                            onChange={(event) =>
                                                edit(index, {
                                                    id: event.target.value,
                                                })
                                            }
                                            maxLength={64}
                                        />
                                    </Field>
                                    <Field label="Question">
                                        <Input
                                            value={field.label}
                                            onChange={(event) =>
                                                edit(index, {
                                                    label: event.target.value,
                                                })
                                            }
                                            maxLength={160}
                                        />
                                    </Field>
                                    <Field label="Help text">
                                        <Textarea
                                            value={field.help ?? ''}
                                            onChange={(event) =>
                                                edit(index, {
                                                    help: event.target.value,
                                                })
                                            }
                                            maxLength={500}
                                        />
                                    </Field>
                                    <Field label="Answer type">
                                        <SelectInput
                                            value={field.type}
                                            placeholder="Choose type"
                                            onChange={(type) =>
                                                edit(index, {
                                                    type: type as ConfiguredQuestion['type'],
                                                    options:
                                                        type === 'choice'
                                                            ? ['First option']
                                                            : [],
                                                })
                                            }
                                            options={[
                                                'text',
                                                'date',
                                                'number',
                                                'choice',
                                                'checkbox',
                                            ].map((value) => ({
                                                value,
                                                label:
                                                    value === 'checkbox'
                                                        ? 'Yes / no (explicit answer)'
                                                        : label(value),
                                            }))}
                                        />
                                    </Field>
                                    {field.type === 'choice' && (
                                        <Field
                                            label="Choices"
                                            hint="One option per line. Keep historical option meanings stable."
                                        >
                                            <Textarea
                                                value={field.options.join('\n')}
                                                onChange={(event) =>
                                                    edit(index, {
                                                        options:
                                                            event.target.value.split(
                                                                '\n',
                                                            ),
                                                    })
                                                }
                                            />
                                        </Field>
                                    )}
                                    <Field label="When an answer is required">
                                        <SelectInput
                                            value={field.required}
                                            placeholder="Choose requirement"
                                            onChange={(required) =>
                                                edit(index, {
                                                    required:
                                                        required as ConfiguredQuestion['required'],
                                                    ...(required === 'intake'
                                                        ? { public: true }
                                                        : {}),
                                                })
                                            }
                                            options={[
                                                {
                                                    value: 'optional',
                                                    label: 'Optional',
                                                },
                                                {
                                                    value: 'intake',
                                                    label: 'At intake',
                                                },
                                                {
                                                    value: 'handover',
                                                    label: 'Before handover',
                                                },
                                            ]}
                                        />
                                    </Field>
                                    <Field label="Information audience and destination">
                                        <SelectInput
                                            value={field.audience}
                                            placeholder="Choose audience"
                                            onChange={(audience) =>
                                                edit(index, {
                                                    audience:
                                                        audience as ConfiguredQuestion['audience'],
                                                    destination: `referral_${audience}`,
                                                })
                                            }
                                            options={[
                                                'intake',
                                                'assessment',
                                                'funding',
                                            ].map((value) => ({
                                                value,
                                                label: `${label(value)} · referral ${value}`,
                                            }))}
                                        />
                                    </Field>
                                    <div className="space-y-2">
                                        {(['enabled', 'public'] as const).map(
                                            (key) => (
                                                <div
                                                    key={key}
                                                    className="flex items-center justify-between gap-4"
                                                >
                                                    <span className="text-subtle">
                                                        {key === 'enabled'
                                                            ? 'Use this question (turn off to archive it)'
                                                            : 'Include in website and staff intake'}
                                                    </span>
                                                    <OnOff
                                                        label={
                                                            key === 'enabled'
                                                                ? 'Use this question'
                                                                : 'Include in website and staff intake'
                                                        }
                                                        checked={field[key]}
                                                        disabled={
                                                            disabled ||
                                                            readOnly ||
                                                            (key === 'public' &&
                                                                field.required ===
                                                                    'intake')
                                                        }
                                                        onChange={(checked) =>
                                                            edit(index, {
                                                                [key]: checked,
                                                            })
                                                        }
                                                    />
                                                </div>
                                            ),
                                        )}
                                    </div>
                                    <fieldset className="space-y-2">
                                        <legend className="text-subtle">
                                            Show for these services (none means
                                            all)
                                        </legend>
                                        {[
                                            ...value.services,
                                            {
                                                id: 'unsure',
                                                label: 'Not sure yet',
                                            },
                                        ].map((service) => (
                                            <label
                                                key={service.id}
                                                className="text-subtle flex items-center gap-3"
                                            >
                                                <Checkbox
                                                    checked={field.service_ids.includes(
                                                        service.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        edit(index, {
                                                            service_ids: checked
                                                                ? [
                                                                      ...field.service_ids,
                                                                      service.id,
                                                                  ]
                                                                : field.service_ids.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          service.id,
                                                                  ),
                                                        })
                                                    }
                                                />
                                                {service.label}
                                            </label>
                                        ))}
                                    </fieldset>
                                    <fieldset className="space-y-2">
                                        <legend className="text-subtle">
                                            Show for these enquiry purposes
                                        </legend>
                                        {[
                                            'support',
                                            'referral',
                                            'funding',
                                            'callback',
                                        ].map((purpose) => (
                                            <label
                                                key={purpose}
                                                className="text-subtle flex items-center gap-3"
                                            >
                                                <Checkbox
                                                    checked={field.purposes.includes(
                                                        purpose,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        edit(index, {
                                                            purposes: checked
                                                                ? [
                                                                      ...field.purposes,
                                                                      purpose,
                                                                  ]
                                                                : field.purposes.filter(
                                                                      (id) =>
                                                                          id !==
                                                                          purpose,
                                                                  ),
                                                        })
                                                    }
                                                />
                                                {label(purpose)}
                                            </label>
                                        ))}
                                    </fieldset>
                                </>
                            )}
                        </div>
                    </details>
                ))}
                {!readOnly && (
                    <Button
                        variant="outline"
                        disabled={fields.length >= 40}
                        onClick={() =>
                            onChange({
                                ...value,
                                forms: {
                                    fields: [
                                        ...fields,
                                        {
                                            id: `question_${crypto.randomUUID().slice(0, 8)}`,
                                            label: 'New question',
                                            help: '',
                                            type: 'text',
                                            options: [],
                                            enabled: true,
                                            public: true,
                                            required: 'optional',
                                            audience: 'intake',
                                            destination: 'referral_intake',
                                            service_ids: [],
                                            purposes: [
                                                'support',
                                                'referral',
                                                'funding',
                                                'callback',
                                            ],
                                        },
                                    ],
                                },
                            })
                        }
                    >
                        <Plus className="size-4" />
                        Add question
                    </Button>
                )}
            </fieldset>
            <details>
                <summary className="text-subtle cursor-pointer rounded py-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                    Preview public questions
                </summary>
                <div className="mt-4 space-y-4">
                    <p className="text-subtle">
                        Use fictional answers only. Preview answers are not
                        saved or included in publication.
                    </p>
                    <Field label="Preview service">
                        <SelectInput
                            value={previewService}
                            onChange={setPreviewService}
                            placeholder="Choose service"
                            options={[
                                ...value.services
                                    .filter((service) => service.enabled)
                                    .map((service) => ({
                                        value: service.id,
                                        label: service.label,
                                    })),
                                { value: 'unsure', label: 'Not sure yet' },
                            ]}
                        />
                    </Field>
                    <Field label="Preview purpose">
                        <SelectInput
                            value={previewPurpose}
                            onChange={setPreviewPurpose}
                            placeholder="Choose purpose"
                            options={[
                                'support',
                                'referral',
                                'funding',
                                'callback',
                                'business',
                            ].map((value) => ({
                                value,
                                label: label(value),
                            }))}
                        />
                    </Field>
                    {previewFields.length ? (
                        <FormQuestions
                            fields={previewFields}
                            answers={previewAnswers}
                            onChange={setPreviewAnswers}
                        />
                    ) : (
                        <p className="text-subtle">
                            No additional questions apply to this enquiry.
                        </p>
                    )}
                </div>
            </details>
        </EditorFrame>
    );
}
