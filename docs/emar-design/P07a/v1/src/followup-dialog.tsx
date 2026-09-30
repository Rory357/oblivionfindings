/* The house lead's follow-up for doses given without a witness under a
 * manager's override (P00 v5: "a next-shift follow-up goes to the house
 * lead"). Stephan, 30 Sep 2026: a witnessed count of those medicines by the
 * end of the next shift, then a sign-off of each dose. Real WizardShell,
 * ReviewCard/ReviewRow, Checkbox, Textarea, WizardSuccessPane. */
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Check, CheckCircle2, ChevronLeft, ChevronRight, ClipboardCheck, FileText, Loader2, ShieldCheck, Users } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { NOW_LABEL } from './clock';
import { MORNING_OVERRIDE, PEOPLE, PERSONAS, cdById, doseById, qty, staffById } from './data';
import { DISCREPANCY, FOLLOW_UP, morningOverride } from './model';
import { useStore, type CountRecord, type Discrepancy } from './store';
import { CountRow, focusFirst, lineResult, validateCounts, type CountLine } from './count-dialog';
import { KV, Notice } from './ui';
import { WitnessField, checkPin, type WitnessValue } from './witness';

const STEPS = [
    { key: 'doses', label: 'The doses', blurb: 'Given without a witness', icon: FileText },
    { key: 'count', label: 'Witnessed count', blurb: 'Those medicines, with a witness', icon: ClipboardCheck },
    { key: 'sign', label: 'Sign off', blurb: 'Check, then sign off', icon: Check },
];
const blank: CountLine = { first: '', recount: '', found: '', did: '' };

export function FollowUpDialog({ onClose, returnFocus }: { onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const persona = PERSONAS[s.route.persona];
    const o = morningOverride(s.route.scenario);
    const doses = MORNING_OVERRIDE.doses.map(doseById);
    const meds = [...new Set(doses.map((d) => d.cdMed!))].map(cdById);
    const canSign = s.route.persona === 'lead' || s.route.persona === 'pm';
    // A count made after the doses with the lead taking part (counting or witnessing) covers the count step.
    const covered = meds.every((m) => {
        const c = s.counted[m.id];
        return c && (c.by === FOLLOW_UP.owner || c.witness === FOLLOW_UP.owner);
    });
    const [step, setStep] = useState(0);
    const [lines, setLines] = useState<Record<string, CountLine>>({});
    const [witness, setWitness] = useState<WitnessValue>({ id: null, pin: '' });
    const [checked, setChecked] = useState(false);
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [discs, setDiscs] = useState<Discrepancy[]>([]);
    const bodyRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        bodyRef.current?.closest('[data-wizard-region="body"]')?.scrollTo?.(0, 0);
    }, [step]);
    const set = (id: string) => (p: Partial<CountLine>) => setLines((x) => ({ ...x, [id]: { ...(x[id] ?? blank), ...p } }));
    const dirty = Object.values(lines).some((l) => l.first) || checked || !!witness.id;
    const witnessName = witness.id ? staffById(witness.id).name : null;

    function next() {
        let e: Record<string, string> = {};
        if (step === 1 && !covered) {
            e = validateCounts(meds, lines, s.balanceOf);
            if (!witness.id) e.witness = 'Choose who is witnessing the count.';
            else if (!/^\d{6}$/.test(witness.pin)) e.pin = 'Enter their 6-digit PIN.';
        }
        setErrors(e);
        if (Object.keys(e).length) return focusFirst(e);
        setStep(step + 1);
    }
    function save() {
        if (!checked) {
            setErrors({ 'fu-check': 'Tick to confirm you’ve checked each dose.' });
            return focusFirst({ 'fu-check': 'x' });
        }
        setPhase('sending');
        window.setTimeout(() => {
            if (!covered) {
                const pinErr = checkPin(witnessName!, witness.pin);
                if (pinErr) {
                    setPhase('edit');
                    setStep(1);
                    setWitness((w) => ({ ...w, pin: '' }));
                    setErrors({ pin: pinErr });
                    return focusFirst({ pin: pinErr });
                }
                const recs: Record<string, CountRecord> = {};
                const ds: Discrepancy[] = [];
                meds.forEach((m, i) => {
                    const r = lineResult(lines[m.id], s.balanceOf(m));
                    if (r.kind === 'empty' || r.kind === 'recount') return;
                    recs[m.id] = { at: NOW_LABEL, by: persona.name, witness: witnessName!, result: r.kind === 'differs' ? 'discrepancy' : 'matched', counted: r.final, register: s.balanceOf(m), firstCount: r.kind === 'differs' || r.kind === 'matchSecond' ? r.first : undefined };
                    if (r.kind === 'differs') ds.push({ id: `disc-fu-${m.id}`, ref: `CD-2026-0${18 + i}`, cdMed: m.id, startedAt: NOW_LABEL, by: persona.name, witness: witnessName!, register: s.balanceOf(m), firstCount: r.first, recount: r.final, found: lines[m.id].found, did: lines[m.id].did, owner: DISCREPANCY.owner });
                });
                s.recordCounts(recs, ds);
                setDiscs(ds);
            }
            s.signFollowUp();
            setPhase('done');
        }, 900);
    }
    function requestClose() {
        if (phase === 'sending') return;
        if (phase === 'done' || !dirty) return onClose();
        setDiscard(true);
    }

    const step0: ReactNode = (
        <>
            <Notice tone="warning" icon={ShieldCheck} title={`Recorded without a witness — override by ${MORNING_OVERRIDE.by}`}>
                {o.covers}, {MORNING_OVERRIDE.from} – {o.until} today. Reason: {MORNING_OVERRIDE.reason.toLowerCase()}. Note from {MORNING_OVERRIDE.requestedBy}, who asked at {MORNING_OVERRIDE.requestedAt}: “{MORNING_OVERRIDE.note}”
            </Notice>
            {doses.map((d) => {
                const m = cdById(d.cdMed!);
                const e = s.entries().find((x) => x.cdMed === m.id && x.kind === 'dose' && x.at === d.at);
                return (
                    <section key={d.id} className="rounded-xl border bg-card p-4" aria-label={`${PEOPLE[d.pid].pref} · ${d.med} ${d.slot}`}>
                        <p className="text-[14px] font-semibold">
                            {PEOPLE[d.pid].pref} · {d.med} <span className="font-normal text-muted-foreground">{d.strength}</span> · {d.slot} dose
                        </p>
                        <div className="mt-2">
                            <KV
                                rows={[
                                    ['Given', `${d.at} by ${d.by} · 1 ${m.unit} as ordered`],
                                    ['Witness', <StatusBadge key="w" variant="warning" size="sm">No witness — override by {MORNING_OVERRIDE.by}</StatusBadge>],
                                    ['Register after', e ? qty(e.after, m) : '—'],
                                ]}
                            />
                        </div>
                    </section>
                );
            })}
            <p className="text-caption">Due {FOLLOW_UP.dueLine.toLowerCase()}. Until it’s signed off, it stays visible to everyone rostered at Kōwhai House and the house lead.</p>
        </>
    );
    const step1: ReactNode = covered ? (
        <Notice tone="success" icon={CheckCircle2} title="Already counted with you taking part">
            {meds.map((m) => {
                const c = s.counted[m.id];
                return (
                    <span key={m.id} className="block">
                        {m.med}: counted {c.at} by {c.by}, witnessed by {c.witness} — {c.result === 'matched' ? 'matches the register' : 'discrepancy started'}.
                    </span>
                );
            })}
            <span className="mt-1 block">Nothing more to count. Continue to sign off.</span>
        </Notice>
    ) : (
        <>
            <p className="text-sm">Count {meds.map((m) => m.med.toLowerCase()).join(' and ')} with a witness. A shift-change count you take part in counts too.</p>
            {meds.map((m, i) => (
                <CountRow key={m.id} m={m} idx={i} register={s.balanceOf(m)} line={lines[m.id] ?? blank} set={set(m.id)} errors={errors} />
            ))}
            <WitnessField persona={s.route.persona} house="kowhai" scenario={s.route.scenario} value={witness} onChange={(v) => (setWitness(v), setErrors({}))} errors={errors} purpose="the count" />
        </>
    );
    const step2: ReactNode = (
        <>
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={FileText} title="Doses" onEdit={() => setStep(0)}>
                    {doses.map((d) => (
                        <ReviewRow key={d.id} label={`${PEOPLE[d.pid].pref} · ${d.med} ${d.slot}`} value={`Given ${d.at} · ${d.by}`} />
                    ))}
                </ReviewCard>
                <ReviewCard icon={ClipboardCheck} title="Witnessed count" onEdit={() => setStep(1)}>
                    {meds.map((m) => {
                        const c = s.counted[m.id];
                        const r = lineResult(lines[m.id] ?? blank, s.balanceOf(m));
                        return <ReviewRow key={m.id} label={m.med} value={c ? `${qty(c.counted, m)} · ${c.result === 'matched' ? 'matches' : 'discrepancy'} (${c.at})` : r.kind === 'differs' ? `${qty(r.final, m)} · differs — a discrepancy starts` : r.kind === 'empty' || r.kind === 'recount' ? '—' : `${qty(r.final, m)} · matches`} />;
                    })}
                    {!covered ? <ReviewRow label="Witnessed by" value={witnessName} /> : null}
                </ReviewCard>
            </div>
            <div className="space-y-1" data-field="fu-check">
                <div className="flex items-start gap-2">
                    <Checkbox id="fu-check" checked={checked} onCheckedChange={(v) => (setChecked(v === true), setErrors({}))} aria-invalid={!!errors['fu-check']} className="mt-0.5" />
                    <Label htmlFor="fu-check" className="text-sm font-normal">
                        I’ve checked each dose against the chart and the count <span className="text-status-critical">*</span>
                    </Label>
                </div>
                <InputError message={errors['fu-check']} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="fu-note">
                    Note <span className="text-subtle">(optional)</span>
                </Label>
                <Textarea id="fu-note" rows={2} value={note} placeholder="e.g. Spoke with Priya about booking Mere’s controlled drugs assessment." onChange={(e) => setNote(e.target.value)} />
            </div>
        </>
    );

    if (!canSign)
        return null;
    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                onCloseAutoFocus={(e) => {
                    const el = returnFocus?.();
                    if (el) {
                        e.preventDefault();
                        el.focus();
                    }
                }}
                title="Check doses given without a witness — Kōwhai House"
                description="Review the doses, count those medicines with a witness, then sign off."
                railIcon={ShieldCheck}
                railTitle={FOLLOW_UP.title}
                railSub={`Kōwhai House · by ${FOLLOW_UP.due}`}
                steps={STEPS.map((x, i) => ({ ...x, disabled: i > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(i) => i <= step && setStep(i)}
                pct={Math.round(([step >= 1, covered || Object.keys(lines).length === meds.length, checked].filter(Boolean).length / 3) * 100)}
                pctLabel="Completeness"
                railExtra={
                    <div className="space-y-2 text-[11.5px] text-muted-foreground">
                        <p>
                            <span className="font-semibold text-foreground">Owner</span>
                            <br />
                            {FOLLOW_UP.owner} (house lead)
                        </p>
                        <p>
                            <span className="font-semibold text-foreground">Due</span>
                            <br />
                            {FOLLOW_UP.dueLine}
                        </p>
                    </div>
                }
                footerStart={
                    step > 0 && phase !== 'sending' ? (
                        <Button type="button" variant="ghost" onClick={() => setStep(step - 1)}>
                            <ChevronLeft className="size-4" /> Back
                        </Button>
                    ) : (
                        <Button type="button" variant="outline" onClick={requestClose} disabled={phase === 'sending'}>
                            Cancel
                        </Button>
                    )
                }
                footerEnd={
                    step < 2 ? (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" onClick={save} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                            {phase === 'sending' ? 'Sending…' : 'Sign off follow-up'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title="Follow-up signed off"
                            blurb={
                                <span className="block space-y-2">
                                    <span className="block">Both doses given without a witness are checked and signed off by {persona.name} at {NOW_LABEL}. The follow-up leaves everyone’s list.</span>
                                    {discs.map((d) => (
                                        <span key={d.id} className="block font-medium text-status-critical">
                                            {cdById(d.cdMed).med}: the count didn’t match. A discrepancy has started and is linked to this follow-up.
                                        </span>
                                    ))}
                                </span>
                            }
                            actions={
                                <Button type="button" onClick={onClose} autoFocus>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1040px)"
                maxHeight="min(86vh, 780px)"
            >
                <WizardStepPane>
                    <div ref={bodyRef} className="space-y-4">
                        {step === 0 ? step0 : step === 1 ? step1 : step2}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard this sign-off?"
                description="Nothing has been saved. The follow-up stays open and due by 11:00 pm."
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}
