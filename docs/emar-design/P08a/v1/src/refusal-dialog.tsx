/* “Follow up a refusal” — P08a’s redesign of RefusalFollowUpDialog, which was
 * deleted with the dead code (7a9a3285e, 29 Sep) while its backend stayed:
 * POST /emar/refusal-followups, …/complete, …/notify-gp (RefusalFollowUpController).
 * Main, Q3: short by default (Outcome → Review); the fuller step — why,
 * capacity, something else offered, GP and whānau told, what happens next —
 * appears only when the 3-in-7-days escalation is reached or the person refuses
 * again. Real WizardShell, ReviewCard/ReviewRow, WizardSuccessPane, TilePicker,
 * Select, Switch, Textarea, Input, DateTimeField, ConfirmDialog. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Check, ChevronLeft, ChevronRight, ClipboardList, Clock3, HeartHandshake, Loader2, Repeat, SkipForward, Undo2, UserCheck, X } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { NOW_LABEL, NOW_LOCAL, NOW_MIN, labelOf, localToMinutes } from './clock';
import { PEOPLE, PERSONAS } from './data';
import { finish, NotFoundDialog } from './modal';
import { SHIFT_END_MIN, canAct, endOfShiftError } from './model';
import { useStore } from './store';
import { KV, Notice, TilePicker } from './ui';

/* The backend’s nine reason values and four capacity values, in plain words. */
const REASONS: [string, string][] = [
    ['personal_choice', 'Their choice — didn’t want it'],
    ['side_effects', 'Side effects'],
    ['difficulty_swallowing', 'Hard to swallow'],
    ['nausea', 'Feeling sick'],
    ['pain', 'In pain'],
    ['cognitive', 'Confused or unsure what it’s for'],
    ['behavioural', 'Upset or distressed'],
    ['sleeping', 'Asleep or drowsy'],
    ['other', 'Other'],
];
const CAPACITY = [
    { key: 'has_capacity', label: 'Understood the choice', description: 'Knew what the medicine is for', icon: UserCheck },
    { key: 'lacks_capacity', label: 'Didn’t seem to understand', description: 'Not able to weigh it up then', icon: X },
    { key: 'fluctuating', label: 'It varies', description: 'Some days yes, some days no', icon: Repeat },
    { key: 'not_assessed', label: 'Not sure', description: 'Not assessed', icon: Clock3 },
];
const OUTCOMES = [
    { key: 'taken', label: 'Offered again — taken', description: 'Recorded as “Given after re-offer”', icon: Check },
    { key: 'again', label: 'Offered again — refused again', description: 'A few more questions follow', icon: Undo2 },
    { key: 'couldnt', label: 'Couldn’t offer yet', description: 'Asleep, out or busy', icon: Clock3 },
    { key: 'notneeded', label: 'Not needed now', description: 'Next dose is soon, or advice says skip it', icon: SkipForward },
];
const COULDNT = ['Asleep', 'Out of the house', 'Staff were supporting someone else', 'Other'];
const NOT_NEEDED = ['The next dose is due soon', 'The prescriber or GP said to skip it', 'The person is away', 'Other'];

export function RefusalDialog({ id, onClose, returnFocus }: { id: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const row = s.row(id);
    const [step, setStep] = useState(0);
    const [f, setF] = useState({ outcome: '', said: '', reason: '', again: `${NOW_LOCAL.slice(0, 11)}10:30`, notNeeded: '', note: '', why: '', capacity: '', alt: false, altText: '', gp: false, gpText: '', whanau: false, whanauText: '', next: '' });
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const bodyRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        bodyRef.current?.closest('[data-wizard-region="body"]')?.scrollTo?.(0, 0);
    }, [step]);
    if (!row || row.f.type !== 'reoffer' || !row.f.refusal) return <NotFoundDialog onClose={onClose} />;
    const fu = row.f;
    const r = fu.refusal!;
    const p = PEOPLE[fu.pid!];
    const act = canAct(fu, s.route.persona);
    const escalated = r.count7 >= 3;
    const full = escalated || f.outcome === 'again';
    const steps = [
        { key: 'outcome', label: 'What happened', blurb: 'Offered again, or why not', icon: HeartHandshake },
        ...(full ? [{ key: 'details', label: 'Why, and who was told', blurb: escalated ? '3 refusals in 7 days' : 'A second refusal', icon: ClipboardList }] : []),
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const set = (patch: Partial<typeof f>) => (setF((x) => ({ ...x, ...patch })), setE({}));
    const dirty = !!(f.outcome || f.note || f.why);

    function validate(k: string): Record<string, string> {
        const x: Record<string, string> = {};
        if (k === 'outcome') {
            if (!f.outcome) x['rf-outcome'] = 'Choose what happened.';
            if (f.outcome === 'couldnt') {
                if (!f.reason) x['rf-reason'] = 'Choose why you couldn’t offer it.';
                const t = localToMinutes(f.again);
                if (t == null) x['rf-again'] = 'Enter a time.';
                else if (t <= NOW_MIN) x['rf-again'] = `Choose a time after now (${NOW_LABEL}).`;
                else if (t > SHIFT_END_MIN) x['rf-again'] = endOfShiftError;
            }
            if (f.outcome === 'notneeded') {
                if (!f.notNeeded) x['rf-notneeded'] = 'Choose why it isn’t needed now.';
                if (!f.note.trim()) x['rf-note'] = 'Add a short note — who decided, or what the advice was.';
            }
        }
        if (k === 'details') {
            if (!f.why) x['rf-why'] = `Choose why you think ${p.pref} refused.`;
            if (!f.capacity) x['rf-capacity'] = `Choose whether ${p.pref} understood the choice.`;
            if (f.alt && !f.altText.trim()) x['rf-alttext'] = 'Say what else was offered.';
            if (f.gp && !f.gpText.trim()) x['rf-gptext'] = 'Say who you spoke to, when, and what they said.';
            if (f.whanau && !f.whanauText.trim()) x['rf-whanautext'] = 'Say who was told.';
            if (!f.next.trim()) x['rf-next'] = 'Say what happens next.';
        }
        return x;
    }
    function next() {
        const x = validate(cur);
        setE(x);
        if (Object.keys(x).length) return window.setTimeout(() => document.getElementById(Object.keys(x)[0])?.focus(), 50);
        setStep(step + 1);
    }
    function save() {
        setPhase('sending');
        window.setTimeout(() => {
            if (f.outcome === 'couldnt') {
                const t = localToMinutes(f.again)!;
                s.update(fu.id, { couldnt: { at: NOW_LABEL, by: PERSONAS[s.route.persona].name, reason: f.reason, again: labelOf(t), againMin: t } });
            } else finish(s, fu, OUTCOMES.find((o) => o.key === f.outcome)!.label + (f.note.trim() ? ` — ${f.note.trim()}` : ''));
            setPhase('done');
        }, 800);
    }
    const tile = (key: string) => OUTCOMES.find((o) => o.key === key)?.label ?? '—';
    const body: Record<string, ReactNode> = {
        outcome: (
            <>
                {escalated ? (
                    <Notice tone="warning" title={`${r.count7} refusals in 7 days — the house lead and clinical lead have been told`}>
                        {r.history.join(' · ')}. The repeated-refusals rule (3 in 7 days, Settings › Rounds &amp; timing) was reached at {r.at}.
                    </Notice>
                ) : null}
                {!act.close ? <Notice tone="neutral" title="You can see this, but not record it">{act.why}</Notice> : null}
                <KV
                    rows={[
                        ['Refused', `${r.at} · ${r.med} · recorded by ${r.by} · “${r.said}”`],
                        ['Offer again by', `${fu.due} · owner ${row.owner ?? 'set when the handover is acknowledged'}`],
                    ]}
                />
                <div className="space-y-2" data-field="rf-outcome">
                    <Label id="rf-outcome-l">
                        What happened? <span className="text-status-critical">*</span>
                    </Label>
                    <div id="rf-outcome" tabIndex={-1}>
                        <TilePicker labelledBy="rf-outcome-l" value={f.outcome || null} invalid={!!e['rf-outcome']} onChange={(k) => set({ outcome: k })} tiles={OUTCOMES} />
                    </div>
                    <InputError message={e['rf-outcome']} />
                </div>
                {f.outcome === 'taken' ? (
                    <Notice tone="info" title="Recorded in the dose dialog">
                        When you save, the recording dialog opens at “Given after re-offer”, linked to this refusal (P01’s approved dialog — outside this preview). This follow-up closes when that’s saved.
                    </Notice>
                ) : null}
                {f.outcome === 'again' ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="rf-said">
                            What did {p.pref} say or do? <span className="text-subtle">(optional)</span>
                        </Label>
                        <Textarea id="rf-said" rows={2} value={f.said} placeholder={`e.g. ${p.pref} pushed the cup away and said “not today”.`} onChange={(ev) => set({ said: ev.target.value })} />
                    </div>
                ) : null}
                {f.outcome === 'couldnt' ? (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="rf-reason">
                                Why not? <span className="text-status-critical">*</span>
                            </Label>
                            <Select value={f.reason || undefined} onValueChange={(v) => set({ reason: v })}>
                                <SelectTrigger id="rf-reason" className="w-full" aria-invalid={!!e['rf-reason']}>
                                    <SelectValue placeholder="Choose" />
                                </SelectTrigger>
                                <SelectContent>
                                    {COULDNT.map((x) => (
                                        <SelectItem key={x} value={x}>
                                            {x}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={e['rf-reason']} />
                        </div>
                        <div className="sm:col-span-2">
                            <DateTimeField id="rf-again" label="Try again at" value={f.again} onChange={(v) => set({ again: v })} error={e['rf-again']} hint="Up to the end of your shift (3:00 pm). If it’s still not done, it carries over to the next shift." />
                        </div>
                    </div>
                ) : null}
                {f.outcome === 'notneeded' ? (
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="space-y-1.5">
                            <Label htmlFor="rf-notneeded">
                                Why isn’t it needed now? <span className="text-status-critical">*</span>
                            </Label>
                            <Select value={f.notNeeded || undefined} onValueChange={(v) => set({ notNeeded: v })}>
                                <SelectTrigger id="rf-notneeded" className="w-full" aria-invalid={!!e['rf-notneeded']}>
                                    <SelectValue placeholder="Choose" />
                                </SelectTrigger>
                                <SelectContent>
                                    {NOT_NEEDED.map((x) => (
                                        <SelectItem key={x} value={x}>
                                            {x}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                            <InputError message={e['rf-notneeded']} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="rf-note">
                                Note <span className="text-status-critical">*</span>
                            </Label>
                            <Textarea id="rf-note" rows={2} value={f.note} aria-invalid={!!e['rf-note']} placeholder="e.g. Next dose is at 12:00 pm — Jordan agreed to skip this one." onChange={(ev) => set({ note: ev.target.value })} />
                            <InputError message={e['rf-note']} />
                        </div>
                    </div>
                ) : null}
            </>
        ),
        details: (
            <>
                <Notice tone={escalated ? 'warning' : 'info'} title={escalated ? `${r.count7} refusals in 7 days` : `${p.pref} refused again`}>
                    {escalated ? 'The house lead and clinical lead have been told. If the GP or prescriber needs to know, say so below — or the house lead records it later.' : 'A second refusal of the same dose asks a few more questions, so the house lead can see the pattern.'}
                </Notice>
                <div className="space-y-1.5">
                    <Label htmlFor="rf-why">
                        Why do you think {p.pref} refused? <span className="text-status-critical">*</span>
                    </Label>
                    <Select value={f.why || undefined} onValueChange={(v) => set({ why: v })}>
                        <SelectTrigger id="rf-why" className="w-full sm:w-1/2" aria-invalid={!!e['rf-why']}>
                            <SelectValue placeholder="Choose" />
                        </SelectTrigger>
                        <SelectContent>
                            {REASONS.map(([v, l]) => (
                                <SelectItem key={v} value={v}>
                                    {l}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={e['rf-why']} />
                </div>
                <div className="space-y-2">
                    <Label id="rf-capacity-l">
                        Did {p.pref} understand the choice at the time? <span className="text-status-critical">*</span>
                    </Label>
                    <div id="rf-capacity" tabIndex={-1}>
                        <TilePicker labelledBy="rf-capacity-l" value={f.capacity || null} invalid={!!e['rf-capacity']} onChange={(k) => set({ capacity: k })} tiles={CAPACITY} />
                    </div>
                    <InputError message={e['rf-capacity']} />
                </div>
                {(
                    [
                        ['alt', 'altText', 'Something else was offered', 'e.g. The liquid form, or taking it with food', 'What was offered?', Input],
                        ['gp', 'gpText', 'The GP or prescriber was told', 'e.g. Dr Lena Chen’s nurse, 9:40 am — keep offering, review Friday', 'Who, when, and what they said', Textarea],
                        ['whanau', 'whanauText', 'Whānau or family were told', 'e.g. Grace’s daughter, Mei, by phone', 'Who was told', Input],
                    ] as const
                ).map(([k, tk, label, ph, sub, Comp]) => (
                    <div key={k} className="space-y-2 rounded-xl border p-3">
                        <div className="flex items-center justify-between gap-4">
                            <Label htmlFor={`rf-${k}`} className="text-sm font-medium">
                                {label}
                            </Label>
                            <div className="flex items-center gap-2">
                                <span className="text-caption">{f[k] ? 'On' : 'Off'}</span>
                                <Switch id={`rf-${k}`} checked={f[k]} onCheckedChange={(v) => set({ [k]: v } as Partial<typeof f>)} />
                            </div>
                        </div>
                        {f[k] ? (
                            <div className="space-y-1.5">
                                <Label htmlFor={`rf-${k}text`} className="text-caption">
                                    {sub} <span className="text-status-critical">*</span>
                                </Label>
                                <Comp id={`rf-${k}text`} value={f[tk]} placeholder={ph} aria-invalid={!!e[`rf-${k}text`]} onChange={(ev: { target: { value: string } }) => set({ [tk]: ev.target.value } as Partial<typeof f>)} />
                                <InputError message={e[`rf-${k}text`]} />
                            </div>
                        ) : null}
                    </div>
                ))}
                <div className="space-y-1.5">
                    <Label htmlFor="rf-next">
                        What happens next? <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea id="rf-next" rows={2} value={f.next} aria-invalid={!!e['rf-next']} placeholder="e.g. Offer again with lunch; Jordan to raise it at Friday’s review." onChange={(ev) => set({ next: ev.target.value })} />
                    <InputError message={e['rf-next']} />
                </div>
            </>
        ),
        review: (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={HeartHandshake} title="What happened" onEdit={() => setStep(0)} span={!full}>
                    <ReviewRow label="Outcome" value={tile(f.outcome)} />
                    {f.outcome === 'couldnt' ? <ReviewRow label="Try again at" value={`${labelOf(localToMinutes(f.again) ?? NOW_MIN)} · ${f.reason.toLowerCase()}`} /> : null}
                    {f.outcome === 'notneeded' ? <ReviewRow label="Why" value={`${f.notNeeded} — ${f.note}`} /> : null}
                    {f.said ? <ReviewRow label={`${p.pref} said`} value={f.said} /> : null}
                </ReviewCard>
                {full ? (
                    <ReviewCard icon={ClipboardList} title="Why, and who was told" onEdit={() => setStep(1)}>
                        <ReviewRow label="Why" value={REASONS.find(([v]) => v === f.why)?.[1]} />
                        <ReviewRow label="Understood the choice" value={CAPACITY.find((c) => c.key === f.capacity)?.label} />
                        <ReviewRow label="Something else offered" value={f.alt ? f.altText : 'No'} />
                        <ReviewRow label="GP or prescriber told" value={f.gp ? f.gpText : 'No'} />
                        <ReviewRow label="Whānau told" value={f.whanau ? f.whanauText : 'No'} />
                        <ReviewRow label="What happens next" value={f.next} />
                    </ReviewCard>
                ) : null}
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you save">
                        {f.outcome === 'couldnt'
                            ? `The follow-up stays open. Try again at ${labelOf(localToMinutes(f.again) ?? NOW_MIN)}; if it isn’t done by 3:00 pm it carries over to the next shift.`
                            : f.outcome === 'taken'
                              ? 'The follow-up closes, and the recording dialog opens at “Given after re-offer”.'
                              : full
                                ? 'The follow-up closes. Jordan Tipene, the house lead, sees these details with the refusal pattern in Safety & oversight.'
                                : 'The follow-up closes. It stays on the refusal record and in Done today.'}
                    </Notice>
                </div>
            </div>
        ),
    };
    const success =
        phase === 'done' ? (
            <WizardSuccessPane
                title={f.outcome === 'couldnt' ? 'Saved — try again later' : 'Follow-up done'}
                blurb={
                    f.outcome === 'couldnt'
                        ? `Try again at ${labelOf(localToMinutes(f.again) ?? NOW_MIN)}. If it isn’t done by 3:00 pm it carries over to the next shift.`
                        : f.outcome === 'taken'
                          ? 'The recording dialog opens next at “Given after re-offer”, linked to this refusal.'
                          : `Saved at ${NOW_LABEL}.${full ? ' The house lead sees the details with the refusal pattern.' : ''}`
                }
                actions={
                    <Button type="button" onClick={onClose} autoFocus>
                        Done
                    </Button>
                }
            />
        ) : undefined;
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={(ev) => {
                    const el = returnFocus?.();
                    if (el) {
                        ev.preventDefault();
                        el.focus();
                    }
                }}
                title={`Follow up a refusal — ${p.pref}, ${r.med}`}
                description="What happened, then review and save."
                railIcon={HeartHandshake}
                railTitle="Follow up a refusal"
                railSub={`${p.pref} · refused ${r.at}`}
                steps={steps.map((x, i) => ({ ...x, disabled: i > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(i) => i <= step && setStep(i)}
                pct={Math.round(((step + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
                pctLabel="Completeness"
                railExtra={
                    <div className="space-y-2 text-[11.5px] text-muted-foreground">
                        <p>
                            <span className="font-semibold text-foreground">Owner</span>
                            <br />
                            {row.owner ?? 'Set when the handover is acknowledged'}
                        </p>
                        <p>
                            <span className="font-semibold text-foreground">Offer again by</span>
                            <br />
                            {fu.due}
                        </p>
                    </div>
                }
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
                    !act.close ? (
                        <span className="text-caption">You can see this, but not record it.</span>
                    ) : cur !== 'review' ? (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" onClick={save} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                            {phase === 'sending' ? 'Sending…' : 'Save follow-up'}
                        </Button>
                    )
                }
                success={success}
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(86vh, 780px)"
            >
                <WizardStepPane>
                    <div ref={bodyRef} className="space-y-4">
                        {body[cur]}
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
                title="Discard this follow-up?"
                description={`Nothing has been saved. The follow-up stays open and due by ${fu.due}.`}
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}
