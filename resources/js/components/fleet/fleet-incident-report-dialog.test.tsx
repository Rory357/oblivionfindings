import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    post: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const ReactActual = await vi.importActual<typeof import('react')>('react');

    return {
        usePage: () => ({ props: { flash: {} } }),
        useForm: (initial: Record<string, unknown>) => {
            const [data, setDataState] = ReactActual.useState(initial);
            const transformRef = ReactActual.useRef<
                | ((data: Record<string, unknown>) => Record<string, unknown>)
                | null
            >(null);

            const form = {
                data,
                errors: {},
                processing: false,
                setData: (key: string, value: unknown) =>
                    setDataState((current) => ({
                        ...current,
                        [key]: value,
                    })),
                transform: (
                    callback: (
                        data: Record<string, unknown>,
                    ) => Record<string, unknown>,
                ) => {
                    transformRef.current = callback;
                    return form;
                },
                post: (url: string) => {
                    inertia.post(
                        url,
                        transformRef.current
                            ? transformRef.current(data)
                            : data,
                    );
                },
                reset: vi.fn(),
                clearErrors: vi.fn(),
            };

            return form;
        },
    };
});

import { FleetIncidentReportDialog } from './fleet-incident-report-dialog';

const formOptions = {
    assets: [
        {
            id: 10,
            name: 'Community van',
            registration_number: 'ABC123',
            category: 'vehicle',
        },
    ],
    users: [],
    sites: [],
    types: [],
    severities: [],
    injury_severities: [],
    damage_classifications: [],
};

describe('FleetIncidentReportDialog serious-incident truth', () => {
    beforeEach(() => {
        inertia.post.mockReset();
    });

    it('requires and submits the operators exact immediate action for a major report', async () => {
        render(
            <FleetIncidentReportDialog
                open
                onClose={vi.fn()}
                mode="vehicle"
                formOptions={formOptions}
                initialAssetId={10}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.change(screen.getByPlaceholderText('What happened?'), {
            target: { value: 'The van struck a roadside barrier.' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Major/ }));

        const nextButton = screen.getByRole('button', { name: 'Next' });
        expect(nextButton).toBeDisabled();
        expect(
            screen.getByText(/If no control was possible, say that explicitly/),
        ).toBeVisible();

        const immediateAction =
            'Stopped the van, checked every passenger, and called emergency services.';
        fireEvent.change(
            screen.getByPlaceholderText(
                'What did you do immediately to protect the people involved?',
            ),
            { target: { value: immediateAction } },
        );
        expect(nextButton).toBeEnabled();

        fireEvent.click(
            screen.getByRole('button', { name: /Review & submit/ }),
        );
        expect(screen.getByText(immediateAction)).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Submit incident' }),
        );

        await waitFor(() =>
            expect(inertia.post).toHaveBeenCalledWith(
                '/fleet-assets/incidents',
                expect.objectContaining({
                    severity: 'major',
                    immediate_action_taken: immediateAction,
                }),
            ),
        );
    });
});
