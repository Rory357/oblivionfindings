import { Button } from '@/components/ui/button';
import { FileDropzone } from '@/components/ui/file-dropzone';
import { Input } from '@/components/ui/input';
import { Textarea } from '@/components/ui/textarea';
import { Field, SelectInput } from '@/components/wizard/primitives';
import { ReviewCard, ReviewRow, WizardShell } from '@/components/wizard/shell';
import type { ChemicalCapture, ChemicalDetail, ChemicalSdsRecord, ChemicalStorageLocation } from '@/lib/health-safety/chemical-contract';
import { CHEMICAL_REGISTER, type ChemicalAction } from '@/lib/health-safety/chemical-navigation';
import { formatDateOnly } from '@/lib/datetime';
import axios from 'axios';
import { CheckCircle2, ClipboardCheck, FlaskConical, Shield, FileText } from 'lucide-react';
import { useEffect, useId, useRef, useState } from 'react';
import { PHYSICAL_FORMS, QUANTITY_UNITS, STATUS_OPTIONS } from './constants';
import { useChemicalCommand, type ChemicalCommandTarget } from './_use-command';

type Values = Record<string, string | File | null>;
type FieldSpec = { key: string; label: string; multiline?: boolean; required?: boolean; type?: string; options?: { value: string; label: string }[] };
export const CHEMICAL_LABELS: Record<ChemicalAction | 'create', string> = {
    create: 'Add chemical reference', edit: 'Edit chemical reference', status: 'Change reference status', add_sds: 'Add safety data sheet',
    edit_sds: 'Correct sheet details', current_sds: 'Select current sheet', add_storage: 'Add storage location', edit_storage: 'Edit storage location',
    audit_storage: 'Record inventory check', archive_storage: 'Archive storage location', restore_storage: 'Restore storage location',
};
const reference: FieldSpec[] = [
    { key: 'name', label: 'Chemical name', required: true }, { key: 'physical_form', label: 'Physical form', required: true, options: PHYSICAL_FORMS },
    { key: 'common_name', label: 'Common name' }, { key: 'un_number', label: 'UN number' }, { key: 'hsno_approval', label: 'HSNO approval' },
    { key: 'hsno_classification', label: 'HSNO classification' }, { key: 'signal_word', label: 'Signal word' },
    { key: 'hazard_classifications', label: 'Hazard classifications — one per line', multiline: true },
    { key: 'ghs_pictograms', label: 'GHS pictogram codes — one per line', multiline: true },
    ...['is_controlled_substance', 'requires_tracking'].map((key) => ({ key, label: key === 'requires_tracking' ? 'Tracking required' : 'Controlled substance', options: [{ value: '0', label: 'No' }, { value: '1', label: 'Yes' }] })),
];
const controls: FieldSpec[] = [
    { key: 'hazard_statements', label: 'Hazard statements' }, { key: 'precautionary_statements', label: 'Precautionary statements' },
    { key: 'ppe_required', label: 'PPE required' }, { key: 'storage_requirements', label: 'Storage requirements' },
    { key: 'handling_precautions', label: 'Handling precautions' }, { key: 'first_aid_measures', label: 'First aid measures' },
    { key: 'firefighting_measures', label: 'Firefighting measures' }, { key: 'spill_procedures', label: 'Spill procedures' },
].map((field) => ({ ...field, multiline: true }));
controls.push({ key: 'exposure_limit_type', label: 'Exposure limit type' }, { key: 'exposure_limit_value', label: 'Exposure limit value' });
const sheetFields: FieldSpec[] = [{ key: 'version', label: 'Sheet version', required: true }, { key: 'issue_date', label: 'Issue date', required: true, type: 'date' },
    { key: 'review_date', label: 'Review date', type: 'date' }, { key: 'supplier_name', label: 'Supplier name' }, { key: 'supplier_contact', label: 'Supplier contact' }];
const storageFields: FieldSpec[] = [{ key: 'location_description', label: 'Storage location', required: true }, { key: 'current_quantity', label: 'Recorded quantity', type: 'text' },
    { key: 'quantity_unit', label: 'Quantity unit', options: QUANTITY_UNITS }, { key: 'maximum_quantity', label: 'Maximum quantity', type: 'text' },
    { key: 'container_type', label: 'Container type' }, { key: 'last_audit_date', label: 'Last inventory check', type: 'date' },
    { key: 'storage_notes', label: 'Storage notes', multiline: true }];
const booleanFields: FieldSpec[] = ['properly_labelled', 'segregation_compliant'].map((key) => ({ key,
    label: key === 'properly_labelled' ? 'Containers properly labelled' : 'Segregation compliant',
    options: [{ value: 'unknown', label: 'Not recorded' }, { value: '1', label: 'Yes' }, { value: '0', label: 'No' }] }));
const reason: FieldSpec = { key: 'reason', label: 'Reason for this change', required: true, multiline: true };
function specs(action: ChemicalAction | 'create', capture: ChemicalCapture | null): FieldSpec[] {
    if (action === 'create' || action === 'edit') return [...reference, ...controls];
    if (action === 'status') return [{ key: 'status', label: 'Reference status', required: true, options: STATUS_OPTIONS }, reason];
    if (action === 'add_sds' || action === 'edit_sds') return [...sheetFields, ...(action === 'edit_sds' ? [reason] : [])];
    if (action === 'current_sds' || action === 'archive_storage' || action === 'restore_storage') return [reason];
    if (action === 'audit_storage') return [storageFields[1], ...booleanFields.map((field) => ({ ...field, required: true, options: field.options?.filter((option) => option.value !== 'unknown') })),
        { key: 'last_audit_date', label: 'Date checked', required: true, type: 'date' }, storageFields[6]];
    return [{ key: 'site_id', label: 'Site', required: true, options: (capture?.sites ?? []).map((site) => ({ value: String(site.id), label: site.name })) }, ...storageFields, ...booleanFields];
}
function initial(action: ChemicalAction | 'create', detail?: ChemicalDetail, storage?: ChemicalStorageLocation, sheet?: ChemicalSdsRecord): Values {
    const values: Values = {};
    const source = storage ?? sheet ?? detail;
    for (const field of [...reference, ...controls, ...sheetFields, ...storageFields, ...booleanFields, reason, { key: 'status' }]) {
        const value = source ? (source as unknown as Record<string, unknown>)[field.key] : null;
        values[field.key] = Array.isArray(value) ? value.join('\n') : typeof value === 'boolean' ? value ? '1' : '0' : value == null ? '' : String(value);
    }
    values.site_id = storage ? String(storage.site.id) : ''; values.file = null; values.reason = '';
    if (action === 'create') { values.is_controlled_substance = '0'; values.requires_tracking = '0'; }
    for (const field of booleanFields) if (values[field.key] === '') values[field.key] = 'unknown';
    return values;
}
export function chemicalFormPayload(action: ChemicalAction | 'create', values: Values, capture: ChemicalCapture | null): Record<string, unknown> {
    const result: Record<string, unknown> = {};
    for (const field of specs(action, capture)) {
        const value = values[field.key];
        if (field.key === 'hazard_classifications' || field.key === 'ghs_pictograms') result[field.key] = String(value ?? '').split('\n').map((item) => item.trim()).filter(Boolean);
        else if (['is_controlled_substance', 'requires_tracking', 'properly_labelled', 'segregation_compliant'].includes(field.key)) {
            if (value === '0' || value === '1') result[field.key] = value === '1';
        } else result[field.key] = value === '' && !field.required ? null : value;
    }
    if (action === 'add_sds') result.file = values.file;
    return result;
}
export function ChemicalCommandForm({ action, actorId, detail: providedDetail, storage: providedStorage, sheet: providedSheet, capture: supplied, sourceAvailable = true, active, onCancel, onDone, onWithdraw }: {
    action: ChemicalAction | 'create'; actorId: number; detail?: ChemicalDetail; storage?: ChemicalStorageLocation; sheet?: ChemicalSdsRecord;
    capture?: ChemicalCapture; sourceAvailable?: boolean; active: boolean; onCancel: () => void; onDone: () => void; onWithdraw?: () => void;
}) {
    const [capture, setCapture] = useState<ChemicalCapture | null>(supplied ?? null);
    const [reviewedRecord, setReviewedRecord] = useState<{ detail: ChemicalDetail; storage?: ChemicalStorageLocation; sheet?: ChemicalSdsRecord; observedVersion: number | null; observedStorageVersion?: number | null } | null>(null);
    const overlay = reviewedRecord && sourceAvailable && providedDetail && providedDetail.source_integrity !== 'repair_required' && providedDetail.expected_version === reviewedRecord.observedVersion
        && (!providedStorage || (providedStorage.source_integrity !== 'repair_required' && providedStorage.can.manage && providedStorage.expected_version === reviewedRecord.observedStorageVersion))
        && (!providedSheet || providedSheet.source_integrity !== 'repair_required')
        && (['add_sds', 'add_storage'].includes(action) ? providedDetail.can.create : providedDetail.can.manage) ? reviewedRecord : null;
    const detail = overlay?.detail ?? providedDetail; const storage = overlay?.storage ?? providedStorage; const sheet = overlay?.sheet ?? providedSheet;
    const [choicesError, setChoicesError] = useState(false); const [step, setStep] = useState(0);
    const [values, setValues] = useState<Values>(() => initial(action, detail, storage, sheet));
    const [baseline] = useState(() => JSON.stringify(initial(action, detail, storage, sheet)));
    const [versions, setVersions] = useState(() => ({ expected: action === 'create' || action === 'add_storage' ? null :
        action.includes('storage') ? storage?.expected_version ?? null : detail?.expected_version ?? null,
        parent: action.includes('storage') ? detail?.expected_version ?? null : null }));
    const id = useId(); const fields = specs(action, capture); const catalogue = action === 'create' || action === 'edit';
    const needsChoices = action === 'add_storage' || action === 'edit_storage';
    useEffect(() => {
        if (!needsChoices || supplied) return;
        const controller = new AbortController();
        axios.get(`${CHEMICAL_REGISTER}/capture-options`, { headers: { Accept: 'application/json' }, signal: controller.signal }).then(({ data }) => {
            if (data?.can?.create === true && Array.isArray(data.sites) && data.sites.every((site: { id?: unknown; name?: unknown }) => Number.isSafeInteger(site?.id) && typeof site?.name === 'string')) {
                setCapture(data); setChoicesError(false);
            } else setChoicesError(true);
        }).catch(() => { if (!controller.signal.aborted) setChoicesError(true); });
        return () => controller.abort();
    }, [needsChoices, supplied]);
    const create = action === 'create' || ['add_sds', 'add_storage'].includes(action);
    const validSource = sourceAvailable && detail?.source_integrity !== 'repair_required' && (storage ? storage.source_integrity !== 'repair_required' : true) && (sheet ? sheet.source_integrity !== 'repair_required' : true);
    const stale = !!detail && (action.includes('storage') ? detail.expected_version !== versions.parent || (storage && storage.expected_version !== versions.expected) : detail.expected_version !== versions.expected);
    const allowed = action === 'create' ? capture?.can.create === true : !!detail && validSource && !stale &&
        (create ? detail.can.create : detail.can.manage && (storage ? storage.can.manage : true)) && (!needsChoices || !!capture);
    const base = detail ? `${CHEMICAL_REGISTER}/${detail.id}` : CHEMICAL_REGISTER;
    const path = action === 'create' || action === 'edit' ? base : action === 'status' ? `${base}/status` :
        action === 'add_sds' ? `${base}/sds` : action === 'edit_sds' ? `${base}/sds/${sheet?.id}` :
            action === 'current_sds' ? `${base}/sds/${sheet?.id}/current` : action === 'add_storage' ? `${base}/storage-locations` :
                `${base}/storage-locations/${storage?.id}${action === 'audit_storage' ? '/inventory-audit' : action === 'restore_storage' ? '/restore' : ''}`;
    const names: Record<ChemicalAction | 'create', string> = { create: 'create_catalogue', edit: 'update_catalogue', status: 'change_status', add_sds: 'upload_sds', edit_sds: 'correct_sds_metadata', current_sds: 'set_current_sds',
        add_storage: 'create_storage', edit_storage: 'update_storage', audit_storage: 'audit_inventory', archive_storage: 'archive_storage', restore_storage: 'restore_storage' };
    const target: ChemicalCommandTarget = { command: names[action], actorId, substanceId: detail?.id ?? null,
        sourceType: action.includes('storage') ? 'storage' : action.includes('sds') ? 'sds' : 'catalogue',
        sourceId: action === 'create' || action === 'add_storage' || action === 'add_sds' ? null : storage?.id ?? sheet?.id ?? detail?.id ?? null,
        expectedVersion: versions.expected, parentExpectedVersion: versions.parent,
        method: action === 'archive_storage' ? 'delete' : action === 'status' ? 'patch' : ['edit', 'edit_sds', 'edit_storage'].includes(action) ? 'put' : 'post', path, allowed,
        canRecover: action === 'create' ? capture?.can.create === true : !!detail && validSource && (create ? detail.can.create : detail.can.manage && (storage ? storage.can.manage : true)) };
    const command = useChemicalCommand(target, baseline !== JSON.stringify({ ...values, file: null }) || values.file !== null);
    const [fresh, setFresh] = useState<{ detail: ChemicalDetail; storage?: ChemicalStorageLocation; sheet?: ChemicalSdsRecord; observedVersion: number | null; observedStorageVersion?: number | null } | null>(null);
    const [reviewing, setReviewing] = useState(false); const [reviewFailure, setReviewFailure] = useState<string | null>(null);
    const reviewRequest = useRef<AbortController | null>(null);
    useEffect(() => () => { reviewRequest.current?.abort(); reviewRequest.current = null; }, []);
    const reviewCurrent = async () => {
        if (!detail || !validSource || !(create ? detail.can.create : detail.can.manage && (storage ? storage.can.manage : true))
            || reviewRequest.current || reviewing || command.busy || command.receipt || (command.uncertain && !command.canReviewRejected)) return;
        const controller = new AbortController(); reviewRequest.current = controller;
        setReviewing(true); setFresh(null); setReviewFailure(null);
        try {
            const { data } = await axios.get(`${CHEMICAL_REGISTER}/${detail.id}`, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (controller.signal.aborted) return;
            const current = data?.detail as ChemicalDetail | undefined;
            if (!current || current.id !== detail.id || current.source_integrity === 'repair_required' || !current.can
                || !Number.isSafeInteger(current.expected_version) || current.expected_version === null || current.expected_version < 0
                || !Array.isArray(current.sds_records) || !Array.isArray(current.storage_locations) || !Array.isArray(current.archived_storage?.items)) throw new Error('Current source unavailable');
            const currentStorage = storage ? [...current.storage_locations, ...current.archived_storage.items].find((row) => row.id === storage.id) : undefined;
            const currentSheet = sheet ? current.sds_records.find((row) => row.id === sheet.id) : undefined;
            if ((storage && (!currentStorage || currentStorage.source_integrity === 'repair_required' || !currentStorage.can.manage))
                || (sheet && (!currentSheet || currentSheet.source_integrity === 'repair_required')) || !(create ? current.can.create : current.can.manage)) throw new Error('Current change unavailable');
            setFresh({ detail: current, storage: currentStorage, sheet: currentSheet,
                observedVersion: providedDetail?.expected_version ?? null, observedStorageVersion: providedStorage?.expected_version });
        } catch (error) {
            if (controller.signal.aborted) return;
            if (axios.isAxiosError(error) && [401, 403, 404].includes(error.response?.status ?? 0)) onWithdraw?.();
            setReviewFailure('Current facts could not be confirmed. Your original entries are retained.');
        } finally { if (reviewRequest.current === controller) { reviewRequest.current = null; setReviewing(false); } }
    };
    const useReviewed = () => {
        if (!fresh || !providedDetail || reviewing || command.busy || !validSource || providedDetail.expected_version !== fresh.observedVersion
            || providedStorage?.expected_version !== fresh.observedStorageVersion
            || !(create ? providedDetail.can.create : providedDetail.can.manage && (providedStorage ? providedStorage.can.manage : true))) return;
        // Retained facts stay unchanged; only an explicitly reviewed source revision changes.
        const next = { expected: action === 'add_storage' ? null : action.includes('storage') ? fresh.storage?.expected_version ?? null : fresh.detail.expected_version,
            parent: action.includes('storage') ? fresh.detail.expected_version : null };
        if (action.includes('storage') && action !== 'add_storage' && next.expected === null) return;
        if (command.uncertain && !command.resetReviewedRejection()) return;
        setReviewedRecord(fresh);
        setVersions(next); setFresh(null); setReviewFailure('Current revision reviewed. Your retained entries have not been replaced. Recheck them before submitting.');
    };
    useEffect(() => { if (command.withdrawn) onWithdraw?.(); }, [command.withdrawn, onWithdraw]);
    const steps = catalogue ? [{ key: 'reference', label: 'Reference', blurb: 'Identity and classification', icon: FlaskConical },
        { key: 'controls', label: 'Safe use', blurb: 'Recorded precautions and responses', icon: Shield }, { key: 'review', label: 'Review', blurb: 'Check before saving', icon: ClipboardCheck }] :
        [{ key: 'facts', label: 'Details', blurb: 'Record this change', icon: FileText }, { key: 'review', label: 'Review', blurb: 'Check before saving', icon: ClipboardCheck }];
    const review = step === steps.length - 1; const displayFields = catalogue ? step === 0 ? reference : controls : fields;
    const title = CHEMICAL_LABELS[action];
    const field = (spec: FieldSpec) => <Field key={spec.key} label={spec.label} required={spec.required} htmlFor={`${id}-${spec.key}`} error={command.errors[spec.key]}>
        {spec.options ? <SelectInput id={`${id}-${spec.key}`} ariaLabel={spec.label} frontline value={String(values[spec.key] ?? '')} placeholder="Choose…" options={spec.options} disabled={command.locked}
            onChange={(value) => setValues((current) => ({ ...current, [spec.key]: value }))} /> : spec.multiline ?
            <Textarea id={`${id}-${spec.key}`} value={String(values[spec.key] ?? '')} disabled={command.locked} onChange={(event) => setValues((current) => ({ ...current, [spec.key]: event.target.value }))} /> :
            <Input id={`${id}-${spec.key}`} type={spec.type ?? 'text'} className="frontline-tap" value={String(values[spec.key] ?? '')} disabled={command.locked} onChange={(event) => setValues((current) => ({ ...current, [spec.key]: event.target.value }))} />}
    </Field>;
    return <WizardShell open={active} frontline onClose={onCancel} title={title} description="Record the reviewed chemical facts. Changes stay with their permanent source record."
        railIcon={FlaskConical} railTitle={title} railSub="Chemical reference" steps={steps} stepIndex={step} onStepClick={(next) => !command.locked && setStep(next)}
        footerStart={<Button variant="outline" type="button" className="frontline-tap" onClick={onCancel}>{command.receipt ? 'Close' : 'Cancel'}</Button>}
        footerEnd={command.receipt ? <Button type="button" className="frontline-tap" onClick={onDone}>{action === 'create' ? 'Done' : 'Return to record'}</Button> : <>
            {step > 0 && <Button type="button" variant="outline" className="frontline-tap" disabled={command.locked} onClick={() => setStep(step - 1)}>Back</Button>}
            {!review ? <Button type="button" className="frontline-tap" disabled={command.locked} onClick={() => setStep(step + 1)}>Review</Button> :
                <Button type="button" className="frontline-tap" disabled={command.locked || (action === 'add_sds' && !values.file)} onClick={() => command.submit(chemicalFormPayload(action, values, capture), action === 'add_sds')}>Save reviewed details</Button>}</>}
        success={!!command.receipt}>
        {command.receipt ? <div className="space-y-4"><CheckCircle2 aria-hidden className="size-8 text-status-success" /><h2 className="text-section-title">Saved</h2>
            <p className="text-subtle">{command.receipt.changed ? 'This exact submission was accepted.' : 'The submission was accepted with no change to the recorded facts.'}</p>
            {command.href ? <Button asChild className="frontline-tap"><a href={command.href}>Open chemical record</a></Button> : <p className="text-subtle">Your submission is confirmed. Your current access does not include opening the saved record.</p>}</div> :
            command.withdrawn || !validSource ? <p role="alert" className="text-subtle text-status-warning">Current access or source history is unavailable. Saved facts are withheld; your unsent entries remain in memory until you leave.</p> : <>
                {(stale || choicesError || !allowed) && <p role="alert" className="text-subtle text-status-warning">{stale ? 'The record has changed. Check the original submission before reviewing current facts.' : choicesError ? 'Current Site choices could not be loaded. Close this form and check access before trying again.' : 'This change is unavailable for the current record.'}</p>}
                {command.message && <div role="status" className="space-y-3"><p className="text-subtle text-status-warning">{command.message}</p>
                    <div className="flex flex-wrap gap-2">{command.canCheck && <Button type="button" className="frontline-tap" variant="outline" disabled={command.busy} onClick={() => void command.check()}>Check original submission</Button>}
                        <Button type="button" className="frontline-tap" variant="outline" disabled={command.busy || !command.canRetry} onClick={command.retry}>Retry exact submission</Button>
                        {command.canEditRejected && <Button type="button" className="frontline-tap" variant="outline" onClick={() => { command.editRejected(); setStep(0); }}>Edit rejected entries</Button>}</div></div>}
                {Object.entries(command.errors).filter(([key]) => ['command', 'expected_version', 'parent_expected_version', 'idempotency_key', 'file'].includes(key)).map(([key, message]) => <p role="alert" key={key} className="text-subtle text-status-critical">{message}</p>)}
                {(stale || command.errors.expected_version || command.errors.parent_expected_version) && <Button type="button" className="frontline-tap" variant="outline" disabled={reviewing || command.busy || (command.uncertain && !command.canReviewRejected)} onClick={() => void reviewCurrent()}>Review current record</Button>}
                {reviewFailure && <p role="status" className="text-subtle">{reviewFailure}</p>}
                {fresh && <ReviewCard icon={ClipboardCheck} title="Current recorded facts">
                    {fields.map((spec) => { const source = fresh.storage ?? fresh.sheet ?? fresh.detail; const value = (source as unknown as Record<string, unknown>)[spec.key];
                        return <ReviewRow key={spec.key} label={spec.label} value={spec.key === 'reason' ? 'Your reason is retained below' : spec.key === 'site_id' ? fresh.storage?.site.name :
                            Array.isArray(value) ? value.join(', ') : typeof value === 'boolean' ? value ? 'Yes' : 'No' : spec.type === 'date' ? formatDateOnly(value as string | null, 'Not recorded') : value == null ? 'Not recorded' : String(value)} />; })}
                    <Button type="button" className="mt-3 frontline-tap" disabled={reviewing || command.busy} onClick={useReviewed}>Use reviewed revision</Button>
                </ReviewCard>}
                {review ? <ReviewCard icon={ClipboardCheck} title="Reviewed submission" onEdit={command.locked ? undefined : () => setStep(0)}>
                    {fields.map((spec) => <ReviewRow key={spec.key} label={spec.label} value={String(values[spec.key] ?? '') || 'Not recorded'} />)}
                    {action === 'add_sds' && <ReviewRow label="Selected sheet" value={values.file instanceof File ? values.file.name : 'No file selected'} />}
                    <p className="mt-3 text-subtle">Recorded reference information does not certify that use, storage or worker exposure is safe.</p>
                </ReviewCard> : <div className="grid gap-4 sm:grid-cols-2">{displayFields.map(field)}
                    {action === 'add_sds' && <div className="sm:col-span-2"><Field label="Safety data sheet" required error={command.errors.file}>
                        <FileDropzone accept=".pdf,.doc,.docx" multiple={false} disabled={command.locked} title="Choose safety data sheet" hint="PDF or Word · maximum 10 MB" onFiles={(files) => setValues((current) => ({ ...current, file: files[0] ?? null }))} />
                        <p className="text-subtle">{values.file instanceof File ? values.file.name : 'No file selected'}</p></Field></div>}
                </div>}
            </>}
    </WizardShell>;
}
