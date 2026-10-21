import {
    responsePolicyChanges,
    type ResponsePolicy,
} from './chat-response-settings';
import type { UrgentContactSetting } from './chat-urgent-contact';

export type ChatConfiguration = ResponsePolicy & {
    version: number;
    enabled: boolean;
    family_enabled: boolean;
    files_enabled: boolean;
    voice_notes_enabled?: boolean;
    max_file_mb: number;
    max_files: number;
    message_change_window_minutes: number | null;
    calls_enabled: boolean;
    family_calls_enabled: boolean;
    max_call_participants: number;
    urgent_contact?: UrgentContactSetting | null;
};
export const chatSettingSections = {
    general: 'General',
    family: 'Family contact',
    calls: 'Voice calls',
    files: 'Messages and files',
    notifications: 'Notifications',
    privacy: 'Privacy and records',
    access: 'Channels and access',
    health: 'Service health',
} as const;
export type ChatSettingSection = keyof typeof chatSettingSections;
export const chatSettingLabels = {
    enabled: 'Enable Chat',
    family_enabled: 'Family conversations',
    files_enabled: 'Share photos and documents',
    voice_notes_enabled: 'Voice messages',
    max_file_mb: 'Maximum file size (MB)',
    max_files: 'Files per message',
    message_change_window_minutes: 'Edit/removal window (minutes)',
    calls_enabled: 'Staff voice calls',
    family_calls_enabled: 'Family voice calls',
    max_call_participants: 'Maximum call participants',
    response_expectation: 'Response wording',
    response_schedule: 'Response hours',
    urgent_contact: 'Service urgent contact',
} as const;
export type ChatSettingField = keyof typeof chatSettingLabels;
export const chatSectionFields: Record<ChatSettingSection, ChatSettingField[]> =
    {
        general: ['enabled'],
        family: [
            'family_enabled',
            'response_expectation',
            'response_schedule',
            'urgent_contact',
        ],
        calls: [
            'calls_enabled',
            'family_calls_enabled',
            'max_call_participants',
        ],
        files: [
            'files_enabled',
            'voice_notes_enabled',
            'max_file_mb',
            'max_files',
            'message_change_window_minutes',
        ],
        notifications: [],
        privacy: [],
        access: [],
        health: [],
    };
export function effectiveChatSetting<K extends ChatSettingField>(
    settings: ChatConfiguration,
    scope: string,
    field: K,
): ChatConfiguration[K] {
    const override =
        scope === 'organisation' ? undefined : settings.site_overrides[scope];
    return (
        override && Object.hasOwn(override, field)
            ? override[field]
            : settings[field]
    ) as ChatConfiguration[K];
}
export function editChatSetting<K extends ChatSettingField>(
    settings: ChatConfiguration,
    scope: string,
    field: K,
    value: ChatConfiguration[K],
): ChatConfiguration {
    if (scope === 'organisation') return { ...settings, [field]: value };
    return {
        ...settings,
        site_overrides: {
            ...settings.site_overrides,
            [scope]: { ...settings.site_overrides[scope], [field]: value },
        },
    };
}
export function inheritChatSection(
    settings: ChatConfiguration,
    scope: string,
    section: ChatSettingSection,
): ChatConfiguration {
    if (scope === 'organisation') return settings;
    const overrides = { ...settings.site_overrides };
    const override = { ...overrides[scope] };
    for (const field of chatSectionFields[section]) delete override[field];
    if (Object.keys(override).length) overrides[scope] = override;
    else delete overrides[scope];
    return { ...settings, site_overrides: overrides };
}
export function describeChatSetting(value: unknown): string {
    if (value === null || value === undefined) return 'Not configured';
    if (typeof value === 'boolean') return value ? 'On' : 'Off';
    if (typeof value === 'object' && 'phone' in value && 'label' in value) {
        const contact = value as UrgentContactSetting;
        return `${contact.label || 'Label needed'} · ${contact.phone || 'Phone number needed'}${contact.instructions ? ` · ${contact.instructions}` : ''} · ${contact.confirmed ? 'Confirmed for family use' : 'Confirmation needed'}`;
    }
    return String(value);
}
export function chatPolicyChanges(
    before: ChatConfiguration,
    after: ChatConfiguration,
    sites: { id: number; name: string }[],
) {
    const result = responsePolicyChanges(before, after, sites).map((row) => ({
        ...row,
        impact: 'Response deadlines use the current effective policy, including unanswered requests already waiting.',
    }));
    for (const field of Object.keys(chatSettingLabels) as ChatSettingField[]) {
        if (field === 'response_schedule' || field === 'response_expectation')
            continue;
        if (JSON.stringify(before[field]) !== JSON.stringify(after[field])) {
            const inherited = sites
                .filter(
                    (site) =>
                        !Object.hasOwn(
                            after.site_overrides[String(site.id)] ?? {},
                            field,
                        ),
                )
                .map((site) => site.name);
            result.push({
                label: `Organisation defaults · ${chatSettingLabels[field]}`,
                before: describeChatSetting(before[field]),
                after: describeChatSetting(after[field]),
                impact:
                    field === 'urgent_contact'
                        ? `Shown to authorised families and care-team staff for ${inherited.length ? `inheriting sites: ${inherited.join(', ')}` : 'clients using organisation defaults'}. Explicit site overrides stay in place. This does not enable Oblivion voice calling.`
                        : `Applies to private staff conversations without a site and ${inherited.length ? `inheriting site conversations: ${inherited.join(', ')}` : 'no currently listed site conversations'}. Explicit site overrides stay in place.`,
            });
        }
        for (const scope of new Set([
            ...Object.keys(before.site_overrides),
            ...Object.keys(after.site_overrides),
        ])) {
            const a = before.site_overrides[scope] ?? {},
                b = after.site_overrides[scope] ?? {};
            if (JSON.stringify(a[field]) === JSON.stringify(b[field])) continue;
            const label =
                sites.find((site) => String(site.id) === scope)?.name ??
                `Site ${scope}`;
            result.push({
                label: `${label} · ${chatSettingLabels[field]}`,
                before: `${Object.hasOwn(a, field) ? 'Override' : 'Inherited'}: ${describeChatSetting(effectiveChatSetting(before, scope, field))}`,
                after: `${Object.hasOwn(b, field) ? 'Override' : 'Inherited'}: ${describeChatSetting(effectiveChatSetting(after, scope, field))}`,
                impact:
                    field === 'urgent_contact'
                        ? `Shown to authorised families and care-team staff for ${label}'s clients. Other sites keep their own contact.`
                        : `Applies to ${label}'s client conversations and managed site channels. Other sites and private staff conversations keep their own policy.`,
            });
        }
    }
    return result;
}
