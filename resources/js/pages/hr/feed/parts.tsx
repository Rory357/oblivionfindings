/* eslint-disable no-restricted-syntax -- The feed wall renders social cards with
 * on-card reaction/reply controls (raw <button> affordances) styled with
 * semantic tokens; these are bespoke surfaces, not generic shadcn <Button> cases. */
import { router } from '@inertiajs/react';
import {
    CheckCircle2,
    Gift,
    Heart,
    MessageCircle,
    Megaphone,
    PartyPopper,
    Pin,
    Send,
    Sparkles,
    Trophy,
} from 'lucide-react';
import { useState } from 'react';

import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';

/* ------------------------------------------------------------------ */
/*  Shared types (the FeedController@index payload)                    */
/* ------------------------------------------------------------------ */

export type FeedUser = { id: number; name: string };

export type ReactionSummary = {
    counts: Record<'heart' | 'party' | 'hands', number>;
    mine: string[];
};

export type KudosReply = {
    id: number;
    user_name: string;
    body: string;
    created_at: string | null;
};

export type KudosData = {
    id: number;
    category: string;
    impact: string;
    from_user: FeedUser | null;
    to_user: FeedUser | null;
    reactions: ReactionSummary;
    replies: KudosReply[];
    can_reply: boolean;
};

export type FeedPost = {
    id: number;
    post_type: string;
    kind: string | null;
    content: string;
    is_pinned: boolean;
    user: FeedUser | null;
    kudos: KudosData | null;
    created_at: string | null;
    created_at_date: string | null;
};

export type FeedAnnouncement = {
    id: number;
    title: string;
    content: string;
    priority: string;
    is_pinned: boolean;
    requires_acknowledgement: boolean;
    target_audience: string;
    target_value: string | null;
    creator: FeedUser | null;
    acknowledged_count: number;
    audience_count: number;
    viewer_acknowledged: boolean;
    created_at: string | null;
};

export type Milestone = {
    type: string;
    user_name: string;
    user_id: number;
    date: string;
    days_away?: number;
    years?: number;
    position?: string | null;
};

export type LeaderboardEntry = {
    user_id: number;
    user_name: string;
    kudos_count: number;
};

export type FeedEmployee = {
    id: number;
    name: string;
    role?: string | null;
    site?: string | null;
};

/* ------------------------------------------------------------------ */
/*  Display helpers                                                    */
/* ------------------------------------------------------------------ */

export function initials(name: string): string {
    return (
        name
            .split(/\s+/)
            .filter(Boolean)
            .slice(0, 2)
            .map((p) => p[0]?.toUpperCase() ?? '')
            .join('') || '?'
    );
}

const REACTION_GLYPH: Record<string, string> = { heart: '❤️', party: '🎉', hands: '👏' };
const REACTION_ORDER = ['heart', 'party', 'hands'] as const;

const IMPACT_CLASS: Record<string, string> = {
    thank_you: 'border-border bg-muted text-muted-foreground',
    good_job: 'border-status-info/30 bg-status-info-bg text-status-info',
    impressive: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    exceptional: 'border-status-success/30 bg-status-success-bg text-status-success',
};

const PRIORITY_CLASS: Record<string, string> = {
    low: 'border-border bg-muted text-muted-foreground',
    normal: 'border-status-info/30 bg-status-info-bg text-status-info',
    high: 'border-status-warning/30 bg-status-warning-bg text-status-warning',
    urgent: 'border-status-critical/30 bg-status-critical-bg text-status-critical',
};

// Update / Question / Win / Milestone wall badge (post_type=milestone, else the
// composer `kind`). All three update kinds share post_type=update.
const POST_BADGE: Record<string, { label: string; className: string }> = {
    milestone: { label: 'Milestone', className: 'border-status-warning/30 bg-status-warning-bg text-status-warning' },
    question: { label: 'Question', className: 'border-status-info/30 bg-status-info-bg text-status-info' },
    win: { label: 'Win', className: 'border-status-success/30 bg-status-success-bg text-status-success' },
    update: { label: 'Update', className: 'border-status-info/30 bg-status-info-bg text-status-info' },
};

function Avatar({ name, className }: { name: string; className?: string }) {
    return (
        <span
            className={cn(
                'grid flex-none place-items-center rounded-full bg-muted text-sm font-bold text-foreground',
                className,
            )}
        >
            {initials(name)}
        </span>
    );
}

function AuthorMeta({ employee }: { employee?: FeedEmployee }) {
    const bits = [employee?.role, employee?.site].filter(Boolean).join(' · ');
    return bits ? <span className="text-xs text-muted-foreground">{bits}</span> : null;
}

/* ------------------------------------------------------------------ */
/*  Kudos card — value + impact badges, reactions, reply thread        */
/* ------------------------------------------------------------------ */

export function KudosCard({
    post,
    categoryLabel,
    impactLabel,
    employeeById,
}: {
    post: FeedPost;
    categoryLabel: string;
    impactLabel: string;
    employeeById: Map<number, FeedEmployee>;
}) {
    const kudos = post.kudos!;
    const author = post.user;
    const [showReply, setShowReply] = useState(false);
    const [replyBody, setReplyBody] = useState('');
    const [posting, setPosting] = useState(false);

    const react = (emoji: string) => {
        router.post(
            `/hr/feed/kudos/${kudos.id}/react`,
            { emoji },
            { preserveScroll: true, preserveState: true, only: ['posts'] },
        );
    };

    const submitReply = () => {
        if (!replyBody.trim()) return;
        setPosting(true);
        router.post(
            `/hr/feed/kudos/${kudos.id}/reply`,
            { body: replyBody },
            {
                preserveScroll: true,
                preserveState: true,
                only: ['posts'],
                onSuccess: () => {
                    setReplyBody('');
                    setShowReply(false);
                },
                onFinish: () => setPosting(false),
            },
        );
    };

    return (
        <Card>
            <CardContent className="pt-6">
                <div className="flex items-start gap-3">
                    <Avatar name={author?.name ?? '?'} className="h-10 w-10" />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="font-semibold">{author?.name ?? 'Unknown'}</span>
                            {author ? <AuthorMeta employee={employeeById.get(author.id)} /> : null}
                            <Badge variant="outline" className="border-status-critical/30 bg-status-critical-bg text-status-critical">
                                Kudos
                            </Badge>
                            <Badge variant="outline" className={IMPACT_CLASS[kudos.impact] ?? IMPACT_CLASS.good_job}>
                                {impactLabel}
                            </Badge>
                            {post.is_pinned ? <Pin className="h-3.5 w-3.5 text-status-warning" /> : null}
                            <span className="text-xs text-muted-foreground">{post.created_at}</span>
                        </div>

                        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-sm">
                            <Heart className="h-4 w-4 text-status-critical" />
                            <span className="text-muted-foreground">to</span>
                            <span className="font-semibold">{kudos.to_user?.name ?? 'a colleague'}</span>
                            <Badge variant="secondary">{categoryLabel}</Badge>
                        </div>

                        <p className="mt-2 text-sm whitespace-pre-wrap">{post.content}</p>

                        {/* reactions + reply toggle */}
                        <div className="mt-3 flex flex-wrap items-center gap-2">
                            {REACTION_ORDER.map((emoji) => {
                                const count = kudos.reactions.counts[emoji] ?? 0;
                                const mine = kudos.reactions.mine.includes(emoji);
                                return (
                                    <button
                                        key={emoji}
                                        type="button"
                                        onClick={() => react(emoji)}
                                        aria-pressed={mine}
                                        className={cn(
                                            'inline-flex items-center gap-1.5 rounded-full border px-2.5 py-1 text-xs font-semibold transition-colors',
                                            mine
                                                ? 'border-primary bg-primary/10 text-primary'
                                                : 'border-border bg-card text-muted-foreground hover:border-primary/40 hover:text-foreground',
                                        )}
                                    >
                                        <span aria-hidden>{REACTION_GLYPH[emoji]}</span>
                                        {count}
                                    </button>
                                );
                            })}
                            <button
                                type="button"
                                onClick={() => setShowReply((v) => !v)}
                                className="inline-flex items-center gap-1.5 rounded-full border border-border bg-card px-2.5 py-1 text-xs font-semibold text-muted-foreground transition-colors hover:border-primary/40 hover:text-foreground"
                            >
                                <MessageCircle className="h-3.5 w-3.5" />
                                {kudos.replies.length}
                            </button>
                        </div>

                        {/* reply thread */}
                        {kudos.replies.length > 0 ? (
                            <ul className="mt-3 space-y-2 border-l-2 border-border pl-3">
                                {kudos.replies.map((reply) => (
                                    <li key={reply.id} className="text-sm">
                                        <span className="font-semibold">{reply.user_name}</span>{' '}
                                        <span className="text-xs text-muted-foreground">{reply.created_at}</span>
                                        <p className="whitespace-pre-wrap text-muted-foreground">{reply.body}</p>
                                    </li>
                                ))}
                            </ul>
                        ) : null}

                        {showReply && kudos.can_reply ? (
                            <div className="mt-3 flex items-end gap-2">
                                <Textarea
                                    rows={2}
                                    value={replyBody}
                                    onChange={(e) => setReplyBody(e.target.value)}
                                    placeholder="Write a reply…"
                                    maxLength={2000}
                                    className="flex-1"
                                />
                                <button
                                    type="button"
                                    onClick={submitReply}
                                    disabled={posting || !replyBody.trim()}
                                    className={cn(
                                        'inline-flex h-9 items-center gap-1.5 rounded-md bg-primary px-3 text-sm font-semibold text-primary-foreground',
                                        (posting || !replyBody.trim()) && 'cursor-not-allowed opacity-50',
                                    )}
                                >
                                    <Send className="h-3.5 w-3.5" />
                                    Reply
                                </button>
                            </div>
                        ) : showReply && !kudos.can_reply ? (
                            <p className="mt-2 text-xs text-muted-foreground">
                                Only {kudos.from_user?.name ?? 'the sender'} and{' '}
                                {kudos.to_user?.name ?? 'the recipient'} can reply to this thread.
                            </p>
                        ) : null}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Announcement (Notice) card — acknowledge + progress                */
/* ------------------------------------------------------------------ */

export function AnnouncementCard({
    announcement,
    employeeById,
}: {
    announcement: FeedAnnouncement;
    employeeById: Map<number, FeedEmployee>;
}) {
    const a = announcement;
    const pct = a.audience_count > 0 ? Math.round((a.acknowledged_count / a.audience_count) * 100) : 0;

    const acknowledge = () => {
        router.post(
            `/hr/announcements/${a.id}/acknowledge`,
            {},
            { preserveScroll: true, preserveState: true, only: ['announcements'] },
        );
    };

    return (
        <Card className="border-primary/30">
            <CardContent className="pt-6">
                {a.is_pinned ? (
                    <div className="mb-2 inline-flex items-center gap-1.5 text-[11px] font-bold uppercase tracking-wide text-primary">
                        <Pin className="h-3.5 w-3.5" /> Pinned
                    </div>
                ) : null}
                <div className="flex items-start gap-3">
                    <span className="grid h-10 w-10 flex-none place-items-center rounded-full bg-primary/10 text-primary">
                        <Megaphone className="h-5 w-5" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="font-semibold">{a.creator?.name ?? 'HR'}</span>
                            {a.creator ? <AuthorMeta employee={employeeById.get(a.creator.id)} /> : null}
                            <Badge variant="outline" className="border-status-success/30 bg-status-success-bg text-status-success">
                                Announcement
                            </Badge>
                            {a.priority !== 'normal' ? (
                                <Badge variant="outline" className={PRIORITY_CLASS[a.priority] ?? ''}>
                                    {a.priority}
                                </Badge>
                            ) : null}
                            <span className="text-xs text-muted-foreground">{a.created_at}</span>
                        </div>

                        <h3 className="mt-1.5 text-base font-bold">{a.title}</h3>
                        <p className="mt-1 text-sm whitespace-pre-wrap">{a.content}</p>

                        {a.requires_acknowledgement ? (
                            <div className="mt-3 rounded-lg border border-border bg-muted/40 p-3">
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    {a.viewer_acknowledged ? (
                                        <span className="inline-flex items-center gap-1.5 text-sm font-semibold text-status-success">
                                            <CheckCircle2 className="h-4 w-4" /> Acknowledged
                                        </span>
                                    ) : (
                                        <button
                                            type="button"
                                            onClick={acknowledge}
                                            className="rounded-md bg-primary px-3.5 py-1.5 text-sm font-semibold text-primary-foreground hover:opacity-90"
                                        >
                                            Acknowledge
                                        </button>
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {a.acknowledged_count} of {a.audience_count} acknowledged · {pct}%
                                    </span>
                                </div>
                                <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div className="h-full rounded-full bg-primary transition-[width] duration-500" style={{ width: `${pct}%` }} />
                                </div>
                            </div>
                        ) : null}
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Plain update / milestone card                                      */
/* ------------------------------------------------------------------ */

export function UpdateCard({
    post,
    employeeById,
}: {
    post: FeedPost;
    employeeById: Map<number, FeedEmployee>;
}) {
    const badgeKey =
        post.post_type === 'milestone'
            ? 'milestone'
            : post.kind && post.kind !== 'update'
              ? post.kind
              : 'update';
    const badge = POST_BADGE[badgeKey] ?? POST_BADGE.update;
    return (
        <Card>
            <CardContent className="pt-6">
                <div className="flex items-start gap-3">
                    <Avatar name={post.user?.name ?? '?'} className="h-10 w-10" />
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                            <span className="font-semibold">{post.user?.name ?? 'Unknown'}</span>
                            {post.user ? <AuthorMeta employee={employeeById.get(post.user.id)} /> : null}
                            <Badge variant="outline" className={badge.className}>
                                {badge.label}
                            </Badge>
                            {post.is_pinned ? <Pin className="h-3.5 w-3.5 text-status-warning" /> : null}
                            <span className="text-xs text-muted-foreground">{post.created_at}</span>
                        </div>
                        <p className="mt-2 text-sm whitespace-pre-wrap">{post.content}</p>
                    </div>
                </div>
            </CardContent>
        </Card>
    );
}

/* ------------------------------------------------------------------ */
/*  Right sidebar                                                      */
/* ------------------------------------------------------------------ */

export function TopRecognised({ leaderboard }: { leaderboard: LeaderboardEntry[] }) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center justify-between text-sm">
                    <span className="flex items-center gap-2">
                        <Trophy className="h-4 w-4 text-status-warning" /> Top recognised
                    </span>
                    <span className="text-[11px] font-medium text-muted-foreground">This month</span>
                </CardTitle>
            </CardHeader>
            <CardContent className="pt-0">
                {leaderboard.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No kudos yet this month.</p>
                ) : (
                    <ul className="space-y-3">
                        {leaderboard.map((entry, i) => (
                            <li key={entry.user_id} className="flex items-center gap-3">
                                <span
                                    className={cn(
                                        'grid h-6 w-6 flex-none place-items-center rounded-full text-xs font-bold',
                                        i === 0
                                            ? 'bg-status-warning-bg text-status-warning'
                                            : 'bg-muted text-muted-foreground',
                                    )}
                                >
                                    {i + 1}
                                </span>
                                <Avatar name={entry.user_name} className="h-7 w-7 text-[11px]" />
                                <span className="min-w-0 flex-1 truncate text-sm font-medium">{entry.user_name}</span>
                                <Badge variant="secondary" className="shrink-0">
                                    {entry.kudos_count}
                                </Badge>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

const CELEBRATION_GLYPH: Record<string, string> = {
    anniversary: '🎉',
    birthday: '🎂',
    new_hire: '👋',
};

export function CelebrationsCard({
    milestones,
    onCongratulate,
}: {
    milestones: Milestone[];
    onCongratulate: (m: Milestone) => void;
}) {
    return (
        <Card>
            <CardHeader className="pb-3">
                <CardTitle className="flex items-center gap-2 text-sm">
                    <PartyPopper className="h-4 w-4 text-primary" /> Celebrations
                </CardTitle>
            </CardHeader>
            <CardContent className="pt-0">
                {milestones.length === 0 ? (
                    <p className="text-sm text-muted-foreground">No celebrations coming up.</p>
                ) : (
                    <ul className="space-y-3">
                        {milestones.map((m) => (
                            <li key={`${m.type}-${m.user_id}-${m.date}`} className="flex items-center gap-3">
                                <Avatar name={m.user_name} className="h-8 w-8 text-[11px]" />
                                <div className="min-w-0 flex-1">
                                    <div className="truncate text-sm font-medium">{m.user_name}</div>
                                    <div className="truncate text-xs text-muted-foreground">
                                        {CELEBRATION_GLYPH[m.type] ?? '✨'} {milestoneLabel(m)}
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => onCongratulate(m)}
                                    aria-label={`Congratulate ${m.user_name}`}
                                    title={`Congratulate ${m.user_name}`}
                                    className="grid h-8 w-8 flex-none place-items-center rounded-md border border-border text-muted-foreground transition-colors hover:border-primary/40 hover:text-primary"
                                >
                                    <Gift className="h-4 w-4" />
                                </button>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

export function milestoneLabel(m: Milestone): string {
    if (m.type === 'anniversary') return `${m.years ?? ''}-year work anniversary`.trim();
    if (m.type === 'birthday') return m.days_away === 0 ? 'Birthday today' : `Birthday · ${m.date}`;
    if (m.type === 'new_hire') return m.position ? `New hire · ${m.position}` : 'New hire';
    return m.date;
}

/* ------------------------------------------------------------------ */
/*  Empty-state helper                                                 */
/* ------------------------------------------------------------------ */

export function FeedEmpty({ label }: { label: string }) {
    return (
        <Card>
            <CardContent className="flex flex-col items-center gap-2 py-12 text-center text-muted-foreground">
                <Sparkles className="h-8 w-8 text-muted-foreground/60" />
                <p className="text-sm">{label}</p>
            </CardContent>
        </Card>
    );
}
