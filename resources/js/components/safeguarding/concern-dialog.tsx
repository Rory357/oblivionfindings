import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { Field, InfoCard, SelectInput, StepHead } from '@/components/wizard/primitives';
import { formatDateTime } from '@/lib/datetime';
import { Link, router, useForm } from '@inertiajs/react';
import {
    Activity,
    BadgeCheck,
    CheckCircle2,
    ClipboardCheck,
    Clock,
    ExternalLink,
    FileText,
    Landmark,
    LinkIcon,
    ListTodo,
    Lock,
    Plus,
    RadioTower,
    Search,
    Shield,
    ShieldAlert,
    User as UserIcon,
    UserCog,
    Users,
} from 'lucide-react';
import { useState, type ComponentType, type FormEvent, type ReactNode } from 'react';

/* ------------------------------------------------------------------ */
/*  Types — mirrors SafeguardingConcernController::buildConcernDetail() */
/* ------------------------------------------------------------------ */

type Person = { name: string; href: string | null; type: string } | null;

export type ConcernDetail = {
    id: number;
    reference_number: string;
    restricted: boolean;
    severity: string;
    status: string;
    status_label: string;
    stage_index: number;
    occurred_at: string | null;
    reported_at: string | null;
    // Present only when not restricted:
    concern_type?: string;
    abuse_category?: string | null;
    location?: string | null;
    description?: string | null;
    immediate_actions?: string | null;
    subject_informed?: boolean;
    subject_informed_at?: string | null;
    requires_external_referral?: boolean;
    current_risk_level?: string | null;
    triage?: { at: string | null; by: string | null; substantiation: string | null; decision: string | null; notes: string | null } | null;
    closure?: { at: string | null; by: string | null; summary: string | null; lessons: string | null } | null;
    people?: { subject: Person; reported_by: string | null; assigned_to: string | null; alleged_perpetrator: string | null };
    risk_assessments?: Array<{
        id: number;
        assessed_at: string | null;
        assessor: string | null;
        risk_to_self: string | null;
        risk_to_others: string | null;
        risk_from_others: string | null;
        overall_risk_level: string | null;
        mental_capacity: string | null;
        protective_measures: string | null;
        next_review_date: string | null;
        notes: string | null;
    }>;
    investigations?: Array<{ id: number; type: string; status: string; lead: string | null; started_at: string | null; completed_at: string | null; outcome: string | null; findings: string | null; recommendations: string | null }>;
    external_reports?: Array<{ id: number; authority_type: string; authority_name: string; reported_at: string | null; method: string; summary: string | null; ack_received: boolean; acknowledged_at: string | null; ack_reference: string | null; authority_action: string | null }>;
    action_plans?: Array<{ id: number; description: string; type: string; assigned_to: string | null; due_date: string | null; status: string; completed_at: string | null; overdue: boolean }>;
    alerts?: Array<{ id: number; alert_type: string; summary: string; severity: string; active: boolean }>;
    related_incident_id?: number | null;
    hs_event?: { id: number; reference_number: string; status: string } | null;
    control_room_alert_id?: number | null;
    can?: { update: boolean; investigate: boolean; report_external: boolean };
    assignable_staff?: Array<{ id: number; name: string }>;
};

type ActionKey = 'assign' | 'investigation' | 'report' | 'risk' | 'action';

type SectionKey = 'overview' | 'timeline' | 'risk' | 'investigation' | 'reports' | 'actions' | 'linked';

/* ------------------------------------------------------------------ */
/*  Tokens                                                             */
/* ------------------------------------------------------------------ */

const DOT: Record<string, string> = {
    neutral: 'bg-muted-foreground',
    info: 'bg-status-info',
    success: 'bg-status-success',
    warning: 'bg-status-warning',
    critical: 'bg-status-critical',
    primary: 'bg-primary',
};
const SEV_LABEL: Record<string, string> = { low: 'Low', medium: 'Medium', high: 'High', critical: 'Critical' };
const SEV_TONE: Record<string, string> = { low: 'success', medium: 'warning', high: 'critical', critical: 'critical' };

const STAGES = ['Reported', 'Triaged', 'Investigating', 'Action plan', 'Monitoring', 'Closed'];

function titleCase(s: string | null | undefined): string {
    return (s ?? '').replace(/[_-]/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}

const todayStr = (): string => {
    const dt = new Date();
    return new Date(dt.getTime() - dt.getTimezoneOffset() * 60000).toISOString().slice(0, 10);
};

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

export function SafeguardingConcernDialog({ detail, open, onClose }: { detail: ConcernDetail; open: boolean; onClose: () => void }) {
    const [section, setSection] = useState<SectionKey>('overview');
    const [action, setAction] = useState<ActionKey | null>(null);
    const d = detail;

    if (d.restricted) {
        return (
            <WizardShell
                open={open}
                onClose={onClose}
                title={`Concern ${d.reference_number}`}
                description="Restricted · need-to-know"
                railIcon={Lock}
                railTitle="Restricted"
                railSub={d.reference_number}
                steps={[{ key: 'restricted', label: 'Restricted', blurb: 'need-to-know', icon: Lock }]}
                stepIndex={0}
                onStepClick={() => {}}
            >
                <div className="flex flex-col items-center justify-center py-16 text-center">
                    <Lock className="mb-3 h-10 w-10 text-muted-foreground/50" />
                    <p className="text-base font-semibold text-foreground">Restricted · need-to-know</p>
                    <p className="mt-1 max-w-sm text-sm text-muted-foreground">
                        This is a sensitive safeguarding allegation. It is visible only to the assigned lead, the reporter, and staff cleared to view sensitive concerns.
                    </p>
                </div>
            </WizardShell>
        );
    }

    const subject = d.people?.subject ?? null;
    const subjectName = subject?.name ?? 'Subject withheld';

    const SECTIONS: { key: SectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
        { key: 'overview', label: 'Overview', blurb: 'Stage & people', icon: FileText },
        { key: 'timeline', label: 'Timeline', blurb: 'Audit trail', icon: Clock },
        { key: 'risk', label: 'Risk', blurb: `${d.risk_assessments?.length ?? 0} assessment${(d.risk_assessments?.length ?? 0) === 1 ? '' : 's'}`, icon: Activity },
        { key: 'investigation', label: 'Investigation', blurb: d.hs_event ? d.hs_event.reference_number : `${d.investigations?.length ?? 0} record${(d.investigations?.length ?? 0) === 1 ? '' : 's'}`, icon: Search },
        { key: 'reports', label: 'External reports', blurb: `${d.external_reports?.length ?? 0} logged`, icon: Landmark },
        { key: 'actions', label: 'Action plan', blurb: `${d.action_plans?.length ?? 0} item${(d.action_plans?.length ?? 0) === 1 ? '' : 's'}`, icon: ListTodo },
        { key: 'linked', label: 'Linked records', blurb: 'incident · H&S · alerts', icon: LinkIcon },
    ];
    const stepIndex = SECTIONS.findIndex((s) => s.key === section);

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span className="inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium">
                <span className={`h-1.5 w-1.5 rounded-full ${DOT[SEV_TONE[d.severity] ?? 'neutral']}`} />
                {SEV_LABEL[d.severity] ?? d.severity}
            </span>
            <span className="text-muted-foreground">{d.status_label}</span>
            <span className="hidden items-center gap-1 text-muted-foreground/70 sm:inline-flex">
                <Lock className="h-3 w-3" /> Viewing is logged
            </span>
        </div>
    );

    // Gated Options bar — buttons hide when the viewer lacks permission and
    // disable (with a one-line reason) when the lifecycle forbids the action.
    // Triage + Close are added in Step 5. Suppressed while an action pane owns the body.
    const terminal = d.status === 'closed' || d.status === 'no_action_required';
    const reported = d.status === 'reported';
    const can = d.can ?? { update: false, investigate: false, report_external: false };
    const triageFirst = 'Triage the concern first.';

    const markInformed = () => router.post(`/safeguarding/${d.id}/subject-informed`, {}, { preserveScroll: true });

    const footerEnd = action ? null : (
        <div className="flex flex-wrap items-center justify-end gap-2">
            <Link href={`/safeguarding/${d.id}`} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted">
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
            {can.update && !terminal ? <OptionBtn icon={UserCog} label="Assign" onClick={() => setAction('assign')} /> : null}
            {can.update && !terminal ? <OptionBtn icon={Activity} label="Add risk" onClick={() => setAction('risk')} /> : null}
            {can.investigate && !terminal ? <OptionBtn icon={Search} label="Start investigation" onClick={() => setAction('investigation')} disabled={reported} reason={triageFirst} /> : null}
            {can.report_external && !terminal ? <OptionBtn icon={Landmark} label="Log referral" onClick={() => setAction('report')} disabled={reported} reason={triageFirst} /> : null}
            {can.update && !terminal ? <OptionBtn icon={ListTodo} label="Add action" onClick={() => setAction('action')} disabled={reported} reason={triageFirst} /> : null}
            {can.update && !terminal && !d.subject_informed ? <OptionBtn icon={BadgeCheck} label="Mark informed" onClick={markInformed} /> : null}
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Concern ${d.reference_number}`}
            description={`${titleCase(d.concern_type)} — ${subjectName}`}
            railIcon={d.severity === 'critical' ? ShieldAlert : Shield}
            railTitle={subjectName}
            railSub={`${d.reference_number} · ${titleCase(d.concern_type)}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {action === 'assign' ? (
                <AssignPane d={d} onDone={() => setAction(null)} />
            ) : action === 'investigation' ? (
                <InvestigationPane d={d} onDone={() => setAction(null)} />
            ) : action === 'report' ? (
                <ReportPane d={d} onDone={() => setAction(null)} />
            ) : action === 'risk' ? (
                <RiskPane d={d} onDone={() => setAction(null)} />
            ) : action === 'action' ? (
                <ActionItemPane d={d} onDone={() => setAction(null)} />
            ) : (
                <>
                    {section === 'overview' ? <OverviewSection d={d} subjectName={subjectName} /> : null}
                    {section === 'timeline' ? <TimelineSection d={d} /> : null}
                    {section === 'risk' ? <RiskSection d={d} /> : null}
                    {section === 'investigation' ? <InvestigationSection d={d} /> : null}
                    {section === 'reports' ? <ReportsSection d={d} /> : null}
                    {section === 'actions' ? <ActionsSection d={d} /> : null}
                    {section === 'linked' ? <LinkedSection d={d} subject={subject} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Options bar button + action panes                                  */
/* ------------------------------------------------------------------ */

function OptionBtn({ icon: Icon, label, onClick, disabled, reason }: { icon: ComponentType<{ className?: string }>; label: string; onClick: () => void; disabled?: boolean; reason?: string }) {
    return (
        <Button size="sm" variant="outline" onClick={onClick} disabled={disabled} title={disabled ? reason : undefined}>
            <Icon className="mr-1.5 h-4 w-4" /> {label}
        </Button>
    );
}

/** Shared submit handler: post, keep the pane open if the server flashed an error. */
function onSuccessGuard(onDone: () => void) {
    return (page: { props: Record<string, unknown> }) => {
        const flash = page.props.flash as { error?: string } | undefined;
        if (!flash?.error) onDone();
    };
}

function PaneShell({ children, onCancel, onSubmit, cta, processing }: { children: ReactNode; onCancel: () => void; onSubmit: (e: FormEvent) => void; cta: string; processing: boolean }) {
    return (
        <form onSubmit={onSubmit} className="flex flex-col gap-4">
            {children}
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onCancel}>
                    Cancel
                </Button>
                <Button type="submit" disabled={processing}>
                    {cta}
                </Button>
            </div>
        </form>
    );
}

function AssignPane({ d, onDone }: { d: ConcernDetail; onDone: () => void }) {
    const staff = d.assignable_staff ?? [];
    const form = useForm<{ assigned_to_user_id: string }>({ assigned_to_user_id: '' });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.assigned_to_user_id) {
            form.setError('assigned_to_user_id', 'Choose a lead.');
            return;
        }
        form.post(`/safeguarding/${d.id}/assign`, { preserveScroll: true, onSuccess: onSuccessGuard(onDone) });
    };
    return (
        <>
            <StepHead icon={UserCog} title="Assign a lead" blurb="The lead owns the concern through triage, investigation and closure." />
            <PaneShell onCancel={onDone} onSubmit={submit} cta="Assign lead" processing={form.processing}>
                <Field label="Lead" required error={form.errors.assigned_to_user_id}>
                    <SelectInput value={form.data.assigned_to_user_id} onChange={(v) => form.setData('assigned_to_user_id', v)} placeholder="Select a lead" options={staff.map((s) => ({ value: String(s.id), label: s.name }))} />
                </Field>
            </PaneShell>
        </>
    );
}

function InvestigationPane({ d, onDone }: { d: ConcernDetail; onDone: () => void }) {
    const staff = d.assignable_staff ?? [];
    const form = useForm<{ investigation_type: string; lead_investigator_id: string; started_at: string; terms_of_reference: string; methodology: string }>({
        investigation_type: 'internal',
        lead_investigator_id: '',
        started_at: todayStr(),
        terms_of_reference: '',
        methodology: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.lead_investigator_id) {
            form.setError('lead_investigator_id', 'Choose a lead investigator.');
            return;
        }
        form.post(`/safeguarding/${d.id}/investigations`, { preserveScroll: true, onSuccess: onSuccessGuard(onDone) });
    };
    return (
        <>
            <StepHead icon={Search} title="Start an investigation" blurb="Opening an investigation record moves the concern to Under investigation. Completing it auto-advances the concern." />
            <PaneShell onCancel={onDone} onSubmit={submit} cta="Open investigation" processing={form.processing}>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Type" required>
                        <SelectInput value={form.data.investigation_type} onChange={(v) => form.setData('investigation_type', v)} placeholder="Type" options={[{ value: 'internal', label: 'Internal' }, { value: 'external', label: 'External' }, { value: 'joint', label: 'Joint' }]} />
                    </Field>
                    <Field label="Lead investigator" required error={form.errors.lead_investigator_id}>
                        <SelectInput value={form.data.lead_investigator_id} onChange={(v) => form.setData('lead_investigator_id', v)} placeholder="Select" options={staff.map((s) => ({ value: String(s.id), label: s.name }))} />
                    </Field>
                </div>
                <Field label="Started" required error={form.errors.started_at}>
                    <Input type="date" value={form.data.started_at} onChange={(e) => form.setData('started_at', e.target.value)} />
                </Field>
                <Field label="Terms of reference" hint="Optional">
                    <Textarea rows={2} value={form.data.terms_of_reference} onChange={(e) => form.setData('terms_of_reference', e.target.value)} />
                </Field>
                <Field label="Methodology" hint="Optional">
                    <Input value={form.data.methodology} onChange={(e) => form.setData('methodology', e.target.value)} />
                </Field>
            </PaneShell>
        </>
    );
}

const AUTHORITIES = [
    { value: 'police', label: 'NZ Police' },
    { value: 'oranga_tamariki', label: 'Oranga Tamariki' },
    { value: 'hdc', label: 'Health & Disability Commissioner' },
    { value: 'health_nz', label: 'Te Whatu Ora – Health NZ' },
    { value: 'whaikaha', label: 'Whaikaha' },
    { value: 'privacy_commissioner', label: 'Privacy Commissioner' },
    { value: 'worksafe', label: 'WorkSafe' },
    { value: 'other', label: 'Other' },
];

function ReportPane({ d, onDone }: { d: ConcernDetail; onDone: () => void }) {
    const form = useForm<{ authority_type: string; authority_name: string; report_method: string; reported_at: string; report_summary: string }>({
        authority_type: '',
        authority_name: '',
        report_method: 'phone',
        reported_at: todayStr(),
        report_summary: '',
    });
    const pickAuthority = (v: string) => {
        form.setData('authority_type', v);
        const label = AUTHORITIES.find((a) => a.value === v)?.label ?? '';
        if (!form.data.authority_name || AUTHORITIES.some((a) => a.label === form.data.authority_name)) {
            form.setData('authority_name', label);
        }
    };
    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.post(`/safeguarding/${d.id}/external-reports`, { preserveScroll: true, onSuccess: onSuccessGuard(onDone) });
    };
    return (
        <>
            <StepHead icon={Landmark} title="Log an external referral" blurb="Record a report to an external authority. NZ has no single adult-safeguarding statute — you choose the right authority." />
            <PaneShell onCancel={onDone} onSubmit={submit} cta="Log report" processing={form.processing}>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Authority" required error={form.errors.authority_type}>
                        <SelectInput value={form.data.authority_type} onChange={pickAuthority} placeholder="Select authority" options={AUTHORITIES} />
                    </Field>
                    <Field label="Authority name" required error={form.errors.authority_name}>
                        <Input value={form.data.authority_name} onChange={(e) => form.setData('authority_name', e.target.value)} placeholder="e.g. NZ Police — Central" />
                    </Field>
                </div>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Method">
                        <SelectInput value={form.data.report_method} onChange={(v) => form.setData('report_method', v)} placeholder="Method" options={[{ value: 'phone', label: 'Phone' }, { value: 'email', label: 'Email' }, { value: 'online_form', label: 'Online form' }, { value: 'in_person', label: 'In person' }, { value: 'letter', label: 'Letter' }]} />
                    </Field>
                    <Field label="Reported" required error={form.errors.reported_at}>
                        <Input type="date" value={form.data.reported_at} onChange={(e) => form.setData('reported_at', e.target.value)} />
                    </Field>
                </div>
                <Field label="What was reported" required error={form.errors.report_summary}>
                    <Textarea rows={3} value={form.data.report_summary} onChange={(e) => form.setData('report_summary', e.target.value)} />
                </Field>
            </PaneShell>
        </>
    );
}

const RISK_OPTS = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
    { value: 'critical', label: 'Critical' },
];

function RiskPane({ d, onDone }: { d: ConcernDetail; onDone: () => void }) {
    const form = useForm<{ overall_risk_level: string; risk_to_self: string; risk_to_others: string; risk_from_others: string; mental_capacity: string; protective_measures: string; next_review_date: string; assessment_notes: string }>({
        overall_risk_level: d.current_risk_level ?? '',
        risk_to_self: '',
        risk_to_others: '',
        risk_from_others: '',
        mental_capacity: '',
        protective_measures: '',
        next_review_date: '',
        assessment_notes: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.overall_risk_level) {
            form.setError('overall_risk_level', 'Set the overall risk level.');
            return;
        }
        form.post(`/safeguarding/${d.id}/risk-assessments`, { preserveScroll: true, onSuccess: onSuccessGuard(onDone) });
    };
    return (
        <>
            <StepHead icon={Activity} title="Risk assessment" blurb="Record the current risk picture and when it should next be reviewed." />
            <PaneShell onCancel={onDone} onSubmit={submit} cta="Save assessment" processing={form.processing}>
                <Field label="Overall risk" required error={form.errors.overall_risk_level}>
                    <SelectInput value={form.data.overall_risk_level} onChange={(v) => form.setData('overall_risk_level', v)} placeholder="Overall risk" options={RISK_OPTS} />
                </Field>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="To self">
                        <SelectInput value={form.data.risk_to_self} onChange={(v) => form.setData('risk_to_self', v)} placeholder="—" options={RISK_OPTS} />
                    </Field>
                    <Field label="To others">
                        <SelectInput value={form.data.risk_to_others} onChange={(v) => form.setData('risk_to_others', v)} placeholder="—" options={RISK_OPTS} />
                    </Field>
                    <Field label="From others">
                        <SelectInput value={form.data.risk_from_others} onChange={(v) => form.setData('risk_from_others', v)} placeholder="—" options={RISK_OPTS} />
                    </Field>
                </div>
                <Field label="Protective measures" hint="One per line">
                    <Textarea rows={2} value={form.data.protective_measures} onChange={(e) => form.setData('protective_measures', e.target.value)} />
                </Field>
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Mental capacity" hint="Optional">
                        <SelectInput value={form.data.mental_capacity} onChange={(v) => form.setData('mental_capacity', v)} placeholder="—" options={[{ value: 'has_capacity', label: 'Has capacity' }, { value: 'lacks_capacity', label: 'Lacks capacity' }, { value: 'fluctuating', label: 'Fluctuating' }, { value: 'not_assessed', label: 'Not assessed' }]} />
                    </Field>
                    <Field label="Next review">
                        <Input type="date" value={form.data.next_review_date} onChange={(e) => form.setData('next_review_date', e.target.value)} />
                    </Field>
                </div>
                <Field label="Notes" hint="Optional">
                    <Textarea rows={2} value={form.data.assessment_notes} onChange={(e) => form.setData('assessment_notes', e.target.value)} />
                </Field>
            </PaneShell>
        </>
    );
}

function ActionItemPane({ d, onDone }: { d: ConcernDetail; onDone: () => void }) {
    const staff = d.assignable_staff ?? [];
    const form = useForm<{ action_description: string; action_type: string; assigned_to_user_id: string; due_date: string }>({
        action_description: '',
        action_type: 'protective_measure',
        assigned_to_user_id: '',
        due_date: '',
    });
    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.action_description.trim()) {
            form.setError('action_description', 'Describe the action.');
            return;
        }
        if (!form.data.assigned_to_user_id) {
            form.setError('assigned_to_user_id', 'Assign an owner.');
            return;
        }
        if (!form.data.due_date) {
            form.setError('due_date', 'Set a due date.');
            return;
        }
        form.post(`/safeguarding/${d.id}/action-plans`, { preserveScroll: true, onSuccess: onSuccessGuard(onDone) });
    };
    return (
        <>
            <StepHead icon={ListTodo} title="Add a protective action" blurb="Track a protective measure or corrective action with an owner and due date." />
            <PaneShell onCancel={onDone} onSubmit={submit} cta="Add action" processing={form.processing}>
                <Field label="Action" required error={form.errors.action_description}>
                    <Textarea rows={2} value={form.data.action_description} onChange={(e) => form.setData('action_description', e.target.value)} placeholder="e.g. Increase observations and review the support plan" />
                </Field>
                <div className="grid gap-3 sm:grid-cols-3">
                    <Field label="Type">
                        <SelectInput value={form.data.action_type} onChange={(v) => form.setData('action_type', v)} placeholder="Type" options={[{ value: 'protective_measure', label: 'Protective measure' }, { value: 'support_service', label: 'Support service' }, { value: 'supervision', label: 'Supervision' }, { value: 'monitoring', label: 'Monitoring' }, { value: 'training', label: 'Training' }, { value: 'policy_change', label: 'Policy change' }, { value: 'referral', label: 'Referral' }, { value: 'other', label: 'Other' }]} />
                    </Field>
                    <Field label="Owner" required error={form.errors.assigned_to_user_id}>
                        <SelectInput value={form.data.assigned_to_user_id} onChange={(v) => form.setData('assigned_to_user_id', v)} placeholder="Owner" options={staff.map((s) => ({ value: String(s.id), label: s.name }))} />
                    </Field>
                    <Field label="Due" required error={form.errors.due_date}>
                        <Input type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                    </Field>
                </div>
            </PaneShell>
        </>
    );
}

/* ------------------------------------------------------------------ */
/*  Lifecycle stage tracker                                            */
/* ------------------------------------------------------------------ */

function LifecycleTracker({ d }: { d: ConcernDetail }) {
    const idx = d.stage_index;
    const branch = d.status === 'referred_external' ? 'Referred external' : d.status === 'no_action_required' ? 'No further action' : null;
    return (
        <div className="sm:col-span-2">
            <p className="mb-3 text-[11px] font-semibold tracking-wide text-muted-foreground uppercase">Lifecycle stage</p>
            <div className="flex flex-wrap items-center gap-1.5">
                {STAGES.map((label, i) => {
                    const done = i < idx;
                    const active = i === idx;
                    return (
                        <div key={label} className="flex items-center gap-1.5">
                            <span
                                className={
                                    active
                                        ? 'inline-flex items-center gap-1.5 rounded-full bg-primary px-2.5 py-1 text-[11px] font-semibold text-primary-foreground'
                                        : done
                                          ? 'inline-flex items-center gap-1.5 rounded-full bg-status-success-bg px-2.5 py-1 text-[11px] font-medium text-status-success'
                                          : 'inline-flex items-center gap-1.5 rounded-full bg-muted px-2.5 py-1 text-[11px] font-medium text-muted-foreground'
                                }
                            >
                                {done ? <CheckCircle2 className="h-3 w-3" /> : null}
                                {label}
                            </span>
                            {i < STAGES.length - 1 ? <span className="h-px w-3 bg-border" /> : null}
                        </div>
                    );
                })}
                {branch ? (
                    <span className="ml-1 inline-flex items-center gap-1.5 rounded-full bg-status-critical-bg px-2.5 py-1 text-[11px] font-semibold text-status-critical">
                        <Landmark className="h-3 w-3" /> {branch}
                    </span>
                ) : null}
            </div>
        </div>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ d, subjectName }: { d: ConcernDetail; subjectName: string }) {
    const informedLabel = d.subject_informed ? `Yes${d.subject_informed_at ? ` · ${formatDateTime(d.subject_informed_at)}` : ''}` : 'Not yet';
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            <LifecycleTracker d={d} />

            {d.status === 'reported' ? (
                <InfoCard icon={ClipboardCheck} tone="warn">
                    <span className="font-semibold">Awaiting triage.</span> Triage decides the path — investigate, refer externally, or no further action.
                </InfoCard>
            ) : null}

            {d.alerts?.some((a) => a.active) ? (
                <InfoCard icon={RadioTower} tone="crit">
                    <span className="font-semibold">Active protective alert</span> on the subject — see Linked records.
                </InfoCard>
            ) : null}

            <ReviewCard icon={FileText} title="What was raised" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.description || '—'}</p>
            </ReviewCard>

            <ReviewCard icon={Users} title="People">
                <ReviewRow label="Subject" value={subjectName} />
                <ReviewRow label="Reported by" value={d.people?.reported_by} />
                <ReviewRow label="Alleged person" value={d.people?.alleged_perpetrator} />
                <ReviewRow label="Lead" value={d.people?.assigned_to ?? 'Unassigned'} />
            </ReviewCard>

            <ReviewCard icon={Search} title="Classification">
                <ReviewRow label="Type" value={titleCase(d.concern_type)} />
                <ReviewRow label="Category" value={d.abuse_category ? titleCase(d.abuse_category) : undefined} />
                <ReviewRow label="Current risk" value={d.current_risk_level ? titleCase(d.current_risk_level) : undefined} />
                <ReviewRow label="Occurred" value={d.occurred_at ? formatDateTime(d.occurred_at) : undefined} />
                <ReviewRow label="Subject informed" value={informedLabel} />
                <ReviewRow label="External referral" value={d.requires_external_referral ? 'Indicated' : 'Not indicated'} />
            </ReviewCard>

            <ReviewCard icon={CheckCircle2} title="Immediate response" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.immediate_actions || '—'}</p>
            </ReviewCard>

            {d.closure ? (
                <ReviewCard icon={CheckCircle2} title="Closure" span>
                    <ReviewRow label="Closed by" value={d.closure.by} />
                    <ReviewRow label="Summary" value={d.closure.summary} />
                    <ReviewRow label="Lessons learned" value={d.closure.lessons} />
                </ReviewCard>
            ) : null}
        </div>
    );
}

function TimelineSection({ d }: { d: ConcernDetail }) {
    type TLEvent = { at: string; label: string; tone: string; icon: ComponentType<{ className?: string }> };
    const events: TLEvent[] = [];
    if (d.reported_at) events.push({ at: d.reported_at, label: 'Concern raised — created as Awaiting triage', tone: 'primary', icon: ShieldAlert });
    if (d.triage?.at) events.push({ at: d.triage.at, label: `Triaged${d.triage.decision ? ` · ${titleCase(d.triage.decision)}` : ''}${d.triage.by ? ` by ${d.triage.by}` : ''}`, tone: 'info', icon: ClipboardCheck });
    (d.investigations ?? []).forEach((i) => {
        if (i.started_at) events.push({ at: i.started_at, label: 'Investigation opened — required to enter Investigating', tone: 'primary', icon: Search });
        if (i.completed_at) events.push({ at: i.completed_at, label: `Investigation completed${i.outcome ? ` · ${titleCase(i.outcome)}` : ''}`, tone: 'success', icon: CheckCircle2 });
    });
    (d.external_reports ?? []).forEach((r) => {
        if (r.reported_at) events.push({ at: r.reported_at, label: `Reported to ${r.authority_name}`, tone: 'warning', icon: Landmark });
        if (r.acknowledged_at) events.push({ at: r.acknowledged_at, label: `${r.authority_name} acknowledged`, tone: 'success', icon: CheckCircle2 });
    });
    if (d.closure?.at) events.push({ at: d.closure.at, label: `Closed${d.closure.by ? ` by ${d.closure.by}` : ''}`, tone: 'success', icon: CheckCircle2 });
    events.sort((a, b) => new Date(a.at).getTime() - new Date(b.at).getTime());

    if (!events.length) return <p className="text-sm text-muted-foreground">No timeline events yet.</p>;

    return (
        <ol className="relative ml-2 border-l border-border">
            {events.map((e, i) => {
                const Icon = e.icon;
                return (
                    <li key={i} className="mb-5 ml-5">
                        <span className={`absolute -left-[7px] flex h-3.5 w-3.5 items-center justify-center rounded-full ${DOT[e.tone] ?? DOT.neutral}`} />
                        <div className="flex items-center gap-2">
                            <Icon className="h-4 w-4 text-muted-foreground" />
                            <span className="text-sm font-medium text-foreground">{e.label}</span>
                        </div>
                        <p className="mt-0.5 text-xs text-muted-foreground">{formatDateTime(e.at)}</p>
                    </li>
                );
            })}
        </ol>
    );
}

function RiskSection({ d }: { d: ConcernDetail }) {
    if (!d.risk_assessments?.length) {
        return <EmptyState icon={Activity} text="No risk assessment recorded yet." />;
    }
    return (
        <div className="flex flex-col gap-3">
            {d.risk_assessments.map((r) => (
                <ReviewCard key={r.id} icon={Activity} title={`Risk assessment${r.assessed_at ? ` · ${formatDateTime(r.assessed_at)}` : ''}`} span>
                    <ReviewRow label="Overall risk" value={r.overall_risk_level ? titleCase(r.overall_risk_level) : undefined} />
                    <ReviewRow label="Risk to self" value={titleCase(r.risk_to_self)} />
                    <ReviewRow label="Risk to others" value={titleCase(r.risk_to_others)} />
                    <ReviewRow label="Risk from others" value={titleCase(r.risk_from_others)} />
                    <ReviewRow label="Mental capacity" value={r.mental_capacity ? titleCase(r.mental_capacity) : undefined} />
                    <ReviewRow label="Protective measures" value={r.protective_measures} />
                    <ReviewRow label="Assessor" value={r.assessor} />
                    <ReviewRow label="Next review" value={r.next_review_date ? formatDateTime(r.next_review_date) : undefined} />
                </ReviewCard>
            ))}
        </div>
    );
}

function InvestigationSection({ d }: { d: ConcernDetail }) {
    return (
        <div className="flex flex-col gap-4">
            {d.hs_event ? (
                <div className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-3">
                    <div>
                        <p className="text-sm font-semibold text-foreground">{d.hs_event.reference_number}</p>
                        <p className="text-xs text-muted-foreground">H&amp;S event · {titleCase(d.hs_event.status)}</p>
                    </div>
                    <Link href={`/health-safety/events/${d.hs_event.id}`} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted">
                        <ExternalLink className="h-3.5 w-3.5" /> Open in Health &amp; Safety
                    </Link>
                </div>
            ) : null}

            {d.investigations?.length ? (
                d.investigations.map((i) => (
                    <ReviewCard key={i.id} icon={Search} title={`Investigation · ${titleCase(i.status)}`} span>
                        <ReviewRow label="Type" value={titleCase(i.type)} />
                        <ReviewRow label="Lead" value={i.lead} />
                        <ReviewRow label="Started" value={i.started_at ? formatDateTime(i.started_at) : undefined} />
                        <ReviewRow label="Completed" value={i.completed_at ? formatDateTime(i.completed_at) : undefined} />
                        <ReviewRow label="Outcome" value={i.outcome ? titleCase(i.outcome) : undefined} />
                        {i.findings ? <ReviewRow label="Findings" value={i.findings} /> : null}
                        {i.recommendations ? <ReviewRow label="Recommendations" value={i.recommendations} /> : null}
                    </ReviewCard>
                ))
            ) : (
                <EmptyState icon={Search} text="No investigation opened. Completing an investigation auto-advances the concern." />
            )}
        </div>
    );
}

function ReportsSection({ d }: { d: ConcernDetail }) {
    if (!d.external_reports?.length) {
        return (
            <EmptyState
                icon={Landmark}
                text={d.requires_external_referral ? 'Referral indicated at triage — no report logged yet.' : 'No external reports logged.'}
                tone={d.requires_external_referral ? 'warn' : 'neutral'}
            />
        );
    }
    return (
        <div className="flex flex-col gap-3">
            {d.external_reports.map((r) => (
                <ReviewCard key={r.id} icon={Landmark} title={r.authority_name} span>
                    <ReviewRow label="Method" value={titleCase(r.method)} />
                    <ReviewRow label="Reported" value={r.reported_at ? formatDateTime(r.reported_at) : undefined} />
                    <ReviewRow label="Summary" value={r.summary} />
                    <ReviewRow label="Acknowledged" value={r.ack_received ? `Yes${r.acknowledged_at ? ` · ${formatDateTime(r.acknowledged_at)}` : ''}${r.ack_reference ? ` · ${r.ack_reference}` : ''}` : 'Awaiting'} />
                    <ReviewRow label="Authority outcome" value={r.authority_action ? titleCase(r.authority_action) : undefined} />
                </ReviewCard>
            ))}
        </div>
    );
}

function ActionsSection({ d }: { d: ConcernDetail }) {
    if (!d.action_plans?.length) {
        return <EmptyState icon={ListTodo} text="No action-plan items yet." />;
    }
    return (
        <div className="flex flex-col gap-2">
            {d.action_plans.map((a) => (
                <div key={a.id} className="flex items-start gap-3 rounded-lg border border-border p-3">
                    <ListTodo className={`mt-0.5 h-4 w-4 shrink-0 ${a.completed_at ? 'text-status-success' : a.overdue ? 'text-status-critical' : 'text-status-warning'}`} />
                    <div className="min-w-0 flex-1">
                        <p className="text-sm text-foreground">{a.description}</p>
                        <p className="text-xs text-muted-foreground">
                            {a.assigned_to ?? 'Unassigned'}
                            {a.due_date ? ` · due ${formatDateTime(a.due_date)}` : ''}
                            {a.completed_at ? ` · completed ${formatDateTime(a.completed_at)}` : a.overdue ? ' · overdue' : ''}
                        </p>
                    </div>
                    <span className="text-xs text-muted-foreground">{titleCase(a.status)}</span>
                </div>
            ))}
        </div>
    );
}

function LinkedSection({ d, subject }: { d: ConcernDetail; subject: Person }) {
    const hasAny = d.related_incident_id || d.hs_event || subject?.href || d.alerts?.length || d.control_room_alert_id;
    if (!hasAny) return <p className="text-sm text-muted-foreground">No linked records.</p>;
    return (
        <div className="flex flex-col gap-2">
            {d.control_room_alert_id ? <LinkedRow icon={RadioTower} title="Control Room alert" sub="Active alert" href={`/control-room/alerts/${d.control_room_alert_id}`} /> : null}
            {d.related_incident_id ? <LinkedRow icon={ShieldAlert} title="Originating incident" sub={`INC-${d.related_incident_id}`} href={`/incidents/${d.related_incident_id}`} /> : null}
            {d.hs_event ? <LinkedRow icon={Shield} title="Health & Safety event" sub={`${d.hs_event.reference_number} · ${titleCase(d.hs_event.status)}`} href={`/health-safety/events/${d.hs_event.id}`} /> : null}
            {subject?.href ? <LinkedRow icon={UserIcon} title="Subject record" sub={subject.name} href={subject.href} /> : null}
            {d.alerts?.length ? (
                <div className="rounded-lg border border-border p-3">
                    <p className="mb-1 text-sm font-medium text-foreground">Protective alerts</p>
                    {d.alerts.map((a) => (
                        <p key={a.id} className="text-xs text-muted-foreground">
                            {titleCase(a.alert_type)} · {a.summary}
                            {a.active ? '' : ' (inactive)'}
                        </p>
                    ))}
                </div>
            ) : null}
        </div>
    );
}

function LinkedRow({ icon: Icon, title, sub, href }: { icon: ComponentType<{ className?: string }>; title: string; sub: string; href: string }) {
    return (
        <Link href={href} className="flex items-center gap-3 rounded-lg border border-border p-3 transition-colors hover:bg-muted/50">
            <span className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-muted">
                <Icon className="h-4 w-4 text-muted-foreground" />
            </span>
            <div className="min-w-0 flex-1">
                <p className="text-sm font-medium text-foreground">{title}</p>
                <p className="truncate text-xs text-muted-foreground">{sub}</p>
            </div>
            <ExternalLink className="h-4 w-4 text-muted-foreground" />
        </Link>
    );
}

function EmptyState({ icon: Icon, text, tone = 'neutral' }: { icon: ComponentType<{ className?: string }>; text: string; tone?: 'neutral' | 'warn' }) {
    return (
        <div className={`rounded-xl border border-dashed py-12 text-center ${tone === 'warn' ? 'border-status-warning/40 bg-status-warning-bg/30' : 'border-border'}`}>
            <Icon className={`mx-auto mb-2 h-8 w-8 ${tone === 'warn' ? 'text-status-warning' : 'text-muted-foreground/40'}`} />
            <p className={`text-sm ${tone === 'warn' ? 'text-status-warning' : 'text-muted-foreground'}`}>{text}</p>
        </div>
    );
}
