import { cleanup, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

import { MedicinesSection } from './reading';
import { SafetySection } from './safety';
import type { RecordMedicine } from './types';

vi.mock('@/pages/emar/components/mar-governance-dialogs', () => ({
    ManageAlertsDialog: () => null,
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
