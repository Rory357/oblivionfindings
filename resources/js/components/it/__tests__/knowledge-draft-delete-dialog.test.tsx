import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { KnowledgeDraftDeleteDialog } from '../knowledge-draft-delete-dialog';

const mocks = vi.hoisted(() => ({ delete: vi.fn() }));

vi.mock('@inertiajs/react', () => ({
    router: { delete: mocks.delete },
}));

vi.mock('sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

describe('KnowledgeDraftDeleteDialog', () => {
    beforeEach(() => mocks.delete.mockReset());

    it('requires a reason and sends governed draft deletion evidence', () => {
        render(
            <KnowledgeDraftDeleteDialog
                article={{ id: 42, title: 'Reset a password' }}
                open
                onOpenChange={vi.fn()}
            />,
        );

        expect(
            screen.getByText(/Only draft articles can be deleted/i),
        ).toBeVisible();
        const remove = screen.getByRole('button', { name: 'Delete draft' });
        expect(remove).toBeDisabled();

        fireEvent.change(
            screen.getByLabelText('Reason for deleting this draft'),
            {
                target: {
                    value: '  Duplicate draft created during authoring.  ',
                },
            },
        );
        fireEvent.click(remove);

        expect(mocks.delete).toHaveBeenCalledWith(
            '/it/kb/42',
            expect.objectContaining({
                data: { reason: 'Duplicate draft created during authoring.' },
                preserveScroll: true,
            }),
        );
    });
});
