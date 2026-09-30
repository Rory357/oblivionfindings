/* P08a’s simple dialogs, in the Fleet Settings Modal layout (./modal):
 * follow-up detail with its history, reassign (Main, Q4), the house lead’s
 * sign-offs, the phone-instruction countersign, the handover heads-up (Q5),
 * P01 v2’s “Were you there?” (FallbackConfirmDialog, reused) and the
 * as-needed dose detail (PrnDetailDialog redesign). Real Dialog, Command,
 * Select, Textarea, TilePicker, StatusBadge, ConfirmDialog. */
import ConfirmDialog from '@/components/confirm-dialog';
import InputError from '@/components/input-error';
import { PersonDisc } from '@/components/lists/entity-cells';
import { Button } from '@/components/ui/button';
import { Command, CommandGroup, CommandItem, CommandList } from '@/components/ui/command';
import { Label } from '@/components/ui/label';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { Check, CheckCircle2, Flag, MessageSquareWarning, Send, UserCheck, X } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LABEL } from './clock';
import { FROM_PACKAGE, HOUSES, PEOPLE, PERSONAS, TYPE_LABEL, hoById, type FollowUp } from './data';
import { finish, Modal, NotFoundDialog } from './modal';
import { DELIVERY, canAct, deliveryLine, dueLabel, isLead, onShiftAt, overdueBy } from './model';
import { useStore, type Row } from './store';
import { DesignNote, FuBadge, KV, Notice, NotConfigured, TilePicker } from './ui';

/* ───────────── history and state lines shared by the detail and rows ───────────── */
export function stateLines(row: Row, scn: string, me?: string): { text: string; tone?: 'warning' | 'critical' | 'success' }[] {
    const { f, rt, state } = row;
    const out: { text: string; tone?: 'warning' | 'critical' | 'success' }[] = [];
    const done = rt?.done ?? f.done;
    if (done) out.push({ text: `${done.outcome} · ${done.at} by ${done.by}${done.late ? ` · ${done.late}` : ''}`, tone: done.late ? 'warning' : 'success' });
    else {
        const c = rt?.couldnt ?? f.couldnt;
        if (c) out.push({ text: `Couldn’t check at ${c.at} — ${c.reason.toLowerCase()} · check again at ${c.again}`, tone: state === 'overdue' ? 'critical' : 'warning' });
        if (state === 'overdue' && !c) out.push({ text: `${overdueBy(f.dueMin)} overdue`, tone: 'critical' });
        if (f.type === 'confirm' && !rt?.answer) out.push({ text: me === f.owner ? `Your answer is needed by ${f.due}` : `Waiting for ${f.owner} to answer · by ${f.due}` });
        if (f.escalated) out.push({ text: '3 refusals in 7 days — house lead and clinical lead told', tone: 'warning' });
        const d = deliveryLine(f, state, scn as never);
        if (d) out.push({ text: d, tone: 'critical' });
    }
    return out;
}
export function ownerLines(row: Row): string[] {
    const { f, rt } = row;
    const out: string[] = [];
    const re = rt?.reassigned ?? f.reassigned;
    if (re) out.push(`Reassigned from ${re.from} by ${re.by} at ${re.at}`);
    if (f.carried) out.push(row.owner ? `Carried over from ${f.carried.from} · owner set at ${row.ownerSetAt ?? f.carried.ownerSetAt}` : `Carried over from ${f.carried.from}`);
    return out;
}

/* ───────────── follow-up detail (history, who can act, reminders) ───────────── */
export function FollowUpDetailDialog({ id, onClose, onAction }: { id: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const row = s.row(id);
    if (!row) return <NotFoundDialog onClose={onClose} />;
    const { f, state } = row;
    const act = canAct(f, s.route.persona);
    const open = !(row.rt?.done ?? f.done);
    const primary = open && act.close ? primaryAction(f) : null;
    const base = f.carried && row.ownerSetAt !== f.carried.ownerSetAt ? [...f.events.filter((e) => !e.what.startsWith('Owner set when')), ...(row.ownerSetAt && row.owner ? [{ at: row.ownerSetAt, what: 'Owner set when the handover was acknowledged', who: row.owner }] : [])] : f.events;
    const events = [...base, ...(row.rt?.reassigned ? [{ at: row.rt.reassigned.at, what: `Reassigned to ${row.rt.owner}${row.rt.reassigned.why ? ` — “${row.rt.reassigned.why}”` : ''}`, who: row.rt.reassigned.by }] : []), ...(row.rt?.couldnt ? [{ at: row.rt.couldnt.at, what: `Couldn’t check — ${row.rt.couldnt.reason.toLowerCase()} · check again at ${row.rt.couldnt.again}`, who: row.rt.couldnt.by }] : []), ...(row.rt?.done ? [{ at: row.rt.done.at, what: `Done — ${row.rt.done.outcome}${row.rt.done.late ? ` (${row.rt.done.late})` : ''}`, who: row.rt.done.by }] : [])];
    return (
        <Modal
            width={720}
            title={f.title}
            description={`${TYPE_LABEL[f.type]} · ${HOUSES[f.house]} · ${f.source}`}
            onClose={onClose}
            footer={
                <>
                    {open && act.reassign && f.type !== 'confirm' ? (
                        <Button variant="outline" onClick={() => onAction(`reassign:${f.id}`)}>
                            <UserCheck className="size-4" /> Reassign
                        </Button>
                    ) : null}
                    {primary ? <Button onClick={() => onAction(primary.spec)}>{primary.label}</Button> : null}
                    {!primary ? (
                        <Button variant="outline" onClick={onClose} autoFocus>
                            Close
                        </Button>
                    ) : null}
                </>
            }
        >
            <div className="flex flex-wrap items-center gap-2">
                <FuBadge state={state} />
                {row.state === 'overdue' ? <span className="text-caption">{overdueBy(row.f.dueMin)} overdue</span> : null}
            </div>
            <KV
                rows={[
                    ['Owner', <span key="o">{row.owner ?? 'Set when the handover is acknowledged'}{ownerLines(row).length ? <span className="block text-caption">{ownerLines(row).join(' · ')}</span> : null}</span>],
                    ['Due', dueLabel(f)],
                    ['Who can close it', act.close ? 'You can' : f.type === 'confirm' ? `Only ${f.owner}` : isLead(f) ? 'House or clinical leads' : 'The owner or anyone rostered at the house'],
                    ['Who sees it', 'Everyone rostered at the house and the house lead, until it’s done'],
                    ['Reminders', s.route.scenario === 'delivery' ? `On — every ${DELIVERY.realertEvery} minutes, up to ${DELIVERY.realertMax} times; escalate to ${DELIVERY.escalateTo} after ${DELIVERY.escalateAfter} minutes; stops when ${DELIVERY.attended}` : <span key="r">Off — <NotConfigured /> until a manager switches them on (Settings › Alerts › Delivery)</span>],
                ]}
            />
            <section aria-label="History" className="rounded-xl border">
                <p className="border-b px-3 py-2 text-sm font-semibold">History · times in NZDT</p>
                <ol className="divide-y">
                    {events.map((ev, i) => (
                        <li key={i} className="grid gap-1 px-3 py-2 text-sm sm:grid-cols-[150px_1fr]">
                            <span className="text-muted-foreground tabular-nums">{ev.at}</span>
                            <span>
                                {ev.what}
                                <span className="block text-caption">{ev.who}</span>
                            </span>
                        </li>
                    ))}
                </ol>
            </section>
            {!open ? null : !act.close ? <Notice tone="neutral" title="You can see this, but not close it">{act.why}</Notice> : null}
            <DesignNote>Created by: {FROM_PACKAGE[f.type]}. One record — the same item shows in Meds today, Safety &amp; oversight, All Tasks and the handover.</DesignNote>
        </Modal>
    );
}
export function primaryAction(f: FollowUp): { label: string; spec: string } | null {
    switch (f.type) {
        case 'effect':
            return { label: 'Check effect', spec: `effect:${f.id}` };
        case 'reoffer':
            return { label: 'Follow up', spec: `refusal:${f.id}` };
        case 'confirm':
            return { label: 'Answer', spec: `confirm:${f.id}` };
        case 'countersign':
            return { label: 'Countersign', spec: `countersign:${f.id}` };
        case 'handover':
            return { label: 'Deal with it', spec: `headsup:${f.id}` };
        case 'override':
            return { label: 'Count and sign off', spec: `override:${f.id}` };
        default:
            return { label: 'Check and sign off', spec: `signoff:${f.id}` };
    }
}

/* ───────────── reassign (Main, Q4: to someone rostered at the house) ───────────── */
export function ReassignDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const row = s.row(id);
    const [to, setTo] = useState<string | null>(null);
    const [why, setWhy] = useState('');
    const [err, setErr] = useState('');
    if (!row || !canAct(row.f, s.route.persona).reassign) return <NotFoundDialog onClose={onClose} />;
    const staff = onShiftAt(row.f.house).filter((x) => x.name !== row.owner);
    return (
        <Modal
            title="Reassign this follow-up"
            description={`${row.f.title} · owner now ${row.owner ?? 'not set'}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={() => {
                            if (!to) return setErr('Choose who takes it over.');
                            const who = staff.find((x) => x.id === to)!;
                            s.update(row.f.id, { owner: who.name, reassigned: { from: row.owner ?? 'nobody', by: PERSONAS[s.route.persona].name, at: NOW_LABEL, why: why.trim() } });
                            s.toast('success', `Reassigned to ${who.name}. They’re told in-app; the history keeps who had it before.`);
                            onClose();
                        }}
                    >
                        Reassign
                    </Button>
                </>
            }
        >
            <div className="space-y-1.5">
                <Label id="ra-l">
                    Give it to <span className="text-status-critical">*</span>
                </Label>
                <Command className="rounded-lg border" aria-labelledby="ra-l">
                    <CommandList className="max-h-[260px]">
                        <CommandGroup heading={`Rostered on a covering shift at ${HOUSES[row.f.house]} now`}>
                            {staff.map((x) => (
                                <CommandItem key={x.id} value={x.name} onSelect={() => (setTo(x.id), setErr(''))} className="items-start" data-reassign={x.id}>
                                    <PersonDisc name={x.name} size={24} />
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium">{x.name}</span>
                                        <span className="block text-xs text-muted-foreground">
                                            {x.role} · {x.shift} · {x.now}
                                        </span>
                                    </span>
                                    {to === x.id ? <Check className="ml-auto size-4" /> : null}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
                <InputError message={err} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="ra-why">
                    Why <span className="text-subtle">(optional)</span>
                </Label>
                <Textarea id="ra-why" rows={2} value={why} placeholder="e.g. Mere is at the day programme with Sam until 11:00 am." onChange={(e) => setWhy(e.target.value)} />
            </div>
            <p className="text-caption">Only people rostered on a covering shift can own it. {row.f.type === 'effect' || row.f.type === 'reoffer' ? 'Anyone rostered at the house can still close it.' : 'Lead follow-ups stay with house or clinical leads.'}</p>
        </Modal>
    );
}

/* ───────────── the house lead’s sign-off: not confirmed / partial / disputed ───────────── */
const SIGNOFF: Record<string, { title: string; ask: string[] }> = {
    unconfirmed: { title: 'Check a dose nobody else confirmed', ask: ['Talk to the person who recorded it: what happened, and who else was around.', 'Check the chart and, where it applies, what’s left in the pack.'] },
    partial: { title: 'Check a partial dose', ask: ['Talk to the person who recorded it: how much was taken, and what happened to the rest.', 'Decide whether the prescriber needs to know about the missed half.'] },
    disputed: { title: 'Sort out a disputed second person', ask: ['Talk to both people — the one who recorded the dose and the one who was named.', 'If the dose wasn’t given as recorded, report a medication error.'] },
};
export function SignOffDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const row = s.row(id);
    const [outcome, setOutcome] = useState<string | null>(null);
    const [note, setNote] = useState('');
    const [err, setErr] = useState<Record<string, string>>({});
    if (!row || !SIGNOFF[row.f.type] || !canAct(row.f, s.route.persona).close) return <NotFoundDialog onClose={onClose} />;
    const { f } = row;
    const cfg = SIGNOFF[f.type];
    return (
        <Modal
            width={720}
            title={cfg.title}
            description={`${f.pid ? `${PEOPLE[f.pid].pref} · ` : ''}${f.source}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={() => {
                            const e: Record<string, string> = {};
                            if (!outcome) e.outcome = 'Choose what you found.';
                            if (!note.trim()) e.note = 'Say what you checked.';
                            setErr(e);
                            if (Object.keys(e).length) return document.getElementById(e.outcome ? 'so-outcome' : 'so-note')?.focus();
                            finish(s, f, outcome === 'ok' ? `Checked — recorded correctly · ${note.trim()}` : `Not right — medication error reported · ${note.trim()}`);
                            if (outcome === 'error') s.toast('info', 'The Report a medication error dialog opens next (redesigned in P08b) — outside this preview.');
                            onClose();
                        }}
                    >
                        <Check className="size-4" /> Sign off
                    </Button>
                </>
            }
        >
            {row.state === 'overdue' ? <Notice tone="critical" title={`Overdue — due ${f.due}`}>{overdueBy(f.dueMin)} past the end of the next shift. It stays on everyone’s list until you sign it off.</Notice> : null}
            <section aria-label="What happened" className="rounded-xl border">
                <p className="border-b px-3 py-2 text-sm font-semibold">What happened</p>
                <ol className="divide-y">
                    {f.events.map((ev, i) => (
                        <li key={i} className="grid gap-1 px-3 py-2 text-sm sm:grid-cols-[150px_1fr]">
                            <span className="text-muted-foreground tabular-nums">{ev.at}</span>
                            <span>
                                {ev.what}
                                <span className="block text-caption">{ev.who}</span>
                            </span>
                        </li>
                    ))}
                </ol>
            </section>
            <div className="rounded-xl border bg-muted/30 p-3 text-sm">
                <p className="font-semibold">What to check</p>
                <ul className="mt-1 list-disc space-y-1 pl-5">
                    {cfg.ask.map((a) => (
                        <li key={a}>{a}</li>
                    ))}
                </ul>
            </div>
            <div className="space-y-2">
                <Label id="so-outcome-l">
                    What did you find? <span className="text-status-critical">*</span>
                </Label>
                <div id="so-outcome" tabIndex={-1}>
                    <TilePicker
                        labelledBy="so-outcome-l"
                        value={outcome}
                        invalid={!!err.outcome}
                        onChange={(k) => (setOutcome(k), setErr({}))}
                        tiles={[
                            { key: 'ok', label: 'Checked — recorded correctly', description: 'The chart shows what happened', icon: CheckCircle2 },
                            { key: 'error', label: 'Not right — report a medication error', description: 'The error report opens next', icon: Flag },
                        ]}
                    />
                </div>
                <InputError message={err.outcome} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="so-note">
                    What you checked <span className="text-status-critical">*</span>
                </Label>
                <Textarea id="so-note" rows={3} value={note} aria-invalid={!!err.note} placeholder="e.g. Spoke to Mere and Wiremu at 9:20 am. Mere gave it alone and named Wiremu by mistake — dose given as recorded." onChange={(e) => (setNote(e.target.value), setErr({}))} />
                <InputError message={err.note} />
            </div>
        </Modal>
    );
}

/* ───────────── countersign a prescriber’s phone instruction (P00 v5: by the end of the next day) ───────────── */
export function CountersignDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const row = s.row(id);
    const [choice, setChoice] = useState<string | null>(null);
    const [note, setNote] = useState('');
    const [err, setErr] = useState('');
    if (!row || row.f.type !== 'countersign' || !canAct(row.f, s.route.persona).close) return <NotFoundDialog onClose={onClose} />;
    const { f } = row;
    return (
        <Modal
            width={720}
            title="Countersign a phone instruction"
            description={`${PEOPLE[f.pid!].pref} · ${f.source}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={() => {
                            if (!choice) return setErr('Choose countersign or query.');
                            if (choice === 'query' && !note.trim()) return setErr('Say what you’re querying.');
                            finish(s, f, choice === 'sign' ? `Countersigned${note.trim() ? ` — ${note.trim()}` : ''}` : `Queried with the prescriber — ${note.trim()}`);
                            onClose();
                        }}
                    >
                        {choice === 'query' ? 'Save query' : 'Countersign'}
                    </Button>
                </>
            }
        >
            <KV
                rows={[
                    ['Prescriber', 'Dr Lena Chen, by phone at 8:55 am'],
                    ['What they said', 'Give 8 units of insulin glargine today instead of 10, for this dose only'],
                    ['Read back', 'Read back and confirmed by Priya Shah'],
                    ['Recorded', 'Priya Shah at 9:05 am · the 9:00 am dose was given as 8 units'],
                    ['Countersign by', 'The end of tomorrow (the organisation setting: by the end of the next day)'],
                ]}
            />
            <div className="space-y-2">
                <Label id="cs-l">
                    Your decision <span className="text-status-critical">*</span>
                </Label>
                <TilePicker
                    labelledBy="cs-l"
                    value={choice}
                    invalid={!!err && !choice}
                    onChange={(k) => (setChoice(k), setErr(''))}
                    tiles={[
                        { key: 'sign', label: 'Countersign', description: 'It matches what the prescriber asked for', icon: Check },
                        { key: 'query', label: 'Query it with the prescriber', description: 'Something doesn’t look right', icon: MessageSquareWarning },
                    ]}
                />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="cs-note">Note {choice === 'query' ? <span className="text-status-critical">*</span> : <span className="text-subtle">(optional)</span>}</Label>
                <Textarea id="cs-note" rows={2} value={note} onChange={(e) => (setNote(e.target.value), setErr(''))} placeholder="e.g. Confirmed with Dr Chen’s nurse at 10:20 am." />
                <InputError message={err} />
            </div>
            <p className="text-caption">A countersign isn’t a new order. If the change should last, the prescriber updates the order.</p>
        </Modal>
    );
}

/* ───────────── handover not acknowledged — heads-up for the house lead (Main, Q5) ───────────── */
export function HeadsUpDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const row = s.row(id);
    const [note, setNote] = useState('');
    if (!row || row.f.type !== 'handover' || !canAct(row.f, s.route.persona).close) return <NotFoundDialog onClose={onClose} />;
    const h = hoById('h-rimu-am')!;
    return (
        <Modal
            title="Handover not acknowledged"
            description={`${HOUSES[h.house]} · ${h.label} at ${h.change} · submitted ${h.submitted} by ${h.from}`}
            onClose={onClose}
            footer={
                <>
                    <Button
                        variant="outline"
                        onClick={() => {
                            s.toast('success', `${h.to} has been reminded in-app to read the handover.`);
                            onClose();
                        }}
                    >
                        <Send className="size-4" /> Remind {h.to.split(' ')[0]}
                    </Button>
                    <Button
                        onClick={() => {
                            finish(s, row.f, `Handled${note.trim() ? ` — ${note.trim()}` : ''}`);
                            onClose();
                        }}
                    >
                        Mark as handled
                    </Button>
                </>
            }
        >
            <p className="text-sm">
                {h.to} hasn’t acknowledged the handover {overdueBy(7 * 60)} into the shift. Acknowledging never blocks recording — this is a heads-up, so nothing from the night is missed.
            </p>
            <div className="space-y-1.5">
                <Label htmlFor="hu-note">
                    Note <span className="text-subtle">(optional)</span>
                </Label>
                <Textarea id="hu-note" rows={2} value={note} placeholder="e.g. Rang Ana — they’ve read it; the app was offline." onChange={(e) => setNote(e.target.value)} />
            </div>
        </Modal>
    );
}

/* ───────────── “Were you there?” — P01 v2 FallbackConfirmDialog, reused ───────────── */
export function WereYouThereDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const row = s.row(id);
    const [confirmNo, setConfirmNo] = useState(false);
    if (!row || row.f.type !== 'confirm') return <NotFoundDialog onClose={onClose} />;
    const mine = PERSONAS[s.route.persona].name === row.f.owner;
    return (
        <>
            <Modal
                title="Were you there?"
                description="Priya Shah named you to confirm this dose because you’d forgotten your PIN."
                onClose={onClose}
                footer={
                    mine ? (
                        <>
                            <Button variant="outline" onClick={() => setConfirmNo(true)}>
                                <X className="size-4" /> I wasn’t there
                            </Button>
                            <Button
                                onClick={() => {
                                    s.update(row.f.id, { answer: 'yes' });
                                    finish(s, row.f, 'I was there');
                                    s.toast('success', 'Thanks — the dose now shows you confirmed you were there. Please reset your witness PIN in your account settings.');
                                    onClose();
                                }}
                            >
                                <Check className="size-4" /> I was there
                            </Button>
                        </>
                    ) : (
                        <Button onClick={onClose} autoFocus>
                            Close
                        </Button>
                    )
                }
            >
                <KV
                    rows={[
                        ['Person', 'Aroha (Aroha Mere Ngata)'],
                        ['Medicine', 'Insulin glargine 100 units/mL pen'],
                        ['Recorded', 'Given 9:05 am · Priya S. · 8 units (phone instruction)'],
                        ['Answer by', '9:35 am (30 minutes)'],
                    ]}
                />
                <p className="text-caption">If you answer “I wasn’t there”, or don’t answer by 9:35 am, the house lead gets a follow-up. Your answer is recorded with the dose.</p>
                {!mine ? <Notice tone="neutral" title={`Only ${row.f.owner} can answer`}>It asks whether they were there.</Notice> : null}
            </Modal>
            <ConfirmDialog
                open={confirmNo}
                onClose={() => setConfirmNo(false)}
                onConfirm={() => {
                    s.update(row.f.id, { answer: 'no' });
                    finish(s, row.f, 'I wasn’t there');
                    s.toast('warning', 'Recorded: you weren’t there. Jordan Tipene (house lead) has a follow-up to check this dose.');
                    onClose();
                }}
                title="Say you weren’t there?"
                description="The dose stays on Aroha’s chart, marked “second person disputed”, and the house lead gets a follow-up to find out what happened."
                confirmText="I wasn’t there"
            />
        </>
    );
}

/* ───────────── an as-needed dose, with its effect check (PrnDetailDialog redesign) ───────────── */
export function AsNeededDoseDialog({ id, onClose, onAction }: { id: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const row = s.row(id);
    if (!row || !row.f.prn) return <NotFoundDialog onClose={onClose} />;
    const { f } = row;
    const prn = f.prn!;
    const done = row.rt?.done ?? f.done;
    const act = canAct(f, s.route.persona);
    const line = (k: ReactNode, v: ReactNode) => [k, v] as [ReactNode, ReactNode];
    return (
        <Modal
            width={720}
            title={`${prn.med.split(' ')[0]} · ${PEOPLE[f.pid!].pref}`}
            description={`As-needed dose · ${prn.given} · ${HOUSES[f.house]} · times in NZDT`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={() => s.toast('info', `${PEOPLE[f.pid!].pref}’s medication record is P02’s approved design — outside this preview.`)}>
                        Open {PEOPLE[f.pid!].pref}’s medication record
                    </Button>
                    {!done && act.close ? <Button onClick={() => onAction(`effect:${f.id}`)}>Check effect</Button> : <Button onClick={onClose}>Close</Button>}
                </>
            }
        >
            <KV
                rows={[
                    line('Given', `${prn.given} by ${prn.by} · ${prn.amount} of ${prn.med} · for ${prn.reason.toLowerCase()}`),
                    line('Effect check', done ? <span key="d">{done.outcome} · {done.at} by {done.by}{done.late ? <StatusBadge variant="warning" size="sm" className="ml-2">{done.late}</StatusBadge> : null}</span> : <span key="o" className={cn(row.state === 'overdue' && 'font-semibold text-status-critical')}>{row.state === 'overdue' ? `Overdue — was due ${f.due}` : `Due ${f.due}`} · owner {row.owner ?? 'set when the handover is acknowledged'}</span>),
                    line('Check time', 'Chosen when the dose was recorded: 1 hour after, unless another time was picked'),
                ]}
            />
            <DesignNote>Replaces today’s read-only “PRN administration detail” (PrnDetailDialog on /emar/prn). The effect check is the same follow-up that shows in Meds today, Safety &amp; oversight and All Tasks.</DesignNote>
        </Modal>
    );
}
