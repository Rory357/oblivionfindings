import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput, TilePicker } from '@/components/wizard/primitives';
import { useForm, usePage } from '@inertiajs/react';
import { Activity, AlertTriangle, CheckCircle2, RadioTower, ShieldAlert } from 'lucide-react';
import { useState, type FormEvent } from 'react';

/**
 * Operator quick-flag (Gap A). Raises a Control Room alert and creates the incident
 * record together via POST /control-room/incidents/flag. Ready to mount into the
 * (separately redesigned) Control Room desk — pass the client list + an onFlagged
 * callback to jump to either record.
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

const SEVERITY_TILES = [
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
    const [done, setDone] = useState(false);
    const flash = (usePage().props as { flash?: { flagged_incident_id?: number; flagged_alert_id?: number; error?: string } }).flash;
    const form = useForm({ client_id: '', type: '', severity: 'high', note: '' });

    const close = () => {
        form.reset();
        form.clearErrors();
        setDone(false);
        onClose();
    };

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.transform((d) => ({ ...d, client_id: d.client_id ? Number(d.client_id) : null }));
        form.post('/control-room/incidents/flag', {
            preserveScroll: true,
            onSuccess: (pg) => {
                if (!(pg.props as { flash?: { error?: string } }).flash?.error) setDone(true);
            },
        });
    };

    const incidentId = flash?.flagged_incident_id;
    const alertId = flash?.flagged_alert_id;

    return (
        <Dialog open={open} onOpenChange={(o) => (!o ? close() : undefined)}>
            <DialogContent className="sm:max-w-md">
                {done ? (
                    <div className="flex flex-col items-center gap-3 py-4 text-center">
                        <span className="flex h-12 w-12 items-center justify-center rounded-full bg-status-success-bg">
                            <CheckCircle2 className="h-6 w-6 text-status-success" />
                        </span>
                        <DialogTitle>Flagged and alert raised</DialogTitle>
                        <DialogDescription>
                            The alert is on the operator desk and the incident is the system of record.
                        </DialogDescription>
                        <div className="flex flex-wrap justify-center gap-2 text-xs">
                            {alertId ? <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2.5 py-1 font-medium text-status-critical"><RadioTower className="h-3.5 w-3.5" /> CR-{alertId}</span> : null}
                            {incidentId ? <span className="inline-flex items-center gap-1 rounded-full bg-primary/10 px-2.5 py-1 font-medium text-primary"><ShieldAlert className="h-3.5 w-3.5" /> INC-{incidentId}</span> : null}
                        </div>
                        <div className="mt-2 flex gap-2">
                            {incidentId && onFlagged && alertId ? (
                                <Button size="sm" onClick={() => { onFlagged(incidentId, alertId); close(); }}>Open incident</Button>
                            ) : null}
                            <Button size="sm" variant="outline" onClick={close}>Done</Button>
                        </div>
                    </div>
                ) : (
                    <form onSubmit={submit} className="flex flex-col gap-4">
                        <DialogHeader>
                            <DialogTitle>Flag an incident</DialogTitle>
                            <DialogDescription>Raise a Control Room alert and create the incident record in one step.</DialogDescription>
                        </DialogHeader>

                        <Field label="Client" required error={form.errors.client_id}>
                            <SelectInput value={form.data.client_id} onChange={(v) => form.setData('client_id', v)} placeholder="Select client" options={clients.map((c) => ({ value: String(c.id), label: c.name }))} />
                        </Field>
                        <Field label="Type" required error={form.errors.type}>
                            <SelectInput value={form.data.type} onChange={(v) => form.setData('type', v)} placeholder="Select type" options={TYPE_OPTIONS} />
                        </Field>
                        <Field label="Severity" required error={form.errors.severity}>
                            <TilePicker value={form.data.severity} onChange={(v) => form.setData('severity', v)} options={SEVERITY_TILES} cols={2} />
                        </Field>
                        <Field label="Note" hint="What's happening right now?">
                            <Textarea rows={3} value={form.data.note} onChange={(e) => form.setData('note', e.target.value)} placeholder="Brief description for the operator and the record…" />
                        </Field>

                        <div className="flex justify-end gap-2">
                            <Button type="button" variant="outline" onClick={close}>Cancel</Button>
                            <Button type="submit" disabled={form.processing || !form.data.client_id || !form.data.type}>
                                <RadioTower className="mr-1.5 h-4 w-4" /> Flag incident
                            </Button>
                        </div>
                    </form>
                )}
            </DialogContent>
        </Dialog>
    );
}
