/** Translate the roster's selected context to the existing full-list filter names. */
export function rosterShiftListUrl({
    from,
    to,
    siteIds,
    staffId,
    clientId,
}: {
    from: string;
    to: string;
    siteIds: readonly number[];
    staffId: number | null;
    clientId: number | null;
}) {
    const query = new URLSearchParams({ from, to });
    siteIds.forEach((id) => query.append('site_ids[]', String(id)));
    if (staffId !== null) query.set('user_id', String(staffId));
    if (clientId !== null) query.set('client_id', String(clientId));
    return `/operations/shifts?${query}`;
}
