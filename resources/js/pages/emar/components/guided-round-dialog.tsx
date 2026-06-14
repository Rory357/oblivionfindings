/* eslint-disable no-restricted-syntax -- dose/resident/summary panes are
   custom-layout bordered surfaces inside the wizard, not Card components; all
   colours are semantic tokens. */
import { MedsWizardDialog } from '@/components/meds/wizard-shell';
import { Field, InfoCard, SelectInput, StepHead } from '@/components/wizard/primitives';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { cn } from '@/lib/utils';
import type { GuidedRound, RoundItem, StaffOption } from '@/components/emar/rounds/types';
import { router } from '@inertiajs/react';
import { AlertTriangle, Ban, Check, CheckCircle2, ClipboardCheck, Hand, Pill } from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

type NotGivenReason = { value: string; label: string; requires_detail: boolean };

type Props = {
    guided: GuidedRound;
    witnesses: StaffOption[];
    notGivenReasons: NotGivenReason[];
    signer: { med_competent: boolean; cd_witness: boolean };
    onClose: () => void;
};

type Pending = 'given' | 'refused' | 'held' | null;

function initials(name: string): string {
    return name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('');
}
function firstName(name: string): string {
    return name.split(/\s+/)[0] ?? name;
}
function shortMed(name: string): string {
    return name.length > 16 ? `${name.slice(0, 15)}…` : name;
}
function needsBloodGlucose(med: string): boolean {
    return /insulin|novorapid|lantus|humalog/i.test(med);
}
function needsPulse(med: string): boolean {
    return /digoxin/i.test(med);
}

export default function GuidedRoundDialog({ guided, witnesses, notGivenReasons, signer, onClose }: Props) {
    const { round, items, progress } = guided;
    const [stepIndex, setStepIndex] = useState(progress.next_index ?? 0);
    const [identity, setIdentity] = useState(false);
    const [pending, setPending] = useState<Pending>(null);
    const [reasonCode, setReasonCode] = useState('');
    const [reason, setReason] = useState('');
    const [witnessedBy, setWitnessedBy] = useState('');
    const [witnessCredential, setWitnessCredential] = useState('');
    const [bloodGlucose, setBloodGlucose] = useState('');
    const [pulse, setPulse] = useState('');
    const [saving, setSaving] = useState(false);

    const isSummary = stepIndex >= items.length;
    const item: RoundItem | undefined = items[stepIndex];

    const resetPanel = () => {
        setPending(null);
        setReasonCode('');
        setReason('');
        setWitnessedBy('');
        setWitnessCredential('');
        setBloodGlucose('');
        setPulse('');
    };

    const goTo = (index: number) => {
        resetPanel();
        setIdentity(false);
        setStepIndex(index);
    };

    const steps = useMemo(
        () => [
            ...items.map((it) => {
                const status = it.administration?.status ?? null;
                const icon = status === 'given' ? CheckCircle2 : status === 'refused' || status === 'withheld' ? Ban : Pill;
                return { key: `${it.medication_id}-${it.scheduled_for}`, label: `${firstName(it.client_name)} · ${shortMed(it.medication_name)}`, blurb: `${it.dose ?? ''}`, icon };
            }),
            { key: 'summary', label: 'Round summary', blurb: `${progress.percent}% complete`, icon: ClipboardCheck },
        ],
        [items, progress.percent],
    );

    const reasonObj = notGivenReasons.find((r) => r.value === reasonCode);
    const confirmValid = (() => {
        if (!pending || !item) return false;
        if (pending === 'given') {
            if (item.requires_witness && !witnessedBy) return false;
            if (needsBloodGlucose(item.medication_name) && !bloodGlucose) return false;
            if (needsPulse(item.medication_name) && !pulse) return false;
            return true;
        }
        // refused / held
        if (!reasonCode) return false;
        if (reasonObj?.requires_detail && !reason.trim()) return false;
        return true;
    })();

    const submit = () => {
        if (!item || !pending) return;
        setSaving(true);
        router.post(
            `/emar/rounds/${round.id}/guided/items/${item.medication_id}`,
            {
                status: pending,
                scheduled_for: item.scheduled_for,
                reason: reason || null,
                reason_code: pending === 'given' ? null : reasonCode || null,
                witnessed_by: pending === 'given' && witnessedBy ? Number(witnessedBy) : null,
                witness_credential: witnessCredential || null,
                blood_glucose_level: pending === 'given' && bloodGlucose ? Number(bloodGlucose) : null,
                pulse_bpm: pending === 'given' && pulse ? Number(pulse) : null,
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Dose ${pending}`);
                    // Advance to the next still-due dose, else the summary.
                    const nextDue = items.findIndex((it, i) => i > stepIndex && !it.administration);
                    goTo(nextDue === -1 ? items.length : nextDue);
                },
                onError: () => toast.error('Could not record this dose'),
                onFinish: () => setSaving(false),
            },
        );
    };

    const finish = () => {
        setSaving(true);
        router.post(`/emar/rounds/${round.id}/guided/complete`, {}, {
            preserveScroll: true,
            onSuccess: () => {
                toast.success('Round completed');
                onClose();
            },
            onFinish: () => setSaving(false),
        });
    };

    // ── Footer ──────────────────────────────────────────────────────────────
    const footer = isSummary ? (
        <>
            <Button variant="ghost" onClick={onClose}>
                Close
            </Button>
            <Button onClick={finish} disabled={saving}>
                <Check className="h-4 w-4" />
                Finish round
            </Button>
        </>
    ) : pending ? (
        <>
            <Button variant="ghost" onClick={resetPanel} disabled={saving}>
                Cancel
            </Button>
            <Button onClick={submit} disabled={!confirmValid || saving}>
                Confirm
            </Button>
        </>
    ) : (
        <>
            <Button variant="ghost" onClick={() => goTo(Math.max(0, stepIndex - 1))} disabled={stepIndex === 0}>
                Previous
            </Button>
            <Button variant="outline" onClick={() => goTo(stepIndex + 1)}>
                {stepIndex >= items.length - 1 ? 'Summary' : 'Next'}
            </Button>
        </>
    );

    return (
        <MedsWizardDialog
            open
            onClose={onClose}
            title={`Guided round · ${round.name}`}
            description="Record each dose in the round with the safety gate."
            railIcon={Pill}
            railTitle={round.name}
            railSubtitle={`${round.scheduled_time} · ±${round.window_minutes} min`}
            railFooter={<span className="text-xs text-muted-foreground">Round progress {progress.percent}%</span>}
            steps={steps}
            stepIndex={stepIndex}
            onStepClick={(i) => goTo(i)}
            footer={footer}
        >
            {isSummary ? (
                <SummaryPane round={round.name} progress={progress} />
            ) : item ? (
                item.administration ? (
                    <RecordedPane item={item} />
                ) : (
                    <DosePane
                        item={item}
                        identity={identity}
                        setIdentity={setIdentity}
                        pending={pending}
                        setPending={setPending}
                        canRecord={signer.med_competent}
                        reasonCode={reasonCode}
                        setReasonCode={setReasonCode}
                        reason={reason}
                        setReason={setReason}
                        reasonObj={reasonObj}
                        notGivenReasons={notGivenReasons}
                        witnesses={witnesses}
                        witnessedBy={witnessedBy}
                        setWitnessedBy={setWitnessedBy}
                        witnessCredential={witnessCredential}
                        setWitnessCredential={setWitnessCredential}
                        bloodGlucose={bloodGlucose}
                        setBloodGlucose={setBloodGlucose}
                        pulse={pulse}
                        setPulse={setPulse}
                    />
                )
            ) : null}
        </MedsWizardDialog>
    );
}

function FlagPill({ label, tone }: { label: string; tone: string }) {
    return <span className={cn('rounded px-1.5 py-0.5 text-[10px] font-bold uppercase tracking-wide', tone)}>{label}</span>;
}

function DoseHeader({ item }: { item: RoundItem }) {
    return (
        <div className="flex items-center gap-3 rounded-xl border bg-background p-3">
            <span className="flex h-12 w-12 items-center justify-center rounded-full bg-primary/10 text-sm font-bold text-primary">
                {initials(item.client_name)}
            </span>
            <div className="min-w-0">
                <div className="font-bold">{item.client_name}</div>
                <div className="text-xs text-muted-foreground">
                    {item.medication_name} · {[item.dose, item.route, item.form].filter(Boolean).join(' · ')}
                </div>
            </div>
        </div>
    );
}

type DosePaneProps = {
    item: RoundItem;
    identity: boolean;
    setIdentity: (v: boolean) => void;
    pending: Pending;
    setPending: (p: Pending) => void;
    canRecord: boolean;
    reasonCode: string;
    setReasonCode: (v: string) => void;
    reason: string;
    setReason: (v: string) => void;
    reasonObj: NotGivenReason | undefined;
    notGivenReasons: NotGivenReason[];
    witnesses: StaffOption[];
    witnessedBy: string;
    setWitnessedBy: (v: string) => void;
    witnessCredential: string;
    setWitnessCredential: (v: string) => void;
    bloodGlucose: string;
    setBloodGlucose: (v: string) => void;
    pulse: string;
    setPulse: (v: string) => void;
};

function DosePane(props: DosePaneProps) {
    const { item, identity, setIdentity, pending, setPending, canRecord } = props;
    return (
        <div className="flex flex-col gap-4">
            <DoseHeader item={item} />

            {(item.is_controlled || item.requires_witness || item.is_high_risk || needsBloodGlucose(item.medication_name) || needsPulse(item.medication_name)) && (
                <div className="flex flex-wrap gap-1.5">
                    {item.is_controlled && <FlagPill label="Controlled" tone="bg-status-critical-bg text-status-critical" />}
                    {item.requires_witness && <FlagPill label="Witness" tone="bg-status-warning-bg text-status-warning" />}
                    {item.is_high_risk && <FlagPill label="High-risk" tone="bg-status-warning-bg text-status-warning" />}
                    {needsBloodGlucose(item.medication_name) && <FlagPill label="Blood glucose" tone="bg-status-info-bg text-status-info" />}
                    {needsPulse(item.medication_name) && <FlagPill label="Apical pulse" tone="bg-status-info-bg text-status-info" />}
                </div>
            )}

            {item.instructions && (
                <InfoCard icon={AlertTriangle} tone="warn">
                    {item.instructions}
                </InfoCard>
            )}

            <label className="flex items-center gap-2 rounded-lg border bg-accent/40 px-3 py-2.5 text-sm font-medium">
                <input type="checkbox" checked={identity} onChange={(e) => setIdentity(e.target.checked)} className="h-4 w-4" />
                Right resident, right medication (6 Rs confirmed)
            </label>

            {!pending ? (
                <div className="grid grid-cols-3 gap-2">
                    <Button variant="outline" disabled={!identity || !canRecord} onClick={() => setPending('given')}>
                        <Check className="h-4 w-4" />
                        Given
                    </Button>
                    <Button variant="outline" disabled={!identity || !canRecord} onClick={() => setPending('refused')}>
                        <Ban className="h-4 w-4" />
                        Refused
                    </Button>
                    <Button variant="outline" disabled={!identity || !canRecord} onClick={() => setPending('held')}>
                        <Hand className="h-4 w-4" />
                        Held
                    </Button>
                </div>
            ) : (
                <ConfirmPanel {...props} />
            )}
        </div>
    );
}

function ConfirmPanel(props: DosePaneProps) {
    const {
        item, pending, reasonCode, setReasonCode, reason, setReason, reasonObj, notGivenReasons,
        witnesses, witnessedBy, setWitnessedBy, witnessCredential, setWitnessCredential,
        bloodGlucose, setBloodGlucose, pulse, setPulse,
    } = props;

    return (
        <div className="flex flex-col gap-4 rounded-xl border bg-background p-3">
            <div className="text-sm font-semibold capitalize">{pending} — confirm</div>
            {pending !== 'given' && (
                <>
                    <Field label="Coded reason" required>
                        <SelectInput
                            value={reasonCode}
                            onChange={setReasonCode}
                            placeholder="Select reason…"
                            options={notGivenReasons.map((r) => ({ value: r.value, label: r.label }))}
                        />
                    </Field>
                    <Field label="Reason detail" required={!!reasonObj?.requires_detail}>
                        <Input value={reason} onChange={(e) => setReason(e.target.value)} placeholder="Add a note" />
                    </Field>
                </>
            )}
            {pending === 'given' && item.requires_witness && (
                <>
                    <Field label="Controlled-drug witness" required>
                        <SelectInput
                            value={witnessedBy}
                            onChange={setWitnessedBy}
                            placeholder="Select witness…"
                            options={witnesses.map((w) => ({ value: String(w.id), label: w.name }))}
                        />
                    </Field>
                    <Field label="Witness password / PIN">
                        <Input type="password" value={witnessCredential} onChange={(e) => setWitnessCredential(e.target.value)} placeholder="Re-authenticate" />
                    </Field>
                </>
            )}
            {pending === 'given' && needsBloodGlucose(item.medication_name) && (
                <Field label="Blood glucose (mmol/L)" required>
                    <Input type="number" step="0.1" value={bloodGlucose} onChange={(e) => setBloodGlucose(e.target.value)} placeholder="e.g. 6.4" />
                </Field>
            )}
            {pending === 'given' && needsPulse(item.medication_name) && (
                <Field label="Apical pulse (bpm)" required hint="Warn if below 60">
                    <Input type="number" value={pulse} onChange={(e) => setPulse(e.target.value)} placeholder="e.g. 72" />
                </Field>
            )}
        </div>
    );
}

function RecordedPane({ item }: { item: RoundItem }) {
    const status = item.administration?.status ?? '';
    const tone = status === 'given' ? 'text-status-success' : 'text-status-warning';
    return (
        <div className="flex flex-col gap-4">
            <DoseHeader item={item} />
            <div className="rounded-xl border bg-status-success-bg/30 p-4">
                <div className={cn('text-sm font-bold capitalize', tone)}>{status}</div>
                <div className="mt-1 text-xs text-muted-foreground">
                    {item.administration?.administered_at ? new Date(item.administration.administered_at).toLocaleString('en-NZ') : ''}
                </div>
                {item.administration?.reason && <div className="mt-1 text-xs text-muted-foreground">Reason: {item.administration.reason}</div>}
            </div>
            <p className="text-xs text-muted-foreground">This dose is already recorded in this round. Use Next to continue.</p>
        </div>
    );
}

function SummaryPane({ round, progress }: { round: string; progress: GuidedRound['progress'] }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center gap-3">
                <span className={cn('flex h-12 w-12 items-center justify-center rounded-full', progress.pending === 0 ? 'bg-status-success-bg text-status-success' : 'bg-status-warning-bg text-status-warning')}>
                    {progress.pending === 0 ? <CheckCircle2 className="h-6 w-6" /> : <AlertTriangle className="h-6 w-6" />}
                </span>
                <div>
                    <div className="text-[15px] font-bold">{round}</div>
                    <div className="text-xs text-muted-foreground">{progress.completed} of {progress.total} doses recorded · {progress.pending} remaining</div>
                </div>
            </div>
            <div className="grid grid-cols-4 gap-2">
                <SummaryStat label="Given" value={progress.given} tone="text-status-success" />
                <SummaryStat label="Refused" value={progress.refused} tone="text-status-warning" />
                <SummaryStat label="Held" value={progress.held} tone="text-status-warning" />
                <SummaryStat label="Due" value={progress.pending} tone="text-status-critical" />
            </div>
        </div>
    );
}

function SummaryStat({ label, value, tone }: { label: string; value: number; tone: string }) {
    return (
        <div className="rounded-lg border bg-background px-2 py-3 text-center">
            <div className={cn('text-xl font-bold', tone)}>{value}</div>
            <div className="text-[10px] uppercase tracking-wide text-muted-foreground">{label}</div>
        </div>
    );
}
