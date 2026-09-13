import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import type { TicketWatcherCommand } from '@/hooks/use-ticket-watcher-command';
import { Eye, EyeOff, UserMinus, UserPlus } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

/** Shared People & feedback projection; every change uses the canonical command. */
export function TicketWatchers({
    command,
    actorId,
}: {
    command: TicketWatcherCommand;
    actorId: number | null | undefined;
}) {
    const [selected, setSelected] = useState('');
    const selectable = command.options.filter(
        (person) =>
            !command.watchers.some((watcher) => watcher.id === person.id),
    );
    const selfWatching = command.watchers.some(
        (watcher) => watcher.id === actorId,
    );
    return (
        <section aria-label="Ticket watchers" className="space-y-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <h3 className="text-sm font-semibold">
                    Watchers
                    {!command.concealed ? ` (${command.watchers.length})` : ''}
                </h3>
                {command.canManage &&
                    actorId &&
                    (selfWatching ||
                        command.options.some(
                            (person) => person.id === actorId,
                        )) && (
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            disabled={command.busy}
                            onClick={() =>
                                command.begin(actorId, !selfWatching)
                            }
                        >
                            {selfWatching ? (
                                <EyeOff className="size-4" aria-hidden />
                            ) : (
                                <Eye className="size-4" aria-hidden />
                            )}
                            {selfWatching ? 'Stop watching' : 'Watch ticket'}
                        </Button>
                    )}
            </div>
            {command.concealed ? (
                <p role="alert" className="text-sm text-muted-foreground">
                    {command.message}
                </p>
            ) : (
                <>
                    {command.watchers.length ? (
                        <ul className="space-y-2">
                            {command.watchers.map((person) => (
                                <li
                                    key={person.id}
                                    className="flex items-center justify-between gap-3 rounded-lg border border-border px-3 py-2"
                                >
                                    <div className="min-w-0 space-y-1">
                                        <p className="text-sm font-medium break-words">
                                            {person.name}
                                        </p>
                                        {person.receives_updates === false && (
                                            <StatusBadge
                                                variant="warning"
                                                size="sm"
                                            >
                                                Updates paused: access changed
                                            </StatusBadge>
                                        )}
                                    </div>
                                    {command.canManage && (
                                        <Button
                                            type="button"
                                            variant="ghost"
                                            size="sm"
                                            className="shrink-0"
                                            disabled={command.busy}
                                            aria-label={`Remove ${person.name} as watcher`}
                                            onClick={() =>
                                                command.begin(person.id, false)
                                            }
                                        >
                                            <UserMinus
                                                className="size-4"
                                                aria-hidden
                                            />{' '}
                                            Remove
                                        </Button>
                                    )}
                                </li>
                            ))}
                        </ul>
                    ) : (
                        <p className="text-sm text-muted-foreground">
                            Nobody is watching this ticket.
                        </p>
                    )}
                    {command.canManage && (
                        <div className="space-y-2">
                            <Label htmlFor={`watcher-person-${actorId}`}>
                                Add a person
                            </Label>
                            <div className="flex items-start gap-2">
                                <Select
                                    value={
                                        selectable.some(
                                            (row) =>
                                                String(row.id) === selected,
                                        )
                                            ? selected
                                            : ''
                                    }
                                    onValueChange={setSelected}
                                    disabled={
                                        command.busy || !selectable.length
                                    }
                                >
                                    <SelectTrigger
                                        id={`watcher-person-${actorId}`}
                                        className="min-w-0 flex-1"
                                    >
                                        <SelectValue
                                            placeholder={
                                                selectable.length
                                                    ? 'Choose an eligible person'
                                                    : 'No other eligible people'
                                            }
                                        />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {selectable.map((person) => (
                                            <SelectItem
                                                key={person.id}
                                                value={String(person.id)}
                                            >
                                                {person.name}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <Button
                                    type="button"
                                    variant="outline"
                                    disabled={
                                        command.busy ||
                                        !selectable.some(
                                            (row) =>
                                                String(row.id) === selected,
                                        )
                                    }
                                    onClick={() =>
                                        command.begin(Number(selected), true)
                                    }
                                >
                                    <UserPlus className="size-4" aria-hidden />{' '}
                                    Add
                                </Button>
                            </div>
                            <p className="text-xs text-muted-foreground">
                                Only people currently permitted to receive this
                                ticket's updates are available. Watching does
                                not grant access.
                            </p>
                        </div>
                    )}
                </>
            )}
            {command.pending && !command.open && (
                <Button
                    type="button"
                    variant="outline"
                    onClick={command.reopen}
                >
                    Review pending watcher change
                </Button>
            )}
            <Dialog
                open={command.open}
                onOpenChange={(open) => !open && command.close()}
            >
                <DialogContent
                    style={{ width: 'min(92vw, 560px)', maxWidth: '560px' }}
                >
                    {command.open && <WatcherChangeBody command={command} />}
                </DialogContent>
            </Dialog>
        </section>
    );
}

function WatcherChangeBody({ command }: { command: TicketWatcherCommand }) {
    const alert = useRef<HTMLDivElement>(null);
    useEffect(() => {
        if (command.message) alert.current?.focus();
    }, [command.message]);
    const intent = command.intent;
    const person = intent
        ? (command.options.find((row) => row.id === intent.userId) ??
          command.watchers.find((row) => row.id === intent.userId))
        : null;
    const reviewedWatching =
        intent &&
        command.reviewed?.watchers.some((row) => row.id === intent.userId);
    const eligible =
        intent &&
        (!intent.watching ||
            command.reviewed?.options.some((row) => row.id === intent.userId));
    return (
        <>
            <DialogHeader>
                <DialogTitle>
                    {command.stage === 'done'
                        ? 'Watcher change confirmed'
                        : 'Change ticket watchers'}
                </DialogTitle>
                <DialogDescription>
                    Review this notification subscription. Ticket access and the
                    current version are checked when it is applied.
                </DialogDescription>
            </DialogHeader>
            {command.message && (
                <div
                    ref={alert}
                    tabIndex={-1}
                    role="alert"
                    className="rounded-lg border border-border bg-muted/40 p-3 text-sm"
                >
                    {command.message}
                </div>
            )}
            {command.stage === 'session' && (
                <a
                    href="/login"
                    target="_blank"
                    rel="noreferrer"
                    className="text-sm font-medium text-primary underline"
                >
                    Sign in with the same account
                </a>
            )}
            {!command.concealed && intent && command.stage !== 'done' && (
                <div className="space-y-2 rounded-lg border border-border p-3 text-sm">
                    <p className="font-medium">
                        {intent.watching ? 'Add' : 'Remove'}{' '}
                        {person?.name ?? 'the selected person'}{' '}
                        {intent.watching ? 'as a watcher' : 'from watchers'}.
                    </p>
                    <p className="text-muted-foreground">
                        {intent.watching
                            ? 'They will receive permitted ticket updates while they retain access.'
                            : 'They will stop receiving updates through this watcher subscription.'}
                    </p>
                </div>
            )}
            {command.stage === 'reviewed' && command.reviewed && (
                <div className="space-y-2 rounded-lg border border-border bg-muted/30 p-3 text-sm">
                    <h4 className="font-semibold">
                        Current watchers · version {command.reviewed.version}
                    </h4>
                    <p>
                        {reviewedWatching
                            ? 'The selected person is currently watching.'
                            : 'The selected person is not currently watching.'}
                    </p>
                    {!eligible && (
                        <p role="alert">
                            This person is no longer eligible to be added. Keep
                            the current watchers or choose another person.
                        </p>
                    )}
                    <p className="text-muted-foreground">
                        {command.reviewed.watchers
                            .map((row) => row.name)
                            .join(', ') || 'No watchers.'}
                    </p>
                </div>
            )}
            {command.acknowledgement && (
                <p role="status" className="text-sm">
                    {command.acknowledgement.changed
                        ? command.acknowledgement.watching
                            ? 'The watcher was added.'
                            : 'The watcher was removed.'
                        : command.acknowledgement.watching
                          ? 'This person was already watching. No change was needed.'
                          : 'This person was already removed. No change was needed.'}
                </p>
            )}
            <DialogFooter className="flex-wrap gap-2">
                {command.busy ? (
                    <Button
                        type="button"
                        variant="outline"
                        onClick={command.cancelWait}
                    >
                        Cancel wait
                    </Button>
                ) : (
                    <>
                        <Button
                            type="button"
                            variant="outline"
                            onClick={command.close}
                        >
                            {command.stage === 'done'
                                ? 'Done'
                                : command.pending && command.stage === 'unknown'
                                  ? 'Close and review later'
                                  : 'Close'}
                        </Button>
                        {command.stage === 'editing' && (
                            <Button
                                type="button"
                                onClick={() => void command.send()}
                            >
                                {intent?.watching
                                    ? 'Add watcher'
                                    : 'Remove watcher'}
                            </Button>
                        )}
                        {command.stage === 'unknown' && (
                            <Button
                                type="button"
                                onClick={() => void command.send()}
                            >
                                Retry exact change
                            </Button>
                        )}
                        {['unknown', 'conflict', 'session'].includes(
                            command.stage,
                        ) && (
                            <Button
                                type="button"
                                variant="outline"
                                onClick={() => void command.review()}
                            >
                                Review current watchers
                            </Button>
                        )}
                        {command.stage === 'reviewed' && (
                            <>
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={command.finishReview}
                                >
                                    Keep current watchers
                                </Button>
                                {eligible && (
                                    <Button
                                        type="button"
                                        onClick={command.adopt}
                                    >
                                        Use reviewed version
                                    </Button>
                                )}
                            </>
                        )}
                    </>
                )}
            </DialogFooter>
        </>
    );
}
