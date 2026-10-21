import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import { ChatError, chatRequest, conversationPath } from './api';
import type { ChatConversation } from './types';

function ChatManagementSession({
    conversation,
    actorId,
    onChanged,
    onExit,
}: {
    conversation: ChatConversation;
    actorId: number;
    onChanged: () => void;
    onExit: () => void;
}) {
    const [snapshot, setSnapshot] = useState<ChatConversation | null>(null);
    const [members, setMembers] = useState(false);
    const [name, setName] = useState('');
    const [owner, setOwner] = useState('');
    const [remove, setRemove] = useState<number | null>(null);
    const [confirmed, setConfirmed] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [stale, setStale] = useState(false);
    const active = useRef(true);
    useEffect(() => {
        active.current = true;
        return () => {
            active.current = false;
        };
    }, []);
    const current = snapshot ?? conversation;
    const rights = current.management;
    function open() {
        setSnapshot(conversation);
        setMembers(false);
        setName(conversation.title);
        setOwner('');
        setRemove(null);
        setConfirmed(false);
        setError('');
        setStale(false);
    }
    async function save(
        path: 'preferences' | 'management',
        body: Record<string, unknown>,
        exit = false,
    ) {
        if (busy || stale) return;
        setBusy(true);
        setError('');
        try {
            await chatRequest(`${conversationPath(current.id)}/${path}`, {
                method: 'PATCH',
                headers: { 'X-Chat-Actor': String(actorId) },
                body: JSON.stringify(
                    path === 'management'
                        ? { ...body, version: current.version }
                        : body,
                ),
            });
            if (!active.current) return;
            setSnapshot(null);
            onChanged();
            window.dispatchEvent(new Event('oblivion:chat-unread-changed'));
            if (exit) onExit();
            toast.success(
                body.mark_unread
                    ? 'Marked unread for you. Existing receipts stay unchanged.'
                    : 'Conversation updated.',
            );
        } catch (failure) {
            if (!active.current) return;
            setError((failure as Error).message);
            if (failure instanceof ChatError && failure.status === 409) {
                setStale(true);
                onChanged();
            }
        } finally {
            if (active.current) setBusy(false);
        }
    }
    return (
        <>
            <Button
                variant="outline"
                className="min-h-11 justify-start"
                onClick={open}
            >
                Manage conversation
            </Button>
            <Dialog
                open={!!snapshot}
                onOpenChange={(value) => {
                    if (!value && !busy) setSnapshot(null);
                }}
            >
                <DialogContent
                    style={{
                        maxWidth: 'min(92vw, 480px)',
                        width: 'min(92vw, 480px)',
                    }}
                    className="max-h-[85dvh] overflow-x-hidden overflow-y-auto"
                >
                    <DialogHeader>
                        <DialogTitle>
                            {members
                                ? current.type === 'family'
                                    ? 'Conversation members'
                                    : 'Membership and ownership'
                                : 'Manage conversation'}
                        </DialogTitle>
                        <DialogDescription>{current.title}</DialogDescription>
                    </DialogHeader>
                    {error && (
                        <p
                            role="alert"
                            className="text-sm text-status-critical"
                        >
                            {error}
                            {stale &&
                                ' Close and reopen to review the latest details.'}
                        </p>
                    )}
                    <fieldset
                        disabled={busy || stale}
                        className="min-w-0 space-y-4"
                    >
                        {!members ? (
                            <>
                                <div className="grid grid-cols-2 gap-2">
                                    <Button
                                        variant="outline"
                                        className="min-h-11 whitespace-normal"
                                        onClick={() =>
                                            void save('preferences', {
                                                is_favourite:
                                                    !current.favourite,
                                            })
                                        }
                                    >
                                        {current.favourite
                                            ? 'Remove favourite'
                                            : 'Favourite'}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={() =>
                                            void save(
                                                'preferences',
                                                {
                                                    mark_unread:
                                                        !current.unread_marker,
                                                },
                                                !current.unread_marker,
                                            )
                                        }
                                    >
                                        {current.unread_marker
                                            ? 'Clear reminder'
                                            : 'Mark unread'}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="min-h-11"
                                        onClick={() =>
                                            void save('preferences', {
                                                is_muted: !current.muted,
                                            })
                                        }
                                    >
                                        {current.muted ? 'Unmute' : 'Mute'}
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className="min-h-11 whitespace-normal"
                                        onClick={() =>
                                            void save(
                                                'preferences',
                                                {
                                                    is_archived_for_user:
                                                        !current.archived_for_me,
                                                },
                                                !current.archived_for_me,
                                            )
                                        }
                                    >
                                        {current.archived_for_me
                                            ? 'Unarchive for me'
                                            : 'Archive for me'}
                                    </Button>
                                </div>
                                <p className="text-xs text-muted-foreground">
                                    These preferences affect only your inbox.
                                    Archiving keeps the conversation and its
                                    files.
                                </p>
                                {current.archived && (
                                    <p className="text-sm text-muted-foreground">
                                        This conversation is closed for
                                        everyone. Personal inbox changes do not
                                        reopen it.
                                    </p>
                                )}
                                {(rights?.can_leave || rights?.can_remove) && (
                                    <Button
                                        variant="outline"
                                        className="min-h-11 w-full"
                                        onClick={() => setMembers(true)}
                                    >
                                        {current.type === 'family'
                                            ? 'Conversation members'
                                            : 'Membership and ownership'}
                                    </Button>
                                )}
                                {rights?.can_rename && (
                                    <label className="block space-y-2 text-sm font-medium">
                                        Conversation name
                                        <Input
                                            value={name}
                                            onChange={(event) =>
                                                setName(event.target.value)
                                            }
                                            maxLength={200}
                                            className="min-h-11"
                                        />
                                    </label>
                                )}
                            </>
                        ) : (
                            <>
                                <ul className="space-y-2">
                                    {current.participants.map((person) => (
                                        <li
                                            key={person.id}
                                            className="flex min-h-11 items-center gap-2 text-sm"
                                        >
                                            <span className="min-w-0 flex-1 break-words">
                                                {person.name}
                                                {person.id === actorId
                                                    ? ' · You'
                                                    : ''}
                                            </span>
                                            {rights?.owner_ids.includes(
                                                person.id,
                                            ) ? (
                                                <span className="text-xs text-muted-foreground">
                                                    Owner
                                                </span>
                                            ) : (
                                                rights?.can_remove &&
                                                (rights.removable_ids?.includes(
                                                    person.id,
                                                ) ??
                                                    current.type !==
                                                        'family') &&
                                                person.id !== actorId && (
                                                    <Button
                                                        variant="outline"
                                                        className="min-h-11"
                                                        onClick={() => {
                                                            setRemove(
                                                                person.id,
                                                            );
                                                            setConfirmed(false);
                                                        }}
                                                    >
                                                        Remove
                                                    </Button>
                                                )
                                            )}
                                        </li>
                                    ))}
                                </ul>
                                {remove !== null && (
                                    <div className="space-y-2 rounded-xl border p-3">
                                        <p className="text-sm">
                                            Remove{' '}
                                            {
                                                current.participants.find(
                                                    (person) =>
                                                        person.id === remove,
                                                )?.name
                                            }
                                            ? They will lose access to this
                                            conversation, its files and calls.
                                        </p>
                                        <label className="flex min-h-11 items-center gap-2 text-sm">
                                            <Checkbox
                                                checked={confirmed}
                                                onCheckedChange={(checked) =>
                                                    setConfirmed(
                                                        checked === true,
                                                    )
                                                }
                                            />
                                            I have reviewed this removal.
                                        </label>
                                        <div className="flex flex-wrap gap-2">
                                            <Button
                                                variant="outline"
                                                className="min-h-11"
                                                onClick={() => setRemove(null)}
                                            >
                                                Cancel removal
                                            </Button>
                                            <Button
                                                variant="destructive"
                                                className="min-h-11"
                                                disabled={!confirmed}
                                                onClick={() =>
                                                    void save('management', {
                                                        action: 'remove',
                                                        user_id: remove,
                                                    })
                                                }
                                            >
                                                Remove member
                                            </Button>
                                        </div>
                                    </div>
                                )}
                                {rights?.can_transfer && (
                                    <div className="block space-y-2 text-sm font-medium">
                                        <span className="block">
                                            Transfer ownership before leaving
                                        </span>
                                        {/* An empty owner shows the placeholder. */}
                                        <Select
                                            value={owner}
                                            onValueChange={setOwner}
                                        >
                                            <SelectTrigger
                                                aria-label="New owner"
                                                className="min-h-11 w-full rounded-lg bg-background"
                                            >
                                                <SelectValue placeholder="Choose an eligible member" />
                                            </SelectTrigger>
                                            <SelectContent>
                                                {current.participants
                                                    .filter((person) =>
                                                        rights.eligible_owner_ids.includes(
                                                            person.id,
                                                        ),
                                                    )
                                                    .map((person) => (
                                                        <SelectItem
                                                            key={person.id}
                                                            value={String(
                                                                person.id,
                                                            )}
                                                        >
                                                            {person.name}
                                                        </SelectItem>
                                                    ))}
                                            </SelectContent>
                                        </Select>
                                    </div>
                                )}
                                {rights?.must_transfer_to_leave && (
                                    <p className="text-sm text-muted-foreground">
                                        Choose a new owner before leaving.
                                        Remaining members keep the conversation.
                                    </p>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {rights?.can_transfer && (
                                        <Button
                                            variant="outline"
                                            className="min-h-11"
                                            disabled={!owner}
                                            onClick={() =>
                                                void save('management', {
                                                    action: 'transfer',
                                                    user_id: Number(owner),
                                                })
                                            }
                                        >
                                            Transfer ownership
                                        </Button>
                                    )}
                                    {rights?.can_leave && (
                                        <Button
                                            variant="destructive"
                                            className="min-h-11"
                                            disabled={
                                                rights.must_transfer_to_leave &&
                                                !owner
                                            }
                                            onClick={() =>
                                                void save(
                                                    'management',
                                                    {
                                                        action: 'leave',
                                                        ...(owner
                                                            ? {
                                                                  user_id:
                                                                      Number(
                                                                          owner,
                                                                      ),
                                                              }
                                                            : {}),
                                                    },
                                                    true,
                                                )
                                            }
                                        >
                                            {owner
                                                ? 'Transfer and leave'
                                                : 'Leave conversation'}
                                        </Button>
                                    )}
                                </div>
                            </>
                        )}
                    </fieldset>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            className="min-h-11"
                            disabled={busy}
                            onClick={() => {
                                if (members) setMembers(false);
                                else setSnapshot(null);
                            }}
                        >
                            {members ? 'Back' : 'Close'}
                        </Button>
                        {!members && rights?.can_rename && (
                            <Button
                                className="min-h-11"
                                disabled={busy || stale || !name.trim()}
                                onClick={() =>
                                    void save('management', {
                                        action: 'rename',
                                        title: name.trim(),
                                    })
                                }
                            >
                                {busy ? 'Saving…' : 'Save name'}
                            </Button>
                        )}
                    </DialogFooter>
                    {busy && (
                        <p
                            role="status"
                            className="text-sm text-muted-foreground"
                        >
                            Saving changes…
                        </p>
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

export function ChatManagement(
    props: Parameters<typeof ChatManagementSession>[0],
) {
    return (
        <ChatManagementSession
            key={`${props.actorId}:${props.conversation.id}`}
            {...props}
        />
    );
}
