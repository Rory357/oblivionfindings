import { describe, expect, it } from 'vitest';
import {
    canRestore,
    changes,
    loosens,
    stillToDecide,
    withDraft,
    withoutDrafts,
    type HistoryEntry,
    type SettingDefinition,
    type SettingsPayload,
} from './_model';

const restricted: SettingDefinition = {
    group: 'safety',
    key: 'restricted_competency',
    scope: 'organisation',
    section: 'safety',
    label: 'A worker’s medication competency is marked restricted',
    options: [
        { value: 'off', label: 'Off — no extra check' },
        { value: 'block', label: 'Block' },
        { value: 'cosigner', label: 'Co-signer with witness PIN' },
    ],
    default: 'off',
    rank: ['off', 'cosigner', 'block'],
    numeric: null,
    range: null,
    unit: null,
    paired_with: null,
};
const renewal: SettingDefinition = {
    group: 'pin',
    key: 'renewal_months',
    scope: 'organisation',
    section: 'pins',
    label: 'PIN renewal (optional)',
    options: [
        { value: 'none', label: 'No renewal' },
        { value: '6', label: 'Every 6 months' },
        { value: '12', label: 'Every 12 months' },
    ],
    default: 'none',
    rank: null,
    numeric: {
        direction: 'higher_is_looser',
        off: 'none',
        off_is_loosest: true,
    },
    range: null,
    unit: null,
    paired_with: null,
};

const payload = (over: Partial<SettingsPayload> = {}): SettingsPayload => ({
    groups: {
        safety: {
            key: 'safety',
            view: 'rules',
            effect: 'From the next dose signed, at every house',
            audit_event: 'medications.safety_policy.updated',
            keys: ['restricted_competency'],
        },
        pin: {
            key: 'pin',
            view: 'staff',
            effect: 'From the next dose signed or witnessed, at every house',
            audit_event: 'medications.witness_pin_rules.updated',
            keys: ['renewal_months'],
        },
    },
    definitions: {
        safety: { restricted_competency: restricted },
        pin: { renewal_months: renewal },
    },
    values: {
        safety: { restricted_competency: 'block' },
        pin: { renewal_months: 'none' },
    },
    reviewed: {
        safety: { restricted_competency: { by: 'Hana Kereama', at: null } },
        pin: { renewal_months: null },
    },
    site_values: {},
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
    ...over,
});

describe('loosens — the same rule as the server', () => {
    it('ranks options from loosest to strictest', () => {
        expect(loosens(restricted, 'block', 'cosigner')).toBe(true);
        expect(loosens(restricted, 'block', 'off')).toBe(true);
        expect(loosens(restricted, 'off', 'block')).toBe(false);
        expect(loosens(restricted, 'block', 'block')).toBe(false);
    });
    it('treats switching a number off as loosest, and switching on as never looser', () => {
        expect(loosens(renewal, '6', 'none')).toBe(true);
        expect(loosens(renewal, '6', '12')).toBe(true);
        expect(loosens(renewal, 'none', '12')).toBe(false);
        expect(loosens(renewal, '12', '6')).toBe(false);
    });
});

describe('drafts', () => {
    it('lists only real changes, per view, with words and the loosening flag', () => {
        const s = payload();
        let draft = withDraft({}, 'safety', 'restricted_competency', 'off');
        draft = withDraft(draft, 'pin', 'renewal_months', 'none'); // same as saved
        const rules = changes(s, draft, 'rules');
        expect(rules).toHaveLength(1);
        expect(rules[0]).toMatchObject({
            label: 'A worker’s medication competency is marked restricted',
            fromText: 'Block',
            toText: 'Off — no extra check',
            loosens: true,
        });
        expect(changes(s, draft, 'staff')).toHaveLength(0);
        expect(changes(s, withoutDrafts(s, draft, 'rules'))).toHaveLength(0);
    });
});

describe('still to decide', () => {
    it('lists organisation settings nobody has saved or kept, with how they behave meanwhile', () => {
        const pending = stillToDecide(payload());
        expect(pending).toEqual([
            expect.objectContaining({
                group: 'pin',
                key: 'renewal_months',
                view: 'staff',
                state: 'default',
                until: 'Behaves as: No renewal',
            }),
        ]);
    });
});

describe('put the earlier value back', () => {
    const entry = (over: Partial<HistoryEntry> = {}): HistoryEntry => ({
        id: 1,
        at: null,
        who: 'Hana Kereama',
        action: 'changed',
        group: 'safety',
        key: 'restricted_competency',
        site_id: null,
        site_name: null,
        view: 'rules',
        section: 'safety',
        label: restricted.label,
        before_text: 'Off — no extra check',
        after_text: 'Block',
        before_value: 'off',
        loosens: false,
        note: null,
        event: 'medications.safety_policy.updated',
        ...over,
    });
    const can = () => true;

    it('offers a saved change whose earlier value differs from the draft', () => {
        expect(canRestore(payload(), {}, entry(), can)).toBe(true);
    });
    it('never offers a kept default, an unknown value, or a value already in the draft', () => {
        expect(
            canRestore(
                payload(),
                {},
                entry({ action: 'kept', before_value: null }),
                can,
            ),
        ).toBe(false);
        expect(
            canRestore(payload(), {}, entry({ before_value: 'always' }), can),
        ).toBe(false);
        expect(
            canRestore(
                payload(),
                withDraft({}, 'safety', 'restricted_competency', 'off'),
                entry(),
                can,
            ),
        ).toBe(false);
        expect(canRestore(payload(), {}, entry(), () => false)).toBe(false);
    });
});
