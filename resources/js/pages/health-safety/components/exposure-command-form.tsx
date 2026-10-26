import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import { formatDateTime, WORKER_TIMEZONE } from '@/lib/datetime';
import { CHEMICAL_EXPOSURES, type ExposureAction } from '@/lib/health-safety/chemical-exposure-navigation';
import { EXPOSURE_FORM_LABELS, EXPOSURE_REPORTED_FACTS, EXPOSURE_TREATMENTS, EXPOSURE_TYPES, exposureFactText, exposureFormPayload, exposureInitialValues, exposureTimeChoices, type ExposureFormValues } from '@/lib/health-safety/chemical-exposure-form';
import type { ExposureCapture, ExposureEvidence, ExposureFollowup, ExposureReportedFact, ExposureWorkspaceDetail } from '@/lib/health-safety/chemical-exposure-read-contract';
import axios from 'axios';
import { ClipboardCheck, LockKeyhole } from 'lucide-react';
import type { ReactNode } from 'react';
import { useEffect, useId, useRef, useState } from 'react';
import { useExposureChoices } from './use-exposure-choices';
import { useExposureCommand, type ExposureCommand, type ExposureCommandTarget } from './use-exposure-command';

type Action = ExposureAction | 'capture';
type Spec = { key: string; label: string; required?: boolean; multiline?: boolean; time?: boolean; max?: number };
const facts: Spec[] = [{ key: 'exposed_at', label: 'Exposure date and time', required: true, time: true },
    { key: 'exposure_duration', label: 'Recorded duration', max: 255 }, { key: 'circumstances', label: 'What happened', multiline: true, max: 5000 },
    { key: 'symptoms', label: 'Reported symptoms', multiline: true, max: 2000 }, { key: 'first_aid_given', label: 'First aid given', multiline: true, max: 2000 },
    { key: 'medical_outcome', label: 'Recorded medical outcome', multiline: true, max: 2000 }];
const specs = (action: Action): Spec[] => action === 'capture' || action === 'correct' ? [...facts,
    ...(action === 'correct' ? [{ key: 'reason', label: 'Reason for this correction', required: true, multiline: true, max: 2000 }] : [])] :
    action === 'review' ? [{ key: 'reviewed_at', label: 'Reviewed at', required: true, time: true }, { key: 'notes', label: 'Review notes', required: true, multiline: true, max: 5000 }] :
    action === 'assign_followup' ? [{ key: 'instruction', label: 'What needs to happen', required: true, multiline: true, max: 5000 }, { key: 'due_at', label: 'Due at', required: true, time: true }] :
    action === 'reassign_followup' ? [{ key: 'reason', label: 'Reason for this transfer', required: true, multiline: true, max: 2000 }] :
    action === 'complete_followup' ? [{ key: 'completed_at', label: 'Completed at', required: true, time: true }, { key: 'completion_notes', label: 'Completion notes', required: true, multiline: true, max: 5000 }] :
    action === 'upload_evidence' ? [{ key: 'description', label: 'Evidence description', multiline: true, max: 2000 }] :
    [{ key: 'reason', label: 'Reason for removing this evidence', required: true, multiline: true, max: 2000 }];
const label = (value: string) => value.replaceAll('_', ' ');
const FIELD_LABELS: Record<string, string> = { user_id: 'Worker involved', site_id: 'Site where this happened', exposed_at: 'Exposure date and time',
    exposure_type: 'Exposure route', exposure_duration: 'Recorded duration', circumstances: 'What happened', symptoms: 'Reported symptoms',
    first_aid_given: 'First aid given', medical_attention_sought: 'Medical attention sought', medical_treatment: 'Recorded treatment',
    medical_outcome: 'Recorded medical outcome', incident_reported: 'Incident reported', related_incident_id: 'Related incident',
    reason: 'Reason for this change', reviewed_at: 'Reviewed at', outcome: 'Review outcome', notes: 'Review notes', assigned_to: 'Follow-up assignee',
    instruction: 'What needs to happen', due_at: 'Due at', completed_at: 'Completed at', completion_notes: 'Completion notes', file: 'Evidence file', description: 'Evidence description' };
const text = (value: string | File | null | undefined) => value instanceof File ? value.name : value == null || value === '' ? 'Not recorded' : value;
const privateRoot = (d?: ExposureWorkspaceDetail) => !!d && d.exposure_access === 'readable' && d.id === d.record?.id
    && d.source_integrity !== 'repair_required' && d.record.attribution_state !== 'repair_required'
    && d.command_context?.root_type === 'exposure' && d.command_context.root_id === d.id
    && Number.isSafeInteger(d.command_context.expected_version) && d.command_context.expected_version >= 0;
function authority(d: ExposureWorkspaceDetail | undefined, action: Action, sourceId?: number) {
    if (!privateRoot(d) || !d) return false;
    if (action === 'correct') return d.can.correct;
    if (action === 'review') return d.can.review;
    if (action === 'assign_followup') return d.can.assign_followup;
    if (action === 'upload_evidence') return d.can.upload_evidence;
    const row = action === 'complete_followup' || action === 'reassign_followup' ? d.followups.items?.find((item) => item.id === sourceId) : d.attachments.items?.find((item) => item.id === sourceId);
    return !!row && row.attribution_state !== 'repair_required' && (action === 'complete_followup'
        ? 'can_complete' in row && row.can_complete && 'completed_at' in row && row.completed_at === null : action === 'reassign_followup'
            ? 'can_reassign' in row && row.can_reassign && 'completed_at' in row && row.completed_at === null : 'can_remove' in row && row.can_remove && row.attribution_state === 'recorded');
}
/** Inline saved-record commands. The capture page may place this bounded form inside the shared capture shell. */
export function ExposureCommandForm({ action, actorId, detail: suppliedDetail, capture, sourceId, retainedChild, active, onCancel, onDone, onWithdraw, onStep }: {
    action: Action; actorId: number; detail?: ExposureWorkspaceDetail; capture?: ExposureCapture; sourceId?: number;
    retainedChild?: ExposureFollowup | ExposureEvidence; active: boolean; onCancel: () => void; onDone: () => void; onWithdraw?: () => void;
    /** The capture page's header stage line follows the wizard (pg-hsn-130): the current step, and `saved` once confirmed. */
    onStep?: (step: number, saved: boolean) => void;
}) {
    const [values, setValues] = useState<ExposureFormValues>(() => exposureInitialValues(action, suppliedDetail?.record));
    const originalValues = useRef(values);
    const [confirmedFacts, setConfirmedFacts] = useState<ExposureReportedFact[]>([]);
    const [baseline] = useState(() => JSON.stringify(values)); const [version, setVersion] = useState(suppliedDetail?.command_context?.expected_version ?? null);
    const [reviewed, setReviewed] = useState<{ detail: ExposureWorkspaceDetail; observed: number | null } | null>(null);
    const overlay = reviewed && privateRoot(suppliedDetail) && suppliedDetail?.command_context?.expected_version === reviewed.observed
        && suppliedDetail.can.correct ? reviewed.detail : null;
    const detail = overlay ?? suppliedDetail;
    const [localErrors, setLocalErrors] = useState<Record<string, string>>({}); const [step, setStep] = useState(0);
    const [fresh, setFresh] = useState<{ detail: ExposureWorkspaceDetail; observed: number | null } | null>(null);
    const [reviewing, setReviewing] = useState(false); const [reviewFailure, setReviewFailure] = useState<string | null>(null);
    const reviewRequest = useRef<AbortController | null>(null); const id = useId();
    useEffect(() => () => { reviewRequest.current?.abort(); reviewRequest.current = null; }, []);
    const admitted = action === 'capture' ? capture?.can.create === true : privateRoot(detail);
    const permitted = action === 'capture' ? capture?.can.create === true : authority(detail, action, sourceId);
    const needsChoices = ['capture', 'correct', 'assign_followup', 'reassign_followup'].includes(action);
    const siteId = action === 'assign_followup' || action === 'reassign_followup' ? String(detail?.record?.site.id ?? '') : String(values.site_id ?? '');
    const choices = useExposureChoices({ actorId, rootId: detail?.id, sourceRevision: detail?.command_context?.expected_version, substanceId: capture?.substance.id, siteId,
        enabled: active && admitted && permitted && needsChoices, onWithdraw });
    const stale = action !== 'capture' && detail?.command_context?.expected_version !== version;
    const child = retainedChild;
    const base = `${CHEMICAL_EXPOSURES}/${detail?.id ?? 0}`;
    const commandName: Record<Action, ExposureCommand> = { capture: 'create_exposure', correct: 'correct_exposure', review: 'review_exposure',
        assign_followup: 'assign_followup', reassign_followup: 'reassign_followup', complete_followup: 'complete_followup', upload_evidence: 'upload_evidence', remove_evidence: 'remove_evidence' };
    const path = action === 'capture' ? `/health-safety/substances/${capture?.substance.id ?? 0}/exposures` : action === 'correct' ? base :
        action === 'review' ? `${base}/reviews` : action === 'assign_followup' ? `${base}/followups` : action === 'complete_followup'
            ? `${base}/followups/${sourceId ?? 0}/complete` : action === 'reassign_followup' ? `${base}/followups/${sourceId ?? 0}/reassign` : action === 'upload_evidence' ? `${base}/evidence` : `${base}/evidence/${sourceId ?? 0}`;
    const target: ExposureCommandTarget = { actorId, command: commandName[action], rootId: action === 'capture' ? null : detail?.id ?? null,
        substanceId: capture?.substance.id ?? detail?.record?.substance.id ?? null,
        sourceType: action === 'review' ? 'review' : ['assign_followup', 'reassign_followup', 'complete_followup'].includes(action) ? 'followup' : ['upload_evidence', 'remove_evidence'].includes(action) ? 'evidence' : 'exposure',
        sourceId: action === 'correct' ? detail?.id ?? null : ['reassign_followup', 'complete_followup', 'remove_evidence'].includes(action) ? sourceId ?? null : null,
        expectedVersion: action === 'capture' ? null : version, method: ['correct', 'reassign_followup', 'complete_followup'].includes(action) ? 'put' : action === 'remove_evidence' ? 'delete' : 'post', path,
        allowed: admitted && permitted && !stale && (!needsChoices || !!choices.choices),
        // An accepted removal/completion can disappear from active children. Original receipt recovery stays separate from a new write.
        canRecover: action === 'capture' ? capture?.can.create === true : privateRoot(detail) && !!detail?.can.correct };
    const command = useExposureCommand(target, baseline !== JSON.stringify({ ...values, file: null }) || values.file !== null || confirmedFacts.length > 0);
    const editingLocked = command.busy || command.uncertain || command.withdrawn || choices.withdrawn || !!command.receipt || !admitted || !permitted;
    useEffect(() => { if (command.withdrawn) onWithdraw?.(); }, [command.withdrawn, onWithdraw]);
    useEffect(() => { if (command.rejected) setStep(0); }, [command.rejected]);
    useEffect(() => { onStep?.(step, !!command.receipt); }, [step, command.receipt, onStep]);
    const set = (key: string, value: string | File | null) => { setValues((current) => ({ ...current, [key]: value })); setLocalErrors((current) => ({ ...current, [key]: '' })); };
    const fields = specs(action); const errors = { ...localErrors, ...command.errors };
    const prepare = () => {
        if (command.locked || !target.allowed) return;
        const next = exposureFormPayload(action, values, originalValues.current, confirmedFacts); const invalid = { ...next.errors };
        for (const field of fields) if (field.required && !String(values[field.key] ?? '').trim()) invalid[field.key] = 'Record this before continuing.';
        if (action === 'capture' || action === 'correct') {
            for (const key of ['site_id', 'user_id', 'exposure_type']) if (!String(values[key] ?? '')) invalid[key] = 'Choose the recorded value.';
            if (!EXPOSURE_TYPES.includes(String(values.exposure_type ?? ''))) invalid.exposure_type = 'Choose a supported exposure route.';
            if (values.medical_treatment && values.medical_treatment !== 'unknown' && !EXPOSURE_TREATMENTS.includes(String(values.medical_treatment))) invalid.medical_treatment = 'Choose a supported treatment level.';
            if (action === 'correct' && values.site_id !== String(detail?.record?.site.id) && values.association_mode === 'keep'
                && detail?.record?.incident_connection.state !== 'absent') invalid.related_incident_id = 'Explicitly review the incident association before changing the Site.';
        }
        if (action === 'review' && !values.outcome) invalid.outcome = 'Choose the recorded review outcome.';
        if (['assign_followup', 'reassign_followup'].includes(action) && !values.assigned_to) invalid.assigned_to = 'Choose an eligible assignee.';
        if (action === 'reassign_followup') {
            const current = detail?.followups.items?.find((item) => item.id === sourceId);
            if (current && 'assigned_to' in current && String(current.assigned_to?.id) === values.assigned_to) invalid.assigned_to = 'Choose a different eligible assignee.';
        }
        if (action === 'upload_evidence' && !(values.file instanceof File)) invalid.file = 'Choose the evidence file.';
        setLocalErrors(invalid); if (!Object.values(invalid).some(Boolean)) setStep(1);
    };
    const reviewCurrent = async () => {
        if (!privateRoot(detail) || !detail || !detail.can.correct || reviewRequest.current || command.busy || command.receipt || (command.uncertain && !command.canReviewRejected)) return;
        const controller = new AbortController(); reviewRequest.current = controller; setReviewing(true); setReviewFailure(null); setFresh(null);
        try {
            const response = await axios.get(`${CHEMICAL_EXPOSURES}/${detail.id}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
            const next = response.data?.detail as ExposureWorkspaceDetail | undefined;
            if (!privateRoot(next) || next?.id !== detail.id) { setReviewFailure('The current private source could not be confirmed. Your entries are retained.'); onWithdraw?.(); return; }
            if (!authority(next, action, sourceId)) { setReviewFailure('This change is unavailable on the current source. Your entries are retained.'); return; }
            setFresh({ detail: next, observed: suppliedDetail?.command_context?.expected_version ?? null });
        } catch (error) {
            if (!controller.signal.aborted) { setReviewFailure('Current facts could not be confirmed. Keep the original submission and entries.');
                if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) onWithdraw?.(); }
        } finally { if (reviewRequest.current === controller) { reviewRequest.current = null; if (!controller.signal.aborted) setReviewing(false); } }
    };
    const adopt = () => {
        if (!fresh || !privateRoot(suppliedDetail) || suppliedDetail?.command_context?.expected_version !== fresh.observed
            || !suppliedDetail.can.correct || !authority(fresh.detail, action, sourceId) || command.busy || (command.uncertain && !command.canReviewRejected)) return;
        if (command.uncertain && !command.resetReviewedRejection()) return;
        setReviewed(fresh); setVersion(fresh.detail.command_context!.expected_version); setFresh(null); setStep(0); choices.refresh();
    };
    const select = (key: string, name: string, options: { value: string; label: string }[], required = false, placeholder = 'Choose…') => <Field key={key} label={name} required={required} error={errors[key]}>
        <SelectInput value={String(values[key] ?? '')} onChange={(value) => {
            set(key, value);
            if (key === 'site_id') { set('user_id', ''); set('related_incident_id', ''); set('association_mode', action === 'capture' ? 'absent' : 'keep'); choices.resetPages(); }
        }} options={options} placeholder={placeholder} disabled={editingLocked} frontline ariaLabel={name} />
    </Field>;
    const reportedFact = (key: ExposureReportedFact, name: string, options: { value: string; label: string }[]) => <div key={key} className="space-y-2">
        {select(key, name, [{ value: 'unknown', label: 'Unknown' }, ...options], false, 'Unknown · not answered')}
        {action === 'correct' && detail?.record && <>
            <p className="text-caption text-muted-foreground">Current record: {exposureFactText(detail.record, key)}</p>
            <label htmlFor={id + '-confirm-' + key} className="frontline-tap flex cursor-pointer items-center gap-3 text-subtle">
                <Checkbox id={id + '-confirm-' + key} className="frontline-hit" disabled={editingLocked}
                    checked={confirmedFacts.includes(key)} onCheckedChange={(checked) => setConfirmedFacts((current) => checked === true
                        ? [...current.filter((field) => field !== key), key] : current.filter((field) => field !== key))} />
                Confirm {name.toLowerCase()} for this correction
            </label>
        </>}
    </div>;
    const currentChoices = choices.choices; const sites = currentChoices?.sites ?? capture?.sites ?? (detail?.record ? [detail.record.site] : []);
    const staff = ['assign_followup', 'reassign_followup'].includes(action) && currentChoices && 'receivers' in currentChoices ? currentChoices.receivers : currentChoices?.staff;
    const staffOptions = (staff?.items ?? []).map((row) => ({ value: String(row.id), label: row.name }));
    const pinnedWorker = detail?.record?.worker;
    if (action === 'correct' && pinnedWorker && values.user_id === String(pinnedWorker.id) && values.site_id === String(detail?.record?.site.id)
        && !staffOptions.some((row) => row.value === String(pinnedWorker.id))) staffOptions.unshift({ value: String(pinnedWorker.id), label: pinnedWorker.name + ' · retained recorded worker' });
    const optionIncident = currentChoices?.incidents;
    const visibleKeys = new Set([...fields.map((field) => field.key),
        ...(['capture', 'correct'].includes(action) ? ['site_id', 'user_id', 'exposure_type', 'medical_treatment', 'medical_attention_sought', 'incident_reported', 'association_mode',
            ...(values.association_mode === 'selected' ? ['related_incident_id'] : [])] : []), ...(action === 'review' ? ['outcome'] : []),
        ...(['assign_followup', 'reassign_followup'].includes(action) ? ['assigned_to'] : []), ...(action === 'upload_evidence' ? ['file'] : [])]);
    const reviewValue = (key: string, value: unknown) => {
        if (EXPOSURE_REPORTED_FACTS.includes(key as ExposureReportedFact) && value === null) return 'Unknown';
        if (key === 'user_id') return staffOptions.find((row) => row.value === String(value))?.label ?? 'Worker reference ' + value;
        if (key === 'assigned_to') return staffOptions.find((row) => row.value === String(value))?.label ?? 'Assignee reference ' + value;
        if (key === 'site_id') return sites.find((row) => row.id === Number(value))?.name ?? 'Site reference ' + value;
        if (key === 'related_incident_id') return value === null ? 'No related incident retained' : optionIncident?.items?.find((row) => row.id === Number(value))?.reference ?? 'Incident reference ' + value;
        if (typeof value === 'boolean') return value ? 'Yes' : 'No';
        if (key.endsWith('_at') && typeof value === 'string') return formatDateTime(value);
        return text(value as string | File | null);
    };
    const formId = id + '-form';
    const controls = <div className="flex flex-wrap gap-3">
        {step === 1 && <Button type="button" variant="outline" className="frontline-tap" disabled={command.busy || command.uncertain} onClick={() => setStep(0)}>Edit entries</Button>}
        <Button type="submit" form={formId} className="frontline-tap" disabled={command.locked || !target.allowed}>{command.busy ? 'Recording…' : step === 0 ? 'Review submission' : 'Confirm and record'}</Button>
        {command.canCheck && <Button type="button" variant="outline" className="frontline-tap" onClick={() => void command.check()}>Check original submission</Button>}
        {command.canRetry && <Button type="button" variant="outline" className="frontline-tap" onClick={command.retry}>Retry exact submission</Button>}
        {command.canEditRejected && <Button type="button" variant="outline" className="frontline-tap" onClick={() => { if (command.editRejected()) setStep(0); }}>Correct rejected entries</Button>}
        {action !== 'capture' && admitted && !command.busy && (!command.uncertain || command.canReviewRejected) &&
            <Button type="button" variant="outline" className="frontline-tap" disabled={reviewing} onClick={() => void reviewCurrent()}>{reviewing ? 'Checking current facts…' : 'Review current record'}</Button>}
    </div>;
    const formVisible = admitted && !command.withdrawn && !choices.withdrawn && !command.receipt;
    const wrap = (content: ReactNode) => action === 'capture' ? <WizardShell frontline open={active} onClose={onCancel}
        title="Record a private exposure" description="Record what happened, review your entries and confirm the original submission."
        railIcon={LockKeyhole} railTitle="Private exposure" railSub={capture?.substance.name ?? 'Chemical exposure'}
        steps={[{ key: 'facts', label: 'Recorded facts', blurb: 'Worker, Site and reported exposure', icon: LockKeyhole },
            { key: 'review', label: 'Review submission', blurb: 'Check before recording', icon: ClipboardCheck, disabled: step === 0 }]}
        stepIndex={step} onStepClick={(next) => { if (next === 0 && !editingLocked) setStep(0); }}
        footerStart={formVisible ? <Button type="button" variant="outline" className="frontline-tap" onClick={onCancel}>Cancel</Button> : undefined}
        footerEnd={formVisible ? controls : undefined}>
        {content}</WizardShell> : content;
    if (!active) return null;
    if (!admitted || command.withdrawn || choices.withdrawn) return wrap(<div role="status" className="space-y-3"><h2 className="text-section-title">Current access unavailable</h2>
        <p className="text-subtle text-status-warning">Private fields and changes are withheld. The original in-memory submission has not been replaced or confirmed.</p>
        <Button type="button" variant="outline" className="frontline-tap" onClick={onCancel}>Return to connected work</Button></div>);
    if (command.receipt) return wrap(<div role="status" className="space-y-4"><h2 className="text-section-title">{command.receipt.changed ? 'Recorded submission confirmed' : 'No change needed · confirmed'}</h2>
        <p className="text-subtle">This confirms the original submitted command. Delivery, medical recovery and physical file cleanup are checked separately.</p>
        {command.href && action === 'capture' && <Button asChild className="frontline-tap"><a href={command.href}>Open private exposure record</a></Button>}
        <Button type="button" variant="outline" className="frontline-tap" onClick={onDone}>{action === 'capture' ? 'Return to connected work' : 'View current record'}</Button></div>);
    const childCommand = ['reassign_followup', 'complete_followup', 'remove_evidence'].includes(action);
    const currentChild = action === 'remove_evidence' ? detail?.attachments.items?.find((item) => item.id === sourceId) : detail?.followups.items?.find((item) => item.id === sourceId);
    if (childCommand && (!currentChild || currentChild.attribution_state === 'repair_required')) return <div role="status" className="space-y-3">
        <h2 className="text-section-title">This saved source is unavailable</h2><p className="text-subtle text-status-warning">Child details and retained private entries are withheld. The original submission remains in memory; this is not confirmation of a saved change.</p>
        {command.message && <p className="text-subtle">{command.message}</p>}
        <div className="flex flex-wrap gap-3"><Button type="button" variant="outline" className="frontline-tap" onClick={onCancel}>Return to this record</Button>
            {command.canCheck && <Button type="button" variant="outline" className="frontline-tap" onClick={() => void command.check()}>Check original submission</Button>}
            {command.canRetry && <Button type="button" variant="outline" className="frontline-tap" onClick={command.retry}>Retry exact submission</Button>}</div></div>;
    return wrap(<section aria-labelledby={id + '-title'} className="space-y-5 border-t border-border pt-5">
        <h2 id={id + '-title'} className="text-section-title">{EXPOSURE_FORM_LABELS[action]}</h2>
        {child && <p className="text-subtle">{action === 'complete_followup' && 'instruction' in child ? child.instruction : 'original_name' in child ? child.original_name : null}</p>}
        {action === 'reassign_followup' && currentChild && 'assigned_to' in currentChild && <p className="text-subtle">Currently assigned to {currentChild.assigned_to?.name ?? 'an unconfirmed assignee'}. The instruction and due time stay on the same follow-up; its earlier assignment remains in history.</p>}
        {command.message && <p role="status" className="text-subtle text-status-warning">{command.message}</p>}
        {(stale || !permitted) && <p role="status" className="text-subtle text-status-warning">The current record or authority has changed. Keep your entries and review current facts before a new submission.</p>}
        {choices.error && <p role="status" className="text-subtle text-status-warning">{choices.error}</p>}
        {Object.entries(errors).filter(([key, value]) => value && (step !== 0 || !visibleKeys.has(key)))
            .map(([key, value]) => <p role="alert" key={key} className="text-subtle text-status-critical">{value}</p>)}
        <form id={formId} onSubmit={(event) => { event.preventDefault(); if (step === 0) prepare(); else { const next = exposureFormPayload(action, values, originalValues.current, confirmedFacts);
            if (!Object.values(next.errors).some(Boolean)) command.submit(next.payload, action === 'upload_evidence'); else { setLocalErrors(next.errors); setStep(0); } } }}>
            {step === 0 ? <fieldset disabled={editingLocked} className="space-y-5"><legend className="sr-only">{EXPOSURE_FORM_LABELS[action]} details</legend>
                {(action === 'capture' || action === 'correct') && <div className="grid gap-4 sm:grid-cols-2">
                    {select('site_id', 'Site where this happened', sites.map((row) => ({ value: String(row.id), label: row.name })), true)}
                    {select('user_id', 'Worker involved', staffOptions, true)}
                    {select('exposure_type', 'Exposure route', (capture?.options.exposure_types ?? EXPOSURE_TYPES).map((value) => ({ value, label: label(value) })), true)}
                </div>}
                {needsChoices && siteId && <div className="space-y-3"><Field label={['assign_followup', 'reassign_followup'].includes(action) ? 'Find an eligible assignee' : 'Find a worker'}>
                    <Input className="frontline-tap" value={choices.search} maxLength={200} onChange={(event) => choices.setSearch(event.target.value)} /></Field>
                    {choices.loading && <p role="status" className="text-subtle">Checking current choices…</p>}
                    {staff?.next_cursor && <Button type="button" variant="outline" className="frontline-tap" onClick={() => choices.moreStaff(staff.next_cursor!)}>Next staff choices</Button>}
                    <Button type="button" variant="outline" className="frontline-tap" onClick={choices.refresh}>Check current choices</Button>
                </div>}
                {['assign_followup', 'reassign_followup'].includes(action) && select('assigned_to', action === 'reassign_followup' ? 'New named assignee' : 'Named assignee', staffOptions, true)}
                {action === 'review' && select('outcome', 'Review outcome', [{ value: 'recorded', label: 'Review recorded' }, { value: 'followup_required', label: 'Follow-up required' }], true)}
                <div className="grid gap-4 sm:grid-cols-2">{fields.map((field) => <Field key={field.key} label={field.label} required={field.required} error={errors[field.key]} span={field.multiline}
                    htmlFor={field.time ? id + '-' + field.key : undefined} errorId={id + '-' + field.key + '-error'}>
                    {field.multiline ? <Textarea className="frontline-tap" rows={3} maxLength={field.max} value={String(values[field.key] ?? '')} onChange={(event) => set(field.key, event.target.value)} /> :
                        field.time ? <div className="space-y-2"><Input id={id + '-' + field.key} aria-label={field.label} aria-invalid={!!errors[field.key]}
                            aria-describedby={errors[field.key] ? id + '-' + field.key + '-error' : undefined} type="datetime-local" step={1} className="frontline-tap" value={String(values[field.key] ?? '')}
                            onChange={(event) => { set(field.key, event.target.value); set(field.key + '_offset', ''); }} />
                            <p className="text-caption text-muted-foreground">{WORKER_TIMEZONE} · saved with the chosen clock offset</p>
                            {exposureTimeChoices(String(values[field.key] ?? '')).length > 1 && select(field.key + '_offset', 'Which occurrence of this clock time?', exposureTimeChoices(String(values[field.key] ?? '')).map((offset) => ({ value: offset, label: offset })), true)}
                        </div> : <Input className="frontline-tap" maxLength={field.max} value={String(values[field.key] ?? '')} onChange={(event) => set(field.key, event.target.value)} />}
                </Field>)}</div>
                {(action === 'capture' || action === 'correct') && <div className="space-y-4"><div className="grid gap-4 sm:grid-cols-2">
                    {reportedFact('medical_treatment', 'Recorded treatment', (capture?.options.medical_treatments ?? EXPOSURE_TREATMENTS).map((value) => ({ value, label: label(value) })))}
                    {reportedFact('medical_attention_sought', 'Medical attention sought', [{ value: '0', label: 'No' }, { value: '1', label: 'Yes' }])}
                    {reportedFact('incident_reported', 'Incident reported', [{ value: '0', label: 'No' }, { value: '1', label: 'Yes' }])}
                    {select('association_mode', 'Related incident association', [...(action === 'correct' ? [{ value: 'keep', label: 'Keep the original association unchanged' }] : []),
                        { value: 'absent', label: 'No related incident' }, { value: 'selected', label: 'Choose an accessible incident' }], true)}
                </div>
                    <p className="text-subtle">Record each answer separately. If you do not know, leave it unanswered or choose Unknown. A treatment entry does not answer whether medical attention was sought.</p>
                    {values.association_mode === 'selected' && <div className="space-y-3"><Field label="Find an incident reference"><Input className="frontline-tap" value={choices.incidentSearch}
                        maxLength={200} onChange={(event) => choices.setIncidentSearch(event.target.value)} /></Field>
                        {optionIncident?.access === 'restricted' ? <p role="status" className="text-subtle text-status-warning">Separate incident and Client access is required to select an association.</p> :
                            select('related_incident_id', 'Related incident', (optionIncident?.items ?? []).map((row) => ({ value: String(row.id), label: `${row.reference} · ${row.client.name}` })), true)}
                        {optionIncident?.page && <div className="flex flex-wrap gap-3"><Button type="button" variant="outline" className="frontline-tap" disabled={optionIncident.page.current_page <= 1}
                            onClick={() => choices.setIncidentPage(choices.incidentPage - 1)}>Previous incident choices</Button><Button type="button" variant="outline" className="frontline-tap" disabled={!optionIncident.page.has_more}
                            onClick={() => choices.setIncidentPage(choices.incidentPage + 1)}>Next incident choices</Button></div>}
                    </div>}
                </div>}
                {action === 'upload_evidence' && <Field label="Evidence file" required error={errors.file} labelId={id + '-file-label'} errorId={id + '-file-error'}>
                    <div className="space-y-3"><FileDropzone multiple={false} disabled={editingLocked} title="Choose private evidence" hint="Choose a file; saved access is checked separately"
                        aria-labelledby={id + '-file-label'} aria-describedby={errors.file ? id + '-file-error' : undefined} aria-invalid={!!errors.file}
                        onFiles={(files) => set('file', files[0] ?? null)} />
                        <p className="text-subtle">{values.file instanceof File ? values.file.name : 'No file selected'}</p>
                        {values.file instanceof File && <Button type="button" variant="outline" className="frontline-tap" disabled={editingLocked} onClick={() => set('file', null)}>Clear selected file</Button>}
                    </div></Field>}
            </fieldset> : <ReviewCard title="Review this submission" icon={ClipboardCheck}><p className="text-subtle">Check the entered facts before recording. This submission does not confirm medical recovery or delivery to another team.</p>
                {action === 'correct' && <><p className="text-subtle">The saved exposure time is kept unless you change it.</p><p className="text-subtle">Unchanged answers are kept with their earlier history. Only changed or explicitly confirmed answers are recorded again.</p></>}
                {Object.entries(exposureFormPayload(action, values, originalValues.current, confirmedFacts).payload).map(([key, value]) => <ReviewRow key={key} label={FIELD_LABELS[key] ?? label(key)} value={reviewValue(key, value)} />)}
                {action === 'capture' && EXPOSURE_REPORTED_FACTS.filter((key) => values[key] === '').map((key) => <ReviewRow key={key} label={FIELD_LABELS[key]} value="Unknown · not answered" />)}
                {action === 'correct' && values.association_mode === 'keep' && <ReviewRow label="Related incident" value="Keep the retained association; earlier provenance remains unconfirmed where applicable" />}
            </ReviewCard>}
            {action !== 'capture' && <div className="mt-5 flex flex-wrap gap-3"><Button type="button" variant="outline" className="frontline-tap" onClick={onCancel}>Cancel</Button>{controls}</div>}
        </form>
        {reviewFailure && <p role="status" className="text-subtle text-status-warning">{reviewFailure}</p>}
        {/* eslint-disable-next-line no-restricted-syntax -- the review panel sits inside the form's own layout (the form stays as it is). */}
        {fresh?.detail.record && <div className="space-y-4 rounded-lg border border-border bg-card p-4"><h3 className="text-section-title">Current facts for review</h3>
            <p className="text-subtle">{fresh.detail.record.worker.name} · {fresh.detail.record.site.name} · Revision {fresh.detail.command_context?.expected_version}</p>
            <dl className="grid gap-3 sm:grid-cols-2">{Object.entries(fresh.detail.record).filter(([key]) => ['exposed_at', 'exposure_type', 'exposure_duration', 'circumstances', 'symptoms', 'first_aid_given', 'medical_attention_sought', 'medical_treatment', 'medical_outcome', 'incident_reported'].includes(key)).map(([key, value]) =>
                    <div key={key}><dt className="text-caption text-muted-foreground">{FIELD_LABELS[key] ?? label(key)}</dt><dd className="whitespace-pre-wrap break-words text-subtle">{EXPOSURE_REPORTED_FACTS.includes(key as ExposureReportedFact) ? exposureFactText(fresh.detail.record!, key as ExposureReportedFact) : key === 'exposed_at' ? formatDateTime(value as string | null) : typeof value === 'boolean' ? value ? 'Yes' : 'No' : text(value as string | null)}</dd></div>)}</dl>
            <p className="text-subtle">Your entered fields and selected file remain unchanged. Use this revision only after reviewing the current facts and your entries.</p>
            <Button type="button" className="frontline-tap" onClick={adopt}>Use reviewed revision and keep my entries</Button>
        </div>}
    </section>);
}
