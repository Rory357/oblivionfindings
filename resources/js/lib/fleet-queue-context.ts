/** Local return paths only, with the finite query vocabulary of the originating queues. */
const parameters: Record<string, string[]> = {
    '/fleet-assets/vehicles': [
        'search',
        'site_id',
        'status',
        'view',
        'page',
        'sort',
        'direction',
    ],
    '/fleet-assets/compliance': [
        'search',
        'site_id',
        'view',
        'state',
        'kind',
        'page',
        'layout',
    ],
    '/fleet-assets/alerts': [
        'search',
        'site_id',
        'entity',
        'asset_id',
        'status',
        'severity',
        'cr_page',
        'layout',
        'sort',
        'direction',
    ],
};

export function fleetQueueReturn(candidate: string | null): string | null {
    if (
        !candidate ||
        !candidate.startsWith('/fleet-assets/') ||
        /[\\\r\n]/.test(candidate)
    )
        return null;
    const url = new URL(candidate, 'https://fleet.invalid');
    const allowed = parameters[url.pathname];
    if (!allowed || url.origin !== 'https://fleet.invalid' || url.hash)
        return null;
    const query = new URLSearchParams();
    for (const key of allowed) {
        const value = url.searchParams.get(key);
        if (value !== null && value.length <= 200) query.set(key, value);
    }
    return url.pathname + (query.size ? `?${query}` : '');
}

export function fleetScopeHref(href: string, current: string): string {
    const target = new URL(href, 'https://fleet.invalid');
    if (
        !['/fleet-assets', ...Object.keys(parameters)].includes(target.pathname)
    )
        return href;
    const source = new URL(current, 'https://fleet.invalid');
    const site =
        source.searchParams.get('site_id') ?? source.searchParams.get('site');
    if (site && /^\d+$/.test(site))
        target.searchParams.set(
            target.pathname === '/fleet-assets' ? 'site' : 'site_id',
            site,
        );
    if (['/fleet-assets', '/fleet-assets/alerts'].includes(target.pathname)) {
        const entity = source.searchParams.get('entity');
        if (entity && ['vehicle', 'asset'].includes(entity))
            target.searchParams.set('entity', entity);
    }
    return target.pathname + target.search + target.hash;
}
