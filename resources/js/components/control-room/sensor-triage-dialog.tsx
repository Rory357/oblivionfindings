import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { formatDateTime } from '@/lib/datetime';
import { router } from '@inertiajs/react';
import { Cpu, MapPin, Radar, ShieldAlert, ShieldQuestion, X } from 'lucide-react';
import { useState } from 'react';

/**
 * Sensor confirm/dismiss triage (Gap B). Shows the detection evidence and lets an
 * operator confirm it into an incident (POST .../confirm) or dismiss it as a false
 * positive with a reason (POST .../dismiss). Ready to mount into the (separately
 * redesigned) Control Room desk.
 */
export type SensorAlert = {
    id: number;
    severity: string;
    alert_type: string;
    triggered_at: string | null;
    client_name?: string | null;
    signal?: {
        signal_type_code?: string | null;
        device?: string | null;
        occurred_at?: string | null;
        payload?: Record<string, unknown> | null;
    } | null;
};

const DISMISS_REASONS = ['Resident sat down', 'Pet or animal', 'Object dropped', 'Staff present', 'Other'];

export function SensorTriageDialog({
    open,
    onClose,
    alert,
    onConfirmed,
}: {
    open: boolean;
    onClose: () => void;
    alert: SensorAlert;
    onConfirmed?: (incidentId?: number) => void;
}) {
    const [mode, setMode] = useState<'triage' | 'dismiss'>('triage');
    const [reason, setReason] = useState('');
    const [otherReason, setOtherReason] = useState('');
    const [busy, setBusy] = useState(false);

    const close = () => {
        setMode('triage');
        setReason('');
        setOtherReason('');
        setBusy(false);
        onClose();
    };

    const confirm = () => {
        setBusy(true);
        router.post(`/control-room/alerts/${alert.id}/confirm`, {}, {
            preserveScroll: true,
            onSuccess: (pg) => {
                const id = (pg.props as { flash?: { confirmed_incident_id?: number } }).flash?.confirmed_incident_id;
                onConfirmed?.(id);
                close();
            },
            onFinish: () => setBusy(false),
        });
    };

    const dismiss = () => {
        const finalReason = reason === 'Other' ? otherReason.trim() : reason;
        if (!finalReason) return;
        setBusy(true);
        router.post(`/control-room/alerts/${alert.id}/dismiss`, { reason: finalReason }, {
            preserveScroll: true,
            onSuccess: close,
            onFinish: () => setBusy(false),
        });
    };

    const payload = alert.signal?.payload ?? {};
    const confidence = payload.confidence;
    const location = payload.location;
    const signalLabel = (alert.signal?.signal_type_code ?? alert.alert_type ?? 'sensor signal').replace(/[._]/g, ' ');

    return (
        <Dialog open={open} onOpenChange={(o) => (!o ? close() : undefined)}>
            <DialogContent className="sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <Radar className="h-5 w-5 text-status-critical" /> Sensor detection
                    </DialogTitle>
                    <DialogDescription>
                        {alert.client_name ? `${alert.client_name} · ` : ''}
                        Confirm this into an incident, or dismiss it as a false positive.
                    </DialogDescription>
                </DialogHeader>

                {/* Evidence panel */}
                <div className="rounded-xl border border-border bg-muted/30 p-3">
                    <p className="mb-2 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Signal evidence</p>
                    <dl className="grid grid-cols-2 gap-x-4 gap-y-1.5 text-sm">
                        <Evidence icon={Radar} label="Signal" value={signalLabel} />
                        <Evidence icon={Cpu} label="Device" value={alert.signal?.device ?? '—'} />
                        {confidence !== undefined && confidence !== null ? <Evidence icon={ShieldAlert} label="Confidence" value={String(confidence)} /> : null}
                        {location ? <Evidence icon={MapPin} label="Location" value={String(location)} /> : null}
                        <Evidence icon={Radar} label="Detected" value={alert.signal?.occurred_at ? formatDateTime(alert.signal.occurred_at) : alert.triggered_at ? formatDateTime(alert.triggered_at) : '—'} />
                    </dl>
                </div>

                {mode === 'triage' ? (
                    <div className="flex justify-end gap-2">
                        <Button variant="outline" onClick={() => setMode('dismiss')}>
                            <X className="mr-1.5 h-4 w-4" /> Dismiss
                        </Button>
                        <Button onClick={confirm} disabled={busy}>
                            <ShieldAlert className="mr-1.5 h-4 w-4" /> Confirm — create incident
                        </Button>
                    </div>
                ) : (
                    <div className="flex flex-col gap-3">
                        <p className="flex items-center gap-1.5 text-sm font-medium text-foreground">
                            <ShieldQuestion className="h-4 w-4 text-muted-foreground" /> Why is this a false positive?
                        </p>
                        <div className="flex flex-wrap gap-2">
                            {DISMISS_REASONS.map((r) => (
                                <button
                                    key={r}
                                    type="button"
                                    onClick={() => setReason(r)}
                                    className={`rounded-full border px-3 py-1.5 text-xs font-medium transition-colors ${reason === r ? 'border-primary bg-primary/10 text-primary' : 'border-border text-muted-foreground hover:bg-muted'}`}
                                >
                                    {r}
                                </button>
                            ))}
                        </div>
                        {reason === 'Other' ? (
                            <Input value={otherReason} onChange={(e) => setOtherReason(e.target.value)} placeholder="Describe the false positive" />
                        ) : null}
                        <p className="text-xs text-muted-foreground">Logged for sensor tuning — no incident is created.</p>
                        <div className="flex justify-end gap-2">
                            <Button variant="outline" onClick={() => setMode('triage')}>Back</Button>
                            <Button variant="destructive" onClick={dismiss} disabled={busy || !reason || (reason === 'Other' && !otherReason.trim())}>
                                Dismiss as false positive
                            </Button>
                        </div>
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

function Evidence({ icon: Icon, label, value }: { icon: typeof Radar; label: string; value: string }) {
    return (
        <div className="flex items-start gap-1.5">
            <Icon className="mt-0.5 h-3.5 w-3.5 shrink-0 text-muted-foreground" />
            <span className="min-w-0">
                <span className="block text-[10.5px] tracking-wide text-muted-foreground uppercase">{label}</span>
                <span className="block truncate font-medium text-foreground">{value}</span>
            </span>
        </div>
    );
}
