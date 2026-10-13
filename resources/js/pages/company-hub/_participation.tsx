import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatRelative } from '@/lib/datetime';
import { router, useForm } from '@inertiajs/react';
import { Heart, PartyPopper, Sparkles } from 'lucide-react';
import { useState } from 'react';
import { ReportResource } from './_resource-care';
import type { HubBlock, HubProps } from './_types';

export function ReplyForm({
    url,
    label = 'Add a reply',
    parentId,
}: {
    url: string;
    label?: string;
    parentId?: number;
}) {
    const form = useForm({
        body: '',
        kind: 'comment',
        ...(parentId ? { parent_id: parentId } : {}),
    });
    return (
        <form
            className="mt-4 space-y-3"
            onSubmit={(event) => {
                event.preventDefault();
                form.post(url, {
                    preserveScroll: true,
                    onSuccess: () => form.reset('body'),
                });
            }}
        >
            <Label className="block space-y-2">
                <span>{label}</span>
                <Textarea
                    required
                    maxLength={2000}
                    rows={2}
                    value={form.data.body}
                    onChange={(event) =>
                        form.setData('body', event.target.value)
                    }
                    placeholder="Keep it kind and constructive…"
                />
            </Label>
            {form.errors.body && (
                <p role="alert" className="text-sm text-destructive">
                    {form.errors.body}
                </p>
            )}
            <Button
                disabled={form.processing || !form.data.body.trim()}
                type="submit"
            >
                {form.processing ? 'Posting…' : 'Post'}
            </Button>
        </form>
    );
}

export function PageParticipation({ data }: { data: HubProps }) {
    const activity = data.participation;
    const [replyTo, setReplyTo] = useState<number | null>(null);
    const [error, setError] = useState('');
    if (!activity || !data.selectedPage) return null;
    const content = data.selectedPage.content;
    return (
        <Card className="space-y-5 p-5 sm:p-6">
            <div>
                <h2 className="text-section-title">Join the conversation</h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    A little encouragement makes a difference.
                </p>
            </div>
            {['page', 'knowledge'].includes(data.selectedPage.kind ?? '') &&
                activity.helpful && (
                    <div className="flex items-center gap-3 border-b pb-4">
                        <span className="text-sm">
                            Was this resource helpful?
                        </span>
                        {!data.demo && (
                            <ReportResource pageId={data.selectedPage.id} />
                        )}
                        <Button
                            variant={
                                activity.helpful.mine ? 'secondary' : 'outline'
                            }
                            aria-pressed={activity.helpful.mine}
                            onClick={() =>
                                router.post(
                                    `/company-hub/pages/${activity.page_id}/interactions`,
                                    {
                                        kind: 'helpful',
                                        active: !activity.helpful?.mine,
                                    },
                                    {
                                        preserveScroll: true,
                                        onError: (e) =>
                                            setError(
                                                Object.values(e).join(' '),
                                            ),
                                    },
                                )
                            }
                        >
                            {activity.helpful.mine
                                ? 'Helpful · thank you'
                                : 'Yes, helpful'}{' '}
                            · {activity.helpful.count}
                        </Button>
                    </div>
                )}
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
            {content.reactions_enabled && (
                <div className="flex flex-wrap gap-2">
                    {Object.entries(activity.reactions).map(
                        ([key, reaction]) => {
                            const Icon =
                                key === 'heart'
                                    ? Heart
                                    : key === 'celebrate'
                                      ? PartyPopper
                                      : Sparkles;
                            const label =
                                key === 'heart'
                                    ? 'Appreciate'
                                    : key === 'celebrate'
                                      ? 'Celebrate'
                                      : 'Applaud';
                            return (
                                <Button
                                    key={key}
                                    variant={
                                        reaction.mine ? 'secondary' : 'outline'
                                    }
                                    aria-pressed={reaction.mine}
                                    onClick={() =>
                                        router.post(
                                            `/company-hub/pages/${activity.page_id}/interactions`,
                                            {
                                                kind: 'reaction',
                                                reaction: key,
                                                active: !reaction.mine,
                                            },
                                            { preserveScroll: true },
                                        )
                                    }
                                >
                                    <Icon className="size-4" />
                                    {label} {reaction.count || ''}
                                </Button>
                            );
                        },
                    )}
                </div>
            )}
            {content.comments_enabled && (
                <>
                    <ReplyForm
                        url={`/company-hub/pages/${activity.page_id}/interactions`}
                        label="Add a comment"
                    />
                    <div className="divide-y divide-border">
                        {activity.comments
                            .filter((comment) => !comment.parent_id)
                            .map((comment) => (
                                <article key={comment.id} className="py-4">
                                    <div className="flex flex-wrap items-center justify-between gap-2">
                                        <p className="text-sm">
                                            <strong>{comment.name}</strong>
                                            <span className="ml-2 text-muted-foreground">
                                                {formatRelative(
                                                    comment.created_at,
                                                )}
                                            </span>
                                        </p>
                                        {comment.can_remove && (
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                onClick={() =>
                                                    router.delete(
                                                        `/company-hub/pages/${activity.page_id}/interactions/${comment.id}`,
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                Remove comment
                                            </Button>
                                        )}
                                    </div>
                                    <p className="mt-2 text-sm leading-relaxed break-words whitespace-pre-wrap">
                                        {comment.body}
                                    </p>
                                    <Button
                                        size="sm"
                                        variant="ghost"
                                        aria-expanded={replyTo === comment.id}
                                        onClick={() =>
                                            setReplyTo(
                                                replyTo === comment.id
                                                    ? null
                                                    : comment.id,
                                            )
                                        }
                                    >
                                        Reply
                                    </Button>
                                    <div className="ml-5 space-y-3 border-l pl-4">
                                        {activity.comments
                                            .filter(
                                                (reply) =>
                                                    reply.parent_id ===
                                                    comment.id,
                                            )
                                            .map((reply) => (
                                                <article
                                                    key={reply.id}
                                                    className="py-2 text-sm"
                                                >
                                                    <div className="flex items-center justify-between">
                                                        <strong>
                                                            {reply.name}
                                                        </strong>
                                                        {reply.can_remove && (
                                                            <Button
                                                                size="sm"
                                                                variant="ghost"
                                                                onClick={() =>
                                                                    router.delete(
                                                                        `/company-hub/pages/${activity.page_id}/interactions/${reply.id}`,
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                Remove reply
                                                            </Button>
                                                        )}
                                                    </div>
                                                    <p className="whitespace-pre-wrap">
                                                        {reply.body}
                                                    </p>
                                                </article>
                                            ))}
                                    </div>
                                    {replyTo === comment.id && (
                                        <ReplyForm
                                            key={comment.id}
                                            url={`/company-hub/pages/${activity.page_id}/interactions`}
                                            parentId={comment.id}
                                            label={`Reply to ${comment.name}`}
                                        />
                                    )}
                                </article>
                            ))}
                    </div>
                </>
            )}
        </Card>
    );
}

export function HubPoll({ block, data }: { block: HubBlock; data: HubProps }) {
    const activity = data.participation;
    const poll = activity?.polls[block.id];
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    return (
        <Card className="space-y-4 p-5">
            <p className="font-semibold">{block.question}</p>
            <p className="text-sm text-muted-foreground">
                Your answer is recorded. Colleagues see the totals.
            </p>
            <div className="space-y-2">
                {block.options?.map((option, index) => {
                    const count = poll?.counts[index] ?? 0;
                    const percent = poll?.total
                        ? Math.round((count / poll.total) * 100)
                        : 0;
                    return (
                        <Button
                            key={index}
                            variant="outline"
                            className="relative h-auto min-h-11 w-full justify-between overflow-hidden p-3 text-left whitespace-normal"
                            disabled={!poll || busy || poll.closed}
                            aria-pressed={poll?.mine === index}
                            onClick={() => {
                                if (!activity || !poll) return;
                                setBusy(true);
                                setError('');
                                router.post(
                                    `/company-hub/pages/${activity.page_id}/interactions`,
                                    {
                                        kind: 'vote',
                                        block_id: block.id,
                                        poll_key: poll.key,
                                        choice: index,
                                    },
                                    {
                                        preserveScroll: true,
                                        onError: (errors) =>
                                            setError(
                                                Object.values(errors).join(' '),
                                            ),
                                        onFinish: () => setBusy(false),
                                    },
                                );
                            }}
                        >
                            <span
                                aria-hidden
                                className="absolute inset-y-0 left-0 bg-primary/10"
                                style={{ width: `${percent}%` }}
                            />
                            <span className="relative">
                                {option}
                                {poll?.mine === index ? ' · Your choice' : ''}
                            </span>{' '}
                            <span className="relative ml-3 shrink-0 text-sm">
                                {poll ? `${percent}%` : ''}
                            </span>
                        </Button>
                    );
                })}
            </div>
            <p aria-live="polite" className="text-sm text-muted-foreground">
                {poll
                    ? `${poll.total} ${poll.total === 1 ? 'response' : 'responses'}${poll.closed ? ' · Poll closed' : ' · You can change your answer'}`
                    : 'Voting opens when this page is published.'}
            </p>
            {error && (
                <p role="alert" className="text-sm text-destructive">
                    {error}
                </p>
            )}
        </Card>
    );
}
