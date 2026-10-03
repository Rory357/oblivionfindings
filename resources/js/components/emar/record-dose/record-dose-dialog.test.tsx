import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

import {
    blockAllCopy,
    blockCopy,
    competencyCopy,
    onCallText,
    whoSentence,
} from './copy';
import { formatAmount } from './fields';
import { RecordDoseDialog } from './record-dose-dialog';
import type {
    BlockedRequirements,
    DoseRequirements,
    DoseTarget,
} from './types';

const getMock = vi.fn();
const submitMock = vi.fn();

vi.mock('axios', async (importOriginal) => {
    const actual = await importOriginal<typeof import('axios')>();
    return {
        ...actual,
        default: {
            ...actual.default,
            get: (...args: unknown[]) => getMock(...args),
            isAxiosError: actual.default.isAxiosError,
        },
    };
});

vi.mock('@/lib/emar-offline', () => ({
    createMedicationMutationReplayState: () => ({
        uuid: '7c9a4d4e-8f1b-4c38-9f59-2f6a2d3b1a10',
        fingerprint: null,
    }),
    prepareMedicationMutationReplayState: (current: { uuid: string }) => ({
        uuid: current.uuid,
        fingerprint: 'x',
    }),
    submitEmarMutation: (...args: unknown[]) => submitMock(...args),
}));

function requirements(
    overrides: Partial<DoseRequirements> = {},
): DoseRequirements {
    const now = new Date();
    const due = new Date(now.getTime() - 10 * 60 * 1000);
    return {
        kind: 'scheduled',
        order: {
            id: 41,
            name: 'Losartan 50mg',
            dosage: '1 tablet',
            dose_amount: 1,
            dose_unit: 'tablet',
            route: 'Oral',
            form: 'tablet',
            instructions: 'Once a day with water',
            prescriber: 'Dr Lena Chen',
            controlled: false,
            high_risk: false,
            witness_required: false,
            is_prn: false,
            version: 1,
            verified: { at: now.toISOString(), by: 'Jordan Tipene' },
            awaiting_check: false,
            stock: null,
        },
        due: {
            due_at: due.toISOString(),
            window_opens_at: new Date(
                due.getTime() - 30 * 60 * 1000,
            ).toISOString(),
            window_closes_at: new Date(
                due.getTime() + 60 * 60 * 1000,
            ).toISOString(),
            before_minutes: 30,
            after_minutes: 60,
            state: 'due',
            late_minutes: 10,
        },
        block_all: null,
        block_given: null,
        competency: { state: 'current', message: null },
        second_person: {
            kind: null,
            rule_sentences: [],
            anyone_available: false,
            may_go_unconfirmed: true,
            candidates: [],
        },
        observations: [],
        observation_rule_sentences: [],
        allergy: {
            status: 'none_recorded',
            list: [],
            match: null,
            rule: 'warn',
        },
        covert: { state: 'none', plan: null, review_date: null },
        support: 'administer',
        variable: false,
        not_simple: [],
        person: {
            id: 201,
            preferred_name: 'Aroha',
            legal_name: 'Aroha Ngata',
            house: 'Kōwhai House',
            born: '1984-03-02',
            age: 42,
            nhi: 'ZZZ0016',
            photo_url: null,
        },
        who_can_give: [],
        house_lead: { id: 7, name: 'Jordan Tipene' },
        on_call: {
            configured: true,
            name: 'Rangi Parata',
            phone: '021 555 0142',
            warning: null,
        },
        prn: null,
        reoffer: null,
        options: {
            late_reasons: {
                out_or_asleep: 'Person was out or asleep at the time',
                other: 'Other',
            },
            amount_reasons: { part_taken: 'Only part taken', other: 'Other' },
            withheld_reasons: {
                fasting: 'Fasting',
                withheld: 'Safety concern — not safe to give',
                other: 'Other (say what happened)',
            },
            away_reasons: {
                absent: 'Out (day programme, appointment or with family)',
            },
            more_severities: ['minor', 'moderate', 'major', 'critical'],
        },
        checked_at: now.toISOString(),
        ...overrides,
    };
}

function open(
    req: DoseRequirements | BlockedRequirements,
    target?: DoseTarget,
) {
    getMock.mockResolvedValue({ data: req });
    return render(
        <RecordDoseDialog
            target={
                target ?? {
                    kind: 'scheduled',
                    orderId: 41,
                    scheduledFor: 'due' in req ? (req.due?.due_at ?? '') : '',
                }
            }
            entry="meds-today"
            signedAs={{ name: 'Priya Shah', role_label: 'Support worker' }}
            onClose={() => {}}
        />,
    );
}

function controlled(
    stockUnit: string,
    overrides: Partial<DoseRequirements['order']> = {},
): DoseRequirements {
    const base = requirements();
    return requirements({
        order: {
            ...base.order,
            name: 'Clonazepam 0.5mg',
            controlled: true,
            witness_required: true,
            dose_amount: 2,
            dose_unit: 'tablet',
            stock: { unit: stockUnit, from_order: stockUnit === 'tablets' },
            ...overrides,
        },
        second_person: {
            kind: 'witness',
            rule_sentences: [],
            anyone_available: true,
            may_go_unconfirmed: false,
            candidates: [{ id: 5, name: 'Mere Kahu', can_confirm: true }],
        },
    });
}

describe('RecordDoseDialog (P01)', () => {
    beforeEach(() => {
        getMock.mockReset();
        submitMock.mockReset();
    });

    it('walks safety checks, outcome and review, then records once', async () => {
        submitMock.mockResolvedValue({
            status: 'processed',
            data: { administration: { id: 9 } },
        });
        open(requirements());

        expect(await screen.findByText('Losartan 50mg')).toBeInTheDocument();
        expect(
            screen.getByText('No allergies recorded for Aroha'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));

        fireEvent.click(await screen.findByRole('button', { name: /^Given/ }));
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));

        expect(
            (await screen.findAllByText('Opened from')).length,
        ).toBeGreaterThan(0);
        fireEvent.click(
            screen.getByRole('button', { name: /^record outcome$/i }),
        );

        await waitFor(() => expect(submitMock).toHaveBeenCalledTimes(1));
        const [url, body] = submitMock.mock.calls[0] as [
            string,
            Record<string, unknown>,
        ];
        expect(url).toBe('/meds/today/record');
        expect(body).toMatchObject({
            client_medication_id: 41,
            status: 'given',
            amount_mode: 'as_ordered',
        });
        expect(await screen.findByText('Recorded')).toBeInTheDocument();
    });

    it('keeps "given" closed while a block applies but a refusal stays open', async () => {
        open(
            requirements({
                block_given: {
                    key: 'covertMissing',
                    facts: { review_date: '2026-03-01' },
                },
                covert: {
                    state: 'missing',
                    plan: null,
                    review_date: '2026-03-01',
                },
            }),
        );
        expect(
            await screen.findByText('No current covert plan'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));

        const given = await screen.findByRole('button', { name: /^Given/ });
        expect(given).toHaveAttribute('aria-disabled', 'true');
        const refused = screen.getByRole('button', { name: /^Refused/ });
        expect(refused).not.toHaveAttribute('aria-disabled');
    });

    it('asks for ordinary pack quantity in the stock unit when the ordered unit differs', async () => {
        const base = requirements();
        submitMock.mockResolvedValue({
            status: 'processed',
            data: { administration: { id: 9 } },
        });
        open(
            requirements({
                order: {
                    ...base.order,
                    dose_amount: 500,
                    dose_unit: 'mg',
                    stock: {
                        unit: 'tablets',
                        from_order: false,
                        tracked: true,
                    },
                },
            }),
        );
        await screen.findByText('Losartan 50mg');
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(await screen.findByRole('button', { name: /^Given/ }));
        expect(screen.getByText('Medicine stock')).toBeInTheDocument();
        expect(
            screen.getByText('In the stock’s unit: tablets.'),
        ).toBeInTheDocument();
        expect(screen.queryByLabelText(/Balance left/)).not.toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(submitMock).not.toHaveBeenCalled();
        fireEvent.change(
            screen.getByLabelText(/Given from stock for this dose/),
            { target: { value: '1.25' } },
        );
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(
            await screen.findByRole('button', { name: /^record outcome$/i }),
        );
        await waitFor(() => expect(submitMock).toHaveBeenCalledTimes(1));
        expect(submitMock.mock.calls[0][1]).toMatchObject({
            quantity_administered: 1.25,
        });
        expect(submitMock.mock.calls[0][1]).not.toHaveProperty('cd_balance');
    });

    it('records "Not confirmed by a second person" when a rule needs one and nobody can', async () => {
        submitMock.mockResolvedValue({
            status: 'processed',
            data: { administration: { id: 10 } },
        });
        open(
            requirements({
                second_person: {
                    kind: 'rule',
                    rule_sentences: [
                        'Before saving a dose of Insulin at All houses: a second person confirms with their witness PIN.',
                    ],
                    anyone_available: false,
                    may_go_unconfirmed: true,
                    candidates: [],
                },
            }),
        );
        await screen.findByText('Losartan 50mg');
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(await screen.findByRole('button', { name: /^Given/ }));
        expect(
            screen.getByText('Nobody else on shift can confirm this dose'),
        ).toBeInTheDocument();
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        const review = await screen.findByText('Second person');
        expect(
            within(review.closest('div')!.parentElement!).getByText(
                'Not confirmed by a second person',
            ),
        ).toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: /^record outcome$/i }),
        );
        await waitFor(() => expect(submitMock).toHaveBeenCalled());
        expect(
            (submitMock.mock.calls[0] as [string, Record<string, unknown>])[1],
        ).toMatchObject({ second_person_unavailable: true });
    });

    it('asks for a follow-up time on a refusal', async () => {
        open(requirements());
        await screen.findByText('Losartan 50mg');
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(
            await screen.findByRole('button', { name: /^Refused/ }),
        );
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(
            await screen.findByText(
                'Choose when to follow up — offer again or record why not.',
            ),
        ).toBeInTheDocument();
    });

    it('says "Already recorded" with who and when', async () => {
        submitMock.mockResolvedValue({
            status: 'duplicate',
            data: {
                replayed: false,
                duplicate_of: {
                    by: 'Daniel Ahn',
                    status: 'given',
                    administered_at: new Date().toISOString(),
                },
            },
        });
        open(requirements());
        await screen.findByText('Losartan 50mg');
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(await screen.findByRole('button', { name: /^Given/ }));
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(
            await screen.findByRole('button', { name: /^record outcome$/i }),
        );
        expect(
            await screen.findByText('Already recorded — nothing new was saved'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/Daniel Ahn recorded this dose as given/),
        ).toBeInTheDocument();
    });

    it('shows only why when nothing can be recorded, named from the row (P0-1)', async () => {
        open(
            {
                kind: 'scheduled',
                block_all: {
                    key: 'notClockedIn',
                    facts: { house: 'Kōwhai House' },
                },
                checked_at: new Date().toISOString(),
            },
            {
                kind: 'scheduled',
                orderId: 41,
                scheduledFor: '2026-04-30T09:30:00+12:00',
                label: { person: 'Aroha', medicine: 'Losartan 50mg' },
            },
        );
        expect(
            await screen.findByText('You’re not clocked in'),
        ).toBeInTheDocument();
        expect(
            screen.getByText('Why can’t I record this?'),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/^Aroha · Losartan 50mg · /),
        ).toBeInTheDocument();
        expect(screen.getByRole('link', { name: /clock in/i })).toHaveAttribute(
            'href',
            '/attendance',
        );
        // No wizard, no identity or allergy surfaces.
        expect(screen.queryByText(/allerg/i)).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: /continue/i }),
        ).not.toBeInTheDocument();
    });

    it('counts a smaller controlled dose out of the stock and records the rest as witnessed waste', async () => {
        submitMock.mockResolvedValue({
            status: 'processed',
            data: { administration: { id: 11 } },
        });
        open(controlled('tablets'));
        await screen.findByText('Clonazepam 0.5mg');
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(await screen.findByRole('button', { name: /^Given/ }));
        fireEvent.click(
            screen.getByRole('button', { name: /record a different amount/i }),
        );
        fireEvent.change(screen.getByLabelText('Amount given, in tablet'), {
            target: { value: '1' },
        });
        fireEvent.change(
            screen.getByLabelText(/Taken from the stock for this dose/),
            { target: { value: '2' } },
        );
        fireEvent.change(
            screen.getByLabelText(/Balance left after this dose/),
            { target: { value: '8' } },
        );
        expect(
            screen.getByText('1 tablet taken but not given'),
        ).toBeInTheDocument();

        // Taken can't be less than given.
        fireEvent.change(
            screen.getByLabelText(/Taken from the stock for this dose/),
            { target: { value: '0.5' } },
        );
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        expect(
            await screen.findByText(
                /More was given than was taken from the stock/,
            ),
        ).toBeInTheDocument();
    });

    it('doesn’t offer less than ordered on a controlled medicine counted in other units', async () => {
        open(controlled('mL', { dose_unit: 'mg', dose_amount: 5 }));
        await screen.findByText('Clonazepam 0.5mg');
        fireEvent.click(screen.getByRole('button', { name: /continue/i }));
        fireEvent.click(await screen.findByRole('button', { name: /^Given/ }));
        expect(
            screen.queryByRole('button', {
                name: /record a different amount/i,
            }),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/Controlled drugs › Loss/)).toBeInTheDocument();
        expect(
            screen.getByLabelText(/Taken from the stock for this dose/),
        ).toBeInTheDocument();
    });

    it('lists colleagues as can or can’t confirm, never why', async () => {
        open(
            requirements({
                order: {
                    ...requirements().order,
                    controlled: true,
                    witness_required: true,
                    stock: { unit: 'tablets', from_order: false },
                },
                block_given: { key: 'noWitness', facts: {} },
                second_person: {
                    kind: 'witness',
                    rule_sentences: [],
                    anyone_available: false,
                    may_go_unconfirmed: false,
                    candidates: [
                        { id: 5, name: 'Mere Kahu', can_confirm: false },
                    ],
                },
            }),
        );
        expect(await screen.findByText('Mere Kahu')).toBeInTheDocument();
        expect(
            screen.getByText('✕ Can’t confirm this dose'),
        ).toBeInTheDocument();
        expect(screen.getByText(/Rangi Parata/)).toBeInTheDocument();
    });
});

describe('P01 copy', () => {
    it('words blocks from the server facts', () => {
        const req = requirements();
        const label = { person: 'Aroha', medicine: 'Losartan 50mg' };
        expect(
            blockAllCopy(
                { key: 'notOnShift', facts: { house: 'Kōwhai House' } },
                label,
            ).title,
        ).toBe('Aroha isn’t on your shift');
        expect(
            blockAllCopy({ key: 'controlledNotAllowed', facts: {} }, label)
                .still,
        ).toBe('none');
        expect(
            blockAllCopy(
                {
                    key: 'prnLimit',
                    facts: { type: 'prn_limit', count_24h: 2, max_24h: 2 },
                },
                label,
            ).text,
        ).toContain('allows 2 doses in 24 hours');
        expect(blockCopy({ key: 'noWitness', facts: {} }, req).roster).toBe(
            true,
        );
        expect(
            blockCopy(
                {
                    key: 'allergyBlocked',
                    facts: { allergen: 'Penicillin', severity: 'severe' },
                },
                req,
            ).still,
        ).toBe('withheld-always');
        expect(competencyCopy('current', req)).toBeNull();
    });

    it('builds "who can give it" from the roster, or points to the coordinator', () => {
        const req = requirements({
            who_can_give: [
                { id: 1, name: 'Daniel Ahn' },
                { id: 2, name: 'Mere Kahu' },
                { id: 3, name: 'Jordan Tipene' },
            ],
        });
        expect(whoSentence(req)).toBe(
            '**Daniel Ahn**, **Mere Kahu** and **Jordan Tipene** (from the roster and who is clocked in).',
        );
        expect(whoSentence(requirements())).toContain('coordinator on call');
    });

    it('names the house’s on-call contact, or says it isn’t configured', () => {
        expect(onCallText(requirements())).toBe(
            '**Rangi Parata** (021 555 0142)',
        );
        expect(
            onCallText(
                requirements({
                    on_call: {
                        configured: false,
                        name: null,
                        phone: null,
                        warning: null,
                    },
                }),
            ),
        ).toBe('{NC}');
        expect(
            onCallText(
                requirements({
                    on_call: {
                        configured: true,
                        name: null,
                        phone: null,
                        warning: 'Nobody — Rangi Parata is on leave',
                    },
                }),
            ),
        ).toBe('Nobody — Rangi Parata is on leave');
    });

    it('formats amounts like the approved mockup', () => {
        expect(formatAmount(1.5, 'tablet')).toBe('1½ tablets');
        expect(formatAmount(1, 'tablet')).toBe('1 tablet');
        expect(formatAmount(500, 'mg')).toBe('500 mg');
    });
});
