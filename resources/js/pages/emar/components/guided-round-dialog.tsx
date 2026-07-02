/* eslint-disable no-restricted-syntax -- dose/resident/summary panes are
   custom-layout bordered surfaces inside the wizard, not Card components; all
   colours are semantic tokens. */
import { ClientAvatar } from '@/components/meds/board-bits';
import RoundAuditTimeline, { itemsToAuditEntries, type RoundAuditMeta } from '@/components/emar/rounds/round-audit-timeline';
import { DoseStatusBadge } from '@/components/emar/rounds/round-bits';
import { doseStatusMeta, type GuidedRound, type RoundItem, type StaffOption } from '@/components/emar/rounds/types';
import { MedsWizardDialog } from '@/components/meds/wizard-shell';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Field, InfoCard, SelectInput } from '@/components/wizard/primitives';
import { cn } from '@/lib/utils';
import { router } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    ArrowRight,
    Ban,
    Check,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    Droplet,
    Hand,
    Heart,
    Pencil,
    Pill,
    Printer,
    ShieldAlert,
    Users,
} from 'lucide-react';
import type { ComponentType } from 'react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

type NotGivenReason = { value: string; label: string; requires_detail: boolean };

type Props = {
    guided: GuidedRound;
    witnesses: StaffOption[];
    notGivenReasons: NotGivenReason[];
    signer: { med_competent: boolean; cd_witness: boolean };
    canExport: boolean;
    onPrint: () => void;
    onClose: () => void;
};

type Pending = 'given' | 'refused' | 'held' | null;

function firstName(name: string): string {
    return name.split(/\s+/)[0] ?? name;
}
function shortMed(name: string): string {
    return name.length > 16 ? `${name.slice(0, 15)}…` : name;
}
function fmtWhen(iso: string | null): string {
    if (!iso) return 'just now';
    const d = new Date(iso);
    return Number.isNaN(d.getTime()) ? '' : d.toLocaleString('en-NZ', { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' });
}

export default function GuidedRoundDialog({ guided, witnesses, notGivenReasons, signer, canExport, onPrint, onClose }: Props) {
    const { round, items, progress } = guided;
    const [stepIndex, setStepIndex] = useState(progress.next_index ?? 0);
    const [identity, setIdentity] = useState(false);
    const [pending, setPending] = useState<Pending>(null);
    const [reasonCode, setReasonCode] = useState('');
    const [reason, setReason] = useState('');
    const [witnessedBy, setWitnessedBy] = useState('');
    const [witnessCredential, setWitnessCredential] = useState('');
    const [quantityGiven, setQuantityGiven] = useState('');
    const [bloodGlucose, setBloodGlucose] = useState('');
    const [pulse, setPulse] = useState('');
    const [saving, setSaving] = useState(false);
    const [reRecording, setReRecording] = useState<Record<number, boolean>>({});

    const isSummary = stepIndex >= items.length;
    const item: RoundItem | undefined = items[stepIndex];
    const showRecorded = !!item?.administration && !reRecording[item.medication_id];

    const resetPanel = () => {
        setPending(null);
        setReasonCode('');
        setReason('');
        setWitnessedBy('');
        setWitnessCredential('');
        setQuantityGiven('');
        setBloodGlucose('');
        setPulse('');
    };

    const goTo = (index: number) => {
        resetPanel();
        setIdentity(false);
        setStepIndex(index);
    };

    const startReRecord = (medicationId: number) => {
        resetPanel();
        setIdentity(false);
        setReRecording((prev) => ({ ...prev, [medicationId]: true }));
    };

    const steps = useMemo(
        () => [
            ...items.map((it) => {
                const status = it.administration?.status ?? null;
                const icon = status === 'given' ? CheckCircle2 : status === 'refused' || status === 'withheld' ? Ban : Pill;
                return { key: `${it.medication_id}-${it.scheduled_for}`, label: `${firstName(it.client_name)} · ${shortMed(it.medication_name)}`, blurb: it.dose ?? '', icon };
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
            if (item.is_controlled && !quantityGiven) return false;
            if (item.requires_blood_glucose && !bloodGlucose) return false;
            if (item.requires_pulse && !pulse) return false;
            return true;
        }
        if (!reasonCode) return false;
        if (reasonObj?.requires_detail && !reason.trim()) return false;
        return true;
    })();

    const submit = () => {
        if (!item || !pending) return;
        const medicationId = item.medication_id;
        setSaving(true);
        router.post(
            `/emar/rounds/${round.id}/guided/items/${medicationId}`,
            {
                status: pending,
                scheduled_for: item.scheduled_for,
                reason: reason || null,
                reason_code: pending === 'given' ? null : reasonCode || null,
                witnessed_by: pending === 'given' && witnessedBy ? Number(witnessedBy) : null,
                witness_credential: witnessCredential || null,
                quantity_administered: pending === 'given' && quantityGiven ? Number(quantityGiven) : null,
                blood_glucose_level: pending === 'given' && bloodGlucose ? Number(bloodGlucose) : null,
                pulse_bpm: pending === 'given' && pulse ? Number(pulse) : null,
                client_request_uuid: crypto.randomUUID(),
            },
            {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => {
                    toast.success(`Dose ${pending}`);
                    setReRecording((prev) => {
                        const next = { ...prev };
                        delete next[medicationId];
                        return next;
                    });
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

    const goToFirstDue = () => {
        const idx = items.findIndex((it) => !it.administration);
        goTo(idx === -1 ? items.length : idx);
    };

    // ── Footer ──────────────────────────────────────────────────────────────
    const footer = isSummary ? (
        <>
            {canExport ? (
                <Button variant="outline" onClick={onPrint}>
                    <Printer className="h-4 w-4" />
                    Print round sheet
                </Button>
            ) : (
                <span />
            )}
            {progress.pending === 0 ? (
                <Button onClick={finish} disabled={saving}>
                    <Check className="h-4 w-4" />
                    Finish round
                </Button>
            ) : (
                <Button onClick={goToFirstDue}>
                    <ArrowRight className="h-4 w-4" />
                    Go to next due
                </Button>
            )}
        </>
    ) : pending ? (
        <>
            <Button variant="ghost" onClick={resetPanel} disabled={saving}>
                Cancel
            </Button>
            <Button onClick={submit} disabled={!confirmValid || saving}>
                <Check className="h-4 w-4" />
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
                <SummaryPane round={round} progress={progress} entries={itemsToAuditEntries(items)} meta={summaryMeta(guided)} />
            ) : item ? (
                showRecorded ? (
                    <RecordedPane item={item} onReRecord={() => startReRecord(item.medication_id)} />
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
                        quantityGiven={quantityGiven}
                        setQuantityGiven={setQuantityGiven}
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

function summaryMeta(guided: GuidedRound): RoundAuditMeta {
    const r = guided.round;
    return {
        template_name: r.template_name,
        created_at: r.created_at,
        assignee: r.assignee,
        started_at: r.started_at,
        started_by: r.started_by,
        completed_at: r.completed_at,
        completed_by: r.completed_by,
    };
}

function FlagPill({ icon: Icon, label, tone }: { icon: ComponentType<{ className?: string }>; label: string; tone: string }) {
    return (
        <span className={cn('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold', tone)}>
            <Icon className="h-3 w-3" />
            {label}
        </span>
    );
}

function DoseCard({ item }: { item: RoundItem }) {
    const hasFlags =
        item.is_controlled || item.requires_witness || item.is_high_risk || item.requires_blood_glucose || item.requires_pulse;
    return (
        <div className="overflow-hidden rounded-xl border">
            <div className="flex items-center gap-3.5 border-b bg-muted/40 p-4">
                <ClientAvatar name={item.client_name} clientId={item.client_id} className="h-13 w-13 text-base" />
                <div className="min-w-0 flex-1">
                    <div className="text-[17px] font-bold">{item.client_name}</div>
                    <div className="text-xs text-muted-foreground">{item.site_name ?? '—'}</div>
                </div>
                {item.administration ? <DoseStatusBadge status={item.administration.status} /> : null}
            </div>
            <div className="flex flex-col gap-2.5 p-4">
                <div className="flex flex-wrap items-baseline gap-2">
                    <span className="text-[19px] font-bold">{item.medication_name}</span>
                    <span className="text-sm text-muted-foreground">{[item.dose, item.route, item.form].filter(Boolean).join(' · ')}</span>
                </div>
                {item.instructions ? (
                    <InfoCard icon={AlertTriangle} tone="warn">
                        {item.instructions}
                    </InfoCard>
                ) : null}
                {hasFlags ? (
                    <div className="flex flex-wrap gap-1.5">
                        {item.is_controlled ? <FlagPill icon={ShieldAlert} label="Controlled drug" tone="bg-status-info-bg text-status-info" /> : null}
                        {item.requires_witness ? <FlagPill icon={Users} label="Witness required" tone="bg-status-warning-bg text-status-warning" /> : null}
                        {item.is_high_risk ? <FlagPill icon={AlertTriangle} label="High-risk" tone="bg-status-critical-bg text-status-critical" /> : null}
                        {item.requires_blood_glucose ? <FlagPill icon={Droplet} label="Record blood glucose" tone="bg-status-info-bg text-status-info" /> : null}
                        {item.requires_pulse ? <FlagPill icon={Heart} label="Check apical pulse" tone="bg-status-info-bg text-status-info" /> : null}
                    </div>
                ) : null}
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
    quantityGiven: string;
    setQuantityGiven: (v: string) => void;
    bloodGlucose: string;
    setBloodGlucose: (v: string) => void;
    pulse: string;
    setPulse: (v: string) => void;
};

function DosePane(props: DosePaneProps) {
    const { item, identity, setIdentity, pending, setPending, canRecord } = props;
    return (
        <div className="flex flex-col gap-4">
            <DoseCard item={item} />

            <label
                className={cn(
                    'flex cursor-pointer items-start gap-3 rounded-xl border px-3.5 py-3',
                    identity ? 'border-status-success/40 bg-status-success-bg' : 'bg-card',
                )}
            >
                <input type="checkbox" checked={identity} onChange={(e) => setIdentity(e.target.checked)} className="mt-0.5 h-4 w-4" />
                <span>
                    <span className="block text-sm font-semibold">Right resident, right medication</span>
                    <span className="mt-0.5 block text-[11.5px] text-muted-foreground">
                        I have confirmed identity against the photo and NHI, and checked the medication, dose, route and time.
                    </span>
                </span>
            </label>

            {!pending ? (
                <>
                    <div className="grid grid-cols-3 gap-2">
                        <Button variant="outline" className="h-12 text-sm" disabled={!identity || !canRecord} onClick={() => setPending('given')}>
                            <Check className="h-4 w-4" />
                            Given
                        </Button>
                        <Button variant="outline" className="h-12 text-sm" disabled={!identity || !canRecord} onClick={() => setPending('refused')}>
                            <Ban className="h-4 w-4" />
                            Refused
                        </Button>
                        <Button variant="outline" className="h-12 text-sm" disabled={!identity || !canRecord} onClick={() => setPending('held')}>
                            <Hand className="h-4 w-4" />
                            Held
                        </Button>
                    </div>
                    {!identity ? (
                        <p className="text-center text-[11.5px] text-muted-foreground">Confirm identity above to enable recording.</p>
                    ) : null}
                </>
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
        quantityGiven, setQuantityGiven, bloodGlucose, setBloodGlucose, pulse, setPulse,
    } = props;

    const title = pending === 'given' ? 'Confirm administration' : pending === 'refused' ? 'Record refusal' : 'Record withheld dose';
    const tone = pending === 'given' ? 'text-status-success' : 'text-status-warning';

    return (
        <div className="flex flex-col gap-4 rounded-xl border bg-background p-4">
            <div className={cn('text-sm font-bold', tone)}>{title}</div>
            {pending !== 'given' ? (
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
            ) : null}
            {pending === 'given' && item.is_controlled ? (
                <Field label="Units given" required hint="Removed from CD stock — the register entry uses this quantity.">
                    <Input
                        type="number"
                        min={0.25}
                        step={0.25}
                        value={quantityGiven}
                        onChange={(e) => setQuantityGiven(e.target.value)}
                        placeholder="e.g. 1"
                    />
                </Field>
            ) : null}
            {pending === 'given' && item.requires_witness ? (
                <>
                    <Field label="Controlled-drug witness" required hint="Both signatures are written to the CD register.">
                        <SelectInput
                            value={witnessedBy}
                            onChange={setWitnessedBy}
                            placeholder="Select a second signatory…"
                            options={witnesses.map((w) => ({ value: String(w.id), label: w.name }))}
                        />
                    </Field>
                    <Field label="Witness password / PIN">
                        <Input type="password" value={witnessCredential} onChange={(e) => setWitnessCredential(e.target.value)} placeholder="Re-authenticate" />
                    </Field>
                </>
            ) : null}
            {pending === 'given' && item.requires_blood_glucose ? (
                <Field label="Blood glucose (mmol/L)" required>
                    <Input type="number" step="0.1" value={bloodGlucose} onChange={(e) => setBloodGlucose(e.target.value)} placeholder="e.g. 7.2" />
                </Field>
            ) : null}
            {pending === 'given' && item.requires_pulse ? (
                <Field label="Apical pulse (bpm)" required hint="Withhold and tell the nurse if under 60 bpm.">
                    <Input type="number" value={pulse} onChange={(e) => setPulse(e.target.value)} placeholder="e.g. 72" />
                </Field>
            ) : null}
        </div>
    );
}

function RecordedPane({ item, onReRecord }: { item: RoundItem; onReRecord: () => void }) {
    const a = item.administration!;
    const meta = doseStatusMeta(a.status);
    const toneText = meta.tone === 'success' ? 'text-status-success' : meta.tone === 'critical' ? 'text-status-critical' : 'text-status-warning';
    const toneBg = meta.tone === 'success' ? 'bg-status-success-bg' : meta.tone === 'critical' ? 'bg-status-critical-bg' : 'bg-status-warning-bg';
    const dotBg = meta.tone === 'success' ? 'bg-status-success' : meta.tone === 'critical' ? 'bg-status-critical' : 'bg-status-warning';
    const Icon = a.status === 'given' ? Check : a.status === 'refused' ? Ban : a.status === 'missed' ? AlertTriangle : Hand;
    const chips = [
        a.witnessed_by ? `Witness: ${a.witnessed_by}` : null,
        a.blood_glucose_level != null ? `BG ${a.blood_glucose_level} mmol/L` : null,
        a.pulse_bpm != null ? `Pulse ${a.pulse_bpm} bpm` : null,
    ].filter(Boolean) as string[];

    return (
        <div className="flex flex-col gap-4">
            <DoseCard item={item} />
            <div className={cn('rounded-xl border p-4', toneBg)}>
                <div className="flex items-center gap-3">
                    <span className={cn('grid h-8 w-8 shrink-0 place-items-center rounded-full text-white', dotBg)}>
                        <Icon className="h-4 w-4" />
                    </span>
                    <div>
                        <div className={cn('text-sm font-bold', toneText)}>Recorded as {meta.label}</div>
                        <div className="text-[11.5px] text-muted-foreground">
                            {(a.administered_by ?? 'Staff')} · {fmtWhen(a.administered_at)}
                        </div>
                    </div>
                </div>
                {chips.length > 0 ? (
                    <div className="mt-3 flex flex-wrap gap-1.5">
                        {chips.map((c) => (
                            <span key={c} className="rounded-md border bg-card px-2 py-0.5 text-[11.5px]">
                                {c}
                            </span>
                        ))}
                    </div>
                ) : null}
                {a.reason ? <div className="mt-2 text-xs text-foreground italic">“{a.reason}”</div> : null}
                <div className="mt-3">
                    <Button variant="ghost" size="sm" onClick={onReRecord}>
                        <Pencil className="h-4 w-4" />
                        Re-record
                    </Button>
                </div>
            </div>
        </div>
    );
}

function SummaryStat({ label, value, tone, bg }: { label: string; value: number; tone: string; bg: string }) {
    return (
        <div className={cn('rounded-xl px-2 py-3 text-center', bg)}>
            <div className={cn('text-[19px] font-bold', tone)}>{value}</div>
            <div className="text-[11px] text-muted-foreground">{label}</div>
        </div>
    );
}

function SummaryPane({
    round,
    progress,
    entries,
    meta,
}: {
    round: GuidedRound['round'];
    progress: GuidedRound['progress'];
    entries: ReturnType<typeof itemsToAuditEntries>;
    meta: RoundAuditMeta;
}) {
    const done = progress.pending === 0;
    return (
        <div className="flex flex-col gap-5">
            <div className="flex flex-col items-center gap-3 pt-1 text-center">
                <span
                    className={cn(
                        'grid h-16 w-16 place-items-center rounded-full',
                        done ? 'bg-status-success-bg text-status-success' : 'bg-status-warning-bg text-status-warning',
                    )}
                >
                    {done ? <Check className="h-8 w-8" /> : <Clock className="h-8 w-8" />}
                </span>
                <div>
                    <h2 className="text-xl font-bold">{done ? 'Round complete' : 'Round summary'}</h2>
                    <p className="mx-auto mt-1 max-w-[440px] text-[13px] leading-relaxed text-muted-foreground">
                        {done
                            ? `Every dose in ${round.name} has been recorded. Refusals and held doses are flagged for follow-up.`
                            : `${progress.pending} dose${progress.pending === 1 ? '' : 's'} still to give in ${round.name}.`}
                    </p>
                </div>
            </div>

            <div className="grid grid-cols-4 gap-2">
                <SummaryStat label="Given" value={progress.given} tone="text-status-success" bg="bg-status-success-bg" />
                <SummaryStat label="Refused" value={progress.refused} tone="text-status-warning" bg="bg-status-warning-bg" />
                <SummaryStat label="Held" value={progress.held} tone="text-status-warning" bg="bg-status-warning-bg" />
                <SummaryStat label="Due" value={progress.pending} tone="text-status-critical" bg="bg-status-critical-bg" />
            </div>

            <div className="border-t pt-4">
                <div className="mb-3.5 flex items-center gap-2 text-[13px] font-bold">
                    <Activity className="h-4 w-4 text-primary" />
                    Audit &amp; timeline
                </div>
                <RoundAuditTimeline meta={meta} entries={entries} />
            </div>
        </div>
    );
}
