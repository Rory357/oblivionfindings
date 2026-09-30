/* P04’s order dialogs — redesigning OrderDetailDialog, NewOrderDialog,
 * VerifyOrderDialog, CountersignDialog, DiscontinueDialog, RejectOrderDialog,
 * EditMedicationDialog and AddMedicationDialog into one versioned order (Main,
 * Q1–Q4). Real WizardShell (sequential and viewer), ReviewCard/ReviewRow,
 * WizardSuccessPane, the Fleet Settings Modal, ConfirmDialog, TilePicker,
 * Checkbox, Select, Input, Textarea, Switch, FileDropzone + StagedFileCard and
 * the PKG-01 DateTimeField. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { EntityChip } from '@/components/lists/entity-cells';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { AlertTriangle, Check, ChevronLeft, ChevronRight, ClipboardCheck, ClipboardList, FileSignature, FileText, History, Loader2, Mail, MessageSquare, Package, PenLine, Phone, Pill, ShieldCheck, Undo2, User } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LABEL, NOW_LOCAL } from './clock';
import { CHECKERS, PEOPLE, PEOPLE_ORDER, PERSONAS, SOURCE_LABEL, STAFF_ON_SHIFT, type Order, type PersonId, type SourceType, type Version } from './data';
import { Modal } from './modal';
import { allOrders, canCheck, canEnter, concealed, covertOf, covertState, currentOf, pendingOf, statusOf, versionsOf, whyCantCheck, type Runtime } from './model';
import { useStore } from './store';
import { DesignNote, KV, NewOrderSupport, Notice, OrderBadge, StateLine, TilePicker } from './ui';

const cantSaveOffline = 'You’re offline, so this can’t be saved yet. Nothing is lost — keep this open and save when you reconnect. Staff keep giving the last checked version.';
const focusFirst = (x: Record<string, string>) => window.setTimeout(() => document.getElementById(Object.keys(x)[0])?.focus(), 50);
const restore = (rf?: () => HTMLElement | null) => (ev: Event) => {
    const el = rf?.();
    if (el) {
        ev.preventDefault();
        el.focus();
    }
};
const when = (v: Version, o: Order) => (o.prn ? `${v.dose} when needed — ${o.prn}` : `${v.dose} · ${v.when}`);
const stamp = () => `Mon 28 Sep, ${NOW_LABEL}`;
/** Preview-only allergy matching on a few fixture names. The build must not hard-code drug classes:
 * class matching (a penicillin, a cephalosporin cross-reaction) comes from a maintained class source (Main, 30 Sep). */
export function allergyMatch(pid: PersonId, med: string): string | null {
    const p = PEOPLE[pid];
    if (p.allergy.state !== 'recorded') return null;
    const name = med.toLowerCase();
    const PEN = ['amoxicillin', 'flucloxacillin', 'penicillin', 'co-amoxiclav'];
    for (const a of p.allergy.list) {
        const key = a.toLowerCase().split(' ')[0];
        if (name.includes(key)) return `${a} — ${med} contains ${key}`;
        if (key === 'penicillin' && PEN.some((x) => name.includes(x))) return `${a} — ${med} is a penicillin`;
        if (key === 'penicillin' && /^(cef|ceph)/.test(name)) return `${a} — ${med} is a cephalosporin; some people with a penicillin allergy react to it`;
    }
    return null;
}
/** How a person’s allergy record reads (main bbc7705ad: “couldn’t be loaded” is never “none recorded”). */
export function allergyText(pid: PersonId): string {
    const a = PEOPLE[pid].allergy;
    if (a.state === 'recorded') return a.list.join(' · ');
    if (a.state === 'nkda') return 'No known allergies (recorded)';
    if (a.state === 'unavailable') return 'Couldn’t be loaded — check the health profile';
    return 'Not recorded — ask the prescriber';
}
const setVersions = (rt: Runtime, o: Order, vs: Version[]): Runtime => ({ ...rt, versions: { ...rt.versions, [o.id]: vs } });

/* ═════════════ The order (OrderDetailDialog, redesigned): a viewer with sections ═════════════ */
export function OrderDialog({ id, onClose, onAction }: { id: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const p = s.route.persona;
    const o = allOrders(s.rt).find((x) => x.id === id)!;
    const [sec, setSec] = useState(0);
    const vs = versionsOf(o, s.rt);
    const cur = currentOf(o, s.rt);
    const pend = pendingOf(o, s.rt);
    const st = statusOf(o, s.rt);
    const cov = o.covert ? covertOf(o.id, s.rt, s.route.scenario) : null;
    const who = PEOPLE[o.pid];
    const SECS = [
        { key: 'now', label: 'This order', blurb: cur ? `Version ${cur.v}` : 'Not checked yet', icon: ClipboardList },
        { key: 'versions', label: 'Versions', blurb: `${vs.length} kept`, icon: History },
        { key: 'checks', label: 'Checks and confirmations', blurb: 'Who checked, what was confirmed', icon: ClipboardCheck },
        { key: 'supply', label: 'Supply', blurb: 'From the pharmacy', icon: Package },
    ];
    const v = cur ?? pend!;
    const body: ReactNode[] = [
        <div key="now" className="space-y-4">
            <div className="flex flex-wrap items-center gap-2">
                <OrderBadge state={st.state} />
                {o.covert ? <EntityChip>Covert plan</EntityChip> : null}
                {o.cd ? <EntityChip>Controlled medicine</EntityChip> : null}
            </div>
            {st.lines.map((l) => (
                <p key={l} className="text-sm">
                    {l}
                </p>
            ))}
            <KV
                rows={[
                    ['Person', `${who.legal} (“${who.pref}”)`],
                    ['Medicine', `${o.med} ${o.strength} · ${o.route}`],
                    ['Dose and when', when(v, o)],
                    ['For', o.indication],
                    ['From', `${o.start}${o.end ? ` until ${o.end}` : ' — no end date'}`],
                    ['Prescriber', `${v.source.prescriber} · ${SOURCE_LABEL[v.source.type].toLowerCase()} · ${v.source.at}`],
                    ['Support', vs.length === 1 && o.start === '28 September 2026' ? <NewOrderSupport key="s" /> : 'Set in the person’s Support plan'],
                    ...(cov ? ([['Covert', covertState(cov).line]] as [string, string][]) : []),
                ]}
            />
            {pend && cur ? <Notice tone={pend.state === 'sentBack' ? 'warning' : 'info'} title={`Version ${pend.v}: ${pend.changed ?? 'a change'}`}>{pend.state === 'sentBack' ? `Sent back by ${pend.sentBack!.by}: “${pend.sentBack!.reason}”` : `Waiting to be checked. Staff give version ${cur.v} until then.`}</Notice> : null}
        </div>,
        <ul key="versions" className="divide-y rounded-lg border">
            {[...vs].reverse().map((x) => (
                <li key={x.v} className="space-y-1 px-3 py-2.5 text-sm">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="font-semibold">
                            Version {x.v} · {x.kind === 'new' ? 'new order' : x.kind === 'stop' ? 'stop' : 'change'}
                        </span>
                        <StatusBadge variant={x.state === 'checked' ? 'success' : x.state === 'lone' ? 'warning' : x.state === 'sentBack' ? 'warning' : 'info'} className="rounded-[8px]">
                            {x.state === 'checked' ? (x === cur ? 'Current' : 'Replaced') : x.state === 'lone' ? 'Checked alone' : x.state === 'sentBack' ? 'Sent back' : 'Waiting to be checked'}
                        </StatusBadge>
                    </div>
                    <p>{x.changed ?? when(x, o)}</p>
                    <p className="text-caption">
                        {SOURCE_LABEL[x.source.type]} · {x.source.prescriber} · {x.source.at} · entered {x.enteredAt} by {x.enteredBy}
                        {x.checked ? ` · checked ${x.checked.at} by ${x.checked.by}` : ''}
                    </p>
                </li>
            ))}
        </ul>,
        <div key="checks" className="space-y-3 text-sm">
            {vs.map((x) => (
                <div key={x.v} className="space-y-1 rounded-lg border p-3">
                    <p className="font-semibold">Version {x.v}</p>
                    <p>{x.checked ? `Checked ${x.checked.at} by ${x.checked.by}${x.checked.lone ? ` — alone: “${x.checked.lone.reason}” Second check due by ${x.checked.lone.secondDue}.` : ` (entered by ${x.enteredBy} — an independent check).`}` : x.sentBack ? `Sent back ${x.sentBack.at} by ${x.sentBack.by}.` : 'Not checked yet.'}</p>
                    {x.source.readBack ? <p>Read back to {x.source.prescriber} {x.source.readBack.at} · witnessed by {x.source.readBack.witness} (witness PIN)</p> : null}
                    {x.written ? <p>{x.written.done ? `Prescriber’s written confirmation: ${x.written.done.how}, ${x.written.done.at}, attached by ${x.written.done.by}.` : `Prescriber’s written confirmation due by ${x.written.due}.`}</p> : null}
                    {x.source.doc ? <p>Document: {x.source.doc}</p> : null}
                    {x.allergy ? <p>{x.allergy.confirmed ? `Allergy match (${x.allergy.match}) — ${x.allergy.confirmed.prescriber} confirmed it’s safe (${x.allergy.confirmed.how.toLowerCase()}, ${x.allergy.confirmed.at}, recorded by ${x.allergy.confirmed.by}): “${x.allergy.confirmed.note}”` : `Allergy match (${x.allergy.match}) — waiting for the prescriber’s confirmation.`}</p> : null}
                </div>
            ))}
        </div>,
        <div key="supply" className="space-y-2 text-sm">
            <p>{o.supply ?? 'Supplied by Kōwhai Pharmacy.'}</p>
            <p className="text-caption">What the pharmacy dispensed — batch, expiry and date — is recorded in Stock &amp; pharmacy and shown here, read only.</p>
            <DesignNote title="Design note — dispensing moves to P06 (Main, Q8)">
                <p>Today’s DispenseDialog and Dispensing tab move to Stock &amp; pharmacy. P04 only reads them.</p>
            </DesignNote>
        </div>,
    ];
    const actions: ReactNode[] = [];
    if (st.state !== 'stopped') {
        if (pend?.state === 'waiting' && canCheck(p) && !whyCantCheck(pend, p) && !(pend.allergy && !pend.allergy.confirmed)) actions.push(<Button key="c" onClick={() => onAction(`check:${o.id}`)}>Check version {pend.v}</Button>);
        if (cur?.state === 'lone' && canCheck(p) && !whyCantCheck(cur, p)) actions.push(<Button key="2" onClick={() => onAction(`check:${o.id}`)}>Second check</Button>);
        if (pend?.state === 'waiting' && canCheck(p) && whyCantCheck(pend, p)?.startsWith('You entered') && !(pend.allergy && !pend.allergy.confirmed)) actions.push(<Button key="cl" variant="outline" onClick={() => onAction(`check:${o.id}`)}>Check it alone…</Button>);
        if (pend?.allergy && !pend.allergy.confirmed && canEnter(p)) actions.push(<Button key="a" onClick={() => onAction(`allergy:${o.id}`)}>Record the prescriber’s confirmation</Button>);
        if (cur?.written && !cur.written.done && canEnter(p)) actions.push(<Button key="w" variant="outline" onClick={() => onAction(`written:${o.id}`)}>Attach written confirmation</Button>);
        if (canEnter(p)) actions.push(<Button key="ch" variant="outline" onClick={() => onAction(`change:${o.id}`)}>Enter a change</Button>);
    }
    return (
        <WizardShell
            open
            onClose={onClose}
            title={`${o.med} — ${who.pref}`}
            description="The order, its versions, checks and supply."
            railIcon={Pill}
            railTitle={`${o.med} ${o.strength}`}
            railSub={`${who.pref} ${who.surname} · ${o.status === 'stopped' ? 'stopped' : cur ? `version ${cur.v}` : 'not checked yet'}`}
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
            footerEnd={actions.length ? <span className="flex flex-wrap items-center gap-2">{actions}</span> : undefined}
            maxWidth="min(94vw, 1000px)"
            maxHeight="min(86vh, 760px)"
        >
            <WizardStepPane>
                <div className="space-y-4">{body[sec]}</div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ═════════════ Enter an order or a change (NewOrderDialog + Add/EditMedicationDialog, redesigned) ═════════════ */
const PRESCRIBERS = ['Dr Lena Chen', 'Dr Arun Patel (after-hours GP)', 'Someone else'];
const ROUTES = ['By mouth', 'Subcutaneous injection', 'Inhaled', 'On the skin', 'Eye drops'];
export function EntryDialog({ orderId, onClose, returnFocus }: { orderId?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const o = orderId ? allOrders(s.rt).find((x) => x.id === orderId)! : null;
    const base = o ? (currentOf(o, s.rt) ?? pendingOf(o, s.rt)!) : null;
    const [step, setStep] = useState(0);
    const [f, setF] = useState({
        source: '' as '' | SourceType,
        prescriber: base?.source.prescriber && PRESCRIBERS.includes(base.source.prescriber) ? base.source.prescriber : '',
        other: '',
        at: NOW_LOCAL,
        file: null as File | null,
        pid: (o?.pid ?? '') as PersonId | '',
        med: o?.med ?? '',
        strength: o?.strength ?? '',
        route: o?.route ?? 'By mouth',
        dose: base?.dose ?? '',
        when: base?.when ?? '',
        prn: !!o?.prn,
        prnLimits: o?.prn ?? '',
        indication: o?.indication ?? '',
        end: o?.end ?? '',
        readBack: false,
        witness: '',
        pin: '',
    });
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [offlineErr, setOfflineErr] = useState(false);
    const set = (patch: Partial<typeof f>) => (setF((x) => ({ ...x, ...patch })), setE({}));
    const spoken = f.source === 'phone' || f.source === 'verbal';
    const pidOk = f.pid || null;
    const dup = !o && pidOk && f.med.trim() ? allOrders(s.rt).find((x) => x.pid === pidOk && !concealed(x, s.route.persona) && x.med.toLowerCase() === f.med.trim().toLowerCase() && statusOf(x, s.rt).state !== 'stopped') : undefined;
    const match = pidOk && f.med ? allergyMatch(pidOk as PersonId, f.med) : null;
    const allergyUnknown = pidOk ? PEOPLE[pidOk as PersonId].allergy.state === 'none' || PEOPLE[pidOk as PersonId].allergy.state === 'unavailable' : false;
    const changes = o && base ? ([['Dose', base.dose, f.dose], ['When', base.when, f.when], ['Limits', o.prn ?? '', f.prnLimits], ['End date', o.end ?? '', f.end]] as const).filter(([, a, b]) => a !== b) : [];
    const steps = [
        { key: 'source', label: 'Where it came from', blurb: 'Prescriber and how', icon: FileText },
        { key: 'med', label: o ? 'What’s changing' : 'The medicine', blurb: o ? `${o.med} · version ${(versionsOf(o, s.rt).length ?? 0) + 1}` : 'Dose, times and dates', icon: Pill },
        ...(spoken ? [{ key: 'readback', label: 'Read it back', blurb: 'With a witness', icon: Phone }] : []),
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const dirty = !!(f.source || f.dose !== (base?.dose ?? '') || f.med !== (o?.med ?? ''));
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'source') {
            if (!f.source) x['en-source'] = 'Choose where the order came from.';
            if (!f.prescriber) x['en-prescriber'] = 'Choose the prescriber.';
            if (f.prescriber === 'Someone else' && !f.other.trim()) x['en-other'] = 'Enter the prescriber’s name.';
            if (!f.at) x['en-at'] = 'Enter when.';
            else if (f.at > NOW_LOCAL) x['en-at'] = 'Choose a time before now (9:12 am).';
            if (f.source === 'written' && !f.file) x['en-file'] = 'Attach the prescription.';
        }
        if (k === 'med') {
            if (!f.pid) x['en-pid'] = 'Choose the person.';
            if (!f.med.trim()) x['en-med'] = 'Enter the medicine.';
            if (!f.dose.trim()) x['en-dose'] = 'Enter the dose.';
            if (!f.prn && !f.when.trim()) x['en-when'] = 'Enter when it’s given.';
            if (f.prn && !f.prnLimits.trim()) x['en-limits'] = 'Enter the limits from the prescription.';
            if (o && !changes.length) x['en-dose'] = 'Nothing has changed — change something, or cancel.';
        }
        if (k === 'readback') {
            if (!f.readBack) x['en-readback'] = 'Read the order back to the prescriber first.';
            if (!f.witness) x['en-witness'] = 'Choose who witnessed the read-back.';
            if (!/^\d{6}$/.test(f.pin)) x['en-pin'] = 'Enter their 6-digit PIN.';
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
        if (s.route.scenario === 'offline') return setOfflineErr(true);
        setPhase('sending');
        window.setTimeout(() => {
            const prescriber = f.prescriber === 'Someone else' ? f.other.trim() : f.prescriber;
            const ver: Version = {
                v: o ? versionsOf(o, s.rt).length + 1 : 1,
                kind: o ? 'change' : 'new',
                source: { type: f.source as SourceType, prescriber, at: 'Mon 28 Sep, 9:10 am', doc: f.file?.name, readBack: spoken ? { witness: f.witness, at: 'Mon 28 Sep, 9:11 am' } : undefined },
                dose: f.dose,
                when: f.prn ? 'When needed' : f.when,
                changed: o ? changes.map(([k, a, b]) => `${k} ${a || '—'} → ${b || '—'}`).join(' · ') : undefined,
                enteredBy: me.name,
                enteredAt: stamp(),
                state: 'waiting',
                written: spoken ? { due: 'end of tomorrow (Tuesday 29 September)', dueIso: '2026-09-29' } : undefined,
                allergy: match ? { match } : undefined,
            };
            s.update((rt) => {
                if (o) return { ...setVersions(rt, o, [...versionsOf(o, rt), ver]), events: [{ id: `ev-${Date.now()}`, at: stamp(), pid: o.pid, what: `Change entered — ${o.med} (version ${ver.v})`, who: me.name }, ...rt.events] };
                const n: Order = { id: `o-new-${Date.now()}`, pid: f.pid as PersonId, med: f.med.trim(), strength: f.strength.trim(), route: f.route, prn: f.prn ? f.prnLimits : undefined, indication: f.indication || '—', start: '28 September 2026', end: f.end || undefined, status: 'active', versions: [ver] };
                return { ...rt, added: [...rt.added, n], events: [{ id: `ev-${Date.now()}`, at: stamp(), pid: n.pid, what: `${SOURCE_LABEL[ver.source.type]} entered — ${n.med}`, who: me.name }, ...rt.events] };
            });
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        source: (
            <>
                {o ? <KV rows={[['Order', `${o.med} ${o.strength} · ${PEOPLE[o.pid].legal}`], ['Now', base ? `Version ${base.v}: ${when(base, o)}` : '—']]} /> : null}
                <div className="space-y-2">
                    <Label id="en-source-l">
                        Where did it come from? <span className="text-status-critical">*</span>
                    </Label>
                    <div id="en-source" tabIndex={-1}>
                        <TilePicker
                            labelledBy="en-source-l"
                            value={f.source || null}
                            invalid={!!e['en-source']}
                            onChange={(k) => set({ source: k as SourceType })}
                            tiles={[
                                { key: 'written', label: 'Written prescription', description: 'Paper, email or e-prescription — attach it', icon: FileText },
                                { key: 'phone', label: 'Phone order', description: 'Read it back, with a witness', icon: Phone },
                                { key: 'verbal', label: 'Verbal order, in person', description: 'Read it back, with a witness', icon: MessageSquare },
                            ]}
                        />
                    </div>
                    <InputError message={e['en-source']} />
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="en-prescriber">
                            Prescriber <span className="text-status-critical">*</span>
                        </Label>
                        <Select value={f.prescriber || undefined} onValueChange={(v) => set({ prescriber: v })}>
                            <SelectTrigger id="en-prescriber" className="w-full" aria-invalid={!!e['en-prescriber']}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {PRESCRIBERS.map((x) => (
                                    <SelectItem key={x} value={x}>
                                        {x}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['en-prescriber']} />
                    </div>
                    {f.prescriber === 'Someone else' ? (
                        <div className="space-y-1.5">
                            <Label htmlFor="en-other">
                                Their name <span className="text-status-critical">*</span>
                            </Label>
                            <Input id="en-other" value={f.other} aria-invalid={!!e['en-other']} onChange={(ev) => set({ other: ev.target.value })} />
                            <InputError message={e['en-other']} />
                        </div>
                    ) : null}
                </div>
                <DateTimeField id="en-at" label="Received" value={f.at} onChange={(v) => set({ at: v })} error={e['en-at']} />
                {f.source === 'written' ? (
                    <div className="space-y-1.5">
                        <Label id="en-file-l">
                            The prescription <span className="text-status-critical">*</span>
                        </Label>
                        {f.file ? <StagedFileCard file={f.file} onRemove={() => set({ file: null })} /> : <FileDropzone id="en-file" aria-labelledby="en-file-l" aria-invalid={!!e['en-file'] || undefined} multiple={false} accept=".pdf,image/*" hint="PDF or a photo of the prescription" onFiles={(x) => set({ file: x[0] ?? null })} />}
                        <InputError message={e['en-file']} />
                    </div>
                ) : null}
                {spoken ? <Notice tone="info" icon={Phone} title="Phone and verbal orders">You’ll read it back to the prescriber with a colleague witnessing (their witness PIN). The prescriber’s written confirmation is attached by a lead by the end of tomorrow.</Notice> : null}
            </>
        ),
        med: (
            <>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="en-pid">
                            Person <span className="text-status-critical">*</span>
                        </Label>
                        <Select value={f.pid || undefined} onValueChange={(v) => set({ pid: v as PersonId })} disabled={!!o}>
                            <SelectTrigger id="en-pid" className="w-full" aria-invalid={!!e['en-pid']}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {PEOPLE_ORDER.filter((x) => me.houses.includes(PEOPLE[x].house)).map((x) => (
                                    <SelectItem key={x} value={x}>
                                        {PEOPLE[x].legal}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['en-pid']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="en-med">
                            Medicine <span className="text-status-critical">*</span>
                        </Label>
                        <Input id="en-med" value={f.med} disabled={!!o} aria-invalid={!!e['en-med']} placeholder="e.g. Loratadine" onChange={(ev) => set({ med: ev.target.value })} />
                        <InputError message={e['en-med']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="en-strength">Strength and form</Label>
                        <Input id="en-strength" value={f.strength} disabled={!!o} placeholder="e.g. 10 mg tablet" onChange={(ev) => set({ strength: ev.target.value })} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="en-route">Route</Label>
                        <Select value={f.route} onValueChange={(v) => set({ route: v })} disabled={!!o}>
                            <SelectTrigger id="en-route" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {ROUTES.map((x) => (
                                    <SelectItem key={x} value={x}>
                                        {x}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="en-dose">
                            Dose <span className="text-status-critical">*</span>
                        </Label>
                        <Input id="en-dose" value={f.dose} aria-invalid={!!e['en-dose']} placeholder="e.g. 1 tablet" onChange={(ev) => set({ dose: ev.target.value })} />
                        <InputError message={e['en-dose']} />
                    </div>
                    {!f.prn ? (
                        <div className="space-y-1.5">
                            <Label htmlFor="en-when">
                                When <span className="text-status-critical">*</span>
                            </Label>
                            <Input id="en-when" value={f.when} aria-invalid={!!e['en-when']} placeholder="e.g. 8:00 am, once a day" onChange={(ev) => set({ when: ev.target.value })} />
                            <InputError message={e['en-when']} />
                        </div>
                    ) : (
                        <div className="space-y-1.5">
                            <Label htmlFor="en-limits">
                                Limits from the prescription <span className="text-status-critical">*</span>
                            </Label>
                            <Input id="en-limits" value={f.prnLimits} aria-invalid={!!e['en-limits']} placeholder="e.g. up to 4 doses in 24 hours, at least 4 hours apart" onChange={(ev) => set({ prnLimits: ev.target.value })} />
                            <InputError message={e['en-limits']} />
                        </div>
                    )}
                </div>
                <div className="flex items-center justify-between gap-4 rounded-xl border p-3">
                    <div>
                        <Label htmlFor="en-prn" className="text-sm font-medium">
                            Given when needed
                        </Label>
                        <p className="text-caption">An as-needed medicine, with limits from the prescription.</p>
                    </div>
                    <Switch id="en-prn" checked={f.prn} disabled={!!o} onCheckedChange={(v) => set({ prn: v })} />
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="en-ind">What it’s for</Label>
                        <Input id="en-ind" value={f.indication} disabled={!!o} placeholder="e.g. Hay fever" onChange={(ev) => set({ indication: ev.target.value })} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="en-end">End date (optional)</Label>
                        <Input id="en-end" value={f.end} placeholder="e.g. 1 October 2026 — a warning comes 14 days before" onChange={(ev) => set({ end: ev.target.value })} />
                    </div>
                </div>
                {dup ? (
                    <Notice tone="warning" title={`${PEOPLE[dup.pid].pref} already has ${dup.med}`} actions={<Button size="sm" variant="outline" onClick={() => s.set({ open: `change:${dup.id}` })}>Enter a change instead</Button>}>
                        {dup.med} {dup.strength}, {(() => { const d = currentOf(dup, s.rt) ?? pendingOf(dup, s.rt)!; return `version ${d.v}: ${when(d, dup)}`; })()}. Each medicine has one order — if the prescriber changed it, enter a change. Save a new order only if the prescriber wants both.
                    </Notice>
                ) : null}
                {match ? (
                    <Notice tone="critical" icon={ShieldCheck} title="Allergy match">
                        {match}. It can be saved, but it can’t be checked or given until the prescriber confirms it’s safe — a lead records that confirmation.
                    </Notice>
                ) : allergyUnknown && pidOk ? (
                    PEOPLE[pidOk as PersonId].allergy.state === 'unavailable' ? (
                        <Notice tone="warning" title={`${PEOPLE[pidOk as PersonId].pref}’s allergy record couldn’t be loaded`}>Check the health profile before ordering. This isn’t “no known allergies”.</Notice>
                    ) : (
                        <Notice tone="warning" title={`${PEOPLE[pidOk as PersonId].pref}’s allergies aren’t recorded`}>Ask the prescriber about allergies, and record them on the health profile. This isn’t “no known allergies”.</Notice>
                    )
                ) : null}
                {!o && pidOk ? <p className="text-caption">A new medicine is Administer — staff give it — until its support is set in {PEOPLE[pidOk as PersonId].pref}’s Support plan.</p> : null}
            </>
        ),
        readback: (
            <>
                <KV rows={[['Read back', `${f.med} ${f.strength} — ${f.dose}, ${f.prn ? `when needed, ${f.prnLimits}` : f.when}`], ['To', f.prescriber === 'Someone else' ? f.other : f.prescriber]]} />
                <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                    <Checkbox id="en-readback" checked={f.readBack} onCheckedChange={(c) => set({ readBack: !!c })} aria-invalid={!!e['en-readback'] || undefined} />
                    I read the order back to the prescriber and they confirmed it
                </label>
                <InputError message={e['en-readback']} />
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="en-witness">
                            Witnessed by <span className="text-status-critical">*</span>
                        </Label>
                        <Select value={f.witness || undefined} onValueChange={(v) => set({ witness: v })}>
                            <SelectTrigger id="en-witness" className="w-full" aria-invalid={!!e['en-witness']}>
                                <SelectValue placeholder="Someone who heard it" />
                            </SelectTrigger>
                            <SelectContent>
                                {STAFF_ON_SHIFT.filter((x) => x !== me.name).map((x) => (
                                    <SelectItem key={x} value={x}>
                                        {x}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['en-witness']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="en-pin">
                            Witness’s 6-digit PIN <span className="text-status-critical">*</span>
                        </Label>
                        <Input id="en-pin" type="password" inputMode="numeric" autoComplete="off" maxLength={6} value={f.pin} aria-invalid={!!e['en-pin']} onChange={(ev) => set({ pin: ev.target.value.replace(/\D/g, '') })} />
                        <p className="text-caption">Their own witness PIN — not their login password. They type it here.</p>
                        <InputError message={e['en-pin']} />
                    </div>
                </div>
            </>
        ),
        review: (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={FileText} title="Where it came from" onEdit={() => setStep(0)}>
                    <ReviewRow label="Source" value={f.source ? SOURCE_LABEL[f.source] : '—'} />
                    <ReviewRow label="Prescriber" value={f.prescriber === 'Someone else' ? f.other : f.prescriber} />
                    {f.file ? <ReviewRow label="Document" value={f.file.name} /> : null}
                    {spoken ? <ReviewRow label="Read back" value={`Witnessed by ${f.witness}`} /> : null}
                </ReviewCard>
                <ReviewCard icon={Pill} title={o ? 'What’s changing' : 'The medicine'} onEdit={() => setStep(1)}>
                    {o ? changes.map(([k, a, b]) => <ReviewRow key={k} label={k} value={`${a || '—'} → ${b || '—'}`} />) : null}
                    {!o ? <ReviewRow label="Medicine" value={`${f.med} ${f.strength}`} /> : null}
                    {!o ? <ReviewRow label="Dose and when" value={`${f.dose} · ${f.prn ? `when needed — ${f.prnLimits}` : f.when}`} /> : null}
                    {match ? <ReviewRow label="Allergy" value={<StatusBadge variant="critical" className="rounded-[8px]">Prescriber must confirm</StatusBadge>} /> : null}
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you save">
                        {o ? `Version ${versionsOf(o, s.rt).length + 1} is saved as “Waiting to be checked”. Staff keep giving version ${base?.v} until someone other than you checks it.` : 'The order is saved as “Waiting to be checked”. It can’t be given until someone other than you checks it.'}
                        {match ? ' It can’t be checked until the prescriber confirms it’s safe with the allergy.' : ''}
                        {spoken ? ' The prescriber’s written confirmation is due by the end of tomorrow.' : ''}
                    </Notice>
                </div>
                {offlineErr ? (
                    <div className="sm:col-span-2">
                        <Notice tone="critical" title="Not saved">
                            {cantSaveOffline}
                        </Notice>
                    </div>
                ) : null}
            </div>
        ),
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={o ? `Enter a change — ${o.med}, ${PEOPLE[o.pid].pref}` : 'Enter an order'}
                description="Where it came from, the medicine, then review and save."
                railIcon={o ? Undo2 : ClipboardList}
                railTitle={o ? 'Enter a change' : 'Enter an order'}
                railSub={o ? `${PEOPLE[o.pid].pref} · version ${versionsOf(o, s.rt).length + 1}` : 'A prescriber’s order'}
                steps={steps.map((x, i) => ({ ...x, disabled: i > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(i) => i <= step && setStep(i)}
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
                            {phase === 'sending' ? 'Saving…' : o ? 'Save the change' : 'Save the order'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title={o ? 'Change saved' : 'Order saved'}
                            blurb={`Waiting to be checked by someone other than you${match ? ', once the prescriber confirms it’s safe with the allergy' : ''}. It’s in Orders & reviews › To check.`}
                            actions={
                                <Button type="button" autoFocus onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(86vh, 780px)"
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
                title={o ? 'Discard this change?' : 'Discard this order?'}
                description="Nothing has been saved."
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

/* ═════════════ Check a version (VerifyOrderDialog + RejectOrderDialog, redesigned — Q2) ═════════════ */
export function CheckDialog({ orderId, onClose, returnFocus }: { orderId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const o = allOrders(s.rt).find((x) => x.id === orderId)!;
    const vs = versionsOf(o, s.rt);
    const cur = currentOf(o, s.rt);
    const pend = pendingOf(o, s.rt);
    const second = !pend && cur?.state === 'lone';
    const v = (second ? cur : pend)!;
    const prev = second ? null : cur;
    const why = whyCantCheck(v, p);
    const others = CHECKERS.filter((c) => c.name !== me.name && c.name !== v.enteredBy && c.houses.includes(PEOPLE[o.pid].house));
    const [ticks, setTicks] = useState({ source: false, right: false, allergy: false });
    const [decision, setDecision] = useState<'' | 'ok' | 'back'>('');
    const [reason, setReason] = useState('');
    const [lone, setLone] = useState(false);
    const [loneReason, setLoneReason] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const blockedAllergy = !!v.allergy && !v.allergy.confirmed;
    function save() {
        const x: Record<string, string> = {};
        if (!decision) x['ck-decision'] = 'Choose checked or send back.';
        if (decision === 'ok' && !(ticks.source && ticks.right && ticks.allergy)) x['ck-ticks'] = 'Tick all three before marking it checked.';
        if (decision === 'back' && !reason.trim()) x['ck-reason'] = 'Say what needs fixing.';
        if (why && lone && !loneReason.trim()) x['ck-lone'] = 'Say why nobody else can check it today.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'ck-decision': cantSaveOffline });
        const updated: Version =
            decision === 'back'
                ? { ...v, state: 'sentBack', sentBack: { by: me.name, at: stamp(), reason: reason.trim() } }
                : second
                  ? { ...v, state: 'checked', checked: { by: `${v.checked!.by}, second check ${me.name}`, at: stamp() } }
                  : { ...v, state: why ? 'lone' : 'checked', checked: { by: me.name, at: stamp(), lone: why ? { reason: loneReason.trim(), secondBy: 'a house or clinical lead', secondDue: 'end of tomorrow (Tuesday 29 September)' } : undefined } };
        s.update((rt) => ({ ...setVersions(rt, o, versionsOf(o, rt).map((x) => (x.v === v.v ? updated : x))), events: [{ id: `ev-${Date.now()}`, at: stamp(), pid: o.pid, what: decision === 'back' ? `Sent back — ${o.med} version ${v.v}` : second ? `Second check — ${o.med}` : `${why ? 'Checked alone' : 'Order checked'} — ${o.med} version ${v.v}`, who: me.name }, ...rt.events] }));
        s.toast('success', decision === 'back' ? `Sent back to ${v.enteredBy}. Staff keep giving ${cur ? `version ${cur.v}` : 'nothing — it isn’t on the chart yet'}.` : `${o.med} version ${v.v} is checked — Meds today uses it now.${why ? ' A second check is due by the end of tomorrow.' : ''}`);
        onClose();
    }
    const canDecide = !blockedAllergy && (!why || (lone && !second && canCheck(p) && why.startsWith('You entered')));
    return (
        <Modal
            width={900}
            title={`${second ? 'Second check' : `Check version ${v.v}`} — ${o.med}, ${PEOPLE[o.pid].pref}`}
            description={`${SOURCE_LABEL[v.source.type]} from ${v.source.prescriber}, ${v.source.at} · entered ${v.enteredAt} by ${v.enteredBy}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    {canDecide ? <Button onClick={save}>{decision === 'back' ? 'Send it back' : 'Save the check'}</Button> : null}
                </>
            }
        >
            {blockedAllergy ? (
                <Notice tone="critical" icon={ShieldCheck} title="It can’t be checked yet">
                    Allergy match: {v.allergy!.match}. A lead records the prescriber’s confirmation that it’s safe first.
                    {canEnter(p) ? (
                        <span className="mt-2 flex">
                            <Button size="sm" variant="outline" onClick={() => s.set({ open: `allergy:${o.id}` })}>
                                Record the prescriber’s confirmation
                            </Button>
                        </span>
                    ) : null}
                </Notice>
            ) : null}
            {why ? (
                <Notice tone="warning" title={lone ? 'You’re checking it alone — a second check follows' : why} actions={why.startsWith('You entered') && canCheck(p) && !lone && !second ? <Button size="sm" variant="outline" onClick={() => setLone(true)}>Nobody else can check today</Button> : undefined}>
                    {lone ? `The second check goes to ${others.length ? others.map((c) => `${c.name} (${c.role.toLowerCase()})`).join(' or ') : 'a house or clinical lead'} as a follow-up, due by the end of tomorrow.` : others.length ? `Who else can check it: ${others.map((c) => `${c.name} (${c.role.toLowerCase()})`).join(', ')}.` : 'Nobody else at this house can check orders.'}
                </Notice>
            ) : null}
            {lone ? (
                <div className="space-y-1.5">
                    <Label htmlFor="ck-lone">
                        Why can’t anyone else check it today? <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea id="ck-lone" rows={2} value={loneReason} aria-invalid={!!e['ck-lone']} placeholder="e.g. Hana and Rangi are on leave; Mele needs the first dose this morning." onChange={(ev) => (setLoneReason(ev.target.value), setE({}))} />
                                        <InputError message={e['ck-lone']} />
                </div>
            ) : null}
            <div className="grid gap-3 md:grid-cols-2">
                <div className="space-y-1 rounded-lg border p-3 text-sm">
                    <p className="text-[12px] font-semibold text-muted-foreground uppercase">{prev ? `Now — version ${prev.v}` : 'Now'}</p>
                    <p>{prev ? when(prev, o) : 'Not on the chart yet'}</p>
                </div>
                <div className="space-y-1 rounded-lg border border-primary/40 p-3 text-sm">
                    <p className="text-[12px] font-semibold text-muted-foreground uppercase">{second ? `Checked alone — version ${v.v}` : `To check — version ${v.v}`}</p>
                    <p className="font-semibold">{when(v, o)}</p>
                    {v.changed ? <p className="text-caption">Changed: {v.changed}</p> : null}
                </div>
            </div>
            <KV
                rows={[
                    ['Source', v.source.doc ? `${SOURCE_LABEL[v.source.type]} · ${v.source.doc}` : SOURCE_LABEL[v.source.type]],
                    ...(v.source.readBack ? ([['Read back', `${v.source.readBack.at} · witnessed by ${v.source.readBack.witness}`]] as [string, string][]) : []),
                    ['Allergies', allergyText(o.pid)],
                    ...(v.allergy?.confirmed ? ([['Prescriber confirmed', `${v.allergy.confirmed.prescriber}: “${v.allergy.confirmed.note}”`]] as [string, string][]) : []),
                    ...(second ? ([['Checked alone', `${v.checked!.by}, ${v.checked!.at}: “${v.checked!.lone!.reason}”`]] as [string, string][]) : []),
                ]}
            />
            {canDecide ? (
                <>
                    <div className="space-y-2">
                        <Label id="ck-decision-l">
                            Your check <span className="text-status-critical">*</span>
                        </Label>
                        <div id="ck-decision" tabIndex={-1}>
                            <TilePicker
                                labelledBy="ck-decision-l"
                                value={decision || null}
                                invalid={!!e['ck-decision']}
                                onChange={(k) => (setDecision(k as 'ok' | 'back'), setE({}))}
                                tiles={[
                                    { key: 'ok', label: 'Checked — it can be given', description: second ? 'The second check is done' : `Meds today uses version ${v.v} from now`, icon: ClipboardCheck },
                                    ...(lone ? [] : [{ key: 'back', label: 'Send it back', description: `To ${v.enteredBy}, with what needs fixing`, icon: Undo2 }]),
                                ]}
                            />
                        </div>
                        <InputError message={e['ck-decision']} />
                    </div>
                    {decision === 'ok' ? (
                        <div id="ck-ticks" tabIndex={-1} className="space-y-2">
                            {(
                                [
                                    ['source', 'It matches the prescription or the read-back'],
                                    ['right', 'The dose, route and times are right for this person'],
                                    ['allergy', 'I checked their allergies and other medicines'],
                                ] as const
                            ).map(([k, l]) => (
                                <label key={k} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                                    <Checkbox checked={ticks[k]} onCheckedChange={(c) => (setTicks((t) => ({ ...t, [k]: !!c })), setE({}))} />
                                    {l}
                                </label>
                            ))}
                            <InputError message={e['ck-ticks']} />
                        </div>
                    ) : decision === 'back' ? (
                        <div className="space-y-1.5">
                            <Label htmlFor="ck-reason">
                                What needs fixing? <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea id="ck-reason" rows={2} value={reason} aria-invalid={!!e['ck-reason']} placeholder="e.g. The letter says 3 mg — check with Dr Chen." onChange={(ev) => (setReason(ev.target.value), setE({}))} />
                            <InputError message={e['ck-reason']} />
                        </div>
                    ) : null}
                </>
            ) : null}
            <p className="text-caption">{vs.length > 1 ? 'Earlier versions are kept in the order’s Versions.' : 'A new order can’t be given until it’s checked.'}</p>
        </Modal>
    );
}

/* ═════════════ The prescriber’s written confirmation (replaces CountersignDialog — Q3) ═════════════ */
export function WrittenDialog({ orderId, onClose, returnFocus }: { orderId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const o = allOrders(s.rt).find((x) => x.id === orderId)!;
    const v = currentOf(o, s.rt)!;
    const [how, setHow] = useState('');
    const [at, setAt] = useState(NOW_LOCAL);
    const [file, setFile] = useState<File | null>(null);
    const [matches, setMatches] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        const x: Record<string, string> = {};
        if (!how) x['wr-how'] = 'Choose how it arrived.';
        if (!file) x['wr-file'] = 'Attach it.';
        if (!matches) x['wr-matches'] = 'Say whether it matches the phone order.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'wr-how': cantSaveOffline });
        const label = { script: 'Signed prescription', email: 'Email from the prescriber', eprescription: 'E-prescription' }[how as 'script'];
        s.update((rt) => ({ ...setVersions(rt, o, versionsOf(o, rt).map((x) => (x.v === v.v ? { ...x, written: { ...x.written!, done: { by: me.name, at: stamp(), how: label, file: file!.name } } } : x))), events: [{ id: `ev-${Date.now()}`, at: stamp(), pid: o.pid, what: `Written confirmation attached — ${o.med}`, who: me.name }, ...rt.events] }));
        s.toast('success', matches === 'yes' ? `Written confirmation attached to ${o.med}. The follow-up is closed.` : `Attached. It differs from the phone order — enter a change so the order matches, and it’s checked again.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Prescriber’s written confirmation — ${o.med}, ${PEOPLE[o.pid].pref}`}
            description={`${SOURCE_LABEL[v.source.type]} from ${v.source.prescriber}, ${v.source.at} · due by ${v.written!.due}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Attach it</Button>
                </>
            }
        >
            <KV rows={[['The phone order', when(v, o)], ['Read back', v.source.readBack ? `${v.source.readBack.at} · witnessed by ${v.source.readBack.witness}` : '—'], ['Checked', v.checked ? `${v.checked.at} by ${v.checked.by}` : 'Not yet']]} />
            <div className="space-y-2">
                <Label id="wr-how-l">
                    How did it arrive? <span className="text-status-critical">*</span>
                </Label>
                <div id="wr-how" tabIndex={-1}>
                    <TilePicker
                        labelledBy="wr-how-l"
                        value={how || null}
                        invalid={!!e['wr-how']}
                        onChange={(k) => (setHow(k), setE({}))}
                        tiles={[
                            { key: 'script', label: 'Signed prescription', description: 'Paper, scanned or photographed', icon: PenLine },
                            { key: 'email', label: 'Email from the prescriber', description: 'From their practice address', icon: Mail },
                            { key: 'eprescription', label: 'E-prescription', description: 'From the pharmacy or practice system', icon: FileSignature },
                        ]}
                    />
                </div>
                <InputError message={e['wr-how']} />
            </div>
            <DateTimeField id="wr-at" label="Received" value={at} onChange={setAt} />
            <div className="space-y-1.5">
                <Label id="wr-file-l">
                    The confirmation <span className="text-status-critical">*</span>
                </Label>
                {file ? <StagedFileCard file={file} onRemove={() => setFile(null)} /> : <FileDropzone id="wr-file" aria-labelledby="wr-file-l" aria-invalid={!!e['wr-file'] || undefined} multiple={false} accept=".pdf,image/*,.eml,.msg" hint="PDF, a photo, or the email" onFiles={(x) => (setFile(x[0] ?? null), setE({}))} />}
                <InputError message={e['wr-file']} />
            </div>
            <div className="space-y-2">
                <Label id="wr-matches-l">
                    Does it match the phone order? <span className="text-status-critical">*</span>
                </Label>
                <div id="wr-matches" tabIndex={-1}>
                    <TilePicker
                        labelledBy="wr-matches-l"
                        value={matches || null}
                        invalid={!!e['wr-matches']}
                        onChange={(k) => (setMatches(k), setE({}))}
                        tiles={[
                            { key: 'yes', label: 'Yes, it matches', description: 'The follow-up closes', icon: Check },
                            { key: 'no', label: 'No, it’s different', description: 'Then enter a change so the order matches', icon: AlertTriangle },
                        ]}
                    />
                </div>
                <InputError message={e['wr-matches']} />
            </div>
            <p className="text-caption">The prescriber’s confirmation is kept with this version of the order.</p>
            <DesignNote title="Design note — replaces CountersignDialog (Main, Q3)">
                <p>Today a staff member “countersigns on the prescriber’s authority”. Here the prescriber’s own written confirmation is attached instead.</p>
            </DesignNote>
        </Modal>
    );
}

/* ═════════════ The prescriber confirmed it’s safe with the allergy (Q4) ═════════════ */
export function AllergyDialog({ orderId, onClose, returnFocus }: { orderId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const o = allOrders(s.rt).find((x) => x.id === orderId)!;
    const v = pendingOf(o, s.rt)!;
    const [prescriber, setPrescriber] = useState(v.source.prescriber);
    const [how, setHow] = useState('');
    const [at, setAt] = useState(NOW_LOCAL);
    const [note, setNote] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    function save() {
        const x: Record<string, string> = {};
        if (!prescriber.trim()) x['al-prescriber'] = 'Enter the prescriber.';
        if (!how) x['al-how'] = 'Choose how they confirmed it.';
        if (!note.trim()) x['al-note'] = 'Write what the prescriber said.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'al-how': cantSaveOffline });
        s.update((rt) => ({ ...setVersions(rt, o, versionsOf(o, rt).map((y) => (y.v === v.v ? { ...y, allergy: { ...y.allergy!, confirmed: { prescriber: prescriber.trim(), by: me.name, at: stamp(), how: how === 'phone' ? 'Phone call' : how === 'writing' ? 'In writing' : 'In person', note: note.trim() } } } : y))), events: [{ id: `ev-${Date.now()}`, at: stamp(), pid: o.pid, what: `Prescriber confirmed it’s safe — ${o.med} and the allergy`, who: me.name }, ...rt.events] }));
        s.toast('success', `Recorded. ${o.med} can now be checked by someone other than ${v.enteredBy}.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`The prescriber confirmed it’s safe — ${o.med}, ${PEOPLE[o.pid].pref}`}
            description={`Allergy match: ${v.allergy!.match}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Record it</Button>
                </>
            }
        >
            <Notice tone="warning" icon={ShieldCheck} title="Only record what the prescriber said">
                Ask the prescriber whether {o.med} is safe for {PEOPLE[o.pid].pref} given the allergy. If they say no, or you can’t reach them, don’t record this — they change the order instead.
            </Notice>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="al-prescriber">
                        Prescriber <span className="text-status-critical">*</span>
                    </Label>
                    <Select value={prescriber || undefined} onValueChange={(x) => (setPrescriber(x), setE({}))}>
                        <SelectTrigger id="al-prescriber" className="w-full" aria-invalid={!!e['al-prescriber']}>
                            <SelectValue placeholder="Choose" />
                        </SelectTrigger>
                        <SelectContent>
                            {[...new Set([v.source.prescriber, ...PRESCRIBERS.filter((x) => x !== 'Someone else')])].map((x) => (
                                <SelectItem key={x} value={x}>
                                    {x}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={e['al-prescriber']} />
                </div>
            </div>
            <div className="space-y-2">
                <Label id="al-how-l">
                    How did they confirm it? <span className="text-status-critical">*</span>
                </Label>
                <div id="al-how" tabIndex={-1}>
                    <TilePicker
                        labelledBy="al-how-l"
                        value={how || null}
                        invalid={!!e['al-how']}
                        onChange={(k) => (setHow(k), setE({}))}
                        tiles={[
                            { key: 'phone', label: 'Phone call', description: 'You spoke to them', icon: Phone },
                            { key: 'writing', label: 'In writing', description: 'Letter, email or the prescription', icon: FileText },
                            { key: 'person', label: 'In person', description: 'At an appointment', icon: User },
                        ]}
                    />
                </div>
                <InputError message={e['al-how']} />
            </div>
            <DateTimeField id="al-at" label="Confirmed" value={at} onChange={setAt} />
            <div className="space-y-1.5">
                <Label htmlFor="al-note">
                    What the prescriber said <span className="text-status-critical">*</span>
                </Label>
                <Textarea id="al-note" rows={3} value={note} aria-invalid={!!e['al-note']} placeholder="e.g. The vomiting was a side effect, not an allergy. Give with food; stop and call if it happens again." onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                <InputError message={e['al-note']} />
            </div>
            <p className="text-caption">It stays with this version of the order and is shown to staff when they give it. It doesn’t change the allergy list — update that on the health profile if the prescriber says so.</p>
        </Modal>
    );
}

/* ═════════════ Stop an order (DiscontinueDialog + CancelOrderDialog, redesigned) ═════════════ */
export function StopDialog({ orderId, onClose, returnFocus }: { orderId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const o = allOrders(s.rt).find((x) => x.id === orderId)!;
    const [from, setFrom] = useState('');
    const [reason, setReason] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    function check() {
        const x: Record<string, string> = {};
        if (!from) x['st-from'] = 'Choose who stopped it.';
        if (!reason.trim()) x['st-reason'] = 'Say why it’s stopping.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'st-from': cantSaveOffline });
        setConfirm(true);
    }
    function commit() {
        s.update((rt) => ({ ...rt, stopped: { ...rt.stopped, [o.id]: { on: '28 September 2026', reason: reason.trim(), by: me.name } }, events: [{ id: `ev-${Date.now()}`, at: stamp(), pid: o.pid, what: `Order stopped — ${o.med} (${from})`, who: me.name }, ...rt.events] }));
        s.toast('success', `${o.med} is stopped for ${PEOPLE[o.pid].pref}. It’s off Meds today now; someone else checks the stop by the end of tomorrow.`);
        onClose();
    }
    return (
        <>
            <Modal
                width={720}
                title={`Stop ${o.med} — ${PEOPLE[o.pid].pref}`}
                description={`${o.strength} · current version ${currentOf(o, s.rt)?.v ?? '—'}`}
                onClose={onClose}
                onCloseAutoFocus={restore(returnFocus)}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={check}>
                            Stop the order
                        </Button>
                    </>
                }
            >
                <div className="space-y-2">
                    <Label id="st-from-l">
                        Who stopped it? <span className="text-status-critical">*</span>
                    </Label>
                    <div id="st-from" tabIndex={-1}>
                        <TilePicker
                            labelledBy="st-from-l"
                            value={from || null}
                            invalid={!!e['st-from']}
                            onChange={(k) => (setFrom(k), setE({}))}
                            tiles={[
                                { key: 'The prescriber, in writing', label: 'The prescriber, in writing', description: 'Attach it from the order afterwards', icon: FileText },
                                { key: 'The prescriber, by phone', label: 'The prescriber, by phone', description: 'The written confirmation follows', icon: Phone },
                                { key: 'The course finished', label: 'The course finished', description: 'As prescribed', icon: Check },
                                { key: 'Entered by mistake', label: 'Entered by mistake', description: 'It was never meant to be given', icon: AlertTriangle },
                            ]}
                        />
                    </div>
                    <InputError message={e['st-from']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="st-reason">
                        Why? <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea id="st-reason" rows={2} value={reason} aria-invalid={!!e['st-reason']} placeholder="e.g. Chest infection cleared — Dr Chen stopped it at the review." onChange={(ev) => (setReason(ev.target.value), setE({}))} />
                    <InputError message={e['st-reason']} />
                </div>
                <p className="text-caption">It stops straight away, so nobody gives it by mistake. The reason is kept on the order, and someone else checks the stop by the end of tomorrow.</p>
            </Modal>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    commit();
                }}
                title={`Stop ${o.med} for ${PEOPLE[o.pid].pref}?`}
                description={`It comes off Meds today and the chart now. ${o.covert ? 'The covert authorisation ends with it. ' : ''}The order and its versions are kept.`}
                confirmText="Stop the order"
                cancelText="Keep it"
            />
        </>
    );
}

export { concealed };
