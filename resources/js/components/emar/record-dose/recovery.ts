import { getOfflineQueueActorId } from '@/lib/offline-queue';

// Unconfirmed requests survive an Inertia handoff or closing/reopening the
// dialog, but clinical drafts and witness secrets are never written to storage.
const pending = new Map<string, unknown>();
let owner: string | null = null;
if (typeof window !== 'undefined')
    window.addEventListener('emar:actor-changed', () => {
        pending.clear();
        owner = null;
    });
if (typeof window !== 'undefined')
    window.addEventListener('beforeunload', (event) => {
        if (pending.size) {
            event.preventDefault();
            event.returnValue = '';
        }
    });
function normalizedSlot(slot: string) {
    const time = Date.parse(slot);
    return slot === 'prn' || !Number.isFinite(time)
        ? slot
        : new Date(time).toISOString();
}
export function doseRecoveryKey(
    person: number,
    order: number,
    slot: string,
): string | null {
    const actor = getOfflineQueueActorId();
    if (actor !== owner) {
        pending.clear();
        owner = actor;
    }
    return actor
        ? JSON.stringify([actor, person, order, normalizedSlot(slot)])
        : null;
}
export function pendingDoseRecoveries<T>(
    person?: number,
): { key: string; draft: T; person: number; order: number; slot: string }[] {
    doseRecoveryKey(0, 0, 'prn');
    return [...pending].flatMap(([key, draft]) => {
        const [actor, personId, order, slot] = JSON.parse(key);
        return actor === owner && (person === undefined || person === personId)
            ? [
                  {
                      key,
                      draft: draft as T,
                      person: personId as number,
                      order: order as number,
                      slot: slot as string,
                  },
              ]
            : [];
    });
}
export function findDoseRecovery<T>(order: number, slot: string) {
    return (
        pendingDoseRecoveries<T>().find(
            (item) =>
                item.order === order && item.slot === normalizedSlot(slot),
        ) ?? null
    );
}
export function readDoseRecovery<T>(key: string | null): T | null {
    return key ? ((pending.get(key) as T | undefined) ?? null) : null;
}
export function keepDoseRecovery<T>(key: string | null, draft: T) {
    if (key) pending.set(key, draft);
}
export function clearDoseRecovery(key: string | null) {
    if (key) pending.delete(key);
}
