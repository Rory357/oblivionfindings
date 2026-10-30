/** Only the Fleet landing route may be supplied as a return destination. */
export function safeFleetReturn(value: string | null): string | null {
    if (
        !value ||
        !/^\/fleet-assets\/vehicles(?:\?|$)/.test(value) ||
        /[\\\r\n]/.test(value)
    )
        return null;
    return value;
}

export function fleetReturnFromLocation(): string | null {
    return typeof window === 'undefined'
        ? null
        : safeFleetReturn(
              new URLSearchParams(window.location.search).get('return_to'),
          );
}

export function fleetSourceHref(source: string): string {
    const url = new URL(source, window.location.origin);
    url.searchParams.set(
        'return_to',
        window.location.pathname + window.location.search,
    );
    return url.pathname + url.search;
}
