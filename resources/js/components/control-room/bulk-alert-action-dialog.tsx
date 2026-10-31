/**
 * Bulk actions over ticked alerts, on the pop-up kit: one pop-up per action
 * that shows what is selected, asks only what the action needs, and names
 * the number in its main button. The server rechecks every alert (Site,
 * state, permission) and skips any it can't act on.
 */
import { PopupConfirm, PopupField, PopupForm } from '@/components/popup';
import { Textarea } from '@/components/ui/textarea';
import { SelectInput } from '@/components/wizard/primitives';
import { useForm } from '@inertiajs/react';
import { ArrowUpCircle, UserCheck, UserPlus } from 'lucide-react';

export type BulkAlertSummary = {
    id: number;
    reference_number?: string | null;
    summary?: string | null;
    alert_type: string;
    severity: string;
    status?: string;
    client_name?: string | null;
};

export type BulkAlertMode = 'acknowledge' | 'assign' | 'escalate';

const plural = (n: number) => `${n} alert${n === 1 ? '' : 's'}`;

function SelectedList({ alerts }: { alerts: BulkAlertSummary[] }) {
    return (
        <ul
            aria-label="Selected alerts"
            className="max-h-48 divide-y divide-border overflow-y-auto rounded-[12px] border border-border text-sm scrollbar-pretty"
        >
            {alerts.map((a) => (
                <li key={a.id} className="flex items-baseline gap-2 px-3 py-2">
                    <span className="shrink-0 font-medium tabular-nums">
                        {a.reference_number ?? `Alert ${a.id}`}
                    </span>
                    <span className="min-w-0 flex-1 truncate text-muted-foreground">
                        {a.summary ?? a.alert_type}
                        {a.client_name ? ` · ${a.client_name}` : ''}
                    </span>
                </li>
            ))}
        </ul>
    );
}

export function BulkAlertActionDialog({
    mode,
    open,
    onClose,
    alerts,
    staff,
    onDone,
}: {
    mode: BulkAlertMode;
    open: boolean;
    onClose: () => void;
    alerts: BulkAlertSummary[];
    staff?: Array<{ id: number; name: string }>;
    onDone: () => void;
}) {
    const form = useForm<{ assigned_to_user_id: string; reason: string }>({
        assigned_to_user_id: '',
        reason: '',
    });
    const ids = alerts.map((a) => a.id);
    const errors = form.errors as Record<string, string | undefined>;
    const serverError = errors.alert_ids ?? errors.alert;
    const finish = {
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            onDone();
            onClose();
        },
    };

    if (mode === 'acknowledge') {
        return (
            <PopupConfirm
                open={open}
                onClose={onClose}
                onConfirm={() => {
                    form.transform(() => ({ alert_ids: ids }));
                    form.post('/control-room/alerts/bulk-acknowledge', finish);
                }}
                icon={UserCheck}
                title={`Acknowledge ${plural(alerts.length)}?`}
                description="Each open alert is marked as seen and its acknowledgement target stops. Anything already acknowledged, resolved or closed is skipped."
                confirmLabel={`Acknowledge ${plural(alerts.length)}`}
                processing={form.processing}
                error={serverError}
            >
                <SelectedList alerts={alerts} />
            </PopupConfirm>
        );
    }

    if (mode === 'assign') {
        const person = staff?.find(
            (s) => String(s.id) === form.data.assigned_to_user_id,
        );
        return (
            <PopupForm
                open={open}
                onClose={onClose}
                icon={UserPlus}
                title="Assign selected alerts"
                description="Gives every selected alert the same owner. Resolved or closed alerts are skipped."
                error={serverError}
                dirty={form.isDirty}
                draftName={`Assign ${plural(alerts.length)}`}
                processing={form.processing}
                submitDisabled={!person}
                submitLabel={
                    person
                        ? `Assign ${plural(alerts.length)} to ${person.name}`
                        : `Assign ${plural(alerts.length)}`
                }
                onSubmit={() => {
                    form.transform((data) => ({
                        alert_ids: ids,
                        assigned_to_user_id: Number(data.assigned_to_user_id),
                    }));
                    form.post('/control-room/alerts/bulk-assign', finish);
                }}
            >
                <SelectedList alerts={alerts} />
                <PopupField
                    label="Assign to"
                    required
                    error={errors.assigned_to_user_id}
                >
                    <SelectInput
                        value={form.data.assigned_to_user_id}
                        onChange={(v) => form.setData('assigned_to_user_id', v)}
                        placeholder="Choose a person"
                        ariaLabel="Assign to"
                        options={(staff ?? []).map((s) => ({
                            value: String(s.id),
                            label: s.name,
                        }))}
                    />
                </PopupField>
            </PopupForm>
        );
    }

    return (
        <PopupConfirm
            open={open}
            onClose={onClose}
            onConfirm={() => {
                form.transform((data) => ({
                    alert_ids: ids,
                    reason: data.reason.trim(),
                }));
                form.post('/control-room/escalations/bulk-escalate', finish);
            }}
            variant="warning"
            icon={ArrowUpCircle}
            title={`Escalate ${plural(alerts.length)}?`}
            description="Each alert moves to its next escalation queue and level. The reason is kept in every alert's history."
            confirmLabel={`Escalate ${plural(alerts.length)}`}
            confirmDisabled={!form.data.reason.trim()}
            processing={form.processing}
            error={serverError ?? errors.reason}
        >
            <SelectedList alerts={alerts} />
            <PopupField
                label="Why do they need senior attention?"
                htmlFor="bulk-escalate-reason"
                required
            >
                <Textarea
                    id="bulk-escalate-reason"
                    rows={3}
                    value={form.data.reason}
                    onChange={(e) => form.setData('reason', e.target.value)}
                />
            </PopupField>
        </PopupConfirm>
    );
}
