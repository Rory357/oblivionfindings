import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import type { ReactNode } from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { SettingsCtx, type SettingsContext } from './_context';
import type { SettingDefinition, SettingsPayload } from './_model';
import {
    canRemind,
    pinHouseOptions,
    PinStatus,
    type WitnessPinStaffRow,
} from './_sections';
import { Competency, ExemptionLimit, StaffOverview } from './_staff';

const routerMock = vi.hoisted(() => ({
    put: vi.fn(),
    post: vi.fn(),
    reload: vi.fn(),
    visit: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    router: routerMock,
    Link: ({ children, href }: { children: ReactNode; href: string }) => (
        <a href={href}>{children}</a>
    ),
}));

const number = (
    group: string,
    key: string,
    section: string,
    label: string,
    range: [number, number],
    unit: string,
    dflt: string,
    over: Partial<SettingDefinition> = {},
): SettingDefinition => ({
    group,
    key,
    scope: 'organisation',
    section,
    label,
    options: [],
    default: dflt,
    rank: null,
    numeric: {
        direction: 'higher_is_looser',
        off: null,
        off_is_loosest: false,
    },
    range,
    unit,
    paired_with: null,
    ...over,
});

const elig = {
    validity: number(
        'elig',
        'validity',
        'competency',
        'An assessment stays current for',
        [1, 36],
        'months',
        '12',
    ),
    pass_mark: number(
        'elig',
        'pass_mark',
        'competency',
        'Pass mark',
        [1, 12],
        'of the 12 areas passed',
        '10',
    ),
    core_must_pass: {
        ...number(
            'elig',
            'core_must_pass',
            'competency',
            'Every core area must be passed',
            [1, 1],
            '',
            'no',
        ),
        options: [
            { value: 'no', label: 'Off — only the pass mark counts' },
            { value: 'yes', label: 'On' },
        ],
        range: null,
        numeric: null,
        rank: ['no', 'yes'],
    },
    observed_minimum: number(
        'elig',
        'observed_minimum',
        'competency',
        'Minimum observed administrations',
        [1, 100],
        'observed administrations',
        'off',
        {
            numeric: {
                direction: 'higher_is_stricter',
                off: 'off',
                off_is_loosest: true,
            },
            off_label: 'Off — no minimum',
            when_not_configured: 'No minimum is asked for',
        },
    ),
    reminder: number(
        'elig',
        'reminder',
        'competency',
        'Renewal reminder',
        [1, 180],
        'days before the end date',
        '30',
    ),
    longest_exemption: number(
        'elig',
        'longest_exemption',
        'exemptions',
        'Longest exemption',
        [1, 90],
        'days',
        '30',
    ),
};
const pin = {
    max_attempts: number(
        'pin',
        'max_attempts',
        'pins',
        'Wrong attempts before a PIN locks',
        [3, 10],
        'attempts',
        '5',
    ),
    lockout_minutes: number(
        'pin',
        'lockout_minutes',
        'pins',
        'How long a locked PIN stays locked',
        [5, 60],
        'minutes',
        '15',
    ),
    renewal_months: number(
        'pin',
        'renewal_months',
        'pins',
        'PIN renewal (optional)',
        [1, 24],
        'months',
        'none',
        {
            numeric: {
                direction: 'higher_is_looser',
                off: 'none',
                off_is_loosest: true,
            },
            off_label: 'No renewal',
        },
    ),
};

const payload = (over: Partial<SettingsPayload> = {}): SettingsPayload => ({
    groups: {
        elig: {
            key: 'elig',
            view: 'staff',
            effect: 'From the next assessment or exemption recorded',
            audit_event: 'medications.competency_policy.updated',
            keys: Object.keys(elig),
        },
        pin: {
            key: 'pin',
            view: 'staff',
            effect: 'From the next dose signed or witnessed',
            audit_event: 'medications.witness_pin_rules.updated',
            keys: Object.keys(pin),
        },
    },
    definitions: { elig, pin },
    values: {
        elig: {
            validity: '12',
            pass_mark: '10',
            core_must_pass: 'no',
            observed_minimum: 'off',
            reminder: '30',
            longest_exemption: '30',
        },
        pin: {
            max_attempts: '5',
            lockout_minutes: '15',
            renewal_months: '12',
        },
        safety: { restricted_competency: 'cosigner', competency_areas: 'off' },
    },
    reviewed: {
        elig: {
            validity: { by: 'Hana Kereama', at: null },
            pass_mark: null,
            core_must_pass: null,
            observed_minimum: null,
            reminder: null,
            longest_exemption: null,
        },
        pin: {
            max_attempts: null,
            lockout_minutes: null,
            renewal_months: null,
        },
    },
    site_values: {},
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
    ...over,
});

function renderWith(
    node: ReactNode,
    over: Partial<SettingsContext> = {},
    s: SettingsPayload = payload(),
) {
    const ctx: SettingsContext = {
        s,
        draft: {},
        setDraft: vi.fn(),
        canEdit: (group) => !!s.groups[group] && s.can_manage_organisation,
        go: vi.fn(),
        open: vi.fn(),
        close: vi.fn(),
        flash: vi.fn(),
        freshAfter: 0,
        leave: vi.fn(),
        errors: {},
        clearError: vi.fn(),
        ...over,
    };
    return {
        ctx,
        ...render(
            <SettingsCtx.Provider value={ctx}>{node}</SettingsCtx.Provider>,
        ),
    };
}

const row = (over: Partial<WitnessPinStaffRow>): WitnessPinStaffRow => ({
    id: 1,
    name: 'Aroha Ngata',
    status: 'set',
    set_at: '2026-09-20T01:00:00Z',
    locked_until: null,
    reset_at: null,
    can_reset: true,
    house: 'Kōwhai House',
    reminded_at: null,
    reminded_by: null,
    reminded_today: false,
    ...over,
});
const staff = [
    row({ id: 1, name: 'Aroha Ngata' }),
    row({ id: 2, name: 'Ben Carter', status: 'not_set', set_at: null }),
    row({
        id: 3,
        name: 'Chloe Wu',
        status: 'reset',
        house: 'Rimu House',
        reset_at: '2026-09-30T01:00:00Z',
    }),
    row({
        id: 4,
        name: 'Dan Tipene',
        status: 'not_set',
        set_at: null,
        reminded_at: '2026-10-01T20:12:00Z',
        reminded_by: 'Hana Kereama',
        reminded_today: true,
    }),
    row({ id: 5, name: 'Eru Parata', status: 'locked', can_reset: false }),
];

afterEach(() => {
    cleanup();
    vi.clearAllMocks();
});

describe('Staff & PINs › Overview', () => {
    it('shows the four cards with today’s values, review state and PIN counts', () => {
        const { ctx } = renderWith(
            <StaffOverview q="" witnessPin={{ can_reset: true, staff }} />,
        );
        expect(
            screen.getByText(
                'An assessment lasts 12 months. Pass mark 10 of 12.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText('An exemption lasts up to 30 days, at one house.'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                'Locks after 5 wrong attempts, for 15 minutes. Renewed every 12 months.',
            ),
        ).toBeInTheDocument();
        expect(
            screen.getByText(
                '1 of 5 people have set a PIN · 1 locked · 3 still to set.',
            ),
        ).toBeInTheDocument();
        // validity is reviewed; pass mark, core areas and reminder aren't.
        expect(screen.getByText('3 not yet reviewed')).toBeInTheDocument();
        expect(screen.queryByText(/Forgotten PIN/)).toBeNull();
        fireEvent.click(
            screen.getByRole('button', { name: /Review exemption limit/ }),
        );
        expect(ctx.go).toHaveBeenCalledWith('staff', 'exemptions');
    });
});

describe('Staff & PINs › Competency', () => {
    it('shows the observed minimum as not configured until a number is chosen', () => {
        const setDraft = vi.fn();
        renderWith(<Competency q="" show="all" clear={vi.fn()} />, {
            setDraft,
        });
        expect(screen.getByText('Not configured')).toBeInTheDocument();
        expect(
            screen.queryByRole('spinbutton', {
                name: 'Minimum observed administrations',
            }),
        ).toBeNull();
        fireEvent.click(screen.getAllByRole('switch')[1]);
        // On asks for a number: an empty box to fill in.
        expect(setDraft.mock.calls[0][0]({})).toEqual({
            elig: { observed_minimum: '' },
        });
    });

    it('turns "every core area must pass" on through the draft', () => {
        const setDraft = vi.fn();
        renderWith(<Competency q="" show="all" clear={vi.fn()} />, {
            setDraft,
        });
        fireEvent.click(screen.getAllByRole('switch')[0]);
        expect(setDraft.mock.calls[0][0]({})).toEqual({
            elig: { core_must_pass: 'yes' },
        });
    });

    it('says how competency is used today, and links to the register', () => {
        renderWith(<Competency q="" show="all" clear={vi.fn()} />);
        expect(
            screen.getByText('Co-signer with witness PIN (Safety checks)'),
        ).toBeInTheDocument();
        expect(
            screen.getByRole('link', { name: /Open the competency register/ }),
        ).toHaveAttribute('href', '/emar/competency');
    });

    it('shows a value the server won’t take at its field', () => {
        renderWith(<ExemptionLimit q="" show="all" clear={vi.fn()} />, {
            errors: {
                'elig.longest_exemption': 'Enter a whole number from 1 to 90.',
            },
        });
        expect(screen.getByRole('spinbutton')).toHaveAttribute(
            'aria-invalid',
            'true',
        );
        expect(
            screen.getByText('Enter a whole number from 1 to 90.'),
        ).toBeInTheDocument();
    });
});

describe('Staff & PINs › PIN status reminders', () => {
    it('reminds only people without a usable PIN, whose PIN they could reset, not already reminded today', () => {
        expect(
            staff.filter((x) => canRemind(true, x)).map((x) => x.id),
        ).toEqual([2, 3]);
        expect(staff.filter((x) => canRemind(false, x))).toEqual([]);
        expect(pinHouseOptions(staff).map((o) => o.label)).toEqual([
            'All houses',
            'Kōwhai House',
            'Rimu House',
        ]);
    });

    it('sends the reminders after confirming, and says who reminded whom', () => {
        renderWith(
            <PinStatus
                witnessPin={{ can_reset: true, staff }}
                q=""
                state="all"
                house="all"
                clear={vi.fn()}
            />,
        );
        expect(
            screen.getByText(/^Reminded today .*, by Hana Kereama$/),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Remind 2 to set a PIN' }),
        );
        expect(
            screen.getByText('Remind 2 people to set a witness PIN?'),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Send 2 reminders' }),
        );
        expect(routerMock.post).toHaveBeenCalledWith(
            '/emar/settings/witness-pins/remind',
            { user_ids: [2, 3] },
            expect.anything(),
        );
    });

    it('filters by house', () => {
        renderWith(
            <PinStatus
                witnessPin={{ can_reset: true, staff }}
                q=""
                state="all"
                house="Rimu House"
                clear={vi.fn()}
            />,
        );
        expect(screen.getByText('1 of 5 shown')).toBeInTheDocument();
        expect(screen.getByText('Chloe Wu')).toBeInTheDocument();
        expect(screen.queryByText('Aroha Ngata')).toBeNull();
    });
});
