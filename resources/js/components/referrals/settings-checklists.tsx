import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { Plus } from 'lucide-react';
import { checklistGateLabel, type ChecklistTemplate } from './checklist-types';
import type { ReferralConfiguration } from './settings-types';
import { EditorFrame, OnOff } from './settings-ui';
import { label } from './types';

const reference = (prefix: string) =>
    `${prefix}_${crypto.randomUUID().slice(0, 8)}`;
export function SettingsChecklists({
    value,
    onChange,
    roles,
    disabled,
    readOnly = false,
    embedded = false,
}: {
    value: ReferralConfiguration;
    onChange: (value: ReferralConfiguration) => void;
    roles: { id: number; name: string }[];
    disabled: boolean;
    readOnly?: boolean;
    /** Inside a settings dialog: plain sections instead of a card. */
    embedded?: boolean;
}) {
    const templates = value.checklists ?? [];
    const edit = (index: number, patch: Partial<ChecklistTemplate>) =>
        onChange({
            ...value,
            checklists: templates.map((template, position) =>
                position === index ? { ...template, ...patch } : template,
            ),
        });
    return (
        <EditorFrame
            embedded={embedded}
            header={
                <>
                    <h2 className="text-section-title">
                        Preparation checklist templates
                    </h2>
                    <p className="text-subtle">
                        Reusable preparation steps with recorded responsibility.
                        Required steps also apply at later gates, so skipping a
                        working stage cannot bypass preparation. They never
                        replace consent, funding or service-start approvals.
                    </p>
                </>
            }
        >
            <fieldset disabled={disabled || readOnly} className="space-y-4">
                {!templates.length && (
                    <p className="text-subtle">
                        No preparation templates configured. Existing assessment
                        and funding checks still apply.
                    </p>
                )}
                {templates.map((template, index) => (
                    <details key={index} className="rounded-lg border p-4">
                        <summary className="text-subtle cursor-pointer rounded py-1 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                            {template.label} ·{' '}
                            {template.enabled
                                ? checklistGateLabel(template.gate)
                                : 'Archived'}
                        </summary>
                        <div className="mt-4 space-y-4">
                            {readOnly ? (
                                <>
                                    <p className="text-subtle">
                                        {label(template.audience)} ·{' '}
                                        {template.role_id
                                            ? (roles.find(
                                                  (role) =>
                                                      role.id ===
                                                      template.role_id,
                                              )?.name ?? 'Recorded role')
                                            : 'Any permitted role'}{' '}
                                        ·{' '}
                                        {template.service_ids.length
                                            ? template.service_ids
                                                  .map(
                                                      (id) =>
                                                          value.services.find(
                                                              (service) =>
                                                                  service.id ===
                                                                  id,
                                                          )?.label ?? label(id),
                                                  )
                                                  .join(', ')
                                            : 'All services'}
                                    </p>
                                    <ul className="space-y-3">
                                        {template.items.map((item) => (
                                            <li
                                                key={item.id}
                                                className="text-subtle"
                                            >
                                                <p>
                                                    {item.title} ·{' '}
                                                    {item.enabled
                                                        ? item.required
                                                            ? 'Required'
                                                            : 'Optional'
                                                        : 'Archived'}
                                                </p>
                                                <p className="text-muted-foreground">
                                                    {item.instruction}
                                                </p>
                                            </li>
                                        ))}
                                    </ul>
                                </>
                            ) : (
                                <>
                                    <Field label="Checklist reference">
                                        <Input
                                            value={template.id}
                                            maxLength={64}
                                            onChange={(event) =>
                                                edit(index, {
                                                    id: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label="Checklist name">
                                        <Input
                                            value={template.label}
                                            maxLength={160}
                                            onChange={(event) =>
                                                edit(index, {
                                                    label: event.target.value,
                                                })
                                            }
                                        />
                                    </Field>
                                    <Field label="Information audience">
                                        <SelectInput
                                            ariaLabel="Checklist information audience"
                                            value={template.audience}
                                            onChange={(audience) =>
                                                edit(index, {
                                                    audience:
                                                        audience as ChecklistTemplate['audience'],
                                                })
                                            }
                                            placeholder="Choose audience"
                                            options={[
                                                'intake',
                                                'assessment',
                                                'funding',
                                            ].map((value) => ({
                                                value,
                                                label: label(value),
                                            }))}
                                        />
                                    </Field>
                                    <Field label="Responsible role">
                                        <SelectInput
                                            ariaLabel="Checklist responsible role"
                                            value={
                                                template.role_id === null
                                                    ? 'any'
                                                    : String(template.role_id)
                                            }
                                            onChange={(role) =>
                                                edit(index, {
                                                    role_id:
                                                        role === 'any'
                                                            ? null
                                                            : Number(role),
                                                })
                                            }
                                            placeholder="Choose role"
                                            options={[
                                                {
                                                    value: 'any',
                                                    label: 'Any permitted role',
                                                },
                                                ...roles.map((role) => ({
                                                    value: String(role.id),
                                                    label: role.name,
                                                })),
                                            ]}
                                        />
                                    </Field>
                                    <Field label="Required before">
                                        <SelectInput
                                            ariaLabel="Checklist required before"
                                            value={template.gate}
                                            onChange={(gate) =>
                                                edit(index, {
                                                    gate: gate as ChecklistTemplate['gate'],
                                                    ...(gate === 'none'
                                                        ? {
                                                              items: template.items.map(
                                                                  (item) => ({
                                                                      ...item,
                                                                      required: false,
                                                                  }),
                                                              ),
                                                          }
                                                        : {}),
                                                })
                                            }
                                            placeholder="Choose gate"
                                            options={[
                                                'none',
                                                'assessment',
                                                'funding_assistance',
                                                'handover',
                                            ].map((value) => ({
                                                value,
                                                label: checklistGateLabel(
                                                    value,
                                                ),
                                            }))}
                                        />
                                    </Field>
                                    <div className="flex items-center justify-between gap-4">
                                        <span className="text-subtle">
                                            Use this checklist (turn off to
                                            archive it)
                                        </span>
                                        <OnOff
                                            label="Use this checklist"
                                            checked={template.enabled}
                                            disabled={disabled || readOnly}
                                            onChange={(checked) =>
                                                edit(index, {
                                                    enabled: checked,
                                                })
                                            }
                                        />
                                    </div>
                                    <fieldset className="space-y-2">
                                        <legend className="text-subtle">
                                            Applies to services (none means all)
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
                                                    checked={template.service_ids.includes(
                                                        service.id,
                                                    )}
                                                    onCheckedChange={(
                                                        checked,
                                                    ) =>
                                                        edit(index, {
                                                            service_ids: checked
                                                                ? [
                                                                      ...template.service_ids,
                                                                      service.id,
                                                                  ]
                                                                : template.service_ids.filter(
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
                                    <div className="space-y-4">
                                        {template.items.map(
                                            (item, itemIndex) => {
                                                const change = (
                                                    patch: Partial<typeof item>,
                                                ) =>
                                                    edit(index, {
                                                        items: template.items.map(
                                                            (
                                                                entry,
                                                                position,
                                                            ) =>
                                                                position ===
                                                                itemIndex
                                                                    ? {
                                                                          ...entry,
                                                                          ...patch,
                                                                      }
                                                                    : entry,
                                                        ),
                                                    });
                                                return (
                                                    <div
                                                        key={itemIndex}
                                                        className="space-y-3 rounded-lg border p-4"
                                                    >
                                                        <Field label="Step reference">
                                                            <Input
                                                                value={item.id}
                                                                maxLength={64}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    change({
                                                                        id: event
                                                                            .target
                                                                            .value,
                                                                    })
                                                                }
                                                            />
                                                        </Field>
                                                        <Field label="Preparation step">
                                                            <Input
                                                                value={
                                                                    item.title
                                                                }
                                                                maxLength={160}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    change({
                                                                        title: event
                                                                            .target
                                                                            .value,
                                                                    })
                                                                }
                                                            />
                                                        </Field>
                                                        <Field label="Instructions">
                                                            <Textarea
                                                                value={
                                                                    item.instruction ??
                                                                    ''
                                                                }
                                                                maxLength={1000}
                                                                onChange={(
                                                                    event,
                                                                ) =>
                                                                    change({
                                                                        instruction:
                                                                            event
                                                                                .target
                                                                                .value,
                                                                    })
                                                                }
                                                            />
                                                        </Field>
                                                        <div className="flex items-center justify-between gap-4">
                                                            <span className="text-subtle">
                                                                Use this step
                                                                (turn off to
                                                                archive it)
                                                            </span>
                                                            <OnOff
                                                                label="Use this step"
                                                                checked={
                                                                    item.enabled
                                                                }
                                                                disabled={
                                                                    disabled ||
                                                                    readOnly
                                                                }
                                                                onChange={(
                                                                    checked,
                                                                ) =>
                                                                    change({
                                                                        enabled:
                                                                            checked,
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                        <div className="flex items-center justify-between gap-4">
                                                            <span className="text-subtle">
                                                                Required before
                                                                this gate
                                                            </span>
                                                            <OnOff
                                                                label="Required before this gate"
                                                                checked={
                                                                    item.required
                                                                }
                                                                disabled={
                                                                    disabled ||
                                                                    readOnly ||
                                                                    template.gate ===
                                                                        'none'
                                                                }
                                                                onChange={(
                                                                    checked,
                                                                ) =>
                                                                    change({
                                                                        required:
                                                                            checked,
                                                                    })
                                                                }
                                                            />
                                                        </div>
                                                    </div>
                                                );
                                            },
                                        )}
                                    </div>
                                    <Button
                                        variant="outline"
                                        disabled={template.items.length >= 30}
                                        onClick={() =>
                                            edit(index, {
                                                items: [
                                                    ...template.items,
                                                    {
                                                        id: reference('step'),
                                                        title: 'New preparation step',
                                                        instruction: '',
                                                        enabled: true,
                                                        required:
                                                            template.gate !==
                                                            'none',
                                                    },
                                                ],
                                            })
                                        }
                                    >
                                        <Plus className="size-4" />
                                        Add preparation step
                                    </Button>
                                    <p className="text-subtle">
                                        Archive published references instead of
                                        deleting them. Changed instructions or
                                        responsibility require a new review on
                                        migrated referrals; original completion
                                        history stays recorded.
                                    </p>
                                </>
                            )}
                        </div>
                    </details>
                ))}
                {!readOnly && (
                    <Button
                        variant="outline"
                        disabled={templates.length >= 20}
                        onClick={() =>
                            onChange({
                                ...value,
                                checklists: [
                                    ...templates,
                                    {
                                        id: reference('checklist'),
                                        label: 'New preparation checklist',
                                        enabled: true,
                                        audience: 'assessment',
                                        role_id: null,
                                        service_ids: [],
                                        gate: 'handover',
                                        items: [
                                            {
                                                id: reference('step'),
                                                title: 'New preparation step',
                                                instruction: '',
                                                enabled: true,
                                                required: true,
                                            },
                                        ],
                                    },
                                ],
                            })
                        }
                    >
                        <Plus className="size-4" />
                        Add checklist template
                    </Button>
                )}
            </fieldset>
        </EditorFrame>
    );
}
