import { FilePreviewDialog, type PreviewFile } from '@/components/files/file-preview-dialog';
import { Button } from '@/components/ui/button';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { StatusBadge } from '@/components/ui/status-badge';
import { formatDateLong, formatDateTimeLong } from '@/lib/datetime';
import { injuryReadLeaf } from '@/lib/health-safety/injury-read-leaf';
import { Link } from '@inertiajs/react';
import { useState, type ReactNode } from 'react';
import { injuryCommandAllowed, INJURY_COMMAND_LABELS } from './injury-command-state';
import { severityLabel, statusLabel } from './injury-constants';
import type { InjuryCommand, InjuryConnection, InjuryRecord, InjurySection } from './injury-workspace-types';

export const injuryDay = (value: string | null | undefined) => formatDateLong(value, 'Not recorded');
export const injuryTime = (value: string | null | undefined) => formatDateTimeLong(value, 'Not recorded');
const label = (value: string | null | undefined) => value?.replaceAll('_', ' ') ?? 'Not recorded';
function Fact({ title, children }: { title: string; children: ReactNode }) {
    return <div className="min-w-0 space-y-1"><dt className="text-subtle">{title}</dt>
        <dd className="text-sm break-words whitespace-pre-wrap">{children == null || children === '' ? 'Not recorded' : children}</dd></div>;
}
function MedicalRestricted() {
    return <div className="space-y-3"><StatusBadge variant="neutral">Medical details restricted</StatusBadge>
        <p className="text-subtle">Your current access covers the injury facts. Recovery assessments, medical documents and private history require separate access.</p></div>;
}
function PrivateSourceState({ detail }: { detail: InjuryRecord }) {
    return detail.medical_access === 'restricted' ? <MedicalRestricted /> : <div className="space-y-3"><StatusBadge variant="warning">Source needs review</StatusBadge><p className="text-subtle">The private records are withheld while their source history is unconfirmed.</p></div>;
}
function Action({ detail, command, plan, attachment, open }: {
    detail: InjuryRecord; command: InjuryCommand; plan?: number; attachment?: number;
    open: (command: InjuryCommand, child?: { plan?: number; attachment?: number }) => void;
}) {
    if (!injuryCommandAllowed(detail, command, { plan, attachment })) return null;
    return <Button type="button" variant="outline" className="frontline-tap"
        onClick={() => open(command, { plan, attachment })}>{INJURY_COMMAND_LABELS[command]}</Button>;
}
type PanelProps = {
    detail: InjuryRecord;
    section: InjurySection;
    open: (command: InjuryCommand, child?: { plan?: number; attachment?: number }) => void;
    sourceHref: string;
};
function Overview({ detail: d, open }: PanelProps) {
    return <div className="space-y-5"><h2 className="text-section-title">Injury and coordination</h2>
        <dl className="grid gap-4 sm:grid-cols-2">
            <Fact title="Injured worker">{d.worker?.name}</Fact><Fact title="Recorded Site">{d.site?.name}</Fact>
            <Fact title="Injury occurred">{injuryTime(d.injury_date)}</Fact><Fact title="Injury type">{d.injury_type_label}</Fact>
            <Fact title="Recorded injury status">{statusLabel(d.status)}</Fact>
            <Fact title="Body part">{d.body_part_affected}</Fact><Fact title="Severity">{severityLabel(d.severity)}</Fact>
            <Fact title="Recovery coordinator">{d.coordination.coordinator?.name ?? (d.coordination.attribution_state === 'unassigned' ? 'Unassigned' : 'Assignment needs review')}</Fact>
            <Fact title="Next review">{injuryDay(d.coordination.next_review_date)}</Fact>
        </dl><dl><Fact title="What happened">{d.description}</Fact></dl>
        <div className="flex flex-wrap gap-2"><Action detail={d} command="assign" open={open} />
            <Action detail={d} command="edit" open={open} /><Action detail={d} command="review" open={open} />
            <Action detail={d} command="close" open={open} /></div>
        <p className="text-subtle">Recovery coordination, operational response and safety review each retain their own owner and outcome.</p>
    </div>;
}
function Recovery({ detail: d, open }: PanelProps) {
    if (d.rtw_plans === null) return <PrivateSourceState detail={d} />;
    return <div className="space-y-5"><div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-section-title">Recovery plans</h2><Action detail={d} command="add_rtw" open={open} /></div>
        {d.recovery_integrity === 'repair_required' && <p role="status" className="text-sm text-status-warning">The worker or source history needs review before new recovery work can be saved.</p>}
        {!d.rtw_plans.length && d.recovery_integrity !== 'repair_required' && <p className="text-subtle">No recovery plan is recorded.</p>}
        <div className="flex flex-wrap gap-2"><Action detail={d} command="begin_rtw" open={open} /><Action detail={d} command="mark_recovered" open={open} /></div>
        {d.rtw_plans.map((plan) => <article key={plan.id} id={`plan-${plan.id}`} tabIndex={-1} className="space-y-4 rounded-lg border p-4 focus-visible:ring-2 focus-visible:ring-ring">
            <div className="flex flex-wrap items-center justify-between gap-3"><h3 className="font-medium">Recovery plan {plan.id}</h3>
                <StatusBadge variant="neutral">{label(plan.status)}</StatusBadge></div>
            {plan.attribution_state === 'legacy_unknown' && <p className="text-subtle">Historical record. Worker and recorder attribution has not been independently confirmed.</p>}
            <dl className="grid gap-4 sm:grid-cols-2"><Fact title="Recorded worker">{plan.recorded_worker?.name ?? plan.worker?.name}</Fact>
                <Fact title="Plan manager">{plan.manager?.name}</Fact><Fact title="Plan starts">{injuryDay(plan.plan_start_date)}</Fact>
                <Fact title="Plan ends">{injuryDay(plan.plan_end_date)}</Fact><Fact title="Next plan review">{injuryDay(plan.next_review_date)}</Fact>
                <Fact title="Recorded by">{plan.recorder?.name}</Fact></dl>
            <div><p className="text-subtle">Recovery goals</p><ul className="mt-2 list-disc space-y-1 pl-5">{plan.goals.map((goal, index) => <li key={index} className="text-sm break-words">{goal}</li>)}</ul></div>
            <ol className="space-y-3">{plan.stages.map((stage, index) => <li key={index} className="space-y-2 rounded-md bg-muted/40 p-3">
                <p className="font-medium break-words">{stage.name}</p><dl className="grid gap-3 sm:grid-cols-2">
                    <Fact title="Starts">{injuryDay(stage.start_date)}</Fact><Fact title="Ends">{injuryDay(stage.end_date)}</Fact>
                    <Fact title="Planned hours per week">{stage.hours_per_week ?? 'Not recorded'}</Fact><Fact title="Planned duties">{stage.duties_description}</Fact></dl></li>)}</ol>
            <dl className="grid gap-4 sm:grid-cols-2"><Fact title="Recorded clearance provider">{plan.medical_clearance_provider}</Fact>
                <Fact title="Recorded clearance date">{injuryDay(plan.medical_clearance_date)}</Fact>
                <Fact title="Recorded clearance notes">{plan.medical_clearance_notes}</Fact><Fact title="Workplace modifications">{plan.workplace_modifications}</Fact>
                <Fact title="Worker agreement recorded">{plan.worker_agreed ? 'Yes' : 'No'}</Fact><Fact title="Agreement date">{injuryDay(plan.worker_agreed_at)}</Fact>
                <Fact title="Agreement notes">{plan.worker_agreement_notes}</Fact><Fact title="Plan review notes">{plan.review_notes}</Fact></dl>
            <p className="text-subtle">These are recorded recovery arrangements. Work restrictions and scheduling decisions require their own approval.</p>
            <div className="flex flex-wrap gap-2"><Action detail={d} command="edit_rtw" plan={plan.id} open={open} />
                <Action detail={d} command="add_duty" plan={plan.id} open={open} /></div>
            {plan.modified_duties.map((duty) => <div key={duty.id} id={`duty-${duty.id}`} tabIndex={-1} className="space-y-3 border-t pt-4 focus-visible:ring-2 focus-visible:ring-ring">
                <p className="font-medium">Modified duties {duty.id} · {label(duty.status)}</p><dl className="grid gap-4 sm:grid-cols-2">
                    <Fact title="Recorded worker">{duty.recorded_worker?.name ?? duty.user?.name}</Fact><Fact title="Recorded by">{duty.recorder?.name}</Fact>
                    <Fact title="Starts">{injuryDay(duty.start_date)}</Fact><Fact title="Ends">{injuryDay(duty.end_date)}</Fact>
                    <Fact title="Planned hours per day">{duty.hours_per_day ?? 'Not recorded'}</Fact><Fact title="Duties">{duty.modified_duties_description}</Fact>
                    <Fact title="Reported restrictions">{duty.restrictions}</Fact><Fact title="Accommodations">{duty.accommodations}</Fact>
                    <Fact title="Notes">{duty.notes}</Fact></dl><StatusBadge variant="neutral">Approval not confirmed</StatusBadge></div>)}
        </article>)}
    </div>;
}
function Capacity({ detail: d, open }: PanelProps) {
    if (d.capacity_assessments === null) return <PrivateSourceState detail={d} />;
    return <div className="space-y-5"><div className="flex flex-wrap items-center justify-between gap-3">
        <h2 className="text-section-title">Capacity assessments</h2><Action detail={d} command="add_capacity" open={open} /></div>
        {d.recovery_integrity === 'repair_required' && <p role="status" className="text-subtle text-status-warning">Some recovery sources need repair. Unconfirmed assessments are withheld.</p>}
        {!d.capacity_assessments.length && d.recovery_integrity !== 'repair_required' && <p className="text-subtle">No capacity assessment is recorded.</p>}
        {d.capacity_assessments.map((assessment) => <article key={assessment.id} id={`assessment-${assessment.id}`} tabIndex={-1} className="space-y-4 rounded-lg border p-4 focus-visible:ring-2 focus-visible:ring-ring">
            <h3 className="font-medium">Assessment · {injuryDay(assessment.assessment_date)}</h3>
            {assessment.attribution_state === 'legacy_unknown' && <p className="text-sm text-status-warning">The old person field has unclear attribution. It has not been relabelled as the worker or assessor.</p>}
            <dl className="grid gap-4 sm:grid-cols-2"><Fact title="Injured worker">{assessment.recorded_worker?.name ?? assessment.worker?.name}</Fact>
                <Fact title="Attributed assessor">{assessment.assessor?.name ?? assessment.assessor_name}</Fact><Fact title="Recorded assessor type">{label(assessment.assessor_type)}</Fact>
                <Fact title="Recorded by">{assessment.recorder?.name}</Fact><Fact title="Reported capacity">{label(assessment.capacity_status)}</Fact>
                <Fact title="Next assessment">{injuryDay(assessment.next_assessment_date)}</Fact><Fact title="Assessment summary">{assessment.assessment_summary}</Fact>
                <Fact title="Reported restrictions">{assessment.restrictions}</Fact><Fact title="Recommendations">{assessment.recommendations}</Fact></dl>
            <p className="text-subtle">A recorded assessment does not establish verified medical certification or approved work restrictions.</p>
            {assessment.hr_document.access === 'readable' && assessment.hr_document.url && <Button asChild variant="outline" className="frontline-tap">
                <a href={assessment.hr_document.url}>Open {assessment.hr_document.title ?? 'linked HR evidence'}</a></Button>}
            {assessment.hr_document.access === 'restricted' && <p className="text-subtle">Linked HR evidence requires separate access.</p>}
        </article>)}
    </div>;
}
function Scheduling() {
    return <div className="space-y-4"><h2 className="text-section-title">Scheduling impact</h2>
        <StatusBadge variant="neutral">Scheduling review unavailable</StatusBadge>
        <p className="text-sm">The effect on current and future duties has not been confirmed.</p>
        <p className="text-subtle">Approved restrictions, the duties assessed and the responsible scheduling coordinator will appear here when their recorded review is available. An injury or recovery status does not change a shift or confirm fitness to work.</p>
    </div>;
}
function Treatment({ detail: d, open }: PanelProps) {
    return <div className="space-y-5"><h2 className="text-section-title">Treatment and claim facts</h2>
        <dl className="grid gap-4 sm:grid-cols-2"><Fact title="Lost time recorded">{d.lost_time_days} days</Fact>
            <Fact title="Expected return">{injuryDay(d.expected_return_date)}</Fact><Fact title="Actual return recorded">{injuryDay(d.actual_return_date)}</Fact>
            <Fact title="Claim lodged internally recorded">{d.claim_delivery.internally_recorded ? 'Yes' : 'No'}</Fact></dl>
        {d.medical_access === 'readable' ? <dl className="grid gap-4 sm:grid-cols-2"><Fact title="Treatment level">{d.medical_treatment_label}</Fact>
            <Fact title="Immediate treatment">{d.immediate_treatment}</Fact><Fact title="ACC claim number">{d.acc_claim_number}</Fact><Fact title="Private notes">{d.notes}</Fact></dl> : <MedicalRestricted />}
        <StatusBadge variant="neutral">External claim receipt unconfirmed</StatusBadge>
        <p className="text-subtle">A claim fact recorded here does not confirm that ACC received or acknowledged it.</p>
        <div className="flex flex-wrap gap-2"><Action detail={d} command="acc" open={open} /><Action detail={d} command="start_treatment" open={open} /></div>
    </div>;
}
function Connection({ title, record, sourceHref }: { title: string; record: InjuryConnection; sourceHref: string }) {
    const source = new URL(sourceHref, 'https://injury.invalid'); source.searchParams.delete('return_to');
    const back = injuryReadLeaf(source.pathname + source.search);
    const href = record.access === 'readable' && record.url && back ? record.url + (record.url.includes('?') ? '&' : '?') + 'return_to=' + encodeURIComponent(back) : null;
    return <li className="flex flex-wrap items-center justify-between gap-3 p-4"><div className="min-w-0 space-y-1"><p className="font-medium">{title}</p>
        <p className="text-subtle break-words">{record.access === 'readable' ? [record.reference, record.title, record.status && label(record.status)].filter(Boolean).join(' · ')
            : record.access === 'absent' ? 'No connected record'
            : record.access === 'restricted' ? 'Separate access required' : 'Connection needs review'}</p></div>
        {href && <Button asChild variant="outline" className="frontline-tap"><Link href={href}>Open {title.toLowerCase()}</Link></Button>}</li>;
}
function Connections({ detail: d, sourceHref }: PanelProps) {
    const worksafe = d.connections.worksafe;
    return <div className="space-y-5"><h2 className="text-section-title">Connected safety work</h2>
        <ul className="divide-y rounded-lg border"><Connection title="Incident" record={d.connections.incident} sourceHref={sourceHref} />
            <Connection title="Safety review" record={d.connections.health_safety} sourceHref={sourceHref} />
            <Connection title="Control Room response" record={d.connections.control_room} sourceHref={sourceHref} /></ul>
        <h3 className="font-medium">WorkSafe notification</h3>
        {worksafe.access === 'readable' ? <dl className="grid gap-4 sm:grid-cols-2"><Fact title="Decision recorded">{worksafe.decision_state === 'recorded' ? 'Yes' : 'Not confirmed'}</Fact>
            <Fact title="Recorded notification state">{label(worksafe.notification_state)}</Fact><Fact title="Recorded notification time">{injuryTime(worksafe.notified_at)}</Fact>
            <Fact title="Recorded acknowledgement">{injuryTime(worksafe.acknowledged_at)}</Fact><Fact title="Recorded reference">{worksafe.reference}</Fact></dl>
            : <p className="text-subtle">{worksafe.access === 'restricted' ? 'Notification details require separate safety-review access.' : 'Notification details are not confirmed.'}</p>}
        <p className="text-subtle">Recording an injury does not complete a notification, operational response or safety review.</p>
    </div>;
}
function Evidence({ detail: d, open }: PanelProps) {
    const [preview, setPreview] = useState<PreviewFile | null>(null);
    if (d.attachments === null) return <PrivateSourceState detail={d} />;
    return <div className="space-y-5"><div className="flex flex-wrap items-center justify-between gap-3"><h2 className="text-section-title">Evidence</h2>
        <Action detail={d} command="upload_evidence" open={open} /></div>
        {d.evidence_integrity === 'repair_required' && <p role="status" className="text-sm text-status-warning">Some evidence needs review. Its contents and download are unavailable here.</p>}
        {!d.attachments.length && d.evidence_integrity !== 'repair_required' && <p className="text-subtle">No evidence is recorded.</p>}
        <ul className="space-y-3">{d.attachments.map((file) => <li key={file.id} className="space-y-3 rounded-lg border p-4">
            <p className="font-medium break-words">{file.original_name}</p><p className="text-subtle">{file.size == null ? 'Size not recorded' : formatFileSize(file.size)} · {injuryTime(file.created_at)}{file.uploaded_by ? ` · ${file.uploaded_by}` : ''}</p>
            {file.notes && <p className="text-sm whitespace-pre-wrap">{file.notes}</p>}
            {file.availability === 'available' && file.url ? <div className="flex flex-wrap gap-2">
                {(file.is_image || file.mime === 'application/pdf') && <Button type="button" variant="outline" className="frontline-tap" onClick={() => setPreview({
                    id: file.id, version: file.source_version_id ?? undefined,
                    name: file.original_name, mime: file.mime, bytes: file.size, source: d.reference,
                    previewUrl: file.url, downloadUrl: file.url,
                })}>Preview {file.original_name}</Button>}
                <Button asChild variant="outline" className="frontline-tap"><a href={file.url}>Download {file.original_name}</a></Button></div> : <p className="text-subtle">File unavailable. The retained record does not confirm retrievable bytes.</p>}
            <Action detail={d} command="remove_evidence" attachment={file.id} open={open} />
        </li>)}</ul><FilePreviewDialog file={preview} onClose={() => setPreview(null)} />
    </div>;
}
function History({ detail: d }: PanelProps) {
    if (d.history === null) return <PrivateSourceState detail={d} />;
    return <div className="space-y-5"><h2 className="text-section-title">Recorded history</h2>
        {d.history_integrity === 'repair_required' && <p role="status" className="text-sm text-status-warning">Some retained source history needs review. It is not presented as verified history.</p>}
        {!d.history.length && d.history_integrity !== 'repair_required' && <p className="text-subtle">No attributed source version is recorded. Earlier records retain their original audit history.</p>}
        <ol className="space-y-3">{d.history.map((entry) => <li key={entry.source_revision} className="space-y-3 rounded-lg border p-4">
            <p className="font-medium">Version {entry.source_revision} · {label(entry.command)}</p>
            <p className="text-subtle">{injuryTime(entry.recorded_at)} · {entry.recorder?.name ?? 'Recorder not confirmed'}</p>
            {entry.attribution_state === 'legacy_unknown' && <StatusBadge variant="neutral">Historical attribution unknown</StatusBadge>}
            {entry.access === 'restricted' && <p className="text-subtle">Some history belongs to a Site outside your current access. Restricted facts and the recorder are withheld.</p>}
            <details><summary className="frontline-tap cursor-pointer rounded-md text-sm focus-visible:ring-2 focus-visible:ring-ring">{entry.before_access === 'restricted' || entry.after_access === 'restricted' ? 'Source version facts' : 'Recorded changes'}</summary>
                <dl className="mt-3 space-y-3">{[...new Set([...Object.keys(entry.before ?? {}), ...Object.keys(entry.after ?? {})])]
                    .filter((key) => !key.endsWith('_id') && (entry.before_access === 'restricted' || entry.after_access === 'restricted' || JSON.stringify(entry.before?.[key]) !== JSON.stringify(entry.after?.[key])))
                    .map((key) => <Fact key={key} title={historyFieldLabel(key)}>
                        <span className="block text-subtle">Before: {entry.before_access === 'restricted' ? 'Restricted' : historyValue(key, entry.before?.[key])}</span>
                        <span className="block">Recorded: {entry.after_access === 'restricted' ? 'Restricted' : historyValue(key, entry.after?.[key])}</span>
                    </Fact>)}</dl></details>
        </li>)}</ol>
        {!!d.audits?.length && <details><summary className="frontline-tap cursor-pointer rounded-md text-sm focus-visible:ring-2 focus-visible:ring-ring">Earlier audit entries</summary>
            <ol className="mt-3 space-y-2">{d.audits.map((entry) => <li key={entry.id} className="text-sm">{injuryTime(entry.at)} · {entry.actor ?? 'Recorder not confirmed'} · {label(entry.action)}</li>)}</ol></details>}
    </div>;
}
const HISTORY_LABELS: Record<string, string> = {
    injury_date: 'Injury occurred', injury_type: 'Injury type', body_part_affected: 'Body part',
    severity: 'Severity', description: 'What happened', immediate_treatment: 'Immediate treatment',
    medical_treatment_type: 'Treatment level', worksafe_notifiable: 'WorkSafe notifiable recorded',
    acc_claim_lodged: 'Claim lodged internally recorded', acc_claim_number: 'ACC claim number',
    lost_time_days: 'Lost time days', expected_return_date: 'Expected return', actual_return_date: 'Actual return',
    status: 'Recorded status', notes: 'Notes', next_review_date: 'Next review',
    assessment_date: 'Assessment date', assessor_name: 'Attributed assessor name', assessor_type: 'Recorded assessor type',
    capacity_status: 'Reported capacity', assessment_summary: 'Assessment summary', restrictions: 'Reported restrictions',
    recommendations: 'Recommendations', next_assessment_date: 'Next assessment', plan_start_date: 'Plan starts',
    plan_end_date: 'Plan ends', medical_clearance_notes: 'Recorded clearance notes', medical_clearance_date: 'Recorded clearance date',
    medical_clearance_provider: 'Recorded clearance provider', goals: 'Recovery goals', stages: 'Recovery stages',
    workplace_modifications: 'Workplace modifications', worker_agreement_notes: 'Worker agreement notes',
    worker_agreed: 'Worker agreement recorded', worker_agreed_at: 'Agreement date', review_notes: 'Review notes',
    start_date: 'Starts', end_date: 'Ends', modified_duties_description: 'Planned duties', accommodations: 'Accommodations',
    hours_per_day: 'Planned hours per day',
};
const historyFieldLabel = (key: string) => HISTORY_LABELS[key] ?? 'Recorded fact';
function historyValue(key: string, value: unknown): string {
    if (value == null || value === '') return 'Not recorded';
    if (typeof value === 'boolean') return value ? 'Yes' : 'No';
    if (key === 'injury_date' && typeof value === 'string') return injuryTime(value);
    if ((key.endsWith('_date') || key === 'worker_agreed_at') && typeof value === 'string') return injuryDay(value);
    if (Array.isArray(value)) return value.map((item) => {
        if (typeof item === 'string') return item;
        if (item && typeof item === 'object' && typeof item.name === 'string') {
            return [item.name, item.start_date && injuryDay(item.start_date), item.end_date && injuryDay(item.end_date),
                item.hours_per_week != null && `${item.hours_per_week} hours per week`, item.duties_description].filter(Boolean).join(' · ');
        }
        return 'Recorded arrangement';
    }).join('\n') || 'None recorded';
    return typeof value === 'string'
        ? (['status', 'severity', 'injury_type', 'medical_treatment_type', 'capacity_status', 'assessor_type'].includes(key) ? label(value) : value)
        : typeof value === 'number' ? String(value) : 'Recorded fact';
}
export function InjuryRecordPanel(props: PanelProps) {
    switch (props.section) {
        case 'overview': return <Overview {...props} />;
        case 'recovery': return <Recovery {...props} />;
        case 'capacity': return <Capacity {...props} />;
        case 'scheduling': return <Scheduling />;
        case 'treatment': return <Treatment {...props} />;
        case 'connections': return <Connections {...props} />;
        case 'evidence': return <Evidence {...props} />;
        case 'history': return <History {...props} />;
    }
}
