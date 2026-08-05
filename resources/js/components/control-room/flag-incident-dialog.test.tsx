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
            const [data, setDataState] = ReactActual.useState({
                ...initial,
                client_id: '10',
                type: 'fall',
            });
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
                post: (
                    url: string,
                    options?: {
                        onSuccess?: (page: {
                            props: { flash: Record<string, unknown> };
                        }) => void;
                    },
                ) => {
                    inertia.post(
                        url,
                        transformRef.current
                            ? transformRef.current(data)
                            : data,
                    );
                    options?.onSuccess?.({ props: { flash: {} } });
                },
                reset: vi.fn(),
                clearErrors: vi.fn(),
            };

            return form;
        },
    };
});

import { FlagIncidentDialog } from './flag-incident-dialog';

describe('FlagIncidentDialog immediate controls', () => {
    beforeEach(() => {
        inertia.post.mockReset();
    });

    it('requires immediate action for the default high-severity quick flag and submits the recorded truth', async () => {
        render(
            <FlagIncidentDialog
                open
                onClose={vi.fn()}
                clients={[{ id: 10, name: 'Aroha Rangi' }]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));

        const continueButton = screen.getByRole('button', {
            name: 'Next',
        });
        expect(continueButton).toBeDisabled();
        expect(
            screen.getByText(/No immediate control was possible/),
        ).toBeVisible();

        fireEvent.change(screen.getByLabelText('Immediate action taken *'), {
            target: { value: 'Area isolated and RN called.' },
        });
        expect(continueButton).toBeEnabled();
        fireEvent.click(continueButton);
        fireEvent.click(screen.getByRole('button', { name: 'Flag incident' }));

        await waitFor(() =>
            expect(inertia.post).toHaveBeenCalledWith(
                '/control-room/incidents/flag',
                expect.objectContaining({
                    client_id: 10,
                    severity: 'high',
                    immediate_action_taken: 'Area isolated and RN called.',
                }),
            ),
        );
    });

    it('keeps low-severity quick flags valid without immediate action', () => {
        render(
            <FlagIncidentDialog
                open
                onClose={vi.fn()}
                clients={[{ id: 10, name: 'Aroha Rangi' }]}
            />,
        );

        fireEvent.click(screen.getByRole('button', { name: 'Next' }));
        fireEvent.click(screen.getByRole('button', { name: 'Low' }));

        expect(screen.getByRole('button', { name: 'Next' })).toBeEnabled();
    });
});
