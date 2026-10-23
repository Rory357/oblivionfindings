import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import { formatDateTime } from '@/lib/datetime';
import { useEffect, useState } from 'react';

export type ActionOwnership = {
    revision: number;
    state: 'assigned' | 'replacement_needed' | 'unassigned' | 'historical';
    can_assign: boolean;
    history: Array<{
        id: number;
        previous_owner: string | null;
        owner: string;
        changed_by: string;
        changed_at: string;
        reason: string;
    }>;
};
type Person = { id: number; name: string };

export function ActionOwnershipHistory({
    ownership,
}: {
    ownership: ActionOwnership;
}) {
    if (!ownership.history.length) return null;
    return (
        <section className="space-y-3">
            <h3 className="text-sm font-semibold">Ownership handovers</h3>
            <ol className="space-y-4">
                {ownership.history.map((change) => (
                    <li key={change.id} className="space-y-1 text-sm">
                        <p className="font-medium">
                            {change.previous_owner
                                ? `${change.previous_owner} → ${change.owner}`
                                : `Assigned to ${change.owner}`}
                        </p>
                        <p className="text-xs text-muted-foreground">
                            {change.changed_by} ·{' '}
                            {formatDateTime(change.changed_at)}
                        </p>
                        <p className="break-words whitespace-pre-wrap">
                            {change.reason}
                        </p>
                    </li>
                ))}
            </ol>
        </section>
    );
}

export function ActionOwnerPane({
    actionId,
    owner,
    ownership,
    onDone,
    onCancel,
}: {
    actionId: number;
    owner: Person | null;
    ownership: ActionOwnership;
    onDone: () => void;
    onCancel: () => void;
}) {
    const [requestUuid] = useState(() => crypto.randomUUID());
    const form = useSafetyForm({
        owner_user_id: '',
        expected_revision: ownership.revision,
        request_uuid: requestUuid,
        reason: '',
    });
    const [search, setSearch] = useState('');
    const [selected, setSelected] = useState<Person | null>(null);
    const [options, setOptions] = useState<Person[]>([]);
    const [loading, setLoading] = useState(true);
    const [lookupFailed, setLookupFailed] = useState(false);
    const [retry, setRetry] = useState(0);
    const stale = form.data.expected_revision !== ownership.revision;
    useEffect(() => {
        const controller = new AbortController();
        setLoading(true);
        setLookupFailed(false);
        const timer = setTimeout(async () => {
            try {
                const response = await fetch(
                    `/health-safety/corrective-actions/${actionId}/owner-candidates?q=${encodeURIComponent(search)}`,
                    {
                        headers: { Accept: 'application/json' },
                        signal: controller.signal,
                    },
                );
                if (!response.ok) throw new Error('Owner lookup unavailable');
                const data = (await response.json()) as { options: Person[] };
                if (!controller.signal.aborted) setOptions(data.options);
            } catch {
                if (!controller.signal.aborted) {
                    setLookupFailed(true);
                    setOptions([]);
                }
            } finally {
                if (!controller.signal.aborted) setLoading(false);
            }
        }, 200);
        return () => {
            clearTimeout(timer);
            controller.abort();
        };
    }, [actionId, search, retry]);

    return (
        <form
            className="space-y-5"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.processing || stale || !selected) return;
                form.post(
                    `/health-safety/corrective-actions/${actionId}/owner`,
                    { preserveScroll: true, onSuccess: onDone },
                );
            }}
        >
            <div className="space-y-2">
                <h2 className="text-section-title">
                    Hand over action ownership
                </h2>
                <p className="text-sm text-muted-foreground">
                    The owner remains accountable for follow-through and
                    evidence. Changing the owner keeps this action’s progress
                    and the incident’s owner unchanged.
                </p>
                <p className="text-sm">
                    Current owner:{' '}
                    <strong>{owner?.name ?? 'Not assigned'}</strong>
                </p>
            </div>
            {stale ? (
                <div
                    role="alert"
                    className="space-y-2 rounded-lg border p-3 text-sm"
                >
                    <p>
                        Ownership changed while you were working. The current
                        owner is shown above. Your selected person and reason
                        have been kept.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        className="frontline-tap"
                        onClick={() => {
                            form.setData(
                                'expected_revision',
                                ownership.revision,
                            );
                            form.setData('request_uuid', crypto.randomUUID());
                            form.clearErrors(
                                'expected_revision',
                                'request_uuid',
                            );
                            setRetry((value) => value + 1);
                        }}
                    >
                        I have reviewed the current owner
                    </Button>
                </div>
            ) : null}
            <div className="space-y-2">
                <label
                    htmlFor="action-owner-search"
                    className="text-sm font-medium"
                >
                    Find the next owner
                </label>
                <Input
                    id="action-owner-search"
                    className="frontline-tap"
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    maxLength={100}
                    disabled={form.processing}
                    autoComplete="off"
                />
                <p className="text-xs text-muted-foreground">
                    Search approved H&S staff with current access to this site.
                    Up to 30 matches are shown.
                </p>
                {loading ? (
                    <p role="status" className="text-sm">
                        Finding eligible owners…
                    </p>
                ) : lookupFailed ? (
                    <div role="alert" className="space-y-2 text-sm">
                        <p>
                            Owners could not be loaded. Your entries have been
                            kept.
                        </p>
                        <Button
                            type="button"
                            className="frontline-tap"
                            variant="outline"
                            onClick={() => setRetry((value) => value + 1)}
                        >
                            Retry owner search
                        </Button>
                    </div>
                ) : options.length ? (
                    <fieldset className="max-h-64 space-y-1 overflow-y-auto rounded-lg border p-2">
                        <legend className="sr-only">
                            Eligible action owners
                        </legend>
                        {options.map((option) => (
                            <label
                                key={option.id}
                                className="frontline-tap flex cursor-pointer items-center gap-3 rounded-md px-2 text-sm focus-within:ring-2 focus-within:ring-ring"
                            >
                                <input
                                    type="radio"
                                    name="action-owner"
                                    value={option.id}
                                    checked={selected?.id === option.id}
                                    disabled={form.processing}
                                    onChange={() => {
                                        setSelected(option);
                                        form.setData(
                                            'owner_user_id',
                                            String(option.id),
                                        );
                                    }}
                                />
                                <span className="break-words">
                                    {option.name}
                                </span>
                            </label>
                        ))}
                    </fieldset>
                ) : (
                    <p role="status" className="text-sm">
                        No eligible owners match this search.
                    </p>
                )}
                {selected ? (
                    <p className="text-sm font-medium">
                        Selected: {selected.name}
                    </p>
                ) : null}
                {form.errors.owner_user_id ? (
                    <p role="alert" className="text-sm text-destructive">
                        {form.errors.owner_user_id}
                    </p>
                ) : null}
            </div>
            <div className="space-y-2">
                <label
                    htmlFor="action-owner-reason"
                    className="text-sm font-medium"
                >
                    Reason for the handover
                </label>
                <Textarea
                    id="action-owner-reason"
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    maxLength={2000}
                    required
                    disabled={form.processing}
                    rows={4}
                />
                <p className="text-xs text-muted-foreground">
                    Include what the next owner needs to follow up. This reason
                    is retained in the action’s ownership history.
                </p>
                {form.errors.reason ? (
                    <p role="alert" className="text-sm text-destructive">
                        {form.errors.reason}
                    </p>
                ) : null}
            </div>
            {form.errors.expected_revision ? (
                <p role="alert" className="text-sm text-destructive">
                    {form.errors.expected_revision}
                </p>
            ) : null}
            {form.errors.request_uuid ? (
                <p role="alert" className="text-sm text-destructive">
                    {form.errors.request_uuid}
                </p>
            ) : null}
            <div className="flex flex-wrap gap-3">
                <Button
                    className="frontline-tap"
                    type="submit"
                    disabled={
                        form.processing ||
                        stale ||
                        !selected ||
                        !form.data.reason.trim()
                    }
                >
                    {form.processing ? 'Saving handover…' : 'Confirm handover'}
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
