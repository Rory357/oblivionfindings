/* P10’s downtime dialogs (Main, Q9–Q11): make the downtime pack (with the
 * controlled-register rule and the “Downtime” purpose recorded), record a
 * downtime, enter one dose from the paper (or add an as-needed dose the paper
 * lists), and finish the downtime. The Fleet Settings `Modal`, real
 * ReviewCard/ReviewRow, ConfirmDialog, TilePicker, Select, Input, the
 * approved DateTimeField, FileDropzone, Table and PIN-1’s WitnessPinInput. */
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import { WitnessPinInput } from '@/components/medications/witness-pin-input';
import { Button } from '@/components/ui/button';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Table, TableBody, TableCell, TableHead, TableHeader, TableRow } from '@/components/ui/table';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { Ban, Check, FileText, Hand, LockKeyhole, PenLine, Printer } from 'lucide-react';
import { useState } from 'react';
import { HOUSES, ORDERS, PEOPLE, PERSONAS, STAFF_KOWHAI, STAFF_RIMU, orderOf, type Downtime, type ExportMade, type House, type PaperItem, type PersonId } from './data';
import { COULDNT_SAVE } from './dialogs-ea';
import { cantSaveOffline, focusFirst, Req, restore, stamp } from './helpers';
import { Modal } from './modal';
import { allDowntimes, canPack, cdView, housesOf, labelIso, rtEvent, slotsIn, stampAt, time12, toMin, TODAY_ISO, NOW, type PaperEntry } from './model';
import { dtWhen } from './pages/downtime';
import { useStore } from './store';
import { KV, Notice, TilePicker } from './ui';

const staffAt = (h: House) => (h === 'kowhai' ? STAFF_KOWHAI : STAFF_RIMU);
const OUTCOME_TILES = [
    { key: 'given', label: 'Given', description: 'The paper says it was given', icon: Check },
    { key: 'refused', label: 'Refused', description: 'The paper says they didn’t take it', icon: Hand },
    { key: 'withheld', label: 'Withheld', description: 'The paper says it was held back', icon: Ban },
];

/* ═════════════ The downtime pack (Q9, Q10 + Main’s refinement) ═════════════ */
export function PackDialog({ onClose, returnFocus }: { onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const scn = s.route.scenario;
    const houses = housesOf(p);
    const [house, setHouse] = useState<House | ''>(houses.length === 1 ? houses[0] : '');
    const [day, setDay] = useState<'today' | 'tomorrow'>('today');
    const [x, setX] = useState<Record<string, string>>({});
    const cd = cdView(p);
    const people = house ? (Object.keys(PEOPLE) as PersonId[]).filter((q) => PEOPLE[q].house === house) : [];
    const cdOrders = ORDERS.filter((o) => o.cd && people.includes(o.pid));
    const dayText = day === 'today' ? 'Mon 28 Sep' : 'Tue 29 Sep';
    function make() {
        if (!house) return (setX({ 'pk-house': 'Choose the house.' }), focusFirst({ 'pk-house': '' }));
        if (scn === 'offline') return setX({ 'pk-save': cantSaveOffline });
        if (scn === 'logdown') return setX({ 'pk-save': COULDNT_SAVE });
        const made: ExportMade = { id: `EX-${119 + s.rt.exports.length}`, what: 'Downtime pack (PDF)', detail: `${HOUSES[house]} · ${dayText}${cd ? '' : ' · without controlled register pages'}`, by: me.name, at: stamp(), day: TODAY_ISO, hm: '09:12', purpose: 'Downtime — a paper copy in case the system is down', house };
        s.update((rt) => ({ ...rt, exports: [made, ...rt.exports], events: [...rt.events, rtEvent(rt, { house, kind: 'export', what: `Export made — ${made.what}`, detail: `${made.detail} · purpose: ${made.purpose}`, by: me.name, cd: false })] }));
        s.toast('success', `The downtime pack for ${HOUSES[house]}, ${dayText}, is ready and kept on this device for when you’re offline — recorded as ${made.id}. (Nothing downloads in the preview.)`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title="Make the downtime pack"
            description="Everything staff need to record on paper if the system or the internet is down — for one house, today or tomorrow."
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={make} disabled={!canPack(p)}>
                        <Printer className="size-4" /> Make the pack
                    </Button>
                </>
            }
        >
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="pk-house">
                        House <Req />
                    </Label>
                    <Select value={house || undefined} onValueChange={(v) => (setHouse(v as House), setX({}))}>
                        <SelectTrigger id="pk-house" className="w-full" aria-invalid={!!x['pk-house']}>
                            <SelectValue placeholder="Choose the house" />
                        </SelectTrigger>
                        <SelectContent>
                            {houses.map((h) => (
                                <SelectItem key={h} value={h}>
                                    {HOUSES[h]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={x['pk-house']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="pk-day">Day</Label>
                    <Select value={day} onValueChange={(v) => setDay(v as 'today' | 'tomorrow')}>
                        <SelectTrigger id="pk-day" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="today">Today — Mon 28 Sep</SelectItem>
                            <SelectItem value="tomorrow">Tomorrow — Tue 29 Sep</SelectItem>
                        </SelectContent>
                    </Select>
                </div>
            </div>
            <ReviewCard icon={FileText} title="What’s in it">
                <ReviewRow label="Recording sheets" value="Every scheduled dose for each person — time, dose, route and instructions — with blank “given at”, initials and witness boxes" />
                <ReviewRow label="As-needed medicines" value="Each with its limits — the most in 24 hours and the gap between doses" />
                <ReviewRow label="Allergies" value="On every person’s page" />
                <ReviewRow label="Round sheets" value="Built from the scheduled doses — not only the ones already recorded" />
                <ReviewRow label="Controlled register pages" value={cd ? (cdOrders.length ? `${cdOrders.map((o) => o.med).join(', ')} — the balance at 9:12 am, then blank rows` : 'None — no controlled medicines at this house') : 'Not included. In their place: “Controlled register pages need controlled-medicine access — ask the house lead.”'} />
                <ReviewRow label="How to record on paper" value="One page — and how it’s entered afterwards" />
                <ReviewRow label="On every page" value={`Printed Mon 28 Sep, 9:12 am by ${me.name} — for ${dayText} only. Check for changes before each round.`} />
            </ReviewCard>
            {!cd ? (
                <Notice tone="neutral" icon={LockKeyhole} title="The controlled register pages need controlled-medicine access">
                    The rest of the pack prints. A line in their place says to ask the house lead.
                </Notice>
            ) : null}
            {house ? <FirstPage house={house} /> : null}
            <KV rows={[['Purpose', 'Downtime — a paper copy in case the system is down (recorded)']]} />
            {x['pk-save'] ? (
                <Notice tone="critical" title={x['pk-save'] === cantSaveOffline ? 'You’re offline' : 'Couldn’t save — try again'} live="alert">
                    {x['pk-save'] === cantSaveOffline ? 'The pack is made while you’re online — make it at the start of each day, so it’s ready if the connection goes. Use the printed copy kept in the house for now.' : 'Nothing was made or recorded. The event log couldn’t be written.'}
                </Notice>
            ) : null}
            <p className="text-caption">The pack, your name, the purpose and the time are recorded in the audit trail. It’s kept on this device, so Meds today can open it when you’re offline.</p>
        </Modal>
    );
}
/** A preview of the pack’s first page: the recording sheet for the first person. */
function FirstPage({ house }: { house: House }) {
    const pid = (Object.keys(PEOPLE) as PersonId[]).find((q) => PEOPLE[q].house === house)!;
    const rows = ORDERS.filter((o) => o.pid === pid && !o.to && !o.prn).flatMap((o) => o.times.map((t) => ({ o, t }))).sort((a, b) => a.t.localeCompare(b.t));
    return (
        <section aria-label="The first page" className="space-y-1.5">
            <p className="text-sm font-semibold">The first page — {PEOPLE[pid].legal}</p>
            <div className="overflow-x-auto rounded-lg border">
                <Table>
                    <TableHeader>
                        <TableRow>
                            <TableHead>Due</TableHead>
                            <TableHead>Medicine</TableHead>
                            <TableHead>Given at</TableHead>
                            <TableHead>Initials</TableHead>
                            <TableHead>Witness</TableHead>
                        </TableRow>
                    </TableHeader>
                    <TableBody>
                        {rows.map(({ o, t }) => (
                            <TableRow key={`${o.id}${t}`}>
                                <TableCell className="text-[12.5px]">{time12(t)}</TableCell>
                                <TableCell className="text-[12.5px] whitespace-normal">
                                    {o.cd ? 'Controlled — see its register page' : `${o.med} ${o.strength}`}
                                </TableCell>
                                <TableCell className="text-[12.5px] text-muted-foreground">______</TableCell>
                                <TableCell className="text-[12.5px] text-muted-foreground">____</TableCell>
                                <TableCell className="text-[12.5px] text-muted-foreground">{o.cd ? '____' : '—'}</TableCell>
                            </TableRow>
                        ))}
                    </TableBody>
                </Table>
            </div>
        </section>
    );
}

/* ═════════════ Record a downtime ═════════════ */
export function DeclareDialog({ onClose, returnFocus }: { onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const scn = s.route.scenario;
    const houses = housesOf(p);
    const [house, setHouse] = useState<House | ''>(houses.length === 1 ? houses[0] : '');
    const [from, setFrom] = useState('');
    const [to, setTo] = useState('');
    const [why, setWhy] = useState('');
    const [files, setFiles] = useState<string[]>([]);
    const [x, setX] = useState<Record<string, string>>({});
    const ok = (v: string) => /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(v);
    const at = (v: string) => ({ day: v.slice(0, 10), hm: v.slice(11) });
    function save() {
        const v: Record<string, string> = {};
        if (!house) v['dc-house'] = 'Choose the house.';
        if (!ok(from)) v['dc-from'] = 'Choose when it started.';
        if (!ok(to)) v['dc-to'] = 'Choose when it ended.';
        if (ok(from) && ok(to) && toMin(at(to)) <= toMin(at(from))) v['dc-to'] = 'It must end after it started.';
        if (ok(to) && toMin(at(to)) > toMin(NOW)) v['dc-to'] = 'It can’t end later than now. Record it once it’s over.';
        if (why.trim().length < 5) v['dc-why'] = 'Say what went down.';
        setX(v);
        if (Object.keys(v).length) return focusFirst(v);
        if (scn === 'offline') return setX({ 'dc-save': cantSaveOffline });
        if (scn === 'logdown') return setX({ 'dc-save': COULDNT_SAVE });
        const pids = (Object.keys(PEOPLE) as PersonId[]).filter((q) => PEOPLE[q].house === house);
        const start = at(from);
        const end = at(to);
        const slots = slotsIn(start.day, end.day, pids).counted.filter((sl) => sl.outcome === 'notRecorded' && toMin({ day: sl.day, hm: sl.time }) >= toMin(start) && toMin({ day: sl.day, hm: sl.time }) <= toMin(end));
        const id = `DT-${5 + s.rt.newDowntimes.length}`;
        const d: Downtime = { id, house: house as House, start, end, why: why.trim(), declared: { by: me.name, at: stampAt() }, items: slots.map((sl, i) => ({ id: `${id}-${i + 1}`, pid: sl.pid, orderId: sl.orderId, source: 'slot' as const, due: sl.time, paper: { outcome: 'given' as const, hm: sl.time, by: '—' } })), files };
        s.update((rt) => ({ ...rt, newDowntimes: [...rt.newDowntimes, d], events: [...rt.events, rtEvent(rt, { house: house as House, kind: 'downtime', what: `Downtime recorded — ${HOUSES[house as House]}`, detail: `${dtWhen(d)} · ${d.why} · ${d.items.length} paper records to enter`, by: me.name, cd: false })] }));
        s.toast('success', `${id} recorded. ${d.items.length ? `${d.items.length} doses in that time have nothing recorded — enter them from the paper.` : 'No scheduled dose fell in that time — add any as-needed doses the paper lists.'}`);
        s.set({ open: undefined, dt: id });
    }
    return (
        <Modal
            width={720}
            title="Record a downtime"
            description="When the system or the internet was down and staff recorded on paper. Afterwards, every dose on the paper is entered."
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Record the downtime</Button>
                </>
            }
        >
            <div className="space-y-1.5">
                <Label htmlFor="dc-house">
                    House <Req />
                </Label>
                <Select value={house || undefined} onValueChange={(v) => (setHouse(v as House), setX({}))}>
                    <SelectTrigger id="dc-house" className="w-full" aria-invalid={!!x['dc-house']}>
                        <SelectValue placeholder="Choose the house" />
                    </SelectTrigger>
                    <SelectContent>
                        {houses.map((h) => (
                            <SelectItem key={h} value={h}>
                                {HOUSES[h]}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={x['dc-house']} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <DateTimeField id="dc-from" label="It started" value={from} onChange={(v) => (setFrom(v), setX({}))} error={x['dc-from']} />
                <DateTimeField id="dc-to" label="It ended" value={to} onChange={(v) => (setTo(v), setX({}))} error={x['dc-to']} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="dc-why">
                    What went down <Req />
                </Label>
                <Input id="dc-why" value={why} aria-invalid={!!x['dc-why']} onChange={(ev) => (setWhy(ev.target.value), setX({}))} placeholder="For example: the internet was down at the house" />
                <InputError message={x['dc-why']} />
            </div>
            <div className="space-y-1.5">
                <Label id="dc-files-l">
                    The paper sheets <span className="font-normal text-muted-foreground">(optional)</span>
                </Label>
                <FileDropzone aria-labelledby="dc-files-l" title="Add a photo or scan of the paper" hint="Images or PDF" accept="image/*,application/pdf" onFiles={(fs) => setFiles([...files, ...fs.map((f) => f.name)])} />
                {files.length ? <p className="text-caption">{files.join(', ')}</p> : null}
            </div>
            {x['dc-save'] ? (
                <Notice tone="critical" title={x['dc-save'] === cantSaveOffline ? 'You’re offline' : 'Couldn’t save — try again'} live="alert">
                    {x['dc-save'] === cantSaveOffline ? 'Record the downtime once you’re back online. Keep the paper sheets together until then.' : 'Nothing was recorded, and what you entered is still here. The event log couldn’t be written.'}
                </Notice>
            ) : null}
            <p className="text-caption">Every scheduled dose in that time with nothing recorded is listed to enter. As-needed doses the paper lists are added one by one.</p>
        </Modal>
    );
}

/* ═════════════ Enter one dose from the paper (or add one the paper lists) ═════════════ */
export function PaperEntryDialog({ itemId, addTo, onClose, returnFocus }: { itemId?: string; addTo?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const scn = s.route.scenario;
    const all = allDowntimes(s.rt, scn);
    const d = addTo ? all.find((q) => q.id === addTo)! : all.find((q) => q.items.some((i) => i.id === itemId))!;
    const item = itemId ? d.items.find((i) => i.id === itemId) : undefined;
    const housePeople = (Object.keys(PEOPLE) as PersonId[]).filter((q) => PEOPLE[q].house === d.house);
    const [pid, setPid] = useState<PersonId | ''>(item?.pid ?? '');
    const [orderId, setOrderId] = useState(item?.orderId ?? '');
    const [outcome, setOutcome] = useState('');
    const [time, setTime] = useState(`${d.start.day}T`);
    const [givenBy, setGivenBy] = useState('');
    const [note, setNote] = useState('');
    const [witness, setWitness] = useState('');
    const [pin, setPin] = useState('');
    const [x, setX] = useState<Record<string, string>>({});
    const o = orderId ? orderOf(orderId) : null;
    const prnOrders = pid ? ORDERS.filter((q) => q.pid === pid && q.prn) : [];
    const forOther = !!givenBy && givenBy !== me.name;
    const hideCd = !!o?.cd && !cdView(p);
    function save() {
        const v: Record<string, string> = {};
        if (!item && !pid) v['pe-person'] = 'Choose the person.';
        if (!item && !orderId) v['pe-med'] = 'Choose the medicine.';
        if (!outcome) v['pe-outcome'] = 'Choose what the paper says.';
        const hm = time.slice(11);
        if (!/^\d{2}:\d{2}$/.test(hm) || time.slice(0, 10) !== d.start.day) v['pe-time'] = `Choose the time on the paper, on ${labelIso(d.start.day)}.`;
        else if (hm < d.start.hm || hm > d.end.hm) v['pe-time'] = `It must be inside the downtime, ${time12(d.start.hm)}–${time12(d.end.hm)}.`;
        if (!givenBy) v['pe-by'] = 'Choose who gave it, from the paper.';
        if (o?.cd && outcome === 'given' && !witness) v['pe-witness'] = 'Choose the witness named on the paper.';
        setX(v);
        if (Object.keys(v).length) return focusFirst(v);
        if (scn === 'offline') return setX({ 'pe-save': cantSaveOffline });
        if (scn === 'logdown') return setX({ 'pe-save': COULDNT_SAVE });
        const id = item?.id ?? `${d.id}-add-${(s.rt.extraPaper[d.id]?.length ?? 0) + 1}`;
        const entry: PaperEntry = { by: me.name, at: stampAt(), outcome: outcome as PaperEntry['outcome'], hm, givenBy, ...(note.trim() ? { note: note.trim() } : {}), ...(forOther ? { forOther: true } : {}), ...(o?.cd && outcome === 'given' ? { witness: { name: witness, ...(pin.length === 6 ? { confirmed: stampAt() } : {}) } } : {}) };
        const added: PaperItem | null = item ? null : { id, pid: pid as PersonId, orderId, source: 'paper', paper: { outcome: outcome as PaperEntry['outcome'], hm, by: givenBy, ...(note.trim() ? { note: note.trim() } : {}) } };
        const med = orderOf(orderId).med;
        s.update((rt) => ({
            ...rt,
            paper: { ...rt.paper, [id]: entry },
            extraPaper: added ? { ...rt.extraPaper, [d.id]: [...(rt.extraPaper[d.id] ?? []), added] } : rt.extraPaper,
            events: [...rt.events, rtEvent(rt, { house: d.house, kind: orderOf(orderId).cd ? 'controlled' : 'dose', what: `Entered from paper — ${med}`, detail: `${outcome === 'given' ? 'Given' : outcome === 'refused' ? 'Refused' : 'Withheld'} ${time12(hm)} ${labelIso(d.start.day)} by ${givenBy} (paper) — entered ${time12('09:12')} Mon 28 Sep by ${me.name}, ${d.id} ${time12(d.start.hm)}–${time12(d.end.hm)}`, by: me.name, pid: pid as PersonId, cd: !!orderOf(orderId).cd })],
        }));
        s.toast('success', `${med} entered from paper for ${PEOPLE[pid as PersonId].pref}.${forOther ? ` ${givenBy} is asked to confirm it.` : ''}${entry.witness && !entry.witness.confirmed ? ` It waits for ${witness} to confirm as the witness.` : ''}`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={item ? `Enter from paper — ${hideCd ? 'Controlled medicine' : orderOf(item.orderId).med}, ${PEOPLE[item.pid].pref}` : 'Add a dose from the paper'}
            description={`${d.id} · ${HOUSES[d.house]} · ${dtWhen(d)}${item?.due ? ` · due ${time12(item.due)}` : ''}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>
                        <PenLine className="size-4" /> Enter it
                    </Button>
                </>
            }
        >
            {item && item.paper.by === '—' ? (
                <Notice tone="neutral" icon={FileText} title={`Due ${time12(item.due!)} — nothing was recorded for it`}>
                    Enter what the paper says for this dose.
                </Notice>
            ) : item ? (
                <ReviewCard icon={FileText} title="What the paper says">
                    <ReviewRow label="Outcome" value={item.paper.outcome === 'given' ? 'Given' : item.paper.outcome === 'refused' ? 'Refused' : 'Withheld'} />
                    <ReviewRow label="Time" value={time12(item.paper.hm)} />
                    <ReviewRow label="By" value={item.paper.by} />
                    {item.paper.note && !hideCd ? <ReviewRow label="Note" value={`“${item.paper.note}”`} /> : null}
                    <p className="text-caption pt-1.5">Shown as the starting point — enter what the paper says; nothing is entered for you.</p>
                </ReviewCard>
            ) : (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="pe-person">
                            Person <Req />
                        </Label>
                        <Select value={pid || undefined} onValueChange={(v) => (setPid(v as PersonId), setOrderId(''), setX({}))}>
                            <SelectTrigger id="pe-person" className="w-full" aria-invalid={!!x['pe-person']}>
                                <SelectValue placeholder="Choose someone" />
                            </SelectTrigger>
                            <SelectContent>
                                {housePeople.map((q) => (
                                    <SelectItem key={q} value={q}>
                                        {PEOPLE[q].legal}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={x['pe-person']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="pe-med">
                            As-needed medicine <Req />
                        </Label>
                        <Select value={orderId || undefined} onValueChange={(v) => (setOrderId(v), setX({}))} disabled={!pid}>
                            <SelectTrigger id="pe-med" className="w-full" aria-invalid={!!x['pe-med']}>
                                <SelectValue placeholder={pid && !prnOrders.length ? 'None ordered' : 'Choose the medicine'} />
                            </SelectTrigger>
                            <SelectContent>
                                {prnOrders.map((q) => (
                                    <SelectItem key={q.id} value={q.id}>
                                        {q.cd && !cdView(p) ? 'Controlled medicine' : `${q.med} ${q.strength}`}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={x['pe-med']} />
                    </div>
                </div>
            )}
            <div className="space-y-2">
                <Label id="pe-outcome-l">
                    What the paper says <Req />
                </Label>
                <div id="pe-outcome" tabIndex={-1}>
                    <TilePicker labelledBy="pe-outcome-l" value={outcome || null} invalid={!!x['pe-outcome'] && !outcome} onChange={(k) => (setOutcome(k), setX({}))} tiles={OUTCOME_TILES} />
                </div>
                <InputError message={x['pe-outcome']} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <DateTimeField id="pe-time" label="Time on the paper" value={time} onChange={(v) => (setTime(v), setX({}))} error={x['pe-time']} hint={`Inside the downtime, ${time12(d.start.hm)}–${time12(d.end.hm)}, ${labelIso(d.start.day)}`} />
                <div className="space-y-1.5">
                    <Label htmlFor="pe-by">
                        Who gave it <Req />
                    </Label>
                    <Select value={givenBy || undefined} onValueChange={(v) => (setGivenBy(v), setX({}))}>
                        <SelectTrigger id="pe-by" className="w-full" aria-invalid={!!x['pe-by']}>
                            <SelectValue placeholder="Choose who — from the paper" />
                        </SelectTrigger>
                        <SelectContent>
                            {staffAt(d.house).map((n) => (
                                <SelectItem key={n} value={n}>
                                    {n}
                                    {n === me.name ? ' (you)' : ''}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={x['pe-by']} />
                </div>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="pe-note">
                    Note from the paper <span className="font-normal text-muted-foreground">(optional)</span>
                </Label>
                <Input id="pe-note" value={note} onChange={(ev) => setNote(ev.target.value)} />
            </div>
            {forOther ? (
                <Notice tone="info" title={`You’re entering this for ${givenBy}`}>
                    {`${givenBy.split(' ')[0]} is asked to confirm it in Follow-ups. It shows as “Waiting for ${givenBy.split(' ')[0]} to confirm” until they do.`}
                </Notice>
            ) : null}
            {o?.cd && outcome === 'given' ? (
                <>
                    <div className="grid gap-4 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="pe-witness">
                                Witness on the paper <Req />
                            </Label>
                            <Select value={witness || undefined} onValueChange={(v) => (setWitness(v), setX({}))}>
                                <SelectTrigger id="pe-witness" className="w-full" aria-invalid={!!x['pe-witness']}>
                                    <SelectValue placeholder="Choose who" />
                                </SelectTrigger>
                                <SelectContent>
                                    {staffAt(d.house)
                                        .filter((n) => n !== givenBy)
                                        .map((n) => (
                                            <SelectItem key={n} value={n}>
                                                {n}
                                            </SelectItem>
                                        ))}
                                </SelectContent>
                            </Select>
                            <InputError message={x['pe-witness']} />
                        </div>
                        <WitnessPinInput id="pe-pin" value={pin} onChange={setPin} required={false} label="Their witness PIN, if they’re here" />
                    </div>
                    <Notice tone="neutral" icon={LockKeyhole} title="A controlled medicine">
                        {`It waits as “Witness to confirm” until ${witness || 'the witness'} confirms with their PIN — now, or from Follow-ups. It goes into the register in time order, and its running balance is worked out again. The closing count at the end of the downtime checks it.`}
                    </Notice>
                </>
            ) : null}
            {x['pe-save'] ? (
                <Notice tone="critical" title={x['pe-save'] === cantSaveOffline ? 'You’re offline' : 'Couldn’t save — try again'} live="alert">
                    {x['pe-save'] === cantSaveOffline ? 'Paper records are entered once you’re back online.' : 'Nothing was recorded, and what you entered is still here. The event log couldn’t be written.'}
                </Notice>
            ) : null}
            <KV rows={[['Recorded as', `Entered from paper — the time on the paper, and “entered 9:12 am Mon 28 Sep by ${me.name}” beside it`]]} />
        </Modal>
    );
}

/* ═════════════ Finish the downtime ═════════════ */
export function FinishDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const p = s.route.persona;
    const scn = s.route.scenario;
    const d = allDowntimes(s.rt, scn).find((q) => q.id === id)!;
    const hasCd = d.items.some((i) => orderOf(i.orderId).cd);
    const finish = () => {
        if (scn === 'offline' || scn === 'logdown') return (s.toast('critical', scn === 'offline' ? 'You’re offline — finish it once you’re back.' : COULDNT_SAVE), onClose());
        s.update((rt) => ({ ...rt, closedDowntimes: { ...rt.closedDowntimes, [id]: { by: PERSONAS[p].name, at: stampAt() } }, events: [...rt.events, rtEvent(rt, { house: d.house, kind: 'downtime', what: `Downtime finished — ${HOUSES[d.house]}`, detail: `${d.id} · ${d.items.length} paper records entered`, by: PERSONAS[p].name, cd: false })] }));
        s.toast('success', `${d.id} is finished. The paper sheets stay attached.`);
        onClose();
    };
    return <ConfirmDialog open variant="default" onClose={onClose} onConfirm={finish} title={`Finish ${d.id}?`} description={`Every paper record is entered.${hasCd ? ' Do the closing controlled count first — it checks the register.' : ''} The paper sheets stay attached. Anything still waiting to be confirmed carries on in Follow-ups.`} confirmText="Finish it" cancelText="Not yet" />;
}
