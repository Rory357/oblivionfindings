export type ClientLite = { id: number; first_name: string; last_name: string; site?: { id: number; name: string } | null };
export type EmergencyPolicy = { default_minutes: number; max_minutes: number; extend_minutes: number; reason_required: boolean; second_person: 'off' | 'optional' | 'required'; review_days: number; repeat_threshold_count: number; repeat_window_days: number };
export type Approver = { id: number; name: string; witness_pin: string; site_ids: number[] };
export type OnCallContact = { name: string | null; phone: string | null; how: string | null; warning: string | null };
export type Grant = {
    id: number; client_id: number; client_name: string; site_name: string | null; staff: string;
    reason: string; reason_category: string | null; cosign_label: string | null;
    created_at: string; expires_at: string; ended_at: string | null; ended_how: string | null; end_reason: string | null;
    review_due_at: string | null; status: 'active' | 'expired' | 'revoked'; own: boolean;
    can_extend: boolean; can_revoke: boolean; can_review: boolean; review_denial: string | null;
    review_outcome: string | null; reviewed_by: string | null;
    reviews: { id: number; outcome: string; notes: string | null; by: string | null; at: string; correction_reason: string | null }[];
    events: { action: string; detail: string | null; at: string | null }[];
};
