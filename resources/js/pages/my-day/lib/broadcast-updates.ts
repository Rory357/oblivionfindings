import type { MyDayNotification } from './types';

const uuid = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const record = (value: unknown): value is Record<string, unknown> =>
    value !== null && typeof value === 'object' && !Array.isArray(value);

export function isBroadcastUpdate(value: MyDayNotification): boolean {
    // A malformed marker must never fall back to an ordinary preview.
    return Object.prototype.hasOwnProperty.call(value, 'broadcast');
}

export function broadcastReaderId(value: unknown): string | null {
    return record(value) && typeof value.read_id === 'string' && uuid.test(value.read_id)
        ? value.read_id
        : null;
}

/** Call only for a response already bound to a current request and snapshot. */
export function validateBroadcastUpdates(
    notifications: unknown,
    reader: unknown,
    viewerId: number | null,
): MyDayNotification[] | null {
    if (!Number.isSafeInteger(viewerId) || Number(viewerId) <= 0 ||
        !record(reader) || reader.schema_version !== 1 || reader.viewer_id !== viewerId ||
        !broadcastReaderId(reader) || typeof reader.checked_at !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}T.*(?:Z|\+00:00)$/.test(reader.checked_at) ||
        !Number.isFinite(Date.parse(reader.checked_at)) ||
        !record(reader.capabilities) ||
        typeof reader.capabilities.mark_read !== 'boolean' ||
        reader.capabilities.acknowledge !== false ||
        typeof reader.capabilities.bulk_mark_read !== 'boolean' ||
        !Array.isArray(notifications)) return null;

    const ids = new Set<string>();
    const items: MyDayNotification[] = [];
    for (const item of notifications) {
        if (!record(item)) return null;
        if (!Object.prototype.hasOwnProperty.call(item, 'broadcast')) continue;
        const typed = item.broadcast;
        if (!record(typed) || typed.schema_version !== 1 ||
            typeof item.id !== 'string' || !uuid.test(item.id) || ids.has(item.id) ||
            typed.notification_id !== item.id ||
            typed.recipient_viewer_id !== viewerId || typed.reader_read_id !== reader.read_id ||
            typed.href !== `/notifications?notification=${item.id}` ||
            typeof item.title !== 'string' || typeof item.at !== 'string' ||
            (item.tone !== undefined && !['primary', 'info', 'muted'].includes(String(item.tone))))
            return null;
        ids.add(item.id);
        items.push(item as unknown as MyDayNotification);
    }
    return items;
}
