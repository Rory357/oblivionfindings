/* A FRAME of P01’s record dialog, showing only P10’s parts (Main, Q5): the
 * live strip while recording under emergency access, and what happens when the
 * access ends before Save — what was entered is kept, nothing is saved, and it
 * says so plainly instead of today’s “You do not have a current assignment for
 * this medication action.” The Fleet Settings `Modal`, TilePicker, the
 * approved DateTimeField and PIN-1’s WitnessPinInput. */
import InputError from '@/components/input-error';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Ban, Check, Hand, KeyRound, ListChecks, Phone } from 'lucide-react';
import { useState } from 'react';
import { HOUSES, MED_RULES, ONCALL, PEOPLE, PERSONAS, STAFF_KOWHAI, STAFF_RIMU, orderOf, readingFor, secondPersonFor, type PersonId } from './data';
import { LiveStrip } from './ea-ui';
import { cantSaveOffline, focusFirst, Req, restore } from './helpers';
import { COULDNT_SAVE } from './dialogs-ea';
import { Modal } from './modal';
import { rtEvent, time12, NOW_HM, TODAY_ISO, stampAt } from './model';
import { myEndedGrant, myLiveGrant, todayRows } from './pages/mar-frame';
import { useStore } from './store';
import { DesignNote, Notice, TilePicker } from './ui';

export function RecordDialog({ pid, orderId, onClose, returnFocus }: { pid: PersonId; orderId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const scn = s.route.scenario;
    const me = PERSONAS[p];
    const person = PEOPLE[pid];
    const o = orderOf(orderId);
    const row = todayRows(s.rt, pid).find((d) => d.order.id === orderId && d.state === 'due');
    const grant = myLiveGrant(s.rt, scn, p, pid);
    const ranOut = myEndedGrant(s.rt, scn, p, pid);
    const draft = s.rt.recordDraft && s.rt.recordDraft.pid === pid && s.rt.recordDraft.orderId === orderId ? s.rt.recordDraft : null;
    const [outcome, setOutcome] = useState(draft?.outcome ?? '');
    const [at, setAt] = useState(draft ? `${TODAY_ISO}T${draft.hm}` : `${TODAY_ISO}T09:10`);
    const [witness, setWitness] = useState('');
    const [pin, setPin] = useState('');
    const [bsl, setBsl] = useState('');
    const [x, setX] = useState<Record<string, string>>({});
    const staff = (person.house === 'kowhai' ? STAFF_KOWHAI : STAFF_RIMU).filter((n) => n !== me.name);
    const oncall = ONCALL[person.house];
    /* P01 v2’s medication rules for this dose: mr1 (a reading) and mr2 (a second person) — the same rules as the downtime pack’s boxes. */
    const need2 = secondPersonFor(orderId);
    const reading = readingFor(orderId);
    function save() {
        const v: Record<string, string> = {};
        if (!outcome) v['rd-outcome'] = 'Choose what happened.';
        if (outcome === 'given' && !/T\d{2}:\d{2}$/.test(at)) v['rd-at'] = 'Choose when it was given.';
        if (outcome === 'given' && at.slice(11) > NOW_HM) v['rd-at'] = 'It can’t be later than now.';
        if (reading && outcome === 'given' && !/^\d{1,2}(\.\d)?$/.test(bsl.trim())) v['rd-bsl'] = `Enter the ${reading.label.charAt(0).toLowerCase()}${reading.label.slice(1)} in ${reading.unit} — for example 6.4.`;
        if (need2 && outcome === 'given' && !witness) v['rd-witness'] = need2 === 'rule' ? 'Choose the second person.' : 'Choose who witnessed it.';
        if (need2 && outcome === 'given' && pin.length !== 6) v['rd-pin'] = 'They type their 6-digit witness PIN.';
        setX(v);
        if (Object.keys(v).length) return focusFirst(v);
        if (ranOut) {
            s.update((rt) => ({ ...rt, recordDraft: { pid, orderId, outcome, hm: at.slice(11) } }));
            return setX({ 'rd-save': 'ended' });
        }
        if (scn === 'logdown') return setX({ 'rd-save': COULDNT_SAVE });
        if (scn === 'offline' && need2) return setX({ 'rd-save': cantSaveOffline });
        const hm = at.slice(11);
        const key = `${orderId}|${row?.time ?? o.times[0]}`;
        s.update((rt) => {
            const ev = rtEvent(rt, { house: person.house, kind: o.cd ? 'controlled' : 'dose', what: `Dose ${outcome === 'given' ? 'given' : outcome} — ${o.med}`, detail: `${time12(row?.time ?? o.times[0])} dose${grant ? ` — under emergency access ${grant.id}` : ''}`, by: me.name, pid, cd: !!o.cd });
            const next = { ...rt, recorded: { ...rt.recorded, [key]: { by: me.name, hm, grant: grant?.id, outcome: scn === 'offline' ? 'queued' : outcome } }, recordDraft: null, events: scn === 'offline' ? rt.events : [...rt.events, ev] };
            if (grant) next.grants = { ...rt.grants, [grant.id]: { ...(rt.grants[grant.id] ?? {}), activity: [...grant.activity, { at: stampAt(), what: `Dose ${outcome === 'given' ? 'given' : outcome} — ${o.med} ${o.strength.split(' ')[0]} ${o.strength.split(' ')[1] ?? ''}, the ${time12(row?.time ?? o.times[0])} dose`.replace('  ', ' '), kind: 'dose' }] } };
            return next;
        });
        s.toast('success', scn === 'offline' ? `Saved on this device — ${o.med} for ${person.pref} is sent when you’re back.${grant ? ` If your emergency access has ended by then, it’s still accepted — it was recorded inside it — and reviewers see “sent after the grant ended”.` : ''}` : `${o.med} recorded for ${person.pref}${grant ? ` — under emergency access ${grant.id}` : ''}.`);
        onClose();
    }
    const ended = x['rd-save'] === 'ended';
    return (
        <Modal
            width={720}
            title={`Record ${o.med} — ${person.pref}`}
            description={`${o.strength} · due ${time12(row?.time ?? o.times[0] ?? '09:00')} · ${HOUSES[person.house]}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save} disabled={ended}>
                        Save
                    </Button>
                </>
            }
        >
            {grant ? <LiveStrip g={grant} compact onExtend={() => s.set({ open: `extend:${grant.id}` })} onDone={() => s.set({ open: `done:${grant.id}` })} /> : null}
            {ranOut && !ended ? (
                <Notice tone="info" icon={KeyRound} title={`Recording under emergency access ${ranOut.id}`}>
                    It covers {person.pref} only.
                </Notice>
            ) : null}
            {ended ? (
                <Notice
                    tone="critical"
                    icon={KeyRound}
                    live="alert"
                    title={`Your emergency access for ${person.pref} ended at 9:10 am, so this wasn’t saved`}
                    actions={
                        <>
                            <Button size="sm" onClick={() => s.set({ open: `request:${pid}` })}>
                                <KeyRound className="size-4" /> Start it again
                            </Button>
                            <Button size="sm" variant="outline" onClick={() => s.toast('info', oncall ? `Call ${oncall.name}, ${HOUSES[person.house]}’s on-call contact — ${oncall.phone}.` : 'Call your manager.')}>
                                <Phone className="size-4" /> Ask someone on shift
                            </Button>
                        </>
                    }
                >
                    What you entered is still here, and it’s kept if you start emergency access again. Or ask someone on shift to record it.
                </Notice>
            ) : null}
            <div className="space-y-2">
                <Label id="rd-outcome-l">
                    What happened <Req />
                </Label>
                <div id="rd-outcome" tabIndex={-1}>
                    <TilePicker
                        labelledBy="rd-outcome-l"
                        value={outcome || null}
                        invalid={!!x['rd-outcome'] && !outcome}
                        onChange={(k) => (setOutcome(k), setX({}))}
                        tiles={[
                            { key: 'given', label: 'Given', description: `${o.strength} as ordered`, icon: Check },
                            { key: 'refused', label: 'Refused', description: `${person.pref} didn’t take it`, icon: Hand },
                            { key: 'withheld', label: 'Withheld', description: 'Not given, for a clinical reason', icon: Ban },
                        ]}
                    />
                </div>
                <InputError message={x['rd-outcome']} />
            </div>
            {(need2 === 'rule' || reading) && outcome !== 'refused' && outcome !== 'withheld' ? (
                <Notice tone="neutral" icon={ListChecks} title="Medication rules for this dose">
                    {[reading ? MED_RULES.mr1.sentence : '', need2 === 'rule' ? MED_RULES.mr2.sentence : ''].filter(Boolean).join(' ')}
                </Notice>
            ) : null}
            {outcome === 'given' ? <DateTimeField id="rd-at" label="Given at" value={at} onChange={(v) => (setAt(v), setX({}))} error={x['rd-at']} /> : null}
            {reading && outcome === 'given' ? (
                <div className="space-y-1.5 sm:max-w-xs">
                    <Label htmlFor="rd-bsl">
                        {reading.label} <Req />
                    </Label>
                    <Input id="rd-bsl" inputMode="decimal" value={bsl} aria-invalid={!!x['rd-bsl']} placeholder={reading.example} onChange={(ev) => (setBsl(ev.target.value), setX({}))} />
                    <p className="text-caption">In {reading.unit}. A medication rule asks for it before saving.</p>
                    <InputError message={x['rd-bsl']} />
                </div>
            ) : null}
            {need2 && outcome === 'given' ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="rd-witness">
                            {need2 === 'rule' ? 'Second person' : 'Witness'} <Req />
                        </Label>
                        <Select value={witness || undefined} onValueChange={(v) => (setWitness(v), setX({}))}>
                            <SelectTrigger id="rd-witness" className="w-full" aria-invalid={!!x['rd-witness']}>
                                <SelectValue placeholder="Choose who" />
                            </SelectTrigger>
                            <SelectContent>
                                {staff.map((n) => (
                                    <SelectItem key={n} value={n}>
                                        {n}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={x['rd-witness']} />
                    </div>
                    <WitnessPinInput id="rd-pin" value={pin} onChange={(v) => (setPin(v), setX({}))} error={x['rd-pin']} atCupboard={need2 === 'controlled'} />
                </div>
            ) : null}
            {x['rd-save'] && !ended ? (
                <Notice tone="critical" title={x['rd-save'] === cantSaveOffline ? 'You’re offline' : 'Couldn’t save — try again'} live="alert">
                    {x['rd-save'] === cantSaveOffline ? `${need2 === 'rule' ? 'A dose that needs a second person' : 'A controlled-medicine entry'} needs a connection. Nothing is lost — keep this open and save when you reconnect, or record it on the paper pack.` : 'Nothing was recorded, and what you entered is still here. The event log couldn’t be written.'}
                </Notice>
            ) : null}
            <DesignNote>A frame of P01’s approved record dialog — only P10’s parts are designed here: the strip while recording under emergency access, and the plain “ended” state that keeps what was entered. {grant ? `Reviewers see this dose on ${grant.id}.` : ''}</DesignNote>
        </Modal>
    );
}
