import { CommentAppreciation } from '@/components/company-hub/comment-appreciation';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import {
    TabsContent,
    TabsList,
    TabsRoot,
    TabsTrigger,
} from '@/components/ui/tabs';
import { Textarea } from '@/components/ui/textarea';
import { useSettingsLeaveConfirmation } from '@/hooks/use-settings-leave-confirmation';
import { formatRelative } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import {
    ArrowUpRight,
    EyeOff,
    MessageSquare,
    Reply,
    Send,
    X,
} from 'lucide-react';
import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { CommentActions, HiddenReview } from './_moderation';
import type { HubProps } from './_types';

type Activity = NonNullable<HubProps['participation']>;
type Comment = Activity['comments'][number];
type Props = {
    activity: Activity;
    title: string;
    commentsEnabled: boolean;
    demo?: boolean;
};

export function StoryDiscussion(props: Props) {
    return (
        <DiscussionPanel
            key={`${props.activity.actor_id}:${props.activity.page_id}:${props.activity.revision_id}:${props.commentsEnabled}:${props.demo}`}
            {...props}
        />
    );
}

function DiscussionPanel({ activity, title, commentsEnabled, demo }: Props) {
    const canModerate = !!(
        !demo &&
        activity.can_moderate &&
        activity.actor_id &&
        activity.revision_id
    );
    const [open, setOpen] = useState(
        () =>
            typeof window !== 'undefined' &&
            window.location.hash === '#hub-conversation',
    );
    const [tab, setTab] = useState('discussion');
    const [body, setBody] = useState('');
    const [replyTo, setReplyTo] = useState<Pick<Comment, 'id' | 'name'> | null>(
        null,
    );
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [saved, setSaved] = useState('');
    const [needsReload, setNeedsReload] = useState(false);
    const [refreshAvailable, setRefreshAvailable] = useState(false);
    const pending = useRef(false),
        alive = useRef(false);
    const draftDirty = useRef(false);
    const composer = useRef<HTMLTextAreaElement>(null);
    const dirty = !!body.trim();
    useLayoutEffect(() => {
        draftDirty.current = dirty;
    }, [dirty]);
    const navigation = useSettingsLeaveConfirmation(
        dirty || busy,
        'Leave this conversation?',
        'Your unfinished comment will be discarded. A comment already sent may still finish; check the conversation before posting it again.',
    );
    const replyAvailable =
        !replyTo ||
        activity.comments.some(
            (comment) => comment.id === replyTo.id && !comment.parent_id,
        );
    const canPost =
        !demo &&
        !!activity.actor_id &&
        !!activity.revision_id &&
        commentsEnabled;
    const activeTab =
        canModerate && (tab === 'hidden' || !commentsEnabled)
            ? 'hidden'
            : 'discussion';
    useEffect(() => {
        alive.current = true;
        const fromHash = () => {
            if (window.location.hash === '#hub-conversation') setOpen(true);
        };
        window.addEventListener('hashchange', fromHash);
        return () => {
            alive.current = false;
            window.removeEventListener('hashchange', fromHash);
        };
    }, []);
    const reloadConversation = () =>
        navigation.discardAndRun(() => {
            if (pending.current) return;
            pending.current = true;
            setBusy(true);
            // Keep the draft until the new, authorised page has actually arrived.
            router.reload({
                only: ['participation', 'selectedPage'],
                preserveScroll: true,
                onSuccess: () => {
                    if (!alive.current) return;
                    setBody('');
                    setReplyTo(null);
                    setNeedsReload(false);
                    setError('');
                    setSaved('');
                    setRefreshAvailable(false);
                },
                onFinish: () => {
                    if (!alive.current) return;
                    pending.current = false;
                    setBusy(false);
                },
            });
        });
    const refresh = () => navigation.request(reloadConversation);
    const updateConversation = () => {
        // This callback may have been captured before the user began writing.
        // Never replace the story/release underneath the current draft.
        if (pending.current || draftDirty.current) {
            setRefreshAvailable(true);
            return;
        }
        reloadConversation();
    };
    const submit = () => {
        if (
            pending.current ||
            !canPost ||
            !body.trim() ||
            !replyAvailable ||
            needsReload
        )
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        setSaved('');
        let outcome: 'unknown' | 'saved' | 'invalid' = 'unknown';
        router.post(
            `/company-hub/pages/${activity.page_id}/interactions`,
            {
                kind: 'comment',
                body: body.trim(),
                actor_id: activity.actor_id,
                revision_id: activity.revision_id,
                ...(replyTo ? { parent_id: replyTo.id } : {}),
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    if (!alive.current) return;
                    const confirmed = page.props.participation as
                        | Activity
                        | null
                        | undefined;
                    if (
                        confirmed?.actor_id !== activity.actor_id ||
                        confirmed?.page_id !== activity.page_id ||
                        confirmed?.revision_id !== activity.revision_id
                    )
                        return;
                    outcome = 'saved';
                    setBody('');
                    setReplyTo(null);
                    setRefreshAvailable(false);
                    setSaved(
                        replyTo
                            ? 'Your reply was posted.'
                            : 'Your comment was posted.',
                    );
                },
                onError: (errors) => {
                    if (!alive.current) return;
                    outcome = 'invalid';
                    setError(
                        Object.values(errors).join(' ') ||
                            'Your comment could not be posted. Please check your writing.',
                    );
                },
                onFinish: () => {
                    if (!alive.current) return;
                    pending.current = false;
                    setBusy(false);
                    if (outcome === 'unknown') {
                        setNeedsReload(true);
                        setError(
                            'We could not confirm your comment. Check the current conversation before posting again.',
                        );
                    }
                },
            },
        );
    };
    const reply = (comment: Comment) => {
        if (pending.current || needsReload) return;
        setReplyTo({ id: comment.id, name: comment.name });
        setSaved('');
        composer.current?.focus();
        composer.current?.scrollIntoView({
            block: 'nearest',
            behavior: 'smooth',
        });
    };
    const renderComment = (comment: Comment, child = false) => (
        <article
            key={comment.id}
            className="flex gap-3 py-4"
            aria-label={`${child ? 'Reply' : 'Comment'} by ${comment.name}`}
        >
            <span
                aria-hidden="true"
                className="flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-xs font-semibold text-primary"
            >
                {comment.name
                    .trim()
                    .split(/\s+/)
                    .slice(0, 2)
                    .map((part) => part[0])
                    .join('')}
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                    <h3 className="text-sm font-semibold">{comment.name}</h3>
                    <span className="text-xs text-muted-foreground">
                        {formatRelative(comment.created_at)}
                    </span>
                </div>
                <p className="mt-2 text-sm leading-6 break-words whitespace-pre-wrap">
                    {comment.body}
                </p>
                <div className="mt-2 flex flex-wrap items-start gap-1">
                    <CommentAppreciation
                        actorId={demo ? undefined : activity.actor_id}
                        pageId={activity.page_id}
                        revisionId={activity.revision_id}
                        comment={comment}
                        onSaved={updateConversation}
                    />
                    {!child && canPost && (
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={busy || needsReload}
                            onClick={() => reply(comment)}
                        >
                            <Reply className="size-3.5" />
                            Reply
                        </Button>
                    )}
                    {!demo && (
                        <CommentActions
                            key={`${comment.version}:${comment.can_hide}:${comment.can_remove}`}
                            activity={activity}
                            comment={comment}
                            reply={child}
                            onUpdated={updateConversation}
                        />
                    )}
                </div>
            </div>
        </article>
    );
    if (!commentsEnabled && !canModerate) return null;
    return (
        <>
            <section
                id="hub-conversation"
                aria-label="Story conversation"
                className="flex scroll-mt-24 items-center justify-between gap-4 border-t pt-5"
            >
                <div className="flex items-center gap-3">
                    <span className="flex size-10 items-center justify-center rounded-xl bg-primary/10 text-primary">
                        <MessageSquare className="size-5" />
                    </span>
                    <div>
                        <p className="text-sm font-semibold">
                            {commentsEnabled
                                ? `${activity.comments.length} ${activity.comments.length === 1 ? 'comment' : 'comments'}`
                                : 'Comments are closed'}
                        </p>
                        <p className="mt-0.5 text-xs text-muted-foreground">
                            {dirty
                                ? 'Your unfinished comment is waiting here.'
                                : 'Make room for a good conversation.'}
                        </p>
                    </div>
                </div>
                <Button variant="outline" onClick={() => setOpen(true)}>
                    {!commentsEnabled
                        ? 'Review hidden comments'
                        : dirty
                          ? 'Continue writing'
                          : activity.comments.length
                            ? 'View discussion'
                            : 'Join the discussion'}
                    <ArrowUpRight className="size-4" />
                </Button>
            </section>
            <Sheet
                open={open}
                onOpenChange={(next) => {
                    if (!pending.current) setOpen(next);
                }}
            >
                <SheetContent
                    side="right"
                    className="gap-0 overflow-hidden p-0 sm:max-w-[640px]"
                    overlayClassName="bg-black/30 backdrop-blur-[2px]"
                    closeLabel="Close discussion"
                    closeButtonClassName={
                        busy ? 'pointer-events-none opacity-30' : ''
                    }
                >
                    <SheetHeader className="shrink-0 border-b px-7 pt-7 pr-14 pb-6">
                        <p className="mb-1 text-xs font-semibold tracking-wide text-primary uppercase">
                            Story discussion
                        </p>
                        <SheetTitle className="text-2xl">
                            A conversation worth joining
                        </SheetTitle>
                        <SheetDescription className="mt-2 leading-6">
                            {title}
                        </SheetDescription>
                    </SheetHeader>
                    <TabsRoot
                        value={activeTab}
                        onValueChange={setTab}
                        className="flex min-h-0 flex-1 flex-col"
                    >
                        {canModerate && (
                            <TabsList
                                aria-label="Conversation views"
                                className="mx-7 mt-5 shrink-0 self-start"
                            >
                                <TabsTrigger
                                    value="discussion"
                                    disabled={busy || !commentsEnabled}
                                >
                                    Discussion
                                </TabsTrigger>
                                <TabsTrigger value="hidden" disabled={busy}>
                                    <EyeOff className="mr-2 size-3.5" />
                                    Hidden comments
                                    {activity.hidden_count
                                        ? ` (${activity.hidden_count})`
                                        : ''}
                                </TabsTrigger>
                            </TabsList>
                        )}
                        <div className="min-h-0 flex-1 overflow-y-auto px-7 py-5">
                            <TabsContent value="discussion" className="m-0">
                                <fieldset
                                    disabled={busy}
                                    className="min-w-0 divide-y border-b"
                                >
                                    <legend className="sr-only">
                                        Comments and replies
                                    </legend>
                                    {activity.comments
                                        .filter((comment) => !comment.parent_id)
                                        .map((comment) => (
                                            <div key={comment.id}>
                                                {renderComment(comment)}
                                                <div className="mb-2 ml-4 border-l pl-7">
                                                    {activity.comments
                                                        .filter(
                                                            (child) =>
                                                                child.parent_id ===
                                                                comment.id,
                                                        )
                                                        .map((child) =>
                                                            renderComment(
                                                                child,
                                                                true,
                                                            ),
                                                        )}
                                                </div>
                                            </div>
                                        ))}
                                    {!activity.comments.length && (
                                        <div className="py-10 text-center">
                                            <MessageSquare className="mx-auto mb-3 size-7 text-primary" />
                                            <h2 className="font-semibold">
                                                Start the conversation
                                            </h2>
                                            <p className="mx-auto mt-2 max-w-xs text-sm leading-6 text-muted-foreground">
                                                Share a thoughtful question, a
                                                useful idea or a little
                                                encouragement.
                                            </p>
                                        </div>
                                    )}
                                </fieldset>
                                {canPost ? (
                                    <form
                                        className="mt-6 space-y-3"
                                        onSubmit={(event) => {
                                            event.preventDefault();
                                            submit();
                                        }}
                                    >
                                        {replyTo && (
                                            <div className="flex items-center justify-between gap-3 rounded-lg bg-muted/60 px-3 py-2 text-sm">
                                                <span>
                                                    {replyAvailable
                                                        ? `Replying to ${replyTo.name}`
                                                        : 'This comment is no longer available.'}
                                                </span>
                                                <Button
                                                    type="button"
                                                    variant="ghost"
                                                    size="sm"
                                                    disabled={
                                                        busy || needsReload
                                                    }
                                                    onClick={() =>
                                                        setReplyTo(null)
                                                    }
                                                >
                                                    {replyAvailable ? (
                                                        <>
                                                            <X className="size-3.5" />
                                                            Cancel reply
                                                        </>
                                                    ) : (
                                                        'Use as a new comment'
                                                    )}
                                                </Button>
                                            </div>
                                        )}
                                        <Label className="grid gap-2">
                                            {replyTo
                                                ? `Reply to ${replyTo.name}`
                                                : 'Add a comment'}
                                            <Textarea
                                                ref={composer}
                                                rows={4}
                                                required
                                                maxLength={2000}
                                                disabled={busy}
                                                value={body}
                                                placeholder="What would you like to add?"
                                                onChange={(event) => {
                                                    setBody(event.target.value);
                                                    setSaved('');
                                                }}
                                            />
                                        </Label>
                                        <div className="flex items-center justify-between gap-3">
                                            <span className="text-xs text-muted-foreground">
                                                {body.length.toLocaleString()} /
                                                2,000
                                            </span>
                                            <Button
                                                type="submit"
                                                disabled={
                                                    busy ||
                                                    !dirty ||
                                                    !replyAvailable ||
                                                    needsReload
                                                }
                                            >
                                                <Send className="size-3.5" />
                                                {busy
                                                    ? 'Posting…'
                                                    : replyTo
                                                      ? 'Post reply'
                                                      : 'Post comment'}
                                            </Button>
                                        </div>
                                    </form>
                                ) : (
                                    <p className="mt-5 rounded-lg bg-muted/40 p-4 text-sm text-muted-foreground">
                                        {demo
                                            ? 'Comments are read-only in this preview.'
                                            : 'Comments are currently unavailable.'}
                                    </p>
                                )}
                                {saved && (
                                    <p
                                        role="status"
                                        className="mt-4 text-sm text-primary"
                                    >
                                        {saved}
                                    </p>
                                )}
                                {error && (
                                    <div
                                        role="alert"
                                        className="mt-4 space-y-3 rounded-lg border border-destructive/30 p-4 text-sm"
                                    >
                                        <p className="text-destructive">
                                            {error}
                                        </p>
                                        {needsReload && (
                                            <Button
                                                variant="outline"
                                                onClick={refresh}
                                            >
                                                Check current conversation
                                            </Button>
                                        )}
                                    </div>
                                )}
                            </TabsContent>
                            {canModerate && (
                                <TabsContent
                                    value="hidden"
                                    className="m-0 space-y-4"
                                >
                                    <p className="text-sm leading-6 text-muted-foreground">
                                        Visible only to moderators. Restoring a
                                        parent brings back only replies hidden
                                        with it. Author-removed text cannot be
                                        restored.
                                    </p>
                                    {open && activeTab === 'hidden' && (
                                        <HiddenReview
                                            activity={activity}
                                            onUpdated={updateConversation}
                                        />
                                    )}
                                </TabsContent>
                            )}
                            {refreshAvailable && !needsReload && (
                                <div
                                    role="status"
                                    className="mt-4 flex items-center justify-between gap-3 rounded-lg bg-muted/40 p-4 text-sm"
                                >
                                    <p>
                                        The conversation has updates. Your
                                        writing is still here.
                                    </p>
                                    <Button
                                        variant="outline"
                                        disabled={busy}
                                        onClick={refresh}
                                    >
                                        Refresh conversation
                                    </Button>
                                </div>
                            )}
                        </div>
                    </TabsRoot>
                    <footer className="flex shrink-0 items-center justify-between gap-4 border-t px-7 py-5">
                        <p className="max-w-xs text-xs leading-5 text-muted-foreground">
                            {dirty
                                ? 'Your draft stays here while this story is open.'
                                : 'Keep the conversation kind and useful.'}
                        </p>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setOpen(false)}
                        >
                            {dirty ? 'Keep draft and close' : 'Done'}
                        </Button>
                    </footer>
                </SheetContent>
            </Sheet>
            {navigation.confirmation}
        </>
    );
}
