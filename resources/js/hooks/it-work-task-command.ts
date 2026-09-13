import { draftRecord, IT_DRAFT_UUID } from './it-ticket-draft-contract';
import {
    readItWorkTaskReadiness,
    type ItWorkTaskReadiness,
} from './it-work-task-lifecycle';

export type ItWorkTaskOperation =
    | 'create'
    | 'update'
    | 'complete'
    | 'reopen'
    | 'reorder';
export type ItWorkTaskFields = Partial<{
    title: string;
    description: string | null;
    status: 'pending' | 'in_progress' | 'blocked' | 'cancelled';
    team_id: number | null;
    assigned_to_user_id: number | null;
    due_at: string | null;
    is_required: boolean;
    evidence_required: boolean;
    sort_order: number;
    dependency_ids: number[];
    approval_id: number | null;
    reason: string;
    completion_note: string | null;
    evidence: string[] | null;
    ordered_ids: number[];
}>;
export interface ItWorkTaskIdentity {
    actorId: number;
    ticketId: number;
    operation: ItWorkTaskOperation;
    taskId: number | null;
    requestUuid: string;
    expectedVersion?: number;
}
export interface ItWorkTaskIntent extends ItWorkTaskIdentity {
    expectedVersion: number;
    fields: ItWorkTaskFields;
}
export interface ItWorkTaskCommitted {
    id: number;
    viewer_user_id: number;
    request_uuid: string;
    operation: `task.${ItWorkTaskOperation}`;
    task_id: number | null;
    lock_version: number;
    changed: boolean;
    replayed: boolean;
}
export interface ItWorkTaskCancelled {
    id: number;
    viewer_user_id: number;
    request_uuid: string;
    operation: `task.${ItWorkTaskOperation}`;
    task_id: number | null;
    cancelled_at: string;
    replayed: boolean;
}
export interface ItWorkTaskRecord {
    id: number;
    title: string;
    description: string | null;
    status: 'pending' | 'in_progress' | 'blocked' | 'completed' | 'cancelled';
    due_at: string | null;
    is_required: boolean;
    evidence_required: boolean;
    evidence: string[] | null;
    completion_note: string | null;
    completed_at: string | null;
    sort_order: number;
    team: { id: number; name: string } | null;
    assignee: { id: number; name: string } | null;
    completed_by: { id: number; name: string } | null;
    dependencies: { id: number; title: string; status: string }[];
    current_completion_id?: number | null;
    approval?: { id: number; status: string } | null;
    readiness?: ItWorkTaskReadiness;
}
export interface ItWorkTaskReview {
    viewerId: number;
    ticketId: number;
    version: number;
    canManage: boolean;
    tasks: ItWorkTaskRecord[];
    assignees: { id: number; name: string }[];
    teams: { id: number; name: string }[];
    approvals: { id: number; status: string }[];
}

export const taskFieldNames: Record<ItWorkTaskOperation, readonly string[]> = {
    create: [
        'title',
        'description',
        'team_id',
        'assigned_to_user_id',
        'due_at',
        'is_required',
        'evidence_required',
        'sort_order',
        'dependency_ids',
        'approval_id',
    ],
    update: [
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
        'reason',
    ],
    complete: ['completion_note', 'evidence'],
    reopen: ['reason'],
    reorder: ['ordered_ids'],
};
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const nullableText = (value: unknown, max: number) =>
    value === null || (typeof value === 'string' && value.length <= max);
const validIdList = (value: unknown) =>
    Array.isArray(value) &&
    value.length <= 1000 &&
    value.every(positive) &&
    new Set(value).size === value.length;
const validField = (key: string, value: unknown) => {
    switch (key) {
        case 'title':
            return typeof value === 'string' && value.length <= 255;
        case 'description':
        case 'completion_note':
            return nullableText(value, 5000);
        case 'reason':
            return typeof value === 'string' && value.length <= 2000;
        case 'status':
            return ['pending', 'in_progress', 'blocked', 'cancelled'].includes(
                String(value),
            );
        case 'team_id':
        case 'assigned_to_user_id':
        case 'approval_id':
            return value === null || positive(value);
        case 'due_at':
            return (
                value === null ||
                (typeof value === 'string' &&
                    value.length <= 50 &&
                    Number.isFinite(Date.parse(value)))
            );
        case 'is_required':
        case 'evidence_required':
            return typeof value === 'boolean';
        case 'sort_order':
            return (
                Number.isSafeInteger(value) &&
                Number(value) >= 0 &&
                Number(value) <= 1000000
            );
        case 'dependency_ids':
        case 'ordered_ids':
            return validIdList(value);
        case 'evidence':
            return (
                value === null ||
                (Array.isArray(value) &&
                    value.length <= 20 &&
                    value.every(
                        (entry) =>
                            typeof entry === 'string' &&
                            entry.trim().length > 0 &&
                            entry.length <= 2000,
                    ))
            );
        default:
            return false;
    }
};
export function validItWorkTaskIdentity(
    value: unknown,
): value is ItWorkTaskIdentity {
    if (
        !draftRecord(value) ||
        !positive(value.actorId) ||
        !positive(value.ticketId) ||
        typeof value.operation !== 'string' ||
        !Object.hasOwn(taskFieldNames, value.operation) ||
        typeof value.requestUuid !== 'string' ||
        !IT_DRAFT_UUID.test(value.requestUuid)
    )
        return false;
    return (
        (value.operation === 'create' || value.operation === 'reorder'
            ? value.taskId === null
            : positive(value.taskId)) &&
        (value.expectedVersion === undefined || positive(value.expectedVersion))
    );
}
export function freezeItWorkTaskIntent(
    value: unknown,
): Readonly<ItWorkTaskIntent> | null {
    if (
        !validItWorkTaskIdentity(value) ||
        !positive(value.expectedVersion) ||
        !draftRecord(value) ||
        !draftRecord(value.fields)
    )
        return null;
    const fields = value.fields;
    if (
        !Object.entries(fields).every(
            ([key, field]) =>
                taskFieldNames[value.operation].includes(key) &&
                validField(key, field),
        )
    )
        return null;
    if (
        value.operation === 'create' &&
        (typeof fields.title !== 'string' || !fields.title.trim())
    )
        return null;
    if (
        value.operation === 'reopen' &&
        (typeof fields.reason !== 'string' || !fields.reason.trim())
    )
        return null;
    if (value.operation === 'reorder' && !validIdList(fields.ordered_ids))
        return null;
    if (
        value.operation === 'update' &&
        fields.status === 'cancelled' &&
        (typeof fields.reason !== 'string' || !fields.reason.trim())
    )
        return null;
    const cloned = structuredClone(fields) as ItWorkTaskFields;
    for (const field of Object.values(cloned))
        if (Array.isArray(field)) Object.freeze(field);
    return Object.freeze({
        actorId: value.actorId,
        ticketId: value.ticketId,
        operation: value.operation,
        taskId: value.taskId,
        requestUuid: value.requestUuid.toLowerCase(),
        expectedVersion: value.expectedVersion,
        fields: Object.freeze(cloned),
    });
}
export const itWorkTaskReceiptPath = (identity: ItWorkTaskIdentity) =>
    `/it/tickets/${identity.ticketId}/task-commands/${identity.operation}/${identity.requestUuid}`;
export function itWorkTaskMutation(intent: ItWorkTaskIntent) {
    const base = `/it/tickets/${intent.ticketId}/tasks`;
    return {
        method:
            intent.operation === 'update' || intent.operation === 'reorder'
                ? ('patch' as const)
                : ('post' as const),
        url:
            intent.operation === 'create'
                ? base
                : intent.operation === 'reorder'
                  ? `${base}/reorder`
                  : `${base}/${intent.taskId}${intent.operation === 'update' ? '' : `/${intent.operation}`}`,
        data: {
            ...intent.fields,
            actor_user_id: intent.actorId,
            request_uuid: intent.requestUuid,
            expected_version: intent.expectedVersion,
        },
    };
}
function matchingResult(
    value: unknown,
    identity: ItWorkTaskIdentity,
): value is Record<string, unknown> {
    return (
        draftRecord(value) &&
        value.id === identity.ticketId &&
        value.viewer_user_id === identity.actorId &&
        value.request_uuid === identity.requestUuid &&
        value.operation === `task.${identity.operation}` &&
        typeof value.replayed === 'boolean'
    );
}
export function readItWorkTaskCommitted(
    body: unknown,
    identity: ItWorkTaskIdentity,
): ItWorkTaskCommitted | null {
    if (
        !draftRecord(body) ||
        body.status !== 'committed' ||
        !matchingResult(body.data, identity)
    )
        return null;
    const result = body.data;
    if (
        !positive(result.lock_version) ||
        typeof result.changed !== 'boolean' ||
        (identity.operation === 'create'
            ? !positive(result.task_id)
            : result.task_id !== identity.taskId)
    )
        return null;
    if (
        identity.expectedVersion !== undefined &&
        result.lock_version !==
            identity.expectedVersion + Number(result.changed)
    )
        return null;
    if (identity.operation === 'create' && !result.changed) return null;
    return {
        id: identity.ticketId,
        viewer_user_id: identity.actorId,
        request_uuid: identity.requestUuid,
        operation: `task.${identity.operation}`,
        task_id: result.task_id as number | null,
        lock_version: result.lock_version,
        changed: result.changed,
        replayed: result.replayed as boolean,
    };
}
export function readItWorkTaskCancelled(
    body: unknown,
    identity: ItWorkTaskIdentity,
): ItWorkTaskCancelled | null {
    if (
        !draftRecord(body) ||
        body.status !== 'cancelled' ||
        !matchingResult(body.data, identity) ||
        body.data.task_id !== identity.taskId ||
        typeof body.data.cancelled_at !== 'string' ||
        body.data.cancelled_at.length > 50 ||
        !Number.isFinite(Date.parse(body.data.cancelled_at))
    )
        return null;
    return {
        id: identity.ticketId,
        viewer_user_id: identity.actorId,
        request_uuid: identity.requestUuid,
        operation: `task.${identity.operation}`,
        task_id: identity.taskId,
        cancelled_at: body.data.cancelled_at,
        replayed: body.data.replayed as boolean,
    };
}
const option = (value: unknown): value is { id: number; name: string } =>
    draftRecord(value) &&
    positive(value.id) &&
    typeof value.name === 'string' &&
    value.name.length <= 255;
const nullableOption = (value: unknown) => value === null || option(value);
export function readItWorkTaskRecord(value: unknown): ItWorkTaskRecord | null {
    if (
        !draftRecord(value) ||
        !positive(value.id) ||
        typeof value.title !== 'string' ||
        value.title.length > 255 ||
        !nullableText(value.description, 5000) ||
        ![
            'pending',
            'in_progress',
            'blocked',
            'completed',
            'cancelled',
        ].includes(String(value.status)) ||
        !nullableText(value.due_at, 50) ||
        typeof value.is_required !== 'boolean' ||
        typeof value.evidence_required !== 'boolean' ||
        !validField('evidence', value.evidence) ||
        !nullableText(value.completion_note, 5000) ||
        !nullableText(value.completed_at, 50) ||
        !validField('sort_order', value.sort_order) ||
        !nullableOption(value.team) ||
        !nullableOption(value.assignee) ||
        !nullableOption(value.completed_by) ||
        !(
            value.current_completion_id === null ||
            positive(value.current_completion_id)
        ) ||
        !(
            value.approval === null ||
            (draftRecord(value.approval) &&
                positive(value.approval.id) &&
                typeof value.approval.status === 'string' &&
                value.approval.status.length <= 60)
        ) ||
        !readItWorkTaskReadiness(value.readiness) ||
        !Array.isArray(value.dependencies) ||
        value.dependencies.length > 1000
    )
        return null;
    if (
        !value.dependencies.every(
            (entry) =>
                draftRecord(entry) &&
                positive(entry.id) &&
                typeof entry.title === 'string' &&
                entry.title.length <= 255 &&
                [
                    'pending',
                    'in_progress',
                    'blocked',
                    'completed',
                    'cancelled',
                ].includes(String(entry.status)),
        )
    )
        return null;
    if (
        new Set(value.dependencies.map((entry) => entry.id)).size !==
        value.dependencies.length
    )
        return null;
    // Copy only canonical fields; a read response never injects arbitrary objects into a form.
    return Object.fromEntries(
        [
            'id',
            'title',
            'description',
            'status',
            'due_at',
            'is_required',
            'evidence_required',
            'evidence',
            'completion_note',
            'completed_at',
            'sort_order',
            'team',
            'assignee',
            'completed_by',
            'dependencies',
            'current_completion_id',
            'approval',
            'readiness',
        ].map((key) => [key, structuredClone(value[key])]),
    ) as unknown as ItWorkTaskRecord;
}
export function readItWorkTaskReview(
    body: unknown,
    identity: Pick<ItWorkTaskIdentity, 'actorId' | 'ticketId' | 'taskId'>,
): ItWorkTaskReview | null {
    if (
        !draftRecord(body) ||
        body.viewer_user_id !== identity.actorId ||
        !draftRecord(body.ticket) ||
        body.ticket.id !== identity.ticketId ||
        !positive(body.ticket.lock_version) ||
        !draftRecord(body.can) ||
        body.can.manage !== true ||
        !draftRecord(body.linked_context) ||
        !Array.isArray(body.linked_context.tasks) ||
        body.linked_context.tasks.length > 1000 ||
        !Array.isArray(body.assignees) ||
        !body.assignees.every(option) ||
        !Array.isArray(body.teamOptions) ||
        !body.teamOptions.every(option) ||
        !Array.isArray(body.approvals) ||
        !body.approvals.every(
            (item) =>
                draftRecord(item) &&
                positive(item.id) &&
                typeof item.status === 'string' &&
                item.status.length <= 60,
        )
    )
        return null;
    const tasks = body.linked_context.tasks.map(readItWorkTaskRecord);
    if (
        tasks.some((task) => task === null) ||
        new Set(tasks.map((task) => task!.id)).size !== tasks.length ||
        (identity.taskId !== null &&
            !tasks.some((task) => task?.id === identity.taskId))
    )
        return null;
    if (
        typeof body.ticket.status !== 'string' ||
        !Object.hasOwn(body.ticket, 'merged_into')
    )
        return null;
    return {
        viewerId: identity.actorId,
        ticketId: identity.ticketId,
        version: body.ticket.lock_version,
        canManage:
            ['open', 'in_progress', 'waiting'].includes(body.ticket.status) &&
            body.ticket.merged_into === null,
        tasks: tasks as ItWorkTaskRecord[],
        assignees: body.assignees.map(({ id, name }) => ({ id, name })),
        teams: body.teamOptions.map(({ id, name }) => ({ id, name })),
        approvals: body.approvals.map(({ id, status }) => ({ id, status })),
    };
}
