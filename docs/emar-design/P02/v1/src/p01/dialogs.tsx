/* Simple dialogs for P01, laid out exactly like the Fleet Settings `Modal`
 * (pages/fleet-assets/settings/_ui.tsx): header band with title + description,
 * scrolling body, muted footer band. Width follows the POPUP_STYLE_GUIDE
 * tokens via inline style. Confirmations use the real ConfirmDialog. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogTitle } from '@/components/ui/dialog';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Check, Clock3, Home, LogIn, MessageSquare, Pill, Send, ShieldCheck, UserCheck, X } from 'lucide-react';
import { useState, type ComponentProps, type ReactNode } from 'react';
import { NOW_LABEL } from './clock';
import { BLOCKS, type BlockKey, PIN_RULES, STILL, competencyOf, fill } from './contract';
import { PEOPLE, PERSONAS, PRN, allDoseById, can, prnById } from './data';
import { hrefFor, useStore } from './store';
import { BlockedPanel, DesignNote, IdentityHeader, KV, Notice, NotConfigured, RosterEvidence, TilePicker } from './ui';

/* ───────────── the Fleet Settings Modal anatomy, width-token aware ───────────── */
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

/* ───────────── Why can't I record this? (NF-07) ───────────── */
export function WhyDialog({
    block,
    doseId,
    onClose,
    onRecordNotGiven,
    onAction,
}: {
    block: BlockKey;
    doseId: string;
    onClose: () => void;
    onRecordNotGiven: () => void;
    onAction: (a: 'clock-in' | 'eligibility' | 'ovr-request' | 'message-lead') => void;
}) {
    const s = useStore();
    const d = allDoseById(doseId);
    const p = PEOPLE[d.pid];
    const b = BLOCKS[block];
    const allowNotGiven = b.still !== 'none' && STILL[b.still] !== undefined;
    const waiting = block === 'noWitness' && s.override === 'waiting';
    return (
        <Modal
            width={720}
            title="Why can’t I record this?"
            description={`${p.pref} · ${d.med} ${d.strength} · ${d.slot}`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {allowNotGiven ? (
                        <Button variant={b.action ? 'outline' : 'default'} onClick={onRecordNotGiven}>
                            Record not given
                        </Button>
                    ) : null}
                    {waiting ? (
                        <Button disabled>
                            <Send className="size-4" /> Request sent 9:13 am — waiting
                        </Button>
                    ) : b.action ? (
                        <Button onClick={() => onAction(b.action!.act)}>
                            {b.action.act === 'clock-in' ? <LogIn className="size-4" /> : b.action.act === 'eligibility' ? <UserCheck className="size-4" /> : b.action.act === 'ovr-request' ? <Send className="size-4" /> : <MessageSquare className="size-4" />}
                            {b.action.label}
                        </Button>
                    ) : null}
                </>
            }
        >
            <IdentityHeader pid={d.pid} support={d.support} compact />
            <BlockedPanel block={block} pid={d.pid} med={d.med} scenario={s.ctx.scenario} />
            {block === 'siteNotApproved' ? (
                <DesignNote>Shown only when the person is already visible to you (they moved house during your shift). A direct link to someone outside your access shows “We can’t show this record”.</DesignNote>
            ) : null}
        </Modal>
    );
}

/* ───────────── My medication eligibility (EM-03 truthful labels) ───────────── */
export function EligibilityDialog({ onClose }: { onClose: () => void }) {
    const s = useStore();
    const p = PERSONAS[s.ctx.persona];
    const comp = competencyOf(s.ctx.scenario);
    return (
        <Modal
            width={720}
            title="My medication eligibility"
            description={`${p.name} · ${p.role} · checked ${NOW_LABEL} NZDT`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={() => s.toast('info', 'Account settings › Witness PIN is built in PIN-1 — outside this preview.')}>
                        Manage my witness PIN
                    </Button>
                    <Button onClick={onClose} autoFocus>
                        Close
                    </Button>
                </>
            }
        >
            <KV
                rows={[
                    ['Medication competency', comp === 'expired' ? <StatusBadge key="c" variant="critical">Expired 14 September 2026</StatusBadge> : comp === 'restricted' ? <StatusBadge key="c" variant="critical">Restricted</StatusBadge> : <StatusBadge key="c" variant="success"><Check className="size-3" /> Current until 14 March 2027</StatusBadge>],
                    ['Assessed', '14 March 2026 by Hana Kereama · declaration and acknowledgement recorded'],
                    ['Areas passed', 'General administration · As-needed · Controlled medicines'],
                    ['Areas not passed', 'Insulin · Covert administration'],
                    ['Restrictions', comp === 'restricted' ? '“Supervised practice until reassessed” — organisation rule: Block (you can’t sign doses as given)' : 'None'],
                    ['Controlled-medicine witness', comp === 'current' ? 'Eligible' : 'Not eligible while your competency isn’t current'],
                    ['Witness PIN', <span key="p"><StatusBadge variant="success">Set</StatusBadge> last changed 9 September 2026</span>],
                    ['Shift', s.ctx.scenario === 'notClockedIn' && !s.clockedIn ? 'Not clocked in' : 'Clocked in 7:02 am · Kōwhai House · 7:00 am–3:00 pm'],
                    ['House access', 'Kōwhai House'],
                ]}
            />
            <DesignNote>Built from the competency policy, not from permissions (EM-03). The full register is Safety &amp; oversight › Staff eligibility (P11). Areas “not passed” matter only where the organisation rule applies them (controlled drugs and covert: Block when failed).</DesignNote>
        </Modal>
    );
}

/* ───────────── offline item refused when sent (EM-26) ───────────── */
export function RejectedReviewDialog({ onClose }: { onClose: () => void }) {
    const s = useStore();
    return (
        <Modal
            width={720}
            title="A saved medication action was not recorded"
            description="Mele · Paracetamol 500 mg tablet · saved offline at 8:50 am, sent at 9:02 am"
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    <Button
                        variant="outline"
                        onClick={() => {
                            s.dismissOfflineRefused();
                            onClose();
                        }}
                    >
                        It wasn’t given — dismiss
                    </Button>
                    <Button
                        onClick={() => {
                            s.dismissOfflineRefused();
                            s.toast('info', 'Jordan Tipene (house lead) has been told. It shows in their follow-ups until it’s resolved.');
                            onClose();
                        }}
                    >
                        <Send className="size-4" /> Tell the house lead
                    </Button>
                </>
            }
        >
            <IdentityHeader pid="mele" support="administer" compact />
            <Notice tone="critical" title="Not recorded — this dose was not saved.">
                It was saved on this device at 8:50 am while offline. When it was sent at 9:02 am, the server refused it: the as-needed limit on the prescription is reached (4 doses in the last 24 hours, the most recent at 8:05 am). The chart doesn’t show this dose, and it won’t be sent again.
            </Notice>
            <p className="text-sm">
                <strong>If the dose was already given</strong>, tell the house lead now so it can be recorded correctly and followed up. <strong>If it wasn’t given</strong>, you can dismiss this.
            </p>
        </Modal>
    );
}

/* ───────────── more than ordered — recorded and reported ───────────── */
export function ErrorCreatedDialog({ doseId, amount, onClose }: { doseId: string; amount: string; onClose: () => void }) {
    const s = useStore();
    const d = allDoseById(doseId);
    const p = PEOPLE[d.pid];
    return (
        <Modal
            width={720}
            title="Recorded and reported"
            description={`${d.med} for ${p.pref}: ${amount} given — more than ordered. The chart shows what was really given.`}
            onClose={onClose}
            footer={
                <>
                    <Button variant="outline" onClick={() => s.toast('info', 'The medication error record is redesigned in P08b — outside this preview.')}>
                        Open the error
                    </Button>
                    <Button variant="outline" onClick={() => s.toast('info', 'Opens the incident in Incidents — outside this preview.')}>
                        Open the incident
                    </Button>
                    <Button onClick={onClose} autoFocus>
                        Done
                    </Button>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <Notice tone="critical" title="Medication error ME-2026-031">
                    Created and linked to this dose · the house lead reviews the severity
                </Notice>
                <Notice tone="warning" title="Incident INC-2026-118">
                    Created and linked to the error — one incident for this dose
                </Notice>
            </div>
            <Notice tone="critical" title="Do this now">
                <ol className="list-decimal pl-5">
                    <li>
                        Contact the prescriber or on-call contact: <NotConfigured />
                    </li>
                    <li>Stay with {p.pref} and watch closely. Add what you see to the incident.</li>
                    <li>The house lead and everyone rostered at Kōwhai House have been told; they see it until it’s resolved.</li>
                </ol>
            </Notice>
            <p className="text-caption">Saving again or trying again reuses this error and incident — never a second one.{d.cd ? ' The incident doesn’t name the medicine to people without controlled-medicine access.' : ''}</p>
            <DesignNote>Reuses the existing link: the error is saved with create_incident, which sets the linked incident — the same shape as “Create &amp; link incident”, which is idempotent. References are synthetic. The error record is designed in P08b.</DesignNote>
        </Modal>
    );
}

/* ───────────── witness override: the worker's request ───────────── */
export function OverrideRequestDialog({ doseId, onClose }: { doseId: string; onClose: () => void }) {
    const s = useStore();
    const d = allDoseById(doseId);
    const p = PEOPLE[d.pid];
    const [cover, setCover] = useState<'dose' | 'shift'>('dose');
    const [why, setWhy] = useState('Nobody else on shift can witness (from the roster)');
    const [note, setNote] = useState('Mere is on shift but isn’t witness-trained.');
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
                            s.toast('info', 'Request sent at 9:13 am to people who can grant witness overrides. You’ll see their answer on the dose.');
                            onClose();
                        }}
                    >
                        <Send className="size-4" /> Send request
                    </Button>
                </>
            }
        >
            <IdentityHeader pid={d.pid} support={d.support} compact />
            <RosterEvidence scenario={s.ctx.scenario} />
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

/* ───────────── witness override: the manager's one-screen approval ───────────── */
export function OverrideApproveDialog({ onClose, startDeclining = false }: { onClose: () => void; startDeclining?: boolean }) {
    const s = useStore();
    const canGrant = can(s.ctx.persona, 'cd.override');
    const [start, setStart] = useState('2026-09-28T09:14');
    const [end, setEnd] = useState('2026-09-28T15:00');
    const [reason, setReason] = useState('Nobody else on shift can witness (from the roster)');
    const [note, setNote] = useState('Mere is on shift but isn’t witness-trained.');
    const [declining, setDeclining] = useState(startDeclining);
    const [declineReason, setDeclineReason] = useState('');
    const [err, setErr] = useState<Record<string, string>>({});
    const approve = () => {
        const e: Record<string, string> = {};
        if (!(end > start) || end <= '2026-09-28T09:12') e.end = 'The end must be after the start and later than now (9:12 am).';
        else if (end > '2026-09-28T15:00') e.end = 'That’s longer than your organisation allows (one rostered shift). End it by 3:00 pm today (end of the day shift); grant another override for the next shift if it’s still needed.';
        if (!reason) e.reason = 'Choose a reason.';
        setErr(e);
        if (Object.keys(e).length) return;
        s.setOverride('approved');
        s.toast('success', 'Override approved. Priya Shah can record Grace’s clonazepam without a witness until 3:00 pm, and gets a message now.');
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
            description="From Priya Shah at 9:13 am · Grace · clonazepam 9:00 am dose · Kōwhai House"
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
            <RosterEvidence scenario="cdNoWitness" who="requester" />
            <div className="grid gap-3 sm:grid-cols-3">
                <ReadOnlyField label="House" value="Kōwhai House" />
                <ReadOnlyField label="Only for" value="Grace Liu" />
                <ReadOnlyField label="Only this medicine" value="Clonazepam" />
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
                Controlled doses — Grace’s clonazepam — at Kōwhai House can be recorded without a witness from <strong>9:14 am today</strong> to <strong>3:00 pm today</strong>. Each dose is marked “No witness — override by Rangi Parata”, and the house lead gets a follow-up next shift. It ends by itself; it can be revoked sooner (Safety &amp; oversight › Witness overrides).
            </Notice>
            {declining ? (
                <div className="space-y-1.5">
                    <Label htmlFor="g-decline">
                        Tell Priya why, and what to do instead <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea id="g-decline" rows={2} autoFocus value={declineReason} aria-invalid={!!err.decline} placeholder="e.g. Jordan can come in at 10:00 am to witness." onChange={(e) => setDeclineReason(e.target.value)} />
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

/* ───────────── named colleague confirms (forgotten-PIN fallback, PIN-2) ───────────── */
export function FallbackConfirmDialog({ doseId, onClose }: { doseId: string; onClose: () => void }) {
    const s = useStore();
    const d = allDoseById(doseId);
    const r = s.records[doseId];
    const [confirmNo, setConfirmNo] = useState(false);
    const kindLabel = r?.second?.kind === 'amount' ? 'confirm the amount given' : r?.second?.kind === 'witness' ? 'witness this dose' : 'confirm this dose';
    return (
        <>
            <Modal
                title="Were you there?"
                description={`${r?.by ?? 'Priya Shah'} named you to ${kindLabel} because you’d forgotten your PIN.`}
                onClose={onClose}
                footer={
                    <>
                        <Button variant="outline" onClick={() => setConfirmNo(true)}>
                            <X className="size-4" /> I wasn’t there
                        </Button>
                        <Button
                            onClick={() => {
                                s.answerFallback(doseId, 'yes');
                                s.toast('success', 'Thanks — the dose now shows you confirmed you were there. Please reset your witness PIN in your account settings.');
                                onClose();
                            }}
                        >
                            <Check className="size-4" /> I was there
                        </Button>
                    </>
                }
            >
                <KV
                    rows={[
                        ['Person', `${PEOPLE[d.pid].pref} (${PEOPLE[d.pid].legal})`],
                        ['Medicine', `${d.med} ${d.strength}`],
                        ['Recorded', `${r?.line ?? ''}`],
                        ['Answer by', `9:42 am (${PIN_RULES.confirmWithinMin} minutes)`],
                    ]}
                />
                <p className="text-caption">If you answer “I wasn’t there”, or don’t answer by 9:42 am, the house lead gets a follow-up. Your answer is recorded with the dose.</p>
            </Modal>
            <ConfirmDialog
                open={confirmNo}
                onClose={() => setConfirmNo(false)}
                onConfirm={() => {
                    s.answerFallback(doseId, 'no');
                    s.toast('warning', 'Recorded: you weren’t there. Jordan Tipene (house lead) has a follow-up to check this dose.');
                    onClose();
                }}
                title="Say you weren’t there?"
                description={`The dose stays on ${PEOPLE[d.pid].pref}’s chart, marked “second person disputed”, and the house lead gets a follow-up to find out what happened.`}
                confirmText="I wasn’t there"
            />
        </>
    );
}

/* ───────────── recorded dose detail ───────────── */
export function DoseDetailDialog({ doseId, onClose }: { doseId: string; onClose: () => void }) {
    const s = useStore();
    const d = allDoseById(doseId);
    const r = s.recordOf(d);
    const st = s.stateOf(d);
    return (
        <Modal
            width={720}
            title={`${d.med} · ${PEOPLE[d.pid].pref}`}
            description={`${d.strength} · ${d.slot} dose · Monday 28 September 2026 · times in NZDT`}
            onClose={onClose}
            footer={
                <>
                    {r && can(s.ctx.persona, 'correct') ? (
                        <Button variant="outline" onClick={() => s.toast('info', 'Corrections keep the original and show the change (existing correction flow; lineage is designed in P02).')}>
                            Correct this record
                        </Button>
                    ) : null}
                    <Button onClick={onClose} autoFocus>
                        Close
                    </Button>
                </>
            }
        >
            <IdentityHeader pid={d.pid} support={d.support} compact />
            <KV
                rows={[
                    ['Outcome', r ? r.line : st === 'selfmanaged' ? `${PEOPLE[d.pid].pref} manages this medicine · nothing to record` : st === 'notdue' ? `Not yet due — window opens ${d.slot === '12:00 pm' ? '11:30 am' : ''}` : '—'],
                    ['Amount', r?.amount ?? '—'],
                    ['Recorded', r ? `${r.at} by ${r.by}` : st === 'queued' ? 'Saved on this device — not sent yet' : st === 'rejected' ? 'Not saved' : '—'],
                    ...(r?.second ? ([[r.second.kind === 'witness' ? 'Witnessed by' : 'Confirmed by', <span key="s">{r.second.name} {r.second.forgot ? <StatusBadge variant="warning" size="sm">{r.second.answer === 'yes' ? 'Confirmed later — “I was there”' : r.second.answer === 'no' ? 'Disputed — “I wasn’t there”' : 'Not verified — PIN forgotten'}</StatusBadge> : <StatusBadge variant="success" size="sm">Witness PIN</StatusBadge>}</span>]] as [ReactNode, ReactNode][]) : []),
                    ...(r?.warn?.length ? ([['Also recorded', <ul key="w" className="list-disc pl-4">{r.warn.map((w) => <li key={w}>{w}</li>)}</ul>]] as [ReactNode, ReactNode][]) : []),
                    ['Order version', d.order.verified ? `Verified ${d.order.on} by ${d.order.by}` : 'Waiting to be checked'],
                ]}
            />
        </Modal>
    );
}

/* ───────────── choose an as-needed medicine (searchable) ───────────── */
export function PrnPickerDialog({ onClose, onPick }: { onClose: () => void; onPick: (orderId: string) => void }) {
    return (
        <Modal width={720} title="Record an as-needed dose" description="Choose the person and medicine. Only people on your shift and their current as-needed orders are listed." onClose={onClose}>
            <Command className="rounded-lg border">
                <CommandInput placeholder="Search people or medicines…" autoFocus />
                <CommandList className="max-h-[320px]">
                    <CommandEmpty>No as-needed medicine matches. Check the spelling, or open the person’s medication record.</CommandEmpty>
                    {['aroha', 'tama', 'mele', 'grace'].map((pid) => (
                        <CommandGroup key={pid} heading={`${PEOPLE[pid].pref} ${PEOPLE[pid].surname}`}>
                            {PRN.filter((o) => o.pid === pid).map((o) => {
                                const full = o.last24h.length >= o.maxPer24h;
                                return (
                                    <CommandItem key={o.id} value={`${PEOPLE[pid].pref} ${o.med}`} onSelect={() => onPick(o.id)} className="items-start">
                                        <Pill className="mt-0.5 size-4" />
                                        <span className="min-w-0 flex-1">
                                            <span className="block text-sm font-medium">
                                                {o.med} {o.strength}
                                                {o.cd ? ' · controlled' : ''}
                                            </span>
                                            <span className="block text-xs text-muted-foreground">
                                                {o.last24h.length} of {o.maxPer24h} in the last 24 hours{o.last24h[0] ? ` · last ${o.last24h[0].at}` : ''}
                                            </span>
                                        </span>
                                        {full ? <StatusBadge variant="critical" size="sm">Limit reached</StatusBadge> : <StatusBadge variant="neutral" size="sm">Available</StatusBadge>}
                                    </CommandItem>
                                );
                            })}
                        </CommandGroup>
                    ))}
                </CommandList>
            </Command>
            <p className="text-caption">A medicine at its limit still opens, so you can see why it can’t be recorded and what to do.</p>
        </Modal>
    );
}

/* ───────────── choose a dose (client profile Record dose) ───────────── */
export function DosePickerDialog({ pid, onClose, onPick }: { pid: string; onClose: () => void; onPick: (doseId: string) => void }) {
    const s = useStore();
    const doses = s.visibleDoses().filter((d) => d.pid === pid);
    return (
        <Modal width={720} title={`Record a dose for ${PEOPLE[pid].pref}`} description="Doses on today’s chart. Recording opens the same steps as Meds today." onClose={onClose}>
            <ul className="divide-y rounded-lg border">
                {doses.map((d) => {
                    const st = s.stateOf(d);
                    const open = st === 'due' || st === 'late';
                    return (
                        <li key={d.id} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                            <span className="min-w-0">
                                <span className="block text-sm font-medium">
                                    {d.slot} · {d.med} {d.strength}
                                </span>
                                <span className="block text-caption">{d.instructions}</span>
                            </span>
                            {open ? (
                                <Button size="sm" onClick={() => onPick(d.id)}>
                                    Record
                                </Button>
                            ) : (
                                <StatusBadge variant={st === 'notdue' ? 'neutral' : 'success'} size="sm">
                                    {st === 'notdue' ? 'Not yet due' : 'Has an outcome'}
                                </StatusBadge>
                            )}
                        </li>
                    );
                })}
            </ul>
            <p className="text-caption">
                <Clock3 className="mr-1 inline size-3" /> Times in NZDT. As-needed doses: use “Record as-needed dose” on Meds today.
            </p>
        </Modal>
    );
}

