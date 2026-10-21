import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CandidateShow from './show';

// Declining an offer for changes used a browser prompt() for the reason. It
// now opens a dialog with an optional reason, and only its "Decline offer"
// button sends the request.

type VisitOptions = {
    onStart?: () => void;
    onFinish?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
};

const router = vi.hoisted(() => ({
    post: vi.fn(),
    put: vi.fn(),
    delete: vi.fn(),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    usePage: () => ({ props: { flash: {} } }),
    useForm: <T extends object>(data: T) => ({
        data,
        setData: vi.fn(),
        post: vi.fn(),
        reset: vi.fn(),
        processing: false,
        errors: {},
    }),
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: () => null,
}));
vi.mock('@/components/hr', () => ({
    OfferRespondDialog: () => null,
    OfferWizardDialog: () => null,
}));

const settle = (_url: string, _data: unknown, options?: VisitOptions) => {
    options?.onStart?.();
    options?.onSuccess?.();
    options?.onFinish?.();
};

const candidate = {
    id: 12,
    first_name: 'Aroha',
    last_name: 'Ngata',
    preferred_name: null,
    personal_email: 'aroha@example.test',
    personal_phone: null,
    source: 'website',
    source_detail: null,
    tags: [],
    notes: null,
    created_at: '2026-09-01T00:00:00Z',
    applications: [
        {
            id: 30,
            position_title: 'Support worker',
            position_role: null,
            stage: 'offer',
            status: 'offered' as const,
            interview_kit: null,
            applied_at: '2026-09-02T00:00:00Z',
            target_site: null,
            interviews: [],
            reference_checks: [],
            job_posting: null,
            cover_letter: null,
            screening_answers: null,
            cv_original_name: null,
            offer: {
                id: 77,
                position_title: 'Support worker',
                position_role: null,
                employment_type: 'part_time',
                proposed_start_date: '2026-11-02',
                hours_per_week: 30,
                hourly_rate: 28.5,
                annual_salary: null,
                approval_status: 'pending_approval',
                approved_at: null,
                approved_by: null,
                sent_at: null,
                portal_expires_at: null,
                response: null,
                response_at: null,
                response_notes: null,
                signed_full_name: null,
                signed_at: null,
                portal_url: null,
                offer_letter_name: null,
                offer_letter_id: null,
                primary_site: null,
            },
        },
    ],
};

const renderPage = () =>
    render(
        <CandidateShow
            candidate={candidate}
            can={{ manage: true }}
            documents={[]}
            documentCategories={{}}
        />,
    );

const dialog = () =>
    screen.getByRole('dialog', { name: 'Decline this offer for changes?' });

const openDecline = () => {
    renderPage();
    fireEvent.click(screen.getByRole('button', { name: 'Decline' }));
};

beforeEach(() => {
    router.post.mockReset();
    router.post.mockImplementation(settle);
});

describe('Recruitment › Candidate: decline an offer for changes', () => {
    it('opens a dialog instead of declining straight away', () => {
        openDecline();

        expect(router.post).not.toHaveBeenCalled();
        expect(dialog()).toHaveTextContent("The candidate isn't told.");
        expect(
            within(dialog()).getByLabelText('Reason (optional)'),
        ).toHaveAttribute('maxLength', '2000');
    });

    it('Cancel declines nothing', async () => {
        openDecline();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Cancel' }),
        );

        expect(router.post).not.toHaveBeenCalled();
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('declines without a reason, because the reason is optional', async () => {
        openDecline();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Decline offer' }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/hr/recruitment/offers/77/decline-approval',
            { reason: '' },
            expect.objectContaining({ preserveScroll: true }),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('sends the reason the approver typed', () => {
        openDecline();

        fireEvent.change(within(dialog()).getByLabelText('Reason (optional)'), {
            target: { value: '  Start date needs to move  ' },
        });
        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Decline offer' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/hr/recruitment/offers/77/decline-approval',
            { reason: 'Start date needs to move' },
            expect.anything(),
        );
    });

    it('shows a server error under the reason and stays open', () => {
        router.post.mockImplementation(
            (_url: string, _data: unknown, options?: VisitOptions) => {
                options?.onStart?.();
                options?.onError?.({
                    reason: 'The reason field must not be greater than 2000 characters.',
                });
                options?.onFinish?.();
            },
        );
        openDecline();

        fireEvent.click(
            within(dialog()).getByRole('button', { name: 'Decline offer' }),
        );

        expect(dialog()).toHaveTextContent(
            'The reason field must not be greater than 2000 characters.',
        );
    });
});
