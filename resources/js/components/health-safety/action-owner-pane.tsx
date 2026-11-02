import { Log, Note } from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useSafetyForm } from '@/hooks/use-safety-workspace-drafts';
import { formatDateTime } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { TriangleAlert, UserRound } from 'lucide-react';
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

/** Owner changes as a log (pg-hsn-43c): when, who to whom, who changed it and the full reason. */
export function ActionOwnershipHistory({
    ownership,
}: {
    ownership: ActionOwnership;
}) {
    if (!ownership.history.length) return null;
    return (
        <Log
            title="Owner changes"
            limit={ownership.history.length}
            items={ownership.history.map((change) => ({
                time: formatDateTime(change.changed_at),
                tone: 'info',
                title: change.previous_owner
                    ? `${change.previous_owner} → ${change.owner}`
                    : `Assigned to ${change.owner}`,
                sub: (
                    <>
                        {change.changed_by} ·{' '}
                        <span className="break-words whitespace-pre-wrap">
                            {change.reason}
                        </span>
                    </>
                ),
            }))}
        />
    );
}

/**
 * Change the owner (pg-hsn-43g). The submission is unchanged: the chosen person, the revision the user reviewed,
 * one request identity kept across retries, and the reason.
 */
export function ActionOwnerPane({
    actionId,
    owner,
    ownership,
    onDone,
    onCancel,
    siteName,
}: {
    actionId: number;
    owner: Person | null;
    ownership: ActionOwnership;
    onDone: () => void;
    onCancel: () => void;
    /** The review's site, so the picker can say who is listed and why it may be empty (M13). */
    siteName?: string | null;
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
            {stale ? (
                <Note tone="warning" icon={TriangleAlert}>
                    <div role="alert" className="space-y-2">
                        <p>
                            Someone changed the owner while you were working.
                            The new owner is shown below. Your choice and reason
                            are still here.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            onClick={() => {
                                form.setData(
                                    'expected_revision',
                                    ownership.revision,
                                );
                                form.setData(
                                    'request_uuid',
                                    crypto.randomUUID(),
                                );
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
                </Note>
            ) : null}
            <div className="space-y-1">
                <h2 className="text-section-title">Change the owner</h2>
                <p className="text-sm text-muted-foreground">
                    The owner is responsible for finishing the action and its
                    evidence. Changing owner keeps the action’s progress and
                    doesn’t change the incident’s owner.
                </p>
            </div>
            <div className="space-y-1">
                <p className="text-sm font-medium">Current owner</p>
                <p className="text-sm font-semibold">
                    {owner?.name ?? 'Not assigned'}
                </p>
            </div>
            <div className="space-y-2">
                <label
                    htmlFor="action-owner-search"
                    className="text-sm font-medium"
                >
                    Find the next owner
                </label>
                <p className="text-caption text-muted-foreground">
                    Only people who manage health and safety at{' '}
                    {siteName ?? 'this site'} are listed · shows up to 30
                </p>
                <Input
                    id="action-owner-search"
                    type="search"
                    value={search}
                    onChange={(event) => setSearch(event.target.value)}
                    maxLength={100}
                    disabled={form.processing}
                    autoComplete="off"
                />
            </div>
            <div className="space-y-2">
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
                            variant="outline"
                            onClick={() => setRetry((value) => value + 1)}
                        >
                            Retry owner search
                        </Button>
                    </div>
                ) : options.length ? (
                    <fieldset className="space-y-2">
                        <legend className="mb-2 text-sm font-medium">
                            Who can own it
                        </legend>
                        <div className="grid max-h-64 gap-2 overflow-y-auto sm:grid-cols-3">
                            {options.map((option) => {
                                const on = selected?.id === option.id;
                                return (
                                    <label
                                        key={option.id}
                                        className={cn(
                                            'flex cursor-pointer items-center gap-3 rounded-lg border bg-card p-3 text-sm font-medium transition-colors focus-within:ring-2 focus-within:ring-ring hover:bg-muted/50',
                                            on
                                                ? 'border-primary bg-primary-fill/10 text-primary'
                                                : 'border-border',
                                        )}
                                    >
                                        <input
                                            type="radio"
                                            name="action-owner"
                                            value={option.id}
                                            checked={on}
                                            disabled={form.processing}
                                            className="sr-only"
                                            onChange={() => {
                                                setSelected(option);
                                                form.setData(
                                                    'owner_user_id',
                                                    String(option.id),
                                                );
                                            }}
                                        />
                                        <span
                                            aria-hidden="true"
                                            className="grid size-7 shrink-0 place-items-center rounded-full bg-muted"
                                        >
                                            <UserRound className="size-3.5" />
                                        </span>
                                        <span className="min-w-0 break-words">
                                            {option.name}
                                        </span>
                                    </label>
                                );
                            })}
                        </div>
                    </fieldset>
                ) : search.trim() ? (
                    <p role="status" className="text-sm">
                        No eligible owners match this search.
                    </p>
                ) : (
                    // IR6: an empty picker says why and who can help.
                    <div role="status" className="space-y-1 text-sm">
                        <p className="font-semibold">
                            Nobody here can own this yet
                        </p>
                        <p className="text-muted-foreground">
                            Owners need to manage health and safety at{' '}
                            {siteName ?? 'this site'}. An admin can give someone
                            that access.
                        </p>
                    </div>
                )}
                {selected ? (
                    <div className="space-y-1 pt-1">
                        <p className="text-sm font-medium">Selected</p>
                        <p className="text-sm font-semibold">{selected.name}</p>
                    </div>
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
                    Why you’re changing owner
                    <span aria-hidden="true" className="text-status-critical">
                        {' '}
                        *
                    </span>
                </label>
                <p
                    id="action-owner-reason-hint"
                    className="text-caption text-muted-foreground"
                >
                    Say what the new owner needs to follow up. This reason is
                    kept in the owner history.
                </p>
                <Textarea
                    id="action-owner-reason"
                    aria-describedby="action-owner-reason-hint"
                    value={form.data.reason}
                    onChange={(event) =>
                        form.setData('reason', event.target.value)
                    }
                    maxLength={2000}
                    required
                    disabled={form.processing}
                    rows={4}
                />
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
            <div className="flex flex-wrap justify-end gap-2">
                <Button
                    type="button"
                    variant="outline"
                    disabled={form.processing}
                    onClick={onCancel}
                >
                    Cancel
                </Button>
                <Button
                    type="submit"
                    disabled={
                        form.processing ||
                        stale ||
                        !selected ||
                        !form.data.reason.trim()
                    }
                >
                    {form.processing
                        ? 'Saving…'
                        : selected
                          ? `Make ${selected.name} the owner`
                          : 'Choose an owner'}
                </Button>
            </div>
        </form>
    );
}
