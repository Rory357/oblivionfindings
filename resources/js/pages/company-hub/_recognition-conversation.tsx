import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatRelative } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import {
    AtSign,
    ImagePlus,
    Loader2,
    MessageCircle,
    Pencil,
    RefreshCw,
    Send,
    Shield,
    Trash2,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest, HubRequestError } from './_api';
import { HubPersonAvatar } from './_person-avatar';
import type { HubKudos } from './_types';

type Mention = { id: number; name: string; profile_url?: string | null };
type Picture = { id: number; url: string; name?: string };
type Reply = HubKudos['replies'][number] & {
    parent_id?: number | null;
    edited_at?: string | null;
    hidden?: boolean;
    removed?: boolean;
    can_edit?: boolean;
    can_moderate?: boolean;
    mentions?: Mention[];
    images?: Picture[];
};
export type SocialKudos = HubKudos & {
    mentions?: Mention[];
    images?: Picture[];
    avatar_url?: string | null;
    recipient_avatar_url?: string | null;
};

export function RecognitionMedia({
    images = [],
    mentions = [],
}: {
    images?: Picture[];
    mentions?: Mention[];
}) {
    return (
        <>
            {!!mentions.length && (
                <div className="flex flex-wrap gap-2 py-2 text-sm">
                    {mentions.map((person) =>
                        person.profile_url ? (
                            <Link
                                key={person.id}
                                href={person.profile_url}
                                className="font-medium text-primary hover:underline"
                            >
                                @{person.name}
                            </Link>
                        ) : (
                            <span
                                key={person.id}
                                className="font-medium text-primary"
                            >
                                @{person.name}
                            </span>
                        ),
                    )}
                </div>
            )}
            {!!images.length && (
                <div
                    className={`hub-social-pictures ${images.length === 1 ? 'hub-social-picture-single' : ''}`}
                >
                    {images.map((picture) => (
                        <a
                            key={picture.id}
                            href={picture.url}
                            target="_blank"
                            rel="noopener noreferrer"
                            aria-label={`Open ${picture.name || 'attached picture'}`}
                        >
                            <img
                                src={picture.url}
                                alt={
                                    picture.name ||
                                    'Picture shared with this shout-out'
                                }
                                loading="lazy"
                            />
                        </a>
                    ))}
                </div>
            )}
        </>
    );
}

export function RecognitionConversation({
    kudos,
    actorId,
    writable,
    onChange,
    onDraftChange,
}: {
    kudos: SocialKudos;
    actorId?: number;
    writable: boolean;
    onChange: (value: SocialKudos) => void;
    onDraftChange?: (dirty: boolean) => void;
}) {
    const [record, setRecord] = useState(kudos);
    const [body, setBody] = useState('');
    const [parent, setParent] = useState<Reply | null>(null);
    const [editing, setEditing] = useState<Reply | null>(null);
    const [confirmation, setConfirmation] = useState<{
        reply: Reply;
        action: 'remove' | 'moderate';
    } | null>(null);
    const [reason, setReason] = useState('');
    const [images, setImages] = useState<File[]>([]);
    const [mentions, setMentions] = useState<Mention[]>([]);
    const [people, setPeople] = useState<Mention[]>([]);
    const [mentionOpen, setMentionOpen] = useState(false);
    const [search, setSearch] = useState('');
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [notice, setNotice] = useState('');
    const [uncertain, setUncertain] = useState(false);
    const mounted = useRef(true),
        locked = useRef(false),
        textarea = useRef<HTMLTextAreaElement>(null);
    const base = `/company-hub/recognition/${kudos.id}`;
    const dirty = !!body.trim() || !!images.length || !!mentions.length;
    const draftListener = useRef(onDraftChange);
    draftListener.current = onDraftChange;
    useEffect(() => {
        draftListener.current?.(dirty || busy);
    }, [dirty, busy]);
    useEffect(
        () => () => {
            draftListener.current?.(false);
        },
        [],
    );
    const leave = useSettingsLeaveConfirmation(
        dirty || busy,
        'Leave this comment?',
        'Your comment has not been saved. Keep writing, or discard it before leaving.',
        undefined,
        busy,
    );
    useEffect(() => {
        mounted.current = true;
        return () => {
            mounted.current = false;
        };
    }, []);
    useEffect(() => {
        setRecord(kudos);
    }, [kudos]);
    const accept = (next: SocialKudos) => {
        if (next.id !== kudos.id || !Array.isArray(next.replies))
            throw new Error(
                'The conversation changed. Refresh it before continuing.',
            );
        if (!mounted.current) return;
        setRecord(next);
        onChange(next);
    };
    const reset = () => {
        setBody('');
        setParent(null);
        setEditing(null);
        setImages([]);
        setMentions([]);
        setMentionOpen(false);
    };
    const run = async (
        operation: () => Promise<SocialKudos>,
        success: string,
        clear = false,
    ) => {
        if (locked.current) return;
        locked.current = true;
        setBusy(true);
        setError('');
        setNotice('');
        try {
            const next = await operation();
            if (!mounted.current) return;
            accept(next);
            setUncertain(false);
            if (clear) reset();
            setConfirmation(null);
            setReason('');
            setNotice(success);
        } catch (cause) {
            if (mounted.current) {
                setError(
                    cause instanceof Error
                        ? cause.message
                        : 'The conversation could not be updated.',
                );
                if (
                    !(cause instanceof HubRequestError) ||
                    cause.status === 409 ||
                    cause.status >= 500
                )
                    setUncertain(true);
            }
        } finally {
            locked.current = false;
            if (mounted.current) setBusy(false);
        }
    };
    const refresh = () =>
        run(async () => {
            const next = await hubRequest<SocialKudos>(
                `${base}/conversation`,
                'GET',
            );
            if (mounted.current && editing)
                setEditing(
                    next.replies.find((reply) => reply.id === editing.id) ?? {
                        ...editing,
                        can_edit: false,
                    },
                );
            if (mounted.current && parent)
                setParent(
                    next.replies.find((reply) => reply.id === parent.id) ?? {
                        ...parent,
                        removed: true,
                    },
                );
            return next;
        }, 'Conversation refreshed. Review the latest comments before sending again.');
    const submit = () => {
        if (
            !actorId ||
            !writable ||
            !body.trim() ||
            uncertain ||
            !record.can_reply ||
            parent?.hidden ||
            parent?.removed ||
            (editing && !editing.can_edit)
        )
            return;
        if (editing)
            void run(
                () =>
                    hubRequest<SocialKudos>(
                        `${base}/replies/${editing.id}`,
                        'PATCH',
                        {
                            actor_id: actorId,
                            version: editing.version,
                            body: body.trim(),
                            mention_ids: mentions.map((p) => p.id),
                        },
                    ),
                'Your comment was updated.',
                true,
            );
        else {
            const form = new FormData();
            form.set('actor_id', String(actorId));
            form.set('body', body.trim());
            if (parent) form.set('parent_id', String(parent.id));
            mentions.forEach((p) => form.append('mention_ids[]', String(p.id)));
            images.forEach((file) => form.append('images[]', file));
            void run(
                () => hubRequest<SocialKudos>(`${base}/replies`, 'POST', form),
                'Your comment was posted.',
                true,
            );
        }
    };
    const openMentions = async () => {
        setMentionOpen(!mentionOpen);
        if (mentionOpen || people.length) return;
        try {
            const result = await hubRequest<{
                actor_id: number;
                people: Mention[];
            }>(`${base}/mention-options`, 'GET');
            if (mounted.current && result.actor_id === actorId)
                setPeople(result.people);
        } catch {
            if (mounted.current)
                setError(
                    'People could not be loaded. Your comment is still here; try mentioning someone again.',
                );
        }
    };
    const replies = record.replies as Reply[];
    const renderReply = (
        reply: Reply,
        depth = 0,
        seen: number[] = [],
    ): React.ReactNode => {
        if (seen.includes(reply.id)) return null;
        const children = replies.filter(
            (child) => child.parent_id === reply.id,
        );
        return (
            <div
                key={reply.id}
                className={
                    depth ? 'hub-social-thread-child' : 'hub-social-thread'
                }
            >
                <div className="flex items-start gap-2.5">
                    <HubPersonAvatar
                        name={reply.name}
                        url={reply.avatar_url}
                        className="size-8 text-xs"
                    />
                    <div className="min-w-0 flex-1">
                        <div
                            className={`hub-social-comment ${reply.hidden ? 'opacity-65' : ''}`}
                        >
                            <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                                <strong className="text-sm">
                                    {reply.name}
                                </strong>
                                <span className="text-[11px] text-muted-foreground">
                                    {reply.created_at &&
                                        formatRelative(reply.created_at)}
                                    {reply.edited_at ? ' · Edited' : ''}
                                    {reply.hidden
                                        ? ' · Hidden by moderator'
                                        : ''}
                                </span>
                            </div>
                            <p className="mt-1 text-sm break-words whitespace-pre-wrap">
                                {reply.body ??
                                    (reply.removed
                                        ? 'This comment was removed by its author.'
                                        : 'This comment is hidden.')}
                            </p>
                            <RecognitionMedia
                                images={reply.images}
                                mentions={reply.mentions}
                            />
                        </div>
                        <div className="flex flex-wrap items-center gap-1 pt-1">
                            {writable &&
                                record.can_reply &&
                                !reply.hidden &&
                                !reply.removed && (
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        className="min-h-11 px-2 text-xs"
                                        disabled={busy}
                                        onClick={() =>
                                            leave.request(() => {
                                                reset();
                                                setParent(reply);
                                                textarea.current?.focus();
                                            })
                                        }
                                    >
                                        <MessageCircle className="size-3" />{' '}
                                        Reply
                                    </Button>
                                )}
                            {writable && reply.can_edit && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="min-h-11 px-2 text-xs"
                                    disabled={busy}
                                    onClick={() =>
                                        leave.request(() => {
                                            reset();
                                            setEditing(reply);
                                            setBody(reply.body ?? '');
                                            setMentions(reply.mentions ?? []);
                                            textarea.current?.focus();
                                        })
                                    }
                                >
                                    <Pencil className="size-3" /> Edit
                                </Button>
                            )}
                            {writable && reply.can_remove && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="min-h-11 px-2 text-xs"
                                    disabled={busy}
                                    onClick={() =>
                                        setConfirmation({
                                            reply,
                                            action: 'remove',
                                        })
                                    }
                                >
                                    <Trash2 className="size-3" /> Remove
                                </Button>
                            )}
                            {reply.can_moderate && actorId && (
                                <Button
                                    size="sm"
                                    variant="ghost"
                                    className="min-h-11 px-2 text-xs"
                                    disabled={busy}
                                    onClick={() => {
                                        setReason('');
                                        setConfirmation({
                                            reply,
                                            action: 'moderate',
                                        });
                                    }}
                                >
                                    <Shield className="size-3" />{' '}
                                    {reply.hidden ? 'Restore' : 'Hide'}
                                </Button>
                            )}
                        </div>
                    </div>
                </div>
                {children.length > 0 && (
                    <div className={depth < 2 ? 'ml-5 border-l pl-3' : 'pl-0'}>
                        {children.map((child) =>
                            renderReply(child, depth + 1, [...seen, reply.id]),
                        )}
                    </div>
                )}
            </div>
        );
    };
    return (
        <div className="space-y-3">
            {replies
                .filter(
                    (reply) =>
                        !reply.parent_id ||
                        !replies.some((other) => other.id === reply.parent_id),
                )
                .map((reply) => renderReply(reply))}
            {!replies.length && (
                <p className="py-2 text-sm text-muted-foreground">
                    A few kind words can make someone’s day. Start the
                    conversation.
                </p>
            )}
            {confirmation && (
                <div className="space-y-2 rounded-xl border bg-muted/40 p-3">
                    <p className="text-sm font-medium">
                        {confirmation.action === 'remove'
                            ? 'Remove your comment? Replies will remain in the conversation.'
                            : `${confirmation.reply.hidden ? 'Restore' : 'Hide'} this comment?`}
                    </p>
                    {confirmation.action === 'moderate' && (
                        <Input
                            aria-label="Moderation reason"
                            placeholder="Brief reason for this decision"
                            maxLength={500}
                            value={reason}
                            onChange={(e) => setReason(e.target.value)}
                        />
                    )}
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={busy}
                            onClick={() => setConfirmation(null)}
                        >
                            Cancel
                        </Button>
                        <Button
                            size="sm"
                            disabled={
                                busy ||
                                uncertain ||
                                (confirmation.action === 'moderate' &&
                                    !reason.trim())
                            }
                            onClick={() => {
                                const { reply, action } = confirmation;
                                void run(
                                    () =>
                                        hubRequest<SocialKudos>(
                                            `${base}/replies/${reply.id}${action === 'moderate' ? '/moderation' : ''}`,
                                            action === 'moderate'
                                                ? 'PATCH'
                                                : 'DELETE',
                                            {
                                                actor_id: actorId,
                                                version: reply.version,
                                                ...(action === 'moderate'
                                                    ? {
                                                          hidden: !reply.hidden,
                                                          reason: reason.trim(),
                                                      }
                                                    : {}),
                                            },
                                        ),
                                    action === 'remove'
                                        ? 'Comment removed.'
                                        : 'Moderation saved.',
                                );
                            }}
                        >
                            Confirm
                        </Button>
                    </div>
                </div>
            )}
            {writable && actorId && record.can_reply && (
                <div className="hub-social-composer">
                    {(parent || editing) && (
                        <div className="flex items-center justify-between px-3 pt-2 text-xs text-primary">
                            <span>
                                {editing
                                    ? 'Editing your comment'
                                    : `Replying to ${parent?.name}`}
                            </span>
                            <Button
                                size="icon"
                                variant="ghost"
                                className="size-7"
                                aria-label="Cancel comment editing"
                                disabled={busy}
                                onClick={() => leave.request(reset)}
                            >
                                <X className="size-3.5" />
                            </Button>
                        </div>
                    )}
                    <Textarea
                        ref={textarea}
                        aria-label={
                            editing
                                ? 'Edit your comment'
                                : parent
                                  ? `Reply to ${parent.name}`
                                  : 'Write a comment'
                        }
                        placeholder="Add a little encouragement…"
                        value={body}
                        onChange={(e) => setBody(e.target.value)}
                        maxLength={2000}
                        disabled={busy}
                        className="min-h-20 resize-y border-0 bg-transparent shadow-none focus-visible:ring-0"
                    />
                    {!!mentions.length && (
                        <div className="flex flex-wrap gap-1 px-3">
                            {mentions.map((person) => (
                                <Button
                                    key={person.id}
                                    size="sm"
                                    variant="secondary"
                                    disabled={busy}
                                    onClick={() =>
                                        setMentions(
                                            mentions.filter(
                                                (p) => p.id !== person.id,
                                            ),
                                        )
                                    }
                                >
                                    @{person.name}
                                    <X className="size-3" />
                                </Button>
                            ))}
                        </div>
                    )}
                    {!!images.length && (
                        <div className="flex flex-wrap gap-2 px-3">
                            {images.map((file, index) => (
                                <Button
                                    key={`${file.name}-${index}`}
                                    variant="secondary"
                                    size="sm"
                                    disabled={busy}
                                    onClick={() =>
                                        setImages(
                                            images.filter(
                                                (_, i) => i !== index,
                                            ),
                                        )
                                    }
                                >
                                    {file.name}
                                    <X className="size-3" />
                                </Button>
                            ))}
                        </div>
                    )}
                    {mentionOpen && (
                        <Card
                            unstyled
                            className="m-2 space-y-2 rounded-lg border bg-card p-2"
                        >
                            <Input
                                aria-label="Find a person to mention"
                                value={search}
                                onChange={(e) => setSearch(e.target.value)}
                                placeholder="Find a colleague…"
                            />
                            <div className="max-h-40 overflow-auto">
                                {people
                                    .filter(
                                        (p) =>
                                            p.name
                                                .toLowerCase()
                                                .includes(
                                                    search.toLowerCase(),
                                                ) &&
                                            !mentions.some(
                                                (m) => m.id === p.id,
                                            ),
                                    )
                                    .slice(0, 30)
                                    .map((person) => (
                                        <Button
                                            key={person.id}
                                            variant="ghost"
                                            size="sm"
                                            className="flex w-full justify-start"
                                            disabled={
                                                busy || mentions.length >= 10
                                            }
                                            onClick={() => {
                                                setMentions([
                                                    ...mentions,
                                                    person,
                                                ]);
                                                setMentionOpen(false);
                                                setSearch('');
                                                textarea.current?.focus();
                                            }}
                                        >
                                            {person.name}
                                        </Button>
                                    ))}
                                {!people.length && (
                                    <p className="p-2 text-xs text-muted-foreground">
                                        No people available to mention in this
                                        conversation.
                                    </p>
                                )}
                            </div>
                        </Card>
                    )}
                    <div className="flex items-center gap-1 border-t px-2 py-1.5">
                        {dirty && !parent && !editing && (
                            <Button
                                variant="ghost"
                                size="sm"
                                disabled={busy}
                                onClick={() => leave.request(reset)}
                            >
                                Cancel
                            </Button>
                        )}
                        <Button
                            variant="ghost"
                            size="icon"
                            aria-label="Mention a colleague"
                            disabled={busy}
                            onClick={() => void openMentions()}
                        >
                            <AtSign className="size-4" />
                        </Button>
                        {!editing && (
                            <label
                                className="inline-flex size-11 cursor-pointer items-center justify-center rounded-md hover:bg-muted"
                                title="Add pictures"
                            >
                                <ImagePlus className="size-4" />
                                <input
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    multiple
                                    aria-label="Attach comment pictures"
                                    disabled={busy || images.length >= 4}
                                    className="sr-only"
                                    onChange={(e) => {
                                        const files = Array.from(
                                            e.target.files ?? [],
                                        );
                                        if (
                                            images.length + files.length > 4 ||
                                            files.some(
                                                (f) =>
                                                    ![
                                                        'image/jpeg',
                                                        'image/png',
                                                        'image/webp',
                                                    ].includes(f.type) ||
                                                    f.size > 5 * 1024 * 1024,
                                            )
                                        )
                                            setError(
                                                'Choose up to four JPG, PNG or WebP pictures, each under 5 MB.',
                                            );
                                        else {
                                            setImages([...images, ...files]);
                                            setError('');
                                        }
                                        e.target.value = '';
                                    }}
                                />
                            </label>
                        )}
                        <span className="ml-auto text-[11px] text-muted-foreground">
                            {body.length}/2000
                        </span>
                        <Button
                            size="sm"
                            className="ml-2"
                            disabled={
                                busy ||
                                uncertain ||
                                !body.trim() ||
                                !!parent?.hidden ||
                                !!parent?.removed ||
                                !!(editing && !editing.can_edit)
                            }
                            onClick={submit}
                        >
                            {busy ? (
                                <Loader2 className="size-4 animate-spin" />
                            ) : (
                                <Send className="size-4" />
                            )}
                            {editing ? 'Save changes' : 'Post'}
                        </Button>
                    </div>
                </div>
            )}
            {!record.can_reply && (
                <p className="text-xs text-muted-foreground">
                    You can read this conversation. Posting is not available
                    with your current permissions.
                </p>
            )}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {uncertain && (
                <p className="text-xs text-muted-foreground">
                    Your text is kept here. Refresh and check whether the last
                    action saved before trying again.
                </p>
            )}
            {editing && !editing.can_edit && (
                <p role="status" className="text-xs text-muted-foreground">
                    This comment can no longer be edited. Your text is kept here
                    so you can copy it before cancelling.
                </p>
            )}
            {(parent?.hidden || parent?.removed) && (
                <p role="status" className="text-xs text-muted-foreground">
                    The comment you were replying to is no longer available.
                    Your text is kept here; cancel the reply target before
                    starting a new comment.
                </p>
            )}
            <div className="flex items-center justify-between gap-2">
                <span role="status" className="text-xs text-muted-foreground">
                    {notice}
                </span>
                {actorId && (
                    <Button
                        size="sm"
                        variant="ghost"
                        disabled={busy}
                        onClick={refresh}
                    >
                        <RefreshCw className="size-3.5" /> Refresh conversation
                    </Button>
                )}
            </div>
            {leave.confirmation}
        </div>
    );
}
