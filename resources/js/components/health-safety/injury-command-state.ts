import { humaniseHsValue } from '@/lib/health-safety/hs-labels';
import { CheckCircle2, ClipboardList, FileText } from 'lucide-react';
import {
    ATTACHMENT_KINDS,
    injuryTypeLabel,
    severityLabel,
    statusLabel,
    treatmentLabel,
} from './injury-constants';
import type { InjuryCommand, InjuryRecord } from './injury-workspace-types';

/** The injury wizard's three steps — shared by the wizard and the Report injury page header. */
export const INJURY_WIZARD_STEPS = [
    { key: 'facts', label: 'What happened', blurb: 'Who was hurt and what happened', icon: FileText },
    { key: 'detail', label: 'More detail', blurb: 'Dates, recovery details and files', icon: ClipboardList },
    { key: 'review', label: 'Check and save', blurb: 'Check your entries, then save', icon: CheckCircle2 },
];

/** Plain words for the stored injury and recovery values (one place, used by the forms and the record). */
export const ASSESSOR_TYPE_LABELS: Record<string, string> = {
    gp: 'GP',
    nurse_practitioner: 'Nurse practitioner',
    specialist: 'Specialist',
    physiotherapist: 'Physiotherapist',
    occupational_therapist: 'Occupational therapist',
    employer: 'Employer',
};
export const CAPACITY_STATUS_LABELS: Record<string, string> = {
    fit_for_full_duties: 'Fit for full duties',
    fit_for_modified_duties: 'Fit for modified duties',
    unfit_for_work: 'Unfit for work',
    requires_review: 'Needs another review',
};
export const RTW_PLAN_STATUS_LABELS: Record<string, string> = {
    draft: 'Draft',
    active: 'Active',
    in_progress: 'In progress',
    completed: 'Completed',
    cancelled: 'Cancelled',
};
/** Field names as people read them (review step and history). */
export const INJURY_FIELD_LABELS: Record<string, string> = {
    user_id: 'Injured worker',
    site_id: 'Site',
    coordinator_user_id: 'Injury owner',
    manager_id: 'Recovery coordinator',
    assessor_user_id: 'Assessor (staff)',
    related_incident_id: 'Linked incident',
    hr_document_id: 'Assessment document',
    injury_date: 'When it happened',
    injury_type: 'Injury type',
    severity: 'Severity',
    status: 'Status',
    body_part_affected: 'Body part affected',
    description: 'What happened',
    immediate_treatment: 'Immediate treatment',
    medical_treatment_type: 'Medical treatment',
    acc_claim_lodged: 'ACC claim lodged',
    acc_claim_number: 'ACC claim number',
    worksafe_notifiable: 'Marked notifiable to WorkSafe',
    lost_time_days: 'Days off work',
    expected_return_date: 'Expected return date',
    actual_return_date: 'Date back at work',
    notes: 'Notes',
    reason: 'Reason',
    next_review_date: 'Next check-in date',
    reviewed_at: 'When the review happened',
    plan_start_date: 'Plan starts',
    plan_end_date: 'Plan ends',
    plan_status: 'Plan status',
    status_label: 'Plan status',
    goals: 'Recovery goals',
    stages: 'Recovery stages',
    name: 'Stage name',
    start_date: 'Starts',
    end_date: 'Ends',
    hours_per_week: 'Hours per week',
    duties_description: 'Duties',
    workplace_modifications: 'Changes made at work',
    worker_agreed: 'Worker agreed to the plan',
    worker_agreed_at: 'Date the worker agreed',
    worker_agreement_notes: 'Worker agreement notes',
    medical_clearance_provider: 'Who cleared them to work',
    medical_clearance_date: 'Date cleared',
    medical_clearance_notes: 'Clearance notes',
    review_notes: 'Review notes',
    assessment_date: 'Assessment date',
    assessor_name: 'Assessor’s name',
    assessor_type: 'Assessor type',
    capacity_status: 'Result',
    assessment_summary: 'Assessment summary',
    restrictions: 'Restrictions',
    recommendations: 'Recommendations',
    next_assessment_date: 'Next assessment date',
    modified_duties_description: 'Duties',
    hours_per_day: 'Hours per day',
    accommodations: 'Changes made at work',
    kind: 'File type',
    alt_text: 'Image description',
};
export function injuryFieldLabel(key: string): string {
    return INJURY_FIELD_LABELS[key] ?? humaniseHsValue(key);
}
/** A stored value for a known field in plain words; other stored codes are made readable. */
export function injuryValueLabel(key: string, value: string): string {
    switch (key) {
        case 'injury_type': return injuryTypeLabel(value);
        case 'severity': return severityLabel(value);
        case 'status': return statusLabel(value);
        case 'medical_treatment_type': return treatmentLabel(value);
        case 'assessor_type': return ASSESSOR_TYPE_LABELS[value] ?? humaniseHsValue(value);
        case 'capacity_status': return CAPACITY_STATUS_LABELS[value] ?? humaniseHsValue(value);
        case 'plan_status': return RTW_PLAN_STATUS_LABELS[value] ?? humaniseHsValue(value);
        case 'kind': return value === 'rtw_clearance' ? 'Return-to-work clearance'
            : ATTACHMENT_KINDS.find((item) => item.value === value)?.label ?? humaniseHsValue(value);
        default: return /^[a-z]+(_[a-z]+)+$/.test(value) ? humaniseHsValue(value) : value;
    }
}

export const INJURY_COMMAND_LABELS: Record<InjuryCommand, string> = {
    start_treatment: 'Record treatment started',
    begin_rtw: 'Start recovery planning',
    mark_recovered: 'Record recovery',
    close: 'Close injury',
    acc: 'Update ACC claim',
    add_rtw: 'Add recovery plan',
    add_capacity: 'Record fitness-for-work check',
    edit: 'Edit injury details',
    assign: 'Change injury owner',
    review: 'Record recovery review',
    edit_rtw: 'Edit recovery plan',
    add_duty: 'Add modified duties',
    upload_evidence: 'Add evidence',
    remove_evidence: 'Remove evidence',
};
export const INJURY_RECEIPT_COMMAND: Record<InjuryCommand | 'create', string> = {
    create: 'create', edit: 'update', acc: 'update',
    start_treatment: 'transition', begin_rtw: 'transition',
    mark_recovered: 'transition', close: 'transition',
    assign: 'assign', review: 'review', add_capacity: 'record_capacity',
    add_rtw: 'create_rtw_plan', edit_rtw: 'update_rtw_plan',
    add_duty: 'add_modified_duty', upload_evidence: 'upload_evidence',
    remove_evidence: 'remove_evidence',
};
export function injuryCommandAllowed(record: InjuryRecord, command: InjuryCommand,
    selected?: { plan?: number | null; attachment?: number | null }): boolean {
    if (record.source_integrity === 'repair_required') return false;
    if (command === 'assign') return record.can.assign;
    if (record.status === 'closed') return false;
    const transition = {
        start_treatment: 'under_treatment', begin_rtw: 'return_to_work',
        mark_recovered: 'recovered', close: 'closed',
    }[command as 'start_treatment' | 'begin_rtw' | 'mark_recovered' | 'close'];
    if (transition)
        return record.can.transition && record.commands.transitions.includes(transition);
    switch (command) {
        case 'edit': return record.can.update;
        case 'acc': return record.can.update && record.medical_access === 'readable';
        case 'review': return record.can.review && record.medical_access === 'readable';
        case 'add_capacity':
        case 'add_rtw':
            return record.can.recovery && record.medical_access === 'readable' && record.recovery_integrity === 'valid';
        case 'edit_rtw':
        case 'add_duty':
            return record.can.recovery && record.medical_access === 'readable' && record.recovery_integrity === 'valid' &&
                !!record.rtw_plans?.some((plan) => plan.id === selected?.plan);
        case 'upload_evidence': return record.can.upload_evidence && record.medical_access === 'readable';
        case 'remove_evidence':
            return record.can.remove_evidence && record.medical_access === 'readable' &&
                !!record.attachments?.some((file) => file.id === selected?.attachment);
        default: return false;
    }
}
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const revision = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) >= 0 && Number(value) <= 4294967294;
const receiptKeys = [
    'accepted', 'injury_id', 'source_type', 'source_id', 'actor_id', 'command',
    'idempotency_key', 'expected_version', 'version', 'source_version_id',
    'changed', 'receipt_id', 'replayed',
] as const;
export type InjuryCommandReceipt = Record<string, unknown> & {
    accepted: true;
    injury_id: number;
    source_type: 'injury' | 'rtw_plan' | 'capacity_assessment' | 'modified_duty' | 'attachment';
    source_id: number;
    actor_id: number;
    command: string;
    idempotency_key: string;
    expected_version: number | null;
    version: number;
    source_version_id: number;
    changed: boolean;
    receipt_id: number;
    replayed: boolean;
};
/** UI success needs the reconstructed actor/source/command receipt. */
export function confirmsInjuryReceipt(value: unknown, expected: {
    injuryId?: number; version?: number; actorId: number; key: string;
    command: InjuryCommand | 'create'; childId?: number;
}): value is InjuryCommandReceipt {
    if (!value || typeof value !== 'object' || Array.isArray(value)) return false;
    const receipt = value as Record<string, unknown>;
    if (Object.keys(receipt).length !== receiptKeys.length ||
        !receiptKeys.every((key) => Object.hasOwn(receipt, key))) return false;
    const type = ['add_rtw', 'edit_rtw'].includes(expected.command) ? 'rtw_plan'
        : expected.command === 'add_capacity' ? 'capacity_assessment'
        : expected.command === 'add_duty' ? 'modified_duty'
        : ['upload_evidence', 'remove_evidence'].includes(expected.command) ? 'attachment'
        : 'injury';
    return receipt.accepted === true && positive(receipt.injury_id) &&
        (expected.injuryId == null || receipt.injury_id === expected.injuryId) &&
        positive(receipt.actor_id) && receipt.actor_id === expected.actorId &&
        receipt.command === INJURY_RECEIPT_COMMAND[expected.command] &&
        receipt.idempotency_key === expected.key.toLowerCase() &&
        receipt.expected_version === (expected.version ?? null) &&
        revision(receipt.version) && typeof receipt.changed === 'boolean' &&
        receipt.version === (expected.version == null ? 1 : expected.version + (receipt.changed ? 1 : 0)) &&
        (expected.version != null || receipt.changed === true) &&
        receipt.source_type === type && positive(receipt.source_id) &&
        (type !== 'injury' || receipt.source_id === receipt.injury_id) &&
        (expected.childId == null || receipt.source_id === expected.childId) &&
        positive(receipt.source_version_id) && positive(receipt.receipt_id) &&
        typeof receipt.replayed === 'boolean';
}
