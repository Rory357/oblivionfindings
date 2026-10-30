import { Button } from '@/components/ui/button';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { EyeOff, RotateCcw } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { hubRequest } from './_api';
import type { HubProps } from './_types';

type Activity = NonNullable<HubProps['participation']>;
type Comment = Activity['comments'][number];
type HiddenComment = {
    id: number;
    parent_id: number | null;
    name: string;
    body: string | null;
    created_at: string;
    version: number;
    hidden_reason:
        | 'moderator_hidden'
        | 'author_removed'
        | 'parent_hidden'
        | 'legacy_hidden';
    parent_hidden: boolean;
    hidden_at: string | null;
    can_restore: boolean;
};
type Review = {
    actor_id: number;
    page_id: number;
    revision_id: number;
    comments_enabled: boolean;
    hidden_comments: HiddenComment[];
};
const context = (activity: Activity) => ({
    actor_id: activity.actor_id,
    revision_id: activity.revision_id,
});
const matches = (result: Review, activity: Activity) =>
    result.actor_id === activity.actor_id &&
    result.page_id === activity.page_id &&
    result.revision_id === activity.revision_id;

export function CommentActions({
    activity,
    comment,
    reply = false,
    onUpdated,
}: {
    activity: Activity;
    comment: Comment;
    reply?: boolean;
    onUpdated?: () => void;
}) {
    const [confirm, setConfirm] = useState<'hide' | 'remove' | null>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const kind = reply ? 'reply' : 'comment';
    const hide = async () => {
        setBusy(true);
        setError('');
        try {
            const result = await hubRequest<Review>(
                `/company-hub/pages/${activity.page_id}/interactions/${comment.id}/moderation`,
                'PUT',
                {
                    ...context(activity),
                    version: comment.version,
                    hidden: true,
                },
            );
            if (!alive.current) return;
            if (!matches(result, activity))
                throw new Error(
                    'The page or your account changed. Reload before continuing.',
                );
            setConfirm(null);
            if (onUpdated) onUpdated();
            else
                router.reload({
                    only: ['participation'],
                    preserveScroll: true,
                });
        } catch (failure) {
            if (alive.current) setError((failure as Error).message);
        } finally {
            if (alive.current) setBusy(false);
        }
    };
    const remove = () => {
        setBusy(true);
        setError('');
        router.delete(
            `/company-hub/pages/${activity.page_id}/interactions/${comment.id}`,
            {
                data: { ...context(activity), version: comment.version },
                preserveScroll: true,
                onSuccess: () => {
                    if (alive.current) setConfirm(null);
                },
                onError: (errors) => {
                    if (alive.current)
                        setError(Object.values(errors).join(' '));
                },
                onFinish: () => {
                    if (alive.current) setBusy(false);
                },
            },
        );
    };
    return (
        <div className="max-w-sm text-right">
            {!confirm ? (
                <div className="flex justify-end gap-1">
                    {comment.can_hide &&
                        activity.actor_id &&
                        activity.revision_id && (
                            <Button
                                size="sm"
                                variant="ghost"
                                onClick={() => setConfirm('hide')}
                            >
                                <EyeOff className="size-3" />
                                Hide {kind}
                            </Button>
                        )}
                    {comment.can_remove && (
                        <Button
                            size="sm"
                            variant="ghost"
                            onClick={() => setConfirm('remove')}
                        >
                            Remove {kind}
                        </Button>
                    )}
                </div>
            ) : (
                <div className="space-y-2 rounded-lg border p-3 text-left text-xs">
                    <p>
                        {confirm === 'hide'
                            ? `Hide this ${kind} from staff? ${reply ? 'A moderator can restore it later.' : 'Its replies will also be hidden until the comment is restored.'}`
                            : `Remove your ${kind}? It cannot be restored.${reply ? '' : ' Its replies will also leave the conversation.'}`}
                    </p>
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            disabled={busy}
                            onClick={confirm === 'hide' ? hide : remove}
                        >
                            {busy
                                ? 'Saving…'
                                : confirm === 'hide'
                                  ? `Confirm hide`
                                  : `Confirm removal`}
                        </Button>
                        <Button
                            size="sm"
                            variant="ghost"
                            disabled={busy}
                            onClick={() => {
                                setConfirm(null);
                                setError('');
                            }}
                        >
                            Cancel
                        </Button>
                    </div>
                </div>
            )}
            {error && (
                <p className="mt-2 text-xs text-destructive" role="alert">
                    {error}
                </p>
            )}
        </div>
    );
}

export function HiddenReview({
    activity,
    onUpdated,
}: {
    activity: Activity;
    onUpdated?: () => void;
}) {
    const [data, setData] = useState<Review | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState<number | null>(null);
    const [reload, setReload] = useState(0);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        let current = true;
        hubRequest<Review>(
            `/company-hub/pages/${activity.page_id}/moderation?actor_id=${activity.actor_id}&revision_id=${activity.revision_id}`,
            'GET',
        )
            .then((result) => {
                if (!current) return;
                if (!matches(result, activity))
                    throw new Error(
                        'The page or your account changed. Reload this page before reviewing comments.',
                    );
                setData(result);
                setError('');
            })
            .catch((failure) => {
                if (current) setError((failure as Error).message);
            });
        return () => {
            current = false;
            alive.current = false;
        };
    }, [activity, reload]);
    const restore = async (item: HiddenComment) => {
        setBusy(item.id);
        setError('');
        try {
            const result = await hubRequest<Review>(
                `/company-hub/pages/${activity.page_id}/interactions/${item.id}/moderation`,
                'PUT',
                { ...context(activity), version: item.version, hidden: false },
            );
            if (!alive.current) return;
            if (!matches(result, activity))
                throw new Error(
                    'The page or your account changed. Reload before continuing.',
                );
            setData(result);
            if (onUpdated) onUpdated();
            else
                router.reload({
                    only: ['participation'],
                    preserveScroll: true,
                });
        } catch (failure) {
            if (alive.current) {
                setData(null);
                setError((failure as Error).message);
            }
        } finally {
            if (alive.current) setBusy(null);
        }
    };
    return (
        <>
            {!data && !error && (
                <p className="py-4 text-sm text-muted-foreground" role="status">
                    Loading hidden comments…
                </p>
            )}
            {error && (
                <div role="alert" className="rounded-lg border p-3 text-sm">
                    <p className="text-destructive">{error}</p>
                    <Button
                        variant="ghost"
                        onClick={() => {
                            setData(null);
                            setError('');
                            setReload((value) => value + 1);
                        }}
                    >
                        Refresh review
                    </Button>
                    <Button variant="ghost" onClick={() => router.reload()}>
                        Reload page
                    </Button>
                </div>
            )}
            {data && !data.comments_enabled && (
                <p className="rounded-lg bg-muted p-3 text-sm">
                    Comments are currently closed. Restored comments will appear
                    when comments are reopened.
                </p>
            )}
            <div className="space-y-3">
                {data?.hidden_comments.map((item) => (
                    <article key={item.id} className="rounded-lg border p-4">
                        <div className="flex justify-between gap-3">
                            <strong className="text-sm">
                                {item.name}
                                {item.parent_id ? ' · Reply' : ''}
                            </strong>
                            <span className="text-xs text-muted-foreground">
                                {formatDateTime(item.created_at)}
                            </span>
                        </div>
                        {item.body && (
                            <p className="my-3 text-sm whitespace-pre-wrap">
                                {item.body}
                            </p>
                        )}
                        <p className="mt-2 text-xs text-muted-foreground">
                            {item.hidden_reason === 'author_removed'
                                ? 'Removed by its author. Its text is unavailable and it cannot be restored.'
                                : item.hidden_reason === 'legacy_hidden'
                                  ? 'Hidden before recovery tracking was introduced. It cannot be restored.'
                                  : item.parent_hidden
                                    ? 'Hidden with its parent comment. Restore the parent first.'
                                    : 'Hidden by a moderator.'}
                        </p>
                        {item.can_restore && (
                            <Button
                                className="mt-3"
                                variant="outline"
                                size="sm"
                                disabled={busy !== null}
                                onClick={() => restore(item)}
                            >
                                <RotateCcw className="size-3" />
                                {busy === item.id
                                    ? 'Restoring…'
                                    : 'Restore comment'}
                            </Button>
                        )}
                    </article>
                ))}
                {data && !data.hidden_comments.length && (
                    <p className="py-6 text-center text-sm text-muted-foreground">
                        No hidden comments to review.
                    </p>
                )}
            </div>
        </>
    );
}

export function ModerationReview({ activity }: { activity: Activity }) {
    const [open, setOpen] = useState(false);
    if (!activity.can_moderate || !activity.actor_id || !activity.revision_id)
        return null;
    return (
        <>
            <Button variant="outline" onClick={() => setOpen(true)}>
                <EyeOff className="size-4" />
                Review hidden comments
                {activity.hidden_count ? ` (${activity.hidden_count})` : ''}
            </Button>
            <Sheet open={open} onOpenChange={setOpen}>
                <SheetContent
                    side="right"
                    className="gap-0 overflow-hidden p-0 sm:max-w-[600px]"
                    overlayClassName="bg-black/30 backdrop-blur-[2px]"
                    closeLabel="Close moderation review"
                >
                    <SheetHeader className="shrink-0 border-b px-7 pt-7 pr-14 pb-6">
                        <SheetTitle className="text-2xl">
                            Hidden comments
                        </SheetTitle>
                        <SheetDescription>
                            Review comments hidden from staff. Restoring a
                            parent brings back only replies that were hidden
                            with it.
                        </SheetDescription>
                    </SheetHeader>
                    <div className="min-h-0 flex-1 space-y-4 overflow-y-auto px-7 py-6">
                        {open && (
                            <HiddenReview
                                key={`${activity.actor_id}:${activity.page_id}:${activity.revision_id}`}
                                activity={activity}
                            />
                        )}
                    </div>
                    <footer className="flex shrink-0 justify-end border-t px-7 py-5">
                        <Button
                            variant="outline"
                            onClick={() => setOpen(false)}
                        >
                            Done
                        </Button>
                    </footer>
                </SheetContent>
            </Sheet>
        </>
    );
}
