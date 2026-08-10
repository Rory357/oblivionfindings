import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { router } from '@inertiajs/react';
import { Clock3 } from 'lucide-react';
import { type FormEvent, useEffect, useState } from 'react';
import { toast } from 'sonner';

export type WaitingParty =
    | 'requester'
    | 'vendor'
    | 'approver'
    | 'team'
    | 'change'
    | 'other';

export interface TicketWaitingDetails {
    party: WaitingParty | 'other';
    reason?: string | null;
    next_action?: string | null;
    since: string | null;
    since_human: string | null;
}

const WAITING_PARTIES: { value: WaitingParty; label: string }[] = [
    { value: 'requester', label: 'Requester' },
    { value: 'vendor', label: 'Vendor or supplier' },
    { value: 'approver', label: 'Approver' },
    { value: 'team', label: 'Internal team' },
    { value: 'change', label: 'Related change' },
    { value: 'other', label: 'Other dependency' },
];

export function waitingPartyLabel(party: string | null | undefined): string {
    return (
        WAITING_PARTIES.find((option) => option.value === party)?.label ??
        'Other dependency'
    );
}

export function waitingStatusLabel(
    party: string | null | undefined,
    requesterView = false,
): string {
    if (requesterView) {
        return party === 'requester' ? 'Waiting on you' : 'Waiting on IT';
    }

    return `Waiting · ${waitingPartyLabel(party)}`;
}

export function requesterWaitingCopy(party: string | null | undefined): string {
    return party === 'requester'
        ? 'Please reply in the conversation with the requested information so IT can continue.'
        : 'IT is waiting for another team or dependency and will continue when it is ready.';
}

interface Props {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    scope: 'single' | 'bulk';
    ticketIds: number[];
    ticketReference?: string | null;
    current?: TicketWaitingDetails | null;
    onCompleted?: () => void;
}

/** Governed waiting evidence shared by a ticket and the bulk queue. */
export function TicketWaitingDialog({
    open,
    onOpenChange,
    scope,
    ticketIds,
    ticketReference,
    current = null,
    onCompleted,
}: Props) {
    const [party, setParty] = useState<WaitingParty>('requester');
    const [reason, setReason] = useState('');
    const [nextAction, setNextAction] = useState('');
    const [processing, setProcessing] = useState(false);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const bulk = scope === 'bulk';

    useEffect(() => {
        if (!open) return;
        setParty(
            WAITING_PARTIES.some((option) => option.value === current?.party)
                ? (current?.party as WaitingParty)
                : 'requester',
        );
        setReason(current?.reason ?? '');
        setNextAction(current?.next_action ?? '');
        setErrors({});
    }, [current, open]);

    const close = () => {
        if (processing) return;
        setErrors({});
        onOpenChange(false);
    };

    const submit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        const recordedReason = reason.trim();
        if (recordedReason === '' || ticketIds.length === 0 || processing) {
            return;
        }

        const waiting = {
            waiting_party: party,
            waiting_reason: recordedReason,
            next_action: nextAction.trim() || null,
        };
        const url = bulk ? '/it/tickets/bulk' : `/it/tickets/${ticketIds[0]}`;
        const payload = bulk
            ? {
                  ids: ticketIds,
                  action: 'status',
                  status: 'waiting',
                  ...waiting,
              }
            : { status: 'waiting', ...waiting };
        const options = {
            preserveScroll: true,
            preserveState: bulk,
            onStart: () => setProcessing(true),
            onError: (nextErrors: Record<string, string>) =>
                setErrors(nextErrors),
            onSuccess: (page: { props: Record<string, unknown> }) => {
                const flash = page.props.flash as
                    | { error?: string; success?: string }
                    | undefined;
                if (flash?.error) {
                    toast.error(flash.error);
                    return;
                }

                toast.success(
                    flash?.success ??
                        (bulk
                            ? 'Selected tickets are waiting.'
                            : 'Waiting details recorded.'),
                );
                onOpenChange(false);
                onCompleted?.();
            },
            onFinish: () => setProcessing(false),
        };

        if (bulk) {
            router.post(url, payload, options);
        } else {
            router.patch(url, payload, options);
        }
    };

    const title = bulk
        ? `Set ${ticketIds.length} selected ticket${ticketIds.length === 1 ? '' : 's'} waiting`
        : `${current ? 'Edit waiting details for' : 'Set waiting for'} ${ticketReference ?? 'this ticket'}`;

    return (
        <Dialog open={open} onOpenChange={(next) => !next && close()}>
            <DialogContent className="sm:max-w-lg">
                <form onSubmit={submit}>
                    <DialogHeader>
                        <DialogTitle className="flex items-center gap-2">
                            <Clock3
                                className="h-5 w-5 text-status-warning"
                                aria-hidden="true"
                            />
                            {title}
                        </DialogTitle>
                        <DialogDescription>
                            Record the dependency so queues, handovers and SLA
                            pauses stay accurate. These details are visible to
                            authorised technicians. If the requester must act,
                            also send them a public reply.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="mt-5 space-y-4">
                        <div className="space-y-2">
                            <label
                                htmlFor={`ticket-waiting-party-${scope}`}
                                className="text-sm font-medium"
                            >
                                Who or what is IT waiting for?
                            </label>
                            <Select
                                value={party}
                                onValueChange={(value) =>
                                    setParty(value as WaitingParty)
                                }
                            >
                                <SelectTrigger
                                    id={`ticket-waiting-party-${scope}`}
                                    className="min-h-11"
                                    aria-label="Who or what is IT waiting for?"
                                    aria-invalid={
                                        errors.waiting_party ? true : undefined
                                    }
                                >
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {WAITING_PARTIES.map((option) => (
                                        <SelectItem
                                            key={option.value}
                                            value={option.value}
                                        >
                                            {option.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            {errors.waiting_party ? (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {errors.waiting_party}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <label
                                htmlFor={`ticket-waiting-reason-${scope}`}
                                className="text-sm font-medium"
                            >
                                Reason for waiting
                            </label>
                            <Textarea
                                id={`ticket-waiting-reason-${scope}`}
                                value={reason}
                                onChange={(event) =>
                                    setReason(event.target.value)
                                }
                                placeholder="What must happen before work can continue?"
                                required
                                rows={3}
                                maxLength={1000}
                                aria-invalid={
                                    errors.waiting_reason ? true : undefined
                                }
                            />
                            {errors.waiting_reason ? (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {errors.waiting_reason}
                                </p>
                            ) : null}
                        </div>

                        <div className="space-y-2">
                            <label
                                htmlFor={`ticket-waiting-next-${scope}`}
                                className="text-sm font-medium"
                            >
                                Next action{' '}
                                <span className="text-muted-foreground">
                                    (optional)
                                </span>
                            </label>
                            <Textarea
                                id={`ticket-waiting-next-${scope}`}
                                value={nextAction}
                                onChange={(event) =>
                                    setNextAction(event.target.value)
                                }
                                placeholder="For example, chase the supplier tomorrow at 10 am"
                                rows={2}
                                maxLength={2000}
                                aria-invalid={
                                    errors.next_action ? true : undefined
                                }
                            />
                            {errors.next_action ? (
                                <p
                                    role="alert"
                                    className="text-sm text-destructive"
                                >
                                    {errors.next_action}
                                </p>
                            ) : null}
                        </div>
                    </div>

                    <DialogFooter className="mt-6 gap-2 sm:gap-0">
                        <Button
                            type="button"
                            variant="outline"
                            className="min-h-11"
                            disabled={processing}
                            onClick={close}
                        >
                            Keep current status
                        </Button>
                        <Button
                            type="submit"
                            className="min-h-11"
                            disabled={
                                processing ||
                                reason.trim() === '' ||
                                ticketIds.length === 0
                            }
                        >
                            <Clock3 className="h-4 w-4" aria-hidden="true" />
                            {processing
                                ? 'Saving…'
                                : bulk
                                  ? `Set ${ticketIds.length} ticket${ticketIds.length === 1 ? '' : 's'} waiting`
                                  : 'Set waiting'}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    );
}
