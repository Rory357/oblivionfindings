/* Report a medication error (today’s ReportErrorModal, which has no medicine
 * picker). One wizard, started from the page, the dose menu (P01), a check
 * that wasn’t right (P08a “Not right”), “More than ordered — this already
 * happened” (P01) or the person’s record. Real WizardShell, WizardStepPane,
 * ReviewCard/ReviewRow, WizardSuccessPane, ConfirmDialog, TilePicker, Select,
 * Checkbox, Input, Textarea and the PKG-01 DateTimeField. Decisions: Main,
 * Q1–Q4, Q6, Q9 and the refinements. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { AlertOctagon, Check, ChevronLeft, ChevronRight, CircleHelp, CircleSlash, Clock3, CopyCheck, EyeOff, FilePlus2, Loader2, LockKeyhole, MessageSquareText, PenLine, Pill, Route, Scale, ShieldAlert, UserRound, Users } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LOCAL } from './clock';
import { DOSE_TIME, HARM_LABEL, HOUSES, MORE_THAN, NOT_RIGHT, PEOPLE, PEOPLE_ORDER, PERSONAS, REACH_LABEL, TYPE_LABEL, orderOf, ordersOf, type ErrType, type Harm, type MedError, type MedIncident, type PersonId, type Reach, type Source } from './data';
import { cantSaveOffline, focusFirst, Req, restore, stamp } from './helpers';
import { cdView, duplicateOf, incidentRequired, localLabel, medsText, redacted, summaryOf, triageDue } from './model';
import { useStore } from './store';
import { KV, Notice, TilePicker } from './ui';

export const TYPE_TILES: { key: ErrType; description: string; icon: typeof Pill }[] = [
    { key: 'wrongTime', description: 'Too early or too late for the order', icon: Clock3 },
    { key: 'omission', description: 'It wasn’t given and should have been', icon: CircleSlash },
    { key: 'wrongDose', description: 'More or less than the order', icon: Scale },
    { key: 'wrongMedicine', description: 'A different medicine from the one ordered', icon: Pill },
    { key: 'wrongPerson', description: 'Someone else’s medicine', icon: Users },
    { key: 'wrongRoute', description: 'For example swallowed instead of under the tongue', icon: Route },
    { key: 'recorded', description: 'Given right, recorded wrong — or not recorded', icon: PenLine },
    { key: 'other', description: 'Say what in your account', icon: CircleHelp },
];
export const reachTiles = (pref: string) => [
    { key: 'no', label: 'No — a near miss', description: `Caught before ${pref} took it or it was given`, icon: ShieldAlert },
    { key: 'yes', label: 'Yes', description: `${pref} took it or it was given — a missed dose counts too`, icon: UserRound },
    { key: 'unsure', label: 'Not sure yet', description: 'The owner updates it at triage', icon: CircleHelp },
];
export const HARM_TILES: { key: Harm; label: string; description: string; icon: typeof Pill }[] = [
    { key: 'none', label: 'No harm', description: 'No change seen', icon: UserRound },
    { key: 'minor', label: 'Minor or temporary', description: 'Extra checks or brief treatment; no lasting effect', icon: AlertOctagon },
    { key: 'moderate', label: 'Moderate', description: 'Needed treatment, a GP or an after-hours visit, or a longer effect', icon: AlertOctagon },
    { key: 'severe', label: 'Severe or permanent', description: 'Hospital, or a lasting effect', icon: AlertOctagon },
    { key: 'death', label: 'Death', description: 'The person died', icon: AlertOctagon },
    { key: 'unknown', label: 'Not known yet', description: 'Say so — the owner updates it', icon: CircleHelp },
];
const VALID = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/;

/** A controlled medicine named in the reporter’s own words (the prompt runs only for reporters with controlled view — Main, Q4 i). */
export function namedControlled(pid: PersonId, text: string) {
    const t = text.toLowerCase();
    return ordersOf(pid).filter((o) => o.cd && t.includes(o.med.toLowerCase()));
}

export function ReportDialog({ mode, orderId, pid: pid0, onClose, onAction, returnFocus }: { mode: 'page' | 'dose' | 'notright' | 'more'; orderId?: string; pid?: PersonId; onClose: () => void; onAction: (spec: string) => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const fromDose = mode !== 'page';
    const order = orderId ? orderOf(orderId) : null;
    const more = mode === 'more' && orderId ? MORE_THAN[orderId] : null;
    const check = mode === 'notright' && orderId ? NOT_RIGHT[orderId] : null;
    const people = PEOPLE_ORDER.filter((x) => me.houses.includes(PEOPLE[x].house));
    const [step, setStep] = useState(0);
    const [pid, setPid] = useState<PersonId | ''>(order?.pid ?? pid0 ?? '');
    const [meds, setMeds] = useState<string[]>(order ? [order.id] : []);
    const [notAbout, setNotAbout] = useState<'' | 'none' | 'offChart'>('');
    const [offChart, setOffChart] = useState('');
    const [type, setType] = useState<ErrType | ''>(more ? 'wrongDose' : '');
    const [when, setWhen] = useState(orderId && fromDose ? DOSE_TIME[orderId] ?? '' : '');
    const [reach, setReach] = useState<Reach | ''>(more ? 'yes' : '');
    const [harm, setHarm] = useState<Harm | ''>('');
    const [account, setAccount] = useState(more ? `Recorded at the dose as “More than ordered — this already happened”: ${more.given} given, ${more.ordered} ordered.` : '');
    const [immediate, setImmediate] = useState('');
    const [contributing, setContributing] = useState('');
    const [kept, setKept] = useState(false);
    const [dupChoice, setDupChoice] = useState<'' | 'same' | 'different'>('');
    const [incidentOpt, setIncidentOpt] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [result, setResult] = useState<{ id: string; incident?: string; added?: boolean } | null>(null);
    const person = pid ? PEOPLE[pid] : null;
    const source: Source = mode === 'page' ? 'page' : mode === 'dose' ? 'dose' : mode === 'notright' ? 'notRight' : 'more';
    const draft = { pid: (pid || 'aroha') as PersonId, orderIds: meds, notAbout: notAbout || undefined, offChart, type: (type || 'other') as ErrType, reach: (reach || 'unsure') as Reach, harm: (reach === 'no' ? 'none' : harm || 'unknown') as Harm, source };
    const dup = pid && meds.length && VALID.test(when) ? duplicateOf(s.rt, pid, meds, when.slice(0, 10)) : null;
    const required = incidentRequired(draft);
    const due = triageDue(s.rt.org.value);
    const cdNamed = cdView(p) && pid ? namedControlled(pid, `${account} ${immediate} ${contributing}`) : [];
    const steps = [
        { key: 'what', label: 'What happened', blurb: 'Who, which medicine, what and when', icon: AlertOctagon },
        { key: 'harm', label: 'Reach & harm', blurb: 'Two plain questions', icon: UserRound },
        { key: 'account', label: 'Your account', blurb: 'In your own words', icon: MessageSquareText },
        ...(dup ? [{ key: 'same', label: 'Already reported?', blurb: `An open report may match`, icon: CopyCheck }] : []),
        { key: 'review', label: 'Review & send', blurb: 'Check, then send', icon: Check },
    ];
    const cur = steps[Math.min(step, steps.length - 1)].key;
    const dirty = !!((pid && !order && !pid0) || (meds.length && !order) || notAbout || (type && !more) || (when && !fromDose) || (reach && !more) || harm || (account && !more) || immediate || contributing);

    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'what') {
            if (!pid) x['rp-person'] = 'Choose the person.';
            if (pid && !meds.length && !notAbout) x['rp-meds'] = 'Choose the medicine, or say it isn’t about one medicine.';
            if (notAbout === 'offChart' && !offChart.trim()) x['rp-offchart'] = 'Say which medicine.';
            if (!type) x['rp-type'] = 'Choose what went wrong.';
            if (!VALID.test(when)) x['rp-when'] = 'Choose when it happened.';
            else if (when > NOW_LOCAL) x['rp-when'] = 'It can’t be in the future.';
        }
        if (k === 'harm') {
            if (!reach) x['rp-reach'] = `Say whether it reached ${person?.pref ?? 'the person'}.`;
            if (reach && reach !== 'no' && !harm) x['rp-harm'] = 'Choose how much harm, so far — or “Not known yet”.';
        }
        if (k === 'account') {
            if (account.trim().length < 20) x['rp-account'] = 'Say what happened, in a sentence or two — at least 20 characters.';
            if (!immediate.trim()) x['rp-immediate'] = 'Say what you did straight away — even “told the house lead”.';
        }
        if (k === 'same' && !dupChoice) x['rp-same'] = 'Say whether it’s the same error.';
        return x;
    }
    function next() {
        const x = validate(cur);
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        setStep(steps.findIndex((st) => st.key === cur) + 1);
    }
    const toggleMed = (id: string, on: boolean) => (setMeds((m) => (on ? [...m, id] : m.filter((y) => y !== id))), setNotAbout(''), setE({}));
    function send() {
        if (s.route.scenario === 'offline') return setE({ 'rp-send': cantSaveOffline });
        setPhase('sending');
        window.setTimeout(() => {
            const at = stamp();
            const entry = { by: me.name, at, text: [account.trim(), immediate.trim() ? `Straight away: ${immediate.trim()}` : '', contributing.trim() ? `More likely because: ${contributing.trim()}` : ''].filter(Boolean).join('\n') };
            if (dup && dupChoice === 'same') {
                s.update((rt) => ({ ...rt, patch: { ...rt.patch, [dup.id]: { ...rt.patch[dup.id], accounts: [...dup.accounts, entry], log: [...(dup.log ?? []), { at, text: `Account added by ${me.name} — reported again from ${mode === 'page' ? 'Medication errors' : 'the dose'}` }] } } }));
                setResult({ id: dup.id, added: true });
                return setPhase('done');
            }
            const id = `MED-00${49 + s.rt.errors.length}`;
            const inc = required || incidentOpt ? `INC-${2237 + s.rt.newIncidents.length}` : undefined;
            const err: MedError = {
                id,
                pid: pid as PersonId,
                orderIds: meds,
                notAbout: notAbout || undefined,
                offChart: notAbout === 'offChart' ? offChart.trim() : undefined,
                type: type as ErrType,
                source,
                occurred: localLabel(when),
                occurredIso: when.slice(0, 10),
                reported: { by: me.name, at },
                reach: reach as Reach,
                harm: reach === 'no' ? 'none' : (harm as Harm),
                accounts: [{ by: me.name, at, text: account.trim() }],
                immediate: immediate.trim(),
                contributing: contributing.trim() || undefined,
                stage: 'triage',
                triageDue: due.label,
                triageDueIso: due.iso,
                notes: [],
                actions: [],
                incident: inc,
                log: inc ? [{ at, text: `Incident ${inc} made with the report — it carries the summary only` }] : [],
            };
            const incident: MedIncident | null = inc ? { id: inc, source: 'error', ref: id, pid: pid as PersonId, status: 'submitted' } : null;
            s.update((rt) => ({ ...rt, errors: [err, ...rt.errors], newIncidents: incident ? [incident, ...rt.newIncidents] : rt.newIncidents }));
            setResult({ id, incident: inc });
            setPhase('done');
        }, 700);
    }

    const medRow = (id: string) => {
        const o = orderOf(id);
        const hide = !!o.cd && !cdView(p);
        return (
            <li key={id} className="flex items-start gap-3 px-3 py-2.5">
                <Checkbox id={`rp-med-${id}`} checked={meds.includes(id)} disabled={mode === 'more'} onCheckedChange={(v) => toggleMed(id, v === true)} aria-describedby={`rp-med-${id}-d`} />
                <Label htmlFor={`rp-med-${id}`} className="block min-w-0 flex-1 font-normal">
                    <span className="flex items-center gap-2 text-sm font-semibold">
                        {hide ? <LockKeyhole className="size-3.5 text-muted-foreground" aria-hidden="true" /> : null}
                        {hide ? 'Controlled medicine' : `${o.med} ${o.strength}`}
                        {o.cd && !hide ? <StatusBadge variant="neutral" size="sm">Controlled</StatusBadge> : null}
                    </span>
                    <span id={`rp-med-${id}-d`} className="block text-caption">
                        {hide ? 'Details need controlled-medicine access' : `${o.dose} · ${o.when}`}
                    </span>
                </Label>
            </li>
        );
    };
    const body: Record<string, ReactNode> = {
        what: (
            <>
                {check ? (
                    <Notice tone="neutral" icon={AlertOctagon} title={`From the ${check.check.toLowerCase()} — “Not right”`}>
                        {check.note} ({check.by}, {check.at})
                    </Notice>
                ) : null}
                {more ? (
                    <Notice tone="warning" title="From the dose — more than ordered, and it already happened">
                        {`The dose is recorded as given: ${more.given}, ordered ${more.ordered}. This report and one incident are made together.`}
                    </Notice>
                ) : null}
                <div className="space-y-1.5">
                    <Label htmlFor="rp-person">
                        Person <Req />
                    </Label>
                    <Select value={pid || undefined} onValueChange={(v) => (setPid(v as PersonId), setMeds([]), setNotAbout(''), setE({}))} disabled={fromDose || !!pid0}>
                        <SelectTrigger id="rp-person" className="w-full" aria-invalid={!!e['rp-person']}>
                            <SelectValue placeholder="Choose someone at your houses" />
                        </SelectTrigger>
                        <SelectContent>
                            {people.map((x) => (
                                <SelectItem key={x} value={x}>
                                    {PEOPLE[x].legal} — {HOUSES[PEOPLE[x].house]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={e['rp-person']} />
                </div>
                {person ? (
                    <div className="space-y-2">
                        <Label id="rp-meds-l">
                            Which medicine <Req />
                        </Label>
                        <div id="rp-meds" tabIndex={-1} role="group" aria-labelledby="rp-meds-l" className="space-y-2">
                            <ul className="divide-y rounded-lg border">{ordersOf(person.id).map((o) => medRow(o.id))}</ul>
                            {mode !== 'more' ? (
                                <ul className="divide-y rounded-lg border">
                                    <li className="flex items-start gap-3 px-3 py-2.5">
                                        <Checkbox id="rp-none" checked={notAbout === 'none'} onCheckedChange={(v) => (setNotAbout(v === true ? 'none' : ''), v === true && setMeds([]), setE({}))} />
                                        <Label htmlFor="rp-none" className="block font-normal">
                                            <span className="block text-sm font-semibold">It isn’t about one medicine</span>
                                            <span className="block text-caption">For example the wrong person’s pack, or a round that was missed</span>
                                        </Label>
                                    </li>
                                    <li className="flex items-start gap-3 px-3 py-2.5">
                                        <Checkbox id="rp-off" checked={notAbout === 'offChart'} onCheckedChange={(v) => (setNotAbout(v === true ? 'offChart' : ''), v === true && setMeds([]), setE({}))} />
                                        <Label htmlFor="rp-off" className="block font-normal">
                                            <span className="block text-sm font-semibold">A medicine that isn’t on {person.pref}’s chart</span>
                                            <span className="block text-caption">Say which — the house lead checks it at triage</span>
                                        </Label>
                                    </li>
                                </ul>
                            ) : null}
                        </div>
                        <InputError message={e['rp-meds']} />
                        {notAbout === 'offChart' ? (
                            <div className="space-y-1.5">
                                <Label htmlFor="rp-offchart">
                                    Which medicine <Req />
                                </Label>
                                <Input id="rp-offchart" value={offChart} aria-invalid={!!e['rp-offchart']} onChange={(ev) => (setOffChart(ev.target.value), setE({}))} />
                                <InputError message={e['rp-offchart']} />
                            </div>
                        ) : null}
                    </div>
                ) : null}
                {more ? (
                    <KV rows={[['What went wrong', `${TYPE_LABEL.wrongDose} — from the dose record`]]} />
                ) : (
                    <div className="space-y-2">
                        <Label id="rp-type-l">
                            What went wrong <Req />
                        </Label>
                        <div id="rp-type" tabIndex={-1}>
                            <TilePicker labelledBy="rp-type-l" value={type || null} invalid={!!e['rp-type'] && !type} onChange={(k) => (setType(k as ErrType), setE({}))} tiles={TYPE_TILES.map((t) => ({ ...t, label: TYPE_LABEL[t.key] }))} />
                        </div>
                        <InputError message={e['rp-type']} />
                    </div>
                )}
                <DateTimeField id="rp-when" label="When it happened" value={when} onChange={(v) => (setWhen(v), setE({}))} error={e['rp-when']} hint="The time it happened, not now. Reports are counted by this time." />
            </>
        ),
        harm: (
            <>
                {more ? (
                    <KV rows={[[`Did it reach ${person?.pref}?`, 'Yes — from the dose record']]} />
                ) : (
                    <div className="space-y-2">
                        <Label id="rp-reach-l">
                            Did it reach {person?.pref}? <Req />
                        </Label>
                        <div id="rp-reach" tabIndex={-1}>
                            <TilePicker labelledBy="rp-reach-l" value={reach || null} invalid={!!e['rp-reach'] && !reach} onChange={(k) => (setReach(k as Reach), setE({}))} tiles={reachTiles(person?.pref ?? 'the person')} />
                        </div>
                        <InputError message={e['rp-reach']} />
                    </div>
                )}
                {reach && reach !== 'no' ? (
                    <div className="space-y-2">
                        <Label id="rp-harm-l">
                            How much harm, so far? <Req />
                        </Label>
                        <div id="rp-harm" tabIndex={-1}>
                            <TilePicker labelledBy="rp-harm-l" value={harm || null} invalid={!!e['rp-harm'] && !harm} onChange={(k) => (setHarm(k as Harm), setE({}))} tiles={HARM_TILES} />
                        </div>
                        <InputError message={e['rp-harm']} />
                    </div>
                ) : null}
                {harm === 'severe' || harm === 'death' ? (
                    <Notice tone="critical" title={`If ${person?.pref} needs help now, call 111 first`}>
                        Then tell the manager on call. Finish this report when {person?.pref} is safe — what you’ve typed stays here.
                    </Notice>
                ) : null}
                {reach && required ? (
                    <Notice tone="warning" title="An incident is made with this report">
                        {`It carries the summary — “${summaryOf(draft)}” — with the person, when it happened and the harm. Never your account.`}
                    </Notice>
                ) : null}
            </>
        ),
        account: (
            <>
                <div className="space-y-1.5">
                    <Label htmlFor="rp-account">
                        What happened <Req />
                    </Label>
                    <Textarea id="rp-account" rows={4} value={account} aria-invalid={!!e['rp-account']} aria-describedby="rp-account-hint" placeholder="What happened, in your own words." onChange={(ev) => (setAccount(ev.target.value), setE({}))} />
                    <p id="rp-account-hint" className="text-caption">
                        Kept inside this report. Outside it — the incident, Control Room, tasks and the dashboard — a neutral summary is shown instead.
                    </p>
                    <InputError message={e['rp-account']} />
                </div>
                {cdNamed.length && !kept ? (
                    <Notice
                        tone="info"
                        icon={LockKeyhole}
                        title="You don’t need to name the medicine"
                        actions={
                            <Button size="sm" variant="outline" onClick={() => setKept(true)}>
                                Leave it as it is
                            </Button>
                        }
                    >
                        It’s already on the report. Your words are shown only to people with controlled-medicine access.
                    </Notice>
                ) : null}
                <div className="space-y-1.5">
                    <Label htmlFor="rp-immediate">
                        What you did straight away <Req />
                    </Label>
                    <Textarea id="rp-immediate" rows={2} value={immediate} aria-invalid={!!e['rp-immediate']} placeholder="For example: told the house lead, checked on the person, rang the GP." onChange={(ev) => (setImmediate(ev.target.value), setE({}))} />
                    <InputError message={e['rp-immediate']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="rp-contributing">
                        What made it more likely <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Textarea id="rp-contributing" rows={2} value={contributing} placeholder="For example: interrupted, two packs look alike, a new pen." onChange={(ev) => setContributing(ev.target.value)} />
                </div>
            </>
        ),
        same: dup ? (
            <>
                <ReviewCard icon={CopyCheck} title={`Is this the same as ${dup.id}?`}>
                    <ReviewRow label="Report" value={redacted(p, dup) ? 'An open report about this person at this dose time' : summaryOf(dup)} />
                    <ReviewRow label="When it happened" value={dup.occurred} />
                </ReviewCard>
                <div className="space-y-2">
                    <Label id="rp-same-l">
                        Is it the same error? <Req />
                    </Label>
                    <div id="rp-same" tabIndex={-1}>
                        <TilePicker
                            labelledBy="rp-same-l"
                            value={dupChoice || null}
                            invalid={!!e['rp-same'] && !dupChoice}
                            onChange={(k) => (setDupChoice(k as 'same' | 'different'), setE({}))}
                            tiles={[
                                { key: 'same', label: `Yes — add my account to ${dup.id}`, description: 'Your account is added to that report. Nothing is counted twice.', icon: CopyCheck },
                                { key: 'different', label: 'No — it’s a different error', description: 'A new report is made', icon: FilePlus2 },
                            ]}
                        />
                    </div>
                    <InputError message={e['rp-same']} />
                </div>
            </>
        ) : null,
        review: person ? (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={AlertOctagon} title="What happened" onEdit={() => setStep(0)}>
                    <ReviewRow label="Person" value={person.legal} />
                    <ReviewRow label="Medicine" value={medsText(p, draft)} />
                    <ReviewRow label="What went wrong" value={type ? TYPE_LABEL[type] : '—'} />
                    <ReviewRow label="When" value={localLabel(when)} />
                </ReviewCard>
                <ReviewCard icon={UserRound} title="Reach & harm" onEdit={() => setStep(1)}>
                    <ReviewRow label="Reached" value={reach ? REACH_LABEL[reach] : '—'} />
                    <ReviewRow label="Harm" value={reach === 'no' ? 'None — a near miss' : harm ? HARM_LABEL[harm] : '—'} />
                </ReviewCard>
                <ReviewCard icon={MessageSquareText} title="Your account" onEdit={() => setStep(2)}>
                    <ReviewRow label="What happened" value={account} />
                    <ReviewRow label="Straight away" value={immediate} />
                    {contributing ? <ReviewRow label="More likely because" value={contributing} /> : null}
                </ReviewCard>
                {dup && dupChoice === 'same' ? (
                    <ReviewCard icon={CopyCheck} title="Already reported" onEdit={() => setStep(3)}>
                        <ReviewRow label="Added to" value={dup.id} />
                        <ReviewRow label="Outside the report" value="Nothing new — no second report or incident" />
                    </ReviewCard>
                ) : (
                    <ReviewCard icon={EyeOff} title="Outside this report">
                        <ReviewRow label="Shown as" value={summaryOf(draft)} />
                        <ReviewRow
                            label="Incident"
                            value={
                                required ? (
                                    source === 'more' ? 'Made with it — more than ordered was given' : `Made with it — it reached ${person.pref} with ${HARM_LABEL[harm as Harm].toLowerCase()}`
                                ) : (
                                    <span className="flex items-start gap-2">
                                        <Checkbox id="rp-incident" checked={incidentOpt} onCheckedChange={(v) => setIncidentOpt(v === true)} />
                                        <Label htmlFor="rp-incident" className="font-normal">
                                            Also make an incident <span className="text-muted-foreground">(optional)</span>
                                        </Label>
                                    </span>
                                )
                            }
                        />
                    </ReviewCard>
                )}
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you send it">
                        {dup && dupChoice === 'same' ? `Your account is added to ${dup.id} with your name and the time. Its owner is told.` : `It’s triaged ${due.label}. The house lead and the clinical lead are alerted until then. You’ll see it in Your reports, and you’re told the outcome when it closes.`}
                    </Notice>
                    <InputError message={e['rp-send']} />
                </div>
            </div>
        ) : null,
    };
    const title = person ? `Report a medication error — ${person.legal}` : 'Report a medication error';
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={title}
                description="What happened, reach and harm, your account, then review."
                railIcon={AlertOctagon}
                railTitle="Report a medication error"
                railSub={person ? `${person.pref} · ${HOUSES[person.house]}` : 'Anyone who gives medicines can report'}
                steps={steps.map((x, n) => ({ ...x, disabled: n > step || phase === 'sending' }))}
                stepIndex={Math.min(step, steps.length - 1)}
                onStepClick={(n) => n <= step && setStep(n)}
                pct={Math.round(((Math.min(step, steps.length - 1) + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
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
                        <Button type="button" onClick={send} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                            {phase === 'sending' ? 'Sending…' : dup && dupChoice === 'same' ? `Add to ${dup.id}` : 'Send the report'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' && result ? (
                        <WizardSuccessPane
                            title={result.added ? `Added to ${result.id}` : 'Report sent'}
                            blurb={result.added ? `Your account is on ${result.id}. Nothing was counted twice.` : `${result.id} is triaged ${due.label}.${result.incident ? ` Incident ${result.incident} was made with it and carries the summary only.` : ''} You’ll see it in Your reports.`}
                            actions={
                                <>
                                    <Button type="button" variant="outline" onClick={() => onAction(`error:${result.id}`)}>
                                        Open the report
                                    </Button>
                                    <Button type="button" autoFocus onClick={onClose}>
                                        Done
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(88vh, 820px)"
            >
                <WizardStepPane>
                    <div className="space-y-4">{body[cur]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog open={discard} onClose={() => setDiscard(false)} onConfirm={() => (setDiscard(false), onClose())} title="Discard this report?" description="Nothing has been sent." confirmText="Discard" cancelText="Keep going" />
        </>
    );
}
