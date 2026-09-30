/* P03’s dialogs — the redesigned AssessmentWizardDialog (self-admin),
 * SignAgreementDialog, MedScopeDialog and ViewSelfAdminDialog, plus “record a
 * change the person asked for” (consent changed, Q5). Real WizardShell,
 * ReviewCard/ReviewRow, WizardSuccessPane, Dialog in the Fleet Settings Modal
 * layout, ConfirmDialog, ToggleGroup, Checkbox, Select, Input, Textarea,
 * FileDropzone + StagedFileCard and the PKG-01 DateTimeField. */
import ConfirmDialog from '@/components/confirm-dialog';
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
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Building2, Check, ChevronLeft, ChevronRight, ClipboardList, FileSignature, Hand, Loader2, MessageSquare, MessageSquareText, PenLine, Pill, Store, User, UserCheck, Users } from 'lucide-react';
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { NOW_LABEL, NOW_LOCAL, TODAY } from './clock';
import {
    ASSESSMENTS,
    CHECKS,
    INVOLVED,
    MEDICINES,
    OUTCOME_CAP,
    PEOPLE,
    PERSONAS,
    SCORES,
    SCORE_WORDS,
    STORAGE,
    SUPPORT,
    SUPPORT_ORDER,
    medsOf,
    type Agreement,
    type Assessment,
    type CheckKey,
    type ScoreKey,
    type Support,
    type PersonId,
} from './data';
import { Modal } from './modal';
import { agreementOf, allowedFor, assessmentOf, capOf, computeOutcome, concealed, moreIndependent, needsAgreement, supportOf, triggersOf, type Runtime } from './model';
import { useStore } from './store';
import { KV, Notice, SupportChip, TilePicker } from './ui';

const INTERVALS: { v: 3 | 6 | 12; by: string }[] = [
    { v: 3, by: '28 December 2026' },
    { v: 6, by: '28 March 2027' },
    { v: 12, by: '28 September 2027' },
];
const STAFF_ON_SHIFT = ['Priya Shah', 'Daniel Ahn', 'Mere Kahu', 'Jordan Tipene'];
const focusFirst = (x: Record<string, string>) => window.setTimeout(() => document.getElementById(Object.keys(x)[0])?.focus(), 50);
const restore = (rf?: () => HTMLElement | null) => (ev: Event) => {
    const el = rf?.();
    if (el) {
        ev.preventDefault();
        el.focus();
    }
};
const cantSaveOffline = 'You’re offline, so this can’t be saved yet. Nothing is lost — keep this open and save when you reconnect.';

/* ═════════════ Assess or reassess support (AssessmentWizardDialog, redesigned) ═════════════ */
export function AssessDialog({ pid, onClose, returnFocus }: { pid: PersonId; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const p = PEOPLE[pid];
    const prev = assessmentOf(pid, s.rt);
    const reassess = !!prev;
    const trig = triggersOf(pid, s.rt);
    const meds = medsOf(pid);
    const visible = meds.filter((m) => !concealed(m, s.route.persona));
    const hiddenMeds = meds.length - visible.length;
    const [step, setStep] = useState(0);
    const [f, setF] = useState(() => ({
        wishes: prev ? (prev.wishes ? 'yes' : 'no') : '',
        with: prev ? [...prev.with] : ([] as string[]),
        guardian: pid === 'grace' ? 'Wei Liu' : '',
        scores: (prev ? { ...prev.scores } : { cognitive: 0, dexterity: 0, vision: 0, swallowing: 0, understanding: 0 }) as Record<ScoreKey, number>,
        checks: (prev ? { ...prev.checks } : { identify: false, labels: false, packaging: false, timing: false, storage: false, willing: false }) as Record<CheckKey, boolean>,
        support: Object.fromEntries(visible.map((m) => [m.key, supportOf(m, s.rt) ?? 'administer'])) as Record<string, Support>,
        storage: prev ? (STORAGE.find((x) => prev.storage.toLowerCase().includes(x.label.toLowerCase().slice(0, 12)))?.key ?? 'office') : '',
        interval: (prev?.intervalMonths ?? 12) as 3 | 6 | 12,
        notes: '',
    }));
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [offlineErr, setOfflineErr] = useState(false);
    const [savedChanged, setSavedChanged] = useState(0);
    const bodyRef = useRef<HTMLDivElement>(null);
    useEffect(() => {
        bodyRef.current?.closest('[data-wizard-region="body"]')?.scrollTo?.(0, 0);
    }, [step]);
    const total = Object.values(f.scores).reduce((n, v) => n + v, 0);
    const outcome = computeOutcome(f.wishes === 'yes', f.checks.willing, total);
    const cap = OUTCOME_CAP[outcome];
    // Anything more independent than the new result is lowered to it (Q2).
    const effective = (key: string) => {
        const m = meds.find((x) => x.key === key)!;
        const allowed = allowedFor(m, cap);
        return allowed.includes(f.support[key]) ? f.support[key] : allowed[0];
    };
    const changed = visible.filter((m) => effective(m.key) !== (supportOf(m, s.rt) ?? 'administer') || supportOf(m, s.rt) === null);
    const loosened = changed.filter((m) => moreIndependent(effective(m.key), supportOf(m, s.rt) ?? 'administer'));
    const interval = INTERVALS.find((x) => x.v === f.interval)!;
    const agreement = agreementOf(pid, s.rt);
    const willNeedAgreement = visible.some((m) => ['selfmanaged', 'prompt'].includes(effective(m.key))) || meds.some((m) => concealed(m, s.route.persona) && ['selfmanaged', 'prompt'].includes(supportOf(m, s.rt) ?? 'administer'));
    const steps = [
        { key: 'person', label: `${p.pref} and who took part`, blurb: 'Their wishes', icon: User },
        { key: 'ability', label: 'What they can do', blurb: 'Five questions, six checks', icon: ClipboardList },
        { key: 'support', label: 'Support for each medicine', blurb: `Up to ${f.wishes ? SUPPORT[cap].label : '…'}`, icon: Pill },
        { key: 'storage', label: 'Storage and next review', blurb: 'Where it’s kept, when to look again', icon: Store },
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const set = (patch: Partial<typeof f>) => (setF((x) => ({ ...x, ...patch })), setE({}));
    const dirty = step > 0 || f.wishes !== (prev ? (prev.wishes ? 'yes' : 'no') : '');
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'person') {
            if (!f.wishes) x['as-wishes'] = `Choose whether ${p.pref} wants to manage any medicines.`;
            if (!f.with.includes('The person') && !f.with.includes('Welfare guardian or EPOA')) x['as-with'] = `Choose who took part — ${p.pref}, or someone who can speak for them.`;
            if (f.with.includes('Welfare guardian or EPOA') && !f.guardian.trim()) x['as-guardian'] = 'Enter their name.';
        }
        if (k === 'ability') {
            const missing = SCORES.find((q) => !f.scores[q.key]);
            if (missing) x[`as-score-${missing.key}`] = `Answer “${missing.label}”.`;
        }
        if (k === 'storage' && !f.storage) x['as-storage'] = 'Choose where the medicines are kept.';
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
        setSavedChanged(changed.length);
        setPhase('sending');
        window.setTimeout(() => {
            const a: Assessment = {
                id: `as-${pid}-new`,
                pid,
                assessed: '28 September 2026',
                assessedIso: TODAY,
                by: me.name,
                with: f.with.map((w) => (w === 'Welfare guardian or EPOA' ? `${f.guardian} (welfare guardian or EPOA)` : w)),
                wishes: f.wishes === 'yes',
                scores: f.scores,
                checks: f.checks,
                outcome,
                storage: STORAGE.find((x) => x.key === f.storage)!.label,
                intervalMonths: f.interval,
                reassessBy: interval.by,
                reassessIso: '2027-01-01',
                notes: f.notes,
                supersedes: prev?.id,
            };
            s.update((rt: Runtime) => ({
                ...rt,
                assessments: { ...rt.assessments, [pid]: a },
                support: { ...rt.support, ...Object.fromEntries(visible.map((m) => [m.key, effective(m.key)])) },
                closedTriggers: [...rt.closedTriggers, ...trig.map((t) => t.id)],
                events: [
                    ...changed.map((m, i) => ({ id: `ev-${Date.now()}-${i}`, pid, at: `Mon 28 Sep, ${NOW_LABEL}`, what: `Support changed — ${m.name}`, who: me.name, before: supportOf(m, rt) ? SUPPORT[supportOf(m, rt)!].label : 'Not set', after: SUPPORT[effective(m.key)].label })),
                    { id: `ev-${Date.now()}-a`, pid, at: `Mon 28 Sep, ${NOW_LABEL}`, what: `${reassess ? 'Reassessment' : 'Assessment'} completed — most independence allowed: ${SUPPORT[cap].label}`, who: me.name, after: `Reassess by ${interval.by}` },
                    ...rt.events,
                ],
            }));
            setPhase('done');
        }, 800);
    }
    const scoreRow = (q: (typeof SCORES)[number]) => (
        <div key={q.key} className="flex flex-wrap items-center justify-between gap-3 rounded-lg border px-3 py-2">
            <div className="min-w-0">
                <p id={`as-score-${q.key}-l`} className="text-sm font-medium">
                    {q.label} <span className="text-status-critical">*</span>
                </p>
                <p className="text-caption">{q.help}</p>
            </div>
            <div className="flex flex-col items-end gap-1">
                <ToggleGroup
                    id={`as-score-${q.key}`}
                    type="single"
                    variant="outline"
                    size="sm"
                    aria-labelledby={`as-score-${q.key}-l`}
                    aria-invalid={!!e[`as-score-${q.key}`] || undefined}
                    value={f.scores[q.key] ? String(f.scores[q.key]) : ''}
                    onValueChange={(v) => v && set({ scores: { ...f.scores, [q.key]: Number(v) } })}
                >
                    {[1, 2, 3, 4, 5].map((n) => (
                        <ToggleGroupItem key={n} value={String(n)} aria-label={`${n} — ${SCORE_WORDS[n]}`} className="frontline-tap">
                            {n}
                        </ToggleGroupItem>
                    ))}
                </ToggleGroup>
                <span className="text-caption">{f.scores[q.key] ? SCORE_WORDS[f.scores[q.key]] : 'Not answered'}</span>
            </div>
        </div>
    );
    const body: Record<string, ReactNode> = {
        person: (
            <>
                {trig.length ? (
                    <Notice tone="critical" title={`Why now: ${trig.map((t) => t.detail.split(' (')[0].replace(/\.$/, '')).join(' · ')}`}>
                        The reassessment follow-up is due by {trig[0].due} ({trig[0].owner}). Saving closes it.
                    </Notice>
                ) : prev && prev.reassessIso < TODAY ? (
                    <Notice tone="warning" title={`The review date passed on ${prev.reassessBy}`}>Support stayed as it was until today. Answers start from the last assessment — change what’s different now.</Notice>
                ) : null}
                <KV
                    rows={[
                        ['Person', `${p.legal} (“${p.pref}”) · ${p.age} years · NHI ${p.nhi} (test)`],
                        ['Assessed by', `${me.name} · today, Monday 28 September 2026`],
                        [reassess ? 'Last assessment' : 'Before this', reassess ? `${prev!.assessed} by ${prev!.by} — answers below start from it` : 'No assessment — staff give every medicine'],
                    ]}
                />
                <div className="space-y-2">
                    <Label id="as-wishes-l">
                        Does {p.pref} want to manage any of their medicines? <span className="text-status-critical">*</span>
                    </Label>
                    <div id="as-wishes" tabIndex={-1}>
                        <TilePicker
                            labelledBy="as-wishes-l"
                            value={f.wishes || null}
                            invalid={!!e['as-wishes']}
                            onChange={(k) => set({ wishes: k })}
                            tiles={[
                                { key: 'yes', label: 'Yes, some or all', description: 'Continue — the answers set how much', icon: UserCheck },
                                { key: 'no', label: 'No — staff to give them', description: 'Every medicine will be Administer', icon: Hand },
                            ]}
                        />
                    </div>
                    <InputError message={e['as-wishes']} />
                </div>
                <div className="space-y-2">
                    <Label id="as-with-l">
                        Who took part? <span className="text-status-critical">*</span>
                    </Label>
                    <div id="as-with" tabIndex={-1} role="group" aria-labelledby="as-with-l" className="grid gap-2 sm:grid-cols-3">
                        {INVOLVED.map((w) => (
                            <label key={w} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                                <Checkbox checked={f.with.includes(w)} onCheckedChange={(c) => set({ with: c ? [...f.with, w] : f.with.filter((x) => x !== w) })} />
                                {w}
                            </label>
                        ))}
                    </div>
                    <InputError message={e['as-with']} />
                </div>
                {f.with.includes('Welfare guardian or EPOA') ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="as-guardian">
                            Their name <span className="text-status-critical">*</span>
                        </Label>
                        <Input id="as-guardian" value={f.guardian} aria-invalid={!!e['as-guardian']} onChange={(ev) => set({ guardian: ev.target.value })} />
                        <InputError message={e['as-guardian']} />
                    </div>
                ) : null}
            </>
        ),
        ability: (
            <>
                <p className="text-sm text-muted-foreground">1 means not at all, 5 means fully. These are today’s questions, in plain words.</p>
                <div className="space-y-2">{SCORES.map(scoreRow)}</div>
                <div className="space-y-2">
                    <Label id="as-checks-l">Everyday checks — tick what {p.pref} can do now</Label>
                    <div role="group" aria-labelledby="as-checks-l" className="grid gap-2 sm:grid-cols-2">
                        {CHECKS.map((c) => (
                            <label key={c.key} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                                <Checkbox checked={f.checks[c.key]} onCheckedChange={(v) => set({ checks: { ...f.checks, [c.key]: !!v } })} />
                                {c.label}
                            </label>
                        ))}
                    </div>
                </div>
                {!f.checks.willing && f.wishes === 'yes' ? <Notice tone="warning" title="Not ticked: wants to do it and is engaged">Without it, every medicine will be Administer, whatever the answers.</Notice> : null}
            </>
        ),
        support: (
            <>
                <Notice tone="info" icon={ClipboardList} title={`Most independence allowed: ${SUPPORT[cap].label}`}>
                    {f.wishes === 'no' ? `${p.pref} prefers staff to give their medicines.` : !f.checks.willing ? 'Not engaged right now.' : `From the answers (${total} of 25).`} Each medicine can be set at or below it. Controlled medicines are Assist or Administer at most.
                </Notice>
                <ul className="divide-y rounded-lg border">
                    {visible.map((m) => {
                        const allowed = allowedFor(m, cap);
                        const val = effective(m.key);
                        const before = supportOf(m, s.rt);
                        const lowered = allowed.includes(f.support[m.key]) ? null : f.support[m.key];
                        return (
                            <li key={m.key} className="flex flex-wrap items-center justify-between gap-3 px-3 py-2.5">
                                <div className="min-w-0">
                                    <p id={`as-sup-${m.key}-l`} className="text-sm font-medium">
                                        {m.name} {m.strength}
                                    </p>
                                    <p className="text-caption">
                                        {m.prn ? 'As needed' : m.when} · now {before ? SUPPORT[before].label : 'not set'}
                                        {lowered ? ` · lowered to ${SUPPORT[val].label} — the result allows up to ${SUPPORT[cap].label}` : ''}
                                        {m.cd ? ' · controlled: Assist or Administer' : ''}
                                    </p>
                                </div>
                                <ToggleGroup type="single" variant="outline" size="sm" aria-labelledby={`as-sup-${m.key}-l`} value={val} onValueChange={(v) => v && set({ support: { ...f.support, [m.key]: v as Support } })}>
                                    {SUPPORT_ORDER.map((k) => (
                                        <ToggleGroupItem key={k} value={k} disabled={!allowed.includes(k)} className="frontline-tap px-2.5 text-[12.5px]">
                                            {SUPPORT[k].label}
                                        </ToggleGroupItem>
                                    ))}
                                </ToggleGroup>
                            </li>
                        );
                    })}
                </ul>
                {hiddenMeds ? (
                    <p className="text-caption">
                        {hiddenMeds} controlled {hiddenMeds === 1 ? 'medicine keeps its' : 'medicines keep their'} support — details need controlled-medicine access. Someone with it can change {hiddenMeds === 1 ? 'it' : 'them'}.
                    </p>
                ) : null}
            </>
        ),
        storage: (
            <>
                <div className="space-y-1.5">
                    <Label htmlFor="as-storage">
                        Where are {p.pref}’s medicines kept? <span className="text-status-critical">*</span>
                    </Label>
                    <Select value={f.storage || undefined} onValueChange={(v) => set({ storage: v })}>
                        <SelectTrigger id="as-storage" className="w-full" aria-invalid={!!e['as-storage']}>
                            <SelectValue placeholder="Choose" />
                        </SelectTrigger>
                        <SelectContent>
                            {STORAGE.map((x) => (
                                <SelectItem key={x.key} value={x.key}>
                                    {x.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={e['as-storage']} />
                </div>
                <div className="space-y-2">
                    <Label id="as-interval-l">Reassess in</Label>
                    <ToggleGroup type="single" variant="outline" aria-labelledby="as-interval-l" value={String(f.interval)} onValueChange={(v) => v && set({ interval: Number(v) as 3 | 6 | 12 })}>
                        {INTERVALS.map((x) => (
                            <ToggleGroupItem key={x.v} value={String(x.v)} className="frontline-tap px-3">
                                {x.v} months
                            </ToggleGroupItem>
                        ))}
                    </ToggleGroup>
                    <p className="text-caption">Reassess by {interval.by}. Sooner if {p.pref} comes back from hospital, has a medication error or incident, gets a new or changed order for a medicine they manage or take with a prompt, refuses or misses doses 3 times in 7 days, asks, or a lead notices a change — each starts a follow-up for the house lead.</p>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="as-notes">
                        Anything else <span className="text-subtle">(optional)</span>
                    </Label>
                    <Textarea id="as-notes" rows={3} value={f.notes} placeholder={`e.g. ${p.pref} would like to try their morning tablets with a prompt at the next review.`} onChange={(ev) => set({ notes: ev.target.value })} />
                </div>
            </>
        ),
        review: (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={User} title={`${p.pref} and who took part`} onEdit={() => setStep(0)}>
                    <ReviewRow label="Wants to manage medicines" value={f.wishes === 'yes' ? 'Yes, some or all' : 'No — staff to give them'} />
                    <ReviewRow label="Took part" value={f.with.map((w) => (w === 'Welfare guardian or EPOA' ? `${f.guardian} (welfare guardian or EPOA)` : w)).join(', ')} />
                </ReviewCard>
                <ReviewCard icon={ClipboardList} title="What they can do" onEdit={() => setStep(1)}>
                    <ReviewRow label="Answers" value={`${total} of 25`} />
                    <ReviewRow label="Checks ticked" value={`${Object.values(f.checks).filter(Boolean).length} of 6`} />
                    <ReviewRow label="Most independence allowed" value={SUPPORT[cap].label} />
                </ReviewCard>
                <ReviewCard icon={Pill} title="Support for each medicine" onEdit={() => setStep(2)}>
                    {changed.length ? (
                        changed.map((m) => (
                            <ReviewRow
                                key={m.key}
                                label={m.name}
                                value={
                                    <span className="flex flex-wrap items-center justify-end gap-1.5">
                                        {supportOf(m, s.rt) ? SUPPORT[supportOf(m, s.rt)!].label : 'Not set'} → {SUPPORT[effective(m.key)].label}
                                        {loosened.includes(m) ? <StatusBadge variant="critical" className="rounded-[8px]">Loosens staff support</StatusBadge> : null}
                                    </span>
                                }
                            />
                        ))
                    ) : (
                        <ReviewRow label="Changes" value="None — support stays as it is" />
                    )}
                    {visible.length - changed.length && changed.length ? <ReviewRow label="Unchanged" value={`${visible.length - changed.length} ${visible.length - changed.length === 1 ? 'medicine' : 'medicines'}`} /> : null}
                </ReviewCard>
                <ReviewCard icon={Store} title="Storage and next review" onEdit={() => setStep(3)}>
                    <ReviewRow label="Kept" value={STORAGE.find((x) => x.key === f.storage)?.label ?? '—'} />
                    <ReviewRow label="Reassess by" value={interval.by} />
                    {f.notes ? <ReviewRow label="Notes" value={f.notes} /> : null}
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone={loosened.length ? 'warning' : 'neutral'} title="When you save">
                        {changed.length ? `Meds today and the chart use the new support straight away${loosened.length ? ` — ${loosened.length === 1 ? 'one medicine gets' : `${loosened.length} medicines get`} less staff support` : ''}. ` : 'Support stays as it is. '}
                        {willNeedAgreement ? (agreement ? `The agreement of ${agreement.on} carries over — record a new one if what’s agreed has changed. ` : `An agreement is needed — you can record it next. `) : ''}
                        {trig.length ? `The reassessment follow-up for ${trig[0].owner} closes. ` : ''}
                        {prev ? `The assessment of ${prev.assessed} is kept.` : ''}
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
    const success =
        phase === 'done' ? (
            <WizardSuccessPane
                title={reassess ? 'Reassessment saved' : 'Assessment saved'}
                blurb={`Most independence allowed: ${SUPPORT[cap].label}. ${savedChanged ? `${savedChanged} ${savedChanged === 1 ? 'medicine’s' : 'medicines’'} support changed — Meds today shows it now.` : 'Support is unchanged.'} Reassess by ${interval.by}.`}
                actions={
                    <>
                        {willNeedAgreement && !agreement ? (
                            <Button type="button" autoFocus onClick={() => s.set({ open: `agreement:${pid}` })}>
                                <FileSignature className="size-4" /> Record the agreement
                            </Button>
                        ) : null}
                        <Button type="button" variant={willNeedAgreement && !agreement ? 'outline' : 'default'} autoFocus={!(willNeedAgreement && !agreement)} onClick={onClose}>
                            Done
                        </Button>
                    </>
                }
            />
        ) : undefined;
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={`${reassess ? 'Reassess' : 'Assess'} ${p.pref}’s support`}
                description="Wishes, what they can do, then support for each medicine."
                railIcon={ClipboardList}
                railTitle={reassess ? 'Reassess support' : 'Self-administration assessment'}
                railSub={`${p.pref} · ${PEOPLE[pid].house === 'kowhai' ? 'Kōwhai House' : 'Rimu House'}`}
                steps={steps.map((x, i) => ({ ...x, disabled: i > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(i) => i <= step && setStep(i)}
                pct={Math.round(((step + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
                pctLabel="Completeness"
                railExtra={
                    <div className="space-y-2 text-[11.5px] text-muted-foreground">
                        <p>
                            <span className="font-semibold text-foreground">Most independence allowed</span>
                            <br />
                            {f.wishes ? SUPPORT[cap].label : 'Answer the questions first'}
                        </p>
                        {prev ? (
                            <p>
                                <span className="font-semibold text-foreground">Last assessment</span>
                                <br />
                                {prev.assessed} · {SUPPORT[OUTCOME_CAP[prev.outcome]].label}
                            </p>
                        ) : null}
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
                    cur !== 'review' ? (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" onClick={save} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                            {phase === 'sending' ? 'Saving…' : reassess ? 'Save reassessment' : 'Save assessment'}
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
                title={`Discard this ${reassess ? 'reassessment' : 'assessment'}?`}
                description={`Nothing has been saved. ${p.pref}’s support stays as it is${trig.length ? `, and the reassessment is still due by ${trig[0].due}` : ''}.`}
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

/* ═════════════ Record the agreement (SignAgreementDialog, redesigned — Q3) ═════════════ */
export function AgreementDialog({ pid, onClose, returnFocus }: { pid: PersonId; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const p = PEOPLE[pid];
    const prev = agreementOf(pid, s.rt);
    const a = assessmentOf(pid, s.rt);
    const [step, setStep] = useState(0);
    const [f, setF] = useState({
        role: prev?.role ?? (pid === 'grace' ? 'guardian' : 'person'),
        name: prev && prev.role !== 'person' ? prev.agreedBy.replace(/ \(.*\)$/, '') : '',
        how: '' as '' | 'signed' | 'verbal',
        witness: '',
        file: null as File | null,
        ordering: prev?.ordering ?? ('' as '' | Agreement['ordering']),
        personDoes: prev?.personDoes ?? '',
        staffDo: prev?.staffDo ?? '',
    });
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [offlineErr, setOfflineErr] = useState(false);
    const set = (patch: Partial<typeof f>) => (setF((x) => ({ ...x, ...patch })), setE({}));
    const dirty = !!(f.how || f.name || f.file || (!prev && (f.personDoes || f.staffDo)));
    const steps = [
        { key: 'who', label: 'Who agreed, and how', blurb: `${p.pref} or someone for them`, icon: User },
        { key: 'terms', label: 'What’s agreed', blurb: 'What each side does', icon: FileSignature },
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    const agreedBy = f.role === 'person' ? p.legal : `${f.name.trim()} (${f.role === 'guardian' ? 'welfare guardian' : 'EPOA'})`;
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'who') {
            if (f.role !== 'person' && !f.name.trim()) x['ag-name'] = 'Enter their name.';
            if (!f.how) x['ag-how'] = 'Choose how they agreed.';
            if (f.how === 'signed' && !f.file) x['ag-file'] = 'Add the signed form.';
            if (f.how === 'verbal' && !f.witness) x['ag-witness'] = 'Choose who witnessed it.';
        }
        if (k === 'terms') {
            if (!f.ordering) x['ag-ordering'] = 'Choose who orders the medicines.';
            if (!f.personDoes.trim()) x['ag-person'] = `Say what ${f.role === 'person' ? p.pref : 'the person'} does.`;
            if (!f.staffDo.trim()) x['ag-staff'] = 'Say what staff do.';
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
            const ag: Agreement = { pid, agreedBy, role: f.role as Agreement['role'], how: f.how as Agreement['how'], witness: f.witness || undefined, file: f.file?.name, staff: me.name, on: '28 September 2026', ordering: f.ordering as Agreement['ordering'], personDoes: f.personDoes, staffDo: f.staffDo };
            s.update((rt) => ({ ...rt, agreements: { ...rt.agreements, [pid]: ag }, events: [{ id: `ev-${Date.now()}`, pid, at: `Mon 28 Sep, ${NOW_LABEL}`, what: `Agreement recorded — ${agreedBy}`, who: me.name, before: prev ? `Agreement of ${prev.on}` : undefined, after: f.how === 'signed' ? 'Signed form attached' : `Agreed verbally · witness ${f.witness}` }, ...rt.events] }));
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        who: (
            <>
                {prev ? <Notice tone="neutral" title={`Replaces the agreement of ${prev.on}`}>That one is kept in Changes.</Notice> : null}
                <div className="space-y-2">
                    <Label id="ag-role-l">Who agreed?</Label>
                    <TilePicker
                        labelledBy="ag-role-l"
                        value={f.role}
                        onChange={(k) => set({ role: k as Agreement['role'] })}
                        tiles={[
                            { key: 'person', label: p.pref, description: 'The person themselves', icon: User },
                            { key: 'guardian', label: 'Welfare guardian', description: 'Appointed by the Family Court', icon: Users },
                            { key: 'epoa', label: 'EPOA (personal care and welfare)', description: 'An activated enduring power of attorney', icon: Users },
                        ]}
                    />
                </div>
                {f.role !== 'person' ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="ag-name">
                            Their name <span className="text-status-critical">*</span>
                        </Label>
                        <Input id="ag-name" value={f.name} aria-invalid={!!e['ag-name']} onChange={(ev) => set({ name: ev.target.value })} />
                        <InputError message={e['ag-name']} />
                    </div>
                ) : null}
                <div className="space-y-2">
                    <Label id="ag-how-l">
                        How did they agree? <span className="text-status-critical">*</span>
                    </Label>
                    <div id="ag-how" tabIndex={-1}>
                        <TilePicker
                            labelledBy="ag-how-l"
                            value={f.how || null}
                            invalid={!!e['ag-how']}
                            onChange={(k) => set({ how: k as 'signed' | 'verbal' })}
                            tiles={[
                                { key: 'signed', label: 'Signed the form', description: 'Attach the signed form', icon: PenLine },
                                { key: 'verbal', label: 'Agreed out loud', description: 'Another staff member witnessed it', icon: MessageSquare },
                            ]}
                        />
                    </div>
                    <InputError message={e['ag-how']} />
                </div>
                {f.how === 'signed' ? (
                    <div className="space-y-1.5">
                        <Label id="ag-file-l">
                            The signed form <span className="text-status-critical">*</span>
                        </Label>
                        {f.file ? (
                            <StagedFileCard file={f.file} onRemove={() => set({ file: null })} />
                        ) : (
                            <FileDropzone id="ag-file" aria-labelledby="ag-file-l" aria-invalid={!!e['ag-file'] || undefined} multiple={false} accept=".pdf,image/*" hint="PDF or a photo of the signed page" onFiles={(files) => set({ file: files[0] ?? null })} />
                        )}
                        <InputError message={e['ag-file']} />
                    </div>
                ) : null}
                {f.how === 'verbal' ? (
                    <div className="space-y-1.5">
                        <Label htmlFor="ag-witness">
                            Witnessed by <span className="text-status-critical">*</span>
                        </Label>
                        <Select value={f.witness || undefined} onValueChange={(v) => set({ witness: v })}>
                            <SelectTrigger id="ag-witness" className="w-full" aria-invalid={!!e['ag-witness']}>
                                <SelectValue placeholder="Choose someone who was there" />
                            </SelectTrigger>
                            <SelectContent>
                                {STAFF_ON_SHIFT.filter((n) => n !== me.name).map((n) => (
                                    <SelectItem key={n} value={n}>
                                        {n}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-caption">Not you — you’re recording it.</p>
                        <InputError message={e['ag-witness']} />
                    </div>
                ) : null}
            </>
        ),
        terms: (
            <>
                <KV rows={[['Support now', medsOf(pid).filter((m) => !concealed(m, s.route.persona)).map((m) => `${m.name}: ${SUPPORT[supportOf(m, s.rt) ?? 'administer'].label}`).join(' · ')], ['Kept', a?.storage ?? 'Not recorded']]} />
                <div className="space-y-2">
                    <Label id="ag-ordering-l">
                        Who orders the medicines? <span className="text-status-critical">*</span>
                    </Label>
                    <div id="ag-ordering" tabIndex={-1}>
                        <TilePicker
                            labelledBy="ag-ordering-l"
                            value={f.ordering || null}
                            invalid={!!e['ag-ordering']}
                            onChange={(k) => set({ ordering: k as Agreement['ordering'] })}
                            tiles={[
                                { key: 'person', label: `${p.pref} orders their own`, description: 'From their GP or pharmacy', icon: User },
                                { key: 'service', label: 'The service orders', description: 'Staff reorder as usual', icon: Building2 },
                                { key: 'pharmacy', label: 'The pharmacy supplies automatically', description: 'Repeat supply without a request', icon: Store },
                            ]}
                        />
                    </div>
                    <InputError message={e['ag-ordering']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="ag-person">
                        What {p.pref} does <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea id="ag-person" rows={3} value={f.personDoes} aria-invalid={!!e['ag-person']} placeholder="e.g. Takes cetirizine each morning. Keeps it in the locked drawer. Tells staff when a pack is nearly empty." onChange={(ev) => set({ personDoes: ev.target.value })} />
                    <InputError message={e['ag-person']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="ag-staff">
                        What staff do <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea id="ag-staff" rows={3} value={f.staffDo} aria-invalid={!!e['ag-staff']} placeholder="e.g. Remind at bedtime. Check the drawer supply on Sundays." onChange={(ev) => set({ staffDo: ev.target.value })} />
                    <InputError message={e['ag-staff']} />
                </div>
            </>
        ),
        review: (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={User} title="Who agreed, and how" onEdit={() => setStep(0)}>
                    <ReviewRow label="Agreed by" value={agreedBy} />
                    <ReviewRow label="How" value={f.how === 'signed' ? `Signed form · ${f.file?.name ?? ''}` : `Out loud · witnessed by ${f.witness}`} />
                    <ReviewRow label="Recorded by" value={`${me.name} · today`} />
                </ReviewCard>
                <ReviewCard icon={FileSignature} title="What’s agreed" onEdit={() => setStep(1)}>
                    <ReviewRow label="Ordering" value={{ person: `${p.pref} orders their own`, service: 'The service orders', pharmacy: 'The pharmacy supplies automatically', '': '—' }[f.ordering]} />
                    <ReviewRow label={`What ${p.pref} does`} value={f.personDoes} />
                    <ReviewRow label="What staff do" value={f.staffDo} />
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you save">
                        This becomes {p.pref}’s agreement{prev ? `; the one of ${prev.on} is kept in Changes` : ''}. It carries over when support is reassessed, unless what’s agreed changes.
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
                title={`Record ${p.pref}’s agreement`}
                description="Who agreed, how, and what each side does."
                railIcon={FileSignature}
                railTitle="Self-administration agreement"
                railSub={`${p.pref} · ${a ? `most independence allowed: ${SUPPORT[capOf(pid, s.rt)].label}` : 'no assessment'}`}
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
                            {phase === 'sending' ? 'Saving…' : 'Save agreement'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title="Agreement saved"
                            blurb={`Agreed by ${agreedBy}. It shows on ${p.pref}’s Support plan › Agreement.`}
                            actions={
                                <Button type="button" autoFocus onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 900px)"
                maxHeight="min(86vh, 760px)"
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
                title="Discard this agreement?"
                description={`Nothing has been saved.${prev ? ` The agreement of ${prev.on} stays.` : ''}`}
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

/* ═════════════ Set or change support for one medicine (MedScopeDialog, redesigned) ═════════════ */
const SUPPORT_TILE_ICON: Record<Support, typeof Pill> = { selfmanaged: UserCheck, prompt: MessageSquare, assist: Hand, administer: Hand };
export function SupportDialog({ medKey, onClose, returnFocus }: { medKey: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const m = MEDICINES.find((x) => x.key === medKey)!;
    const p = PEOPLE[m.pid];
    const now = supportOf(m, s.rt);
    const a = assessmentOf(m.pid, s.rt);
    const cap = capOf(m.pid, s.rt);
    // Outside a reassessment: set a new medicine within the cap, or give more staff support. More independence needs a reassessment (Q5, P02).
    const allowed = allowedFor(m, cap).filter((k) => now === null || !moreIndependent(k, now));
    const [choice, setChoice] = useState<Support | null>(now);
    const [why, setWhy] = useState('');
    const [e, setE] = useState<Record<string, string>>({});
    const [confirm, setConfirm] = useState(false);
    const loosens = !!choice && !!now && moreIndependent(choice, now);
    const needAgreement = !!choice && ['selfmanaged', 'prompt'].includes(choice) && !agreementOf(m.pid, s.rt);
    const reasonFor = (k: Support) => (allowed.includes(k) ? null : m.cd && ['selfmanaged', 'prompt'].includes(k) ? 'Controlled medicines are Assist or Administer' : !a ? 'Needs an assessment first' : now !== null && moreIndependent(k, now) && allowedFor(m, cap).includes(k) ? 'More independence needs a reassessment' : `The assessment allows up to ${SUPPORT[cap].label}`);
    function commit() {
        s.update((rt) => ({ ...rt, support: { ...rt.support, [m.key]: choice! }, events: [{ id: `ev-${Date.now()}`, pid: m.pid, at: `Mon 28 Sep, ${NOW_LABEL}`, what: `Support ${now ? 'changed' : 'set'} — ${m.name}`, who: me.name, before: now ? SUPPORT[now].label : 'Not set', after: `${SUPPORT[choice!].label} · “${why.trim()}”` }, ...rt.events] }));
        s.toast('success', `${m.name} is now ${SUPPORT[choice!].label} for ${p.pref}. Meds today shows it from now.${needAgreement ? ' Record the agreement next.' : ''}`);
        onClose();
    }
    function save() {
        const x: Record<string, string> = {};
        if (!choice) x['sp-choice'] = 'Choose the support.';
        else if (choice === now && now !== null) x['sp-choice'] = 'That’s the support now — choose more staff support, or cancel.';
        if (!why.trim()) x['sp-why'] = 'Say why it’s changing.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'sp-choice': cantSaveOffline });
        if (loosens) return setConfirm(true);
        commit();
    }
    return (
        <>
            <Modal
                width={720}
                title={`${now ? 'Give more staff support' : 'Set support'} — ${m.name}`}
                description={`${p.pref} · ${m.strength} · ${m.prn ? 'as needed' : m.when}`}
                onClose={onClose}
                onCloseAutoFocus={restore(returnFocus)}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button onClick={save}>{now ? 'Save' : 'Set support'}</Button>
                    </>
                }
            >
                <KV
                    rows={[
                        ['Support now', now ? `${SUPPORT[now].label} — ${SUPPORT[now].desc.toLowerCase()}` : m.newOrder ? `Not set — new order ${m.newOrder}; staff give it` : 'Not set — staff give it'],
                        ['Most independence allowed', a ? `${SUPPORT[cap].label} (assessment of ${a.assessed})` : 'Administer — no assessment yet'],
                    ]}
                />
                <div className="space-y-2">
                    <Label id="sp-choice-l">
                        Support <span className="text-status-critical">*</span>
                    </Label>
                    <div id="sp-choice" tabIndex={-1}>
                        <TilePicker labelledBy="sp-choice-l" value={choice} invalid={!!e['sp-choice']} onChange={(k) => (setChoice(k as Support), setE({}))} tiles={SUPPORT_ORDER.map((k) => ({ key: k, label: SUPPORT[k].label, description: `${SUPPORT[k].desc} · ${SUPPORT[k].recorded.toLowerCase()}`, icon: SUPPORT_TILE_ICON[k], disabled: reasonFor(k) }))} />
                    </div>
                    <InputError message={e['sp-choice']} />
                </div>
                {needAgreement ? <Notice tone="warning" icon={FileSignature} title="An agreement will be needed">{p.pref} would keep or take this medicine themselves. Record who agreed and what each side does after saving.</Notice> : null}
                <div className="space-y-1.5">
                    <Label htmlFor="sp-why">
                        Why is it changing? <span className="text-status-critical">*</span>
                    </Label>
                    <Textarea id="sp-why" rows={2} value={why} aria-invalid={!!e['sp-why']} placeholder={now ? `e.g. ${p.pref} has found it harder this week — staff to help for now.` : 'e.g. Takes it with breakfast when reminded.'} onChange={(ev) => (setWhy(ev.target.value), setE({}))} />
                    <InputError message={e['sp-why']} />
                </div>
                <p className="text-caption">{now ? 'Here you can give more staff support at any time. More independence needs a reassessment. ' : ''}Changes show in Meds today and on the chart straight away, and are kept in Support plan › Changes.</p>
            </Modal>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    commit();
                }}
                title="Loosens staff support"
                description={`${m.name} goes from ${now ? SUPPORT[now].label : '—'} to ${choice ? SUPPORT[choice].label : '—'}: ${choice ? SUPPORT[choice].desc.toLowerCase() : ''}. ${choice === 'selfmanaged' ? 'Staff stop recording each dose.' : 'Staff do less for each dose.'}`}
                confirmText="Loosen support"
                cancelText="Keep it as it is"
            />
        </>
    );
}

/* ═════════════ Record a change the person asked for (consent changed — Q5) ═════════════ */
export function ConsentDialog({ pid, medKey, onClose, returnFocus }: { pid: PersonId; medKey?: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const p = PEOPLE[pid];
    const meds = medsOf(pid).filter((m) => !concealed(m, s.route.persona));
    const [med, setMed] = useState(medKey ?? '');
    const [dir, setDir] = useState<'' | 'less' | 'more'>('');
    const [said, setSaid] = useState('');
    const [when, setWhen] = useState(NOW_LOCAL);
    const [e, setE] = useState<Record<string, string>>({});
    const m = meds.find((x) => x.key === med);
    const now = m ? (supportOf(m, s.rt) ?? 'administer') : null;
    const effect =
        !m || !dir
            ? null
            : dir === 'less'
              ? now === 'administer'
                  ? `${m.name} is already Administer. The request is kept, and Jordan Tipene gets a reassessment follow-up due by Monday 5 October.`
                  : `${m.name} moves from ${SUPPORT[now!].label} to Administer straight away. Jordan Tipene gets a reassessment follow-up due by Monday 5 October.`
              : `${m.name} stays ${SUPPORT[now!].label} until a reassessment. Jordan Tipene gets a follow-up due by Monday 5 October.`;
    function save() {
        const x: Record<string, string> = {};
        if (!med) x['cc-med'] = 'Choose the medicine.';
        if (!dir) x['cc-dir'] = `Choose what ${p.pref} asked for.`;
        if (!said.trim()) x['cc-said'] = `Write what ${p.pref} said, in their words if you can.`;
        if (!when) x['cc-when'] = 'Enter when.';
        else if (when > NOW_LOCAL) x['cc-when'] = 'Choose a time before now (9:12 am).';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        const id = `cc-${Date.now()}`;
        const offline = s.route.scenario === 'offline';
        s.update((rt) => ({
            ...rt,
            support: dir === 'less' ? { ...rt.support, [med]: 'administer' } : rt.support,
            consent: [{ id, pid, at: 'Today, 9:12 am', med, direction: dir as 'less' | 'more', said: `“${said.trim()}”`, recordedBy: me.name, effect: dir === 'less' ? (now === 'administer' ? 'Already Administer' : `Moved from ${SUPPORT[now!].label} to Administer straight away`) : `Stays ${SUPPORT[now!].label} until the reassessment` }, ...rt.consent],
            queued: offline ? [...rt.queued, id] : rt.queued,
            events: [{ id: `ev-${id}`, pid, at: `Mon 28 Sep, ${NOW_LABEL}`, what: `${dir === 'less' ? 'Support changed at' : 'Request recorded —'} ${p.pref}’s request — ${m!.name}`, who: me.name, before: dir === 'less' && now !== 'administer' ? SUPPORT[now!].label : undefined, after: dir === 'less' ? 'Administer' : 'Reassessment due by 5 Oct' }, ...rt.events],
        }));
        s.toast(offline ? 'warning' : 'success', offline ? `Saved on this device — sends when you reconnect. Until then other staff still see ${m!.name} as ${SUPPORT[now!].label}.` : dir === 'less' && now !== 'administer' ? `${m!.name} is now Administer for ${p.pref}. The house lead has a reassessment follow-up.` : `Recorded. The house lead has a reassessment follow-up for ${p.pref}.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Record a change ${p.pref} asked for`}
            description="When someone no longer wants to manage a medicine, or wants to do more themselves."
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
            <div className="space-y-1.5">
                <Label htmlFor="cc-med">
                    Medicine <span className="text-status-critical">*</span>
                </Label>
                <Select value={med || undefined} onValueChange={(v) => (setMed(v), setE({}))}>
                    <SelectTrigger id="cc-med" className="w-full" aria-invalid={!!e['cc-med']}>
                        <SelectValue placeholder="Choose" />
                    </SelectTrigger>
                    <SelectContent>
                        {meds.map((x) => (
                            <SelectItem key={x.key} value={x.key}>
                                {x.name} {x.strength} · {SUPPORT[supportOf(x, s.rt) ?? 'administer'].label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <InputError message={e['cc-med']} />
            </div>
            <div className="space-y-2">
                <Label id="cc-dir-l">
                    What did {p.pref} ask for? <span className="text-status-critical">*</span>
                </Label>
                <div id="cc-dir" tabIndex={-1}>
                    <TilePicker
                        labelledBy="cc-dir-l"
                        value={dir || null}
                        invalid={!!e['cc-dir']}
                        onChange={(k) => (setDir(k as 'less' | 'more'), setE({}))}
                        tiles={[
                            { key: 'less', label: 'For staff to do more', description: 'Happens straight away', icon: Hand },
                            { key: 'more', label: 'To do more themselves', description: 'Waits for a reassessment', icon: UserCheck },
                        ]}
                    />
                </div>
                <InputError message={e['cc-dir']} />
            </div>
            <div className="space-y-1.5">
                <Label htmlFor="cc-said">
                    What {p.pref} said <span className="text-status-critical">*</span>
                </Label>
                <Textarea id="cc-said" rows={2} value={said} aria-invalid={!!e['cc-said']} placeholder="e.g. “Can you just give it to me? I keep forgetting.”" onChange={(ev) => (setSaid(ev.target.value), setE({}))} />
                <InputError message={e['cc-said']} />
            </div>
            <DateTimeField id="cc-when" label="When" value={when} onChange={(v) => (setWhen(v), setE({}))} error={e['cc-when']} />
            {effect ? (
                <Notice tone={dir === 'less' ? 'info' : 'neutral'} icon={MessageSquareText} title="What happens">
                    {effect}
                </Notice>
            ) : null}
            <p className="text-caption">Refusing a single dose isn’t a change of support — record it as a refusal on the dose.</p>
        </Modal>
    );
}

/* ═════════════ An earlier assessment, read only (ViewSelfAdminDialog, redesigned) ═════════════ */
export function ViewAssessmentDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useStore();
    const a = ASSESSMENTS.find((x) => x.id === id)!;
    const p = PEOPLE[a.pid];
    const total = Object.values(a.scores).reduce((n, v) => n + v, 0);
    const current = assessmentOf(a.pid, s.rt);
    return (
        <Modal width={720} title={`${p.pref}’s assessment of ${a.assessed}`} description={`By ${a.by} with ${a.with.join(', ')}${current && current.id !== a.id ? ` · replaced by the assessment of ${current.assessed}` : ''}`} onClose={onClose}>
            <KV
                rows={[
                    ['Most independence allowed', <SupportChip key="c" support={OUTCOME_CAP[a.outcome]} />],
                    ['Wanted to manage medicines', a.wishes ? 'Yes' : 'No'],
                    ['Answers', `${total} of 25 · ${SCORES.map((q) => `${q.label.toLowerCase()} ${a.scores[q.key]}`).join(' · ')}`],
                    ['Checks ticked', CHECKS.filter((c) => a.checks[c.key]).map((c) => c.label).join(' · ') || 'None'],
                    ['Kept', a.storage],
                    ['Reassess by', a.reassessBy],
                ]}
            />
            {a.notes ? <p className="text-sm">{a.notes}</p> : null}
        </Modal>
    );
}
