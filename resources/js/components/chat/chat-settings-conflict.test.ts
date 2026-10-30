import { describe, expect, it } from 'vitest';
import type { ResponsePolicy } from './chat-response-settings';
import { reviewLatestChatSettings } from './chat-settings-conflict';

const before: ResponsePolicy & { version: number; enabled: boolean } = {
    version: 1,
    enabled: true,
    response_expectation: '',
    response_schedule: null,
    site_overrides: { '8': { response_expectation: 'Original wording' } },
};
const schedule = {
    timezone: 'Pacific/Auckland',
    response_minutes: 240,
    weekly: [{ day: 1, start: '08:00', end: '18:00' }],
    holidays: [],
};

describe('review latest Chat settings', () => {
    it('preserves concurrent changes to other fields and sites while keeping the proposed field for review', () => {
        const draft = {
            ...before,
            site_overrides: {
                '8': { response_expectation: 'My proposed wording' },
            },
        };
        const latest = {
            ...before,
            version: 2,
            enabled: false,
            site_overrides: {
                '8': {
                    response_expectation: 'Their latest wording',
                    response_schedule: schedule,
                },
                '9': { response_schedule: null },
            },
        };
        const reviewed = reviewLatestChatSettings(before, draft, latest);
        expect(reviewed).toEqual({
            ...latest,
            site_overrides: {
                ...latest.site_overrides,
                '8': {
                    response_expectation: 'My proposed wording',
                    response_schedule: schedule,
                },
            },
        });
        expect(latest.site_overrides['8'].response_expectation).toBe(
            'Their latest wording',
        );
    });

    it('resets only the reviewed override field to inherited without deleting newly added site settings', () => {
        const draft = { ...before, site_overrides: {} };
        const latest = {
            ...before,
            version: 2,
            site_overrides: {
                '8': {
                    response_expectation: 'Changed concurrently',
                    response_schedule: schedule,
                },
            },
        };
        expect(
            reviewLatestChatSettings(before, draft, latest).site_overrides['8'],
        ).toEqual({ response_schedule: schedule });
    });

    it('does not invent changes after an uncertain save already applied the draft', () => {
        const draft = { ...before, response_schedule: schedule };
        const applied = { ...draft, version: 2 };
        expect(reviewLatestChatSettings(before, draft, applied)).toEqual(
            applied,
        );
    });
});
