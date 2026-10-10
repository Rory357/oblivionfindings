import type { ShiftSaveProjection } from './use-shift-save-command';

export type ShiftSaveFields = {
    client_id: number | string;
    service_context_id: number | string | null;
    user_id: number | string | null;
    starts_at: string;
    ends_at: string;
    location: string | null;
    notes: string | null;
    status: 'draft' | 'scheduled';
    shift_type: string;
    is_sleepover: boolean;
    is_on_call: boolean;
    is_lone_worker: boolean;
    expected_break_minutes: number | string | null;
    coverage_roles: string[];
    required_licence_class: string | null;
    required_licence_endorsements: string[];
    tasks: Array<{
        id?: number | null;
        label: string;
        scheduled_time?: string | null;
        can_edit?: boolean;
    }> | null;
};
const edges =
    '[\\s\\u0000\\u0085\\u00ad\\u034f\\u061c\\u115f\\u1160\\u17b4\\u17b5\\u180e\\u200b-\\u200f\\u2060-\\u2065\\u206a-\\u206f\\u2800\\u3164\\uffa0\\u{1d159}\\u{1d173}-\\u{1d17a}\\u{e0020}]';
export function shiftSaveText(value: string | null): string | null {
    return (
        (value ?? '').replace(
            // eslint-disable-next-line no-misleading-character-class -- Match the application's Laravel edge trimming.
            new RegExp('^' + edges + '+|' + edges + '+$', 'gu'),
            '',
        ) || null
    );
}
function id(value: number | string | null): number | null {
    if (value === '' || value === null) return null;
    if (typeof value === 'string' && !/^\d+$/.test(value))
        throw new Error('Choose a valid record.');
    const result = Number(value);
    if (!Number.isSafeInteger(result) || result <= 0)
        throw new Error('Choose a valid record.');
    return result;
}
function instant(value: string): string {
    if (!/(?:Z|[+-]\d{2}:\d{2})$/.test(value))
        throw new Error('Choose a date and time in the roster timezone.');
    const date = new Date(value);
    if (!Number.isFinite(date.getTime()))
        throw new Error('Choose a valid date and time.');
    date.setUTCMilliseconds(0);
    return date.toISOString();
}
function requiredText(value: string) {
    const normalized = shiftSaveText(value);
    if (normalized === null) throw new Error('Choose a valid option.');
    return normalized;
}
function breakMinutes(value: string | number | null): number | null {
    if (value === null || value === '') return null;
    if (typeof value === 'string' && !/^\d+$/.test(value))
        throw new Error('Enter whole break minutes.');
    const result = Number(value);
    if (!Number.isSafeInteger(result) || result < 0)
        throw new Error('Enter whole break minutes.');
    return result;
}
/** The form supplies explicit planning values and already-resolved UTC instants.
 * This does not emulate omitted-field semantics for other server callers.
 * Protected tasks keep their separate source-owned edit path.
 */
export function shiftSaveProjection(
    fields: ShiftSaveFields,
): ShiftSaveProjection {
    const clientId = id(fields.client_id);
    if (clientId === null) throw new Error('Choose the person supported.');
    const userId = id(fields.user_id);
    const type = requiredText(fields.shift_type);
    const tasks =
        fields.tasks === null
            ? null
            : fields.tasks
                  .filter((task) => task.can_edit !== false)
                  .flatMap((task) => {
                      const label = shiftSaveText(task.label);
                      if (label === null) return [];
                      const time = shiftSaveText(task.scheduled_time ?? null);
                      if (
                          time !== null &&
                          !/^([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(time)
                      )
                          throw new Error('Choose a valid task time.');
                      return [
                          {
                              id: id(task.id ?? null),
                              label,
                              scheduled_time: time?.slice(0, 5) ?? null,
                          },
                      ];
                  });
    return {
        client_id: clientId,
        service_context_id: id(fields.service_context_id),
        user_id: userId,
        starts_at: instant(fields.starts_at),
        ends_at: instant(fields.ends_at),
        location: shiftSaveText(fields.location),
        notes: shiftSaveText(fields.notes),
        status:
            userId === null && fields.status === 'scheduled'
                ? 'draft'
                : fields.status,
        shift_type: type,
        is_sleepover: type === 'sleepover' || fields.is_sleepover,
        is_on_call: type === 'on_call' || fields.is_on_call,
        is_lone_worker: fields.is_lone_worker,
        expected_break_minutes: breakMinutes(fields.expected_break_minutes),
        coverage_roles: fields.coverage_roles.map(requiredText),
        required_licence_class: shiftSaveText(fields.required_licence_class),
        required_licence_endorsements:
            fields.required_licence_endorsements.map(requiredText),
        tasks,
    };
}
