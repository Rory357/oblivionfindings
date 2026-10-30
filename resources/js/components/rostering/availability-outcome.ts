import type { EditAvailabilityBlock } from './edit-availability-dialog';

export type AvailabilityCommand = {
    action: 'create' | 'delete';
    staff_id: number;
    availability_id?: number;
    day_of_week: number;
    starts_at: string;
    ends_at: string;
    ends_next_day: boolean;
};
export type AvailabilityResult = AvailabilityCommand & {
    availability_id: number;
};
const record = (value: unknown): Record<string, unknown> | null =>
    value !== null && typeof value === 'object' && !Array.isArray(value)
        ? (value as Record<string, unknown>)
        : null;
const clock = (value: unknown): value is string =>
    typeof value === 'string' && /^([01]\d|2[0-3]):[0-5]\d$/.test(value);

export function availabilityResult(
    page: unknown,
    expected: AvailabilityCommand,
): AvailabilityResult | null {
    const props = record(record(page)?.props);
    const flash = record(props?.flash);
    const result = record(flash?.staff_availability_result);
    if (
        !result ||
        flash?.error ||
        Object.keys(record(props?.errors) ?? {}).length
    )
        return null;
    if (
        result.action !== expected.action ||
        result.staff_id !== expected.staff_id ||
        !Number.isSafeInteger(result.availability_id) ||
        Number(result.availability_id) <= 0 ||
        (expected.availability_id !== undefined &&
            result.availability_id !== expected.availability_id) ||
        result.day_of_week !== expected.day_of_week ||
        result.starts_at !== expected.starts_at ||
        result.ends_at !== expected.ends_at ||
        result.ends_next_day !== expected.ends_next_day
    )
        return null;
    return result as AvailabilityResult;
}

/** Accept only a complete, owner-matched weekly pattern from the requested read. */
export function availabilitySnapshot(
    page: unknown,
    staffId: number,
): { blocks: EditAvailabilityBlock[]; canManage: boolean } | null {
    const props = record(record(page)?.props);
    if (
        !props ||
        record(props.flash)?.error ||
        Object.keys(record(props.errors) ?? {}).length
    )
        return null;
    const staff = record(props.staffAvailabilitySummary)?.staff;
    const member = Array.isArray(staff)
        ? staff.map(record).find((row) => row?.id === staffId)
        : null;
    const standalone = record(props.user)?.id === staffId;
    const rows = member
        ? member.staff_availability
        : standalone
          ? props.availability
          : null;
    const canManage = member
        ? member.can_manage
        : standalone
          ? props.canManage
          : null;
    if (!Array.isArray(rows) || typeof canManage !== 'boolean') return null;
    const blocks: EditAvailabilityBlock[] = [];
    for (const value of rows) {
        const row = record(value);
        if (!row) return null;
        const start = member ? row.start_time : row.starts_at;
        const end = member ? row.end_time : row.ends_at;
        if (
            !Number.isSafeInteger(row.id) ||
            Number(row.id) <= 0 ||
            !Number.isInteger(row.day_of_week) ||
            Number(row.day_of_week) < 0 ||
            Number(row.day_of_week) > 6 ||
            !clock(start) ||
            !clock(end) ||
            typeof row.ends_next_day !== 'boolean'
        )
            return null;
        blocks.push({
            id: Number(row.id),
            day_of_week: Number(row.day_of_week),
            start_time: start,
            end_time: end,
            ends_next_day: row.ends_next_day,
        });
    }
    if (new Set(blocks.map((block) => block.id)).size !== blocks.length)
        return null;
    return { blocks, canManage };
}

export function matchesAvailability(
    block: EditAvailabilityBlock,
    command: AvailabilityCommand,
): boolean {
    return (
        block.day_of_week === command.day_of_week &&
        block.start_time === command.starts_at &&
        block.end_time === command.ends_at &&
        Boolean(block.ends_next_day) === command.ends_next_day
    );
}
