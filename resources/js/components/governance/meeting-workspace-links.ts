/**
 * Addressable locations inside the meeting workspace
 * (`/governance/meetings/{id}?tab=…&paper=…`). The workspace restores its tab
 * and selected paper from these params, so any record opened from a paper can
 * send the member straight back to the same place.
 */

export type MeetingWorkspaceFocus = 'follow-ups';

export function meetingWorkspaceUrl(
    meetingId: number | string,
    options: {
        tab?: string | null;
        paperId?: number | string | null;
        focus?: MeetingWorkspaceFocus | null;
    } = {},
): string {
    const params = new URLSearchParams();
    const tab = options.tab ?? (options.paperId != null ? 'resolutions' : null);
    if (tab) params.set('tab', tab);
    if (options.paperId != null) params.set('paper', String(options.paperId));
    if (options.focus) params.set('focus', options.focus);
    const query = params.toString();
    return `/governance/meetings/${meetingId}${query ? `?${query}` : ''}`;
}

/**
 * Canonical follow-up action link carrying `?return=` back to the paper's
 * follow-up list. Actions/Show honours only same-origin relative
 * `/governance/…` return paths, so the return value is always one.
 */
export function actionHrefWithReturn(
    openUrl: string,
    meetingId: number | string,
    paperId: number | string,
): string {
    const returnTo = meetingWorkspaceUrl(meetingId, {
        tab: 'resolutions',
        paperId,
        focus: 'follow-ups',
    });
    const [pathAndQuery, hash] = openUrl.split('#');
    const [path, query = ''] = (pathAndQuery ?? '').split('?');
    const params = new URLSearchParams(query);
    params.set('return', returnTo);
    return `${path}?${params.toString()}${hash ? `#${hash}` : ''}`;
}

/** Remove a single query param from a relative URL, keeping the rest. */
export function withoutQueryParam(url: string, name: string): string {
    const [pathAndQuery, hash] = url.split('#');
    const [path, query = ''] = (pathAndQuery ?? '').split('?');
    const params = new URLSearchParams(query);
    params.delete(name);
    const qs = params.toString();
    return `${path}${qs ? `?${qs}` : ''}${hash ? `#${hash}` : ''}`;
}

/** Set (or clear with null) query params on a relative URL. */
export function withQueryParams(
    url: string,
    patch: Record<string, string | null>,
): string {
    const [pathAndQuery, hash] = url.split('#');
    const [path, query = ''] = (pathAndQuery ?? '').split('?');
    const params = new URLSearchParams(query);
    for (const [key, value] of Object.entries(patch)) {
        if (value === null) params.delete(key);
        else params.set(key, value);
    }
    const qs = params.toString();
    return `${path}${qs ? `?${qs}` : ''}${hash ? `#${hash}` : ''}`;
}
