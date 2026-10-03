import { CHECKS, SCORES } from './types';
export function assessmentErrorStep(field: string): number {
    const key = field.split('.')[0];
    if (SCORES.some((s) => s.key === key) || CHECKS.some((c) => c.key === key))
        return 1;
    if (key === 'med_scope' || key === 'confirm_loosening') return 2;
    if (
        [
            'storage_location',
            'safe_storage_notes',
            'risk_factors',
            'support_needed',
            'support_adjustments',
            'assessor_notes',
            'reassessment_interval_months',
            'reassessment_trigger',
            'agreement_terms_changed',
        ].includes(key)
    )
        return 3;
    if (key === 'confirmed_with_person' || key === 'client_request_uuid')
        return 4;
    return 0;
}
export function firstAssessmentError(errors: Record<string, string>): {
    step: number;
    field: string;
} {
    const field =
        Object.keys(errors).sort(
            (a, b) => assessmentErrorStep(a) - assessmentErrorStep(b),
        )[0] ?? '';
    return {
        step: assessmentErrorStep(field),
        field: field.startsWith('med_scope')
            ? 'med_scope'
            : field.split('.')[0],
    };
}

export function agreementErrorStep(field: string): number {
    const key = field.split('.')[0];
    if (
        [
            'ordering_responsibility',
            'person_responsibilities',
            'staff_responsibilities',
            'storage_notes',
        ].includes(key)
    )
        return 1;
    if (key === 'confirm_loosening' || key === 'client_request_uuid') return 2;
    return 0;
}
export function firstAgreementError(errors: Record<string, string>): {
    step: number;
    field: string;
} {
    const field =
        Object.keys(errors).sort(
            (a, b) => agreementErrorStep(a) - agreementErrorStep(b),
        )[0] ?? '';
    const key = field.split('.')[0];
    return {
        step: agreementErrorStep(field),
        field: key === 'storage_notes' ? 'agreement-storage' : key,
    };
}
