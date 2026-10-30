import { Button } from '@/components/ui/button';
import { hubRequest } from '@/pages/company-hub/_api';
import { Heart } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

type Appreciation = { count: number; mine: boolean };
type Props = {
    actorId?: number;
    pageId: number;
    revisionId?: number;
    comment: {
        id: number;
        version?: number;
        can_appreciate?: boolean;
        appreciation?: Appreciation;
    };
    onSaved: () => void;
};

export function CommentAppreciation(props: Props) {
    return (
        <AppreciationAction
            key={`${props.actorId}:${props.pageId}:${props.revisionId}:${props.comment.id}:${props.comment.version}:${props.comment.can_appreciate}`}
            {...props}
        />
    );
}

function AppreciationAction({
    actorId,
    pageId,
    revisionId,
    comment,
    onSaved,
}: Props) {
    const [confirmed, setConfirmed] = useState<{
        source: Appreciation | undefined;
        value: Appreciation;
    } | null>(null);
    const value =
        confirmed && confirmed.source === comment.appreciation
            ? confirmed.value
            : (comment.appreciation ?? { count: 0, mine: false });
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const pending = useRef(false);
    const alive = useRef(true);
    useEffect(() => {
        alive.current = true;
        return () => {
            alive.current = false;
        };
    }, []);
    const change = async () => {
        if (
            pending.current ||
            !actorId ||
            !revisionId ||
            !comment.version ||
            !comment.can_appreciate
        )
            return;
        pending.current = true;
        setBusy(true);
        setError('');
        const active = !value.mine;
        try {
            const result = await hubRequest<{
                saved: boolean;
                comment_id: number;
                version: number;
                appreciation: Appreciation;
            }>(
                `/company-hub/pages/${pageId}/comments/${comment.id}/appreciation`,
                'PUT',
                {
                    actor_id: actorId,
                    revision_id: revisionId,
                    version: comment.version,
                    active,
                },
            );
            if (!alive.current) return;
            if (
                result.saved !== true ||
                result.comment_id !== comment.id ||
                result.version !== comment.version ||
                !Number.isInteger(result.appreciation?.count) ||
                result.appreciation.count < 0 ||
                result.appreciation.mine !== active
            ) {
                throw new Error(
                    'Your appreciation could not be confirmed. Refresh the conversation and try again.',
                );
            }
            setConfirmed({
                source: comment.appreciation,
                value: result.appreciation,
            });
            onSaved();
        } catch (failure) {
            if (alive.current)
                setError(
                    failure instanceof Error
                        ? failure.message
                        : 'Your appreciation could not be saved. Try again.',
                );
        } finally {
            pending.current = false;
            if (alive.current) setBusy(false);
        }
    };
    if (!comment.appreciation && !comment.can_appreciate) return null;
    return (
        <div className="inline-flex flex-col items-start">
            <Button
                size="sm"
                variant="ghost"
                className={
                    value.mine ? 'text-primary' : 'text-muted-foreground'
                }
                aria-pressed={value.mine}
                aria-busy={busy}
                aria-label={`${value.mine ? 'Remove appreciation from' : 'Appreciate'} comment${value.count ? ` · ${value.count} appreciations` : ''}`}
                disabled={
                    busy ||
                    !actorId ||
                    !revisionId ||
                    !comment.version ||
                    !comment.can_appreciate
                }
                onClick={() => void change()}
            >
                <Heart
                    className={value.mine ? 'size-4 fill-current' : 'size-4'}
                />
                {busy ? 'Saving…' : value.mine ? 'Appreciated' : 'Appreciate'}
                {value.count > 0 && (
                    <span aria-label={`${value.count} appreciations`}>
                        {value.count}
                    </span>
                )}
            </Button>
            {error && (
                <p
                    role="alert"
                    className="max-w-sm px-2 text-xs text-destructive"
                >
                    {error}
                </p>
            )}
        </div>
    );
}
