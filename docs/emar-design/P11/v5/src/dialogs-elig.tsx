/* Staff eligibility dialogs: detail viewers and wizards on WizardShell, Fleet Modal for
 * simple forms. The assessment shows the restricted and unsupervised flags and every
 * area result truthfully (NF-03); acknowledgement only from the worker’s own login. */
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DiscardDraftDialog } from '@/components/governance/DiscardDraftDialog';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { StatusBadge } from '@/components/ui/status-badge';
import { Textarea } from '@/components/ui/textarea';
import { Field, InfoCard, SelectInput, StepHead, TilePicker } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Modal, Notice } from '@/pages/fleet-assets/settings/_ui';
import { Activity, AlertTriangle, Check, CheckCircle2, ChevronLeft, ChevronRight, ClipboardCheck, FileText, KeyRound, LogIn, Plus, RefreshCw, Settings2, ShieldCheck, Trash2, User, UserCheck, XCircle } from 'lucide-react';
import { useEffect, useState } from 'react';
import { AREAS, HOUSES, NOW_LABEL, PEOPLE, PERSONAS, SAFETY_RULES, STAFF, TODAY, type Staff } from './data';
import {
    areaAbility, areaRes, canAssess, canExempt, effStatus, eligLine, firstName, givenAbility, has, mySelf, myHouses, passCount, pinOf, reminderDays, restrictMode,
    staffById, staffNow, STATUS_META, useStore, validState, witnessAbility, type StaffNow,
} from './model';
import { useNav } from './nav';
import { CanList, Choice, OnOff, RecordPicker, type PickItem } from './ui';
import { NotFound } from './dialogs-settings';

const fmtDate = (iso: string) => { const [y, m, d] = iso.split('-').map(Number); return `${d} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][m - 1]} ${y}`; };
const addMonths = (iso: string, n: number) => { const [y, m, d] = iso.split('-').map(Number); const dt = new Date(y, m - 1 + n, d); return `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`; };
const addDays = (iso: string, n: number) => { const [y, m, d] = iso.split('-').map(Number); const dt = new Date(y, m - 1, d + n); return `${dt.getFullYear()}-${String(dt.getMonth() + 1).padStart(2, '0')}-${String(dt.getDate()).padStart(2, '0')}`; };

/* ── Assessment detail viewer: WizardShell sections (headerLabel, not sequential) ── */
const AV_SECS = [{ key: 'can', label: 'What they can do', blurb: 'Given, controlled, witness', icon: CheckCircle2 }, { key: 'areas', label: 'Areas', blurb: '12 areas and results', icon: ClipboardCheck }, { key: 'assess', label: 'Assessment', blurb: 'Who, when, flags', icon: FileText }] as const;
function AreaTable({ x }: { x: StaffNow }) {
    const { m } = useStore();
    return (
        <div className="divide-y divide-border rounded-xl border">
            {AREAS.map((a) => { const r = areaRes(x, a.k); return (
                <div key={a.k} className="grid gap-2 p-3 sm:grid-cols-[1.2fr_0.8fr_2fr] sm:items-center">
                    <span className="text-[13px] font-medium">{a.l}{a.core ? <StatusBadge variant="neutral" size="sm" className="ml-2">Core</StatusBadge> : null}</span>
                    <span>{r === 'yes' ? <StatusBadge variant="success" size="sm">Passed</StatusBadge> : r === 'no' ? <StatusBadge variant="critical" size="sm">Not passed</StatusBadge> : <StatusBadge variant="neutral" size="sm">Not assessed</StatusBadge>}</span>
                    <span className="text-caption">{a.rule === 'area' ? areaAbility(m, x, a.k).t : a.rule === 'notyet' ? 'Not checked when recording yet — orders don’t say which medicines are insulin' : a.core ? (r === 'yes' ? 'Core area' : 'Core area — not passed means the assessment isn’t passed') : 'Recorded on the assessment — not checked when recording'}</span>
                </div>); })}
        </div>
    );
}
export function AssessmentView({ id }: { id: string }) {
    const { m } = useStore();
    const { close, open } = useNav();
    const [sec, setSec] = useState(0);
    const x0 = staffById(id);
    if (!x0 || !myHouses(m.persona).includes(x0.house)) return <NotFound what="person" />;
    const x = staffNow(m, x0), g = givenAbility(m, x), [v, label] = STATUS_META[effStatus(m, x)];
    const renew = canAssess(m.persona) && x.st !== 'ack' ? <Button onClick={() => open({ kind: 'aw', arg: x.id, step: x.st === 'none' ? 'new' : x.st === 'failed' ? 'remedial' : 'renew' })}>{x.st === 'none' ? 'Start first assessment' : x.st === 'failed' ? 'Start remedial assessment' : 'Renew or reassess'}</Button> : null;
    return (
        <WizardShell open onClose={close} title={`${x.name} — medication competency`} description="Medication competency, what it allows, and each area result." railIcon={UserCheck} railTitle={x.name} railSub={`${x.role} · ${HOUSES[x.house]}`}
            steps={AV_SECS} stepIndex={sec} onStepClick={setSec} sequential={false} headerLabel={`${x.name} — ${AV_SECS[sec].label}`}
            railExtra={<div className="space-y-1"><StatusBadge variant={v} size="sm">{label}</StatusBadge><p className="text-caption">{eligLine(m, x)}</p></div>}
            footerStart={<Button variant="outline" onClick={close}>Close</Button>} footerEnd={renew}>
            <WizardStepPane key={sec}>
                {x.st === 'none' ? <InfoCard icon={User} tone="crit"><b>No assessment yet.</b> {x.name} started on {x.started}. Until assessed they can record refused, withheld and away, but not given.</InfoCard>
                    : sec === 0 ? (
                        <div className="space-y-4">
                            <InfoCard icon={g.v === 'yes' ? CheckCircle2 : g.v === 'part' ? AlertTriangle : XCircle} tone={g.v === 'yes' ? 'info' : g.v === 'part' ? 'warn' : 'crit'}><b>{g.t}</b><br />{label} · {eligLine(m, x)}</InfoCard>
                            <CanList items={[{ ...g, head: 'Given doses' }, { ...areaAbility(m, x, 'cd'), head: 'Controlled drugs' }, { ...areaAbility(m, x, 'covert'), head: 'Covert administration' }, { ...areaAbility(m, x, 'insulin'), head: 'Insulin' }, { ...witnessAbility(m, x), head: 'Witnessing controlled doses' }]} />
                        </div>
                    ) : sec === 1 ? <AreaTable x={x} /> : (
                        <div className="grid gap-4 sm:grid-cols-2">
                            <ReviewCard icon={FileText} title="Assessment"><ReviewRow label="Type" value={x.type} /><ReviewRow label="Assessed" value={`${x.assessed} by ${x.by}`} /><ReviewRow label="Ends" value={x.until} /><ReviewRow label="Result" value={`${x.st === 'failed' ? 'Not passed' : 'Passed'} · ${passCount(x)} of 12 (pass mark ${m.saved.elig.passMark})`} /><ReviewRow label="Observed" value={`${x.obs} administrations · minimum: ${m.saved.elig.obsNeeded || 'Not configured'}`} /></ReviewCard>
                            <ReviewCard icon={Settings2} title="Flags and acknowledgement"><ReviewRow label="Restriction" value={x.restricted || 'None'} /><ReviewRow label="Can give unsupervised" value={`${x.unsup ? 'On' : 'Off'} — recorded only, not used yet`} /><ReviewRow label="Can witness controlled drugs" value={x.witness ? 'On' : 'Off'} /><ReviewRow label={`${firstName(x)} acknowledged`} value={x.ack || <StatusBadge variant="info" size="sm">Waiting — from their own login</StatusBadge>} />{x.exempt ? <ReviewRow label="Exemption" value={`${HOUSES[x.exempt.house]} until ${x.exempt.until} · ${x.exempt.by}`} /> : null}</ReviewCard>
                        </div>
                    )}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ── Assessment wizard: 5 steps, free navigation, completeness, review, success, discard guard ── */
const AW_STEPS = [{ key: 'person', label: 'Person & context', blurb: 'Who, why and when', icon: User }, { key: 'areas', label: 'Areas', blurb: '12 areas, each answered', icon: ClipboardCheck }, { key: 'obs', label: 'Observed', blurb: 'Administrations watched', icon: Activity }, { key: 'result', label: 'Result', blurb: 'What they’ll be able to do', icon: ShieldCheck }, { key: 'review', label: 'Review & record', blurb: 'Check and declare', icon: Check }] as const;
type Obs = { person: string; type: string; outcome: string };
export function AssessmentWizard({ who: who0, mode = 'new', seed }: { who?: string; mode?: string; seed?: Record<string, string> }) {
    const { m, set } = useStore();
    const { close, open } = useNav();
    const p = m.persona;
    const start = who0 && staffById(who0) ? staffNow(m, staffById(who0)!) : null;
    const [A, setA] = useState(() => {
        const allYes = Object.fromEntries(AREAS.map((a) => [a.k, a.k === 'insulin' ? 'unseen' : 'yes']));
        const res: Record<string, string> = seed?.seed === 'pass' ? allYes : seed?.seed === 'partial' ? Object.fromEntries(AREAS.slice(0, 8).map((a) => [a.k, 'yes'])) : seed?.seed === 'fail' ? { ...allYes, safety: 'no', cd: 'no' } : {};
        return { who: start?.id ?? '', type: mode === 'renew' ? 'Renewal' : mode === 'remedial' ? 'Remedial' : start && start.st !== 'none' ? 'Renewal' : 'First assessment', date: TODAY, until: addMonths(TODAY, parseInt(m.saved.elig.validity, 10) || 12), untilTouched: false, err: '', res, obs: (seed?.seed === 'pass' ? [{ person: 'Aroha Ngata', type: 'Tablet or capsule', outcome: 'Safe' }, { person: 'Tama Walker', type: 'Liquid', outcome: 'Prompted' }] : []) as Obs[], restricted: seed?.restricted === '1', rnotes: '', witness: false, unsup: false, strengths: '', improve: '', declared: false };
    });
    const [step, setStep] = useState(Number(seed?.awstep || 0)), [errs, setErrs] = useState<Record<string, string>>({}), [dirty, setDirty] = useState(!!seed?.seed), [saved, setSaved] = useState(false), [guard, setGuard] = useState(seed?.discard === '1');
    const up = (patch: Partial<typeof A>) => { setA({ ...A, ...patch }); setDirty(true); };
    const answered = AREAS.filter((a) => A.res[a.k]).length, passed = AREAS.filter((a) => A.res[a.k] === 'yes').length, pm = parseInt(m.saved.elig.passMark, 10) || 10;
    const coreNot = AREAS.filter((a) => a.core && A.res[a.k] && A.res[a.k] !== 'yes');
    const pass = answered === 12 && passed >= pm && (m.saved.elig.coreMust !== 'yes' || !coreNot.length);
    const cdOk = A.res.cd === 'yes';
    const x = A.who ? staffNow(m, staffById(A.who)!) : null;
    const pct = Math.round(([!!A.who, !!A.type, answered === 12, A.obs.length > 0, A.declared, !!(A.strengths || A.improve)].filter(Boolean).length / 6) * 100);
    const stepErrs = (s: number) => {
        const e: Record<string, string> = {};
        if (s === 0) { if (!A.who) e.who = 'Choose who you’re assessing.'; if (A.date > TODAY) e.date = 'The assessment date can’t be in the future (today is 29 Sep 2026).'; if (A.until <= A.date) e.until = 'The end date must be after the assessment date.'; }
        if (s === 1 && answered < 12) e.areas = `Choose a result for every area — ${12 - answered} still ${12 - answered === 1 ? 'needs' : 'need'} one.`;
        if (s === 3 && pass && A.restricted && !A.rnotes.trim()) e.rnotes = 'Say what the restriction is — the person and their lead see this.';
        if (s === 4 && !A.declared) e.declared = 'Turn on the declaration to record the assessment.';
        return e;
    };
    useEffect(() => { if (seed?.awerr === '1') setErrs(stepErrs(step)); }, []); // eslint-disable-line react-hooks/exhaustive-deps
    const next = () => { const e = stepErrs(step); setErrs(e); if (!Object.keys(e).length) setStep(step + 1); };
    const save = () => {
        for (const s of [0, 1, 3, 4]) { const e = stepErrs(s); if (Object.keys(e).length) { setErrs(e); setStep(s); return; } }
        set((d) => { d.eligRows[A.who] = { st: 'ack', assessed: fmtDate(A.date), until: fmtDate(A.until), days: 365, by: PERSONAS[d.persona].name, type: A.type, res: Object.fromEntries(AREAS.map((a) => [a.k, A.res[a.k]])) as Staff['res'], witness: A.witness && pass && cdOk && !A.restricted, unsup: A.unsup && pass, restricted: A.restricted && pass ? A.rnotes : null, obs: A.obs.length, ack: null, prevValid: x?.st === 'current' ? x.until : null }; });
        setSaved(true); setDirty(false);
    };
    const onClose = () => (dirty && !saved ? setGuard(true) : close());
    const people: PickItem[] = STAFF.map((s) => staffNow(m, s)).filter((s) => myHouses(p).includes(s.house)).map((s) => { const self = s.name === PERSONAS[p].name; return { id: s.id, name: s.name, sub: `${s.role} · ${HOUSES[s.house]} · ${STATUS_META[effStatus(m, s)][1]}`, ok: !self, why: self ? 'you can’t assess yourself' : undefined }; });
    const px: StaffNow = { ...(x ?? (STAFF[0] as StaffNow)), name: x?.name ?? 'They', st: !pass ? 'failed' : A.restricted ? 'restricted' : 'current', res: Object.fromEntries(AREAS.map((a) => [a.k, (A.res[a.k] as 'yes') || 'unseen'])), witness: A.witness && pass && cdOk && !A.restricted, days: 365, exempt: undefined, until: fmtDate(A.until) };
    const consequence = (k: string, v?: string) => {
        const a = AREAS.find((y) => y.k === k)!; if (!v) return null;
        if (a.core && v !== 'yes') return <p className="text-xs text-status-critical">Core area — {m.saved.elig.coreMust === 'yes' ? 'not passed means the assessment isn’t passed' : 'counts towards the pass mark only'}</p>;
        if (a.rule === 'area' && v !== 'yes') { const mode = m.saved.safety.area; return <p className="text-caption">{mode === 'off' ? 'Recorded only — the area rule is off' : v === 'no' || mode === 'failed_or_not_seen' ? `They won’t be able to sign ${k === 'cd' ? 'controlled doses or witness them' : 'doses with a covert plan'}` : 'Allowed — the current rule only blocks when the area was failed'}</p>; }
        if (a.rule === 'notyet') return <p className="text-caption">Recorded only — the system doesn’t check insulin yet</p>;
        return null;
    };
    return (
        <>
            <WizardShell open onClose={onClose} title={mode === 'renew' ? 'Renew assessment' : mode === 'remedial' ? 'Remedial assessment' : 'New assessment'} description="Record a medication competency assessment." railIcon={UserCheck}
                railTitle={mode === 'renew' ? 'Renew assessment' : mode === 'remedial' ? 'Remedial assessment' : 'New assessment'} railSub={x ? x.name : 'Medication competency'} steps={AW_STEPS} stepIndex={step} onStepClick={setStep} pct={pct}
                success={saved ? <WizardSuccessPane title="Assessment recorded" blurb={<>Result: <b>{pass ? 'Passed' : 'Not passed'}</b> · {passed} of 12{pass ? ` · ends ${fmtDate(A.until)}` : ''}{A.restricted && pass ? ' · restricted' : ''}. {x ? firstName(x) : 'They'} acknowledges it from their own login in Meds today › My eligibility; it counts from then. {x?.st === 'current' ? `Until then their current assessment still counts (until ${x.until}).` : 'Until then they can’t record doses as given.'}</>} actions={<><Button variant="outline" onClick={() => open({ kind: 'aw', arg: 'new' })}>Assess someone else</Button><Button onClick={() => open({ kind: 'av', arg: A.who })} autoFocus>View assessment</Button></>} /> : undefined}
                footerStart={<div className="flex gap-2"><Button variant="outline" onClick={onClose}>Cancel</Button>{step > 0 ? <Button variant="outline" onClick={() => setStep(step - 1)}><ChevronLeft />Back</Button> : null}</div>}
                footerEnd={step < 4 ? <Button onClick={next}>Continue<ChevronRight /></Button> : <Button onClick={save}><Check />Record assessment</Button>}>
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <div className="space-y-5">
                            <StepHead icon={User} title="Person & context" blurb="Who you’re assessing, and when." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <RecordPicker id="aw-who" label="Who you’re assessing" required value={A.who} items={people} error={errs.who} onChange={(v) => { const s = staffNow(m, staffById(v)!); up({ who: v, type: mode === 'new' && s.st !== 'none' ? (s.st === 'failed' ? 'Remedial' : 'Renewal') : A.type }); setErrs({ ...errs, who: '' }); }} foot="People at your houses. You can’t assess yourself." />
                                <Field label="Assessor"><InfoCard icon={User}><b>{PERSONAS[p].name}</b> · {PERSONAS[p].role} — must be a different person.</InfoCard></Field>
                            </div>
                            <Field label="Type of assessment" required>
                                <TilePicker value={A.type} onChange={(v) => up({ type: v })} options={[{ key: 'First assessment', label: 'First assessment', description: 'First time for this person', icon: User }, { key: 'Renewal', label: 'Renewal', description: 'Before or after the end date', icon: RefreshCw }, { key: 'Remedial', label: 'Remedial', description: 'After an error or not passed', icon: AlertTriangle }, { key: 'Return to work', label: 'Return to work', description: 'After a long break', icon: LogIn }]} />
                            </Field>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="Assessment date" required error={errs.date} hint="Pacific/Auckland"><DatePicker id="aw-date" label="Assessment date" value={A.date} invalid={!!errs.date} onChange={(v) => { up({ date: v, until: A.untilTouched ? A.until : addMonths(v, parseInt(m.saved.elig.validity, 10) || 12) }); setErrs({ ...errs, date: '' }); }} /></Field>
                                <Field label="Ends" required error={errs.until} hint={`${m.saved.elig.validity} months${m.setBy.elig.validity ? '' : ' (default — not yet reviewed)'}`}><DatePicker id="aw-until" label="Ends" value={A.until} invalid={!!errs.until} onChange={(v) => { up({ until: v, untilTouched: true }); setErrs({ ...errs, until: '' }); }} /></Field>
                            </div>
                            {A.type === 'Remedial' ? <Field label="Linked medication error (optional)"><Input id="aw-err" value={A.err} placeholder="For example ME-2026-031" onChange={(e) => up({ err: e.target.value })} /></Field> : null}
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-4">
                            <StepHead icon={ClipboardCheck} title="Areas" blurb="Choose a result for every area. Nothing is chosen for you. “Not assessed” means you didn’t see it today." />
                            <InfoCard icon={ClipboardCheck} tone={errs.areas ? 'crit' : 'info'}><b>{answered} of 12 answered · {passed} passed</b> · pass mark {pm} of 12{m.saved.elig.coreMust === 'yes' ? ', every core area passed' : ''}{errs.areas ? <><br /><span role="alert">{errs.areas}</span></> : null}</InfoCard>
                            <div className="divide-y divide-border rounded-xl border">
                                {AREAS.map((a) => (
                                    <div key={a.k} className="grid gap-2 p-3 sm:grid-cols-[1fr_auto] sm:items-center" role="group" aria-label={a.l} aria-invalid={(errs.areas && !A.res[a.k]) || undefined}>
                                        <div><span className="text-[13px] font-medium">{a.l}</span>{a.core ? <StatusBadge variant="neutral" size="sm" className="ml-2">Core</StatusBadge> : null}{a.d ? <p className="text-caption">{a.d}</p> : null}{consequence(a.k, A.res[a.k])}</div>
                                        <Choice value={(A.res[a.k] || '') as string} onChange={(v) => { const res = { ...A.res, [a.k]: v }; setA({ ...A, res, witness: a.k === 'cd' && v !== 'yes' ? false : A.witness }); setDirty(true); if (errs.areas) { const n = AREAS.filter((y) => res[y.k]).length; setErrs(n === 12 ? {} : { areas: `Choose a result for every area — ${12 - n} still ${12 - n === 1 ? 'needs' : 'need'} one.` }); } }} options={[['yes', 'Passed'], ['no', 'Not passed'], ['unseen', 'Not assessed']]} />
                                    </div>
                                ))}
                            </div>
                        </div>
                    ) : step === 2 ? (
                        <div className="space-y-4">
                            <StepHead icon={Activity} title="Observed administrations" blurb="Log each administration you watched." />
                            <InfoCard icon={Activity}><b>Minimum: {m.saved.elig.obsNeeded || 'Not configured'}</b>{m.saved.elig.obsNeeded ? '' : ' — today’s form asks for 12, citing UK guidance; the organisation sets its own (Settings › Staff & PINs).'}</InfoCard>
                            {A.obs.length ? (
                                <div className="divide-y divide-border rounded-xl border">
                                    {A.obs.map((o, i) => (
                                        <div key={i} className="grid items-end gap-3 p-3 sm:grid-cols-[1fr_1fr_auto_auto]">
                                            <RecordPicker id={`ob-p-${i}`} label="Person" value={o.person} items={Object.values(PEOPLE).filter((pp) => !x || pp.house === HOUSES[x.house]).map((pp) => ({ id: `${pp.pref} ${pp.surname}`, name: `${pp.pref} ${pp.surname}`, sub: pp.house, ok: true }))} onChange={(v) => up({ obs: A.obs.map((y, j) => (j === i ? { ...y, person: v } : y)) })} placeholder="Search and choose" search="Search people…" />
                                            <Field label="Medicine type"><SelectInput value={o.type} placeholder="Choose" onChange={(v) => up({ obs: A.obs.map((y, j) => (j === i ? { ...y, type: v } : y)) })} options={['Tablet or capsule', 'Liquid', 'Inhaler', 'Topical', 'Injection', 'Controlled drug', 'As needed'].map((t) => ({ value: t, label: t }))} ariaLabel={`Observation ${i + 1} medicine type`} /></Field>
                                            <Field label="Outcome"><Choice value={o.outcome} onChange={(v) => up({ obs: A.obs.map((y, j) => (j === i ? { ...y, outcome: v } : y)) })} options={[['Safe', 'Safe'], ['Prompted', 'Prompted'], ['Stepped in', 'Stepped in']]} /></Field>
                                            <Button variant="outline" size="icon" aria-label={`Remove observation ${i + 1}`} onClick={() => up({ obs: A.obs.filter((_, j) => j !== i) })}><Trash2 /></Button>
                                        </div>
                                    ))}
                                </div>) : <p className="text-subtle">None logged yet.</p>}
                            <div className="flex items-center gap-3"><Button variant="outline" size="sm" onClick={() => up({ obs: [...A.obs, { person: '', type: 'Tablet or capsule', outcome: 'Safe' }] })}><Plus />Add an observed administration</Button><span className="text-caption">{A.obs.length} logged</span></div>
                        </div>
                    ) : step === 3 ? (
                        <div className="space-y-4">
                            <StepHead icon={ShieldCheck} title="Result" blurb="What they’ll be able to do under today’s rules." />
                            <InfoCard icon={pass ? CheckCircle2 : XCircle} tone={pass ? 'info' : 'crit'}><b>{pass ? 'Passed' : 'Not passed'}</b> · {passed} of 12 areas passed · pass mark {pm}{coreNot.length ? ` · core not passed: ${coreNot.map((a) => a.l.toLowerCase()).join(', ')}` : ''}</InfoCard>
                            {pass ? (
                                <div className="divide-y divide-border rounded-xl border">
                                    <div className="space-y-2 p-3">
                                        <div className="flex items-start justify-between gap-4"><div><label htmlFor="aw-f-restricted" className="text-[13px] font-semibold">Restrict their practice</label><p className="text-caption">Organisation rule for restricted competency: {SAFETY_RULES[1].opts.find((o) => o[0] === m.saved.safety.restricted)![1]}. A restricted worker can’t witness.</p></div><OnOff id="aw-f-restricted" checked={A.restricted} onChange={(v) => up({ restricted: v, witness: v ? false : A.witness })} /></div>
                                        {A.restricted ? <Field label="What’s the restriction?" required error={errs.rnotes}><Input id="aw-rn" value={A.rnotes} placeholder="For example: supervised practice until reassessed" aria-invalid={!!errs.rnotes || undefined} onChange={(e) => { up({ rnotes: e.target.value }); setErrs({ ...errs, rnotes: '' }); }} /></Field> : null}
                                    </div>
                                    <div className="flex items-start justify-between gap-4 p-3"><div><label htmlFor="aw-f-witness" className="text-[13px] font-semibold">Can witness controlled drugs</label><p className="text-caption">{cdOk ? (A.restricted ? 'Not while restricted.' : 'They also need a witness PIN and to be on shift.') : 'Needs the controlled drugs area passed.'}</p></div><OnOff id="aw-f-witness" checked={A.witness && cdOk && !A.restricted} disabled={!cdOk || A.restricted} onChange={(v) => up({ witness: v })} /></div>
                                    <div className="flex items-start justify-between gap-4 p-3"><div><label htmlFor="aw-f-unsup" className="text-[13px] font-semibold">Can give medicines unsupervised</label><p className="text-caption">Recorded only — not used to decide who can record yet.</p></div><OnOff id="aw-f-unsup" checked={A.unsup} onChange={(v) => up({ unsup: v })} /></div>
                                </div>
                            ) : <InfoCard icon={AlertTriangle} tone="crit"><b>They won’t be able to record doses as given.</b> Refused, withheld and away can still be recorded. Plan a remedial assessment, and note what to work on in the next step.</InfoCard>}
                            <p className="text-sm font-semibold">What {px.name.split(' ')[0]} will be able to do</p>
                            <CanList items={[{ ...givenAbility(m, px), head: 'Given doses' }, { ...areaAbility(m, px, 'cd'), head: 'Controlled drugs' }, { ...areaAbility(m, px, 'covert'), head: 'Covert administration' }, { ...areaAbility(m, px, 'insulin'), head: 'Insulin' }, { ...witnessAbility(m, px, x ? pinOf(m, x.name) : 'notset'), head: 'Witnessing controlled doses' }]} />
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead icon={Check} title="Review & record" blurb="Check everything, then declare." />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard icon={User} title="Person & context" onEdit={() => setStep(0)}><ReviewRow label="Person" value={x?.name} /><ReviewRow label="Type" value={A.type} /><ReviewRow label="Assessed" value={`${fmtDate(A.date)} by ${PERSONAS[p].name}`} /><ReviewRow label="Ends" value={pass ? fmtDate(A.until) : '—'} /></ReviewCard>
                                <ReviewCard icon={ClipboardCheck} title="Areas" onEdit={() => setStep(1)}><ReviewRow label="Result" value={<b>{pass ? 'Passed' : 'Not passed'} · {passed} of 12</b>} /><ReviewRow label="Not passed" value={AREAS.filter((a) => A.res[a.k] === 'no').map((a) => a.l).join(', ') || 'None'} /><ReviewRow label="Not assessed" value={AREAS.filter((a) => A.res[a.k] === 'unseen').map((a) => a.l).join(', ') || 'None'} /></ReviewCard>
                                <ReviewCard icon={Activity} title="Observed" onEdit={() => setStep(2)}><ReviewRow label="Administrations" value={String(A.obs.length)} /></ReviewCard>
                                <ReviewCard icon={ShieldCheck} title="Result" onEdit={() => setStep(3)}><ReviewRow label="Restricted" value={A.restricted && pass ? A.rnotes : 'No'} /><ReviewRow label="Can witness" value={A.witness && pass && cdOk && !A.restricted ? 'On' : 'Off'} /><ReviewRow label="Can give unsupervised" value={A.unsup && pass ? 'On (recorded only)' : 'Off'} /></ReviewCard>
                            </div>
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="What went well"><Textarea id="aw-s" rows={2} value={A.strengths} onChange={(e) => up({ strengths: e.target.value })} /></Field>
                                <Field label="What to work on"><Textarea id="aw-i" rows={2} value={A.improve} onChange={(e) => up({ improve: e.target.value })} /></Field>
                            </div>
                            <div className="flex items-start justify-between gap-4 rounded-xl border p-3">
                                <div><label htmlFor="aw-f-declared" className="text-[13px] font-semibold">I observed this assessment myself and the results are accurate <span className="text-status-critical">*</span></label><p className="text-caption">{x ? firstName(x) : 'The person'} then acknowledges it from their own login — only they can.</p>{errs.declared ? <p className="mt-1 text-xs text-status-critical" role="alert">{errs.declared}</p> : null}</div>
                                <span aria-invalid={!!errs.declared || undefined}><OnOff id="aw-f-declared" checked={A.declared} onChange={(v) => { up({ declared: v }); setErrs({}); }} /></span>
                            </div>
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog open={guard} mode="create" description="Nothing you’ve entered in this assessment has been saved. Closing now loses it." onKeepEditing={() => setGuard(false)} onDiscard={() => { setGuard(false); close(); }} />
        </>
    );
}

/* ── Exemption (NF-03): WizardShell — Person & reason · Dates · Review & grant ── */
const XW_STEPS = [{ key: 'who', label: 'Person & reason', blurb: 'Who and why', icon: User }, { key: 'dates', label: 'Dates', blurb: 'From and until', icon: FileText }, { key: 'review', label: 'Review & grant', blurb: 'What it allows', icon: Check }] as const;
export function ExemptionWizard({ who: who0, seed }: { who?: string; seed?: Record<string, string> }) {
    const { m, set } = useStore();
    const { close } = useNav();
    const p = m.persona, max = parseInt(m.saved.elig.longestEx, 10);
    const eligible = (s: StaffNow) => !validState(s) && !s.exempt;
    const people: PickItem[] = STAFF.map((s) => staffNow(m, s)).filter((s) => myHouses(p).includes(s.house)).map((s) => ({ id: s.id, name: s.name, sub: `${s.role} · ${HOUSES[s.house]} · ${STATUS_META[effStatus(m, s)][1]}`, ok: eligible(s), why: s.exempt ? 'already exempt' : 'has a current assessment' }));
    const [X, setX] = useState({ who: who0 && people.some((y) => y.id === who0 && y.ok) ? who0 : '', reason: seed?.reason ?? '', from: TODAY, until: seed?.until ?? '2026-10-03' });
    const [step, setStep] = useState(Number(seed?.xstep || 0)), [errs, setErrs] = useState<Record<string, string>>({}), [dirty, setDirty] = useState(false), [saved, setSaved] = useState(false), [guard, setGuard] = useState(false);
    if (!canExempt(p)) return <NotFound what="exemption" />;
    const up = (patch: Partial<typeof X>) => { setX({ ...X, ...patch }); setDirty(true); };
    const x = X.who ? staffById(X.who)! : null, last = addDays(X.from, max);
    const errsFor = (s: number) => {
        const e: Record<string, string> = {};
        if (s === 0) { if (!X.who) e.who = 'Choose who the exemption is for.'; if (X.reason.trim().length < 10) e.reason = 'Say why, in at least 10 characters.'; }
        if (s === 1) { if (X.until <= X.from) e.until = 'The end date must be after the start date.'; else if (X.until > last) e.until = `That’s longer than your organisation allows (${max} days). Choose ${fmtDate(last)} or earlier.`; }
        return e;
    };
    useEffect(() => { if (seed?.xerr === '1') setErrs(errsFor(step)); }, []); // eslint-disable-line react-hooks/exhaustive-deps
    const next = () => { const e = errsFor(step); setErrs(e); if (!Object.keys(e).length) setStep(step + 1); };
    const save = () => {
        for (const s of [0, 1]) { const e = errsFor(s); if (Object.keys(e).length) { setErrs(e); setStep(s); return; } }
        set((d) => { d.exemptions.unshift({ id: `x${d.exemptions.length + 1}`, who: X.who, house: x!.house, reason: X.reason.trim(), from: fmtDate(X.from), until: fmtDate(X.until), by: PERSONAS[d.persona].name, at: '29 Sep 2026 9:12 am', status: 'active' }); });
        setSaved(true); setDirty(false);
    };
    const onClose = () => (dirty && !saved ? setGuard(true) : close());
    const what = [{ v: 'part' as const, t: `${x ? firstName(x) : 'They'} can record doses as given at ${x ? HOUSES[x.house] : 'their house'} from ${fmtDate(X.from)} until ${fmtDate(X.until)}, then it ends by itself.` }, { v: 'no' as const, t: 'They can’t witness controlled drugs — a witness needs a current assessment.' }, { v: 'na' as const, t: 'The restricted and area rules still apply.' }];
    return (
        <>
            <WizardShell open onClose={onClose} title="Grant an exemption" description="Lets one person record doses as given at one house without a current assessment, until a fixed end date." railIcon={ShieldCheck} railTitle="Grant an exemption" railSub="Staff eligibility › Exemptions"
                steps={XW_STEPS} stepIndex={step} onStepClick={setStep} pct={Math.round(([!!X.who, X.reason.trim().length >= 10, X.until > X.from && X.until <= last].filter(Boolean).length / 3) * 100)}
                success={saved ? <WizardSuccessPane title="Exemption granted" blurb={`${x!.name} can record doses as given at ${HOUSES[x!.house]} until ${fmtDate(X.until)}. It ends by itself. Recorded in the audit log.`} actions={<Button onClick={close} autoFocus>Done</Button>} /> : undefined}
                footerStart={<div className="flex gap-2"><Button variant="outline" onClick={onClose}>Cancel</Button>{step > 0 ? <Button variant="outline" onClick={() => setStep(step - 1)}><ChevronLeft />Back</Button> : null}</div>}
                footerEnd={step < 2 ? <Button onClick={next}>Continue<ChevronRight /></Button> : <Button onClick={save}><Check />Grant exemption</Button>}>
                <WizardStepPane key={step}>
                    {step === 0 ? (
                        <div className="space-y-5">
                            <StepHead icon={User} title="Person & reason" blurb="Only people without a current assessment can be chosen." />
                            <RecordPicker id="xw-who" label="Person" required value={X.who} items={people} error={errs.who} onChange={(v) => { up({ who: v }); setErrs({ ...errs, who: '' }); }} foot="Only people without a current assessment can be chosen." />
                            {x ? <InfoCard icon={FileText}><b>{HOUSES[x.house]}</b> — an exemption covers one house, their own.</InfoCard> : null}
                            <Field label="Why" required error={errs.reason} hint="At least 10 characters"><Textarea id="xw-why" rows={3} value={X.reason} aria-invalid={!!errs.reason || undefined} placeholder="For example: renewal booked for 3 October — the assessor is on leave until then" onChange={(e) => { up({ reason: e.target.value }); setErrs({ ...errs, reason: '' }); }} /></Field>
                        </div>
                    ) : step === 1 ? (
                        <div className="space-y-5">
                            <StepHead icon={FileText} title="Dates" blurb={`Longest allowed: ${max} days — on or before ${fmtDate(last)}.`} />
                            <div className="grid gap-4 sm:grid-cols-2">
                                <Field label="From" required hint="Pacific/Auckland"><DatePicker id="xw-from" label="From" value={X.from} onChange={(v) => up({ from: v })} /></Field>
                                <Field label="Until" required error={errs.until} hint={`On or before ${fmtDate(last)}`}><DatePicker id="xw-until" label="Until" value={X.until} invalid={!!errs.until} onChange={(v) => { up({ until: v }); setErrs({ ...errs, until: '' }); }} /></Field>
                            </div>
                            <p className="text-caption">The longest exemption is set in Settings › Staff &amp; PINs › Exemption limit ({m.setBy.elig.longestEx ? 'set' : 'default — not yet reviewed'}).</p>
                        </div>
                    ) : (
                        <div className="space-y-4">
                            <StepHead icon={Check} title="Review & grant" blurb={`Approved by you (${PERSONAS[p].name}). Recorded in the audit log.`} />
                            <ReviewCard icon={User} title="Exemption" onEdit={() => setStep(0)}><ReviewRow label="Person" value={x?.name} /><ReviewRow label="Where" value={x ? HOUSES[x.house] : ''} /><ReviewRow label="Why" value={X.reason} /></ReviewCard>
                            <ReviewCard icon={FileText} title="Dates" onEdit={() => setStep(1)}><ReviewRow label="From" value={fmtDate(X.from)} /><ReviewRow label="Until" value={fmtDate(X.until)} /></ReviewCard>
                            <p className="text-sm font-semibold">What this does</p>
                            <CanList items={what} />
                        </div>
                    )}
                </WizardStepPane>
            </WizardShell>
            <DiscardDraftDialog open={guard} mode="create" description="Nothing you’ve entered here has been saved. Closing now loses it." onKeepEditing={() => setGuard(false)} onDiscard={() => { setGuard(false); close(); }} />
        </>
    );
}
/** End early: a consequential action with a required reason — Fleet Modal with the destructive verb. */
export function EndExemption({ id }: { id: string }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const [why, setWhy] = useState(''), [err, setErr] = useState('');
    const ex = m.exemptions.find((e) => e.id === id);
    if (!ex) return <NotFound what="exemption" />;
    const x = staffById(ex.who)!;
    return (
        <Modal title={`End ${x.name}’s exemption early?`} description={`From now, ${firstName(x)} can’t record doses as given at ${HOUSES[ex.house]} until they have a current assessment.`} onClose={close}
            footer={<><Button variant="outline" onClick={close}>Cancel</Button><Button variant="destructive" onClick={() => { if (why.trim().length < 3) { setErr('Say why — the person and their lead see this.'); setTimeout(() => document.getElementById('xe-why')?.focus(), 0); return; } set((d) => { const e = d.exemptions.find((y) => y.id === id)!; e.status = 'revoked'; e.endNote = `Ended 29 Sep 2026 9:12 am by ${PERSONAS[d.persona].name}: “${why.trim()}”`; }); close(); flash('Exemption ended. They can’t record doses as given until they have a current assessment.'); }}>End exemption</Button></>}>
            <Field label="Why" required error={err}><Textarea id="xe-why" rows={2} value={why} aria-invalid={!!err || undefined} placeholder="For example: renewal done" onChange={(e) => { setWhy(e.target.value); setErr(''); }} /></Field>
            <p className="text-caption">Recorded in the audit log with your name and the time.</p>
        </Modal>
    );
}

/* ── My eligibility: WizardShell detail viewer from the Meds today meter (answer 5, 12) ── */
const ME_SECS = [{ key: 'sum', label: 'Summary', blurb: 'Can I give doses now?', icon: UserCheck }, { key: 'can', label: 'What I can do', blurb: 'Given, controlled, witness', icon: CheckCircle2 }, { key: 'areas', label: 'My areas', blurb: '12 areas', icon: ClipboardCheck }, { key: 'details', label: 'Details', blurb: 'Dates, PIN, shift', icon: FileText }] as const;
export function meHead(m: ReturnType<typeof useStore>['m'], x: StaffNow): [tone: 'info' | 'warn' | 'crit', title: string, text: string] {
    if (x.st === 'ack') return ['warn', 'Your new assessment is waiting for you', 'Hana Kereama recorded it on 28 September. It counts once you acknowledge it — until then you can’t record doses as given (your previous assessment ended on 14 September).'];
    if (x.st === 'none') return ['crit', 'You haven’t been assessed yet', 'You can record refused, withheld and away, but not given. Jordan Tipene, your house lead, books your first assessment.'];
    if (x.exempt) return ['warn', `You can record doses as given until ${x.exempt.until} — exemption`, `Hana Kereama approved it for Kōwhai House: “${x.exempt.reason}”. You can’t witness during an exemption, and the restricted and area rules still apply.`];
    if (x.st === 'expired') return ['crit', 'You can’t record doses as given', `Your competency ended on ${x.until}. You can still record refused, withheld and away. Talk to Jordan Tipene, your house lead, about reassessment.`];
    if (x.st === 'restricted') return restrictMode(m) === 'cosigner' ? ['warn', 'A colleague confirms each dose you give', `Your competency is restricted: ${x.restricted}. A colleague on shift confirms with their witness PIN. You can’t witness while restricted.`] : ['crit', 'You can’t sign doses as given on your own', `Your competency is restricted: ${x.restricted}. A colleague on shift gives the dose; you can record refused, withheld and away. You can’t witness while restricted.`];
    if (effStatus(m, x) === 'due') return ['warn', `You can record doses as given — renewal due in ${x.days} days`, `Your competency ends on ${x.until}. Ask Jordan Tipene, your house lead, to book your renewal.`];
    return ['info', 'You can record doses as given', `Your competency is current until ${x.until}.`];
}
export function MyEligibility() {
    const { m } = useStore();
    const { close, open } = useNav();
    const [sec, setSec] = useState(0);
    if (!has(m.persona, 'administer')) {
        return <Modal title="My medication eligibility" description={`${PERSONAS[m.persona].name} · ${PERSONAS[m.persona].role} · checked ${NOW_LABEL}`} onClose={close}><InfoCard icon={UserCheck}><b>Your role doesn’t record doses.</b> No medication competency assessment is needed for your work. You can’t witness controlled drugs.</InfoCard></Modal>;
    }
    const x = mySelf(m), g = givenAbility(m, x), w = witnessAbility(m, x, pinOf(m, 'Priya Shah')), [tone, title, text] = meHead(m, x);
    const Icon = tone === 'info' ? CheckCircle2 : tone === 'warn' ? AlertTriangle : XCircle;
    const pin = pinOf(m, 'Priya Shah');
    return (
        <WizardShell open onClose={close} title="My medication eligibility" description="What you can do right now, from the same rules as the register." railIcon={UserCheck} railTitle="My eligibility" railSub={`${PERSONAS[m.persona].name} · checked ${NOW_LABEL}`}
            steps={ME_SECS} stepIndex={sec} onStepClick={setSec} sequential={false} headerLabel={`My medication eligibility — ${ME_SECS[sec].label}`}
            footerStart={<Button variant="outline" onClick={close}>Close</Button>}
            footerEnd={x.st === 'ack' ? <Button onClick={() => open({ kind: 'ack' })}><Check />Read and acknowledge</Button> : null}>
            <WizardStepPane key={sec}>
                {sec === 0 ? <div className="space-y-4"><InfoCard icon={Icon} tone={tone}><b>{title}</b><br />{text}</InfoCard><CanList items={[{ ...g, head: 'Give doses' }, { ...w, head: 'Witness controlled doses' }, { v: 'yes', t: 'Refused, withheld and away — always recordable', head: 'Not given' }]} /></div>
                    : sec === 1 ? <CanList items={[{ ...g, head: 'Give doses' }, ...(x.st === 'none' ? [] : [{ ...areaAbility(m, x, 'cd'), head: 'Controlled drugs' }, { ...areaAbility(m, x, 'covert'), head: 'Covert administration' }, { ...areaAbility(m, x, 'insulin'), head: 'Insulin' }]), { ...w, head: 'Witness controlled doses' }, { v: 'yes' as const, t: 'Refused, withheld and away — always recordable', head: 'Not given' }]} />
                        : sec === 2 ? (x.st === 'none' ? <InfoCard icon={ClipboardCheck}>No assessment yet, so no areas are recorded.</InfoCard> : <AreaTable x={x} />)
                            : <div className="grid gap-4 sm:grid-cols-2">
                                <ReviewCard icon={FileText} title="My assessment">{x.assessed ? <><ReviewRow label="Assessed" value={`${x.assessed} by ${x.by} · ${x.type || 'Renewal'}`} /><ReviewRow label="Ends" value={`${x.until} · reminders start ${reminderDays(m)} days before`} /><ReviewRow label="Restriction" value={x.restricted || 'None'} /><ReviewRow label="My acknowledgement" value={x.ack || <b>Not yet</b>} /></> : <ReviewRow label="Assessment" value="None yet" />}</ReviewCard>
                                <ReviewCard icon={KeyRound} title="Witness PIN and shift"><ReviewRow label="Witness PIN" value={pin === 'set' ? 'Set — last changed 9 September 2026' : pin === 'locked' ? `Locked after ${m.saved.pin.attempts} wrong attempts — unlocks after ${m.saved.pin.lockout} minutes` : 'Not set — you can’t co-sign or witness until you set one'} /><ReviewRow label="Shift" value="Clocked in 7:02 am · Kōwhai House · 7:00 am–3:00 pm" /><ReviewRow label="House access" value="Kōwhai House" /></ReviewCard>
                            </div>}
            </WizardStepPane>
        </WizardShell>
    );
}
export function Acknowledge({ err0 }: { err0?: boolean }) {
    const { m, set, flash } = useStore();
    const { close } = useNav();
    const [on, setOn] = useState(false), [err, setErr] = useState(!!err0);
    const x = mySelf(m);
    return (
        <Modal title="Acknowledge your assessment" description={`Recorded by ${x.by} on ${x.assessed}. Only you can acknowledge it.`} onClose={close}
            footer={<><Button variant="outline" onClick={close}>Cancel</Button><Button onClick={() => { if (!on) { setErr(true); document.getElementById('ack-tick')?.focus(); return; } set((d) => { d.myAck = true; }); close(); flash('Assessment acknowledged. You can record doses as given until 28 Sep 2027.'); }}>Acknowledge</Button></>}>
            <ReviewCard icon={ClipboardCheck} title="Your result"><ReviewRow label="Result" value={<b>Passed · {passCount(x)} of 12 areas</b>} /><ReviewRow label="Ends" value={x.until} /><ReviewRow label="Not passed" value={AREAS.filter((a) => areaRes(x, a.k) === 'no').map((a) => a.l).join(', ') || 'None'} /><ReviewRow label="Not assessed" value={AREAS.filter((a) => areaRes(x, a.k) === 'unseen').map((a) => a.l).join(', ') || 'None'} /><ReviewRow label="Restriction" value="None" /><ReviewRow label="What to work on" value="Insulin pens — practise with Hana before the next assessment" /></ReviewCard>
            <div className="flex items-start justify-between gap-4 rounded-xl border p-3">
                <div><label htmlFor="ack-tick" className="text-[13px] font-semibold">I’ve read my assessment and I understand what I can and can’t do <span className="text-status-critical">*</span></label>{err ? <p className="mt-1 text-xs text-status-critical" role="alert">Turn this on to acknowledge.</p> : null}</div>
                <OnOff id="ack-tick" checked={on} onChange={(v) => { setOn(v); setErr(false); }} />
            </div>
        </Modal>
    );
}
