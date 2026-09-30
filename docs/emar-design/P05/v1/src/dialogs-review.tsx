/* P05’s review dialogs — the review viewer (today’s ReviewDetailDialog), Book
 * a review (ScheduleReviewDialog and the duplicate MedicationReviewModal, now
 * one) and Record the outcome (ConductReviewDialog). Real WizardShell (sequential,
 * and the viewer with sequential={false}), WizardStepPane, ReviewCard/ReviewRow,
 * WizardSuccessPane, ConfirmDialog, TilePicker, Select, Input, Textarea,
 * Checkbox, FileDropzone + StagedFileCard, the PKG-01 DateTimeField and the
 * approved DatePicker. Decisions: Main, Q1–Q9 and the additions. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, StagedFileCard } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import {
    AlertTriangle,
    Building2,
    CalendarClock,
    CalendarPlus,
    Check,
    ChevronLeft,
    ChevronRight,
    FileSignature,
    FileText,
    History,
    Home,
    Loader2,
    Phone,
    Pill,
    Stethoscope,
    UserRound,
    Users,
    Video,
    XCircle,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LOCAL } from './clock';
import {
    CLINICIANS,
    DECISION_HOW,
    HOUSES,
    MOVE_LABEL,
    OUTCOME_LABEL,
    OWNERS,
    PEOPLE,
    PEOPLE_ORDER,
    PERSONAS,
    TRIGGER_LABEL,
    WHERE_LABEL,
    ordersOf,
    type ClinRole,
    type Clinician,
    type Item,
    type Kind,
    type Outcome,
    type PersonId,
    type Review,
    type Trigger,
    type Where,
} from './data';
import { cantSaveOffline, focusFirst, Req, restore, stamp } from './helpers';
import { addMonths, allReviews, canManage, canSeeSummary, changeState, daysUntil, hiddenFor, intervalOf, kindText, labelIso, lowerFirst, medText, reviewState, TODAY_ISO } from './model';
import { useStore } from './store';
import { ConcealedIdentity, KV, Notice, TilePicker } from './ui';

export const WHERE_TILES = [
    { key: 'house', label: 'At the house', description: 'The clinician came to the house', icon: Home },
    { key: 'practice', label: 'At the practice', description: 'The person went to the clinic', icon: Building2 },
    { key: 'phone', label: 'By phone', description: 'A phone consultation', icon: Phone },
    { key: 'video', label: 'By video', description: 'A video consultation', icon: Video },
];
export const localLabel = (v: string) => {
    const m = /^(\d{4}-\d{2}-\d{2})T(\d{2}):(\d{2})$/.exec(v);
    if (!m) return v;
    const h = Number(m[2]);
    return `${labelIso(m[1])}, ${h % 12 || 12}:${m[3]} ${h < 12 ? 'am' : 'pm'}`;
};
/** A file already stored privately (read-only) — the real StagedFileCard needs a staged File, so this is its read-only line. */
export function StoredFile({ name, note }: { name: string; note?: string }) {
    const s = useStore();
    return (
        <Button type="button" variant="outline" onClick={() => s.toast('info', `${name} opens from private storage — outside this preview.`)} className="h-auto w-full justify-start gap-2.5 px-3 py-2 text-left font-normal">
            <FileText className="size-4 shrink-0 text-muted-foreground" aria-hidden="true" />
            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium">{name}</span>
                <span className="block text-caption">{note ?? 'Stored privately · opens for people who can see this review'}</span>
            </span>
        </Button>
    );
}

/* ═════════════ The review (viewer) ═════════════ */
export function ReviewDialog({ review: r, onClose, onAction }: { review: Review; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const p = s.route.persona;
    const person = PEOPLE[r.pid];
    const [step, setStep] = useState(0);
    const rec = r.recorded;
    const steps = [
        { key: 'review', label: 'This review', blurb: `${kindText(r)} · due ${r.due}`, icon: Stethoscope },
        { key: 'meds', label: 'Medicines', blurb: rec ? `${rec.items.length} reviewed` : 'After the review', icon: Pill },
        { key: 'took', label: 'Who took part', blurb: rec ? 'The person and whānau' : 'After the review', icon: Users },
        { key: 'summary', label: 'Summary & letter', blurb: canSeeSummary(p) ? 'The clinician’s own words' : 'People who manage reviews', icon: FileText },
        { key: 'history', label: 'History', blurb: 'Every step, kept', icon: History },
    ];
    const cur = steps[step].key;
    const st = reviewState(r);
    const events: [string, string][] = [
        [r.made.at, r.made.auto ? r.made.auto : `Booked by ${r.made.by}`],
        ...r.moves.map((m) => [m.at, `Moved from ${m.from} to ${m.to} by ${m.by} — ${MOVE_LABEL[m.reason].toLowerCase()}${m.note ? `: ${m.note}` : ''}`] as [string, string]),
        ...(r.booked ? ([[r.booked.at, `Appointment with ${r.booked.with.name} (${r.booked.with.role}) · ${WHERE_LABEL[r.booked.where].toLowerCase()}`]] as [string, string][]) : []),
        ...(rec ? ([[rec.recordedAt, `Outcome recorded by ${rec.by}`]] as [string, string][]) : []),
        ...(rec?.items.flatMap((it) => {
            const out: [string, string][] = [];
            const name = medText(p, it.orderId);
            if (it.decision?.asked) out.push([it.decision.asked.at, `${name}: asked the prescriber — ${lowerFirst(it.decision.asked.how)}`]);
            if (it.decision?.state === 'agreed' || it.decision?.state === 'notAgreed') out.push([it.decision.at!, `${name}: ${it.decision.state === 'agreed' ? 'agreed' : 'not agreed'} by ${it.decision.prescriber} — ${DECISION_HOW[it.decision.how!].toLowerCase()}`]);
            if (it.entered) out.push([it.entered.at, `${name}: entered in Orders as ${it.entered.version} by ${it.entered.by}`]);
            return out;
        }) ?? []),
        ...(r.cancelled ? ([[r.cancelled.at, `Cancelled by ${r.cancelled.by} — ${r.cancelled.reason}`]] as [string, string][]) : []),
        ...(r.closed ? ([[r.closed.at, r.closed.reason]] as [string, string][]) : []),
    ];
    const body: Record<string, ReactNode> = {
        review: (
            <>
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge variant={st.variant} className="rounded-[8px] font-semibold">
                        {st.label}
                    </StatusBadge>
                    {r.state === 'booked' && daysUntil(r.dueIso) < 0 && person.away ? <span className="text-sm text-muted-foreground">Away — {person.away.charAt(0).toLowerCase() + person.away.slice(1)}</span> : null}
                </div>
                <KV
                    rows={[
                        ['Person', `${person.legal} · ${HOUSES[person.house]}`],
                        ['Kind', `${kindText(r)}${r.trigger ? ` — ${TRIGGER_LABEL[r.trigger]}` : ''}`],
                        ...(r.triggerNote ? ([['What happened', r.triggerNote]] as [string, string][]) : []),
                        ['Due', r.due],
                        ['Appointment', r.booked ? `${r.booked.with.name} (${r.booked.with.role}, ${r.booked.with.practice}) · ${r.booked.at} · ${WHERE_LABEL[r.booked.where].toLowerCase()}` : r.state === 'booked' ? 'Not booked with a clinician yet' : '—'],
                        ['Owner', r.owner],
                        ['How often', (() => {
                            const e = intervalOf(s.rt, r.pid);
                            return e.own ? `Every ${e.months} months — set for ${person.pref}` : `Every ${e.months} months — the organisation default`;
                        })()],
                    ]}
                />
                {r.kind === 'regular' && r.state === 'booked' ? <p className="text-caption">Regular reviews are moved, not cancelled — so the cycle never lapses.</p> : null}
                {r.moves.length ? <p className="text-caption">Earlier dates are kept: {r.moves.map((m) => m.from).join(', ')}.</p> : null}
            </>
        ),
        meds: rec ? (
            <ul className="divide-y rounded-lg border">
                {rec.items.map((it) => (
                    <li key={it.orderId} className="flex flex-wrap items-start justify-between gap-3 px-3 py-2.5">
                        {hiddenFor(p, it.orderId) ? (
                            <ConcealedIdentity compact />
                        ) : (
                            <span className="min-w-0">
                                <span className="block text-sm font-semibold">{medText(p, it.orderId)}</span>
                                <span className="block text-caption">{OUTCOME_LABEL[it.outcome]}{it.what ? ` — ${it.what}` : it.watch ? ` — ${it.watch.what}, until ${it.watch.until}` : ''}</span>
                            </span>
                        )}
                        <span className="flex flex-col items-end gap-1">
                            <StatusBadge variant={changeState(it).variant} className="rounded-[8px]">
                                {changeState(it).label}
                            </StatusBadge>
                            {it.outcome !== 'continue' ? (
                                <Button size="sm" variant="link" className="h-auto p-0" onClick={() => onAction(`change:${r.id}:${it.orderId}`)}>
                                    Open the change
                                </Button>
                            ) : null}
                        </span>
                    </li>
                ))}
            </ul>
        ) : (
            <p className="text-subtle">Each of {person.pref}’s current medicines gets an outcome when the review is recorded.</p>
        ),
        took: rec ? (
            <KV
                rows={[
                    [person.pref, rec.took.person === 'yes' ? 'Took part' : `Didn’t take part — ${rec.took.personWhy}`],
                    ['Whānau, welfare guardian or EPOA', rec.took.whanau === 'took' ? `Took part — ${rec.took.whanauWho}` : rec.took.whanau === 'told' ? `Told afterwards — ${rec.took.whanauWho}` : `Not involved — ${rec.took.whanauWhy}`],
                    ['How', WHERE_LABEL[rec.took.how]],
                    ['Done by', rec.clinicians.map((c) => `${c.name} (${c.role}, ${c.practice}${c.reg ? `, ${c.reg}` : ''})`).join('; ')],
                    ['Recorded by', `${rec.by}, ${rec.recordedAt}`],
                ]}
            />
        ) : (
            <p className="text-subtle">Recorded with the outcome.</p>
        ),
        summary: !rec ? (
            <p className="text-subtle">Recorded with the outcome.</p>
        ) : canSeeSummary(p) ? (
            <>
                <div className="space-y-1">
                    <p className="text-sm font-semibold">In the clinician’s words</p>
                    <p className="text-sm">{rec.summary}</p>
                </div>
                {rec.letter ? <StoredFile name={rec.letter} /> : <p className="text-caption">No written review attached.</p>}
                {rec.figures ? (
                    <KV rows={[...(rec.figures.dbi ? ([['Drug burden index (the clinician’s)', rec.figures.dbi]] as [string, string][]) : []), ...(rec.figures.falls ? ([['Falls (the clinician’s note)', rec.figures.falls]] as [string, string][]) : [])]} />
                ) : null}
            </>
        ) : (
            <Notice tone="neutral" title="Shown to people who manage reviews">
                The clinician’s own summary and letter are for house leads, clinical leads, coordinators and managers. Each medicine’s outcome, what to watch and the next date are in the other sections.
            </Notice>
        ),
        history: (
            <ol className="space-y-3" aria-label="What happened">
                {events.map(([at, what], n) => (
                    <li key={n} className="flex gap-3 text-sm">
                        <span className="grid size-6 shrink-0 place-items-center rounded-full border border-primary bg-primary/10 text-[11px] font-semibold text-primary" aria-hidden="true">
                            {n + 1}
                        </span>
                        <span>
                            <span className="block">{what}</span>
                            <span className="block text-caption">{at}</span>
                        </span>
                    </li>
                ))}
            </ol>
        ),
    };
    return (
        <WizardShell
            open
            onClose={onClose}
            title={`${r.id} — ${person.legal}`}
            description={`${kindText(r)} medication review, due ${r.due}.`}
            railIcon={Stethoscope}
            railTitle={`Medication review ${r.id}`}
            railSub={`${person.pref} · ${kindText(r).toLowerCase()} · due ${r.due}`}
            steps={steps}
            stepIndex={step}
            onStepClick={setStep}
            headerLabel={steps[step].label}
            sequential={false}
            pct={null}
            footerStart={
                <Button type="button" variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                r.state === 'booked' && canManage(p) ? (
                    <>
                        <Button type="button" variant="outline" onClick={() => onAction(`move:${r.id}`)}>
                            Move the review
                        </Button>
                        <Button type="button" onClick={() => onAction(r.booked ? `record:${r.id}` : `appt:${r.id}`)}>
                            {r.booked ? 'Record the outcome' : 'Book the appointment'}
                        </Button>
                    </>
                ) : undefined
            }
        >
            <WizardStepPane>
                <div className="space-y-4">{body[cur]}</div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ═════════════ Book a review (Q2, Q3) ═════════════ */
export function BookDialog({ pid: pid0, onClose, returnFocus }: { pid?: PersonId; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const people = PEOPLE_ORDER.filter((x) => me.houses.includes(PEOPLE[x].house) && !PEOPLE[x].left);
    const [step, setStep] = useState(0);
    const [pid, setPid] = useState<PersonId | ''>(pid0 ?? '');
    const [kind, setKind] = useState<Kind | ''>('');
    const [trigger, setTrigger] = useState<Trigger | ''>('');
    const [note, setNote] = useState('');
    const [due, setDue] = useState('');
    const [withWho, setWithWho] = useState('');
    const [when, setWhen] = useState('');
    const [where, setWhere] = useState<Where | ''>('');
    const [owner, setOwner] = useState(me.perms.includes('reviews.manage') ? me.name : '');
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [newId, setNewId] = useState('');
    const person = pid ? PEOPLE[pid] : null;
    const regularBooked = pid ? allReviews(s.rt).find((x) => x.pid === pid && x.state === 'booked' && x.kind === 'regular') : undefined;
    const owners = OWNERS.filter((o) => (person ? o.houses.includes(person.house) : true));
    const steps = [
        { key: 'who', label: 'Who and why', blurb: 'The person, regular or triggered', icon: UserRound },
        { key: 'when', label: 'When and with whom', blurb: 'Due date, appointment, owner', icon: CalendarClock },
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const dirty = !!(pid && !pid0) || !!(kind || note || due || withWho);
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'who') {
            if (!pid) x['bk-person'] = 'Choose the person.';
            if (!kind) x['bk-kind'] = 'Choose regular or triggered.';
            else if (kind === 'regular' && regularBooked) x['bk-kind'] = `${person!.pref}’s regular review is already booked for ${regularBooked.due} (${regularBooked.id}). Move that one, or book a triggered review.`;
            if (kind === 'triggered' && !trigger) x['bk-trigger'] = 'Choose what started it.';
            if (kind === 'triggered' && trigger === 'other' && !note.trim()) x['bk-note'] = 'Say what happened.';
        }
        if (k === 'when') {
            if (!due) x['bk-due-date'] = 'Choose when it’s due.';
            else if (due < TODAY_ISO) x['bk-due-date'] = 'The due date can’t be in the past.';
            if (withWho && !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(when)) x['bk-when'] = 'Choose the appointment’s date and time, or clear the clinician.';
            if (withWho && !where) x['bk-where'] = 'Choose where it happens.';
            if (!owner) x['bk-owner'] = 'Choose who owns it.';
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
        if (s.route.scenario === 'offline') return setE({ 'bk-save': cantSaveOffline });
        setPhase('sending');
        window.setTimeout(() => {
            const id = `R-${36 + s.rt.reviews.length}`;
            setNewId(id);
            const c = CLINICIANS.find((x) => x.name === withWho);
            const review: Review = {
                id,
                pid: pid as PersonId,
                kind: kind as Kind,
                trigger: kind === 'triggered' ? (trigger as Trigger) : undefined,
                triggerNote: note.trim() || undefined,
                due: labelIso(due),
                dueIso: due,
                booked: c ? { with: c, at: localLabel(when), iso: when.slice(0, 10), where: where as Where } : undefined,
                owner,
                made: { by: me.name, at: stamp() },
                moves: [],
                state: 'booked',
            };
            s.update((rt) => ({ ...rt, reviews: [review, ...rt.reviews] }));
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        who: (
            <>
                <div className="space-y-1.5">
                    <Label htmlFor="bk-person">
                        Person <Req />
                    </Label>
                    <Select value={pid || undefined} onValueChange={(v) => (setPid(v as PersonId), setE({}))} disabled={!!pid0}>
                        <SelectTrigger id="bk-person" className="w-full" aria-invalid={!!e['bk-person']}>
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
                    <InputError message={e['bk-person']} />
                    <p className="text-caption">Only the people at your houses are listed.</p>
                </div>
                <div className="space-y-2">
                    <Label id="bk-kind-l">
                        Regular or triggered <Req />
                    </Label>
                    <div id="bk-kind" tabIndex={-1}>
                        <TilePicker
                            labelledBy="bk-kind-l"
                            value={kind || null}
                            invalid={!!e['bk-kind'] && !kind}
                            onChange={(k) => (setKind(k as Kind), setE({}))}
                            tiles={[
                                { key: 'regular', label: 'Regular', description: person ? `Every ${intervalOf(s.rt, person.id).months} months for ${person.pref}` : 'From the person’s interval', icon: CalendarClock },
                                { key: 'triggered', label: 'Triggered', description: 'Something happened that needs a review', icon: AlertTriangle },
                            ]}
                        />
                    </div>
                    <InputError message={e['bk-kind']} />
                </div>
                {kind === 'triggered' ? (
                    <>
                        <div className="space-y-2">
                            <Label id="bk-trigger-l">
                                What started it <Req />
                            </Label>
                            <div id="bk-trigger" tabIndex={-1}>
                                <Select value={trigger || undefined} onValueChange={(v) => (setTrigger(v as Trigger), setE({}))}>
                                    <SelectTrigger className="w-full" aria-labelledby="bk-trigger-l" aria-invalid={!!e['bk-trigger']}>
                                        <SelectValue placeholder="Choose" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {(Object.keys(TRIGGER_LABEL) as Trigger[]).map((k) => (
                                            <SelectItem key={k} value={k}>
                                                {TRIGGER_LABEL[k]}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                            <InputError message={e['bk-trigger']} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="bk-note">What happened {trigger === 'other' ? <Req /> : <span className="font-normal text-muted-foreground">(optional)</span>}</Label>
                            <Textarea id="bk-note" rows={2} value={note} aria-invalid={!!e['bk-note']} placeholder="e.g. Fell in the bathroom on Saturday — no injury found." onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                            <InputError message={e['bk-note']} />
                        </div>
                    </>
                ) : null}
            </>
        ),
        when: (
            <>
                <div className="space-y-1.5">
                    <Label htmlFor="bk-due-date">
                        Due by <Req />
                    </Label>
                    <DatePicker id="bk-due-date" label="Due by" value={due} invalid={!!e['bk-due-date']} onChange={(v) => (setDue(v), setE({}))} />
                    <InputError message={e['bk-due-date']} />
                    <p className="text-caption">It becomes overdue the day after, in NZ time.</p>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="bk-with">
                        Clinician <span className="font-normal text-muted-foreground">(optional — add when the appointment is made)</span>
                    </Label>
                    <Select value={withWho || 'none'} onValueChange={(v) => (setWithWho(v === 'none' ? '' : v), setE({}))}>
                        <SelectTrigger id="bk-with" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="none">Not booked with a clinician yet</SelectItem>
                            {CLINICIANS.map((c) => (
                                <SelectItem key={c.name} value={c.name}>
                                    {c.name} — {c.role}, {c.practice}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                {withWho ? (
                    <>
                        <DateTimeField id="bk-when" label="Appointment" value={when} onChange={(v) => (setWhen(v), setE({}))} error={e['bk-when']} />
                        <div className="space-y-2">
                            <Label id="bk-where-l">
                                Where <Req />
                            </Label>
                            <div id="bk-where" tabIndex={-1}>
                                <TilePicker labelledBy="bk-where-l" value={where || null} invalid={!!e['bk-where'] && !where} onChange={(k) => (setWhere(k as Where), setE({}))} tiles={WHERE_TILES} />
                            </div>
                            <InputError message={e['bk-where']} />
                        </div>
                    </>
                ) : null}
                <div className="space-y-1.5">
                    <Label htmlFor="bk-owner">
                        Owner <Req />
                    </Label>
                    <Select value={owner || undefined} onValueChange={(v) => (setOwner(v), setE({}))}>
                        <SelectTrigger id="bk-owner" className="w-full" aria-invalid={!!e['bk-owner']}>
                            <SelectValue placeholder="Who books it and records the outcome" />
                        </SelectTrigger>
                        <SelectContent>
                            {owners.map((o) => (
                                <SelectItem key={o.name} value={o.name}>
                                    {o.name} — {o.role}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={e['bk-owner']} />
                </div>
            </>
        ),
        review: person ? (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={UserRound} title="Who and why" onEdit={() => setStep(0)}>
                    <ReviewRow label="Person" value={person.legal} />
                    <ReviewRow label="Kind" value={kind === 'triggered' ? `Triggered — ${TRIGGER_LABEL[trigger as Trigger]}` : 'Regular'} />
                    {note ? <ReviewRow label="What happened" value={note} /> : null}
                </ReviewCard>
                <ReviewCard icon={CalendarClock} title="When and with whom" onEdit={() => setStep(1)}>
                    <ReviewRow label="Due by" value={due ? labelIso(due) : '—'} />
                    <ReviewRow label="Appointment" value={withWho ? `${withWho} · ${localLabel(when)}${where ? ` · ${WHERE_LABEL[where].toLowerCase()}` : ''}` : 'Not booked with a clinician yet'} />
                    <ReviewRow label="Owner" value={owner} />
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you save">
                        {`The review shows in Medication reviews, on ${person.pref}’s record, in All Tasks for ${owner} and in Actions & Reviews on the client profile. ${kind === 'regular' ? 'Recording it books the next regular one.' : 'It doesn’t change the regular cycle.'}`}
                    </Notice>
                    <InputError message={e['bk-save']} />
                </div>
            </div>
        ) : null,
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={person ? `Book a review — ${person.legal}` : 'Book a medication review'}
                description="Who and why, when and with whom, then review."
                railIcon={CalendarPlus}
                railTitle="Book a review"
                railSub={person ? `${person.pref} · ${HOUSES[person.house]}` : 'A medication review'}
                steps={steps.map((x, n) => ({ ...x, disabled: n > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(n) => n <= step && setStep(n)}
                pct={Math.round(((step + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
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
                            {phase === 'sending' ? 'Saving…' : 'Book the review'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title="Review booked"
                            blurb={`${newId} is due ${labelIso(due)}. ${owner} owns it.`}
                            actions={
                                <Button type="button" autoFocus onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(88vh, 800px)"
            >
                <WizardStepPane>
                    <div className="space-y-4">{body[cur]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog open={discard} onClose={() => setDiscard(false)} onConfirm={() => (setDiscard(false), onClose())} title="Discard this booking?" description="Nothing has been saved." confirmText="Discard" cancelText="Keep going" />
        </>
    );
}

/* ═════════════ Record the outcome (Q4–Q7) ═════════════ */
type Row = { outcome: Outcome | ''; what: string; watch: string; until: string; agreedNow: boolean };
export function RecordDialog({ review: r, onClose, returnFocus }: { review: Review; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const person = PEOPLE[r.pid];
    const orders = ordersOf(r.pid);
    const [step, setStep] = useState(0);
    const [clin, setClin] = useState(r.booked?.with.name ?? '');
    const [other, setOther] = useState({ name: '', role: '' as ClinRole | '', practice: '', reg: '' });
    const [when, setWhen] = useState(r.booked && r.booked.iso <= TODAY_ISO ? `${r.booked.iso}T${r.booked.at.includes('8:30') ? '08:30' : '10:00'}` : NOW_LOCAL);
    const [where, setWhere] = useState<Where | ''>(r.booked?.where ?? '');
    const [tookP, setTookP] = useState<'yes' | 'no' | ''>('');
    const [pWhy, setPWhy] = useState('');
    const [tookW, setTookW] = useState<'took' | 'told' | 'none' | ''>('');
    const [wWho, setWWho] = useState('');
    const [wWhy, setWWhy] = useState('');
    const [rows, setRows] = useState<Record<string, Row>>(() => Object.fromEntries(orders.map((o) => [o.id, { outcome: '', what: '', watch: '', until: '', agreedNow: false }])));
    const [newMed, setNewMed] = useState<{ on: boolean; name: string; what: string }>({ on: false, name: '', what: '' });
    const [summary, setSummary] = useState('');
    const [file, setFile] = useState<File | null>(null);
    const [dbi, setDbi] = useState('');
    const [falls, setFalls] = useState('');
    const every = intervalOf(s.rt, r.pid);
    const nextIso = addMonths(TODAY_ISO, every.months);
    const [earlier, setEarlier] = useState(false);
    const [earlierIso, setEarlierIso] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const clinician: Clinician | null = clin === 'other' ? (other.name && other.role ? { name: other.name, role: other.role as ClinRole, practice: other.practice || 'Practice not given', reg: other.reg || undefined } : null) : CLINICIANS.find((c) => c.name === clin) ?? null;
    const prescriber = !!clinician && clinician.role !== 'Pharmacist';
    const regularNext = allReviews(s.rt).find((x) => x.pid === r.pid && x.kind === 'regular' && x.state === 'booked' && x.id !== r.id);
    const steps = [
        { key: 'who', label: 'Who did it', blurb: 'The clinician, when and how', icon: Stethoscope },
        { key: 'took', label: 'Who took part', blurb: `${person.pref} and whānau`, icon: Users },
        { key: 'meds', label: 'Each medicine', blurb: `${orders.length} current orders`, icon: Pill },
        { key: 'summary', label: 'Summary & letter', blurb: 'The clinician’s own words', icon: FileText },
        { key: 'next', label: 'Next review', blurb: r.kind === 'regular' ? 'Booked automatically' : 'The cycle carries on', icon: CalendarClock },
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const dirty = !!(tookP || tookW || summary || file || Object.values(rows).some((x) => x.outcome));
    const setRow = (id: string, patch: Partial<Row>) => (setRows((x) => ({ ...x, [id]: { ...x[id], ...patch } })), setE({}));
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'who') {
            if (!clin) x['rc-clin'] = 'Choose who did the review.';
            if (clin === 'other' && !other.name.trim()) x['rc-oname'] = 'Enter their name.';
            if (clin === 'other' && !other.role) x['rc-orole'] = 'Choose their role.';
            if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(when)) x['rc-when'] = 'Choose when it happened.';
            else if (when > NOW_LOCAL) x['rc-when'] = 'It can’t be in the future — record it after the review.';
            if (!where) x['rc-where'] = 'Choose how it happened.';
        }
        if (k === 'took') {
            if (!tookP) x['rc-tookp'] = `Say whether ${person.pref} took part.`;
            if (tookP === 'no' && !pWhy.trim()) x['rc-pwhy'] = 'Say why not.';
            if (!tookW) x['rc-tookw'] = 'Say whether whānau, a welfare guardian or an EPOA was involved.';
            if ((tookW === 'took' || tookW === 'told') && !wWho.trim()) x['rc-wwho'] = 'Say who.';
            if (tookW === 'none' && !wWhy.trim()) x['rc-wwhy'] = 'Say why not.';
        }
        if (k === 'meds') {
            orders.forEach((o) => {
                if (hiddenFor(p, o.id)) return;
                const row = rows[o.id];
                if (!row.outcome) x[`rc-out-${o.id}`] = 'Choose the outcome.';
                else if (['change', 'stop', 'swap'].includes(row.outcome) && !row.what.trim()) x[`rc-what-${o.id}`] = 'Say what the clinician recommends.';
                else if (row.outcome === 'watch' && !row.watch.trim()) x[`rc-watch-${o.id}`] = 'Say what to watch for.';
                else if (row.outcome === 'watch' && !row.until) x[`rc-until-${o.id}-date`] = 'Choose until when.';
            });
            if (newMed.on && !newMed.name.trim()) x['rc-newname'] = 'Enter the medicine.';
            if (newMed.on && !newMed.what.trim()) x['rc-newwhat'] = 'Say what the clinician recommends.';
        }
        if (k === 'summary' && !summary.trim()) x['rc-summary'] = 'Write what the clinician said — their letter can go with it.';
        if (k === 'next' && earlier && !earlierIso) x['rc-earlier-date'] = 'Choose the earlier date, or untick it.';
        if (k === 'next' && earlier && earlierIso && earlierIso <= TODAY_ISO) x['rc-earlier-date'] = 'Choose a date after today.';
        return x;
    }
    function next() {
        const x = validate(cur);
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        setStep(step + 1);
    }
    const hiddenRows = orders.filter((o) => hiddenFor(p, o.id));
    const changes = orders.filter((o) => !hiddenFor(p, o.id) && ['change', 'stop', 'swap'].includes(rows[o.id].outcome)).length + (newMed.on ? 1 : 0);
    const watches = orders.filter((o) => !hiddenFor(p, o.id) && rows[o.id].outcome === 'watch').length;
    const agreedNow = orders.filter((o) => !hiddenFor(p, o.id) && ['change', 'stop', 'swap'].includes(rows[o.id].outcome) && rows[o.id].agreedNow).length;
    const nextLabel = r.kind === 'regular' ? labelIso(earlier && earlierIso ? earlierIso : nextIso) : regularNext ? regularNext.due : labelIso(nextIso);
    function save() {
        if (s.route.scenario === 'offline') return setE({ 'rc-save': cantSaveOffline });
        setPhase('sending');
        window.setTimeout(() => {
            const nid = `R-${36 + s.rt.reviews.length}`;
            const items: Item[] = orders.map((o) => {
                if (hiddenFor(p, o.id)) return { orderId: o.id, outcome: 'continue' as Outcome, pendingCd: true };
                const row = rows[o.id];
                const it: Item = { orderId: o.id, outcome: row.outcome as Outcome };
                if (['change', 'stop', 'swap'].includes(row.outcome)) {
                    it.what = row.what.trim();
                    it.decision = row.agreedNow && prescriber ? { state: 'agreed', prescriber: clinician!.name, at: localLabel(when), how: 'review', by: me.name } : { state: 'waiting' };
                }
                if (row.outcome === 'watch') it.watch = { what: row.watch.trim(), until: labelIso(row.until), followUp: `FU-${90 + s.rt.reviews.length}` };
                return it;
            });
            const nextReview: Review | null =
                r.kind === 'regular' || earlier
                    ? { id: nid, pid: r.pid, kind: 'regular', due: nextLabel, dueIso: earlier && earlierIso ? earlierIso : nextIso, owner: r.owner, made: { by: 'System', at: stamp(), auto: `Booked automatically when ${r.id} was recorded` }, moves: [], state: 'booked' }
                    : null;
            s.update((rt) => ({
                ...rt,
                reviews: nextReview && (r.kind === 'regular' || !regularNext) ? [nextReview, ...rt.reviews] : rt.reviews,
                patch: {
                    ...rt.patch,
                    [r.id]: {
                        state: 'recorded',
                        recorded: {
                            at: localLabel(when),
                            by: me.name,
                            recordedAt: stamp(),
                            clinicians: [clinician!],
                            took: { person: tookP as 'yes' | 'no', personWhy: pWhy || undefined, whanau: tookW as 'took' | 'told' | 'none', whanauWho: wWho || undefined, whanauWhy: wWhy || undefined, how: where as Where },
                            summary: summary.trim(),
                            letter: file?.name,
                            figures: dbi || falls ? { dbi: dbi || undefined, falls: falls || undefined } : undefined,
                            items,
                            next: nextReview ? { id: nextReview.id, due: nextReview.due, earlier } : undefined,
                        },
                    },
                    ...(r.kind === 'triggered' && earlier && regularNext ? { [regularNext.id]: { due: nextLabel, dueIso: earlierIso, moves: [...regularNext.moves, { from: regularNext.due, to: nextLabel, reason: 'other' as const, note: `The clinician asked for an earlier review at ${r.id}`, by: me.name, at: stamp() }] } } : {}),
                },
            }));
            setPhase('done');
        }, 700);
    }
    const outcomeTiles = (id: string, row: Row) => (
        <Select value={row.outcome || undefined} onValueChange={(v) => setRow(id, { outcome: v as Outcome })}>
            <SelectTrigger id={`rc-out-${id}`} className="w-full" aria-label="Outcome" aria-invalid={!!e[`rc-out-${id}`]}>
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
    );
    const body: Record<string, ReactNode> = {
        who: (
            <>
                <div className="space-y-1.5">
                    <Label htmlFor="rc-clin">
                        Who did the review <Req />
                    </Label>
                    <Select value={clin || undefined} onValueChange={(v) => (setClin(v), setE({}))}>
                        <SelectTrigger id="rc-clin" className="w-full" aria-invalid={!!e['rc-clin']}>
                            <SelectValue placeholder="Choose the clinician" />
                        </SelectTrigger>
                        <SelectContent>
                            {CLINICIANS.map((c) => (
                                <SelectItem key={c.name} value={c.name}>
                                    {c.name} — {c.role}, {c.practice}
                                </SelectItem>
                            ))}
                            <SelectItem value="other">Someone else — enter their details</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError message={e['rc-clin']} />
                    <p className="text-caption">A GP, pharmacist, nurse practitioner or specialist — usually from outside. You record what they did.</p>
                </div>
                {clin === 'other' ? (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-oname">
                                Name <Req />
                            </Label>
                            <Input id="rc-oname" value={other.name} aria-invalid={!!e['rc-oname']} onChange={(ev) => (setOther({ ...other, name: ev.target.value }), setE({}))} />
                            <InputError message={e['rc-oname']} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-orole">
                                Role <Req />
                            </Label>
                            <Select value={other.role || undefined} onValueChange={(v) => (setOther({ ...other, role: v as ClinRole }), setE({}))}>
                                <SelectTrigger id="rc-orole" className="w-full" aria-invalid={!!e['rc-orole']}>
                                    <SelectValue placeholder="Choose" />
                                </SelectTrigger>
                                <SelectContent>
                                    {(['GP', 'Pharmacist', 'Nurse practitioner', 'Specialist'] as ClinRole[]).map((k) => (
                                        <SelectItem key={k} value={k}>
                                            {k}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={e['rc-orole']} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-oprac">Practice or service</Label>
                            <Input id="rc-oprac" value={other.practice} onChange={(ev) => setOther({ ...other, practice: ev.target.value })} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-oreg">Registration number (optional)</Label>
                            <Input id="rc-oreg" value={other.reg} onChange={(ev) => setOther({ ...other, reg: ev.target.value })} />
                        </div>
                    </div>
                ) : null}
                <DateTimeField id="rc-when" label="When it happened" value={when} onChange={(v) => (setWhen(v), setE({}))} error={e['rc-when']} />
                <div className="space-y-2">
                    <Label id="rc-where-l">
                        How <Req />
                    </Label>
                    <div id="rc-where" tabIndex={-1}>
                        <TilePicker labelledBy="rc-where-l" value={where || null} invalid={!!e['rc-where'] && !where} onChange={(k) => (setWhere(k as Where), setE({}))} tiles={WHERE_TILES} />
                    </div>
                    <InputError message={e['rc-where']} />
                </div>
            </>
        ),
        took: (
            <>
                <div className="space-y-2">
                    <Label id="rc-tookp-l">
                        {person.pref} <Req />
                    </Label>
                    <div id="rc-tookp" tabIndex={-1}>
                        <TilePicker
                            labelledBy="rc-tookp-l"
                            value={tookP || null}
                            invalid={!!e['rc-tookp'] && !tookP}
                            onChange={(k) => (setTookP(k as 'yes' | 'no'), setE({}))}
                            tiles={[
                                { key: 'yes', label: 'Took part', description: `${person.pref} was there and had a say`, icon: UserRound },
                                { key: 'no', label: 'Didn’t take part', description: 'Say why', icon: XCircle },
                            ]}
                        />
                    </div>
                    <InputError message={e['rc-tookp']} />
                </div>
                {tookP === 'no' ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-pwhy">
                            Why not <Req />
                        </Label>
                        <Textarea id="rc-pwhy" rows={2} value={pWhy} aria-invalid={!!e['rc-pwhy']} onChange={(ev) => (setPWhy(ev.target.value), setE({}))} />
                        <InputError message={e['rc-pwhy']} />
                    </div>
                ) : null}
                <div className="space-y-2">
                    <Label id="rc-tookw-l">
                        Whānau, welfare guardian or EPOA <Req />
                    </Label>
                    <div id="rc-tookw" tabIndex={-1}>
                        <TilePicker
                            labelledBy="rc-tookw-l"
                            value={tookW || null}
                            invalid={!!e['rc-tookw'] && !tookW}
                            onChange={(k) => (setTookW(k as 'took' | 'told' | 'none'), setE({}))}
                            tiles={[
                                { key: 'took', label: 'Took part', description: 'In person, by phone or video', icon: Users },
                                { key: 'told', label: 'Told afterwards', description: 'Say who, and how', icon: Phone },
                                { key: 'none', label: 'Not involved', description: 'Say why', icon: XCircle },
                            ]}
                        />
                    </div>
                    <InputError message={e['rc-tookw']} />
                </div>
                {tookW === 'took' || tookW === 'told' ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-wwho">
                            Who <Req />
                        </Label>
                        <Input id="rc-wwho" value={wWho} aria-invalid={!!e['rc-wwho']} placeholder="e.g. Ruby Liu — sister, welfare guardian" onChange={(ev) => (setWWho(ev.target.value), setE({}))} />
                        <InputError message={e['rc-wwho']} />
                    </div>
                ) : null}
                {tookW === 'none' ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-wwhy">
                            Why not <Req />
                        </Label>
                        <Textarea id="rc-wwhy" rows={2} value={wWhy} aria-invalid={!!e['rc-wwhy']} placeholder={`e.g. ${person.pref} asked for it to be just them.`} onChange={(ev) => (setWWhy(ev.target.value), setE({}))} />
                        <InputError message={e['rc-wwhy']} />
                    </div>
                ) : null}
            </>
        ),
        meds: (
            <>
                <p className="text-sm">
                    Each of {person.pref}’s current orders, from the chart. A change is a recommendation: nothing changes on the chart until the prescriber agrees and the change is entered and checked in Orders.
                </p>
                <ul className="space-y-3">
                    {orders.map((o) => {
                        const row = rows[o.id];
                        if (hiddenFor(p, o.id))
                            return (
                                <li key={o.id} className="rounded-lg border p-3">
                                    <div className="flex flex-wrap items-start justify-between gap-3">
                                        <ConcealedIdentity compact />
                                        <StatusBadge variant="neutral" className="rounded-[8px]">
                                            A house lead records this one
                                        </StatusBadge>
                                    </div>
                                </li>
                            );
                        return (
                            <li key={o.id} className="space-y-2 rounded-lg border p-3">
                                <div className="grid items-start gap-3 sm:grid-cols-[1fr_260px]">
                                    <span className="min-w-0">
                                        <span className="block text-sm font-semibold">
                                            {o.med} {o.strength}
                                        </span>
                                        <span className="block text-caption">
                                            {o.dose} · {o.when}
                                            {o.course ? ` · ${o.course}` : ''}
                                            {o.covert ? ' · covert plan' : ''}
                                        </span>
                                    </span>
                                    <div className="space-y-1">
                                        {outcomeTiles(o.id, row)}
                                        <InputError message={e[`rc-out-${o.id}`]} />
                                    </div>
                                </div>
                                {['change', 'stop', 'swap'].includes(row.outcome) ? (
                                    <div className="space-y-1.5">
                                        <Label htmlFor={`rc-what-${o.id}`}>
                                            What the clinician recommends <Req />
                                        </Label>
                                        <Input id={`rc-what-${o.id}`} value={row.what} aria-invalid={!!e[`rc-what-${o.id}`]} placeholder={row.outcome === 'stop' ? 'e.g. Stop — no longer needed since the course ended' : 'e.g. Take at 8:00 pm instead of 8:00 am'} onChange={(ev) => setRow(o.id, { what: ev.target.value })} />
                                        <InputError message={e[`rc-what-${o.id}`]} />
                                        {prescriber ? (
                                            <label className="flex items-start gap-2 text-sm">
                                                <Checkbox checked={row.agreedNow} onCheckedChange={(c) => setRow(o.id, { agreedNow: !!c })} />
                                                {clinician!.name} prescribes and agreed it at the review
                                            </label>
                                        ) : (
                                            <p className="text-caption">It waits for the prescriber’s decision — record it on the change once they’ve answered.</p>
                                        )}
                                    </div>
                                ) : null}
                                {row.outcome === 'watch' ? (
                                    <div className="grid gap-3 sm:grid-cols-[1fr_240px]">
                                        <div className="space-y-1.5">
                                            <Label htmlFor={`rc-watch-${o.id}`}>
                                                What to watch for <Req />
                                            </Label>
                                            <Input id={`rc-watch-${o.id}`} value={row.watch} aria-invalid={!!e[`rc-watch-${o.id}`]} placeholder="e.g. Sleepiness in the mornings" onChange={(ev) => setRow(o.id, { watch: ev.target.value })} />
                                            <InputError message={e[`rc-watch-${o.id}`]} />
                                        </div>
                                        <div className="space-y-1.5">
                                            <Label htmlFor={`rc-until-${o.id}-date`}>
                                                Until <Req />
                                            </Label>
                                            <DatePicker id={`rc-until-${o.id}-date`} label="Watch until" value={row.until} invalid={!!e[`rc-until-${o.id}-date`]} onChange={(v) => setRow(o.id, { until: v })} />
                                            <InputError message={e[`rc-until-${o.id}-date`]} />
                                        </div>
                                    </div>
                                ) : null}
                            </li>
                        );
                    })}
                </ul>
                {hiddenRows.length ? <p className="text-caption">{hiddenRows.length} controlled medicine{hiddenRows.length === 1 ? '' : 's'} can’t be shown to you. A house lead with controlled-medicine access adds its outcome — it shows as “Outcome to add” until they do.</p> : null}
                {newMed.on ? (
                    <div className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-newname">
                                Start something new — the medicine <Req />
                            </Label>
                            <Input id="rc-newname" value={newMed.name} aria-invalid={!!e['rc-newname']} onChange={(ev) => (setNewMed({ ...newMed, name: ev.target.value }), setE({}))} />
                            <InputError message={e['rc-newname']} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-newwhat">
                                What the clinician recommends <Req />
                            </Label>
                            <Input id="rc-newwhat" value={newMed.what} aria-invalid={!!e['rc-newwhat']} onChange={(ev) => (setNewMed({ ...newMed, what: ev.target.value }), setE({}))} />
                            <InputError message={e['rc-newwhat']} />
                        </div>
                        <div className="sm:col-span-2">
                            <Button type="button" variant="outline" size="sm" onClick={() => setNewMed({ on: false, name: '', what: '' })}>
                                Remove
                            </Button>
                        </div>
                    </div>
                ) : (
                    <Button type="button" variant="outline" size="sm" onClick={() => setNewMed({ ...newMed, on: true })}>
                        Start something new
                    </Button>
                )}
            </>
        ),
        summary: (
            <>
                <div className="space-y-1.5">
                    <Label htmlFor="rc-summary">
                        What the clinician said <Req />
                    </Label>
                    <Textarea id="rc-summary" rows={4} value={summary} aria-invalid={!!e['rc-summary']} placeholder="In their words, or a short summary of their letter." onChange={(ev) => (setSummary(ev.target.value), setE({}))} />
                    <InputError message={e['rc-summary']} />
                    <p className="text-caption">Shown only to people who manage reviews. Everyone else sees each medicine’s outcome and what to watch.</p>
                </div>
                <div className="space-y-1.5">
                    <Label id="rc-letter-l">The clinician’s written review (optional)</Label>
                    {file ? <StagedFileCard file={file} onRemove={() => setFile(null)} /> : <FileDropzone id="rc-letter" aria-labelledby="rc-letter-l" multiple={false} accept="application/pdf,image/*" hint="Their letter or report — stored privately. It’s the source record." onFiles={(x) => setFile(x[0] ?? null)} />}
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-dbi">Drug burden index (optional — the clinician’s figure)</Label>
                        <Input id="rc-dbi" value={dbi} onChange={(ev) => setDbi(ev.target.value)} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-falls">Falls in the last 3 months (optional — the clinician’s note)</Label>
                        <Input id="rc-falls" value={falls} onChange={(ev) => setFalls(ev.target.value)} />
                    </div>
                </div>
                <p className="text-caption">These figures are only what the clinician wrote. They’re never calculated here.</p>
            </>
        ),
        next: (
            <>
                {r.kind === 'regular' ? (
                    <Notice tone="neutral" title={`The next regular review is booked for ${labelIso(nextIso)}`}>
                        {`Every ${every.months} months — ${every.own ? `set for ${person.pref}` : 'the organisation default'}. ${r.owner} owns it.`}
                    </Notice>
                ) : (
                    <Notice tone="neutral" title={regularNext ? `${person.pref}’s regular review stays booked for ${regularNext.due}` : `${person.pref} has no regular review booked`}>
                        A triggered review doesn’t change the regular cycle.
                    </Notice>
                )}
                <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                    <Checkbox checked={earlier} onCheckedChange={(c) => (setEarlier(!!c), setE({}))} />
                    The clinician asked for an earlier review
                </label>
                {earlier ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-earlier-date">
                            Earlier review by <Req />
                        </Label>
                        <DatePicker id="rc-earlier-date" label="Earlier review by" value={earlierIso} invalid={!!e['rc-earlier-date']} onChange={(v) => (setEarlierIso(v), setE({}))} />
                        <InputError message={e['rc-earlier-date']} />
                    </div>
                ) : null}
            </>
        ),
        review: clinician ? (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={Stethoscope} title="Who did it" onEdit={() => setStep(0)}>
                    <ReviewRow label="Clinician" value={`${clinician.name} — ${clinician.role}`} />
                    <ReviewRow label="When" value={localLabel(when)} />
                    <ReviewRow label="How" value={where ? WHERE_LABEL[where] : '—'} />
                </ReviewCard>
                <ReviewCard icon={Users} title="Who took part" onEdit={() => setStep(1)}>
                    <ReviewRow label={person.pref} value={tookP === 'yes' ? 'Took part' : `Didn’t — ${pWhy}`} />
                    <ReviewRow label="Whānau" value={tookW === 'took' ? `Took part — ${wWho}` : tookW === 'told' ? `Told afterwards — ${wWho}` : `Not involved — ${wWhy}`} />
                </ReviewCard>
                <ReviewCard icon={Pill} title="Each medicine" onEdit={() => setStep(2)}>
                    <ReviewRow label="Changes" value={changes ? `${changes}${agreedNow ? ` — ${agreedNow} agreed at the review` : ' — waiting for the prescriber'}` : 'None'} />
                    <ReviewRow label="To watch" value={watches ? `${watches} — a follow-up for ${r.owner} each` : 'None'} />
                    <ReviewRow label="Continue" value={String(orders.filter((o) => !hiddenFor(p, o.id) && rows[o.id].outcome === 'continue').length)} />
                </ReviewCard>
                <ReviewCard icon={CalendarClock} title="Next review" onEdit={() => setStep(4)}>
                    <ReviewRow label="Next" value={r.kind === 'regular' || earlier ? nextLabel : `${nextLabel} (unchanged)`} />
                    <ReviewRow label="Letter" value={file ? file.name : 'Not attached'} />
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you save">
                        {`Nothing changes on ${person.pref}’s chart yet. ${changes ? `${changes - agreedNow ? `${changes - agreedNow} ${changes - agreedNow === 1 ? 'change waits' : 'changes wait'} for the prescriber’s decision; ` : ''}${agreedNow ? `${agreedNow} agreed ${agreedNow === 1 ? 'change goes' : 'changes go'} to Changes to make, to enter and check in Orders; ` : ''}` : ''}${watches ? `${watches} follow-up${watches === 1 ? '' : 's'} open for what to watch; ` : ''}${r.kind === 'regular' ? `the next regular review is booked for ${nextLabel}.` : 'the regular cycle carries on.'} Any change flags ${person.pref}’s support for reassessment.`}
                    </Notice>
                    <InputError message={e['rc-save']} />
                </div>
            </div>
        ) : null,
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={`Record the outcome — ${r.id}, ${person.legal}`}
                description="Who did it, who took part, each medicine, the summary, the next review, then review."
                railIcon={FileSignature}
                railTitle="Record the outcome"
                railSub={`${person.pref} · ${kindText(r).toLowerCase()} · ${r.id}`}
                steps={steps.map((x, n) => ({ ...x, disabled: n > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(n) => n <= step && setStep(n)}
                pct={Math.round(((step + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
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
                            {phase === 'sending' ? 'Saving…' : 'Record the outcome'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title="Outcome recorded"
                            blurb={`${r.id} is recorded. ${changes ? 'Its changes are in Changes from reviews. ' : ''}${r.kind === 'regular' ? `The next review is booked for ${nextLabel}.` : ''}`}
                            actions={
                                <Button type="button" autoFocus onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(88vh, 800px)"
            >
                <WizardStepPane>
                    <div className="space-y-4">{body[cur]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog open={discard} onClose={() => setDiscard(false)} onConfirm={() => (setDiscard(false), onClose())} title="Discard this outcome?" description="Nothing has been saved." confirmText="Discard" cancelText="Keep going" />
        </>
    );
}
