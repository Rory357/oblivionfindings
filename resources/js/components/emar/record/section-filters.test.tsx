import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { PrnStrip } from '@/components/clients/profile/mar-day/prn-strip';
import type { DayPrnMedication } from '@/components/clients/profile/mar-day/types';
import { MedicationSupportPanel } from '@/pages/emar/support/support-panel';
import type { SupportPlan } from '@/pages/emar/support/types';

import { ClinicalSection } from './clinical';
import { MedicinesSection } from './reading';
import { SafetySection } from './safety';
import type { RecordMedicine } from './types';

vi.mock('@/pages/emar/components/mar-governance-dialogs', () => ({
    ManageAlertsDialog: () => null,
    SyringeDriverDialog: () => null,
}));

afterEach(() => {
    cleanup();
    vi.unstubAllGlobals();
});

function serve(body: unknown) {
    vi.stubGlobal(
        'fetch',
        vi.fn().mockResolvedValue({ ok: true, json: async () => body }),
    );
}

const medicine = (overrides: Partial<RecordMedicine>): RecordMedicine => ({
    key: String(overrides.id ?? 1),
    id: 1,
    name: 'Metformin',
    strength: '500 mg',
    amount: '1 tablet',
    route: 'Oral',
    form: 'Tablet',
    kind: 'scheduled',
    when: '08:00',
    support: 'administer',
    status: 'active',
    controlled: false,
    witness: false,
    high_risk: false,
    started: null,
    verified: null,
    stopped: null,
    ...overrides,
});

const rows = [
    medicine({ id: 1, name: 'Metformin' }),
    medicine({ id: 2, name: 'Paracetamol', kind: 'prn', when: 'As needed' }),
    medicine({ id: 3, name: 'Warfarin', status: 'awaiting' }),
];

describe('record section filters (EA-109)', () => {
    it('filters Medicines by type', async () => {
        serve({ rows, hidden: 0 });
        render(
            <MedicinesSection
                clientId={7}
                stopped={false}
                search=""
                type="prn"
                onMedicine={vi.fn()}
            />,
        );
        expect(
            (await screen.findAllByText(/Paracetamol/)).length,
        ).toBeGreaterThan(0);
        expect(screen.queryByText(/Metformin/)).not.toBeInTheDocument();
        expect(screen.getByText(/1 of 3 shown/)).toBeInTheDocument();
    });

    it('shows only medicines that need attention', async () => {
        serve({ rows, hidden: 0 });
        render(
            <MedicinesSection
                clientId={7}
                stopped={false}
                search=""
                attention
                onMedicine={vi.fn()}
            />,
        );
        expect((await screen.findAllByText(/Warfarin/)).length).toBeGreaterThan(
            0,
        );
        expect(screen.queryByText(/Paracetamol/)).not.toBeInTheDocument();
    });

    it('leaves resolved chart alerts out until asked for', async () => {
        const alert = {
            id: 1,
            key: 'a1',
            detail: null,
            type: 'general',
            enabled: true,
            prompt_on_open: false,
            created_at: '2026-10-01T00:00:00Z',
            created_by: 'Lead',
            resolved_by: null,
        };
        serve({
            alerts: [
                { ...alert, title: 'Thicken fluids', resolved_at: null },
                {
                    ...alert,
                    id: 2,
                    key: 'a2',
                    title: 'Old swallowing alert',
                    resolved_at: '2026-10-02T00:00:00Z',
                },
            ],
            interactions: { rows: [], hidden: 0 },
            suppression: { suppressed: false, reason: null },
            can_manage: false,
        });
        const { rerender } = render(
            <SafetySection clientId={7} view="alerts" />,
        );
        expect(await screen.findByText('Thicken fluids')).toBeInTheDocument();
        expect(
            screen.queryByText('Old swallowing alert'),
        ).not.toBeInTheDocument();
        expect(screen.getByText(/1 resolved alert not/)).toBeInTheDocument();
        rerender(<SafetySection clientId={7} view="alerts" showResolved />);
        expect(
            await screen.findByText('Old swallowing alert'),
        ).toBeInTheDocument();
    });
});

const prn = (overrides: Partial<DayPrnMedication>): DayPrnMedication => ({
    id: 1,
    client_id: 7,
    client_name: 'Aroha',
    name: 'Paracetamol',
    dose: '1 g',
    route: 'Oral',
    form: 'Tablet',
    instructions: null,
    prn_reason: null,
    max_per_day: 4,
    given_last_24h: 1,
    remaining_today: 3,
    near_limit: false,
    over_limit: false,
    is_controlled: false,
    requires_witness: false,
    min_hours_between: null,
    last_given_at: null,
    last_given_label: null,
    next_allowed_at: null,
    next_allowed_label: null,
    interval_blocked: false,
    given_on_day: 1,
    last_given_on_day: null,
    is_today: true,
    ...overrides,
});

const supportMedicine = (
    overrides: Partial<SupportPlan['medicines'][number]>,
): SupportPlan['medicines'][number] => ({
    id: 1,
    name: 'Metformin',
    dosage: '500 mg',
    controlled: false,
    mode: 'staff_given',
    requested_mode: 'staff_given',
    agreement_needed: false,
    is_prn: false,
    ...overrides,
});

const plan: SupportPlan = {
    client_id: 7,
    client_name: 'Aroha',
    site_id: 1,
    site_name: 'Kowhai House',
    state: 'current',
    cap: 'self_managed',
    assessment: null,
    medicines: [
        supportMedicine({ id: 1, name: 'Metformin' }),
        supportMedicine({
            id: 2,
            name: 'Salbutamol inhaler',
            mode: 'prompted',
            requested_mode: 'prompted',
        }),
        supportMedicine({
            id: 3,
            name: 'Vitamin D',
            mode: 'self_managed',
            requested_mode: 'self_managed',
        }),
    ],
    concealed_count: 0,
    agreement: null,
    agreement_needed: false,
    reviews: [],
    can_assess: false,
    can_set_controlled: false,
    can_record_consent: false,
    url: '/emar/support/7',
};

describe('record section filters, remaining P02 set (Lane C9f)', () => {
    it('shows only as-needed medicines at their limit when asked', () => {
        const rows = [
            prn({ id: 1, name: 'Paracetamol' }),
            prn({
                id: 2,
                name: 'Ibuprofen',
                max_per_day: 3,
                given_last_24h: 3,
                remaining_today: 0,
                over_limit: true,
            }),
        ];
        const props = {
            hidden: 0,
            recordBlocked: null,
            canRecordControlled: true,
            onRecord: vi.fn(),
        };
        const { rerender } = render(
            <PrnStrip rows={rows} {...props} limitReachedOnly />,
        );
        expect(screen.getByText('Ibuprofen')).toBeInTheDocument();
        expect(screen.getByText('Limit reached')).toBeInTheDocument();
        expect(screen.queryByText('Paracetamol')).not.toBeInTheDocument();

        rerender(<PrnStrip rows={[rows[0]]} {...props} limitReachedOnly />);
        expect(
            screen.getByText('No as-needed medicine is at its limit'),
        ).toBeInTheDocument();

        rerender(<PrnStrip rows={rows} {...props} />);
        expect(screen.getByText('Paracetamol')).toBeInTheDocument();
    });

    it('filters Support by medicine by support level', () => {
        const { rerender } = render(
            <MedicationSupportPanel plan={plan} mode="prompted" />,
        );
        expect(
            screen.getAllByText('Salbutamol inhaler').length,
        ).toBeGreaterThan(0);
        expect(screen.queryByText('Metformin')).not.toBeInTheDocument();
        expect(screen.getByText(/1 of 3 shown/)).toBeInTheDocument();

        rerender(<MedicationSupportPanel plan={plan} mode="assisted" />);
        expect(
            screen.getByText('No medicines with Assist support'),
        ).toBeInTheDocument();

        rerender(<MedicationSupportPanel plan={plan} />);
        expect(screen.getAllByText('Metformin').length).toBeGreaterThan(0);
        expect(screen.getByText(/^3 shown/)).toBeInTheDocument();
    });

    it('asks the server for the Clinical range and says when nothing is in it', async () => {
        serve({
            inr: { rows: [], hidden: 0 },
            drivers: [],
            observations: { rows: [], hidden: 0 },
            medicines: [],
            witnesses: [],
            can_manage: false,
            can_check: false,
        });
        render(<ClinicalSection clientId={7} view="inr" range="3m" />);
        expect(
            await screen.findByText(
                /No visible INR result in the last 3 months/,
            ),
        ).toBeInTheDocument();
        expect(String(vi.mocked(fetch).mock.calls[0][0])).toContain(
            '/emar/clients/7/record/clinical?range=3m',
        );
    });
});

describe('medicine names on the record (1440 walk)', () => {
    const losartan = medicine({
        id: 9,
        name: 'Losartan 50 mg tablet',
        strength: '1 tablet (50 mg)',
        amount: '1 tablet',
    });

    it('names a medicine without its prescribed dose text', async () => {
        serve({ rows: [losartan], hidden: 0 });
        render(
            <MedicinesSection
                clientId={7}
                stopped={false}
                search=""
                onMedicine={vi.fn()}
            />,
        );
        expect(
            (await screen.findAllByText('Losartan 50 mg tablet')).length,
        ).toBeGreaterThan(0);
        expect(screen.queryByText(/Losartan 50 mg tablet 1 tablet/)).toBeNull();
    });

    it('still finds a medicine by its prescribed dose text', async () => {
        serve({ rows: [losartan], hidden: 0 });
        render(
            <MedicinesSection
                clientId={7}
                stopped={false}
                search="(50 mg)"
                onMedicine={vi.fn()}
            />,
        );
        expect(
            (await screen.findAllByText('Losartan 50 mg tablet')).length,
        ).toBeGreaterThan(0);
    });
});
