import { PaneNav } from '@/components/control-room/alert-workspace-dialog';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import {
    Field,
    InfoCard,
    SelectInput,
    StepHead,
} from '@/components/wizard/primitives';
import { useForm } from '@inertiajs/react';
import {
    ArrowUpCircle,
    CheckCircle2,
    ShieldAlert,
    UserPlus,
} from 'lucide-react';
import { useState } from 'react';

/**
 * Stepped bulk actions over selected alerts — review the selection first, then
 * the action details, then submit. Shared by the All Alerts list (acknowledge,
 * assign) and the Escalation Queue board (escalate with a required reason).
 */
export type BulkAlertSummary = {
    id: number;
    alert_type: string;
    severity: string;
    status?: string;
    client_name?: string | null;
};

export type BulkAlertMode = 'acknowledge' | 'assign' | 'escalate';

const MODE_META: Record<
    BulkAlertMode,
    {
        title: string;
        blurb: string;
        icon: typeof CheckCircle2;
        cta: (n: number) => string;
    }
> = {
    acknowledge: {
        title: 'Acknowledge selected alerts',
        blurb: 'Marks every selected open alert as seen and stops its acknowledge SLA clock. Alerts that are already past Open are skipped.',
        icon: CheckCircle2,
        cta: (n) => `Acknowledge ${n} alert${n === 1 ? '' : 's'}`,
    },
    assign: {
        title: 'Assign selected alerts',
        blurb: 'Gives every selected alert the same owner. Resolved or closed alerts are skipped.',
        icon: UserPlus,
        cta: (n) => `Assign ${n} alert${n === 1 ? '' : 's'}`,
    },
    escalate: {
        title: 'Escalate selected alerts',
        blurb: 'Moves every selected alert to the next escalation queue and raises its level. The reason is recorded on each alert for the audit trail.',
        icon: ArrowUpCircle,
        cta: (n) => `Escalate ${n} alert${n === 1 ? '' : 's'}`,
    },
};

const SEV_DOT: Record<string, string> = {
    critical: 'bg-status-critical',
    high: 'bg-status-warning',
    medium: 'bg-status-warning',
    low: 'bg-status-info',
};

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
    const [step, setStep] = useState(0);
    const meta = MODE_META[mode];
    const form = useForm<{ assigned_to_user_id: string; reason: string }>({
        assigned_to_user_id: '',
        reason: '',
    });

    const close = () => {
        setStep(0);
        form.reset();
        form.clearErrors();
        onClose();
    };

    const submit = () => {
        const ids = alerts.map((a) => a.id);
        if (mode === 'acknowledge') {
            form.transform(() => ({ alert_ids: ids }));
            form.post('/control-room/alerts/bulk-acknowledge', {
                preserveScroll: true,
                onSuccess: () => {
                    close();
                    onDone();
                },
            });
        } else if (mode === 'assign') {
            form.transform((data) => ({
                alert_ids: ids,
                assigned_to_user_id: Number(data.assigned_to_user_id),
            }));
            form.post('/control-room/alerts/bulk-assign', {
                preserveScroll: true,
                onSuccess: () => {
                    close();
                    onDone();
                },
            });
        } else {
            form.transform((data) => ({ alert_ids: ids, reason: data.reason }));
            form.post('/control-room/escalations/bulk-escalate', {
                preserveScroll: true,
                onSuccess: () => {
                    close();
                    onDone();
                },
            });
        }
    };

    const detailsValid =
        mode === 'acknowledge'
            ? true
            : mode === 'assign'
              ? Boolean(form.data.assigned_to_user_id)
              : Boolean(form.data.reason.trim());

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle className="sr-only">{meta.title}</DialogTitle>
                <DialogDescription className="sr-only">
                    {meta.blurb}
                </DialogDescription>
                <div className="flex flex-col gap-4">
                    <StepHead
                        icon={meta.icon}
                        title={meta.title}
                        blurb={meta.blurb}
                    />

                    {step === 0 ? (
                        <>
                            <div className="max-h-56 overflow-y-auto rounded-xl border border-border">
                                {alerts.map((a) => (
                                    <div
                                        key={a.id}
                                        className="flex items-center gap-2.5 border-b border-border/60 px-3 py-2 text-sm last:border-0"
                                    >
                                        <span
                                            className={`h-2 w-2 shrink-0 rounded-full ${SEV_DOT[a.severity] ?? 'bg-muted-foreground'}`}
                                        />
                                        <span className="font-medium text-foreground">
                                            CR-{a.id}
                                        </span>
                                        <span className="min-w-0 flex-1 truncate text-muted-foreground">
                                            {a.alert_type}
                                            {a.client_name
                                                ? ` · ${a.client_name}`
                                                : ''}
                                        </span>
                                        {a.status ? (
                                            <span className="shrink-0 text-xs text-muted-foreground capitalize">
                                                {a.status}
                                            </span>
                                        ) : null}
                                    </div>
                                ))}
                            </div>
                            <PaneNav
                                onCancel={close}
                                onNext={() => setStep(1)}
                                nextDisabled={alerts.length === 0}
                                step={0}
                                stepCount={2}
                            />
                        </>
                    ) : (
                        <>
                            {mode === 'acknowledge' ? (
                                <InfoCard icon={ShieldAlert} tone="info">
                                    {alerts.length} alert
                                    {alerts.length === 1 ? '' : 's'} will move
                                    to{' '}
                                    <span className="font-semibold">
                                        Acknowledged
                                    </span>
                                    . Anything already acknowledged, resolved or
                                    closed is skipped and reported back.
                                </InfoCard>
                            ) : null}
                            {mode === 'assign' ? (
                                <Field
                                    label="Assign to"
                                    required
                                    error={
                                        (
                                            form.errors as Record<
                                                string,
                                                string | undefined
                                            >
                                        ).assigned_to_user_id
                                    }
                                >
                                    <SelectInput
                                        value={form.data.assigned_to_user_id}
                                        onChange={(v) =>
                                            form.setData(
                                                'assigned_to_user_id',
                                                v,
                                            )
                                        }
                                        placeholder="Select a staff member"
                                        options={(staff ?? []).map((s) => ({
                                            value: String(s.id),
                                            label: s.name,
                                        }))}
                                    />
                                </Field>
                            ) : null}
                            {mode === 'escalate' ? (
                                <Field
                                    label="Reason for escalating"
                                    required
                                    error={
                                        (
                                            form.errors as Record<
                                                string,
                                                string | undefined
                                            >
                                        ).reason
                                    }
                                >
                                    <Textarea
                                        rows={3}
                                        value={form.data.reason}
                                        onChange={(e) =>
                                            form.setData(
                                                'reason',
                                                e.target.value,
                                            )
                                        }
                                        placeholder="Why do these need to move up a queue?"
                                    />
                                </Field>
                            ) : null}
                            <PaneNav
                                onCancel={close}
                                onBack={() => setStep(0)}
                                onSubmit={submit}
                                submitLabel={meta.cta(alerts.length)}
                                submitDisabled={!detailsValid}
                                processing={form.processing}
                                step={1}
                                stepCount={2}
                            />
                        </>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}
