import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard } from '@/components/wizard/primitives';
import { useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import {
    PROCEDURE_REGISTER,
    type ProcedureCommand as Command,
} from '@/lib/health-safety/procedure-navigation';
import { Info } from 'lucide-react';
import { useState } from 'react';
import type { ProcedureWorkspaceDetail } from './types';

export const PROCEDURE_COMMAND_LABELS: Record<Command, string> = {
    'submit-review': 'Submit for review',
    approve: 'Approve & publish',
    'request-changes': 'Request changes',
    'record-review': 'Start a review',
    archive: 'Archive procedure',
    restore: 'Restore as draft',
    acknowledge: 'Acknowledge this version',
};
export function procedureCommandAllowed(
    d: ProcedureWorkspaceDetail,
    command: Command,
) {
    const key = {
        'submit-review': 'submit_review',
        approve: 'approve',
        'request-changes': 'request_changes',
        'record-review': 'record_review',
        archive: 'archive',
        restore: 'restore',
        acknowledge: 'acknowledge',
    } as const;
    return d.can[key[command]] === true;
}
export function ProcedureCommand({
    detail: d,
    command,
    onCancel,
    onSaved,
}: {
    detail: ProcedureWorkspaceDetail;
    command: Command;
    onCancel: () => void;
    onSaved: () => void;
}) {
    const form = useSafetyForm({
        note: '',
        review_date: d.review_date ?? '',
        confirmed: false,
    });
    const [failure, setFailure] = useState<string | null>(null);
    const legacy = d.viewed_revision.legacy;
    const descriptions: Record<Command, string> = {
        'submit-review':
            'Send this working revision for approval. Staff continue to use the last published version.',
        approve:
            'Publish this exact revision and its documents for its selected roles and sites. Staff will acknowledge this published version separately.',
        'request-changes':
            'Return the working revision for changes and record what is needed. The last published version remains available.',
        'record-review':
            'Record why a review is needed and open a working revision for review. This does not renew or replace the version staff currently use.',
        archive:
            'Remove this procedure from current instructions. Its version history and acknowledgement receipts remain available to authorised readers.',
        restore:
            'Bring the procedure back as a draft. It needs review and explicit approval before it is published again.',
        acknowledge: legacy
            ? 'Record that you have read these captured current instructions. Their original approval history is incomplete. This does not confirm training or competence.'
            : 'Record that you have read this published version. A later publication requires a new acknowledgement. This does not confirm training or competence.',
    };
    const noteRequired =
        command === 'record-review' || command === 'request-changes';
    const hasNote = noteRequired || command === 'approve';
    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.processing || !procedureCommandAllowed(d, command))
                    return;
                setFailure(null);
                if (command === 'acknowledge' && !form.data.confirmed) {
                    setFailure('Confirm that you have read this version.');
                    return;
                }
                form.transform((data) =>
                    command === 'acknowledge'
                        ? { published_version_id: d.publication?.version_id }
                        : {
                              expected_version: d.expected_version,
                              expected_lock_version: d.lock_version,
                              ...(command === 'approve'
                                  ? {
                                        approval_note: data.note || null,
                                        review_date: data.review_date || null,
                                    }
                                  : {}),
                              ...(command === 'request-changes'
                                  ? { note: data.note }
                                  : {}),
                              ...(command === 'record-review'
                                  ? {
                                        review_note: data.note,
                                        review_date: data.review_date || null,
                                    }
                                  : {}),
                          },
                );
                let responded = false;
                form.post(
                    `${PROCEDURE_REGISTER}/${d.id}/${command === 'submit-review' ? 'submit-for-review' : command}`,
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
                            form.reset();
                            form.clearErrors();
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
                                    'The save could not be confirmed. Check the record before trying again.',
                                );
                        },
                    },
                );
            }}
        >
            <h2 className="text-section-title">
                {PROCEDURE_COMMAND_LABELS[command]}
            </h2>
            <InfoCard icon={Info}>{descriptions[command]}</InfoCard>
            {hasNote && (
                <Field
                    label={
                        command === 'request-changes'
                            ? 'Changes needed'
                            : command === 'record-review'
                              ? 'Review notes'
                              : 'Approval note'
                    }
                    required={noteRequired}
                    error={form.errors.note}
                >
                    <Textarea
                        value={form.data.note}
                        onChange={(e) => form.setData('note', e.target.value)}
                        required={noteRequired}
                        maxLength={5000}
                        rows={4}
                    />
                </Field>
            )}
            {(command === 'approve' || command === 'record-review') && (
                <Field label="Next review date" error={form.errors.review_date}>
                    <Input
                        className="frontline-tap"
                        type="date"
                        value={form.data.review_date}
                        onChange={(e) =>
                            form.setData('review_date', e.target.value)
                        }
                    />
                </Field>
            )}
            {command === 'acknowledge' && (
                <label className="frontline-tap flex cursor-pointer items-start gap-3 rounded-lg border border-border p-3">
                    <input
                        className="mt-1 size-5 accent-primary"
                        type="checkbox"
                        checked={form.data.confirmed}
                        onChange={(e) =>
                            form.setData('confirmed', e.target.checked)
                        }
                    />
                    <span>
                        {legacy
                            ? 'I have read these captured instructions'
                            : 'I have read this published procedure'}{' '}
                        (version {d.viewed_revision.version}).
                    </span>
                </label>
            )}
            {(failure || Object.keys(form.errors).length > 0) && (
                <div
                    role="alert"
                    className="space-y-1 text-sm text-status-critical"
                >
                    {failure && <p>{failure}</p>}
                    {Object.entries(form.errors).map(([key, value]) => (
                        <p key={key}>{value}</p>
                    ))}
                </div>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap"
                    type="submit"
                    disabled={
                        form.processing || !procedureCommandAllowed(d, command)
                    }
                >
                    {form.processing
                        ? 'Saving…'
                        : PROCEDURE_COMMAND_LABELS[command]}
                </Button>
                <Button
                    className="frontline-tap"
                    type="button"
                    variant="outline"
                    disabled={form.processing}
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
