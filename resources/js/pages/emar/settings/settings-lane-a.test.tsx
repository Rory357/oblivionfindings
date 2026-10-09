/* Lane A settings (Stephan, 9 Oct: "if it needs a toggle setting add it to
 * the settings"): the forgotten-PIN fallback and its time limit on Staff &
 * PINs › Witness PINs, and how long a dose saved on a device can wait on
 * Rounds & timing › Dose timing. Saved through the save bar like the rest. */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type SettingsContext } from './_context';
import type { Draft, SettingDefinition, SettingsPayload } from './_model';
import { WitnessPins } from './_sections';
import { DoseTiming } from './_timing';

vi.mock('@inertiajs/react', () => ({
    router: { put: vi.fn(), post: vi.fn(), reload: vi.fn(), visit: vi.fn() },
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

afterEach(cleanup);

const def = (
    group: string,
    key: string,
    section: string,
    label: string,
    over: Partial<SettingDefinition>,
): SettingDefinition => ({
    group,
    key,
    scope: 'organisation',
    section,
    label,
    options: [],
    default: '',
    rank: null,
    numeric: null,
    range: null,
    unit: null,
    paired_with: null,
    ...over,
});

const looser = {
    direction: 'higher_is_looser',
    off: null,
    off_is_loosest: false,
} as SettingDefinition['numeric'];

const pin = {
    max_attempts: def('pin', 'max_attempts', 'pins', 'Wrong attempts', {
        default: '5',
        range: [3, 10],
        unit: 'attempts',
        numeric: looser,
    }),
    lockout_minutes: def('pin', 'lockout_minutes', 'pins', 'Locked for', {
        default: '15',
        range: [5, 60],
        unit: 'minutes',
        numeric: looser,
    }),
    renewal_months: def('pin', 'renewal_months', 'pins', 'PIN renewal', {
        default: 'none',
        range: [1, 24],
        unit: 'months',
        numeric: { ...looser!, off: 'none', off_is_loosest: true },
    }),
};

const forgotten = {
    forgotten_fallback: def(
        'forgotten',
        'forgotten_fallback',
        'pins',
        'Forgotten-PIN fallback',
        {
            default: 'no',
            rank: ['yes', 'no'],
            options: [
                { value: 'no', label: 'Not allowed' },
                { value: 'yes', label: 'Allowed' },
            ],
        },
    ),
    confirm_minutes: def(
        'forgotten',
        'confirm_minutes',
        'pins',
        'Time limit for a named colleague to confirm',
        { default: '30', range: [10, 240], unit: 'minutes', numeric: looser },
    ),
};

const offline = {
    max_hours: def(
        'offline',
        'max_hours',
        'timing',
        'A dose saved on a device can still be sent for',
        { default: '12', range: [1, 48], unit: 'hours', numeric: looser },
    ),
};

const payload = (): SettingsPayload => ({
    groups: {
        pin: {
            key: 'pin',
            view: 'staff',
            effect: 'From the next dose signed or witnessed',
            audit_event: 'medications.witness_pin_rules.updated',
            keys: Object.keys(pin),
        },
        forgotten: {
            key: 'forgotten',
            view: 'staff',
            effect: 'From the next dose signed, at every house',
            audit_event: 'medications.forgotten_pin.updated',
            keys: Object.keys(forgotten),
        },
        offline: {
            key: 'offline',
            view: 'rounds',
            effect: 'From the next dose sent from a device, at every house',
            audit_event: 'medications.offline_replay.updated',
            keys: Object.keys(offline),
        },
    },
    definitions: { pin, forgotten, offline },
    values: {
        pin: {
            max_attempts: '5',
            lockout_minutes: '15',
            renewal_months: 'none',
        },
        forgotten: { forgotten_fallback: 'yes', confirm_minutes: '30' },
        offline: { max_hours: '12' },
    },
    reviewed: {
        pin: {
            max_attempts: null,
            lockout_minutes: null,
            renewal_months: null,
        },
        forgotten: { forgotten_fallback: null, confirm_minutes: null },
        offline: { max_hours: null },
    },
    site_values: {},
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
});

function renderWith(node: ReactNode) {
    const s = payload();
    let draft: Draft = {};
    const setDraft = vi.fn((fn: (d: Draft) => Draft) => {
        draft = fn(draft);
    });
    const ctx = {
        s,
        draft: {},
        setDraft,
        canEdit: (group: string) => !!s.groups[group],
        go: vi.fn(),
        open: vi.fn(),
        close: vi.fn(),
        flash: vi.fn(),
        freshAfter: 0,
        leave: vi.fn(),
        errors: {},
        clearError: vi.fn(),
    } as unknown as SettingsContext;
    render(<SettingsCtx.Provider value={ctx}>{node}</SettingsCtx.Provider>);
    return { draft: () => draft };
}

describe('Witness PINs › Forgotten PIN (Lane A settings)', () => {
    it('switches the fallback off as a draft and keeps controlled drugs fixed off', () => {
        const { draft } = renderWith(
            <WitnessPins q="" show="all" clear={vi.fn()} />,
        );
        const allowed = screen.getByRole('switch', { name: 'Allowed' });
        expect(allowed).toBeChecked();
        fireEvent.click(allowed);
        expect(draft()).toEqual({ forgotten: { forgotten_fallback: 'no' } });

        expect(
            screen.getByText('Controlled and witness-required medicines'),
        ).toBeInTheDocument();
        expect(screen.getByText('Always off')).toBeInTheDocument();
        expect(
            screen.queryByRole('switch', {
                name: 'Controlled and witness-required medicines',
            }),
        ).not.toBeInTheDocument();
    });

    it('edits the time a named colleague has to confirm', () => {
        const { draft } = renderWith(
            <WitnessPins q="" show="all" clear={vi.fn()} />,
        );
        const input = screen.getByLabelText('Time to confirm');
        expect(input).toHaveValue(30);
        fireEvent.change(input, { target: { value: '45' } });
        expect(draft()).toEqual({ forgotten: { confirm_minutes: '45' } });
    });
});

describe('Dose timing › Saved on a device (Lane A settings)', () => {
    it('edits how long a saved dose can still be sent', () => {
        const { draft } = renderWith(
            <DoseTiming q="" show="all" clear={vi.fn()} />,
        );
        expect(screen.getByText('Saved on a device')).toBeInTheDocument();
        const input = screen.getByLabelText('Can still be sent for');
        expect(input).toHaveValue(12);
        fireEvent.change(input, { target: { value: '6' } });
        expect(draft()).toEqual({ offline: { max_hours: '6' } });
    });

    it('is found by searching for the setting’s own words', () => {
        renderWith(<DoseTiming q="device" show="all" clear={vi.fn()} />);
        expect(
            screen.getByLabelText('Can still be sent for'),
        ).toBeInTheDocument();
    });
});
