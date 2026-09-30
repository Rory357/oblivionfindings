/* P04’s covert and reconciliation dialogs — redesigning CovertDialog,
 * RevokeCovertDialog (Main Q6) and Respite’s counts-only reconciliation modal
 * (Q5) as structured, checked records. Real WizardShell, ReviewCard/ReviewRow,
 * WizardSuccessPane, the Fleet Settings Modal, ConfirmDialog, Select, Input,
 * Textarea, Checkbox, FileDropzone + StagedFileCard and the PKG-01
 * DateTimeField. */
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
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { Brain, Calendar, Check, ChevronLeft, ChevronRight, ClipboardCheck, EyeOff, FileSignature, GitCompare, Loader2, Package, Pill, Plus, ShieldCheck, Users } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LABEL, NOW_LOCAL } from './clock';
import { PEOPLE, PEOPLE_ORDER, PERSONAS, REC_REASON, type Covert, type Order, type PersonId, type RecDecision, type RecItem, type RecReason, type Reconciliation, type Version } from './data';
import { allergyMatch, allergyText } from './dialogs-order';
import { Modal } from './modal';
import { allOrders, allRecs, canCheck, cdView, concealed, covertOf, covertState, currentOf, recOf, statusOf } from './model';
import { useStore } from './store';
import { KV, Notice, TilePicker } from './ui';

const cantSaveOffline = 'You’re offline, so this can’t be saved yet. Nothing is lost — keep this open and save when you reconnect.';
const focusFirst = (x: Record<string, string>) => window.setTimeout(() => document.getElementById(Object.keys(x)[0])?.focus(), 50);
const restore = (rf?: () => HTMLElement | null) => (ev: Event) => {
    const el = rf?.();
    if (el) {
        ev.preventDefault();
        el.focus();
    }
};
const stamp = () => `Mon 28 Sep, ${NOW_LABEL}`;
const Req = () => <span className="text-status-critical">*</span>;

/* ═════════════ Covert giving: review or authorise (CovertDialog, redesigned — Q6) ═════════════ */
const ASSESSORS = ['Dr Lena Chen (GP)', 'Hana Kereama (clinical lead)', 'Someone else'];
const ROLES = ['Welfare guardian', 'EPOA (personal care and welfare)', 'Family or whānau — no guardian or EPOA'];
const REVIEW: Record<string, { label: string; on: string; iso: string }> = {
    '3': { label: 'In 3 months (the default)', on: '28 December 2026', iso: '2026-12-28' },
    '1': { label: 'In 1 month', on: '28 October 2026', iso: '2026-10-28' },
    '6': { label: 'In 6 months', on: '28 March 2027', iso: '2027-03-28' },
};
export function CovertDialog({ orderId, onClose, onRevoke, returnFocus }: { orderId: string; onClose: () => void; onRevoke: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const o = allOrders(s.rt).find((x) => x.id === orderId)!;
    const who = PEOPLE[o.pid];
    const cur = covertOf(o.id, s.rt, s.route.scenario);
    const st = cur ? covertState(cur) : null;
    const [step, setStep] = useState(0);
    const [f, setF] = useState({
        assessor: '',
        assessorOther: '',
        assessedOn: NOW_LOCAL,
        outcome: '' as '' | 'cannot' | 'can',
        consultedName: cur?.consulted[0]?.name ?? '',
        consultedRole: cur?.consulted[0]?.role ?? '',
        consultedView: '',
        pharmacy: cur?.pharmacist.name ?? 'Kōwhai Pharmacy',
        advice: '',
        gp: cur?.gp.name ?? 'Dr Lena Chen',
        file: null as File | null,
        method: cur?.method ?? '',
        review: '3',
    });
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const [offlineErr, setOfflineErr] = useState(false);
    const set = (patch: Partial<typeof f>) => (setF((x) => ({ ...x, ...patch })), setE({}));
    const dirty = !!(f.assessor || f.outcome || f.consultedView || f.advice || f.file);
    const steps = [
        { key: 'capacity', label: 'Capacity', blurb: 'For this decision', icon: Brain },
        { key: 'consulted', label: 'Who was consulted', blurb: 'Guardian or EPOA', icon: Users },
        { key: 'pharmacist', label: 'Pharmacist’s advice', blurb: 'Can it be hidden safely?', icon: Package },
        { key: 'gp', label: 'GP and method', blurb: 'Authorisation, how, review', icon: FileSignature },
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const key = steps[step].key;
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'capacity') {
            if (!f.assessor) x['cv-assessor'] = 'Choose who assessed capacity.';
            if (f.assessor === 'Someone else' && !f.assessorOther.trim()) x['cv-assessor-other'] = 'Enter their name and role.';
            if (!f.outcome) x['cv-outcome'] = 'Choose the outcome.';
            if (f.outcome === 'can') x['cv-outcome'] = `${who.pref} can make this decision, so covert giving can’t be authorised. Stop covert giving instead.`;
        }
        if (k === 'consulted') {
            if (!f.consultedName.trim()) x['cv-cname'] = 'Enter who was consulted.';
            if (!f.consultedRole) x['cv-crole'] = 'Choose their role.';
            if (!f.consultedView.trim()) x['cv-cview'] = 'Write what they said.';
        }
        if (k === 'pharmacist') {
            if (!f.pharmacy.trim()) x['cv-pharmacy'] = 'Enter the pharmacy or pharmacist.';
            if (!f.advice.trim()) x['cv-advice'] = 'Write the pharmacist’s advice — it’s required.';
        }
        if (k === 'gp') {
            if (!f.gp.trim()) x['cv-gp'] = 'Enter the GP.';
            if (!f.file) x['cv-file'] = 'Attach the GP’s signed authorisation.';
            if (!f.method.trim()) x['cv-method'] = 'Describe how it’s given.';
        }
        return x;
    }
    function next() {
        const x = validate(key);
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        setStep(step + 1);
    }
    function save() {
        if (s.route.scenario === 'offline') return setOfflineErr(true);
        setPhase('sending');
        window.setTimeout(() => {
            const r = REVIEW[f.review];
            const n: Covert = {
                id: `cv-new-${Date.now()}`,
                pid: o.pid,
                orderId: o.id,
                status: 'active',
                capacity: { by: f.assessor === 'Someone else' ? f.assessorOther.trim() : f.assessor, on: '28 September 2026', outcome: `${who.pref} can’t currently make this decision — decision-specific assessment` },
                consulted: [{ name: f.consultedName.trim(), role: f.consultedRole, on: '28 September 2026', view: f.consultedView.trim() }],
                pharmacist: { name: f.pharmacy.trim(), on: '28 September 2026', advice: f.advice.trim() },
                gp: { name: f.gp.trim(), on: '28 September 2026', file: f.file?.name },
                method: f.method.trim(),
                authorised: '28 September 2026',
                review: r.on,
                reviewIso: r.iso,
            };
            s.update((rt) => ({
                ...rt,
                covert: cur ? { ...rt.covert, [cur.id]: { ...rt.covert[cur.id], status: 'replaced' } } : rt.covert,
                newCovert: [n, ...rt.newCovert],
                events: [{ id: `ev-${Date.now()}`, at: stamp(), pid: o.pid, what: `Covert giving ${cur ? 'reviewed and re-authorised' : 'authorised'} — ${o.med} (review by ${r.on})`, who: me.name }, ...rt.events],
            }));
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        capacity: (
            <>
                {cur && st ? (
                    <Notice tone={st.state === 'overdue' ? 'critical' : st.state === 'due' ? 'warning' : 'info'} icon={EyeOff} title={st.state === 'overdue' ? 'Covert giving is blocked until this review is saved' : `Authorised ${cur.authorised} · review by ${cur.review}`}>
                        {st.line}. A review is a fresh decision: capacity, who was consulted, the pharmacist’s advice and the GP’s authorisation are recorded again.
                    </Notice>
                ) : (
                    <Notice tone="info" icon={EyeOff} title="Covert giving is a last resort">
                        Only when {who.pref} can’t make this decision, it’s in their best interests, and the medicine is needed. It’s authorised for this one medicine.
                    </Notice>
                )}
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="cv-assessor">
                            Who assessed capacity? <Req />
                        </Label>
                        <Select value={f.assessor || undefined} onValueChange={(v) => set({ assessor: v })}>
                            <SelectTrigger id="cv-assessor" className="w-full" aria-invalid={!!e['cv-assessor']}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {ASSESSORS.map((x) => (
                                    <SelectItem key={x} value={x}>
                                        {x}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['cv-assessor']} />
                    </div>
                    {f.assessor === 'Someone else' ? (
                        <div className="space-y-1.5">
                            <Label htmlFor="cv-assessor-other">
                                Their name and role <Req />
                            </Label>
                            <Input id="cv-assessor-other" value={f.assessorOther} aria-invalid={!!e['cv-assessor-other']} onChange={(ev) => set({ assessorOther: ev.target.value })} />
                            <InputError message={e['cv-assessor-other']} />
                        </div>
                    ) : null}
                </div>
                <DateTimeField id="cv-assessed" label="Assessed" value={f.assessedOn} onChange={(v) => set({ assessedOn: v })} />
                <div className="space-y-2">
                    <Label id="cv-outcome-l">
                        Can {who.pref} decide about taking {o.med} right now? <Req />
                    </Label>
                    <div id="cv-outcome" tabIndex={-1}>
                        <TilePicker
                            labelledBy="cv-outcome-l"
                            value={f.outcome || null}
                            invalid={!!e['cv-outcome']}
                            onChange={(k) => set({ outcome: k as 'cannot' | 'can' })}
                            tiles={[
                                { key: 'cannot', label: 'No — not for this decision', description: 'They can’t understand, weigh up or remember it, even with support', icon: Brain },
                                { key: 'can', label: 'Yes, with support', description: 'Then it isn’t covert — offer it openly', icon: Check },
                            ]}
                        />
                    </div>
                    <InputError message={e['cv-outcome']} />
                </div>
                {f.outcome === 'can' && cur ? (
                    <Notice tone="warning" title="Covert giving can’t continue" actions={<Button size="sm" variant="outline" onClick={onRevoke}>Stop covert giving</Button>}>
                        Stop the authorisation with this as the reason. {who.pref} is offered {o.med} openly from now.
                    </Notice>
                ) : null}
            </>
        ),
        consulted: (
            <>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="cv-cname">
                            Name <Req />
                        </Label>
                        <Input id="cv-cname" value={f.consultedName} aria-invalid={!!e['cv-cname']} onChange={(ev) => set({ consultedName: ev.target.value })} />
                        <InputError message={e['cv-cname']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="cv-crole">
                            Their role <Req />
                        </Label>
                        <Select value={f.consultedRole || undefined} onValueChange={(v) => set({ consultedRole: v })}>
                            <SelectTrigger id="cv-crole" className="w-full" aria-invalid={!!e['cv-crole']}>
                                <SelectValue placeholder="Choose" />
                            </SelectTrigger>
                            <SelectContent>
                                {ROLES.map((x) => (
                                    <SelectItem key={x} value={x}>
                                        {x}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <InputError message={e['cv-crole']} />
                    </div>
                </div>
                {f.consultedRole.startsWith('Family') ? <Notice tone="warning" title={`${who.pref} has no welfare guardian or EPOA recorded`}>Family or whānau can be consulted, but they can’t consent for {who.pref}. The GP’s authorisation carries the decision; the provider manager is told.</Notice> : null}
                <div className="space-y-1.5">
                    <Label htmlFor="cv-cview">
                        What they said <Req />
                    </Label>
                    <Textarea id="cv-cview" rows={3} value={f.consultedView} aria-invalid={!!e['cv-cview']} placeholder={`e.g. Agrees it’s in ${who.pref}’s best interests; wants to be told if ${who.pref} starts taking it openly.`} onChange={(ev) => set({ consultedView: ev.target.value })} />
                    <InputError message={e['cv-cview']} />
                </div>
            </>
        ),
        pharmacist: (
            <>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="cv-pharmacy">
                            Pharmacist or pharmacy <Req />
                        </Label>
                        <Input id="cv-pharmacy" value={f.pharmacy} aria-invalid={!!e['cv-pharmacy']} onChange={(ev) => set({ pharmacy: ev.target.value })} />
                        <InputError message={e['cv-pharmacy']} />
                    </div>
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="cv-advice">
                        Their advice <Req />
                    </Label>
                    <Textarea id="cv-advice" rows={3} value={f.advice} aria-invalid={!!e['cv-advice']} placeholder="e.g. Can be crushed and mixed with a spoonful of yoghurt. Give at the same time each day; don’t mix with iron." onChange={(ev) => set({ advice: ev.target.value })} />
                    <p className="text-caption">Whether it can be crushed, opened or mixed, with what, and anything that stops it working. Shown to staff when they give it.</p>
                    <InputError message={e['cv-advice']} />
                </div>
                {cur ? <p className="text-caption">Last time: {cur.pharmacist.name}, {cur.pharmacist.on} — “{cur.pharmacist.advice}”</p> : null}
            </>
        ),
        gp: (
            <>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="cv-gp">
                            GP <Req />
                        </Label>
                        <Input id="cv-gp" value={f.gp} aria-invalid={!!e['cv-gp']} onChange={(ev) => set({ gp: ev.target.value })} />
                        <InputError message={e['cv-gp']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="cv-review">Review by</Label>
                        <Select value={f.review} onValueChange={(v) => set({ review: v })}>
                            <SelectTrigger id="cv-review" className="w-full">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {Object.entries(REVIEW).map(([k, r]) => (
                                    <SelectItem key={k} value={k}>
                                        {r.label} — {r.on}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                        <p className="text-caption">The house lead gets a follow-up 14 days before. After the date, covert giving is blocked until it’s reviewed.</p>
                    </div>
                </div>
                <div className="space-y-1.5">
                    <Label id="cv-file-l">
                        The GP’s signed authorisation <Req />
                    </Label>
                    {f.file ? <StagedFileCard file={f.file} onRemove={() => set({ file: null })} /> : <FileDropzone id="cv-file" aria-labelledby="cv-file-l" aria-invalid={!!e['cv-file'] || undefined} multiple={false} accept=".pdf,image/*" hint="PDF or a photo of the signed form" onFiles={(x) => set({ file: x[0] ?? null })} />}
                    <InputError message={e['cv-file']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="cv-method">
                        How it’s given <Req />
                    </Label>
                    <Textarea id="cv-method" rows={2} value={f.method} aria-invalid={!!e['cv-method']} placeholder="e.g. Offer the tablet openly first; if refused, crushed into a spoonful of yoghurt at breakfast." onChange={(ev) => set({ method: ev.target.value })} />
                    <InputError message={e['cv-method']} />
                </div>
            </>
        ),
        review: (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={Brain} title="Capacity" onEdit={() => setStep(0)}>
                    <ReviewRow label="Assessed by" value={f.assessor === 'Someone else' ? f.assessorOther : f.assessor} />
                    <ReviewRow label="Outcome" value="Can’t make this decision now" />
                </ReviewCard>
                <ReviewCard icon={Users} title="Consulted" onEdit={() => setStep(1)}>
                    <ReviewRow label={f.consultedRole.split(' (')[0]} value={f.consultedName} />
                    <ReviewRow label="Said" value={f.consultedView} />
                </ReviewCard>
                <ReviewCard icon={Package} title="Pharmacist" onEdit={() => setStep(2)}>
                    <ReviewRow label={f.pharmacy} value={f.advice} />
                </ReviewCard>
                <ReviewCard icon={FileSignature} title="GP and method" onEdit={() => setStep(3)}>
                    <ReviewRow label="Authorised by" value={`${f.gp} · ${f.file?.name ?? ''}`} />
                    <ReviewRow label="How" value={f.method} />
                    <ReviewRow label="Review by" value={REVIEW[f.review].on} />
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="When you save">
                        {cur ? `The authorisation from ${cur.authorised} is kept as “Replaced”. ` : ''}Staff see the method and the pharmacist’s advice when they give {o.med}. It applies to {o.med} only.
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
                title={`${cur ? 'Review covert giving' : 'Authorise covert giving'} — ${o.med}, ${who.pref}`}
                description="Capacity, who was consulted, the pharmacist’s advice, the GP’s authorisation and the method."
                railIcon={EyeOff}
                railTitle={cur ? 'Review covert giving' : 'Authorise covert giving'}
                railSub={`${who.pref} · ${o.med}`}
                steps={steps.map((x, i) => ({ ...x, disabled: i > step || phase === 'sending' }))}
                stepIndex={step}
                onStepClick={(i) => i <= step && setStep(i)}
                pct={Math.round(((step + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
                pctLabel="Completeness"
                railExtra={
                    cur ? (
                        <Button type="button" variant="outline" size="sm" className="w-full" onClick={onRevoke} disabled={phase !== 'edit'}>
                            Stop covert giving…
                        </Button>
                    ) : undefined
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
                    key !== 'review' ? (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <Button type="button" onClick={save} disabled={phase === 'sending'}>
                            {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <Check className="size-4" />}
                            {phase === 'sending' ? 'Saving…' : cur ? 'Save the review' : 'Authorise'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title={cur ? 'Review saved' : 'Covert giving authorised'}
                            blurb={`Staff can give ${o.med} covertly, as described, until ${REVIEW[f.review].on}. The house lead gets a follow-up 14 days before.`}
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
                    <div className="space-y-4">{body[key]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard this review?"
                description={cur && st?.state === 'overdue' ? 'Nothing has been saved, and covert giving stays blocked.' : 'Nothing has been saved. The current authorisation stays as it is.'}
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

/* ═════════════ Stop covert giving (RevokeCovertDialog, redesigned — the reason is kept) ═════════════ */
export function RevokeCovertDialog({ orderId, onClose, returnFocus }: { orderId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const o = allOrders(s.rt).find((x) => x.id === orderId)!;
    const cur = covertOf(o.id, s.rt, s.route.scenario)!;
    const [why, setWhy] = useState('');
    const [note, setNote] = useState('');
    const [confirm, setConfirm] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    function check() {
        const x: Record<string, string> = {};
        if (!why) x['rv-why'] = 'Choose why it’s stopping.';
        if (!note.trim()) x['rv-note'] = 'Add what happened.';
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (s.route.scenario === 'offline') return setE({ 'rv-why': cantSaveOffline });
        setConfirm(true);
    }
    function commit() {
        const reason = `${why} — ${note.trim()}`;
        s.update((rt) => ({ ...rt, covert: { ...rt.covert, [cur.id]: { ...rt.covert[cur.id], status: 'revoked', revoked: { by: me.name, on: '28 September 2026', reason } } }, events: [{ id: `ev-${Date.now()}`, at: stamp(), pid: o.pid, what: `Covert giving stopped — ${o.med} (${why.toLowerCase()})`, who: me.name }, ...rt.events] }));
        s.toast('success', `Covert giving of ${o.med} is stopped. Staff offer it openly from now; the reason is kept with the authorisation.`);
        onClose();
    }
    return (
        <>
            <Modal
                width={720}
                title={`Stop covert giving — ${o.med}, ${PEOPLE[o.pid].pref}`}
                description={`Authorised ${cur.authorised} by ${cur.gp.name} · review by ${cur.review}`}
                onClose={onClose}
                onCloseAutoFocus={restore(returnFocus)}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button variant="destructive" onClick={check}>
                            Stop covert giving
                        </Button>
                    </>
                }
            >
                <div className="space-y-2">
                    <Label id="rv-why-l">
                        Why is it stopping? <Req />
                    </Label>
                    <div id="rv-why" tabIndex={-1}>
                        <TilePicker
                            labelledBy="rv-why-l"
                            value={why || null}
                            invalid={!!e['rv-why']}
                            onChange={(k) => (setWhy(k), setE({}))}
                            tiles={[
                                { key: `${PEOPLE[o.pid].pref} can decide now`, label: `${PEOPLE[o.pid].pref} can decide now`, description: 'A new capacity assessment', icon: Brain },
                                { key: `${PEOPLE[o.pid].pref} takes it openly`, label: `${PEOPLE[o.pid].pref} takes it openly`, description: 'Covert isn’t needed any more', icon: Check },
                                { key: 'The GP ended it', label: 'The GP ended it', description: 'Attach their letter to the order', icon: FileSignature },
                                { key: 'The guardian or EPOA objects', label: 'The guardian or EPOA objects', description: 'Talk to the GP the same day', icon: Users },
                            ]}
                        />
                    </div>
                    <InputError message={e['rv-why']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="rv-note">
                        What happened <Req />
                    </Label>
                    <Textarea id="rv-note" rows={2} value={note} aria-invalid={!!e['rv-note']} placeholder="e.g. Grace has taken the tablet openly with breakfast every day this month." onChange={(ev) => (setNote(ev.target.value), setE({}))} />
                    <InputError message={e['rv-note']} />
                </div>
                <p className="text-caption">The order itself carries on — staff offer {o.med} openly. The authorisation and this reason are kept.</p>
            </Modal>
            <ConfirmDialog
                open={confirm}
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    commit();
                }}
                title={`Stop covert giving of ${o.med}?`}
                description={`From now, staff can only offer it openly. To start again, a new authorisation is needed.`}
                confirmText="Stop covert giving"
                cancelText="Keep it"
            />
        </>
    );
}

/* ═════════════ Reconcile medicines (replaces Respite’s counts-only modal — Q5) ═════════════ */
const SOURCES: Record<RecReason, string[]> = {
    movein: ['Pharmacy pack brought with them', 'GP medication list', 'Previous provider’s chart'],
    hospital: ['Discharge summary', 'Pharmacy pack from the hospital', 'GP medication list'],
    respiteIn: ['Pharmacy pack brought with them', 'GP medication list'],
    respiteOut: ['What’s being sent home', 'Our chart'],
    move: ['Chart and supplies from the other house'],
};
type Draft = RecItem & { changeTo?: string; orderId?: string };
function itemsFor(pid: PersonId, rt: ReturnType<typeof useStore>['rt'], persona: ReturnType<typeof useStore>['route']['persona']): { items: Draft[]; hidden: number } {
    const os = allOrders(rt).filter((o) => o.pid === pid && statusOf(o, rt).state !== 'stopped');
    const shown = os.filter((o) => !concealed(o, persona));
    return {
        items: shown.map((o) => {
            const v = currentOf(o, rt);
            return { key: o.id, med: `${o.med} ${o.strength}`, detail: v ? `${v.dose} · ${o.prn ? 'when needed' : v.when}` : 'Not checked yet', inPack: true, onList: true, onChart: o.id, decision: null };
        }),
        hidden: os.length - shown.length,
    };
}
function decisionsFor(i: Draft): RecDecision[] {
    return i.onChart ? ['continue', 'change', 'stop', 'ask'] : ['new', 'stop', 'ask'];
}
const decisionText = (i: Draft, d: RecDecision) => (!i.onChart && d === 'stop' ? 'Don’t start — send it back' : d === 'continue' ? 'Continue as charted' : d === 'change' ? 'Change it' : d === 'stop' ? 'Stop it' : d === 'new' ? 'Start it (a new order)' : 'Ask the GP first');

export function ReconcileDialog({ recId, onClose, returnFocus }: { recId: string; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const existing = recId === 'new' ? null : allRecs(s.rt).find((x) => x.id === recId)!;
    const rec = existing ? recOf(existing, s.rt) : null;
    const [step, setStep] = useState(existing ? 1 : 0);
    const [pid, setPid] = useState<PersonId | ''>(existing?.pid ?? '');
    const [reason, setReason] = useState<RecReason | ''>(existing?.reason ?? '');
    const [sources, setSources] = useState<string[]>(existing?.sources ?? []);
    const [file, setFile] = useState<File | null>(null);
    const [items, setItems] = useState<Draft[]>(existing ? existing.items.map((i) => ({ ...i, decision: rec!.decisions[i.key] })) : []);
    const [hidden, setHidden] = useState(0);
    const [adding, setAdding] = useState({ open: false, med: '', detail: '' });
    const [confirmAll, setConfirmAll] = useState(false);
    const [e, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done' | 'saved'>('edit');
    const [discard, setDiscard] = useState(false);
    const [offlineErr, setOfflineErr] = useState(false);
    const person = pid ? PEOPLE[pid] : null;
    const steps = [
        { key: 'who', label: 'Who and why', blurb: 'And what you checked against', icon: Users },
        { key: 'match', label: 'Match each medicine', blurb: `${items.length || '—'} medicines`, icon: GitCompare },
        { key: 'changes', label: 'What changes', blurb: 'Sent to be checked', icon: ClipboardCheck },
        { key: 'signoff', label: 'Sign off', blurb: 'A lead signs it off', icon: ShieldCheck },
    ];
    const key = steps[step].key;
    const setItem = (k: string, patch: Partial<Draft>) => (setItems((xs) => xs.map((x) => (x.key === k ? { ...x, ...patch } : x))), setE({}));
    const undecided = items.filter((i) => !i.decision);
    const dirty = !!(pid || reason || items.some((i, n) => i.decision !== (existing?.items[n] ? rec!.decisions[existing.items[n].key] : null)));
    const cdBlocked = hidden > 0 && !cdView(p);
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'who') {
            if (!pid) x['rc-pid'] = 'Choose the person.';
            if (!reason) x['rc-reason'] = 'Choose why.';
            if (!sources.length) x['rc-sources'] = 'Tick what you checked against.';
        }
        if (k === 'match') {
            const u = items.find((i) => !i.decision);
            if (u) x[`rc-d-${u.key}`] = `Choose what happens to ${u.med.split(' ')[0]}.`;
            const c = items.find((i) => i.decision === 'change' && !i.changeTo?.trim());
            if (c) x[`rc-c-${c.key}`] = 'Enter the new dose and when, as the source says.';
        }
        if (k === 'signoff') {
            if (!confirmAll) x['rc-confirm'] = 'Confirm you matched every medicine.';
        }
        return x;
    }
    function next() {
        const x = validate(key);
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        if (key === 'who' && !existing) {
            const r = itemsFor(pid as PersonId, s.rt, p);
            setItems(r.items);
            setHidden(r.hidden);
        }
        setStep(step + 1);
    }
    function persist(signOff: boolean) {
        if (s.route.scenario === 'offline') return setOfflineErr(true);
        if (signOff) {
            const x = validate('signoff');
            setE(x);
            if (Object.keys(x).length) return focusFirst(x);
        }
        setPhase('sending');
        window.setTimeout(() => {
            const id = existing?.id ?? `rec-new-${Date.now()}`;
            const decisions = Object.fromEntries(items.map((i) => [i.key, i.decision])) as Record<string, RecDecision>;
            s.update((rt) => {
                let next = { ...rt };
                const base: Reconciliation = existing ?? { id, pid: pid as PersonId, reason: reason as RecReason, sources, started: stamp(), by: me.name, due: 'before the next dose of anything it changes', status: 'open', items: items.map(({ changeTo: _c, orderId: _o, ...i }) => i) };
                if (signOff) {
                    const added: Order[] = [];
                    const versions = { ...rt.versions };
                    const stopped = { ...rt.stopped };
                    for (const i of items) {
                        if (i.decision === 'new') {
                            const med = i.med.replace(/\s\d.*$/, '');
                            const match = allergyMatch(base.pid, i.med);
                            const v: Version = { v: 1, kind: 'new', source: { type: 'reconciliation', prescriber: 'From the GP medication list', at: stamp() }, dose: i.detail.split(' at ')[0].split(' when')[0], when: i.detail.includes('when needed') ? 'When needed' : i.detail.split(' at ')[1] ? `at ${i.detail.split(' at ')[1]}` : i.detail, enteredBy: me.name, enteredAt: stamp(), state: 'waiting', allergy: match ? { match } : undefined };
                            added.push({ id: `o-rec-${i.key}-${Date.now()}`, pid: base.pid, med, strength: i.med.slice(med.length + 1), route: i.med.includes('inhaler') ? 'Inhaled' : 'By mouth', prn: i.detail.includes('when needed') ? i.detail : undefined, indication: 'From the reconciliation', start: '28 September 2026', status: 'active', versions: [v] });
                        }
                        if (i.decision === 'change' && i.onChart) {
                            const o = allOrders(rt).find((x) => x.id === i.onChart)!;
                            const vs = rt.versions[o.id] ?? o.versions;
                            const cur = currentOf(o, rt);
                            versions[o.id] = [...vs, { v: vs.length + 1, kind: 'change', source: { type: 'reconciliation', prescriber: base.sources[0], at: stamp() }, dose: i.changeTo!, when: cur?.when ?? '', changed: `${cur ? `${cur.dose} · ${cur.when}` : '—'} → ${i.changeTo}`, enteredBy: me.name, enteredAt: stamp(), state: 'waiting' }];
                        }
                        if (i.decision === 'stop' && i.onChart) stopped[i.onChart] = { on: '28 September 2026', reason: `${REC_REASON[base.reason]} reconciliation — not on ${base.sources[0].toLowerCase()}`, by: me.name };
                    }
                    next = { ...next, added: [...rt.added, ...added], versions, stopped };
                }
                next.recs = { ...rt.recs, [id]: { ...(existing ? {} : base), ...rt.recs[id], decisions, ...(signOff ? { status: 'signedOff' as const, signedOff: { by: me.name, at: stamp() } } : {}) } };
                next.events = [{ id: `ev-${Date.now()}`, at: stamp(), pid: base.pid, what: signOff ? `Reconciliation signed off — ${REC_REASON[base.reason].toLowerCase()}` : `Reconciliation saved — ${REC_REASON[base.reason].toLowerCase()}`, who: me.name }, ...rt.events];
                return next;
            });
            setPhase(signOff ? 'done' : 'saved');
        }, 700);
    }
    const counts = (d: RecDecision) => items.filter((i) => i.decision === d);
    const body: Record<string, ReactNode> = {
        who: (
            <>
                <div className="grid gap-3 sm:grid-cols-2">
                    <div className="space-y-1.5">
                        <Label htmlFor="rc-pid">
                            Person <Req />
                        </Label>
                        <Select value={pid || undefined} onValueChange={(v) => (setPid(v as PersonId), setE({}))}>
                            <SelectTrigger id="rc-pid" className="w-full" aria-invalid={!!e['rc-pid']}>
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
                        <InputError message={e['rc-pid']} />
                    </div>
                </div>
                <div className="space-y-2">
                    <Label id="rc-reason-l">
                        Why are you reconciling? <Req />
                    </Label>
                    <div id="rc-reason" tabIndex={-1}>
                        <TilePicker
                            labelledBy="rc-reason-l"
                            value={reason || null}
                            invalid={!!e['rc-reason']}
                            onChange={(k) => (setReason(k as RecReason), setSources([]), setE({}))}
                            tiles={(Object.keys(REC_REASON) as RecReason[]).map((k) => ({ key: k, label: REC_REASON[k], description: k === 'hospital' ? 'Against the discharge summary' : k === 'move' ? 'Chart and supplies move with them' : k === 'respiteOut' ? 'What goes home with them' : 'Against their pack and GP list', icon: k === 'hospital' ? FileSignature : k === 'move' ? GitCompare : Package }))}
                        />
                    </div>
                    <InputError message={e['rc-reason']} />
                </div>
                {reason ? (
                    <div id="rc-sources" tabIndex={-1} className="space-y-2">
                        <Label>
                            What did you check against? <Req />
                        </Label>
                        {SOURCES[reason].map((x) => (
                            <label key={x} className="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm">
                                <Checkbox checked={sources.includes(x)} onCheckedChange={(c) => (setSources((xs) => (c ? [...xs, x] : xs.filter((y) => y !== x))), setE({}))} />
                                {x}
                            </label>
                        ))}
                        <InputError message={e['rc-sources']} />
                    </div>
                ) : null}
                <div className="space-y-1.5">
                    <Label id="rc-file-l">Attach the list or summary (optional)</Label>
                    {file ? <StagedFileCard file={file} onRemove={() => setFile(null)} /> : <FileDropzone id="rc-file" aria-labelledby="rc-file-l" multiple={false} accept=".pdf,image/*" hint="PDF or a photo" onFiles={(x) => setFile(x[0] ?? null)} />}
                </div>
            </>
        ),
        match: (
            <>
                <KV rows={[['Person', person ? `${person.legal}${person.note ? ` · ${person.note}` : ''}` : '—'], ['Checked against', sources.join(' · ') || '—'], ['Allergies', person ? allergyText(person.id) : '—']]} />
                {hidden ? (
                    <Notice tone={cdBlocked ? 'warning' : 'info'} title={`${hidden} controlled medicine${hidden === 1 ? '' : 's'} not shown — needs controlled-medicine access`}>
                        Someone with controlled-medicine access matches {hidden === 1 ? 'it' : 'them'} and signs this off.
                    </Notice>
                ) : null}
                <ul className="divide-y rounded-lg border">
                    {items.map((i) => (
                        <li key={i.key} className="grid gap-3 px-3 py-3 md:grid-cols-[1fr_260px]">
                            <div className="min-w-0 space-y-1 text-sm">
                                <p className="font-semibold">{i.med}</p>
                                <p>{i.detail}</p>
                                <p className="flex flex-wrap gap-1.5">
                                    <StatusBadge variant={i.onChart ? 'success' : 'info'} className="rounded-[8px]">{i.onChart ? 'On our chart' : 'Not on our chart'}</StatusBadge>
                                    {!i.onList ? <StatusBadge variant="warning" className="rounded-[8px]">Not on the GP list</StatusBadge> : null}
                                    {i.allergy || (person && allergyMatch(person.id, i.med)) ? <StatusBadge variant="critical" className="rounded-[8px]">Allergy: {i.allergy ?? allergyMatch(person!.id, i.med)}</StatusBadge> : null}
                                </p>
                                {i.note ? <p className="text-caption">{i.note}</p> : null}
                            </div>
                            <div className="space-y-1.5">
                                <Label htmlFor={`rc-d-${i.key}`} className="sr-only">
                                    What happens to {i.med}
                                </Label>
                                <Select value={i.decision ?? undefined} onValueChange={(v) => setItem(i.key, { decision: v as RecDecision })}>
                                    <SelectTrigger id={`rc-d-${i.key}`} className="w-full" aria-invalid={!!e[`rc-d-${i.key}`]}>
                                        <SelectValue placeholder="Choose what happens" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {decisionsFor(i).map((d) => (
                                            <SelectItem key={d} value={d}>
                                                {decisionText(i, d)}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                                <InputError message={e[`rc-d-${i.key}`]} />
                                {i.decision === 'change' ? (
                                    <>
                                        <Label htmlFor={`rc-c-${i.key}`} className="text-[12px]">
                                            New dose and when <Req />
                                        </Label>
                                        <Input id={`rc-c-${i.key}`} value={i.changeTo ?? ''} aria-invalid={!!e[`rc-c-${i.key}`]} placeholder="As the source says" onChange={(ev) => setItem(i.key, { changeTo: ev.target.value })} />
                                        <InputError message={e[`rc-c-${i.key}`]} />
                                    </>
                                ) : null}
                                {i.decision === 'new' && (i.allergy || (person && allergyMatch(person.id, i.med))) ? <p className="text-[12px] text-muted-foreground">It can’t be checked until the prescriber confirms it’s safe.</p> : null}
                                {!i.onList && i.decision !== 'ask' && !i.decision ? <p className="text-[12px] text-muted-foreground">Not on the GP list — ask the GP first is usual.</p> : null}
                            </div>
                        </li>
                    ))}
                </ul>
                {adding.open ? (
                    <div className="grid gap-3 rounded-lg border p-3 sm:grid-cols-[1fr_1fr_auto] sm:items-end">
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-add-med">Medicine and strength</Label>
                            <Input id="rc-add-med" value={adding.med} placeholder="e.g. Vitamin D 1.25 mg capsule" onChange={(ev) => setAdding((a) => ({ ...a, med: ev.target.value }))} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="rc-add-detail">Dose and when, as the source says</Label>
                            <Input id="rc-add-detail" value={adding.detail} placeholder="e.g. 1 capsule once a month" onChange={(ev) => setAdding((a) => ({ ...a, detail: ev.target.value }))} />
                        </div>
                        <Button type="button" variant="outline" disabled={!adding.med.trim() || !adding.detail.trim()} onClick={() => (setItems((xs) => [...xs, { key: `add-${xs.length}`, med: adding.med.trim(), detail: adding.detail.trim(), inPack: true, onList: true, decision: null }]), setAdding({ open: false, med: '', detail: '' }))}>
                            Add
                        </Button>
                    </div>
                ) : (
                    <Button type="button" variant="outline" size="sm" onClick={() => setAdding((a) => ({ ...a, open: true }))}>
                        <Plus className="size-4" /> Add a medicine from the sources
                    </Button>
                )}
            </>
        ),
        changes: (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={Pill} title="New orders" onEdit={() => setStep(1)}>
                    {counts('new').length ? counts('new').map((i) => <ReviewRow key={i.key} label={i.med} value={i.detail} />) : <ReviewRow label="None" value="—" />}
                </ReviewCard>
                <ReviewCard icon={GitCompare} title="Changes and stops" onEdit={() => setStep(1)}>
                    {[...counts('change'), ...counts('stop')].length ? [...counts('change'), ...counts('stop')].map((i) => <ReviewRow key={i.key} label={i.med} value={i.decision === 'change' ? `Change to ${i.changeTo}` : decisionText(i, 'stop')} />) : <ReviewRow label="None" value="—" />}
                </ReviewCard>
                <ReviewCard icon={Calendar} title="Ask the GP first" onEdit={() => setStep(1)}>
                    {counts('ask').length ? counts('ask').map((i) => <ReviewRow key={i.key} label={i.med} value="Not given until the GP answers" />) : <ReviewRow label="None" value="—" />}
                </ReviewCard>
                <ReviewCard icon={Check} title="Continue as charted" onEdit={() => setStep(1)}>
                    <ReviewRow label="Medicines" value={`${counts('continue').length}`} />
                </ReviewCard>
                <div className="sm:col-span-2">
                    <Notice tone="neutral" title="What happens at sign-off">
                        New orders and changes become order versions waiting to be checked by someone other than you. Stops take effect straight away. Each “Ask the GP first” becomes a follow-up due before the next dose. {person?.pref ?? 'The person'}’s support is flagged for reassessment.
                    </Notice>
                </div>
            </div>
        ),
        signoff: (
            <>
                <KV rows={[['Person', person?.legal ?? '—'], ['Why', reason ? REC_REASON[reason] : '—'], ['Started', existing ? `${existing.started} by ${existing.by}` : `Now, by ${me.name}`], ['Due', existing?.due ?? 'before the next dose of anything it changes'], ['Medicines', `${items.length}${hidden ? ` + ${hidden} controlled not shown` : ''} · ${undecided.length ? `${undecided.length} still to decide` : 'all decided'}`]]} />
                {cdBlocked ? (
                    <Notice tone="warning" title="Someone with controlled-medicine access signs this off">
                        You can save it; a lead with controlled-medicine access matches the controlled medicine and signs it off.
                    </Notice>
                ) : !canCheck(p) ? (
                    <Notice tone="warning" title="A lead signs this off">
                        You can save it; a house lead, clinical lead or manager signs it off.
                    </Notice>
                ) : (
                    <>
                        <label className="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm">
                            <Checkbox id="rc-confirm" checked={confirmAll} onCheckedChange={(c) => (setConfirmAll(!!c), setE({}))} aria-invalid={!!e['rc-confirm'] || undefined} />
                            I matched every medicine against {sources.length > 1 ? 'the sources' : 'the source'}, and what happens to each one is right
                        </label>
                        <InputError message={e['rc-confirm']} />
                    </>
                )}
                {offlineErr ? (
                    <Notice tone="critical" title="Not saved">
                        {cantSaveOffline}
                    </Notice>
                ) : null}
            </>
        ),
    };
    const canSign = canCheck(p) && !cdBlocked;
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase !== 'edit' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={`Reconcile medicines${person ? ` — ${person.pref}` : ''}`}
                description="Match every medicine against the sources, send the changes to be checked, then sign it off."
                railIcon={GitCompare}
                railTitle="Reconcile medicines"
                railSub={person ? `${person.pref} · ${reason ? REC_REASON[reason].toLowerCase() : ''}` : 'Moving in, hospital, respite or a move'}
                steps={steps.map((x, i) => ({ ...x, disabled: i > step || phase === 'sending' || (!!existing && i === 0) }))}
                stepIndex={step}
                onStepClick={(i) => i <= step && !(existing && i === 0) && setStep(i)}
                pct={Math.round(((step + (phase === 'done' ? 1 : 0)) / steps.length) * 100)}
                pctLabel="Completeness"
                footerStart={
                    step > (existing ? 1 : 0) && phase !== 'sending' ? (
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
                    key !== 'signoff' ? (
                        <Button type="button" onClick={next}>
                            Continue <ChevronRight className="size-4" />
                        </Button>
                    ) : (
                        <span className="flex flex-wrap items-center gap-2">
                            <Button type="button" variant="outline" onClick={() => persist(false)} disabled={phase === 'sending'}>
                                Save — sign off later
                            </Button>
                            {canSign ? (
                                <Button type="button" onClick={() => persist(true)} disabled={phase === 'sending'}>
                                    {phase === 'sending' ? <Loader2 className="size-4 animate-spin motion-reduce:animate-none" /> : <ShieldCheck className="size-4" />}
                                    {phase === 'sending' ? 'Saving…' : 'Sign it off'}
                                </Button>
                            ) : null}
                        </span>
                    )
                }
                success={
                    phase === 'done' || phase === 'saved' ? (
                        <WizardSuccessPane
                            title={phase === 'done' ? 'Reconciliation signed off' : 'Reconciliation saved'}
                            blurb={
                                phase === 'done'
                                    ? `${counts('new').length + counts('change').length} order${counts('new').length + counts('change').length === 1 ? '' : 's'} waiting to be checked in To check${counts('stop').length ? ` · ${counts('stop').length} stopped` : ''}${counts('ask').length ? ` · ${counts('ask').length} follow-up${counts('ask').length === 1 ? '' : 's'} to ask the GP` : ''}. ${person?.pref}’s support is flagged for reassessment.`
                                    : `It stays open in Reconciliation. It’s due ${existing?.due ?? 'before the next dose of anything it changes'}.`
                            }
                            actions={
                                <Button type="button" autoFocus onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1080px)"
                maxHeight="min(88vh, 820px)"
            >
                <WizardStepPane>
                    <div className="space-y-4">{body[key]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog
                open={discard}
                onClose={() => setDiscard(false)}
                onConfirm={() => {
                    setDiscard(false);
                    onClose();
                }}
                title="Discard your changes?"
                description={existing ? 'What was saved before stays; nothing new is saved.' : 'Nothing has been saved.'}
                confirmText="Discard"
                cancelText="Keep going"
            />
        </>
    );
}

/* A signed-off reconciliation, or one your role can only read. */
export function ReconcileViewDialog({ recId, onClose }: { recId: string; onClose: () => void }) {
    const s = useStore();
    const r = recOf(allRecs(s.rt).find((x) => x.id === recId)!, s.rt);
    const person = PEOPLE[r.pid];
    return (
        <Modal width={900} title={`${REC_REASON[r.reason]} — ${person.pref} ${person.surname}`} description={r.status === 'signedOff' ? `Signed off ${r.signedOff!.at} by ${r.signedOff!.by}` : `Open · due ${r.due}`} onClose={onClose}>
            <KV rows={[['Checked against', r.sources.join(' · ')], ['Started', `${r.started} by ${r.by}`]]} />
            <ul className="divide-y rounded-lg border">
                {r.items.map((i) => (
                    <li key={i.key} className="flex flex-wrap items-start justify-between gap-3 px-3 py-2.5 text-sm">
                        <span className="min-w-0">
                            <span className="block font-semibold">{i.med}</span>
                            <span className="block text-caption">{i.detail}</span>
                        </span>
                        <StatusBadge variant={r.decisions[i.key] ? 'neutral' : 'warning'} className="rounded-[8px]">
                            {r.decisions[i.key] ? decisionText(i, r.decisions[i.key]!) : 'Still to decide'}
                        </StatusBadge>
                    </li>
                ))}
            </ul>
            {r.status === 'open' ? <p className="text-caption">A house lead, clinical lead or manager completes and signs off reconciliations.</p> : null}
        </Modal>
    );
}
