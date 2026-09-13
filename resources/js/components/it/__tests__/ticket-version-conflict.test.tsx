import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import axios from 'axios';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { TicketCloseDialog } from '../ticket-close-dialog';
import { TicketVersionConflict } from '../ticket-version-conflict';

const mocks = vi.hoisted(() => ({
    post: vi.fn(),
    patch: vi.fn(),
    success: vi.fn(),
    error: vi.fn(),
}));
vi.mock('@inertiajs/react', () => ({
    usePage: () => ({ props: { auth: { user: { id: 11 } } } }),
    router: {
        post: mocks.post,
        patch: mocks.patch,
        on: () => () => {},
        reload: vi.fn(),
    },
}));
vi.mock('sonner', () => ({
    toast: { success: mocks.success, error: mocks.error },
}));

const message = 'This ticket changed. Your changes were not saved.';
const current = {
    id: 42,
    reference: 'IT-000042',
    lock_version: 8,
    title: 'Restored connection',
    status: 'resolved',
    priority: 'high',
    assignee: { name: 'Current technician' },
};

describe('ticket version recovery', () => {
    it('permits a requester to review a reply version without granting ticket management', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    ticket: current,
                    viewer_user_id: 3,
                    can: { manage: false, comment: true, internal: false },
                }),
            }),
        );
        const reviewed = vi.fn();
        render(
            <TicketVersionConflict
                error={message}
                ticketId={42}
                actorId={3}
                requiredCapability="comment"
                onReviewed={reviewed}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        fireEvent.click(
            await screen.findByRole('button', {
                name: 'Use this version and keep my draft',
            }),
        );
        expect(reviewed).toHaveBeenCalledWith(8, current);
    });
    it('conceals an internal-note review if public comment access remains but internal work access is lost', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({
                    ticket: current,
                    viewer_user_id: 3,
                    can: { manage: false, comment: true, internal: false },
                }),
            }),
        );
        const concealed = vi.fn();
        const reviewed = vi.fn();
        render(
            <TicketVersionConflict
                error={message}
                ticketId={42}
                actorId={3}
                requiredCapability="comment"
                requireInternal
                onAccessLost={concealed}
                onReviewed={reviewed}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        await waitFor(() => expect(concealed).toHaveBeenCalledOnce());
        expect(screen.queryByText(current.title)).not.toBeInTheDocument();
        expect(reviewed).not.toHaveBeenCalled();
    });
    beforeEach(() => {
        vi.clearAllMocks();
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                json: async () => ({ ticket: current }),
            }),
        );
    });
    afterEach(() => vi.unstubAllGlobals());

    it('keeps closure evidence and its old version through a refresh until explicit review', async () => {
        const request = vi
            .spyOn(axios, 'request')
            .mockRejectedValue({
                isAxiosError: true,
                response: { status: 409 },
            });
        vi.mocked(fetch).mockResolvedValue({
            ok: true,
            json: async () => ({
                ticket: current,
                viewer_user_id: 11,
                can: { manage: true },
            }),
        } as Response);
        const onOpenChange = vi.fn();
        const view = render(
            <TicketCloseDialog
                open
                onOpenChange={onOpenChange}
                scope="single"
                ticketIds={[42]}
                expectedVersions={{ 42: 3 }}
            />,
        );
        const note = 'Requester confirmed this exact restoration.';
        fireEvent.change(screen.getByLabelText('Reason for closing'), {
            target: { value: note },
        });
        fireEvent.click(screen.getByRole('button', { name: 'Close ticket' }));
        expect(request.mock.calls[0][0].data).toEqual({
            actor_user_id: 11,
            reason: note,
            expected_version: 3,
        });
        await screen.findByRole('button', { name: 'Review current ticket' });

        view.rerender(
            <TicketCloseDialog
                open
                onOpenChange={onOpenChange}
                scope="single"
                ticketIds={[42]}
                expectedVersions={{ 42: 8 }}
            />,
        );
        expect(screen.getByLabelText('Reason for closing')).toHaveValue(note);
        expect(
            screen.getByRole('button', { name: 'Close ticket' }),
        ).toBeDisabled();
        expect(fetch).not.toHaveBeenCalled();
        expect(onOpenChange).not.toHaveBeenCalled();
        expect(mocks.success).not.toHaveBeenCalled();

        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        expect(await screen.findByText('Restored connection')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', {
                name: 'Use this version and keep my draft',
            }),
        );
        expect(request).toHaveBeenCalledTimes(1);
        fireEvent.click(screen.getByRole('button', { name: 'Close ticket' }));
        expect(request.mock.calls[1][0].data).toEqual({
            actor_user_id: 11,
            reason: note,
            expected_version: 8,
        });
        await act(async () => {});
        request.mockRestore();
    });

    it('shows denial without accepting a version or pretending the review succeeded', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: false,
            status: 403,
        } as Response);
        const accept = vi.fn();
        render(
            <TicketVersionConflict
                error={message}
                ticketId={42}
                onReviewed={accept}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        expect(
            await screen.findByText(/no longer available to you/),
        ).toBeVisible();
        expect(
            screen.queryByRole('button', {
                name: 'Use this version and keep my draft',
            }),
        ).not.toBeInTheDocument();
        expect(accept).not.toHaveBeenCalled();
    });

    it('keeps review unavailable when current ticket details are incomplete', async () => {
        vi.mocked(fetch).mockResolvedValueOnce({
            ok: true,
            json: async () => ({ ticket: { id: 42, lock_version: 8 } }),
        } as Response);
        const accept = vi.fn();
        render(
            <TicketVersionConflict
                error={message}
                ticketId={42}
                onReviewed={accept}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        expect(
            await screen.findByText(/Current details could not be confirmed/),
        ).toBeVisible();
        expect(accept).not.toHaveBeenCalled();
    });

    it('allows retry after a failed review without submitting anything', async () => {
        vi.mocked(fetch).mockRejectedValueOnce(
            new TypeError('Network unavailable'),
        );
        const accept = vi.fn();
        render(
            <TicketVersionConflict
                error={message}
                ticketId={42}
                onReviewed={accept}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        expect(await screen.findByText('Network unavailable')).toBeVisible();
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        expect(await screen.findByText('Restored connection')).toBeVisible();
        expect(accept).not.toHaveBeenCalled();
        expect(mocks.patch).not.toHaveBeenCalled();
        expect(mocks.post).not.toHaveBeenCalled();
    });

    it('cancels a review request and leaves the draft decision pending', async () => {
        vi.mocked(fetch).mockImplementationOnce(
            (_url, options) =>
                new Promise((_resolve, reject) => {
                    options?.signal?.addEventListener('abort', () =>
                        reject(new DOMException('Cancelled', 'AbortError')),
                    );
                }),
        );
        const accept = vi.fn();
        render(
            <TicketVersionConflict
                error={message}
                ticketId={42}
                onReviewed={accept}
            />,
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'Review current ticket' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Cancel loading' }));
        await waitFor(() =>
            expect(
                screen.getByRole('button', { name: 'Review current ticket' }),
            ).toBeEnabled(),
        );
        expect(accept).not.toHaveBeenCalled();
    });
});
