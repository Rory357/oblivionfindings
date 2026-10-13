export type ApproverRead = Readonly<{
    version: 1;
    state: 'pending_setup' | 'configured' | 'unavailable';
    actor_id: number;
    read_generation: string;
    scope_site_ids: readonly number[];
    checked_at: string;
    revision: string | null;
    authorized_user_ids: readonly number[];
    selected_users: readonly { id: number; name: string; eligible: boolean }[];
    candidate_options: readonly { id: number; name: string }[];
    can_configure: boolean;
}>;

export type ApproverCommand = Readonly<{
    command_id: string;
    expected_revision: string | null;
    authorized_user_ids: readonly number[];
    reason: string;
}>;

export type ApproverAttempt = Readonly<{
    actorId: number;
    command: ApproverCommand;
    intentHash: string;
}>;

/** Enough to check an original outcome after reload, without retaining the
 * selected people, their names or the manager's reason in browser storage. */
export type ApproverSaveCorrelation = Readonly<{
    actorId: number;
    command: Pick<ApproverCommand, 'command_id' | 'expected_revision'>;
    intentHash: string;
}>;

export const approverSettingsUrl = '/operations/work-restriction-approvers';
const revision = (value: unknown): value is string =>
    typeof value === 'string' && value.length === 64 && /^[a-f0-9]{64}$/.test(value);
const positiveId = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const asciiTrim = (value: string): string =>
    value.replace(/^[\x00\x09\x0a\x0b\x0d\x20]+|[\x00\x09\x0a\x0b\x0d\x20]+$/g, '');
const wellFormedText = (value: string): boolean =>
    !/[\uD800-\uDBFF](?![\uDC00-\uDFFF])|(?:^|[^\uD800-\uDBFF])[\uDC00-\uDFFF]/.test(value);
const personName = (value: unknown): value is string =>
    typeof value === 'string' && asciiTrim(value).length > 0 && wellFormedText(value);
const record = (value: unknown): Record<string, unknown> | null =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? value as Record<string, unknown>
        : null;
const ids = (value: unknown): value is number[] =>
    Array.isArray(value) && value.every(positiveId) &&
    value.every((id, index) => index === 0 || value[index - 1] < id);

/** Never turn a partial, actor-mismatched or unavailable global list into an editor. */
export function parseApproverRead(value: unknown, actorId: number): ApproverRead | null {
    const data = record(value);
    if (!positiveId(actorId) || !data || data.version !== 1 || data.actor_id !== actorId ||
        typeof data.state !== 'string' || !['pending_setup', 'configured', 'unavailable'].includes(data.state) ||
        typeof data.read_generation !== 'string' || data.read_generation.length !== 32 || !/^[a-f0-9]{32}$/.test(data.read_generation) ||
        typeof data.checked_at !== 'string' || data.checked_at.length !== 20 || !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(data.checked_at) ||
        !Number.isFinite(Date.parse(data.checked_at)) ||
        !ids(data.scope_site_ids) || !ids(data.authorized_user_ids) ||
        !Array.isArray(data.selected_users) || !Array.isArray(data.candidate_options) ||
        typeof data.can_configure !== 'boolean') return null;

    const selected = data.selected_users.map(record);
    const candidates = data.candidate_options.map(record);
    const authorizedIds = data.authorized_user_ids;
    if (new Date(data.checked_at).toISOString() !== data.checked_at.replace('Z', '.000Z')) return null;
    if (selected.some((person) => !person || !positiveId(person.id) ||
        !personName(person.name) || typeof person.eligible !== 'boolean') ||
        candidates.some((person) => !person || !positiveId(person.id) ||
            !personName(person.name))) return null;
    const selectedIds = selected.map((person) => Number(person!.id)).sort((a, b) => a - b);
    const candidateIds = candidates.map((person) => Number(person!.id));
    if (new Set(candidateIds).size !== candidateIds.length ||
        selectedIds.length !== authorizedIds.length ||
        selectedIds.some((id, index) => id !== authorizedIds[index])) return null;
    if (selected.some((person) => person!.eligible === true &&
        !candidates.some((candidate) => candidate!.id === person!.id && candidate!.name === person!.name))) return null;
    if (selected.some((person) => person!.eligible === false &&
        candidates.some((candidate) => candidate!.id === person!.id))) return null;

    if (data.state === 'unavailable') {
        if (data.can_configure || data.revision !== null || data.scope_site_ids.length ||
            data.authorized_user_ids.length || selected.length || candidates.length) return null;
    } else if (!data.can_configure || data.scope_site_ids.length === 0) {
        return null;
    } else if (data.state === 'pending_setup') {
        if (data.revision !== null || data.authorized_user_ids.length || selected.length) return null;
    } else if (!revision(data.revision)) return null;

    return {
        version: 1,
        state: data.state as ApproverRead['state'],
        actor_id: actorId,
        read_generation: data.read_generation,
        scope_site_ids: [...data.scope_site_ids],
        checked_at: data.checked_at,
        revision: data.revision as string | null,
        authorized_user_ids: [...data.authorized_user_ids],
        selected_users: selected.map((person) => ({
            id: person!.id as number, name: person!.name as string, eligible: person!.eligible as boolean,
        })),
        candidate_options: candidates.map((person) => ({ id: person!.id as number, name: person!.name as string })),
        can_configure: data.can_configure,
    };
}

/** PHP ASCII trim and canonical IDs; never coerce an ID or normalise a person's text. */
export function approverCommand(
    commandId: string, expectedRevision: string | null, selectedIds: readonly number[], reason: string,
): ApproverCommand | null {
    const trimmed = asciiTrim(reason);
    if (commandId.length !== 36 || !/^[a-f0-9]{8}-[a-f0-9]{4}-[1-8][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/.test(commandId) ||
        (expectedRevision !== null && !revision(expectedRevision)) ||
        !Array.isArray(selectedIds) || !selectedIds.every(positiveId) ||
        !trimmed || [...trimmed].length > 2000 || /^[\s\p{Z}\u200B\uFEFF]*$/u.test(trimmed) ||
        !wellFormedText(trimmed)) return null;
    return Object.freeze({
        command_id: commandId,
        expected_revision: expectedRevision,
        authorized_user_ids: Object.freeze([...new Set(selectedIds)].sort((a, b) => a - b)),
        reason: trimmed,
    });
}

/** Match PHP's sorted compact JSON, including its escaped Unicode line separators. */
export function approverIntentJson(command: ApproverCommand): string {
    return JSON.stringify({
        authorized_user_ids: command.authorized_user_ids,
        command_id: command.command_id,
        expected_revision: command.expected_revision,
        reason: command.reason,
    }).replace(/\u2028/g, '\\u2028').replace(/\u2029/g, '\\u2029');
}

export async function prepareApproverAttempt(actorId: number, command: ApproverCommand): Promise<ApproverAttempt> {
    if (!positiveId(actorId)) throw new Error('Check the current account before saving.');
    const stable = approverCommand(
        command.command_id, command.expected_revision, command.authorized_user_ids, command.reason,
    );
    if (!stable) throw new Error('Review the selected people and reason before saving.');
    const body = new TextEncoder().encode(
        `oblivion.work_restriction.approver_list_command.v1\0${approverIntentJson(stable)}`,
    );
    const digest = await crypto.subtle.digest('SHA-256', body);
    const intentHash = Array.from(new Uint8Array(digest), (byte) => byte.toString(16).padStart(2, '0')).join('');
    return Object.freeze({ actorId, command: stable, intentHash });
}

/** Only the original actor/UUID/intent/revisions can confirm an earlier save. */
export function matchedApproverSave(value: unknown, attempt: ApproverSaveCorrelation): boolean {
    const response = record(value);
    const result = record(response?.result);
    return response?.status === 'saved' && result !== null && result.version === 1 &&
        result.actor_id === attempt.actorId && result.command_id === attempt.command.command_id &&
        result.action === 'hr.workRestrictions.approvers.updated' &&
        result.source_key === 'hr.work_restriction_approvers' &&
        result.prior_revision === attempt.command.expected_revision && revision(result.revision) &&
        result.revision !== result.prior_revision && result.intent_hash === attempt.intentHash;
}
