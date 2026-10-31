/** My Day reads the current worker-local day; it has no date/selection URL contract. */
export function myDayIncidentHref(
    context: { shiftId?: number | null; clientId?: number | null } = {},
): string {
    const params = new URLSearchParams();
    for (const [key, id] of [
        ['shift_id', context.shiftId],
        ['client_id', context.clientId],
    ] as const) {
        if (typeof id === 'number' && Number.isSafeInteger(id) && id > 0) {
            params.set(key, String(id));
        }
    }
    // A fresh read restores current access and day, never a report action or
    // locally selected filter. The incident destination validates context IDs.
    params.set('return_to', '/my-day');
    return `/incidents/create?${params.toString()}`;
}
