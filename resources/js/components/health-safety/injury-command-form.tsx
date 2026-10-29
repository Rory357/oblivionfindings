import { DrillTimeField } from '@/components/health-safety/drills/drill-time-field';
import { INJURY_TYPES, SEVERITY_OPTIONS, statusLabel, TREATMENT_OPTIONS } from '@/components/health-safety/injury-constants';
import { SourceCommandStatus } from '@/components/health-safety/restraints/command-status';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone, formatFileSize } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { WizardShell } from '@/components/wizard/shell';
import { formatDateOnly, formatDateTimeLong } from '@/lib/datetime';
import { Link } from '@inertiajs/react';
import axios from 'axios';
import { HeartPulse, Plus, Trash2 } from 'lucide-react';
import { useEffect, useId, useState, type ReactNode } from 'react';
import { ASSESSOR_TYPE_LABELS, CAPACITY_STATUS_LABELS, INJURY_COMMAND_LABELS, INJURY_WIZARD_STEPS, injuryFieldLabel, injuryValueLabel, RTW_PLAN_STATUS_LABELS } from './injury-command-state';
import { emptyInjuryStage, injuryCommandRequest, injuryFormValues, type InjuryFormValues } from './injury-command-values';
import { InjuryPeoplePicker } from './injury-people-picker';
import type { InjuryCaptureOptions, InjuryCommand, InjuryCommandOptions, InjuryRecord, InjuryRecoveryOptions } from './injury-workspace-types';
import { useInjuryCommand } from './use-injury-command';

const WIZARD_COMMANDS = ['create', 'edit', 'add_rtw', 'edit_rtw', 'add_capacity', 'add_duty', 'upload_evidence'];
const STEPS = INJURY_WIZARD_STEPS;
/** The save button names what it does (never a bare "Save and confirm"). */
const SAVE_LABELS: Record<InjuryCommand | 'create', string> = {
    create: 'Save injury', edit: 'Save changes', assign: 'Save owner', review: 'Save review', acc: 'Save ACC claim',
    add_rtw: 'Save recovery plan', edit_rtw: 'Save recovery plan', add_capacity: 'Save check', add_duty: 'Save duties',
    upload_evidence: 'Add file', remove_evidence: 'Remove file', start_treatment: 'Record treatment started',
    begin_rtw: 'Start recovery planning', mark_recovered: 'Record recovery', close: 'Close injury',
};
const options = (labels: Record<string, string>) => Object.entries(labels).map(([value, label]) => ({ value, label }));
type Options = InjuryCaptureOptions | InjuryCommandOptions | InjuryRecoveryOptions;
const optionsObject = (value: unknown): value is Record<string, unknown> => !!value && typeof value === 'object' && !Array.isArray(value);
export function InjuryCommandForm({ detail: d, command, actorId, capture, selected, onDone, onCancel, returnTo, onStepChange }: {
    detail: InjuryRecord | null; command: InjuryCommand | 'create'; actorId: number; capture?: InjuryCaptureOptions;
    selected?: { plan?: number | null; attachment?: number | null }; onDone: (href?: string | null) => void;
    onCancel: () => void; returnTo: string;
    /** Reports the wizard step (0-based) and whether saving is confirmed — display only. */
    onStepChange?: (step: number, saved: boolean) => void;
}) {
    const id = useId();
    const [step, setStep] = useState(0);
    const [pickers, setPickers] = useState<Options | null>(capture ?? null);
    const [pickerFailure, setPickerFailure] = useState<string | null>(null);
    const [pickerRetry, setPickerRetry] = useState(0);
    const [captureChoicesAllowed, setCaptureChoicesAllowed] = useState(true);
    const [siteChoices, setSiteChoices] = useState(capture?.sites ?? (d?.site ? [d.site] : []));
    const [selectedPeople, setSelectedPeople] = useState<Record<string, { id: number; name: string }>>(() => {
        const plan = d?.rtw_plans?.find((item) => item.id === selected?.plan);
        const people: Record<string, { id: number; name: string }> = {};
        if (d?.worker) people.user_id = d.worker;
        if (d?.coordination.coordinator?.id != null) people.coordinator_user_id = { id: d.coordination.coordinator.id, name: d.coordination.coordinator.name };
        if (plan?.manager) people.manager_id = plan.manager;
        return people;
    });
    const source = useInjuryCommand({ initial: { ...injuryFormValues(d, selected?.plan), ...(['review', 'upload_evidence'].includes(command) ? { notes: '' } : {}) }, detail: d, command, actorId, selected, captureAllowed: capture?.can.create === true && capture.can.record_reported_details === true });
    const { form, change, save } = source;
    useEffect(() => onStepChange?.(step, !!source.receipt), [onStepChange, step, source.receipt]);
    const f = form.data;
    const checked = optionsObject(source.checkedResponse) && optionsObject(source.checkedResponse.detail) ? source.checkedResponse.detail : null;
    const reviewed = checked !== null && d !== null && checked.id === d.id && checked.source_version === source.guard.version;
    const checkedSite = checked && optionsObject(checked.site) ? checked.site : null;
    const canonicalSiteId = reviewed && checkedSite && Number.isSafeInteger(checkedSite.id)
        ? Number(checkedSite.id) : d?.site?.id;
    const currentMedicalAccess = source.allowed && !source.checkFailed &&
        (checked?.id === d?.id ? checked?.medical_access : d?.medical_access) === 'readable';
    const commandRecord = d ? { ...d, medical_access: currentMedicalAccess ? 'readable' : 'restricted' } as InjuryRecord : null;
    const correctingSite = command === 'edit' && Number(f.site_id) !== canonicalSiteId;
    const busy = form.processing || source.checking || !!source.receipt;
    const checkedCapture = optionsObject(source.checkedResponse) && optionsObject(source.checkedResponse.capture) && optionsObject(source.checkedResponse.capture.can) ? source.checkedResponse.capture.can : null;
    const recordReportedDetails = command === 'create' && source.allowed && !source.checkFailed && captureChoicesAllowed && (checkedCapture
        ? checkedCapture.create === true && checkedCapture.record_reported_details === true
        : capture?.can.create === true && capture.can.record_reported_details === true);
    const medical = command === 'create' ? recordReportedDetails : commandRecord?.medical_access === 'readable';
    const privateCommandBlocked = ['acc', 'review', 'add_capacity', 'add_rtw', 'edit_rtw', 'add_duty', 'upload_evidence', 'remove_evidence'].includes(command) && !medical;
    const recovery = ['add_capacity', 'add_rtw', 'edit_rtw', 'add_duty'].includes(command);
    const needsPickers = ['create', 'edit', 'assign', 'add_capacity', 'add_rtw', 'edit_rtw'].includes(command);
    const endpoint = command === 'create' ? '/health-safety/injuries/capture-options' : `/health-safety/injuries/${d?.id}/${command === 'add_capacity' ? 'recovery-options' : 'command-options'}`;
    const selectedSite = command === 'create' || command === 'edit' ? f.site_id : null;
    useEffect(() => {
        if (!needsPickers) return;
        const controller = new AbortController();
        setPickerFailure(null); setPickers(null);
        if (command === 'create' && !f.site_id) { setPickers(capture ?? null); return () => controller.abort(); }
        axios.get(endpoint, { headers: { Accept: 'application/json' }, signal: controller.signal, params: selectedSite ? { site_id: selectedSite } : {} }).then(({ data }) => {
            if (controller.signal.aborted) return;
            if (!optionsObject(data) || (d && (data.injury_id !== d.id || data.expected_version !== source.guard.version)) ||
                (command === 'create' && (!optionsObject(data.can) || data.can.create !== true || data.can.record_reported_details !== true)) ||
                !Array.isArray(data[command === 'add_capacity' ? 'assessors' : 'workers'])) throw new Error('Options changed');
            if (command === 'edit' && (data.canonical_site_id !== canonicalSiteId || data.site_id !== Number(selectedSite) ||
                !Array.isArray(data.sites) || !data.sites.every((site: { id?: unknown; name?: unknown } | null) => !!site && Number.isSafeInteger(site.id) && typeof site.name === 'string') ||
                !data.sites.some((site: { id: number }) => site.id === data.site_id))) throw new Error('Site choices changed');
            if (command === 'edit') setSiteChoices(data.sites as { id: number; name: string }[]);
            setPickers(data as unknown as Options);
            if (command === 'create') setCaptureChoicesAllowed(true);
        }).catch(() => { if (!controller.signal.aborted) {
            if (command === 'create') setCaptureChoicesAllowed(false);
            setPickerFailure('We couldn’t load the list of choices. Try again before saving.');
        } });
        return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reload choices only when the record id, version or Site changes, not for each new detail object.
    }, [endpoint, needsPickers, command, d?.id, canonicalSiteId, source.guard.version, selectedSite, pickerRetry, capture]);
    const title = command === 'create' ? 'Report workplace injury' : command === 'assign' && !d?.coordination.coordinator ? 'Choose injury owner' : INJURY_COMMAND_LABELS[command];
    const field = <K extends keyof InjuryFormValues>(key: K, label: string, type = 'text', required = false, maxLength?: number) => <Field label={label} htmlFor={`${id}-${key}`} required={required} error={(form.errors as Record<string, string>)[key]}>
        <Input id={`${id}-${key}`} className="frontline-tap" type={type} required={required} disabled={busy} value={String(f[key] ?? '')} maxLength={maxLength} onChange={(event) => change(key, event.target.value as InjuryFormValues[K])} />
    </Field>;
    const area = (key: keyof InjuryFormValues, label: string, maxLength = 2000, required = false) => <Field label={label} htmlFor={`${id}-${key}`} required={required} error={(form.errors as Record<string, string>)[key]}><Textarea id={`${id}-${key}`} className="frontline-tap" required={required} disabled={busy} value={String(f[key] ?? '')} maxLength={maxLength} rows={3} onChange={(event) => change(key, event.target.value as never)} /></Field>;
    const select = (key: keyof InjuryFormValues, label: string, choices: { value: string; label: string }[], required = false) => <Field label={label} htmlFor={`${id}-${key}`} required={required} error={(form.errors as Record<string, string>)[key]}><SelectInput id={`${id}-${key}`} frontline disabled={busy} value={f[key] === '' && choices.some((item) => item.value === '') ? '__none' : String(f[key])} placeholder={`Choose ${label.toLowerCase()}`} options={choices.map((item) => ({ ...item, value: item.value || '__none' }))} onChange={(value) => change(key, (value === '__none' ? '' : value) as never)} /></Field>;
    const check = (key: 'worksafe_notifiable' | 'acc_claim_lodged' | 'worker_agreed', label: string) => <div className="flex items-center gap-3"><Checkbox id={`${id}-${key}`} className="frontline-hit" checked={f[key]} disabled={busy} onCheckedChange={(value) => change(key, value === true)} /><label htmlFor={`${id}-${key}`} className="frontline-hit text-subtle cursor-pointer">{label}</label></div>;
    const person = (key: 'user_id' | 'coordinator_user_id' | 'assessor_user_id' | 'manager_id', label: string, group: 'workers' | 'coordinators' | 'assessors') => <Field label={label} htmlFor={`${id}-${key}`} error={(form.errors as Record<string, string>)[key]}><InjuryPeoplePicker id={`${id}-${key}`} label={label} endpoint={endpoint} group={group} value={f[key]} onChange={(value) => change(key, value)} onSelection={(person) => setSelectedPeople((current) => ({ ...current, [key]: person }))} {...(command === 'create' ? { siteId: f.site_id } : { injuryId: d?.id, version: source.guard.version, ...(command === 'edit' ? { siteId: f.site_id, canonicalSiteId } : {}) })} disabled={busy} />{selectedPeople[key]?.id === Number(f[key]) && <p className="text-caption">Selected: {selectedPeople[key].name}</p>}</Field>;
    const incidentChoices = pickers && 'incidents' in pickers ? pickers.incidents : [];
    const linkIncident = (!d || ['absent', 'readable'].includes(d.connections.incident.access)) && select('related_incident_id', 'Linked incident', [{ value: '', label: 'No linked incident' }, ...incidentChoices.map((item) => ({ value: String(item.id), label: `${item.label} · ${item.title}` }))]);
    const facts = command === 'create' || command === 'edit' ? <>
        <Field label="Site" htmlFor={`${id}-site_id`} required error={(form.errors as Record<string, string>).site_id}><SelectInput id={`${id}-site_id`} frontline disabled={busy || (command === 'edit' && !pickers)} value={f.site_id} placeholder="Choose the site" options={siteChoices.map((site) => ({ value: String(site.id), label: site.name }))} onChange={(value) => {
            if (value === f.site_id) return;
            change('site_id', value); change('user_id', '');
            if (command === 'edit') change('coordinator_user_id', '');
            if (!d || ['absent', 'readable'].includes(d.connections.incident.access)) change('related_incident_id', '');
        }} /></Field>
        {correctingSite && <p className="text-subtle">You’ve changed the site. Choose the injured worker, the injury owner and any linked incident again. The old details stay in the history.</p>}
        {person('user_id', 'Injured worker', 'workers')}
        {correctingSite && <>{person('coordinator_user_id', 'Injury owner at the new site', 'coordinators')}{field('next_review_date', 'Next check-in date', 'date', true)}{area('reason', 'Why are you changing the site?', 2000, true)}</>}
        <DrillTimeField label="Injury occurred" value={f.injury_date} offset={f.injury_offset} onChange={(value, offset) => { change('injury_date', value); change('injury_offset', offset); }} />
        {select('injury_type', 'Injury type', INJURY_TYPES.map((item) => ({ value: item.key, label: item.label })), true)}
        {select('severity', 'Severity', SEVERITY_OPTIONS, true)}
        {field('body_part_affected', 'Body part affected', 'text', true, 255)}
        {area('description', 'What happened', 5000, true)}
        {linkIncident}
    </> : command === 'add_rtw' || command === 'edit_rtw' ? <>
        {field('plan_start_date', 'Plan starts', 'date', true)}{field('plan_end_date', 'Plan ends', 'date')}
        {person('manager_id', 'Recovery coordinator', 'coordinators')}
        {select('plan_status', 'Plan status', options(RTW_PLAN_STATUS_LABELS))}
        <fieldset className="space-y-3"><legend className="text-section-title">Recovery goals</legend>{f.goals.map((goal, index) => <div className="flex items-start gap-2" key={index}><Field label={`Goal ${index + 1}`} htmlFor={`${id}-goal-${index}`}><Input id={`${id}-goal-${index}`} className="frontline-tap" value={goal} disabled={busy} maxLength={500} onChange={(event) => change('goals', f.goals.map((item, pos) => pos === index ? event.target.value : item))} /></Field><Button type="button" className="frontline-tap shrink-0" variant="outline" aria-label={`Remove goal ${index + 1}`} disabled={busy || f.goals.length === 1} onClick={() => change('goals', f.goals.filter((_, pos) => pos !== index))}><Trash2 className="size-4" /></Button></div>)}<Button type="button" variant="outline" className="frontline-tap" disabled={busy || f.goals.length >= 30} onClick={() => change('goals', [...f.goals, ''])}><Plus className="size-4" />Add goal</Button></fieldset>
        <fieldset className="space-y-4"><legend className="text-section-title">Recovery stages</legend>{f.stages.map((stage, index) => <div key={index} className="space-y-3 rounded-xl border border-border p-4"><h3 className="text-subtle font-medium">Stage {index + 1}</h3>{(['name', 'start_date', 'end_date', 'hours_per_week', 'duties_description'] as const).map((key) => <Field key={key} label={({ name: 'Stage name', start_date: 'Starts', end_date: 'Ends', hours_per_week: 'Hours per week', duties_description: 'Duties' })[key]} htmlFor={`${id}-stage-${index}-${key}`}><Input id={`${id}-stage-${index}-${key}`} className="frontline-tap" type={key.endsWith('date') ? 'date' : key === 'hours_per_week' ? 'number' : 'text'} disabled={busy} value={stage[key]} maxLength={key === 'name' ? 255 : 1000} onChange={(event) => change('stages', f.stages.map((item, pos) => pos === index ? { ...item, [key]: event.target.value } : item))} /></Field>)}<Button type="button" variant="outline" className="frontline-tap" disabled={busy || f.stages.length === 1} onClick={() => change('stages', f.stages.filter((_, pos) => pos !== index))}>Remove stage {index + 1}</Button></div>)}<Button type="button" className="frontline-tap" variant="outline" disabled={busy || f.stages.length >= 30} onClick={() => change('stages', [...f.stages, emptyInjuryStage()])}>Add stage</Button></fieldset>
    </> : command === 'add_capacity' ? <>
        {field('assessment_date', 'Assessment date', 'date', true)}
        {person('assessor_user_id', 'Assessor (if they’re on our staff)', 'assessors')}
        <Button type="button" variant="outline" className="frontline-tap" disabled={busy} onClick={() => change('assessor_user_id', '')}>Assessor isn’t on our staff</Button>
        {field('assessor_name', 'Assessor’s name (if not on our staff)', 'text', false, 255)}
        {select('assessor_type', 'Assessor type', options(ASSESSOR_TYPE_LABELS), true)}
        {select('capacity_status', 'What the assessor said', options(CAPACITY_STATUS_LABELS), true)}
        {area('assessment_summary', 'Assessment summary', 5000)}
    </> : command === 'add_duty' ? <>{field('start_date', 'Starts', 'date', true)}{field('end_date', 'Ends', 'date')}{area('modified_duties_description', 'Duties', 2000, true)}{field('hours_per_day', 'Hours per day', 'number', true)}</> : command === 'upload_evidence' ? <>
        <Field label="Evidence file" required error={(form.errors as Record<string, string>).file} labelId={`${id}-file-label`}><FileDropzone aria-labelledby={`${id}-file-label`} multiple={false} disabled={busy} accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx" hint="PDF, Word or image · up to 10 MB" onFiles={(files) => change('file', files[0] ?? null)} /></Field>
        {f.file && <div className="space-y-2 rounded-xl border border-border p-4"><p className="text-subtle break-words">{f.file.name} · {formatFileSize(f.file.size)}</p><Button type="button" className="frontline-tap" variant="outline" disabled={busy} onClick={() => change('file', null)}>Remove file</Button></div>}
    </> : null;
    const supporting = command === 'create' || command === 'edit' ? <>
        {medical && <>{area('immediate_treatment', 'Immediate treatment')}{select('medical_treatment_type', 'Medical treatment', TREATMENT_OPTIONS, command === 'create')}{check('acc_claim_lodged', 'ACC claim lodged')}{field('acc_claim_number', 'ACC claim number', 'text', false, 50)}{area('notes', 'Private injury notes')}</>}
        {check('worksafe_notifiable', 'Marked notifiable to WorkSafe')}{field('lost_time_days', 'Days off work', 'number', true)}{field('expected_return_date', 'Expected return date', 'date')}{field('actual_return_date', 'Date back at work — if known', 'date')}
    </> : command === 'add_rtw' || command === 'edit_rtw' ? <>
        {area('workplace_modifications', 'Changes made at work', 5000)}{check('worker_agreed', 'Worker agreed to the plan')}{field('worker_agreed_at', 'Worker agreement date', 'date')}{area('worker_agreement_notes', 'Worker agreement notes')}
        {field('medical_clearance_provider', 'Who cleared them to work', 'text', false, 255)}{field('medical_clearance_date', 'Date cleared', 'date')}{area('medical_clearance_notes', 'Clearance notes')}
        {field('next_review_date', 'Next review date', 'date')}{area('review_notes', 'Review notes')}
    </> : command === 'add_capacity' ? <>
        {area('restrictions', 'Restrictions', 5000)}{area('recommendations', 'Recommendations', 5000)}{field('next_assessment_date', 'Next assessment date', 'date')}
        {pickers && 'documents' in pickers && pickers.documents.access === 'readable' ? select('hr_document_id', 'Assessment document', [{ value: '', label: 'No document linked' }, ...(pickers.documents.items ?? []).filter((item) => item.id !== null).map((item) => ({ value: String(item.id), label: item.title ?? 'Assessment document' }))]) : <p className="text-subtle">To link a medical document you need HR document access. Ask HR if you need it.</p>}
    </> : command === 'add_duty' ? <>{area('restrictions', 'Restrictions')}{area('accommodations', 'Changes made at work')}</> : command === 'upload_evidence' ? <>
        {select('kind', 'Evidence type', [{ value: 'medical_cert', label: 'Medical certificate' }, { value: 'acc_form', label: 'ACC form' }, { value: 'rtw_clearance', label: 'Return-to-work clearance' }, { value: 'photo', label: 'Photo' }, { value: 'document', label: 'Document' }])}{area('notes', 'Evidence notes')}{field('alt_text', 'Image description', 'text', false, 255)}
    </> : null;
    const simple = command === 'assign' ? <>{person('coordinator_user_id', 'Injury owner', 'coordinators')}{field('next_review_date', 'Next check-in date', 'date', true)}{area('reason', 'Why this owner?', 2000, true)}</> : command === 'review' ? <>
        <DrillTimeField label="Review occurred" value={f.reviewed_at} offset={f.review_offset} onChange={(value, offset) => { change('reviewed_at', value); change('review_offset', offset); }} />{field('next_review_date', 'Next review date', 'date', true)}{area('notes', 'Review findings and next steps', 5000, true)}
    </> : command === 'acc' ? <>{check('acc_claim_lodged', 'ACC claim lodged')}{field('acc_claim_number', 'ACC claim number', 'text', false, 50)}<p className="text-subtle">This only notes the claim here. It doesn’t send anything to ACC.</p></> : <>
        {command === 'remove_evidence' && <p className="text-subtle">Remove {d?.attachments?.find((item) => item.id === selected?.attachment)?.original_name ?? 'this file'} from this injury. A note that it was removed stays in the history.</p>}
        {area('reason', command === 'remove_evidence' ? 'Removal reason' : 'Reason and outcome', 2000, true)}
        {command === 'mark_recovered' && <p className="text-subtle">Note how their recovery is going. Add the date they returned to work once you know it.</p>}
        {command === 'close' && <p className="text-subtle">The injury’s history is kept. Check the linked investigation, ACC claim, restrictions and actions on their own records.</p>}
    </>;
    const finish = () => onDone(source.confirmedHref);
    const currentFacts = checked ? [
        { label: 'Status now', value: typeof checked.status === 'string' ? statusLabel(checked.status) : null },
        { label: 'Site now', value: optionsObject(checked.site) && typeof checked.site.name === 'string' ? checked.site.name : null },
        { label: 'Injured worker', value: optionsObject(checked.worker) && typeof checked.worker.name === 'string' ? checked.worker.name : null },
        { label: 'What happened', value: typeof checked.description === 'string' ? checked.description : null },
        { label: 'Next check-in', value: optionsObject(checked.coordination) && typeof checked.coordination.next_review_date === 'string' ? formatDateOnly(checked.coordination.next_review_date) : null }] : undefined;
    const status = <SourceCommandStatus receipt={source.receipt} failure={source.failure} stale={source.stale} checking={source.checking} allowed={source.allowed} canCheck={!source.receipt} reviewAvailable={source.reviewAvailable} currentFacts={currentFacts} onCheck={() => void source.checkCurrent()} onReview={() => { source.useCurrent(); setStep(2); }} onDone={finish} />;
    const submit = () => {
        if (privateCommandBlocked) return;
        if (needsPickers && (!pickers || pickerFailure)) return;
        if (command === 'create' && !recordReportedDetails) return;
        if ((command === 'create' || correctingSite) && (!f.site_id || !f.user_id)) return;
        if (correctingSite && (!f.coordinator_user_id || !f.reason.trim() || !f.next_review_date)) return;
        const request = injuryCommandRequest(command, f, commandRecord && correctingSite ? { ...commandRecord, site: { id: canonicalSiteId ?? 0, name: commandRecord.site?.name ?? '' } } : commandRecord, selected, recordReportedDetails);
        save(request.method, request.href, { ...request.values, return_to: returnTo });
    };
    const correctionIncomplete = (command === 'create' && !recordReportedDetails) || ((command === 'create' || correctingSite) && (!f.site_id || !f.user_id)) || (correctingSite && (!f.coordinator_user_id || !f.reason.trim() || !f.next_review_date));
    const saveButton = <Button type="button" className="frontline-tap" disabled={busy || !source.allowed || source.stale || source.checkFailed || privateCommandBlocked || correctionIncomplete || (needsPickers && (!pickers || !!pickerFailure))} onClick={submit}>{form.processing ? 'Saving…' : SAVE_LABELS[command]}</Button>;
    const errors = Object.entries(form.errors as Record<string, string>);
    const notices: ReactNode = <>
        {command === 'create' && !recordReportedDetails && <p role="alert" className="text-subtle text-status-warning">You can’t add injury details with your current access. Your draft is still here — ask a health and safety manager for injury access before saving.</p>}
        {(privateCommandBlocked || (command === 'edit' && d?.medical_access === 'readable' && !medical)) && <p role="alert" className="text-subtle text-status-warning">Private injury details are hidden with your current access. Your draft is still here — ask a health and safety manager for injury access before continuing.</p>}
        {pickerFailure && <div className="space-y-3"><p role="alert" className="text-subtle text-status-critical">{pickerFailure}</p><Button type="button" className="frontline-tap" variant="outline" disabled={busy} onClick={() => setPickerRetry(pickerRetry + 1)}>Try again</Button></div>}
        {errors.length > 0 && <div role="alert" className="space-y-2 rounded-xl border border-status-critical bg-status-critical-bg p-4"><p className="font-medium">Check these entries</p><ul className="list-inside list-disc text-subtle">{errors.map(([key, message]) => <li key={key}>{message}</li>)}</ul></div>}
        {recovery && <p className="text-subtle">Write down what the assessor or doctor said, or the recovery arrangement. This doesn’t change anyone’s roster — limits on work need a separate approved rostering decision before they affect shifts.</p>}
    </>;
    const requested = privateCommandBlocked ? {} : injuryCommandRequest(command, f, commandRecord && correctingSite ? { ...commandRecord, site: { id: canonicalSiteId ?? 0, name: commandRecord.site?.name ?? '' } } : commandRecord, selected, recordReportedDetails).values;
    const identityReview = Object.entries(requested).filter(([key]) => key.endsWith('_id')).map(([key, value]) => {
        let name: string | null = null;
        if (key === 'site_id') name = siteChoices.find((site) => site.id === value)?.name ?? null;
        else if (key === 'related_incident_id') name = incidentChoices.find((incident) => incident.id === value)?.label ?? (d?.related_incident && d.related_incident.id === value ? d.related_incident.label : null);
        else if (key === 'hr_document_id') name = pickers && 'documents' in pickers ? pickers.documents.items?.find((document) => document.id === value)?.title ?? null : null;
        else if (selectedPeople[key]?.id === value) name = selectedPeople[key].name;
        return { key, name: value == null ? 'Not selected' : name ?? 'Check this choice before saving' };
    });
    const reviewValue = (key: string, value: unknown): string => {
        if (value == null || value === '') return 'Not recorded';
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDateOnly(value);
        if (typeof value === 'string' && ['injury_date', 'reviewed_at'].includes(key)) return formatDateTimeLong(value);
        if (Array.isArray(value)) return value.map((item) => typeof item === 'string' ? item : Object.entries(item).map(([field, part]) => `${injuryFieldLabel(field)}: ${reviewValue(field, part)}`).join(' · ')).join('\n');
        return typeof value === 'string' ? injuryValueLabel(key, value) : String(value);
    };
    const missing = (command === 'create' || correctingSite) && (!f.site_id || !f.user_id) ? `Choose the site and the injured worker${correctingSite ? ', the new injury owner, the next check-in date and the reason' : ''} before you can save.`
        : correctingSite && (!f.coordinator_user_id || !f.reason.trim() || !f.next_review_date) ? 'Choose the new injury owner, the next check-in date and the reason for the site change before you can save.' : null;
    const review = <div className="space-y-4"><h2 className="text-section-title">Check your changes</h2><p className="text-subtle">{title} for {selectedPeople.user_id?.id === Number(f.user_id) ? selectedPeople.user_id.name : d?.worker?.name ?? 'the selected worker'}. Nothing is saved until you select {SAVE_LABELS[command]}.</p>{missing && <p className="text-subtle text-status-warning">{missing}</p>}<dl className="space-y-3">{identityReview.map(({ key, name }) => <div key={key}><dt className="text-caption text-muted-foreground">{injuryFieldLabel(key)}</dt><dd className="text-subtle break-words">{name}</dd></div>)}{Object.entries(requested).filter(([key]) => !key.endsWith('_id') && key !== 'file').map(([key, value]) => <div key={key}><dt className="text-caption text-muted-foreground">{injuryFieldLabel(key)}</dt><dd className="text-subtle break-words whitespace-pre-wrap">{reviewValue(key, value)}</dd></div>)}</dl>{f.file && <p>{f.file.name}</p>}</div>;
    if (WIZARD_COMMANDS.includes(command)) return <WizardShell frontline open onClose={onCancel} title={title} description="Fill in the details, then check and save" railIcon={HeartPulse} railTitle={title} railSub={d?.reference ?? 'New injury'} steps={STEPS.map((item) => ({ ...item, disabled: busy }))} stepIndex={step} onStepClick={setStep} pct={Math.round((step + 1) / 3 * 100)} pctLabel="Progress" footerStart={<Button type="button" variant="outline" className="frontline-tap" disabled={form.processing || source.checking} onClick={onCancel}>Cancel</Button>} footerEnd={source.receipt ? null : <>{step > 0 && <Button type="button" className="frontline-tap" variant="outline" disabled={busy} onClick={() => setStep(step - 1)}>Back</Button>}{step < 2 ? <Button type="button" className="frontline-tap" disabled={busy} onClick={() => setStep(step + 1)}>Next</Button> : saveButton}</>} success={source.receipt ? status : undefined}>
        <div className="space-y-5">{notices}{!privateCommandBlocked && (step === 0 ? facts : step === 1 ? supporting : review)}{status}</div>
    </WizardShell>;
    return <div className="space-y-5"><h2 className="text-section-title">{title}</h2>{notices}{!source.receipt && !privateCommandBlocked && simple}{status}{!source.receipt && <div className="flex flex-wrap gap-3">{saveButton}<Button type="button" variant="outline" className="frontline-tap" disabled={form.processing || source.checking} onClick={onCancel}>Cancel</Button></div>}{source.receipt && !source.confirmedHref && <Link className="frontline-tap frontline-focus inline-flex items-center rounded-md text-subtle" href={returnTo}>Return to your work</Link>}</div>;
}
