/** Normalize existing entry links without changing their house, day or view. */
export function medsTodayQuery(search: string): URLSearchParams {
    const params = new URLSearchParams(search);
    const personKey = ['client_id', 'client', 'pp'].find((key) =>
        params.has(key),
    );
    const person = personKey ? params.get(personKey) : null;
    params.delete('client');
    params.delete('pp');
    if (person) params.set('client_id', person);
    else params.delete('client_id');
    return params;
}

export function medsTodayHref(clientId: number, date?: string): string {
    const params = new URLSearchParams({ client_id: String(clientId) });
    if (date) params.set('date', date);
    return `/meds/today?${params}`;
}
