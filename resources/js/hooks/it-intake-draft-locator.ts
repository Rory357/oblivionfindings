import { IT_DRAFT_UUID } from './it-ticket-draft-contract';

export type IntakeDraftPurpose = 'requester_intake' | 'technician_intake';
function prefix(
    actorId: number | undefined,
    purpose: IntakeDraftPurpose,
): string | null {
    return Number.isSafeInteger(actorId) &&
        actorId! > 0 &&
        ['requester_intake', 'technician_intake'].includes(purpose)
        ? `it.draft-context.v1.actor.${actorId}.${purpose}.`
        : null;
}
/** Each context has its own UUID-only key: simultaneous tabs cannot overwrite another draft. */
export function intakeDraftLocators(
    enabled: boolean,
    actorId: number | undefined,
    purpose: IntakeDraftPurpose,
): string[] {
    const base = enabled ? prefix(actorId, purpose) : null;
    if (!base) return [];
    try {
        const ids: string[] = [];
        for (let index = 0; index < localStorage.length; index++) {
            const key = localStorage.key(index);
            if (!key?.startsWith(base)) continue;
            const uuid = key.slice(base.length);
            if (IT_DRAFT_UUID.test(uuid) && localStorage.getItem(key) === uuid)
                ids.push(uuid);
        }
        return ids.sort();
    } catch {
        return [];
    }
}
export function preferredIntakeDraft(
    enabled: boolean,
    actorId: number | undefined,
    purpose: IntakeDraftPurpose,
): string | undefined {
    const ids = intakeDraftLocators(enabled, actorId, purpose);
    if (!ids.length) return undefined;
    try {
        const preferred = localStorage.getItem(
            `${prefix(actorId, purpose)}preferred`,
        );
        return ids.find((id) => id === preferred) ?? ids[0];
    } catch {
        return ids[0];
    }
}
/** Call only after the canonical metadata acknowledgement for this exact context. */
export function retainIntakeDraft(
    actorId: number,
    purpose: IntakeDraftPurpose,
    uuid: string,
): boolean {
    const base = prefix(actorId, purpose);
    if (!base || !IT_DRAFT_UUID.test(uuid)) return false;
    try {
        localStorage.setItem(`${base}${uuid}`, uuid);
        // The pointer is a convenience only; all independent context keys remain.
        if (!localStorage.getItem(`${base}preferred`))
            localStorage.setItem(`${base}preferred`, uuid);
        return localStorage.getItem(`${base}${uuid}`) === uuid;
    } catch {
        return false;
    }
}
export function forgetIntakeDraft(
    actorId: number,
    purpose: IntakeDraftPurpose,
    uuid: string,
): void {
    const base = prefix(actorId, purpose);
    if (!base || !IT_DRAFT_UUID.test(uuid)) return;
    try {
        if (localStorage.getItem(`${base}${uuid}`) === uuid)
            localStorage.removeItem(`${base}${uuid}`);
        if (localStorage.getItem(`${base}preferred`) === uuid)
            localStorage.removeItem(`${base}preferred`);
    } catch {
        /* A stale locator never authorizes a read or write. */
    }
}
