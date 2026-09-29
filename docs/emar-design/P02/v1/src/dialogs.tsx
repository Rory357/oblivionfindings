/* P02 dialogs, opened by `?dlg=` so every state deep-links. Simple dialogs use
 * P01's Modal (the Fleet Settings `Modal` anatomy at the POPUP width tokens);
 * multi-section views and edits use the real WizardShell; consequential saves
 * use the real ConfirmDialog (destructive where a check is loosened — note the
 * known purple-destructive bug, fixed by PR #15, is left as the real component
 * renders it). Recording itself is never here: it is P01's dialog. */
import ConfirmDialog from '@/components/confirm-dialog';
import { DatePicker } from '@/components/fleet-assets/maintenance/date-picker';
import { DateTimeField } from '@/components/fleet-assets/maintenance/date-time-field';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { StatusBadge } from '@/components/ui/status-badge';
import { Switch } from '@/components/ui/switch';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import {
    Activity,
    ArrowRight,
    BellOff,
    Brain,
    CheckCircle2,
    ClipboardCheck,
    ClipboardList,
    FileText,
    Hand,
    History as HistoryIcon,
    Image,
    LogOut,
    MessageSquare,
    PauseCircle,
    Pill,
    Plus,
    Printer,
    RefreshCw,
    ShieldAlert,
    Stethoscope,
    Syringe,
    Trash2,
    TriangleAlert,
    UserCheck,
    Users,
    X, ArrowUpRight } from 'lucide-react';
import { useRef, useState, type ReactNode } from 'react';
import { DAYS, DRIVER, EVENTS, FACTS, HOUSES, INTERACTIONS, P02_PERSONAS, medByKey, medsOf, recorderIn01, type Admin, type ChartAlert, type HOutcome, type PersonId } from './data';
import { OUTCOME_LABEL, useAdmins, useToday } from './model';
import { RULES, PEOPLE } from './p01/data';
import { Modal } from './p01/dialogs';
import { useOpen } from './p01/doses';
import { DoseBadge, IdentityHeader, KV, MedicinePhoto, Notice, NotConfigured, SUPPORT, SupportChip, TilePicker, type Tile } from './p01/ui';
import { returnFocus, useDlg, useP02 } from './store';
import { ALERT_TYPE } from './pages/tab-allergies';
import { inrStatus, targetText } from './pages/tab-clinical';
import { changeText } from './pages/tab-history';
import { CONCEALED, useAllergy } from './ui';

const focusBack = (e: Event) => {
    const t = returnFocus();
    if (t) {
        e.preventDefault();
        t.focus();
    }
};
function Field({ id, label, required, error, hint, children }: { id: string; label: string; required?: boolean; error?: string; hint?: string; children: ReactNode }) {
    return (
        <div className="grid gap-1.5">
            <Label htmlFor={id}>
                {label}
                {required ? <span className="text-status-critical"> *</span> : null}
            </Label>
            {children}
            {hint ? (
                <p id={`${id}-hint`} className="text-caption">
                    {hint}
                </p>
            ) : null}
            <InputError message={error} className="mt-0" />
        </div>
    );
}
const focusFirst = (errors: Record<string, string | undefined>, order: string[]) => {
    const first = order.find((k) => errors[k]);
    if (first) window.setTimeout(() => document.getElementById(first)?.focus(), 30);
};

/* ───────────── host ───────────── */
export function P02DialogHost() {
    const s = useP02();
    const { spec, close } = useDlg();
    if (!spec || !s.pid) return null;
    const [kind, arg, extra] = spec.split(':');
    const pid = s.pid;
    switch (kind) {
        case 'med':
            return <MedicineDialog pid={pid} medKey={arg} section={extra} onClose={close} />;
        case 'dose':
            return <DoseRecordDialog pid={pid} id={arg} onClose={close} />;
        case 'correct':
            return <CorrectionWizard pid={pid} id={arg} onClose={close} />;
        case 'correction':
            return <CorrectionReview pid={pid} id={arg} onClose={close} />;
        case 'warnings':
            return <WarningsDialog pid={pid} onClose={close} />;
        case 'alert':
            return <AlertDialog pid={pid} id={arg} onClose={close} />;
        case 'resolve':
            return <ResolveAlert id={arg} onClose={close} />;
        case 'onopen-off':
            return <OnOpenOff id={arg} onClose={close} />;
        case 'pause':
            return <PauseAlerts pid={pid} onClose={close} />;
        case 'interaction':
            return <InteractionDialog id={arg} onClose={close} />;
        case 'inr':
            return arg === 'new' ? <RecordInrDialog pid={pid} onClose={close} /> : <InrDetail pid={pid} id={arg} onClose={close} />;
        case 'inr-error':
            return <InrError id={arg} onClose={close} />;
        case 'check':
            return <DriverCheckDialog onClose={close} />;
        case 'finish':
            return <FinishDriver onClose={close} />;
        case 'driver':
            return <DriverWizard pid={pid} onClose={close} />;
        case 'event':
            return <EventDialog pid={pid} id={arg} onClose={close} />;
        case 'print':
            return <PrintDialog pid={pid} onClose={close} />;
        case 'allergy-review':
            return <AllergyReviewDialog pid={pid} onClose={close} />;
        default:
            return null;
    }
}

/* ───────────── Medicine details (MedicationDetailDialog, redesigned) ───────────── */
const MED_SECTIONS = [
    { key: 'order', label: 'Order', blurb: 'What was prescribed', icon: ClipboardList },
    { key: 'how', label: 'How it’s given', blurb: 'Support, rules, witness', icon: Hand },
    { key: 'photo', label: 'Pack photo & supply', blurb: 'Check the label', icon: Image },
    { key: 'doses', label: 'Recent doses', blurb: 'Last 7 days', icon: HistoryIcon },
] as const;
function MedicineDialog({ pid, medKey, section, onClose }: { pid: PersonId; medKey: string; section?: string; onClose: () => void }) {
    const s = useP02();
    const open01 = useOpen();
    const m = medByKey(medKey);
    const admins = useAdmins(pid).filter((a) => a.med === medKey).slice(0, 6);
    const today = useToday(pid).filter((c) => c.med.key === medKey && (c.state === 'due' || c.state === 'late') && c.dose);
    const [i, setI] = useState(Math.max(0, MED_SECTIONS.findIndex((x) => x.key === section)));
    if (!m || (m.cd && !s.cdView))
        return (
            <Modal title={CONCEALED.name} description={CONCEALED.subline} onClose={onClose} onCloseAutoFocus={focusBack}>
                <Notice tone="neutral" title={CONCEALED.subline}>
                    {CONCEALED.ask}
                </Notice>
            </Modal>
        );
    const photo = m.photos.find((p) => p.state !== 'replaced');
    const sec = MED_SECTIONS[i].key;
    return (
        <WizardShell
            open
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            title={`${m.name} ${m.strength} — ${PEOPLE[pid].pref}`}
            description="Medicine details: order, how it’s given, pack photo and recent doses"
            railIcon={Pill}
            railTitle={m.name}
            railSub={`${m.strength} · ${PEOPLE[pid].pref}`}
            steps={MED_SECTIONS}
            stepIndex={i}
            onStepClick={setI}
            headerLabel={MED_SECTIONS[i].label}
            sequential={false}
            pct={null}
            footerStart={
                <Button variant="outline" onClick={onClose}>
                    Close
                </Button>
            }
            footerEnd={
                <>
                    {s.can('orders.manage') ? (
                        <Button variant="outline" onClick={() => s.go('/emar/prescriptions', { client_id: String(FACTS[pid].clientId) })}>
                            Change or stop in Orders &amp; reviews <ArrowUpRight className="size-4" aria-hidden="true" />
                        </Button>
                    ) : null}
                    {today[0] && recorderIn01(s.route.persona) ? (
                        <Button onClick={() => (onClose(), window.setTimeout(() => open01(`record:${today[0].dose!.id}`, { from: 'mar' }), 30))}>
                            <Pill className="size-4" /> Record the {today[0].slot} dose
                        </Button>
                    ) : null}
                </>
            }
        >
            <WizardStepPane key={sec}>
                <div className="space-y-4 p-6">
                    {sec === 'order' ? (
                        <>
                            {m.status === 'awaiting' ? (
                                <Notice tone="warning" title="This order is waiting to be checked">
                                    Someone who can check orders must verify it before it’s given. It can still be recorded as withheld.
                                </Notice>
                            ) : null}
                            {m.status === 'stopped' ? (
                                <Notice tone="neutral" title={`Stopped ${m.stopped!.on} by ${m.stopped!.by}`}>
                                    Reason recorded: “{m.stopped!.reason}”.
                                </Notice>
                            ) : null}
                            <KV
                                rows={[
                                    ['Medicine', `${m.name} ${m.strength}`],
                                    ['Amount', m.amount],
                                    ['Route', m.route],
                                    ['When', m.kind === 'prn' ? 'As needed' : m.when],
                                    ['Instructions', m.instructions],
                                    ['Prescriber', m.prescriber],
                                    ['Started', m.started],
                                    ['Order', m.order],
                                    ['Review', m.review],
                                ]}
                            />
                        </>
                    ) : null}
                    {sec === 'how' ? (
                        <KV
                            rows={[
                                ['Support', <span key="s" className="flex flex-wrap items-center gap-2"><SupportChip support={m.support} /><span className="text-subtle">{SUPPORT[m.support].desc} · from the support plan</span></span>],
                                ...(m.cd ? ([['Witness', 'Controlled medicine — a second person witnesses with their witness PIN']] as [ReactNode, ReactNode][]) : []),
                                ...(m.covert ? ([['Covert plan', 'Given in a spoon of yoghurt, as set out in Grace’s covert plan (authorised by Dr Lena Chen and Grace’s welfare guardian, 1 June 2026). Review due 1 December 2026.']] as [ReactNode, ReactNode][]) : []),
                                ...(m.rules ? ([['Medication rules', <ul key="r" className="list-disc space-y-1 pl-4">{[RULES.mr1, RULES.mr2].map((r) => <li key={r.id}>{r.sentence} <span className="text-caption">({r.by})</span></li>)}</ul>]] as [ReactNode, ReactNode][]) : []),
                                ...(m.inr ? ([['Dose by INR', <span key="i">The dose follows the latest INR instruction. <Button variant="link" className="h-auto p-0" onClick={() => s.set({ tab: 'clinical', view: undefined, dlg: undefined })}>Open INR results <ArrowUpRight className="size-4" aria-hidden="true" /></Button></span>]] as [ReactNode, ReactNode][]) : []),
                                ['Allergy check', 'Checked against the allergy list every time a dose is recorded'],
                            ]}
                        />
                    ) : null}
                    {sec === 'photo' ? (
                        <>
                            {photo ? <MedicinePhoto med={m.name} photo={{ state: photo.state === 'changed' ? 'changed' : 'current', taken: photo.taken, pack: photo.pack, nowPack: 'the pack supplied on 21 Sep 2026 is a different brand', file: photo.file }} /> : <MedicinePhoto med={m.name} photo={{ state: 'none' }} />}
                            <KV rows={[['Supply', m.supply], ['Photo history', `${m.photos.length} ${m.photos.length === 1 ? 'photo' : 'photos'}`]]} />
                            {m.photos.length ? (
                                <Button variant="link" className="h-auto p-0" onClick={() => s.set({ tab: 'medicines', view: 'photos', med: m.key, dlg: undefined })}>
                                    Open the photo history <ArrowUpRight className="size-4" aria-hidden="true" />
                                </Button>
                            ) : null}
                        </>
                    ) : null}
                    {sec === 'doses' ? (
                        admins.length ? (
                            <>
                                <ul className="divide-y rounded-lg border">
                                    {admins.map((a) => (
                                        <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 px-3 py-2 text-sm">
                                            <span>
                                                {a.dayLabel} · {a.at} · {a.by}
                                            </span>
                                            <DoseBadge state={a.outcome} size="sm" />
                                        </li>
                                    ))}
                                </ul>
                                <Button variant="link" className="h-auto p-0" onClick={() => s.set({ tab: 'history', view: undefined, med: m.key, dlg: undefined })}>
                                    Open this medicine’s history <ArrowUpRight className="size-4" aria-hidden="true" />
                                </Button>
                            </>
                        ) : (
                            <p className="text-subtle">No doses recorded in the last 7 days.</p>
                        )
                    ) : null}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ───────────── a dose record, with its correction chain ───────────── */
function useAdmin(pid: PersonId, id: string) {
    return useAdmins(pid).find((a) => a.id === id) ?? null;
}
function DoseRecordDialog({ pid, id, onClose }: { pid: PersonId; id: string; onClose: () => void }) {
    const s = useP02();
    const dlg = useDlg();
    const a = useAdmin(pid, id);
    if (!a) return null;
    const m = medByKey(a.med);
    if (m.cd && !s.cdView)
        return (
            <Modal title={CONCEALED.name} description={CONCEALED.subline} onClose={onClose} onCloseAutoFocus={focusBack}>
                <Notice tone="neutral" title={CONCEALED.subline}>{CONCEALED.ask}</Notice>
            </Modal>
        );
    const c = a.correction;
    const day = DAYS.find((d) => d.iso === a.day)!;
    return (
        <Modal
            width={720}
            title={`${m.name} · ${PEOPLE[pid].pref}`}
            description={`${m.strength} · ${a.prn ? 'as-needed dose' : `${a.slot} dose`} · ${day.long} · times in NZDT`}
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            footer={
                <>
                    {s.can('correct') && !m.cd && c?.status !== 'pending' && a.outcome !== 'selfmanaged' ? (
                        <Button variant="outline" onClick={() => dlg.open(`correct:${a.id}`)}>
                            <RefreshCw className="size-4" /> Request a correction
                        </Button>
                    ) : null}
                    {c?.status === 'pending' && s.can('correct') ? (
                        <Button variant="outline" onClick={() => dlg.open(`correction:${a.id}`)}>
                            Review the correction
                        </Button>
                    ) : null}
                    <Button onClick={onClose} autoFocus>
                        Close
                    </Button>
                </>
            }
        >
            <IdentityHeader pid={pid} support={m.support} compact />
            <KV
                rows={[
                    ['Outcome', <span key="o" className="flex flex-wrap items-center gap-2"><DoseBadge state={a.outcome} size="sm" />{c?.status === 'approved' ? <StatusBadge variant="info" size="sm" className="rounded-[8px]">Corrected</StatusBadge> : null}</span>],
                    ['Time', a.at],
                    ['Recorded by', a.by],
                    ...(a.second ? ([['Second person', a.second]] as [ReactNode, ReactNode][]) : []),
                    ['Amount', a.amount ?? '—'],
                    ...(a.reading ? ([[a.reading.label, a.reading.value]] as [ReactNode, ReactNode][]) : []),
                    ...(a.note ? ([['Note', a.note]] as [ReactNode, ReactNode][]) : []),
                    ['House', HOUSES[a.house]],
                    ['Order', m.order],
                ]}
            />
            {c ? (
                <section aria-label="Correction" className="space-y-2">
                    <h3 className="text-sm font-semibold">Correction</h3>
                    <ol className="space-y-2 border-l-2 border-border pl-4 text-sm">
                        <li>
                            <span className="font-medium">First recorded</span> — {OUTCOME_LABEL[c.from.outcome]} {c.from.at}
                            {c.from.note ? ` · “${c.from.note}”` : ''}
                        </li>
                        <li>
                            <span className="font-medium">Correction asked</span> by {c.requestedBy}, {c.requestedAt} — {OUTCOME_LABEL[c.to.outcome]} {c.to.at} · “{c.reason}”
                        </li>
                        <li>
                            {c.status === 'pending' ? (
                                <span className="font-semibold text-status-warning">Waiting for a second person to approve it — the chart still shows the first record</span>
                            ) : c.status === 'approved' ? (
                                <span>
                                    <span className="font-medium">Approved</span> by {c.decidedBy}, {c.decidedAt} — the chart shows the corrected record; the first record stays here
                                </span>
                            ) : (
                                <span>
                                    <span className="font-medium">Declined</span> by {c.decidedBy}, {c.decidedAt} — “{c.rejectReason}”. The first record stands.
                                </span>
                            )}
                        </li>
                    </ol>
                </section>
            ) : null}
        </Modal>
    );
}

/* ───────────── Request a correction (WizardShell: what, why, review, success) ───────────── */
const OUT_TILES: Tile[] = [
    { key: 'given', label: 'Given', description: 'The dose was given or taken', icon: CheckCircle2 },
    { key: 'refused', label: 'Refused', description: 'The person said no', icon: X },
    { key: 'withheld', label: 'Withheld', description: 'Staff didn’t give it, with a reason', icon: PauseCircle },
    { key: 'away', label: 'Away', description: 'The person was out', icon: LogOut },
];
const C_STEPS = [
    { key: 'what', label: 'What to change', blurb: 'Outcome, time, note', icon: RefreshCw },
    { key: 'why', label: 'Why', blurb: 'The reason', icon: MessageSquare },
    { key: 'review', label: 'Review & send', blurb: 'For a second person', icon: ClipboardCheck },
] as const;
function CorrectionWizard({ pid, id, onClose }: { pid: PersonId; id: string; onClose: () => void }) {
    const s = useP02();
    const a = useAdmin(pid, id);
    const me = P02_PERSONAS[s.route.persona];
    const [i, setI] = useState(0);
    const [done, setDone] = useState(false);
    const [f, setF] = useState<{ outcome: HOutcome | null; time: string; note: string; reason: string }>({ outcome: null, time: a ? `${a.day}T${hm(a.at)}` : '', note: '', reason: '' });
    const [err, setErr] = useState<Record<string, string | undefined>>({});
    const [dirtyGuard, setDirtyGuard] = useState(false);
    if (!a) return null;
    const m = medByKey(a.med);
    const reasonNeeded = true; // every record here is more than 30 minutes old (today’s rule)
    const changed = (f.outcome && f.outcome !== a.outcome) || f.time !== `${a.day}T${hm(a.at)}` || f.note.trim();
    const validate = (step: number) => {
        const e: Record<string, string | undefined> = {};
        if (step >= 0 && !changed) e['c-outcome'] = 'Choose what should change — the outcome, the time or a note.';
        if (step >= 0 && f.time && !f.time.startsWith(a.day)) e['c-time'] = `The time must stay on ${a.dayLabel}.`;
        if (step >= 1 && reasonNeeded && !f.reason.trim()) e['c-reason'] = 'Say why the record was wrong. It was saved more than 30 minutes ago.';
        setErr(e);
        focusFirst(e, ['c-outcome', 'c-time', 'c-reason']);
        return !Object.keys(e).some((k) => e[k]);
    };
    const toOutcome = f.outcome ?? a.outcome;
    const toAt = f.time ? fmt12(f.time.split('T')[1]) : a.at;
    const send = () => {
        s.setCorrection(a.id, { id: `c-${Date.now()}`, status: 'pending', requestedBy: me.name, requestedAt: 'Mon 28 Sep, 9:12 am', reason: f.reason.trim(), from: { outcome: a.outcome, at: a.at, note: a.note }, to: { outcome: toOutcome, at: toAt, note: f.note.trim() || undefined } });
        setDone(true);
    };
    return (
        <>
            <WizardShell
                open
                onClose={() => (changed || f.reason ? setDirtyGuard(true) : onClose())}
                onCloseAutoFocus={focusBack}
                title="Request a correction"
                description="Change a dose record. The original is kept and a second person approves the change."
                railIcon={RefreshCw}
                railTitle="Request a correction"
                railSub={`${m.name} · ${a.dayLabel} ${a.slot ?? a.at}`}
                steps={C_STEPS}
                stepIndex={i}
                onStepClick={(n) => (n <= i || validate(i) ? setI(n) : null)}
                pct={Math.round(((changed ? 1 : 0) + (f.reason.trim() ? 1 : 0)) * 50)}
                footerStart={
                    <Button variant="outline" onClick={() => (i ? setI(i - 1) : changed || f.reason ? setDirtyGuard(true) : onClose())}>
                        {i ? 'Back' : 'Cancel'}
                    </Button>
                }
                footerEnd={
                    i < 2 ? (
                        <Button onClick={() => validate(i) && setI(i + 1)}>
                            Continue <ArrowRight className="size-4" />
                        </Button>
                    ) : (
                        <Button onClick={() => validate(2) && send()}>Send for approval</Button>
                    )
                }
                success={
                    done ? (
                        <WizardSuccessPane
                            title="Sent for approval"
                            blurb={`Someone other than you approves or declines it. Until then the chart shows the first record, marked “Correction waiting”.`}
                            actions={
                                <>
                                    <Button variant="outline" onClick={() => s.set({ tab: 'history', view: 'corrections', dlg: undefined })}>
                                        View corrections
                                    </Button>
                                    <Button onClick={onClose}>Done</Button>
                                </>
                            }
                        />
                    ) : undefined
                }
            >
                <WizardStepPane key={i}>
                    <div className="space-y-4 p-6">
                        <Notice tone="neutral" title={`The record now: ${OUTCOME_LABEL[a.outcome]} ${a.at} · ${a.by}`}>
                            {a.note ? `Note: “${a.note}”. ` : ''}The original is never overwritten — it stays in History with your correction beside it.
                        </Notice>
                        {i === 0 ? (
                            <>
                                <div className="grid gap-1.5">
                                    <Label id="c-outcome-label">What should the outcome be?</Label>
                                    <div id="c-outcome" tabIndex={-1} className="outline-none">
                                        <TilePicker tiles={OUT_TILES.map((t) => ({ ...t, disabled: t.key === a.outcome ? 'This is what it says now' : null }))} value={f.outcome} onChange={(k) => setF({ ...f, outcome: k as HOutcome })} labelledBy="c-outcome-label" invalid={!!err['c-outcome']} />
                                    </div>
                                    <InputError message={err['c-outcome']} />
                                </div>
                                <DateTimeField id="c-time" label="Time it happened" value={f.time} onChange={(v) => setF({ ...f, time: v })} error={err['c-time']} hint={`Must stay on ${a.dayLabel}. Leave it if the time was right.`} />
                                <Field id="c-note" label="Note (optional)" hint="What actually happened, in a few words.">
                                    <Textarea id="c-note" value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} placeholder="e.g. Taken after lunch" />
                                </Field>
                            </>
                        ) : null}
                        {i === 1 ? (
                            <Field id="c-reason" label="Why was the record wrong?" required error={err['c-reason']} hint="Needed because it was saved more than 30 minutes ago. The approver sees this.">
                                <Textarea id="c-reason" value={f.reason} onChange={(e) => setF({ ...f, reason: e.target.value })} placeholder="e.g. I recorded the refusal before Aroha had lunch" aria-invalid={!!err['c-reason']} />
                            </Field>
                        ) : null}
                        {i === 2 ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <ReviewCard icon={FileText} title="Now" onEdit={() => setI(0)}>
                                    <ReviewRow label="Outcome" value={OUTCOME_LABEL[a.outcome]} />
                                    <ReviewRow label="Time" value={a.at} />
                                    <ReviewRow label="Note" value={a.note ?? '—'} />
                                </ReviewCard>
                                <ReviewCard icon={RefreshCw} title="After approval" onEdit={() => setI(0)}>
                                    <ReviewRow label="Outcome" value={OUTCOME_LABEL[toOutcome]} />
                                    <ReviewRow label="Time" value={toAt} />
                                    <ReviewRow label="Note" value={f.note.trim() || '—'} />
                                </ReviewCard>
                                <ReviewCard icon={MessageSquare} title="Why" span onEdit={() => setI(1)}>
                                    <ReviewRow label="Reason" value={f.reason.trim() || '—'} />
                                    <ReviewRow label="Approved by" value="Someone other than you, with correction access" />
                                </ReviewCard>
                            </div>
                        ) : null}
                    </div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog open={dirtyGuard} onClose={() => setDirtyGuard(false)} onConfirm={() => (setDirtyGuard(false), onClose())} title="Discard this correction?" description="Nothing has been sent. The dose record stays as it is." confirmText="Discard" variant="destructive" />
        </>
    );
}
const hm = (label: string) => {
    const m = /^(\d{1,2}):(\d{2})\s*(am|pm)$/i.exec(label.trim());
    if (!m) return '09:00';
    let h = Number(m[1]) % 12;
    if (m[3].toLowerCase() === 'pm') h += 12;
    return `${String(h).padStart(2, '0')}:${m[2]}`;
};
const fmt12 = (t: string) => {
    const [H, M] = t.split(':').map(Number);
    return `${H % 12 || 12}:${String(M).padStart(2, '0')} ${H < 12 ? 'am' : 'pm'}`;
};

/* ───────────── Review a correction (CorrectionsReviewDialog, redesigned) ───────────── */
function CorrectionReview({ pid, id, onClose }: { pid: PersonId; id: string; onClose: () => void }) {
    const s = useP02();
    const a = useAdmin(pid, id);
    const me = P02_PERSONAS[s.route.persona];
    const [declining, setDeclining] = useState(false);
    const [reason, setReason] = useState('');
    const [err, setErr] = useState<string>();
    const [approve, setApprove] = useState(false);
    if (!a?.correction) return null;
    const c = a.correction;
    const m = medByKey(a.med);
    const mine = c.requestedBy === me.name;
    const canDecide = c.status === 'pending' && s.can('correct') && !mine;
    return (
        <>
            <Modal
                width={720}
                title={`Correction · ${m.name} · ${a.dayLabel} ${a.slot ?? ''}`}
                description={`Asked for by ${c.requestedBy}, ${c.requestedAt}. ${c.status === 'pending' ? 'A second person approves or declines it.' : ''}`}
                onClose={onClose}
                onCloseAutoFocus={focusBack}
                footer={
                    canDecide ? (
                        declining ? (
                            <>
                                <Button variant="outline" onClick={() => setDeclining(false)}>
                                    Back
                                </Button>
                                <Button variant="destructive" onClick={() => (reason.trim() ? (s.setCorrection(a.id, { ...c, status: 'rejected', decidedBy: me.name, decidedAt: 'Mon 28 Sep, 9:12 am', rejectReason: reason.trim() }), s.toast('success', 'Correction declined — the first record stands.'), onClose()) : (setErr('Say why, so the person who asked knows what to do.'), document.getElementById('cr-reason')?.focus()))}>
                                    Decline correction
                                </Button>
                            </>
                        ) : (
                            <>
                                <Button variant="outline" onClick={() => setDeclining(true)}>
                                    Decline…
                                </Button>
                                <Button onClick={() => setApprove(true)}>Approve correction</Button>
                            </>
                        )
                    ) : (
                        <Button onClick={onClose} autoFocus>
                            Close
                        </Button>
                    )
                }
            >
                <div className="grid gap-3 sm:grid-cols-2">
                    <ReviewCard icon={FileText} title="First recorded">
                        <ReviewRow label="Outcome" value={OUTCOME_LABEL[c.from.outcome]} />
                        <ReviewRow label="Time" value={c.from.at} />
                        <ReviewRow label="By" value={a.by} />
                        <ReviewRow label="Note" value={c.from.note ?? '—'} />
                    </ReviewCard>
                    <ReviewCard icon={RefreshCw} title="Change asked for">
                        <ReviewRow label="Outcome" value={OUTCOME_LABEL[c.to.outcome]} />
                        <ReviewRow label="Time" value={c.to.at} />
                        <ReviewRow label="Note" value={c.to.note ?? '—'} />
                        <ReviewRow label="Reason" value={c.reason} />
                    </ReviewCard>
                </div>
                <p className="text-caption">Evidence attached: none. {changeText(c)}.</p>
                {c.status === 'approved' ? <Notice tone="success" title={`Approved by ${c.decidedBy}, ${c.decidedAt}`}>The chart shows the corrected record. The first record stays in History.</Notice> : null}
                {c.status === 'rejected' ? <Notice tone="neutral" title={`Declined by ${c.decidedBy}, ${c.decidedAt}`}>“{c.rejectReason}” The first record stands.</Notice> : null}
                {c.status === 'pending' && mine ? (
                    <Notice tone="warning" title="You asked for this correction">
                        Someone other than you must approve or decline it (two-person rule). House leads and staff on shift with correction access can. Jordan Tipene is on shift today.
                    </Notice>
                ) : null}
                {c.status === 'pending' && !s.can('correct') ? <Notice tone="neutral" title="Read only">Approving corrections needs correction access.</Notice> : null}
                {declining ? (
                    <Field id="cr-reason" label="Why are you declining it?" required error={err} hint="The person who asked sees this.">
                        <Textarea id="cr-reason" autoFocus value={reason} onChange={(e) => (setReason(e.target.value), setErr(undefined))} placeholder="e.g. The round sheet shows it was given at 12:15 pm" aria-invalid={!!err} />
                    </Field>
                ) : null}
            </Modal>
            <ConfirmDialog
                open={approve}
                onClose={() => setApprove(false)}
                onConfirm={() => (s.setCorrection(a.id, { ...c, status: 'approved', decidedBy: me.name, decidedAt: 'Mon 28 Sep, 9:12 am' }), setApprove(false), s.toast('success', `Correction approved — the chart now shows ${OUTCOME_LABEL[c.to.outcome]} ${c.to.at}.`), onClose())}
                title="Approve this correction?"
                description={`The chart will show ${OUTCOME_LABEL[c.to.outcome]} ${c.to.at} instead of ${OUTCOME_LABEL[c.from.outcome]} ${c.from.at}. The first record stays in History with both names.`}
                confirmText="Approve correction"
            />
        </>
    );
}

/* ───────────── Chart alerts to read (WarningsDialog, redesigned) ───────────── */
function WarningsDialog({ pid, onClose }: { pid: PersonId; onClose: () => void }) {
    const s = useP02();
    const me = P02_PERSONAS[s.route.persona];
    const list = s.alerts.filter((a) => a.pid === pid && !a.resolved && (s.cdView || !a.cd));
    const toRead = list.filter((a) => a.onOpen);
    return (
        <Modal
            title={`Before you record for ${PEOPLE[pid].pref}`}
            description={toRead.length ? 'Read these chart alerts. Reading is recorded against your name, once a day.' : 'Current chart alerts.'}
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Close
                    </Button>
                    {toRead.length ? (
                        <Button autoFocus onClick={() => (s.readAlerts(pid), s.toast('success', `Recorded: ${me.name} read ${toRead.length === 1 ? 'the chart alert' : 'the chart alerts'} at 9:12 am.`), onClose())}>
                            I’ve read {toRead.length === 1 ? 'it' : 'them'}
                        </Button>
                    ) : null}
                </>
            }
        >
            {list.map((a) => (
                <Notice key={a.id} tone={a.type === 'warfarin' ? 'critical' : 'warning'} title={a.title}>
                    {a.detail} <span className="text-caption">· {ALERT_TYPE[a.type]} · added {a.on} by {a.by}</span>
                </Notice>
            ))}
        </Modal>
    );
}

/* ───────────── Add / edit a chart alert (ManageAlertsDialog, redesigned) ───────────── */
const TYPE_TILES: Tile[] = [
    { key: 'warfarin', label: 'Warfarin / INR', description: 'Dose depends on INR results', icon: Activity },
    { key: 'paper', label: 'Paper prescription', description: 'A signed paper copy is on file', icon: ClipboardList },
    { key: 'warning', label: 'Chart warning', description: 'Anything staff must read first', icon: TriangleAlert },
];
function AlertDialog({ pid, id, onClose }: { pid: PersonId; id: string; onClose: () => void }) {
    const s = useP02();
    const me = P02_PERSONAS[s.route.persona];
    const existing = s.alerts.find((a) => a.id === id);
    const [f, setF] = useState<ChartAlert>(existing ?? { id: `a-${Date.now()}`, pid, type: 'warning', title: '', detail: '', onOpen: true, by: me.name, on: '28 Sep 2026' });
    const [err, setErr] = useState<string>();
    const [loosen, setLoosen] = useState(false);
    const save = () => {
        s.saveAlert(f);
        s.toast('success', existing ? `Chart alert saved: “${f.title}”.` : `Chart alert added: “${f.title}”.`);
        onClose();
    };
    const submit = () => {
        if (!f.title.trim()) {
            setErr('Give the alert a short title staff will recognise.');
            document.getElementById('al-title')?.focus();
            return;
        }
        if (existing?.onOpen && !f.onOpen) return setLoosen(true);
        save();
    };
    return (
        <>
            <Modal
                width={720}
                title={existing ? 'Edit chart alert' : 'Add a chart alert'}
                description={`For ${PEOPLE[pid].pref}’s medication record. Staff see it on the chart; “shown when the chart opens” asks them to read it before recording.`}
                onClose={onClose}
                onCloseAutoFocus={focusBack}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button onClick={submit}>{existing ? 'Save alert' : 'Add alert'}</Button>
                    </>
                }
            >
                <div className="grid gap-1.5">
                    <Label id="al-type">Type</Label>
                    <TilePicker tiles={TYPE_TILES} value={f.type} onChange={(k) => setF({ ...f, type: k as ChartAlert['type'] })} labelledBy="al-type" />
                </div>
                <Field id="al-title" label="Title" required error={err}>
                    <Input id="al-title" value={f.title} onChange={(e) => (setF({ ...f, title: e.target.value }), setErr(undefined))} placeholder="e.g. Swallowing — offer one tablet at a time" aria-invalid={!!err} />
                </Field>
                <Field id="al-detail" label="What staff need to know" hint="Plain words. Say where the source is (a letter, a plan).">
                    <Textarea id="al-detail" value={f.detail} onChange={(e) => setF({ ...f, detail: e.target.value })} placeholder="e.g. From the speech-language therapist letter, 2 July 2026." />
                </Field>
                <div className="flex items-start justify-between gap-4 rounded-lg border p-3">
                    <div>
                        <Label htmlFor="al-open">Show when the chart opens</Label>
                        <p className="text-caption">Staff read it before recording; reading is recorded once a day per person.</p>
                    </div>
                    <Switch id="al-open" checked={f.onOpen} onCheckedChange={(on) => setF({ ...f, onOpen: on })} />
                </div>
            </Modal>
            <ConfirmDialog open={loosen} onClose={() => setLoosen(false)} onConfirm={() => (setLoosen(false), save())} title="Stop showing this alert when the chart opens?" description={`Loosens this check: staff will no longer be asked to read “${f.title}” before recording. It stays in Chart alerts.`} confirmText="Save and stop showing it" variant="destructive" />
        </>
    );
}
function ResolveAlert({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useP02();
    const a = s.alerts.find((x) => x.id === id);
    if (!a) return null;
    return <ConfirmDialog open onClose={onClose} onConfirm={() => (s.resolveAlert(id), s.toast('success', `Resolved: “${a.title}”. It’s kept under “Show resolved alerts”.`), onClose())} title="Mark this alert resolved?" description={`“${a.title}” stops showing on the chart. It stays in the list under “Show resolved alerts”, with your name.`} confirmText="Mark resolved" />;
}
function OnOpenOff({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useP02();
    const a = s.alerts.find((x) => x.id === id);
    if (!a) return null;
    return <ConfirmDialog open onClose={onClose} onConfirm={() => (s.saveAlert({ ...a, onOpen: false }), s.toast('warning', `“${a.title}” no longer shows when the chart opens.`), onClose())} title="Stop showing this alert when the chart opens?" description={`Loosens this check: staff will no longer be asked to read “${a.title}” before recording. It stays in Chart alerts.`} confirmText="Stop showing it" variant="destructive" />;
}

/* ───────────── Pause due and late dose alerts (the suppression switch) ───────────── */
const BASIS: Tile[] = [
    { key: 'Capacity assessment', label: 'Capacity assessment', description: 'A recorded assessment supports it', icon: Brain },
    { key: 'Team decision', label: 'Team decision', description: 'Agreed at a multidisciplinary meeting', icon: Users },
    { key: 'Clinical judgement', label: 'Clinical judgement', description: 'A clinician decided, with a reason', icon: Stethoscope },
    { key: 'The person’s choice', label: 'The person’s choice', description: 'What the person has asked for', icon: UserCheck },
];
function PauseAlerts({ pid, onClose }: { pid: PersonId; onClose: () => void }) {
    const s = useP02();
    const [basis, setBasis] = useState<string | null>(null);
    const [reason, setReason] = useState('');
    const [err, setErr] = useState<Record<string, string | undefined>>({});
    const [confirm, setConfirm] = useState(false);
    const next = () => {
        const e = { 'p-basis': basis ? undefined : 'Choose what the pause is based on.', 'p-reason': reason.trim() ? undefined : 'Say why the alerts should pause.' };
        setErr(e);
        focusFirst(e, ['p-basis', 'p-reason']);
        if (!e['p-basis'] && !e['p-reason']) setConfirm(true);
    };
    return (
        <>
            <Modal
                width={720}
                title={`Pause due and late dose alerts for ${PEOPLE[pid].pref}?`}
                description="Staff stop being alerted when this person’s doses are due or late. The chart still shows every dose."
                onClose={onClose}
                onCloseAutoFocus={focusBack}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button onClick={next}>Continue</Button>
                    </>
                }
            >
                <Notice tone="warning" icon={BellOff} title="Loosens this check">
                    Leads see the pause on the Overview until it’s turned back on. It’s recorded with your name, the basis and the reason.
                </Notice>
                <div className="grid gap-1.5">
                    <Label id="p-basis-label">What is it based on? <span className="text-status-critical">*</span></Label>
                    <div id="p-basis" tabIndex={-1} className="outline-none">
                        <TilePicker tiles={BASIS} value={basis} onChange={(k) => (setBasis(k), setErr({ ...err, 'p-basis': undefined }))} labelledBy="p-basis-label" invalid={!!err['p-basis']} />
                    </div>
                    <InputError message={err['p-basis']} />
                </div>
                <Field id="p-reason" label="Reason" required error={err['p-reason']}>
                    <Textarea id="p-reason" value={reason} onChange={(e) => (setReason(e.target.value), setErr({ ...err, 'p-reason': undefined }))} placeholder="e.g. Aroha manages her own reminders this month — agreed at the team meeting on 25 Sep" aria-invalid={!!err['p-reason']} />
                </Field>
            </Modal>
            <ConfirmDialog open={confirm} onClose={() => setConfirm(false)} onConfirm={() => (s.setPaused(pid, { basis: basis!, reason: reason.trim() }), s.toast('warning', `Due and late dose alerts paused for ${PEOPLE[pid].pref}.`), setConfirm(false), onClose())} title="Pause dose alerts?" description={`Loosens this check: staff won’t be alerted when ${PEOPLE[pid].pref}’s doses are due or late. Basis: ${basis}. Reason: “${reason.trim()}”.`} confirmText="Pause alerts" variant="destructive" />
        </>
    );
}

/* ───────────── Interaction details (InteractionsDialog, redesigned) ───────────── */
function InteractionDialog({ id, onClose }: { id: string; onClose: () => void }) {
    const x = INTERACTIONS.find((i) => i.id === id);
    if (!x) return null;
    return (
        <Modal title={`${x.a} with ${x.b}`} description="Recorded by the pharmacy or prescriber — not from an interaction database." onClose={onClose} onCloseAutoFocus={focusBack}>
            <KV rows={[['Severity (as recorded)', x.severity], ['What they said', x.note], ['Recorded by', x.by]]} />
            <p className="text-caption">If you’re unsure, ask the pharmacist before giving.</p>
        </Modal>
    );
}

/* ───────────── Record an INR result (RecordInrDialog, redesigned) ───────────── */
function RecordInrDialog({ pid, onClose }: { pid: PersonId; onClose: () => void }) {
    const s = useP02();
    const me = P02_PERSONAS[s.route.persona];
    const warfarin = medsOf(pid).find((m) => m.inr && m.status !== 'stopped');
    const last = s.inr.find((r) => !r.disabled && r.target);
    const [f, setF] = useState({ med: warfarin ? warfarin.key : 'none', value: '', tested: '2026-09-28', low: last ? last.target![0].toFixed(1) : '', high: last ? last.target![1].toFixed(1) : '', dose: '', instruction: '', next: '', source: '', note: '' });
    const [err, setErr] = useState<Record<string, string | undefined>>({});
    const v = Number(f.value);
    const out = f.value && f.low && f.high && (v < Number(f.low) || v > Number(f.high));
    const save = () => {
        const e: Record<string, string | undefined> = {};
        if (!f.value || Number.isNaN(v) || v < 0.5 || v > 20) e['inr-value'] = 'Enter the INR result, between 0.5 and 20.';
        if (!f.tested) e['inr-tested'] = 'Choose the day of the test.';
        else if (f.tested > '2026-09-28') e['inr-tested'] = 'The test can’t be in the future.';
        if (f.low && f.high && Number(f.high) < Number(f.low)) e['inr-high'] = 'The top of the range must be at least the bottom.';
        if (f.next && f.next < f.tested) e['inr-next'] = 'The next test must be on or after this test.';
        if (!f.source) e['inr-source'] = 'Say where the result and instruction came from.';
        setErr(e);
        focusFirst(e, ['inr-value', 'inr-source', 'inr-tested', 'inr-next', 'inr-high']);
        if (Object.values(e).some(Boolean)) return;
        s.addInr({ id: `i-${Date.now()}`, value: v, tested: dateLabel(f.tested), testedIso: f.tested, target: f.low && f.high ? [Number(f.low), Number(f.high)] : null, instruction: f.instruction || (f.dose ? `${f.dose} mg a day` : 'No instruction recorded'), next: f.next ? dateLabel(f.next) : null, nextIso: f.next || null, by: me.name, source: f.source, linked: f.med !== 'none' });
        s.toast(out ? 'warning' : 'success', out ? `INR ${v.toFixed(1)} saved — outside the target range. Follow ${PEOPLE[pid].pref}’s plan and tell the prescriber today.` : `INR ${v.toFixed(1)} saved.`);
        onClose();
    };
    return (
        <Modal
            width={720}
            title="Record an INR result"
            description={`For ${PEOPLE[pid].pref}. Enter the result and the instruction exactly as the clinic, lab or prescriber gave them — nothing is calculated.`}
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save INR result</Button>
                </>
            }
        >
            <div className="grid gap-3 sm:grid-cols-2">
                <div className="sm:col-span-2">
                    <Field id="inr-med" label="Medicine" hint={warfarin ? 'Pre-selected: the order the result guides. Every result shows, linked or not.' : 'No INR-guided medicine is on the chart. The result is still shown.'}>
                        <Select value={f.med} onValueChange={(x) => setF({ ...f, med: x })}>
                            <SelectTrigger id="inr-med">
                                <SelectValue />
                            </SelectTrigger>
                            <SelectContent>
                                {warfarin ? <SelectItem value={warfarin.key}>{warfarin.name} {warfarin.strength} · {warfarin.when}</SelectItem> : null}
                                <SelectItem value="none">Not linked to a medicine</SelectItem>
                            </SelectContent>
                        </Select>
                    </Field>
                </div>
                <Field id="inr-value" label="INR result" required error={err['inr-value']}>
                    <Input id="inr-value" inputMode="decimal" value={f.value} onChange={(e) => setF({ ...f, value: e.target.value })} placeholder="e.g. 2.4" aria-invalid={!!err['inr-value']} />
                </Field>
                <Field id="inr-source" label="Where it came from" required error={err['inr-source']}>
                    <Select value={f.source || undefined} onValueChange={(x) => setF({ ...f, source: x })}>
                        <SelectTrigger id="inr-source" aria-invalid={!!err['inr-source']}>
                            <SelectValue placeholder="Choose…" />
                        </SelectTrigger>
                        <SelectContent>
                            {['Anticoagulation clinic phone call', 'Lab result letter', 'The prescriber', 'Other (say in the note)'].map((o) => (
                                <SelectItem key={o} value={o}>
                                    {o}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </Field>
                <Field id="inr-tested" label="Tested on" required error={err['inr-tested']}>
                    <DatePicker id="inr-tested" label="Tested on" value={f.tested} onChange={(x) => setF({ ...f, tested: x })} invalid={!!err['inr-tested']} />
                </Field>
                <Field id="inr-next" label="Next test" error={err['inr-next']} hint="Leave empty if they didn’t say — it shows “Not set”.">
                    <DatePicker id="inr-next" label="Next test" value={f.next} onChange={(x) => setF({ ...f, next: x })} invalid={!!err['inr-next']} allowClear />
                </Field>
                <Field id="inr-low" label="Target range — from">
                    <Input id="inr-low" inputMode="decimal" value={f.low} onChange={(e) => setF({ ...f, low: e.target.value })} placeholder="Not recorded" />
                </Field>
                <Field id="inr-high" label="Target range — to" error={err['inr-high']}>
                    <Input id="inr-high" inputMode="decimal" value={f.high} onChange={(e) => setF({ ...f, high: e.target.value })} placeholder="Not recorded" aria-invalid={!!err['inr-high']} />
                </Field>
                <p className="text-caption sm:col-span-2">Target range: from the prescriber — change it only if they changed it.</p>
                {out ? (
                    <div className="sm:col-span-2">
                        <Notice tone="critical" title={`${v.toFixed(1)} is outside the target range on the order (${f.low}–${f.high})`}>
                            Follow {PEOPLE[pid].pref}’s plan and tell the prescriber or anticoagulation clinic today. On-call contact: <NotConfigured />
                        </Notice>
                    </div>
                ) : null}
                <Field id="inr-dose" label="Warfarin dose (mg a day)">
                    <Input id="inr-dose" inputMode="decimal" value={f.dose} onChange={(e) => setF({ ...f, dose: e.target.value })} placeholder="e.g. 3" />
                </Field>
                <Field id="inr-instruction" label="Instruction in their words">
                    <Input id="inr-instruction" value={f.instruction} onChange={(e) => setF({ ...f, instruction: e.target.value })} placeholder="e.g. 3 mg a day this week" />
                </Field>
                <p className="text-caption sm:col-span-2">Dose and instruction exactly as given with this result.</p>
                <div className="sm:col-span-2">
                    <Field id="inr-note" label="Note (optional)">
                        <Textarea id="inr-note" value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} placeholder="e.g. Clinic rang at 2:10 pm; read back and confirmed" />
                    </Field>
                </div>
            </div>
        </Modal>
    );
}
const dateLabel = (iso: string) => {
    const [y, m, d] = iso.split('-').map(Number);
    return `${d} ${['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'][m - 1]} ${y}`;
};
function InrDetail({ pid, id, onClose }: { pid: PersonId; id: string; onClose: () => void }) {
    const s = useP02();
    const dlg = useDlg();
    const r = s.inr.find((x) => x.id === id);
    const warfarin = medsOf(pid).find((m) => m.inr);
    if (!r) return null;
    const st = inrStatus(r);
    return (
        <Modal
            title={`INR ${r.value.toFixed(1)} · tested ${r.tested}`}
            description={r.disabled ? `Marked as entered in error by ${r.disabled.by}: “${r.disabled.reason}”` : st.label}
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            footer={
                <>
                    {s.can('orders.manage') && !r.disabled ? (
                        <Button variant="destructive" onClick={() => dlg.open(`inr-error:${r.id}`)}>
                            <Trash2 className="size-4" /> Mark as entered in error…
                        </Button>
                    ) : null}
                    {s.can('orders.manage') && !r.linked && !r.disabled && warfarin ? (
                        <Button variant="outline" onClick={() => (s.linkInr(r.id), s.toast('success', `Linked to ${warfarin.name} ${warfarin.strength}.`))}>
                            Link to the warfarin order
                        </Button>
                    ) : null}
                    <Button onClick={onClose} autoFocus>
                        Close
                    </Button>
                </>
            }
        >
            <KV
                rows={[
                    ['Target (from the prescriber)', targetText(r)],
                    ['Instruction', r.instruction],
                    ['Next test', r.next ?? 'Not set'],
                    ['Where it came from', r.source],
                    ['Entered by', r.by],
                    ['Medicine', r.linked && warfarin ? `${warfarin.name} ${warfarin.strength}` : <StatusBadge key="n" variant="warning" size="sm" className="rounded-[8px]">Not linked to a medicine</StatusBadge>],
                ]}
            />
        </Modal>
    );
}
function InrError({ id, onClose }: { id: string; onClose: () => void }) {
    const s = useP02();
    const r = s.inr.find((x) => x.id === id);
    const [reason, setReason] = useState('');
    const [err, setErr] = useState<string>();
    if (!r) return null;
    return (
        <Modal
            title="Mark this INR result as entered in error?"
            description={`INR ${r.value.toFixed(1)}, tested ${r.tested}. It stays in the list, crossed out, with your reason — results are never deleted.`}
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button variant="destructive" onClick={() => (reason.trim() ? (s.disableInr(r.id, reason.trim()), s.toast('warning', `INR ${r.value.toFixed(1)} marked as entered in error.`), onClose()) : (setErr('Say what was wrong.'), document.getElementById('ie-reason')?.focus()))}>
                        Mark as entered in error
                    </Button>
                </>
            }
        >
            <Field id="ie-reason" label="What was wrong?" required error={err}>
                <Textarea id="ie-reason" autoFocus value={reason} onChange={(e) => (setReason(e.target.value), setErr(undefined))} placeholder="e.g. Entered for the wrong person" aria-invalid={!!err} />
            </Field>
            <p className="text-caption">If it was the latest result, the one before it becomes the latest.</p>
        </Modal>
    );
}

/* ───────────── Syringe driver: record a check · finish · start (SyringeDriverDialog, redesigned) ───────────── */
const RUN_TILES: Tile[] = [
    { key: 'yes', label: 'Running', description: 'The pump is running as set', icon: CheckCircle2 },
    { key: 'no', label: 'Not running', description: 'Stopped, alarming or empty', icon: TriangleAlert },
];
function DriverCheckDialog({ onClose }: { onClose: () => void }) {
    const s = useP02();
    const me = P02_PERSONAS[s.route.persona];
    const [run, setRun] = useState<string | null>(null);
    const [site, setSite] = useState('');
    const [left, setLeft] = useState('');
    const [note, setNote] = useState('');
    const [err, setErr] = useState<Record<string, string | undefined>>({});
    const save = () => {
        const e = { 'dc-run': run ? undefined : 'Say whether it’s running.', 'dc-site': site ? undefined : 'Choose how the site looks.', 'dc-left': left.trim() ? undefined : 'Enter what’s left in the syringe.' };
        setErr(e);
        focusFirst(e, ['dc-run', 'dc-site', 'dc-left']);
        if (Object.values(e).some(Boolean)) return;
        s.addDriverCheck({ at: '9:12 am', by: me.name, running: run === 'yes', site, remaining: `${left.trim()} mL`, note: note.trim() || undefined });
        s.toast(run === 'yes' ? 'success' : 'critical', run === 'yes' ? 'Check saved at 9:12 am.' : 'Check saved — not running. Contact the district nursing team now.');
        onClose();
    };
    return (
        <Modal
            title="Record a syringe driver check"
            description={`Grace · ${DRIVER.plan}. Time: 9:12 am.`}
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save check</Button>
                </>
            }
        >
            <div className="grid gap-1.5">
                <Label id="dc-run-label">Is it running? <span className="text-status-critical">*</span></Label>
                <div id="dc-run" tabIndex={-1} className="outline-none">
                    <TilePicker tiles={RUN_TILES} value={run} onChange={setRun} labelledBy="dc-run-label" invalid={!!err['dc-run']} />
                </div>
                <InputError message={err['dc-run']} />
            </div>
            {run === 'no' ? (
                <Notice tone="critical" title="Follow Grace’s palliative care plan now">
                    Contact the district nursing team. On-call contact: <NotConfigured />
                </Notice>
            ) : null}
            <Field id="dc-site" label="The site" required error={err['dc-site']}>
                <Select value={site || undefined} onValueChange={setSite}>
                    <SelectTrigger id="dc-site" aria-invalid={!!err['dc-site']}>
                        <SelectValue placeholder="Choose…" />
                    </SelectTrigger>
                    <SelectContent>
                        {['No redness or swelling', 'Red', 'Swollen', 'Leaking', 'Other (say in the note)'].map((o) => (
                            <SelectItem key={o} value={o}>
                                {o}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <Field id="dc-left" label="Left in the syringe (mL)" required error={err['dc-left']}>
                <Input id="dc-left" inputMode="decimal" value={left} onChange={(e) => setLeft(e.target.value)} placeholder="e.g. 12.5" aria-invalid={!!err['dc-left']} />
            </Field>
            <Field id="dc-note" label="Note (optional)">
                <Textarea id="dc-note" value={note} onChange={(e) => setNote(e.target.value)} />
            </Field>
        </Modal>
    );
}
function FinishDriver({ onClose }: { onClose: () => void }) {
    const s = useP02();
    return <ConfirmDialog open onClose={onClose} onConfirm={() => (s.finishDriver(), s.toast('success', 'Syringe driver finished at 9:12 am.'), onClose())} title="Finish the syringe driver?" description="Records it as finished at 9:12 am with your name. Its checks stay in the list. A new driver is started from Grace’s orders." confirmText="Finish driver" />;
}
const D_STEPS = [
    { key: 'contents', label: 'Contents', blurb: 'From the orders', icon: Pill },
    { key: 'pump', label: 'Pump & site', blurb: 'Rate, site, start', icon: Syringe },
    { key: 'witness', label: 'Witness', blurb: 'For controlled medicines', icon: ShieldAlert },
    { key: 'review', label: 'Review', blurb: 'Check and start', icon: ClipboardCheck },
] as const;
function DriverWizard({ pid, onClose }: { pid: PersonId; onClose: () => void }) {
    const s = useP02();
    const orders = medsOf(pid).filter((m) => m.status === 'active' && (s.cdView || !m.cd));
    const [i, setI] = useState(0);
    const [f, setF] = useState({ picked: [] as string[], rate: '', runs: '24 hours', site: '', start: '2026-09-28T09:12', witness: '', pin: '' });
    const [err, setErr] = useState<Record<string, string | undefined>>({});
    const [done, setDone] = useState(false);
    const hasCd = f.picked.some((k) => medByKey(k).cd);
    const check = (step: number) => {
        const e: Record<string, string | undefined> = {};
        if (step === 0 && !f.picked.length) e['dw-contents'] = 'Choose at least one medicine from the orders.';
        if (step === 1 && !f.rate.trim()) e['dw-rate'] = 'Enter the rate from the plan.';
        if (step === 1 && !f.site) e['dw-site'] = 'Choose the site.';
        if (step === 2 && hasCd && !f.witness) e['dw-witness'] = 'Choose the witness.';
        if (step === 2 && hasCd && !/^\d{6}$/.test(f.pin)) e['dw-pin'] = 'The witness enters their 6-digit witness PIN.';
        setErr(e);
        focusFirst(e, ['dw-contents', 'dw-rate', 'dw-site', 'dw-witness', 'dw-pin']);
        return !Object.values(e).some(Boolean);
    };
    return (
        <WizardShell
            open
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            title="Start a syringe driver"
            description="Contents from the person’s orders, pump and site, witness, review"
            railIcon={Syringe}
            railTitle="Start a syringe driver"
            railSub={PEOPLE[pid].pref}
            steps={D_STEPS}
            stepIndex={i}
            onStepClick={(n) => (n <= i || check(i) ? setI(n) : null)}
            pct={Math.round(([f.picked.length > 0, !!f.rate, !!f.site, !hasCd || /^\d{6}$/.test(f.pin)].filter(Boolean).length / 4) * 100)}
            footerStart={
                <Button variant="outline" onClick={() => (i ? setI(i - 1) : onClose())}>
                    {i ? 'Back' : 'Cancel'}
                </Button>
            }
            footerEnd={i < 3 ? <Button onClick={() => check(i) && setI(i + 1)}>Continue <ArrowRight className="size-4" /></Button> : <Button onClick={() => setDone(true)}>Start driver</Button>}
            success={done ? <WizardSuccessPane title="Syringe driver started" blurb="Checks are due every 4 hours from the plan. The first check is due by 1:12 pm." actions={<Button onClick={onClose}>Done</Button>} /> : undefined}
        >
            <WizardStepPane key={i}>
                <div className="space-y-4 p-6">
                    {i === 0 ? (
                        <div className="grid gap-2">
                            <Label id="dw-contents-label">Which ordered medicines go in the syringe?</Label>
                            <ul id="dw-contents" tabIndex={-1} aria-labelledby="dw-contents-label" className="divide-y rounded-lg border outline-none">
                                {orders.map((m) => (
                                    <li key={m.key} className="flex items-center gap-3 px-3 py-2.5">
                                        <Checkbox id={`dw-${m.key}`} checked={f.picked.includes(m.key)} onCheckedChange={(c) => setF({ ...f, picked: c ? [...f.picked, m.key] : f.picked.filter((x) => x !== m.key) })} />
                                        <Label htmlFor={`dw-${m.key}`} className="flex-1 font-normal">
                                            {m.name} {m.strength} <span className="text-caption">· {m.route}</span>
                                        </Label>
                                        {m.cd ? <StatusBadge variant="neutral" size="sm" className="rounded-[8px]">Controlled</StatusBadge> : null}
                                    </li>
                                ))}
                            </ul>
                            <InputError message={err['dw-contents']} />
                            <p className="text-caption">Each item keeps the order it came from, so the server can check it. A medicine that isn’t ordered can’t be added here — ask the prescriber.</p>
                        </div>
                    ) : null}
                    {i === 1 ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <Field id="dw-rate" label="Rate (from the plan)" required error={err['dw-rate']}>
                                <Input id="dw-rate" value={f.rate} onChange={(e) => setF({ ...f, rate: e.target.value })} placeholder="e.g. 0.83 mL an hour" aria-invalid={!!err['dw-rate']} />
                            </Field>
                            <Field id="dw-runs" label="Runs for">
                                <Select value={f.runs} onValueChange={(x) => setF({ ...f, runs: x })}>
                                    <SelectTrigger id="dw-runs">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['12 hours', '24 hours'].map((o) => (
                                            <SelectItem key={o} value={o}>
                                                {o}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <Field id="dw-site" label="Site" required error={err['dw-site']}>
                                <Select value={f.site || undefined} onValueChange={(x) => setF({ ...f, site: x })}>
                                    <SelectTrigger id="dw-site" aria-invalid={!!err['dw-site']}>
                                        <SelectValue placeholder="Choose…" />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {['Left upper chest', 'Right upper chest', 'Left upper arm', 'Right upper arm', 'Abdomen'].map((o) => (
                                            <SelectItem key={o} value={o}>
                                                {o}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </Field>
                            <div className="sm:col-span-2">
                                <DateTimeField id="dw-start" label="Started" value={f.start} onChange={(x) => setF({ ...f, start: x })} />
                            </div>
                        </div>
                    ) : null}
                    {i === 2 ? (
                        hasCd ? (
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Field id="dw-witness" label="Witness" required error={err['dw-witness']} hint="On shift now, with controlled-medicine witness competency and a witness PIN.">
                                    <Select value={f.witness || undefined} onValueChange={(x) => setF({ ...f, witness: x })}>
                                        <SelectTrigger id="dw-witness" aria-invalid={!!err['dw-witness']}>
                                            <SelectValue placeholder="Choose…" />
                                        </SelectTrigger>
                                        <SelectContent>
                                            <SelectItem value="Daniel Ahn">Daniel Ahn · on shift · PIN set</SelectItem>
                                            <SelectItem value="Mere Kahu">Mere Kahu · on shift · PIN set</SelectItem>
                                        </SelectContent>
                                    </Select>
                                </Field>
                                <Field id="dw-pin" label="Witness PIN" required error={err['dw-pin']} hint="The witness types it — 6 digits.">
                                    <Input id="dw-pin" type="password" inputMode="numeric" maxLength={6} value={f.pin} onChange={(e) => setF({ ...f, pin: e.target.value.replace(/\D/g, '') })} aria-invalid={!!err['dw-pin']} />
                                </Field>
                            </div>
                        ) : (
                            <Notice tone="neutral" title="No witness needed">None of the chosen medicines is controlled.</Notice>
                        )
                    ) : null}
                    {i === 3 ? (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <ReviewCard icon={Pill} title="Contents" onEdit={() => setI(0)} span>
                                {f.picked.map((k) => (
                                    <ReviewRow key={k} label={medByKey(k).name} value={`${medByKey(k).strength} · from the order`} />
                                ))}
                            </ReviewCard>
                            <ReviewCard icon={Syringe} title="Pump & site" onEdit={() => setI(1)}>
                                <ReviewRow label="Rate" value={f.rate} />
                                <ReviewRow label="Runs for" value={f.runs} />
                                <ReviewRow label="Site" value={f.site} />
                            </ReviewCard>
                            <ReviewCard icon={ShieldAlert} title="Witness" onEdit={() => setI(2)}>
                                <ReviewRow label="Witness" value={hasCd ? `${f.witness} (witness PIN)` : 'Not needed'} />
                            </ReviewCard>
                        </div>
                    ) : null}
                </div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ───────────── a recorded change (MedicationEventDrawer, redesigned) ───────────── */
function EventDialog({ pid, id, onClose }: { pid: PersonId; id: string; onClose: () => void }) {
    const s = useP02();
    const e = EVENTS.find((x) => x.id === id);
    if (!e) return null;
    return (
        <Modal
            width={720}
            title={e.what}
            description={`${e.at} · ${e.who} · times in NZDT`}
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            footer={
                <>
                    <Button variant="outline" onClick={() => s.toast('info', 'Flag for review (Reports & audit, designed in P09) — outside this preview.')}>
                        Flag for review
                    </Button>
                    <Button variant="outline" onClick={() => s.set({ tab: undefined, view: undefined, dlg: undefined, day: e.at.startsWith('Mon 28') ? undefined : '2026-09-27' })}>
                        Show on {PEOPLE[pid].pref}’s chart
                    </Button>
                    <Button onClick={onClose} autoFocus>
                        Close
                    </Button>
                </>
            }
        >
            <KV
                rows={[
                    ['What happened', e.what],
                    ['Who', e.who],
                    ['Before', e.before ?? '—'],
                    ['After', e.after ?? '—'],
                    ['Linked', e.linked ?? '—'],
                    ['Checked', e.checked === 'unchanged' ? 'Unchanged since it was saved' : 'Not checked yet'],
                ]}
            />
            <p className="text-caption">“Show on the chart” opens this person’s chart on that day (today’s drawer opens the MAR without the person).</p>
        </Modal>
    );
}

/* ───────────── Print MAR (existing PDF) ───────────── */
function PrintDialog({ pid, onClose }: { pid: PersonId; onClose: () => void }) {
    const s = useP02();
    const [range, setRange] = useState('week');
    return (
        <Modal
            title="Print the MAR"
            description={`${PEOPLE[pid].pref}’s chart as a PDF — the existing MAR chart PDF. Times in NZDT.`}
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={() => (s.toast('info', 'Downloads the existing MAR chart PDF — outside this preview.'), onClose())}>
                        <Printer className="size-4" /> Download PDF
                    </Button>
                </>
            }
        >
            <Field id="pr-range" label="Dates">
                <Select value={range} onValueChange={setRange}>
                    <SelectTrigger id="pr-range">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="today">Today · Mon 28 Sep</SelectItem>
                        <SelectItem value="week">This week · Tue 22 – Mon 28 Sep</SelectItem>
                        <SelectItem value="month">September 2026</SelectItem>
                    </SelectContent>
                </Select>
            </Field>
            {!s.cdView ? (
                <Notice tone="neutral" title="Controlled medicines are left out">
                    You don’t have controlled-medicine access, so they aren’t in your printout. {CONCEALED.ask}
                </Notice>
            ) : null}
        </Modal>
    );
}

/* ───────────── Review allergies — on the health profile, the one place (Stephan, 30 Sep) ───────────── */
function AllergyReviewDialog({ pid, onClose }: { pid: PersonId; onClose: () => void }) {
    const s = useP02();
    const a = useAllergy(pid);
    const me = P02_PERSONAS[s.route.persona];
    const [rows, setRows] = useState(a.entries.map((e) => ({ allergen: e.allergen, reaction: e.reaction ?? '', severity: e.severity ?? '' })));
    const [none, setNone] = useState(a.status === 'nkda');
    const [how, setHow] = useState('');
    const [err, setErr] = useState<Record<string, string | undefined>>({});
    const lead = s.can('clients.update') && s.can('orders.manage');
    const firstNew = useRef<HTMLInputElement>(null);
    const save = () => {
        const e: Record<string, string | undefined> = {};
        if (!rows.length && !none) e['ar-none'] = 'Record at least one allergy, or tick “No known allergies” after checking.';
        rows.forEach((r, i) => {
            if (!r.allergen.trim()) e[`ar-a-${i}`] = 'Name the allergy, or remove the row.';
        });
        if (!how) e['ar-how'] = 'Say how you checked.';
        setErr(e);
        focusFirst(e, ['ar-none', ...rows.map((_, i) => `ar-a-${i}`), 'ar-how']);
        if (Object.values(e).some(Boolean)) return;
        s.review(pid, how.toLowerCase(), none && !rows.length);
        s.toast('success', `Allergies reviewed — saved to ${PEOPLE[pid].pref}’s health profile by ${me.name}.`);
        onClose();
    };
    if (!lead)
        return (
            <Modal title={`${PEOPLE[pid].pref}’s allergies`} description="On the health profile — the one place allergies are kept." onClose={onClose} onCloseAutoFocus={focusBack}>
                <Notice tone="neutral" title="Only house leads and clinical leads review allergies">
                    Tell Jordan Tipene if something on the list is wrong or missing.
                </Notice>
            </Modal>
        );
    return (
        <Modal
            width={720}
            title={`Review ${PEOPLE[pid].pref}’s allergies`}
            description="Saved to the health profile — the one place allergies are kept. The medication record, Meds today and every recording screen read it."
            onClose={onClose}
            onCloseAutoFocus={focusBack}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>
                        <CheckCircle2 className="size-4" /> Confirm the list is right
                    </Button>
                </>
            }
        >
            {rows.map((r, i) => (
                <div key={i} className="grid items-end gap-2 rounded-lg border p-3 sm:grid-cols-[1.2fr_1.4fr_1fr_auto]">
                    <Field id={`ar-a-${i}`} label="Allergy" required error={err[`ar-a-${i}`]}>
                        <Input id={`ar-a-${i}`} ref={i === rows.length - 1 ? firstNew : undefined} value={r.allergen} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, allergen: e.target.value } : x)))} placeholder="e.g. Penicillin" />
                    </Field>
                    <Field id={`ar-r-${i}`} label="Reaction">
                        <Input id={`ar-r-${i}`} value={r.reaction} onChange={(e) => setRows(rows.map((x, j) => (j === i ? { ...x, reaction: e.target.value } : x)))} placeholder="Not recorded" />
                    </Field>
                    <Field id={`ar-s-${i}`} label="Severity">
                        <Select value={r.severity || undefined} onValueChange={(v) => setRows(rows.map((x, j) => (j === i ? { ...x, severity: v } : x)))}>
                            <SelectTrigger id={`ar-s-${i}`}>
                                <SelectValue placeholder="Not recorded" />
                            </SelectTrigger>
                            <SelectContent>
                                {['Severe', 'Moderate', 'Mild'].map((o) => (
                                    <SelectItem key={o} value={o}>
                                        {o}
                                    </SelectItem>
                                ))}
                            </SelectContent>
                        </Select>
                    </Field>
                    <Button variant="ghost" size="icon" aria-label={`Remove ${r.allergen || 'this allergy'}`} onClick={() => setRows(rows.filter((_, j) => j !== i))}>
                        <X className="size-4" />
                    </Button>
                </div>
            ))}
            <Button variant="outline" onClick={() => (setRows([...rows, { allergen: '', reaction: '', severity: '' }]), setNone(false), window.setTimeout(() => firstNew.current?.focus(), 30))}>
                <Plus className="size-4" /> Add an allergy
            </Button>
            {!rows.length ? (
                <div className="grid gap-1">
                    <div className="flex items-start gap-2.5 rounded-lg border p-3">
                        <Checkbox id="ar-none" checked={none} onCheckedChange={(c) => (setNone(!!c), setErr({ ...err, 'ar-none': undefined }))} />
                        <Label htmlFor="ar-none" className="font-normal">
                            <span className="font-semibold">No known allergies</span> — I checked and none were found
                        </Label>
                    </div>
                    <InputError message={err['ar-none']} />
                </div>
            ) : null}
            <Field id="ar-how" label="How did you check?" required error={err['ar-how']}>
                <Select value={how || undefined} onValueChange={(v) => (setHow(v), setErr({ ...err, 'ar-how': undefined }))}>
                    <SelectTrigger id="ar-how" aria-invalid={!!err['ar-how']}>
                        <SelectValue placeholder="Choose…" />
                    </SelectTrigger>
                    <SelectContent>
                        {[`Checked with ${PEOPLE[pid].pref} and the GP record`, 'Checked with family or whānau', 'Checked the hospital or GP record', 'Checked with the pharmacy'].map((o) => (
                            <SelectItem key={o} value={o}>
                                {o}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </Field>
            <p className="text-caption">Saving records “Reviewed 28 September 2026 by {me.name}”. Re-check at each medication review; no fixed interval until the clinical lead sets one (<NotConfigured />).</p>
        </Modal>
    );
}
