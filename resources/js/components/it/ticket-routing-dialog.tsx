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
import { useMemo, useState } from 'react';
import { toast } from 'sonner';
import type { TicketRoutingDetails } from './ticket-routing-summary';
import { useTicketFormCommand } from './use-ticket-form-command';

type Option = { id: number; name: string };
const NONE = 'none';
const selected = (value: Option | null | undefined) =>
    value ? String(value.id) : NONE;
const routingFields = [
    'queue_id',
    'owner_user_id',
    'assigned_to_user_id',
    'release_routing_override',
    'routing_reason',
];
const acceptsRoutingFields = (saved: ItDraftFields) =>
    Object.keys(saved).every((key) => routingFields.includes(key)) &&
    (saved.routing_reason == null ||
        typeof saved.routing_reason === 'string') &&
    !(
        saved.release_routing_override &&
        Object.keys(saved).some((key) =>
            ['queue_id', 'owner_user_id', 'assigned_to_user_id'].includes(key),
        )
    );

interface Props {
    ticket: {
        id: number;
        lock_version: number;
        assignee: Option | null;
        routing: TicketRoutingDetails;
    };
    queues: Option[];
    agents: Option[];
    onClose: () => void;
}
export function TicketRoutingDialog(props: Props) {
    const page = usePage<
        SharedData & { draftRecovery?: { enabled: boolean } }
    >();
    return (
        <TicketRoutingForm
            {...props}
            actorId={page.props.auth.user?.id}
            draftsEnabled={page.props.draftRecovery?.enabled === true}
        />
    );
}
function TicketRoutingForm({
    ticket,
    queues,
    agents,
    onClose,
    actorId,
    draftsEnabled,
}: Props & { actorId: number | undefined; draftsEnabled: boolean }) {
    const [initial] = useState(() => ({
        mode: 'manual',
        queue_id: selected(ticket.routing.queue),
        owner_user_id: selected(ticket.routing.owner),
        assigned_to_user_id: selected(ticket.assignee),
        routing_reason: '',
    }));
    const [data, setData] = useState(initial);
    const fields: ItDraftFields = useMemo(
        () =>
            data.mode === 'automatic'
                ? {
                      release_routing_override: true,
                      routing_reason: data.routing_reason,
                  }
                : {
                      queue_id:
                          data.queue_id === NONE ? null : Number(data.queue_id),
                      owner_user_id:
                          data.owner_user_id === NONE
                              ? null
                              : Number(data.owner_user_id),
                      assigned_to_user_id:
                          data.assigned_to_user_id === NONE
                              ? null
                              : Number(data.assigned_to_user_id),
                      routing_reason: data.routing_reason,
                  },
        [data],
    );
    const command = useTicketFormCommand({
        actorId,
        ticketIds: [ticket.id],
        expectedVersions: { [ticket.id]: ticket.lock_version },
        fields,
        dirty: JSON.stringify(data) !== JSON.stringify(initial),
        draftsEnabled,
        acceptedFields: routingFields,
        acceptsFields: acceptsRoutingFields,
        onClose,
        onClear: () =>
            setData({
                mode: 'manual',
                queue_id: NONE,
                owner_user_id: NONE,
                assigned_to_user_id: NONE,
                routing_reason: '',
            }),
        onCommitted: () => {
            toast.success('Ticket routing saved.');
            onClose();
            router.reload({ preserveScroll: true });
        },
        onResume: (saved) => {
            if (!acceptsRoutingFields(saved)) return false;
            setData({
                mode: saved.release_routing_override ? 'automatic' : 'manual',
                queue_id:
                    saved.queue_id == null ? NONE : String(saved.queue_id),
                owner_user_id:
                    saved.owner_user_id == null
                        ? NONE
                        : String(saved.owner_user_id),
                assigned_to_user_id:
                    saved.assigned_to_user_id == null
                        ? NONE
                        : String(saved.assigned_to_user_id),
                routing_reason: saved.routing_reason ?? '',
            });
            return true;
        },
    });
    const form = {
        data,
        processing: command.busy,
        setData: (key: keyof typeof data, value: string) =>
            setData((current) => ({ ...current, [key]: value })),
    };
    const close = command.close;
    const submit = () => {
        if (data.routing_reason.trim()) void command.submit();
    };
    return (
        <>
            <Dialog open onOpenChange={(next) => !next && close()}>
                <DialogContent>
                    <DialogHeader>
                        <DialogTitle>
                            {command.concealed
                                ? 'Routing details unavailable'
                                : 'Change ticket routing'}
                        </DialogTitle>
                        <DialogDescription>
                            Choose who is accountable and explain the change.
                            Manual choices remain in place until explicitly
                            released; unavailable people cannot receive new
                            work.
                        </DialogDescription>
                    </DialogHeader>
                    {command.recovery}
                    {!command.concealed && (
                        <fieldset
                            disabled={command.locked}
                            className="space-y-4"
                        >
                            <div className="space-y-2">
                                <Label>Routing choice</Label>
                                <Select
                                    value={form.data.mode}
                                    onValueChange={(value) =>
                                        form.setData('mode', value)
                                    }
                                >
                                    <SelectTrigger aria-label="Routing choice">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        <SelectItem value="manual">
                                            Set manual ownership
                                        </SelectItem>
                                        <SelectItem value="automatic">
                                            Use automatic routing
                                        </SelectItem>
                                    </SelectContent>
                                </Select>
                            </div>
                            {form.data.mode === 'manual' ? (
                                <>
                                    <RoutingSelect
                                        label="Queue"
                                        allowClear={false}
                                        value={form.data.queue_id}
                                        options={queues}
                                        current={ticket.routing.queue}
                                        empty="No queue selected"
                                        onChange={(value) =>
                                            form.setData('queue_id', value)
                                        }
                                    />
                                    <RoutingSelect
                                        label="Accountable owner"
                                        allowClear={false}
                                        value={form.data.owner_user_id}
                                        options={agents}
                                        current={ticket.routing.owner}
                                        empty="No individual owner selected"
                                        onChange={(value) =>
                                            form.setData('owner_user_id', value)
                                        }
                                    />
                                    <RoutingSelect
                                        label="Assigned technician"
                                        value={form.data.assigned_to_user_id}
                                        options={agents}
                                        current={ticket.assignee}
                                        empty="Unassigned for triage"
                                        onChange={(value) =>
                                            form.setData(
                                                'assigned_to_user_id',
                                                value,
                                            )
                                        }
                                    />
                                    {queues.length === 0 && (
                                        <p className="text-sm text-muted-foreground">
                                            No eligible active queue is
                                            configured for this ticket. IT setup
                                            needs an accountable team and cover.
                                        </p>
                                    )}
                                </>
                            ) : (
                                <p className="text-sm text-muted-foreground">
                                    The saved manual choices will be released
                                    and the current service, category and
                                    fallback rules will be applied. Any missing
                                    owner or cover remains visible on the
                                    ticket.
                                </p>
                            )}
                            <div className="space-y-2">
                                <Label htmlFor="routing-change-reason">
                                    Reason for routing change
                                </Label>
                                <Textarea
                                    id="routing-change-reason"
                                    required
                                    maxLength={1000}
                                    value={form.data.routing_reason}
                                    onChange={(event) =>
                                        form.setData(
                                            'routing_reason',
                                            event.target.value,
                                        )
                                    }
                                />
                            </div>
                        </fieldset>
                    )}
                    <DialogFooter>
                        <Button variant="ghost" onClick={close}>
                            Cancel
                        </Button>
                        {form.processing && (
                            <Button
                                variant="outline"
                                onClick={command.cancelWait}
                            >
                                Cancel wait
                            </Button>
                        )}
                        <Button
                            disabled={
                                !command.ready ||
                                !form.data.routing_reason.trim() ||
                                (data.mode === 'manual' &&
                                    (data.queue_id === NONE ||
                                        data.owner_user_id === NONE))
                            }
                            onClick={submit}
                        >
                            {form.processing ? 'Saving…' : 'Save routing'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
            {command.confirmation}
        </>
    );
}

function RoutingSelect({
    label,
    value,
    options,
    current,
    empty,
    onChange,
    allowClear = true,
}: {
    label: string;
    value: string;
    options: Option[];
    current: Option | null;
    empty: string;
    onChange: (value: string) => void;
    allowClear?: boolean;
}) {
    return (
        <div className="space-y-2">
            <Label>{label}</Label>
            <Select value={value} onValueChange={onChange}>
                <SelectTrigger aria-label={label}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE} disabled={!allowClear}>
                        {empty}
                    </SelectItem>
                    {current &&
                        !options.some((option) => option.id === current.id) && (
                            <SelectItem value={String(current.id)} disabled>
                                {current.name} — currently unavailable
                            </SelectItem>
                        )}
                    {options.map((option) => (
                        <SelectItem key={option.id} value={String(option.id)}>
                            {option.name}
                        </SelectItem>
                    ))}
                </SelectContent>
            </Select>
        </div>
    );
}
