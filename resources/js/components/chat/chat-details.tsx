import { Button } from '@/components/ui/button';
import { cn } from '@/lib/utils';
import { X } from 'lucide-react';
import { useRef } from 'react';
import { CallHistory } from './call-history';
import { ChatCollection } from './chat-collection';
import { ChatInvitationHistory } from './chat-invitation-history';
import { ChatManagement } from './chat-management';
import { ChatPeopleInvite } from './chat-people-invite';
import type { ChatConversation } from './types';

export function ChatDetails({
    conversation,
    actorId,
    onClose,
    onMessage,
    onChanged,
    onExit,
    compact = false,
}: {
    conversation: ChatConversation;
    actorId: number;
    onClose: () => void;
    onMessage: (id: number) => void;
    onChanged: () => void;
    onExit: () => void;
    compact?: boolean;
}) {
    const invitation = useRef<{ open: (personId?: number) => void }>(null);
    return (
        <aside
            aria-label="Conversation details"
            className={cn(
                'flex h-full min-h-0 w-full shrink-0 flex-col gap-4 overflow-y-auto border-l bg-background p-4',
                !compact && 'md:w-64 xl:w-80',
            )}
            onKeyDown={(event) => {
                if (
                    event.key === 'Escape' &&
                    !event.defaultPrevented &&
                    (event.target as HTMLElement).closest('[role="dialog"]') ===
                        event.currentTarget.closest('[role="dialog"]')
                ) {
                    event.preventDefault();
                    onClose();
                }
            }}
        >
            <div className="flex items-center justify-between gap-2">
                <h2 className="font-semibold">Conversation details</h2>
                <Button
                    autoFocus
                    variant="ghost"
                    size="icon"
                    className="size-11 shrink-0"
                    aria-label="Close details"
                    onClick={onClose}
                >
                    <X className="size-4" />
                </Button>
            </div>
            <div>
                <h3 className="font-medium break-words">
                    {conversation.title}
                </h3>
                <p className="mt-1 text-sm text-muted-foreground">
                    {conversation.client
                        ? `About ${conversation.client.name}`
                        : 'Private staff conversation'}
                </p>
            </div>
            <ul className="space-y-3">
                {conversation.participants.map((person) => (
                    <li
                        key={person.id}
                        className="flex items-center justify-between gap-2 rounded-xl bg-muted/40 p-3 text-sm"
                    >
                        <span className="min-w-0 break-words">
                            {person.name}
                        </span>
                        {person.id === actorId && (
                            <span className="text-xs text-muted-foreground">
                                You
                            </span>
                        )}
                        {conversation.management?.owner_ids.includes(
                            person.id,
                        ) && (
                            <span className="text-xs text-muted-foreground">
                                Owner
                            </span>
                        )}
                    </li>
                ))}
            </ul>
            {conversation.care_team_cover && (
                <p className="text-sm text-muted-foreground">
                    Authorised care-team cover may also read and respond. Other
                    relatives are not included automatically.
                </p>
            )}
            <div className="grid gap-2">
                {(['media', 'saved', 'pinned'] as const).map((kind) => (
                    <ChatCollection
                        actorId={actorId}
                        key={kind}
                        kind={kind}
                        conversationId={conversation.id}
                        onSelect={(item) => onMessage(item.message.id)}
                    />
                ))}
                <ChatPeopleInvite
                    actorId={actorId}
                    ref={invitation}
                    key={`invite:${conversation.id}`}
                    conversation={conversation}
                    onChanged={onChanged}
                />
                <ChatInvitationHistory
                    actorId={actorId}
                    key={`invitations:${conversation.id}`}
                    conversation={conversation}
                    onChanged={onChanged}
                    onReinvite={(personId) =>
                        invitation.current?.open(personId)
                    }
                />
                <ChatManagement
                    key={`management:${conversation.id}`}
                    conversation={conversation}
                    actorId={actorId}
                    onChanged={onChanged}
                    onExit={onExit}
                />
            </div>
            <div className="flex items-center justify-between border-t pt-3">
                <span className="text-sm">Call history</span>
                <CallHistory
                    key={`${actorId}:${conversation.id}`}
                    actorId={actorId}
                    conversationId={conversation.id}
                />
            </div>
        </aside>
    );
}
