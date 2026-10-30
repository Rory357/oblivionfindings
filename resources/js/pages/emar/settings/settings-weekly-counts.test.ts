import { describe, expect, it } from 'vitest';
import {
    accepts,
    format,
    loosens,
    notConfigured,
    validateView,
    type SettingDefinition,
    type SettingsPayload,
} from './_model';

const anchor: SettingDefinition = {
    group: 'controlled_counts',
    key: 'weekly_anchor',
    scope: 'organisation',
    section: 'controlled_product',
    label: 'Weekly count day and time',
    options: [],
    default: 'off',
    range: null,
    unit: null,
    paired_with: null,
    rank: null,
    numeric: null,
    kind: 'weekly_anchor',
    timezone: 'Pacific/Auckland',
    weekday_options: [
        { value: '1', label: 'Monday' },
        { value: '7', label: 'Sunday' },
    ],
    when_not_configured: 'Weekly counts need a day and time.',
};
const monday = JSON.stringify({ day: 1, time: '09:00' });
const sunday = JSON.stringify({ day: 7, time: '03:00' });
const payload: SettingsPayload = {
    groups: {
        controlled_counts: {
            key: 'controlled_counts',
            view: 'rules',
            effect: '',
            audit_event: '',
            keys: ['cadence', 'weekly_anchor', 'overdue_minutes'],
        },
    },
    definitions: { controlled_counts: { weekly_anchor: anchor } },
    values: {
        controlled_counts: {
            cadence: 'week',
            weekly_anchor: 'off',
            overdue_minutes: '60',
        },
    },
    reviewed: {},
    site_values: {},
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
};

describe('weekly count settings', () => {
    it('requires both explicit choices and rejects the ambiguous NZ clock-change hour', () => {
        expect(accepts(anchor, monday)).toBe(true);
        expect(accepts(anchor, sunday)).toBe(true);
        for (const value of [
            '{}',
            '{"day":1,"time":""}',
            '{"day":null,"time":"09:00"}',
            '{"day":7,"time":"02:30"}',
            '{"day":8,"time":"09:00"}',
        ])
            expect(accepts(anchor, value)).toBe(false);
    });
    it('shows the chosen day, time and timezone in review/history instead of stored JSON', () => {
        expect(format(anchor, monday)).toBe(
            'Monday at 9:00 am · Pacific/Auckland',
        );
        expect(format(anchor, 'off')).toBe('Day and time not configured');
        expect(notConfigured(anchor, 'off')).toBe(true);
    });
    it('requires review confirmation when an existing count schedule changes', () => {
        expect(loosens(anchor, monday, sunday)).toBe(true);
        expect(loosens(anchor, monday, 'off')).toBe(true);
        expect(loosens(anchor, 'off', monday)).toBe(false);
        expect(loosens(anchor, monday, monday)).toBe(false);
    });
    it('rejects any weekly policy draft with missing timing, including only changing the late window', () => {
        expect(
            validateView(
                payload,
                { controlled_counts: { overdue_minutes: '90' } },
                'rules',
            ),
        ).toHaveProperty('controlled_counts.weekly_anchor');
        expect(
            validateView(
                payload,
                { controlled_counts: { weekly_anchor: monday } },
                'rules',
            ),
        ).toEqual({});
        expect(
            validateView(
                payload,
                { controlled_counts: { cadence: 'shift' } },
                'rules',
            ),
        ).toEqual({});
    });
});
