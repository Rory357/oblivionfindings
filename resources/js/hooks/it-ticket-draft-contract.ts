import type { ItApprovalOperation } from './it-ticket-approval-contract';
import type { ItWorkTaskOperation } from './it-work-task-command';

/** Client contracts for canonical IT drafts and RAM-only task recovery. */
export type ItDraftPurpose =
    | 'requester_intake'
    | 'technician_intake'
    | 'public_reply'
    | 'internal_note'
    | 'ticket_edit'
    | 'public_resolution'
    | 'task_work'
    | 'approval_work'
    | 'merge_work';
export type ItDraftContext =
    | {
          purpose: 'requester_intake' | 'technician_intake';
          requestUuid: string;
          ticketId?: never;
      }
    | {
          purpose:
              | 'public_reply'
              | 'internal_note'
              | 'ticket_edit'
              | 'public_resolution'
              | 'merge_work';
          ticketId: number;
          requestUuid?: never;
      }
    | {
          /** Local recovery only; no persisted task draft slot is enabled. */
          purpose: 'task_work';
          ticketId: number;
          operation: ItWorkTaskOperation;
          taskId: number | null;
          requestUuid?: never;
      }
    | {
          /** Internal document-memory only; never a persisted public draft slot. */
          purpose: 'approval_work';
          ticketId: number;
          operation: ItApprovalOperation;
          approvalId: number | null;
          requestUuid?: never;
      };
export type ItDraftFieldValue =
    | string
    | number
    | boolean
    | null
    | number[]
    | string[];
export type ItDraftFields = Partial<{
    title: string | null;
    description: string | null;
    category: string | null;
    site_id: number | null;
    impact: string | null;
    urgency: string | null;
    subcategory: string | null;
    priority: string | null;
    priority_reason: string | null;
    routing_reason: string | null;
    is_organisation_wide: boolean;
    it_service_id: number | null;
    work_type: string | null;
    assigned_to_user_id: number | null;
    asset_id: number | null;
    requester_user_id: number | null;
    device_id: number | null;
    provisioning_request_id: number | null;
    watchers: number[] | null;
    body: string | null;
    note: string | null;
    notify_requester: boolean;
    status: string | null;
    queue_id: number | null;
    owner_user_id: number | null;
    release_priority_override: boolean;
    release_routing_override: boolean;
    waiting_party: string | null;
    waiting_reason: string | null;
    next_action: string | null;
    resolution_code: string | null;
    resolution_summary: string | null;
    resolution_verification: string | null;
    team_id: number | null;
    due_at: string | null;
    is_required: boolean;
    evidence_required: boolean;
    sort_order: number;
    dependency_ids: number[];
    approval_id: number | null;
    ordered_ids: number[];
    reason: string | null;
    completion_note: string | null;
    evidence: string[] | null;
    decision: string | null;
    primary_approver_user_id: number | null;
    cover_approver_user_id: number | null;
    expires_at: string | null;
    remind_at: string | null;
    target_ticket_id: number | null;
    target_version: number | null;
}>;
export interface ItDraftPayload {
    fields: ItDraftFields;
    step_index: number;
}
export interface ItDraftSnapshot extends ItDraftPayload {
    base_ticket_version?: number | null;
}
export interface ItDraftMetadata {
    draft_uuid: string;
    purpose: ItDraftPurpose;
    context_key: string;
    audience: 'public' | 'internal';
    ticket_id: number | null;
    request_uuid: string | null;
    revision: number;
    state: 'active' | 'consumed' | 'discarded' | 'expired';
    has_content: boolean;
    saved_at: string | null;
    expires_at: string;
    base_ticket_version: number | null;
    current_ticket_version: number | null;
    files: { ready: number; pending: number; cleanup_pending: number };
    capabilities: {
        read: boolean;
        save: boolean;
        submit: boolean;
        discard: boolean;
        start_new: boolean;
    };
    blocker: null | { code: string; message: string; recovery_url?: string };
    cleanup?: { deleted: number; failed: number };
}
export interface ItDraftAttachment {
    id: number;
    upload_uuid: string;
    name: string;
    mime: string;
    size: number;
    state: 'reserved' | 'ready' | 'failed';
    download_url: string | null;
}
export interface ItDraftResumed {
    draft: ItDraftMetadata;
    payload: ItDraftPayload;
    attachments: ItDraftAttachment[];
    /** Present for a recovered local snapshot; never replace it with a newer ticket version. */
    base_ticket_version?: number | null;
}
export function resumedDraftBaseVersion(
    resumed: ItDraftResumed,
): number | null {
    return resumed.base_ticket_version === undefined
        ? resumed.draft.base_ticket_version
        : resumed.base_ticket_version;
}
export interface ItDraftCommitReference {
    draft_uuid: string;
    draft_revision: number;
    draft_actor_user_id: number;
}
export const IT_DRAFT_UUID =
    /^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i;
export function draftRecord(value: unknown): value is Record<string, unknown> {
    return !!value && typeof value === 'object' && !Array.isArray(value);
}
const natural = (value: unknown): value is number =>
    Number.isSafeInteger(value) && (value as number) >= 0;
const positive = (value: unknown): value is number =>
    natural(value) && value > 0;
const nullableId = (value: unknown) => value === null || positive(value);
const date = (value: unknown): value is string =>
    typeof value === 'string' &&
    value.length <= 50 &&
    Number.isFinite(Date.parse(value));
export function draftAudience(purpose: ItDraftPurpose): 'public' | 'internal' {
    return [
        'technician_intake',
        'internal_note',
        'ticket_edit',
        'task_work',
        'approval_work',
        'merge_work',
    ].includes(purpose)
        ? 'internal'
        : 'public';
}
export function draftContextKey(context: ItDraftContext): string {
    if (context.purpose === 'approval_work')
        return `ticket:${context.ticketId}:approval:${context.approvalId ?? 'new'}:operation:${context.operation}`;
    if (context.purpose === 'task_work')
        return `ticket:${context.ticketId}:task:${context.taskId ?? (context.operation === 'create' ? 'new' : 'order')}:operation:${context.operation}`;
    return context.ticketId !== undefined
        ? `ticket:${context.ticketId}`
        : `request:${context.requestUuid?.toLowerCase() ?? 'unavailable'}`;
}
export function draftScopeKey(
    actorId: number | undefined,
    context: ItDraftContext,
): string {
    return `${actorId ?? 'none'}:${context.purpose}:${draftContextKey(context)}`;
}
export function validDraftContext(
    actorId: number | undefined,
    context: ItDraftContext,
): boolean {
    if (context.purpose === 'approval_work')
        return (
            positive(actorId) &&
            positive(context.ticketId) &&
            context.requestUuid === undefined &&
            ['request', 'decide', 'withdraw'].includes(context.operation) &&
            (context.operation === 'request'
                ? context.approvalId === null
                : positive(context.approvalId))
        );
    if (context.purpose === 'task_work')
        return (
            positive(actorId) &&
            positive(context.ticketId) &&
            context.requestUuid === undefined &&
            ['create', 'update', 'complete', 'reopen', 'reorder'].includes(
                context.operation,
            ) &&
            (['create', 'reorder'].includes(context.operation)
                ? context.taskId === null
                : positive(context.taskId))
        );
    return (
        positive(actorId) &&
        Object.hasOwn(fieldsByPurpose, context.purpose) &&
        (!['requester_intake', 'technician_intake'].includes(context.purpose)
            ? positive(context.ticketId) && context.requestUuid === undefined
            : typeof context.requestUuid === 'string' &&
              context.ticketId === undefined &&
              IT_DRAFT_UUID.test(context.requestUuid))
    );
}
/** Metadata is accepted only for the caller's exact purpose/audience/context. */
export function readDraftMetadata(
    value: unknown,
    context: ItDraftContext,
): ItDraftMetadata | null {
    if (
        context.purpose === 'task_work' ||
        context.purpose === 'approval_work' ||
        context.purpose === 'merge_work' ||
        !draftRecord(value) ||
        value.purpose !== context.purpose ||
        value.context_key !== draftContextKey(context) ||
        value.audience !== draftAudience(context.purpose) ||
        typeof value.draft_uuid !== 'string' ||
        !IT_DRAFT_UUID.test(value.draft_uuid) ||
        value.ticket_id !== (context.ticketId ?? null) ||
        value.request_uuid !== (context.requestUuid?.toLowerCase() ?? null) ||
        !natural(value.revision) ||
        !['active', 'consumed', 'discarded', 'expired'].includes(
            String(value.state),
        ) ||
        typeof value.has_content !== 'boolean' ||
        !(value.saved_at === null || date(value.saved_at)) ||
        !date(value.expires_at) ||
        !nullableId(value.base_ticket_version) ||
        !nullableId(value.current_ticket_version) ||
        !draftRecord(value.files) ||
        !['ready', 'pending', 'cleanup_pending'].every((key) =>
            natural((value.files as Record<string, unknown>)[key]),
        ) ||
        !draftRecord(value.capabilities) ||
        !['read', 'save', 'submit', 'discard', 'start_new'].every(
            (key) =>
                typeof (value.capabilities as Record<string, unknown>)[key] ===
                'boolean',
        )
    )
        return null;
    const blocker = value.blocker;
    if (
        blocker !== null &&
        (!draftRecord(blocker) ||
            typeof blocker.code !== 'string' ||
            blocker.code.length > 80 ||
            typeof blocker.message !== 'string' ||
            blocker.message.length > 2000 ||
            (blocker.recovery_url !== undefined &&
                blocker.recovery_url !==
                    `/it/ticket-commands/${context.requestUuid?.toLowerCase()}`))
    )
        return null;
    if (
        value.cleanup !== undefined &&
        (!draftRecord(value.cleanup) ||
            !natural(value.cleanup.deleted) ||
            !natural(value.cleanup.failed))
    )
        return null;
    // Construct an allowlisted projection; never propagate unexpected private fields.
    return {
        draft_uuid: value.draft_uuid,
        purpose: context.purpose,
        context_key: draftContextKey(context),
        audience: draftAudience(context.purpose),
        ticket_id: context.ticketId ?? null,
        request_uuid: context.requestUuid?.toLowerCase() ?? null,
        revision: value.revision,
        state: value.state as ItDraftMetadata['state'],
        has_content: value.has_content,
        saved_at: value.saved_at as string | null,
        expires_at: value.expires_at,
        base_ticket_version: value.base_ticket_version as number | null,
        current_ticket_version: value.current_ticket_version as number | null,
        files: {
            ready: value.files.ready as number,
            pending: value.files.pending as number,
            cleanup_pending: value.files.cleanup_pending as number,
        },
        capabilities: {
            read: value.capabilities.read as boolean,
            save: value.capabilities.save as boolean,
            submit: value.capabilities.submit as boolean,
            discard: value.capabilities.discard as boolean,
            start_new: value.capabilities.start_new as boolean,
        },
        blocker:
            blocker === null
                ? null
                : {
                      code: (blocker as Record<string, string>).code,
                      message: (blocker as Record<string, string>).message,
                      ...((blocker as Record<string, string>).recovery_url
                          ? {
                                recovery_url: (
                                    blocker as Record<string, string>
                                ).recovery_url,
                            }
                          : {}),
                  },
        ...(value.cleanup
            ? {
                  cleanup: {
                      deleted: (value.cleanup as { deleted: number }).deleted,
                      failed: (value.cleanup as { failed: number }).failed,
                  },
              }
            : {}),
    };
}
const intake = [
    'title',
    'description',
    'category',
    'site_id',
    'impact',
    'urgency',
];
const triage = [
    'category',
    'subcategory',
    'priority',
    'impact',
    'urgency',
    'priority_reason',
    'routing_reason',
    'site_id',
    'is_organisation_wide',
    'it_service_id',
    'work_type',
    'assigned_to_user_id',
    'asset_id',
];
const fieldsByPurpose: Record<ItDraftPurpose, string[]> = {
    merge_work: ['reason', 'target_ticket_id', 'target_version'],
    approval_work: [
        'reason',
        'decision',
        'primary_approver_user_id',
        'cover_approver_user_id',
        'expires_at',
        'remind_at',
    ],
    task_work: [
        'title',
        'description',
        'status',
        'team_id',
        'assigned_to_user_id',
        'due_at',
        'is_required',
        'evidence_required',
        'sort_order',
        'dependency_ids',
        'approval_id',
        'ordered_ids',
        'reason',
        'completion_note',
        'evidence',
    ],
    requester_intake: intake,
    technician_intake: [
        ...intake,
        ...triage,
        'requester_user_id',
        'device_id',
        'provisioning_request_id',
        'watchers',
    ],
    public_reply: ['body'],
    internal_note: ['body'],
    public_resolution: [
        'note',
        'resolution_code',
        'resolution_verification',
        'notify_requester',
    ],
    ticket_edit: [
        ...triage,
        'status',
        'queue_id',
        'owner_user_id',
        'release_priority_override',
        'release_routing_override',
        'waiting_party',
        'waiting_reason',
        'next_action',
        'resolution_code',
        'resolution_summary',
    ],
};
export function readDraftPayload(
    value: unknown,
    purpose: ItDraftPurpose,
): ItDraftPayload | null {
    if (
        !Object.hasOwn(fieldsByPurpose, purpose) ||
        !draftRecord(value) ||
        !natural(value.step_index) ||
        value.step_index > 20 ||
        !(
            draftRecord(value.fields) ||
            (Array.isArray(value.fields) && value.fields.length === 0)
        )
    )
        return null;
    const fields: Record<string, ItDraftFieldValue> = {};
    for (const [key, rawField] of Object.entries(
        value.fields as Record<string, unknown>,
    )) {
        let field = rawField;
        if (!fieldsByPurpose[purpose].includes(key)) return null;
        const booleanField = [
            'is_organisation_wide',
            'notify_requester',
            'release_priority_override',
            'release_routing_override',
            'is_required',
            'evidence_required',
        ].includes(key);
        if (booleanField && (field === 0 || field === '0')) field = false;
        if (booleanField && (field === 1 || field === '1')) field = true;
        const idValue = (value: unknown) =>
            typeof value === 'string' && /^[1-9][0-9]*$/.test(value)
                ? Number(value)
                : value;
        if (key.endsWith('_id')) field = idValue(field);
        if (
            ['watchers', 'dependency_ids', 'ordered_ids'].includes(key) &&
            Array.isArray(field)
        )
            field = field.map(idValue);
        const valid = booleanField
            ? typeof field === 'boolean'
            : field === null ||
              (key === 'sort_order'
                  ? natural(field) && field <= 1000000
                  : key === 'evidence'
                    ? Array.isArray(field) &&
                      field.length <= 20 &&
                      field.every(
                          (entry) =>
                              typeof entry === 'string' && entry.length <= 2000,
                      )
                    : ['watchers', 'dependency_ids', 'ordered_ids'].includes(
                            key,
                        )
                      ? Array.isArray(field) &&
                        field.length <= (key === 'watchers' ? 100 : 1000) &&
                        field.every(positive) &&
                        new Set(field).size === field.length
                      : key.endsWith('_id') || key === 'target_version'
                        ? positive(field)
                        : typeof field === 'string' && field.length <= 5000);
        if (!valid) return null;
        fields[key] = field as ItDraftFieldValue;
    }
    if (new TextEncoder().encode(JSON.stringify(fields)).length > 65536)
        return null;
    return { fields: fields as ItDraftFields, step_index: value.step_index };
}
export function readDraftAttachment(value: unknown): ItDraftAttachment | null {
    if (
        !draftRecord(value) ||
        !positive(value.id) ||
        typeof value.upload_uuid !== 'string' ||
        !IT_DRAFT_UUID.test(value.upload_uuid) ||
        typeof value.name !== 'string' ||
        value.name.length > 255 ||
        typeof value.mime !== 'string' ||
        value.mime.length > 255 ||
        !natural(value.size) ||
        !['reserved', 'ready', 'failed'].includes(String(value.state)) ||
        (value.state === 'ready'
            ? value.download_url !== `/it/attachments/${value.id}`
            : value.download_url !== null)
    )
        return null;
    return {
        id: value.id,
        upload_uuid: value.upload_uuid,
        name: value.name,
        mime: value.mime,
        size: value.size,
        state: value.state as ItDraftAttachment['state'],
        download_url: value.download_url as string | null,
    };
}
export function draftSnapshotKey(snapshot: ItDraftSnapshot): string {
    return JSON.stringify({
        fields: Object.fromEntries(
            Object.entries(snapshot.fields).sort(([a], [b]) =>
                a.localeCompare(b),
            ),
        ),
        step_index: snapshot.step_index,
        base_ticket_version: snapshot.base_ticket_version ?? null,
    });
}
