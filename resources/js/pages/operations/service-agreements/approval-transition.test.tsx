import { fireEvent, render, screen } from '@testing-library/react';
import { beforeEach, expect, it, vi } from 'vitest';
import { TransitionDialog } from './Show';

const { post } = vi.hoisted(() => ({ post: vi.fn() }));
vi.mock('@inertiajs/react', () => ({ router: { post } }));
vi.mock('@/layouts/app-layout', () => ({ default: () => null }));
beforeEach(() => post.mockClear());

it('submits for independent approval through the endpoint that preserves submission provenance', () => {
    render(
        <TransitionDialog
            open
            onOpenChange={vi.fn()}
            agreementId={7}
            transition={{
                label: 'Submit for Approval',
                toStatus: 'pending_approval',
                icon: null,
                variant: 'default',
            }}
        />,
    );
    expect(
        screen.getByText(/The submitter cannot approve their own submission/),
    ).toBeInTheDocument();
    expect(
        screen.queryByRole('textbox', { name: 'Notes' }),
    ).not.toBeInTheDocument();
    fireEvent.click(
        screen.getByRole('button', { name: 'Submit for Approval' }),
    );
    expect(post).toHaveBeenCalledWith(
        '/operations/service-agreements/7/submit-for-approval',
        {},
        expect.any(Object),
    );
});

it('retains the reason and notes for ordinary agreement transitions', () => {
    render(
        <TransitionDialog
            open
            onOpenChange={vi.fn()}
            agreementId={7}
            transition={{
                label: 'Suspend',
                toStatus: 'suspended',
                icon: null,
                variant: 'outline',
            }}
        />,
    );
    fireEvent.change(screen.getByRole('textbox', { name: 'Reason' }), {
        target: { value: 'The person requested a review.' },
    });
    fireEvent.change(screen.getByRole('textbox', { name: 'Notes' }), {
        target: { value: 'Coordinator will follow up tomorrow.' },
    });
    fireEvent.click(screen.getByRole('button', { name: 'Suspend' }));
    expect(post).toHaveBeenCalledWith(
        '/operations/service-agreements/7/transition',
        {
            status: 'suspended',
            reason: 'The person requested a review.',
            notes: 'Coordinator will follow up tomorrow.',
        },
        expect.any(Object),
    );
});
