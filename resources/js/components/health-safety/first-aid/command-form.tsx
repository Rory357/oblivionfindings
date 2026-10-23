import { actualEventInstant } from '@/components/clinical/actual-event-time-field';
import { DrillTimeField } from '@/components/health-safety/drills/drill-time-field';
import { HazardStaffPicker } from '@/components/health-safety/hazard-staff-picker';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import { toDatetimeLocal, workerTimeOffset } from '@/lib/datetime';
import type { FormDataType, Method } from '@inertiajs/core';
import { useState } from 'react';
import type {
    FirstAidCommand,
    FirstAidPickers,
    FirstAidRecord,
    FirstAidSection,
} from './types';

export const FIRST_AID_COMMAND_LABELS: Record<FirstAidCommand, string> = {
    edit: 'Edit treatment record',
    archive: 'Archive treatment record',
    'link-incident': 'Connect an incident',
    'add-followup': 'Add follow-up',
    'edit-followup': 'Edit follow-up',
    'complete-followup': 'Record follow-up outcome',
    'add-evidence': 'Add evidence',
    'remove-evidence': 'Remove evidence',
};
export const FIRST_AID_COMMAND_SECTIONS: Record<
    FirstAidCommand,
    FirstAidSection
> = {
    edit: 'overview',
    archive: 'overview',
    'link-incident': 'incident',
    'add-followup': 'followups',
    'edit-followup': 'followups',
    'complete-followup': 'followups',
    'add-evidence': 'evidence',
    'remove-evidence': 'evidence',
};
const RECEIPT_COMMAND: Record<FirstAidCommand | 'create', string> = {
    create: 'create',
    edit: 'update',
    archive: 'archive',
    'link-incident': 'link_incident',
    'add-followup': 'add_followup',
    'edit-followup': 'update_followup',
    'complete-followup': 'complete_followup',
    'add-evidence': 'add_evidence',
    'remove-evidence': 'remove_evidence',
};
export function firstAidCommandAllowed(
    d: FirstAidRecord,
    command: FirstAidCommand,
    childId?: number | null,
) {
    if (d.status === 'archived') return false;
    switch (command) {
        case 'edit-followup':
            return d.followups.some(
                (item) => item.id === childId && item.can.edit,
            );
        case 'complete-followup':
            return d.followups.some(
                (item) => item.id === childId && item.can.complete,
            );
        case 'remove-evidence':
            return d.attachments.some(
                (item) => item.id === childId && item.can.remove,
            );
        case 'link-incident':
            return d.can.link_incident || d.can.create_incident;
        case 'add-followup':
            return d.can.add_followup;
        case 'add-evidence':
            return d.can.add_evidence;
        default:
            return d.can[command];
    }
}
export function confirmedFirstAidCommand(
    value: unknown,
    expected: {
        id?: number;
        version?: number;
        key: string;
        command: FirstAidCommand | 'create';
    },
) {
    if (!value || typeof value !== 'object') return false;
    const receipt = value as Record<string, unknown>;
    return (
        Number.isSafeInteger(receipt.id) &&
        Number(receipt.id) > 0 &&
        (expected.id == null || receipt.id === expected.id) &&
        receipt.lock_version ===
            (expected.version == null ? 1 : expected.version + 1) &&
        receipt.idempotency_key === expected.key &&
        receipt.command === RECEIPT_COMMAND[expected.command] &&
        receipt.removed === (expected.command === 'archive')
    );
}
export function useFirstAidCommand<T extends FormDataType<T>>(
    initial: T,
    detail: FirstAidRecord | null,
    command: FirstAidCommand | 'create',
    onSaved: () => void,
) {
    const [expected] = useState(() => ({
        id: detail?.id,
        version: detail?.expected_version,
        key: crypto.randomUUID(),
        command,
    }));
    const [failure, setFailure] = useState<string | null>(null);
    const form = useSafetyForm(initial);
    const guards = {
        idempotency_key: expected.key,
        ...(expected.version != null
            ? { expected_version: expected.version }
            : {}),
    };
    const save = (method: Method, href: string) => {
        if (form.processing) return;
        setFailure(null);
        let responded = false;
        form.submit(method, href, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                responded = true;
                const error = (
                    page.props.flash as { error?: string } | undefined
                )?.error;
                if (error) {
                    setFailure(error);
                    return;
                }
                if (
                    !confirmedFirstAidCommand(
                        page.props.first_aid_confirmation,
                        expected,
                    )
                ) {
                    setFailure(
                        'This change could not be confirmed. Your entries are still here. Check the record before trying again.',
                    );
                    return;
                }
                form.reset();
                onSaved();
            },
            onError: () => {
                responded = true;
            },
            onCancel: () => {
                responded = true;
                setFailure(
                    'Saving was interrupted. Your entries are still here. Check the record before trying again.',
                );
            },
            onFinish: () => {
                if (!responded)
                    setFailure(
                        'The save could not be confirmed. Your entries are still here. Check the record before trying again.',
                    );
            },
        });
    };
    return { form, guards, save, failure, setFailure };
}

/** The record owns each follow-up and evidence change; opening Tasks cannot bypass this command. */
export function FirstAidCommandForm({
    detail: d,
    command,
    childId,
    pickers,
    returnTo,
    onSaved,
    onCancel,
}: {
    detail: FirstAidRecord;
    command: Exclude<FirstAidCommand, 'edit'>;
    childId?: number | null;
    pickers: FirstAidPickers;
    returnTo: string;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const [followup] = useState(() =>
        d.followups.find((item) => item.id === childId),
    );
    const attachment = d.attachments.find((item) => item.id === childId);
    const { form, guards, save, failure, setFailure } = useFirstAidCommand(
        {
            notes: command === 'edit-followup' ? (followup?.notes ?? '') : '',
            assigned_to_user_id:
                command === 'edit-followup'
                    ? String(followup?.assigned_to?.id ?? '')
                    : '',
            due_at:
                command === 'edit-followup'
                    ? toDatetimeLocal(followup?.due_at)
                    : '',
            due_offset:
                command === 'edit-followup' && followup?.due_at
                    ? workerTimeOffset(followup.due_at)
                    : '',
            completion_notes: '',
            outcome_kind: '',
            outcome_result: '',
            outcome_at: '',
            outcome_offset: '',
            outcome_note: '',
            completion_evidence_ids: [] as number[],
            incident_mode: '',
            related_incident_id: '',
            immediate_action_taken: '',
            file: null as File | null,
            kind: '',
            alt_text: '',
        },
        d,
        command,
        onSaved,
    );
    const allowed = firstAidCommandAllowed(d, command, childId);
    const editingFollowup =
        command === 'add-followup' || command === 'edit-followup';
    const choices = pickers.followup_assignees.map((person) => ({
        value: String(person.id),
        label: person.name,
    }));
    if (
        followup?.assigned_to &&
        !choices.some((item) => item.value === String(followup.assigned_to!.id))
    ) {
        choices.unshift({
            value: String(followup.assigned_to.id),
            label: `${followup.assigned_to.name} (recorded assignment)`,
        });
    }
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.processing || !allowed) return;
                setFailure(null);
                const due =
                    command === 'edit-followup' &&
                    followup?.due_at &&
                    form.data.due_at === toDatetimeLocal(followup.due_at) &&
                    form.data.due_offset === workerTimeOffset(followup.due_at)
                        ? followup.due_at
                        : form.data.due_at
                          ? actualEventInstant(
                                form.data.due_at,
                                form.data.due_offset,
                            )
                          : null;
                if (editingFollowup && form.data.due_at && !due) {
                    setFailure(
                        'Choose a valid New Zealand due time and its clock occurrence if requested.',
                    );
                    return;
                }
                const outcome = form.data.outcome_kind
                    ? actualEventInstant(
                          form.data.outcome_at,
                          form.data.outcome_offset,
                      )
                    : null;
                if (
                    command === 'complete-followup' &&
                    form.data.outcome_kind &&
                    (!outcome ||
                        !form.data.outcome_result ||
                        Date.parse(outcome) > Date.now())
                ) {
                    setFailure(
                        'Record the actual outcome time and result. An outcome cannot be in the future.',
                    );
                    return;
                }
                if (
                    command === 'link-incident' &&
                    !(
                        (form.data.incident_mode === 'existing' &&
                            d.can.link_incident &&
                            form.data.related_incident_id) ||
                        (form.data.incident_mode === 'create' &&
                            d.can.create_incident)
                    )
                ) {
                    setFailure(
                        'Choose an available incident or choose to create one.',
                    );
                    return;
                }
                if (
                    command === 'add-evidence' &&
                    (!form.data.file || form.data.file.size > 20 * 1024 * 1024)
                ) {
                    setFailure('Choose one supported file up to 20 MB.');
                    return;
                }
                form.transform((data) => ({
                    ...guards,
                    return_to: returnTo,
                    section: FIRST_AID_COMMAND_SECTIONS[command],
                    action: command,
                    ...(childId && command.includes('followup')
                        ? { followup: childId }
                        : {}),
                    ...(editingFollowup
                        ? {
                              notes: data.notes,
                              assigned_to_user_id: data.assigned_to_user_id
                                  ? Number(data.assigned_to_user_id)
                                  : null,
                              due_at: due,
                          }
                        : {}),
                    ...(command === 'complete-followup'
                        ? {
                              completion_notes: data.completion_notes,
                              completion_outcome: data.outcome_kind
                                  ? {
                                        kind: data.outcome_kind,
                                        result: data.outcome_result,
                                        occurred_at: outcome,
                                        note: data.outcome_note || null,
                                    }
                                  : null,
                              completion_evidence_ids:
                                  data.completion_evidence_ids,
                          }
                        : {}),
                    ...(command === 'link-incident'
                        ? {
                              related_incident_id:
                                  data.incident_mode === 'existing'
                                      ? Number(data.related_incident_id)
                                      : null,
                              immediate_action_taken:
                                  data.immediate_action_taken || null,
                          }
                        : {}),
                    ...(command === 'add-evidence'
                        ? {
                              file: data.file,
                              kind: data.kind || null,
                              notes: data.notes || null,
                              alt_text: data.alt_text || null,
                          }
                        : {}),
                }));
                const suffix =
                    command === 'archive'
                        ? ''
                        : command === 'link-incident'
                          ? '/link-incident'
                          : command === 'add-followup'
                            ? '/followups'
                            : command === 'edit-followup'
                              ? `/followups/${childId}`
                              : command === 'complete-followup'
                                ? `/followups/${childId}/complete`
                                : command === 'add-evidence'
                                  ? '/attachments'
                                  : `/attachments/${childId}`;
                const method =
                    command === 'archive' || command === 'remove-evidence'
                        ? 'delete'
                        : command === 'edit-followup'
                          ? 'put'
                          : command === 'complete-followup'
                            ? 'patch'
                            : 'post';
                save(method, `/health-safety/first-aid/${d.id}${suffix}`);
            }}
        >
            <h2 className="text-section-title">
                {FIRST_AID_COMMAND_LABELS[command]}
            </h2>
            {!allowed && (
                <p role="alert" className="text-sm text-status-critical">
                    This action is no longer available. Your entries remain here
                    for you to review.
                </p>
            )}
            {editingFollowup && (
                <>
                    <Field label="What needs to happen" required>
                        <Textarea
                            required
                            maxLength={2000}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Follow-up owner">
                        <HazardStaffPicker
                            searchLabel="Search available follow-up owners"
                            listLabel="Available follow-up owners"
                            emptyLabel="No available owners match this search."
                            value={form.data.assigned_to_user_id}
                            onChange={(value) =>
                                form.setData('assigned_to_user_id', value)
                            }
                            options={choices}
                            placeholder="Unassigned"
                        />
                    </Field>
                    {form.data.assigned_to_user_id && (
                        <Button
                            type="button"
                            variant="outline"
                            className="frontline-tap"
                            disabled={form.processing}
                            onClick={() =>
                                form.setData('assigned_to_user_id', '')
                            }
                        >
                            Leave unassigned
                        </Button>
                    )}
                    <DrillTimeField
                        required={false}
                        label="Due (optional)"
                        value={form.data.due_at}
                        offset={form.data.due_offset}
                        onChange={(value, offset) =>
                            form.setData((data) => ({
                                ...data,
                                due_at: value,
                                due_offset: offset,
                            }))
                        }
                    />
                    <p className="text-subtle">
                        This person owns the follow-up. The linked incident
                        keeps its own owner and review.
                    </p>
                </>
            )}
            {command === 'complete-followup' && (
                <>
                    <p className="text-sm whitespace-pre-wrap">
                        {followup?.notes}
                    </p>
                    <Field label="What happened" required>
                        <Textarea
                            required
                            maxLength={2000}
                            value={form.data.completion_notes}
                            onChange={(event) =>
                                form.setData(
                                    'completion_notes',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <Field label="Outcome type (optional)">
                        <SelectInput
                            frontline
                            value={form.data.outcome_kind}
                            onChange={(value) =>
                                form.setData('outcome_kind', value)
                            }
                            placeholder="No structured outcome"
                            options={[
                                { value: 'action', label: 'Action' },
                                { value: 'contact', label: 'Contact' },
                                {
                                    value: 'claim_followup',
                                    label: 'Claim follow-up',
                                },
                                { value: 'observation', label: 'Observation' },
                            ]}
                        />
                    </Field>
                    {form.data.outcome_kind && (
                        <>
                            <Button
                                type="button"
                                variant="outline"
                                className="frontline-tap"
                                disabled={form.processing}
                                onClick={() => form.setData('outcome_kind', '')}
                            >
                                Remove structured outcome
                            </Button>
                            <Field label="Recorded result" required>
                                <SelectInput
                                    frontline
                                    value={form.data.outcome_result}
                                    onChange={(value) =>
                                        form.setData('outcome_result', value)
                                    }
                                    placeholder="Choose the actual result"
                                    options={[
                                        {
                                            value: 'recorded',
                                            label: 'Recorded',
                                        },
                                        {
                                            value: 'contacted',
                                            label: 'Contact made',
                                        },
                                        {
                                            value: 'attempted',
                                            label: 'Attempt made',
                                        },
                                        {
                                            value: 'unable_to_contact',
                                            label: 'Unable to make contact',
                                        },
                                        {
                                            value: 'not_required',
                                            label: 'Not required',
                                        },
                                        { value: 'other', label: 'Other' },
                                    ]}
                                />
                            </Field>
                            <DrillTimeField
                                label="When the outcome happened"
                                value={form.data.outcome_at}
                                offset={form.data.outcome_offset}
                                onChange={(value, offset) =>
                                    form.setData((data) => ({
                                        ...data,
                                        outcome_at: value,
                                        outcome_offset: offset,
                                    }))
                                }
                            />
                            <Field label="Outcome note (optional)">
                                <Textarea
                                    maxLength={2000}
                                    value={form.data.outcome_note}
                                    onChange={(event) =>
                                        form.setData(
                                            'outcome_note',
                                            event.target.value,
                                        )
                                    }
                                />
                            </Field>
                        </>
                    )}
                    <fieldset className="space-y-2">
                        <legend className="text-sm font-medium">
                            Evidence already in this record (optional)
                        </legend>
                        {d.attachments
                            .filter((file) => file.availability === 'available')
                            .map((file) => (
                                <label
                                    key={file.id}
                                    className="frontline-tap flex items-center gap-3 rounded-lg border p-3"
                                >
                                    <Checkbox
                                        checked={form.data.completion_evidence_ids.includes(
                                            file.id,
                                        )}
                                        onCheckedChange={(checked) =>
                                            form.setData(
                                                'completion_evidence_ids',
                                                checked === true
                                                    ? [
                                                          ...new Set([
                                                              ...form.data
                                                                  .completion_evidence_ids,
                                                              file.id,
                                                          ]),
                                                      ]
                                                    : form.data.completion_evidence_ids.filter(
                                                          (id) =>
                                                              id !== file.id,
                                                      ),
                                            )
                                        }
                                    />
                                    <span className="break-words">
                                        {file.original_name}
                                    </span>
                                </label>
                            ))}
                        {!d.attachments.some(
                            (file) => file.availability === 'available',
                        ) && (
                            <p className="text-subtle">
                                No available files are recorded here.
                            </p>
                        )}
                    </fieldset>
                    {form.data.completion_evidence_ids.length > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            className="frontline-tap"
                            disabled={form.processing}
                            onClick={() =>
                                form.setData('completion_evidence_ids', [])
                            }
                        >
                            Clear evidence selection
                        </Button>
                    )}
                    <p className="text-subtle">
                        Record what actually happened. This does not send a
                        message, submit a claim or record clinical clearance.
                    </p>
                </>
            )}
            {command === 'link-incident' && (
                <>
                    <Field label="How to connect the incident" required>
                        <SelectInput
                            frontline
                            value={form.data.incident_mode}
                            onChange={(value) =>
                                form.setData('incident_mode', value)
                            }
                            placeholder="Choose an option"
                            options={[
                                ...(d.can.link_incident
                                    ? [
                                          {
                                              value: 'existing',
                                              label: 'Link an existing incident',
                                          },
                                      ]
                                    : []),
                                ...(d.can.create_incident
                                    ? [
                                          {
                                              value: 'create',
                                              label: 'Create an incident from this treatment',
                                          },
                                      ]
                                    : []),
                            ]}
                        />
                    </Field>
                    {form.data.incident_mode === 'existing' && (
                        <Field label="Incident" required>
                            <HazardStaffPicker
                                searchLabel="Search available incidents"
                                listLabel="Available incidents"
                                emptyLabel="No available incidents match this search."
                                value={form.data.related_incident_id}
                                onChange={(value) =>
                                    form.setData('related_incident_id', value)
                                }
                                placeholder="Choose an available incident"
                                options={pickers.incidents.map((item) => ({
                                    value: String(item.id),
                                    label: `${item.reference ?? `Incident ${item.id}`}${item.title ? ` · ${item.title}` : ''}`,
                                }))}
                            />
                        </Field>
                    )}
                    {form.data.incident_mode === 'create' && (
                        <Field label="Immediate action taken" required>
                            <Textarea
                                required
                                maxLength={5000}
                                value={form.data.immediate_action_taken}
                                onChange={(event) =>
                                    form.setData(
                                        'immediate_action_taken',
                                        event.target.value,
                                    )
                                }
                            />
                        </Field>
                    )}
                    <p className="text-subtle">
                        The incident uses this treatment's person and Site. Its
                        ownership, review and closure remain separate.
                    </p>
                </>
            )}
            {command === 'add-evidence' && (
                <>
                    <FileDropzone
                        id="first-aid-evidence"
                        multiple={false}
                        disabled={form.processing}
                        accept=".jpg,.jpeg,.png,.gif,.webp,.pdf,.doc,.docx"
                        hint="One image, PDF or Word document, up to 20 MB"
                        onFiles={(files) => {
                            if (!form.processing)
                                form.setData('file', files[0] ?? null);
                        }}
                    />
                    {form.data.file && (
                        <div className="[&_button]:min-h-11 [&_button]:min-w-11">
                            <StagedFileCard
                                file={form.data.file}
                                onRemove={() => {
                                    if (!form.processing)
                                        form.setData('file', null);
                                }}
                            />
                            <p className="text-subtle">
                                {form.processing
                                    ? `Uploading${form.progress?.percentage != null ? ` · ${form.progress.percentage}%` : '…'}`
                                    : 'Selected · not saved yet'}
                            </p>
                        </div>
                    )}
                    <Field label="Evidence kind">
                        <SelectInput
                            frontline
                            value={form.data.kind}
                            onChange={(value) => form.setData('kind', value)}
                            options={pickers.attachment_kinds}
                            placeholder="Not specified"
                        />
                    </Field>
                    <Field label="Notes">
                        <Textarea
                            maxLength={1000}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Image description">
                        <Input
                            maxLength={255}
                            value={form.data.alt_text}
                            onChange={(event) =>
                                form.setData('alt_text', event.target.value)
                            }
                        />
                    </Field>
                </>
            )}
            {command === 'remove-evidence' && (
                <p className="text-sm">
                    Remove {attachment?.original_name ?? 'this file'} from the
                    available evidence? The recorded history is retained.
                    Evidence used for a completed outcome cannot be removed
                    here.
                </p>
            )}
            {command === 'archive' && (
                <p className="text-sm">
                    Archive this treatment record? Its history and evidence are
                    retained. Each follow-up must have a recorded outcome first.
                    Archiving does not close the linked incident or safety
                    review.
                </p>
            )}
            {(failure || Object.keys(form.errors).length > 0) && (
                <p role="alert" className="text-sm text-status-critical">
                    {failure ?? Object.values(form.errors).join(' ')}
                </p>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap"
                    type="submit"
                    disabled={!allowed || form.processing}
                >
                    {form.processing
                        ? 'Saving…'
                        : FIRST_AID_COMMAND_LABELS[command]}
                </Button>
                <Button
                    className="frontline-tap"
                    type="button"
                    variant="outline"
                    disabled={form.processing}
                    onClick={onCancel}
                >
                    Back to treatment record
                </Button>
            </div>
        </form>
    );
}
