import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    defaultWorkflowTransitions,
    type ReferralConfiguration,
} from './settings-types';
import { EditorFrame } from './settings-ui';
import { label } from './types';

const sources = [
    'new',
    'triage',
    'assessment',
    'funding_assistance',
    'ready_for_handover',
    'on_hold',
];
const targets = ['triage', 'assessment', 'funding_assistance'];
const evidence = [
    'assessment:authority',
    'assessment:identity',
    'assessment:assessment',
    'funding:application',
    'funding:external_decision',
    'funding:agreement',
];
type Transition = NonNullable<
    ReferralConfiguration['workflow']
>['transitions'][number];

export function SettingsWorkflow({
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
    const [selected, setSelected] = useState('');
    const rules = value.workflow?.transitions ?? defaultWorkflowTransitions();
    const change = (next: Transition[]) =>
        onChange({ ...value, workflow: { transitions: next } });
    const update = (index: number, patch: Partial<Transition>) =>
        change(
            rules.map((rule, position) =>
                position === index ? { ...rule, ...patch } : rule,
            ),
        );
    const name = (id: string) =>
        value.stages.find((stage) => stage.id === id)?.label ?? label(id);
    const unused = sources.flatMap((from) =>
        targets
            .filter(
                (to) =>
                    to !== from &&
                    !rules.some((rule) => rule.from === from && rule.to === to),
            )
            .map((to) => ({ from, to })),
    );
    const next =
        unused.find((rule) => `${rule.from}:${rule.to}` === selected) ??
        unused[0];
    return (
        <EditorFrame
            embedded={embedded}
            header={
                <>
                    <h2 className="text-section-title">
                        Allowed stage changes
                    </h2>
                    <p className="text-subtle">
                        These rules belong to each referral’s recorded settings
                        version. Responsible roles add a requirement; they never
                        grant access. Conversion, closure, reopening and
                        service-start checks retain their separate controls.
                    </p>
                </>
            }
            contentClassName="space-y-4"
        >
            <p className="text-subtle">
                Initial triage remains available without personal evidence. All
                working stages must be reachable. Document availability does not
                mean an external award or consent has been verified.
            </p>
            <fieldset disabled={disabled || readOnly} className="space-y-3">
                {rules.map((rule, index) => (
                    <details
                        key={`${rule.from}:${rule.to}`}
                        className="rounded-lg border border-border p-4"
                    >
                        <summary className="cursor-pointer rounded py-1 font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">
                            {name(rule.from)} → {name(rule.to)}
                            <span className="text-subtle ml-2">
                                {rule.role_id
                                    ? (roles.find(
                                          (role) => role.id === rule.role_id,
                                      )?.name ?? 'Archived role')
                                    : 'Any permitted role'}{' '}
                                ·{' '}
                                {rule.evidence.length === 0
                                    ? 'No additional checks'
                                    : `${rule.evidence.length} evidence ${rule.evidence.length === 1 ? 'check' : 'checks'}`}
                            </span>
                        </summary>
                        <div className="mt-4 space-y-4">
                            {readOnly ? (
                                <p className="text-subtle">
                                    {rule.evidence
                                        .map((item) =>
                                            item
                                                .split(':')
                                                .map(label)
                                                .join(' · '),
                                        )
                                        .join('; ') ||
                                        'No additional document requirement'}
                                </p>
                            ) : (
                                <>
                                    <Field label="Responsible role">
                                        <SelectInput
                                            value={
                                                rule.role_id === null
                                                    ? 'any'
                                                    : String(rule.role_id)
                                            }
                                            placeholder="Choose responsible role"
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
                                            onChange={(value) =>
                                                update(index, {
                                                    role_id:
                                                        value === 'any'
                                                            ? null
                                                            : Number(value),
                                                })
                                            }
                                        />
                                    </Field>
                                    {!(
                                        rule.from === 'new' &&
                                        rule.to === 'triage'
                                    ) && (
                                        <div className="grid gap-3 md:grid-cols-2">
                                            {evidence.map((item) => (
                                                <label
                                                    key={item}
                                                    className="flex items-center gap-3"
                                                >
                                                    <Checkbox
                                                        checked={rule.evidence.includes(
                                                            item,
                                                        )}
                                                        onCheckedChange={(
                                                            checked,
                                                        ) =>
                                                            update(index, {
                                                                evidence:
                                                                    checked
                                                                        ? [
                                                                              ...rule.evidence,
                                                                              item,
                                                                          ]
                                                                        : rule.evidence.filter(
                                                                              (
                                                                                  value,
                                                                              ) =>
                                                                                  value !==
                                                                                  item,
                                                                          ),
                                                            })
                                                        }
                                                    />
                                                    {item
                                                        .split(':')
                                                        .map(label)
                                                        .join(' · ')}
                                                </label>
                                            ))}
                                        </div>
                                    )}
                                    {rule.from === 'new' &&
                                    rule.to === 'triage' ? (
                                        <p className="text-subtle">
                                            Protected entry route for support
                                            requests and process enquiries.
                                        </p>
                                    ) : (
                                        <Button
                                            variant="ghost"
                                            type="button"
                                            onClick={() =>
                                                change(
                                                    rules.filter(
                                                        (_, position) =>
                                                            position !== index,
                                                    ),
                                                )
                                            }
                                        >
                                            <Trash2 className="size-4" />
                                            Remove this transition
                                        </Button>
                                    )}
                                </>
                            )}
                        </div>
                    </details>
                ))}
                {!readOnly && (
                    <div className="space-y-3">
                        {next && (
                            <Field label="Transition to add">
                                <SelectInput
                                    value={`${next.from}:${next.to}`}
                                    onChange={setSelected}
                                    placeholder="Choose a transition"
                                    options={unused.map((rule) => ({
                                        value: `${rule.from}:${rule.to}`,
                                        label: `${name(rule.from)} → ${name(rule.to)}`,
                                    }))}
                                />
                            </Field>
                        )}
                        <Button
                            variant="outline"
                            type="button"
                            disabled={!unused.length}
                            onClick={() => {
                                if (next)
                                    change([
                                        ...rules,
                                        {
                                            ...next,
                                            role_id: null,
                                            evidence: [],
                                        },
                                    ]);
                            }}
                        >
                            <Plus className="size-4" />
                            {next
                                ? 'Add allowed transition'
                                : 'All working transitions included'}
                        </Button>
                    </div>
                )}
            </fieldset>
        </EditorFrame>
    );
}
