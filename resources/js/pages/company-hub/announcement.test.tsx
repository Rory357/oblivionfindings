import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { HubAnnouncementDialog, type HubAnnouncement } from './_announcement';
const { request, reload } = vi.hoisted(() => ({
    request: vi.fn(),
    reload: vi.fn(),
}));
vi.mock('./_api', () => ({ hubRequest: request }));
vi.mock('@inertiajs/react', () => ({ router: { reload } }));
const notice: HubAnnouncement = {
    id: 1,
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
beforeEach(() => {
    request.mockReset();
    reload.mockReset();
});
describe('Hub announcement reading', () => {
    it('records no confirmation on open and submits only the version explicitly read', async () => {
        request
            .mockResolvedValueOnce(notice)
            .mockResolvedValueOnce({
                ...notice,
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
            { version: 'exact-reviewed-version' },
        );
        expect(
            screen.queryByRole('button', { name: 'I have read this' }),
        ).not.toBeInTheDocument();
        expect(reload).toHaveBeenCalledWith({
            only: ['announcements', 'updates'],
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
