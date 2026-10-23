import { actualEventInstant } from '@/components/clinical/actual-event-time-field';
import { toDateInput, toDatetimeLocal, workerTimeOffset } from '@/lib/datetime';
import type { Method } from '@inertiajs/core';
import type { InjuryCommand, InjuryRecord } from './injury-workspace-types';

export type InjuryFormValues = {
    user_id: string; site_id: string; related_incident_id: string; injury_date: string; injury_offset: string;
    injury_type: string; severity: string; body_part_affected: string; description: string; immediate_treatment: string;
    medical_treatment_type: string; worksafe_notifiable: boolean; acc_claim_lodged: boolean; acc_claim_number: string;
    lost_time_days: string; expected_return_date: string; actual_return_date: string; notes: string;
    coordinator_user_id: string; next_review_date: string; reason: string; reviewed_at: string; review_offset: string;
    assessment_date: string; assessor_user_id: string; assessor_name: string; assessor_type: string; capacity_status: string;
    assessment_summary: string; restrictions: string; recommendations: string; next_assessment_date: string; hr_document_id: string;
    manager_id: string; plan_start_date: string; plan_end_date: string; goals: string[];
    stages: { name: string; start_date: string; end_date: string; hours_per_week: string; duties_description: string }[];
    plan_status: string; medical_clearance_notes: string; medical_clearance_date: string; medical_clearance_provider: string;
    workplace_modifications: string; worker_agreement_notes: string; worker_agreed: boolean; worker_agreed_at: string; review_notes: string;
    start_date: string; end_date: string; modified_duties_description: string; hours_per_day: string; accommodations: string;
    kind: string; alt_text: string; file: File | null;
};
export const emptyInjuryStage = () => ({ name: '', start_date: '', end_date: '', hours_per_week: '', duties_description: '' });
export function injuryFormValues(d: InjuryRecord | null, planId?: number | null): InjuryFormValues {
    const plan = d?.rtw_plans?.find((item) => item.id === planId);
    return {
        user_id: String(d?.worker?.id ?? ''), site_id: String(d?.site?.id ?? ''), related_incident_id: String(d?.related_incident?.id ?? ''),
        injury_date: toDatetimeLocal(d?.injury_date), injury_offset: d?.injury_date ? workerTimeOffset(d.injury_date) : '',
        injury_type: d?.injury_type ?? '', severity: d?.severity ?? '', body_part_affected: d?.body_part_affected ?? '', description: d?.description ?? '',
        immediate_treatment: d?.immediate_treatment ?? '', medical_treatment_type: d?.medical_treatment_type ?? '', worksafe_notifiable: d?.worksafe_notifiable ?? false,
        acc_claim_lodged: d?.acc_claim_lodged ?? false, acc_claim_number: d?.acc_claim_number ?? '', lost_time_days: String(d?.lost_time_days ?? 0),
        expected_return_date: toDateInput(d?.expected_return_date), actual_return_date: toDateInput(d?.actual_return_date), notes: d?.notes ?? '',
        coordinator_user_id: String(d?.coordination.coordinator?.id ?? ''), next_review_date: toDateInput(plan?.next_review_date ?? d?.coordination.next_review_date), reason: '', reviewed_at: '', review_offset: '',
        assessment_date: '', assessor_user_id: '', assessor_name: '', assessor_type: '', capacity_status: '', assessment_summary: '', restrictions: '', recommendations: '', next_assessment_date: '', hr_document_id: '',
        manager_id: String(plan?.manager?.id ?? ''), plan_start_date: toDateInput(plan?.plan_start_date), plan_end_date: toDateInput(plan?.plan_end_date),
        goals: plan?.goals.length ? [...plan.goals] : [''], stages: plan?.stages.length ? plan.stages.map((stage) => ({ name: stage.name, start_date: toDateInput(stage.start_date), end_date: toDateInput(stage.end_date), hours_per_week: String(stage.hours_per_week ?? ''), duties_description: stage.duties_description ?? '' })) : [emptyInjuryStage()],
        plan_status: plan?.status ?? 'draft', medical_clearance_notes: plan?.medical_clearance_notes ?? '', medical_clearance_date: toDateInput(plan?.medical_clearance_date), medical_clearance_provider: plan?.medical_clearance_provider ?? '',
        workplace_modifications: plan?.workplace_modifications ?? '', worker_agreement_notes: plan?.worker_agreement_notes ?? '', worker_agreed: plan?.worker_agreed ?? false, worker_agreed_at: toDateInput(plan?.worker_agreed_at), review_notes: plan?.review_notes ?? '',
        start_date: '', end_date: '', modified_duties_description: '', hours_per_day: '', accommodations: '', kind: '', alt_text: '', file: null,
    };
}
const nullable = (value: string) => value.trim() === '' ? null : value;
const identity = (value: string) => nullable(value) === null ? null : Number(value);
/** Submit only the fields of this command; hidden medical fields are omitted, never cleared. */
export function injuryCommandRequest(command: InjuryCommand | 'create', f: InjuryFormValues, d: InjuryRecord | null,
    selected?: { plan?: number | null; attachment?: number | null }, recordReportedDetails = false): { method: Method; href: string; values: Record<string, unknown> } {
    const base = '/health-safety/injuries'; const record = `${base}/${d?.id}`;
    if (command === 'create' || command === 'edit') {
        const values: Record<string, unknown> = { user_id: identity(f.user_id), site_id: identity(f.site_id), related_incident_id: identity(f.related_incident_id), injury_date: actualEventInstant(f.injury_date, f.injury_offset), injury_type: f.injury_type, severity: f.severity, body_part_affected: f.body_part_affected, description: f.description, worksafe_notifiable: f.worksafe_notifiable, lost_time_days: Number(f.lost_time_days), expected_return_date: nullable(f.expected_return_date), actual_return_date: nullable(f.actual_return_date) };
        if (command === 'edit') {
            if (!f.user_id) delete values.user_id;
            if (!f.site_id) delete values.site_id;
            if (d?.connections.incident.access !== 'absent' && d?.connections.incident.access !== 'readable') delete values.related_incident_id;
            if (d?.site && Number(f.site_id) !== d.site.id && f.coordinator_user_id) Object.assign(values, {
                coordinator_user_id: identity(f.coordinator_user_id), reason: f.reason, next_review_date: nullable(f.next_review_date),
            });
        }
        if ((command === 'create' && recordReportedDetails) || (command === 'edit' && d?.medical_access === 'readable')) Object.assign(values, { immediate_treatment: nullable(f.immediate_treatment), medical_treatment_type: nullable(f.medical_treatment_type), acc_claim_lodged: f.acc_claim_lodged, acc_claim_number: nullable(f.acc_claim_number), notes: nullable(f.notes) });
        return { method: command === 'create' ? 'post' : 'put', href: command === 'create' ? base : record, values };
    }
    const status = ({ start_treatment: 'under_treatment', begin_rtw: 'return_to_work', mark_recovered: 'recovered', close: 'closed' } as Partial<Record<InjuryCommand, string>>)[command];
    if (status) return { method: 'post', href: `${record}/status`, values: { status, reason: f.reason } };
    if (command === 'assign') return { method: 'post', href: `${record}/coordinator`, values: { coordinator_user_id: identity(f.coordinator_user_id), next_review_date: nullable(f.next_review_date), reason: f.reason } };
    if (command === 'review') return { method: 'post', href: `${record}/reviews`, values: { reviewed_at: actualEventInstant(f.reviewed_at, f.review_offset), next_review_date: nullable(f.next_review_date), notes: f.notes } };
    if (command === 'acc') return { method: 'put', href: record, values: { acc_claim_lodged: f.acc_claim_lodged, acc_claim_number: nullable(f.acc_claim_number) } };
    if (command === 'add_capacity') return { method: 'post', href: `${record}/capacity-assessments`, values: { assessment_date: f.assessment_date, assessor_user_id: identity(f.assessor_user_id), assessor_name: nullable(f.assessor_name), assessor_type: f.assessor_type, capacity_status: f.capacity_status, assessment_summary: nullable(f.assessment_summary), restrictions: nullable(f.restrictions), recommendations: nullable(f.recommendations), next_assessment_date: nullable(f.next_assessment_date), ...(f.hr_document_id ? { hr_document_id: identity(f.hr_document_id) } : {}) } };
    if (command === 'add_rtw' || command === 'edit_rtw') return { method: command === 'add_rtw' ? 'post' : 'put', href: command === 'add_rtw' ? `${record}/rtw-plans` : `${base}/rtw-plans/${selected?.plan}`, values: { manager_id: identity(f.manager_id), plan_start_date: f.plan_start_date, plan_end_date: nullable(f.plan_end_date), goals: f.goals, stages: f.stages.map((stage) => ({ ...stage, end_date: nullable(stage.end_date), hours_per_week: identity(stage.hours_per_week), duties_description: nullable(stage.duties_description) })), status: f.plan_status, medical_clearance_notes: nullable(f.medical_clearance_notes), medical_clearance_date: nullable(f.medical_clearance_date), medical_clearance_provider: nullable(f.medical_clearance_provider), workplace_modifications: nullable(f.workplace_modifications), worker_agreement_notes: nullable(f.worker_agreement_notes), worker_agreed: f.worker_agreed, worker_agreed_at: nullable(f.worker_agreed_at), next_review_date: nullable(f.next_review_date), review_notes: nullable(f.review_notes) } };
    if (command === 'add_duty') return { method: 'post', href: `${base}/rtw-plans/${selected?.plan}/modified-duties`, values: { start_date: f.start_date, end_date: nullable(f.end_date), modified_duties_description: f.modified_duties_description, hours_per_day: identity(f.hours_per_day), restrictions: nullable(f.restrictions), accommodations: nullable(f.accommodations) } };
    if (command === 'upload_evidence') return { method: 'post', href: `${record}/attachments`, values: { file: f.file, kind: nullable(f.kind), notes: nullable(f.notes), alt_text: nullable(f.alt_text) } };
    return { method: 'delete', href: `${record}/attachments/${selected?.attachment}`, values: { reason: f.reason } };
}
