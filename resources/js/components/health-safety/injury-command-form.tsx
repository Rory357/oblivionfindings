import { DrillTimeField } from '@/components/health-safety/drills/drill-time-field';
import { INJURY_TYPES, SEVERITY_OPTIONS, TREATMENT_OPTIONS } from '@/components/health-safety/injury-constants';
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
import { INJURY_COMMAND_LABELS, INJURY_WIZARD_STEPS } from './injury-command-state';
import { emptyInjuryStage, injuryCommandRequest, injuryFormValues, type InjuryFormValues } from './injury-command-values';
import { InjuryPeoplePicker } from './injury-people-picker';
import type { InjuryCaptureOptions, InjuryCommand, InjuryCommandOptions, InjuryRecord, InjuryRecoveryOptions } from './injury-workspace-types';
import { useInjuryCommand } from './use-injury-command';

const WIZARD_COMMANDS = ['create', 'edit', 'add_rtw', 'edit_rtw', 'add_capacity', 'add_duty', 'upload_evidence'];
const STEPS = INJURY_WIZARD_STEPS;
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
            setPickerFailure('Current choices could not be loaded. Retry or check the current record before saving.');
        } });
        return () => controller.abort();
    // eslint-disable-next-line react-hooks/exhaustive-deps -- reload choices only when the record id, version or Site changes, not for each new detail object.
    }, [endpoint, needsPickers, command, d?.id, canonicalSiteId, source.guard.version, selectedSite, pickerRetry, capture]);
    const title = command === 'create' ? 'Report workplace injury' : command === 'assign' && !d?.coordination.coordinator ? 'Assign incident owner' : INJURY_COMMAND_LABELS[command];
    const field = <K extends keyof InjuryFormValues>(key: K, label: string, type = 'text', required = false, maxLength?: number) => <Field label={label} htmlFor={`${id}-${key}`} required={required} error={(form.errors as Record<string, string>)[key]}>
        <Input id={`${id}-${key}`} className="frontline-tap" type={type} required={required} disabled={busy} value={String(f[key] ?? '')} maxLength={maxLength} onChange={(event) => change(key, event.target.value as InjuryFormValues[K])} />
    </Field>;
    const area = (key: keyof InjuryFormValues, label: string, maxLength = 2000, required = false) => <Field label={label} htmlFor={`${id}-${key}`} required={required} error={(form.errors as Record<string, string>)[key]}><Textarea id={`${id}-${key}`} className="frontline-tap" required={required} disabled={busy} value={String(f[key] ?? '')} maxLength={maxLength} rows={3} onChange={(event) => change(key, event.target.value as never)} /></Field>;
    const select = (key: keyof InjuryFormValues, label: string, choices: { value: string; label: string }[], required = false) => <Field label={label} htmlFor={`${id}-${key}`} required={required} error={(form.errors as Record<string, string>)[key]}><SelectInput id={`${id}-${key}`} frontline disabled={busy} value={f[key] === '' && choices.some((item) => item.value === '') ? '__none' : String(f[key])} placeholder={`Choose ${label.toLowerCase()}`} options={choices.map((item) => ({ ...item, value: item.value || '__none' }))} onChange={(value) => change(key, (value === '__none' ? '' : value) as never)} /></Field>;
    const check = (key: 'worksafe_notifiable' | 'acc_claim_lodged' | 'worker_agreed', label: string) => <div className="flex items-center gap-3"><Checkbox id={`${id}-${key}`} className="frontline-hit" checked={f[key]} disabled={busy} onCheckedChange={(value) => change(key, value === true)} /><label htmlFor={`${id}-${key}`} className="frontline-hit text-subtle cursor-pointer">{label}</label></div>;
    const person = (key: 'user_id' | 'coordinator_user_id' | 'assessor_user_id' | 'manager_id', label: string, group: 'workers' | 'coordinators' | 'assessors') => <Field label={label} htmlFor={`${id}-${key}`} error={(form.errors as Record<string, string>)[key]}><InjuryPeoplePicker id={`${id}-${key}`} label={label} endpoint={endpoint} group={group} value={f[key]} onChange={(value) => change(key, value)} onSelection={(person) => setSelectedPeople((current) => ({ ...current, [key]: person }))} {...(command === 'create' ? { siteId: f.site_id } : { injuryId: d?.id, version: source.guard.version, ...(command === 'edit' ? { siteId: f.site_id, canonicalSiteId } : {}) })} disabled={busy} />{selectedPeople[key]?.id === Number(f[key]) && <p className="text-caption">Selected: {selectedPeople[key].name}</p>}</Field>;
    const incidentChoices = pickers && 'incidents' in pickers ? pickers.incidents : [];
    const linkIncident = (!d || ['absent', 'readable'].includes(d.connections.incident.access)) && select('related_incident_id', 'Connected incident', [{ value: '', label: 'No connected incident' }, ...incidentChoices.map((item) => ({ value: String(item.id), label: `${item.label} · ${item.title}` }))]);
    const facts = command === 'create' || command === 'edit' ? <>
        <Field label="Recorded Site" htmlFor={`${id}-site_id`} required error={(form.errors as Record<string, string>).site_id}><SelectInput id={`${id}-site_id`} frontline disabled={busy || (command === 'edit' && !pickers)} value={f.site_id} placeholder="Choose recorded Site" options={siteChoices.map((site) => ({ value: String(site.id), label: site.name }))} onChange={(value) => {
            if (value === f.site_id) return;
            change('site_id', value); change('user_id', '');
            if (command === 'edit') change('coordinator_user_id', '');
            if (!d || ['absent', 'readable'].includes(d.connections.incident.access)) change('related_incident_id', '');
        }} /></Field>
        {correctingSite && <p className="text-subtle">Confirm the injured worker, named incident owner and any connected incident for this Site. The correction and handover are saved together, retaining the original history.</p>}
        {person('user_id', 'Injured worker', 'workers')}
        {correctingSite && <>{person('coordinator_user_id', 'Incident owner at corrected Site', 'coordinators')}{field('next_review_date', 'Next owner review date', 'date', true)}{area('reason', 'Correction and handover reason', 2000, true)}</>}
        <DrillTimeField label="Injury occurred" value={f.injury_date} offset={f.injury_offset} onChange={(value, offset) => { change('injury_date', value); change('injury_offset', offset); }} />
        {select('injury_type', 'Injury type', INJURY_TYPES.map((item) => ({ value: item.key, label: item.label })), true)}
        {select('severity', 'Severity', SEVERITY_OPTIONS, true)}
        {field('body_part_affected', 'Body part affected', 'text', true, 255)}
        {area('description', 'What happened', 5000, true)}
        {linkIncident}
    </> : command === 'add_rtw' || command === 'edit_rtw' ? <>
        {field('plan_start_date', 'Plan starts', 'date', true)}{field('plan_end_date', 'Plan ends', 'date')}
        {person('manager_id', 'Recovery coordinator', 'coordinators')}
        {select('plan_status', 'Recorded plan status', ['draft', 'active', 'in_progress', 'completed', 'cancelled'].map((value) => ({ value, label: value.replaceAll('_', ' ') })))}
        <fieldset className="space-y-3"><legend className="text-section-title">Recovery goals</legend>{f.goals.map((goal, index) => <div className="flex items-start gap-2" key={index}><Field label={`Goal ${index + 1}`} htmlFor={`${id}-goal-${index}`}><Input id={`${id}-goal-${index}`} className="frontline-tap" value={goal} disabled={busy} maxLength={500} onChange={(event) => change('goals', f.goals.map((item, pos) => pos === index ? event.target.value : item))} /></Field><Button type="button" className="frontline-tap shrink-0" variant="outline" aria-label={`Remove goal ${index + 1}`} disabled={busy || f.goals.length === 1} onClick={() => change('goals', f.goals.filter((_, pos) => pos !== index))}><Trash2 className="size-4" /></Button></div>)}<Button type="button" variant="outline" className="frontline-tap" disabled={busy || f.goals.length >= 30} onClick={() => change('goals', [...f.goals, ''])}><Plus className="size-4" />Add goal</Button></fieldset>
        <fieldset className="space-y-4"><legend className="text-section-title">Recovery stages</legend>{f.stages.map((stage, index) => <div key={index} className="space-y-3 rounded-xl border border-border p-4"><h3 className="text-subtle font-medium">Stage {index + 1}</h3>{(['name', 'start_date', 'end_date', 'hours_per_week', 'duties_description'] as const).map((key) => <Field key={key} label={({ name: 'Stage name', start_date: 'Starts', end_date: 'Ends', hours_per_week: 'Reported hours per week', duties_description: 'Reported duties' })[key]} htmlFor={`${id}-stage-${index}-${key}`}><Input id={`${id}-stage-${index}-${key}`} className="frontline-tap" type={key.endsWith('date') ? 'date' : key === 'hours_per_week' ? 'number' : 'text'} disabled={busy} value={stage[key]} maxLength={key === 'name' ? 255 : 1000} onChange={(event) => change('stages', f.stages.map((item, pos) => pos === index ? { ...item, [key]: event.target.value } : item))} /></Field>)}<Button type="button" variant="outline" className="frontline-tap" disabled={busy || f.stages.length === 1} onClick={() => change('stages', f.stages.filter((_, pos) => pos !== index))}>Remove stage {index + 1}</Button></div>)}<Button type="button" className="frontline-tap" variant="outline" disabled={busy || f.stages.length >= 30} onClick={() => change('stages', [...f.stages, emptyInjuryStage()])}>Add stage</Button></fieldset>
    </> : command === 'add_capacity' ? <>
        {field('assessment_date', 'Assessment date', 'date', true)}
        {person('assessor_user_id', 'Internal assessor, if applicable', 'assessors')}
        <Button type="button" variant="outline" className="frontline-tap" disabled={busy} onClick={() => change('assessor_user_id', '')}>Use reported external assessor</Button>
        {field('assessor_name', 'Reported external assessor name', 'text', false, 255)}
        {select('assessor_type', 'Reported assessor type', [{ value: 'gp', label: 'GP' }, { value: 'nurse_practitioner', label: 'Nurse practitioner' }, { value: 'specialist', label: 'Specialist' }, { value: 'physiotherapist', label: 'Physiotherapist' }, { value: 'occupational_therapist', label: 'Occupational therapist' }, { value: 'employer', label: 'Employer' }], true)}
        {select('capacity_status', 'Reported assessment outcome', [{ value: 'fit_for_full_duties', label: 'Assessor reports full duties' }, { value: 'fit_for_modified_duties', label: 'Assessor reports modified duties' }, { value: 'unfit_for_work', label: 'Assessor reports unfit for work' }, { value: 'requires_review', label: 'Assessor requests review' }], true)}
        {area('assessment_summary', 'Assessment summary', 5000)}
    </> : command === 'add_duty' ? <>{field('start_date', 'Arrangement starts', 'date', true)}{field('end_date', 'Arrangement ends', 'date')}{area('modified_duties_description', 'Proposed duties', 2000, true)}{field('hours_per_day', 'Reported hours per day', 'number', true)}</> : command === 'upload_evidence' ? <>
        <Field label="Evidence file" required error={(form.errors as Record<string, string>).file} labelId={`${id}-file-label`}><FileDropzone aria-labelledby={`${id}-file-label`} multiple={false} disabled={busy} accept=".pdf,.jpg,.jpeg,.png,.webp,.gif,.doc,.docx" hint="PDF, Word or image · up to 10 MB" onFiles={(files) => change('file', files[0] ?? null)} /></Field>
        {f.file && <div className="space-y-2 rounded-xl border border-border p-4"><p className="text-subtle break-words">{f.file.name} · {formatFileSize(f.file.size)}</p><Button type="button" className="frontline-tap" variant="outline" disabled={busy} onClick={() => change('file', null)}>Remove staged file</Button></div>}
    </> : null;
    const supporting = command === 'create' || command === 'edit' ? <>
        {medical && <>{area('immediate_treatment', 'Immediate treatment')}{select('medical_treatment_type', 'Medical treatment', TREATMENT_OPTIONS, command === 'create')}{check('acc_claim_lodged', 'Claim lodged — recorded internally')}{field('acc_claim_number', 'Claim number', 'text', false, 50)}{area('notes', 'Private injury notes')}</>}
        {check('worksafe_notifiable', 'WorkSafe notifiable flag recorded')}{field('lost_time_days', 'Recorded days lost', 'number', true)}{field('expected_return_date', 'Expected return date', 'date')}{field('actual_return_date', 'Actual return date — if known', 'date')}
    </> : command === 'add_rtw' || command === 'edit_rtw' ? <>
        {area('workplace_modifications', 'Workplace modifications', 5000)}{check('worker_agreed', 'Worker agreement recorded')}{field('worker_agreed_at', 'Worker agreement date', 'date')}{area('worker_agreement_notes', 'Worker agreement notes')}
        {field('medical_clearance_provider', 'Reported clearance provider', 'text', false, 255)}{field('medical_clearance_date', 'Reported clearance date', 'date')}{area('medical_clearance_notes', 'Reported clearance notes')}
        {field('next_review_date', 'Next review date', 'date')}{area('review_notes', 'Review notes')}
    </> : command === 'add_capacity' ? <>
        {area('restrictions', 'Reported restrictions', 5000)}{area('recommendations', 'Recommendations', 5000)}{field('next_assessment_date', 'Next assessment date', 'date')}
        {pickers && 'documents' in pickers && pickers.documents.access === 'readable' ? select('hr_document_id', 'Assessment document', [{ value: '', label: 'No document linked' }, ...(pickers.documents.items ?? []).filter((item) => item.id !== null).map((item) => ({ value: String(item.id), label: item.title ?? 'Assessment document' }))]) : <p className="text-subtle">Medical document choices require separate HR document access.</p>}
    </> : command === 'add_duty' ? <>{area('restrictions', 'Reported restrictions')}{area('accommodations', 'Workplace accommodations')}</> : command === 'upload_evidence' ? <>
        {select('kind', 'Evidence type', [{ value: 'medical_cert', label: 'Medical certificate' }, { value: 'acc_form', label: 'ACC form' }, { value: 'rtw_clearance', label: 'Return-to-work clearance source' }, { value: 'photo', label: 'Photo' }, { value: 'document', label: 'Document' }])}{area('notes', 'Evidence notes')}{field('alt_text', 'Image description', 'text', false, 255)}
    </> : null;
    const simple = command === 'assign' ? <>{person('coordinator_user_id', 'Incident owner', 'coordinators')}{field('next_review_date', 'Next review date', 'date', true)}{area('reason', 'Handover reason', 2000, true)}</> : command === 'review' ? <>
        <DrillTimeField label="Review occurred" value={f.reviewed_at} offset={f.review_offset} onChange={(value, offset) => { change('reviewed_at', value); change('review_offset', offset); }} />{field('next_review_date', 'Next review date', 'date', true)}{area('notes', 'Review findings and next steps', 5000, true)}
    </> : command === 'acc' ? <>{check('acc_claim_lodged', 'Claim lodged — recorded internally')}{field('acc_claim_number', 'Claim number', 'text', false, 50)}<p className="text-subtle">This records the claim fact supplied to you. External delivery and acknowledgement remain separate.</p></> : <>
        {command === 'remove_evidence' && <p className="text-subtle">Remove {d?.attachments?.find((item) => item.id === selected?.attachment)?.original_name ?? 'this evidence'} from the active record. Its source history is retained.</p>}
        {area('reason', command === 'remove_evidence' ? 'Removal reason' : 'Reason and outcome', 2000, true)}
        {command === 'mark_recovered' && <p className="text-subtle">Record recovery coordination status. Record an actual return date separately when it is known.</p>}
        {command === 'close' && <p className="text-subtle">Closing this injury retains its history. Review connected investigation, claim, restrictions and actions separately.</p>}
    </>;
    const finish = () => onDone(source.confirmedHref);
    const currentFacts = checked ? [{ label: 'Current record version', value: String(checked.source_version) },
        { label: 'Current status', value: typeof checked.status === 'string' ? checked.status.replaceAll('_', ' ') : null },
        { label: 'Current recorded Site', value: optionsObject(checked.site) && typeof checked.site.name === 'string' ? checked.site.name : null },
        { label: 'Current injured worker', value: optionsObject(checked.worker) && typeof checked.worker.name === 'string' ? checked.worker.name : null },
        { label: 'Current recorded description', value: typeof checked.description === 'string' ? checked.description : null },
        { label: 'Current next review', value: optionsObject(checked.coordination) && typeof checked.coordination.next_review_date === 'string' ? formatDateOnly(checked.coordination.next_review_date) : null }] : undefined;
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
    const saveButton = <Button type="button" className="frontline-tap" disabled={busy || !source.allowed || source.stale || source.checkFailed || privateCommandBlocked || correctionIncomplete || (needsPickers && (!pickers || !!pickerFailure))} onClick={submit}>{form.processing ? 'Saving…' : 'Save and confirm'}</Button>;
    const errors = Object.entries(form.errors as Record<string, string>);
    const notices: ReactNode = <>
        {command === 'create' && !recordReportedDetails && <p role="alert" className="text-subtle text-status-warning">Reported detail entry is unavailable with your current access. Your draft is retained; check current access before saving.</p>}
        {(privateCommandBlocked || (command === 'edit' && d?.medical_access === 'readable' && !medical)) && <p role="alert" className="text-subtle text-status-warning">Private injury details are unavailable with your current access. Your draft is retained; check current access before continuing.</p>}
        {pickerFailure && <div className="space-y-3"><p role="alert" className="text-subtle text-status-critical">{pickerFailure}</p><Button type="button" className="frontline-tap" variant="outline" disabled={busy} onClick={() => setPickerRetry(pickerRetry + 1)}>Retry choices</Button></div>}
        {errors.length > 0 && <div role="alert" className="space-y-2 rounded-xl border border-status-critical bg-status-critical-bg p-4"><p className="font-medium">Check these entries</p><ul className="list-inside list-disc text-subtle">{errors.map(([key, message]) => <li key={key}>{message}</li>)}</ul></div>}
        {recovery && <p className="text-subtle">Record the supplied assessment or recovery arrangement. Operational restrictions need a separate authorised Workforce decision before they affect scheduling.</p>}
    </>;
    const requested = privateCommandBlocked ? {} : injuryCommandRequest(command, f, commandRecord && correctingSite ? { ...commandRecord, site: { id: canonicalSiteId ?? 0, name: commandRecord.site?.name ?? '' } } : commandRecord, selected, recordReportedDetails).values;
    const identityReview = Object.entries(requested).filter(([key]) => key.endsWith('_id')).map(([key, value]) => {
        let name: string | null = null;
        if (key === 'site_id') name = siteChoices.find((site) => site.id === value)?.name ?? null;
        else if (key === 'related_incident_id') name = incidentChoices.find((incident) => incident.id === value)?.label ?? (d?.related_incident && d.related_incident.id === value ? d.related_incident.label : null);
        else if (key === 'hr_document_id') name = pickers && 'documents' in pickers ? pickers.documents.items?.find((document) => document.id === value)?.title ?? null : null;
        else if (selectedPeople[key]?.id === value) name = selectedPeople[key].name;
        return { key, name: value == null ? 'Not selected' : name ?? 'Selected record needs confirmation' };
    });
    const reviewValue = (key: string, value: unknown): string => {
        if (value == null || value === '') return 'Not recorded';
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        if (typeof value === 'string' && /^\d{4}-\d{2}-\d{2}$/.test(value)) return formatDateOnly(value);
        if (typeof value === 'string' && ['injury_date', 'reviewed_at'].includes(key)) return formatDateTimeLong(value);
        if (Array.isArray(value)) return value.map((item) => typeof item === 'string' ? item : Object.entries(item).map(([field, part]) => `${field.replaceAll('_', ' ')}: ${reviewValue(field, part)}`).join(' · ')).join('\n');
        return String(value);
    };
    const review = <div className="space-y-4"><h2 className="text-section-title">Review the recorded change</h2><p className="text-subtle">{title} for {selectedPeople.user_id?.id === Number(f.user_id) ? selectedPeople.user_id.name : d?.worker?.name ?? 'the selected worker'}. Your entries remain in this form until saving is confirmed.</p><dl className="space-y-3">{identityReview.map(({ key, name }) => <div key={key}><dt className="text-caption text-muted-foreground">{({ user_id: 'Injured worker', site_id: 'Recorded Site', coordinator_user_id: 'Incident owner', manager_id: 'Recovery coordinator', assessor_user_id: 'Internal assessor', related_incident_id: 'Connected incident', hr_document_id: 'Assessment document' } as Record<string, string>)[key] ?? key.replaceAll('_', ' ')}</dt><dd className="text-subtle break-words">{name}</dd></div>)}{Object.entries(requested).filter(([key]) => !key.endsWith('_id') && key !== 'file').map(([key, value]) => <div key={key}><dt className="text-caption text-muted-foreground">{key.replaceAll('_', ' ')}</dt><dd className="text-subtle break-words whitespace-pre-wrap">{reviewValue(key, value)}</dd></div>)}</dl>{f.file && <p>{f.file.name}</p>}</div>;
    if (WIZARD_COMMANDS.includes(command)) return <WizardShell frontline open onClose={onCancel} title={title} description="Record the facts, review your entries and confirm saving." railIcon={HeartPulse} railTitle={title} railSub={d?.reference ?? 'New injury'} steps={STEPS.map((item) => ({ ...item, disabled: busy }))} stepIndex={step} onStepClick={setStep} pct={Math.round((step + 1) / 3 * 100)} pctLabel="Review progress" footerStart={<Button type="button" variant="outline" className="frontline-tap" disabled={form.processing || source.checking} onClick={onCancel}>Cancel</Button>} footerEnd={source.receipt ? null : <>{step > 0 && <Button type="button" className="frontline-tap" variant="outline" disabled={busy} onClick={() => setStep(step - 1)}>Back</Button>}{step < 2 ? <Button type="button" className="frontline-tap" disabled={busy} onClick={() => setStep(step + 1)}>Review next section</Button> : saveButton}</>} success={source.receipt ? status : undefined}>
        <div className="space-y-5">{notices}{!privateCommandBlocked && (step === 0 ? facts : step === 1 ? supporting : review)}{status}</div>
    </WizardShell>;
    return <div className="space-y-5"><h2 className="text-section-title">{title}</h2>{notices}{!source.receipt && !privateCommandBlocked && simple}{status}{!source.receipt && <div className="flex flex-wrap gap-3">{saveButton}<Button type="button" variant="outline" className="frontline-tap" disabled={form.processing || source.checking} onClick={onCancel}>Cancel</Button></div>}{source.receipt && !source.confirmedHref && <Link className="frontline-tap frontline-focus inline-flex items-center rounded-md text-subtle" href={returnTo}>Return to your work</Link>}</div>;
}
