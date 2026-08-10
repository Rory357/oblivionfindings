import { router } from '@inertiajs/react';
import { fireEvent, render, screen } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { ProvisioningCancelDialog } from '../provisioning-cancel-dialog';

vi.mock('@inertiajs/react', () => ({
    router: { post: vi.fn() },
}));

describe('ProvisioningCancelDialog', () => {
    it('explains the HR handover and requires an auditable reason', () => {
        const onOpenChange = vi.fn();
        render(
            <ProvisioningCancelDialog
                open
                onOpenChange={onOpenChange}
                request={{
                    id: 41,
                    item: 'New starter laptop',
                    from_onboarding: true,
                }}
            />,
        );

        expect(
            screen.getByText(/linked onboarding task remains open/i),
        ).toBeVisible();
        fireEvent.click(screen.getByRole('button', { name: 'Cancel request' }));
        expect(screen.getByRole('alert')).toHaveTextContent(
            'Add a reason before cancelling this request.',
        );
        expect(router.post).not.toHaveBeenCalled();

        fireEvent.change(screen.getByLabelText('Reason for cancelling'), {
            target: { value: 'Role changed before the employee started.' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Cancel request' }));

        expect(router.post).toHaveBeenCalledWith(
            '/it/provisioning/41/cancel',
            { reason: 'Role changed before the employee started.' },
            expect.objectContaining({ preserveScroll: true }),
        );
    });
});
