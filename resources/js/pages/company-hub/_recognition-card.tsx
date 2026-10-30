import { AppreciationIcon } from '@/components/recognition/appreciation-icon';
import { recognitionHeading } from '@/components/recognition/recognition-preview';
import { Avatar, AvatarFallback, AvatarImage } from '@/components/ui/avatar';
import { Button } from '@/components/ui/button';
import { formatRelative } from '@/lib/datetime';
import { cn } from '@/lib/utils';
import { Link, router } from '@inertiajs/react';
import {
    Heart,
    Link2,
    MessageCircle,
    PartyPopper,
    UsersRound,
    X,
} from 'lucide-react';
import { useId, useRef, useState, type ReactNode } from 'react';
import {
    RecognitionConversation,
    RecognitionMedia,
    type SocialKudos,
} from './_recognition-conversation';
import type { HubKudos, HubProps } from './_types';
import './recognition.css';

type RecognitionCardProps = {
    kudos: HubKudos;
    data: HubProps;
    action?: ReactNode;
    compact?: boolean;
    readOnly?: boolean;
    onDraftChange?: (id: number, dirty: boolean) => void;
};

export function RecognitionCard(props: RecognitionCardProps) {
    return (
        <RecognitionCardBody
            key={`${props.data.recognition?.actor_id}:${props.data.demo}:${props.kudos.id}`}
            {...props}
        />
    );
}

function RecognitionCardBody({
    kudos: source,
    data,
    action,
    compact = false,
    readOnly = false,
    onDraftChange,
}: RecognitionCardProps) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [conversationOpen, setConversationOpen] = useState(false);
    const [updated, setUpdated] = useState<{
        source: HubKudos;
        value: SocialKudos;
    } | null>(null);
    const kudos: SocialKudos =
        updated?.source === source ? updated.value : source;
    const [expanded, setExpanded] = useState(false);
    const [copied, setCopied] = useState(false);
    const [optimistic, setOptimistic] = useState<HubKudos['reactions'] | null>(
        null,
    );
    const reactionLock = useRef(false);
    const reactions = optimistic ?? kudos.reactions;
    const reactionCount = Object.values(reactions).reduce(
        (total, item) => total + item.count,
        0,
    );
    const conversationId = useId();
    const conversation = useRef<HTMLDivElement>(null);
    const conversationTrigger = useRef<HTMLButtonElement>(null);
    const team = kudos.recipient_type === 'team';
    const canReply =
        kudos.can_reply &&
        data.capabilities.recognise &&
        !data.demo &&
        !readOnly;
    const meaningfulValue =
        kudos.category && kudos.category.toLowerCase() !== 'other';
    const closeConversation = () => {
        setConversationOpen(false);
        conversationTrigger.current?.focus();
    };

    return (
        <article
            id={`kudos-${kudos.id}`}
            tabIndex={-1}
            aria-label={`Shout-out for ${kudos.to}`}
            className={cn(
                'hub-recognition-card scroll-mt-28',
                compact && 'hub-recognition-card-compact',
            )}
        >
            <header className="hub-recognition-card-heading">
                <Avatar className="hub-recognition-author-avatar">
                    {kudos.avatar_url && (
                        <AvatarImage src={kudos.avatar_url} alt="" />
                    )}
                    <AvatarFallback>
                        {kudos.from
                            .split(/\s+/)
                            .filter(Boolean)
                            .map((part) => part[0])
                            .slice(0, 2)
                            .join('')}
                    </AvatarFallback>
                </Avatar>
                <div className="hub-recognition-author">
                    <p>
                        <strong>{kudos.from}</strong>{' '}
                        <span>gave a shout-out</span>
                    </p>
                    <time dateTime={kudos.created_at}>
                        {formatRelative(kudos.created_at)}
                    </time>
                </div>
                {action && (
                    <span className="hub-recognition-editor-action">
                        {action}
                    </span>
                )}
            </header>
            <div className="hub-recognition-card-body">
                <p className="hub-recognition-impact">
                    <AppreciationIcon className="size-4" />
                    {recognitionHeading(kudos.impact, kudos.impact_label)}
                </p>
                <div className="hub-recognition-person">
                    <Avatar className="hub-recognition-avatar">
                        {kudos.recipient_avatar_url && (
                            <AvatarImage
                                src={kudos.recipient_avatar_url}
                                alt=""
                            />
                        )}
                        <AvatarFallback>
                            {team ? (
                                <UsersRound className="size-6" />
                            ) : (
                                kudos.to
                                    ?.split(/\s+/)
                                    .filter(Boolean)
                                    .map((v) => v[0])
                                    .slice(0, 2)
                                    .join('')
                            )}
                        </AvatarFallback>
                    </Avatar>
                    <div className="min-w-0">
                        <h3>
                            {kudos.recipient_url ? (
                                <Link
                                    href={kudos.recipient_url}
                                    className="hover:underline"
                                >
                                    {kudos.to}
                                </Link>
                            ) : (
                                kudos.to
                            )}
                        </h3>
                        <p>
                            {team
                                ? 'Team recognition'
                                : kudos.team_name ||
                                  'A little appreciation for you'}
                        </p>
                    </div>
                    {meaningfulValue && (
                        <span className="hub-recognition-value">
                            {kudos.category}
                        </span>
                    )}
                </div>
                <p className="hub-recognition-message">
                    {!expanded && kudos.message.length > 350
                        ? `${kudos.message.slice(0, 350)}…`
                        : kudos.message}
                </p>
                {kudos.message.length > 350 && (
                    <Button
                        variant="link"
                        className="h-auto p-0"
                        aria-expanded={expanded}
                        onClick={() => setExpanded(!expanded)}
                    >
                        {expanded ? 'Show less' : 'Read more'}
                    </Button>
                )}
                <RecognitionMedia
                    images={kudos.images}
                    mentions={kudos.mentions}
                />
            </div>
            <div className="hub-recognition-social-summary" aria-live="polite">
                <span>
                    <Heart className="size-3.5" />
                    {reactionCount
                        ? `${reactionCount} ${reactionCount === 1 ? 'reaction' : 'reactions'}`
                        : 'Be the first to celebrate'}
                </span>
                <span>
                    {kudos.replies.length}{' '}
                    {kudos.replies.length === 1 ? 'comment' : 'comments'}
                </span>
            </div>
            <footer className="hub-recognition-card-actions">
                <div
                    className="hub-recognition-reactions"
                    aria-label="Celebrate this shout-out"
                >
                    {Object.entries(reactions).map(([emoji, reaction]) => {
                        const label =
                            emoji === 'heart'
                                ? 'Appreciate'
                                : emoji === 'party'
                                  ? 'Celebrate'
                                  : 'Applaud';
                        const Icon =
                            emoji === 'heart'
                                ? Heart
                                : emoji === 'party'
                                  ? PartyPopper
                                  : AppreciationIcon;
                        return (
                            <Button
                                key={emoji}
                                variant="ghost"
                                size="sm"
                                className="hub-recognition-reaction"
                                disabled={
                                    data.demo ||
                                    readOnly ||
                                    !data.capabilities.recognise ||
                                    busy
                                }
                                aria-label={`${label} ${kudos.to}`}
                                aria-pressed={reaction.mine}
                                onClick={() => {
                                    if (reactionLock.current) return;
                                    reactionLock.current = true;
                                    setBusy(true);
                                    setError('');
                                    setOptimistic({
                                        ...kudos.reactions,
                                        [emoji]: {
                                            mine: !reaction.mine,
                                            count: Math.max(
                                                0,
                                                reaction.count +
                                                    (reaction.mine ? -1 : 1),
                                            ),
                                        },
                                    });
                                    let confirmed = false;
                                    router.post(
                                        `/hr/feed/kudos/${kudos.id}/react`,
                                        { emoji },
                                        {
                                            preserveScroll: true,
                                            onSuccess: () => {
                                                confirmed = true;
                                            },
                                            onError: () => {
                                                setOptimistic(null);
                                                setError(
                                                    'Your reaction could not be saved. Please try again.',
                                                );
                                            },
                                            onCancel: () => {
                                                setOptimistic(null);
                                                setError(
                                                    'Reaction cancelled. Please try again.',
                                                );
                                            },
                                            onFinish: () => {
                                                reactionLock.current = false;
                                                setOptimistic(null);
                                                setBusy(false);
                                                if (!confirmed)
                                                    setError(
                                                        (current) =>
                                                            current ||
                                                            'We could not confirm your reaction. Refresh this post before trying again.',
                                                    );
                                            },
                                        },
                                    );
                                }}
                            >
                                <Icon className="size-4" />
                                <span>{label}</span>
                                {reaction.count > 0 && (
                                    <span className="hub-recognition-reaction-count">
                                        {reaction.count}
                                    </span>
                                )}
                            </Button>
                        );
                    })}
                </div>
                {(canReply || kudos.replies.length > 0) && (
                    <Button
                        ref={conversationTrigger}
                        variant="ghost"
                        size="sm"
                        className="hub-recognition-conversation-trigger"
                        aria-expanded={conversationOpen}
                        aria-controls={conversationId}
                        onClick={() => {
                            if (conversationOpen) {
                                closeConversation();
                                return;
                            }
                            setConversationOpen(true);
                            if (canReply && !kudos.replies.length)
                                requestAnimationFrame(() =>
                                    conversation.current
                                        ?.querySelector<HTMLTextAreaElement>(
                                            'textarea',
                                        )
                                        ?.focus(),
                                );
                        }}
                    >
                        <MessageCircle className="size-4" />
                        {kudos.replies.length
                            ? `${kudos.replies.length} ${kudos.replies.length === 1 ? 'reply' : 'replies'}`
                            : 'Reply'}
                    </Button>
                )}
                {!data.demo && !readOnly && !compact && (
                    <Button
                        variant="ghost"
                        size="sm"
                        aria-label="Copy shout-out link"
                        onClick={async () => {
                            setError('');
                            try {
                                await navigator.clipboard.writeText(
                                    `${window.location.origin}/company-hub?view=shoutouts&recognition=${kudos.id}#kudos-${kudos.id}`,
                                );
                                setCopied(true);
                            } catch {
                                setError(
                                    'The link could not be copied. Please try again.',
                                );
                            }
                        }}
                    >
                        <Link2 className="size-4" />
                        {copied ? 'Link copied' : 'Copy link'}
                    </Button>
                )}
            </footer>
            {!compact && !conversationOpen && kudos.replies.length > 0 && (
                <div className="hub-recognition-reply-preview">
                    <strong>
                        {kudos.replies[kudos.replies.length - 1].name}
                    </strong>
                    <p>
                        {kudos.replies[kudos.replies.length - 1].body ??
                            (kudos.replies[kudos.replies.length - 1].removed
                                ? 'This comment was removed by its author.'
                                : 'This comment is hidden.')}
                    </p>
                </div>
            )}
            {canReply && !compact && !conversationOpen && (
                <Button
                    variant="ghost"
                    className="hub-recognition-comment-prompt h-auto justify-start"
                    onClick={() => {
                        setConversationOpen(true);
                        requestAnimationFrame(() =>
                            conversation.current
                                ?.querySelector<HTMLTextAreaElement>('textarea')
                                ?.focus(),
                        );
                    }}
                >
                    <MessageCircle className="size-4" />
                    Add a little encouragement…
                </Button>
            )}
            {error && (
                <p role="alert" className="px-5 pb-4 text-sm text-destructive">
                    {error}
                </p>
            )}
            <div
                ref={conversation}
                id={conversationId}
                hidden={!conversationOpen}
                className="hub-recognition-conversation"
            >
                <div className="hub-recognition-conversation-heading">
                    <h4>Keep the good feeling going</h4>
                    <Button
                        variant="ghost"
                        size="icon"
                        aria-label="Close replies"
                        onClick={closeConversation}
                    >
                        <X className="size-4" />
                    </Button>
                </div>
                <RecognitionConversation
                    key={`${data.recognition?.actor_id}:${kudos.id}`}
                    kudos={kudos}
                    actorId={
                        data.demo || readOnly
                            ? undefined
                            : data.recognition?.actor_id
                    }
                    writable={
                        !!data.capabilities.recognise && !data.demo && !readOnly
                    }
                    onChange={(value) => setUpdated({ source, value })}
                    onDraftChange={(dirty) => onDraftChange?.(kudos.id, dirty)}
                />
            </div>
        </article>
    );
}
