import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader } from '@/components/ui/card';
import {
    Command,
    CommandInput,
    CommandItem,
    CommandList,
} from '@/components/ui/command';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Field } from '@/components/wizard/primitives';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatDateTime } from '@/lib/datetime';
import { router, useForm } from '@inertiajs/react';
import { Check, UserRound } from 'lucide-react';
import { useEffect, useState, type FormEvent } from 'react';

export type IncidentOwnership = {
    revision: number;
    state: 'unassigned' | 'assigned' | 'replacement_needed' | 'historical';
    owner: { id: number; name: string } | null;
    last_owner_name?: string | null;
    can_assign: boolean;
    can_retry_delivery: boolean;
    history?: Array<{
        id: number;
        revision: number;
        request_uuid?: string;
        owner_user_id?: number;
        previous_owner: string | null;
        owner: string;
        changed_by: string;
        changed_at: string;
        reason: string | null;
        source: string;
        deliveries: Array<{
            id: string;
            role: 'incoming' | 'outgoing';
            status: 'pending' | 'delivered' | 'failed' | 'skipped';
        }>;
    }>;
};

export function incidentOwnerLabel(ownership: IncidentOwnership): string {
    if (ownership.state === 'replacement_needed')
        return 'Owner needs replacement';
    if (ownership.state === 'unassigned') return 'Needs an owner';
    return (
        ownership.owner?.name ??
        ownership.last_owner_name ??
        'Owner not recorded'
    );
}

export function IncidentOwnershipPanel({
    incidentId,
    reference,
    ownership,
}: {
    incidentId: number;
    reference: string;
    ownership: IncidentOwnership;
}) {
    const [open, setOpen] = useState(false);
    const [retrying, setRetrying] = useState<string | null>(null);
    const history = ownership.history ?? [];
    const latest = history[0];
    return (
        <>
            <Card>
                <CardHeader>
                    <h2 className="text-section-title flex items-center gap-2">
                        <UserRound
                            aria-hidden
                            className="size-4 text-primary"
                        />
                        Incident owner
                    </h2>
                </CardHeader>
                <CardContent className="space-y-3">
                    <p className="text-subtle">
                        {incidentOwnerLabel(ownership)}
                    </p>
                    {ownership.state === 'replacement_needed' ? (
                        <p className="text-subtle text-muted-foreground">
                            Previous owner:{' '}
                            {ownership.owner?.name ??
                                ownership.last_owner_name ??
                                'Not recorded'}
                            . Their current access or eligibility no longer
                            supports this assignment.
                        </p>
                    ) : null}
                    {ownership.state === 'unassigned' ? (
                        <p className="text-subtle text-muted-foreground">
                            An authorized coordinator needs to assign an
                            eligible person to follow this incident through.
                        </p>
                    ) : null}
                    {ownership.state === 'historical' ? (
                        <p className="text-subtle text-muted-foreground">
                            Historical ownership. Reopen the incident through
                            its normal controls before reassigning it.
                        </p>
                    ) : null}
                    {ownership.can_assign ? (
                        <Button
                            className="frontline-tap frontline-focus w-full"
                            variant="outline"
                            onClick={() => setOpen(true)}
                        >
                            {ownership.revision > 0
                                ? 'Reassign owner'
                                : 'Assign owner'}
                        </Button>
                    ) : null}
                    {latest ? (
                        <div className="text-subtle space-y-2">
                            <p className="text-muted-foreground">
                                Assigned by {latest.changed_by} ·{' '}
                                {formatDateTime(latest.changed_at)}
                            </p>
                            {latest.deliveries.map((delivery) => (
                                <div key={delivery.id} className="space-y-1">
                                    <p>
                                        {delivery.role === 'incoming'
                                            ? 'New owner'
                                            : 'Previous owner'}{' '}
                                        notification:{' '}
                                        {delivery.status === 'delivered'
                                            ? 'Delivered in the app'
                                            : delivery.status === 'skipped'
                                              ? 'No longer applicable or permitted'
                                              : delivery.status === 'failed'
                                                ? 'Delivery failed'
                                                : 'Pending delivery'}
                                    </p>
                                    {delivery.status === 'failed' &&
                                    ownership.can_retry_delivery ? (
                                        <Button
                                            variant="outline"
                                            className="frontline-tap frontline-focus"
                                            disabled={retrying !== null}
                                            onClick={() => {
                                                setRetrying(delivery.id);
                                                router.post(
                                                    `/incidents/${incidentId}/owner-deliveries/${delivery.id}/retry`,
                                                    {},
                                                    {
                                                        preserveScroll: true,
                                                        onFinish: () =>
                                                            setRetrying(null),
                                                    },
                                                );
                                            }}
                                        >
                                            Retry notification
                                        </Button>
                                    ) : null}
                                </div>
                            ))}
                        </div>
                    ) : null}
                    {history.length ? (
                        <details>
                            <summary className="frontline-tap frontline-focus text-subtle flex cursor-pointer items-center rounded-md">
                                Ownership history ({history.length})
                            </summary>
                            <ol className="mt-2 space-y-3">
                                {history.map((change) => (
                                    <li
                                        key={change.id}
                                        className="text-subtle space-y-1 border-l-2 border-border pl-3"
                                    >
                                        <p className="font-medium">
                                            {change.previous_owner
                                                ? `${change.previous_owner} → ${change.owner}`
                                                : `Assigned to ${change.owner}`}
                                        </p>
                                        <p className="text-muted-foreground">
                                            {change.changed_by} ·{' '}
                                            {formatDateTime(change.changed_at)}
                                        </p>
                                        {change.reason ? (
                                            <p className="whitespace-pre-wrap">
                                                {change.reason}
                                            </p>
                                        ) : null}
                                    </li>
                                ))}
                            </ol>
                        </details>
                    ) : null}
                </CardContent>
            </Card>
            {open ? (
                <AssignIncidentOwner
                    incidentId={incidentId}
                    reference={reference}
                    ownership={ownership}
                    onClose={() => setOpen(false)}
                />
            ) : null}
        </>
    );
}

function readOwnerOptions(
    value: unknown,
): Array<{ id: number; name: string }> | null {
    if (typeof value !== 'object' || value === null || !('owners' in value)) {
        return null;
    }
    if (!Array.isArray(value.owners)) return null;
    const entries: unknown[] = value.owners;
    const owners: Array<{ id: number; name: string }> = [];
    const ids = new Set<number>();
    for (const owner of entries) {
        if (
            typeof owner !== 'object' ||
            owner === null ||
            !('id' in owner) ||
            !('name' in owner) ||
            typeof owner.id !== 'number' ||
            !Number.isSafeInteger(owner.id) ||
            owner.id <= 0 ||
            ids.has(owner.id) ||
            typeof owner.name !== 'string' ||
            !owner.name.trim()
        ) {
            return null;
        }
        ids.add(owner.id);
        owners.push({ id: owner.id, name: owner.name });
    }
    return owners;
}

function AssignIncidentOwner({
    incidentId,
    reference,
    ownership,
    onClose,
}: {
    incidentId: number;
    reference: string;
    ownership: IncidentOwnership;
    onClose: () => void;
}) {
    const [requestUuid] = useState(() => crypto.randomUUID());
    const form = useForm({
        owner_user_id: '',
        expected_revision: ownership.revision,
        request_uuid: requestUuid,
        reason: '',
    });
    const [search, setSearch] = useState('');
    const [options, setOptions] = useState<Array<{ id: number; name: string }>>(
        [],
    );
    const [selected, setSelected] = useState<{
        id: number;
        name: string;
    } | null>(null);
    const [loading, setLoading] = useState(true);
    const [lookupError, setLookupError] = useState(false);
    const [retry, setRetry] = useState(0);
    const [confirmationFailure, setConfirmationFailure] = useState(false);
    const stale = form.data.expected_revision !== ownership.revision;
    const guard = useSettingsLeaveConfirmation(
        form.isDirty || form.processing,
        'Discard this ownership change?',
        'Your selected owner and unsaved reason will be discarded. Cancel to keep editing.',
    );
    const requestClose = () => {
        if (!form.processing) guard.request(onClose);
    };
    useEffect(() => {
        if (!ownership.can_assign) {
            setOptions([]);
            setSelected(null);
            setLoading(false);
            setLookupError(false);
            return;
        }
        const controller = new AbortController();
        setLoading(true);
        setLookupError(false);
        setOptions([]);
        const timer = window.setTimeout(() => {
            fetch(
                `/incidents/${incidentId}/owners/options?${new URLSearchParams({ q: search })}`,
                {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    signal: controller.signal,
                },
            )
                .then(async (response) => {
                    if (!response.ok) throw new Error('Owner lookup failed');
                    const owners = readOwnerOptions(await response.json());
                    if (!owners) throw new Error('Invalid owner lookup');
                    if (!controller.signal.aborted) setOptions(owners);
                })
                .catch(() => {
                    if (!controller.signal.aborted) setLookupError(true);
                })
                .finally(() => {
                    if (!controller.signal.aborted) setLoading(false);
                });
        }, 200);
        return () => {
            window.clearTimeout(timer);
            controller.abort();
        };
    }, [incidentId, search, retry, ownership.can_assign]);
    const change = (key: 'owner_user_id' | 'reason', value: string) => {
        if (form.data[key] === value) return;
        form.setData(key, value);
        form.setData('request_uuid', crypto.randomUUID());
        setConfirmationFailure(false);
    };
    const submit = (event: FormEvent) => {
        event.preventDefault();
        if (
            form.processing ||
            loading ||
            lookupError ||
            stale ||
            !ownership.can_assign ||
            !selected ||
            (ownership.revision > 0 && !form.data.reason.trim())
        )
            return;
        const submitted = { ...form.data };
        setConfirmationFailure(false);
        form.post(`/incidents/${incidentId}/owner`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const detail = (
                    page.props as {
                        detail?: { id?: number; ownership?: IncidentOwnership };
                    }
                ).detail;
                const returnedHistory = detail?.ownership?.history;
                const accepted =
                    detail?.id === incidentId &&
                    Array.isArray(returnedHistory) &&
                    returnedHistory.some(
                        (entry) =>
                            entry.request_uuid === submitted.request_uuid &&
                            entry.revision ===
                                submitted.expected_revision + 1 &&
                            entry.owner_user_id ===
                                Number(submitted.owner_user_id),
                    );
                if (accepted) onClose();
                else setConfirmationFailure(true);
            },
        });
    };
    return (
        <>
            <Dialog
                open
                onOpenChange={(open) => {
                    if (!open) requestClose();
                }}
            >
                <DialogContent className="frontline-dialog max-h-[90dvh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {ownership.revision > 0
                                ? 'Reassign incident owner'
                                : 'Assign incident owner'}
                        </DialogTitle>
                        <DialogDescription>
                            {reference} ·{' '}
                            {ownership.owner
                                ? `Current owner: ${ownership.owner.name}.`
                                : 'No eligible owner is currently assigned.'}{' '}
                            Response, investigation and action owners stay
                            separate.
                        </DialogDescription>
                    </DialogHeader>
                    <form onSubmit={submit} className="space-y-4">
                        {!ownership.can_assign && (
                            <p
                                role="alert"
                                className="text-subtle text-status-warning"
                            >
                                You cannot change the incident owner with your
                                current access. Your reason has been kept.
                            </p>
                        )}
                        {confirmationFailure && (
                            <p
                                role="alert"
                                className="text-subtle text-status-warning"
                            >
                                The owner save could not be confirmed. Your
                                entries have been kept. Check the incident
                                ownership history before trying again.
                            </p>
                        )}
                        {stale ? (
                            <div role="alert" className="text-subtle space-y-2">
                                <p>
                                    The incident owner has changed. Review the
                                    current owner above, then choose again. Your
                                    reason has been kept.
                                </p>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className="frontline-hit frontline-focus"
                                    disabled={
                                        form.processing || !ownership.can_assign
                                    }
                                    onClick={() => {
                                        form.setData(
                                            'expected_revision',
                                            ownership.revision,
                                        );
                                        form.setData('owner_user_id', '');
                                        form.setData(
                                            'request_uuid',
                                            crypto.randomUUID(),
                                        );
                                        form.clearErrors();
                                        setSelected(null);
                                        setRetry((value) => value + 1);
                                    }}
                                >
                                    Use current owner version
                                </Button>
                            </div>
                        ) : null}
                        {ownership.can_assign && (
                            <Command
                                shouldFilter={false}
                                label="Search eligible incident owners"
                                className="border border-border"
                            >
                                <CommandInput
                                    placeholder="Search eligible staff…"
                                    value={search}
                                    onValueChange={setSearch}
                                    disabled={form.processing}
                                />
                                <CommandList label="Eligible incident owners">
                                    {loading ? (
                                        <p
                                            role="status"
                                            className="text-subtle p-3"
                                        >
                                            Checking eligible staff…
                                        </p>
                                    ) : lookupError ? (
                                        <div
                                            role="alert"
                                            className="text-subtle space-y-2 p-3"
                                        >
                                            <p>
                                                Eligible staff could not be
                                                loaded. Your entries have been
                                                kept.
                                            </p>
                                            <Button
                                                type="button"
                                                variant="outline"
                                                className="frontline-hit frontline-focus"
                                                disabled={form.processing}
                                                onClick={() =>
                                                    setRetry(
                                                        (value) => value + 1,
                                                    )
                                                }
                                            >
                                                Retry staff lookup
                                            </Button>
                                        </div>
                                    ) : options.length === 0 ? (
                                        <p className="text-subtle p-3 text-muted-foreground">
                                            No eligible staff match this search.
                                        </p>
                                    ) : (
                                        options.map((owner) => (
                                            <CommandItem
                                                key={owner.id}
                                                value={String(owner.id)}
                                                className="frontline-tap frontline-focus"
                                                disabled={form.processing}
                                                onSelect={() => {
                                                    setSelected(owner);
                                                    change(
                                                        'owner_user_id',
                                                        String(owner.id),
                                                    );
                                                }}
                                            >
                                                {owner.name}
                                                {selected?.id === owner.id ? (
                                                    <Check
                                                        className="ml-auto size-4"
                                                        aria-hidden="true"
                                                    />
                                                ) : null}
                                            </CommandItem>
                                        ))
                                    )}
                                </CommandList>
                            </Command>
                        )}
                        {ownership.can_assign && selected ? (
                            <p className="text-subtle">
                                Selected owner: <strong>{selected.name}</strong>
                            </p>
                        ) : null}
                        {form.errors.owner_user_id ? (
                            <p
                                role="alert"
                                className="text-subtle text-destructive"
                            >
                                {form.errors.owner_user_id}
                            </p>
                        ) : null}
                        <Field
                            label="Reason for this ownership change"
                            required={ownership.revision > 0}
                            error={form.errors.reason}
                        >
                            <Textarea
                                value={form.data.reason}
                                disabled={
                                    form.processing || !ownership.can_assign
                                }
                                onChange={(event) =>
                                    change('reason', event.target.value)
                                }
                                maxLength={4000}
                                rows={3}
                            />
                        </Field>
                        {form.errors.expected_revision ||
                        form.errors.request_uuid ? (
                            <p
                                role="alert"
                                className="text-subtle text-destructive"
                            >
                                {form.errors.expected_revision ??
                                    form.errors.request_uuid}
                            </p>
                        ) : null}
                        <div className="flex flex-wrap justify-end gap-2">
                            <Button
                                type="button"
                                variant="outline"
                                className="frontline-tap frontline-focus"
                                disabled={form.processing}
                                onClick={requestClose}
                            >
                                Cancel
                            </Button>
                            <Button
                                type="submit"
                                className="frontline-tap frontline-focus"
                                disabled={
                                    form.processing ||
                                    loading ||
                                    lookupError ||
                                    stale ||
                                    !ownership.can_assign ||
                                    !selected ||
                                    (ownership.revision > 0 &&
                                        !form.data.reason.trim())
                                }
                            >
                                {form.processing
                                    ? 'Saving owner…'
                                    : 'Save owner'}
                            </Button>
                        </div>
                    </form>
                </DialogContent>
            </Dialog>
            {guard.confirmation}
        </>
    );
}
