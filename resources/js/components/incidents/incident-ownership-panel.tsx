import { QuietLine } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { StatusBadge } from '@/components/ui/status-badge';
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
import { Check } from 'lucide-react';
import { useEffect, useId, useState, type FormEvent } from 'react';

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
        return 'Owner needs replacing';
    if (ownership.state === 'unassigned') return 'Needs an owner';
    return (
        ownership.owner?.name ??
        ownership.last_owner_name ??
        'Owner not recorded'
    );
}

/** The owner-assignment entry label for the current ownership state. */
export function incidentAssignLabel(ownership: IncidentOwnership): string {
    if (ownership.state === 'unassigned') return 'Assign someone';
    if (ownership.state === 'replacement_needed') return 'Choose a new owner';
    return 'Change owner';
}

const DELIVERY_TEXT = {
    delivered: 'Delivered in the app',
    skipped: 'No longer applicable or permitted',
    failed: 'Delivery failed',
    pending: 'Pending delivery',
} as const;

/**
 * The incident owner (drawing pg-hsn-2 side column): who owns it, who assigned
 * them and whether they were told, the owner history on demand, and the one
 * assign dialog. The page can open that dialog from its header through
 * `assignOpen` / `onAssignOpenChange`; `showAssign={false}` drops the in-card
 * button when the header already carries it.
 */
export function IncidentOwnershipPanel({
    incidentId,
    reference,
    ownership,
    assignOpen,
    onAssignOpenChange,
    showAssign = true,
}: {
    incidentId: number;
    reference: string;
    ownership: IncidentOwnership;
    assignOpen?: boolean;
    onAssignOpenChange?: (open: boolean) => void;
    showAssign?: boolean;
}) {
    const [ownOpen, setOwnOpen] = useState(false);
    const open = assignOpen ?? ownOpen;
    const setOpen = (next: boolean) => {
        if (onAssignOpenChange) onAssignOpenChange(next);
        else setOwnOpen(next);
    };
    const [retrying, setRetrying] = useState<string | null>(null);
    const [historyOpen, setHistoryOpen] = useState(false);
    const historyId = useId();
    const history = ownership.history ?? [];
    const latest = history[0];
    const assignLabel = incidentAssignLabel(ownership);
    const named =
        ownership.owner?.name ?? ownership.last_owner_name ?? 'Not recorded';
    const incomingDelivered = latest?.deliveries.some(
        (delivery) =>
            delivery.role === 'incoming' && delivery.status === 'delivered',
    );
    const deliveryLines = (latest?.deliveries ?? []).filter(
        (delivery) =>
            !(delivery.role === 'incoming' && delivery.status === 'delivered'),
    );
    const assignedLine = latest ? (
        <>
            Assigned by {latest.changed_by} ·{' '}
            {formatDateTime(latest.changed_at)}
            {incomingDelivered ? ' · told in the app' : ''}
        </>
    ) : null;
    const deliveries = deliveryLines.length ? (
        <div className="mt-1.5 space-y-1.5">
            {deliveryLines.map((delivery) => (
                <div key={delivery.id} className="space-y-1">
                    <p>
                        {delivery.role === 'incoming'
                            ? 'New owner'
                            : 'Previous owner'}{' '}
                        notification: {DELIVERY_TEXT[delivery.status]}
                    </p>
                    {delivery.status === 'failed' &&
                    ownership.can_retry_delivery ? (
                        <Button
                            variant="outline"
                            size="sm"
                            className="frontline-hit frontline-focus"
                            disabled={retrying !== null}
                            onClick={() => {
                                setRetrying(delivery.id);
                                router.post(
                                    `/incidents/${incidentId}/owner-deliveries/${delivery.id}/retry`,
                                    {},
                                    {
                                        preserveScroll: true,
                                        onFinish: () => setRetrying(null),
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
    ) : null;
    const assignButton =
        ownership.can_assign && showAssign ? (
            <Button
                variant="outline"
                size="sm"
                className="frontline-hit frontline-focus mt-3"
                onClick={() => setOpen(true)}
            >
                {assignLabel}
            </Button>
        ) : null;

    return (
        <>
            <section aria-label="Incident owner" className="min-w-0">
                <div className="sg-section-head">
                    <h3>Owner</h3>
                    {history.length ? (
                        <p>
                            {history.length === 1
                                ? '1 change'
                                : `${history.length} changes`}
                        </p>
                    ) : null}
                    {history.length ? (
                        <Button
                            unstyled
                            type="button"
                            className="sg-act"
                            aria-expanded={historyOpen}
                            aria-controls={historyId}
                            onClick={() => setHistoryOpen((value) => !value)}
                        >
                            {historyOpen ? 'Hide owner history' : 'Owner history'}
                        </Button>
                    ) : null}
                </div>
                {ownership.state === 'unassigned' ? (
                    <QuietLine
                        text={
                            <>
                                No owner yet. An authorised coordinator needs to
                                assign an eligible person to follow this
                                incident through.
                            </>
                        }
                        action={
                            ownership.can_assign
                                ? { label: assignLabel, onClick: () => setOpen(true) }
                                : null
                        }
                    />
                ) : (
                    <div className="sg-surface sg-p">
                        <div className="sg-ph">
                            <span aria-hidden="true" className="sg-av">
                                {initialsOf(named)}
                            </span>
                            <div>
                                <b>{named}</b>
                                <small>
                                    {ownership.state === 'replacement_needed'
                                        ? 'Previous owner'
                                        : ownership.state === 'historical'
                                          ? 'Owner when it closed'
                                          : 'Incident owner'}
                                </small>
                                {ownership.state === 'replacement_needed' ? (
                                    <span className="sg-flag">
                                        <StatusBadge variant="warning">
                                            {incidentOwnerLabel(ownership)}
                                        </StatusBadge>
                                    </span>
                                ) : null}
                            </div>
                        </div>
                        <div className="sg-pf">
                          <div className="grid w-full gap-1">
                            {ownership.state === 'replacement_needed' ? (
                                <p>
                                    Previous owner: {named}. Their current
                                    access or eligibility no longer supports
                                    this assignment.
                                </p>
                            ) : null}
                            {ownership.state === 'historical' ? (
                                <p>
                                    Historical ownership. Reopen the incident
                                    through its normal controls before
                                    reassigning it.
                                </p>
                            ) : null}
                            {assignedLine ? <p>{assignedLine}</p> : null}
                            {deliveries}
                            {assignButton ? <div>{assignButton}</div> : null}
                          </div>
                        </div>
                    </div>
                )}
                {history.length ? (
                    <ol
                        id={historyId}
                        hidden={!historyOpen}
                        aria-label="Ownership history"
                        className="sg-surface sg-log mt-2.5"
                    >
                        {history.map((change) => (
                            <li key={change.id} className="sg-lr">
                                <time>{formatDateTime(change.changed_at)}</time>
                                <span />
                                <div className="min-w-0">
                                    <b>
                                        {change.previous_owner
                                            ? `${change.previous_owner} → ${change.owner}`
                                            : `Assigned to ${change.owner}`}
                                    </b>
                                    <small>
                                        By {change.changed_by}
                                        {change.reason ? (
                                            <span className="block whitespace-pre-wrap">
                                                {change.reason}
                                            </span>
                                        ) : null}
                                    </small>
                                </div>
                                <span />
                            </li>
                        ))}
                    </ol>
                ) : null}
            </section>
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

function initialsOf(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((part) => part[0])
            .join('')
            .toUpperCase() || '?'
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

/** The one owner-assignment dialog (eligible staff search, reason, revision guard). */
export function AssignIncidentOwner({
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
