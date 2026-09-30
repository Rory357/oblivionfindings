/* P05’s change and upkeep dialogs — one change and its timeline (Q6, with
 * Main’s phone-rule addition), the prescriber’s decision, entering an agreed
 * change in Orders (the bridge to P04’s approved “Enter a change”), adding a
 * controlled medicine’s outcome, moving and cancelling a review (Q8), the
 * appointment, and how often (Q2). Fleet Settings `Modal`, ConfirmDialog,
 * TilePicker, Select, Input, Textarea, FileDropzone + StagedFileCard, the
 * PKG-01 DateTimeField and the approved DatePicker. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { AlertTriangle, CalendarClock, Check, HelpCircle, Hospital, Mail, Phone, Repeat, Settings2, UserRound, Users, XCircle } from 'lucide-react';
import { useState } from 'react';
import { NOW_LOCAL } from './clock';
import { CLINICIANS, DECISION_HOW, MOVE_LABEL, OUTCOME_LABEL, PEOPLE, PERSONAS, WHERE_LABEL, orderOf, type Decision, type DecisionHow, type Item, type MoveReason, type Outcome, type Review, type Where } from './data';
import { localLabel, StoredFile, WHERE_TILES } from './dialogs-review';
import { cantSaveOffline, focusFirst, Req, restore, stamp } from './helpers';
import { Modal } from './modal';
import { addMonths, canEnter, canManage, changeState, intervalOf, itemKey, labelIso, medText, TODAY_ISO } from './model';
import { useStore } from './store';
import { DefaultChip, KV, Notice, TilePicker } from './ui';

const itemOf = (r: Review, orderId: string) => r.recorded!.items.find((i) => i.orderId === orderId)!;

/* ═════════════ One change, and where it’s at (Q6, Q7) ═════════════ */
export function ChangeDialog({ review: r, orderId, onClose, onAction }: { review: Review; orderId: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const p = s.route.persona;
    const it = itemOf(r, orderId);
    const rec = r.recorded!;
    const st = changeState(it);
    const k = `${r.id}:${orderId}`;
    const hidden = !!orderOf(orderId).cd && !PERSONAS[p].perms.includes('cd.view');
    const steps: { title: string; body: string; done: boolean; extra?: React.ReactNode }[] = [];
    const who = rec.clinicians.map((c) => `${c.name} (${c.role})`).join(', ');
    if (it.pendingCd) {
        steps.push({ title: 'Recorded without this medicine’s outcome', body: `${rec.by} can’t see controlled medicines. A house lead with controlled-medicine access adds it.`, done: false });
    } else if (it.outcome === 'watch') {
        steps.push({ title: 'Recommended at the review', body: `Watch for: ${it.watch!.what} — until ${it.watch!.until}. ${who}, ${rec.at}.`, done: true });
        steps.push({ title: 'A follow-up for the house lead', body: `${it.watch!.followUp} · ${r.owner} · until ${it.watch!.until}.`, done: true });
        steps.push({ title: 'Watched', body: it.watch!.done ? `${it.watch!.done.by}, ${it.watch!.done.at}: “${it.watch!.done.note}”` : 'Open — recorded on the follow-up when it’s done.', done: !!it.watch!.done });
    } else {
        const d = it.decision;
        steps.push({ title: 'Recommended at the review', body: `${OUTCOME_LABEL[it.outcome]}: ${hidden ? 'details need controlled-medicine access' : it.what}. ${who}, ${rec.at}.`, done: true });
        steps.push({ title: 'Asked the prescriber', body: d?.asked ? `${d.asked.how}, ${d.asked.at} — ${d.asked.by}` : d?.how === 'review' ? 'Not needed — the prescriber did the review' : 'Not asked yet', done: !!d?.asked || d?.how === 'review' });
        steps.push({
            title: 'The prescriber’s decision',
            body: !d || d.state === 'waiting' ? 'Waiting — nothing changes on the chart until they decide.' : `${d.state === 'agreed' ? 'Agreed' : 'Not agreed'} by ${d.prescriber}, ${d.at} — ${DECISION_HOW[d.how!].toLowerCase()}${d.note ? `: “${d.note}”` : ''}. Recorded by ${d.by}.`,
            done: !!d && d.state !== 'waiting',
            extra: d?.file ? <StoredFile name={d.file} /> : undefined,
        });
        if (d?.state !== 'notAgreed') {
            const e = it.entered;
            steps.push({ title: 'Entered in Orders', body: e ? `${e.version}, ${e.at} by ${e.by}${e.phone ? ` — a phone order, read back with ${e.phone.witness} (their witness PIN)` : ''}.` : d?.state === 'agreed' ? `Not entered yet — ${canEnter(p) ? 'you, or another lead or manager,' : 'a house lead, clinical lead or manager'} enters it in Orders.` : 'After the decision.', done: !!e });
            if (e?.phone || (d?.state === 'agreed' && d.how !== 'writing'))
                steps.push({ title: 'The prescriber’s written confirmation', body: e?.phone?.written ? `${e.phone.written.at} — ${e.phone.written.by}` : e?.phone ? `Due ${e.phone.writtenDue} — it’s needed before the change is checked (the phone rule).` : 'Needed before the check, because the decision wasn’t in writing (the phone rule).', done: !!e?.phone?.written });
            steps.push({ title: 'Checked', body: e?.checked ? `By ${e.checked.by}, ${e.checked.at} — the chart now shows the change.` : 'By someone else who checks orders — then it can be given.', done: !!e?.checked });
        }
    }
    const nextStep =
        st.step === 'pending' && canManage(p) && !hidden
            ? ['Add the outcome', `outcome:${k}`]
            : st.step === 'waiting' && canManage(p) && !hidden
              ? ['Record the decision', `decision:${k}`]
              : st.step === 'agreed' && !hidden
                ? ['Enter the change', `enter:${k}`]
                : null;
    return (
        <Modal
            width={720}
            title={`${medText(p, orderId)} — ${PEOPLE[r.pid].legal}`}
            description={`${it.pendingCd ? 'Outcome to add' : OUTCOME_LABEL[it.outcome]} · from ${r.id}, ${rec.at.split(', ')[0]}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {['written', 'check'].includes(st.step) ? (
                        <Button variant="outline" onClick={() => s.toast('info', 'Opens the order in P04’s Orders — outside this preview.')}>
                            Open the order
                        </Button>
                    ) : null}
                    {nextStep ? <Button onClick={() => onAction(nextStep[1])}>{nextStep[0]}</Button> : null}
                </>
            }
        >
            <div className="flex flex-wrap items-center gap-2">
                <StatusBadge variant={st.variant} className="rounded-[8px] font-semibold">
                    {st.label}
                </StatusBadge>
            </div>
            <ol className="space-y-3" aria-label="Where it’s at">
                {steps.map((x, n) => (
                    <li key={x.title} className="flex gap-3 text-sm">
                        <span className={`grid size-6 shrink-0 place-items-center rounded-full border text-[11px] font-semibold ${x.done ? 'border-primary bg-primary/10 text-primary' : 'text-muted-foreground'}`} aria-hidden="true">
                            {n + 1}
                        </span>
                        <span className="min-w-0 flex-1 space-y-1.5">
                            <span className="block font-semibold">{x.title}</span>
                            <span className="block text-muted-foreground">{x.body}</span>
                            {x.extra}
                        </span>
                    </li>
                ))}
            </ol>
        </Modal>
    );
}

/* ═════════════ The prescriber’s decision (Q6) ═════════════ */
export function DecisionDialog({ review: r, orderId, onClose, returnFocus }: { review: Review; orderId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const it = itemOf(r, orderId);
    const o = orderOf(orderId);
    const [state, setState] = useState<'agreed' | 'notAgreed' | 'asked' | ''>('');
    const [prescriber, setPrescriber] = useState('Dr Lena Chen');
    const [otherName, setOtherName] = useState('');
    const [when, setWhen] = useState(NOW_LOCAL);
    const [how, setHow] = useState<DecisionHow | ''>('');
    const [file, setFile] = useState<File | null>(null);
    const [note, setNote] = useState('');
    const [askedHow, setAskedHow] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const decided = state === 'agreed' || state === 'notAgreed';
    function save() {
        const x: Record<string, string> = {};
        if (!state) x['dc-state'] = 'Choose what happened.';
        if (state === 'asked' && !askedHow.trim()) x['dc-asked'] = 'Say how you asked — for example “Email to Dr Lena Chen”.';
        if (decided && prescriber === 'other' && !otherName.trim()) x['dc-other'] = 'Enter the prescriber’s name.';
        if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(when)) x['dc-when'] = 'Choose when.';
        else if (when > NOW_LOCAL) x['dc-when'] = 'It can’t be in the future.';
        if (decided && !how) x['dc-how'] = 'Choose how they told you.';
        if (decided && how === 'writing' && !file) x['dc-file'] = 'Attach what they wrote — it’s the record.';
        if (state === 'notAgreed' && !note.trim()) x['dc-note'] = 'Say what they said.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'dc-state': cantSaveOffline });
        const name = prescriber === 'other' ? otherName.trim() : prescriber;
        const decision: Decision = state === 'asked' ? { ...it.decision, state: 'waiting', asked: { how: askedHow.trim(), at: localLabel(when), by: me.name } } : { ...it.decision, state: state as 'agreed' | 'notAgreed', prescriber: name, at: localLabel(when), how: how as DecisionHow, file: file?.name, note: note.trim() || undefined, by: me.name };
        s.update((rt) => ({ ...rt, items: { ...rt.items, [itemKey(r.id, orderId)]: { ...rt.items[itemKey(r.id, orderId)], decision } } }));
        s.toast('success', state === 'asked' ? 'Recorded that you asked. It still waits for their decision.' : state === 'agreed' ? `Agreed — it’s in Changes to make, to enter in Orders.${how === 'phone' || how === 'person' ? ' It follows the phone rule when it’s entered.' : ''}` : 'Not agreed — kept on the review. Nothing changes.');
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`The prescriber’s decision — ${o.med}, ${PEOPLE[r.pid].pref}`}
            description={`${OUTCOME_LABEL[it.outcome]}: ${it.what} · from ${r.id}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save</Button>
                </>
            }
        >
            <div className="space-y-2">
                <Label id="dc-state-l">
                    What happened <Req />
                </Label>
                <div id="dc-state" tabIndex={-1}>
                    <TilePicker
                        labelledBy="dc-state-l"
                        value={state || null}
                        invalid={!!e['dc-state'] && !state}
                        onChange={(k) => (setState(k as 'agreed' | 'notAgreed' | 'asked'), setE({}))}
                        tiles={[
                            { key: 'agreed', label: 'Agreed', description: 'It goes to Changes to make, for Orders', icon: Check },
                            { key: 'notAgreed', label: 'Not agreed', description: 'Kept on the review; nothing changes', icon: XCircle },
                            { key: 'asked', label: 'Asked — no answer yet', description: 'Record how and when you asked', icon: HelpCircle },
                        ]}
                    />
                </div>
                <InputError message={e['dc-state']} />
            </div>
            {state === 'asked' ? (
                <div className="space-y-1.5">
                    <Label htmlFor="dc-asked">
                        How you asked <Req />
                    </Label>
                    <Input id="dc-asked" value={askedHow} aria-invalid={!!e['dc-asked']} placeholder="e.g. Email to Dr Lena Chen" onChange={(ev) => (setAskedHow(ev.target.value), setE({}))} />
                    <InputError message={e['dc-asked']} />
                </div>
            ) : null}
            {decided ? (
                <div className="space-y-1.5">
                    <Label htmlFor="dc-pres">
                        Prescriber <Req />
                    </Label>
                    <Select value={prescriber} onValueChange={(v) => (setPrescriber(v), setE({}))}>
                        <SelectTrigger id="dc-pres" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {CLINICIANS.filter((c) => c.role !== 'Pharmacist').map((c) => (
                                <SelectItem key={c.name} value={c.name}>
                                    {c.name} — {c.role}, {c.practice}
                                </SelectItem>
                            ))}
                            <SelectItem value="other">Someone else</SelectItem>
                        </SelectContent>
                    </Select>
                    {prescriber === 'other' ? <Input id="dc-other" aria-label="Prescriber’s name" value={otherName} aria-invalid={!!e['dc-other']} onChange={(ev) => (setOtherName(ev.target.value), setE({}))} /> : null}
                    <InputError message={e['dc-other']} />
                </div>
            ) : null}
            {state ? <DateTimeField id="dc-when" label={state === 'asked' ? 'When you asked' : 'When they decided'} value={when} onChange={(v) => (setWhen(v), setE({}))} error={e['dc-when']} /> : null}
            {decided ? (
                <>
                    <div className="space-y-2">
                        <Label id="dc-how-l">
                            How they told you <Req />
                        </Label>
                        <div id="dc-how" tabIndex={-1}>
                            <TilePicker
                                labelledBy="dc-how-l"
                                value={how || null}
                                invalid={!!e['dc-how'] && !how}
                                onChange={(k) => (setHow(k as DecisionHow), setE({}))}
                                tiles={[
                                    { key: 'writing', label: 'In writing', description: 'A letter, email or e-prescription — attach it', icon: Mail },
                                    { key: 'phone', label: 'By phone', description: 'Written confirmation before it’s checked', icon: Phone },
                                    { key: 'person', label: 'In person', description: 'Written confirmation before it’s checked', icon: UserRound },
                                ]}
                            />
                        </div>
                        <InputError message={e['dc-how']} />
                    </div>
                    {how === 'writing' ? (
                        <div className="space-y-1.5">
                            <Label id="dc-file-l">
                                What they wrote <Req />
                            </Label>
                            {file ? <StagedFileCard file={file} onRemove={() => setFile(null)} /> : <FileDropzone id="dc-file" aria-labelledby="dc-file-l" aria-invalid={!!e['dc-file']} multiple={false} accept="application/pdf,image/*" hint="Stored privately with the review" onFiles={(x) => (setFile(x[0] ?? null), setE({}))} />}
                            <InputError message={e['dc-file']} />
                        </div>
                    ) : null}
                    {state === 'agreed' && (how === 'phone' || how === 'person') ? (
                        <Notice tone="info" title="It follows the phone rule when it’s entered">
                            It’s read back to the prescriber with a colleague who types their witness PIN, and their written confirmation is needed before it’s checked.
                        </Notice>
                    ) : null}
                    <div className="space-y-1.5">
                        <Label htmlFor="dc-note">What they said {state === 'notAgreed' ? <Req /> : <span className="font-normal text-muted-foreground">(optional)</span>}</Label>
                        <Textarea id="dc-note" rows={2} value={note} aria-invalid={!!e['dc-note']} placeholder={state === 'notAgreed' ? 'e.g. Keep 12:00 pm — lunch is her main meal.' : ''} onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                        <InputError message={e['dc-note']} />
                    </div>
                </>
            ) : null}
        </Modal>
    );
}

/* ═════════════ Enter an agreed change — the bridge to P04 (Q6) ═════════════ */
const SOURCE_FOR: Record<DecisionHow, string> = { writing: 'Written prescription — what they wrote is attached', phone: 'Phone order — read back with a witness PIN; written confirmation before the check', person: 'Verbal order — read back with a witness PIN; written confirmation before the check', review: 'Verbal order — agreed at the review; written confirmation before the check' };
export function EnterDialog({ review: r, orderId, onClose, returnFocus }: { review: Review; orderId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const it = itemOf(r, orderId);
    const o = orderOf(orderId);
    const d = it.decision!;
    function go() {
        if (s.route.scenario === 'offline') return s.toast('warning', cantSaveOffline);
        const phone = d.how !== 'writing' ? { witness: 'the colleague who heard the read-back', writtenDue: 'by the end of tomorrow (Tuesday 29 September)' } : undefined;
        s.update((rt) => ({ ...rt, items: { ...rt.items, [itemKey(r.id, orderId)]: { ...rt.items[itemKey(r.id, orderId)], entered: { at: stamp(), by: me.name, version: 'v2', phone } } } }));
        s.toast('success', `Entered in Orders as ${o.med} v2 — ${phone ? 'waiting for the written confirmation, then the check' : 'waiting to be checked by someone else'}.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Enter the agreed change — ${o.med}, ${PEOPLE[r.pid].pref}`}
            description={`Agreed by ${d.prescriber}, ${d.at} · from ${r.id}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={go}>Continue in Orders</Button>
                </>
            }
        >
            <p className="text-sm">Orders’ “Enter a change” opens with this filled in, and the change is linked to {r.id}.</p>
            <KV
                rows={[
                    ['Person', PEOPLE[r.pid].legal],
                    ['Order now', `${o.med} ${o.strength} · ${o.dose} · ${o.when}`],
                    ['What’s changing', it.what ?? OUTCOME_LABEL[it.outcome]],
                    ['Where it came from', SOURCE_FOR[d.how!]],
                    ...(d.file ? ([['Attached', d.file]] as [string, string][]) : []),
                ]}
            />
            <Notice tone="neutral" title="Then">
                {d.how === 'writing' ? 'Someone else checks it before it can be given. Until then the chart shows the current order.' : 'The prescriber’s written confirmation is due by the end of tomorrow. Someone else checks the change once it’s attached. Until then the chart shows the current order.'}
            </Notice>
        </Modal>
    );
}

/* ═════════════ A controlled medicine’s outcome, added by a house lead (Q9 addition) ═════════════ */
export function OutcomeDialog({ review: r, orderId, onClose, returnFocus }: { review: Review; orderId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const o = orderOf(orderId);
    const [outcome, setOutcome] = useState<Outcome | ''>('');
    const [what, setWhat] = useState('');
    const [watch, setWatch] = useState('');
    const [until, setUntil] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        const x: Record<string, string> = {};
        if (!outcome) x['oc-out'] = 'Choose the outcome.';
        else if (['change', 'stop', 'swap'].includes(outcome) && !what.trim()) x['oc-what'] = 'Say what the clinician recommends.';
        else if (outcome === 'watch' && (!watch.trim() || !until)) x[watch.trim() ? 'oc-until-date' : 'oc-watch'] = watch.trim() ? 'Choose until when.' : 'Say what to watch for.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'oc-out': cantSaveOffline });
        const patch: Partial<Item> = { pendingCd: false, outcome: outcome as Outcome, what: what.trim() || undefined, decision: ['change', 'stop', 'swap'].includes(outcome) ? { state: 'waiting' } : undefined, watch: outcome === 'watch' ? { what: watch.trim(), until: labelIso(until), followUp: 'FU-95' } : undefined };
        s.update((rt) => ({ ...rt, items: { ...rt.items, [itemKey(r.id, orderId)]: { ...rt.items[itemKey(r.id, orderId)], ...patch } } }));
        s.toast('success', 'Outcome added to the review.');
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Add the outcome — ${o.med}, ${PEOPLE[r.pid].pref}`}
            description={`A controlled medicine · ${r.id} was recorded by ${r.recorded!.by}, who can’t see it`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Add it</Button>
                </>
            }
        >
            <p className="text-sm">
                {o.med} {o.strength} · {o.dose} · {o.when}. Use the clinician’s letter or summary on the review.
            </p>
            <div className="space-y-1.5">
                <Label htmlFor="oc-out">
                    Outcome <Req />
                </Label>
                <Select value={outcome || undefined} onValueChange={(v) => (setOutcome(v as Outcome), setE({}))}>
                    <SelectTrigger id="oc-out" className="w-full" aria-invalid={!!e['oc-out']}>
                        <SelectValue placeholder="Choose the outcome" />
                    </SelectTrigger>
                    <SelectContent>
                        {(['continue', 'change', 'stop', 'swap', 'watch'] as Outcome[]).map((k) => (
                            <SelectItem key={k} value={k}>
                                {OUTCOME_LABEL[k]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={e['oc-out']} />
            </div>
            {['change', 'stop', 'swap'].includes(outcome) ? (
                <div className="space-y-1.5">
                    <Label htmlFor="oc-what">
                        What the clinician recommends <Req />
                    </Label>
                    <Input id="oc-what" value={what} aria-invalid={!!e['oc-what']} onChange={(ev) => (setWhat(ev.target.value), setE({}))} />
                    <InputError message={e['oc-what']} />
                </div>
            ) : null}
            {outcome === 'watch' ? (
                <div className="grid gap-3 sm:grid-cols-[1fr_240px]">
                    <div className="space-y-1.5">
                        <Label htmlFor="oc-watch">
                            What to watch for <Req />
                        </Label>
                        <Input id="oc-watch" value={watch} aria-invalid={!!e['oc-watch']} onChange={(ev) => (setWatch(ev.target.value), setE({}))} />
                        <InputError message={e['oc-watch']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="oc-until-date">
                            Until <Req />
                        </Label>
                        <DatePicker id="oc-until-date" label="Watch until" value={until} invalid={!!e['oc-until-date']} onChange={(v) => (setUntil(v), setE({}))} />
                        <InputError message={e['oc-until-date']} />
                    </div>
                </div>
            ) : null}
        </Modal>
    );
}

/* ═════════════ Move a review (Q8) ═════════════ */
export function MoveDialog({ review: r, onClose, returnFocus }: { review: Review; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const [to, setTo] = useState('');
    const [reason, setReason] = useState<MoveReason | ''>('');
    const [note, setNote] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        const x: Record<string, string> = {};
        if (!to) x['mv-to-date'] = 'Choose the new date.';
        else if (to <= TODAY_ISO) x['mv-to-date'] = 'Choose a date after today.';
        else if (to === r.dueIso) x['mv-to-date'] = 'That’s the date it already has.';
        if (!reason) x['mv-reason'] = 'Choose why it’s moving.';
        if (reason === 'other' && !note.trim()) x['mv-note'] = 'Say why.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'mv-reason': cantSaveOffline });
        s.update((rt) => ({ ...rt, patch: { ...rt.patch, [r.id]: { ...rt.patch[r.id], due: labelIso(to), dueIso: to, moves: [...r.moves, { from: r.due, to: labelIso(to), reason: reason as MoveReason, note: note.trim() || undefined, by: me.name, at: stamp() }] } } }));
        s.toast('success', `Moved to ${labelIso(to)}. ${r.due} is kept in its history.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Move the review — ${r.id}, ${PEOPLE[r.pid].pref}`}
            description={`Due ${r.due} now · earlier dates are always kept`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Move it</Button>
                </>
            }
        >
            <div className="space-y-1.5">
                <Label htmlFor="mv-to-date">
                    New due date <Req />
                </Label>
                <DatePicker id="mv-to-date" label="New due date" value={to} invalid={!!e['mv-to-date']} onChange={(v) => (setTo(v), setE({}))} />
                <InputError message={e['mv-to-date']} />
            </div>
            <div className="space-y-2">
                <Label id="mv-reason-l">
                    Why it’s moving <Req />
                </Label>
                <div id="mv-reason" tabIndex={-1}>
                    <TilePicker
                        labelledBy="mv-reason-l"
                        value={reason || null}
                        invalid={!!e['mv-reason'] && !reason}
                        onChange={(k) => (setReason(k as MoveReason), setE({}))}
                        tiles={[
                            { key: 'clinician', label: MOVE_LABEL.clinician, description: 'Their diary, leave or illness', icon: UserRound },
                            { key: 'unwell', label: MOVE_LABEL.unwell, description: 'Or away from the house', icon: AlertTriangle },
                            { key: 'hospital', label: MOVE_LABEL.hospital, description: 'A review may follow when they’re back', icon: Hospital },
                            { key: 'whanau', label: MOVE_LABEL.whanau, description: 'So they can take part', icon: Users },
                            { key: 'other', label: MOVE_LABEL.other, description: 'Say why', icon: HelpCircle },
                        ]}
                    />
                </div>
                <InputError message={e['mv-reason']} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="mv-note">Note {reason === 'other' ? <Req /> : <span className="font-normal text-muted-foreground">(optional)</span>}</Label>
                <Textarea id="mv-note" rows={2} value={note} aria-invalid={!!e['mv-note']} onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                <InputError message={e['mv-note']} />
            </div>
            {r.moves.length ? <p className="text-caption">Moved before: {r.moves.map((m) => `${m.from} → ${m.to} (${MOVE_LABEL[m.reason].toLowerCase()})`).join('; ')}.</p> : null}
            {PEOPLE[r.pid].away ? <p className="text-caption">{PEOPLE[r.pid].pref} is away — {PEOPLE[r.pid].away!.charAt(0).toLowerCase() + PEOPLE[r.pid].away!.slice(1)}. Being away doesn’t pause the due date; move it if the review can’t happen.</p> : null}
        </Modal>
    );
}

/* ═════════════ Cancel a triggered review (Q8) ═════════════ */
export function CancelDialog({ review: r, onClose, onAction, returnFocus }: { review: Review; onClose: () => void; onAction: (spec: string) => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const [reason, setReason] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const [confirm, setConfirm] = useState(false);
    if (r.kind === 'regular')
        return (
            <Modal
                title="A regular review can’t be cancelled"
                description="So the cycle never lapses."
                onClose={onClose}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                        <Button onClick={() => onAction(`move:${r.id}`)}>Move it instead</Button>
                    </>
                }
            >
                <p className="text-sm">Move {PEOPLE[r.pid].pref}’s review to a date that works, with the reason. When a person leaves the service, their open reviews close by themselves.</p>
            </Modal>
        );
    function check() {
        if (reason.trim().length < 10) {
            setE({ 'cx-reason': 'Say why, in a sentence — at least 10 characters.' });
            return focusFirst({ 'cx-reason': 'x' });
        }
        if (s.route.scenario === 'offline') return setE({ 'cx-reason': cantSaveOffline });
        setConfirm(true);
    }
    return (
        <>
            <Modal
                width={720}
                title={`Cancel the review — ${r.id}, ${PEOPLE[r.pid].pref}`}
                description={`Triggered — due ${r.due} · it stays in the history with the reason`}
                onClose={onClose}
                onCloseAutoFocus={restore(returnFocus)}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Keep it
                        </Button>
                        <Button variant="destructive" onClick={check}>
                            Cancel the review
                        </Button>
                    </>
                }
            >
                <div className="space-y-1.5">
                    <Label htmlFor="cx-reason">
                        Why it’s no longer needed <Req />
                    </Label>
                    <Textarea id="cx-reason" rows={3} value={reason} aria-invalid={!!e['cx-reason']} placeholder="e.g. The GP answered it at her appointment on 14 Sep." onChange={(ev) => (setReason(ev.target.value), setE({}))} />
                    <InputError message={e['cx-reason']} />
                </div>
            </Modal>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    s.update((rt) => ({ ...rt, patch: { ...rt.patch, [r.id]: { ...rt.patch[r.id], state: 'cancelled', cancelled: { by: me.name, at: stamp(), reason: reason.trim() } } } }));
                    s.toast('success', `${r.id} is cancelled. It stays in ${PEOPLE[r.pid].pref}’s history with the reason.`);
                    onClose();
                }}
                title={`Cancel ${r.id}?`}
                description="It can’t be reopened. Book a new review if one’s needed later."
                confirmText="Cancel the review"
                cancelText="Keep it"
            />
        </>
    );
}

/* ═════════════ The appointment ═════════════ */
export function ApptDialog({ review: r, onClose, returnFocus }: { review: Review; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const [withWho, setWithWho] = useState(r.booked?.with.name ?? '');
    const [when, setWhen] = useState('');
    const [where, setWhere] = useState<Where | ''>(r.booked?.where ?? '');
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        const x: Record<string, string> = {};
        if (!withWho) x['ap-with'] = 'Choose the clinician.';
        if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(when)) x['ap-when'] = 'Choose the date and time.';
        else if (when < NOW_LOCAL) x['ap-when'] = 'Choose a time from now on.';
        if (!where) x['ap-where'] = 'Choose where it happens.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'ap-with': cantSaveOffline });
        const c = CLINICIANS.find((z) => z.name === withWho)!;
        s.update((rt) => ({ ...rt, patch: { ...rt.patch, [r.id]: { ...rt.patch[r.id], booked: { with: c, at: localLabel(when), iso: when.slice(0, 10), where: where as Where } } } }));
        s.toast('success', `Appointment booked with ${c.name}, ${localLabel(when)}.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`${r.booked ? 'Change the appointment' : 'Book the appointment'} — ${r.id}, ${PEOPLE[r.pid].pref}`}
            description={`Due ${r.due}${r.booked ? ` · now ${r.booked.at} with ${r.booked.with.name}` : ''}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save the appointment</Button>
                </>
            }
        >
            <div className="space-y-1.5">
                <Label htmlFor="ap-with">
                    Clinician <Req />
                </Label>
                <Select value={withWho || undefined} onValueChange={(v) => (setWithWho(v), setE({}))}>
                    <SelectTrigger id="ap-with" className="w-full" aria-invalid={!!e['ap-with']}>
                        <SelectValue placeholder="Choose the clinician" />
                    </SelectTrigger>
                    <SelectContent>
                        {CLINICIANS.map((c) => (
                            <SelectItem key={c.name} value={c.name}>
                                {c.name} — {c.role}, {c.practice}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={e['ap-with']} />
            </div>
            <DateTimeField id="ap-when" label="Appointment" value={when} onChange={(v) => (setWhen(v), setE({}))} error={e['ap-when']} />
            <div className="space-y-2">
                <Label id="ap-where-l">
                    Where <Req />
                </Label>
                <div id="ap-where" tabIndex={-1}>
                    <TilePicker labelledBy="ap-where-l" value={where || null} invalid={!!e['ap-where'] && !where} onChange={(k) => (setWhere(k as Where), setE({}))} tiles={WHERE_TILES} />
                </div>
                <InputError message={e['ap-where']} />
            </div>
            {r.booked ? <p className="text-caption">The earlier appointment ({r.booked.at}, {WHERE_LABEL[r.booked.where].toLowerCase()}) is kept in the review’s history.</p> : null}
        </Modal>
    );
}

/* ═════════════ How often — the person’s interval (Q2) ═════════════ */
export function IntervalDialog({ pid, onClose, returnFocus }: { pid: keyof typeof PEOPLE; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const person = PEOPLE[pid];
    const cur = intervalOf(s.rt, pid);
    const [mode, setMode] = useState<'org' | 'own'>(cur.own ? 'own' : 'org');
    const [months, setMonths] = useState(cur.own ? String(cur.months) : '');
    const [why, setWhy] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        const n = Number(months);
        const x: Record<string, string> = {};
        if (mode === 'own' && !(Number.isInteger(n) && n >= 1 && n <= 12)) x['iv-months'] = 'Enter a whole number of months, 1 to 12.';
        if (mode === 'own' && !why.trim()) x['iv-why'] = 'Say why this person has their own interval.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'iv-months': cantSaveOffline });
        s.update((rt) => ({ ...rt, intervals: { ...rt.intervals, [pid]: mode === 'own' ? n : null } }));
        s.toast('success', mode === 'own' ? `${person.pref}’s regular review is every ${n} months from the next one booked.` : `${person.pref} follows the organisation default — every ${s.rt.org.months} months.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`How often — ${person.legal}`}
            description={cur.own ? `Every ${cur.months} months — set for ${person.pref}` : `Every ${cur.months} months — the organisation default`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save</Button>
                </>
            }
        >
            <div className="space-y-2">
                <Label id="iv-mode-l">Regular review</Label>
                <TilePicker
                    labelledBy="iv-mode-l"
                    value={mode}
                    onChange={(k) => (setMode(k as 'org' | 'own'), setE({}))}
                    tiles={[
                        { key: 'org', label: 'The organisation default', description: `Every ${s.rt.org.months} months — set in Settings`, icon: Settings2 },
                        { key: 'own', label: `${person.pref}’s own interval`, description: '1 to 12 months', icon: Repeat },
                    ]}
                />
                {mode === 'org' && !s.rt.org.reviewed ? <DefaultChip value={`Every ${s.rt.org.months} months`} /> : null}
            </div>
            {mode === 'own' ? (
                <div className="grid gap-3 sm:grid-cols-[200px_1fr]">
                    <div className="space-y-1.5">
                        <Label htmlFor="iv-months">
                            Every <Req />
                        </Label>
                        <span className="inline-flex items-center gap-2">
                            <Input id="iv-months" type="number" inputMode="numeric" min={1} max={12} value={months} aria-invalid={!!e['iv-months']} className="h-9 w-24 tabular-nums" onChange={(ev) => (setMonths(ev.target.value.trim()), setE({}))} />
                            <span className="text-subtle">months</span>
                        </span>
                        <InputError message={e['iv-months']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="iv-why">
                            Why <Req />
                        </Label>
                        <Input id="iv-why" value={why} aria-invalid={!!e['iv-why']} placeholder="e.g. Asked for by Dr Arun Patel at the last review" onChange={(ev) => (setWhy(ev.target.value), setE({}))} />
                        <InputError message={e['iv-why']} />
                    </div>
                </div>
            ) : null}
            <p className="text-caption">A review already booked keeps its date — move it if it needs to change. The interval sets the next one booked when a regular review is recorded ({labelIso(addMonths(TODAY_ISO, mode === 'own' && Number(months) ? Number(months) : s.rt.org.months))} if one were recorded today).</p>
            <p className="text-caption">
                <CalendarClock className="mr-1 inline size-3.5" aria-hidden="true" />
                Recorded in {person.pref}’s record history with your name and the time.
            </p>
        </Modal>
    );
}
