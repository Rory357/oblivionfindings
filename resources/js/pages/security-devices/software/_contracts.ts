export const softwareBase = '/security-devices/software';
export const softwareStatuses = [
    'published',
    'pending_review',
    'uploaded',
    'withdrawn',
    'all',
] as const;
export type SoftwareFilter = (typeof softwareStatuses)[number];
export type SoftwareStatus = Exclude<SoftwareFilter, 'all'>;
export const softwareStatusLabel: Record<SoftwareFilter, string> = {
    published: 'Approved',
    pending_review: 'Awaiting review',
    uploaded: 'Drafts',
    withdrawn: 'Withdrawn',
    all: 'All software',
};
export type PackageMetadata = {
    name: string;
    publisher: string;
    product_code: string;
    product_version: string;
    architecture: 'amd64' | 'arm64';
    signer_sha256: string;
};
type Actor = { id: number; name: string } | null;
export type SoftwarePackage = PackageMetadata & {
    uuid: string;
    version: number;
    status: SoftwareStatus;
    status_version: number;
    sha256: string;
    size_bytes: number;
    uploaded_by: Actor;
    reviewed_by: Actor;
    uploaded_at: string | null;
    submitted_at: string | null;
    reviewed_at: string | null;
    published_at: string | null;
    withdrawn_at: string | null;
    can_submit: boolean;
    can_review: boolean;
    can_withdraw: boolean;
    submit_href: string | null;
    review_href: string | null;
    withdraw_href: string | null;
    show_href: string;
};
export type SoftwareIndex = {
    actor_id: number;
    available: boolean;
    max_upload_bytes: number;
    can_upload: boolean;
    upload_href: string;
    recovery_href_template: string;
    query: { device_id: number | null; status: SoftwareFilter; search: string };
    summary?: Record<SoftwareStatus, number>;
    pagination: {
        page_key: string;
        limit: number;
        shown: number;
        has_more: boolean;
        previous_href: string | null;
        next_href: string | null;
        reset_href: string;
        position_reset: boolean;
    };
    packages: SoftwarePackage[];
};
export const uuidPattern =
    /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
const hashPattern = /^[0-9a-f]{64}$/;
function object(value: unknown): Record<string, unknown> {
    if (!value || typeof value !== 'object' || Array.isArray(value))
        throw Error('Software information is unavailable.');
    return value as Record<string, unknown>;
}
function integer(value: unknown, min = 1): value is number {
    return Number.isSafeInteger(value) && (value as number) >= min;
}
export function safeSoftwareText(
    value: unknown,
    max: number,
    min = 1,
): value is string {
    return (
        typeof value === 'string' &&
        Array.from(value.trim()).length >= min &&
        Array.from(value).length <= max &&
        !/[\p{Cc}\p{Cf}]/u.test(value)
    );
}
export function productVersionValid(value: unknown): value is string {
    if (
        typeof value !== 'string' ||
        !/^(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,2})\.(0|[1-9][0-9]{0,4})$/.test(
            value,
        )
    )
        return false;
    const [major, minor, build] = value.split('.').map(Number);
    return major <= 255 && minor <= 255 && build <= 65535;
}
export function metadataValid(value: PackageMetadata): boolean {
    return (
        safeSoftwareText(value.name, 200) &&
        safeSoftwareText(value.publisher, 200) &&
        /^\{[A-F0-9]{8}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{4}-[A-F0-9]{12}\}$/.test(
            value.product_code,
        ) &&
        productVersionValid(value.product_version) &&
        ['amd64', 'arm64'].includes(value.architecture) &&
        hashPattern.test(value.signer_sha256)
    );
}
export function sameMetadata(a: PackageMetadata, b: PackageMetadata): boolean {
    return (
        [
            'name',
            'publisher',
            'product_code',
            'product_version',
            'architecture',
            'signer_sha256',
        ] as const
    ).every((key) => a[key] === b[key]);
}
export function softwarePackage(
    value: unknown,
    actor: number,
): SoftwarePackage {
    const row = object(value);
    if (
        typeof row.uuid !== 'string' ||
        !uuidPattern.test(row.uuid) ||
        !integer(row.version) ||
        !integer(row.status_version) ||
        typeof row.status !== 'string' ||
        !softwareStatuses
            .filter((x) => x !== 'all')
            .includes(row.status as SoftwareStatus) ||
        !metadataValid(row as PackageMetadata) ||
        typeof row.sha256 !== 'string' ||
        !hashPattern.test(row.sha256) ||
        !integer(row.size_bytes) ||
        row.size_bytes > 536870912
    )
        throw Error('Software package could not be verified.');
    for (const key of ['uploaded_by', 'reviewed_by']) {
        if (row[key] !== null) {
            const user = object(row[key]);
            if (!integer(user.id) || !safeSoftwareText(user.name, 200))
                throw Error('Package reviewer unavailable.');
        }
    }
    for (const key of [
        'uploaded_at',
        'submitted_at',
        'reviewed_at',
        'published_at',
        'withdrawn_at',
    ]) {
        if (
            row[key] !== null &&
            (typeof row[key] !== 'string' ||
                row[key].length > 40 ||
                !Number.isFinite(Date.parse(row[key])))
        )
            throw Error('Package dates unavailable.');
    }
    for (const action of ['submit', 'review', 'withdraw']) {
        const allowed = row['can_' + action],
            href = row[action + '_href'];
        if (
            typeof allowed !== 'boolean' ||
            (allowed
                ? href !== softwareBase + '/' + row.uuid + '/' + action
                : href !== null)
        )
            throw Error('Package actions changed.');
    }
    if (
        row.show_href !==
        softwareBase + '/' + row.uuid + '?expected_actor_id=' + actor
    )
        throw Error('Package context changed.');
    return value as SoftwarePackage;
}
export function softwareWriteReply(
    value: unknown,
    actor: number,
): SoftwarePackage {
    const reply = object(value);
    if (reply.actor_id !== actor)
        throw Error('Your account changed. Reload software before continuing.');
    return softwarePackage(reply.package, actor);
}
export function softwarePageHref(
    value: unknown,
    actor: number,
    device: number | null,
): string {
    if (
        typeof value !== 'string' ||
        value.length > 1800 ||
        !value.startsWith(softwareBase + '?')
    )
        throw Error('Software page unavailable.');
    const url = new URL(value, 'https://oblivion.invalid');
    if (
        url.origin !== 'https://oblivion.invalid' ||
        url.pathname !== softwareBase ||
        url.hash ||
        [...url.searchParams.keys()].some(
            (key) =>
                ![
                    'expected_actor_id',
                    'device_id',
                    'status',
                    'search',
                    'software_cursor',
                ].includes(key),
        ) ||
        new Set(url.searchParams.keys()).size !==
            [...url.searchParams.keys()].length ||
        url.searchParams.get('expected_actor_id') !== String(actor) ||
        url.searchParams.get('device_id') !==
            (device === null ? null : String(device))
    )
        throw Error('Software page context changed.');
    return value;
}
export function softwareIndexReply(
    value: unknown,
    actor: number,
    device: number | null,
): SoftwareIndex {
    if (!integer(actor) || (device !== null && !integer(device)))
        throw Error('Software account or device unavailable.');
    const reply = object(value),
        query = object(reply.query),
        page = object(reply.pagination);
    if (
        reply.actor_id !== actor ||
        query.device_id !== device ||
        !softwareStatuses.includes(query.status as SoftwareFilter) ||
        !safeSoftwareText(query.search, 200, 0) ||
        typeof reply.available !== 'boolean' ||
        typeof reply.can_upload !== 'boolean' ||
        !integer(reply.max_upload_bytes) ||
        reply.max_upload_bytes > 536870912 ||
        reply.upload_href !== softwareBase ||
        reply.recovery_href_template !==
            softwareBase +
                '/uploads/{request_key}?expected_actor_id=' +
                actor ||
        !Array.isArray(reply.packages) ||
        reply.packages.length > 20 ||
        page.limit !== 20 ||
        page.shown !== reply.packages.length ||
        typeof page.page_key !== 'string' ||
        !hashPattern.test(page.page_key) ||
        typeof page.has_more !== 'boolean' ||
        typeof page.position_reset !== 'boolean'
    )
        throw Error('Software catalogue context changed.');
    const ids = new Set<string>();
    for (const row of reply.packages) {
        const item = softwarePackage(row, actor);
        if (ids.has(item.uuid)) throw Error('Duplicate software release.');
        ids.add(item.uuid);
    }
    for (const name of ['previous_href', 'next_href'])
        if (page[name] !== null) softwarePageHref(page[name], actor, device);
    softwarePageHref(page.reset_href, actor, device);
    if (reply.summary !== undefined) {
        const summary = object(reply.summary);
        for (const status of softwareStatuses.filter((x) => x !== 'all'))
            if (!integer(summary[status], 0))
                throw Error('Software summary unavailable.');
    }
    return value as SoftwareIndex;
}
