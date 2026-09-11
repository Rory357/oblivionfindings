import { draftRecord } from './it-ticket-draft-contract';

export interface ItWorkTaskBlocker {
    code: string;
    message: string;
    task_id: number | null;
    approval_id: number | null;
}
export interface ItWorkTaskReadiness {
    storage_ready: boolean;
    prerequisites: 'ready' | 'blocked' | 'unknown';
    completion: 'valid' | 'invalid' | 'unknown' | 'none';
    can_start: boolean;
    can_complete: boolean;
    can_edit: boolean;
    can_cancel: boolean;
    can_restore: boolean;
    can_reopen: boolean;
    blockers: ItWorkTaskBlocker[];
    warnings: ItWorkTaskBlocker[];
}

/** Saved status and currently verified evidence are separate facts. */
export function summarizeItWorkTasks(
    tasks: readonly {
        status: string;
        is_required: boolean;
        readiness?: ItWorkTaskReadiness;
    }[],
) {
    return {
        total: tasks.length,
        completed: tasks.filter(
            (task) =>
                task.status === 'completed' &&
                task.readiness?.completion === 'valid',
        ).length,
        needsReview: tasks.filter(
            (task) =>
                task.status === 'completed' &&
                task.readiness?.completion === 'invalid',
        ).length,
        unverified: tasks.filter(
            (task) =>
                task.status === 'completed' &&
                !['valid', 'invalid'].includes(
                    task.readiness?.completion ?? '',
                ),
        ).length,
        // Unknown legacy provenance is labelled, without inventing a blocking policy.
        outstanding: tasks.filter(
            (task) =>
                task.is_required &&
                (task.status !== 'completed' ||
                    task.readiness?.completion === 'invalid'),
        ).length,
    };
}
export interface ItWorkTaskCompletion {
    id: number;
    sequence: number;
    source: 'command' | 'legacy_snapshot';
    recorded_at: string;
    completed_at: string | null;
    completed_by_user_id: number | null;
    completed_by: { id: number; name: string } | null;
    recorded_by_user_id: number | null;
    recorded_by: { id: number; name: string } | null;
    task_definition: {
        title: string;
        description: string | null;
        is_required: boolean;
        evidence_required: boolean;
        team_id: number | null;
        assigned_to_user_id: number | null;
        due_at: string | null;
    };
    prerequisite_completions:
        | { task_id: number; completion_id: number }[]
        | null;
    approval_id: number | null;
    completion_note: string | null;
    evidence: string[] | null;
}
export interface ItWorkTaskHistoryPage {
    viewer_user_id: number;
    ticket_id: number;
    task_id: number;
    lock_version: number;
    review_nonce: string;
    current_completion_id: number | null;
    readiness: ItWorkTaskReadiness;
    affected_tasks: { id: number; title: string; status: string }[];
    history: {
        entries: ItWorkTaskCompletion[];
        has_more: boolean;
        next_before_sequence: number | null;
        total_count: number;
    };
}
const positive = (value: unknown): value is number =>
    Number.isSafeInteger(value) && Number(value) > 0;
const nullableId = (value: unknown) => value === null || positive(value);
const text = (value: unknown, max: number): value is string =>
    typeof value === 'string' && value.length <= max;
const nullableText = (value: unknown, max: number) =>
    value === null || text(value, max);
const date = (value: unknown): value is string =>
    text(value, 60) && Number.isFinite(Date.parse(value));
const nullableDate = (value: unknown) => value === null || date(value);
const person = (value: unknown, id: unknown) =>
    value === null ||
    (draftRecord(value) &&
        positive(value.id) &&
        value.id === id &&
        text(value.name, 255));
const unique = <T>(items: T[], key: (item: T) => unknown) =>
    new Set(items.map(key)).size === items.length;

export function readItWorkTaskReadiness(
    value: unknown,
): ItWorkTaskReadiness | null {
    const blockers = (items: unknown) =>
        Array.isArray(items) &&
        items.length <= 1000 &&
        items.every(
            (item) =>
                draftRecord(item) &&
                text(item.code, 100) &&
                text(item.message, 2000) &&
                nullableId(item.task_id) &&
                nullableId(item.approval_id),
        );
    if (
        !draftRecord(value) ||
        !['ready', 'blocked', 'unknown'].includes(
            String(value.prerequisites),
        ) ||
        !['valid', 'invalid', 'unknown', 'none'].includes(
            String(value.completion),
        ) ||
        ![
            'storage_ready',
            'can_start',
            'can_complete',
            'can_edit',
            'can_cancel',
            'can_restore',
            'can_reopen',
        ].every((key) => typeof value[key] === 'boolean') ||
        !blockers(value.blockers) ||
        !blockers(value.warnings)
    )
        return null;
    return value as unknown as ItWorkTaskReadiness;
}
function completion(value: unknown): value is ItWorkTaskCompletion {
    if (
        !draftRecord(value) ||
        !positive(value.id) ||
        !positive(value.sequence) ||
        !['command', 'legacy_snapshot'].includes(String(value.source)) ||
        !date(value.recorded_at) ||
        !nullableDate(value.completed_at) ||
        !nullableId(value.completed_by_user_id) ||
        !person(value.completed_by, value.completed_by_user_id) ||
        !nullableId(value.recorded_by_user_id) ||
        !person(value.recorded_by, value.recorded_by_user_id) ||
        !nullableId(value.approval_id) ||
        !nullableText(value.completion_note, 5000)
    )
        return false;
    const definition = value.task_definition;
    if (
        !draftRecord(definition) ||
        !text(definition.title, 255) ||
        !nullableText(definition.description, 5000) ||
        typeof definition.is_required !== 'boolean' ||
        typeof definition.evidence_required !== 'boolean' ||
        !nullableId(definition.team_id) ||
        !nullableId(definition.assigned_to_user_id) ||
        !nullableDate(definition.due_at)
    )
        return false;
    const prerequisites = value.prerequisite_completions;
    if (
        prerequisites !== null &&
        (!Array.isArray(prerequisites) ||
            prerequisites.length > 1000 ||
            !prerequisites.every(
                (entry) =>
                    draftRecord(entry) &&
                    positive(entry.task_id) &&
                    positive(entry.completion_id),
            ) ||
            !unique(prerequisites, (entry) => entry.task_id))
    )
        return false;
    return (
        value.evidence === null ||
        (Array.isArray(value.evidence) &&
            value.evidence.length <= 20 &&
            value.evidence.every((entry) => text(entry, 2000)))
    );
}
export function readItWorkTaskHistoryPage(
    body: unknown,
    identity: {
        actorId: number;
        ticketId: number;
        taskId: number;
        nonce: string;
        beforeSequence?: number;
    },
): ItWorkTaskHistoryPage | null {
    if (!draftRecord(body) || body.status !== 'ok' || !draftRecord(body.data))
        return null;
    const data = body.data;
    if (
        data.viewer_user_id !== identity.actorId ||
        data.ticket_id !== identity.ticketId ||
        data.task_id !== identity.taskId ||
        data.review_nonce !== identity.nonce ||
        !positive(data.lock_version) ||
        !nullableId(data.current_completion_id) ||
        !readItWorkTaskReadiness(data.readiness)
    )
        return null;
    if (
        !Array.isArray(data.affected_tasks) ||
        data.affected_tasks.length > 1000 ||
        !data.affected_tasks.every(
            (task) =>
                draftRecord(task) &&
                positive(task.id) &&
                text(task.title, 255) &&
                [
                    'pending',
                    'in_progress',
                    'blocked',
                    'completed',
                    'cancelled',
                ].includes(String(task.status)),
        ) ||
        !unique(data.affected_tasks, (task) => task.id)
    )
        return null;
    const history = data.history;
    if (
        !draftRecord(history) ||
        !Array.isArray(history.entries) ||
        history.entries.length > 20 ||
        !history.entries.every(completion) ||
        !unique(history.entries, (entry) => entry.id) ||
        typeof history.has_more !== 'boolean' ||
        !Number.isSafeInteger(history.total_count) ||
        Number(history.total_count) < history.entries.length
    )
        return null;
    const entries = history.entries;
    if (
        !entries.every(
            (entry, index) =>
                (identity.beforeSequence === undefined ||
                    entry.sequence < identity.beforeSequence) &&
                (index === 0 || entry.sequence < entries[index - 1].sequence),
        )
    )
        return null;
    if (
        history.has_more
            ? !entries.length ||
              history.next_before_sequence !== entries.at(-1)?.sequence
            : history.next_before_sequence !== null
    )
        return null;
    return data as unknown as ItWorkTaskHistoryPage;
}
