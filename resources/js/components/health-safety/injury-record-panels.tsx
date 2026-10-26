/**
 * An injury record's sections as the approved drawings draw them (pg-hsn-88,
 * 88b–88j, Stephan 23 Oct 2026): what happened as a story, recovery stages and
 * time off work on the Journey line, assessments and history as time lines,
 * evidence as a table (kebab and right-click from one action list), the
 * WorkSafe notification as its steps, and who coordinates in the side column.
 * Medical details stay behind the medical-access guard; every restricted, ACC,
 * WorkSafe and "does not confirm" line keeps the code's wording.
 */
import { FilePreviewDialog, type PreviewFile } from '@/components/files/file-preview-dialog';
import { dateWithYear } from '@/components/incidents/incident-stages';
import { RecordIdentity, RecordTable } from '@/components/incidents/record-table';
import { compactMenu } from '@/components/lists/entity-menu';
import {
    Journey,
    Log,
    Note,
    PeopleCards,
    QuietLine,
    Ring,
    RingCard,
    Story,
    Surface,
    type CheckItem,
    type JourneyStep,
    type LogItem,
    type SgAction,
    type SgTone,
    type StoryFact,
} from '@/components/signature/blocks';
import { Button } from '@/components/ui/button';
import { formatFileSize } from '@/components/ui/file-dropzone';
import { StatusBadge, type StatusVariant } from '@/components/ui/status-badge';
import { formatDateLong, formatDateTimeLong, formatTime, toDateInput } from '@/lib/datetime';
import { injuryReadLeaf } from '@/lib/health-safety/injury-read-leaf';
import { Link } from '@inertiajs/react';
import {
    AlertTriangle,
    CalendarClock,
    CalendarDays,
    Clock,
    Download,
    Eye,
    FileCheck,
    FileText,
    Hash,
    Info,
    Lock,
    MapPin,
    PersonStanding,
    Stethoscope,
    Trash2,
    User,
} from 'lucide-react';
import { useState, type ReactNode } from 'react';
import { injuryCommandAllowed, INJURY_COMMAND_LABELS } from './injury-command-state';
import { statusLabel } from './injury-constants';
import type { InjuryCommand, InjuryConnection, InjuryEvidence, InjuryHistory, InjuryRecord, InjurySection } from './injury-workspace-types';

export const injuryDay = (value: string | null | undefined) => formatDateLong(value, 'Not recorded');
export const injuryTime = (value: string | null | undefined) => formatDateTimeLong(value, 'Not recorded');
const label = (value: string | null | undefined) => value?.replaceAll('_', ' ') ?? 'Not recorded';
const sentence = (value: string | null | undefined) => {
    const text = label(value);
    return text.charAt(0).toUpperCase() + text.slice(1);
};
const shortDay = (value: string | null | undefined, fallback = 'Not recorded') => (value ? dateWithYear(value, fallback) : fallback);

type Open = (command: InjuryCommand, child?: { plan?: number; attachment?: number }) => void;

/** A block-head action for a command the viewer can run now (hidden otherwise). */
function commandAction(detail: InjuryRecord, command: InjuryCommand, open: Open, child?: { plan?: number; attachment?: number }): SgAction | null {
    if (!injuryCommandAllowed(detail, command, child)) return null;
    return { label: INJURY_COMMAND_LABELS[command], onClick: () => open(command, child) };
}
function Action({ detail, command, plan, attachment, open }: {
    detail: InjuryRecord; command: InjuryCommand; plan?: number; attachment?: number; open: Open;
}) {
    if (!injuryCommandAllowed(detail, command, { plan, attachment })) return null;
    return <Button type="button" variant="outline" size="sm" onClick={() => open(command, { plan, attachment })}>{INJURY_COMMAND_LABELS[command]}</Button>;
}
function Actions({ children }: { children: ReactNode }) {
    return <div className="flex flex-wrap gap-2 empty:hidden">{children}</div>;
}
function MedicalRestricted() {
    return <Note icon={Lock}><b>Medical details restricted.</b> Your current access covers the injury facts. Recovery assessments, medical documents and private history require separate access.</Note>;
}
function PrivateSourceState({ detail }: { detail: InjuryRecord }) {
    return detail.medical_access === 'restricted' ? <MedicalRestricted /> : <Surface>
        <div className="grid justify-items-start gap-2 px-[18px] py-4"><StatusBadge variant="warning">Source needs review</StatusBadge><p className="text-subtle">The private records are withheld while their source history is unconfirmed.</p></div>
    </Surface>;
}
function IntegrityStatus({ children }: { children: ReactNode }) {
    return <Surface tone="warning"><p role="status" className="flex items-center gap-2.5 px-[18px] py-3 text-sm"><AlertTriangle aria-hidden="true" className="size-4 shrink-0 text-status-warning" />{children}</p></Surface>;
}

type PanelProps = {
    detail: InjuryRecord;
    section: InjurySection;
    open: Open;
    sourceHref: string;
};

/* ------------------------------------------------------------------ */
/*  Overview                                                           */
/* ------------------------------------------------------------------ */

function Overview({ detail: d, open }: PanelProps) {
    const facts: StoryFact[] = [
        { icon: MapPin, text: d.site?.name ?? 'Site unavailable' },
        ...(d.injury_date ? [{ icon: Clock, text: <><b>{shortDay(d.injury_date)}</b>, {formatTime(d.injury_date)}</> }] : []),
        ...(d.body_part_affected ? [{ icon: PersonStanding, text: d.body_part_affected }] : []),
    ];
    return <>
        <Story title="What happened" action={commandAction(d, 'edit', open)} facts={facts}
            paras={[{ h: 'What happened', t: d.description || 'Not recorded' }]} />
        {d.worker?.name ? <PeopleCards title="People" cols={2} items={[{ name: d.worker.name, sub: `Injured worker · ${d.site?.name ?? 'Site unavailable'}` }]} /> : null}
        <Actions><Action detail={d} command="close" open={open} /></Actions>
    </>;
}

/* ------------------------------------------------------------------ */
/*  Recovery plan                                                      */
/* ------------------------------------------------------------------ */

const today = () => toDateInput(new Date());
function stageState(start?: string | null, end?: string | null): JourneyStep['state'] {
    const now = today();
    if (end && end < now) return 'done';
    if (start && start <= now) return 'now';
    return 'todo';
}
const range = (start?: string | null, end?: string | null) =>
    start || end ? `${start ? shortDay(start) : 'Start not recorded'} – ${end ? shortDay(end) : 'no end recorded'}` : 'Dates not recorded';

function Recovery({ detail: d, open }: PanelProps) {
    if (d.rtw_plans === null) return <PrivateSourceState detail={d} />;
    return <>
        {d.recovery_integrity === 'repair_required' && <IntegrityStatus>The worker or source history needs review before new recovery work can be saved.</IntegrityStatus>}
        {!d.rtw_plans.length && d.recovery_integrity !== 'repair_required' && <QuietLine title="Recovery plan" text="No recovery plan is recorded." action={commandAction(d, 'add_rtw', open)} />}
        {d.rtw_plans.map((plan) => {
            const steps: JourneyStep[] = plan.stages.map((stage) => ({
                label: stage.name,
                meta: [range(stage.start_date, stage.end_date), stage.hours_per_week != null ? `${stage.hours_per_week} hours a week` : null].filter(Boolean).join(' · '),
                state: stageState(stage.start_date, stage.end_date),
            }));
            const title = `Recovery plan ${plan.id} · ${label(plan.status)}`;
            const sub = [plan.manager?.name ? `Plan manager ${plan.manager.name}` : null, plan.recorder?.name ? `recorded by ${plan.recorder.name}` : null].filter(Boolean).join(' · ') || undefined;
            const paras = [
                { h: 'Recovery goals', t: plan.goals.length ? <ul className="list-disc space-y-1 pl-5">{plan.goals.map((goal, index) => <li key={index} className="break-words">{goal}</li>)}</ul> : 'Not recorded' },
                ...plan.stages.filter((stage) => stage.duties_description).map((stage) => ({ h: `Planned duties · ${stage.name}`, t: stage.duties_description! })),
                { h: 'Recorded clearance notes', t: plan.medical_clearance_notes || 'Not recorded' },
                { h: 'Workplace modifications', t: plan.workplace_modifications || 'Not recorded' },
                ...(plan.worker_agreement_notes ? [{ h: 'Agreement notes', t: plan.worker_agreement_notes }] : []),
                ...(plan.review_notes ? [{ h: 'Plan review notes', t: plan.review_notes }] : []),
            ];
            return <div key={plan.id} id={`plan-${plan.id}`} tabIndex={-1} className="grid gap-5 rounded-[14px] outline-none focus-visible:ring-2 focus-visible:ring-ring">
                {steps.length ? <Journey title={title} sub={sub} action={commandAction(d, 'edit_rtw', open, { plan: plan.id })} steps={steps} />
                    : <QuietLine title={title} sub={sub} text="No recovery stages are recorded." action={commandAction(d, 'edit_rtw', open, { plan: plan.id })} />}
                {plan.attribution_state === 'legacy_unknown' && <Note icon={Info}>Historical record. Worker and recorder attribution has not been independently confirmed.</Note>}
                <Story title="What the plan asks"
                    facts={[
                        { icon: CalendarDays, text: range(plan.plan_start_date, plan.plan_end_date) },
                        ...(plan.next_review_date ? [{ icon: CalendarClock, text: `Next plan review ${shortDay(plan.next_review_date)}` }] : []),
                        { icon: User, text: `Recorded worker: ${plan.recorded_worker?.name ?? plan.worker?.name ?? 'Not recorded'}` },
                    ]}
                    paras={paras} />
                <Log title="Modified duties" action={commandAction(d, 'add_duty', open, { plan: plan.id })} limit={plan.modified_duties.length || 1}
                    items={plan.modified_duties.length ? plan.modified_duties.map((duty) => ({
                        key: duty.id,
                        time: shortDay(duty.start_date),
                        tone: 'info' as SgTone,
                        title: <span id={`duty-${duty.id}`} tabIndex={-1} className="rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring">Modified duties {duty.id} · {label(duty.status)}</span>,
                        sub: <>
                            <span className="block">{range(duty.start_date, duty.end_date)} · {duty.hours_per_day ?? 'Not recorded'} planned hours per day · Recorded worker {duty.recorded_worker?.name ?? duty.user?.name ?? 'Not recorded'} · Recorded by {duty.recorder?.name ?? 'Not recorded'}</span>
                            <span className="block whitespace-pre-wrap">Duties: {duty.modified_duties_description || 'Not recorded'}</span>
                            <span className="block whitespace-pre-wrap">Reported restrictions: {duty.restrictions || 'Not recorded'}</span>
                            <span className="block whitespace-pre-wrap">Accommodations: {duty.accommodations || 'Not recorded'}</span>
                            {duty.notes ? <span className="block whitespace-pre-wrap">Notes: {duty.notes}</span> : null}
                            <span className="mt-1 inline-flex"><StatusBadge variant="neutral">Approval not confirmed</StatusBadge></span>
                        </>,
                    })) : [{ key: 'none', tone: 'none' as SgTone, title: 'No modified duties are recorded.' }]} />
            </div>;
        })}
        {d.rtw_plans.length > 0 && <Note icon={Info}>These are recorded recovery arrangements. Work restrictions and scheduling decisions require their own approval.</Note>}
        <Actions>
            {d.rtw_plans.length > 0 && <Action detail={d} command="add_rtw" open={open} />}
            <Action detail={d} command="begin_rtw" open={open} /><Action detail={d} command="mark_recovered" open={open} />
        </Actions>
    </>;
}

/** The latest plan's recorded clearance and worker agreement, read as a ring. */
function ClearanceRing({ detail: d }: { detail: InjuryRecord }) {
    const plan = d.rtw_plans?.[0];
    if (!plan) return null;
    const checks: CheckItem[] = [
        { label: `Recorded clearance${plan.medical_clearance_provider ? `: ${plan.medical_clearance_provider}` : ''}`, state: plan.medical_clearance_date ? 'success' : 'none', note: shortDay(plan.medical_clearance_date) },
        { label: 'Worker agreement recorded', state: plan.worker_agreed ? 'success' : 'none', note: plan.worker_agreed ? shortDay(plan.worker_agreed_at, 'Yes') : 'No' },
    ];
    return <RingCard value={checks.filter((check) => check.state === 'success').length} of={checks.length}
        label="Recorded clearance and agreement" sub={`Recovery plan ${plan.id}`} checks={checks} />;
}

/* ------------------------------------------------------------------ */
/*  Assessments                                                        */
/* ------------------------------------------------------------------ */

function Capacity({ detail: d, open }: PanelProps) {
    if (d.capacity_assessments === null) return <PrivateSourceState detail={d} />;
    const next = d.capacity_assessments.map((item) => item.next_assessment_date).filter((value): value is string => !!value).sort()[0] ?? null;
    const items: LogItem[] = [
        ...(next && next >= today() ? [{ key: 'next', time: shortDay(next), tone: 'info' as SgTone, title: 'Next assessment' }] : []),
        ...d.capacity_assessments.map((item) => ({
            key: item.id,
            time: shortDay(item.assessment_date),
            tone: (item.capacity_status === 'unfit_for_work' ? 'warning' : item.capacity_status === 'requires_review' ? 'warning' : 'success') as SgTone,
            title: `Reported capacity: ${label(item.capacity_status)}`,
            sub: [item.assessor?.name ?? item.assessor_name ?? 'Assessor not recorded', label(item.assessor_type), item.recorder?.name ? `recorded by ${item.recorder.name}` : null].filter(Boolean).join(' · '),
        })),
    ];
    return <>
        {d.recovery_integrity === 'repair_required' && <IntegrityStatus>Some recovery sources need repair. Unconfirmed assessments are withheld.</IntegrityStatus>}
        {!d.capacity_assessments.length && d.recovery_integrity !== 'repair_required'
            ? <QuietLine title="Capacity assessments" text="No capacity assessment is recorded." action={commandAction(d, 'add_capacity', open)} />
            : <Log title="Capacity assessments" action={commandAction(d, 'add_capacity', open)} items={items} limit={items.length} />}
        {d.capacity_assessments.map((assessment) => <div key={assessment.id} id={`assessment-${assessment.id}`} tabIndex={-1} className="grid gap-5 rounded-[14px] outline-none focus-visible:ring-2 focus-visible:ring-ring">
            {assessment.attribution_state === 'legacy_unknown' && <Note tone="warning" icon={AlertTriangle}>The old person field has unclear attribution. It has not been relabelled as the worker or assessor.</Note>}
            <Story title={`What the assessment found · ${shortDay(assessment.assessment_date)}`}
                sub={[assessment.assessor?.name ?? assessment.assessor_name, assessment.assessor_type ? label(assessment.assessor_type) : null].filter(Boolean).join(' · ') || undefined}
                facts={[
                    { icon: User, text: `Injured worker: ${assessment.recorded_worker?.name ?? assessment.worker?.name ?? 'Not recorded'}` },
                    { icon: CalendarClock, text: `Next assessment ${shortDay(assessment.next_assessment_date)}` },
                ]}
                paras={[
                    { h: 'Assessment summary', t: assessment.assessment_summary || 'Not recorded' },
                    { h: 'Reported restrictions', t: assessment.restrictions || 'Not recorded' },
                    { h: 'Recommendations', t: assessment.recommendations || 'Not recorded' },
                    ...(assessment.hr_document.access === 'readable' && assessment.hr_document.url
                        ? [{ h: 'Linked HR evidence', t: <a className="font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none" href={assessment.hr_document.url}>Open {assessment.hr_document.title ?? 'linked HR evidence'}</a> }]
                        : assessment.hr_document.access === 'restricted' ? [{ h: 'Linked HR evidence', t: 'Linked HR evidence requires separate access.' }] : []),
                ]} />
        </div>)}
        {d.capacity_assessments.length > 0 && <Note icon={Info}>A recorded assessment does not establish verified medical certification or approved work restrictions.</Note>}
    </>;
}

/* ------------------------------------------------------------------ */
/*  Scheduling                                                         */
/* ------------------------------------------------------------------ */

function Scheduling() {
    return <>
        <QuietLine title="Scheduling impact" text={<><b>Scheduling review unavailable.</b> The effect on current and future duties has not been confirmed.</>} />
        <Note icon={Info}>Approved restrictions, the duties assessed and the responsible scheduling coordinator will appear here when their recorded review is available. An injury or recovery status does not change a shift or confirm fitness to work.</Note>
    </>;
}

/* ------------------------------------------------------------------ */
/*  Treatment and claim                                                */
/* ------------------------------------------------------------------ */

function Treatment({ detail: d, open }: PanelProps) {
    const medical = d.medical_access === 'readable';
    return <>
        <Journey title="Time off work" steps={[
            { label: 'Lost time recorded', meta: `${d.lost_time_days} days`, state: 'done' },
            { label: 'Expected return', meta: shortDay(d.expected_return_date), state: d.actual_return_date ? 'done' : 'now' },
            { label: 'Actual return recorded', meta: shortDay(d.actual_return_date), state: d.actual_return_date ? 'done' : 'todo' },
        ]} />
        <Story title="Treatment and claim facts" action={commandAction(d, 'acc', open)}
            facts={[
                ...(medical ? [{ icon: Stethoscope, text: d.medical_treatment_label ?? 'Treatment level not recorded' }] : []),
                { icon: FileCheck, text: <>Claim lodged internally recorded: <b>{d.claim_delivery.internally_recorded ? 'Yes' : 'No'}</b></> },
                ...(medical && d.acc_claim_number ? [{ icon: Hash, text: d.acc_claim_number }] : []),
            ]}
            paras={medical ? [
                { h: 'Immediate treatment', t: d.immediate_treatment || 'Not recorded' },
                { h: 'Private notes', t: d.notes || 'Not recorded' },
            ] : []} />
        {!medical && <MedicalRestricted />}
        <Note icon={Info}><b>External claim receipt unconfirmed.</b> A claim fact recorded here does not confirm that ACC received or acknowledged it.</Note>
        <Actions><Action detail={d} command="start_treatment" open={open} /></Actions>
    </>;
}

/* ------------------------------------------------------------------ */
/*  Connected work                                                     */
/* ------------------------------------------------------------------ */

function connectionVariant(status: string | null): StatusVariant {
    if (!status) return 'neutral';
    if (['closed', 'resolved', 'completed'].includes(status)) return 'neutral';
    if (['open', 'draft', 'pending'].includes(status)) return 'warning';
    return 'info';
}
function Connection({ title, record, sourceHref, icon: Icon }: { title: string; record: InjuryConnection; sourceHref: string; icon: typeof FileText }) {
    const source = new URL(sourceHref, 'https://injury.invalid'); source.searchParams.delete('return_to');
    const back = injuryReadLeaf(source.pathname + source.search);
    const href = record.access === 'readable' && record.url && back ? record.url + (record.url.includes('?') ? '&' : '?') + 'return_to=' + encodeURIComponent(back) : null;
    return <li><div className="sg-li">
        <span className="sg-li-lead"><Icon className="size-4" /></span>
        <span className="sg-li-t"><b>{title}</b>
            <small className="break-words">{record.access === 'readable' ? [record.reference, record.title].filter(Boolean).join(' · ') || 'Connected record'
                : record.access === 'absent' ? 'No connected record'
                : record.access === 'restricted' ? 'Separate access required' : 'Connection needs review'}</small></span>
        {record.access === 'readable' && record.status ? <StatusBadge variant={connectionVariant(record.status)}>{sentence(record.status)}</StatusBadge> : null}
        {href && <Link href={href} className="sg-act ml-2">Open {title.toLowerCase()}</Link>}
    </div></li>;
}
function Connections({ detail: d, sourceHref }: PanelProps) {
    const worksafe = d.connections.worksafe;
    return <>
        <Surface title="Connected safety work">
            <ul className="sg-list mt-2">
                <Connection title="Incident" icon={AlertTriangle} record={d.connections.incident} sourceHref={sourceHref} />
                <Connection title="Safety review" icon={FileCheck} record={d.connections.health_safety} sourceHref={sourceHref} />
                <Connection title="Control Room response" icon={Eye} record={d.connections.control_room} sourceHref={sourceHref} />
            </ul>
        </Surface>
        {worksafe.access === 'readable'
            ? <Journey title="WorkSafe notification" sub={`Recorded notification state: ${label(worksafe.notification_state)}`} steps={[
                { label: 'Decision recorded', meta: worksafe.decision_state === 'recorded' ? 'Yes' : 'Not confirmed', state: worksafe.decision_state === 'recorded' ? 'done' : 'now' },
                { label: 'Recorded notification time', meta: injuryTime(worksafe.notified_at), state: worksafe.notified_at ? 'done' : worksafe.decision_state === 'recorded' ? 'now' : 'todo' },
                { label: 'Recorded acknowledgement', meta: injuryTime(worksafe.acknowledged_at), state: worksafe.acknowledged_at ? 'done' : worksafe.notified_at ? 'now' : 'todo' },
                { label: 'Recorded reference', meta: worksafe.reference ?? 'Not recorded', state: worksafe.reference ? 'done' : 'todo' },
            ]} />
            : <QuietLine title="WorkSafe notification" text={worksafe.access === 'restricted' ? 'Notification details require separate safety-review access.' : 'Notification details are not confirmed.'} />}
        <Note tone="warning" icon={AlertTriangle}>Recording an injury does not complete a notification, operational response or safety review.</Note>
    </>;
}

/* ------------------------------------------------------------------ */
/*  Evidence                                                           */
/* ------------------------------------------------------------------ */

const KIND_LABELS: Record<string, string> = { medical_cert: 'Medical certificate', acc_form: 'ACC form', rtw_clearance: 'Return-to-work clearance source', photo: 'Photo', document: 'Document' };
function Evidence({ detail: d, open }: PanelProps) {
    const [preview, setPreview] = useState<PreviewFile | null>(null);
    if (d.attachments === null) return <PrivateSourceState detail={d} />;
    const available = (file: InjuryEvidence) => file.availability === 'available' && !!file.url;
    const add = injuryCommandAllowed(d, 'upload_evidence') ? <Button type="button" variant="outline" size="sm" onClick={() => open('upload_evidence')}>{INJURY_COMMAND_LABELS.upload_evidence}</Button> : null;
    return <>
        {d.evidence_integrity === 'repair_required' && <IntegrityStatus>Some evidence needs review. Its contents and download are unavailable here.</IntegrityStatus>}
        {!d.attachments.length && d.evidence_integrity !== 'repair_required'
            ? <QuietLine title="Evidence" text="No evidence is recorded." action={commandAction(d, 'upload_evidence', open)} />
            : d.attachments.length ? <RecordTable title="Evidence" sub="Preview, download or remove from the row menu" actions={add} ariaLabel="Evidence"
                rows={d.attachments} rowKey={(file) => file.id} menuTitle={(file) => file.original_name} menuIcon={FileText}
                actionsFor={(file) => compactMenu([
                    available(file) && (file.is_image || file.mime === 'application/pdf') && { label: `Preview ${file.original_name}`, icon: Eye, onClick: () => setPreview({
                        id: file.id, version: file.source_version_id ?? undefined, name: file.original_name, mime: file.mime, bytes: file.size, source: d.reference,
                        previewUrl: file.url, downloadUrl: file.url }) },
                    available(file) && { label: `Download ${file.original_name}`, icon: Download, onClick: () => { window.location.assign(file.url!); } },
                    injuryCommandAllowed(d, 'remove_evidence', { attachment: file.id }) && { separator: true },
                    injuryCommandAllowed(d, 'remove_evidence', { attachment: file.id }) && { label: INJURY_COMMAND_LABELS.remove_evidence, icon: Trash2, danger: true, onClick: () => open('remove_evidence', { plan: undefined, attachment: file.id }) },
                ])}
                columns={[
                    { key: 'file', label: 'File', className: 'min-w-[16rem] max-w-[26rem]', cell: (file) => <RecordIdentity
                        title={available(file) ? <a href={file.url!} onClick={(event) => event.stopPropagation()} className="rounded-sm break-words text-foreground underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none">{file.original_name}</a> : <span className="break-words">{file.original_name}</span>}
                        sub={<>
                            <span className="block">{file.kind ? (KIND_LABELS[file.kind] ?? sentence(file.kind)) : 'Type not recorded'}</span>
                            {file.notes ? <span className="block whitespace-pre-wrap">{file.notes}</span> : null}
                            {!available(file) ? <span className="block">File unavailable. The retained record does not confirm retrievable bytes.</span> : null}
                        </>} /> },
                    { key: 'size', label: 'Size', nowrap: true, cell: (file) => file.size == null ? 'Size not recorded' : formatFileSize(file.size) },
                    { key: 'uploaded', label: 'Uploaded', nowrap: true, cell: (file) => file.created_at ? <RecordIdentity title={shortDay(file.created_at)} sub={formatTime(file.created_at)} /> : <span className="text-muted-foreground">Not recorded</span> },
                    { key: 'by', label: 'Uploaded by', nowrap: true, cell: (file) => file.uploaded_by ?? <span className="text-muted-foreground">Not recorded</span> },
                ]} /> : null}
        <FilePreviewDialog file={preview} onClose={() => setPreview(null)} />
    </>;
}

/* ------------------------------------------------------------------ */
/*  History                                                            */
/* ------------------------------------------------------------------ */

function History({ detail: d }: PanelProps) {
    if (d.history === null) return <PrivateSourceState detail={d} />;
    const entries = [...d.history].reverse();
    return <>
        {d.history_integrity === 'repair_required' && <IntegrityStatus>Some retained source history needs review. It is not presented as verified history.</IntegrityStatus>}
        {!d.history.length && d.history_integrity !== 'repair_required' && <QuietLine title="History" text="No attributed source version is recorded. Earlier records retain their original audit history." />}
        {d.history.length > 0 && <Surface title="History" sub="Open a version to see what changed · newest first">
            <ol className="sg-log">{entries.map((entry) => <li key={entry.source_revision} className="sg-lr">
                <time>{shortDay(entry.recorded_at)}</time>
                <Ring tone={entry.command.includes('transition') || entry.command === 'create' ? 'success' : 'info'} />
                <div className="min-w-0">
                    <b>Version {entry.source_revision} · {historyTitle(entry)}</b>
                    <small>{entry.recorded_at ? `${formatTime(entry.recorded_at)} · ` : ''}{entry.recorder?.name ?? 'Recorder not confirmed'}</small>
                    {entry.attribution_state === 'legacy_unknown' && <span className="mt-1 inline-flex"><StatusBadge variant="neutral">Historical attribution unknown</StatusBadge></span>}
                    {entry.access === 'restricted' && <small>Some history belongs to a Site outside your current access. Restricted facts and the recorder are withheld.</small>}
                    <details className="mt-1"><summary className="inline-flex cursor-pointer items-center rounded-sm text-sm font-medium text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring">{entry.before_access === 'restricted' || entry.after_access === 'restricted' ? 'Source version facts' : 'Recorded changes'}</summary>
                        <dl className="mt-3 space-y-3">{[...new Set([...Object.keys(entry.before ?? {}), ...Object.keys(entry.after ?? {})])]
                            .filter((key) => !key.endsWith('_id') && (entry.before_access === 'restricted' || entry.after_access === 'restricted' || JSON.stringify(entry.before?.[key]) !== JSON.stringify(entry.after?.[key])))
                            .map((key) => <div key={key} className="min-w-0 space-y-1"><dt className="text-subtle">{historyFieldLabel(key)}</dt><dd className="text-sm break-words whitespace-pre-wrap">
                                <span className="block text-subtle">Before: {entry.before_access === 'restricted' ? 'Restricted' : historyValue(key, entry.before?.[key])}</span>
                                <span className="block">Recorded: {entry.after_access === 'restricted' ? 'Restricted' : historyValue(key, entry.after?.[key])}</span>
                            </dd></div>)}</dl></details>
                </div>
                <span />
            </li>)}</ol>
        </Surface>}
        {!!d.audits?.length && <Surface title="Earlier audit entries"><details className="px-[18px] pt-1 pb-4"><summary className="inline-flex cursor-pointer items-center rounded-sm text-sm font-medium text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring">Earlier audit entries</summary>
            <ol className="mt-3 space-y-2">{d.audits.map((entry) => <li key={entry.id} className="text-sm">{injuryTime(entry.at)} · {entry.actor ?? 'Recorder not confirmed'} · {label(entry.action)}</li>)}</ol></details></Surface>}
    </>;
}
/** What a source version recorded, in plain words; a readable status change names both stages. */
const HISTORY_COMMANDS: Record<string, string> = {
    create: 'Injury reported', update: 'Injury facts updated', assign: 'Owner assigned', review: 'Recovery review recorded',
    transition: 'Status changed', record_capacity: 'Capacity assessed', create_rtw_plan: 'Return-to-work plan added',
    update_rtw_plan: 'Return-to-work plan updated', add_modified_duty: 'Modified duties added',
    upload_evidence: 'Evidence added', remove_evidence: 'Evidence removed',
};
function historyTitle(entry: InjuryHistory): string {
    const before = entry.before_access === 'readable' ? entry.before?.status : undefined;
    const after = entry.after_access === 'readable' ? entry.after?.status : undefined;
    if (entry.command === 'transition' && typeof before === 'string' && typeof after === 'string' && before !== after)
        return `Status changed: ${statusLabel(before)} → ${statusLabel(after)}`;
    return HISTORY_COMMANDS[entry.command] ?? sentence(entry.command);
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

/** The main column for one section. */
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

/** The side column: who coordinates (on every section), and the clearance ring beside the recovery plan. */
export function InjuryRecordSide({ detail: d, section, open }: { detail: InjuryRecord; section: InjurySection; open: Open }) {
    const coordinator = d.coordination.coordinator;
    return <>
        {section === 'recovery' && d.rtw_plans !== null ? <ClearanceRing detail={d} /> : null}
        {coordinator
            ? <PeopleCards title="Who’s coordinating" action={commandAction(d, 'review', open)} cols={1} items={[{
                name: coordinator.name,
                sub: 'Recovery coordinator',
                foot: 'Recovery coordination, operational response and safety review each retain their own owner and outcome.',
            }]} />
            : <QuietLine title="Who’s coordinating" text={`Recovery coordinator: ${d.coordination.attribution_state === 'unassigned' ? 'Unassigned' : 'Assignment needs review'}`} />}
    </>;
}

