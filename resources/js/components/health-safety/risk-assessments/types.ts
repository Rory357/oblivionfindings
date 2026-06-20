/* Shared types for the Risk Assessments register — consumed by the standalone
 * page, the Client profile section and the Site profile tab. Mirrors
 * App\Support\HealthSafety\RiskAssessmentPresenter. */

export type AttachType = 'standalone' | 'site' | 'client' | 'event';
export type RaStatus = 'draft' | 'active' | 'under_review' | 'superseded' | 'archived';
export type RaLevel = 'low' | 'medium' | 'high' | 'extreme';
export type RaModalKind = 'new' | 'edit' | 'supersede' | 'approve' | 'review' | 'residual' | 'archive';

export interface AttachedTo {
    type: AttachType;
    id: number | null;
    name: string;
}

export interface ReviewState {
    kind: 'overdue' | 'soon' | 'ok' | 'none';
    days: number | null;
}

export interface RaRow {
    id: number;
    reference_number: string;
    title: string;
    risk_description: string | null;
    status: RaStatus;
    attached_to: AttachedTo;
    likelihood: number;
    consequence: number;
    risk_score: number;
    risk_level: RaLevel;
    residual_likelihood: number | null;
    residual_consequence: number | null;
    residual_risk_score: number | null;
    residual_risk_level: RaLevel | null;
    risk_acceptable: boolean | null;
    assessed_by_name: string | null;
    review_due_at: string | null;
    review_state: ReviewState;
    is_due_for_review: boolean;
    attachments_count: number;
    superseded_by_id: number | null;
}

export interface RaAttachment {
    id: number;
    original_name: string;
    mime: string | null;
    size: number | null;
    kind: string | null;
    notes: string | null;
    is_image: boolean;
    uploaded_by_name: string | null;
    created_at: string | null;
    download_url: string;
}

export interface RaFormPrefill {
    title: string;
    risk_description: string | null;
    attach_type: AttachType;
    attach_id: number | null;
    likelihood: number;
    consequence: number;
    existing_controls: string | null;
    additional_controls: string | null;
    residual_likelihood: number;
    residual_consequence: number;
    risk_acceptable: boolean;
    review_frequency_days: number;
    review_due_at: string | null;
}

export interface RaDetail extends RaRow {
    existing_controls: string | null;
    additional_controls: string | null;
    review_frequency_days: number | null;
    approval_note: string | null;
    last_review_note: string | null;
    assessed_at: string | null;
    approved_by_name: string | null;
    approved_at: string | null;
    created_by_name: string | null;
    created_at: string | null;
    updated_at: string | null;
    superseded_by: { id: number; reference_number: string; status: RaStatus } | null;
    hs_event: { id: number; reference_number: string } | null;
    attachments: RaAttachment[];
    can: { manage: boolean };
    form: RaFormPrefill;
}

export interface RaPickerItem {
    id: number;
    name: string;
}

export interface RaPickers {
    sites: RaPickerItem[];
    clients: RaPickerItem[];
    events: RaPickerItem[];
}

/** A pre-attached entity for embedded surfaces (Client / Site profile). */
export interface LockedAssessable {
    type: 'site' | 'client';
    id: number;
    name: string;
}
