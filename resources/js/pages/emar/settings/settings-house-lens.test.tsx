import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { HouseLens } from './_house-lens';
import type { SettingDefinition, SettingsPayload } from './_model';
import type { MedicineRule } from './_rules';
import type { WitnessPinStaffRow } from './_sections';
import type { RoundTemplate } from './_templates';

vi.mock('@inertiajs/react', () => ({ router: { post: vi.fn() } }));

const def = (
    group: string,
    key: string,
    options: [string, string][],
    dflt: string,
): SettingDefinition => ({
    group,
    key,
    scope: 'organisation',
    section: group,
    label: key,
    options: options.map(([value, label]) => ({ value, label })),
    default: dflt,
    rank: null,
    numeric: null,
    range: null,
    unit: null,
    paired_with: null,
});
const s: SettingsPayload = {
    groups: {},
    definitions: {
        safety: {
            profile_allergy_match: def(
                'safety',
                'profile_allergy_match',
                [['block', 'Block — contact the prescriber']],
                'block',
            ),
            restricted_competency: def(
                'safety',
                'restricted_competency',
                [
                    [
                        'cosigner',
                        'Co-signer with witness PIN (recommended) — a present colleague',
                    ],
                ],
                'cosigner',
            ),
            competency_areas: def(
                'safety',
                'competency_areas',
                [['off', 'Off — no extra check']],
                'off',
            ),
        },
    },
    values: {
        timing: { early: '30', late: '60', late_incident: '120' },
        elig: { validity: '12', pass_mark: '10', longest_exemption: '30' },
        pin: {
            max_attempts: '5',
            lockout_minutes: '15',
            renewal_months: 'none',
        },
    },
    reviewed: {},
    site_values: {},
    site_reviewed: {},
    history: [],
    can_manage_organisation: true,
};
const rule = (over: Partial<MedicineRule>): MedicineRule => ({
    id: 1,
    site_id: null,
    site_name: null,
    match_type: 'medicine_name' as MedicineRule['match_type'],
    match_value: 'Warfarin',
    requires_countersign: true,
    required_observations: [],
    active: true,
    what: 'Warfarin',
    needs: 'Second person confirms',
    sentence: '',
    last_changed_by: null,
    last_changed_at: null,
    paused_note: null,
    can_change: true,
    concealed: false,
    overlaps: [],
    ...over,
});
const houses = [
    { id: 3, name: 'Kōwhai House' },
    { id: 4, name: 'Rimu House' },
];
const templates = [
    {
        id: 9,
        name: 'Morning round',
        scheduled_time: '08:00',
        window_minutes: 30,
        days_of_week: [],
        status: 'active',
        site_id: 3,
        site_name: 'Kōwhai House',
        default_assigned_to: null,
        default_staff: null,
        today: null,
        last_changed_by: null,
        last_changed_at: null,
        can_change: true,
    },
] as RoundTemplate[];
const pins = [
    { id: 1, name: 'Aroha', status: 'set', house_id: 3 },
    { id: 2, name: 'Ben', status: 'not_set', house_id: 3 },
    { id: 3, name: 'Chloe', status: 'set', house_id: 4 },
] as WitnessPinStaffRow[];

afterEach(cleanup);

describe('What applies at a house', () => {
    it('lists the organisation’s rules and the house’s own, read-only', () => {
        render(
            <HouseLens
                s={s}
                houses={houses}
                rules={[
                    rule({ id: 1 }),
                    rule({ id: 2, site_id: 3, what: 'Insulin glargine' }),
                    rule({ id: 3, site_id: 4, what: 'Morphine' }),
                ]}
                templates={templates}
                pins={pins}
                onOpen={vi.fn()}
                onClose={vi.fn()}
            />,
        );
        expect(
            screen.getByText('What applies at Kōwhai House'),
        ).toBeInTheDocument();
        expect(screen.getByText('Block')).toBeInTheDocument();
        expect(
            screen.getByText('Co-signer with witness PIN (recommended)'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Medicine rules that apply here (2)'),
        ).toBeInTheDocument();
        expect(screen.getByText('Insulin glargine')).toBeInTheDocument();
        expect(screen.queryByText('Morphine')).toBeNull();
        expect(screen.getAllByText('This house')).toHaveLength(1);
        // Not built yet: P07a's controlled-drug witness. Alerts & on-call
        // shows only for someone who sees Alerts & access (below).
        expect(screen.queryByText(/Controlled drugs/)).toBeNull();
        expect(screen.queryByText(/Alerts & on-call/)).toBeNull();
    });

    it('shows the house’s rounds and its witness PINs', () => {
        render(
            <HouseLens
                s={s}
                houses={houses}
                rules={[]}
                templates={templates}
                pins={pins}
                onOpen={vi.fn()}
                onClose={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Rounds & timing/ }),
        );
        expect(screen.getByText('Morning round · 8:00 am')).toBeInTheDocument();
        expect(screen.getByText('Every day · ±30 min')).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /Staff & PINs/ }));
        expect(screen.getByText('1 of 2')).toBeInTheDocument();
        expect(screen.getByText('No renewal')).toBeInTheDocument();
    });

    it('switches house in the footer, and opens the view behind each step', () => {
        const onOpen = vi.fn();
        render(
            <HouseLens
                s={s}
                houses={houses}
                rules={[rule({ id: 3, site_id: 4, what: 'Morphine' })]}
                templates={templates}
                pins={pins}
                onOpen={onOpen}
                onClose={vi.fn()}
            />,
        );
        expect(screen.queryByText('Morphine')).toBeNull();
        fireEvent.click(screen.getByRole('button', { name: 'Rimu House' }));
        expect(
            screen.getByRole('button', { name: 'Rimu House' }),
        ).toHaveAttribute('aria-pressed', 'true');
        expect(
            screen.getByText('What applies at Rimu House'),
        ).toBeInTheDocument();
        expect(screen.getByText('Morphine')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Open Medication rules/ }),
        );
        expect(onOpen).toHaveBeenLastCalledWith('rules');
        fireEvent.click(screen.getByRole('button', { name: /Staff & PINs/ }));
        expect(screen.getByText('1 of 1')).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /Open Staff & PINs/ }),
        );
        expect(onOpen).toHaveBeenLastCalledWith('staff');
    });

    it('keeps two houses with the same name apart', () => {
        render(
            <HouseLens
                s={s}
                houses={[
                    { id: 7, name: 'Remote Compact Site' },
                    { id: 8, name: 'Remote Compact Site' },
                ]}
                rules={[]}
                templates={[]}
                pins={[
                    { ...pins[0], house_id: 7 },
                    { ...pins[1], house_id: 8 },
                    { ...pins[2], house_id: 8 },
                ]}
                onOpen={vi.fn()}
                onClose={vi.fn()}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /Staff & PINs/ }));
        expect(screen.getByText('1 of 1')).toBeInTheDocument();
    });

    it('chooses among many houses with a searchable picker instead', () => {
        render(
            <HouseLens
                s={s}
                houses={[1, 2, 3, 4, 5].map((id) => ({
                    id,
                    name: `House ${id}`,
                }))}
                rules={[]}
                templates={[]}
                pins={[]}
                onOpen={vi.fn()}
                onClose={vi.fn()}
            />,
        );
        expect(screen.queryByRole('button', { name: 'House 2' })).toBeNull();
        expect(document.getElementById('lens-house')).not.toBeNull();
    });

    it('adds Alerts & on-call for someone who sees Alerts & access (B2 C4)', () => {
        render(
            <HouseLens
                s={s}
                houses={houses}
                rules={[]}
                templates={[]}
                pins={[]}
                oncall={{
                    houses: [
                        {
                            site_id: houses[0].id,
                            name: houses[0].name,
                            house_leads: [],
                            rule: {
                                mode: 'roster',
                                team_lead: true,
                                backup: {
                                    id: 7,
                                    name: 'Hana Kereama',
                                    phone: '021 555 0142',
                                },
                                describe:
                                    'Follows the roster, then the team lead on shift · backup Hana Kereama',
                                changed_by: null,
                                changed_at: null,
                            },
                            roster: [
                                {
                                    label: 'Tonight',
                                    hours: '5:00 pm – 7:00 am',
                                    on_call: null,
                                    team_lead: null,
                                },
                            ],
                            can_manage: false,
                        },
                    ],
                    staff: {},
                }}
                onOpen={vi.fn()}
                onClose={vi.fn()}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Alerts & on-call/ }),
        );
        expect(
            screen.getByText('The roster, then the team lead on shift'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Hana Kereama · 021 555 0142'),
        ).toBeInTheDocument();
        expect(screen.getByText('On-call contact')).toBeInTheDocument();
    });
});
