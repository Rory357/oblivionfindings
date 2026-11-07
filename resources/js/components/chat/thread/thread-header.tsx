import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import { ArrowLeft, Loader2, Search, ShieldCheck, Star, X } from 'lucide-react';
import type { Ref } from 'react';
import { ChatCallButton } from '../chat-calling';
import { ChatResponseClock } from '../chat-response-clock';
import { ChatUrgentHelp } from '../chat-urgent-contact';
import type { ChatConversation, ChatPolicy } from '../types';
import type { ThreadSearch } from './use-thread-search';

/** The conversation's title bar: back, name and people, call, search and favourite. */
export function ThreadHeader({
    conversation,
    actorId,
    family,
    detailsTrigger,
    onBack,
    onDetails,
    onToggleSearch,
    onFavourite,
}: {
    conversation: ChatConversation;
    actorId: number;
    family: boolean;
    detailsTrigger: Ref<HTMLButtonElement>;
    onBack?: () => void;
    onDetails: () => void;
    onToggleSearch: () => void;
    onFavourite: () => void;
}) {
    return (
        <header className="flex min-h-16 shrink-0 items-center gap-1 border-b px-2 py-2 sm:min-h-20 sm:gap-3 sm:px-4 sm:py-3">
            {onBack && (
                <Button
                    variant="ghost"
                    size="icon"
                    className="size-8 shrink-0 md:hidden"
                    aria-label="Back to conversations"
                    onClick={onBack}
                >
                    <ArrowLeft />
                </Button>
            )}
            <div className="hidden size-8 shrink-0 items-center justify-center rounded-2xl bg-primary/10 font-semibold text-primary sm:flex">
                {conversation.title.slice(0, 2).toUpperCase()}
            </div>
            <Button
                ref={detailsTrigger}
                variant="ghost"
                type="button"
                className="h-auto min-w-0 flex-1 flex-col items-start gap-0 rounded px-1 text-left focus-visible:outline-2 focus-visible:outline-primary [&>*]:max-w-full"
                onClick={onDetails}
            >
                <h2 className="truncate font-semibold">
                    {conversation.title || 'Conversation'}
                </h2>
                <p className="truncate text-xs text-muted-foreground">
                    {conversation.client?.name ??
                        `${conversation.participants.length} participants`}{' '}
                    · View people
                </p>
            </Button>
            <ChatCallButton
                conversation={conversation}
                actorId={actorId}
                family={family}
            />
            <Button
                variant="ghost"
                size="icon"
                className="size-8"
                aria-label="Search this conversation"
                onClick={onToggleSearch}
            >
                <Search className="size-5" />
            </Button>
            <Button
                variant="ghost"
                size="icon"
                className="hidden size-8 sm:inline-flex"
                aria-label={
                    conversation.favourite
                        ? 'Remove favourite'
                        : 'Favourite conversation'
                }
                aria-pressed={conversation.favourite}
                onClick={onFavourite}
            >
                <Star
                    className={cn(
                        'size-5',
                        conversation.favourite &&
                            'fill-current text-status-warning',
                    )}
                />
            </Button>
        </header>
    );
}

/** Who can see a family conversation, the reply promise and urgent help. */
export function ThreadFamilyNotice({
    conversation,
    actorId,
    family,
    policy,
}: {
    conversation: ChatConversation;
    actorId: number;
    family: boolean;
    policy: ChatPolicy;
}) {
    return (
        <div className="flex shrink-0 gap-2 border-b bg-status-info-bg px-4 py-2.5 text-xs text-status-info">
            <ShieldCheck className="mt-0.5 size-4 shrink-0" />
            <div>
                {conversation.care_team_cover
                    ? 'The named worker and authorised care-team cover can see and respond to this conversation.'
                    : 'Only the invited family members and authorised staff participants can see this conversation.'}
                {family && (
                    <p className="mt-1">
                        <ChatResponseClock
                            audience="family"
                            clock={conversation.response_clock ?? undefined}
                            wording={policy.response_expectation}
                        />
                        <span>Chat is not for emergencies.</span>
                    </p>
                )}
                {conversation.client && (
                    <div className="mt-2">
                        <ChatUrgentHelp
                            actorId={actorId}
                            clientId={conversation.client.id}
                        />
                    </div>
                )}
            </div>
        </div>
    );
}

/** The search field and its status lines. */
export function ThreadSearchBar({
    search,
    onClose,
}: {
    search: ThreadSearch;
    onClose: () => void;
}) {
    if (!search.open) return null;
    return (
        <>
            <div className="flex shrink-0 gap-2 border-b p-3">
                <Input
                    autoFocus
                    aria-label="Search messages"
                    value={search.query}
                    onChange={(event) => search.setQuery(event.target.value)}
                    placeholder="Search this conversation…"
                />
                <Button
                    variant="ghost"
                    size="icon"
                    aria-label="Close search"
                    className="size-8 shrink-0"
                    onClick={onClose}
                >
                    <X />
                </Button>
            </div>
            {search.error && (
                <div
                    role="status"
                    className="flex items-center justify-between gap-2 bg-status-warning-bg px-4 py-2 text-sm text-status-warning"
                >
                    <span>Search updates paused. {search.error}</span>
                    <Button variant="ghost" onClick={search.retry}>
                        Retry search
                    </Button>
                </div>
            )}
            {search.busy && (
                <p
                    role="status"
                    className="flex items-center gap-2 px-4 py-2 text-sm text-muted-foreground"
                >
                    <Loader2
                        className="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    Searching messages…
                </p>
            )}
        </>
    );
}

/** Paused or reconnecting updates, and catching up after a gap. */
export function ThreadUpdateStatus({
    failure,
    recovering,
    catchingUp,
    onRetry,
}: {
    failure: string;
    recovering: boolean;
    catchingUp: boolean;
    onRetry: () => void;
}) {
    return (
        <>
            {(failure || recovering) && (
                <div
                    role="status"
                    className="flex items-center justify-between bg-status-warning-bg px-4 py-2 text-sm text-status-warning"
                >
                    {failure
                        ? `Updates paused. ${failure}`
                        : 'Live updates reconnecting. Checking for new messages.'}
                    <Button variant="ghost" onClick={onRetry}>
                        Retry
                    </Button>
                </div>
            )}
            {!failure && catchingUp && (
                <div
                    role="status"
                    className="flex items-center gap-2 bg-muted px-4 py-2 text-sm text-muted-foreground"
                >
                    <Loader2
                        className="size-4 animate-spin"
                        aria-hidden="true"
                    />
                    Catching up on messages…
                </div>
            )}
        </>
    );
}
