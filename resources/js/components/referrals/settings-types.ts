import type { ChecklistTemplate } from './checklist-types';
import type { ConfiguredQuestion } from './form-types';
import type { MessageTemplate } from './message-templates';
import type { Page } from './types';

export type ReferralConfiguration = {
    checklists?: ChecklistTemplate[];
    forms?: { fields: ConfiguredQuestion[] };
    workflow?: {
        transitions: {
            from: string;
            to: string;
            role_id: number | null;
            evidence: string[];
        }[];
    };
    routing?: {
        enabled: boolean;
        fallback_owner_ids: number[];
        fallback_max_open: number;
        rules: {
            id: string;
            label: string;
            enabled: boolean;
            service_ids: string[];
            regions: string[];
            funding_situations?: string[];
            pathway_ids?: string[];
            site_id: number | null;
            team: string;
            owner_ids: number[];
            max_open: number;
        }[];
    };
    public: {
        enabled: boolean;
        provider_name: string;
        contact_phone: string | null;
        response_hours: string;
        notice_version: string;
        notice: string | null;
        acknowledgement_enabled: boolean;
    };
    services: {
        id: string;
        label: string;
        enabled: boolean;
        context_types?: string[] | null;
    }[];
    intake: {
        team: string;
        first_contact_business_days: number;
        hold_review_business_days: number;
    };
    follow_up?: {
        manager_after_days: number;
        day_basis: 'calendar' | 'business';
        office_hours_only: boolean;
    };
    document_requests?: { enabled: boolean; maximum_days: number };
    business_calendar: {
        timezone: string;
        weekdays: number[];
        holidays: string[];
        closing_time: string;
        opening_time?: string | null;
    };
    stages: { id: string; label: string }[];
    pathways: Pathway[];
    proposals: {
        approval_limit_cents: number;
        approval_limits?: ApprovalLimit[];
        temporary_funding_enabled: boolean;
    };
    /** Hold reasons, close reasons and priorities. Older versions are filled with the standard lists by the server. */
    choices?: Record<ChoiceList, Choice[]>;
    /** Staff message templates (safe merge fields only). Older versions are filled with the standard templates by the server. */
    communications?: { templates: MessageTemplate[] };
};
export type ChoiceList = 'hold' | 'close' | 'priority';
export type Choice = { id: string; label: string; enabled: boolean };
export type PayerKind = 'external' | 'private';
export const payerKindLabels: Record<PayerKind, string> = {
    external: 'External funder',
    private: 'The person or their family (private)',
};
export type PathwayContact = {
    organisation: string | null;
    phone: string | null;
    email: string | null;
    website: string | null;
    region_notes: string | null;
};
export type Pathway = {
    id: string;
    label: string;
    enabled: boolean;
    personal_income: boolean;
    service_ids: string[];
    evidence_checklist: string[];
    source_url: string | null;
    verified_on: string | null;
    review_on: string | null;
    /** Directory details shown to staff on funding work; nothing is sent automatically. */
    contact?: PathwayContact | null;
    /** Who pays: an external funder (verified decision) or the person or their family (private agreement). */
    payer_kind?: PayerKind;
};
export type ApprovalLimit = {
    currency: string;
    period: string;
    role_id: number | null;
    limit_cents: number;
};
export type ImpactLine = {
    area: 'intake' | 'workflow' | 'funding' | 'access';
    affects: string;
    text: string;
    loosens: boolean;
};
export type SettingsImpactSummary = {
    checked_at: string;
    version_id: number | null;
    scope: string;
    open_referrals: number;
    without_owner: number;
    outside_enabled_services: number;
    retaining_another_version: number;
    funding_outside_enabled_pathways: number | null;
    intake_team: string;
    enabled_services: string[];
    public_enabled: boolean;
    notice_version: string;
    lines?: ImpactLine[];
};
export type SettingsAuditEvent = {
    id: number;
    kind: string;
    version: number | null;
    restored_from_version: number | null;
    actor_name: string | null;
    occurred_at: string | null;
};
export const defaultWorkflowTransitions = (): NonNullable<
    ReferralConfiguration['workflow']
>['transitions'] =>
    [
        'new',
        'triage',
        'assessment',
        'funding_assistance',
        'ready_for_handover',
        'on_hold',
    ].flatMap((from) =>
        ['triage', 'assessment', 'funding_assistance']
            .filter((to) => to !== from)
            .map((to) => ({ from, to, role_id: null, evidence: [] })),
    );
export type ConfigurationVersion = {
    id: number;
    version: number;
    state: string;
    base_version_id: number | null;
    base_version?: number | null;
    restored_from_version_id?: number | null;
    restored_from_version?: number | null;
    created_by: number;
    created_by_name?: string | null;
    published_by: number | null;
    published_by_name?: string | null;
    discarded_by_name?: string | null;
    reason: string;
    publication_note?: string | null;
    discard_reason?: string | null;
    created_at: string;
    published_at: string | null;
    effective_at: string | null;
    discarded_at?: string | null;
};
export type ReferralSettingsPage = {
    routing_staff?: { id: number; name: string }[];
    routing_sites?: { id: number; name: string }[];
    impact?: SettingsImpactSummary | null;
    pending_drafts?: ConfigurationVersion[];
    audit?: SettingsAuditEvent[];
    role_grants?: { id: number; name: string; keys: string[] }[];
    protected_choices?: Record<ChoiceList, string[]>;
    built_in_choices?: Record<ChoiceList, string[]>;
    delivery?: {
        email_configured: boolean;
        /** Staff text messages: a deliberate switch plus a real SMS provider. */
        sms_configured?: boolean;
        /** Length of a secure upload link, for the text-length check. */
        link_length?: number;
    };
    latest: ConfigurationVersion | null;
    effective: ConfigurationVersion | null;
    selected:
        | (ConfigurationVersion & {
              configuration: ReferralConfiguration;
              base_configuration: ReferralConfiguration;
              governance_required: boolean;
          })
        | null;
    configuration: ReferralConfiguration;
    history: Page<ConfigurationVersion>;
    roles: { id: number; name: string }[];
    service_types?: { id: string; name: string }[];
    permissions: Record<string, string>;
    can_manage: boolean;
    can_publish: boolean;
    can_govern: boolean;
    can_edit_roles: boolean;
    actor_id: number;
};
/** The rail views (approved mockup: Overview / Intake / Workflow / Funding / Access / History). */
export const settingAreas = [
    'overview',
    'intake',
    'workflow',
    'funding',
    'access',
    'history',
] as const;
export type SettingArea = (typeof settingAreas)[number];
/** What every settings area receives from the page. */
export type SettingsAreaProps = {
    value: ReferralConfiguration;
    onChange: (next: ReferralConfiguration) => void;
    /** History, someone else's view-only access, or a request in flight. */
    readOnly: boolean;
    settings: ReferralSettingsPage;
    tab: SettingTab;
    jump: (area: SettingArea, tab?: SettingTab) => void;
};
/** Apply a change to a copy so the published configuration is never mutated. */
export function editConfiguration(
    value: ReferralConfiguration,
    onChange: (next: ReferralConfiguration) => void,
    change: (copy: ReferralConfiguration) => void,
) {
    const copy = structuredClone(value);
    change(copy);
    onChange(copy);
}
/** Sub-tabs inside an area. Every area with settings opens on its Overview. */
export type SettingTab =
    | 'overview'
    | 'configuration'
    | 'notifications'
    | 'delivery'
    | 'scope';

/**
 * A stable reference for a new option, made from its first name. People
 * never see or type references; renaming later keeps the reference.
 */
export function referenceFor(label: string, taken: string[]): string {
    let base = label
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^a-z0-9]+/g, '_')
        .replace(/^_+|_+$/g, '')
        .slice(0, 56);
    if (!/^[a-z]/.test(base)) base = `option_${base}`.replace(/_+$/, '');
    let candidate = base;
    for (let n = 2; taken.includes(candidate); n++) candidate = `${base}_${n}`;
    return candidate;
}

/** Plain names for the configuration's top-level groups, used in change lists. */
const groupNames: Record<string, string> = {
    public: 'Website',
    services: 'Services',
    intake: 'Intake',
    follow_up: 'Overdue reminders',
    document_requests: 'Document requests',
    routing: 'Routing',
    workflow: 'Stage changes',
    forms: 'Intake questions',
    checklists: 'Checklists',
    business_calendar: 'Working calendar',
    stages: 'Stage names',
    pathways: 'Funding pathways',
    proposals: 'Proposal approvals',
    choices: 'Reasons and priorities',
    communications: 'Message templates',
};

/**
 * A readable label for one entry from `configurationChanges`: the group's
 * plain name first, then the remaining path in sentence case, with stable
 * references replaced by the names people know them by.
 */
export function describeChange(
    label: string,
    configuration: ReferralConfiguration,
): string {
    const [group, ...rest] = label.split(' · ');
    const names = new Map<string, string>();
    configuration.services.forEach((item) => names.set(item.id, item.label));
    configuration.pathways.forEach((item) => names.set(item.id, item.label));
    configuration.forms?.fields.forEach((item) =>
        names.set(item.id, item.label),
    );
    configuration.checklists?.forEach((item) => {
        names.set(item.id, item.label);
        item.items.forEach((step) => names.set(step.id, step.title));
    });
    Object.values(configuration.choices ?? {}).forEach((list) =>
        list.forEach((item) => names.set(item.id, item.label)),
    );
    configuration.communications?.templates.forEach((item) =>
        names.set(item.id, item.label),
    );
    const listNames: Record<string, string> = {
        hold: 'Hold reasons',
        close: 'Close reasons',
        priority: 'Priorities',
    };
    const stage = (id: string) =>
        configuration.stages.find((item) => item.id === id)?.label ??
        id.replaceAll('_', ' ');
    const parts = rest.map((part) => {
        if (group === 'choices' && listNames[part]) return listNames[part];
        if (group === 'communications' && part === 'templates') return '';
        if (part.includes(' → '))
            return part
                .split(' → ')
                .map((id) => stage(id))
                .join(' to ');
        if (part === 'payer kind') return 'Payer';
        if (names.has(part)) return `“${names.get(part)}”`;
        return part;
    });
    return [groupNames[group.replaceAll(' ', '_')] ?? group, ...parts]
        .filter(Boolean)
        .join(' · ');
}

export function configurationChanges(
    before: ReferralConfiguration,
    after: ReferralConfiguration,
    roles: { id: number; name: string }[] = [],
    serviceTypes: { id: string; name: string }[] = [],
) {
    function flatten(
        value: unknown,
        prefix = '',
        result: Record<string, string> = {},
    ) {
        if (Array.isArray(value)) {
            if (!value.length) result[prefix] = 'None';
            value.forEach((entry, index) =>
                flatten(
                    prefix === 'services'
                        ? { context_types: null, ...entry }
                        : entry,
                    prefix === 'workflow · transitions'
                        ? `${prefix} · ${entry.from} → ${entry.to}`
                        : prefix === 'forms · fields' ||
                            prefix === 'checklists' ||
                            prefix === 'services' ||
                            prefix === 'pathways' ||
                            /^choices · (hold|close|priority)$/.test(prefix) ||
                            prefix === 'communications · templates' ||
                            /^checklists · [a-z][a-z0-9_]+ · items$/.test(
                                prefix,
                            )
                          ? `${prefix} · ${entry?.id ?? index + 1}`
                          : `${prefix} · ${index + 1}`,
                    result,
                ),
            );
        } else if (value !== null && typeof value === 'object') {
            for (const [key, entry] of Object.entries(value)) {
                const context = value as Record<string, unknown>;
                if (key === 'context_types') {
                    result[`${prefix} · delivery settings`] =
                        entry === null
                            ? 'Any available'
                            : Array.isArray(entry)
                              ? entry
                                    .map(
                                        (id) =>
                                            serviceTypes.find(
                                                (type) => type.id === id,
                                            )?.name ??
                                            String(id).replaceAll('_', ' '),
                                    )
                                    .sort()
                                    .join(', ') || 'None selected'
                              : 'Invalid setting selection';
                    continue;
                }
                if (key === 'approval_limit_cents' || key === 'limit_cents') {
                    const amount =
                        typeof entry === 'number' && Number.isFinite(entry)
                            ? (entry / 100).toFixed(2)
                            : 'Invalid amount';
                    result[
                        `${prefix} · ${key === 'approval_limit_cents' ? 'general weekly NZD limit' : 'approval amount'}`
                    ] =
                        `${amount} ${key === 'approval_limit_cents' ? 'NZD per week' : `${context.currency ?? ''} per ${context.period ?? ''}`}`;
                    continue;
                }
                if (key === 'payer_kind') {
                    result[`${prefix} · payer kind`] =
                        payerKindLabels[entry as PayerKind] ?? String(entry);
                    continue;
                }
                if (key === 'role_id') {
                    const workflow = prefix.startsWith('workflow ·');
                    const checklist = prefix.startsWith('checklists ·');
                    result[
                        `${prefix} · ${workflow || checklist ? 'responsible role' : 'approver role'}`
                    ] =
                        entry === null
                            ? workflow
                                ? 'Any role with transition permission'
                                : checklist
                                  ? 'Any permitted checklist reviewer'
                                  : 'Any authorised approver'
                            : (roles.find((role) => role.id === entry)?.name ??
                              `Role ${entry}`);
                    continue;
                }
                flatten(
                    entry,
                    `${prefix ? `${prefix} · ` : ''}${key.replaceAll('_', ' ')}`,
                    result,
                );
            }
        } else
            result[prefix] =
                value === null || value === ''
                    ? 'Not set'
                    : value === true
                      ? 'Yes'
                      : value === false
                        ? 'No'
                        : String(value);
        return result;
    }
    // Optional legacy fields have defined behaviour; omission is not a policy removal.
    const normalized = (value: ReferralConfiguration) => ({
        document_requests: { enabled: false, maximum_days: 7 },
        follow_up: {
            manager_after_days: 3,
            day_basis: 'calendar',
            office_hours_only: false,
        },
        routing: {
            enabled: false,
            fallback_owner_ids: [],
            fallback_max_open: 50,
            rules: [],
        },
        workflow: { transitions: defaultWorkflowTransitions() },
        forms: { fields: [] },
        checklists: [],
        ...value,
    });
    const a = flatten(normalized(before)),
        b = flatten(normalized(after));
    return [...new Set([...Object.keys(a), ...Object.keys(b)])]
        .filter((key) => a[key] !== b[key])
        .map((label) => ({
            label,
            before: a[label] ?? 'Not set',
            after: b[label] ?? 'Not set',
        }));
}
