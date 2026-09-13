export const API_IDENTITY_ABILITIES = [
    ['work:create', 'Create work items'],
    ['work:read', 'Read safe status and context'],
    ['work:comment', 'Append public evidence or comments'],
    ['work:transition', 'Send lifecycle status callbacks'],
    ['work:update', 'Update delegated triage fields'],
    ['work:link', 'Manage related-work links'],
    ['work:sensitive', 'Access explicitly sensitive work'],
    ['work:organisation-wide', 'Access explicit application-wide work'],
] as const;

export const API_IDENTITY_WORK_TYPES = [
    ['incident', 'Incidents'],
    ['service_request', 'Service requests'],
    ['security_request', 'Security requests'],
] as const;

export const API_IDENTITY_CREATE_FIELDS = [
    ['title', 'Title'],
    ['description', 'Description'],
    ['category', 'Category'],
    ['subcategory', 'Subcategory'],
    ['priority', 'Priority'],
    ['impact', 'Impact'],
    ['urgency', 'Urgency'],
    ['work_type', 'Work type'],
    ['site_id', 'Site link'],
    ['is_organisation_wide', 'Application-wide scope marker'],
    ['it_service_id', 'Service link'],
    ['asset_id', 'Asset link'],
] as const;

export const API_IDENTITY_READ_FIELDS = [
    ['description', 'Description'],
    ['category', 'Category'],
    ['subcategory', 'Subcategory'],
    ['impact', 'Impact'],
    ['urgency', 'Urgency'],
    ['site', 'Site context'],
    ['service', 'Service context'],
    ['asset', 'Asset context'],
    ['queue', 'Queue'],
    ['team', 'Team'],
    ['owner', 'Owner'],
    ['assignee', 'Assignee'],
    ['sla', 'SLA targets'],
    ['resolution', 'Resolution details'],
] as const;

export const API_IDENTITY_UPDATE_FIELDS = [
    ['category', 'Category'],
    ['subcategory', 'Subcategory'],
    ['priority', 'Priority'],
    ['impact', 'Impact'],
    ['urgency', 'Urgency'],
] as const;

export type ApiIdentityDraft = {
    name: string;
    description: string;
    actor_user_id: string;
    abilities: string[];
    allowed_work_types: string[];
    allowed_site_ids: number[];
    create_fields: string[];
    read_fields: string[];
    update_fields: string[];
    require_signature: boolean;
    rate_limit_per_minute: number;
    expires_at: string;
};

export type ApiIdentityOption = { id: number; name: string };

export type ApiIdentityRecord = {
    id: number;
    public_id: string;
    name: string;
    description: string | null;
    actor: ApiIdentityOption | null;
    creator: ApiIdentityOption | null;
    abilities: string[];
    allowed_work_types: string[];
    allowed_site_ids: number[];
    allowed_fields: { create?: string[]; read?: string[]; update?: string[] };
    require_signature: boolean;
    rate_limit_per_minute: number;
    expires_at: string | null;
    revoked_at: string | null;
    last_used_at: string | null;
    last_rotated_at: string | null;
    created_at: string | null;
    configuration_version: number;
    is_active: boolean;
};

export type OneTimeApiCredential = {
    identity_id: number;
    name: string;
    token: string;
};

export function emptyApiIdentityDraft(actorId = ''): ApiIdentityDraft {
    return {
        name: '',
        description: '',
        actor_user_id: actorId,
        abilities: API_IDENTITY_ABILITIES.map(([value]) => value).filter(
            (value) =>
                value !== 'work:sensitive' &&
                value !== 'work:organisation-wide' &&
                value !== 'work:update' &&
                value !== 'work:link',
        ),
        allowed_work_types: ['incident'],
        allowed_site_ids: [],
        create_fields: [
            'title',
            'description',
            'category',
            'priority',
            'work_type',
            'site_id',
        ],
        read_fields: [],
        update_fields: [],
        require_signature: true,
        rate_limit_per_minute: 60,
        expires_at: '',
    };
}

export function draftForApiIdentity(
    identity: ApiIdentityRecord,
): ApiIdentityDraft {
    return {
        name: identity.name,
        description: identity.description ?? '',
        actor_user_id: String(identity.actor?.id ?? ''),
        abilities: [...identity.abilities],
        allowed_work_types: [...identity.allowed_work_types],
        allowed_site_ids: [...identity.allowed_site_ids],
        create_fields: [...(identity.allowed_fields.create ?? [])],
        read_fields: [...(identity.allowed_fields.read ?? [])],
        update_fields: [...(identity.allowed_fields.update ?? [])],
        require_signature: identity.require_signature,
        rate_limit_per_minute: identity.rate_limit_per_minute,
        expires_at: identity.expires_at
            ? localDateTimeInputValue(identity.expires_at)
            : '',
    };
}

function localDateTimeInputValue(value: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return '';

    const pad = (number: number) => String(number).padStart(2, '0');
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`;
}

export function normalizeApiIdentityDraft(
    draft: ApiIdentityDraft,
): ApiIdentityDraft {
    if (draft.abilities.includes('work:update')) return draft;

    return { ...draft, update_fields: [] };
}

export function newApiIdentityRequestUuid(): string {
    if (typeof globalThis.crypto?.randomUUID === 'function')
        return globalThis.crypto.randomUUID();

    return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(
        /[xy]/g,
        (character) => {
            const random = Math.floor(Math.random() * 16);
            const value = character === 'x' ? random : (random & 0x3) | 0x8;
            return value.toString(16);
        },
    );
}
