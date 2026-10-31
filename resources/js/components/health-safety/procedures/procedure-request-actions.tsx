import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import { PROCEDURE_REGISTER } from '@/lib/health-safety/procedure-navigation';
import { useEffect, useState } from 'react';
import type { ProcedureRequest, ProcedureWorkspaceDetail } from './types';

type Action = keyof ProcedureRequest['can'];
const LABELS: Record<Action, string> = {
    start: 'Start requested review',
    resolve: 'Record review outcome',
    decline: 'Decline request',
    cancel: 'Cancel request',
    reassign: 'Change responsible person',
};
export function ProcedureRequestActions({
    request,
    detail,
    requestDiscard,
}: {
    request: ProcedureRequest;
    detail: ProcedureWorkspaceDetail;
    requestDiscard: (action: () => void) => void;
}) {
    const [action, setAction] = useState<Action | null>(null);
    const allowed = (Object.keys(LABELS) as Action[]).filter(
        (key) => request.can[key],
    );
    if (!allowed.length) return null;
    return action ? (
        <RequestCommand
            key={action}
            action={action}
            request={request}
            detail={detail}
            onSaved={() => setAction(null)}
            onCancel={() => requestDiscard(() => setAction(null))}
        />
    ) : (
        <div className="flex flex-wrap gap-2">
            {allowed.map((key) => (
                <Button
                    className="frontline-tap"
                    key={key}
                    variant="outline"
                    onClick={() => setAction(key)}
                >
                    {LABELS[key]}
                </Button>
            ))}
        </div>
    );
}
function RequestCommand({
    action,
    request,
    detail,
    onSaved,
    onCancel,
}: {
    action: Action;
    request: ProcedureRequest;
    detail: ProcedureWorkspaceDetail;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const form = useSafetyForm({
        outcome: '',
        decision_note: '',
        assigned_to_user_id: '',
        idempotency_key: crypto.randomUUID(),
    });
    const [executors, setExecutors] = useState<{ id: number; name: string }[]>(
        [],
    );
    const [failure, setFailure] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    useEffect(() => {
        if (action !== 'reassign') return;
        const controller = new AbortController();
        setLoading(true);
        fetch(
            `/health-safety/events/${request.source.id}/procedure-review-options?procedure_id=${detail.id}&request_id=${request.id}`,
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'Eligible people could not be loaded. Your access may have changed.',
                    );
                return response.json();
            })
            .then((data) => setExecutors(data.executors ?? []))
            .catch((error) => {
                if (!controller.signal.aborted) setFailure(error.message);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [action, detail.id, request.source.id, request.id]);
    const outcomeVersion =
        form.data.outcome === 'no_change'
            ? request.requested_version.id
            : detail.publication?.version_id;
    return (
        <form
            className="space-y-4 border-t border-border pt-4"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.processing || loading || !request.can[action]) return;
                setFailure(null);
                form.transform((data) => ({
                    expected_version: request.lock_version,
                    idempotency_key: data.idempotency_key,
                    ...(action === 'resolve'
                        ? {
                              outcome: data.outcome,
                              result_version_id: outcomeVersion ?? null,
                              decision_note: data.decision_note,
                          }
                        : {}),
                    ...(['decline', 'cancel'].includes(action)
                        ? { decision_note: data.decision_note }
                        : {}),
                    ...(action === 'reassign'
                        ? {
                              assigned_to_user_id: data.assigned_to_user_id
                                  ? Number(data.assigned_to_user_id)
                                  : null,
                          }
                        : {}),
                }));
                let responded = false;
                form.post(
                    `${PROCEDURE_REGISTER}/${detail.id}/review-requests/${request.id}/${action}`,
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
                            onSaved();
                        },
                        onError: () => {
                            responded = true;
                        },
                        onCancel: () => {
                            responded = true;
                            setFailure(
                                'Saving was interrupted. Your entries are still here. Check the request before trying again.',
                            );
                        },
                        onFinish: () => {
                            if (!responded)
                                setFailure(
                                    'The save could not be confirmed. Your entries are still here. Check the request before trying again.',
                                );
                        },
                    },
                );
            }}
        >
            <h4 className="font-semibold">{LABELS[action]}</h4>
            {action === 'resolve' && (
                <>
                    <Field label="Review outcome" required>
                        <SelectInput
                            frontline
                            value={form.data.outcome}
                            onChange={(value) => form.setData('outcome', value)}
                            placeholder="Choose an outcome"
                            options={[
                                {
                                    value: 'no_change',
                                    label: 'Reviewed without changes',
                                },
                                {
                                    value: 'revised',
                                    label: 'Revised version published',
                                },
                            ]}
                        />
                    </Field>
                    <p className="text-subtle">
                        {form.data.outcome === 'revised'
                            ? `The outcome will link the current published version${detail.publication ? ` (${detail.publication.version})` : ''}. A working draft cannot complete this request.`
                            : 'Confirm the procedure review in your outcome notes. The originating corrective action still needs its own completion and verification.'}
                    </p>
                </>
            )}
            {['resolve', 'decline', 'cancel'].includes(action) && (
                <Field label="Decision notes" required>
                    <Textarea
                        required
                        maxLength={5000}
                        rows={3}
                        value={form.data.decision_note}
                        onChange={(event) =>
                            form.setData('decision_note', event.target.value)
                        }
                    />
                </Field>
            )}
            {action === 'reassign' && (
                <Field label="Responsible person" required>
                    <SelectInput
                        frontline
                        value={form.data.assigned_to_user_id}
                        onChange={(value) =>
                            form.setData('assigned_to_user_id', value)
                        }
                        placeholder={
                            loading
                                ? 'Loading eligible people…'
                                : 'Choose a responsible person'
                        }
                        options={executors.map((person) => ({
                            value: String(person.id),
                            label: person.name,
                        }))}
                    />
                </Field>
            )}
            {(failure || Object.keys(form.errors).length > 0) && (
                <p role="alert" className="text-sm text-status-critical">
                    {failure ?? Object.values(form.errors).join(' ')}
                </p>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap"
                    disabled={form.processing || loading}
                    type="submit"
                >
                    {form.processing ? 'Saving…' : LABELS[action]}
                </Button>
                <Button
                    className="frontline-tap"
                    variant="outline"
                    type="button"
                    disabled={form.processing}
                    onClick={onCancel}
                >
                    Cancel
                </Button>
            </div>
        </form>
    );
}
