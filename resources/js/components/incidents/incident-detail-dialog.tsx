import { Button } from '@/components/ui/button';
import { AttachmentUploader } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { Field, InfoCard, SelectInput, StepHead } from '@/components/wizard/primitives';
import { formatDateTime } from '@/lib/datetime';
import { Link, router, useForm } from '@inertiajs/react';
import {
    AlertTriangle,
    CheckCircle2,
    Clock,
    Download,
    ExternalLink,
    FileText,
    Hand,
    HeartPulse,
    LinkIcon,
    ListTodo,
    Paperclip,
    Pencil,
    Pill,
    Plus,
    RadioTower,
    Truck,
    RotateCcw,
    Search,
    Send,
    ShieldAlert,
    Trash2,
    User,
    Users,
} from 'lucide-react';
import { useState, type ComponentType, type FormEvent } from 'react';

/* ------------------------------------------------------------------ */
/*  Types — mirrors IncidentController::buildIncidentDetail()           */
/* ------------------------------------------------------------------ */

type JsonCause = { description?: string; category?: string };
type JsonRec = { description?: string; priority?: string; target_area?: string };

export type IncidentDetail = {
    id: number;
    type: string;
    source: string;
    interactive: boolean;
    severity: string;
    status: string;
    occurred_at: string | null;
    description: string | null;
    immediate_action_taken: string | null;
    witnesses: string | null;
    is_notifiable: boolean;
    worksafe_notification_status: string | null;
    worksafe_notified_at: string | null;
    worksafe_reference: string | null;
    potential_severity: string | null;
    potential_consequence: string | null;
    investigation_status: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    review_notes: string | null;
    closed_at: string | null;
    closed_outcome: string | null;
    closed_notes: string | null;
    reopened_at: string | null;
    reopened_reason: string | null;
    control_room_alert_id: number | null;
    client: { id: number; first_name: string; last_name: string; site: string | null } | null;
    reporter: { name: string; email: string } | null;
    investigator: string | null;
    attachments: Array<{
        id: number;
        name: string;
        mime: string | null;
        size: number | null;
        portal_visible: boolean;
        notes: string | null;
        uploaded_by: string | null;
        created_at: string | null;
        download_url: string;
    }>;
    followups: Array<{
        id: number;
        notes: string | null;
        assigned_to: string | null;
        due_at: string | null;
        completed_at: string | null;
        created_by: string | null;
        overdue: boolean;
    }>;
    control_room_alert: { id: number; status: string; severity: string; alert_type: string; triggered_at: string | null; resolved_at: string | null } | null;
    hs_event: {
        id: number;
        reference_number: string;
        status: string;
        investigation_required: boolean;
        investigation: {
            reference_number: string;
            status: string;
            methodology: string | null;
            root_causes: JsonCause[] | null;
            contributing_factors: JsonCause[] | null;
            recommendations: JsonRec[] | null;
            lessons_learned: string | null;
        } | null;
        corrective_actions: Array<{ id: number; reference_number: string; title: string; status: string; priority: string; assigned_to: string | null; due_date: string | null }>;
    } | null;
    can: { update: boolean; submit: boolean; review: boolean; close: boolean; reopen: boolean; followupsManage: boolean; followupsComplete: boolean; portalManage: boolean; raiseCorrectiveAction: boolean };
    assignable_staff: Array<{ id: number; name: string }>;
    safeguarding_concerns?: Array<{ id: number; reference_number: string | null; status: string | null; severity: string | null; can_view: boolean }>;
    fleet_incident?: { id: number; reference: string; type: string } | null;
    medication_error?: { id: number; error_type: string; severity: string; status: string; medication: string | null; reported_at: string | null; url: string } | null;
    restraint_events?: Array<{ id: number; reference: string; restraint_type: string; severity: string; within_support_plan: boolean; injury_occurred: boolean }>;
    first_aid_records?: Array<{ id: number; reference: string; person: string; injury: string; treatment_date: string | null; ambulance_called: boolean }>;
};

type SectionKey = 'overview' | 'timeline' | 'photos' | 'followups' | 'investigation' | 'linked';

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
const STATUS_LABEL: Record<string, string> = { draft: 'Draft', submitted: 'Submitted', reviewed: 'Reviewed', closed: 'Closed' };

function titleCase(s: string): string {
    return s.replace(/_/g, ' ').replace(/\b\w/g, (c) => c.toUpperCase());
}
function fmtSize(bytes: number | null): string {
    if (!bytes) return '';
    if (bytes < 1024) return `${bytes} B`;
    if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`;
    return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

/* ------------------------------------------------------------------ */
/*  Dialog                                                             */
/* ------------------------------------------------------------------ */

type LifecycleAction = 'review' | 'close' | 'reopen';

export function IncidentDetailDialog({ detail, open, onClose }: { detail: IncidentDetail; open: boolean; onClose: () => void }) {
    const [section, setSection] = useState<SectionKey>('overview');
    const [action, setAction] = useState<LifecycleAction | null>(null);
    const [editing, setEditing] = useState(false);

    const d = detail;
    const clientName = d.client ? `${d.client.first_name} ${d.client.last_name}`.trim() : 'No client linked';
    const isNearMiss = d.type === 'near_miss';
    const openFollowups = d.followups.filter((f) => !f.completed_at).length;

    const SECTIONS: { key: SectionKey; label: string; blurb: string; icon: ComponentType<{ className?: string }> }[] = [
        { key: 'overview', label: 'Overview', blurb: 'What happened', icon: FileText },
        { key: 'timeline', label: 'Timeline', blurb: 'Audit trail', icon: Clock },
        { key: 'photos', label: 'Photos & documents', blurb: `${d.attachments.length} file${d.attachments.length === 1 ? '' : 's'}`, icon: Paperclip },
        { key: 'followups', label: 'Follow-ups', blurb: openFollowups > 0 ? `${openFollowups} open` : 'all complete', icon: ListTodo },
        { key: 'investigation', label: 'Investigation', blurb: d.hs_event ? d.hs_event.reference_number : 'no H&S event', icon: Search },
        { key: 'linked', label: 'Linked records', blurb: 'CR · H&S · client', icon: LinkIcon },
    ];
    const stepIndex = SECTIONS.findIndex((s) => s.key === section);

    // Both endpoints return back() -> Inertia follows the redirect to the current
    // URL (which still carries ?incident=), so the dialog + list refresh together.
    const submit = () => router.post(`/incidents/${d.id}/submit`, {}, { preserveScroll: true });
    const completeFollowup = (fid: number) => router.post(`/incidents/${d.id}/followups/${fid}/complete`, {}, { preserveScroll: true });

    // While an action / edit pane is open it owns the body + its own buttons,
    // so the Options bar is suppressed.
    const footerEnd = action || editing ? null : (
        <div className="flex flex-wrap items-center gap-2">
            <Link href={`/incidents/${d.id}`} className="inline-flex items-center gap-1.5 rounded-lg px-3 py-2 text-sm font-medium text-muted-foreground transition-colors hover:bg-muted">
                <ExternalLink className="h-4 w-4" /> Open full page
            </Link>
            {d.can.update && d.status === 'draft' ? (
                <Button size="sm" variant="outline" onClick={() => setEditing(true)}>
                    <Pencil className="mr-1.5 h-4 w-4" /> Edit
                </Button>
            ) : null}
            {d.can.submit && d.status === 'draft' ? (
                <Button size="sm" onClick={submit}>
                    <Send className="mr-1.5 h-4 w-4" /> Submit for review
                </Button>
            ) : null}
            {d.can.review && d.status === 'submitted' ? (
                <Button size="sm" onClick={() => setAction('review')}>
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Review
                </Button>
            ) : null}
            {d.can.close && d.status === 'reviewed' ? (
                <Button size="sm" onClick={() => setAction('close')}>
                    <CheckCircle2 className="mr-1.5 h-4 w-4" /> Close
                </Button>
            ) : null}
            {d.can.reopen && d.status === 'closed' ? (
                <Button size="sm" variant="outline" onClick={() => setAction('reopen')}>
                    <RotateCcw className="mr-1.5 h-4 w-4" /> Reopen
                </Button>
            ) : null}
        </div>
    );

    const footerStart = (
        <div className="flex items-center gap-2 text-xs">
            <span className={`inline-flex items-center gap-1 rounded-full bg-muted px-2 py-0.5 font-medium`}>
                <span className={`h-1.5 w-1.5 rounded-full ${DOT[SEV_TONE[d.severity] ?? 'neutral']}`} />
                {SEV_LABEL[d.severity] ?? d.severity}
            </span>
            <span className="text-muted-foreground">{STATUS_LABEL[d.status] ?? d.status}</span>
        </div>
    );

    return (
        <WizardShell
            open={open}
            onClose={onClose}
            title={`Incident INC-${d.id}`}
            description={`${titleCase(d.type)} — ${clientName}`}
            railIcon={isNearMiss ? ShieldAlert : AlertTriangle}
            railTitle={clientName}
            railSub={`INC-${d.id} · ${titleCase(d.type)}`}
            steps={SECTIONS}
            stepIndex={stepIndex}
            onStepClick={(i) => setSection(SECTIONS[i].key)}
            footerStart={footerStart}
            footerEnd={footerEnd}
        >
            {editing ? (
                <EditPane d={d} onDone={() => setEditing(false)} />
            ) : action ? (
                <ActionPane incidentId={d.id} action={action} onDone={() => setAction(null)} />
            ) : (
                <>
                    {section === 'overview' ? <OverviewSection d={d} isNearMiss={isNearMiss} /> : null}
                    {section === 'timeline' ? <TimelineSection d={d} /> : null}
                    {section === 'photos' ? <PhotosSection d={d} /> : null}
                    {section === 'followups' ? <FollowupsSection d={d} onComplete={completeFollowup} /> : null}
                    {section === 'investigation' ? <InvestigationSection d={d} /> : null}
                    {section === 'linked' ? <LinkedSection d={d} clientName={clientName} /> : null}
                </>
            )}
        </WizardShell>
    );
}

/* ------------------------------------------------------------------ */
/*  Lifecycle action pane (review / close / reopen)                    */
/* ------------------------------------------------------------------ */

const ACTION_META: Record<LifecycleAction, { title: string; blurb: string; icon: ComponentType<{ className?: string }>; cta: string }> = {
    review: { title: 'Review incident', blurb: 'Mark this incident as reviewed and add any notes.', icon: CheckCircle2, cta: 'Mark reviewed' },
    close: { title: 'Close incident', blurb: 'Record the outcome to close. High-severity incidents need a completed investigation and no open follow-ups.', icon: CheckCircle2, cta: 'Close incident' },
    reopen: { title: 'Reopen incident', blurb: 'Reopen a closed incident — a reason is required for the audit trail.', icon: RotateCcw, cta: 'Reopen incident' },
};

function ActionPane({ incidentId, action, onDone }: { incidentId: number; action: LifecycleAction; onDone: () => void }) {
    const meta = ACTION_META[action];
    const form = useForm<{ review_notes: string; closed_outcome: string; closed_notes: string; reopened_reason: string }>({
        review_notes: '',
        closed_outcome: '',
        closed_notes: '',
        reopened_reason: '',
    });

    const submit = (e: FormEvent) => {
        e.preventDefault();
        const url = `/incidents/${incidentId}/${action}`;
        form.post(url, {
            preserveScroll: true,
            // Guardrail failures come back as flash.error on a 302 (Inertia onSuccess),
            // not a 422 — keep the pane open in that case so the user can adjust.
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={meta.icon} title={meta.title} blurb={meta.blurb} />

            {action === 'review' ? (
                <Field label="Review notes" hint="Optional">
                    <Textarea rows={4} value={form.data.review_notes} onChange={(e) => form.setData('review_notes', e.target.value)} placeholder="Notes from your review…" />
                </Field>
            ) : null}

            {action === 'close' ? (
                <>
                    <Field label="Outcome" required error={form.errors.closed_outcome}>
                        <Input value={form.data.closed_outcome} onChange={(e) => form.setData('closed_outcome', e.target.value)} placeholder="e.g. Resolved — care plan updated" />
                    </Field>
                    <Field label="Closing notes" hint="Optional">
                        <Textarea rows={3} value={form.data.closed_notes} onChange={(e) => form.setData('closed_notes', e.target.value)} />
                    </Field>
                </>
            ) : null}

            {action === 'reopen' ? (
                <Field label="Reason for reopening" required error={form.errors.reopened_reason}>
                    <Textarea rows={4} value={form.data.reopened_reason} onChange={(e) => form.setData('reopened_reason', e.target.value)} placeholder="Why is this incident being reopened?" />
                </Field>
            ) : null}

            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    {meta.cta}
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Edit pane (core fields — drafts only)                              */
/* ------------------------------------------------------------------ */

const INCIDENT_TYPES = [
    { value: 'injury', label: 'Injury' },
    { value: 'fall', label: 'Fall' },
    { value: 'behaviour', label: 'Behaviour' },
    { value: 'medication', label: 'Medication' },
    { value: 'safeguarding', label: 'Safeguarding' },
    { value: 'near_miss', label: 'Near miss' },
    { value: 'property_damage', label: 'Property damage' },
    { value: 'missing_person', label: 'Missing person' },
    { value: 'complaint', label: 'Complaint' },
    { value: 'other', label: 'Other' },
];
const SEVERITY_OPTIONS = [
    { value: 'low', label: 'Low' },
    { value: 'medium', label: 'Medium' },
    { value: 'high', label: 'High' },
];
const POTENTIAL_OPTIONS = [...SEVERITY_OPTIONS, { value: 'critical', label: 'Critical' }];

function toLocalInput(iso: string): string {
    const dt = new Date(iso);
    if (Number.isNaN(dt.getTime())) return '';
    return new Date(dt.getTime() - dt.getTimezoneOffset() * 60000).toISOString().slice(0, 16);
}

function EditPane({ d, onDone }: { d: IncidentDetail; onDone: () => void }) {
    const form = useForm({
        type: d.type,
        severity: d.severity,
        occurred_at: d.occurred_at ? toLocalInput(d.occurred_at) : '',
        description: d.description ?? '',
        immediate_action_taken: d.immediate_action_taken ?? '',
        witnesses: d.witnesses ?? '',
        potential_severity: d.potential_severity ?? '',
        potential_consequence: d.potential_consequence ?? '',
        is_notifiable: d.is_notifiable,
    });
    const isNearMiss = form.data.type === 'near_miss';

    const submit = (e: FormEvent) => {
        e.preventDefault();
        form.put(`/incidents/${d.id}`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) onDone();
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-4">
            <StepHead icon={Pencil} title="Edit incident" blurb="Update the incident details. Drafts only — once submitted, the record is locked for audit." />
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Type" required error={form.errors.type}>
                    <SelectInput value={form.data.type} onChange={(v) => form.setData('type', v)} placeholder="Select type" options={INCIDENT_TYPES} />
                </Field>
                <Field label="Severity" required error={form.errors.severity}>
                    <SelectInput value={form.data.severity} onChange={(v) => form.setData('severity', v)} placeholder="Select severity" options={SEVERITY_OPTIONS} />
                </Field>
            </div>
            <Field label="When it occurred" error={form.errors.occurred_at}>
                <Input type="datetime-local" value={form.data.occurred_at} onChange={(e) => form.setData('occurred_at', e.target.value)} />
            </Field>
            <Field label="What happened" error={form.errors.description}>
                <Textarea rows={4} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
            </Field>
            <Field label="Immediate action taken" error={form.errors.immediate_action_taken}>
                <Textarea rows={3} value={form.data.immediate_action_taken} onChange={(e) => form.setData('immediate_action_taken', e.target.value)} />
            </Field>
            <Field label="Witnesses" error={form.errors.witnesses}>
                <Input value={form.data.witnesses} onChange={(e) => form.setData('witnesses', e.target.value)} placeholder="Names of any witnesses" />
            </Field>
            {isNearMiss ? (
                <div className="grid gap-3 sm:grid-cols-2">
                    <Field label="Potential severity" error={form.errors.potential_severity}>
                        <SelectInput value={form.data.potential_severity} onChange={(v) => form.setData('potential_severity', v)} placeholder="What could have happened" options={POTENTIAL_OPTIONS} />
                    </Field>
                    <Field label="Could have caused" error={form.errors.potential_consequence}>
                        <Input value={form.data.potential_consequence} onChange={(e) => form.setData('potential_consequence', e.target.value)} />
                    </Field>
                </div>
            ) : null}
            <label className="flex items-center gap-2 text-sm text-foreground">
                <input type="checkbox" checked={form.data.is_notifiable} onChange={(e) => form.setData('is_notifiable', e.target.checked)} className="h-4 w-4 rounded border-border" />
                WorkSafe NZ notifiable event
            </label>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" onClick={onDone}>
                    Cancel
                </Button>
                <Button type="submit" disabled={form.processing}>
                    Save changes
                </Button>
            </div>
        </form>
    );
}

/* ------------------------------------------------------------------ */
/*  Sections                                                           */
/* ------------------------------------------------------------------ */

function OverviewSection({ d, isNearMiss }: { d: IncidentDetail; isNearMiss: boolean }) {
    const concerns = d.safeguarding_concerns ?? [];
    const escalation = concerns.length ? (concerns.find((c) => c.can_view) ?? concerns[0]) : null;
    return (
        <div className="grid gap-4 sm:grid-cols-2">
            {escalation ? (
                <div className="sm:col-span-2">
                    <InfoCard icon={ShieldAlert} tone="warn">
                        <span className="font-semibold">Escalated to safeguarding.</span>{' '}
                        {escalation.can_view ? (
                            <>
                                Concern {escalation.reference_number}
                                {escalation.status ? ` · ${titleCase(escalation.status)}` : ''}.{' '}
                                <Link href={`/safeguarding/${escalation.id}`} className="font-medium text-primary hover:underline">
                                    Open concern
                                </Link>
                            </>
                        ) : (
                            'A safeguarding concern was raised from this incident (restricted — need-to-know).'
                        )}
                    </InfoCard>
                </div>
            ) : null}

            {d.is_notifiable ? (
                <div className="sm:col-span-2">
                    <InfoCard icon={ShieldAlert} tone="crit">
                        <span className="font-semibold">WorkSafe NZ notifiable event.</span>{' '}
                        {d.worksafe_notification_status === 'notified'
                            ? `Notified${d.worksafe_notified_at ? ` ${formatDateTime(d.worksafe_notified_at)}` : ''}${d.worksafe_reference ? ` · ref ${d.worksafe_reference}` : ''}.`
                            : 'Notification to WorkSafe NZ is still pending — notify from the full page.'}
                    </InfoCard>
                </div>
            ) : null}

            <ReviewCard icon={FileText} title="What happened" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.description || '—'}</p>
            </ReviewCard>

            <ReviewCard icon={Users} title="People">
                <ReviewRow label="Client" value={d.client ? `${d.client.first_name} ${d.client.last_name}` : undefined} />
                <ReviewRow label="Reported by" value={d.reporter?.name} />
                <ReviewRow label="Witnesses" value={d.witnesses} />
            </ReviewCard>

            <ReviewCard icon={Search} title="Classification">
                <ReviewRow label="Source" value={titleCase(d.source)} />
                <ReviewRow label={isNearMiss ? 'Potential severity' : 'Severity'} value={isNearMiss ? (d.potential_severity ? SEV_LABEL[d.potential_severity] : undefined) : SEV_LABEL[d.severity]} />
                {isNearMiss ? <ReviewRow label="Could have caused" value={d.potential_consequence} /> : null}
                <ReviewRow label="H&S event" value={d.hs_event?.reference_number} />
            </ReviewCard>

            <ReviewCard icon={CheckCircle2} title="Immediate actions" span>
                <p className="text-sm whitespace-pre-wrap text-foreground">{d.immediate_action_taken || '—'}</p>
            </ReviewCard>
        </div>
    );
}

function TimelineSection({ d }: { d: IncidentDetail }) {
    type TLEvent = { at: string; label: string; tone: string; icon: ComponentType<{ className?: string }> };
    const events: TLEvent[] = [];
    if (d.occurred_at) events.push({ at: d.occurred_at, label: 'Incident occurred', tone: 'neutral', icon: AlertTriangle });
    if (d.control_room_alert?.triggered_at) events.push({ at: d.control_room_alert.triggered_at, label: 'Control Room alert raised', tone: 'critical', icon: RadioTower });
    if (d.submitted_at) events.push({ at: d.submitted_at, label: 'Submitted for review', tone: 'info', icon: Send });
    if (d.reviewed_at) events.push({ at: d.reviewed_at, label: 'Reviewed', tone: 'primary', icon: CheckCircle2 });
    if (d.reopened_at) events.push({ at: d.reopened_at, label: `Reopened${d.reopened_reason ? ` · ${d.reopened_reason}` : ''}`, tone: 'warning', icon: RotateCcw });
    if (d.closed_at) events.push({ at: d.closed_at, label: `Closed${d.closed_outcome ? ` · ${d.closed_outcome}` : ''}`, tone: 'success', icon: CheckCircle2 });
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

function PhotosSection({ d }: { d: IncidentDetail }) {
    // Attachments are only mutable while the incident is a draft (server guardrail).
    const canEdit = d.status === 'draft' && d.can.update;
    return (
        <div className="flex flex-col gap-3">
            {canEdit ? <AttachmentUploader endpoint={`/incidents/${d.id}/attachments`} hint="PDF, Word, images — up to 10 MB each" /> : null}

            {d.attachments.length ? (
                <div className="flex flex-col gap-2">
                    {d.attachments.map((a) => (
                        <AttachmentRow key={a.id} a={a} incidentId={d.id} canEdit={canEdit} canPortal={d.can.portalManage} />
                    ))}
                </div>
            ) : (
                <div className="rounded-xl border border-dashed border-border py-10 text-center">
                    <Paperclip className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">No photos or documents attached.</p>
                    {!canEdit ? <p className="mt-1 text-xs text-muted-foreground/70">Attachments can only be added while the incident is a draft.</p> : null}
                </div>
            )}
        </div>
    );
}

function AttachmentRow({ a, incidentId, canEdit, canPortal }: { a: IncidentDetail['attachments'][number]; incidentId: number; canEdit: boolean; canPortal: boolean }) {
    const remove = () => router.delete(`/incidents/${incidentId}/attachments/${a.id}`, { preserveScroll: true });
    const togglePortal = () => router.patch(`/incidents/${incidentId}/attachments/${a.id}`, { portal_visible: !a.portal_visible }, { preserveScroll: true });
    return (
        <div className="flex items-center gap-3 rounded-lg border border-border p-3">
            <FileText className="h-5 w-5 shrink-0 text-muted-foreground" />
            <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-medium text-foreground">{a.name}</p>
                <p className="text-xs text-muted-foreground">
                    {fmtSize(a.size)}
                    {a.uploaded_by ? ` · ${a.uploaded_by}` : ''}
                    {a.created_at ? ` · ${formatDateTime(a.created_at)}` : ''}
                    {a.portal_visible ? ' · shared to portal' : ''}
                </p>
                {a.notes ? <p className="mt-0.5 text-xs text-muted-foreground">{a.notes}</p> : null}
            </div>
            {canPortal ? (
                <Button variant="ghost" size="sm" onClick={togglePortal} title={a.portal_visible ? 'Stop sharing to the family portal' : 'Share to the family portal'}>
                    {a.portal_visible ? 'Unshare' : 'Share'}
                </Button>
            ) : null}
            <a href={a.download_url} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted">
                <Download className="h-3.5 w-3.5" /> Download
            </a>
            {canEdit ? (
                <Button variant="ghost" size="sm" onClick={remove} title="Remove attachment" className="text-status-critical hover:text-status-critical">
                    <Trash2 className="h-3.5 w-3.5" />
                </Button>
            ) : null}
        </div>
    );
}

function FollowupsSection({ d, onComplete }: { d: IncidentDetail; onComplete: (id: number) => void }) {
    return (
        <div className="flex flex-col gap-3">
            {d.can.followupsManage ? <AddFollowupForm d={d} /> : null}

            {d.followups.length ? (
                <div className="flex flex-col gap-2">
                    {d.followups.map((f) => (
                        <div key={f.id} className="flex items-start gap-3 rounded-lg border border-border p-3">
                            <ListTodo className={`mt-0.5 h-4 w-4 shrink-0 ${f.completed_at ? 'text-status-success' : f.overdue ? 'text-status-critical' : 'text-status-warning'}`} />
                            <div className="min-w-0 flex-1">
                                <p className="text-sm text-foreground">{f.notes || 'Follow-up task'}</p>
                                <p className="text-xs text-muted-foreground">
                                    {f.assigned_to ? `${f.assigned_to}` : 'Unassigned'}
                                    {f.due_at ? ` · due ${formatDateTime(f.due_at)}` : ''}
                                    {f.completed_at ? ` · completed ${formatDateTime(f.completed_at)}` : f.overdue ? ' · overdue' : ''}
                                </p>
                            </div>
                            {!f.completed_at && d.can.followupsComplete ? (
                                <Button variant="outline" size="sm" onClick={() => onComplete(f.id)}>
                                    <CheckCircle2 className="mr-1.5 h-3.5 w-3.5" /> Complete
                                </Button>
                            ) : f.completed_at ? (
                                <span className="inline-flex items-center gap-1 text-xs font-medium text-status-success">
                                    <CheckCircle2 className="h-3.5 w-3.5" /> Done
                                </span>
                            ) : null}
                        </div>
                    ))}
                </div>
            ) : (
                <div className="rounded-xl border border-dashed border-border py-10 text-center">
                    <ListTodo className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                    <p className="text-sm text-muted-foreground">No follow-ups on this incident.</p>
                </div>
            )}
        </div>
    );
}

function AddFollowupForm({ d }: { d: IncidentDetail }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ notes: string; assigned_to_user_id: string; due_at: string }>({ notes: '', assigned_to_user_id: '', due_at: '' });

    if (!open) {
        return (
            <Button variant="outline" size="sm" className="self-start" onClick={() => setOpen(true)}>
                <Plus className="mr-1.5 h-3.5 w-3.5" /> Add follow-up
            </Button>
        );
    }

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.notes.trim()) {
            form.setError('notes', 'Describe the follow-up task.');
            return;
        }
        form.post(`/incidents/${d.id}/followups`, {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                setOpen(false);
            },
        });
    };

    return (
        <form onSubmit={submit} className="flex flex-col gap-3 rounded-xl border border-border bg-muted/30 p-3">
            <Field label="Task" required error={form.errors.notes}>
                <Textarea rows={2} value={form.data.notes} onChange={(e) => form.setData('notes', e.target.value)} placeholder="e.g. Update the care plan and notify the GP" />
            </Field>
            <div className="grid gap-3 sm:grid-cols-2">
                <Field label="Assign to">
                    <SelectInput
                        value={form.data.assigned_to_user_id}
                        onChange={(v) => form.setData('assigned_to_user_id', v)}
                        placeholder="Unassigned"
                        options={d.assignable_staff.map((s) => ({ value: String(s.id), label: s.name }))}
                    />
                </Field>
                <Field label="Due">
                    <Input type="date" value={form.data.due_at} onChange={(e) => form.setData('due_at', e.target.value)} />
                </Field>
            </div>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" onClick={() => { form.reset(); form.clearErrors(); setOpen(false); }}>
                    Cancel
                </Button>
                <Button type="submit" size="sm" disabled={form.processing}>
                    Add follow-up
                </Button>
            </div>
        </form>
    );
}

function InvestigationSection({ d }: { d: IncidentDetail }) {
    if (!d.hs_event) {
        return (
            <div className="rounded-xl border border-dashed border-border py-12 text-center">
                <Search className="mx-auto mb-2 h-8 w-8 text-muted-foreground/40" />
                <p className="text-sm text-muted-foreground">No Health &amp; Safety event recorded for this incident.</p>
            </div>
        );
    }
    const ev = d.hs_event;
    const inv = ev.investigation;
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-center justify-between rounded-lg border border-border bg-muted/30 p-3">
                <div>
                    <p className="text-sm font-semibold text-foreground">{ev.reference_number}</p>
                    <p className="text-xs text-muted-foreground">H&amp;S event · {titleCase(ev.status)}{ev.investigation_required ? ' · investigation required' : ''}</p>
                </div>
                <Link href={`/health-safety/events/${ev.id}`} className="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-medium text-primary transition-colors hover:bg-muted">
                    <ExternalLink className="h-3.5 w-3.5" /> Open in Health &amp; Safety
                </Link>
            </div>

            {inv ? (
                <ReviewCard icon={Search} title={`Investigation ${inv.reference_number}`} span>
                    <ReviewRow label="Status" value={titleCase(inv.status)} />
                    <ReviewRow label="Methodology" value={inv.methodology ? inv.methodology.replace(/_/g, '-') : undefined} />
                    {inv.root_causes?.length ? <ReviewRow label="Root causes" value={inv.root_causes.map((c) => c.description).filter(Boolean).join('; ')} /> : null}
                    {inv.recommendations?.length ? <ReviewRow label="Recommendations" value={inv.recommendations.map((r) => r.description).filter(Boolean).join('; ')} /> : null}
                    {inv.lessons_learned ? <ReviewRow label="Lessons learned" value={inv.lessons_learned} /> : null}
                </ReviewCard>
            ) : (
                <p className="text-sm text-muted-foreground">No investigation has been opened yet.</p>
            )}

            <div>
                <div className="mb-2 flex items-center justify-between">
                    <p className="text-sm font-semibold text-foreground">Corrective actions</p>
                    <Link href={`/health-safety/corrective-actions?event=${ev.id}`} className="text-xs font-medium text-primary hover:underline">
                        Open register
                    </Link>
                </div>
                {ev.corrective_actions.length ? (
                    <div className="flex flex-col gap-2">
                        {ev.corrective_actions.map((ca) => (
                            <div key={ca.id} className="flex items-center justify-between rounded-lg border border-border p-2.5 text-sm">
                                <div className="min-w-0">
                                    <p className="truncate font-medium text-foreground">{ca.reference_number} · {ca.title}</p>
                                    <p className="text-xs text-muted-foreground">{titleCase(ca.status)}{ca.assigned_to ? ` · ${ca.assigned_to}` : ''}{ca.due_date ? ` · due ${formatDateTime(ca.due_date)}` : ''}</p>
                                </div>
                            </div>
                        ))}
                    </div>
                ) : (
                    <p className="text-xs text-muted-foreground">No corrective actions raised. Formal remediation is raised and governed in the Health &amp; Safety register.</p>
                )}

                {d.can.raiseCorrectiveAction ? <RaiseCorrectiveActionForm d={d} /> : null}
            </div>
        </div>
    );
}

function RaiseCorrectiveActionForm({ d }: { d: IncidentDetail }) {
    const [open, setOpen] = useState(false);
    const form = useForm<{ title: string; description: string; priority: string; due_date: string; assigned_to_user_id: string }>({
        title: '',
        description: '',
        priority: 'medium',
        due_date: '',
        assigned_to_user_id: '',
    });

    if (!d.hs_event) {
        return null; // need an H&S event to attach the action to
    }

    if (!open) {
        return (
            <Button variant="outline" size="sm" className="mt-2" onClick={() => setOpen(true)}>
                <Plus className="mr-1.5 h-3.5 w-3.5" /> Raise corrective action
            </Button>
        );
    }

    const submit = (e: FormEvent) => {
        e.preventDefault();
        if (!form.data.title.trim()) {
            form.setError('title', 'Give the corrective action a title.');
            return;
        }
        const hsEventId = d.hs_event?.id;
        form.post(`/incidents/${d.id}/corrective-actions`, {
            preserveScroll: true,
            onSuccess: (page) => {
                if (!(page.props as { flash?: { error?: string } }).flash?.error) {
                    form.reset();
                    setOpen(false);
                    // Land on the new action: open its parent H&S event on the
                    // Corrective actions pane in the register (reads ?event=).
                    if (hsEventId) router.visit(`/health-safety/corrective-actions?event=${hsEventId}`);
                }
            },
        });
    };

    return (
        <form onSubmit={submit} className="mt-2 flex flex-col gap-3 rounded-xl border border-border bg-muted/30 p-3">
            <p className="text-xs text-muted-foreground">Creates a row in the H&amp;S Corrective Actions register, linked to {d.hs_event.reference_number}.</p>
            <Field label="Action" required error={form.errors.title}>
                <Input value={form.data.title} onChange={(e) => form.setData('title', e.target.value)} placeholder="e.g. Install a grab rail in the bathroom" />
            </Field>
            <Field label="Detail" hint="Optional">
                <Textarea rows={2} value={form.data.description} onChange={(e) => form.setData('description', e.target.value)} />
            </Field>
            <div className="grid gap-3 sm:grid-cols-3">
                <Field label="Priority">
                    <SelectInput
                        value={form.data.priority}
                        onChange={(v) => form.setData('priority', v)}
                        placeholder="Priority"
                        options={[
                            { value: 'low', label: 'Low' },
                            { value: 'medium', label: 'Medium' },
                            { value: 'high', label: 'High' },
                            { value: 'critical', label: 'Critical' },
                        ]}
                    />
                </Field>
                <Field label="Owner">
                    <SelectInput value={form.data.assigned_to_user_id} onChange={(v) => form.setData('assigned_to_user_id', v)} placeholder="Unassigned" options={d.assignable_staff.map((s) => ({ value: String(s.id), label: s.name }))} />
                </Field>
                <Field label="Due">
                    <Input type="date" value={form.data.due_date} onChange={(e) => form.setData('due_date', e.target.value)} />
                </Field>
            </div>
            <div className="flex justify-end gap-2">
                <Button type="button" variant="outline" size="sm" onClick={() => { form.reset(); form.clearErrors(); setOpen(false); }}>
                    Cancel
                </Button>
                <Button type="submit" size="sm" disabled={form.processing}>
                    Raise in H&amp;S register
                </Button>
            </div>
        </form>
    );
}

function LinkedSection({ d, clientName }: { d: IncidentDetail; clientName: string }) {
    return (
        <div className="flex flex-col gap-2">
            {d.control_room_alert ? (
                <LinkedRow
                    icon={RadioTower}
                    title="Control Room alert"
                    sub={`${titleCase(d.control_room_alert.alert_type)} · ${titleCase(d.control_room_alert.status)}${d.control_room_alert.triggered_at ? ` · ${formatDateTime(d.control_room_alert.triggered_at)}` : ''}`}
                    href={`/control-room/alerts/${d.control_room_alert.id}`}
                />
            ) : null}
            {d.hs_event ? (
                <LinkedRow icon={ShieldAlert} title="Health & Safety event" sub={`${d.hs_event.reference_number} · ${titleCase(d.hs_event.status)}`} href={`/health-safety/events/${d.hs_event.id}`} />
            ) : null}
            {(d.safeguarding_concerns ?? []).map((c) =>
                c.can_view ? (
                    <LinkedRow key={c.id} icon={ShieldAlert} title="Safeguarding concern" sub={`${c.reference_number}${c.status ? ` · ${titleCase(c.status)}` : ''}`} href={`/safeguarding/${c.id}`} />
                ) : (
                    <div key={c.id} className="flex items-center gap-3 rounded-lg border border-dashed border-border p-3 text-muted-foreground">
                        <ShieldAlert className="h-4 w-4 shrink-0" />
                        <span className="text-sm">Safeguarding concern raised · restricted (need-to-know)</span>
                    </div>
                ),
            )}
            {d.fleet_incident ? (
                <LinkedRow icon={Truck} title="Fleet incident" sub={`${d.fleet_incident.reference} · ${titleCase(d.fleet_incident.type)}`} href={`/fleet-assets/incidents?incident=${d.fleet_incident.id}`} />
            ) : null}
            {d.medication_error ? (
                <LinkedRow
                    icon={Pill}
                    title="Medication error report"
                    sub={`${titleCase(d.medication_error.error_type)} · ${titleCase(d.medication_error.severity)} · ${titleCase(d.medication_error.status)}${d.medication_error.medication ? ` · ${d.medication_error.medication}` : ''}`}
                    href={d.medication_error.url}
                />
            ) : null}
            {(d.restraint_events ?? []).map((r) => (
                <LinkedRow
                    key={`re-${r.id}`}
                    icon={Hand}
                    title="Restraint event"
                    sub={`${r.reference} · ${titleCase(r.restraint_type)} · ${titleCase(r.severity)}${r.within_support_plan ? '' : ' · out of plan'}${r.injury_occurred ? ' · injury' : ''}`}
                    href={`/health-safety/restraints?event=${r.id}`}
                />
            ))}
            {(d.first_aid_records ?? []).map((r) => (
                <LinkedRow
                    key={`fa-${r.id}`}
                    icon={HeartPulse}
                    title="First-aid treatment"
                    sub={`${r.reference} · ${r.person} · ${titleCase(r.injury)}${r.ambulance_called ? ' · ambulance' : ''}`}
                    href={`/health-safety/first-aid?record=${r.id}`}
                />
            ))}
            {d.client ? <LinkedRow icon={User} title="Client record" sub={clientName} href={`/operations/clients/${d.client.id}/care`} /> : null}
            {!d.control_room_alert && !d.hs_event && !d.client && !d.fleet_incident && !d.medication_error && !(d.safeguarding_concerns ?? []).length && !(d.restraint_events ?? []).length && !(d.first_aid_records ?? []).length ? (
                <p className="text-sm text-muted-foreground">No linked records.</p>
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
