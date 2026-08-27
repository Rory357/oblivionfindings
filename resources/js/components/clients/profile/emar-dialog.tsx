/* eslint-disable no-restricted-syntax -- Outcome tiles are styled native
 * buttons per the redesign handoff; all colours are semantic tokens. */
/* eMAR sign-administration popup (design: emar.jsx). A focused sign-off for
 * the profile MAR tab — outcome tiles, structured not-given reasons, PRN
 * indication and controlled-drug witness — submitting to the client-scoped
 * administration endpoint. The full RecordAdministrationDialog (safety
 * checks, scanning, vitals) remains on the Medical/MAR pages. */
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    Check,
    Loader2,
    Lock,
    PauseCircle,
    PenLine,
    Pill,
    ShieldCheck,
    X,
    XCircle,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';

export type EmarMedication = {
    id: number;
    name: string;
    dosage?: string | null;
    route?: string | null;
    frequency?: string | null;
    is_prn?: boolean;
    controlled_drug?: boolean;
    witness_required?: boolean;
};

const OUTCOMES = [
    {
        value: 'given',
        label: 'Given',
        desc: 'Administered',
        icon: Check,
        tone: 'bg-status-success-bg text-status-success',
    },
    {
        value: 'refused',
        label: 'Refused',
        desc: 'Client declined',
        icon: X,
        tone: 'bg-status-critical-bg text-status-critical',
    },
    {
        value: 'withheld',
        label: 'Withheld',
        desc: 'Clinical hold',
        icon: PauseCircle,
        tone: 'bg-status-warning-bg text-status-warning',
    },
    {
        value: 'missed',
        label: 'Missed',
        desc: 'Not given',
        icon: XCircle,
        tone: 'bg-muted text-muted-foreground',
    },
] as const;

const NOT_GIVEN_REASONS = [
    { value: 'refused', label: 'Client declined' },
    { value: 'absent', label: 'Absent / on leave' },
    { value: 'vomit_or_nausea', label: 'Vomiting or nausea' },
    { value: 'fasting', label: 'Fasting / nil by mouth' },
    { value: 'doctors_instruction', label: "Doctor's instruction" },
    { value: 'medication_unavailable', label: 'Medication unavailable' },
    { value: 'hospitalised', label: 'Hospitalised' },
    { value: 'social_leave', label: 'Social leave' },
    { value: 'other', label: 'Other (explain in notes)' },
];

function nowLocal(): string {
    const d = new Date();
    d.setMinutes(d.getMinutes() - d.getTimezoneOffset());
    return d.toISOString().slice(0, 16);
}

export function EmarRecordDialog({
    open,
    onClose,
    clientId,
    clientLabel,
    medications,
    canRecord,
    canRecordControlled,
    staffOptions,
    initialMedicationId,
}: {
    open: boolean;
    onClose: () => void;
    clientId: number;
    clientLabel: string;
    medications: EmarMedication[];
    canRecord: boolean;
    canRecordControlled: boolean;
    staffOptions: { value: string; label: string }[];
    initialMedicationId?: number;
}) {
    const [medicationId, setMedicationId] = useState<string>(
        initialMedicationId ? String(initialMedicationId) : '',
    );
    const [outcome, setOutcome] = useState<string>('given');
    const [reasonCode, setReasonCode] = useState('');
    const [prnReason, setPrnReason] = useState('');
    const [administeredAt, setAdministeredAt] = useState(nowLocal());
    const [witnessedBy, setWitnessedBy] = useState('');
    const [witnessCredential, setWitnessCredential] = useState('');
    const [notes, setNotes] = useState('');
    const [busy, setBusy] = useState(false);

    useEffect(() => {
        if (open) {
            setMedicationId(
                initialMedicationId ? String(initialMedicationId) : '',
            );
            setOutcome('given');
            setReasonCode('');
            setPrnReason('');
            setAdministeredAt(nowLocal());
            setWitnessedBy('');
            setWitnessCredential('');
            setNotes('');
            setBusy(false);
        }
    }, [open, initialMedicationId]);

    const recordableMedications = useMemo(
        () =>
            medications.filter(
                (candidate) =>
                    !candidate.controlled_drug || canRecordControlled,
            ),
        [canRecordControlled, medications],
    );
    const medication = useMemo(
        () =>
            recordableMedications.find(
                (candidate) => String(candidate.id) === medicationId,
            ) ?? null,
        [medicationId, recordableMedications],
    );

    const needsReason = outcome !== 'given';
    const needsWitness = Boolean(
        outcome === 'given' &&
        medication &&
        (medication.controlled_drug || medication.witness_required),
    );
    const isPrn = Boolean(medication?.is_prn);

    useEffect(() => {
        if (!needsWitness) {
            setWitnessedBy('');
            setWitnessCredential('');
        }
    }, [needsWitness]);

    const submit = () => {
        if (!canRecord) {
            toast.error('You are not authorised to record medication.');
            return;
        }
        if (!medication) {
            toast.error('Select a medication first.');
            return;
        }
        if (medication.controlled_drug && !canRecordControlled) {
            toast.error(
                'You are not authorised to record controlled medication.',
            );
            return;
        }
        if (needsReason && !reasonCode) {
            toast.error('Choose why the medication was not given.');
            return;
        }
        if (isPrn && outcome === 'given' && !prnReason.trim()) {
            toast.error('Provide the PRN indication (reason).');
            return;
        }
        if (needsWitness && !witnessedBy) {
            toast.error('Select the authorised witness.');
            return;
        }
        if (needsWitness && !witnessCredential.trim()) {
            toast.error('Enter the witness password or PIN.');
            return;
        }
        setBusy(true);
        router.post(
            `/operations/clients/${clientId}/medical/medications/${medication.id}/administrations`,
            {
                status: outcome,
                reason_code: needsReason ? reasonCode : undefined,
                reason:
                    isPrn && outcome === 'given' ? prnReason.trim() : undefined,
                administered_at: administeredAt
                    ? new Date(administeredAt).toISOString()
                    : undefined,
                witnessed_by: needsWitness
                    ? parseInt(witnessedBy, 10)
                    : undefined,
                witness_credential: needsWitness
                    ? witnessCredential
                    : undefined,
                notes: notes.trim() || undefined,
            },
            {
                preserveScroll: true,
                onSuccess: (page) => {
                    const flash = (page.props as { flash?: { error?: string } })
                        .flash;
                    if (flash?.error) {
                        toast.error(flash.error);
                        setBusy(false);
                        return;
                    }
                    toast.success(
                        `${medication.name} · ${OUTCOMES.find((o) => o.value === outcome)?.label ?? 'Recorded'} — signed`,
                    );
                    onClose();
                },
                onError: (errors) => {
                    setBusy(false);
                    const first = Object.values(errors ?? {})[0];
                    toast.error(
                        first
                            ? String(first)
                            : 'Could not record the administration.',
                    );
                },
            },
        );
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && !busy && onClose()}>
            <DialogContent className="max-h-[88vh] overflow-y-auto sm:max-w-[640px]">
                <DialogHeader>
                    <DialogTitle className="flex items-center gap-2">
                        <span className="flex h-9 w-9 items-center justify-center rounded-xl bg-accent text-primary">
                            <Pill className="h-[18px] w-[18px]" />
                        </span>
                        Record administration
                    </DialogTitle>
                    <DialogDescription>
                        eMAR sign-off · {clientLabel}
                    </DialogDescription>
                </DialogHeader>

                <div className="space-y-4">
                    {!initialMedicationId ? (
                        <div>
                            <Label className="mb-1.5 block">
                                Medication{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Select
                                value={medicationId}
                                onValueChange={setMedicationId}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select medication…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {recordableMedications.map((m) => (
                                        <SelectItem
                                            key={m.id}
                                            value={String(m.id)}
                                        >
                                            {m.name}
                                            {m.dosage ? ` ${m.dosage}` : ''}
                                            {m.is_prn ? ' · PRN' : ''}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    ) : null}

                    {medication ? (
                        <div className="flex items-center gap-3 rounded-xl border border-primary/30 bg-accent px-3.5 py-3">
                            <span className="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-card text-primary">
                                <Pill className="h-5 w-5" />
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="flex flex-wrap items-center gap-2">
                                    <span className="text-[15px] font-semibold">
                                        {medication.name}
                                        {medication.dosage
                                            ? ` ${medication.dosage}`
                                            : ''}
                                    </span>
                                    {medication.controlled_drug ? (
                                        <span className="inline-flex items-center gap-1 rounded-full bg-status-critical-bg px-2 py-0.5 text-xs font-semibold text-status-critical">
                                            <Lock className="h-3 w-3" />{' '}
                                            Controlled
                                        </span>
                                    ) : null}
                                    <span className="inline-flex items-center rounded-full bg-muted px-2 py-0.5 text-xs font-semibold text-muted-foreground">
                                        {isPrn ? 'PRN' : 'Regular'}
                                    </span>
                                </div>
                                <div className="mt-0.5 text-xs text-muted-foreground">
                                    {[medication.route, medication.frequency]
                                        .filter(Boolean)
                                        .join(' · ') || 'Oral'}
                                </div>
                            </div>
                        </div>
                    ) : null}

                    <div>
                        <Label className="mb-1.5 block">Outcome</Label>
                        <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            {OUTCOMES.map((o) => {
                                const Icon = o.icon;
                                const active = outcome === o.value;
                                return (
                                    <button
                                        key={o.value}
                                        type="button"
                                        aria-pressed={active}
                                        onClick={() => setOutcome(o.value)}
                                        className={cn(
                                            'flex flex-col items-center gap-1.5 rounded-xl border px-2 py-3 transition-all',
                                            active
                                                ? 'border-primary bg-accent ring-1 ring-primary/40'
                                                : 'border-border bg-card hover:border-primary/40',
                                        )}
                                    >
                                        <span
                                            className={cn(
                                                'flex h-9 w-9 items-center justify-center rounded-full',
                                                o.tone,
                                            )}
                                        >
                                            <Icon className="h-[18px] w-[18px]" />
                                        </span>
                                        <span className="text-[13px] leading-none font-semibold">
                                            {o.label}
                                        </span>
                                        <span className="text-[10px] leading-none text-muted-foreground">
                                            {o.desc}
                                        </span>
                                    </button>
                                );
                            })}
                        </div>
                    </div>

                    {needsReason ? (
                        <div>
                            <Label className="mb-1.5 block">
                                Reason{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Select
                                value={reasonCode}
                                onValueChange={setReasonCode}
                            >
                                <SelectTrigger className="w-full">
                                    <SelectValue placeholder="Select reason…" />
                                </SelectTrigger>
                                <SelectContent>
                                    {NOT_GIVEN_REASONS.map((r) => (
                                        <SelectItem
                                            key={r.value}
                                            value={r.value}
                                        >
                                            {r.label}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    ) : null}

                    {isPrn && outcome === 'given' ? (
                        <div>
                            <Label className="mb-1.5 block">
                                PRN reason{' '}
                                <span className="text-status-critical">*</span>
                            </Label>
                            <Input
                                value={prnReason}
                                onChange={(e) => setPrnReason(e.target.value)}
                                placeholder="e.g. headache, rated 6/10"
                            />
                        </div>
                    ) : null}

                    <div className="grid gap-3 sm:grid-cols-2">
                        <div>
                            <Label className="mb-1.5 block">
                                Administered at
                            </Label>
                            <Input
                                type="datetime-local"
                                value={administeredAt}
                                onChange={(e) =>
                                    setAdministeredAt(e.target.value)
                                }
                            />
                        </div>
                        {needsWitness ? (
                            <div className="space-y-3">
                                <div>
                                    <Label className="mb-1.5 block">
                                        Witnessed by{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Select
                                        value={witnessedBy}
                                        onValueChange={setWitnessedBy}
                                    >
                                        <SelectTrigger className="w-full">
                                            <SelectValue placeholder="Second signature…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            {staffOptions.map((s) => (
                                                <SelectItem
                                                    key={s.value}
                                                    value={s.value}
                                                >
                                                    {s.label}
                                                </SelectItem>
                                            ))}
                                        </SelectContent>
                                    </Select>
                                </div>
                                <div>
                                    <Label className="mb-1.5 block">
                                        Witness password / PIN{' '}
                                        <span className="text-status-critical">
                                            *
                                        </span>
                                    </Label>
                                    <Input
                                        type="password"
                                        autoComplete="off"
                                        value={witnessCredential}
                                        onChange={(event) =>
                                            setWitnessCredential(
                                                event.target.value,
                                            )
                                        }
                                        placeholder="Witness confirms identity"
                                    />
                                </div>
                            </div>
                        ) : null}
                    </div>

                    <div>
                        <Label className="mb-1.5 block">
                            Note{' '}
                            <span className="font-normal text-muted-foreground">
                                (optional)
                            </span>
                        </Label>
                        <Textarea
                            value={notes}
                            onChange={(e) => setNotes(e.target.value)}
                            rows={2}
                            placeholder="Any observations, side effects, or follow-up…"
                        />
                    </div>

                    <div className="flex items-center gap-2 rounded-lg bg-muted/60 px-3 py-2 text-[11px] text-muted-foreground">
                        <ShieldCheck className="h-3.5 w-3.5 shrink-0" />
                        Six rights checked: right person, drug, dose, route,
                        time, documentation.
                    </div>
                </div>

                <DialogFooter>
                    <Button variant="outline" onClick={onClose} disabled={busy}>
                        Cancel
                    </Button>
                    <Button onClick={submit} disabled={busy}>
                        {busy ? (
                            <Loader2 className="mr-1.5 h-4 w-4 animate-spin" />
                        ) : (
                            <PenLine className="mr-1.5 h-4 w-4" />
                        )}
                        Sign &amp; record
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}
