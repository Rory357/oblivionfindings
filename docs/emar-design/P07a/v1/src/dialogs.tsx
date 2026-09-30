/* Simple dialogs for P07a, laid out exactly like the Fleet Settings `Modal`
 * (pages/fleet-assets/settings/_ui.tsx) — the P01 v1 `Modal`, copied unchanged:
 * header band with title + description, scrolling body, muted footer band.
 * The witness-override request and the manager's one-screen approval are
 * P01 v1's approved dialogs (dialogs.tsx OverrideRequestDialog /
 * OverrideApproveDialog), reused with the same fields and wording; only the
 * synthetic dose and times differ. Confirmations use the real ConfirmDialog. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { EntityTable } from '@/components/lists/entity-table';
import { Button } from '@/components/ui/button';
import { Command, CommandGroup, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { cn } from '@/lib/utils';
import { ArrowDownLeft, ArrowUpRight, CalendarDays, Check, ClipboardCheck, Home, Info, Pill, Send, X } from 'lucide-react';
import { useState, type ComponentProps, type ReactNode } from 'react';
import { CD_MEDS, HOUSES, MORNING_OVERRIDE, PEOPLE, PERSONAS, PRN_CD, cdById, doseById, qty, type Entry, type HouseKey } from './data';
import { DISCREPANCY, PIN, candidates, countBlock, morningOverride, rosterFor, short, type Scenario } from './model';
import { useStore } from './store';
import { DesignNote, KV, Notice, NotConfigured, PersonMark, Rich, TilePicker } from './ui';

/* ───────────── the Fleet Settings Modal anatomy (P01 v1, unchanged) ───────────── */
export function Modal({
    title,
    description,
    children,
    footer,
    onClose,
    width = 480,
    onCloseAutoFocus,
}: {
    title: string;
    description: ReactNode;
    children: ReactNode;
    footer?: ReactNode;
    onClose: () => void;
    width?: 480 | 720 | 900;
    onCloseAutoFocus?: ComponentProps<typeof DialogContent>['onCloseAutoFocus'];
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent
                className="flex max-h-[88vh] flex-col overflow-hidden p-0"
                style={{ width: `min(92vw, ${width}px)`, maxWidth: `min(92vw, ${width}px)` }}
                onCloseAutoFocus={onCloseAutoFocus}
            >
                <div className="shrink-0 border-b p-5 pr-12">
                    <DialogTitle>{title}</DialogTitle>
                    <DialogDescription className="mt-2">{description}</DialogDescription>
                </div>
                <div className="min-h-0 space-y-4 overflow-y-auto p-5">{children}</div>
                <DialogFooter className="shrink-0 border-t bg-muted/30 p-4">
                    {footer ?? (
                        <Button variant="outline" onClick={onClose}>
                            Close
                        </Button>
                    )}
                </DialogFooter>
            </DialogContent>
        </Dialog>
    );
}

/* ───────────── roster evidence (P01 v1 RosterEvidence, this house and time) ───────────── */
export function RosterEvidence({ house, scenario, who = 'you', youId }: { house: HouseKey; scenario: Scenario; who?: 'you' | 'requester'; youId: string }) {
    const rows = rosterFor(house, scenario);
    const cands = candidates(youId === 'priya' ? 'sw' : youId === 'jordan' ? 'lead' : youId === 'mere' ? 'mere' : 'sw', house, scenario);
    const next = rows.find((s) => !s.onShiftNow && s.witnessAccess && s.cdArea && s.competency === 'current' && s.pin === 'set' && s.id !== youId);
    const anyOk = cands.some((c) => c.ok);
    return (
        <section aria-label="Roster evidence" className="rounded-lg border bg-card text-sm">
            <header className="flex items-center gap-2 border-b px-3 py-2 font-semibold">
                <CalendarDays className="size-4 text-muted-foreground" aria-hidden="true" />
                Roster and clock-ins · {HOUSES[house]} · checked 2:48 pm NZDT
            </header>
            <ul className="divide-y">
                {rows.map((s) => {
                    const c = cands.find((x) => x.staff.id === s.id);
                    const self = s.id === youId;
                    const reasons = !s.onShiftNow ? [s.now.startsWith('not') ? 'not clocked in yet' : 'not on shift now'] : c && !c.ok ? c.reasons : [];
                    return (
                        <li key={s.id} className="grid gap-1 px-3 py-2 sm:grid-cols-[1.1fr_1.5fr_1.7fr]">
                            <span className="font-medium">
                                {s.name}
                                {self ? <span className="text-subtle"> ({who === 'you' ? 'you' : 'asking'})</span> : null}
                            </span>
                            <span className="text-subtle">
                                {s.shift} · {s.now}
                            </span>
                            <span className={cn('text-sm', self ? 'text-muted-foreground' : reasons.length ? 'text-status-critical' : 'text-status-success')}>
                                {self ? 'The witness must be someone else' : reasons.length ? `✕ ${reasons.join(' · ')}` : '✓ Can witness'}
                            </span>
                        </li>
                    );
                })}
            </ul>
            <footer className="flex items-start gap-2 border-t px-3 py-2 text-subtle">
                <Info className="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                {anyOk
                    ? `${cands.filter((x) => x.ok).map((x) => x.staff.name).join(' and ')} can witness now.`
                    : `Nobody else on shift can witness right now.${next ? ` Next witness-eligible staff member: ${next.name} from ${next.shift.split('–')[0]}${next.now.startsWith('not clocked') ? ' (not clocked in yet)' : ''}.` : ''}`}
            </footer>
        </section>
    );
}

/* ───────────── ask a colleague to witness (Stephan, 30 Sep: yes, in-app) ───────────── */
export function AskWitnessDialog({ target, onClose }: { target: string; onClose: () => void }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const house: HouseKey = 'kowhai';
    const cands = candidates(s.route.persona, house, s.route.scenario).filter((c) => !c.self);
    const ok = cands.filter((c) => c.ok);
    const what = target === 'count' ? 'the 3:00 pm shift-change count' : target === PRN_CD.id ? `${PEOPLE[PRN_CD.pid].pref}’s as-needed ${PRN_CD.med.toLowerCase()}` : `${PEOPLE[doseById(target).pid].pref}’s ${doseById(target).slot} ${doseById(target).med.toLowerCase()}`;
    const [to, setTo] = useState<string | null>(ok[0]?.staff.id ?? null);
    const [note, setNote] = useState('At the medicine cupboard now.');
    const send = () => {
        const who = cands.find((c) => c.staff.id === to)!.staff;
        s.addAsk({ id: `ask-${Date.now()}`, from: me.name, to: who.name, what, kind: target === 'count' ? 'count' : 'dose', house, at: '2:48 pm', note, status: 'sent' });
        s.toast('success', `Request sent to ${who.name} at 2:48 pm. You’ll see their answer here and in the bell.`);
        onClose();
    };
    return (
        <Modal
            title="Ask someone to witness"
            description={`${what.charAt(0).toUpperCase()}${what.slice(1)} · ${HOUSES[house]}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={send} disabled={!to}>
                        <Send className="size-4" /> Send request
                    </Button>
                </>
            }
        >
            {ok.length === 0 ? (
                <Notice tone="warning" title="There’s nobody you can ask right now">
                    Nobody else on shift at {HOUSES[house]} can witness a controlled medicine. {target === 'count' ? 'Count with an eligible witness later — the count stays due and is overdue after 4:00 pm.' : 'You can ask a manager for a witness override from the dose.'}
                </Notice>
            ) : null}
            <div className="space-y-1.5">
                <Label id="ask-to-l">
                    Ask <span className="text-status-critical">*</span>
                </Label>
                <Command className="rounded-lg border" aria-labelledby="ask-to-l">
                    <CommandList className="max-h-[260px]">
                        <CommandGroup heading={`On shift at ${HOUSES[house]} now`}>
                            {cands.map((c) => (
                                <CommandItem key={c.staff.id} value={c.staff.name} disabled={!c.ok} onSelect={() => setTo(c.staff.id)} className="items-start" data-ask={c.staff.id}>
                                    <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/15 text-[10px] font-semibold text-primary">{c.staff.initials}</span>
                                    <span className="min-w-0">
                                        <span className="block text-sm font-medium">{c.staff.name}</span>
                                        <span className={cn('block text-xs', c.ok ? 'text-muted-foreground' : 'text-status-critical')}>{c.ok ? `${c.staff.role} · ${c.staff.now} · can witness` : `${c.why} — can’t be asked`}</span>
                                    </span>
                                    {to === c.staff.id ? <Check className="ml-auto size-4" /> : null}
                                </CommandItem>
                            ))}
                        </CommandGroup>
                    </CommandList>
                </Command>
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="ask-note">
                    Message <span className="text-subtle">(optional)</span>
                </Label>
                <Textarea id="ask-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
            </div>
            <p className="text-caption">Only colleagues who can witness are asked. They get it in the bell and in Controlled checks. Nothing is recorded until they type their PIN on your screen at the cupboard.</p>
        </Modal>
    );
}

/* ───────────── the colleague answers ───────────── */
export function AnswerAskDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const a = s.asks.find((x) => x.id === id);
    const [cant, setCant] = useState(false);
    const [reason, setReason] = useState('');
    const [err, setErr] = useState('');
    if (!a) return <NotFoundDialog onClose={onClose} />;
    return (
        <Modal
            title={`${a.from} asks you to witness`}
            description={`${a.what.charAt(0).toUpperCase()}${a.what.slice(1)} · ${HOUSES[a.house]} · sent ${a.at}`}
            onClose={onClose}
            footer={
                cant ? (
                    <>
                        <Button variant="outline" onClick={() => setCant(false)}>
                            Back
                        </Button>
                        <Button
                            onClick={() => {
                                if (!reason.trim()) return setErr('Say why, so they can ask someone else.');
                                s.answerAsk(a.id, 'cantcome', reason.trim());
                                s.toast('info', `${a.from} has been told you can’t come now.`);
                                onClose();
                            }}
                        >
                            Send answer
                        </Button>
                    </>
                ) : (
                    <>
                        <Button variant="outline" onClick={() => setCant(true)}>
                            <X className="size-4" /> Can’t come now
                        </Button>
                        <Button
                            autoFocus
                            onClick={() => {
                                s.answerAsk(a.id, 'coming');
                                s.toast('success', `${a.from} has been told you’re on the way.`);
                                onClose();
                            }}
                        >
                            <Check className="size-4" /> On my way
                        </Button>
                    </>
                )
            }
        >
            <KV
                rows={[
                    ['What', a.what.charAt(0).toUpperCase() + a.what.slice(1)],
                    ['Where', `${HOUSES[a.house]} · the controlled-medicine cupboard`],
                    ['Message', a.note || '—'],
                ]}
            />
            <p className="text-caption">You’ll type your witness PIN on {a.from.split(' ')[0]}’s screen at the cupboard. Nothing is recorded until then.</p>
            {cant ? (
                <div className="space-y-1.5">
                    <Label htmlFor="ask-cant">
                        Why not? <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea id="ask-cant" rows={2} autoFocus value={reason} aria-invalid={!!err} placeholder="e.g. Supporting Tama in the bathroom — free in 10 minutes." onChange={(e) => (setReason(e.target.value), setErr(''))} />
                    <InputError message={err} />
                </div>
            ) : null}
        </Modal>
    );
}

/* ───────────── discrepancy — what was started (resolution is P07b) ───────────── */
export function DiscrepancyDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const d = s.discrepancies.find((x) => x.id === id);
    if (!d || !PERSONAS[s.route.persona].perms.includes('cd.view')) return <NotFoundDialog onClose={onClose} />;
    const m = cdById(d.cdMed);
    const diff = d.recount - d.register;
    return (
        <Modal
            width={720}
            title={`Discrepancy — ${m.med.toLowerCase()}, ${PEOPLE[m.pid].pref}`}
            description={
                <span>
                    Started {d.startedAt} by {d.by}, witnessed by {d.witness} · open · <span className="text-muted-foreground">{d.ref}</span>
                </span>
            }
            onClose={onClose}
            footer={
                <>
                    {s.route.persona === 'lead' || s.route.persona === 'pm' ? (
                        <Button variant="outline" onClick={() => s.toast('info', 'Investigating and resolving a discrepancy is designed in P07b (controlled register) — outside this preview.')}>
                            Open in the controlled register
                        </Button>
                    ) : null}
                    <Button onClick={onClose} autoFocus>
                        Close
                    </Button>
                </>
            }
        >
            <Notice tone="critical" title={`${qty(Math.abs(diff), m)} ${diff < 0 ? 'short' : 'over'}`}>
                The register said {qty(d.register, m)}. It was counted twice: {d.firstCount}, then {d.recount}. The register was then set to what was counted ({qty(d.recount, m)}); the difference stays on this discrepancy until the house lead resolves it. The register now says {qty(s.balanceOf(m), m)}.
            </Notice>
            <KV
                rows={[
                    ['What they found', d.found],
                    ['What they did straight away', d.did],
                    ['Owner', `${d.owner} (house lead)`],
                    ['Who was told', <span key="t">{d.owner} (house lead), in-app at {d.startedAt}. Hana Kereama (clinical lead) wasn’t told — no controlled-medicine access.</span>],
                    ['Incident', 'Created and linked, as today (a critical controlled-drug incident). The incident doesn’t name the medicine to people without controlled-medicine access.'],
                    ['Controlled doses', 'Not blocked. Doses are still recorded as they happen; the dose dialog shows that this medicine has an open discrepancy.'],
                ]}
            />
            <DesignNote>Starting is P07a; investigating, resolving and linking to a loss report is P07b. The existing “Resolve discrepancy” dialog stays in the controlled register until P07b replaces it. Today the incident title names the medicine (“Controlled drug discrepancy: {'{medicine}'}”) — a concealment gap listed in AUDIT.md for P07b / D9.</DesignNote>
        </Modal>
    );
}

/* ───────────── counts and movements for one medicine (read-only) ───────────── */
export function HistoryDialog({ cdId, onClose }: { cdId: string; onClose: () => void }) {
    const s = useStore();
    const m = CD_MEDS.find((x) => x.id === cdId);
    if (!m || !PERSONAS[s.route.persona].perms.includes('cd.view') || !PERSONAS[s.route.persona].houses.includes(m.house)) return <NotFoundDialog onClose={onClose} />;
    const rows = s.entries().filter((e) => e.cdMed === cdId);
    return (
        <Modal width={900} title={`${m.med} ${m.strength} · ${PEOPLE[m.pid].pref}`} description={`Counts and movements, last 24 hours · ${HOUSES[m.house]} · register now ${qty(s.balanceOf(m), m)} · times in NZDT`} onClose={onClose}>
            <EntityTable<Entry>
                rows={rows}
                rowKey={(e) => e.id}
                minWidth={760}
                identityLabel="When"
                identityWidth="130px"
                identity={(e) => ({ icon: e.kind === 'count' ? ClipboardCheck : e.kind === 'dose' ? Pill : e.kind === 'out' ? ArrowUpRight : ArrowDownLeft, name: e.at, subline: e.day })}
                rowHeight="content"
                columns={[
                    { key: 'kind', label: 'What', width: '0.7fr', cell: (e) => <span className="text-[12.5px]">{KIND_LABEL[e.kind]}</span> },
                    { key: 'ch', label: 'Change', width: '0.6fr', cell: (e) => <span className="text-[12.5px] font-semibold tabular-nums">{e.change === 0 ? '—' : e.change > 0 ? `+${e.change}` : e.change}</span> },
                    { key: 'after', label: 'Balance after', width: '0.8fr', cell: (e) => <span className="text-[12.5px] tabular-nums">{qty(e.after, m)}</span> },
                    { key: 'by', label: 'By and witness', width: '1.3fr', cell: (e) => <span className="text-[12.5px]">{short(e.by)}{e.witness ? ` · witnessed by ${short(e.witness)}` : ''}</span> },
                    { key: 'd', label: 'Detail', width: '1.8fr', cell: (e) => <span className="text-[12px] text-muted-foreground">{e.detail}</span> },
                ]}
                actionsFor={() => []}
            />
            <p className="text-caption">Read-only. The full register, exports and corrections are in Stock &amp; controlled drugs › Controlled register (P07b).</p>
        </Modal>
    );
}
export const KIND_LABEL: Record<Entry['kind'], string> = { count: 'Count', dose: 'Dose', out: 'Went out', in: 'Came back' };

/* ───────────── my witness eligibility (for someone who can't witness yet) ───────────── */
export function WitnessEligibilityDialog({ onClose }: { onClose: () => void }) {
    const s = useStore();
    const p = PERSONAS[s.route.persona];
    const mere = s.route.persona === 'mere';
    const line = (ok: boolean, text: ReactNode) => (
        <span className={cn('flex items-start gap-1.5', ok ? 'text-status-success' : 'font-semibold text-status-critical')}>
            {ok ? <Check className="mt-0.5 size-3.5 shrink-0" /> : <X className="mt-0.5 size-3.5 shrink-0" />}
            <span className={ok ? 'text-foreground' : ''}>{text}</span>
        </span>
    );
    return (
        <Modal
            width={720}
            title="Can I witness controlled medicines?"
            description={`${p.name} · ${p.role} · checked 2:48 pm NZDT`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={() => s.toast('info', 'Account settings › Witness PIN is built in PIN-1 — outside this preview.')}>
                        Set my witness PIN
                    </Button>
                    <Button onClick={onClose} autoFocus>
                        Close
                    </Button>
                </>
            }
        >
            <KV
                rows={[
                    ['A different person', line(true, 'You can witness someone else’s count or dose, never your own.')],
                    ['On shift at the house', line(true, `Clocked in ${mere ? '6:58 am' : '7:02 am'} · Kōwhai House · 7:00 am–3:00 pm`)],
                    ['Controlled drugs area', mere ? line(false, 'Not passed at your assessment on 2 February 2026 (Hana Kereama).') : line(true, 'Passed, with “can witness controlled drugs”.')],
                    ['Not restricted', line(true, 'No restriction on your competency.')],
                    ['Witness PIN', mere ? line(false, 'Not set. Set one in your account: Settings › Witness PIN.') : line(true, 'Set · last changed 9 September 2026')],
                ]}
            />
            {mere ? (
                <Notice tone="info" title="What you can still do">
                    You can count controlled medicines with an eligible witness, and record movements. To witness, pass the controlled drugs area at your next assessment — ask your assessor, Hana Kereama — and set a witness PIN.
                </Notice>
            ) : null}
            <DesignNote>Built from the competency policy and PIN status, not from permissions (EM-03), with the P11 v5 rules: witnessing needs the controlled drugs area passed; restricted workers can’t witness; “exempt” doesn’t count.</DesignNote>
        </Modal>
    );
}

/* ───────────── why can't I count? ───────────── */
export function CantCountDialog({ onClose }: { onClose: () => void }) {
    const s = useStore();
    const b = countBlock(s.route.persona, s.route.scenario, s.clockedIn);
    const noWitness = !candidates(s.route.persona, 'kowhai', s.route.scenario).some((c) => c.ok);
    return (
        <Modal
            width={720}
            title="Why can’t I count now?"
            description="Kōwhai House · 3:00 pm shift-change count"
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {b && s.route.scenario === 'notClockedIn' ? (
                        <Button
                            onClick={() => {
                                s.clockIn();
                                s.toast('success', 'Clocked in at 2:48 pm · Kōwhai House. You can count now.');
                                onClose();
                            }}
                        >
                            Clock in
                        </Button>
                    ) : null}
                </>
            }
        >
            {b ? (
                <Notice tone="warning" title={b.title}>
                    {b.text}
                    <ul className="mt-2 list-disc space-y-1 pl-5">
                        {b.next.map((n) => (
                            <li key={n}>
                                <Rich text={n} />
                            </li>
                        ))}
                    </ul>
                </Notice>
            ) : noWitness ? (
                <>
                    <Notice tone="warning" title="Nobody on shift can witness this count">
                        A count is never recorded without a witness. Count when an eligible witness is here — the count stays due, and it’s overdue after 4:00 pm. If it becomes overdue, Jordan Tipene (house lead) is told.
                        <ul className="mt-2 list-disc space-y-1 pl-5">
                            <li>Jordan Tipene’s shift starts at 3:00 pm (not clocked in yet). Count together when they arrive.</li>
                            <li>
                                Coordinator on call: <NotConfigured />
                            </li>
                            <li>Don’t ask someone who isn’t eligible to witness.</li>
                        </ul>
                    </Notice>
                    <RosterEvidence house="kowhai" scenario={s.route.scenario} youId={PERSONAS[s.route.persona].staffId} />
                </>
            ) : (
                <p className="text-sm">Nothing stops you counting now.</p>
            )}
        </Modal>
    );
}

/* ───────────── the active witness override (details) ───────────── */
export function OverrideDetailDialog({ onClose }: { onClose: () => void }) {
    const s = useStore();
    const o = morningOverride(s.route.scenario);
    return (
        <Modal
            width={720}
            title="Witness override at Kōwhai House"
            description={`Approved by ${MORNING_OVERRIDE.by} at ${MORNING_OVERRIDE.approvedAt} · requested by ${MORNING_OVERRIDE.requestedBy} at ${MORNING_OVERRIDE.requestedAt}`}
            onClose={onClose}
        >
            <KV
                rows={[
                    ['Covers', o.covers],
                    ['From – to', `${MORNING_OVERRIDE.from} – ${o.until} today${o.active ? ' · ends by itself' : ' · ended'}`],
                    ['Reason', `${MORNING_OVERRIDE.reason} · “${MORNING_OVERRIDE.note}”`],
                    ['Doses recorded under it', MORNING_OVERRIDE.doses.map((id) => { const d = doseById(id); return `${PEOPLE[d.pid].pref}’s ${d.med.toLowerCase()} ${d.slot} (given ${d.at} by ${d.by})`; }).join(' · ')],
                    ['Follow-up', 'Jordan Tipene (house lead) counts these medicines with a witness and signs off each dose by the end of the next shift (11:00 pm).'],
                ]}
            />
            <p className="text-caption">Each dose is marked “No witness — override by Rangi Parata”. People who can grant overrides can end it sooner in Safety &amp; oversight › Witness overrides (P08a / P08b).</p>
        </Modal>
    );
}

/* ───────────── witness override: the worker's request (P01 v1, reused) ───────────── */
export function OverrideRequestDialog({ doseId, onClose }: { doseId: string; onClose: () => void }) {
    const s = useStore();
    const d = doseById(doseId);
    const p = PEOPLE[d.pid];
    const [cover, setCover] = useState<'dose' | 'shift'>('dose');
    const [why, setWhy] = useState('Nobody else on shift can witness (from the roster)');
    const [note, setNote] = useState('Mere is on shift but hasn’t passed the controlled drugs area. Jordan is running late.');
    return (
        <Modal
            width={720}
            title="Ask a manager for a witness override"
            description={`${p.pref} · ${d.med} ${d.strength} · ${d.slot} dose · Kōwhai House`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={() => {
                            s.setOverride('waiting');
                            s.toast('info', 'Request sent at 2:48 pm to people who can grant witness overrides. You’ll see their answer on the dose.');
                            onClose();
                        }}
                    >
                        <Send className="size-4" /> Send request
                    </Button>
                </>
            }
        >
            <RosterEvidence house="kowhai" scenario={s.route.scenario} youId="priya" />
            <div className="space-y-2">
                <Label id="orq-l" className="text-sm font-medium">
                    What should it cover? <span className="text-status-critical">*</span>
                </Label>
                <TilePicker
                    labelledBy="orq-l"
                    value={cover}
                    onChange={(k) => setCover(k as 'dose' | 'shift')}
                    tiles={[
                        { key: 'dose', label: `${p.pref}’s ${d.med.toLowerCase()}`, description: 'Until 3:00 pm, the end of your shift', icon: Pill },
                        { key: 'shift', label: 'All controlled doses at Kōwhai House', description: 'Until 3:00 pm, the end of your shift', icon: Home },
                    ]}
                />
            </div>
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="orq-r">
                        Why <span className="text-status-critical">*</span>
                    </Label>
                    <Select value={why} onValueChange={setWhy}>
                        <SelectTrigger id="orq-r" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {['Nobody else on shift can witness (from the roster)', 'Witness called away', 'Other'].map((x) => (
                                <SelectItem key={x} value={x}>
                                    {x}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="orq-n">
                        Anything the manager should know <span className="text-subtle">(optional)</span>
                    </Label>
                    <Textarea id="orq-n" rows={2} value={note} onChange={(e) => setNote(e.target.value)} />
                </div>
            </div>
            <Notice tone="info" title="Who gets it">
                People with the “Grant controlled-drug witness overrides” permission (provider managers in this mockup). They see this roster and approve or decline on one screen, from their own login.
            </Notice>
            <p className="text-caption">Until a manager answers, don’t give it without a witness. You can record it as not given, or wait.</p>
        </Modal>
    );
}

/* ───────────── witness override: the manager's one-screen approval (P01 v1, reused) ───────────── */
export function OverrideApproveDialog({ onClose, startDeclining = false }: { onClose: () => void; startDeclining?: boolean }) {
    const s = useStore();
    const canGrant = PERSONAS[s.route.persona].perms.includes('witness_override');
    const [start, setStart] = useState('2026-09-28T14:49');
    const [end, setEnd] = useState('2026-09-28T15:00');
    const [reason, setReason] = useState('Nobody else on shift can witness (from the roster)');
    const [note, setNote] = useState('Mere is on shift but hasn’t passed the controlled drugs area. Jordan is running late.');
    const [declining, setDeclining] = useState(startDeclining);
    const [declineReason, setDeclineReason] = useState('');
    const [err, setErr] = useState<Record<string, string>>({});
    const approve = () => {
        const e: Record<string, string> = {};
        if (!(end > start) || end <= '2026-09-28T14:48') e.end = 'The end must be after the start and later than now (2:48 pm).';
        else if (end > '2026-09-28T15:00') e.end = 'That’s longer than your organisation allows (one rostered shift). End it by 3:00 pm today (end of the day shift); grant another override for the next shift if it’s still needed.';
        if (!reason) e.reason = 'Choose a reason.';
        setErr(e);
        if (Object.keys(e).length) return;
        s.setOverride('approved');
        s.toast('success', 'Override approved. Priya Shah can record Aroha’s methylphenidate without a witness until 3:00 pm, and gets a message now.');
        onClose();
    };
    const decline = () => {
        if (!declineReason.trim()) return setErr({ decline: 'Say why you’re declining.' });
        s.setOverride('declined', declineReason.trim());
        s.toast('info', 'Request declined. Priya Shah sees your reason on the dose.');
        onClose();
    };
    return (
        <Modal
            width={720}
            title="Witness override request"
            description="From Priya Shah at 2:48 pm · Aroha · methylphenidate 2:30 pm dose · Kōwhai House"
            onClose={onClose}
            footer={
                !canGrant ? (
                    <Button onClick={onClose} autoFocus>
                        Close
                    </Button>
                ) : declining ? (
                    <>
                        <Button variant="outline" onClick={() => setDeclining(false)}>
                            Back
                        </Button>
                        <Button variant="destructive" onClick={decline}>
                            Decline request
                        </Button>
                    </>
                ) : (
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button variant="outline" onClick={() => setDeclining(true)}>
                            Decline
                        </Button>
                        <Button onClick={approve}>
                            <Check className="size-4" /> Approve override
                        </Button>
                    </>
                )
            }
        >
            <RosterEvidence house="kowhai" scenario="noWitness" who="requester" youId="priya" />
            <div className="grid gap-3 sm:grid-cols-3">
                <ReadOnlyField label="House" value="Kōwhai House" />
                <ReadOnlyField label="Only for" value="Aroha Mere Ngata" />
                <ReadOnlyField label="Only this medicine" value="Methylphenidate" />
            </div>
            <DateTimeField id="g-s" label="Starts" value={start} onChange={setStart} hint="Prefilled from the request." />
            <DateTimeField id="g-e" label="Ends" value={end} onChange={setEnd} error={err.end} hint="It ends by itself at this time. Longest allowed: one rostered shift — this one ends 3:00 pm today." />
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="g-reason">
                        Reason <span className="text-status-critical">*</span>
                    </Label>
                    <Select value={reason || undefined} onValueChange={setReason} disabled={!canGrant}>
                        <SelectTrigger id="g-reason" className="w-full" aria-invalid={!!err.reason}>
                            <SelectValue placeholder="Choose a reason" />
                        </SelectTrigger>
                        <SelectContent>
                            {['Nobody else on shift can witness (from the roster)', 'Single staffing on the roster', 'Witness called away', 'Staff shortage — no cover found', 'Other'].map((x) => (
                                <SelectItem key={x} value={x}>
                                    {x}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={err.reason} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="g-note">
                        Note <span className="text-subtle">(optional)</span>
                    </Label>
                    <Textarea id="g-note" rows={2} value={note} onChange={(e) => setNote(e.target.value)} disabled={!canGrant} />
                </div>
            </div>
            <Notice tone="info" title="What this does">
                Controlled doses — Aroha’s methylphenidate — at Kōwhai House can be recorded without a witness from <strong>2:49 pm today</strong> to <strong>3:00 pm today</strong>. Each dose is marked “No witness — override by Rangi Parata”, and the house lead gets a follow-up next shift. It ends by itself; it can be revoked sooner (Safety &amp; oversight › Witness overrides).
            </Notice>
            {declining ? (
                <div className="space-y-1.5">
                    <Label htmlFor="g-decline">
                        Tell Priya why, and what to do instead <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea id="g-decline" rows={2} autoFocus value={declineReason} aria-invalid={!!err.decline} placeholder="e.g. Jordan will be there at 3:20 pm — wait for them to witness." onChange={(e) => setDeclineReason(e.target.value)} />
                    <InputError message={err.decline} />
                </div>
            ) : null}
            {!canGrant ? <p className="text-caption">Only people with the “Grant controlled-drug witness overrides” permission can approve or decline. You can see the request and the roster.</p> : null}
        </Modal>
    );
}
function ReadOnlyField({ label, value }: { label: string; value: string }) {
    return (
        <div className="space-y-1.5">
            <p className="text-sm font-medium">{label}</p>
            <p className="rounded-md border bg-muted/40 px-3 py-2 text-sm">{value}</p>
        </div>
    );
}

/* ───────────── a record you can't see and one that doesn't exist look the same ───────────── */
export function NotFoundDialog({ onClose }: { onClose: () => void }) {
    return (
        <Modal title="We can’t show this record" description="It may not exist, or it may not be available to you. Check the link, or go back." onClose={onClose}>
            <DesignNote>A controlled record opened without controlled-medicine access, a record at a house outside your access, and a record that doesn’t exist all show this (404 — no existence leak, P00 v5).</DesignNote>
        </Modal>
    );
}

/* ───────────── cancel my witness request ───────────── */
export function CancelAskConfirm({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const a = s.asks.find((x) => x.id === id);
    return (
        <ConfirmDialog
            open
            onClose={onClose}
            onConfirm={() => {
                s.answerAsk(id, 'cancelled');
                s.toast('info', `Request to ${a?.to ?? 'your colleague'} cancelled. They’ve been told.`);
                onClose();
            }}
            title="Cancel this request?"
            description={`${a?.to ?? 'Your colleague'} is told you no longer need them to witness ${a?.what ?? 'this'}. Nothing else changes.`}
            confirmText="Cancel request"
            cancelText="Keep it"
            variant="default"
        />
    );
}

