import { Button } from '@/components/ui/button';
import { Headphones, Phone, UserRound, Volume2 } from 'lucide-react';

export type CallParticipant = {
    id: number;
    name: string;
    status: string;
    invitation_id?: string;
};

const labels: Record<string, string> = {
    invited: 'Invited',
    accepted: 'Connecting',
    connected: 'Connected',
    left: 'Left the call',
    declined: 'Declined',
    expired: 'No answer',
    revoked: 'Access ended',
    removing: 'Removing from call',
    removed: 'Removed',
    answered_elsewhere: 'Answered by another care-team member',
};

export function CallParticipants({
    people,
    actorId,
    hostId,
    muted,
    maxParticipants,
    onRemove,
    working = false,
    speakers = [],
}: {
    people: CallParticipant[];
    actorId: number;
    hostId: number;
    muted: boolean;
    maxParticipants?: number;
    onRemove?: (person: CallParticipant) => void;
    working?: boolean;
    speakers?: string[];
}) {
    // Care-team cover invitations close without those responders joining the call.
    const visible = people.filter(
        (person) => person.status !== 'answered_elsewhere',
    );
    if (!visible.length) return null;
    return (
        <section
            aria-label="Call participants"
            className="mt-4 rounded-xl border p-3 text-left"
        >
            <h3 className="text-sm font-semibold">People in this call</h3>
            {maxParticipants !== undefined && (
                <p className="mt-1 text-xs text-muted-foreground">
                    {
                        visible.filter((person) =>
                            [
                                'invited',
                                'accepted',
                                'connected',
                                'removing',
                            ].includes(person.status),
                        ).length
                    }{' '}
                    / {maxParticipants} places, including invitations
                </p>
            )}
            <ul className="mt-2 grid gap-2 sm:grid-cols-2">
                {visible.map((person) => {
                    const Icon =
                        person.status === 'connected'
                            ? Headphones
                            : person.status === 'invited'
                              ? Phone
                              : UserRound;
                    return (
                        <li
                            key={person.id}
                            className="flex min-w-0 items-start gap-2 rounded-lg bg-muted/40 p-2"
                        >
                            <span
                                className="flex size-8 shrink-0 items-center justify-center rounded-full bg-background"
                                aria-hidden="true"
                            >
                                <Icon className="size-4" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <p className="text-sm font-medium break-words">
                                    {person.name}
                                    {person.id === actorId ? ' (you)' : ''}
                                </p>
                                <p className="text-xs break-words text-muted-foreground">
                                    {labels[person.status] ?? 'Checking status'}
                                    {person.id === actorId &&
                                    muted &&
                                    ['accepted', 'connected'].includes(
                                        person.status,
                                    )
                                        ? ' · Microphone muted'
                                        : ''}
                                </p>
                                {person.status === 'connected' &&
                                    person.invitation_id &&
                                    speakers.includes(person.invitation_id) &&
                                    !(person.id === actorId && muted) && (
                                        <span className="mt-1 inline-flex items-center gap-1 text-xs font-medium text-primary">
                                            <Volume2
                                                className="size-3"
                                                aria-hidden="true"
                                            />
                                            Speaking
                                        </span>
                                    )}
                                {person.id === hostId &&
                                    ['accepted', 'connected'].includes(
                                        person.status,
                                    ) && (
                                        <span className="mt-1 inline-block rounded bg-primary/10 px-1.5 py-0.5 text-xs font-medium text-primary">
                                            Host
                                        </span>
                                    )}
                                {onRemove &&
                                    person.id !== actorId &&
                                    person.invitation_id &&
                                    [
                                        'invited',
                                        'accepted',
                                        'connected',
                                    ].includes(person.status) && (
                                        <Button
                                            variant="ghost"
                                            className="mt-1 min-h-[44px] px-2 text-xs"
                                            disabled={working}
                                            onClick={() => onRemove(person)}
                                            aria-label={`Remove ${person.name} from call`}
                                        >
                                            Remove
                                        </Button>
                                    )}
                            </div>
                        </li>
                    );
                })}
            </ul>
            <p className="mt-2 text-xs text-muted-foreground">
                Everyone controls their own microphone.
            </p>
        </section>
    );
}
