export type PersonalCalendarKind =
    | 'task'
    | 'meeting'
    | 'appointment'
    | 'reminder';
export interface PersonalCalendarEntry {
    id: number;
    kind: PersonalCalendarKind;
    title: string;
    description: string | null;
    location: string | null;
    start_at: string;
    end_at: string | null;
    all_day: boolean;
    status: 'scheduled' | 'completed' | 'cancelled';
    version: number;
}

export async function personalCalendarRequest(
    path = '',
    method = 'GET',
    data?: unknown,
): Promise<PersonalCalendarEntry> {
    const token = document.cookie
        .split('; ')
        .find((cookie) => cookie.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    const response = await fetch(`/my-calendar/entries${path}`, {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            ...(token
                ? { 'X-XSRF-TOKEN': decodeURIComponent(token) }
                : csrf
                  ? { 'X-CSRF-TOKEN': csrf }
                  : {}),
        },
        ...(data === undefined ? {} : { body: JSON.stringify(data) }),
    });
    const result = await response.json().catch(() => ({}));
    if (!response.ok) {
        const errors = result.errors
            ? Object.values(result.errors).flat().join(' ')
            : null;
        throw new Error(
            errors ||
                (response.status === 409 ? result.message : null) ||
                (response.status === 404
                    ? 'This entry is no longer available.'
                    : 'Could not save your calendar. Please try again.'),
        );
    }
    if (!result.entry?.id || !result.entry?.version)
        throw new Error(
            'Your session may have expired. Reload the page and sign in before trying again.',
        );
    return result.entry;
}

export function localDateInput(date: Date): string {
    const pad = (n: number) => String(n).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}
