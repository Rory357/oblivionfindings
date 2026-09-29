/* ONE recording dialog for every entry point (P01). Scheduled dose, as-needed
 * dose, guided round, MAR, client profile, Fleet transport and follow-up
 * re-offer all open THIS component; only the locked "Opened from" context
 * changes. Steps, requirements, outcomes and states come from contract.ts.
 * Composes the real WizardShell, ReviewCard/ReviewRow, WizardSuccessPane,
 * DateTimeField, Popover+Command picker, Checkbox, Input, Textarea, Select and
 * ConfirmDialog. Synthetic: nothing is sent. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Command, CommandEmpty, CommandGroup, CommandInput, CommandItem, CommandList } from '@/components/ui/command';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Popover, PopoverContent, PopoverTrigger } from '@/components/ui/popover';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { cn } from '@/lib/utils';
import {
    AlertOctagon,
    Check,
    ChevronDown,
    ChevronLeft,
    ChevronRight,
    ClipboardCheck,
    EyeOff,
    FileText,
    Hand,
    Link2,
    Loader2,
    LogOut,
    MessageSquare,
    PauseCircle,
    Pill,
    RefreshCw,
    Repeat,
    Search,
    Send,
    ShieldCheck,
    Truck,
    User,
    UserCheck,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react';
import { NOW_LABEL, NOW_LOCAL, labelOf, localToMinutesToday } from './clock';
import {
    AMOUNT_REASONS,
    AWAY_REASONS,
    BLOCKS,
    type Candidate,
    GIVEN_LIKE,
    LATE_REASONS,
    type Outcome,
    PIN_RULES,
    type Requirements,
    type SecondKind,
    WITHHELD_REASONS,
    asOrdered,
    candidates,
    fill,
    fmtAmount,
    lateBy,
    prnRequirements,
    requirementsFor,
    windowOf,
} from './contract';
import { PEOPLE, PERSONAS, allDoseById, prnById, type Amount, type Dose, type PrnOrder } from './data';
import { useStore } from './store';
import {
    AllergyNotice,
    BlockedPanel,
    DesignNote,
    IdentityHeader,
    KV,
    MatchLine,
    MedicinePhoto,
    NotConfigured,
    Notice,
    OrderLine,
    SupportChip,
    TilePicker,
    type Tile,
} from './ui';

export type EntryPoint = 'meds-today' | 'round' | 'as-needed' | 'mar' | 'client-profile' | 'transport' | 'follow-up' | 'my-day';
export const ENTRY_LABEL: Record<EntryPoint, string> = {
    'meds-today': 'Meds today › Schedule',
    round: 'Meds today › Rounds (guided round)',
    'as-needed': 'Meds today › As-needed',
    mar: 'MAR chart',
    'client-profile': 'Client profile › Medical',
    transport: 'Fleet › Transport',
    'follow-up': 'A follow-up',
    'my-day': 'My Day',
};
export type Target = { kind: 'scheduled'; doseId: string } | { kind: 'prn'; orderId: string };
export type Mode = 'record' | 'notgiven' | 'reoffer';

const STEPS = [
    { key: 'safety', label: 'Safety checks', blurb: 'Person, allergies, instructions', icon: ShieldCheck },
    { key: 'outcome', label: 'Record outcome', blurb: 'What actually happened', icon: ClipboardCheck },
    { key: 'review', label: 'Review & sign', blurb: 'Check, then save', icon: Check },
] as const;

type Phase = 'edit' | 'sending' | 'rejected' | 'uncertain' | 'duplicate' | 'done';
interface Form {
    step: number;
    outcome: Outcome | null;
    reason: string;
    lateReason: string;
    note: string;
    when: string;
    followBy: string;
    prnReason: string;
    checkBy: string;
    amtMode: 'asOrdered' | 'less' | 'more' | 'prescriber';
    amt: number | null;
    amtReason: string;
    sev: string;
    imm: string;
    rx: { who: string; when: string; readBack: boolean; note: string };
    obs: Record<string, string>;
    second: { name: string | null; pin: string; forgot: boolean };
    packChecked: boolean;
    changeAck: boolean;
}
type Errors = Record<string, string>;

/* ───────────── outcome tiles per support mode (EM-04, NF-11) ───────────── */
function outcomeTiles(p: string, support: Dose['support'], mode: Mode, isPrn: boolean, givenWhy: string | null): Tile[] {
    if (isPrn) return [{ key: 'given', label: 'Given', description: 'You gave the medicine', icon: Check, disabled: givenWhy }];
    if (mode === 'reoffer')
        return [
            { key: 'reoffered', label: 'Given after re-offer', description: 'Offered again and taken', icon: Repeat, disabled: givenWhy },
            { key: 'refused', label: 'Refused again', description: `${p} still chose not to take it`, icon: X },
        ];
    const given: Tile[] =
        support === 'prompt'
            ? [
                  { key: 'prompted', label: 'Taken with prompting', description: `${p} took it after a reminder`, icon: MessageSquare, disabled: givenWhy },
                  { key: 'selfmanaged', label: 'Took it without a prompt', description: `${p} managed it without help today`, icon: UserCheck, disabled: givenWhy },
              ]
            : support === 'assist'
              ? [{ key: 'assisted', label: 'Taken with assistance', description: 'You helped; the person took it', icon: Hand, disabled: givenWhy }]
              : [{ key: 'given', label: 'Given', description: 'You gave the medicine', icon: Check, disabled: givenWhy }];
    return [
        ...given.map((t) => (mode === 'notgiven' ? { ...t, disabled: t.disabled ?? 'Can’t be recorded as given right now' } : t)),
        { key: 'refused', label: 'Refused', description: `${p} chose not to take it`, icon: X },
        { key: 'withheld', label: 'Withheld', description: 'Not given, with a reason', icon: PauseCircle },
        { key: 'away', label: 'Away', description: `${p} isn’t here`, icon: LogOut },
    ];
}
const isGivenLike = (o: Outcome | null) => !!o && (GIVEN_LIKE.includes(o) || o === 'selfmanaged');

/* ───────────── the dialog ───────────── */
export function RecordDoseDialog({
    target,
    entry,
    mode = 'record',
    onClose,
    onAction,
    onNext,
    nextLabel,
    returnFocus,
}: {
    target: Target;
    entry: EntryPoint;
    mode?: Mode;
    onClose: () => void;
    onAction?: (a: 'ovr-request' | 'eligibility' | 'chart' | 'error-created', id?: string) => void;
    onNext?: () => void;
    nextLabel?: string | null;
    returnFocus?: () => HTMLElement | null;
}) {
    const s = useStore();
    const ctx = s.ctx;
    const isPrn = target.kind === 'prn';
    const dose: Dose | null = target.kind === 'scheduled' ? asOrdered(allDoseById(target.doseId), ctx.scenario) : null;
    const prn: PrnOrder | null = target.kind === 'prn' ? prnById(target.orderId) : null;
    const pid = (dose ?? prn)!.pid;
    const person = PEOPLE[pid];
    const med = (dose ?? prn)!.med;
    const strength = (dose ?? prn)!.strength;
    const amount: Amount = (dose ?? prn)!.amount;
    const req: Requirements = dose ? requirementsFor(dose, ctx) : prnRequirements(prn!, ctx);
    const rowId = dose?.id ?? `prn-${prn!.id}`;
    const orderChanged = !!dose && dose.id === 'r2' && ctx.scenario === 'orderChanged';

    const [f, setF] = useState<Form>(() => ({
        step: mode === 'notgiven' || mode === 'reoffer' ? 1 : 0,
        outcome: null,
        reason: '',
        lateReason: '',
        note: '',
        when: NOW_LOCAL,
        followBy: '',
        prnReason: '',
        checkBy: '',
        amtMode: 'asOrdered',
        amt: amount.range ? null : amount.n,
        amtReason: '',
        sev: '',
        imm: '',
        rx: { who: '', when: '', readBack: false, note: '' },
        obs: {},
        second: { name: null, pin: '', forgot: false },
        packChecked: false,
        changeAck: false,
    }));
    const [errors, setErrors] = useState<Errors>({});
    const [phase, setPhase] = useState<Phase>('edit');
    const [serverMsg, setServerMsg] = useState<string | null>(null);
    const [rejectedOnce, setRejectedOnce] = useState(false);
    const [discard, setDiscard] = useState(false);
    const [result, setResult] = useState<{ title: string; tone: 'success' | 'warning'; lines: string[] } | null>(null);
    const dirty = useRef(false);
    const bodyRef = useRef<HTMLDivElement>(null);
    const set = (patch: Partial<Form>) => {
        dirty.current = true;
        setF((x) => ({ ...x, ...patch }));
        // Fixing a field clears its own message; other messages (and values) stay.
        const touched = new Set<string>(Object.keys(patch));
        if (touched.has('second')) ['second', 'pin'].forEach((k) => touched.add(k));
        if (touched.has('rx')) ['rxWho', 'rxWhen', 'rxReadBack'].forEach((k) => touched.add(k));
        if (touched.has('obs')) Object.keys(patch.obs ?? {}).forEach((k) => touched.add(`obs-${k}`));
        if (touched.has('outcome')) touched.add('reason');
        setErrors((e) => {
            const n = { ...e };
            touched.forEach((k) => delete n[k]);
            return n;
        });
    };

    /* ── blocks ── */
    const competencyBlock = req.competency === 'expired' ? 'competencyExpired' : req.competency === 'restricted' ? 'restrictedBlock' : null;
    const givenWhy = req.blockAll
        ? fill(BLOCKS[req.blockAll].title, person.pref, med)
        : req.blockGiven
          ? fill(BLOCKS[req.blockGiven].title, person.pref, med)
          : competencyBlock
            ? competencyBlock === 'competencyExpired'
                ? 'Your competency expired on 14 September 2026'
                : 'You can’t sign doses as given — competency restricted'
            : null;
    const tiles = outcomeTiles(person.pref, req.support, mode, isPrn, givenWhy);
    const givenLike = isGivenLike(f.outcome);
    const secondKind: SecondKind | null = !givenLike
        ? null
        : req.witness === 'needed'
          ? 'witness'
          : req.countersignRule
            ? 'countersign'
            : f.amtMode === 'less'
              ? 'amount'
              : null;
    const cands: Candidate[] = secondKind ? candidates(ctx, secondKind) : [];
    const anyoneAvailable = cands.some((c) => c.ok || c.fallbackOk);
    const amountUnconfirmed = secondKind === 'amount' && !anyoneAvailable;
    const countersignNobody = secondKind === 'countersign' && !anyoneAvailable;
    const whenMin = localToMinutesToday(f.when);
    const win = dose ? windowOf(dose.slotMin) : null;
    const outsideWindow = !!(dose && givenLike && whenMin != null && win && (whenMin < win.start || whenMin > win.end));
    const selfName = PERSONAS[ctx.persona].name;

    /* ── validation (keeps values; focuses the first error) ── */
    function validateOutcome(): Errors {
        const e: Errors = {};
        if (!f.outcome) e.outcome = 'Choose what happened.';
        if (f.outcome === 'withheld' && !f.reason) e.reason = 'Choose a reason for withholding.';
        if (f.outcome === 'away' && !f.reason) e.reason = `Choose where ${person.pref} is.`;
        if (f.outcome === 'refused' && !isPrn) {
            if (!f.followBy) e.followBy = 'Choose when to follow up — offer again or record why not.';
        }
        if (givenLike) {
            if (whenMin == null) e.when = 'Choose the date and time it was given.';
            else if (whenMin > localToMinutesToday(NOW_LOCAL)!) e.when = 'The time can’t be later than now (Monday 28 September 2026, 9:12 am NZDT).';
            else if (outsideWindow && !f.lateReason) e.lateReason = `Say why it’s outside the dose window (${win!.label}).`;
            if (isPrn) {
                if (!f.prnReason) e.prnReason = 'Choose what it was for.';
                if (!f.checkBy) e.checkBy = 'Choose when to check whether it helped.';
            }
            if (f.amtMode === 'asOrdered' && amount.range && f.amt == null) e.amt = 'Choose the amount given.';
            if (f.amtMode === 'less') {
                const min = amount.range ? amount.range[0] : amount.n;
                if (!(f.amt! > 0)) e.amt = 'Nothing given? Go back and record it as refused or withheld.';
                else if (f.amt! >= min) e.amt = `That isn’t less than the order (${fmtAmount(min, amount)}). If more was given, use “More than ordered was given”.`;
                if (!f.amtReason) e.amtReason = 'Choose why less was given.';
            }
            if (f.amtMode === 'more') {
                if (!(f.amt! > amount.n)) e.amt = `Enter the amount actually given — more than ${fmtAmount(amount.n, amount)}.`;
                if (!f.sev) e.sev = 'Choose how serious it seems.';
                if (!f.imm.trim()) e.imm = 'Say what you did straight away.';
                if (!f.note.trim()) e.note = 'Say what happened — it goes into the medication error report.';
            }
            if (f.amtMode === 'prescriber') {
                if (!f.rx.who.trim()) e.rxWho = 'Enter who gave the instruction.';
                if (!(f.amt! > 0) || f.amt === amount.n) e.amt = 'Enter the new dose — different from the order.';
                const rm = localToMinutesToday(f.rx.when);
                if (rm == null) e.rxWhen = 'Choose when the prescriber told you.';
                else if (rm > localToMinutesToday(NOW_LOCAL)!) e.rxWhen = 'The time can’t be later than now (Monday 28 September 2026, 9:12 am NZDT).';
                if (!f.rx.readBack) e.rxReadBack = 'Read the instruction back to the prescriber, then tick this.';
            }
            for (const r of req.observationRules) {
                const v = f.obs[r.obs!.key] ?? '';
                if (!v.trim()) e[`obs-${r.obs!.key}`] = `Enter the ${r.obs!.label.toLowerCase()} — the medication rule asks for it.`;
                else if (!/^\d+(\.\d+)?$/.test(v.trim())) e[`obs-${r.obs!.key}`] = `Enter the reading as a number, ${r.obs!.example}.`;
            }
            if (secondKind && !amountUnconfirmed && !countersignNobody) {
                if (!f.second.name) e.second = `Choose who is ${secondKind === 'witness' ? 'witnessing' : secondKind === 'amount' ? 'confirming' : 'confirming the dose'}.`;
                else if (!f.second.forgot && !/^\d{6}$/.test(f.second.pin)) e.pin = 'Enter their 6-digit PIN.';
            }
        }
        return e;
    }
    const ORDER = ['outcome', 'reason', 'followBy', 'prnReason', 'second', 'pin', 'when', 'lateReason', 'amt', 'amtReason', 'sev', 'imm', 'rxWho', 'rxWhen', 'rxReadBack', 'checkBy', 'obs-bsl', 'note'];
    function focusFirst(e: Errors) {
        const first = ORDER.find((k) => e[k]) ?? Object.keys(e)[0];
        window.setTimeout(() => {
            const el = bodyRef.current?.querySelector<HTMLElement>(`[data-field="${first}"] button:not([aria-disabled="true"]), [data-field="${first}"] input, [data-field="${first}"] textarea, [data-field="${first}"] [role="combobox"]`);
            el?.focus();
        }, 30);
    }
    function next() {
        if (f.step === 0) {
            setF((x) => ({ ...x, step: 1 }));
            return;
        }
        if (f.step === 1) {
            const e = validateOutcome();
            setErrors(e);
            if (Object.keys(e).length) return focusFirst(e);
            setF((x) => ({ ...x, step: 2 }));
        }
    }

    /* ── save (synthetic server; one request key, duplicate-safe) ── */
    function save() {
        setPhase('sending');
        setServerMsg(null);
        if (dose) s.setSending(dose.id, true);
        window.setTimeout(() => {
            if (dose) s.setSending(dose.id, false);
            const sc = ctx.scenario;
            const second = f.second;
            if (secondKind && !second.forgot && second.pin === '000000') {
                setPhase('edit');
                setF((x) => ({ ...x, step: 1 }));
                const err = { pin: `Incorrect PIN. ${PIN_RULES.attempts - 1} tries left before ${second.name}’s PIN locks for ${PIN_RULES.lockoutMin} minutes.` };
                setErrors(err);
                return focusFirst(err);
            }
            if (secondKind && !second.forgot && second.pin === '999999') {
                setPhase('edit');
                setF((x) => ({ ...x, step: 1 }));
                const fb = secondKind !== 'witness' ? ', or tick “They’ve forgotten their PIN”' : '';
                const err = { pin: `${second.name}’s PIN is locked after ${PIN_RULES.attempts} wrong attempts. It unlocks at 9:27 am, or a house lead or clinical lead can reset it. Choose another ${secondKind === 'witness' ? 'witness' : 'colleague'}${fb}.` };
                setErrors(err);
                return focusFirst(err);
            }
            if (sc === 'reject' && !rejectedOnce) {
                setRejectedOnce(true);
                setPhase('rejected');
                setServerMsg('Your competency was checked again when you saved: your competency record changed at 9:10 am and no longer covers this dose.');
                if (dose) s.setFlag(dose.id, 'rejected');
                return;
            }
            if (sc === 'uncertain') {
                setPhase('uncertain');
                if (dose) s.setFlag(dose.id, 'uncertain');
                return;
            }
            if (sc === 'duplicate' && dose?.id === 'r2') {
                setPhase('duplicate');
                s.setRecord('r2', { outcome: 'given', at: '9:10 am', by: 'Daniel Ahn', line: 'Given 9:10 am · Daniel A. (recorded on another device)' });
                return;
            }
            if (dose) s.setFlag(dose.id, null);
            const offline = sc === 'offline';
            const outLabel = tiles.find((t) => t.key === f.outcome)?.label ?? f.outcome!;
            const t = givenLike ? labelOf(whenMin!) : NOW_LABEL;
            const amtTxt = givenLike ? fmtAmount(f.amt, amount) : '';
            const warn: string[] = [];
            const lines: string[] = [];
            let refs: { err: string; inc: string } | undefined;
            if (givenLike && f.amtMode === 'less') warn.push(amountUnconfirmed ? 'Different amount not confirmed by a second person · follow-up for the house lead' : `Less than ordered (${f.amtReason.toLowerCase()}) · confirmed by ${second.name}`);
            if (givenLike && f.amtMode === 'prescriber') warn.push(`Prescriber’s phone instruction (${f.rx.who}) · waiting for a lead to countersign`);
            if (givenLike && f.amtMode === 'more') {
                refs = { err: 'ME-2026-031', inc: 'INC-2026-118' };
                warn.push(`More than ordered — medication error ${refs.err} and incident ${refs.inc} created`);
            }
            if (countersignNobody) warn.push('Not confirmed by a second person — nobody else on shift (from the roster) · follow-up for the house lead');
            if (givenLike && req.witness === 'override') warn.push('No witness — override by Rangi Parata: Nobody else on shift can witness (from the roster) · follow-up for the house lead next shift');
            if (second.forgot && second.name) warn.push(`Second person not verified — PIN forgotten · waiting for ${second.name} to confirm (by 9:42 am)`);
            if (outsideWindow) warn.push(`Outside the dose window · ${f.lateReason}`);
            if (f.outcome === 'refused' && !isPrn) warn.push(`Follow-up · ${PERSONAS[ctx.persona].short} · by ${labelOf(localToMinutesToday(f.followBy) ?? 720)}`);
            const who = PERSONAS[ctx.persona].short;
            const lineFor: Record<string, string> = {
                given: `Given ${t} · ${who}`,
                prompted: `Taken ${t} · ${who} reminded ${person.pref}`,
                assisted: `Taken ${t} · ${who} helped`,
                selfmanaged: `${person.pref} took it without a prompt · ${t} · seen by ${who}`,
                reoffered: `Given ${t} after re-offer · first offered 8:10 am`,
                refused: mode === 'reoffer' ? `Refused again ${t} · first offered 8:10 am` : `Refused ${t} · ${person.pref} said no`,
                withheld: `Withheld ${t} · ${[...WITHHELD_REASONS].find((r) => r.value === f.reason)?.label ?? f.reason}`,
                away: `Away · ${[...AWAY_REASONS].find((r) => r.value === f.reason)?.label ?? f.reason}`,
            };
            if (dose) {
                if (offline) s.setFlag(dose.id, 'queued');
                else
                    s.setRecord(dose.id, {
                        outcome: f.outcome!,
                        at: t,
                        by: selfName,
                        line: lineFor[f.outcome!] + (givenLike && (f.amtMode !== 'asOrdered' || amount.range) ? ` · ${amtTxt}` : ''),
                        amount: amtTxt,
                        warn,
                        second: secondKind && second.name ? { kind: secondKind, name: second.name, forgot: second.forgot } : undefined,
                    });
            } else if (prn) {
                s.addPrn({ id: `prn-${Date.now()}`, orderId: prn.id, at: t, amount: amtTxt, reason: f.prnReason, by: selfName, state: offline ? 'queued' : 'recorded', checkBy: labelOf(localToMinutesToday(f.checkBy) ?? 600) });
                if (!offline) s.addFollowUp({ id: `fu-prn-${prn.id}`, pid, title: `Check whether ${med.toLowerCase()} helped — ${person.pref}`, src: `As-needed dose given ${t} · Kōwhai House`, owner: selfName, due: `Due ${labelOf(localToMinutesToday(f.checkBy) ?? 600)}`, state: 'due', line: 'Time entered by you' });
            }
            if (f.outcome === 'refused' && !isPrn && dose)
                s.addFollowUp({ id: `fu-ref-${dose.id}`, pid, title: `Follow up ${person.pref}’s refusal of ${med.toLowerCase()}`, src: `Refused ${t} · Kōwhai House`, owner: selfName, due: `Due ${labelOf(localToMinutesToday(f.followBy) ?? 720)}`, state: 'due', line: 'Offer again, or record why not' });
            const base = `${med} for ${person.pref}: ${outLabel.toLowerCase()} at ${t}.`;
            if (offline) {
                // Not a success: nothing is on the chart yet (EM-26). No green check.
                s.toast('warning', `Saved on this device — ${med} for ${person.pref} isn’t on the chart yet. It will send when you reconnect. Don’t record it again.`);
                onClose();
                return;
            }
            if (refs) {
                // More than ordered: the error-and-incident confirmation (P00 v4/v5), not a success pane.
                onAction?.('error-created', `${rowId}|${amtTxt}`);
                onClose();
                return;
            }
            lines.push(`${base} It’s on ${person.pref}’s chart.`);
            warn.forEach((w) => lines.push(w));
            setResult({ title: 'Recorded', tone: warn.length ? 'warning' : 'success', lines });
            setPhase('done');
        }, 1100);
    }

    /* ── close: dirty guard; never while sending ── */
    function requestClose() {
        if (phase === 'sending') return;
        if (phase === 'done' || !dirty.current) return onClose();
        setDiscard(true);
    }

    /* ── completeness ── */
    const pct = useMemo(() => {
        const items = [f.step >= 1, !!f.outcome, givenLike ? !!f.when : !!(f.reason || f.outcome === 'refused'), f.step >= 2];
        return Math.round((items.filter(Boolean).length / items.length) * 100);
    }, [f, givenLike]);

    /* ── step bodies ── */
    const medCard = (
        <section aria-label="Medicine" className="space-y-3 rounded-xl border bg-card p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <p className="text-section-title">
                        {med} <span className="font-normal text-muted-foreground">{strength}</span>
                    </p>
                    <p className="text-subtle">
                        {isPrn ? `${prn!.amount.range ? `${prn!.amount.range[0]}–${prn!.amount.range[1]} ${prn!.amount.plural}` : fmtAmount(prn!.amount.n, prn!.amount)} · by mouth · as needed` : `${fmtAmount(amount.n, amount)} · ${dose!.route.toLowerCase()}`}
                    </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {dose?.cd || prn?.cd ? (
                        <StatusBadge variant="neutral" className="rounded-[8px]">
                            <ShieldCheck className="size-3" /> Controlled
                        </StatusBadge>
                    ) : null}
                    {dose?.covert ? (
                        <StatusBadge variant={req.covert === 'missing' ? 'critical' : 'neutral'} className="rounded-[8px]">
                            <EyeOff className="size-3" /> Covert
                        </StatusBadge>
                    ) : null}
                    <SupportChip support={req.support} />
                </div>
            </div>
            <MedicinePhoto photo={(dose ?? prn)!.photo} med={med} />
            <KV
                rows={[
                    ['Instructions', <span key="i">{isPrn ? prn!.instructions : dose!.instructions} <span className="text-subtle">(from the prescription)</span></span>],
                    ...(isPrn
                        ? ([
                              ['Last 24 hours', <span key="l">{prn!.last24h.length} of {prn!.maxPer24h} doses{prn!.last24h[0] ? ` · last ${prn!.last24h[0].at} by ${prn!.last24h[0].by}` : ''} · counted over the real last 24 hours</span>],
                          ] as [ReactNode, ReactNode][])
                        : ([
                              ['Due', <span key="d">{dose!.slot} · today’s window {win!.label} <span className="text-subtle">(organisation window: 30 min before to 60 min after)</span></span>],
                              ['Order', <OrderLine key="o" d={dose!} />],
                          ] as [ReactNode, ReactNode][])),
                    ...(dose?.covert
                        ? ([['Covert plan', req.covert === 'missing' ? <span key="c" className="font-semibold text-status-critical">No current authorisation on file</span> : <span key="c">{dose.covert.plan} Review due {dose.covert.reviewDue}.</span>]] as [ReactNode, ReactNode][])
                        : []),
                    ...(req.witness !== 'none'
                        ? ([['Witness', req.witness === 'override' ? <span key="w">Not needed now — witness override by Rangi Parata until 3:00 pm</span> : <span key="w">Needed — a different person, clocked in on a shift covering this house, with witness competency and a witness PIN</span>]] as [ReactNode, ReactNode][])
                        : []),
                    ...(req.observationRules.length || req.countersignRule
                        ? ([
                              [
                                  'Medication rules',
                                  <ul key="r" className="space-y-1">
                                      {[...req.observationRules, ...(req.countersignRule ? [req.countersignRule] : [])].map((r) => (
                                          <li key={r.id}>
                                              {r.sentence} <span className="text-subtle">({r.by})</span>
                                          </li>
                                      ))}
                                  </ul>,
                              ],
                          ] as [ReactNode, ReactNode][])
                        : []),
                ]}
            />
        </section>
    );

    const blockPanels = (
        <>
            {req.blockAll ? (
                <BlockedPanel block={req.blockAll} pid={pid} med={med} scenario={ctx.scenario} />
            ) : null}
            {!req.blockAll && req.blockGiven ? (
                <BlockedPanel
                    block={req.blockGiven}
                    pid={pid}
                    med={med}
                    scenario={ctx.scenario}
                    extra={
                        req.blockGiven === 'noWitness' ? (
                            s.override === 'waiting' ? (
                                <Notice tone="info" icon={Send} title="Witness override requested at 9:13 am">
                                    Waiting for a manager. Until they answer, don’t give it without a witness.
                                </Notice>
                            ) : s.override === 'declined' ? (
                                <Notice tone="critical" title="Rangi Parata declined the witness override">
                                    “{s.overrideDecline || 'Jordan can come in at 10:00 am to witness'}” Record it as not given, or wait.
                                </Notice>
                            ) : null
                        ) : null
                    }
                    action={
                        req.blockGiven === 'noWitness' && s.override === 'none' ? (
                            <Button type="button" variant="outline" className="frontline-tap" onClick={() => onAction?.('ovr-request', rowId)}>
                                <Send className="size-4" /> Ask a manager for a witness override
                            </Button>
                        ) : null
                    }
                />
            ) : null}
            {!req.blockAll && !req.blockGiven && competencyBlock ? (
                <BlockedPanel
                    block={competencyBlock}
                    pid={pid}
                    med={med}
                    scenario={ctx.scenario}
                    action={
                        <Button type="button" variant="outline" className="frontline-tap" onClick={() => onAction?.('eligibility')}>
                            <UserCheck className="size-4" /> View my eligibility
                        </Button>
                    }
                />
            ) : null}
        </>
    );

    const step0 = (
        <div className="space-y-4">
            {entry === 'transport' && dose?.transport ? (
                <div className="flex items-start gap-3 rounded-xl border border-primary/40 bg-primary/10 p-3">
                    <span className="mt-0.5 shrink-0 rounded-lg bg-background/60 p-1.5">
                        <Truck className="h-4 w-4 text-primary" />
                    </span>
                    <div className="min-w-0 flex-1 text-sm">
                        <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">During transport · {dose.transport.vehicle}</span>
                            <StatusBadge variant="neutral" className="rounded-[8px]">From the transport</StatusBadge>
                        </div>
                        <p className="text-caption">{dose.transport.trip} · packed {dose.transport.packed}. Locked from the transport you opened.</p>
                    </div>
                </div>
            ) : null}
            <IdentityHeader pid={pid} support={req.support} />
            {orderChanged ? (
                <Notice tone="warning" live="alert" title="This order changed at 9:05 am — check the new instructions" actions={null}>
                    <p>Dr Lena Chen changed Tama’s levetiracetam at 9:05 am, after this round started. Jordan Tipene verified the new order at 9:08 am.</p>
                    <ul className="mt-1 list-disc pl-5">
                        <li>Before: 500 mg (1 tablet) morning and evening</li>
                        <li>Now: <strong>750 mg (1½ tablets)</strong> morning and evening — the dose and amount below use the new order</li>
                    </ul>
                    <div className="mt-2 flex items-center gap-2" data-field="changeAck">
                        <Checkbox id="w-change-ack" checked={f.changeAck} onCheckedChange={(v) => set({ changeAck: v === true })} />
                        <Label htmlFor="w-change-ack" className="text-sm font-normal">I’ve checked the new instructions and the label</Label>
                    </div>
                </Notice>
            ) : null}
            <AllergyNotice pid={pid} />
            {req.allergy.match && req.allergy.rule === 'warn' ? (
                <Notice tone="critical" live="alert" title="Possible allergy match — check before giving">
                    <MatchLine {...req.allergy.match} />
                    <p className="mt-1.5">Your organisation warns on allergy matches. Check with the prescriber before giving; you can still record it as given.</p>
                </Notice>
            ) : null}
            {medCard}
            {!isPrn && req.window === 'late' && !req.blockAll && !req.blockGiven ? (
                <Notice tone="warning" title={`This dose is late — ${lateBy(dose!.slotMin)} after ${dose!.slot}`}>
                    It’s outside today’s window ({win!.label}). Recording isn’t blocked. No late-dose instruction is set for this medicine: <NotConfigured /> Check with the on-call contact before giving it: <NotConfigured />
                </Notice>
            ) : null}
            {!isPrn && req.window === 'notdue' ? (
                <Notice tone="info" title={`Not yet due — the window opens at ${labelOf(win!.start)}`}>
                    Early doses aren’t recorded from here. If {person.pref} will be out when it’s due, the dose can be packed for the trip and recorded when it’s given.
                </Notice>
            ) : null}
            {entry === 'transport' ? (
                <div data-field="packChecked" className="flex items-center gap-2 rounded-lg border p-3">
                    <Checkbox id="w-pack" checked={f.packChecked} onCheckedChange={(v) => set({ packChecked: v === true })} />
                    <Label htmlFor="w-pack" className="text-sm font-normal">
                        I’ve checked the packed medicine against the label and the person <span className="text-status-critical">*</span>
                    </Label>
                </div>
            ) : null}
            {blockPanels}
        </div>
    );

    const secondPerson = secondKind ? (
        <SecondPerson
            kind={secondKind}
            cands={cands}
            value={f.second}
            onChange={(v) => set({ second: v })}
            nobody={amountUnconfirmed || countersignNobody}
            errors={errors}
            rule={req.countersignRule?.sentence}
        />
    ) : null;

    const amountBlock = givenLike ? <AmountField f={f} set={set} amount={amount} errors={errors} secondKind={secondKind} amountUnconfirmed={amountUnconfirmed} secondPerson={secondKind === 'amount' ? secondPerson : null} /> : null;

    const step1 = (
        <div className="space-y-4">
            <IdentityHeader pid={pid} support={req.support} compact />
            <AllergyNotice pid={pid} compact />
            {mode === 'reoffer' ? (
                <Notice tone="neutral" icon={Link2} title="Linked to the refusal at 8:10 am">
                    The refusal stays in the history. Re-offer rule: <NotConfigured />
                </Notice>
            ) : null}
            {(req.blockAll || req.blockGiven || competencyBlock) && !isPrn ? (
                <Notice tone={req.blockAll ? 'warning' : 'critical'} title={givenWhy!}>
                    {req.blockAll ? fill(BLOCKS[req.blockAll].stillText ?? '', person.pref, med) : 'You can still record a refusal, a withhold or an absence. Blocking checks apply to “given” only.'}
                </Notice>
            ) : null}
            <div data-field="outcome" className="space-y-2">
                <Label id="oc-l" className="text-sm font-medium">
                    What happened? <span className="text-status-critical">*</span>
                </Label>
                <TilePicker
                    labelledBy="oc-l"
                    describedBy={errors.outcome ? 'oc-e' : undefined}
                    invalid={!!errors.outcome}
                    value={f.outcome}
                    tiles={req.blockAll ? tiles.map((t) => ({ ...t, disabled: t.disabled ?? 'Nothing can be recorded right now' })) : tiles}
                    onChange={(k) => set({ outcome: k as Outcome, reason: f.outcome === k ? f.reason : '' })}
                />
                <InputError id="oc-e" message={errors.outcome} />
            </div>

            {f.outcome === 'withheld' || f.outcome === 'away' ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field id="reason" label={f.outcome === 'withheld' ? 'Reason for withholding' : `Where is ${person.pref}?`} required error={errors.reason}>
                        <Select value={f.reason || undefined} onValueChange={(v) => set({ reason: v })}>
                            <SelectTrigger id="w-reason" aria-invalid={!!errors.reason} className="frontline-tap w-full">
                                <SelectValue placeholder="Choose a reason" />
                            </SelectTrigger>
                            <SelectContent>
                                {(f.outcome === 'withheld' ? WITHHELD_REASONS : AWAY_REASONS).map((r) => (
                                    <SelectItem key={r.value} value={r.value}>
                                        {r.label}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <NoteField f={f} set={set} errors={errors} placeholder="e.g. Felt sick after breakfast; will offer again at lunch if the plan allows." />
                    {f.outcome === 'withheld' && (req.blockGiven || req.allergy.match) ? (
                        <p className="text-caption sm:col-span-2">Saving a withhold is always allowed while a safety check blocks “given”. The house lead and everyone rostered at Kōwhai House see it until it’s resolved.</p>
                    ) : null}
                </div>
            ) : null}

            {f.outcome === 'refused' ? (
                <div className="space-y-3">
                    <NoteField f={f} set={set} errors={errors} placeholder="e.g. Grace said she felt fine and didn’t want it today" />
                    {!isPrn ? (
                        <div data-field="followBy" className="space-y-2 rounded-xl border p-3">
                            <p className="text-sm font-medium">
                                Follow-up <span className="text-subtle">— owner: you ({selfName})</span>
                            </p>
                            <DateTimeField id="w-follow" label="Follow up by" value={f.followBy} onChange={(v) => set({ followBy: v })} error={errors.followBy} hint="Offer again, or record why not. No default time is set: the re-offer rule is not configured." />
                            <p className="text-caption">It shows in your Follow-ups and the handover until you record a result.</p>
                        </div>
                    ) : null}
                </div>
            ) : null}

            {givenLike ? (
                <div className="space-y-4">
                    {req.witness === 'override' ? (
                        <Notice tone="warning" icon={ShieldCheck} title="No witness needed — override by Rangi Parata until 3:00 pm">
                            Nobody else on shift can witness (from the roster). The dose will be marked “No witness — override by Rangi Parata”, and the house lead gets a follow-up next shift.
                        </Notice>
                    ) : null}
                    {secondKind && secondKind !== 'amount' ? secondPerson : null}
                    {isPrn ? (
                        <div data-field="prnReason" className="space-y-2">
                            <Label id="prn-r" className="text-sm font-medium">
                                What is it for? <span className="text-status-critical">*</span>
                            </Label>
                            <TilePicker
                                labelledBy="prn-r"
                                invalid={!!errors.prnReason}
                                value={f.prnReason || null}
                                onChange={(k) => set({ prnReason: k })}
                                tiles={[...prn!.reasons.map((r) => ({ key: r, label: r, description: 'From the prescription', icon: Pill })), { key: 'Other', label: 'Other', description: 'Say what in the note', icon: FileText }]}
                            />
                            <InputError message={errors.prnReason} />
                        </div>
                    ) : null}
                    <div data-field="when">
                        <DateTimeField id="w-when" label={f.outcome === 'given' || f.outcome === 'reoffered' ? 'Given' : 'Taken'} value={f.when} onChange={(v) => set({ when: v })} error={errors.when} hint="Now by default. The exact minute is kept." />
                    </div>
                    {outsideWindow ? (
                        <Field id="lateReason" label={`Why is it outside the dose window (${win!.label})?`} required error={errors.lateReason}>
                            <Select value={f.lateReason || undefined} onValueChange={(v) => set({ lateReason: v })}>
                                <SelectTrigger id="w-lateReason" aria-invalid={!!errors.lateReason} className="frontline-tap w-full">
                                    <SelectValue placeholder="Choose a reason" />
                                </SelectTrigger>
                                <SelectContent>
                                    {LATE_REASONS.map((r) => (
                                        <SelectItem key={r} value={r}>
                                            {r}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </Field>
                    ) : null}
                    {amountBlock}
                    {req.observationRules.map((r) => (
                        <Field key={r.id} id={`obs-${r.obs!.key}`} label={r.obs!.label} required error={errors[`obs-${r.obs!.key}`]} hint={`Required by the medication rule (${r.by}). No range is set here — follow ${person.pref}’s plan for what the reading means.`}>
                            <div className="flex max-w-xs items-center gap-2">
                                <Input id={`w-obs-${r.obs!.key}`} inputMode="decimal" autoComplete="off" value={f.obs[r.obs!.key] ?? ''} aria-invalid={!!errors[`obs-${r.obs!.key}`]} placeholder={r.obs!.example} onChange={(e) => set({ obs: { ...f.obs, [r.obs!.key]: e.target.value } })} />
                                <span className="text-sm text-muted-foreground">{r.obs!.unit}</span>
                            </div>
                        </Field>
                    ))}
                    {isPrn ? (
                        <div data-field="checkBy" className="space-y-2 rounded-xl border p-3">
                            <p className="text-sm font-medium">
                                Check whether it helped <span className="text-subtle">— owner: you ({selfName})</span>
                            </p>
                            <DateTimeField id="w-check" label="Check by" value={f.checkBy} onChange={(v) => set({ checkBy: v })} error={errors.checkBy} hint="No default: the response-time policy isn’t configured. The check stays open across midnight and shift change until someone records it." />
                        </div>
                    ) : null}
                    <NoteField f={f} set={set} errors={errors} required={f.amtMode === 'more'} placeholder={f.amtMode === 'more' ? 'e.g. Gave 2 tablets instead of 1 — picked up the wrong pack. Noticed when signing.' : 'e.g. Took it with yoghurt. Asked about side effects.'} />
                </div>
            ) : null}
        </div>
    );

    const outcomeLabel = tiles.find((t) => t.key === f.outcome)?.label ?? '';
    const step2 = (
        <div className="space-y-4">
            <IdentityHeader pid={pid} support={req.support} compact />
            {phase === 'sending' ? (
                <Notice tone="info" icon={Loader2} live="status" title="Sending — not yet confirmed">
                    Don’t close this window. Nothing shows as recorded until it’s confirmed.
                </Notice>
            ) : null}
            {phase === 'rejected' ? (
                <Notice tone="critical" live="alert" title="Not recorded — this dose was not saved.">
                    {serverMsg} The chart doesn’t show this dose. What you entered is kept. Ask a colleague with current competency to give it, or record a refusal, withhold or absence.
                </Notice>
            ) : null}
            {phase === 'uncertain' ? (
                <Notice
                    tone="warning"
                    live="alert"
                    title="Not confirmed — check before trying again"
                    actions={
                        <Button type="button" variant="outline" className="frontline-tap" onClick={() => onAction?.('chart', pid)}>
                            <Search className="size-4" /> Check the chart
                        </Button>
                    }
                >
                    We didn’t get confirmation that this was saved, so it isn’t shown as recorded. Check {person.pref}’s chart first. Trying again won’t create a duplicate.
                </Notice>
            ) : null}
            {phase === 'duplicate' ? (
                <Notice tone="warning" live="alert" title="Already recorded — nothing new was saved">
                    Daniel Ahn recorded this dose as given at 9:10 am on another device. Your entry wasn’t saved, so the dose isn’t on the chart twice. If what you did differs, tell Daniel and the house lead.
                </Notice>
            ) : null}
            {req.allergy.match ? <MatchLine {...req.allergy.match} /> : null}
            <ReviewCard icon={Pill} title="Medicine" onEdit={mode === 'record' ? () => setF((x) => ({ ...x, step: 0 })) : undefined}>
                <ReviewRow label="Medicine" value={`${med} ${strength}`} />
                <ReviewRow label="Ordered" value={isPrn ? prn!.instructions.split(' · ')[1] ?? '' : `${fmtAmount(amount.n, amount)} · ${dose!.slot}`} />
                <ReviewRow label="Opened from" value={ENTRY_LABEL[entry]} />
            </ReviewCard>
            <ReviewCard icon={ClipboardCheck} title="Outcome" onEdit={() => setF((x) => ({ ...x, step: 1 }))}>
                <ReviewRow label="What happened" value={outcomeLabel} />
                {f.reason ? <ReviewRow label="Reason" value={[...WITHHELD_REASONS, ...AWAY_REASONS].find((r) => r.value === f.reason)?.label ?? f.reason} /> : null}
                {isPrn && f.prnReason ? <ReviewRow label="For" value={f.prnReason} /> : null}
                {givenLike ? <ReviewRow label="Amount given" value={<AmountReview f={f} amount={amount} />} /> : null}
                {givenLike && f.amtMode === 'prescriber' ? (
                    <ReviewRow
                        label="Prescriber’s instruction"
                        value={
                            <span>
                                {f.rx.who} by phone · {labelOf(localToMinutesToday(f.rx.when) ?? 0)} · read back ✓{f.rx.note ? ` · “${f.rx.note}”` : ''}{' '}
                                <StatusBadge variant="warning" size="sm">Waiting for a lead to countersign</StatusBadge>
                            </span>
                        }
                    />
                ) : null}
                {givenLike && f.amtMode === 'more' ? <ReviewRow label="When you save" value={`A medication error (severity: ${f.sev}) and one linked incident are created. Immediate action: ${f.imm}`} /> : null}
                <ReviewRow label="Time" value={`${givenLike ? `28 Sep 2026 · ${labelOf(whenMin ?? 0)}` : `28 Sep 2026 · ${NOW_LABEL}`} NZDT`} />
                {outsideWindow ? <ReviewRow label="Outside the window" value={f.lateReason} /> : null}
                {req.observationRules.map((r) => (
                    <ReviewRow key={r.id} label={r.obs!.label} value={`${f.obs[r.obs!.key]} ${r.obs!.unit}`} />
                ))}
                {secondKind && f.second.name ? (
                    <ReviewRow
                        label={{ witness: 'Witnessed by', countersign: 'Confirmed by', amount: 'Amount confirmed by' }[secondKind]}
                        value={
                            <span>
                                {f.second.name}{' '}
                                {f.second.forgot ? <StatusBadge variant="warning" size="sm">Not verified — PIN forgotten</StatusBadge> : <StatusBadge variant="success" size="sm">PIN checked when saved</StatusBadge>}
                            </span>
                        }
                    />
                ) : null}
                {countersignNobody ? <ReviewRow label="Second person" value={<span><StatusBadge variant="warning" size="sm">Not confirmed by a second person</StatusBadge> nobody else on shift (from the roster) · Jordan Tipene (house lead) gets a follow-up</span>} /> : null}
                {amountUnconfirmed ? <ReviewRow label="Second person" value={<span><StatusBadge variant="warning" size="sm">Not confirmed by a second person</StatusBadge> nobody else on shift · the house lead gets a follow-up</span>} /> : null}
                {givenLike && req.witness === 'override' ? <ReviewRow label="Witness" value={<span><StatusBadge variant="warning" size="sm">No witness — override</StatusBadge> by Rangi Parata: Nobody else on shift can witness (from the roster)</span>} /> : null}
                {f.outcome === 'refused' && !isPrn ? <ReviewRow label="Follow-up" value={`Owner ${selfName} · by ${labelOf(localToMinutesToday(f.followBy) ?? 0)}`} /> : null}
                {isPrn && f.checkBy ? <ReviewRow label="Check whether it helped" value={`Owner ${selfName} · by ${labelOf(localToMinutesToday(f.checkBy) ?? 0)}`} /> : null}
                {f.note ? <ReviewRow label="Note" value={f.note} /> : null}
            </ReviewCard>
            <ReviewCard icon={User} title="Signed as">
                <ReviewRow label="Name" value={`${selfName} · ${PERSONAS[ctx.persona].role}`} />
            </ReviewCard>
        </div>
    );

    /* ── footer ── */
    const blockedContinue =
        f.step === 0 && (isPrn ? !!req.blockAll : false)
            ? 'Can’t continue: as-needed limit reached'
            : f.step === 0 && entry === 'transport' && !f.packChecked
              ? 'Tick the pack check to continue'
              : f.step === 0 && orderChanged && !f.changeAck
                ? 'Confirm you’ve checked the new instructions'
                : f.step === 0 && req.blockAll && !isPrn
                  ? 'Nothing can be recorded right now'
                  : null;
    const footerStart =
        phase === 'duplicate' ? null : f.step > 0 && !(mode !== 'record' && f.step === 1) && phase !== 'sending' ? (
            <Button className="frontline-tap" type="button" variant="ghost" onClick={() => setF((x) => ({ ...x, step: x.step - 1 }))}>
                <ChevronLeft className="size-4" /> Back
            </Button>
        ) : (
            <Button className="frontline-tap" type="button" variant="outline" onClick={requestClose} disabled={phase === 'sending'}>
                Cancel
            </Button>
        );
    const footerEnd = (
        <div className="flex flex-wrap items-center justify-end gap-2">
            {blockedContinue ? (
                <span id="w-cant" className="text-caption">
                    {blockedContinue}
                </span>
            ) : null}
            {f.step > 0 && phase !== 'sending' && phase !== 'duplicate' && !(mode !== 'record' && f.step === 1) ? (
                <Button className="frontline-tap" type="button" variant="outline" onClick={requestClose}>
                    Cancel
                </Button>
            ) : null}
            {f.step < 2 ? (
                <Button className="frontline-tap" type="button" onClick={next} disabled={!!blockedContinue} aria-describedby={blockedContinue ? 'w-cant' : undefined}>
                    Continue <ChevronRight className="size-4" />
                </Button>
            ) : phase === 'duplicate' ? (
                <Button className="frontline-tap" type="button" onClick={onClose}>
                    Close
                </Button>
            ) : (
                <Button className="frontline-tap" type="button" onClick={save} disabled={phase === 'sending'}>
                    {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : phase === 'uncertain' || phase === 'rejected' ? <RefreshCw className="size-4" /> : <Check className="size-4" />}
                    {phase === 'sending' ? 'Sending…' : phase === 'uncertain' ? 'Try again' : 'Record outcome'}
                </Button>
            )}
        </div>
    );

    const railTitle = isPrn ? 'Record as-needed dose' : mode === 'reoffer' ? 'Record re-offer' : mode === 'notgiven' ? 'Record not given' : 'Record dose';
    const success =
        phase === 'done' && result ? (
            <WizardSuccessPane
                title={result.title}
                blurb={
                    <span className="block space-y-2">
                        {result.lines.map((l, i) => (
                            <span key={i} className={cn('block', i > 0 && 'font-medium text-status-warning')}>
                                {l}
                            </span>
                        ))}
                    </span>
                }
                actions={
                    <>
                        {onNext && nextLabel ? (
                            <Button className="frontline-tap" type="button" variant="outline" onClick={onNext}>
                                {nextLabel} <ChevronRight className="size-4" />
                            </Button>
                        ) : null}
                        <Button className="frontline-tap" type="button" onClick={onClose} autoFocus>
                            Done
                        </Button>
                    </>
                }
            />
        ) : undefined;

    // Keep the preview's scroll at the top of each step.
    useEffect(() => {
        bodyRef.current?.closest('[data-wizard-region="body"]')?.scrollTo?.(0, 0);
    }, [f.step]);

    return (
        <>
            <WizardShell
                open
                onClose={requestClose}
                onCloseAutoFocus={(e) => {
                    const el = returnFocus?.();
                    if (el) {
                        e.preventDefault();
                        el.focus();
                    }
                }}
                title={`${railTitle} — ${person.pref}, ${med}`}
                description="Safety checks, then what happened, then review and save."
                railIcon={Pill}
                railTitle={railTitle}
                railSub={`${person.pref} · ${isPrn ? 'As needed' : dose!.slot}`}
                steps={STEPS.map((x, i) => ({ ...x, disabled: i > f.step || (mode !== 'record' && i === 0) || phase === 'sending' }))}
                stepIndex={f.step}
                onStepClick={(i) => i <= f.step && setF((x) => ({ ...x, step: i }))}
                pct={pct}
                pctLabel="Completeness"
                railExtra={
                    <div className="space-y-2 text-[11.5px] text-muted-foreground">
                        <p>
                            <span className="font-semibold text-foreground">Opened from</span>
                            <br />
                            {ENTRY_LABEL[entry]}
                        </p>
                        <p>
                            <span className="font-semibold text-foreground">Signed as</span>
                            <br />
                            {selfName}
                            <br />
                            {PERSONAS[ctx.persona].role}
                        </p>
                    </div>
                }
                footerStart={footerStart}
                footerEnd={footerEnd}
                success={success}
                maxWidth="min(94vw, 1040px)"
                maxHeight="min(86vh, 780px)"
            >
                <WizardStepPane>
                    <div ref={bodyRef} className="space-y-4">
                        {f.step === 0 ? step0 : f.step === 1 ? step1 : step2}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard this record?"
                description="Nothing has been saved. What you entered for this dose will be lost, and the dose stays as not yet recorded."
                confirmText="Discard"
                cancelText="Keep recording"
            />
        </>
    );
}

/* ───────────── field helpers ───────────── */
function Field({ id, label, required, error, hint, children }: { id: string; label: string; required?: boolean; error?: string; hint?: string; children: ReactNode }) {
    return (
        <div data-field={id} className="space-y-1.5">
            <Label htmlFor={`w-${id}`} className="text-sm font-medium">
                {label} {required ? <span className="text-status-critical">*</span> : <span className="text-subtle">(optional)</span>}
            </Label>
            {children}
            {error ? <InputError message={error} /> : hint ? <p className="text-caption">{hint}</p> : null}
        </div>
    );
}
function NoteField({ f, set, errors, placeholder, required }: { f: Form; set: (p: Partial<Form>) => void; errors: Errors; placeholder: string; required?: boolean }) {
    return (
        <Field id="note" label={required ? 'What happened (goes into the medication error report)' : 'What happened'} required={required} error={errors.note} hint="Saved with this dose and shown in the chart history.">
            <Textarea id="w-note" rows={2} maxLength={1000} value={f.note} aria-invalid={!!errors.note} placeholder={placeholder} onChange={(e) => set({ note: e.target.value })} />
        </Field>
    );
}

/* ───────────── amount given (Stephan-approved, P00 v4/v5) ───────────── */
function Stepper({ value, onChange, amount, label, invalid }: { value: number | null; onChange: (n: number) => void; amount: Amount; label: string; invalid?: boolean }) {
    return (
        <div className="flex items-center gap-2">
            <Button type="button" variant="outline" size="icon" className="frontline-tap" aria-label={`Less ${label.toLowerCase()}`} onClick={() => onChange(Math.max(0, (value ?? 0) - amount.step))}>
                −
            </Button>
            <Input id="w-amt" inputMode="decimal" autoComplete="off" className="w-20 text-center" aria-invalid={invalid} aria-label={`${label}, in ${amount.plural}`} value={value == null ? '' : String(value)} onChange={(e) => onChange(Number(e.target.value.replace(/[^\d.]/g, '')) || 0)} />
            <Button type="button" variant="outline" size="icon" className="frontline-tap" aria-label={`More ${label.toLowerCase()}`} onClick={() => onChange((value ?? 0) + amount.step)}>
                +
            </Button>
            <span className="text-sm text-muted-foreground">{value != null && value <= 1 ? amount.unit : amount.plural} <span className="text-caption">(unit follows the order)</span></span>
        </div>
    );
}
function AmountField({
    f,
    set,
    amount,
    errors,
    secondKind,
    amountUnconfirmed,
    secondPerson,
}: {
    f: Form;
    set: (p: Partial<Form>) => void;
    amount: Amount;
    errors: Errors;
    secondKind: SecondKind | null;
    amountUnconfirmed: boolean;
    secondPerson: ReactNode;
}) {
    const links = (
        <div className="flex flex-wrap gap-2">
            <Button type="button" variant="outline" className="frontline-tap" onClick={() => set({ amtMode: 'less', amt: null, amtReason: '' })}>
                Record a different amount
            </Button>
            <Button type="button" variant="outline" className="frontline-tap" onClick={() => set({ amtMode: 'prescriber', amt: null })}>
                Prescriber asked for a different dose?
            </Button>
        </div>
    );
    const back = (label: string) => (
        <Button type="button" variant="outline" className="frontline-tap" onClick={() => set({ amtMode: 'asOrdered', amt: amount.range ? null : amount.n })}>
            {label}
        </Button>
    );
    if (f.amtMode === 'prescriber') {
        return (
            <section role="group" aria-labelledby="rx-l" className="space-y-3 rounded-xl border p-4">
                <p id="rx-l" className="flex items-center gap-2 text-sm font-semibold">
                    <FileText className="size-4" aria-hidden="true" /> Prescriber’s instruction for this dose
                </p>
                <p className="text-caption">A different dose needs the prescriber — a colleague’s PIN is not authority for it. Record what the prescriber told you by phone, then carry on recording.</p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field id="rxWho" label="Prescriber" required error={errors.rxWho} hint="The prescriber on the order is Dr Lena Chen.">
                        <Input id="w-rxWho" autoComplete="off" value={f.rx.who} aria-invalid={!!errors.rxWho} placeholder="e.g. Dr Lena Chen" onChange={(e) => set({ rx: { ...f.rx, who: e.target.value } })} />
                    </Field>
                    <Field id="amt" label="New dose" required error={errors.amt} hint={`${fmtAmount(f.amt, amount)} · the order is ${fmtAmount(amount.n, amount)}`}>
                        <Stepper value={f.amt} onChange={(n) => set({ amt: n })} amount={amount} label="New dose" invalid={!!errors.amt} />
                    </Field>
                </div>
                <div data-field="rxWhen">
                    <DateTimeField id="w-rx" label="Instruction given" value={f.rx.when} onChange={(v) => set({ rx: { ...f.rx, when: v } })} error={errors.rxWhen} hint="When the prescriber told you. The exact minute is kept." />
                </div>
                <div data-field="rxReadBack" className="space-y-1">
                    <div className="flex items-center gap-2">
                        <Checkbox id="w-rxReadBack" checked={f.rx.readBack} aria-invalid={!!errors.rxReadBack} onCheckedChange={(v) => set({ rx: { ...f.rx, readBack: v === true } })} />
                        <Label htmlFor="w-rxReadBack" className="text-sm font-normal">
                            I read the instruction back to the prescriber and they confirmed it <span className="text-status-critical">*</span>
                        </Label>
                    </div>
                    <InputError message={errors.rxReadBack} />
                </div>
                <Field id="rxNote" label="What the prescriber said">
                    <Textarea id="w-rxNote" rows={2} value={f.rx.note} placeholder="e.g. Give 2 tablets this morning only, then back to 1." onChange={(e) => set({ rx: { ...f.rx, note: e.target.value } })} />
                </Field>
                <Notice tone="info" icon={FileText} title="For this dose only — a lead countersigns it">
                    It doesn’t change the order. A lead who can check orders countersigns it by the end of the next day; until then the house lead has a follow-up. If the change is ongoing, the order is changed in Prescriptions.
                </Notice>
                <p className="text-caption">Organisation setting: support workers and leads can record this; a lead countersigns it (Settings › Medication rules).</p>
                {back('Back to the ordered amount')}
            </section>
        );
    }
    if (f.amtMode === 'more') {
        return (
            <section role="group" aria-labelledby="more-l" className="space-y-3 rounded-xl border border-status-critical/40 p-4">
                <p id="more-l" className="flex items-center gap-2 text-sm font-semibold text-status-critical">
                    <AlertOctagon className="size-4" aria-hidden="true" /> More than ordered was given
                </p>
                <Notice tone="critical" title="Only record this if it has already happened">
                    The chart will show what was really given. Saving also reports a medication error and creates one linked incident. A colleague’s PIN is not authority for a larger dose.
                </Notice>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field id="amt" label="Amount actually given" required error={errors.amt} hint={`The order is ${fmtAmount(amount.n, amount)}.`}>
                        <Stepper value={f.amt} onChange={(n) => set({ amt: n })} amount={amount} label="Amount actually given" invalid={!!errors.amt} />
                    </Field>
                    <Field id="sev" label="How serious does it seem?" required error={errors.sev} hint="Your first view — the house lead reviews it.">
                        <Select value={f.sev || undefined} onValueChange={(v) => set({ sev: v })}>
                            <SelectTrigger id="w-sev" aria-invalid={!!errors.sev} className="frontline-tap w-full">
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {['Minor', 'Moderate', 'Major', 'Critical'].map((x) => (
                                    <SelectItem key={x} value={x.toLowerCase()}>
                                        {x}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </div>
                <Field id="imm" label="What did you do straight away?" required error={errors.imm}>
                    <Textarea id="w-imm" rows={2} value={f.imm} aria-invalid={!!errors.imm} placeholder="e.g. Rang the on-call contact at 9:15 am. Staying with them." onChange={(e) => set({ imm: e.target.value })} />
                </Field>
                <div className="rounded-lg border bg-muted/40 p-3 text-sm">
                    <p className="font-medium">Now</p>
                    <ol className="mt-1 list-decimal space-y-0.5 pl-5">
                        <li>
                            Contact the prescriber or on-call contact: <NotConfigured />
                        </li>
                        <li>Stay with the person and watch closely. Note what you see.</li>
                        <li>The house lead and everyone rostered at Kōwhai House are told when you save, and see it until it’s resolved.</li>
                    </ol>
                </div>
                {back('Back to the ordered amount')}
            </section>
        );
    }
    if (f.amtMode === 'less') {
        const min = amount.range ? amount.range[0] : amount.n;
        return (
            <section role="group" aria-labelledby="less-l" className="space-y-3 rounded-xl border p-4">
                <p id="less-l" className="text-sm font-semibold">
                    Amount given — less than ordered
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field id="amt" label="Amount given" required error={errors.amt} hint={`${f.amt ? `${fmtAmount(f.amt, amount)} · ` : ''}The order is ${amount.range ? `${amount.range[0]}–${amount.range[1]} ${amount.plural}` : fmtAmount(min, amount)}.`}>
                        <Stepper value={f.amt} onChange={(n) => set({ amt: n })} amount={amount} label="Amount given" invalid={!!errors.amt} />
                    </Field>
                    <Field id="amtReason" label="Why was it less?" required error={errors.amtReason}>
                        <Select value={f.amtReason || undefined} onValueChange={(v) => set({ amtReason: v })}>
                            <SelectTrigger id="w-amtReason" aria-invalid={!!errors.amtReason} className="frontline-tap w-full">
                                <SelectValue placeholder="Choose a reason" />
                            </SelectTrigger>
                            <SelectContent>
                                {AMOUNT_REASONS.map((x) => (
                                    <SelectItem key={x} value={x}>
                                        {x}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                </div>
                {secondKind && secondKind !== 'amount' ? (
                    <p className="text-caption">The {secondKind === 'witness' ? 'witness' : 'person confirming the dose'} above also confirms the different amount.</p>
                ) : amountUnconfirmed ? (
                    <Notice tone="warning" icon={Users} title="Nobody else on shift to confirm the amount">
                        It will still be recorded, marked “Not confirmed by a second person”, and the house lead gets a follow-up — the house lead and everyone rostered at Kōwhai House see it until it’s resolved. This never stops you recording what happened.
                    </Notice>
                ) : (
                    <div className="space-y-2">
                        <p className="text-sm font-medium">A colleague on shift confirms the different amount</p>
                        {secondPerson}
                    </div>
                )}
                <div className="flex flex-wrap gap-2">
                    {back('Use the ordered amount')}
                    <Button type="button" variant="outline" className="frontline-tap" onClick={() => set({ amtMode: 'more', amt: null })}>
                        More than ordered was given
                    </Button>
                </div>
            </section>
        );
    }
    if (amount.range) {
        return (
            <Field id="amt" label="Amount given" required error={errors.amt} hint={`The order allows ${amount.range[0]} or ${amount.range[1]} ${amount.plural}. Nothing is chosen for you.`}>
                <Select value={f.amt == null ? undefined : String(f.amt)} onValueChange={(v) => set({ amt: Number(v) })}>
                    <SelectTrigger id="w-amt" aria-invalid={!!errors.amt} className="frontline-tap w-full max-w-xs">
                        <SelectValue placeholder="Choose the amount" />
                    </SelectTrigger>
                    <SelectContent>
                        {Array.from({ length: amount.range[1] - amount.range[0] + 1 }, (_, i) => amount.range![0] + i).map((n) => (
                            <SelectItem key={n} value={String(n)}>
                                {fmtAmount(n, amount)}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                {links}
            </Field>
        );
    }
    return (
        <div data-field="amt" className="space-y-1.5">
            <p id="amt-l" className="text-sm font-medium">
                Amount given
            </p>
            <div role="group" aria-labelledby="amt-l" className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/30 px-3 py-2 text-sm">
                <Check className="size-4 text-status-success" aria-hidden="true" />
                <strong>{fmtAmount(amount.n, amount)}</strong>
                <span className="text-subtle">as ordered · the unit follows the order</span>
            </div>
            {links}
        </div>
    );
}
function AmountReview({ f, amount }: { f: Form; amount: Amount }) {
    const tag =
        f.amtMode === 'less' ? (
            <StatusBadge variant="warning" size="sm">Less than ordered · {f.amtReason}</StatusBadge>
        ) : f.amtMode === 'more' ? (
            <StatusBadge variant="critical" size="sm">More than ordered — medication error</StatusBadge>
        ) : f.amtMode === 'prescriber' ? (
            <StatusBadge variant="warning" size="sm">Prescriber’s instruction</StatusBadge>
        ) : (
            <span className="text-subtle">{amount.range ? 'chosen within the order' : 'as ordered'}</span>
        );
    return (
        <span className="inline-flex flex-wrap items-center gap-2">
            {fmtAmount(f.amt, amount)} {tag}
        </span>
    );
}

/* ───────────── second person — witness PIN (Stephan option B) ───────────── */
function SecondPerson({
    kind,
    cands,
    value,
    onChange,
    nobody,
    errors,
    rule,
}: {
    kind: SecondKind;
    cands: Candidate[];
    value: Form['second'];
    onChange: (v: Form['second']) => void;
    nobody: boolean;
    errors: Errors;
    rule?: string;
}) {
    const [open, setOpen] = useState(false);
    const cd = kind === 'witness';
    const label = kind === 'witness' ? 'Witnessed by' : kind === 'amount' ? 'Confirmed by' : 'Confirmed by';
    if (nobody) {
        return kind === 'countersign' ? (
            <Notice tone="warning" icon={Users} title="Nobody else on shift can confirm this dose">
                The medication rule asks for a second person, but nobody else on shift at Kōwhai House has current competency and a witness PIN (from the roster). The dose will still be recorded, marked “Not confirmed by a second person”, and Jordan Tipene (house lead) gets a follow-up by the end of the next shift. The house lead and everyone rostered at Kōwhai House see it until it’s resolved. This never stops you recording what happened.
            </Notice>
        ) : null;
    }
    const fallbackAllowed = !cd;
    const chosen = cands.find((c) => c.staff.name === value.name);
    return (
        <section role="group" aria-labelledby="sp-l" className="space-y-3 rounded-xl border p-4">
            <p id="sp-l" className="flex items-center gap-2 text-sm font-semibold">
                <Users className="size-4" aria-hidden="true" />
                {kind === 'witness' ? 'Witness — a second person watches and confirms' : kind === 'countersign' ? 'Second person confirms this dose' : 'Second person confirms the amount'}
            </p>
            {rule ? <p className="text-caption">{rule}</p> : null}
            <div className="grid gap-3 sm:grid-cols-2">
                <div data-field="second" className="space-y-1.5">
                    <Label htmlFor="w-second" className="text-sm font-medium">
                        {label} <span className="text-status-critical">*</span>
                    </Label>
                    <Popover open={open} onOpenChange={setOpen}>
                        <PopoverTrigger asChild>
                            <Button id="w-second" type="button" variant="outline" role="combobox" aria-expanded={open} aria-invalid={!!errors.second} className="frontline-tap w-full justify-between font-normal">
                                <span className="flex items-center gap-2 truncate">
                                    <Search className="size-4 text-muted-foreground" />
                                    {value.name ?? 'Choose a colleague on shift'}
                                </span>
                                <ChevronDown className="size-4 opacity-60" />
                            </Button>
                        </PopoverTrigger>
                        <PopoverContent className="w-[360px] p-0" align="start">
                            <Command>
                                <CommandInput placeholder="Search colleagues on shift…" />
                                <CommandList>
                                    <CommandEmpty>No colleague on shift matches.</CommandEmpty>
                                    <CommandGroup heading="On shift at Kōwhai House now">
                                        {cands.map((c) => {
                                            const selectable = c.ok || (value.forgot && c.fallbackOk);
                                            return (
                                                <CommandItem
                                                    key={c.staff.id}
                                                    value={c.staff.name}
                                                    disabled={!selectable}
                                                    onSelect={() => {
                                                        onChange({ ...value, name: c.staff.name, pin: '' });
                                                        setOpen(false);
                                                    }}
                                                    className="items-start"
                                                >
                                                    <span className="mt-0.5 flex size-6 shrink-0 items-center justify-center rounded-full bg-primary/15 text-[10px] font-semibold text-primary">{c.staff.initials}</span>
                                                    <span className="min-w-0">
                                                        <span className="block text-sm font-medium">{c.staff.name}</span>
                                                        <span className="block text-xs text-muted-foreground">
                                                            {c.staff.role} · {c.ok ? c.why : `${c.why}${!c.ok && c.fallbackOk && value.forgot ? ' — the forgotten-PIN fallback applies' : ' — can’t be chosen'}`}
                                                        </span>
                                                    </span>
                                                    {value.name === c.staff.name ? <Check className="ml-auto size-4" /> : null}
                                                </CommandItem>
                                            );
                                        })}
                                    </CommandGroup>
                                </CommandList>
                                <p className="border-t px-3 py-2 text-xs text-muted-foreground">Only colleagues clocked in at Kōwhai House now are listed. {cd ? 'A witness also needs controlled-medicine witness competency.' : ''}</p>
                            </Command>
                        </PopoverContent>
                    </Popover>
                    <InputError message={errors.second} />
                </div>
                {!value.forgot ? (
                    <div data-field="pin" className="space-y-1.5">
                        <Label htmlFor="w-pin" className="text-sm font-medium">
                            {cd ? 'Witness’s' : 'Their'} 6-digit PIN <span className="text-status-critical">*</span>
                        </Label>
                        <Input id="w-pin" type="password" inputMode="numeric" maxLength={6} autoComplete="off" value={value.pin} aria-invalid={!!errors.pin} aria-describedby="w-pin-h" onChange={(e) => onChange({ ...value, pin: e.target.value.replace(/\D/g, '').slice(0, 6) })} />
                        {errors.pin ? <InputError message={errors.pin} /> : <p id="w-pin-h" className="text-caption">Their own witness PIN — not their login password. They type it here{cd ? ', at the medicine cupboard' : ''}.</p>}
                    </div>
                ) : null}
            </div>
            <div className="space-y-1">
                <div className="flex items-center gap-2">
                    <Checkbox id="w-forgot" disabled={!fallbackAllowed} checked={value.forgot} onCheckedChange={(v) => onChange({ ...value, forgot: v === true, pin: '', name: v === true ? value.name : chosen?.ok ? value.name : null })} aria-describedby="w-forgot-h" />
                    <Label htmlFor="w-forgot" className={cn('text-sm font-normal', !fallbackAllowed && 'text-muted-foreground')}>
                        They’ve forgotten their PIN
                    </Label>
                </div>
                <p id="w-forgot-h" className="text-caption">
                    {fallbackAllowed ? 'Switches to naming them from the list. The dose is then marked “second person not verified”.' : 'Not allowed for controlled drugs (your organisation’s setting, Settings › Second-person confirmation).'}
                </p>
            </div>
            {value.forgot ? (
                <Notice tone="warning" title="Second person not verified — PIN forgotten">
                    This dose will be marked as not verified. <strong>{value.name ?? 'The colleague'}</strong> gets an item in their own login to confirm “I was there” or “I wasn’t there” within {PIN_RULES.confirmWithinMin} minutes (by 9:42 am). If they answer “I wasn’t there”, or don’t answer in time, the house lead gets a follow-up. They’ll also be asked to reset their PIN.
                </Notice>
            ) : null}
            <DesignNote title="Mockup">PIN 000000 shows a wrong PIN; 999999 shows a locked PIN; any other 6 digits succeed. PINs are checked when you save (PIN-1 builds the check).</DesignNote>
        </section>
    );
}

/* ───────────── MAR one-click "Mark given" — same contract, no second UI ───────────── */
export function useQuickGive() {
    const s = useStore();
    return (d: Dose): { ok: true } | { ok: false; reasons: string[] } => {
        const r = requirementsFor(d, s.ctx);
        const st = s.stateOf(d);
        if (r.notSimple.length || st !== 'due') return { ok: false, reasons: r.notSimple.length ? r.notSimple : ['Already has an outcome'] };
        s.setSending(d.id, true);
        window.setTimeout(() => {
            s.setSending(d.id, false);
            if (s.ctx.scenario === 'offline') {
                s.setFlag(d.id, 'queued');
                s.toast('warning', `Saved on this device — ${d.med} for ${PEOPLE[d.pid].pref} isn’t on the chart yet. It will send when you reconnect.`);
                return;
            }
            if (s.ctx.scenario === 'reject') {
                s.setFlag(d.id, 'rejected');
                s.toast('critical', `Not recorded — ${d.med} for ${PEOPLE[d.pid].pref} was not saved. Your competency record changed at 9:10 am. Open the dose to review.`);
                return;
            }
            s.setRecord(d.id, { outcome: 'given', at: NOW_LABEL, by: PERSONAS[s.ctx.persona].name, line: `Given ${NOW_LABEL} · ${PERSONAS[s.ctx.persona].short} (Mark given on the MAR)`, amount: fmtAmount(d.amount.n, d.amount) });
            s.toast('success', `Recorded — ${d.med} for ${PEOPLE[d.pid].pref}: given at ${NOW_LABEL}, ${fmtAmount(d.amount.n, d.amount)} as ordered.`);
        }, 900);
        return { ok: true };
    };
}
