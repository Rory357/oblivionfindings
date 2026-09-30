/* P07b’s register dialogs — the medicine’s register, void-and-correct (Q2),
 * the NZ class (Q9), breakage or spillage (Q3), the discrepancy and its
 * resolution (Q4, replacing ResolveDiscrepancyDialog). Real WizardShell,
 * ReviewCard/ReviewRow, WizardSuccessPane, the Fleet Settings Modal,
 * ConfirmDialog, TilePicker, Select, Input, Textarea, Checkbox and PIN-1’s
 * WitnessPinInput. */
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { ArrowUpRight, BookOpen, Check, ChevronLeft, ChevronRight, Copy, FileWarning, Hash, Loader2, PenLine, RefreshCw, Scale, Search, ShieldCheck, Undo2, UserX } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { ENTRY_LABEL, PEOPLE, PERSONAS, type Entry, type Loss, type NzClass, type Outcome } from './data';
import { cantSaveOffline, focusFirst, Req, restore, stamp, WitnessField, witnessErrors } from './helpers';
import { Modal } from './modal';
import { allDiscrepancies, allEntries, balanceOf, canManage, classOf, entriesOf, medOf, OUTCOME_LABEL, signed, unitLabel, whyCantResolve } from './model';
import { EntryTable } from './pages/register';
import { useStore } from './store';
import { ClassChip, KV, Notice, TilePicker } from './ui';

const newEntry = (medId: string, kind: Entry['kind'], change: number, before: number, by: string, witnesses: string[], note?: string, extra: Partial<Entry> = {}): Entry => ({ id: `en-new-${Date.now()}-${Math.round(Math.random() * 1000)}`, medId, at: stamp(), kind, change, before, after: before + change, by, witnesses, note, ...extra });

/* ═════════════ The medicine’s register: a viewer ═════════════ */
export function MedDialog({ id, onClose, onAction }: { id: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const p = s.route.persona;
    const m = medOf(id);
    const [sec, setSec] = useState(0);
    const entries = entriesOf(m, s.rt);
    const SECS = [
        { key: 'now', label: 'This medicine', blurb: unitLabel(m, balanceOf(m, s.rt)), icon: ShieldCheck },
        { key: 'entries', label: 'Entries', blurb: `${entries.length} kept · ${entries.filter((e) => e.voided).length} voided`, icon: BookOpen },
    ];
    const body: ReactNode[] = [
        <div key="now" className="space-y-4">
            <KV
                rows={[
                    ['Person', `${PEOPLE[m.pid].legal} (“${PEOPLE[m.pid].pref}”)`],
                    ['Medicine', `${m.med} ${m.strength}`],
                    ['Class', <ClassChip key="c" cls={classOf(m, s.rt)} legacy={m.legacySchedule} />],
                    ['Balance', unitLabel(m, balanceOf(m, s.rt))],
                    ['Last count', m.lastCount],
                ]}
            />
            <p className="text-caption">Entries are never edited or deleted. A wrong one is voided with a reason and a witness PIN, stays visible struck through, and a correcting entry follows.</p>
        </div>,
        <EntryTable key="entries" entries={entries} compact />,
    ];
    return (
        <WizardShell
            open
            onClose={onClose}
            title={`${m.med} — ${PEOPLE[m.pid].pref}`}
            description="The medicine’s controlled register."
            railIcon={ShieldCheck}
            railTitle={`${m.med} ${m.strength}`}
            railSub={`${PEOPLE[m.pid].pref} ${PEOPLE[m.pid].surname} · ${unitLabel(m, balanceOf(m, s.rt))}`}
            steps={SECS}
            stepIndex={sec}
            onStepClick={setSec}
            headerLabel={SECS[sec].label}
            sequential={false}
            pct={null}
            footerStart={
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                canManage(p) ? (
                    <Button type="button" variant="outline" onClick={() => onAction(`class:${m.id}`)}>
                        {classOf(m, s.rt) ? 'Change the class' : 'Set the class'}
                    </Button>
                ) : undefined
            }
            maxWidth="min(96vw, 1180px)"
            maxHeight="min(88vh, 800px)"
        >
            <WizardStepPane>
                <div className="space-y-4">{body[sec]}</div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ═════════════ Void an entry, and correct it (Q2) ═════════════ */
const VOID_REASONS = [
    { key: 'Wrong amount', label: 'Wrong amount', description: 'Record the right amount as a correcting entry', icon: Hash },
    { key: 'Wrong medicine or person', label: 'Wrong medicine or person', description: 'Record it against the right one', icon: UserX },
    { key: 'Recorded twice', label: 'Recorded twice', description: 'No correcting entry needed', icon: Copy },
    { key: 'Other', label: 'Other', description: 'Say what was wrong', icon: PenLine },
];
export function VoidEntryDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const e0 = allEntries(s.rt).find((x) => x.id === id)!;
    const m = medOf(e0.medId);
    const [reason, setReason] = useState('');
    const [note, setNote] = useState('');
    const [correct, setCorrect] = useState(true);
    const [amount, setAmount] = useState('');
    const [who, setWho] = useState('');
    const [pin, setPin] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    const needsCorrection = reason !== 'Recorded twice' && reason !== '' && correct;
    function check() {
        const x: Record<string, string> = {};
        if (!reason) x['vo-reason'] = 'Choose what was wrong.';
        if (!note.trim()) x['vo-note'] = 'Say what happened.';
        if (needsCorrection && !(Number(amount) > 0)) x['vo-amount'] = 'Enter the right amount.';
        Object.assign(x, witnessErrors('vo-w', who, pin));
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'vo-reason': cantSaveOffline });
        setConfirm(true);
    }
    function commit() {
        s.update((rt) => {
            const before = balanceOf(m, rt) - e0.change;
            const corr = needsCorrection ? newEntry(m.id, 'correction', -Math.abs(Number(amount)) * (e0.change < 0 ? 1 : -1), before, me.name, [who], `${ENTRY_LABEL[e0.kind]} of ${e0.at}: ${note.trim()}`, { corrects: e0.id }) : null;
            return { ...rt, voids: { ...rt.voids, [e0.id]: { by: me.name, witness: who, at: stamp(), reason: `${reason} — ${note.trim()}`, correctedBy: corr?.id } }, entries: corr ? [corr, ...rt.entries] : rt.entries };
        });
        s.toast('success', `Voided — the entry stays visible, struck through.${needsCorrection ? ' The correcting entry is recorded.' : ''}`);
        onClose();
    }
    return (
        <>
            <Modal
                width={720}
                title={`Void an entry — ${m.med}, ${PEOPLE[m.pid].pref}`}
                description={`${ENTRY_LABEL[e0.kind]} · ${signed(e0.change)} · ${e0.at} · recorded by ${e0.by}${e0.witnesses.length ? `, witnessed by ${e0.witnesses.join(' and ')}` : ''}`}
                onClose={onClose}
                onCloseAutoFocus={restore(returnFocus)}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={check}>
                            Void the entry
                        </Button>
                    </>
                }
            >
                <div className="space-y-2">
                    <Label id="vo-reason-l">
                        What was wrong? <Req />
                    </Label>
                    <div id="vo-reason" tabIndex={-1}>
                        <TilePicker labelledBy="vo-reason-l" value={reason || null} invalid={!!e['vo-reason']} onChange={(k) => (setReason(k), setE({}))} tiles={VOID_REASONS} />
                    </div>
                    <InputError message={e['vo-reason']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="vo-note">
                        What happened <Req />
                    </Label>
                    <Textarea id="vo-note" rows={2} value={note} aria-invalid={!!e['vo-note']} placeholder="e.g. One tablet was given; two were recorded by mistake." onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                    <InputError message={e['vo-note']} />
                </div>
                {reason && reason !== 'Recorded twice' ? (
                    <div className="space-y-2 rounded-lg border p-3">
                        <label className="flex items-center gap-2 text-sm">
                            <Checkbox checked={correct} onCheckedChange={(c) => (setCorrect(!!c), setE({}))} />
                            Record the correcting entry now
                        </label>
                        {correct ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <div className="space-y-1.5">
                                    <Label htmlFor="vo-amount">
                                        The right amount <Req />
                                    </Label>
                                    <Input id="vo-amount" value={amount} inputMode="numeric" aria-invalid={!!e['vo-amount']} placeholder={`In ${m.unit}`} onChange={(ev) => (setAmount(ev.target.value.replace(/[^\d.]/g, '')), setE({}))} />
                                    <InputError message={e['vo-amount']} />
                                </div>
                            </div>
                        ) : (
                            <p className="text-caption">Record the right entry yourself afterwards — the void and the balance are shown until then.</p>
                        )}
                    </div>
                ) : null}
                <WitnessField id="vo-w" house={PEOPLE[m.pid].house} recorder={me.name} exclude={[e0.by]} who={who} pin={pin} onWho={(v) => (setWho(v), setE({}))} onPin={(v) => (setPin(v), setE({}))} errors={e} />
                <p className="text-caption">The original entry is never deleted: it stays in the register, struck through, with who voided it, the witness and the reason.</p>
            </Modal>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    commit();
                }}
                title="Void this register entry?"
                description={`The ${ENTRY_LABEL[e0.kind].toLowerCase()} of ${e0.at} stops counting towards the balance.${needsCorrection ? ` The correcting entry of ${amount} is recorded in its place.` : ''} Both stay in the register.`}
                confirmText="Void the entry"
                cancelText="Keep it"
            />
        </>
    );
}

/* ═════════════ The NZ class (Q9) ═════════════ */
export function ClassDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const m = medOf(id);
    const [cls, setCls] = useState<string>(classOf(m, s.rt) ?? '');
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        if (!cls) {
            setE({ 'cl-cls': 'Choose the class.' });
            return focusFirst({ 'cl-cls': 'x' });
        }
        s.update((rt) => ({ ...rt, classes: { ...rt.classes, [m.id]: cls as NzClass } }));
        s.toast('success', `${m.med} is Class ${cls}.`);
        onClose();
    }
    return (
        <Modal width={720} title={`Class — ${m.med}, ${PEOPLE[m.pid].pref}`} description={m.legacySchedule ? `Today it says “${m.legacySchedule}” — the UK scheme. Choose the NZ class; it isn’t mapped automatically.` : `${m.strength}`} onClose={onClose} onCloseAutoFocus={restore(returnFocus)} footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button onClick={save}>Save</Button></>}>
            <div className="space-y-2">
                <Label id="cl-cls-l">
                    Class under the Misuse of Drugs Act 1975 <Req />
                </Label>
                <div id="cl-cls" tabIndex={-1}>
                    <TilePicker
                        labelledBy="cl-cls-l"
                        value={cls || null}
                        invalid={!!e['cl-cls']}
                        onChange={(k) => (setCls(k), setE({}))}
                        tiles={[
                            { key: 'A', label: 'Class A', description: 'Very high risk of harm', icon: ShieldCheck },
                            { key: 'B', label: 'Class B', description: 'High risk of harm', icon: ShieldCheck },
                            { key: 'C', label: 'Class C', description: 'Moderate risk of harm', icon: ShieldCheck },
                        ]}
                    />
                </div>
                <InputError message={e['cl-cls']} />
            </div>
            <p className="text-caption">Check the class on the pharmacy label or with the pharmacist. It doesn’t change how the register works — every controlled medicine is recorded with a witness.</p>
        </Modal>
    );
}

/* ═════════════ Breakage or spillage (a named reason — Q3) ═════════════ */
export function BreakageDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const m = medOf(id);
    const [qty, setQty] = useState('');
    const [what, setWhat] = useState('');
    const [who, setWho] = useState('');
    const [pin, setPin] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const bal = balanceOf(m, s.rt);
    function save() {
        const x: Record<string, string> = {};
        if (!(Number(qty) > 0)) x['br-qty'] = 'Enter how many.';
        else if (Number(qty) > bal) x['br-qty'] = `The balance is ${bal}.`;
        if (!what.trim()) x['br-what'] = 'Say what happened.';
        Object.assign(x, witnessErrors('br-w', who, pin));
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'br-qty': cantSaveOffline });
        s.update((rt) => ({ ...rt, entries: [newEntry(m.id, 'breakage', -Number(qty), balanceOf(m, rt), me.name, [who], what.trim()), ...rt.entries] }));
        s.toast('success', `Recorded — ${m.med} balance is now ${bal - Number(qty)}. What’s left of it goes back to the pharmacy with the returns.`);
        onClose();
    }
    return (
        <Modal width={720} title={`Breakage or spillage — ${m.med}, ${PEOPLE[m.pid].pref}`} description={`Balance ${unitLabel(m, bal)} · the witness sees what happened`} onClose={onClose} onCloseAutoFocus={restore(returnFocus)} footer={<><Button variant="outline" onClick={onClose}>Cancel</Button><Button onClick={save}>Record it</Button></>}>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="br-qty">
                        How many <Req />
                    </Label>
                    <Input id="br-qty" value={qty} inputMode="numeric" aria-invalid={!!e['br-qty']} onChange={(ev) => (setQty(ev.target.value.replace(/[^\d.]/g, '')), setE({}))} />
                    <InputError message={e['br-qty']} />
                </div>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="br-what">
                    What happened <Req />
                </Label>
                <Textarea id="br-what" rows={2} value={what} aria-invalid={!!e['br-what']} placeholder="e.g. Tablet dropped and crushed while popping it from the blister — pieces bagged for return." onChange={(ev) => (setWhat(ev.target.value), setE({}))} />
                <InputError message={e['br-what']} />
            </div>
            <WitnessField id="br-w" house={PEOPLE[m.pid].house} recorder={me.name} who={who} pin={pin} onWho={(v) => (setWho(v), setE({}))} onPin={(v) => (setPin(v), setE({}))} errors={e} />
            <p className="text-caption">A breakage the witness saw isn’t a loss. If you can’t account for it, report a loss instead.</p>
        </Modal>
    );
}

/* ═════════════ A discrepancy (read-only) ═════════════ */
export function DiscrepancyDialog({ id, onClose, onAction }: { id: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const p = s.route.persona;
    const d = allDiscrepancies(s.rt).find((x) => x.id === id)!;
    const m = medOf(d.medId);
    const why = whyCantResolve(d, p);
    return (
        <Modal
            width={720}
            title={`${d.id} — ${m.med}, ${PEOPLE[m.pid].pref}`}
            description={`Counted ${d.counted}, expected ${d.expected} · ${d.started}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {d.status !== 'closed' && !why && (d.status === 'open' || PERSONAS[p].perms.includes('manager')) ? <Button onClick={() => onAction(`resolve:${d.id}`)}>Resolve it</Button> : null}
                </>
            }
        >
            <KV
                rows={[
                    ['Counted by', `${d.countedBy}, witnessed by ${d.witness} — recounted, still different`],
                    ['Register', `Follows what was counted (${d.counted}); the difference stays here`],
                    ['Owner', d.owner],
                    ['Incident', `${d.incident} — closed in Safety & oversight › Medication errors`],
                    ['Doses', 'Not blocked'],
                    ['State', d.status === 'closed' ? `${OUTCOME_LABEL[d.resolved!.outcome]} — ${d.resolved!.at} by ${d.resolved!.by}: “${d.resolved!.note}”` : d.status === 'under_review' ? `With a manager — escalated ${d.escalated?.at} by ${d.escalated?.by}` : 'Open'],
                ]}
            />
            {d.status !== 'closed' && why ? <p className="text-caption">{why}</p> : null}
        </Modal>
    );
}

/* ═════════════ Resolve a discrepancy (replaces ResolveDiscrepancyDialog — Q4) ═════════════ */
const OUTCOMES: { key: Outcome; label: string; description: string; icon: typeof Scale }[] = [
    { key: 'recount', label: 'Recount matched', description: 'A counting slip — the balance goes back', icon: RefreshCw },
    { key: 'recording', label: 'Recording error', description: 'Void the wrong entry and correct it', icon: Undo2 },
    { key: 'found', label: 'Stock found', description: 'Add it back, witnessed', icon: Search },
    { key: 'loss', label: 'Unexplained loss', description: 'Creates a loss report', icon: FileWarning },
    { key: 'escalate', label: 'Escalate to a manager', description: 'A manager resolves it', icon: ArrowUpRight },
];
export function ResolveDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const d = allDiscrepancies(s.rt).find((x) => x.id === id)!;
    const m = medOf(d.medId);
    const diff = d.expected - d.counted;
    const recent = entriesOf(m, s.rt).filter((e) => !e.voided && e.kind !== 'count').slice(0, 5);
    const [step, setStep] = useState(0);
    const [outcome, setOutcome] = useState<Outcome | ''>('');
    const [note, setNote] = useState('');
    const [entryId, setEntryId] = useState('');
    const [where, setWhere] = useState('');
    const [circ, setCirc] = useState('');
    const [police, setPolice] = useState(false);
    const [who, setWho] = useState('');
    const [pin, setPin] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    /** Every balance change and every void is witnessed (Q2, Q3). */
    const witnessed = outcome === 'recount' || outcome === 'found' || outcome === 'loss' || outcome === 'recording';
    const steps = [
        { key: 'what', label: 'What you found', blurb: 'The outcome', icon: Scale },
        { key: 'detail', label: 'Details', blurb: outcome ? OUTCOMES.find((o) => o.key === outcome)!.label : 'For the outcome', icon: PenLine },
        ...(witnessed ? [{ key: 'witness', label: 'Witness', blurb: 'At the cupboard', icon: ShieldCheck }] : []),
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const dirty = !!(outcome || note);
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'what' && !outcome) x['rs-outcome'] = 'Choose what you found.';
        if (k === 'detail') {
            if (!note.trim()) x['rs-note'] = 'Say what you checked and found.';
            if (outcome === 'recording' && !entryId) x['rs-entry'] = 'Choose the wrong entry.';
            if (outcome === 'found' && !where.trim()) x['rs-where'] = 'Say where it was.';
            if (outcome === 'loss' && !circ.trim()) x['rs-circ'] = 'Describe the circumstances for the loss report.';
        }
        if (k === 'witness') Object.assign(x, witnessErrors('rs-w', who, pin));
        return x;
    }
    function next() {
        const x = validate(cur);
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        setStep(step + 1);
    }
    function save() {
        if (s.route.scenario === 'offline') return setE({ 'rs-note': cantSaveOffline });
        setPhase('sending');
        window.setTimeout(() => {
            s.update((rt) => {
                const next = { ...rt };
                const bal = balanceOf(m, rt);
                let link: string | undefined;
                if (outcome === 'recount' || outcome === 'found') next.entries = [newEntry(m.id, outcome === 'found' ? 'found' : 'count', diff, bal, me.name, [who], outcome === 'found' ? `Found: ${where.trim()} (${d.id})` : `Recount matched the register (${d.id})`, { link: d.id }), ...rt.entries];
                if (outcome === 'loss') {
                    link = `L-${8 + rt.losses.length}`;
                    const loss: Loss = { id: link, medId: m.id, qty: diff, discovered: d.started, circumstances: circ.trim(), immediate: note.trim(), reportedBy: me.name, witness: who, incident: d.incident, fromDiscrepancy: d.id, police: { informed: police }, regulator: { informed: false }, timeline: [{ at: stamp(), by: me.name, note: `Reported from discrepancy ${d.id} — unexplained loss of ${diff}.` }], status: 'investigating' };
                    next.losses = [loss, ...rt.losses];
                    next.entries = [newEntry(m.id, 'loss', 0, bal, me.name, [who], `Unexplained loss of ${diff} — the count on ${d.started} had already moved the balance (${d.id})`, { link }), ...rt.entries];
                }
                if (outcome === 'recording') next.voids = { ...rt.voids, [entryId]: { by: me.name, witness: who, at: stamp(), reason: `Recording error found resolving ${d.id} — ${note.trim()}` } };
                next.discrepancies = { ...rt.discrepancies, [d.id]: outcome === 'escalate' ? { status: 'under_review', escalated: { by: me.name, at: stamp(), note: note.trim() } } : { status: 'closed', resolved: { by: me.name, at: stamp(), outcome: outcome as Outcome, note: note.trim(), link } } };
                return next;
            });
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        what: (
            <>
                <KV rows={[['Medicine', `${m.med} ${m.strength} · ${PEOPLE[m.pid].legal}`], ['Counted', `${d.counted}, expected ${d.expected} (${signed(-diff)}) — ${d.started}, by ${d.countedBy} with ${d.witness}`], ['Owner', d.owner], ['Incident', d.incident]]} />
                <div className="space-y-2">
                    <Label id="rs-outcome-l">
                        What did you find? <Req />
                    </Label>
                    <div id="rs-outcome" tabIndex={-1}>
                        <TilePicker labelledBy="rs-outcome-l" value={outcome || null} invalid={!!e['rs-outcome']} onChange={(k) => (setOutcome(k as Outcome), setStep(0), setE({}))} tiles={OUTCOMES.map((o) => ({ ...o, disabled: o.key === 'escalate' && d.status === 'under_review' ? 'Already with a manager' : null }))} />
                    </div>
                    <InputError message={e['rs-outcome']} />
                </div>
            </>
        ),
        detail: (
            <>
                {outcome === 'recording' ? (
                    <div className="space-y-2">
                        <Label id="rs-entry-l">
                            Which entry was wrong? <Req />
                        </Label>
                        <ul id="rs-entry" tabIndex={-1} className="divide-y rounded-lg border" aria-labelledby="rs-entry-l">
                            {recent.map((x) => (
                                <li key={x.id}>
                                    <label className="flex items-center gap-3 px-3 py-2 text-sm">
                                        <Checkbox checked={entryId === x.id} onCheckedChange={(c) => (setEntryId(c ? x.id : ''), setE({}))} />
                                        {ENTRY_LABEL[x.kind]} · {signed(x.change)} · {x.at} · {x.by}
                                    </label>
                                </li>
                            ))}
                        </ul>
                        <InputError message={e['rs-entry']} />
                        <p className="text-caption">It’s voided (kept, struck through). Record the correct entry from the register afterwards.</p>
                    </div>
                ) : null}
                {outcome === 'found' ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="rs-where">
                            Where was it? <Req />
                        </Label>
                        <Input id="rs-where" value={where} aria-invalid={!!e['rs-where']} placeholder="e.g. In the fridge door with Tama’s other syringes" onChange={(ev) => (setWhere(ev.target.value), setE({}))} />
                        <InputError message={e['rs-where']} />
                    </div>
                ) : null}
                {outcome === 'loss' ? (
                    <>
                        <div className="space-y-1.5">
                            <Label htmlFor="rs-circ">
                                Circumstances, for the loss report <Req />
                            </Label>
                            <Textarea id="rs-circ" rows={2} value={circ} aria-invalid={!!e['rs-circ']} onChange={(ev) => (setCirc(ev.target.value), setE({}))} />
                            <InputError message={e['rs-circ']} />
                        </div>
                        <label className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                            <Checkbox checked={police} onCheckedChange={(c) => setPolice(!!c)} />
                            Theft is suspected — the police are being told
                        </label>
                    </>
                ) : null}
                {outcome === 'escalate' ? <Notice tone="info" title="A manager resolves it">It moves to “With a manager”. The manager can’t be someone who did the count.</Notice> : null}
                <div className="space-y-1.5">
                    <Label htmlFor="rs-note">
                        What you checked and found <Req />
                    </Label>
                    <Textarea id="rs-note" rows={3} value={note} aria-invalid={!!e['rs-note']} placeholder="e.g. Recounted with Mere — 22. The 7:00 am count missed a tablet stuck in the blister." onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                    <InputError message={e['rs-note']} />
                </div>
            </>
        ),
        witness: (
            <>
                <p className="text-sm">{outcome === 'loss' ? 'The loss is a witnessed register entry.' : outcome === 'recording' ? 'Voiding an entry is witnessed.' : 'The balance changes, so someone witnesses it at the cupboard.'}</p>
                <WitnessField id="rs-w" house={PEOPLE[m.pid].house} recorder={me.name} exclude={[]} who={who} pin={pin} onWho={(v) => (setWho(v), setE({}))} onPin={(v) => (setPin(v), setE({}))} errors={e} />
            </>
        ),
        review: (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={Scale} title="Outcome" onEdit={() => setStep(0)}>
                    <ReviewRow label="Outcome" value={outcome ? OUTCOME_LABEL[outcome] : '—'} />
                    <ReviewRow label="Found" value={note} />
                </ReviewCard>
                <ReviewCard icon={BookOpen} title="The register" onEdit={() => setStep(1)}>
                    <ReviewRow label="Balance" value={outcome === 'recount' || outcome === 'found' ? `${balanceOf(m, s.rt)} → ${balanceOf(m, s.rt) + diff}` : outcome === 'recording' ? 'The wrong entry is voided' : 'Stays as counted'} />
                    {witnessed ? <ReviewRow label="Witness" value={who} /> : null}
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you save">
                        {outcome === 'escalate' ? `${d.id} moves to “With a manager”.` : `${d.id} closes${outcome === 'loss' ? ', and a loss report opens for a manager to close' : ''}. A note goes on incident ${d.incident}; the incident is closed in Medication errors.`}
                    </Notice>
                </div>
            </div>
        ),
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={`Resolve ${d.id} — ${m.med}, ${PEOPLE[m.pid].pref}`}
                description="What you found, the details, a witness if the balance changes, then review."
                railIcon={Scale}
                railTitle="Resolve a discrepancy"
                railSub={`${PEOPLE[m.pid].pref} · counted ${d.counted}, expected ${d.expected}`}
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
                            {phase === 'sending' ? 'Saving…' : outcome === 'escalate' ? 'Escalate' : 'Resolve'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title={outcome === 'escalate' ? 'Escalated' : 'Resolved'}
                            blurb={outcome === 'escalate' ? 'A manager resolves it from Discrepancies › With a manager.' : outcome === 'loss' ? 'The loss report is open in Losses; a manager closes it.' : `${d.id} is closed. The note is on incident ${d.incident}.`}
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
                title="Discard this resolution?"
                description="Nothing has been saved. The discrepancy stays open."
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

