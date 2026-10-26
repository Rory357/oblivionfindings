import { object } from './_command-state';

export type RecoveryKind = 'uploads' | 'removals' | 'relocations';
export type RecoveryAttempt = { id: number; state: string; recorded_at: string | null; can_retry: boolean; command?: string };
export type RecoveryPage = { attempts: RecoveryAttempt[]; next_cursor: number | null };
const positive = (value: unknown): value is number => Number.isSafeInteger(value) && Number(value) > 0;
const STATES = { uploads: ['staged', 'accepted', 'cleanup_pending', 'unknown', 'cleaned'], removals: ['pending', 'complete', 'unknown'], relocations: ['pending', 'complete', 'unknown'] };
export function checkedRecoveryAttempt(value: unknown, kind: RecoveryKind, id?: number): RecoveryAttempt | null {
    if (!object(value) || !positive(value.id) || (id != null && value.id !== id) || !STATES[kind].includes(String(value.state)) ||
        (kind === 'uploads' ? typeof value.accepted !== 'boolean' : kind === 'relocations' ?
            value.purpose !== 'legacy_public_origin' || value.private_reference_recorded !== true || value.public_copy_cleanup !== value.state || 'removed_from_record' in value :
            value.removed_from_record !== true)) return null;
    return { id: value.id, state: String(value.state), recorded_at: typeof value.recorded_at === 'string' ? value.recorded_at : null,
        can_retry: value.can_retry !== false && (kind === 'uploads' ? value.accepted === false && !['accepted', 'cleaned'].includes(String(value.state)) : value.state !== 'complete'),
        ...(typeof value.command === 'string' ? { command: value.command } : {}) };
}
export function checkedRecoveryPage(value: unknown, kind: RecoveryKind, afterId = 0): RecoveryPage | null {
    if (!object(value) || !Array.isArray(value.attempts) || value.attempts.length > 25 ||
        !(value.next_cursor === null || positive(value.next_cursor) && value.next_cursor > afterId)) return null;
    const attempts = value.attempts.map((row) => checkedRecoveryAttempt(row, kind));
    if (attempts.some((row) => !row || row.id <= afterId) || new Set(attempts.map((row) => row?.id)).size !== attempts.length) return null;
    return { attempts: attempts as RecoveryAttempt[], next_cursor: value.next_cursor as number | null };
}
export function recoveryStateLabel(kind: RecoveryKind, state: string): string {
    if (kind === 'relocations') return ({ pending: 'Old public copy cleanup pending', complete: 'Old public copy cleanup confirmed', unknown: 'Old public copy cleanup not confirmed' } as Record<string, string>)[state] ?? 'State not confirmed';
    return kind === 'removals' ? ({ pending: 'File cleanup pending', complete: 'File cleanup confirmed', unknown: 'File cleanup not confirmed' } as Record<string, string>)[state] ?? 'State not confirmed' :
        ({ staged: 'Unaccepted file attempt', accepted: 'Saved evidence · protected', cleanup_pending: 'Rejected-file cleanup pending', unknown: 'Rejected-file cleanup not confirmed', cleaned: 'Rejected-file cleanup confirmed' } as Record<string, string>)[state] ?? 'State not confirmed';
}
