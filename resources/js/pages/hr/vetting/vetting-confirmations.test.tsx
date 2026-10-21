import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import VettingShow from './show';

// Mark cleared, Request renewal and Delete record used browser confirm().
// Each now opens the shared ConfirmDialog and only its confirm button sends
// the request.

type VisitOptions = { onStart?: () => void; onFinish?: () => void };

const router = vi.hoisted(() => {
    const settle = (options?: VisitOptions) => {
        options?.onStart?.();
        options?.onFinish?.();
    };
    return {
        post: vi.fn((_url: string, _data: unknown, options?: VisitOptions) =>
            settle(options),
        ),
        delete: vi.fn((_url: string, options?: VisitOptions) =>
            settle(options),
        ),
    };
});

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: ({ actions }: { actions?: ReactNode }) => <>{actions}</>,
    PageLayout: ({
        hero,
        children,
    }: PropsWithChildren<{ hero?: ReactNode }>) => (
        <>
            {hero}
            {children}
        </>
    ),
}));

const baseCheck = {
    id: 14,
    check_type: 'police_vet',
    status: 'pending',
    reference_number: null,
    check_date: null,
    issue_date: null,
    expires_at: null,
    verified_at: null,
    created_at: '2026-09-01T00:00:00Z',
    notes: null,
    disclosures_present: false,
    disclosure_details: null,
    risk_decision: null,
    user: { id: 4, name: 'Mere Tane' },
};

const dialog = () => screen.getByRole('alertdialog');

beforeEach(() => {
    router.post.mockClear();
    router.delete.mockClear();
});

describe('HR › Vetting: mark cleared', () => {
    it('asks first (default tone), and Cancel sends nothing', async () => {
        render(<VettingShow check={baseCheck} can={{ manage: true }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Mark Cleared' }));

        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Mark this check as cleared?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent(
            'The police vet for Mere Tane is recorded as clear',
        );
        // Default (primary) tone, not destructive.
        expect(
            within(dialog()).getByRole('button', { name: 'Mark cleared' }),
        ).toHaveClass('btn-soft-primary');

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('marks the check cleared once confirmed', () => {
        render(<VettingShow check={baseCheck} can={{ manage: true }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Mark Cleared' }));
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Mark cleared' }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/hr/compliance/vetting/14/clear',
            {},
            expect.anything(),
        );
    });
});

describe('HR › Vetting: request renewal', () => {
    const expired = {
        ...baseCheck,
        status: 'clear',
        expires_at: '2025-01-01',
    };

    it('asks first and says no request is sent to anyone', () => {
        render(<VettingShow check={expired} can={{ manage: true }} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Request Renewal' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Request a renewal for this check?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent('No request is sent to anyone');
        expect(
            within(dialog()).getByRole('button', { name: 'Request renewal' }),
        ).toHaveClass('btn-soft-primary');

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );
        expect(router.post).not.toHaveBeenCalled();
    });

    it('marks the check for renewal once confirmed', () => {
        render(<VettingShow check={expired} can={{ manage: true }} />);
        fireEvent.click(
            screen.getByRole('button', { name: 'Request Renewal' }),
        );
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Request renewal' }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/hr/compliance/vetting/14/renew',
            {},
            expect.anything(),
        );
    });
});

describe('HR › Vetting: delete record', () => {
    it('asks first with a destructive button, and Cancel deletes nothing', () => {
        render(<VettingShow check={baseCheck} can={{ manage: true }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete Record' }));

        expect(router.delete).not.toHaveBeenCalled();
        expect(
            within(dialog()).getByText('Delete this vetting record?'),
        ).toBeInTheDocument();
        expect(dialog()).toHaveTextContent("This can't be undone.");
        expect(
            within(dialog()).getByRole('button', { name: 'Delete record' }),
        ).toHaveClass('bg-destructive');

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );
        expect(router.delete).not.toHaveBeenCalled();
    });

    it('deletes the record once confirmed', async () => {
        render(<VettingShow check={baseCheck} can={{ manage: true }} />);
        fireEvent.click(screen.getByRole('button', { name: 'Delete Record' }));
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Delete record' }),
        );

        expect(router.delete).toHaveBeenCalledTimes(1);
        expect(router.delete).toHaveBeenCalledWith(
            '/hr/compliance/vetting/14',
            expect.anything(),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });
});
