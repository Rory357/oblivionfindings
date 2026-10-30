import { Button } from '@/components/ui/button';
import { formatRelative } from '@/lib/datetime';
import { hubRequest } from '@/pages/company-hub/_api';
import { useEffect, useRef, useState } from 'react';

export type RecognitionReplyData = {
    id: number;
    name: string;
    body: string;
    created_at?: string;
    version?: number;
    can_remove?: boolean;
};

type RecognitionReplyProps = {
    actorId?: number;
    kudosId: number;
    reply: RecognitionReplyData;
    onRemoved: () => void;
};

export function RecognitionReply(props: RecognitionReplyProps) {
    return (
        <RecognitionReplyBody
            key={`${props.actorId}:${props.kudosId}:${props.reply.id}:${props.reply.version}`}
            {...props}
        />
    );
}

function RecognitionReplyBody({
    actorId,
    kudosId,
    reply,
    onRemoved,
}: RecognitionReplyProps) {
    const [confirm, setConfirm] = useState(false);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const [removed, setRemoved] = useState(false);
    const pending = useRef(false);
    const alive = useRef(true);
    const trigger = useRef<HTMLButtonElement>(null);
    const keep = () => {
        if (pending.current) return;
        setConfirm(false);
        setError('');
        requestAnimationFrame(() => trigger.current?.focus());
    };
    const identity = `${actorId}:${kudosId}:${reply.id}:${reply.version}`;
    const current = useRef(identity);
    current.current = identity;
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const remove = async () => {
        if (pending.current || !actorId || !reply.version || !reply.can_remove)
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        const submittedIdentity = identity;
        try {
            const result = await hubRequest<{
                saved: boolean;
                kudos_id: number;
                reply_id: number;
            }>(`/hr/feed/kudos/${kudosId}/replies/${reply.id}`, 'DELETE', {
                actor_id: actorId,
                version: reply.version,
            });
            if (!alive.current || current.current !== submittedIdentity) return;
            if (
                result.saved !== true ||
                result.kudos_id !== kudosId ||
                result.reply_id !== reply.id
            ) {
                throw new Error(
                    'The removal could not be confirmed. Refresh the conversation before trying again.',
                );
            }
            setRemoved(true);
            onRemoved();
        } catch (failure) {
            if (alive.current && current.current === submittedIdentity) {
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Your reply could not be removed. Try again.',
                );
            }
        } finally {
            pending.current = false;
            if (alive.current && current.current === submittedIdentity)
                setBusy(false);
        }
    };
    if (removed)
        return (
            <p role="status" className="text-xs text-muted-foreground">
                Your reply was removed.
            </p>
        );
    return (
        <article className="space-y-2 rounded-xl bg-muted/50 p-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <strong className="text-sm">{reply.name}</strong>
                {reply.created_at && (
                    <span className="text-xs text-muted-foreground">
                        {formatRelative(reply.created_at)}
                    </span>
                )}
            </div>
            <p className="text-sm leading-relaxed break-words whitespace-pre-wrap">
                {reply.body}
            </p>
            {!!actorId &&
                !!reply.version &&
                reply.can_remove &&
                (confirm ? (
                    <div
                        className="space-y-2 border-t pt-3"
                        onKeyDown={(event) => {
                            if (event.key === 'Escape') {
                                event.preventDefault();
                                event.stopPropagation();
                                keep();
                            }
                        }}
                    >
                        <p className="text-xs text-muted-foreground">
                            Remove your reply? This cannot be undone.
                        </p>
                        <div className="flex flex-wrap gap-2">
                            <Button
                                size="sm"
                                variant="destructive"
                                disabled={busy}
                                onClick={() => void remove()}
                            >
                                {busy ? 'Removing…' : 'Confirm removal'}
                            </Button>
                            <Button
                                size="sm"
                                variant="ghost"
                                autoFocus
                                disabled={busy}
                                onClick={keep}
                            >
                                Keep reply
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button
                        ref={trigger}
                        size="sm"
                        variant="ghost"
                        className="-ml-2 h-8 text-xs text-muted-foreground"
                        onClick={() => setConfirm(true)}
                    >
                        Remove my reply
                    </Button>
                ))}
            {error && (
                <p role="alert" className="text-xs text-destructive">
                    {error}
                </p>
            )}
        </article>
    );
}
