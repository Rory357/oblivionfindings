import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { Link, router } from '@inertiajs/react';
import { Check, Clock3, Loader2, UsersRound } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest, HubRequestError } from './_api';
import { HubSearchSelect } from './_search-select';
import { HubSelect } from './_select';
import type { HubIdea, HubIdeaUpdate, HubProps } from './_types';

export const ideaStatuses: Record<string, string> = {
    open: 'Open for ideas',
    reviewing: 'Under review',
    planned: 'Planned',
    in_progress: 'In progress',
    delivered: 'Delivered',
    closed: 'Closed',
};

export type IdeaProgressContext = {
    actorId: number | null;
    progressReady: boolean;
    teams: NonNullable<HubProps['ideaTeams']>;
    owners: NonNullable<HubProps['ideaOwners']>;
};

export function IdeaProgress({ idea }: { idea: HubIdea }) {
    const history: HubIdeaUpdate[] = idea.updates?.length
        ? idea.updates
        : idea.status_note
          ? [
                {
                    id: 0,
                    status: idea.status,
                    note: idea.status_note,
                    created_at: null,
                    author: null,
                    owner: null,
                    team: null,
                    legacy: true,
                },
            ]
          : [];
    return (
        <section
            className="my-5 rounded-xl border border-border bg-muted/20 p-5"
            aria-label="Idea progress"
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h3 className="font-semibold">What happened next</h3>
                    <p className="mt-1 text-sm text-muted-foreground">
                        Follow the response and the people taking it forward.
                    </p>
                </div>
                <span className="inline-flex items-center gap-1.5 rounded-full bg-primary/10 px-3 py-1 text-xs font-semibold text-primary">
                    {idea.status === 'delivered' ? (
                        <Check className="size-3.5" />
                    ) : (
                        <Clock3 className="size-3.5" />
                    )}
                    {ideaStatuses[idea.status] ?? idea.status}
                </span>
            </div>
            <dl className="my-4 grid grid-cols-2 gap-4 rounded-lg bg-background p-3 text-sm">
                <div>
                    <dt className="text-xs text-muted-foreground">
                        Taking it forward
                    </dt>
                    <dd className="mt-1 font-medium">
                        {idea.owner ? (
                            <Link
                                href={idea.owner.url}
                                className="text-primary underline-offset-4 hover:underline"
                            >
                                {idea.owner.name}
                            </Link>
                        ) : (
                            'Owner to be confirmed'
                        )}
                    </dd>
                </div>
                <div>
                    <dt className="text-xs text-muted-foreground">Best team</dt>
                    <dd className="mt-1 flex items-center gap-1.5 font-medium">
                        <UsersRound className="size-3.5 text-muted-foreground" />
                        {idea.team?.name ?? 'No team shown'}
                    </dd>
                </div>
            </dl>
            {history.length ? (
                <ol
                    className="space-y-5 border-l border-border pl-4"
                    aria-label="Progress updates"
                >
                    {history.map((update) => (
                        <li key={update.id} className="relative text-sm">
                            <span
                                aria-hidden="true"
                                className="absolute top-1 -left-[21px] size-2 rounded-full bg-primary"
                            />
                            <div className="flex flex-wrap items-center gap-x-3 gap-y-1">
                                <strong>
                                    {ideaStatuses[update.status] ??
                                        update.status}
                                </strong>
                                <span className="text-xs text-muted-foreground">
                                    {update.created_at
                                        ? formatDateTime(update.created_at)
                                        : 'Date not recorded'}
                                    {update.legacy ? ' · Earlier response' : ''}
                                </span>
                            </div>
                            <p className="mt-2 leading-relaxed whitespace-pre-wrap">
                                {update.note}
                            </p>
                            <div className="mt-2 flex flex-wrap gap-x-3 gap-y-1 text-xs text-muted-foreground">
                                {update.author && (
                                    <span>
                                        Update from {update.author.name}
                                    </span>
                                )}
                                {update.owner && (
                                    <Link
                                        href={update.owner.url}
                                        className="text-primary hover:underline"
                                    >
                                        Owner: {update.owner.name}
                                    </Link>
                                )}
                                {update.team && <span>{update.team.name}</span>}
                            </div>
                        </li>
                    ))}
                </ol>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Updates will appear here as this idea is considered. You can
                    support it or add to the discussion below.
                </p>
            )}
        </section>
    );
}

function progressBaseline(idea: HubIdea) {
    return {
        version: idea.version,
        status: idea.status,
        ownerId: idea.owner?.id ?? null,
        teamId: idea.team?.id ?? null,
    };
}

export function IdeaProgressEditor({
    idea,
    actorId,
    progressReady,
    teams,
    owners,
    disabled = false,
    onDraftStateChange,
    runRefresh = (run) => run(),
}: {
    idea: HubIdea;
    disabled?: boolean;
    onDraftStateChange?: (state: { dirty: boolean; pending: boolean }) => void;
    runRefresh?: (run: () => void) => void;
} & IdeaProgressContext) {
    const [baseline, setBaseline] = useState(() => progressBaseline(idea));
    const [status, setStatus] = useState(idea.status);
    const [owner, setOwner] = useState(
        owners.some((p) => p.id === idea.owner?.id)
            ? String(idea.owner!.id)
            : '',
    );
    const [team, setTeam] = useState(
        teams.some((t) => t.id === idea.team?.id) ? String(idea.team!.id) : '',
    );
    const [note, setNote] = useState('');
    const [busy, setBusy] = useState(false);
    const [saved, setSaved] = useState(false);
    const [error, setError] = useState('');
    const [needsRefresh, setNeedsRefresh] = useState(false);
    const alive = useRef(false);
    const lock = useRef(false);
    const allowed =
        progressReady && idea.can_update === true && actorId !== null;
    const dirty =
        !saved &&
        (!!note ||
            status !== baseline.status ||
            owner !==
                (owners.some((person) => person.id === baseline.ownerId)
                    ? String(baseline.ownerId)
                    : '') ||
            team !==
                (teams.some((item) => item.id === baseline.teamId)
                    ? String(baseline.teamId)
                    : ''));
    const stale = baseline.version !== idea.version;
    const resetToLatest = (keepNote: boolean) => {
        setBaseline(progressBaseline(idea));
        setStatus(idea.status);
        setOwner(
            owners.some((person) => person.id === idea.owner?.id)
                ? String(idea.owner!.id)
                : '',
        );
        setTeam(
            teams.some((item) => item.id === idea.team?.id)
                ? String(idea.team!.id)
                : '',
        );
        if (!keepNote) setNote('');
        setSaved(false);
        setNeedsRefresh(false);
        setError('');
        lock.current = false;
    };
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        onDraftStateChange?.({ dirty: allowed && dirty, pending: busy });
    }, [allowed, dirty, busy, onDraftStateChange]);
    useEffect(
        () => () => onDraftStateChange?.({ dirty: false, pending: false }),
        [onDraftStateChange],
    );
    useEffect(() => {
        if (
            (saved && idea.version > baseline.version) ||
            (!saved && !dirty && stale)
        )
            resetToLatest(false);
    });
    const refresh = () => {
        if (!alive.current) return;
        setBusy(true);
        lock.current = true;
        runRefresh(() =>
            router.reload({
                only: [
                    'ideas',
                    'ideaBrowse',
                    'selectedIdea',
                    'ideaId',
                    'ideaUnavailable',
                    'ideaProgressReady',
                    'ideaActorId',
                    'ideaTeams',
                    'ideaOwners',
                ],
                onSuccess: () => {
                    if (alive.current) setNeedsRefresh(false);
                },
                onFinish: () => {
                    if (alive.current) {
                        setBusy(false);
                        if (!saved) lock.current = false;
                    }
                },
            }),
        );
    };
    const submit = async () => {
        if (
            !allowed ||
            disabled ||
            stale ||
            needsRefresh ||
            lock.current ||
            saved ||
            !note.trim() ||
            !owners.some((p) => p.id === Number(owner)) ||
            (!!team && !teams.some((item) => item.id === Number(team)))
        )
            return;
        lock.current = true;
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<{
                saved: boolean;
                idea_id: number;
            }>(`/company-hub/ideas/${idea.id}`, 'POST', {
                action: 'status',
                status,
                status_note: note.trim(),
                owner_id: Number(owner),
                ...(team !== (baseline.teamId ? String(baseline.teamId) : '')
                    ? { department_id: team ? Number(team) : null }
                    : {}),
                version: baseline.version,
                actor_id: actorId,
            });
            if (!alive.current) return;
            if (result.saved !== true || result.idea_id !== idea.id)
                throw new Error(
                    'The update could not be confirmed. Reload the idea before trying again.',
                );
            setSaved(true);
            refresh();
        } catch (failure) {
            if (!alive.current) return;
            setError(
                failure instanceof Error
                    ? failure.message
                    : 'Your update could not be saved.',
            );
            lock.current = false;
            setBusy(false);
            setNeedsRefresh(
                !(failure instanceof HubRequestError) ||
                    ![422, 429].includes(failure.status),
            );
        }
    };
    if (!allowed) return null;
    return (
        <details className="my-4 rounded-xl border border-border p-4">
            <summary className="cursor-pointer text-sm font-semibold">
                Post a progress update
            </summary>
            <form
                className="mt-4 space-y-4"
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
            >
                <p className="text-sm text-muted-foreground">
                    Give staff a clear next step. Each update stays in the
                    history.
                </p>
                {stale && !saved && (
                    <div
                        role="status"
                        className="space-y-3 rounded-xl border border-primary/20 bg-primary/5 p-4 text-sm"
                    >
                        <p>
                            This idea has a newer progress update. Your note and
                            choices are still here. Review the latest progress
                            above before continuing.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy || disabled}
                            onClick={() => resetToLatest(true)}
                        >
                            Use latest stage, owner and team; keep my note
                        </Button>
                    </div>
                )}
                <fieldset
                    disabled={busy || saved || disabled}
                    className="space-y-4"
                >
                    <div className="grid grid-cols-2 gap-3">
                        <Label className="grid gap-2">
                            Stage
                            <HubSelect
                                label="Stage"
                                value={status}
                                onChange={setStatus}
                                disabled={busy || saved || disabled}
                                options={Object.entries(ideaStatuses).map(
                                    ([value, label]) => ({ value, label }),
                                )}
                            />
                        </Label>
                        <Label className="grid gap-2">
                            Best team
                            <HubSelect
                                label="Best team"
                                value={team}
                                onChange={setTeam}
                                disabled={busy || saved || disabled}
                                options={[
                                    { value: '', label: 'Company-wide' },
                                    ...teams.map((item) => ({
                                        value: String(item.id),
                                        label: item.name,
                                    })),
                                ]}
                            />
                        </Label>
                    </div>
                    <Label className="grid gap-2">
                        Taking it forward
                        <HubSearchSelect
                            label="Taking it forward"
                            searchLabel="Search colleagues by name or role"
                            placeholder="Choose an owner"
                            disabled={busy || saved || disabled}
                            value={owner}
                            onChange={setOwner}
                            options={owners.map((person) => ({
                                value: String(person.id),
                                label: person.name,
                                description: person.position ?? undefined,
                            }))}
                        />
                    </Label>
                    {!owners.length && (
                        <p className="text-sm text-muted-foreground">
                            No eligible colleagues are currently available to
                            own this idea.
                        </p>
                    )}
                    <Label className="grid gap-2">
                        Update for staff
                        <Textarea
                            required
                            maxLength={2000}
                            rows={4}
                            placeholder="What has happened, and what comes next?"
                            value={note}
                            onChange={(e) => setNote(e.target.value)}
                        />
                    </Label>
                </fieldset>
                {error && (
                    <div
                        role="alert"
                        className="space-y-2 text-sm text-destructive"
                    >
                        <p>{error}</p>
                        <p className="text-muted-foreground">
                            Your note stays here while you reload. Check the
                            history before posting again.
                        </p>
                        <Button
                            type="button"
                            variant="outline"
                            disabled={busy || disabled}
                            onClick={refresh}
                        >
                            Reload latest progress
                        </Button>
                    </div>
                )}
                {saved ? (
                    <div
                        role="status"
                        className="flex items-center gap-3 text-sm"
                    >
                        <Check className="size-4 text-primary" />
                        Update saved.
                        {busy ? (
                            ' Refreshing progress…'
                        ) : (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={refresh}
                            >
                                Reload latest update
                            </Button>
                        )}
                    </div>
                ) : (
                    <Button
                        disabled={
                            busy ||
                            disabled ||
                            stale ||
                            needsRefresh ||
                            !owners.some(
                                (person) => person.id === Number(owner),
                            ) ||
                            (!!team &&
                                !teams.some(
                                    (item) => item.id === Number(team),
                                )) ||
                            !note.trim()
                        }
                        type="submit"
                    >
                        {busy && <Loader2 className="size-4 animate-spin" />}
                        {busy ? 'Saving update…' : 'Post update'}
                    </Button>
                )}
            </form>
        </details>
    );
}
