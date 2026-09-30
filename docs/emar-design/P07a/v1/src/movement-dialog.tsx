/* Record a movement — P07a's frontline redesign of RecordCdEntryDialog
 * (pages/emar/_cd-dialogs.tsx). Frontline staff record only medicines going
 * out with the person and coming back (today's "Transfer out / Transfer in").
 * Doses update the register from the dose record (P01); counts use Count;
 * receipts, disposals and adjustments stay with the house lead in the
 * controlled register (P06 / P07b). Real WizardShell, Select, Input,
 * DateTimeField (PKG-01), ReviewCard, WizardSuccessPane, ConfirmDialog. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { ArrowDownLeft, ArrowUpRight, Check, ChevronLeft, ChevronRight, ClipboardList, Loader2, Lock, Package, User, Users } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { NOW_LABEL, NOW_LOCAL, localToMinutesToday, NOW_MIN, labelOf } from './clock';
import { CD_MEDS, HOUSES, PEOPLE, PERSONAS, cdById, qty, staffById } from './data';
import { countBlock, eligibleWitnesses } from './model';
import { useStore } from './store';
import { Notice, TilePicker } from './ui';
import { WitnessField, checkPin, type WitnessValue } from './witness';
import { focusFirst } from './count-dialog';

const PLACES = ['Day programme', 'Whānau or family', 'Respite', 'Hospital', 'Another house'];
const STEPS = [
    { key: 'what', label: 'What’s moving', blurb: 'Medicine, amount, who took it', icon: Package },
    { key: 'witness', label: 'Balance & witness', blurb: 'Count what’s left, with a witness', icon: Users },
    { key: 'review', label: 'Review & sign', blurb: 'Check, then save', icon: ClipboardList },
];

export function MovementDialog({ lockedId, onClose, returnFocus }: { lockedId: string | null; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const persona = PERSONAS[s.route.persona];
    const meds = CD_MEDS.filter((m) => m.house === 'kowhai');
    const [step, setStep] = useState(0);
    const [f, setF] = useState({ med: lockedId ?? '', kind: '' as '' | 'out' | 'in', amount: '', place: '', person: '', when: NOW_LOCAL, left: '' });
    const [witness, setWitness] = useState<WitnessValue>({ id: null, pin: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [banner, setBanner] = useState<ReactNode>(null);
    const [discard, setDiscard] = useState(false);
    const bodyRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        bodyRef.current?.closest('[data-wizard-region="body"]')?.scrollTo?.(0, 0);
    }, [step]);
    const m = f.med ? cdById(f.med) : null;
    const before = m ? s.balanceOf(m) : 0;
    const n = Number(f.amount);
    const after = f.kind === 'out' ? before - n : before + n;
    const set = (p: Partial<typeof f>) => (setF((x) => ({ ...x, ...p })), setErrors({}));
    const block = countBlock(s.route.persona, s.route.scenario, s.clockedIn);
    const noWitness = eligibleWitnesses(s.route.persona, 'kowhai', s.route.scenario).length === 0;
    const dirty = !!(f.kind || f.amount || f.person || witness.id);
    const witnessName = witness.id ? staffById(witness.id).name : null;

    function next() {
        const e: Record<string, string> = {};
        if (step === 0) {
            if (!f.med) e['mv-med'] = 'Choose the medicine.';
            if (!f.kind) e['mv-kind'] = 'Choose whether it’s going out or coming back.';
            if (!f.amount || !(n > 0)) e['mv-amount'] = 'Enter how many.';
            else if (f.kind === 'out' && m && n > before) e['mv-amount'] = `You can’t send out more than the register holds (${qty(before, m)}).`;
            if (!f.place) e['mv-place'] = f.kind === 'in' ? 'Choose where it came from.' : 'Choose where it’s going.';
            if (!f.person.trim()) e['mv-person'] = f.kind === 'in' ? 'Say who brought it back.' : 'Say who it was handed to.';
            const t = localToMinutesToday(f.when);
            if (t == null) e['mv-when'] = 'Enter the date and time.';
            else if (t > NOW_MIN) e['mv-when'] = `It can’t be in the future (now ${NOW_LABEL}).`;
        } else if (step === 1) {
            if (f.left.trim() === '') e['mv-left'] = 'Count what’s left and enter it.';
            else if (Number(f.left) !== after) e['mv-left'] = `That doesn’t match what should be left (${m ? qty(after, m) : after}). Check the amount and count again.`;
            if (!witness.id) e.witness = 'Choose who is witnessing.';
            else if (!/^\d{6}$/.test(witness.pin)) e.pin = 'Enter their 6-digit PIN.';
        }
        setErrors(e);
        if (Object.keys(e).length) return focusFirst(e);
        setStep(step + 1);
    }
    function save() {
        setPhase('sending');
        setBanner(null);
        window.setTimeout(() => {
            if (s.route.scenario === 'offline') {
                setPhase('edit');
                setBanner(
                    <Notice tone="warning" title="You’re offline — this movement wasn’t saved">
                        A witnessed movement can’t be kept on this device, because witness PINs are never stored. What you entered is still here: save again when you’re back online.
                    </Notice>,
                );
                return;
            }
            const pinErr = checkPin(witnessName!, witness.pin);
            if (pinErr) {
                setPhase('edit');
                setStep(1);
                setWitness((w) => ({ ...w, pin: '' }));
                setErrors({ pin: pinErr });
                focusFirst({ pin: pinErr });
                return;
            }
            const t = localToMinutesToday(f.when) ?? NOW_MIN;
            s.addMovement({ id: `mv-${Date.now()}`, cdMed: m!.id, at: labelOf(t), day: 'Today', kind: f.kind as 'out' | 'in', change: f.kind === 'out' ? -n : n, after, by: persona.name, witness: witnessName, detail: `${f.kind === 'out' ? 'Went out with' : 'Came back with'} ${PEOPLE[m!.pid].pref} · ${f.place.toLowerCase()} · ${f.kind === 'out' ? 'handed to' : 'brought by'} ${f.person.trim()}` });
            setPhase('done');
        }, 900);
    }
    function requestClose() {
        if (phase === 'sending') return;
        if (phase === 'done' || !dirty) return onClose();
        setDiscard(true);
    }

    const locked = lockedId ? cdById(lockedId) : null;
    const step0 = block ? (
        <Notice tone="warning" title={block.title}>
            {block.text} {block.next[0]}
        </Notice>
    ) : (
        <>
            {locked ? (
                <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
                    <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                        <Lock className="h-4 w-4 text-primary" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="text-sm font-medium">
                                {PEOPLE[locked.pid].pref} · {locked.med} {locked.strength}
                            </span>
                            <StatusBadge variant="neutral" size="sm">
                                From Controlled checks
                            </StatusBadge>
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">Locked from the row you opened. The register says {qty(before, locked)}.</p>
                    </div>
                </div>
            ) : (
                <div className="space-y-1.5" data-field="mv-med">
                    <Label htmlFor="mv-med">
                        Medicine <span className="text-status-critical">*</span>
                    </Label>
                    <Select value={f.med || undefined} onValueChange={(v) => set({ med: v })}>
                        <SelectTrigger id="mv-med" className="w-full" aria-invalid={!!errors['mv-med']}>
                            <SelectValue placeholder="Choose a controlled medicine at Kōwhai House" />
                        </SelectTrigger>
                        <SelectContent>
                            {meds.map((x) => (
                                <SelectItem key={x.id} value={x.id}>
                                    {x.med} {x.strength} · {PEOPLE[x.pid].pref} · register {qty(s.balanceOf(x), x)}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors['mv-med']} />
                </div>
            )}
            <div className="space-y-2" data-field="mv-kind">
                <Label id="mv-kind-l">
                    What’s happening? <span className="text-status-critical">*</span>
                </Label>
                <div id="mv-kind" tabIndex={-1}>
                    <TilePicker
                        labelledBy="mv-kind-l"
                        value={f.kind || null}
                        invalid={!!errors['mv-kind']}
                        onChange={(k) => set({ kind: k as 'out' | 'in', place: '' })}
                        tiles={[
                            { key: 'out', label: 'Going out with the person', description: 'A day out, a whānau visit, respite or hospital', icon: ArrowUpRight },
                            { key: 'in', label: 'Coming back or arriving', description: 'Back from leave, or arriving from another house', icon: ArrowDownLeft },
                        ]}
                    />
                </div>
                <InputError message={errors['mv-kind']} />
            </div>
            <Notice tone="neutral" title="Not recorded here">
                Doses — the register updates when the dose is recorded. Counts — use Count. Pharmacy deliveries, returns to the pharmacy and destructions — the house lead records these in the controlled register.
            </Notice>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5" data-field="mv-amount">
                    <Label htmlFor="mv-amount">
                        How many <span className="text-status-critical">*</span>
                    </Label>
                    <div className="flex items-center gap-2">
                        <Input id="mv-amount" type="number" inputMode="decimal" min={0} step={0.5} className="w-32 tabular-nums" value={f.amount} aria-invalid={!!errors['mv-amount']} onChange={(e) => set({ amount: e.target.value })} />
                        <span className="text-sm text-muted-foreground">{m?.plural ?? 'tablets or capsules'}</span>
                    </div>
                    <InputError message={errors['mv-amount']} />
                </div>
                <div className="space-y-1.5" data-field="mv-place">
                    <Label htmlFor="mv-place">
                        {f.kind === 'in' ? 'Where from' : 'Where to'} <span className="text-status-critical">*</span>
                    </Label>
                    <Select value={f.place || undefined} onValueChange={(v) => set({ place: v })}>
                        <SelectTrigger id="mv-place" className="w-full" aria-invalid={!!errors['mv-place']}>
                            <SelectValue placeholder="Choose" />
                        </SelectTrigger>
                        <SelectContent>
                            {PLACES.map((p) => (
                                <SelectItem key={p} value={p}>
                                    {p}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={errors['mv-place']} />
                </div>
                <div className="space-y-1.5" data-field="mv-person">
                    <Label htmlFor="mv-person">
                        {f.kind === 'in' ? 'Brought back by' : 'Handed to'} <span className="text-status-critical">*</span>
                    </Label>
                    <Input id="mv-person" value={f.person} placeholder="e.g. Hine Ngata (Aroha’s mother)" aria-invalid={!!errors['mv-person']} onChange={(e) => set({ person: e.target.value })} />
                    <InputError message={errors['mv-person']} />
                </div>
            </div>
            <div data-field="mv-when">
                <DateTimeField id="mv-when" label="When" value={f.when} onChange={(v) => set({ when: v })} error={errors['mv-when']} hint="When it left or arrived. Not in the future." />
            </div>
        </>
    );
    const step1 = (
        <>
            {m ? (
                <section className="space-y-3 rounded-xl border bg-card p-4" aria-label="Balance">
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div>
                            <p className="text-caption">The register says</p>
                            <p className="text-section-title tabular-nums">{qty(before, m)}</p>
                        </div>
                        <div>
                            <p className="text-caption">{f.kind === 'out' ? 'Going out' : 'Coming back'}</p>
                            <p className="text-section-title tabular-nums">
                                {f.kind === 'out' ? '−' : '+'}
                                {n || 0}
                            </p>
                        </div>
                        <div>
                            <p className="text-caption">Should be left</p>
                            <p className="text-section-title tabular-nums">{qty(after, m)}</p>
                        </div>
                    </div>
                    <div className="space-y-1.5" data-field="mv-left">
                        <Label htmlFor="mv-left">
                            Count what’s left in the cupboard <span className="text-status-critical">*</span>
                        </Label>
                        <div className="flex items-center gap-2">
                            <Input id="mv-left" type="number" inputMode="decimal" min={0} step={0.5} className="w-32 tabular-nums" value={f.left} aria-invalid={!!errors['mv-left']} onChange={(e) => set({ left: e.target.value })} />
                            <span className="text-sm text-muted-foreground">{m.plural}</span>
                        </div>
                        <InputError message={errors['mv-left']} />
                        {errors['mv-left'] && f.left !== '' ? <p className="text-caption">If it still doesn’t match, cancel this and use Count instead — a count that differs starts a discrepancy.</p> : null}
                    </div>
                </section>
            ) : null}
            <WitnessField persona={s.route.persona} house="kowhai" scenario={s.route.scenario} value={witness} onChange={(v) => (setWitness(v), setErrors({}))} errors={errors} purpose="the medicine being handed over" />
        </>
    );
    const step2 = m ? (
        <>
            {banner}
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={Package} title="Movement" onEdit={() => setStep(0)}>
                    <ReviewRow label="Medicine" value={`${m.med} ${m.strength} · ${PEOPLE[m.pid].pref}`} />
                    <ReviewRow label="What" value={f.kind === 'out' ? `${qty(n, m)} going out` : `${qty(n, m)} coming back`} />
                    <ReviewRow label={f.kind === 'in' ? 'From' : 'To'} value={f.place} />
                    <ReviewRow label={f.kind === 'in' ? 'Brought back by' : 'Handed to'} value={f.person} />
                    <ReviewRow label="When" value={`${labelOf(localToMinutesToday(f.when) ?? NOW_MIN)} today (NZDT)`} />
                </ReviewCard>
                <ReviewCard icon={Users} title="Balance & witness" onEdit={() => setStep(1)}>
                    <ReviewRow label="Register" value={`${qty(before, m)} → ${qty(after, m)}`} />
                    <ReviewRow label="Counted left" value={`${f.left} · matches`} />
                    <ReviewRow label="Witnessed by" value={witnessName} />
                    <ReviewRow label="Witness PIN" value={<StatusBadge variant="success" size="sm">Checked when you save</StatusBadge>} />
                </ReviewCard>
                <ReviewCard icon={User} title="Recorded as" span>
                    <ReviewRow label="Recorded by" value={`${persona.name} · ${persona.role}`} />
                    <ReviewRow label="Where" value={HOUSES.kowhai} />
                </ReviewCard>
            </div>
        </>
    ) : null;

    const blockedContinue = step === 0 && block ? block.title : step === 1 && noWitness ? 'Can’t continue: nobody on shift can witness' : null;
    const success =
        phase === 'done' && m ? (
            <WizardSuccessPane
                title="Movement recorded"
                blurb={
                    <>
                        {qty(n, m)} of {m.med.toLowerCase()} {f.kind === 'out' ? `went out with ${PEOPLE[m.pid].pref} (${f.place.toLowerCase()}), handed to ${f.person}` : `came back with ${PEOPLE[m.pid].pref} (${f.place.toLowerCase()}), brought by ${f.person}`}. Witnessed by {witnessName}. The register now says {qty(after, m)}.
                    </>
                }
                actions={
                    <Button type="button" onClick={onClose} autoFocus>
                        Done
                    </Button>
                }
            />
        ) : undefined;
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
                title="Record a movement — Kōwhai House"
                description="What’s moving, then the balance and witness, then review and save."
                railIcon={Package}
                railTitle="Record a movement"
                railSub={m ? `${PEOPLE[m.pid].pref} · ${m.med}` : 'Kōwhai House'}
                steps={STEPS.map((x, i) => ({ ...x, disabled: i > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(i) => i <= step && setStep(i)}
                pct={Math.round(([f.med, f.kind, f.amount, f.place, f.person, f.left, witness.id, witness.pin.length === 6].filter(Boolean).length / 8) * 100)}
                pctLabel="Completeness"
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
                    <div className="flex flex-wrap items-center justify-end gap-2">
                        {blockedContinue ? <span className="text-caption">{blockedContinue}</span> : null}
                        {step < 2 ? (
                            <Button type="button" onClick={next} disabled={!!blockedContinue}>
                                Continue <ChevronRight className="size-4" />
                            </Button>
                        ) : (
                            <Button type="button" onClick={save} disabled={phase === 'sending'}>
                                {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                                {phase === 'sending' ? 'Sending…' : 'Record movement'}
                            </Button>
                        )}
                    </div>
                }
                success={success}
                maxWidth="min(94vw, 1000px)"
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
                title="Discard this movement?"
                description="Nothing has been saved. What you entered will be lost and the register stays as it is."
                confirmText="Discard"
                cancelText="Keep recording"
            />
        </>
    );
}
