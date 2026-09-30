/* P10’s emergency access dialogs (Main, Q3–Q7): start it (the real
 * WizardShell, five steps, pre-filled from context), one grant, review it and
 * correct a review, extend it, end it (yours, or someone else’s with a
 * reason), and acknowledge repeat use. The Fleet Settings `Modal`, real
 * ReviewCard/ReviewRow, ConfirmDialog, TilePicker, Select, Textarea, Input,
 * Checkbox, the P11 Choice (Segmented) and PIN-1’s WitnessPinInput. */
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PersonDisc } from '@/components/lists/entity-cells';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { AlertOctagon, CalendarClock, Check, ChevronLeft, ChevronRight, ClipboardCheck, Eye, HeartPulse, KeyRound, Loader2, MessageSquareText, Phone, Pill, ShieldCheck, Siren, Timer, UserCheck, UserPlus, UserRound, Users, XCircle, type LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { CONFIRMERS, ERRORS, HOUSES, ONCALL, PEOPLE, PERSONAS, REASONS, TYPE_LABEL, orderOf, type At, type Grant, type PersonId, type ReasonKey, type EaReview, type ReviewOutcome } from './data';
import { canExtendNow, ENDING_SOON } from './ea-ui';
import { cantSaveOffline, focusFirst, Req, restore } from './helpers';
import { Modal } from './modal';
import {
    OUTCOME_LABEL,
    POLICY,
    HOW_ENDED,
    allGrants,
    atLong,
    atText,
    currentReview,
    durationsOffered,
    eaNow,
    endOf,
    flagsOf,
    fmtMin,
    houseOfGrant,
    isLive,
    isOverdue,
    latestOf,
    minutesLeft,
    needsReview,
    peopleOf,
    plusMin,
    reviewBlock,
    reviewDueOf,
    reviewersOf,
    rtEvent,
    stampAt,
    time12,
    toMin,
    REVIEW_BLOCK_TEXT,
    type Runtime,
} from './model';
import { Choice } from './p11-ui';
import { useStore } from './store';
import { KV, Notice, TilePicker } from './ui';

export const COULDNT_SAVE = 'Couldn’t save — try again. Nothing was recorded, and what you entered is still here.';
const REASON_ICON: Record<ReasonKey, LucideIcon> = { cover: Users, arrival: UserPlus, urgent: Siren, unwell: HeartPulse, other: MessageSquareText };
const recordOf = (pid: PersonId) => `${PEOPLE[pid].pref}’s record`;
const joinNames = (n: string[]) => (n.length <= 1 ? n.join('') : `${n.slice(0, -1).join(', ')} and ${n[n.length - 1]}`);
/** Patch one grant in the runtime, with its audit event (Q8). */
function patchGrant(rt: Runtime, g: Grant, patch: Partial<Grant>, ev: { what: string; detail: string; by: string }): Runtime {
    return { ...rt, grants: { ...rt.grants, [g.id]: { ...(rt.grants[g.id] ?? {}), ...patch } }, events: [...rt.events, rtEvent(rt, { house: houseOfGrant(g), kind: 'access', pid: g.pid, cd: false, ...ev })] };
}
const findGrant = (rt: Runtime, scn: Parameters<typeof allGrants>[1], id: string) => allGrants(rt, scn).find((g) => g.id === id);

/* ═════════════ Start emergency access (Q3, Q4) ═════════════ */
type StepKey = 'person' | 'why' | 'length' | 'second' | 'check';
export function RequestWizard({ pid: initial, onClose, returnFocus }: { pid?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const scn = s.route.scenario;
    const ea = eaNow(s.rt, scn);
    const people = peopleOf(p);
    const fixed = !!initial && people.includes(initial as PersonId);
    const [pid, setPid] = useState<PersonId | ''>(fixed ? (initial as PersonId) : '');
    const [reason, setReason] = useState<ReasonKey | ''>('');
    const [why, setWhy] = useState('');
    const [mins, setMins] = useState(String(POLICY.def));
    const [ask, setAsk] = useState<'ask' | 'skip' | ''>(ea.second === 'required' ? 'ask' : '');
    const [who, setWho] = useState('');
    const [pin, setPin] = useState('');
    const [tries, setTries] = useState(0);
    const [confirmed, setConfirmed] = useState<string | null>(null);
    const [nobody, setNobody] = useState(false);
    const [a1, setA1] = useState(false);
    const [a2, setA2] = useState(false);
    const [step, setStep] = useState(0);
    const [x, setX] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [made, setMade] = useState<Grant | null>(null);
    const [discard, setDiscard] = useState(false);
    const steps: { key: StepKey; label: string; blurb: string; icon: LucideIcon }[] = [
        { key: 'person', label: 'Who it’s for', blurb: 'One person only', icon: UserRound },
        { key: 'why', label: 'Why', blurb: 'Reviewers see it', icon: MessageSquareText },
        { key: 'length', label: 'How long', blurb: `Up to ${fmtMin(POLICY.max)} in all`, icon: Timer },
        ...(ea.second !== 'off' ? [{ key: 'second' as StepKey, label: 'Second person', blurb: ea.second === 'required' ? 'Required here' : 'Optional', icon: UserCheck }] : []),
        { key: 'check', label: 'Check and start', blurb: 'What it covers', icon: ShieldCheck },
    ];
    const cur = steps[Math.min(step, steps.length - 1)].key;
    const person = pid ? PEOPLE[pid] : null;
    const house = person?.house ?? me.houses[0];
    const existing = pid ? allGrants(s.rt, scn).find((g) => isLive(g) && g.by === me.name && g.pid === pid) : undefined;
    const ends = plusMin(stampAt(), Number(mins));
    const latest = plusMin(stampAt(), POLICY.max);
    const confirmers = CONFIRMERS[house].filter((n) => n !== me.name);
    const told = joinNames([...reviewersOf(house, [me.name, ...(confirmed ? [confirmed] : [])]), ...(confirmed ? [confirmed] : [])].filter((n, i, a) => a.indexOf(n) === i));
    const oncall = scn === 'oncallnone' ? null : ONCALL[house];
    const dirty = (!fixed && !!pid) || !!reason || !!why || mins !== String(POLICY.def) || (ea.second !== 'required' && !!ask) || !!who || !!pin || a1 || a2;

    function next() {
        const v: Record<string, string> = {};
        if (cur === 'person' && !pid) v['rq-person'] = 'Choose who it’s for.';
        if (cur === 'person' && existing) v['rq-person'] = `You already have emergency access for ${person!.pref}.`;
        if (cur === 'why' && !reason) v['rq-reason'] = 'Choose why.';
        if (cur === 'why' && why.trim().length < 10) v['rq-why'] = 'Say why in a few words — at least 10 characters.';
        if (cur === 'second') {
            if (!ask) v['rq-ask'] = 'Choose whether someone confirms it.';
            else if (ask === 'ask' && nobody) v['rq-who'] = 'It can’t start without a second person.';
            else if (ask === 'ask' && !who) v['rq-who'] = 'Choose who is confirming it.';
            else if (ask === 'ask' && pin.length !== 6) v['rq-pin'] = 'They type their 6-digit witness PIN.';
            else if (ask === 'ask' && pin === '000000') {
                const left = 4 - tries;
                setTries(tries + 1);
                setPin('');
                v['rq-pin'] = left > 0 ? `That PIN didn’t match. ${left} ${left === 1 ? 'try' : 'tries'} left before it locks for 15 minutes.` : 'Their PIN is locked for 15 minutes. Ask someone else to confirm.';
            }
        }
        if (cur === 'check' && (!a1 || !a2)) v[!a1 ? 'rq-a1' : 'rq-a2'] = 'Tick both to start.';
        setX(v);
        if (Object.keys(v).length) return focusFirst(v);
        if (cur === 'second' && ask === 'ask') setConfirmed(who);
        if (cur === 'second' && ask === 'skip') setConfirmed(null);
        if (cur !== 'check') return setStep(step + 1);
        start();
    }
    function start() {
        if (scn === 'offline') return setX({ 'rq-save': cantSaveOffline });
        if (scn === 'logdown') return setX({ 'rq-save': COULDNT_SAVE });
        setPhase('sending');
        window.setTimeout(() => {
            const g: Grant = {
                id: `EA-${14 + s.rt.newGrants.length}`,
                pid: pid as PersonId,
                by: me.name,
                start: stampAt(),
                firstEnd: ends,
                reason: reason as ReasonKey,
                why: why.trim(),
                ...(confirmed ? { second: { name: confirmed, at: '09:12' } } : {}),
                extensions: [],
                activity: [],
                reviews: [],
            };
            s.update((rt) => ({ ...rt, newGrants: [...rt.newGrants, g], events: [...rt.events, rtEvent(rt, { house, kind: 'access', what: `Emergency access opened — ${recordOf(g.pid)}`, detail: `${g.id} · ${g.why}${confirmed ? ` · confirmed by ${confirmed} with their PIN` : ''} · until ${time12(ends.hm)}`, by: me.name, pid: g.pid, cd: false })] }));
            setMade(g);
            setPhase('done');
        }, 500);
    }
    const draft = s.rt.recordDraft && s.rt.recordDraft.pid === pid ? s.rt.recordDraft : null;
    const body: Record<StepKey, React.ReactNode> = {
        person: (
            <>
                {fixed && person ? (
                    <Card className="flex-row items-center gap-3 p-3">
                        <PersonDisc name={person.legal} size={36} />
                        <span className="min-w-0">
                            <span className="block text-sm font-semibold">{person.legal}</span>
                            <span className="block text-caption">{HOUSES[person.house]} · from {person.pref}’s record</span>
                        </span>
                    </Card>
                ) : (
                    <div className="space-y-2">
                        <Label id="rq-person-l">
                            Who it’s for <Req />
                        </Label>
                        <div id="rq-person" tabIndex={-1}>
                            <TilePicker labelledBy="rq-person-l" value={pid || null} invalid={!!x['rq-person'] && !pid} onChange={(k) => (setPid(k as PersonId), setX({}))} tiles={people.map((q) => ({ key: q, label: PEOPLE[q].legal, description: HOUSES[PEOPLE[q].house], icon: UserRound }))} />
                        </div>
                    </div>
                )}
                {existing ? (
                    <Notice
                        tone="warning"
                        icon={KeyRound}
                        title={`You already have emergency access for ${person!.pref} until ${atText(endOf(existing))}`}
                        actions={
                            canExtendNow(existing) ? (
                                <Button size="sm" onClick={() => s.set({ open: `extend:${existing.id}` })}>
                                    Extend it instead
                                </Button>
                            ) : (
                                <Button size="sm" variant="outline" onClick={() => (onClose(), s.go('/emar/mar', { person: existing.pid, open: undefined, view: undefined }))}>
                                    Open {person!.pref}’s MAR
                                </Button>
                            )
                        }
                    >
                        {canExtendNow(existing) ? 'Extend it instead of starting another.' : `Carry on with it. You can extend it once ${ENDING_SOON} minutes or less remain.`}
                    </Notice>
                ) : (
                    <InputError message={x['rq-person']} />
                )}
                <Notice tone="neutral" icon={Eye} title="It covers one person — never a round or a whole house">
                    You can open their chart and record for them. Controlled medicines still need your own controlled-medicine permission and a witness.
                </Notice>
            </>
        ),
        why: (
            <>
                <div className="space-y-2">
                    <Label id="rq-reason-l">
                        Why you need it <Req />
                    </Label>
                    <div id="rq-reason" tabIndex={-1}>
                        <TilePicker labelledBy="rq-reason-l" value={reason || null} invalid={!!x['rq-reason'] && !reason} onChange={(k) => (setReason(k as ReasonKey), setX({}))} tiles={REASONS.map((q) => ({ key: q.key, label: q.label, description: q.description, icon: REASON_ICON[q.key] }))} />
                    </div>
                    <InputError message={x['rq-reason']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="rq-why">
                        In a few words <Req />
                    </Label>
                    <Input id="rq-why" maxLength={255} value={why} aria-invalid={!!x['rq-why']} placeholder="For example: Aroha’s support worker went home unwell — her 9:00 am doses are due" onChange={(ev) => (setWhy(ev.target.value), setX({}))} />
                    <InputError message={x['rq-why']} />
                    <p className="text-caption">Reviewers and the audit trail see it.</p>
                </div>
            </>
        ),
        length: (
            <>
                <div className="space-y-2">
                    <Label id="rq-len-l">How long</Label>
                    <div role="group" aria-labelledby="rq-len-l">
                        <Choice value={mins} onChange={setMins} options={durationsOffered().map((m) => [String(m), fmtMin(m)] as [string, string])} />
                    </div>
                    <p className="text-caption">Only lengths up to the longest grant — {fmtMin(POLICY.max)} — are offered.</p>
                </div>
                <KV
                    rows={[
                        ['Starts', 'Now, 9:12 am'],
                        ['Ends', `${atText(ends)} — unless you end it earlier`],
                        ['Extending', Number(mins) >= POLICY.max ? 'Not possible — this is already the longest time' : `${fmtMin(POLICY.ext)} at a time, once ${ENDING_SOON} minutes or less remain — never past ${atText(latest)}`],
                    ]}
                />
            </>
        ),
        second: (
            <>
                {ea.second === 'optional' ? (
                    <div className="space-y-2">
                        <Label id="rq-ask-l">
                            Does someone confirm it? <Req />
                        </Label>
                        <div id="rq-ask" tabIndex={-1}>
                            <TilePicker
                                labelledBy="rq-ask-l"
                                value={ask || null}
                                invalid={!!x['rq-ask'] && !ask}
                                onChange={(k) => (setAsk(k as 'ask' | 'skip'), setX({}))}
                                tiles={[
                                    { key: 'ask', label: 'Someone here confirms it', description: 'They type their own witness PIN on this screen', icon: UserCheck },
                                    { key: 'skip', label: 'Start without a second person', description: 'Allowed here — reviewers see that no one confirmed it', icon: UserRound },
                                ]}
                            />
                        </div>
                        <InputError message={x['rq-ask']} />
                    </div>
                ) : (
                    <Notice tone="info" icon={UserCheck} title="A second person must confirm it here">
                        Someone who reviews emergency access at {HOUSES[house]} types their own witness PIN on this screen.
                    </Notice>
                )}
                {ask === 'ask' && !nobody ? (
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="rq-who">
                                Who is confirming <Req />
                            </Label>
                            <Select value={who || undefined} onValueChange={(v) => (setWho(v), setX({}), setPin(''))}>
                                <SelectTrigger id="rq-who" className="w-full" aria-invalid={!!x['rq-who']}>
                                    <SelectValue placeholder="Choose who" />
                                </SelectTrigger>
                                <SelectContent>
                                    {confirmers.map((n) => (
                                        <SelectItem key={n} value={n}>
                                            {n}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={x['rq-who']} />
                        </div>
                        <WitnessPinInput id="rq-pin" value={pin} onChange={(v) => (setPin(v), setX({}))} error={x['rq-pin']} disabled={!who || tries >= 5} />
                    </div>
                ) : null}
                {ask === 'ask' && ea.second === 'required' && !nobody ? (
                    <p>
                        <Button variant="link" className="h-auto p-0 text-[13px]" onClick={() => (setNobody(true), setWho(''), setPin(''), setX({}))}>
                            Nobody who can confirm is here
                        </Button>
                    </p>
                ) : null}
                {nobody ? (
                    <Notice
                        tone="critical"
                        icon={Phone}
                        live="alert"
                        title="It can’t start without a second person"
                        actions={
                            <Button size="sm" variant="outline" onClick={() => (setNobody(false), setX({}))}>
                                Someone is here now
                            </Button>
                        }
                    >
                        {oncall ? `Call the on-call contact for ${HOUSES[house]}: ${oncall.name}, ${oncall.how.toLowerCase()} — ${oncall.phone}. They can find someone to confirm it, or record the dose if they’re on shift.` : `${HOUSES[house]} has no on-call contact set. Call your manager — and ask for one to be set in Settings › Alerts & access › On-call contacts.`}
                    </Notice>
                ) : null}
            </>
        ),
        check: (
            <>
                <Notice tone="info" icon={Eye} title={`This covers ${person?.pref ?? 'them'} only — not a round or anyone else`}>
                    Controlled medicines still need your own permission and a witness. It ends by itself at {atText(ends)}.
                </Notice>
                <ReviewCard icon={KeyRound} title="Emergency access">
                    <ReviewRow label="For" value={person ? `${person.legal} · ${HOUSES[person.house]}` : '—'} />
                    <ReviewRow label="Why" value={reason ? `${REASONS.find((q) => q.key === reason)!.label} — ${why.trim()}` : '—'} />
                    <ReviewRow label="How long" value={`${fmtMin(Number(mins))} — until ${atText(ends)}`} />
                    {ea.second !== 'off' ? <ReviewRow label="Second person" value={confirmed ? `${confirmed} — PIN checked` : 'None — not asked'} /> : null}
                    <ReviewRow label="Told when it starts" value={told || '—'} />
                </ReviewCard>
                <div className="space-y-2">
                    <label className="flex items-start gap-2.5 text-sm">
                        <Checkbox id="rq-a1" checked={a1} onCheckedChange={(v) => (setA1(v === true), setX({}))} aria-invalid={!!x['rq-a1']} />
                        <span>I’ll only open and record what {person?.pref ?? 'they'} needs right now.</span>
                    </label>
                    <InputError message={x['rq-a1']} />
                    <label className="flex items-start gap-2.5 text-sm">
                        <Checkbox id="rq-a2" checked={a2} onCheckedChange={(v) => (setA2(v === true), setX({}))} aria-invalid={!!x['rq-a2']} />
                        <span>If something goes wrong with a dose, I’ll report it as a medication error.</span>
                    </label>
                    <InputError message={x['rq-a2']} />
                </div>
                {x['rq-save'] ? (
                    <Notice tone="critical" title={x['rq-save'] === cantSaveOffline ? 'You’re offline' : 'Couldn’t save — try again'} live="alert">
                        {x['rq-save'] === cantSaveOffline ? 'Emergency access needs a connection to start. Nothing has started, and what you entered is still here.' : 'Nothing has started, and what you entered is still here. The event log couldn’t be written.'}
                    </Notice>
                ) : null}
            </>
        ),
    };
    const blockedNext = (cur === 'person' && !!existing) || (cur === 'second' && nobody);
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title="Start emergency access"
                description="Who it’s for, why, how long, the second person, then check and start."
                railIcon={KeyRound}
                railTitle="Start emergency access"
                railSub={person ? `${person.pref} · ${HOUSES[person.house]}` : 'For one person you aren’t rostered for'}
                steps={steps.map((q, n) => ({ ...q, disabled: n > step || phase === 'sending' }))}
                stepIndex={Math.min(step, steps.length - 1)}
                onStepClick={(n) => n <= step && setStep(n)}
                pct={Math.round((Math.min(step, steps.length - 1) / steps.length) * 100)}
                footerStart={
                    step > 0 && phase !== 'sending' ? (
                        <Button type="button" variant="ghost" onClick={() => (setStep(step - 1), setX({}))}>
                            <ChevronLeft className="size-4" /> Back
                        </Button>
                    ) : (
                        <Button type="button" variant="outline" onClick={() => (dirty ? setDiscard(true) : onClose())} disabled={phase === 'sending'}>
                            Cancel
                        </Button>
                    )
                }
                footerEnd={
                    cur !== 'check' ? (
                        <Button type="button" onClick={next} disabled={blockedNext}>
                            Continue <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" onClick={next} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <KeyRound className="size-4" />}
                            {phase === 'sending' ? 'Starting…' : 'Start emergency access'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' && made ? (
                        <WizardSuccessPane
                            title="Emergency access has started"
                            blurb={`You can record for ${PEOPLE[made.pid].pref} until ${atText(made.firstEnd)}. ${told ? `${told} ${told.includes(' and ') ? 'have' : 'has'} been told.` : ''}`}
                            actions={
                                <>
                                    {draft ? (
                                        <Button type="button" autoFocus onClick={() => s.go('/emar/mar', { person: made.pid, open: `record:${made.pid}:${draft.orderId}`, view: undefined })}>
                                            Back to the {orderOf(draft.orderId).med.toLowerCase()} dose
                                        </Button>
                                    ) : null}
                                    <Button type="button" variant={draft ? 'outline' : 'default'} autoFocus={!draft} onClick={() => (onClose(), s.go('/emar/mar', { person: made.pid, open: undefined, view: undefined }))}>
                                        Open {PEOPLE[made.pid].pref}’s MAR
                                    </Button>
                                    <Button type="button" variant="outline" onClick={onClose}>
                                        Done
                                    </Button>
                                </>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(88vh, 780px)"
            >
                <WizardStepPane>
                    <div className="space-y-4">{body[cur]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog open={discard} variant="default" onClose={() => setDiscard(false)} onConfirm={() => (setDiscard(false), onClose())} title="Discard this?" description="Emergency access hasn’t started." confirmText="Discard" cancelText="Keep going" />
        </>
    );
}

/* ═════════════ One grant ═════════════ */
export function GrantDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const p = s.route.persona;
    const scn = s.route.scenario;
    const ea = eaNow(s.rt, scn);
    const g = findGrant(s.rt, scn, id)!;
    const person = PEOPLE[g.pid];
    const me = PERSONAS[p];
    const block = reviewBlock(p, g);
    const own = g.by === me.name;
    const timeline: { at: At; what: string; icon: LucideIcon }[] = [
        { at: g.start, what: `Started by ${g.by}${g.second ? ` — confirmed by ${g.second.name} with their PIN` : ''}`, icon: KeyRound },
        ...g.activity.map((a) => ({ at: a.at, what: a.what, icon: a.kind === 'dose' ? Pill : Eye })),
        ...g.extensions.map((e) => ({ at: e.at, what: `Extended to ${atText(e.to)} — “${e.why}”`, icon: Timer })),
        ...(isLive(g) ? [] : [{ at: g.ended?.at ?? endOf(g), what: !g.ended || g.ended.how === 'ranOut' ? 'Its time ran out' : g.ended.how === 'done' ? `Ended early by ${g.ended.by}` : `Ended by ${g.ended.by} — “${(g.ended.why ?? '').replace(/\.$/, '')}”`, icon: XCircle }]),
    ].sort((a, b) => toMin(a.at) - toMin(b.at));
    return (
        <Modal
            width={720}
            title={`${g.id} — emergency access for ${person.pref}`}
            description={`${person.legal} · ${HOUSES[person.house]} · ${isLive(g) ? `running until ${atText(endOf(g))}` : HOW_ENDED(g).charAt(0).toLowerCase() + HOW_ENDED(g).slice(1)}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={() => (onClose(), s.go('/emar/mar', { person: g.pid, open: undefined, view: undefined }))}>
                        Open {person.pref}’s MAR
                    </Button>
                    {isLive(g) && own ? (
                        <Button variant="outline" onClick={() => s.set({ open: `done:${g.id}` })}>
                            I’m done
                        </Button>
                    ) : null}
                    {isLive(g) && own && canExtendNow(g) ? <Button onClick={() => s.set({ open: `extend:${g.id}` })}>Extend</Button> : null}
                    {isLive(g) && !own && PERSONAS[p].perms.includes('ea.end') ? (
                        <Button variant="destructive" onClick={() => s.set({ open: `end:${g.id}` })}>
                            End their access
                        </Button>
                    ) : null}
                    {needsReview(g) && !block ? <Button onClick={() => s.set({ open: `review:${g.id}` })}>Review it</Button> : null}
                    {!isLive(g) && !needsReview(g) && !block ? (
                        <Button variant="outline" onClick={() => s.set({ open: `correct:${g.id}` })}>
                            Correct the review
                        </Button>
                    ) : null}
                    <Button variant={needsReview(g) && !block ? 'outline' : 'default'} onClick={onClose}>
                        Close
                    </Button>
                </>
            }
        >
            <KV
                rows={[
                    ['Used by', `${g.by}`],
                    ['Why', `${REASONS.find((q) => q.key === g.reason)!.label} — ${g.why}`],
                    ['Started', atLong(g.start)],
                    ['Second person', g.second ? `${g.second.name} — confirmed with their PIN at ${time12(g.second.at)}` : 'None'],
                    [isLive(g) ? 'Ends' : 'Ended', isLive(g) ? `${atText(endOf(g))} — ${fmtMin(minutesLeft(g))} left · never past ${atText(latestOf(g))}` : HOW_ENDED(g)],
                    ['Covers', `${person.pref} only — not a round or anyone else`],
                ]}
            />
            <ReviewCard icon={CalendarClock} title="What was done">
                {timeline.map((t, i) => (
                    <ReviewRow key={i} label={atText(t.at)} value={t.what} />
                ))}
                {!g.activity.some((a) => a.kind !== 'viewed') ? <p className="text-caption pt-1.5">Nothing was recorded under it.</p> : null}
            </ReviewCard>
            <ReviewCard icon={ClipboardCheck} title="Review">
                {g.reviews.length ? (
                    g.reviews.map((rv, i) => <ReviewLine key={i} r={rv} superseded={i < g.reviews.length - 1} />)
                ) : isLive(g) ? (
                    <p className="text-sm">It can be reviewed once it ends.</p>
                ) : (
                    <p className="text-sm">{isOverdue(g, ea) ? `Overdue — it was due ${atText(reviewDueOf(g, ea)!)}.` : `Due ${atText(reviewDueOf(g, ea)!)}.`}</p>
                )}
                {block === 'own' || block === 'confirmed' ? <p className="text-caption pt-1.5">{REVIEW_BLOCK_TEXT[block]}</p> : null}
            </ReviewCard>
        </Modal>
    );
}
function ReviewLine({ r, superseded }: { r: EaReview; superseded?: boolean }) {
    return (
        <div className="border-b border-border py-1.5 last:border-0">
            <p className="text-[13px] font-semibold">
                {OUTCOME_LABEL[r.outcome]} — {r.by}, {atText(r.at)}
                {superseded ? <span className="font-normal text-muted-foreground"> · corrected later</span> : null}
            </p>
            {r.correction ? <p className="text-caption">Correction: {r.correction}</p> : null}
            {r.notes ? <p className="text-[13px] text-muted-foreground">{r.notes}</p> : null}
            {r.link ? <p className="text-caption">Linked: {r.link}</p> : null}
        </div>
    );
}

/* ═════════════ Review it, or correct a review (Q6) ═════════════ */
export function ReviewDialog({ id, mode, onClose, returnFocus }: { id: string; mode: 'review' | 'correct'; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const scn = s.route.scenario;
    const me = PERSONAS[p];
    const g = findGrant(s.rt, scn, id)!;
    const person = PEOPLE[g.pid];
    const prev = currentReview(g);
    const [outcome, setOutcome] = useState<ReviewOutcome | ''>('');
    const [notes, setNotes] = useState('');
    const [link, setLink] = useState('none');
    const [why, setWhy] = useState('');
    const [x, setX] = useState<Record<string, string>>({});
    const errs = ERRORS.filter((e) => e.pid === g.pid);
    function save() {
        const v: Record<string, string> = {};
        if (mode === 'correct' && why.trim().length < 10) v['rv-why'] = 'Say why it’s being corrected — at least 10 characters.';
        if (!outcome) v['rv-outcome'] = 'Choose justified or not justified.';
        if (outcome === 'notJustified' && notes.trim().length < 10) v['rv-notes'] = 'Say what wasn’t needed — at least 10 characters.';
        setX(v);
        if (Object.keys(v).length) return focusFirst(v);
        if (scn === 'offline') return setX({ 'rv-save': cantSaveOffline });
        if (scn === 'logdown') return setX({ 'rv-save': COULDNT_SAVE });
        const e = link === 'none' ? undefined : ERRORS.find((q) => q.id === link);
        const rv: EaReview = { by: me.name, at: stampAt(), outcome: outcome as ReviewOutcome, ...(notes.trim() ? { notes: notes.trim() } : {}), ...(e ? { link: `${e.id} — ${TYPE_LABEL[e.type].toLowerCase()}` } : {}), ...(mode === 'correct' ? { correction: why.trim() } : {}) };
        s.update((rt) => patchGrant(rt, g, { reviews: [...g.reviews, rv] }, { what: `Emergency access ${mode === 'correct' ? 'review corrected' : 'reviewed'} — ${recordOf(g.pid)}`, detail: `${g.id} · ${OUTCOME_LABEL[rv.outcome]}${rv.correction ? ` — ${rv.correction}` : ''}${rv.notes ? ` · ${rv.notes}` : ''}`, by: me.name }));
        s.toast('success', `${g.id} ${mode === 'correct' ? 'review corrected' : 'reviewed'} — ${OUTCOME_LABEL[rv.outcome].toLowerCase()}. Recorded in the audit trail${mode === 'correct' ? '; the first review stays visible' : ''}.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`${mode === 'correct' ? 'Correct the review of' : 'Review'} ${g.id} — ${person.pref}`}
            description={`${g.by} · ${atLong(g.start)} · ${HOW_ENDED(g)}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>{mode === 'correct' ? 'Save the correction' : 'Save the review'}</Button>
                </>
            }
        >
            <ReviewCard icon={MessageSquareText} title="Why they used it">
                <ReviewRow label="Reason" value={`${REASONS.find((q) => q.key === g.reason)!.label} — ${g.why}`} />
                <ReviewRow label="Second person" value={g.second ? `${g.second.name} — PIN checked` : 'None'} />
                <ReviewRow label="Length" value={`${fmtMin(toMin(endOf(g)) - toMin(g.start))}${g.extensions.length ? `, after ${g.extensions.length} ${g.extensions.length === 1 ? 'extension' : 'extensions'}` : ''}`} />
            </ReviewCard>
            <ReviewCard icon={CalendarClock} title="What was done">
                {g.activity.map((a, i) => (
                    <ReviewRow
                        key={i}
                        label={atText(a.at)}
                        value={
                            <Button variant="link" className="h-auto p-0 text-right text-[13px] whitespace-normal" onClick={() => s.toast('info', `Opens ${person.pref}’s MAR at ${atText(a.at)} (P02) — outside this preview.`)}>
                                {a.what}
                            </Button>
                        }
                    />
                ))}
                {!g.activity.some((a) => a.kind !== 'viewed') ? <p className="text-caption pt-1.5">Nothing was recorded under it.</p> : null}
            </ReviewCard>
            {mode === 'correct' && prev ? (
                <ReviewCard icon={ClipboardCheck} title="The review now">
                    <ReviewLine r={prev} />
                    <p className="text-caption pt-1.5">It stays visible. Your correction is added with today’s date.</p>
                </ReviewCard>
            ) : null}
            {mode === 'correct' ? (
                <div className="space-y-1.5">
                    <Label htmlFor="rv-why">
                        Why it’s being corrected <Req />
                    </Label>
                    <Input id="rv-why" value={why} aria-invalid={!!x['rv-why']} onChange={(ev) => (setWhy(ev.target.value), setX({}))} placeholder="For example: the roster shows no one was signed off that afternoon" />
                    <InputError message={x['rv-why']} />
                </div>
            ) : null}
            <div className="space-y-2">
                <Label id="rv-outcome-l">
                    {mode === 'correct' ? 'The corrected outcome' : 'Was it justified?'} <Req />
                </Label>
                <div id="rv-outcome" tabIndex={-1}>
                    <TilePicker
                        labelledBy="rv-outcome-l"
                        value={outcome || null}
                        invalid={!!x['rv-outcome'] && !outcome}
                        onChange={(k) => (setOutcome(k as ReviewOutcome), setX({}))}
                        tiles={[
                            { key: 'justified', label: 'Justified', description: 'It was needed, and only what was needed was done', icon: Check },
                            { key: 'notJustified', label: 'Not justified', description: 'It wasn’t needed, or more was done than needed', icon: AlertOctagon },
                        ]}
                    />
                </div>
                <InputError message={x['rv-outcome']} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="rv-notes">
                    {outcome === 'notJustified' ? (
                        <>
                            What wasn’t needed <Req />
                        </>
                    ) : (
                        <>
                            Notes <span className="font-normal text-muted-foreground">(optional)</span>
                        </>
                    )}
                </Label>
                <Textarea id="rv-notes" rows={3} value={notes} aria-invalid={!!x['rv-notes']} onChange={(ev) => (setNotes(ev.target.value), setX({}))} />
                <InputError message={x['rv-notes']} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="rv-link">
                    Link a medication error <span className="font-normal text-muted-foreground">(optional)</span>
                </Label>
                <Select value={link} onValueChange={setLink}>
                    <SelectTrigger id="rv-link" className="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="none">None</SelectItem>
                        {errs.map((e) => (
                            <SelectItem key={e.id} value={e.id}>
                                {e.id} — {TYPE_LABEL[e.type].toLowerCase()}, {e.occurred}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
            {outcome === 'notJustified' ? (
                <Notice tone="neutral" icon={AlertOctagon} title={`Talk it through with ${g.by}`} actions={<Button size="sm" variant="outline" onClick={() => s.toast('info', 'Opens P08b’s Report a medication error — outside this preview.')}>Report a medication error</Button>}>
                    If a dose went wrong, report a medication error — it’s separate from this review.
                </Notice>
            ) : null}
            {x['rv-save'] ? (
                <Notice tone="critical" title={x['rv-save'] === cantSaveOffline ? 'You’re offline' : 'Couldn’t save — try again'} live="alert">
                    {x['rv-save'] === cantSaveOffline ? 'The review needs a connection. Nothing is lost — keep this open and save when you reconnect.' : 'Nothing was recorded, and what you entered is still here. The event log couldn’t be written.'}
                </Notice>
            ) : null}
            <p className="text-caption">Your review, with your name and the time, goes in the audit trail. A review is never overwritten — a correction is added beside it.</p>
        </Modal>
    );
}

/* ═════════════ Extend (Q5) — the person using it, once 10 minutes or less remain ═════════════ */
export function ExtendDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const scn = s.route.scenario;
    const g = findGrant(s.rt, scn, id)!;
    const person = PEOPLE[g.pid];
    const to = toMin(plusMin(endOf(g), POLICY.ext)) > toMin(latestOf(g)) ? latestOf(g) : plusMin(endOf(g), POLICY.ext);
    const [why, setWhy] = useState('');
    const [x, setX] = useState<Record<string, string>>({});
    function save() {
        if (why.trim().length < 5) return (setX({ 'ex-why': 'Say why in a few words.' }), focusFirst({ 'ex-why': '' }));
        if (scn === 'offline') return setX({ 'ex-save': cantSaveOffline });
        if (scn === 'logdown') return setX({ 'ex-save': COULDNT_SAVE });
        s.update((rt) => patchGrant(rt, g, { extensions: [...g.extensions, { at: stampAt(), to, why: why.trim() }] }, { what: `Emergency access extended — ${recordOf(g.pid)}`, detail: `${g.id} · until ${time12(to.hm)} · ${why.trim()}`, by: g.by }));
        s.toast('success', `Extended — your emergency access for ${person.pref} now ends ${atText(to)}.`);
        onClose();
    }
    return (
        <Modal
            title={`Extend emergency access for ${person.pref}`}
            description={`${g.id} · it ends ${atText(endOf(g))}, in ${fmtMin(minutesLeft(g))}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>
                        <Timer className="size-4" /> Extend to {atText(to)}
                    </Button>
                </>
            }
        >
            <KV
                rows={[
                    ['Ends now', atText(endOf(g))],
                    ['Extended to', `${atText(to)} (+${fmtMin(toMin(to) - toMin(endOf(g)))})`],
                    ['Never past', `${atText(latestOf(g))} — ${fmtMin(POLICY.max)} from when it started`],
                ]}
            />
            <div className="space-y-1.5">
                <Label htmlFor="ex-why">
                    Why you need longer <Req />
                </Label>
                <Input id="ex-why" value={why} aria-invalid={!!x['ex-why']} onChange={(ev) => (setWhy(ev.target.value), setX({}))} placeholder="For example: relief hasn’t arrived — the 12:00 pm doses are next" />
                <InputError message={x['ex-why']} />
            </div>
            {x['ex-save'] ? (
                <Notice tone="critical" title={x['ex-save'] === cantSaveOffline ? 'You’re offline' : 'Couldn’t save — try again'} live="alert">
                    {x['ex-save'] === cantSaveOffline ? 'Extending needs a connection. Your access still ends at the time above.' : 'It wasn’t extended — it still ends at the time above. The event log couldn’t be written.'}
                </Notice>
            ) : null}
            <p className="text-caption">Reviewers see each extension and why.</p>
        </Modal>
    );
}

/* ═════════════ I’m done (the person using it — non-destructive, no reason) ═════════════ */
export function DoneDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const scn = s.route.scenario;
    const g = findGrant(s.rt, scn, id)!;
    const person = PEOPLE[g.pid];
    const [err, setErr] = useState('');
    const end = () => {
        if (scn === 'offline') return setErr('You’re offline — it can’t be ended from here. It still ends by itself at its time.');
        if (scn === 'logdown') return setErr('Couldn’t save — try again. It’s still running.');
        s.update((rt) => patchGrant(rt, g, { ended: { how: 'done', at: stampAt(), by: g.by } }, { what: `Emergency access closed — ${recordOf(g.pid)}`, detail: `${g.id} · Ended early by ${g.by}`, by: g.by }));
        s.toast('success', `Your emergency access for ${person.pref} has ended. It goes to reviewers.`);
        onClose();
    };
    return (
        <>
            <ConfirmDialog open={!err} variant="default" onClose={onClose} onConfirm={end} title={`End emergency access for ${person.pref} now?`} description={`You won’t be able to record for ${person.pref} after this. It goes to reviewers.`} confirmText="End it now" cancelText="Keep it running" />
            {err ? (
                <Modal title="It’s still running" description={err} onClose={onClose}>
                    <p className="text-sm">{`Your access for ${person.pref} ends at ${atText(endOf(g))}.`}</p>
                </Modal>
            ) : null}
        </>
    );
}

/* ═════════════ End someone else’s (a reason, and a destructive confirm) ═════════════ */
export function EndDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const scn = s.route.scenario;
    const me = PERSONAS[p];
    const g = findGrant(s.rt, scn, id)!;
    const person = PEOPLE[g.pid];
    const [why, setWhy] = useState('');
    const [x, setX] = useState<Record<string, string>>({});
    const [confirm, setConfirm] = useState(false);
    const check = () => {
        if (why.trim().length < 10) return (setX({ 'en-why': 'Say why — they’re told this. At least 10 characters.' }), focusFirst({ 'en-why': '' }));
        if (scn === 'offline') return setX({ 'en-save': cantSaveOffline });
        setConfirm(true);
    };
    const end = () => {
        if (scn === 'logdown') return (setConfirm(false), setX({ 'en-save': COULDNT_SAVE }));
        s.update((rt) => patchGrant(rt, g, { ended: { how: 'endedBy', at: stampAt(), by: me.name, why: why.trim() } }, { what: `Emergency access closed — ${recordOf(g.pid)}`, detail: `${g.id} · Ended by ${me.name}: ${why.trim()}`, by: me.name }));
        s.toast('success', `${g.by}’s emergency access for ${person.pref} has ended. They’ve been told, with your reason.`);
        onClose();
    };
    return (
        <>
            <Modal
                title={`End ${g.by}’s emergency access`}
                description={`${g.id} · for ${person.legal} · running until ${atText(endOf(g))}`}
                onClose={onClose}
                onCloseAutoFocus={restore(returnFocus)}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={check}>
                            End their access
                        </Button>
                    </>
                }
            >
                <div className="space-y-1.5">
                    <Label htmlFor="en-why">
                        Why you’re ending it <Req />
                    </Label>
                    <Textarea id="en-why" rows={3} value={why} aria-invalid={!!x['en-why']} onChange={(ev) => (setWhy(ev.target.value), setX({}))} placeholder="For example: Mere Kahu is on shift and can give the dose" />
                    <InputError message={x['en-why']} />
                    <p className="text-caption">{g.by} is told straight away, with your reason. It goes to reviewers — someone other than you reviews it.</p>
                </div>
                {x['en-save'] ? (
                    <Notice tone="critical" title={x['en-save'] === cantSaveOffline ? 'You’re offline' : 'Couldn’t save — try again'} live="alert">
                        {x['en-save'] === cantSaveOffline ? 'Ending it needs a connection. It’s still running.' : 'It’s still running. The event log couldn’t be written.'}
                    </Notice>
                ) : null}
            </Modal>
            <ConfirmDialog open={confirm} onClose={() => setConfirm(false)} onConfirm={end} title={`End ${g.by}’s access for ${person.pref}?`} description="It ends now. Anything they haven’t saved won’t be." confirmText="End their access" cancelText="Go back" />
        </>
    );
}

/* ═════════════ Acknowledge repeat use (Q7) ═════════════ */
export function AckDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const scn = s.route.scenario;
    const me = PERSONAS[p];
    const flag = flagsOf(s.rt, scn).find((q) => q.id === id)!;
    const [why, setWhy] = useState('');
    const [x, setX] = useState<Record<string, string>>({});
    const save = () => {
        if (why.trim().length < 10) return (setX({ 'ak-why': 'Say what you found — at least 10 characters.' }), focusFirst({ 'ak-why': '' }));
        if (scn === 'offline') return setX({ 'ak-save': cantSaveOffline });
        if (scn === 'logdown') return setX({ 'ak-save': COULDNT_SAVE });
        s.update((rt) => ({ ...rt, acks: { ...rt.acks, [flag.id]: { by: me.name, at: stampAt(), why: why.trim() } }, events: [...rt.events, rtEvent(rt, { house: 'kowhai', kind: 'access', what: `Repeat use acknowledged — ${flag.who}`, detail: `${flag.id} · ${why.trim()}`, by: me.name, cd: false })] }));
        s.toast('success', `Acknowledged. It comes back if ${flag.who} uses emergency access again.`);
        onClose();
    };
    return (
        <Modal
            title={`Acknowledge repeat use — ${flag.who}`}
            description={`${flag.grants.length} grants within 7 days · flagged ${atText(flag.raised)}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Acknowledge</Button>
                </>
            }
        >
            <KV rows={[['Grants', flag.grants.join(', ')], ['What it does', 'Reports only — it never blocks anyone']]} />
            <div className="space-y-1.5">
                <Label htmlFor="ak-why">
                    What you found <Req />
                </Label>
                <Textarea id="ak-why" rows={3} value={why} aria-invalid={!!x['ak-why']} onChange={(ev) => (setWhy(ev.target.value), setX({}))} placeholder="For example: talked with Rangi — the roster gaps are being fixed" />
                <InputError message={x['ak-why']} />
            </div>
            {x['ak-save'] ? (
                <Notice tone="critical" title={x['ak-save'] === cantSaveOffline ? 'You’re offline' : 'Couldn’t save — try again'} live="alert">
                    {x['ak-save'] === cantSaveOffline ? 'Acknowledging needs a connection. Nothing is lost — keep this open and save when you reconnect.' : 'Nothing was recorded. The event log couldn’t be written.'}
                </Notice>
            ) : null}
            <p className="text-caption">{`It comes back if ${flag.who} uses emergency access again. Recorded in the audit trail.`}</p>
        </Modal>
    );
}
