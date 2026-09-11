import type {
    ItApprovalRecord,
    ItApprovalWork,
} from '@/hooks/it-approval-work';
export const approvalRecord = (
    changes: Partial<ItApprovalRecord> = {},
): ItApprovalRecord => ({
    id: 10,
    status: 'pending',
    recorded_status: 'pending',
    requested_by: { id: 6, name: 'Requesting manager' },
    requested_at: '2026-09-10T01:00:00Z',
    decided_by: null,
    decided_at: null,
    reason_evidence: {
        request: {
            value: 'Privileged access requires review',
            provenance: 'recorded_at_request',
            recorded_at: '2026-09-10T01:00:00Z',
        },
        decision: { value: null, provenance: 'not_decided', recorded_at: null },
        legacy_reason: null,
    },
    primary: { id: 7, name: 'Primary manager' },
    cover: { id: 9, name: 'Cover manager' },
    assignment_recorded_at: '2026-09-10T01:00:00Z',
    current_responsibility: {
        person: { id: 7, name: 'Primary manager' },
        basis: 'primary',
    },
    decision_authority: null,
    expires_at: null,
    remind_at: null,
    reminder_prepared_at: null,
    reminder_last_checked_at: null,
    expired_at: null,
    cancelled_at: null,
    cancelled_by_user_id: null,
    cancellation_reason: null,
    ...changes,
});
export const approvalWork = (
    changes: Partial<ItApprovalWork> = {},
): ItApprovalWork => ({
    storage_ready: true,
    can_request: true,
    can_decide: false,
    can_withdraw: false,
    candidates: [
        { id: 8, name: 'Eligible manager', available: true },
        { id: 9, name: 'Cover manager', available: true },
    ],
    current: null,
    total: 0,
    ...changes,
});
