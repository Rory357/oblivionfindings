/* Lane A settings (Stephan, 9 Oct: "if it needs a toggle setting add it to
 * the settings"): the forgotten-PIN fallback and its time limit on Staff &
 * PINs › Witness PINs, and how long a dose saved on a device can wait on
 * Rounds & timing › Dose timing. Saved through the save bar like the rest. */
import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type SettingsContext } from './_context';
import type { Draft, SettingDefinition, SettingsPayload } from './_model';
import { MedicinePhotos } from './_photos';
import { SafetyChecks, WitnessPins } from './_sections';
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

const away = {
    cap_hours: def(
        'away',
        'cap_hours',
        'timing',
        'With no recorded return, doses stay Away for',
        { default: '24', range: [4, 168], unit: 'hours after the planned end', numeric: looser },
    ),
    return_followup: def(
        'away',
        'return_followup',
        'timing',
        'Ask the house lead to record the return',
        {
            default: 'yes',
            rank: ['no', 'yes'],
            options: [
                { value: 'no', label: 'Off' },
                { value: 'yes', label: 'On' },
            ],
        },
    ),
    hospital_no_discharge: def(
        'away',
        'hospital_no_discharge',
        'timing',
        'In hospital with no discharge recorded',
        {
            default: 'follow_up',
            rank: ['follow_up', 'owed_after_cap'],
            options: [
                { value: 'follow_up', label: 'Stay Away' },
                { value: 'owed_after_cap', label: 'Owed after the cap' },
            ],
        },
    ),
};

const packPhoto = {
    show: def(
        'packPhoto',
        'show',
        'photos',
        'Show the pack photo when a dose is recorded',
        {
            default: 'yes',
            rank: ['no', 'yes'],
            options: [
                { value: 'no', label: 'Off' },
                { value: 'yes', label: 'On' },
            ],
        },
    ),
    no_photo: def('packPhoto', 'no_photo', 'photos', 'When there’s no photo', {
        default: 'warn',
        rank: ['warn', 'tick'],
        options: [
            { value: 'warn', label: 'Warn' },
            { value: 'tick', label: 'Ask for a tick' },
        ],
    }),
};

const chartAlerts = {
    reconfirm: def(
        'chartAlerts',
        'reconfirm',
        'safety',
        'Staff confirm “Prompt staff” chart alerts',
        {
            default: 'day',
            rank: ['day', 'dose'],
            options: [
                { value: 'day', label: 'Once a day' },
                { value: 'dose', label: 'Every dose' },
            ],
        },
    ),
};

const payload = (): SettingsPayload => ({
    groups: {
        chartAlerts: {
            key: 'chartAlerts',
            view: 'rules',
            effect: 'From the next dose recorded, at every house',
            audit_event: 'medications.chart_alert_prompts.updated',
            keys: Object.keys(chartAlerts),
        },
        packPhoto: {
            key: 'packPhoto',
            view: 'rules',
            effect: 'From the next dose recorded, at every house',
            audit_event: 'medications.pack_photo.updated',
            keys: Object.keys(packPhoto),
        },
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
        away: {
            key: 'away',
            view: 'rounds',
            effect: 'From the next dose shown, at every house — a recorded return always counts',
            audit_event: 'medications.away_no_return.updated',
            keys: Object.keys(away),
        },
    },
    definitions: { pin, forgotten, offline, away, packPhoto, chartAlerts },
    values: {
        pin: {
            max_attempts: '5',
            lockout_minutes: '15',
            renewal_months: 'none',
        },
        forgotten: { forgotten_fallback: 'yes', confirm_minutes: '30' },
        offline: { max_hours: '12' },
        away: {
            cap_hours: '24',
            return_followup: 'yes',
            hospital_no_discharge: 'follow_up',
        },
        packPhoto: { show: 'yes', no_photo: 'warn' },
        chartAlerts: { reconfirm: 'day' },
    },
    reviewed: {
        pin: {
            max_attempts: null,
            lockout_minutes: null,
            renewal_months: null,
        },
        forgotten: { forgotten_fallback: null, confirm_minutes: null },
        offline: { max_hours: null },
        away: {
            cap_hours: null,
            return_followup: null,
            hospital_no_discharge: null,
        },
        packPhoto: { show: null, no_photo: null },
        chartAlerts: { reconfirm: null },
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

describe('Dose timing › Away with no recorded return (Lane A8, D6)', () => {
    it('edits how long doses stay Away and switches the lead’s follow-up off as a draft', () => {
        const { draft } = renderWith(
            <DoseTiming q="" show="all" clear={vi.fn()} />,
        );
        expect(
            screen.getByText('Away with no recorded return'),
        ).toBeInTheDocument();
        const cap = screen.getByLabelText(
            'With no recorded return, doses stay Away for',
        );
        expect(cap).toHaveValue(24);
        fireEvent.change(cap, { target: { value: '12' } });
        expect(draft()).toEqual({ away: { cap_hours: '12' } });

        const followup = screen.getByRole('switch', {
            name: 'Ask the house lead to record the return',
        });
        expect(followup).toBeChecked();
        fireEvent.click(followup);
        expect(draft()).toEqual({
            away: { cap_hours: '12', return_followup: 'no' },
        });
    });

    it('makes an open hospital stay owed after the cap as a draft (Main, 14 Oct)', () => {
        const { draft } = renderWith(
            <DoseTiming q="" show="all" clear={vi.fn()} />,
        );
        expect(
            screen.getByText('In hospital with no discharge recorded'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Owed after the time above' }),
        );
        expect(draft()).toEqual({
            away: { hospital_no_discharge: 'owed_after_cap' },
        });
    });

    it('is found by searching for the setting’s own words', () => {
        renderWith(<DoseTiming q="return" show="all" clear={vi.fn()} />);
        expect(
            screen.getByRole('switch', {
                name: 'Ask the house lead to record the return',
            }),
        ).toBeInTheDocument();
    });
});

describe('Medicine photos › At recording (Lane A11 settings)', () => {
    it('asks for a tick instead of a warning, and hides the choice when photos are off', () => {
        const { draft } = renderWith(
            <MedicinePhotos q="" show="all" clear={vi.fn()} />,
        );
        expect(screen.getByText('At recording')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Ask for “I’ve checked the label”',
            }),
        );
        expect(draft()).toEqual({ packPhoto: { no_photo: 'tick' } });

        fireEvent.click(
            screen.getByRole('switch', {
                name: 'Show the pack photo when a dose is recorded',
            }),
        );
        expect(draft()).toEqual({
            packPhoto: { no_photo: 'tick', show: 'no' },
        });
    });
});

describe('Safety checks › Chart alerts (Lane A5 settings)', () => {
    it('asks for a confirmation every dose instead of once a day', () => {
        const { draft } = renderWith(
            <SafetyChecks q="" show="all" clear={vi.fn()} />,
        );
        expect(screen.getByText('Chart alerts')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: 'Every dose' }));
        expect(draft()).toEqual({ chartAlerts: { reconfirm: 'dose' } });
    });
});
