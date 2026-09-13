import type {
    ItApprovalOperation,
    ItApprovalStatus,
} from './it-ticket-approval-contract';
import { draftRecord } from './it-ticket-draft-contract';

type Person = { id: number; name: string };
type ReasonEvidence = {
    value: string | null;
    provenance: string;
    recorded_at: string | null;
};
export interface ItApprovalRecord {
    id: number;
    status: ItApprovalStatus;
    recorded_status: ItApprovalStatus;
    requested_by: Person | null;
    requested_at: string | null;
    decided_by: Person | null;
    decided_at: string | null;
    reason_evidence: {
        request: ReasonEvidence;
        decision: ReasonEvidence;
        legacy_reason: string | null;
    };
    primary: Person | null;
    cover: Person | null;
    assignment_recorded_at: string | null;
    current_responsibility: { person: Person | null; basis: string } | null;
    decision_authority: string | null;
    expires_at: string | null;
    remind_at: string | null;
    reminder_prepared_at: string | null;
    reminder_last_checked_at: string | null;
    expired_at: string | null;
    cancelled_at: string | null;
    cancelled_by_user_id: number | null;
    cancellation_reason: string | null;
}
export interface ItApprovalWork {
    storage_ready: boolean;
    can_request: boolean;
    can_decide: boolean;
    can_withdraw: boolean;
    candidates: (Person & { available: boolean })[];
    current: ItApprovalRecord | null;
    total: number;
}
export interface ItApprovalReview {
    actorId: number;
    ticketId: number;
    version: number;
    work: ItApprovalWork;
}
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const nullableString = (value: unknown) =>
    value === null || typeof value === 'string';
const person = (value: unknown) =>
    value === null ||
    (draftRecord(value) &&
        positive(value.id) &&
        typeof value.name === 'string');
const status = (value: unknown) =>
    typeof value === 'string' &&
    ['pending', 'approved', 'rejected', 'expired', 'cancelled'].includes(value);
const reason = (value: unknown) =>
    draftRecord(value) &&
    nullableString(value.value) &&
    typeof value.provenance === 'string' &&
    nullableString(value.recorded_at);
export function isItApprovalRecord(value: unknown): value is ItApprovalRecord {
    if (
        !draftRecord(value) ||
        !positive(value.id) ||
        !status(value.status) ||
        !status(value.recorded_status)
    )
        return false;
    if (
        !['requested_by', 'decided_by', 'primary', 'cover'].every((key) =>
            person(value[key]),
        )
    )
        return false;
    if (
        ![
            'requested_at',
            'decided_at',
            'assignment_recorded_at',
            'decision_authority',
            'expires_at',
            'remind_at',
            'reminder_prepared_at',
            'reminder_last_checked_at',
            'expired_at',
            'cancelled_at',
            'cancellation_reason',
        ].every((key) => nullableString(value[key]))
    )
        return false;
    if (
        value.cancelled_by_user_id !== null &&
        !positive(value.cancelled_by_user_id)
    )
        return false;
    const evidence = value.reason_evidence;
    const owner = value.current_responsibility;
    return (
        draftRecord(evidence) &&
        reason(evidence.request) &&
        reason(evidence.decision) &&
        nullableString(evidence.legacy_reason) &&
        (owner === null ||
            (draftRecord(owner) &&
                person(owner.person) &&
                typeof owner.basis === 'string'))
    );
}
export function readItApprovalReview(
    value: unknown,
    actorId: number,
    ticketId: number,
): ItApprovalReview | null {
    if (
        !draftRecord(value) ||
        value.viewer_user_id !== actorId ||
        !draftRecord(value.ticket) ||
        value.ticket.id !== ticketId ||
        !positive(value.ticket.lock_version) ||
        !draftRecord(value.can) ||
        value.can.manage !== true
    )
        return null;
    const work = value.approval_work;
    if (
        !draftRecord(work) ||
        !['storage_ready', 'can_request', 'can_decide', 'can_withdraw'].every(
            (key) => typeof work[key] === 'boolean',
        ) ||
        !Number.isSafeInteger(work.total) ||
        Number(work.total) < 0 ||
        !Array.isArray(work.candidates) ||
        !work.candidates.every(
            (item) =>
                draftRecord(item) &&
                positive(item.id) &&
                typeof item.name === 'string' &&
                typeof item.available === 'boolean',
        ) ||
        (work.current !== null && !isItApprovalRecord(work.current))
    )
        return null;
    return {
        actorId,
        ticketId,
        version: value.ticket.lock_version,
        work: structuredClone(work) as unknown as ItApprovalWork,
    };
}
export function canSubmitApproval(
    work: ItApprovalWork,
    operation: ItApprovalOperation,
    approvalId: number | null,
): boolean {
    return (
        work.storage_ready &&
        (operation === 'request'
            ? work.can_request
            : work.current?.id === approvalId &&
              (operation === 'decide' ? work.can_decide : work.can_withdraw))
    );
}
