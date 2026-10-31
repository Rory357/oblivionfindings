/**
 * Small JSON transport helpers shared by the Available shifts commands
 * (posting, claim, approve, alerts). One copy, so every command sends the
 * same session token and reads refusals the same way.
 */

export function readHeaders(): Record<string, string> {
    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    };
}

/** Throws when no session token can be found, before anything is sent. */
export function writeHeaders(): Record<string, string> {
    const cookie = document.cookie
        .split('; ')
        .find((item) => item.startsWith('XSRF-TOKEN='))
        ?.slice(11);
    const meta = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;
    if (!cookie && !meta)
        throw new Error(
            'Your session could not be checked. Reload the page, then try again. Nothing was sent.',
        );
    return {
        ...readHeaders(),
        'Content-Type': 'application/json',
        ...(cookie
            ? { 'X-XSRF-TOKEN': decodeURIComponent(cookie) }
            : { 'X-CSRF-TOKEN': meta as string }),
    };
}

/** A v4 request id. `crypto.randomUUID` only exists on secure origins, so a
 * plain-http dev host falls back to `getRandomValues`. */
export function newRequestId(): string {
    if (typeof crypto.randomUUID === 'function') return crypto.randomUUID();
    const bytes = crypto.getRandomValues(new Uint8Array(16));
    bytes[6] = (bytes[6] & 0x0f) | 0x40;
    bytes[8] = (bytes[8] & 0x3f) | 0x80;
    const hex = [...bytes].map((b) => b.toString(16).padStart(2, '0'));
    return `${hex.slice(0, 4).join('')}-${hex.slice(4, 6).join('')}-${hex
        .slice(6, 8)
        .join('')}-${hex.slice(8, 10).join('')}-${hex.slice(10).join('')}`;
}

export async function readJson(response: Response): Promise<unknown> {
    try {
        return await response.json();
    } catch {
        return null;
    }
}

/**
 * The plain-language reason for a refusal the server raised BEFORE anything
 * was saved, or null when the status may follow a save (or has no reason).
 * `definite` lists the statuses this command can only return before saving.
 */
export function refusalMessage(
    status: number,
    value: unknown,
    definite: readonly number[],
): string | null {
    // 503 counts only with field errors: that is the eligibility check being
    // unavailable, raised before saving. A bare 503 could be anything.
    const unavailable = status === 503;
    if (!definite.includes(status) && !unavailable) return null;
    if (status === 419)
        return 'Your session has expired. Reload the page, then try again. Nothing was saved.';
    if (!value || typeof value !== 'object' || Array.isArray(value))
        return null;
    if (
        unavailable &&
        !(
            'errors' in value &&
            value.errors &&
            typeof value.errors === 'object' &&
            Object.keys(value.errors).length > 0
        )
    )
        return null;
    const body = value as {
        result?: unknown;
        message?: unknown;
        errors?: unknown;
    };
    if (body.result != null) return null;
    const messages =
        body.errors &&
        typeof body.errors === 'object' &&
        !Array.isArray(body.errors)
            ? Object.values(body.errors)
                  .flat()
                  .filter(
                      (item): item is string =>
                          typeof item === 'string' && item.trim() !== '',
                  )
            : [];
    if (messages.length) return messages.join(' ');
    return typeof body.message === 'string' && body.message.trim()
        ? body.message
        : null;
}
