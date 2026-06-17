import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import {
    ReviewCard,
    ReviewRow,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
} from '@/components/wizard/shell';
import { Field, InfoCard, Ring, SelectInput, StepHead, TilePicker } from '@/components/wizard/primitives';
import { useForm, usePage } from '@inertiajs/react';
import {
    Activity,
    AlertTriangle,
    CheckCircle2,
    ClipboardList,
    Eye,
    HeartPulse,
    HelpCircle,
    ListTodo,
    type LucideIcon,
    Pill,
    Plus,
    Search,
    ShieldAlert,
    ShieldQuestion,
    Trash2,
    Users,
    X,
} from 'lucide-react';
import { useMemo, useState } from 'react';

type ClientOpt = { id: number; first_name: string; last_name: string };
type StaffOpt = { id: number; name: string };
type FollowupDraft = { notes: string; assigned_to_user_id: string; due_at: string };

type Mode = 'incident' | 'near_miss';

type ReportForm = {
    type: string;
    client_id: string;
    shift_id: string;
    description: string;
    severity: string;
    potential_severity: string;
    potential_consequence: string;
    hazard: string;
    immediate_action_taken: string;
    witnesses: string;
    is_notifiable: boolean;
    followups: FollowupDraft[];
    stay: boolean;
};

type SetData = <K extends keyof ReportForm>(key: K, value: ReportForm[K]) => void;

export function IncidentReportDialog({
    open,
    onClose,
    mode,
    clients,
    staff,
    prefill,
    onOpenIncident,
}: {
    open: boolean;
    onClose: () => void;
    mode: Mode;
    clients: ClientOpt[];
    staff: StaffOpt[];
    prefill?: { client_id?: number | null; shift_id?: number | null };
    onOpenIncident?: (id: number) => void;
}) {
    const isNearMiss = mode === 'near_miss';
    const [stepIndex, setStepIndex] = useState(0);
    const [submitted, setSubmitted] = useState(false);
    const page = usePage().props as { flash?: { created_incident_id?: number; error?: string } };

    const form = useForm<ReportForm>({
        type: isNearMiss ? 'near_miss' : '',
        client_id: prefill?.client_id ? String(prefill.client_id) : '',
        shift_id: prefill?.shift_id ? String(prefill.shift_id) : '',
        description: '',
        severity: 'low',
        potential_severity: '',
        potential_consequence: '',
        hazard: '',
        immediate_action_taken: '',
        witnesses: '',
        is_notifiable: false,
        followups: [],
        stay: true,
    });
    const d = form.data;

    const clientOptions = clients.map((c) => ({ value: String(c.id), label: `${c.first_name} ${c.last_name}`.trim() }));

    /* ---- step model (branches on mode) ---- */
    const steps = useMemo(
        () =>
            isNearMiss
                ? [
                      { key: 'people', label: 'Who & where', blurb: 'Blame-free — thanks for reporting', icon: Users },
                      { key: 'what', label: 'What happened', blurb: 'The near miss', icon: Eye },
                      { key: 'could', label: 'What could have happened', blurb: 'Potential & hazard', icon: AlertTriangle },
                      { key: 'notifiable', label: 'Dangerous occurrence', blurb: 'Quick WorkSafe check', icon: ShieldQuestion },
                      { key: 'followups', label: 'Follow-ups', blurb: 'Optional tasks', icon: ListTodo },
                      { key: 'review', label: 'Review', blurb: 'Submit', icon: CheckCircle2 },
                  ]
                : [
                      { key: 'people', label: 'Type & people', blurb: 'What and who', icon: ClipboardList },
                      { key: 'what', label: 'What happened', blurb: 'Describe it', icon: Search },
                      { key: 'severity', label: 'Severity & actions', blurb: 'Impact & response', icon: Activity },
                      { key: 'notifiable', label: 'WorkSafe check', blurb: 'NZ HSWA notifiable', icon: ShieldAlert },
                      { key: 'followups', label: 'Follow-ups', blurb: 'Assign tasks', icon: ListTodo },
                      { key: 'review', label: 'Review', blurb: 'Submit', icon: CheckCircle2 },
                  ],
        [isNearMiss],
    );
    const stepKey = steps[stepIndex].key;
    const lastIndex = steps.length - 1;

    /* ---- completeness ---- */
    const pct = useMemo(() => {
        const checks = [
            !!d.client_id,
            isNearMiss ? true : !!d.type,
            !!d.description,
            isNearMiss ? !!d.potential_severity : !!d.severity,
            true, // notifiable step always "answered" (boolean)
        ];
        return Math.round((checks.filter(Boolean).length / checks.length) * 100);
    }, [d, isNearMiss]);

    /* ---- per-step gate ---- */
    const stepValid = (key: string): boolean => {
        switch (key) {
            case 'people':
                return !!d.client_id && (isNearMiss || !!d.type);
            case 'what':
                return !!d.description.trim();
            case 'severity':
                return !!d.severity;
            case 'could':
                return !!d.potential_severity;
            default:
                return true;
        }
    };

    const submit = () => {
        form.transform((data) => ({
            ...data,
            client_id: data.client_id ? Number(data.client_id) : null,
            shift_id: data.shift_id ? Number(data.shift_id) : null,
            followups: data.followups.filter((f) => f.notes.trim()),
        }));
        form.post('/incidents', {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (pg) => {
                const flash = (pg.props as { flash?: { error?: string } }).flash;
                if (!flash?.error) setSubmitted(true);
            },
        });
    };

    const reset = () => {
        form.reset();
        form.clearErrors();
        setStepIndex(0);
        setSubmitted(false);
    };

    const newId = page.flash?.created_incident_id;

    /* ---- follow-up rows ---- */
    const addFollowup = () => form.setData('followups', [...d.followups, { notes: '', assigned_to_user_id: '', due_at: '' }]);
    const updateFollowup = (i: number, patch: Partial<FollowupDraft>) =>
        form.setData('followups', d.followups.map((f, idx) => (idx === i ? { ...f, ...patch } : f)));
    const removeFollowup = (i: number) => form.setData('followups', d.followups.filter((_, idx) => idx !== i));

    const success = submitted ? (
        <WizardSuccessPane
            title={isNearMiss ? 'Near miss reported — thank you' : 'Incident reported'}
            blurb={
                <>
                    {isNearMiss ? 'Near-miss reporting helps everyone stay safe.' : 'The incident has been recorded as a draft.'}
                    {newId ? <> Reference <span className="font-semibold">INC-{newId}</span>.</> : null}
                </>
            }
            actions={
                <>
                    {newId && onOpenIncident ? (
                        <Button onClick={() => { onOpenIncident(newId); }}>Open incident</Button>
                    ) : null}
                    <Button variant="outline" onClick={reset}>
                        Report another
                    </Button>
                    <Button variant="ghost" onClick={onClose}>
                        Done
                    </Button>
                </>
            }
        />
    ) : undefined;

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={isNearMiss ? 'Report a near miss' : 'Report an incident'}
            description={isNearMiss ? 'A blame-free, under-a-minute near-miss report.' : 'Report an incident for review.'}
            railIcon={isNearMiss ? Eye : ShieldAlert}
            railTitle={isNearMiss ? 'Near miss' : 'Incident report'}
            railSub={isNearMiss ? 'Leading safety indicator' : 'System of record'}
            steps={steps}
            stepIndex={stepIndex}
            onStepClick={(i) => setStepIndex(i)}
            pct={pct}
            footerStart={!submitted ? <Ring pct={pct} size={40} /> : undefined}
            footerEnd={
                submitted ? undefined : (
                    <div className="flex items-center gap-2">
                        {stepIndex > 0 ? (
                            <Button variant="outline" onClick={() => setStepIndex((i) => Math.max(0, i - 1))}>
                                Back
                            </Button>
                        ) : null}
                        {stepIndex < lastIndex ? (
                            <Button onClick={() => setStepIndex((i) => Math.min(lastIndex, i + 1))} disabled={!stepValid(stepKey)}>
                                Next
                            </Button>
                        ) : (
                            <Button onClick={submit} disabled={form.processing || !d.client_id || !d.description.trim()}>
                                {isNearMiss ? 'Submit near miss' : 'Submit incident'}
                            </Button>
                        )}
                    </div>
                )
            }
            success={success}
        >
            <WizardStepPane>
                {stepKey === 'people' ? <PeopleStep d={d} setData={form.setData} errors={form.errors} clientOptions={clientOptions} isNearMiss={isNearMiss} /> : null}
                {stepKey === 'what' ? <WhatStep d={d} setData={form.setData} isNearMiss={isNearMiss} /> : null}
                {stepKey === 'severity' ? <SeverityStep d={d} setData={form.setData} /> : null}
                {stepKey === 'could' ? <CouldStep d={d} setData={form.setData} /> : null}
                {stepKey === 'notifiable' ? <NotifiableStep d={d} setData={form.setData} isNearMiss={isNearMiss} /> : null}
                {stepKey === 'followups' ? (
                    <FollowupsStep d={d} staff={staff} onAdd={addFollowup} onUpdate={updateFollowup} onRemove={removeFollowup} />
                ) : null}
                {stepKey === 'review' ? <ReviewStep d={d} isNearMiss={isNearMiss} clients={clients} staff={staff} goto={setStepIndex} /> : null}
            </WizardStepPane>
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Steps                                                              */
/* ------------------------------------------------------------------ */

const INCIDENT_TYPE_TILES: { key: string; label: string; description?: string; icon: LucideIcon }[] = [
    { key: 'injury', label: 'Injury', icon: HeartPulse },
    { key: 'fall', label: 'Fall', icon: Activity },
    { key: 'behaviour', label: 'Behaviour', icon: Users },
    { key: 'medication', label: 'Medication', icon: Pill },
    { key: 'safeguarding', label: 'Safeguarding', icon: ShieldAlert },
    { key: 'property_damage', label: 'Property damage', icon: AlertTriangle },
    { key: 'missing_person', label: 'Missing person', icon: Search },
    { key: 'complaint', label: 'Complaint', icon: X },
    { key: 'other', label: 'Other', icon: HelpCircle },
];

function PeopleStep({ d, setData, errors, clientOptions, isNearMiss }: { d: { type: string; client_id: string }; setData: SetData; errors: Partial<Record<string, string>>; clientOptions: { value: string; label: string }[]; isNearMiss: boolean }) {
    return (
        <div className="flex flex-col gap-5">
            <StepHead
                icon={isNearMiss ? Eye : ClipboardList}
                title={isNearMiss ? 'Report a near miss' : 'Type & people'}
                blurb={isNearMiss ? 'No harm was done — this is blame-free and helps prevent future incidents. Just the essentials.' : 'Choose the kind of incident and who it involves.'}
            />
            {!isNearMiss ? (
                <Field label="Incident type" required error={errors.type}>
                    <TilePicker value={d.type} onChange={(v) => setData('type', v)} options={INCIDENT_TYPE_TILES} cols={3} />
                </Field>
            ) : null}
            <Field label="Client" required error={errors.client_id}>
                <SelectInput value={d.client_id} onChange={(v) => setData('client_id', v)} placeholder="Select client" options={clientOptions} />
            </Field>
        </div>
    );
}

function WhatStep({ d, setData, isNearMiss }: { d: { description: string }; setData: SetData; isNearMiss: boolean }) {
    return (
        <div className="flex flex-col gap-5">
            <StepHead icon={Search} title="What happened" blurb={isNearMiss ? 'Briefly, what happened (or nearly happened)?' : 'Describe what happened, factually.'} />
            <Field label="Description" required>
                <Textarea rows={6} value={d.description} onChange={(e) => setData('description', e.target.value)} placeholder="What happened, where, and who was involved…" />
            </Field>
            <InfoCard icon={ListTodo} tone="info">
                Photos &amp; documents can be attached from the incident once it&apos;s created (while it&apos;s a draft).
            </InfoCard>
        </div>
    );
}

const SEVERITY_TILES = [
    { key: 'low', label: 'Low', description: 'No / minor harm', icon: CheckCircle2 },
    { key: 'medium', label: 'Medium', description: 'Some harm or risk', icon: Activity },
    { key: 'high', label: 'High', description: 'Serious harm or risk', icon: AlertTriangle },
];

function SeverityStep({ d, setData }: { d: { severity: string; immediate_action_taken: string; witnesses: string }; setData: SetData }) {
    return (
        <div className="flex flex-col gap-5">
            <StepHead icon={Activity} title="Severity & immediate actions" blurb="How serious was it, and what was done straight away?" />
            <Field label="Severity" required>
                <TilePicker value={d.severity} onChange={(v) => setData('severity', v)} options={SEVERITY_TILES} cols={3} />
            </Field>
            <Field label="Immediate action taken">
                <Textarea rows={3} value={d.immediate_action_taken} onChange={(e) => setData('immediate_action_taken', e.target.value)} placeholder="First aid given, area made safe, GP called…" />
            </Field>
            <Field label="Witnesses">
                <Input value={d.witnesses} onChange={(e) => setData('witnesses', e.target.value)} placeholder="Names of any witnesses" />
            </Field>
        </div>
    );
}

const POTENTIAL_TILES = [
    { key: 'low', label: 'Low', description: 'Minor at worst', icon: CheckCircle2 },
    { key: 'medium', label: 'Medium', description: 'Some harm possible', icon: Activity },
    { key: 'high', label: 'High', description: 'Serious harm possible', icon: AlertTriangle },
    { key: 'critical', label: 'Critical', description: 'Could have been fatal', icon: ShieldAlert },
];

function CouldStep({ d, setData }: { d: { potential_severity: string; potential_consequence: string; hazard: string; immediate_action_taken: string }; setData: SetData }) {
    return (
        <div className="flex flex-col gap-5">
            <StepHead icon={AlertTriangle} title="What could have happened" blurb="Capture the potential — this is what makes near misses so valuable." />
            <Field label="Potential severity" required>
                <TilePicker value={d.potential_severity} onChange={(v) => setData('potential_severity', v)} options={POTENTIAL_TILES} cols={2} />
            </Field>
            <Field label="What could have happened">
                <Input value={d.potential_consequence} onChange={(e) => setData('potential_consequence', e.target.value)} placeholder="e.g. A resident could have fallen down the stairs" />
            </Field>
            <Field label="Hazard / contributing factor">
                <Input value={d.hazard} onChange={(e) => setData('hazard', e.target.value)} placeholder="e.g. Wet floor, no warning sign" />
            </Field>
            <Field label="Immediate control taken">
                <Textarea rows={2} value={d.immediate_action_taken} onChange={(e) => setData('immediate_action_taken', e.target.value)} placeholder="What did you do to make it safe?" />
            </Field>
        </div>
    );
}

function NotifiableStep({ d, setData, isNearMiss }: { d: { is_notifiable: boolean }; setData: SetData; isNearMiss: boolean }) {
    return (
        <div className="flex flex-col gap-5">
            <StepHead
                icon={isNearMiss ? ShieldQuestion : ShieldAlert}
                title={isNearMiss ? 'Dangerous occurrence check' : 'WorkSafe NZ notifiable check'}
                blurb="Under the Health and Safety at Work Act 2015, some events must be notified to WorkSafe NZ."
            />
            <InfoCard icon={ShieldAlert} tone="warn">
                {isNearMiss ? (
                    <>A no-harm event can still be a <span className="font-semibold">notifiable dangerous occurrence</span> (e.g. an uncontrolled escape, electric shock, or collapse). If unsure, flag it — a manager will confirm.</>
                ) : (
                    <>Notifiable events include a <span className="font-semibold">death</span>, a <span className="font-semibold">notifiable injury/illness</span> (e.g. hospitalisation), or a <span className="font-semibold">notifiable incident</span> (a serious risk to health or safety). If any apply, flag it.</>
                )}
            </InfoCard>
            <label className="flex items-center gap-2.5 rounded-lg border border-border p-3 text-sm">
                <input type="checkbox" checked={d.is_notifiable} onChange={(e) => setData('is_notifiable', e.target.checked)} className="h-4 w-4 rounded border-border" />
                <span className="font-medium text-foreground">This looks WorkSafe NZ–notifiable — flag it for the manager to confirm.</span>
            </label>
        </div>
    );
}

function FollowupsStep({ d, staff, onAdd, onUpdate, onRemove }: { d: { followups: FollowupDraft[] }; staff: StaffOpt[]; onAdd: () => void; onUpdate: (i: number, patch: Partial<FollowupDraft>) => void; onRemove: (i: number) => void }) {
    const staffOptions = staff.map((s) => ({ value: String(s.id), label: s.name }));
    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={ListTodo} title="Follow-ups" blurb="Optional — add any operational tasks to track (e.g. update the care plan)." />
            {d.followups.map((f, i) => (
                <div key={i} className="flex flex-col gap-2 rounded-xl border border-border p-3">
                    <Field label={`Task ${i + 1}`}>
                        <Textarea rows={2} value={f.notes} onChange={(e) => onUpdate(i, { notes: e.target.value })} placeholder="What needs doing?" />
                    </Field>
                    <div className="grid gap-2 sm:grid-cols-2">
                        <Field label="Assign to">
                            <SelectInput value={f.assigned_to_user_id} onChange={(v) => onUpdate(i, { assigned_to_user_id: v })} placeholder="Unassigned" options={staffOptions} />
                        </Field>
                        <Field label="Due">
                            <Input type="date" value={f.due_at} onChange={(e) => onUpdate(i, { due_at: e.target.value })} />
                        </Field>
                    </div>
                    <Button variant="ghost" size="sm" className="self-end text-status-critical hover:text-status-critical" onClick={() => onRemove(i)}>
                        <Trash2 className="mr-1.5 h-3.5 w-3.5" /> Remove
                    </Button>
                </div>
            ))}
            <Button variant="outline" size="sm" className="self-start" onClick={onAdd}>
                <Plus className="mr-1.5 h-3.5 w-3.5" /> Add follow-up
            </Button>
        </div>
    );
}

function ReviewStep({ d, isNearMiss, clients, staff, goto }: { d: IncidentReportData; isNearMiss: boolean; clients: ClientOpt[]; staff: StaffOpt[]; goto: (i: number) => void }) {
    const client = clients.find((c) => String(c.id) === d.client_id);
    const clientName = client ? `${client.first_name} ${client.last_name}` : '—';
    const staffName = (id: string) => staff.find((s) => String(s.id) === id)?.name ?? 'Unassigned';
    return (
        <div className="flex flex-col gap-4">
            <StepHead icon={CheckCircle2} title="Review & submit" blurb="Check the details, then submit." />
            <div className="grid gap-3 sm:grid-cols-2">
                <ReviewCard icon={Users} title={isNearMiss ? 'Who & where' : 'Type & people'} onEdit={() => goto(0)}>
                    {!isNearMiss ? <ReviewRow label="Type" value={d.type ? d.type.replace(/_/g, ' ') : undefined} /> : <ReviewRow label="Type" value="Near miss" />}
                    <ReviewRow label="Client" value={clientName} />
                </ReviewCard>
                <ReviewCard icon={Search} title="What happened" onEdit={() => goto(1)}>
                    <ReviewRow label="Description" value={d.description} />
                </ReviewCard>
                {isNearMiss ? (
                    <ReviewCard icon={AlertTriangle} title="Potential" onEdit={() => goto(2)}>
                        <ReviewRow label="Could have been" value={d.potential_severity} />
                        <ReviewRow label="Consequence" value={d.potential_consequence} />
                        <ReviewRow label="Hazard" value={d.hazard} />
                    </ReviewCard>
                ) : (
                    <ReviewCard icon={Activity} title="Severity & actions" onEdit={() => goto(2)}>
                        <ReviewRow label="Severity" value={d.severity} />
                        <ReviewRow label="Immediate action" value={d.immediate_action_taken} />
                    </ReviewCard>
                )}
                <ReviewCard icon={ShieldAlert} title="WorkSafe" onEdit={() => goto(3)}>
                    <ReviewRow label="Notifiable" value={d.is_notifiable ? 'Flagged for confirmation' : 'No'} />
                </ReviewCard>
                {d.followups.filter((f) => f.notes.trim()).length ? (
                    <ReviewCard icon={ListTodo} title="Follow-ups" span onEdit={() => goto(4)}>
                        {d.followups.filter((f) => f.notes.trim()).map((f, i) => (
                            <ReviewRow key={i} label={staffName(f.assigned_to_user_id)} value={f.notes} />
                        ))}
                    </ReviewCard>
                ) : null}
            </div>
        </div>
    );
}

type IncidentReportData = {
    type: string;
    client_id: string;
    description: string;
    severity: string;
    potential_severity: string;
    potential_consequence: string;
    hazard: string;
    immediate_action_taken: string;
    witnesses: string;
    is_notifiable: boolean;
    followups: FollowupDraft[];
};
