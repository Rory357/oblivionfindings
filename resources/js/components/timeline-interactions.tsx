import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Popover,
    PopoverContent,
    PopoverTrigger,
} from '@/components/ui/popover';
import { router, useForm } from '@inertiajs/react';
import { Heart, MessageSquare, Reply, Send, Smile, Trash2 } from 'lucide-react';
import { useState } from 'react';

export type Comment = {
    id: number;
    body: string;
    user_id: number;
    user_name: string;
    is_staff?: boolean;
    likes_count?: number;
    liked_by_user_ids?: number[];
    created_at: string;
    replies?: Comment[];
};

export type ReactionGroup = {
    emoji: string;
    count: number;
    user_ids: number[];
};

type Props = {
    eventId: number;
    comments: Comment[];
    reactions: ReactionGroup[];
    currentUserId: number;
    commentUrl: string;
    deleteCommentUrl: string;
    likeCommentUrl: string;
    reactUrl: string;
    canComment?: boolean;
    canReact?: boolean;
    showStaffBadge?: boolean;
};

const ALLOWED_REACTIONS = ['❤️', '👍', '😊', '🎉', '🙏', '💛'];

function relativeTime(iso: string): string {
    const now = new Date();
    const date = new Date(iso);
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);
    if (diffMins < 1) return 'just now';
    if (diffMins < 60) return `${diffMins}m ago`;
    const diffHours = Math.floor(diffMins / 60);
    if (diffHours < 24) return `${diffHours}h ago`;
    const diffDays = Math.floor(diffHours / 24);
    if (diffDays < 7) return `${diffDays}d ago`;
    return new Date(iso).toLocaleDateString([], {
        weekday: 'short',
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

export function TimelineInteractions({
    eventId,
    comments,
    reactions,
    currentUserId,
    commentUrl,
    deleteCommentUrl,
    likeCommentUrl,
    reactUrl,
    canComment = true,
    canReact = true,
    showStaffBadge = false,
}: Props) {
    const [showComments, setShowComments] = useState(false);
    const [replyingTo, setReplyingTo] = useState<{ id: number; name: string } | null>(null);
    const commentForm = useForm({ body: '', parent_id: null as number | null });

    const totalComments = comments.reduce((sum, c) => sum + 1 + (c.replies?.length ?? 0), 0);

    const toggleReaction = (emoji: string) => {
        router.post(reactUrl, { emoji }, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const submitComment = (e: React.FormEvent) => {
        e.preventDefault();
        if (!commentForm.data.body.trim()) return;
        commentForm.post(commentUrl, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: () => {
                commentForm.reset();
                setReplyingTo(null);
                setShowComments(true);
            },
        });
    };

    const startReply = (commentId: number, userName: string) => {
        setReplyingTo({ id: commentId, name: userName });
        commentForm.setData('parent_id', commentId);
    };

    const cancelReply = () => {
        setReplyingTo(null);
        commentForm.setData('parent_id', null);
    };

    const deleteComment = (commentId: number) => {
        router.delete(`${deleteCommentUrl}/${commentId}`, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    const toggleLike = (commentId: number) => {
        router.post(`${likeCommentUrl}/${commentId}/like`, {}, {
            preserveScroll: true,
            preserveState: true,
        });
    };

    return (
        <div className="mt-2">
            {/* Reactions + Comment Toggle Row */}
            <div className="flex flex-wrap items-center gap-1.5">
                {reactions.map((r) => {
                    const isActive = r.user_ids.includes(currentUserId);
                    return (
                        <button
                            key={r.emoji}
                            onClick={() => canReact && toggleReaction(r.emoji)}
                            disabled={!canReact}
                            className={`inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-xs transition-colors hover:bg-muted ${
                                isActive
                                    ? 'border-primary/50 bg-primary/5'
                                    : 'border-border'
                            } ${!canReact ? 'cursor-default' : 'cursor-pointer'}`}
                        >
                            <span>{r.emoji}</span>
                            <span className="font-medium">{r.count}</span>
                        </button>
                    );
                })}

                {canReact && (
                    <Popover>
                        <PopoverTrigger asChild>
                            <button className="inline-flex items-center gap-1 rounded-full border border-dashed px-2 py-0.5 text-xs text-muted-foreground transition-colors hover:border-primary/30 hover:bg-muted">
                                <Smile className="h-3 w-3" />
                                <span>+</span>
                            </button>
                        </PopoverTrigger>
                        <PopoverContent className="w-auto p-2" align="start">
                            <div className="grid grid-cols-3 gap-1">
                                {ALLOWED_REACTIONS.map((emoji) => (
                                    <button
                                        key={emoji}
                                        onClick={() => toggleReaction(emoji)}
                                        className="rounded-lg p-2 text-lg transition-colors hover:bg-muted"
                                    >
                                        {emoji}
                                    </button>
                                ))}
                            </div>
                        </PopoverContent>
                    </Popover>
                )}

                <button
                    onClick={() => setShowComments(!showComments)}
                    className="ml-auto inline-flex items-center gap-1 text-xs text-muted-foreground transition-colors hover:text-foreground"
                >
                    <MessageSquare className="h-3 w-3" />
                    {totalComments > 0 ? (
                        <span>{totalComments} comment{totalComments !== 1 ? 's' : ''}</span>
                    ) : (
                        <span>Comment</span>
                    )}
                </button>
            </div>

            {/* Comments Section */}
            {showComments && (
                <div className="mt-3 space-y-2 rounded-lg bg-muted/30 p-3">
                    {comments.length > 0 && (
                        <div className="space-y-2">
                            {comments.map((comment) => (
                                <div key={comment.id}>
                                    <CommentRow
                                        comment={comment}
                                        currentUserId={currentUserId}
                                        showStaffBadge={showStaffBadge}
                                        onDelete={deleteComment}
                                        onReply={canComment ? startReply : undefined}
                                        onToggleLike={toggleLike}
                                    />
                                    {/* Replies */}
                                    {comment.replies && comment.replies.length > 0 && (
                                        <div className="ml-6 mt-1 space-y-1 border-l-2 border-muted pl-3">
                                            {comment.replies.map((reply) => (
                                                <CommentRow
                                                    key={reply.id}
                                                    comment={reply}
                                                    currentUserId={currentUserId}
                                                    showStaffBadge={showStaffBadge}
                                                    onDelete={deleteComment}
                                                    onToggleLike={toggleLike}
                                                    isReply
                                                />
                                            ))}
                                        </div>
                                    )}
                                </div>
                            ))}
                        </div>
                    )}

                    {/* Comment Form */}
                    {canComment && (
                        <div>
                            {replyingTo && (
                                <div className="mb-1 flex items-center gap-1 text-xs text-muted-foreground">
                                    <Reply className="h-3 w-3" />
                                    Replying to {replyingTo.name}
                                    <button onClick={cancelReply} className="ml-1 text-primary hover:underline">Cancel</button>
                                </div>
                            )}
                            <form onSubmit={submitComment} className="flex gap-2">
                                <input
                                    type="text"
                                    placeholder={replyingTo ? `Reply to ${replyingTo.name}...` : 'Leave a comment...'}
                                    value={commentForm.data.body}
                                    onChange={(e) => commentForm.setData('body', e.target.value)}
                                    className="h-8 flex-1 rounded-md border border-input bg-background px-3 text-xs focus:outline-none focus:ring-1 focus:ring-primary"
                                    maxLength={1000}
                                />
                                <Button
                                    type="submit"
                                    size="sm"
                                    className="h-8 px-3"
                                    disabled={commentForm.processing || !commentForm.data.body.trim()}
                                >
                                    <Send className="h-3 w-3" />
                                </Button>
                            </form>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/* ── Comment Row ──────────────────────────────────────── */

function CommentRow({
    comment,
    currentUserId,
    showStaffBadge,
    onDelete,
    onReply,
    onToggleLike,
    isReply,
}: {
    comment: Comment;
    currentUserId: number;
    showStaffBadge: boolean;
    onDelete: (id: number) => void;
    onReply?: (id: number, name: string) => void;
    onToggleLike: (id: number) => void;
    isReply?: boolean;
}) {
    const likesCount = comment.likes_count ?? 0;
    const isLiked = comment.liked_by_user_ids?.includes(currentUserId) ?? false;

    return (
        <div
            className={`rounded-md px-2 py-1.5 ${
                showStaffBadge
                    ? comment.is_staff
                        ? 'border-l-2 border-l-blue-400'
                        : 'border-l-2 border-l-amber-400'
                    : ''
            }`}
        >
            <div className="flex items-start justify-between gap-2">
                <div className="min-w-0 flex-1">
                    <p className="text-xs">
                        <span className="font-medium">{comment.user_name}</span>
                        {showStaffBadge && (
                            comment.is_staff ? (
                                <Badge variant="outline" className="ml-1 text-[9px] border-status-info/30 bg-status-info-bg text-status-info dark:border-status-info/30 dark:bg-status-info-bg dark:text-status-info">
                                    Staff
                                </Badge>
                            ) : (
                                <Badge variant="outline" className="ml-1 text-[9px] border-status-warning/30 bg-status-warning-bg text-status-warning dark:border-status-warning/30 dark:bg-status-warning-bg dark:text-status-warning">
                                    Family
                                </Badge>
                            )
                        )}
                        <span className="ml-2 text-muted-foreground">{relativeTime(comment.created_at)}</span>
                    </p>
                    <p className="mt-0.5 text-sm">{comment.body}</p>
                    {/* Actions: Like, Reply */}
                    <div className="mt-1 flex items-center gap-3">
                        <button
                            onClick={() => onToggleLike(comment.id)}
                            className={`inline-flex items-center gap-1 text-[11px] transition-colors ${
                                isLiked
                                    ? 'text-status-critical'
                                    : 'text-muted-foreground hover:text-status-critical'
                            }`}
                        >
                            <Heart className={`h-3 w-3 ${isLiked ? 'fill-rose-500' : ''}`} />
                            {likesCount > 0 && <span>{likesCount}</span>}
                        </button>
                        {onReply && !isReply && (
                            <button
                                onClick={() => onReply(comment.id, comment.user_name)}
                                className="inline-flex items-center gap-1 text-[11px] text-muted-foreground transition-colors hover:text-foreground"
                            >
                                <Reply className="h-3 w-3" />
                                Reply
                            </button>
                        )}
                    </div>
                </div>
                {comment.user_id === currentUserId && (
                    <button
                        onClick={() => onDelete(comment.id)}
                        className="shrink-0 text-muted-foreground/50 transition-colors hover:text-status-critical"
                    >
                        <Trash2 className="h-3 w-3" />
                    </button>
                )}
            </div>
        </div>
    );
}
