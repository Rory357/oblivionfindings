import {
    act,
    fireEvent,
    render,
    screen,
    waitFor,
} from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubAnnouncementDialog, type HubAnnouncement } from './_announcement';
const { request, reload, actor } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
    actor: { id: 11 },
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({
    router: { reload },
    usePage: () => ({ props: { auth: { user: actor } } }),
}));
const notice: HubAnnouncement = {
    id: 1,
    actor_id: 11,
    title: 'Read our update',
    content: 'Useful information',
    priority: 'normal',
    author: 'Company team',
    published_at: '2026-10-13T00:00:00Z',
    ack_deadline: null,
    requires_acknowledgement: true,
    acknowledged_at: null,
    version: 'exact-reviewed-version',
    attachments: [
        {
            id: 3,
            name: 'Guide.pdf',
            size: 100,
            url: '/company-hub/announcement-files/3',
        },
    ],
};
const receipt = {
    ...notice,
    saved: true,
    acknowledged_at: '2026-10-13T01:00:00Z',
};
beforeEach(() => {
    request.mockReset();
    reload.mockReset();
    actor.id = 11;
});
describe('Hub announcement reading', () => {
    it.each([
        { ...receipt, actor_id: 99 },
        { ...receipt, id: 99 },
        { ...receipt, version: 'another-version' },
        { ...receipt, saved: false },
        { ...receipt, acknowledged_at: null },
    ])(
        'requires an exact current-account acknowledgement receipt',
        async (response) => {
            request
                .mockResolvedValueOnce(notice)
                .mockResolvedValueOnce(response);
            render(<HubAnnouncementDialog id={1} onClose={vi.fn()} />);
            fireEvent.click(
                await screen.findByRole('button', { name: 'I have read this' }),
            );
            await screen.findByText(/could not be verified/);
            expect(
                screen.queryByText(/Read confirmed/),
            ).not.toBeInTheDocument();
            expect(
                screen.getByRole('button', { name: 'I have read this' }),
            ).toBeDisabled();
            expect(reload).not.toHaveBeenCalled();
        },
    );
    it('does not show another account’s GET projection', async () => {
        request.mockResolvedValueOnce({
            ...notice,
            actor_id: 99,
            content: 'Not this reader’s content',
        });
        render(<HubAnnouncementDialog id={1} onClose={vi.fn()} />);
        await screen.findByRole('alert');
        expect(
            screen.queryByText('Not this reader’s content'),
        ).not.toBeInTheDocument();
        expect(
            screen.queryByRole('button', { name: 'I have read this' }),
        ).not.toBeInTheDocument();
    });
    it('locks duplicate confirmation and ignores an old save after account replacement', async () => {
        let finish!: (result: unknown) => void;
        request
            .mockResolvedValueOnce(notice)
            .mockReturnValueOnce(
                new Promise((resolve) => {
                    finish = resolve;
                }),
            )
            .mockResolvedValueOnce({ ...notice, actor_id: 22 });
        const close = vi.fn();
        const view = render(<HubAnnouncementDialog id={1} onClose={close} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'I have read this' }),
        );
        fireEvent.click(screen.getByRole('button', { name: 'Confirming…' }));
        fireEvent.keyDown(screen.getByRole('dialog'), { key: 'Escape' });
        expect(request).toHaveBeenCalledTimes(2);
        expect(close).not.toHaveBeenCalled();
        actor.id = 22;
        view.rerender(<HubAnnouncementDialog id={1} onClose={close} />);
        await screen.findByText('Useful information');
        await act(async () => finish(receipt));
        expect(screen.queryByText(/Read confirmed/)).not.toBeInTheDocument();
        expect(reload).not.toHaveBeenCalled();
    });
    it('records no confirmation on open and submits only the version explicitly read', async () => {
        request.mockResolvedValueOnce(notice).mockResolvedValueOnce({
            ...notice,
            saved: true,
            acknowledged_at: '2026-10-13T01:00:00Z',
        });
        render(<HubAnnouncementDialog id={1} onClose={vi.fn()} />);
        await screen.findByText('Useful information');
        expect(request).toHaveBeenCalledTimes(1);
        expect(screen.getByRole('link', { name: 'Guide.pdf' })).toHaveAttribute(
            'href',
            '/company-hub/announcement-files/3',
        );
        fireEvent.click(
            screen.getByRole('button', { name: 'I have read this' }),
        );
        await screen.findByText(/Read confirmed/);
        expect(request).toHaveBeenLastCalledWith(
            '/company-hub/announcements/1/acknowledge',
            'POST',
            { actor_id: 11, version: 'exact-reviewed-version' },
        );
        expect(
            screen.queryByRole('button', { name: 'I have read this' }),
        ).not.toBeInTheDocument();
        expect(reload).toHaveBeenCalledWith({
            only: ['announcements', 'updates', 'readingAttention'],
        });
    });
    it('keeps failed confirmations unconfirmed and lets the reader refresh changed content', async () => {
        request
            .mockResolvedValueOnce(notice)
            .mockRejectedValueOnce(
                new Error('This announcement has changed. Refresh it.'),
            )
            .mockResolvedValueOnce({
                ...notice,
                version: 'new-version',
                content: 'Revised information',
            });
        render(<HubAnnouncementDialog id={1} onClose={vi.fn()} />);
        fireEvent.click(
            await screen.findByRole('button', { name: 'I have read this' }),
        );
        await screen.findByRole('alert');
        expect(screen.queryByText(/Read confirmed/)).not.toBeInTheDocument();
        fireEvent.click(
            screen.getByRole('button', { name: 'Refresh announcement' }),
        );
        await screen.findByText('Revised information');
        expect(reload).not.toHaveBeenCalled();
    });
    it('does not show an unavailable announcement or offer an acknowledgement action', async () => {
        request.mockRejectedValue(new Error('Not found'));
        render(<HubAnnouncementDialog id={99} onClose={vi.fn()} />);
        await waitFor(() =>
            expect(screen.getByRole('alert')).toHaveTextContent(
                'no longer available',
            ),
        );
        expect(
            screen.queryByRole('button', { name: 'I have read this' }),
        ).not.toBeInTheDocument();
    });
});
