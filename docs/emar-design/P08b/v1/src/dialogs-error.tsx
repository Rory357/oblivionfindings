/* P08b’s error dialogs — the report viewer (today’s detail drawer), Triage
 * (today’s triage + review dialogs, whose status choice is ignored), notes,
 * actions, telling the person, reopen, adding an account, closing the error
 * with its incident, closing an incident that’s ready, and the CSV export.
 * Real WizardShell (sequential, and the viewer with sequential={false}),
 * WizardStepPane, ReviewCard/ReviewRow, WizardSuccessPane, the Fleet Settings
 * Modal, ConfirmDialog, TilePicker, Select, Input, Textarea, Checkbox, the
 * PKG-01 DateTimeField and the approved DatePicker. Decisions: Main, Q2–Q8. */
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
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell, WizardStepPane, WizardSuccessPane } from '@/components/wizard/shell';
import { AlertOctagon, Check, ChevronLeft, ChevronRight, ClipboardCheck, Download, EyeOff, History, ListChecks, Loader2, LockKeyhole, MessageCircleMore, MessageSquareText, NotebookPen, Siren, UserRound } from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { NOW_LOCAL } from './clock';
import { HARM_LABEL, HOUSES, OWNERS, PEOPLE, PERSONAS, REACH_LABEL, SOURCE_LABEL, TYPE_LABEL, type Action, type ErrType, type Harm, type MedError, type MedIncident, type Reach } from './data';
import { HARM_TILES, reachTiles } from './dialogs-report';
import { cantSaveOffline, focusFirst, Req, restore, stamp } from './helpers';
import { Modal } from './modal';
import {
    addDays,
    allErrors,
    canActOn,
    canCloseError,
    canCloseIncident,
    canReport,
    canSeeAll,
    cdView,
    closeBlockers,
    errorOf,
    errorsIn,
    harmTone,
    INC_STATUS,
    incidentOf,
    incidentRequired,
    incidentState,
    incidentTitle,
    isControlled,
    isReady,
    labelIso,
    localLabel,
    lowerFirst,
    medsText,
    redacted,
    stageState,
    summaryOf,
    TODAY_ISO,
    WHO_CLOSES_INCIDENTS,
} from './model';
import { useStore } from './store';
import { CONCEALED, KV, Notice, TilePicker } from './ui';

const isOpen = (e: MedError) => e.stage === 'investigating' || e.stage === 'actions';
/** Every change is appended to the error’s own history (MEC has no audit calls today — AUDIT 2). */
function usePatch() {
    const s = useStore();
    return (e: MedError, patch: Partial<MedError>, ...lines: string[]) =>
        s.update((rt) => ({ ...rt, patch: { ...rt.patch, [e.id]: { ...rt.patch[e.id], ...patch, log: [...(e.log ?? []), ...lines.map((text) => ({ at: stamp(), text }))] } } }));
}
const whoTold = (e: MedError) => (e.disclosure?.who ?? []).map((w) => (w === 'person' ? PEOPLE[e.pid].pref : e.disclosure?.whanauName ?? 'Whānau')).join(' and ');

/* ═════════════ The report (viewer) ═════════════ */
export const ERROR_SECTIONS = ['error', 'accounts', 'triage', 'investigation', 'actions', 'telling', 'incident', 'history'] as const;
export function ErrorDialog({ error: e, section, onClose, onAction }: { error: MedError; section?: string; onClose: () => void; onAction: (spec: string) => void }) {
    const s = useStore();
    const p = s.route.persona;
    const person = PEOPLE[e.pid];
    const hide = redacted(p, e);
    const all = canSeeAll(p);
    const act = canActOn(p, e);
    const inc = e.incident ? incidentOf(s.rt, e.incident) : null;
    const [step, setStep] = useState(Math.max(0, ERROR_SECTIONS.indexOf((section ?? 'error') as (typeof ERROR_SECTIONS)[number])));
    const st = stageState(e);
    const blockers = closeBlockers(e);
    const steps = [
        { key: 'error', label: 'This error', blurb: TYPE_LABEL[e.type], icon: AlertOctagon },
        { key: 'accounts', label: 'Accounts', blurb: `${e.accounts.length} · added, never edited`, icon: MessageSquareText },
        { key: 'triage', label: 'Triage', blurb: e.triaged ? `${e.owner}` : 'Not triaged yet', icon: ClipboardCheck },
        { key: 'investigation', label: 'Investigation', blurb: all ? `${e.notes.length} ${e.notes.length === 1 ? 'note' : 'notes'}` : 'The owner looks into it', icon: NotebookPen },
        { key: 'actions', label: 'Actions', blurb: e.actions.length ? `${e.actions.filter((a) => !a.done).length} open of ${e.actions.length}` : 'None yet', icon: ListChecks },
        { key: 'telling', label: 'Telling the person', blurb: e.reach === 'no' ? 'Not needed — a near miss' : e.disclosure?.state === 'told' ? 'Told' : 'Not recorded yet', icon: MessageCircleMore },
        { key: 'incident', label: 'Incident', blurb: e.incident ?? (incidentRequired(e) ? 'Required' : 'None'), icon: Siren },
        { key: 'history', label: 'History', blurb: 'Every step, kept', icon: History },
    ];
    const cur = steps[step].key;
    const events: [string, string][] = [
        [e.reported.at, `Reported by ${e.reported.by} — ${lowerFirst(SOURCE_LABEL[e.source])}`],
        ...e.accounts.slice(1).map((a) => [a.at, `Account added by ${a.by}`] as [string, string]),
        ...(e.triaged ? ([[e.triaged.at, `Triaged by ${e.triaged.by} — owner ${e.owner}${e.investigateDue ? `, investigation due ${e.investigateDue}` : ''}`]] as [string, string][]) : []),
        ...e.notes.map((n) => [n.at, `Note added by ${n.by}`] as [string, string]),
        ...e.actions.filter((a) => a.done).map((a) => [a.done!.at, `${a.id} done by ${a.done!.by}`] as [string, string]),
        ...(e.disclosure?.state === 'told' && e.disclosure.at ? ([[e.disclosure.at, `Told ${whoTold(e)} — ${e.disclosure.by}`]] as [string, string][]) : []),
        ...(e.closed ? ([[e.closed.at, `Closed by ${e.closed.by}`]] as [string, string][]) : []),
        ...(e.reopened ?? []).map((r) => [r.at, `Reopened by ${r.by} — ${r.reason}`] as [string, string]),
        ...(e.log ?? []).map((l) => [l.at, l.text] as [string, string]),
    ];
    const needs = (what: string) => (
        <Notice tone="neutral" icon={LockKeyhole} title={`${what} need controlled-medicine access`}>
            {`The words may name the medicine. ${CONCEALED.ask}`}
        </Notice>
    );
    const body: Record<string, ReactNode> = {
        error: (
            <>
                <div className="flex flex-wrap items-center gap-2">
                    <StatusBadge variant={st.variant} className="rounded-[8px] font-semibold">
                        {st.label}
                    </StatusBadge>
                    <StatusBadge variant={harmTone(e).variant} className="rounded-[8px]">
                        {harmTone(e).label}
                    </StatusBadge>
                    {isControlled(e) ? (
                        <StatusBadge variant="neutral" className="rounded-[8px]">
                            <LockKeyhole className="size-3" /> Controlled medicine
                        </StatusBadge>
                    ) : null}
                </div>
                {hide && all ? (
                    <Notice tone="neutral" icon={LockKeyhole} title="You can see this report, not act on it">
                        {`It’s about a controlled medicine. A house lead with controlled-medicine access looks after it${e.owner ? ` — ${e.owner}` : ''}.`}
                    </Notice>
                ) : null}
                <KV
                    rows={[
                        ['Person', `${person.legal} · ${HOUSES[person.house]}`],
                        ['Medicine', medsText(p, e)],
                        ['What went wrong', TYPE_LABEL[e.type]],
                        ['When it happened', e.occurred],
                        ['Reported', `${e.reported.by}, ${e.reported.at} — ${lowerFirst(SOURCE_LABEL[e.source])}`],
                        ['Reached', REACH_LABEL[e.reach]],
                        ['Harm', e.reach === 'no' ? 'None — a near miss' : HARM_LABEL[e.harm]],
                        ['Owner', e.owner ?? 'Not triaged yet'],
                        ['Due', e.stage === 'triage' ? `Triage ${e.triageDue}` : e.stage === 'closed' ? '—' : `Investigation ${e.investigateDue}`],
                    ]}
                />
                {act && isOpen(e) && blockers.length ? (
                    <Notice tone="neutral" title="Before it can close">
                        <ul className="list-disc pl-5">
                            {blockers.map((b) => (
                                <li key={b}>{b}</li>
                            ))}
                        </ul>
                    </Notice>
                ) : null}
                <ReviewCard icon={EyeOff} title="Outside this report">
                    <ReviewRow label="Shown as" value={summaryOf(e)} />
                    <ReviewRow label="Where" value="The incident, Control Room, tasks, the dashboard and reports — never the accounts or notes" />
                </ReviewCard>
            </>
        ),
        accounts: hide ? (
            needs('Accounts')
        ) : (
            <>
                <ol className="space-y-2.5" aria-label="Accounts">
                    {e.accounts.map((a, n) => (
                        <li key={n} className="rounded-lg border p-3">
                            <p className="text-sm whitespace-pre-line">{a.text}</p>
                            <p className="mt-1 text-caption">
                                {a.by}, {a.at}
                            </p>
                        </li>
                    ))}
                </ol>
                <KV rows={[['Straight away', e.immediate], ['More likely because', e.contributing ?? 'Not given']]} />
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-caption">Accounts are added, never edited.</p>
                    {canReport(p) && e.stage !== 'closed' ? (
                        <Button variant="outline" size="sm" onClick={() => onAction(`account:${e.id}`)}>
                            Add your account
                        </Button>
                    ) : null}
                </div>
            </>
        ),
        triage: e.triaged ? (
            <KV
                rows={[
                    ['Triaged by', `${e.triaged.by}, ${e.triaged.at}`],
                    ['Owner', e.owner ?? '—'],
                    ['Investigation due', e.investigateDue ?? '—'],
                ]}
            />
        ) : (
            <>
                <Notice tone={st.variant === 'critical' ? 'critical' : 'warning'} title={st.variant === 'critical' ? 'Triage is overdue' : 'Not triaged yet'}>
                    {`Due ${e.triageDue}. The house lead and the clinical lead are alerted until it’s triaged.`}
                </Notice>
                {act ? (
                    <Button className="self-start" onClick={() => onAction(`triage:${e.id}`)}>
                        Triage it
                    </Button>
                ) : null}
            </>
        ),
        investigation: !all ? (
            <p className="text-sm">{e.owner ? `${e.owner} is looking into it.` : 'The house lead looks into it after triage.'} You’re told the outcome when it closes.</p>
        ) : hide ? (
            needs('Notes')
        ) : (
            <>
                {e.notes.length ? (
                    <ol className="space-y-2.5" aria-label="Investigation notes">
                        {e.notes.map((n, i) => (
                            <li key={i} className="rounded-lg border p-3">
                                <p className="text-sm">{n.text}</p>
                                <p className="mt-1 text-caption">
                                    {n.by}, {n.at}
                                </p>
                            </li>
                        ))}
                    </ol>
                ) : (
                    <p className="text-subtle">{e.stage === 'triage' ? 'Notes are added once it’s triaged.' : 'No notes yet.'}</p>
                )}
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-caption">Notes are added, never edited. To correct one, add another.</p>
                    {act && isOpen(e) ? (
                        <Button variant="outline" size="sm" onClick={() => onAction(`note:${e.id}`)}>
                            Add a note
                        </Button>
                    ) : null}
                </div>
            </>
        ),
        actions: (
            <>
                {e.actions.length ? (
                    <ul className="divide-y rounded-lg border" aria-label="Actions">
                        {e.actions.map((a) => (
                            <li key={a.id} className="flex flex-wrap items-start justify-between gap-3 px-3 py-2.5">
                                <span className="min-w-0">
                                    <span className="block text-sm font-semibold">{hide ? 'An action — details need controlled-medicine access' : a.what}</span>
                                    <span className="block text-caption">
                                        {a.id} · {a.owner} · {a.done ? `done ${a.done.at}${hide ? '' : ` — “${a.done.note}”`}` : `due ${a.due}`}
                                    </span>
                                </span>
                                <span className="flex items-center gap-2">
                                    <StatusBadge variant={a.done ? 'success' : a.dueIso < TODAY_ISO ? 'critical' : a.dueIso === TODAY_ISO ? 'warning' : 'neutral'} className="rounded-[8px]">
                                        {a.done ? 'Done' : a.dueIso < TODAY_ISO ? 'Overdue' : a.dueIso === TODAY_ISO ? 'Due today' : 'Open'}
                                    </StatusBadge>
                                    {act && !a.done ? (
                                        <Button size="sm" variant="outline" onClick={() => onAction(`done:${e.id}:${a.id}`)}>
                                            Mark it done
                                        </Button>
                                    ) : null}
                                </span>
                            </li>
                        ))}
                    </ul>
                ) : (
                    <p className="text-subtle">No actions yet.</p>
                )}
                {act && isOpen(e) ? (
                    <Button variant="outline" size="sm" className="self-start" onClick={() => onAction(`action:${e.id}`)}>
                        Add an action
                    </Button>
                ) : null}
            </>
        ),
        telling:
            e.reach === 'no' ? (
                <p className="text-sm">Not needed — it didn’t reach {person.pref}.</p>
            ) : (
                <>
                    {e.disclosure?.state === 'told' ? (
                        <KV
                            rows={[
                                ['Told', whoTold(e)],
                                ['By', `${e.disclosure.by}, ${e.disclosure.at}`],
                                ['How', e.disclosure.how ?? '—'],
                            ]}
                        />
                    ) : e.disclosure?.state === 'notYet' ? (
                        <Notice tone="warning" title="Not told yet">
                            {hide ? 'The reason needs controlled-medicine access.' : e.disclosure.why}
                        </Notice>
                    ) : (
                        <Notice tone="warning" title="Not recorded yet">
                            {`Telling ${person.pref} — and whānau, a welfare guardian or an EPOA where right — is recorded before this can close.`}
                        </Notice>
                    )}
                    {act && e.stage !== 'closed' ? (
                        <Button variant="outline" size="sm" className="self-start" onClick={() => onAction(`disclose:${e.id}`)}>
                            {e.disclosure?.state === 'told' ? 'Update it' : 'Record telling the person'}
                        </Button>
                    ) : null}
                </>
            ),
        incident: inc ? (
            <>
                <KV
                    rows={[
                        ['Incident', `${inc.id} — ${incidentTitle(s.rt, inc)}`],
                        ['In Incidents', INC_STATUS[inc.status]],
                    ]}
                />
                <span className="flex flex-col items-start gap-1">
                    <StatusBadge variant={incidentState(s.rt, inc).variant} className="rounded-[8px]">
                        {incidentState(s.rt, inc).label}
                    </StatusBadge>
                    {incidentState(s.rt, inc).line ? <span className="text-caption">{incidentState(s.rt, inc).line}</span> : null}
                </span>
                <p className="text-caption">It carries the summary, the person, when it happened and the harm — never the accounts or notes. It closes through Incidents, with Incidents’ own checks.</p>
                {all ? (
                    <div className="flex flex-wrap gap-2">
                        {canCloseIncident(p) && isReady(s.rt, inc) ? <Button onClick={() => onAction(`incclose:${inc.id}`)}>Review and close</Button> : null}
                        <Button variant="outline" onClick={() => s.go('/incidents', { tab: 'awaiting', open: undefined, sub: undefined })}>
                            Open in Incidents
                        </Button>
                    </div>
                ) : null}
            </>
        ) : incidentRequired(e) ? (
            <Notice tone="warning" title="An incident is required">
                It’s made when this is triaged.
            </Notice>
        ) : (
            <p className="text-sm">No incident. One is made when an error reached the person with moderate harm or worse, or when more than ordered was given.</p>
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
            title={`${e.id} — ${person.legal}`}
            description={`${TYPE_LABEL[e.type]}, ${e.occurred}.`}
            railIcon={AlertOctagon}
            railTitle={`Medication error ${e.id}`}
            railSub={`${person.pref} · ${lowerFirst(TYPE_LABEL[e.type])} · ${e.occurred}`}
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
                act && e.stage === 'triage' ? (
                    <Button type="button" onClick={() => onAction(`triage:${e.id}`)}>
                        Triage it
                    </Button>
                ) : canCloseError(p, e) && isOpen(e) && !blockers.length ? (
                    <Button type="button" onClick={() => onAction(`close:${e.id}`)}>
                        Close the error
                    </Button>
                ) : act && e.stage === 'closed' ? (
                    <Button type="button" variant="outline" onClick={() => onAction(`reopen:${e.id}`)}>
                        Reopen
                    </Button>
                ) : undefined
            }
        >
            <WizardStepPane>
                <div className="flex flex-col gap-4">{body[cur]}</div>
            </WizardStepPane>
        </WizardShell>
    );
}

/* ═════════════ Triage (Q5) ═════════════ */
export function TriageDialog({ error: e, onClose, returnFocus }: { error: MedError; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const person = PEOPLE[e.pid];
    const owners = OWNERS.filter((o) => o.houses.includes(person.house));
    const [step, setStep] = useState(0);
    const [type, setType] = useState<ErrType>(e.type);
    const [reach, setReach] = useState<Reach>(e.reach);
    const [harm, setHarm] = useState<Harm | ''>(e.reach === 'no' ? '' : e.harm);
    const owner0 = owners.some((o) => o.name === me.name) ? me.name : '';
    const due0 = addDays(TODAY_ISO, 3);
    const [owner, setOwner] = useState(owner0);
    const [due, setDue] = useState(due0);
    const [e2, setE] = useState<Record<string, string>>({});
    const [phase, setPhase] = useState<'edit' | 'sending' | 'done'>('edit');
    const [discard, setDiscard] = useState(false);
    const now = { reach, harm: (reach === 'no' ? 'none' : harm || 'unknown') as Harm, source: e.source };
    const makesIncident = !e.incident && incidentRequired(now);
    const changed = type !== e.type || reach !== e.reach || now.harm !== e.harm;
    const steps = [
        { key: 'check', label: 'Check the report', blurb: 'What went wrong, reach and harm', icon: ClipboardCheck },
        { key: 'owner', label: 'Owner & due date', blurb: 'Who looks into it, by when', icon: UserRound },
        { key: 'review', label: 'Review & save', blurb: 'Check, then save', icon: Check },
    ];
    const cur = steps[step].key;
    function validate(k: string) {
        const x: Record<string, string> = {};
        if (k === 'check' && reach !== 'no' && !harm) x['tr-harm'] = 'Choose how much harm, so far — or “Not known yet”.';
        if (k === 'owner') {
            if (!owner) x['tr-owner'] = 'Choose who owns it.';
            if (!due) x['tr-due'] = 'Choose when the investigation is due.';
            else if (due < TODAY_ISO) x['tr-due'] = 'The due date can’t be in the past.';
        }
        return x;
    }
    function next() {
        const x = validate(cur);
        setE(x);
        if (Object.keys(x).length) return focusFirst(x);
        setStep(step + 1);
    }
    const patch = usePatch();
    function save() {
        if (s.route.scenario === 'offline') return setE({ 'tr-save': cantSaveOffline });
        setPhase('sending');
        window.setTimeout(() => {
            const at = stamp();
            const inc = makesIncident ? `INC-${2237 + s.rt.newIncidents.length}` : e.incident;
            const lines = [`Triaged by ${me.name} — owner ${owner}, investigation due ${labelIso(due)}`];
            if (type !== e.type) lines.push(`What went wrong changed at triage: ${lowerFirst(TYPE_LABEL[e.type])} → ${lowerFirst(TYPE_LABEL[type])}`);
            if (reach !== e.reach || now.harm !== e.harm) lines.push(`Reach and harm changed at triage: ${harmTone(e).label.toLowerCase()} → ${harmTone(now).label.toLowerCase()}`);
            if (makesIncident) lines.push(`Incident ${inc} made at triage — it carries the summary only`);
            patch(e, { stage: 'investigating', triaged: { by: me.name, at }, owner, investigateDue: labelIso(due), investigateDueIso: due, type, reach, harm: now.harm, incident: inc }, ...lines);
            if (makesIncident) {
                const incident: MedIncident = { id: inc!, source: 'error', ref: e.id, pid: e.pid, status: 'submitted' };
                s.update((rt) => ({ ...rt, newIncidents: [incident, ...rt.newIncidents] }));
            }
            setPhase('done');
        }, 700);
    }
    const body: Record<string, ReactNode> = {
        check: (
            <>
                <ReviewCard icon={AlertOctagon} title={`As reported by ${e.reported.by}`}>
                    <ReviewRow label="What went wrong" value={TYPE_LABEL[e.type]} />
                    <ReviewRow label="Medicine" value={medsText(s.route.persona, e)} />
                    <ReviewRow label="When" value={e.occurred} />
                    <ReviewRow label="Reach & harm" value={harmTone(e).label} />
                    <ReviewRow label="In their words" value={e.accounts[0].text} />
                </ReviewCard>
                <div className="space-y-1.5">
                    <Label htmlFor="tr-type">What went wrong</Label>
                    <Select value={type} onValueChange={(v) => setType(v as ErrType)}>
                        <SelectTrigger id="tr-type" className="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            {(Object.keys(TYPE_LABEL) as ErrType[]).map((k) => (
                                <SelectItem key={k} value={k}>
                                    {TYPE_LABEL[k]}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                </div>
                <div className="space-y-2">
                    <Label id="tr-reach-l">Did it reach {person.pref}?</Label>
                    <TilePicker labelledBy="tr-reach-l" value={reach} onChange={(k) => (setReach(k as Reach), setE({}))} tiles={reachTiles(person.pref)} />
                </div>
                {reach !== 'no' ? (
                    <div className="space-y-2">
                        <Label id="tr-harm-l">
                            How much harm, so far? <Req />
                        </Label>
                        <div id="tr-harm" tabIndex={-1}>
                            <TilePicker labelledBy="tr-harm-l" value={harm || null} invalid={!!e2['tr-harm'] && !harm} onChange={(k) => (setHarm(k as Harm), setE({}))} tiles={HARM_TILES} />
                        </div>
                        <InputError message={e2['tr-harm']} />
                    </div>
                ) : null}
                {changed ? <p className="text-caption">Changes from the report are kept in its history, with your name.</p> : null}
            </>
        ),
        owner: (
            <>
                <div className="space-y-1.5">
                    <Label htmlFor="tr-owner">
                        Owner <Req />
                    </Label>
                    <Select value={owner || undefined} onValueChange={(v) => (setOwner(v), setE({}))}>
                        <SelectTrigger id="tr-owner" className="w-full" aria-invalid={!!e2['tr-owner']}>
                            <SelectValue placeholder="Who looks into it" />
                        </SelectTrigger>
                        <SelectContent>
                            {owners.map((o) => (
                                <SelectItem key={o.name} value={o.name}>
                                    {o.name} — {o.role}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={e2['tr-owner']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="tr-due">
                        Investigation due <Req />
                    </Label>
                    <DatePicker id="tr-due" label="Investigation due" value={due} invalid={!!e2['tr-due']} onChange={(v) => (setDue(v), setE({}))} />
                    <InputError message={e2['tr-due']} />
                    <p className="text-caption">It shows in the owner’s All Tasks and My Day with this date, and becomes overdue the day after, in NZ time.</p>
                </div>
                {makesIncident ? (
                    <Notice tone="warning" title="An incident is made when you save">
                        {`It reached ${person.pref} with ${harmTone(now).label.toLowerCase()}. The incident carries the summary — “${summaryOf({ pid: e.pid, type })}” — never the accounts.`}
                    </Notice>
                ) : null}
            </>
        ),
        review: (
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={ClipboardCheck} title="The report" onEdit={() => setStep(0)}>
                    <ReviewRow label="What went wrong" value={TYPE_LABEL[type]} />
                    <ReviewRow label="Reach & harm" value={harmTone(now).label} />
                </ReviewCard>
                <ReviewCard icon={UserRound} title="Owner & due date" onEdit={() => setStep(1)}>
                    <ReviewRow label="Owner" value={owner} />
                    <ReviewRow label="Investigation due" value={labelIso(due)} />
                    <ReviewRow label="Incident" value={makesIncident ? 'Made when you save' : e.incident ?? 'None'} />
                </ReviewCard>
                <div className="sm:col-span-2">
                    <InputError message={e2['tr-save']} />
                </div>
            </div>
        ),
    };
    const dirty = changed || owner !== owner0 || due !== due0;
    return (
        <>
            <WizardShell
                open
                onClose={() => (phase === 'sending' ? undefined : phase === 'done' || !dirty ? onClose() : setDiscard(true))}
                onCloseAutoFocus={restore(returnFocus)}
                title={`Triage ${e.id} — ${person.legal}`}
                description="Check the report, choose the owner and due date, then save."
                railIcon={ClipboardCheck}
                railTitle={`Triage ${e.id}`}
                railSub={`${person.pref} · reported ${e.reported.at}`}
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
                            {phase === 'sending' ? 'Saving…' : 'Save the triage'}
                        </Button>
                    )
                }
                success={
                    phase === 'done' ? (
                        <WizardSuccessPane
                            title="Triaged"
                            blurb={`${e.id} is with ${owner}, due ${labelIso(due)}. The “reported” alert stops.${makesIncident ? ' An incident was made with the summary only.' : ''}`}
                            actions={
                                <Button type="button" autoFocus onClick={onClose}>
                                    Done
                                </Button>
                            }
                        />
                    ) : undefined
                }
                maxWidth="min(94vw, 1000px)"
                maxHeight="min(88vh, 820px)"
            >
                <WizardStepPane>
                    <div className="space-y-4">{body[cur]}</div>
                </WizardStepPane>
            </WizardShell>
            <ConfirmDialog open={discard} onClose={() => setDiscard(false)} onConfirm={() => (setDiscard(false), onClose())} title="Discard the triage?" description="Nothing has been saved." confirmText="Discard" cancelText="Keep going" />
        </>
    );
}

/* ═════════════ A note (append-only) ═════════════ */
export function NoteDialog({ error: e, onClose, returnFocus }: { error: MedError; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const patch = usePatch();
    const [text, setText] = useState('');
    const [err, setErr] = useState('');
    function save() {
        if (text.trim().length < 10) return (setErr('Write the note — at least 10 characters.'), focusFirst({ 'nt-text': 'x' }));
        if (s.route.scenario === 'offline') return setErr(cantSaveOffline);
        patch(e, { notes: [...e.notes, { by: me.name, at: stamp(), text: text.trim() }] });
        s.toast('success', `Note added to ${e.id}.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Add a note — ${e.id}, ${PEOPLE[e.pid].pref}`}
            description="What you found or did. Notes are added, never edited — to correct one, add another."
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Add the note</Button>
                </>
            }
        >
            <div className="space-y-1.5">
                <Label htmlFor="nt-text">
                    Note <Req />
                </Label>
                <Textarea id="nt-text" rows={4} value={text} aria-invalid={!!err} onChange={(ev) => (setText(ev.target.value), setErr(''))} />
                <InputError message={err} />
                <p className="text-caption">Kept inside this report — never copied to the incident, Control Room or tasks.</p>
            </div>
        </Modal>
    );
}

/* ═════════════ An action (owner and due date) ═════════════ */
export function ActionDialog({ error: e, onClose, returnFocus }: { error: MedError; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const patch = usePatch();
    const owners = OWNERS.filter((o) => o.houses.includes(PEOPLE[e.pid].house));
    const [what, setWhat] = useState('');
    const [owner, setOwner] = useState(owners.some((o) => o.name === me.name) ? me.name : '');
    const [due, setDue] = useState('');
    const [x, setX] = useState<Record<string, string>>({});
    const last = Math.max(...allErrors(s.rt).flatMap((y) => y.actions.map((a) => Number(a.id.slice(2)))));
    function save() {
        const v: Record<string, string> = {};
        if (what.trim().length < 5) v['ac-what'] = 'Say what will be done.';
        if (!owner) v['ac-owner'] = 'Choose who does it.';
        if (!due) v['ac-due'] = 'Choose when it’s due.';
        else if (due < TODAY_ISO) v['ac-due'] = 'The due date can’t be in the past.';
        setX(v);
        if (Object.keys(v).length) return focusFirst(v);
        if (s.route.scenario === 'offline') return setX({ 'ac-what': cantSaveOffline });
        const a: Action = { id: `A-${last + 1}`, what: what.trim(), owner, due: labelIso(due), dueIso: due };
        patch(e, { stage: 'actions', actions: [a, ...e.actions] }, `${a.id} added by ${me.name} — ${owner}, due ${a.due}`);
        s.toast('success', `${a.id} added. It shows in ${owner}’s All Tasks, due ${a.due}.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Add an action — ${e.id}, ${PEOPLE[e.pid].pref}`}
            description="Something that changes so it’s less likely to happen again. Each action has an owner and a due date."
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Add the action</Button>
                </>
            }
        >
            <div className="space-y-1.5">
                <Label htmlFor="ac-what">
                    What will be done <Req />
                </Label>
                <Input id="ac-what" value={what} aria-invalid={!!x['ac-what']} placeholder="For example: store packs in time order, labelled on the front" onChange={(ev) => (setWhat(ev.target.value), setX({}))} />
                <InputError message={x['ac-what']} />
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-1.5">
                    <Label htmlFor="ac-owner">
                        Owner <Req />
                    </Label>
                    <Select value={owner || undefined} onValueChange={(v) => (setOwner(v), setX({}))}>
                        <SelectTrigger id="ac-owner" className="w-full" aria-invalid={!!x['ac-owner']}>
                            <SelectValue placeholder="Who does it" />
                        </SelectTrigger>
                        <SelectContent>
                            {owners.map((o) => (
                                <SelectItem key={o.name} value={o.name}>
                                    {o.name} — {o.role}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                    <InputError message={x['ac-owner']} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="ac-due">
                        Due <Req />
                    </Label>
                    <DatePicker id="ac-due" label="Due" value={due} invalid={!!x['ac-due']} onChange={(v) => (setDue(v), setX({}))} />
                    <InputError message={x['ac-due']} />
                </div>
            </div>
            {e.stage === 'investigating' ? <p className="text-caption">Adding the first action moves {e.id} to the actions stage.</p> : null}
        </Modal>
    );
}

/* ═════════════ Mark an action done ═════════════ */
export function DoneDialog({ error: e, action: a, onClose, returnFocus }: { error: MedError; action: Action; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const patch = usePatch();
    const [note, setNote] = useState('');
    const [err, setErr] = useState('');
    function save() {
        if (note.trim().length < 3) return (setErr('Say what was done.'), focusFirst({ 'dn-note': 'x' }));
        if (s.route.scenario === 'offline') return setErr(cantSaveOffline);
        patch(e, { actions: e.actions.map((y) => (y.id === a.id ? { ...y, done: { by: me.name, at: stamp(), note: note.trim() } } : y)) });
        const left = e.actions.filter((y) => !y.done && y.id !== a.id).length;
        s.toast('success', `${a.id} is done.${left ? ` ${left} still open on ${e.id}.` : ` No actions left on ${e.id}.`}`);
        onClose();
    }
    return (
        <Modal
            title={`Mark ${a.id} done`}
            description={`${e.id} · ${a.what}`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Mark it done</Button>
                </>
            }
        >
            <div className="space-y-1.5">
                <Label htmlFor="dn-note">
                    What was done <Req />
                </Label>
                <Textarea id="dn-note" rows={3} value={note} aria-invalid={!!err} onChange={(ev) => (setNote(ev.target.value), setErr(''))} />
                <InputError message={err} />
            </div>
        </Modal>
    );
}

/* ═════════════ Telling the person (Q8) ═════════════ */
export function DiscloseDialog({ error: e, onClose, returnFocus }: { error: MedError; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const patch = usePatch();
    const person = PEOPLE[e.pid];
    const d = e.disclosure;
    const [state, setState] = useState<'' | 'told' | 'notYet'>(d?.state ?? '');
    const [toldP, setToldP] = useState(d?.who?.includes('person') ?? false);
    const [toldW, setToldW] = useState(d?.who?.includes('whanau') ?? false);
    const [how, setHow] = useState(d?.how ?? '');
    const [when, setWhen] = useState('');
    const [why, setWhy] = useState(d?.state === 'notYet' ? d.why ?? '' : '');
    const [x, setX] = useState<Record<string, string>>({});
    function save() {
        const v: Record<string, string> = {};
        if (!state) v['ds-state'] = `Say whether ${person.pref} has been told.`;
        if (state === 'told') {
            if (!toldP && !toldW) v['ds-who'] = 'Choose who was told.';
            if (!how.trim()) v['ds-how'] = 'Say how — for example in person, or by phone.';
            if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/.test(when)) v['ds-when'] = 'Choose when.';
            else if (when > NOW_LOCAL) v['ds-when'] = 'It can’t be in the future.';
        }
        if (state === 'notYet' && why.trim().length < 10) v['ds-why'] = 'Say why not yet, and when it will happen.';
        setX(v);
        if (Object.keys(v).length) return focusFirst(v);
        if (s.route.scenario === 'offline') return setX({ 'ds-state': cantSaveOffline });
        const who = [toldP ? 'person' : '', toldW ? 'whanau' : ''].filter(Boolean);
        const next = state === 'told' ? { state: 'told' as const, who, whanauName: person.whanau, by: me.name, at: localLabel(when), how: how.trim() } : { state: 'notYet' as const, why: why.trim() };
        patch(e, { disclosure: next }, state === 'told' ? `Telling the person recorded by ${me.name}` : `“Not told yet” recorded by ${me.name}`);
        s.toast('success', state === 'told' ? `Recorded: ${who.map((w) => (w === 'person' ? person.pref : person.whanau)).join(' and ')} told.` : 'Recorded as not told yet — it’s needed before closing.');
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Telling the person — ${e.id}, ${person.pref}`}
            description="Open disclosure: telling the person what happened, and whānau, a welfare guardian or an EPOA where right. It’s recorded before an error that reached them can close."
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Save</Button>
                </>
            }
        >
            <div className="space-y-2">
                <Label id="ds-state-l">
                    Has {person.pref} been told? <Req />
                </Label>
                <div id="ds-state" tabIndex={-1}>
                    <TilePicker
                        labelledBy="ds-state-l"
                        value={state || null}
                        invalid={!!x['ds-state'] && !state}
                        onChange={(k) => (setState(k as 'told' | 'notYet'), setX({}))}
                        tiles={[
                            { key: 'told', label: 'Yes — told', description: 'Say who, how and when', icon: MessageCircleMore },
                            { key: 'notYet', label: 'Not yet', description: 'Say why, and when it will happen', icon: History },
                        ]}
                    />
                </div>
                <InputError message={x['ds-state']} />
            </div>
            {state === 'told' ? (
                <>
                    <div className="space-y-2">
                        <Label id="ds-who-l">
                            Who was told <Req />
                        </Label>
                        <ul id="ds-who" tabIndex={-1} role="group" aria-labelledby="ds-who-l" className="divide-y rounded-lg border">
                            <li className="flex items-center gap-3 px-3 py-2.5">
                                <Checkbox id="ds-p" checked={toldP} onCheckedChange={(v) => (setToldP(v === true), setX({}))} />
                                <Label htmlFor="ds-p" className="font-normal">
                                    {person.pref}
                                </Label>
                            </li>
                            <li className="flex items-center gap-3 px-3 py-2.5">
                                <Checkbox id="ds-w" checked={toldW} onCheckedChange={(v) => (setToldW(v === true), setX({}))} />
                                <Label htmlFor="ds-w" className="font-normal">
                                    {person.whanau}
                                </Label>
                            </li>
                        </ul>
                        <InputError message={x['ds-who']} />
                    </div>
                    <div className="space-y-1.5">
                        <Label htmlFor="ds-how">
                            How <Req />
                        </Label>
                        <Input id="ds-how" value={how} aria-invalid={!!x['ds-how']} placeholder="For example: in person with Aroha; Wiki by phone" onChange={(ev) => (setHow(ev.target.value), setX({}))} />
                        <InputError message={x['ds-how']} />
                    </div>
                    <DateTimeField id="ds-when" label="When" value={when} onChange={(v) => (setWhen(v), setX({}))} error={x['ds-when']} />
                </>
            ) : state === 'notYet' ? (
                <div className="space-y-1.5">
                    <Label htmlFor="ds-why">
                        Why not yet, and when <Req />
                    </Label>
                    <Textarea id="ds-why" rows={3} value={why} aria-invalid={!!x['ds-why']} onChange={(ev) => (setWhy(ev.target.value), setX({}))} />
                    <InputError message={x['ds-why']} />
                </div>
            ) : null}
        </Modal>
    );
}

/* ═════════════ Reopen (Q5) ═════════════ */
export function ReopenDialog({ error: e, onClose, returnFocus }: { error: MedError; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const patch = usePatch();
    const [reason, setReason] = useState('');
    const [err, setErr] = useState('');
    const inc = e.incident ? incidentOf(s.rt, e.incident) : null;
    function save() {
        if (reason.trim().length < 10) return (setErr('Say why, in a sentence — at least 10 characters.'), focusFirst({ 'ro-reason': 'x' }));
        if (s.route.scenario === 'offline') return setErr(cantSaveOffline);
        patch(e, { stage: 'investigating', closed: undefined, reopened: [...(e.reopened ?? []), { by: me.name, at: stamp(), reason: reason.trim() }] });
        s.toast('success', `${e.id} is open again, with ${e.owner}. The close note stays in its history.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Reopen ${e.id} — ${PEOPLE[e.pid].pref}`}
            description={`Closed by ${e.closed?.by}, ${e.closed?.at}. It goes back to its owner, ${e.owner}.`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Reopen it</Button>
                </>
            }
        >
            <div className="space-y-1.5">
                <Label htmlFor="ro-reason">
                    Why it’s being reopened <Req />
                </Label>
                <Textarea id="ro-reason" rows={3} value={reason} aria-invalid={!!err} placeholder="For example: the same thing happened again on Friday." onChange={(ev) => (setReason(ev.target.value), setErr(''))} />
                <InputError message={err} />
            </div>
            {inc ? <p className="text-caption">{`Incident ${inc.id} stays as it is in Incidents (${INC_STATUS[inc.status].toLowerCase()}). Reopen it there if it needs to be.`}</p> : null}
        </Modal>
    );
}

/* ═════════════ Add your account (Q9) ═════════════ */
export function AccountDialog({ error: e, onClose, returnFocus }: { error: MedError; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const me = PERSONAS[s.route.persona];
    const patch = usePatch();
    const [text, setText] = useState('');
    const [err, setErr] = useState('');
    function save() {
        if (text.trim().length < 20) return (setErr('Say what you saw or did — at least 20 characters.'), focusFirst({ 'acc-text': 'x' }));
        if (s.route.scenario === 'offline') return setErr(cantSaveOffline);
        patch(e, { accounts: [...e.accounts, { by: me.name, at: stamp(), text: text.trim() }] });
        s.toast('success', `Your account is added to ${e.id}. Its owner is told.`);
        onClose();
    }
    return (
        <Modal
            width={720}
            title={`Add your account — ${e.id}, ${PEOPLE[e.pid].pref}`}
            description="What you saw or did. It’s added with your name and the time, and never edited."
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button onClick={save}>Add my account</Button>
                </>
            }
        >
            <div className="space-y-1.5">
                <Label htmlFor="acc-text">
                    Your account <Req />
                </Label>
                <Textarea id="acc-text" rows={4} value={text} aria-invalid={!!err} onChange={(ev) => (setText(ev.target.value), setErr(''))} />
                <InputError message={err} />
            </div>
        </Modal>
    );
}

/* ═════════════ Close the error — and its incident, through Incidents (Q6) ═════════════ */
export function CloseDialog({ error: e, onClose, returnFocus }: { error: MedError; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const patch = usePatch();
    const inc = e.incident ? incidentOf(s.rt, e.incident) : null;
    const closer = canCloseIncident(p);
    const incOpen = !!inc && inc.status !== 'closed';
    const [note, setNote] = useState('');
    const [outcome, setOutcome] = useState('');
    const [incNote, setIncNote] = useState('');
    const [x, setX] = useState<Record<string, string>>({});
    const [confirm, setConfirm] = useState(false);
    const blockers = closeBlockers(e);
    if (blockers.length)
        return (
            <Modal title={`${e.id} isn’t ready to close`} description="Closing needs every step done." onClose={onClose} onCloseAutoFocus={restore(returnFocus)}>
                <ul className="list-disc space-y-1 pl-5 text-sm">
                    {blockers.map((b) => (
                        <li key={b}>{b}</li>
                    ))}
                </ul>
            </Modal>
        );
    function check() {
        const v: Record<string, string> = {};
        if (note.trim().length < 10) v['cl-note'] = 'Say what was found and what changed — at least 10 characters.';
        if (incOpen && closer && !outcome.trim()) v['cl-outcome'] = 'Give the incident’s outcome, in a line.';
        setX(v);
        if (Object.keys(v).length) return focusFirst(v);
        if (s.route.scenario === 'offline') return setX({ 'cl-note': cantSaveOffline });
        setConfirm(true);
    }
    function done() {
        const at = stamp();
        patch(e, { stage: 'closed', closed: { by: me.name, at, note: note.trim() } }, ...(incOpen ? [closer ? `Incident ${inc!.id} reviewed and closed in Incidents by ${me.name}` : `Incident ${inc!.id} marked ready to close in Incidents`] : []));
        if (incOpen)
            s.update((rt) => ({
                ...rt,
                incidents: { ...rt.incidents, [inc!.id]: closer ? { status: 'closed', closed: { by: me.name, at, outcome: outcome.trim(), note: incNote.trim() || note.trim() } } : { ready: { by: me.name, at, note: note.trim() } } },
            }));
        s.toast('success', incOpen ? (closer ? `${e.id} is closed, and ${inc!.id} is reviewed and closed in Incidents.` : `${e.id} is closed. ${inc!.id} shows “Ready to close” in Incidents for ${WHO_CLOSES_INCIDENTS}.`) : `${e.id} is closed. ${e.reported.by} is told the outcome.`);
        onClose();
    }
    return (
        <>
            <Modal
                width={720}
                title={`Close ${e.id} — ${PEOPLE[e.pid].pref}`}
                description={`${TYPE_LABEL[e.type]} · ${harmTone(e).label} · owner ${e.owner}`}
                onClose={onClose}
                onCloseAutoFocus={restore(returnFocus)}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button onClick={check}>Close the error</Button>
                    </>
                }
            >
                <div className="space-y-1.5">
                    <Label htmlFor="cl-note">
                        Close note <Req />
                    </Label>
                    <Textarea id="cl-note" rows={3} value={note} aria-invalid={!!x['cl-note']} placeholder="What was found, what changed, and that the person was told." onChange={(ev) => (setNote(ev.target.value), setX({}))} />
                    <InputError message={x['cl-note']} />
                    <p className="text-caption">{`${e.reported.by} is told the outcome with this note.`}</p>
                </div>
                {incOpen && closer ? (
                    <ReviewCard icon={Siren} title={`${inc!.id} closes with it, through Incidents`}>
                        <p className="text-sm">It’s reviewed, then closed, in Incidents — the same checks as closing it there, with your name on both.</p>
                        <div className="space-y-1.5">
                            <Label htmlFor="cl-outcome">
                                Incident outcome <Req />
                            </Label>
                            <Input id="cl-outcome" maxLength={120} value={outcome} aria-invalid={!!x['cl-outcome']} aria-describedby="cl-outcome-count" onChange={(ev) => (setOutcome(ev.target.value), setX({}))} />
                            <p id="cl-outcome-count" className="text-caption">
                                {outcome.length} of 120 characters
                            </p>
                            <InputError message={x['cl-outcome']} />
                        </div>
                        <div className="space-y-1.5">
                            <Label htmlFor="cl-incnote">
                                Incident close note <span className="font-normal text-muted-foreground">(optional — your close note is used if empty)</span>
                            </Label>
                            <Textarea id="cl-incnote" rows={2} value={incNote} onChange={(ev) => setIncNote(ev.target.value)} />
                        </div>
                    </ReviewCard>
                ) : incOpen ? (
                    <Notice tone="neutral" icon={Siren} title="You don’t close incidents">
                        {`${inc!.id} gets your close note and shows “Ready to close — medication error closed” in Incidents, for ${WHO_CLOSES_INCIDENTS}.`}
                    </Notice>
                ) : null}
            </Modal>
            <ConfirmDialog
                open={confirm}
                variant="default"
                onClose={() => setConfirm(false)}
                onConfirm={() => (setConfirm(false), done())}
                title={`Close ${e.id}?`}
                description={incOpen ? (closer ? `${inc!.id} is reviewed and closed in Incidents with it. Reopening the error later doesn’t reopen the incident.` : `${inc!.id} is marked ready to close for ${WHO_CLOSES_INCIDENTS}.`) : 'It can be reopened later, with a reason.'}
                confirmText="Close it"
                cancelText="Go back"
            />
        </>
    );
}

/* ═════════════ Review and close an incident that’s ready (Q6; P07b Q4) ═════════════ */
export function IncidentCloseDialog({ incident: i, onClose, returnFocus }: { incident: MedIncident; onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const me = PERSONAS[p];
    const src = i.source === 'error' ? errorOf(s.rt, i.ref) : null;
    const closeNote = i.source === 'error' ? (src?.closed ? (redacted(p, src) ? 'Needs controlled-medicine access' : src.closed.note) : '') : i.ready?.note ?? '';
    const closedBy = i.source === 'error' ? src?.closed : i.ready;
    const [outcome, setOutcome] = useState('');
    const [note, setNote] = useState('');
    const [err, setErr] = useState('');
    const [confirm, setConfirm] = useState(false);
    function check() {
        if (!outcome.trim()) return (setErr('Give the outcome, in a line.'), focusFirst({ 'ic-outcome': 'x' }));
        if (s.route.scenario === 'offline') return setErr(cantSaveOffline);
        setConfirm(true);
    }
    return (
        <>
            <Modal
                width={720}
                title={`Review and close ${i.id}`}
                description={incidentTitle(s.rt, i)}
                onClose={onClose}
                onCloseAutoFocus={restore(returnFocus)}
                footer={
                    <>
                        <Button variant="outline" onClick={onClose}>
                            Cancel
                        </Button>
                        <Button onClick={check}>Review and close</Button>
                    </>
                }
            >
                <ReviewCard icon={Siren} title="From the medication side">
                    <ReviewRow label="Came from" value={i.source === 'error' ? `Medication error ${i.ref}` : i.source === 'loss' ? `Controlled medicine loss ${i.ref}` : `Controlled count discrepancy ${i.ref}`} />
                    <ReviewRow label="Closed there by" value={closedBy ? `${closedBy.by}, ${closedBy.at}` : '—'} />
                    <ReviewRow label="Close note" value={closeNote || '—'} />
                    <ReviewRow label="In Incidents" value={INC_STATUS[i.status]} />
                </ReviewCard>
                <div className="space-y-1.5">
                    <Label htmlFor="ic-outcome">
                        Outcome <Req />
                    </Label>
                    <Input id="ic-outcome" maxLength={120} value={outcome} aria-invalid={!!err} aria-describedby="ic-outcome-count" onChange={(ev) => (setOutcome(ev.target.value), setErr(''))} />
                    <p id="ic-outcome-count" className="text-caption">
                        {outcome.length} of 120 characters
                    </p>
                    <InputError message={err} />
                </div>
                <div className="space-y-1.5">
                    <Label htmlFor="ic-note">
                        Close note <span className="font-normal text-muted-foreground">(optional)</span>
                    </Label>
                    <Textarea id="ic-note" rows={2} value={note} onChange={(ev) => setNote(ev.target.value)} />
                </div>
            </Modal>
            <ConfirmDialog
                open={confirm}
                variant="default"
                onClose={() => setConfirm(false)}
                onConfirm={() => {
                    setConfirm(false);
                    s.update((rt) => ({ ...rt, incidents: { ...rt.incidents, [i.id]: { ...rt.incidents[i.id], status: 'closed', closed: { by: me.name, at: stamp(), outcome: outcome.trim(), note: note.trim() } } } }));
                    s.toast('success', `${i.id} is reviewed and closed in Incidents.`);
                    onClose();
                }}
                title={`Review and close ${i.id}?`}
                description="It’s reviewed, then closed, in Incidents — the same checks as doing it there."
                confirmText="Review and close"
                cancelText="Go back"
            />
        </>
    );
}

/* ═════════════ Export (Q4 ii) ═════════════ */
export function ExportDialog({ onClose, returnFocus }: { onClose: () => void; returnFocus?: () => HTMLElement | null }) {
    const s = useStore();
    const p = s.route.persona;
    const rows = errorsIn(s.rt, p).filter((e) => e.occurredIso >= addDays(TODAY_ISO, -90));
    const ctrl = rows.filter(isControlled);
    return (
        <Modal
            width={720}
            title="Export medication errors"
            description={`A CSV of the ${rows.length} reports from the last 90 days at your houses.`}
            onClose={onClose}
            onCloseAutoFocus={restore(returnFocus)}
            footer={
                <>
                    <Button variant="outline" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        onClick={() => {
                            s.toast('info', 'medication-errors-2026-09-28.csv — nothing downloads in the preview.');
                            onClose();
                        }}
                    >
                        <Download className="size-4" /> Download CSV
                    </Button>
                </>
            }
        >
            <ReviewCard icon={Download} title="What’s in it">
                <ReviewRow label="Every report" value="MED number, person, house, when it happened, what went wrong, reach and harm, stage, owner and dates" />
                <ReviewRow label="Accounts and notes" value="Included for the reports you can open in full" />
                <ReviewRow
                    label="Controlled medicines"
                    value={ctrl.length ? (cdView(p) ? `${ctrl.length} ${ctrl.length === 1 ? 'report is' : 'reports are'} included in full — you have controlled-medicine access` : `${ctrl.length} ${ctrl.length === 1 ? 'report is' : 'reports are'} included without the medicine, accounts or notes — they need controlled-medicine access`) : 'None in these reports'}
                />
            </ReviewCard>
            <p className="text-caption">Cells that start with =, +, − or @ are made safe for spreadsheets. The export is recorded in the audit log.</p>
        </Modal>
    );
}
