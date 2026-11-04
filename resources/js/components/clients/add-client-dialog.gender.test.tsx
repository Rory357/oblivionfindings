import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';

import { AddClientDialog } from '@/components/clients/add-client-dialog';

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');

    return {
        router: { put: vi.fn(), reload: vi.fn() },
        usePage: () => ({ props: { flash: {} } }),
        useForm: <T,>(initial: T) => {
            const [data, setState] = React.useState(initial);
            return {
                data,
                setData: (key: keyof T | ((prev: T) => T), value?: unknown) =>
                    typeof key === 'function'
                        ? setState(key)
                        : setState((current) => ({ ...current, [key]: value })),
                processing: false,
                errors: {},
                post: vi.fn(),
                transform: vi.fn(),
                reset: vi.fn(),
                clearErrors: vi.fn(),
            };
        },
    };
});

const sites = [{ id: 1, name: 'Kōwhai House' }];
const serviceContexts = [{ id: 2, name: 'Supported living' }];

function renderWizard(initialValues: Record<string, unknown>) {
    return render(
        <AddClientDialog
            isOpen
            onClose={vi.fn()}
            sites={sites}
            serviceContexts={serviceContexts}
            keyWorkers={[]}
            geofences={[]}
            initialValues={{
                first_name: 'Aroha',
                last_name: 'Test',
                date_of_birth: '1990-01-15',
                site_id: '1',
                service_context_id: '2',
                ...initialValues,
            }}
        />,
    );
}

describe('Add client gender', () => {
    it('is a dropdown, with a describe box only for Another gender', () => {
        renderWizard({ gender: 'female' });

        expect(
            screen.getByRole('combobox', { name: 'Select gender' }),
        ).toBeInTheDocument();
        expect(
            screen.queryByLabelText(/Describe their gender/),
        ).not.toBeInTheDocument();
    });

    it('needs their own words before moving on from Another gender', () => {
        renderWizard({ gender: 'another_gender', gender_description: '' });

        expect(screen.getByLabelText(/Describe their gender/)).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));

        expect(
            screen.getByText(
                'Describe their gender in their own words, or choose another option',
            ),
        ).toBeInTheDocument();
    });

    it('shows the old typed answer until an option is chosen', () => {
        renderWizard({ gender: '', gender_recorded_as: 'see notes' });

        expect(
            screen.getByText(
                'Gender was recorded as ‘see notes’. Please choose an option.',
            ),
        ).toBeInTheDocument();
    });
});
