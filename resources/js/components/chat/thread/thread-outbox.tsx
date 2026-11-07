import { Button } from '@/components/ui/button';
import { Loader2, Reply, WifiOff } from 'lucide-react';
import type { ChatOutboxEntry } from './chat-outbox';
import type { ThreadOutbox } from './use-thread-outbox';

function entryStatus(entry: ChatOutboxEntry, online: boolean) {
    if (entry.state === 'sending') return 'Sending…';
    if (entry.state === 'failed') return `Not sent. ${entry.error}`;
    if (entry.state === 'uncertain') return entry.error;
    return online ? 'Not sent yet' : 'Waiting for a connection';
}

/** Messages written offline, at the end of the conversation, each with Send, Edit and Remove. */
export function ThreadOutboxMessages({
    outbox,
    online,
    composerEmpty,
}: {
    outbox: ThreadOutbox;
    online: boolean;
    composerEmpty: boolean;
}) {
    if (!outbox.entries.length) return null;
    return (
        <ol aria-label="Messages waiting to send" className="mt-3 space-y-2">
            {outbox.entries.map((entry) => (
                <li
                    key={entry.id}
                    className="ml-auto w-fit max-w-[78%] space-y-1.5 rounded-2xl bg-primary/10 px-3 py-2"
                >
                    <p className="text-xs font-medium">You</p>
                    {entry.reply && (
                        <p className="flex items-center gap-1 rounded-lg border bg-background/60 px-2 py-1 text-xs text-muted-foreground">
                            <Reply
                                className="size-3 shrink-0"
                                aria-hidden="true"
                            />
                            <span className="truncate">
                                Replying to {entry.reply.sender_name}
                            </span>
                        </p>
                    )}
                    <p className="text-sm leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap">
                        {entry.content}
                    </p>
                    <p
                        role={entry.state === 'failed' ? 'alert' : undefined}
                        className="flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        {entry.state === 'sending' && (
                            <Loader2
                                className="size-3 shrink-0 animate-spin"
                                aria-hidden="true"
                            />
                        )}
                        {entryStatus(entry, online)}
                    </p>
                    {entry.state !== 'sending' && (
                        <div className="flex flex-wrap justify-end gap-1">
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                disabled={!composerEmpty}
                                title={
                                    composerEmpty
                                        ? undefined
                                        : 'Send or clear what you are writing first'
                                }
                                onClick={() => outbox.edit(entry)}
                            >
                                Edit
                            </Button>
                            <Button
                                type="button"
                                variant="ghost"
                                size="xs"
                                onClick={() => outbox.discard(entry)}
                            >
                                Remove
                            </Button>
                            {entry.state !== 'failed' && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    size="xs"
                                    disabled={!outbox.canSend}
                                    onClick={() => void outbox.send(entry)}
                                >
                                    Send
                                </Button>
                            )}
                        </div>
                    )}
                </li>
            ))}
        </ol>
    );
}

/** Above the composer: offline, or messages still waiting. Nothing is sent without a tap. */
export function ThreadOutboxBar({
    outbox,
    online,
}: {
    outbox: ThreadOutbox;
    online: boolean;
}) {
    const waiting = outbox.entries.filter(
        (entry) => entry.state !== 'failed',
    ).length;
    if (online && !outbox.entries.length) return null;
    return (
        <div
            role="status"
            className="mb-2 flex items-center gap-2 rounded-lg border bg-muted/40 p-2 text-xs"
        >
            {!online && (
                <WifiOff
                    className="size-4 shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />
            )}
            <p className="min-w-0 flex-1">
                {!online
                    ? outbox.entries.length
                        ? `You're offline. ${outbox.entries.length === 1 ? 'Your message waits' : `${outbox.entries.length} messages wait`} here until you're back online and send ${outbox.entries.length === 1 ? 'it' : 'them'}.`
                        : "You're offline. Messages you send now wait here until you're back online and send them."
                    : `${outbox.entries.length === 1 ? '1 message' : `${outbox.entries.length} messages`} not sent yet. Check, then send.`}
            </p>
            {online && waiting > 1 && (
                <Button
                    type="button"
                    variant="outline"
                    size="xs"
                    disabled={!outbox.canSend}
                    onClick={() => void outbox.sendAll()}
                >
                    Send all
                </Button>
            )}
        </div>
    );
}
