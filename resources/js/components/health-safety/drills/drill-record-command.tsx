import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import { DRILL_REGISTER } from '@/lib/health-safety/drill-navigation';
import { titleCase } from '@/pages/health-safety/drills/shared';
import { useState } from 'react';
import type { DrillPickers, DrillWorkspaceDetail } from './types';

export type DrillRecordCommand =
    | 'start'
    | 'cancel'
    | 'add-participant'
    | 'edit-participant'
    | 'add-finding'
    | 'edit-finding'
    | 'resolve-finding';
export const DRILL_RECORD_COMMAND_LABELS: Record<DrillRecordCommand, string> = {
    start: 'Start drill',
    cancel: 'Cancel drill',
    'add-participant': 'Add participant',
    'edit-participant': 'Update participant',
    'add-finding': 'Record finding',
    'edit-finding': 'Update finding',
    'resolve-finding': 'Record finding resolution',
};

export function DrillRecordCommandForm({
    detail: d,
    pickers,
    command,
    findingId,
    participantId,
    context = {},
    onSaved,
    onCancel,
}: {
    detail: DrillWorkspaceDetail;
    pickers: DrillPickers;
    command: DrillRecordCommand;
    findingId?: number | null;
    participantId?: number | null;
    context?: {
        return_to?: string;
        section?: string;
        action?: string;
        participant?: number;
    };
    onSaved: () => void;
    onCancel: () => void;
}) {
    const finding = d.findings.find((item) => item.id === findingId);
    const participant = d.participants.find(
        (item) => item.id === participantId,
    );
    const isParticipant =
        command === 'add-participant' || command === 'edit-participant';
    const isFinding = command === 'add-finding' || command === 'edit-finding';
    const resolving = command === 'resolve-finding';
    const allowed =
        command === 'start'
            ? d.can.start
            : command === 'cancel'
              ? d.can.cancel
              : command === 'add-participant'
                ? d.can.add_participant
                : command === 'edit-participant'
                  ? participant?.can.edit
                  : command === 'add-finding'
                    ? d.can.add_finding
                    : command === 'edit-finding'
                      ? finding?.can.edit
                      : finding?.can.resolve;
    const [identity] = useState(() => crypto.randomUUID());
    const [failure, setFailure] = useState<string | null>(null);
    const form = useSafetyForm({
        expected_version: d.expected_version,
        idempotency_key: identity,
        reason: '',
        user_id: participant ? String(participant.user_id) : '',
        role: participant?.role ?? 'participant',
        attended: participant?.attended ?? false,
        notes: participant?.notes ?? '',
        accommodations: participant?.accommodations ?? '',
        finding_type: finding?.finding_type ?? 'observation',
        description: finding?.description ?? '',
        severity: finding?.severity ?? '',
        corrective_action: finding?.corrective_action ?? '',
        assigned_to: finding?.assigned_to ? String(finding.assigned_to) : '',
        due_date: finding?.due_date ?? '',
        status: finding?.status === 'in_progress' ? 'in_progress' : 'open',
        resolution_notes: '',
        resolution_evidence_ids: [] as number[],
    });
    if (!allowed)
        return (
            <div className="space-y-3">
                <p role="alert">
                    This action is no longer available. Review the current drill
                    and any linked remedy.
                </p>
                <Button
                    variant="outline"
                    className="frontline-tap"
                    onClick={onCancel}
                >
                    Back to drill
                </Button>
            </div>
        );
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.processing || !allowed) return;
                setFailure(null);
                if (isParticipant && !form.data.user_id) {
                    setFailure('Choose a participant from this Site.');
                    return;
                }
                if (
                    resolving &&
                    (!form.data.resolution_notes.trim() ||
                        !form.data.resolution_evidence_ids.length)
                ) {
                    setFailure(
                        'Record the resolution and select supporting evidence from this drill.',
                    );
                    return;
                }
                form.transform((data) => ({
                    expected_version: data.expected_version,
                    idempotency_key: data.idempotency_key,
                    ...context,
                    ...(command === 'cancel' ? { reason: data.reason } : {}),
                    ...(isParticipant
                        ? {
                              user_id: Number(data.user_id),
                              role: data.role,
                              attended: data.attended,
                              notes: data.notes || null,
                              accommodations: data.accommodations || null,
                          }
                        : {}),
                    ...(isFinding
                        ? {
                              finding_type: data.finding_type,
                              description: data.description,
                              severity: data.severity,
                              corrective_action: data.corrective_action || null,
                              assigned_to: data.assigned_to
                                  ? Number(data.assigned_to)
                                  : null,
                              due_date: data.due_date || null,
                              ...(command === 'edit-finding'
                                  ? { status: data.status }
                                  : {}),
                          }
                        : {}),
                    ...(resolving
                        ? {
                              resolution_notes: data.resolution_notes,
                              resolution_evidence_ids:
                                  data.resolution_evidence_ids,
                          }
                        : {}),
                }));
                const suffix = isParticipant
                    ? 'participants'
                    : isFinding
                      ? `findings${command === 'edit-finding' ? `/${finding!.id}` : ''}`
                      : resolving
                        ? `findings/${finding!.id}/resolve`
                        : command;
                let responded = false;
                form.submit(
                    command === 'edit-finding' ? 'put' : 'post',
                    `${DRILL_REGISTER}/${d.id}/${suffix}`,
                    {
                        preserveState: true,
                        preserveScroll: true,
                        onSuccess: (page) => {
                            responded = true;
                            const error = (
                                page.props.flash as
                                    | { error?: string }
                                    | undefined
                            )?.error;
                            if (error) {
                                setFailure(error);
                                return;
                            }
                            const updated = page.props.detail as
                                | DrillWorkspaceDetail
                                | undefined;
                            if (
                                !updated ||
                                updated.id !== d.id ||
                                updated.lock_version <=
                                    form.data.expected_version
                            ) {
                                setFailure(
                                    'The saved change could not be confirmed. Your entries are still here. Check the drill before trying again.',
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
                                'Saving was interrupted. Your entries are still here. Check the drill before trying again.',
                            );
                        },
                        onFinish: () => {
                            if (!responded)
                                setFailure(
                                    'The save could not be confirmed. Your entries are still here. Check the drill before trying again.',
                                );
                        },
                    },
                );
            }}
        >
            <h2 className="text-section-title">
                {DRILL_RECORD_COMMAND_LABELS[command]}
            </h2>
            {command === 'start' && (
                <p className="text-subtle">
                    Starting records the current time and opens the drill for
                    attendance and completion.
                </p>
            )}
            {command === 'cancel' && (
                <>
                    <p className="text-subtle">
                        The drill will be cancelled and its recorded history
                        retained.
                    </p>
                    <Field label="Reason for cancellation" required>
                        <Textarea
                            required
                            rows={4}
                            maxLength={2000}
                            value={form.data.reason}
                            onChange={(event) =>
                                form.setData('reason', event.target.value)
                            }
                        />
                    </Field>
                </>
            )}
            {isParticipant && (
                <>
                    {participant ? (
                        <p className="font-medium">{participant.name}</p>
                    ) : (
                        <Field label="Participant" required>
                            <SelectInput
                                frontline
                                value={form.data.user_id}
                                placeholder="Choose a person at this Site"
                                onChange={(value) =>
                                    form.setData('user_id', value)
                                }
                                options={pickers.staff.map((person) => ({
                                    value: String(person.id),
                                    label: person.name,
                                }))}
                            />
                        </Field>
                    )}
                    <Field label="Role in the drill">
                        <Input
                            className="frontline-tap"
                            value={form.data.role}
                            maxLength={100}
                            onChange={(event) =>
                                form.setData('role', event.target.value)
                            }
                        />
                    </Field>
                    <label className="frontline-tap flex items-center gap-3">
                        <Checkbox
                            checked={form.data.attended}
                            onCheckedChange={(value) =>
                                form.setData('attended', value === true)
                            }
                        />
                        Attendance confirmed
                    </label>
                    <Field label="Participation notes">
                        <Textarea
                            rows={3}
                            maxLength={500}
                            value={form.data.notes}
                            onChange={(event) =>
                                form.setData('notes', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Support or accommodations for this drill">
                        <Textarea
                            rows={3}
                            maxLength={2000}
                            value={form.data.accommodations}
                            onChange={(event) =>
                                form.setData(
                                    'accommodations',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                </>
            )}
            {isFinding && (
                <>
                    <Field label="Finding type" required>
                        <SelectInput
                            frontline
                            value={form.data.finding_type}
                            placeholder="Choose a finding type"
                            onChange={(value) =>
                                form.setData('finding_type', value)
                            }
                            options={pickers.finding_types.map((value) => ({
                                value,
                                label: titleCase(value),
                            }))}
                        />
                    </Field>
                    <Field label="What was found?" required>
                        <Textarea
                            required
                            rows={4}
                            maxLength={2000}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData('description', event.target.value)
                            }
                        />
                    </Field>
                    <Field label="Severity" required>
                        <SelectInput
                            frontline
                            value={form.data.severity}
                            placeholder="Choose severity"
                            onChange={(value) =>
                                form.setData('severity', value)
                            }
                            options={pickers.severities.map((value) => ({
                                value,
                                label: titleCase(value),
                            }))}
                        />
                    </Field>
                    <Field label="Proposed corrective work">
                        <Textarea
                            rows={3}
                            maxLength={2000}
                            value={form.data.corrective_action}
                            onChange={(event) =>
                                form.setData(
                                    'corrective_action',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <p className="text-subtle">
                        This description records proposed work. A linked
                        corrective action has its own owner, completion and
                        verification.
                    </p>
                    <Field label="Responsible person">
                        <SelectInput
                            frontline
                            value={form.data.assigned_to || 'none'}
                            placeholder="Choose a responsible person"
                            onChange={(value) =>
                                form.setData(
                                    'assigned_to',
                                    value === 'none' ? '' : value,
                                )
                            }
                            options={[
                                { value: 'none', label: 'Unassigned' },
                                ...pickers.staff.map((person) => ({
                                    value: String(person.id),
                                    label: person.name,
                                })),
                            ]}
                        />
                    </Field>
                    <Field label="Due date">
                        <Input
                            className="frontline-tap"
                            type="date"
                            value={form.data.due_date}
                            onChange={(event) =>
                                form.setData('due_date', event.target.value)
                            }
                        />
                    </Field>
                    {command === 'edit-finding' && (
                        <Field label="Progress">
                            <SelectInput
                                frontline
                                value={form.data.status}
                                placeholder="Choose progress"
                                onChange={(value) =>
                                    form.setData('status', value)
                                }
                                options={[
                                    { value: 'open', label: 'Open' },
                                    {
                                        value: 'in_progress',
                                        label: 'In progress',
                                    },
                                ]}
                            />
                        </Field>
                    )}
                </>
            )}
            {resolving && (
                <>
                    <p className="text-sm whitespace-pre-wrap">
                        {finding?.description}
                    </p>
                    <Field label="Resolution notes" required>
                        <Textarea
                            required
                            rows={4}
                            maxLength={2000}
                            value={form.data.resolution_notes}
                            onChange={(event) =>
                                form.setData(
                                    'resolution_notes',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>
                    <fieldset className="space-y-2">
                        <legend className="mb-2 font-medium">
                            Supporting evidence from this drill
                        </legend>
                        {d.attachments.map((file) => (
                            <label
                                key={file.id}
                                className="frontline-tap flex items-start gap-3 rounded-lg border border-border p-3"
                            >
                                <Checkbox
                                    checked={form.data.resolution_evidence_ids.includes(
                                        file.id,
                                    )}
                                    onCheckedChange={(value) =>
                                        form.setData(
                                            'resolution_evidence_ids',
                                            value === true
                                                ? [
                                                      ...form.data
                                                          .resolution_evidence_ids,
                                                      file.id,
                                                  ]
                                                : form.data.resolution_evidence_ids.filter(
                                                      (id) => id !== file.id,
                                                  ),
                                        )
                                    }
                                />
                                <span className="break-words">
                                    {file.original_name}
                                </span>
                            </label>
                        ))}
                    </fieldset>
                    <p className="text-subtle">
                        This records the finding’s resolution. Linked corrective
                        work keeps its independent verification history.
                    </p>
                </>
            )}
            {(failure || Object.keys(form.errors).length > 0) && (
                <p role="alert" className="text-sm text-status-critical">
                    {failure ?? Object.values(form.errors).join(' ')}
                </p>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    type="submit"
                    className="frontline-tap"
                    disabled={form.processing}
                >
                    {form.processing
                        ? 'Saving…'
                        : DRILL_RECORD_COMMAND_LABELS[command]}
                </Button>
                <Button
                    type="button"
                    variant="outline"
                    className="frontline-tap"
                    disabled={form.processing}
                    onClick={onCancel}
                >
                    Back to drill
                </Button>
            </div>
        </form>
    );
}
