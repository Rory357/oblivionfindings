import { Megaphone } from 'lucide-react';
import { useEffect, useState } from 'react';

import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

export type BroadcastShift = {
    id: number;
    starts_at?: string | null;
    client?: string | null;
    site?: string | null;
};

export type BroadcastDialogProps = {
    open: boolean;
    shift: BroadcastShift | null;
    onOpenChange: (open: boolean) => void;
    onConfirm: (shift: BroadcastShift, message: string | null) => void;
};

export function BroadcastDialog({
    open,
    shift,
    onOpenChange,
    onConfirm,
}: BroadcastDialogProps) {
    const [message, setMessage] = useState('');

    useEffect(() => {
        if (open) setMessage('');
    }, [open]);

    if (!shift) return null;

    const dateLabel = shift.starts_at
        ? new Date(shift.starts_at).toLocaleDateString(undefined, {
              weekday: 'long',
              day: 'numeric',
              month: 'short',
          })
        : 'this shift';

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="sm:max-w-md">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Megaphone className="h-5 w-5 text-primary" />
                        Broadcast: shift needs cover
                    </DialogTitle>
                    <DialogDescription>
                        Notifies every eligible staff member that {dateLabel}
                        {shift.client ? ` for ${shift.client}` : ''} needs
                        cover. Staff get an in-app notification and an email
                        with the shift details.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2 py-2">
                    <label
                        htmlFor="broadcast-message"
                        className="text-sm font-medium"
                    >
                        Optional message
                    </label>
                    <textarea
                        id="broadcast-message"
                        value={message}
                        onChange={(e) => setMessage(e.target.value)}
                        rows={3}
                        maxLength={2000}
                        placeholder="e.g. Urgent — please reply if you can pick this up."
                        className="block w-full rounded-md border border-input bg-background px-3 py-2 text-sm focus:border-primary focus:outline-none"
                    />
                    {shift.site || shift.client ? (
                        <div className="text-xs text-muted-foreground">
                            {shift.site ? `Site: ${shift.site}` : null}
                            {shift.site && shift.client ? ' · ' : ''}
                            {shift.client
                                ? `Client: ${shift.client}`
                                : null}
                        </div>
                    ) : null}
                </div>
                <DialogFooter>
                    <Button
                        variant="ghost"
                        onClick={() => onOpenChange(false)}
                    >
                        Cancel
                    </Button>
                    <Button
                        onClick={() =>
                            onConfirm(
                                shift,
                                message.trim() === '' ? null : message.trim(),
                            )
                        }
                    >
                        Send broadcast
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

export default BroadcastDialog;
