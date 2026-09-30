/* The count — P07a's redesign of BalanceCheckDialog (pages/emar/_cd-dialogs.tsx).
 * One WizardShell for the shift-change count (every controlled medicine at the
 * house, one witness, one PIN) and for counting a single medicine. A count
 * that differs is counted again first; if it still differs, the discrepancy
 * starts automatically when saved (Stephan, 30 Sep 2026). Real WizardShell,
 * ReviewCard/ReviewRow, WizardSuccessPane, Input, Textarea, Label, ConfirmDialog.
 * Synthetic: nothing is sent. */
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import { AlertOctagon, Check, CheckCircle2, ChevronLeft, ChevronRight, ClipboardCheck, ClipboardList, Loader2, Lock, User, Users } from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { NOW_LABEL } from './clock';
import { HOUSES, PEOPLE, PERSONAS, cdById, qty, staffById, type CdMed } from './data';
import { DISCREPANCY, countBlock, eligibleWitnesses } from './model';
import { useStore, type CountRecord, type Discrepancy } from './store';
import { Notice, PersonMark } from './ui';
import { WitnessField, checkPin, type WitnessValue } from './witness';

export interface CountLine {
    first: string;
    recount: string;
    found: string;
    did: string;
}
export type CountErrors = Record<string, string>;
const blank: CountLine = { first: '', recount: '', found: '', did: '' };

/** Result of one medicine's count against its register balance. */
export function lineResult(l: CountLine, register: number) {
    const n = (v: string) => (v.trim() === '' ? null : Number(v));
    const first = n(l.first);
    const recount = n(l.recount);
    if (first == null || Number.isNaN(first)) return { kind: 'empty' as const };
    if (first === register) return { kind: 'match' as const, final: first };
    if (recount == null || Number.isNaN(recount)) return { kind: 'recount' as const, first };
    if (recount === register) return { kind: 'matchSecond' as const, final: recount, first };
    return { kind: 'differs' as const, final: recount, first };
}
const diffText = (final: number, register: number, m: CdMed) => `${qty(Math.abs(final - register), m)} ${final < register ? 'short' : 'over'}`;

/* ───────────── one medicine's count card (shared with the follow-up) ───────────── */
export function CountRow({ m, register, line, set, errors, idx }: { m: CdMed; register: number; line: CountLine; set: (p: Partial<CountLine>) => void; errors: CountErrors; idx: number }) {
    const r = lineResult(line, register);
    return (
        <section aria-label={`${PEOPLE[m.pid].pref} · ${m.med}`} className="space-y-3 rounded-xl border bg-card p-4" data-count={m.id}>
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="flex items-center gap-3">
                    <PersonMark pid={m.pid} size={34} />
                    <div>
                        <p className="text-[14px] font-semibold">
                            {idx + 1}. {m.med} <span className="font-normal text-muted-foreground">{m.strength}</span>
                        </p>
                        <p className="text-caption">
                            {PEOPLE[m.pid].pref} {PEOPLE[m.pid].surname} · {m.use}
                        </p>
                    </div>
                </div>
                <div className="text-right">
                    <p className="text-caption">The register says</p>
                    <p className="text-section-title tabular-nums">{qty(register, m)}</p>
                </div>
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5" data-field={`c-${m.id}`}>
                    <Label htmlFor={`c-${m.id}`}>
                        You counted <span className="text-status-critical">*</span>
                    </Label>
                    <div className="flex items-center gap-2">
                        <Input id={`c-${m.id}`} type="number" inputMode="decimal" min={0} step={0.5} className="w-32 tabular-nums" value={line.first} aria-invalid={!!errors[`c-${m.id}`]} onChange={(e) => set({ first: e.target.value, recount: '', found: '', did: '' })} />
                        <span className="text-sm text-muted-foreground">{m.plural}</span>
                    </div>
                    <InputError message={errors[`c-${m.id}`]} />
                </div>
                {r.kind !== 'empty' && r.kind !== 'match' ? (
                    <div className="space-y-1.5" data-field={`r-${m.id}`}>
                        <Label htmlFor={`r-${m.id}`}>
                            Count it again <span className="text-status-critical">*</span>
                        </Label>
                        <div className="flex items-center gap-2">
                            <Input id={`r-${m.id}`} type="number" inputMode="decimal" min={0} step={0.5} className="w-32 tabular-nums" value={line.recount} aria-invalid={!!errors[`r-${m.id}`]} onChange={(e) => set({ recount: e.target.value })} />
                            <span className="text-sm text-muted-foreground">{m.plural}</span>
                        </div>
                        <InputError message={errors[`r-${m.id}`]} />
                    </div>
                ) : null}
            </div>
            {r.kind === 'match' ? (
                <p className="flex items-center gap-1.5 text-sm font-medium text-status-success">
                    <CheckCircle2 className="size-4" aria-hidden="true" /> Matches the register
                </p>
            ) : r.kind === 'recount' ? (
                <Notice tone="warning" title={`That’s ${diffText(r.first, register, m)} — count it again`}>
                    Before anything is reported, count once more with your witness watching: check every strip and bottle, the bin and around the cupboard.
                </Notice>
            ) : r.kind === 'matchSecond' ? (
                <p className="flex items-center gap-1.5 text-sm font-medium text-status-success">
                    <CheckCircle2 className="size-4" aria-hidden="true" /> Matches on the second count — nothing more to do. Your first count ({r.first}) is kept with this record.
                </p>
            ) : r.kind === 'differs' ? (
                <div className="space-y-3">
                    <Notice tone="critical" icon={AlertOctagon} title={`Still ${diffText(r.final, register, m)} on the second count`}>
                        When you save, a discrepancy starts for this medicine. {DISCREPANCY.owner} ({DISCREPANCY.ownerRole}) owns it and is told straight away. The register will show what you counted ({qty(r.final, m)}); the difference stays on the discrepancy.
                    </Notice>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5" data-field={`f-${m.id}`}>
                            <Label htmlFor={`f-${m.id}`}>
                                What you found <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea id={`f-${m.id}`} rows={3} value={line.found} placeholder={DISCREPANCY.whatFoundPlaceholder} aria-invalid={!!errors[`f-${m.id}`]} onChange={(e) => set({ found: e.target.value })} />
                            <InputError message={errors[`f-${m.id}`]} />
                        </div>
                        <div className="space-y-1.5" data-field={`a-${m.id}`}>
                            <Label htmlFor={`a-${m.id}`}>
                                What you did straight away <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea id={`a-${m.id}`} rows={3} value={line.did} placeholder={DISCREPANCY.didPlaceholder} aria-invalid={!!errors[`a-${m.id}`]} onChange={(e) => set({ did: e.target.value })} />
                            <InputError message={errors[`a-${m.id}`]} />
                        </div>
                    </div>
                </div>
            ) : null}
        </section>
    );
}

/** Validate the count lines; returns errors keyed by field id. */
export function validateCounts(meds: CdMed[], lines: Record<string, CountLine>, reg: (m: CdMed) => number): CountErrors {
    const e: CountErrors = {};
    meds.forEach((m) => {
        const l = lines[m.id] ?? blank;
        const r = lineResult(l, reg(m));
        if (r.kind === 'empty') e[`c-${m.id}`] = `Enter how many ${m.plural} you counted.`;
        else if (l.first !== '' && Number(l.first) < 0) e[`c-${m.id}`] = 'A count can’t be less than 0.';
        else if (r.kind === 'recount') e[`r-${m.id}`] = 'Count it again before you carry on.';
        else if (r.kind === 'differs') {
            if (!l.found.trim()) e[`f-${m.id}`] = 'Say what you found.';
            if (!l.did.trim()) e[`a-${m.id}`] = 'Say what you did straight away.';
        }
    });
    return e;
}
export function focusFirst(e: Record<string, string>) {
    const k = Object.keys(e)[0];
    if (!k) return;
    window.setTimeout(() => {
        const el = document.getElementById(k === 'witness' ? 'w-witness' : k === 'pin' ? 'w-pin' : k);
        el?.focus();
        el?.scrollIntoView({ block: 'center' });
    }, 60);
}

const STEPS = [
    { key: 'count', label: 'Count', blurb: 'Each controlled medicine', icon: ClipboardCheck },
    { key: 'witness', label: 'Witness', blurb: 'A second person and their PIN', icon: Users },
    { key: 'review', label: 'Review & sign', blurb: 'Check, then save', icon: ClipboardList },
];

export function CountDialog({ medIds, mode, onClose, returnFocus }: { medIds: string[]; mode: 'shift' | 'one'; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const persona = PERSONAS[s.route.persona];
    const house = cdById(medIds[0]).house;
    const meds = medIds.map(cdById);
    const [registerOverride, setRegisterOverride] = useState<Record<string, number>>({});
    const reg = (m: CdMed) => registerOverride[m.id] ?? s.balanceOf(m);
    const [step, setStep] = useState(0);
    const [lines, setLines] = useState<Record<string, CountLine>>({});
    const [witness, setWitness] = useState<WitnessValue>({ id: null, pin: '' });
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [banner, setBanner] = useState<ReactNode>(null);
    const [discard, setDiscard] = useState(false);
    const [result, setResult] = useState<{ discs: Discrepancy[]; recs: Record<string, CountRecord> } | null>(null);
    const conflicted = useRef(false);
    const dirty = Object.values(lines).some((l) => l.first || l.recount) || !!witness.id;
    const block = countBlock(s.route.persona, s.route.scenario, s.clockedIn);
    const noWitness = eligibleWitnesses(s.route.persona, house, s.route.scenario).length === 0;
    const bodyRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        bodyRef.current?.closest('[data-wizard-region="body"]')?.scrollTo?.(0, 0);
    }, [step]);

    const set = (id: string) => (p: Partial<CountLine>) => setLines((x) => ({ ...x, [id]: { ...(x[id] ?? blank), ...p } }));
    const witnessName = witness.id ? staffById(witness.id).name : null;

    function next() {
        if (step === 0) {
            const e = validateCounts(meds, lines, reg);
            setErrors(e);
            if (Object.keys(e).length) return focusFirst(e);
            setBanner(null);
            setStep(1);
        } else if (step === 1) {
            const e: Record<string, string> = {};
            if (!witness.id) e.witness = 'Choose who is witnessing the count.';
            else if (!/^\d{6}$/.test(witness.pin)) e.pin = 'Enter their 6-digit witness PIN.';
            setErrors(e);
            if (Object.keys(e).length) return focusFirst(e);
            setStep(2);
        }
    }
    function save() {
        setPhase('sending');
        setBanner(null);
        window.setTimeout(() => {
            if (s.route.scenario === 'offline') {
                setPhase('edit');
                setBanner(
                    <Notice tone="warning" title="You’re offline — this count wasn’t saved">
                        A witnessed count can’t be kept on this device, because witness PINs are never stored. Your counts are still here: save again when you’re back online.
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
            const cd1 = meds.find((m) => m.id === 'cd1');
            if (s.route.scenario === 'balanceChanged' && cd1 && !conflicted.current) {
                conflicted.current = true;
                setPhase('edit');
                setStep(0);
                setRegisterOverride({ cd1: reg(cd1) - 1 });
                setLines((x) => ({ ...x, cd1: blank }));
                setBanner(
                    <Notice tone="critical" title="The register changed while you were counting — nothing was saved">
                        Jordan Tipene recorded Aroha’s 2:30 pm methylphenidate a moment ago (2:48 pm), so the register now says {qty(reg(cd1) - 1, cd1)}, not {qty(reg(cd1), cd1)}. Count methylphenidate again. Your other counts are kept.
                    </Notice>,
                );
                setErrors({ 'c-cd1': 'Count methylphenidate again.' });
                focusFirst({ 'c-cd1': 'x' });
                return;
            }
            const recs: Record<string, CountRecord> = {};
            const discs: Discrepancy[] = [];
            meds.forEach((m, i) => {
                const r = lineResult(lines[m.id], reg(m));
                if (r.kind === 'empty' || r.kind === 'recount') return;
                recs[m.id] = { at: NOW_LABEL, by: persona.name, witness: witnessName!, result: r.kind === 'differs' ? 'discrepancy' : 'matched', counted: r.final, register: reg(m), firstCount: r.kind === 'matchSecond' || r.kind === 'differs' ? r.first : undefined };
                if (r.kind === 'differs')
                    discs.push({ id: `disc-new-${m.id}`, ref: `CD-2026-0${15 + i}`, cdMed: m.id, startedAt: NOW_LABEL, by: persona.name, witness: witnessName!, register: reg(m), firstCount: r.first, recount: r.final, found: lines[m.id].found, did: lines[m.id].did, owner: DISCREPANCY.owner });
            });
            s.recordCounts(recs, discs);
            setResult({ recs, discs });
            setPhase('done');
        }, 900);
    }
    function requestClose() {
        if (phase === 'sending') return;
        if (phase === 'done' || !dirty) return onClose();
        setDiscard(true);
    }

    const pct = useMemo(() => {
        const items = [...meds.map((m) => ['match', 'matchSecond', 'differs'].includes(lineResult(lines[m.id] ?? blank, reg(m)).kind)), !!witness.id, /^\d{6}$/.test(witness.pin)];
        return Math.round((items.filter(Boolean).length / items.length) * 100);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [lines, witness, registerOverride]);

    const title = mode === 'shift' ? 'Shift-change count' : 'Count one medicine';
    const context = (
        <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
            <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                <Lock className="h-4 w-4 text-primary" />
            </span>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-center gap-2">
                    <span className="text-sm font-medium">{mode === 'shift' ? `3:00 pm shift change · ${HOUSES[house]} · ${meds.length} controlled medicines` : `${PEOPLE[meds[0].pid].pref}’s ${meds[0].med.toLowerCase()} · ${HOUSES[house]}`}</span>
                    <StatusBadge variant="neutral" size="sm">
                        From Controlled checks
                    </StatusBadge>
                </div>
                <p className="mt-0.5 text-xs text-muted-foreground">{mode === 'shift' ? 'Count every controlled medicine at the house together, with one witness. The outgoing and incoming staff usually count together.' : 'A count of this medicine only. It doesn’t replace the shift-change count of the others.'}</p>
            </div>
        </div>
    );

    const step0 = block ? (
        <Notice tone="warning" title={block.title}>
            {block.text} {block.next[0]}
        </Notice>
    ) : (
        <>
            {context}
            {banner}
            {meds.map((m, i) => (
                <CountRow key={m.id} m={m} idx={i} register={reg(m)} line={lines[m.id] ?? blank} set={set(m.id)} errors={errors} />
            ))}
            <p className="text-caption">Count what’s physically in the cupboard, including opened strips and bottles. Liquids: read the level at eye height.</p>
        </>
    );
    const step1 = (
        <>
            <Card className="flex-row flex-wrap items-center gap-2 px-4 py-3 text-sm">
                <User className="size-4 text-muted-foreground" aria-hidden="true" />
                <span className="text-muted-foreground">Counted by</span>
                <strong>{persona.name}</strong>
                <span className="text-muted-foreground">(you) · {persona.role}</span>
            </Card>
            <WitnessField persona={s.route.persona} house={house} scenario={s.route.scenario} value={witness} onChange={(v) => (setWitness(v), setErrors({}))} errors={errors} purpose="the count" />
        </>
    );
    const discLines = meds
        .map((m) => ({ m, r: lineResult(lines[m.id] ?? blank, reg(m)) }))
        .filter((x) => x.r.kind === 'differs');
    const step2 = (
        <>
            {banner}
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={ClipboardCheck} title="Counts" onEdit={() => setStep(0)} span>
                    {meds.map((m) => {
                        const r = lineResult(lines[m.id] ?? blank, reg(m));
                        return (
                            <ReviewRow
                                key={m.id}
                                label={`${m.med} · ${PEOPLE[m.pid].pref}`}
                                value={
                                    r.kind === 'match' ? (
                                        <span>{qty(r.final, m)} · matches</span>
                                    ) : r.kind === 'matchSecond' ? (
                                        <span>{qty(r.final, m)} · matches on the second count (first: {r.first})</span>
                                    ) : r.kind === 'differs' ? (
                                        <span className="text-status-critical">
                                            {qty(r.final, m)} · {diffText(r.final, reg(m), m)} · counted twice
                                        </span>
                                    ) : (
                                        '—'
                                    )
                                }
                            />
                        );
                    })}
                </ReviewCard>
                {discLines.length ? (
                    <ReviewCard icon={AlertOctagon} title={discLines.length === 1 ? 'A discrepancy starts when you save' : `${discLines.length} discrepancies start when you save`} onEdit={() => setStep(0)} span>
                        {discLines.map(({ m }) => (
                            <ReviewRow key={m.id} label={`${m.med} · ${PEOPLE[m.pid].pref}`} value={<span>{lines[m.id].found} · {lines[m.id].did}</span>} />
                        ))}
                        <ReviewRow label="Owner" value={`${DISCREPANCY.owner} (${DISCREPANCY.ownerRole})`} />
                        <ReviewRow label="Who’s told" value="The house lead, straight away, in-app" />
                        <ReviewRow label="Also created" value="A linked incident, as today" />
                    </ReviewCard>
                ) : null}
                <ReviewCard icon={Users} title="Witness" onEdit={() => setStep(1)}>
                    <ReviewRow label="Witnessed by" value={witnessName ?? '—'} />
                    <ReviewRow label="Witness PIN" value={<StatusBadge variant="success" size="sm">Checked when you save</StatusBadge>} />
                </ReviewCard>
                <ReviewCard icon={User} title="Recorded as">
                    <ReviewRow label="Counted by" value={`${persona.name} · ${persona.role}`} />
                    <ReviewRow label="Time" value={`When you save (now ${NOW_LABEL} NZDT)`} />
                    <ReviewRow label="Where" value={HOUSES[house]} />
                </ReviewCard>
            </div>
        </>
    );

    const blockedContinue = step === 0 && block ? block.title : step === 1 && noWitness ? 'Can’t continue: nobody on shift can witness' : null;
    const footerStart =
        step > 0 && phase !== 'sending' ? (
            <Button type="button" variant="ghost" onClick={() => setStep(step - 1)}>
                <ChevronLeft className="size-4" /> Back
            </Button>
        ) : (
            <Button type="button" variant="outline" onClick={requestClose} disabled={phase === 'sending'}>
                Cancel
            </Button>
        );
    const footerEnd = (
        <div className="flex flex-wrap items-center justify-end gap-2">
            {blockedContinue ? (
                <span id="c-cant" className="text-caption">
                    {blockedContinue}
                </span>
            ) : null}
            {step > 0 && phase !== 'sending' ? (
                <Button type="button" variant="outline" onClick={requestClose}>
                    Cancel
                </Button>
            ) : null}
            {step < 2 ? (
                <Button type="button" onClick={next} disabled={!!blockedContinue} aria-describedby={blockedContinue ? 'c-cant' : undefined}>
                    Continue <ChevronRight className="size-4" />
                </Button>
            ) : (
                <Button type="button" onClick={save} disabled={phase === 'sending'}>
                    {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                    {phase === 'sending' ? 'Sending…' : 'Record count'}
                </Button>
            )}
        </div>
    );
    const success =
        phase === 'done' && result ? (
            <WizardSuccessPane
                title={result.discs.length ? 'Count recorded — discrepancy started' : 'Count recorded'}
                blurb={
                    <span className="block space-y-2">
                        <span className="block">
                            {Object.keys(result.recs).length} {Object.keys(result.recs).length === 1 ? 'medicine' : 'medicines'} counted at {NOW_LABEL} by {persona.name}, witnessed by {witnessName}.{' '}
                            {result.discs.length ? '' : 'Everything matches the register.'}
                            {mode === 'shift' ? ' The 3:00 pm shift-change count is done.' : ''}
                        </span>
                        {result.discs.map((d) => {
                            const m = cdById(d.cdMed);
                            return (
                                <span key={d.id} className="block font-medium text-status-critical">
                                    {m.med} for {PEOPLE[m.pid].pref}: {diffText(d.recount, d.register, m)}. A discrepancy has started — {d.owner} owns it and has been told. The register now shows {qty(d.recount, m)}.
                                </span>
                            );
                        })}
                        {(persona.staffId === 'jordan' || witness.id === 'jordan') && s.followUp === 'open' && meds.some((m) => m.id === 'cd1' || m.id === 'cd2') && s.route.scenario !== 'noWitness' ? (
                            <span className="block">This count also covers Jordan Tipene’s follow-up count for the doses given without a witness. Jordan still signs off each dose.</span>
                        ) : null}
                    </span>
                }
                actions={
                    <>
                        {result.discs.length ? (
                            <Button type="button" variant="outline" onClick={() => s.go('/meds/today', { view: 'controlled', open: `disc:${result.discs[0].id}` })}>
                                View the discrepancy
                            </Button>
                        ) : null}
                        <Button type="button" onClick={onClose} autoFocus>
                            Done
                        </Button>
                    </>
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
                title={`${title} — ${HOUSES[house]}`}
                description="Count, then the witness, then review and save."
                railIcon={ClipboardCheck}
                railTitle={title}
                railSub={`${HOUSES[house]} · ${mode === 'shift' ? '3:00 pm' : meds[0].med}`}
                steps={STEPS.map((x, i) => ({ ...x, disabled: i > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(i) => i <= step && setStep(i)}
                pct={pct}
                pctLabel="Completeness"
                railExtra={
                    <div className="space-y-2 text-[11.5px] text-muted-foreground">
                        <p>
                            <span className="font-semibold text-foreground">Counted by</span>
                            <br />
                            {persona.name}
                            <br />
                            {persona.role}
                        </p>
                        <p>
                            <span className="font-semibold text-foreground">Cadence</span>
                            <br />
                            {s.route.scenario === 'cadenceNotSet' ? 'Not configured' : 'Every shift change'}
                        </p>
                    </div>
                }
                footerStart={footerStart}
                footerEnd={footerEnd}
                success={success}
                maxWidth="min(94vw, 1040px)"
                maxHeight="min(86vh, 780px)"
            >
                <WizardStepPane>
                    <div ref={bodyRef} className={cn('space-y-4')}>
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
                title="Discard this count?"
                description={`Nothing has been saved. The counts you entered will be lost${mode === 'shift' ? ', and the 3:00 pm count stays due' : ''}.`}
                confirmText="Discard"
                cancelText="Keep counting"
            />
        </>
    );
}
