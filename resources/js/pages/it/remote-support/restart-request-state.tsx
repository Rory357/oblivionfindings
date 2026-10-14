import { Alert, AlertDescription } from '@/components/ui/alert';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';

export type RestartRequest = {
    executeAt: string;
    warningSeconds: number;
    consentMode: 'attended' | 'unattended';
    state:
        | 'scheduled'
        | 'cancel_requested'
        | 'awaiting_boot'
        | 'uncertain'
        | 'confirmed'
        | 'cancelled'
        | 'expired'
        | 'failed';
    cancelRequestedAt: string | null;
    canCancel: boolean;
    cancelHref: string | null;
};
const labels: Record<RestartRequest['state'], string> = {
    scheduled: 'Restart time requested',
    cancel_requested: 'Cancellation requested',
    awaiting_boot: 'Waiting for restart confirmation',
    uncertain: 'Restart result needs checking',
    confirmed: 'Restart confirmed',
    cancelled: 'Restart cancelled',
    expired: 'Restart window closed',
    failed: 'Restart could not finish',
};
export function RestartRequestState({
    restart,
    actorId,
    deviceId,
    commandId,
}: {
    restart: RestartRequest;
    actorId?: number;
    deviceId: number;
    commandId: number;
}) {
    const [open, setOpen] = useState(false),
        [reason, setReason] = useState('');
    const [busy, setBusy] = useState(false),
        [unknown, setUnknown] = useState(false),
        [notice, setNotice] = useState('');
    const generation = useRef(0);
    useEffect(
        () => () => {
            generation.current++;
        },
        [],
    );
    const href =
        '/security-devices/devices/' +
        deviceId +
        '/commands/' +
        commandId +
        '/cancel';
    const canCancel =
        restart.canCancel &&
        restart.cancelHref === href &&
        Number.isSafeInteger(actorId) &&
        (actorId ?? 0) > 0;
    const requestCancel = () => {
        if (busy || unknown || !canCancel) return;
        if (
            Array.from(reason.trim()).length < 10 ||
            Array.from(reason.trim()).length > 500 ||
            /[\p{Cc}\p{Cf}]/u.test(reason)
        ) {
            setNotice('Give a reason of 10–500 characters.');
            return;
        }
        const current = ++generation.current;
        let answered = false;
        setBusy(true);
        setNotice('');
        router.post(
            href,
            { expected_actor_id: actorId, reason: reason.trim() },
            {
                preserveScroll: true,
                onSuccess: () => {
                    if (current !== generation.current) return;
                    answered = true;
                    setOpen(false);
                    setReason('');
                },
                onError: (errors) => {
                    if (current !== generation.current) return;
                    answered = true;
                    setNotice(
                        Object.values(errors)[0] ??
                            'Cancellation could not be requested.',
                    );
                },
                onFinish: () => {
                    if (current !== generation.current) return;
                    setBusy(false);
                    if (!answered) {
                        setUnknown(true);
                        setNotice(
                            'The cancellation could not be confirmed. Check the request status before trying again.',
                        );
                    }
                },
            },
        );
    };
    return (
        <div className="mt-3 space-y-3 rounded-lg border p-3">
            <StatusBadge
                variant={
                    restart.state === 'confirmed'
                        ? 'success'
                        : ['uncertain', 'failed', 'expired'].includes(
                                restart.state,
                            )
                          ? 'warning'
                          : 'info'
                }
            >
                {labels[restart.state] ?? 'Restart state unavailable'}
            </StatusBadge>
            <p className="text-subtle">
                Requested time: {formatDateTime(restart.executeAt)} · New
                Zealand time
            </p>
            <p className="text-subtle">
                {restart.consentMode === 'attended'
                    ? 'Staff consent required'
                    : 'Authorised unattended restart'}{' '}
                · minimum warning {restart.warningSeconds} seconds
            </p>
            {restart.state === 'scheduled' && (
                <p className="text-subtle">
                    Approval and delivery follow the request status above. The
                    computer still needs to confirm the schedule.
                </p>
            )}
            {restart.state === 'cancel_requested' && (
                <p className="text-subtle">
                    Waiting for the computer to confirm cancellation. Do not
                    assume the restart has stopped.
                </p>
            )}
            {restart.state === 'awaiting_boot' && (
                <p className="text-subtle">
                    Windows accepted the request. A later computer check-in must
                    confirm the restart.
                </p>
            )}
            {restart.state === 'confirmed' && (
                <p className="text-subtle">
                    This computer checked in after the approved restart.
                </p>
            )}
            {restart.state === 'cancelled' && (
                <p className="text-subtle">
                    This request is cancelled. To postpone the restart, arrange
                    a newly approved time.
                </p>
            )}
            {restart.state === 'expired' && (
                <p className="text-subtle">
                    The deadline passed. Review a new time before sending
                    another restart request.
                </p>
            )}
            {restart.state === 'failed' && (
                <p className="text-subtle">
                    Review the recorded failure before arranging another
                    restart.
                </p>
            )}
            {restart.state === 'uncertain' && (
                <p className="text-subtle">
                    Check this computer before scheduling another restart.
                </p>
            )}
            {canCancel && (
                <Button variant="outline" onClick={() => setOpen(true)}>
                    Cancel restart request
                </Button>
            )}
            <Dialog
                open={open}
                onOpenChange={(value) => {
                    if (!busy) setOpen(value);
                }}
            >
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>Cancel scheduled restart</DialogTitle>
                        <DialogDescription>
                            Requested for {formatDateTime(restart.executeAt)}{' '}
                            (New Zealand time). Cancellation is confirmed only
                            when it is safely acknowledged. To postpone, cancel
                            this request first, then arrange a newly approved
                            time.
                        </DialogDescription>
                    </DialogHeader>
                    {notice && (
                        <Alert>
                            <AlertDescription role="status">
                                {notice}
                            </AlertDescription>
                        </Alert>
                    )}
                    <div className="space-y-2">
                        <Label htmlFor={'restart-cancel-reason-' + commandId}>
                            Reason for cancellation
                        </Label>
                        <Textarea
                            id={'restart-cancel-reason-' + commandId}
                            value={reason}
                            maxLength={500}
                            disabled={busy || unknown}
                            onChange={(event) => setReason(event.target.value)}
                        />
                    </div>
                    <DialogFooter>
                        <Button
                            variant="outline"
                            disabled={busy}
                            onClick={() => setOpen(false)}
                        >
                            Close
                        </Button>
                        {unknown ? (
                            <Button
                                onClick={() => {
                                    const current = generation.current;
                                    setBusy(true);
                                    router.reload({
                                        preserveScroll: true,
                                        onSuccess: () => {
                                            if (
                                                current === generation.current
                                            ) {
                                                setUnknown(false);
                                                setNotice('');
                                            }
                                        },
                                        onFinish: () => {
                                            if (current === generation.current)
                                                setBusy(false);
                                        },
                                    });
                                }}
                                disabled={busy}
                            >
                                Check request status
                            </Button>
                        ) : (
                            <Button
                                disabled={busy || !canCancel}
                                onClick={requestCancel}
                            >
                                {busy ? 'Requesting…' : 'Request cancellation'}
                            </Button>
                        )}
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </div>
    );
}
