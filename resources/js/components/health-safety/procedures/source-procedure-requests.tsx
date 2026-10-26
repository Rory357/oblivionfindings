import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import {
    useSafetyDraftState,
    useSafetyForm,
} from '@/hooks/use-safety-workspace-drafts';
import { formatDateTimeLong } from '@/lib/datetime';
import {
    procedureConnectionHref,
    procedureHref,
    procedureReturn,
} from '@/lib/health-safety/procedure-navigation';
import { Link, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';

type Options = {
    can: { create_request: boolean };
    procedures: {
        id: number;
        title: string;
        reference_number: string | null;
        published_version_id: number;
        version: number;
        provenance: string;
    }[];
    executors: { id: number; name: string }[];
    actions: {
        id: number;
        reference_number: string | null;
        title: string;
        status: string;
    }[];
    requests: {
        id: number;
        status: string;
        requested_at: string | null;
        procedure: { id: number; title: string; href: string };
        executor: { id: number; name: string } | null;
        result_version: { id: number; version: number; href: string } | null;
        outcome: 'no_change' | 'revised' | null;
    }[];
};
export function SourceProcedureRequests({
    eventId,
    requestDiscard,
}: {
    eventId: number;
    requestDiscard: (action: () => void) => void;
}) {
    const { url } = usePage();
    const returnTo = procedureReturn(url);
    const [creating, setCreating] = useState(false);
    const [selected, setSelected] = useState('');
    const [options, setOptions] = useState<Options | null>(null);
    const [loading, setLoading] = useState(true);
    const [failure, setFailure] = useState<string | null>(null);
    const [refresh, setRefresh] = useState(0);
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setFailure(null);
        const query = new URLSearchParams({ return_to: returnTo });
        if (selected) query.set('procedure_id', selected);
        fetch(
            `/health-safety/events/${eventId}/procedure-review-options?${query}`,
            {
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        )
            .then(async (response) => {
                if (!response.ok)
                    throw new Error(
                        'Procedure connections could not be loaded. Your access may have changed.',
                    );
                return response.json() as Promise<Options>;
            })
            .then((data) => setOptions(data))
            .catch((error) => {
                if (!controller.signal.aborted) setFailure(error.message);
            })
            .finally(() => {
                if (!controller.signal.aborted) setLoading(false);
            });
        return () => controller.abort();
    }, [eventId, selected, refresh, returnTo]);
    return (
        <section className="mt-6 space-y-4 rounded-xl border border-border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="text-section-title">
                        Procedure review requests
                    </h3>
                    <p className="text-subtle">
                        Ask a named person to review instructions. The resulting
                        procedure version stays linked to this safety review.
                    </p>
                </div>
                {options?.can.create_request && !creating && (
                    <Button
                        className="frontline-tap"
                        variant="outline"
                        onClick={() => setCreating(true)}
                    >
                        Request a procedure review
                    </Button>
                )}
            </div>
            {failure && (
                <div role="alert" className="space-y-2">
                    <p className="text-sm text-status-critical">{failure}</p>
                    <Button
                        className="frontline-tap"
                        variant="outline"
                        onClick={() => setRefresh((value) => value + 1)}
                    >
                        Try again
                    </Button>
                </div>
            )}
            {loading && !options && (
                <p className="text-subtle" role="status">
                    Loading procedure connections…
                </p>
            )}
            {creating && options?.can.create_request && (
                <SourceRequestForm
                    eventId={eventId}
                    options={options}
                    selected={selected}
                    onSelect={setSelected}
                    loading={loading}
                    returnTo={returnTo}
                    onSaved={() => {
                        setCreating(false);
                        setSelected('');
                        setRefresh((value) => value + 1);
                    }}
                    onCancel={() =>
                        requestDiscard(() => {
                            setCreating(false);
                            setSelected('');
                        })
                    }
                />
            )}
            {options?.requests.length ? (
                <ul className="space-y-3">
                    {options.requests.map((item) => (
                        <li
                            key={item.id}
                            className="space-y-2 rounded-lg border border-border p-3"
                        >
                            <Link
                                className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                                href={procedureConnectionHref(
                                    item.procedure.id,
                                    item.procedure.href,
                                    returnTo,
                                )}
                            >
                                {item.procedure.title ||
                                    'Open requested procedure'}
                            </Link>
                            <div>
                                <StatusBadge status={item.status} />
                            </div>
                            <p className="text-subtle">
                                Responsible person:{' '}
                                {item.executor?.name ?? 'Unassigned'}
                                {item.requested_at
                                    ? ` · Requested ${formatDateTimeLong(item.requested_at)}`
                                    : ''}
                            </p>
                            {item.result_version && (
                                <Link
                                    className="frontline-tap frontline-focus inline-flex items-center text-primary underline"
                                    href={procedureHref(item.procedure.id, {
                                        version: item.result_version.id,
                                        section: 'connections',
                                        request: item.id,
                                        returnTo,
                                    })}
                                >
                                    Read resulting version{' '}
                                    {item.result_version.version}
                                </Link>
                            )}
                            {item.outcome && (
                                <p className="text-sm">
                                    {item.outcome === 'revised'
                                        ? 'Revised instructions published.'
                                        : 'Reviewed without changes.'}{' '}
                                    The linked corrective action has its own
                                    completion checks.
                                </p>
                            )}
                        </li>
                    ))}
                </ul>
            ) : (
                options &&
                !loading && (
                    <p className="text-subtle">
                        No procedure review requests are available here.
                    </p>
                )
            )}
        </section>
    );
}
function SourceRequestForm({
    eventId,
    options,
    selected,
    onSelect,
    loading,
    returnTo,
    onSaved,
    onCancel,
}: {
    eventId: number;
    options: Options;
    selected: string;
    onSelect: (value: string) => void;
    loading: boolean;
    returnTo: string;
    onSaved: () => void;
    onCancel: () => void;
}) {
    const form = useSafetyForm({
        assigned_to_user_id: '',
        hs_corrective_action_id: '',
        note: '',
        idempotency_key: crypto.randomUUID(),
    });
    useSafetyDraftState({ dirty: selected !== '', busy: loading });
    const [failure, setFailure] = useState<string | null>(null);
    const procedure = options.procedures.find(
        (item) => String(item.id) === selected,
    );
    return (
        <form
            className="space-y-4 border-y border-border py-4"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.processing || loading || !procedure) return;
                setFailure(null);
                form.transform((data) => ({
                    procedure_id: procedure.id,
                    requested_version_id: procedure.published_version_id,
                    assigned_to_user_id: data.assigned_to_user_id
                        ? Number(data.assigned_to_user_id)
                        : null,
                    hs_corrective_action_id: data.hs_corrective_action_id
                        ? Number(data.hs_corrective_action_id)
                        : null,
                    note: data.note,
                    idempotency_key: data.idempotency_key,
                    return_to: returnTo,
                }));
                let responded = false;
                form.post(
                    `/health-safety/events/${eventId}/procedure-review-requests`,
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
                                'Saving was interrupted. Your entries are still here. Check connected work before trying again.',
                            );
                        },
                        onFinish: () => {
                            if (!responded)
                                setFailure(
                                    'The save could not be confirmed. Your entries are still here. Check connected work before trying again.',
                                );
                        },
                    },
                );
            }}
        >
            <Field label="Procedure" required>
                <SelectInput
                    frontline
                    value={selected}
                    onChange={(value) => {
                        form.setData('assigned_to_user_id', '');
                        onSelect(value);
                    }}
                    placeholder="Choose published instructions"
                    options={options.procedures.map((item) => ({
                        value: String(item.id),
                        label: `${item.reference_number ? item.reference_number + ' · ' : ''}${item.title}`,
                    }))}
                />
            </Field>
            {procedure && (
                <p className="text-subtle">
                    Requesting a review of version {procedure.version}
                    {procedure.provenance === 'legacy_current_capture'
                        ? ' · Captured legacy instructions'
                        : ''}
                    .
                </p>
            )}
            <Field label="Responsible person" required>
                <SelectInput
                    frontline
                    value={form.data.assigned_to_user_id}
                    onChange={(value) =>
                        form.setData('assigned_to_user_id', value)
                    }
                    placeholder={
                        loading ? 'Loading eligible people…' : 'Choose a person'
                    }
                    options={options.executors.map((person) => ({
                        value: String(person.id),
                        label: person.name,
                    }))}
                />
            </Field>
            <Field label="Linked corrective action">
                <SelectInput
                    frontline
                    value={form.data.hs_corrective_action_id || 'none'}
                    onChange={(value) =>
                        form.setData(
                            'hs_corrective_action_id',
                            value === 'none' ? '' : value,
                        )
                    }
                    placeholder="Optional corrective action"
                    options={[
                        { value: 'none', label: 'No linked action' },
                        ...options.actions.map((item) => ({
                            value: String(item.id),
                            label: `${item.reference_number ? item.reference_number + ' · ' : ''}${item.title}`,
                        })),
                    ]}
                />
            </Field>
            <Field label="What needs reviewing?" required>
                <Textarea
                    rows={4}
                    required
                    maxLength={5000}
                    value={form.data.note}
                    onChange={(event) =>
                        form.setData('note', event.target.value)
                    }
                />
            </Field>
            {(failure || Object.keys(form.errors).length > 0) && (
                <p role="alert" className="text-sm text-status-critical">
                    {failure ?? Object.values(form.errors).join(' ')}
                </p>
            )}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap"
                    type="submit"
                    disabled={form.processing || loading || !procedure}
                >
                    {form.processing ? 'Saving…' : 'Send review request'}
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
