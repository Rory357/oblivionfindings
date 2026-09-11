import type {
    ItWorkTaskCompletion,
    ItWorkTaskReadiness,
} from '@/hooks/it-work-task-lifecycle';

/** Explicit current server verdict used by task command/interaction fixtures. */
export const taskReadiness = (
    overrides: Partial<ItWorkTaskReadiness> = {},
): ItWorkTaskReadiness => ({
    storage_ready: true,
    prerequisites: 'ready',
    completion: 'none',
    can_start: true,
    can_complete: true,
    can_edit: true,
    can_cancel: false,
    can_restore: false,
    can_reopen: false,
    blockers: [],
    warnings: [],
    ...overrides,
});

export function taskHistoryResponse({
    nonce,
    taskId,
    version = 4,
    readiness = taskReadiness(),
    actorId = 7,
    ticketId = 42,
}: {
    nonce: string;
    taskId: number;
    version?: number;
    readiness?: ItWorkTaskReadiness;
    actorId?: number;
    ticketId?: number;
}) {
    return {
        status: 200,
        data: {
            status: 'ok',
            data: {
                viewer_user_id: actorId,
                ticket_id: ticketId,
                task_id: taskId,
                lock_version: version,
                review_nonce: nonce,
                current_completion_id: null,
                readiness,
                affected_tasks: [],
                history: {
                    entries: [],
                    has_more: false,
                    next_before_sequence: null,
                    total_count: 0,
                },
            },
        },
    };
}
export const taskCompletionEntry = (
    sequence: number,
): ItWorkTaskCompletion => ({
    id: sequence + 100,
    sequence,
    source: 'command',
    recorded_at: '2026-09-09T01:00:00Z',
    completed_at: '2026-09-09T01:00:00Z',
    completed_by_user_id: 7,
    completed_by: { id: 7, name: 'Recorded technician' },
    recorded_by_user_id: 7,
    recorded_by: { id: 7, name: 'Recorded technician' },
    task_definition: {
        title: 'Original task definition',
        description: 'Original private description',
        is_required: true,
        evidence_required: true,
        team_id: null,
        assigned_to_user_id: 7,
        due_at: null,
    },
    prerequisite_completions: [],
    approval_id: null,
    completion_note: `Evidence note ${sequence}`,
    evidence: [`Evidence ${sequence}`],
});
