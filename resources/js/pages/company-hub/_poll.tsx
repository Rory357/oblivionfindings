import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import {
    formatDateTime,
    toDatetimeLocal,
    workerTimeOffset,
} from '@/lib/datetime';
import { router, usePage } from '@inertiajs/react';
import { Check, Clock3, Loader2, Vote } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import { pollInstant, PollTimeInput } from './_poll-time';
import type { HubBlock, HubProps } from './_types';

type Props = { block: HubBlock; data: HubProps; preview?: boolean };
type PollAck = {
    saved: boolean;
    page_id: number;
    block_id: string;
    key: string;
    version: number;
};

export function HubPoll(props: Props) {
    const actor = Number(
        (usePage().props.auth as { user?: { id: number } })?.user?.id,
    );
    const activity = props.data.participation;
    const poll = activity?.polls[props.block.id];
    return (
        <PollBody
            key={`${actor}-${activity?.actor_id}-${activity?.page_id}-${activity?.revision_id}-${poll?.key}-${poll?.version}-${poll?.mine}-${poll?.closed}-${poll?.can_manage}-${props.preview}-${props.data.demo}`}
            {...props}
            actor={actor}
        />
    );
}

function PollBody({ block, data, preview, actor }: Props & { actor: number }) {
    const activity = data.participation;
    const poll = activity?.polls[block.id];
    const [choice, setChoice] = useState<number | null>(poll?.mine ?? null);
    const [changing, setChanging] = useState(false);
    const [action, setAction] = useState<'close' | 'reopen' | null>(null);
    const [local, setLocal] = useState(toDatetimeLocal(poll?.closes_at));
    const [offset, setOffset] = useState(
        poll?.closes_at ? workerTimeOffset(poll.closes_at) : '',
    );
    const [busy, setBusy] = useState(false);
    const [refresh, setRefresh] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const pending = useRef(false);
    const alive = useRef(true);
    const trigger = useRef<HTMLButtonElement>(null);
    const confirmHeading = useRef<HTMLHeadingElement>(null);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    useEffect(() => {
        if (action) confirmHeading.current?.focus();
    }, [action]);
    const valid =
        !!poll &&
        !!activity &&
        actor === activity.actor_id &&
        !!activity.revision_id &&
        Number.isInteger(poll.version) &&
        !data.demo &&
        !preview;
    const results =
        !preview &&
        actor === activity?.actor_id &&
        !!poll &&
        poll.results_visible === true &&
        (poll.mine !== null || poll.closed) &&
        Array.isArray(poll.counts) &&
        typeof poll.total === 'number';
    const ballot = !poll?.closed && (!results || changing);
    const instant = pollInstant(local, offset);
    const requiresFuture =
        action === 'reopen' &&
        !!poll?.closes_at &&
        Date.parse(poll.closes_at) <= Date.now();
    const reopenValid = !local
        ? !requiresFuture
        : !!instant && Date.parse(instant) > Date.now();
    const reload = () => {
        if (pending.current) return;
        pending.current = true;
        setBusy(true);
        router.reload({
            only: ['participation', 'selectedPage', 'home'],
            preserveScroll: true,
            onSuccess: () => {
                if (alive.current) setRefresh(false);
            },
            onFinish: () => {
                if (alive.current) {
                    pending.current = false;
                    setBusy(false);
                }
            },
        });
    };
    const command = async (kind: 'vote' | 'close' | 'reopen') => {
        if (!valid || !activity || !poll || pending.current || refresh) return;
        if (kind === 'vote' && (poll.closed || choice === null)) return;
        if (kind !== 'vote' && !poll.can_manage) return;
        if (kind === 'reopen' && !reopenValid) return;
        pending.current = true;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const result = await hubRequest<PollAck>(
                kind === 'vote'
                    ? `/company-hub/pages/${activity.page_id}/interactions`
                    : `/company-hub/pages/${activity.page_id}/polls/${encodeURIComponent(block.id)}/state`,
                kind === 'vote' ? 'POST' : 'PUT',
                {
                    actor_id: actor,
                    revision_id: activity.revision_id,
                    poll_key: poll.key,
                    ...(kind === 'vote'
                        ? {
                              kind: 'vote',
                              block_id: block.id,
                              choice,
                              poll_version: poll.version,
                          }
                        : {
                              action: kind,
                              version: poll.version,
                              ...(kind === 'reopen'
                                  ? { closes_at: local ? instant : null }
                                  : {}),
                          }),
                },
            );
            if (!alive.current) return;
            if (
                result.saved !== true ||
                result.page_id !== activity.page_id ||
                result.block_id !== block.id ||
                result.key !== poll.key ||
                !Number.isInteger(result.version) ||
                (kind === 'vote'
                    ? result.version !== poll.version
                    : result.version !== poll.version! + 1)
            )
                throw new Error(
                    'The poll could not be confirmed. Refresh it before trying again.',
                );
            setAction(null);
            setChanging(false);
            setRefresh(true);
            setNotice(
                kind === 'vote'
                    ? 'Your vote was counted.'
                    : kind === 'close'
                      ? 'The poll is closed.'
                      : 'The poll is open again.',
            );
            pending.current = false;
            reload();
        } catch (error) {
            if (alive.current) {
                setError(
                    error instanceof Error
                        ? error.message
                        : 'Please try again.',
                );
                pending.current = false;
                setBusy(false);
            }
        }
    };
    const closeConfirmation = () => {
        if (pending.current) return;
        setAction(null);
        setError('');
        requestAnimationFrame(() => trigger.current?.focus());
    };
    return (
        <Card
            className="space-y-5 p-5"
            aria-label={block.title || 'Company poll'}
        >
            <div className="flex items-center gap-2 text-xs font-semibold text-primary">
                <Vote className="size-4" />
                Have your say
                {poll?.closed && (
                    <span className="ml-auto rounded-full bg-muted px-2 py-1 text-muted-foreground">
                        Closed
                    </span>
                )}
            </div>
            <div>
                <h3 className="text-lg font-semibold">{block.question}</h3>
                <p className="mt-2 text-sm text-muted-foreground">
                    {poll?.closed
                        ? 'Voting has closed. Thank you for taking part.'
                        : results
                          ? 'Thanks for adding your voice. Here’s how the team voted.'
                          : 'Choose one answer. Results appear after you vote or when the poll closes.'}
                </p>
            </div>
            {poll?.closes_at && (
                <p className="flex items-center gap-2 text-xs text-muted-foreground">
                    <Clock3 className="size-3.5" />
                    {poll.closed ? 'Closing time' : 'Closes'}{' '}
                    {formatDateTime(poll.closes_at)} · NZ time
                </p>
            )}
            {ballot ? (
                <fieldset
                    className="space-y-2"
                    disabled={!valid || busy || refresh}
                >
                    <legend className="sr-only">Your answer</legend>
                    {block.options?.map((option, index) => (
                        <label
                            key={index}
                            className={`flex cursor-pointer items-center gap-3 rounded-xl border p-4 text-sm has-focus-visible:ring-2 has-focus-visible:ring-ring ${choice === index ? 'border-primary bg-primary/5' : 'hover:bg-accent'}`}
                        >
                            <input
                                type="radio"
                                name={`poll-${block.id}`}
                                checked={choice === index}
                                onChange={() => setChoice(index)}
                                className="accent-primary"
                            />
                            {option}
                        </label>
                    ))}
                </fieldset>
            ) : results ? (
                <div className="space-y-3">
                    {block.options?.map((option, index) => {
                        const count = poll.counts![index] ?? 0;
                        const percentage = poll.total
                            ? Math.round((count / poll.total) * 100)
                            : 0;
                        return (
                            <div
                                key={index}
                                className="space-y-2 rounded-xl border p-4"
                            >
                                <div className="flex items-center justify-between gap-4 text-sm">
                                    <span>
                                        {option}
                                        {poll.mine === index && (
                                            <span className="ml-2 inline-flex items-center gap-1 text-xs font-medium text-primary">
                                                <Check className="size-3.5" />
                                                Your vote
                                            </span>
                                        )}
                                    </span>
                                    <strong>{percentage}%</strong>
                                </div>
                                <div
                                    className="h-1.5 overflow-hidden rounded-full bg-muted"
                                    aria-hidden="true"
                                >
                                    <div
                                        className="h-full rounded-full bg-primary"
                                        style={{ width: `${percentage}%` }}
                                    />
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    {count} {count === 1 ? 'vote' : 'votes'}
                                </p>
                            </div>
                        );
                    })}
                </div>
            ) : (
                <p className="text-sm text-muted-foreground">
                    Results are not available yet.
                </p>
            )}
            {results && (
                <p className="text-xs text-muted-foreground">
                    {poll.total} {poll.total === 1 ? 'response' : 'responses'} ·
                    Percentages rounded
                </p>
            )}
            {!valid && !poll && (
                <p className="text-sm text-muted-foreground">
                    Voting opens when this page is published.
                </p>
            )}
            {notice && (
                <p role="status" className="text-sm font-medium text-primary">
                    {notice}
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            <div className="flex flex-wrap items-center gap-2">
                {valid && ballot && (
                    <Button
                        disabled={
                            choice === null ||
                            busy ||
                            refresh ||
                            choice === poll?.mine
                        }
                        onClick={() => void command('vote')}
                    >
                        {busy && <Loader2 className="size-4 animate-spin" />}
                        {poll?.mine === null
                            ? 'Cast my vote'
                            : 'Save my answer'}
                    </Button>
                )}
                {valid && results && !poll?.closed && (
                    <Button
                        variant="outline"
                        disabled={busy || refresh}
                        onClick={() => setChanging(!changing)}
                    >
                        {changing ? 'Keep my answer' : 'Change my answer'}
                    </Button>
                )}
                {valid && poll.can_manage && !action && (
                    <Button
                        ref={trigger}
                        className="ml-auto"
                        variant="ghost"
                        disabled={busy || refresh}
                        onClick={() =>
                            setAction(poll.closed ? 'reopen' : 'close')
                        }
                    >
                        {poll.closed ? 'Reopen poll' : 'Close poll'}
                    </Button>
                )}
                {(refresh || error) && !busy && (
                    <Button variant="outline" onClick={reload}>
                        Refresh poll
                    </Button>
                )}
            </div>
            {action && (
                <section
                    className="space-y-4 rounded-xl border bg-muted/30 p-4"
                    aria-label={
                        action === 'close'
                            ? 'Close this poll'
                            : 'Reopen this poll'
                    }
                    onKeyDown={(event) => {
                        if (event.key === 'Escape') {
                            event.stopPropagation();
                            closeConfirmation();
                        }
                    }}
                >
                    <h4
                        ref={confirmHeading}
                        tabIndex={-1}
                        className="font-semibold"
                    >
                        {action === 'close'
                            ? 'Close voting now?'
                            : 'Welcome more responses'}
                    </h4>
                    <p className="text-sm text-muted-foreground">
                        {action === 'close'
                            ? 'Staff will see the results and can no longer vote. Existing answers stay with this question.'
                            : 'Existing votes stay in place. Choose a future closing time, or leave it empty to close the poll manually.'}
                    </p>
                    {action === 'reopen' && (
                        <>
                            <PollTimeInput
                                local={local}
                                offset={offset}
                                disabled={busy}
                                onChange={(value, zone) => {
                                    setLocal(value);
                                    setOffset(zone);
                                }}
                            />
                            {requiresFuture && (
                                <p className="text-xs text-muted-foreground">
                                    The earlier deadline has passed. Set a
                                    future time to reopen this poll.
                                </p>
                            )}
                        </>
                    )}
                    <div className="flex gap-2">
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={closeConfirmation}
                        >
                            Keep as it is
                        </Button>
                        <Button
                            disabled={
                                busy ||
                                refresh ||
                                (action === 'reopen' && !reopenValid)
                            }
                            onClick={() => void command(action)}
                        >
                            {busy && (
                                <Loader2 className="size-4 animate-spin" />
                            )}
                            {action === 'close'
                                ? 'Confirm close'
                                : 'Confirm reopen'}
                        </Button>
                    </div>
                </section>
            )}
        </Card>
    );
}
