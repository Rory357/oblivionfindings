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
import { humaniseHsValue } from '@/lib/health-safety/hs-labels';
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
import { ASSESSOR_TYPE_LABELS, CAPACITY_STATUS_LABELS, injuryCommandAllowed, INJURY_COMMAND_LABELS, injuryValueLabel, RTW_PLAN_STATUS_LABELS } from './injury-command-state';
import { statusLabel } from './injury-constants';
import type { InjuryCommand, InjuryConnection, InjuryEvidence, InjuryHistory, InjuryRecord, InjurySection } from './injury-workspace-types';

export const injuryDay = (value: string | null | undefined) => formatDateLong(value, 'Not recorded');
export const injuryTime = (value: string | null | undefined) => formatDateTimeLong(value, 'Not recorded');
/** A stored value in plain words: the map's label, else the value made readable; missing reads "Not recorded". */
const label = (value: string | null | undefined, map: Record<string, string> = {}) => value ? (map[value] ?? humaniseHsValue(value)) : 'Not recorded';
const WORKSAFE_STATE_LABELS: Record<string, string> = { pending: 'Waiting to tell WorkSafe', notified: 'WorkSafe told', acknowledged: 'WorkSafe replied' };
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
    return <Note icon={Lock}><b>Medical details are private.</b> You can see what happened, but not recovery checks, medical documents or private history. Ask a health and safety manager if you need them.</Note>;
}
function PrivateSourceState({ detail }: { detail: InjuryRecord }) {
    return detail.medical_access === 'restricted' ? <MedicalRestricted /> : <Surface>
        <div className="grid justify-items-start gap-2 px-[18px] py-4"><StatusBadge variant="critical">Needs fixing — ask an administrator</StatusBadge><p className="text-subtle">These private records are hidden until an administrator fixes their history.</p></div>
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
        { icon: MapPin, text: d.site?.name ?? 'Site hidden' },
        ...(d.injury_date ? [{ icon: Clock, text: <><b>{shortDay(d.injury_date)}</b>, {formatTime(d.injury_date)}</> }] : []),
        ...(d.body_part_affected ? [{ icon: PersonStanding, text: d.body_part_affected }] : []),
    ];
    return <>
        <Story title="What happened" action={commandAction(d, 'edit', open)} facts={facts}
            paras={[{ h: 'What happened', t: d.description || 'Not recorded' }]} />
        {d.worker?.name ? <PeopleCards title="People" cols={2} items={[{ name: d.worker.name, sub: `Injured worker · ${d.site?.name ?? 'Site hidden'}` }]} /> : null}
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
        {d.recovery_integrity === 'repair_required' && <IntegrityStatus>This injury’s history needs fixing before anyone can add recovery work. Ask an administrator.</IntegrityStatus>}
        {!d.rtw_plans.length && d.recovery_integrity !== 'repair_required' && <QuietLine title="Recovery plan" text="No recovery plan is recorded." action={commandAction(d, 'add_rtw', open)} />}
        {d.rtw_plans.map((plan) => {
            const steps: JourneyStep[] = plan.stages.map((stage) => ({
                label: stage.name,
                meta: [range(stage.start_date, stage.end_date), stage.hours_per_week != null ? `${stage.hours_per_week} hours a week` : null].filter(Boolean).join(' · '),
                state: stageState(stage.start_date, stage.end_date),
            }));
            const title = `Recovery plan from ${shortDay(plan.plan_start_date, 'an unknown date')} · ${label(plan.status, RTW_PLAN_STATUS_LABELS)}`;
            const sub = [plan.manager?.name ? `Plan manager ${plan.manager.name}` : null, plan.recorder?.name ? `recorded by ${plan.recorder.name}` : null].filter(Boolean).join(' · ') || undefined;
            const paras = [
                { h: 'Recovery goals', t: plan.goals.length ? <ul className="list-disc space-y-1 pl-5">{plan.goals.map((goal, index) => <li key={index} className="break-words">{goal}</li>)}</ul> : 'Not recorded' },
                ...plan.stages.filter((stage) => stage.duties_description).map((stage) => ({ h: `Planned duties · ${stage.name}`, t: stage.duties_description! })),
                { h: 'Clearance notes', t: plan.medical_clearance_notes || 'Not recorded' },
                { h: 'Changes made at work', t: plan.workplace_modifications || 'Not recorded' },
                ...(plan.worker_agreement_notes ? [{ h: 'Agreement notes', t: plan.worker_agreement_notes }] : []),
                ...(plan.review_notes ? [{ h: 'Plan review notes', t: plan.review_notes }] : []),
            ];
            return <div key={plan.id} id={`plan-${plan.id}`} tabIndex={-1} className="grid gap-5 rounded-[14px] outline-none focus-visible:ring-2 focus-visible:ring-ring">
                {steps.length ? <Journey title={title} sub={sub} action={commandAction(d, 'edit_rtw', open, { plan: plan.id })} steps={steps} />
                    : <QuietLine title={title} sub={sub} text="No recovery stages are recorded." action={commandAction(d, 'edit_rtw', open, { plan: plan.id })} />}
                {plan.attribution_state === 'legacy_unknown' && <Note icon={Info}>Older record — we can’t be sure who it was for or who wrote it.</Note>}
                <Story title="What the plan asks"
                    facts={[
                        { icon: CalendarDays, text: range(plan.plan_start_date, plan.plan_end_date) },
                        ...(plan.next_review_date ? [{ icon: CalendarClock, text: `Next plan review ${shortDay(plan.next_review_date)}` }] : []),
                        { icon: User, text: `Worker: ${plan.recorded_worker?.name ?? plan.worker?.name ?? 'Not recorded'}` },
                    ]}
                    paras={paras} />
                <Log title="Modified duties" action={commandAction(d, 'add_duty', open, { plan: plan.id })} limit={plan.modified_duties.length || 1}
                    items={plan.modified_duties.length ? plan.modified_duties.map((duty) => ({
                        key: duty.id,
                        time: shortDay(duty.start_date),
                        tone: 'info' as SgTone,
                        title: <span id={`duty-${duty.id}`} tabIndex={-1} className="rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring">Modified duties from {shortDay(duty.start_date, 'an unknown date')} · {label(duty.status)}</span>,
                        sub: <>
                            <span className="block">{range(duty.start_date, duty.end_date)} · {duty.hours_per_day ?? 'Not recorded'} hours a day · Worker {duty.recorded_worker?.name ?? duty.user?.name ?? 'Not recorded'} · Recorded by {duty.recorder?.name ?? 'Not recorded'}</span>
                            <span className="block whitespace-pre-wrap">Duties: {duty.modified_duties_description || 'Not recorded'}</span>
                            <span className="block whitespace-pre-wrap">Restrictions: {duty.restrictions || 'Not recorded'}</span>
                            <span className="block whitespace-pre-wrap">Changes made at work: {duty.accommodations || 'Not recorded'}</span>
                            {duty.notes ? <span className="block whitespace-pre-wrap">Notes: {duty.notes}</span> : null}
                            <span className="mt-1 inline-flex"><StatusBadge variant="neutral">Approval not recorded</StatusBadge></span>
                        </>,
                    })) : [{ key: 'none', tone: 'none' as SgTone, title: 'No modified duties are recorded.' }]} />
            </div>;
        })}
        {d.rtw_plans.length > 0 && <Note icon={Info}>These are the recovery arrangements as recorded. Limits on work and roster changes need their own approval.</Note>}
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
        { label: `Cleared to work${plan.medical_clearance_provider ? ` by ${plan.medical_clearance_provider}` : ''}`, state: plan.medical_clearance_date ? 'success' : 'none', note: shortDay(plan.medical_clearance_date) },
        { label: 'Worker agreed to the plan', state: plan.worker_agreed ? 'success' : 'none', note: plan.worker_agreed ? shortDay(plan.worker_agreed_at, 'Yes') : 'No' },
    ];
    return <RingCard value={checks.filter((check) => check.state === 'success').length} of={checks.length}
        label="Clearance and worker agreement" sub="Latest recovery plan" checks={checks} />;
}

/* ------------------------------------------------------------------ */
/*  Assessments                                                        */
/* ------------------------------------------------------------------ */

function Capacity({ detail: d, open }: PanelProps) {
    if (d.capacity_assessments === null) return <PrivateSourceState detail={d} />;
    const next = d.capacity_assessments.map((item) => item.next_assessment_date).filter((value): value is string => !!value).sort()[0] ?? null;
    const items: LogItem[] = [
        ...(next && next >= today() ? [{ key: 'next', time: shortDay(next), tone: 'info' as SgTone, title: 'Next check due' }] : []),
        ...d.capacity_assessments.map((item) => ({
            key: item.id,
            time: shortDay(item.assessment_date),
            tone: (item.capacity_status === 'unfit_for_work' ? 'warning' : item.capacity_status === 'requires_review' ? 'warning' : 'success') as SgTone,
            title: `Result: ${label(item.capacity_status, CAPACITY_STATUS_LABELS)}`,
            sub: [item.assessor?.name ?? item.assessor_name ?? 'Assessor not recorded', label(item.assessor_type, ASSESSOR_TYPE_LABELS), item.recorder?.name ? `recorded by ${item.recorder.name}` : null].filter(Boolean).join(' · '),
        })),
    ];
    return <>
        {d.recovery_integrity === 'repair_required' && <IntegrityStatus>Some checks need fixing, so they’re hidden. Ask an administrator.</IntegrityStatus>}
        {!d.capacity_assessments.length && d.recovery_integrity !== 'repair_required'
            ? <QuietLine title="Fitness-for-work checks" text="No fitness-for-work checks yet." action={commandAction(d, 'add_capacity', open)} />
            : <Log title="Fitness-for-work checks" action={commandAction(d, 'add_capacity', open)} items={items} limit={items.length} />}
        {d.capacity_assessments.map((assessment) => <div key={assessment.id} id={`assessment-${assessment.id}`} tabIndex={-1} className="grid gap-5 rounded-[14px] outline-none focus-visible:ring-2 focus-visible:ring-ring">
            {assessment.attribution_state === 'legacy_unknown' && <Note tone="warning" icon={AlertTriangle}>Older record — it’s not clear whether the name on it is the worker or the assessor, so we haven’t guessed.</Note>}
            <Story title={`What the assessment found · ${shortDay(assessment.assessment_date)}`}
                sub={[assessment.assessor?.name ?? assessment.assessor_name, assessment.assessor_type ? label(assessment.assessor_type, ASSESSOR_TYPE_LABELS) : null].filter(Boolean).join(' · ') || undefined}
                facts={[
                    { icon: User, text: `Injured worker: ${assessment.recorded_worker?.name ?? assessment.worker?.name ?? 'Not recorded'}` },
                    { icon: CalendarClock, text: `Next assessment ${shortDay(assessment.next_assessment_date)}` },
                ]}
                paras={[
                    { h: 'Assessment summary', t: assessment.assessment_summary || 'Not recorded' },
                    { h: 'Restrictions', t: assessment.restrictions || 'Not recorded' },
                    { h: 'Recommendations', t: assessment.recommendations || 'Not recorded' },
                    ...(assessment.hr_document.access === 'readable' && assessment.hr_document.url
                        ? [{ h: 'Linked HR document', t: <a className="font-medium text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none" href={assessment.hr_document.url}>Open {assessment.hr_document.title ?? 'linked HR document'}</a> }]
                        : assessment.hr_document.access === 'restricted' ? [{ h: 'Linked HR document', t: 'You need HR access to open this document. Ask HR if you need it.' }] : []),
                ]} />
        </div>)}
        {d.capacity_assessments.length > 0 && <Note icon={Info}>This notes what the assessor said. It isn’t a checked medical certificate and doesn’t approve any limits on work.</Note>}
    </>;
}

/* ------------------------------------------------------------------ */
/*  Scheduling                                                         */
/* ------------------------------------------------------------------ */

function Scheduling() {
    return <>
        <QuietLine title="Effect on shifts" text={<><b>Roster check not done yet.</b> Nobody has confirmed how this affects current and future shifts.</>} />
        <Note icon={Info}>Once the rostering team reviews this injury, the approved limits, the duties checked and who is handling it will show here. Recording an injury or its recovery doesn’t change anyone’s shifts or confirm they’re fit to work.</Note>
    </>;
}

/* ------------------------------------------------------------------ */
/*  Treatment and claim                                                */
/* ------------------------------------------------------------------ */

function Treatment({ detail: d, open }: PanelProps) {
    const medical = d.medical_access === 'readable';
    return <>
        <Journey title="Time off work" steps={[
            { label: 'Days off work', meta: `${d.lost_time_days} ${d.lost_time_days === 1 ? 'day' : 'days'}`, state: 'done' },
            { label: 'Expected return', meta: shortDay(d.expected_return_date), state: d.actual_return_date ? 'done' : 'now' },
            { label: 'Back at work', meta: shortDay(d.actual_return_date), state: d.actual_return_date ? 'done' : 'todo' },
        ]} />
        <Story title="Treatment and ACC claim" action={commandAction(d, 'acc', open)}
            facts={[
                ...(medical ? [{ icon: Stethoscope, text: d.medical_treatment_label ?? 'Treatment level not recorded' }] : []),
                { icon: FileCheck, text: <>ACC claim lodged (noted here): <b>{d.claim_delivery.internally_recorded ? 'Yes' : 'No'}</b></> },
                ...(medical && d.acc_claim_number ? [{ icon: Hash, text: d.acc_claim_number }] : []),
            ]}
            paras={medical ? [
                { h: 'Immediate treatment', t: d.immediate_treatment || 'Not recorded' },
                { h: 'Private notes', t: d.notes || 'Not recorded' },
            ] : []} />
        {!medical && <MedicalRestricted />}
        <Note icon={Info}><b>We don’t know whether ACC has this claim.</b> Noting it here doesn’t send anything to ACC or show that ACC received or accepted it.</Note>
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
            <small className="break-words">{record.access === 'readable' ? [record.reference, record.title].filter(Boolean).join(' · ') || 'Linked record'
                : record.access === 'absent' ? 'Not linked'
                : record.access === 'restricted' ? 'Hidden — you don’t have access' : 'This link needs checking'}</small></span>
        {record.access === 'readable' && record.status ? <StatusBadge variant={connectionVariant(record.status)}>{label(record.status)}</StatusBadge> : null}
        {href && <Link href={href} className="sg-act ml-2">Open {title.toLowerCase()}</Link>}
    </div></li>;
}
function Connections({ detail: d, sourceHref }: PanelProps) {
    const worksafe = d.connections.worksafe;
    return <>
        <Surface title="Linked safety records">
            <ul className="sg-list mt-2">
                <Connection title="Incident" icon={AlertTriangle} record={d.connections.incident} sourceHref={sourceHref} />
                <Connection title="Safety review" icon={FileCheck} record={d.connections.health_safety} sourceHref={sourceHref} />
                <Connection title="Control room response" icon={Eye} record={d.connections.control_room} sourceHref={sourceHref} />
            </ul>
        </Surface>
        {worksafe.access === 'readable'
            ? <Journey title="WorkSafe notification" sub={`WorkSafe notification: ${label(worksafe.notification_state, WORKSAFE_STATE_LABELS)}`} steps={[
                { label: 'Decision made', meta: worksafe.decision_state === 'recorded' ? 'Yes' : 'Not yet', state: worksafe.decision_state === 'recorded' ? 'done' : 'now' },
                { label: 'When WorkSafe was told', meta: injuryTime(worksafe.notified_at), state: worksafe.notified_at ? 'done' : worksafe.decision_state === 'recorded' ? 'now' : 'todo' },
                { label: 'When WorkSafe replied', meta: injuryTime(worksafe.acknowledged_at), state: worksafe.acknowledged_at ? 'done' : worksafe.notified_at ? 'now' : 'todo' },
                { label: 'WorkSafe reference', meta: worksafe.reference ?? 'Not recorded', state: worksafe.reference ? 'done' : 'todo' },
            ]} />
            : <QuietLine title="WorkSafe notification" text={worksafe.access === 'restricted' ? 'Hidden — you need safety review access to see this. Ask a health and safety manager.' : 'Not available — no WorkSafe decision can be shown here yet.'} />}
        <Note tone="warning" icon={AlertTriangle}>Recording an injury doesn’t tell WorkSafe, alert the control room or complete the safety review. Do those in the safety review.</Note>
    </>;
}

/* ------------------------------------------------------------------ */
/*  Evidence                                                           */
/* ------------------------------------------------------------------ */

const KIND_LABELS: Record<string, string> = { medical_cert: 'Medical certificate', acc_form: 'ACC form', rtw_clearance: 'Return-to-work clearance', photo: 'Photo', document: 'Document' };
function Evidence({ detail: d, open }: PanelProps) {
    const [preview, setPreview] = useState<PreviewFile | null>(null);
    if (d.attachments === null) return <PrivateSourceState detail={d} />;
    const available = (file: InjuryEvidence) => file.availability === 'available' && !!file.url;
    const add = injuryCommandAllowed(d, 'upload_evidence') ? <Button type="button" variant="outline" size="sm" onClick={() => open('upload_evidence')}>{INJURY_COMMAND_LABELS.upload_evidence}</Button> : null;
    return <>
        {d.evidence_integrity === 'repair_required' && <IntegrityStatus>Some files need fixing and can’t be opened here. Ask an administrator.</IntegrityStatus>}
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
                            <span className="block">{file.kind ? label(file.kind, KIND_LABELS) : 'Type not recorded'}</span>
                            {file.notes ? <span className="block whitespace-pre-wrap">{file.notes}</span> : null}
                            {!available(file) ? <span className="block">File not available — it can’t be opened.</span> : null}
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
        {d.history_integrity === 'repair_required' && <IntegrityStatus>Some history needs fixing, so treat it with care. Ask an administrator.</IntegrityStatus>}
        {!d.history.length && d.history_integrity !== 'repair_required' && <QuietLine title="History" text="No changes recorded yet. Older records keep their original history." />}
        {d.history.length > 0 && <Surface title="History" sub="Open a version to see what changed · newest first">
            <ol className="sg-log">{entries.map((entry) => <li key={entry.source_revision} className="sg-lr">
                <time>{shortDay(entry.recorded_at)}</time>
                <Ring tone={entry.command.includes('transition') || entry.command === 'create' ? 'success' : 'info'} />
                <div className="min-w-0">
                    <b>Version {entry.source_revision} · {historyTitle(entry)}</b>
                    <small>{entry.recorded_at ? `${formatTime(entry.recorded_at)} · ` : ''}{entry.recorder?.name ?? 'Unknown person'}</small>
                    {entry.attribution_state === 'legacy_unknown' && <span className="mt-1 inline-flex"><StatusBadge variant="neutral">Recorded by: unknown</StatusBadge></span>}
                    {entry.access === 'restricted' && <small>Part of this change was at a site you can’t see, so some details and who made it are hidden.</small>}
                    <details className="mt-1"><summary className="inline-flex cursor-pointer items-center rounded-sm text-sm font-medium text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring">{entry.before_access === 'restricted' || entry.after_access === 'restricted' ? 'Details at this version' : 'What changed'}</summary>
                        <dl className="mt-3 space-y-3">{[...new Set([...Object.keys(entry.before ?? {}), ...Object.keys(entry.after ?? {})])]
                            .filter((key) => !key.endsWith('_id') && (entry.before_access === 'restricted' || entry.after_access === 'restricted' || JSON.stringify(entry.before?.[key]) !== JSON.stringify(entry.after?.[key])))
                            .map((key) => <div key={key} className="min-w-0 space-y-1"><dt className="text-subtle">{historyFieldLabel(key)}</dt><dd className="text-sm break-words whitespace-pre-wrap">
                                <span className="block text-subtle">Before: {entry.before_access === 'restricted' ? 'Hidden — you don’t have access' : historyValue(key, entry.before?.[key])}</span>
                                <span className="block">After: {entry.after_access === 'restricted' ? 'Hidden — you don’t have access' : historyValue(key, entry.after?.[key])}</span>
                            </dd></div>)}</dl></details>
                </div>
                <span />
            </li>)}</ol>
        </Surface>}
        {!!d.audits?.length && <Surface title="Older history"><details className="px-[18px] pt-1 pb-4"><summary className="inline-flex cursor-pointer items-center rounded-sm text-sm font-medium text-primary outline-none focus-visible:ring-2 focus-visible:ring-ring">Show older history</summary>
            <ol className="mt-3 space-y-2">{d.audits.map((entry) => <li key={entry.id} className="text-sm">{injuryTime(entry.at)} · {entry.actor ?? 'Unknown person'} · {label(entry.action)}</li>)}</ol></details></Surface>}
    </>;
}
/** What a source version recorded, in plain words; a readable status change names both stages. */
const HISTORY_COMMANDS: Record<string, string> = {
    create: 'Injury reported', update: 'Injury details updated', assign: 'Owner assigned', review: 'Recovery review recorded',
    transition: 'Status changed', record_capacity: 'Fitness-for-work check recorded', create_rtw_plan: 'Return-to-work plan added',
    update_rtw_plan: 'Return-to-work plan updated', add_modified_duty: 'Modified duties added',
    upload_evidence: 'File added', remove_evidence: 'File removed',
};
function historyTitle(entry: InjuryHistory): string {
    const before = entry.before_access === 'readable' ? entry.before?.status : undefined;
    const after = entry.after_access === 'readable' ? entry.after?.status : undefined;
    if (entry.command === 'transition' && typeof before === 'string' && typeof after === 'string' && before !== after)
        return `Status changed: ${statusLabel(before)} → ${statusLabel(after)}`;
    return HISTORY_COMMANDS[entry.command] ?? label(entry.command);
}
const HISTORY_LABELS: Record<string, string> = {
    injury_date: 'When it happened', injury_type: 'Injury type', body_part_affected: 'Body part',
    severity: 'Severity', description: 'What happened', immediate_treatment: 'Immediate treatment',
    medical_treatment_type: 'Treatment level', worksafe_notifiable: 'Marked notifiable to WorkSafe',
    acc_claim_lodged: 'ACC claim lodged', acc_claim_number: 'ACC claim number',
    lost_time_days: 'Days off work', expected_return_date: 'Expected return', actual_return_date: 'Back at work',
    status: 'Status', notes: 'Notes', next_review_date: 'Next check-in',
    assessment_date: 'Assessment date', assessor_name: 'Assessor name', assessor_type: 'Assessor type',
    capacity_status: 'Result', assessment_summary: 'Assessment summary', restrictions: 'Restrictions',
    recommendations: 'Recommendations', next_assessment_date: 'Next check due', plan_start_date: 'Plan starts',
    plan_end_date: 'Plan ends', medical_clearance_notes: 'Clearance notes', medical_clearance_date: 'Date cleared',
    medical_clearance_provider: 'Who cleared them to work', goals: 'Recovery goals', stages: 'Recovery stages',
    workplace_modifications: 'Changes made at work', worker_agreement_notes: 'Worker agreement notes',
    worker_agreed: 'Worker agreed to the plan', worker_agreed_at: 'Date the worker agreed', review_notes: 'Review notes',
    start_date: 'Starts', end_date: 'Ends', modified_duties_description: 'Duties', accommodations: 'Changes made at work',
    hours_per_day: 'Hours a day',
};
const historyFieldLabel = (key: string) => HISTORY_LABELS[key] ?? 'Other detail';
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
        return 'Arrangement';
    }).join('\n') || 'None recorded';
    return typeof value === 'string'
        ? injuryValueLabel(key, value)
        : typeof value === 'number' ? String(value) : 'Other detail';
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
                foot: 'The safety review and the control room response have their own owners.',
            }]} />
            : <QuietLine title="Who’s coordinating" text={`Recovery coordinator: ${d.coordination.attribution_state === 'unassigned' ? 'No owner yet' : 'not clear who’s coordinating — a health and safety manager can set this'}`} />}
    </>;
}

