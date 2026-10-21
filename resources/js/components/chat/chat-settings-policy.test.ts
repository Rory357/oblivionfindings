import { describe, expect, it } from 'vitest';
import { reviewLatestChatSettings } from './chat-settings-conflict';
import {
    chatPolicyChanges,
    editChatSetting,
    effectiveChatSetting,
    inheritChatSection,
    type ChatConfiguration,
} from './chat-settings-policy';

const before: ChatConfiguration = {
    version: 1,
    enabled: true,
    family_enabled: true,
    files_enabled: true,
    max_file_mb: 20,
    max_files: 5,
    message_change_window_minutes: null,
    calls_enabled: false,
    family_calls_enabled: false,
    max_call_participants: 8,
    response_expectation: '',
    response_schedule: null,
    site_overrides: {
        '8': { max_file_mb: 4, files_enabled: false, response_schedule: null },
        '9': { max_files: 2 },
    },
};
const sites = [
    { id: 8, name: 'Kōwhai House' },
    { id: 9, name: 'Tōtara service' },
];
describe('effective Chat settings review', () => {
    it('reviews an explicit urgent-contact removal separately from inheriting the organisation contact', () => {
        const configured = {
            ...before,
            urgent_contact: {
                label: 'Service on-call',
                phone: '0800 555 0100',
                instructions: 'Urgent support',
                confirmed: true,
            },
        };
        const removed = editChatSetting(
            configured,
            '8',
            'urgent_contact',
            null,
        );
        expect(effectiveChatSetting(removed, '8', 'urgent_contact')).toBeNull();
        expect(effectiveChatSetting(removed, '9', 'urgent_contact')).toEqual(
            configured.urgent_contact,
        );
        expect(chatPolicyChanges(configured, removed, sites)).toContainEqual(
            expect.objectContaining({
                label: 'Kōwhai House · Service urgent contact',
                before: 'Inherited: Service on-call · 0800 555 0100 · Urgent support · Confirmed for family use',
                after: 'Override: Not configured',
            }),
        );
        const inherited = inheritChatSection(removed, '8', 'family');
        expect(effectiveChatSetting(inherited, '8', 'urgent_contact')).toEqual(
            configured.urgent_contact,
        );
        expect(inherited.site_overrides['8'].max_file_mb).toBe(4);
    });
    it('names only inheriting sites when reviewing an organisation urgent-contact change', () => {
        const previous = editChatSetting(before, '8', 'urgent_contact', null);
        const next = editChatSetting(
            previous,
            'organisation',
            'urgent_contact',
            {
                label: 'Service on-call',
                phone: '0800 555 0100',
                instructions: '',
                confirmed: false,
            },
        );
        const changes = chatPolicyChanges(previous, next, sites);
        expect(changes).toHaveLength(1);
        expect(changes[0].after).toContain('Confirmation needed');
        expect(changes[0].impact).toContain('Tōtara service');
        expect(changes[0].impact).not.toContain('Kōwhai House');
        expect(changes[0].impact).toContain(
            'does not enable Oblivion voice calling',
        );
    });
    it('keeps site file controls isolated and treats explicit false and null as overrides', () => {
        const next = editChatSetting(before, '8', 'max_files', 3);
        expect(effectiveChatSetting(next, '8', 'files_enabled')).toBe(false);
        expect(effectiveChatSetting(next, '8', 'max_files')).toBe(3);
        expect(effectiveChatSetting(next, '9', 'max_files')).toBe(2);
        expect(effectiveChatSetting(next, 'organisation', 'max_files')).toBe(5);
        expect(before.site_overrides['8']).not.toHaveProperty('max_files');
        expect(chatPolicyChanges(before, next, sites)).toEqual([
            expect.objectContaining({
                label: 'Kōwhai House · Files per message',
                before: 'Inherited: 5',
                after: 'Override: 3',
            }),
        ]);
    });
    it('reviews reset to inherited against current organisation values without removing other sections', () => {
        const inherited = inheritChatSection(before, '8', 'files');
        expect(inherited.site_overrides['8']).toEqual({
            response_schedule: null,
        });
        expect(inherited.site_overrides['9']).toEqual({ max_files: 2 });
        expect(chatPolicyChanges(before, inherited, sites)).toContainEqual(
            expect.objectContaining({
                label: 'Kōwhai House · Maximum file size (MB)',
                before: 'Override: 4',
                after: 'Inherited: 20',
            }),
        );
    });
    it('keeps concurrent changes to other policy fields and rebases an explicit reset only for edited fields', () => {
        const draft = inheritChatSection(before, '8', 'files');
        const latest = editChatSetting(
            editChatSetting(
                { ...before, version: 2 },
                '8',
                'calls_enabled',
                true,
            ),
            '9',
            'max_files',
            1,
        );
        const reviewed = reviewLatestChatSettings(before, draft, latest);
        expect(reviewed.site_overrides['8']).toEqual({
            response_schedule: null,
            calls_enabled: true,
        });
        expect(reviewed.site_overrides['9']).toEqual({ max_files: 1 });
        expect(reviewed.version).toBe(2);
    });
});
