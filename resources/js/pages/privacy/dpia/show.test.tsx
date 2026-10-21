import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import type { PropsWithChildren, ReactNode } from 'react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ShowPIA from './show';

// Approve used a browser confirm and "Request review" a browser prompt. Approve
// now asks through ConfirmDialog; the review notes are collected in a dialog.

type VisitOptions = {
    onStart?: () => void;
    onSuccess?: () => void;
    onError?: (errors: Record<string, string>) => void;
    onFinish?: () => void;
};

const page = vi.hoisted(() => ({
    props: { auth: { can: { privacy: { conductDPIA: true } } } },
}));
const router = vi.hoisted(() => ({
    post: vi.fn(
        (_url: string, _data: unknown, options?: VisitOptions) => {
            options?.onStart?.();
            options?.onSuccess?.();
            options?.onFinish?.();
        },
    ),
}));

vi.mock('@inertiajs/react', () => ({
    Head: () => null,
    Link: ({ children }: PropsWithChildren) => <>{children}</>,
    usePage: () => page,
    router,
}));
vi.mock('@/layouts/app-layout', () => ({
    default: ({ children }: PropsWithChildren) => <>{children}</>,
}));
vi.mock('@/components/page', () => ({
    PageHero: ({
        title,
        actions,
        children,
    }: PropsWithChildren<{ title?: ReactNode; actions?: ReactNode }>) => (
        <>
            <h1>{title}</h1>
            {actions}
            {children}
        </>
    ),
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

const dpia = {
    id: 3,
    assessment_name: 'Family portal photo sharing',
    project_or_process: 'Family portal',
    description: null,
    assessment_type: 'new_system',
    personal_data_types: ['Photos'],
    data_subjects: ['Residents'],
    processing_purpose: 'Share daily photos with families.',
    legal_basis: 'Consent',
    identified_risks: [],
    overall_risk_level: 'medium',
    mitigation_measures: [],
    residual_risk_level: null,
    outcome: null as string | null,
    review_date: null,
    assessment_date: '2026-10-01',
};

const press = (name: string) =>
    fireEvent.click(screen.getByRole('button', { name }));

beforeEach(() => {
    router.post.mockClear();
});

describe('Privacy › impact assessment: approve', () => {
    it('asks first, Cancel sends nothing, and confirming approves', async () => {
        render(<ShowPIA dpia={dpia} />);
        press('Approve');

        expect(router.post).not.toHaveBeenCalled();
        const confirm = screen.getByRole('alertdialog');
        expect(
            within(confirm).getByText(
                'Approve this privacy impact assessment?',
            ),
        ).toBeInTheDocument();
        expect(confirm).toHaveTextContent('Family portal photo sharing');
        expect(confirm).toHaveTextContent('No one else is notified.');

        fireEvent.click(within(confirm).getByRole('button', { name: 'Cancel' }));
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
        expect(router.post).not.toHaveBeenCalled();

        press('Approve');
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Approve assessment',
            }),
        );

        expect(router.post).toHaveBeenCalledTimes(1);
        expect(router.post).toHaveBeenCalledWith(
            '/privacy/pia/3/approve',
            {},
            expect.any(Object),
        );
        await waitFor(() =>
            expect(screen.queryByRole('alertdialog')).not.toBeInTheDocument(),
        );
    });

    it('keeps the confirmation open while approval is in flight', () => {
        router.post.mockImplementationOnce(
            (_url: string, _data: unknown, options?: VisitOptions) => {
                options?.onStart?.();
            },
        );
        render(<ShowPIA dpia={dpia} />);
        press('Approve');
        fireEvent.click(
            within(screen.getByRole('alertdialog')).getByRole('button', {
                name: 'Approve assessment',
            }),
        );

        const confirm = screen.getByRole('alertdialog');
        expect(
            within(confirm).getByRole('button', { name: 'Approve assessment' }),
        ).toBeDisabled();
        expect(
            within(confirm).getByRole('button', { name: 'Cancel' }),
        ).toBeDisabled();
    });
});

describe('Privacy › impact assessment: request privacy officer review', () => {
    it('opens a dialog, requires notes, then sends them', async () => {
        render(<ShowPIA dpia={dpia} />);
        press('Request Privacy Officer Review');

        expect(router.post).not.toHaveBeenCalled();
        const review = screen.getByRole('dialog', {
            name: 'Ask the privacy officer to review this assessment?',
        });
        expect(review).toHaveTextContent(
            "The privacy officer isn't notified automatically",
        );
        expect(review).not.toHaveTextContent('no longer show as approved');

        fireEvent.click(
            within(review).getByRole('button', { name: 'Request review' }),
        );
        expect(router.post).not.toHaveBeenCalled();
        expect(review).toHaveTextContent('Add notes for the privacy officer.');

        fireEvent.change(
            within(review).getByLabelText('Notes for the privacy officer'),
            { target: { value: 'Check the photo consent wording.' } },
        );
        fireEvent.click(
            within(review).getByRole('button', { name: 'Request review' }),
        );

        expect(router.post).toHaveBeenCalledWith(
            '/privacy/pia/3/review',
            { review_notes: 'Check the photo consent wording.' },
            expect.any(Object),
        );
        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
    });

    it('Cancel sends nothing', async () => {
        render(<ShowPIA dpia={dpia} />);
        press('Request Privacy Officer Review');
        fireEvent.click(
            within(screen.getByRole('dialog')).getByRole('button', {
                name: 'Cancel',
            }),
        );

        await waitFor(() =>
            expect(screen.queryByRole('dialog')).not.toBeInTheDocument(),
        );
        expect(router.post).not.toHaveBeenCalled();
    });

    it('says an approved assessment stops showing as approved', () => {
        render(<ShowPIA dpia={{ ...dpia, outcome: 'approved' }} />);

        expect(
            screen.queryByRole('button', { name: 'Approve' }),
        ).not.toBeInTheDocument();
        press('Request Privacy Officer Review');
        expect(screen.getByRole('dialog')).toHaveTextContent(
            'It will no longer show as approved.',
        );
    });
});
