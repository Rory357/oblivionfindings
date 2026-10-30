import { formatDateOnly, toDatetimeLocal, workerTimeOffset, workerTimeOffsets } from '@/lib/datetime';
import type { ExposureRecord, ExposureReportedFact } from './chemical-exposure-read-contract';
import type { ExposureAction } from './chemical-exposure-navigation';

export const EXPOSURE_FORM_LABELS: Record<ExposureAction | 'capture', string> = {
    capture: 'Record an exposure', correct: 'Correct exposure facts', review: 'Record a review', assign_followup: 'Assign a follow-up',
    reassign_followup: 'Transfer this follow-up',
    complete_followup: 'Record follow-up completion', upload_evidence: 'Add private evidence', remove_evidence: 'Remove private evidence',
};
export const EXPOSURE_TYPES = ['inhalation', 'skin_contact', 'eye_contact', 'ingestion', 'injection', 'other'];
export const EXPOSURE_TREATMENTS = ['none', 'first_aid', 'medical', 'hospitalisation', 'death'];
export type ExposureFormValues = Record<string, string | File | null>;
export const EXPOSURE_REPORTED_FACTS: ExposureReportedFact[] = ['medical_attention_sought', 'medical_treatment', 'incident_reported'];
export function exposureFactText(record: ExposureRecord, key: ExposureReportedFact): string {
    const entry = record.fact_states?.[key];
    const scalar = (value: boolean | string | null) => value === null || value === '' ? 'Unknown' : typeof value === 'boolean' ? value ? 'Yes' : 'No' : value.replaceAll('_', ' ');
    if (entry?.state === 'reported') return scalar(record[key]);
    if (entry?.state === 'reported_unknown') return 'Unknown · reported';
    if (entry?.state === 'unanswered') return 'Unknown · not answered';
    const earlier = scalar(entry ? entry.recorded_value : record[key]);
    return entry?.state === 'prior_context' ? `Unknown for this worker and Site · earlier answer: ${earlier}` : `Unknown · earlier recorded value: ${earlier} (unconfirmed)`;
}
const factualFields = ['user_id', 'site_id', 'exposed_at', 'exposure_type', 'exposure_duration', 'circumstances', 'symptoms',
    'first_aid_given', 'medical_attention_sought', 'medical_treatment', 'medical_outcome', 'incident_reported'];

/** Preserve whole seconds when editing a recorded instant; never slice UTC into a worker wall clock. */
export function exposureTimeInput(instant: string | null): string {
    if (!instant) return '';
    const date = new Date(instant); if (!Number.isFinite(date.getTime())) return '';
    return toDatetimeLocal(instant) + ':' + String(date.getUTCSeconds()).padStart(2, '0');
}
export function exposureTimeChoices(local: string): string[] {
    if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2})?$/.test(local) || (local.length > 16 && Number(local.slice(17)) > 59)
        || !formatDateOnly(local.slice(0, 10), '')) return [];
    return workerTimeOffsets(local.slice(0, 16));
}
/** Repeated wall times require an explicit offset choice; skipped/invalid times never become a guessed instant. */
export function exposureTimeInstant(local: string, selectedOffset: string): string | null {
    const choices = exposureTimeChoices(local);
    const offset = choices.length === 1 ? choices[0] : choices.includes(selectedOffset) ? selectedOffset : null;
    if (!offset) return null;
    return (local.length === 16 ? local + ':00' : local) + offset;
}
export function exposureInitialValues(action: ExposureAction | 'capture', record?: ExposureRecord | null): ExposureFormValues {
    const values: ExposureFormValues = Object.fromEntries(factualFields.map((key) => {
        const value = record?.[key as keyof ExposureRecord];
        return [key, typeof value === 'boolean' ? value ? '1' : '0' : value == null ? '' : String(value)];
    }));
    values.user_id = record ? String(record.worker.id) : ''; values.site_id = record ? String(record.site.id) : '';
    values.exposed_at = exposureTimeInput(record?.exposed_at ?? null); values.exposed_at_offset = record?.exposed_at ? workerTimeOffset(record.exposed_at) : '';
    values.reason = ''; values.file = null; values.description = ''; values.assigned_to = ''; values.instruction = '';
    values.notes = ''; values.outcome = ''; values.completion_notes = '';
    const now = new Date().toISOString();
    for (const field of ['reviewed_at', 'completed_at']) { values[field] = exposureTimeInput(now); values[field + '_offset'] = workerTimeOffset(now); }
    values.due_at = ''; values.due_at_offset = '';
    values.association_mode = action === 'capture' ? 'absent' : 'keep'; values.related_incident_id = '';
    if (action === 'capture') { values.medical_attention_sought = ''; values.medical_treatment = ''; values.incident_reported = ''; }
    return values;
}
export function exposureFormPayload(action: ExposureAction | 'capture', values: ExposureFormValues, baseline?: ExposureFormValues, confirmedFacts: readonly ExposureReportedFact[] = []): { payload: Record<string, unknown>; errors: Record<string, string> } {
    const payload: Record<string, unknown> = {}; const errors: Record<string, string> = {};
    const include = (fields: string[]) => fields.forEach((key) => { payload[key] = values[key] === '' || values[key] == null ? null : values[key]; });
    const time = (key: string) => { const instant = exposureTimeInstant(String(values[key] ?? ''), String(values[key + '_offset'] ?? ''));
        if (instant === null) errors[key] = 'Choose a real date and time. Repeated clock times require the correct offset.'; else payload[key] = instant; };
    if (action === 'capture' || action === 'correct') {
        const submitted = (key: string) => action !== 'correct' || !baseline || values[key] !== baseline[key] || confirmedFacts.includes(key as ExposureReportedFact);
        include(factualFields.filter((key) => !['exposed_at', 'medical_attention_sought', 'medical_treatment', 'incident_reported'].includes(key) && submitted(key)));
        if (submitted('medical_treatment') && (action !== 'capture' || values.medical_treatment !== '')) {
            payload.medical_treatment = values.medical_treatment === 'unknown' || values.medical_treatment === '' ? null : values.medical_treatment;
        }
        const currentTime = exposureTimeInstant(String(values.exposed_at ?? ''), String(values.exposed_at_offset ?? ''));
        const originalTime = baseline ? exposureTimeInstant(String(baseline.exposed_at ?? ''), String(baseline.exposed_at_offset ?? '')) : null;
        // A fresh revision must not turn an untouched, display-rounded time into a new occurrence fact.
        if (action !== 'correct' || currentTime === null || currentTime !== originalTime) time('exposed_at');
        for (const key of ['medical_attention_sought', 'incident_reported']) {
            if (!submitted(key)) continue;
            if (action === 'capture' && values[key] === '') continue;
            if (values[key] === '0' || values[key] === '1') payload[key] = values[key] === '1';
            else if (values[key] === '' || values[key] === 'unknown') payload[key] = null;
            else errors[key] = 'Choose Yes, No or Unknown for the recorded fact.';
        }
        if (values.association_mode === 'absent') payload.related_incident_id = null;
        else if (values.association_mode === 'selected') { payload.related_incident_id = values.related_incident_id; if (!values.related_incident_id) errors.related_incident_id = 'Choose an admitted incident.'; }
        // 'keep' deliberately omits the field. An unconfirmed legacy pointer cannot silently become a certified null.
        if (action === 'correct') include(['reason']);
    } else if (action === 'review') { include(['outcome', 'notes']); time('reviewed_at'); }
    else if (action === 'assign_followup') { include(['assigned_to', 'instruction']); time('due_at'); }
    else if (action === 'reassign_followup') include(['assigned_to', 'reason']);
    else if (action === 'complete_followup') { include(['completion_notes']); time('completed_at'); }
    else if (action === 'upload_evidence') include(['file', 'description']);
    else include(['reason']);
    return { payload, errors };
}
