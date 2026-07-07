import { PaneNav } from '@/components/control-room/alert-workspace-dialog';
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput, StepHead, TilePicker } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { useForm, usePage } from '@inertiajs/react';
import { Activity, AlertTriangle, CheckCircle2, RadioTower, ShieldAlert } from 'lucide-react';
import { useState, type ComponentType } from 'react';

/**
 * Operator quick-flag (Gap A): raises a Control Room alert and creates the
 * incident record together via POST /control-room/incidents/flag. Guided
 * steps — who & what → severity & note → review — then a success pane
 * linking both records.
 */
type ClientOpt = { id: number; name: string };

const TYPE_OPTIONS = [
    { value: 'injury', label: 'Injury' },
    { value: 'fall', label: 'Fall' },
    { value: 'behaviour', label: 'Behaviour' },
    { value: 'medication', label: 'Medication' },
    { value: 'safeguarding', label: 'Safeguarding' },
    { value: 'property_damage', label: 'Property damage' },
    { value: 'missing_person', label: 'Missing person' },
    { value: 'other', label: 'Other' },
];

const SEVERITY_TILES: Array<{ key: string; label: string; icon: ComponentType<{ className?: string }> }> = [
    { key: 'low', label: 'Low', icon: CheckCircle2 },
    { key: 'medium', label: 'Medium', icon: Activity },
    { key: 'high', label: 'High', icon: AlertTriangle },
    { key: 'critical', label: 'Critical', icon: ShieldAlert },
];

export function FlagIncidentDialog({
    open,
    onClose,
    clients,
    onFlagged,
}: {
    open: boolean;
    onClose: () => void;
    clients: ClientOpt[];
    onFlagged?: (incidentId: number, alertId: number) => void;
}) {
    const [step, setStep] = useState(0);
    const [done, setDone] = useState(false);
    const flash = (usePage().props as { flash?: { flagged_incident_id?: number; flagged_alert_id?: number; error?: string } }).flash;
    const form = useForm({ client_id: '', type: '', severity: 'high', note: '' });

    const close = () => {
        form.reset();
        form.clearErrors();
        setStep(0);
        setDone(false);
        onClose();
    };

    const submit = () => {
        form.transform((d) => ({ ...d, client_id: d.client_id ? Number(d.client_id) : null }));
        form.post('/control-room/incidents/flag', {
            preserveScroll: true,
            onSuccess: (pg) => {
                if (!(pg.props as { flash?: { error?: string } }).flash?.error) setDone(true);
            },
        });
    };

    const clientName = clients.find((c) => String(c.id) === form.data.client_id)?.name;
    const typeLabel = TYPE_OPTIONS.find((t) => t.value === form.data.type)?.label;
    const incidentId = flash?.flagged_incident_id;
    const alertId = flash?.flagged_alert_id;

    return (
        <Dialog open={open} onOpenChange={(o) => (!o ? close() : undefined)}>
            <DialogContent className="sm:max-w-lg">
                <DialogTitle className="sr-only">Flag an incident</DialogTitle>
                <DialogDescription className="sr-only">Raise a Control Room alert and create the incident record in one step.</DialogDescription>
                {done ? (
                    <div className="flex flex-col items-center gap-3 py-4 text-center">
                        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-status-success-bg">
                            <CheckCircle2 className="h-6 w-6 text-status-success" />
                        </span>
                        <p className="text-lg font-bold">Flagged and alert raised</p>
                        <p className="text-sm text-muted-foreground">The alert is on the operator desk and the incident is the system of record.</p>
                        <div className="flex flex-wrap justify-center gap-2 text-xs">
                            {alertId ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2.5 py-1 font-medium text-status-critical">
                                    <RadioTower className="h-3.5 w-3.5" /> CR-{alertId}
                                </span>
                            ) : null}
                            {incidentId ? (
                                <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 font-medium text-primary">
                                    <ShieldAlert className="h-3.5 w-3.5" /> INC-{incidentId}
                                </span>
                            ) : null}
                        </div>
                        <div className="mt-2 flex gap-2">
                            {incidentId && onFlagged && alertId ? (
                                <Button size="sm" onClick={() => { onFlagged(incidentId, alertId); close(); }}>
                                    Open incident
                                </Button>
                            ) : null}
                            <Button size="sm" variant="outline" onClick={close}>
                                Done
                            </Button>
                        </div>
                    </div>
                ) : (
                    <div className="flex flex-col gap-4">
                        <StepHead
                            icon={RadioTower}
                            title="Flag an incident"
                            blurb="Raises a Control Room alert for the operator desk and creates the incident record in one step."
                        />

                        {step === 0 ? (
                            <>
                                <Field label="Client" required error={form.errors.client_id}>
                                    <SelectInput
                                        value={form.data.client_id}
                                        onChange={(v) => form.setData('client_id', v)}
                                        placeholder="Select client"
                                        options={clients.map((c) => ({ value: String(c.id), label: c.name }))}
                                    />
                                </Field>
                                <Field label="Type" required error={form.errors.type}>
                                    <SelectInput value={form.data.type} onChange={(v) => form.setData('type', v)} placeholder="Select type" options={TYPE_OPTIONS} />
                                </Field>
                                <PaneNav onCancel={close} onNext={() => setStep(1)} nextDisabled={!form.data.client_id || !form.data.type} step={0} stepCount={3} />
                            </>
                        ) : step === 1 ? (
                            <>
                                <Field label="Severity" required error={form.errors.severity}>
                                    <TilePicker value={form.data.severity} onChange={(v) => form.setData('severity', v)} options={SEVERITY_TILES} cols={2} />
                                </Field>
                                <Field label="Note" hint="What's happening right now?">
                                    <Textarea rows={3} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="Brief description for the operator and the record…" />
                                </Field>
                                <PaneNav onCancel={close} onBack={() => setStep(0)} onNext={() => setStep(2)} step={1} stepCount={3} />
                            </>
                        ) : (
                            <>
                                <ReviewCard icon={RadioTower} title="Review & flag" span>
                                    <ReviewRow label="Client" value={clientName} />
                                    <ReviewRow label="Type" value={typeLabel} />
                                    <ReviewRow label="Severity" value={form.data.severity ? form.data.severity.charAt(0).toUpperCase() + form.data.severity.slice(1) : undefined} />
                                    <ReviewRow label="Note" value={form.data.note || undefined} />
                                </ReviewCard>
                                <p className="text-xs text-muted-foreground">
                                    Critical severity raises a <span className="font-medium">critical</span> alert; the incident records as high. Both records stay linked.
                                </p>
                                <PaneNav
                                    onCancel={close}
                                    onBack={() => setStep(1)}
                                    onSubmit={submit}
                                    submitLabel="Flag incident"
                                    processing={form.processing}
                                    step={2}
                                    stepCount={3}
                                />
                            </>
                        )}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}
