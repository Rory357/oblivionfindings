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
import type { ItDraftFields } from '@/hooks/it-ticket-draft-contract';
import type { SharedData } from '@/types';
import { router, usePage } from '@inertiajs/react';
import { Clock3 } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import type { ItBulkResult } from './it-bulk-result';
import type { TicketVersions } from './ticket-version-conflict';
import { useTicketFormCommand } from './use-ticket-form-command';

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
const waitingFields = [
    'status',
    'waiting_party',
    'waiting_reason',
    'next_action',
];
const acceptsWaitingFields = (saved: ItDraftFields) =>
    Object.keys(saved).every((key) => waitingFields.includes(key)) &&
    saved.status === 'waiting' &&
    WAITING_PARTIES.some((option) => option.value === saved.waiting_party) &&
    (saved.waiting_reason == null ||
        typeof saved.waiting_reason === 'string') &&
    (saved.next_action == null || typeof saved.next_action === 'string');

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
    expectedVersions: TicketVersions;
    ticketReference?: string | null;
    current?: TicketWaitingDetails | null;
    onCompleted?: () => void;
    onBulkResult?: (result: ItBulkResult) => void;
}

/** The child lifetime captures one original actor, selection and displayed versions. */
export function TicketWaitingDialog(props: Props) {
    const page = usePage<
        SharedData & { draftRecovery?: { enabled: boolean } }
    >();
    if (!props.open) return null;
    return (
        <TicketWaitingForm
            {...props}
            actorId={page.props.auth.user?.id}
            draftsEnabled={page.props.draftRecovery?.enabled === true}
        />
    );
}
function TicketWaitingForm({
    onOpenChange,
    scope,
    ticketIds: selectedIds,
    expectedVersions,
    ticketReference,
    current = null,
    onCompleted,
    onBulkResult,
    actorId,
    draftsEnabled,
}: Props & { actorId: number | undefined; draftsEnabled: boolean }) {
    const [ticketIds] = useState(() => [...selectedIds]);
    const [initial] = useState(() => ({
        party: WAITING_PARTIES.some((option) => option.value === current?.party)
            ? current!.party
            : ('requester' as WaitingParty),
        reason: current?.reason ?? '',
        nextAction: current?.next_action ?? '',
    }));
    const [party, setParty] = useState<WaitingParty>(initial.party);
    const [reason, setReason] = useState(initial.reason);
    const [nextAction, setNextAction] = useState(initial.nextAction);
    const bulk = scope === 'bulk';
    const fields: ItDraftFields = useMemo(
        () => ({
            status: 'waiting',
            waiting_party: party,
            waiting_reason: reason,
            next_action: nextAction || null,
        }),
        [party, reason, nextAction],
    );
    const command = useTicketFormCommand({
        actorId,
        ticketIds: selectedIds,
        expectedVersions,
        fields,
        bulk,
        draftsEnabled,
        acceptedFields: waitingFields,
        acceptsFields: acceptsWaitingFields,
        dirty:
            party !== initial.party ||
            reason !== initial.reason ||
            nextAction !== initial.nextAction,
        onClear: () => {
            setReason('');
            setNextAction('');
            setParty('requester');
        },
        onClose: () => onOpenChange(false),
        onBulkResult,
        onCommitted: () => {
            if (!bulk) toast.success('Waiting details recorded.');
            onOpenChange(false);
            onCompleted?.();
            router.reload({ preserveScroll: true });
        },
        onResume: (saved) => {
            if (!acceptsWaitingFields(saved)) return false;
            setParty(saved.waiting_party as WaitingParty);
            setReason(saved.waiting_reason ?? '');
            setNextAction(saved.next_action ?? '');
            return true;
        },
    });
    const errors = command.errors;
    const processing = command.busy;
    const close = command.close;
    const title = bulk
        ? `Set ${ticketIds.length} selected ticket${ticketIds.length === 1 ? '' : 's'} waiting`
        : `${current ? 'Edit waiting details for' : 'Set waiting for'} ${ticketReference ?? 'this ticket'}`;

    return (
        <>
            <Dialog open onOpenChange={(next) => !next && close()}>
                <DialogContent className="sm:max-w-lg">
                    <form
                        onSubmit={(event) => {
                            event.preventDefault();
                            if (reason.trim()) void command.submit();
                        }}
                    >
                        <DialogHeader>
                            <DialogTitle className="flex items-center gap-2">
                                <Clock3
                                    className="h-5 w-5 text-status-warning"
                                    aria-hidden="true"
                                />
                                {command.concealed
                                    ? 'Waiting details unavailable'
                                    : title}
                            </DialogTitle>
                            <DialogDescription>
                                Record the dependency so queues, handovers and
                                SLA pauses stay accurate. These details are
                                visible to authorised technicians. If the
                                requester must act, also send them a public
                                reply.
                            </DialogDescription>
                        </DialogHeader>

                        {command.recovery}
                        {!command.concealed && (
                            <fieldset
                                disabled={command.locked}
                                className="mt-5 space-y-4"
                            >
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
                                                errors.waiting_party
                                                    ? true
                                                    : undefined
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
                                            errors.waiting_reason
                                                ? true
                                                : undefined
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
                                            errors.next_action
                                                ? true
                                                : undefined
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
                            </fieldset>
                        )}

                        <DialogFooter className="mt-6 gap-2 sm:gap-0">
                            <Button
                                type="button"
                                variant="outline"
                                className="min-h-11"
                                onClick={close}
                            >
                                Close waiting form
                            </Button>
                            {processing && (
                                <Button
                                    type="button"
                                    variant="outline"
                                    onClick={command.cancelWait}
                                >
                                    Cancel wait
                                </Button>
                            )}
                            <Button
                                type="submit"
                                className="min-h-11"
                                disabled={
                                    !command.ready || reason.trim() === ''
                                }
                            >
                                <Clock3
                                    className="h-4 w-4"
                                    aria-hidden="true"
                                />
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
            {command.confirmation}
        </>
    );
}
