import { shiftInputInstant } from '@/lib/workforce-time-input';

export type PostableShift = {
    id: number;
    label: string;
    location: string | null;
    starts_at: string | null;
    ends_at: string | null;
    status: string;
    assigned: boolean;
    source_revision: string;
    post_url: string;
    active_position: {
        id: number;
        status: 'open' | 'claimed';
        expired: boolean;
    } | null;
    can_post: boolean;
    reason: 'duplicate' | 'terminal_shift' | 'request_cover_required' | null;
};

export type PostablePage = {
    data: PostableShift[];
    page: number;
    per_page: number;
    has_more: boolean;
    workerTimezone: string;
};

export type PostingDetails = { skills: string; notes: string; expiry: string };
export type PostingIntent = {
    actor: number;
    row: PostableShift;
    body: {
        shift_id: number;
        request_id: string;
        source_revision: string;
        required_skills: string[];
        notes: string | null;
        expires_at: string | null;
    };
};

const record = (value: unknown): value is Record<string, unknown> =>
    typeof value === 'object' && value !== null && !Array.isArray(value);
const id = (value: unknown): value is number =>
    typeof value === 'number' && Number.isSafeInteger(value) && value > 0;
const revision = (value: unknown): value is string =>
    typeof value === 'string' && /^[a-f0-9]{64}$/.test(value);
const uuid = (value: string) =>
    /^[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}$/i.test(
        value,
    );
const nullableId = (value: unknown) => value === null || id(value);

function instant(value: unknown): string | null | false {
    if (value === null) return null;
    if (
        typeof value !== 'string' ||
        !/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.0{1,6})?Z$/.test(value)
    )
        return false;
    try {
        const normalized = new Date(value).toISOString();
        return normalized.replace('.000Z', 'Z') ===
            value.replace(/\.0{1,6}Z$/, 'Z')
            ? normalized
            : false;
    } catch {
        return false;
    }
}

// Include installed Laravel Str::trim's invisible edge characters. Normalize the
// displayed Review intent before POST; retain skill order and duplicates.
const invisible =
    '(?:\\s|\\u0000|\\u00ad|\\u034f|\\u061c|\\u115f|\\u1160|\\u17b4|\\u17b5|\\u180e|[\\u200b-\\u200f]|[\\u2060-\\u2065]|[\\u206a-\\u206f]|\\u2800|\\u3164|\\uffa0|\\u{1d159}|[\\u{1d173}-\\u{1d17a}]|\\u{e0020})';
const edges = new RegExp(`^${invisible}+|${invisible}+$`, 'gu');
export const postingText = (value: string) => value.replace(edges, '');

export function readPostablePage(
    value: unknown,
    expectedPage: number,
): PostablePage {
    if (
        !record(value) ||
        value.page !== expectedPage ||
        !id(value.per_page) ||
        value.per_page > 25 ||
        typeof value.has_more !== 'boolean' ||
        !Array.isArray(value.data) ||
        value.data.length > value.per_page ||
        typeof value.workerTimezone !== 'string'
    )
        throw new Error(
            'The shift list could not be verified. Try loading it again.',
        );
    try {
        new Intl.DateTimeFormat('en-NZ', { timeZone: value.workerTimezone });
    } catch {
        throw new Error('The shift list has an unavailable time zone.');
    }
    const seen = new Set<number>();
    for (const row of value.data) {
        if (
            !record(row) ||
            !id(row.id) ||
            seen.has(row.id) ||
            typeof row.label !== 'string' ||
            !(row.location === null || typeof row.location === 'string') ||
            instant(row.starts_at) === false ||
            instant(row.ends_at) === false ||
            typeof row.status !== 'string' ||
            !row.status ||
            typeof row.assigned !== 'boolean' ||
            !revision(row.source_revision) ||
            row.post_url !== `/operations/shifts/${row.id}/open-position` ||
            typeof row.can_post !== 'boolean' ||
            ![
                null,
                'duplicate',
                'terminal_shift',
                'request_cover_required',
            ].some((reason) => reason === row.reason) ||
            !(
                row.active_position === null ||
                (record(row.active_position) &&
                    id(row.active_position.id) &&
                    ['open', 'claimed'].includes(
                        String(row.active_position.status),
                    ) &&
                    typeof row.active_position.expired === 'boolean')
            ) ||
            (row.can_post &&
                (row.reason !== null || row.active_position !== null))
        ) {
            throw new Error(
                'The shift list could not be verified. Try loading it again.',
            );
        }
        seen.add(row.id);
    }
    return value as PostablePage;
}

export function preparePostingIntent(
    row: PostableShift,
    actor: number,
    requestId: string,
    details: PostingDetails,
    zone: string,
    now = Date.now(),
): PostingIntent {
    if (
        !id(actor) ||
        !uuid(requestId) ||
        !row.can_post ||
        row.reason !== null ||
        row.active_position !== null ||
        !id(row.id) ||
        !revision(row.source_revision) ||
        row.post_url !== `/operations/shifts/${row.id}/open-position`
    ) {
        throw new Error(
            'Reload the shift list and choose a current position before posting.',
        );
    }
    const skills =
        postingText(details.skills) === ''
            ? []
            : details.skills.split(/\r?\n/).map(postingText);
    if (skills.some((skill) => !skill || [...skill].length > 100))
        throw new Error(
            'Use one skill per line, with no empty lines and at most 100 characters per skill.',
        );
    const notes = postingText(details.notes) || null;
    if (notes !== null && [...notes].length > 2000)
        throw new Error('Keep the posting notes within 2,000 characters.');
    const expires = details.expiry
        ? shiftInputInstant(details.expiry, zone)
        : null;
    if (expires !== null && new Date(expires).getTime() <= now)
        throw new Error('Choose an expiry later than now.');
    return {
        actor,
        row: { ...row },
        body: {
            shift_id: row.id,
            request_id: requestId,
            source_revision: row.source_revision,
            required_skills: skills,
            notes,
            expires_at: expires,
        },
    };
}

export function confirmedPosting(
    status: number,
    value: unknown,
    intent: PostingIntent,
    currentActor: number,
): { positionId: number; committedAt: string } | null {
    if (
        status !== 201 ||
        currentActor !== intent.actor ||
        !record(value) ||
        !record(value.result)
    )
        return null;
    const result = value.result;
    if (
        result.version !== 1 ||
        result.action !== 'post_position' ||
        result.outcome !== 'posted' ||
        result.changed !== true ||
        result.actor_id !== intent.actor ||
        result.request_id !== intent.body.request_id ||
        result.source_revision !== intent.body.source_revision ||
        !record(result.position) ||
        !record(result.shift) ||
        typeof instant(result.committed_at) !== 'string'
    )
        return null;
    const position = result.position;
    const shift = result.shift;
    if (
        Object.keys(shift).sort().join('|') !==
            'assigned|ends_at|id|site_id|starts_at|status' ||
        shift.id !== intent.row.id ||
        !nullableId(shift.site_id) ||
        shift.assigned !== intent.row.assigned ||
        shift.status !== intent.row.status ||
        instant(shift.starts_at) !== instant(intent.row.starts_at) ||
        instant(shift.ends_at) !== instant(intent.row.ends_at) ||
        instant(shift.starts_at) === false ||
        instant(shift.ends_at) === false ||
        !id(position.id) ||
        position.shift_id !== intent.row.id ||
        !nullableId(position.replacement_request_id) ||
        (shift.assigned && position.replacement_request_id === null) ||
        position.status !== 'open' ||
        [
            position.claimed_by,
            position.claimed_at,
            position.approved_by,
            position.approved_at,
        ].some((field) => field !== null) ||
        !Array.isArray(position.required_skills) ||
        position.required_skills.some((skill) => typeof skill !== 'string') ||
        JSON.stringify(position.required_skills) !==
            JSON.stringify(intent.body.required_skills) ||
        !Array.isArray(position.coverage_roles) ||
        position.coverage_roles.some((role) => typeof role !== 'string') ||
        position.notes !== intent.body.notes ||
        instant(position.expires_at) === false ||
        instant(position.expires_at) !== instant(intent.body.expires_at)
    )
        return null;
    return {
        positionId: position.id,
        committedAt: result.committed_at as string,
    };
}

/** What a fresh read of the shift says. It reports the shift's current state
 * only; it can never prove which request created a posting. */
export function postingReadback(row: PostableShift | undefined): string {
    if (row?.active_position)
        return 'This shift is posted for cover now. This check cannot show whether your earlier attempt posted it, so do not post it again.';
    if (row)
        return 'This shift is not posted for cover right now. If it still needs cover, you can start again.';
    return 'This shift could not be found in your current list, so this check cannot say whether the earlier post saved.';
}
