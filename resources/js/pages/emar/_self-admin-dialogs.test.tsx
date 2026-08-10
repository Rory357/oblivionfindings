import { cleanup, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';

// ViewSelfAdminDialog's only Inertia touchpoint is router.visit (the View-client jump).
const visit = vi.fn();
vi.mock('@inertiajs/react', () => ({
    router: {
        visit: (...args: unknown[]) => visit(...args),
        post: vi.fn(),
        put: vi.fn(),
    },
}));

import { ViewSelfAdminDialog, type SelfAdminRow } from './_self-admin-dialogs';

const baseRow: SelfAdminRow = {
    id: 42,
    client_id: 7,
    client_name: 'Ada Lovelace',
    nhi: 'ABC1234',
    site_name: 'Maple House',
    status: 'completed',
    outcome: 'independent',
    outcome_label: 'Cat 1 · Independent',
    wishes_to_self_administer: true,
    people_involved: ['Person', 'Pharmacist'],
    cognitive_capacity: 5,
    physical_dexterity: 4,
    vision_ability: 5,
    swallowing_ability: 4,
    understanding_score: 5,
    total_score: 23,
    can_identify_medications: true,
    can_read_labels: true,
    can_open_packaging: true,
    can_manage_timing: false,
    can_store_safely: true,
    willing_to_self_admin: true,
    risk_factors: null,
    support_needed: null,
    support_adjustments: ['Large-print labels'],
    safe_storage_notes: 'Lockable bedside drawer',
    storage_location: 'lockable_drawer',
    assessor_notes: null,
    assessor_name: 'Nurse Joy',
    assessment_date: '2026-06-01',
    reassessment_date: '2026-12-01',
    reassessment_interval_months: 6,
    reassessment_trigger: null,
    reassessment_due: false,
    med_scope: [{ med_id: 10, med_name: 'Paracetamol', scope: 'self_managed' }],
    ordering_responsibility: 'self',
    agreement_responsibilities: null,
    agreement_signed_at: null,
    agreement_signed_by_name: null,
    client_medications: [
        {
            id: 10,
            name: 'Paracetamol',
            dosage: '500mg',
            controlled: false,
            scope: 'self_managed',
        },
    ],
};

afterEach(() => {
    cleanup();
    visit.mockClear();
});

describe('ViewSelfAdminDialog', () => {
    const noop = () => {};

    it('renders the Options bar and fires the wizard action callbacks in place', () => {
        const onReassess = vi.fn();
        const onSignAgreement = vi.fn();
        const onSetScope = vi.fn();
        render(
            <ViewSelfAdminDialog
                assessment={baseRow}
                onClose={noop}
                onReassess={onReassess}
                onSignAgreement={onSignAgreement}
                onSetScope={onSetScope}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: /Reassess/i }));
        fireEvent.click(
            screen.getByRole('button', { name: /Sign agreement/i }),
        );
        fireEvent.click(screen.getByRole('button', { name: /Set scope/i }));
        expect(onReassess).toHaveBeenCalledTimes(1);
        expect(onSignAgreement).toHaveBeenCalledTimes(1);
        expect(onSetScope).toHaveBeenCalledTimes(1);
    });

    it('jumps to the client profile MAR tab from the Options bar', () => {
        render(
            <ViewSelfAdminDialog
                assessment={baseRow}
                onClose={noop}
                onReassess={noop}
                onSignAgreement={noop}
                onSetScope={noop}
            />,
        );
        fireEvent.click(screen.getByRole('button', { name: /^Client$/i }));
        expect(visit).toHaveBeenCalledWith('/operations/clients/7?tab=mar');
    });

    it('surfaces capacity sub-scores, capability checks and per-medication scope', () => {
        render(
            <ViewSelfAdminDialog
                assessment={baseRow}
                onClose={noop}
                onReassess={noop}
                onSignAgreement={noop}
                onSetScope={noop}
            />,
        );
        // Capacity sub-scores section
        expect(screen.getByText('Cognitive capacity')).toBeTruthy();
        expect(screen.getByText('Swallowing')).toBeTruthy();
        // Capability checks (one failing check still listed)
        expect(screen.getByText('Identify medicines')).toBeTruthy();
        expect(screen.getByText('Manage timing')).toBeTruthy();
        // Per-medication scope
        expect(screen.getByText('Paracetamol')).toBeTruthy();
        expect(screen.getByText('Self-managed')).toBeTruthy();
    });

    it('hides Sign agreement and shows the signed banner once the agreement is signed', () => {
        const signed: SelfAdminRow = {
            ...baseRow,
            agreement_signed_at: '2026-06-02T09:00:00+12:00',
            agreement_signed_by_name: 'Nurse Joy',
        };
        render(
            <ViewSelfAdminDialog
                assessment={signed}
                onClose={noop}
                onReassess={noop}
                onSignAgreement={noop}
                onSetScope={noop}
            />,
        );
        expect(
            screen.queryByRole('button', { name: /Sign agreement/i }),
        ).toBeNull();
        expect(screen.getByText(/Agreement signed by Nurse Joy/i)).toBeTruthy();
    });
});
