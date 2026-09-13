/** Canonical register selection also covers detail links and filtered deep links. */
export function vendorRegisterTab(
    url: string,
): 'vendors' | 'credentials' | null {
    const current = new URL(url, 'https://local.invalid');
    if (
        current.pathname !== '/vendors' &&
        !current.pathname.startsWith('/vendors/')
    )
        return null;
    return current.pathname === '/vendors' &&
        (current.searchParams.get('tab') === 'credentials' ||
            (!current.searchParams.has('tab') &&
                current.searchParams.has('credential_id')))
        ? 'credentials'
        : 'vendors';
}
