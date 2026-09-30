/* P07b’s loss and destruction dialogs — losses as witnessed register entries
 * with an append-only investigation (Main, Q5), and one destruction path with
 * return to the pharmacy by default (Q6), replacing today’s Report loss,
 * investigate/resolve, DestructionDialog and void. Real WizardShell,
 * ReviewCard/ReviewRow, WizardSuccessPane, the Fleet Settings Modal,
 * ConfirmDialog, TilePicker, Select, Input, Textarea, Checkbox, FileDropzone +
 * StagedFileCard, the PKG-01 DateTimeField and PIN-1’s WitnessPinInput. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Building2, Camera, Check, ChevronLeft, ChevronRight, FileWarning, Flame, Loader2, PackageX, ShieldCheck, Siren } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LOCAL } from './clock';
import { DESTROY_REASON, ORG_SETTINGS, PEOPLE, PERSONAS, type DestroyReason, type Destruction, type Entry, type Loss } from './data';
import { cantSaveOffline, focusFirst, Req, restore, stamp, WitnessField, witnessErrors } from './helpers';
import { Modal } from './modal';
import { allDestructions, allLosses, balanceOf, canCloseLoss, canManage, canRecord, destructionState, LOSS_STATE, medOf, medsIn, unitLabel } from './model';
import { useStore } from './store';
import { KV, Notice, TilePicker } from './ui';

const entryOf = (medId: string, kind: Entry['kind'], change: number, before: number, by: string, witnesses: string[], note: string, link?: string): Entry => ({ id: `en-new-${Date.now()}-${Math.round(Math.random() * 1000)}`, medId, at: stamp(), kind, change, before, after: before + change, by, witnesses, note, link });

/* ═════════════ Report a loss (Q5) ═════════════ */
export function ReportLossDialog({ medId, onClose, returnFocus }: { medId?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const meds = medsIn(me.houses);
    const [step, setStep] = useState(0);
    const [id, setId] = useState(medId ?? '');
    const m = id ? medOf(id) : null;
    const [qty, setQty] = useState('');
    const [when, setWhen] = useState(NOW_LOCAL);
    const [circ, setCirc] = useState('');
    const [immediate, setImmediate] = useState('');
    const [police, setPolice] = useState(false);
    const [policeRef, setPoliceRef] = useState('');
    const [regulator, setRegulator] = useState(false);
    const [who, setWho] = useState('');
    const [pin, setPin] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [newId, setNewId] = useState('');
    const steps = [
        { key: 'what', label: 'What’s missing', blurb: 'Medicine and amount', icon: FileWarning },
        { key: 'how', label: 'What happened', blurb: 'And what you’ve done', icon: Siren },
        { key: 'tell', label: 'Who’s been told', blurb: 'Police, Medicines Control', icon: Building2 },
        { key: 'witness', label: 'Witness', blurb: 'At the cupboard', icon: ShieldCheck },
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const dirty = !!(qty || circ || immediate);
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'what') {
            if (!id) x['ls-med'] = 'Choose the medicine.';
            if (!(Number(qty) > 0)) x['ls-qty'] = 'Enter how many are missing.';
            else if (m && Number(qty) > balanceOf(m, s.rt)) x['ls-qty'] = `The register balance is ${balanceOf(m, s.rt)}.`;
        }
        if (k === 'how') {
            if (!circ.trim()) x['ls-circ'] = 'Describe what happened.';
            if (!immediate.trim()) x['ls-imm'] = 'Say what you’ve done so far.';
        }
        if (k === 'tell' && police && !policeRef.trim()) x['ls-ref'] = 'Enter the police event number, or untick it.';
        if (k === 'witness') Object.assign(x, witnessErrors('ls-w', who, pin));
        return x;
    }
    function next() {
        const x = validate(cur);
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        setStep(step + 1);
    }
    function save() {
        if (s.route.scenario === 'offline') return setE({ 'ls-w-who': cantSaveOffline });
        setPhase('sending');
        window.setTimeout(() => {
            const lid = `L-${8 + s.rt.losses.length}`;
            setNewId(lid);
            s.update((rt) => {
                const loss: Loss = { id: lid, medId: id, qty: Number(qty), discovered: stamp(), circumstances: circ.trim(), immediate: immediate.trim(), reportedBy: me.name, witness: who, incident: `INC-${2240 + rt.losses.length}`, police: police ? { informed: true, ref: policeRef.trim(), at: stamp(), by: me.name } : { informed: false }, regulator: regulator ? { informed: true, at: stamp(), by: me.name } : { informed: false }, timeline: [{ at: stamp(), by: me.name, note: `Reported, witnessed by ${who}.` }], status: 'investigating' };
                return { ...rt, losses: [loss, ...rt.losses], entries: [entryOf(id, 'loss', -Number(qty), balanceOf(m!, rt), me.name, [who], circ.trim(), lid), ...rt.entries] };
            });
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        what: (
            <>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="ls-med">
                            Medicine <Req />
                        </Label>
                        <Select value={id || undefined} onValueChange={(v) => (setId(v), setE({}))} disabled={!!medId}>
                            <SelectTrigger id="ls-med" className="w-full" aria-invalid={!!e['ls-med']}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {meds.map((x) => (
                                    <SelectItem key={x.id} value={x.id}>
                                        {x.med} {x.strength} — {PEOPLE[x.pid].pref}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['ls-med']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="ls-qty">
                            How many are missing <Req />
                        </Label>
                        <Input id="ls-qty" value={qty} inputMode="numeric" aria-invalid={!!e['ls-qty']} placeholder={m ? `Balance ${balanceOf(m, s.rt)} ${m.unit}` : ''} onChange={(ev) => (setQty(ev.target.value.replace(/[^\d.]/g, '')), setE({}))} />
                        <InputError message={e['ls-qty']} />
                    </div>
                </div>
                <DateTimeField id="ls-when" label="Discovered" value={when} onChange={setWhen} />
                <Notice tone="info" title="Found a difference at a count?">That starts a discrepancy in Controlled checks, and an unexplained one becomes a loss when it’s resolved. Report a loss here when it’s missing for a known reason — lost on an outing, stolen, dropped where it can’t be recovered.</Notice>
            </>
        ),
        how: (
            <>
                <div className="space-y-1.5">
                    <Label htmlFor="ls-circ">
                        What happened <Req />
                    </Label>
                    <Textarea id="ls-circ" rows={3} value={circ} aria-invalid={!!e['ls-circ']} placeholder="e.g. The day bag was left on the bus; one syringe was in it." onChange={(ev) => (setCirc(ev.target.value), setE({}))} />
                    <InputError message={e['ls-circ']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="ls-imm">
                        What you’ve done so far <Req />
                    </Label>
                    <Textarea id="ls-imm" rows={2} value={immediate} aria-invalid={!!e['ls-imm']} placeholder="e.g. Rang the bus company; told the house lead and Rangi." onChange={(ev) => (setImmediate(ev.target.value), setE({}))} />
                    <InputError message={e['ls-imm']} />
                </div>
            </>
        ),
        tell: (
            <>
                <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                    <Checkbox checked={police} onCheckedChange={(c) => (setPolice(!!c), setE({}))} />
                    Theft is suspected, and the police have been told
                </label>
                {police ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="ls-ref">
                            Police event number <Req />
                        </Label>
                        <Input id="ls-ref" value={policeRef} aria-invalid={!!e['ls-ref']} onChange={(ev) => (setPoliceRef(ev.target.value), setE({}))} />
                        <InputError message={e['ls-ref']} />
                    </div>
                ) : null}
                <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                    <Checkbox checked={regulator} onCheckedChange={(c) => setRegulator(!!c)} />
                    Medicines Control (Ministry of Health) has been told
                </label>
                <p className="text-caption">The manager decides who else is told, and records it before closing the loss. A critical incident opens straight away.</p>
            </>
        ),
        witness: (
            <>
                <p className="text-sm">The loss comes off the register balance, so someone witnesses it at the cupboard.</p>
                {m ? <WitnessField id="ls-w" house={PEOPLE[m.pid].house} recorder={me.name} who={who} pin={pin} onWho={(v) => (setWho(v), setE({}))} onPin={(v) => (setPin(v), setE({}))} errors={e} /> : null}
            </>
        ),
        review: m ? (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={FileWarning} title="What’s missing" onEdit={() => setStep(0)}>
                    <ReviewRow label="Medicine" value={`${m.med} · ${PEOPLE[m.pid].pref}`} />
                    <ReviewRow label="Missing" value={unitLabel(m, Number(qty))} />
                    <ReviewRow label="Balance" value={`${balanceOf(m, s.rt)} → ${balanceOf(m, s.rt) - Number(qty)}`} />
                </ReviewCard>
                <ReviewCard icon={Building2} title="Who’s been told" onEdit={() => setStep(2)}>
                    <ReviewRow label="Police" value={police ? `Yes — ${policeRef}` : 'No'} />
                    <ReviewRow label="Medicines Control" value={regulator ? 'Yes' : 'Not yet'} />
                    <ReviewRow label="Witness" value={who} />
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you save">
                        The loss comes off the register, a critical incident opens, and the loss is open in Losses for the investigation. A manager closes it.
                    </Notice>
                </div>
            </div>
        ) : null,
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={m ? `Report a loss — ${m.med}, ${PEOPLE[m.pid].pref}` : 'Report a loss'}
                description="What’s missing, what happened, who’s been told, a witness, then review."
                railIcon={FileWarning}
                railTitle="Report a loss"
                railSub={m ? `${PEOPLE[m.pid].pref} · ${m.med}` : 'A controlled medicine'}
                steps={steps.map((x, n) => ({ ...x, disabled: n > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(n) => n <= step && setStep(n)}
                pct={Math.round(((step + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
                pctLabel="Completeness"
                footerStart={
                    step > 0 && phase !== 'sending' ? (
                        <Button type="button" variant="ghost" onClick={() => setStep(step - 1)}>
                            <ChevronLeft className="size-4" /> Back
                        </Button>
                    ) : (
                        <Button type="button" variant="outline" onClick={() => (dirty ? setDiscard(true) : onClose())} disabled={phase === 'sending'}>
                            Cancel
                        </Button>
                    )
                }
                footerEnd={
                    cur !== 'review' ? (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" onClick={save} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                            {phase === 'sending' ? 'Saving…' : 'Report the loss'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title="Loss reported"
                            blurb={`${newId} is open in Losses. Add what you find to its investigation; a manager closes it.`}
                            actions={
                                <Button type="button" autoFocus onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(88vh, 800px)"
            >
                <WizardStepPane>
                    <div className="space-y-4">{body[cur]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard this report?"
                description="Nothing has been saved."
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

/* ═════════════ A loss, with its append-only investigation ═════════════ */
export function LossDialog({ id, onClose, onAction }: { id: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const p = s.route.persona;
    const l = allLosses(s.rt).find((x) => x.id === id)!;
    const m = medOf(l.medId);
    return (
        <Modal
            width={900}
            title={`${l.id} — ${m.med}, ${PEOPLE[m.pid].pref}`}
            description={`${unitLabel(m, l.qty)} · discovered ${l.discovered} · ${LOSS_STATE[l.status].label}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {l.status !== 'closed' && canRecord(p) ? (
                        <Button variant="outline" onClick={() => onAction(`lossnote:${l.id}`)}>
                            Add to the investigation
                        </Button>
                    ) : null}
                    {l.status !== 'closed' && canCloseLoss(p) ? <Button onClick={() => onAction(`lossclose:${l.id}`)}>Close the loss</Button> : null}
                </>
            }
        >
            <KV
                rows={[
                    ['What happened', l.circumstances],
                    ['Done straight away', l.immediate],
                    ['Reported', `${l.reportedBy}, witnessed by ${l.witness}${l.fromDiscrepancy ? ` · from discrepancy ${l.fromDiscrepancy}` : ''}`],
                    ['Incident', l.incident],
                    ['Police', toldText(l.police, 'event')],
                    ['Medicines Control', toldText(l.regulator)],
                ]}
            />
            <div className="space-y-2">
                <p className="text-sm font-semibold">Investigation — newest last, never edited</p>
                <ol className="divide-y rounded-lg border">
                    {l.timeline.map((t, n) => (
                        <li key={n} className="px-3 py-2 text-sm">
                            <p>{t.note}</p>
                            <p className="text-caption">
                                {t.at} · {t.by}
                            </p>
                        </li>
                    ))}
                    {l.closed ? (
                        <li className="px-3 py-2 text-sm">
                            <p className="font-semibold">Closed: {l.closed.finding}</p>
                            <p className="text-caption">
                                {l.closed.at} · {l.closed.by}
                            </p>
                        </li>
                    ) : null}
                </ol>
            </div>
        </Modal>
    );
}
/** “Told — event P-4471, Sat 26 Sep, 10:20 am, by Jordan Tipene” or “Not told”. */
function toldText(t: Loss['police'], refLabel?: string): string {
    if (!t?.informed) return 'Not told';
    return ['Told', t.ref && refLabel ? `${refLabel} ${t.ref}` : '', t.at ? `${t.at}${t.by ? `, by ${t.by}` : ''}` : ''].filter(Boolean).join(' · ');
}
export function LossNoteDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const l = allLosses(s.rt).find((x) => x.id === id)!;
    const [note, setNote] = useState('');
    const [ready, setReady] = useState(false);
    const [police, setPolice] = useState(false);
    const [policeRef, setPoliceRef] = useState('');
    const [regulator, setRegulator] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        const x: Record<string, string> = {};
        if (!note.trim()) x['ln-note'] = 'Write what you found or did.';
        if (police && !policeRef.trim()) x['ln-ref'] = 'Enter the police event number, or untick it.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'ln-note': cantSaveOffline });
        const now = stamp();
        s.update((rt) => ({ ...rt, lossPatch: { ...rt.lossPatch, [l.id]: { ...rt.lossPatch[l.id], timeline: [...l.timeline, { at: now, by: me.name, note: note.trim() }], status: ready ? 'awaitingClose' : l.status, ...(police ? { police: { informed: true, ref: policeRef.trim(), at: now, by: me.name } } : {}), ...(regulator ? { regulator: { informed: true, at: now, by: me.name } } : {}) } } }));
        s.toast('success', ready ? 'Added — the loss is ready for a manager to close.' : 'Added to the investigation.');
        onClose();
    }
    return (
        <Modal width={720} title={`Add to the investigation — ${l.id}`} description={`${medOf(l.medId).med} · ${l.timeline.length} notes so far · earlier notes are never changed`} onClose={onClose} onCloseAutoFocus={restore(returnFocus)} footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button onClick={save}>Add it</Button></>}>
            <div className="space-y-1.5">
                <Label htmlFor="ln-note">
                    What you found or did <Req />
                </Label>
                <Textarea id="ln-note" rows={3} value={note} aria-invalid={!!e['ln-note']} placeholder="e.g. Checked the van — not there. Asked Losa at the day programme to check their lost property." onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                <InputError message={e['ln-note']} />
            </div>
            {!l.police?.informed || !l.regulator?.informed ? (
                <div className="space-y-2">
                    <p className="text-sm font-semibold">Told since the report (optional)</p>
                    {!l.police?.informed ? (
                        <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                            <Checkbox checked={police} onCheckedChange={(c) => (setPolice(!!c), setE({}))} />
                            The police have now been told
                        </label>
                    ) : null}
                    {police ? (
                        <div className="space-y-1.5">
                            <Label htmlFor="ln-ref">
                                Police event number <Req />
                            </Label>
                            <Input id="ln-ref" value={policeRef} aria-invalid={!!e['ln-ref']} onChange={(ev) => (setPoliceRef(ev.target.value), setE({}))} />
                            <InputError message={e['ln-ref']} />
                        </div>
                    ) : null}
                    {!l.regulator?.informed ? (
                        <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                            <Checkbox checked={regulator} onCheckedChange={(c) => setRegulator(!!c)} />
                            Medicines Control (Ministry of Health) has now been told
                        </label>
                    ) : null}
                </div>
            ) : null}
            {l.status === 'investigating' ? (
                <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                    <Checkbox checked={ready} onCheckedChange={(c) => setReady(!!c)} />
                    Nothing more to find — ready for a manager to close
                </label>
            ) : null}
        </Modal>
    );
}
export function CloseLossDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const l = allLosses(s.rt).find((x) => x.id === id)!;
    const [finding, setFinding] = useState('');
    const [kind, setKind] = useState('');
    const [told, setTold] = useState(false);
    const [confirm, setConfirm] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    function check() {
        const x: Record<string, string> = {};
        if (!kind) x['lc-kind'] = 'Choose the finding.';
        if (!finding.trim()) x['lc-finding'] = 'Summarise what was found.';
        if (kind === 'Theft' && !l.police?.informed) x['lc-kind'] = 'A theft needs the police told first — add their event number to the investigation.';
        if (!told) x['lc-told'] = 'Confirm you’ve checked who needs to know.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'lc-kind': cantSaveOffline });
        setConfirm(true);
    }
    return (
        <>
            <Modal width={720} title={`Close ${l.id} — ${medOf(l.medId).med}`} description={`${l.timeline.length} investigation notes · incident ${l.incident}`} onClose={onClose} onCloseAutoFocus={restore(returnFocus)} footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button onClick={check}>Close the loss</Button></>}>
                <div className="space-y-2">
                    <Label id="lc-kind-l">
                        Finding <Req />
                    </Label>
                    <div id="lc-kind" tabIndex={-1}>
                        <TilePicker
                            labelledBy="lc-kind-l"
                            value={kind || null}
                            invalid={!!e['lc-kind'] && !kind}
                            onChange={(k) => (setKind(k), setE({}))}
                            tiles={[
                                { key: 'Accidental', label: 'Accidental', description: 'Explained — no one at fault', icon: Check },
                                { key: 'Not explained', label: 'Not explained', description: 'Couldn’t be accounted for', icon: FileWarning },
                                { key: 'Theft', label: 'Theft', description: 'The police are involved', icon: Siren },
                            ]}
                        />
                    </div>
                    {kind === 'Theft' && !l.police?.informed && !e['lc-kind'] ? <p className="text-sm text-muted-foreground">The police aren’t recorded as told yet — add their event number to the investigation before closing.</p> : null}
                    <InputError message={e['lc-kind']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="lc-finding">
                        Summary <Req />
                    </Label>
                    <Textarea id="lc-finding" rows={3} value={finding} aria-invalid={!!e['lc-finding']} placeholder="e.g. Not found after checking with the day programme; handback now signed by both sides." onChange={(ev) => (setFinding(ev.target.value), setE({}))} />
                    <InputError message={e['lc-finding']} />
                </div>
                <div className="space-y-2">
                    <p className="text-sm font-semibold">Who’s been told</p>
                    <KV
                        rows={[
                            ['Police', toldText(l.police, 'event')],
                            ['Medicines Control', toldText(l.regulator)],
                        ]}
                    />
                    <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                        <Checkbox id="lc-told" checked={told} onCheckedChange={(c) => (setTold(!!c), setE({}))} aria-invalid={!!e['lc-told'] || undefined} />
                        I’ve checked who needs to know — anyone not told above doesn’t need to be
                    </label>
                </div>
                <InputError message={e['lc-told']} />
            </Modal>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    s.update((rt) => ({ ...rt, lossPatch: { ...rt.lossPatch, [l.id]: { ...rt.lossPatch[l.id], status: 'closed', closed: { by: me.name, at: stamp(), finding: `${kind} — ${finding.trim()}` } } } }));
                    s.toast('success', `${l.id} is closed. Its incident is closed in Medication errors.`);
                    onClose();
                }}
                title={`Close ${l.id}?`}
                description="The investigation can’t be added to after this. The loss and its notes are kept."
                confirmText="Close the loss"
                cancelText="Keep it open"
            />
        </>
    );
}

/* ═════════════ Return for destruction (one path — Q6) ═════════════ */
export function ReturnDialog({ medId, onClose, returnFocus }: { medId?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const meds = medsIn(me.houses);
    const [step, setStep] = useState(0);
    const [id, setId] = useState(medId ?? '');
    const m = id ? medOf(id) : null;
    const [qty, setQty] = useState('');
    const [reason, setReason] = useState<DestroyReason | ''>('');
    const [method, setMethod] = useState<'return' | 'onsite'>('return');
    const [who, setWho] = useState('');
    const [pin, setPin] = useState('');
    const [who2, setWho2] = useState('');
    const [pin2, setPin2] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const steps = [
        { key: 'what', label: 'What’s going', blurb: 'Medicine, amount, why', icon: PackageX },
        { key: 'how', label: 'How', blurb: 'Back to the pharmacy', icon: Building2 },
        { key: 'witness', label: 'Witness', blurb: method === 'onsite' ? 'Two witnesses' : 'At the cupboard', icon: ShieldCheck },
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const dirty = !!(qty || reason);
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'what') {
            if (!id) x['rt-med'] = 'Choose the medicine.';
            if (!(Number(qty) > 0)) x['rt-qty'] = 'Enter how many.';
            else if (m && Number(qty) > balanceOf(m, s.rt)) x['rt-qty'] = `The register balance is ${balanceOf(m, s.rt)}.`;
            if (!reason) x['rt-reason'] = 'Choose why.';
        }
        if (k === 'witness') {
            Object.assign(x, witnessErrors('rt-w', who, pin));
            if (method === 'onsite') Object.assign(x, witnessErrors('rt-w2', who2, pin2));
        }
        return x;
    }
    function next() {
        const x = validate(cur);
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        setStep(step + 1);
    }
    function save() {
        if (s.route.scenario === 'offline') return setE({ 'rt-w-who': cantSaveOffline });
        setPhase('sending');
        window.setTimeout(() => {
            s.update((rt) => {
                const dsId = `DS-${22 + rt.destructions.length}`;
                const witnesses = method === 'onsite' ? [who, who2] : [who];
                const en = entryOf(id, method === 'onsite' ? 'destroyed' : 'returned', -Number(qty), balanceOf(m!, rt), me.name, witnesses, `${DESTROY_REASON[reason as DestroyReason]}${method === 'return' ? ' — in the returns bag for the pharmacy' : ' — denatured on site'}`, dsId);
                const d: Destruction = { id: dsId, medId: id, qty: Number(qty), reason: reason as DestroyReason, method, at: stamp(), by: me.name, witnesses, entry: en.id, pharmacy: method === 'return' ? 'Kōwhai Pharmacy' : undefined, photo: !!file };
                return { ...rt, destructions: [d, ...rt.destructions], entries: [en, ...rt.entries] };
            });
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        what: (
            <>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="rt-med">
                            Medicine <Req />
                        </Label>
                        <Select value={id || undefined} onValueChange={(v) => (setId(v), setE({}))} disabled={!!medId}>
                            <SelectTrigger id="rt-med" className="w-full" aria-invalid={!!e['rt-med']}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {meds.map((x) => (
                                    <SelectItem key={x.id} value={x.id}>
                                        {x.med} {x.strength} — {PEOPLE[x.pid].pref}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['rt-med']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="rt-qty">
                            How many <Req />
                        </Label>
                        <Input id="rt-qty" value={qty} inputMode="numeric" aria-invalid={!!e['rt-qty']} placeholder={m ? `Balance ${balanceOf(m, s.rt)} ${m.unit}` : ''} onChange={(ev) => (setQty(ev.target.value.replace(/[^\d.]/g, '')), setE({}))} />
                        <InputError message={e['rt-qty']} />
                    </div>
                </div>
                <div className="space-y-2">
                    <Label id="rt-reason-l">
                        Why <Req />
                    </Label>
                    <div id="rt-reason" tabIndex={-1}>
                        <TilePicker labelledBy="rt-reason-l" value={reason || null} invalid={!!e['rt-reason']} onChange={(k) => (setReason(k as DestroyReason), setE({}))} tiles={(Object.keys(DESTROY_REASON) as DestroyReason[]).map((k) => ({ key: k, label: DESTROY_REASON[k], description: k === 'expired' ? 'Past its expiry date' : k === 'stopped' ? 'The prescriber stopped it' : k === 'damaged' ? 'Can’t be given' : k === 'surplus' ? 'More than needed' : 'Their medicines go back', icon: PackageX }))} />
                    </div>
                    <InputError message={e['rt-reason']} />
                </div>
            </>
        ),
        how: (
            <>
                <div className="space-y-2">
                    <Label id="rt-method-l">How is it destroyed?</Label>
                    <TilePicker
                        labelledBy="rt-method-l"
                        value={method}
                        onChange={(k) => setMethod(k as 'return' | 'onsite')}
                        tiles={[
                            { key: 'return', label: 'Return to the pharmacy', description: 'Standard NZ practice — the pharmacist destroys it and signs for it', icon: Building2 },
                            { key: 'onsite', label: 'Denature on site', description: 'Two witnesses', icon: Flame, disabled: ORG_SETTINGS.onSiteDestruction ? null : 'Your organisation doesn’t allow destruction on site' },
                        ]}
                    />
                </div>
                <div className="space-y-1.5">
                    <Label id="rt-photo-l">Photo of what’s going (optional)</Label>
                    {file ? <StagedFileCard file={file} onRemove={() => setFile(null)} /> : <FileDropzone id="rt-photo" aria-labelledby="rt-photo-l" multiple={false} accept="image/*" hint="The pack and its label, sealed in the returns bag" onFiles={(x) => setFile(x[0] ?? null)} />}
                </div>
                {method === 'return' ? <p className="text-caption">It comes off the register now and waits in Destructions until the pharmacist signs for it — record their name and registration then.</p> : null}
            </>
        ),
        witness: m ? (
            <>
                <WitnessField id="rt-w" house={PEOPLE[m.pid].house} recorder={me.name} who={who} pin={pin} onWho={(v) => (setWho(v), setE({}))} onPin={(v) => (setPin(v), setE({}))} errors={e} />
                {method === 'onsite' ? <WitnessField id="rt-w2" label="Second witness" house={PEOPLE[m.pid].house} recorder={me.name} exclude={[who]} who={who2} pin={pin2} onWho={(v) => (setWho2(v), setE({}))} onPin={(v) => (setPin2(v), setE({}))} errors={e} /> : null}
            </>
        ) : null,
        review: m ? (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={PackageX} title="What’s going" onEdit={() => setStep(0)}>
                    <ReviewRow label="Medicine" value={`${m.med} · ${PEOPLE[m.pid].pref}`} />
                    <ReviewRow label="Amount" value={unitLabel(m, Number(qty))} />
                    <ReviewRow label="Why" value={reason ? DESTROY_REASON[reason] : '—'} />
                </ReviewCard>
                <ReviewCard icon={Building2} title="How" onEdit={() => setStep(1)}>
                    <ReviewRow label="Method" value={method === 'return' ? 'Return to Kōwhai Pharmacy' : 'Denature on site'} />
                    <ReviewRow label="Witness" value={method === 'onsite' ? `${who} and ${who2}` : who} />
                    <ReviewRow label="Photo" value={file ? file.name : 'None'} />
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you save">
                        {unitLabel(m, Number(qty))} comes off the register ({balanceOf(m, s.rt)} → {balanceOf(m, s.rt) - Number(qty)}), recorded with {method === 'onsite' ? 'both witnesses' : 'the witness'}. {method === 'return' ? 'It waits in Destructions until the pharmacist signs for it.' : ''}
                    </Notice>
                </div>
            </div>
        ) : null,
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={m ? `Return for destruction — ${m.med}, ${PEOPLE[m.pid].pref}` : 'Return for destruction'}
                description="What’s going, how, a witness, then review."
                railIcon={PackageX}
                railTitle="Return for destruction"
                railSub={m ? `${PEOPLE[m.pid].pref} · ${m.med}` : 'A controlled medicine'}
                steps={steps.map((x, n) => ({ ...x, disabled: n > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(n) => n <= step && setStep(n)}
                pct={Math.round(((step + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
                pctLabel="Completeness"
                footerStart={
                    step > 0 && phase !== 'sending' ? (
                        <Button type="button" variant="ghost" onClick={() => setStep(step - 1)}>
                            <ChevronLeft className="size-4" /> Back
                        </Button>
                    ) : (
                        <Button type="button" variant="outline" onClick={() => (dirty ? setDiscard(true) : onClose())} disabled={phase === 'sending'}>
                            Cancel
                        </Button>
                    )
                }
                footerEnd={
                    cur !== 'review' ? (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" onClick={save} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                            {phase === 'sending' ? 'Saving…' : 'Record it'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title="Recorded"
                            blurb={method === 'return' ? 'It’s in Destructions, waiting for the pharmacist’s receipt.' : 'Destroyed on site, with both witnesses on the register.'}
                            actions={
                                <Button type="button" autoFocus onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(88vh, 800px)"
            >
                <WizardStepPane>
                    <div className="space-y-4">{body[cur]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard this?"
                description="Nothing has been saved."
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

/* ═════════════ The pharmacist’s receipt (destruction sign-off) ═════════════ */
export function ReceiptDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const d = allDestructions(s.rt).find((x) => x.id === id)!;
    const m = medOf(d.medId);
    const [name, setName] = useState('');
    const [reg, setReg] = useState('');
    const [at, setAt] = useState(NOW_LOCAL);
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        const x: Record<string, string> = {};
        if (!name.trim()) x['rc-name'] = 'Enter the pharmacist’s name.';
        if (!reg.trim()) x['rc-reg'] = 'Enter their registration number (from the Pharmacy Council register).';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'rc-name': cantSaveOffline });
        s.update((rt) => ({ ...rt, destructionPatch: { ...rt.destructionPatch, [d.id]: { ...rt.destructionPatch[d.id], received: { pharmacist: name.trim(), registration: reg.trim(), at: stamp(), recordedBy: me.name } } } }));
        s.toast('success', `${d.id} is received by the pharmacy — the destruction is complete.`);
        onClose();
    }
    return (
        <Modal width={720} title={`The pharmacist’s receipt — ${d.id}`} description={`${unitLabel(m, d.qty)} of ${m.med} (${PEOPLE[m.pid].pref}) · returned ${d.at} to ${d.pharmacy}`} onClose={onClose} onCloseAutoFocus={restore(returnFocus)} footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button onClick={save}>Record it</Button></>}>
            <p className="text-sm">From the pharmacy’s signed returns receipt.</p>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="rc-name">
                        Pharmacist <Req />
                    </Label>
                    <Input id="rc-name" value={name} aria-invalid={!!e['rc-name']} onChange={(ev) => (setName(ev.target.value), setE({}))} />
                    <InputError message={e['rc-name']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="rc-reg">
                        Registration number <Req />
                    </Label>
                    <Input id="rc-reg" value={reg} aria-invalid={!!e['rc-reg']} onChange={(ev) => (setReg(ev.target.value), setE({}))} />
                    <InputError message={e['rc-reg']} />
                </div>
            </div>
            <DateTimeField id="rc-at" label="Received by the pharmacy" value={at} onChange={setAt} />
        </Modal>
    );
}

/* ═════════════ A destruction (read-only) and voiding it (reverses the entry — Q6) ═════════════ */
export function DestructionDialog({ id, onClose, onAction }: { id: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const p = s.route.persona;
    const d = allDestructions(s.rt).find((x) => x.id === id)!;
    const m = medOf(d.medId);
    return (
        <Modal
            width={720}
            title={`${d.id} — ${m.med}, ${PEOPLE[m.pid].pref}`}
            description={`${unitLabel(m, d.qty)} · ${DESTROY_REASON[d.reason]} · ${destructionState(d).label}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {!d.voided && canManage(p) ? (
                        <Button variant="outline" onClick={() => onAction(`voiddest:${d.id}`)}>
                            Void it
                        </Button>
                    ) : null}
                    {!d.voided && d.method === 'return' && !d.received && canManage(p) ? <Button onClick={() => onAction(`receipt:${d.id}`)}>Record the receipt</Button> : null}
                </>
            }
        >
            <KV
                rows={[
                    ['How', d.method === 'return' ? `Returned to ${d.pharmacy}` : 'Destroyed on site'],
                    ['Recorded', `${d.at} by ${d.by}`],
                    ['Witnessed by', d.witnesses.join(' and ')],
                    ['Pharmacist', d.received ? `${d.received.pharmacist} · reg. ${d.received.registration} · ${d.received.at}` : d.method === 'return' ? 'Not yet signed for' : '—'],
                    ['Photo', d.photo ? 'Yes — stored privately' : 'None'],
                    ...(d.voided ? ([['Voided', `${d.voided.at} by ${d.voided.by}, witnessed by ${d.voided.witness}: “${d.voided.reason}”`]] as [string, string][]) : []),
                ]}
            />
        </Modal>
    );
}
export function VoidDestructionDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const d = allDestructions(s.rt).find((x) => x.id === id)!;
    const m = medOf(d.medId);
    const [reason, setReason] = useState('');
    const [who, setWho] = useState('');
    const [pin, setPin] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    function check() {
        const x: Record<string, string> = {};
        if (!reason.trim()) x['vd-reason'] = 'Say what was wrong.';
        Object.assign(x, witnessErrors('vd-w', who, pin));
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'vd-reason': cantSaveOffline });
        setConfirm(true);
    }
    return (
        <>
            <Modal width={720} title={`Void ${d.id} — ${m.med}, ${PEOPLE[m.pid].pref}`} description={`${unitLabel(m, d.qty)} · ${d.at} · recorded by ${d.by}`} onClose={onClose} onCloseAutoFocus={restore(returnFocus)} footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button variant="destructive" onClick={check}>Void it</Button></>}>
                <div className="space-y-1.5">
                    <Label htmlFor="vd-reason">
                        What was wrong <Req />
                    </Label>
                    <Textarea id="vd-reason" rows={2} value={reason} aria-invalid={!!e['vd-reason']} placeholder="e.g. Recorded against the wrong medicine — the returns bag held Grace’s clonazepam." onChange={(ev) => (setReason(ev.target.value), setE({}))} />
                    <InputError message={e['vd-reason']} />
                </div>
                <WitnessField id="vd-w" house={PEOPLE[m.pid].house} recorder={me.name} exclude={[d.by]} who={who} pin={pin} onWho={(v) => (setWho(v), setE({}))} onPin={(v) => (setPin(v), setE({}))} errors={e} />
                <Notice tone="info" icon={Camera} title="The register entry is reversed">
                    The “{d.method === 'return' ? 'Returned to the pharmacy' : 'Destroyed on site'}” entry is voided and {unitLabel(m, d.qty)} comes back onto the balance. If the medicine really went, record it again correctly.
                </Notice>
            </Modal>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    s.update((rt) => ({ ...rt, destructionPatch: { ...rt.destructionPatch, [d.id]: { ...rt.destructionPatch[d.id], voided: { by: me.name, witness: who, at: stamp(), reason: reason.trim() } } }, voids: { ...rt.voids, [d.entry]: { by: me.name, witness: who, at: stamp(), reason: `${d.id} voided — ${reason.trim()}` } } }));
                    s.toast('success', `${d.id} is voided and its register entry reversed. Both stay visible.`);
                    onClose();
                }}
                title={`Void ${d.id}?`}
                description={`${unitLabel(m, d.qty)} goes back onto the ${m.med} balance. The destruction and its entry stay in the register, struck through.`}
                confirmText="Void it"
                cancelText="Keep it"
            />
        </>
    );
}
