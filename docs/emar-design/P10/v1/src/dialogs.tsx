/* P09’s dialogs, as P10 uses them — make an export (with its purpose, P09 Q8),
 * what an export holds, and one audit event with its place in the chain (Q7).
 * P10 adds the emergency access history file to the export dialog (Q8). The
 * Fleet Settings `Modal`, real ReviewCard/ReviewRow, TilePicker, Select and the
 * approved DatePicker. */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { ReviewCard, ReviewRow } from '@/components/wizard/shell';
import { BookOpen, ClipboardList, Download, FileText, HeartHandshake, Link2, SearchCheck, ShieldCheck, Siren, Wallet, type LucideIcon } from 'lucide-react';
import { useState } from 'react';
import { HOUSES, ORDERS, PEOPLE, PERSONAS, orderOf, type ExportMade, type House, type PersonId } from './data';
import { COULDNT_SAVE } from './dialogs-ea';
import { cantSaveOffline, focusFirst, Req, restore, stamp } from './helpers';
import { Modal } from './modal';
import { EA_EXPORT, EXPORT_DEFS, PURPOSES, TODAY_ISO, addDays, cdView, eventAt, eventTitle, housesOf, labelIso, logWithRuntime, peopleOf, periodOf, redactedEvent, rtEvent, stockOnly, type ExportKind } from './model';
import { whoCan } from './pages/exports';
import { useStore } from './store';
import { KV, Notice, TilePicker } from './ui';

/** “a MAR PDF”, “a doses CSV”, “an audit trail CSV”. */
const fileName = (name: string, format: string) => { const n = name === 'MAR' ? name : name.charAt(0).toLowerCase() + name.slice(1); return `${/^[aeiou]/i.test(n) ? 'an' : 'a'} ${n} ${format}`; };
const PURPOSE_ICON: Record<string, LucideIcon> = { care: HeartHandshake, audit: SearchCheck, whanau: BookOpen, incident: Siren, costs: Wallet, other: ClipboardList };
const MONTHS = [
    { value: '2026-09', label: 'September 2026 (so far)' },
    { value: '2026-08', label: 'August 2026' },
    { value: '2026-07', label: 'July 2026' },
];

/* ═════════════ Make an export (Q8) ═════════════ */
export function ExportDialog({ kind, arg, onClose, returnFocus }: { kind: ExportKind; arg?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const def = kind === 'ea' ? EA_EXPORT : EXPORT_DEFS.find((d) => d.key === kind)!;
    const period = periodOf(s.route.q.get('period'), s.route.q.get('from'), s.route.q.get('to'));
    const people = peopleOf(p);
    const cdOrders = ORDERS.filter((o) => o.cd && people.includes(o.pid));
    const [pid, setPid] = useState<PersonId | ''>(kind === 'mar' && arg && people.includes(arg as PersonId) ? (arg as PersonId) : '');
    const [month, setMonth] = useState('2026-09');
    const [med, setMed] = useState(kind === 'cdreg' && arg ? arg : '');
    const [house, setHouse] = useState<House | ''>(kind === 'round' && arg ? (arg as House) : housesOf(p).length === 1 ? housesOf(p)[0] : '');
    const [day, setDay] = useState(addDays(TODAY_ISO, -1));
    const [purpose, setPurpose] = useState(stockOnly(p) ? 'costs' : '');
    const [detail, setDetail] = useState('');
    const [x, setX] = useState<Record<string, string>>({});
    const tooLong = ['doses', 'errors', 'audit', 'ea'].includes(kind) && (Date.UTC(+period.to.slice(0, 4), +period.to.slice(5, 7) - 1, +period.to.slice(8, 10)) - Date.UTC(+period.from.slice(0, 4), +period.from.slice(5, 7) - 1, +period.from.slice(8, 10))) / 86400000 > 366;
    const scopeText = kind === 'mar' ? (pid ? `${PEOPLE[pid].legal} · ${MONTHS.find((m) => m.value === month)!.label}` : '') : kind === 'cdreg' ? (med ? `${orderOf(med).med} ${orderOf(med).strength} · ${PEOPLE[orderOf(med).pid].legal}` : '') : kind === 'round' ? (house ? `${HOUSES[house]} · ${labelIso(day)}` : '') : kind === 'stock' ? (stockOnly(p) ? 'Both houses · as at now' : `${housesOf(p).map((h) => HOUSES[h]).join(' and ')} · as at now`) : `${housesOf(p).map((h) => HOUSES[h]).join(' and ')} · ${period.text}`;
    function make() {
        const v: Record<string, string> = {};
        if (kind === 'mar' && !pid) v['ex-person'] = 'Choose the person.';
        if (kind === 'cdreg' && !med) v['ex-med'] = 'Choose the medicine.';
        if (kind === 'round' && !house) v['ex-house'] = 'Choose the house.';
        if (kind === 'round' && !day) v['ex-day'] = 'Choose the day.';
        if (tooLong) v['ex-period'] = 'Choose up to 12 months — change the period on the page.';
        if (def.identifiable && !purpose) v['ex-purpose'] = 'Choose why you need it.';
        if (purpose === 'other' && detail.trim().length < 5) v['ex-detail'] = 'Say what it’s for.';
        setX(v);
        if (Object.keys(v).length) return focusFirst(v);
        if (s.route.scenario === 'offline') return setX({ 'ex-purpose': cantSaveOffline });
        if (s.route.scenario === 'logdown') return setX({ 'ex-save': COULDNT_SAVE });
        const pLabel = PURPOSES.find((q) => q.key === purpose)?.label ?? 'Costs and budgeting';
        const made: ExportMade = { id: `EX-${119 + s.rt.exports.length}`, what: `${def.name} (${def.format})`, detail: scopeText, by: me.name, at: stamp(), day: TODAY_ISO, purpose: purpose === 'other' ? `Something else — ${detail.trim()}` : pLabel, house: kind === 'mar' && pid ? PEOPLE[pid].house : kind === 'cdreg' && med ? PEOPLE[orderOf(med).pid].house : kind === 'round' && house ? house : housesOf(p)[0] };
        s.update((rt) => ({ ...rt, exports: [made, ...rt.exports], events: [...rt.events, rtEvent(rt, { house: made.house, kind: 'export', what: `Export made — ${made.what}`, detail: `${made.detail} · purpose: ${made.purpose}`, by: me.name, cd: made.what.startsWith('Controlled') })] }));
        s.toast('success', `${def.name} (${def.format}) is ready — recorded in the audit trail as ${made.id}, with your purpose. (Nothing downloads in the preview.)`);
        onClose();
    }
    const tiles = PURPOSES.filter((q) => (q.key === 'costs' ? kind === 'stock' : true)).map((q) => ({ key: q.key, label: q.label, description: q.description, icon: PURPOSE_ICON[q.key] }));
    return (
        <Modal
            width={720}
            title={`Make ${fileName(def.name, def.format)}`}
            description={`${def.what}. ${def.limit}.`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={make}>
                        <Download className="size-4" /> Make the file
                    </Button>
                </>
            }
        >
            {kind === 'mar' ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="ex-person">
                            Person <Req />
                        </Label>
                        <Select value={pid || undefined} onValueChange={(v) => (setPid(v as PersonId), setX({}))}>
                            <SelectTrigger id="ex-person" className="w-full" aria-invalid={!!x['ex-person']}>
                                <SelectValue placeholder="Choose someone" />
                            </SelectTrigger>
                            <SelectContent>
                                {people.map((q) => (
                                    <SelectItem key={q} value={q}>
                                        {PEOPLE[q].legal} — {HOUSES[PEOPLE[q].house]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={x['ex-person']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="ex-month">Month</Label>
                        <Select value={month} onValueChange={setMonth}>
                            <SelectTrigger id="ex-month" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {MONTHS.map((m) => (
                                    <SelectItem key={m.value} value={m.value}>
                                        {m.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                </div>
            ) : kind === 'cdreg' ? (
                <div className="space-y-1.5">
                    <Label htmlFor="ex-med">
                        Controlled medicine <Req />
                    </Label>
                    <Select value={med || undefined} onValueChange={(v) => (setMed(v), setX({}))}>
                        <SelectTrigger id="ex-med" className="w-full" aria-invalid={!!x['ex-med']}>
                            <SelectValue placeholder="Choose the medicine" />
                        </SelectTrigger>
                        <SelectContent>
                            {cdOrders.map((o) => (
                                <SelectItem key={o.id} value={o.id}>
                                    {o.med} {o.strength} — {PEOPLE[o.pid].legal}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={x['ex-med']} />
                </div>
            ) : kind === 'round' ? (
                <div className="grid gap-4 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="ex-house">
                            House <Req />
                        </Label>
                        <Select value={house || undefined} onValueChange={(v) => (setHouse(v as House), setX({}))}>
                            <SelectTrigger id="ex-house" className="w-full" aria-invalid={!!x['ex-house']}>
                                <SelectValue placeholder="Choose the house" />
                            </SelectTrigger>
                            <SelectContent>
                                {housesOf(p).map((h) => (
                                    <SelectItem key={h} value={h}>
                                        {HOUSES[h]}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={x['ex-house']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="ex-day">
                            Day <Req />
                        </Label>
                        <DatePicker id="ex-day" label="Day" value={day} invalid={!!x['ex-day']} onChange={(v) => (setDay(v), setX({}))} />
                        <InputError message={x['ex-day']} />
                    </div>
                </div>
            ) : null}
            <InputError message={x['ex-period']} />
            <ReviewCard icon={FileText} title="What’s in it">
                <ReviewRow label="Covers" value={scopeText || '—'} />
                {kind === 'mar' ? <ReviewRow label="Medicines" value="Every medicine on the chart that month — ceased ones included, with when they stopped" /> : null}
                {kind === 'errors' ? <ReviewRow label="Accounts and notes" value={cdView(p) ? 'Included for every report' : 'Included except for controlled-medicine reports, which need controlled-medicine access'} /> : null}
                {kind === 'ea' ? <ReviewRow label="Each grant" value="Who, for whom, why, how long, the second person, what was done and every review — corrections beside the first" /> : null}
                {['mar', 'doses', 'audit'].includes(kind) ? <ReviewRow label="Controlled medicines" value={cdView(p) ? 'Included in full' : 'Shown as “Controlled medicine”, without the name or dose'} /> : null}
                {kind === 'stock' && !cdView(p) ? <ReviewRow label="Controlled medicines" value="Left out — they need controlled-medicine access" /> : null}
                {kind === 'stock' && stockOnly(p) ? <ReviewRow label="People" value="None — medicine and house only" /> : null}
                <ReviewRow label="Times" value="NZ time, labelled NZDT or NZST" />
                {def.format === 'CSV' ? <ReviewRow label="Spreadsheets" value="Cells that start with =, +, − or @ are made safe" /> : null}
            </ReviewCard>
            {def.identifiable ? (
                <>
                    <div className="space-y-2">
                        <Label id="ex-purpose-l">
                            What it’s for <Req />
                        </Label>
                        <div id="ex-purpose" tabIndex={-1}>
                            <TilePicker labelledBy="ex-purpose-l" value={purpose || null} invalid={!!x['ex-purpose'] && !purpose} onChange={(k) => (setPurpose(k), setX({}))} tiles={tiles} />
                        </div>
                        <InputError message={x['ex-purpose']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="ex-detail">
                            {purpose === 'other' ? (
                                <>
                                    Say what it’s for <Req />
                                </>
                            ) : (
                                <>
                                    Anything to add <span className="font-normal text-muted-foreground">(optional)</span>
                                </>
                            )}
                        </Label>
                        <Input id="ex-detail" value={detail} aria-invalid={!!x['ex-detail']} onChange={(ev) => (setDetail(ev.target.value), setX({}))} placeholder="For example: the certification audit on 6 October" />
                        <InputError message={x['ex-detail']} />
                    </div>
                    <p className="text-caption">The file, your name, the purpose and the time are recorded in the audit trail.</p>
                </>
            ) : (
                <p className="text-caption">It names no one, so no purpose is asked for. It’s still recorded in the audit trail.</p>
            )}
            {x['ex-save'] ? (
                <Notice tone="critical" title="Couldn’t save — try again" live="alert">
                    Nothing was made or recorded, and your choices are still here. The event log couldn’t be written.
                </Notice>
            ) : null}
        </Modal>
    );
}

/* ═════════════ What an export holds (for people who can’t make it) ═════════════ */
export function AboutExportDialog({ kind, onClose }: { kind: ExportKind; onClose: () => void }) {
    const s = useStore();
    const def = kind === 'ea' ? EA_EXPORT : EXPORT_DEFS.find((d) => d.key === kind)!;
    return (
        <Modal title={`${def.name} (${def.format})`} description={def.what} onClose={onClose}>
            <KV
                rows={[
                    ['Range', def.limit],
                    ['Made by', whoCan(def).charAt(0).toUpperCase() + whoCan(def).slice(1)],
                    ['Purpose', def.identifiable ? 'Asked for, and recorded in the audit trail' : 'Not asked — it names no one'],
                ]}
            />
            <p className="text-sm">{`${PERSONAS[s.route.persona].name} can view reports. Ask one of the people above to make this file.`}</p>
        </Modal>
    );
}

/* ═════════════ One event, and its place in the chain (Q7) ═════════════ */
export function EventDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const p = s.route.persona;
    const e = logWithRuntime(s.rt).find((x) => x.id === id)!;
    const hide = redactedEvent(p, e);
    return (
        <Modal
            width={720}
            title={eventTitle(p, e)}
            description={`${eventAt(e)} NZDT · ${HOUSES[e.house]} · event #${e.seq.toLocaleString('en-NZ')}`}
            onClose={onClose}
            footer={
                <>
                    {e.pid && (e.kind === 'dose' || (e.kind === 'controlled' && e.orderId)) ? (
                        <Button variant="outline" onClick={() => s.toast('info', `Opens ${PEOPLE[e.pid!].pref}’s MAR on ${labelIso(e.day)} (P02) — outside this preview.`)}>
                            Open the MAR at this dose
                        </Button>
                    ) : null}
                    <Button onClick={onClose}>Close</Button>
                </>
            }
        >
            <KV
                rows={[
                    ['What', eventTitle(p, e)],
                    ['Detail', hide ? 'Needs controlled-medicine access' : e.detail],
                    ['By', e.by],
                    ['Person', e.pid ? PEOPLE[e.pid].legal : '—'],
                    ['House', HOUSES[e.house]],
                    ['When', `${eventAt(e)} NZDT`],
                ]}
            />
            <ReviewCard icon={Link2} title="Its place in the chain">
                <ReviewRow label="Event" value={`#${e.seq.toLocaleString('en-NZ')} at ${HOUSES[e.house]}`} />
                <ReviewRow label="This event’s fingerprint" value={<code className="text-[12px]">{e.hash}…</code>} />
                <ReviewRow label="The one before it" value={<code className="text-[12px]">{e.prev}…</code>} />
                <ReviewRow label="Check" value={<span className="inline-flex items-center gap-1.5 font-semibold"><ShieldCheck className="size-4" aria-hidden="true" /> Linked — it matches the event before it</span>} />
            </ReviewCard>
            <p className="text-caption">Events are only ever added. A correction is a new event that points to the one it corrects. The full fingerprint is in the audit trail export.</p>
        </Modal>
    );
}
