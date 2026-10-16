import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { router, useForm } from '@inertiajs/react';
import { Heart, PartyPopper, Send, Sparkles } from 'lucide-react';
import { useId, useState } from 'react';
import { StoryDiscussion } from './_discussion';
import { ResourceReports } from './_resource-reports';
import type { HubProps } from './_types';

export function ReplyForm({
    url,
    label = 'Add a reply',
    parentId,
    onPosted,
}: {
    url: string;
    label?: string;
    parentId?: number;
    onPosted?: () => void;
}) {
    const fieldId = useId();
    const form = useForm({
        body: '',
        kind: 'comment',
        ...(parentId ? { parent_id: parentId } : {}),
    });
    return (
        <form
            className="hub-recognition-reply-form"
            onSubmit={(event) => {
                event.preventDefault();
                if (form.processing || !form.data.body.trim()) return;
                form.post(url, {
                    preserveScroll: true,
                    onSuccess: () => {
                        form.reset('body');
                        onPosted?.();
                    },
                });
            }}
        >
            <Label htmlFor={fieldId} className="sr-only">
                {label}
            </Label>
            <Textarea
                id={fieldId}
                className="hub-recognition-reply-input"
                required
                disabled={form.processing}
                maxLength={2000}
                rows={form.data.body ? 3 : 1}
                value={form.data.body}
                onChange={(event) => form.setData('body', event.target.value)}
                onKeyDown={(event) => {
                    if (
                        (event.ctrlKey || event.metaKey) &&
                        event.key === 'Enter'
                    ) {
                        event.preventDefault();
                        event.currentTarget.form?.requestSubmit();
                    }
                }}
                placeholder="Add a little encouragement…"
            />
            {form.errors.body && (
                <p role="alert" className="text-sm text-destructive">
                    {form.errors.body}
                </p>
            )}
            <div className="hub-recognition-reply-footer">
                <span className="text-xs text-muted-foreground">
                    Ctrl / ⌘ + Enter to post. Enter adds a new line.
                </span>
                <Button
                    disabled={form.processing || !form.data.body.trim()}
                    type="submit"
                >
                    <Send className="size-3.5" />
                    {form.processing ? 'Posting…' : 'Post reply'}
                </Button>
            </div>
        </form>
    );
}

export function PageParticipation({ data }: { data: HubProps }) {
    const activity = data.participation;
    const [error, setError] = useState('');
    if (
        !activity ||
        !data.selectedPage ||
        activity.page_id !== data.selectedPage.id ||
        (data.selectedPage.revision_id !== undefined &&
            activity.revision_id !== data.selectedPage.revision_id)
    )
        return null;
    const content = data.selectedPage.content;
    const hasFeedback =
        ['page', 'knowledge'].includes(data.selectedPage.kind ?? '') &&
        !!activity.helpful;
    const hasReactions =
        content.reactions_enabled && Object.keys(activity.reactions).length > 0;
    const hasDiscussion =
        content.comments_enabled ||
        (!data.demo &&
            !!activity.can_moderate &&
            !!activity.actor_id &&
            !!activity.revision_id);
    if (!hasFeedback && !hasReactions && !hasDiscussion) return null;
    return (
        <Card className="space-y-5 p-5 sm:p-6">
            <div>
                <h2 className="text-section-title">
                    {hasDiscussion || hasReactions
                        ? 'Join the conversation'
                        : 'Resource feedback'}
                </h2>
                <p className="mt-1 text-sm text-muted-foreground">
                    {hasDiscussion || hasReactions
                        ? 'A little encouragement makes a difference.'
                        : 'Help keep this resource useful for everyone.'}
                </p>
            </div>
            {['page', 'knowledge'].includes(data.selectedPage.kind ?? '') &&
                activity.helpful && (
                    <div className="flex items-center gap-3 border-b pb-4">
                        <span className="text-sm">
                            Was this resource helpful?
                        </span>
                        {!data.demo && data.resourceReports && (
                            <ResourceReports data={data.resourceReports} />
                        )}
                        <Button
                            disabled={data.demo}
                            variant={
                                activity.helpful.mine ? 'secondary' : 'outline'
                            }
                            aria-pressed={activity.helpful.mine}
                            onClick={() =>
                                !data.demo &&
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
                                    disabled={data.demo}
                                    key={key}
                                    variant={
                                        reaction.mine ? 'secondary' : 'outline'
                                    }
                                    aria-pressed={reaction.mine}
                                    onClick={() =>
                                        !data.demo &&
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
            <StoryDiscussion
                activity={activity}
                title={content.title}
                commentsEnabled={content.comments_enabled}
                demo={data.demo}
            />
        </Card>
    );
}

export { HubPoll } from './_poll';
