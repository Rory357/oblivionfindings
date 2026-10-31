import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { DRILL_REGISTER } from '@/lib/health-safety/drill-navigation';
import { titleCase } from '@/pages/health-safety/drills/shared';
import { useEffect, useState } from 'react';
import { DrillCommandFooter, useDrillCommandForm } from './drill-command-form';
import type {
    DrillActor,
    DrillCorrectiveAction,
    DrillWorkspaceDetail,
    DrillWorkspaceFinding,
} from './types';

type Options = {
    actions: DrillCorrectiveAction[];
    staff: DrillActor[];
    can: { create: boolean; link: boolean };
};
export function DrillRemedyForm({
    detail,
    finding,
    returnTo,
    onSaved,
    onCancel,
}: {
    detail: DrillWorkspaceDetail;
    finding: DrillWorkspaceFinding;
    returnTo: string;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const [options, setOptions] = useState<Options | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [retry, setRetry] = useState(0);
    const { form, guards, save, failure, setFailure } = useDrillCommandForm(
        {
            mode: finding.can.create_action ? 'create' : 'link',
            title: '',
            description: finding.corrective_action ?? '',
            assigned_to_user_id: '',
            due_date: finding.due_date ?? '',
            priority: finding.severity ?? '',
            hs_corrective_action_id: '',
        },
        detail,
        onSaved,
    );
    useEffect(() => {
        const controller = new AbortController();
        if (!finding.corrective_action_options_href) return;
        fetch(finding.corrective_action_options_href, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
            signal: controller.signal,
        })
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'Available corrective actions could not be loaded. Try again.',
                    );
                const result = (await response.json()) as Options;
                if (
                    !Array.isArray(result.actions) ||
                    !Array.isArray(result.staff) ||
                    !result.can
                )
                    throw new Error(
                        'Available corrective actions could not be confirmed. Try again.',
                    );
                if (!controller.signal.aborted) {
                    setOptions(result);
                    setLoadError(null);
                }
            })
            .catch((error: unknown) => {
                if (!controller.signal.aborted)
                    setLoadError(
                        error instanceof Error
                            ? error.message
                            : 'Options could not be loaded.',
                    );
            });
        return () => controller.abort();
    }, [finding.corrective_action_options_href, retry]);
    if (!finding.can.create_action && !finding.can.link_action)
        return (
            <p role="alert">
                A corrective action can no longer be created or linked here.
                Review the current finding.
            </p>
        );
    const create = form.data.mode === 'create';
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (!options || loadError || form.processing) return;
                if (
                    create
                        ? !options.can.create ||
                          !options.staff.some(
                              (person) =>
                                  String(person.id) ===
                                  form.data.assigned_to_user_id,
                          ) ||
                          !form.data.priority
                        : !options.can.link ||
                          !options.actions.some(
                              (item) =>
                                  String(item.id) ===
                                  form.data.hs_corrective_action_id,
                          )
                ) {
                    setFailure(
                        create
                            ? 'Choose an eligible owner and priority for the corrective action.'
                            : 'Choose an available corrective action from this drill.',
                    );
                    return;
                }
                form.transform((data) => ({
                    ...guards,
                    return_to: returnTo,
                    section: 'findings',
                    action: 'corrective-action',
                    finding: finding.id,
                    mode: data.mode,
                    ...(create
                        ? {
                              title: data.title,
                              description: data.description || null,
                              assigned_to_user_id: Number(
                                  data.assigned_to_user_id,
                              ),
                              due_date: data.due_date,
                              priority: data.priority,
                          }
                        : {
                              hs_corrective_action_id: Number(
                                  data.hs_corrective_action_id,
                              ),
                          }),
                }));
                save(
                    'post',
                    `${DRILL_REGISTER}/${detail.id}/findings/${finding.id}/corrective-action`,
                );
            }}
        >
            <h2 className="text-section-title">
                Corrective action for this finding
            </h2>
            <p className="text-sm whitespace-pre-wrap">{finding.description}</p>
            <p className="text-subtle">
                The linked action becomes this finding’s required remedy. It has
                its own owner, due date and independent verification. Record the
                local finding resolution after that work is verified and
                effective.
            </p>
            {loadError ? (
                <div className="space-y-2">
                    <p role="alert" className="text-status-critical">
                        {loadError}
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        onClick={() => {
                            setLoadError(null);
                            setRetry((value) => value + 1);
                        }}
                    >
                        Retry options
                    </Button>
                </div>
            ) : !options ? (
                <p role="status">Loading available corrective work…</p>
            ) : (
                <>
                    <Field label="Corrective action">
                        <SelectInput
                            frontline
                            value={form.data.mode}
                            placeholder="Choose how to connect the work"
                            onChange={(value) => form.setData('mode', value)}
                            options={[
                                ...(options.can.create
                                    ? [
                                          {
                                              value: 'create',
                                              label: 'Create a corrective action',
                                          },
                                      ]
                                    : []),
                                ...(options.can.link
                                    ? [
                                          {
                                              value: 'link',
                                              label: 'Link an existing action from this drill',
                                          },
                                      ]
                                    : []),
                            ]}
                        />
                    </Field>
                    {create ? (
                        <>
                            <Field label="Action title" required>
                                <Input
                                    className="frontline-tap"
                                    required
                                    maxLength={255}
                                    value={form.data.title}
                                    onChange={(event) =>
                                        form.setData(
                                            'title',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Work required">
                                <Textarea
                                    rows={4}
                                    maxLength={5000}
                                    value={form.data.description}
                                    onChange={(event) =>
                                        form.setData(
                                            'description',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Action owner" required>
                                <SelectInput
                                    frontline
                                    value={form.data.assigned_to_user_id}
                                    placeholder="Choose an eligible owner"
                                    onChange={(value) =>
                                        form.setData(
                                            'assigned_to_user_id',
                                            value,
                                        )
                                    }
                                    options={options.staff.map((person) => ({
                                        value: String(person.id),
                                        label: person.name,
                                    }))}
                                />
                            </Field>
                            {!options.staff.length && (
                                <p role="status" className="text-subtle">
                                    No eligible action owners are available at
                                    this Site. An eligible owner is needed
                                    before this action can be created.
                                </p>
                            )}
                            <Field label="Due date (New Zealand)" required>
                                <Input
                                    type="date"
                                    className="frontline-tap"
                                    required
                                    value={form.data.due_date}
                                    onChange={(event) =>
                                        form.setData(
                                            'due_date',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                            <Field label="Priority" required>
                                <SelectInput
                                    frontline
                                    value={form.data.priority}
                                    placeholder="Choose priority"
                                    onChange={(value) =>
                                        form.setData('priority', value)
                                    }
                                    options={[
                                        'low',
                                        'medium',
                                        'high',
                                        'critical',
                                    ].map((value) => ({
                                        value,
                                        label: titleCase(value),
                                    }))}
                                />
                            </Field>
                        </>
                    ) : (
                        <>
                            <Field label="Existing corrective action" required>
                                <SelectInput
                                    frontline
                                    value={form.data.hs_corrective_action_id}
                                    placeholder="Choose an action from this drill"
                                    onChange={(value) =>
                                        form.setData(
                                            'hs_corrective_action_id',
                                            value,
                                        )
                                    }
                                    options={options.actions.map((item) => ({
                                        value: String(item.id),
                                        label: `${item.reference_number ?? 'Action'} · ${item.title ?? titleCase(item.status)}`,
                                    }))}
                                />
                            </Field>
                            {!options.actions.length && (
                                <p role="status" className="text-subtle">
                                    No eligible existing corrective actions are
                                    available for this drill.
                                </p>
                            )}
                        </>
                    )}
                </>
            )}
            <DrillCommandFooter
                label={
                    create ? 'Create and link action' : 'Link required remedy'
                }
                processing={form.processing}
                disabled={!options || !!loadError}
                failure={failure}
                errors={form.errors}
                onCancel={onCancel}
            />
        </form>
    );
}
