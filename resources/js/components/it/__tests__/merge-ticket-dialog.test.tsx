import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { MergeTicketDialog } from '../it-wizards';

const mocks = vi.hoisted(() => ({ post: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { post: mocks.post },
    useForm: vi.fn(),
}));

describe('merge ticket dialog', () => {
    it('requires a reviewed reason and submits only the selected private-audience target', () => {
        render(
            <MergeTicketDialog
                ticket={{
                    id: 41,
                    reference: 'IT-000041',
                    title: 'Cannot connect',
                }}
                targets={[
                    {
                        id: 42,
                        reference: 'IT-000042',
                        title: 'Same connection issue',
                        priority: 'high',
                        status: 'open',
                    },
                ]}
                onClose={vi.fn()}
            />,
        );

        expect(
            screen.getByText(
                /Only tickets for the same requester are available/i,
            ),
        ).toBeVisible();
        const merge = screen.getByRole('button', { name: 'Merge' });
        expect(merge).toBeDisabled();

        fireEvent.click(
            screen.getByRole('button', { name: /Same connection issue/i }),
        );
        expect(
            screen.getByRole('button', { name: /Merge into IT-000042/i }),
        ).toBeDisabled();

        fireEvent.change(
            screen.getByRole('textbox', { name: /Reason for merging/i }),
            {
                target: {
                    value: 'Duplicate report of the same connection issue',
                },
            },
        );
        fireEvent.click(
            screen.getByRole('button', { name: /Merge into IT-000042/i }),
        );

        expect(mocks.post).toHaveBeenCalledWith(
            '/it/tickets/41/merge',
            {
                target_ticket_id: 42,
                reason: 'Duplicate report of the same connection issue',
            },
            expect.objectContaining({ onSuccess: expect.any(Function) }),
        );
    });
});
