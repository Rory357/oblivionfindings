import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { formatDateTime } from '@/lib/datetime';
import { Bookmark, Pencil, Pin, Reply, ThumbsUp, Trash2 } from 'lucide-react';
import type { ChatMessage } from '../types';

/** The pop-up of things to do with one message. */
export function ThreadMessageActions({
    message,
    acting,
    canSend,
    composerBusy,
    editing,
    onClose,
    onCloseAutoFocus,
    onReply,
    onAction,
    onEdit,
    onRemove,
}: {
    message: ChatMessage | null;
    acting: boolean;
    canSend: boolean;
    /** A send is in flight or unconfirmed, so edit and remove wait. */
    composerBusy: boolean;
    editing: boolean;
    onClose: () => void;
    onCloseAutoFocus: (event: Event) => void;
    onReply: (message: ChatMessage) => void;
    onAction: (
        message: ChatMessage,
        action: 'save' | 'pin' | 'react',
        emoji?: string,
    ) => void;
    onEdit: (message: ChatMessage) => void;
    onRemove: (message: ChatMessage) => void;
}) {
    return (
        <Dialog
            open={Boolean(message)}
            onOpenChange={(open) => {
                if (!open && !acting) onClose();
            }}
        >
            <DialogContent
                style={{
                    maxWidth: 'min(92vw, 480px)',
                    width: 'min(92vw, 480px)',
                }}
                className="max-h-[85dvh] overflow-x-hidden overflow-y-auto"
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <DialogHeader>
                    <DialogTitle>Message actions</DialogTitle>
                    <DialogDescription>
                        {message?.sender_name} ·{' '}
                        {message && formatDateTime(message.created_at)}
                    </DialogDescription>
                </DialogHeader>
                <p className="line-clamp-4 rounded-xl bg-muted/40 p-3 text-sm break-words">
                    {message?.content || 'Attachment'}
                </p>
                {message && (
                    <div className="grid grid-cols-1 gap-2 min-[360px]:grid-cols-2">
                        <Button
                            variant="outline"
                            className="min-h-[44px] justify-start"
                            disabled={acting || !canSend}
                            onClick={() => onReply(message)}
                        >
                            <Reply className="size-4" />
                            Reply
                        </Button>
                        <Button
                            variant="outline"
                            className="min-h-[44px] justify-start"
                            disabled={acting}
                            onClick={() => onAction(message, 'save')}
                        >
                            <Bookmark className="size-4" />
                            {message.saved ? 'Unsave' : 'Save for me'}
                        </Button>
                        <Button
                            variant="outline"
                            className="min-h-[44px] justify-start"
                            disabled={acting || !canSend}
                            onClick={() => onAction(message, 'react', '👍')}
                        >
                            <ThumbsUp className="size-4" />
                            {message.reactions.some(
                                (reaction) =>
                                    reaction.emoji === '👍' && reaction.mine,
                            )
                                ? 'Remove reaction'
                                : 'Acknowledge'}
                        </Button>
                        <Button
                            variant="outline"
                            className="min-h-[44px] justify-start"
                            disabled={acting || !canSend}
                            onClick={() => onAction(message, 'pin')}
                        >
                            <Pin className="size-4" />
                            {message.pinned ? 'Unpin' : 'Pin'}
                        </Button>
                        {message.mine && (
                            <Button
                                variant="outline"
                                className="min-h-[44px] justify-start"
                                disabled={
                                    acting ||
                                    composerBusy ||
                                    editing ||
                                    !message.can_edit ||
                                    !canSend
                                }
                                onClick={() => onEdit(message)}
                            >
                                <Pencil className="size-4" />
                                Edit my message
                            </Button>
                        )}
                        {message.mine && (
                            <Button
                                variant="outline"
                                className="min-h-[44px] justify-start"
                                disabled={
                                    acting ||
                                    editing ||
                                    composerBusy ||
                                    !canSend ||
                                    !message.can_remove
                                }
                                onClick={() => onRemove(message)}
                            >
                                <Trash2 className="size-4" />
                                Remove my message
                            </Button>
                        )}
                    </div>
                )}
                {acting && (
                    <p role="status" className="text-sm text-muted-foreground">
                        Saving your change…
                    </p>
                )}
                {!message?.mine && (
                    <p className="text-xs text-muted-foreground">
                        Only the sender can edit or remove this message.
                    </p>
                )}
                {message?.mine && message.change_unavailable_reason && (
                    <p className="text-xs text-muted-foreground">
                        {message.change_unavailable_reason}
                    </p>
                )}
            </DialogContent>
        </Dialog>
    );
}
