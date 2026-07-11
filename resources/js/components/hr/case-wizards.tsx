/* The HR Cases & Disciplinary wizards — New case, Add timeline event, Add
 * disciplinary action, Edit disciplinary action. Each is built on the shared
 * HR wizard kit (WizardShell + primitives) so they are visually identical to
 * the Assign-asset / Leave-request modals. They replace the old full-page
 * create/edit forms: every field and validation rule from those pages is
 * preserved (including the NZ good-faith checklist gate), just reorganised
 * into steps with a review pane. */
import { useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    Calendar,
    CheckCircle2,
    ClipboardCheck,
    FileText,
    Folder,
    Gavel,
    HeartHandshake,
    Mail,
    MessageSquareWarning,
    Phone,
    Scale,
    ScrollText,
    Search,
    Shield,
    ShieldAlert,
    StickyNote,
    User,
    Users,
} from 'lucide-react';
import { useMemo, useState } from 'react';
import { toast } from 'sonner';

import {
    Field,
    FieldErr,
    InfoCard,
    ReviewCard,
    ReviewRow,
    Segmented,
    SelectInput,
    StepHead,
    TilePicker,
    useWizard,
    WizardShell,
    WizardStepPane,
    WizardSuccessPane,
    type IconType,
    type WizardStep,
} from '@/components/hr/wizard';
import { Button } from '@/components/ui/button';
import { Card } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { fireConfetti } from '@/lib/confetti';

/* ------------------------------------------------------------------ */
/*  Public types                                                      */
/* ------------------------------------------------------------------ */

export type CaseStaffOption = {
    id: number;
    name: string;
    email?: string | null;
};

export type CaseOption = { value: string; label: string };

export type CaseIncidentOption = {
    id: number;
    reference: string;
    title: string;
    type: string;
    severity: string;
    status: string;
    occurred_at: string | null;
    client: string | null;
};

export type GoodFaithCheckOption = { key: string; label: string };

/** Form-ready disciplinary action payload as serialised by HrCaseController@show. */
export type DisciplinaryActionForm = {
    id: number;
    employee_user_id: string;
    stage: string;
    action_type: string;
    allegation_summary: string;
    investigation_notes: string | null;
    investigator_user_id: string;
    notice_issued_at: string | null;
    notice_document_path: string | null;
    meeting_scheduled_at: string | null;
    meeting_location: string | null;
    support_person_advised: boolean;
    meeting_held_at: string | null;
    meeting_notes: string | null;
    meeting_attendees: string[];
    employee_response: string | null;
    response_deadline: string | null;
    outcome: string | null;
    outcome_rationale: string | null;
    outcome_document_path: string | null;
    good_faith_checklist: Record<string, boolean>;
    appeal_received: boolean;
    appeal_notes: string | null;
    appeal_outcome: string | null;
};

/* ------------------------------------------------------------------ */
/*  Shared helpers                                                    */
/* ------------------------------------------------------------------ */

const NONE = '__none__';

const initials = (name: string) =>
    name
        .split(/\s+/)
        .filter(Boolean)
        .slice(0, 2)
        .map((p) => p[0]?.toUpperCase() ?? '')
        .join('');

const fdate = (value?: string | null) => {
    if (!value) return undefined;
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', { day: '2-digit', month: 'short', year: 'numeric' });
};

const fdatetime = (value?: string | null) => {
    if (!value) return undefined;
    const d = new Date(value);
    return Number.isNaN(d.getTime())
        ? value
        : d.toLocaleDateString('en-NZ', {
              day: '2-digit',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          });
};

/** Flash error carried by an Inertia redirect — `back()->with('error')` fires
 *  onSuccess, not onError (see reference_inertia_flash_error). */
function pageFlashError(page: { props: Record<string, unknown> }): string | null {
    const flash = page.props.flash as { error?: string } | undefined;
    return flash?.error ?? null;
}

function optionLabel(options: CaseOption[], value: string): string | undefined {
    return options.find((o) => o.value === value)?.label;
}

/** Searchable staff pick-list (Assign-asset contract). */
function StaffPickList({
    staff,
    value,
    onPick,
    withEmail,
}: {
    staff: CaseStaffOption[];
    value: string;
    onPick: (id: string) => void;
    withEmail?: boolean;
}) {
    const [search, setSearch] = useState('');
    const filtered = useMemo(() => {
        const q = search.trim().toLowerCase();
        if (!q) return staff;
        return staff.filter((s) => `${s.name} ${s.email ?? ''}`.toLowerCase().includes(q));
    }, [search, staff]);

    return (
        <>
            <div className="relative mb-3">
                <Search className="pointer-events-none absolute top-1/2 left-2.5 h-4 w-4 -translate-y-1/2 text-muted-foreground" />
                <Input
                    value={search}
                    onChange={(e) => setSearch(e.target.value)}
                    placeholder="Search staff by name or email…"
                    className="pl-8"
                />
            </div>
            <div className="flex max-h-64 flex-col gap-1.5 overflow-y-auto">
                {filtered.map((s) => {
                    const active = String(s.id) === value;
                    return (
                        // eslint-disable-next-line no-restricted-syntax -- selector card, not a form button
                        <button
                            key={s.id}
                            type="button"
                            onClick={() => onPick(String(s.id))}
                            className={`flex items-center gap-3 rounded-xl border bg-card px-3 py-2.5 text-left transition-colors ${active ? 'border-primary bg-primary/[0.06]' : 'border-border hover:border-primary/50'}`}
                        >
                            <span className="grid h-9 w-9 flex-none place-items-center rounded-full bg-primary/12 text-[12.5px] font-bold text-primary">
                                {initials(s.name)}
                            </span>
                            <div className="min-w-0 flex-1">
                                <div className="text-[13.5px] font-bold">{s.name}</div>
                                {withEmail && s.email ? (
                                    <div className="truncate text-[11.5px] text-muted-foreground">{s.email}</div>
                                ) : null}
                            </div>
                            {active ? <CheckCircle2 className="h-5 w-5 text-primary" /> : null}
                        </button>
                    );
                })}
                {filtered.length === 0 ? (
                    <div className="py-6 text-center text-[13px] text-muted-foreground">
                        No staff match “{search}”.
                    </div>
                ) : null}
            </div>
        </>
    );
}

const CASE_TYPE_ICONS: Record<string, IconType> = {
    grievance: Scale,
    disciplinary: ShieldAlert,
    investigation: Search,
    welfare: HeartHandshake,
    complaint: MessageSquareWarning,
    other: Folder,
};

const EVENT_TYPE_ICONS: Record<string, IconType> = {
    note: StickyNote,
    meeting: Users,
    phone_call: Phone,
    letter: ScrollText,
    email: Mail,
    document: FileText,
    investigation_update: Search,
    other: Folder,
};

/* ================================================================== */
/*  New case                                                          */
/* ================================================================== */

const NEW_CASE_STEPS: readonly WizardStep[] = [
    { key: 'subject', label: 'Subject', blurb: 'Who the case concerns', icon: User },
    { key: 'details', label: 'Case details', blurb: 'Type, severity, summary', icon: FileText },
    { key: 'assign', label: 'Assignment', blurb: 'Owner & confidentiality', icon: Shield },
    { key: 'review', label: 'Review', blurb: 'Confirm & open', icon: CheckCircle2 },
];

export function NewCaseWizard({
    staff,
    caseTypes,
    severities,
    incidents,
    onClose,
    initial,
}: {
    staff: CaseStaffOption[];
    caseTypes: CaseOption[];
    severities: CaseOption[];
    incidents: CaseIncidentOption[];
    onClose: () => void;
    /** Optional safe prefill (e.g. escalating from an unsuccessful PIP). */
    initial?: {
        user_id?: string;
        case_type?: string;
        description?: string;
    };
}) {
    const wizard = useWizard(NEW_CASE_STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        user_id: initial?.user_id ?? '',
        case_type: initial?.case_type ?? '',
        severity: '',
        title: '',
        description: initial?.description ?? '',
        assigned_to: '',
        is_confidential: false as boolean,
        linked_incident_ids: [] as number[],
    });

    const subject = staff.find((s) => String(s.id) === form.data.user_id) ?? null;
    const assignee = staff.find((s) => String(s.id) === form.data.assigned_to) ?? null;
    const linkedIncidents = incidents.filter((incident) =>
        form.data.linked_incident_ids.includes(incident.id),
    );

    const toggleIncident = (incidentId: number) => {
        form.setData(
            'linked_incident_ids',
            form.data.linked_incident_ids.includes(incidentId)
                ? form.data.linked_incident_ids.filter((id) => id !== incidentId)
                : [...form.data.linked_incident_ids, incidentId],
        );
    };

    const detailsValid =
        form.data.case_type !== '' && form.data.severity !== '' && form.data.title.trim() !== '';

    const stepInvalid =
        (wizard.index === 0 && !subject) || (wizard.index === 1 && !detailsValid);

    const submit = () => {
        form.post('/hr/cases', {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
                fireConfetti();
            },
            onError: () => toast.error('Please check the highlighted fields.'),
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Open HR case"
            description="Open a new HR case for investigation or action."
            railIcon={Folder}
            railTitle="New HR case"
            railSub="Cases & disciplinary"
            steps={NEW_CASE_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Case opened"
                        blurb={
                            <>
                                “{form.data.title}” has been opened
                                {subject ? <> for {subject.name}</> : null}. It now appears in the
                                case register.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button onClick={submit} disabled={form.processing || !subject || !detailsValid}>
                            {form.processing ? 'Opening…' : 'Open case'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={stepInvalid}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={User}
                        title="Who does this case concern?"
                        blurb="Pick the staff member the case is about."
                    />
                    <StaffPickList
                        staff={staff}
                        value={form.data.user_id}
                        onPick={(id) => form.setData('user_id', id)}
                        withEmail
                    />
                    <FieldErr>{form.errors.user_id}</FieldErr>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Case details"
                        blurb="Classify the case and summarise the situation."
                    />
                    <Field label="Case type" required error={form.errors.case_type}>
                        <TilePicker
                            value={form.data.case_type}
                            onChange={(v) => form.setData('case_type', v)}
                            cols={3}
                            options={caseTypes.map((t) => ({
                                key: t.value,
                                label: t.label,
                                icon: CASE_TYPE_ICONS[t.value] ?? Folder,
                            }))}
                        />
                    </Field>
                    <div className="mt-4">
                        <Field label="Severity" required error={form.errors.severity}>
                            <Segmented
                                value={form.data.severity}
                                onChange={(v) => form.setData('severity', v)}
                                options={severities.map((s) => ({ value: s.value, label: s.label }))}
                            />
                        </Field>
                    </div>
                    <div className="mt-4">
                        <Field label="Case title" required error={form.errors.title}>
                            <Input
                                value={form.data.title}
                                onChange={(e) => form.setData('title', e.target.value)}
                                placeholder="Brief summary of the case"
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Description" error={form.errors.description}>
                            <Textarea
                                rows={5}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Detailed description of the situation, including relevant dates, witnesses, and any initial actions taken…"
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={Shield}
                        title="Assignment & confidentiality"
                        blurb="Choose who runs the case and how widely it is visible."
                    />
                    <Field label="Assigned to" hint="optional" error={form.errors.assigned_to}>
                        <SelectInput
                            value={form.data.assigned_to || NONE}
                            onChange={(v) => form.setData('assigned_to', v === NONE ? '' : v)}
                            placeholder="Unassigned"
                            options={[
                                { value: NONE, label: 'Unassigned' },
                                ...staff.map((s) => ({ value: String(s.id), label: s.name })),
                            ]}
                        />
                    </Field>
                    <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-4">
                        <Checkbox
                            checked={form.data.is_confidential}
                            onCheckedChange={(checked) => form.setData('is_confidential', Boolean(checked))}
                            className="mt-0.5"
                        />
                        <span>
                            <span className="block text-[13px] font-semibold">Mark as confidential</span>
                            <span className="block text-[12.5px] text-muted-foreground">
                                Confidential cases are only visible to HR managers and assigned
                                personnel.
                            </span>
                        </span>
                    </label>
                    <div className="mt-4">
                        <Field
                            label="Linked incidents"
                            hint="optional — read-only references"
                            error={form.errors.linked_incident_ids}
                        >
                            {incidents.length > 0 ? (
                                <Card className="max-h-56 space-y-2 overflow-y-auto p-2">
                                    {incidents.map((incident) => {
                                        const checked =
                                            form.data.linked_incident_ids.includes(
                                                incident.id,
                                            );

                                        return (
                                            <label
                                                key={incident.id}
                                                className="flex cursor-pointer items-start gap-3 rounded-lg p-2.5 hover:bg-muted/60"
                                            >
                                                <Checkbox
                                                    checked={checked}
                                                    onCheckedChange={() =>
                                                        toggleIncident(incident.id)
                                                    }
                                                    className="mt-0.5"
                                                />
                                                <span className="min-w-0">
                                                    <span className="block text-sm font-medium">
                                                        {incident.reference} —{' '}
                                                        {incident.title}
                                                    </span>
                                                    <span className="block text-xs text-muted-foreground">
                                                        {incident.client ??
                                                            'Unknown client'}{' '}
                                                        · {incident.severity} ·{' '}
                                                        {incident.status.replace(
                                                            /_/g,
                                                            ' ',
                                                        )}
                                                    </span>
                                                </span>
                                            </label>
                                        );
                                    })}
                                </Card>
                            ) : (
                                <InfoCard icon={AlertTriangle}>
                                    No incidents are available for this
                                    organisation.
                                </InfoCard>
                            )}
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm & open"
                        blurb="Check the details, then open the case."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={User} title="Subject" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Staff member" value={subject?.name} />
                            <ReviewRow label="Email" value={subject?.email ?? undefined} />
                        </ReviewCard>
                        <ReviewCard icon={FileText} title="Case details" onEdit={() => wizard.goTo(1)}>
                            <ReviewRow label="Type" value={optionLabel(caseTypes, form.data.case_type)} />
                            <ReviewRow label="Severity" value={optionLabel(severities, form.data.severity)} />
                            <ReviewRow label="Title" value={form.data.title} />
                        </ReviewCard>
                        <ReviewCard icon={Shield} title="Assignment" onEdit={() => wizard.goTo(2)} span>
                            <ReviewRow label="Assigned to" value={assignee?.name ?? 'Unassigned'} />
                            <ReviewRow
                                label="Confidential"
                                value={form.data.is_confidential ? 'Yes — restricted visibility' : 'No'}
                            />
                            <ReviewRow label="Description" value={form.data.description || undefined} />
                            <ReviewRow
                                label="Linked incidents"
                                value={
                                    linkedIncidents.length > 0
                                        ? linkedIncidents
                                              .map(
                                                  (incident) =>
                                                      incident.reference,
                                              )
                                              .join(', ')
                                        : 'None'
                                }
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Add timeline event                                                */
/* ================================================================== */

const EVENT_STEPS: readonly WizardStep[] = [
    { key: 'what', label: 'Event', blurb: 'Type & when', icon: Calendar },
    { key: 'details', label: 'Details', blurb: 'Title & visibility', icon: FileText },
    { key: 'review', label: 'Review', blurb: 'Confirm & add', icon: CheckCircle2 },
];

const VISIBILITY_OPTIONS = [
    { value: 'internal', label: 'Internal' },
    { value: 'restricted', label: 'Restricted' },
    { value: 'full', label: 'Full' },
] as const;

const VISIBILITY_BLURB: Record<string, string> = {
    internal: 'HR only.',
    restricted: 'Managers + HR (assigned, reporter, subject).',
    full: 'The case subject can see this event.',
};

export function CaseEventWizard({
    caseId,
    caseNumber,
    subjectName,
    eventTypes,
    onClose,
}: {
    caseId: number;
    caseNumber: string;
    subjectName: string | null;
    eventTypes: CaseOption[];
    onClose: () => void;
}) {
    const wizard = useWizard(EVENT_STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        event_type: '',
        title: '',
        description: '',
        occurred_at: new Date().toISOString().slice(0, 16),
        visibility: 'internal',
    });

    const stepInvalid =
        (wizard.index === 0 && (form.data.event_type === '' || form.data.occurred_at === '')) ||
        (wizard.index === 1 && form.data.title.trim() === '');

    const submit = () => {
        form.post(`/hr/cases/${caseId}/events`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
            onError: () => toast.error('Please check the highlighted fields.'),
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Add timeline event"
            description={`Record an event on case ${caseNumber}.`}
            railIcon={Calendar}
            railTitle="Add event"
            railSub={caseNumber}
            steps={EVENT_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Event added"
                        blurb={<>“{form.data.title}” is now on the {caseNumber} timeline.</>}
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={
                                form.processing ||
                                form.data.event_type === '' ||
                                form.data.title.trim() === ''
                            }
                        >
                            {form.processing ? 'Adding…' : 'Add event'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={stepInvalid}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Calendar}
                        title="What happened?"
                        blurb={`Record an event for ${subjectName ?? 'the case subject'} on ${caseNumber}.`}
                    />
                    <Field label="Event type" required error={form.errors.event_type}>
                        <TilePicker
                            value={form.data.event_type}
                            onChange={(v) => form.setData('event_type', v)}
                            cols={3}
                            options={eventTypes.map((t) => ({
                                key: t.value,
                                label: t.label,
                                icon: EVENT_TYPE_ICONS[t.value] ?? Folder,
                            }))}
                        />
                    </Field>
                    <div className="mt-4 sm:max-w-xs">
                        <Field label="Date & time" required error={form.errors.occurred_at}>
                            <Input
                                type="datetime-local"
                                value={form.data.occurred_at}
                                onChange={(e) => form.setData('occurred_at', e.target.value)}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Details & visibility"
                        blurb="Describe the event and control who can see it."
                    />
                    <Field label="Title" required error={form.errors.title}>
                        <Input
                            value={form.data.title}
                            onChange={(e) => form.setData('title', e.target.value)}
                            placeholder="Brief title for this event"
                        />
                    </Field>
                    <div className="mt-3.5">
                        <Field label="Description" error={form.errors.description}>
                            <Textarea
                                rows={5}
                                value={form.data.description}
                                onChange={(e) => form.setData('description', e.target.value)}
                                placeholder="Detailed description of what occurred…"
                            />
                        </Field>
                    </div>
                    <div className="mt-4">
                        <Field
                            label="Visibility"
                            hint={VISIBILITY_BLURB[form.data.visibility]}
                            error={form.errors.visibility}
                        >
                            <Segmented
                                value={form.data.visibility}
                                onChange={(v) => form.setData('visibility', v)}
                                options={[...VISIBILITY_OPTIONS]}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm & add"
                        blurb="Check the event, then add it to the timeline."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Calendar} title="Event" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Type" value={optionLabel(eventTypes, form.data.event_type)} />
                            <ReviewRow label="When" value={fdatetime(form.data.occurred_at)} />
                        </ReviewCard>
                        <ReviewCard icon={FileText} title="Details" onEdit={() => wizard.goTo(1)}>
                            <ReviewRow label="Title" value={form.data.title} />
                            <ReviewRow label="Visibility" value={form.data.visibility} />
                            <ReviewRow label="Description" value={form.data.description || undefined} />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Add disciplinary action                                           */
/* ================================================================== */

const DISCIPLINARY_STEPS: readonly WizardStep[] = [
    { key: 'action', label: 'Action', blurb: 'Employee & action type', icon: Gavel },
    { key: 'allegation', label: 'Allegation', blurb: 'Summary & investigation', icon: FileText },
    { key: 'meeting', label: 'Meeting', blurb: 'Schedule & support person', icon: Users },
    { key: 'review', label: 'Review', blurb: 'Confirm & create', icon: CheckCircle2 },
];

export function DisciplinaryCreateWizard({
    caseId,
    caseNumber,
    subjectId,
    subjectName,
    staff,
    actionTypes,
    onClose,
}: {
    caseId: number;
    caseNumber: string;
    subjectId: number | null;
    subjectName: string | null;
    staff: CaseStaffOption[];
    actionTypes: CaseOption[];
    onClose: () => void;
}) {
    const wizard = useWizard(DISCIPLINARY_STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        employee_user_id: subjectId ? String(subjectId) : '',
        action_type: '',
        allegation_summary: '',
        investigation_notes: '',
        investigator_user_id: '',
        meeting_scheduled_at: '',
        meeting_location: '',
        support_person_advised: false as boolean,
        response_deadline: '',
    });

    const employee = staff.find((s) => String(s.id) === form.data.employee_user_id) ?? null;
    const investigator = staff.find((s) => String(s.id) === form.data.investigator_user_id) ?? null;

    const stepInvalid =
        (wizard.index === 0 && (!employee || form.data.action_type === '')) ||
        (wizard.index === 1 && form.data.allegation_summary.trim() === '');

    const submit = () => {
        form.post(`/hr/cases/${caseId}/disciplinary`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
            onError: () => toast.error('Please check the highlighted fields.'),
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Add disciplinary action"
            description={`Start a disciplinary process on case ${caseNumber}.`}
            railIcon={Gavel}
            railTitle="Disciplinary action"
            railSub={caseNumber}
            steps={DISCIPLINARY_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Disciplinary action created"
                        blurb={
                            <>
                                The action for {employee?.name ?? 'the employee'} starts at the
                                “allegation raised” stage. Advance it from the case page as the
                                process progresses.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={
                                form.processing ||
                                !employee ||
                                form.data.action_type === '' ||
                                form.data.allegation_summary.trim() === ''
                            }
                        >
                            {form.processing ? 'Creating…' : 'Create action'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next} disabled={stepInvalid}>
                            Continue
                        </Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Gavel}
                        title="Who and what?"
                        blurb={`Pick the employee and the type of disciplinary action for ${caseNumber}${subjectName ? ` (subject: ${subjectName})` : ''}.`}
                    />
                    <Field label="Employee" required error={form.errors.employee_user_id}>
                        <SelectInput
                            value={form.data.employee_user_id}
                            onChange={(v) => form.setData('employee_user_id', v)}
                            placeholder="Select employee"
                            options={staff.map((s) => ({ value: String(s.id), label: s.name }))}
                        />
                    </Field>
                    <div className="mt-4">
                        <Field label="Action type" required error={form.errors.action_type}>
                            <TilePicker
                                value={form.data.action_type}
                                onChange={(v) => form.setData('action_type', v)}
                                cols={3}
                                options={actionTypes.map((t) => ({
                                    key: t.value,
                                    label: t.label,
                                    icon: ShieldAlert,
                                }))}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Allegation & investigation"
                        blurb="Summarise the allegations and any investigation so far."
                    />
                    <Field label="Allegation summary" required error={form.errors.allegation_summary}>
                        <Textarea
                            rows={5}
                            value={form.data.allegation_summary}
                            onChange={(e) => form.setData('allegation_summary', e.target.value)}
                            placeholder="Detailed summary of the allegations or concerns…"
                        />
                    </Field>
                    <div className="mt-3.5">
                        <Field label="Investigation notes" error={form.errors.investigation_notes}>
                            <Textarea
                                rows={4}
                                value={form.data.investigation_notes}
                                onChange={(e) => form.setData('investigation_notes', e.target.value)}
                                placeholder="Notes from any investigation conducted…"
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5 grid gap-3.5 sm:grid-cols-2">
                        <Field label="Investigator" hint="optional" error={form.errors.investigator_user_id}>
                            <SelectInput
                                value={form.data.investigator_user_id || NONE}
                                onChange={(v) => form.setData('investigator_user_id', v === NONE ? '' : v)}
                                placeholder="Not assigned"
                                options={[
                                    { value: NONE, label: 'Not assigned' },
                                    ...staff.map((s) => ({ value: String(s.id), label: s.name })),
                                ]}
                            />
                        </Field>
                        <Field label="Response deadline" hint="optional" error={form.errors.response_deadline}>
                            <Input
                                type="date"
                                value={form.data.response_deadline}
                                onChange={(e) => form.setData('response_deadline', e.target.value)}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title="Meeting details"
                        blurb="Schedule the disciplinary meeting and record the support-person offer."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Meeting scheduled" hint="optional" error={form.errors.meeting_scheduled_at}>
                            <Input
                                type="datetime-local"
                                value={form.data.meeting_scheduled_at}
                                onChange={(e) => form.setData('meeting_scheduled_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Meeting location" hint="optional" error={form.errors.meeting_location}>
                            <Input
                                value={form.data.meeting_location}
                                onChange={(e) => form.setData('meeting_location', e.target.value)}
                                placeholder="e.g. Conference Room A"
                            />
                        </Field>
                    </div>
                    <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-4">
                        <Checkbox
                            checked={form.data.support_person_advised}
                            onCheckedChange={(checked) =>
                                form.setData('support_person_advised', Boolean(checked))
                            }
                            className="mt-0.5"
                        />
                        <span>
                            <span className="block text-[13px] font-semibold">Support person offered</span>
                            <span className="block text-[12.5px] text-muted-foreground">
                                The employee has been advised of their right to bring a support
                                person.
                            </span>
                        </span>
                    </label>
                    <div className="mt-4">
                        <InfoCard icon={AlertTriangle} tone="warn">
                            Before proceeding, follow your disciplinary procedure and the
                            principles of natural justice: clear communication of the allegations,
                            a genuine opportunity to respond, the right to bring a support person,
                            and time to prepare a response.
                        </InfoCard>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm & create"
                        blurb="Check the details, then create the disciplinary action."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Gavel} title="Action" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Employee" value={employee?.name} />
                            <ReviewRow label="Action type" value={optionLabel(actionTypes, form.data.action_type)} />
                        </ReviewCard>
                        <ReviewCard icon={FileText} title="Allegation" onEdit={() => wizard.goTo(1)}>
                            <ReviewRow label="Investigator" value={investigator?.name ?? 'Not assigned'} />
                            <ReviewRow
                                label="Response deadline"
                                value={form.data.response_deadline ? fdate(form.data.response_deadline) : undefined}
                            />
                            <ReviewRow label="Summary" value={form.data.allegation_summary} />
                        </ReviewCard>
                        <ReviewCard icon={Users} title="Meeting" onEdit={() => wizard.goTo(2)} span>
                            <ReviewRow
                                label="Scheduled"
                                value={form.data.meeting_scheduled_at ? fdatetime(form.data.meeting_scheduled_at) : undefined}
                            />
                            <ReviewRow label="Location" value={form.data.meeting_location || undefined} />
                            <ReviewRow
                                label="Support person"
                                value={form.data.support_person_advised ? 'Offered' : 'Not yet offered'}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}

/* ================================================================== */
/*  Edit disciplinary action                                          */
/* ================================================================== */

const EDIT_DISCIPLINARY_STEPS: readonly WizardStep[] = [
    { key: 'action', label: 'Action details', blurb: 'Employee & allegation', icon: Gavel },
    { key: 'meeting', label: 'Meeting & response', blurb: 'Dates, notes, response', icon: Users },
    { key: 'goodfaith', label: 'Good faith', blurb: 'NZ natural-justice checks', icon: Scale },
    { key: 'outcome', label: 'Outcome & appeal', blurb: 'Decision & any appeal', icon: FileText },
    { key: 'review', label: 'Review', blurb: 'Confirm & save', icon: CheckCircle2 },
];

export function DisciplinaryEditWizard({
    action,
    caseNumber,
    staff,
    actionTypes,
    stageOptions,
    goodFaithRequiredChecks,
    onClose,
}: {
    action: DisciplinaryActionForm;
    caseNumber: string;
    staff: CaseStaffOption[];
    actionTypes: CaseOption[];
    stageOptions: CaseOption[];
    goodFaithRequiredChecks: GoodFaithCheckOption[];
    onClose: () => void;
}) {
    const wizard = useWizard(EDIT_DISCIPLINARY_STEPS.length);
    const [done, setDone] = useState(false);

    const form = useForm({
        employee_user_id: action.employee_user_id,
        action_type: action.action_type ?? '',
        allegation_summary: action.allegation_summary ?? '',
        investigation_notes: action.investigation_notes ?? '',
        investigator_user_id: action.investigator_user_id ?? '',
        notice_issued_at: action.notice_issued_at ?? '',
        notice_document_path: action.notice_document_path ?? '',
        meeting_scheduled_at: action.meeting_scheduled_at ?? '',
        meeting_location: action.meeting_location ?? '',
        support_person_advised: Boolean(action.support_person_advised),
        meeting_held_at: action.meeting_held_at ?? '',
        meeting_notes: action.meeting_notes ?? '',
        meeting_attendees_text: (action.meeting_attendees ?? []).join('\n'),
        employee_response: action.employee_response ?? '',
        response_deadline: action.response_deadline ?? '',
        outcome: action.outcome ?? '',
        outcome_rationale: action.outcome_rationale ?? '',
        outcome_document_path: action.outcome_document_path ?? '',
        good_faith_checklist: action.good_faith_checklist ?? {},
        appeal_received: Boolean(action.appeal_received),
        appeal_notes: action.appeal_notes ?? '',
        appeal_outcome: action.appeal_outcome ?? '',
    });

    const errors = form.errors as Record<string, string>;

    const employee = staff.find((s) => String(s.id) === form.data.employee_user_id) ?? null;
    const investigator = staff.find((s) => String(s.id) === form.data.investigator_user_id) ?? null;

    const completedGoodFaithCount = goodFaithRequiredChecks.filter(
        (option) => form.data.good_faith_checklist?.[option.key],
    ).length;

    const currentStageLabel =
        stageOptions.find((option) => option.value === action.stage)?.label ??
        action.stage.replace(/_/g, ' ');

    const toggleGoodFaith = (key: string, checked: boolean) => {
        form.setData('good_faith_checklist', {
            ...form.data.good_faith_checklist,
            [key]: checked,
        });
    };

    const submit = () => {
        form.transform((values) => ({
            employee_user_id: Number(values.employee_user_id),
            action_type: values.action_type,
            allegation_summary: values.allegation_summary,
            investigation_notes: values.investigation_notes || null,
            investigator_user_id: values.investigator_user_id
                ? Number(values.investigator_user_id)
                : null,
            notice_issued_at: values.notice_issued_at || null,
            notice_document_path: values.notice_document_path || null,
            meeting_scheduled_at: values.meeting_scheduled_at || null,
            meeting_location: values.meeting_location || null,
            support_person_advised: Boolean(values.support_person_advised),
            meeting_held_at: values.meeting_held_at || null,
            meeting_notes: values.meeting_notes || null,
            meeting_attendees: values.meeting_attendees_text
                .split('\n')
                .map((name) => name.trim())
                .filter((name) => name !== ''),
            employee_response: values.employee_response || null,
            response_deadline: values.response_deadline || null,
            outcome: values.outcome || null,
            outcome_rationale: values.outcome_rationale || null,
            outcome_document_path: values.outcome_document_path || null,
            good_faith_checklist: values.good_faith_checklist,
            appeal_received: Boolean(values.appeal_received),
            appeal_notes: values.appeal_notes || null,
            appeal_outcome: values.appeal_outcome || null,
        }));

        form.put(`/hr/cases/disciplinary/${action.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                const err = pageFlashError(page);
                if (err) {
                    toast.error(err);
                    return;
                }
                setDone(true);
            },
            onError: (errs) => {
                if (errs.good_faith) {
                    wizard.goTo(2);
                    toast.error(
                        Array.isArray(errs.good_faith) ? errs.good_faith.join(' ') : errs.good_faith,
                    );
                    return;
                }
                toast.error('Please check the highlighted fields.');
            },
            onFinish: () => form.transform((values) => values),
        });
    };

    return (
        <WizardShell
            open
            onClose={onClose}
            title="Edit disciplinary action"
            description={`Update the disciplinary action on case ${caseNumber}.`}
            railIcon={Gavel}
            railTitle="Edit disciplinary"
            railSub={`${caseNumber} · ${currentStageLabel}`}
            steps={EDIT_DISCIPLINARY_STEPS}
            stepIndex={wizard.index}
            onStepClick={wizard.goTo}
            pct={wizard.progress}
            success={
                done ? (
                    <WizardSuccessPane
                        title="Disciplinary action updated"
                        blurb={
                            <>
                                Changes to the action for {employee?.name ?? 'the employee'} have
                                been saved to {caseNumber}.
                            </>
                        }
                        actions={<Button onClick={onClose}>Done</Button>}
                    />
                ) : undefined
            }
            footerStart={
                wizard.isFirst ? null : (
                    <Button variant="outline" onClick={wizard.back}>
                        Back
                    </Button>
                )
            }
            footerEnd={
                <>
                    <Button variant="ghost" onClick={onClose}>
                        Cancel
                    </Button>
                    {wizard.isLast ? (
                        <Button
                            onClick={submit}
                            disabled={form.processing || form.data.allegation_summary.trim() === ''}
                        >
                            {form.processing ? 'Saving…' : 'Save changes'}
                        </Button>
                    ) : (
                        <Button onClick={wizard.next}>Continue</Button>
                    )}
                </>
            }
        >
            {wizard.index === 0 && (
                <WizardStepPane>
                    <StepHead
                        icon={Gavel}
                        title="Action details"
                        blurb={`Stage: ${currentStageLabel}. Advance the stage from the case page.`}
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Employee" error={errors.employee_user_id}>
                            <SelectInput
                                value={form.data.employee_user_id}
                                onChange={(v) => form.setData('employee_user_id', v)}
                                placeholder="Select employee"
                                options={staff.map((s) => ({ value: String(s.id), label: s.name }))}
                            />
                        </Field>
                        <Field label="Action type" error={errors.action_type}>
                            <SelectInput
                                value={form.data.action_type}
                                onChange={(v) => form.setData('action_type', v)}
                                placeholder="Select action type"
                                options={actionTypes.map((t) => ({ value: t.value, label: t.label }))}
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Allegation summary" required error={errors.allegation_summary}>
                            <Textarea
                                rows={4}
                                value={form.data.allegation_summary}
                                onChange={(e) => form.setData('allegation_summary', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Investigation notes" error={errors.investigation_notes}>
                            <Textarea
                                rows={4}
                                value={form.data.investigation_notes}
                                onChange={(e) => form.setData('investigation_notes', e.target.value)}
                            />
                        </Field>
                    </div>
                </WizardStepPane>
            )}

            {wizard.index === 1 && (
                <WizardStepPane>
                    <StepHead
                        icon={Users}
                        title="Meeting & response"
                        blurb="Track the meeting, attendees, and the employee's response."
                    />
                    <div className="grid gap-3.5 sm:grid-cols-2">
                        <Field label="Investigator" error={errors.investigator_user_id}>
                            <SelectInput
                                value={form.data.investigator_user_id || NONE}
                                onChange={(v) => form.setData('investigator_user_id', v === NONE ? '' : v)}
                                placeholder="Not assigned"
                                options={[
                                    { value: NONE, label: 'Not assigned' },
                                    ...staff.map((s) => ({ value: String(s.id), label: s.name })),
                                ]}
                            />
                        </Field>
                        <Field label="Response deadline" error={errors.response_deadline}>
                            <Input
                                type="date"
                                value={form.data.response_deadline}
                                onChange={(e) => form.setData('response_deadline', e.target.value)}
                            />
                        </Field>
                        <Field label="Meeting scheduled" error={errors.meeting_scheduled_at}>
                            <Input
                                type="datetime-local"
                                value={form.data.meeting_scheduled_at}
                                onChange={(e) => form.setData('meeting_scheduled_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Meeting held" error={errors.meeting_held_at}>
                            <Input
                                type="datetime-local"
                                value={form.data.meeting_held_at}
                                onChange={(e) => form.setData('meeting_held_at', e.target.value)}
                            />
                        </Field>
                        <Field label="Meeting location" error={errors.meeting_location}>
                            <Input
                                value={form.data.meeting_location}
                                onChange={(e) => form.setData('meeting_location', e.target.value)}
                            />
                        </Field>
                        <Field label="Notice issued" error={errors.notice_issued_at}>
                            <Input
                                type="datetime-local"
                                value={form.data.notice_issued_at}
                                onChange={(e) => form.setData('notice_issued_at', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Meeting attendees" hint="one per line" error={errors.meeting_attendees}>
                            <Textarea
                                rows={3}
                                value={form.data.meeting_attendees_text}
                                onChange={(e) => form.setData('meeting_attendees_text', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Meeting notes" error={errors.meeting_notes}>
                            <Textarea
                                rows={4}
                                value={form.data.meeting_notes}
                                onChange={(e) => form.setData('meeting_notes', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Employee response" error={errors.employee_response}>
                            <Textarea
                                rows={4}
                                value={form.data.employee_response}
                                onChange={(e) => form.setData('employee_response', e.target.value)}
                            />
                        </Field>
                    </div>
                    <label className="mt-4 flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-4">
                        <Checkbox
                            checked={Boolean(form.data.support_person_advised)}
                            onCheckedChange={(checked) =>
                                form.setData('support_person_advised', Boolean(checked))
                            }
                            className="mt-0.5"
                        />
                        <span className="text-[13px] font-semibold">
                            Support person offered and advised
                        </span>
                    </label>
                </WizardStepPane>
            )}

            {wizard.index === 2 && (
                <WizardStepPane>
                    <StepHead
                        icon={Scale}
                        title={`Good faith checklist (${completedGoodFaithCount}/${goodFaithRequiredChecks.length})`}
                        blurb="All four checks must be complete before an outcome can be recorded or the process advanced to an outcome stage."
                    />
                    <div className="space-y-3">
                        {goodFaithRequiredChecks.map((option) => (
                            <label
                                key={option.key}
                                className="flex cursor-pointer items-start gap-3 rounded-xl border border-border bg-card p-3.5 text-sm"
                            >
                                <Checkbox
                                    checked={Boolean(form.data.good_faith_checklist?.[option.key])}
                                    onCheckedChange={(checked) =>
                                        toggleGoodFaith(option.key, Boolean(checked))
                                    }
                                    className="mt-0.5"
                                />
                                <span className="text-[13px] font-medium">{option.label}</span>
                            </label>
                        ))}
                    </div>
                    <FieldErr>{errors.good_faith_checklist}</FieldErr>
                    <FieldErr>{errors.good_faith}</FieldErr>
                </WizardStepPane>
            )}

            {wizard.index === 3 && (
                <WizardStepPane>
                    <StepHead
                        icon={FileText}
                        title="Outcome & appeal"
                        blurb="Record the decision, rationale, and any appeal received."
                    />
                    <Field label="Outcome" error={errors.outcome}>
                        <Textarea
                            rows={3}
                            value={form.data.outcome}
                            onChange={(e) => form.setData('outcome', e.target.value)}
                        />
                    </Field>
                    <div className="mt-3.5">
                        <Field label="Outcome rationale" error={errors.outcome_rationale}>
                            <Textarea
                                rows={3}
                                value={form.data.outcome_rationale}
                                onChange={(e) => form.setData('outcome_rationale', e.target.value)}
                            />
                        </Field>
                    </div>
                    <div className="mt-3.5">
                        <Field label="Outcome document path" error={errors.outcome_document_path}>
                            <Input
                                value={form.data.outcome_document_path}
                                onChange={(e) => form.setData('outcome_document_path', e.target.value)}
                            />
                        </Field>
                    </div>
                    {completedGoodFaithCount < goodFaithRequiredChecks.length &&
                    (form.data.outcome.trim() !== '' || form.data.outcome_rationale.trim() !== '') ? (
                        <div className="mt-4">
                            <InfoCard icon={AlertTriangle} tone="warn">
                                The good-faith checklist is incomplete — recording an outcome will
                                be rejected until all four checks are done.
                            </InfoCard>
                        </div>
                    ) : null}
                    <label className="mt-4 flex cursor-pointer items-center gap-3 rounded-xl border border-border bg-card p-3.5">
                        <Checkbox
                            checked={Boolean(form.data.appeal_received)}
                            onCheckedChange={(checked) => form.setData('appeal_received', Boolean(checked))}
                        />
                        <span className="text-[13px] font-semibold">Appeal received</span>
                    </label>
                    {form.data.appeal_received ? (
                        <div className="mt-3.5 grid gap-3.5 sm:grid-cols-2">
                            <Field label="Appeal notes" error={errors.appeal_notes}>
                                <Textarea
                                    rows={3}
                                    value={form.data.appeal_notes}
                                    onChange={(e) => form.setData('appeal_notes', e.target.value)}
                                />
                            </Field>
                            <Field label="Appeal outcome" error={errors.appeal_outcome}>
                                <Textarea
                                    rows={3}
                                    value={form.data.appeal_outcome}
                                    onChange={(e) => form.setData('appeal_outcome', e.target.value)}
                                />
                            </Field>
                        </div>
                    ) : null}
                </WizardStepPane>
            )}

            {wizard.index === 4 && (
                <WizardStepPane>
                    <StepHead
                        icon={ClipboardCheck}
                        title="Confirm & save"
                        blurb="Check the changes, then save the disciplinary action."
                    />
                    <div className="grid gap-3 sm:grid-cols-2">
                        <ReviewCard icon={Gavel} title="Action" onEdit={() => wizard.goTo(0)}>
                            <ReviewRow label="Employee" value={employee?.name} />
                            <ReviewRow label="Action type" value={optionLabel(actionTypes, form.data.action_type)} />
                            <ReviewRow label="Stage" value={currentStageLabel} />
                        </ReviewCard>
                        <ReviewCard icon={Users} title="Meeting & response" onEdit={() => wizard.goTo(1)}>
                            <ReviewRow label="Investigator" value={investigator?.name ?? 'Not assigned'} />
                            <ReviewRow
                                label="Scheduled"
                                value={form.data.meeting_scheduled_at ? fdatetime(form.data.meeting_scheduled_at) : undefined}
                            />
                            <ReviewRow
                                label="Held"
                                value={form.data.meeting_held_at ? fdatetime(form.data.meeting_held_at) : undefined}
                            />
                            <ReviewRow
                                label="Response deadline"
                                value={form.data.response_deadline ? fdate(form.data.response_deadline) : undefined}
                            />
                        </ReviewCard>
                        <ReviewCard icon={Scale} title="Good faith" onEdit={() => wizard.goTo(2)}>
                            <ReviewRow
                                label="Checks complete"
                                value={`${completedGoodFaithCount} of ${goodFaithRequiredChecks.length}`}
                            />
                            <ReviewRow
                                label="Support person"
                                value={form.data.support_person_advised ? 'Offered' : 'Not yet offered'}
                            />
                        </ReviewCard>
                        <ReviewCard icon={FileText} title="Outcome & appeal" onEdit={() => wizard.goTo(3)}>
                            <ReviewRow label="Outcome" value={form.data.outcome || undefined} />
                            <ReviewRow
                                label="Appeal"
                                value={form.data.appeal_received ? 'Received' : 'None'}
                            />
                        </ReviewCard>
                    </div>
                </WizardStepPane>
            )}
        </WizardShell>
    );
}
