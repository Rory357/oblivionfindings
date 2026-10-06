import { fireEvent, render, screen } from '@testing-library/react';
import type { ComponentProps } from 'react';
import { beforeEach, expect, it, vi } from 'vitest';
import ClinicalPortal from './ClinicalPortal';
vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children, ...props }: any) => <a {...props}>{children}</a>,
    router: { reload: vi.fn(), get: vi.fn() },
    usePage: () => ({ props: { auth: { user: {} } } }),
}));
vi.mock('@/layouts/app-layout', () => ({ default: () => null }));
const medicine = {
    id: 4,
    version: 1,
    state: 'active',
    approval_status: 'verified',
    name: 'Fictional PRN',
    dosage: '1 tablet',
    dose_amount: 1,
    dose_unit: 'tablet',
    frequency: 'As needed',
    frequency_code: null,
    dose_times: null,
    is_prn: true,
    route: 'oral',
    form: 'tablet',
    instructions: 'Fictional evidence',
    indication: 'Synthetic test',
    prn_reason: 'Synthetic test',
    max_per_day: 4,
    min_hours_between_doses: 0,
    start_date: '2026-10-07T00:00:00.000000Z',
    end_date: '2026-10-09T00:00:00.000000Z',
    prescriber: 'Fictional doctor',
    pharmacy: null,
    controlled_drug: false,
    high_risk: false,
    witness_required: false,
};
const props: ComponentProps<typeof ClinicalPortal> = {
    clinician: {
        name: 'Fictional doctor',
        provider_name: 'Fictional practice',
        registration_authority: 'Test',
        registration_number: 'DEMO',
        expires_at: '2026-11-01T00:00:00Z',
    },
    people: [
        {
            id: 7,
            name: 'Fictional person',
            date_of_birth: '1980-01-01',
            expires_at: '2026-11-01T00:00:00Z',
            can_propose: true,
            include_controlled: false,
        },
    ],
    selected_client: {
        id: 7,
        name: 'Fictional person',
        date_of_birth: '1980-01-01',
        medications: [medicine],
        allergies: [],
    },
    proposals: [],
};
beforeEach(() => window.history.replaceState({}, '', '/clinical-portal'));
it('opens a PRN chart with null times and permits a valid zero-hour interval for review', () => {
    render(<ClinicalPortal {...props} />);
    expect(screen.getByText('Fictional PRN')).toBeInTheDocument();
    fireEvent.click(screen.getByRole('button', { name: 'Change' }));
    expect(
        screen.getByRole('button', { name: /Start date: 7 Oct 2026/ }),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Continue' })).toBeEnabled();
});
it('requires an evidenced start date when the existing record has no start boundary', () => {
    render(
        <ClinicalPortal
            {...props}
            selected_client={{
                ...props.selected_client!,
                medications: [{ ...medicine, start_date: null }],
            }}
        />,
    );
    fireEvent.click(screen.getByRole('button', { name: 'Change' }));
    expect(
        screen.getByRole('button', { name: 'Start date: Choose date' }),
    ).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Continue' })).toBeDisabled();
});

it('shows permitted request history without requiring an unrelated chart selection', () => {
    render(
        <ClinicalPortal
            {...props}
            selected_client={null}
            proposals={[
                {
                    id: 1,
                    client_id: 7,
                    client_name: 'Fictional person',
                    clinician_name: 'Fictional doctor',
                    kind: 'stop',
                    medication_id: 4,
                    expected_version: 1,
                    prescription: null,
                    reason: 'Fictional request waiting for review',
                    status: 'submitted',
                    submitted_at: '2026-10-07T01:00:00Z',
                    revision_id: null,
                    decision_note: null,
                    has_source_file: false,
                },
            ]}
        />,
    );
    fireEvent.click(screen.getByRole('tab', { name: 'My requests' }));
    expect(screen.getByText('Awaiting review')).toBeInTheDocument();
    expect(
        screen.getByText(
            /Fictional person.*Fictional request waiting for review/,
        ),
    ).toBeInTheDocument();
});
