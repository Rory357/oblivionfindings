import {
    fireEvent,
    render,
    screen,
    waitFor,
    within,
} from '@testing-library/react';
import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';

// Void destruction, cancel a triggered review and withdraw a prescriber used
// to fire from the wizard's last button. Each now asks in the shared
// ConfirmDialog (red, frontline sizing); nothing is sent until it confirms.

type VisitOptions = {
    onSuccess?: () => void;
    onFinish?: () => void;
};

const transport = vi.hoisted(() => ({
    post: vi.fn(),
    review: vi.fn(),
    reload: vi.fn(),
}));

vi.mock('@inertiajs/react', async () => {
    const React = await import('react');
    return {
        router: { reload: transport.reload, visit: vi.fn(), get: vi.fn() },
        useForm: <T extends Record<string, string>>(initial: T) => {
            const [data, setDataState] = React.useState(initial);
            return {
                data,
                errors: {},
                processing: false,
                isDirty: false,
                setData: (key: keyof T, value: string) =>
                    setDataState((current) => ({ ...current, [key]: value })),
                post: (url: string, options: VisitOptions) => {
                    transport.post(url, data);
                    options.onSuccess?.();
                    options.onFinish?.();
                },
            };
        },
    };
});
vi.mock('sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
vi.mock('axios', () => ({
    default: { request: vi.fn(), isAxiosError: () => false },
}));
vi.mock('./reviews/_request', async (importOriginal) => ({
    ...(await importOriginal<typeof import('./reviews/_request')>()),
    reviewRequest: transport.review,
}));
vi.mock('@/components/files/file-preview-dialog', () => ({
    FilePreviewDialog: () => null,
}));

import { VoidDestructionDialog } from './_cd-dialogs';
import { RevokeAccess } from './connected/_forms';
import { CancelReviewDialog } from './reviews/_upkeep-dialogs';
import type { Review } from './reviews/types';

const request = vi.mocked(axios.request);

beforeEach(() => {
    transport.post.mockClear();
    transport.review.mockReset();
    transport.reload.mockClear();
    request.mockReset();
});

const confirmDialog = () => screen.getByRole('alertdialog');

describe('eMAR › Void destruction record', () => {
    const destruction = {
        id: 55,
        medication_name: 'Morphine 10 mg tablets',
        quantity: 4,
        unit: 'tablets',
    };

    it('asks before voiding, then posts the same request', () => {
        const onClose = vi.fn();
        render(
            <VoidDestructionDialog
                destruction={destruction}
                onClose={onClose}
            />,
        );
        fireEvent.change(screen.getByPlaceholderText(/Duplicate entry/), {
            target: { value: 'Duplicate entry' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Void record' }));

        expect(transport.post).not.toHaveBeenCalled();
        const dialog = confirmDialog();
        expect(
            within(dialog).getByText('Void this destruction record?'),
        ).toBeTruthy();
        expect(
            within(dialog).getByText('Morphine 10 mg tablets (4 tablets)'),
        ).toBeTruthy();
        expect(
            within(dialog).getByText(
                /Stock and register balances don’t change/,
            ),
        ).toBeTruthy();
        const confirm = within(dialog).getByRole('button', {
            name: 'Void record',
        });
        expect(confirm.className).toMatch(/bg-destructive/);

        fireEvent.click(confirm);
        expect(transport.post).toHaveBeenCalledWith(
            '/emar/destructions/55/void',
            { void_reason: 'Duplicate entry' },
        );
        expect(onClose).toHaveBeenCalled();
    });

    it('Cancel on the confirm sends nothing and keeps the reason', () => {
        render(
            <VoidDestructionDialog
                destruction={destruction}
                onClose={vi.fn()}
            />,
        );
        const reason = screen.getByPlaceholderText(/Duplicate entry/);
        fireEvent.change(reason, { target: { value: 'Wrong quantity' } });
        fireEvent.click(screen.getByRole('button', { name: 'Void record' }));
        fireEvent.click(
            within(confirmDialog()).getByRole('button', { name: 'Cancel' }),
        );
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(transport.post).not.toHaveBeenCalled();
        expect((reason as HTMLInputElement).value).toBe('Wrong quantity');
    });
});

describe('eMAR › Cancel a triggered review', () => {
    const review = {
        id: 801,
        client_id: 802,
        client_name: 'Synthetic person',
        review_type: 'triggered',
        status: 'scheduled',
        scheduled_date: '2026-10-12',
        revision: 3,
    } as unknown as Review;

    function reachReviewStep() {
        render(<CancelReviewDialog review={review} onClose={vi.fn()} />);
        fireEvent.change(screen.getByLabelText(/Why it is no longer needed/), {
            target: { value: 'GP reviewed it at the clinic' },
        });
        fireEvent.click(screen.getByRole('button', { name: /Continue/ }));
        fireEvent.click(
            screen.getByRole('button', { name: 'Cancel the review' }),
        );
    }

    it('asks before cancelling, then sends the same request', async () => {
        transport.review.mockResolvedValue(undefined);
        reachReviewStep();

        expect(transport.review).not.toHaveBeenCalled();
        const dialog = confirmDialog();
        expect(
            within(dialog).getByText('Cancel this triggered review?'),
        ).toBeTruthy();
        expect(within(dialog).getByText(/can’t be reopened/)).toBeTruthy();
        const confirm = within(dialog).getByRole('button', {
            name: 'Cancel the review',
        });
        expect(confirm.className).toMatch(/bg-destructive/);

        fireEvent.click(confirm);
        await waitFor(() =>
            expect(transport.review).toHaveBeenCalledWith(
                '/emar/reviews/801',
                { revision: 3, reason: 'GP reviewed it at the clinic' },
                'delete',
            ),
        );
        await waitFor(() =>
            expect(screen.getByText('Triggered review cancelled')).toBeTruthy(),
        );
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('"Keep the review" sends nothing', () => {
        reachReviewStep();
        fireEvent.click(
            within(confirmDialog()).getByRole('button', {
                name: 'Keep the review',
            }),
        );
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(transport.review).not.toHaveBeenCalled();
    });
});

describe('eMAR › Withdraw prescriber identity / revoke access', () => {
    function reachReviewStep(kind: 'clinicians' | 'grants') {
        const onSaved = vi.fn();
        render(
            <RevokeAccess
                kind={kind}
                id={3}
                name="Dr Example"
                clientId={7}
                onClose={vi.fn()}
                onSaved={onSaved}
            />,
        );
        fireEvent.change(screen.getByRole('textbox'), {
            target: { value: 'Left the practice' },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Continue' }));
        return onSaved;
    }

    it('asks before withdrawing an identity, then posts the same request', async () => {
        request.mockResolvedValue({ data: { saved: true } });
        const onSaved = reachReviewStep('clinicians');
        fireEvent.click(
            screen.getByRole('button', { name: 'Withdraw identity' }),
        );

        expect(request).not.toHaveBeenCalled();
        const dialog = confirmDialog();
        expect(
            within(dialog).getByText(
                'Withdraw Dr Example’s prescriber identity?',
            ),
        ).toBeTruthy();
        expect(within(dialog).getByText(/in every house/)).toBeTruthy();
        const confirm = within(dialog).getByRole('button', {
            name: 'Withdraw identity',
        });
        expect(confirm.className).toMatch(/bg-destructive/);

        fireEvent.click(confirm);
        await waitFor(() => expect(onSaved).toHaveBeenCalled());
        expect(request).toHaveBeenCalledWith(
            expect.objectContaining({
                url: '/emar/connected-care/clinicians/3/revoke',
                method: 'post',
                data: { client_id: 7, reason: 'Left the practice' },
            }),
        );
        expect(screen.queryByRole('alertdialog')).toBeNull();
    });

    it('asks before revoking a named-person grant; Cancel sends nothing', () => {
        reachReviewStep('grants');
        fireEvent.click(screen.getByRole('button', { name: 'Revoke access' }));
        expect(
            within(confirmDialog()).getByText('Revoke Dr Example’s access?'),
        ).toBeTruthy();
        fireEvent.click(
            within(confirmDialog()).getByRole('button', { name: 'Cancel' }),
        );
        expect(screen.queryByRole('alertdialog')).toBeNull();
        expect(request).not.toHaveBeenCalled();
    });
});
